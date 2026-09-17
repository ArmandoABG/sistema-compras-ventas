<?php

declare(strict_types=1);

if (isset($_GET['ventas_api'])) {
    $endpoint = __DIR__ . '/../funciones/ventas_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/ventas_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('ventas.ver', false);
si_refrescar_identidad_sesion_actual();

$tituloPagina = 'Ventas';
$csrfToken = si_token_csrf();
$puedeCrear = si_tiene_permiso('ventas.crear');
$puedeCancelar = si_tiene_permiso('ventas.cancelar');
$puedePrecioManual = si_tiene_permiso('ventas.precio_manual');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_ventas.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$cotizacionInicial = filter_input(INPUT_GET, 'cotizacion_id', FILTER_VALIDATE_INT) ?: 0;
$apartadoInicial = filter_input(INPUT_GET, 'apartado_id', FILTER_VALIDATE_INT) ?: 0;
?>
<!doctype html>
<html lang="es" data-theme="<?= si_escapar(si_tema_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ventas | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_ventas.css?v=<?= si_escapar($versionModulo) ?>">
    <script src="../inc/ui_modulos.js?v=20260917-06"></script>
</head>
<body class="ventas-body">
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>
        <main class="page-content ventas-page">
            <section class="ventas-workspace">
                <header class="ventas-workspace__header">
                    <div>
                        <span>GESTIÓN COMERCIAL</span>
                        <h1>Ventas</h1>
                        <p>Consulta y administra las operaciones de venta.</p>
                    </div>
                    <?php if ($puedeCrear): ?>
                        <button type="button" class="btn-primary" id="btnNuevaVenta">Nueva venta</button>
                    <?php endif; ?>
                </header>

            <div id="mensajePagina" class="ventas-toast" role="status" aria-live="polite" hidden></div>

            <section class="stats-grid stats-grid--6">
                <article><span>Total</span><strong id="kpiTotal">0</strong></article>
                <article><span>Confirmadas</span><strong id="kpiConfirmadas">0</strong></article>
                <article><span>Contado</span><strong id="kpiContado">0</strong></article>
                <article><span>Crédito</span><strong id="kpiCredito">0</strong></article>
                <article><span>Canceladas</span><strong id="kpiCanceladas">0</strong></article>
                <article><span>Importe confirmado</span><strong id="kpiImporte">$0.00</strong></article>
            </section>

            <section class="module-card">
                <header class="ventas-list-heading">
                    <div>
                        <span>HISTORIAL COMERCIAL</span>
                        <h2>Operaciones registradas</h2>
                    </div>
                    <p>Filtra y gestiona el historial.</p>
                </header>

                <div class="filters-grid filters-grid--ventas">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input type="search" id="buscarVenta" maxlength="180" placeholder="Folio, cliente, cotización o apartado" autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Estado</span>
                        <select id="filtroEstado">
                            <option value="TODOS">Todos</option>
                            <option value="CONFIRMADA">Confirmada</option>
                            <option value="CANCELADA">Cancelada</option>
                            <option value="BORRADOR">Borrador</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Condición</span>
                        <select id="filtroCondicion">
                            <option value="TODAS">Todas</option>
                            <option value="CONTADO">Contado</option>
                            <option value="CREDITO">Crédito</option>
                        </select>
                    </label>
                    <label class="field"><span>Desde</span><input type="date" id="filtroDesde"></label>
                    <label class="field"><span>Hasta</span><input type="date" id="filtroHasta"></label>
                    <label class="field">
                        <span>Por página</span>
                        <select id="porPagina">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                </div>

                <div class="table-wrap">
                    <table class="module-table module-table--ventas">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Cliente</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                                <th>Total</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaVentas"><tr><td colspan="6" class="empty-cell">Cargando...</td></tr></tbody>
                    </table>
                </div>

                <footer class="pagination">
                    <span id="textoPagina">0 registros</span>
                    <div>
                        <button type="button" class="btn-secondary" id="btnAnterior">Anterior</button>
                        <span id="paginaActual">Página 1 de 1</span>
                        <button type="button" class="btn-secondary" id="btnSiguiente">Siguiente</button>
                    </div>
                </footer>
            </section>
            </section>
        </main>
    </div>
</div>

<!-- Nueva venta -->
<div class="modal-backdrop" id="modalVenta" hidden>
    <section class="modal-card modal-card--venta" role="dialog" aria-modal="true" aria-labelledby="tituloModalVenta">
        <header class="modal-header">
            <div>
                <h2 id="tituloModalVenta">Nueva venta</h2>
                <p id="subtituloModalVenta">El inventario se reserva o descuenta según la validación QR.</p>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalVenta" aria-label="Cerrar">×</button>
        </header>

        <form id="formVenta" class="venta-form" autocomplete="off">
            <div id="mensajeVenta" class="module-message" hidden></div>
            <div id="bannerOrigen" class="conversion-banner" hidden></div>

            <section class="venta-header-grid">
                <label class="field field--client-search">
                    <span>Cliente <small id="clienteRequeridoTexto">(opcional en contado)</small></span>
                    <input type="search" id="buscarClienteVenta" placeholder="Código, nombre, RFC o teléfono" autocomplete="off">
                    <input type="hidden" id="ventaClienteId">
                    <div id="resultadosClientesVenta" class="smart-results" hidden></div>
                </label>
                <label class="field">
                    <span>Almacén *</span>
                    <select id="ventaAlmacen" required></select>
                </label>
                <label class="field">
                    <span>Moneda *</span>
                    <select id="ventaMoneda" required></select>
                </label>
                <label class="field">
                    <span>Condición de pago *</span>
                    <select id="ventaCondicion" required>
                        <option value="CONTADO">Contado</option>
                        <option value="CREDITO">Crédito</option>
                    </select>
                </label>
            </section>

            <div class="si-tc-panel" data-si-tipo-cambio data-endpoint="../funciones/alertas_funciones.php" data-csrf="<?= si_escapar($csrfToken) ?>">
                <div class="si-tc-panel__text">
                    <span>FIX actual USD/MXN</span>
                    <strong data-si-tc-resumen>Consultando FIX...</strong>
                    <small data-si-tc-detalle>Banco de México SIE</small>
                </div>
                <button type="button" class="btn-secondary" data-si-tc-actualizar>Actualizar dólar</button>
            </div>

            <section class="client-summary" id="resumenClienteVenta">
                <div><span>Cliente</span><strong id="clienteNombreVenta">Público general</strong><small id="clienteCodigoVenta">Sin cliente seleccionado.</small></div>
                <div><span>Clasificación</span><strong id="clienteNivelVenta">—</strong><small>Determina el descuento comercial.</small></div>
                <div><span>Descuento</span><strong id="clienteDescuentoVenta">0.00%</strong><small>Se conserva como histórico.</small></div>
                <div><span>Crédito</span><strong id="clienteCreditoVenta">No configurado</strong><small id="clienteCreditoDetalle">Crédito validado al confirmar.</small></div>
            </section>

            <section class="venta-products">
                <div class="venta-products__heading">
                    <div>
                        <h3>Productos</h3>
                        <p id="textoOrigenLineasVenta">Agrega productos y verifica su disponibilidad.</p>
                    </div>
                    <label class="field product-search-field" id="contenedorBuscarProductoVenta">
                        <span>Agregar producto</span>
                        <input type="search" id="buscarProductoVenta" placeholder="SKU, nombre o código de barras" autocomplete="off">
                        <div id="resultadosProductosVenta" class="smart-results smart-results--wide" hidden></div>
                    </label>
                </div>

                <div id="alertaStockVenta" class="stock-assist" hidden></div>

                <div class="table-wrap line-table-wrap">
                    <table class="module-table line-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Presentación</th>
                                <th>Cantidad</th>
                                <th>Precio</th>
                                <th>Nivel</th>
                                <th>Desc.</th>
                                <th>Impuesto</th>
                                <th>Disponible / requerido</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tablaLineasVenta"><tr><td colspan="10" class="empty-cell">Agrega productos a la venta.</td></tr></tbody>
                    </table>
                </div>
            </section>

            <section class="venta-bottom-grid">
                <label class="field observations-field">
                    <span>Observaciones</span>
                    <textarea id="ventaObservaciones" rows="5" maxlength="3000" placeholder="Notas internas de la venta"></textarea>
                </label>

                <div class="payment-card" id="tarjetaPago">
                    <h3>Liquidación</h3>
                    <div class="payment-grid" id="camposPagoContado">
                        <label class="field">
                            <span>Método de pago *</span>
                            <select id="ventaMetodoPago"><option value="0">Seleccionar</option></select>
                        </label>
                        <label class="field">
                            <span>Referencia</span>
                            <input type="text" id="ventaReferenciaPago" maxlength="120" placeholder="Folio / referencia">
                        </label>
                    </div>
                    <div id="resumenCredito" class="credit-box" hidden></div>
                    <div id="resumenAnticipo" class="advance-box" hidden></div>
                    <small id="textoPagoAyuda">La venta de contado debe quedar liquidada al confirmarse.</small>
                </div>

                <aside class="totals-card">
                    <div><span>Subtotal neto</span><strong id="ventaSubtotal">$0.00</strong></div>
                    <div><span>Descuento</span><strong id="ventaDescuento">$0.00</strong></div>
                    <div><span>Impuestos</span><strong id="ventaImpuesto">$0.00</strong></div>
                    <div id="filaAnticipoTotal" hidden><span>Anticipos aplicados</span><strong id="ventaAnticipo">$0.00</strong></div>
                    <div class="totals-card__grand"><span>Total venta</span><strong id="ventaTotal">$0.00</strong></div>
                    <div class="totals-card__balance"><span id="etiquetaSaldo">A liquidar</span><strong id="ventaSaldo">$0.00</strong></div>
                </aside>
            </section>
        </form>

        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalVenta">Cerrar</button>
            <?php if ($puedeCrear): ?><button type="button" class="btn-primary" id="btnConfirmarVenta">Confirmar venta</button><?php endif; ?>
        </footer>
    </section>
</div>

<!-- Detalle -->
<div class="modal-backdrop" id="modalDetalleVenta" hidden>
    <section class="modal-card modal-card--detail" role="dialog" aria-modal="true" aria-labelledby="tituloDetalleVenta">
        <header class="modal-header">
            <div><h2 id="tituloDetalleVenta">Detalle de venta</h2><p id="subtituloDetalleVenta"></p></div>
            <button type="button" class="modal-close" data-cerrar-modal="modalDetalleVenta" aria-label="Cerrar">×</button>
        </header>
        <div class="detail-body">
            <div id="mensajeDetalleVenta" class="module-message" hidden></div>
            <div class="detail-section-heading detail-section-heading--overview"><h3>Información general</h3></div>
            <section class="detail-summary-grid" id="resumenDetalleVenta"></section>

            <section class="detail-section-grid">
                <article class="detail-panel">
                    <div class="detail-section-heading"><h3>Cliente</h3></div>
                    <div id="detalleClienteVenta" class="detail-data-grid"></div>
                </article>
                <article class="detail-panel">
                    <div class="detail-section-heading"><h3>Origen e inventario</h3></div>
                    <div id="detalleOrigenVenta" class="detail-data-grid"></div>
                </article>
            </section>

            <section class="detail-panel detail-panel--wide">
                <div class="detail-section-heading"><h3>Datos financieros</h3></div>
                <div id="detalleFinanciero" class="financial-summary detail-data-grid detail-data-grid--financial"></div>
            </section>

            <section class="detail-panel detail-panel--wide">
                <div class="detail-section-heading"><h3>Productos</h3><p>Condiciones aplicadas al confirmar.</p></div>
                <div class="table-wrap">
                    <table class="module-table detail-table detail-table--products">
                        <thead><tr><th>Producto</th><th>Almacén</th><th>Cantidad</th><th>Precio</th><th>Desc.</th><th>Impuesto</th><th>Total</th></tr></thead>
                        <tbody id="tablaDetalleVentaProductos"></tbody>
                    </table>
                </div>
            </section>

            <section class="detail-panel detail-panel--wide">
                <div class="detail-section-heading"><h3>Pagos aplicados</h3></div>
                <div class="table-wrap">
                    <table class="module-table detail-table detail-table--payments">
                        <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Importe</th><th>Estado</th><th>Usuario</th></tr></thead>
                        <tbody id="tablaPagosVenta"></tbody>
                    </table>
                </div>
            </section>

            <section class="detail-panel detail-panel--wide" id="detalleNotasSection">
                <div class="detail-section-heading"><h3>Notas y seguimiento</h3></div>
                <div id="detalleNotasVenta" class="detail-note"></div>
            </section>
        </div>
        <footer class="modal-footer modal-footer--spread">
            <div>
                <a class="btn-secondary btn-link" id="btnImprimirVenta" target="_blank" href="#">Imprimir comprobante</a>
            </div>
            <div>
                <?php if ($puedeCancelar): ?><button type="button" class="btn-danger" id="btnCancelarVenta">Cancelar venta</button><?php endif; ?>
                <button type="button" class="btn-secondary" data-cerrar-modal="modalDetalleVenta">Cerrar</button>
            </div>
        </footer>
    </section>
</div>

<!-- Confirmaciones de Ventas -->
<div class="modal-backdrop modal-backdrop--confirm" id="modalConfirmacionVentas" data-modal-static hidden>
    <section class="modal-card modal-card--confirm" role="alertdialog" aria-modal="true" aria-labelledby="tituloConfirmacionVentas" aria-describedby="textoConfirmacionVentas">
        <header class="modal-header">
            <div>
                <span class="confirm-dialog__eyebrow">CONFIRMACIÓN</span>
                <h2 id="tituloConfirmacionVentas">Confirmar operación</h2>
            </div>
            <button type="button" class="modal-close" id="btnCerrarConfirmacionVentas" aria-label="Cerrar">×</button>
        </header>
        <div class="confirm-dialog__body">
            <span class="confirm-dialog__icon" id="iconoConfirmacionVentas" aria-hidden="true">✓</span>
            <p id="textoConfirmacionVentas"></p>
            <label class="field confirm-dialog__reason" id="grupoMotivoConfirmacionVentas" hidden>
                <span>Motivo de cancelación *</span>
                <textarea id="motivoConfirmacionVentas" rows="4" maxlength="3000" placeholder="Describe brevemente el motivo"></textarea>
                <small>Captura al menos 5 caracteres.</small>
            </label>
            <div id="mensajeConfirmacionVentas" class="module-message" hidden></div>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn-secondary" id="btnCancelarConfirmacionVentas">Volver</button>
            <button type="button" class="btn-primary" id="btnAceptarConfirmacionVentas">Confirmar</button>
        </footer>
    </section>
</div>

<script src="../inc/tipo_cambio_ui.js?v=20260902-09"></script>
<script src="../inc/idempotencia_cliente.js?v=20260904-01"></script>

<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const puedeCrear = <?= $puedeCrear ? 'true' : 'false' ?>;
    const puedeCancelar = <?= $puedeCancelar ? 'true' : 'false' ?>;
    const puedePrecioManual = <?= $puedePrecioManual ? 'true' : 'false' ?>;
    const cotizacionInicial = <?= (int) $cotizacionInicial ?>;
    const apartadoInicial = <?= (int) $apartadoInicial ?>;
    const $ = (id) => document.getElementById(id);

    const estado = {
        catalogos: { almacenes: [], monedas: [], metodos: [] },
        ventas: [], pagina: 1, totalPaginas: 1, porPagina: 20,
        origen: 'DIRECTO', origenId: 0, fuente: null, anticipos: [],
        cliente: null, lineas: [], secuencia: 1, detalle: null,
        timerBusqueda: null, timerCliente: null, timerProducto: null,
        timersPrecio: {}, solicitudPrecio: {}, cargandoOrigen: false,
    };
    const temporizadoresMensajes = new Map();
    let confirmacionPendiente = null;
    let configuracionConfirmacion = null;
    let selectVentasActivo = null;
    let menuSelectVentas = null;

    function escapeHtml(valor) {
        return String(valor ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    }

    function numero(valor, decimales) {
        return Number(valor || 0).toLocaleString('es-MX', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
    }

    function moneda(valor, codigo, simbolo) {
        const prefijo = simbolo || (codigo === 'USD' ? 'US$' : '$');
        return prefijo + numero(valor, 2) + (codigo ? ' ' + codigo : '');
    }

    function fechaCorta(valor) {
        if (!valor) return '—';
        const d = new Date(String(valor).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? escapeHtml(valor) : d.toLocaleDateString('es-MX');
    }

    function fechaHora(valor) {
        if (!valor) return '—';
        const d = new Date(String(valor).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? escapeHtml(valor) : d.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
    }

    function estadoVisual(valor) {
        return ({
            CONFIRMADA: ['Confirmada', 'success'], PENDIENTE_SALIDA: ['Pendiente de salida', 'warning'], CANCELADA: ['Cancelada', 'danger'], BORRADOR: ['Borrador', 'neutral'],
            PAGADA: ['Pagada', 'success'], PENDIENTE: ['Pendiente', 'warning'], PARCIAL: ['Parcial', 'warning'],
            VENCIDA: ['Vencida', 'danger'], APLICADO: ['Aplicado', 'success'], CREDITO: ['Crédito', 'warning'], CONTADO: ['Contado', 'active']
        })[valor] || [valor || '—', 'neutral'];
    }

    function badge(valor) {
        const e = estadoVisual(valor);
        return '<span class="status-badge status-badge--' + e[1] + '">' + escapeHtml(e[0]) + '</span>';
    }

    function datoDetalle(etiqueta, valorHtml, ayuda = '') {
        return '<div><span>' + escapeHtml(etiqueta) + '</span><strong>' + valorHtml + '</strong>'
            + (ayuda ? '<small>' + ayuda + '</small>' : '') + '</div>';
    }

    function almacenActual() {
        const id = Number($('ventaAlmacen')?.value || 0);
        return estado.catalogos.almacenes.find((a) => Number(a.id) === id) || null;
    }

    function stockAlmacenesHtml(linea, requerido = 0) {
        if (Number(linea.controla_inventario) !== 1) {
            return '<span class="stock-indicator">Sin control de inventario</span>';
        }

        const almacenId = Number(linea.almacen_id || $('ventaAlmacen')?.value || 0);
        const almacenes = Array.isArray(linea.stock_almacenes) ? linea.stock_almacenes : [];
        const actual = almacenes.find((a) => Number(a.almacen_id) === almacenId);
        const disponibleActual = actual ? Number(actual.cantidad_disponible || 0) : Number(linea.disponible || 0);
        const total = Number(linea.stock_total_disponible ?? disponibleActual);
        const unidad = escapeHtml(linea.unidad_base_simbolo || linea.unidad_base_codigo || '');
        const suficiente = disponibleActual + 0.000001 >= Number(requerido || 0);
        const nombreActual = actual?.almacen_nombre || almacenActual()?.nombre || 'Almacén seleccionado';
        const otrosConStock = almacenes.filter((a) => Number(a.almacen_id) !== almacenId && Number(a.cantidad_disponible || 0) > 0.000001);
        const alternativaSuficiente = otrosConStock.find((a) => Number(a.cantidad_disponible || 0) + 0.000001 >= Number(requerido || 0));

        let html = '<div class="stock-cell">'
            + '<div class="stock-cell__top">'
            + '<span class="stock-indicator ' + (suficiente ? 'stock-indicator--ok' : 'stock-indicator--bad') + '">'
            + escapeHtml(nombreActual) + ': ' + numero(disponibleActual, 3) + ' / ' + numero(requerido, 3) + ' ' + unidad
            + '</span>'
            + '<span class="stock-total-badge">Total empresa: ' + numero(total, 3) + ' ' + unidad + '</span>'
            + '</div>';

        if (!suficiente) {
            if (otrosConStock.length) {
                html += '<small class="stock-cell__hint">Hay existencia en ' + otrosConStock.length + ' almacén' + (otrosConStock.length === 1 ? '' : 'es') + ' adicional' + (otrosConStock.length === 1 ? '' : 'es') + '.</small>';
            } else {
                html += '<small class="stock-cell__hint stock-cell__hint--danger">No hay disponibilidad en otros almacenes.</small>';
            }
        }

        if (almacenes.length > 1) {
            html += '<details class="warehouse-stock-details"><summary>Ver stock por almacén</summary><div class="warehouse-stock-list">'
                + almacenes.map((a) => {
                    const disp = Number(a.cantidad_disponible || 0);
                    const esActual = Number(a.almacen_id) === almacenId;
                    return '<div class="warehouse-stock-item ' + (esActual ? 'warehouse-stock-item--selected' : '') + '">'
                        + '<span><strong>' + escapeHtml(a.almacen_nombre || a.almacen_codigo || 'Almacén') + '</strong><small>'
                        + 'Físico ' + numero(a.existencia_fisica, 3) + ' · Reservado ' + numero(a.cantidad_reservada, 3) + '</small></span>'
                        + '<b>' + numero(disp, 3) + ' ' + unidad + '</b></div>';
                }).join('')
                + '</div></details>';
        }

        if (!suficiente && alternativaSuficiente && estado.origen !== 'APARTADO') {
            html += '<button type="button" class="stock-switch-btn" data-switch-warehouse="' + Number(alternativaSuficiente.almacen_id) + '">Usar ' + escapeHtml(alternativaSuficiente.almacen_nombre || alternativaSuficiente.almacen_codigo) + '</button>';
        }

        return html + '</div>';
    }

    function ocultarAlertaStock() {
        const el = $('alertaStockVenta');
        if (!el) return;
        el.hidden = true;
        el.innerHTML = '';
    }

    function mostrarAlertaStock(linea, requerido, mensaje = '') {
        const el = $('alertaStockVenta');
        if (!el || !linea) return;
        const almacenId = Number(linea.almacen_id || $('ventaAlmacen')?.value || 0);
        const almacenes = Array.isArray(linea.stock_almacenes) ? linea.stock_almacenes : [];
        const actual = almacenes.find((a) => Number(a.almacen_id) === almacenId);
        const disponible = actual ? Number(actual.cantidad_disponible || 0) : Number(linea.disponible || 0);
        const total = Number(linea.stock_total_disponible ?? disponible);
        const unidad = escapeHtml(linea.unidad_base_simbolo || linea.unidad_base_codigo || '');
        const otros = almacenes.filter((a) => Number(a.almacen_id) !== almacenId && Number(a.cantidad_disponible || 0) > 0.000001);

        el.innerHTML = '<div class="stock-assist__icon">!</div><div class="stock-assist__body">'
            + '<strong>' + escapeHtml(mensaje || ('Stock insuficiente para ' + linea.nombre)) + '</strong>'
            + '<p>Necesitas <b>' + numero(requerido, 3) + ' ' + unidad + '</b>; en el almacén seleccionado hay <b>' + numero(disponible, 3) + '</b> y en toda la empresa hay <b>' + numero(total, 3) + ' ' + unidad + '</b> disponibles.</p>'
            + (otros.length ? '<div class="stock-assist__warehouses">' + otros.map((a) =>
                '<span>' + escapeHtml(a.almacen_nombre || a.almacen_codigo) + ': <b>' + numero(a.cantidad_disponible, 3) + ' ' + unidad + '</b></span>'
            ).join('') + '</div>' : '<p class="stock-assist__empty">No hay existencia disponible en otros almacenes.</p>')
            + '<small>El sistema no moverá mercancía automáticamente: puedes cambiar el almacén de la venta o registrar una transferencia.</small>'
            + '</div>';
        el.hidden = false;
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    async function refrescarStockLineas() {
        if (estado.origen === 'APARTADO' || !estado.lineas.length) return;
        const almacenId = Number($('ventaAlmacen').value || 0);
        if (!(almacenId > 0)) return;
        const ids = [...new Set(estado.lineas.filter((l) => Number(l.controla_inventario) === 1).map((l) => Number(l.producto_id)).filter((id) => id > 0))];
        if (!ids.length) return;

        const r = await apiGet('STOCK_PRODUCTOS', { almacen_id: almacenId, producto_ids: ids.join(',') });
        const stock = r.stock || {};
        for (const linea of estado.lineas) {
            const dato = stock[String(linea.producto_id)] || stock[linea.producto_id];
            if (!dato) continue;
            const sel = dato.almacen_seleccionado || {};
            linea.almacen_id = almacenId;
            linea.almacen_nombre = sel.almacen_nombre || almacenActual()?.nombre || '';
            linea.existencia_fisica = Number(sel.existencia_fisica || 0);
            linea.reservado = Number(sel.cantidad_reservada || 0);
            linea.disponible = Number(sel.cantidad_disponible || 0);
            linea.stock_total_fisico = Number(dato.stock_total_fisico || 0);
            linea.stock_total_reservado = Number(dato.stock_total_reservado || 0);
            linea.stock_total_disponible = Number(dato.stock_total_disponible || 0);
            linea.stock_almacenes = Array.isArray(dato.stock_almacenes) ? dato.stock_almacenes : [];
        }
        ocultarAlertaStock();
        renderLineas();
    }

    function ocultarMensaje(id, animar = true) {
        const el = $(id);
        if (!el) return;
        window.clearTimeout(temporizadoresMensajes.get(id));
        temporizadoresMensajes.delete(id);
        const finalizar = () => {
            el.hidden = true;
            el.classList.remove('is-leaving');
            el.replaceChildren();
        };
        if (!animar || el.hidden) return finalizar();
        el.classList.add('is-leaving');
        const cierreId = window.setTimeout(() => {
            temporizadoresMensajes.delete(id);
            finalizar();
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180);
        temporizadoresMensajes.set(id, cierreId);
    }

    function mostrarMensaje(id, texto, tipo) {
        const el = $(id);
        if (!el) return;
        if (!texto) return ocultarMensaje(id, false);
        const claseBase = id === 'mensajePagina' ? 'ventas-toast' : 'module-message';
        const tipoSeguro = ['success', 'warning', 'info', 'error'].includes(tipo) ? tipo : 'error';
        window.clearTimeout(temporizadoresMensajes.get(id));
        temporizadoresMensajes.delete(id);
        el.className = claseBase + ' ' + claseBase + '--' + tipoSeguro;
        el.innerHTML = '<span class="module-message__text">' + escapeHtml(texto) + '</span>'
            + '<button type="button" class="module-message__close" aria-label="Cerrar mensaje">×</button>';
        el.hidden = false;
        el.querySelector('.module-message__close')?.addEventListener('click', () => ocultarMensaje(id));
        if (tipoSeguro === 'success') {
            temporizadoresMensajes.set(id, window.setTimeout(() => ocultarMensaje(id), 4400));
        }
    }

    async function apiGet(accion, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ventas_api', '1');
        url.searchParams.set('accion', accion);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v));
        });
        const r = await fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
        const data = await r.json().catch(() => ({ success: false, mensaje: 'La respuesta del servidor no es válida.' }));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), { data, status: r.status });
        return data;
    }

    async function apiPost(accion, datos) {
        const body = new URLSearchParams();
        body.set('accion', accion);
        body.set('csrf_token', csrfToken);
        Object.entries(datos || {}).forEach(([k, v]) => body.set(k, v === null || v === undefined ? '' : String(v)));
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ventas_api', '1');
        const r = await fetch(url.toString(), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString()
        });
        const data = await r.json().catch(() => ({ success: false, mensaje: 'La respuesta del servidor no es válida.' }));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), { data, status: r.status });
        return data;
    }

    function abrirModal(id) { $(id).hidden = false; document.body.classList.add('modal-open'); }
    function cerrarModal(id) {
        $(id).hidden = true;
        if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open');
    }

    function resolverConfirmacion(resultado) {
        const resolver = confirmacionPendiente;
        confirmacionPendiente = null;
        configuracionConfirmacion = null;
        cerrarModal('modalConfirmacionVentas');
        if (resolver) resolver(resultado);
    }

    function solicitarConfirmacion({ titulo, mensaje, textoAceptar, peligro = false, requiereMotivo = false }) {
        if (confirmacionPendiente) resolverConfirmacion(null);
        $('tituloConfirmacionVentas').textContent = titulo;
        $('textoConfirmacionVentas').textContent = mensaje;
        $('grupoMotivoConfirmacionVentas').hidden = !requiereMotivo;
        $('motivoConfirmacionVentas').value = '';
        $('iconoConfirmacionVentas').textContent = peligro ? '!' : '✓';
        $('iconoConfirmacionVentas').className = 'confirm-dialog__icon' + (peligro ? ' confirm-dialog__icon--danger' : '');
        $('btnAceptarConfirmacionVentas').textContent = textoAceptar;
        $('btnAceptarConfirmacionVentas').className = peligro ? 'btn-danger' : 'btn-primary';
        configuracionConfirmacion = { requiereMotivo };
        mostrarMensaje('mensajeConfirmacionVentas', '');
        abrirModal('modalConfirmacionVentas');

        return new Promise((resolve) => {
            confirmacionPendiente = resolve;
            window.requestAnimationFrame(() => {
                (requiereMotivo ? $('motivoConfirmacionVentas') : $('btnAceptarConfirmacionVentas')).focus();
            });
        });
    }

    function aceptarConfirmacion() {
        if (!confirmacionPendiente || !configuracionConfirmacion) return;
        if (!configuracionConfirmacion.requiereMotivo) return resolverConfirmacion(true);
        const motivo = $('motivoConfirmacionVentas').value.trim();
        if (motivo.length < 5) {
            mostrarMensaje('mensajeConfirmacionVentas', 'El motivo debe tener al menos 5 caracteres.', 'error');
            $('motivoConfirmacionVentas').focus();
            return;
        }
        resolverConfirmacion(motivo);
    }

    function actualizarSelectVentas(select) {
        const control = select.closest('.ventas-select');
        if (!control) return;
        const trigger = control.querySelector('.ventas-select__trigger');
        const opcion = select.options[select.selectedIndex];
        trigger.querySelector('.ventas-select__value').textContent = opcion ? opcion.textContent.trim() : 'Seleccionar';
        trigger.disabled = select.disabled;
        trigger.setAttribute('aria-disabled', select.disabled ? 'true' : 'false');
    }

    function cerrarSelectVentas(restaurarFoco = false) {
        if (!selectVentasActivo || !menuSelectVentas) return;
        const trigger = selectVentasActivo.trigger;
        menuSelectVentas.hidden = true;
        menuSelectVentas.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        selectVentasActivo = null;
        if (restaurarFoco) trigger.focus();
    }

    function posicionarMenuSelectVentas() {
        if (!selectVentasActivo || !menuSelectVentas || menuSelectVentas.hidden) return;
        const rect = selectVentasActivo.trigger.getBoundingClientRect();
        const margen = 8;
        const ancho = Math.max(190, rect.width);
        menuSelectVentas.style.width = Math.min(ancho, window.innerWidth - (margen * 2)) + 'px';
        menuSelectVentas.style.left = Math.max(margen, Math.min(rect.left, window.innerWidth - menuSelectVentas.offsetWidth - margen)) + 'px';
        const espacioAbajo = window.innerHeight - rect.bottom - margen;
        const alto = Math.min(menuSelectVentas.scrollHeight, 280);
        menuSelectVentas.style.maxHeight = alto + 'px';
        menuSelectVentas.style.top = (espacioAbajo >= Math.min(alto, 180)
            ? rect.bottom + 7
            : Math.max(margen, rect.top - alto - 7)) + 'px';
    }

    function abrirSelectVentas(select, trigger) {
        if (select.disabled) return;
        if (selectVentasActivo?.select === select) return cerrarSelectVentas(true);
        cerrarSelectVentas();
        actualizarSelectVentas(select);
        menuSelectVentas.replaceChildren();
        Array.from(select.options).forEach((opcion, indice) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'ventas-select__option' + (indice === select.selectedIndex ? ' is-selected' : '');
            item.dataset.optionIndex = String(indice);
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', indice === select.selectedIndex ? 'true' : 'false');
            item.disabled = opcion.disabled;
            item.textContent = opcion.textContent;
            menuSelectVentas.appendChild(item);
        });
        selectVentasActivo = { select, trigger };
        trigger.setAttribute('aria-expanded', 'true');
        menuSelectVentas.hidden = false;
        menuSelectVentas.classList.add('is-open');
        posicionarMenuSelectVentas();
        window.requestAnimationFrame(() => menuSelectVentas.querySelector('.is-selected:not(:disabled), .ventas-select__option:not(:disabled)')?.focus());
    }

    function inicializarSelectVentas(select) {
        if (!(select instanceof HTMLSelectElement) || select.classList.contains('ventas-select__native')) return;
        const control = document.createElement('div');
        control.className = 'ventas-select';
        select.parentNode.insertBefore(control, select);
        control.appendChild(select);
        select.classList.add('ventas-select__native');
        select.tabIndex = -1;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'ventas-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="ventas-select__value"></span><span class="ventas-select__chevron" aria-hidden="true"></span>';
        control.appendChild(trigger);
        actualizarSelectVentas(select);

        trigger.addEventListener('click', () => abrirSelectVentas(select, trigger));
        trigger.addEventListener('keydown', (e) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
                e.preventDefault();
                abrirSelectVentas(select, trigger);
            }
        });
        select.addEventListener('change', () => actualizarSelectVentas(select));
        new MutationObserver(() => actualizarSelectVentas(select)).observe(select, {
            attributes: true,
            childList: true,
            subtree: true,
            attributeFilter: ['disabled', 'selected', 'label']
        });
    }

    function inicializarSelectsVentas(root = document) {
        if (root instanceof HTMLSelectElement) inicializarSelectVentas(root);
        root.querySelectorAll?.('select').forEach(inicializarSelectVentas);
    }

    function sincronizarSelectsVentas() {
        document.querySelectorAll('select.ventas-select__native').forEach(actualizarSelectVentas);
    }

    function prepararSelectsVentas() {
        menuSelectVentas = document.createElement('div');
        menuSelectVentas.className = 'ventas-select__menu';
        menuSelectVentas.id = 'menuSelectVentas';
        menuSelectVentas.setAttribute('role', 'listbox');
        menuSelectVentas.hidden = true;
        document.body.appendChild(menuSelectVentas);
        inicializarSelectsVentas();

        menuSelectVentas.addEventListener('click', (e) => {
            const item = e.target.closest('[data-option-index]');
            if (!item || !selectVentasActivo || item.disabled) return;
            const opcion = selectVentasActivo.select.options[Number(item.dataset.optionIndex)];
            if (!opcion) return;
            selectVentasActivo.select.value = opcion.value;
            selectVentasActivo.select.dispatchEvent(new Event('change', { bubbles: true }));
            actualizarSelectVentas(selectVentasActivo.select);
            cerrarSelectVentas(true);
        });
        menuSelectVentas.addEventListener('keydown', (e) => {
            const opciones = Array.from(menuSelectVentas.querySelectorAll('.ventas-select__option:not(:disabled)'));
            const actual = opciones.indexOf(document.activeElement);
            if (e.key === 'Escape') { e.preventDefault(); cerrarSelectVentas(true); return; }
            if (e.key === 'Tab') { cerrarSelectVentas(); return; }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
            e.preventDefault();
            const indice = e.key === 'Home' ? 0 : (e.key === 'End' ? opciones.length - 1 : (actual + (e.key === 'ArrowDown' ? 1 : -1) + opciones.length) % opciones.length);
            opciones[indice]?.focus();
        });
        document.addEventListener('pointerdown', (e) => {
            if (!selectVentasActivo || menuSelectVentas.contains(e.target) || selectVentasActivo.trigger.contains(e.target)) return;
            cerrarSelectVentas();
        });
        document.addEventListener('scroll', (e) => {
            if (e.target !== menuSelectVentas) cerrarSelectVentas();
        }, true);
        window.addEventListener('resize', () => cerrarSelectVentas());
        new MutationObserver((cambios) => cambios.forEach((cambio) => cambio.addedNodes.forEach((nodo) => {
            if (nodo.nodeType === Node.ELEMENT_NODE) inicializarSelectsVentas(nodo);
        }))).observe(document.body, { childList: true, subtree: true });
    }

    function opciones(items, valor, texto) {
        return items.map((x) => '<option value="' + escapeHtml(x[valor]) + '">' + escapeHtml(texto(x)) + '</option>').join('');
    }

    function monedaActual() {
        const id = Number($('ventaMoneda').value || 0);
        return estado.catalogos.monedas.find((m) => Number(m.id) === id) || { codigo: '', simbolo: '$' };
    }

    function descuentoCliente() {
        return estado.cliente ? Number(estado.cliente.descuento_efectivo_pct || estado.cliente.descuento_actual_pct || 0) : 0;
    }

    function anticipoAplicado() {
        return estado.origen === 'APARTADO' && estado.fuente ? Number(estado.fuente.importe_anticipado || 0) : 0;
    }

    function totales() {
        if (estado.origen === 'COTIZACION' && estado.fuente) {
            return {
                subtotal: Number(estado.fuente.subtotal || 0),
                descuento: Number(estado.fuente.descuento_total || 0),
                impuesto: Number(estado.fuente.impuesto_total || 0),
                total: Number(estado.fuente.total || 0)
            };
        }
        if (estado.origen === 'APARTADO' && estado.fuente) {
            let descuento = 0;
            estado.lineas.forEach((l) => {
                descuento += Math.max(0, Number(l.cantidad || 0) * Number(l.precio || 0) - Number(l.subtotal || 0));
            });
            return {
                subtotal: Number(estado.fuente.subtotal || 0),
                descuento,
                impuesto: Number(estado.fuente.impuesto_total || 0),
                total: Number(estado.fuente.total || 0)
            };
        }

        let subtotal = 0, descuento = 0, impuesto = 0, total = 0;
        estado.lineas.forEach((l) => {
            const bruto = Number(l.cantidad || 0) * Number(l.precio || 0);
            const desc = bruto * (Number(l.descuento || 0) / 100);
            const sub = bruto - desc;
            const imp = sub * (Number(l.impuesto_pct || 0) / 100);
            descuento += desc; subtotal += sub; impuesto += imp; total += sub + imp;
        });
        return { subtotal, descuento, impuesto, total };
    }

    function renderTotales() {
        const t = totales();
        const m = monedaActual();
        const anticipo = anticipoAplicado();
        const saldo = Math.max(0, t.total - anticipo);
        $('ventaSubtotal').textContent = moneda(t.subtotal, m.codigo, m.simbolo);
        $('ventaDescuento').textContent = moneda(t.descuento, m.codigo, m.simbolo);
        $('ventaImpuesto').textContent = moneda(t.impuesto, m.codigo, m.simbolo);
        $('ventaTotal').textContent = moneda(t.total, m.codigo, m.simbolo);
        $('ventaAnticipo').textContent = moneda(anticipo, m.codigo, m.simbolo);
        $('ventaSaldo').textContent = moneda(saldo, m.codigo, m.simbolo);
        $('filaAnticipoTotal').hidden = anticipo <= 0.0001;
        $('resumenAnticipo').hidden = anticipo <= 0.0001;
        if (anticipo > 0.0001) {
            $('resumenAnticipo').innerHTML = '<strong>Anticipos del apartado:</strong> ' + moneda(anticipo, m.codigo, m.simbolo)
                + '<br><small>Se aplican a esta venta sin registrarlos nuevamente como entrada de dinero.</small>';
        }
        renderPago();
    }

    function renderCliente() {
        const c = estado.cliente;
        if (!c) {
            $('clienteNombreVenta').textContent = 'Público general';
            $('clienteCodigoVenta').textContent = 'Sin cliente seleccionado.';
            $('clienteNivelVenta').textContent = '—';
            $('clienteDescuentoVenta').textContent = '0.00%';
            $('clienteCreditoVenta').textContent = 'No configurado';
            $('clienteCreditoDetalle').textContent = 'Una venta a crédito requiere un cliente.';
            return;
        }
        $('clienteNombreVenta').textContent = c.nombre_razon_social || c.cliente_nombre_actual || c.cliente_nombre || c.cliente_nombre_snapshot || 'Cliente';
        $('clienteCodigoVenta').textContent = c.codigo || c.cliente_codigo || '—';
        $('clienteNivelVenta').textContent = c.nivel_nombre || 'Sin nivel';
        $('clienteDescuentoVenta').textContent = numero(c.descuento_efectivo_pct ?? c.descuento_actual_pct ?? 0, 2) + '%';
        const limite = c.limite_credito;
        const dias = Number(c.dias_credito || 0);
        if (limite === null || limite === undefined) {
            $('clienteCreditoVenta').textContent = dias > 0 ? dias + ' días · sin límite definido' : 'No configurado';
            $('clienteCreditoDetalle').textContent = 'Configura límite y días para vender a crédito.';
        } else {
            $('clienteCreditoVenta').textContent = numero(limite, 2) + ' límite · ' + dias + ' días';
            const disponible = c.credito_disponible;
            $('clienteCreditoDetalle').textContent = disponible === null || disponible === undefined
                ? 'El disponible se validará al confirmar.'
                : 'Disponible aprox. en moneda base: ' + numero(disponible, 2);
        }
    }

    function renderPago() {
        const credito = $('ventaCondicion').value === 'CREDITO';
        const t = totales();
        const saldo = Math.max(0, t.total - anticipoAplicado());
        $('clienteRequeridoTexto').textContent = credito ? '(obligatorio en crédito)' : '(opcional en contado)';
        $('camposPagoContado').hidden = credito || saldo <= 0.0001;
        $('resumenCredito').hidden = !credito;
        $('etiquetaSaldo').textContent = credito ? 'A financiar' : 'A liquidar';

        if (credito) {
            const c = estado.cliente;
            $('resumenCredito').innerHTML = c
                ? '<strong>Venta a crédito:</strong> ' + numero(c.dias_credito || 0, 0) + ' días. El sistema validará el límite disponible y generará la Cuenta por Cobrar por el saldo de la venta.'
                : '<strong>Selecciona un cliente.</strong> El crédito no se puede confirmar sin cliente.';
            $('textoPagoAyuda').textContent = 'Los abonos posteriores se registrarán desde Cuentas por cobrar.';
        } else if (saldo <= 0.0001) {
            $('textoPagoAyuda').textContent = 'Los anticipos del apartado cubren completamente la venta; no se registra un cobro adicional.';
        } else {
            $('textoPagoAyuda').textContent = 'La venta de contado debe quedar liquidada al confirmarse.';
        }
    }

    async function cargarCatalogos() {
        const r = await apiGet('CATALOGOS');
        estado.catalogos.almacenes = r.almacenes || [];
        estado.catalogos.monedas = r.monedas || [];
        estado.catalogos.metodos = r.metodos_pago || [];

        $('ventaAlmacen').innerHTML = opciones(estado.catalogos.almacenes, 'id', (x) => x.codigo + ' · ' + x.nombre);
        $('ventaMoneda').innerHTML = opciones(estado.catalogos.monedas, 'id', (x) => x.codigo + ' · ' + x.nombre);
        $('ventaMetodoPago').innerHTML = '<option value="0">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        sincronizarSelectsVentas();
    }

    async function cargarVentas() {
        const r = await apiGet('LISTAR_VENTAS', {
            pagina: estado.pagina,
            por_pagina: estado.porPagina,
            busqueda: $('buscarVenta').value.trim(),
            estado: $('filtroEstado').value,
            condicion: $('filtroCondicion').value,
            desde: $('filtroDesde').value,
            hasta: $('filtroHasta').value
        });
        estado.ventas = r.ventas || [];
        const p = r.paginacion || {};
        estado.pagina = Number(p.pagina || 1);
        estado.totalPaginas = Number(p.total_paginas || 1);
        renderVentas(); renderPaginacion(p); renderKpis(r.kpis || {});
    }

    function renderKpis(k) {
        $('kpiTotal').textContent = Number(k.total || 0).toLocaleString('es-MX');
        $('kpiConfirmadas').textContent = Number(k.confirmadas || 0).toLocaleString('es-MX');
        $('kpiContado').textContent = Number(k.contado || 0).toLocaleString('es-MX');
        $('kpiCredito').textContent = Number(k.credito || 0).toLocaleString('es-MX');
        $('kpiCanceladas').textContent = Number(k.canceladas || 0).toLocaleString('es-MX');
        const base = estado.catalogos.monedas.find((m) => Number(m.es_base) === 1) || { codigo: 'MXN', simbolo: '$' };
        $('kpiImporte').textContent = moneda(k.importe_confirmado || 0, base.codigo, base.simbolo);
    }

    function renderPaginacion(p) {
        $('textoPagina').textContent = Number(p.total_registros || 0).toLocaleString('es-MX') + ' registros';
        $('paginaActual').textContent = 'Página ' + Number(p.pagina || 1) + ' de ' + Number(p.total_paginas || 1);
        $('btnAnterior').disabled = Number(p.pagina || 1) <= 1;
        $('btnSiguiente').disabled = Number(p.pagina || 1) >= Number(p.total_paginas || 1);
    }

    function renderVentas() {
        if (!estado.ventas.length) {
            $('tablaVentas').innerHTML = '<tr><td colspan="6" class="empty-cell">No hay ventas con esos filtros.</td></tr>';
            return;
        }
        $('tablaVentas').innerHTML = estado.ventas.map((v) => {
            return '<tr>'
                + '<td><strong>' + escapeHtml(v.folio) + '</strong></td>'
                + '<td><strong>' + escapeHtml(v.cliente_nombre_snapshot || 'Público general') + '</strong><small class="cell-secondary">' + escapeHtml(v.cliente_codigo || '') + '</small></td>'
                + '<td>' + fechaHora(v.fecha_venta) + '</td>'
                + '<td>' + badge(v.estado_operativo || v.estado) + '</td>'
                + '<td><strong>' + moneda(v.total, v.moneda_codigo, v.moneda_simbolo) + '</strong></td>'
                + '<td class="text-right actions-cell"><button type="button" class="table-action" data-action="ver" data-id="' + v.id + '">Ver</button>'
                + '<a class="table-action table-action--link" target="_blank" href="venta_imprimir.php?id=' + v.id + '">Imprimir</a></td>'
                + '</tr>';
        }).join('');
    }

    function resetVentaDirecta() {
        estado.origen = 'DIRECTO'; estado.origenId = 0; estado.fuente = null; estado.anticipos = [];
        estado.cliente = null; estado.lineas = []; estado.secuencia = 1; estado.timersPrecio = {}; estado.solicitudPrecio = {};
        $('formVenta').reset();
        $('ventaClienteId').value = '';
        $('buscarClienteVenta').readOnly = false;
        $('ventaAlmacen').disabled = false;
        $('ventaMoneda').disabled = false;
        $('ventaCondicion').disabled = false;
        $('contenedorBuscarProductoVenta').hidden = false;
        $('bannerOrigen').hidden = true;
        $('tituloModalVenta').textContent = 'Nueva venta';
        $('subtituloModalVenta').textContent = 'El inventario se reserva o descuenta según la validación QR.';
        $('textoOrigenLineasVenta').textContent = 'Agrega productos y verifica su disponibilidad.';
        $('ventaAlmacen').innerHTML = opciones(estado.catalogos.almacenes, 'id', (x) => x.codigo + ' · ' + x.nombre);
        $('ventaMoneda').innerHTML = opciones(estado.catalogos.monedas, 'id', (x) => x.codigo + ' · ' + x.nombre);
        $('ventaMetodoPago').innerHTML = '<option value="0">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        $('ventaCondicion').value = 'CONTADO';
        ocultarAlertaStock();
        mostrarMensaje('mensajeVenta', ''); renderCliente(); renderLineas(); renderTotales(); sincronizarSelectsVentas();
    }

    function construirClienteFuente(f) {
        if (!f || !(Number(f.cliente_id || 0) > 0)) return null;
        return {
            id: Number(f.cliente_id),
            codigo: f.cliente_codigo || '',
            nombre_razon_social: f.cliente_nombre_actual || f.cliente_nombre || f.cliente_nombre_snapshot || '',
            rfc: f.cliente_rfc || '',
            nivel_cliente_id: f.nivel_cliente_id,
            nivel_codigo: f.nivel_codigo || '',
            nivel_nombre: f.nivel_nombre || '',
            descuento_efectivo_pct: Number(f.descuento_actual_pct ?? 0),
            dias_credito: Number(f.dias_credito || 0),
            limite_credito: f.limite_credito === null || f.limite_credito === undefined ? null : Number(f.limite_credito),
            saldo_cxc: Number(f.saldo_cxc || 0),
            credito_disponible: f.credito_disponible === null || f.credito_disponible === undefined ? null : Number(f.credito_disponible)
        };
    }

    function mapearLineaFuente(d, origen) {
        return {
            key: estado.secuencia++, producto_id: Number(d.producto_id), sku: d.sku || '', nombre: d.producto_nombre_snapshot,
            presentacion_id: d.presentacion_id === null || d.presentacion_id === undefined ? 0 : Number(d.presentacion_id),
            presentacion_nombre: d.unidad_nombre_snapshot, unidad_nombre: d.unidad_nombre_snapshot,
            unidad_base_codigo: d.unidad_base_codigo || '', unidad_base_simbolo: d.unidad_base_simbolo || '',
            cantidad: Number(d.cantidad || 0), factor: Number(d.factor_a_unidad_base || 1), cantidad_base: Number(d.cantidad_base || 0),
            precio: Number(d.precio_unitario || 0), descuento: Number(d.descuento_pct || 0), impuesto_pct: Number(d.impuesto_pct_snapshot || 0),
            subtotal: Number(d.subtotal || 0), impuesto_importe: Number(d.impuesto_importe || 0), total: Number(d.total || 0),
            nivel_precio: origen === 'DIRECTO' ? 'MANUAL' : 'HISTORICO', precio_venta_id: 0, precio_manual: false,
            controla_inventario: Number(d.controla_inventario ?? 1), permite_fraccion: Number(d.permite_fraccion ?? 1),
            disponible: Number(d.cantidad_disponible || 0), existencia_fisica: Number(d.existencia_fisica || 0), reservado: Number(d.cantidad_reservada || 0),
            stock_total_fisico: Number(d.stock_total_fisico ?? d.existencia_fisica ?? 0),
            stock_total_reservado: Number(d.stock_total_reservado ?? d.cantidad_reservada ?? 0),
            stock_total_disponible: Number(d.stock_total_disponible ?? d.cantidad_disponible ?? 0),
            stock_almacenes: Array.isArray(d.stock_almacenes) ? d.stock_almacenes : [],
            almacen_id: Number(d.almacen_id || $('ventaAlmacen').value || 0), almacen_nombre: d.almacen_nombre || '',
            presentaciones: [], bloqueada: true
        };
    }

    async function cargarCotizacionFuente(id) {
        estado.cargandoOrigen = true;
        try {
            const almacenId = Number($('ventaAlmacen').value || 0);
            if (!(almacenId > 0)) throw new Error('Selecciona un almacén para surtir la cotización.');
            const r = await apiGet('COTIZACION_PARA_VENTA', { cotizacion_id: id, almacen_id: almacenId });
            estado.origen = 'COTIZACION'; estado.origenId = id; estado.fuente = r.cotizacion; estado.anticipos = [];
            estado.cliente = construirClienteFuente(r.cotizacion);
            estado.lineas = (r.detalles || []).map((d) => mapearLineaFuente(d, 'COTIZACION'));
            $('buscarClienteVenta').value = estado.cliente ? (estado.cliente.codigo + ' · ' + estado.cliente.nombre_razon_social) : 'Público general';
            $('ventaClienteId').value = estado.cliente ? estado.cliente.id : '';
            $('buscarClienteVenta').readOnly = true;
            $('ventaMoneda').value = String(r.cotizacion.moneda_id);
            $('ventaMoneda').disabled = true;
            $('contenedorBuscarProductoVenta').hidden = true;
            $('bannerOrigen').hidden = false;
            $('bannerOrigen').innerHTML = '<strong>Origen:</strong> Cotización ' + escapeHtml(r.cotizacion.folio) + '. Se conservan sus condiciones aceptadas.';
            $('tituloModalVenta').textContent = 'Venta desde ' + r.cotizacion.folio;
            $('textoOrigenLineasVenta').textContent = 'Productos y condiciones tomados de la cotización aceptada.';
            renderCliente(); renderLineas(); renderTotales(); sincronizarSelectsVentas();
        } finally { estado.cargandoOrigen = false; }
    }

    async function cargarApartadoFuente(id) {
        estado.cargandoOrigen = true;
        try {
            const r = await apiGet('APARTADO_PARA_VENTA', { apartado_id: id });
            estado.origen = 'APARTADO'; estado.origenId = id; estado.fuente = r.apartado; estado.anticipos = r.anticipos || [];
            estado.cliente = construirClienteFuente(r.apartado);
            estado.lineas = (r.detalles || []).map((d) => mapearLineaFuente(d, 'APARTADO'));
            $('buscarClienteVenta').value = estado.cliente ? (estado.cliente.codigo + ' · ' + estado.cliente.nombre_razon_social) : '';
            $('ventaClienteId').value = estado.cliente ? estado.cliente.id : '';
            $('buscarClienteVenta').readOnly = true;
            $('ventaMoneda').value = String(r.apartado.moneda_id);
            $('ventaMoneda').disabled = true;
            const almacenes = [...new Set(estado.lineas.map((l) => l.almacen_id))];
            if (almacenes.length === 1) $('ventaAlmacen').value = String(almacenes[0]);
            $('ventaAlmacen').disabled = true;
            $('contenedorBuscarProductoVenta').hidden = true;
            $('bannerOrigen').hidden = false;
            $('bannerOrigen').innerHTML = '<strong>Origen:</strong> Apartado ' + escapeHtml(r.apartado.folio)
                + '. Anticipo aplicado: ' + moneda(r.apartado.importe_anticipado || 0, r.apartado.moneda_codigo, r.apartado.moneda_simbolo) + '.';
            $('tituloModalVenta').textContent = 'Venta desde ' + r.apartado.folio;
            $('textoOrigenLineasVenta').textContent = 'Productos y almacén tomados de la reserva.';
            renderCliente(); renderLineas(); renderTotales(); sincronizarSelectsVentas();
        } finally { estado.cargandoOrigen = false; }
    }

    async function abrirFuente(tipo, id) {
        resetVentaDirecta();
        abrirModal('modalVenta');
        try {
            if (tipo === 'COTIZACION') await cargarCotizacionFuente(id);
            else await cargarApartadoFuente(id);
        } catch (e) {
            mostrarMensaje('mensajeVenta', e.message, 'error');
        }
    }

    async function buscarClientes(q) {
        if (estado.origen !== 'DIRECTO') return;
        const cont = $('resultadosClientesVenta');
        if (q.trim().length < 2) { cont.hidden = true; cont.innerHTML = ''; return; }
        const r = await apiGet('BUSCAR_CLIENTES', { q: q.trim() });
        const items = r.clientes || [];
        cont.dataset.items = JSON.stringify(items);
        cont.innerHTML = '<button type="button" class="smart-result smart-result--neutral" data-client-clear="1"><strong>Público general</strong><small>Venta de contado sin cliente registrado</small></button>'
            + items.map((c) => '<button type="button" class="smart-result" data-client-id="' + c.id + '"><strong>' + escapeHtml(c.codigo + ' · ' + c.nombre_razon_social) + '</strong><small>' + escapeHtml((c.nivel_nombre || 'Sin nivel') + ' · descuento ' + numero(c.descuento_efectivo_pct, 2) + '% · crédito ' + Number(c.dias_credito || 0) + ' días') + '</small></button>').join('');
        cont.hidden = false;
    }

    async function buscarProductos(q) {
        if (estado.origen !== 'DIRECTO') return;
        const cont = $('resultadosProductosVenta');
        if (q.trim().length < 2) { cont.hidden = true; cont.innerHTML = ''; return; }
        const almacenId = Number($('ventaAlmacen').value || 0);
        if (!(almacenId > 0)) throw new Error('Selecciona primero el almacén.');
        const r = await apiGet('BUSCAR_PRODUCTOS', { q: q.trim(), almacen_id: almacenId });
        const items = r.productos || [];
        cont.dataset.items = JSON.stringify(items);
        const almacen = almacenActual();
        cont.innerHTML = items.map((p) => {
            if (Number(p.controla_inventario) !== 1) {
                return '<button type="button" class="smart-result" data-product-id="' + p.id + '"><strong>' + escapeHtml(p.sku + ' · ' + p.nombre) + '</strong><small>Sin control de inventario</small></button>';
            }
            const unidad = escapeHtml(p.unidad_base_simbolo || p.unidad_base_codigo);
            const seleccionado = Number(p.cantidad_disponible || 0);
            const total = Number(p.stock_total_disponible ?? seleccionado);
            const otros = (Array.isArray(p.stock_almacenes) ? p.stock_almacenes : [])
                .filter((a) => Number(a.almacen_id) !== Number($('ventaAlmacen').value || 0) && Number(a.cantidad_disponible || 0) > 0.000001);
            return '<button type="button" class="smart-result smart-result--stock" data-product-id="' + p.id + '">'
                + '<span class="smart-result__main"><strong>' + escapeHtml(p.sku + ' · ' + p.nombre) + '</strong>'
                + '<small>' + escapeHtml(almacen?.nombre || 'Almacén') + ': <b>' + numero(seleccionado, 3) + ' ' + unidad + '</b> disponibles · Total empresa: <b>' + numero(total, 3) + ' ' + unidad + '</b></small></span>'
                + (otros.length ? '<span class="smart-result__other">' + otros.map((a) => escapeHtml(a.almacen_nombre) + ' ' + numero(a.cantidad_disponible, 3)).join(' · ') + '</span>' : '')
                + '</button>';
        }).join('') || '<div class="smart-result smart-result--empty">No se encontraron productos.</div>';
        cont.hidden = false;
    }

    async function agregarProducto(p) {
        const duplicado = estado.lineas.some((l) => Number(l.producto_id) === Number(p.id) && Number(l.presentacion_id) === 0);
        if (duplicado) throw new Error('Ese producto ya está agregado en unidad base. Ajusta la cantidad del renglón existente.');
        const r = await apiGet('PRESENTACIONES_PRODUCTO', { producto_id: p.id });
        const linea = {
            key: estado.secuencia++, producto_id: Number(p.id), sku: p.sku, nombre: p.nombre,
            presentacion_id: 0, presentacion_nombre: r.presentaciones?.[0]?.nombre || p.unidad_base_nombre,
            unidad_base_codigo: p.unidad_base_codigo, unidad_base_simbolo: p.unidad_base_simbolo,
            cantidad: 1, factor: 1, precio: 0, precio_venta_id: 0, nivel_precio: 'MANUAL', precio_manual: false,
            impuesto_pct: Number(p.impuesto_pct || 0), descuento: descuentoCliente(),
            controla_inventario: Number(p.controla_inventario || 0), permite_fraccion: Number(p.permite_fraccion || 0),
            disponible: Number(p.cantidad_disponible || 0), existencia_fisica: Number(p.existencia_fisica || 0), reservado: Number(p.cantidad_reservada || 0),
            stock_total_fisico: Number(p.stock_total_fisico ?? p.existencia_fisica ?? 0),
            stock_total_reservado: Number(p.stock_total_reservado ?? p.cantidad_reservada ?? 0),
            stock_total_disponible: Number(p.stock_total_disponible ?? p.cantidad_disponible ?? 0),
            stock_almacenes: Array.isArray(p.stock_almacenes) ? p.stock_almacenes : [],
            almacen_id: Number($('ventaAlmacen').value || 0), almacen_nombre: almacenActual()?.nombre || '',
            presentaciones: r.presentaciones || [], bloqueada: false
        };
        estado.lineas.push(linea);
        $('buscarProductoVenta').value = ''; $('resultadosProductosVenta').hidden = true;
        await sugerirPrecio(linea, true);
        renderLineas(); renderTotales();
    }

    async function sugerirPrecio(linea, forzar) {
        if (!linea || linea.bloqueada) return;
        if (linea.precio_manual && !forzar) return;
        const solicitud = (estado.solicitudPrecio[linea.key] || 0) + 1;
        estado.solicitudPrecio[linea.key] = solicitud;
        try {
            const r = await apiGet('SUGERIR_PRECIO', {
                producto_id: linea.producto_id,
                presentacion_id: linea.presentacion_id,
                moneda_id: $('ventaMoneda').value,
                cantidad: Math.max(Number(linea.cantidad || 0), 0.000001)
            });
            if (estado.solicitudPrecio[linea.key] !== solicitud) return;
            if (r.precio !== null && r.precio !== undefined) {
                linea.precio = Number(r.precio);
                linea.precio_venta_id = Number(r.precio_venta_id || 0);
                linea.nivel_precio = r.nivel_precio || 'MENUDEO';
                linea.impuesto_pct = Number(r.impuesto_pct || 0);
                linea.precio_manual = false;
            } else {
                // Si no existe precio para ESTA presentación/cantidad/moneda,
                // nunca conservar el precio automático de la opción anterior.
                // Ejemplo: Litro $25 -> Garrafa 20 L sin precio configurado.
                linea.precio = 0;
                linea.precio_venta_id = 0;
                linea.nivel_precio = 'SIN_CONFIGURAR';
                linea.precio_manual = false;
            }
        } catch (e) {
            if (estado.solicitudPrecio[linea.key] === solicitud) {
                // En un cambio forzado (presentación/moneda) es más seguro dejar
                // la línea sin precio que conservar un importe de otra opción.
                if (forzar && !linea.precio_manual) {
                    linea.precio = 0;
                }
                linea.precio_venta_id = 0;
                linea.nivel_precio = 'SIN_CONFIGURAR';
            }
        }
    }

    function renderLineas() {
        if (!estado.lineas.length) {
            $('tablaLineasVenta').innerHTML = '<tr><td colspan="10" class="empty-cell">Agrega productos a la venta.</td></tr>';
            return;
        }
        const m = monedaActual();
        $('tablaLineasVenta').innerHTML = estado.lineas.map((l) => {
            const requerido = Number(l.cantidad_base || (Number(l.cantidad || 0) * Number(l.factor || 1)));
            const disponible = Number(l.disponible || 0);
            const inventario = stockAlmacenesHtml(l, requerido);
            const totalLinea = l.bloqueada ? Number(l.total || 0) : (() => {
                const bruto = Number(l.cantidad || 0) * Number(l.precio || 0);
                const sub = bruto * (1 - Number(l.descuento || 0) / 100);
                return sub * (1 + Number(l.impuesto_pct || 0) / 100);
            })();

            let presentacion;
            if (l.bloqueada) {
                presentacion = '<strong>' + escapeHtml(l.presentacion_nombre || l.unidad_nombre || 'Unidad base') + '</strong>';
            } else {
                presentacion = '<select class="line-input" data-line-field="presentacion">' + (l.presentaciones || []).map((p) => '<option value="' + p.id + '" ' + (Number(p.id) === Number(l.presentacion_id) ? 'selected' : '') + '>' + escapeHtml(p.nombre) + '</option>').join('') + '</select>';
            }

            return '<tr data-key="' + l.key + '">'
                + '<td><strong>' + escapeHtml(l.nombre) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku) + '</small></td>'
                + '<td>' + presentacion + '</td>'
                + '<td>' + (l.bloqueada ? '<strong>' + numero(l.cantidad, 3) + '</strong>' : '<input class="line-input line-input--number" data-line-field="cantidad" type="number" min="0.000001" step="0.001" value="' + Number(l.cantidad || 0) + '">') + '</td>'
                + '<td>' + (l.bloqueada ? '<strong>' + moneda(l.precio, m.codigo, m.simbolo) + '</strong>' : '<input class="line-input line-input--number" data-line-field="precio" type="number" min="0.0001" step="0.01" value="' + Number(l.precio || 0) + '" ' + (puedePrecioManual ? '' : 'readonly title="No tienes permiso para modificar el precio manualmente."') + '><small class="cell-secondary">' + (l.precio_manual ? 'Manual' : (Number(l.precio_venta_id || 0) > 0 ? 'Automático' : 'Sin precio configurado')) + '</small>') + '</td>'
                + '<td>' + badge(l.nivel_precio === 'HISTORICO' ? 'Histórico' : l.nivel_precio) + '</td>'
                + '<td>' + numero(l.descuento, 2) + '%</td>'
                + '<td>' + numero(l.impuesto_pct, 2) + '%</td>'
                + '<td>' + inventario + '</td>'
                + '<td><strong>' + moneda(totalLinea, m.codigo, m.simbolo) + '</strong></td>'
                + '<td>' + (l.bloqueada ? '' : '<button type="button" class="table-action table-action--danger" data-line-action="eliminar">Quitar</button>') + '</td>'
                + '</tr>';
        }).join('');
    }

    async function confirmarVenta() {
        if (!estado.lineas.length) return mostrarMensaje('mensajeVenta', 'Agrega al menos un producto.', 'error');
        const condicion = $('ventaCondicion').value;
        if (condicion === 'CREDITO' && !estado.cliente) return mostrarMensaje('mensajeVenta', 'Selecciona un cliente para la venta a crédito.', 'error');
        const t = totales();
        const saldo = Math.max(0, t.total - anticipoAplicado());
        if (condicion === 'CONTADO' && saldo > 0.0001 && !(Number($('ventaMetodoPago').value || 0) > 0)) {
            return mostrarMensaje('mensajeVenta', 'Selecciona el método de pago.', 'error');
        }
        if (estado.origen === 'DIRECTO') {
            for (const l of estado.lineas) {
                if (!(Number(l.cantidad) > 0) || !(Number(l.precio) > 0)) return mostrarMensaje('mensajeVenta', 'Todas las líneas deben tener cantidad y precio mayores que cero.', 'error');
                const requerido = Number(l.cantidad || 0) * Number(l.factor || 1);
                if (Number(l.controla_inventario) === 1 && Number(l.disponible || 0) + 0.000001 < requerido) {
                    mostrarAlertaStock(l, requerido, 'No hay existencia suficiente de ' + l.nombre + ' en el almacén seleccionado.');
                    return mostrarMensaje('mensajeVenta', 'Revisa la disponibilidad por almacén antes de confirmar.', 'error');
                }
            }
        }
        const confirmado = await solicitarConfirmacion({
            titulo: 'Confirmar venta',
            mensaje: 'Se registrará la venta y se comprometerá el inventario. Con QR activo, la mercancía permanecerá reservada hasta confirmar su salida.',
            textoAceptar: 'Confirmar venta'
        });
        if (!confirmado) return;

        const lineas = estado.origen === 'DIRECTO' ? estado.lineas.map((l) => ({
            producto_id: l.producto_id,
            presentacion_id: l.presentacion_id,
            cantidad: l.cantidad,
            precio_unitario: l.precio,
            precio_venta_id: l.precio_venta_id || 0
        })) : [];

        $('btnConfirmarVenta').disabled = true;
        try {
            const solicitud = SIIdempotencia.adjuntar('ventas.crear', {
                origen: estado.origen,
                origen_id: estado.origenId,
                cliente_id: estado.cliente ? estado.cliente.id : 0,
                almacen_id: $('ventaAlmacen').value,
                moneda_id: $('ventaMoneda').value,
                condicion_pago: condicion,
                metodo_pago_id: $('ventaMetodoPago').value,
                referencia_pago: $('ventaReferenciaPago').value.trim(),
                observaciones: $('ventaObservaciones').value.trim(),
                lineas: JSON.stringify(lineas)
            });
            const r = await apiPost('CREAR_VENTA', solicitud);
            SIIdempotencia.confirmar('ventas.crear');
            cerrarModal('modalVenta');
            mostrarMensaje('mensajePagina', r.mensaje + ' Folio: ' + r.folio, 'success');
            await cargarVentas();
            await verDetalle(r.venta_id);
        } catch (e) {
            const datos = e?.data || {};
            if (datos.producto_id && Array.isArray(datos.stock_almacenes)) {
                const linea = estado.lineas.find((l) => Number(l.producto_id) === Number(datos.producto_id));
                if (linea) {
                    linea.stock_total_disponible = Number(datos.stock_total_disponible ?? linea.stock_total_disponible ?? 0);
                    linea.stock_almacenes = datos.stock_almacenes;
                    const actual = datos.stock_almacenes.find((a) => Number(a.almacen_id) === Number(datos.almacen_id || linea.almacen_id));
                    if (actual) {
                        linea.existencia_fisica = Number(actual.existencia_fisica || 0);
                        linea.reservado = Number(actual.cantidad_reservada || 0);
                        linea.disponible = Number(actual.cantidad_disponible || 0);
                    }
                    mostrarAlertaStock(linea, Number(datos.requerido_base || (linea.cantidad * linea.factor)), e.message);
                    renderLineas();
                }
            }
            mostrarMensaje('mensajeVenta', e.message, 'error');
        } finally {
            $('btnConfirmarVenta').disabled = false;
        }
    }

    async function verDetalle(id) {
        const r = await apiGet('DETALLE_VENTA', { venta_id: id });
        estado.detalle = r;
        const v = r.venta;
        const detalles = r.detalles || [];
        $('tituloDetalleVenta').textContent = v.folio;
        $('subtituloDetalleVenta').textContent = (v.cliente_nombre_snapshot || 'Público general') + ' · ' + fechaHora(v.fecha_venta);
        const origen = v.apartado_folio ? 'Apartado ' + v.apartado_folio : (v.cotizacion_folio ? 'Cotización ' + v.cotizacion_folio : 'Venta directa');
        const referenciaOrigen = v.apartado_folio || v.cotizacion_folio || 'Sin documento previo';
        const estadoOperativo = v.estado_operativo || v.estado;
        const detalleEstado = estadoOperativo === 'PENDIENTE_SALIDA'
            ? 'Venta confirmada · mercancía reservada'
            : escapeHtml(v.condicion_pago);
        const textoInventario = estadoOperativo === 'PENDIENTE_SALIDA'
            ? 'Salida física pendiente · aún no corresponde movimiento en Kardex'
            : (r.movimiento ? 'Kardex ' + escapeHtml(r.movimiento.folio) : 'Sin movimiento físico de inventario');
        $('resumenDetalleVenta').innerHTML = datoDetalle('Estado', badge(estadoOperativo), detalleEstado)
            + datoDetalle('Fecha de venta', fechaHora(v.fecha_venta), escapeHtml(v.moneda_codigo || ''))
            + datoDetalle('Productos', Number(detalles.length).toLocaleString('es-MX'), detalles.length === 1 ? '1 partida' : detalles.length + ' partidas')
            + datoDetalle('Total', moneda(v.total, v.moneda_codigo, v.moneda_simbolo), escapeHtml(v.condicion_pago));

        $('detalleClienteVenta').innerHTML = datoDetalle('Cliente', escapeHtml(v.cliente_nombre_snapshot || 'Público general'), v.cliente_id ? 'Cliente registrado' : 'Venta al público')
            + datoDetalle('Código', escapeHtml(v.cliente_codigo || '—'))
            + datoDetalle('RFC', escapeHtml(v.cliente_rfc_snapshot || '—'))
            + datoDetalle('Descuento comercial', numero(v.descuento_cliente_pct_snapshot || 0, 2) + '%');

        $('detalleOrigenVenta').innerHTML = datoDetalle('Origen', escapeHtml(origen), escapeHtml(referenciaOrigen))
            + datoDetalle('Inventario', textoInventario)
            + datoDetalle('Registró', escapeHtml(v.creado_por || '—'), fechaHora(v.created_at || v.fecha_venta))
            + datoDetalle('Referencia interna', escapeHtml(r.movimiento?.folio || v.folio));

        let financiero = datoDetalle('Condición', badge(v.condicion_pago))
            + datoDetalle('Moneda', escapeHtml((v.moneda_codigo || '') + (v.moneda_nombre ? ' · ' + v.moneda_nombre : '')), 'Tipo de cambio: ' + numero(v.tipo_cambio_a_base || 1, 4))
            + datoDetalle('Subtotal', moneda(v.subtotal, v.moneda_codigo, v.moneda_simbolo))
            + datoDetalle('Descuento', moneda(v.descuento_total, v.moneda_codigo, v.moneda_simbolo))
            + datoDetalle('Impuestos', moneda(v.impuesto_total, v.moneda_codigo, v.moneda_simbolo))
            + datoDetalle('Total', moneda(v.total, v.moneda_codigo, v.moneda_simbolo))
            + datoDetalle('Pagado / aplicado', moneda(v.pagado_total, v.moneda_codigo, v.moneda_simbolo), 'Anticipos: ' + moneda(v.importe_anticipado, v.moneda_codigo, v.moneda_simbolo))
            + datoDetalle('Saldo comercial', moneda(v.saldo_comercial, v.moneda_codigo, v.moneda_simbolo));
        if (v.condicion_pago === 'CREDITO') {
            financiero += datoDetalle('Cuenta por cobrar', escapeHtml(v.cxc_folio || 'Pendiente de generar'), badge(v.cxc_estado || 'PENDIENTE'))
                + datoDetalle('Importe financiado', moneda(v.cxc_importe_original || 0, v.moneda_codigo, v.moneda_simbolo))
                + datoDetalle('Saldo de la cuenta', moneda(v.cxc_saldo_pendiente || 0, v.moneda_codigo, v.moneda_simbolo))
                + datoDetalle('Vencimiento', fechaCorta(v.cxc_fecha_vencimiento));
        } else {
            financiero += datoDetalle('Cobro directo', moneda(v.pagado_directo || 0, v.moneda_codigo, v.moneda_simbolo));
        }
        $('detalleFinanciero').innerHTML = financiero;

        $('tablaDetalleVentaProductos').innerHTML = detalles.map((d) => '<tr>'
            + '<td><strong>' + escapeHtml(d.producto_nombre_snapshot) + '</strong><small class="cell-secondary">' + escapeHtml(d.sku_snapshot) + ' · Base ' + numero(d.cantidad_base, 3) + ' ' + escapeHtml(d.unidad_base_simbolo || d.unidad_base_codigo) + ' · ' + escapeHtml(d.nivel_precio_snapshot || 'Sin nivel') + '</small></td>'
            + '<td>' + escapeHtml(d.almacen_nombre) + '</td>'
            + '<td>' + numero(d.cantidad, 3) + ' ' + escapeHtml(d.unidad_nombre_snapshot) + '</td>'
            + '<td>' + moneda(d.precio_unitario, v.moneda_codigo, v.moneda_simbolo) + '</td>'
            + '<td>' + numero(d.descuento_pct, 2) + '%</td>'
            + '<td>' + numero(d.impuesto_pct_snapshot, 2) + '%</td>'
            + '<td><strong>' + moneda(d.total, v.moneda_codigo, v.moneda_simbolo) + '</strong></td></tr>').join('');

        const pagos = r.pagos || [];
        $('tablaPagosVenta').innerHTML = pagos.length ? pagos.map((p) => '<tr><td>' + fechaHora(p.fecha_pago) + '</td><td>' + escapeHtml(p.metodo_nombre) + '</td><td>' + escapeHtml(p.referencia || '—') + '</td><td><strong>' + moneda(p.importe, p.moneda_codigo, p.moneda_simbolo) + '</strong></td><td>' + badge(p.estado) + '</td><td>' + escapeHtml(p.registrado_por || '—') + '</td></tr>').join('') : '<tr><td colspan="6" class="empty-cell">No hay cobros directos registrados en esta venta.</td></tr>';

        const notas = [];
        if (v.observaciones) notas.push('<div class="detail-note__item"><span>Observaciones</span><p>' + escapeHtml(v.observaciones) + '</p></div>');
        if (v.estado === 'CANCELADA') {
            notas.push('<div class="detail-note__item detail-note__item--danger"><span>Cancelación</span><p>' + escapeHtml(v.motivo_cancelacion || 'Sin motivo registrado.') + '</p><small>' + fechaHora(v.cancelada_at) + ' · ' + escapeHtml(v.cancelado_por || 'Usuario no disponible') + '</small></div>');
        }
        $('detalleNotasVenta').innerHTML = notas.length ? notas.join('') : '<p class="detail-note__empty">Sin notas adicionales.</p>';

        $('btnImprimirVenta').href = 'venta_imprimir.php?id=' + v.id;
        if ($('btnCancelarVenta')) $('btnCancelarVenta').hidden = !puedeCancelar || v.estado !== 'CONFIRMADA' || Boolean(v.salida_confirmada_qr);
        mostrarMensaje('mensajeDetalleVenta', ''); abrirModal('modalDetalleVenta');
    }

    async function cancelarVenta() {
        const v = estado.detalle && estado.detalle.venta;
        if (!v) return;
        const avisoCancelacion = v.salida_pendiente_qr
            ? 'Se liberará la reserva pendiente y se conservará el historial. No se generará un reverso porque la mercancía aún no salió.'
            : (v.salida_fisica_aplicada
                ? 'Se revertirá la salida física de inventario y se conservará todo el historial.'
                : 'La venta se cancelará y su historial permanecerá disponible.');
        const motivo = await solicitarConfirmacion({
            titulo: 'Cancelar ' + v.folio,
            mensaje: avisoCancelacion,
            textoAceptar: 'Cancelar venta',
            peligro: true,
            requiereMotivo: true
        });
        if (typeof motivo !== 'string') return;
        $('btnCancelarVenta').disabled = true;
        try {
            const r = await apiPost('CANCELAR_VENTA', { venta_id: v.id, motivo });
            await cargarVentas();
            await verDetalle(v.id);
            mostrarMensaje('mensajePagina', r.mensaje, 'success');
        } catch (e) {
            mostrarMensaje('mensajeDetalleVenta', e.message, 'error');
        } finally {
            $('btnCancelarVenta').disabled = false;
        }
    }

    document.querySelectorAll('[data-cerrar-modal]').forEach((b) => b.addEventListener('click', () => cerrarModal(b.dataset.cerrarModal)));
    document.querySelectorAll('.modal-backdrop').forEach((m) => m.addEventListener('mousedown', (e) => {
        if (e.target === m && !m.hasAttribute('data-modal-static')) cerrarModal(m.id);
    }));
    $('btnAceptarConfirmacionVentas').addEventListener('click', aceptarConfirmacion);
    $('btnCancelarConfirmacionVentas').addEventListener('click', () => resolverConfirmacion(null));
    $('btnCerrarConfirmacionVentas').addEventListener('click', () => resolverConfirmacion(null));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('modalConfirmacionVentas').hidden) resolverConfirmacion(null);
    });

    if ($('btnNuevaVenta')) $('btnNuevaVenta').addEventListener('click', () => { resetVentaDirecta(); abrirModal('modalVenta'); });
    if ($('btnConfirmarVenta')) $('btnConfirmarVenta').addEventListener('click', confirmarVenta);
    if ($('btnCancelarVenta')) $('btnCancelarVenta').addEventListener('click', cancelarVenta);

    $('buscarVenta').addEventListener('input', function () { clearTimeout(estado.timerBusqueda); estado.timerBusqueda = setTimeout(() => { estado.pagina = 1; cargarVentas().catch((e) => mostrarMensaje('mensajePagina', e.message)); }, 350); });
    ['filtroEstado','filtroCondicion','filtroDesde','filtroHasta'].forEach((id) => $(id).addEventListener('change', () => { estado.pagina = 1; cargarVentas().catch((e) => mostrarMensaje('mensajePagina', e.message)); }));
    $('porPagina').addEventListener('change', function () { estado.porPagina = Number(this.value); estado.pagina = 1; cargarVentas().catch((e) => mostrarMensaje('mensajePagina', e.message)); });
    $('btnAnterior').addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina--; cargarVentas().catch((e) => mostrarMensaje('mensajePagina', e.message)); } });
    $('btnSiguiente').addEventListener('click', () => { if (estado.pagina < estado.totalPaginas) { estado.pagina++; cargarVentas().catch((e) => mostrarMensaje('mensajePagina', e.message)); } });
    $('tablaVentas').addEventListener('click', (e) => { const b = e.target.closest('[data-action="ver"]'); if (b) verDetalle(Number(b.dataset.id)).catch((x) => mostrarMensaje('mensajePagina', x.message)); });

    $('buscarClienteVenta').addEventListener('input', function () {
        if (estado.origen !== 'DIRECTO') return;
        const esperado = estado.cliente ? estado.cliente.codigo + ' · ' + estado.cliente.nombre_razon_social : '';
        if (estado.cliente && this.value !== esperado) {
            estado.cliente = null; $('ventaClienteId').value = ''; estado.lineas.forEach((l) => l.descuento = 0); renderCliente(); renderLineas(); renderTotales();
        }
        clearTimeout(estado.timerCliente);
        estado.timerCliente = setTimeout(() => buscarClientes(this.value).catch((e) => mostrarMensaje('mensajeVenta', e.message)), 300);
    });

    $('resultadosClientesVenta').addEventListener('click', (e) => {
        const limpiar = e.target.closest('[data-client-clear]');
        if (limpiar) {
            estado.cliente = null; $('ventaClienteId').value = ''; $('buscarClienteVenta').value = ''; $('resultadosClientesVenta').hidden = true;
            estado.lineas.forEach((l) => l.descuento = 0); renderCliente(); renderLineas(); renderTotales(); return;
        }
        const b = e.target.closest('[data-client-id]'); if (!b) return;
        const items = JSON.parse($('resultadosClientesVenta').dataset.items || '[]');
        const c = items.find((x) => Number(x.id) === Number(b.dataset.clientId)); if (!c) return;
        estado.cliente = c; $('ventaClienteId').value = c.id; $('buscarClienteVenta').value = c.codigo + ' · ' + c.nombre_razon_social; $('resultadosClientesVenta').hidden = true;
        estado.lineas.forEach((l) => l.descuento = Number(c.descuento_efectivo_pct || 0)); renderCliente(); renderLineas(); renderTotales();
    });

    $('buscarProductoVenta').addEventListener('input', function () { clearTimeout(estado.timerProducto); estado.timerProducto = setTimeout(() => buscarProductos(this.value).catch((e) => mostrarMensaje('mensajeVenta', e.message)), 300); });
    $('resultadosProductosVenta').addEventListener('click', (e) => {
        const b = e.target.closest('[data-product-id]'); if (!b) return;
        const items = JSON.parse($('resultadosProductosVenta').dataset.items || '[]');
        const p = items.find((x) => Number(x.id) === Number(b.dataset.productId));
        if (p) agregarProducto(p).catch((x) => mostrarMensaje('mensajeVenta', x.message));
    });

    $('tablaLineasVenta').addEventListener('input', (e) => {
        const tr = e.target.closest('[data-key]'); if (!tr) return;
        const l = estado.lineas.find((x) => Number(x.key) === Number(tr.dataset.key)); if (!l || l.bloqueada) return;
        if (e.target.dataset.lineField === 'cantidad') {
            l.cantidad = Number(e.target.value || 0); l.cantidad_base = l.cantidad * Number(l.factor || 1);
            clearTimeout(estado.timersPrecio[l.key]);
            estado.timersPrecio[l.key] = setTimeout(async () => { await sugerirPrecio(l, false); renderLineas(); renderTotales(); }, 300);
            renderLineas(); renderTotales();
        }
        if (e.target.dataset.lineField === 'precio') {
            l.precio = Number(e.target.value || 0); l.precio_venta_id = 0; l.nivel_precio = 'MANUAL'; l.precio_manual = Number(l.precio) > 0;
            renderTotales();
        }
    });

    $('tablaLineasVenta').addEventListener('change', async (e) => {
        const tr = e.target.closest('[data-key]'); if (!tr) return;
        const l = estado.lineas.find((x) => Number(x.key) === Number(tr.dataset.key)); if (!l || l.bloqueada) return;
        if (e.target.dataset.lineField === 'presentacion') {
            const nueva = Number(e.target.value || 0);
            const duplicada = estado.lineas.some((x) => Number(x.key) !== Number(l.key) && Number(x.producto_id) === Number(l.producto_id) && Number(x.presentacion_id) === nueva);
            if (duplicada) { e.target.value = String(l.presentacion_id); return mostrarMensaje('mensajeVenta', 'Ese producto ya existe con la presentación seleccionada.', 'error'); }
            l.presentacion_id = nueva;
            const p = l.presentaciones.find((x) => Number(x.id) === nueva);
            l.factor = p ? Number(p.factor_a_unidad_base || 1) : 1;
            l.presentacion_nombre = p ? p.nombre : 'Unidad base';
            l.cantidad_base = Number(l.cantidad || 0) * l.factor;
            // La presentación tiene su propia regla de precio. Limpiamos el
            // importe anterior antes de resolver el precio de la nueva opción.
            l.precio = 0;
            l.precio_manual = false;
            l.precio_venta_id = 0;
            l.nivel_precio = 'SIN_CONFIGURAR';
            renderLineas();
            renderTotales();
            await sugerirPrecio(l, true); renderLineas(); renderTotales();
        }
    });

    $('tablaLineasVenta').addEventListener('click', (e) => {
        const cambiar = e.target.closest('[data-switch-warehouse]');
        if (cambiar) {
            const destinoId = Number(cambiar.dataset.switchWarehouse || 0);
            if (destinoId > 0 && estado.origen !== 'APARTADO') {
                $('ventaAlmacen').value = String(destinoId);
                $('ventaAlmacen').dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }
        const b = e.target.closest('[data-line-action="eliminar"]'); if (!b) return;
        const tr = b.closest('[data-key]');
        estado.lineas = estado.lineas.filter((x) => Number(x.key) !== Number(tr.dataset.key));
        ocultarAlertaStock();
        renderLineas(); renderTotales();
    });

    $('ventaCondicion').addEventListener('change', renderTotales);
    $('ventaMetodoPago').addEventListener('change', () => {
        const metodo = estado.catalogos.metodos.find((m) => Number(m.id) === Number($('ventaMetodoPago').value || 0));
        $('ventaReferenciaPago').placeholder = metodo && Number(metodo.requiere_referencia) === 1 ? 'Obligatoria para este método' : 'Folio / referencia';
    });

    $('ventaMoneda').addEventListener('change', async () => {
        if (estado.origen !== 'DIRECTO') return;
        for (const l of estado.lineas) {
            if (!l.precio_manual) await sugerirPrecio(l, true);
        }
        renderLineas(); renderTotales();
    });

    window.addEventListener('si:tipo-cambio-actualizado', async () => {
        if (String(monedaActual().codigo || '').toUpperCase() !== 'USD' || estado.origen !== 'DIRECTO') return;
        try {
            for (const linea of estado.lineas) {
                if (!linea.precio_manual) await sugerirPrecio(linea, false);
            }
            renderLineas();
            renderTotales();
        } catch (error) {
            mostrarMensaje('mensajeVenta', error.message || 'Se actualizó el FIX, pero no fue posible refrescar todos los precios sugeridos.', 'error');
        }
    });

    $('ventaAlmacen').addEventListener('change', async () => {
        if (estado.cargandoOrigen || estado.origen === 'APARTADO') return;
        try {
            await refrescarStockLineas();
            if (estado.lineas.length) {
                mostrarMensaje('mensajeVenta', 'Se actualizó la disponibilidad de todos los productos para el almacén seleccionado.', 'success');
            }
        } catch (e) {
            mostrarMensaje('mensajeVenta', e.message, 'error');
        }
    });

    async function iniciar() {
        try {
            await cargarCatalogos();
            await cargarVentas();
            if (apartadoInicial > 0 && puedeCrear) await abrirFuente('APARTADO', apartadoInicial);
            else if (cotizacionInicial > 0 && puedeCrear) await abrirFuente('COTIZACION', cotizacionInicial);
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
        }
    }

    prepararSelectsVentas();
    iniciar();
})();
</script>
</body>
</html>
