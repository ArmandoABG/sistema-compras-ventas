<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API de esta interfaz
|--------------------------------------------------------------------------
| Igual que en varios módulos del sistema de mantenimiento:
| dashboard.php puede consultar su propio ?dashboard_api=1.
|--------------------------------------------------------------------------
*/

if (isset($_GET['dashboard_api'])) {
    $endpoint =
        __DIR__
        . '/../funciones/dashboard_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=utf-8'
            );
        }

        echo json_encode([
            'success' => false,
            'mensaje' =>
                'No se encontró funciones/dashboard_funciones.php.',
        ]);

        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso(
    'dashboard.ver',
    false
);

$tituloPagina = 'Dashboard';

/*
 * El Dashboard conserva su misma estructura, pero cada bloque se muestra solo
 * si el usuario tiene permiso para consultar el módulo que origina esos datos.
 */
$puedeDashboardVentas = si_tiene_permiso('ventas.ver');
$puedeDashboardCompras = si_tiene_permiso('compras.ver');
$puedeDashboardInventario = si_tiene_permiso('inventario.ver');
$puedeDashboardMerma = $puedeDashboardInventario || si_tiene_permiso('inventario.mermas');
$puedeDashboardCobrar = si_tiene_permiso('cuentas_cobrar.ver');
$puedeDashboardPagar = si_tiene_permiso('cuentas_pagar.ver');
$puedeDashboardAuditoria = si_tiene_permiso('auditoria.ver');
$puedeDashboardTopClientes = $puedeDashboardVentas && si_tiene_permiso('clientes.ver');
$puedeDashboardTendencias = $puedeDashboardVentas || $puedeDashboardCompras;
$puedeActualizarTipoCambio = si_tiene_permiso('ventas.crear')
    || si_tiene_permiso('compras.crear')
    || si_tiene_permiso('cuentas_cobrar.cobrar')
    || si_tiene_permiso('cuentas_pagar.pagar');

$nombreUsuario = trim((string) (
    $_SESSION['nombre_completo']
    ?? $_SESSION['usuario']
    ?? 'Usuario'
));

$cssGeneral =
    __DIR__
    . '/../css/style_global.css';

$cssModulo =
    __DIR__
    . '/../css/style_dashboard.css';

$versionGeneral = is_file($cssGeneral)
    ? (string) filemtime($cssGeneral)
    : '1';

$versionModulo = is_file($cssModulo)
    ? (string) filemtime($cssModulo)
    : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="robots" content="noindex, nofollow">

    <title>
        Dashboard | Sistema Integral
    </title>

    <link
        rel="stylesheet"
        href="../css/style_global.css?v=<?= si_escapar($versionGeneral) ?>"
    >

    <link
        rel="stylesheet"
        href="../css/style_dashboard.css?v=<?= si_escapar($versionModulo) ?>"
    >
</head>
<body>

