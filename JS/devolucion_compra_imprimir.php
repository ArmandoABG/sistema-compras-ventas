<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('devoluciones.compra', false);

if (!($conexion instanceof PDO)) {
    http_response_code(503);
    exit('No fue posible conectar con la base de datos.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Devolución inválida.');
}

$stmt = $conexion->prepare(
    "SELECT
        d.*,
        c.folio AS compra_folio,
        c.fecha_compra,
        c.numero_factura,
        c.proveedor_nombre_snapshot,
        c.proveedor_rfc_snapshot,
        c.condicion_pago,
        pr.codigo AS proveedor_codigo,
        pr.nombre_comercial,
        pr.contacto_nombre,
        pr.telefono AS proveedor_telefono,
        pr.correo AS proveedor_correo,
        m.codigo AS moneda_codigo,
        m.simbolo AS moneda_simbolo,
        CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS creado_por
     FROM devoluciones_compra d
     INNER JOIN compras c ON c.id = d.compra_id
     INNER JOIN monedas m ON m.id = c.moneda_id
     LEFT JOIN proveedores pr ON pr.id = d.proveedor_id
     LEFT JOIN usuarios u ON u.id = d.created_by
     WHERE d.id = :id
       AND d.estado = 'CONFIRMADA'
     LIMIT 1"
);
$stmt->execute([':id' => (int) $id]);
$devolucion = $stmt->fetch();

if (!$devolucion) {
    http_response_code(404);
    exit('La devolución a proveedor no existe o todavía no está confirmada.');
}

$stmt = $conexion->prepare(
    "SELECT
        dd.id,
        dd.cantidad_base,
        dd.importe,
        cd.renglon,
        cd.producto_nombre_snapshot AS producto,
        cd.sku_snapshot AS sku,
        cd.unidad_nombre_snapshot AS unidad,
        cd.factor_a_unidad_base,
        um.simbolo AS unidad_simbolo,
        a.codigo AS almacen_codigo,
        a.nombre AS almacen_nombre,
        rc.folio AS recepcion_folio,
        rc.fecha_recepcion
     FROM devoluciones_compra_detalle dd
     INNER JOIN compras_detalle cd ON cd.id = dd.compra_detalle_id
     INNER JOIN almacenes a ON a.id = dd.almacen_id
     LEFT JOIN unidades_medida um ON um.id = cd.unidad_id
     LEFT JOIN recepciones_compra_detalle rcd ON rcd.id = dd.recepcion_detalle_id
     LEFT JOIN recepciones_compra rc ON rc.id = rcd.recepcion_id
     WHERE dd.devolucion_id = :id
     ORDER BY cd.renglon ASC, dd.id ASC"
);
$stmt->execute([':id' => (int) $id]);
$detalles = $stmt->fetchAll();

$stmt = $conexion->prepare(
    "SELECT
        r.folio,
        r.importe,
        r.estado,
        r.referencia,
        r.liquidada_at,
        mp.nombre AS metodo_nombre,
        CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS liquidada_por
     FROM regularizaciones_financieras r
     LEFT JOIN metodos_pago mp ON mp.id = r.metodo_pago_id
     LEFT JOIN usuarios u ON u.id = r.liquidada_by
     WHERE r.devolucion_compra_id = :id
       AND r.tipo = 'REINTEGRO_PROVEEDOR'
     ORDER BY r.id DESC
     LIMIT 1"
);
$stmt->execute([':id' => (int) $id]);
$regularizacion = $stmt->fetch() ?: null;

$empresa = $conexion->query(
    "SELECT valor_texto
     FROM configuracion_sistema
     WHERE clave = 'empresa.nombre'
     LIMIT 1"
)->fetchColumn();
$empresa = $empresa ?: 'Sistema Integral';

function dev_imp_fecha(?string $valor, bool $hora = false): string
{
    if (!$valor) {
        return '—';
    }
    $ts = strtotime($valor);
    if ($ts === false) {
        return $valor;
    }
    return $hora ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}

function dev_imp_num(mixed $valor, int $decimales = 2): string
{
    return number_format((float) $valor, $decimales, '.', ',');
}

function dev_imp_moneda(mixed $valor, array $devolucion): string
{
    return (string) ($devolucion['moneda_simbolo'] ?: '$')
        . dev_imp_num($valor, 2)
        . ' '
        . (string) $devolucion['moneda_codigo'];
}

