<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('produccion.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                prod_catalogos($conexion);
                break;
            case 'LISTAR':
                prod_listar($conexion);
                break;
            case 'DETALLE':
                prod_detalle($conexion);
                break;
            case 'BUSCAR_PRODUCTOS':
                prod_buscar_productos($conexion);
                break;
            case 'OPCIONES_UNIDAD':
                prod_opciones_unidad($conexion);
                break;
            case 'RECETAS_LISTAR':
                prod_recetas_listar($conexion);
                break;
            case 'RECETA_DETALLE':
                prod_receta_detalle($conexion);
                break;
            case 'RECETAS_SELECTOR':
                prod_recetas_selector($conexion);
                break;
            case 'RECETA_BUSCAR_PRODUCTOS':
                prod_receta_buscar_productos($conexion);
                break;
            case 'RECETA_ESCALAR':
                prod_receta_escalar($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();
    si_requerir_permiso('produccion.registrar', true);

    switch ($accion) {
        case 'GUARDAR_BORRADOR':
            prod_guardar($conexion, false);
            break;
        case 'GUARDAR_CONFIRMAR':
            prod_guardar($conexion, true);
            break;
        case 'CONFIRMAR':
            prod_confirmar($conexion);
            break;
        case 'CANCELAR':
            prod_cancelar($conexion);
            break;
        case 'RECETA_GUARDAR':
            prod_receta_guardar($conexion);
            break;
        case 'RECETA_ELIMINAR':
            prod_receta_eliminar($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'PROD-' . date('Ymd-His');
    error_log('[' . $referencia . '][PRODUCCION][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());

    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'No fue posible guardar porque existe un dato duplicado o una relación inválida.', ['referencia' => $referencia], 409);
    }

    si_responder_json(false, 'No fue posible procesar la operación de producción.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'PROD-' . date('Ymd-His');
    error_log('[' . $referencia . '][PRODUCCION] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar producción.', ['referencia' => $referencia], 500);
}

/* =========================================================================
   CATÁLOGOS / CONSULTAS
   ========================================================================= */

function prod_catalogos(PDO $conexion): void
{
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    foreach ($almacenes as &$almacen) {
        $almacen['id'] = (int) $almacen['id'];
    }
    unset($almacen);

    si_responder_json(true, 'Catálogos cargados.', [
        'almacenes' => $almacenes,
        'fecha_hoy' => date('Y-m-d'),
        'fecha_hora' => date('Y-m-d\TH:i'),
        'puede_registrar' => si_tiene_permiso('produccion.registrar'),
    ]);
}

function prod_listar(PDO $conexion): void
{
    $pagina = prod_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prod_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = prod_texto($_GET['buscar'] ?? '', 120);
    $estado = strtoupper(prod_texto($_GET['estado'] ?? 'TODOS', 20));
    $desde = prod_fecha($_GET['desde'] ?? '');
    $hasta = prod_fecha($_GET['hasta'] ?? '');

    if (!in_array($estado, ['TODOS', 'BORRADOR', 'CONFIRMADA', 'CANCELADA'], true)) {
        $estado = 'TODOS';
    }
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la fecha final.', [], 422);
    }

    $where = ['1 = 1'];
    $params = [];

    if ($buscar !== '') {
        $where[] = "(p.folio LIKE :buscar_folio
                    OR EXISTS (
                        SELECT 1
                        FROM producciones_resultados prx
                        INNER JOIN productos px ON px.id = prx.producto_id
                        WHERE prx.produccion_id = p.id
                          AND (px.nombre LIKE :buscar_producto OR px.sku LIKE :buscar_sku)
                    ))";
        $like = '%' . $buscar . '%';
        $params[':buscar_folio'] = $like;
        $params[':buscar_producto'] = $like;
        $params[':buscar_sku'] = $like;
    }
    if ($estado !== 'TODOS') {
        $where[] = 'p.estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($desde !== null) {
        $where[] = 'p.fecha_produccion >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== null) {
        $where[] = 'p.fecha_produccion < :hasta';
        $params[':hasta'] = date('Y-m-d H:i:s', strtotime($hasta . ' +1 day'));
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM producciones p WHERE {$whereSql}");
    prod_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                p.id,
                p.folio,
                p.fecha_produccion,
                p.estado,
                p.observaciones,
                p.created_at,
                CONCAT_WS(' ', uc.nombres, uc.apellido_paterno, uc.apellido_materno) AS creado_por,
                CONCAT_WS(' ', uconf.nombres, uconf.apellido_paterno, uconf.apellido_materno) AS confirmado_por,
                COALESCE(ins.cantidad_insumos, 0) AS cantidad_insumos,
                res.producto_id AS resultado_producto_id,
                res.producto AS resultado_producto,
                res.sku AS resultado_sku,
                res.cantidad AS resultado_cantidad,
                res.cantidad_base AS resultado_cantidad_base,
                res.unidad AS resultado_unidad,
                res.almacen AS resultado_almacen
            FROM producciones p
            INNER JOIN usuarios uc ON uc.id = p.created_by
            LEFT JOIN usuarios uconf ON uconf.id = p.confirmada_by
            LEFT JOIN (
                SELECT produccion_id, COUNT(*) AS cantidad_insumos
                FROM producciones_insumos
                GROUP BY produccion_id
            ) ins ON ins.produccion_id = p.id
            LEFT JOIN (
                SELECT
                    pr.produccion_id,
                    pr.producto_id,
                    prod.nombre AS producto,
                    prod.sku,
                    pr.cantidad,
                    pr.cantidad_base,
                    CONCAT(um.nombre, ' (', um.simbolo, ')') AS unidad,
                    a.nombre AS almacen
                FROM producciones_resultados pr
                INNER JOIN productos prod ON prod.id = pr.producto_id
                INNER JOIN unidades_medida um ON um.id = pr.unidad_id
                INNER JOIN almacenes a ON a.id = pr.almacen_id
                WHERE pr.renglon = 1
            ) res ON res.produccion_id = p.id
            WHERE {$whereSql}
            ORDER BY p.fecha_produccion DESC, p.id DESC
            LIMIT :limite OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    prod_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $registros = $stmt->fetchAll();

    foreach ($registros as &$r) {
        $r['id'] = (int) $r['id'];
        $r['cantidad_insumos'] = (int) $r['cantidad_insumos'];
        $r['resultado_producto_id'] = $r['resultado_producto_id'] !== null ? (int) $r['resultado_producto_id'] : null;
        $r['resultado_cantidad'] = $r['resultado_cantidad'] !== null ? (float) $r['resultado_cantidad'] : null;
        $r['resultado_cantidad_base'] = $r['resultado_cantidad_base'] !== null ? (float) $r['resultado_cantidad_base'] : null;
    }
    unset($r);

    $kpis = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'BORRADOR') AS borradores,
            SUM(estado = 'CONFIRMADA') AS confirmadas,
            SUM(estado = 'CANCELADA') AS canceladas,
            SUM(estado = 'CONFIRMADA' AND fecha_produccion >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')) AS confirmadas_mes
         FROM producciones"
    )->fetch() ?: [];

    si_responder_json(true, 'Producciones cargadas.', [
        'registros' => $registros,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'paginas' => $paginas,
        ],
        'resumen' => [
            'total' => (int) ($kpis['total'] ?? 0),
            'borradores' => (int) ($kpis['borradores'] ?? 0),
            'confirmadas' => (int) ($kpis['confirmadas'] ?? 0),
            'canceladas' => (int) ($kpis['canceladas'] ?? 0),
            'confirmadas_mes' => (int) ($kpis['confirmadas_mes'] ?? 0),
        ],
    ]);
}

function prod_detalle(PDO $conexion): void
{
    $id = prod_entero($_GET['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'La producción solicitada no es válida.', [], 422);
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.*,
            CONCAT_WS(' ', uc.nombres, uc.apellido_paterno, uc.apellido_materno) AS creado_por,
            CONCAT_WS(' ', uco.nombres, uco.apellido_paterno, uco.apellido_materno) AS confirmado_por,
            CONCAT_WS(' ', uca.nombres, uca.apellido_paterno, uca.apellido_materno) AS cancelado_por
         FROM producciones p
         INNER JOIN usuarios uc ON uc.id = p.created_by
         LEFT JOIN usuarios uco ON uco.id = p.confirmada_by
         LEFT JOIN usuarios uca ON uca.id = p.cancelada_by
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $produccion = $stmt->fetch();
    if (!$produccion) {
        si_responder_json(false, 'La producción ya no existe.', [], 404);
    }

    $stmtInsumos = $conexion->prepare(
        "SELECT
            pi.id,
            pi.renglon,
            pi.almacen_id,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen,
            pi.producto_id,
            p.sku,
            p.nombre AS producto,
            p.permite_fraccion,
            pi.unidad_id,
            um.nombre AS unidad,
            um.simbolo,
            ub.simbolo AS simbolo_base,
            pi.cantidad,
            pi.factor_a_unidad_base,
            pi.cantidad_base,
            pi.observaciones,
            COALESCE(e.existencia_fisica, 0) AS existencia_actual,
            COALESCE(e.cantidad_reservada, 0) AS reservado_actual,
            COALESCE(e.cantidad_disponible, 0) AS disponible_actual
         FROM producciones_insumos pi
         INNER JOIN productos p ON p.id = pi.producto_id
         INNER JOIN almacenes a ON a.id = pi.almacen_id
         INNER JOIN unidades_medida um ON um.id = pi.unidad_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e
           ON e.almacen_id = pi.almacen_id
          AND e.producto_id = pi.producto_id
         WHERE pi.produccion_id = :produccion_id
         ORDER BY pi.renglon ASC"
    );
    $stmtInsumos->execute([':produccion_id' => $id]);
    $insumos = $stmtInsumos->fetchAll();

    $stmtResultados = $conexion->prepare(
        "SELECT
            pr.id,
            pr.renglon,
            pr.almacen_id,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen,
            pr.producto_id,
            p.sku,
            p.nombre AS producto,
            p.permite_fraccion,
            pr.unidad_id,
            um.nombre AS unidad,
            um.simbolo,
            ub.simbolo AS simbolo_base,
            pr.cantidad,
            pr.factor_a_unidad_base,
            pr.cantidad_base,
            pr.observaciones,
            COALESCE(e.existencia_fisica, 0) AS existencia_actual,
            COALESCE(e.cantidad_reservada, 0) AS reservado_actual,
            COALESCE(e.cantidad_disponible, 0) AS disponible_actual
         FROM producciones_resultados pr
         INNER JOIN productos p ON p.id = pr.producto_id
         INNER JOIN almacenes a ON a.id = pr.almacen_id
         INNER JOIN unidades_medida um ON um.id = pr.unidad_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e
           ON e.almacen_id = pr.almacen_id
          AND e.producto_id = pr.producto_id
         WHERE pr.produccion_id = :produccion_id
         ORDER BY pr.renglon ASC"
    );
    $stmtResultados->execute([':produccion_id' => $id]);
    $resultados = $stmtResultados->fetchAll();

    $stmtMov = $conexion->prepare(
        "SELECT
            mi.id,
            mi.folio,
            t.codigo AS tipo_codigo,
            t.nombre AS tipo,
            mi.estado,
            mi.fecha_movimiento,
            mi.movimiento_revertido_id
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
         WHERE mi.origen_tipo = 'PRODUCCION'
           AND mi.origen_id = :origen_id
         ORDER BY mi.id ASC"
    );
    $stmtMov->execute([':origen_id' => $id]);
    $movimientos = $stmtMov->fetchAll();

    prod_normalizar_detalles($insumos);
    prod_normalizar_detalles($resultados);
    foreach ($movimientos as &$m) {
        $m['id'] = (int) $m['id'];
        $m['movimiento_revertido_id'] = $m['movimiento_revertido_id'] !== null ? (int) $m['movimiento_revertido_id'] : null;
    }
    unset($m);

    $produccion['id'] = (int) $produccion['id'];
    $produccion['editable'] = $produccion['estado'] === 'BORRADOR' && si_tiene_permiso('produccion.registrar');
    $produccion['confirmable'] = $produccion['estado'] === 'BORRADOR' && si_tiene_permiso('produccion.registrar');
    $produccion['cancelable'] = in_array($produccion['estado'], ['BORRADOR', 'CONFIRMADA'], true) && si_tiene_permiso('produccion.registrar');

    si_responder_json(true, 'Detalle cargado.', [
        'produccion' => $produccion,
        'insumos' => $insumos,
        'resultados' => $resultados,
        'movimientos' => $movimientos,
    ]);
}

function prod_buscar_productos(PDO $conexion): void
{
    $q = prod_texto($_GET['q'] ?? '', 180);
    $tipo = strtoupper(prod_texto($_GET['tipo'] ?? 'INSUMO', 20));
    $almacenId = prod_entero($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);

    if ($almacenId <= 0) {
        si_responder_json(true, 'Selecciona un almacén.', ['productos' => []]);
    }
    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['productos' => []]);
    }
    if (!in_array($tipo, ['INSUMO', 'RESULTADO'], true)) {
        si_responder_json(false, 'El tipo de búsqueda no es válido.', [], 422);
    }

    $tipoProducto = $tipo === 'INSUMO' ? 'MATERIA_PRIMA' : 'PRODUCTO_TERMINADO';
    $like = '%' . $q . '%';

    $sql = "SELECT
                p.id,
                p.sku,
                p.nombre,
                p.tipo,
                p.unidad_base_id,
                p.permite_fraccion,
                um.nombre AS unidad_base,
                um.simbolo AS simbolo_base,
                COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
                COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
                COALESCE(e.cantidad_disponible, 0) AS cantidad_disponible,
                e.costo_promedio_base
            FROM productos p
            INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
            LEFT JOIN existencias_almacen e
              ON e.almacen_id = :almacen_id
             AND e.producto_id = p.id
            WHERE p.activo = 1
              AND p.deleted_at IS NULL
              AND p.controla_inventario = 1
              AND p.tipo = :tipo_producto
              AND (p.sku LIKE :buscar_sku OR p.nombre LIKE :buscar_nombre OR p.codigo_barras LIKE :buscar_codigo)";

    if ($tipo === 'INSUMO') {
        $sql .= " AND COALESCE(e.cantidad_disponible, 0) > 0";
    }

    $sql .= " ORDER BY p.nombre ASC, p.id ASC LIMIT 20";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([
        ':almacen_id' => $almacenId,
        ':tipo_producto' => $tipoProducto,
        ':buscar_sku' => $like,
        ':buscar_nombre' => $like,
        ':buscar_codigo' => $like,
    ]);
    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_base_id'] = (int) $p['unidad_base_id'];
        $p['permite_fraccion'] = (int) $p['permite_fraccion'];
        $p['existencia_fisica'] = (float) $p['existencia_fisica'];
        $p['cantidad_reservada'] = (float) $p['cantidad_reservada'];
        $p['cantidad_disponible'] = (float) $p['cantidad_disponible'];
        $p['costo_promedio_base'] = $p['costo_promedio_base'] !== null ? (float) $p['costo_promedio_base'] : null;
    }
    unset($p);

    si_responder_json(true, 'Productos encontrados.', ['productos' => $productos]);
}

