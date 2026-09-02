<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

/** @var PDO|null $conexion Conexión creada por inc/conexion.php. */
require_once __DIR__ . '/../inc/tipo_cambio_banxico.php';

si_requerir_permiso('cuentas_cobrar.ver', true);

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
                cxc_catalogos($conexion);
                break;

            case 'LISTAR_CUENTAS':
                cxc_listar_cuentas($conexion);
                break;

            case 'DETALLE_CUENTA':
                cxc_detalle_cuenta($conexion);
                break;

            case 'HISTORIAL_CUENTA':
                cxc_historial_cuenta($conexion);
                break;

            case 'LISTAR_PAGOS':
                cxc_listar_pagos($conexion);
                break;

            case 'DETALLE_PAGO':
                cxc_detalle_pago($conexion);
                break;

            case 'VENCIMIENTOS':
                cxc_vencimientos($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('cuentas_cobrar.cobrar')) {
        si_responder_json(
            false,
            'No tienes permiso para registrar o cancelar cobros de clientes.',
            [],
            403
        );
    }

    switch ($accion) {
        case 'REGISTRAR_ABONO':
            cxc_registrar_abono($conexion);
            break;

        case 'CANCELAR_PAGO':
            cxc_cancelar_pago($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CXC-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CUENTAS_COBRAR][PDO] '
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

    $referencia = 'CXC-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CUENTAS_COBRAR] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar cuentas por cobrar.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS
   ========================================================================= */

function cxc_catalogos(PDO $conexion): void
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
   CUENTAS POR COBRAR
   ========================================================================= */

function cxc_listar_cuentas(PDO $conexion): void
{
    $pagina = cxc_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxc_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cxc_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cxc_texto($_GET['estado'] ?? 'TODOS', 20));
    $vencimiento = strtoupper(cxc_texto($_GET['vencimiento'] ?? 'TODOS', 20));
    $monedaId = cxc_entero_rango($_GET['moneda_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($estado, ['TODOS','PENDIENTE','PARCIAL','PAGADA','VENCIDA','CANCELADA'], true)) {
        $estado = 'TODOS';
    }
    if (!in_array($vencimiento, ['TODOS','VENCIDAS','HOY','7_DIAS','15_DIAS','30_DIAS','60_DIAS'], true)) {
        $vencimiento = 'TODOS';
    }

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = "(c.folio LIKE :q_cxc OR v.folio LIKE :q_venta OR cl.codigo LIKE :q_codigo OR cl.nombre_razon_social LIKE :q_cliente OR cl.rfc LIKE :q_rfc OR v.cliente_nombre_snapshot LIKE :q_snapshot)";
        $like = '%' . $q . '%';
        foreach ([':q_cxc',':q_venta',':q_codigo',':q_cliente',':q_rfc',':q_snapshot'] as $k) $params[$k] = $like;
    }
    if ($monedaId > 0) {
        $where[] = 'c.moneda_id = :moneda_id';
        $params[':moneda_id'] = $monedaId;
    }
    cxc_agregar_filtro_estado($where, $estado);
    cxc_agregar_filtro_vencimiento($where, $vencimiento);
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $from = "FROM cuentas_por_cobrar c
             INNER JOIN ventas v ON v.id = c.venta_id
             INNER JOIN clientes cl ON cl.id = c.cliente_id
             INNER JOIN monedas m ON m.id = c.moneda_id";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    cxc_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;
    $estadoCase = cxc_estado_case('c');

    $stmt = $conexion->prepare(
        "SELECT c.id, c.folio, c.venta_id, v.folio AS venta_folio, v.fecha_venta,
                v.condicion_pago, v.dias_credito,
                cl.id AS cliente_id, cl.codigo AS cliente_codigo,
                cl.nombre_razon_social AS cliente, cl.rfc AS cliente_rfc,
                m.id AS moneda_id, m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                c.importe_original, c.importe_pagado, c.saldo_pendiente,
                c.fecha_documento, c.fecha_vencimiento,
                DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento,
                {$estadoCase} AS estado_calculado,
                (SELECT COUNT(*) FROM aplicaciones_pago_cliente app
                 INNER JOIN pagos_cliente pc ON pc.id = app.pago_cliente_id
                 WHERE app.cuenta_por_cobrar_id = c.id AND pc.estado = 'APLICADO') AS abonos_aplicados,
                c.created_at
         {$from} {$whereSql}
         ORDER BY CASE
                    WHEN c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 AND c.fecha_vencimiento < CURDATE() THEN 0
                    WHEN c.estado <> 'CANCELADA' AND c.saldo_pendiente > 0.00005 THEN 1
                    WHEN c.saldo_pendiente <= 0.00005 THEN 2 ELSE 3 END,
                  c.fecha_vencimiento ASC, c.id DESC
         LIMIT :limite OFFSET :offset"
    );
    cxc_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $cuentas = $stmt->fetchAll();
    foreach ($cuentas as &$c) cxc_tipar_cuenta($c);
    unset($c);

    si_responder_json(true, 'Cuentas por cobrar cargadas.', [
        'cuentas' => $cuentas,
        'paginacion' => ['pagina'=>$pagina,'por_pagina'=>$porPagina,'total_registros'=>$total,'total_paginas'=>$totalPaginas],
        'resumen' => cxc_resumen_cuentas($conexion),
    ]);
}

