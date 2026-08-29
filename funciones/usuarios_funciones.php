<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/notificaciones_stock_email.php';

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
        if ($accion === 'DESTINATARIOS_ALERTAS') usr_destinatarios_alertas($conexion);
        si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if ($accion === 'GUARDAR') usr_guardar($conexion);
    if ($accion === 'CAMBIAR_ESTADO') usr_cambiar_estado($conexion);
    if ($accion === 'CAMBIAR_PASSWORD') usr_cambiar_password($conexion);
    if ($accion === 'GUARDAR_DESTINATARIOS_ALERTAS') usr_guardar_destinatarios_alertas($conexion);
    if ($accion === 'ENVIAR_PRUEBA_ALERTAS') usr_enviar_prueba_alertas($conexion);
    if ($accion === 'ENVIAR_ALERTAS_AHORA') usr_enviar_alertas_ahora($conexion);

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

    // Condición base: evita generar un WHERE vacío cuando no hay filtros.
    $where = ['1=1'];
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
    $stmt = $conexion->prepare("SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, debe_cambiar_password, ultimo_acceso, intentos_fallidos, bloqueado_hasta, created_at FROM usuarios WHERE id = :id LIMIT 1");
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
        $stmt = $conexion->prepare("INSERT INTO usuarios (usuario, password_hash, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, debe_cambiar_password) VALUES (:usuario, :password_hash, :nombres, :apellido_paterno, :apellido_materno, :correo, :telefono, 1, 1)");
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
    $roles = $_SESSION['roles'] ?? [];
    if (!is_array($roles) || !in_array('ADMINISTRADOR', $roles, true)) {
        si_responder_json(false, 'Solo un Administrador puede restablecer contraseñas de otras cuentas.', [], 403);
    }

    $id = usr_id($_POST['usuario_id'] ?? null);
    $actorId = (int) ($_SESSION['usuario_id'] ?? 0);

    if ($id === $actorId) {
        si_responder_json(
            false,
            'Para cambiar tu propia contraseña usa Mi perfil en la barra superior.',
            ['usar_perfil' => true],
            409
        );
    }

    $passwordActor = (string) ($_POST['password_actor'] ?? '');
    $nueva = (string) ($_POST['nueva_password'] ?? '');
    $confirmar = (string) ($_POST['confirmar_password'] ?? '');

    usr_validar_password($nueva, $confirmar);

    if ($passwordActor === '') {
        si_responder_json(
            false,
            'Ingresa tu contraseña de Administrador para autorizar el restablecimiento.',
            ['campo' => 'password_actor'],
            422
        );
    }

    $stmtActor = $conexion->prepare(
        "SELECT password_hash
         FROM usuarios
         WHERE id = :id
           AND activo = 1
         LIMIT 1"
    );
    $stmtActor->execute([':id' => $actorId]);
    $hashActor = $stmtActor->fetchColumn();

    if (!$hashActor || !password_verify($passwordActor, (string) $hashActor)) {
        si_responder_json(
            false,
            'Tu contraseña de Administrador es incorrecta.',
            ['campo' => 'password_actor'],
            403
        );
    }

    $conexion->beginTransaction();
    $usuario = usr_bloquear_usuario($conexion, $id);

    if (!$usuario) {
        usr_cancelar($conexion, 'El usuario ya no existe.', 404);
    }

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('No fue posible proteger la contraseña temporal.');
    }

    $conexion->prepare(
        "UPDATE usuarios
         SET password_hash = :password_hash,
             intentos_fallidos = 0,
             bloqueado_hasta = NULL,
             debe_cambiar_password = 1
         WHERE id = :id"
    )->execute([
        ':password_hash' => $hash,
        ':id' => $id,
    ]);

    $stmtCerrar = $conexion->prepare(
        "UPDATE sesiones_usuario
         SET activa = 0,
             fin_sesion = COALESCE(fin_sesion, NOW()),
             motivo_cierre = 'PASSWORD_RESTABLECIDA_ADMIN'
         WHERE usuario_id = :usuario_id
           AND activa = 1"
    );
    $stmtCerrar->execute([':usuario_id' => $id]);

    usr_auditar(
        $conexion,
        $actorId,
        'PASSWORD_RESTABLECIDA_ADMIN',
        $id,
        'Un Administrador estableció una contraseña temporal. El usuario deberá cambiarla en su próximo inicio de sesión.',
        null,
        [
            'debe_cambiar_password' => 1,
            'sesiones_cerradas' => $stmtCerrar->rowCount(),
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Contraseña temporal establecida. El usuario deberá cambiarla al iniciar sesión.'
    );
}