function prod_opciones_unidad(PDO $conexion): void
{
    $productoId = prod_entero($_GET['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($productoId <= 0) {
        si_responder_json(false, 'El producto no es válido.', [], 422);
    }

    $stmtProducto = $conexion->prepare(
        "SELECT p.id, p.unidad_base_id, p.permite_fraccion, um.nombre, um.simbolo
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE p.id = :id
           AND p.activo = 1
           AND p.deleted_at IS NULL
           AND p.controla_inventario = 1
         LIMIT 1"
    );
    $stmtProducto->execute([':id' => $productoId]);
    $producto = $stmtProducto->fetch();
    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $opciones = [[
        'valor' => 'BASE',
        'unidad_id' => (int) $producto['unidad_base_id'],
        'nombre' => 'Unidad base · ' . $producto['nombre'] . ' (' . $producto['simbolo'] . ')',
        'factor' => 1.0,
        'presentacion_id' => null,
        'es_base' => 1,
    ]];

    $stmt = $conexion->prepare(
        "SELECT pp.id, pp.unidad_id, pp.nombre, pp.factor_a_unidad_base, um.simbolo
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida um ON um.id = pp.unidad_id
         WHERE pp.producto_id = :producto_id
           AND pp.activo = 1
           AND pp.factor_a_unidad_base > 0
         ORDER BY pp.factor_a_unidad_base ASC, pp.id ASC"
    );
    $stmt->execute([':producto_id' => $productoId]);

    foreach ($stmt->fetchAll() as $p) {
        $opciones[] = [
            'valor' => 'P:' . (int) $p['id'],
            'unidad_id' => (int) $p['unidad_id'],
            'nombre' => (string) $p['nombre'] . ' (' . (string) $p['simbolo'] . ')',
            'factor' => (float) $p['factor_a_unidad_base'],
            'presentacion_id' => (int) $p['id'],
            'es_base' => 0,
        ];
    }

    si_responder_json(true, 'Unidades cargadas.', [
        'opciones' => $opciones,
        'permite_fraccion' => (int) $producto['permite_fraccion'],
    ]);
}

/* =========================================================================
   GUARDAR / CONFIRMAR
   ========================================================================= */

function prod_guardar(PDO $conexion, bool $confirmar): void
{
    $produccionId = prod_entero($_POST['produccion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $esNuevo = $produccionId <= 0;
    $payload = prod_payload();
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);

    $fecha = prod_fecha_hora($payload['fecha_produccion'] ?? '');
    if ($fecha === null) {
        si_responder_json(false, 'Captura una fecha y hora de producción válidas.', [], 422);
    }
    if (strtotime($fecha) > time() + 300) {
        si_responder_json(false, 'La fecha de producción no puede estar en el futuro.', [], 422);
    }

    $observaciones = prod_texto($payload['observaciones'] ?? '', 4000);
    $almacenId = prod_entero($payload['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($almacenId <= 0 || !prod_almacen_activo($conexion, $almacenId)) {
        si_responder_json(false, 'Selecciona un almacén activo.', [], 422);
    }

    $recetaId = prod_entero($payload['receta_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $recetaVersion = prod_entero($payload['receta_version'] ?? 0, 0, PHP_INT_MAX, 0);
    if ($recetaId > 0) {
        $stmtReceta = $conexion->prepare("SELECT id, version, activo, deleted_at FROM recetas_produccion WHERE id = :id LIMIT 1");
        $stmtReceta->execute([':id' => $recetaId]);
        $recetaActual = $stmtReceta->fetch();
        if (!$recetaActual || (int) $recetaActual['activo'] !== 1 || $recetaActual['deleted_at'] !== null) {
            si_responder_json(false, 'La receta seleccionada ya no está disponible.', [], 409);
        }
        if ($recetaVersion <= 0 || $recetaVersion !== (int) $recetaActual['version']) {
            si_responder_json(false, 'La receta cambió desde que la cargaste. Vuelve a aplicarla antes de guardar la producción.', [], 409);
        }
    } else {
        $recetaId = 0;
        $recetaVersion = 0;
    }

    $resultadoRaw = is_array($payload['resultado'] ?? null) ? $payload['resultado'] : [];
    $insumosRaw = is_array($payload['insumos'] ?? null) ? $payload['insumos'] : [];

    $resultado = prod_validar_renglon($conexion, $resultadoRaw, 'RESULTADO', $almacenId);
    if (!$resultado) {
        si_responder_json(false, 'Captura el producto terminado y la cantidad producida.', [], 422);
    }
    if (!$insumosRaw) {
        si_responder_json(false, 'Agrega al menos una materia prima utilizada.', [], 422);
    }
    if (count($insumosRaw) > 100) {
        si_responder_json(false, 'Una producción no puede contener más de 100 insumos.', [], 422);
    }

    $insumos = [];
    $vistos = [];
    foreach ($insumosRaw as $i => $raw) {
        if (!is_array($raw)) {
            si_responder_json(false, 'Uno de los insumos no tiene un formato válido.', [], 422);
        }
        $insumo = prod_validar_renglon($conexion, $raw, 'INSUMO', $almacenId);
        if (!$insumo) {
            si_responder_json(false, 'Revisa el insumo #' . ($i + 1) . '.', [], 422);
        }
        if (isset($vistos[$insumo['producto_id']])) {
            si_responder_json(false, 'Cada materia prima debe aparecer una sola vez. Ajusta la cantidad del renglón existente.', [], 422);
        }
        if ($insumo['producto_id'] === $resultado['producto_id']) {
            si_responder_json(false, 'El producto terminado no puede utilizarse como insumo de la misma producción.', [], 422);
        }
        $vistos[$insumo['producto_id']] = true;
        $insumos[] = $insumo;
    }

    $conexion->beginTransaction();

    if ($produccionId > 0) {
        $stmt = $conexion->prepare("SELECT id, folio, estado FROM producciones WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $produccionId]);
        $actual = $stmt->fetch();
        if (!$actual) {
            prod_abort($conexion, 'La producción que intentas editar ya no existe.', 404);
        }
        if ($actual['estado'] !== 'BORRADOR') {
            prod_abort($conexion, 'Solo una producción en borrador puede modificarse.', 409);
        }

        $stmtUpdate = $conexion->prepare(
            "UPDATE producciones
             SET fecha_produccion = :fecha_produccion,
                 receta_id = :receta_id,
                 receta_version = :receta_version,
                 observaciones = :observaciones
             WHERE id = :id"
        );
        $stmtUpdate->execute([
            ':fecha_produccion' => $fecha,
            ':receta_id' => $recetaId > 0 ? $recetaId : null,
            ':receta_version' => $recetaVersion > 0 ? $recetaVersion : null,
            ':observaciones' => $observaciones !== '' ? $observaciones : null,
            ':id' => $produccionId,
        ]);

        $conexion->prepare("DELETE FROM producciones_insumos WHERE produccion_id = :produccion_id")
            ->execute([':produccion_id' => $produccionId]);
        $conexion->prepare("DELETE FROM producciones_resultados WHERE produccion_id = :produccion_id")
            ->execute([':produccion_id' => $produccionId]);

        $folio = (string) $actual['folio'];
    } else {
        $folioTemporal = 'TMP-PROD-' . bin2hex(random_bytes(10));
        $stmtInsert = $conexion->prepare(
            "INSERT INTO producciones
                (folio, fecha_produccion, estado, receta_id, receta_version, observaciones, created_by)
             VALUES
                (:folio, :fecha_produccion, 'BORRADOR', :receta_id, :receta_version, :observaciones, :created_by)"
        );
        $stmtInsert->execute([
            ':folio' => $folioTemporal,
            ':fecha_produccion' => $fecha,
            ':receta_id' => $recetaId > 0 ? $recetaId : null,
            ':receta_version' => $recetaVersion > 0 ? $recetaVersion : null,
            ':observaciones' => $observaciones !== '' ? $observaciones : null,
            ':created_by' => $usuarioId,
        ]);
        $produccionId = (int) $conexion->lastInsertId();
        $folio = 'PROD-' . str_pad((string) $produccionId, 7, '0', STR_PAD_LEFT);
        $conexion->prepare("UPDATE producciones SET folio = :folio WHERE id = :id")
            ->execute([':folio' => $folio, ':id' => $produccionId]);
    }

    prod_insertar_detalles($conexion, $produccionId, $insumos, $resultado);

    if ($confirmar) {
        prod_confirmar_en_transaccion($conexion, $produccionId, $usuarioId);
    }

    prod_auditar(
        $conexion,
        $confirmar ? ($esNuevo ? 'PRODUCCION_CREADA_CONFIRMADA' : 'PRODUCCION_EDITADA_CONFIRMADA') : ($esNuevo ? 'PRODUCCION_BORRADOR_CREADO' : 'PRODUCCION_BORRADOR_GUARDADO'),
        $produccionId,
        ($confirmar ? 'Se confirmó' : 'Se guardó') . ' la producción ' . $folio . '.',
        null,
        [
            'folio' => $folio,
            'estado' => $confirmar ? 'CONFIRMADA' : 'BORRADOR',
            'insumos' => count($insumos),
            'receta_id' => $recetaId > 0 ? $recetaId : null,
            'receta_version' => $recetaVersion > 0 ? $recetaVersion : null,
            'resultado_producto_id' => $resultado['producto_id'],
            'resultado_cantidad_base' => $resultado['cantidad_base'],
        ]
    );

    $conexion->commit();
    si_responder_json(true, $confirmar ? 'Producción confirmada correctamente.' : 'Borrador guardado correctamente.', [
        'produccion_id' => $produccionId,
        'folio' => $folio,
        'estado' => $confirmar ? 'CONFIRMADA' : 'BORRADOR',
    ]);
}

function prod_confirmar(PDO $conexion): void
{
    $id = prod_entero($_POST['produccion_id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'La producción seleccionada no es válida.', [], 422);
    }

    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $conexion->beginTransaction();
    $resultado = prod_confirmar_en_transaccion($conexion, $id, $usuarioId);

    prod_auditar(
        $conexion,
        'PRODUCCION_CONFIRMADA',
        $id,
        'Se confirmó la producción ' . $resultado['folio'] . '.',
        ['estado' => 'BORRADOR'],
        ['estado' => 'CONFIRMADA', 'movimientos' => $resultado['movimientos']]
    );

    $conexion->commit();
    si_responder_json(true, 'Producción confirmada correctamente.', [
        'produccion_id' => $id,
        'folio' => $resultado['folio'],
        'estado' => 'CONFIRMADA',
    ]);
}

function prod_confirmar_en_transaccion(PDO $conexion, int $produccionId, int $usuarioId): array
{
    $stmtProd = $conexion->prepare(
        "SELECT id, folio, fecha_produccion, estado
         FROM producciones
         WHERE id = :id
         FOR UPDATE"
    );
    $stmtProd->execute([':id' => $produccionId]);
    $produccion = $stmtProd->fetch();
    if (!$produccion) {
        prod_abort($conexion, 'La producción ya no existe.', 404);
    }
    if ($produccion['estado'] !== 'BORRADOR') {
        prod_abort($conexion, 'Solo las producciones en borrador pueden confirmarse.', 409);
    }

    $insumos = prod_cargar_insumos_confirmacion($conexion, $produccionId);
    $resultados = prod_cargar_resultados_confirmacion($conexion, $produccionId);
    if (!$insumos) {
        prod_abort($conexion, 'La producción no contiene materias primas.', 409);
    }
    if (count($resultados) !== 1) {
        prod_abort($conexion, 'La producción debe contener exactamente un producto terminado.', 409);
    }
    $resultado = $resultados[0];

    $tipoSalida = prod_tipo_movimiento($conexion, 'SALIDA_PRODUCCION');
    $tipoEntrada = prod_tipo_movimiento($conexion, 'ENTRADA_PRODUCCION');
    if (!$tipoSalida || !$tipoEntrada) {
        prod_abort($conexion, 'La configuración de tipos de movimiento para producción está incompleta.', 500);
    }

    // El resultado puede no tener todavía fila de existencia; se crea en cero de forma idempotente.
    $stmtEnsure = $conexion->prepare(
        "INSERT INTO existencias_almacen
            (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo)
         VALUES
            (:almacen_id, :producto_id, 0, 0, 0)
         ON DUPLICATE KEY UPDATE producto_id = producto_id"
    );
    $stmtEnsure->execute([
        ':almacen_id' => (int) $resultado['almacen_id'],
        ':producto_id' => (int) $resultado['producto_id'],
    ]);

    $claves = [];
    foreach ($insumos as $insumo) {
        $claves[(int) $insumo['almacen_id'] . ':' . (int) $insumo['producto_id']] = [
            (int) $insumo['almacen_id'],
            (int) $insumo['producto_id'],
        ];
    }
    $claves[(int) $resultado['almacen_id'] . ':' . (int) $resultado['producto_id']] = [
        (int) $resultado['almacen_id'],
        (int) $resultado['producto_id'],
    ];
    ksort($claves, SORT_NATURAL);

    $existencias = [];
    $stmtLock = $conexion->prepare(
        "SELECT id, almacen_id, producto_id, existencia_fisica, cantidad_reservada, cantidad_disponible, costo_promedio_base
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id
           AND producto_id = :producto_id
         FOR UPDATE"
    );

    foreach ($claves as $clave => [$almacenId, $productoId]) {
        $stmtLock->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);
        $fila = $stmtLock->fetch();
        if ($fila) {
            $existencias[$clave] = $fila;
        }
    }

    $costoTotal = 0.0;
    $costosCompletos = true;

    foreach ($insumos as &$insumo) {
        $clave = (int) $insumo['almacen_id'] . ':' . (int) $insumo['producto_id'];
        $existencia = $existencias[$clave] ?? null;
        if (!$existencia) {
            prod_abort($conexion, 'Uno de los insumos no tiene existencia registrada en el almacén.', 409);
        }

        $disponible = (float) $existencia['existencia_fisica'] - (float) $existencia['cantidad_reservada'];
        $requerido = (float) $insumo['cantidad_base'];
        if ($requerido <= 0 || $disponible + 0.000001 < $requerido) {
            prod_abort(
                $conexion,
                'No hay existencia disponible suficiente de ' . $insumo['producto'] . '. Disponible: '
                . prod_numero($disponible) . ' ' . $insumo['simbolo_base'] . ', requerido: '
                . prod_numero($requerido) . ' ' . $insumo['simbolo_base'] . '.',
                409
            );
        }

        $costo = $existencia['costo_promedio_base'] !== null ? (float) $existencia['costo_promedio_base'] : null;
        $insumo['costo_unitario_base'] = $costo;
        if ($costo === null || $costo < 0) {
            $costosCompletos = false;
        } else {
            $costoTotal += $requerido * $costo;
        }
    }
    unset($insumo);

    $cantidadResultado = (float) $resultado['cantidad_base'];
    if ($cantidadResultado <= 0) {
        prod_abort($conexion, 'La cantidad producida no es válida.', 409);
    }
    $costoProduccionUnitario = $costosCompletos ? round($costoTotal / $cantidadResultado, 6) : null;

    $movSalidaId = prod_crear_movimiento(
        $conexion,
        (int) $tipoSalida['id'],
        (string) $produccion['fecha_produccion'],
        'PRODUCCION',
        $produccionId,
        'PRODUCCION:' . $produccionId . ':SALIDA',
        'Consumo de materias primas por ' . $produccion['folio'],
        $usuarioId,
        null
    );

    $stmtUpdateExistencia = $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :existencia_nueva
         WHERE id = :existencia_id"
    );
    $stmtDetalleMov = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento_id, :renglon, :almacen_id, :producto_id, :cantidad_delta, :existencia_antes, :existencia_despues, :costo_unitario_base, :observaciones)"
    );

    $renglon = 1;
    foreach ($insumos as $insumo) {
        $clave = (int) $insumo['almacen_id'] . ':' . (int) $insumo['producto_id'];
        $existencia = $existencias[$clave];
        $antes = (float) $existencia['existencia_fisica'];
        $delta = -(float) $insumo['cantidad_base'];
        $despues = round($antes + $delta, 6);
        if ($despues < -0.000001) {
            prod_abort($conexion, 'La producción dejaría una existencia física negativa.', 409);
        }

        $stmtUpdateExistencia->execute([
            ':existencia_nueva' => max(0.0, $despues),
            ':existencia_id' => (int) $existencia['id'],
        ]);
        $stmtDetalleMov->execute([
            ':movimiento_id' => $movSalidaId,
            ':renglon' => $renglon++,
            ':almacen_id' => (int) $insumo['almacen_id'],
            ':producto_id' => (int) $insumo['producto_id'],
            ':cantidad_delta' => $delta,
            ':existencia_antes' => $antes,
            ':existencia_despues' => max(0.0, $despues),
            ':costo_unitario_base' => $insumo['costo_unitario_base'],
            ':observaciones' => prod_texto($insumo['observaciones'] ?? '', 255) ?: null,
        ]);
    }

    $movEntradaId = prod_crear_movimiento(
        $conexion,
        (int) $tipoEntrada['id'],
        (string) $produccion['fecha_produccion'],
        'PRODUCCION',
        $produccionId,
        'PRODUCCION:' . $produccionId . ':ENTRADA',
        'Entrada de producto terminado por ' . $produccion['folio'],
        $usuarioId,
        null
    );

    $claveResultado = (int) $resultado['almacen_id'] . ':' . (int) $resultado['producto_id'];
    $existenciaResultado = $existencias[$claveResultado] ?? null;
    if (!$existenciaResultado) {
        prod_abort($conexion, 'No fue posible preparar la existencia del producto terminado.', 500);
    }

    $antesResultado = (float) $existenciaResultado['existencia_fisica'];
    $despuesResultado = round($antesResultado + $cantidadResultado, 6);
    $costoAnterior = $existenciaResultado['costo_promedio_base'] !== null ? (float) $existenciaResultado['costo_promedio_base'] : null;
    $nuevoCosto = $costoAnterior;

    if ($costoProduccionUnitario !== null) {
        if ($antesResultado <= 0.000001 || $costoAnterior === null) {
            $nuevoCosto = $costoProduccionUnitario;
        } else {
            $nuevoCosto = round((($antesResultado * $costoAnterior) + ($cantidadResultado * $costoProduccionUnitario)) / $despuesResultado, 6);
        }
    }

    $stmtResultado = $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :existencia_nueva,
             costo_promedio_base = :costo_nuevo
         WHERE id = :existencia_id"
    );
    $stmtResultado->execute([
        ':existencia_nueva' => $despuesResultado,
        ':costo_nuevo' => $nuevoCosto,
        ':existencia_id' => (int) $existenciaResultado['id'],
    ]);

    $stmtDetalleMov->execute([
        ':movimiento_id' => $movEntradaId,
        ':renglon' => 1,
        ':almacen_id' => (int) $resultado['almacen_id'],
        ':producto_id' => (int) $resultado['producto_id'],
        ':cantidad_delta' => $cantidadResultado,
        ':existencia_antes' => $antesResultado,
        ':existencia_despues' => $despuesResultado,
        ':costo_unitario_base' => $costoProduccionUnitario,
        ':observaciones' => prod_texto($resultado['observaciones'] ?? '', 255) ?: null,
    ]);

    $stmtConfirmar = $conexion->prepare(
        "UPDATE producciones
         SET estado = 'CONFIRMADA',
             confirmada_at = NOW(),
             confirmada_by = :confirmada_by
         WHERE id = :id"
    );
    $stmtConfirmar->execute([':confirmada_by' => $usuarioId, ':id' => $produccionId]);

    return [
        'folio' => (string) $produccion['folio'],
        'movimientos' => [$movSalidaId, $movEntradaId],
        'costo_produccion_unitario' => $costoProduccionUnitario,
    ];
}

/* =========================================================================
   CANCELACIÓN / REVERSO
   ========================================================================= */

function prod_cancelar(PDO $conexion): void
{
    $id = prod_entero($_POST['produccion_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $motivo = prod_texto($_POST['motivo'] ?? '', 2000);
    if ($id <= 0) {
        si_responder_json(false, 'La producción seleccionada no es válida.', [], 422);
    }
    if (mb_strlen($motivo) < 3) {
        si_responder_json(false, 'Captura el motivo de cancelación.', [], 422);
    }

    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT id, folio, estado
         FROM producciones
         WHERE id = :id
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $produccion = $stmt->fetch();
    if (!$produccion) {
        prod_abort($conexion, 'La producción ya no existe.', 404);
    }
    if ($produccion['estado'] === 'CANCELADA') {
        prod_abort($conexion, 'La producción ya está cancelada.', 409);
    }

    if ($produccion['estado'] === 'BORRADOR') {
        $stmtCancelar = $conexion->prepare(
            "UPDATE producciones
             SET estado = 'CANCELADA',
                 motivo_cancelacion = :motivo_cancelacion,
                 cancelada_at = NOW(),
                 cancelada_by = :cancelada_by
             WHERE id = :id"
        );
        $stmtCancelar->execute([
            ':motivo_cancelacion' => $motivo,
            ':cancelada_by' => $usuarioId,
            ':id' => $id,
        ]);

        prod_auditar($conexion, 'PRODUCCION_BORRADOR_CANCELADO', $id, 'Se canceló el borrador ' . $produccion['folio'] . '.', ['estado' => 'BORRADOR'], ['estado' => 'CANCELADA', 'motivo' => $motivo]);
        $conexion->commit();
        si_responder_json(true, 'Borrador cancelado correctamente.', ['produccion_id' => $id]);
    }

    if ($produccion['estado'] !== 'CONFIRMADA') {
        prod_abort($conexion, 'El estado actual de la producción no permite cancelarla.', 409);
    }

    $insumos = prod_cargar_insumos_confirmacion($conexion, $id);
    $resultados = prod_cargar_resultados_confirmacion($conexion, $id);
    if (!$insumos || count($resultados) !== 1) {
        prod_abort($conexion, 'No fue posible reconstruir los movimientos de la producción.', 409);
    }
    $resultado = $resultados[0];

    $stmtMov = $conexion->prepare(
        "SELECT mi.id, mi.folio, t.codigo AS tipo_codigo
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
         WHERE mi.origen_tipo = 'PRODUCCION'
           AND mi.origen_id = :origen_id
           AND mi.estado = 'APLICADO'
           AND t.codigo IN ('SALIDA_PRODUCCION', 'ENTRADA_PRODUCCION')
         ORDER BY mi.id ASC
         FOR UPDATE"
    );
    $stmtMov->execute([':origen_id' => $id]);
    $movimientos = $stmtMov->fetchAll();
    $porTipo = [];
    foreach ($movimientos as $m) {
        $porTipo[(string) $m['tipo_codigo']] = $m;
    }
    if (!isset($porTipo['SALIDA_PRODUCCION'], $porTipo['ENTRADA_PRODUCCION'])) {
        prod_abort($conexion, 'Los movimientos de inventario de esta producción no están completos o ya fueron revertidos.', 409);
    }

    $tipoReverso = prod_tipo_movimiento($conexion, 'REVERSO');
    if (!$tipoReverso) {
        prod_abort($conexion, 'El tipo de movimiento REVERSO no está configurado.', 500);
    }

    $claves = [];
    foreach ($insumos as $insumo) {
        $claves[(int) $insumo['almacen_id'] . ':' . (int) $insumo['producto_id']] = [(int) $insumo['almacen_id'], (int) $insumo['producto_id']];
    }
    $claves[(int) $resultado['almacen_id'] . ':' . (int) $resultado['producto_id']] = [(int) $resultado['almacen_id'], (int) $resultado['producto_id']];
    ksort($claves, SORT_NATURAL);

    $stmtLock = $conexion->prepare(
        "SELECT id, almacen_id, producto_id, existencia_fisica, cantidad_reservada, cantidad_disponible, costo_promedio_base
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id
           AND producto_id = :producto_id
         FOR UPDATE"
    );
    $existencias = [];
    foreach ($claves as $clave => [$almacenId, $productoId]) {
        $stmtLock->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);
        $fila = $stmtLock->fetch();
        if (!$fila) {
            prod_abort($conexion, 'Falta una existencia necesaria para revertir la producción.', 409);
        }
        $existencias[$clave] = $fila;
    }

    $claveResultado = (int) $resultado['almacen_id'] . ':' . (int) $resultado['producto_id'];
    $eResultado = $existencias[$claveResultado];
    $cantidadResultado = (float) $resultado['cantidad_base'];
    $disponibleResultado = (float) $eResultado['existencia_fisica'] - (float) $eResultado['cantidad_reservada'];
    if ($disponibleResultado + 0.000001 < $cantidadResultado) {
        prod_abort(
            $conexion,
            'No se puede cancelar porque el producto terminado ya no tiene suficiente existencia disponible para retirar lo producido. Disponible: '
            . prod_numero($disponibleResultado) . ' ' . $resultado['simbolo_base'] . '.',
            409
        );
    }

    $costosSalida = prod_costos_movimiento($conexion, (int) $porTipo['SALIDA_PRODUCCION']['id']);
    $costosEntrada = prod_costos_movimiento($conexion, (int) $porTipo['ENTRADA_PRODUCCION']['id']);

    // Primero revierte la entrada de producto terminado (sale del inventario).
    $revEntradaId = prod_crear_movimiento(
        $conexion,
        (int) $tipoReverso['id'],
        date('Y-m-d H:i:s'),
        'PRODUCCION',
        $id,
        'PRODUCCION:' . $id . ':REVERSO_ENTRADA',
        'Reverso de entrada de producto terminado por cancelación de ' . $produccion['folio'],
        $usuarioId,
        (int) $porTipo['ENTRADA_PRODUCCION']['id']
    );

    $antesRes = (float) $eResultado['existencia_fisica'];
    $despuesRes = round($antesRes - $cantidadResultado, 6);
    $costoActualRes = $eResultado['costo_promedio_base'] !== null ? (float) $eResultado['costo_promedio_base'] : null;
    $costoOriginalRes = $costosEntrada[$claveResultado] ?? null;
    $nuevoCostoRes = prod_costo_al_retirar($antesRes, $costoActualRes, $cantidadResultado, $costoOriginalRes);

    $stmtUpdate = $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :existencia_nueva,
             costo_promedio_base = :costo_nuevo
         WHERE id = :existencia_id"
    );
    $stmtUpdate->execute([
        ':existencia_nueva' => max(0.0, $despuesRes),
        ':costo_nuevo' => $nuevoCostoRes,
        ':existencia_id' => (int) $eResultado['id'],
    ]);

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento_id, :renglon, :almacen_id, :producto_id, :cantidad_delta, :existencia_antes, :existencia_despues, :costo_unitario_base, :observaciones)"
    );
    $stmtDet->execute([
        ':movimiento_id' => $revEntradaId,
        ':renglon' => 1,
        ':almacen_id' => (int) $resultado['almacen_id'],
        ':producto_id' => (int) $resultado['producto_id'],
        ':cantidad_delta' => -$cantidadResultado,
        ':existencia_antes' => $antesRes,
        ':existencia_despues' => max(0.0, $despuesRes),
        ':costo_unitario_base' => $costoOriginalRes,
        ':observaciones' => prod_texto($motivo, 255),
    ]);

    // Después devuelve las materias primas consumidas.
    $revSalidaId = prod_crear_movimiento(
        $conexion,
        (int) $tipoReverso['id'],
        date('Y-m-d H:i:s'),
        'PRODUCCION',
        $id,
        'PRODUCCION:' . $id . ':REVERSO_SALIDA',
        'Reverso de consumo de materias primas por cancelación de ' . $produccion['folio'],
        $usuarioId,
        (int) $porTipo['SALIDA_PRODUCCION']['id']
    );

    $renglon = 1;
    foreach ($insumos as $insumo) {
        $clave = (int) $insumo['almacen_id'] . ':' . (int) $insumo['producto_id'];
        $e = $existencias[$clave];
        $antes = (float) $e['existencia_fisica'];
        $cantidad = (float) $insumo['cantidad_base'];
        $despues = round($antes + $cantidad, 6);
        $costoActual = $e['costo_promedio_base'] !== null ? (float) $e['costo_promedio_base'] : null;
        $costoOriginal = $costosSalida[$clave] ?? null;
        $nuevoCosto = prod_costo_al_agregar($antes, $costoActual, $cantidad, $costoOriginal);

        $stmtUpdate->execute([
            ':existencia_nueva' => $despues,
            ':costo_nuevo' => $nuevoCosto,
            ':existencia_id' => (int) $e['id'],
        ]);
        $stmtDet->execute([
            ':movimiento_id' => $revSalidaId,
            ':renglon' => $renglon++,
            ':almacen_id' => (int) $insumo['almacen_id'],
            ':producto_id' => (int) $insumo['producto_id'],
            ':cantidad_delta' => $cantidad,
            ':existencia_antes' => $antes,
            ':existencia_despues' => $despues,
            ':costo_unitario_base' => $costoOriginal,
            ':observaciones' => prod_texto($motivo, 255),
        ]);
    }

    $stmtRevertir = $conexion->prepare("UPDATE movimientos_inventario SET estado = 'REVERTIDO' WHERE id = :id AND estado = 'APLICADO'");
    $stmtRevertir->execute([':id' => (int) $porTipo['SALIDA_PRODUCCION']['id']]);
    $stmtRevertir->execute([':id' => (int) $porTipo['ENTRADA_PRODUCCION']['id']]);

    $stmtCancelar = $conexion->prepare(
        "UPDATE producciones
         SET estado = 'CANCELADA',
             motivo_cancelacion = :motivo_cancelacion,
             cancelada_at = NOW(),
             cancelada_by = :cancelada_by
         WHERE id = :id"
    );
    $stmtCancelar->execute([
        ':motivo_cancelacion' => $motivo,
        ':cancelada_by' => $usuarioId,
        ':id' => $id,
    ]);

    prod_auditar(
        $conexion,
        'PRODUCCION_CANCELADA',
        $id,
        'Se canceló y revirtió la producción ' . $produccion['folio'] . '.',
        ['estado' => 'CONFIRMADA'],
        ['estado' => 'CANCELADA', 'motivo' => $motivo, 'reversos' => [$revEntradaId, $revSalidaId]]
    );

    $conexion->commit();
    si_responder_json(true, 'Producción cancelada y movimientos de inventario revertidos correctamente.', [
        'produccion_id' => $id,
        'estado' => 'CANCELADA',
    ]);
}

