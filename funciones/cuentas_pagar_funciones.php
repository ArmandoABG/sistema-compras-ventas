<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

/** @var PDO|null $conexion Conexión creada por inc/conexion.php. */
require_once __DIR__ . '/../inc/tipo_cambio_banxico.php';

si_requerir_permiso('cuentas_pagar.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_CUENTAS')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                cxp_catalogos($conexion);
                break;

            case 'LISTAR_CUENTAS':
                cxp_listar_cuentas($conexion);
                break;

            case 'DETALLE_CUENTA':
                cxp_detalle_cuenta($conexion);
                break;

            case 'HISTORIAL_CUENTA':
                cxp_historial_cuenta($conexion);
                break;

            case 'LISTAR_PAGOS':
                cxp_listar_pagos($conexion);
                break;

            case 'DETALLE_PAGO':
                cxp_detalle_pago($conexion);
                break;

            case 'VENCIMIENTOS':
                cxp_vencimientos($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('cuentas_pagar.pagar')) {
        si_responder_json(
            false,
            'No tienes permiso para registrar o cancelar pagos a proveedores.',
            [],
            403
        );
    }

    switch ($accion) {
        case 'REGISTRAR_ABONO':
            cxp_registrar_abono($conexion);
            break;

        case 'CANCELAR_PAGO':
            cxp_cancelar_pago($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CXP-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CUENTAS_PAGAR][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'La operación financiera ya fue registrada o entra en conflicto con otro movimiento.',
            ['referencia' => $referencia],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible procesar la operación financiera.',
        ['referencia' => $referencia],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CXP-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CUENTAS_PAGAR] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar cuentas por pagar.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS
   ========================================================================= */

function cxp_catalogos(PDO $conexion): void
{
    $metodos = $conexion->query(
        "SELECT id, codigo, nombre, requiere_referencia
         FROM metodos_pago
         WHERE activo = 1
         ORDER BY
            CASE codigo
                WHEN 'TRANSFERENCIA' THEN 0
                WHEN 'CHEQUE' THEN 1
                WHEN 'EFECTIVO' THEN 2
                WHEN 'TARJETA' THEN 3
                ELSE 4
            END,
            nombre ASC"
    )->fetchAll();

    $monedas = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, es_base
         FROM monedas
         WHERE activo = 1
         ORDER BY es_base DESC, codigo ASC"
    )->fetchAll();

    foreach ($metodos as &$m) {
        $m['id'] = (int) $m['id'];
        $m['requiere_referencia'] = (int) $m['requiere_referencia'];
    }
    unset($m);

    foreach ($monedas as &$m) {
        $m['id'] = (int) $m['id'];
        $m['es_base'] = (int) $m['es_base'];
    }
    unset($m);

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'metodos_pago' => $metodos,
            'monedas' => $monedas,
        ]
    );
}

/* =========================================================================
   DEUDAS / CUENTAS POR PAGAR
   ========================================================================= */

function cxp_listar_cuentas(PDO $conexion): void
{
    $pagina = cxp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cxp_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cxp_texto($_GET['estado'] ?? 'TODOS', 20));
    $vencimiento = strtoupper(cxp_texto($_GET['vencimiento'] ?? 'TODOS', 20));
    $monedaId = cxp_entero_rango($_GET['moneda_id'] ?? 0, 0, PHP_INT_MAX, 0);

    $estadosValidos = ['TODOS', 'PENDIENTE', 'PARCIAL', 'PAGADA', 'VENCIDA', 'CANCELADA'];
    $vencimientosValidos = ['TODOS', 'VENCIDAS', 'HOY', '7_DIAS', '15_DIAS', '30_DIAS', '60_DIAS'];

    if (!in_array($estado, $estadosValidos, true)) {
        $estado = 'TODOS';
    }

    if (!in_array($vencimiento, $vencimientosValidos, true)) {
        $vencimiento = 'TODOS';
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            c.folio LIKE :q_cxp
            OR co.folio LIKE :q_compra
            OR co.numero_factura LIKE :q_factura
            OR p.codigo LIKE :q_proveedor_codigo
            OR p.razon_social LIKE :q_proveedor_nombre
            OR p.nombre_comercial LIKE :q_proveedor_comercial
            OR p.rfc LIKE :q_rfc
        )";

        $like = '%' . $q . '%';
        $params[':q_cxp'] = $like;
        $params[':q_compra'] = $like;
        $params[':q_factura'] = $like;
        $params[':q_proveedor_codigo'] = $like;
        $params[':q_proveedor_nombre'] = $like;
        $params[':q_proveedor_comercial'] = $like;
        $params[':q_rfc'] = $like;
    }

    if ($monedaId > 0) {
        $where[] = 'c.moneda_id = :moneda_id';
        $params[':moneda_id'] = $monedaId;
    }

    cxp_agregar_filtro_estado($where, $estado);
    cxp_agregar_filtro_vencimiento($where, $vencimiento);

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $from = "FROM cuentas_por_pagar c
             INNER JOIN compras co ON co.id = c.compra_id
             INNER JOIN proveedores p ON p.id = c.proveedor_id
             INNER JOIN monedas m ON m.id = c.moneda_id";

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*) {$from} {$whereSql}"
    );
    cxp_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $estadoCase = cxp_estado_case('c');

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.compra_id,
            co.folio AS compra_folio,
            co.numero_factura,
            co.fecha_factura,
            p.id AS proveedor_id,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor,
            p.nombre_comercial AS proveedor_comercial,
            p.rfc AS proveedor_rfc,
            m.id AS moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            c.importe_original,
            c.importe_pagado,
            c.saldo_pendiente,
            c.fecha_documento,
            c.fecha_vencimiento,
            DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento,
            {$estadoCase} AS estado_calculado,
            (
                SELECT COUNT(*)
                FROM aplicaciones_pago_proveedor app
                INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
                WHERE app.cuenta_por_pagar_id = c.id
                  AND pp.estado = 'APLICADO'
            ) AS abonos_aplicados,
            c.created_at
         {$from}
         {$whereSql}
         ORDER BY
            CASE
                WHEN c.estado <> 'CANCELADA'
                 AND c.saldo_pendiente > 0.00005
                 AND c.fecha_vencimiento < CURDATE() THEN 0
                WHEN c.estado <> 'CANCELADA'
                 AND c.saldo_pendiente > 0.00005 THEN 1
                WHEN c.saldo_pendiente <= 0.00005 THEN 2
                ELSE 3
            END,
            c.fecha_vencimiento ASC,
            c.id DESC
         LIMIT :limite OFFSET :offset"
    );

    cxp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $cuentas = $stmt->fetchAll();

    foreach ($cuentas as &$c) {
        cxp_tipar_cuenta($c);
    }
    unset($c);

    $resumen = cxp_resumen_cuentas($conexion);

    si_responder_json(
        true,
        'Cuentas por pagar cargadas.',
        [
            'cuentas' => $cuentas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => $resumen,
        ]
    );
}