function usr_resumen_general(PDO $conexion): array
{
    $f = $conexion->query("SELECT COUNT(*) AS total, SUM(activo = 1) AS activos, SUM(activo = 0) AS inactivos, SUM(bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueados, SUM(ultimo_acceso IS NULL) AS sin_ingreso FROM usuarios WHERE 1=1")->fetch();
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
    $stmt = $conexion->prepare("SELECT id, usuario, nombres, apellido_paterno, apellido_materno, correo, telefono, activo, intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id = :id LIMIT 1 FOR UPDATE");
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
    $stmt = $conexion->prepare("SELECT usuario, nombres, apellido_paterno, apellido_materno FROM usuarios WHERE id = :id AND activo = 1 LIMIT 1");
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
    $sql = "SELECT COUNT(DISTINCT u.id) FROM usuarios u INNER JOIN usuarios_roles ur ON ur.usuario_id = u.id INNER JOIN roles r ON r.id = ur.rol_id WHERE 1=1 AND u.activo = 1 AND r.codigo = 'ADMINISTRADOR' AND r.activo = 1";
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

function usr_destinatarios_alertas(PDO $conexion): void
{
    usr_requerir_administrador_alertas();

    $pagina = usr_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = usr_entero_rango($_GET['por_pagina'] ?? 20, 10, 50, 20);
    $busqueda = usr_texto($_GET['busqueda'] ?? '', 120);

    $where = ['u.activo = 1'];
    $params = [];
    if ($busqueda !== '') {
        $patron = '%' . $busqueda . '%';
        $where[] = "(u.usuario LIKE :b_usuario
            OR u.nombres LIKE :b_nombres
            OR u.apellido_paterno LIKE :b_apellido_p
            OR u.apellido_materno LIKE :b_apellido_m
            OR u.correo LIKE :b_correo
            OR EXISTS (
                SELECT 1
                FROM usuarios_roles ur_bus
                INNER JOIN roles r_bus ON r_bus.id = ur_bus.rol_id AND r_bus.activo = 1
                WHERE ur_bus.usuario_id = u.id AND r_bus.nombre LIKE :b_rol
            ))";
        $params = [
            ':b_usuario' => $patron,
            ':b_nombres' => $patron,
            ':b_apellido_p' => $patron,
            ':b_apellido_m' => $patron,
            ':b_correo' => $patron,
            ':b_rol' => $patron,
        ];
    }
    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM usuarios u WHERE {$whereSql}");
    foreach ($params as $clave => $valor) $stmtTotal->bindValue($clave, $valor, PDO::PARAM_STR);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) $pagina = $totalPaginas;
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                u.id,
                u.usuario,
                u.nombres,
                u.apellido_paterno,
                u.apellido_materno,
                u.correo,
                CASE WHEN d.usuario_id IS NULL OR d.activo <> 1 THEN 0 ELSE 1 END AS seleccionado,
                CASE WHEN EXISTS (
                    SELECT 1 FROM usuarios_roles ur_adm
                    INNER JOIN roles r_adm ON r_adm.id = ur_adm.rol_id AND r_adm.activo = 1
                    WHERE ur_adm.usuario_id = u.id AND r_adm.codigo = 'ADMINISTRADOR'
                ) THEN 1 ELSE 0 END AS es_administrador
            FROM usuarios u
            LEFT JOIN alertas_stock_email_destinatarios d ON d.usuario_id = u.id
            WHERE {$whereSql}
            ORDER BY seleccionado DESC, es_administrador DESC, u.nombres, u.apellido_paterno, u.usuario, u.id
            LIMIT :limite OFFSET :offset";
    $stmt = $conexion->prepare($sql);
    foreach ($params as $clave => $valor) $stmt->bindValue($clave, $valor, PDO::PARAM_STR);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    $rolesPorUsuario = [];
    $idsPagina = array_map(static fn(array $fila): int => (int) $fila['id'], $filas);
    if ($idsPagina) {
        $marcas = implode(',', array_fill(0, count($idsPagina), '?'));
        $stmtRoles = $conexion->prepare(
            "SELECT ur.usuario_id,
                    GROUP_CONCAT(DISTINCT r.nombre ORDER BY
                        CASE r.codigo WHEN 'ADMINISTRADOR' THEN 1 WHEN 'SUPERVISOR_ALMACEN' THEN 2 WHEN 'VENDEDOR' THEN 3 ELSE 99 END,
                        r.nombre SEPARATOR ', ') AS roles_nombres
             FROM usuarios_roles ur
             INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1
             WHERE ur.usuario_id IN ({$marcas})
             GROUP BY ur.usuario_id"
        );
        $stmtRoles->execute($idsPagina);
        foreach ($stmtRoles->fetchAll() as $rolFila) {
            $rolesPorUsuario[(int) $rolFila['usuario_id']] = (string) ($rolFila['roles_nombres'] ?? '');
        }
    }

    $usuarios = [];
    foreach ($filas as $fila) {
        $correo = trim((string) ($fila['correo'] ?? ''));
        $correoValido = $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
        $seleccionado = (int) ($fila['seleccionado'] ?? 0) === 1 && $correoValido;
        $id = (int) $fila['id'];
        $usuarios[] = [
            'id' => $id,
            'usuario' => (string) $fila['usuario'],
            'nombre_completo' => usr_nombre_completo($fila),
            'correo' => $correo,
            'correo_valido' => $correoValido,
            'seleccionado' => $seleccionado,
            'roles_nombres' => $rolesPorUsuario[$id] ?? 'Sin rol',
            'es_administrador' => (int) ($fila['es_administrador'] ?? 0) === 1,
        ];
    }

    // Solo se recuperan los IDs ya guardados (máximo 500 por la propia regla de configuración),
    // no la ficha completa de todos los usuarios. Esto permite conservar selecciones entre páginas.
    $seleccionadosIds = [];
    $stmtSeleccionados = $conexion->query(
        "SELECT u.id, u.correo
         FROM alertas_stock_email_destinatarios d
         INNER JOIN usuarios u ON u.id = d.usuario_id
         WHERE d.activo = 1 AND u.activo = 1
         ORDER BY u.id"
    );
    foreach ($stmtSeleccionados->fetchAll() as $filaSel) {
        $correoSel = trim((string) ($filaSel['correo'] ?? ''));
        if (filter_var($correoSel, FILTER_VALIDATE_EMAIL)) {
            $seleccionadosIds[] = (int) $filaSel['id'];
        }
    }

    si_responder_json(true, 'Destinatarios cargados correctamente.', [
        'usuarios' => $usuarios,
        'seleccionados_ids' => $seleccionadosIds,
        'seleccionados' => count($seleccionadosIds),
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
        'remitente' => (string) (si_correo_stock_config()['remitente_correo'] ?? 'Configurado'),
        'reglas' => [
            'critico' => 'Correo individual solo en stock crítico.',
            'reorden' => 'El punto de reorden se incluye únicamente en el resumen diario.',
            'resumen_hora' => '08:00',
        ],
    ]);
}