<div class="app-shell">

    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">

        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content">

            <header class="dashboard-heading">
                <div>
                    <p class="dashboard-eyebrow">
                        RESUMEN OPERATIVO
                    </p>

                    <h1>
                        Bienvenido,
                        <?= si_escapar(
                            $nombreUsuario !== ''
                                ? $nombreUsuario
                                : 'Usuario'
                        ) ?>
                    </h1>

                    <p>
                        Información rápida de ventas,
                        compras, inventario y cuentas.
                    </p>
                </div>

                <div class="dashboard-actions">
                    <button
                        type="button"
                        id="btnActualizar"
                    >
                        Actualizar
                    </button>

                    <small id="ultimaActualizacion">
                        Sin actualizar
                    </small>
                </div>
            </header>

            <?php if (
                isset($_GET['acceso'])
                && (string) $_GET['acceso'] === 'denegado'
            ): ?>
                <div class="dashboard-message dashboard-message--warning">
                    Tu cuenta no tiene permiso para entrar
                    a la sección solicitada.
                </div>
            <?php endif; ?>

            <div
                id="mensajeDashboard"
                class="dashboard-message dashboard-message--error"
                hidden
            ></div>



            <section class="kpi-grid">

                <article class="kpi-card"<?= $puedeDashboardVentas ? '' : ' hidden' ?>>
                    <span>Ventas de hoy</span>
                    <strong id="kpiVentasHoy">0</strong>
                    <small id="detalleVentasHoy">
                        Sin ventas confirmadas
                    </small>
                </article>

                <article class="kpi-card"<?= $puedeDashboardCompras ? '' : ' hidden' ?>>
                    <span>Compras por recibir</span>
                    <strong id="kpiCompras">0</strong>
                    <small>Pendientes o parciales</small>
                </article>

                <article class="kpi-card"<?= $puedeDashboardInventario ? '' : ' hidden' ?>>
                    <span>Inventario crítico</span>
                    <strong id="kpiInventario">0</strong>
                    <small>En mínimo o por debajo</small>
                </article>

                <article class="kpi-card"<?= $puedeDashboardCobrar ? '' : ' hidden' ?>>
                    <span>Cobros vencidos</span>
                    <strong id="kpiCobros">0</strong>
                    <small>Cuentas de clientes</small>
                </article>

                <article class="kpi-card"<?= $puedeDashboardPagar ? '' : ' hidden' ?>>
                    <span>Pagos vencidos</span>
                    <strong id="kpiPagos">0</strong>
                    <small>Cuentas a proveedores</small>
                </article>

                <article class="kpi-card kpi-card--alerts">
                    <span>Alertas sin leer</span>
                    <strong id="kpiNotificaciones">0</strong>
                    <small id="detalleAlertasKpi">Sin pendientes críticos</small>
                </article>

                <article class="kpi-card"<?= $puedeDashboardMerma ? '' : ' hidden' ?>>
                    <span>Índice de merma</span>
                    <strong id="kpiMerma">0.00%</strong>
                    <small id="detalleMerma">Costo de merma del mes</small>
                </article>

            </section>

            <section class="dashboard-trends-heading"<?= $puedeDashboardTendencias ? '' : ' hidden' ?>>
                <div>
                    <p class="dashboard-eyebrow">TENDENCIAS CLAVE</p>
                    <h2>Comportamiento comercial</h2>
                    <p>
                        <?php if ($puedeDashboardVentas && $puedeDashboardCompras): ?>
                            Ventas confirmadas y compras operativas convertidas a la moneda base.
                            La comparación ayuda a detectar cambios sin mezclar unidades de inventario.
                        <?php elseif ($puedeDashboardVentas): ?>
                            Ventas confirmadas convertidas a la moneda base para observar su comportamiento en el tiempo.
                        <?php else: ?>
                            Compras operativas convertidas a la moneda base para observar su comportamiento en el tiempo.
                        <?php endif; ?>
                    </p>
                </div>

                <span class="dashboard-auto-badge" id="estadoAutoActualizacion">
                    Auto · 30 s
                </span>
            </section>

            <section class="dashboard-chart-grid" aria-label="Tendencias comerciales"<?= $puedeDashboardTendencias ? '' : ' hidden' ?>>
                <article class="dashboard-chart-card">
                    <header class="dashboard-chart-card__head">
                        <div>
                            <span class="dashboard-chart-card__kicker">SEMANA</span>
                            <h3>Últimos 7 días</h3>
                            <p id="rangoGraficaSemanal">Cargando periodo...</p>
                        </div>
                        <span class="dashboard-chart-card__currency" id="monedaGraficaSemanal">MXN</span>
                    </header>

                    <div class="dashboard-chart-metrics">
                        <div<?= $puedeDashboardVentas ? '' : ' hidden' ?>>
                            <span>Ventas</span>
                            <strong id="totalVentasSemana">$0.00</strong>
                            <small id="variacionVentasSemana">Sin comparación</small>
                        </div>
                        <div<?= $puedeDashboardCompras ? '' : ' hidden' ?>>
                            <span>Compras</span>
                            <strong id="totalComprasSemana">$0.00</strong>
                            <small id="variacionComprasSemana">Sin comparación</small>
                        </div>
                    </div>

                    <div class="dashboard-chart-legend" aria-hidden="true">
                        <span<?= $puedeDashboardVentas ? '' : ' hidden' ?>><i class="is-sales"></i>Ventas</span>
                        <span<?= $puedeDashboardCompras ? '' : ' hidden' ?>><i class="is-purchases"></i>Compras</span>
                    </div>

                    <div class="dashboard-chart-shell" id="graficaSemanal">
                        <div class="dashboard-chart-empty">Cargando gráfica semanal...</div>
                    </div>
                </article>

                <article class="dashboard-chart-card">
                    <header class="dashboard-chart-card__head">
                        <div>
                            <span class="dashboard-chart-card__kicker">MENSUAL</span>
                            <h3>Últimos 6 meses</h3>
                            <p id="rangoGraficaMensual">Cargando periodo...</p>
                        </div>
                        <span class="dashboard-chart-card__currency" id="monedaGraficaMensual">MXN</span>
                    </header>

                    <div class="dashboard-chart-metrics">
                        <div<?= $puedeDashboardVentas ? '' : ' hidden' ?>>
                            <span>Ventas del mes</span>
                            <strong id="totalVentasMes">$0.00</strong>
                            <small id="variacionVentasMes">Sin comparación</small>
                        </div>
                        <div<?= $puedeDashboardCompras ? '' : ' hidden' ?>>
                            <span>Compras del mes</span>
                            <strong id="totalComprasMes">$0.00</strong>
                            <small id="variacionComprasMes">Sin comparación</small>
                        </div>
                    </div>

                    <div class="dashboard-chart-legend" aria-hidden="true">
                        <span<?= $puedeDashboardVentas ? '' : ' hidden' ?>><i class="is-sales"></i>Ventas</span>
                        <span<?= $puedeDashboardCompras ? '' : ' hidden' ?>><i class="is-purchases"></i>Compras</span>
                    </div>

                    <div class="dashboard-chart-shell" id="graficaMensual">
                        <div class="dashboard-chart-empty">Cargando gráfica mensual...</div>
                    </div>
                </article>
            </section>


            <section class="dashboard-alert-center" id="centroAlertas">
                <header class="dashboard-alert-center__head">
                    <div>
                        <p class="dashboard-eyebrow">PRIORIDADES OPERATIVAS</p>
                        <h2>Centro de alertas</h2>
                        <p>
                            Pendientes calculados con la información real del sistema.
                            Cada usuario ve solo lo que corresponde a sus permisos.
                        </p>
                    </div>

                    <div class="dashboard-alert-center__health" id="estadoGeneralAlertas">
                        Consultando...
                    </div>
                </header>

                <div class="dashboard-alert-summary" id="resumenAlertas">
                    <article class="dashboard-alert-summary__item is-critical">
                        <span>Críticas</span>
                        <strong id="alertasCriticas">0</strong>
                    </article>
                    <article class="dashboard-alert-summary__item is-high">
                        <span>Altas</span>
                        <strong id="alertasAltas">0</strong>
                    </article>
                    <article class="dashboard-alert-summary__item is-normal">
                        <span>Atención</span>
                        <strong id="alertasNormales">0</strong>
                    </article>
                    <article class="dashboard-alert-summary__item is-total">
                        <span>Sin leer</span>
                        <strong id="alertasTotal">0</strong>
                    </article>
                </div>

                <div class="dashboard-alert-toolbar" id="filtrosAlertas">
                    <div class="dashboard-alert-filters" role="group" aria-label="Filtrar alertas">
                        <button type="button" class="is-active" data-alert-filter="NO_LEIDAS">Sin leer</button>
                        <button type="button" data-alert-filter="TODAS">Todas</button>
                        <button type="button" data-alert-filter="CRITICA">Críticas</button>
                        <button type="button" data-alert-filter="ALTA">Altas</button>
                        <button type="button" data-alert-filter="NORMAL">Atención</button>
                        <button type="button" data-alert-filter="LEIDAS">Leídas</button>
                    </div>

                    <div class="dashboard-alert-toolbar__actions">
                        <?php if ($puedeActualizarTipoCambio): ?>
                        <button type="button" class="dashboard-alert-mark-all" id="btnActualizarTipoCambio">
                            Actualizar dólar
                        </button>
                        <?php endif; ?>

                        <button type="button" class="dashboard-alert-mark-all" id="btnMarcarTodasAlertas">
                            Marcar todas como leídas
                        </button>
                    </div>
                </div>

                <div class="dashboard-alert-list" id="listaAlertas">
                    <div class="dashboard-alert-empty">Cargando alertas operativas...</div>
                </div>
            </section>

            <section class="dashboard-two-columns"<?= ($puedeDashboardCobrar || $puedeDashboardPagar) ? '' : ' hidden' ?>>

                <article class="dashboard-panel"<?= $puedeDashboardCobrar ? '' : ' hidden' ?>>
                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Cuentas por cobrar</h2>
                            <p>Saldos pendientes por moneda</p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Moneda</th>
                                    <th>Cuentas</th>
                                    <th>Pendiente</th>
                                    <th>Vencido</th>
                                </tr>
                            </thead>

                            <tbody id="tablaCobrar">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="dashboard-panel"<?= $puedeDashboardPagar ? '' : ' hidden' ?>>
                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Cuentas por pagar</h2>
                            <p>Saldos pendientes por moneda</p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Moneda</th>
                                    <th>Cuentas</th>
                                    <th>Pendiente</th>
                                    <th>Vencido</th>
                                </tr>
                            </thead>

                            <tbody id="tablaPagar">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

            </section>

            <section class="dashboard-panel"<?= $puedeDashboardInventario ? '' : ' hidden' ?>>

                <header class="dashboard-panel__head">
                    <div>
                        <h2>Inventario crítico</h2>
                        <p>
                            Primeros 10 productos
                            que requieren atención
                        </p>
                    </div>
                </header>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Tipo</th>
                                <th>Almacén</th>
                                <th>Físico</th>
                                <th>Reservado</th>
                                <th>Disponible</th>
                                <th>Mínimo</th>
                            </tr>
                        </thead>

                        <tbody id="tablaInventario">
                            <tr>
                                <td colspan="8">
                                    Cargando...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-two-columns"<?= ($puedeDashboardVentas || $puedeDashboardAuditoria) ? '' : ' hidden' ?>>

                <article class="dashboard-panel"<?= $puedeDashboardVentas ? '' : ' hidden' ?>>

                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Top 5 del mes</h2>
                            <p>
                                Productos con mayor
                                frecuencia de venta
                            </p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Ventas</th>
                                    <th>Cantidad base</th>
                                </tr>
                            </thead>

                            <tbody id="tablaTopProductos">
                                <tr>
                                    <td colspan="3">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="dashboard-panel"<?= $puedeDashboardAuditoria ? '' : ' hidden' ?>>

                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Movimientos recientes</h2>
                            <p>
                                Últimas acciones
                                registradas en auditoría
                            </p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Módulo</th>
                                </tr>
                            </thead>

                            <tbody id="tablaMovimientos">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

            </section>

            <section class="dashboard-panel"<?= $puedeDashboardTopClientes ? '' : ' hidden' ?>>
                <header class="dashboard-panel__head">
                    <div>
                        <h2>Top 5 clientes del mes</h2>
                        <p>Clientes con mayor volumen de compra normalizado a moneda base</p>
                    </div>
                </header>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Nivel</th>
                                <th>Ventas</th>
                                <th>Total base</th>
                                <th>Descuento prom.</th>
                            </tr>
                        </thead>
                        <tbody id="tablaTopClientes">
                            <tr>
                                <td colspan="5">Cargando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