function cxp_detalle_cuenta(PDO $conexion): void
{
    $id = cxp_id($_GET['id'] ?? null, 'cuenta por pagar');
    $estadoCase = cxp_estado_case('c');

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.compra_id,
            co.folio AS compra_folio,
            co.fecha_compra,
            co.fecha_factura,
            co.numero_factura,
            co.condicion_pago,
            co.dias_credito,
            co.tipo_cambio_a_base AS tipo_cambio_compra,
            p.id AS proveedor_id,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor,
            p.nombre_comercial AS proveedor_comercial,
            p.rfc AS proveedor_rfc,
            p.telefono AS proveedor_telefono,
            p.correo AS proveedor_correo,
            m.id AS moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            c.importe_original,
            c.importe_pagado,
            c.saldo_pendiente,
            c.fecha_documento,
            c.fecha_vencimiento,
            DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento,
            {$estadoCase} AS estado_calculado,
            c.observaciones,
            c.created_at,
            c.updated_at
         FROM cuentas_por_pagar c
         INNER JOIN compras co ON co.id = c.compra_id
         INNER JOIN proveedores p ON p.id = c.proveedor_id
         INNER JOIN monedas m ON m.id = c.moneda_id
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $cuenta = $stmt->fetch();

    if (!$cuenta) {
        si_responder_json(false, 'No se encontró la cuenta por pagar.', [], 404);
    }

    cxp_tipar_cuenta($cuenta);

    $cuenta['puede_abonar'] = (
        $cuenta['estado_calculado'] !== 'CANCELADA'
        && $cuenta['estado_calculado'] !== 'PAGADA'
        && $cuenta['saldo_pendiente'] > 0.00005
    );

    si_responder_json(true, 'Cuenta encontrada.', ['cuenta' => $cuenta]);
}