/* =========================================================================
   HELPERS DE PRODUCCIÓN
   ========================================================================= */

function prod_payload(): array
{
    $raw = (string) ($_POST['payload'] ?? '');
    if ($raw === '') {
        si_responder_json(false, 'No se recibieron los datos de producción.', [], 422);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        si_responder_json(false, 'Los datos de producción no tienen un formato válido.', [], 422);
    }
    return $payload;
}

function prod_validar_renglon(PDO $conexion, array $raw, string $tipo, int $almacenId): ?array
{
    $productoId = prod_entero($raw['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $cantidad = prod_decimal_positivo($raw['cantidad'] ?? null);
    $opcionUnidad = prod_texto($raw['opcion_unidad'] ?? 'BASE', 50);
    $observaciones = prod_texto($raw['observaciones'] ?? '', 255);

    if ($productoId <= 0 || $cantidad === null) {
        return null;
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            p.permite_fraccion,
            p.controla_inventario,
            um.nombre AS unidad_base,
            um.simbolo AS simbolo_base
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE p.id = :id
           AND p.activo = 1
           AND p.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':id' => $productoId]);
    $producto = $stmt->fetch();
    if (!$producto || (int) $producto['controla_inventario'] !== 1) {
        return null;
    }

    $tipoEsperado = $tipo === 'INSUMO' ? 'MATERIA_PRIMA' : 'PRODUCTO_TERMINADO';
    if ($producto['tipo'] !== $tipoEsperado) {
        si_responder_json(false, $tipo === 'INSUMO' ? 'Los insumos deben ser materias primas.' : 'El resultado debe ser un producto terminado.', [], 422);
    }
    if ((int) $producto['permite_fraccion'] !== 1 && abs($cantidad - round($cantidad)) > 0.000001) {
        si_responder_json(false, 'El producto ' . $producto['nombre'] . ' no permite cantidades fraccionarias.', [], 422);
    }

    $unidad = prod_resolver_unidad($conexion, $productoId, (int) $producto['unidad_base_id'], $opcionUnidad);
    if (!$unidad) {
        si_responder_json(false, 'La unidad seleccionada para ' . $producto['nombre'] . ' ya no está disponible.', [], 409);
    }

    $cantidadBase = round($cantidad * (float) $unidad['factor'], 6);
    if ($cantidadBase <= 0 || $cantidadBase > 999999999999.0) {
        si_responder_json(false, 'La cantidad de ' . $producto['nombre'] . ' está fuera del rango permitido.', [], 422);
    }

    return [
        'almacen_id' => $almacenId,
        'producto_id' => $productoId,
        'sku' => (string) $producto['sku'],
        'producto' => (string) $producto['nombre'],
        'unidad_id' => (int) $unidad['unidad_id'],
        'unidad_nombre' => (string) $unidad['nombre'],
        'unidad_simbolo' => (string) $unidad['simbolo'],
        'cantidad' => $cantidad,
        'factor_a_unidad_base' => (float) $unidad['factor'],
        'cantidad_base' => $cantidadBase,
        'simbolo_base' => (string) $producto['simbolo_base'],
        'observaciones' => $observaciones !== '' ? $observaciones : null,
    ];
}

function prod_resolver_unidad(PDO $conexion, int $productoId, int $unidadBaseId, string $opcion): ?array
{
    if ($opcion === '' || strtoupper($opcion) === 'BASE') {
        $stmt = $conexion->prepare("SELECT id AS unidad_id, nombre, simbolo FROM unidades_medida WHERE id = :id AND activo = 1 LIMIT 1");
        $stmt->execute([':id' => $unidadBaseId]);
        $u = $stmt->fetch();
        if (!$u) {
            return null;
        }
        return [
            'unidad_id' => (int) $u['unidad_id'],
            'nombre' => (string) $u['nombre'],
            'simbolo' => (string) $u['simbolo'],
            'factor' => 1.0,
            'presentacion_id' => null,
        ];
    }

    if (!preg_match('/^P:(\d+)$/', $opcion, $m)) {
        return null;
    }
    $presentacionId = (int) $m[1];
    $stmt = $conexion->prepare(
        "SELECT pp.id, pp.unidad_id, pp.nombre, pp.factor_a_unidad_base, um.simbolo
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida um ON um.id = pp.unidad_id
         WHERE pp.id = :presentacion_id
           AND pp.producto_id = :producto_id
           AND pp.activo = 1
           AND pp.factor_a_unidad_base > 0
         LIMIT 1"
    );
    $stmt->execute([':presentacion_id' => $presentacionId, ':producto_id' => $productoId]);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }
    return [
        'unidad_id' => (int) $p['unidad_id'],
        'nombre' => (string) $p['nombre'],
        'simbolo' => (string) $p['simbolo'],
        'factor' => (float) $p['factor_a_unidad_base'],
        'presentacion_id' => (int) $p['id'],
    ];
}

