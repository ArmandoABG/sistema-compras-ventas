<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('ventas.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_VENTAS')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                ven_catalogos($conexion);
                break;
            case 'LISTAR_VENTAS':
                ven_listar($conexion);
                break;
            case 'DETALLE_VENTA':
                ven_detalle($conexion);
                break;
            case 'BUSCAR_CLIENTES':
                ven_buscar_clientes($conexion);
                break;
            case 'BUSCAR_PRODUCTOS':
                ven_buscar_productos($conexion);
                break;
            case 'PRESENTACIONES_PRODUCTO':
                ven_presentaciones_producto($conexion);
                break;
            case 'SUGERIR_PRECIO':
                ven_sugerir_precio($conexion);
                break;
            case 'COTIZACION_PARA_VENTA':
                ven_cotizacion_para_venta($conexion);
                break;
            case 'APARTADO_PARA_VENTA':
                ven_apartado_para_venta($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'CREAR_VENTA':
            si_requerir_permiso('ventas.crear', true);
            ven_crear($conexion);
            break;
        case 'CANCELAR_VENTA':
            si_requerir_permiso('ventas.cancelar', true);
            ven_cancelar_venta($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'VEN-' . date('Ymd-His');
    error_log('[' . $referencia . '][VENTAS][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());

    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'No fue posible guardar porque existe un dato duplicado o una relación inválida.', ['referencia' => $referencia], 409);
    }

    si_responder_json(false, 'No fue posible procesar la operación de ventas.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'VEN-' . date('Ymd-His');
    error_log('[' . $referencia . '][VENTAS] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar ventas.', ['referencia' => $referencia], 500);
}

/* =========================================================================
   CATÁLOGOS / BÚSQUEDAS
   ========================================================================= */

function ven_catalogos(PDO $conexion): void
{
    ven_procesar_apartados_vencidos($conexion);

    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    foreach ($almacenes as &$a) {
        $a['id'] = (int) $a['id'];
    }
    unset($a);

    $monedas = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, es_base
         FROM monedas
         WHERE activo = 1
         ORDER BY es_base DESC, codigo ASC"
    )->fetchAll();

    foreach ($monedas as &$m) {
        $m['id'] = (int) $m['id'];
        $m['es_base'] = (int) $m['es_base'];
    }
    unset($m);

    $metodos = $conexion->query(
        "SELECT id, codigo, nombre, requiere_referencia
         FROM metodos_pago
         WHERE activo = 1
         ORDER BY id ASC"
    )->fetchAll();

    foreach ($metodos as &$m) {
        $m['id'] = (int) $m['id'];
        $m['requiere_referencia'] = (int) $m['requiere_referencia'];
    }
    unset($m);

    si_responder_json(true, 'Catálogos cargados.', [
        'almacenes' => $almacenes,
        'monedas' => $monedas,
        'metodos_pago' => $metodos,
        'fecha_hoy' => date('Y-m-d'),
    ]);
}

function ven_buscar_clientes(PDO $conexion): void
{
    $q = ven_texto($_GET['q'] ?? '', 180);

    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['clientes' => []]);
    }

    $like = '%' . $q . '%';
    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.codigo,
            c.nombre_razon_social,
            c.rfc,
            c.nivel_cliente_id,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct,
            c.descuento_personal_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct,
            c.dias_credito,
            c.limite_credito,
            COALESCE(cx.saldo_cxc, 0) AS saldo_cxc
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         LEFT JOIN (
            SELECT
                cx.cliente_id,
                SUM(cx.saldo_pendiente * v.tipo_cambio_a_base) AS saldo_cxc
            FROM cuentas_por_cobrar cx
            INNER JOIN ventas v ON v.id = cx.venta_id
            WHERE cx.estado IN ('PENDIENTE','PARCIAL','VENCIDA')
            GROUP BY cx.cliente_id
         ) cx ON cx.cliente_id = c.id
         WHERE c.deleted_at IS NULL
           AND c.activo = 1
           AND (
                c.codigo LIKE :codigo
                OR c.nombre_razon_social LIKE :nombre
                OR c.rfc LIKE :rfc
                OR c.telefono LIKE :telefono
           )
         ORDER BY CASE WHEN c.codigo = :exacta THEN 0 ELSE 1 END, c.nombre_razon_social ASC
         LIMIT 20"
    );
    $stmt->execute([
        ':codigo' => $like,
        ':nombre' => $like,
        ':rfc' => $like,
        ':telefono' => $like,
        ':exacta' => strtoupper($q),
    ]);

    $clientes = $stmt->fetchAll();
    foreach ($clientes as &$c) {
        ven_tipar_cliente($c);
    }
    unset($c);

    si_responder_json(true, 'Clientes cargados.', ['clientes' => $clientes]);
}

function ven_buscar_productos(PDO $conexion): void
{
    $q = ven_texto($_GET['q'] ?? '', 180);
    $almacenId = ven_id($_GET['almacen_id'] ?? null, 'almacén');

    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['productos' => []]);
    }

    if (!ven_almacen_activo($conexion, $almacenId)) {
        si_responder_json(false, 'El almacén seleccionado ya no está disponible.', [], 409);
    }

    $like = '%' . $q . '%';
    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.codigo_barras,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            p.controla_inventario,
            p.permite_fraccion,
            u.codigo AS unidad_base_codigo,
            u.nombre AS unidad_base_nombre,
            u.simbolo AS unidad_base_simbolo,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(e.cantidad_disponible, 0) AS cantidad_disponible
         FROM productos p
         INNER JOIN unidades_medida u ON u.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         LEFT JOIN existencias_almacen e
           ON e.producto_id = p.id AND e.almacen_id = :almacen_id
         WHERE p.deleted_at IS NULL
           AND p.activo = 1
           AND (
                p.sku LIKE :sku
                OR p.nombre LIKE :nombre
                OR p.codigo_barras LIKE :codigo_barras
           )
         ORDER BY CASE WHEN p.sku = :exacta THEN 0 ELSE 1 END, p.nombre ASC
         LIMIT 30"
    );
    $stmt->execute([
        ':almacen_id' => $almacenId,
        ':sku' => $like,
        ':nombre' => $like,
        ':codigo_barras' => $like,
        ':exacta' => strtoupper($q),
    ]);

    $productos = $stmt->fetchAll();
    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_base_id'] = (int) $p['unidad_base_id'];
        $p['controla_inventario'] = (int) $p['controla_inventario'];
        $p['permite_fraccion'] = (int) $p['permite_fraccion'];
        $p['impuesto_pct'] = (float) $p['impuesto_pct'];
        $p['existencia_fisica'] = (float) $p['existencia_fisica'];
        $p['cantidad_reservada'] = (float) $p['cantidad_reservada'];
        $p['cantidad_disponible'] = (float) $p['cantidad_disponible'];
    }
    unset($p);

    si_responder_json(true, 'Productos cargados.', ['productos' => $productos]);
}

function ven_presentaciones_producto(PDO $conexion): void
{
    $productoId = ven_id($_GET['producto_id'] ?? null, 'producto');
    $producto = ven_producto_activo($conexion, $productoId);

    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.unidad_id,
            pp.factor_a_unidad_base,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida u ON u.id = pp.unidad_id
         WHERE pp.producto_id = :producto_id
           AND pp.es_venta = 1
           AND pp.activo = 1
           AND u.activo = 1
         ORDER BY pp.factor_a_unidad_base ASC, pp.nombre ASC"
    );
    $stmt->execute([':producto_id' => $productoId]);
    $presentaciones = $stmt->fetchAll();

    foreach ($presentaciones as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_id'] = (int) $p['unidad_id'];
        $p['factor_a_unidad_base'] = (float) $p['factor_a_unidad_base'];
    }
    unset($p);

    array_unshift($presentaciones, [
        'id' => 0,
        'nombre' => 'Unidad base · ' . $producto['unidad_base_nombre'],
        'unidad_id' => (int) $producto['unidad_base_id'],
        'factor_a_unidad_base' => 1.0,
        'unidad_codigo' => (string) $producto['unidad_base_codigo'],
        'unidad_nombre' => (string) $producto['unidad_base_nombre'],
        'unidad_simbolo' => (string) $producto['unidad_base_simbolo'],
    ]);

    si_responder_json(true, 'Presentaciones cargadas.', [
        'presentaciones' => $presentaciones,
        'producto' => $producto,
    ]);
}

