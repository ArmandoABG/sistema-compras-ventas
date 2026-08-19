<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('usuarios.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) ($metodo === 'GET' ? ($_GET['accion'] ?? 'LISTAR') : ($_POST['accion'] ?? ''))));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');
        if ($accion === 'LISTAR') usr_listar($conexion);
        if ($accion === 'DETALLE') usr_detalle($conexion);
        if ($accion === 'CATALOGOS') usr_catalogos($conexion);
        if ($accion === 'SESIONES') usr_sesiones($conexion);
        si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if ($accion === 'GUARDAR') usr_guardar($conexion);
    if ($accion === 'CAMBIAR_ESTADO') usr_cambiar_estado($conexion);
    if ($accion === 'CAMBIAR_PASSWORD') usr_cambiar_password($conexion);

    si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'USR-' . date('Ymd-His');
    error_log('[' . $ref . '][USUARIOS][PDO] ' . $e->getMessage());
    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'El usuario o correo ya se encuentra registrado.', ['referencia' => $ref], 409);
    }
    si_responder_json(false, 'No fue posible procesar la cuenta.', ['referencia' => $ref], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    $ref = 'USR-' . date('Ymd-His');
    error_log('[' . $ref . '][USUARIOS] ' . $e->getMessage());
    si_responder_json(false, 'Ocurrió un error interno al procesar la cuenta.', ['referencia' => $ref], 500);
}

function usr_listar(PDO $conexion): void
{
    $pagina = usr_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = usr_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $busqueda = usr_texto($_GET['busqueda'] ?? '', 120);
    $estado = strtoupper(usr_texto($_GET['estado'] ?? 'TODOS', 20));
    $rolId = usr_entero_rango($_GET['rol_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS', 'BLOQUEADOS'], true)) $estado = 'TODOS';

    $where = ['u.deleted_at IS NULL'];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(u.usuario LIKE :busqueda_usuario OR u.nombres LIKE :busqueda_nombres OR u.apellido_paterno LIKE :busqueda_apellido_paterno OR u.apellido_materno LIKE :busqueda_apellido_materno OR u.correo LIKE :busqueda_correo OR u.telefono LIKE :busqueda_telefono)";
        $patronBusqueda = '%' . $busqueda . '%';
        $params[':busqueda_usuario'] = $patronBusqueda;
        $params[':busqueda_nombres'] = $patronBusqueda;
        $params[':busqueda_apellido_paterno'] = $patronBusqueda;
        $params[':busqueda_apellido_materno'] = $patronBusqueda;
        $params[':busqueda_correo'] = $patronBusqueda;
        $params[':busqueda_telefono'] = $patronBusqueda;
    }

    if ($estado === 'ACTIVOS') $where[] = 'u.activo = 1';
    if ($estado === 'INACTIVOS') $where[] = 'u.activo = 0';
    if ($estado === 'BLOQUEADOS') $where[] = 'u.bloqueado_hasta > NOW()';

    if ($rolId > 0) {
        $where[] = "EXISTS (SELECT 1 FROM usuarios_roles ur_f WHERE ur_f.usuario_id = u.id AND ur_f.rol_id = :rol_id)";
        $params[':rol_id'] = $rolId;
    }

    $whereSql = implode(' AND ', $where);
    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM usuarios u WHERE {$whereSql}");
    usr_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                u.id, u.usuario, u.nombres, u.apellido_paterno, u.apellido_materno,
                u.correo, u.telefono, u.activo, u.debe_cambiar_password,
                u.ultimo_acceso, u.intentos_fallidos, u.bloqueado_hasta, u.created_at,
                GROUP_CONCAT(DISTINCT r.nombre ORDER BY r.nombre SEPARATOR ', ') AS roles_nombres,
                GROUP_CONCAT(DISTINCT r.codigo ORDER BY r.codigo SEPARATOR ',') AS roles_codigos
            FROM usuarios u
            LEFT JOIN usuarios_roles ur ON ur.usuario_id = u.id
            LEFT JOIN roles r ON r.id = ur.rol_id AND r.activo = 1
            WHERE {$whereSql}
            GROUP BY u.id, u.usuario, u.nombres, u.apellido_paterno, u.apellido_materno,
                     u.correo, u.telefono, u.activo, u.debe_cambiar_password,
                     u.ultimo_acceso, u.intentos_fallidos, u.bloqueado_hasta, u.created_at
            ORDER BY u.activo DESC, u.nombres ASC, u.apellido_paterno ASC, u.id DESC
            LIMIT :limite OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    usr_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $usuarios = $stmt->fetchAll();

    foreach ($usuarios as &$u) {
        $u['id'] = (int) $u['id'];
        $u['activo'] = (int) $u['activo'];
        $u['debe_cambiar_password'] = (int) $u['debe_cambiar_password'];
        $u['intentos_fallidos'] = (int) $u['intentos_fallidos'];
        $u['nombre_completo'] = usr_nombre_completo($u);
        $u['ultimo_acceso_texto'] = $u['ultimo_acceso'] === null ? 'Nunca' : date('d/m/Y H:i', strtotime((string) $u['ultimo_acceso']));
        $u['bloqueado'] = !empty($u['bloqueado_hasta']) && strtotime((string) $u['bloqueado_hasta']) > time();
        $u['es_usuario_actual'] = $u['id'] === (int) $_SESSION['usuario_id'];
    }
    unset($u);

    si_responder_json(true, 'Usuarios cargados correctamente.', [
        'usuarios' => $usuarios,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
        'resumen' => usr_resumen_general($conexion),
    ]);
}

