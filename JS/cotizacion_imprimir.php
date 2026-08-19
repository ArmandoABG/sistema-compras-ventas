<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('cotizaciones.ver', false);

if (!($conexion instanceof PDO)) {
    http_response_code(503);
    exit('No fue posible conectar con la base de datos.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Cotización inválida.');
}

$stmt = $conexion->prepare(
    "SELECT
        c.*,
        cl.codigo AS cliente_codigo,
        cl.rfc AS cliente_rfc,
        cl.telefono AS cliente_telefono,
        cl.correo AS cliente_correo,
        cl.calle,
        cl.numero_exterior,
        cl.numero_interior,
        cl.colonia,
        cl.municipio,
        cl.estado AS cliente_estado,
        cl.codigo_postal,
        cl.pais,
        m.codigo AS moneda_codigo,
        m.simbolo AS moneda_simbolo,
        u.usuario AS creado_por
     FROM cotizaciones c
     LEFT JOIN clientes cl ON cl.id = c.cliente_id
     INNER JOIN monedas m ON m.id = c.moneda_id
     LEFT JOIN usuarios u ON u.id = c.created_by
     WHERE c.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => (int) $id]);
$cotizacion = $stmt->fetch();

if (!$cotizacion) {
    http_response_code(404);
    exit('La cotización no existe.');
}

$stmtDet = $conexion->prepare(
    "SELECT
        cd.*,
        p.sku,
        pp.nombre AS presentacion_nombre,
        u.simbolo AS unidad_simbolo
     FROM cotizaciones_detalle cd
     INNER JOIN productos p ON p.id = cd.producto_id
     INNER JOIN unidades_medida u ON u.id = cd.unidad_id
     LEFT JOIN presentaciones_producto pp ON pp.id = cd.presentacion_id
     WHERE cd.cotizacion_id = :id
     ORDER BY cd.renglon ASC"
);
$stmtDet->execute([':id' => (int) $id]);
$detalles = $stmtDet->fetchAll();

$empresa = $conexion->query(
    "SELECT valor_texto
     FROM configuracion_sistema
     WHERE clave = 'empresa.nombre'
     LIMIT 1"
)->fetchColumn();

$empresa = $empresa ?: 'Sistema Integral';

function cot_imp_fecha(?string $valor, bool $hora = false): string
{
    if (!$valor) {
        return '—';
    }

    $ts = strtotime($valor);

    if ($ts === false) {
        return $valor;
    }

    return $hora
        ? date('d/m/Y H:i', $ts)
        : date('d/m/Y', $ts);
}

function cot_imp_num($valor, int $decimales = 2): string
{
    return number_format((float) $valor, $decimales, '.', ',');
}

function cot_imp_moneda($valor, array $cotizacion): string
{
    return ($cotizacion['moneda_simbolo'] ?: '$')
        . cot_imp_num($valor, 2)
        . ' '
        . $cotizacion['moneda_codigo'];
}

$direccion = array_filter([
    trim((string) ($cotizacion['calle'] ?? '') . ' ' . (string) ($cotizacion['numero_exterior'] ?? '')),
    $cotizacion['numero_interior'] ? 'Int. ' . $cotizacion['numero_interior'] : null,
    $cotizacion['colonia'] ?? null,
    $cotizacion['municipio'] ?? null,
    $cotizacion['cliente_estado'] ?? null,
    $cotizacion['codigo_postal'] ? 'C.P. ' . $cotizacion['codigo_postal'] : null,
    $cotizacion['pais'] ?? null,
]);

