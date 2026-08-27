<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('apartados.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_APARTADOS')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                apa_catalogos($conexion);
                break;
            case 'LISTAR_APARTADOS':
                apa_listar($conexion);
                break;
            case 'DETALLE_APARTADO':
                apa_detalle($conexion);
                break;
            case 'BUSCAR_CLIENTES':
                apa_buscar_clientes($conexion);
                break;
            case 'BUSCAR_PRODUCTOS':
                apa_buscar_productos($conexion);
                break;
            case 'PRESENTACIONES_PRODUCTO':
                apa_presentaciones_producto($conexion);
                break;
            case 'SUGERIR_PRECIO':
                apa_sugerir_precio($conexion);
                break;
            case 'COTIZACION_PARA_APARTAR':
                apa_cotizacion_para_apartar($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('apartados.crear')) {
        si_responder_json(false, 'No tienes permiso para crear o administrar apartados.', [], 403);
    }

    switch ($accion) {
        case 'CREAR_APARTADO':
            apa_crear($conexion);
            break;
        case 'REGISTRAR_ANTICIPO':
            apa_registrar_anticipo($conexion);
            break;
        case 'CANCELAR_ANTICIPO':
            apa_cancelar_anticipo($conexion);
            break;
        case 'CANCELAR_APARTADO':
            apa_cancelar_apartado($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'APA-' . date('Ymd-His');
    error_log('[' . $referencia . '][APARTADOS][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());

    if ((string) $e->getCode() === '23000') {
        si_responder_json(false, 'No fue posible guardar porque existe un dato duplicado o una relación inválida.', ['referencia' => $referencia], 409);
    }

    si_responder_json(false, 'No fue posible procesar la operación de apartados.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'APA-' . date('Ymd-His');
    error_log('[' . $referencia . '][APARTADOS] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar apartados.', ['referencia' => $referencia], 500);
}

/* =========================================================================
   CATÁLOGOS / BÚSQUEDAS
   ========================================================================= */

function apa_catalogos(PDO $conexion): void
{
    apa_procesar_vencidos($conexion);

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
        'reserva_sugerida' => date('Y-m-d', strtotime('+7 days')),
    ]);
}

function apa_buscar_clientes(PDO $conexion): void
{
    $q = apa_texto($_GET['q'] ?? '', 180);

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
            CASE
                WHEN c.descuento_personal_pct IS NOT NULL THEN 'ESPECIAL'
                WHEN n.id IS NOT NULL THEN 'NIVEL'
                ELSE 'SIN_DESCUENTO'
            END AS origen_descuento
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE 1=1
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
        $c['id'] = (int) $c['id'];
        $c['nivel_cliente_id'] = $c['nivel_cliente_id'] !== null ? (int) $c['nivel_cliente_id'] : null;
        $c['descuento_default_pct'] = $c['descuento_default_pct'] !== null ? (float) $c['descuento_default_pct'] : 0.0;
        $c['descuento_personal_pct'] = $c['descuento_personal_pct'] !== null ? (float) $c['descuento_personal_pct'] : null;
        $c['descuento_efectivo_pct'] = (float) $c['descuento_efectivo_pct'];
    }
    unset($c);

    si_responder_json(true, 'Clientes cargados.', ['clientes' => $clientes]);
}

function apa_buscar_productos(PDO $conexion): void
{
    $q = apa_texto($_GET['q'] ?? '', 180);
    $almacenId = apa_id($_GET['almacen_id'] ?? null, 'almacén');

    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['productos' => []]);
    }

    if (!apa_almacen_activo($conexion, $almacenId)) {
        si_responder_json(false, 'El almacén seleccionado ya no está disponible.', [], 409);
    }

    $like = '%' . $q . '%';
    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.tasa_impuesto_id,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(ea.cantidad_disponible, 0) AS cantidad_disponible
         FROM productos p
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         LEFT JOIN existencias_almacen ea
            ON ea.producto_id = p.id
           AND ea.almacen_id = :almacen_id
         WHERE 1=1
           AND p.activo = 1
           AND p.controla_inventario = 1
           AND (
                p.sku LIKE :sku
                OR p.nombre LIKE :nombre
                OR p.codigo_barras LIKE :codigo_barras
           )
         ORDER BY CASE WHEN p.sku = :exacta THEN 0 ELSE 1 END, p.nombre ASC
         LIMIT 20"
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
        $p['tasa_impuesto_id'] = $p['tasa_impuesto_id'] !== null ? (int) $p['tasa_impuesto_id'] : null;
        $p['impuesto_pct'] = (float) $p['impuesto_pct'];
        $p['existencia_fisica'] = (float) $p['existencia_fisica'];
        $p['cantidad_reservada'] = (float) $p['cantidad_reservada'];
        $p['cantidad_disponible'] = (float) $p['cantidad_disponible'];
    }
    unset($p);

    si_responder_json(true, 'Productos cargados.', ['productos' => $productos]);
}

function apa_presentaciones_producto(PDO $conexion): void
{
    $productoId = apa_id($_GET['producto_id'] ?? null, 'producto');
    $producto = apa_producto_activo($conexion, $productoId);

    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.unidad_id,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            pp.factor_a_unidad_base
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
        $p['es_unidad_base_virtual'] = 0;
    }
    unset($p);

    array_unshift($presentaciones, [
        'id' => 0,
        'nombre' => 'Unidad base · ' . $producto['unidad_base_nombre'],
        'unidad_id' => (int) $producto['unidad_base_id'],
        'unidad_codigo' => (string) $producto['unidad_base_codigo'],
        'unidad_nombre' => (string) $producto['unidad_base_nombre'],
        'unidad_simbolo' => (string) $producto['unidad_base_simbolo'],
        'factor_a_unidad_base' => 1.0,
        'es_unidad_base_virtual' => 1,
    ]);

    si_responder_json(true, 'Presentaciones cargadas.', [
        'producto' => $producto,
        'presentaciones' => $presentaciones,
    ]);
}

