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

$fechaActual = new DateTimeImmutable('now');
$diasDashboard = [
    'domingo',
    'lunes',
    'martes',
    'miércoles',
    'jueves',
    'viernes',
    'sábado',
];
$mesesDashboard = [
    1 => 'enero',
    2 => 'febrero',
    3 => 'marzo',
    4 => 'abril',
    5 => 'mayo',
    6 => 'junio',
    7 => 'julio',
    8 => 'agosto',
    9 => 'septiembre',
    10 => 'octubre',
    11 => 'noviembre',
    12 => 'diciembre',
];
$fechaDashboard = sprintf(
    '%s, %d de %s de %s',
    $diasDashboard[(int) $fechaActual->format('w')],
    (int) $fechaActual->format('j'),
    $mesesDashboard[(int) $fechaActual->format('n')],
    $fechaActual->format('Y')
);

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
<body class="dashboard-page">

<div class="app-shell">

    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">

        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content dashboard-page-content">

            <header class="dashboard-heading">
                <div class="dashboard-heading__copy">
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

                    <p class="dashboard-heading__description">
                        Información rápida de ventas,
                        compras, inventario y cuentas.
                    </p>

                    <div class="dashboard-heading__meta">
                        <span>
                            <?= si_escapar(ucfirst($fechaDashboard)) ?>
                        </span>
                        <span class="dashboard-heading__status">
                            <i aria-hidden="true"></i>
                            Panel operativo
                        </span>
                    </div>
                </div>

                <div class="dashboard-actions">
                    <button
                        type="button"
                        id="btnActualizar"
                        class="dashboard-refresh-button"
                    >
                        <svg class="dashboard-refresh-button__icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 6v5h-5"></path>
                            <path d="M4 18v-5h5"></path>
                            <path d="M6.1 9a7 7 0 0 1 11.5-2.6L20 11"></path>
                            <path d="m4 13 2.4 4.6A7 7 0 0 0 17.9 15"></path>
                        </svg>
                        <span id="btnActualizarTexto">Actualizar</span>
                    </button>

                    <small id="ultimaActualizacion">
                        Sin actualizar
                    </small>
                </div>
            </header>

            <div class="dashboard-workspace">

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

                <article class="kpi-card kpi-card--primary"<?= $puedeDashboardVentas ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Ventas de hoy</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"></path></svg></span>
                    </div>
                    <strong id="kpiVentasHoy">0</strong>
                    <small id="detalleVentasHoy">
                        Sin ventas confirmadas
                    </small>
                </article>

                <article class="kpi-card kpi-card--medium"<?= $puedeDashboardCompras ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Compras por recibir</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6h18l-2 9H6L3 3H1m6 16a1 1 0 1 0 0 .01M18 19a1 1 0 1 0 0 .01"></path></svg></span>
                    </div>
                    <strong id="kpiCompras">0</strong>
                    <small>Pendientes o parciales</small>
                </article>

                <article class="kpi-card kpi-card--soft"<?= $puedeDashboardInventario ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Inventario crítico</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Zm9 10-9 5-9-5m18 5-9 5-9-5"></path></svg></span>
                    </div>
                    <strong id="kpiInventario">0</strong>
                    <small>En mínimo o por debajo</small>
                </article>

                <article class="kpi-card kpi-card--primary"<?= $puedeDashboardCobrar ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Cobros vencidos</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
                    </div>
                    <strong id="kpiCobros">0</strong>
                    <small>Cuentas de clientes</small>
                </article>

                <article class="kpi-card kpi-card--medium"<?= $puedeDashboardPagar ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Pagos vencidos</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 10h18m-5 5h2"></path></svg></span>
                    </div>
                    <strong id="kpiPagos">0</strong>
                    <small>Cuentas a proveedores</small>
                </article>

                <article class="kpi-card kpi-card--soft"<?= $puedeDashboardMerma ? '' : ' hidden' ?>>
                    <div class="kpi-card__top">
                        <span>Índice de merma</span>
                        <span class="kpi-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3c4 4 7 7 7 11a7 7 0 0 1-14 0c0-4 3-7 7-11Z"></path><path d="M9 16c1 1 2 1 3 1"></path></svg></span>
                    </div>
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
                <article class="dashboard-chart-card dashboard-chart-card--sales"<?= $puedeDashboardVentas ? '' : ' hidden' ?>>
                    <header class="dashboard-chart-card__head">
                        <div class="dashboard-chart-card__copy">
                            <span class="dashboard-chart-card__kicker">VENTAS</span>
                            <h3 id="tituloGraficaVentas">Ventas · Últimos 7 días</h3>
                            <p id="rangoGraficaVentas">Cargando periodo...</p>
                        </div>

                        <div class="dashboard-chart-card__controls">
                            <span class="dashboard-chart-card__currency" id="monedaGraficaVentas">MXN</span>
                            <div class="dashboard-chart-period-switch" role="group" aria-label="Periodo de la gráfica de ventas">
                                <button type="button" class="is-active" data-chart-kind="ventas" data-chart-period="7d" aria-pressed="true">7 días</button>
                                <button type="button" data-chart-kind="ventas" data-chart-period="6m" aria-pressed="false">6 meses</button>
                            </div>
                        </div>
                    </header>

                    <div class="dashboard-chart-overview">
                        <div class="dashboard-chart-overview__primary">
                            <span>Total del periodo</span>
                            <strong id="totalGraficaVentas">$0.00</strong>
                            <small id="variacionGraficaVentas">Sin comparación</small>
                        </div>
                        <div class="dashboard-chart-overview__stat">
                            <span>Operaciones</span>
                            <strong id="operacionesGraficaVentas">0</strong>
                            <small id="operacionesTextoGraficaVentas">En el periodo visible</small>
                        </div>
                        <div class="dashboard-chart-overview__stat">
                            <span>Pico del periodo</span>
                            <strong id="picoGraficaVentas">$0.00</strong>
                            <small id="picoEtiquetaGraficaVentas">Sin datos</small>
                        </div>
                    </div>

                    <div class="dashboard-chart-shell" id="graficaVentas">
                        <div class="dashboard-chart-empty">Cargando ventas...</div>
                    </div>

                    <div class="dashboard-chart-color-guide" aria-label="Guía de color de la gráfica">
                        <span><i class="is-rise"></i>Sube</span>
                        <span><i class="is-peak"></i>Pico</span>
                        <span><i class="is-down"></i>Baja</span>
                    </div>
                </article>

                <article class="dashboard-chart-card dashboard-chart-card--purchases"<?= $puedeDashboardCompras ? '' : ' hidden' ?>>
                    <header class="dashboard-chart-card__head">
                        <div class="dashboard-chart-card__copy">
                            <span class="dashboard-chart-card__kicker">COMPRAS</span>
                            <h3 id="tituloGraficaCompras">Compras · Últimos 7 días</h3>
                            <p id="rangoGraficaCompras">Cargando periodo...</p>
                        </div>

                        <div class="dashboard-chart-card__controls">
                            <span class="dashboard-chart-card__currency" id="monedaGraficaCompras">MXN</span>
                            <div class="dashboard-chart-period-switch" role="group" aria-label="Periodo de la gráfica de compras">
                                <button type="button" class="is-active" data-chart-kind="compras" data-chart-period="7d" aria-pressed="true">7 días</button>
                                <button type="button" data-chart-kind="compras" data-chart-period="6m" aria-pressed="false">6 meses</button>
                            </div>
                        </div>
                    </header>

                    <div class="dashboard-chart-overview">
                        <div class="dashboard-chart-overview__primary">
                            <span>Total del periodo</span>
                            <strong id="totalGraficaCompras">$0.00</strong>
                            <small id="variacionGraficaCompras">Sin comparación</small>
                        </div>
                        <div class="dashboard-chart-overview__stat">
                            <span>Operaciones</span>
                            <strong id="operacionesGraficaCompras">0</strong>
                            <small id="operacionesTextoGraficaCompras">En el periodo visible</small>
                        </div>
                        <div class="dashboard-chart-overview__stat">
                            <span>Pico del periodo</span>
                            <strong id="picoGraficaCompras">$0.00</strong>
                            <small id="picoEtiquetaGraficaCompras">Sin datos</small>
                        </div>
                    </div>

                    <div class="dashboard-chart-shell" id="graficaCompras">
                        <div class="dashboard-chart-empty">Cargando compras...</div>
                    </div>

                    <div class="dashboard-chart-color-guide" aria-label="Guía de color de la gráfica">
                        <span><i class="is-rise"></i>Sube</span>
                        <span><i class="is-peak"></i>Pico</span>
                        <span><i class="is-down"></i>Baja</span>
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

            </div>

        </main>
    </div>
