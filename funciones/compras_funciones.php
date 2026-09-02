<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

/** @var PDO|null $conexion Conexión creada por inc/conexion.php. */
require_once __DIR__ . '/../inc/tipo_cambio_banxico.php';

si_requerir_permiso('compras.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_COMPRAS')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                cmp_catalogos($conexion);
                break;

            case 'LISTAR_COMPRAS':
                cmp_listar_compras($conexion);
                break;

            case 'DETALLE_COMPRA':
                cmp_detalle_compra($conexion);
                break;

            case 'BUSCAR_PROVEEDORES':
                cmp_buscar_proveedores($conexion);
                break;

            case 'DATOS_PROVEEDOR':
                cmp_datos_proveedor($conexion);
                break;

            case 'BUSCAR_PRODUCTOS_PROVEEDOR':
                cmp_buscar_productos_proveedor($conexion);
                break;

            case 'TIPO_CAMBIO':
                cmp_tipo_cambio($conexion);
                break;

            case 'BUSCAR_COMPRAS_PENDIENTES':
                cmp_requerir_permiso('recepciones.confirmar');
                cmp_buscar_compras_pendientes($conexion);
                break;

            case 'LISTAR_RECEPCIONES':
                cmp_requerir_permiso('recepciones.ver');
                cmp_listar_recepciones($conexion);
                break;

            case 'PREPARAR_RECEPCION':
                cmp_requerir_permiso('recepciones.confirmar');
                cmp_preparar_recepcion($conexion);
                break;

            case 'DETALLE_RECEPCION':
                cmp_requerir_permiso('recepciones.ver');
                cmp_detalle_recepcion($conexion);
                break;

            case 'HISTORIAL':
                cmp_historial($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'GUARDAR_COMPRA':
            cmp_requerir_permiso('compras.crear');
            cmp_guardar_compra($conexion);
            break;

        case 'CONFIRMAR_COMPRA':
            cmp_requerir_permiso('compras.crear');
            cmp_confirmar_compra($conexion);
            break;

        case 'CANCELAR_COMPRA':
            cmp_requerir_permiso('compras.cancelar');
            cmp_cancelar_compra($conexion);
            break;

        case 'GUARDAR_RECEPCION':
            cmp_requerir_permiso('recepciones.confirmar');
            cmp_guardar_recepcion($conexion);
            break;

        case 'CONFIRMAR_RECEPCION':
            cmp_requerir_permiso('recepciones.confirmar');
            cmp_confirmar_recepcion($conexion);
            break;

        case 'CANCELAR_RECEPCION':
            cmp_requerir_permiso('recepciones.cancelar');
            cmp_cancelar_recepcion($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'COMP-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][COMPRAS][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'Ya existe un documento con esos datos o el registro está relacionado con otra operación.',
            ['referencia' => $referencia],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible procesar la operación.',
        ['referencia' => $referencia],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'COMP-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][COMPRAS] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar compras.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS Y BÚSQUEDAS INTELIGENTES
   ========================================================================= */

function cmp_catalogos(PDO $conexion): void
{
    $monedas = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, es_base
         FROM monedas
         WHERE activo = 1
         ORDER BY es_base DESC, codigo ASC"
    )->fetchAll();

    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC"
    )->fetchAll();

    foreach ($monedas as &$m) {
        $m['id'] = (int) $m['id'];
        $m['es_base'] = (int) $m['es_base'];
    }
    unset($m);

    foreach ($almacenes as &$a) {
        $a['id'] = (int) $a['id'];
    }
    unset($a);

    $base = null;
    foreach ($monedas as $m) {
        if ($m['es_base'] === 1) {
            $base = $m;
            break;
        }
    }

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'monedas' => $monedas,
            'almacenes' => $almacenes,
            'moneda_base' => $base,
        ]
    );
}

function cmp_buscar_proveedores(PDO $conexion): void
{
    $q = cmp_texto($_GET['q'] ?? '', 180);

    if (mb_strlen($q) < 1) {
        si_responder_json(true, 'Sin búsqueda.', ['proveedores' => []]);
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.codigo,
            p.razon_social,
            p.nombre_comercial,
            p.rfc,
            p.moneda_default_id,
            m.codigo AS moneda_codigo,
            p.dias_credito,
            p.limite_credito
         FROM proveedores p
         LEFT JOIN monedas m
            ON m.id = p.moneda_default_id
         WHERE 1=1
           AND p.activo = 1
           AND (
                p.codigo = :exacto
                OR p.codigo LIKE :prefijo
                OR p.razon_social LIKE :razon
                OR p.nombre_comercial LIKE :comercial
                OR p.rfc LIKE :rfc
           )
         ORDER BY
            CASE WHEN p.codigo = :exacto_orden THEN 0 ELSE 1 END,
            p.razon_social ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':exacto' => strtoupper($q),
        ':prefijo' => strtoupper($q) . '%',
        ':razon' => '%' . $q . '%',
        ':comercial' => '%' . $q . '%',
        ':rfc' => strtoupper($q) . '%',
        ':exacto_orden' => strtoupper($q),
    ]);

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['moneda_default_id'] = $f['moneda_default_id'] !== null
            ? (int) $f['moneda_default_id']
            : null;
        $f['dias_credito'] = (int) $f['dias_credito'];
        $f['limite_credito'] = $f['limite_credito'] !== null
            ? (float) $f['limite_credito']
            : null;
    }
    unset($f);

    si_responder_json(true, 'Proveedores encontrados.', ['proveedores' => $filas]);
}

function cmp_datos_proveedor(PDO $conexion): void
{
    $id = cmp_id($_GET['proveedor_id'] ?? null, 'proveedor');

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.codigo,
            p.razon_social,
            p.nombre_comercial,
            p.rfc,
            p.moneda_default_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            p.dias_credito,
            p.limite_credito
         FROM proveedores p
         LEFT JOIN monedas m
            ON m.id = p.moneda_default_id
         WHERE p.id = :id
           AND p.activo = 1
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();

    if (!$p) {
        si_responder_json(false, 'El proveedor seleccionado no está disponible.', [], 404);
    }

    $p['id'] = (int) $p['id'];
    $p['moneda_default_id'] = $p['moneda_default_id'] !== null
        ? (int) $p['moneda_default_id']
        : null;
    $p['dias_credito'] = (int) $p['dias_credito'];
    $p['limite_credito'] = $p['limite_credito'] !== null
        ? (float) $p['limite_credito']
        : null;

    si_responder_json(true, 'Proveedor cargado.', ['proveedor' => $p]);
}

function cmp_buscar_productos_proveedor(PDO $conexion): void
{
    $proveedorId = cmp_id($_GET['proveedor_id'] ?? null, 'proveedor');
    $q = cmp_texto($_GET['q'] ?? '', 180);

    if (mb_strlen($q) < 1) {
        si_responder_json(true, 'Sin búsqueda.', ['productos' => []]);
    }

    $stmt = $conexion->prepare(
        "SELECT
            pp.id AS relacion_id,
            pp.producto_id,
            p.sku,
            p.nombre AS producto,
            pp.presentacion_id,
            COALESCE(pres.nombre, 'Unidad base') AS presentacion,
            COALESCE(pres.unidad_id, p.unidad_base_id) AS unidad_id,
            COALESCE(up.nombre, ub.nombre) AS unidad_nombre,
            COALESCE(up.simbolo, ub.simbolo) AS unidad_simbolo,
            COALESCE(pres.factor_a_unidad_base, 1) AS factor_a_unidad_base,
            pp.compra_minima,
            pp.dias_entrega,
            p.tasa_impuesto_id,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            hpp.precio_unitario AS ultimo_precio,
            hpp.moneda_id AS ultimo_precio_moneda_id,
            mon.codigo AS ultimo_precio_moneda,
            hpp.fecha_precio AS ultimo_precio_fecha
         FROM proveedores_productos pp
         INNER JOIN proveedores pr
            ON pr.id = pp.proveedor_id
         INNER JOIN productos p
            ON p.id = pp.producto_id
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         LEFT JOIN unidades_medida up
            ON up.id = pres.unidad_id
         LEFT JOIN tasas_impuesto ti
            ON ti.id = p.tasa_impuesto_id
         LEFT JOIN historial_precios_proveedor hpp
            ON hpp.id = (
                SELECT h2.id
                FROM historial_precios_proveedor h2
                WHERE h2.proveedor_producto_id = pp.id
                  AND h2.activo = 1
                  AND h2.fecha_precio <= NOW()
                  AND (h2.vigencia_hasta IS NULL OR h2.vigencia_hasta >= NOW())
                ORDER BY h2.fecha_precio DESC, h2.id DESC
                LIMIT 1
            )
         LEFT JOIN monedas mon
            ON mon.id = hpp.moneda_id
         WHERE pp.proveedor_id = :proveedor_id
           AND pp.activo = 1
           AND pr.activo = 1
           AND p.activo = 1
           AND p.tipo = 'MATERIA_PRIMA'
           AND (
                pp.presentacion_id IS NULL
                OR (pres.activo = 1 AND pres.es_compra = 1)
           )
           AND (
                p.sku = :exacto
                OR p.sku LIKE :prefijo
                OR p.nombre LIKE :nombre
                OR pres.nombre LIKE :presentacion_busqueda
           )
         ORDER BY
            CASE WHEN p.sku = :exacto_orden THEN 0 ELSE 1 END,
            p.nombre ASC,
            COALESCE(pres.nombre, '') ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':proveedor_id' => $proveedorId,
        ':exacto' => strtoupper($q),
        ':prefijo' => strtoupper($q) . '%',
        ':nombre' => '%' . $q . '%',
        ':presentacion_busqueda' => '%' . $q . '%',
        ':exacto_orden' => strtoupper($q),
    ]);

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['relacion_id'] = (int) $f['relacion_id'];
        $f['producto_id'] = (int) $f['producto_id'];
        $f['presentacion_id'] = $f['presentacion_id'] !== null
            ? (int) $f['presentacion_id']
            : null;
        $f['unidad_id'] = (int) $f['unidad_id'];
        $f['factor_a_unidad_base'] = (float) $f['factor_a_unidad_base'];
        $f['compra_minima'] = $f['compra_minima'] !== null
            ? (float) $f['compra_minima']
            : null;
        $f['dias_entrega'] = $f['dias_entrega'] !== null
            ? (int) $f['dias_entrega']
            : null;
        $f['tasa_impuesto_id'] = $f['tasa_impuesto_id'] !== null
            ? (int) $f['tasa_impuesto_id']
            : null;
        $f['impuesto_pct'] = (float) $f['impuesto_pct'];
        $f['ultimo_precio'] = $f['ultimo_precio'] !== null
            ? (float) $f['ultimo_precio']
            : null;
        $f['ultimo_precio_moneda_id'] = $f['ultimo_precio_moneda_id'] !== null
            ? (int) $f['ultimo_precio_moneda_id']
            : null;
    }
    unset($f);

    si_responder_json(true, 'Productos encontrados.', ['productos' => $filas]);
}

function cmp_tipo_cambio(PDO $conexion): void
{
    $monedaId = cmp_id($_GET['moneda_id'] ?? null, 'moneda');
    $fecha = cmp_fecha($_GET['fecha'] ?? date('Y-m-d'), 'fecha');

    $base = cmp_moneda_base($conexion);

    if (!$base) {
        si_responder_json(false, 'No está configurada la moneda base del sistema.', [], 500);
    }

    if ($monedaId === (int) $base['id']) {
        si_responder_json(
            true,
            'La moneda seleccionada es la moneda base.',
            [
                'encontrado' => true,
                'tipo_cambio' => 1,
                'fecha_tipo_cambio' => $fecha,
                'moneda_base' => $base,
            ]
        );
    }

    $tipo = cmp_buscar_tipo_cambio(
        $conexion,
        $monedaId,
        (int) $base['id'],
        $fecha
    );

    si_responder_json(
        true,
        $tipo !== null ? 'Tipo de cambio encontrado.' : 'No hay tipo de cambio registrado para esa fecha.',
        [
            'encontrado' => $tipo !== null,
            'tipo_cambio' => $tipo['tipo_cambio'] ?? null,
            'fecha_tipo_cambio' => $tipo['fecha'] ?? null,
            'fuente' => $tipo['fuente'] ?? null,
            'desactualizado' => (bool) ($tipo['desactualizado'] ?? false),
            'dias_habiles_antiguedad' => (int) ($tipo['dias_habiles_antiguedad'] ?? 0),
            'moneda_base' => $base,
        ]
    );
}

/* =========================================================================
   COMPRAS
   ========================================================================= */