function cxp_historial_cuenta(PDO $conexion): void
{
    $cuentaId = cxp_id($_GET['cuenta_id'] ?? null, 'cuenta por pagar');
    $pagina = cxp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxp_entero_rango($_GET['por_pagina'] ?? 10, 5, 100, 10);

    $stmtCuenta = $conexion->prepare(
        "SELECT id FROM cuentas_por_pagar WHERE id = :id LIMIT 1"
    );
    $stmtCuenta->execute([':id' => $cuentaId]);
    if (!$stmtCuenta->fetchColumn()) {
        si_responder_json(false, 'La cuenta por pagar ya no existe.', [], 404);
    }

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM aplicaciones_pago_proveedor app
         INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
         WHERE app.cuenta_por_pagar_id = :cuenta_id"
    );
    $stmtTotal->execute([':cuenta_id' => $cuentaId]);
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.folio,
            pp.fecha_pago,
            pp.importe AS importe_pago,
            app.importe_aplicado,
            pp.referencia,
            pp.estado,
            pp.motivo_cancelacion,
            mp.codigo AS metodo_codigo,
            mp.nombre AS metodo_nombre,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            u.usuario AS usuario,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS usuario_nombre
         FROM aplicaciones_pago_proveedor app
         INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
         INNER JOIN metodos_pago mp ON mp.id = pp.metodo_pago_id
         INNER JOIN monedas m ON m.id = pp.moneda_id
         LEFT JOIN usuarios u ON u.id = pp.created_by
         WHERE app.cuenta_por_pagar_id = :cuenta_id
         ORDER BY pp.fecha_pago DESC, pp.id DESC
         LIMIT :limite OFFSET :offset"
    );
    $stmt->bindValue(':cuenta_id', $cuentaId, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $pagos = $stmt->fetchAll();

    foreach ($pagos as &$pago) {
        $pago['id'] = (int) $pago['id'];
        $pago['importe_pago'] = (float) $pago['importe_pago'];
        $pago['importe_aplicado'] = (float) $pago['importe_aplicado'];
    }
    unset($pago);

    si_responder_json(
        true,
        'Historial cargado.',
        [
            'pagos' => $pagos,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cxp_resumen_cuentas(PDO $conexion): array
{
    $r = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(c.estado = 'CANCELADA') AS canceladas,
            SUM(c.estado <> 'CANCELADA' AND c.saldo_pendiente <= 0.00005) AS pagadas,
            SUM(c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 AND c.fecha_vencimiento < CURDATE()) AS vencidas,
            SUM(c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 AND c.fecha_vencimiento >= CURDATE() AND c.importe_pagado > 0.00005) AS parciales,
            SUM(c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 AND c.fecha_vencimiento >= CURDATE() AND c.importe_pagado <= 0.00005) AS pendientes,
            SUM(c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS proximas_7
         FROM cuentas_por_pagar c"
    )->fetch();

    $stmtMonedas = $conexion->query(
        "SELECT
            m.id AS moneda_id,
            m.codigo,
            m.simbolo,
            COUNT(*) AS cuentas_abiertas,
            SUM(c.saldo_pendiente) AS saldo_pendiente,
            SUM(
                CASE
                    WHEN c.fecha_vencimiento < CURDATE()
                    THEN c.saldo_pendiente
                    ELSE 0
                END
            ) AS saldo_vencido
         FROM cuentas_por_pagar c
         INNER JOIN monedas m ON m.id = c.moneda_id
         WHERE c.estado <> 'CANCELADA'
           AND c.saldo_pendiente > 0.00005
         GROUP BY m.id, m.codigo, m.simbolo, m.es_base
         ORDER BY m.es_base DESC, m.codigo ASC"
    );

    $monedas = $stmtMonedas->fetchAll();
    foreach ($monedas as &$m) {
        $m['moneda_id'] = (int) $m['moneda_id'];
        $m['cuentas_abiertas'] = (int) $m['cuentas_abiertas'];
        $m['saldo_pendiente'] = (float) $m['saldo_pendiente'];
        $m['saldo_vencido'] = (float) $m['saldo_vencido'];
    }
    unset($m);

    return [
        'total' => (int) ($r['total'] ?? 0),
        'pendientes' => (int) ($r['pendientes'] ?? 0),
        'parciales' => (int) ($r['parciales'] ?? 0),
        'pagadas' => (int) ($r['pagadas'] ?? 0),
        'vencidas' => (int) ($r['vencidas'] ?? 0),
        'canceladas' => (int) ($r['canceladas'] ?? 0),
        'proximas_7' => (int) ($r['proximas_7'] ?? 0),
        'saldos_por_moneda' => $monedas,
    ];
}

/* =========================================================================
   ABONOS / PAGOS
   ========================================================================= */

function cxp_listar_pagos(PDO $conexion): void
{
    $pagina = cxp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cxp_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cxp_texto($_GET['estado'] ?? 'TODOS', 20));
    $metodoId = cxp_entero_rango($_GET['metodo_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $desde = cxp_fecha_opcional($_GET['desde'] ?? '');
    $hasta = cxp_fecha_opcional($_GET['hasta'] ?? '');

    if (!in_array($estado, ['TODOS', 'APLICADO', 'CANCELADO'], true)) {
        $estado = 'TODOS';
    }

    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la fecha final.', [], 422);
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            pp.folio LIKE :q_pago
            OR pp.referencia LIKE :q_referencia
            OR p.codigo LIKE :q_proveedor_codigo
            OR p.razon_social LIKE :q_proveedor_nombre
            OR EXISTS (
                SELECT 1
                FROM aplicaciones_pago_proveedor aq
                INNER JOIN cuentas_por_pagar cq ON cq.id = aq.cuenta_por_pagar_id
                INNER JOIN compras coq ON coq.id = cq.compra_id
                WHERE aq.pago_proveedor_id = pp.id
                  AND (
                    cq.folio LIKE :q_cxp
                    OR coq.folio LIKE :q_compra
                    OR coq.numero_factura LIKE :q_factura
                  )
            )
        )";

        $like = '%' . $q . '%';
        $params[':q_pago'] = $like;
        $params[':q_referencia'] = $like;
        $params[':q_proveedor_codigo'] = $like;
        $params[':q_proveedor_nombre'] = $like;
        $params[':q_cxp'] = $like;
        $params[':q_compra'] = $like;
        $params[':q_factura'] = $like;
    }

    if ($estado !== 'TODOS') {
        $where[] = 'pp.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($metodoId > 0) {
        $where[] = 'pp.metodo_pago_id = :metodo_id';
        $params[':metodo_id'] = $metodoId;
    }

    if ($desde !== null) {
        $where[] = 'pp.fecha_pago >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }

    if ($hasta !== null) {
        $where[] = 'pp.fecha_pago < :hasta';
        $params[':hasta'] = (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $from = "FROM pagos_proveedor pp
             INNER JOIN proveedores p ON p.id = pp.proveedor_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             INNER JOIN metodos_pago mp ON mp.id = pp.metodo_pago_id
             LEFT JOIN usuarios u ON u.id = pp.created_by";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    cxp_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.folio,
            pp.fecha_pago,
            p.id AS proveedor_id,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor,
            m.id AS moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            mp.id AS metodo_pago_id,
            mp.codigo AS metodo_codigo,
            mp.nombre AS metodo_nombre,
            pp.importe,
            pp.tipo_cambio_a_base,
            pp.referencia,
            pp.estado,
            pp.observaciones,
            pp.motivo_cancelacion,
            pp.cancelado_at,
            u.usuario,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS usuario_nombre,
            (
                SELECT GROUP_CONCAT(c.folio ORDER BY c.id SEPARATOR ', ')
                FROM aplicaciones_pago_proveedor app
                INNER JOIN cuentas_por_pagar c ON c.id = app.cuenta_por_pagar_id
                WHERE app.pago_proveedor_id = pp.id
            ) AS cuentas_folios,
            (
                SELECT GROUP_CONCAT(co.folio ORDER BY co.id SEPARATOR ', ')
                FROM aplicaciones_pago_proveedor app2
                INNER JOIN cuentas_por_pagar c2 ON c2.id = app2.cuenta_por_pagar_id
                INNER JOIN compras co ON co.id = c2.compra_id
                WHERE app2.pago_proveedor_id = pp.id
            ) AS compras_folios,
            (
                SELECT COUNT(*)
                FROM aplicaciones_pago_proveedor app3
                WHERE app3.pago_proveedor_id = pp.id
            ) AS aplicaciones
         {$from}
         {$whereSql}
         ORDER BY pp.fecha_pago DESC, pp.id DESC
         LIMIT :limite OFFSET :offset"
    );

    cxp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $pagos = $stmt->fetchAll();

    foreach ($pagos as &$pago) {
        $pago['id'] = (int) $pago['id'];
        $pago['proveedor_id'] = (int) $pago['proveedor_id'];
        $pago['moneda_id'] = (int) $pago['moneda_id'];
        $pago['metodo_pago_id'] = (int) $pago['metodo_pago_id'];
        $pago['importe'] = (float) $pago['importe'];
        $pago['tipo_cambio_a_base'] = (float) $pago['tipo_cambio_a_base'];
        $pago['aplicaciones'] = (int) $pago['aplicaciones'];
    }
    unset($pago);

    $resumen = cxp_resumen_pagos($conexion);

    si_responder_json(
        true,
        'Pagos cargados.',
        [
            'pagos' => $pagos,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => $resumen,
        ]
    );
}

function cxp_detalle_pago(PDO $conexion): void
{
    $id = cxp_id($_GET['id'] ?? null, 'pago');

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.folio,
            pp.fecha_pago,
            pp.importe,
            pp.tipo_cambio_a_base,
            pp.referencia,
            pp.estado,
            pp.observaciones,
            pp.motivo_cancelacion,
            pp.cancelado_at,
            p.id AS proveedor_id,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            mp.codigo AS metodo_codigo,
            mp.nombre AS metodo_nombre,
            u.usuario,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS usuario_nombre,
            uc.usuario AS usuario_cancelo
         FROM pagos_proveedor pp
         INNER JOIN proveedores p ON p.id = pp.proveedor_id
         INNER JOIN monedas m ON m.id = pp.moneda_id
         INNER JOIN metodos_pago mp ON mp.id = pp.metodo_pago_id
         LEFT JOIN usuarios u ON u.id = pp.created_by
         LEFT JOIN usuarios uc ON uc.id = pp.cancelado_by
         WHERE pp.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $pago = $stmt->fetch();

    if (!$pago) {
        si_responder_json(false, 'No se encontró el pago.', [], 404);
    }

    $pago['id'] = (int) $pago['id'];
    $pago['proveedor_id'] = (int) $pago['proveedor_id'];
    $pago['importe'] = (float) $pago['importe'];
    $pago['tipo_cambio_a_base'] = (float) $pago['tipo_cambio_a_base'];

    $stmtApps = $conexion->prepare(
        "SELECT
            app.id,
            app.importe_aplicado,
            c.id AS cuenta_id,
            c.folio AS cuenta_folio,
            co.folio AS compra_folio,
            co.numero_factura,
            c.importe_original,
            c.fecha_vencimiento
         FROM aplicaciones_pago_proveedor app
         INNER JOIN cuentas_por_pagar c ON c.id = app.cuenta_por_pagar_id
         INNER JOIN compras co ON co.id = c.compra_id
         WHERE app.pago_proveedor_id = :pago_id
         ORDER BY app.id ASC"
    );
    $stmtApps->execute([':pago_id' => $id]);
    $aplicaciones = $stmtApps->fetchAll();

    foreach ($aplicaciones as &$a) {
        $a['id'] = (int) $a['id'];
        $a['cuenta_id'] = (int) $a['cuenta_id'];
        $a['importe_aplicado'] = (float) $a['importe_aplicado'];
        $a['importe_original'] = (float) $a['importe_original'];
    }
    unset($a);

    si_responder_json(
        true,
        'Pago encontrado.',
        [
            'pago' => $pago,
            'aplicaciones' => $aplicaciones,
        ]
    );
}

function cxp_resumen_pagos(PDO $conexion): array
{
    $r = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'APLICADO') AS aplicados,
            SUM(estado = 'CANCELADO') AS cancelados
         FROM pagos_proveedor"
    )->fetch();

    $monedas = $conexion->query(
        "SELECT
            m.id AS moneda_id,
            m.codigo,
            m.simbolo,
            COUNT(*) AS pagos,
            SUM(pp.importe) AS importe_aplicado
         FROM pagos_proveedor pp
         INNER JOIN monedas m ON m.id = pp.moneda_id
         WHERE pp.estado = 'APLICADO'
         GROUP BY m.id, m.codigo, m.simbolo, m.es_base
         ORDER BY m.es_base DESC, m.codigo ASC"
    )->fetchAll();

    foreach ($monedas as &$m) {
        $m['moneda_id'] = (int) $m['moneda_id'];
        $m['pagos'] = (int) $m['pagos'];
        $m['importe_aplicado'] = (float) $m['importe_aplicado'];
    }
    unset($m);

    return [
        'total' => (int) ($r['total'] ?? 0),
        'aplicados' => (int) ($r['aplicados'] ?? 0),
        'cancelados' => (int) ($r['cancelados'] ?? 0),
        'totales_por_moneda' => $monedas,
    ];
}

function cxp_registrar_abono(PDO $conexion): void
{
    $cuentaId = cxp_id($_POST['cuenta_id'] ?? null, 'cuenta por pagar');
    $importe = cxp_decimal_positivo(
        $_POST['importe'] ?? null,
        'Ingresa un importe de pago válido.'
    );
    $metodoId = cxp_id($_POST['metodo_pago_id'] ?? null, 'método de pago');
    $fechaPago = cxp_fecha_hora($_POST['fecha_pago'] ?? '', 'fecha de pago');
    $referencia = cxp_nullable($_POST['referencia'] ?? '', 120);
    $observaciones = cxp_nullable($_POST['observaciones'] ?? '', 10000);

    if ($fechaPago > date('Y-m-d H:i:s', time() + 300)) {
        si_responder_json(
            false,
            'No puedes registrar como aplicado un pago con fecha futura.',
            ['campo' => 'fecha_pago'],
            422
        );
    }

    $conexion->beginTransaction();

    $cuenta = cxp_recalcular_cuenta($conexion, $cuentaId);

    if ($cuenta['estado'] === 'CANCELADA') {
        cxp_cancelar($conexion, 'La cuenta por pagar está cancelada.', 409);
    }

    $saldo = (float) $cuenta['saldo_pendiente'];

    if ($saldo <= 0.00005) {
        cxp_cancelar($conexion, 'La cuenta ya está completamente pagada.', 409);
    }

    if ($importe - $saldo > 0.00005) {
        cxp_cancelar(
            $conexion,
            'El abono no puede ser mayor al saldo pendiente.',
            422,
            [
                'campo' => 'importe',
                'saldo_pendiente' => $saldo,
            ]
        );
    }

    $stmtMetodo = $conexion->prepare(
        "SELECT id, codigo, nombre, requiere_referencia
         FROM metodos_pago
         WHERE id = :id
           AND activo = 1
         LIMIT 1"
    );
    $stmtMetodo->execute([':id' => $metodoId]);
    $metodo = $stmtMetodo->fetch();

    if (!$metodo) {
        cxp_cancelar($conexion, 'El método de pago ya no está disponible.', 409);
    }

    if ((int) $metodo['requiere_referencia'] === 1 && $referencia === null) {
        cxp_cancelar(
            $conexion,
            'El método de pago seleccionado requiere una referencia o número de operación.',
            422,
            ['campo' => 'referencia']
        );
    }

    $tipoCambio = cxp_tipo_cambio_pago(
        $conexion,
        (int) $cuenta['moneda_id'],
        substr($fechaPago, 0, 10),
        (float) $cuenta['tipo_cambio_compra']
    );

    $folioTemporal = 'TMP-PAGO-' . bin2hex(random_bytes(10));

    $stmtPago = $conexion->prepare(
        "INSERT INTO pagos_proveedor
            (
                folio,
                proveedor_id,
                fecha_pago,
                moneda_id,
                tipo_cambio_a_base,
                metodo_pago_id,
                importe,
                referencia,
                estado,
                observaciones,
                created_by
            )
         VALUES
            (
                :folio,
                :proveedor_id,
                :fecha_pago,
                :moneda_id,
                :tipo_cambio,
                :metodo_pago_id,
                :importe,
                :referencia,
                'APLICADO',
                :observaciones,
                :created_by
            )"
    );

    $stmtPago->execute([
        ':folio' => $folioTemporal,
        ':proveedor_id' => (int) $cuenta['proveedor_id'],
        ':fecha_pago' => $fechaPago,
        ':moneda_id' => (int) $cuenta['moneda_id'],
        ':tipo_cambio' => $tipoCambio,
        ':metodo_pago_id' => $metodoId,
        ':importe' => cxp_round4($importe),
        ':referencia' => $referencia,
        ':observaciones' => $observaciones,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $pagoId = (int) $conexion->lastInsertId();
    $folio = 'PAG-PROV-' . str_pad((string) $pagoId, 7, '0', STR_PAD_LEFT);

    $conexion->prepare(
        "UPDATE pagos_proveedor
         SET folio = :folio
         WHERE id = :id"
    )->execute([
        ':folio' => $folio,
        ':id' => $pagoId,
    ]);

    $conexion->prepare(
        "INSERT INTO aplicaciones_pago_proveedor
            (pago_proveedor_id, cuenta_por_pagar_id, importe_aplicado)
         VALUES
            (:pago_id, :cuenta_id, :importe)"
    )->execute([
        ':pago_id' => $pagoId,
        ':cuenta_id' => $cuentaId,
        ':importe' => cxp_round4($importe),
    ]);

    $cuentaActualizada = cxp_recalcular_cuenta($conexion, $cuentaId);

    cxp_auditar(
        $conexion,
        'PAGO_PROVEEDOR_REGISTRADO',
        'pagos_proveedor',
        $pagoId,
        'Se registró y aplicó un abono a una cuenta por pagar.',
        null,
        [
            'folio_pago' => $folio,
            'cuenta_por_pagar_id' => $cuentaId,
            'cuenta_folio' => $cuenta['folio'],
            'proveedor_id' => (int) $cuenta['proveedor_id'],
            'importe' => cxp_round4($importe),
            'moneda_id' => (int) $cuenta['moneda_id'],
            'metodo_pago' => $metodo['codigo'],
            'referencia' => $referencia,
            'saldo_anterior' => $saldo,
            'saldo_nuevo' => (float) $cuentaActualizada['saldo_pendiente'],
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $cuentaActualizada['estado'] === 'PAGADA'
            ? 'Pago registrado. La cuenta quedó liquidada.'
            : 'Abono registrado correctamente.',
        [
            'pago_id' => $pagoId,
            'folio_pago' => $folio,
            'cuenta_id' => $cuentaId,
            'estado_cuenta' => $cuentaActualizada['estado'],
            'saldo_pendiente' => (float) $cuentaActualizada['saldo_pendiente'],
        ],
        201
    );
}

function cxp_cancelar_pago(PDO $conexion): void
{
    $pagoId = cxp_id($_POST['pago_id'] ?? null, 'pago');
    $motivo = cxp_requerido(
        $_POST['motivo'] ?? '',
        'Indica el motivo de cancelación del pago.',
        10000
    );

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            pp.*,
            m.codigo AS moneda_codigo,
            p.razon_social AS proveedor
         FROM pagos_proveedor pp
         INNER JOIN monedas m ON m.id = pp.moneda_id
         INNER JOIN proveedores p ON p.id = pp.proveedor_id
         WHERE pp.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $pagoId]);
    $pago = $stmt->fetch();

    if (!$pago) {
        cxp_cancelar($conexion, 'El pago ya no existe.', 404);
    }

    if ($pago['estado'] === 'CANCELADO') {
        $conexion->commit();
        si_responder_json(true, 'El pago ya estaba cancelado.');
    }

    $stmtApps = $conexion->prepare(
        "SELECT cuenta_por_pagar_id, importe_aplicado
         FROM aplicaciones_pago_proveedor
         WHERE pago_proveedor_id = :pago_id
         ORDER BY id ASC
         FOR UPDATE"
    );
    $stmtApps->execute([':pago_id' => $pagoId]);
    $apps = $stmtApps->fetchAll();

    if (!$apps) {
        cxp_cancelar(
            $conexion,
            'El pago no tiene aplicaciones financieras relacionadas. Revisa la integridad antes de cancelarlo.',
            409
        );
    }

    $cuentasAntes = [];

    foreach ($apps as $app) {
        $cuentaId = (int) $app['cuenta_por_pagar_id'];
        $cuenta = cxp_recalcular_cuenta($conexion, $cuentaId);
        $cuentasAntes[$cuentaId] = [
            'folio' => $cuenta['folio'],
            'importe_pagado' => (float) $cuenta['importe_pagado'],
            'saldo_pendiente' => (float) $cuenta['saldo_pendiente'],
            'estado' => $cuenta['estado'],
        ];
    }

    $conexion->prepare(
        "UPDATE pagos_proveedor
         SET
            estado = 'CANCELADO',
            motivo_cancelacion = :motivo,
            cancelado_at = NOW(),
            cancelado_by = :usuario_id
         WHERE id = :id"
    )->execute([
        ':motivo' => $motivo,
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':id' => $pagoId,
    ]);

    $cuentasDespues = [];

    foreach ($apps as $app) {
        $cuentaId = (int) $app['cuenta_por_pagar_id'];
        $cuenta = cxp_recalcular_cuenta($conexion, $cuentaId);
        $cuentasDespues[$cuentaId] = [
            'folio' => $cuenta['folio'],
            'importe_pagado' => (float) $cuenta['importe_pagado'],
            'saldo_pendiente' => (float) $cuenta['saldo_pendiente'],
            'estado' => $cuenta['estado'],
        ];
    }

    cxp_auditar(
        $conexion,
        'PAGO_PROVEEDOR_CANCELADO',
        'pagos_proveedor',
        $pagoId,
        'Se canceló un pago a proveedor y se recalcularon las cuentas relacionadas.',
        [
            'folio' => $pago['folio'],
            'estado' => $pago['estado'],
            'importe' => (float) $pago['importe'],
            'cuentas' => $cuentasAntes,
        ],
        [
            'folio' => $pago['folio'],
            'estado' => 'CANCELADO',
            'motivo_cancelacion' => $motivo,
            'cuentas' => $cuentasDespues,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Pago cancelado correctamente. El saldo de la cuenta fue restaurado sin borrar el historial.'
    );
}

/* =========================================================================
   VENCIMIENTOS
   ========================================================================= */

function cxp_vencimientos(PDO $conexion): void
{
    $pagina = cxp_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxp_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cxp_texto($_GET['busqueda'] ?? '', 180);
    $horizonte = strtoupper(cxp_texto($_GET['horizonte'] ?? '30_DIAS', 20));
    $monedaId = cxp_entero_rango($_GET['moneda_id'] ?? 0, 0, PHP_INT_MAX, 0);

    $validos = ['VENCIDAS', 'HOY', '7_DIAS', '15_DIAS', '30_DIAS', '60_DIAS', 'TODAS'];
    if (!in_array($horizonte, $validos, true)) {
        $horizonte = '30_DIAS';
    }

    $where = [
        "c.estado <> 'CANCELADA'",
        'c.saldo_pendiente > 0.00005',
    ];
    $params = [];

    if ($q !== '') {
        $where[] = "(
            c.folio LIKE :q_cxp
            OR co.folio LIKE :q_compra
            OR co.numero_factura LIKE :q_factura
            OR p.codigo LIKE :q_proveedor_codigo
            OR p.razon_social LIKE :q_proveedor_nombre
        )";
        $like = '%' . $q . '%';
        $params[':q_cxp'] = $like;
        $params[':q_compra'] = $like;
        $params[':q_factura'] = $like;
        $params[':q_proveedor_codigo'] = $like;
        $params[':q_proveedor_nombre'] = $like;
    }

    if ($monedaId > 0) {
        $where[] = 'c.moneda_id = :moneda_id';
        $params[':moneda_id'] = $monedaId;
    }

    switch ($horizonte) {
        case 'VENCIDAS':
            $where[] = 'c.fecha_vencimiento < CURDATE()';
            break;
        case 'HOY':
            $where[] = 'c.fecha_vencimiento = CURDATE()';
            break;
        case '7_DIAS':
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            break;
        case '15_DIAS':
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)';
            break;
        case '30_DIAS':
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
            break;
        case '60_DIAS':
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
            break;
        case 'TODAS':
            break;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $from = "FROM cuentas_por_pagar c
             INNER JOIN compras co ON co.id = c.compra_id
             INNER JOIN proveedores p ON p.id = c.proveedor_id
             INNER JOIN monedas m ON m.id = c.moneda_id";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    cxp_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $estadoCase = cxp_estado_case('c');

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            co.folio AS compra_folio,
            co.numero_factura,
            p.codigo AS proveedor_codigo,
            p.razon_social AS proveedor,
            m.id AS moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            c.importe_original,
            c.importe_pagado,
            c.saldo_pendiente,
            c.fecha_documento,
            c.fecha_vencimiento,
            DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento,
            {$estadoCase} AS estado_calculado
         {$from}
         {$whereSql}
         ORDER BY c.fecha_vencimiento ASC, c.id ASC
         LIMIT :limite OFFSET :offset"
    );
    cxp_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $cuentas = $stmt->fetchAll();
    foreach ($cuentas as &$c) {
        cxp_tipar_cuenta($c);
    }
    unset($c);

    $stmtTotales = $conexion->prepare(
        "SELECT
            m.id AS moneda_id,
            m.codigo,
            m.simbolo,
            COUNT(*) AS cuentas,
            SUM(c.saldo_pendiente) AS saldo
         {$from}
         {$whereSql}
         GROUP BY m.id, m.codigo, m.simbolo, m.es_base
         ORDER BY m.es_base DESC, m.codigo ASC"
    );
    cxp_bind($stmtTotales, $params);
    $stmtTotales->execute();
    $totales = $stmtTotales->fetchAll();

    foreach ($totales as &$t) {
        $t['moneda_id'] = (int) $t['moneda_id'];
        $t['cuentas'] = (int) $t['cuentas'];
        $t['saldo'] = (float) $t['saldo'];
    }
    unset($t);

    si_responder_json(
        true,
        'Vencimientos cargados.',
        [
            'cuentas' => $cuentas,
            'totales_por_moneda' => $totales,
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
   INTEGRIDAD FINANCIERA
   ========================================================================= */

function cxp_recalcular_cuenta(PDO $conexion, int $cuentaId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            co.tipo_cambio_a_base AS tipo_cambio_compra
         FROM cuentas_por_pagar c
         INNER JOIN compras co ON co.id = c.compra_id
         WHERE c.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $cuentaId]);
    $cuenta = $stmt->fetch();

    if (!$cuenta) {
        cxp_cancelar($conexion, 'La cuenta por pagar ya no existe.', 404);
    }

    if ($cuenta['estado'] === 'CANCELADA') {
        $cuenta['importe_original'] = (float) $cuenta['importe_original'];
        $cuenta['importe_pagado'] = (float) $cuenta['importe_pagado'];
        $cuenta['saldo_pendiente'] = (float) $cuenta['saldo_pendiente'];
        $cuenta['moneda_id'] = (int) $cuenta['moneda_id'];
        $cuenta['proveedor_id'] = (int) $cuenta['proveedor_id'];
        $cuenta['tipo_cambio_compra'] = (float) $cuenta['tipo_cambio_compra'];
        return $cuenta;
    }

    $stmtPagado = $conexion->prepare(
        "SELECT COALESCE(SUM(app.importe_aplicado), 0)
         FROM aplicaciones_pago_proveedor app
         INNER JOIN pagos_proveedor pp ON pp.id = app.pago_proveedor_id
         WHERE app.cuenta_por_pagar_id = :cuenta_id
           AND pp.estado = 'APLICADO'"
    );
    $stmtPagado->execute([':cuenta_id' => $cuentaId]);
    $pagado = cxp_round4((float) $stmtPagado->fetchColumn());
    $original = cxp_round4((float) $cuenta['importe_original']);

    if ($pagado - $original > 0.00005) {
        cxp_cancelar(
            $conexion,
            'Los pagos aplicados exceden el importe original de la cuenta. Se requiere revisar la integridad financiera.',
            409
        );
    }

    $saldo = cxp_round4(max(0.0, $original - $pagado));

    if ($saldo <= 0.00005) {
        $estado = 'PAGADA';
    } elseif ((string) $cuenta['fecha_vencimiento'] < date('Y-m-d')) {
        $estado = 'VENCIDA';
    } elseif ($pagado > 0.00005) {
        $estado = 'PARCIAL';
    } else {
        $estado = 'PENDIENTE';
    }

    $conexion->prepare(
        "UPDATE cuentas_por_pagar
         SET
            importe_pagado = :importe_pagado,
            estado = :estado
         WHERE id = :id"
    )->execute([
        ':importe_pagado' => $pagado,
        ':estado' => $estado,
        ':id' => $cuentaId,
    ]);

    $cuenta['importe_original'] = $original;
    $cuenta['importe_pagado'] = $pagado;
    $cuenta['saldo_pendiente'] = $saldo;
    $cuenta['estado'] = $estado;
    $cuenta['moneda_id'] = (int) $cuenta['moneda_id'];
    $cuenta['proveedor_id'] = (int) $cuenta['proveedor_id'];
    $cuenta['tipo_cambio_compra'] = (float) $cuenta['tipo_cambio_compra'];

    return $cuenta;
}

function cxp_tipo_cambio_pago(
    PDO $conexion,
    int $monedaId,
    string $fecha,
    float $fallbackCompra
): float {
    $tipo = si_tc_resolver_a_base($conexion, $monedaId, $fecha, true);
    if ($tipo !== null && (float) $tipo['tipo_cambio'] > 0) {
        return (float) $tipo['tipo_cambio'];
    }

    if ($fallbackCompra > 0) {
        return $fallbackCompra;
    }

    cxp_cancelar(
        $conexion,
        'No existe un tipo de cambio disponible para registrar este pago.',
        409
    );
}

/* =========================================================================
   CONSULTAS / FILTROS / HELPERS
   ========================================================================= */

function cxp_estado_case(string $alias): string
{
    return "CASE
        WHEN {$alias}.estado = 'CANCELADA' THEN 'CANCELADA'
        WHEN {$alias}.saldo_pendiente <= 0.00005 THEN 'PAGADA'
        WHEN {$alias}.fecha_vencimiento < CURDATE() THEN 'VENCIDA'
        WHEN {$alias}.importe_pagado > 0.00005 THEN 'PARCIAL'
        ELSE 'PENDIENTE'
    END";
}

function cxp_agregar_filtro_estado(array &$where, string $estado): void
{
    switch ($estado) {
        case 'PENDIENTE':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento >= CURDATE()';
            $where[] = 'c.importe_pagado <= 0.00005';
            break;

        case 'PARCIAL':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento >= CURDATE()';
            $where[] = 'c.importe_pagado > 0.00005';
            break;

        case 'PAGADA':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente <= 0.00005';
            break;

        case 'VENCIDA':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento < CURDATE()';
            break;

        case 'CANCELADA':
            $where[] = "c.estado = 'CANCELADA'";
            break;

        case 'TODOS':
            break;
    }
}

function cxp_agregar_filtro_vencimiento(array &$where, string $vencimiento): void
{
    switch ($vencimiento) {
        case 'VENCIDAS':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento < CURDATE()';
            break;
        case 'HOY':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento = CURDATE()';
            break;
        case '7_DIAS':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
            break;
        case '15_DIAS':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)';
            break;
        case '30_DIAS':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
            break;
        case '60_DIAS':
            $where[] = "c.estado <> 'CANCELADA'";
            $where[] = 'c.saldo_pendiente > 0.00005';
            $where[] = 'c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
            break;
        case 'TODOS':
            break;
    }
}

function cxp_tipar_cuenta(array &$c): void
{
    foreach (['id', 'compra_id', 'proveedor_id', 'moneda_id', 'abonos_aplicados'] as $campo) {
        if (isset($c[$campo])) {
            $c[$campo] = (int) $c[$campo];
        }
    }

    foreach (['importe_original', 'importe_pagado', 'saldo_pendiente'] as $campo) {
        if (isset($c[$campo])) {
            $c[$campo] = (float) $c[$campo];
        }
    }

    if (isset($c['dias_vencimiento'])) {
        $c['dias_vencimiento'] = (int) $c['dias_vencimiento'];
    }
}

function cxp_requerido($valor, string $mensaje, int $maximo): string
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

function cxp_nullable($valor, int $maximo): ?string
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

function cxp_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    if (mb_strlen($texto) > $maximo) {
        $texto = mb_substr($texto, 0, $maximo);
    }
    return $texto;
}

function cxp_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Identificador de ' . $entidad . ' inválido.', [], 422);
    }
    return (int) $id;
}