$estado = (string) $cotizacion['estado'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= si_escapar($cotizacion['folio']) ?> | Cotización</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f4f6f5;
            color: #1f2f26;
            font-family: Arial, Helvetica, sans-serif;
        }
        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            width: min(980px, calc(100% - 32px));
            margin: 16px auto 0;
        }
        .toolbar button,
        .toolbar a {
            padding: 9px 13px;
            border: 1px solid #ced9d1;
            border-radius: 8px;
            background: #fff;
            color: #24342a;
            text-decoration: none;
            cursor: pointer;
        }
        .sheet {
            position: relative;
            width: min(980px, calc(100% - 32px));
            margin: 12px auto 32px;
            padding: 34px 38px;
            background: #fff;
            box-shadow: 0 12px 35px rgba(0,0,0,.08);
        }
        .watermark {
            position: absolute;
            top: 160px;
            left: 50%;
            transform: translateX(-50%) rotate(-25deg);
            color: rgba(120,120,120,.10);
            font-size: 90px;
            font-weight: 800;
            letter-spacing: .12em;
            pointer-events: none;
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            padding-bottom: 22px;
            border-bottom: 2px solid #173d2b;
        }
        .brand h1 {
            margin: 0 0 6px;
            font-size: 25px;
            color: #173d2b;
        }
        .brand p {
            margin: 0;
            color: #617067;
        }
        .doc-meta {
            min-width: 270px;
            text-align: right;
        }
        .doc-meta h2 {
            margin: 0 0 10px;
            font-size: 24px;
        }
        .doc-meta strong,
        .doc-meta span {
            display: block;
        }
        .doc-meta span {
            margin-top: 4px;
            color: #66736b;
            font-size: 13px;
        }
        .client-box {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
            margin: 24px 0;
            padding: 16px;
            border: 1px solid #dde6e0;
            border-radius: 10px;
            background: #fafcfb;
        }
        .client-box h3 {
            margin: 0 0 8px;
            font-size: 13px;
            text-transform: uppercase;
            color: #647168;
            letter-spacing: .05em;
        }
        .client-box strong {
            display: block;
            margin-bottom: 5px;
            font-size: 16px;
        }
        .client-box p {
            margin: 3px 0;
            color: #5e6c63;
            font-size: 13px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th,
        td {
            padding: 9px 8px;
            border-bottom: 1px solid #e8eeea;
            text-align: left;
            font-size: 12px;
            vertical-align: top;
        }
        th {
            background: #f4f7f5;
            color: #56635b;
        }
        .num { text-align: right; white-space: nowrap; }
        .product strong { display: block; }
        .product small { color: #718078; }
        .footer-grid {
            display: grid;
            grid-template-columns: 1.4fr .8fr;
            gap: 28px;
            margin-top: 22px;
        }
        .notes h3 {
            margin: 0 0 8px;
            font-size: 14px;
        }
        .notes p {
            margin: 0;
            white-space: pre-wrap;
            color: #5d6a62;
            font-size: 13px;
            line-height: 1.5;
        }
        .totals {
            padding: 12px 14px;
            border: 1px solid #dce5df;
            border-radius: 10px;
        }
        .totals div {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 6px 0;
        }
        .totals .grand {
            margin-top: 4px;
            padding-top: 11px;
            border-top: 2px solid #173d2b;
            font-size: 18px;
            font-weight: 800;
        }
        .legal {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px solid #e3e9e5;
            color: #7a857e;
            font-size: 11px;
            line-height: 1.45;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet {
                width: 100%;
                margin: 0;
                padding: 20px;
                box-shadow: none;
            }
        }
        @media (max-width: 700px) {
            .header,
            .client-box,
            .footer-grid {
                grid-template-columns: 1fr;
                display: grid;
            }
            .doc-meta { text-align: left; }
            .sheet { padding: 22px 18px; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="cotizaciones.php">Volver</a>
        <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <main class="sheet">
        <?php if ($estado === 'BORRADOR'): ?>
            <div class="watermark">BORRADOR</div>
        <?php elseif ($estado === 'VENCIDA'): ?>
            <div class="watermark">VENCIDA</div>
        <?php elseif ($estado === 'RECHAZADA'): ?>
            <div class="watermark">RECHAZADA</div>
        <?php endif; ?>

        <header class="header">
            <div class="brand">
                <h1><?= si_escapar($empresa) ?></h1>
                <p>Cotización comercial</p>
            </div>

            <div class="doc-meta">
                <h2><?= si_escapar($cotizacion['folio']) ?></h2>
                <strong><?= si_escapar($estado) ?></strong>
                <span>Fecha: <?= si_escapar(cot_imp_fecha($cotizacion['fecha_cotizacion'], true)) ?></span>
                <span>Vigencia: <?= si_escapar(cot_imp_fecha($cotizacion['vigencia_hasta'])) ?></span>
                <span>Moneda: <?= si_escapar($cotizacion['moneda_codigo']) ?></span>
            </div>
        </header>

        <section class="client-box">
            <div>
                <h3>Cliente</h3>
                <strong><?= si_escapar($cotizacion['cliente_nombre_snapshot'] ?: '—') ?></strong>
                <?php if ($cotizacion['cliente_codigo']): ?>
                    <p>Código: <?= si_escapar($cotizacion['cliente_codigo']) ?></p>
                <?php endif; ?>
                <?php if ($cotizacion['cliente_rfc']): ?>
                    <p>RFC: <?= si_escapar($cotizacion['cliente_rfc']) ?></p>
                <?php endif; ?>
                <?php if ($cotizacion['cliente_telefono']): ?>
                    <p>Teléfono: <?= si_escapar($cotizacion['cliente_telefono']) ?></p>
                <?php endif; ?>
                <?php if ($cotizacion['cliente_correo']): ?>
                    <p>Correo: <?= si_escapar($cotizacion['cliente_correo']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <h3>Dirección</h3>
                <p><?= $direccion ? si_escapar(implode(', ', $direccion)) : 'No registrada.' ?></p>
                <p>Elaboró: <?= si_escapar($cotizacion['creado_por'] ?: '—') ?></p>
            </div>
        </section>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Producto</th>
                    <th>Presentación</th>
                    <th class="num">Cantidad</th>
                    <th class="num">Precio</th>
                    <th class="num">Desc.</th>
                    <th class="num">IVA</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $d): ?>
                    <tr>
                        <td><?= (int) $d['renglon'] ?></td>
                        <td class="product">
                            <strong><?= si_escapar($d['producto_nombre_snapshot']) ?></strong>
                            <small><?= si_escapar($d['sku']) ?></small>
                        </td>
                        <td><?= si_escapar($d['presentacion_nombre'] ?: $d['unidad_nombre_snapshot']) ?></td>
                        <td class="num"><?= si_escapar(cot_imp_num($d['cantidad'], 6)) ?></td>
                        <td class="num"><?= si_escapar(cot_imp_moneda($d['precio_unitario'], $cotizacion)) ?></td>
                        <td class="num"><?= si_escapar(cot_imp_num($d['descuento_pct'], 2)) ?>%</td>
                        <td class="num"><?= si_escapar(cot_imp_num($d['impuesto_pct_snapshot'], 2)) ?>%</td>
                        <td class="num"><strong><?= si_escapar(cot_imp_moneda($d['total'], $cotizacion)) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer-grid">
            <section class="notes">
                <h3>Observaciones</h3>
                <p><?= si_escapar($cotizacion['observaciones'] ?: 'Sin observaciones.') ?></p>
            </section>

            <section class="totals">
                <div><span>Descuento</span><strong>-<?= si_escapar(cot_imp_moneda($cotizacion['descuento_total'], $cotizacion)) ?></strong></div>
                <div><span>Subtotal</span><strong><?= si_escapar(cot_imp_moneda($cotizacion['subtotal'], $cotizacion)) ?></strong></div>
                <div><span>Impuestos</span><strong><?= si_escapar(cot_imp_moneda($cotizacion['impuesto_total'], $cotizacion)) ?></strong></div>
                <div class="grand"><span>Total</span><strong><?= si_escapar(cot_imp_moneda($cotizacion['total'], $cotizacion)) ?></strong></div>
            </section>
        </div>

        <footer class="legal">
            Esta cotización es una propuesta comercial y por sí sola no reserva ni descuenta inventario.
            Los precios, descuentos e impuestos mostrados corresponden a los valores guardados en esta cotización.
        </footer>
    </main>
</body>
</html>
