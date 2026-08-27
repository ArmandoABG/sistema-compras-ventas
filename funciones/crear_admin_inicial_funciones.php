<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/integridad_bd.php';

si_requerir_metodo('POST');
si_validar_csrf('csrf_admin_inicial');

if (!($conexion instanceof PDO)) {
    si_responder_json(
        false,
        'No fue posible conectar con la base de datos.',
        [],
        503
    );
}

$usuario = trim((string) ($_POST['usuario'] ?? ''));
$nombres = trim((string) ($_POST['nombres'] ?? ''));
$apellidoPaterno = trim((string) ($_POST['apellido_paterno'] ?? ''));
$apellidoMaterno = trim((string) ($_POST['apellido_materno'] ?? ''));
$correo = trim((string) ($_POST['correo'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmar = (string) ($_POST['confirmar_password'] ?? '');

if (!preg_match(
    '/^[A-Za-z0-9._-]{4,60}$/',
    $usuario
)) {
    si_responder_json(
        false,
        'El usuario debe tener entre 4 y 60 caracteres y usar solo letras, números, punto, guion o guion bajo.',
        ['campo' => 'usuario'],
        422
    );
}

if (
    $nombres === ''
    || mb_strlen($nombres) > 120
) {
    si_responder_json(
        false,
        'Ingresa un nombre válido.',
        ['campo' => 'nombres'],
        422
    );
}

if (
    $correo !== ''
    && !filter_var($correo, FILTER_VALIDATE_EMAIL)
) {
    si_responder_json(
        false,
        'El correo no tiene un formato válido.',
        ['campo' => 'correo'],
        422
    );
}

if (
    strlen($password) < 10
    || strlen($password) > 72
) {
    si_responder_json(
        false,
        'La contraseña debe tener entre 10 y 72 caracteres.',
        ['campo' => 'password'],
        422
    );
}

if ($password !== $confirmar) {
    si_responder_json(
        false,
        'Las contraseñas no coinciden.',
        ['campo' => 'confirmar_password'],
        422
    );
}

try {
    // Asegura roles/permisos oficiales antes de crear la primera cuenta.
    si_sincronizar_seguridad_base($conexion);

    $conexion->beginTransaction();

    /*
     * Bloqueamos el rol Administrador para serializar la instalación inicial.
     */
    $stmtRol = $conexion->prepare(
        "SELECT id
         FROM roles
         WHERE codigo = 'ADMINISTRADOR'
           AND activo = 1
         LIMIT 1
         FOR UPDATE"
    );
    $stmtRol->execute();

    $rolId = (int) $stmtRol->fetchColumn();

    if ($rolId <= 0) {
        throw new RuntimeException(
            'No existe el rol ADMINISTRADOR.'
        );
    }

    $existeUsuario = $conexion->query(
        "SELECT id
         FROM usuarios
         WHERE 1=1
         LIMIT 1"
    )->fetchColumn();

    if ($existeUsuario) {
        $conexion->rollBack();

        si_responder_json(
            false,
            'El administrador inicial ya fue configurado.',
            [],
            409
        );
    }

    $stmt = $conexion->prepare(
        "INSERT INTO usuarios
            (
                usuario,
                password_hash,
                nombres,
                apellido_paterno,
                apellido_materno,
                correo,
                telefono,
                activo,
                debe_cambiar_password
            )
         VALUES
            (
                :usuario,
                :password_hash,
                :nombres,
                :apellido_paterno,
                :apellido_materno,
                :correo,
                :telefono,
                1,
                0
            )"
    );

    $stmt->execute([
        ':usuario' => $usuario,
        ':password_hash' => password_hash(
            $password,
            PASSWORD_DEFAULT
        ),
        ':nombres' => $nombres,
        ':apellido_paterno' => $apellidoPaterno !== ''
            ? $apellidoPaterno
            : null,
        ':apellido_materno' => $apellidoMaterno !== ''
            ? $apellidoMaterno
            : null,
        ':correo' => $correo !== ''
            ? $correo
            : null,
        ':telefono' => $telefono !== ''
            ? $telefono
            : null,
    ]);

    $usuarioId = (int) $conexion->lastInsertId();

    $conexion->prepare(
        "INSERT INTO usuarios_roles
            (usuario_id, rol_id)
         VALUES
            (:usuario_id, :rol_id)"
    )->execute([
        ':usuario_id' => $usuarioId,
        ':rol_id' => $rolId,
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
                datos_nuevos,
                ip,
                user_agent
            )
         VALUES
            (
                :usuario_id,
                'ADMIN_INICIAL_CREADO',
                'seguridad',
                'usuarios',
                :entidad_id,
                'Creación del administrador inicial.',
                :datos_nuevos,
                :ip,
                :user_agent
            )"
    )->execute([
        ':usuario_id' => $usuarioId,
        ':entidad_id' => $usuarioId,
        ':datos_nuevos' => json_encode(
            ['usuario' => $usuario, 'rol' => 'ADMINISTRADOR'],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);

    $conexion->commit();

    si_responder_json(
        true,
        'Administrador creado correctamente.',
        [
            'redirect' => si_url('login.php?admin=creado'),
        ]
    );

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log(
        '[ADMIN INICIAL][PDO] '
        . $e->getMessage()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'El usuario o correo ya está registrado.',
            [],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible crear el administrador.',
        [],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    error_log(
        '[ADMIN INICIAL] '
        . $e->getMessage()
    );

    si_responder_json(
        false,
        'No fue posible crear el administrador.',
        [],
        500
    );
}