function cmp_listar_compras(PDO $conexion): void
{
    $pagina = cmp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cmp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cmp_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cmp_texto($_GET['estado'] ?? 'TODOS', 30));
    $condicion = strtoupper(cmp_texto($_GET['condicion_pago'] ?? 'TODAS', 20));
    $desde = cmp_fecha_opcional($_GET['desde'] ?? '');
    $hasta = cmp_fecha_opcional($_GET['hasta'] ?? '');

    $estados = ['TODOS', 'BORRADOR', 'PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL', 'RECIBIDA', 'CANCELADA'];
    if (!in_array($estado, $estados, true)) {
        $estado = 'TODOS';
    }

    if (!in_array($condicion, ['TODAS', 'CONTADO', 'CREDITO'], true)) {
        $condicion = 'TODAS';
    }

    $where = ['1=1'];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            c.folio = :folio_exacto
            OR c.folio LIKE :folio_prefijo
            OR c.numero_factura LIKE :factura
            OR c.proveedor_nombre_snapshot LIKE :proveedor
            OR p.codigo LIKE :codigo_proveedor
        )";

        $params[':folio_exacto'] = strtoupper($q);
        $params[':folio_prefijo'] = strtoupper($q) . '%';
        $params[':factura'] = '%' . $q . '%';
        $params[':proveedor'] = '%' . $q . '%';
        $params[':codigo_proveedor'] = strtoupper($q) . '%';
    }

    if ($estado !== 'TODOS') {
        $where[] = 'c.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($condicion !== 'TODAS') {
        $where[] = 'c.condicion_pago = :condicion';
        $params[':condicion'] = $condicion;
    }

    if ($desde !== null) {
        $where[] = 'c.fecha_compra >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta !== null) {
        $where[] = 'c.fecha_compra < DATE_ADD(:hasta, INTERVAL 1 DAY)';
        $params[':hasta'] = $hasta . ' 00:00:00';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM compras c
         INNER JOIN proveedores p
            ON p.id = c.proveedor_id
         WHERE {$whereSql}"
    );

    cmp_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.fecha_compra,
            c.numero_factura,
            c.proveedor_id,
            c.proveedor_nombre_snapshot AS proveedor,
            p.codigo AS proveedor_codigo,
            m.codigo AS moneda,
            m.simbolo AS moneda_simbolo,
            c.condicion_pago,
            c.fecha_vencimiento,
            c.estado,
            c.total,
            c.created_at,
            COALESCE(r.recepciones_confirmadas, 0) AS recepciones_confirmadas,
            COALESCE(r.cantidad_base_recibida, 0) AS cantidad_base_recibida,
            COALESCE(d.cantidad_base_comprada, 0) AS cantidad_base_comprada
         FROM compras c
         INNER JOIN proveedores p
            ON p.id = c.proveedor_id
         INNER JOIN monedas m
            ON m.id = c.moneda_id
         LEFT JOIN (
            SELECT
                rc.compra_id,
                COUNT(DISTINCT rc.id) AS recepciones_confirmadas,
                SUM(rcd.cantidad_base) AS cantidad_base_recibida
            FROM recepciones_compra rc
            INNER JOIN recepciones_compra_detalle rcd
                ON rcd.recepcion_id = rc.id
            WHERE rc.estado = 'CONFIRMADA'
            GROUP BY rc.compra_id
         ) r
            ON r.compra_id = c.id
         LEFT JOIN (
            SELECT
                compra_id,
                SUM(cantidad_base) AS cantidad_base_comprada
            FROM compras_detalle
            GROUP BY compra_id
         ) d
            ON d.compra_id = c.id
         WHERE {$whereSql}
         ORDER BY c.fecha_compra DESC, c.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cmp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['proveedor_id'] = (int) $f['proveedor_id'];
        $f['total'] = (float) $f['total'];
        $f['recepciones_confirmadas'] = (int) $f['recepciones_confirmadas'];
        $f['cantidad_base_recibida'] = (float) $f['cantidad_base_recibida'];
        $f['cantidad_base_comprada'] = (float) $f['cantidad_base_comprada'];
    }
    unset($f);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'BORRADOR') AS borradores,
            SUM(estado = 'PENDIENTE_RECEPCION') AS pendientes,
            SUM(estado = 'RECIBIDA_PARCIAL') AS parciales,
            SUM(estado = 'RECIBIDA') AS recibidas
         FROM compras"
    )->fetch();

    si_responder_json(
        true,
        'Compras cargadas.',
        [
            'compras' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => [
                'total' => (int) ($resumen['total'] ?? 0),
                'borradores' => (int) ($resumen['borradores'] ?? 0),
                'pendientes' => (int) ($resumen['pendientes'] ?? 0),
                'parciales' => (int) ($resumen['parciales'] ?? 0),
                'recibidas' => (int) ($resumen['recibidas'] ?? 0),
            ],
        ]
    );
}

function cmp_detalle_compra(PDO $conexion): void
{
    $id = cmp_id($_GET['id'] ?? null, 'compra');

    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor_actual,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            u.usuario AS creado_por_usuario
         FROM compras c
         INNER JOIN proveedores p
            ON p.id = c.proveedor_id
         INNER JOIN monedas m
            ON m.id = c.moneda_id
         INNER JOIN usuarios u
            ON u.id = c.created_by
         WHERE c.id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $compra = $stmt->fetch();

    if (!$compra) {
        si_responder_json(false, 'No se encontró la compra.', [], 404);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            cd.id,
            cd.renglon,
            cd.producto_id,
            cd.presentacion_id,
            cd.producto_nombre_snapshot,
            cd.sku_snapshot,
            cd.unidad_id,
            cd.unidad_nombre_snapshot,
            u.simbolo AS unidad_simbolo,
            cd.cantidad,
            cd.factor_a_unidad_base,
            cd.cantidad_base,
            cd.precio_unitario,
            cd.descuento_pct,
            cd.descuento_importe,
            cd.tasa_impuesto_id,
            cd.impuesto_pct_snapshot,
            cd.subtotal,
            cd.impuesto_importe,
            cd.total,
            COALESCE(rec.cantidad_recibida, 0) AS cantidad_recibida,
            GREATEST(cd.cantidad - COALESCE(rec.cantidad_recibida, 0), 0) AS cantidad_pendiente,
            pp.id AS relacion_id,
            COALESCE(pres.nombre, 'Unidad base') AS presentacion_nombre
         FROM compras_detalle cd
         INNER JOIN unidades_medida u
            ON u.id = cd.unidad_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = cd.presentacion_id
         LEFT JOIN proveedores_productos pp
            ON pp.proveedor_id = :proveedor_id
           AND pp.producto_id = cd.producto_id
           AND pp.presentacion_id <=> cd.presentacion_id
         LEFT JOIN (
            SELECT
                rcd.compra_detalle_id,
                SUM(rcd.cantidad_recibida) AS cantidad_recibida
            FROM recepciones_compra_detalle rcd
            INNER JOIN recepciones_compra rc
                ON rc.id = rcd.recepcion_id
            WHERE rc.estado = 'CONFIRMADA'
            GROUP BY rcd.compra_detalle_id
         ) rec
            ON rec.compra_detalle_id = cd.id
         WHERE cd.compra_id = :compra_id
         ORDER BY cd.renglon ASC"
    );

    $stmtDet->execute([
        ':proveedor_id' => (int) $compra['proveedor_id'],
        ':compra_id' => $id,
    ]);

    $detalles = $stmtDet->fetchAll();

    foreach ($detalles as &$d) {
        foreach (['id', 'renglon', 'producto_id', 'unidad_id'] as $campo) {
            $d[$campo] = (int) $d[$campo];
        }
        $d['presentacion_id'] = $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null;
        $d['tasa_impuesto_id'] = $d['tasa_impuesto_id'] !== null ? (int) $d['tasa_impuesto_id'] : null;
        $d['relacion_id'] = $d['relacion_id'] !== null ? (int) $d['relacion_id'] : null;
        foreach (['cantidad', 'factor_a_unidad_base', 'cantidad_base', 'precio_unitario', 'descuento_pct', 'descuento_importe', 'impuesto_pct_snapshot', 'subtotal', 'impuesto_importe', 'total', 'cantidad_recibida', 'cantidad_pendiente'] as $campo) {
            $d[$campo] = (float) $d[$campo];
        }
    }
    unset($d);

    $compra['id'] = (int) $compra['id'];
    $compra['proveedor_id'] = (int) $compra['proveedor_id'];
    $compra['moneda_id'] = (int) $compra['moneda_id'];
    $compra['dias_credito'] = (int) $compra['dias_credito'];
    foreach (['tipo_cambio_a_base', 'subtotal', 'descuento_total', 'impuesto_total', 'total'] as $campo) {
        $compra[$campo] = (float) $compra[$campo];
    }

    si_responder_json(true, 'Compra cargada.', ['compra' => $compra, 'detalles' => $detalles]);
}

