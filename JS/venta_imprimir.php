<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('ventas.ver', false);

if (!($conexion instanceof PDO)) {
    http_response_code(503);
    exit('No fue posible conectar con la base de datos.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Venta inválida.');
}

$stmt = $conexion->prepare(
    "SELECT v.*, c.codigo AS cliente_codigo, m.codigo AS moneda_codigo, m.simbolo AS moneda_simbolo,
            q.folio AS cotizacion_folio, a.folio AS apartado_folio, COALESCE(a.importe_anticipado, 0) AS importe_anticipado,
            cx.folio AS cxc_folio, cx.saldo_pendiente AS cxc_saldo, cx.fecha_vencimiento AS cxc_vencimiento, cx.estado AS cxc_estado,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por
     FROM ventas v
     LEFT JOIN clientes c ON c.id = v.cliente_id
     INNER JOIN monedas m ON m.id = v.moneda_id
     LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
     LEFT JOIN apartados a ON a.id = v.apartado_id
     LEFT JOIN cuentas_por_cobrar cx ON cx.venta_id = v.id
     LEFT JOIN usuarios u ON u.id = v.created_by
     WHERE v.id = :id LIMIT 1"
);
$stmt->execute([':id' => (int) $id]);
$venta = $stmt->fetch();
if (!$venta) {
    http_response_code(404);
    exit('La venta no existe.');
}

$stmt = $conexion->prepare(
    "SELECT d.*, a.codigo AS almacen_codigo, a.nombre AS almacen_nombre
     FROM ventas_detalle d
     INNER JOIN almacenes a ON a.id = d.almacen_id
     WHERE d.venta_id = :id
     ORDER BY d.renglon ASC"
);
$stmt->execute([':id' => (int) $id]);
$detalles = $stmt->fetchAll();

$stmt = $conexion->prepare(
    "SELECT pv.fecha_pago, pv.importe, pv.referencia, pv.estado, mp.nombre AS metodo_nombre
     FROM pagos_venta pv
     INNER JOIN metodos_pago mp ON mp.id = pv.metodo_pago_id
     WHERE pv.venta_id = :id
     ORDER BY pv.fecha_pago ASC, pv.id ASC"
);
$stmt->execute([':id' => (int) $id]);
$pagos = $stmt->fetchAll();

$empresa = $conexion->query("SELECT valor_texto FROM configuracion_sistema WHERE clave = 'empresa.nombre' LIMIT 1")->fetchColumn();
$empresa = $empresa ?: 'Sistema Integral';

function ven_imp_fecha(?string $v, bool $hora = false): string {
    if (!$v) return '—';
    $ts = strtotime($v);
    if ($ts === false) return $v;
    return $hora ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}
function ven_imp_num($v, int $d = 2): string { return number_format((float) $v, $d, '.', ','); }
function ven_imp_moneda($v, array $venta): string {
    return ($venta['moneda_simbolo'] ?: '$') . ven_imp_num($v, 2) . ' ' . $venta['moneda_codigo'];
}

$origen = $venta['apartado_folio']
    ? 'Apartado ' . $venta['apartado_folio']
    : ($venta['cotizacion_folio'] ? 'Cotización ' . $venta['cotizacion_folio'] : 'Venta directa');