function prod_insertar_detalles(PDO $conexion, int $produccionId, array $insumos, array $resultado): void
{
    $stmtIns = $conexion->prepare(
        "INSERT INTO producciones_insumos
            (produccion_id, renglon, almacen_id, producto_id, unidad_id, cantidad, factor_a_unidad_base, cantidad_base, observaciones)
         VALUES
            (:produccion_id, :renglon, :almacen_id, :producto_id, :unidad_id, :cantidad, :factor, :cantidad_base, :observaciones)"
    );

    $renglon = 1;
    foreach ($insumos as $i) {
        $stmtIns->execute([
            ':produccion_id' => $produccionId,
            ':renglon' => $renglon++,
            ':almacen_id' => (int) $i['almacen_id'],
            ':producto_id' => (int) $i['producto_id'],
            ':unidad_id' => (int) $i['unidad_id'],
            ':cantidad' => (float) $i['cantidad'],
            ':factor' => (float) $i['factor_a_unidad_base'],
            ':cantidad_base' => (float) $i['cantidad_base'],
            ':observaciones' => $i['observaciones'],
        ]);
    }

    $stmtRes = $conexion->prepare(
        "INSERT INTO producciones_resultados
            (produccion_id, renglon, almacen_id, producto_id, unidad_id, cantidad, factor_a_unidad_base, cantidad_base, observaciones)
         VALUES
            (:produccion_id, 1, :almacen_id, :producto_id, :unidad_id, :cantidad, :factor, :cantidad_base, :observaciones)"
    );
    $stmtRes->execute([
        ':produccion_id' => $produccionId,
        ':almacen_id' => (int) $resultado['almacen_id'],
        ':producto_id' => (int) $resultado['producto_id'],
        ':unidad_id' => (int) $resultado['unidad_id'],
        ':cantidad' => (float) $resultado['cantidad'],
        ':factor' => (float) $resultado['factor_a_unidad_base'],
        ':cantidad_base' => (float) $resultado['cantidad_base'],
        ':observaciones' => $resultado['observaciones'],
    ]);
}