</div>

<div class="dashboard-toast-region" id="dashboardToastRegion" aria-live="polite" aria-atomic="false"></div>

<div class="dashboard-confirm" id="dashboardConfirm" hidden>
    <div class="dashboard-confirm__backdrop" data-dashboard-confirm-close></div>
    <section class="dashboard-confirm__dialog" role="dialog" aria-modal="true" aria-labelledby="dashboardConfirmTitle" aria-describedby="dashboardConfirmMessage">
        <div class="dashboard-confirm__icon" id="dashboardConfirmIcon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 9v4"></path>
                <path d="M12 17h.01"></path>
                <path d="M10.3 3.7 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"></path>
            </svg>
        </div>
        <div class="dashboard-confirm__copy">
            <span class="dashboard-confirm__eyebrow">CONFIRMACIÓN</span>
            <h2 id="dashboardConfirmTitle">Confirmar acción</h2>
            <p id="dashboardConfirmMessage">Confirma que deseas continuar.</p>
        </div>
        <div class="dashboard-confirm__actions">
            <button type="button" class="dashboard-confirm__cancel" id="dashboardConfirmCancel">Cancelar</button>
            <button type="button" class="dashboard-confirm__accept" id="dashboardConfirmAccept">Confirmar</button>
        </div>
    </section>
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
    let datosGraficasDashboard = null;
    const periodosGraficas = { ventas: '7d', compras: '7d' };

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

    const botonActualizarTexto =
        document.getElementById('btnActualizarTexto');

    const toastRegion =
        document.getElementById('dashboardToastRegion');

    const confirmRoot =
        document.getElementById('dashboardConfirm');

    const confirmTitle =
        document.getElementById('dashboardConfirmTitle');

    const confirmMessage =
        document.getElementById('dashboardConfirmMessage');

    const confirmCancel =
        document.getElementById('dashboardConfirmCancel');

    const confirmAccept =
        document.getElementById('dashboardConfirmAccept');

    let resolverConfirmacion = null;
    let ultimoFocoConfirmacion = null;

    function mostrarToast(tipo, titulo, detalle) {
        if (!toastRegion) return;

        const variante = ['success', 'error', 'warning', 'info'].includes(tipo)
            ? tipo
            : 'info';

        const toast = document.createElement('article');
        toast.className = 'dashboard-toast dashboard-toast--' + variante;
        toast.setAttribute('role', variante === 'error' ? 'alert' : 'status');

        const icono = document.createElement('span');
        icono.className = 'dashboard-toast__icon';
        icono.setAttribute('aria-hidden', 'true');
        icono.textContent = variante === 'success'
            ? '✓'
            : variante === 'error'
                ? '!'
                : variante === 'warning'
                    ? '!'
                    : 'i';

        const contenido = document.createElement('div');
        contenido.className = 'dashboard-toast__content';

        const encabezado = document.createElement('strong');
        encabezado.textContent = titulo || 'Aviso';
        contenido.appendChild(encabezado);

        if (detalle) {
            const texto = document.createElement('span');
            texto.textContent = detalle;
            contenido.appendChild(texto);
        }

        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.className = 'dashboard-toast__close';
        cerrar.setAttribute('aria-label', 'Cerrar aviso');
        cerrar.textContent = '×';

        toast.append(icono, contenido, cerrar);
        toastRegion.appendChild(toast);

        let temporizador = window.setTimeout(function () {
            retirar();
        }, 5200);

        function retirar() {
            if (!toast.isConnected) return;
            window.clearTimeout(temporizador);
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                toast.remove();
            }, 190);
        }

        cerrar.addEventListener('click', retirar);
    }

    function cerrarConfirmacion(resultado) {
        if (!confirmRoot || confirmRoot.hidden) return;

        confirmRoot.classList.remove('is-open');
        document.body.classList.remove('dashboard-confirm-open');

        window.setTimeout(function () {
            confirmRoot.hidden = true;
        }, 180);

        const resolver = resolverConfirmacion;
        resolverConfirmacion = null;

        if (ultimoFocoConfirmacion instanceof HTMLElement) {
            ultimoFocoConfirmacion.focus({ preventScroll: true });
        }
        ultimoFocoConfirmacion = null;

        if (resolver) resolver(Boolean(resultado));
    }

    function confirmarAccion(configuracion) {
        if (!confirmRoot || !confirmTitle || !confirmMessage || !confirmAccept) {
            return Promise.resolve(true);
        }

        const config = configuracion || {};
        confirmTitle.textContent = config.titulo || 'Confirmar acción';
        confirmMessage.textContent = config.mensaje || 'Confirma que deseas continuar.';
        confirmAccept.textContent = config.aceptar || 'Confirmar';

        ultimoFocoConfirmacion = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        confirmRoot.hidden = false;
        document.body.classList.add('dashboard-confirm-open');

        window.requestAnimationFrame(function () {
            confirmRoot.classList.add('is-open');
            confirmAccept.focus({ preventScroll: true });
        });

        return new Promise(function (resolve) {
            resolverConfirmacion = resolve;
        });
    }

    confirmCancel?.addEventListener('click', function () {
        cerrarConfirmacion(false);
    });

    confirmAccept?.addEventListener('click', function () {
        cerrarConfirmacion(true);
    });

    confirmRoot?.addEventListener('click', function (event) {
        const objetivo = event.target;
        if (objetivo instanceof Element && objetivo.closest('[data-dashboard-confirm-close]')) {
            cerrarConfirmacion(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && confirmRoot && !confirmRoot.hidden) {
            event.preventDefault();
            cerrarConfirmacion(false);
        }
    });

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

    function resumenSerieGrafica(serie, campo, campoOperaciones) {
        const filas = Array.isArray(serie) ? serie : [];
        let total = 0;
        let operaciones = 0;
        let pico = 0;
        let etiquetaPico = 'Sin datos';

        filas.forEach(function (item) {
            const valor = Number(item[campo] || 0);
            const cantidad = Number(item[campoOperaciones] || 0);

            if (Number.isFinite(valor)) total += valor;
            if (Number.isFinite(cantidad)) operaciones += cantidad;

            if (Number.isFinite(valor) && valor >= pico) {
                pico = valor;
                etiquetaPico = item.etiqueta || item.periodo || 'Periodo';
            }
        });

        return {
            total: total,
            operaciones: operaciones,
            pico: pico,
            etiquetaPico: etiquetaPico
        };
    }

    function claseTendenciaBarra(valor, anterior, esPico) {
        if (esPico && Number(valor) > 0) return 'is-peak';
        if (anterior === null || !Number.isFinite(anterior)) return 'is-neutral';
        if (Number(valor) > anterior) return 'is-rise';
        if (Number(valor) < anterior) return 'is-down';
        return 'is-neutral';
    }

    function renderGraficaBarras(id, filas, campo, moneda, tipo) {
        const contenedor = document.getElementById(id);
        if (!contenedor) return;

        const datos = Array.isArray(filas) ? filas : [];
        if (!datos.length) {
            contenedor.innerHTML = '<div class="dashboard-chart-empty">Sin información para este periodo.</div>';
            return;
        }

        const valores = datos.map(function (item) {
            const valor = Number(item[campo] || 0);
            return Number.isFinite(valor) ? Math.max(0, valor) : 0;
        });

        const maximoReal = Math.max.apply(null, valores.concat([0]));
        const indicePico = maximoReal > 0 ? valores.lastIndexOf(maximoReal) : -1;
        const maximoEscala = maximoReal > 0 ? maximoReal * 1.18 : 1;

        const width = 760;
        const height = 285;
        const pad = { top: 20, right: 18, bottom: 48, left: 70 };
        const plotW = width - pad.left - pad.right;
        const plotH = height - pad.top - pad.bottom;
        const slotW = plotW / Math.max(datos.length, 1);
        const barW = Math.max(24, Math.min(58, slotW * .48));
        const baseY = pad.top + plotH;
        const tipoTexto = tipo === 'compras' ? 'Compras' : 'Ventas';

        let svg = '<svg class="dashboard-chart-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + tipoTexto + ' del periodo">';

        for (let i = 0; i <= 4; i += 1) {
            const valorEscala = maximoEscala * (4 - i) / 4;
            const gy = pad.top + (plotH * i / 4);
            svg += '<line class="dashboard-chart-gridline" x1="' + pad.left + '" y1="' + gy.toFixed(2) + '" x2="' + (width - pad.right) + '" y2="' + gy.toFixed(2) + '"></line>';
            svg += '<text class="dashboard-chart-axis-label dashboard-chart-axis-label--y" x="' + (pad.left - 10) + '" y="' + (gy + 4).toFixed(2) + '">' + escapeHtml(dineroCompacto(valorEscala, moneda)) + '</text>';
        }

        datos.forEach(function (item, indice) {
            const valor = valores[indice];
            const xCentro = pad.left + (slotW * indice) + (slotW / 2);
            const altura = maximoReal <= 0 ? 0 : Math.max(valor > 0 ? 5 : 0, valor / maximoEscala * plotH);
            const yBarra = baseY - altura;
            const anterior = indice > 0 ? valores[indice - 1] : null;
            const clase = claseTendenciaBarra(valor, anterior, indice === indicePico);
            const etiqueta = escapeHtml(item.etiqueta || item.periodo || '');
            const valorTexto = escapeHtml(dinero(valor, moneda));
            let comparacion = 'Sin periodo anterior';

            if (anterior !== null) {
                if (valor > anterior) comparacion = 'Sube respecto al periodo anterior';
                else if (valor < anterior) comparacion = 'Baja respecto al periodo anterior';
                else comparacion = 'Sin cambio respecto al periodo anterior';
            }

            const tooltip = etiqueta + ' · ' + tipoTexto + ': ' + valorTexto + ' · ' + comparacion;
            const rx = (barW / 2).toFixed(2);

            svg += '<rect class="dashboard-chart-bar dashboard-chart-bar--' + tipo + ' ' + clase + '" x="' + (xCentro - barW / 2).toFixed(2) + '" y="' + yBarra.toFixed(2) + '" width="' + barW.toFixed(2) + '" height="' + altura.toFixed(2) + '" rx="' + rx + '" tabindex="0" aria-label="' + tooltip + '" data-chart-tooltip="' + tooltip + '"><title>' + tooltip + '</title></rect>';
            svg += '<text class="dashboard-chart-axis-label dashboard-chart-axis-label--x" x="' + xCentro.toFixed(2) + '" y="' + (height - 17) + '">' + etiqueta + '</text>';
        });

        svg += '</svg>';
        contenedor.innerHTML = svg + '<div class="dashboard-chart-tooltip" role="status" hidden></div>';

        const tooltip = contenedor.querySelector('.dashboard-chart-tooltip');
        const ocultarTooltip = function () {
            if (tooltip) tooltip.hidden = true;
        };
        const mostrarTooltip = function (event) {
            if (!tooltip) return;

            const barra = event.currentTarget;
            const contenedorRect = contenedor.getBoundingClientRect();
            const barraRect = barra.getBoundingClientRect();
            const centroX = barraRect.left - contenedorRect.left + (barraRect.width / 2);
            const centroY = barraRect.top - contenedorRect.top;

            tooltip.textContent = barra.dataset.chartTooltip || '';
            tooltip.hidden = false;

            const anchoTooltip = tooltip.offsetWidth;
            const altoTooltip = tooltip.offsetHeight;
            const izquierda = Math.max(
                8,
                Math.min(
                    centroX - (anchoTooltip / 2),
                    contenedor.clientWidth - anchoTooltip - 8
                )
            );

            tooltip.style.left = izquierda + 'px';
            tooltip.style.top = Math.max(8, centroY - altoTooltip - 12) + 'px';
        };

        contenedor.querySelectorAll('[data-chart-tooltip]').forEach(function (barra) {
            barra.addEventListener('pointerenter', mostrarTooltip);
            barra.addEventListener('focus', mostrarTooltip);
            barra.addEventListener('pointerleave', ocultarTooltip);
            barra.addEventListener('blur', ocultarTooltip);
        });
    }

    function variacionGrafica(datos, tipo, periodo) {
        const semanal = datos.grafica_semanal || {};
        const mensual = datos.grafica_mensual || {};
        const totalesSemana = semanal.totales || {};
        const totalesMes = mensual.totales || {};

        if (periodo === '6m') {
            return {
                valor: totalesMes['variacion_' + tipo + '_pct'],
                etiqueta: 'mes anterior',
                prefijo: 'Mes actual · '
            };
        }

        return {
            valor: totalesSemana['variacion_' + tipo + '_pct'],
            etiqueta: '7 días anteriores',
            prefijo: ''
        };
    }

    function actualizarBotonesPeriodo(tipo, periodo) {
        document.querySelectorAll('[data-chart-kind="' + tipo + '"]').forEach(function (boton) {
            const activo = boton.dataset.chartPeriod === periodo;
            boton.classList.toggle('is-active', activo);
            boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
    }

    function renderGraficaComercial(tipo) {
        if (!datosGraficasDashboard) return;

        const esVentas = tipo === 'ventas';
        const capitalizado = esVentas ? 'Ventas' : 'Compras';
        const campoOperaciones = tipo + '_operaciones';
        const periodo = periodosGraficas[tipo] || '7d';
        const fuente = periodo === '6m'
            ? (datosGraficasDashboard.grafica_mensual || {})
            : (datosGraficasDashboard.grafica_semanal || {});
        const serie = Array.isArray(fuente.serie) ? fuente.serie : [];
        const moneda = fuente.moneda_base || 'MXN';
        const resumen = resumenSerieGrafica(serie, tipo, campoOperaciones);
        const variacion = variacionGrafica(datosGraficasDashboard, tipo, periodo);
        const sufijoTitulo = periodo === '6m' ? 'Últimos 6 meses' : 'Últimos 7 días';
        const comparacion = document.getElementById('variacionGrafica' + capitalizado);

        document.getElementById('tituloGrafica' + capitalizado).textContent = capitalizado + ' · ' + sufijoTitulo;
        document.getElementById('rangoGrafica' + capitalizado).textContent = fuente.periodo || sufijoTitulo;
        document.getElementById('monedaGrafica' + capitalizado).textContent = moneda;
        document.getElementById('totalGrafica' + capitalizado).textContent = dinero(resumen.total, moneda);
        document.getElementById('operacionesGrafica' + capitalizado).textContent = numero(resumen.operaciones);
        document.getElementById('picoGrafica' + capitalizado).textContent = dinero(resumen.pico, moneda);
        document.getElementById('picoEtiquetaGrafica' + capitalizado).textContent = resumen.etiquetaPico;
        document.getElementById('operacionesTextoGrafica' + capitalizado).textContent = periodo === '6m'
            ? 'Acumuladas en seis meses'
            : 'Acumuladas en siete días';

        if (comparacion) {
            renderVariacion('variacionGrafica' + capitalizado, variacion.valor, variacion.etiqueta);
            comparacion.textContent = variacion.prefijo + comparacion.textContent;
        }

        actualizarBotonesPeriodo(tipo, periodo);
        renderGraficaBarras('grafica' + capitalizado, serie, tipo, moneda, tipo);
    }

    function renderGraficas(datos) {
        datosGraficasDashboard = datos;

        if (permisosDashboard.ventas === true) {
            renderGraficaComercial('ventas');
        }

        if (permisosDashboard.compras === true) {
            renderGraficaComercial('compras');
        }
    }

    document.addEventListener('click', function (event) {
        const botonPeriodo = event.target.closest('[data-chart-kind][data-chart-period]');
        if (!botonPeriodo) return;

        const tipo = botonPeriodo.dataset.chartKind;
        const periodo = botonPeriodo.dataset.chartPeriod;

        if (!Object.prototype.hasOwnProperty.call(periodosGraficas, tipo)) return;
        if (periodo !== '7d' && periodo !== '6m') return;
        if (periodosGraficas[tipo] === periodo) return;

        periodosGraficas[tipo] = periodo;
        renderGraficaComercial(tipo);
    });

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
            mostrarToast(
                'success',
                'Tipo de cambio actualizado',
                valor > 0
                    ? '1 USD = $' + numero(valor, 4) + ' MXN' + (fecha ? ' · FIX ' + fecha : '')
                    : 'La actualización se completó correctamente.'
            );

            renderAlertas(data.alertas_operativas || {});
            window.dispatchEvent(new CustomEvent('si:alertas-actualizadas'));
        } catch (error) {
            mensaje.className = 'dashboard-message dashboard-message--error';
            mensaje.textContent = error.message || 'No fue posible actualizar el tipo de cambio.';
            mensaje.hidden = false;
            mostrarToast('error', 'No se pudo actualizar el tipo de cambio', mensaje.textContent);
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
            mostrarToast('success', 'Alerta actualizada', 'La alerta se marcó como leída.');
        } catch (error) {
            button.disabled = false;
            button.textContent = textoOriginal;
            mensaje.textContent = error.message || 'No fue posible actualizar la alerta.';
            mensaje.hidden = false;
            mostrarToast('error', 'No se pudo actualizar la alerta', mensaje.textContent);
        }
    });

    document.getElementById('btnMarcarTodasAlertas')?.addEventListener('click', async function () {
        const button = this;
        if (button.disabled) return;

        const confirmado = await confirmarAccion({
            titulo: 'Marcar todas como leídas',
            mensaje: 'Las alertas activas permanecerán disponibles, pero dejarán de mostrarse como pendientes de lectura.',
            aceptar: 'Sí, marcar todas'
        });

        if (!confirmado) return;

        button.disabled = true;
        const textoOriginal = button.textContent;
        button.textContent = 'Marcando...';
        try {
            await actualizarLecturaAlertas('MARCAR_TODAS_LEIDAS');
            button.textContent = textoOriginal;
            mostrarToast('success', 'Alertas revisadas', 'Todas las alertas pendientes se marcaron como leídas.');
        } catch (error) {
            button.disabled = false;
            button.textContent = textoOriginal;
            mensaje.textContent = error.message || 'No fue posible actualizar la alerta.';
            mensaje.hidden = false;
            mostrarToast('error', 'No se pudieron actualizar las alertas', mensaje.textContent);
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
        const eraCargaInicial = !cargaInicialCompleta;

        if (!silencioso) {
            mensaje.hidden = true;
            botonActualizar.disabled = true;
            botonActualizar.classList.add('is-loading');
            if (botonActualizarTexto) botonActualizarTexto.textContent = 'Actualizando...';
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
            } else if (!eraCargaInicial) {
                mostrarToast('success', 'Dashboard actualizado', 'La información visible se sincronizó correctamente.');
            }

            window.dispatchEvent(new CustomEvent('si:alertas-actualizadas'));

        } catch (error) {
            const textoError = error.message || 'Ocurrió un error inesperado.';

            if (!silencioso || !cargaInicialCompleta) {
                mensaje.textContent = textoError;
                mensaje.hidden = false;
                if (!silencioso) {
                    mostrarToast('error', 'No se pudo actualizar el dashboard', textoError);
                }
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
                botonActualizar.classList.remove('is-loading');
                if (botonActualizarTexto) botonActualizarTexto.textContent = 'Actualizar';
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