(function () {
    'use strict';

    const endpoint =
        '?dashboard_api=1&accion=RESUMEN';

    const permisosDashboard = <?= json_encode([
        'ventas' => $puedeDashboardVentas,
        'compras' => $puedeDashboardCompras,
        'inventario' => $puedeDashboardInventario,
        'merma' => $puedeDashboardMerma,
        'cuentas_cobrar' => $puedeDashboardCobrar,
        'cuentas_pagar' => $puedeDashboardPagar,
        'auditoria' => $puedeDashboardAuditoria,
        'top_clientes' => $puedeDashboardTopClientes,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    const alertasEndpoint = <?= json_encode(si_url('funciones/alertas_funciones.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrfAlertas = <?= json_encode(si_token_csrf(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    let estadoAlertas = { alertas: [], total: 0, total_sin_leer: 0, prioridades: {}, prioridades_sin_leer: {} };
    let filtroAlertas = 'NO_LEIDAS';

    const AUTO_REFRESH_MS = 30000;
    let cargaDashboardEnCurso = false;
    let ultimaCargaExitosaMs = 0;
    let cargaInicialCompleta = false;

    const botonActualizar =
        document.getElementById('btnActualizar');

    const mensaje =
        document.getElementById('mensajeDashboard');

    const ultimaActualizacion =
        document.getElementById('ultimaActualizacion');

    function numero(valor, decimales) {
        const cantidadDecimales =
            typeof decimales === 'number'
                ? decimales
                : 0;

        const n = Number(valor || 0);

        return new Intl.NumberFormat(
            'es-MX',
            {
                minimumFractionDigits:
                    cantidadDecimales,

                maximumFractionDigits:
                    cantidadDecimales
            }
        ).format(
            Number.isFinite(n) ? n : 0
        );
    }

    function dinero(valor, moneda) {
        const n = Number(valor || 0);
        const codigo = moneda || 'MXN';

        try {
            return new Intl.NumberFormat(
                'es-MX',
                {
                    style: 'currency',
                    currency: codigo,
                    maximumFractionDigits: 2
                }
            ).format(
                Number.isFinite(n) ? n : 0
            );
        } catch (error) {
            return numero(n, 2) + ' ' + codigo;
        }
    }

    function escapeHtml(valor) {
        return String(
            valor == null ? '' : valor
        )
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function filaVacia(
        tbody,
        columnas,
        texto
    ) {
        tbody.innerHTML =
            '<tr>'
            + '<td colspan="'
            + columnas
            + '" class="empty-cell">'
            + escapeHtml(texto)
            + '</td>'
            + '</tr>';
    }


    function dineroCompacto(valor, moneda) {
        const n = Number(valor || 0);
        const codigo = moneda || 'MXN';

        try {
            return new Intl.NumberFormat(
                'es-MX',
                {
                    style: 'currency',
                    currency: codigo,
                    notation: 'compact',
                    maximumFractionDigits: 1
                }
            ).format(Number.isFinite(n) ? n : 0);
        } catch (error) {
            return numero(n, 0) + ' ' + codigo;
        }
    }

    function textoVariacion(valor, etiqueta) {
        if (valor === null || typeof valor === 'undefined') {
            return 'Sin base de comparación';
        }

        const n = Number(valor);
        if (!Number.isFinite(n)) {
            return 'Sin base de comparación';
        }

        if (Math.abs(n) < 0.05) {
            return 'Sin cambio vs ' + etiqueta;
        }

        return (n > 0 ? '↑ ' : '↓ ')
            + numero(Math.abs(n), 1)
            + '% vs '
            + etiqueta;
    }

    function renderVariacion(id, valor, etiqueta) {
        const elemento = document.getElementById(id);
        if (!elemento) return;

        const n = valor === null || typeof valor === 'undefined'
            ? null
            : Number(valor);

        elemento.className = '';
        elemento.textContent = textoVariacion(valor, etiqueta);

        if (n === null || !Number.isFinite(n) || Math.abs(n) < 0.05) {
            elemento.classList.add('is-neutral');
        } else {
            elemento.classList.add(n > 0 ? 'is-up' : 'is-down');
        }
    }

    function renderGraficaLineas(id, filas, moneda) {
        const contenedor = document.getElementById(id);
        if (!contenedor) return;

        const datos = Array.isArray(filas) ? filas : [];
        if (!datos.length) {
            contenedor.innerHTML = '<div class="dashboard-chart-empty">Sin información para este periodo.</div>';
            return;
        }

        const width = 760;
        const height = 270;
        const pad = { top: 18, right: 20, bottom: 46, left: 72 };
        const plotW = width - pad.left - pad.right;
        const plotH = height - pad.top - pad.bottom;

        const mostrarVentas = permisosDashboard.ventas === true;
        const mostrarCompras = permisosDashboard.compras === true;

        if (!mostrarVentas && !mostrarCompras) {
            contenedor.innerHTML = '<div class="dashboard-chart-empty">Sin información autorizada para este periodo.</div>';
            return;
        }

        const valores = [];
        datos.forEach(function (item) {
            if (mostrarVentas) valores.push(Number(item.ventas || 0));
            if (mostrarCompras) valores.push(Number(item.compras || 0));
        });

        let maximo = Math.max.apply(null, valores.concat([0]));
        if (!Number.isFinite(maximo) || maximo <= 0) {
            maximo = 1;
        }
        maximo *= 1.12;

        const x = function (indice) {
            if (datos.length <= 1) return pad.left + (plotW / 2);
            return pad.left + (indice * plotW / (datos.length - 1));
        };

        const y = function (valor) {
            const n = Math.max(0, Number(valor || 0));
            return pad.top + plotH - (n / maximo * plotH);
        };

        const puntos = function (campo) {
            return datos.map(function (item, indice) {
                return x(indice).toFixed(2) + ',' + y(item[campo]).toFixed(2);
            }).join(' ');
        };

        let svg = '';
        const etiquetaGrafica = mostrarVentas && mostrarCompras
            ? 'Ventas y compras del periodo'
            : (mostrarVentas ? 'Ventas del periodo' : 'Compras del periodo');
        svg += '<svg class="dashboard-chart-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + etiquetaGrafica + '">';

        for (let i = 0; i <= 4; i += 1) {
            const valor = maximo * (4 - i) / 4;
            const gy = pad.top + (plotH * i / 4);
            svg += '<line class="dashboard-chart-gridline" x1="' + pad.left + '" y1="' + gy.toFixed(2) + '" x2="' + (width - pad.right) + '" y2="' + gy.toFixed(2) + '"></line>';
            svg += '<text class="dashboard-chart-axis-label dashboard-chart-axis-label--y" x="' + (pad.left - 10) + '" y="' + (gy + 4).toFixed(2) + '">' + escapeHtml(dineroCompacto(valor, moneda)) + '</text>';
        }

        datos.forEach(function (item, indice) {
            svg += '<text class="dashboard-chart-axis-label dashboard-chart-axis-label--x" x="' + x(indice).toFixed(2) + '" y="' + (height - 15) + '">' + escapeHtml(item.etiqueta || '') + '</text>';
        });

        if (mostrarVentas) {
            svg += '<polyline class="dashboard-chart-line dashboard-chart-line--sales" points="' + puntos('ventas') + '"></polyline>';
        }
        if (mostrarCompras) {
            svg += '<polyline class="dashboard-chart-line dashboard-chart-line--purchases" points="' + puntos('compras') + '"></polyline>';
        }

        datos.forEach(function (item, indice) {
            const px = x(indice).toFixed(2);
            const etiqueta = escapeHtml(item.etiqueta || '');

            if (mostrarVentas) {
                const ventasY = y(item.ventas).toFixed(2);
                const ventaTexto = escapeHtml(dinero(item.ventas || 0, moneda));
                svg += '<circle class="dashboard-chart-point dashboard-chart-point--sales" cx="' + px + '" cy="' + ventasY + '" r="4.2"><title>' + etiqueta + ' · Ventas: ' + ventaTexto + '</title></circle>';
            }

            if (mostrarCompras) {
                const comprasY = y(item.compras).toFixed(2);
                const compraTexto = escapeHtml(dinero(item.compras || 0, moneda));
                svg += '<circle class="dashboard-chart-point dashboard-chart-point--purchases" cx="' + px + '" cy="' + comprasY + '" r="4.2"><title>' + etiqueta + ' · Compras: ' + compraTexto + '</title></circle>';
            }
        });

        svg += '</svg>';
        contenedor.innerHTML = svg;
    }

    function renderGraficas(datos) {
        const semanal = datos.grafica_semanal || {};
        const mensual = datos.grafica_mensual || {};
        const monedaSemana = semanal.moneda_base || 'MXN';
        const monedaMes = mensual.moneda_base || monedaSemana;
        const totalesSemana = semanal.totales || {};
        const totalesMes = mensual.totales || {};

        document.getElementById('monedaGraficaSemanal').textContent = monedaSemana;
        document.getElementById('monedaGraficaMensual').textContent = monedaMes;
        document.getElementById('rangoGraficaSemanal').textContent = semanal.periodo || 'Últimos 7 días';
        document.getElementById('rangoGraficaMensual').textContent = mensual.periodo || 'Últimos 6 meses';

        document.getElementById('totalVentasSemana').textContent = dinero(totalesSemana.ventas || 0, monedaSemana);
        document.getElementById('totalComprasSemana').textContent = dinero(totalesSemana.compras || 0, monedaSemana);
        document.getElementById('totalVentasMes').textContent = dinero(totalesMes.ventas_actual || 0, monedaMes);
        document.getElementById('totalComprasMes').textContent = dinero(totalesMes.compras_actual || 0, monedaMes);

        renderVariacion('variacionVentasSemana', totalesSemana.variacion_ventas_pct, '7 días anteriores');
        renderVariacion('variacionComprasSemana', totalesSemana.variacion_compras_pct, '7 días anteriores');
        renderVariacion('variacionVentasMes', totalesMes.variacion_ventas_pct, 'mes anterior');
        renderVariacion('variacionComprasMes', totalesMes.variacion_compras_pct, 'mes anterior');

        renderGraficaLineas('graficaSemanal', semanal.serie || [], monedaSemana);
        renderGraficaLineas('graficaMensual', mensual.serie || [], monedaMes);
    }

    function renderKpis(datos) {
        const kpis = datos.kpis || {};

        document.getElementById(
            'kpiVentasHoy'
        ).textContent =
            numero(kpis.ventas_hoy);

        document.getElementById(
            'kpiCompras'
        ).textContent =
            numero(kpis.compras_por_recibir);

        document.getElementById(
            'kpiInventario'
        ).textContent =
            numero(kpis.stock_critico);

        document.getElementById(
            'kpiCobros'
        ).textContent =
            numero(kpis.cobros_vencidos);

        document.getElementById(
            'kpiPagos'
        ).textContent =
            numero(kpis.pagos_vencidos);

        document.getElementById(
            'kpiNotificaciones'
        ).textContent =
            numero(kpis.alertas_activas);

        const detalleAlertas = document.getElementById('detalleAlertasKpi');
        const criticas = Number(kpis.alertas_criticas || 0);
        const altas = Number(kpis.alertas_altas || 0);
        detalleAlertas.textContent = criticas > 0
            ? numero(criticas) + (criticas === 1 ? ' crítica' : ' críticas')
            : (altas > 0
                ? numero(altas) + (altas === 1 ? ' prioridad alta' : ' prioridades altas')
                : 'Sin pendientes críticos');

        const merma = datos.merma_mes || {};
        document.getElementById('kpiMerma').textContent =
            numero(merma.indice_pct || 0, 2) + '%';
        document.getElementById('detalleMerma').textContent =
            'Costo base: ' + dinero(merma.costo_merma_base || 0, 'MXN');

        const ventas =
            datos.ventas_hoy_monedas || [];

        const detalle =
            document.getElementById(
                'detalleVentasHoy'
            );

        detalle.textContent =
            ventas.length
                ? ventas.map(
                    function (item) {
                        return item.codigo
                            + ': '
                            + dinero(
                                item.importe,
                                item.codigo
                            );
                    }
                ).join(' · ')
                : 'Sin ventas confirmadas';
    }

    function clasePrioridadAlerta(prioridad) {
        const valor = String(prioridad || 'NORMAL').toUpperCase();
        if (valor === 'CRITICA') return 'is-critical';
        if (valor === 'ALTA') return 'is-high';
        if (valor === 'BAJA') return 'is-low';
        return 'is-normal';
    }

    function textoPrioridadAlerta(prioridad) {
        const valor = String(prioridad || 'NORMAL').toUpperCase();
        if (valor === 'CRITICA') return 'Crítica';
        if (valor === 'ALTA') return 'Alta';
        if (valor === 'BAJA') return 'Baja';
        return 'Atención';
    }

    function alertasFiltradas() {
        const items = Array.isArray(estadoAlertas.alertas) ? estadoAlertas.alertas : [];

        return items.filter(function (item) {
            const prioridad = String(item.prioridad || 'NORMAL').toUpperCase();
            const leida = Boolean(item.leida);

            if (filtroAlertas === 'TODAS') return true;
            if (filtroAlertas === 'NO_LEIDAS') return !leida;
            if (filtroAlertas === 'LEIDAS') return leida;
            if (filtroAlertas === 'NORMAL') return !leida && (prioridad === 'NORMAL' || prioridad === 'BAJA');
            return !leida && prioridad === filtroAlertas;
        });
    }

    function renderListaAlertas() {
        const lista = document.getElementById('listaAlertas');
        const items = alertasFiltradas();
        const detallesAbiertos = new Set(
            Array.from(lista.querySelectorAll('[data-alert-key] details[open]')).map(function (details) {
                const fila = details.closest('[data-alert-key]');
                return fila ? String(fila.dataset.alertKey || '') : '';
            }).filter(Boolean)
        );

        document.querySelectorAll('[data-alert-filter]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.alertFilter === filtroAlertas);
        });

        const marcarTodas = document.getElementById('btnMarcarTodasAlertas');
        if (marcarTodas) {
            marcarTodas.disabled = Number(estadoAlertas.total_sin_leer || 0) <= 0;
        }

        if (!items.length) {
            const mensajes = {
                NO_LEIDAS: ['Sin alertas nuevas', 'Ya revisaste todas las alertas activas.'],
                LEIDAS: ['Todavía no hay alertas leídas', 'Cuando marques una alerta como leída aparecerá aquí durante su periodo de reconocimiento.'],
                CRITICA: ['Sin alertas críticas nuevas', 'No hay prioridades críticas sin leer.'],
                ALTA: ['Sin alertas altas nuevas', 'No hay prioridades altas sin leer.'],
                NORMAL: ['Sin alertas de atención nuevas', 'No hay alertas de seguimiento pendientes de leer.'],
                TODAS: ['Todo en orden', 'No hay alertas activas para tu perfil.']
            };
            const texto = mensajes[filtroAlertas] || mensajes.TODAS;
            lista.innerHTML = '<div class="dashboard-alert-empty is-ok"><strong>' + escapeHtml(texto[0]) + '</strong><span>' + escapeHtml(texto[1]) + '</span></div>';
            return;
        }

        lista.innerHTML = items.map(function (item) {
            const detalles = Array.isArray(item.detalles) ? item.detalles : [];
            const leida = Boolean(item.leida);
            const claveAlerta = String(item.clave || '');
            const detalleHtml = detalles.length
                ? '<details class="dashboard-alert-details"' + (detallesAbiertos.has(claveAlerta) ? ' open' : '') + '><summary>Ver ' + numero(detalles.length) + (detalles.length === 1 ? ' detalle' : ' detalles') + '</summary>'
                    + '<div class="dashboard-alert-details__body">' + detalles.map(function (detalle) {
                        return '<div class="dashboard-alert-detail">'
                            + '<div><strong>' + escapeHtml(detalle.principal || '') + '</strong>'
                            + (detalle.secundario ? '<span>' + escapeHtml(detalle.secundario) + '</span>' : '') + '</div>'
                            + (detalle.meta ? '<small>' + escapeHtml(detalle.meta) + '</small>' : '')
                            + '</div>';
                    }).join('') + '</div></details>'
                : '';

            return '<article class="dashboard-alert-row ' + clasePrioridadAlerta(item.prioridad) + (leida ? ' is-read' : '') + '" data-alert-key="' + escapeHtml(item.clave || '') + '">'
                + '<div class="dashboard-alert-row__indicator" aria-hidden="true"></div>'
                + '<div class="dashboard-alert-row__main">'
                + '<div class="dashboard-alert-row__meta">'
                + '<span class="dashboard-alert-card__priority">' + escapeHtml(textoPrioridadAlerta(item.prioridad)) + '</span>'
                + '<span class="dashboard-alert-card__category">' + escapeHtml(item.categoria || '') + '</span>'
                + (leida ? '<span class="dashboard-alert-read-badge">Leída</span>' : '<span class="dashboard-alert-new-badge">Nueva</span>')
                + '</div>'
                + '<div class="dashboard-alert-row__title"><h3>' + escapeHtml(item.titulo || 'Alerta') + '</h3>'
                + '<strong class="dashboard-alert-card__count">' + numero(item.conteo || 0) + '</strong></div>'
                + '<p>' + escapeHtml(item.mensaje || '') + '</p>'
                + detalleHtml
                + '</div>'
                + '<div class="dashboard-alert-row__actions">'
                + '<a class="dashboard-alert-card__action" href="' + escapeHtml(item.href || '#') + '">' + escapeHtml(item.accion || 'Revisar') + '</a>'
                + (!leida ? '<button type="button" class="dashboard-alert-read" data-alert-read="' + escapeHtml(item.clave || '') + '">Marcar como leída</button>' : '')
                + '</div>'
                + '</article>';
        }).join('');
    }

    function renderAlertas(datos) {
        estadoAlertas = datos || {};
        const prioridades = estadoAlertas.prioridades_sin_leer || {};
        const alertas = Array.isArray(estadoAlertas.alertas) ? estadoAlertas.alertas : [];
        const totalActivo = Number(estadoAlertas.total || 0);
        const totalSinLeer = Number(estadoAlertas.total_sin_leer || 0);
        const criticas = Number(prioridades.CRITICA || 0);
        const altas = Number(prioridades.ALTA || 0);
        const normales = Number(prioridades.NORMAL || 0) + Number(prioridades.BAJA || 0);

        document.getElementById('alertasCriticas').textContent = numero(criticas);
        document.getElementById('alertasAltas').textContent = numero(altas);
        document.getElementById('alertasNormales').textContent = numero(normales);
        document.getElementById('alertasTotal').textContent = numero(totalSinLeer);

        const estado = document.getElementById('estadoGeneralAlertas');

        if (totalActivo <= 0 || !alertas.length) {
            estado.className = 'dashboard-alert-center__health is-ok';
            estado.textContent = 'Sin pendientes activos';
            renderListaAlertas();
            return;
        }

        if (totalSinLeer <= 0) {
            estado.className = 'dashboard-alert-center__health is-ok';
            estado.textContent = numero(totalActivo) + ' activas · todas revisadas';
        } else if (criticas > 0) {
            estado.className = 'dashboard-alert-center__health is-critical';
            estado.textContent = numero(totalSinLeer) + ' sin leer · atención inmediata';
        } else if (altas > 0) {
            estado.className = 'dashboard-alert-center__health is-high';
            estado.textContent = numero(totalSinLeer) + ' sin leer · prioridad alta';
        } else {
            estado.className = 'dashboard-alert-center__health is-normal';
            estado.textContent = numero(totalSinLeer) + ' sin leer · seguimiento';
        }

        renderListaAlertas();
    }

    async function actualizarLecturaAlertas(accion, clave) {
        const body = new URLSearchParams();
        body.set('accion', accion);
        body.set('csrf_token', csrfAlertas);
        if (clave) body.set('clave', clave);

        const response = await fetch(alertasEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        });

        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (!response.ok || data.success !== true) {
            throw new Error(data.mensaje || 'No fue posible actualizar la alerta.');
        }

        renderAlertas(data.alertas_operativas || {});
        window.dispatchEvent(new CustomEvent('si:alertas-actualizadas'));
    }

    document.getElementById('btnActualizarTipoCambio')?.addEventListener('click', async function () {
        const button = this;
        if (button.disabled) return;

        button.disabled = true;
        const textoOriginal = button.textContent;
        button.textContent = 'Consultando Banxico...';
        mensaje.hidden = true;

        try {
            const body = new URLSearchParams();
            body.set('accion', 'ACTUALIZAR_TIPO_CAMBIO');
            body.set('csrf_token', csrfAlertas);

            const response = await fetch(alertasEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            if (!response.ok || data.success !== true) {
                throw new Error(data.mensaje || 'No fue posible actualizar el tipo de cambio.');
            }

            const valor = Number(data.tipo_cambio || 0);
            const fecha = String(data.fecha || '');
            const fuente = String(data.fuente || 'Banco de México');

            mensaje.className = 'dashboard-message dashboard-message--success';
            mensaje.textContent = valor > 0
                ? 'Tipo de cambio actualizado: 1 USD = $' + numero(valor, 4) + ' MXN'
                    + (fecha ? ' · FIX ' + fecha : '')
                    + (fuente ? ' · ' + fuente : '')
                : 'Tipo de cambio actualizado correctamente.';
            mensaje.hidden = false;

            renderAlertas(data.alertas_operativas || {});
            window.dispatchEvent(new CustomEvent('si:alertas-actualizadas'));
        } catch (error) {
            mensaje.className = 'dashboard-message dashboard-message--error';
            mensaje.textContent = error.message || 'No fue posible actualizar el tipo de cambio.';
            mensaje.hidden = false;
        } finally {
            button.disabled = false;
            button.textContent = textoOriginal;
        }
    });

    document.getElementById('filtrosAlertas')?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-alert-filter]');
        if (!button) return;
        filtroAlertas = String(button.dataset.alertFilter || 'NO_LEIDAS');
        renderListaAlertas();
    });

    document.getElementById('listaAlertas')?.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-alert-read]');
        if (!button || button.disabled) return;

        button.disabled = true;
        const textoOriginal = button.textContent;
        button.textContent = 'Marcando...';
        try {
            await actualizarLecturaAlertas('MARCAR_LEIDA', String(button.dataset.alertRead || ''));
        } catch (error) {
            button.disabled = false;
            button.textContent = textoOriginal;
            mensaje.textContent = error.message || 'No fue posible actualizar la alerta.';
            mensaje.hidden = false;
        }
    });

    document.getElementById('btnMarcarTodasAlertas')?.addEventListener('click', async function () {
        const button = this;
        if (button.disabled) return;
        button.disabled = true;
        const textoOriginal = button.textContent;
        button.textContent = 'Marcando...';
        try {
            await actualizarLecturaAlertas('MARCAR_TODAS_LEIDAS');
            button.textContent = textoOriginal;
        } catch (error) {
            button.disabled = false;
            button.textContent = textoOriginal;
            mensaje.textContent = error.message || 'No fue posible actualizar la alerta.';
            mensaje.hidden = false;
        }
    });

    function renderCuentas(
        id,
        filas
    ) {
        const tbody =
            document.getElementById(id);

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                4,
                'Sin cuentas pendientes.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr>'
                    + '<td><strong>'
                    + escapeHtml(item.codigo)
                    + '</strong></td>'
                    + '<td>'
                    + numero(item.cuentas)
                    + '</td>'
                    + '<td>'
                    + dinero(
                        item.saldo_pendiente,
                        item.codigo
                    )
                    + '</td>'
                    + '<td>'
                    + dinero(
                        item.saldo_vencido,
                        item.codigo
                    )
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderInventario(filas) {
        const tbody =
            document.getElementById(
                'tablaInventario'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                8,
                'No hay productos en nivel crítico.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                const tipo =
                    item.tipo === 'MATERIA_PRIMA'
                        ? 'Materia prima'
                        : 'Producto terminado';

                return ''
                    + '<tr>'
                    + '<td>'
                    + escapeHtml(item.sku)
                    + '</td>'
                    + '<td><strong>'
                    + escapeHtml(item.producto)
                    + '</strong></td>'
                    + '<td>'
                    + escapeHtml(tipo)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.almacen)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.existencia_fisica,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_reservada,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_disponible,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.stock_minimo,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderTopProductos(filas) {
        const tbody =
            document.getElementById(
                'tablaTopProductos'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                3,
                'Todavía no hay ventas confirmadas este mes.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr>'
                    + '<td>'
                    + '<strong>'
                    + escapeHtml(item.producto)
                    + '</strong>'
                    + '<small class="cell-secondary">'
                    + escapeHtml(item.sku)
                    + '</small>'
                    + '</td>'
                    + '<td>'
                    + numero(item.operaciones)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_base,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderTopClientes(filas) {
        const tbody = document.getElementById('tablaTopClientes');

        if (!filas || !filas.length) {
            filaVacia(tbody, 5, 'Todavía no hay ventas de clientes este mes.');
            return;
        }

        tbody.innerHTML = filas.map(function (item) {
            return ''
                + '<tr>'
                + '<td><strong>' + escapeHtml(item.cliente) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(item.codigo || '') + '</small></td>'
                + '<td>' + escapeHtml(item.nivel || 'General') + '</td>'
                + '<td>' + numero(item.operaciones) + '</td>'
                + '<td>' + dinero(item.total_base, 'MXN') + '</td>'
                + '<td>' + numero(item.descuento_promedio_pct, 2) + '%</td>'
                + '</tr>';
        }).join('');
    }

    function renderMovimientos(filas) {
        const tbody =
            document.getElementById(
                'tablaMovimientos'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                4,
                'Todavía no hay movimientos registrados.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr title="'
                    + escapeHtml(
                        item.descripcion || ''
                    )
                    + '">'
                    + '<td>'
                    + escapeHtml(item.fecha_hora)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.usuario)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.accion)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.modulo)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    async function cargarDashboard(opciones) {
        const config = opciones || {};
        const silencioso = config.silencioso === true;

        if (cargaDashboardEnCurso) {
            return;
        }

        cargaDashboardEnCurso = true;

        if (!silencioso) {
            mensaje.hidden = true;
            botonActualizar.disabled = true;
            botonActualizar.textContent = 'Actualizando...';
        }

        try {
            const separador = endpoint.indexOf('?') >= 0 ? '&' : '?';
            const respuesta = await fetch(
                endpoint + separador + '_=' + Date.now(),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const texto = await respuesta.text();
            let datos;

            try {
                datos = JSON.parse(texto);
            } catch (error) {
                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            if (!respuesta.ok || datos.success !== true) {
                if (datos.sesion_expirada && datos.redirect) {
                    window.location.href = datos.redirect;
                    return;
                }

                throw new Error(datos.mensaje || 'No fue posible cargar el dashboard.');
            }

            renderKpis(datos);
            renderGraficas(datos);
            renderAlertas(datos.alertas_operativas || {});

            renderCuentas('tablaCobrar', datos.resumen_cobrar);
            renderCuentas('tablaPagar', datos.resumen_pagar);
            renderInventario(datos.inventario_critico);
            renderTopProductos(datos.top_productos);
            renderTopClientes(datos.top_clientes || []);
            renderMovimientos(datos.movimientos_recientes);

            ultimaCargaExitosaMs = Date.now();
            cargaInicialCompleta = true;
            ultimaActualizacion.textContent = 'Actualizado: '
                + (datos.fecha_servidor || '')
                + ' · automático cada 30 s';

            const estadoAuto = document.getElementById('estadoAutoActualizacion');
            if (estadoAuto) {
                estadoAuto.textContent = 'Auto · 30 s';
                estadoAuto.classList.remove('is-warning');
            }

            if (silencioso) {
                mensaje.hidden = true;
            }

            window.dispatchEvent(new CustomEvent('si:alertas-actualizadas'));

        } catch (error) {
            const textoError = error.message || 'Ocurrió un error inesperado.';

            if (!silencioso || !cargaInicialCompleta) {
                mensaje.textContent = textoError;
                mensaje.hidden = false;
            }

            const estadoAuto = document.getElementById('estadoAutoActualizacion');
            if (estadoAuto) {
                estadoAuto.textContent = 'Auto · reintentando';
                estadoAuto.classList.add('is-warning');
            }

            if (silencioso && cargaInicialCompleta) {
                ultimaActualizacion.textContent = 'Última actualización conservada · se reintentará automáticamente';
            }

        } finally {
            cargaDashboardEnCurso = false;

            if (!silencioso) {
                botonActualizar.disabled = false;
                botonActualizar.textContent = 'Actualizar';
            }
        }
    }

    botonActualizar.addEventListener('click', function () {
        cargarDashboard({ silencioso: false });
    });

    window.setInterval(function () {
        if (document.hidden) {
            return;
        }
        cargarDashboard({ silencioso: true });
    }, AUTO_REFRESH_MS);

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            return;
        }

        if (!ultimaCargaExitosaMs || (Date.now() - ultimaCargaExitosaMs) >= AUTO_REFRESH_MS) {
            cargarDashboard({ silencioso: true });
        }
    });

    cargarDashboard({ silencioso: false });
})();
</script>

</body>
</html>