function usr_guardar_destinatarios_alertas(PDO $conexion): void
{
    usr_requerir_administrador_alertas();
    $actorId = (int) ($_SESSION['usuario_id'] ?? 0);

    $entrada = $_POST['usuario_ids'] ?? [];
    if (!is_array($entrada)) $entrada = [$entrada];

    $ids = [];
    foreach ($entrada as $valor) {
        if (filter_var($valor, FILTER_VALIDATE_INT) === false) continue;
        $id = (int) $valor;
        if ($id > 0) $ids[$id] = $id;
    }
    $ids = array_values($ids);
    if (count($ids) > 500) {
        si_responder_json(false, 'La selección de destinatarios excede el límite permitido.', [], 422);
    }

    if ($ids) {
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $ph = ':u' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }
        $stmt = $conexion->prepare(
            "SELECT id, correo, activo
             FROM usuarios
             WHERE id IN (" . implode(',', $placeholders) . ")"
        );
        foreach ($params as $ph => $id) $stmt->bindValue($ph, $id, PDO::PARAM_INT);
        $stmt->execute();
        $validos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $correo = trim((string) ($fila['correo'] ?? ''));
            if ((int) $fila['activo'] === 1 && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $validos[(int) $fila['id']] = true;
            }
        }
        foreach ($ids as $id) {
            if (!isset($validos[$id])) {
                si_responder_json(false, 'Uno de los usuarios seleccionados está inactivo o no tiene un correo válido.', [], 422);
            }
        }
    }

    $anteriores = array_map('intval', $conexion->query(
        "SELECT usuario_id FROM alertas_stock_email_destinatarios WHERE activo = 1 ORDER BY usuario_id"
    )->fetchAll(PDO::FETCH_COLUMN));

    $conexion->beginTransaction();
    try {
        $conexion->exec("DELETE FROM alertas_stock_email_destinatarios");
        if ($ids) {
            $stmtInsert = $conexion->prepare(
                "INSERT INTO alertas_stock_email_destinatarios
                    (usuario_id, activo, created_by, updated_by)
                 VALUES (:usuario_id, 1, :created_by, :updated_by)"
            );
            foreach ($ids as $id) {
                $stmtInsert->execute([
                    ':usuario_id' => $id,
                    ':created_by' => $actorId > 0 ? $actorId : null,
                    ':updated_by' => $actorId > 0 ? $actorId : null,
                ]);
            }
        }

        $nuevos = $ids;
        sort($anteriores);
        sort($nuevos);

        $stmtAudit = $conexion->prepare(
            "INSERT INTO auditoria
                (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
                 datos_anteriores, datos_nuevos, ip, user_agent)
             VALUES
                (:usuario_id, 'DESTINATARIOS_EMAIL_STOCK_ACTUALIZADOS', 'seguridad',
                 'alertas_stock_email_destinatarios', NULL,
                 'El Administrador actualizó los destinatarios de alertas de inventario por correo.',
                 :anteriores, :nuevos, :ip, :ua)"
        );
        $stmtAudit->execute([
            ':usuario_id' => $actorId > 0 ? $actorId : null,
            ':anteriores' => json_encode(['usuario_ids' => $anteriores], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':nuevos' => json_encode(['usuario_ids' => $nuevos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip' => si_ip_cliente(),
            ':ua' => si_user_agent(),
        ]);

        $conexion->commit();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        throw $e;
    }

    si_responder_json(true, count($ids) === 1
        ? 'Se configuró 1 destinatario para las alertas por correo.'
        : 'Se configuraron ' . count($ids) . ' destinatarios para las alertas por correo.', [
        'seleccionados' => count($ids),
    ]);
}

function usr_enviar_prueba_alertas(PDO $conexion): void
{
    usr_requerir_administrador_alertas();
    $destinatarios = si_stock_email_destinatarios($conexion);
    if (!$destinatarios) {
        si_responder_json(false, 'Primero configura y guarda al menos un destinatario con correo válido.', [], 409);
    }

    $config = si_correo_stock_config();
    if (empty($config['activo'])) {
        si_responder_json(false, 'El envío de correos está desactivado en la configuración.', [], 409);
    }

    $enviados = 0;
    $errores = 0;
    $primerError = null;
    $fecha = date('d/m/Y H:i:s');

    foreach ($destinatarios as $destinatario) {
        $nombre = htmlspecialchars((string) $destinatario['nombre'], ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#26352c">'
            . '<h2 style="color:#14532d;margin-bottom:8px">Prueba de alertas por correo</h2>'
            . '<p>Hola <strong>' . $nombre . '</strong>.</p>'
            . '<p>Este mensaje confirma que tu correo está configurado correctamente como destinatario de las alertas de inventario del Sistema Integral.</p>'
            . '<div style="background:#f3f7f4;border-left:5px solid #15803d;padding:16px;border-radius:8px;margin:18px 0">'
            . '<strong>Configuración correcta</strong><br><span style="color:#647067">Prueba realizada el ' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '.</span>'
            . '</div>'
            . '<p>Los avisos individuales reales solo se enviarán cuando un producto alcance stock crítico. El punto de reorden se informa mediante el resumen diario.</p>'
            . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0">'
            . '<small style="color:#6b7280">Mensaje de prueba del Sistema Integral.</small></div>';

        $resultado = si_smtp_enviar(
            $config,
            (string) $destinatario['correo'],
            (string) $destinatario['nombre'],
            'Prueba de alertas de inventario · Sistema Integral',
            $html
        );
        if (!empty($resultado['ok'])) {
            $enviados++;
        } else {
            $errores++;
            if ($primerError === null) $primerError = (string) ($resultado['error'] ?? 'Error SMTP no especificado.');
        }
    }

    usr_auditar_accion_alertas_email($conexion, 'PRUEBA_EMAIL_STOCK', [
        'destinatarios' => count($destinatarios),
        'enviados' => $enviados,
        'errores' => $errores,
    ]);

    $mensaje = $errores === 0
        ? 'Correo de prueba enviado correctamente a ' . $enviados . ' destinatario(s).'
        : 'La prueba terminó con ' . $enviados . ' envío(s) correcto(s) y ' . $errores . ' error(es).';

    si_responder_json(true, $mensaje, [
        'enviados' => $enviados,
        'errores' => $errores,
        'error_muestra' => $primerError === null ? null : mb_substr($primerError, 0, 300),
    ]);
}

function usr_enviar_alertas_ahora(PDO $conexion): void
{
    usr_requerir_administrador_alertas();
    $destinatarios = si_stock_email_destinatarios($conexion);
    if (!$destinatarios) {
        si_responder_json(false, 'Primero configura y guarda al menos un destinatario con correo válido.', [], 409);
    }

    $resultado = si_stock_email_procesar($conexion, true, true);
    usr_auditar_accion_alertas_email($conexion, 'ALERTAS_EMAIL_STOCK_MANUAL', $resultado);

    $enviados = (int) ($resultado['enviados'] ?? 0);
    $errores = (int) ($resultado['errores'] ?? 0);
    $criticos = (int) ($resultado['criticos'] ?? 0);
    $reorden = (int) ($resultado['reorden'] ?? 0);

    if ($enviados > 0 && $errores === 0) {
        $mensaje = 'Revisión manual completada. Se enviaron ' . $enviados . ' correo(s).';
    } elseif ($enviados > 0) {
        $mensaje = 'Revisión manual completada con ' . $enviados . ' envío(s) y ' . $errores . ' error(es).';
    } elseif ($errores > 0) {
        $mensaje = 'La revisión encontró correos pendientes, pero se produjeron ' . $errores . ' error(es) de envío.';
    } elseif ($criticos === 0 && $reorden === 0) {
        $mensaje = 'Revisión completada: actualmente no hay productos críticos ni en punto de reorden.';
    } else {
        $mensaje = 'Revisión completada. No había correos nuevos por enviar; los avisos ya enviados no se duplicaron.';
    }

    si_responder_json(true, $mensaje, ['resultado' => $resultado]);
}

function usr_auditar_accion_alertas_email(PDO $conexion, string $accion, array $datos): void
{
    try {
        $actorId = (int) ($_SESSION['usuario_id'] ?? 0);
        $stmt = $conexion->prepare(
            "INSERT INTO auditoria
                (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_nuevos, ip, user_agent)
             VALUES
                (:usuario_id, :accion, 'seguridad', 'alertas_stock_email_destinatarios', NULL,
                 :descripcion, :datos, :ip, :ua)"
        );
        $descripcion = $accion === 'PRUEBA_EMAIL_STOCK'
            ? 'El Administrador ejecutó una prueba manual de las alertas por correo.'
            : 'El Administrador ejecutó manualmente la revisión y envío de alertas de inventario.';
        $stmt->execute([
            ':usuario_id' => $actorId > 0 ? $actorId : null,
            ':accion' => $accion,
            ':descripcion' => $descripcion,
            ':datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip' => si_ip_cliente(),
            ':ua' => si_user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('[SISTEMA INTEGRAL][AUDITORIA EMAIL STOCK] ' . $e->getMessage());
    }
}

function usr_requerir_administrador_alertas(): void
{
    $roles = $_SESSION['roles'] ?? [];
    if (!is_array($roles) || !in_array('ADMINISTRADOR', $roles, true)) {
        si_responder_json(false, 'Solo un Administrador puede configurar los destinatarios de las alertas por correo.', [], 403);
    }
}

