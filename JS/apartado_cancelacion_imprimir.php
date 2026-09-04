<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('apartados.ver', false);

if (!($conexion instanceof PDO)) {
    http_response_code(503);
    exit('No fue posible conectar con la base de datos.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Cancelación inválida.');
}

$stmt = $conexion->prepare(
    "SELECT
        ca.*,
        a.folio AS apartado_folio,
        a.fecha_apartado,
        a.reservado_hasta,
        a.estado AS apartado_estado,
        a.total AS apartado_total,
        c.codigo AS cliente_codigo,
        c.nombre_razon_social AS cliente_nombre,
        c.rfc AS cliente_rfc,
        c.telefono AS cliente_telefono,
        m.codigo AS moneda_codigo,
        m.simbolo AS moneda_simbolo,
        mp.nombre AS metodo_nombre,
        CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS registrado_por
     FROM cancelaciones_apartado ca
     INNER JOIN apartados a ON a.id = ca.apartado_id
     INNER JOIN clientes c ON c.id = ca.cliente_id
     INNER JOIN monedas m ON m.id = ca.moneda_id
     LEFT JOIN metodos_pago mp ON mp.id = ca.metodo_pago_id
     LEFT JOIN usuarios u ON u.id = ca.created_by
     WHERE ca.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => (int) $id]);
$cancelacion = $stmt->fetch();

if (!$cancelacion) {
    http_response_code(404);
    exit('La cancelación del apartado no existe.');
}

$empresa = $conexion->query(
    "SELECT valor_texto FROM configuracion_sistema WHERE clave = 'empresa.nombre' LIMIT 1"
)->fetchColumn();
$empresa = $empresa ?: 'Sistema Integral';

function can_apa_fecha(?string $valor, bool $hora = false): string
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

function can_apa_num(mixed $valor, int $decimales = 2): string
{
    return number_format((float) $valor, $decimales, '.', ',');
}