function cxc_detalle_cuenta(PDO $conexion): void
{
    $id = cxc_id($_GET['id'] ?? null, 'cuenta por cobrar');
    $estadoCase = cxc_estado_case('c');
    $stmt = $conexion->prepare(
        "SELECT c.id, c.folio, c.venta_id, v.folio AS venta_folio, v.fecha_venta,
                v.condicion_pago, v.dias_credito, v.tipo_cambio_a_base AS tipo_cambio_venta,
                v.total AS venta_total, v.apartado_id, v.cotizacion_id,
                cl.id AS cliente_id, cl.codigo AS cliente_codigo, cl.nombre_razon_social AS cliente,
                cl.rfc AS cliente_rfc, cl.telefono AS cliente_telefono, cl.correo AS cliente_correo,
                cl.limite_credito, cl.dias_credito AS cliente_dias_credito,
                m.id AS moneda_id, m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                c.importe_original, c.importe_pagado, c.saldo_pendiente,
                c.fecha_documento, c.fecha_vencimiento,
                DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_vencimiento,
                {$estadoCase} AS estado_calculado,
                c.observaciones, c.created_at, c.updated_at
         FROM cuentas_por_cobrar c
         INNER JOIN ventas v ON v.id = c.venta_id
         INNER JOIN clientes cl ON cl.id = c.cliente_id
         INNER JOIN monedas m ON m.id = c.moneda_id
         WHERE c.id = :id LIMIT 1"
    );
    $stmt->execute([':id'=>$id]);
    $cuenta = $stmt->fetch();
    if (!$cuenta) si_responder_json(false, 'No se encontró la cuenta por cobrar.', [], 404);
    cxc_tipar_cuenta($cuenta);
    foreach (['venta_total','limite_credito','tipo_cambio_venta'] as $campo) {
        if (isset($cuenta[$campo]) && $cuenta[$campo] !== null) $cuenta[$campo] = (float) $cuenta[$campo];
    }
    foreach (['apartado_id','cotizacion_id','cliente_dias_credito','dias_credito'] as $campo) {
        if (isset($cuenta[$campo]) && $cuenta[$campo] !== null) $cuenta[$campo] = (int) $cuenta[$campo];
    }
    $cuenta['puede_abonar'] = !in_array($cuenta['estado_calculado'], ['CANCELADA','PAGADA'], true) && $cuenta['saldo_pendiente'] > 0.00005;
    si_responder_json(true, 'Cuenta encontrada.', ['cuenta'=>$cuenta]);
}

function cxc_historial_cuenta(PDO $conexion): void
{
    $cuentaId = cxc_id($_GET['cuenta_id'] ?? null, 'cuenta por cobrar');
    $pagina = cxc_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cxc_entero_rango($_GET['por_pagina'] ?? 10, 5, 100, 10);
    $stmtCuenta = $conexion->prepare("SELECT id FROM cuentas_por_cobrar WHERE id = :id LIMIT 1");
    $stmtCuenta->execute([':id'=>$cuentaId]);
    if (!$stmtCuenta->fetchColumn()) si_responder_json(false, 'La cuenta por cobrar ya no existe.', [], 404);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM aplicaciones_pago_cliente app INNER JOIN pagos_cliente pc ON pc.id = app.pago_cliente_id WHERE app.cuenta_por_cobrar_id = :id");
    $stmtTotal->execute([':id'=>$cuentaId]);
    $total = (int)$stmtTotal->fetchColumn();
    $totalPaginas=max(1,(int)ceil($total/$porPagina)); $pagina=min($pagina,$totalPaginas); $offset=($pagina-1)*$porPagina;
    $stmt=$conexion->prepare(
        "SELECT pc.id, pc.folio, pc.fecha_pago, pc.importe AS importe_pago, app.importe_aplicado,
                pc.referencia, pc.estado, pc.motivo_cancelacion,
                mp.codigo AS metodo_codigo, mp.nombre AS metodo_nombre,
                m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
                u.usuario, CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) AS usuario_nombre
         FROM aplicaciones_pago_cliente app
         INNER JOIN pagos_cliente pc ON pc.id=app.pago_cliente_id
         INNER JOIN metodos_pago mp ON mp.id=pc.metodo_pago_id
         INNER JOIN monedas m ON m.id=pc.moneda_id
         LEFT JOIN usuarios u ON u.id=pc.created_by
         WHERE app.cuenta_por_cobrar_id=:id
         ORDER BY pc.fecha_pago DESC, pc.id DESC LIMIT :limite OFFSET :offset"
    );
    $stmt->bindValue(':id',$cuentaId,PDO::PARAM_INT); $stmt->bindValue(':limite',$porPagina,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute();
    $pagos=$stmt->fetchAll();
    foreach($pagos as &$p){$p['id']=(int)$p['id'];$p['importe_pago']=(float)$p['importe_pago'];$p['importe_aplicado']=(float)$p['importe_aplicado'];} unset($p);
    si_responder_json(true,'Historial cargado.',['pagos'=>$pagos,'paginacion'=>['pagina'=>$pagina,'por_pagina'=>$porPagina,'total_registros'=>$total,'total_paginas'=>$totalPaginas]]);
}