function usr_detalle(PDO $conexion): void
{
    $id = usr_id($_GET['id'] ?? null);
    $stmt = $conexion->prepare("SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, debe_cambiar_password, ultimo_acceso, intentos_fallidos, bloqueado_hasta, created_at FROM usuarios WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();
    if (!$usuario) si_responder_json(false, 'No se encontró el usuario seleccionado.', [], 404);

    $stmtRoles = $conexion->prepare("SELECT r.id, r.codigo, r.nombre FROM usuarios_roles ur INNER JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :usuario_id AND r.activo = 1 ORDER BY r.nombre");
    $stmtRoles->execute([':usuario_id' => $id]);
    $roles = $stmtRoles->fetchAll();

    $usuario['id'] = (int) $usuario['id'];
    $usuario['activo'] = (int) $usuario['activo'];
    $usuario['debe_cambiar_password'] = (int) $usuario['debe_cambiar_password'];
    $usuario['intentos_fallidos'] = (int) $usuario['intentos_fallidos'];
    $usuario['nombre_completo'] = usr_nombre_completo($usuario);
    $usuario['rol_ids'] = array_map('intval', array_column($roles, 'id'));
    $usuario['roles'] = $roles;

    si_responder_json(true, 'Usuario encontrado.', ['usuario' => $usuario]);
}

function usr_catalogos(PDO $conexion): void
{
    $roles = $conexion->query("SELECT id, codigo, nombre, descripcion, es_sistema FROM roles WHERE activo = 1 ORDER BY CASE codigo WHEN 'ADMINISTRADOR' THEN 1 WHEN 'VENDEDOR' THEN 2 WHEN 'SUPERVISOR_ALMACEN' THEN 3 ELSE 99 END, nombre")->fetchAll();
    foreach ($roles as &$rol) {
        $rol['id'] = (int) $rol['id'];
        $rol['es_sistema'] = (int) $rol['es_sistema'];
    }
    unset($rol);
    si_responder_json(true, 'Catálogos cargados.', ['roles' => $roles]);
}

function usr_sesiones(PDO $conexion): void
{
    $id = usr_id($_GET['usuario_id'] ?? null);

    $pagina = usr_entero_rango(
        $_GET['pagina'] ?? 1,
        1,
        PHP_INT_MAX,
        1
    );

    $porPagina = usr_entero_rango(
        $_GET['por_pagina'] ?? 20,
        10,
        100,
        20
    );

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM sesiones_usuario
         WHERE usuario_id = :usuario_id"
    );

    $stmtTotal->execute([
        ':usuario_id' => $id,
    ]);

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            id,
            ip,
            user_agent,
            inicio_sesion,
            fin_sesion,
            motivo_cierre,
            activa
         FROM sesiones_usuario
         WHERE usuario_id = :usuario_id
         ORDER BY id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    $stmt->bindValue(':usuario_id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $sesiones = $stmt->fetchAll();

    foreach ($sesiones as &$s) {
        $s['id'] = (int) $s['id'];
        $s['activa'] = (int) $s['activa'];
    }
    unset($s);

    si_responder_json(
        true,
        'Sesiones cargadas.',
        [
            'sesiones' => $sesiones,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function usr_guardar(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['usuario_id'] ?? ''));
    $id = $idTexto === '' ? 0 : usr_id($idTexto);
    $esNuevo = $id === 0;

    if ($esNuevo && !si_tiene_permiso('usuarios.crear')) si_responder_json(false, 'No tienes permiso para crear usuarios.', [], 403);
    if (!$esNuevo && !si_tiene_permiso('usuarios.editar')) si_responder_json(false, 'No tienes permiso para editar usuarios.', [], 403);

    $usuario = usr_validar_usuario($_POST['usuario'] ?? '');
    $nombres = usr_validar_nombre($_POST['nombres'] ?? '', 'nombres', true, 120);
    $apellidoPaterno = usr_validar_nombre($_POST['apellido_paterno'] ?? '', 'apellido_paterno', false, 100);
    $apellidoMaterno = usr_validar_nombre($_POST['apellido_materno'] ?? '', 'apellido_materno', false, 100);
    $correo = usr_validar_correo($_POST['correo'] ?? '');
    $telefono = usr_validar_telefono($_POST['telefono'] ?? '');
    $rolIds = usr_validar_roles($_POST['rol_ids'] ?? []);
    $password = (string) ($_POST['password'] ?? '');
    $confirmar = (string) ($_POST['confirmar_password'] ?? '');
    if ($esNuevo) usr_validar_password($password, $confirmar);

    $conexion->beginTransaction();
    $anterior = null;
    $rolesAnteriores = [];

    if (!$esNuevo) {
        $anterior = usr_bloquear_usuario($conexion, $id);
        if (!$anterior) usr_cancelar($conexion, 'El usuario ya no existe.', 404);
        $rolesAnteriores = usr_obtener_role_ids($conexion, $id);
    }

    usr_validar_usuario_unico($conexion, $usuario, $id);
    usr_validar_correo_unico($conexion, $correo, $id);
    usr_validar_roles_existentes($conexion, $rolIds);

    if (!$esNuevo
        && usr_tiene_rol_codigo($conexion, $id, 'ADMINISTRADOR')
        && !usr_lista_roles_contiene_codigo($conexion, $rolIds, 'ADMINISTRADOR')
        && (int) $anterior['activo'] === 1
        && usr_contar_administradores_activos($conexion, $id) === 0) {
        usr_cancelar($conexion, 'No puedes quitar el rol Administrador al último administrador activo del sistema.', 409);
    }

    if ($esNuevo) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) throw new RuntimeException('No fue posible proteger la contraseña.');
        $stmt = $conexion->prepare("INSERT INTO usuarios (usuario, password_hash, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, debe_cambiar_password) VALUES (:usuario, :password_hash, :nombres, :apellido_paterno, :apellido_materno, :correo, :telefono, 1, 0)");
        $stmt->execute([
            ':usuario' => $usuario,
            ':password_hash' => $hash,
            ':nombres' => $nombres,
            ':apellido_paterno' => $apellidoPaterno,
            ':apellido_materno' => $apellidoMaterno,
            ':correo' => $correo,
            ':telefono' => $telefono,
        ]);
        $id = (int) $conexion->lastInsertId();
    } else {
        $stmt = $conexion->prepare("UPDATE usuarios SET usuario = :usuario, nombres = :nombres, apellido_paterno = :apellido_paterno, apellido_materno = :apellido_materno, correo = :correo, telefono = :telefono WHERE id = :id");
        $stmt->execute([
            ':usuario' => $usuario,
            ':nombres' => $nombres,
            ':apellido_paterno' => $apellidoPaterno,
            ':apellido_materno' => $apellidoMaterno,
            ':correo' => $correo,
            ':telefono' => $telefono,
            ':id' => $id,
        ]);
    }

    $conexion->prepare("DELETE FROM usuarios_roles WHERE usuario_id = :usuario_id")->execute([':usuario_id' => $id]);
    $stmtRol = $conexion->prepare("INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (:usuario_id, :rol_id)");
    foreach ($rolIds as $rolId) $stmtRol->execute([':usuario_id' => $id, ':rol_id' => $rolId]);

    $nuevo = [
        'id' => $id,
        'usuario' => $usuario,
        'nombres' => $nombres,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'correo' => $correo,
        'telefono' => $telefono,
        'rol_ids' => $rolIds,
    ];

    $actorId = (int) $_SESSION['usuario_id'];
    usr_auditar($conexion, $actorId, $esNuevo ? 'USUARIO_CREADO' : 'USUARIO_EDITADO', $id, $esNuevo ? 'Se registró un usuario.' : 'Se actualizó un usuario.', $anterior ? usr_datos_seguro($anterior) : null, $nuevo);

    $rolesNuevosOrdenados = $rolIds;
    sort($rolesNuevosOrdenados);
    $rolesAnterioresOrdenados = $rolesAnteriores;
    sort($rolesAnterioresOrdenados);
    $cambiaronRoles = !$esNuevo && $rolesAnterioresOrdenados !== $rolesNuevosOrdenados;

    if ($cambiaronRoles && $id !== $actorId) {
        $conexion->prepare("UPDATE sesiones_usuario SET activa = 0, fin_sesion = COALESCE(fin_sesion, NOW()), motivo_cierre = 'ROLES_ACTUALIZADOS' WHERE usuario_id = :usuario_id AND activa = 1")->execute([':usuario_id' => $id]);
    }

    $conexion->commit();

    if ($id === $actorId) {
        usr_refrescar_sesion_actor($conexion, $actorId);
    }

    si_responder_json(true, $esNuevo ? 'Usuario registrado correctamente.' : 'Usuario actualizado correctamente.', ['usuario_id' => $id], $esNuevo ? 201 : 200);
}