function cxp_entero_rango($valor, int $minimo, int $maximo, int $default): int
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

function cxp_decimal_positivo($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $n = (float) $valor;
    if (!is_finite($n) || $n <= 0 || $n > 999999999999.0) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return cxp_round4($n);
}

function cxp_fecha_opcional($valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
    $errores = DateTimeImmutable::getLastErrors();

    if (
        !$dt
        || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))
        || $dt->format('Y-m-d') !== $texto
    ) {
        si_responder_json(false, 'La fecha indicada no es válida.', [], 422);
    }

    return $texto;
}

function cxp_fecha_hora($valor, string $campo): string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return date('Y-m-d H:i:s');
    }

    $formatos = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'];

    foreach ($formatos as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $texto);
        $errores = DateTimeImmutable::getLastErrors();

        if (
            $dt
            && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))
        ) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    si_responder_json(false, 'La ' . $campo . ' no es válida.', ['campo' => $campo], 422);
}

function cxp_round4(float $n): float
{
    return round($n, 4, PHP_ROUND_HALF_UP);
}

function cxp_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}

function cxp_auditar(
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
                'cuentas_por_pagar',
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
        ':datos_anteriores' => $anterior === null
            ? null
            : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':datos_nuevos' => $nuevo === null
            ? null
            : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

function cxp_cancelar(PDO $conexion, string $mensaje, int $codigo, array $extra = []): never
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    si_responder_json(false, $mensaje, $extra, $codigo);
}