function dev_imp_estado_regularizacion(string $estado): string
{
    return match ($estado) {
        'LIQUIDADA' => 'Liquidada',
        'PENDIENTE' => 'Pendiente',
        'NO_APLICA' => 'No aplica',
        default => $estado,
    };
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= si_escapar((string) $devolucion['folio']) ?> | Devolución a proveedor</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f4;color:#202b25;font-family:Arial,Helvetica,sans-serif}.toolbar{display:flex;justify-content:flex-end;gap:8px;width:min(900px,calc(100% - 28px));margin:16px auto 0}.toolbar a,.toolbar button{padding:9px 13px;border:1px solid #cfd8d2;border-radius:8px;background:#fff;color:#24352b;text-decoration:none;cursor:pointer}.sheet{width:min(900px,calc(100% - 28px));margin:12px auto 32px;padding:32px 36px;background:#fff;box-shadow:0 12px 34px rgba(0,0,0,.08)}.header{display:flex;justify-content:space-between;gap:24px;padding-bottom:18px;border-bottom:2px solid #244934}.brand h1{margin:0 0 6px;font-size:24px;color:#244934}.brand p{margin:0;color:#647168;font-size:13px}.meta{text-align:right}.meta h2{margin:0 0 7px;font-size:22px}.meta strong,.meta span{display:block;margin-top:3px}.info{display:grid;grid-template-columns:1.35fr 1fr;gap:18px;margin:20px 0}.card{padding:14px;border:1px solid #dfe7e2;border-radius:9px;background:#fbfcfb}.card h3{margin:0 0 7px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#65736a}.card p{margin:4px 0;font-size:13px}.reason{margin:16px 0;padding:13px 15px;border-left:4px solid #244934;background:#f7faf8}.reason strong{display:block;margin-bottom:5px}.reason p{margin:0;white-space:pre-wrap;font-size:13px}table{width:100%;border-collapse:collapse}th,td{padding:9px 7px;border-bottom:1px solid #e6ece8;text-align:left;font-size:12px;vertical-align:top}th{background:#f3f7f4;color:#56655c}.num{text-align:right;white-space:nowrap}.product strong,.product small{display:block}.product small{margin-top:3px;color:#718078}.summary{display:grid;grid-template-columns:1.2fr .8fr;gap:22px;margin-top:20px}.finance{padding:12px 14px;border:1px solid #dfe7e2;border-radius:9px}.finance div{display:flex;justify-content:space-between;gap:18px;padding:6px 0;border-bottom:1px dashed #dce4df;font-size:13px}.finance div:last-child{border:0;font-size:16px;font-weight:700}.settlement{padding:12px 14px;border:1px solid #dfe7e2;border-radius:9px}.settlement h3{margin:0 0 8px;font-size:13px}.settlement p{margin:5px 0;font-size:12px;color:#5e6b63}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:54px}.signature{text-align:center;font-size:12px}.signature::before{content:"";display:block;border-top:1px solid #6c776f;margin-bottom:7px}.legal{margin-top:26px;color:#748078;font-size:10px;text-align:center}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:100%;margin:0;padding:18px;box-shadow:none}.legal{margin-top:18px}}@media(max-width:700px){.header,.info,.summary,.signatures{display:block}.meta{text-align:left;margin-top:14px}.card,.settlement,.finance{margin-bottom:12px}.signature{margin-top:50px}.sheet{width:100%;margin:0;padding:18px}}
</style>
</head>
<body>
<div class="toolbar"><a href="devoluciones.php">Volver</a><button type="button" onclick="window.print()">Imprimir ticket / Guardar PDF</button></div>
<main class="sheet">
<header class="header">
    <div class="brand">
        <h1><?= si_escapar((string) $empresa) ?></h1>
        <p>Ticket / comprobante de devolución de mercancía a proveedor</p>
    </div>
    <div class="meta">
        <h2>DEVOLUCIÓN</h2>
        <strong><?= si_escapar((string) $devolucion['folio']) ?></strong>
        <span><?= si_escapar(dev_imp_fecha((string) $devolucion['fecha_devolucion'], true)) ?></span>
        <span><?= si_escapar((string) $devolucion['estado']) ?></span>
    </div>
</header>

<section class="info">
    <div class="card">
        <h3>Proveedor</h3>
        <p><strong><?= si_escapar((string) $devolucion['proveedor_nombre_snapshot']) ?></strong></p>
        <?php if (!empty($devolucion['proveedor_rfc_snapshot'])): ?><p>RFC: <?= si_escapar((string) $devolucion['proveedor_rfc_snapshot']) ?></p><?php endif; ?>
        <?php if (!empty($devolucion['proveedor_codigo'])): ?><p>Código: <?= si_escapar((string) $devolucion['proveedor_codigo']) ?></p><?php endif; ?>
        <?php if (!empty($devolucion['contacto_nombre'])): ?><p>Contacto: <?= si_escapar((string) $devolucion['contacto_nombre']) ?></p><?php endif; ?>
        <?php if (!empty($devolucion['proveedor_telefono'])): ?><p>Tel.: <?= si_escapar((string) $devolucion['proveedor_telefono']) ?></p><?php endif; ?>
    </div>
    <div class="card">
        <h3>Documento origen</h3>
        <p>Compra: <strong><?= si_escapar((string) $devolucion['compra_folio']) ?></strong></p>
        <p>Fecha compra: <?= si_escapar(dev_imp_fecha((string) $devolucion['fecha_compra'])) ?></p>
        <p>Factura / documento: <?= si_escapar((string) ($devolucion['numero_factura'] ?: '—')) ?></p>
        <p>Registró: <?= si_escapar(trim((string) $devolucion['creado_por']) ?: '—') ?></p>
    </div>
</section>

<section class="reason"><strong>Motivo de la devolución</strong><p><?= si_escapar((string) $devolucion['motivo']) ?></p></section>

<table>
<thead><tr><th>#</th><th>Producto</th><th>Recepción</th><th>Almacén</th><th class="num">Cantidad</th><th class="num">Importe</th></tr></thead>
<tbody>
<?php foreach ($detalles as $detalle):
    $factor = (float) $detalle['factor_a_unidad_base'];
    $cantidad = $factor > 0 ? (float) $detalle['cantidad_base'] / $factor : (float) $detalle['cantidad_base'];
?>
<tr>
    <td><?= (int) $detalle['renglon'] ?></td>
    <td class="product"><strong><?= si_escapar((string) $detalle['producto']) ?></strong><small><?= si_escapar((string) ($detalle['sku'] ?: '')) ?></small></td>
    <td><?= si_escapar((string) ($detalle['recepcion_folio'] ?: 'Histórica')) ?></td>
    <td><?= si_escapar((string) $detalle['almacen_codigo']) ?> · <?= si_escapar((string) $detalle['almacen_nombre']) ?></td>
    <td class="num"><?= si_escapar(dev_imp_num($cantidad, 3)) ?> <?= si_escapar((string) ($detalle['unidad_simbolo'] ?: $detalle['unidad'])) ?></td>
    <td class="num"><strong><?= si_escapar(dev_imp_moneda($detalle['importe'], $devolucion)) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<section class="summary">
    <div class="settlement">
        <h3>Tratamiento financiero</h3>
        <p>Estado: <strong><?= si_escapar(dev_imp_estado_regularizacion((string) $devolucion['regularizacion_estado'])) ?></strong></p>
        <?php if ($regularizacion): ?>
            <p>Reintegro: <strong><?= si_escapar(dev_imp_moneda($regularizacion['importe'], $devolucion)) ?></strong></p>
            <p>Método: <?= si_escapar((string) ($regularizacion['metodo_nombre'] ?: 'Pendiente')) ?></p>
            <?php if (!empty($regularizacion['referencia'])): ?><p>Referencia: <?= si_escapar((string) $regularizacion['referencia']) ?></p><?php endif; ?>
            <?php if (!empty($regularizacion['liquidada_at'])): ?><p>Liquidado: <?= si_escapar(dev_imp_fecha((string) $regularizacion['liquidada_at'], true)) ?></p><?php endif; ?>
        <?php else: ?>
            <p>La devolución no generó un reintegro financiero adicional.</p>
        <?php endif; ?>
    </div>
    <div class="finance">
        <div><span>Total devuelto</span><strong><?= si_escapar(dev_imp_moneda($devolucion['total'], $devolucion)) ?></strong></div>
        <div><span>Compensado CxP</span><strong><?= si_escapar(dev_imp_moneda($devolucion['importe_compensado_cxp'], $devolucion)) ?></strong></div>
        <div><span>Reintegro proveedor</span><strong><?= si_escapar(dev_imp_moneda($devolucion['importe_reintegro'], $devolucion)) ?></strong></div>
    </div>
</section>

<section class="signatures">
    <div class="signature">Entregó mercancía / responsable de <?= si_escapar((string) $empresa) ?></div>
    <div class="signature">Recibió proveedor / nombre y firma</div>
</section>

<p class="legal">Comprobante interno de devolución de mercancía. No sustituye CFDI, nota de crédito ni documento fiscal emitido por el proveedor.</p>
</main>
</body>
</html>