function prod_cargar_insumos_confirmacion(PDO $conexion, int $produccionId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            pi.*,
            p.nombre AS producto,
            p.sku,
            p.tipo,
            p.activo,
            p.deleted_at,
            p.controla_inventario,
            p.permite_fraccion,
            ub.simbolo AS simbolo_base
         FROM producciones_insumos pi
         INNER JOIN productos p ON p.id = pi.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         INNER JOIN almacenes a ON a.id = pi.almacen_id AND a.activo = 1
         WHERE pi.produccion_id = :produccion_id
         ORDER BY pi.renglon ASC"
    );
    $stmt->execute([':produccion_id' => $produccionId]);
    $filas = $stmt->fetchAll();
    foreach ($filas as $f) {
        if ($f['tipo'] !== 'MATERIA_PRIMA' || (int) $f['activo'] !== 1 || $f['deleted_at'] !== null || (int) $f['controla_inventario'] !== 1) {
            prod_abort($conexion, 'Uno de los insumos ya no está disponible para producción.', 409);
        }
    }
    return $filas;
}

function prod_cargar_resultados_confirmacion(PDO $conexion, int $produccionId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            pr.*,
            p.nombre AS producto,
            p.sku,
            p.tipo,
            p.activo,
            p.deleted_at,
            p.controla_inventario,
            p.permite_fraccion,
            ub.simbolo AS simbolo_base
         FROM producciones_resultados pr
         INNER JOIN productos p ON p.id = pr.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         INNER JOIN almacenes a ON a.id = pr.almacen_id AND a.activo = 1
         WHERE pr.produccion_id = :produccion_id
         ORDER BY pr.renglon ASC"
    );
    $stmt->execute([':produccion_id' => $produccionId]);
    $filas = $stmt->fetchAll();
    foreach ($filas as $f) {
        if ($f['tipo'] !== 'PRODUCTO_TERMINADO' || (int) $f['activo'] !== 1 || $f['deleted_at'] !== null || (int) $f['controla_inventario'] !== 1) {
            prod_abort($conexion, 'El producto terminado ya no está disponible para producción.', 409);
        }
    }
    return $filas;
}

function prod_tipo_movimiento(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare("SELECT id, codigo, nombre FROM tipos_movimiento_inventario WHERE codigo = :codigo AND activo = 1 LIMIT 1");
    $stmt->execute([':codigo' => $codigo]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function prod_crear_movimiento(
    PDO $conexion,
    int $tipoMovimientoId,
    string $fecha,
    string $origenTipo,
    int $origenId,
    string $idempotencyKey,
    string $motivo,
    int $usuarioId,
    ?int $movimientoRevertidoId
): int {
    $stmt = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id, idempotency_key,
             movimiento_revertido_id, motivo, aplicado_at, aplicado_by, created_by)
         VALUES
            (:folio, :tipo_movimiento_id, :fecha_movimiento, 'APLICADO', :origen_tipo, :origen_id, :idempotency_key,
             :movimiento_revertido_id, :motivo, NOW(), :aplicado_by, :created_by)"
    );
    $folioTmp = 'TMP-MOV-' . bin2hex(random_bytes(10));
    $stmt->execute([
        ':folio' => $folioTmp,
        ':tipo_movimiento_id' => $tipoMovimientoId,
        ':fecha_movimiento' => $fecha,
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $origenId,
        ':idempotency_key' => $idempotencyKey,
        ':movimiento_revertido_id' => $movimientoRevertidoId,
        ':motivo' => prod_texto($motivo, 2000),
        ':aplicado_by' => $usuarioId,
        ':created_by' => $usuarioId,
    ]);
    $id = (int) $conexion->lastInsertId();
    $folio = 'MOV-' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE movimientos_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);
    return $id;
}

function prod_costos_movimiento(PDO $conexion, int $movimientoId): array
{
    $stmt = $conexion->prepare(
        "SELECT almacen_id, producto_id, costo_unitario_base
         FROM movimientos_inventario_detalle
         WHERE movimiento_id = :movimiento_id"
    );
    $stmt->execute([':movimiento_id' => $movimientoId]);
    $costos = [];
    foreach ($stmt->fetchAll() as $r) {
        $clave = (int) $r['almacen_id'] . ':' . (int) $r['producto_id'];
        $costos[$clave] = $r['costo_unitario_base'] !== null ? (float) $r['costo_unitario_base'] : null;
    }
    return $costos;
}

function prod_costo_al_agregar(float $cantidadActual, ?float $costoActual, float $cantidadAgregar, ?float $costoAgregar): ?float
{
    $nuevoTotal = $cantidadActual + $cantidadAgregar;
    if ($nuevoTotal <= 0.000001) {
        return null;
    }
    if ($costoAgregar === null) {
        return $costoActual;
    }
    if ($cantidadActual <= 0.000001 || $costoActual === null) {
        return round($costoAgregar, 6);
    }
    return round((($cantidadActual * $costoActual) + ($cantidadAgregar * $costoAgregar)) / $nuevoTotal, 6);
}