function cmp_guardar_compra(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['compra_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cmp_id($idTexto, 'compra');
    $esNueva = $id === 0;

    $proveedorId = cmp_id($_POST['proveedor_id'] ?? null, 'proveedor');
    $fechaCompra = cmp_fecha_hora($_POST['fecha_compra'] ?? null, 'fecha de compra');
    $fechaFactura = cmp_fecha_opcional($_POST['fecha_factura'] ?? '');
    $numeroFactura = cmp_nullable($_POST['numero_factura'] ?? '', 80);
    $monedaId = cmp_id($_POST['moneda_id'] ?? null, 'moneda');
    $condicion = strtoupper(trim((string) ($_POST['condicion_pago'] ?? 'CONTADO')));

    if (!in_array($condicion, ['CONTADO', 'CREDITO'], true)) {
        si_responder_json(false, 'Selecciona una condición de pago válida.', ['campo' => 'condicion_pago'], 422);
    }

    $observaciones = cmp_nullable($_POST['observaciones'] ?? '', 10000);

    $detallesRaw = json_decode((string) ($_POST['detalles'] ?? '[]'), true);

    if (!is_array($detallesRaw) || count($detallesRaw) < 1) {
        si_responder_json(false, 'Agrega al menos un producto a la compra.', ['campo' => 'detalles'], 422);
    }

    if (count($detallesRaw) > 200) {
        si_responder_json(false, 'Una compra no puede contener más de 200 renglones.', ['campo' => 'detalles'], 422);
    }

    $conexion->beginTransaction();

    $proveedor = cmp_bloquear_proveedor($conexion, $proveedorId);

    if (!$proveedor || (int) $proveedor['activo'] !== 1) {
        cmp_cancelar($conexion, 'Selecciona un proveedor activo.', 409);
    }

    $moneda = cmp_moneda($conexion, $monedaId, true);

    if (!$moneda) {
        cmp_cancelar($conexion, 'La moneda seleccionada no está disponible.', 409);
    }

    $base = cmp_moneda_base($conexion);

    if (!$base) {
        cmp_cancelar($conexion, 'No está configurada la moneda base del sistema.', 500);
    }

    if ($monedaId === (int) $base['id']) {
        $tipoCambio = 1.0;
    } else {
        $tipoCambioTexto = trim((string) ($_POST['tipo_cambio_a_base'] ?? ''));

        if ($tipoCambioTexto !== '') {
            $tipoCambio = cmp_decimal_positivo($tipoCambioTexto, 'El tipo de cambio debe ser mayor que cero.');
        } else {
            $tipo = cmp_buscar_tipo_cambio(
                $conexion,
                $monedaId,
                (int) $base['id'],
                substr($fechaCompra, 0, 10)
            );

            if ($tipo === null) {
                cmp_cancelar(
                    $conexion,
                    'No hay tipo de cambio registrado para la moneda seleccionada. Captúralo antes de guardar.',
                    422,
                    ['campo' => 'tipo_cambio_a_base']
                );
            }

            $tipoCambio = (float) $tipo['tipo_cambio'];
        }
    }

    if ($condicion === 'CONTADO') {
        $diasCredito = 0;
        $fechaVencimiento = null;
    } else {
        $diasTexto = trim((string) ($_POST['dias_credito'] ?? ''));

        if ($diasTexto === '') {
            $diasCredito = max(1, (int) $proveedor['dias_credito']);
        } else {
            $diasCredito = cmp_entero_rango($diasTexto, 1, 3650, -1);
        }

        if ($diasCredito < 1) {
            cmp_cancelar($conexion, 'Para una compra a crédito indica al menos 1 día de crédito.', 422, ['campo' => 'dias_credito']);
        }

        $vencimientoTexto = trim((string) ($_POST['fecha_vencimiento'] ?? ''));

        if ($vencimientoTexto !== '') {
            $fechaVencimiento = cmp_fecha($vencimientoTexto, 'fecha de vencimiento');
        } else {
            $baseFecha = $fechaFactura ?: substr($fechaCompra, 0, 10);
            $fechaVencimiento = (new DateTimeImmutable($baseFecha))
                ->modify('+' . $diasCredito . ' days')
                ->format('Y-m-d');
        }
    }

    if ($condicion === 'CREDITO') {
        $fechaBaseCredito = $fechaFactura ?: substr($fechaCompra, 0, 10);
        if ($fechaVencimiento < $fechaBaseCredito) {
            cmp_cancelar(
                $conexion,
                'La fecha de vencimiento no puede ser anterior a la fecha base de la compra/factura.',
                422,
                ['campo' => 'fecha_vencimiento']
            );
        }
    }

    $anterior = null;
    $folio = '';

    if (!$esNueva) {
        $anterior = cmp_bloquear_compra($conexion, $id);

        if (!$anterior) {
            cmp_cancelar($conexion, 'La compra ya no existe.', 404);
        }

        if ($anterior['estado'] !== 'BORRADOR') {
            cmp_cancelar($conexion, 'Solo las compras en borrador pueden modificarse.', 409);
        }

        $folio = (string) $anterior['folio'];
    }

    $detalles = [];
    $subtotal = 0.0;
    $descuentoTotal = 0.0;
    $impuestoTotal = 0.0;
    $total = 0.0;
    $relacionesUsadas = [];

    foreach ($detallesRaw as $indice => $entrada) {
        if (!is_array($entrada)) {
            cmp_cancelar($conexion, 'Hay un renglón de compra inválido.', 422);
        }

        $relacionId = cmp_id($entrada['relacion_id'] ?? null, 'relación proveedor-producto');

        if (isset($relacionesUsadas[$relacionId])) {
            cmp_cancelar(
                $conexion,
                'La misma presentación de producto está repetida en la compra. Ajusta la cantidad en un solo renglón.',
                422
            );
        }
        $relacionesUsadas[$relacionId] = true;

        $relacion = cmp_bloquear_relacion_compra($conexion, $relacionId, $proveedorId);

        if (!$relacion) {
            cmp_cancelar(
                $conexion,
                'Uno de los productos ya no está disponible para este proveedor.',
                409
            );
        }

        $cantidad = cmp_decimal_positivo(
            $entrada['cantidad'] ?? null,
            'La cantidad debe ser mayor que cero.'
        );

        if (
            $relacion['compra_minima'] !== null
            && $cantidad + 0.0000001 < (float) $relacion['compra_minima']
        ) {
            cmp_cancelar(
                $conexion,
                'La cantidad de ' . $relacion['producto'] . ' es menor a la compra mínima configurada ('
                . cmp_numero((float) $relacion['compra_minima'], 6) . ').',
                422
            );
        }

        $precio = cmp_decimal_positivo(
            $entrada['precio_unitario'] ?? null,
            'El precio unitario debe ser mayor que cero.'
        );

        $descuentoPct = cmp_decimal_rango(
            $entrada['descuento_pct'] ?? 0,
            0,
            100,
            'El descuento debe estar entre 0 y 100.'
        );

        $factor = (float) $relacion['factor_a_unidad_base'];

        if ($factor <= 0) {
            cmp_cancelar($conexion, 'La presentación de ' . $relacion['producto'] . ' tiene una conversión inválida.', 409);
        }

        $cantidadBase = cmp_round6($cantidad * $factor);
        $bruto = cmp_round4($cantidad * $precio);
        $descuentoImporte = cmp_round4($bruto * ($descuentoPct / 100));
        $subtotalLinea = cmp_round4($bruto - $descuentoImporte);
        $impuestoPct = (float) $relacion['impuesto_pct'];
        $impuestoImporte = cmp_round4($subtotalLinea * ($impuestoPct / 100));
        $totalLinea = cmp_round4($subtotalLinea + $impuestoImporte);

        $subtotal += $bruto;
        $descuentoTotal += $descuentoImporte;
        $impuestoTotal += $impuestoImporte;
        $total += $totalLinea;

        $detalles[] = [
            'relacion_id' => $relacionId,
            'renglon' => $indice + 1,
            'producto_id' => (int) $relacion['producto_id'],
            'presentacion_id' => $relacion['presentacion_id'] !== null ? (int) $relacion['presentacion_id'] : null,
            'producto_nombre_snapshot' => $relacion['producto'],
            'sku_snapshot' => $relacion['sku'],
            'unidad_id' => (int) $relacion['unidad_id'],
            'unidad_nombre_snapshot' => $relacion['unidad_nombre'],
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $factor,
            'cantidad_base' => $cantidadBase,
            'precio_unitario' => $precio,
            'descuento_pct' => $descuentoPct,
            'descuento_importe' => $descuentoImporte,
            'tasa_impuesto_id' => $relacion['tasa_impuesto_id'] !== null ? (int) $relacion['tasa_impuesto_id'] : null,
            'impuesto_pct_snapshot' => $impuestoPct,
            'subtotal' => $subtotalLinea,
            'impuesto_importe' => $impuestoImporte,
            'total' => $totalLinea,
        ];
    }

    $subtotal = cmp_round4($subtotal);
    $descuentoTotal = cmp_round4($descuentoTotal);
    $impuestoTotal = cmp_round4($impuestoTotal);
    $total = cmp_round4($total);

    if ($esNueva) {
        $folioTemporal = 'TMP-COMP-' . bin2hex(random_bytes(10));

        $stmt = $conexion->prepare(
            "INSERT INTO compras
                (
                    folio,
                    proveedor_id,
                    proveedor_nombre_snapshot,
                    proveedor_rfc_snapshot,
                    fecha_compra,
                    fecha_factura,
                    numero_factura,
                    moneda_id,
                    tipo_cambio_a_base,
                    condicion_pago,
                    dias_credito,
                    fecha_vencimiento,
                    estado,
                    subtotal,
                    descuento_total,
                    impuesto_total,
                    total,
                    observaciones,
                    created_by
                )
             VALUES
                (
                    :folio,
                    :proveedor_id,
                    :proveedor_nombre,
                    :proveedor_rfc,
                    :fecha_compra,
                    :fecha_factura,
                    :numero_factura,
                    :moneda_id,
                    :tipo_cambio,
                    :condicion_pago,
                    :dias_credito,
                    :fecha_vencimiento,
                    'BORRADOR',
                    :subtotal,
                    :descuento_total,
                    :impuesto_total,
                    :total,
                    :observaciones,
                    :created_by
                )"
        );

        $stmt->execute([
            ':folio' => $folioTemporal,
            ':proveedor_id' => $proveedorId,
            ':proveedor_nombre' => $proveedor['razon_social'],
            ':proveedor_rfc' => $proveedor['rfc'],
            ':fecha_compra' => $fechaCompra,
            ':fecha_factura' => $fechaFactura,
            ':numero_factura' => $numeroFactura,
            ':moneda_id' => $monedaId,
            ':tipo_cambio' => $tipoCambio,
            ':condicion_pago' => $condicion,
            ':dias_credito' => $diasCredito,
            ':fecha_vencimiento' => $fechaVencimiento,
            ':subtotal' => $subtotal,
            ':descuento_total' => $descuentoTotal,
            ':impuesto_total' => $impuestoTotal,
            ':total' => $total,
            ':observaciones' => $observaciones,
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);

        $id = (int) $conexion->lastInsertId();
        $folio = 'COM-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);

        $conexion->prepare(
            "UPDATE compras SET folio = :folio WHERE id = :id"
        )->execute([
            ':folio' => $folio,
            ':id' => $id,
        ]);

    } else {
        $stmt = $conexion->prepare(
            "UPDATE compras
             SET
                proveedor_id = :proveedor_id,
                proveedor_nombre_snapshot = :proveedor_nombre,
                proveedor_rfc_snapshot = :proveedor_rfc,
                fecha_compra = :fecha_compra,
                fecha_factura = :fecha_factura,
                numero_factura = :numero_factura,
                moneda_id = :moneda_id,
                tipo_cambio_a_base = :tipo_cambio,
                condicion_pago = :condicion_pago,
                dias_credito = :dias_credito,
                fecha_vencimiento = :fecha_vencimiento,
                subtotal = :subtotal,
                descuento_total = :descuento_total,
                impuesto_total = :impuesto_total,
                total = :total,
                observaciones = :observaciones
             WHERE id = :id
               AND estado = 'BORRADOR'"
        );

        $stmt->execute([
            ':proveedor_id' => $proveedorId,
            ':proveedor_nombre' => $proveedor['razon_social'],
            ':proveedor_rfc' => $proveedor['rfc'],
            ':fecha_compra' => $fechaCompra,
            ':fecha_factura' => $fechaFactura,
            ':numero_factura' => $numeroFactura,
            ':moneda_id' => $monedaId,
            ':tipo_cambio' => $tipoCambio,
            ':condicion_pago' => $condicion,
            ':dias_credito' => $diasCredito,
            ':fecha_vencimiento' => $fechaVencimiento,
            ':subtotal' => $subtotal,
            ':descuento_total' => $descuentoTotal,
            ':impuesto_total' => $impuestoTotal,
            ':total' => $total,
            ':observaciones' => $observaciones,
            ':id' => $id,
        ]);

        $conexion->prepare(
            "DELETE FROM compras_detalle WHERE compra_id = :compra_id"
        )->execute([':compra_id' => $id]);
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO compras_detalle
            (
                compra_id,
                renglon,
                producto_id,
                presentacion_id,
                producto_nombre_snapshot,
                sku_snapshot,
                unidad_id,
                unidad_nombre_snapshot,
                cantidad,
                factor_a_unidad_base,
                cantidad_base,
                precio_unitario,
                descuento_pct,
                descuento_importe,
                tasa_impuesto_id,
                impuesto_pct_snapshot,
                subtotal,
                impuesto_importe,
                total
            )
         VALUES
            (
                :compra_id,
                :renglon,
                :producto_id,
                :presentacion_id,
                :producto_nombre,
                :sku,
                :unidad_id,
                :unidad_nombre,
                :cantidad,
                :factor,
                :cantidad_base,
                :precio_unitario,
                :descuento_pct,
                :descuento_importe,
                :tasa_impuesto_id,
                :impuesto_pct,
                :subtotal,
                :impuesto_importe,
                :total
            )"
    );

    foreach ($detalles as $d) {
        $stmtDet->execute([
            ':compra_id' => $id,
            ':renglon' => $d['renglon'],
            ':producto_id' => $d['producto_id'],
            ':presentacion_id' => $d['presentacion_id'],
            ':producto_nombre' => $d['producto_nombre_snapshot'],
            ':sku' => $d['sku_snapshot'],
            ':unidad_id' => $d['unidad_id'],
            ':unidad_nombre' => $d['unidad_nombre_snapshot'],
            ':cantidad' => $d['cantidad'],
            ':factor' => $d['factor_a_unidad_base'],
            ':cantidad_base' => $d['cantidad_base'],
            ':precio_unitario' => $d['precio_unitario'],
            ':descuento_pct' => $d['descuento_pct'],
            ':descuento_importe' => $d['descuento_importe'],
            ':tasa_impuesto_id' => $d['tasa_impuesto_id'],
            ':impuesto_pct' => $d['impuesto_pct_snapshot'],
            ':subtotal' => $d['subtotal'],
            ':impuesto_importe' => $d['impuesto_importe'],
            ':total' => $d['total'],
        ]);
    }

    cmp_auditar(
        $conexion,
        $esNueva ? 'COMPRA_CREADA' : 'COMPRA_EDITADA',
        'compras',
        $id,
        $esNueva ? 'Se creó una compra en borrador.' : 'Se actualizó una compra en borrador.',
        $anterior ? cmp_compra_auditoria($anterior) : null,
        [
            'folio' => $folio,
            'proveedor_id' => $proveedorId,
            'moneda_id' => $monedaId,
            'condicion_pago' => $condicion,
            'total' => $total,
            'renglones' => count($detalles),
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNueva ? 'Compra guardada como borrador.' : 'Borrador actualizado correctamente.',
        ['compra_id' => $id, 'folio' => $folio],
        $esNueva ? 201 : 200
    );
}

function cmp_confirmar_compra(PDO $conexion): void
{
    $id = cmp_id($_POST['compra_id'] ?? null, 'compra');

    $conexion->beginTransaction();

    $compra = cmp_bloquear_compra($conexion, $id);

    if (!$compra) {
        cmp_cancelar($conexion, 'La compra ya no existe.', 404);
    }

    if ($compra['estado'] !== 'BORRADOR') {
        cmp_cancelar($conexion, 'Solo una compra en borrador puede confirmarse.', 409);
    }

    $proveedor = cmp_bloquear_proveedor($conexion, (int) $compra['proveedor_id']);

    if (!$proveedor || (int) $proveedor['activo'] !== 1) {
        cmp_cancelar($conexion, 'El proveedor está inactivo. No se puede confirmar la compra.', 409);
    }

    $stmtDet = $conexion->prepare(
        "SELECT *
         FROM compras_detalle
         WHERE compra_id = :compra_id
         ORDER BY renglon ASC
         FOR UPDATE"
    );
    $stmtDet->execute([':compra_id' => $id]);
    $detalles = $stmtDet->fetchAll();

    if (!$detalles) {
        cmp_cancelar($conexion, 'La compra no contiene productos.', 409);
    }

    $conexion->prepare(
        "UPDATE compras
         SET estado = 'PENDIENTE_RECEPCION'
         WHERE id = :id"
    )->execute([':id' => $id]);

    /*
     * El precio histórico se registra al confirmar la compra, no al guardar
     * borradores. Así el comparador no usa negociaciones que nunca se cerraron.
     */
    $stmtRelacion = $conexion->prepare(
        "SELECT id
         FROM proveedores_productos
         WHERE proveedor_id = :proveedor_id
           AND producto_id = :producto_id
           AND presentacion_id <=> :presentacion_id
           AND activo = 1
         LIMIT 1"
    );

    $stmtPrecio = $conexion->prepare(
        "INSERT INTO historial_precios_proveedor
            (
                proveedor_producto_id,
                fecha_precio,
                unidad_id,
                cantidad_referencia,
                precio_unitario,
                moneda_id,
                tipo_cambio_a_base,
                factor_a_unidad_base,
                precio_normalizado_base,
                vigencia_hasta,
                fuente,
                referencia,
                activo,
                created_by
            )
         VALUES
            (
                :relacion_id,
                :fecha_precio,
                :unidad_id,
                1,
                :precio_unitario,
                :moneda_id,
                :tipo_cambio,
                :factor,
                :precio_normalizado,
                NULL,
                'COMPRA',
                :referencia,
                1,
                :created_by
            )"
    );

    foreach ($detalles as $d) {
        $stmtRelacion->execute([
            ':proveedor_id' => (int) $compra['proveedor_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':presentacion_id' => $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null,
        ]);

        $relacionId = $stmtRelacion->fetchColumn();

        if ($relacionId === false) {
            cmp_cancelar(
                $conexion,
                'Una relación proveedor-producto fue desactivada antes de confirmar. Revisa el borrador.',
                409
            );
        }

        $precioNetoUnitario = (float) $d['precio_unitario'] * (1 - ((float) $d['descuento_pct'] / 100));
        $precioNormalizado = ($precioNetoUnitario * (float) $compra['tipo_cambio_a_base']) / (float) $d['factor_a_unidad_base'];

        $stmtPrecio->execute([
            ':relacion_id' => (int) $relacionId,
            ':fecha_precio' => $compra['fecha_compra'],
            ':unidad_id' => (int) $d['unidad_id'],
            ':precio_unitario' => cmp_round4($precioNetoUnitario),
            ':moneda_id' => (int) $compra['moneda_id'],
            ':tipo_cambio' => (float) $compra['tipo_cambio_a_base'],
            ':factor' => (float) $d['factor_a_unidad_base'],
            ':precio_normalizado' => cmp_round6($precioNormalizado),
            ':referencia' => (string) $compra['folio'],
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);
    }

    if ($compra['condicion_pago'] === 'CREDITO') {
        cmp_generar_cuenta_por_pagar($conexion, $compra);
    }

    cmp_auditar(
        $conexion,
        'COMPRA_CONFIRMADA',
        'compras',
        $id,
        'La compra fue confirmada y quedó pendiente de recepción física.',
        ['estado' => 'BORRADOR'],
        ['estado' => 'PENDIENTE_RECEPCION']
    );

    $conexion->commit();

    si_responder_json(
        true,
        $compra['condicion_pago'] === 'CREDITO'
            ? 'Compra confirmada. Quedó pendiente de recepción y se generó su cuenta por pagar.'
            : 'Compra confirmada. Quedó pendiente de recepción física.'
    );
}

function cmp_cancelar_compra(PDO $conexion): void
{
    $id = cmp_id($_POST['compra_id'] ?? null, 'compra');
    $motivo = cmp_requerido(
        $_POST['motivo'] ?? '',
        'Indica el motivo de cancelación.',
        10000
    );

    $conexion->beginTransaction();

    $compra = cmp_bloquear_compra($conexion, $id);

    if (!$compra) {
        cmp_cancelar($conexion, 'La compra ya no existe.', 404);
    }

    if ($compra['estado'] === 'CANCELADA') {
        $conexion->commit();
        si_responder_json(true, 'La compra ya estaba cancelada.');
    }

    $stmtRec = $conexion->prepare(
        "SELECT COUNT(*)
         FROM recepciones_compra
         WHERE compra_id = :compra_id
           AND estado = 'CONFIRMADA'"
    );
    $stmtRec->execute([':compra_id' => $id]);

    if ((int) $stmtRec->fetchColumn() > 0) {
        cmp_cancelar(
            $conexion,
            'La compra tiene recepciones confirmadas. Primero cancela esas recepciones para revertir el inventario y conservar el Kardex.',
            409
        );
    }

    $stmtCuenta = $conexion->prepare(
        "SELECT id, importe_pagado, estado
         FROM cuentas_por_pagar
         WHERE compra_id = :compra_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtCuenta->execute([':compra_id' => $id]);
    $cuenta = $stmtCuenta->fetch();

    if ($cuenta) {
        $stmtPagos = $conexion->prepare(
            "SELECT COALESCE(SUM(app.importe_aplicado), 0)
             FROM aplicaciones_pago_proveedor app
             INNER JOIN pagos_proveedor pp
                ON pp.id = app.pago_proveedor_id
             WHERE app.cuenta_por_pagar_id = :cuenta_id
               AND pp.estado = 'APLICADO'"
        );
        $stmtPagos->execute([':cuenta_id' => (int) $cuenta['id']]);
        $pagosAplicados = (float) $stmtPagos->fetchColumn();

        if ((float) $cuenta['importe_pagado'] > 0.0001 || $pagosAplicados > 0.0001) {
            cmp_cancelar(
                $conexion,
                'La cuenta por pagar de esta compra ya tiene abonos. Cancela o revierte esos pagos antes de cancelar la compra.',
                409
            );
        }
    }

    if ($cuenta) {
        $conexion->prepare(
            "UPDATE cuentas_por_pagar
             SET estado = 'CANCELADA'
             WHERE id = :id"
        )->execute([':id' => (int) $cuenta['id']]);
    }

    $conexion->prepare(
        "UPDATE historial_precios_proveedor
         SET activo = 0
         WHERE fuente = 'COMPRA'
           AND referencia = :folio
           AND activo = 1"
    )->execute([':folio' => $compra['folio']]);

    $conexion->prepare(
        "UPDATE recepciones_compra
         SET
            estado = 'CANCELADA',
            motivo_cancelacion = 'Compra cancelada antes de confirmar la recepción.',
            cancelada_at = NOW(),
            cancelada_by = :usuario_id
         WHERE compra_id = :compra_id
           AND estado = 'BORRADOR'"
    )->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':compra_id' => $id,
    ]);

    $conexion->prepare(
        "UPDATE compras
         SET
            estado = 'CANCELADA',
            motivo_cancelacion = :motivo,
            cancelada_at = NOW(),
            cancelada_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    cmp_auditar(
        $conexion,
        'COMPRA_CANCELADA',
        'compras',
        $id,
        'Se canceló una compra sin recepciones físicas vigentes.',
        ['estado' => $compra['estado']],
        ['estado' => 'CANCELADA', 'motivo' => $motivo]
    );

    $conexion->commit();

    si_responder_json(true, 'Compra cancelada correctamente.');
}

/* =========================================================================
   RECEPCIONES E INVENTARIO
   ========================================================================= */

function cmp_buscar_compras_pendientes(PDO $conexion): void
{
    $q = cmp_texto($_GET['q'] ?? '', 180);

    $where = "c.estado IN ('PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL')";
    $params = [];

    if ($q !== '') {
        $where .= " AND (
            c.folio = :exacto
            OR c.folio LIKE :prefijo
            OR c.proveedor_nombre_snapshot LIKE :proveedor
            OR c.numero_factura LIKE :factura
        )";
        $params = [
            ':exacto' => strtoupper($q),
            ':prefijo' => strtoupper($q) . '%',
            ':proveedor' => '%' . $q . '%',
            ':factura' => '%' . $q . '%',
        ];
    }

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.fecha_compra,
            c.proveedor_nombre_snapshot AS proveedor,
            c.numero_factura,
            c.estado,
            m.codigo AS moneda,
            c.total
         FROM compras c
         INNER JOIN monedas m
            ON m.id = c.moneda_id
         WHERE {$where}
         ORDER BY c.fecha_compra DESC, c.id DESC
         LIMIT 20"
    );

    cmp_bind($stmt, $params);
    $stmt->execute();

    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['total'] = (float) $f['total'];
    }
    unset($f);

    si_responder_json(true, 'Compras encontradas.', ['compras' => $filas]);
}

function cmp_listar_recepciones(PDO $conexion): void
{
    $pagina = cmp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cmp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cmp_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cmp_texto($_GET['estado'] ?? 'TODOS', 20));
    $desde = cmp_fecha_opcional($_GET['desde'] ?? '');
    $hasta = cmp_fecha_opcional($_GET['hasta'] ?? '');

    if (!in_array($estado, ['TODOS', 'BORRADOR', 'CONFIRMADA', 'CANCELADA'], true)) {
        $estado = 'TODOS';
    }

    $where = ['1=1'];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            r.folio = :folio_exacto
            OR r.folio LIKE :folio_prefijo
            OR c.folio LIKE :compra
            OR c.proveedor_nombre_snapshot LIKE :proveedor
            OR r.documento_recepcion LIKE :documento
        )";
        $params[':folio_exacto'] = strtoupper($q);
        $params[':folio_prefijo'] = strtoupper($q) . '%';
        $params[':compra'] = strtoupper($q) . '%';
        $params[':proveedor'] = '%' . $q . '%';
        $params[':documento'] = '%' . $q . '%';
    }

    if ($estado !== 'TODOS') {
        $where[] = 'r.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($desde !== null) {
        $where[] = 'r.fecha_recepcion >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta !== null) {
        $where[] = 'r.fecha_recepcion < DATE_ADD(:hasta, INTERVAL 1 DAY)';
        $params[':hasta'] = $hasta . ' 00:00:00';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM recepciones_compra r
         INNER JOIN compras c
            ON c.id = r.compra_id
         WHERE {$whereSql}"
    );
    cmp_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            r.id,
            r.folio,
            r.compra_id,
            c.folio AS compra_folio,
            c.proveedor_nombre_snapshot AS proveedor,
            r.fecha_recepcion,
            r.estado,
            r.documento_recepcion,
            COUNT(rd.id) AS renglones,
            COALESCE(SUM(rd.cantidad_base), 0) AS cantidad_base
         FROM recepciones_compra r
         INNER JOIN compras c
            ON c.id = r.compra_id
         LEFT JOIN recepciones_compra_detalle rd
            ON rd.recepcion_id = r.id
         WHERE {$whereSql}
         GROUP BY
            r.id,
            r.folio,
            r.compra_id,
            c.folio,
            c.proveedor_nombre_snapshot,
            r.fecha_recepcion,
            r.estado,
            r.documento_recepcion
         ORDER BY r.fecha_recepcion DESC, r.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cmp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['compra_id'] = (int) $f['compra_id'];
        $f['renglones'] = (int) $f['renglones'];
        $f['cantidad_base'] = (float) $f['cantidad_base'];
    }
    unset($f);

    si_responder_json(
        true,
        'Recepciones cargadas.',
        [
            'recepciones' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cmp_preparar_recepcion(PDO $conexion): void
{
    $compraId = cmp_id($_GET['compra_id'] ?? null, 'compra');

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.proveedor_nombre_snapshot AS proveedor,
            c.fecha_compra,
            c.fecha_factura,
            c.numero_factura,
            c.estado,
            m.codigo AS moneda
         FROM compras c
         INNER JOIN monedas m
            ON m.id = c.moneda_id
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $compraId]);
    $compra = $stmt->fetch();

    if (!$compra) {
        si_responder_json(false, 'No se encontró la compra.', [], 404);
    }

    if (!in_array($compra['estado'], ['PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL'], true)) {
        si_responder_json(false, 'Esta compra no tiene mercancía pendiente de recepción.', [], 409);
    }

    $lineas = cmp_lineas_pendientes_recepcion($conexion, $compraId, 0);

    if (!$lineas) {
        si_responder_json(false, 'La compra ya no tiene cantidades pendientes.', [], 409);
    }

    $almacenes = cmp_almacenes_activos($conexion);

    if (!$almacenes) {
        si_responder_json(false, 'No existe ningún almacén activo para recibir mercancía.', [], 409);
    }

    $compra['id'] = (int) $compra['id'];

    si_responder_json(
        true,
        'Compra preparada para recepción.',
        [
            'compra' => $compra,
            'detalles' => $lineas,
            'almacenes' => $almacenes,
        ]
    );
}

