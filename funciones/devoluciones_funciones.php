<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('devoluciones.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_DEVOLUCIONES')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                dev_catalogos($conexion);
                break;
            case 'LISTAR_DEVOLUCIONES':
                dev_listar_devoluciones($conexion);
                break;
            case 'BUSCAR_VENTAS':
                if (!si_tiene_permiso('devoluciones.venta')) {
                    si_responder_json(false, 'No tienes permiso para registrar devoluciones de clientes.', [], 403);
                }
                dev_buscar_ventas($conexion);
                break;
            case 'PREPARAR_VENTA':
                if (!si_tiene_permiso('devoluciones.venta')) {
                    si_responder_json(false, 'No tienes permiso para registrar devoluciones de clientes.', [], 403);
                }
                dev_preparar_venta($conexion);
                break;
            case 'BUSCAR_COMPRAS':
                if (!si_tiene_permiso('devoluciones.compra')) {
                    si_responder_json(false, 'No tienes permiso para registrar devoluciones a proveedor.', [], 403);
                }
                dev_buscar_compras($conexion);
                break;
            case 'PREPARAR_COMPRA':
                if (!si_tiene_permiso('devoluciones.compra')) {
                    si_responder_json(false, 'No tienes permiso para registrar devoluciones a proveedor.', [], 403);
                }
                dev_preparar_compra($conexion);
                break;
            case 'DETALLE':
                dev_detalle($conexion);
                break;
            case 'LISTAR_REGULARIZACIONES':
                if (!si_tiene_permiso('devoluciones.regularizar')) {
                    si_responder_json(false, 'No tienes permiso para consultar regularizaciones financieras.', [], 403);
                }
                dev_listar_regularizaciones($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'REGISTRAR_DEVOLUCION_VENTA':
            si_requerir_permiso('devoluciones.venta', true);
            dev_registrar_devolucion_venta($conexion);
            break;
        case 'REGISTRAR_DEVOLUCION_COMPRA':
            si_requerir_permiso('devoluciones.compra', true);
            dev_registrar_devolucion_compra($conexion);
            break;
        case 'LIQUIDAR_REGULARIZACION':
            si_requerir_permiso('devoluciones.regularizar', true);
            dev_liquidar_regularizacion($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (InvalidArgumentException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $e->getMessage(), [], 422);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    $referencia = 'DEV-' . date('Ymd-His');
    error_log('[' . $referencia . '][DEVOLUCIONES][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible procesar la operación de devoluciones.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    $referencia = 'DEV-' . date('Ymd-His');
    error_log('[' . $referencia . '][DEVOLUCIONES] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar devoluciones.', ['referencia' => $referencia], 500);
}

/* =========================================================================
   CATÁLOGOS Y LISTADOS
   ========================================================================= */

function dev_catalogos(PDO $conexion): void
{
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $metodos = $conexion->query(
        "SELECT id, codigo, nombre, requiere_referencia
         FROM metodos_pago
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    foreach ($almacenes as &$a) {
        $a['id'] = (int) $a['id'];
    }
    unset($a);

    foreach ($metodos as &$m) {
        $m['id'] = (int) $m['id'];
        $m['requiere_referencia'] = (int) $m['requiere_referencia'];
    }
    unset($m);

    si_responder_json(true, 'Catálogos cargados.', [
        'almacenes' => $almacenes,
        'metodos_pago' => $metodos,
        'puede_devolucion_venta' => si_tiene_permiso('devoluciones.venta'),
        'puede_devolucion_compra' => si_tiene_permiso('devoluciones.compra'),
        'puede_regularizar' => si_tiene_permiso('devoluciones.regularizar'),
    ]);
}

function dev_listar_devoluciones(PDO $conexion): void
{
    $tipo = strtoupper(dev_texto($_GET['tipo'] ?? 'VENTA', 10));
    if (!in_array($tipo, ['VENTA', 'COMPRA'], true)) {
        $tipo = 'VENTA';
    }

    $q = dev_texto($_GET['busqueda'] ?? '', 180);
    $pagina = dev_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = dev_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $params = [];
    $where = ["d.estado = 'CONFIRMADA'"];

    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($tipo === 'VENTA') {
            $where[] = "(d.folio LIKE :q1 OR v.folio LIKE :q2 OR COALESCE(v.cliente_nombre_snapshot, '') LIKE :q3 OR d.motivo LIKE :q4)";
        } else {
            $where[] = "(d.folio LIKE :q1 OR c.folio LIKE :q2 OR c.proveedor_nombre_snapshot LIKE :q3 OR d.motivo LIKE :q4)";
        }
        foreach ([':q1', ':q2', ':q3', ':q4'] as $k) {
            $params[$k] = $like;
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    if ($tipo === 'VENTA') {
        $from = "FROM devoluciones_venta d
                 INNER JOIN ventas v ON v.id = d.venta_id
                 INNER JOIN monedas m ON m.id = v.moneda_id
                 LEFT JOIN usuarios u ON u.id = d.created_by";
        $select = "SELECT d.id, d.folio, d.fecha_devolucion, d.estado, d.motivo, d.total,
                          d.importe_compensado_cxc, d.importe_reembolso, d.regularizacion_estado,
                          v.id AS documento_id, v.folio AS documento_folio,
                          COALESCE(v.cliente_nombre_snapshot, 'Público general') AS tercero,
                          m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                          (SELECT COUNT(*) FROM devoluciones_venta_detalle dd WHERE dd.devolucion_id = d.id) AS renglones,
                          CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por";
    } else {
        $from = "FROM devoluciones_compra d
                 INNER JOIN compras c ON c.id = d.compra_id
                 INNER JOIN monedas m ON m.id = c.moneda_id
                 LEFT JOIN usuarios u ON u.id = d.created_by";
        $select = "SELECT d.id, d.folio, d.fecha_devolucion, d.estado, d.motivo, d.total,
                          d.importe_compensado_cxp, d.importe_reintegro, d.regularizacion_estado,
                          c.id AS documento_id, c.folio AS documento_folio,
                          c.proveedor_nombre_snapshot AS tercero,
                          m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                          (SELECT COUNT(*) FROM devoluciones_compra_detalle dd WHERE dd.devolucion_id = d.id) AS renglones,
                          CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por";
    }

    $stmt = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    dev_bind($stmt, $params);
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "{$select}
         {$from}
         {$whereSql}
         ORDER BY d.fecha_devolucion DESC, d.id DESC
         LIMIT :limite OFFSET :offset"
    );
    dev_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['documento_id'] = (int) $f['documento_id'];
        $f['renglones'] = (int) $f['renglones'];
        foreach (['total', $tipo === 'VENTA' ? 'importe_compensado_cxc' : 'importe_compensado_cxp',
                  $tipo === 'VENTA' ? 'importe_reembolso' : 'importe_reintegro'] as $campo) {
            $f[$campo] = (float) $f[$campo];
        }
    }
    unset($f);

    si_responder_json(true, 'Devoluciones cargadas.', [
        'tipo' => $tipo,
        'devoluciones' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

/* =========================================================================
   BÚSQUEDA / PREPARACIÓN DE DEVOLUCIÓN DE CLIENTE
   ========================================================================= */

function dev_buscar_ventas(PDO $conexion): void
{
    $q = dev_texto($_GET['q'] ?? '', 180);
    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['ventas' => []]);
    }

    $like = '%' . $q . '%';
    $stmt = $conexion->prepare(
        "SELECT
            v.id,
            v.folio,
            v.fecha_venta,
            v.condicion_pago,
            v.total,
            COALESCE(v.cliente_nombre_snapshot, 'Público general') AS cliente,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            tq.usado_at AS salida_qr_at,
            COALESCE(dev.total_devuelto, 0) AS total_devuelto
         FROM ventas v
         INNER JOIN monedas m ON m.id = v.moneda_id
         INNER JOIN tokens_qr_venta tq
            ON tq.id = (
                SELECT t2.id
                FROM tokens_qr_venta t2
                WHERE t2.venta_id = v.id
                  AND t2.usado_at IS NOT NULL
                  AND t2.revocado_at IS NULL
                ORDER BY t2.usado_at DESC, t2.id DESC
                LIMIT 1
            )
         LEFT JOIN (
            SELECT venta_id, SUM(total) AS total_devuelto
            FROM devoluciones_venta
            WHERE estado = 'CONFIRMADA'
            GROUP BY venta_id
         ) dev ON dev.venta_id = v.id
         WHERE v.estado = 'CONFIRMADA'
           AND (v.folio LIKE :q1 OR COALESCE(v.cliente_nombre_snapshot, '') LIKE :q2)
           AND EXISTS (
                SELECT 1
                FROM ventas_detalle vdq
                WHERE vdq.venta_id = v.id
                  AND COALESCE((
                        SELECT SUM(ddq.cantidad_base)
                        FROM devoluciones_venta_detalle ddq
                        INNER JOIN devoluciones_venta dq ON dq.id = ddq.devolucion_id
                        WHERE dq.venta_id = v.id
                          AND dq.estado = 'CONFIRMADA'
                          AND ddq.venta_detalle_id = vdq.id
                    ), 0) + 0.0000001 < vdq.cantidad_base
           )
         ORDER BY v.fecha_venta DESC, v.id DESC
         LIMIT 20"
    );
    $stmt->execute([':q1' => $like, ':q2' => $like]);
    $ventas = $stmt->fetchAll();

    foreach ($ventas as &$v) {
        $v['id'] = (int) $v['id'];
        $v['total'] = (float) $v['total'];
        $v['total_devuelto'] = (float) $v['total_devuelto'];
        $v['total_restante'] = dev_round4(max(0.0, $v['total'] - $v['total_devuelto']));
    }
    unset($v);

    si_responder_json(true, 'Ventas encontradas.', ['ventas' => $ventas]);
}

function dev_preparar_venta(PDO $conexion): void
{
    $ventaId = dev_id($_GET['venta_id'] ?? null, 'venta');

    $stmt = $conexion->prepare(
        "SELECT
            v.*,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            COALESCE(v.cliente_nombre_snapshot, 'Público general') AS cliente
         FROM ventas v
         INNER JOIN monedas m ON m.id = v.moneda_id
         WHERE v.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $ventaId]);
    $venta = $stmt->fetch();

    if (!$venta || $venta['estado'] !== 'CONFIRMADA') {
        si_responder_json(false, 'La venta no existe o ya no está confirmada.', [], 409);
    }

    $stmtQr = $conexion->prepare(
        "SELECT id, usado_at, usado_by
         FROM tokens_qr_venta
         WHERE venta_id = :venta_id
           AND usado_at IS NOT NULL
           AND revocado_at IS NULL
         ORDER BY usado_at DESC, id DESC
         LIMIT 1"
    );
    $stmtQr->execute([':venta_id' => $ventaId]);
    $qr = $stmtQr->fetch();

    if (!$qr) {
        si_responder_json(
            false,
            'La salida física de esta venta no está confirmada actualmente por QR. Si la mercancía no salió, utiliza la cancelación de venta; una devolución requiere una salida física confirmada.',
            [],
            409
        );
    }

    $stmt = $conexion->prepare(
        "SELECT
            vd.id AS venta_detalle_id,
            vd.renglon,
            vd.producto_id,
            vd.producto_nombre_snapshot AS producto,
            vd.sku_snapshot AS sku,
            vd.unidad_nombre_snapshot AS unidad,
            um.simbolo AS unidad_simbolo,
            vd.cantidad,
            vd.factor_a_unidad_base,
            vd.cantidad_base,
            vd.total AS importe_linea,
            vd.costo_unitario_base_snapshot,
            p.controla_inventario,
            COALESCE(dev.cantidad_devuelta_base, 0) AS cantidad_devuelta_base,
            COALESCE(dev.importe_devuelto, 0) AS importe_devuelto
         FROM ventas_detalle vd
         INNER JOIN productos p ON p.id = vd.producto_id
         INNER JOIN unidades_medida um ON um.id = vd.unidad_id
         LEFT JOIN (
            SELECT
                dd.venta_detalle_id,
                SUM(dd.cantidad_base) AS cantidad_devuelta_base,
                SUM(dd.importe) AS importe_devuelto
            FROM devoluciones_venta_detalle dd
            INNER JOIN devoluciones_venta d ON d.id = dd.devolucion_id
            WHERE d.estado = 'CONFIRMADA'
            GROUP BY dd.venta_detalle_id
         ) dev ON dev.venta_detalle_id = vd.id
         WHERE vd.venta_id = :venta_id
         ORDER BY vd.renglon ASC"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $lineas = $stmt->fetchAll();

    $retornables = [];
    foreach ($lineas as $l) {
        $cantidadBase = (float) $l['cantidad_base'];
        $devueltaBase = (float) $l['cantidad_devuelta_base'];
        $restanteBase = dev_round6(max(0.0, $cantidadBase - $devueltaBase));
        if ($restanteBase <= 0.0000001) {
            continue;
        }
        $factor = (float) $l['factor_a_unidad_base'];
        $l['venta_detalle_id'] = (int) $l['venta_detalle_id'];
        $l['renglon'] = (int) $l['renglon'];
        $l['producto_id'] = (int) $l['producto_id'];
        $l['controla_inventario'] = (int) $l['controla_inventario'];
        $l['cantidad'] = (float) $l['cantidad'];
        $l['factor_a_unidad_base'] = $factor;
        $l['cantidad_base'] = $cantidadBase;
        $l['cantidad_devuelta_base'] = $devueltaBase;
        $l['cantidad_restante_base'] = $restanteBase;
        $l['cantidad_restante'] = $factor > 0 ? dev_round6($restanteBase / $factor) : 0.0;
        $l['importe_linea'] = (float) $l['importe_linea'];
        $l['importe_devuelto'] = (float) $l['importe_devuelto'];
        $l['importe_restante'] = dev_round4(max(0.0, $l['importe_linea'] - $l['importe_devuelto']));
        $retornables[] = $l;
    }

    if (!$retornables) {
        si_responder_json(false, 'Esta venta ya fue devuelta completamente.', [], 409);
    }

    $finanzas = dev_resumen_financiero_venta($conexion, $ventaId, false);
    if ($venta['condicion_pago'] === 'CREDITO' && $finanzas['cuenta_por_cobrar_id'] === null) {
        si_responder_json(false, 'La venta a crédito no tiene una cuenta por cobrar asociada. Revisa la integridad financiera antes de devolver.', [], 409);
    }
    $totalDevuelto = dev_total_devoluciones_venta($conexion, $ventaId, false);

    $venta['id'] = (int) $venta['id'];
    $venta['cliente_id'] = $venta['cliente_id'] !== null ? (int) $venta['cliente_id'] : null;
    $venta['apartado_id'] = $venta['apartado_id'] !== null ? (int) $venta['apartado_id'] : null;
    $venta['moneda_id'] = (int) $venta['moneda_id'];
    $venta['total'] = (float) $venta['total'];
    $venta['tipo_cambio_a_base'] = (float) $venta['tipo_cambio_a_base'];
    $venta['total_devuelto'] = $totalDevuelto;
    $venta['total_restante'] = dev_round4(max(0.0, $venta['total'] - $totalDevuelto));

    si_responder_json(true, 'Venta preparada para devolución.', [
        'venta' => $venta,
        'salida_qr' => [
            'token_id' => (int) $qr['id'],
            'usado_at' => $qr['usado_at'],
        ],
        'lineas' => $retornables,
        'finanzas' => $finanzas,
    ]);
}

/* =========================================================================
   BÚSQUEDA / PREPARACIÓN DE DEVOLUCIÓN A PROVEEDOR
   ========================================================================= */

function dev_buscar_compras(PDO $conexion): void
{
    $q = dev_texto($_GET['q'] ?? '', 180);
    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['compras' => []]);
    }

    $like = '%' . $q . '%';
    $stmt = $conexion->prepare(
        "SELECT DISTINCT
            c.id,
            c.folio,
            c.fecha_compra,
            c.condicion_pago,
            c.total,
            c.proveedor_nombre_snapshot AS proveedor,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            COALESCE(dev.total_devuelto, 0) AS total_devuelto
         FROM compras c
         INNER JOIN monedas m ON m.id = c.moneda_id
         INNER JOIN recepciones_compra r ON r.compra_id = c.id AND r.estado = 'CONFIRMADA'
         LEFT JOIN (
            SELECT compra_id, SUM(total) AS total_devuelto
            FROM devoluciones_compra
            WHERE estado = 'CONFIRMADA'
            GROUP BY compra_id
         ) dev ON dev.compra_id = c.id
         WHERE c.estado IN ('RECIBIDA_PARCIAL', 'RECIBIDA', 'PENDIENTE_RECEPCION')
           AND (c.folio LIKE :q1 OR c.proveedor_nombre_snapshot LIKE :q2 OR COALESCE(c.numero_factura, '') LIKE :q3)
           AND EXISTS (
                SELECT 1
                FROM recepciones_compra rq
                INNER JOIN recepciones_compra_detalle rdq ON rdq.recepcion_id = rq.id
                WHERE rq.compra_id = c.id
                  AND rq.estado = 'CONFIRMADA'
                  AND COALESCE((
                        SELECT SUM(ddq.cantidad_base)
                        FROM devoluciones_compra_detalle ddq
                        INNER JOIN devoluciones_compra dq ON dq.id = ddq.devolucion_id
                        WHERE dq.compra_id = c.id
                          AND dq.estado = 'CONFIRMADA'
                          AND ddq.recepcion_detalle_id = rdq.id
                    ), 0) + 0.0000001 < rdq.cantidad_base
           )
         ORDER BY c.fecha_compra DESC, c.id DESC
         LIMIT 20"
    );
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $compras = $stmt->fetchAll();

    foreach ($compras as &$c) {
        $c['id'] = (int) $c['id'];
        $c['total'] = (float) $c['total'];
        $c['total_devuelto'] = (float) $c['total_devuelto'];
        $c['total_restante'] = dev_round4(max(0.0, $c['total'] - $c['total_devuelto']));
    }
    unset($c);

    si_responder_json(true, 'Compras encontradas.', ['compras' => $compras]);
}

function dev_preparar_compra(PDO $conexion): void
{
    $compraId = dev_id($_GET['compra_id'] ?? null, 'compra');

    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo
         FROM compras c
         INNER JOIN monedas m ON m.id = c.moneda_id
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $compraId]);
    $compra = $stmt->fetch();

    if (!$compra || $compra['estado'] === 'CANCELADA' || $compra['estado'] === 'BORRADOR') {
        si_responder_json(false, 'La compra no existe o no está disponible para devolución.', [], 409);
    }

    $stmt = $conexion->prepare(
        "SELECT
            rd.id AS recepcion_detalle_id,
            r.id AS recepcion_id,
            r.folio AS recepcion_folio,
            r.fecha_recepcion,
            rd.compra_detalle_id,
            rd.almacen_id,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen,
            rd.producto_id,
            cd.producto_nombre_snapshot AS producto,
            cd.sku_snapshot AS sku,
            cd.unidad_nombre_snapshot AS unidad,
            um.simbolo AS unidad_simbolo,
            cd.factor_a_unidad_base,
            cd.cantidad_base AS cantidad_comprada_base,
            cd.total AS importe_linea_compra,
            rd.cantidad_recibida,
            rd.cantidad_base AS cantidad_recibida_base,
            p.controla_inventario,
            COALESCE(dev.cantidad_devuelta_base, 0) AS cantidad_devuelta_base,
            COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(ea.cantidad_disponible, 0) AS cantidad_disponible
         FROM recepciones_compra_detalle rd
         INNER JOIN recepciones_compra r ON r.id = rd.recepcion_id AND r.estado = 'CONFIRMADA'
         INNER JOIN compras_detalle cd ON cd.id = rd.compra_detalle_id
         INNER JOIN almacenes a ON a.id = rd.almacen_id
         INNER JOIN productos p ON p.id = rd.producto_id
         INNER JOIN unidades_medida um ON um.id = cd.unidad_id
         LEFT JOIN existencias_almacen ea
            ON ea.almacen_id = rd.almacen_id AND ea.producto_id = rd.producto_id
         LEFT JOIN (
            SELECT
                dd.recepcion_detalle_id,
                SUM(dd.cantidad_base) AS cantidad_devuelta_base
            FROM devoluciones_compra_detalle dd
            INNER JOIN devoluciones_compra d ON d.id = dd.devolucion_id
            WHERE d.estado = 'CONFIRMADA'
              AND dd.recepcion_detalle_id IS NOT NULL
            GROUP BY dd.recepcion_detalle_id
         ) dev ON dev.recepcion_detalle_id = rd.id
         WHERE r.compra_id = :compra_id
         ORDER BY r.fecha_recepcion ASC, r.id ASC, cd.renglon ASC, rd.id ASC"
    );
    $stmt->execute([':compra_id' => $compraId]);
    $rows = $stmt->fetchAll();

    $retornables = [];
    foreach ($rows as $l) {
        $factor = (float) $l['factor_a_unidad_base'];
        $recibidaBase = (float) $l['cantidad_recibida_base'];
        $devueltaBase = (float) $l['cantidad_devuelta_base'];
        $restanteBase = dev_round6(max(0.0, $recibidaBase - $devueltaBase));
        if ($restanteBase <= 0.0000001) {
            continue;
        }

        $disponible = (float) $l['cantidad_disponible'];
        if ((int) $l['controla_inventario'] === 1) {
            $maxBase = dev_round6(max(0.0, min($restanteBase, $disponible)));
        } else {
            $maxBase = $restanteBase;
        }

        if ($maxBase <= 0.0000001) {
            continue;
        }

        foreach (['recepcion_detalle_id', 'recepcion_id', 'compra_detalle_id', 'almacen_id', 'producto_id'] as $campo) {
            $l[$campo] = (int) $l[$campo];
        }
        $l['controla_inventario'] = (int) $l['controla_inventario'];
        foreach (['factor_a_unidad_base', 'cantidad_comprada_base', 'importe_linea_compra', 'cantidad_recibida',
                  'cantidad_recibida_base', 'cantidad_devuelta_base', 'existencia_fisica',
                  'cantidad_reservada', 'cantidad_disponible'] as $campo) {
            $l[$campo] = (float) $l[$campo];
        }
        $l['cantidad_restante_base'] = $restanteBase;
        $l['cantidad_restante'] = $factor > 0 ? dev_round6($restanteBase / $factor) : 0.0;
        $l['cantidad_maxima_base'] = $maxBase;
        $l['cantidad_maxima'] = $factor > 0 ? dev_round6($maxBase / $factor) : 0.0;
        $retornables[] = $l;
    }

    if (!$retornables) {
        si_responder_json(false, 'No hay cantidades recibidas y disponibles que puedan devolverse a este proveedor.', [], 409);
    }

    $finanzas = dev_resumen_financiero_compra($conexion, $compraId, false);
    if ($compra['condicion_pago'] === 'CREDITO' && $finanzas['cuenta_por_pagar_id'] === null) {
        si_responder_json(false, 'La compra a crédito no tiene una cuenta por pagar asociada. Revisa la integridad financiera antes de devolver.', [], 409);
    }
    $totalDevuelto = dev_total_devoluciones_compra($conexion, $compraId, false);

    $compra['id'] = (int) $compra['id'];
    $compra['proveedor_id'] = (int) $compra['proveedor_id'];
    $compra['moneda_id'] = (int) $compra['moneda_id'];
    $compra['total'] = (float) $compra['total'];
    $compra['tipo_cambio_a_base'] = (float) $compra['tipo_cambio_a_base'];
    $compra['total_devuelto'] = $totalDevuelto;
    $compra['total_restante'] = dev_round4(max(0.0, $compra['total'] - $totalDevuelto));

    si_responder_json(true, 'Compra preparada para devolución.', [
        'compra' => $compra,
        'lineas' => $retornables,
        'finanzas' => $finanzas,
    ]);
}