function prod_costo_al_retirar(float $cantidadActual, ?float $costoActual, float $cantidadRetirar, ?float $costoRetirar): ?float
{
    $cantidadNueva = $cantidadActual - $cantidadRetirar;
    if ($cantidadNueva <= 0.000001) {
        return null;
    }
    if ($costoActual === null || $costoRetirar === null) {
        return $costoActual;
    }
    $valorNuevo = ($cantidadActual * $costoActual) - ($cantidadRetirar * $costoRetirar);
    if ($valorNuevo < 0) {
        return $costoActual;
    }
    return round($valorNuevo / $cantidadNueva, 6);
}

function prod_auditar(PDO $conexion, string $accion, int $entidadId, string $descripcion, ?array $antes, ?array $nuevos, string $tabla = 'producciones', string $modulo = 'Produccion'): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, :accion, :modulo, :tabla, :entidad_id, :descripcion, :datos_anteriores, :datos_nuevos, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario_id' => (int) ($_SESSION['usuario_id'] ?? 0),
        ':accion' => prod_texto($accion, 80),
        ':modulo' => prod_texto($modulo, 80),
        ':tabla' => prod_texto($tabla, 80),
        ':entidad_id' => $entidadId,
        ':descripcion' => prod_texto($descripcion, 500),
        ':datos_anteriores' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':datos_nuevos' => $nuevos !== null ? json_encode($nuevos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':ip' => prod_texto($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
        ':user_agent' => prod_texto($_SERVER['HTTP_USER_AGENT'] ?? '', 500) ?: null,
    ]);
}

function prod_almacen_activo(PDO $conexion, int $almacenId): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $almacenId]);
    return (bool) $stmt->fetchColumn();
}

function prod_normalizar_detalles(array &$filas): void
{
    foreach ($filas as &$f) {
        foreach (['id', 'renglon', 'almacen_id', 'producto_id', 'unidad_id', 'permite_fraccion'] as $campo) {
            if (array_key_exists($campo, $f)) {
                $f[$campo] = (int) $f[$campo];
            }
        }
        foreach (['cantidad', 'factor_a_unidad_base', 'cantidad_base', 'existencia_actual', 'reservado_actual', 'disponible_actual'] as $campo) {
            if (array_key_exists($campo, $f)) {
                $f[$campo] = (float) $f[$campo];
            }
        }
    }
    unset($f);
}

function prod_abort(PDO $conexion, string $mensaje, int $status = 409, array $extra = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $extra, $status);
}

function prod_texto($valor, int $max): string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return '';
    }
    return mb_substr($texto, 0, $max);
}

function prod_entero($valor, int $min, int $max, int $default): int
{
    if (!is_scalar($valor) || filter_var((string) $valor, FILTER_VALIDATE_INT) === false) {
        return $default;
    }
    $n = (int) $valor;
    return ($n >= $min && $n <= $max) ? $n : $default;
}

function prod_decimal_positivo($valor): ?float
{
    $texto = trim((string) $valor);
    if ($texto === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $texto)) {
        return null;
    }
    $n = (float) $texto;
    if (!is_finite($n) || $n <= 0 || $n > 999999999999.0) {
        return null;
    }
    return round($n, 6);
}

function prod_fecha($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
    return $dt && $dt->format('Y-m-d') === $texto ? $texto : null;
}

function prod_fecha_hora($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $texto);
        if ($dt !== false) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function prod_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function prod_numero(float $n): string
{
    $texto = number_format($n, 6, '.', ',');
    return rtrim(rtrim($texto, '0'), '.');
}

/* =========================================================================
   RECETAS DE PRODUCCIÓN
   Cada receta expresa lo necesario para 1 unidad/presentación del resultado.
   ========================================================================= */

function prod_recetas_listar(PDO $conexion): void
{
    $pagina = prod_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prod_entero($_GET['por_pagina'] ?? 12, 6, 48, 12);
    $buscar = prod_texto($_GET['buscar'] ?? '', 160);
    $estado = strtoupper(prod_texto($_GET['estado'] ?? 'ACTIVAS', 20));
    if (!in_array($estado, ['ACTIVAS', 'INACTIVAS', 'TODAS'], true)) {
        $estado = 'ACTIVAS';
    }

    $where = ['r.deleted_at IS NULL'];
    $params = [];
    if ($estado === 'ACTIVAS') $where[] = 'r.activo = 1';
    if ($estado === 'INACTIVAS') $where[] = 'r.activo = 0';
    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $where[] = '(r.codigo LIKE :b1 OR r.nombre LIKE :b2 OR p.nombre LIKE :b3 OR p.sku LIKE :b4)';
        $params = [':b1' => $like, ':b2' => $like, ':b3' => $like, ':b4' => $like];
    }
    $whereSql = implode(' AND ', $where);

    $st = $conexion->prepare("SELECT COUNT(*) FROM recetas_produccion r INNER JOIN productos p ON p.id = r.producto_resultado_id WHERE {$whereSql}");
    prod_bind($st, $params);
    $st->execute();
    $total = (int) $st->fetchColumn();
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                r.id, r.codigo, r.nombre, r.version, r.activo,
                r.presentacion_resultado_id,
                r.cantidad_resultado, r.factor_resultado_base, r.cantidad_resultado_base,
                r.observaciones, r.updated_at,
                p.id AS producto_id, p.sku, p.nombre AS producto,
                um.simbolo, um.nombre AS unidad, um.tipo AS tipo_unidad_referencia,
                pp.nombre AS presentacion_resultado,
                COALESCE(x.insumos, 0) AS insumos,
                x.ingredientes_resumen,
                CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS actualizado_por
            FROM recetas_produccion r
            INNER JOIN productos p ON p.id = r.producto_resultado_id
            INNER JOIN unidades_medida um ON um.id = r.unidad_resultado_id
            LEFT JOIN presentaciones_producto pp ON pp.id = r.presentacion_resultado_id
            LEFT JOIN (
                SELECT
                    ri.receta_id,
                    COUNT(*) AS insumos,
                    SUBSTRING_INDEX(GROUP_CONCAT(pi.nombre ORDER BY ri.renglon SEPARATOR '|||'), '|||', 3) AS ingredientes_resumen
                FROM recetas_produccion_insumos ri
                INNER JOIN productos pi ON pi.id = ri.producto_id
                GROUP BY ri.receta_id
            ) x ON x.receta_id = r.id
            LEFT JOIN usuarios u ON u.id = COALESCE(r.updated_by, r.created_by)
            WHERE {$whereSql}
            ORDER BY r.activo DESC, r.nombre ASC, r.id DESC
            LIMIT :lim OFFSET :off";

    $st = $conexion->prepare($sql);
    prod_bind($st, $params);
    $st->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $registros = $st->fetchAll();

    foreach ($registros as &$r) {
        $r['id'] = (int) $r['id'];
        $r['version'] = (int) $r['version'];
        $r['activo'] = (int) $r['activo'];
        $r['insumos'] = (int) $r['insumos'];
        $r['presentacion_resultado_id'] = $r['presentacion_resultado_id'] !== null ? (int) $r['presentacion_resultado_id'] : null;
        $r['cantidad_resultado'] = (float) $r['cantidad_resultado'];
        $r['factor_resultado_base'] = (float) $r['factor_resultado_base'];
        $r['cantidad_resultado_base'] = (float) $r['cantidad_resultado_base'];
        $r['referencia'] = prod_receta_referencia_texto($r);
        $r['ingredientes_resumen'] = $r['ingredientes_resumen'] ? explode('|||', (string) $r['ingredientes_resumen']) : [];
    }
    unset($r);

    si_responder_json(true, 'Recetas cargadas.', [
        'registros' => $registros,
        'paginacion' => ['pagina' => $pagina, 'paginas' => $paginas, 'total' => $total, 'por_pagina' => $porPagina],
    ]);
}

function prod_recetas_selector(PDO $conexion): void
{
    $st = $conexion->query(
        "SELECT
            r.id, r.codigo, r.nombre, r.version,
            r.producto_resultado_id, r.presentacion_resultado_id,
            r.unidad_resultado_id, r.cantidad_resultado,
            r.factor_resultado_base, r.cantidad_resultado_base,
            p.nombre AS producto, p.sku,
            um.nombre AS unidad, um.simbolo, um.tipo AS tipo_unidad_referencia,
            pp.nombre AS presentacion_resultado
         FROM recetas_produccion r
         INNER JOIN productos p ON p.id = r.producto_resultado_id
         INNER JOIN unidades_medida um ON um.id = r.unidad_resultado_id
         LEFT JOIN presentaciones_producto pp ON pp.id = r.presentacion_resultado_id
         WHERE r.activo = 1
           AND r.deleted_at IS NULL
           AND p.activo = 1
           AND p.deleted_at IS NULL
         ORDER BY r.nombre, r.id"
    );
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['version'] = (int) $r['version'];
        $r['producto_resultado_id'] = (int) $r['producto_resultado_id'];
        $r['presentacion_resultado_id'] = $r['presentacion_resultado_id'] !== null ? (int) $r['presentacion_resultado_id'] : null;
        $r['cantidad_resultado'] = (float) $r['cantidad_resultado'];
        $r['factor_resultado_base'] = (float) $r['factor_resultado_base'];
        $r['cantidad_resultado_base'] = (float) $r['cantidad_resultado_base'];
        $r['referencia'] = prod_receta_referencia_texto($r);
    }
    unset($r);
    si_responder_json(true, 'Recetas disponibles.', ['recetas' => $rows]);
}