function usr_cambiar_estado(PDO $conexion): void
{
    if (!si_tiene_permiso('usuarios.desactivar')) si_responder_json(false, 'No tienes permiso para cambiar el estado de usuarios.', [], 403);

    $id = usr_id($_POST['usuario_id'] ?? null);
    $activo = isset($_POST['activo']) ? (int) $_POST['activo'] : -1;
    if (!in_array($activo, [0, 1], true)) si_responder_json(false, 'Estado inválido.', [], 422);

    $actorId = (int) $_SESSION['usuario_id'];
    if ($activo === 0 && $id === $actorId) si_responder_json(false, 'No puedes desactivar tu propia cuenta.', [], 409);

    $conexion->beginTransaction();
    $usuario = usr_bloquear_usuario($conexion, $id);
    if (!$usuario) usr_cancelar($conexion, 'El usuario ya no existe.', 404);
    if ((int) $usuario['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'La cuenta ya se encontraba en ese estado.');
    }

    if ($activo === 0 && usr_tiene_rol_codigo($conexion, $id, 'ADMINISTRADOR') && usr_contar_administradores_activos($conexion, $id) === 0) {
        usr_cancelar($conexion, 'No puedes desactivar al último administrador activo del sistema.', 409);
    }

    if ($activo === 1) {
        $conexion->prepare("UPDATE usuarios SET activo = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id")->execute([':id' => $id]);
    } else {
        $conexion->prepare("UPDATE usuarios SET activo = 0 WHERE id = :id")->execute([':id' => $id]);
    }

    if ($activo === 0) {
        $conexion->prepare("UPDATE sesiones_usuario SET activa = 0, fin_sesion = COALESCE(fin_sesion, NOW()), motivo_cierre = 'USUARIO_DESACTIVADO' WHERE usuario_id = :usuario_id AND activa = 1")->execute([':usuario_id' => $id]);
    }

    usr_auditar($conexion, $actorId, $activo === 1 ? 'USUARIO_ACTIVADO' : 'USUARIO_DESACTIVADO', $id, $activo === 1 ? 'Se activó una cuenta de usuario.' : 'Se desactivó una cuenta de usuario.', ['activo' => (int) $usuario['activo']], ['activo' => $activo]);
    $conexion->commit();
    si_responder_json(true, $activo === 1 ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.');
}

function usr_cambiar_password(PDO $conexion): void
{
    if (!si_tiene_permiso('usuarios.editar')) si_responder_json(false, 'No tienes permiso para restablecer contraseñas.', [], 403);

    $id = usr_id($_POST['usuario_id'] ?? null);
    $passwordActor = (string) ($_POST['password_actor'] ?? '');
    $nueva = (string) ($_POST['nueva_password'] ?? '');
    $confirmar = (string) ($_POST['confirmar_password'] ?? '');
    usr_validar_password($nueva, $confirmar);
    if ($passwordActor === '') si_responder_json(false, 'Ingresa tu contraseña actual para autorizar el cambio.', ['campo' => 'password_actor'], 422);

    $actorId = (int) $_SESSION['usuario_id'];
    $stmtActor = $conexion->prepare("SELECT password_hash FROM usuarios WHERE id = :id AND activo = 1 AND deleted_at IS NULL LIMIT 1");
    $stmtActor->execute([':id' => $actorId]);
    $hashActor = $stmtActor->fetchColumn();
    if (!$hashActor || !password_verify($passwordActor, (string) $hashActor)) si_responder_json(false, 'Tu contraseña de autorización es incorrecta.', ['campo' => 'password_actor'], 403);

    $conexion->beginTransaction();
    $usuario = usr_bloquear_usuario($conexion, $id);
    if (!$usuario) usr_cancelar($conexion, 'El usuario ya no existe.', 404);

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    if ($hash === false) throw new RuntimeException('No fue posible proteger la nueva contraseña.');

    $conexion->prepare("UPDATE usuarios SET password_hash = :password_hash, intentos_fallidos = 0, bloqueado_hasta = NULL, debe_cambiar_password = 0 WHERE id = :id")->execute([':password_hash' => $hash, ':id' => $id]);

    if ($id !== $actorId) {
        $conexion->prepare("UPDATE sesiones_usuario SET activa = 0, fin_sesion = COALESCE(fin_sesion, NOW()), motivo_cierre = 'PASSWORD_RESTABLECIDA' WHERE usuario_id = :usuario_id AND activa = 1")->execute([':usuario_id' => $id]);
    }

    usr_auditar($conexion, $actorId, 'PASSWORD_RESTABLECIDA', $id, 'Se restableció la contraseña de una cuenta.', null, null);
    $conexion->commit();
    si_responder_json(true, 'Contraseña actualizada correctamente.');
}

function usr_resumen_general(PDO $conexion): array
{
    $f = $conexion->query("SELECT COUNT(*) AS total, SUM(activo = 1) AS activos, SUM(activo = 0) AS inactivos, SUM(bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueados, SUM(ultimo_acceso IS NULL) AS sin_ingreso FROM usuarios WHERE deleted_at IS NULL")->fetch();
    return [
        'total' => (int) ($f['total'] ?? 0),
        'activos' => (int) ($f['activos'] ?? 0),
        'inactivos' => (int) ($f['inactivos'] ?? 0),
        'bloqueados' => (int) ($f['bloqueados'] ?? 0),
        'sin_ingreso' => (int) ($f['sin_ingreso'] ?? 0),
    ];
}

function usr_bloquear_usuario(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, intentos_fallidos, bloqueado_hasta, deleted_at FROM usuarios WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $id]);
    $f = $stmt->fetch();
    return $f ?: null;
}

