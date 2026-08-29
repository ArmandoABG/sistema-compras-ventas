<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_sesion(true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) ($metodo === 'GET' ? ($_GET['accion'] ?? 'OBTENER') : ($_POST['accion'] ?? ''))));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');
        if ($accion === 'OBTENER') perfil_obtener($conexion);
        si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if ((int) ($_SESSION['debe_cambiar_password'] ?? 0) === 1 && $accion !== 'CAMBIAR_PASSWORD') {
        si_responder_json(false, 'Primero debes cambiar tu contraseña temporal.', [
            'cambio_password_requerido' => true,
            'redirect' => si_url('JS/cambiar_password.php'),
        ], 403);
    }

    if ($accion === 'GUARDAR_PERFIL') perfil_guardar($conexion);
    if ($accion === 'CAMBIAR_PASSWORD') perfil_cambiar_password($conexion);

    si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'PERFIL-' . date('Ymd-His');
    error_log('[' . $ref . '][PERFIL][PDO] ' . $e->getMessage());
    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'El correo ya está siendo utilizado por otra cuenta.', ['referencia' => $ref], 409);
    }
    si_responder_json(false, 'No fue posible procesar tu perfil.', ['referencia' => $ref], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'PERFIL-' . date('Ymd-His');
    error_log('[' . $ref . '][PERFIL] ' . $e->getMessage());
    si_responder_json(false, 'Ocurrió un error interno al procesar tu perfil.', ['referencia' => $ref], 500);
}