function cxc_resumen_cuentas(PDO $conexion): array
{
    $r=$conexion->query("SELECT COUNT(*) total,
        SUM(c.estado='CANCELADA') canceladas,
        SUM(c.estado<>'CANCELADA' AND c.saldo_pendiente<=0.00005) pagadas,
        SUM(c.estado<>'CANCELADA' AND c.saldo_pendiente>0.00005 AND c.fecha_vencimiento<CURDATE()) vencidas,
        SUM(c.estado<>'CANCELADA' AND c.saldo_pendiente>0.00005 AND c.fecha_vencimiento>=CURDATE() AND c.importe_pagado>0.00005) parciales,
        SUM(c.estado<>'CANCELADA' AND c.saldo_pendiente>0.00005 AND c.fecha_vencimiento>=CURDATE() AND c.importe_pagado<=0.00005) pendientes,
        SUM(c.estado<>'CANCELADA' AND c.saldo_pendiente>0.00005 AND c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) proximas_7
        FROM cuentas_por_cobrar c")->fetch();
    $monedas=$conexion->query("SELECT m.id moneda_id,m.codigo,m.simbolo,COUNT(*) cuentas_abiertas,
        SUM(c.saldo_pendiente) saldo_pendiente,
        SUM(CASE WHEN c.fecha_vencimiento<CURDATE() THEN c.saldo_pendiente ELSE 0 END) saldo_vencido
        FROM cuentas_por_cobrar c INNER JOIN monedas m ON m.id=c.moneda_id
        WHERE c.estado<>'CANCELADA' AND c.saldo_pendiente>0.00005
        GROUP BY m.id,m.codigo,m.simbolo,m.es_base ORDER BY m.es_base DESC,m.codigo ASC")->fetchAll();
    foreach($monedas as &$m){$m['moneda_id']=(int)$m['moneda_id'];$m['cuentas_abiertas']=(int)$m['cuentas_abiertas'];$m['saldo_pendiente']=(float)$m['saldo_pendiente'];$m['saldo_vencido']=(float)$m['saldo_vencido'];} unset($m);
    return ['total'=>(int)($r['total']??0),'pendientes'=>(int)($r['pendientes']??0),'parciales'=>(int)($r['parciales']??0),'pagadas'=>(int)($r['pagadas']??0),'vencidas'=>(int)($r['vencidas']??0),'canceladas'=>(int)($r['canceladas']??0),'proximas_7'=>(int)($r['proximas_7']??0),'saldos_por_moneda'=>$monedas];
}

function cxc_listar_pagos(PDO $conexion): void
{
    $pagina=cxc_entero_rango($_GET['pagina']??1,1,PHP_INT_MAX,1);$porPagina=cxc_entero_rango($_GET['por_pagina']??20,10,100,20);
    $q=cxc_texto($_GET['busqueda']??'',180);$estado=strtoupper(cxc_texto($_GET['estado']??'TODOS',20));$metodoId=cxc_entero_rango($_GET['metodo_id']??0,0,PHP_INT_MAX,0);
    $desde=cxc_fecha_opcional($_GET['desde']??'');$hasta=cxc_fecha_opcional($_GET['hasta']??'');
    if(!in_array($estado,['TODOS','APLICADO','CANCELADO'],true))$estado='TODOS';
    if($desde!==null&&$hasta!==null&&$desde>$hasta)si_responder_json(false,'La fecha inicial no puede ser posterior a la fecha final.',[],422);
    $where=[];$params=[];
    if($q!==''){
        $where[]="(pc.folio LIKE :q_pago OR pc.referencia LIKE :q_ref OR cl.codigo LIKE :q_codigo OR cl.nombre_razon_social LIKE :q_cliente OR EXISTS(
            SELECT 1 FROM aplicaciones_pago_cliente aq INNER JOIN cuentas_por_cobrar cq ON cq.id=aq.cuenta_por_cobrar_id INNER JOIN ventas vq ON vq.id=cq.venta_id
            WHERE aq.pago_cliente_id=pc.id AND (cq.folio LIKE :q_cxc OR vq.folio LIKE :q_venta)))";
        $like='%'.$q.'%'; foreach([':q_pago',':q_ref',':q_codigo',':q_cliente',':q_cxc',':q_venta'] as $k)$params[$k]=$like;
    }
    if($estado!=='TODOS'){$where[]='pc.estado=:estado';$params[':estado']=$estado;}
    if($metodoId>0){$where[]='pc.metodo_pago_id=:metodo';$params[':metodo']=$metodoId;}
    if($desde!==null){$where[]='pc.fecha_pago>=:desde';$params[':desde']=$desde.' 00:00:00';}
    if($hasta!==null){$where[]='pc.fecha_pago<:hasta';$params[':hasta']=(new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $from="FROM pagos_cliente pc INNER JOIN clientes cl ON cl.id=pc.cliente_id INNER JOIN monedas m ON m.id=pc.moneda_id INNER JOIN metodos_pago mp ON mp.id=pc.metodo_pago_id LEFT JOIN usuarios u ON u.id=pc.created_by";
    $st=$conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");cxc_bind($st,$params);$st->execute();$total=(int)$st->fetchColumn();$totalPaginas=max(1,(int)ceil($total/$porPagina));$pagina=min($pagina,$totalPaginas);$offset=($pagina-1)*$porPagina;
    $st=$conexion->prepare("SELECT pc.id,pc.folio,pc.fecha_pago,cl.id cliente_id,cl.codigo cliente_codigo,cl.nombre_razon_social cliente,
        m.id moneda_id,m.codigo moneda_codigo,m.simbolo moneda_simbolo,mp.id metodo_pago_id,mp.codigo metodo_codigo,mp.nombre metodo_nombre,
        pc.importe,pc.tipo_cambio_a_base,pc.referencia,pc.estado,pc.observaciones,pc.motivo_cancelacion,pc.cancelado_at,
        u.usuario,CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) usuario_nombre,
        (SELECT GROUP_CONCAT(c.folio ORDER BY c.id SEPARATOR ', ') FROM aplicaciones_pago_cliente a INNER JOIN cuentas_por_cobrar c ON c.id=a.cuenta_por_cobrar_id WHERE a.pago_cliente_id=pc.id) cuentas_folios,
        (SELECT GROUP_CONCAT(v.folio ORDER BY v.id SEPARATOR ', ') FROM aplicaciones_pago_cliente a2 INNER JOIN cuentas_por_cobrar c2 ON c2.id=a2.cuenta_por_cobrar_id INNER JOIN ventas v ON v.id=c2.venta_id WHERE a2.pago_cliente_id=pc.id) ventas_folios,
        (SELECT COUNT(*) FROM aplicaciones_pago_cliente a3 WHERE a3.pago_cliente_id=pc.id) aplicaciones
        {$from} {$whereSql} ORDER BY pc.fecha_pago DESC,pc.id DESC LIMIT :limite OFFSET :offset");
    cxc_bind($st,$params);$st->bindValue(':limite',$porPagina,PDO::PARAM_INT);$st->bindValue(':offset',$offset,PDO::PARAM_INT);$st->execute();$pagos=$st->fetchAll();
    foreach($pagos as &$p){foreach(['id','cliente_id','moneda_id','metodo_pago_id','aplicaciones'] as $f)$p[$f]=(int)$p[$f];foreach(['importe','tipo_cambio_a_base'] as $f)$p[$f]=(float)$p[$f];}unset($p);
    si_responder_json(true,'Cobros cargados.',['pagos'=>$pagos,'paginacion'=>['pagina'=>$pagina,'por_pagina'=>$porPagina,'total_registros'=>$total,'total_paginas'=>$totalPaginas],'resumen'=>cxc_resumen_pagos($conexion)]);
}

function cxc_detalle_pago(PDO $conexion): void
{
    $id=cxc_id($_GET['id']??null,'cobro');
    $st=$conexion->prepare("SELECT pc.id,pc.folio,pc.fecha_pago,pc.importe,pc.tipo_cambio_a_base,pc.referencia,pc.estado,pc.observaciones,pc.motivo_cancelacion,pc.cancelado_at,
        cl.id cliente_id,cl.codigo cliente_codigo,cl.nombre_razon_social cliente,m.codigo moneda_codigo,m.simbolo moneda_simbolo,
        mp.codigo metodo_codigo,mp.nombre metodo_nombre,u.usuario,CONCAT_WS(' ',u.nombres,u.apellido_paterno,u.apellido_materno) usuario_nombre,uc.usuario usuario_cancelo
        FROM pagos_cliente pc INNER JOIN clientes cl ON cl.id=pc.cliente_id INNER JOIN monedas m ON m.id=pc.moneda_id INNER JOIN metodos_pago mp ON mp.id=pc.metodo_pago_id
        LEFT JOIN usuarios u ON u.id=pc.created_by LEFT JOIN usuarios uc ON uc.id=pc.cancelado_by WHERE pc.id=:id LIMIT 1");
    $st->execute([':id'=>$id]);$pago=$st->fetch();if(!$pago)si_responder_json(false,'No se encontró el cobro.',[],404);
    $pago['id']=(int)$pago['id'];$pago['cliente_id']=(int)$pago['cliente_id'];$pago['importe']=(float)$pago['importe'];$pago['tipo_cambio_a_base']=(float)$pago['tipo_cambio_a_base'];
    $st=$conexion->prepare("SELECT app.id,app.importe_aplicado,c.id cuenta_id,c.folio cuenta_folio,v.folio venta_folio,v.fecha_venta,c.importe_original,c.fecha_vencimiento
        FROM aplicaciones_pago_cliente app INNER JOIN cuentas_por_cobrar c ON c.id=app.cuenta_por_cobrar_id INNER JOIN ventas v ON v.id=c.venta_id
        WHERE app.pago_cliente_id=:id ORDER BY app.id ASC");$st->execute([':id'=>$id]);$apps=$st->fetchAll();
    foreach($apps as &$a){$a['id']=(int)$a['id'];$a['cuenta_id']=(int)$a['cuenta_id'];$a['importe_aplicado']=(float)$a['importe_aplicado'];$a['importe_original']=(float)$a['importe_original'];}unset($a);
    si_responder_json(true,'Cobro encontrado.',['pago'=>$pago,'aplicaciones'=>$apps]);
}

function cxc_resumen_pagos(PDO $conexion): array
{
    $r=$conexion->query("SELECT COUNT(*) total,SUM(estado='APLICADO') aplicados,SUM(estado='CANCELADO') cancelados FROM pagos_cliente")->fetch();
    $monedas=$conexion->query("SELECT m.id moneda_id,m.codigo,m.simbolo,COUNT(*) pagos,SUM(pc.importe) importe_aplicado FROM pagos_cliente pc INNER JOIN monedas m ON m.id=pc.moneda_id WHERE pc.estado='APLICADO' GROUP BY m.id,m.codigo,m.simbolo,m.es_base ORDER BY m.es_base DESC,m.codigo ASC")->fetchAll();
    foreach($monedas as &$m){$m['moneda_id']=(int)$m['moneda_id'];$m['pagos']=(int)$m['pagos'];$m['importe_aplicado']=(float)$m['importe_aplicado'];}unset($m);
    return ['total'=>(int)($r['total']??0),'aplicados'=>(int)($r['aplicados']??0),'cancelados'=>(int)($r['cancelados']??0),'totales_por_moneda'=>$monedas];
}

function cxc_registrar_abono(PDO $conexion): void
{
    $cuentaId=cxc_id($_POST['cuenta_id']??null,'cuenta por cobrar');
    $importe=cxc_decimal_positivo($_POST['importe']??null,'Ingresa un importe de abono válido.');
    $metodoId=cxc_id($_POST['metodo_pago_id']??null,'método de cobro');
    $fechaPago=cxc_fecha_hora($_POST['fecha_pago']??'','fecha de cobro');
    $referencia=cxc_nullable($_POST['referencia']??'',120);$observaciones=cxc_nullable($_POST['observaciones']??'',10000);
    if($fechaPago>date('Y-m-d H:i:s',time()+300))si_responder_json(false,'No puedes registrar como aplicado un cobro con fecha futura.',['campo'=>'fecha_pago'],422);
    $conexion->beginTransaction();
    $cuenta=cxc_recalcular_cuenta($conexion,$cuentaId);
    if($cuenta['estado']==='CANCELADA')cxc_cancelar($conexion,'La cuenta por cobrar está cancelada.',409);
    if (substr($fechaPago, 0, 10) < (string) $cuenta['fecha_documento']) {
        cxc_cancelar($conexion, 'La fecha del cobro no puede ser anterior a la fecha de la cuenta por cobrar.', 422, ['campo' => 'fecha_pago']);
    }
    $saldo=(float)$cuenta['saldo_pendiente']; if($saldo<=0.00005)cxc_cancelar($conexion,'La cuenta ya está completamente liquidada.',409);
    if($importe-$saldo>0.00005)cxc_cancelar($conexion,'El abono no puede ser mayor al saldo pendiente.',422,['campo'=>'importe','saldo_pendiente'=>$saldo]);
    $st=$conexion->prepare("SELECT id,codigo,nombre,requiere_referencia FROM metodos_pago WHERE id=:id AND activo=1 LIMIT 1");$st->execute([':id'=>$metodoId]);$metodo=$st->fetch();
    if(!$metodo)cxc_cancelar($conexion,'El método seleccionado ya no está disponible.',409);
    if((int)$metodo['requiere_referencia']===1&&$referencia===null)cxc_cancelar($conexion,'El método seleccionado requiere una referencia o número de operación.',422,['campo'=>'referencia']);
    $tipoCambio=cxc_tipo_cambio_pago($conexion,(int)$cuenta['moneda_id'],substr($fechaPago,0,10),(float)$cuenta['tipo_cambio_venta']);
    $tmp='TMP-PAG-CLI-'.bin2hex(random_bytes(10));
    $st=$conexion->prepare("INSERT INTO pagos_cliente(folio,cliente_id,fecha_pago,moneda_id,tipo_cambio_a_base,metodo_pago_id,importe,referencia,estado,observaciones,created_by)
        VALUES(:folio,:cliente,:fecha,:moneda,:tc,:metodo,:importe,:referencia,'APLICADO',:obs,:usuario)");
    $st->execute([':folio'=>$tmp,':cliente'=>(int)$cuenta['cliente_id'],':fecha'=>$fechaPago,':moneda'=>(int)$cuenta['moneda_id'],':tc'=>$tipoCambio,':metodo'=>$metodoId,':importe'=>cxc_round4($importe),':referencia'=>$referencia,':obs'=>$observaciones,':usuario'=>(int)$_SESSION['usuario_id']]);
    $pagoId=(int)$conexion->lastInsertId();$folio='PAG-CLI-'.str_pad((string)$pagoId,7,'0',STR_PAD_LEFT);
    $conexion->prepare("UPDATE pagos_cliente SET folio=:folio WHERE id=:id")->execute([':folio'=>$folio,':id'=>$pagoId]);
    $conexion->prepare("INSERT INTO aplicaciones_pago_cliente(pago_cliente_id,cuenta_por_cobrar_id,importe_aplicado) VALUES(:pago,:cuenta,:importe)")->execute([':pago'=>$pagoId,':cuenta'=>$cuentaId,':importe'=>cxc_round4($importe)]);
    $actual=cxc_recalcular_cuenta($conexion,$cuentaId);
    cxc_auditar($conexion,'COBRO_CLIENTE_REGISTRADO','pagos_cliente',$pagoId,'Se registró y aplicó un abono a una cuenta por cobrar.',null,[
        'folio_pago'=>$folio,'cuenta_por_cobrar_id'=>$cuentaId,'cuenta_folio'=>$cuenta['folio'],'cliente_id'=>(int)$cuenta['cliente_id'],'importe'=>cxc_round4($importe),'moneda_id'=>(int)$cuenta['moneda_id'],'metodo_pago'=>$metodo['codigo'],'referencia'=>$referencia,'saldo_anterior'=>$saldo,'saldo_nuevo'=>(float)$actual['saldo_pendiente']]);
    $conexion->commit();
    si_responder_json(true,$actual['estado']==='PAGADA'?'Cobro registrado. La cuenta quedó liquidada.':'Abono registrado correctamente.',['pago_id'=>$pagoId,'folio_pago'=>$folio,'cuenta_id'=>$cuentaId,'estado_cuenta'=>$actual['estado'],'saldo_pendiente'=>(float)$actual['saldo_pendiente']],201);
}

function cxc_cancelar_pago(PDO $conexion): void
{
    $pagoId=cxc_id($_POST['pago_id']??null,'cobro');$motivo=cxc_requerido($_POST['motivo']??'','Indica el motivo de cancelación del cobro.',10000);
    $conexion->beginTransaction();
    $st=$conexion->prepare("SELECT pc.*,m.codigo moneda_codigo,cl.nombre_razon_social cliente FROM pagos_cliente pc INNER JOIN monedas m ON m.id=pc.moneda_id INNER JOIN clientes cl ON cl.id=pc.cliente_id WHERE pc.id=:id LIMIT 1 FOR UPDATE");$st->execute([':id'=>$pagoId]);$pago=$st->fetch();
    if(!$pago)cxc_cancelar($conexion,'El cobro ya no existe.',404);if($pago['estado']==='CANCELADO'){$conexion->commit();si_responder_json(true,'El cobro ya estaba cancelado.');}
    $st=$conexion->prepare("SELECT cuenta_por_cobrar_id,importe_aplicado FROM aplicaciones_pago_cliente WHERE pago_cliente_id=:id ORDER BY id ASC FOR UPDATE");$st->execute([':id'=>$pagoId]);$apps=$st->fetchAll();
    if(!$apps)cxc_cancelar($conexion,'El cobro no tiene aplicaciones financieras relacionadas. Revisa la integridad antes de cancelarlo.',409);
    $antes=[];foreach($apps as $a){$id=(int)$a['cuenta_por_cobrar_id'];$c=cxc_recalcular_cuenta($conexion,$id);$antes[$id]=['folio'=>$c['folio'],'importe_pagado'=>(float)$c['importe_pagado'],'saldo_pendiente'=>(float)$c['saldo_pendiente'],'estado'=>$c['estado']];}
    $conexion->prepare("UPDATE pagos_cliente SET estado='CANCELADO',motivo_cancelacion=:motivo,cancelado_at=NOW(),cancelado_by=:usuario WHERE id=:id")->execute([':motivo'=>$motivo,':usuario'=>(int)$_SESSION['usuario_id'],':id'=>$pagoId]);
    $despues=[];foreach($apps as $a){$id=(int)$a['cuenta_por_cobrar_id'];$c=cxc_recalcular_cuenta($conexion,$id);$despues[$id]=['folio'=>$c['folio'],'importe_pagado'=>(float)$c['importe_pagado'],'saldo_pendiente'=>(float)$c['saldo_pendiente'],'estado'=>$c['estado']];}
    cxc_auditar($conexion,'COBRO_CLIENTE_CANCELADO','pagos_cliente',$pagoId,'Se canceló un cobro de cliente y se recalcularon las cuentas relacionadas.',
        ['folio'=>$pago['folio'],'estado'=>$pago['estado'],'importe'=>(float)$pago['importe'],'cuentas'=>$antes],
        ['folio'=>$pago['folio'],'estado'=>'CANCELADO','motivo_cancelacion'=>$motivo,'cuentas'=>$despues]);
    $conexion->commit();si_responder_json(true,'Cobro cancelado correctamente. El saldo de la cuenta fue restaurado sin borrar el historial.');
}

function cxc_vencimientos(PDO $conexion): void
{
    $pagina=cxc_entero_rango($_GET['pagina']??1,1,PHP_INT_MAX,1);$porPagina=cxc_entero_rango($_GET['por_pagina']??20,10,100,20);$q=cxc_texto($_GET['busqueda']??'',180);$h=strtoupper(cxc_texto($_GET['horizonte']??'30_DIAS',20));$monedaId=cxc_entero_rango($_GET['moneda_id']??0,0,PHP_INT_MAX,0);
    if(!in_array($h,['VENCIDAS','HOY','7_DIAS','15_DIAS','30_DIAS','60_DIAS','TODAS'],true))$h='30_DIAS';
    $where=["c.estado<>'CANCELADA'",'c.saldo_pendiente>0.00005'];$params=[];
    if($q!==''){$where[]="(c.folio LIKE :q_cxc OR v.folio LIKE :q_venta OR cl.codigo LIKE :q_codigo OR cl.nombre_razon_social LIKE :q_cliente)";$like='%'.$q.'%';foreach([':q_cxc',':q_venta',':q_codigo',':q_cliente'] as $k)$params[$k]=$like;}
    if($monedaId>0){$where[]='c.moneda_id=:moneda';$params[':moneda']=$monedaId;}
    if($h==='VENCIDAS')$where[]='c.fecha_vencimiento<CURDATE()'; elseif($h==='HOY')$where[]='c.fecha_vencimiento=CURDATE()'; elseif(in_array($h,['7_DIAS','15_DIAS','30_DIAS','60_DIAS'],true)){$d=(int)$h;$where[]="c.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$d} DAY)";}
    $whereSql='WHERE '.implode(' AND ',$where);$from="FROM cuentas_por_cobrar c INNER JOIN ventas v ON v.id=c.venta_id INNER JOIN clientes cl ON cl.id=c.cliente_id INNER JOIN monedas m ON m.id=c.moneda_id";
    $st=$conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");cxc_bind($st,$params);$st->execute();$total=(int)$st->fetchColumn();$totalPaginas=max(1,(int)ceil($total/$porPagina));$pagina=min($pagina,$totalPaginas);$offset=($pagina-1)*$porPagina;$case=cxc_estado_case('c');
    $st=$conexion->prepare("SELECT c.id,c.folio,v.folio venta_folio,v.fecha_venta,cl.codigo cliente_codigo,cl.nombre_razon_social cliente,m.id moneda_id,m.codigo moneda_codigo,m.simbolo moneda_simbolo,c.importe_original,c.importe_pagado,c.saldo_pendiente,c.fecha_documento,c.fecha_vencimiento,DATEDIFF(c.fecha_vencimiento,CURDATE()) dias_vencimiento,{$case} estado_calculado {$from} {$whereSql} ORDER BY c.fecha_vencimiento ASC,c.id ASC LIMIT :limite OFFSET :offset");cxc_bind($st,$params);$st->bindValue(':limite',$porPagina,PDO::PARAM_INT);$st->bindValue(':offset',$offset,PDO::PARAM_INT);$st->execute();$cuentas=$st->fetchAll();foreach($cuentas as &$c)cxc_tipar_cuenta($c);unset($c);
    $st=$conexion->prepare("SELECT m.id moneda_id,m.codigo,m.simbolo,COUNT(*) cuentas,SUM(c.saldo_pendiente) saldo {$from} {$whereSql} GROUP BY m.id,m.codigo,m.simbolo,m.es_base ORDER BY m.es_base DESC,m.codigo ASC");cxc_bind($st,$params);$st->execute();$tot=$st->fetchAll();foreach($tot as &$t){$t['moneda_id']=(int)$t['moneda_id'];$t['cuentas']=(int)$t['cuentas'];$t['saldo']=(float)$t['saldo'];}unset($t);
    si_responder_json(true,'Vencimientos cargados.',['cuentas'=>$cuentas,'totales_por_moneda'=>$tot,'paginacion'=>['pagina'=>$pagina,'por_pagina'=>$porPagina,'total_registros'=>$total,'total_paginas'=>$totalPaginas]]);
}

function cxc_recalcular_cuenta(PDO $conexion, int $cuentaId): array
{
    $st=$conexion->prepare("SELECT c.*,v.tipo_cambio_a_base AS tipo_cambio_venta FROM cuentas_por_cobrar c INNER JOIN ventas v ON v.id=c.venta_id WHERE c.id=:id LIMIT 1 FOR UPDATE");$st->execute([':id'=>$cuentaId]);$c=$st->fetch();if(!$c)cxc_cancelar($conexion,'La cuenta por cobrar ya no existe.',404);
    foreach(['importe_original','importe_pagado','saldo_pendiente','tipo_cambio_venta'] as $f)$c[$f]=(float)$c[$f];$c['moneda_id']=(int)$c['moneda_id'];$c['cliente_id']=(int)$c['cliente_id'];
    if($c['estado']==='CANCELADA')return $c;
    $st=$conexion->prepare("SELECT COALESCE(SUM(app.importe_aplicado),0) FROM aplicaciones_pago_cliente app INNER JOIN pagos_cliente pc ON pc.id=app.pago_cliente_id WHERE app.cuenta_por_cobrar_id=:id AND pc.estado='APLICADO'");$st->execute([':id'=>$cuentaId]);$pagado=cxc_round4((float)$st->fetchColumn());$original=cxc_round4((float)$c['importe_original']);
    if($pagado-$original>0.00005)cxc_cancelar($conexion,'Los cobros aplicados exceden el importe original de la cuenta. Se requiere revisar la integridad financiera.',409);
    $saldo=cxc_round4(max(0,$original-$pagado));$estado=$saldo<=0.00005?'PAGADA':(((string)$c['fecha_vencimiento']<date('Y-m-d'))?'VENCIDA':($pagado>0.00005?'PARCIAL':'PENDIENTE'));
    $conexion->prepare("UPDATE cuentas_por_cobrar SET importe_pagado=:pagado,estado=:estado WHERE id=:id")->execute([':pagado'=>$pagado,':estado'=>$estado,':id'=>$cuentaId]);
    $c['importe_original']=$original;$c['importe_pagado']=$pagado;$c['saldo_pendiente']=$saldo;$c['estado']=$estado;return $c;
}

function cxc_tipo_cambio_pago(
    PDO $conexion,
    int $monedaId,
    string $fecha,
    float $fallbackVenta
): float {
    $tipo = si_tc_resolver_a_base($conexion, $monedaId, $fecha, true);
    if ($tipo !== null && (float) $tipo['tipo_cambio'] > 0) {
        return (float) $tipo['tipo_cambio'];
    }

    if ($fallbackVenta > 0) {
        return $fallbackVenta;
    }

    cxc_cancelar(
        $conexion,
        'No existe un tipo de cambio disponible para registrar este cobro.',
        409
    );
}

/* =========================================================================
   CONSULTAS / FILTROS / HELPERS
   ========================================================================= */

function cxc_estado_case(string $alias): string
{
    return "CASE
        WHEN {$alias}.estado = 'CANCELADA' THEN 'CANCELADA'
        WHEN {$alias}.saldo_pendiente <= 0.00005 THEN 'PAGADA'
        WHEN {$alias}.fecha_vencimiento < CURDATE() THEN 'VENCIDA'
        WHEN {$alias}.importe_pagado > 0.00005 THEN 'PARCIAL'
        ELSE 'PENDIENTE'
    END";
}

function cxc_agregar_filtro_estado(array &$where, string $estado): void
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

function cxc_agregar_filtro_vencimiento(array &$where, string $vencimiento): void
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

function cxc_tipar_cuenta(array &$c): void
{
    foreach (['id', 'venta_id', 'cliente_id', 'moneda_id', 'abonos_aplicados'] as $campo) {
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

function cxc_requerido($valor, string $mensaje, int $maximo): string
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

function cxc_nullable($valor, int $maximo): ?string
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

function cxc_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    if (mb_strlen($texto) > $maximo) {
        $texto = mb_substr($texto, 0, $maximo);
    }
    return $texto;
}

function cxc_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Identificador de ' . $entidad . ' inválido.', [], 422);
    }
    return (int) $id;
}

function cxc_entero_rango($valor, int $minimo, int $maximo, int $default): int
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

function cxc_decimal_positivo($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $n = (float) $valor;
    if (!is_finite($n) || $n <= 0 || $n > 999999999999.0) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return cxc_round4($n);
}

function cxc_fecha_opcional($valor): ?string
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

function cxc_fecha_hora($valor, string $campo): string
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

function cxc_round4(float $n): float
{
    return round($n, 4, PHP_ROUND_HALF_UP);
}

function cxc_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}

function cxc_auditar(
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
                'cuentas_por_cobrar',
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

function cxc_cancelar(PDO $conexion, string $mensaje, int $codigo, array $extra = []): never
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    si_responder_json(false, $mensaje, $extra, $codigo);
}
