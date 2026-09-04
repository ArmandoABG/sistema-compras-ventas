<?php

declare(strict_types=1);

if (isset($_GET['apartados_api'])) {
    $endpoint = __DIR__ . '/../funciones/apartados_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/apartados_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('apartados.ver', false);

$tituloPagina = 'Apartados';
$csrfToken = si_token_csrf();
$puedeCrear = si_tiene_permiso('apartados.crear');
$puedeCrearVenta = si_tiene_permiso('ventas.crear') && si_tiene_permiso('ventas.ver');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_apartados.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
$cotizacionInicial = filter_input(INPUT_GET, 'cotizacion_id', FILTER_VALIDATE_INT) ?: 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Apartados | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_apartados.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>
        <main class="page-content apartados-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">GESTIÓN COMERCIAL · RESERVAS</p>
                    <h1>Apartados y anticipos</h1>
                    <p>Reserva existencia disponible sin descontar físicamente el inventario y conserva cada anticipo en historial.</p>
                </div>
                <?php if ($puedeCrear): ?>
                    <button type="button" class="btn-primary" id="btnNuevoApartado">Nuevo apartado</button>
                <?php endif; ?>
            </header>

            <div class="info-banner">
                <strong>Flujo:</strong> un apartado ACTIVO reserva mercancía sin mover existencia física. Si vence, la reserva se libera pero los anticipos permanecen registrados; después puede reactivarse o cancelarse con reembolso y retención documentados.
            </div>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="stats-grid stats-grid--5">
                <article><span>Total</span><strong id="kpiTotal">0</strong></article>
                <article><span>Activos</span><strong id="kpiActivos">0</strong></article>
                <article><span>Por vencer</span><strong id="kpiPorVencer">0</strong></article>
                <article><span>Vencidos</span><strong id="kpiVencidos">0</strong></article>
                <article><span>Completados</span><strong id="kpiCompletados">0</strong></article>
            </section>

            <section class="module-card">
                <div class="filters-grid filters-grid--apartados">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input type="search" id="buscarApartado" maxlength="180" placeholder="Folio, cliente o cotización" autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Estado</span>
                        <select id="filtroEstado">
                            <option value="TODOS">Todos</option>
                            <option value="ACTIVO">Activo</option>
                            <option value="COMPLETADO">Completado</option>
                            <option value="VENCIDO">Vencido</option>
                            <option value="CANCELADO">Cancelado</option>
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
                    <table class="module-table module-table--apartados">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Cliente</th>
                                <th>Reserva</th>
                                <th>Estado</th>
                                <th>Productos</th>
                                <th>Total</th>
                                <th>Anticipado / saldo</th>
                                <th>Origen</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaApartados"><tr><td colspan="9" class="empty-cell">Cargando...</td></tr></tbody>
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
        </main>
    </div>
</div>

<!-- Crear apartado -->
<div class="modal-backdrop" id="modalApartado" hidden>
    <section class="modal-card modal-card--apartado" role="dialog" aria-modal="true" aria-labelledby="tituloModalApartado">
        <header class="modal-header">
            <div>
                <h2 id="tituloModalApartado">Nuevo apartado</h2>
                <p id="subtituloModalApartado">La reserva se valida contra la existencia disponible al guardar.</p>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalApartado" aria-label="Cerrar">×</button>
        </header>

        <form id="formApartado" class="apartado-form" autocomplete="off">
            <input type="hidden" id="apartadoCotizacionId" value="0">
            <div id="mensajeApartado" class="module-message" hidden></div>
            <div id="bannerCotizacion" class="conversion-banner" hidden></div>

            <section class="apartado-header-grid">
                <label class="field field--client-search">
                    <span>Cliente *</span>
                    <input type="search" id="buscarClienteApartado" placeholder="Código, nombre, RFC o teléfono" autocomplete="off">
                    <input type="hidden" id="apartadoClienteId">
                    <div id="resultadosClientes" class="smart-results" hidden></div>
                </label>
                <label class="field">
                    <span>Almacén de reserva *</span>
                    <select id="apartadoAlmacen" required></select>
                </label>
                <label class="field">
                    <span>Moneda *</span>
                    <select id="apartadoMoneda" required></select>
                </label>
                <label class="field">
                    <span>Reservado hasta *</span>
                    <input type="date" id="apartadoReservadoHasta" required>
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

            <section class="client-summary" id="resumenCliente">
                <div><span>Cliente</span><strong id="clienteNombreResumen">Ninguno</strong><small id="clienteCodigoResumen">Selecciona un cliente.</small></div>
                <div><span>Clasificación</span><strong id="clienteNivelResumen">—</strong><small>Condición comercial actual.</small></div>
                <div><span>Descuento</span><strong id="clienteDescuentoResumen">0.00%</strong><small id="clienteOrigenDescuento">Se guardará como histórico.</small></div>
                <div><span>Reserva</span><strong>Disponible</strong><small>Físico − reservado existente.</small></div>
            </section>

            <section class="apartado-products">
                <div class="apartado-products__heading">
                    <div><h3>Productos a reservar</h3><p id="textoOrigenLineas">Agrega productos usando la existencia disponible del almacén seleccionado.</p></div>
                    <label class="field product-search-field" id="contenedorBuscarProducto">
                        <span>Agregar producto</span>
                        <input type="search" id="buscarProductoApartado" placeholder="SKU, nombre o código de barras" autocomplete="off">
                        <div id="resultadosProductos" class="smart-results smart-results--wide" hidden></div>
                    </label>
                </div>

                <div class="table-wrap line-table-wrap">
                    <table class="module-table line-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Presentación</th>
                                <th>Cantidad</th>
                                <th>Precio</th>
                                <th>Desc.</th>
                                <th>Impuesto</th>
                                <th>Disponible / requerido</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tablaLineasApartado"><tr><td colspan="9" class="empty-cell">Agrega productos al apartado.</td></tr></tbody>
                    </table>
                </div>
            </section>

            <section class="apartado-bottom-grid">
                <label class="field observations-field">
                    <span>Observaciones</span>
                    <textarea id="apartadoObservaciones" rows="5" maxlength="3000" placeholder="Notas internas del apartado"></textarea>
                </label>

                <div class="initial-advance-card">
                    <h3>Anticipo inicial <small>(opcional)</small></h3>
                    <div class="advance-grid">
                        <label class="field"><span>Importe</span><input type="number" id="anticipoInicialImporte" min="0" step="0.01" placeholder="0.00"></label>
                        <label class="field"><span>Método</span><select id="anticipoInicialMetodo"><option value="0">Seleccionar</option></select></label>
                        <label class="field"><span>Referencia</span><input type="text" id="anticipoInicialReferencia" maxlength="120" placeholder="Folio / referencia"></label>
                    </div>
                    <small>Si no hay anticipo al crear, podrás registrarlo después desde el detalle.</small>
                </div>

                <aside class="totals-card">
                    <div><span>Subtotal neto</span><strong id="totalSubtotal">$0.00</strong></div>
                    <div><span>Impuestos</span><strong id="totalImpuesto">$0.00</strong></div>
                    <div class="totals-card__grand"><span>Total</span><strong id="totalGeneral">$0.00</strong></div>
                </aside>
            </section>
        </form>

        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalApartado">Cerrar</button>
            <?php if ($puedeCrear): ?><button type="button" class="btn-primary" id="btnGuardarApartado">Crear y reservar</button><?php endif; ?>
        </footer>
    </section>
</div>

<!-- Detalle -->
<div class="modal-backdrop" id="modalDetalle" hidden>
    <section class="modal-card modal-card--detail" role="dialog" aria-modal="true" aria-labelledby="tituloDetalle">
        <header class="modal-header">
            <div><h2 id="tituloDetalle">Detalle del apartado</h2><p id="subtituloDetalle"></p></div>
            <button type="button" class="modal-close" data-cerrar-modal="modalDetalle" aria-label="Cerrar">×</button>
        </header>
        <div class="detail-body">
            <div id="mensajeDetalle" class="module-message" hidden></div>
            <section class="detail-summary-grid" id="resumenDetalle"></section>
            <div class="financial-close-banner" id="cancelacionFinancieraResumen" hidden></div>

            <div class="detail-section-heading"><h3>Productos reservados</h3></div>
            <div class="table-wrap">
                <table class="module-table detail-table">
                    <thead><tr><th>Producto</th><th>Almacén</th><th>Cantidad</th><th>Base reservada</th><th>Precio</th><th>Desc.</th><th>Impuesto</th><th>Total</th></tr></thead>
                    <tbody id="tablaDetalleProductos"></tbody>
                </table>
            </div>

            <div class="detail-section-heading advance-heading">
                <div><h3>Anticipos</h3><p>Los anticipos cancelados permanecen visibles para conservar el historial.</p></div>
                <?php if ($puedeCrear): ?><button type="button" class="btn-secondary" id="btnAbrirAnticipo">Registrar anticipo</button><?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="module-table detail-table">
                    <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Importe</th><th>Estado</th><th>Usuario</th><th class="text-right">Acción</th></tr></thead>
                    <tbody id="tablaAnticipos"></tbody>
                </table>
            </div>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalDetalle">Cerrar</button>
            <div class="detail-footer-actions">
                <a class="btn-secondary btn-link" id="btnTicketCancelacion" href="#" target="_blank" rel="noopener" hidden>Imprimir ticket de cancelación</a>
                <?php if ($puedeCrear): ?>
                    <button type="button" class="btn-secondary" id="btnReactivarApartado" hidden>Reactivar apartado</button>
                    <button type="button" class="btn-danger" id="btnCancelarApartado">Cancelar apartado</button>
                <?php endif; ?>
            </div>
        </footer>
    </section>
</div>

<!-- Reactivar apartado vencido -->
<div class="modal-backdrop modal-backdrop--nested" id="modalReactivarApartado" hidden>
    <section class="modal-card modal-card--small" role="dialog" aria-modal="true" aria-labelledby="tituloReactivarApartado">
        <header class="modal-header"><div><h2 id="tituloReactivarApartado">Reactivar apartado</h2><p id="subtituloReactivarApartado">La mercancía volverá a reservarse si sigue disponible.</p></div><button type="button" class="modal-close" data-cerrar-modal="modalReactivarApartado" aria-label="Cerrar">×</button></header>
        <form class="small-form" id="formReactivarApartado">
            <div id="mensajeReactivarApartado" class="module-message" hidden></div>
            <label class="field"><span>Nueva fecha límite *</span><input type="date" id="reactivarReservadoHasta" required></label>
            <div class="operation-note">El anticipo existente no se modifica. Solo se vuelve a reservar la misma mercancía y se actualiza la fecha límite.</div>
        </form>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalReactivarApartado">Cerrar</button><button type="button" class="btn-primary" id="btnConfirmarReactivar">Reactivar y reservar</button></footer>
    </section>
</div>

<!-- Cancelar apartado y liquidar anticipo -->
<div class="modal-backdrop modal-backdrop--nested" id="modalCancelarApartado" hidden>
    <section class="modal-card modal-card--financial" role="dialog" aria-modal="true" aria-labelledby="tituloCancelarApartado">
        <header class="modal-header"><div><h2 id="tituloCancelarApartado">Cancelar apartado</h2><p id="subtituloCancelarApartado">La cancelación es definitiva y quedará documentada.</p></div><button type="button" class="modal-close" data-cerrar-modal="modalCancelarApartado" aria-label="Cerrar">×</button></header>
        <form class="small-form" id="formCancelarApartado">
            <div id="mensajeCancelarApartado" class="module-message" hidden></div>
            <section class="cancel-summary-grid">
                <div><span>Anticipo recibido</span><strong id="cancelacionAnticipoResumen">$0.00</strong></div>
                <div><span>Retención</span><strong id="cancelacionRetenidoResumen">$0.00</strong></div>
                <div><span>Reembolso</span><strong id="cancelacionReembolsoResumen">$0.00</strong></div>
            </section>
            <label class="field" id="campoRetencionCancelacion"><span>Retención sobre el anticipo (%) *</span><input type="number" id="cancelacionRetencionPct" min="0" max="100" step="0.01" value="0"><small>No existe un tope comercial prefijado: puedes decidir cualquier porcentaje entre 0% y 100%.</small></label>
            <label class="field" id="campoMetodoReembolso"><span>Método de reembolso *</span><select id="cancelacionMetodoReembolso"></select></label>
            <label class="field" id="campoReferenciaReembolso"><span>Referencia</span><input type="text" id="cancelacionReferenciaReembolso" maxlength="120" placeholder="Folio / referencia del reembolso"></label>
            <label class="field"><span>Motivo de cancelación *</span><textarea id="cancelacionMotivo" rows="4" maxlength="1500" placeholder="Explica por qué se cancela el apartado"></textarea></label>
            <div class="operation-note" id="notaCancelacionApartado"></div>
        </form>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalCancelarApartado">Cerrar</button><button type="button" class="btn-danger" id="btnConfirmarCancelarApartado">Cancelar y generar ticket</button></footer>
    </section>
</div>

<!-- Registrar anticipo -->
<div class="modal-backdrop modal-backdrop--nested" id="modalAnticipo" hidden>
    <section class="modal-card modal-card--small" role="dialog" aria-modal="true" aria-labelledby="tituloAnticipo">
        <header class="modal-header"><div><h2 id="tituloAnticipo">Registrar anticipo</h2><p id="saldoAnticipoTexto"></p></div><button type="button" class="modal-close" data-cerrar-modal="modalAnticipo" aria-label="Cerrar">×</button></header>
        <form class="small-form" id="formAnticipo">
            <div id="mensajeAnticipo" class="module-message" hidden></div>
            <label class="field"><span>Importe *</span><input type="number" id="nuevoAnticipoImporte" min="0.01" step="0.01" required></label>
            <label class="field"><span>Método de pago *</span><select id="nuevoAnticipoMetodo" required></select></label>
            <label class="field"><span>Referencia</span><input type="text" id="nuevoAnticipoReferencia" maxlength="120" placeholder="Obligatoria cuando el método la requiera"></label>
        </form>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalAnticipo">Cerrar</button><button type="button" class="btn-primary" id="btnGuardarAnticipo">Registrar</button></footer>
    </section>
</div>

<script src="../inc/tipo_cambio_ui.js?v=20260902-09"></script>

<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const puedeCrear = <?= $puedeCrear ? 'true' : 'false' ?>;
    const puedeCrearVenta = <?= $puedeCrearVenta ? 'true' : 'false' ?>;
    const cotizacionInicial = <?= (int) $cotizacionInicial ?>;
    const $ = (id) => document.getElementById(id);

    const estado = {
        catalogos: { almacenes: [], monedas: [], metodos: [], reserva_sugerida: '' },
        apartados: [], pagina: 1, totalPaginas: 1, porPagina: 20,
        cliente: null, lineas: [], secuencia: 1, cotizacion: null,
        detalle: null, timerBusqueda: null, timerCliente: null, timerProducto: null, almacenSeleccionado: 0,
        timersPrecio: {}, solicitudPrecio: {},
    };

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
            ACTIVO: ['Activo', 'success'], COMPLETADO: ['Completado', 'active'],
            VENCIDO: ['Vencido', 'warning'], CANCELADO: ['Cancelado', 'danger'],
            APLICADO: ['Aplicado', 'success']
        })[valor] || [valor || '—', 'neutral'];
    }

    function badge(valor) {
        const e = estadoVisual(valor);
        return '<span class="status-badge status-badge--' + e[1] + '">' + escapeHtml(e[0]) + '</span>';
    }

    function mostrarMensaje(id, texto, tipo) {
        const el = $(id);
        if (!el) return;
        if (!texto) { el.hidden = true; el.textContent = ''; el.className = 'module-message'; return; }
        el.hidden = false;
        el.textContent = texto;
        el.className = 'module-message module-message--' + (tipo || 'error');
    }

    async function apiGet(accion, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('apartados_api', '1');
        url.searchParams.set('accion', accion);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v));
        });
        const r = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' });
        const data = await r.json().catch(() => ({ success: false, mensaje: 'La respuesta del servidor no es válida.' }));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), { data, status: r.status });
        return data;
    }

    async function apiPost(accion, datos) {
        const body = new URLSearchParams();
        body.set('accion', accion); body.set('csrf_token', csrfToken);
        Object.entries(datos || {}).forEach(([k, v]) => body.set(k, v === null || v === undefined ? '' : String(v)));
        const url = new URL(window.location.href); url.search = ''; url.searchParams.set('apartados_api', '1');
        const r = await fetch(url.toString(), {
            method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin', body: body.toString()
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

    function opciones(items, valor, texto) {
        return items.map((x) => '<option value="' + escapeHtml(x[valor]) + '">' + escapeHtml(texto(x)) + '</option>').join('');
    }

    function monedaActual() {
        const id = Number($('apartadoMoneda').value || 0);
        return estado.catalogos.monedas.find((m) => Number(m.id) === id) || { codigo: '', simbolo: '$' };
    }

    async function cargarCatalogos() {
        const r = await apiGet('CATALOGOS');
        estado.catalogos.almacenes = r.almacenes || [];
        estado.catalogos.monedas = r.monedas || [];
        estado.catalogos.metodos = r.metodos_pago || [];
        estado.catalogos.reserva_sugerida = r.reserva_sugerida || '';

        $('apartadoAlmacen').innerHTML = opciones(estado.catalogos.almacenes, 'id', (x) => x.codigo + ' · ' + x.nombre);
        estado.almacenSeleccionado = Number($('apartadoAlmacen').value || 0);
        $('apartadoMoneda').innerHTML = opciones(estado.catalogos.monedas, 'id', (x) => x.codigo + ' · ' + x.nombre);
        const metodosHtml = '<option value="0">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        $('anticipoInicialMetodo').innerHTML = metodosHtml;
        $('nuevoAnticipoMetodo').innerHTML = '<option value="">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        $('cancelacionMetodoReembolso').innerHTML = '<option value="">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        $('apartadoReservadoHasta').value = r.reserva_sugerida || '';
    }

    async function cargarApartados() {
        const r = await apiGet('LISTAR_APARTADOS', {
            pagina: estado.pagina, por_pagina: estado.porPagina,
            busqueda: $('buscarApartado').value.trim(), estado: $('filtroEstado').value,
            desde: $('filtroDesde').value, hasta: $('filtroHasta').value
        });
        estado.apartados = r.apartados || [];
        const p = r.paginacion || {};
        estado.pagina = Number(p.pagina || 1); estado.totalPaginas = Number(p.total_paginas || 1);
        renderApartados(); renderPaginacion(p); renderKpis(r.kpis || {});
    }

    function renderKpis(k) {
        $('kpiTotal').textContent = k.total || 0; $('kpiActivos').textContent = k.activos || 0;
        $('kpiPorVencer').textContent = k.por_vencer || 0; $('kpiVencidos').textContent = k.vencidos || 0; $('kpiCompletados').textContent = k.completados || 0;
    }

    function renderApartados() {
        if (!estado.apartados.length) {
            $('tablaApartados').innerHTML = '<tr><td colspan="9" class="empty-cell">No hay apartados con esos filtros.</td></tr>'; return;
        }
        $('tablaApartados').innerHTML = estado.apartados.map((a) => {
            const origen = a.cotizacion_folio ? 'Cotización ' + escapeHtml(a.cotizacion_folio) : 'Directo';
            const conversion = a.venta_folio ? '<small class="cell-secondary">Venta: ' + escapeHtml(a.venta_folio) + '</small>' : '';
            return '<tr>'
                + '<td><strong>' + escapeHtml(a.folio) + '</strong><small class="cell-secondary">' + escapeHtml(a.moneda_codigo) + '</small></td>'
                + '<td><strong>' + escapeHtml(a.cliente_nombre) + '</strong><small class="cell-secondary">' + escapeHtml(a.cliente_codigo) + '</small></td>'
                + '<td>' + fechaCorta(a.fecha_apartado) + '<small class="cell-secondary">Hasta: ' + fechaCorta(a.reservado_hasta) + '</small></td>'
                + '<td>' + badge(a.estado) + conversion + '</td>'
                + '<td>' + Number(a.renglones || 0) + '</td>'
                + '<td><strong>' + moneda(a.total, a.moneda_codigo, a.moneda_simbolo) + '</strong></td>'
                + '<td>' + moneda(a.importe_anticipado, a.moneda_codigo, a.moneda_simbolo)
                + (a.cancelacion_financiera_id
                    ? '<small class="cell-secondary">Reembolso: ' + moneda(a.cancelacion_importe_reembolsado, a.moneda_codigo, a.moneda_simbolo) + ' · Retenido: ' + moneda(a.cancelacion_importe_retenido, a.moneda_codigo, a.moneda_simbolo) + '</small>'
                    : '<small class="cell-secondary">Saldo: ' + moneda(a.saldo_pendiente, a.moneda_codigo, a.moneda_simbolo) + '</small>') + '</td>'
                + '<td>' + origen + '</td>'
                + '<td class="text-right actions-cell">'
                + '<button type="button" class="table-action" data-action="ver" data-id="' + a.id + '">Ver</button>'
                + (puedeCrearVenta && a.estado === 'ACTIVO' && !a.venta_folio
                    ? '<a class="table-action table-action--success table-action--link" href="ventas.php?apartado_id=' + a.id + '">Crear venta</a>'
                    : '')
                + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginacion(p) {
        $('textoPagina').textContent = Number(p.total_registros || 0).toLocaleString('es-MX') + ' registros';
        $('paginaActual').textContent = 'Página ' + Number(p.pagina || 1) + ' de ' + Number(p.total_paginas || 1);
        $('btnAnterior').disabled = Number(p.pagina || 1) <= 1;
        $('btnSiguiente').disabled = Number(p.pagina || 1) >= Number(p.total_paginas || 1);
    }

    function resetNuevo() {
        estado.cliente = null; estado.lineas = []; estado.secuencia = 1; estado.cotizacion = null;
        $('formApartado').reset(); $('apartadoCotizacionId').value = '0'; $('apartadoClienteId').value = '';
        $('buscarClienteApartado').readOnly = false; $('apartadoMoneda').disabled = false; $('contenedorBuscarProducto').hidden = false;
        $('bannerCotizacion').hidden = true; $('tituloModalApartado').textContent = 'Nuevo apartado';
        $('subtituloModalApartado').textContent = 'La reserva se valida contra la existencia disponible al guardar.';
        $('textoOrigenLineas').textContent = 'Agrega productos usando la existencia disponible del almacén seleccionado.';
        $('apartadoAlmacen').innerHTML = opciones(estado.catalogos.almacenes, 'id', (x) => x.codigo + ' · ' + x.nombre);
        estado.almacenSeleccionado = Number($('apartadoAlmacen').value || 0);
        $('apartadoMoneda').innerHTML = opciones(estado.catalogos.monedas, 'id', (x) => x.codigo + ' · ' + x.nombre);
        $('anticipoInicialMetodo').innerHTML = '<option value="0">Seleccionar</option>' + opciones(estado.catalogos.metodos, 'id', (x) => x.nombre);
        $('apartadoReservadoHasta').value = estado.catalogos.reserva_sugerida || '';
        mostrarMensaje('mensajeApartado', ''); renderCliente(); renderLineas(); renderTotales();
    }

    function renderCliente() {
        const c = estado.cliente;
        $('clienteNombreResumen').textContent = c ? c.nombre_razon_social : 'Ninguno';
        $('clienteCodigoResumen').textContent = c ? c.codigo : 'Selecciona un cliente.';
        $('clienteNivelResumen').textContent = c ? (c.nivel_nombre || c.nivel_codigo || 'Sin clasificación') : '—';
        $('clienteDescuentoResumen').textContent = c ? numero(c.descuento_efectivo_pct, 2) + '%' : '0.00%';
        $('clienteOrigenDescuento').textContent = c ? (c.origen_descuento === 'HISTORICO' ? 'Conservado desde la cotización.' : (c.origen_descuento === 'ESPECIAL' ? 'Descuento especial del cliente.' : 'Descuento de su clasificación.')) : 'Se guardará como histórico.';
    }

    async function buscarClientes(texto) {
        if (texto.trim().length < 2 || estado.cotizacion) { $('resultadosClientes').hidden = true; return; }
        const r = await apiGet('BUSCAR_CLIENTES', { q: texto.trim() });
        const items = r.clientes || [];
        $('resultadosClientes').innerHTML = items.length ? items.map((c) => '<button type="button" class="smart-result" data-client-id="' + c.id + '"><strong>' + escapeHtml(c.codigo + ' · ' + c.nombre_razon_social) + '</strong><small>' + escapeHtml(c.nivel_nombre || 'Sin clasificación') + ' · Desc. ' + numero(c.descuento_efectivo_pct, 2) + '%</small></button>').join('') : '<div class="smart-result-empty">Sin coincidencias.</div>';
        $('resultadosClientes').dataset.items = JSON.stringify(items); $('resultadosClientes').hidden = false;
    }

    async function buscarProductos(texto) {
        if (texto.trim().length < 2 || estado.cotizacion) { $('resultadosProductos').hidden = true; return; }
        const almacenId = Number($('apartadoAlmacen').value || 0);
        if (!almacenId) { mostrarMensaje('mensajeApartado', 'Selecciona primero el almacén.', 'error'); return; }
        const r = await apiGet('BUSCAR_PRODUCTOS', { q: texto.trim(), almacen_id: almacenId });
        const items = r.productos || [];
        $('resultadosProductos').innerHTML = items.length ? items.map((p) => '<button type="button" class="smart-result" data-product-id="' + p.id + '"><strong>' + escapeHtml(p.sku + ' · ' + p.nombre) + '</strong><small>Disponible: ' + numero(p.cantidad_disponible, 3) + ' ' + escapeHtml(p.unidad_base_simbolo || p.unidad_base_codigo) + '</small></button>').join('') : '<div class="smart-result-empty">Sin coincidencias.</div>';
        $('resultadosProductos').dataset.items = JSON.stringify(items); $('resultadosProductos').hidden = false;
    }

    async function agregarProducto(producto) {
        const r = await apiGet('PRESENTACIONES_PRODUCTO', { producto_id: producto.id });
        const presentaciones = r.presentaciones || [];
        const presentacionInicial = presentaciones.length ? Number(presentaciones[0].id || 0) : 0;
        if (estado.lineas.some((x) => Number(x.producto_id) === Number(producto.id) && Number(x.presentacion_id) === presentacionInicial)) {
            mostrarMensaje('mensajeApartado', 'Ese producto con esa presentación ya está agregado. Modifica su cantidad.', 'error');
            return;
        }
        const linea = {
            key: estado.secuencia++, producto_id: Number(producto.id), sku: producto.sku, nombre: producto.nombre,
            presentacion_id: presentacionInicial, presentaciones, cantidad: 1,
            factor: Number((presentaciones[0] && presentaciones[0].factor_a_unidad_base) || 1),
            unidad_nombre: (presentaciones[0] && presentaciones[0].unidad_nombre) || r.producto.unidad_base_nombre,
            unidad_simbolo: (presentaciones[0] && (presentaciones[0].unidad_simbolo || presentaciones[0].unidad_codigo)) || r.producto.unidad_base_simbolo || r.producto.unidad_base_codigo,
            unidad_base_simbolo: r.producto.unidad_base_simbolo || r.producto.unidad_base_codigo,
            disponible_base: Number(producto.cantidad_disponible || 0), precio: 0, precio_venta_id: 0, nivel_precio: 'MANUAL', precio_origen: 'MANUAL', precio_manual: false,
            descuento: Number(estado.cliente ? estado.cliente.descuento_efectivo_pct : 0), impuesto: Number(r.producto.impuesto_pct || 0), impuesto_nombre: r.producto.impuesto_nombre || 'Sin impuesto'
        };
        estado.lineas.push(linea); $('buscarProductoApartado').value = ''; $('resultadosProductos').hidden = true;
        await sugerirPrecio(linea); renderLineas(); renderTotales();
    }

    async function sugerirPrecio(linea, forzar = false) {
        if (estado.cotizacion) return;
        const monedaId = Number($('apartadoMoneda').value || 0); if (!monedaId || !linea.producto_id || !(Number(linea.cantidad) > 0)) return;

        // Una captura manual pertenece al usuario. Cambiar solo la cantidad no debe
        // volver a sobrescribirla; presentación o moneda sí fuerzan una nueva búsqueda.
        if (!forzar && linea.precio_manual && Number(linea.precio) > 0) {
            return;
        }

        const token = (estado.solicitudPrecio[linea.key] || 0) + 1; estado.solicitudPrecio[linea.key] = token;
        try {
            const r = await apiGet('SUGERIR_PRECIO', { producto_id: linea.producto_id, presentacion_id: linea.presentacion_id || 0, moneda_id: monedaId, cantidad: linea.cantidad });
            if (estado.solicitudPrecio[linea.key] !== token) return;
            const d = r || {};
            linea.precio = d.precio === null ? 0 : Number(d.precio);
            linea.precio_venta_id = Number(d.precio_venta_id || 0);
            linea.nivel_precio = d.nivel_precio || 'MANUAL';
            linea.precio_origen = d.origen || 'MANUAL';
            linea.precio_manual = false;
            linea.impuesto = Number(d.impuesto_pct || linea.impuesto || 0);
            linea.impuesto_nombre = d.impuesto_nombre || linea.impuesto_nombre;
        } catch (e) {
            if (estado.solicitudPrecio[linea.key] === token) {
                linea.precio_venta_id = 0;
                linea.nivel_precio = 'MANUAL';
                linea.precio_origen = 'MANUAL';
            }
        }
    }

    function calculoLinea(l) {
        const cantidad = Number(l.cantidad || 0), precio = Number(l.precio || 0), descuento = Number(l.descuento || 0), impuesto = Number(l.impuesto || 0);
        const bruto = cantidad * precio, desc = bruto * descuento / 100, subtotal = bruto - desc, imp = subtotal * impuesto / 100;
        return { requerido: cantidad * Number(l.factor || 1), subtotal, impuesto: imp, total: subtotal + imp };
    }

    function renderLineas() {
        if (!estado.lineas.length) { $('tablaLineasApartado').innerHTML = '<tr><td colspan="9" class="empty-cell">Agrega productos al apartado.</td></tr>'; return; }
        const bloqueado = !!estado.cotizacion;
        const requeridoPorProducto = {};
        estado.lineas.forEach((item) => {
            const calc = calculoLinea(item);
            requeridoPorProducto[item.producto_id] = (requeridoPorProducto[item.producto_id] || 0) + calc.requerido;
        });
        $('tablaLineasApartado').innerHTML = estado.lineas.map((l) => {
            const c = calculoLinea(l);
            const requeridoTotalProducto = Number(requeridoPorProducto[l.producto_id] || c.requerido);
            const options = (l.presentaciones || []).map((p) => '<option value="' + p.id + '" ' + (Number(p.id) === Number(l.presentacion_id) ? 'selected' : '') + '>' + escapeHtml(p.nombre) + '</option>').join('');
            const disp = l.disponible_base === null || l.disponible_base === undefined ? 'Se valida al guardar' : numero(l.disponible_base, 3) + ' ' + escapeHtml(l.unidad_base_simbolo || '');
            const excede = l.disponible_base !== null && l.disponible_base !== undefined && requeridoTotalProducto > Number(l.disponible_base) + 0.000001;
            return '<tr data-key="' + l.key + '">'
                + '<td><strong>' + escapeHtml(l.nombre) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku) + '</small></td>'
                + '<td><select class="line-input" data-line-field="presentacion" ' + (bloqueado ? 'disabled' : '') + '>' + options + '</select></td>'
                + '<td><input class="line-input line-number" type="number" min="0.000001" step="0.001" data-line-field="cantidad" value="' + escapeHtml(l.cantidad) + '" ' + (bloqueado ? 'readonly' : '') + '><small class="cell-secondary">' + escapeHtml(l.unidad_nombre || '') + '</small></td>'
                + '<td><input class="line-input line-number" type="number" min="0.0001" step="0.01" data-line-field="precio" value="' + escapeHtml(Number(l.precio || 0).toFixed(4)) + '" ' + (bloqueado ? 'readonly' : '') + '><small class="cell-secondary">' + escapeHtml(l.nivel_precio || 'MANUAL') + '</small></td>'
                + '<td>' + numero(l.descuento, 2) + '%</td><td>' + numero(l.impuesto, 2) + '%</td>'
                + '<td class="' + (excede ? 'stock-danger' : '') + '">' + disp + '<small class="cell-secondary">Requiere total: ' + numero(requeridoTotalProducto, 3) + ' ' + escapeHtml(l.unidad_base_simbolo || '') + '</small></td>'
                + '<td><strong>' + moneda(c.total, monedaActual().codigo, monedaActual().simbolo) + '</strong></td>'
                + '<td>' + (bloqueado ? '' : '<button type="button" class="table-action table-action--danger" data-line-action="eliminar">Eliminar</button>') + '</td></tr>';
        }).join('');
    }

    function renderTotales() {
        const t = estado.cotizacion
            ? { subtotal: Number(estado.cotizacion.subtotal || 0), impuesto: Number(estado.cotizacion.impuesto_total || 0), total: Number(estado.cotizacion.total || 0) }
            : estado.lineas.reduce((acc, l) => { const c = calculoLinea(l); acc.subtotal += c.subtotal; acc.impuesto += c.impuesto; acc.total += c.total; return acc; }, { subtotal: 0, impuesto: 0, total: 0 });
        const m = monedaActual(); $('totalSubtotal').textContent = moneda(t.subtotal, m.codigo, m.simbolo); $('totalImpuesto').textContent = moneda(t.impuesto, m.codigo, m.simbolo); $('totalGeneral').textContent = moneda(t.total, m.codigo, m.simbolo);
        return t;
    }

    async function cargarCotizacionParaApartar(id) {
        resetNuevo();
        const r = await apiGet('COTIZACION_PARA_APARTAR', { cotizacion_id: id });
        const c = r.cotizacion; estado.cotizacion = c; $('apartadoCotizacionId').value = c.id;
        const detallesCot = r.detalles || [];
        const descuentoHistorico = detallesCot.length ? Number(detallesCot[0].descuento_pct || 0) : 0;
        estado.cliente = { id: c.cliente_id, codigo: c.cliente_codigo, nombre_razon_social: c.cliente_nombre, nivel_nombre: 'Histórico de cotización', descuento_efectivo_pct: descuentoHistorico, origen_descuento: 'HISTORICO' };
        $('apartadoClienteId').value = c.cliente_id; $('buscarClienteApartado').value = c.cliente_codigo + ' · ' + c.cliente_nombre; $('buscarClienteApartado').readOnly = true;
        $('apartadoMoneda').value = String(c.moneda_id); $('apartadoMoneda').disabled = true; $('contenedorBuscarProducto').hidden = true;
        $('tituloModalApartado').textContent = 'Apartado desde ' + c.folio; $('subtituloModalApartado').textContent = 'Se conservarán precios, descuentos e impuestos históricos de la cotización aceptada.';
        $('bannerCotizacion').hidden = false; $('bannerCotizacion').innerHTML = '<strong>' + escapeHtml(c.folio) + '</strong> · Total ' + moneda(c.total, c.moneda_codigo, c.moneda_simbolo) + '. Al crear el apartado la cotización quedará CONVERTIDA.';
        $('textoOrigenLineas').textContent = 'Los renglones provienen de la cotización y no se recalculan. Solo se valida la disponibilidad real antes de reservar.';
        estado.lineas = detallesCot.map((d) => ({
            key: estado.secuencia++, producto_id: Number(d.producto_id), sku: d.sku, nombre: d.producto_nombre_snapshot,
            presentacion_id: Number(d.presentacion_id || 0), presentaciones: [{ id: Number(d.presentacion_id || 0), nombre: d.presentacion_nombre || ('Unidad base · ' + d.unidad_nombre_snapshot) }],
            cantidad: Number(d.cantidad), factor: Number(d.factor_a_unidad_base), unidad_nombre: d.unidad_nombre_snapshot,
            unidad_base_simbolo: d.unidad_base_simbolo || d.unidad_base_codigo, disponible_base: null,
            precio: Number(d.precio_unitario), precio_venta_id: 0, nivel_precio: 'HISTÓRICO', precio_origen: 'COTIZACIÓN', precio_manual: false,
            descuento: Number(d.descuento_pct), impuesto: Number(d.impuesto_pct_snapshot)
        }));
        renderCliente(); renderLineas(); renderTotales(); abrirModal('modalApartado');
    }

    async function guardarApartado() {
        mostrarMensaje('mensajeApartado', '');
        const clienteId = Number($('apartadoClienteId').value || 0), almacenId = Number($('apartadoAlmacen').value || 0), monedaId = Number($('apartadoMoneda').value || 0);
        if (!clienteId) return mostrarMensaje('mensajeApartado', 'Selecciona un cliente.', 'error');
        if (!almacenId) return mostrarMensaje('mensajeApartado', 'Selecciona un almacén.', 'error');
        if (!monedaId) return mostrarMensaje('mensajeApartado', 'Selecciona una moneda.', 'error');
        if (!$('apartadoReservadoHasta').value) return mostrarMensaje('mensajeApartado', 'Captura la fecha límite de reserva.', 'error');
        if (!estado.lineas.length) return mostrarMensaje('mensajeApartado', 'Agrega al menos un producto.', 'error');
        if (estado.lineas.some((l) => !(Number(l.cantidad) > 0) || !(Number(l.precio) > 0))) return mostrarMensaje('mensajeApartado', 'Revisa cantidades y precios. Todos deben ser mayores que cero.', 'error');
        const t = renderTotales(); const anticipo = Number($('anticipoInicialImporte').value || 0);
        if (anticipo > t.total + 0.0001) return mostrarMensaje('mensajeApartado', 'El anticipo inicial no puede superar el total.', 'error');
        if (anticipo > 0 && !Number($('anticipoInicialMetodo').value || 0)) return mostrarMensaje('mensajeApartado', 'Selecciona el método del anticipo inicial.', 'error');

        const datos = {
            cliente_id: clienteId, moneda_id: monedaId, almacen_id: almacenId, cotizacion_id: Number($('apartadoCotizacionId').value || 0),
            reservado_hasta: $('apartadoReservadoHasta').value, observaciones: $('apartadoObservaciones').value.trim(),
            anticipo_importe: anticipo > 0 ? anticipo : '', anticipo_metodo_id: anticipo > 0 ? $('anticipoInicialMetodo').value : '0', anticipo_referencia: $('anticipoInicialReferencia').value.trim(),
            lineas: JSON.stringify(estado.cotizacion ? [] : estado.lineas.map((l) => ({ producto_id: l.producto_id, presentacion_id: l.presentacion_id || 0, cantidad: l.cantidad, precio_unitario: l.precio, precio_venta_id: l.precio_venta_id || 0 })))
        };
        $('btnGuardarApartado').disabled = true;
        try {
            const r = await apiPost('CREAR_APARTADO', datos); cerrarModal('modalApartado'); mostrarMensaje('mensajePagina', r.mensaje + ' ' + (r.folio || ''), 'success'); estado.pagina = 1; await cargarApartados();
            const url = new URL(window.location.href); url.searchParams.delete('cotizacion_id'); history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
        } catch (e) { mostrarMensaje('mensajeApartado', e.message, 'error'); }
        finally { $('btnGuardarApartado').disabled = false; }
    }

    async function verDetalle(id) {
        const r = await apiGet('DETALLE_APARTADO', { apartado_id: id }); estado.detalle = r; const a = r.apartado; const c = r.cancelacion || null;
        $('tituloDetalle').textContent = a.folio; $('subtituloDetalle').textContent = a.cliente_codigo + ' · ' + a.cliente_nombre;
        const saldoTexto = a.estado === 'CANCELADO' && c
            ? 'Cancelado · liquidación ' + c.folio
            : 'Saldo ' + moneda(a.saldo_pendiente, a.moneda_codigo, a.moneda_simbolo);
        $('resumenDetalle').innerHTML = '<div><span>Estado</span><strong>' + badge(a.estado) + '</strong><small>Reserva hasta ' + fechaCorta(a.reservado_hasta) + '</small></div>'
            + '<div><span>Total</span><strong>' + moneda(a.total, a.moneda_codigo, a.moneda_simbolo) + '</strong><small>Subtotal ' + moneda(a.subtotal, a.moneda_codigo, a.moneda_simbolo) + '</small></div>'
            + '<div><span>Anticipado</span><strong>' + moneda(a.importe_anticipado, a.moneda_codigo, a.moneda_simbolo) + '</strong><small>' + escapeHtml(saldoTexto) + '</small></div>'
            + '<div><span>Origen</span><strong>' + escapeHtml(a.cotizacion_folio || 'Directo') + '</strong><small>' + escapeHtml(a.venta_folio ? 'Venta ' + a.venta_folio : 'Sin venta todavía') + '</small></div>';
        $('tablaDetalleProductos').innerHTML = (r.detalles || []).map((d) => '<tr><td><strong>' + escapeHtml(d.producto_nombre_snapshot) + '</strong><small class="cell-secondary">' + escapeHtml(d.sku) + '</small></td><td>' + escapeHtml(d.almacen_nombre) + '</td><td>' + numero(d.cantidad, 3) + ' ' + escapeHtml(d.unidad_simbolo || d.unidad_codigo) + '</td><td>' + numero(d.cantidad_base, 3) + ' ' + escapeHtml(d.unidad_base_simbolo || d.unidad_base_codigo) + '</td><td>' + moneda(d.precio_unitario, a.moneda_codigo, a.moneda_simbolo) + '</td><td>' + numero(d.descuento_pct, 2) + '%</td><td>' + numero(d.impuesto_pct_snapshot, 2) + '%</td><td><strong>' + moneda(d.total, a.moneda_codigo, a.moneda_simbolo) + '</strong></td></tr>').join('');
        renderAnticipos();

        const resumenCancelacion = $('cancelacionFinancieraResumen');
        resumenCancelacion.hidden = !c;
        resumenCancelacion.innerHTML = c ? '<strong>Liquidación de cancelación ' + escapeHtml(c.folio) + '</strong>'
            + '<span>Anticipo recibido: ' + moneda(c.importe_anticipado, a.moneda_codigo, a.moneda_simbolo)
            + ' · Retención ' + numero(c.retencion_pct, 2) + '% (' + moneda(c.importe_retenido, a.moneda_codigo, a.moneda_simbolo) + ')'
            + ' · Reembolsado: ' + moneda(c.importe_reembolsado, a.moneda_codigo, a.moneda_simbolo) + '</span>'
            + '<small>' + escapeHtml(c.metodo_nombre ? 'Método: ' + c.metodo_nombre + (c.referencia ? ' · Ref. ' + c.referencia : '') : 'Sin reembolso monetario') + '</small>' : '';

        $('btnAbrirAnticipo').hidden = !puedeCrear || a.estado !== 'ACTIVO';
        $('btnCancelarApartado').hidden = !puedeCrear || !['ACTIVO','VENCIDO'].includes(a.estado);
        $('btnReactivarApartado').hidden = !puedeCrear || a.estado !== 'VENCIDO' || !!a.venta_folio;
        $('btnTicketCancelacion').hidden = !c;
        $('btnTicketCancelacion').href = c ? 'apartado_cancelacion_imprimir.php?id=' + Number(c.id) : '#';
        mostrarMensaje('mensajeDetalle', ''); abrirModal('modalDetalle');
    }

    function renderAnticipos() {
        const a = estado.detalle.apartado, items = estado.detalle.anticipos || [];
        if (!items.length) { $('tablaAnticipos').innerHTML = '<tr><td colspan="7" class="empty-cell">Todavía no hay anticipos registrados.</td></tr>'; return; }
        $('tablaAnticipos').innerHTML = items.map((x) => '<tr><td>' + fechaHora(x.fecha_pago) + '</td><td>' + escapeHtml(x.metodo_nombre) + '</td><td>' + escapeHtml(x.referencia || '—') + '</td><td><strong>' + moneda(x.importe, a.moneda_codigo, a.moneda_simbolo) + '</strong></td><td>' + badge(x.estado) + (x.motivo_cancelacion ? '<small class="cell-secondary">' + escapeHtml(x.motivo_cancelacion) + '</small>' : '') + '</td><td>' + escapeHtml(x.registrado_por || '—') + '</td><td class="text-right">' + (puedeCrear && x.estado === 'APLICADO' && a.estado === 'ACTIVO' ? '<button type="button" class="table-action table-action--danger" data-anticipo-action="cancelar" data-id="' + x.id + '">Cancelar</button>' : '—') + '</td></tr>').join('');
    }

    async function registrarAnticipo() {
        const a = estado.detalle && estado.detalle.apartado; if (!a) return;
        const importe = Number($('nuevoAnticipoImporte').value || 0); if (!(importe > 0)) return mostrarMensaje('mensajeAnticipo', 'Captura un importe mayor que cero.', 'error');
        if (importe > Number(a.saldo_pendiente) + 0.0001) return mostrarMensaje('mensajeAnticipo', 'El importe supera el saldo pendiente.', 'error');
        try {
            const r = await apiPost('REGISTRAR_ANTICIPO', { apartado_id: a.id, importe, metodo_pago_id: $('nuevoAnticipoMetodo').value, referencia: $('nuevoAnticipoReferencia').value.trim() });
            cerrarModal('modalAnticipo'); await verDetalle(a.id); mostrarMensaje('mensajeDetalle', r.mensaje, 'success'); await cargarApartados();
        } catch (e) { mostrarMensaje('mensajeAnticipo', e.message, 'error'); }
    }

    async function cancelarAnticipo(id) {
        if (!window.confirm('Esta opción anula un anticipo capturado por corrección mientras el apartado sigue ACTIVO. Si quieres cerrar el apartado y devolver o retener el dinero, usa “Cancelar apartado”. ¿Continuar?')) return;
        const motivo = window.prompt('Motivo de anulación/corrección del anticipo:'); if (motivo === null) return; if (motivo.trim().length < 5) return mostrarMensaje('mensajeDetalle', 'El motivo debe tener al menos 5 caracteres.', 'error');
        try { const r = await apiPost('CANCELAR_ANTICIPO', { anticipo_id: id, motivo: motivo.trim() }); await verDetalle(estado.detalle.apartado.id); mostrarMensaje('mensajeDetalle', r.mensaje, 'success'); await cargarApartados(); }
        catch (e) { mostrarMensaje('mensajeDetalle', e.message, 'error'); }
    }

    function abrirReactivarApartado() {
        const a = estado.detalle && estado.detalle.apartado; if (!a || a.estado !== 'VENCIDO') return;
        $('formReactivarApartado').reset();
        $('reactivarReservadoHasta').value = estado.catalogos.reserva_sugerida || '';
        $('reactivarReservadoHasta').min = new Date().toISOString().slice(0, 10);
        $('tituloReactivarApartado').textContent = 'Reactivar ' + a.folio;
        $('subtituloReactivarApartado').textContent = 'Se conservará el anticipo de ' + moneda(a.importe_anticipado, a.moneda_codigo, a.moneda_simbolo) + '.';
        mostrarMensaje('mensajeReactivarApartado', '');
        abrirModal('modalReactivarApartado');
    }

    async function confirmarReactivarApartado() {
        const a = estado.detalle && estado.detalle.apartado; if (!a) return;
        const fecha = $('reactivarReservadoHasta').value;
        if (!fecha) return mostrarMensaje('mensajeReactivarApartado', 'Selecciona la nueva fecha límite.', 'error');
        if (!window.confirm('Se volverá a reservar la mercancía del apartado si existe disponibilidad suficiente. ¿Deseas continuar?')) return;
        $('btnConfirmarReactivar').disabled = true;
        try {
            const r = await apiPost('REACTIVAR_APARTADO', { apartado_id: a.id, reservado_hasta: fecha });
            cerrarModal('modalReactivarApartado');
            await verDetalle(a.id);
            mostrarMensaje('mensajeDetalle', r.mensaje, 'success');
            await cargarApartados();
        } catch (e) { mostrarMensaje('mensajeReactivarApartado', e.message, 'error'); }
        finally { $('btnConfirmarReactivar').disabled = false; }
    }

    function actualizarResumenCancelacion() {
        const a = estado.detalle && estado.detalle.apartado; if (!a) return;
        const anticipo = Math.max(0, Number(a.importe_anticipado || 0));
        let pct = Number($('cancelacionRetencionPct').value || 0);
        if (!Number.isFinite(pct)) pct = 0;
        pct = Math.min(100, Math.max(0, pct));
        const retenido = Math.round((anticipo * pct / 100) * 10000) / 10000;
        const reembolso = Math.max(0, Math.round((anticipo - retenido) * 10000) / 10000);
        $('cancelacionAnticipoResumen').textContent = moneda(anticipo, a.moneda_codigo, a.moneda_simbolo);
        $('cancelacionRetenidoResumen').textContent = moneda(retenido, a.moneda_codigo, a.moneda_simbolo) + ' (' + numero(pct, 2) + '%)';
        $('cancelacionReembolsoResumen').textContent = moneda(reembolso, a.moneda_codigo, a.moneda_simbolo);
        $('campoRetencionCancelacion').hidden = anticipo <= 0.0001;
        $('campoMetodoReembolso').hidden = reembolso <= 0.0001;
        $('campoReferenciaReembolso').hidden = reembolso <= 0.0001;
        return { anticipo, pct, retenido, reembolso };
    }

    function abrirCancelarApartado() {
        const a = estado.detalle && estado.detalle.apartado; if (!a || !['ACTIVO','VENCIDO'].includes(a.estado)) return;
        $('formCancelarApartado').reset();
        $('cancelacionRetencionPct').value = '0';
        $('cancelacionMetodoReembolso').value = '';
        $('tituloCancelarApartado').textContent = 'Cancelar ' + a.folio;
        $('subtituloCancelarApartado').textContent = a.estado === 'VENCIDO'
            ? 'La reserva ya fue liberada por vencimiento. El anticipo sigue intacto y ahora se liquidará.'
            : 'La reserva se liberará y el anticipo se liquidará en la misma operación.';
        $('notaCancelacionApartado').textContent = a.estado === 'VENCIDO'
            ? 'La existencia física no cambiará: el producto nunca salió. Solo se cerrará el apartado y se registrará el tratamiento del anticipo.'
            : 'La existencia física no cambiará. Se liberará únicamente la cantidad reservada del apartado.';
        actualizarResumenCancelacion();
        mostrarMensaje('mensajeCancelarApartado', '');
        abrirModal('modalCancelarApartado');
    }

    async function confirmarCancelarApartado() {
        const a = estado.detalle && estado.detalle.apartado; if (!a) return;
        const motivo = $('cancelacionMotivo').value.trim();
        if (motivo.length < 5) return mostrarMensaje('mensajeCancelarApartado', 'El motivo debe tener al menos 5 caracteres.', 'error');
        const calculo = actualizarResumenCancelacion();
        const metodoId = Number($('cancelacionMetodoReembolso').value || 0);
        const referencia = $('cancelacionReferenciaReembolso').value.trim();
        if (calculo.reembolso > 0.0001 && !metodoId) return mostrarMensaje('mensajeCancelarApartado', 'Selecciona el método con el que se devolverá el dinero al cliente.', 'error');
        if (calculo.reembolso > 0.0001) {
            const metodo = estado.catalogos.metodos.find((m) => Number(m.id) === metodoId);
            if (metodo && Number(metodo.requiere_referencia || 0) === 1 && !referencia) return mostrarMensaje('mensajeCancelarApartado', 'Ese método requiere una referencia de reembolso.', 'error');
        }
        const resumen = calculo.anticipo > 0.0001
            ? 'Se reembolsarán ' + moneda(calculo.reembolso, a.moneda_codigo, a.moneda_simbolo) + ' y se retendrán ' + moneda(calculo.retenido, a.moneda_codigo, a.moneda_simbolo) + '. '
            : 'El apartado no tiene anticipo aplicado. ';
        if (!window.confirm(resumen + 'La cancelación será definitiva. ¿Confirmas?')) return;

        const ventanaTicket = window.open('about:blank', '_blank');
        $('btnConfirmarCancelarApartado').disabled = true;
        try {
            const r = await apiPost('CANCELAR_APARTADO', {
                apartado_id: a.id,
                motivo,
                retencion_pct: calculo.anticipo > 0.0001 ? calculo.pct : 0,
                metodo_reembolso_id: calculo.reembolso > 0.0001 ? metodoId : 0,
                referencia_reembolso: calculo.reembolso > 0.0001 ? referencia : ''
            });
            cerrarModal('modalCancelarApartado');
            await cargarApartados();
            await verDetalle(a.id);
            mostrarMensaje('mensajeDetalle', r.mensaje, 'success');
            if (ventanaTicket && r.ticket_url) ventanaTicket.location.href = r.ticket_url;
            else if (ventanaTicket) ventanaTicket.close();
        } catch (e) {
            if (ventanaTicket) ventanaTicket.close();
            mostrarMensaje('mensajeCancelarApartado', e.message, 'error');
        } finally { $('btnConfirmarCancelarApartado').disabled = false; }
    }

    document.querySelectorAll('[data-cerrar-modal]').forEach((b) => b.addEventListener('click', () => cerrarModal(b.dataset.cerrarModal)));
    document.querySelectorAll('.modal-backdrop').forEach((m) => m.addEventListener('mousedown', (e) => { if (e.target === m) cerrarModal(m.id); }));

    if ($('btnNuevoApartado')) $('btnNuevoApartado').addEventListener('click', () => { resetNuevo(); abrirModal('modalApartado'); });
    if ($('btnGuardarApartado')) $('btnGuardarApartado').addEventListener('click', guardarApartado);
    if ($('btnGuardarAnticipo')) $('btnGuardarAnticipo').addEventListener('click', registrarAnticipo);
    if ($('btnCancelarApartado')) $('btnCancelarApartado').addEventListener('click', abrirCancelarApartado);
    if ($('btnReactivarApartado')) $('btnReactivarApartado').addEventListener('click', abrirReactivarApartado);
    if ($('btnConfirmarReactivar')) $('btnConfirmarReactivar').addEventListener('click', confirmarReactivarApartado);
    if ($('btnConfirmarCancelarApartado')) $('btnConfirmarCancelarApartado').addEventListener('click', confirmarCancelarApartado);
    if ($('cancelacionRetencionPct')) $('cancelacionRetencionPct').addEventListener('input', actualizarResumenCancelacion);

    $('buscarApartado').addEventListener('input', function () { clearTimeout(estado.timerBusqueda); estado.timerBusqueda = setTimeout(() => { estado.pagina = 1; cargarApartados().catch((e) => mostrarMensaje('mensajePagina', e.message)); }, 350); });
    ['filtroEstado','filtroDesde','filtroHasta'].forEach((id) => $(id).addEventListener('change', () => { estado.pagina = 1; cargarApartados().catch((e) => mostrarMensaje('mensajePagina', e.message)); }));
    $('porPagina').addEventListener('change', function () { estado.porPagina = Number(this.value); estado.pagina = 1; cargarApartados().catch((e) => mostrarMensaje('mensajePagina', e.message)); });
    $('btnAnterior').addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina--; cargarApartados().catch((e) => mostrarMensaje('mensajePagina', e.message)); } });
    $('btnSiguiente').addEventListener('click', () => { if (estado.pagina < estado.totalPaginas) { estado.pagina++; cargarApartados().catch((e) => mostrarMensaje('mensajePagina', e.message)); } });
    $('tablaApartados').addEventListener('click', (e) => { const b = e.target.closest('[data-action="ver"]'); if (b) verDetalle(Number(b.dataset.id)).catch((x) => mostrarMensaje('mensajePagina', x.message)); });

    $('buscarClienteApartado').addEventListener('input', function () { if (estado.cotizacion) return; if (estado.cliente && this.value !== estado.cliente.codigo + ' · ' + estado.cliente.nombre_razon_social) { estado.cliente = null; $('apartadoClienteId').value = ''; renderCliente(); estado.lineas.forEach((l) => l.descuento = 0); renderLineas(); renderTotales(); } clearTimeout(estado.timerCliente); estado.timerCliente = setTimeout(() => buscarClientes(this.value).catch((e) => mostrarMensaje('mensajeApartado', e.message)), 300); });
    $('resultadosClientes').addEventListener('click', (e) => { const b = e.target.closest('[data-client-id]'); if (!b) return; const items = JSON.parse($('resultadosClientes').dataset.items || '[]'); const c = items.find((x) => Number(x.id) === Number(b.dataset.clientId)); if (!c) return; estado.cliente = c; $('apartadoClienteId').value = c.id; $('buscarClienteApartado').value = c.codigo + ' · ' + c.nombre_razon_social; $('resultadosClientes').hidden = true; estado.lineas.forEach((l) => l.descuento = Number(c.descuento_efectivo_pct || 0)); renderCliente(); renderLineas(); renderTotales(); });

    $('buscarProductoApartado').addEventListener('input', function () { clearTimeout(estado.timerProducto); estado.timerProducto = setTimeout(() => buscarProductos(this.value).catch((e) => mostrarMensaje('mensajeApartado', e.message)), 300); });
    $('resultadosProductos').addEventListener('click', (e) => { const b = e.target.closest('[data-product-id]'); if (!b) return; if (!estado.cliente) return mostrarMensaje('mensajeApartado', 'Selecciona primero el cliente.', 'error'); const items = JSON.parse($('resultadosProductos').dataset.items || '[]'); const p = items.find((x) => Number(x.id) === Number(b.dataset.productId)); if (p) agregarProducto(p).catch((x) => mostrarMensaje('mensajeApartado', x.message)); });

    $('tablaLineasApartado').addEventListener('input', (e) => {
        const tr = e.target.closest('[data-key]'); if (!tr) return; const l = estado.lineas.find((x) => Number(x.key) === Number(tr.dataset.key)); if (!l) return;
        if (e.target.dataset.lineField === 'cantidad') { l.cantidad = Number(e.target.value || 0); clearTimeout(estado.timersPrecio[l.key]); estado.timersPrecio[l.key] = setTimeout(async () => { await sugerirPrecio(l, false); renderLineas(); renderTotales(); }, 300); renderTotales(); }
        if (e.target.dataset.lineField === 'precio') { l.precio = Number(e.target.value || 0); l.precio_venta_id = 0; l.nivel_precio = 'MANUAL'; l.precio_origen = 'MANUAL'; l.precio_manual = Number(l.precio) > 0; renderTotales(); }
    });
    $('tablaLineasApartado').addEventListener('change', async (e) => {
        const tr = e.target.closest('[data-key]'); if (!tr) return; const l = estado.lineas.find((x) => Number(x.key) === Number(tr.dataset.key)); if (!l) return;
        if (e.target.dataset.lineField === 'presentacion') {
            const anterior = Number(l.presentacion_id || 0);
            const nueva = Number(e.target.value || 0);
            const duplicada = estado.lineas.some((x) => Number(x.key) !== Number(l.key) && Number(x.producto_id) === Number(l.producto_id) && Number(x.presentacion_id) === nueva);
            if (duplicada) {
                e.target.value = String(anterior);
                mostrarMensaje('mensajeApartado', 'Ese producto con esa presentación ya está agregado. Modifica la cantidad del renglón existente.', 'error');
                return;
            }
            l.presentacion_id = nueva;
            const p = l.presentaciones.find((x) => Number(x.id) === l.presentacion_id);
            if (p) { l.factor = Number(p.factor_a_unidad_base || 1); l.unidad_nombre = p.unidad_nombre || p.nombre; l.unidad_simbolo = p.unidad_simbolo || p.unidad_codigo || ''; }
            l.precio_manual = false;
            await sugerirPrecio(l, true); renderLineas(); renderTotales();
        }
    });
    $('tablaLineasApartado').addEventListener('click', (e) => { const b = e.target.closest('[data-line-action="eliminar"]'); if (!b) return; const tr = b.closest('[data-key]'); estado.lineas = estado.lineas.filter((x) => Number(x.key) !== Number(tr.dataset.key)); renderLineas(); renderTotales(); });

    $('apartadoMoneda').addEventListener('change', async () => { if (estado.cotizacion) return; for (const l of estado.lineas) { l.precio_manual = false; await sugerirPrecio(l, true); } renderLineas(); renderTotales(); });

    window.addEventListener('si:tipo-cambio-actualizado', async () => {
        if (estado.cotizacion || String(monedaActual().codigo || '').toUpperCase() !== 'USD') return;
        try {
            for (const linea of estado.lineas) await sugerirPrecio(linea, false);
            renderLineas();
            renderTotales();
        } catch (error) {
            mostrarMensaje('mensajeApartado', error.message || 'Se actualizó el FIX, pero no fue posible refrescar todos los precios sugeridos.', 'error');
        }
    });
    $('apartadoAlmacen').addEventListener('change', function () {
        const nuevo = Number(this.value || 0);
        if (estado.cotizacion) { estado.almacenSeleccionado = nuevo; estado.lineas.forEach((l) => l.disponible_base = null); renderLineas(); return; }
        if (estado.lineas.length) {
            if (!window.confirm('Cambiar de almacén requiere volver a seleccionar los productos para usar la disponibilidad correcta. ¿Limpiar los renglones?')) {
                this.value = String(estado.almacenSeleccionado || '');
                return;
            }
            estado.lineas = []; renderLineas(); renderTotales();
        }
        estado.almacenSeleccionado = nuevo;
    });

    if ($('btnAbrirAnticipo')) $('btnAbrirAnticipo').addEventListener('click', () => { const a = estado.detalle.apartado; $('formAnticipo').reset(); $('saldoAnticipoTexto').textContent = 'Saldo pendiente: ' + moneda(a.saldo_pendiente, a.moneda_codigo, a.moneda_simbolo); $('nuevoAnticipoImporte').max = String(a.saldo_pendiente); mostrarMensaje('mensajeAnticipo', ''); abrirModal('modalAnticipo'); });
    $('tablaAnticipos').addEventListener('click', (e) => { const b = e.target.closest('[data-anticipo-action="cancelar"]'); if (b) cancelarAnticipo(Number(b.dataset.id)); });

    Promise.resolve().then(cargarCatalogos).then(cargarApartados).then(() => { if (cotizacionInicial > 0) return cargarCotizacionParaApartar(cotizacionInicial); }).catch((e) => mostrarMensaje('mensajePagina', e.message, 'error'));
})();
</script>
</body>
</html>