/* =========================================================================
   REGISTRAR DEVOLUCIÓN DE CLIENTE
   ========================================================================= */

function dev_registrar_devolucion_venta(PDO $conexion): void
{
    $ventaId = dev_id($_POST['venta_id'] ?? null, 'venta');
    $almacenId = dev_id($_POST['almacen_id'] ?? null, 'almacén de entrada');
    $motivo = dev_requerido($_POST['motivo'] ?? '', 'Indica el motivo de la devolución.', 255);
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'El motivo debe tener al menos 5 caracteres.', ['campo' => 'motivo'], 422);
    }
    $observaciones = dev_nullable($_POST['observaciones'] ?? null, 10000);
    $lineasEntrada = dev_json_array($_POST['lineas'] ?? '[]', 'productos');
    if (!$lineasEntrada || count($lineasEntrada) > 200) {
        si_responder_json(false, 'Agrega al menos un producto válido a la devolución.', [], 422);
    }

    $resolverAhora = dev_bool($_POST['resolver_regularizacion'] ?? '0');
    $metodoPagoId = dev_entero_rango($_POST['metodo_pago_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $referencia = dev_nullable($_POST['referencia'] ?? null, 120);
    $observacionFinanciera = dev_nullable($_POST['observacion_financiera'] ?? null, 3000);
    $idempotencyKey = dev_idempotency_key($_POST['idempotency_key'] ?? null);

    if ($resolverAhora && !si_tiene_permiso('devoluciones.regularizar')) {
        si_responder_json(false, 'No tienes permiso para liquidar reembolsos financieros.', [], 403);
    }

    $conexion->beginTransaction();

    if (!dev_almacen_activo($conexion, $almacenId)) {
        dev_cancelar($conexion, 'El almacén de entrada ya no está activo.', 409);
    }

    $stmt = $conexion->prepare("SELECT * FROM ventas WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $ventaId]);
    $venta = $stmt->fetch();
    if (!$venta || $venta['estado'] !== 'CONFIRMADA') {
        dev_cancelar($conexion, 'La venta ya no está disponible para devolución.', 409);
    }

    $repetida = dev_devolucion_idempotente_venta($conexion, $idempotencyKey, $ventaId);
    if ($repetida !== null) {
        $conexion->commit();
        si_responder_json(true, 'La devolución ya había sido confirmada. No se duplicó la operación.', $repetida, 200);
    }

    $stmtQr = $conexion->prepare(
        "SELECT id, usado_at
         FROM tokens_qr_venta
         WHERE venta_id = :venta_id
           AND usado_at IS NOT NULL
           AND revocado_at IS NULL
         ORDER BY usado_at DESC, id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmtQr->execute([':venta_id' => $ventaId]);
    $qr = $stmtQr->fetch();
    if (!$qr) {
        dev_cancelar(
            $conexion,
            'La salida física de esta venta no está confirmada por QR. No se registró la devolución.',
            409
        );
    }

    $idsEntrada = [];
    foreach ($lineasEntrada as $linea) {
        if (!is_array($linea)) {
            dev_cancelar($conexion, 'El formato de productos de la devolución no es válido.', 422);
        }
        $detalleId = dev_id_local($linea['venta_detalle_id'] ?? null, 'renglón de venta');
        if (isset($idsEntrada[$detalleId])) {
            dev_cancelar($conexion, 'Un renglón de venta aparece repetido en la devolución.', 422);
        }
        $cantidad = dev_decimal_positivo_local($linea['cantidad'] ?? null, 'cantidad a devolver');
        $idsEntrada[$detalleId] = $cantidad;
    }

    $stmt = $conexion->prepare(
        "SELECT
            vd.*,
            p.controla_inventario
         FROM ventas_detalle vd
         INNER JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = :venta_id
         ORDER BY vd.renglon ASC
         FOR UPDATE"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $detallesVenta = $stmt->fetchAll();

    $mapa = [];
    foreach ($detallesVenta as $d) {
        $mapa[(int) $d['id']] = $d;
    }

    foreach ($idsEntrada as $detalleId => $_) {
        if (!isset($mapa[$detalleId])) {
            dev_cancelar($conexion, 'Uno de los renglones no pertenece a la venta seleccionada.', 409);
        }
    }

    $stmtPrev = $conexion->prepare(
        "SELECT
            dd.venta_detalle_id,
            COALESCE(SUM(dd.cantidad_base), 0) AS cantidad_devuelta_base,
            COALESCE(SUM(dd.importe), 0) AS importe_devuelto
         FROM devoluciones_venta_detalle dd
         INNER JOIN devoluciones_venta d ON d.id = dd.devolucion_id
         WHERE d.venta_id = :venta_id
           AND d.estado = 'CONFIRMADA'
         GROUP BY dd.venta_detalle_id"
    );
    $stmtPrev->execute([':venta_id' => $ventaId]);
    $previos = [];
    foreach ($stmtPrev->fetchAll() as $p) {
        $previos[(int) $p['venta_detalle_id']] = [
            'cantidad' => (float) $p['cantidad_devuelta_base'],
            'importe' => (float) $p['importe_devuelto'],
        ];
    }

    $procesadas = [];
    $total = 0.0;

    foreach ($idsEntrada as $detalleId => $cantidadEntrada) {
        $d = $mapa[$detalleId];
        $factor = (float) $d['factor_a_unidad_base'];
        if ($factor <= 0) {
            dev_cancelar($conexion, 'El renglón ' . (int) $d['renglon'] . ' tiene un factor histórico inválido.', 409);
        }

        $cantidadBase = dev_round6($cantidadEntrada * $factor);
        $vendidaBase = (float) $d['cantidad_base'];
        $prevBase = (float) ($previos[$detalleId]['cantidad'] ?? 0.0);
        $prevImporte = (float) ($previos[$detalleId]['importe'] ?? 0.0);
        $restanteBase = dev_round6(max(0.0, $vendidaBase - $prevBase));

        if ($cantidadBase <= 0.0000001 || $cantidadBase - $restanteBase > 0.0000001) {
            dev_cancelar(
                $conexion,
                'La cantidad a devolver de ' . $d['producto_nombre_snapshot'] . ' supera lo pendiente.',
                422,
                [
                    'venta_detalle_id' => $detalleId,
                    'cantidad_maxima' => dev_round6($restanteBase / $factor),
                ]
            );
        }

        $importeLinea = (float) $d['total'];
        if (abs($cantidadBase - $restanteBase) <= 0.0000001) {
            $importe = dev_round4(max(0.0, $importeLinea - $prevImporte));
        } else {
            $importe = dev_round4($importeLinea * ($cantidadBase / $vendidaBase));
            $importe = min($importe, dev_round4(max(0.0, $importeLinea - $prevImporte)));
        }

        if ($importe < 0) {
            dev_cancelar($conexion, 'El importe calculado de la devolución no es válido.', 409);
        }

        $procesadas[] = [
            'venta_detalle_id' => $detalleId,
            'almacen_id' => $almacenId,
            'producto_id' => (int) $d['producto_id'],
            'producto' => (string) $d['producto_nombre_snapshot'],
            'renglon' => (int) $d['renglon'],
            'cantidad_base' => $cantidadBase,
            'importe' => $importe,
            'controla_inventario' => (int) $d['controla_inventario'],
            'costo_unitario_base' => $d['costo_unitario_base_snapshot'] !== null
                ? (float) $d['costo_unitario_base_snapshot']
                : null,
        ];
        $total = dev_round4($total + $importe);
    }

    $totalDevueltoAntes = dev_total_devoluciones_venta($conexion, $ventaId, true);
    if ($totalDevueltoAntes + $total - (float) $venta['total'] > 0.00005) {
        dev_cancelar($conexion, 'La devolución excedería el total original de la venta.', 409);
    }

    $folioTmp = 'TMP-DEV-VTA-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO devoluciones_venta
            (folio, venta_id, cliente_id, fecha_devolucion, estado, motivo, total,
             importe_compensado_cxc, importe_reembolso, afecta_cuenta_por_cobrar,
             regularizacion_estado, idempotency_key, observaciones, created_by)
         VALUES
            (:folio, :venta_id, :cliente_id, NOW(), 'BORRADOR', :motivo, :total,
             0, 0, 0, 'NO_APLICA', :idempotency_key, :observaciones, :usuario)"
    );
    $stmt->execute([
        ':folio' => $folioTmp,
        ':venta_id' => $ventaId,
        ':cliente_id' => $venta['cliente_id'] !== null ? (int) $venta['cliente_id'] : null,
        ':motivo' => $motivo,
        ':total' => $total,
        ':idempotency_key' => $idempotencyKey,
        ':observaciones' => $observaciones,
        ':usuario' => (int) $_SESSION['usuario_id'],
    ]);
    $devolucionId = (int) $conexion->lastInsertId();
    $folio = 'DEV-VTA-' . str_pad((string) $devolucionId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE devoluciones_venta SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $devolucionId]);

    $stmtDet = $conexion->prepare(
        "INSERT INTO devoluciones_venta_detalle
            (devolucion_id, venta_detalle_id, almacen_id, producto_id, cantidad_base, importe)
         VALUES
            (:devolucion_id, :venta_detalle_id, :almacen_id, :producto_id, :cantidad_base, :importe)"
    );
    foreach ($procesadas as $p) {
        $stmtDet->execute([
            ':devolucion_id' => $devolucionId,
            ':venta_detalle_id' => $p['venta_detalle_id'],
            ':almacen_id' => $p['almacen_id'],
            ':producto_id' => $p['producto_id'],
            ':cantidad_base' => $p['cantidad_base'],
            ':importe' => $p['importe'],
        ]);
    }

    $movimientoId = dev_aplicar_entrada_devolucion_venta($conexion, $devolucionId, $folio, $procesadas);

    $finanzasAntes = dev_resumen_financiero_venta($conexion, $ventaId, true);
    $compensado = min($total, (float) $finanzasAntes['saldo_cxc']);
    $compensado = dev_round4(max(0.0, $compensado));
    $reembolso = dev_round4(max(0.0, $total - $compensado));

    $cxcId = $finanzasAntes['cuenta_por_cobrar_id'];
    $cxcDespues = null;

    if ($venta['condicion_pago'] === 'CREDITO' && $cxcId === null) {
        dev_cancelar($conexion, 'La venta a crédito no tiene una cuenta por cobrar asociada. Revisa la integridad financiera antes de devolver.', 409);
    }

    if ($cxcId !== null && $compensado > 0.00005) {
        $nuevoOriginal = dev_round4((float) $finanzasAntes['cxc_importe_original'] - $compensado);
        if ($nuevoOriginal + 0.00005 < (float) $finanzasAntes['cxc_importe_pagado']) {
            dev_cancelar($conexion, 'La regularización dejaría pagos aplicados por encima del importe de la cuenta.', 409);
        }

        $conexion->prepare(
            "UPDATE cuentas_por_cobrar
             SET importe_original = :importe_original
             WHERE id = :id"
        )->execute([
            ':importe_original' => $nuevoOriginal,
            ':id' => $cxcId,
        ]);

        $cxcDespues = dev_recalcular_cxc($conexion, $cxcId);
    } elseif ($cxcId !== null) {
        $cxcDespues = dev_recalcular_cxc($conexion, $cxcId);
    }

    $regularizacionId = null;
    $regularizacionEstado = 'NO_APLICA';

    if ($reembolso > 0.00005) {
        $liquidada = $resolverAhora;
        $metodo = null;

        if ($liquidada) {
            $metodo = dev_metodo_pago_activo($conexion, $metodoPagoId);
            if (!$metodo) {
                dev_cancelar($conexion, 'Selecciona un método válido para registrar el reembolso.', 422);
            }
            if ((int) $metodo['requiere_referencia'] === 1 && $referencia === null) {
                dev_cancelar($conexion, 'El método seleccionado requiere referencia.', 422);
            }
        }

        $regularizacionId = dev_crear_regularizacion(
            $conexion,
            'REEMBOLSO_CLIENTE',
            $devolucionId,
            null,
            $venta['cliente_id'] !== null ? (int) $venta['cliente_id'] : null,
            null,
            (int) $venta['moneda_id'],
            (float) $venta['tipo_cambio_a_base'],
            $reembolso,
            $liquidada,
            $metodo ? (int) $metodo['id'] : null,
            $referencia,
            $observacionFinanciera
        );
        $regularizacionEstado = $liquidada ? 'LIQUIDADA' : 'PENDIENTE';
    }

    $conexion->prepare(
        "UPDATE devoluciones_venta
         SET estado = 'CONFIRMADA',
             importe_compensado_cxc = :compensado,
             importe_reembolso = :reembolso,
             afecta_cuenta_por_cobrar = :afecta,
             regularizacion_estado = :regularizacion_estado
         WHERE id = :id"
    )->execute([
        ':compensado' => $compensado,
        ':reembolso' => $reembolso,
        ':afecta' => $compensado > 0.00005 ? 1 : 0,
        ':regularizacion_estado' => $regularizacionEstado,
        ':id' => $devolucionId,
    ]);

    dev_auditar(
        $conexion,
        'DEVOLUCION_VENTA_CONFIRMADA',
        'devoluciones_venta',
        $devolucionId,
        'Se confirmó la devolución de cliente ' . $folio . ' vinculada a la venta ' . $venta['folio'] . '.',
        null,
        [
            'folio' => $folio,
            'venta_id' => $ventaId,
            'total' => $total,
            'movimiento_inventario_id' => $movimientoId,
            'cuenta_por_cobrar_id' => $cxcId,
            'importe_compensado_cxc' => $compensado,
            'importe_reembolso' => $reembolso,
            'regularizacion_financiera_id' => $regularizacionId,
            'cxc_antes' => $finanzasAntes,
            'cxc_despues' => $cxcDespues,
        ]
    );

    $conexion->commit();

    $mensaje = 'Devolución de cliente confirmada. Inventario y Kardex fueron actualizados.';
    if ($reembolso > 0.00005) {
        $mensaje .= $regularizacionEstado === 'LIQUIDADA'
            ? ' El reembolso quedó liquidado.'
            : ' Quedó un reembolso financiero pendiente de liquidar.';
    }

    si_responder_json(true, $mensaje, [
        'devolucion_id' => $devolucionId,
        'folio' => $folio,
        'movimiento_inventario_id' => $movimientoId,
        'importe_compensado_cxc' => $compensado,
        'importe_reembolso' => $reembolso,
        'regularizacion_financiera_id' => $regularizacionId,
        'regularizacion_estado' => $regularizacionEstado,
    ], 201);
}

/* =========================================================================
   REGISTRAR DEVOLUCIÓN A PROVEEDOR
   ========================================================================= */

function dev_registrar_devolucion_compra(PDO $conexion): void
{
    $compraId = dev_id($_POST['compra_id'] ?? null, 'compra');
    $motivo = dev_requerido($_POST['motivo'] ?? '', 'Indica el motivo de la devolución.', 255);
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'El motivo debe tener al menos 5 caracteres.', ['campo' => 'motivo'], 422);
    }
    $observaciones = dev_nullable($_POST['observaciones'] ?? null, 10000);
    $lineasEntrada = dev_json_array($_POST['lineas'] ?? '[]', 'productos');
    if (!$lineasEntrada || count($lineasEntrada) > 200) {
        si_responder_json(false, 'Agrega al menos un producto válido a la devolución.', [], 422);
    }

    $resolverAhora = dev_bool($_POST['resolver_regularizacion'] ?? '0');
    $metodoPagoId = dev_entero_rango($_POST['metodo_pago_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $referencia = dev_nullable($_POST['referencia'] ?? null, 120);
    $observacionFinanciera = dev_nullable($_POST['observacion_financiera'] ?? null, 3000);
    $idempotencyKey = dev_idempotency_key($_POST['idempotency_key'] ?? null);

    if ($resolverAhora && !si_tiene_permiso('devoluciones.regularizar')) {
        si_responder_json(false, 'No tienes permiso para liquidar reintegros de proveedor.', [], 403);
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare("SELECT * FROM compras WHERE id = :id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id' => $compraId]);
    $compra = $stmt->fetch();

    if (!$compra || in_array($compra['estado'], ['BORRADOR', 'CANCELADA'], true)) {
        dev_cancelar($conexion, 'La compra ya no está disponible para devolución.', 409);
    }

    $repetida = dev_devolucion_idempotente_compra($conexion, $idempotencyKey, $compraId);
    if ($repetida !== null) {
        $conexion->commit();
        si_responder_json(true, 'La devolución ya había sido confirmada. No se duplicó la operación.', $repetida, 200);
    }

    $idsEntrada = [];
    foreach ($lineasEntrada as $linea) {
        if (!is_array($linea)) {
            dev_cancelar($conexion, 'El formato de productos de la devolución no es válido.', 422);
        }
        $recepcionDetalleId = dev_id_local($linea['recepcion_detalle_id'] ?? null, 'renglón recibido');
        if (isset($idsEntrada[$recepcionDetalleId])) {
            dev_cancelar($conexion, 'Un renglón de recepción aparece repetido en la devolución.', 422);
        }
        $cantidad = dev_decimal_positivo_local($linea['cantidad'] ?? null, 'cantidad a devolver');
        $idsEntrada[$recepcionDetalleId] = $cantidad;
    }

    $stmt = $conexion->prepare(
        "SELECT
            rd.*,
            r.folio AS recepcion_folio,
            r.estado AS recepcion_estado,
            cd.compra_id,
            cd.renglon AS compra_renglon,
            cd.producto_nombre_snapshot,
            cd.factor_a_unidad_base,
            cd.cantidad_base AS cantidad_comprada_base,
            cd.total AS importe_linea_compra,
            p.controla_inventario
         FROM recepciones_compra_detalle rd
         INNER JOIN recepciones_compra r ON r.id = rd.recepcion_id
         INNER JOIN compras_detalle cd ON cd.id = rd.compra_detalle_id
         INNER JOIN productos p ON p.id = rd.producto_id
         WHERE r.compra_id = :compra_id
         ORDER BY r.id ASC, cd.renglon ASC, rd.id ASC
         FOR UPDATE"
    );
    $stmt->execute([':compra_id' => $compraId]);
    $recepciones = $stmt->fetchAll();

    $mapa = [];
    foreach ($recepciones as $r) {
        $mapa[(int) $r['id']] = $r;
    }

    foreach ($idsEntrada as $rdId => $_) {
        if (!isset($mapa[$rdId]) || $mapa[$rdId]['recepcion_estado'] !== 'CONFIRMADA') {
            dev_cancelar($conexion, 'Uno de los renglones no pertenece a una recepción confirmada de la compra.', 409);
        }
    }

    $stmtPrev = $conexion->prepare(
        "SELECT
            dd.recepcion_detalle_id,
            dd.compra_detalle_id,
            COALESCE(SUM(dd.cantidad_base), 0) AS cantidad_devuelta_base,
            COALESCE(SUM(dd.importe), 0) AS importe_devuelto
         FROM devoluciones_compra_detalle dd
         INNER JOIN devoluciones_compra d ON d.id = dd.devolucion_id
         WHERE d.compra_id = :compra_id
           AND d.estado = 'CONFIRMADA'
         GROUP BY dd.recepcion_detalle_id, dd.compra_detalle_id"
    );
    $stmtPrev->execute([':compra_id' => $compraId]);
    $prevPorRecepcion = [];
    $prevPorCompraDetalle = [];
    foreach ($stmtPrev->fetchAll() as $p) {
        if ($p['recepcion_detalle_id'] !== null) {
            $prevPorRecepcion[(int) $p['recepcion_detalle_id']] = (float) $p['cantidad_devuelta_base'];
        }
        $cdId = (int) $p['compra_detalle_id'];
        if (!isset($prevPorCompraDetalle[$cdId])) {
            $prevPorCompraDetalle[$cdId] = ['cantidad' => 0.0, 'importe' => 0.0];
        }
        $prevPorCompraDetalle[$cdId]['cantidad'] += (float) $p['cantidad_devuelta_base'];
        $prevPorCompraDetalle[$cdId]['importe'] += (float) $p['importe_devuelto'];
    }

    $procesadas = [];
    $gruposImporte = [];
    foreach ($idsEntrada as $rdId => $cantidadEntrada) {
        $r = $mapa[$rdId];
        $factor = (float) $r['factor_a_unidad_base'];
        if ($factor <= 0) {
            dev_cancelar($conexion, 'Un renglón de compra tiene un factor histórico inválido.', 409);
        }

        $cantidadBase = dev_round6($cantidadEntrada * $factor);
        $recibidaBase = (float) $r['cantidad_base'];
        $prevBase = (float) ($prevPorRecepcion[$rdId] ?? 0.0);
        $restanteBase = dev_round6(max(0.0, $recibidaBase - $prevBase));

        if ($cantidadBase <= 0.0000001 || $cantidadBase - $restanteBase > 0.0000001) {
            dev_cancelar(
                $conexion,
                'La cantidad a devolver de ' . $r['producto_nombre_snapshot'] . ' supera lo recibido pendiente de devolver.',
                422,
                [
                    'recepcion_detalle_id' => $rdId,
                    'cantidad_maxima' => dev_round6($restanteBase / $factor),
                ]
            );
        }

        $cdId = (int) $r['compra_detalle_id'];
        if (!isset($gruposImporte[$cdId])) {
            $gruposImporte[$cdId] = [
                'cantidad_base' => 0.0,
                'cantidad_comprada_base' => (float) $r['cantidad_comprada_base'],
                'importe_linea' => (float) $r['importe_linea_compra'],
                'prev_cantidad' => (float) ($prevPorCompraDetalle[$cdId]['cantidad'] ?? 0.0),
                'prev_importe' => (float) ($prevPorCompraDetalle[$cdId]['importe'] ?? 0.0),
                'indices' => [],
            ];
        }

        $indice = count($procesadas);
        $procesadas[] = [
            'recepcion_detalle_id' => $rdId,
            'compra_detalle_id' => $cdId,
            'almacen_id' => (int) $r['almacen_id'],
            'producto_id' => (int) $r['producto_id'],
            'producto' => (string) $r['producto_nombre_snapshot'],
            'recepcion_folio' => (string) $r['recepcion_folio'],
            'cantidad_base' => $cantidadBase,
            'importe' => 0.0,
            'controla_inventario' => (int) $r['controla_inventario'],
        ];
        $gruposImporte[$cdId]['cantidad_base'] = dev_round6($gruposImporte[$cdId]['cantidad_base'] + $cantidadBase);
        $gruposImporte[$cdId]['indices'][] = $indice;
    }

    foreach ($gruposImporte as $cdId => $g) {
        $compradaBase = (float) $g['cantidad_comprada_base'];
        if ($compradaBase <= 0) {
            dev_cancelar($conexion, 'Un renglón de compra tiene una cantidad histórica inválida.', 409);
        }

        $nuevaAcumulada = dev_round6((float) $g['prev_cantidad'] + (float) $g['cantidad_base']);
        if ($nuevaAcumulada - $compradaBase > 0.0000001) {
            dev_cancelar($conexion, 'La devolución acumulada excedería la cantidad originalmente comprada.', 409);
        }

        if (abs($nuevaAcumulada - $compradaBase) <= 0.0000001) {
            $importeGrupo = dev_round4(max(0.0, (float) $g['importe_linea'] - (float) $g['prev_importe']));
        } else {
            $importeGrupo = dev_round4((float) $g['importe_linea'] * ((float) $g['cantidad_base'] / $compradaBase));
            $importeGrupo = min(
                $importeGrupo,
                dev_round4(max(0.0, (float) $g['importe_linea'] - (float) $g['prev_importe']))
            );
        }

        $restanteImporteGrupo = $importeGrupo;
        $indices = $g['indices'];
        foreach ($indices as $pos => $idx) {
            if ($pos === count($indices) - 1) {
                $importe = dev_round4($restanteImporteGrupo);
            } else {
                $parte = (float) $procesadas[$idx]['cantidad_base'] / (float) $g['cantidad_base'];
                $importe = dev_round4($importeGrupo * $parte);
                $restanteImporteGrupo = dev_round4($restanteImporteGrupo - $importe);
            }
            $procesadas[$idx]['importe'] = $importe;
        }
    }

    $total = 0.0;
    foreach ($procesadas as $p) {
        $total = dev_round4($total + (float) $p['importe']);
    }
    $totalDevueltoAntes = dev_total_devoluciones_compra($conexion, $compraId, true);
    if ($totalDevueltoAntes + $total - (float) $compra['total'] > 0.00005) {
        dev_cancelar($conexion, 'La devolución excedería el total original de la compra.', 409);
    }

    dev_validar_disponibilidad_compra($conexion, $procesadas);

    $folioTmp = 'TMP-DEV-COM-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO devoluciones_compra
            (folio, compra_id, proveedor_id, fecha_devolucion, estado, motivo, total,
             importe_compensado_cxp, importe_reintegro, afecta_cuenta_por_pagar,
             regularizacion_estado, idempotency_key, observaciones, created_by)
         VALUES
            (:folio, :compra_id, :proveedor_id, NOW(), 'BORRADOR', :motivo, :total,
             0, 0, 0, 'NO_APLICA', :idempotency_key, :observaciones, :usuario)"
    );
    $stmt->execute([
        ':folio' => $folioTmp,
        ':compra_id' => $compraId,
        ':proveedor_id' => (int) $compra['proveedor_id'],
        ':motivo' => $motivo,
        ':total' => $total,
        ':idempotency_key' => $idempotencyKey,
        ':observaciones' => $observaciones,
        ':usuario' => (int) $_SESSION['usuario_id'],
    ]);
    $devolucionId = (int) $conexion->lastInsertId();
    $folio = 'DEV-COM-' . str_pad((string) $devolucionId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE devoluciones_compra SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $devolucionId]);

    $stmtDet = $conexion->prepare(
        "INSERT INTO devoluciones_compra_detalle
            (devolucion_id, compra_detalle_id, recepcion_detalle_id, almacen_id, producto_id, cantidad_base, importe)
         VALUES
            (:devolucion_id, :compra_detalle_id, :recepcion_detalle_id, :almacen_id, :producto_id, :cantidad_base, :importe)"
    );
    foreach ($procesadas as $p) {
        $stmtDet->execute([
            ':devolucion_id' => $devolucionId,
            ':compra_detalle_id' => $p['compra_detalle_id'],
            ':recepcion_detalle_id' => $p['recepcion_detalle_id'],
            ':almacen_id' => $p['almacen_id'],
            ':producto_id' => $p['producto_id'],
            ':cantidad_base' => $p['cantidad_base'],
            ':importe' => $p['importe'],
        ]);
    }

    $movimientoId = dev_aplicar_salida_devolucion_compra($conexion, $devolucionId, $folio, $procesadas);

    $finanzasAntes = dev_resumen_financiero_compra($conexion, $compraId, true);
    $compensado = min($total, (float) $finanzasAntes['saldo_cxp']);
    $compensado = dev_round4(max(0.0, $compensado));
    $reintegro = dev_round4(max(0.0, $total - $compensado));

    $cxpId = $finanzasAntes['cuenta_por_pagar_id'];
    $cxpDespues = null;

    if ($compra['condicion_pago'] === 'CREDITO' && $cxpId === null) {
        dev_cancelar($conexion, 'La compra a crédito no tiene una cuenta por pagar asociada. Revisa la integridad financiera antes de devolver.', 409);
    }

    if ($cxpId !== null && $compensado > 0.00005) {
        $nuevoOriginal = dev_round4((float) $finanzasAntes['cxp_importe_original'] - $compensado);
        if ($nuevoOriginal + 0.00005 < (float) $finanzasAntes['cxp_importe_pagado']) {
            dev_cancelar($conexion, 'La regularización dejaría pagos aplicados por encima del importe de la cuenta por pagar.', 409);
        }

        $conexion->prepare(
            "UPDATE cuentas_por_pagar
             SET importe_original = :importe_original
             WHERE id = :id"
        )->execute([
            ':importe_original' => $nuevoOriginal,
            ':id' => $cxpId,
        ]);

        $cxpDespues = dev_recalcular_cxp($conexion, $cxpId);
    } elseif ($cxpId !== null) {
        $cxpDespues = dev_recalcular_cxp($conexion, $cxpId);
    }

    $regularizacionId = null;
    $regularizacionEstado = 'NO_APLICA';

    if ($reintegro > 0.00005) {
        $liquidada = $resolverAhora;
        $metodo = null;
        if ($liquidada) {
            $metodo = dev_metodo_pago_activo($conexion, $metodoPagoId);
            if (!$metodo) {
                dev_cancelar($conexion, 'Selecciona un método válido para registrar el reintegro del proveedor.', 422);
            }
            if ((int) $metodo['requiere_referencia'] === 1 && $referencia === null) {
                dev_cancelar($conexion, 'El método seleccionado requiere referencia.', 422);
            }
        }

        $regularizacionId = dev_crear_regularizacion(
            $conexion,
            'REINTEGRO_PROVEEDOR',
            null,
            $devolucionId,
            null,
            (int) $compra['proveedor_id'],
            (int) $compra['moneda_id'],
            (float) $compra['tipo_cambio_a_base'],
            $reintegro,
            $liquidada,
            $metodo ? (int) $metodo['id'] : null,
            $referencia,
            $observacionFinanciera
        );
        $regularizacionEstado = $liquidada ? 'LIQUIDADA' : 'PENDIENTE';
    }

    $conexion->prepare(
        "UPDATE devoluciones_compra
         SET estado = 'CONFIRMADA',
             importe_compensado_cxp = :compensado,
             importe_reintegro = :reintegro,
             afecta_cuenta_por_pagar = :afecta,
             regularizacion_estado = :regularizacion_estado
         WHERE id = :id"
    )->execute([
        ':compensado' => $compensado,
        ':reintegro' => $reintegro,
        ':afecta' => $compensado > 0.00005 ? 1 : 0,
        ':regularizacion_estado' => $regularizacionEstado,
        ':id' => $devolucionId,
    ]);

    dev_auditar(
        $conexion,
        'DEVOLUCION_COMPRA_CONFIRMADA',
        'devoluciones_compra',
        $devolucionId,
        'Se confirmó la devolución a proveedor ' . $folio . ' vinculada a la compra ' . $compra['folio'] . '.',
        null,
        [
            'folio' => $folio,
            'compra_id' => $compraId,
            'total' => $total,
            'movimiento_inventario_id' => $movimientoId,
            'cuenta_por_pagar_id' => $cxpId,
            'importe_compensado_cxp' => $compensado,
            'importe_reintegro' => $reintegro,
            'regularizacion_financiera_id' => $regularizacionId,
            'cxp_antes' => $finanzasAntes,
            'cxp_despues' => $cxpDespues,
        ]
    );

    $conexion->commit();

    $mensaje = 'Devolución a proveedor confirmada. Inventario y Kardex fueron actualizados.';
    if ($reintegro > 0.00005) {
        $mensaje .= $regularizacionEstado === 'LIQUIDADA'
            ? ' El reintegro quedó liquidado.'
            : ' Quedó un reintegro de proveedor pendiente de registrar.';
    }

    si_responder_json(true, $mensaje, [
        'devolucion_id' => $devolucionId,
        'folio' => $folio,
        'movimiento_inventario_id' => $movimientoId,
        'importe_compensado_cxp' => $compensado,
        'importe_reintegro' => $reintegro,
        'regularizacion_financiera_id' => $regularizacionId,
        'regularizacion_estado' => $regularizacionEstado,
    ], 201);
}

/* =========================================================================
   REGULARIZACIONES FINANCIERAS
   ========================================================================= */

function dev_listar_regularizaciones(PDO $conexion): void
{
    $estado = strtoupper(dev_texto($_GET['estado'] ?? 'TODAS', 20));
    if (!in_array($estado, ['TODAS', 'PENDIENTE', 'LIQUIDADA'], true)) {
        $estado = 'TODAS';
    }
    $q = dev_texto($_GET['busqueda'] ?? '', 180);
    $pagina = dev_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = dev_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);

    $where = ["rf.estado <> 'CANCELADA'"];
    $params = [];

    if ($estado !== 'TODAS') {
        $where[] = 'rf.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($q !== '') {
        $where[] = "(rf.folio LIKE :q1 OR COALESCE(v.folio, '') LIKE :q2 OR COALESCE(c.folio, '') LIKE :q3
                     OR COALESCE(cl.nombre_razon_social, '') LIKE :q4 OR COALESCE(p.razon_social, '') LIKE :q5)";
        $like = '%' . $q . '%';
        foreach ([':q1', ':q2', ':q3', ':q4', ':q5'] as $k) {
            $params[$k] = $like;
        }
    }

    $from = "FROM regularizaciones_financieras rf
             LEFT JOIN devoluciones_venta dv ON dv.id = rf.devolucion_venta_id
             LEFT JOIN ventas v ON v.id = dv.venta_id
             LEFT JOIN clientes cl ON cl.id = rf.cliente_id
             LEFT JOIN devoluciones_compra dc ON dc.id = rf.devolucion_compra_id
             LEFT JOIN compras c ON c.id = dc.compra_id
             LEFT JOIN proveedores p ON p.id = rf.proveedor_id
             INNER JOIN monedas m ON m.id = rf.moneda_id
             LEFT JOIN metodos_pago mp ON mp.id = rf.metodo_pago_id
             LEFT JOIN usuarios u ON u.id = rf.liquidada_by";
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    dev_bind($stmt, $params);
    $stmt->execute();
    $total = (int) $stmt->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            rf.id, rf.folio, rf.tipo, rf.importe, rf.estado, rf.referencia,
            rf.created_at, rf.liquidada_at, rf.observaciones,
            m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
            mp.codigo AS metodo_codigo, mp.nombre AS metodo_nombre,
            COALESCE(v.folio, c.folio) AS documento_folio,
            COALESCE(dv.folio, dc.folio) AS devolucion_folio,
            COALESCE(cl.nombre_razon_social, p.razon_social, 'Público general') AS tercero,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS liquidada_por
         {$from}
         {$whereSql}
         ORDER BY CASE WHEN rf.estado = 'PENDIENTE' THEN 0 ELSE 1 END,
                  rf.created_at DESC, rf.id DESC
         LIMIT :limite OFFSET :offset"
    );
    dev_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['importe'] = (float) $f['importe'];
    }
    unset($f);

    si_responder_json(true, 'Regularizaciones cargadas.', [
        'regularizaciones' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function dev_liquidar_regularizacion(PDO $conexion): void
{
    $id = dev_id($_POST['regularizacion_id'] ?? null, 'regularización');
    $metodoId = dev_id($_POST['metodo_pago_id'] ?? null, 'método');
    $referencia = dev_nullable($_POST['referencia'] ?? null, 120);
    $observaciones = dev_nullable($_POST['observaciones'] ?? null, 3000);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT *
         FROM regularizaciones_financieras
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $reg = $stmt->fetch();

    if (!$reg) {
        dev_cancelar($conexion, 'La regularización ya no existe.', 404);
    }
    if ($reg['estado'] === 'LIQUIDADA') {
        $conexion->commit();
        si_responder_json(true, 'La regularización ya estaba liquidada.');
    }
    if ($reg['estado'] !== 'PENDIENTE') {
        dev_cancelar($conexion, 'La regularización no puede liquidarse en su estado actual.', 409);
    }

    $metodo = dev_metodo_pago_activo($conexion, $metodoId);
    if (!$metodo) {
        dev_cancelar($conexion, 'El método seleccionado ya no está disponible.', 409);
    }
    if ((int) $metodo['requiere_referencia'] === 1 && $referencia === null) {
        dev_cancelar($conexion, 'El método seleccionado requiere referencia.', 422);
    }

    $conexion->prepare(
        "UPDATE regularizaciones_financieras
         SET estado = 'LIQUIDADA',
             metodo_pago_id = :metodo,
             referencia = :referencia,
             observaciones = COALESCE(:observaciones, observaciones),
             liquidada_at = NOW(),
             liquidada_by = :usuario
         WHERE id = :id"
    )->execute([
        ':metodo' => $metodoId,
        ':referencia' => $referencia,
        ':observaciones' => $observaciones,
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    if ($reg['devolucion_venta_id'] !== null) {
        $conexion->prepare(
            "UPDATE devoluciones_venta
             SET regularizacion_estado = 'LIQUIDADA'
             WHERE id = :id AND regularizacion_estado = 'PENDIENTE'"
        )->execute([':id' => (int) $reg['devolucion_venta_id']]);
    }
    if ($reg['devolucion_compra_id'] !== null) {
        $conexion->prepare(
            "UPDATE devoluciones_compra
             SET regularizacion_estado = 'LIQUIDADA'
             WHERE id = :id AND regularizacion_estado = 'PENDIENTE'"
        )->execute([':id' => (int) $reg['devolucion_compra_id']]);
    }

    dev_auditar(
        $conexion,
        'REGULARIZACION_FINANCIERA_LIQUIDADA',
        'regularizaciones_financieras',
        $id,
        'Se liquidó la regularización financiera ' . $reg['folio'] . '.',
        [
            'estado' => $reg['estado'],
            'importe' => (float) $reg['importe'],
        ],
        [
            'estado' => 'LIQUIDADA',
            'metodo_pago' => $metodo['codigo'],
            'referencia' => $referencia,
        ]
    );

    $conexion->commit();
    si_responder_json(true, 'Regularización liquidada correctamente.');
}

/* =========================================================================
   DETALLE DE DEVOLUCIÓN
   ========================================================================= */

function dev_detalle(PDO $conexion): void
{
    $tipo = strtoupper(dev_texto($_GET['tipo'] ?? '', 10));
    $id = dev_id($_GET['id'] ?? null, 'devolución');

    if ($tipo === 'VENTA') {
        $stmt = $conexion->prepare(
            "SELECT
                d.*, v.folio AS documento_folio, v.fecha_venta AS fecha_documento,
                COALESCE(v.cliente_nombre_snapshot, 'Público general') AS tercero,
                m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por
             FROM devoluciones_venta d
             INNER JOIN ventas v ON v.id = d.venta_id
             INNER JOIN monedas m ON m.id = v.moneda_id
             LEFT JOIN usuarios u ON u.id = d.created_by
             WHERE d.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $cabecera = $stmt->fetch();
        if (!$cabecera) {
            si_responder_json(false, 'La devolución no existe.', [], 404);
        }

        $stmt = $conexion->prepare(
            "SELECT
                dd.id, vd.renglon, vd.producto_nombre_snapshot AS producto, vd.sku_snapshot AS sku,
                vd.unidad_nombre_snapshot AS unidad, um.simbolo AS unidad_simbolo,
                vd.factor_a_unidad_base, dd.cantidad_base,
                dd.cantidad_base / vd.factor_a_unidad_base AS cantidad,
                dd.importe, a.codigo AS almacen_codigo, a.nombre AS almacen
             FROM devoluciones_venta_detalle dd
             INNER JOIN ventas_detalle vd ON vd.id = dd.venta_detalle_id
             INNER JOIN unidades_medida um ON um.id = vd.unidad_id
             INNER JOIN almacenes a ON a.id = dd.almacen_id
             WHERE dd.devolucion_id = :id
             ORDER BY vd.renglon ASC, dd.id ASC"
        );
        $stmt->execute([':id' => $id]);
        $detalles = $stmt->fetchAll();
    } elseif ($tipo === 'COMPRA') {
        $stmt = $conexion->prepare(
            "SELECT
                d.*, c.folio AS documento_folio, c.fecha_compra AS fecha_documento,
                c.proveedor_nombre_snapshot AS tercero,
                m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por
             FROM devoluciones_compra d
             INNER JOIN compras c ON c.id = d.compra_id
             INNER JOIN monedas m ON m.id = c.moneda_id
             LEFT JOIN usuarios u ON u.id = d.created_by
             WHERE d.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $cabecera = $stmt->fetch();
        if (!$cabecera) {
            si_responder_json(false, 'La devolución no existe.', [], 404);
        }

        $stmt = $conexion->prepare(
            "SELECT
                dd.id, cd.renglon, cd.producto_nombre_snapshot AS producto, cd.sku_snapshot AS sku,
                cd.unidad_nombre_snapshot AS unidad, um.simbolo AS unidad_simbolo,
                cd.factor_a_unidad_base, dd.cantidad_base,
                dd.cantidad_base / cd.factor_a_unidad_base AS cantidad,
                dd.importe, a.codigo AS almacen_codigo, a.nombre AS almacen,
                r.folio AS recepcion_folio
             FROM devoluciones_compra_detalle dd
             INNER JOIN compras_detalle cd ON cd.id = dd.compra_detalle_id
             INNER JOIN unidades_medida um ON um.id = cd.unidad_id
             INNER JOIN almacenes a ON a.id = dd.almacen_id
             LEFT JOIN recepciones_compra_detalle rd ON rd.id = dd.recepcion_detalle_id
             LEFT JOIN recepciones_compra r ON r.id = rd.recepcion_id
             WHERE dd.devolucion_id = :id
             ORDER BY cd.renglon ASC, dd.id ASC"
        );
        $stmt->execute([':id' => $id]);
        $detalles = $stmt->fetchAll();
    } else {
        si_responder_json(false, 'El tipo de devolución no es válido.', [], 422);
    }

    foreach ($detalles as &$d) {
        $d['id'] = (int) $d['id'];
        $d['renglon'] = (int) $d['renglon'];
        $d['factor_a_unidad_base'] = (float) $d['factor_a_unidad_base'];
        $d['cantidad_base'] = (float) $d['cantidad_base'];
        $d['cantidad'] = (float) $d['cantidad'];
        $d['importe'] = (float) $d['importe'];
    }
    unset($d);

    $stmt = $conexion->prepare(
        "SELECT
            rf.id, rf.folio, rf.tipo, rf.importe, rf.estado, rf.referencia,
            rf.created_at, rf.liquidada_at, rf.observaciones,
            mp.nombre AS metodo_nombre
         FROM regularizaciones_financieras rf
         LEFT JOIN metodos_pago mp ON mp.id = rf.metodo_pago_id
         WHERE " . ($tipo === 'VENTA' ? 'rf.devolucion_venta_id' : 'rf.devolucion_compra_id') . " = :id
           AND rf.estado <> 'CANCELADA'
         ORDER BY rf.id ASC"
    );
    $stmt->execute([':id' => $id]);
    $regularizaciones = $stmt->fetchAll();
    foreach ($regularizaciones as &$r) {
        $r['id'] = (int) $r['id'];
        $r['importe'] = (float) $r['importe'];
    }
    unset($r);

    $cabecera['id'] = (int) $cabecera['id'];
    $cabecera['total'] = (float) $cabecera['total'];

    si_responder_json(true, 'Detalle cargado.', [
        'tipo' => $tipo,
        'devolucion' => $cabecera,
        'detalles' => $detalles,
        'regularizaciones' => $regularizaciones,
    ]);
}

/* =========================================================================
   INVENTARIO
   ========================================================================= */

function dev_aplicar_entrada_devolucion_venta(PDO $conexion, int $devolucionId, string $folio, array $lineas): ?int
{
    $controladas = array_values(array_filter(
        $lineas,
        static fn(array $l): bool => (int) $l['controla_inventario'] === 1
    ));

    if (!$controladas) {
        return null;
    }

    $tipo = dev_tipo_movimiento($conexion, 'DEVOLUCION_VENTA');
    if (!$tipo) {
        dev_cancelar($conexion, 'No está configurado el tipo de movimiento DEVOLUCION_VENTA.', 500);
    }

    $bloqueos = dev_bloquear_existencias_agrupadas($conexion, $controladas);
    $movimientoId = dev_crear_movimiento(
        $conexion,
        (int) $tipo['id'],
        'DEVOLUCION_VENTA',
        $devolucionId,
        'DEVOLUCION_VENTA:' . $devolucionId,
        'Entrada por devolución ' . $folio
    );

    $estado = [];
    foreach ($bloqueos as $clave => $e) {
        $estado[$clave] = [
            'id' => (int) $e['id'],
            'fisica' => (float) $e['existencia_fisica'],
            'reservada' => (float) $e['cantidad_reservada'],
            'costo' => $e['costo_promedio_base'] !== null ? (float) $e['costo_promedio_base'] : null,
        ];
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta,
             existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, :renglon, :almacen, :producto, :delta,
             :antes, :despues, :costo, :observaciones)"
    );

    $renglon = 1;
    foreach ($controladas as $l) {
        $clave = $l['almacen_id'] . ':' . $l['producto_id'];
        $e = &$estado[$clave];

        $antes = $e['fisica'];
        $entrada = (float) $l['cantidad_base'];
        $despues = dev_round6($antes + $entrada);
        $costoEntrada = $l['costo_unitario_base'];

        $nuevoCosto = $e['costo'];
        if ($despues > 0.0000001 && $costoEntrada !== null) {
            $valorAnterior = ($antes > 0 && $e['costo'] !== null) ? $antes * $e['costo'] : 0.0;
            $nuevoCosto = dev_round6(($valorAnterior + ($entrada * (float) $costoEntrada)) / $despues);
        } elseif ($e['costo'] === null && $costoEntrada !== null) {
            $nuevoCosto = (float) $costoEntrada;
        }

        $stmtDet->execute([
            ':movimiento' => $movimientoId,
            ':renglon' => $renglon++,
            ':almacen' => (int) $l['almacen_id'],
            ':producto' => (int) $l['producto_id'],
            ':delta' => $entrada,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $costoEntrada,
            ':observaciones' => 'Entrada por ' . $folio . ' · renglón de venta ' . $l['renglon'],
        ]);

        $e['fisica'] = $despues;
        $e['costo'] = $nuevoCosto;
        unset($e);
    }

    foreach ($estado as $e) {
        $conexion->prepare(
            "UPDATE existencias_almacen
             SET existencia_fisica = :fisica,
                 costo_promedio_base = :costo
             WHERE id = :id"
        )->execute([
            ':fisica' => $e['fisica'],
            ':costo' => $e['costo'],
            ':id' => $e['id'],
        ]);
    }

    dev_aplicar_movimiento($conexion, $movimientoId);
    return $movimientoId;
}

function dev_validar_disponibilidad_compra(PDO $conexion, array $lineas): void
{
    $controladas = array_values(array_filter(
        $lineas,
        static fn(array $l): bool => (int) $l['controla_inventario'] === 1
    ));
    if (!$controladas) {
        return;
    }

    $requeridos = [];
    foreach ($controladas as $l) {
        $clave = $l['almacen_id'] . ':' . $l['producto_id'];
        if (!isset($requeridos[$clave])) {
            $requeridos[$clave] = [
                'almacen_id' => (int) $l['almacen_id'],
                'producto_id' => (int) $l['producto_id'],
                'producto' => (string) $l['producto'],
                'cantidad' => 0.0,
            ];
        }
        $requeridos[$clave]['cantidad'] = dev_round6($requeridos[$clave]['cantidad'] + (float) $l['cantidad_base']);
    }

    uasort($requeridos, static fn(array $a, array $b): int =>
        [$a['almacen_id'], $a['producto_id']] <=> [$b['almacen_id'], $b['producto_id']]
    );

    foreach ($requeridos as $r) {
        $e = dev_bloquear_existencia($conexion, $r['almacen_id'], $r['producto_id'], false);
        $disponible = (float) $e['cantidad_disponible'];
        if ($disponible + 0.0000001 < (float) $r['cantidad']) {
            dev_cancelar(
                $conexion,
                'No hay inventario disponible suficiente para devolver ' . $r['producto'] . ' al proveedor sin consumir stock reservado.',
                409,
                [
                    'almacen_id' => $r['almacen_id'],
                    'producto_id' => $r['producto_id'],
                    'disponible_base' => dev_round6($disponible),
                    'requerido_base' => dev_round6((float) $r['cantidad']),
                ]
            );
        }
    }
}

function dev_aplicar_salida_devolucion_compra(PDO $conexion, int $devolucionId, string $folio, array $lineas): ?int
{
    $controladas = array_values(array_filter(
        $lineas,
        static fn(array $l): bool => (int) $l['controla_inventario'] === 1
    ));
    if (!$controladas) {
        return null;
    }

    $tipo = dev_tipo_movimiento($conexion, 'DEVOLUCION_COMPRA');
    if (!$tipo) {
        dev_cancelar($conexion, 'No está configurado el tipo de movimiento DEVOLUCION_COMPRA.', 500);
    }

    $bloqueos = dev_bloquear_existencias_agrupadas($conexion, $controladas);
    $requeridos = [];
    foreach ($controladas as $l) {
        $clave = $l['almacen_id'] . ':' . $l['producto_id'];
        $requeridos[$clave] = dev_round6(($requeridos[$clave] ?? 0.0) + (float) $l['cantidad_base']);
    }

    foreach ($requeridos as $clave => $cantidad) {
        $e = $bloqueos[$clave];
        if ((float) $e['cantidad_disponible'] + 0.0000001 < $cantidad) {
            dev_cancelar($conexion, 'El inventario disponible cambió mientras se confirmaba la devolución. Recarga y vuelve a intentarlo.', 409);
        }
    }

    $movimientoId = dev_crear_movimiento(
        $conexion,
        (int) $tipo['id'],
        'DEVOLUCION_COMPRA',
        $devolucionId,
        'DEVOLUCION_COMPRA:' . $devolucionId,
        'Salida por devolución ' . $folio
    );

    $estado = [];
    foreach ($bloqueos as $clave => $e) {
        $estado[$clave] = [
            'id' => (int) $e['id'],
            'fisica' => (float) $e['existencia_fisica'],
            'reservada' => (float) $e['cantidad_reservada'],
            'costo' => $e['costo_promedio_base'] !== null ? (float) $e['costo_promedio_base'] : null,
        ];
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta,
             existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, :renglon, :almacen, :producto, :delta,
             :antes, :despues, :costo, :observaciones)"
    );

    $renglon = 1;
    foreach ($controladas as $l) {
        $clave = $l['almacen_id'] . ':' . $l['producto_id'];
        $e = &$estado[$clave];
        $antes = $e['fisica'];
        $salida = (float) $l['cantidad_base'];
        $despues = dev_round6($antes - $salida);

        if ($despues + 0.0000001 < $e['reservada']) {
            dev_cancelar($conexion, 'La devolución a proveedor consumiría inventario reservado. No se aplicó.', 409);
        }

        $stmtDet->execute([
            ':movimiento' => $movimientoId,
            ':renglon' => $renglon++,
            ':almacen' => (int) $l['almacen_id'],
            ':producto' => (int) $l['producto_id'],
            ':delta' => -$salida,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $e['costo'],
            ':observaciones' => 'Salida por ' . $folio . ' · recepción ' . $l['recepcion_folio'],
        ]);

        $e['fisica'] = $despues;
        unset($e);
    }

    foreach ($estado as $e) {
        $conexion->prepare(
            "UPDATE existencias_almacen
             SET existencia_fisica = :fisica
             WHERE id = :id"
        )->execute([
            ':fisica' => $e['fisica'],
            ':id' => $e['id'],
        ]);
    }

    dev_aplicar_movimiento($conexion, $movimientoId);
    return $movimientoId;
}

function dev_bloquear_existencias_agrupadas(PDO $conexion, array $lineas): array
{
    $grupos = [];
    foreach ($lineas as $l) {
        $clave = $l['almacen_id'] . ':' . $l['producto_id'];
        $grupos[$clave] = [
            'almacen_id' => (int) $l['almacen_id'],
            'producto_id' => (int) $l['producto_id'],
        ];
    }

    uasort($grupos, static fn(array $a, array $b): int =>
        [$a['almacen_id'], $a['producto_id']] <=> [$b['almacen_id'], $b['producto_id']]
    );

    $bloqueos = [];
    foreach ($grupos as $clave => $g) {
        $bloqueos[$clave] = dev_bloquear_existencia($conexion, $g['almacen_id'], $g['producto_id'], true);
    }
    return $bloqueos;
}

function dev_bloquear_existencia(PDO $conexion, int $almacenId, int $productoId, bool $crear): array
{
    if ($crear) {
        $stmt = $conexion->prepare(
            "INSERT IGNORE INTO existencias_almacen
                (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden, costo_promedio_base)
             VALUES
                (:almacen, :producto, 0, 0, 0, NULL, NULL)"
        );
        $stmt->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    }

    $stmt = $conexion->prepare(
        "SELECT *
         FROM existencias_almacen
         WHERE almacen_id = :almacen AND producto_id = :producto
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    $e = $stmt->fetch();

    if (!$e) {
        dev_cancelar($conexion, 'No existe un registro de inventario para el producto y almacén seleccionados.', 409);
    }
    return $e;
}

function dev_tipo_movimiento(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare(
        "SELECT id, codigo
         FROM tipos_movimiento_inventario
         WHERE codigo = :codigo AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([':codigo' => $codigo]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $r['id'] = (int) $r['id'];
    return $r;
}

function dev_crear_movimiento(
    PDO $conexion,
    int $tipoMovimientoId,
    string $origenTipo,
    int $origenId,
    string $idempotencyKey,
    string $motivo
): int {
    $stmt = $conexion->prepare(
        "SELECT id
         FROM movimientos_inventario
         WHERE idempotency_key = :clave
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':clave' => $idempotencyKey]);
    if ($stmt->fetchColumn()) {
        dev_cancelar($conexion, 'Esta devolución de inventario ya fue procesada. Recarga la página.', 409);
    }

    $tmp = 'TMP-MOV-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id,
             idempotency_key, movimiento_revertido_id, motivo, observaciones, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'BORRADOR', :origen_tipo, :origen_id,
             :clave, NULL, :motivo, NULL, :usuario)"
    );
    $stmt->execute([
        ':folio' => $tmp,
        ':tipo' => $tipoMovimientoId,
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $origenId,
        ':clave' => $idempotencyKey,
        ':motivo' => $motivo,
        ':usuario' => (int) $_SESSION['usuario_id'],
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'MOV-' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE movimientos_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);
    return $id;
}

function dev_aplicar_movimiento(PDO $conexion, int $movimientoId): void
{
    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET estado = 'APLICADO', aplicado_at = NOW(), aplicado_by = :usuario
         WHERE id = :id AND estado = 'BORRADOR'"
    )->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':id' => $movimientoId,
    ]);
}

function dev_idempotency_key(mixed $valor): string
{
    $clave = trim((string) ($valor ?? ''));
    if (strlen($clave) < 16 || strlen($clave) > 120 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clave)) {
        throw new InvalidArgumentException('La clave de seguridad de la operación no es válida. Recarga el formulario y vuelve a intentarlo.');
    }
    return $clave;
}

function dev_devolucion_idempotente_venta(PDO $conexion, string $clave, int $ventaId): ?array
{
    $stmt = $conexion->prepare(
        "SELECT d.id, d.folio, d.venta_id, d.estado, d.importe_compensado_cxc, d.importe_reembolso,
                d.regularizacion_estado,
                mi.id AS movimiento_inventario_id,
                rf.id AS regularizacion_financiera_id
         FROM devoluciones_venta d
         LEFT JOIN movimientos_inventario mi
            ON mi.origen_tipo = 'DEVOLUCION_VENTA' AND mi.origen_id = d.id AND mi.estado = 'APLICADO'
         LEFT JOIN regularizaciones_financieras rf
            ON rf.devolucion_venta_id = d.id AND rf.estado <> 'CANCELADA'
         WHERE d.idempotency_key = :clave
         LIMIT 1"
    );
    $stmt->execute([':clave' => $clave]);
    $d = $stmt->fetch();
    if (!$d) {
        return null;
    }
    if ((int) $d['venta_id'] !== $ventaId) {
        dev_cancelar($conexion, 'La clave de seguridad ya fue utilizada por otra devolución.', 409);
    }
    if ((string) $d['estado'] !== 'CONFIRMADA') {
        dev_cancelar($conexion, 'Existe una devolución previa con esta clave que no quedó confirmada. Recarga el formulario antes de continuar.', 409);
    }
    return [
        'devolucion_id' => (int) $d['id'],
        'folio' => (string) $d['folio'],
        'movimiento_inventario_id' => $d['movimiento_inventario_id'] !== null ? (int) $d['movimiento_inventario_id'] : null,
        'importe_compensado_cxc' => (float) $d['importe_compensado_cxc'],
        'importe_reembolso' => (float) $d['importe_reembolso'],
        'regularizacion_financiera_id' => $d['regularizacion_financiera_id'] !== null ? (int) $d['regularizacion_financiera_id'] : null,
        'regularizacion_estado' => (string) $d['regularizacion_estado'],
        'idempotente' => true,
    ];
}

function dev_devolucion_idempotente_compra(PDO $conexion, string $clave, int $compraId): ?array
{
    $stmt = $conexion->prepare(
        "SELECT d.id, d.folio, d.compra_id, d.estado, d.importe_compensado_cxp, d.importe_reintegro,
                d.regularizacion_estado,
                mi.id AS movimiento_inventario_id,
                rf.id AS regularizacion_financiera_id
         FROM devoluciones_compra d
         LEFT JOIN movimientos_inventario mi
            ON mi.origen_tipo = 'DEVOLUCION_COMPRA' AND mi.origen_id = d.id AND mi.estado = 'APLICADO'
         LEFT JOIN regularizaciones_financieras rf
            ON rf.devolucion_compra_id = d.id AND rf.estado <> 'CANCELADA'
         WHERE d.idempotency_key = :clave
         LIMIT 1"
    );
    $stmt->execute([':clave' => $clave]);
    $d = $stmt->fetch();
    if (!$d) {
        return null;
    }
    if ((int) $d['compra_id'] !== $compraId) {
        dev_cancelar($conexion, 'La clave de seguridad ya fue utilizada por otra devolución.', 409);
    }
    if ((string) $d['estado'] !== 'CONFIRMADA') {
        dev_cancelar($conexion, 'Existe una devolución previa con esta clave que no quedó confirmada. Recarga el formulario antes de continuar.', 409);
    }
    return [
        'devolucion_id' => (int) $d['id'],
        'folio' => (string) $d['folio'],
        'movimiento_inventario_id' => $d['movimiento_inventario_id'] !== null ? (int) $d['movimiento_inventario_id'] : null,
        'importe_compensado_cxp' => (float) $d['importe_compensado_cxp'],
        'importe_reintegro' => (float) $d['importe_reintegro'],
        'regularizacion_financiera_id' => $d['regularizacion_financiera_id'] !== null ? (int) $d['regularizacion_financiera_id'] : null,
        'regularizacion_estado' => (string) $d['regularizacion_estado'],
        'idempotente' => true,
    ];
}

/* =========================================================================
   FINANZAS
   ========================================================================= */

function dev_resumen_financiero_venta(PDO $conexion, int $ventaId, bool $bloquear): array
{
    $sufijo = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexion->prepare(
        "SELECT id, importe_original, importe_pagado, saldo_pendiente, estado
         FROM cuentas_por_cobrar
         WHERE venta_id = :venta_id
         LIMIT 1{$sufijo}"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $cxc = $stmt->fetch();

    $cxcId = null;
    $original = 0.0;
    $pagadoCxc = 0.0;
    $saldo = 0.0;
    $estado = null;

    if ($cxc) {
        if ((string) $cxc['estado'] === 'CANCELADA') {
            dev_cancelar($conexion, 'La cuenta por cobrar asociada está cancelada. Revisa la integridad financiera antes de devolver.', 409);
        }
        $cxcId = (int) $cxc['id'];
        if ($bloquear) {
            $actual = dev_recalcular_cxc($conexion, $cxcId);
            $original = (float) $actual['importe_original'];
            $pagadoCxc = (float) $actual['importe_pagado'];
            $saldo = (float) $actual['saldo_pendiente'];
            $estado = (string) $actual['estado'];
        } else {
            $stmtPagado = $conexion->prepare(
                "SELECT COALESCE(SUM(app.importe_aplicado), 0)
                 FROM aplicaciones_pago_cliente app
                 INNER JOIN pagos_cliente pc ON pc.id = app.pago_cliente_id
                 WHERE app.cuenta_por_cobrar_id = :id
                   AND pc.estado = 'APLICADO'"
            );
            $stmtPagado->execute([':id' => $cxcId]);
            $pagadoCxc = dev_round4((float) $stmtPagado->fetchColumn());
            $original = dev_round4((float) $cxc['importe_original']);
            $saldo = dev_round4(max(0.0, $original - $pagadoCxc));
            $estado = (string) $cxc['estado'];
        }
    }

    $stmt = $conexion->prepare(
        "SELECT COALESCE(SUM(importe), 0)
         FROM pagos_venta
         WHERE venta_id = :venta_id AND estado = 'APLICADO'"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $pagosVenta = dev_round4((float) $stmt->fetchColumn());

    $stmt = $conexion->prepare(
        "SELECT COALESCE(SUM(a.importe), 0)
         FROM ventas v
         INNER JOIN anticipos_apartado a ON a.apartado_id = v.apartado_id
         WHERE v.id = :venta_id
           AND a.estado = 'APLICADO'"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $anticipos = dev_round4((float) $stmt->fetchColumn());

    return [
        'cuenta_por_cobrar_id' => $cxcId,
        'cxc_importe_original' => $original,
        'cxc_importe_pagado' => $pagadoCxc,
        'saldo_cxc' => $saldo,
        'cxc_estado' => $estado,
        'pagos_venta' => $pagosVenta,
        'anticipos_apartado' => $anticipos,
        'total_recibido_historico' => dev_round4($pagadoCxc + $pagosVenta + $anticipos),
    ];
}

function dev_resumen_financiero_compra(PDO $conexion, int $compraId, bool $bloquear): array
{
    $sufijo = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexion->prepare(
        "SELECT id, importe_original, importe_pagado, saldo_pendiente, estado
         FROM cuentas_por_pagar
         WHERE compra_id = :compra_id
         LIMIT 1{$sufijo}"
    );
    $stmt->execute([':compra_id' => $compraId]);
    $cxp = $stmt->fetch();

    $cxpId = null;
    $original = 0.0;
    $pagado = 0.0;
    $saldo = 0.0;
    $estado = null;

    if ($cxp) {
        if ((string) $cxp['estado'] === 'CANCELADA') {
            dev_cancelar($conexion, 'La cuenta por pagar asociada está cancelada. Revisa la integridad financiera antes de devolver.', 409);
        }
        $cxpId = (int) $cxp['id'];
        if ($bloquear) {
            $actual = dev_recalcular_cxp($conexion, $cxpId);
            $original = (float) $actual['importe_original'];
            $pagado = (float) $actual['importe_pagado'];
            $saldo = (float) $actual['saldo_pendiente'];
            $estado = (string) $actual['estado'];
        } else {
            $stmtPagado = $conexion->prepare(
                "SELECT COALESCE(SUM(app.importe_aplicado), 0)
                 FROM aplicaciones_pago_proveedor app
                 INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
                 WHERE app.cuenta_por_pagar_id = :id
                   AND pp.estado = 'APLICADO'"
            );
            $stmtPagado->execute([':id' => $cxpId]);
            $pagado = dev_round4((float) $stmtPagado->fetchColumn());
            $original = dev_round4((float) $cxp['importe_original']);
            $saldo = dev_round4(max(0.0, $original - $pagado));
            $estado = (string) $cxp['estado'];
        }
    }

    return [
        'cuenta_por_pagar_id' => $cxpId,
        'cxp_importe_original' => $original,
        'cxp_importe_pagado' => $pagado,
        'saldo_cxp' => $saldo,
        'cxp_estado' => $estado,
    ];
}

function dev_recalcular_cxc(PDO $conexion, int $cuentaId): array
{
    $stmt = $conexion->prepare(
        "SELECT *
         FROM cuentas_por_cobrar
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $cuentaId]);
    $c = $stmt->fetch();
    if (!$c) {
        dev_cancelar($conexion, 'La cuenta por cobrar ya no existe.', 404);
    }

    $stmt = $conexion->prepare(
        "SELECT COALESCE(SUM(app.importe_aplicado), 0)
         FROM aplicaciones_pago_cliente app
         INNER JOIN pagos_cliente pc ON pc.id = app.pago_cliente_id
         WHERE app.cuenta_por_cobrar_id = :id
           AND pc.estado = 'APLICADO'"
    );
    $stmt->execute([':id' => $cuentaId]);
    $pagado = dev_round4((float) $stmt->fetchColumn());
    $original = dev_round4((float) $c['importe_original']);

    if ($pagado - $original > 0.00005) {
        dev_cancelar($conexion, 'Los cobros aplicados exceden el importe actual de la cuenta por cobrar.', 409);
    }

    $saldo = dev_round4(max(0.0, $original - $pagado));
    $estado = $saldo <= 0.00005
        ? 'PAGADA'
        : (((string) $c['fecha_vencimiento'] < date('Y-m-d')) ? 'VENCIDA' : ($pagado > 0.00005 ? 'PARCIAL' : 'PENDIENTE'));

    $conexion->prepare(
        "UPDATE cuentas_por_cobrar
         SET importe_pagado = :pagado, estado = :estado
         WHERE id = :id"
    )->execute([
        ':pagado' => $pagado,
        ':estado' => $estado,
        ':id' => $cuentaId,
    ]);

    return [
        'id' => $cuentaId,
        'importe_original' => $original,
        'importe_pagado' => $pagado,
        'saldo_pendiente' => $saldo,
        'estado' => $estado,
    ];
}

function dev_recalcular_cxp(PDO $conexion, int $cuentaId): array
{
    $stmt = $conexion->prepare(
        "SELECT *
         FROM cuentas_por_pagar
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $cuentaId]);
    $c = $stmt->fetch();
    if (!$c) {
        dev_cancelar($conexion, 'La cuenta por pagar ya no existe.', 404);
    }

    $stmt = $conexion->prepare(
        "SELECT COALESCE(SUM(app.importe_aplicado), 0)
         FROM aplicaciones_pago_proveedor app
         INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
         WHERE app.cuenta_por_pagar_id = :id
           AND pp.estado = 'APLICADO'"
    );
    $stmt->execute([':id' => $cuentaId]);
    $pagado = dev_round4((float) $stmt->fetchColumn());
    $original = dev_round4((float) $c['importe_original']);

    if ($pagado - $original > 0.00005) {
        dev_cancelar($conexion, 'Los pagos aplicados exceden el importe actual de la cuenta por pagar.', 409);
    }

    $saldo = dev_round4(max(0.0, $original - $pagado));
    $estado = $saldo <= 0.00005
        ? 'PAGADA'
        : (((string) $c['fecha_vencimiento'] < date('Y-m-d')) ? 'VENCIDA' : ($pagado > 0.00005 ? 'PARCIAL' : 'PENDIENTE'));

    $conexion->prepare(
        "UPDATE cuentas_por_pagar
         SET importe_pagado = :pagado, estado = :estado
         WHERE id = :id"
    )->execute([
        ':pagado' => $pagado,
        ':estado' => $estado,
        ':id' => $cuentaId,
    ]);

    return [
        'id' => $cuentaId,
        'importe_original' => $original,
        'importe_pagado' => $pagado,
        'saldo_pendiente' => $saldo,
        'estado' => $estado,
    ];
}

function dev_crear_regularizacion(
    PDO $conexion,
    string $tipo,
    ?int $devolucionVentaId,
    ?int $devolucionCompraId,
    ?int $clienteId,
    ?int $proveedorId,
    int $monedaId,
    float $tipoCambio,
    float $importe,
    bool $liquidada,
    ?int $metodoPagoId,
    ?string $referencia,
    ?string $observaciones
): int {
    $tmp = 'TMP-REG-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO regularizaciones_financieras
            (folio, tipo, devolucion_venta_id, devolucion_compra_id, cliente_id, proveedor_id,
             moneda_id, tipo_cambio_a_base, importe, estado, metodo_pago_id, referencia,
             observaciones, liquidada_at, liquidada_by, created_by)
         VALUES
            (:folio, :tipo, :dev_venta, :dev_compra, :cliente, :proveedor,
             :moneda, :tipo_cambio, :importe, :estado, :metodo, :referencia,
             :observaciones, " . ($liquidada ? 'NOW()' : 'NULL') . ", :liquidada_by, :created_by)"
    );
    $stmt->execute([
        ':folio' => $tmp,
        ':tipo' => $tipo,
        ':dev_venta' => $devolucionVentaId,
        ':dev_compra' => $devolucionCompraId,
        ':cliente' => $clienteId,
        ':proveedor' => $proveedorId,
        ':moneda' => $monedaId,
        ':tipo_cambio' => dev_round8($tipoCambio),
        ':importe' => dev_round4($importe),
        ':estado' => $liquidada ? 'LIQUIDADA' : 'PENDIENTE',
        ':metodo' => $metodoPagoId,
        ':referencia' => $referencia,
        ':observaciones' => $observaciones,
        ':liquidada_by' => $liquidada ? (int) $_SESSION['usuario_id'] : null,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'REG-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE regularizaciones_financieras SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);

    dev_auditar(
        $conexion,
        $liquidada ? 'REGULARIZACION_FINANCIERA_CREADA_LIQUIDADA' : 'REGULARIZACION_FINANCIERA_CREADA_PENDIENTE',
        'regularizaciones_financieras',
        $id,
        $liquidada
            ? 'Se registró y liquidó una regularización financiera vinculada a una devolución.'
            : 'Se creó una regularización financiera pendiente vinculada a una devolución.',
        null,
        [
            'folio' => $folio,
            'tipo' => $tipo,
            'importe' => dev_round4($importe),
            'estado' => $liquidada ? 'LIQUIDADA' : 'PENDIENTE',
            'devolucion_venta_id' => $devolucionVentaId,
            'devolucion_compra_id' => $devolucionCompraId,
        ]
    );

    return $id;
}

function dev_metodo_pago_activo(PDO $conexion, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre, requiere_referencia
         FROM metodos_pago
         WHERE id = :id AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $m = $stmt->fetch();
    if (!$m) {
        return null;
    }
    $m['id'] = (int) $m['id'];
    $m['requiere_referencia'] = (int) $m['requiere_referencia'];
    return $m;
}

/* =========================================================================
   TOTALES / AUXILIARES DE NEGOCIO
   ========================================================================= */

function dev_total_devoluciones_venta(PDO $conexion, int $ventaId, bool $bloquear): float
{
    $sql = "SELECT id, total
            FROM devoluciones_venta
            WHERE venta_id = :venta_id AND estado = 'CONFIRMADA'
            ORDER BY id ASC" . ($bloquear ? ' FOR UPDATE' : '');
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':venta_id' => $ventaId]);
    $total = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $total = dev_round4($total + (float) $r['total']);
    }
    return $total;
}

