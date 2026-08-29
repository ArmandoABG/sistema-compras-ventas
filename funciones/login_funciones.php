<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/integridad_bd.php';

si_requerir_metodo('POST');
si_validar_csrf('csrf_login');

if (!($conexion instanceof PDO)) {
    si_responder_json(
        false,
        'No fue posible conectar con la base de datos.',
        [],
        503
    );
}

$usuario = trim(
    (string) ($_POST['usuario'] ?? '')
);

$password = (string) (
    $_POST['password'] ?? ''
);

if ($usuario === '' || $password === '') {
    si_responder_json(
        false,
        'Ingresa usuario y contraseña.',
        [],
        422
    );
}

if (
    strlen($usuario) > 60
    || strlen($password) > 255
) {
    si_responder_json(
        false,
        'Usuario o contraseña incorrectos.',
        [],
        401
    );
}

try {
    /*
     * Aseguramos catálogo oficial antes de leer roles/permisos.
     */
    si_sincronizar_seguridad_base(
        $conexion
    );

    $stmt = $conexion->prepare(
        "SELECT
            id,
            usuario,
            password_hash,
            nombres,
            apellido_paterno,
            apellido_materno,
            activo,
            debe_cambiar_password,
            bloqueado_hasta
         FROM usuarios
         WHERE usuario = :usuario
         LIMIT 1"
    );

    $stmt->execute([
        ':usuario' => $usuario,
    ]);

    $registro = $stmt->fetch();

    if (!$registro) {
        password_verify(
            $password,
            '$2y$10$wH0nvtVgk7DvheF6xV5m1uGchQhy1RlIOmO7Df7Y1mIUb3piFJePi'
        );

        si_responder_json(
            false,
            'Usuario o contraseña incorrectos.',
            [],
            401
        );
    }

    $usuarioId = (int) $registro['id'];

    if ((int) $registro['activo'] !== 1) {
        si_responder_json(
            false,
            'La cuenta se encuentra desactivada.',
            [],
            403
        );
    }

    if (!empty($registro['bloqueado_hasta'])) {
        $bloqueadoHasta = new DateTimeImmutable(
            (string) $registro['bloqueado_hasta']
        );

        if (
            $bloqueadoHasta
            > new DateTimeImmutable()
        ) {
            si_responder_json(
                false,
                'La cuenta está bloqueada temporalmente. Intenta más tarde.',
                [],
                423
            );
        }

        $conexion->prepare(
            "UPDATE usuarios
             SET
                intentos_fallidos = 0,
                bloqueado_hasta = NULL
             WHERE id = :id"
        )->execute([
            ':id' => $usuarioId,
        ]);
    }

    if (
        !password_verify(
            $password,
            (string) $registro['password_hash']
        )
    ) {
        $conexion->beginTransaction();

        $conexion->prepare(
            "UPDATE usuarios
             SET
                intentos_fallidos = intentos_fallidos + 1,
                bloqueado_hasta = CASE
                    WHEN intentos_fallidos + 1 >= 5
                    THEN DATE_ADD(
                        NOW(),
                        INTERVAL 15 MINUTE
                    )
                    ELSE bloqueado_hasta
                END
             WHERE id = :id"
        )->execute([
            ':id' => $usuarioId,
        ]);

        $conexion->prepare(
            "INSERT INTO auditoria
                (
                    usuario_id,
                    accion,
                    modulo,
                    entidad_tabla,
                    entidad_id,
                    descripcion,
                    ip,
                    user_agent
                )
             VALUES
                (
                    :usuario_id,
                    'LOGIN_FALLIDO',
                    'seguridad',
                    'usuarios',
                    :entidad_id,
                    'Contraseña incorrecta.',
                    :ip,
                    :user_agent
                )"
        )->execute([
            ':usuario_id' => $usuarioId,
            ':entidad_id' => $usuarioId,
            ':ip' => si_ip_cliente(),
            ':user_agent' => si_user_agent(),
        ]);

        $conexion->commit();

        si_responder_json(
            false,
            'Usuario o contraseña incorrectos.',
            [],
            401
        );
    }

    /*
     * En la BD exportada armando ya es ADMINISTRADOR.
     * Esto solo conserva la recuperación segura para instalaciones iniciales.
     */
    si_recuperar_administrador_unico(
        $conexion,
        $usuarioId
    );

    /*
     * Limpiamos datos anteriores y generamos una sesión nueva.
     */
    $_SESSION = [];

    session_regenerate_id(true);

    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['usuario'] = (string) $registro['usuario'];

    $_SESSION['nombre_completo'] = trim(
        implode(
            ' ',
            array_filter([
                $registro['nombres'] ?? '',
                $registro['apellido_paterno'] ?? '',
                $registro['apellido_materno'] ?? '',
            ])
        )
    );

    /*
     * Roles y permisos salen de MySQL, no de datos heredados.
     */
    $identidad = si_cargar_identidad_sesion(
        $conexion,
        $usuarioId
    );

    if ($identidad['roles'] === []) {
        $_SESSION = [];

        si_responder_json(
            false,
            'La cuenta no tiene un rol activo asignado.',
            [],
            403
        );
    }

    $conexion->beginTransaction();

    $conexion->prepare(
        "UPDATE usuarios
         SET
            ultimo_acceso = NOW(),
            intentos_fallidos = 0,
            bloqueado_hasta = NULL
         WHERE id = :id"
    )->execute([
        ':id' => $usuarioId,
    ]);

    $stmtSesion = $conexion->prepare(
        "INSERT INTO sesiones_usuario
            (
                usuario_id,
                token_hash,
                ip,
                user_agent,
                inicio_sesion,
                activa
            )
         VALUES
            (
                :usuario_id,
                :token_hash,
                :ip,
                :user_agent,
                NOW(),
                1
            )"
    );

    $stmtSesion->execute([
        ':usuario_id' => $usuarioId,
        ':token_hash' => hash(
            'sha256',
            session_id()
        ),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);

    $sesionDbId = (int) $conexion->lastInsertId();

    $conexion->prepare(
        "INSERT INTO auditoria
            (
                usuario_id,
                accion,
                modulo,
                entidad_tabla,
                entidad_id,
                descripcion,
                datos_nuevos,
                ip,
                user_agent
            )
         VALUES
            (
                :usuario_id,
                'LOGIN_EXITOSO',
                'seguridad',
                'usuarios',
                :entidad_id,
                'Inicio de sesión correcto con seguridad V2.',
                :datos_nuevos,
                :ip,
                :user_agent
            )"
    )->execute([
        ':usuario_id' => $usuarioId,
        ':entidad_id' => $usuarioId,
        ':datos_nuevos' => json_encode(
            [
                'roles' => array_column(
                    $identidad['roles'],
                    'codigo'
                ),
                'permisos_cargados' => count(
                    $identidad['permisos']
                ),
                'sesion_version' => 2,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);

    $conexion->commit();

    $_SESSION['sesion_db_id'] = $sesionDbId;
    $_SESSION['debe_cambiar_password'] = (int) (
        $registro['debe_cambiar_password'] ?? 0
    );
    $_SESSION['ultima_actividad'] = time();
    $_SESSION['sesion_regenerada_en'] = time();

    si_responder_json(
        true,
        'Inicio de sesión correcto.',
        [
            'redirect' => si_url(
                (int) ($_SESSION['debe_cambiar_password'] ?? 0) === 1
                    ? 'JS/cambiar_password.php'
                    : 'JS/dashboard.php'
            ),
            'cambio_password_requerido' => (int) ($_SESSION['debe_cambiar_password'] ?? 0) === 1,
        ]
    );

} catch (Throwable $e) {
    if (
        $conexion instanceof PDO
        && $conexion->inTransaction()
    ) {
        $conexion->rollBack();
    }

    $referencia =
        'LOGIN-V2-'
        . date('Ymd-His');

    error_log(
        '[' . $referencia . '] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al iniciar sesión.',
        ['referencia' => $referencia],
        500
    );
}