function usr_obtener_role_ids(PDO $conexion, int $usuarioId): array
{
    $stmt = $conexion->prepare("SELECT rol_id FROM usuarios_roles WHERE usuario_id = :usuario_id ORDER BY rol_id");
    $stmt->execute([':usuario_id' => $usuarioId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function usr_refrescar_sesion_actor(PDO $conexion, int $usuarioId): void
{
    $stmt = $conexion->prepare("SELECT usuario, nombres, apellido_paterno, apellido_materno FROM usuarios WHERE id = :id AND activo = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $usuarioId]);
    $usuario = $stmt->fetch();
    if (!$usuario) return;

    $stmt = $conexion->prepare("SELECT r.codigo, r.nombre FROM usuarios_roles ur INNER JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :usuario_id AND r.activo = 1 ORDER BY CASE r.codigo WHEN 'ADMINISTRADOR' THEN 1 WHEN 'VENDEDOR' THEN 2 WHEN 'SUPERVISOR_ALMACEN' THEN 3 ELSE 99 END, r.nombre");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $roles = $stmt->fetchAll();

    $stmt = $conexion->prepare("SELECT DISTINCT p.codigo FROM usuarios_roles ur INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 INNER JOIN roles_permisos rp ON rp.rol_id = r.id INNER JOIN permisos p ON p.id = rp.permiso_id WHERE ur.usuario_id = :usuario_id ORDER BY p.codigo");
    $stmt->execute([':usuario_id' => $usuarioId]);

    $_SESSION['usuario'] = (string) $usuario['usuario'];
    $_SESSION['nombre_completo'] = usr_nombre_completo($usuario);
    $_SESSION['roles'] = array_column($roles, 'codigo');
    $_SESSION['rol_codigo'] = (string) ($roles[0]['codigo'] ?? '');
    $_SESSION['rol_nombre'] = (string) ($roles[0]['nombre'] ?? 'Usuario');
    $_SESSION['permisos'] = array_column($stmt->fetchAll(), 'codigo');
}

function usr_contar_administradores_activos(PDO $conexion, int $excluirId = 0): int
{
    $sql = "SELECT COUNT(DISTINCT u.id) FROM usuarios u INNER JOIN usuarios_roles ur ON ur.usuario_id = u.id INNER JOIN roles r ON r.id = ur.rol_id WHERE u.deleted_at IS NULL AND u.activo = 1 AND r.codigo = 'ADMINISTRADOR' AND r.activo = 1";
    if ($excluirId > 0) $sql .= " AND u.id <> :excluir";
    $stmt = $conexion->prepare($sql);
    if ($excluirId > 0) $stmt->bindValue(':excluir', $excluirId, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function usr_tiene_rol_codigo(PDO $conexion, int $usuarioId, string $codigo): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM usuarios_roles ur INNER JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = :usuario_id AND r.codigo = :codigo AND r.activo = 1 LIMIT 1");
    $stmt->execute([':usuario_id' => $usuarioId, ':codigo' => $codigo]);
    return (bool) $stmt->fetchColumn();
}

function usr_lista_roles_contiene_codigo(PDO $conexion, array $rolIds, string $codigo): bool
{
    if (!$rolIds) return false;
    $marks = implode(',', array_fill(0, count($rolIds), '?'));
    $stmt = $conexion->prepare("SELECT 1 FROM roles WHERE id IN ({$marks}) AND codigo = ? AND activo = 1 LIMIT 1");
    $vals = array_values($rolIds); $vals[] = $codigo;
    $stmt->execute($vals);
    return (bool) $stmt->fetchColumn();
}

function usr_validar_roles_existentes(PDO $conexion, array $rolIds): void
{
    $marks = implode(',', array_fill(0, count($rolIds), '?'));
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM roles WHERE activo = 1 AND id IN ({$marks})");
    $stmt->execute(array_values($rolIds));
    if ((int) $stmt->fetchColumn() !== count($rolIds)) usr_cancelar($conexion, 'Uno de los roles seleccionados ya no está disponible.', 409);
}

function usr_validar_usuario_unico(PDO $conexion, string $usuario, int $excluirId): void
{
    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = :usuario AND id <> :id LIMIT 1");
    $stmt->execute([':usuario' => $usuario, ':id' => $excluirId]);
    if ($stmt->fetchColumn()) usr_cancelar($conexion, 'El nombre de usuario ya está registrado.', 409, ['campo' => 'usuario']);
}

function usr_validar_correo_unico(PDO $conexion, ?string $correo, int $excluirId): void
{
    if ($correo === null) return;
    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE correo = :correo AND id <> :id LIMIT 1");
    $stmt->execute([':correo' => $correo, ':id' => $excluirId]);
    if ($stmt->fetchColumn()) usr_cancelar($conexion, 'El correo ya está registrado.', 409, ['campo' => 'correo']);
}

function usr_validar_roles($valor): array
{
    if (!is_array($valor)) $valor = [$valor];
    $roles = [];
    foreach ($valor as $id) {
        $v = filter_var($id, FILTER_VALIDATE_INT);
        if ($v !== false && (int) $v > 0) $roles[] = (int) $v;
    }
    $roles = array_values(array_unique($roles));
    if (!$roles) si_responder_json(false, 'Selecciona al menos un rol.', ['campo' => 'roles'], 422);
    return $roles;
}

function usr_validar_usuario($valor): string
{
    $u = trim((string) $valor);
    if (!preg_match('/^[A-Za-z0-9._-]{4,60}$/', $u)) si_responder_json(false, 'El usuario debe tener entre 4 y 60 caracteres y usar letras, números, punto, guion o guion bajo.', ['campo' => 'usuario'], 422);
    return $u;
}

function usr_validar_nombre($valor, string $campo, bool $requerido, int $maximo): ?string
{
    $t = trim((string) $valor);
    if ($t === '') {
        if ($requerido) si_responder_json(false, 'El nombre es obligatorio.', ['campo' => $campo], 422);
        return null;
    }
    if (mb_strlen($t) > $maximo) si_responder_json(false, 'El campo supera la longitud permitida.', ['campo' => $campo], 422);
    return $t;
}

function usr_validar_correo($valor): ?string
{
    $c = trim((string) $valor);
    if ($c === '') return null;
    if (mb_strlen($c) > 180 || !filter_var($c, FILTER_VALIDATE_EMAIL)) si_responder_json(false, 'Ingresa un correo válido.', ['campo' => 'correo'], 422);
    return mb_strtolower($c);
}

function usr_validar_telefono($valor): ?string
{
    $t = trim((string) $valor);
    if ($t === '') return null;
    if (mb_strlen($t) > 30 || !preg_match('/^[0-9+()\-\s.]{7,30}$/', $t)) si_responder_json(false, 'Ingresa un teléfono válido.', ['campo' => 'telefono'], 422);
    return $t;
}

function usr_validar_password(string $password, string $confirmar): void
{
    if (strlen($password) < 10 || strlen($password) > 72) si_responder_json(false, 'La contraseña debe tener entre 10 y 72 caracteres.', ['campo' => 'password'], 422);
    if ($password !== $confirmar) si_responder_json(false, 'Las contraseñas no coinciden.', ['campo' => 'confirmar_password'], 422);
}

function usr_nombre_completo(array $f): string
{
    return trim(implode(' ', array_filter([$f['nombres'] ?? '', $f['apellido_paterno'] ?? '', $f['apellido_materno'] ?? ''])));
}

function usr_texto($valor, int $max): string
{
    $t = trim((string) $valor);
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) : $t;
}

function usr_id($valor): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) si_responder_json(false, 'Identificador de usuario inválido.', [], 422);
    return (int) $id;
}

function usr_entero_rango($valor, int $min, int $max, int $default): int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT);
    if ($n === false) return $default;
    $n = (int) $n;
    return ($n < $min || $n > $max) ? $default : $n;
}