function ven_sugerir_precio(PDO $conexion): void
{
    $productoId = ven_id($_GET['producto_id'] ?? null, 'producto');
    $presentacionId = ven_entero_rango($_GET['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $monedaId = ven_id($_GET['moneda_id'] ?? null, 'moneda');
    $cantidad = ven_decimal($_GET['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0);

    $resultado = ven_resolver_precio($conexion, $productoId, $presentacionId, $monedaId, $cantidad);
    si_responder_json(true, $resultado['mensaje'], $resultado['datos']);
}

/* =========================================================================
   FUENTES: COTIZACIÓN / APARTADO
   ========================================================================= */

function ven_cotizacion_para_venta(PDO $conexion): void
{
    $cotizacionId = ven_id($_GET['cotizacion_id'] ?? null, 'cotización');
    $almacenId = ven_id($_GET['almacen_id'] ?? null, 'almacén');

    if (!ven_almacen_activo($conexion, $almacenId)) {
        si_responder_json(false, 'El almacén seleccionado ya no está disponible.', [], 409);
    }

    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            cl.codigo AS cliente_codigo,
            cl.nombre_razon_social AS cliente_nombre_actual,
            cl.rfc AS cliente_rfc,
            cl.nivel_cliente_id,
            cl.dias_credito,
            cl.limite_credito,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            COALESCE(cl.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_actual_pct,
            m.codigo AS moneda_codigo,
            m.nombre AS moneda_nombre,
            m.simbolo AS moneda_simbolo
         FROM cotizaciones c
         LEFT JOIN clientes cl ON cl.id = c.cliente_id
         LEFT JOIN niveles_cliente n ON n.id = cl.nivel_cliente_id
         INNER JOIN monedas m ON m.id = c.moneda_id
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $cotizacionId]);
    $cotizacion = $stmt->fetch();

    if (!$cotizacion) {
        si_responder_json(false, 'La cotización ya no existe.', [], 404);
    }
    if ($cotizacion['estado'] !== 'ACEPTADA') {
        si_responder_json(false, 'La cotización debe estar ACEPTADA para convertirla en venta.', [], 409);
    }

    $stmtVenta = $conexion->prepare("SELECT id, folio, estado FROM ventas WHERE cotizacion_id = :id ORDER BY id DESC LIMIT 1");
    $stmtVenta->execute([':id' => $cotizacionId]);
    if ($venta = $stmtVenta->fetch()) {
        si_responder_json(false, 'La cotización ya está relacionada con la venta ' . $venta['folio'] . '.', ['venta_id' => (int) $venta['id']], 409);
    }

    $stmtApa = $conexion->prepare("SELECT id, folio, estado FROM apartados WHERE cotizacion_id = :id ORDER BY id DESC LIMIT 1");
    $stmtApa->execute([':id' => $cotizacionId]);
    if ($apartado = $stmtApa->fetch()) {
        si_responder_json(false, 'La cotización ya fue llevada al apartado ' . $apartado['folio'] . '. Convierte ese apartado a venta para respetar la reserva y los anticipos.', ['apartado_id' => (int) $apartado['id']], 409);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            d.*,
            p.sku,
            p.controla_inventario,
            p.permite_fraccion,
            ub.codigo AS unidad_base_codigo,
            ub.simbolo AS unidad_base_simbolo,
            COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(e.cantidad_disponible, 0) AS cantidad_disponible
         FROM cotizaciones_detalle d
         INNER JOIN productos p ON p.id = d.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e
           ON e.almacen_id = :almacen_id AND e.producto_id = d.producto_id
         WHERE d.cotizacion_id = :id
         ORDER BY d.renglon ASC"
    );
    $stmtDet->execute([':almacen_id' => $almacenId, ':id' => $cotizacionId]);
    $detalles = $stmtDet->fetchAll();

    foreach ($detalles as &$d) {
        ven_tipar_detalle_fuente($d);
        $d['almacen_id'] = $almacenId;
    }
    unset($d);

    ven_tipar_cotizacion_fuente($cotizacion);

    si_responder_json(true, 'Cotización preparada para venta.', [
        'cotizacion' => $cotizacion,
        'detalles' => $detalles,
    ]);
}

function ven_apartado_para_venta(PDO $conexion): void
{
    ven_procesar_apartados_vencidos($conexion);

    $apartadoId = ven_id($_GET['apartado_id'] ?? null, 'apartado');
    $stmt = $conexion->prepare(
        "SELECT
            a.*,
            c.codigo AS cliente_codigo,
            c.nombre_razon_social AS cliente_nombre,
            c.rfc AS cliente_rfc,
            c.nivel_cliente_id,
            c.dias_credito,
            c.limite_credito,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_actual_pct,
            m.codigo AS moneda_codigo,
            m.nombre AS moneda_nombre,
            m.simbolo AS moneda_simbolo,
            q.folio AS cotizacion_folio
         FROM apartados a
         INNER JOIN clientes c ON c.id = a.cliente_id
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         INNER JOIN monedas m ON m.id = a.moneda_id
         LEFT JOIN cotizaciones q ON q.id = a.cotizacion_id
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $apartadoId]);
    $apartado = $stmt->fetch();

    if (!$apartado) {
        si_responder_json(false, 'El apartado ya no existe.', [], 404);
    }
    if ($apartado['estado'] !== 'ACTIVO') {
        si_responder_json(false, 'Solo un apartado ACTIVO puede convertirse en venta.', [], 409);
    }
    if (!empty($apartado['reservado_hasta']) && strtotime((string) $apartado['reservado_hasta']) < time()) {
        si_responder_json(false, 'El apartado ya venció y su reserva fue liberada.', [], 409);
    }

    $stmtVenta = $conexion->prepare("SELECT id, folio, estado FROM ventas WHERE apartado_id = :id LIMIT 1");
    $stmtVenta->execute([':id' => $apartadoId]);
    if ($venta = $stmtVenta->fetch()) {
        si_responder_json(false, 'El apartado ya está relacionado con la venta ' . $venta['folio'] . '.', ['venta_id' => (int) $venta['id']], 409);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            d.*,
            p.sku,
            p.controla_inventario,
            p.permite_fraccion,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen_nombre,
            ub.codigo AS unidad_base_codigo,
            ub.simbolo AS unidad_base_simbolo,
            COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(e.cantidad_disponible, 0) AS cantidad_disponible
         FROM apartados_detalle d
         INNER JOIN productos p ON p.id = d.producto_id
         INNER JOIN almacenes a ON a.id = d.almacen_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e
           ON e.almacen_id = d.almacen_id AND e.producto_id = d.producto_id
         WHERE d.apartado_id = :id
         ORDER BY d.renglon ASC"
    );
    $stmtDet->execute([':id' => $apartadoId]);
    $detalles = $stmtDet->fetchAll();
    foreach ($detalles as &$d) {
        ven_tipar_detalle_fuente($d);
        $d['almacen_id'] = (int) $d['almacen_id'];
    }
    unset($d);

    $stmtAnt = $conexion->prepare(
        "SELECT
            aa.id,
            aa.fecha_pago,
            aa.importe,
            aa.referencia,
            mp.nombre AS metodo_nombre
         FROM anticipos_apartado aa
         INNER JOIN metodos_pago mp ON mp.id = aa.metodo_pago_id
         WHERE aa.apartado_id = :id AND aa.estado = 'APLICADO'
         ORDER BY aa.fecha_pago ASC, aa.id ASC"
    );
    $stmtAnt->execute([':id' => $apartadoId]);
    $anticipos = $stmtAnt->fetchAll();
    foreach ($anticipos as &$a) {
        $a['id'] = (int) $a['id'];
        $a['importe'] = (float) $a['importe'];
    }
    unset($a);

    ven_tipar_apartado_fuente($apartado);

    si_responder_json(true, 'Apartado preparado para venta.', [
        'apartado' => $apartado,
        'detalles' => $detalles,
        'anticipos' => $anticipos,
    ]);
}

/* =========================================================================
   LISTADO / DETALLE
   ========================================================================= */

function ven_listar(PDO $conexion): void
{
    $pagina = ven_entero_rango($_GET['pagina'] ?? 1, 1, 1000000, 1);
    $porPagina = ven_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    if (!in_array($porPagina, [10, 20, 50, 100], true)) {
        $porPagina = 20;
    }

    $busqueda = ven_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(ven_texto($_GET['estado'] ?? 'TODOS', 20));
    $condicion = strtoupper(ven_texto($_GET['condicion'] ?? 'TODAS', 20));
    $desde = ven_fecha_opcional($_GET['desde'] ?? null);
    $hasta = ven_fecha_opcional($_GET['hasta'] ?? null);

    if (!in_array($estado, ['TODOS','BORRADOR','CONFIRMADA','CANCELADA'], true)) {
        $estado = 'TODOS';
    }
    if (!in_array($condicion, ['TODAS','CONTADO','CREDITO'], true)) {
        $condicion = 'TODAS';
    }

    $where = [];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(v.folio LIKE :buscar_folio OR v.cliente_nombre_snapshot LIKE :buscar_cliente OR c.codigo LIKE :buscar_codigo OR q.folio LIKE :buscar_cotizacion OR a.folio LIKE :buscar_apartado)";
        $like = '%' . $busqueda . '%';
        $params[':buscar_folio'] = $like;
        $params[':buscar_cliente'] = $like;
        $params[':buscar_codigo'] = $like;
        $params[':buscar_cotizacion'] = $like;
        $params[':buscar_apartado'] = $like;
    }
    if ($estado !== 'TODOS') {
        $where[] = 'v.estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($condicion !== 'TODAS') {
        $where[] = 'v.condicion_pago = :condicion';
        $params[':condicion'] = $condicion;
    }
    if ($desde !== null) {
        $where[] = 'v.fecha_venta >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== null) {
        $where[] = 'v.fecha_venta < :hasta_siguiente';
        $params[':hasta_siguiente'] = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00';
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtCount = $conexion->prepare(
        "SELECT COUNT(*)
         FROM ventas v
         LEFT JOIN clientes c ON c.id = v.cliente_id
         LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
         LEFT JOIN apartados a ON a.id = v.apartado_id
         {$sqlWhere}"
    );
    ven_bind($stmtCount, $params);
    $stmtCount->execute();
    $total = (int) $stmtCount->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            v.id,
            v.folio,
            v.fecha_venta,
            v.estado,
            v.condicion_pago,
            v.total,
            v.cliente_nombre_snapshot,
            c.codigo AS cliente_codigo,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            q.folio AS cotizacion_folio,
            a.folio AS apartado_folio,
            COALESCE(a.importe_anticipado, 0) AS importe_anticipado,
            COALESCE(d.renglones, 0) AS renglones,
            COALESCE(pv.pagado_directo, 0) AS pagado_directo,
            cx.folio AS cxc_folio,
            cx.estado AS cxc_estado,
            cx.saldo_pendiente AS cxc_saldo,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por
         FROM ventas v
         LEFT JOIN clientes c ON c.id = v.cliente_id
         INNER JOIN monedas m ON m.id = v.moneda_id
         LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
         LEFT JOIN apartados a ON a.id = v.apartado_id
         LEFT JOIN (
            SELECT venta_id, COUNT(*) AS renglones
            FROM ventas_detalle
            GROUP BY venta_id
         ) d ON d.venta_id = v.id
         LEFT JOIN (
            SELECT venta_id, SUM(importe) AS pagado_directo
            FROM pagos_venta
            WHERE estado = 'APLICADO'
            GROUP BY venta_id
         ) pv ON pv.venta_id = v.id
         LEFT JOIN cuentas_por_cobrar cx ON cx.venta_id = v.id
         LEFT JOIN usuarios u ON u.id = v.created_by
         {$sqlWhere}
         ORDER BY v.fecha_venta DESC, v.id DESC
         LIMIT :limite OFFSET :offset"
    );
    ven_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $ventas = $stmt->fetchAll();

    foreach ($ventas as &$v) {
        ven_tipar_venta_listado($v);
    }
    unset($v);

    $kpis = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'CONFIRMADA') AS confirmadas,
            SUM(estado = 'CONFIRMADA' AND condicion_pago = 'CONTADO') AS contado,
            SUM(estado = 'CONFIRMADA' AND condicion_pago = 'CREDITO') AS credito,
            SUM(estado = 'CANCELADA') AS canceladas,
            COALESCE(SUM(CASE WHEN estado = 'CONFIRMADA' THEN total * tipo_cambio_a_base ELSE 0 END), 0) AS importe_confirmado
         FROM ventas"
    )->fetch() ?: [];

    si_responder_json(true, 'Ventas cargadas.', [
        'ventas' => $ventas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
        'kpis' => [
            'total' => (int) ($kpis['total'] ?? 0),
            'confirmadas' => (int) ($kpis['confirmadas'] ?? 0),
            'contado' => (int) ($kpis['contado'] ?? 0),
            'credito' => (int) ($kpis['credito'] ?? 0),
            'canceladas' => (int) ($kpis['canceladas'] ?? 0),
            'importe_confirmado' => (float) ($kpis['importe_confirmado'] ?? 0),
        ],
    ]);
}

function ven_detalle(PDO $conexion): void
{
    $ventaId = ven_id($_GET['venta_id'] ?? null, 'venta');

    $stmt = $conexion->prepare(
        "SELECT
            v.*,
            c.codigo AS cliente_codigo,
            m.codigo AS moneda_codigo,
            m.nombre AS moneda_nombre,
            m.simbolo AS moneda_simbolo,
            q.folio AS cotizacion_folio,
            a.folio AS apartado_folio,
            COALESCE(a.importe_anticipado, 0) AS importe_anticipado,
            cx.id AS cxc_id,
            cx.folio AS cxc_folio,
            cx.importe_original AS cxc_importe_original,
            cx.importe_pagado AS cxc_importe_pagado,
            cx.saldo_pendiente AS cxc_saldo_pendiente,
            cx.fecha_vencimiento AS cxc_fecha_vencimiento,
            cx.estado AS cxc_estado,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por,
            CONCAT_WS(' ', uc.nombres, uc.apellido_paterno, uc.apellido_materno) AS cancelado_por
         FROM ventas v
         LEFT JOIN clientes c ON c.id = v.cliente_id
         INNER JOIN monedas m ON m.id = v.moneda_id
         LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
         LEFT JOIN apartados a ON a.id = v.apartado_id
         LEFT JOIN cuentas_por_cobrar cx ON cx.venta_id = v.id
         LEFT JOIN usuarios u ON u.id = v.created_by
         LEFT JOIN usuarios uc ON uc.id = v.cancelada_by
         WHERE v.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $ventaId]);
    $venta = $stmt->fetch();

    if (!$venta) {
        si_responder_json(false, 'La venta ya no existe.', [], 404);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            d.*,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen_nombre,
            ub.codigo AS unidad_base_codigo,
            ub.simbolo AS unidad_base_simbolo
         FROM ventas_detalle d
         INNER JOIN almacenes a ON a.id = d.almacen_id
         INNER JOIN productos p ON p.id = d.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         WHERE d.venta_id = :id
         ORDER BY d.renglon ASC"
    );
    $stmtDet->execute([':id' => $ventaId]);
    $detalles = $stmtDet->fetchAll();
    foreach ($detalles as &$d) {
        ven_tipar_detalle_venta($d);
    }
    unset($d);

    $stmtPagos = $conexion->prepare(
        "SELECT
            pv.id,
            pv.fecha_pago,
            pv.importe,
            pv.referencia,
            pv.estado,
            pv.motivo_cancelacion,
            mp.nombre AS metodo_nombre,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS registrado_por
         FROM pagos_venta pv
         INNER JOIN metodos_pago mp ON mp.id = pv.metodo_pago_id
         INNER JOIN monedas m ON m.id = pv.moneda_id
         LEFT JOIN usuarios u ON u.id = pv.created_by
         WHERE pv.venta_id = :id
         ORDER BY pv.fecha_pago ASC, pv.id ASC"
    );
    $stmtPagos->execute([':id' => $ventaId]);
    $pagos = $stmtPagos->fetchAll();
    $pagadoDirecto = 0.0;
    foreach ($pagos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['importe'] = (float) $p['importe'];
        if ($p['estado'] === 'APLICADO') {
            $pagadoDirecto += (float) $p['importe'];
        }
    }
    unset($p);

    $stmtMov = $conexion->prepare(
        "SELECT mi.id, mi.folio, mi.estado, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
         WHERE mi.origen_tipo = 'VENTA' AND mi.origen_id = :id
         ORDER BY mi.id ASC
         LIMIT 1"
    );
    $stmtMov->execute([':id' => $ventaId]);
    $movimiento = $stmtMov->fetch() ?: null;
    if (is_array($movimiento)) {
        $movimiento['id'] = (int) $movimiento['id'];
    }

    ven_tipar_venta_detalle_header($venta);
    $venta['pagado_directo'] = round($pagadoDirecto, 4);
    $venta['pagado_total'] = round($pagadoDirecto + (float) $venta['importe_anticipado'], 4);
    $venta['saldo_comercial'] = max(0.0, round((float) $venta['total'] - $venta['pagado_total'], 4));

    si_responder_json(true, 'Detalle cargado.', [
        'venta' => $venta,
        'detalles' => $detalles,
        'pagos' => $pagos,
        'movimiento' => $movimiento,
    ]);
}

