<?php

declare(strict_types=1);

if (isset($_GET['compras_api'])) {
    require __DIR__ . '/../funciones/compras_funciones.php';
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('compras.ver', false);

$tituloPagina = 'Compras';
$csrfToken = si_token_csrf();
$puedeCrear = si_tiene_permiso('compras.crear');
$puedeCancelarCompra = si_tiene_permiso('compras.cancelar');
$puedeCancelarRecepcion = si_tiene_permiso('recepciones.cancelar');
$puedeVerRecepciones = si_tiene_permiso('recepciones.ver');
$puedeRecepcionar = si_tiene_permiso('recepciones.confirmar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_compras.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'compras')));
if (!in_array($seccionInicial, ['compras', 'recepciones', 'historial'], true)) {
    $seccionInicial = 'compras';
}
if ($seccionInicial === 'recepciones' && !$puedeVerRecepciones) {
    $seccionInicial = 'compras';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Compras | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_compras.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content compras-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">COMPRAS Y RECEPCIÓN</p>
                    <h1>Compras de materia prima</h1>
                    <p>La compra registra el compromiso comercial. El inventario cambia únicamente cuando la recepción física se confirma.</p>
                </div>
            </header>

            <nav class="module-tabs" aria-label="Compras">
                <button type="button" class="module-tab" data-seccion="compras">Compras</button>
                <?php if ($puedeVerRecepciones): ?>
                    <button type="button" class="module-tab" data-seccion="recepciones">Recepciones</button>
                <?php endif; ?>
                <button type="button" class="module-tab" data-seccion="historial">Historial</button>
            </nav>

            <div id="mensajePagina" class="module-message" hidden></div>

            <!-- COMPRAS -->
            <section id="seccionCompras" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Compras</h2>
                        <p>Folios automáticos, datos del proveedor inteligentes y detalle con precios históricos.</p>
                    </div>

                    <?php if ($puedeCrear): ?>
                        <button type="button" class="btn-primary" id="btnNuevaCompra">Nueva compra</button>
                    <?php endif; ?>
                </div>

                <section class="stats-grid stats-grid--5">
                    <article><span>Total</span><strong id="kpiTotalCompras">0</strong></article>
                    <article><span>Borradores</span><strong id="kpiBorradores">0</strong></article>
                    <article><span>Por recibir</span><strong id="kpiPendientes">0</strong></article>
                    <article><span>Parciales</span><strong id="kpiParciales">0</strong></article>
                    <article><span>Recibidas</span><strong id="kpiRecibidas">0</strong></article>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--purchases">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarCompra" maxlength="180" placeholder="Folio, proveedor o factura" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoCompra">
                                <option value="TODOS">Todos</option>
                                <option value="BORRADOR">Borrador</option>
                                <option value="PENDIENTE_RECEPCION">Pendiente recepción</option>
                                <option value="RECIBIDA_PARCIAL">Recibida parcial</option>
                                <option value="RECIBIDA">Recibida</option>
                                <option value="CANCELADA">Cancelada</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Pago</span>
                            <select id="filtroCondicionCompra">
                                <option value="TODAS">Todas</option>
                                <option value="CONTADO">Contado</option>
                                <option value="CREDITO">Crédito</option>
                            </select>
                        </label>

                        <label class="field"><span>Desde</span><input type="date" id="filtroDesdeCompra"></label>
                        <label class="field"><span>Hasta</span><input type="date" id="filtroHastaCompra"></label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaCompra">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--purchases">
                            <thead>
                                <tr>
                                    <th>Folio / fecha</th>
                                    <th>Proveedor</th>
                                    <th>Documento</th>
                                    <th>Condición</th>
                                    <th>Total</th>
                                    <th>Recepción</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaCompras"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaCompra">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnCompraAnterior">Anterior</button>
                            <span id="paginaCompraActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnCompraSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- RECEPCIONES -->
            <section id="seccionRecepciones" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Recepciones</h2>
                        <p>Solo una recepción confirmada incrementa existencia física y genera Kardex.</p>
                    </div>

                    <?php if ($puedeRecepcionar): ?>
                        <button type="button" class="btn-primary" id="btnNuevaRecepcion">Nueva recepción</button>
                    <?php endif; ?>
                </div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--receipts">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarRecepcion" maxlength="180" placeholder="Recepción, compra, proveedor o documento">
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoRecepcion">
                                <option value="TODOS">Todos</option>
                                <option value="BORRADOR">Borrador</option>
                                <option value="CONFIRMADA">Confirmada</option>
                                <option value="CANCELADA">Cancelada</option>
                            </select>
                        </label>

                        <label class="field"><span>Desde</span><input type="date" id="filtroDesdeRecepcion"></label>
                        <label class="field"><span>Hasta</span><input type="date" id="filtroHastaRecepcion"></label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaRecepcion">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table">
                            <thead>
                                <tr>
                                    <th>Recepción</th>
                                    <th>Compra</th>
                                    <th>Proveedor</th>
                                    <th>Documento</th>
                                    <th>Renglones</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaRecepciones"><tr><td colspan="7" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaRecepcion">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnRecepcionAnterior">Anterior</button>
                            <span id="paginaRecepcionActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnRecepcionSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- HISTORIAL -->
            <section id="seccionHistorial" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Historial del módulo</h2>
                        <p>Confirmaciones, cancelaciones y cambios importantes realizados por los usuarios.</p>
                    </div>
                </div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--history">
                        <label class="field field--search"><span>Buscar</span><input type="search" id="buscarHistorial" maxlength="180" placeholder="Acción, descripción o usuario"></label>
                        <label class="field"><span>Desde</span><input type="date" id="filtroDesdeHistorial"></label>
                        <label class="field"><span>Hasta</span><input type="date" id="filtroHastaHistorial"></label>
                        <label class="field"><span>Por página</span><select id="porPaginaHistorial"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table">
                            <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Registro</th><th>Descripción</th></tr></thead>
                            <tbody id="tablaHistorial"><tr><td colspan="5" class="empty-cell">Cargando...</td></tr></tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaHistorial">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnHistorialAnterior">Anterior</button>
                            <span id="paginaHistorialActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnHistorialSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>
        </main>
    </div>
</div>

<!-- MODAL COMPRA -->
<div class="modal-backdrop" id="modalCompra" hidden>
    <section class="modal-card modal-card--purchase" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div><small>COMPRA</small><h2 id="tituloModalCompra">Nueva compra</h2></div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCompra">×</button>
        </header>

        <form id="formCompra">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_COMPRA">
            <input type="hidden" name="compra_id" id="compraId">
            <input type="hidden" name="proveedor_id" id="compraProveedorId">
            <input type="hidden" name="detalles" id="compraDetallesJson">

            <div id="mensajeCompra" class="module-message" hidden></div>

            <div class="purchase-head-grid">
                <label class="field">
                    <span>Folio</span>
                    <input type="text" id="compraFolio" value="Se genera al guardar" readonly>
                </label>

                <label class="field">
                    <span>Fecha de compra *</span>
                    <input type="datetime-local" name="fecha_compra" id="compraFecha" required>
                </label>

                <label class="field field--span-2">
                    <span>Proveedor *</span>
                    <div class="smart-search">
                        <input type="search" id="buscarProveedorCompra" maxlength="180" placeholder="Escribe código, razón social o RFC" autocomplete="off">
                        <div id="resultadosProveedorCompra" class="smart-results" hidden></div>
                    </div>
                    <div id="proveedorCompraSeleccionado" class="selected-summary" hidden></div>
                </label>

                <label class="field">
                    <span>Factura / documento del proveedor <em>(opcional)</em></span>
                    <input
                        type="text"
                        name="numero_factura"
                        id="compraNumeroFactura"
                        maxlength="80"
                        placeholder="Ej. F-18342, FAC-9081 o REM-5501"
                    >
                    <small>
                        Este número lo entrega el proveedor. El sistema no lo inventa ni lo genera.
                        Si todavía no tienes la factura o remisión, déjalo vacío.
                    </small>
                </label>

                <label class="field">
                    <span>Fecha del documento <em>(opcional)</em></span>
                    <input type="date" name="fecha_factura" id="compraFechaFactura">
                    <small>Captúrala únicamente cuando exista la factura o documento del proveedor.</small>
                </label>

                <label class="field">
                    <span>Moneda *</span>
                    <select name="moneda_id" id="compraMoneda" required></select>
                </label>

                <label class="field">
                    <span>Tipo de cambio a moneda base *</span>
                    <input type="number" name="tipo_cambio_a_base" id="compraTipoCambio" min="0.00000001" step="0.00000001" required>
                    <small id="ayudaTipoCambio">Se completa automáticamente cuando existe un tipo de cambio registrado.</small>
                </label>

                <label class="field">
                    <span>Condición de pago *</span>
                    <select name="condicion_pago" id="compraCondicion" required>
                        <option value="CONTADO">Contado</option>
                        <option value="CREDITO">Crédito</option>
                    </select>
                </label>

                <label class="field" id="grupoDiasCredito">
                    <span>Días de crédito</span>
                    <input type="number" name="dias_credito" id="compraDiasCredito" min="1" max="3650" step="1">
                </label>

                <label class="field" id="grupoVencimiento">
                    <span>Vencimiento</span>
                    <input type="date" name="fecha_vencimiento" id="compraVencimiento">
                </label>

                <label class="field field--span-2">
                    <span>Observaciones</span>
                    <textarea name="observaciones" id="compraObservaciones" rows="2" maxlength="10000"></textarea>
                </label>
            </div>

            <div class="si-tc-panel" data-si-tipo-cambio data-endpoint="../funciones/alertas_funciones.php" data-csrf="<?= si_escapar($csrfToken) ?>">
                <div class="si-tc-panel__text">
                    <span>FIX actual USD/MXN</span>
                    <strong data-si-tc-resumen>Consultando FIX...</strong>
                    <small data-si-tc-detalle>Banco de México SIE</small>
                </div>
                <button type="button" class="btn-secondary" data-si-tc-actualizar>Actualizar dólar</button>
            </div>

            <section class="purchase-items">
                <div class="subsection-heading">
                    <div><h3>Productos</h3><p>Solo aparecen materias primas configuradas previamente para el proveedor.</p></div>
                </div>

                <label class="field">
                    <span>Agregar producto</span>
                    <div class="smart-search">
                        <input type="search" id="buscarProductoCompra" maxlength="180" placeholder="Primero selecciona un proveedor" autocomplete="off" disabled>
                        <div id="resultadosProductoCompra" class="smart-results smart-results--wide" hidden></div>
                    </div>
                </label>

                <div class="table-wrap purchase-lines-wrap">
                    <table class="module-table purchase-lines-table">
                        <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio unit.</th><th>Desc. %</th><th>IVA</th><th>Total</th><th></th></tr></thead>
                        <tbody id="tablaLineasCompra"><tr><td colspan="7" class="empty-cell">Agrega al menos un producto.</td></tr></tbody>
                    </table>
                </div>

                <div class="totals-box">
                    <div><span>Subtotal</span><strong id="totalSubtotal">$0.00</strong></div>
                    <div><span>Descuento</span><strong id="totalDescuento">$0.00</strong></div>
                    <div><span>Impuestos</span><strong id="totalImpuesto">$0.00</strong></div>
                    <div class="totals-box__grand"><span>Total</span><strong id="totalCompra">$0.00</strong></div>
                </div>
            </section>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalCompra">Cerrar</button>
                <div class="modal-footer__actions">
                    <button type="submit" class="btn-secondary" data-modo-guardar="borrador">Guardar borrador</button>
                    <?php if ($puedeCrear): ?>
                        <button type="submit" class="btn-primary" data-modo-guardar="confirmar">Guardar y confirmar</button>
                    <?php endif; ?>
                </div>
            </footer>
        </form>
    </section>
</div>

<!-- MODAL VER COMPRA -->
<div class="modal-backdrop" id="modalVerCompra" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header"><div><small>DETALLE</small><h2 id="tituloVerCompra">Compra</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalVerCompra">×</button></header>
        <div class="modal-body" id="contenidoVerCompra"></div>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalVerCompra">Cerrar</button></footer>
    </section>
</div>

<!-- MODAL SELECCIONAR COMPRA PENDIENTE -->
<div class="modal-backdrop" id="modalSeleccionCompra" hidden>
    <section class="modal-card" role="dialog" aria-modal="true">
        <header class="modal-header"><div><small>RECEPCIÓN</small><h2>Seleccionar compra pendiente</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalSeleccionCompra">×</button></header>
        <div class="modal-body">
            <label class="field"><span>Buscar compra</span><div class="smart-search"><input type="search" id="buscarCompraPendiente" maxlength="180" placeholder="Folio, proveedor o factura" autocomplete="off"><div id="resultadosCompraPendiente" class="smart-results smart-results--static"></div></div></label>
        </div>
        <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalSeleccionCompra">Cerrar</button></footer>
    </section>
</div>

<!-- MODAL RECEPCION -->
<div class="modal-backdrop" id="modalRecepcion" hidden>
    <section class="modal-card modal-card--receipt" role="dialog" aria-modal="true">
        <header class="modal-header"><div><small>RECEPCIÓN FÍSICA</small><h2 id="tituloModalRecepcion">Nueva recepción</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalRecepcion">×</button></header>

        <form id="formRecepcion">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_RECEPCION">
            <input type="hidden" name="recepcion_id" id="recepcionId">
            <input type="hidden" name="compra_id" id="recepcionCompraId">
            <input type="hidden" name="detalles" id="recepcionDetallesJson">

            <div id="mensajeRecepcion" class="module-message" hidden></div>
            <div id="resumenCompraRecepcion" class="selected-summary selected-summary--strong"></div>

            <div class="form-grid">
                <label class="field">
                    <span>Fecha de recepción *</span>
                    <input type="datetime-local" name="fecha_recepcion" id="recepcionFecha" required>
                </label>

                <label class="field">
                    <span>Referencia de recepción / acuse <em>(opcional)</em></span>
                    <input
                        type="text"
                        name="documento_recepcion"
                        id="recepcionDocumento"
                        maxlength="100"
                        placeholder="Ej. báscula, entrada, acuse o remisión física"
                    >
                    <small>Identifica esta recepción física; no es el folio interno REC- del sistema.</small>
                </label>

                <label class="field">
                    <span>Factura / documento del proveedor <em>(opcional)</em></span>
                    <input
                        type="text"
                        name="numero_factura_compra"
                        id="recepcionNumeroFactura"
                        maxlength="80"
                        placeholder="Captúralo si llegó junto con la mercancía"
                    >
                    <small>Si ya se capturó al crear la compra, aparecerá automáticamente.</small>
                </label>

                <label class="field">
                    <span>Fecha de factura / documento <em>(opcional)</em></span>
                    <input type="date" name="fecha_factura_compra" id="recepcionFechaFactura">
                </label>

                <label class="field field--span-2">
                    <span>Observaciones</span>
                    <textarea name="observaciones" id="recepcionObservaciones" rows="2" maxlength="10000"></textarea>
                </label>
            </div>

            <div class="table-wrap receipt-lines-wrap">
                <table class="module-table receipt-lines-table">
                    <thead><tr><th>Producto</th><th>Comprado</th><th>Ya recibido</th><th>Pendiente</th><th>Recibir ahora</th><th>Almacén</th><th>Observación</th></tr></thead>
                    <tbody id="tablaLineasRecepcion"><tr><td colspan="7" class="empty-cell">Cargando...</td></tr></tbody>
                </table>
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalRecepcion">Cerrar</button>
                <div class="modal-footer__actions">
                    <button type="submit" class="btn-secondary" data-modo-recepcion="borrador">Guardar borrador</button>
                    <?php if ($puedeRecepcionar): ?>
                        <button type="submit" class="btn-primary" data-modo-recepcion="confirmar">Guardar y confirmar recepción</button>
                    <?php endif; ?>
                </div>
            </footer>
        </form>
    </section>
</div>

<script src="../inc/tipo_cambio_ui.js?v=20260902-09"></script>

<script>
(function () {
    'use strict';

    const puedeCrear = <?= $puedeCrear ? 'true' : 'false' ?>;
    const puedeCancelarCompra = <?= $puedeCancelarCompra ? 'true' : 'false' ?>;
    const puedeCancelarRecepcion = <?= $puedeCancelarRecepcion ? 'true' : 'false' ?>;
    const puedeRecepcionar = <?= $puedeRecepcionar ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const seccionInicial = <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const $ = id => document.getElementById(id);

    const estado = {
        seccion: seccionInicial,
        monedas: [],
        almacenes: [],
        monedaBase: null,

        paginaCompra: 1,
        porPaginaCompra: 20,
        totalPaginasCompra: 1,
        compras: [],

        paginaRecepcion: 1,
        porPaginaRecepcion: 20,
        totalPaginasRecepcion: 1,
        recepciones: [],

        paginaHistorial: 1,
        porPaginaHistorial: 20,
        totalPaginasHistorial: 1,

        proveedorSeleccionado: null,
        lineasCompra: [],
        lineasRecepcion: [],
        compraRecepcion: null,

        timerCompra: null,
        timerRecepcion: null,
        timerHistorial: null,
        timerProveedor: null,
        timerProducto: null,
        timerCompraPendiente: null,

        modoGuardarCompra: 'borrador',
        modoGuardarRecepcion: 'borrador'
    };

    function escapeHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function numero(v, dec = 2) {
        const n = Number(v || 0);
        return new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: dec,
            maximumFractionDigits: dec
        }).format(Number.isFinite(n) ? n : 0);
    }

    function dinero(v, moneda) {
        const codigo = moneda || monedaActualCodigo() || 'MXN';
        try {
            return new Intl.NumberFormat('es-MX', {
                style: 'currency',
                currency: codigo,
                maximumFractionDigits: 2
            }).format(Number(v || 0));
        } catch (e) {
            return numero(v, 2) + ' ' + codigo;
        }
    }

    function fechaCorta(v) {
        if (!v) return '—';
        const raw = String(v).replace(' ', 'T');
        const d = new Date(raw);
        if (Number.isNaN(d.getTime())) return escapeHtml(v);
        return new Intl.DateTimeFormat('es-MX', {dateStyle: 'medium'}).format(d);
    }

    function fechaHora(v) {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return escapeHtml(v);
        return new Intl.DateTimeFormat('es-MX', {dateStyle: 'short', timeStyle: 'short'}).format(d);
    }

    function ahoraLocalInput() {
        const d = new Date();
        const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    }

    function hoy() {
        const d = new Date();
        const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 10);
    }

    function status(texto, tipo) {
        return '<span class="status-badge status-badge--' + escapeHtml(tipo) + '">' + escapeHtml(texto) + '</span>';
    }

    function etiquetaEstadoCompra(estadoCompra) {
        const map = {
            BORRADOR: ['Borrador', 'draft'],
            PENDIENTE_RECEPCION: ['Pendiente recepción', 'warning'],
            RECIBIDA_PARCIAL: ['Recibida parcial', 'partial'],
            RECIBIDA: ['Recibida', 'active'],
            CANCELADA: ['Cancelada', 'inactive']
        };
        const m = map[estadoCompra] || [estadoCompra, 'inactive'];
        return status(m[0], m[1]);
    }

    function etiquetaEstadoRecepcion(v) {
        const map = {
            BORRADOR: ['Borrador', 'draft'],
            CONFIRMADA: ['Confirmada', 'active'],
            CANCELADA: ['Cancelada', 'inactive']
        };
        const m = map[v] || [v, 'inactive'];
        return status(m[0], m[1]);
    }

    function mostrarMensaje(el, texto, tipo = 'error') {
        el.textContent = texto;
        el.className = 'module-message module-message--' + tipo;
        el.hidden = false;
    }

    function ocultarMensaje(el) {
        el.textContent = '';
        el.hidden = true;
    }

    async function api(url, opciones) {
        const respuesta = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }, opciones || {}));

        const texto = await respuesta.text();
        let datos;

        try {
            datos = JSON.parse(texto);
        } catch (e) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (datos.sesion_expirada && datos.redirect) {
            window.location.href = datos.redirect;
            return null;
        }

        if (!respuesta.ok || datos.success !== true) {
            const err = new Error(datos.mensaje || 'No fue posible completar la operación.');
            err.data = datos;
            throw err;
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

    function cambiarSeccion(seccion) {
        estado.seccion = seccion;
        document.querySelectorAll('.module-section').forEach(s => s.hidden = true);
        document.querySelectorAll('.module-tab').forEach(tab => {
            tab.classList.toggle('is-active', tab.dataset.seccion === seccion);
        });

        const id = seccion === 'compras'
            ? 'seccionCompras'
            : seccion === 'recepciones'
                ? 'seccionRecepciones'
                : 'seccionHistorial';
        $(id).hidden = false;

        const url = new URL(window.location.href);
        url.searchParams.set('seccion', seccion);
        history.replaceState(null, '', url);

        cargarSeccion().catch(mostrarErrorGlobal);
    }

    async function cargarCatalogos() {
        const d = await api('?compras_api=1&accion=CATALOGOS');
        estado.monedas = d.monedas || [];
        estado.almacenes = d.almacenes || [];
        estado.monedaBase = d.moneda_base || null;

        $('compraMoneda').innerHTML = estado.monedas.map(m =>
            '<option value="' + m.id + '">' + escapeHtml(m.codigo + ' · ' + m.nombre) + '</option>'
        ).join('');
    }

    async function cargarSeccion() {
        if (estado.seccion === 'compras') return cargarCompras();
        if (estado.seccion === 'recepciones') return cargarRecepciones();
        return cargarHistorial();
    }

    /* ======================== COMPRAS ======================== */

    async function cargarCompras() {
        const params = new URLSearchParams({
            compras_api: '1', accion: 'LISTAR_COMPRAS',
            pagina: String(estado.paginaCompra),
            por_pagina: String(estado.porPaginaCompra),
            busqueda: $('buscarCompra').value.trim(),
            estado: $('filtroEstadoCompra').value,
            condicion_pago: $('filtroCondicionCompra').value,
            desde: $('filtroDesdeCompra').value,
            hasta: $('filtroHastaCompra').value
        });

        $('tablaCompras').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        const d = await api('?' + params.toString());
        estado.compras = d.compras || [];
        estado.totalPaginasCompra = d.paginacion.total_paginas || 1;
        renderCompras(estado.compras);
        renderPaginacion('Compra', d.paginacion);

        const r = d.resumen || {};
        $('kpiTotalCompras').textContent = r.total || 0;
        $('kpiBorradores').textContent = r.borradores || 0;
        $('kpiPendientes').textContent = r.pendientes || 0;
        $('kpiParciales').textContent = r.parciales || 0;
        $('kpiRecibidas').textContent = r.recibidas || 0;
    }

    function renderCompras(filas) {
        if (!filas.length) {
            $('tablaCompras').innerHTML = '<tr><td colspan="8" class="empty-cell">No hay compras con esos filtros.</td></tr>';
            return;
        }

        $('tablaCompras').innerHTML = filas.map(c => {
            const porcentaje = c.cantidad_base_comprada > 0
                ? Math.min(100, (c.cantidad_base_recibida / c.cantidad_base_comprada) * 100)
                : 0;

            let acciones = '<button type="button" class="table-action" data-action="ver-compra" data-id="' + c.id + '">Ver</button>';

            if (puedeCrear && c.estado === 'BORRADOR') {
                acciones += '<button type="button" class="table-action" data-action="editar-compra" data-id="' + c.id + '">Editar</button>';
                acciones += '<button type="button" class="table-action table-action--success" data-action="confirmar-compra" data-id="' + c.id + '">Confirmar</button>';
            }

            if (puedeRecepcionar && ['PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL'].includes(c.estado)) {
                acciones += '<button type="button" class="table-action table-action--success" data-action="recibir-compra" data-id="' + c.id + '">Recibir</button>';
            }

            if (puedeCancelarCompra && !['CANCELADA', 'RECIBIDA'].includes(c.estado)) {
                acciones += '<button type="button" class="table-action table-action--danger" data-action="cancelar-compra" data-id="' + c.id + '">Cancelar</button>';
            }

            return '<tr>'
                + '<td><strong>' + escapeHtml(c.folio) + '</strong><small class="cell-secondary">' + fechaHora(c.fecha_compra) + '</small></td>'
                + '<td><strong>' + escapeHtml(c.proveedor) + '</strong><small class="cell-secondary">' + escapeHtml(c.proveedor_codigo) + '</small></td>'
                + '<td>' + escapeHtml(c.numero_factura || 'Sin factura') + '</td>'
                + '<td>' + escapeHtml(c.condicion_pago === 'CREDITO' ? 'Crédito' : 'Contado')
                + (c.fecha_vencimiento ? '<small class="cell-secondary">Vence ' + fechaCorta(c.fecha_vencimiento) + '</small>' : '') + '</td>'
                + '<td><strong>' + dinero(c.total, c.moneda) + '</strong></td>'
                + '<td><div class="progress-line"><span style="width:' + porcentaje.toFixed(2) + '%"></span></div><small>' + numero(porcentaje, 0) + '% · ' + c.recepciones_confirmadas + ' recepción(es)</small></td>'
                + '<td>' + etiquetaEstadoCompra(c.estado) + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    function nuevaCompra() {
        $('formCompra').reset();
        $('compraId').value = '';
        $('compraFolio').value = 'Se genera al guardar';
        $('compraFecha').value = ahoraLocalInput();
        $('compraProveedorId').value = '';
        $('buscarProveedorCompra').value = '';
        $('proveedorCompraSeleccionado').hidden = true;
        estado.proveedorSeleccionado = null;
        estado.lineasCompra = [];
        $('buscarProductoCompra').disabled = true;
        $('buscarProductoCompra').placeholder = 'Primero selecciona un proveedor';
        $('compraNumeroFactura').value = '';
        $('compraFechaFactura').value = '';
        $('compraObservaciones').value = '';

        const base = estado.monedaBase || estado.monedas[0];
        if (base) $('compraMoneda').value = String(base.id);
        $('compraTipoCambio').value = '1';
        $('compraCondicion').value = 'CONTADO';
        $('compraDiasCredito').value = '';
        $('compraVencimiento').value = '';
        actualizarCamposCredito();
        renderLineasCompra();
        recalcularTotalesCompra();
        ocultarMensaje($('mensajeCompra'));
        $('tituloModalCompra').textContent = 'Nueva compra';
        abrirModal('modalCompra');
    }

    async function editarCompra(id) {
        const d = await api('?compras_api=1&accion=DETALLE_COMPRA&id=' + encodeURIComponent(id));
        const c = d.compra;

        if (c.estado !== 'BORRADOR') {
            throw new Error('Solo los borradores pueden editarse.');
        }

        $('formCompra').reset();
        $('compraId').value = c.id;
        $('compraFolio').value = c.folio;
        $('compraFecha').value = String(c.fecha_compra).replace(' ', 'T').slice(0, 16);
        $('compraProveedorId').value = c.proveedor_id;
        $('buscarProveedorCompra').value = c.proveedor_actual;
        $('proveedorCompraSeleccionado').innerHTML = '<strong>' + escapeHtml(c.proveedor_actual) + '</strong><span>' + escapeHtml(c.proveedor_codigo + (c.proveedor_rfc_snapshot ? ' · ' + c.proveedor_rfc_snapshot : '')) + '</span>';
        $('proveedorCompraSeleccionado').hidden = false;
        estado.proveedorSeleccionado = {id: c.proveedor_id, razon_social: c.proveedor_actual, codigo: c.proveedor_codigo, moneda_default_id: c.moneda_id, dias_credito: c.dias_credito};
        $('buscarProductoCompra').disabled = false;
        $('buscarProductoCompra').placeholder = 'Código o nombre de materia prima';
        $('compraNumeroFactura').value = c.numero_factura || '';
        $('compraFechaFactura').value = c.fecha_factura || '';
        $('compraMoneda').value = String(c.moneda_id);
        $('compraTipoCambio').value = c.tipo_cambio_a_base;
        $('compraCondicion').value = c.condicion_pago;
        $('compraDiasCredito').value = c.dias_credito || '';
        $('compraVencimiento').value = c.fecha_vencimiento || '';
        $('compraObservaciones').value = c.observaciones || '';

        estado.lineasCompra = (d.detalles || []).map(x => ({
            relacion_id: x.relacion_id,
            producto_id: x.producto_id,
            sku: x.sku_snapshot,
            producto: x.producto_nombre_snapshot,
            presentacion_id: x.presentacion_id,
            presentacion: x.presentacion_nombre,
            unidad_id: x.unidad_id,
            unidad_nombre: x.unidad_nombre_snapshot,
            unidad_simbolo: x.unidad_simbolo,
            factor_a_unidad_base: Number(x.factor_a_unidad_base),
            cantidad: Number(x.cantidad),
            precio_unitario: Number(x.precio_unitario),
            descuento_pct: Number(x.descuento_pct),
            impuesto_pct: Number(x.impuesto_pct_snapshot),
            compra_minima: null,
            ultimo_precio: null,
            ultimo_precio_moneda: null
        }));

        actualizarCamposCredito();
        renderLineasCompra();
        recalcularTotalesCompra();
        ocultarMensaje($('mensajeCompra'));
        $('tituloModalCompra').textContent = 'Editar ' + c.folio;
        abrirModal('modalCompra');
    }

    async function verCompra(id) {
        const d = await api('?compras_api=1&accion=DETALLE_COMPRA&id=' + encodeURIComponent(id));
        const c = d.compra;
        const detalles = d.detalles || [];

        $('tituloVerCompra').textContent = c.folio;
        $('contenidoVerCompra').innerHTML = '<div class="detail-grid">'
            + '<div><span>Proveedor</span><strong>' + escapeHtml(c.proveedor_nombre_snapshot) + '</strong></div>'
            + '<div><span>Fecha</span><strong>' + fechaHora(c.fecha_compra) + '</strong></div>'
            + '<div><span>Documento</span><strong>' + escapeHtml(c.numero_factura || 'Sin factura') + '</strong></div>'
            + '<div><span>Pago</span><strong>' + escapeHtml(c.condicion_pago === 'CREDITO' ? 'Crédito' : 'Contado') + '</strong></div>'
            + '<div><span>Moneda</span><strong>' + escapeHtml(c.moneda_codigo) + '</strong></div>'
            + '<div><span>Estado</span><strong>' + etiquetaEstadoCompra(c.estado) + '</strong></div>'
            + '</div>'
            + '<div class="table-wrap"><table class="module-table"><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Descuento</th><th>IVA</th><th>Total</th><th>Recibido</th></tr></thead><tbody>'
            + detalles.map(x => '<tr><td><strong>' + escapeHtml(x.producto_nombre_snapshot) + '</strong><small class="cell-secondary">' + escapeHtml(x.sku_snapshot + ' · ' + x.presentacion_nombre) + '</small></td><td>' + numero(x.cantidad, 6) + ' ' + escapeHtml(x.unidad_simbolo) + '</td><td>' + dinero(x.precio_unitario, c.moneda_codigo) + '</td><td>' + numero(x.descuento_pct, 2) + '%</td><td>' + numero(x.impuesto_pct_snapshot, 2) + '%</td><td>' + dinero(x.total, c.moneda_codigo) + '</td><td>' + numero(x.cantidad_recibida, 6) + ' / ' + numero(x.cantidad, 6) + '</td></tr>').join('')
            + '</tbody></table></div>'
            + '<div class="totals-box totals-box--view"><div><span>Subtotal</span><strong>' + dinero(c.subtotal, c.moneda_codigo) + '</strong></div><div><span>Descuento</span><strong>' + dinero(c.descuento_total, c.moneda_codigo) + '</strong></div><div><span>Impuestos</span><strong>' + dinero(c.impuesto_total, c.moneda_codigo) + '</strong></div><div class="totals-box__grand"><span>Total</span><strong>' + dinero(c.total, c.moneda_codigo) + '</strong></div></div>'
            + (c.observaciones ? '<div class="note-box"><strong>Observaciones</strong><p>' + escapeHtml(c.observaciones) + '</p></div>' : '');

        abrirModal('modalVerCompra');
    }

    async function confirmarCompra(id) {
        if (!confirm('¿Confirmar esta compra? Después ya no podrá editarse y quedará pendiente de recepción física.')) return;
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', 'CONFIRMAR_COMPRA');
        form.append('compra_id', String(id));
        const d = await api('?compras_api=1', {method: 'POST', body: form});
        mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
        await cargarCompras();
    }

    async function cancelarCompra(id) {
        const motivo = prompt('Motivo de cancelación de la compra:');
        if (motivo == null) return;
        if (!motivo.trim()) {
            mostrarMensaje($('mensajePagina'), 'Debes indicar el motivo de cancelación.', 'error');
            return;
        }
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', 'CANCELAR_COMPRA');
        form.append('compra_id', String(id));
        form.append('motivo', motivo.trim());
        const d = await api('?compras_api=1', {method: 'POST', body: form});
        mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
        await cargarCompras();
    }

    async function buscarProveedores() {
        const q = $('buscarProveedorCompra').value.trim();
        if (!q) {
            $('resultadosProveedorCompra').hidden = true;
            return;
        }
        const d = await api('?compras_api=1&accion=BUSCAR_PROVEEDORES&q=' + encodeURIComponent(q));
        const filas = d.proveedores || [];
        $('resultadosProveedorCompra').innerHTML = filas.length
            ? filas.map(p => '<button type="button" class="smart-result" data-provider-id="' + p.id + '"><strong>' + escapeHtml(p.razon_social) + '</strong><span>' + escapeHtml(p.codigo + (p.rfc ? ' · ' + p.rfc : '') + (p.moneda_codigo ? ' · ' + p.moneda_codigo : '')) + '</span></button>').join('')
            : '<div class="smart-empty">No se encontraron proveedores activos.</div>';
        $('resultadosProveedorCompra').hidden = false;
    }

    async function seleccionarProveedor(id) {
        const d = await api('?compras_api=1&accion=DATOS_PROVEEDOR&proveedor_id=' + encodeURIComponent(id));
        const p = d.proveedor;

        if (estado.proveedorSeleccionado && estado.proveedorSeleccionado.id !== p.id && estado.lineasCompra.length) {
            if (!confirm('Cambiar de proveedor eliminará los productos ya agregados. ¿Continuar?')) return;
            estado.lineasCompra = [];
            renderLineasCompra();
        }

        estado.proveedorSeleccionado = p;
        $('compraProveedorId').value = p.id;
        $('buscarProveedorCompra').value = p.razon_social;
        $('resultadosProveedorCompra').hidden = true;
        $('proveedorCompraSeleccionado').innerHTML = '<strong>' + escapeHtml(p.razon_social) + '</strong><span>' + escapeHtml(p.codigo + (p.rfc ? ' · ' + p.rfc : '') + (p.moneda_codigo ? ' · ' + p.moneda_codigo : '')) + '</span>';
        $('proveedorCompraSeleccionado').hidden = false;
        $('buscarProductoCompra').disabled = false;
        $('buscarProductoCompra').placeholder = 'Código o nombre de materia prima';

        if (p.moneda_default_id) $('compraMoneda').value = String(p.moneda_default_id);
        if (p.dias_credito > 0) {
            $('compraCondicion').value = 'CREDITO';
            $('compraDiasCredito').value = p.dias_credito;
        } else {
            $('compraCondicion').value = 'CONTADO';
            $('compraDiasCredito').value = '';
        }

        actualizarCamposCredito();
        calcularVencimiento();
        await cargarTipoCambio();
    }

    async function buscarProductosProveedor() {
        const proveedorId = Number($('compraProveedorId').value || 0);
        const q = $('buscarProductoCompra').value.trim();
        if (!proveedorId || !q) {
            $('resultadosProductoCompra').hidden = true;
            return;
        }

        const d = await api('?compras_api=1&accion=BUSCAR_PRODUCTOS_PROVEEDOR&proveedor_id=' + proveedorId + '&q=' + encodeURIComponent(q));
        const filas = d.productos || [];
        const monedaId = Number($('compraMoneda').value || 0);

        $('resultadosProductoCompra').innerHTML = filas.length
            ? filas.map(p => {
                const ultimo = p.ultimo_precio == null
                    ? 'Sin precio histórico'
                    : 'Último: ' + numero(p.ultimo_precio, 4) + ' ' + escapeHtml(p.ultimo_precio_moneda || '') + (p.ultimo_precio_fecha ? ' · ' + fechaCorta(p.ultimo_precio_fecha) : '');
                const min = p.compra_minima ? ' · mínimo ' + numero(p.compra_minima, 6) + ' ' + escapeHtml(p.unidad_simbolo) : '';
                return '<button type="button" class="smart-result smart-result--product" data-product-json="' + escapeHtml(JSON.stringify(p)) + '"><strong>' + escapeHtml(p.producto) + '</strong><span>' + escapeHtml(p.sku + ' · ' + p.presentacion + ' · ' + p.unidad_nombre) + min + '</span><small>' + ultimo + '</small></button>';
            }).join('')
            : '<div class="smart-empty">No hay materias primas configuradas para este proveedor con esa búsqueda.</div>';
        $('resultadosProductoCompra').hidden = false;
    }

    function agregarProducto(p) {
        if (estado.lineasCompra.some(x => x.relacion_id === p.relacion_id)) {
            mostrarMensaje($('mensajeCompra'), 'Ese producto/presentación ya está agregado. Modifica su cantidad.', 'error');
            return;
        }

        const monedaActual = Number($('compraMoneda').value || 0);
        const precio = p.ultimo_precio != null && p.ultimo_precio_moneda_id === monedaActual
            ? Number(p.ultimo_precio)
            : 0;

        estado.lineasCompra.push({
            relacion_id: Number(p.relacion_id),
            producto_id: Number(p.producto_id),
            sku: p.sku,
            producto: p.producto,
            presentacion_id: p.presentacion_id == null ? null : Number(p.presentacion_id),
            presentacion: p.presentacion,
            unidad_id: Number(p.unidad_id),
            unidad_nombre: p.unidad_nombre,
            unidad_simbolo: p.unidad_simbolo,
            factor_a_unidad_base: Number(p.factor_a_unidad_base),
            compra_minima: p.compra_minima == null ? null : Number(p.compra_minima),
            cantidad: p.compra_minima && Number(p.compra_minima) > 0 ? Number(p.compra_minima) : 1,
            precio_unitario: precio,
            descuento_pct: 0,
            impuesto_pct: Number(p.impuesto_pct || 0),
            ultimo_precio: p.ultimo_precio,
            ultimo_precio_moneda: p.ultimo_precio_moneda
        });

        $('buscarProductoCompra').value = '';
        $('resultadosProductoCompra').hidden = true;
        renderLineasCompra();
        recalcularTotalesCompra();
    }

    function renderLineasCompra() {
        if (!estado.lineasCompra.length) {
            $('tablaLineasCompra').innerHTML = '<tr><td colspan="7" class="empty-cell">Agrega al menos un producto.</td></tr>';
            return;
        }

        $('tablaLineasCompra').innerHTML = estado.lineasCompra.map((l, i) => {
            const calc = calcularLinea(l);
            const ayudaPrecio = l.precio_unitario > 0
                ? ''
                : (l.ultimo_precio != null ? '<small class="field-hint">Último precio: ' + numero(l.ultimo_precio, 4) + ' ' + escapeHtml(l.ultimo_precio_moneda || '') + '</small>' : '<small class="field-hint">Captura el precio negociado.</small>');
            return '<tr data-line-index="' + i + '">'
                + '<td><strong>' + escapeHtml(l.producto) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku + ' · ' + l.presentacion + ' · ' + l.unidad_simbolo) + '</small></td>'
                + '<td><input class="line-input" type="number" min="0.000001" step="0.000001" data-line-field="cantidad" value="' + escapeHtml(l.cantidad) + '"></td>'
                + '<td><input class="line-input" type="number" min="0.0001" step="0.0001" data-line-field="precio_unitario" value="' + escapeHtml(l.precio_unitario || '') + '">' + ayudaPrecio + '</td>'
                + '<td><input class="line-input" type="number" min="0" max="100" step="0.0001" data-line-field="descuento_pct" value="' + escapeHtml(l.descuento_pct) + '"></td>'
                + '<td>' + numero(l.impuesto_pct, 2) + '%</td>'
                + '<td><strong>' + dinero(calc.total) + '</strong></td>'
                + '<td><button type="button" class="table-action table-action--danger" data-remove-line="' + i + '">Quitar</button></td>'
                + '</tr>';
        }).join('');
    }

    function calcularLinea(l) {
        const cantidad = Number(l.cantidad || 0);
        const precio = Number(l.precio_unitario || 0);
        const desc = Number(l.descuento_pct || 0);
        const iva = Number(l.impuesto_pct || 0);
        const bruto = cantidad * precio;
        const descuento = bruto * desc / 100;
        const subtotal = bruto - descuento;
        const impuesto = subtotal * iva / 100;
        return {bruto, descuento, subtotal, impuesto, total: subtotal + impuesto};
    }

    function recalcularTotalesCompra() {
        let bruto = 0, descuento = 0, impuesto = 0, total = 0;
        estado.lineasCompra.forEach(l => {
            const c = calcularLinea(l);
            bruto += c.bruto;
            descuento += c.descuento;
            impuesto += c.impuesto;
            total += c.total;
        });
        $('totalSubtotal').textContent = dinero(bruto);
        $('totalDescuento').textContent = dinero(descuento);
        $('totalImpuesto').textContent = dinero(impuesto);
        $('totalCompra').textContent = dinero(total);
    }

    function monedaActualCodigo() {
        const id = Number($('compraMoneda').value || 0);
        const m = estado.monedas.find(x => x.id === id);
        return m ? m.codigo : (estado.monedaBase ? estado.monedaBase.codigo : 'MXN');
    }

    function actualizarCamposCredito() {
        const credito = $('compraCondicion').value === 'CREDITO';
        $('grupoDiasCredito').hidden = !credito;
        $('grupoVencimiento').hidden = !credito;
        $('compraDiasCredito').required = credito;
        $('compraVencimiento').required = credito;
        if (!credito) {
            $('compraDiasCredito').value = '';
            $('compraVencimiento').value = '';
        }
    }

    function calcularVencimiento() {
        if ($('compraCondicion').value !== 'CREDITO') return;
        const dias = Number($('compraDiasCredito').value || 0);
        if (!dias) return;
        const base = $('compraFechaFactura').value || $('compraFecha').value.slice(0, 10);
        if (!base) return;
        const d = new Date(base + 'T12:00:00');
        d.setDate(d.getDate() + dias);
        const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
        $('compraVencimiento').value = local.toISOString().slice(0, 10);
    }

    async function cargarTipoCambio() {
        const monedaId = Number($('compraMoneda').value || 0);
        if (!monedaId) return;
        const fecha = $('compraFecha').value ? $('compraFecha').value.slice(0, 10) : hoy();
        const d = await api('?compras_api=1&accion=TIPO_CAMBIO&moneda_id=' + monedaId + '&fecha=' + encodeURIComponent(fecha));
        if (d.encontrado) {
            $('compraTipoCambio').value = Number(d.tipo_cambio).toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
            const baseTexto = d.fecha_tipo_cambio
                ? 'Tipo de cambio ' + d.fecha_tipo_cambio + (d.fuente ? ' · ' + d.fuente : '')
                : 'Moneda base.';
            $('ayudaTipoCambio').textContent = d.desactualizado
                ? '⚠ ' + baseTexto + ' · Está desactualizado; se usa como respaldo local.'
                : baseTexto;
        } else {
            $('compraTipoCambio').value = '';
            $('ayudaTipoCambio').textContent = 'No hay tipo de cambio registrado para esa fecha. Captúralo manualmente.';
        }
        renderLineasCompra();
        recalcularTotalesCompra();
    }

    async function guardarCompra(event) {
        event.preventDefault();
        ocultarMensaje($('mensajeCompra'));

        if (!Number($('compraProveedorId').value || 0)) {
            mostrarMensaje($('mensajeCompra'), 'Selecciona un proveedor.', 'error');
            return;
        }
        if (!estado.lineasCompra.length) {
            mostrarMensaje($('mensajeCompra'), 'Agrega al menos un producto.', 'error');
            return;
        }
        if (estado.lineasCompra.some(l => Number(l.cantidad) <= 0 || Number(l.precio_unitario) <= 0)) {
            mostrarMensaje($('mensajeCompra'), 'Todas las cantidades y precios deben ser mayores que cero.', 'error');
            return;
        }

        $('compraDetallesJson').value = JSON.stringify(estado.lineasCompra.map(l => ({
            relacion_id: l.relacion_id,
            cantidad: l.cantidad,
            precio_unitario: l.precio_unitario,
            descuento_pct: l.descuento_pct
        })));

        const form = new FormData(event.currentTarget);
        const botones = event.currentTarget.querySelectorAll('button[type="submit"]');
        botones.forEach(b => b.disabled = true);

        try {
            const d = await api('?compras_api=1', {method: 'POST', body: form});
            let compraId = d.compra_id;

            /*
             * Si la confirmación posterior fallara, el formulario ya queda
             * apuntando al borrador guardado y un reintento no duplica compras.
             */
            $('compraId').value = String(d.compra_id);
            if (d.folio) $('compraFolio').value = d.folio;

            if (estado.modoGuardarCompra === 'confirmar') {
                const conf = new FormData();
                conf.append('csrf_token', csrfToken);
                conf.append('accion', 'CONFIRMAR_COMPRA');
                conf.append('compra_id', String(compraId));
                const dc = await api('?compras_api=1', {method: 'POST', body: conf});
                mostrarMensaje($('mensajePagina'), dc.mensaje, 'success');
            } else {
                mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
            }

            cerrarModal('modalCompra');
            await cargarCompras();
        } catch (e) {
            mostrarMensaje($('mensajeCompra'), e.message, 'error');
        } finally {
            botones.forEach(b => b.disabled = false);
        }
    }

    /* ======================== RECEPCIONES ======================== */

    async function cargarRecepciones() {
        const params = new URLSearchParams({
            compras_api: '1', accion: 'LISTAR_RECEPCIONES',
            pagina: String(estado.paginaRecepcion),
            por_pagina: String(estado.porPaginaRecepcion),
            busqueda: $('buscarRecepcion').value.trim(),
            estado: $('filtroEstadoRecepcion').value,
            desde: $('filtroDesdeRecepcion').value,
            hasta: $('filtroHastaRecepcion').value
        });
        $('tablaRecepciones').innerHTML = '<tr><td colspan="7" class="empty-cell">Cargando...</td></tr>';
        const d = await api('?' + params.toString());
        estado.recepciones = d.recepciones || [];
        estado.totalPaginasRecepcion = d.paginacion.total_paginas || 1;
        renderRecepciones(estado.recepciones);
        renderPaginacion('Recepcion', d.paginacion);
    }

    function renderRecepciones(filas) {
        if (!filas.length) {
            $('tablaRecepciones').innerHTML = '<tr><td colspan="7" class="empty-cell">No hay recepciones con esos filtros.</td></tr>';
            return;
        }
        $('tablaRecepciones').innerHTML = filas.map(r => {
            let acciones = '<button type="button" class="table-action" data-action="ver-recepcion" data-id="' + r.id + '">Ver</button>';
            if (puedeRecepcionar && r.estado === 'BORRADOR') {
                acciones += '<button type="button" class="table-action" data-action="editar-recepcion" data-id="' + r.id + '">Editar</button>';
                acciones += '<button type="button" class="table-action table-action--success" data-action="confirmar-recepcion" data-id="' + r.id + '">Confirmar</button>';
            }
            if (puedeCancelarRecepcion && r.estado !== 'CANCELADA') {
                acciones += '<button type="button" class="table-action table-action--danger" data-action="cancelar-recepcion" data-id="' + r.id + '">Cancelar</button>';
            }
            return '<tr>'
                + '<td><strong>' + escapeHtml(r.folio) + '</strong><small class="cell-secondary">' + fechaHora(r.fecha_recepcion) + '</small></td>'
                + '<td>' + escapeHtml(r.compra_folio) + '</td>'
                + '<td>' + escapeHtml(r.proveedor) + '</td>'
                + '<td>' + escapeHtml(r.documento_recepcion || '—') + '</td>'
                + '<td>' + r.renglones + '</td>'
                + '<td>' + etiquetaEstadoRecepcion(r.estado) + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    function nuevaRecepcion() {
        $('buscarCompraPendiente').value = '';
        $('resultadosCompraPendiente').innerHTML = '<div class="smart-empty">Escribe un folio o proveedor para buscar.</div>';
        abrirModal('modalSeleccionCompra');
        $('buscarCompraPendiente').focus();
    }

    async function buscarComprasPendientes() {
        const q = $('buscarCompraPendiente').value.trim();
        const d = await api('?compras_api=1&accion=BUSCAR_COMPRAS_PENDIENTES&q=' + encodeURIComponent(q));
        const filas = d.compras || [];
        $('resultadosCompraPendiente').innerHTML = filas.length
            ? filas.map(c => '<button type="button" class="smart-result" data-pending-purchase-id="' + c.id + '"><strong>' + escapeHtml(c.folio + ' · ' + c.proveedor) + '</strong><span>' + escapeHtml(fechaCorta(c.fecha_compra) + (c.numero_factura ? ' · Factura ' + c.numero_factura : '') + ' · ' + c.estado.replaceAll('_', ' ')) + '</span></button>').join('')
            : '<div class="smart-empty">No hay compras pendientes con esa búsqueda.</div>';
    }

    async function abrirRecepcionCompra(compraId) {
        const d = await api('?compras_api=1&accion=PREPARAR_RECEPCION&compra_id=' + encodeURIComponent(compraId));
        prepararModalRecepcionNueva(d);
    }

    function prepararModalRecepcionNueva(d) {
        const c = d.compra;
        estado.compraRecepcion = c;
        estado.almacenes = d.almacenes || estado.almacenes;
        estado.lineasRecepcion = (d.detalles || []).map(x => ({
            compra_detalle_id: x.compra_detalle_id,
            producto_id: x.producto_id,
            producto: x.producto,
            sku: x.sku,
            unidad: x.unidad,
            unidad_simbolo: x.unidad_simbolo,
            cantidad_comprada: Number(x.cantidad_comprada),
            cantidad_recibida_previa: Number(x.cantidad_recibida),
            cantidad_pendiente: Number(x.cantidad_pendiente),
            factor_a_unidad_base: Number(x.factor_a_unidad_base),
            cantidad_recibida: Number(x.cantidad_pendiente),
            almacen_id: estado.almacenes.length ? estado.almacenes[0].id : 0,
            observaciones: ''
        }));

        $('formRecepcion').reset();
        $('recepcionId').value = '';
        $('recepcionCompraId').value = c.id;
        $('recepcionFecha').value = ahoraLocalInput();
        $('recepcionDocumento').value = '';
        $('recepcionNumeroFactura').value = c.numero_factura || '';
        $('recepcionFechaFactura').value = c.fecha_factura || '';
        $('recepcionObservaciones').value = '';
        $('tituloModalRecepcion').textContent = 'Nueva recepción · ' + c.folio;
        $('resumenCompraRecepcion').innerHTML = '<strong>' + escapeHtml(c.folio + ' · ' + c.proveedor) + '</strong><span>' + escapeHtml('Estado: ' + c.estado.replaceAll('_', ' ')) + '</span>';
        renderLineasRecepcion();
        ocultarMensaje($('mensajeRecepcion'));
        cerrarModal('modalSeleccionCompra');
        abrirModal('modalRecepcion');
    }

    async function editarRecepcion(id) {
        const d = await api('?compras_api=1&accion=DETALLE_RECEPCION&id=' + encodeURIComponent(id));
        const r = d.recepcion;
        if (r.estado !== 'BORRADOR') throw new Error('Solo los borradores de recepción pueden editarse.');

        estado.almacenes = d.almacenes || estado.almacenes;
        estado.compraRecepcion = {id: r.compra_id, folio: r.compra_folio, proveedor: r.proveedor, estado: r.compra_estado};
        estado.lineasRecepcion = (d.detalles || []).map(x => ({
            compra_detalle_id: x.compra_detalle_id,
            producto_id: x.producto_id,
            producto: x.producto,
            sku: x.sku,
            unidad: x.unidad,
            unidad_simbolo: x.unidad_simbolo,
            cantidad_comprada: Number(x.cantidad_comprada),
            cantidad_recibida_previa: Number(x.cantidad_recibida_otros || 0),
            cantidad_pendiente: Number(x.cantidad_pendiente_max || x.cantidad_recibida),
            factor_a_unidad_base: Number(x.factor_a_unidad_base),
            cantidad_recibida: Number(x.cantidad_recibida),
            almacen_id: x.almacen_id || (estado.almacenes.length ? estado.almacenes[0].id : 0),
            observaciones: x.observaciones || ''
        }));

        $('formRecepcion').reset();
        $('recepcionId').value = r.id;
        $('recepcionCompraId').value = r.compra_id;
        $('recepcionFecha').value = String(r.fecha_recepcion).replace(' ', 'T').slice(0, 16);
        $('recepcionDocumento').value = r.documento_recepcion || '';
        $('recepcionNumeroFactura').value = r.compra_numero_factura || '';
        $('recepcionFechaFactura').value = r.compra_fecha_factura || '';
        $('recepcionObservaciones').value = r.observaciones || '';
        $('tituloModalRecepcion').textContent = 'Editar ' + r.folio;
        $('resumenCompraRecepcion').innerHTML = '<strong>' + escapeHtml(r.compra_folio + ' · ' + r.proveedor) + '</strong><span>Recepción en borrador</span>';
        renderLineasRecepcion();
        ocultarMensaje($('mensajeRecepcion'));
        abrirModal('modalRecepcion');
    }

    async function verRecepcion(id) {
        const d = await api('?compras_api=1&accion=DETALLE_RECEPCION&id=' + encodeURIComponent(id));
        const r = d.recepcion;
        $('tituloVerCompra').textContent = r.folio;
        $('contenidoVerCompra').innerHTML = '<div class="detail-grid">'
            + '<div><span>Compra</span><strong>' + escapeHtml(r.compra_folio) + '</strong></div>'
            + '<div><span>Proveedor</span><strong>' + escapeHtml(r.proveedor) + '</strong></div>'
            + '<div><span>Fecha</span><strong>' + fechaHora(r.fecha_recepcion) + '</strong></div>'
            + '<div><span>Estado</span><strong>' + etiquetaEstadoRecepcion(r.estado) + '</strong></div>'
            + '<div><span>Factura / documento proveedor</span><strong>' + escapeHtml(r.compra_numero_factura || 'No capturado') + '</strong></div>'
            + '</div>'
            + '<div class="table-wrap"><table class="module-table"><thead><tr><th>Producto</th><th>Cantidad</th><th>Almacén</th><th>Observación</th></tr></thead><tbody>'
            + (d.detalles || []).map(x => '<tr><td><strong>' + escapeHtml(x.producto) + '</strong><small class="cell-secondary">' + escapeHtml(x.sku) + '</small></td><td>' + numero(x.cantidad_recibida, 6) + ' ' + escapeHtml(x.unidad_simbolo) + '</td><td>' + escapeHtml(x.almacen) + '</td><td>' + escapeHtml(x.observaciones || '—') + '</td></tr>').join('')
            + '</tbody></table></div>'
            + (r.observaciones ? '<div class="note-box"><strong>Observaciones</strong><p>' + escapeHtml(r.observaciones) + '</p></div>' : '');
        abrirModal('modalVerCompra');
    }

    function renderLineasRecepcion() {
        if (!estado.lineasRecepcion.length) {
            $('tablaLineasRecepcion').innerHTML = '<tr><td colspan="7" class="empty-cell">No hay cantidades pendientes.</td></tr>';
            return;
        }
        const opcionesAlmacen = estado.almacenes.map(a => '<option value="' + a.id + '">' + escapeHtml(a.nombre) + '</option>').join('');
        $('tablaLineasRecepcion').innerHTML = estado.lineasRecepcion.map((l, i) => '<tr data-receipt-line-index="' + i + '">'
            + '<td><strong>' + escapeHtml(l.producto) + '</strong><small class="cell-secondary">' + escapeHtml(l.sku + ' · ' + l.unidad_simbolo) + '</small></td>'
            + '<td>' + numero(l.cantidad_comprada, 6) + '</td>'
            + '<td>' + numero(l.cantidad_recibida_previa, 6) + '</td>'
            + '<td><strong>' + numero(l.cantidad_pendiente, 6) + '</strong></td>'
            + '<td><input class="line-input" type="number" min="0" max="' + escapeHtml(l.cantidad_pendiente) + '" step="0.000001" data-receipt-field="cantidad_recibida" value="' + escapeHtml(l.cantidad_recibida) + '"></td>'
            + '<td><select class="line-select" data-receipt-field="almacen_id">' + opcionesAlmacen + '</select></td>'
            + '<td><input class="line-input line-input--text" type="text" maxlength="255" data-receipt-field="observaciones" value="' + escapeHtml(l.observaciones) + '"></td>'
            + '</tr>').join('');

        document.querySelectorAll('[data-receipt-line-index]').forEach(tr => {
            const i = Number(tr.dataset.receiptLineIndex);
            const sel = tr.querySelector('[data-receipt-field="almacen_id"]');
            if (sel) sel.value = String(estado.lineasRecepcion[i].almacen_id || '');
        });
    }

    async function guardarRecepcion(event) {
        event.preventDefault();
        ocultarMensaje($('mensajeRecepcion'));

        $('recepcionDetallesJson').value = JSON.stringify(estado.lineasRecepcion.map(l => ({
            compra_detalle_id: l.compra_detalle_id,
            almacen_id: l.almacen_id,
            cantidad_recibida: l.cantidad_recibida,
            observaciones: l.observaciones
        })));

        const botones = event.currentTarget.querySelectorAll('button[type="submit"]');
        botones.forEach(b => b.disabled = true);

        try {
            const d = await api('?compras_api=1', {method: 'POST', body: new FormData(event.currentTarget)});

            /* Mismo criterio idempotente: conservar el ID del borrador guardado. */
            $('recepcionId').value = String(d.recepcion_id);

            if (estado.modoGuardarRecepcion === 'confirmar') {
                const form = new FormData();
                form.append('csrf_token', csrfToken);
                form.append('accion', 'CONFIRMAR_RECEPCION');
                form.append('recepcion_id', String(d.recepcion_id));
                const dc = await api('?compras_api=1', {method: 'POST', body: form});
                mostrarMensaje($('mensajePagina'), dc.mensaje, 'success');
            } else {
                mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
            }
            cerrarModal('modalRecepcion');
            if (estado.seccion === 'compras') await cargarCompras();
            else await cargarRecepciones();
        } catch (e) {
            mostrarMensaje($('mensajeRecepcion'), e.message, 'error');
        } finally {
            botones.forEach(b => b.disabled = false);
        }
    }

    async function confirmarRecepcion(id) {
        if (!confirm('¿Confirmar esta recepción? Esta acción incrementará inventario y generará el movimiento Kardex.')) return;
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', 'CONFIRMAR_RECEPCION');
        form.append('recepcion_id', String(id));
        const d = await api('?compras_api=1', {method: 'POST', body: form});
        mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
        await cargarRecepciones();
    }

    async function cancelarRecepcion(id) {
        const motivo = prompt('Motivo de cancelación de la recepción:');
        if (motivo == null) return;
        if (!motivo.trim()) {
            mostrarMensaje($('mensajePagina'), 'Debes indicar el motivo de cancelación.', 'error');
            return;
        }
        if (!confirm('Si la recepción ya está confirmada, el sistema intentará revertir su entrada de inventario y dejará evidencia en Kardex. ¿Continuar?')) return;
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', 'CANCELAR_RECEPCION');
        form.append('recepcion_id', String(id));
        form.append('motivo', motivo.trim());
        const d = await api('?compras_api=1', {method: 'POST', body: form});
        mostrarMensaje($('mensajePagina'), d.mensaje, 'success');
        await cargarRecepciones();
    }

    /* ======================== HISTORIAL ======================== */

    async function cargarHistorial() {
        const params = new URLSearchParams({
            compras_api: '1', accion: 'HISTORIAL',
            pagina: String(estado.paginaHistorial),
            por_pagina: String(estado.porPaginaHistorial),
            busqueda: $('buscarHistorial').value.trim(),
            desde: $('filtroDesdeHistorial').value,
            hasta: $('filtroHastaHistorial').value
        });
        $('tablaHistorial').innerHTML = '<tr><td colspan="5" class="empty-cell">Cargando...</td></tr>';
        const d = await api('?' + params.toString());
        const filas = d.historial || [];
        estado.totalPaginasHistorial = d.paginacion.total_paginas || 1;
        $('tablaHistorial').innerHTML = filas.length
            ? filas.map(h => '<tr><td>' + fechaHora(h.fecha_hora) + '</td><td>' + escapeHtml(h.usuario) + '</td><td><strong>' + escapeHtml(h.accion.replaceAll('_', ' ')) + '</strong></td><td>' + escapeHtml((h.entidad_tabla || '—') + (h.entidad_id ? ' #' + h.entidad_id : '')) + '</td><td>' + escapeHtml(h.descripcion || '—') + '</td></tr>').join('')
            : '<tr><td colspan="5" class="empty-cell">Sin movimientos en el historial.</td></tr>';
        renderPaginacion('Historial', d.paginacion);
    }

    function renderPaginacion(tipo, p) {
        $('textoPagina' + tipo).textContent = p.total_registros + ' registro(s)';
        $('pagina' + tipo + 'Actual').textContent = 'Página ' + p.pagina + ' de ' + p.total_paginas;
        $('btn' + tipo + 'Anterior').disabled = p.pagina <= 1;
        $('btn' + tipo + 'Siguiente').disabled = p.pagina >= p.total_paginas;
    }

    function mostrarErrorGlobal(e) {
        mostrarMensaje($('mensajePagina'), e.message || 'Ocurrió un error.', 'error');
    }

    /* ======================== EVENTOS ======================== */

    document.querySelectorAll('.module-tab').forEach(tab => tab.addEventListener('click', () => cambiarSeccion(tab.dataset.seccion)));
    document.querySelectorAll('[data-cerrar-modal]').forEach(btn => btn.addEventListener('click', () => cerrarModal(btn.dataset.cerrarModal)));
    document.querySelectorAll('.modal-backdrop').forEach(m => m.addEventListener('click', e => { if (e.target === m) cerrarModal(m.id); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(m => cerrarModal(m.id)); });

    $('btnNuevaCompra')?.addEventListener('click', nuevaCompra);
    $('btnNuevaRecepcion')?.addEventListener('click', nuevaRecepcion);

    $('buscarProveedorCompra').addEventListener('input', () => {
        clearTimeout(estado.timerProveedor);
        estado.timerProveedor = setTimeout(() => buscarProveedores().catch(mostrarErrorGlobal), 300);
    });
    $('resultadosProveedorCompra').addEventListener('click', e => {
        const b = e.target.closest('[data-provider-id]');
        if (b) seleccionarProveedor(Number(b.dataset.providerId)).catch(mostrarErrorGlobal);
    });

    $('buscarProductoCompra').addEventListener('input', () => {
        clearTimeout(estado.timerProducto);
        estado.timerProducto = setTimeout(() => buscarProductosProveedor().catch(mostrarErrorGlobal), 300);
    });
    $('resultadosProductoCompra').addEventListener('click', e => {
        const b = e.target.closest('[data-product-json]');
        if (!b) return;
        try { agregarProducto(JSON.parse(b.dataset.productJson)); }
        catch (err) { mostrarMensaje($('mensajeCompra'), 'No fue posible leer el producto seleccionado.', 'error'); }
    });

    $('tablaLineasCompra').addEventListener('input', e => {
        const tr = e.target.closest('[data-line-index]');
        const field = e.target.dataset.lineField;
        if (!tr || !field) return;
        const i = Number(tr.dataset.lineIndex);
        estado.lineasCompra[i][field] = Number(e.target.value || 0);
        recalcularTotalesCompra();
        const totalCell = tr.children[5]?.querySelector('strong');
        if (totalCell) totalCell.textContent = dinero(calcularLinea(estado.lineasCompra[i]).total);
    });
    $('tablaLineasCompra').addEventListener('click', e => {
        const b = e.target.closest('[data-remove-line]');
        if (!b) return;
        estado.lineasCompra.splice(Number(b.dataset.removeLine), 1);
        renderLineasCompra();
        recalcularTotalesCompra();
    });

    $('compraCondicion').addEventListener('change', () => { actualizarCamposCredito(); calcularVencimiento(); });
    $('compraDiasCredito').addEventListener('input', calcularVencimiento);
    $('compraFechaFactura').addEventListener('change', calcularVencimiento);
    $('compraFecha').addEventListener('change', () => { calcularVencimiento(); cargarTipoCambio().catch(mostrarErrorGlobal); });
    $('compraMoneda').addEventListener('change', () => cargarTipoCambio().catch(mostrarErrorGlobal));

    window.addEventListener('si:tipo-cambio-actualizado', () => {
        cargarTipoCambio().catch(mostrarErrorGlobal);
    });

    $('formCompra').querySelectorAll('[data-modo-guardar]').forEach(b => b.addEventListener('click', () => estado.modoGuardarCompra = b.dataset.modoGuardar));
    $('formCompra').addEventListener('submit', guardarCompra);

    $('tablaCompras').addEventListener('click', e => {
        const b = e.target.closest('[data-action]');
        if (!b) return;
        const id = Number(b.dataset.id);
        const action = b.dataset.action;
        const run = async () => {
            if (action === 'ver-compra') return verCompra(id);
            if (action === 'editar-compra') return editarCompra(id);
            if (action === 'confirmar-compra') return confirmarCompra(id);
            if (action === 'recibir-compra') return abrirRecepcionCompra(id);
            if (action === 'cancelar-compra') return cancelarCompra(id);
        };
        run().catch(mostrarErrorGlobal);
    });

    $('buscarCompraPendiente').addEventListener('input', () => {
        clearTimeout(estado.timerCompraPendiente);
        estado.timerCompraPendiente = setTimeout(() => buscarComprasPendientes().catch(mostrarErrorGlobal), 250);
    });
    $('resultadosCompraPendiente').addEventListener('click', e => {
        const b = e.target.closest('[data-pending-purchase-id]');
        if (b) abrirRecepcionCompra(Number(b.dataset.pendingPurchaseId)).catch(mostrarErrorGlobal);
    });

    $('tablaLineasRecepcion').addEventListener('input', e => {
        const tr = e.target.closest('[data-receipt-line-index]');
        const field = e.target.dataset.receiptField;
        if (!tr || !field) return;
        const i = Number(tr.dataset.receiptLineIndex);
        estado.lineasRecepcion[i][field] = field === 'observaciones' ? e.target.value : Number(e.target.value || 0);
    });
    $('tablaLineasRecepcion').addEventListener('change', e => {
        const tr = e.target.closest('[data-receipt-line-index]');
        const field = e.target.dataset.receiptField;
        if (!tr || !field) return;
        const i = Number(tr.dataset.receiptLineIndex);
        estado.lineasRecepcion[i][field] = field === 'observaciones' ? e.target.value : Number(e.target.value || 0);
    });

    $('formRecepcion').querySelectorAll('[data-modo-recepcion]').forEach(b => b.addEventListener('click', () => estado.modoGuardarRecepcion = b.dataset.modoRecepcion));
    $('formRecepcion').addEventListener('submit', guardarRecepcion);

    $('tablaRecepciones').addEventListener('click', e => {
        const b = e.target.closest('[data-action]');
        if (!b) return;
        const id = Number(b.dataset.id);
        const action = b.dataset.action;
        const run = async () => {
            if (action === 'ver-recepcion') return verRecepcion(id);
            if (action === 'editar-recepcion') return editarRecepcion(id);
            if (action === 'confirmar-recepcion') return confirmarRecepcion(id);
            if (action === 'cancelar-recepcion') return cancelarRecepcion(id);
        };
        run().catch(mostrarErrorGlobal);
    });

    function debounceFilter(inputId, timerKey, callback, delay = 300) {
        $(inputId).addEventListener('input', () => {
            clearTimeout(estado[timerKey]);
            estado[timerKey] = setTimeout(callback, delay);
        });
    }

    debounceFilter('buscarCompra', 'timerCompra', () => { estado.paginaCompra = 1; cargarCompras().catch(mostrarErrorGlobal); });
    ['filtroEstadoCompra', 'filtroCondicionCompra', 'filtroDesdeCompra', 'filtroHastaCompra'].forEach(id => $(id).addEventListener('change', () => { estado.paginaCompra = 1; cargarCompras().catch(mostrarErrorGlobal); }));
    $('porPaginaCompra').addEventListener('change', e => { estado.porPaginaCompra = Number(e.target.value); estado.paginaCompra = 1; cargarCompras().catch(mostrarErrorGlobal); });
    $('btnCompraAnterior').addEventListener('click', () => { if (estado.paginaCompra > 1) { estado.paginaCompra--; cargarCompras().catch(mostrarErrorGlobal); } });
    $('btnCompraSiguiente').addEventListener('click', () => { if (estado.paginaCompra < estado.totalPaginasCompra) { estado.paginaCompra++; cargarCompras().catch(mostrarErrorGlobal); } });

    debounceFilter('buscarRecepcion', 'timerRecepcion', () => { estado.paginaRecepcion = 1; cargarRecepciones().catch(mostrarErrorGlobal); });
    ['filtroEstadoRecepcion', 'filtroDesdeRecepcion', 'filtroHastaRecepcion'].forEach(id => $(id).addEventListener('change', () => { estado.paginaRecepcion = 1; cargarRecepciones().catch(mostrarErrorGlobal); }));
    $('porPaginaRecepcion').addEventListener('change', e => { estado.porPaginaRecepcion = Number(e.target.value); estado.paginaRecepcion = 1; cargarRecepciones().catch(mostrarErrorGlobal); });
    $('btnRecepcionAnterior').addEventListener('click', () => { if (estado.paginaRecepcion > 1) { estado.paginaRecepcion--; cargarRecepciones().catch(mostrarErrorGlobal); } });
    $('btnRecepcionSiguiente').addEventListener('click', () => { if (estado.paginaRecepcion < estado.totalPaginasRecepcion) { estado.paginaRecepcion++; cargarRecepciones().catch(mostrarErrorGlobal); } });

    debounceFilter('buscarHistorial', 'timerHistorial', () => { estado.paginaHistorial = 1; cargarHistorial().catch(mostrarErrorGlobal); });
    ['filtroDesdeHistorial', 'filtroHastaHistorial'].forEach(id => $(id).addEventListener('change', () => { estado.paginaHistorial = 1; cargarHistorial().catch(mostrarErrorGlobal); }));
    $('porPaginaHistorial').addEventListener('change', e => { estado.porPaginaHistorial = Number(e.target.value); estado.paginaHistorial = 1; cargarHistorial().catch(mostrarErrorGlobal); });
    $('btnHistorialAnterior').addEventListener('click', () => { if (estado.paginaHistorial > 1) { estado.paginaHistorial--; cargarHistorial().catch(mostrarErrorGlobal); } });
    $('btnHistorialSiguiente').addEventListener('click', () => { if (estado.paginaHistorial < estado.totalPaginasHistorial) { estado.paginaHistorial++; cargarHistorial().catch(mostrarErrorGlobal); } });

    async function iniciar() {
        await cargarCatalogos();
        cambiarSeccion(seccionInicial);
    }

    iniciar().catch(mostrarErrorGlobal);
})();
</script>
</body>
</html>
