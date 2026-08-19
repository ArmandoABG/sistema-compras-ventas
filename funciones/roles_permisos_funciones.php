<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/integridad_bd.php';

si_requerir_permiso('roles.administrar', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

// Corrige roles/permisos faltantes antes de administrarlos.
si_sincronizar_seguridad_base($conexion);

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) ($metodo === 'GET' ? ($_GET['accion'] ?? 'INICIAL') : ($_POST['accion'] ?? ''))));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');
        if ($accion === 'INICIAL') rp_inicial($conexion);
        if ($accion === 'DETALLE') rp_detalle($conexion);
        si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

    si_requerir_metodo('POST');
    si_validar_csrf();
    if ($accion === 'GUARDAR_PERMISOS') rp_guardar_permisos($conexion);
    si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'ROL-' . date('Ymd-His');
    error_log('[' . $ref . '][ROLES][PDO] ' . $e->getMessage());
    si_responder_json(false, 'No fue posible actualizar los permisos.', ['referencia' => $ref], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'ROL-' . date('Ymd-His');
    error_log('[' . $ref . '][ROLES] ' . $e->getMessage());
    si_responder_json(false, 'Ocurrió un error interno al administrar los permisos.', ['referencia' => $ref], 500);
}

function rp_inicial(PDO $conexion): void
{
    $roles = $conexion->query(
        "SELECT r.id, r.codigo, r.nombre, r.descripcion, r.es_sistema, r.activo,
                COUNT(rp.permiso_id) AS total_permisos,
                (SELECT COUNT(*)
                   FROM usuarios_roles ur
                   INNER JOIN usuarios u ON u.id = ur.usuario_id
                  WHERE ur.rol_id = r.id
                    AND u.deleted_at IS NULL
                    AND u.activo = 1) AS usuarios_activos
           FROM roles r
           LEFT JOIN roles_permisos rp ON rp.rol_id = r.id
          GROUP BY r.id, r.codigo, r.nombre, r.descripcion, r.es_sistema, r.activo
          ORDER BY CASE r.codigo
              WHEN 'ADMINISTRADOR' THEN 1
              WHEN 'VENDEDOR' THEN 2
              WHEN 'SUPERVISOR_ALMACEN' THEN 3
              ELSE 99 END, r.nombre"
    )->fetchAll();

    foreach ($roles as &$rol) {
        $rol['id'] = (int) $rol['id'];
        $rol['es_sistema'] = (int) $rol['es_sistema'];
        $rol['activo'] = (int) $rol['activo'];
        $rol['total_permisos'] = (int) $rol['total_permisos'];
        $rol['usuarios_activos'] = (int) $rol['usuarios_activos'];
        $rol['bloqueado'] = $rol['codigo'] === 'ADMINISTRADOR';
    }
    unset($rol);

    $permisos = $conexion->query("SELECT id, codigo, modulo, nombre, descripcion FROM permisos ORDER BY modulo, nombre, id")->fetchAll();
    foreach ($permisos as &$permiso) $permiso['id'] = (int) $permiso['id'];
    unset($permiso);

    si_responder_json(true, 'Roles y permisos cargados.', [
        'roles' => $roles,
        'permisos' => $permisos,
    ]);
}