function usr_bind_params(PDOStatement $stmt, array $params): void
{
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, $k === ':rol_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function usr_datos_seguro(array $f): array
{
    return [
        'id' => (int) ($f['id'] ?? 0),
        'usuario' => $f['usuario'] ?? null,
        'nombres' => $f['nombres'] ?? null,
        'apellido_paterno' => $f['apellido_paterno'] ?? null,
        'apellido_materno' => $f['apellido_materno'] ?? null,
        'correo' => $f['correo'] ?? null,
        'telefono' => $f['telefono'] ?? null,
        'activo' => isset($f['activo']) ? (int) $f['activo'] : null,
    ];
}

function usr_auditar(PDO $conexion, int $actorId, string $accion, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare("INSERT INTO auditoria (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent) VALUES (:usuario_id, :accion, 'seguridad', 'usuarios', :entidad_id, :descripcion, :anteriores, :nuevos, :ip, :ua)");
    $stmt->execute([
        ':usuario_id' => $actorId,
        ':accion' => $accion,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anteriores' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevos' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':ua' => si_user_agent(),
    ]);
}

function usr_cancelar(PDO $conexion, string $mensaje, int $codigo, array $extra = []): void
{
    if ($conexion->inTransaction()) $conexion->rollBack();
    si_responder_json(false, $mensaje, $extra, $codigo);
}