$pagadoDirecto = 0.0;
foreach ($pagos as $p) if ($p['estado'] === 'APLICADO') $pagadoDirecto += (float) $p['importe'];
$pagadoTotal = $pagadoDirecto + (float) $venta['importe_anticipado'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= si_escapar($venta['folio']) ?> | Venta</title>
<style>
*{box-sizing:border-box} body{margin:0;background:#f4f6f5;color:#1f2f26;font-family:Arial,Helvetica,sans-serif}.toolbar{display:flex;justify-content:flex-end;gap:8px;width:min(980px,calc(100% - 32px));margin:16px auto 0}.toolbar button,.toolbar a{padding:9px 13px;border:1px solid #ced9d1;border-radius:8px;background:#fff;color:#24342a;text-decoration:none;cursor:pointer}.sheet{position:relative;width:min(980px,calc(100% - 32px));margin:12px auto 32px;padding:34px 38px;background:#fff;box-shadow:0 12px 35px rgba(0,0,0,.08)}.watermark{position:absolute;top:180px;left:50%;transform:translateX(-50%) rotate(-25deg);color:rgba(150,20,20,.10);font-size:82px;font-weight:800;letter-spacing:.12em;pointer-events:none}.header{display:flex;justify-content:space-between;gap:28px;padding-bottom:20px;border-bottom:2px solid #173d2b}.brand h1{margin:0 0 6px;font-size:25px;color:#173d2b}.brand p{margin:0;color:#617067}.meta{text-align:right}.meta h2{margin:0 0 8px;font-size:24px}.meta span,.meta strong{display:block;margin-top:3px}.box{display:grid;grid-template-columns:1.5fr 1fr;gap:20px;margin:22px 0;padding:15px;border:1px solid #dde6e0;border-radius:10px;background:#fafcfb}.box h3{margin:0 0 7px;font-size:12px;text-transform:uppercase;color:#647168}.box p{margin:3px 0;font-size:13px;color:#5e6c63}table{width:100%;border-collapse:collapse}th,td{padding:9px 8px;border-bottom:1px solid #e8eeea;text-align:left;font-size:12px;vertical-align:top}th{background:#f4f7f5;color:#56635b}.num{text-align:right;white-space:nowrap}.product strong,.product small{display:block}.product small{margin-top:3px;color:#718078}.footer-grid{display:grid;grid-template-columns:1.35fr .8fr;gap:28px;margin-top:22px}.notes h3{margin:0 0 7px;font-size:14px}.notes p{margin:4px 0;color:#5e6c63;font-size:13px;white-space:pre-wrap}.totals{border:1px solid #dde6e0;border-radius:9px;padding:10px 13px}.totals div{display:flex;justify-content:space-between;gap:18px;padding:6px 0;border-bottom:1px dashed #dce5df}.totals div:last-child{border:0;font-size:17px;font-weight:700}.payment{margin-top:18px;padding-top:14px;border-top:1px solid #e2e8e4}.payment h3{margin:0 0 8px;font-size:14px}.legal{margin-top:24px;color:#738078;font-size:11px;text-align:center}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:100%;margin:0;padding:20px;box-shadow:none}}@media(max-width:700px){.header,.box,.footer-grid{display:block}.meta{text-align:left;margin-top:16px}.sheet{width:100%;margin:0;padding:20px}}
</style>
</head>
<body>
<div class="toolbar"><a href="ventas.php">Volver</a><button type="button" onclick="window.print()">Imprimir</button></div>
<main class="sheet">
<?php if ($venta['estado'] === 'CANCELADA'): ?><div class="watermark">CANCELADA</div><?php endif; ?>
<header class="header"><div class="brand"><h1><?= si_escapar((string) $empresa) ?></h1><p>Comprobante interno de venta</p></div><div class="meta"><h2>VENTA</h2><strong><?= si_escapar($venta['folio']) ?></strong><span><?= ven_imp_fecha($venta['fecha_venta'], true) ?></span><span><?= si_escapar($venta['estado']) ?> · <?= si_escapar($venta['condicion_pago']) ?></span></div></header>
<section class="box"><div><h3>Cliente</h3><strong><?= si_escapar($venta['cliente_nombre_snapshot'] ?: 'Público general') ?></strong><p><?= si_escapar($venta['cliente_codigo'] ?: 'Sin código de cliente') ?></p><?php if ($venta['cliente_rfc_snapshot']): ?><p>RFC: <?= si_escapar($venta['cliente_rfc_snapshot']) ?></p><?php endif; ?></div><div><h3>Operación</h3><p>Origen: <?= si_escapar($origen) ?></p><p>Moneda: <?= si_escapar($venta['moneda_codigo']) ?></p><p>Registró: <?= si_escapar(trim((string) $venta['creado_por']) ?: '—') ?></p></div></section>
<table><thead><tr><th>#</th><th>Producto</th><th>Almacén</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Desc.</th><th class="num">Impuesto</th><th class="num">Total</th></tr></thead><tbody>
<?php foreach ($detalles as $d): ?><tr><td><?= (int) $d['renglon'] ?></td><td class="product"><strong><?= si_escapar($d['producto_nombre_snapshot']) ?></strong><small><?= si_escapar($d['sku_snapshot']) ?></small></td><td><?= si_escapar($d['almacen_codigo']) ?></td><td class="num"><?= ven_imp_num($d['cantidad'], 3) ?> <?= si_escapar($d['unidad_nombre_snapshot']) ?></td><td class="num"><?= ven_imp_moneda($d['precio_unitario'], $venta) ?></td><td class="num"><?= ven_imp_num($d['descuento_pct'], 2) ?>%</td><td class="num"><?= ven_imp_num($d['impuesto_pct_snapshot'], 2) ?>%</td><td class="num"><strong><?= ven_imp_moneda($d['total'], $venta) ?></strong></td></tr><?php endforeach; ?>
</tbody></table>
<div class="footer-grid"><section class="notes"><h3>Observaciones</h3><p><?= si_escapar($venta['observaciones'] ?: 'Sin observaciones.') ?></p><?php if ($venta['estado'] === 'CANCELADA'): ?><h3>Motivo de cancelación</h3><p><?= si_escapar($venta['motivo_cancelacion'] ?: '—') ?></p><?php endif; ?></section><section class="totals"><div><span>Subtotal</span><strong><?= ven_imp_moneda($venta['subtotal'], $venta) ?></strong></div><div><span>Descuento</span><strong><?= ven_imp_moneda($venta['descuento_total'], $venta) ?></strong></div><div><span>Impuestos</span><strong><?= ven_imp_moneda($venta['impuesto_total'], $venta) ?></strong></div><div><span>Total</span><strong><?= ven_imp_moneda($venta['total'], $venta) ?></strong></div></section></div>
<section class="payment"><h3>Situación de pago</h3>
<?php if ($venta['condicion_pago'] === 'CREDITO'): ?><p>Cuenta por cobrar: <strong><?= si_escapar($venta['cxc_folio'] ?: '—') ?></strong> · Saldo: <strong><?= ven_imp_moneda($venta['cxc_saldo'] ?: 0, $venta) ?></strong> · Vencimiento: <?= ven_imp_fecha($venta['cxc_vencimiento']) ?>.</p><?php else: ?><p>Anticipos aplicados: <strong><?= ven_imp_moneda($venta['importe_anticipado'], $venta) ?></strong> · Cobro registrado al vender: <strong><?= ven_imp_moneda($pagadoDirecto, $venta) ?></strong> · Total cubierto: <strong><?= ven_imp_moneda($pagadoTotal, $venta) ?></strong>.</p><?php endif; ?>
<?php if ($pagos): ?><table><thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Estado</th><th class="num">Importe</th></tr></thead><tbody><?php foreach ($pagos as $p): ?><tr><td><?= ven_imp_fecha($p['fecha_pago'], true) ?></td><td><?= si_escapar($p['metodo_nombre']) ?></td><td><?= si_escapar($p['referencia'] ?: '—') ?></td><td><?= si_escapar($p['estado']) ?></td><td class="num"><?= ven_imp_moneda($p['importe'], $venta) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<p class="legal">Comprobante interno del Sistema Integral. No sustituye un CFDI.</p>
</main>
</body>
</html>