function perfil_obtener(PDO $conexion): void
{
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    $stmt = $conexion->prepare(
        "SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono,
                activo, debe_cambiar_password, ultimo_acceso
         FROM usuarios
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    if (!$usuario || (int) $usuario['activo'] !== 1) {
        si_responder_json(false, 'Tu cuenta ya no está disponible.', [], 403);
    }

    $roles = $_SESSION['roles'] ?? [];
    si_responder_json(true, 'Perfil cargado.', [
        'perfil' => [
            'id' => (int) $usuario['id'],
            'usuario' => (string) $usuario['usuario'],
            'nombres' => (string) $usuario['nombres'],
            'apellido_paterno' => $usuario['apellido_paterno'],
            'apellido_materno' => $usuario['apellido_materno'],
            'correo' => $usuario['correo'],
            'telefono' => $usuario['telefono'],
            'debe_cambiar_password' => (int) $usuario['debe_cambiar_password'],
            'roles' => is_array($roles) ? array_values($roles) : [],
        ],
    ]);
}

function perfil_guardar(PDO $conexion): void
{
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    $nombres = perfil_nombre($_POST['nombres'] ?? '', 'nombres', true, 120);
    $apellidoPaterno = perfil_nombre($_POST['apellido_paterno'] ?? '', 'apellido_paterno', false, 100);
    $apellidoMaterno = perfil_nombre($_POST['apellido_materno'] ?? '', 'apellido_materno', false, 100);
    $correo = perfil_correo($_POST['correo'] ?? '');
    $telefono = perfil_telefono($_POST['telefono'] ?? '');

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono, activo
         FROM usuarios
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $anterior = $stmt->fetch();

    if (!$anterior || (int) $anterior['activo'] !== 1) {
        $conexion->rollBack();
        si_responder_json(false, 'Tu cuenta ya no está disponible.', [], 403);
    }

    if ($correo !== null) {
        $stmt = $conexion->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = :correo AND id <> :id");
        $stmt->execute([':correo' => $correo, ':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $conexion->rollBack();
            si_responder_json(false, 'Ese correo ya está registrado por otra cuenta.', ['campo' => 'correo'], 409);
        }
    }

    $stmt = $conexion->prepare(
        "UPDATE usuarios
         SET nombres = :nombres,
             apellido_paterno = :apellido_paterno,
             apellido_materno = :apellido_materno,
             correo = :correo,
             telefono = :telefono
         WHERE id = :id"
    );
    $stmt->execute([
        ':nombres' => $nombres,
        ':apellido_paterno' => $apellidoPaterno,
        ':apellido_materno' => $apellidoMaterno,
        ':correo' => $correo,
        ':telefono' => $telefono,
        ':id' => $id,
    ]);

    $nuevo = [
        'nombres' => $nombres,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'correo' => $correo,
        'telefono' => $telefono,
    ];

    perfil_auditar($conexion, $id, 'PERFIL_ACTUALIZADO', 'El usuario actualizó los datos de su propio perfil.', [
        'nombres' => $anterior['nombres'],
        'apellido_paterno' => $anterior['apellido_paterno'],
        'apellido_materno' => $anterior['apellido_materno'],
        'correo' => $anterior['correo'],
        'telefono' => $anterior['telefono'],
    ], $nuevo);

    $conexion->commit();

    $_SESSION['nombre_completo'] = trim(implode(' ', array_filter([$nombres, $apellidoPaterno, $apellidoMaterno])));

    si_responder_json(true, 'Tu perfil se actualizó correctamente.', [
        'nombre_completo' => $_SESSION['nombre_completo'],
    ]);
}

function perfil_cambiar_password(PDO $conexion): void
{
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    $actual = (string) ($_POST['password_actual'] ?? '');
    $nueva = (string) ($_POST['nueva_password'] ?? '');
    $confirmar = (string) ($_POST['confirmar_password'] ?? '');

    if ($actual === '') {
        si_responder_json(false, 'Ingresa tu contraseña actual.', ['campo' => 'password_actual'], 422);
    }
    perfil_validar_password($nueva, $confirmar);

    $conexion->beginTransaction();
    $stmt = $conexion->prepare(
        "SELECT password_hash, activo, debe_cambiar_password
         FROM usuarios
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    if (!$usuario || (int) $usuario['activo'] !== 1) {
        $conexion->rollBack();
        si_responder_json(false, 'Tu cuenta ya no está disponible.', [], 403);
    }

    if (!password_verify($actual, (string) $usuario['password_hash'])) {
        $conexion->rollBack();
        si_responder_json(false, 'Tu contraseña actual es incorrecta.', ['campo' => 'password_actual'], 403);
    }

    if (password_verify($nueva, (string) $usuario['password_hash'])) {
        $conexion->rollBack();
        si_responder_json(false, 'La nueva contraseña debe ser diferente de la actual.', ['campo' => 'nueva_password'], 422);
    }

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('No fue posible proteger la nueva contraseña.');
    }

    $conexion->prepare(
        "UPDATE usuarios
         SET password_hash = :hash,
             debe_cambiar_password = 0,
             intentos_fallidos = 0,
             bloqueado_hasta = NULL
         WHERE id = :id"
    )->execute([':hash' => $hash, ':id' => $id]);

    $sesionId = (int) ($_SESSION['sesion_db_id'] ?? 0);
    $stmt = $conexion->prepare(
        "UPDATE sesiones_usuario
         SET activa = 0,
             fin_sesion = COALESCE(fin_sesion, NOW()),
             motivo_cierre = 'PASSWORD_PROPIA_CAMBIADA'
         WHERE usuario_id = :usuario_id
           AND activa = 1
           AND id <> :sesion_id"
    );
    $stmt->execute([':usuario_id' => $id, ':sesion_id' => $sesionId]);

    perfil_auditar(
        $conexion,
        $id,
        'PASSWORD_PROPIA_CAMBIADA',
        (int) $usuario['debe_cambiar_password'] === 1
            ? 'El usuario sustituyó la contraseña temporal obligatoria.'
            : 'El usuario cambió su propia contraseña.',
        null,
        ['otras_sesiones_cerradas' => $stmt->rowCount()]
    );

    $conexion->commit();
    $_SESSION['debe_cambiar_password'] = 0;

    si_responder_json(true, 'Tu contraseña se actualizó correctamente.', [
        'redirect' => si_url('JS/dashboard.php'),
    ]);
}

function perfil_nombre($valor, string $campo, bool $requerido, int $maximo): ?string
{
    $t = trim((string) $valor);
    if ($t === '') {
        if ($requerido) si_responder_json(false, 'El nombre es obligatorio.', ['campo' => $campo], 422);
        return null;
    }
    if (mb_strlen($t) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', ['campo' => $campo], 422);
    }
    return $t;
}

function perfil_correo($valor): ?string
{
    $c = trim((string) $valor);
    if ($c === '') return null;
    if (mb_strlen($c) > 180 || !filter_var($c, FILTER_VALIDATE_EMAIL)) {
        si_responder_json(false, 'Ingresa un correo válido.', ['campo' => 'correo'], 422);
    }
    return mb_strtolower($c);
}

function perfil_telefono($valor): ?string
{
    $t = trim((string) $valor);
    if ($t === '') return null;
    if (mb_strlen($t) > 30 || !preg_match('/^[0-9+()\-\s.]{7,30}$/', $t)) {
        si_responder_json(false, 'Ingresa un teléfono válido.', ['campo' => 'telefono'], 422);
    }
    return $t;
}

function perfil_validar_password(string $password, string $confirmar): void
{
    if (strlen($password) < 10 || strlen($password) > 72) {
        si_responder_json(false, 'La contraseña debe tener entre 10 y 72 caracteres.', ['campo' => 'nueva_password'], 422);
    }
    if ($password !== $confirmar) {
        si_responder_json(false, 'Las contraseñas no coinciden.', ['campo' => 'confirmar_password'], 422);
    }
}

function perfil_auditar(PDO $conexion, int $usuarioId, string $accion, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
             datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, :accion, 'seguridad', 'usuarios', :entidad_id, :descripcion,
             :anteriores, :nuevos, :ip, :ua)"
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':accion' => $accion,
        ':entidad_id' => $usuarioId,
        ':descripcion' => $descripcion,
        ':anteriores' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevos' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':ua' => si_user_agent(),
    ]);
}
