<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('inventario.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

si_requerir_metodo('GET');

$accion = strtoupper(trim((string) ($_GET['accion'] ?? 'LISTAR_INVENTARIO')));

try {
    switch ($accion) {
        case 'CATALOGOS':
            inv_catalogos($conexion);
            break;

        case 'RESUMEN':
            inv_resumen($conexion);
            break;

        case 'LISTAR_INVENTARIO':
            inv_listar_inventario($conexion);
            break;

        case 'LISTAR_KARDEX':
            if (!si_tiene_permiso('inventario.kardex')) {
                si_responder_json(false, 'No tienes permiso para consultar el Kardex.', [], 403);
            }
            inv_listar_kardex($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    $referencia = 'INV-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][INVENTARIO][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'No fue posible consultar la información de inventario.',
        ['referencia' => $referencia],
        500
    );
} catch (Throwable $e) {
    $referencia = 'INV-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][INVENTARIO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al consultar inventario.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS
   ========================================================================= */

function inv_catalogos(PDO $conexion): void
{
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $tiposMovimiento = $conexion->query(
        "SELECT id, codigo, nombre
         FROM tipos_movimiento_inventario
         WHERE activo = 1
         ORDER BY id ASC"
    )->fetchAll();

    foreach ($almacenes as &$almacen) {
        $almacen['id'] = (int) $almacen['id'];
    }
    unset($almacen);

    foreach ($tiposMovimiento as &$tipo) {
        $tipo['id'] = (int) $tipo['id'];
    }
    unset($tipo);

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'almacenes' => $almacenes,
            'tipos_movimiento' => $tiposMovimiento,
            'puede_kardex' => si_tiene_permiso('inventario.kardex'),
        ]
    );
}

/* =========================================================================
   RESUMEN DE EXISTENCIAS
   ========================================================================= */

function inv_resumen(PDO $conexion): void
{
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipoProducto = inv_tipo_producto($_GET['tipo_producto'] ?? 'TODOS');
    $estadoProducto = inv_estado_producto($_GET['estado_producto'] ?? 'TODOS');

    [$where, $params] = inv_filtros_base_existencias(
        $almacenId,
        $tipoProducto,
        $estadoProducto,
        ''
    );

    $disponible = inv_sql_disponible();
    $estadoStock = inv_sql_estado_stock();

    $sql = "SELECT
                COUNT(*) AS total_registros,
                SUM(CASE WHEN x.existencia_fisica > 0 THEN 1 ELSE 0 END) AS con_existencia,
                SUM(CASE WHEN x.estado_stock IN ('SIN_STOCK', 'SIN_DISPONIBLE') THEN 1 ELSE 0 END) AS sin_disponible,
                SUM(CASE WHEN x.estado_stock = 'CRITICO' THEN 1 ELSE 0 END) AS criticos,
                SUM(CASE WHEN x.estado_stock = 'REORDEN' THEN 1 ELSE 0 END) AS reorden,
                SUM(CASE WHEN x.cantidad_reservada > 0 THEN 1 ELSE 0 END) AS con_reserva,
                COALESCE(SUM(x.cantidad_reservada), 0) AS reservado_total
            FROM (
                SELECT
                    COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
                    COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
                    {$disponible} AS cantidad_disponible,
                    {$estadoStock} AS estado_stock
                FROM productos p
                INNER JOIN unidades_medida um
                    ON um.id = p.unidad_base_id
                CROSS JOIN almacenes a
                LEFT JOIN existencias_almacen ea
                    ON ea.producto_id = p.id
                   AND ea.almacen_id = a.id
                {$where}
            ) x";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $fila = $stmt->fetch() ?: [];

    si_responder_json(
        true,
        'Resumen cargado.',
        [
            'resumen' => [
                'total_registros' => (int) ($fila['total_registros'] ?? 0),
                'con_existencia' => (int) ($fila['con_existencia'] ?? 0),
                'sin_disponible' => (int) ($fila['sin_disponible'] ?? 0),
                'criticos' => (int) ($fila['criticos'] ?? 0),
                'reorden' => (int) ($fila['reorden'] ?? 0),
                'con_reserva' => (int) ($fila['con_reserva'] ?? 0),
                'reservado_total' => (float) ($fila['reservado_total'] ?? 0),
            ],
        ]
    );
}

/* =========================================================================
   LISTADO DE EXISTENCIAS
   ========================================================================= */

function inv_listar_inventario(PDO $conexion): void
{
    $pagina = inv_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = inv_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = inv_texto($_GET['buscar'] ?? '', 120);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipoProducto = inv_tipo_producto($_GET['tipo_producto'] ?? 'TODOS');
    $estadoProducto = inv_estado_producto($_GET['estado_producto'] ?? 'TODOS');
    $estadoStockFiltro = inv_estado_stock($_GET['estado_stock'] ?? 'TODOS');

    [$where, $params] = inv_filtros_base_existencias(
        $almacenId,
        $tipoProducto,
        $estadoProducto,
        $buscar
    );

    $estadoStock = inv_sql_estado_stock();
    $disponible = inv_sql_disponible();

    if ($estadoStockFiltro !== 'TODOS') {
        $where .= " AND ({$estadoStock}) = :estado_stock";
        $params[':estado_stock'] = $estadoStockFiltro;
    }

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM productos p
         INNER JOIN unidades_medida um
            ON um.id = p.unidad_base_id
         CROSS JOIN almacenes a
         LEFT JOIN existencias_almacen ea
            ON ea.producto_id = p.id
           AND ea.almacen_id = a.id
         {$where}"
    );
    inv_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                p.id AS producto_id,
                p.sku,
                p.codigo_barras,
                p.nombre AS producto,
                p.tipo AS tipo_producto,
                p.activo AS producto_activo,
                um.nombre AS unidad_base,
                um.simbolo AS unidad_simbolo,
                a.id AS almacen_id,
                a.codigo AS almacen_codigo,
                a.nombre AS almacen,
                COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
                COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
                {$disponible} AS cantidad_disponible,
                COALESCE(ea.stock_minimo, 0) AS stock_minimo,
                ea.punto_reorden,
                {$estadoStock} AS estado_stock,
                ea.updated_at
            FROM productos p
            INNER JOIN unidades_medida um
                ON um.id = p.unidad_base_id
            CROSS JOIN almacenes a
            LEFT JOIN existencias_almacen ea
                ON ea.producto_id = p.id
               AND ea.almacen_id = a.id
            {$where}
            ORDER BY
                CASE ({$estadoStock})
                    WHEN 'SIN_STOCK' THEN 0
                    WHEN 'SIN_DISPONIBLE' THEN 1
                    WHEN 'CRITICO' THEN 2
                    WHEN 'REORDEN' THEN 3
                    ELSE 4
                END ASC,
                p.nombre ASC,
                a.nombre ASC,
                p.id ASC
            LIMIT :limite
            OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['producto_activo'] = (int) $fila['producto_activo'];
        $fila['almacen_id'] = (int) $fila['almacen_id'];
        $fila['existencia_fisica'] = (float) $fila['existencia_fisica'];
        $fila['cantidad_reservada'] = (float) $fila['cantidad_reservada'];
        $fila['cantidad_disponible'] = (float) $fila['cantidad_disponible'];
        $fila['stock_minimo'] = (float) $fila['stock_minimo'];
        $fila['punto_reorden'] = $fila['punto_reorden'] !== null
            ? (float) $fila['punto_reorden']
            : null;
    }
    unset($fila);

    si_responder_json(
        true,
        'Inventario cargado.',
        [
            'registros' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

/* =========================================================================
   KARDEX
   ========================================================================= */

function inv_listar_kardex(PDO $conexion): void
{
    $pagina = inv_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = inv_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = inv_texto($_GET['buscar'] ?? '', 120);
    $productoId = inv_entero_rango($_GET['producto_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipoMovimientoId = inv_entero_rango($_GET['tipo_movimiento_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $estado = inv_estado_movimiento($_GET['estado'] ?? 'TODOS');
    $fechaDesde = inv_fecha($_GET['fecha_desde'] ?? '');
    $fechaHasta = inv_fecha($_GET['fecha_hasta'] ?? '');

    if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaDesde > $fechaHasta) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la fecha final.', [], 422);
    }

    $where = "WHERE 1 = 1";
    $params = [];

    if ($buscar !== '') {
        $where .= " AND (
            p.sku LIKE :buscar_sku
            OR p.nombre LIKE :buscar_producto
            OR mi.folio LIKE :buscar_folio
            OR COALESCE(mi.origen_tipo, '') LIKE :buscar_origen
        )";
        $patron = '%' . $buscar . '%';
        $params[':buscar_sku'] = $patron;
        $params[':buscar_producto'] = $patron;
        $params[':buscar_folio'] = $patron;
        $params[':buscar_origen'] = $patron;
    }

    if ($productoId > 0) {
        $where .= ' AND mid.producto_id = :producto_id';
        $params[':producto_id'] = $productoId;
    }

    if ($almacenId > 0) {
        $where .= ' AND mid.almacen_id = :almacen_id';
        $params[':almacen_id'] = $almacenId;
    }

    if ($tipoMovimientoId > 0) {
        $where .= ' AND mi.tipo_movimiento_id = :tipo_movimiento_id';
        $params[':tipo_movimiento_id'] = $tipoMovimientoId;
    }

    if ($estado !== 'TODOS') {
        $where .= ' AND mi.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($fechaDesde !== '') {
        $where .= ' AND mi.fecha_movimiento >= :fecha_desde';
        $params[':fecha_desde'] = $fechaDesde . ' 00:00:00';
    }

    if ($fechaHasta !== '') {
        $where .= ' AND mi.fecha_movimiento <= :fecha_hasta';
        $params[':fecha_hasta'] = $fechaHasta . ' 23:59:59';
    }

    $from = "FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi
                ON mi.id = mid.movimiento_id
             INNER JOIN tipos_movimiento_inventario tmi
                ON tmi.id = mi.tipo_movimiento_id
             INNER JOIN almacenes a
                ON a.id = mid.almacen_id
             INNER JOIN productos p
                ON p.id = mid.producto_id
             INNER JOIN unidades_medida um
                ON um.id = p.unidad_base_id
             LEFT JOIN usuarios u
                ON u.id = COALESCE(mi.aplicado_by, mi.created_by)
             LEFT JOIN recepciones_compra rc
                ON rc.id = mi.origen_id
               AND mi.origen_tipo IN ('RECEPCION_COMPRA', 'CANCELACION_RECEPCION_COMPRA')
             LEFT JOIN compras c
                ON c.id = rc.compra_id";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$where}");
    inv_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                mid.id AS detalle_id,
                mi.id AS movimiento_id,
                mi.folio,
                mi.fecha_movimiento,
                mi.estado,
                tmi.codigo AS tipo_codigo,
                tmi.nombre AS tipo_movimiento,
                mi.origen_tipo,
                mi.origen_id,
                CASE
                    WHEN mi.origen_tipo IN ('RECEPCION_COMPRA', 'CANCELACION_RECEPCION_COMPRA')
                         AND rc.id IS NOT NULL
                    THEN CONCAT(rc.folio, IF(c.folio IS NULL, '', CONCAT(' / ', c.folio)))
                    WHEN mi.origen_tipo IS NOT NULL AND mi.origen_id IS NOT NULL
                    THEN CONCAT(mi.origen_tipo, ' #', mi.origen_id)
                    WHEN mi.origen_tipo IS NOT NULL
                    THEN mi.origen_tipo
                    ELSE 'Movimiento manual / interno'
                END AS origen_referencia,
                p.id AS producto_id,
                p.sku,
                p.nombre AS producto,
                um.nombre AS unidad_base,
                um.simbolo AS unidad_simbolo,
                a.id AS almacen_id,
                a.codigo AS almacen_codigo,
                a.nombre AS almacen,
                mid.cantidad_delta,
                CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END AS cantidad_entrada,
                CASE WHEN mid.cantidad_delta < 0 THEN ABS(mid.cantidad_delta) ELSE 0 END AS cantidad_salida,
                mid.existencia_antes,
                mid.existencia_despues,
                mid.costo_unitario_base,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)), ''),
                    u.usuario,
                    'Sistema'
                ) AS usuario_aplico,
                mi.motivo,
                COALESCE(mid.observaciones, mi.observaciones) AS observaciones
            {$from}
            {$where}
            ORDER BY mi.fecha_movimiento DESC, mi.id DESC, mid.renglon DESC, mid.id DESC
            LIMIT :limite
            OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['detalle_id'] = (int) $fila['detalle_id'];
        $fila['movimiento_id'] = (int) $fila['movimiento_id'];
        $fila['origen_id'] = $fila['origen_id'] !== null ? (int) $fila['origen_id'] : null;
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['almacen_id'] = (int) $fila['almacen_id'];
        $fila['cantidad_delta'] = (float) $fila['cantidad_delta'];
        $fila['cantidad_entrada'] = (float) $fila['cantidad_entrada'];
        $fila['cantidad_salida'] = (float) $fila['cantidad_salida'];
        $fila['existencia_antes'] = (float) $fila['existencia_antes'];
        $fila['existencia_despues'] = (float) $fila['existencia_despues'];
        $fila['costo_unitario_base'] = $fila['costo_unitario_base'] !== null
            ? (float) $fila['costo_unitario_base']
            : null;
    }
    unset($fila);

    si_responder_json(
        true,
        'Kardex cargado.',
        [
            'registros' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

/* =========================================================================
   FILTROS / SQL COMPARTIDO
   ========================================================================= */

function inv_filtros_base_existencias(
    int $almacenId,
    string $tipoProducto,
    string $estadoProducto,
    string $buscar
): array {
    $where = "WHERE p.controla_inventario = 1
              AND p.deleted_at IS NULL
              AND a.activo = 1";
    $params = [];

    if ($almacenId > 0) {
        $where .= ' AND a.id = :almacen_id';
        $params[':almacen_id'] = $almacenId;
    }

    if ($tipoProducto !== 'TODOS') {
        $where .= ' AND p.tipo = :tipo_producto';
        $params[':tipo_producto'] = $tipoProducto;
    }

    if ($estadoProducto === 'ACTIVO') {
        $where .= ' AND p.activo = 1';
    } elseif ($estadoProducto === 'INACTIVO') {
        $where .= ' AND p.activo = 0';
    }

    if ($buscar !== '') {
        $where .= " AND (
            p.sku LIKE :buscar_sku
            OR p.nombre LIKE :buscar_producto
            OR COALESCE(p.codigo_barras, '') LIKE :buscar_barra
        )";
        $patron = '%' . $buscar . '%';
        $params[':buscar_sku'] = $patron;
        $params[':buscar_producto'] = $patron;
        $params[':buscar_barra'] = $patron;
    }

    return [$where, $params];
}

function inv_sql_disponible(): string
{
    return '(COALESCE(ea.existencia_fisica, 0) - COALESCE(ea.cantidad_reservada, 0))';
}

function inv_sql_estado_stock(): string
{
    $disponible = inv_sql_disponible();

    return "CASE
        WHEN COALESCE(ea.existencia_fisica, 0) <= 0 THEN 'SIN_STOCK'
        WHEN {$disponible} <= 0 THEN 'SIN_DISPONIBLE'
        WHEN COALESCE(ea.stock_minimo, 0) > 0
             AND {$disponible} <= COALESCE(ea.stock_minimo, 0) THEN 'CRITICO'
        WHEN ea.punto_reorden IS NOT NULL
             AND ea.punto_reorden > 0
             AND {$disponible} <= ea.punto_reorden THEN 'REORDEN'
        ELSE 'NORMAL'
    END";
}

/* =========================================================================
   VALIDACIÓN / CONVERSIÓN
   ========================================================================= */

function inv_entero_rango($valor, int $min, int $max, int $predeterminado): int
{
    if (is_int($valor)) {
        $numero = $valor;
    } elseif (is_string($valor) && preg_match('/^-?\d+$/', trim($valor))) {
        $numero = (int) trim($valor);
    } else {
        return $predeterminado;
    }

    return max($min, min($max, $numero));
}

function inv_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($texto, 0, $maximo, 'UTF-8');
    }

    return substr($texto, 0, $maximo);
}

function inv_tipo_producto($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'MATERIA_PRIMA', 'PRODUCTO_TERMINADO'], true)
        ? $valor
        : 'TODOS';
}

function inv_estado_producto($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'ACTIVO', 'INACTIVO'], true)
        ? $valor
        : 'TODOS';
}

function inv_estado_stock($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array(
        $valor,
        ['TODOS', 'NORMAL', 'REORDEN', 'CRITICO', 'SIN_DISPONIBLE', 'SIN_STOCK'],
        true
    ) ? $valor : 'TODOS';
}

function inv_estado_movimiento($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'APLICADO', 'REVERTIDO', 'BORRADOR'], true)
        ? $valor
        : 'TODOS';
}

function inv_fecha($valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '';
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $errores = DateTimeImmutable::getLastErrors();

    if (!$fecha || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
        return '';
    }

    return $fecha->format('Y-m-d') === $valor ? $valor : '';
}

function inv_bind_params(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}