function apa_sugerir_precio(PDO $conexion): void
{
    $productoId = apa_id($_GET['producto_id'] ?? null, 'producto');
    $presentacionId = apa_entero_rango($_GET['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $monedaId = apa_id($_GET['moneda_id'] ?? null, 'moneda');
    $cantidad = apa_decimal($_GET['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0);

    $resultado = apa_resolver_precio($conexion, $productoId, $presentacionId, $monedaId, $cantidad);
    si_responder_json(true, $resultado['mensaje'], $resultado['datos']);
}

function apa_cotizacion_para_apartar(PDO $conexion): void
{
    apa_procesar_vencidos($conexion);
    $id = apa_id($_GET['cotizacion_id'] ?? null, 'cotización');
    $cotizacion = apa_cargar_cotizacion($conexion, $id, false);

    if (!$cotizacion) {
        si_responder_json(false, 'La cotización ya no existe.', [], 404);
    }

    if ($cotizacion['estado'] !== 'ACEPTADA') {
        si_responder_json(false, 'Solo una cotización ACEPTADA puede convertirse en apartado.', ['estado' => $cotizacion['estado']], 409);
    }

    if ($cotizacion['cliente_id'] === null) {
        si_responder_json(false, 'La cotización no tiene un cliente válido para crear el apartado.', [], 409);
    }

    $existente = apa_apartado_de_cotizacion($conexion, $id);
    if ($existente) {
        si_responder_json(false, 'Esta cotización ya está relacionada con el apartado ' . $existente['folio'] . '.', ['apartado_id' => (int) $existente['id']], 409);
    }

    $detalles = apa_detalles_cotizacion($conexion, $id, null);
    if (!$detalles) {
        si_responder_json(false, 'La cotización no contiene productos.', [], 409);
    }

    si_responder_json(true, 'Cotización lista para convertirse en apartado.', [
        'cotizacion' => $cotizacion,
        'detalles' => $detalles,
    ]);
}

/* =========================================================================
   LISTADO / DETALLE
   ========================================================================= */

function apa_listar(PDO $conexion): void
{
    apa_procesar_vencidos($conexion);

    $pagina = apa_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = apa_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = apa_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(apa_texto($_GET['estado'] ?? 'TODOS', 30));
    $desde = apa_fecha_opcional($_GET['desde'] ?? null);
    $hasta = apa_fecha_opcional($_GET['hasta'] ?? null);

    $estados = ['TODOS', 'ACTIVO', 'COMPLETADO', 'VENCIDO', 'CANCELADO'];
    if (!in_array($estado, $estados, true)) {
        $estado = 'TODOS';
    }

    $where = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(a.folio LIKE :folio OR c.codigo LIKE :codigo OR c.nombre_razon_social LIKE :cliente OR co.folio LIKE :cotizacion)";
        $params[':folio'] = $like;
        $params[':codigo'] = $like;
        $params[':cliente'] = $like;
        $params[':cotizacion'] = $like;
    }

    if ($estado !== 'TODOS') {
        $where[] = 'a.estado = :estado';
        $params[':estado'] = $estado;
    }

    // Rangos sobre la columna sin DATE() para que MySQL pueda aprovechar los índices
    // cuando el historial crezca a miles de apartados.
    if ($desde !== null) {
        $where[] = 'a.fecha_apartado >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta !== null) {
        $limiteHasta = (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d');
        $where[] = 'a.fecha_apartado < :hasta_exclusivo';
        $params[':hasta_exclusivo'] = $limiteHasta . ' 00:00:00';
    }

    $from = "FROM apartados a
             INNER JOIN clientes c ON c.id = a.cliente_id
             INNER JOIN monedas m ON m.id = a.moneda_id
             LEFT JOIN cotizaciones co ON co.id = a.cotizacion_id
             LEFT JOIN usuarios u ON u.id = a.created_by";
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    apa_bind($stmtTotal, $params);
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
            a.folio,
            a.cliente_id,
            c.codigo AS cliente_codigo,
            c.nombre_razon_social AS cliente_nombre,
            a.cotizacion_id,
            co.folio AS cotizacion_folio,
            a.fecha_apartado,
            a.reservado_hasta,
            a.estado,
            a.moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            a.subtotal,
            a.impuesto_total,
            a.total,
            a.importe_anticipado,
            a.saldo_pendiente,
            u.usuario AS creado_por,
            (SELECT COUNT(*) FROM apartados_detalle ad WHERE ad.apartado_id = a.id) AS renglones,
            (SELECT COUNT(*) FROM anticipos_apartado aa WHERE aa.apartado_id = a.id AND aa.estado = 'APLICADO') AS anticipos_aplicados,
            (SELECT MIN(v.folio) FROM ventas v WHERE v.apartado_id = a.id) AS venta_folio
         {$from}
         {$whereSql}
         ORDER BY a.fecha_apartado DESC, a.id DESC
         LIMIT :limite OFFSET :offset"
    );
    apa_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $apartados = $stmt->fetchAll();

    foreach ($apartados as &$a) {
        apa_tipar_apartado($a);
        $a['renglones'] = (int) $a['renglones'];
        $a['anticipos_aplicados'] = (int) $a['anticipos_aplicados'];
    }
    unset($a);

    $kpis = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'ACTIVO') AS activos,
            SUM(estado = 'ACTIVO' AND reservado_hasta IS NOT NULL AND reservado_hasta BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 DAY)) AS por_vencer,
            SUM(estado = 'VENCIDO') AS vencidos,
            SUM(estado = 'COMPLETADO') AS completados
         FROM apartados"
    )->fetch();

    foreach ($kpis as $clave => $valor) {
        $kpis[$clave] = (int) ($valor ?? 0);
    }

    si_responder_json(true, 'Apartados cargados.', [
        'apartados' => $apartados,
        'kpis' => $kpis,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function apa_detalle(PDO $conexion): void
{
    apa_procesar_vencidos($conexion);
    $id = apa_id($_GET['apartado_id'] ?? null, 'apartado');

    $stmt = $conexion->prepare(
        "SELECT
            a.*,
            c.codigo AS cliente_codigo,
            c.nombre_razon_social AS cliente_nombre,
            c.rfc AS cliente_rfc,
            m.codigo AS moneda_codigo,
            m.nombre AS moneda_nombre,
            m.simbolo AS moneda_simbolo,
            co.folio AS cotizacion_folio,
            u.usuario AS creado_por,
            uc.usuario AS cancelado_por,
            (SELECT MIN(v.folio) FROM ventas v WHERE v.apartado_id = a.id) AS venta_folio
         FROM apartados a
         INNER JOIN clientes c ON c.id = a.cliente_id
         INNER JOIN monedas m ON m.id = a.moneda_id
         LEFT JOIN cotizaciones co ON co.id = a.cotizacion_id
         LEFT JOIN usuarios u ON u.id = a.created_by
         LEFT JOIN usuarios uc ON uc.id = a.cancelado_by
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $apartado = $stmt->fetch();

    if (!$apartado) {
        si_responder_json(false, 'El apartado ya no existe.', [], 404);
    }
    apa_tipar_apartado($apartado);

    $stmtDet = $conexion->prepare(
        "SELECT
            ad.id,
            ad.renglon,
            ad.almacen_id,
            al.codigo AS almacen_codigo,
            al.nombre AS almacen_nombre,
            ad.producto_id,
            p.sku,
            ad.presentacion_id,
            pp.nombre AS presentacion_nombre,
            ad.producto_nombre_snapshot,
            ad.unidad_id,
            ad.unidad_nombre_snapshot,
            u.codigo AS unidad_codigo,
            u.simbolo AS unidad_simbolo,
            ub.codigo AS unidad_base_codigo,
            ub.simbolo AS unidad_base_simbolo,
            ad.cantidad,
            ad.factor_a_unidad_base,
            ad.cantidad_base,
            ad.precio_unitario,
            ad.descuento_pct,
            ad.impuesto_pct_snapshot,
            ad.subtotal,
            ad.impuesto_importe,
            ad.total,
            COALESCE(ea.existencia_fisica, 0) AS existencia_fisica_actual,
            COALESCE(ea.cantidad_reservada, 0) AS reservado_total_actual,
            COALESCE(ea.cantidad_disponible, 0) AS disponible_actual
         FROM apartados_detalle ad
         INNER JOIN almacenes al ON al.id = ad.almacen_id
         INNER JOIN productos p ON p.id = ad.producto_id
         LEFT JOIN presentaciones_producto pp ON pp.id = ad.presentacion_id
         INNER JOIN unidades_medida u ON u.id = ad.unidad_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN existencias_almacen ea
            ON ea.almacen_id = ad.almacen_id
           AND ea.producto_id = ad.producto_id
         WHERE ad.apartado_id = :id
         ORDER BY ad.renglon ASC"
    );
    $stmtDet->execute([':id' => $id]);
    $detalles = $stmtDet->fetchAll();

    foreach ($detalles as &$d) {
        foreach (['id', 'renglon', 'almacen_id', 'producto_id', 'unidad_id'] as $campo) {
            $d[$campo] = (int) $d[$campo];
        }
        $d['presentacion_id'] = $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : 0;
        foreach (['cantidad', 'factor_a_unidad_base', 'cantidad_base', 'precio_unitario', 'descuento_pct', 'impuesto_pct_snapshot', 'subtotal', 'impuesto_importe', 'total', 'existencia_fisica_actual', 'reservado_total_actual', 'disponible_actual'] as $campo) {
            $d[$campo] = (float) $d[$campo];
        }
    }
    unset($d);

    $stmtAnt = $conexion->prepare(
        "SELECT
            aa.id,
            aa.fecha_pago,
            aa.metodo_pago_id,
            mp.codigo AS metodo_codigo,
            mp.nombre AS metodo_nombre,
            aa.importe,
            aa.referencia,
            aa.estado,
            aa.motivo_cancelacion,
            aa.cancelado_at,
            u.usuario AS registrado_por,
            uc.usuario AS cancelado_por
         FROM anticipos_apartado aa
         INNER JOIN metodos_pago mp ON mp.id = aa.metodo_pago_id
         LEFT JOIN usuarios u ON u.id = aa.created_by
         LEFT JOIN usuarios uc ON uc.id = aa.cancelado_by
         WHERE aa.apartado_id = :id
         ORDER BY aa.fecha_pago DESC, aa.id DESC"
    );
    $stmtAnt->execute([':id' => $id]);
    $anticipos = $stmtAnt->fetchAll();
    foreach ($anticipos as &$ant) {
        $ant['id'] = (int) $ant['id'];
        $ant['metodo_pago_id'] = (int) $ant['metodo_pago_id'];
        $ant['importe'] = (float) $ant['importe'];
    }
    unset($ant);

    si_responder_json(true, 'Detalle del apartado cargado.', [
        'apartado' => $apartado,
        'detalles' => $detalles,
        'anticipos' => $anticipos,
    ]);
}

/* =========================================================================
   CREACIÓN / ANTICIPOS / CANCELACIÓN
   ========================================================================= */

function apa_crear(PDO $conexion): void
{
    // Libera reservas vencidas antes de calcular disponibilidad para un apartado nuevo.
    apa_procesar_vencidos($conexion);

    $clienteId = apa_id($_POST['cliente_id'] ?? null, 'cliente');
    $monedaId = apa_id($_POST['moneda_id'] ?? null, 'moneda');
    $almacenId = apa_id($_POST['almacen_id'] ?? null, 'almacén');
    $cotizacionId = apa_entero_rango($_POST['cotizacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $reservadoHasta = apa_fecha_requerida($_POST['reservado_hasta'] ?? null, 'fecha límite de reserva');
    $observaciones = apa_nullable($_POST['observaciones'] ?? null, 3000);

    if (strtotime($reservadoHasta . ' 23:59:59') <= time()) {
        si_responder_json(false, 'La fecha límite de reserva debe ser posterior a hoy.', ['campo' => 'reservado_hasta'], 422);
    }

    $cliente = apa_cliente_activo($conexion, $clienteId);
    if (!$cliente) {
        si_responder_json(false, 'El cliente seleccionado ya no está activo.', [], 409);
    }

    if (!apa_moneda_activa($conexion, $monedaId)) {
        si_responder_json(false, 'La moneda seleccionada ya no está disponible.', [], 409);
    }

    if (!apa_almacen_activo($conexion, $almacenId)) {
        si_responder_json(false, 'El almacén seleccionado ya no está disponible.', [], 409);
    }

    $importeAnticipo = apa_decimal_opcional($_POST['anticipo_importe'] ?? null, 0.0, 999999999999.0, 0.0);
    $metodoAnticipoId = apa_entero_rango($_POST['anticipo_metodo_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $referenciaAnticipo = apa_nullable($_POST['anticipo_referencia'] ?? null, 120);

    $conexion->beginTransaction();

    $detalles = [];
    $subtotal = 0.0;
    $impuesto = 0.0;
    $total = 0.0;

    if ($cotizacionId > 0) {
        $cotizacion = apa_cargar_cotizacion($conexion, $cotizacionId, true);
        if (!$cotizacion) {
            apa_cancelar($conexion, 'La cotización ya no existe.', 404);
        }
        if ($cotizacion['estado'] !== 'ACEPTADA') {
            apa_cancelar($conexion, 'La cotización debe estar ACEPTADA para convertirla en apartado.', 409);
        }
        if ((int) ($cotizacion['cliente_id'] ?? 0) !== $clienteId) {
            apa_cancelar($conexion, 'El cliente no coincide con la cotización aceptada.', 409);
        }
        if ((int) $cotizacion['moneda_id'] !== $monedaId) {
            apa_cancelar($conexion, 'La moneda no coincide con la cotización aceptada.', 409);
        }
        if (apa_apartado_de_cotizacion($conexion, $cotizacionId)) {
            apa_cancelar($conexion, 'La cotización ya fue convertida a un apartado.', 409);
        }

        $detalles = apa_detalles_cotizacion($conexion, $cotizacionId, $almacenId);
        if (!$detalles) {
            apa_cancelar($conexion, 'La cotización no tiene productos para reservar.', 409);
        }
        foreach ($detalles as $detalleCotizacion) {
            if (!apa_producto_activo($conexion, (int) $detalleCotizacion['producto_id'])) {
                apa_cancelar($conexion, 'Uno de los productos de la cotización ya no está activo o no controla inventario. Revisa la cotización antes de apartar.', 409, ['producto_id' => (int) $detalleCotizacion['producto_id']]);
            }
        }

        $subtotal = round((float) $cotizacion['subtotal'], 4);
        $impuesto = round((float) $cotizacion['impuesto_total'], 4);
        $total = round((float) $cotizacion['total'], 4);
    } else {
        $lineas = apa_json_array($_POST['lineas'] ?? '[]', 'productos');
        if (!$lineas) {
            apa_cancelar($conexion, 'Agrega al menos un producto al apartado.', 422);
        }

        [$detalles, $subtotal, $impuesto, $total] = apa_normalizar_lineas_directas($conexion, $lineas, $cliente, $monedaId, $almacenId);
    }

    if ($total <= 0) {
        apa_cancelar($conexion, 'El total del apartado debe ser mayor que cero.', 422);
    }

    if ($importeAnticipo > round($total + 0.0001, 4)) {
        apa_cancelar($conexion, 'El anticipo inicial no puede ser mayor que el total del apartado.', 422);
    }

    $reservas = apa_agrupar_reservas($detalles);
    apa_validar_y_reservar($conexion, $reservas);

    $folioTmp = 'TMP-APA-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO apartados
            (folio, cliente_id, cotizacion_id, fecha_apartado, reservado_hasta, moneda_id, estado, subtotal, impuesto_total, total, importe_anticipado, observaciones, created_by)
         VALUES
            (:folio, :cliente_id, :cotizacion_id, NOW(), :reservado_hasta, :moneda_id, 'ACTIVO', :subtotal, :impuesto, :total, 0, :observaciones, :created_by)"
    );
    $stmt->execute([
        ':folio' => $folioTmp,
        ':cliente_id' => $clienteId,
        ':cotizacion_id' => $cotizacionId > 0 ? $cotizacionId : null,
        ':reservado_hasta' => $reservadoHasta . ' 23:59:59',
        ':moneda_id' => $monedaId,
        ':subtotal' => $subtotal,
        ':impuesto' => $impuesto,
        ':total' => $total,
        ':observaciones' => $observaciones,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $apartadoId = (int) $conexion->lastInsertId();
    $folio = 'APA-' . str_pad((string) $apartadoId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE apartados SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $apartadoId]);

    $insertDetalle = $conexion->prepare(
        "INSERT INTO apartados_detalle
            (apartado_id, renglon, almacen_id, producto_id, presentacion_id, producto_nombre_snapshot, unidad_id, unidad_nombre_snapshot, cantidad, factor_a_unidad_base, cantidad_base, precio_unitario, descuento_pct, impuesto_pct_snapshot, subtotal, impuesto_importe, total)
         VALUES
            (:apartado_id, :renglon, :almacen_id, :producto_id, :presentacion_id, :producto_nombre, :unidad_id, :unidad_nombre, :cantidad, :factor, :cantidad_base, :precio, :descuento_pct, :impuesto_pct, :subtotal, :impuesto_importe, :total)"
    );

    foreach ($detalles as $d) {
        $insertDetalle->execute([
            ':apartado_id' => $apartadoId,
            ':renglon' => $d['renglon'],
            ':almacen_id' => $d['almacen_id'],
            ':producto_id' => $d['producto_id'],
            ':presentacion_id' => $d['presentacion_id'],
            ':producto_nombre' => $d['producto_nombre_snapshot'],
            ':unidad_id' => $d['unidad_id'],
            ':unidad_nombre' => $d['unidad_nombre_snapshot'],
            ':cantidad' => $d['cantidad'],
            ':factor' => $d['factor_a_unidad_base'],
            ':cantidad_base' => $d['cantidad_base'],
            ':precio' => $d['precio_unitario'],
            ':descuento_pct' => $d['descuento_pct'],
            ':impuesto_pct' => $d['impuesto_pct_snapshot'],
            ':subtotal' => $d['subtotal'],
            ':impuesto_importe' => $d['impuesto_importe'],
            ':total' => $d['total'],
        ]);
    }

    if ($importeAnticipo > 0) {
        apa_insertar_anticipo($conexion, $apartadoId, $importeAnticipo, $metodoAnticipoId, $referenciaAnticipo);
        $conexion->prepare("UPDATE apartados SET importe_anticipado = :importe WHERE id = :id")
            ->execute([':importe' => $importeAnticipo, ':id' => $apartadoId]);
    }

    if ($cotizacionId > 0) {
        $conexion->prepare("UPDATE cotizaciones SET estado = 'CONVERTIDA' WHERE id = :id AND estado = 'ACEPTADA'")
            ->execute([':id' => $cotizacionId]);
        apa_auditar($conexion, 'CONVERTIR_A_APARTADO', 'cotizaciones', $cotizacionId, 'La cotización fue convertida al apartado ' . $folio . '.', ['estado' => 'ACEPTADA'], ['estado' => 'CONVERTIDA', 'apartado_id' => $apartadoId]);
    }

    apa_auditar($conexion, 'CREAR', 'apartados', $apartadoId, 'Se creó el apartado ' . $folio . ' y se reservó inventario.', null, [
        'folio' => $folio,
        'cliente_id' => $clienteId,
        'cotizacion_id' => $cotizacionId > 0 ? $cotizacionId : null,
        'almacen_id' => $almacenId,
        'reservado_hasta' => $reservadoHasta,
        'total' => $total,
        'importe_anticipado' => $importeAnticipo,
        'renglones' => count($detalles),
    ]);

    $conexion->commit();
    si_responder_json(true, 'Apartado creado correctamente.', ['apartado_id' => $apartadoId, 'folio' => $folio], 201);
}

function apa_registrar_anticipo(PDO $conexion): void
{
    // Un anticipo no debe reactivar de hecho una reserva cuyo plazo ya venció.
    apa_procesar_vencidos($conexion);

    $apartadoId = apa_id($_POST['apartado_id'] ?? null, 'apartado');
    $importe = apa_decimal($_POST['importe'] ?? null, 'importe', 0.01, 999999999999.0);
    $metodoId = apa_id($_POST['metodo_pago_id'] ?? null, 'método de pago');
    $referencia = apa_nullable($_POST['referencia'] ?? null, 120);

    $conexion->beginTransaction();
    $apartado = apa_bloquear_apartado($conexion, $apartadoId);
    if (!$apartado) {
        apa_cancelar($conexion, 'El apartado ya no existe.', 404);
    }
    if ($apartado['estado'] !== 'ACTIVO') {
        apa_cancelar($conexion, 'Solo se pueden registrar anticipos en un apartado ACTIVO.', 409);
    }

    $saldo = round((float) $apartado['total'] - (float) $apartado['importe_anticipado'], 4);
    if ($importe > $saldo + 0.0001) {
        apa_cancelar($conexion, 'El anticipo no puede ser mayor que el saldo pendiente.', 422, ['saldo_pendiente' => max(0, $saldo)]);
    }

    $anticipoId = apa_insertar_anticipo($conexion, $apartadoId, $importe, $metodoId, $referencia);
    $nuevoAnticipado = round((float) $apartado['importe_anticipado'] + $importe, 4);
    $conexion->prepare("UPDATE apartados SET importe_anticipado = :importe WHERE id = :id")
        ->execute([':importe' => $nuevoAnticipado, ':id' => $apartadoId]);

    apa_auditar($conexion, 'REGISTRAR_ANTICIPO', 'anticipos_apartado', $anticipoId, 'Se registró un anticipo al apartado ' . $apartado['folio'] . '.', null, [
        'apartado_id' => $apartadoId,
        'importe' => $importe,
        'importe_anticipado_total' => $nuevoAnticipado,
    ]);

    $conexion->commit();
    si_responder_json(true, 'Anticipo registrado correctamente.', ['anticipo_id' => $anticipoId, 'importe_anticipado' => $nuevoAnticipado, 'saldo_pendiente' => round((float) $apartado['total'] - $nuevoAnticipado, 4)]);
}

function apa_cancelar_anticipo(PDO $conexion): void
{
    $anticipoId = apa_id($_POST['anticipo_id'] ?? null, 'anticipo');
    $motivo = apa_texto($_POST['motivo'] ?? '', 1000);
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo de cancelación de al menos 5 caracteres.', ['campo' => 'motivo'], 422);
    }

    $conexion->beginTransaction();
    $stmt = $conexion->prepare("SELECT * FROM anticipos_apartado WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $anticipoId]);
    $anticipo = $stmt->fetch();
    if (!$anticipo) {
        apa_cancelar($conexion, 'El anticipo ya no existe.', 404);
    }
    if ($anticipo['estado'] !== 'APLICADO') {
        apa_cancelar($conexion, 'El anticipo ya está cancelado.', 409);
    }

    $apartado = apa_bloquear_apartado($conexion, (int) $anticipo['apartado_id']);
    if (!$apartado) {
        apa_cancelar($conexion, 'El apartado relacionado ya no existe.', 409);
    }
    if ($apartado['estado'] === 'COMPLETADO') {
        apa_cancelar($conexion, 'Un anticipo de un apartado COMPLETADO debe regularizarse desde el flujo de venta.', 409);
    }

    $conexion->prepare(
        "UPDATE anticipos_apartado
         SET estado = 'CANCELADO', motivo_cancelacion = :motivo, cancelado_at = NOW(), cancelado_by = :usuario
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $anticipoId,
    ]);

    $nuevoAnticipado = max(0.0, round((float) $apartado['importe_anticipado'] - (float) $anticipo['importe'], 4));
    $conexion->prepare("UPDATE apartados SET importe_anticipado = :importe WHERE id = :id")
        ->execute([':importe' => $nuevoAnticipado, ':id' => (int) $apartado['id']]);

    apa_auditar($conexion, 'CANCELAR_ANTICIPO', 'anticipos_apartado', $anticipoId, 'Se canceló un anticipo del apartado ' . $apartado['folio'] . '.', ['estado' => 'APLICADO', 'importe' => (float) $anticipo['importe']], ['estado' => 'CANCELADO', 'motivo' => $motivo]);
    $conexion->commit();

    si_responder_json(true, 'Anticipo cancelado correctamente.', ['importe_anticipado' => $nuevoAnticipado, 'saldo_pendiente' => round((float) $apartado['total'] - $nuevoAnticipado, 4)]);
}

function apa_cancelar_apartado(PDO $conexion): void
{
    $apartadoId = apa_id($_POST['apartado_id'] ?? null, 'apartado');
    $motivo = apa_texto($_POST['motivo'] ?? '', 1500);
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo de cancelación de al menos 5 caracteres.', ['campo' => 'motivo'], 422);
    }

    $conexion->beginTransaction();
    $apartado = apa_bloquear_apartado($conexion, $apartadoId);
    if (!$apartado) {
        apa_cancelar($conexion, 'El apartado ya no existe.', 404);
    }
    if (!in_array($apartado['estado'], ['ACTIVO', 'VENCIDO'], true)) {
        apa_cancelar($conexion, 'Este apartado ya no puede cancelarse desde este flujo.', 409);
    }

    $stmtAnt = $conexion->prepare("SELECT COALESCE(SUM(importe), 0) FROM anticipos_apartado WHERE apartado_id = :id AND estado = 'APLICADO'");
    $stmtAnt->execute([':id' => $apartadoId]);
    $anticiposAplicados = (float) $stmtAnt->fetchColumn();
    if ($anticiposAplicados > 0.0001) {
        apa_cancelar($conexion, 'El apartado tiene anticipos aplicados. Cancela o regulariza primero esos anticipos para no perder trazabilidad financiera.', 409, ['importe_anticipado' => $anticiposAplicados]);
    }

    if ($apartado['estado'] === 'ACTIVO') {
        apa_liberar_reserva_apartado($conexion, $apartadoId);
    }

    $conexion->prepare(
        "UPDATE apartados
         SET estado = 'CANCELADO', motivo_cancelacion = :motivo, cancelado_at = NOW(), cancelado_by = :usuario
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $apartadoId,
    ]);

    apa_auditar($conexion, 'CANCELAR', 'apartados', $apartadoId, 'Se canceló el apartado ' . $apartado['folio'] . '.', ['estado' => $apartado['estado']], ['estado' => 'CANCELADO', 'motivo' => $motivo]);
    $conexion->commit();
    si_responder_json(true, 'Apartado cancelado y reserva liberada correctamente.');
}

/* =========================================================================
   LÓGICA DE NEGOCIO
   ========================================================================= */

function apa_normalizar_lineas_directas(PDO $conexion, array $lineas, array $cliente, int $monedaId, int $almacenId): array
{
    if (count($lineas) > 200) {
        apa_cancelar($conexion, 'Un apartado no puede contener más de 200 renglones.', 422);
    }

    $descuentoCliente = round((float) $cliente['descuento_efectivo_pct'], 6);
    if ($descuentoCliente < 0 || $descuentoCliente > 100) {
        apa_cancelar($conexion, 'El descuento configurado para el cliente no es válido.', 409);
    }

    $detalles = [];
    $claves = [];
    $subtotalHeader = 0.0;
    $impuestoHeader = 0.0;
    $totalHeader = 0.0;

    foreach ($lineas as $indice => $entrada) {
        if (!is_array($entrada)) {
            apa_cancelar($conexion, 'Uno de los renglones de productos no es válido.', 422);
        }

        try {
            $productoId = apa_id_local($entrada['producto_id'] ?? null, 'producto');
            $presentacionId = apa_entero_rango($entrada['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
            $cantidad = apa_decimal_local($entrada['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0);
            $precio = apa_decimal_local($entrada['precio_unitario'] ?? null, 'precio', 0.0001, 999999999999.0);
            $precioVentaId = apa_entero_rango($entrada['precio_venta_id'] ?? 0, 0, PHP_INT_MAX, 0);
        } catch (InvalidArgumentException $e) {
            apa_cancelar($conexion, $e->getMessage(), 422);
        }

        $clave = $productoId . ':' . $presentacionId;
        if (isset($claves[$clave])) {
            apa_cancelar($conexion, 'No repitas el mismo producto con la misma presentación. Ajusta la cantidad del renglón existente.', 422);
        }
        $claves[$clave] = true;

        $producto = apa_producto_activo($conexion, $productoId);
        if (!$producto) {
            apa_cancelar($conexion, 'Uno de los productos ya no está disponible.', 409);
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
                apa_cancelar($conexion, 'Una presentación seleccionada ya no está disponible.', 409);
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
            apa_cancelar($conexion, 'La conversión de una presentación no es válida.', 409);
        }

        $cantidadBase = round($cantidad * $factor, 6);
        $impuestoPct = (float) $producto['impuesto_pct'];

        if ($precioVentaId > 0) {
            $cond = $presentacionId > 0 ? 'pv.presentacion_id = :presentacion_id' : 'pv.presentacion_id IS NULL';
            $stmtPrecio = $conexion->prepare(
                "SELECT pv.id, COALESCE(ti.porcentaje, tip.porcentaje, 0) AS impuesto_pct
                 FROM precios_venta_producto pv
                 INNER JOIN productos p ON p.id = pv.producto_id
                 LEFT JOIN tasas_impuesto ti ON ti.id = pv.tasa_impuesto_id
                 LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
                 WHERE pv.id = :id AND pv.producto_id = :producto_id AND {$cond}
                   AND pv.activo = 1
                   AND (pv.nivel_precio = 'MENUDEO' OR pv.cantidad_minima <= :cantidad)
                   AND pv.vigente_desde <= NOW()
                   AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
                 LIMIT 1"
            );
            $params = [':id' => $precioVentaId, ':producto_id' => $productoId, ':cantidad' => $cantidad];
            if ($presentacionId > 0) {
                $params[':presentacion_id'] = $presentacionId;
            }
            $stmtPrecio->execute($params);
            $filaPrecio = $stmtPrecio->fetch();
            if ($filaPrecio) {
                $impuestoPct = (float) $filaPrecio['impuesto_pct'];
            }
        }

        $bruto = round($cantidad * $precio, 4);
        $descuentoImporte = round($bruto * ($descuentoCliente / 100), 4);
        $subtotal = round($bruto - $descuentoImporte, 4);
        $impuestoImporte = round($subtotal * ($impuestoPct / 100), 4);
        $total = round($subtotal + $impuestoImporte, 4);

        $subtotalHeader += $subtotal;
        $impuestoHeader += $impuestoImporte;
        $totalHeader += $total;

        $detalles[] = [
            'renglon' => $indice + 1,
            'almacen_id' => $almacenId,
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId > 0 ? $presentacionId : null,
            'producto_nombre_snapshot' => (string) $producto['nombre'],
            'unidad_id' => $unidadId,
            'unidad_nombre_snapshot' => $unidadNombre,
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $factor,
            'cantidad_base' => $cantidadBase,
            'precio_unitario' => $precio,
            'descuento_pct' => $descuentoCliente,
            'impuesto_pct_snapshot' => $impuestoPct,
            'subtotal' => $subtotal,
            'impuesto_importe' => $impuestoImporte,
            'total' => $total,
        ];
    }

    return [$detalles, round($subtotalHeader, 4), round($impuestoHeader, 4), round($totalHeader, 4)];
}

function apa_agrupar_reservas(array $detalles): array
{
    $grupos = [];
    foreach ($detalles as $d) {
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
    return array_values($grupos);
}

function apa_validar_y_reservar(PDO $conexion, array $reservas): void
{
    usort($reservas, static function (array $a, array $b): int {
        return [(int) $a['almacen_id'], (int) $a['producto_id']] <=> [(int) $b['almacen_id'], (int) $b['producto_id']];
    });

    $stmtLock = $conexion->prepare(
        "SELECT id, existencia_fisica, cantidad_reservada, cantidad_disponible
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id AND producto_id = :producto_id
         FOR UPDATE"
    );
    $stmtUpdate = $conexion->prepare(
        "UPDATE existencias_almacen
         SET cantidad_reservada = cantidad_reservada + :cantidad
         WHERE id = :id"
    );

    foreach ($reservas as $r) {
        $stmtLock->execute([':almacen_id' => $r['almacen_id'], ':producto_id' => $r['producto_id']]);
        $existencia = $stmtLock->fetch();
        $disponible = $existencia ? (float) $existencia['cantidad_disponible'] : 0.0;
        $necesario = (float) $r['cantidad_base'];

        if (!$existencia || $disponible + 0.000001 < $necesario) {
            apa_cancelar($conexion, 'No hay existencia disponible suficiente para ' . $r['producto_nombre'] . '.', 409, [
                'producto_id' => (int) $r['producto_id'],
                'disponible_base' => round($disponible, 6),
                'requerido_base' => round($necesario, 6),
            ]);
        }

        $stmtUpdate->execute([':cantidad' => $necesario, ':id' => (int) $existencia['id']]);
    }
}

function apa_liberar_reserva_apartado(PDO $conexion, int $apartadoId): void
{
    $stmt = $conexion->prepare(
        "SELECT almacen_id, producto_id, SUM(cantidad_base) AS cantidad_base
         FROM apartados_detalle
         WHERE apartado_id = :apartado_id
         GROUP BY almacen_id, producto_id"
    );
    $stmt->execute([':apartado_id' => $apartadoId]);
    $reservas = $stmt->fetchAll();
    usort($reservas, static function (array $a, array $b): int {
        return [(int) $a['almacen_id'], (int) $a['producto_id']] <=> [(int) $b['almacen_id'], (int) $b['producto_id']];
    });

    $lock = $conexion->prepare(
        "SELECT id, cantidad_reservada
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id AND producto_id = :producto_id
         FOR UPDATE"
    );
    $update = $conexion->prepare("UPDATE existencias_almacen SET cantidad_reservada = :cantidad WHERE id = :id");

    foreach ($reservas as $r) {
        $lock->execute([':almacen_id' => (int) $r['almacen_id'], ':producto_id' => (int) $r['producto_id']]);
        $existencia = $lock->fetch();
        if (!$existencia) {
            continue;
        }
        $nuevo = max(0.0, round((float) $existencia['cantidad_reservada'] - (float) $r['cantidad_base'], 6));
        $update->execute([':cantidad' => $nuevo, ':id' => (int) $existencia['id']]);
    }
}

function apa_procesar_vencidos(PDO $conexion): void
{
    $ids = $conexion->query(
        "SELECT id
         FROM apartados
         WHERE estado = 'ACTIVO'
           AND reservado_hasta IS NOT NULL
           AND reservado_hasta < NOW()
         ORDER BY reservado_hasta ASC, id ASC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        return;
    }

    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }

    try {
        foreach ($ids as $idRaw) {
            $id = (int) $idRaw;
            $stmt = $conexion->prepare("SELECT id, folio, estado, reservado_hasta FROM apartados WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $apartado = $stmt->fetch();
            if (!$apartado || $apartado['estado'] !== 'ACTIVO' || !$apartado['reservado_hasta'] || strtotime((string) $apartado['reservado_hasta']) >= time()) {
                continue;
            }

            apa_liberar_reserva_apartado($conexion, $id);
            $conexion->prepare("UPDATE apartados SET estado = 'VENCIDO' WHERE id = :id AND estado = 'ACTIVO'")
                ->execute([':id' => $id]);

            apa_auditar_sistema($conexion, 'APARTADO_VENCIDO_AUTOMATICO', 'apartados', $id, 'El apartado ' . $apartado['folio'] . ' venció y su reserva de inventario fue liberada.', ['estado' => 'ACTIVO'], ['estado' => 'VENCIDO']);
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

function apa_insertar_anticipo(PDO $conexion, int $apartadoId, float $importe, int $metodoId, ?string $referencia): int
{
    if ($metodoId <= 0) {
        apa_cancelar($conexion, 'Selecciona un método de pago para el anticipo.', 422);
    }

    $stmtMetodo = $conexion->prepare("SELECT id, nombre, requiere_referencia FROM metodos_pago WHERE id = :id AND activo = 1 LIMIT 1");
    $stmtMetodo->execute([':id' => $metodoId]);
    $metodo = $stmtMetodo->fetch();
    if (!$metodo) {
        apa_cancelar($conexion, 'El método de pago ya no está disponible.', 409);
    }
    if ((int) $metodo['requiere_referencia'] === 1 && ($referencia === null || $referencia === '')) {
        apa_cancelar($conexion, 'Captura la referencia del pago para ' . $metodo['nombre'] . '.', 422, ['campo' => 'referencia']);
    }

    $stmt = $conexion->prepare(
        "INSERT INTO anticipos_apartado
            (apartado_id, fecha_pago, metodo_pago_id, importe, referencia, estado, created_by)
         VALUES
            (:apartado_id, NOW(), :metodo_id, :importe, :referencia, 'APLICADO', :created_by)"
    );
    $stmt->execute([
        ':apartado_id' => $apartadoId,
        ':metodo_id' => $metodoId,
        ':importe' => $importe,
        ':referencia' => $referencia,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);
    return (int) $conexion->lastInsertId();
}

function apa_resolver_precio(PDO $conexion, int $productoId, int $presentacionId, int $monedaId, float $cantidad): array
{
    $producto = apa_producto_activo($conexion, $productoId);
    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    if ($presentacionId > 0) {
        $stmtPres = $conexion->prepare("SELECT id FROM presentaciones_producto WHERE id = :id AND producto_id = :producto_id AND es_venta = 1 AND activo = 1 LIMIT 1");
        $stmtPres->execute([':id' => $presentacionId, ':producto_id' => $productoId]);
        if (!$stmtPres->fetchColumn()) {
            si_responder_json(false, 'La presentación seleccionada no es válida para venta.', [], 422);
        }
    }

    $condicion = $presentacionId > 0 ? 'pv.presentacion_id = :presentacion_id' : 'pv.presentacion_id IS NULL';
    $stmt = $conexion->prepare(
        "SELECT
            pv.id, pv.nivel_precio, pv.cantidad_minima, pv.moneda_id, pv.precio_unitario,
            m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
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

    $datosBase = [
        'precio' => null,
        'precio_venta_id' => 0,
        'nivel_precio' => 'MANUAL',
        'origen' => 'MANUAL',
        'impuesto_pct' => (float) $producto['impuesto_pct'],
        'impuesto_nombre' => (string) $producto['impuesto_nombre'],
    ];

    if (!$candidatos) {
        return ['mensaje' => 'No hay precio vigente para esta presentación. Puedes capturar el precio manualmente o configurarlo en Productos / Catálogos.', 'datos' => $datosBase];
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
    $origenABase = apa_tipo_cambio_a_base($conexion, (int) $elegido['moneda_id'], date('Y-m-d'));
    $destinoABase = apa_tipo_cambio_a_base($conexion, $monedaId, date('Y-m-d'));
    if ($origenABase === null || $destinoABase === null || $destinoABase <= 0) {
        return ['mensaje' => 'Existe un precio configurado, pero falta un tipo de cambio para expresarlo en la moneda del apartado.', 'datos' => $datosBase];
    }

    $precio = ((float) $elegido['precio_unitario'] * $origenABase) / $destinoABase;
    return [
        'mensaje' => 'Precio sugerido automáticamente.',
        'datos' => [
            'precio' => round($precio, 4),
            'precio_venta_id' => (int) $elegido['id'],
            'nivel_precio' => (string) $elegido['nivel_precio'],
            'origen' => (int) $elegido['moneda_id'] === $monedaId ? 'CONFIGURADO' : 'CONVERTIDO',
            'moneda_origen' => (string) $elegido['moneda_codigo'],
            'precio_origen' => (float) $elegido['precio_unitario'],
            'cantidad_minima' => (string) $elegido['nivel_precio'] === 'MENUDEO' ? 1.0 : (float) $elegido['cantidad_minima'],
            'impuesto_pct' => (float) $elegido['impuesto_pct'],
            'impuesto_nombre' => (string) $elegido['impuesto_nombre'],
        ],
    ];
}

/* =========================================================================
   CONSULTAS AUXILIARES
   ========================================================================= */

function apa_cliente_activo(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT c.id, c.codigo, c.nombre_razon_social, c.rfc, c.nivel_cliente_id,
                n.codigo AS nivel_codigo, n.nombre AS nivel_nombre, n.descuento_default_pct,
                c.descuento_personal_pct,
                COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE c.id = :id AND c.activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    $fila['nivel_cliente_id'] = $fila['nivel_cliente_id'] !== null ? (int) $fila['nivel_cliente_id'] : null;
    $fila['descuento_default_pct'] = $fila['descuento_default_pct'] !== null ? (float) $fila['descuento_default_pct'] : 0.0;
    $fila['descuento_personal_pct'] = $fila['descuento_personal_pct'] !== null ? (float) $fila['descuento_personal_pct'] : null;
    $fila['descuento_efectivo_pct'] = (float) $fila['descuento_efectivo_pct'];
    return $fila;
}

function apa_producto_activo(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT p.id, p.sku, p.nombre, p.tipo, p.unidad_base_id,
                ub.codigo AS unidad_base_codigo, ub.nombre AS unidad_base_nombre, ub.simbolo AS unidad_base_simbolo,
                p.tasa_impuesto_id, COALESCE(ti.porcentaje, 0) AS impuesto_pct,
                COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre
         FROM productos p
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         WHERE p.id = :id AND p.activo = 1 AND p.controla_inventario = 1 AND ub.activo = 1
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
    $fila['impuesto_pct'] = (float) $fila['impuesto_pct'];
    return $fila;
}

function apa_moneda_activa(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM monedas WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function apa_almacen_activo(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function apa_bloquear_apartado(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare("SELECT * FROM apartados WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function apa_cargar_cotizacion(PDO $conexion, int $id, bool $bloquear): ?array
{
    $sql = "SELECT c.*, cl.codigo AS cliente_codigo, cl.nombre_razon_social AS cliente_nombre,
                   m.codigo AS moneda_codigo, m.nombre AS moneda_nombre, m.simbolo AS moneda_simbolo
            FROM cotizaciones c
            LEFT JOIN clientes cl ON cl.id = c.cliente_id
            INNER JOIN monedas m ON m.id = c.moneda_id
            WHERE c.id = :id LIMIT 1" . ($bloquear ? ' FOR UPDATE' : '');
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    $fila['cliente_id'] = $fila['cliente_id'] !== null ? (int) $fila['cliente_id'] : null;
    $fila['moneda_id'] = (int) $fila['moneda_id'];
    foreach (['tipo_cambio_a_base', 'subtotal', 'descuento_total', 'impuesto_total', 'total'] as $campo) {
        $fila[$campo] = (float) $fila[$campo];
    }
    return $fila;
}

function apa_detalles_cotizacion(PDO $conexion, int $cotizacionId, ?int $almacenId): array
{
    $stmt = $conexion->prepare(
        "SELECT cd.*, p.sku, ub.codigo AS unidad_base_codigo, ub.simbolo AS unidad_base_simbolo, pp.nombre AS presentacion_nombre
         FROM cotizaciones_detalle cd
         INNER JOIN productos p ON p.id = cd.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = cd.presentacion_id
         WHERE cd.cotizacion_id = :id
         ORDER BY cd.renglon ASC"
    );
    $stmt->execute([':id' => $cotizacionId]);
    $filas = $stmt->fetchAll();
    $resultado = [];
    foreach ($filas as $f) {
        $resultado[] = [
            'id' => (int) $f['id'],
            'renglon' => (int) $f['renglon'],
            'almacen_id' => $almacenId,
            'producto_id' => (int) $f['producto_id'],
            'sku' => (string) $f['sku'],
            'presentacion_id' => $f['presentacion_id'] !== null ? (int) $f['presentacion_id'] : null,
            'presentacion_nombre' => $f['presentacion_nombre'],
            'producto_nombre_snapshot' => (string) $f['producto_nombre_snapshot'],
            'unidad_id' => (int) $f['unidad_id'],
            'unidad_nombre_snapshot' => (string) $f['unidad_nombre_snapshot'],
            'unidad_base_codigo' => (string) $f['unidad_base_codigo'],
            'unidad_base_simbolo' => (string) $f['unidad_base_simbolo'],
            'cantidad' => (float) $f['cantidad'],
            'factor_a_unidad_base' => (float) $f['factor_a_unidad_base'],
            'cantidad_base' => (float) $f['cantidad_base'],
            'precio_unitario' => (float) $f['precio_unitario'],
            'descuento_pct' => (float) $f['descuento_pct'],
            'impuesto_pct_snapshot' => (float) $f['impuesto_pct_snapshot'],
            'subtotal' => (float) $f['subtotal'],
            'impuesto_importe' => (float) $f['impuesto_importe'],
            'total' => (float) $f['total'],
        ];
    }
    return $resultado;
}

function apa_apartado_de_cotizacion(PDO $conexion, int $cotizacionId): ?array
{
    $stmt = $conexion->prepare("SELECT id, folio, estado FROM apartados WHERE cotizacion_id = :id ORDER BY id ASC LIMIT 1");
    $stmt->execute([':id' => $cotizacionId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['id'] = (int) $fila['id'];
    return $fila;
}

function apa_tipo_cambio_a_base(PDO $conexion, int $monedaId, string $fecha): ?float
{
    $base = $conexion->query("SELECT id FROM monedas WHERE es_base = 1 AND activo = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$base) {
        return null;
    }
    $baseId = (int) $base;
    if ($monedaId === $baseId) {
        return 1.0;
    }

    $stmt = $conexion->prepare(
        "SELECT tipo_cambio
         FROM tipos_cambio
         WHERE moneda_origen_id = :origen AND moneda_destino_id = :destino AND fecha <= :fecha
         ORDER BY fecha DESC, id DESC LIMIT 1"
    );
    $stmt->execute([':origen' => $monedaId, ':destino' => $baseId, ':fecha' => $fecha]);
    $directo = $stmt->fetchColumn();
    if ($directo !== false && (float) $directo > 0) {
        return (float) $directo;
    }
    $stmt->execute([':origen' => $baseId, ':destino' => $monedaId, ':fecha' => $fecha]);
    $inverso = $stmt->fetchColumn();
    if ($inverso !== false && (float) $inverso > 0) {
        return 1 / (float) $inverso;
    }
    return null;
}

/* =========================================================================
   AUDITORÍA / TIPADO / VALIDACIÓN
   ========================================================================= */

function apa_auditar(PDO $conexion, string $accion, string $tabla, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, :accion, 'apartados', :tabla, :entidad_id, :descripcion, :anterior, :nuevo, :ip, :ua)"
    );
    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anterior' => $anterior !== null ? json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':nuevo' => $nuevo !== null ? json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':ip' => si_ip_cliente(),
        ':ua' => si_user_agent(),
    ]);
}

function apa_auditar_sistema(PDO $conexion, string $accion, string $tabla, int $entidadId, string $descripcion, ?array $anterior, ?array $nuevo): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (NULL, :accion, 'apartados', :tabla, :entidad_id, :descripcion, :anterior, :nuevo, NULL, 'Proceso automático por vencimiento')"
    );
    $stmt->execute([
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anterior' => $anterior !== null ? json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':nuevo' => $nuevo !== null ? json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function apa_tipar_apartado(array &$a): void
{
    $a['id'] = (int) $a['id'];
    $a['cliente_id'] = (int) $a['cliente_id'];
    $a['cotizacion_id'] = $a['cotizacion_id'] !== null ? (int) $a['cotizacion_id'] : null;
    $a['moneda_id'] = (int) $a['moneda_id'];
    $a['cancelado_by'] = isset($a['cancelado_by']) && $a['cancelado_by'] !== null ? (int) $a['cancelado_by'] : null;
    foreach (['subtotal', 'impuesto_total', 'total', 'importe_anticipado', 'saldo_pendiente'] as $campo) {
        if (array_key_exists($campo, $a)) {
            $a[$campo] = (float) $a[$campo];
        }
    }
}

function apa_cancelar(PDO $conexion, string $mensaje, int $codigo = 422, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $datos, $codigo);
}

function apa_id($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        si_responder_json(false, 'Selecciona un ' . $nombre . ' válido.', ['campo' => $nombre], 422);
    }
    return (int) $id;
}

function apa_id_local($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Selecciona un ' . $nombre . ' válido.');
    }
    return (int) $id;
}

function apa_entero_rango($valor, int $minimo, int $maximo, int $defecto): int
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

function apa_decimal($valor, string $nombre, float $minimo, float $maximo): float
{
    if ($valor === null || trim((string) $valor) === '') {
        si_responder_json(false, 'Captura ' . $nombre . '.', ['campo' => $nombre], 422);
    }
    $texto = str_replace(',', '', trim((string) $valor));
    if (!is_numeric($texto)) {
        si_responder_json(false, 'El valor de ' . $nombre . ' no es válido.', ['campo' => $nombre], 422);
    }
    $numero = (float) $texto;
    if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
        si_responder_json(false, 'El valor de ' . $nombre . ' está fuera del rango permitido.', ['campo' => $nombre], 422);
    }
    return $numero;
}

function apa_decimal_local($valor, string $nombre, float $minimo, float $maximo): float
{
    $texto = str_replace(',', '', trim((string) $valor));
    if ($texto === '' || !is_numeric($texto)) {
        throw new InvalidArgumentException('El valor de ' . $nombre . ' no es válido.');
    }
    $numero = (float) $texto;
    if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
        throw new InvalidArgumentException('El valor de ' . $nombre . ' está fuera del rango permitido.');
    }
    return $numero;
}

function apa_decimal_opcional($valor, float $minimo, float $maximo, float $defecto): float
{
    if ($valor === null || trim((string) $valor) === '') {
        return $defecto;
    }
    $texto = str_replace(',', '', trim((string) $valor));
    if (!is_numeric($texto)) {
        si_responder_json(false, 'El importe indicado no es válido.', [], 422);
    }
    $numero = (float) $texto;
    if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
        si_responder_json(false, 'El importe indicado está fuera del rango permitido.', [], 422);
    }
    return $numero;
}

function apa_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo) : $texto;
}

function apa_nullable($valor, int $maximo): ?string
{
    $texto = apa_texto($valor, $maximo);
    return $texto === '' ? null : $texto;
}

function apa_fecha_opcional($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $texto);
    $errores = DateTimeImmutable::getLastErrors();
    if (!$d || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
        si_responder_json(false, 'La fecha indicada no es válida.', [], 422);
    }
    return $d->format('Y-m-d');
}

function apa_fecha_requerida($valor, string $nombre): string
{
    $fecha = apa_fecha_opcional($valor);
    if ($fecha === null) {
        si_responder_json(false, 'Captura ' . $nombre . '.', ['campo' => $nombre], 422);
    }
    return $fecha;
}

function apa_json_array($valor, string $nombre): array
{
    $decodificado = json_decode((string) $valor, true);
    if (!is_array($decodificado)) {
        si_responder_json(false, 'La información de ' . $nombre . ' no es válida.', [], 422);
    }
    return $decodificado;
}

function apa_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