function rp_detalle(PDO $conexion): void
{
    $rolId = rp_id($_GET['rol_id'] ?? null);
    $stmt = $conexion->prepare("SELECT id, codigo, nombre, descripcion, es_sistema, activo FROM roles WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $rolId]);
    $rol = $stmt->fetch();
    if (!$rol) si_responder_json(false, 'No se encontró el rol seleccionado.', [], 404);

    $stmt = $conexion->prepare("SELECT permiso_id FROM roles_permisos WHERE rol_id = :rol_id ORDER BY permiso_id");
    $stmt->execute([':rol_id' => $rolId]);

    $rol['id'] = (int) $rol['id'];
    $rol['es_sistema'] = (int) $rol['es_sistema'];
    $rol['activo'] = (int) $rol['activo'];
    $rol['permiso_ids'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $rol['bloqueado'] = $rol['codigo'] === 'ADMINISTRADOR';

    si_responder_json(true, 'Rol cargado.', ['rol' => $rol]);
}

function rp_guardar_permisos(PDO $conexion): void
{
    $rolId = rp_id($_POST['rol_id'] ?? null);
    $ids = $_POST['permiso_ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];

    $permisoIds = [];
    foreach ($ids as $id) {
        $v = filter_var($id, FILTER_VALIDATE_INT);
        if ($v !== false && (int) $v > 0) $permisoIds[] = (int) $v;
    }
    $permisoIds = array_values(array_unique($permisoIds));

    $conexion->beginTransaction();
    $stmtRol = $conexion->prepare("SELECT id, codigo, nombre, activo FROM roles WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmtRol->execute([':id' => $rolId]);
    $rol = $stmtRol->fetch();

    if (!$rol) rp_cancelar($conexion, 'El rol ya no existe.', 404);
    if ((int) $rol['activo'] !== 1) rp_cancelar($conexion, 'El rol se encuentra inactivo.', 409);

    if ($rol['codigo'] === 'ADMINISTRADOR') {
        $permisoIds = array_map('intval', $conexion->query("SELECT id FROM permisos ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$permisoIds) rp_cancelar($conexion, 'Selecciona al menos un permiso.', 422);
    rp_validar_permisos($conexion, $permisoIds);

    $stmtAnterior = $conexion->prepare("SELECT permiso_id FROM roles_permisos WHERE rol_id = :rol_id ORDER BY permiso_id");
    $stmtAnterior->execute([':rol_id' => $rolId]);
    $anteriores = array_map('intval', $stmtAnterior->fetchAll(PDO::FETCH_COLUMN));
    sort($anteriores); sort($permisoIds);

    if ($anteriores === $permisoIds) {
        $conexion->commit();
        si_responder_json(true, 'No se detectaron cambios en los permisos.');
    }

    $conexion->prepare("DELETE FROM roles_permisos WHERE rol_id = :rol_id")->execute([':rol_id' => $rolId]);
    $stmtInsert = $conexion->prepare("INSERT INTO roles_permisos (rol_id, permiso_id) VALUES (:rol_id, :permiso_id)");
    foreach ($permisoIds as $permisoId) $stmtInsert->execute([':rol_id' => $rolId, ':permiso_id' => $permisoId]);

    $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
             datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, 'PERMISOS_ROL_ACTUALIZADOS', 'seguridad', 'roles', :entidad_id,
             :descripcion, :anteriores, :nuevos, :ip, :ua)"
    )->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':entidad_id' => $rolId,
        ':descripcion' => 'Se actualizaron los permisos del rol ' . $rol['nombre'] . '.',
        ':anteriores' => json_encode(['permiso_ids' => $anteriores], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevos' => json_encode(['permiso_ids' => $permisoIds], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':ua' => si_user_agent(),
    ]);

    $actorId = (int) $_SESSION['usuario_id'];

    $stmtCerrar = $conexion->prepare("UPDATE sesiones_usuario s INNER JOIN usuarios_roles ur ON ur.usuario_id = s.usuario_id SET s.activa = 0, s.fin_sesion = COALESCE(s.fin_sesion, NOW()), s.motivo_cierre = 'PERMISOS_ROL_CAMBIADOS' WHERE ur.rol_id = :rol_id AND s.activa = 1 AND s.usuario_id <> :actor_id");
    $stmtCerrar->execute([
        ':rol_id' => $rolId,
        ':actor_id' => $actorId,
    ]);

    $conexion->commit();
    si_cargar_identidad_sesion($conexion, $actorId);

    si_responder_json(true, $rol['codigo'] === 'ADMINISTRADOR'
        ? 'El Administrador conserva acceso total.'
        : 'Permisos actualizados correctamente. Las sesiones afectadas deberán volver a iniciar sesión.');
}

function rp_validar_permisos(PDO $conexion, array $ids): void
{
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM permisos WHERE id IN ({$marks})");
    $stmt->execute(array_values($ids));
    if ((int) $stmt->fetchColumn() !== count($ids)) rp_cancelar($conexion, 'Uno de los permisos seleccionados ya no existe.', 409);
}

function rp_refrescar_permisos_actor(PDO $conexion, int $usuarioId): void
{
    $stmt = $conexion->prepare("SELECT DISTINCT p.codigo FROM usuarios_roles ur INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 INNER JOIN roles_permisos rp ON rp.rol_id = r.id INNER JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = :usuario_id ORDER BY p.codigo");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $_SESSION['permisos'] = array_column($stmt->fetchAll(), 'codigo');
}

function rp_id($valor): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) si_responder_json(false, 'Identificador de rol inválido.', [], 422);
    return (int) $id;
}

function rp_cancelar(PDO $conexion, string $mensaje, int $codigo): void
{
    if ($conexion->inTransaction()) $conexion->rollBack();
    si_responder_json(false, $mensaje, [], $codigo);
}