function can_apa_moneda(mixed $valor, array $cancelacion): string
{
    return (string) ($cancelacion['moneda_simbolo'] ?: '$')
        . can_apa_num($valor, 2)
        . ' '
        . (string) $cancelacion['moneda_codigo'];
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= si_escapar((string) $cancelacion['folio']) ?> | Cancelación de apartado</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f4;color:#202b25;font-family:Arial,Helvetica,sans-serif}.toolbar{display:flex;justify-content:flex-end;gap:8px;width:min(860px,calc(100% - 28px));margin:16px auto 0}.toolbar a,.toolbar button{padding:9px 13px;border:1px solid #cfd8d2;border-radius:8px;background:#fff;color:#24352b;text-decoration:none;cursor:pointer}.sheet{width:min(860px,calc(100% - 28px));margin:12px auto 32px;padding:32px 36px;background:#fff;box-shadow:0 12px 34px rgba(0,0,0,.08)}.header{display:flex;justify-content:space-between;gap:24px;padding-bottom:18px;border-bottom:2px solid #244934}.brand h1{margin:0 0 6px;font-size:24px;color:#244934}.brand p{margin:0;color:#647168;font-size:13px}.meta{text-align:right}.meta h2{margin:0 0 7px;font-size:21px}.meta strong,.meta span{display:block;margin-top:3px}.info{display:grid;grid-template-columns:1.2fr 1fr;gap:18px;margin:20px 0}.card{padding:14px;border:1px solid #dfe7e2;border-radius:9px;background:#fbfcfb}.card h3{margin:0 0 7px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#65736a}.card p{margin:4px 0;font-size:13px}.reason{margin:16px 0;padding:13px 15px;border-left:4px solid #244934;background:#f7faf8}.reason strong{display:block;margin-bottom:5px}.reason p{margin:0;white-space:pre-wrap;font-size:13px}.finance{margin-top:18px;padding:14px;border:1px solid #dfe7e2;border-radius:9px}.finance div{display:flex;justify-content:space-between;gap:18px;padding:8px 0;border-bottom:1px dashed #dce4df;font-size:13px}.finance div:last-child{border:0;font-size:17px;font-weight:700}.retention{font-weight:700}.note{margin-top:14px;padding:12px 14px;border:1px solid #eadfbd;border-radius:8px;background:#fffdf5;color:#66592f;font-size:12px;line-height:1.45}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:54px}.signature{text-align:center;font-size:12px}.signature::before{content:"";display:block;border-top:1px solid #6c776f;margin-bottom:7px}.legal{margin-top:26px;color:#748078;font-size:10px;text-align:center}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:100%;margin:0;padding:18px;box-shadow:none}}@media(max-width:700px){.header,.info,.signatures{display:block}.meta{text-align:left;margin-top:14px}.card{margin-bottom:12px}.signature{margin-top:50px}.sheet{width:100%;margin:0;padding:18px}}
</style>
</head>
<body>
<div class="toolbar"><a href="apartados.php">Volver</a><button type="button" onclick="window.print()">Imprimir ticket / Guardar PDF</button></div>
<main class="sheet">
<header class="header">
    <div class="brand">
        <h1><?= si_escapar((string) $empresa) ?></h1>
        <p>Comprobante interno de cancelación y liquidación de apartado</p>
    </div>
    <div class="meta">
        <h2>CANCELACIÓN DE APARTADO</h2>
        <strong><?= si_escapar((string) $cancelacion['folio']) ?></strong>
        <span><?= si_escapar(can_apa_fecha((string) $cancelacion['created_at'], true)) ?></span>
        <span>Apartado <?= si_escapar((string) $cancelacion['apartado_folio']) ?></span>
    </div>
</header>

<section class="info">
    <div class="card">
        <h3>Cliente</h3>
        <p><strong><?= si_escapar((string) $cancelacion['cliente_nombre']) ?></strong></p>
        <p>Código: <?= si_escapar((string) $cancelacion['cliente_codigo']) ?></p>
        <?php if (!empty($cancelacion['cliente_rfc'])): ?><p>RFC: <?= si_escapar((string) $cancelacion['cliente_rfc']) ?></p><?php endif; ?>
        <?php if (!empty($cancelacion['cliente_telefono'])): ?><p>Tel.: <?= si_escapar((string) $cancelacion['cliente_telefono']) ?></p><?php endif; ?>
    </div>
    <div class="card">
        <h3>Apartado</h3>
        <p>Folio: <strong><?= si_escapar((string) $cancelacion['apartado_folio']) ?></strong></p>
        <p>Creado: <?= si_escapar(can_apa_fecha((string) $cancelacion['fecha_apartado'], true)) ?></p>
        <p>Reserva hasta: <?= si_escapar(can_apa_fecha((string) $cancelacion['reservado_hasta'], true)) ?></p>
        <p>Registró cancelación: <?= si_escapar(trim((string) $cancelacion['registrado_por']) ?: '—') ?></p>
    </div>
</section>

<section class="reason"><strong>Motivo de cancelación</strong><p><?= si_escapar((string) $cancelacion['motivo']) ?></p></section>

<section class="finance">
    <div><span>Total del apartado</span><strong><?= si_escapar(can_apa_moneda($cancelacion['apartado_total'], $cancelacion)) ?></strong></div>
    <div><span>Anticipo recibido</span><strong><?= si_escapar(can_apa_moneda($cancelacion['importe_anticipado'], $cancelacion)) ?></strong></div>
    <div><span>Retención aplicada</span><strong class="retention"><?= si_escapar(can_apa_num($cancelacion['retencion_pct'], 2)) ?>%</strong></div>
    <div><span>Importe retenido</span><strong><?= si_escapar(can_apa_moneda($cancelacion['importe_retenido'], $cancelacion)) ?></strong></div>
    <div><span>Reembolso entregado al cliente</span><strong><?= si_escapar(can_apa_moneda($cancelacion['importe_reembolsado'], $cancelacion)) ?></strong></div>
</section>

<div class="note">
    <?php if ((float) $cancelacion['importe_reembolsado'] > 0.0001): ?>
        Reembolso registrado mediante <strong><?= si_escapar((string) ($cancelacion['metodo_nombre'] ?: 'método no disponible')) ?></strong><?php if (!empty($cancelacion['referencia'])): ?> · Referencia <?= si_escapar((string) $cancelacion['referencia']) ?><?php endif; ?>.
    <?php else: ?>
        No hubo importe a reembolsar. La totalidad del anticipo quedó retenida conforme al porcentaje registrado en esta cancelación.
    <?php endif; ?>
</div>

<section class="signatures">
    <div class="signature">Responsable de <?= si_escapar((string) $empresa) ?></div>
    <div class="signature">Cliente / firma de recibido y conformidad</div>
</section>

<p class="legal">Comprobante interno de cancelación de apartado y devolución de anticipo. No sustituye CFDI, nota de crédito ni documento fiscal cuando alguno resulte aplicable.</p>
</main>
</body>
</html>