function prod_receta_detalle(PDO $conexion): void
{
    $id = prod_entero($_GET['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'La receta no es válida.', [], 422);
    }

    $st = $conexion->prepare(
        "SELECT
            r.*,
            p.nombre AS producto, p.sku, p.permite_fraccion, p.unidad_base_id,
            ub.nombre AS unidad_base, ub.simbolo AS simbolo_base, ub.codigo AS codigo_unidad_base, ub.tipo AS tipo_unidad_base,
            um.nombre AS unidad, um.simbolo, um.tipo AS tipo_unidad_referencia,
            pp.nombre AS presentacion_resultado
         FROM recetas_produccion r
         INNER JOIN productos p ON p.id = r.producto_resultado_id
         INNER JOIN unidades_medida um ON um.id = r.unidad_resultado_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = r.presentacion_resultado_id
         WHERE r.id = :id AND r.deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    if (!$r) {
        si_responder_json(false, 'La receta ya no existe.', [], 404);
    }

    $st = $conexion->prepare(
        "SELECT
            ri.*,
            p.nombre AS producto, p.sku, p.permite_fraccion, p.unidad_base_id,
            um.nombre AS unidad, um.simbolo,
            ub.nombre AS unidad_base, ub.simbolo AS simbolo_base, ub.codigo AS codigo_unidad_base, ub.tipo AS tipo_unidad_base,
            pp.nombre AS presentacion
         FROM recetas_produccion_insumos ri
         INNER JOIN productos p ON p.id = ri.producto_id
         INNER JOIN unidades_medida um ON um.id = ri.unidad_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = ri.presentacion_id
         WHERE ri.receta_id = :id
         ORDER BY ri.renglon"
    );
    $st->execute([':id' => $id]);
    $insumos = $st->fetchAll();

    $r['id'] = (int) $r['id'];
    $r['version'] = (int) $r['version'];
    $r['activo'] = (int) $r['activo'];
    $r['producto_resultado_id'] = (int) $r['producto_resultado_id'];
    $r['presentacion_resultado_id'] = $r['presentacion_resultado_id'] !== null ? (int) $r['presentacion_resultado_id'] : null;
    $r['unidad_resultado_id'] = (int) $r['unidad_resultado_id'];
    $r['cantidad_resultado'] = (float) $r['cantidad_resultado'];
    $r['factor_resultado_base'] = (float) $r['factor_resultado_base'];
    $r['cantidad_resultado_base'] = (float) $r['cantidad_resultado_base'];
    $r['referencia'] = prod_receta_referencia_texto($r);

    foreach ($insumos as &$i) {
        $i['id'] = (int) $i['id'];
        $i['producto_id'] = (int) $i['producto_id'];
        $i['presentacion_id'] = $i['presentacion_id'] !== null ? (int) $i['presentacion_id'] : null;
        $i['unidad_id'] = (int) $i['unidad_id'];
        $i['unidad_base_id'] = (int) $i['unidad_base_id'];
        $i['cantidad'] = (float) $i['cantidad'];
        $i['factor_a_unidad_base'] = (float) $i['factor_a_unidad_base'];
        $i['cantidad_base'] = (float) $i['cantidad_base'];
    }
    unset($i);

    $balance = prod_receta_balance_desde_datos($r, $insumos);
    si_responder_json(true, 'Detalle de receta.', ['receta' => $r, 'insumos' => $insumos, 'balance' => $balance]);
}

function prod_receta_buscar_productos(PDO $conexion): void
{
    $q = prod_texto($_GET['q'] ?? '', 180);
    $tipo = strtoupper(prod_texto($_GET['tipo'] ?? 'INSUMO', 20));
    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['productos' => []]);
    }
    if (!in_array($tipo, ['INSUMO', 'RESULTADO'], true)) {
        si_responder_json(false, 'Tipo no válido.', [], 422);
    }

    $tipoProducto = $tipo === 'INSUMO' ? 'MATERIA_PRIMA' : 'PRODUCTO_TERMINADO';
    $like = '%' . $q . '%';
    $st = $conexion->prepare(
        "SELECT
            p.id, p.sku, p.nombre, p.tipo, p.unidad_base_id, p.permite_fraccion,
            um.nombre AS unidad_base, um.simbolo AS simbolo_base, um.codigo AS codigo_unidad_base, um.tipo AS tipo_unidad_base
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE p.activo = 1
           AND p.deleted_at IS NULL
           AND p.controla_inventario = 1
           AND p.tipo = :tipo
           AND (p.sku LIKE :s OR p.nombre LIKE :n OR p.codigo_barras LIKE :c)
         ORDER BY p.nombre
         LIMIT 20"
    );
    $st->execute([':tipo' => $tipoProducto, ':s' => $like, ':n' => $like, ':c' => $like]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['unidad_base_id'] = (int) $r['unidad_base_id'];
        $r['permite_fraccion'] = (int) $r['permite_fraccion'];
    }
    unset($r);
    si_responder_json(true, 'Productos encontrados.', ['productos' => $rows]);
}