function cmp_detalle_recepcion(PDO $conexion): void
{
    $id = cmp_id($_GET['id'] ?? null, 'recepción');

    $stmt = $conexion->prepare(
        "SELECT
            r.*,
            c.folio AS compra_folio,
            c.proveedor_nombre_snapshot AS proveedor,
            c.numero_factura AS compra_numero_factura,
            c.fecha_factura AS compra_fecha_factura,
            c.estado AS compra_estado
         FROM recepciones_compra r
         INNER JOIN compras c
            ON c.id = r.compra_id
         WHERE r.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();

    if (!$r) {
        si_responder_json(false, 'No se encontró la recepción.', [], 404);
    }

    if ($r['estado'] === 'BORRADOR') {
        /*
         * Para editar un borrador mostramos TODOS los renglones de la compra.
         * Así es posible agregar o quitar productos del borrador sin perder
         * el control de lo ya recibido por otras recepciones confirmadas.
         */
        $stmtDet = $conexion->prepare(
            "SELECT
                COALESCE(rd.id, 0) AS id,
                cd.id AS compra_detalle_id,
                rd.almacen_id,
                a.nombre AS almacen,
                cd.producto_id,
                cd.producto_nombre_snapshot AS producto,
                cd.sku_snapshot AS sku,
                cd.unidad_nombre_snapshot AS unidad,
                u.simbolo AS unidad_simbolo,
                cd.cantidad AS cantidad_comprada,
                cd.factor_a_unidad_base,
                COALESCE(rd.cantidad_recibida, 0) AS cantidad_recibida,
                COALESCE(rd.cantidad_base, 0) AS cantidad_base,
                COALESCE(otros.cantidad_recibida, 0) AS cantidad_recibida_otros,
                GREATEST(cd.cantidad - COALESCE(otros.cantidad_recibida, 0), 0) AS cantidad_pendiente_max,
                rd.observaciones
             FROM compras_detalle cd
             INNER JOIN unidades_medida u
                ON u.id = cd.unidad_id
             LEFT JOIN recepciones_compra_detalle rd
                ON rd.compra_detalle_id = cd.id
               AND rd.recepcion_id = :recepcion_actual
             LEFT JOIN almacenes a
                ON a.id = rd.almacen_id
             LEFT JOIN (
                SELECT
                    rcd2.compra_detalle_id,
                    SUM(rcd2.cantidad_recibida) AS cantidad_recibida
                FROM recepciones_compra_detalle rcd2
                INNER JOIN recepciones_compra rc2
                    ON rc2.id = rcd2.recepcion_id
                WHERE rc2.estado = 'CONFIRMADA'
                  AND rc2.id <> :recepcion_excluir
                GROUP BY rcd2.compra_detalle_id
             ) otros
                ON otros.compra_detalle_id = cd.id
             WHERE cd.compra_id = :compra_id
               AND cd.cantidad - COALESCE(otros.cantidad_recibida, 0) > 0.0000001
             ORDER BY cd.renglon ASC"
        );

        $stmtDet->execute([
            ':recepcion_actual' => $id,
            ':recepcion_excluir' => $id,
            ':compra_id' => (int) $r['compra_id'],
        ]);
    } else {
        $stmtDet = $conexion->prepare(
            "SELECT
                rd.id,
                rd.compra_detalle_id,
                rd.almacen_id,
                a.nombre AS almacen,
                rd.producto_id,
                cd.producto_nombre_snapshot AS producto,
                cd.sku_snapshot AS sku,
                cd.unidad_nombre_snapshot AS unidad,
                u.simbolo AS unidad_simbolo,
                cd.cantidad AS cantidad_comprada,
                cd.factor_a_unidad_base,
                rd.cantidad_recibida,
                rd.cantidad_base,
                0 AS cantidad_recibida_otros,
                rd.cantidad_recibida AS cantidad_pendiente_max,
                rd.observaciones
             FROM recepciones_compra_detalle rd
             INNER JOIN compras_detalle cd
                ON cd.id = rd.compra_detalle_id
             INNER JOIN almacenes a
                ON a.id = rd.almacen_id
             INNER JOIN unidades_medida u
                ON u.id = cd.unidad_id
             WHERE rd.recepcion_id = :recepcion_id
             ORDER BY cd.renglon ASC"
        );

        $stmtDet->execute([':recepcion_id' => $id]);
    }

    $detalles = $stmtDet->fetchAll();

    foreach ($detalles as &$d) {
        foreach (['id', 'compra_detalle_id', 'producto_id'] as $campo) {
            $d[$campo] = (int) $d[$campo];
        }
        $d['almacen_id'] = $d['almacen_id'] !== null ? (int) $d['almacen_id'] : null;
        foreach (['cantidad_comprada', 'factor_a_unidad_base', 'cantidad_recibida', 'cantidad_base', 'cantidad_recibida_otros', 'cantidad_pendiente_max'] as $campo) {
            $d[$campo] = (float) $d[$campo];
        }
    }
    unset($d);

    $r['id'] = (int) $r['id'];
    $r['compra_id'] = (int) $r['compra_id'];

    $almacenes = cmp_almacenes_activos($conexion);

    si_responder_json(
        true,
        'Recepción cargada.',
        ['recepcion' => $r, 'detalles' => $detalles, 'almacenes' => $almacenes]
    );
}

function cmp_guardar_recepcion(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['recepcion_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cmp_id($idTexto, 'recepción');
    $esNueva = $id === 0;

    $compraId = cmp_id($_POST['compra_id'] ?? null, 'compra');
    $fechaRecepcion = cmp_fecha_hora($_POST['fecha_recepcion'] ?? null, 'fecha de recepción');
    $documento = cmp_nullable($_POST['documento_recepcion'] ?? '', 100);
    $numeroFacturaCompra = cmp_nullable($_POST['numero_factura_compra'] ?? '', 80);
    $fechaFacturaCompra = cmp_fecha_opcional($_POST['fecha_factura_compra'] ?? '');
    $observaciones = cmp_nullable($_POST['observaciones'] ?? '', 10000);

    if ($fechaFacturaCompra !== null && $numeroFacturaCompra === null) {
        si_responder_json(
            false,
            'Si capturas la fecha de la factura/documento también debes indicar su número o referencia.',
            ['campo' => 'numero_factura_compra'],
            422
        );
    }

    $detallesRaw = json_decode((string) ($_POST['detalles'] ?? '[]'), true);

    if (!is_array($detallesRaw)) {
        si_responder_json(false, 'El detalle de recepción no es válido.', [], 422);
    }

    $conexion->beginTransaction();

    $compra = cmp_bloquear_compra($conexion, $compraId);

    if (!$compra) {
        cmp_cancelar($conexion, 'La compra ya no existe.', 404);
    }

    if (!in_array($compra['estado'], ['PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL'], true)) {
        cmp_cancelar($conexion, 'La compra no está disponible para recibir mercancía.', 409);
    }

    if ($fechaRecepcion < (string) $compra['fecha_compra']) {
        cmp_cancelar(
            $conexion,
            'La fecha de recepción no puede ser anterior a la fecha de compra.',
            422,
            ['campo' => 'fecha_recepcion']
        );
    }

    /*
     * La factura/documento es externo: lo asigna el proveedor.
     * Puede conocerse al crear la compra o hasta que llega la mercancía.
     * Por eso permitimos completar este dato desde la recepción sin alterar
     * productos, cantidades, precios ni movimientos de inventario.
     */
    $docAnterior = [
        'numero_factura' => $compra['numero_factura'] ?? null,
        'fecha_factura' => $compra['fecha_factura'] ?? null,
        'fecha_vencimiento' => $compra['fecha_vencimiento'] ?? null,
    ];

    $documentoCambio =
        ($numeroFacturaCompra !== ($compra['numero_factura'] ?? null))
        || ($fechaFacturaCompra !== ($compra['fecha_factura'] ?? null));

    if ($documentoCambio) {
        $nuevoVencimiento = $compra['fecha_vencimiento'];

        if (
            $compra['condicion_pago'] === 'CREDITO'
            && (int) $compra['dias_credito'] > 0
            && $fechaFacturaCompra !== null
        ) {
            $nuevoVencimiento = (new DateTimeImmutable($fechaFacturaCompra))
                ->modify('+' . (int) $compra['dias_credito'] . ' days')
                ->format('Y-m-d');
        }

        $conexion->prepare(
            "UPDATE compras
             SET
                numero_factura = :numero_factura,
                fecha_factura = :fecha_factura,
                fecha_vencimiento = :fecha_vencimiento
             WHERE id = :id"
        )->execute([
            ':numero_factura' => $numeroFacturaCompra,
            ':fecha_factura' => $fechaFacturaCompra,
            ':fecha_vencimiento' => $nuevoVencimiento,
            ':id' => $compraId,
        ]);

        /*
         * Si existe CxP y aún no tiene abonos, mantenemos sus fechas
         * sincronizadas con el documento real del proveedor.
         */
        if ($compra['condicion_pago'] === 'CREDITO' && $nuevoVencimiento !== null) {
            $fechaDocumentoCxP = $fechaFacturaCompra
                ?: substr((string) $compra['fecha_compra'], 0, 10);

            $conexion->prepare(
                "UPDATE cuentas_por_pagar
                 SET
                    fecha_documento = :fecha_documento,
                    fecha_vencimiento = :fecha_vencimiento,
                    estado = CASE
                        WHEN estado = 'CANCELADA' THEN 'CANCELADA'
                        WHEN importe_pagado > 0 THEN estado
                        WHEN :fecha_vencimiento_estado < CURDATE() THEN 'VENCIDA'
                        ELSE 'PENDIENTE'
                    END
                 WHERE compra_id = :compra_id
                   AND importe_pagado = 0"
            )->execute([
                ':fecha_documento' => $fechaDocumentoCxP,
                ':fecha_vencimiento' => $nuevoVencimiento,
                ':fecha_vencimiento_estado' => $nuevoVencimiento,
                ':compra_id' => $compraId,
            ]);
        }

        cmp_auditar(
            $conexion,
            'COMPRA_DOCUMENTO_ACTUALIZADO',
            'compras',
            $compraId,
            'Se actualizó la factura/documento externo del proveedor durante la recepción.',
            $docAnterior,
            [
                'numero_factura' => $numeroFacturaCompra,
                'fecha_factura' => $fechaFacturaCompra,
                'fecha_vencimiento' => $nuevoVencimiento,
            ]
        );

        $compra['numero_factura'] = $numeroFacturaCompra;
        $compra['fecha_factura'] = $fechaFacturaCompra;
        $compra['fecha_vencimiento'] = $nuevoVencimiento;
    }

    $anterior = null;
    $folio = '';

    if (!$esNueva) {
        $stmtR = $conexion->prepare(
            "SELECT *
             FROM recepciones_compra
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmtR->execute([':id' => $id]);
        $anterior = $stmtR->fetch();

        if (!$anterior) {
            cmp_cancelar($conexion, 'La recepción ya no existe.', 404);
        }

        if ($anterior['estado'] !== 'BORRADOR') {
            cmp_cancelar($conexion, 'Solo una recepción en borrador puede modificarse.', 409);
        }

        if ((int) $anterior['compra_id'] !== $compraId) {
            cmp_cancelar($conexion, 'La recepción no pertenece a la compra seleccionada.', 409);
        }

        $folio = (string) $anterior['folio'];
    }

    $pendientes = cmp_mapa_pendientes_recepcion($conexion, $compraId, $id);
    $filas = [];
    $usadas = [];

    foreach ($detallesRaw as $entrada) {
        if (!is_array($entrada)) {
            continue;
        }

        $cantidadTexto = trim((string) ($entrada['cantidad_recibida'] ?? '0'));

        if ($cantidadTexto === '' || !is_numeric($cantidadTexto)) {
            cmp_cancelar($conexion, 'Hay una cantidad de recepción inválida.', 422);
        }

        $cantidad = (float) $cantidadTexto;

        if ($cantidad <= 0.0000001) {
            continue;
        }

        $compraDetalleId = cmp_id($entrada['compra_detalle_id'] ?? null, 'renglón de compra');
        $almacenId = cmp_id($entrada['almacen_id'] ?? null, 'almacén');

        if (isset($usadas[$compraDetalleId])) {
            cmp_cancelar($conexion, 'Un producto aparece repetido en la recepción.', 422);
        }
        $usadas[$compraDetalleId] = true;

        if (!isset($pendientes[$compraDetalleId])) {
            cmp_cancelar($conexion, 'Uno de los renglones ya no pertenece a esta compra.', 409);
        }

        $p = $pendientes[$compraDetalleId];

        if ($cantidad > (float) $p['cantidad_pendiente'] + 0.0000001) {
            cmp_cancelar(
                $conexion,
                'No puedes recibir más de lo pendiente para ' . $p['producto'] . '. Pendiente: '
                . cmp_numero((float) $p['cantidad_pendiente'], 6) . ' ' . $p['unidad_simbolo'] . '.',
                422
            );
        }

        if (!cmp_almacen_activo($conexion, $almacenId)) {
            cmp_cancelar($conexion, 'Selecciona un almacén activo.', 409);
        }

        $filas[] = [
            'compra_detalle_id' => $compraDetalleId,
            'almacen_id' => $almacenId,
            'producto_id' => (int) $p['producto_id'],
            'cantidad_recibida' => $cantidad,
            'cantidad_base' => cmp_round6($cantidad * (float) $p['factor_a_unidad_base']),
            'observaciones' => cmp_nullable($entrada['observaciones'] ?? '', 255),
        ];
    }

    if (!$filas) {
        cmp_cancelar($conexion, 'Captura al menos una cantidad recibida mayor que cero.', 422);
    }

    if ($esNueva) {
        $folioTemporal = 'TMP-REC-' . bin2hex(random_bytes(10));

        $stmt = $conexion->prepare(
            "INSERT INTO recepciones_compra
                (
                    folio,
                    compra_id,
                    fecha_recepcion,
                    estado,
                    documento_recepcion,
                    observaciones,
                    created_by
                )
             VALUES
                (
                    :folio,
                    :compra_id,
                    :fecha_recepcion,
                    'BORRADOR',
                    :documento,
                    :observaciones,
                    :created_by
                )"
        );

        $stmt->execute([
            ':folio' => $folioTemporal,
            ':compra_id' => $compraId,
            ':fecha_recepcion' => $fechaRecepcion,
            ':documento' => $documento,
            ':observaciones' => $observaciones,
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);

        $id = (int) $conexion->lastInsertId();
        $folio = 'REC-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);

        $conexion->prepare(
            "UPDATE recepciones_compra SET folio = :folio WHERE id = :id"
        )->execute([':folio' => $folio, ':id' => $id]);

    } else {
        $conexion->prepare(
            "UPDATE recepciones_compra
             SET
                fecha_recepcion = :fecha_recepcion,
                documento_recepcion = :documento,
                observaciones = :observaciones
             WHERE id = :id
               AND estado = 'BORRADOR'"
        )->execute([
            ':fecha_recepcion' => $fechaRecepcion,
            ':documento' => $documento,
            ':observaciones' => $observaciones,
            ':id' => $id,
        ]);

        $conexion->prepare(
            "DELETE FROM recepciones_compra_detalle WHERE recepcion_id = :id"
        )->execute([':id' => $id]);
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO recepciones_compra_detalle
            (
                recepcion_id,
                compra_detalle_id,
                almacen_id,
                producto_id,
                cantidad_recibida,
                cantidad_base,
                observaciones
            )
         VALUES
            (
                :recepcion_id,
                :compra_detalle_id,
                :almacen_id,
                :producto_id,
                :cantidad_recibida,
                :cantidad_base,
                :observaciones
            )"
    );

    foreach ($filas as $f) {
        $stmtDet->execute([
            ':recepcion_id' => $id,
            ':compra_detalle_id' => $f['compra_detalle_id'],
            ':almacen_id' => $f['almacen_id'],
            ':producto_id' => $f['producto_id'],
            ':cantidad_recibida' => $f['cantidad_recibida'],
            ':cantidad_base' => $f['cantidad_base'],
            ':observaciones' => $f['observaciones'],
        ]);
    }

    cmp_auditar(
        $conexion,
        $esNueva ? 'RECEPCION_CREADA' : 'RECEPCION_EDITADA',
        'recepciones_compra',
        $id,
        $esNueva ? 'Se creó una recepción en borrador.' : 'Se actualizó una recepción en borrador.',
        $anterior ? ['folio' => $anterior['folio'], 'estado' => $anterior['estado']] : null,
        ['folio' => $folio, 'compra_id' => $compraId, 'renglones' => count($filas)]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNueva ? 'Recepción guardada como borrador.' : 'Recepción actualizada.',
        ['recepcion_id' => $id, 'folio' => $folio],
        $esNueva ? 201 : 200
    );
}

function cmp_confirmar_recepcion(PDO $conexion): void
{
    $id = cmp_id($_POST['recepcion_id'] ?? null, 'recepción');

    $conexion->beginTransaction();

    $stmtR = $conexion->prepare(
        "SELECT *
         FROM recepciones_compra
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtR->execute([':id' => $id]);
    $recepcion = $stmtR->fetch();

    if (!$recepcion) {
        cmp_cancelar($conexion, 'La recepción ya no existe.', 404);
    }

    if ($recepcion['estado'] !== 'BORRADOR') {
        cmp_cancelar($conexion, 'Solo una recepción en borrador puede confirmarse.', 409);
    }

    $compra = cmp_bloquear_compra($conexion, (int) $recepcion['compra_id']);

    if (!$compra || !in_array($compra['estado'], ['PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL'], true)) {
        cmp_cancelar($conexion, 'La compra ya no está disponible para recepción.', 409);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            rd.*,
            cd.producto_nombre_snapshot,
            cd.unidad_nombre_snapshot,
            cd.unidad_id,
            cd.factor_a_unidad_base,
            cd.precio_unitario,
            cd.descuento_pct,
            cd.cantidad AS cantidad_comprada,
            a.activo AS almacen_activo
         FROM recepciones_compra_detalle rd
         INNER JOIN compras_detalle cd
            ON cd.id = rd.compra_detalle_id
         INNER JOIN almacenes a
            ON a.id = rd.almacen_id
         WHERE rd.recepcion_id = :recepcion_id
         ORDER BY cd.renglon ASC
         FOR UPDATE"
    );
    $stmtDet->execute([':recepcion_id' => $id]);
    $detalles = $stmtDet->fetchAll();

    if (!$detalles) {
        cmp_cancelar($conexion, 'La recepción no contiene productos.', 409);
    }

    $pendientes = cmp_mapa_pendientes_recepcion($conexion, (int) $compra['id'], $id);

    foreach ($detalles as $d) {
        $detalleId = (int) $d['compra_detalle_id'];

        if (!isset($pendientes[$detalleId])) {
            cmp_cancelar($conexion, 'Un renglón de la recepción ya no es válido.', 409);
        }

        if ((int) $d['almacen_activo'] !== 1) {
            cmp_cancelar($conexion, 'Uno de los almacenes fue desactivado. Edita la recepción.', 409);
        }

        if ((float) $d['cantidad_recibida'] > (float) $pendientes[$detalleId]['cantidad_pendiente'] + 0.0000001) {
            cmp_cancelar(
                $conexion,
                'La cantidad recibida de ' . $d['producto_nombre_snapshot'] . ' supera lo pendiente. Edita la recepción.',
                409
            );
        }
    }

    $tipoEntrada = cmp_tipo_movimiento($conexion, 'ENTRADA_COMPRA');

    if (!$tipoEntrada) {
        cmp_cancelar($conexion, 'No está configurado el tipo de movimiento ENTRADA_COMPRA.', 500);
    }

    $movimientoId = cmp_crear_movimiento(
        $conexion,
        (int) $tipoEntrada['id'],
        'RECEPCION_COMPRA',
        $id,
        'RECEPCION_COMPRA:' . $id,
        null,
        'Entrada física por ' . $recepcion['folio'] . ' / ' . $compra['folio']
    );

    $stmtMovDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (
                movimiento_id,
                renglon,
                almacen_id,
                producto_id,
                cantidad_delta,
                existencia_antes,
                existencia_despues,
                costo_unitario_base,
                observaciones
            )
         VALUES
            (
                :movimiento_id,
                :renglon,
                :almacen_id,
                :producto_id,
                :cantidad_delta,
                :existencia_antes,
                :existencia_despues,
                :costo_unitario_base,
                :observaciones
            )"
    );

    foreach ($detalles as $indice => $d) {
        $existencia = cmp_bloquear_existencia(
            $conexion,
            (int) $d['almacen_id'],
            (int) $d['producto_id']
        );

        $antes = (float) $existencia['existencia_fisica'];
        $reservada = (float) $existencia['cantidad_reservada'];
        $costoAnterior = $existencia['costo_promedio_base'] !== null
            ? (float) $existencia['costo_promedio_base']
            : null;

        $entrada = (float) $d['cantidad_base'];
        $despues = cmp_round6($antes + $entrada);

        $precioNetoPresentacion = (float) $d['precio_unitario'] * (1 - ((float) $d['descuento_pct'] / 100));
        $costoBase = cmp_round6(
            ($precioNetoPresentacion * (float) $compra['tipo_cambio_a_base'])
            / (float) $d['factor_a_unidad_base']
        );

        if ($despues > 0.0000001) {
            $valorAnterior = $antes > 0 && $costoAnterior !== null
                ? $antes * $costoAnterior
                : 0.0;
            $valorEntrada = $entrada * $costoBase;
            $nuevoCosto = cmp_round6(($valorAnterior + $valorEntrada) / $despues);
        } else {
            $nuevoCosto = $costoBase;
        }

        $conexion->prepare(
            "UPDATE existencias_almacen
             SET
                existencia_fisica = :existencia,
                costo_promedio_base = :costo
             WHERE id = :id"
        )->execute([
            ':existencia' => $despues,
            ':costo' => $nuevoCosto,
            ':id' => (int) $existencia['id'],
        ]);

        $stmtMovDet->execute([
            ':movimiento_id' => $movimientoId,
            ':renglon' => $indice + 1,
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':cantidad_delta' => $entrada,
            ':existencia_antes' => $antes,
            ':existencia_despues' => $despues,
            ':costo_unitario_base' => $costoBase,
            ':observaciones' => $d['observaciones'],
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET
            estado = 'APLICADO',
            aplicado_at = NOW(),
            aplicado_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $movimientoId,
    ]);

    $conexion->prepare(
        "UPDATE recepciones_compra
         SET
            estado = 'CONFIRMADA',
            confirmada_at = NOW(),
            confirmada_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    $nuevoEstadoCompra = cmp_recalcular_estado_compra($conexion, (int) $compra['id']);

    cmp_auditar(
        $conexion,
        'RECEPCION_CONFIRMADA',
        'recepciones_compra',
        $id,
        'Se confirmó una recepción. El inventario y Kardex fueron actualizados.',
        ['estado' => 'BORRADOR'],
        [
            'estado' => 'CONFIRMADA',
            'movimiento_inventario_id' => $movimientoId,
            'compra_estado' => $nuevoEstadoCompra,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $nuevoEstadoCompra === 'RECIBIDA'
            ? 'Recepción confirmada. La compra quedó recibida completamente y el inventario fue actualizado.'
            : 'Recepción confirmada. La compra quedó recibida parcialmente y el inventario fue actualizado.',
        ['movimiento_id' => $movimientoId, 'compra_estado' => $nuevoEstadoCompra]
    );
}

function cmp_cancelar_recepcion(PDO $conexion): void
{
    $id = cmp_id($_POST['recepcion_id'] ?? null, 'recepción');
    $motivo = cmp_requerido($_POST['motivo'] ?? '', 'Indica el motivo de cancelación.', 10000);

    $conexion->beginTransaction();

    $stmtR = $conexion->prepare(
        "SELECT *
         FROM recepciones_compra
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtR->execute([':id' => $id]);
    $recepcion = $stmtR->fetch();

    if (!$recepcion) {
        cmp_cancelar($conexion, 'La recepción ya no existe.', 404);
    }

    if ($recepcion['estado'] === 'CANCELADA') {
        $conexion->commit();
        si_responder_json(true, 'La recepción ya estaba cancelada.');
    }

    $compra = cmp_bloquear_compra($conexion, (int) $recepcion['compra_id']);

    if (!$compra) {
        cmp_cancelar($conexion, 'La compra relacionada ya no existe.', 409);
    }

    if ($recepcion['estado'] === 'BORRADOR') {
        $conexion->prepare(
            "UPDATE recepciones_compra
             SET
                estado = 'CANCELADA',
                motivo_cancelacion = :motivo,
                cancelada_at = NOW(),
                cancelada_by = :usuario_id
             WHERE id = :id"
        )->execute([
            ':motivo' => $motivo,
            ':usuario_id' => (int) $_SESSION['usuario_id'],
            ':id' => $id,
        ]);

        cmp_auditar(
            $conexion,
            'RECEPCION_CANCELADA',
            'recepciones_compra',
            $id,
            'Se canceló una recepción en borrador. No existían movimientos de inventario.',
            ['estado' => 'BORRADOR'],
            ['estado' => 'CANCELADA', 'motivo' => $motivo]
        );

        $conexion->commit();
        si_responder_json(true, 'Recepción en borrador cancelada. No se modificó inventario.');
    }

    $stmtMov = $conexion->prepare(
        "SELECT *
         FROM movimientos_inventario
         WHERE origen_tipo = 'RECEPCION_COMPRA'
           AND origen_id = :origen_id
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmtMov->execute([':origen_id' => $id]);
    $movOriginal = $stmtMov->fetch();

    if (!$movOriginal || $movOriginal['estado'] !== 'APLICADO') {
        cmp_cancelar(
            $conexion,
            'No se encontró el movimiento de inventario aplicado de esta recepción. No se canceló para evitar inconsistencias.',
            409
        );
    }

    $stmtMovDet = $conexion->prepare(
        "SELECT *
         FROM movimientos_inventario_detalle
         WHERE movimiento_id = :movimiento_id
         ORDER BY renglon ASC
         FOR UPDATE"
    );
    $stmtMovDet->execute([':movimiento_id' => (int) $movOriginal['id']]);
    $detallesMov = $stmtMovDet->fetchAll();

    if (!$detallesMov) {
        cmp_cancelar($conexion, 'El movimiento original no contiene detalle.', 409);
    }

    $tipoReverso = cmp_tipo_movimiento($conexion, 'REVERSO');

    if (!$tipoReverso) {
        cmp_cancelar($conexion, 'No está configurado el tipo de movimiento REVERSO.', 500);
    }

    /*
     * Antes de modificar cualquier stock se valida todo el reverso.
     * Así nunca dejamos una cancelación aplicada a medias.
     */
    $totalesReverso = [];

    foreach ($detallesMov as $d) {
        $key = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        if (!isset($totalesReverso[$key])) {
            $totalesReverso[$key] = [
                'almacen_id' => (int) $d['almacen_id'],
                'producto_id' => (int) $d['producto_id'],
                'cantidad' => 0.0,
            ];
        }
        $totalesReverso[$key]['cantidad'] += (float) $d['cantidad_delta'];
    }

    foreach ($totalesReverso as $grupo) {
        $existencia = cmp_bloquear_existencia(
            $conexion,
            (int) $grupo['almacen_id'],
            (int) $grupo['producto_id']
        );

        $fisicaDespues = (float) $existencia['existencia_fisica'] - (float) $grupo['cantidad'];

        if ($fisicaDespues < -0.0000001) {
            cmp_cancelar(
                $conexion,
                'No se puede cancelar la recepción porque parte de esa mercancía ya salió del inventario. Debe corregirse mediante el flujo de inventario correspondiente.',
                409
            );
        }

        if ($fisicaDespues + 0.0000001 < (float) $existencia['cantidad_reservada']) {
            cmp_cancelar(
                $conexion,
                'No se puede cancelar la recepción porque dejaría la existencia física por debajo de la cantidad actualmente reservada.',
                409
            );
        }
    }

    $movReversoId = cmp_crear_movimiento(
        $conexion,
        (int) $tipoReverso['id'],
        'CANCELACION_RECEPCION_COMPRA',
        $id,
        'REVERSO_RECEPCION_COMPRA:' . $id,
        (int) $movOriginal['id'],
        $motivo
    );

    $stmtRevDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (
                movimiento_id,
                renglon,
                almacen_id,
                producto_id,
                cantidad_delta,
                existencia_antes,
                existencia_despues,
                costo_unitario_base,
                observaciones
            )
         VALUES
            (
                :movimiento_id,
                :renglon,
                :almacen_id,
                :producto_id,
                :cantidad_delta,
                :existencia_antes,
                :existencia_despues,
                :costo_unitario_base,
                :observaciones
            )"
    );

    foreach ($detallesMov as $indice => $d) {
        $existencia = cmp_bloquear_existencia(
            $conexion,
            (int) $d['almacen_id'],
            (int) $d['producto_id']
        );

        $antes = (float) $existencia['existencia_fisica'];
        $salida = (float) $d['cantidad_delta'];
        $despues = cmp_round6($antes - $salida);
        $costoActual = $existencia['costo_promedio_base'] !== null
            ? (float) $existencia['costo_promedio_base']
            : 0.0;
        $costoOrigen = $d['costo_unitario_base'] !== null
            ? (float) $d['costo_unitario_base']
            : $costoActual;

        if ($despues > 0.0000001) {
            $valorActual = $antes * $costoActual;
            $valorRetirado = $salida * $costoOrigen;
            $nuevoCosto = cmp_round6(max(0, $valorActual - $valorRetirado) / $despues);
        } else {
            $nuevoCosto = null;
        }

        $conexion->prepare(
            "UPDATE existencias_almacen
             SET
                existencia_fisica = :existencia,
                costo_promedio_base = :costo
             WHERE id = :id"
        )->execute([
            ':existencia' => $despues,
            ':costo' => $nuevoCosto,
            ':id' => (int) $existencia['id'],
        ]);

        $stmtRevDet->execute([
            ':movimiento_id' => $movReversoId,
            ':renglon' => $indice + 1,
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':cantidad_delta' => -$salida,
            ':existencia_antes' => $antes,
            ':existencia_despues' => $despues,
            ':costo_unitario_base' => $costoOrigen,
            ':observaciones' => 'Reverso de ' . $recepcion['folio'],
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET
            estado = 'APLICADO',
            aplicado_at = NOW(),
            aplicado_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $movReversoId,
    ]);

    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET estado = 'REVERTIDO'
         WHERE id = :id"
    )->execute([':id' => (int) $movOriginal['id']]);

    $conexion->prepare(
        "UPDATE recepciones_compra
         SET
            estado = 'CANCELADA',
            motivo_cancelacion = :motivo,
            cancelada_at = NOW(),
            cancelada_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    $nuevoEstado = cmp_recalcular_estado_compra($conexion, (int) $compra['id']);

    cmp_auditar(
        $conexion,
        'RECEPCION_CANCELADA_REVERTIDA',
        'recepciones_compra',
        $id,
        'Se canceló una recepción confirmada y se generó un movimiento inverso de inventario.',
        ['estado' => 'CONFIRMADA', 'movimiento_original_id' => (int) $movOriginal['id']],
        [
            'estado' => 'CANCELADA',
            'motivo' => $motivo,
            'movimiento_reverso_id' => $movReversoId,
            'compra_estado' => $nuevoEstado,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Recepción cancelada. El inventario fue revertido y el Kardex conserva ambos movimientos.',
        ['movimiento_reverso_id' => $movReversoId, 'compra_estado' => $nuevoEstado]
    );
}

/* =========================================================================
   HISTORIAL DEL MÓDULO
   ========================================================================= */

function cmp_historial(PDO $conexion): void
{
    $pagina = cmp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cmp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cmp_texto($_GET['busqueda'] ?? '', 180);
    $desde = cmp_fecha_opcional($_GET['desde'] ?? '');
    $hasta = cmp_fecha_opcional($_GET['hasta'] ?? '');

    $where = ["a.modulo = 'compras'"];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            a.accion LIKE :accion
            OR a.descripcion LIKE :descripcion
            OR u.usuario LIKE :usuario
        )";
        $params[':accion'] = '%' . strtoupper($q) . '%';
        $params[':descripcion'] = '%' . $q . '%';
        $params[':usuario'] = '%' . $q . '%';
    }

    if ($desde !== null) {
        $where[] = 'a.fecha_hora >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta !== null) {
        $where[] = 'a.fecha_hora < DATE_ADD(:hasta, INTERVAL 1 DAY)';
        $params[':hasta'] = $hasta . ' 00:00:00';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM auditoria a
         LEFT JOIN usuarios u
            ON u.id = a.usuario_id
         WHERE {$whereSql}"
    );
    cmp_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            a.id,
            a.fecha_hora,
            a.accion,
            a.entidad_tabla,
            a.entidad_id,
            a.descripcion,
            COALESCE(u.usuario, 'Sistema') AS usuario
         FROM auditoria a
         LEFT JOIN usuarios u
            ON u.id = a.usuario_id
         WHERE {$whereSql}
         ORDER BY a.fecha_hora DESC, a.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cmp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();
    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['entidad_id'] = $f['entidad_id'] !== null ? (int) $f['entidad_id'] : null;
    }
    unset($f);

    si_responder_json(
        true,
        'Historial cargado.',
        [
            'historial' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

/* =========================================================================
   HELPERS DE NEGOCIO
   ========================================================================= */

function cmp_requerir_permiso(string $permiso): void
{
    if (!si_tiene_permiso($permiso)) {
        si_responder_json(false, 'No tienes permiso para realizar esta acción.', ['permiso' => $permiso], 403);
    }
}

function cmp_bloquear_compra(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT *
         FROM compras
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function cmp_bloquear_proveedor(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT *
         FROM proveedores
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function cmp_bloquear_relacion_compra(PDO $conexion, int $relacionId, int $proveedorId): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.proveedor_id,
            pp.producto_id,
            pp.presentacion_id,
            pp.compra_minima,
            pp.dias_entrega,
            p.sku,
            p.nombre AS producto,
            p.tasa_impuesto_id,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(pres.unidad_id, p.unidad_base_id) AS unidad_id,
            COALESCE(up.nombre, ub.nombre) AS unidad_nombre,
            COALESCE(up.simbolo, ub.simbolo) AS unidad_simbolo,
            COALESCE(pres.factor_a_unidad_base, 1) AS factor_a_unidad_base
         FROM proveedores_productos pp
         INNER JOIN proveedores pr
            ON pr.id = pp.proveedor_id
         INNER JOIN productos p
            ON p.id = pp.producto_id
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         LEFT JOIN unidades_medida up
            ON up.id = pres.unidad_id
         LEFT JOIN tasas_impuesto ti
            ON ti.id = p.tasa_impuesto_id
         WHERE pp.id = :relacion_id
           AND pp.proveedor_id = :proveedor_id
           AND pp.activo = 1
           AND pr.activo = 1
           AND p.activo = 1
           AND p.tipo = 'MATERIA_PRIMA'
           AND (
                pp.presentacion_id IS NULL
                OR (pres.activo = 1 AND pres.es_compra = 1)
           )
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([
        ':relacion_id' => $relacionId,
        ':proveedor_id' => $proveedorId,
    ]);

    $fila = $stmt->fetch();
    return $fila ?: null;
}

function cmp_moneda(PDO $conexion, int $id, bool $soloActiva): ?array
{
    $sql = "SELECT id, codigo, nombre, simbolo, es_base, activo
            FROM monedas
            WHERE id = :id";
    if ($soloActiva) {
        $sql .= ' AND activo = 1';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    $fila['es_base'] = (int) $fila['es_base'];
    $fila['activo'] = (int) $fila['activo'];
    return $fila;
}

function cmp_moneda_base(PDO $conexion): ?array
{
    $fila = $conexion->query(
        "SELECT id, codigo, nombre, simbolo
         FROM monedas
         WHERE es_base = 1
           AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    return $fila;
}

function cmp_buscar_tipo_cambio(PDO $conexion, int $origenId, int $destinoId, string $fecha): ?array
{
    $base = cmp_moneda_base($conexion);
    if ($base && $destinoId === (int) $base['id']) {
        return si_tc_resolver_a_base($conexion, $origenId, $fecha, true);
    }

    $directo = si_tc_buscar_local($conexion, $origenId, $destinoId, $fecha);
    if ($directo !== null) {
        return [
            'tipo_cambio' => (float) $directo['tipo_cambio'],
            'fecha' => (string) $directo['fecha'],
            'fuente' => $directo['fuente'],
            'desactualizado' => false,
            'dias_habiles_antiguedad' => 0,
        ];
    }

    $inverso = si_tc_buscar_local($conexion, $destinoId, $origenId, $fecha);
    if ($inverso === null || (float) $inverso['tipo_cambio'] <= 0) {
        return null;
    }

    return [
        'tipo_cambio' => 1 / (float) $inverso['tipo_cambio'],
        'fecha' => (string) $inverso['fecha'],
        'fuente' => trim((string) ($inverso['fuente'] ?? '')) !== ''
            ? (string) $inverso['fuente'] . ' · inverso'
            : 'Par inverso',
        'desactualizado' => false,
        'dias_habiles_antiguedad' => 0,
    ];
}

function cmp_generar_cuenta_por_pagar(PDO $conexion, array $compra): int
{
    $stmt = $conexion->prepare(
        "SELECT id, estado
         FROM cuentas_por_pagar
         WHERE compra_id = :compra_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':compra_id' => (int) $compra['id']]);
    $existente = $stmt->fetch();

    if ($existente) {
        if ($existente['estado'] === 'CANCELADA') {
            cmp_cancelar($conexion, 'La compra tiene una cuenta por pagar cancelada previamente. Revisa la integridad financiera antes de confirmar.', 409);
        }
        return (int) $existente['id'];
    }

    if (empty($compra['fecha_vencimiento'])) {
        cmp_cancelar($conexion, 'La compra a crédito no tiene fecha de vencimiento.', 409);
    }

    $fechaDocumento = $compra['fecha_factura'] ?: substr((string) $compra['fecha_compra'], 0, 10);
    $estado = (string) $compra['fecha_vencimiento'] < date('Y-m-d') ? 'VENCIDA' : 'PENDIENTE';
    $folioTmp = 'TMP-CXP-' . bin2hex(random_bytes(10));

    $stmt = $conexion->prepare(
        "INSERT INTO cuentas_por_pagar
            (
                folio,
                compra_id,
                proveedor_id,
                moneda_id,
                importe_original,
                importe_pagado,
                fecha_documento,
                fecha_vencimiento,
                estado,
                observaciones
            )
         VALUES
            (
                :folio,
                :compra_id,
                :proveedor_id,
                :moneda_id,
                :importe_original,
                0,
                :fecha_documento,
                :fecha_vencimiento,
                :estado,
                :observaciones
            )"
    );

    $stmt->execute([
        ':folio' => $folioTmp,
        ':compra_id' => (int) $compra['id'],
        ':proveedor_id' => (int) $compra['proveedor_id'],
        ':moneda_id' => (int) $compra['moneda_id'],
        ':importe_original' => (float) $compra['total'],
        ':fecha_documento' => $fechaDocumento,
        ':fecha_vencimiento' => $compra['fecha_vencimiento'],
        ':estado' => $estado,
        ':observaciones' => 'Generada automáticamente desde ' . $compra['folio'] . '.',
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'CXP-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);

    $conexion->prepare(
        "UPDATE cuentas_por_pagar SET folio = :folio WHERE id = :id"
    )->execute([':folio' => $folio, ':id' => $id]);

    cmp_auditar(
        $conexion,
        'CUENTA_POR_PAGAR_GENERADA',
        'cuentas_por_pagar',
        $id,
        'Se generó automáticamente una cuenta por pagar desde una compra a crédito.',
        null,
        [
            'folio' => $folio,
            'compra_id' => (int) $compra['id'],
            'importe_original' => (float) $compra['total'],
            'fecha_vencimiento' => $compra['fecha_vencimiento'],
        ]
    );

    return $id;
}

function cmp_lineas_pendientes_recepcion(PDO $conexion, int $compraId, int $excluirRecepcionId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            cd.id AS compra_detalle_id,
            cd.producto_id,
            cd.producto_nombre_snapshot AS producto,
            cd.sku_snapshot AS sku,
            cd.unidad_id,
            cd.unidad_nombre_snapshot AS unidad,
            u.simbolo AS unidad_simbolo,
            cd.cantidad AS cantidad_comprada,
            cd.factor_a_unidad_base,
            COALESCE(rec.cantidad_recibida, 0) AS cantidad_recibida,
            GREATEST(cd.cantidad - COALESCE(rec.cantidad_recibida, 0), 0) AS cantidad_pendiente
         FROM compras_detalle cd
         INNER JOIN unidades_medida u
            ON u.id = cd.unidad_id
         LEFT JOIN (
            SELECT
                rcd.compra_detalle_id,
                SUM(rcd.cantidad_recibida) AS cantidad_recibida
            FROM recepciones_compra_detalle rcd
            INNER JOIN recepciones_compra rc
                ON rc.id = rcd.recepcion_id
            WHERE rc.estado = 'CONFIRMADA'
              AND rc.id <> :excluir_recepcion_id
            GROUP BY rcd.compra_detalle_id
         ) rec
            ON rec.compra_detalle_id = cd.id
         WHERE cd.compra_id = :compra_id
           AND cd.cantidad - COALESCE(rec.cantidad_recibida, 0) > 0.0000001
         ORDER BY cd.renglon ASC"
    );

    $stmt->execute([
        ':excluir_recepcion_id' => $excluirRecepcionId,
        ':compra_id' => $compraId,
    ]);

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['compra_detalle_id'] = (int) $f['compra_detalle_id'];
        $f['producto_id'] = (int) $f['producto_id'];
        $f['unidad_id'] = (int) $f['unidad_id'];
        foreach (['cantidad_comprada', 'factor_a_unidad_base', 'cantidad_recibida', 'cantidad_pendiente'] as $campo) {
            $f[$campo] = (float) $f[$campo];
        }
    }
    unset($f);

    return $filas;
}

function cmp_mapa_pendientes_recepcion(PDO $conexion, int $compraId, int $excluirRecepcionId): array
{
    $filas = cmp_lineas_pendientes_recepcion($conexion, $compraId, $excluirRecepcionId);
    $mapa = [];
    foreach ($filas as $f) {
        $mapa[(int) $f['compra_detalle_id']] = $f;
    }
    return $mapa;
}

function cmp_almacenes_activos(PDO $conexion): array
{
    $filas = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC"
    )->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
    }
    unset($f);

    return $filas;
}

function cmp_almacen_activo(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare(
        "SELECT 1 FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function cmp_tipo_movimiento(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre
         FROM tipos_movimiento_inventario
         WHERE codigo = :codigo
           AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([':codigo' => $codigo]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    return $fila;
}

function cmp_crear_movimiento(
    PDO $conexion,
    int $tipoMovimientoId,
    string $origenTipo,
    int $origenId,
    string $idempotencyKey,
    ?int $movimientoRevertidoId,
    ?string $motivo
): int {
    $stmtExiste = $conexion->prepare(
        "SELECT id
         FROM movimientos_inventario
         WHERE idempotency_key = :clave
         LIMIT 1
         FOR UPDATE"
    );
    $stmtExiste->execute([':clave' => $idempotencyKey]);

    if ($stmtExiste->fetchColumn()) {
        cmp_cancelar(
            $conexion,
            'Esta operación de inventario ya fue procesada anteriormente. Recarga la página antes de continuar.',
            409
        );
    }

    $tmp = 'TMP-MOV-' . bin2hex(random_bytes(10));

    $stmt = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (
                folio,
                tipo_movimiento_id,
                fecha_movimiento,
                estado,
                origen_tipo,
                origen_id,
                idempotency_key,
                movimiento_revertido_id,
                motivo,
                observaciones,
                created_by
            )
         VALUES
            (
                :folio,
                :tipo_movimiento_id,
                NOW(),
                'BORRADOR',
                :origen_tipo,
                :origen_id,
                :idempotency_key,
                :movimiento_revertido_id,
                :motivo,
                NULL,
                :created_by
            )"
    );

    $stmt->execute([
        ':folio' => $tmp,
        ':tipo_movimiento_id' => $tipoMovimientoId,
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $origenId,
        ':idempotency_key' => $idempotencyKey,
        ':movimiento_revertido_id' => $movimientoRevertidoId,
        ':motivo' => $motivo,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'MOV-' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);

    $conexion->prepare(
        "UPDATE movimientos_inventario SET folio = :folio WHERE id = :id"
    )->execute([':folio' => $folio, ':id' => $id]);

    return $id;
}

function cmp_bloquear_existencia(PDO $conexion, int $almacenId, int $productoId): array
{
    /*
     * INSERT IGNORE evita carreras al crear por primera vez la combinación
     * almacén/producto; después SELECT ... FOR UPDATE serializa el saldo.
     */
    $stmtInsert = $conexion->prepare(
        "INSERT IGNORE INTO existencias_almacen
            (
                almacen_id,
                producto_id,
                existencia_fisica,
                cantidad_reservada,
                stock_minimo,
                punto_reorden,
                costo_promedio_base
            )
         VALUES
            (
                :almacen_id,
                :producto_id,
                0,
                0,
                0,
                NULL,
                NULL
            )"
    );
    $stmtInsert->execute([
        ':almacen_id' => $almacenId,
        ':producto_id' => $productoId,
    ]);

    $stmt = $conexion->prepare(
        "SELECT *
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id
           AND producto_id = :producto_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([
        ':almacen_id' => $almacenId,
        ':producto_id' => $productoId,
    ]);
    $fila = $stmt->fetch();

    if (!$fila) {
        cmp_cancelar($conexion, 'No fue posible bloquear la existencia del producto.', 500);
    }

    return $fila;
}

function cmp_recalcular_estado_compra(PDO $conexion, int $compraId): string
{
    $stmt = $conexion->prepare(
        "SELECT
            COUNT(*) AS renglones,
            SUM(
                CASE
                    WHEN COALESCE(rec.cantidad_recibida, 0) + 0.0000001 >= cd.cantidad
                    THEN 1 ELSE 0
                END
            ) AS completos,
            SUM(COALESCE(rec.cantidad_recibida, 0)) AS recibido_total
         FROM compras_detalle cd
         LEFT JOIN (
            SELECT
                rcd.compra_detalle_id,
                SUM(rcd.cantidad_recibida) AS cantidad_recibida
            FROM recepciones_compra_detalle rcd
            INNER JOIN recepciones_compra rc
                ON rc.id = rcd.recepcion_id
            WHERE rc.estado = 'CONFIRMADA'
            GROUP BY rcd.compra_detalle_id
         ) rec
            ON rec.compra_detalle_id = cd.id
         WHERE cd.compra_id = :compra_id"
    );
    $stmt->execute([':compra_id' => $compraId]);
    $r = $stmt->fetch();

    $renglones = (int) ($r['renglones'] ?? 0);
    $completos = (int) ($r['completos'] ?? 0);
    $recibido = (float) ($r['recibido_total'] ?? 0);

    if ($renglones > 0 && $completos === $renglones) {
        $estado = 'RECIBIDA';
    } elseif ($recibido > 0.0000001) {
        $estado = 'RECIBIDA_PARCIAL';
    } else {
        $estado = 'PENDIENTE_RECEPCION';
    }

    $conexion->prepare(
        "UPDATE compras SET estado = :estado WHERE id = :id"
    )->execute([':estado' => $estado, ':id' => $compraId]);

    return $estado;
}

function cmp_compra_auditoria(array $fila): array
{
    return [
        'folio' => $fila['folio'] ?? null,
        'proveedor_id' => isset($fila['proveedor_id']) ? (int) $fila['proveedor_id'] : null,
        'fecha_compra' => $fila['fecha_compra'] ?? null,
        'numero_factura' => $fila['numero_factura'] ?? null,
        'moneda_id' => isset($fila['moneda_id']) ? (int) $fila['moneda_id'] : null,
        'condicion_pago' => $fila['condicion_pago'] ?? null,
        'estado' => $fila['estado'] ?? null,
        'total' => isset($fila['total']) ? (float) $fila['total'] : null,
    ];
}

function cmp_auditar(
    PDO $conexion,
    string $accion,
    string $tabla,
    int $entidadId,
    string $descripcion,
    ?array $anterior,
    ?array $nuevo
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (
                usuario_id,
                accion,
                modulo,
                entidad_tabla,
                entidad_id,
                descripcion,
                datos_anteriores,
                datos_nuevos,
                ip,
                user_agent
            )
         VALUES
            (
                :usuario_id,
                :accion,
                'compras',
                :tabla,
                :entidad_id,
                :descripcion,
                :datos_anteriores,
                :datos_nuevos,
                :ip,
                :user_agent
            )"
    );

    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':datos_anteriores' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':datos_nuevos' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

/* =========================================================================
   VALIDACIÓN Y UTILIDADES
   ========================================================================= */

function cmp_requerido($valor, string $mensaje, int $maximo): string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        si_responder_json(false, $mensaje, [], 422);
    }
    if (mb_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }
    return $texto;
}

function cmp_nullable($valor, int $maximo): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    if (mb_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }
    return $texto;
}

function cmp_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    if (mb_strlen($texto) > $maximo) {
        $texto = mb_substr($texto, 0, $maximo);
    }
    return $texto;
}

function cmp_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Identificador de ' . $entidad . ' inválido.', [], 422);
    }
    return (int) $id;
}

function cmp_entero_rango($valor, int $minimo, int $maximo, int $default): int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT);
    if ($n === false) {
        return $default;
    }
    $n = (int) $n;
    if ($n < $minimo || $n > $maximo) {
        return $default;
    }
    return $n;
}

function cmp_decimal_positivo($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }
    $n = (float) $valor;
    if (!is_finite($n) || $n <= 0 || $n > 999999999999.0) {
        si_responder_json(false, $mensaje, [], 422);
    }
    return $n;
}

function cmp_decimal_rango($valor, float $minimo, float $maximo, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }
    $n = (float) $valor;
    if (!is_finite($n) || $n < $minimo || $n > $maximo) {
        si_responder_json(false, $mensaje, [], 422);
    }
    return $n;
}

function cmp_fecha($valor, string $campo): string
{
    $texto = trim((string) $valor);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
    $errores = DateTimeImmutable::getLastErrors();

    if (!$dt || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0)) || $dt->format('Y-m-d') !== $texto) {
        si_responder_json(false, 'La ' . $campo . ' no es válida.', ['campo' => $campo], 422);
    }
    return $texto;
}

function cmp_fecha_opcional($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    return cmp_fecha($texto, 'fecha');
}

function cmp_fecha_hora($valor, string $campo): string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return date('Y-m-d H:i:s');
    }

    $formatos = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'];

    foreach ($formatos as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $texto);
        $errores = DateTimeImmutable::getLastErrors();
        if ($dt && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    si_responder_json(false, 'La ' . $campo . ' no es válida.', ['campo' => $campo], 422);
}

function cmp_round4(float $n): float
{
    return round($n, 4, PHP_ROUND_HALF_UP);
}

function cmp_round6(float $n): float
{
    return round($n, 6, PHP_ROUND_HALF_UP);
}

function cmp_numero(float $n, int $decimales): string
{
    return rtrim(rtrim(number_format($n, $decimales, '.', ''), '0'), '.');
}

function cmp_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}

function cmp_cancelar(PDO $conexion, string $mensaje, int $codigo, array $extra = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $extra, $codigo);
}
