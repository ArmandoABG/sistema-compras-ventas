<?php

declare(strict_types=1);

if (isset($_GET['dev_api'])) {
    $endpoint = __DIR__ . '/../funciones/devoluciones_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/devoluciones_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('devoluciones.ver', false);

$tituloPagina = 'Devoluciones';
$csrfToken = si_token_csrf();
$puedeVenta = si_tiene_permiso('devoluciones.venta');
$puedeCompra = si_tiene_permiso('devoluciones.compra');
$puedeRegularizar = si_tiene_permiso('devoluciones.regularizar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_devoluciones.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Devoluciones | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_devoluciones.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content devoluciones-page">
            <header class="module-heading devoluciones-heading">
                <div>
                    <p class="module-eyebrow">DEVOLUCIONES · INVENTARIO · REGULARIZACIÓN FINANCIERA</p>
                    <h1>Devoluciones</h1>
                    <p>Devuelve mercancía sin borrar operaciones originales. Cada devolución confirmada queda ligada al documento fuente, actualiza Kardex e inventario y regulariza CxC/CxP cuando corresponde.</p>
                </div>
                <div class="devoluciones-heading__actions">
                    <?php if ($puedeVenta): ?><button type="button" class="btn-primary" id="btnNuevaVenta">Devolución de cliente</button><?php endif; ?>
                    <?php if ($puedeCompra): ?><button type="button" class="btn-secondary" id="btnNuevaCompra">Devolución a proveedor</button><?php endif; ?>
                </div>
            </header>

            <section class="flow-notes" aria-label="Reglas del módulo">
                <article><strong>Cliente</strong><span>Solo ventas con salida física confirmada por QR. La mercancía vuelve a existencia física.</span></article>
                <article><strong>Proveedor</strong><span>Solo mercancía realmente recibida. Nunca se toma stock reservado para devolver.</span></article>
                <article><strong>Finanzas</strong><span>Primero se compensa saldo pendiente; cualquier excedente queda como reembolso o reintegro.</span></article>
                <article><strong>Historial</strong><span>La venta, compra, pagos, anticipos y movimientos originales permanecen intactos.</span></article>
            </section>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="module-card devoluciones-card">
                <div class="module-tabs" role="tablist" aria-label="Tipo de consulta">
                    <?php if ($puedeVenta): ?><button type="button" class="module-tab<?= $puedeVenta ? ' is-active' : '' ?>" data-tab="VENTA">Clientes</button><?php endif; ?>
                    <?php if ($puedeCompra): ?><button type="button" class="module-tab<?= !$puedeVenta && $puedeCompra ? ' is-active' : '' ?>" data-tab="COMPRA">Proveedores</button><?php endif; ?>
                    <?php if ($puedeRegularizar): ?><button type="button" class="module-tab<?= !$puedeVenta && !$puedeCompra ? ' is-active' : '' ?>" data-tab="REGULARIZACIONES">Regularizaciones</button><?php endif; ?>
                </div>

                <div id="panelDevoluciones">
                    <div class="filters-grid devoluciones-filters">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarDevolucion" maxlength="180" placeholder="Folio de devolución, documento, tercero o motivo" autocomplete="off">
                        </label>
                        <label class="field">
                            <span>Mostrar</span>
                            <select id="porPaginaDevoluciones">
                                <option value="20">20 por página</option>
                                <option value="50">50 por página</option>
                                <option value="100">100 por página</option>
                            </select>
                        </label>
                    </div>

                    <div class="context-strip" id="contextoLista">Devoluciones de clientes confirmadas.</div>

                    <div class="table-wrap">
                        <table class="module-table devoluciones-table">
                            <thead>
                            <tr>
                                <th>Devolución</th>
                                <th>Documento origen</th>
                                <th>Tercero</th>
                                <th>Motivo</th>
                                <th>Regularización</th>
                                <th>Total</th>
                                <th>Usuario</th>
                                <th class="text-right">Acción</th>
                            </tr>
                            </thead>
                            <tbody id="tablaDevoluciones"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="module-pagination" id="paginacionDevoluciones"></div>
                </div>

                <?php if ($puedeRegularizar): ?>
                <div id="panelRegularizaciones" hidden>
                    <div class="filters-grid devoluciones-filters devoluciones-filters--reg">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarRegularizacion" maxlength="180" placeholder="Folio, devolución, documento o tercero" autocomplete="off">
                        </label>
                        <label class="field">
                            <span>Estado</span>
                            <select id="estadoRegularizacion">
                                <option value="TODAS">Todas</option>
                                <option value="PENDIENTE">Pendientes</option>
                                <option value="LIQUIDADA">Liquidadas</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Mostrar</span>
                            <select id="porPaginaRegularizaciones">
                                <option value="20">20 por página</option>
                                <option value="50">50 por página</option>
                                <option value="100">100 por página</option>
                            </select>
                        </label>
                    </div>
                    <div class="table-wrap">
                        <table class="module-table devoluciones-table">
                            <thead><tr><th>Regularización</th><th>Tipo</th><th>Documento</th><th>Tercero</th><th>Método / referencia</th><th>Importe</th><th>Estado</th><th class="text-right">Acción</th></tr></thead>
                            <tbody id="tablaRegularizaciones"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="module-pagination" id="paginacionRegularizaciones"></div>
                </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<!-- Devolución de cliente -->
<div class="modal-backdrop" id="modalVenta" hidden>
    <section class="modal-card modal-card--return" role="dialog" aria-modal="true" aria-labelledby="tituloModalVenta">
        <header class="modal-header">
            <div><small>DEVOLUCIÓN DE CLIENTE</small><h2 id="tituloModalVenta">Seleccionar venta</h2><p id="subtituloModalVenta">Busca una venta confirmada cuya salida física esté validada por QR.</p></div>
            <button type="button" class="modal-close" data-cerrar-modal="modalVenta" aria-label="Cerrar">×</button>
        </header>
        <div class="return-body">
            <div id="mensajeVenta" class="module-message" hidden></div>

            <section class="document-picker" id="selectorVenta">
                <label class="field field--search">
                    <span>Buscar venta *</span>
                    <input type="search" id="buscarVentaOrigen" maxlength="180" placeholder="Folio o cliente" autocomplete="off">
                </label>
                <p class="picker-help">Solo aparecen ventas confirmadas, con salida QR vigente y con cantidad pendiente por devolver.</p>
                <div id="resultadosVentas" class="document-results"><div class="empty-state">Escribe al menos 2 caracteres.</div></div>
            </section>

            <form id="formDevolucionVenta" hidden autocomplete="off">
                <input type="hidden" id="ventaId">
                <section class="source-summary" id="resumenVenta"></section>

                <div class="return-grid">
                    <label class="field">
                        <span>Almacén de entrada *</span>
                        <select id="almacenVenta" required></select>
                        <small>La mercancía regresará físicamente a este almacén.</small>
                    </label>
                    <label class="field field--wide">
                        <span>Motivo *</span>
                        <input type="text" id="motivoVenta" maxlength="255" minlength="5" placeholder="Ej. Producto rechazado por el cliente" required>
                    </label>
                </div>

                <section class="lines-section">
                    <div class="section-heading"><div><h3>Productos a devolver</h3><p>Captura únicamente lo que regresó físicamente. El máximo ya descuenta devoluciones anteriores.</p></div><strong id="estimadoVenta">Estimado: $0.00</strong></div>
                    <div class="table-wrap line-table-wrap">
                        <table class="module-table return-lines-table">
                            <thead><tr><th>Producto</th><th>Vendido</th><th>Devuelto antes</th><th>Máximo ahora</th><th>Cantidad a devolver</th><th>Importe pendiente</th></tr></thead>
                            <tbody id="lineasVenta"></tbody>
                        </table>
                    </div>
                </section>

                <div class="return-bottom-grid">
                    <label class="field"><span>Observaciones</span><textarea id="observacionesVenta" rows="4" maxlength="10000" placeholder="Notas internas opcionales"></textarea></label>
                    <aside class="finance-preview" id="finanzasVenta"></aside>
                </div>

                <?php if ($puedeRegularizar): ?>
                <section class="settlement-box">
                    <label class="check-row"><input type="checkbox" id="resolverVenta" checked><span><strong>Completar el reembolso en esta misma devolución</strong><small id="resumenResolverVenta">El sistema compensará primero cualquier saldo pendiente de CxC y solo devolverá al cliente el excedente.</small></span></label>
                    <div class="settlement-fields" id="camposResolverVenta" hidden>
                        <label class="field"><span>Método *</span><select id="metodoVenta"></select></label>
                        <label class="field"><span>Referencia</span><input type="text" id="referenciaVenta" maxlength="120"></label>
                        <label class="field field--wide"><span>Nota financiera</span><input type="text" id="notaFinVenta" maxlength="3000" placeholder="Opcional"></label>
                    </div>
                </section>
                <?php endif; ?>

                <section class="settlement-box">
                    <label class="check-row"><input type="checkbox" id="imprimirVentaAlConfirmar" checked><span><strong>Abrir ticket al confirmar</strong><small>Opcional. El comprobante también quedará disponible después en la lista y en Ver detalle.</small></span></label>
                </section>
            </form>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalVenta">Cerrar</button>
            <div class="modal-footer__right">
                <button type="button" class="btn-secondary" id="btnCambiarVenta" hidden>Cambiar venta</button>
                <button type="button" class="btn-primary" id="btnGuardarVenta" hidden>Confirmar devolución</button>
            </div>
        </footer>
    </section>
</div>

<!-- Devolución a proveedor -->
<div class="modal-backdrop" id="modalCompra" hidden>
    <section class="modal-card modal-card--return" role="dialog" aria-modal="true" aria-labelledby="tituloModalCompra">
        <header class="modal-header">
            <div><small>DEVOLUCIÓN A PROVEEDOR</small><h2 id="tituloModalCompra">Seleccionar compra</h2><p id="subtituloModalCompra">Busca una compra con recepción física confirmada.</p></div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCompra" aria-label="Cerrar">×</button>
        </header>
        <div class="return-body">
            <div id="mensajeCompra" class="module-message" hidden></div>

            <section class="document-picker" id="selectorCompra">
                <label class="field field--search"><span>Buscar compra *</span><input type="search" id="buscarCompraOrigen" maxlength="180" placeholder="Folio, proveedor o factura" autocomplete="off"></label>
                <p class="picker-help">La devolución se amarra a la recepción exacta y solo permite inventario disponible: físico menos reservado.</p>
                <div id="resultadosCompras" class="document-results"><div class="empty-state">Escribe al menos 2 caracteres.</div></div>
            </section>

            <form id="formDevolucionCompra" hidden autocomplete="off">
                <input type="hidden" id="compraId">
                <section class="source-summary" id="resumenCompra"></section>

                <div class="return-grid return-grid--purchase">
                    <label class="field field--wide"><span>Motivo *</span><input type="text" id="motivoCompra" maxlength="255" minlength="5" placeholder="Ej. Material fuera de especificación" required></label>
                </div>

                <section class="lines-section">
                    <div class="section-heading"><div><h3>Recepciones disponibles para devolver</h3><p>Cada renglón conserva su recepción y almacén de origen. No se permite tomar inventario reservado.</p></div><strong id="estimadoCompra">Estimado: $0.00</strong></div>
                    <div class="table-wrap line-table-wrap">
                        <table class="module-table return-lines-table return-lines-table--purchase">
                            <thead><tr><th>Producto</th><th>Recepción</th><th>Almacén</th><th>Recibido</th><th>Disponible actual</th><th>Máximo ahora</th><th>Cantidad a devolver</th></tr></thead>
                            <tbody id="lineasCompra"></tbody>
                        </table>
                    </div>
                </section>

                <div class="return-bottom-grid">
                    <label class="field"><span>Observaciones</span><textarea id="observacionesCompra" rows="4" maxlength="10000" placeholder="Notas internas opcionales"></textarea></label>
                    <aside class="finance-preview" id="finanzasCompra"></aside>
                </div>

                <?php if ($puedeRegularizar): ?>
                <section class="settlement-box">
                    <label class="check-row"><input type="checkbox" id="resolverCompra"><span><strong>Registrar reintegro del proveedor ahora, si resulta uno</strong><small>Si la devolución solo disminuye saldo pendiente de CxP, no se generará reintegro.</small></span></label>
                    <div class="settlement-fields" id="camposResolverCompra" hidden>
                        <label class="field"><span>Método *</span><select id="metodoCompra"></select></label>
                        <label class="field"><span>Referencia</span><input type="text" id="referenciaCompra" maxlength="120"></label>
                        <label class="field field--wide"><span>Nota financiera</span><input type="text" id="notaFinCompra" maxlength="3000" placeholder="Opcional"></label>
                    </div>
                </section>
                <?php endif; ?>

                <section class="settlement-box">
                    <label class="check-row"><input type="checkbox" id="imprimirCompraAlConfirmar" checked><span><strong>Abrir ticket al confirmar</strong><small>Opcional. El comprobante también quedará disponible después en la lista y en Ver detalle.</small></span></label>
                </section>
            </form>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn-secondary" data-cerrar-modal="modalCompra">Cerrar</button>
            <div class="modal-footer__right">
                <button type="button" class="btn-secondary" id="btnCambiarCompra" hidden>Cambiar compra</button>
                <button type="button" class="btn-primary" id="btnGuardarCompra" hidden>Confirmar devolución</button>
            </div>
        </footer>
    </section>
</div>

<!-- Detalle -->
<div class="modal-backdrop" id="modalDetalle" hidden>
    <section class="modal-card modal-card--detail" role="dialog" aria-modal="true" aria-labelledby="tituloDetalle">
        <header class="modal-header"><div><small>TRAZABILIDAD</small><h2 id="tituloDetalle">Detalle de devolución</h2><p id="subtituloDetalle"></p></div><button type="button" class="modal-close" data-cerrar-modal="modalDetalle" aria-label="Cerrar">×</button></header>
        <div class="detail-body">
            <div id="mensajeDetalle" class="module-message" hidden></div>
            <section class="detail-summary-grid" id="resumenDetalle"></section>
            <div class="detail-section-heading"><h3>Productos</h3></div>
            <div class="table-wrap"><table class="module-table"><thead><tr><th>Producto</th><th>Origen</th><th>Almacén</th><th>Cantidad</th><th>Base</th><th>Importe</th></tr></thead><tbody id="detalleProductos"></tbody></table></div>
            <div class="detail-section-heading"><h3>Regularización financiera</h3></div>
            <div id="detalleRegularizaciones" class="regularization-detail"></div>
        </div>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalDetalle">Cerrar</button><a class="btn-secondary btn-link" id="btnImprimirDetalle" target="_blank" rel="noopener" href="#" hidden>Imprimir ticket</a></footer>
    </section>
</div>

<?php if ($puedeRegularizar): ?>
<!-- Liquidar regularización -->
<div class="modal-backdrop modal-backdrop--nested" id="modalLiquidar" hidden>
    <section class="modal-card modal-card--small" role="dialog" aria-modal="true" aria-labelledby="tituloLiquidar">
        <header class="modal-header"><div><small>REGULARIZACIÓN FINANCIERA</small><h2 id="tituloLiquidar">Liquidar pendiente</h2><p id="subtituloLiquidar"></p></div><button type="button" class="modal-close" data-cerrar-modal="modalLiquidar" aria-label="Cerrar">×</button></header>
        <form class="settle-form" id="formLiquidar" autocomplete="off">
            <input type="hidden" id="regularizacionId">
            <div id="mensajeLiquidar" class="module-message" hidden></div>
            <label class="field"><span>Método *</span><select id="metodoLiquidar" required></select></label>
            <label class="field"><span>Referencia</span><input type="text" id="referenciaLiquidar" maxlength="120" placeholder="Obligatoria cuando el método la requiera"></label>
            <label class="field"><span>Observaciones</span><textarea id="observacionesLiquidar" rows="4" maxlength="3000"></textarea></label>
        </form>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalLiquidar">Cerrar</button><button type="button" class="btn-primary" id="btnConfirmarLiquidacion">Marcar como liquidada</button></footer>
    </section>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const puedeVenta = <?= $puedeVenta ? 'true' : 'false' ?>;
    const puedeCompra = <?= $puedeCompra ? 'true' : 'false' ?>;
    const puedeRegularizar = <?= $puedeRegularizar ? 'true' : 'false' ?>;
    const $ = (id) => document.getElementById(id);

    const estado = {
        tab: 'VENTA',
        devoluciones: { pagina: 1, totalPaginas: 1, timer: null },
        regularizaciones: { pagina: 1, totalPaginas: 1, timer: null, items: [] },
        catalogos: { almacenes: [], metodos_pago: [] },
        venta: null,
        compra: null,
        timerVenta: null,
        timerCompra: null,
        idempotenciaVenta: '',
        idempotenciaCompra: '',
        guardando: false
    };

    function nuevaClaveOperacion(prefijo) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return prefijo + '-' + window.crypto.randomUUID();
        }
        const aleatorio = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        return prefijo + '-' + Date.now().toString(36) + '-' + aleatorio;
    }

    function urlComprobante(tipo, id) {
        const devolucionId = Number(id || 0);
        if (!devolucionId) return '#';
        return tipo === 'COMPRA'
            ? 'devolucion_compra_imprimir.php?id=' + encodeURIComponent(devolucionId)
            : 'devolucion_venta_imprimir.php?id=' + encodeURIComponent(devolucionId);
    }

    function prepararVentanaComprobante(checkId) {
        const check = $(checkId);
        if (!check || !check.checked) return null;
        const ventana = window.open('', '_blank');
        if (!ventana) return null;
        try {
            ventana.opener = null;
            ventana.document.title = 'Preparando comprobante...';
            ventana.document.body.innerHTML = '<p style="font-family:Arial,sans-serif;padding:24px">Preparando ticket de devolución...</p>';
        } catch (_) {}
        return ventana;
    }

    function mostrarComprobante(ventana, tipo, id) {
        const url = urlComprobante(tipo, id);
        if (url === '#') {
            if (ventana && !ventana.closed) ventana.close();
            return false;
        }
        if (ventana && !ventana.closed) {
            ventana.location.href = url;
            return true;
        }
        return false;
    }

    function cerrarVentanaComprobante(ventana) {
        if (ventana && !ventana.closed) {
            try { ventana.close(); } catch (_) {}
        }
    }

    function escapeHtml(valor) {
        return String(valor ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    }

    function numero(valor, dec = 2) {
        return Number(valor || 0).toLocaleString('es-MX', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    function dinero(valor, simbolo = '$', codigo = '') {
        return escapeHtml(simbolo || '$') + numero(valor, 2) + (codigo ? ' <small>' + escapeHtml(codigo) + '</small>' : '');
    }

    function fecha(valor) {
        if (!valor) return '—';
        const t = String(valor).replace(' ', 'T');
        const d = new Date(t);
        if (Number.isNaN(d.getTime())) return escapeHtml(valor);
        return d.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
    }

    function mostrarMensaje(id, texto, tipo = 'error') {
        const el = $(id);
        if (!el) return;
        if (!texto) {
            el.hidden = true;
            el.textContent = '';
            el.className = 'module-message';
            return;
        }
        el.hidden = false;
        el.textContent = texto;
        el.className = 'module-message module-message--' + tipo;
    }

    function badge(valor) {
        const mapa = {
            CONFIRMADA: ['Confirmada', 'success'],
            PENDIENTE: ['Pendiente', 'warning'],
            LIQUIDADA: ['Liquidada', 'success'],
            NO_APLICA: ['No aplica', 'neutral']
        };
        const e = mapa[valor] || [valor || '—', 'neutral'];
        return '<span class="status-badge status-badge--' + e[1] + '">' + escapeHtml(e[0]) + '</span>';
    }

    async function apiGet(accion, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('dev_api', '1');
        url.searchParams.set('accion', accion);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v));
        });
        const r = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' });
        const data = await r.json().catch(() => ({ success: false, mensaje: 'La respuesta del servidor no es válida.' }));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), { data, status: r.status });
        return data;
    }

    async function apiPost(accion, datos = {}) {
        const body = new URLSearchParams();
        body.set('accion', accion);
        body.set('csrf_token', csrfToken);
        Object.entries(datos).forEach(([k, v]) => body.set(k, v === null || v === undefined ? '' : String(v)));
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('dev_api', '1');
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

    function abrirModal(id) {
        const modal = $(id);
        if (!modal) return;
        modal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function cerrarModal(id) {
        const modal = $(id);
        if (!modal) return;
        modal.hidden = true;
        if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open');
    }

    function setBusy(boton, busy, textoBusy = 'Procesando...') {
        if (!boton) return;
        if (busy) {
            boton.dataset.textoOriginal = boton.textContent;
            boton.disabled = true;
            boton.textContent = textoBusy;
        } else {
            boton.disabled = false;
            boton.textContent = boton.dataset.textoOriginal || boton.textContent;
        }
    }

    function opcionesMetodos(seleccionar = true) {
        return (seleccionar ? '<option value="">Seleccionar</option>' : '') + estado.catalogos.metodos_pago.map((m) =>
            '<option value="' + Number(m.id) + '">' + escapeHtml(m.nombre) + '</option>'
        ).join('');
    }

    async function cargarCatalogos() {
        const r = await apiGet('CATALOGOS');
        estado.catalogos.almacenes = r.almacenes || [];
        estado.catalogos.metodos_pago = r.metodos_pago || [];
        if ($('almacenVenta')) $('almacenVenta').innerHTML = estado.catalogos.almacenes.map((a) => '<option value="' + Number(a.id) + '">' + escapeHtml(a.codigo + ' · ' + a.nombre) + '</option>').join('');
        ['metodoVenta', 'metodoCompra', 'metodoLiquidar'].forEach((id) => { if ($(id)) $(id).innerHTML = opcionesMetodos(true); });
    }

    function cambiarTab(tab) {
        estado.tab = tab;
        document.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('is-active', b.dataset.tab === tab));
        const esReg = tab === 'REGULARIZACIONES';
        $('panelDevoluciones').hidden = esReg;
        if ($('panelRegularizaciones')) $('panelRegularizaciones').hidden = !esReg;
        mostrarMensaje('mensajePagina', '');
        if (esReg) {
            estado.regularizaciones.pagina = 1;
            cargarRegularizaciones();
        } else {
            estado.devoluciones.pagina = 1;
            $('contextoLista').textContent = tab === 'VENTA' ? 'Devoluciones de clientes confirmadas.' : 'Devoluciones a proveedores confirmadas.';
            cargarDevoluciones();
        }
    }

    async function cargarDevoluciones() {
        $('tablaDevoluciones').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        try {
            const r = await apiGet('LISTAR_DEVOLUCIONES', {
                tipo: estado.tab,
                busqueda: $('buscarDevolucion').value.trim(),
                pagina: estado.devoluciones.pagina,
                por_pagina: $('porPaginaDevoluciones').value
            });
            const p = r.paginacion || {};
            estado.devoluciones.pagina = Number(p.pagina || 1);
            estado.devoluciones.totalPaginas = Number(p.total_paginas || 1);
            renderDevoluciones(r.devoluciones || []);
            renderPaginacion('paginacionDevoluciones', estado.devoluciones.pagina, estado.devoluciones.totalPaginas, Number(p.total_registros || 0), (pag) => { estado.devoluciones.pagina = pag; cargarDevoluciones(); });
        } catch (e) {
            $('tablaDevoluciones').innerHTML = '<tr><td colspan="8" class="empty-cell empty-cell--error">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    function renderDevoluciones(items) {
        if (!items.length) {
            $('tablaDevoluciones').innerHTML = '<tr><td colspan="8" class="empty-cell">No hay devoluciones confirmadas con estos filtros.</td></tr>';
            return;
        }
        $('tablaDevoluciones').innerHTML = items.map((d) => {
            const esVenta = estado.tab === 'VENTA';
            const compensado = esVenta ? Number(d.importe_compensado_cxc || 0) : Number(d.importe_compensado_cxp || 0);
            const contra = esVenta ? Number(d.importe_reembolso || 0) : Number(d.importe_reintegro || 0);
            const labelComp = esVenta ? 'CxC compensada' : 'CxP compensada';
            const labelContra = esVenta ? 'Reembolso' : 'Reintegro';
            return '<tr>' +
                '<td><strong>' + escapeHtml(d.folio) + '</strong><small class="cell-secondary">' + fecha(d.fecha_devolucion) + ' · ' + Number(d.renglones || 0) + ' renglón(es)</small></td>' +
                '<td><strong>' + escapeHtml(d.documento_folio) + '</strong><small class="cell-secondary">' + (esVenta ? 'Venta' : 'Compra') + '</small></td>' +
                '<td>' + escapeHtml(d.tercero) + '</td>' +
                '<td><span class="truncate-cell" title="' + escapeHtml(d.motivo) + '">' + escapeHtml(d.motivo) + '</span></td>' +
                '<td>' + badge(d.regularizacion_estado) + '<small class="cell-secondary">' + labelComp + ': ' + dinero(compensado, d.moneda_simbolo, d.moneda_codigo) + '<br>' + labelContra + ': ' + dinero(contra, d.moneda_simbolo, d.moneda_codigo) + '</small></td>' +
                '<td><strong>' + dinero(d.total, d.moneda_simbolo, d.moneda_codigo) + '</strong></td>' +
                '<td>' + escapeHtml(d.creado_por || '—') + '</td>' +
                '<td class="text-right">' +
                    '<button type="button" class="table-action" data-detalle="' + Number(d.id) + '" data-tipo="' + estado.tab + '">Ver detalle</button>' +
                    ' <a class="table-action table-action--link" target="_blank" rel="noopener" href="' + urlComprobante(estado.tab, d.id) + '">Imprimir ticket</a>' +
                '</td>' +
            '</tr>';
        }).join('');
    }

    async function cargarRegularizaciones() {
        if (!puedeRegularizar || !$('tablaRegularizaciones')) return;
        $('tablaRegularizaciones').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        try {
            const r = await apiGet('LISTAR_REGULARIZACIONES', {
                busqueda: $('buscarRegularizacion').value.trim(),
                estado: $('estadoRegularizacion').value,
                pagina: estado.regularizaciones.pagina,
                por_pagina: $('porPaginaRegularizaciones').value
            });
            estado.regularizaciones.items = r.regularizaciones || [];
            const p = r.paginacion || {};
            estado.regularizaciones.pagina = Number(p.pagina || 1);
            estado.regularizaciones.totalPaginas = Number(p.total_paginas || 1);
            renderRegularizaciones();
            renderPaginacion('paginacionRegularizaciones', estado.regularizaciones.pagina, estado.regularizaciones.totalPaginas, Number(p.total_registros || 0), (pag) => { estado.regularizaciones.pagina = pag; cargarRegularizaciones(); });
        } catch (e) {
            $('tablaRegularizaciones').innerHTML = '<tr><td colspan="8" class="empty-cell empty-cell--error">' + escapeHtml(e.message) + '</td></tr>';
        }
    }

    function renderRegularizaciones() {
        const items = estado.regularizaciones.items;
        if (!items.length) {
            $('tablaRegularizaciones').innerHTML = '<tr><td colspan="8" class="empty-cell">No hay regularizaciones con estos filtros.</td></tr>';
            return;
        }
        $('tablaRegularizaciones').innerHTML = items.map((r) => {
            const tipo = r.tipo === 'REEMBOLSO_CLIENTE' ? 'Reembolso a cliente' : 'Reintegro de proveedor';
            const metodo = r.estado === 'LIQUIDADA' ? escapeHtml(r.metodo_nombre || 'Sin método') : 'Pendiente de liquidar';
            const referencia = r.referencia ? '<small class="cell-secondary">Ref. ' + escapeHtml(r.referencia) + '</small>' : '';
            return '<tr>' +
                '<td><strong>' + escapeHtml(r.folio) + '</strong><small class="cell-secondary">' + escapeHtml(r.devolucion_folio || '—') + ' · ' + fecha(r.created_at) + '</small></td>' +
                '<td>' + escapeHtml(tipo) + '</td>' +
                '<td>' + escapeHtml(r.documento_folio || '—') + '</td>' +
                '<td>' + escapeHtml(r.tercero || '—') + '</td>' +
                '<td>' + metodo + referencia + '</td>' +
                '<td><strong>' + dinero(r.importe, r.moneda_simbolo, r.moneda_codigo) + '</strong></td>' +
                '<td>' + badge(r.estado) + (r.liquidada_at ? '<small class="cell-secondary">' + fecha(r.liquidada_at) + '</small>' : '') + '</td>' +
                '<td class="text-right">' + (r.estado === 'PENDIENTE' ? '<button type="button" class="table-action table-action--success" data-liquidar="' + Number(r.id) + '">Liquidar</button>' : '—') + '</td>' +
            '</tr>';
        }).join('');
    }

    function renderPaginacion(id, pagina, totalPaginas, totalRegistros, onChange) {
        const el = $(id);
        if (!el) return;
        el.innerHTML = '<span>' + Number(totalRegistros) + ' registro(s)</span><div><button type="button" class="btn-secondary page-prev" ' + (pagina <= 1 ? 'disabled' : '') + '>Anterior</button><strong>Página ' + pagina + ' de ' + totalPaginas + '</strong><button type="button" class="btn-secondary page-next" ' + (pagina >= totalPaginas ? 'disabled' : '') + '>Siguiente</button></div>';
        el.querySelector('.page-prev').addEventListener('click', () => onChange(Math.max(1, pagina - 1)));
        el.querySelector('.page-next').addEventListener('click', () => onChange(Math.min(totalPaginas, pagina + 1)));
    }

    function resetVenta() {
        estado.venta = null;
        estado.idempotenciaVenta = nuevaClaveOperacion('DEV-VTA');
        $('ventaId').value = '';
        $('buscarVentaOrigen').value = '';
        $('resultadosVentas').innerHTML = '<div class="empty-state">Escribe al menos 2 caracteres.</div>';
        $('selectorVenta').hidden = false;
        $('formDevolucionVenta').hidden = true;
        $('btnCambiarVenta').hidden = true;
        $('btnGuardarVenta').hidden = true;
        $('tituloModalVenta').textContent = 'Seleccionar venta';
        $('subtituloModalVenta').textContent = 'Busca una venta confirmada cuya salida física esté validada por QR.';
        $('motivoVenta').value = '';
        $('observacionesVenta').value = '';
        if ($('resolverVenta')) $('resolverVenta').checked = true;
        if ($('resumenResolverVenta')) $('resumenResolverVenta').textContent = 'El sistema compensará primero cualquier saldo pendiente de CxC y solo devolverá al cliente el excedente.';
        if ($('camposResolverVenta')) $('camposResolverVenta').hidden = true;
        if ($('metodoVenta')) $('metodoVenta').value = '';
        if ($('referenciaVenta')) $('referenciaVenta').value = '';
        if ($('notaFinVenta')) $('notaFinVenta').value = '';
        if ($('imprimirVentaAlConfirmar')) $('imprimirVentaAlConfirmar').checked = true;
        mostrarMensaje('mensajeVenta', '');
    }

    function abrirVenta() {
        resetVenta();
        abrirModal('modalVenta');
        setTimeout(() => $('buscarVentaOrigen').focus(), 0);
    }

    async function buscarVentas() {
        const q = $('buscarVentaOrigen').value.trim();
        if (q.length < 2) {
            $('resultadosVentas').innerHTML = '<div class="empty-state">Escribe al menos 2 caracteres.</div>';
            return;
        }
        $('resultadosVentas').innerHTML = '<div class="empty-state">Buscando...</div>';
        try {
            const r = await apiGet('BUSCAR_VENTAS', { q });
            const items = r.ventas || [];
            if (!items.length) {
                $('resultadosVentas').innerHTML = '<div class="empty-state">No se encontraron ventas elegibles. Recuerda que la salida debe estar confirmada por QR.</div>';
                return;
            }
            $('resultadosVentas').innerHTML = items.map((v) => '<button type="button" class="document-result" data-venta="' + Number(v.id) + '"><span><strong>' + escapeHtml(v.folio) + '</strong><small>' + escapeHtml(v.cliente) + ' · ' + fecha(v.fecha_venta) + '</small></span><span><strong>' + dinero(v.total_restante, v.moneda_simbolo, v.moneda_codigo) + '</strong><small>Pendiente por devolver</small></span></button>').join('');
        } catch (e) {
            $('resultadosVentas').innerHTML = '<div class="empty-state empty-state--error">' + escapeHtml(e.message) + '</div>';
        }
    }

    async function seleccionarVenta(id) {
        mostrarMensaje('mensajeVenta', '');
        $('resultadosVentas').innerHTML = '<div class="empty-state">Preparando venta...</div>';
        try {
            const r = await apiGet('PREPARAR_VENTA', { venta_id: id });
            estado.venta = r;
            $('ventaId').value = Number(r.venta.id);
            $('selectorVenta').hidden = true;
            $('formDevolucionVenta').hidden = false;
            $('btnCambiarVenta').hidden = false;
            $('btnGuardarVenta').hidden = false;
            $('tituloModalVenta').textContent = r.venta.folio;
            $('subtituloModalVenta').textContent = 'Salida QR confirmada el ' + (r.salida_qr.usado_at || '—') + '. La venta original no será eliminada ni reescrita.';
            renderResumenVenta();
            renderLineasVenta();
            renderFinanzasVenta();
            actualizarEstimadoVenta();
        } catch (e) {
            resetVenta();
            mostrarMensaje('mensajeVenta', e.message, 'error');
        }
    }

    function renderResumenVenta() {
        const v = estado.venta.venta;
        $('resumenVenta').innerHTML = '<article><span>Venta</span><strong>' + escapeHtml(v.folio) + '</strong><small>' + fecha(v.fecha_venta) + '</small></article>' +
            '<article><span>Cliente</span><strong>' + escapeHtml(v.cliente) + '</strong><small>' + escapeHtml(v.condicion_pago) + '</small></article>' +
            '<article><span>Total original</span><strong>' + dinero(v.total, v.moneda_simbolo, v.moneda_codigo) + '</strong><small>Documento histórico</small></article>' +
            '<article><span>Pendiente por devolver</span><strong>' + dinero(v.total_restante, v.moneda_simbolo, v.moneda_codigo) + '</strong><small>Después de devoluciones previas</small></article>';
    }

    function renderLineasVenta() {
        const lineas = estado.venta.lineas || [];
        $('lineasVenta').innerHTML = lineas.map((l) => '<tr><td><strong>' + escapeHtml(l.producto) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku || '') + ' · ' + escapeHtml(l.unidad_simbolo || l.unidad) + '</small></td><td>' + numero(l.cantidad, 6) + '</td><td>' + numero(Number(l.cantidad_devuelta_base || 0) / Number(l.factor_a_unidad_base || 1), 6) + '</td><td><strong>' + numero(l.cantidad_restante, 6) + '</strong></td><td><input class="qty-input qty-venta" type="number" min="0" step="0.000001" max="' + Number(l.cantidad_restante) + '" data-id="' + Number(l.venta_detalle_id) + '" data-max="' + Number(l.cantidad_restante) + '" value="0"></td><td>' + dinero(l.importe_restante, estado.venta.venta.moneda_simbolo, estado.venta.venta.moneda_codigo) + '</td></tr>').join('');
    }

    function renderFinanzasVenta() {
        const f = estado.venta.finanzas || {};
        const v = estado.venta.venta;
        $('finanzasVenta').innerHTML = '<h3>Situación financiera actual</h3>' +
            '<div><span>Saldo CxC pendiente</span><strong>' + dinero(f.saldo_cxc, v.moneda_simbolo, v.moneda_codigo) + '</strong></div>' +
            '<div><span>Pagos de venta</span><strong>' + dinero(f.pagos_venta, v.moneda_simbolo, v.moneda_codigo) + '</strong></div>' +
            '<div><span>Anticipos de apartado</span><strong>' + dinero(f.anticipos_apartado, v.moneda_simbolo, v.moneda_codigo) + '</strong></div>' +
            '<p>Al confirmar, el sistema compensa primero la CxC pendiente. Solo el excedente se registra como reembolso al cliente.</p>';
    }

    function estimadoFinancieroVenta() {
        if (!estado.venta) return { total: 0, compensado: 0, reembolso: 0 };
        let total = 0;
        document.querySelectorAll('.qty-venta').forEach((input) => {
            const l = (estado.venta.lineas || []).find((x) => Number(x.venta_detalle_id) === Number(input.dataset.id));
            const qty = Math.max(0, Math.min(Number(input.value || 0), Number(input.dataset.max || 0)));
            if (l && Number(l.cantidad_restante) > 0) {
                total += Number(l.importe_restante || 0) * (qty / Number(l.cantidad_restante));
            }
        });
        total = Math.max(0, Math.round((total + Number.EPSILON) * 10000) / 10000);
        const saldoCxc = Math.max(0, Number((estado.venta.finanzas || {}).saldo_cxc || 0));
        const compensado = Math.min(total, saldoCxc);
        const reembolso = Math.max(0, total - compensado);
        return { total, compensado, reembolso };
    }

    function actualizarLiquidacionVenta() {
        if (!estado.venta || !$('resolverVenta')) return;
        const e = estimadoFinancieroVenta();
        const simbolo = estado.venta.venta.moneda_simbolo || '$';
        const codigo = estado.venta.venta.moneda_codigo || '';
        const sufijo = codigo ? ' ' + codigo : '';
        if ($('resumenResolverVenta')) {
            $('resumenResolverVenta').textContent = e.reembolso > 0.00005
                ? 'Reembolso estimado al cliente: ' + simbolo + numero(e.reembolso, 2) + sufijo + '. Se registrará dentro de la misma devolución.'
                : 'No se estima salida de dinero: el importe se aplicará contra el saldo pendiente de CxC, si existe.';
        }
        if ($('camposResolverVenta')) {
            $('camposResolverVenta').hidden = !$('resolverVenta').checked || e.reembolso <= 0.00005;
        }
    }

    function actualizarEstimadoVenta() {
        if (!estado.venta) return;
        const e = estimadoFinancieroVenta();
        $('estimadoVenta').innerHTML = 'Estimado: ' + dinero(e.total, estado.venta.venta.moneda_simbolo, estado.venta.venta.moneda_codigo);
        actualizarLiquidacionVenta();
    }

    function lineasVentaSeleccionadas() {
        return Array.from(document.querySelectorAll('.qty-venta')).map((input) => ({ venta_detalle_id: Number(input.dataset.id), cantidad: Number(input.value || 0) })).filter((x) => x.cantidad > 0);
    }

    async function guardarVenta() {
        if (estado.guardando || !estado.venta) return;
        mostrarMensaje('mensajeVenta', '');
        const lineas = lineasVentaSeleccionadas();
        if (!lineas.length) return mostrarMensaje('mensajeVenta', 'Captura al menos una cantidad a devolver.', 'error');
        if ($('motivoVenta').value.trim().length < 5) return mostrarMensaje('mensajeVenta', 'Indica un motivo de al menos 5 caracteres.', 'error');
        if (!$('almacenVenta').value) return mostrarMensaje('mensajeVenta', 'Selecciona el almacén de entrada.', 'error');

        const estimado = estimadoFinancieroVenta();
        const resolverAhora = $('resolverVenta') && $('resolverVenta').checked;
        if (resolverAhora && estimado.reembolso > 0.00005 && (!$('metodoVenta') || !$('metodoVenta').value)) {
            return mostrarMensaje('mensajeVenta', 'Selecciona el método con el que se entregará el reembolso al cliente.', 'error');
        }
        if (!resolverAhora && estimado.reembolso > 0.00005) {
            const continuarPendiente = window.confirm('Esta devolución genera un reembolso al cliente. Si continúas sin liquidarlo, el dinero quedará registrado como pendiente. ¿Deseas continuar así?');
            if (!continuarPendiente) return;
        }

        const ventanaComprobante = prepararVentanaComprobante('imprimirVentaAlConfirmar');
        estado.guardando = true;
        setBusy($('btnGuardarVenta'), true, 'Confirmando...');
        try {
            const r = await apiPost('REGISTRAR_DEVOLUCION_VENTA', {
                venta_id: $('ventaId').value,
                almacen_id: $('almacenVenta').value,
                motivo: $('motivoVenta').value.trim(),
                observaciones: $('observacionesVenta').value.trim(),
                lineas: JSON.stringify(lineas),
                resolver_regularizacion: resolverAhora ? '1' : '0',
                metodo_pago_id: $('metodoVenta') ? $('metodoVenta').value : '',
                referencia: $('referenciaVenta') ? $('referenciaVenta').value.trim() : '',
                observacion_financiera: $('notaFinVenta') ? $('notaFinVenta').value.trim() : '',
                idempotency_key: estado.idempotenciaVenta
            });
            cerrarModal('modalVenta');
            const comprobanteAbierto = mostrarComprobante(ventanaComprobante, 'VENTA', r.devolucion_id);
            mostrarMensaje('mensajePagina', r.mensaje + (comprobanteAbierto ? ' El ticket se abrió en una nueva pestaña.' : ' Puedes imprimir el ticket desde la columna Acción o desde Ver detalle.'), 'success');
            estado.tab = 'VENTA';
            document.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('is-active', b.dataset.tab === 'VENTA'));
            $('panelDevoluciones').hidden = false;
            if ($('panelRegularizaciones')) $('panelRegularizaciones').hidden = true;
            estado.devoluciones.pagina = 1;
            await cargarDevoluciones();
        } catch (e) {
            cerrarVentanaComprobante(ventanaComprobante);
            mostrarMensaje('mensajeVenta', e.message, 'error');
        } finally {
            estado.guardando = false;
            setBusy($('btnGuardarVenta'), false);
        }
    }

    function resetCompra() {
        estado.compra = null;
        estado.idempotenciaCompra = nuevaClaveOperacion('DEV-CMP');
        $('compraId').value = '';
        $('buscarCompraOrigen').value = '';
        $('resultadosCompras').innerHTML = '<div class="empty-state">Escribe al menos 2 caracteres.</div>';
        $('selectorCompra').hidden = false;
        $('formDevolucionCompra').hidden = true;
        $('btnCambiarCompra').hidden = true;
        $('btnGuardarCompra').hidden = true;
        $('tituloModalCompra').textContent = 'Seleccionar compra';
        $('subtituloModalCompra').textContent = 'Busca una compra con recepción física confirmada.';
        $('motivoCompra').value = '';
        $('observacionesCompra').value = '';
        if ($('resolverCompra')) $('resolverCompra').checked = false;
        if ($('camposResolverCompra')) $('camposResolverCompra').hidden = true;
        if ($('metodoCompra')) $('metodoCompra').value = '';
        if ($('referenciaCompra')) $('referenciaCompra').value = '';
        if ($('notaFinCompra')) $('notaFinCompra').value = '';
        if ($('imprimirCompraAlConfirmar')) $('imprimirCompraAlConfirmar').checked = true;
        mostrarMensaje('mensajeCompra', '');
    }

    function abrirCompra() {
        resetCompra();
        abrirModal('modalCompra');
        setTimeout(() => $('buscarCompraOrigen').focus(), 0);
    }

    async function buscarCompras() {
        const q = $('buscarCompraOrigen').value.trim();
        if (q.length < 2) {
            $('resultadosCompras').innerHTML = '<div class="empty-state">Escribe al menos 2 caracteres.</div>';
            return;
        }
        $('resultadosCompras').innerHTML = '<div class="empty-state">Buscando...</div>';
        try {
            const r = await apiGet('BUSCAR_COMPRAS', { q });
            const items = r.compras || [];
            if (!items.length) {
                $('resultadosCompras').innerHTML = '<div class="empty-state">No hay compras con recepción confirmada y cantidades pendientes por devolver.</div>';
                return;
            }
            $('resultadosCompras').innerHTML = items.map((c) => '<button type="button" class="document-result" data-compra="' + Number(c.id) + '"><span><strong>' + escapeHtml(c.folio) + '</strong><small>' + escapeHtml(c.proveedor) + ' · ' + fecha(c.fecha_compra) + '</small></span><span><strong>' + dinero(c.total_restante, c.moneda_simbolo, c.moneda_codigo) + '</strong><small>Pendiente por devolver</small></span></button>').join('');
        } catch (e) {
            $('resultadosCompras').innerHTML = '<div class="empty-state empty-state--error">' + escapeHtml(e.message) + '</div>';
        }
    }

    async function seleccionarCompra(id) {
        mostrarMensaje('mensajeCompra', '');
        $('resultadosCompras').innerHTML = '<div class="empty-state">Preparando compra...</div>';
        try {
            const r = await apiGet('PREPARAR_COMPRA', { compra_id: id });
            estado.compra = r;
            $('compraId').value = Number(r.compra.id);
            $('selectorCompra').hidden = true;
            $('formDevolucionCompra').hidden = false;
            $('btnCambiarCompra').hidden = false;
            $('btnGuardarCompra').hidden = false;
            $('tituloModalCompra').textContent = r.compra.folio;
            $('subtituloModalCompra').textContent = 'La salida se registrará contra cada recepción y almacén originales, sin tocar inventario reservado.';
            renderResumenCompra();
            renderLineasCompra();
            renderFinanzasCompra();
            actualizarEstimadoCompra();
        } catch (e) {
            resetCompra();
            mostrarMensaje('mensajeCompra', e.message, 'error');
        }
    }

    function renderResumenCompra() {
        const c = estado.compra.compra;
        $('resumenCompra').innerHTML = '<article><span>Compra</span><strong>' + escapeHtml(c.folio) + '</strong><small>' + fecha(c.fecha_compra) + '</small></article>' +
            '<article><span>Proveedor</span><strong>' + escapeHtml(c.proveedor_nombre_snapshot) + '</strong><small>' + escapeHtml(c.condicion_pago) + '</small></article>' +
            '<article><span>Total original</span><strong>' + dinero(c.total, c.moneda_simbolo, c.moneda_codigo) + '</strong><small>Documento histórico</small></article>' +
            '<article><span>Pendiente por devolver</span><strong>' + dinero(c.total_restante, c.moneda_simbolo, c.moneda_codigo) + '</strong><small>Después de devoluciones previas</small></article>';
    }

    function renderLineasCompra() {
        const lineas = estado.compra.lineas || [];
        $('lineasCompra').innerHTML = lineas.map((l) => '<tr><td><strong>' + escapeHtml(l.producto) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku || '') + ' · ' + escapeHtml(l.unidad_simbolo || l.unidad) + '</small></td><td><strong>' + escapeHtml(l.recepcion_folio) + '</strong><small class="cell-secondary">' + fecha(l.fecha_recepcion) + '</small></td><td>' + escapeHtml(l.almacen_codigo + ' · ' + l.almacen) + '</td><td>' + numero(l.cantidad_recibida, 6) + '</td><td>' + numero(Number(l.cantidad_disponible || 0) / Number(l.factor_a_unidad_base || 1), 6) + '<small class="cell-secondary">Base disp.: ' + numero(l.cantidad_disponible, 6) + '</small></td><td><strong>' + numero(l.cantidad_maxima, 6) + '</strong></td><td><input class="qty-input qty-compra" type="number" min="0" step="0.000001" max="' + Number(l.cantidad_maxima) + '" data-id="' + Number(l.recepcion_detalle_id) + '" data-max="' + Number(l.cantidad_maxima) + '" value="0"></td></tr>').join('');
    }

    function renderFinanzasCompra() {
        const f = estado.compra.finanzas || {};
        const c = estado.compra.compra;
        $('finanzasCompra').innerHTML = '<h3>Situación financiera actual</h3>' +
            '<div><span>Saldo CxP pendiente</span><strong>' + dinero(f.saldo_cxp, c.moneda_simbolo, c.moneda_codigo) + '</strong></div>' +
            '<div><span>Importe pagado en CxP</span><strong>' + dinero(f.cxp_importe_pagado, c.moneda_simbolo, c.moneda_codigo) + '</strong></div>' +
            '<p>Al confirmar, el sistema disminuye primero la CxP pendiente. Solo el excedente se registra como reintegro del proveedor.</p>';
    }

    function actualizarEstimadoCompra() {
        if (!estado.compra) return;
        const grupos = new Map();
        document.querySelectorAll('.qty-compra').forEach((input) => {
            const l = (estado.compra.lineas || []).find((x) => Number(x.recepcion_detalle_id) === Number(input.dataset.id));
            if (!l) return;
            const qty = Math.max(0, Math.min(Number(input.value || 0), Number(input.dataset.max || 0)));
            const base = qty * Number(l.factor_a_unidad_base || 1);
            const key = Number(l.compra_detalle_id);
            if (!grupos.has(key)) grupos.set(key, { base: 0, totalBase: Number(l.cantidad_comprada_base || 0), importe: Number(l.importe_linea_compra || 0) });
            grupos.get(key).base += base;
        });
        let total = 0;
        grupos.forEach((g) => { if (g.totalBase > 0) total += g.importe * (g.base / g.totalBase); });
        $('estimadoCompra').innerHTML = 'Estimado: ' + dinero(total, estado.compra.compra.moneda_simbolo, estado.compra.compra.moneda_codigo);
    }

    function lineasCompraSeleccionadas() {
        return Array.from(document.querySelectorAll('.qty-compra')).map((input) => ({ recepcion_detalle_id: Number(input.dataset.id), cantidad: Number(input.value || 0) })).filter((x) => x.cantidad > 0);
    }

    async function guardarCompra() {
        if (estado.guardando || !estado.compra) return;
        mostrarMensaje('mensajeCompra', '');
        const lineas = lineasCompraSeleccionadas();
        if (!lineas.length) return mostrarMensaje('mensajeCompra', 'Captura al menos una cantidad a devolver.', 'error');
        if ($('motivoCompra').value.trim().length < 5) return mostrarMensaje('mensajeCompra', 'Indica un motivo de al menos 5 caracteres.', 'error');

        const ventanaComprobante = prepararVentanaComprobante('imprimirCompraAlConfirmar');
        estado.guardando = true;
        setBusy($('btnGuardarCompra'), true, 'Confirmando...');
        try {
            const r = await apiPost('REGISTRAR_DEVOLUCION_COMPRA', {
                compra_id: $('compraId').value,
                motivo: $('motivoCompra').value.trim(),
                observaciones: $('observacionesCompra').value.trim(),
                lineas: JSON.stringify(lineas),
                resolver_regularizacion: $('resolverCompra') && $('resolverCompra').checked ? '1' : '0',
                metodo_pago_id: $('metodoCompra') ? $('metodoCompra').value : '',
                referencia: $('referenciaCompra') ? $('referenciaCompra').value.trim() : '',
                observacion_financiera: $('notaFinCompra') ? $('notaFinCompra').value.trim() : '',
                idempotency_key: estado.idempotenciaCompra
            });
            cerrarModal('modalCompra');
            const comprobanteAbierto = mostrarComprobante(ventanaComprobante, 'COMPRA', r.devolucion_id);
            mostrarMensaje('mensajePagina', r.mensaje + (comprobanteAbierto ? ' El ticket se abrió en una nueva pestaña.' : ' Puedes imprimir el ticket desde la columna Acción o desde Ver detalle.'), 'success');
            estado.tab = 'COMPRA';
            document.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('is-active', b.dataset.tab === 'COMPRA'));
            $('panelDevoluciones').hidden = false;
            if ($('panelRegularizaciones')) $('panelRegularizaciones').hidden = true;
            $('contextoLista').textContent = 'Devoluciones a proveedores confirmadas.';
            estado.devoluciones.pagina = 1;
            await cargarDevoluciones();
        } catch (e) {
            cerrarVentanaComprobante(ventanaComprobante);
            mostrarMensaje('mensajeCompra', e.message, 'error');
        } finally {
            estado.guardando = false;
            setBusy($('btnGuardarCompra'), false);
        }
    }

    async function abrirDetalle(tipo, id) {
        abrirModal('modalDetalle');
        mostrarMensaje('mensajeDetalle', '');
        $('tituloDetalle').textContent = 'Cargando...';
        $('subtituloDetalle').textContent = '';
        $('resumenDetalle').innerHTML = '';
        $('detalleProductos').innerHTML = '<tr><td colspan="6" class="empty-cell">Cargando...</td></tr>';
        $('detalleRegularizaciones').innerHTML = '';
        if ($('btnImprimirDetalle')) {
            $('btnImprimirDetalle').hidden = false;
            $('btnImprimirDetalle').href = urlComprobante(tipo, id);
        }
        try {
            const r = await apiGet('DETALLE', { tipo, id });
            const d = r.devolucion;
            $('tituloDetalle').textContent = d.folio;
            $('subtituloDetalle').textContent = (tipo === 'VENTA' ? 'Venta ' : 'Compra ') + d.documento_folio + ' · ' + d.tercero;
            const comp = tipo === 'VENTA' ? Number(d.importe_compensado_cxc || 0) : Number(d.importe_compensado_cxp || 0);
            const contra = tipo === 'VENTA' ? Number(d.importe_reembolso || 0) : Number(d.importe_reintegro || 0);
            $('resumenDetalle').innerHTML = '<article><span>Fecha</span><strong>' + fecha(d.fecha_devolucion) + '</strong><small>' + escapeHtml(d.creado_por || '—') + '</small></article>' +
                '<article><span>Total devuelto</span><strong>' + dinero(d.total, d.moneda_simbolo, d.moneda_codigo) + '</strong><small>' + escapeHtml(d.motivo) + '</small></article>' +
                '<article><span>' + (tipo === 'VENTA' ? 'Compensado CxC' : 'Compensado CxP') + '</span><strong>' + dinero(comp, d.moneda_simbolo, d.moneda_codigo) + '</strong><small>Saldo financiero reducido</small></article>' +
                '<article><span>' + (tipo === 'VENTA' ? 'Reembolso' : 'Reintegro') + '</span><strong>' + dinero(contra, d.moneda_simbolo, d.moneda_codigo) + '</strong><small>' + badge(d.regularizacion_estado) + '</small></article>';
            $('detalleProductos').innerHTML = (r.detalles || []).map((l) => '<tr><td><strong>' + escapeHtml(l.producto) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku || '') + '</small></td><td>' + escapeHtml(tipo === 'COMPRA' ? (l.recepcion_folio || 'Recepción histórica') : ('Renglón ' + l.renglon)) + '</td><td>' + escapeHtml(l.almacen_codigo + ' · ' + l.almacen) + '</td><td>' + numero(l.cantidad, 6) + ' ' + escapeHtml(l.unidad_simbolo || l.unidad) + '</td><td>' + numero(l.cantidad_base, 6) + '</td><td>' + dinero(l.importe, d.moneda_simbolo, d.moneda_codigo) + '</td></tr>').join('') || '<tr><td colspan="6" class="empty-cell">Sin detalle.</td></tr>';
            const regs = r.regularizaciones || [];
            $('detalleRegularizaciones').innerHTML = regs.length ? regs.map((x) => '<article><div><strong>' + escapeHtml(x.folio) + '</strong><span>' + escapeHtml(x.tipo === 'REEMBOLSO_CLIENTE' ? 'Reembolso a cliente' : 'Reintegro de proveedor') + '</span></div><div><strong>' + dinero(x.importe, d.moneda_simbolo, d.moneda_codigo) + '</strong>' + badge(x.estado) + '<small>' + escapeHtml(x.metodo_nombre || 'Sin método') + (x.referencia ? ' · Ref. ' + escapeHtml(x.referencia) : '') + '</small></div></article>').join('') : '<div class="empty-state">Esta devolución no generó regularización financiera adicional.</div>';
        } catch (e) {
            mostrarMensaje('mensajeDetalle', e.message, 'error');
            $('detalleProductos').innerHTML = '<tr><td colspan="6" class="empty-cell">No fue posible cargar el detalle.</td></tr>';
        }
    }

    function abrirLiquidar(id) {
        if (!puedeRegularizar) return;
        const r = estado.regularizaciones.items.find((x) => Number(x.id) === Number(id));
        if (!r || r.estado !== 'PENDIENTE') return;
        $('regularizacionId').value = Number(r.id);
        $('tituloLiquidar').textContent = r.folio;
        $('subtituloLiquidar').innerHTML = (r.tipo === 'REEMBOLSO_CLIENTE' ? 'Reembolso a cliente · ' : 'Reintegro de proveedor · ') + dinero(r.importe, r.moneda_simbolo, r.moneda_codigo);
        $('metodoLiquidar').value = '';
        $('referenciaLiquidar').value = '';
        $('observacionesLiquidar').value = '';
        mostrarMensaje('mensajeLiquidar', '');
        abrirModal('modalLiquidar');
    }

    async function liquidar() {
        if (estado.guardando) return;
        if (!$('metodoLiquidar').value) return mostrarMensaje('mensajeLiquidar', 'Selecciona un método.', 'error');
        estado.guardando = true;
        setBusy($('btnConfirmarLiquidacion'), true, 'Liquidando...');
        try {
            const r = await apiPost('LIQUIDAR_REGULARIZACION', {
                regularizacion_id: $('regularizacionId').value,
                metodo_pago_id: $('metodoLiquidar').value,
                referencia: $('referenciaLiquidar').value.trim(),
                observaciones: $('observacionesLiquidar').value.trim()
            });
            cerrarModal('modalLiquidar');
            mostrarMensaje('mensajePagina', r.mensaje, 'success');
            await cargarRegularizaciones();
        } catch (e) {
            mostrarMensaje('mensajeLiquidar', e.message, 'error');
        } finally {
            estado.guardando = false;
            setBusy($('btnConfirmarLiquidacion'), false);
        }
    }

    function programarBusqueda(inputId, timerKey, callback) {
        $(inputId).addEventListener('input', () => {
            clearTimeout(estado[timerKey]);
            estado[timerKey] = setTimeout(callback, 280);
        });
    }

    document.querySelectorAll('[data-cerrar-modal]').forEach((b) => b.addEventListener('click', () => cerrarModal(b.dataset.cerrarModal)));
    document.querySelectorAll('.modal-backdrop').forEach((m) => m.addEventListener('mousedown', (e) => { if (e.target === m) cerrarModal(m.id); }));
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const abiertos = Array.from(document.querySelectorAll('.modal-backdrop:not([hidden])'));
        const ultimo = abiertos[abiertos.length - 1];
        if (ultimo) cerrarModal(ultimo.id);
    });

    document.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => cambiarTab(b.dataset.tab)));
    if ($('btnNuevaVenta')) $('btnNuevaVenta').addEventListener('click', abrirVenta);
    if ($('btnNuevaCompra')) $('btnNuevaCompra').addEventListener('click', abrirCompra);
    $('btnCambiarVenta').addEventListener('click', resetVenta);
    $('btnCambiarCompra').addEventListener('click', resetCompra);
    $('btnGuardarVenta').addEventListener('click', guardarVenta);
    $('btnGuardarCompra').addEventListener('click', guardarCompra);

    programarBusqueda('buscarVentaOrigen', 'timerVenta', buscarVentas);
    programarBusqueda('buscarCompraOrigen', 'timerCompra', buscarCompras);

    $('resultadosVentas').addEventListener('click', (e) => { const b = e.target.closest('[data-venta]'); if (b) seleccionarVenta(Number(b.dataset.venta)); });
    $('resultadosCompras').addEventListener('click', (e) => { const b = e.target.closest('[data-compra]'); if (b) seleccionarCompra(Number(b.dataset.compra)); });
    $('lineasVenta').addEventListener('input', (e) => { if (e.target.matches('.qty-venta')) actualizarEstimadoVenta(); });
    $('lineasCompra').addEventListener('input', (e) => { if (e.target.matches('.qty-compra')) actualizarEstimadoCompra(); });

    if ($('resolverVenta')) $('resolverVenta').addEventListener('change', actualizarLiquidacionVenta);
    if ($('resolverCompra')) $('resolverCompra').addEventListener('change', () => { $('camposResolverCompra').hidden = !$('resolverCompra').checked; });

    $('tablaDevoluciones').addEventListener('click', (e) => { const b = e.target.closest('[data-detalle]'); if (b) abrirDetalle(b.dataset.tipo, Number(b.dataset.detalle)); });
    if ($('tablaRegularizaciones')) $('tablaRegularizaciones').addEventListener('click', (e) => { const b = e.target.closest('[data-liquidar]'); if (b) abrirLiquidar(Number(b.dataset.liquidar)); });
    if ($('btnConfirmarLiquidacion')) $('btnConfirmarLiquidacion').addEventListener('click', liquidar);

    $('buscarDevolucion').addEventListener('input', () => {
        clearTimeout(estado.devoluciones.timer);
        estado.devoluciones.timer = setTimeout(() => { estado.devoluciones.pagina = 1; cargarDevoluciones(); }, 280);
    });
    $('porPaginaDevoluciones').addEventListener('change', () => { estado.devoluciones.pagina = 1; cargarDevoluciones(); });

    if ($('buscarRegularizacion')) $('buscarRegularizacion').addEventListener('input', () => {
        clearTimeout(estado.regularizaciones.timer);
        estado.regularizaciones.timer = setTimeout(() => { estado.regularizaciones.pagina = 1; cargarRegularizaciones(); }, 280);
    });
    if ($('estadoRegularizacion')) $('estadoRegularizacion').addEventListener('change', () => { estado.regularizaciones.pagina = 1; cargarRegularizaciones(); });
    if ($('porPaginaRegularizaciones')) $('porPaginaRegularizaciones').addEventListener('change', () => { estado.regularizaciones.pagina = 1; cargarRegularizaciones(); });

    (async function iniciar() {
        try {
            await cargarCatalogos();
            if (puedeVenta) {
                estado.tab = 'VENTA';
                await cargarDevoluciones();
            } else if (puedeCompra) {
                cambiarTab('COMPRA');
            } else if (puedeRegularizar) {
                cambiarTab('REGULARIZACIONES');
            } else {
                throw new Error('No tienes permisos operativos de devoluciones asignados.');
            }
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
            $('tablaDevoluciones').innerHTML = '<tr><td colspan="8" class="empty-cell empty-cell--error">No fue posible iniciar el módulo.</td></tr>';
        }
    })();
})();
</script>
</body>
</html>