function prod_receta_escalar(PDO $conexion): void
{
    $id = prod_entero($_GET['receta_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $almacen = prod_entero($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $unidadesObjetivo = prod_decimal_positivo($_GET['cantidad'] ?? null);
    if ($id <= 0 || $almacen <= 0 || $unidadesObjetivo === null) {
        si_responder_json(false, 'Selecciona receta, almacén y cantidad a producir.', [], 422);
    }
    if (!prod_almacen_activo($conexion, $almacen)) {
        si_responder_json(false, 'El almacén ya no está activo.', [], 409);
    }

    $st = $conexion->prepare(
        "SELECT
            r.*,
            p.nombre AS producto, p.sku, p.permite_fraccion, p.unidad_base_id,
            ub.simbolo AS simbolo_base,
            um.nombre AS unidad, um.simbolo, um.tipo AS tipo_unidad_referencia,
            pp.nombre AS presentacion_resultado
         FROM recetas_produccion r
         INNER JOIN productos p ON p.id = r.producto_resultado_id
         INNER JOIN unidades_medida um ON um.id = r.unidad_resultado_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = r.presentacion_resultado_id
         WHERE r.id = :id AND r.activo = 1 AND r.deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    if (!$r) {
        si_responder_json(false, 'La receta ya no está disponible.', [], 404);
    }

    if ((string) ($r['tipo_unidad_referencia'] ?? '') === 'UNIDAD' && abs($unidadesObjetivo - round($unidadesObjetivo)) > 0.000001) {
        si_responder_json(false, 'La presentación de referencia se produce en unidades completas. Captura un número entero.', [], 422);
    }

    // La receta siempre representa exactamente 1 unidad/presentación del producto final.
    $escala = $unidadesObjetivo;

    $st = $conexion->prepare(
        "SELECT
            ri.*,
            p.nombre AS producto, p.sku, p.permite_fraccion, p.unidad_base_id,
            ub.simbolo AS simbolo_base,
            COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(e.cantidad_disponible, 0) AS cantidad_disponible
         FROM recetas_produccion_insumos ri
         INNER JOIN productos p ON p.id = ri.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e ON e.almacen_id = :almacen AND e.producto_id = ri.producto_id
         WHERE ri.receta_id = :id
         ORDER BY ri.renglon"
    );
    $st->execute([':almacen' => $almacen, ':id' => $id]);
    $insumos = $st->fetchAll();

    $faltantes = [];
    foreach ($insumos as &$i) {
        $i['producto_id'] = (int) $i['producto_id'];
        $i['presentacion_id'] = $i['presentacion_id'] !== null ? (int) $i['presentacion_id'] : null;
        $i['unidad_id'] = (int) $i['unidad_id'];
        $i['cantidad'] = round((float) $i['cantidad'] * $escala, 6);
        $i['cantidad_base'] = round((float) $i['cantidad_base'] * $escala, 6);
        $i['factor_a_unidad_base'] = (float) $i['factor_a_unidad_base'];
        $i['existencia_fisica'] = (float) $i['existencia_fisica'];
        $i['cantidad_reservada'] = (float) $i['cantidad_reservada'];
        $i['cantidad_disponible'] = (float) $i['cantidad_disponible'];
        $i['suficiente'] = $i['cantidad_disponible'] + 0.000001 >= $i['cantidad_base'];
        if (!$i['suficiente']) {
            $faltantes[] = [
                'producto' => $i['producto'],
                'necesario' => $i['cantidad_base'],
                'disponible' => $i['cantidad_disponible'],
                'faltante' => max(0, round($i['cantidad_base'] - $i['cantidad_disponible'], 6)),
                'simbolo' => $i['simbolo_base'],
            ];
        }
    }
    unset($i);

    $referencia = prod_receta_referencia_texto($r);
    $cantidadResultado = $unidadesObjetivo;
    $cantidadResultadoBase = round($unidadesObjetivo * (float) $r['factor_resultado_base'], 6);

    si_responder_json(true, 'Receta calculada.', [
        'receta' => [
            'id' => (int) $r['id'],
            'codigo' => $r['codigo'],
            'nombre' => $r['nombre'],
            'version' => (int) $r['version'],
            'referencia' => $referencia,
        ],
        'resultado' => [
            'producto_id' => (int) $r['producto_resultado_id'],
            'producto' => $r['producto'],
            'sku' => $r['sku'],
            'presentacion_id' => $r['presentacion_resultado_id'] !== null ? (int) $r['presentacion_resultado_id'] : null,
            'unidad_id' => (int) $r['unidad_resultado_id'],
            'cantidad' => $cantidadResultado,
            'factor_a_unidad_base' => (float) $r['factor_resultado_base'],
            'cantidad_base' => $cantidadResultadoBase,
            'simbolo' => $r['simbolo'],
            'simbolo_base' => $r['simbolo_base'],
            'permite_fraccion' => (int) $r['permite_fraccion'],
        ],
        'insumos' => $insumos,
        'faltantes' => $faltantes,
        'puede_producir' => count($faltantes) === 0,
    ]);
}

function prod_receta_guardar(PDO $conexion): void
{
    $payload = prod_payload();
    $id = prod_entero($payload['id'] ?? 0, 0, PHP_INT_MAX, 0);
    $usuario = (int) ($_SESSION['usuario_id'] ?? 0);
    $nombre = prod_texto($payload['nombre'] ?? '', 160);
    $observaciones = prod_texto($payload['observaciones'] ?? '', 4000);
    $activo = !empty($payload['activo']) ? 1 : 0;

    if (mb_strlen($nombre) < 3) {
        si_responder_json(false, 'Captura un nombre de receta de al menos 3 caracteres.', [], 422);
    }

    $resultadoRaw = is_array($payload['resultado'] ?? null) ? $payload['resultado'] : [];
    $insumosRaw = is_array($payload['insumos'] ?? null) ? $payload['insumos'] : [];

    $resultado = prod_receta_validar_renglon($conexion, $resultadoRaw, 'RESULTADO');
    if (!$resultado) {
        si_responder_json(false, 'Selecciona el producto terminado y la presentación de referencia de la receta.', [], 422);
    }
    if (!$insumosRaw) {
        si_responder_json(false, 'Agrega al menos una materia prima a la receta.', [], 422);
    }
    if (count($insumosRaw) > 100) {
        si_responder_json(false, 'La receta no puede superar 100 ingredientes.', [], 422);
    }

    $insumos = [];
    $vistos = [];
    foreach ($insumosRaw as $n => $raw) {
        if (!is_array($raw)) {
            si_responder_json(false, 'Revisa el ingrediente #' . ($n + 1) . '.', [], 422);
        }
        $x = prod_receta_validar_renglon($conexion, $raw, 'INSUMO');
        if (!$x) {
            si_responder_json(false, 'Revisa el ingrediente #' . ($n + 1) . '.', [], 422);
        }
        if (isset($vistos[$x['producto_id']])) {
            si_responder_json(false, 'Cada materia prima debe aparecer una sola vez en la receta.', [], 422);
        }
        $vistos[$x['producto_id']] = true;
        $insumos[] = $x;
    }

    $balance = prod_receta_balance_desde_datos($resultado, $insumos);
    if (!empty($balance['bloquea_guardado'])) {
        si_responder_json(false, $balance['mensaje'] . ' Corrige la receta antes de guardarla.', [
            'balance' => $balance,
        ], 422);
    }

    $conexion->beginTransaction();
    if ($id > 0) {
        $st = $conexion->prepare("SELECT id, codigo, version FROM recetas_produccion WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
        $st->execute([':id' => $id]);
        $anterior = $st->fetch();
        if (!$anterior) {
            prod_abort($conexion, 'La receta ya no existe.', 404);
        }
        $version = (int) $anterior['version'] + 1;
        $codigo = (string) $anterior['codigo'];

        $st = $conexion->prepare(
            "UPDATE recetas_produccion
             SET nombre = :nombre,
                 producto_resultado_id = :producto,
                 presentacion_resultado_id = :presentacion,
                 unidad_resultado_id = :unidad,
                 cantidad_resultado = 1,
                 factor_resultado_base = :factor,
                 cantidad_resultado_base = :base,
                 version = :version,
                 activo = :activo,
                 observaciones = :obs,
                 updated_by = :usuario
             WHERE id = :id"
        );
        $st->execute([
            ':nombre' => $nombre,
            ':producto' => $resultado['producto_id'],
            ':presentacion' => $resultado['presentacion_id'],
            ':unidad' => $resultado['unidad_id'],
            ':factor' => $resultado['factor_a_unidad_base'],
            ':base' => $resultado['cantidad_base'],
            ':version' => $version,
            ':activo' => $activo,
            ':obs' => $observaciones !== '' ? $observaciones : null,
            ':usuario' => $usuario,
            ':id' => $id,
        ]);
        $conexion->prepare("DELETE FROM recetas_produccion_insumos WHERE receta_id = :id")->execute([':id' => $id]);
    } else {
        $temporal = 'TMP-REC-' . bin2hex(random_bytes(8));
        $version = 1;
        $st = $conexion->prepare(
            "INSERT INTO recetas_produccion
                (codigo, nombre, producto_resultado_id, presentacion_resultado_id, unidad_resultado_id,
                 cantidad_resultado, factor_resultado_base, cantidad_resultado_base,
                 version, activo, observaciones, created_by, updated_by)
             VALUES
                (:codigo, :nombre, :producto, :presentacion, :unidad,
                 1, :factor, :base,
                 1, :activo, :obs, :creado, :actualizado)"
        );
        $st->execute([
            ':codigo' => $temporal,
            ':nombre' => $nombre,
            ':producto' => $resultado['producto_id'],
            ':presentacion' => $resultado['presentacion_id'],
            ':unidad' => $resultado['unidad_id'],
            ':factor' => $resultado['factor_a_unidad_base'],
            ':base' => $resultado['cantidad_base'],
            ':activo' => $activo,
            ':obs' => $observaciones !== '' ? $observaciones : null,
            ':creado' => $usuario,
            ':actualizado' => $usuario,
        ]);
        $id = (int) $conexion->lastInsertId();
        $codigo = 'REC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $conexion->prepare("UPDATE recetas_produccion SET codigo = :codigo WHERE id = :id")->execute([':codigo' => $codigo, ':id' => $id]);
    }

    $st = $conexion->prepare(
        "INSERT INTO recetas_produccion_insumos
            (receta_id, renglon, producto_id, presentacion_id, unidad_id, cantidad, factor_a_unidad_base, cantidad_base, observaciones)
         VALUES
            (:receta, :renglon, :producto, :presentacion, :unidad, :cantidad, :factor, :base, :obs)"
    );
    $renglon = 1;
    foreach ($insumos as $x) {
        $st->execute([
            ':receta' => $id,
            ':renglon' => $renglon++,
            ':producto' => $x['producto_id'],
            ':presentacion' => $x['presentacion_id'],
            ':unidad' => $x['unidad_id'],
            ':cantidad' => $x['cantidad'],
            ':factor' => $x['factor_a_unidad_base'],
            ':base' => $x['cantidad_base'],
            ':obs' => $x['observaciones'],
        ]);
    }

    prod_auditar(
        $conexion,
        $version === 1 ? 'RECETA_CREADA' : 'RECETA_EDITADA',
        $id,
        ($version === 1 ? 'Se creó' : 'Se actualizó') . ' la receta ' . $codigo . ' por 1 ' . $resultado['unidad_nombre'] . '.',
        null,
        [
            'codigo' => $codigo,
            'version' => $version,
            'producto_id' => $resultado['producto_id'],
            'presentacion_resultado_id' => $resultado['presentacion_id'],
            'factor_resultado_base' => $resultado['factor_a_unidad_base'],
            'insumos' => count($insumos),
            'balance' => $balance,
        ],
        'recetas_produccion',
        'Produccion'
    );

    $conexion->commit();
    si_responder_json(true, $version === 1 ? 'Receta creada correctamente.' : 'Receta actualizada correctamente.', [
        'receta_id' => $id,
        'codigo' => $codigo,
        'version' => $version,
        'balance' => $balance,
    ]);
}

function prod_receta_eliminar(PDO $conexion): void
{
    $id = prod_entero($_POST['receta_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $usuario = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($id <= 0) {
        si_responder_json(false, 'La receta no es válida.', [], 422);
    }

    $conexion->beginTransaction();
    $st = $conexion->prepare("SELECT id, codigo, nombre FROM recetas_produccion WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    if (!$r) {
        prod_abort($conexion, 'La receta ya no existe.', 404);
    }

    $conexion->prepare(
        "UPDATE recetas_produccion
         SET activo = 0, deleted_at = NOW(), deleted_by = :usuario, updated_by = :actualizado
         WHERE id = :id"
    )->execute([':usuario' => $usuario, ':actualizado' => $usuario, ':id' => $id]);

    prod_auditar($conexion, 'RECETA_ELIMINADA', $id, 'Se retiró la receta ' . $r['codigo'] . ' del catálogo.', null, [
        'codigo' => $r['codigo'], 'nombre' => $r['nombre'],
    ], 'recetas_produccion', 'Produccion');

    $conexion->commit();
    si_responder_json(true, 'Receta eliminada del catálogo.');
}

function prod_receta_validar_renglon(PDO $conexion, array $raw, string $tipo): ?array
{
    $productoId = prod_entero($raw['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $opcion = prod_texto($raw['opcion_unidad'] ?? 'BASE', 50);
    $observaciones = prod_texto($raw['observaciones'] ?? '', 255);
    $cantidad = $tipo === 'RESULTADO' ? 1.0 : prod_decimal_positivo($raw['cantidad'] ?? null);
    if ($productoId <= 0 || $cantidad === null) {
        return null;
    }

    $st = $conexion->prepare(
        "SELECT
            p.id, p.nombre, p.sku, p.tipo, p.unidad_base_id, p.permite_fraccion, p.controla_inventario,
            um.nombre AS unidad_base, um.simbolo AS simbolo_base, um.codigo AS codigo_unidad_base, um.tipo AS tipo_unidad_base
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE p.id = :id AND p.activo = 1 AND p.deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([':id' => $productoId]);
    $producto = $st->fetch();
    if (!$producto || (int) $producto['controla_inventario'] !== 1) {
        return null;
    }

    $esperado = $tipo === 'INSUMO' ? 'MATERIA_PRIMA' : 'PRODUCTO_TERMINADO';
    if ($producto['tipo'] !== $esperado) {
        si_responder_json(false, $tipo === 'INSUMO' ? 'Los ingredientes deben ser materias primas.' : 'El resultado debe ser un producto terminado.', [], 422);
    }
    if ($tipo === 'INSUMO' && (int) $producto['permite_fraccion'] !== 1 && abs($cantidad - round($cantidad)) > 0.000001) {
        si_responder_json(false, 'El producto ' . $producto['nombre'] . ' no permite fracciones.', [], 422);
    }

    $unidad = prod_resolver_unidad($conexion, $productoId, (int) $producto['unidad_base_id'], $opcion);
    if (!$unidad) {
        si_responder_json(false, 'La unidad o presentación de ' . $producto['nombre'] . ' ya no está disponible.', [], 409);
    }

    $cantidadBase = round($cantidad * (float) $unidad['factor'], 6);
    if ($cantidadBase <= 0) {
        si_responder_json(false, 'La cantidad debe ser mayor que cero.', [], 422);
    }

    return [
        'producto_id' => $productoId,
        'producto' => $producto['nombre'],
        'sku' => $producto['sku'],
        'presentacion_id' => $unidad['presentacion_id'] ?? null,
        'unidad_id' => (int) $unidad['unidad_id'],
        'unidad_nombre' => (string) $unidad['nombre'],
        'unidad_simbolo' => (string) $unidad['simbolo'],
        'cantidad' => $cantidad,
        'factor_a_unidad_base' => (float) $unidad['factor'],
        'cantidad_base' => $cantidadBase,
        'unidad_base' => (string) $producto['unidad_base'],
        'simbolo_base' => (string) $producto['simbolo_base'],
        'codigo_unidad_base' => (string) $producto['codigo_unidad_base'],
        'tipo_unidad_base' => (string) $producto['tipo_unidad_base'],
        'observaciones' => $observaciones !== '' ? $observaciones : null,
    ];
}

function prod_receta_balance_desde_datos(array $resultado, array $insumos): array
{
    $salidaKg = prod_receta_masa_a_kg(
        (float) ($resultado['cantidad_resultado_base'] ?? $resultado['cantidad_base'] ?? 0),
        (string) ($resultado['codigo_unidad_base'] ?? '')
    );

    if ($salidaKg === null || $salidaKg <= 0) {
        return [
            'comparable' => 0,
            'mensaje' => 'La presentación del producto terminado no puede compararse automáticamente por masa. La receta seguirá siendo válida, pero revisa manualmente su rendimiento.',
            'bloquea_guardado' => 0,
        ];
    }

    $masaInsumosKg = 0.0;
    $noComparables = 0;
    foreach ($insumos as $i) {
        $kg = prod_receta_masa_a_kg((float) ($i['cantidad_base'] ?? 0), (string) ($i['codigo_unidad_base'] ?? ''));
        if ($kg === null) {
            $noComparables++;
            continue;
        }
        $masaInsumosKg += $kg;
    }

    $masaInsumosKg = round($masaInsumosKg, 6);
    $ratio = $salidaKg > 0 ? $masaInsumosKg / $salidaKg : 0.0;
    $diferencia = round($masaInsumosKg - $salidaKg, 6);

    // Una receta estándar no debe aceptar desbalances absurdos en las magnitudes que sí son comparables.
    // Si hay ingredientes volumétricos no intentamos inventar densidades: el control de masa queda parcial.
    // Para procesos excepcionales con pérdidas extremas se conserva la captura manual de Producción.
    $bloquea = $ratio > 1.50 || ($noComparables === 0 && $ratio < 0.50);
    if ($bloquea) {
        $mensaje = 'La receta es desproporcionada: las materias primas comparables suman ' . prod_numero_texto($masaInsumosKg)
            . ' kg para una presentación que produce ' . prod_numero_texto($salidaKg) . ' kg.';
    } elseif ($ratio > 1.10) {
        $mensaje = 'Aviso: la masa de materias primas supera en más de 10% la masa del producto terminado. Puede existir merma de proceso, pero conviene revisar la fórmula.';
    } elseif ($noComparables === 0 && $ratio < 0.90) {
        $mensaje = 'Aviso: la masa capturada de materias primas es menor que la masa del producto terminado. Revisa si falta algún ingrediente.';
    } elseif ($noComparables > 0) {
        $mensaje = 'La masa comparable está en un rango razonable. Hay ' . $noComparables . ' ingrediente(s) en unidades que no pueden convertirse automáticamente a kg; el balance es informativo.';
    } else {
        $mensaje = 'La suma de materias primas de masa es coherente con la presentación de referencia.';
    }

    return [
        'comparable' => 1,
        'masa_resultado_kg' => round($salidaKg, 6),
        'masa_insumos_kg' => $masaInsumosKg,
        'diferencia_kg' => $diferencia,
        'ratio' => round($ratio, 6),
        'no_comparables' => $noComparables,
        'bloquea_guardado' => $bloquea ? 1 : 0,
        'mensaje' => $mensaje,
    ];
}

function prod_receta_masa_a_kg(float $cantidadBase, string $codigoUnidadBase): ?float
{
    $codigo = strtoupper(trim($codigoUnidadBase));
    $factor = match ($codigo) {
        'KG' => 1.0,
        'G' => 0.001,
        'TON' => 1000.0,
        default => null,
    };
    return $factor === null ? null : $cantidadBase * $factor;
}

function prod_receta_referencia_texto(array $r): string
{
    $presentacion = trim((string) ($r['presentacion_resultado'] ?? ''));
    if ($presentacion !== '') {
        return '1 ' . $presentacion;
    }
    $unidad = trim((string) ($r['unidad'] ?? ''));
    $simbolo = trim((string) ($r['simbolo'] ?? ''));
    if ($unidad !== '') {
        return '1 ' . $unidad . ($simbolo !== '' ? ' (' . $simbolo . ')' : '');
    }
    return '1 unidad';
}

function prod_numero_texto(float $valor): string
{
    $texto = number_format($valor, 6, '.', '');
    $texto = rtrim(rtrim($texto, '0'), '.');
    return $texto === '' ? '0' : $texto;
}
