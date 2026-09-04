<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/stock_operativo.php';

si_requerir_permiso('almacenes.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) ($metodo === 'GET' ? ($_GET['accion'] ?? 'LISTAR_ALMACENES') : ($_POST['accion'] ?? ''))));

try {
    si_stock_preparar_operacion($conexion);

    if ($metodo === 'GET') {
        si_requerir_metodo('GET');
        switch ($accion) {
            case 'LISTAR_ALMACENES':
                alm_listar($conexion);
                break;
            case 'DETALLE_ALMACEN':
                alm_detalle($conexion);
                break;
            case 'INVENTARIO_ALMACEN':
                alm_inventario($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();
    si_requerir_permiso('almacenes.administrar', true);

    switch ($accion) {
        case 'GUARDAR_ALMACEN':
            alm_guardar($conexion);
            break;
        case 'CAMBIAR_ESTADO_ALMACEN':
            alm_cambiar_estado($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    $referencia = 'ALM-' . date('Ymd-His');
    error_log('[' . $referencia . '][ALMACENES][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());

    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'Ya existe un almacén con ese código.', ['referencia' => $referencia], 409);
    }
    si_responder_json(false, 'No fue posible procesar la operación.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    $referencia = 'ALM-' . date('Ymd-His');
    error_log('[' . $referencia . '][ALMACENES] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar almacenes.', ['referencia' => $referencia], 500);
}

function alm_listar(PDO $conexion): void
{
    $pagina = alm_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = alm_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = alm_texto($_GET['buscar'] ?? '', 160);
    $estado = strtoupper(alm_texto($_GET['estado'] ?? 'TODOS', 20));
    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS'], true)) {
        $estado = 'TODOS';
    }

    $where = ['1=1'];
    $params = [];
    if ($buscar !== '') {
        $where[] = '(a.codigo LIKE :codigo OR a.nombre LIKE :nombre OR COALESCE(a.ubicacion, \'\') LIKE :ubicacion)';
        $patron = '%' . $buscar . '%';
        $params[':codigo'] = $patron;
        $params[':nombre'] = $patron;
        $params[':ubicacion'] = $patron;
    }
    if ($estado === 'ACTIVOS') {
        $where[] = 'a.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'a.activo = 0';
    }
    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM almacenes a WHERE {$whereSql}");
    alm_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                a.id, a.codigo, a.nombre, a.ubicacion, a.activo, a.created_at, a.updated_at,
                COUNT(ea.id) AS registros_existencia,
                COALESCE(SUM(CASE WHEN ea.existencia_fisica > 0 THEN 1 ELSE 0 END), 0) AS productos_con_existencia,
                COALESCE(SUM(CASE WHEN ea.cantidad_reservada > 0 THEN 1 ELSE 0 END), 0) AS productos_reservados,
                COALESCE(SUM(CASE WHEN (ea.existencia_fisica - ea.cantidad_reservada) <= 0 AND ea.existencia_fisica > 0 THEN 1 ELSE 0 END), 0) AS sin_disponible,
                COALESCE(SUM(CASE WHEN ea.stock_minimo > 0 AND (ea.existencia_fisica - ea.cantidad_reservada) > 0 AND (ea.existencia_fisica - ea.cantidad_reservada) <= ea.stock_minimo THEN 1 ELSE 0 END), 0) AS criticos,
                COALESCE(SUM(CASE WHEN ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND (ea.existencia_fisica - ea.cantidad_reservada) > ea.stock_minimo AND (ea.existencia_fisica - ea.cantidad_reservada) <= ea.punto_reorden THEN 1 ELSE 0 END), 0) AS reorden,
                (SELECT MAX(mi.fecha_movimiento)
                   FROM movimientos_inventario_detalle mid
                   INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
                  WHERE mid.almacen_id = a.id) AS ultima_actividad
            FROM almacenes a
            LEFT JOIN existencias_almacen ea ON ea.almacen_id = a.id
            WHERE {$whereSql}
            GROUP BY a.id, a.codigo, a.nombre, a.ubicacion, a.activo, a.created_at, a.updated_at
            ORDER BY a.activo DESC, a.nombre ASC, a.id ASC
            LIMIT :limite OFFSET :offset";
    $stmt = $conexion->prepare($sql);
    alm_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        alm_normalizar_resumen($f);
    }
    unset($f);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(activo = 1),0) AS activos,
            COALESCE(SUM(activo = 0),0) AS inactivos
         FROM almacenes"
    )->fetch() ?: ['total' => 0, 'activos' => 0, 'inactivos' => 0];

    $conStock = (int) $conexion->query(
        "SELECT COUNT(DISTINCT almacen_id)
         FROM existencias_almacen
         WHERE existencia_fisica > 0 OR cantidad_reservada > 0"
    )->fetchColumn();

    si_responder_json(true, 'Almacenes cargados.', [
        'almacenes' => $filas,
        'resumen' => [
            'total' => (int) $resumen['total'],
            'activos' => (int) $resumen['activos'],
            'inactivos' => (int) $resumen['inactivos'],
            'con_stock' => $conStock,
        ],
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
        'puede_administrar' => si_tiene_permiso('almacenes.administrar'),
    ]);
}

function alm_detalle(PDO $conexion): void
{
    $id = alm_entero($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'El almacén seleccionado no es válido.', [], 422);
    }
    $almacen = alm_cargar($conexion, $id, false);
    if (!$almacen) {
        si_responder_json(false, 'El almacén ya no existe.', [], 404);
    }

    $metricas = alm_metricas($conexion, $id);
    $historial = alm_tiene_historial($conexion, $id);
    $bloqueos = alm_bloqueos_desactivacion($conexion, $id);

    $almacen['id'] = (int) $almacen['id'];
    $almacen['activo'] = (int) $almacen['activo'];
    $almacen['codigo_bloqueado'] = $historial;
    $almacen['puede_desactivar'] = (int) $almacen['activo'] === 1 && $bloqueos === [];
    $almacen['bloqueos_desactivacion'] = array_values($bloqueos);

    si_responder_json(true, 'Detalle del almacén cargado.', [
        'almacen' => $almacen,
        'metricas' => $metricas,
        'puede_administrar' => si_tiene_permiso('almacenes.administrar'),
    ]);
}

function alm_inventario(PDO $conexion): void
{
    $id = alm_entero($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $pagina = alm_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = alm_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = alm_texto($_GET['buscar'] ?? '', 140);
    $estado = strtoupper(alm_texto($_GET['estado_stock'] ?? 'TODOS', 30));
    $permitidos = ['TODOS', 'CON_EXISTENCIA', 'RESERVADO', 'SIN_DISPONIBLE', 'CRITICO', 'REORDEN'];
    if (!in_array($estado, $permitidos, true)) {
        $estado = 'TODOS';
    }
    if ($id <= 0 || !alm_cargar($conexion, $id, false)) {
        si_responder_json(false, 'El almacén seleccionado no es válido.', [], 422);
    }

    $where = ['p.controla_inventario = 1'];
    $params = [':almacen_id' => $id];
    if ($buscar !== '') {
        $where[] = '(p.sku LIKE :sku OR p.nombre LIKE :nombre OR COALESCE(p.codigo_barras, \'\') LIKE :barra)';
        $patron = '%' . $buscar . '%';
        $params[':sku'] = $patron;
        $params[':nombre'] = $patron;
        $params[':barra'] = $patron;
    }

    $fisica = 'COALESCE(ea.existencia_fisica,0)';
    $reservada = 'COALESCE(ea.cantidad_reservada,0)';
    $disponible = "({$fisica} - {$reservada})";
    $minimo = 'COALESCE(ea.stock_minimo,0)';
    if ($estado === 'CON_EXISTENCIA') {
        $where[] = "{$fisica} > 0";
    } elseif ($estado === 'RESERVADO') {
        $where[] = "{$reservada} > 0";
    } elseif ($estado === 'SIN_DISPONIBLE') {
        $where[] = "{$fisica} > 0 AND {$disponible} <= 0";
    } elseif ($estado === 'CRITICO') {
        $where[] = "{$disponible} > 0 AND {$minimo} > 0 AND {$disponible} <= {$minimo}";
    } elseif ($estado === 'REORDEN') {
        $where[] = "ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND {$disponible} > {$minimo} AND {$disponible} <= ea.punto_reorden";
    }
    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
           FROM productos p
           LEFT JOIN existencias_almacen ea ON ea.producto_id = p.id AND ea.almacen_id = :almacen_id
          WHERE {$whereSql}"
    );
    alm_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $estadoSql = "CASE
        WHEN {$fisica} <= 0 THEN 'SIN_STOCK'
        WHEN {$disponible} <= 0 THEN 'SIN_DISPONIBLE'
        WHEN {$minimo} > 0 AND {$disponible} <= {$minimo} THEN 'CRITICO'
        WHEN ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND {$disponible} <= ea.punto_reorden THEN 'REORDEN'
        ELSE 'NORMAL' END";

    $stmt = $conexion->prepare(
        "SELECT p.id AS producto_id, p.sku, p.nombre AS producto, p.tipo, p.activo AS producto_activo,
                um.nombre AS unidad, um.simbolo AS unidad_simbolo,
                {$fisica} AS existencia_fisica, {$reservada} AS cantidad_reservada, {$disponible} AS cantidad_disponible,
                {$minimo} AS stock_minimo, ea.punto_reorden, ea.costo_promedio_base,
                {$estadoSql} AS estado_stock
           FROM productos p
           INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
           LEFT JOIN existencias_almacen ea ON ea.producto_id = p.id AND ea.almacen_id = :almacen_id
          WHERE {$whereSql}
          ORDER BY
            CASE {$estadoSql} WHEN 'SIN_STOCK' THEN 0 WHEN 'SIN_DISPONIBLE' THEN 1 WHEN 'CRITICO' THEN 2 WHEN 'REORDEN' THEN 3 ELSE 4 END,
            p.nombre ASC, p.id ASC
          LIMIT :limite OFFSET :offset"
    );
    alm_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        foreach (['producto_id', 'producto_activo'] as $campo) {
            $f[$campo] = (int) $f[$campo];
        }
        foreach (['existencia_fisica', 'cantidad_reservada', 'cantidad_disponible', 'stock_minimo'] as $campo) {
            $f[$campo] = (float) $f[$campo];
        }
        $f['punto_reorden'] = $f['punto_reorden'] !== null ? (float) $f['punto_reorden'] : null;
        $f['costo_promedio_base'] = $f['costo_promedio_base'] !== null ? (float) $f['costo_promedio_base'] : null;
    }
    unset($f);

    si_responder_json(true, 'Inventario del almacén cargado.', [
        'registros' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function alm_guardar(PDO $conexion): void
{
    $id = alm_entero($_POST['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $codigo = alm_codigo($_POST['codigo'] ?? '');
    $nombre = alm_texto($_POST['nombre'] ?? '', 120);
    $ubicacion = alm_texto($_POST['ubicacion'] ?? '', 255);

    if (alm_strlen($nombre) < 2) {
        si_responder_json(false, 'El nombre del almacén debe tener al menos 2 caracteres.', [], 422);
    }

    $conexion->beginTransaction();
    $anterior = null;
    if ($id > 0) {
        $anterior = alm_cargar($conexion, $id, true);
        if (!$anterior) {
            alm_abort($conexion, 'El almacén que intentas editar ya no existe.', 404);
        }
        if (alm_tiene_historial($conexion, $id) && $codigo !== (string) $anterior['codigo']) {
            alm_abort($conexion, 'El código ya no puede cambiarse porque este almacén tiene historial operativo. Puedes modificar su nombre y ubicación.', 409);
        }

        $stmt = $conexion->prepare(
            "UPDATE almacenes
             SET codigo = :codigo, nombre = :nombre, ubicacion = :ubicacion
             WHERE id = :id"
        );
        $stmt->execute([
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':ubicacion' => $ubicacion !== '' ? $ubicacion : null,
            ':id' => $id,
        ]);
        $accion = 'ALMACEN_MODIFICADO';
        $mensaje = 'Almacén actualizado correctamente.';
    } else {
        $stmt = $conexion->prepare(
            "INSERT INTO almacenes (codigo, nombre, ubicacion, activo)
             VALUES (:codigo, :nombre, :ubicacion, 1)"
        );
        $stmt->execute([
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':ubicacion' => $ubicacion !== '' ? $ubicacion : null,
        ]);
        $id = (int) $conexion->lastInsertId();
        $accion = 'ALMACEN_CREADO';
        $mensaje = 'Almacén creado correctamente. Ya está disponible para inventario, compras, ventas y transferencias.';
    }

    $nuevo = alm_cargar($conexion, $id, false);
    alm_auditar($conexion, $accion, $id, $mensaje, $anterior ? alm_auditoria_resumen($anterior) : null, $nuevo ? alm_auditoria_resumen($nuevo) : null);
    $conexion->commit();

    si_responder_json(true, $mensaje, ['almacen_id' => $id]);
}

function alm_cambiar_estado(PDO $conexion): void
{
    $id = alm_entero($_POST['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $activar = alm_entero($_POST['activo'] ?? 0, 0, 1, 0) === 1;
    if ($id <= 0) {
        si_responder_json(false, 'El almacén seleccionado no es válido.', [], 422);
    }

    $conexion->beginTransaction();
    $almacen = alm_cargar($conexion, $id, true);
    if (!$almacen) {
        alm_abort($conexion, 'El almacén ya no existe.', 404);
    }
    $estadoActual = (int) $almacen['activo'] === 1;
    if ($estadoActual === $activar) {
        $conexion->commit();
        si_responder_json(true, $activar ? 'El almacén ya está activo.' : 'El almacén ya está inactivo.', ['almacen_id' => $id]);
    }

    if (!$activar) {
        // Bloquea el conjunto de almacenes activos para evitar desactivar dos simultáneamente.
        $activos = $conexion->query("SELECT id FROM almacenes WHERE activo = 1 ORDER BY id FOR UPDATE")->fetchAll();
        if (count($activos) <= 1) {
            alm_abort($conexion, 'No puedes desactivar el único almacén activo. El sistema necesita al menos uno.', 409);
        }

        // Bloquea existencias actuales antes de comprobar que el almacén esté vacío.
        $stmtExistencias = $conexion->prepare(
            "SELECT id FROM existencias_almacen
             WHERE almacen_id = :almacen_id
             ORDER BY id FOR UPDATE"
        );
        $stmtExistencias->execute([':almacen_id' => $id]);
        $stmtExistencias->fetchAll();

        $bloqueos = alm_bloqueos_desactivacion($conexion, $id);
        if ($bloqueos !== []) {
            alm_abort($conexion, 'No es posible desactivar este almacén: ' . implode(' ', $bloqueos), 409);
        }
    }

    $stmt = $conexion->prepare("UPDATE almacenes SET activo = :activo WHERE id = :id");
    $stmt->execute([':activo' => $activar ? 1 : 0, ':id' => $id]);
    $nuevo = alm_cargar($conexion, $id, false);
    $mensaje = $activar
        ? 'Almacén activado correctamente. Volverá a aparecer en las operaciones nuevas.'
        : 'Almacén desactivado correctamente. Su historial permanece disponible y no aparecerá en operaciones nuevas.';
    alm_auditar(
        $conexion,
        $activar ? 'ALMACEN_ACTIVADO' : 'ALMACEN_DESACTIVADO',
        $id,
        $mensaje,
        alm_auditoria_resumen($almacen),
        $nuevo ? alm_auditoria_resumen($nuevo) : null
    );
    $conexion->commit();
    si_responder_json(true, $mensaje, ['almacen_id' => $id, 'activo' => $activar ? 1 : 0]);
}

function alm_metricas(PDO $conexion, int $id): array
{
    $stmt = $conexion->prepare(
        "SELECT
            COUNT(*) AS registros_existencia,
            COALESCE(SUM(existencia_fisica > 0),0) AS productos_con_existencia,
            COALESCE(SUM(cantidad_reservada > 0),0) AS productos_reservados,
            COALESCE(SUM((existencia_fisica - cantidad_reservada) <= 0 AND existencia_fisica > 0),0) AS sin_disponible,
            COALESCE(SUM(stock_minimo > 0 AND (existencia_fisica - cantidad_reservada) > 0 AND (existencia_fisica - cantidad_reservada) <= stock_minimo),0) AS criticos,
            COALESCE(SUM(punto_reorden IS NOT NULL AND punto_reorden > 0 AND (existencia_fisica - cantidad_reservada) > stock_minimo AND (existencia_fisica - cantidad_reservada) <= punto_reorden),0) AS reorden
         FROM existencias_almacen
         WHERE almacen_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $m = $stmt->fetch() ?: [];

    $stmtMov = $conexion->prepare(
        "SELECT COUNT(DISTINCT mid.movimiento_id) AS movimientos, MAX(mi.fecha_movimiento) AS ultima_actividad
         FROM movimientos_inventario_detalle mid
         INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
         WHERE mid.almacen_id = :id"
    );
    $stmtMov->execute([':id' => $id]);
    $mov = $stmtMov->fetch() ?: [];

    return [
        'registros_existencia' => (int) ($m['registros_existencia'] ?? 0),
        'productos_con_existencia' => (int) ($m['productos_con_existencia'] ?? 0),
        'productos_reservados' => (int) ($m['productos_reservados'] ?? 0),
        'sin_disponible' => (int) ($m['sin_disponible'] ?? 0),
        'criticos' => (int) ($m['criticos'] ?? 0),
        'reorden' => (int) ($m['reorden'] ?? 0),
        'movimientos' => (int) ($mov['movimientos'] ?? 0),
        'ultima_actividad' => $mov['ultima_actividad'] ?? null,
    ];
}

function alm_bloqueos_desactivacion(PDO $conexion, int $id): array
{
    $bloqueos = [];
    $stmt = $conexion->prepare(
        "SELECT
            COALESCE(SUM(existencia_fisica),0) AS fisica,
            COALESCE(SUM(cantidad_reservada),0) AS reservada,
            COALESCE(SUM(existencia_fisica > 0),0) AS productos_fisicos,
            COALESCE(SUM(cantidad_reservada > 0),0) AS productos_reservados
         FROM existencias_almacen
         WHERE almacen_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $stock = $stmt->fetch() ?: [];
    if ((int) ($stock['productos_fisicos'] ?? 0) > 0 || (int) ($stock['productos_reservados'] ?? 0) > 0) {
        $bloqueos[] = 'Primero deja su inventario físico y reservado en cero mediante los flujos normales (por ejemplo, transferencias).';
    }

    $stmtActivos = $conexion->prepare('SELECT COUNT(*) FROM almacenes WHERE activo = 1 AND id <> :id');
    $stmtActivos->execute([':id' => $id]);
    if ((int) $stmtActivos->fetchColumn() === 0) {
        $bloqueos[] = 'Es el único almacén activo; el sistema necesita al menos uno disponible.';
    }

    $pendientes = [
        [
            "SELECT COUNT(*) FROM producciones p
             WHERE p.estado = 'BORRADOR' AND (
                EXISTS (SELECT 1 FROM producciones_insumos pi WHERE pi.produccion_id = p.id AND pi.almacen_id = :id_insumo)
                OR EXISTS (SELECT 1 FROM producciones_resultados pr WHERE pr.produccion_id = p.id AND pr.almacen_id = :id_resultado)
             )",
            'Tiene producciones en borrador vinculadas.',
            [':id_insumo' => $id, ':id_resultado' => $id],
        ],
        [
            "SELECT COUNT(*) FROM recepciones_compra rc
             WHERE rc.estado = 'BORRADOR'
               AND EXISTS (SELECT 1 FROM recepciones_compra_detalle rd WHERE rd.recepcion_id = rc.id AND rd.almacen_id = :id)",
            'Tiene recepciones de compra en borrador vinculadas.',
            [':id' => $id],
        ],
        [
            "SELECT COUNT(*) FROM ventas v
             WHERE v.estado = 'BORRADOR'
               AND EXISTS (SELECT 1 FROM ventas_detalle vd WHERE vd.venta_id = v.id AND vd.almacen_id = :id)",
            'Tiene ventas en borrador vinculadas.',
            [':id' => $id],
        ],
        [
            "SELECT COUNT(*) FROM apartados ap
             WHERE ap.estado = 'ACTIVO'
               AND EXISTS (SELECT 1 FROM apartados_detalle ad WHERE ad.apartado_id = ap.id AND ad.almacen_id = :id)",
            'Tiene apartados activos vinculados.',
            [':id' => $id],
        ],
    ];
    foreach ($pendientes as [$sql, $mensaje, $parametros]) {
        $q = $conexion->prepare($sql);
        $q->execute($parametros);
        if ((int) $q->fetchColumn() > 0) {
            $bloqueos[] = $mensaje;
        }
    }
    return array_values(array_unique($bloqueos));
}

function alm_tiene_historial(PDO $conexion, int $id): bool
{
    $consultas = [
        'SELECT 1 FROM movimientos_inventario_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM ventas_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM apartados_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM recepciones_compra_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM producciones_insumos WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM producciones_resultados WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM devoluciones_venta_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM devoluciones_compra_detalle WHERE almacen_id = :id LIMIT 1',
        'SELECT 1 FROM ajustes_inventario_detalle WHERE almacen_id = :id LIMIT 1',
    ];
    foreach ($consultas as $sql) {
        $stmt = $conexion->prepare($sql);
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

function alm_cargar(PDO $conexion, int $id, bool $bloquear): ?array
{
    $sql = 'SELECT id, codigo, nombre, ubicacion, activo, created_at, updated_at FROM almacenes WHERE id = :id LIMIT 1';
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function alm_normalizar_resumen(array &$f): void
{
    foreach (['id', 'activo', 'registros_existencia', 'productos_con_existencia', 'productos_reservados', 'sin_disponible', 'criticos', 'reorden'] as $campo) {
        $f[$campo] = (int) ($f[$campo] ?? 0);
    }
}

function alm_codigo(mixed $valor): string
{
    $codigo = strtoupper(trim((string) $valor));
    $codigo = preg_replace('/\s+/', '-', $codigo) ?? $codigo;
    if (strlen($codigo) < 2 || strlen($codigo) > 40) {
        si_responder_json(false, 'El código debe tener entre 2 y 40 caracteres.', [], 422);
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $codigo)) {
        si_responder_json(false, 'El código solo puede contener letras A-Z, números, punto, guion y guion bajo.', [], 422);
    }
    return $codigo;
}

function alm_texto(mixed $valor, int $max): string
{
    $texto = trim((string) $valor);
    if (alm_strlen($texto) > $max) {
        $texto = alm_substr($texto, 0, $max);
    }
    return $texto;
}

function alm_entero(mixed $valor, int $min, int $max, int $default): int
{
    if (filter_var($valor, FILTER_VALIDATE_INT) === false) {
        return $default;
    }
    $n = (int) $valor;
    return ($n < $min || $n > $max) ? $default : $n;
}

function alm_strlen(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}

function alm_substr(string $texto, int $inicio, int $longitud): string
{
    return function_exists('mb_substr') ? mb_substr($texto, $inicio, $longitud, 'UTF-8') : substr($texto, $inicio, $longitud);
}

function alm_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function alm_abort(PDO $conexion, string $mensaje, int $status): never
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, [], $status);
}

function alm_auditoria_resumen(array $fila): array
{
    return [
        'codigo' => $fila['codigo'] ?? null,
        'nombre' => $fila['nombre'] ?? null,
        'ubicacion' => $fila['ubicacion'] ?? null,
        'activo' => isset($fila['activo']) ? (int) $fila['activo'] : null,
    ];
}

function alm_auditar(PDO $conexion, string $accion, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, :accion, 'almacenes', 'almacenes', :entidad_id, :descripcion, :anteriores, :nuevos, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario_id' => (int) ($_SESSION['usuario_id'] ?? 0),
        ':accion' => $accion,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anteriores' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevos' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}