function dev_total_devoluciones_compra(PDO $conexion, int $compraId, bool $bloquear): float
{
    $sql = "SELECT id, total
            FROM devoluciones_compra
            WHERE compra_id = :compra_id AND estado = 'CONFIRMADA'
            ORDER BY id ASC" . ($bloquear ? ' FOR UPDATE' : '');
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':compra_id' => $compraId]);
    $total = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $total = dev_round4($total + (float) $r['total']);
    }
    return $total;
}

function dev_almacen_activo(PDO $conexion, int $id): bool
{
    $stmt = $conexion->prepare("SELECT 1 FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

/* =========================================================================
   AUDITORÍA
   ========================================================================= */

function dev_auditar(
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
            (usuario_id, fecha_hora, accion, modulo, entidad_tabla, entidad_id,
             descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario, NOW(), :accion, 'devoluciones', :tabla, :entidad_id,
             :descripcion, :anterior, :nuevo, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':accion' => mb_substr($accion, 0, 60),
        ':tabla' => mb_substr($tabla, 0, 80),
        ':entidad_id' => $entidadId,
        ':descripcion' => mb_substr($descripcion, 0, 500),
        ':anterior' => $anterior !== null
            ? json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : null,
        ':nuevo' => $nuevo !== null
            ? json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : null,
        ':ip' => si_ip_cliente(),
        ':user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

/* =========================================================================
   VALIDACIÓN / RESPUESTAS
   ========================================================================= */

function dev_cancelar(PDO $conexion, string $mensaje, int $codigo = 422, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $datos, $codigo);
}

function dev_id($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Selecciona una ' . $nombre . ' válida.', ['campo' => $nombre], 422);
    }
    return (int) $id;
}

function dev_id_local($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        throw new InvalidArgumentException('El identificador de ' . $nombre . ' no es válido.');
    }
    return (int) $id;
}

function dev_entero_rango($valor, int $minimo, int $maximo, int $default): int
{
    $n = filter_var($valor, FILTER_VALIDATE_INT);
    if ($n === false) {
        return $default;
    }
    return max($minimo, min($maximo, (int) $n));
}

function dev_decimal_positivo_local($valor, string $nombre): float
{
    if (!is_scalar($valor)) {
        throw new InvalidArgumentException('La ' . $nombre . ' no es válida.');
    }
    $texto = str_replace(',', '.', trim((string) $valor));
    if ($texto === '' || !is_numeric($texto)) {
        throw new InvalidArgumentException('La ' . $nombre . ' no es válida.');
    }
    $n = (float) $texto;
    if (!is_finite($n) || $n <= 0) {
        throw new InvalidArgumentException('La ' . $nombre . ' debe ser mayor a cero.');
    }
    return $n;
}

function dev_requerido($valor, string $mensaje, int $maximo): string
{
    $texto = dev_texto($valor, $maximo);
    if ($texto === '') {
        si_responder_json(false, $mensaje, [], 422);
    }
    return $texto;
}

function dev_texto($valor, int $maximo): string
{
    if (!is_scalar($valor)) {
        return '';
    }
    return mb_substr(trim((string) $valor), 0, $maximo);
}

function dev_nullable($valor, int $maximo): ?string
{
    $texto = dev_texto($valor, $maximo);
    return $texto === '' ? null : $texto;
}

function dev_json_array($valor, string $nombre): array
{
    if (!is_string($valor)) {
        si_responder_json(false, 'El formato de ' . $nombre . ' no es válido.', [], 422);
    }
    $data = json_decode($valor, true);
    if (!is_array($data)) {
        si_responder_json(false, 'El formato de ' . $nombre . ' no es válido.', [], 422);
    }
    return $data;
}

function dev_bool($valor): bool
{
    return in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí', 'on'], true);
}

function dev_round4(float $n): float
{
    return round($n, 4);
}

function dev_round6(float $n): float
{
    return round($n, 6);
}

function dev_round8(float $n): float
{
    return round($n, 8);
}

function dev_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } elseif ($valor === null) {
            $stmt->bindValue($clave, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}