/* =========================================================================
   CREAR Y CONFIRMAR VENTA
   ========================================================================= */

function ven_crear(PDO $conexion): void
{
    ven_procesar_apartados_vencidos($conexion);

    $origen = strtoupper(ven_texto($_POST['origen'] ?? 'DIRECTO', 20));
    if (!in_array($origen, ['DIRECTO','COTIZACION','APARTADO'], true)) {
        si_responder_json(false, 'El origen de la venta no es válido.', [], 422);
    }

    $origenId = ven_entero_rango($_POST['origen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $clienteIdEntrada = ven_entero_rango($_POST['cliente_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $almacenIdEntrada = ven_entero_rango($_POST['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $monedaIdEntrada = ven_id($_POST['moneda_id'] ?? null, 'moneda');
    $condicionPago = strtoupper(ven_texto($_POST['condicion_pago'] ?? 'CONTADO', 20));
    $metodoPagoId = ven_entero_rango($_POST['metodo_pago_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $referenciaPago = ven_nullable($_POST['referencia_pago'] ?? null, 120);
    $observaciones = ven_nullable($_POST['observaciones'] ?? null, 3000);

    if (!in_array($condicionPago, ['CONTADO','CREDITO'], true)) {
        si_responder_json(false, 'La condición de pago debe ser CONTADO o CRÉDITO.', [], 422);
    }

    $lineasEntrada = [];
    if ($origen === 'DIRECTO') {
        $lineasEntrada = ven_json_array($_POST['lineas'] ?? '[]', 'productos');
        if (!$lineasEntrada) {
            si_responder_json(false, 'Agrega al menos un producto a la venta.', [], 422);
        }
    } elseif ($origenId <= 0) {
        si_responder_json(false, 'Selecciona el documento que se convertirá a venta.', [], 422);
    }

    $conexion->beginTransaction();

    $cliente = null;
    $clienteId = null;
    $monedaId = $monedaIdEntrada;
    $tipoCambio = 1.0;
    $cotizacionId = null;
    $apartadoId = null;
    $importeAnticipado = 0.0;
    $detalles = [];
    $subtotal = 0.0;
    $descuentoTotal = 0.0;
    $impuestoTotal = 0.0;
    $total = 0.0;
    $descuentoClienteSnapshot = 0.0;
    $clienteNombreSnapshot = null;
    $clienteRfcSnapshot = null;
    $nivelClienteId = null;

    if ($origen === 'DIRECTO') {
        if ($almacenIdEntrada <= 0 || !ven_almacen_activo($conexion, $almacenIdEntrada)) {
            ven_cancelar($conexion, 'Selecciona un almacén activo.', 409);
        }
        if (!ven_moneda_activa($conexion, $monedaId)) {
            ven_cancelar($conexion, 'La moneda seleccionada ya no está disponible.', 409);
        }

        if ($clienteIdEntrada > 0) {
            $cliente = ven_cliente_activo($conexion, $clienteIdEntrada, true);
            if (!$cliente) {
                ven_cancelar($conexion, 'El cliente seleccionado ya no está activo.', 409);
            }
            $clienteId = (int) $cliente['id'];
            $clienteNombreSnapshot = (string) $cliente['nombre_razon_social'];
            $clienteRfcSnapshot = $cliente['rfc'] !== null ? (string) $cliente['rfc'] : null;
            $nivelClienteId = $cliente['nivel_cliente_id'] !== null ? (int) $cliente['nivel_cliente_id'] : null;
            $descuentoClienteSnapshot = (float) $cliente['descuento_efectivo_pct'];
        }

        [$detalles, $subtotal, $descuentoTotal, $impuestoTotal, $total] = ven_normalizar_lineas_directas(
            $conexion,
            $lineasEntrada,
            $cliente,
            $monedaId,
            $almacenIdEntrada
        );
        $tipoCambio = ven_tipo_cambio_a_base($conexion, $monedaId, date('Y-m-d')) ?? 0.0;
        if ($tipoCambio <= 0) {
            ven_cancelar($conexion, 'No existe un tipo de cambio válido para la moneda de la venta.', 409);
        }
    } elseif ($origen === 'COTIZACION') {
        $cotizacion = ven_bloquear_cotizacion($conexion, $origenId);
        if (!$cotizacion) {
            ven_cancelar($conexion, 'La cotización ya no existe.', 404);
        }
        if ($cotizacion['estado'] !== 'ACEPTADA') {
            ven_cancelar($conexion, 'La cotización debe estar ACEPTADA para convertirse en venta.', 409);
        }
        if ($almacenIdEntrada <= 0 || !ven_almacen_activo($conexion, $almacenIdEntrada)) {
            ven_cancelar($conexion, 'Selecciona un almacén activo para surtir la cotización.', 409);
        }
        if (ven_venta_de_cotizacion($conexion, $origenId)) {
            ven_cancelar($conexion, 'La cotización ya fue utilizada en una venta.', 409);
        }
        if (ven_apartado_de_cotizacion($conexion, $origenId)) {
            ven_cancelar($conexion, 'La cotización ya fue convertida en apartado. Convierte el apartado a venta para respetar su reserva.', 409);
        }

        $cotizacionId = (int) $cotizacion['id'];
        $clienteId = $cotizacion['cliente_id'] !== null ? (int) $cotizacion['cliente_id'] : null;
        if ($clienteId !== null) {
            $cliente = ven_cliente_activo($conexion, $clienteId, true);
            if (!$cliente) {
                ven_cancelar($conexion, 'El cliente de la cotización ya no está activo.', 409);
            }
        }
        $monedaId = (int) $cotizacion['moneda_id'];
        $tipoCambio = (float) $cotizacion['tipo_cambio_a_base'];
        if ($tipoCambio <= 0) {
            ven_cancelar($conexion, 'La cotización no contiene un tipo de cambio histórico válido.', 409);
        }

        $detalles = ven_detalles_cotizacion_para_venta($conexion, $cotizacionId, $almacenIdEntrada);
        if (!$detalles) {
            ven_cancelar($conexion, 'La cotización no contiene productos.', 409);
        }
        foreach ($detalles as $d) {
            if (!ven_producto_activo($conexion, (int) $d['producto_id'])) {
                ven_cancelar($conexion, 'El producto ' . $d['producto_nombre_snapshot'] . ' ya no está activo.', 409);
            }
        }

        $subtotal = (float) $cotizacion['subtotal'];
        $descuentoTotal = (float) $cotizacion['descuento_total'];
        $impuestoTotal = (float) $cotizacion['impuesto_total'];
        $total = (float) $cotizacion['total'];
        $clienteNombreSnapshot = $cotizacion['cliente_nombre_snapshot'] !== null
            ? (string) $cotizacion['cliente_nombre_snapshot']
            : ($cliente ? (string) $cliente['nombre_razon_social'] : null);
        $clienteRfcSnapshot = $cliente && $cliente['rfc'] !== null ? (string) $cliente['rfc'] : null;
        $nivelClienteId = $cliente && $cliente['nivel_cliente_id'] !== null ? (int) $cliente['nivel_cliente_id'] : null;
        $descuentoClienteSnapshot = $detalles ? (float) $detalles[0]['descuento_pct'] : 0.0;
    } else {
        $apartado = ven_bloquear_apartado($conexion, $origenId);
        if (!$apartado) {
            ven_cancelar($conexion, 'El apartado ya no existe.', 404);
        }
        if ($apartado['estado'] !== 'ACTIVO') {
            ven_cancelar($conexion, 'El apartado debe estar ACTIVO para convertirse en venta.', 409);
        }
        if (!empty($apartado['reservado_hasta']) && strtotime((string) $apartado['reservado_hasta']) < time()) {
            ven_cancelar($conexion, 'El apartado ya venció y no puede convertirse en venta.', 409);
        }
        if (ven_venta_de_apartado($conexion, $origenId)) {
            ven_cancelar($conexion, 'El apartado ya fue utilizado en una venta.', 409);
        }

        $apartadoId = (int) $apartado['id'];
        $cotizacionId = $apartado['cotizacion_id'] !== null ? (int) $apartado['cotizacion_id'] : null;
        $clienteId = (int) $apartado['cliente_id'];
        $cliente = ven_cliente_activo($conexion, $clienteId, true);
        if (!$cliente) {
            ven_cancelar($conexion, 'El cliente del apartado ya no está activo.', 409);
        }
        $monedaId = (int) $apartado['moneda_id'];
        $tipoCambio = ven_tipo_cambio_a_base($conexion, $monedaId, date('Y-m-d')) ?? 0.0;
        if ($tipoCambio <= 0) {
            ven_cancelar($conexion, 'No existe un tipo de cambio válido para la moneda del apartado.', 409);
        }

        $detalles = ven_detalles_apartado_para_venta($conexion, $apartadoId);
        if (!$detalles) {
            ven_cancelar($conexion, 'El apartado no contiene productos reservados.', 409);
        }

        $subtotal = (float) $apartado['subtotal'];
        $descuentoTotal = ven_calcular_descuento_historico($detalles);
        $impuestoTotal = (float) $apartado['impuesto_total'];
        $total = (float) $apartado['total'];
        $importeAnticipado = (float) $apartado['importe_anticipado'];
        $clienteNombreSnapshot = (string) $cliente['nombre_razon_social'];
        $clienteRfcSnapshot = $cliente['rfc'] !== null ? (string) $cliente['rfc'] : null;
        $nivelClienteId = $cliente['nivel_cliente_id'] !== null ? (int) $cliente['nivel_cliente_id'] : null;
        $descuentoClienteSnapshot = $detalles ? (float) $detalles[0]['descuento_pct'] : 0.0;
    }

    if ($total < -0.0001) {
        ven_cancelar($conexion, 'El total de la venta no es válido.', 409);
    }

    $saldoDespuesAnticipo = max(0.0, round($total - $importeAnticipado, 4));

    if ($condicionPago === 'CREDITO') {
        if ($clienteId === null || !$cliente) {
            ven_cancelar($conexion, 'Una venta a crédito requiere un cliente registrado.', 422);
        }
        if ($saldoDespuesAnticipo <= 0.0001) {
            ven_cancelar($conexion, 'El apartado ya está totalmente cubierto por anticipos. Registra la venta como CONTADO.', 422);
        }
        ven_validar_credito_cliente($conexion, $cliente, $saldoDespuesAnticipo, $tipoCambio);
    }

    $metodoPago = null;
    if ($condicionPago === 'CONTADO' && $saldoDespuesAnticipo > 0.0001) {
        if ($metodoPagoId <= 0) {
            ven_cancelar($conexion, 'Selecciona el método con el que se liquidará la venta.', 422);
        }
        $metodoPago = ven_metodo_pago_activo($conexion, $metodoPagoId);
        if (!$metodoPago) {
            ven_cancelar($conexion, 'El método de pago seleccionado ya no está disponible.', 409);
        }
        if ((int) $metodoPago['requiere_referencia'] === 1 && ($referenciaPago === null || $referenciaPago === '')) {
            ven_cancelar($conexion, 'El método de pago seleccionado requiere una referencia.', 422);
        }
    }

    $folioTmp = 'TMP-VTA-' . bin2hex(random_bytes(10));
    $diasCredito = $condicionPago === 'CREDITO' && $cliente ? (int) $cliente['dias_credito'] : 0;
    $fechaVencimiento = $condicionPago === 'CREDITO'
        ? date('Y-m-d', strtotime('+' . $diasCredito . ' days'))
        : null;

    $stmtVenta = $conexion->prepare(
        "INSERT INTO ventas
            (folio, cliente_id, cotizacion_id, apartado_id, cliente_nombre_snapshot, cliente_rfc_snapshot,
             nivel_cliente_id, descuento_cliente_pct_snapshot, fecha_venta, moneda_id, tipo_cambio_a_base,
             condicion_pago, dias_credito, fecha_vencimiento, estado, subtotal, descuento_total,
             impuesto_total, total, observaciones, created_by)
         VALUES
            (:folio, :cliente_id, :cotizacion_id, :apartado_id, :cliente_nombre, :cliente_rfc,
             :nivel_cliente_id, :descuento_cliente, NOW(), :moneda_id, :tipo_cambio,
             :condicion_pago, :dias_credito, :fecha_vencimiento, 'BORRADOR', :subtotal, :descuento_total,
             :impuesto_total, :total, :observaciones, :created_by)"
    );
    $stmtVenta->execute([
        ':folio' => $folioTmp,
        ':cliente_id' => $clienteId,
        ':cotizacion_id' => $cotizacionId,
        ':apartado_id' => $apartadoId,
        ':cliente_nombre' => $clienteNombreSnapshot,
        ':cliente_rfc' => $clienteRfcSnapshot,
        ':nivel_cliente_id' => $nivelClienteId,
        ':descuento_cliente' => round($descuentoClienteSnapshot, 6),
        ':moneda_id' => $monedaId,
        ':tipo_cambio' => round($tipoCambio, 8),
        ':condicion_pago' => $condicionPago,
        ':dias_credito' => $diasCredito,
        ':fecha_vencimiento' => $fechaVencimiento,
        ':subtotal' => round($subtotal, 4),
        ':descuento_total' => round($descuentoTotal, 4),
        ':impuesto_total' => round($impuestoTotal, 4),
        ':total' => round($total, 4),
        ':observaciones' => $observaciones,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $ventaId = (int) $conexion->lastInsertId();
    $folio = 'VTA-' . str_pad((string) $ventaId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE ventas SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $ventaId]);

    $existenciasBloqueadas = ven_bloquear_y_validar_existencias($conexion, $detalles, $apartadoId !== null);

    $insertDetalle = $conexion->prepare(
        "INSERT INTO ventas_detalle
            (venta_id, renglon, almacen_id, producto_id, presentacion_id, producto_nombre_snapshot, sku_snapshot,
             unidad_id, unidad_nombre_snapshot, cantidad, factor_a_unidad_base, cantidad_base, nivel_precio_snapshot,
             precio_unitario, descuento_pct, descuento_importe, tasa_impuesto_id, impuesto_pct_snapshot,
             subtotal, impuesto_importe, total, costo_unitario_base_snapshot)
         VALUES
            (:venta_id, :renglon, :almacen_id, :producto_id, :presentacion_id, :producto_nombre, :sku,
             :unidad_id, :unidad_nombre, :cantidad, :factor, :cantidad_base, :nivel_precio,
             :precio, :descuento_pct, :descuento_importe, :tasa_impuesto_id, :impuesto_pct,
             :subtotal, :impuesto_importe, :total, :costo)"
    );

    foreach ($detalles as &$d) {
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        $costo = null;
        if ((int) ($d['controla_inventario'] ?? 1) === 1 && isset($existenciasBloqueadas[$clave])) {
            $costo = $existenciasBloqueadas[$clave]['costo_promedio_base'];
            $costo = $costo !== null ? (float) $costo : null;
        }
        $d['costo_unitario_base_snapshot'] = $costo;

        $insertDetalle->execute([
            ':venta_id' => $ventaId,
            ':renglon' => (int) $d['renglon'],
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':presentacion_id' => $d['presentacion_id'],
            ':producto_nombre' => (string) $d['producto_nombre_snapshot'],
            ':sku' => (string) $d['sku_snapshot'],
            ':unidad_id' => (int) $d['unidad_id'],
            ':unidad_nombre' => (string) $d['unidad_nombre_snapshot'],
            ':cantidad' => (float) $d['cantidad'],
            ':factor' => (float) $d['factor_a_unidad_base'],
            ':cantidad_base' => (float) $d['cantidad_base'],
            ':nivel_precio' => (string) $d['nivel_precio_snapshot'],
            ':precio' => (float) $d['precio_unitario'],
            ':descuento_pct' => (float) $d['descuento_pct'],
            ':descuento_importe' => (float) $d['descuento_importe'],
            ':tasa_impuesto_id' => $d['tasa_impuesto_id'],
            ':impuesto_pct' => (float) $d['impuesto_pct_snapshot'],
            ':subtotal' => (float) $d['subtotal'],
            ':impuesto_importe' => (float) $d['impuesto_importe'],
            ':total' => (float) $d['total'],
            ':costo' => $costo,
        ]);
    }
    unset($d);

    $movimientoId = ven_aplicar_salida_inventario(
        $conexion,
        $ventaId,
        $folio,
        $detalles,
        $existenciasBloqueadas,
        $apartadoId !== null
    );

    $pagoVentaId = null;
    $cuentaCobrarId = null;

    if ($condicionPago === 'CONTADO' && $saldoDespuesAnticipo > 0.0001) {
        $pagoVentaId = ven_insertar_pago_venta(
            $conexion,
            $ventaId,
            $monedaId,
            $tipoCambio,
            $metodoPagoId,
            $saldoDespuesAnticipo,
            $referenciaPago
        );
    }

    if ($condicionPago === 'CREDITO') {
        $cuentaCobrarId = ven_generar_cuenta_por_cobrar(
            $conexion,
            $ventaId,
            $folio,
            (int) $clienteId,
            $monedaId,
            $saldoDespuesAnticipo,
            (string) $fechaVencimiento,
            $importeAnticipado
        );
    }

    $conexion->prepare("UPDATE ventas SET estado = 'CONFIRMADA' WHERE id = :id")
        ->execute([':id' => $ventaId]);

    if ($cotizacionId !== null && $apartadoId === null) {
        $conexion->prepare("UPDATE cotizaciones SET estado = 'CONVERTIDA' WHERE id = :id AND estado = 'ACEPTADA'")
            ->execute([':id' => $cotizacionId]);
    }

    if ($apartadoId !== null) {
        $conexion->prepare("UPDATE apartados SET estado = 'COMPLETADO' WHERE id = :id AND estado = 'ACTIVO'")
            ->execute([':id' => $apartadoId]);
    }

    ven_auditar($conexion, 'VENTA_CONFIRMADA', 'ventas', $ventaId, 'Se confirmó la venta ' . $folio . ' y se aplicó su salida de inventario.', null, [
        'folio' => $folio,
        'origen' => $origen,
        'cotizacion_id' => $cotizacionId,
        'apartado_id' => $apartadoId,
        'cliente_id' => $clienteId,
        'condicion_pago' => $condicionPago,
        'total' => round($total, 4),
        'importe_anticipado_aplicado' => round($importeAnticipado, 4),
        'saldo_posterior_anticipo' => $saldoDespuesAnticipo,
        'movimiento_inventario_id' => $movimientoId,
        'pago_venta_id' => $pagoVentaId,
        'cuenta_por_cobrar_id' => $cuentaCobrarId,
    ]);

    $conexion->commit();

    si_responder_json(true, 'Venta confirmada correctamente.', [
        'venta_id' => $ventaId,
        'folio' => $folio,
        'movimiento_inventario_id' => $movimientoId,
        'pago_venta_id' => $pagoVentaId,
        'cuenta_por_cobrar_id' => $cuentaCobrarId,
    ], 201);
}

/* =========================================================================
   CANCELACIÓN / REVERSO
   ========================================================================= */

function ven_cancelar_venta(PDO $conexion): void
{
    $ventaId = ven_id($_POST['venta_id'] ?? null, 'venta');
    $motivo = ven_texto($_POST['motivo'] ?? '', 1500);
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo de cancelación de al menos 5 caracteres.', ['campo' => 'motivo'], 422);
    }

    $conexion->beginTransaction();

    $venta = ven_bloquear_venta($conexion, $ventaId);
    if (!$venta) {
        ven_cancelar($conexion, 'La venta ya no existe.', 404);
    }
    if ($venta['estado'] === 'CANCELADA') {
        $conexion->commit();
        si_responder_json(true, 'La venta ya estaba cancelada.');
    }
    if ($venta['estado'] !== 'CONFIRMADA') {
        ven_cancelar($conexion, 'Solo una venta CONFIRMADA puede cancelarse desde este flujo.', 409);
    }

    if ($venta['apartado_id'] !== null) {
        $stmtApa = $conexion->prepare("SELECT id, folio, estado FROM apartados WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmtApa->execute([':id' => (int) $venta['apartado_id']]);
        $apartadoOrigen = $stmtApa->fetch();

        $stmtAnt = $conexion->prepare(
            "SELECT id, importe
             FROM anticipos_apartado
             WHERE apartado_id = :id AND estado = 'APLICADO'
             ORDER BY id ASC
             FOR UPDATE"
        );
        $stmtAnt->execute([':id' => (int) $venta['apartado_id']]);
        $anticipos = 0.0;
        foreach ($stmtAnt->fetchAll() as $anticipo) {
            $anticipos += (float) $anticipo['importe'];
        }

        if ($anticipos > 0.0001) {
            ven_cancelar(
                $conexion,
                'La venta proviene de un apartado con anticipos aplicados. No se canceló para evitar dejar dinero sin regularizar. Ese caso se atenderá mediante el flujo financiero/devolución correspondiente.',
                409,
                ['importe_anticipado' => round($anticipos, 4)]
            );
        }
    } else {
        $apartadoOrigen = null;
    }

    $stmtCxc = $conexion->prepare("SELECT * FROM cuentas_por_cobrar WHERE venta_id = :id LIMIT 1 FOR UPDATE");
    $stmtCxc->execute([':id' => $ventaId]);
    $cxc = $stmtCxc->fetch();
    if ($cxc && (float) $cxc['importe_pagado'] > 0.0001) {
        ven_cancelar(
            $conexion,
            'La cuenta por cobrar de esta venta ya tiene abonos. Regulariza primero esos movimientos financieros antes de cancelar la venta.',
            409,
            ['cuenta_por_cobrar_id' => (int) $cxc['id'], 'importe_pagado' => (float) $cxc['importe_pagado']]
        );
    }

    $stmtMov = $conexion->prepare(
        "SELECT mi.*
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
         WHERE mi.origen_tipo = 'VENTA'
           AND mi.origen_id = :id
           AND t.codigo = 'SALIDA_VENTA'
         ORDER BY mi.id ASC
         LIMIT 1
         FOR UPDATE"
    );
    $stmtMov->execute([':id' => $ventaId]);
    $movOriginal = $stmtMov->fetch();

    if ($movOriginal) {
        if ($movOriginal['estado'] !== 'APLICADO') {
            ven_cancelar($conexion, 'El movimiento de inventario original no está APLICADO. No se canceló la venta para evitar inconsistencias.', 409);
        }

        $stmtDet = $conexion->prepare(
            "SELECT *
             FROM movimientos_inventario_detalle
             WHERE movimiento_id = :id
             ORDER BY renglon ASC
             FOR UPDATE"
        );
        $stmtDet->execute([':id' => (int) $movOriginal['id']]);
        $movDetalles = $stmtDet->fetchAll();

        $tipoReverso = ven_tipo_movimiento($conexion, 'REVERSO');
        if (!$tipoReverso) {
            ven_cancelar($conexion, 'No está configurado el tipo de movimiento REVERSO.', 500);
        }

        $movReversoId = ven_crear_movimiento(
            $conexion,
            (int) $tipoReverso['id'],
            'CANCELACION_VENTA',
            $ventaId,
            'REVERSO_VENTA:' . $ventaId,
            (int) $movOriginal['id'],
            $motivo
        );

        $stmtRev = $conexion->prepare(
            "INSERT INTO movimientos_inventario_detalle
                (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes,
                 existencia_despues, costo_unitario_base, observaciones)
             VALUES
                (:movimiento_id, :renglon, :almacen_id, :producto_id, :cantidad_delta, :antes,
                 :despues, :costo, :observaciones)"
        );

        foreach ($movDetalles as $indice => $d) {
            $existencia = ven_bloquear_existencia($conexion, (int) $d['almacen_id'], (int) $d['producto_id']);
            $antes = (float) $existencia['existencia_fisica'];
            $delta = -((float) $d['cantidad_delta']);
            $despues = round($antes + $delta, 6);

            $conexion->prepare("UPDATE existencias_almacen SET existencia_fisica = :fisica WHERE id = :id")
                ->execute([':fisica' => $despues, ':id' => (int) $existencia['id']]);

            $stmtRev->execute([
                ':movimiento_id' => $movReversoId,
                ':renglon' => $indice + 1,
                ':almacen_id' => (int) $d['almacen_id'],
                ':producto_id' => (int) $d['producto_id'],
                ':cantidad_delta' => $delta,
                ':antes' => $antes,
                ':despues' => $despues,
                ':costo' => $d['costo_unitario_base'],
                ':observaciones' => 'Reverso de ' . $venta['folio'],
            ]);
        }

        $conexion->prepare(
            "UPDATE movimientos_inventario
             SET estado = 'APLICADO', aplicado_at = NOW(), aplicado_by = :usuario
             WHERE id = :id"
        )->execute([':usuario' => (int) $_SESSION['usuario_id'], ':id' => $movReversoId]);

        $conexion->prepare("UPDATE movimientos_inventario SET estado = 'REVERTIDO' WHERE id = :id")
            ->execute([':id' => (int) $movOriginal['id']]);
    } else {
        $movReversoId = null;
    }

    $conexion->prepare(
        "UPDATE pagos_venta
         SET estado = 'CANCELADO', motivo_cancelacion = :motivo, cancelado_at = NOW(), cancelado_by = :usuario
         WHERE venta_id = :venta_id AND estado = 'APLICADO'"
    )->execute([
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':venta_id' => $ventaId,
    ]);

    if ($cxc) {
        $conexion->prepare("UPDATE cuentas_por_cobrar SET estado = 'CANCELADA' WHERE id = :id")
            ->execute([':id' => (int) $cxc['id']]);
    }

    /*
     * Un apartado ya consumido no puede volver a utilizarse porque la relación
     * venta-apartado es histórica y única. Si la venta se cancela (y no había
     * anticipos, validado arriba), el apartado también queda CANCELADO y la
     * existencia restaurada queda disponible; no se recrea una reserva fantasma.
     */
    if (is_array($apartadoOrigen)) {
        $motivoApartado = 'Cancelado por reverso de la venta ' . $venta['folio'] . ': ' . $motivo;
        $conexion->prepare(
            "UPDATE apartados
             SET estado = 'CANCELADO',
                 motivo_cancelacion = :motivo,
                 cancelado_at = NOW(),
                 cancelado_by = :usuario
             WHERE id = :id"
        )->execute([
            ':motivo' => mb_substr($motivoApartado, 0, 3000),
            ':usuario' => (int) $_SESSION['usuario_id'],
            ':id' => (int) $apartadoOrigen['id'],
        ]);
    }

    $conexion->prepare(
        "UPDATE tokens_qr_venta
         SET activo = 0, revocado_at = NOW(), revocado_by = :usuario, motivo_revocacion = :motivo
         WHERE venta_id = :venta_id AND activo = 1"
    )->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':motivo' => mb_substr($motivo, 0, 255),
        ':venta_id' => $ventaId,
    ]);

    $conexion->prepare(
        "UPDATE ventas
         SET estado = 'CANCELADA', motivo_cancelacion = :motivo, cancelada_at = NOW(), cancelada_by = :usuario
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $ventaId,
    ]);

    ven_auditar($conexion, 'VENTA_CANCELADA_REVERTIDA', 'ventas', $ventaId, 'Se canceló la venta ' . $venta['folio'] . ' y se revirtió su salida de inventario.', [
        'estado' => 'CONFIRMADA',
    ], [
        'estado' => 'CANCELADA',
        'motivo' => $motivo,
        'movimiento_reverso_id' => $movReversoId,
        'cuenta_por_cobrar_cancelada' => $cxc ? (int) $cxc['id'] : null,
    ]);

    $conexion->commit();

    si_responder_json(true, 'Venta cancelada. El inventario fue revertido y el historial se conservó.', [
        'movimiento_reverso_id' => $movReversoId,
    ]);
}

/* =========================================================================
   NORMALIZACIÓN DE PRODUCTOS / PRECIOS
   ========================================================================= */

function ven_normalizar_lineas_directas(PDO $conexion, array $lineas, ?array $cliente, int $monedaId, int $almacenId): array
{
    if (count($lineas) > 200) {
        ven_cancelar($conexion, 'Una venta no puede contener más de 200 renglones.', 422);
    }

    $descuentoCliente = $cliente ? round((float) $cliente['descuento_efectivo_pct'], 6) : 0.0;
    if ($descuentoCliente < 0 || $descuentoCliente > 100) {
        ven_cancelar($conexion, 'El descuento configurado para el cliente no es válido.', 409);
    }

    $detalles = [];
    $claves = [];
    $subtotalHeader = 0.0;
    $descuentoHeader = 0.0;
    $impuestoHeader = 0.0;
    $totalHeader = 0.0;

    foreach ($lineas as $indice => $entrada) {
        if (!is_array($entrada)) {
            ven_cancelar($conexion, 'Uno de los renglones de productos no es válido.', 422);
        }

        try {
            $productoId = ven_id_local($entrada['producto_id'] ?? null, 'producto');
            $presentacionId = ven_entero_rango($entrada['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
            $cantidad = ven_decimal_local($entrada['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0);
            $precio = ven_decimal_local($entrada['precio_unitario'] ?? null, 'precio', 0.0001, 999999999999.0);
            $precioVentaId = ven_entero_rango($entrada['precio_venta_id'] ?? 0, 0, PHP_INT_MAX, 0);
        } catch (InvalidArgumentException $e) {
            ven_cancelar($conexion, $e->getMessage(), 422);
        }

        $clave = $productoId . ':' . $presentacionId;
        if (isset($claves[$clave])) {
            ven_cancelar($conexion, 'No repitas el mismo producto con la misma presentación. Ajusta la cantidad del renglón existente.', 422);
        }
        $claves[$clave] = true;

        $producto = ven_producto_activo($conexion, $productoId);
        if (!$producto) {
            ven_cancelar($conexion, 'Uno de los productos ya no está disponible.', 409);
        }

        if ((int) $producto['permite_fraccion'] !== 1 && abs($cantidad - round($cantidad)) > 0.000001) {
            ven_cancelar($conexion, 'El producto ' . $producto['nombre'] . ' no permite cantidades fraccionadas.', 422);
        }

        if ($presentacionId > 0) {
            $stmtPres = $conexion->prepare(
                "SELECT pp.id, pp.unidad_id, pp.nombre, pp.factor_a_unidad_base, u.nombre AS unidad_nombre
                 FROM presentaciones_producto pp
                 INNER JOIN unidades_medida u ON u.id = pp.unidad_id
                 WHERE pp.id = :id AND pp.producto_id = :producto_id AND pp.es_venta = 1 AND pp.activo = 1 AND u.activo = 1
                 LIMIT 1"
            );
            $stmtPres->execute([':id' => $presentacionId, ':producto_id' => $productoId]);
            $presentacion = $stmtPres->fetch();
            if (!$presentacion) {
                ven_cancelar($conexion, 'Una presentación seleccionada ya no está disponible.', 409);
            }
            $unidadId = (int) $presentacion['unidad_id'];
            $unidadNombre = (string) $presentacion['unidad_nombre'];
            $factor = (float) $presentacion['factor_a_unidad_base'];
        } else {
            $unidadId = (int) $producto['unidad_base_id'];
            $unidadNombre = (string) $producto['unidad_base_nombre'];
            $factor = 1.0;
        }

        if ($factor <= 0) {
            ven_cancelar($conexion, 'La conversión de una presentación no es válida.', 409);
        }

        $cantidadBase = round($cantidad * $factor, 6);
        $impuestoPct = (float) $producto['impuesto_pct'];
        $tasaImpuestoId = $producto['tasa_impuesto_id'] !== null ? (int) $producto['tasa_impuesto_id'] : null;
        $nivelPrecio = 'MANUAL';

        /*
         * No confiamos en el id de precio enviado por el navegador. Volvemos a
         * resolver el escalón comercial en servidor y únicamente lo conservamos
         * como MENUDEO/MAYOREO si el id y el importe coinciden con lo vigente.
         * Si el usuario cambió el importe, queda correctamente como MANUAL.
         */
        if ($precioVentaId > 0) {
            $resuelto = ven_resolver_precio($conexion, $productoId, $presentacionId, $monedaId, $cantidad);
            $precioEsperado = $resuelto['datos']['precio'] ?? null;
            $precioEsperadoId = (int) ($resuelto['datos']['precio_venta_id'] ?? 0);

            if (
                $precioEsperado !== null
                && $precioEsperadoId === $precioVentaId
                && abs((float) $precioEsperado - $precio) <= 0.0001
            ) {
                $nivelPrecio = (string) ($resuelto['datos']['nivel_precio'] ?? 'MENUDEO');
                $impuestoPct = (float) ($resuelto['datos']['impuesto_pct'] ?? $impuestoPct);
                $tasaImpuestoId = isset($resuelto['datos']['tasa_impuesto_id'])
                    ? (int) $resuelto['datos']['tasa_impuesto_id']
                    : $tasaImpuestoId;
            } else {
                $precioVentaId = 0;
            }
        }

        $bruto = round($cantidad * $precio, 4);
        $descuentoImporte = round($bruto * ($descuentoCliente / 100), 4);
        $subtotal = round($bruto - $descuentoImporte, 4);
        $impuestoImporte = round($subtotal * ($impuestoPct / 100), 4);
        $total = round($subtotal + $impuestoImporte, 4);

        $subtotalHeader += $subtotal;
        $descuentoHeader += $descuentoImporte;
        $impuestoHeader += $impuestoImporte;
        $totalHeader += $total;

        $detalles[] = [
            'renglon' => $indice + 1,
            'almacen_id' => $almacenId,
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId > 0 ? $presentacionId : null,
            'producto_nombre_snapshot' => (string) $producto['nombre'],
            'sku_snapshot' => (string) $producto['sku'],
            'unidad_id' => $unidadId,
            'unidad_nombre_snapshot' => $unidadNombre,
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $factor,
            'cantidad_base' => $cantidadBase,
            'nivel_precio_snapshot' => $nivelPrecio,
            'precio_unitario' => $precio,
            'descuento_pct' => $descuentoCliente,
            'descuento_importe' => $descuentoImporte,
            'tasa_impuesto_id' => $tasaImpuestoId,
            'impuesto_pct_snapshot' => $impuestoPct,
            'subtotal' => $subtotal,
            'impuesto_importe' => $impuestoImporte,
            'total' => $total,
            'controla_inventario' => (int) $producto['controla_inventario'],
        ];
    }

    return [
        $detalles,
        round($subtotalHeader, 4),
        round($descuentoHeader, 4),
        round($impuestoHeader, 4),
        round($totalHeader, 4),
    ];
}

function ven_resolver_precio(PDO $conexion, int $productoId, int $presentacionId, int $monedaId, float $cantidad): array
{
    $producto = ven_producto_activo($conexion, $productoId);
    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    if ($presentacionId > 0) {
        $stmtPres = $conexion->prepare(
            "SELECT id
             FROM presentaciones_producto
             WHERE id = :id AND producto_id = :producto_id AND es_venta = 1 AND activo = 1
             LIMIT 1"
        );
        $stmtPres->execute([':id' => $presentacionId, ':producto_id' => $productoId]);
        if (!$stmtPres->fetchColumn()) {
            si_responder_json(false, 'La presentación seleccionada no es válida para venta.', [], 422);
        }
    }

    $condicion = $presentacionId > 0 ? 'pv.presentacion_id = :presentacion_id' : 'pv.presentacion_id IS NULL';
    $stmt = $conexion->prepare(
        "SELECT
            pv.id,
            pv.nivel_precio,
            pv.cantidad_minima,
            pv.moneda_id,
            pv.precio_unitario,
            pv.tasa_impuesto_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            COALESCE(ti.porcentaje, tip.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, tip.nombre, 'Sin impuesto') AS impuesto_nombre
         FROM precios_venta_producto pv
         INNER JOIN productos p ON p.id = pv.producto_id
         INNER JOIN monedas m ON m.id = pv.moneda_id AND m.activo = 1
         LEFT JOIN tasas_impuesto ti ON ti.id = pv.tasa_impuesto_id
         LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
         WHERE pv.producto_id = :producto_id
           AND {$condicion}
           AND pv.activo = 1
           AND (pv.nivel_precio = 'MENUDEO' OR pv.cantidad_minima <= :cantidad)
           AND pv.vigente_desde <= NOW()
           AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
         ORDER BY CASE pv.nivel_precio WHEN 'MAYOREO' THEN 0 ELSE 1 END,
                  CASE WHEN pv.nivel_precio = 'MAYOREO' THEN pv.cantidad_minima ELSE 1 END DESC,
                  pv.id DESC"
    );
    $params = [':producto_id' => $productoId, ':cantidad' => $cantidad];
    if ($presentacionId > 0) {
        $params[':presentacion_id'] = $presentacionId;
    }
    $stmt->execute($params);
    $candidatos = $stmt->fetchAll();

    $base = [
        'precio' => null,
        'precio_venta_id' => 0,
        'nivel_precio' => 'MANUAL',
        'origen_precio' => 'MANUAL',
        'impuesto_pct' => (float) $producto['impuesto_pct'],
        'impuesto_nombre' => (string) $producto['impuesto_nombre'],
        'tasa_impuesto_id' => $producto['tasa_impuesto_id'] !== null ? (int) $producto['tasa_impuesto_id'] : null,
    ];

    if (!$candidatos) {
        return [
            'mensaje' => 'No hay precio vigente para esta presentación. Puedes capturarlo manualmente o configurarlo en Productos / Catálogos.',
            'datos' => $base,
        ];
    }

    usort($candidatos, static function (array $a, array $b) use ($monedaId): int {
        $aMay = (string) $a['nivel_precio'] === 'MAYOREO' ? 1 : 0;
        $bMay = (string) $b['nivel_precio'] === 'MAYOREO' ? 1 : 0;
        if ($aMay !== $bMay) {
            return $bMay <=> $aMay;
        }
        if ($aMay === 1) {
            $cmp = (float) $b['cantidad_minima'] <=> (float) $a['cantidad_minima'];
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        $aMisma = (int) $a['moneda_id'] === $monedaId ? 1 : 0;
        $bMisma = (int) $b['moneda_id'] === $monedaId ? 1 : 0;
        if ($aMisma !== $bMisma) {
            return $bMisma <=> $aMisma;
        }
        return (int) $b['id'] <=> (int) $a['id'];
    });

    $elegido = $candidatos[0];
    $origenABase = ven_tipo_cambio_a_base($conexion, (int) $elegido['moneda_id'], date('Y-m-d'));
    $destinoABase = ven_tipo_cambio_a_base($conexion, $monedaId, date('Y-m-d'));
    if ($origenABase === null || $destinoABase === null || $destinoABase <= 0) {
        return [
            'mensaje' => 'Existe un precio configurado, pero falta un tipo de cambio para expresarlo en la moneda de la venta.',
            'datos' => $base,
        ];
    }

    $precio = ((float) $elegido['precio_unitario'] * $origenABase) / $destinoABase;

    return [
        'mensaje' => 'Precio sugerido automáticamente.',
        'datos' => [
            'precio' => round($precio, 4),
            'precio_venta_id' => (int) $elegido['id'],
            'nivel_precio' => (string) $elegido['nivel_precio'],
            'origen_precio' => (int) $elegido['moneda_id'] === $monedaId ? 'CONFIGURADO' : 'CONVERTIDO',
            'moneda_origen' => (string) $elegido['moneda_codigo'],
            'precio_origen' => (float) $elegido['precio_unitario'],
            'cantidad_minima' => (string) $elegido['nivel_precio'] === 'MENUDEO' ? 1.0 : (float) $elegido['cantidad_minima'],
            'impuesto_pct' => (float) $elegido['impuesto_pct'],
            'impuesto_nombre' => (string) $elegido['impuesto_nombre'],
            'tasa_impuesto_id' => $elegido['tasa_impuesto_id'] !== null ? (int) $elegido['tasa_impuesto_id'] : null,
        ],
    ];
}

/* =========================================================================
   INVENTARIO
   ========================================================================= */

function ven_bloquear_y_validar_existencias(PDO $conexion, array $detalles, bool $desdeApartado): array
{
    $grupos = [];
    foreach ($detalles as $d) {
        if ((int) ($d['controla_inventario'] ?? 1) !== 1) {
            continue;
        }
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'almacen_id' => (int) $d['almacen_id'],
                'producto_id' => (int) $d['producto_id'],
                'producto_nombre' => (string) $d['producto_nombre_snapshot'],
                'cantidad_base' => 0.0,
            ];
        }
        $grupos[$clave]['cantidad_base'] = round($grupos[$clave]['cantidad_base'] + (float) $d['cantidad_base'], 6);
    }

    uasort($grupos, static function (array $a, array $b): int {
        return [(int) $a['almacen_id'], (int) $a['producto_id']] <=> [(int) $b['almacen_id'], (int) $b['producto_id']];
    });

    $bloqueadas = [];
    foreach ($grupos as $clave => $g) {
        $existencia = ven_bloquear_existencia($conexion, $g['almacen_id'], $g['producto_id']);
        $necesario = (float) $g['cantidad_base'];
        $fisica = (float) $existencia['existencia_fisica'];
        $reservada = (float) $existencia['cantidad_reservada'];
        $disponible = (float) $existencia['cantidad_disponible'];

        if ($desdeApartado) {
            if ($fisica + 0.000001 < $necesario) {
                ven_cancelar($conexion, 'La existencia física ya no alcanza para surtir ' . $g['producto_nombre'] . '.', 409, [
                    'producto_id' => $g['producto_id'],
                    'existencia_fisica' => round($fisica, 6),
                    'requerido_base' => round($necesario, 6),
                ]);
            }
            if ($reservada + 0.000001 < $necesario) {
                ven_cancelar($conexion, 'La reserva almacenada ya no alcanza para surtir ' . $g['producto_nombre'] . '. Revisa la integridad del apartado antes de vender.', 409, [
                    'producto_id' => $g['producto_id'],
                    'cantidad_reservada' => round($reservada, 6),
                    'requerido_base' => round($necesario, 6),
                ]);
            }
            $fisicaFinal = round($fisica - $necesario, 6);
            $reservadaFinal = round(max(0, $reservada - $necesario), 6);
            if ($fisicaFinal + 0.000001 < $reservadaFinal) {
                ven_cancelar($conexion, 'La venta dejaría la existencia física por debajo de otras reservas vigentes para ' . $g['producto_nombre'] . '.', 409);
            }
        } else {
            if ($disponible + 0.000001 < $necesario) {
                ven_cancelar($conexion, 'No hay existencia disponible suficiente para ' . $g['producto_nombre'] . '.', 409, [
                    'producto_id' => $g['producto_id'],
                    'disponible_base' => round($disponible, 6),
                    'requerido_base' => round($necesario, 6),
                ]);
            }
        }

        $existencia['cantidad_requerida'] = $necesario;
        $bloqueadas[$clave] = $existencia;
    }

    return $bloqueadas;
}

function ven_aplicar_salida_inventario(
    PDO $conexion,
    int $ventaId,
    string $folioVenta,
    array $detalles,
    array $existenciasBloqueadas,
    bool $desdeApartado
): ?int {
    $controlados = array_values(array_filter($detalles, static fn(array $d): bool => (int) ($d['controla_inventario'] ?? 1) === 1));
    if (!$controlados) {
        return null;
    }

    $tipoSalida = ven_tipo_movimiento($conexion, 'SALIDA_VENTA');
    if (!$tipoSalida) {
        ven_cancelar($conexion, 'No está configurado el tipo de movimiento SALIDA_VENTA.', 500);
    }

    $movimientoId = ven_crear_movimiento(
        $conexion,
        (int) $tipoSalida['id'],
        'VENTA',
        $ventaId,
        'VENTA:' . $ventaId,
        null,
        'Salida física por ' . $folioVenta
    );

    $estadoLocal = [];
    foreach ($existenciasBloqueadas as $clave => $e) {
        $estadoLocal[$clave] = [
            'id' => (int) $e['id'],
            'fisica' => (float) $e['existencia_fisica'],
            'reservada' => (float) $e['cantidad_reservada'],
            'costo' => $e['costo_promedio_base'] !== null ? (float) $e['costo_promedio_base'] : null,
        ];
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes,
             existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento_id, :renglon, :almacen_id, :producto_id, :delta, :antes,
             :despues, :costo, :observaciones)"
    );

    $renglonMov = 1;
    foreach ($detalles as $d) {
        if ((int) ($d['controla_inventario'] ?? 1) !== 1) {
            continue;
        }
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        if (!isset($estadoLocal[$clave])) {
            ven_cancelar($conexion, 'No se encontró la existencia bloqueada para aplicar la venta.', 500);
        }
        $antes = $estadoLocal[$clave]['fisica'];
        $salida = (float) $d['cantidad_base'];
        $despues = round($antes - $salida, 6);
        if ($despues < -0.000001) {
            ven_cancelar($conexion, 'La salida de inventario resultó negativa. No se confirmó la venta.', 409);
        }
        $estadoLocal[$clave]['fisica'] = $despues;

        $stmtDet->execute([
            ':movimiento_id' => $movimientoId,
            ':renglon' => $renglonMov++,
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':delta' => -$salida,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $estadoLocal[$clave]['costo'],
            ':observaciones' => 'Salida por ' . $folioVenta . ' · renglón ' . (int) $d['renglon'],
        ]);
    }

    foreach ($estadoLocal as $clave => $e) {
        $reservadaFinal = $e['reservada'];
        if ($desdeApartado) {
            $reservadaFinal = round(max(0, $e['reservada'] - (float) $existenciasBloqueadas[$clave]['cantidad_requerida']), 6);
        }

        $conexion->prepare(
            "UPDATE existencias_almacen
             SET existencia_fisica = :fisica, cantidad_reservada = :reservada
             WHERE id = :id"
        )->execute([
            ':fisica' => $e['fisica'],
            ':reservada' => $reservadaFinal,
            ':id' => $e['id'],
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET estado = 'APLICADO', aplicado_at = NOW(), aplicado_by = :usuario
         WHERE id = :id"
    )->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $movimientoId,
    ]);

    return $movimientoId;
}

function ven_bloquear_existencia(PDO $conexion, int $almacenId, int $productoId): array
{
    $stmtInsert = $conexion->prepare(
        "INSERT IGNORE INTO existencias_almacen
            (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden, costo_promedio_base)
         VALUES
            (:almacen_id, :producto_id, 0, 0, 0, NULL, NULL)"
    );
    $stmtInsert->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);

    $stmt = $conexion->prepare(
        "SELECT *
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id AND producto_id = :producto_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);
    $fila = $stmt->fetch();

    if (!$fila) {
        ven_cancelar($conexion, 'No fue posible bloquear la existencia del producto.', 500);
    }

    return $fila;
}

function ven_tipo_movimiento(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre
         FROM tipos_movimiento_inventario
         WHERE codigo = :codigo AND activo = 1
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

function ven_crear_movimiento(
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
        ven_cancelar($conexion, 'Esta operación de inventario ya fue procesada. Recarga la página antes de continuar.', 409);
    }

    $tmp = 'TMP-MOV-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id,
             idempotency_key, movimiento_revertido_id, motivo, observaciones, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'BORRADOR', :origen_tipo, :origen_id,
             :clave, :revertido_id, :motivo, NULL, :usuario)"
    );
    $stmt->execute([
        ':folio' => $tmp,
        ':tipo' => $tipoMovimientoId,
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $origenId,
        ':clave' => $idempotencyKey,
        ':revertido_id' => $movimientoRevertidoId,
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'MOV-' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE movimientos_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);

    return $id;
}

/* =========================================================================
   FINANZAS DE VENTA
   ========================================================================= */

function ven_insertar_pago_venta(
    PDO $conexion,
    int $ventaId,
    int $monedaId,
    float $tipoCambio,
    int $metodoId,
    float $importe,
    ?string $referencia
): int {
    $stmt = $conexion->prepare(
        "INSERT INTO pagos_venta
            (venta_id, fecha_pago, metodo_pago_id, moneda_id, tipo_cambio_a_base, importe,
             referencia, estado, created_by)
         VALUES
            (:venta_id, NOW(), :metodo_id, :moneda_id, :tipo_cambio, :importe,
             :referencia, 'APLICADO', :usuario)"
    );
    $stmt->execute([
        ':venta_id' => $ventaId,
        ':metodo_id' => $metodoId,
        ':moneda_id' => $monedaId,
        ':tipo_cambio' => round($tipoCambio, 8),
        ':importe' => round($importe, 4),
        ':referencia' => $referencia,
        ':usuario' => (int) $_SESSION['usuario_id'],
    ]);

    return (int) $conexion->lastInsertId();
}

function ven_generar_cuenta_por_cobrar(
    PDO $conexion,
    int $ventaId,
    string $folioVenta,
    int $clienteId,
    int $monedaId,
    float $importeFinanciado,
    string $fechaVencimiento,
    float $anticipoAplicado
): int {
    $stmtExiste = $conexion->prepare("SELECT id FROM cuentas_por_cobrar WHERE venta_id = :id LIMIT 1 FOR UPDATE");
    $stmtExiste->execute([':id' => $ventaId]);
    if ($stmtExiste->fetchColumn()) {
        ven_cancelar($conexion, 'La venta ya tiene una cuenta por cobrar asociada.', 409);
    }

    $folioTmp = 'TMP-CXC-' . bin2hex(random_bytes(10));
    $estado = $fechaVencimiento < date('Y-m-d') ? 'VENCIDA' : 'PENDIENTE';
    $observaciones = 'Generada automáticamente desde ' . $folioVenta . '.';
    if ($anticipoAplicado > 0.0001) {
        $observaciones .= ' El importe financiado ya considera $' . number_format($anticipoAplicado, 2) . ' de anticipos del apartado.';
    }

    $stmt = $conexion->prepare(
        "INSERT INTO cuentas_por_cobrar
            (folio, venta_id, cliente_id, moneda_id, importe_original, importe_pagado,
             fecha_documento, fecha_vencimiento, estado, observaciones)
         VALUES
            (:folio, :venta_id, :cliente_id, :moneda_id, :importe, 0,
             :fecha_documento, :fecha_vencimiento, :estado, :observaciones)"
    );
    $stmt->execute([
        ':folio' => $folioTmp,
        ':venta_id' => $ventaId,
        ':cliente_id' => $clienteId,
        ':moneda_id' => $monedaId,
        ':importe' => round($importeFinanciado, 4),
        ':fecha_documento' => date('Y-m-d'),
        ':fecha_vencimiento' => $fechaVencimiento,
        ':estado' => $estado,
        ':observaciones' => $observaciones,
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'CXC-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE cuentas_por_cobrar SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);

    ven_auditar($conexion, 'CUENTA_POR_COBRAR_GENERADA', 'cuentas_por_cobrar', $id, 'Se generó automáticamente una cuenta por cobrar desde una venta a crédito.', null, [
        'folio' => $folio,
        'venta_id' => $ventaId,
        'cliente_id' => $clienteId,
        'importe_original' => round($importeFinanciado, 4),
        'fecha_vencimiento' => $fechaVencimiento,
    ]);

    return $id;
}

function ven_validar_credito_cliente(PDO $conexion, array $cliente, float $nuevoSaldo, float $tipoCambioVenta): void
{
    $dias = (int) $cliente['dias_credito'];
    if ($dias <= 0) {
        ven_cancelar($conexion, 'El cliente no tiene días de crédito configurados.', 422);
    }

    if ($cliente['limite_credito'] === null) {
        ven_cancelar($conexion, 'El cliente no tiene un límite de crédito configurado.', 422);
    }

    $limite = (float) $cliente['limite_credito'];
    if ($limite <= 0) {
        ven_cancelar($conexion, 'El límite de crédito del cliente no permite una venta a crédito.', 422);
    }
    if ($tipoCambioVenta <= 0) {
        ven_cancelar($conexion, 'No es posible validar el crédito sin un tipo de cambio válido.', 409);
    }

    // El límite de crédito se controla en la moneda base del sistema.
    $stmt = $conexion->prepare(
        "SELECT COALESCE(SUM(cx.saldo_pendiente * v.tipo_cambio_a_base), 0)
         FROM cuentas_por_cobrar cx
         INNER JOIN ventas v ON v.id = cx.venta_id
         WHERE cx.cliente_id = :cliente_id
           AND cx.estado IN ('PENDIENTE','PARCIAL','VENCIDA')"
    );
    $stmt->execute([':cliente_id' => (int) $cliente['id']]);
    $saldoActualBase = (float) $stmt->fetchColumn();
    $nuevoSaldoBase = round($nuevoSaldo * $tipoCambioVenta, 4);

    if ($saldoActualBase + $nuevoSaldoBase > $limite + 0.0001) {
        ven_cancelar($conexion, 'La venta supera el crédito disponible del cliente.', 409, [
            'limite_credito_base' => round($limite, 4),
            'saldo_actual_base' => round($saldoActualBase, 4),
            'credito_disponible_base' => round(max(0, $limite - $saldoActualBase), 4),
            'nuevo_saldo_base' => round($nuevoSaldoBase, 4),
        ]);
    }
}

/* =========================================================================
   APARTADOS VENCIDOS
   ========================================================================= */

function ven_procesar_apartados_vencidos(PDO $conexion): void
{
    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }

    try {
        $stmt = $conexion->query(
            "SELECT id, folio
             FROM apartados
             WHERE estado = 'ACTIVO'
               AND reservado_hasta IS NOT NULL
               AND reservado_hasta < NOW()
             ORDER BY id ASC
             FOR UPDATE"
        );
        $vencidos = $stmt->fetchAll();

        foreach ($vencidos as $a) {
            $apartadoId = (int) $a['id'];
            $det = $conexion->prepare(
                "SELECT almacen_id, producto_id, SUM(cantidad_base) AS cantidad_base
                 FROM apartados_detalle
                 WHERE apartado_id = :id
                 GROUP BY almacen_id, producto_id
                 ORDER BY almacen_id ASC, producto_id ASC"
            );
            $det->execute([':id' => $apartadoId]);

            foreach ($det->fetchAll() as $d) {
                $e = ven_bloquear_existencia($conexion, (int) $d['almacen_id'], (int) $d['producto_id']);
                $nueva = round(max(0, (float) $e['cantidad_reservada'] - (float) $d['cantidad_base']), 6);
                $conexion->prepare("UPDATE existencias_almacen SET cantidad_reservada = :cantidad WHERE id = :id")
                    ->execute([':cantidad' => $nueva, ':id' => (int) $e['id']]);
            }

            $conexion->prepare("UPDATE apartados SET estado = 'VENCIDO' WHERE id = :id AND estado = 'ACTIVO'")
                ->execute([':id' => $apartadoId]);

            ven_auditar_sistema($conexion, 'APARTADO_VENCIDO', 'apartados', $apartadoId, 'El apartado ' . $a['folio'] . ' venció y su reserva fue liberada automáticamente.', ['estado' => 'ACTIVO'], ['estado' => 'VENCIDO']);
        }

        if ($propia) {
            $conexion->commit();
        }
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

/* =========================================================================
   CONSULTAS AUXILIARES
   ========================================================================= */

function ven_cliente_activo(PDO $conexion, int $id, bool $bloquear = false): ?array
{
    $lock = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE c.id = :id AND c.deleted_at IS NULL AND c.activo = 1
         LIMIT 1{$lock}"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    ven_tipar_cliente($fila);
    return $fila;
}

function ven_producto_activo(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            p.*,
            u.codigo AS unidad_base_codigo,
            u.nombre AS unidad_base_nombre,
            u.simbolo AS unidad_base_simbolo,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre
         FROM productos p
         INNER JOIN unidades_medida u ON u.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         WHERE p.id = :id AND p.deleted_at IS NULL AND p.activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    $fila['unidad_base_id'] = (int) $fila['unidad_base_id'];
    $fila['tasa_impuesto_id'] = $fila['tasa_impuesto_id'] !== null ? (int) $fila['tasa_impuesto_id'] : null;
    $fila['controla_inventario'] = (int) $fila['controla_inventario'];
    $fila['permite_fraccion'] = (int) $fila['permite_fraccion'];
    $fila['impuesto_pct'] = (float) $fila['impuesto_pct'];
    return $fila;
}

function ven_moneda_activa(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM monedas WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function ven_almacen_activo(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function ven_metodo_pago_activo(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT id, codigo, nombre, requiere_referencia FROM metodos_pago WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    $fila['requiere_referencia'] = (int) $fila['requiere_referencia'];
    return $fila;
}

function ven_bloquear_cotizacion(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT * FROM cotizaciones WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function ven_bloquear_apartado(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT * FROM apartados WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function ven_bloquear_venta(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT * FROM ventas WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function ven_venta_de_cotizacion(PDO $conexion, int $cotizacionId): ?array
{
    $stmt = $conexion->prepare("SELECT id, folio, estado FROM ventas WHERE cotizacion_id = :id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':id' => $cotizacionId]);
    return $stmt->fetch() ?: null;
}

function ven_venta_de_apartado(PDO $conexion, int $apartadoId): ?array
{
    $stmt = $conexion->prepare("SELECT id, folio, estado FROM ventas WHERE apartado_id = :id LIMIT 1");
    $stmt->execute([':id' => $apartadoId]);
    return $stmt->fetch() ?: null;
}

function ven_apartado_de_cotizacion(PDO $conexion, int $cotizacionId): ?array
{
    $stmt = $conexion->prepare("SELECT id, folio, estado FROM apartados WHERE cotizacion_id = :id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':id' => $cotizacionId]);
    return $stmt->fetch() ?: null;
}

function ven_detalles_cotizacion_para_venta(PDO $conexion, int $cotizacionId, int $almacenId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            d.*,
            p.sku,
            p.controla_inventario
         FROM cotizaciones_detalle d
         INNER JOIN productos p ON p.id = d.producto_id
         WHERE d.cotizacion_id = :id
         ORDER BY d.renglon ASC"
    );
    $stmt->execute([':id' => $cotizacionId]);
    $filas = $stmt->fetchAll();

    $resultado = [];
    foreach ($filas as $d) {
        $resultado[] = [
            'renglon' => (int) $d['renglon'],
            'almacen_id' => $almacenId,
            'producto_id' => (int) $d['producto_id'],
            'presentacion_id' => $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null,
            'producto_nombre_snapshot' => (string) $d['producto_nombre_snapshot'],
            'sku_snapshot' => (string) $d['sku'],
            'unidad_id' => (int) $d['unidad_id'],
            'unidad_nombre_snapshot' => (string) $d['unidad_nombre_snapshot'],
            'cantidad' => (float) $d['cantidad'],
            'factor_a_unidad_base' => (float) $d['factor_a_unidad_base'],
            'cantidad_base' => (float) $d['cantidad_base'],
            'nivel_precio_snapshot' => 'MANUAL',
            'precio_unitario' => (float) $d['precio_unitario'],
            'descuento_pct' => (float) $d['descuento_pct'],
            'descuento_importe' => (float) $d['descuento_importe'],
            'tasa_impuesto_id' => $d['tasa_impuesto_id'] !== null ? (int) $d['tasa_impuesto_id'] : null,
            'impuesto_pct_snapshot' => (float) $d['impuesto_pct_snapshot'],
            'subtotal' => (float) $d['subtotal'],
            'impuesto_importe' => (float) $d['impuesto_importe'],
            'total' => (float) $d['total'],
            'controla_inventario' => (int) $d['controla_inventario'],
        ];
    }
    return $resultado;
}

function ven_detalles_apartado_para_venta(PDO $conexion, int $apartadoId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            d.*,
            p.sku,
            p.controla_inventario,
            p.tasa_impuesto_id
         FROM apartados_detalle d
         INNER JOIN productos p ON p.id = d.producto_id
         WHERE d.apartado_id = :id
         ORDER BY d.renglon ASC"
    );
    $stmt->execute([':id' => $apartadoId]);
    $filas = $stmt->fetchAll();

    $resultado = [];
    foreach ($filas as $d) {
        $bruto = round((float) $d['cantidad'] * (float) $d['precio_unitario'], 4);
        $descuentoImporte = round(max(0, $bruto - (float) $d['subtotal']), 4);
        $resultado[] = [
            'renglon' => (int) $d['renglon'],
            'almacen_id' => (int) $d['almacen_id'],
            'producto_id' => (int) $d['producto_id'],
            'presentacion_id' => $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null,
            'producto_nombre_snapshot' => (string) $d['producto_nombre_snapshot'],
            'sku_snapshot' => (string) $d['sku'],
            'unidad_id' => (int) $d['unidad_id'],
            'unidad_nombre_snapshot' => (string) $d['unidad_nombre_snapshot'],
            'cantidad' => (float) $d['cantidad'],
            'factor_a_unidad_base' => (float) $d['factor_a_unidad_base'],
            'cantidad_base' => (float) $d['cantidad_base'],
            'nivel_precio_snapshot' => 'MANUAL',
            'precio_unitario' => (float) $d['precio_unitario'],
            'descuento_pct' => (float) $d['descuento_pct'],
            'descuento_importe' => $descuentoImporte,
            'tasa_impuesto_id' => $d['tasa_impuesto_id'] !== null ? (int) $d['tasa_impuesto_id'] : null,
            'impuesto_pct_snapshot' => (float) $d['impuesto_pct_snapshot'],
            'subtotal' => (float) $d['subtotal'],
            'impuesto_importe' => (float) $d['impuesto_importe'],
            'total' => (float) $d['total'],
            'controla_inventario' => (int) $d['controla_inventario'],
        ];
    }
    return $resultado;
}

function ven_calcular_descuento_historico(array $detalles): float
{
    $total = 0.0;
    foreach ($detalles as $d) {
        $total += (float) $d['descuento_importe'];
    }
    return round($total, 4);
}

function ven_tipo_cambio_a_base(PDO $conexion, int $monedaId, string $fecha): ?float
{
    $stmtMoneda = $conexion->prepare("SELECT id, es_base FROM monedas WHERE id = :id AND activo = 1 LIMIT 1");
    $stmtMoneda->execute([':id' => $monedaId]);
    $moneda = $stmtMoneda->fetch();
    if (!$moneda) {
        return null;
    }
    if ((int) $moneda['es_base'] === 1) {
        return 1.0;
    }

    $stmtBase = $conexion->query("SELECT id FROM monedas WHERE es_base = 1 AND activo = 1 ORDER BY id ASC LIMIT 1");
    $baseId = (int) ($stmtBase->fetchColumn() ?: 0);
    if ($baseId <= 0) {
        return null;
    }

    $stmt = $conexion->prepare(
        "SELECT tipo_cambio
         FROM tipos_cambio
         WHERE moneda_origen_id = :origen
           AND moneda_destino_id = :destino
           AND fecha <= :fecha
         ORDER BY fecha DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([':origen' => $monedaId, ':destino' => $baseId, ':fecha' => $fecha]);
    $tasa = $stmt->fetchColumn();
    if ($tasa !== false) {
        return (float) $tasa;
    }

    $stmtInv = $conexion->prepare(
        "SELECT tipo_cambio
         FROM tipos_cambio
         WHERE moneda_origen_id = :origen
           AND moneda_destino_id = :destino
           AND fecha <= :fecha
         ORDER BY fecha DESC, id DESC
         LIMIT 1"
    );
    $stmtInv->execute([':origen' => $baseId, ':destino' => $monedaId, ':fecha' => $fecha]);
    $inversa = $stmtInv->fetchColumn();
    if ($inversa !== false && (float) $inversa > 0) {
        return 1 / (float) $inversa;
    }

    return null;
}

/* =========================================================================
   AUDITORÍA
   ========================================================================= */

function ven_auditar(PDO $conexion, string $accion, string $tabla, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
             datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, :accion, 'ventas', :tabla, :entidad_id, :descripcion,
             :anterior, :nuevo, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anterior' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevo' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

function ven_auditar_sistema(PDO $conexion, string $accion, string $tabla, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
             datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (NULL, :accion, 'ventas', :tabla, :entidad_id, :descripcion,
             :anterior, :nuevo, NULL, 'Proceso automático del sistema')"
    );
    $stmt->execute([
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anterior' => $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':nuevo' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/* =========================================================================
   TIPADO DE RESPUESTAS
   ========================================================================= */

function ven_tipar_cliente(array &$c): void
{
    $c['id'] = (int) $c['id'];
    $c['nivel_cliente_id'] = $c['nivel_cliente_id'] !== null ? (int) $c['nivel_cliente_id'] : null;
    $c['descuento_default_pct'] = isset($c['descuento_default_pct']) && $c['descuento_default_pct'] !== null ? (float) $c['descuento_default_pct'] : 0.0;
    $c['descuento_personal_pct'] = isset($c['descuento_personal_pct']) && $c['descuento_personal_pct'] !== null ? (float) $c['descuento_personal_pct'] : null;
    $c['descuento_efectivo_pct'] = (float) ($c['descuento_efectivo_pct'] ?? 0);
    $c['dias_credito'] = (int) ($c['dias_credito'] ?? 0);
    $c['limite_credito'] = isset($c['limite_credito']) && $c['limite_credito'] !== null ? (float) $c['limite_credito'] : null;
    $c['saldo_cxc'] = (float) ($c['saldo_cxc'] ?? 0);
    $c['credito_disponible'] = $c['limite_credito'] === null ? null : max(0.0, round($c['limite_credito'] - $c['saldo_cxc'], 4));
}

function ven_tipar_detalle_fuente(array &$d): void
{
    foreach (['id','renglon','producto_id','unidad_id'] as $campo) {
        if (isset($d[$campo])) {
            $d[$campo] = (int) $d[$campo];
        }
    }
    $d['presentacion_id'] = isset($d['presentacion_id']) && $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null;
    $d['controla_inventario'] = (int) ($d['controla_inventario'] ?? 1);
    $d['permite_fraccion'] = (int) ($d['permite_fraccion'] ?? 1);
    foreach (['cantidad','factor_a_unidad_base','cantidad_base','precio_unitario','descuento_pct','descuento_importe','impuesto_pct_snapshot','subtotal','impuesto_importe','total','existencia_fisica','cantidad_reservada','cantidad_disponible'] as $campo) {
        if (isset($d[$campo]) && $d[$campo] !== null) {
            $d[$campo] = (float) $d[$campo];
        }
    }
}

function ven_tipar_cotizacion_fuente(array &$c): void
{
    foreach (['id','cliente_id','moneda_id','nivel_cliente_id','dias_credito'] as $campo) {
        if (isset($c[$campo]) && $c[$campo] !== null) {
            $c[$campo] = (int) $c[$campo];
        }
    }
    foreach (['tipo_cambio_a_base','subtotal','descuento_total','impuesto_total','total','descuento_actual_pct','limite_credito'] as $campo) {
        if (isset($c[$campo]) && $c[$campo] !== null) {
            $c[$campo] = (float) $c[$campo];
        }
    }
}

function ven_tipar_apartado_fuente(array &$a): void
{
    foreach (['id','cliente_id','cotizacion_id','moneda_id','nivel_cliente_id','dias_credito'] as $campo) {
        if (isset($a[$campo]) && $a[$campo] !== null) {
            $a[$campo] = (int) $a[$campo];
        }
    }
    foreach (['subtotal','impuesto_total','total','importe_anticipado','saldo_pendiente','descuento_actual_pct','limite_credito'] as $campo) {
        if (isset($a[$campo]) && $a[$campo] !== null) {
            $a[$campo] = (float) $a[$campo];
        }
    }
}

function ven_tipar_venta_listado(array &$v): void
{
    $v['id'] = (int) $v['id'];
    $v['renglones'] = (int) $v['renglones'];
    $v['total'] = (float) $v['total'];
    $v['importe_anticipado'] = (float) $v['importe_anticipado'];
    $v['pagado_directo'] = (float) $v['pagado_directo'];
    $v['cxc_saldo'] = $v['cxc_saldo'] !== null ? (float) $v['cxc_saldo'] : null;
    $v['pagado_total'] = round($v['importe_anticipado'] + $v['pagado_directo'], 4);

    if ($v['estado'] === 'CANCELADA') {
        $v['estado_pago'] = 'CANCELADA';
    } elseif ($v['condicion_pago'] === 'CREDITO') {
        $v['estado_pago'] = $v['cxc_estado'] ?: 'PENDIENTE';
    } else {
        $v['estado_pago'] = $v['pagado_total'] + 0.0001 >= $v['total'] ? 'PAGADA' : 'PENDIENTE';
    }
}

function ven_tipar_venta_detalle_header(array &$v): void
{
    foreach (['id','cliente_id','cotizacion_id','apartado_id','nivel_cliente_id','moneda_id','dias_credito','cxc_id'] as $campo) {
        if (isset($v[$campo]) && $v[$campo] !== null) {
            $v[$campo] = (int) $v[$campo];
        }
    }
    foreach (['descuento_cliente_pct_snapshot','tipo_cambio_a_base','subtotal','descuento_total','impuesto_total','total','importe_anticipado','cxc_importe_original','cxc_importe_pagado','cxc_saldo_pendiente'] as $campo) {
        if (isset($v[$campo]) && $v[$campo] !== null) {
            $v[$campo] = (float) $v[$campo];
        }
    }
}

function ven_tipar_detalle_venta(array &$d): void
{
    foreach (['id','venta_id','renglon','almacen_id','producto_id','unidad_id'] as $campo) {
        if (isset($d[$campo])) {
            $d[$campo] = (int) $d[$campo];
        }
    }
    $d['presentacion_id'] = $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : null;
    $d['tasa_impuesto_id'] = $d['tasa_impuesto_id'] !== null ? (int) $d['tasa_impuesto_id'] : null;
    foreach (['cantidad','factor_a_unidad_base','cantidad_base','precio_unitario','descuento_pct','descuento_importe','impuesto_pct_snapshot','subtotal','impuesto_importe','total','costo_unitario_base_snapshot'] as $campo) {
        if (isset($d[$campo]) && $d[$campo] !== null) {
            $d[$campo] = (float) $d[$campo];
        }
    }
}

/* =========================================================================
   VALIDACIÓN Y UTILIDADES
   ========================================================================= */

function ven_cancelar(PDO $conexion, string $mensaje, int $codigo = 422, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $datos, $codigo);
}

function ven_id($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Selecciona un ' . $nombre . ' válido.', ['campo' => $nombre], 422);
    }
    return (int) $id;
}

function ven_id_local($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        throw new InvalidArgumentException('Selecciona un ' . $nombre . ' válido.');
    }
    return (int) $id;
}

function ven_entero_rango($valor, int $minimo, int $maximo, int $defecto): int
{
    if ($valor === null || $valor === '') {
        return $defecto;
    }
    $entero = filter_var($valor, FILTER_VALIDATE_INT);
    if ($entero === false) {
        return $defecto;
    }
    return max($minimo, min($maximo, (int) $entero));
}

function ven_decimal($valor, string $nombre, float $minimo, float $maximo): float
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        si_responder_json(false, 'Captura un valor válido para ' . $nombre . '.', ['campo' => $nombre], 422);
    }
    $n = (float) $valor;
    if (!is_finite($n) || $n < $minimo || $n > $maximo) {
        si_responder_json(false, 'El valor de ' . $nombre . ' está fuera del rango permitido.', ['campo' => $nombre], 422);
    }
    return $n;
}

function ven_decimal_local($valor, string $nombre, float $minimo, float $maximo): float
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        throw new InvalidArgumentException('Captura un valor válido para ' . $nombre . '.');
    }
    $n = (float) $valor;
    if (!is_finite($n) || $n < $minimo || $n > $maximo) {
        throw new InvalidArgumentException('El valor de ' . $nombre . ' está fuera del rango permitido.');
    }
    return $n;
}

function ven_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    return mb_substr($texto, 0, $maximo);
}

function ven_nullable($valor, int $maximo): ?string
{
    $texto = ven_texto($valor, $maximo);
    return $texto === '' ? null : $texto;
}

function ven_fecha_opcional($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $texto);
    if (!$d || $d->format('Y-m-d') !== $texto) {
        si_responder_json(false, 'Una de las fechas no es válida.', [], 422);
    }
    return $texto;
}

function ven_json_array($valor, string $nombre): array
{
    $data = json_decode((string) $valor, true);
    if (!is_array($data)) {
        si_responder_json(false, 'La información de ' . $nombre . ' no es válida.', [], 422);
    }
    return $data;
}

function ven_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor);
    }
}
