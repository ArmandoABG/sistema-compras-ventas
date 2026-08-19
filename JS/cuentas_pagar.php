<?php

declare(strict_types=1);

if (isset($_GET['cxp_api'])) {
    $endpoint = __DIR__ . '/../funciones/cuentas_pagar_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/cuentas_pagar_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('cuentas_pagar.ver', false);

$tituloPagina = 'Cuentas por pagar';
$csrfToken = si_token_csrf();
$puedePagar = si_tiene_permiso('cuentas_pagar.pagar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_cuentas_pagar.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'deudas')));
if (!in_array($seccionInicial, ['deudas', 'abonos', 'vencimientos'], true)) {
    $seccionInicial = 'deudas';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cuentas por pagar | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_cuentas_pagar.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content cxp-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">GESTIÓN FINANCIERA · PASIVOS</p>
                    <h1>Cuentas por pagar</h1>
                    <p>Las compras a crédito generan la deuda automáticamente. Aquí solo se consultan saldos, se registran abonos y se controlan vencimientos.</p>
                </div>
            </header>

            <nav class="module-tabs" aria-label="Cuentas por pagar">
                <button type="button" class="module-tab" data-seccion="deudas">Deudas</button>
                <button type="button" class="module-tab" data-seccion="abonos">Abonos</button>
                <button type="button" class="module-tab" data-seccion="vencimientos">Vencimientos</button>
            </nav>

            <div id="mensajePagina" class="module-message" hidden></div>

            <!-- DEUDAS -->
            <section id="seccionDeudas" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Deudas con proveedores</h2>
                        <p>No se crean manualmente: aparecen cuando una compra a crédito se confirma.</p>
                    </div>
                </div>

                <section class="stats-grid stats-grid--5">
                    <article><span>Total</span><strong id="kpiCxpTotal">0</strong></article>
                    <article><span>Pendientes</span><strong id="kpiCxpPendientes">0</strong></article>
                    <article><span>Con abonos</span><strong id="kpiCxpParciales">0</strong></article>
                    <article><span>Vencidas</span><strong id="kpiCxpVencidas">0</strong></article>
                    <article><span>Pagadas</span><strong id="kpiCxpPagadas">0</strong></article>
                </section>

                <div id="saldosMonedaDeudas" class="currency-strip"></div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--debts">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarCuenta" maxlength="180" placeholder="CxP, compra, factura o proveedor" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoCuenta">
                                <option value="TODOS">Todos</option>
                                <option value="PENDIENTE">Pendientes</option>
                                <option value="PARCIAL">Parcialmente pagadas</option>
                                <option value="VENCIDA">Vencidas</option>
                                <option value="PAGADA">Pagadas</option>
                                <option value="CANCELADA">Canceladas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Vencimiento</span>
                            <select id="filtroVencimientoCuenta">
                                <option value="TODOS">Todos</option>
                                <option value="VENCIDAS">Ya vencidas</option>
                                <option value="HOY">Vencen hoy</option>
                                <option value="7_DIAS">Próximos 7 días</option>
                                <option value="15_DIAS">Próximos 15 días</option>
                                <option value="30_DIAS">Próximos 30 días</option>
                                <option value="60_DIAS">Próximos 60 días</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Moneda</span>
                            <select id="filtroMonedaCuenta">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaCuenta">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--debts">
                            <thead>
                                <tr>
                                    <th>Cuenta / compra</th>
                                    <th>Proveedor</th>
                                    <th>Documento</th>
                                    <th>Vencimiento</th>
                                    <th>Original</th>
                                    <th>Pagado</th>
                                    <th>Saldo</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaCuentas"><tr><td colspan="9" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaCuenta">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnCuentaAnterior">Anterior</button>
                            <span id="paginaCuentaActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnCuentaSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- ABONOS -->
            <section id="seccionAbonos" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Historial de abonos</h2>
                        <p>Cada pago conserva fecha, monto, método, referencia y usuario responsable. Los pagos cancelados permanecen visibles.</p>
                    </div>
                </div>

                <section class="stats-grid stats-grid--3">
                    <article><span>Pagos registrados</span><strong id="kpiPagosTotal">0</strong></article>
                    <article><span>Aplicados</span><strong id="kpiPagosAplicados">0</strong></article>
                    <article><span>Cancelados</span><strong id="kpiPagosCancelados">0</strong></article>
                </section>

                <div id="totalesMonedaPagos" class="currency-strip"></div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--payments">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarPago" maxlength="180" placeholder="Pago, referencia, CxP, compra o proveedor" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoPago">
                                <option value="TODOS">Todos</option>
                                <option value="APLICADO">Aplicados</option>
                                <option value="CANCELADO">Cancelados</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Método</span>
                            <select id="filtroMetodoPago">
                                <option value="0">Todos</option>
                            </select>
                        </label>

                        <label class="field"><span>Desde</span><input type="date" id="filtroDesdePago"></label>
                        <label class="field"><span>Hasta</span><input type="date" id="filtroHastaPago"></label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaPago">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--payments">
                            <thead>
                                <tr>
                                    <th>Pago</th>
                                    <th>Proveedor</th>
                                    <th>Cuenta / compra</th>
                                    <th>Método / referencia</th>
                                    <th>Importe</th>
                                    <th>Usuario</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaPagos"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaPago">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnPagoAnterior">Anterior</button>
                            <span id="paginaPagoActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnPagoSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- VENCIMIENTOS -->
            <section id="seccionVencimientos" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Calendario de vencimientos</h2>
                        <p>Prioriza lo vencido y lo que está próximo a vencer, sin mezclar importes de monedas diferentes.</p>
                    </div>
                </div>

                <div id="totalesMonedaVencimientos" class="currency-strip"></div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--due">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarVencimiento" maxlength="180" placeholder="Cuenta, compra, factura o proveedor" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Periodo</span>
                            <select id="filtroHorizonteVencimiento">
                                <option value="VENCIDAS">Ya vencidas</option>
                                <option value="HOY">Vencen hoy</option>
                                <option value="7_DIAS">Próximos 7 días</option>
                                <option value="15_DIAS">Próximos 15 días</option>
                                <option value="30_DIAS" selected>Próximos 30 días</option>
                                <option value="60_DIAS">Próximos 60 días</option>
                                <option value="TODAS">Todas las abiertas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Moneda</span>
                            <select id="filtroMonedaVencimiento">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaVencimiento">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--due">
                            <thead>
                                <tr>
                                    <th>Vencimiento</th>
                                    <th>Proveedor</th>
                                    <th>Cuenta / compra</th>
                                    <th>Documento</th>
                                    <th>Pagado</th>
                                    <th>Saldo</th>
                                    <th>Situación</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaVencimientos"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaVencimiento">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnVencimientoAnterior">Anterior</button>
                            <span id="paginaVencimientoActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnVencimientoSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>
        </main>
    </div>
</div>

<!-- DETALLE DE CUENTA -->
<div class="modal-backdrop" id="modalCuenta" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>CUENTA POR PAGAR</small>
                <h2 id="tituloDetalleCuenta">Detalle</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCuenta">×</button>
        </header>

        <div class="modal-body">
            <div id="detalleCuenta" class="detail-grid detail-grid--account"></div>

            <div class="subsection-heading subsection-heading--inline">
                <div>
                    <h3>Abonos de esta cuenta</h3>
                    <p>El historial no se elimina aunque un pago se cancele.</p>
                </div>
            </div>

            <div class="table-wrap detail-payments-wrap">
                <table class="module-table module-table--detail-payments">
                    <thead>
                        <tr>
                            <th>Pago / fecha</th>
                            <th>Método</th>
                            <th>Importe aplicado</th>
                            <th>Usuario</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="tablaHistorialCuenta"><tr><td colspan="5" class="empty-cell">Cargando...</td></tr></tbody>
                </table>
            </div>

            <footer class="inline-pagination">
                <span id="textoPaginaHistorialCuenta">0 registros</span>
                <div>
                    <button type="button" class="btn-secondary" id="btnHistorialCuentaAnterior">Anterior</button>
                    <span id="paginaHistorialCuentaActual">Página 1 de 1</span>
                    <button type="button" class="btn-secondary" id="btnHistorialCuentaSiguiente">Siguiente</button>
                </div>
            </footer>
        </div>

        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalCuenta">Cerrar</button>
            <?php if ($puedePagar): ?>
                <button type="button" class="btn-primary" id="btnAbonarDesdeDetalle" hidden>Registrar abono</button>
            <?php endif; ?>
        </footer>
    </section>
</div>

<!-- REGISTRAR ABONO -->
<?php if ($puedePagar): ?>
<div class="modal-backdrop" id="modalAbono" hidden>
    <section class="modal-card modal-card--payment" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>REGISTRAR ABONO</small>
                <h2>Pago a proveedor</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalAbono">×</button>
        </header>

        <form id="formAbono">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="REGISTRAR_ABONO">
            <input type="hidden" name="cuenta_id" id="abonoCuentaId">

            <div id="mensajeAbono" class="module-message module-message--error" hidden></div>

            <div id="resumenCuentaAbono" class="payment-account-summary"></div>

            <div class="form-grid">
                <label class="field">
                    <span>Importe a abonar *</span>
                    <div class="money-input-wrap">
                        <span id="simboloMonedaAbono">$</span>
                        <input type="number" name="importe" id="abonoImporte" min="0.0001" step="0.0001" required>
                    </div>
                    <small id="ayudaImporteAbono">Se propone el saldo completo; puedes registrar un pago parcial.</small>
                </label>

                <label class="field">
                    <span>Moneda</span>
                    <input type="text" id="abonoMoneda" readonly>
                    <small>Se toma automáticamente de la cuenta. No necesitas capturarla.</small>
                </label>

                <label class="field">
                    <span>Método de pago *</span>
                    <select name="metodo_pago_id" id="abonoMetodo" required>
                        <option value="">Selecciona</option>
                    </select>
                </label>

                <label class="field">
                    <span>Referencia / operación</span>
                    <input type="text" name="referencia" id="abonoReferencia" maxlength="120" placeholder="Ej. SPEI 845732">
                    <small id="ayudaReferenciaAbono">Se solicitará solo cuando el método la requiera.</small>
                </label>

                <label class="field">
                    <span>Fecha y hora del pago *</span>
                    <input type="datetime-local" name="fecha_pago" id="abonoFecha" required>
                </label>

                <label class="field field--span-2">
                    <span>Observaciones</span>
                    <textarea name="observaciones" id="abonoObservaciones" rows="3" maxlength="10000" placeholder="Opcional"></textarea>
                </label>
            </div>

            <div class="smart-note">
                <strong>El sistema hace el resto</strong>
                <span>Genera el folio del pago, aplica el abono, actualiza pagado/saldo/estado y conserva al usuario responsable.</span>
            </div>

            <footer class="modal-footer modal-footer--form">
                <button type="button" class="btn-secondary" id="btnLiquidarSaldo">Usar saldo completo</button>
                <div class="modal-footer__actions">
                    <button type="button" class="btn-secondary" data-cerrar-modal="modalAbono">Cancelar</button>
                    <button type="submit" class="btn-primary">Registrar pago</button>
                </div>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>

<!-- DETALLE PAGO -->
<div class="modal-backdrop" id="modalPago" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>PAGO A PROVEEDOR</small>
                <h2 id="tituloDetallePago">Detalle del pago</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalPago">×</button>
        </header>

        <div class="modal-body">
            <div id="detallePago" class="detail-grid"></div>
            <div id="aplicacionesPago"></div>
        </div>

        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalPago">Cerrar</button>
            <?php if ($puedePagar): ?>
                <button type="button" class="btn-danger" id="btnCancelarDesdeDetallePago" hidden>Cancelar pago</button>
            <?php endif; ?>
        </footer>
    </section>
</div>

<!-- CANCELAR PAGO -->
<?php if ($puedePagar): ?>
<div class="modal-backdrop" id="modalCancelarPago" hidden>
    <section class="modal-card" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>CONSERVAR HISTORIAL</small>
                <h2>Cancelar pago</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCancelarPago">×</button>
        </header>

        <form id="formCancelarPago">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="CANCELAR_PAGO">
            <input type="hidden" name="pago_id" id="cancelarPagoId">

            <div id="mensajeCancelarPago" class="module-message module-message--error" hidden></div>

            <div class="warning-box">
                <strong>Este pago no se borrará.</strong>
                <span>Quedará marcado como cancelado y el importe aplicado regresará al saldo pendiente de la cuenta.</span>
            </div>

            <label class="field">
                <span>Motivo de cancelación *</span>
                <textarea name="motivo" id="cancelarPagoMotivo" rows="4" maxlength="10000" required></textarea>
            </label>

            <footer class="modal-footer modal-footer--form">
                <span></span>
                <div class="modal-footer__actions">
                    <button type="button" class="btn-secondary" data-cerrar-modal="modalCancelarPago">Volver</button>
                    <button type="submit" class="btn-danger">Confirmar cancelación</button>
                </div>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    const puedePagar = <?= $puedePagar ? 'true' : 'false' ?>;
    const seccionInicial = <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const estado = {
        seccion: seccionInicial,
        monedas: [],
        metodos: [],

        paginaCuenta: 1,
        totalPaginasCuenta: 1,
        porPaginaCuenta: 20,

        paginaPago: 1,
        totalPaginasPago: 1,
        porPaginaPago: 20,

        paginaVencimiento: 1,
        totalPaginasVencimiento: 1,
        porPaginaVencimiento: 20,

        cuentaDetalle: null,
        paginaHistorialCuenta: 1,
        totalPaginasHistorialCuenta: 1,
        pagoDetalle: null,

        timerCuenta: null,
        timerPago: null,
        timerVencimiento: null
    };

    const $ = id => document.getElementById(id);

    function escapeHtml(valor) {
        return String(valor == null ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function numero(valor, decimales = 2) {
        const n = Number(valor || 0);
        return new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: decimales,
            maximumFractionDigits: decimales
        }).format(Number.isFinite(n) ? n : 0);
    }

    function dinero(valor, codigo, simbolo) {
        return escapeHtml(simbolo || '')
            + numero(valor, 2)
            + ' '
            + escapeHtml(codigo || '');
    }

    function fecha(valor) {
        if (!valor) return '—';
        const partes = String(valor).slice(0, 10).split('-');
        if (partes.length !== 3) return escapeHtml(valor);
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function fechaHora(valor) {
        if (!valor) return '—';
        const texto = String(valor).replace(' ', 'T');
        const d = new Date(texto);
        if (Number.isNaN(d.getTime())) return escapeHtml(valor);
        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'short',
            timeStyle: 'short'
        }).format(d);
    }

    function ahoraLocalInput() {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return d.getFullYear()
            + '-' + pad(d.getMonth() + 1)
            + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours())
            + ':' + pad(d.getMinutes());
    }

    function mostrarMensaje(elemento, texto, tipo = 'error') {
        elemento.textContent = texto;
        elemento.className = 'module-message module-message--' + tipo;
        elemento.hidden = false;
    }

    function ocultarMensaje(elemento) {
        elemento.textContent = '';
        elemento.hidden = true;
    }

    function mostrarError(error) {
        mostrarMensaje(
            $('mensajePagina'),
            error && error.message ? error.message : 'Ocurrió un error.',
            'error'
        );
    }

    async function api(url, opciones) {
        const respuesta = await fetch(
            url,
            Object.assign({
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }, opciones || {})
        );

        const texto = await respuesta.text();
        let datos;

        try {
            datos = JSON.parse(texto);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (datos.sesion_expirada && datos.redirect) {
            window.location.href = datos.redirect;
            return null;
        }

        if (!respuesta.ok || datos.success !== true) {
            const error = new Error(datos.mensaje || 'No fue posible completar la operación.');
            error.data = datos;
            throw error;
        }

        return datos;
    }

    function abrirModal(id) {
        $(id).hidden = false;
        document.body.classList.add('modal-open');
    }

    function cerrarModal(id) {
        $(id).hidden = true;

        if (!document.querySelector('.modal-backdrop:not([hidden])')) {
            document.body.classList.remove('modal-open');
        }
    }

    function estadoCuentaHtml(c) {
        const e = c.estado_calculado;
        let clase = 'draft';
        let texto = e;

        if (e === 'PENDIENTE') {
            clase = 'warning';
            texto = 'Pendiente';
        } else if (e === 'PARCIAL') {
            clase = 'partial';
            texto = 'Parcialmente pagada';
        } else if (e === 'PAGADA') {
            clase = 'active';
            texto = 'Pagada';
        } else if (e === 'VENCIDA') {
            clase = 'danger';
            texto = Number(c.importe_pagado || 0) > 0
                ? 'Vencida · con abonos'
                : 'Vencida';
        } else if (e === 'CANCELADA') {
            clase = 'inactive';
            texto = 'Cancelada';
        }

        return '<span class="status-badge status-badge--'
            + clase
            + '">'
            + escapeHtml(texto)
            + '</span>';
    }

    function vencimientoHtml(c) {
        const dias = Number(c.dias_vencimiento || 0);
        let clase = 'due-normal';
        let texto;

        if (dias < 0) {
            clase = 'due-overdue';
            texto = 'Venció hace ' + Math.abs(dias) + ' día(s)';
        } else if (dias === 0) {
            clase = 'due-today';
            texto = 'Vence hoy';
        } else if (dias <= 7) {
            clase = 'due-soon';
            texto = 'En ' + dias + ' día(s)';
        } else {
            texto = 'En ' + dias + ' día(s)';
        }

        return '<span class="due-label ' + clase + '">'
            + escapeHtml(texto)
            + '</span>';
    }

    function botonAccion(texto, accion, id, variante) {
        return '<button type="button" class="table-action'
            + (variante ? ' table-action--' + variante : '')
            + '" data-action="'
            + escapeHtml(accion)
            + '" data-id="'
            + Number(id)
            + '">'
            + escapeHtml(texto)
            + '</button>';
    }

    async function cargarCatalogos() {
        const datos = await api('?cxp_api=1&accion=CATALOGOS');
        estado.monedas = datos.monedas || [];
        estado.metodos = datos.metodos_pago || [];

        const opcionesMoneda = estado.monedas.map(m =>
            '<option value="' + m.id + '">'
            + escapeHtml(m.codigo + ' · ' + m.nombre)
            + '</option>'
        ).join('');

        $('filtroMonedaCuenta').innerHTML = '<option value="0">Todas</option>' + opcionesMoneda;
        $('filtroMonedaVencimiento').innerHTML = '<option value="0">Todas</option>' + opcionesMoneda;

        const opcionesMetodo = estado.metodos.map(m =>
            '<option value="' + m.id + '">'
            + escapeHtml(m.nombre)
            + '</option>'
        ).join('');

        $('filtroMetodoPago').innerHTML = '<option value="0">Todos</option>' + opcionesMetodo;

        if (puedePagar) {
            $('abonoMetodo').innerHTML = '<option value="">Selecciona</option>' + opcionesMetodo;
        }
    }

    function cambiarSeccion(seccion) {
        estado.seccion = seccion;

        document.querySelectorAll('.module-section').forEach(s => {
            s.hidden = true;
        });

        document.querySelectorAll('.module-tab').forEach(tab => {
            tab.classList.toggle('is-active', tab.dataset.seccion === seccion);
        });

        const ids = {
            deudas: 'seccionDeudas',
            abonos: 'seccionAbonos',
            vencimientos: 'seccionVencimientos'
        };

        $(ids[seccion]).hidden = false;

        const url = new URL(window.location.href);
        url.searchParams.set('seccion', seccion);
        history.replaceState(null, '', url);

        cargarSeccion().catch(mostrarError);
    }

    async function cargarSeccion() {
        if (estado.seccion === 'deudas') {
            await cargarCuentas();
        } else if (estado.seccion === 'abonos') {
            await cargarPagos();
        } else if (estado.seccion === 'vencimientos') {
            await cargarVencimientos();
        }
    }

    /* ==============================================================
       DEUDAS
       ============================================================== */

    async function cargarCuentas() {
        const params = new URLSearchParams({
            cxp_api: '1',
            accion: 'LISTAR_CUENTAS',
            pagina: String(estado.paginaCuenta),
            por_pagina: String(estado.porPaginaCuenta),
            busqueda: $('buscarCuenta').value.trim(),
            estado: $('filtroEstadoCuenta').value,
            vencimiento: $('filtroVencimientoCuenta').value,
            moneda_id: $('filtroMonedaCuenta').value
        });

        $('tablaCuentas').innerHTML = '<tr><td colspan="9" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        const cuentas = datos.cuentas || [];

        estado.totalPaginasCuenta = datos.paginacion.total_paginas || 1;
        renderCuentas(cuentas);
        renderPaginacion('Cuenta', datos.paginacion);
        renderResumenCuentas(datos.resumen || {});
    }

    function renderCuentas(cuentas) {
        if (!cuentas.length) {
            $('tablaCuentas').innerHTML = '<tr><td colspan="9" class="empty-cell">No se encontraron cuentas por pagar.</td></tr>';
            return;
        }

        $('tablaCuentas').innerHTML = cuentas.map(c => {
            let acciones = botonAccion('Detalle', 'detalle-cuenta', c.id);

            if (
                puedePagar
                && !['PAGADA', 'CANCELADA'].includes(c.estado_calculado)
                && Number(c.saldo_pendiente) > 0
            ) {
                acciones += botonAccion('Abonar', 'abonar-cuenta', c.id, 'success');
            }

            return '<tr>'
                + '<td><strong>' + escapeHtml(c.folio) + '</strong>'
                + '<small class="cell-secondary">Compra ' + escapeHtml(c.compra_folio) + '</small></td>'
                + '<td><strong>' + escapeHtml(c.proveedor) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(c.proveedor_codigo) + '</small></td>'
                + '<td>' + escapeHtml(c.numero_factura || 'Sin factura / documento')
                + '<small class="cell-secondary">Doc. ' + fecha(c.fecha_documento) + '</small></td>'
                + '<td><strong>' + fecha(c.fecha_vencimiento) + '</strong>'
                + '<small class="cell-secondary">' + vencimientoHtml(c) + '</small></td>'
                + '<td>' + dinero(c.importe_original, c.moneda_codigo, c.moneda_simbolo) + '</td>'
                + '<td>' + dinero(c.importe_pagado, c.moneda_codigo, c.moneda_simbolo)
                + '<small class="cell-secondary">' + Number(c.abonos_aplicados || 0) + ' abono(s)</small></td>'
                + '<td><strong>' + dinero(c.saldo_pendiente, c.moneda_codigo, c.moneda_simbolo) + '</strong></td>'
                + '<td>' + estadoCuentaHtml(c) + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderResumenCuentas(r) {
        $('kpiCxpTotal').textContent = r.total || 0;
        $('kpiCxpPendientes').textContent = r.pendientes || 0;
        $('kpiCxpParciales').textContent = r.parciales || 0;
        $('kpiCxpVencidas').textContent = r.vencidas || 0;
        $('kpiCxpPagadas').textContent = r.pagadas || 0;

        renderCurrencyStrip(
            $('saldosMonedaDeudas'),
            r.saldos_por_moneda || [],
            item => 'Saldo pendiente: ' + dinero(item.saldo_pendiente, item.codigo, item.simbolo)
                + (Number(item.saldo_vencido) > 0
                    ? ' · Vencido: ' + dinero(item.saldo_vencido, item.codigo, item.simbolo)
                    : '')
        );
    }

    async function abrirDetalleCuenta(id) {
        const datos = await api('?cxp_api=1&accion=DETALLE_CUENTA&id=' + encodeURIComponent(id));
        const c = datos.cuenta;
        estado.cuentaDetalle = c;
        estado.paginaHistorialCuenta = 1;

        $('tituloDetalleCuenta').textContent = c.folio;
        $('detalleCuenta').innerHTML = [
            detalleItem('Proveedor', c.proveedor + ' · ' + c.proveedor_codigo),
            detalleItem('Compra relacionada', c.compra_folio),
            detalleItem('Factura / documento', c.numero_factura || 'Sin capturar'),
            detalleItem('Fecha documento', fecha(c.fecha_documento)),
            detalleItem('Vencimiento', fecha(c.fecha_vencimiento) + ' · ' + textoVencimiento(c)),
            detalleItem('Estado', etiquetaEstadoTexto(c)),
            detalleItem('Monto original', textoDinero(c.importe_original, c.moneda_codigo, c.moneda_simbolo)),
            detalleItem('Monto pagado', textoDinero(c.importe_pagado, c.moneda_codigo, c.moneda_simbolo)),
            detalleItem('Saldo pendiente', textoDinero(c.saldo_pendiente, c.moneda_codigo, c.moneda_simbolo))
        ].join('');

        if (puedePagar) {
            $('btnAbonarDesdeDetalle').hidden = !c.puede_abonar;
        }

        abrirModal('modalCuenta');
        await cargarHistorialCuenta();
    }

    async function cargarHistorialCuenta() {
        if (!estado.cuentaDetalle) return;

        const params = new URLSearchParams({
            cxp_api: '1',
            accion: 'HISTORIAL_CUENTA',
            cuenta_id: String(estado.cuentaDetalle.id),
            pagina: String(estado.paginaHistorialCuenta),
            por_pagina: '10'
        });

        $('tablaHistorialCuenta').innerHTML = '<tr><td colspan="5" class="empty-cell">Cargando...</td></tr>';
        const datos = await api('?' + params.toString());
        const pagos = datos.pagos || [];
        estado.totalPaginasHistorialCuenta = datos.paginacion.total_paginas || 1;

        if (!pagos.length) {
            $('tablaHistorialCuenta').innerHTML = '<tr><td colspan="5" class="empty-cell">Esta cuenta todavía no tiene abonos.</td></tr>';
        } else {
            $('tablaHistorialCuenta').innerHTML = pagos.map(p =>
                '<tr>'
                + '<td><strong>' + escapeHtml(p.folio) + '</strong><small class="cell-secondary">' + fechaHora(p.fecha_pago) + '</small></td>'
                + '<td>' + escapeHtml(p.metodo_nombre) + '<small class="cell-secondary">' + escapeHtml(p.referencia || 'Sin referencia') + '</small></td>'
                + '<td><strong>' + dinero(p.importe_aplicado, p.moneda_codigo, p.moneda_simbolo) + '</strong></td>'
                + '<td>' + escapeHtml(p.usuario_nombre || p.usuario || '—') + '</td>'
                + '<td>' + (p.estado === 'APLICADO'
                    ? '<span class="status-badge status-badge--active">Aplicado</span>'
                    : '<span class="status-badge status-badge--inactive">Cancelado</span>') + '</td>'
                + '</tr>'
            ).join('');
        }

        renderPaginacion('HistorialCuenta', datos.paginacion);
    }

    /* ==============================================================
       ABONOS
       ============================================================== */

    async function cargarPagos() {
        const params = new URLSearchParams({
            cxp_api: '1',
            accion: 'LISTAR_PAGOS',
            pagina: String(estado.paginaPago),
            por_pagina: String(estado.porPaginaPago),
            busqueda: $('buscarPago').value.trim(),
            estado: $('filtroEstadoPago').value,
            metodo_id: $('filtroMetodoPago').value,
            desde: $('filtroDesdePago').value,
            hasta: $('filtroHastaPago').value
        });

        $('tablaPagos').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        const datos = await api('?' + params.toString());
        const pagos = datos.pagos || [];
        estado.totalPaginasPago = datos.paginacion.total_paginas || 1;

        if (!pagos.length) {
            $('tablaPagos').innerHTML = '<tr><td colspan="8" class="empty-cell">No se encontraron pagos.</td></tr>';
        } else {
            $('tablaPagos').innerHTML = pagos.map(p => {
                let acciones = botonAccion('Detalle', 'detalle-pago', p.id);

                if (puedePagar && p.estado === 'APLICADO') {
                    acciones += botonAccion('Cancelar', 'cancelar-pago', p.id, 'danger');
                }

                return '<tr>'
                    + '<td><strong>' + escapeHtml(p.folio) + '</strong><small class="cell-secondary">' + fechaHora(p.fecha_pago) + '</small></td>'
                    + '<td><strong>' + escapeHtml(p.proveedor) + '</strong><small class="cell-secondary">' + escapeHtml(p.proveedor_codigo) + '</small></td>'
                    + '<td>' + escapeHtml(p.cuentas_folios || '—') + '<small class="cell-secondary">' + escapeHtml(p.compras_folios || '—') + '</small></td>'
                    + '<td>' + escapeHtml(p.metodo_nombre) + '<small class="cell-secondary">' + escapeHtml(p.referencia || 'Sin referencia') + '</small></td>'
                    + '<td><strong>' + dinero(p.importe, p.moneda_codigo, p.moneda_simbolo) + '</strong></td>'
                    + '<td>' + escapeHtml(p.usuario_nombre || p.usuario || '—') + '</td>'
                    + '<td>' + (p.estado === 'APLICADO'
                        ? '<span class="status-badge status-badge--active">Aplicado</span>'
                        : '<span class="status-badge status-badge--inactive">Cancelado</span>') + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }).join('');
        }

        $('kpiPagosTotal').textContent = datos.resumen.total || 0;
        $('kpiPagosAplicados').textContent = datos.resumen.aplicados || 0;
        $('kpiPagosCancelados').textContent = datos.resumen.cancelados || 0;

        renderCurrencyStrip(
            $('totalesMonedaPagos'),
            datos.resumen.totales_por_moneda || [],
            item => 'Pagos aplicados: ' + dinero(item.importe_aplicado, item.codigo, item.simbolo)
                + ' · ' + item.pagos + ' movimiento(s)'
        );

        renderPaginacion('Pago', datos.paginacion);
    }

    async function abrirDetallePago(id) {
        const datos = await api('?cxp_api=1&accion=DETALLE_PAGO&id=' + encodeURIComponent(id));
        const p = datos.pago;
        estado.pagoDetalle = p;

        $('tituloDetallePago').textContent = p.folio;
        $('detallePago').innerHTML = [
            detalleItem('Proveedor', p.proveedor + ' · ' + p.proveedor_codigo),
            detalleItem('Fecha del pago', fechaHora(p.fecha_pago)),
            detalleItem('Método', p.metodo_nombre),
            detalleItem('Referencia', p.referencia || 'Sin referencia'),
            detalleItem('Importe', textoDinero(p.importe, p.moneda_codigo, p.moneda_simbolo)),
            detalleItem('Usuario responsable', p.usuario_nombre || p.usuario || '—'),
            detalleItem('Estado', p.estado === 'APLICADO' ? 'Aplicado' : 'Cancelado'),
            detalleItem('Tipo de cambio guardado', numero(p.tipo_cambio_a_base, 6)),
            detalleItem('Cancelado por', p.usuario_cancelo || '—')
        ].join('');

        if (p.observaciones) {
            $('detallePago').innerHTML += detalleItem('Observaciones', p.observaciones);
        }
        if (p.motivo_cancelacion) {
            $('detallePago').innerHTML += detalleItem('Motivo de cancelación', p.motivo_cancelacion);
        }

        const apps = datos.aplicaciones || [];
        $('aplicacionesPago').innerHTML = apps.length
            ? '<div class="application-list"><h3>Aplicación del pago</h3>'
                + apps.map(a => '<div class="application-row"><div><strong>'
                    + escapeHtml(a.cuenta_folio)
                    + '</strong><small>Compra ' + escapeHtml(a.compra_folio)
                    + (a.numero_factura ? ' · ' + escapeHtml(a.numero_factura) : '')
                    + '</small></div><strong>'
                    + numero(a.importe_aplicado, 2)
                    + ' ' + escapeHtml(p.moneda_codigo)
                    + '</strong></div>').join('')
                + '</div>'
            : '<div class="empty-inline">Sin aplicaciones relacionadas.</div>';

        if (puedePagar) {
            $('btnCancelarDesdeDetallePago').hidden = p.estado !== 'APLICADO';
        }

        abrirModal('modalPago');
    }

    /* ==============================================================
       REGISTRAR / CANCELAR PAGO
       ============================================================== */

    async function abrirAbono(cuentaId) {
        if (!puedePagar) return;

        const datos = await api('?cxp_api=1&accion=DETALLE_CUENTA&id=' + encodeURIComponent(cuentaId));
        const c = datos.cuenta;

        if (!c.puede_abonar) {
            throw new Error('Esta cuenta ya no admite abonos.');
        }

        estado.cuentaDetalle = c;
        $('formAbono').reset();
        $('abonoCuentaId').value = c.id;
        $('abonoImporte').value = Number(c.saldo_pendiente).toFixed(4);
        $('abonoImporte').max = Number(c.saldo_pendiente).toFixed(4);
        $('abonoFecha').value = ahoraLocalInput();
        $('abonoMoneda').value = c.moneda_codigo + ' · ' + (c.moneda_simbolo || '');
        $('simboloMonedaAbono').textContent = c.moneda_simbolo || '$';
        $('ayudaImporteAbono').textContent = 'Saldo disponible para pagar: ' + textoDinero(c.saldo_pendiente, c.moneda_codigo, c.moneda_simbolo) + '. Puedes registrar un abono menor.';

        $('resumenCuentaAbono').innerHTML = '<div><span>Cuenta</span><strong>' + escapeHtml(c.folio) + '</strong></div>'
            + '<div><span>Proveedor</span><strong>' + escapeHtml(c.proveedor) + '</strong></div>'
            + '<div><span>Compra</span><strong>' + escapeHtml(c.compra_folio) + '</strong></div>'
            + '<div><span>Vence</span><strong>' + fecha(c.fecha_vencimiento) + '</strong></div>'
            + '<div class="payment-account-summary__balance"><span>Saldo pendiente</span><strong>' + dinero(c.saldo_pendiente, c.moneda_codigo, c.moneda_simbolo) + '</strong></div>';

        ocultarMensaje($('mensajeAbono'));
        actualizarReferenciaMetodo();
        abrirModal('modalAbono');
    }

    function actualizarReferenciaMetodo() {
        if (!puedePagar) return;

        const id = Number($('abonoMetodo').value || 0);
        const metodo = estado.metodos.find(m => m.id === id);
        const requiere = metodo && metodo.requiere_referencia === 1;

        $('abonoReferencia').required = !!requiere;
        $('ayudaReferenciaAbono').textContent = requiere
            ? 'Obligatoria para ' + metodo.nombre + '. Captura el número de operación, cheque o autorización.'
            : 'Opcional para este método de pago.';
    }

    async function guardarAbono(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const boton = form.querySelector('button[type="submit"]');
        const original = boton.textContent;
        boton.disabled = true;
        boton.textContent = 'Registrando...';
        ocultarMensaje($('mensajeAbono'));

        try {
            const datos = await api('?cxp_api=1', {
                method: 'POST',
                body: new FormData(form)
            });

            cerrarModal('modalAbono');
            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');

            if (!$('modalCuenta').hidden && estado.cuentaDetalle) {
                await abrirDetalleCuenta(estado.cuentaDetalle.id);
            }

            await cargarCuentas();

            if (estado.seccion === 'abonos') {
                await cargarPagos();
            } else if (estado.seccion === 'vencimientos') {
                await cargarVencimientos();
            }
        } catch (error) {
            mostrarMensaje($('mensajeAbono'), error.message, 'error');
        } finally {
            boton.disabled = false;
            boton.textContent = original;
        }
    }

    function prepararCancelarPago(id) {
        if (!puedePagar) return;
        $('formCancelarPago').reset();
        $('cancelarPagoId').value = id;
        ocultarMensaje($('mensajeCancelarPago'));
        abrirModal('modalCancelarPago');
    }

    async function cancelarPago(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const boton = form.querySelector('button[type="submit"]');
        const original = boton.textContent;
        boton.disabled = true;
        boton.textContent = 'Cancelando...';
        ocultarMensaje($('mensajeCancelarPago'));

        try {
            const datos = await api('?cxp_api=1', {
                method: 'POST',
                body: new FormData(form)
            });

            cerrarModal('modalCancelarPago');
            cerrarModal('modalPago');
            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');

            await cargarCuentas();
            if (estado.seccion === 'abonos') await cargarPagos();
            if (estado.seccion === 'vencimientos') await cargarVencimientos();
        } catch (error) {
            mostrarMensaje($('mensajeCancelarPago'), error.message, 'error');
        } finally {
            boton.disabled = false;
            boton.textContent = original;
        }
    }

    /* ==============================================================
       VENCIMIENTOS
       ============================================================== */

    async function cargarVencimientos() {
        const params = new URLSearchParams({
            cxp_api: '1',
            accion: 'VENCIMIENTOS',
            pagina: String(estado.paginaVencimiento),
            por_pagina: String(estado.porPaginaVencimiento),
            busqueda: $('buscarVencimiento').value.trim(),
            horizonte: $('filtroHorizonteVencimiento').value,
            moneda_id: $('filtroMonedaVencimiento').value
        });

        $('tablaVencimientos').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        const datos = await api('?' + params.toString());
        const cuentas = datos.cuentas || [];
        estado.totalPaginasVencimiento = datos.paginacion.total_paginas || 1;

        if (!cuentas.length) {
            $('tablaVencimientos').innerHTML = '<tr><td colspan="8" class="empty-cell">No hay vencimientos en el periodo seleccionado.</td></tr>';
        } else {
            $('tablaVencimientos').innerHTML = cuentas.map(c => {
                let acciones = botonAccion('Detalle', 'detalle-cuenta', c.id);
                if (puedePagar && Number(c.saldo_pendiente) > 0) {
                    acciones += botonAccion('Abonar', 'abonar-cuenta', c.id, 'success');
                }

                return '<tr class="' + (Number(c.dias_vencimiento) < 0 ? 'row-overdue' : '') + '">'
                    + '<td><strong>' + fecha(c.fecha_vencimiento) + '</strong><small class="cell-secondary">' + vencimientoHtml(c) + '</small></td>'
                    + '<td><strong>' + escapeHtml(c.proveedor) + '</strong><small class="cell-secondary">' + escapeHtml(c.proveedor_codigo) + '</small></td>'
                    + '<td><strong>' + escapeHtml(c.folio) + '</strong><small class="cell-secondary">Compra ' + escapeHtml(c.compra_folio) + '</small></td>'
                    + '<td>' + escapeHtml(c.numero_factura || 'Sin factura / documento') + '</td>'
                    + '<td>' + dinero(c.importe_pagado, c.moneda_codigo, c.moneda_simbolo) + '</td>'
                    + '<td><strong>' + dinero(c.saldo_pendiente, c.moneda_codigo, c.moneda_simbolo) + '</strong></td>'
                    + '<td>' + estadoCuentaHtml(c) + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }).join('');
        }

        renderCurrencyStrip(
            $('totalesMonedaVencimientos'),
            datos.totales_por_moneda || [],
            item => 'Compromiso del periodo: ' + dinero(item.saldo, item.codigo, item.simbolo)
                + ' · ' + item.cuentas + ' cuenta(s)'
        );

        renderPaginacion('Vencimiento', datos.paginacion);
    }

    /* ==============================================================
       COMUNES
       ============================================================== */

    function renderPaginacion(prefijo, p) {
        $('textoPagina' + prefijo).textContent = (p.total_registros || 0) + ' registro(s)';
        $('pagina' + prefijo + 'Actual').textContent = 'Página ' + p.pagina + ' de ' + p.total_paginas;
        $('btn' + prefijo + 'Anterior').disabled = p.pagina <= 1;
        $('btn' + prefijo + 'Siguiente').disabled = p.pagina >= p.total_paginas;
    }

    function renderCurrencyStrip(elemento, items, callback) {
        if (!items.length) {
            elemento.innerHTML = '<div class="currency-empty">Sin saldos para mostrar.</div>';
            return;
        }

        elemento.innerHTML = items.map(item =>
            '<article><span>' + escapeHtml(item.codigo) + '</span><strong>' + callback(item) + '</strong></article>'
        ).join('');
    }

    function textoDinero(valor, codigo, simbolo) {
        return (simbolo || '') + numero(valor, 2) + ' ' + (codigo || '');
    }

    function textoVencimiento(c) {
        const dias = Number(c.dias_vencimiento || 0);
        if (dias < 0) return 'venció hace ' + Math.abs(dias) + ' día(s)';
        if (dias === 0) return 'vence hoy';
        return 'vence en ' + dias + ' día(s)';
    }

    function etiquetaEstadoTexto(c) {
        const e = c.estado_calculado;
        if (e === 'PENDIENTE') return 'Pendiente';
        if (e === 'PARCIAL') return 'Parcialmente pagada';
        if (e === 'PAGADA') return 'Pagada';
        if (e === 'VENCIDA') return Number(c.importe_pagado || 0) > 0 ? 'Vencida con abonos' : 'Vencida';
        if (e === 'CANCELADA') return 'Cancelada';
        return e;
    }

    function detalleItem(etiqueta, valor) {
        return '<div><span>' + escapeHtml(etiqueta) + '</span><strong>' + escapeHtml(valor == null ? '—' : valor) + '</strong></div>';
    }

    document.querySelectorAll('.module-tab').forEach(tab => {
        tab.addEventListener('click', () => cambiarSeccion(tab.dataset.seccion));
    });

    document.querySelectorAll('[data-cerrar-modal]').forEach(btn => {
        btn.addEventListener('click', () => cerrarModal(btn.dataset.cerrarModal));
    });

    document.querySelectorAll('.modal-backdrop').forEach(modal => {
        modal.addEventListener('click', event => {
            if (event.target === modal) cerrarModal(modal.id);
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(modal => cerrarModal(modal.id));
    });

    $('tablaCuentas').addEventListener('click', event => {
        const b = event.target.closest('[data-action]');
        if (!b) return;
        const id = Number(b.dataset.id);
        if (b.dataset.action === 'detalle-cuenta') abrirDetalleCuenta(id).catch(mostrarError);
        if (b.dataset.action === 'abonar-cuenta') abrirAbono(id).catch(mostrarError);
    });

    $('tablaVencimientos').addEventListener('click', event => {
        const b = event.target.closest('[data-action]');
        if (!b) return;
        const id = Number(b.dataset.id);
        if (b.dataset.action === 'detalle-cuenta') abrirDetalleCuenta(id).catch(mostrarError);
        if (b.dataset.action === 'abonar-cuenta') abrirAbono(id).catch(mostrarError);
    });

    $('tablaPagos').addEventListener('click', event => {
        const b = event.target.closest('[data-action]');
        if (!b) return;
        const id = Number(b.dataset.id);
        if (b.dataset.action === 'detalle-pago') abrirDetallePago(id).catch(mostrarError);
        if (b.dataset.action === 'cancelar-pago') prepararCancelarPago(id);
    });

    if (puedePagar) {
        $('btnAbonarDesdeDetalle').addEventListener('click', () => {
            if (!estado.cuentaDetalle) return;
            cerrarModal('modalCuenta');
            abrirAbono(estado.cuentaDetalle.id).catch(mostrarError);
        });

        $('abonoMetodo').addEventListener('change', actualizarReferenciaMetodo);

        $('btnLiquidarSaldo').addEventListener('click', () => {
            if (!estado.cuentaDetalle) return;
            $('abonoImporte').value = Number(estado.cuentaDetalle.saldo_pendiente).toFixed(4);
        });

        $('formAbono').addEventListener('submit', guardarAbono);
        $('formCancelarPago').addEventListener('submit', cancelarPago);

        $('btnCancelarDesdeDetallePago').addEventListener('click', () => {
            if (!estado.pagoDetalle) return;
            cerrarModal('modalPago');
            prepararCancelarPago(estado.pagoDetalle.id);
        });
    }

    function debounce(timerKey, callback) {
        clearTimeout(estado[timerKey]);
        estado[timerKey] = setTimeout(callback, 320);
    }

    $('buscarCuenta').addEventListener('input', () => debounce('timerCuenta', () => {
        estado.paginaCuenta = 1;
        cargarCuentas().catch(mostrarError);
    }));

    ['filtroEstadoCuenta', 'filtroVencimientoCuenta', 'filtroMonedaCuenta'].forEach(id => {
        $(id).addEventListener('change', () => {
            estado.paginaCuenta = 1;
            cargarCuentas().catch(mostrarError);
        });
    });

    $('porPaginaCuenta').addEventListener('change', event => {
        estado.porPaginaCuenta = Number(event.target.value);
        estado.paginaCuenta = 1;
        cargarCuentas().catch(mostrarError);
    });

    $('btnCuentaAnterior').addEventListener('click', () => {
        if (estado.paginaCuenta <= 1) return;
        estado.paginaCuenta--;
        cargarCuentas().catch(mostrarError);
    });

    $('btnCuentaSiguiente').addEventListener('click', () => {
        if (estado.paginaCuenta >= estado.totalPaginasCuenta) return;
        estado.paginaCuenta++;
        cargarCuentas().catch(mostrarError);
    });

    $('buscarPago').addEventListener('input', () => debounce('timerPago', () => {
        estado.paginaPago = 1;
        cargarPagos().catch(mostrarError);
    }));

    ['filtroEstadoPago', 'filtroMetodoPago', 'filtroDesdePago', 'filtroHastaPago'].forEach(id => {
        $(id).addEventListener('change', () => {
            estado.paginaPago = 1;
            cargarPagos().catch(mostrarError);
        });
    });

    $('porPaginaPago').addEventListener('change', event => {
        estado.porPaginaPago = Number(event.target.value);
        estado.paginaPago = 1;
        cargarPagos().catch(mostrarError);
    });

    $('btnPagoAnterior').addEventListener('click', () => {
        if (estado.paginaPago <= 1) return;
        estado.paginaPago--;
        cargarPagos().catch(mostrarError);
    });

    $('btnPagoSiguiente').addEventListener('click', () => {
        if (estado.paginaPago >= estado.totalPaginasPago) return;
        estado.paginaPago++;
        cargarPagos().catch(mostrarError);
    });

    $('buscarVencimiento').addEventListener('input', () => debounce('timerVencimiento', () => {
        estado.paginaVencimiento = 1;
        cargarVencimientos().catch(mostrarError);
    }));

    ['filtroHorizonteVencimiento', 'filtroMonedaVencimiento'].forEach(id => {
        $(id).addEventListener('change', () => {
            estado.paginaVencimiento = 1;
            cargarVencimientos().catch(mostrarError);
        });
    });

    $('porPaginaVencimiento').addEventListener('change', event => {
        estado.porPaginaVencimiento = Number(event.target.value);
        estado.paginaVencimiento = 1;
        cargarVencimientos().catch(mostrarError);
    });

    $('btnVencimientoAnterior').addEventListener('click', () => {
        if (estado.paginaVencimiento <= 1) return;
        estado.paginaVencimiento--;
        cargarVencimientos().catch(mostrarError);
    });

    $('btnVencimientoSiguiente').addEventListener('click', () => {
        if (estado.paginaVencimiento >= estado.totalPaginasVencimiento) return;
        estado.paginaVencimiento++;
        cargarVencimientos().catch(mostrarError);
    });

    $('btnHistorialCuentaAnterior').addEventListener('click', () => {
        if (estado.paginaHistorialCuenta <= 1) return;
        estado.paginaHistorialCuenta--;
        cargarHistorialCuenta().catch(mostrarError);
    });

    $('btnHistorialCuentaSiguiente').addEventListener('click', () => {
        if (estado.paginaHistorialCuenta >= estado.totalPaginasHistorialCuenta) return;
        estado.paginaHistorialCuenta++;
        cargarHistorialCuenta().catch(mostrarError);
    });

    async function iniciar() {
        try {
            await cargarCatalogos();
            cambiarSeccion(seccionInicial);
        } catch (error) {
            mostrarError(error);
        }
    }

    iniciar();
})();
</script>
</body>
</html>
