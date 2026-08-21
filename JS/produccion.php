<?php

declare(strict_types=1);

if (isset($_GET['prod_api'])) {
    $endpoint = __DIR__ . '/../funciones/produccion_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/produccion_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('produccion.ver', false);

$tituloPagina = 'Producción';
$puedeRegistrar = si_tiene_permiso('produccion.registrar');
$csrfToken = si_token_csrf();

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_produccion.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Producción | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_produccion.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content prod-page">
            <header class="module-heading prod-heading">
                <div>
                    <p class="module-eyebrow">ALMACÉN · PRODUCCIÓN SIMPLE</p>
                    <h1>Producción</h1>
                    <p>Registra lo realmente utilizado y lo realmente producido. Al confirmar, las materias primas salen del inventario y el producto terminado entra con sus movimientos de Kardex.</p>
                </div>
                <?php if ($puedeRegistrar): ?>
                    <button type="button" class="btn-primary" id="btnNuevaProduccion">Nueva producción</button>
                <?php endif; ?>
            </header>

            <div class="flow-note">
                <strong>Flujo:</strong> BORRADOR no modifica inventario. CONFIRMAR genera SALIDA_PRODUCCION de materias primas y ENTRADA_PRODUCCION del producto terminado. No hay recetas ni fórmulas automáticas.
            </div>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section id="vistaListado">
                <section class="stats-grid stats-grid--5">
                    <article><span>Total</span><strong id="kpiTotal">0</strong><small>producciones registradas</small></article>
                    <article><span>Borradores</span><strong id="kpiBorradores">0</strong><small>sin afectar inventario</small></article>
                    <article><span>Confirmadas</span><strong id="kpiConfirmadas">0</strong><small>inventario aplicado</small></article>
                    <article><span>Este mes</span><strong id="kpiMes">0</strong><small>confirmadas</small></article>
                    <article><span>Canceladas</span><strong id="kpiCanceladas">0</strong><small>conservan historial</small></article>
                </section>

                <section class="module-card">
                    <div class="filters-grid prod-filters">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="filtroBuscar" maxlength="120" placeholder="Folio, producto terminado o SKU" autocomplete="off">
                        </label>
                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstado">
                                <option value="TODOS">Todos</option>
                                <option value="BORRADOR">Borrador</option>
                                <option value="CONFIRMADA">Confirmada</option>
                                <option value="CANCELADA">Cancelada</option>
                            </select>
                        </label>
                        <label class="field">
                            <span>Desde</span>
                            <input type="date" id="filtroDesde">
                        </label>
                        <label class="field">
                            <span>Hasta</span>
                            <input type="date" id="filtroHasta">
                        </label>
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

                    <div class="table-wrap prod-table-wrap">
                        <table class="module-table prod-table">
                            <thead>
                                <tr>
                                    <th>Fecha / folio</th>
                                    <th>Producto terminado</th>
                                    <th>Almacén</th>
                                    <th class="text-right">Cantidad</th>
                                    <th class="text-right">Insumos</th>
                                    <th>Estado</th>
                                    <th>Responsable</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaProducciones">
                                <tr><td colspan="8" class="empty-cell">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginacion">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnAnterior">Anterior</button>
                            <span id="paginaActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <?php if ($puedeRegistrar): ?>
            <section id="vistaEditor" hidden>
                <div class="section-actions editor-titlebar">
                    <div>
                        <p class="module-eyebrow">CAPTURA OPERATIVA</p>
                        <h2 id="tituloEditor">Nueva producción</h2>
                        <p id="subtituloEditor">Captura el resultado y las materias primas utilizadas realmente.</p>
                    </div>
                    <button type="button" class="btn-secondary" id="btnVolverListado">Volver al historial</button>
                </div>

                <div class="prod-editor-grid">
                    <section class="module-card prod-editor-main">
                        <div class="form-grid form-grid--3">
                            <label class="field">
                                <span>Fecha y hora</span>
                                <input type="datetime-local" id="fechaProduccion" required>
                            </label>
                            <label class="field">
                                <span>Almacén de producción</span>
                                <select id="almacenProduccion" required>
                                    <option value="">Selecciona almacén</option>
                                </select>
                            </label>
                            <label class="field field--wide">
                                <span>Observaciones generales <small>opcional</small></span>
                                <input type="text" id="observacionesProduccion" maxlength="4000" placeholder="Turno, lote interno, observación del proceso...">
                            </label>
                        </div>

                        <div class="editor-block">
                            <div class="block-heading">
                                <div>
                                    <span class="step-number">1</span>
                                    <div><h3>Producto terminado</h3><p>Registra lo que realmente quedó terminado.</p></div>
                                </div>
                            </div>

                            <div id="resultadoBuscador" class="product-picker">
                                <label class="field">
                                    <span>Buscar producto terminado</span>
                                    <input type="search" id="buscarResultado" maxlength="180" placeholder="SKU o nombre" autocomplete="off" disabled>
                                </label>
                                <div id="sugerenciasResultado" class="suggestions" hidden></div>
                            </div>

                            <div id="resultadoSeleccionado" class="selected-product" hidden>
                                <div class="selected-product__identity">
                                    <strong id="resultadoNombre">—</strong>
                                    <small id="resultadoMeta">—</small>
                                </div>
                                <button type="button" class="btn-secondary btn-small" id="btnCambiarResultado">Cambiar</button>
                                <label class="field">
                                    <span>Unidad / presentación</span>
                                    <select id="resultadoUnidad"></select>
                                </label>
                                <label class="field">
                                    <span>Cantidad producida</span>
                                    <input type="number" id="resultadoCantidad" min="0" step="0.000001" inputmode="decimal" placeholder="0">
                                </label>
                                <label class="field selected-product__note">
                                    <span>Observación <small>opcional</small></span>
                                    <input type="text" id="resultadoObservacion" maxlength="255" placeholder="Detalle del resultado">
                                </label>
                            </div>
                        </div>

                        <div class="editor-block">
                            <div class="block-heading">
                                <div>
                                    <span class="step-number">2</span>
                                    <div><h3>Materias primas utilizadas</h3><p>Agrega únicamente las cantidades consumidas realmente.</p></div>
                                </div>
                                <span class="counter-badge" id="contadorInsumos">0 insumos</span>
                            </div>

                            <div class="product-picker">
                                <label class="field">
                                    <span>Agregar materia prima</span>
                                    <input type="search" id="buscarInsumo" maxlength="180" placeholder="SKU o nombre" autocomplete="off" disabled>
                                </label>
                                <div id="sugerenciasInsumo" class="suggestions" hidden></div>
                            </div>

                            <div class="table-wrap ingredients-wrap">
                                <table class="module-table ingredients-table">
                                    <thead>
                                        <tr>
                                            <th>Materia prima</th>
                                            <th>Unidad / presentación</th>
                                            <th class="text-right">Disponible</th>
                                            <th>Cantidad usada</th>
                                            <th>Observación</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tablaInsumos">
                                        <tr><td colspan="6" class="empty-cell">Todavía no has agregado materias primas.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div id="resumenEditor" class="production-preview">
                            <strong>Antes de confirmar</strong>
                            <p>La producción en borrador no modifica inventario. Al confirmar, el sistema volverá a validar la existencia disponible de cada materia prima dentro de una transacción.</p>
                        </div>

                        <div class="editor-actions">
                            <button type="button" class="btn-secondary" id="btnGuardarBorrador">Guardar borrador</button>
                            <button type="button" class="btn-primary" id="btnGuardarConfirmar">Guardar y confirmar</button>
                        </div>
                    </section>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<div class="modal-backdrop" id="modalDetalle" hidden>
    <section class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="detalleTitulo">
        <header class="detail-modal__header">
            <div><span class="module-eyebrow">DETALLE DE PRODUCCIÓN</span><h2 id="detalleTitulo">Producción</h2></div>
            <button type="button" class="modal-close" id="btnCerrarDetalle" aria-label="Cerrar">×</button>
        </header>
        <div class="detail-modal__body" id="detalleContenido"></div>
        <div class="cancel-panel" id="panelCancelar" hidden>
            <label class="field">
                <span>Motivo de cancelación</span>
                <textarea id="motivoCancelacion" rows="3" maxlength="2000" placeholder="Explica por qué se cancela esta producción"></textarea>
            </label>
            <div class="cancel-panel__actions">
                <button type="button" class="btn-secondary" id="btnCerrarCancelar">No cancelar</button>
                <button type="button" class="btn-danger" id="btnConfirmarCancelacion">Confirmar cancelación</button>
            </div>
        </div>
        <footer class="detail-modal__footer" id="detalleAcciones"></footer>
    </section>
</div>

<script>
(() => {
    'use strict';

    const API = 'produccion.php?prod_api=1';
    const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const PUEDE_REGISTRAR = <?= $puedeRegistrar ? 'true' : 'false' ?>;

    const $ = (id) => document.getElementById(id);
    const estado = {
        catalogos: null,
        pagina: 1,
        paginas: 1,
        editorId: 0,
        resultado: null,
        insumos: [],
        detalleId: 0,
        detalleEstado: '',
        timers: {},
        secuenciaBusqueda: { resultado: 0, insumo: 0 },
    };

    function escapar(valor) {
        return String(valor ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function numero(valor, maxDec = 6) {
        const n = Number(valor || 0);
        return new Intl.NumberFormat('es-MX', { maximumFractionDigits: maxDec }).format(n);
    }

    function fechaHora(valor) {
        if (!valor) return '—';
        const d = new Date(String(valor).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return escapar(valor);
        return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(d);
    }

    function badgeEstado(valor) {
        const e = String(valor || '').toUpperCase();
        const clases = { BORRADOR: 'is-draft', CONFIRMADA: 'is-ok', CANCELADA: 'is-cancelled' };
        return `<span class="status-badge ${clases[e] || ''}">${escapar(e || '—')}</span>`;
    }

    function mensaje(texto, tipo = 'success') {
        const el = $('mensajePagina');
        if (!el) return;
        el.hidden = !texto;
        el.className = 'module-message ' + (tipo === 'error' ? 'is-error' : 'is-success');
        el.textContent = texto || '';
        if (texto) window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function apiGet(accion, params = {}) {
        const url = new URL(API, window.location.href);
        url.searchParams.set('accion', accion);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
        });
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
        let data;
        try { data = await response.json(); } catch { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!response.ok || !data.success) throw new Error(data.mensaje || 'No fue posible completar la operación.');
        return data;
    }

    async function apiPost(accion, campos = {}) {
        const fd = new FormData();
        fd.set('accion', accion);
        fd.set('csrf_token', CSRF);
        Object.entries(campos).forEach(([k, v]) => fd.set(k, typeof v === 'string' ? v : String(v ?? '')));
        const response = await fetch(API, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
        let data;
        try { data = await response.json(); } catch { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!response.ok || !data.success) throw new Error(data.mensaje || 'No fue posible completar la operación.');
        return data;
    }

    async function iniciar() {
        try {
            estado.catalogos = await apiGet('CATALOGOS');
            cargarAlmacenes();
            if ($('fechaProduccion')) {
                $('fechaProduccion').max = estado.catalogos.fecha_hora || '';
            }
            await listar();
        } catch (e) {
            mensaje(e.message, 'error');
        }
    }

    function cargarAlmacenes() {
        if (!$('almacenProduccion')) return;
        const opciones = (estado.catalogos?.almacenes || []).map(a =>
            `<option value="${a.id}">${escapar(a.nombre)} · ${escapar(a.codigo)}</option>`
        ).join('');
        $('almacenProduccion').innerHTML = `<option value="">Selecciona almacén</option>${opciones}`;
    }

    async function listar() {
        $('tablaProducciones').innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        try {
            const r = await apiGet('LISTAR', {
                pagina: estado.pagina,
                por_pagina: $('porPagina').value,
                buscar: $('filtroBuscar').value.trim(),
                estado: $('filtroEstado').value,
                desde: $('filtroDesde').value,
                hasta: $('filtroHasta').value,
            });
            estado.pagina = Number(r.paginacion.pagina || 1);
            estado.paginas = Number(r.paginacion.paginas || 1);
            renderListado(r.registros || []);
            renderPaginacion(r.paginacion || {});
            renderKpis(r.resumen || {});
        } catch (e) {
            $('tablaProducciones').innerHTML = `<tr><td colspan="8" class="empty-cell error-text">${escapar(e.message)}</td></tr>`;
        }
    }

    function renderKpis(k) {
        $('kpiTotal').textContent = Number(k.total || 0);
        $('kpiBorradores').textContent = Number(k.borradores || 0);
        $('kpiConfirmadas').textContent = Number(k.confirmadas || 0);
        $('kpiMes').textContent = Number(k.confirmadas_mes || 0);
        $('kpiCanceladas').textContent = Number(k.canceladas || 0);
    }

    function renderListado(registros) {
        if (!registros.length) {
            $('tablaProducciones').innerHTML = '<tr><td colspan="8" class="empty-cell">No hay producciones que coincidan con los filtros.</td></tr>';
            return;
        }
        $('tablaProducciones').innerHTML = registros.map(r => `
            <tr>
                <td><strong>${escapar(r.folio)}</strong><small>${fechaHora(r.fecha_produccion)}</small></td>
                <td><strong>${escapar(r.resultado_producto || 'Sin resultado')}</strong><small>${escapar(r.resultado_sku || '')}</small></td>
                <td>${escapar(r.resultado_almacen || '—')}</td>
                <td class="text-right">${r.resultado_cantidad !== null ? `${numero(r.resultado_cantidad)} <small>${escapar(r.resultado_unidad || '')}</small>` : '—'}</td>
                <td class="text-right">${Number(r.cantidad_insumos || 0)}</td>
                <td>${badgeEstado(r.estado)}</td>
                <td>${escapar(r.confirmado_por || r.creado_por || '—')}</td>
                <td class="text-right actions-cell">
                    <button type="button" class="btn-link" data-ver="${r.id}">Ver</button>
                    ${PUEDE_REGISTRAR && r.estado === 'BORRADOR' ? `<button type="button" class="btn-link" data-editar="${r.id}">Editar</button>` : ''}
                </td>
            </tr>
        `).join('');
    }

    function renderPaginacion(p) {
        const total = Number(p.total || 0);
        $('textoPaginacion').textContent = `${total} ${total === 1 ? 'registro' : 'registros'}`;
        $('paginaActual').textContent = `Página ${Number(p.pagina || 1)} de ${Number(p.paginas || 1)}`;
        $('btnAnterior').disabled = estado.pagina <= 1;
        $('btnSiguiente').disabled = estado.pagina >= estado.paginas;
    }

    function debounce(clave, fn, espera = 280) {
        clearTimeout(estado.timers[clave]);
        estado.timers[clave] = setTimeout(fn, espera);
    }

    function limpiarEditor() {
        estado.editorId = 0;
        estado.resultado = null;
        estado.insumos = [];
        $('tituloEditor').textContent = 'Nueva producción';
        $('subtituloEditor').textContent = 'Captura el resultado y las materias primas utilizadas realmente.';
        $('fechaProduccion').value = estado.catalogos?.fecha_hora || '';
        $('almacenProduccion').value = estado.catalogos?.almacenes?.[0]?.id ? String(estado.catalogos.almacenes[0].id) : '';
        $('observacionesProduccion').value = '';
        $('buscarResultado').value = '';
        $('buscarInsumo').value = '';
        $('resultadoObservacion').value = '';
        $('resultadoCantidad').value = '';
        habilitarBuscadores();
        renderResultado();
        renderInsumos();
    }

    function abrirEditorNuevo() {
        limpiarEditor();
        mostrarEditor();
    }

    function mostrarEditor() {
        $('vistaListado').hidden = true;
        $('vistaEditor').hidden = false;
        $('btnNuevaProduccion').disabled = true;
        mensaje('');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function cerrarEditor() {
        $('vistaEditor').hidden = true;
        $('vistaListado').hidden = false;
        $('btnNuevaProduccion').disabled = false;
        estado.editorId = 0;
        estado.resultado = null;
        estado.insumos = [];
        listar();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function habilitarBuscadores() {
        const hayAlmacen = Boolean($('almacenProduccion').value);
        $('buscarResultado').disabled = !hayAlmacen;
        $('buscarInsumo').disabled = !hayAlmacen;
        $('buscarResultado').placeholder = hayAlmacen ? 'SKU o nombre' : 'Primero selecciona almacén';
        $('buscarInsumo').placeholder = hayAlmacen ? 'SKU o nombre' : 'Primero selecciona almacén';
    }

    async function buscarProductos(tipo) {
        const esResultado = tipo === 'resultado';
        const input = $(esResultado ? 'buscarResultado' : 'buscarInsumo');
        const cont = $(esResultado ? 'sugerenciasResultado' : 'sugerenciasInsumo');
        const q = input.value.trim();
        const almacenId = $('almacenProduccion').value;
        if (!almacenId || q.length < 2) {
            cont.hidden = true;
            cont.innerHTML = '';
            return;
        }

        const sec = ++estado.secuenciaBusqueda[tipo];
        try {
            const r = await apiGet('BUSCAR_PRODUCTOS', { q, almacen_id: almacenId, tipo: esResultado ? 'RESULTADO' : 'INSUMO' });
            if (sec !== estado.secuenciaBusqueda[tipo]) return;
            const productos = r.productos || [];
            cont.innerHTML = productos.length ? productos.map(p => `
                <button type="button" class="suggestion-item" data-producto='${escapar(JSON.stringify(p))}'>
                    <strong>${escapar(p.nombre)}</strong>
                    <span>${escapar(p.sku)} · ${esResultado ? `Actual ${numero(p.existencia_fisica)} ${escapar(p.simbolo_base)}` : `Disponible ${numero(p.cantidad_disponible)} ${escapar(p.simbolo_base)}`}</span>
                </button>
            `).join('') : '<div class="suggestion-empty">No se encontraron productos.</div>';
            cont.hidden = false;
        } catch (e) {
            cont.innerHTML = `<div class="suggestion-empty error-text">${escapar(e.message)}</div>`;
            cont.hidden = false;
        }
    }

    async function cargarOpcionesProducto(producto) {
        const r = await apiGet('OPCIONES_UNIDAD', { producto_id: producto.id });
        return r.opciones || [];
    }

    async function seleccionarResultado(producto) {
        try {
            producto.opciones = await cargarOpcionesProducto(producto);
            producto.opcion_unidad = 'BASE';
            producto.cantidad = '';
            producto.observaciones = '';
            estado.resultado = producto;
            $('buscarResultado').value = '';
            $('sugerenciasResultado').hidden = true;
            renderResultado();
        } catch (e) { mensaje(e.message, 'error'); }
    }

    async function agregarInsumo(producto) {
        if (estado.insumos.some(i => Number(i.id) === Number(producto.id))) {
            mensaje('Esa materia prima ya está agregada. Modifica la cantidad del renglón existente.', 'error');
            $('sugerenciasInsumo').hidden = true;
            return;
        }
        try {
            producto.opciones = await cargarOpcionesProducto(producto);
            producto.opcion_unidad = 'BASE';
            producto.cantidad = '';
            producto.observaciones = '';
            estado.insumos.push(producto);
            $('buscarInsumo').value = '';
            $('sugerenciasInsumo').hidden = true;
            renderInsumos();
        } catch (e) { mensaje(e.message, 'error'); }
    }

    function renderResultado() {
        const r = estado.resultado;
        $('resultadoBuscador').hidden = Boolean(r);
        $('resultadoSeleccionado').hidden = !r;
        if (!r) return;

        $('resultadoNombre').textContent = `${r.nombre} · ${r.sku}`;
        $('resultadoMeta').textContent = `Existencia actual: ${numero(r.existencia_fisica)} · Reservado: ${numero(r.cantidad_reservada)} · Disponible: ${numero(r.cantidad_disponible)} ${r.simbolo_base || ''}`;
        $('resultadoUnidad').innerHTML = (r.opciones || []).map(o => `<option value="${escapar(o.valor)}">${escapar(o.nombre)} · factor ${numero(o.factor)}</option>`).join('');
        $('resultadoUnidad').value = r.opcion_unidad || 'BASE';
        $('resultadoCantidad').value = r.cantidad ?? '';
        $('resultadoCantidad').step = Number(r.permite_fraccion) === 1 ? '0.000001' : '1';
        $('resultadoObservacion').value = r.observaciones || '';
        actualizarPreview();
    }

    function renderInsumos() {
        $('contadorInsumos').textContent = `${estado.insumos.length} ${estado.insumos.length === 1 ? 'insumo' : 'insumos'}`;
        if (!estado.insumos.length) {
            $('tablaInsumos').innerHTML = '<tr><td colspan="6" class="empty-cell">Todavía no has agregado materias primas.</td></tr>';
            actualizarPreview();
            return;
        }

        $('tablaInsumos').innerHTML = estado.insumos.map((i, idx) => `
            <tr data-insumo-index="${idx}">
                <td><strong>${escapar(i.nombre)}</strong><small>${escapar(i.sku)}</small></td>
                <td><select class="line-select" data-campo="unidad">${(i.opciones || []).map(o => `<option value="${escapar(o.valor)}" ${o.valor === i.opcion_unidad ? 'selected' : ''}>${escapar(o.nombre)}</option>`).join('')}</select></td>
                <td class="text-right"><strong>${numero(i.cantidad_disponible)}</strong><small>${escapar(i.simbolo_base || '')}</small></td>
                <td><input class="line-input" data-campo="cantidad" type="number" min="0" step="${Number(i.permite_fraccion) === 1 ? '0.000001' : '1'}" inputmode="decimal" value="${escapar(i.cantidad ?? '')}" placeholder="0"></td>
                <td><input class="line-input" data-campo="observaciones" type="text" maxlength="255" value="${escapar(i.observaciones || '')}" placeholder="Opcional"></td>
                <td class="text-right"><button type="button" class="btn-remove" data-quitar="${idx}" title="Quitar">×</button></td>
            </tr>
        `).join('');
        actualizarPreview();
    }

    function factorSeleccionado(item) {
        const op = (item.opciones || []).find(o => o.valor === item.opcion_unidad);
        return Number(op?.factor || 1);
    }

    function actualizarPreview() {
        if (!$('resumenEditor')) return;
        const r = estado.resultado;
        let textoResultado = 'Falta seleccionar el producto terminado.';
        if (r) {
            const cant = Number(r.cantidad || 0);
            const base = cant * factorSeleccionado(r);
            textoResultado = cant > 0
                ? `Resultado: ${numero(cant)} en la unidad seleccionada = ${numero(base)} ${escapar(r.simbolo_base || '')} en inventario.`
                : 'Captura la cantidad producida.';
        }
        const usados = estado.insumos.filter(i => Number(i.cantidad || 0) > 0).length;
        $('resumenEditor').innerHTML = `<strong>Antes de confirmar</strong><p>${textoResultado} · ${usados} de ${estado.insumos.length} insumos tienen cantidad capturada. El servidor volverá a validar las existencias disponibles antes de aplicar el Kardex.</p>`;
    }

    function payloadEditor() {
        if (estado.resultado) {
            estado.resultado.opcion_unidad = $('resultadoUnidad').value;
            estado.resultado.cantidad = $('resultadoCantidad').value;
            estado.resultado.observaciones = $('resultadoObservacion').value.trim();
        }
        return {
            fecha_produccion: $('fechaProduccion').value,
            almacen_id: Number($('almacenProduccion').value || 0),
            observaciones: $('observacionesProduccion').value.trim(),
            resultado: estado.resultado ? {
                producto_id: Number(estado.resultado.id),
                opcion_unidad: estado.resultado.opcion_unidad,
                cantidad: estado.resultado.cantidad,
                observaciones: estado.resultado.observaciones,
            } : {},
            insumos: estado.insumos.map(i => ({
                producto_id: Number(i.id),
                opcion_unidad: i.opcion_unidad || 'BASE',
                cantidad: i.cantidad ?? '',
                observaciones: i.observaciones || '',
            })),
        };
    }

    function validarEditor() {
        const p = payloadEditor();
        if (!p.fecha_produccion) return 'Captura la fecha y hora de producción.';
        if (!p.almacen_id) return 'Selecciona el almacén de producción.';
        if (!p.resultado.producto_id) return 'Selecciona el producto terminado.';
        if (!(Number(p.resultado.cantidad) > 0)) return 'Captura una cantidad producida mayor a cero.';
        if (!p.insumos.length) return 'Agrega al menos una materia prima.';
        if (p.insumos.some(i => !(Number(i.cantidad) > 0))) return 'Captura la cantidad utilizada de todas las materias primas.';
        return '';
    }

    async function guardar(confirmar) {
        const error = validarEditor();
        if (error) { mensaje(error, 'error'); return; }
        if (confirmar && !window.confirm('Al confirmar se descontarán las materias primas y se dará entrada al producto terminado. ¿Deseas continuar?')) return;

        const btn1 = $('btnGuardarBorrador');
        const btn2 = $('btnGuardarConfirmar');
        btn1.disabled = btn2.disabled = true;
        try {
            const r = await apiPost(confirmar ? 'GUARDAR_CONFIRMAR' : 'GUARDAR_BORRADOR', {
                produccion_id: estado.editorId || 0,
                payload: JSON.stringify(payloadEditor()),
            });
            cerrarEditor();
            mensaje(`${r.mensaje} Folio: ${r.folio}.`, 'success');
        } catch (e) {
            mensaje(e.message, 'error');
        } finally {
            btn1.disabled = btn2.disabled = false;
        }
    }

    async function editar(id) {
        try {
            const r = await apiGet('DETALLE', { id });
            if (r.produccion.estado !== 'BORRADOR') throw new Error('Solo los borradores pueden editarse.');
            limpiarEditor();
            estado.editorId = Number(id);
            $('tituloEditor').textContent = `Editar ${r.produccion.folio}`;
            $('subtituloEditor').textContent = 'Los cambios siguen sin afectar inventario hasta confirmar.';
            $('fechaProduccion').value = String(r.produccion.fecha_produccion || '').replace(' ', 'T').slice(0, 16);
            $('observacionesProduccion').value = r.produccion.observaciones || '';

            const resultado = r.resultados?.[0];
            const almacenId = resultado?.almacen_id || r.insumos?.[0]?.almacen_id || estado.catalogos?.almacenes?.[0]?.id || '';
            $('almacenProduccion').value = String(almacenId);
            habilitarBuscadores();

            if (resultado) {
                const opciones = await cargarOpcionesProducto({ id: resultado.producto_id });
                const opcion = opciones.find(o => Number(o.unidad_id) === Number(resultado.unidad_id) && Math.abs(Number(o.factor) - Number(resultado.factor_a_unidad_base)) < 0.000001) || opciones[0];
                estado.resultado = {
                    id: Number(resultado.producto_id), nombre: resultado.producto, sku: resultado.sku,
                    permite_fraccion: Number(resultado.permite_fraccion), existencia_fisica: Number(resultado.existencia_actual),
                    cantidad_reservada: Number(resultado.reservado_actual), cantidad_disponible: Number(resultado.disponible_actual),
                    simbolo_base: resultado.simbolo_base || resultado.simbolo, opciones, opcion_unidad: opcion?.valor || 'BASE',
                    cantidad: Number(resultado.cantidad), observaciones: resultado.observaciones || '',
                };
            }

            estado.insumos = await Promise.all((r.insumos || []).map(async i => {
                const opciones = await cargarOpcionesProducto({ id: i.producto_id });
                const opcion = opciones.find(o => Number(o.unidad_id) === Number(i.unidad_id) && Math.abs(Number(o.factor) - Number(i.factor_a_unidad_base)) < 0.000001) || opciones[0];
                return {
                    id: Number(i.producto_id), nombre: i.producto, sku: i.sku,
                    permite_fraccion: Number(i.permite_fraccion), existencia_fisica: Number(i.existencia_actual),
                    cantidad_reservada: Number(i.reservado_actual), cantidad_disponible: Number(i.disponible_actual),
                    simbolo_base: i.simbolo_base || i.simbolo, opciones, opcion_unidad: opcion?.valor || 'BASE',
                    cantidad: Number(i.cantidad), observaciones: i.observaciones || '',
                };
            }));
            renderResultado();
            renderInsumos();
            mostrarEditor();
        } catch (e) { mensaje(e.message, 'error'); }
    }

    async function verDetalle(id) {
        try {
            const r = await apiGet('DETALLE', { id });
            estado.detalleId = Number(id);
            estado.detalleEstado = r.produccion.estado;
            renderDetalle(r);
            $('modalDetalle').hidden = false;
            document.body.classList.add('modal-open');
        } catch (e) { mensaje(e.message, 'error'); }
    }

    function renderDetalle(r) {
        const p = r.produccion;
        $('detalleTitulo').textContent = `${p.folio} · ${p.estado}`;
        const resultado = r.resultados?.[0];
        const insumos = r.insumos || [];
        const movimientos = r.movimientos || [];

        $('detalleContenido').innerHTML = `
            <div class="detail-summary-grid">
                <div><span>Fecha producción</span><strong>${fechaHora(p.fecha_produccion)}</strong></div>
                <div><span>Estado</span><strong>${badgeEstado(p.estado)}</strong></div>
                <div><span>Creado por</span><strong>${escapar(p.creado_por || '—')}</strong></div>
                <div><span>Confirmado por</span><strong>${escapar(p.confirmado_por || '—')}</strong></div>
            </div>
            ${p.observaciones ? `<div class="detail-note"><strong>Observaciones</strong><p>${escapar(p.observaciones)}</p></div>` : ''}
            ${p.motivo_cancelacion ? `<div class="detail-note is-danger"><strong>Motivo de cancelación</strong><p>${escapar(p.motivo_cancelacion)}</p></div>` : ''}
            <section class="detail-section">
                <h3>Producto terminado</h3>
                ${resultado ? `<div class="result-card"><div><strong>${escapar(resultado.producto)}</strong><small>${escapar(resultado.sku)} · ${escapar(resultado.almacen)}</small></div><div class="result-card__qty"><strong>${numero(resultado.cantidad)}</strong><small>${escapar(resultado.unidad)} (${escapar(resultado.simbolo)}) · ${numero(resultado.cantidad_base)} base</small></div></div>` : '<p class="muted-text">Sin resultado.</p>'}
            </section>
            <section class="detail-section">
                <h3>Materias primas</h3>
                <div class="table-wrap"><table class="module-table compact-table"><thead><tr><th>Producto</th><th>Almacén</th><th class="text-right">Cantidad</th><th class="text-right">Cantidad base</th></tr></thead><tbody>
                    ${insumos.length ? insumos.map(i => `<tr><td><strong>${escapar(i.producto)}</strong><small>${escapar(i.sku)}</small></td><td>${escapar(i.almacen)}</td><td class="text-right">${numero(i.cantidad)} ${escapar(i.simbolo)}</td><td class="text-right">${numero(i.cantidad_base)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty-cell">Sin insumos.</td></tr>'}
                </tbody></table></div>
            </section>
            <section class="detail-section">
                <h3>Movimientos de inventario</h3>
                ${movimientos.length ? `<div class="movement-list">${movimientos.map(m => `<div><strong>${escapar(m.folio)}</strong><span>${escapar(m.tipo)} · ${badgeEstado(m.estado)}</span></div>`).join('')}</div>` : '<p class="muted-text">Este borrador todavía no tiene movimientos de inventario.</p>'}
            </section>
        `;

        $('panelCancelar').hidden = true;
        $('motivoCancelacion').value = '';
        const acciones = [];
        if (PUEDE_REGISTRAR && p.editable) acciones.push(`<button type="button" class="btn-secondary" data-accion-detalle="editar">Editar borrador</button>`);
        if (PUEDE_REGISTRAR && p.confirmable) acciones.push(`<button type="button" class="btn-primary" data-accion-detalle="confirmar">Confirmar producción</button>`);
        if (PUEDE_REGISTRAR && p.cancelable) acciones.push(`<button type="button" class="btn-danger" data-accion-detalle="cancelar">Cancelar producción</button>`);
        $('detalleAcciones').innerHTML = acciones.join('');
    }

    function cerrarDetalle() {
        $('modalDetalle').hidden = true;
        $('panelCancelar').hidden = true;
        document.body.classList.remove('modal-open');
        estado.detalleId = 0;
        estado.detalleEstado = '';
    }

    async function confirmarDetalle() {
        if (!estado.detalleId) return;
        if (!window.confirm('Se aplicarán las salidas de materias primas y la entrada del producto terminado. ¿Confirmar producción?')) return;
        try {
            const r = await apiPost('CONFIRMAR', { produccion_id: estado.detalleId });
            cerrarDetalle();
            mensaje(r.mensaje, 'success');
            await listar();
        } catch (e) { mensaje(e.message, 'error'); }
    }

    async function cancelarDetalle() {
        const motivo = $('motivoCancelacion').value.trim();
        if (motivo.length < 3) { $('motivoCancelacion').focus(); return; }
        $('btnConfirmarCancelacion').disabled = true;
        try {
            const r = await apiPost('CANCELAR', { produccion_id: estado.detalleId, motivo });
            cerrarDetalle();
            mensaje(r.mensaje, 'success');
            await listar();
        } catch (e) {
            mensaje(e.message, 'error');
            $('btnConfirmarCancelacion').disabled = false;
        }
    }

    function enlazarEventos() {
        $('filtroBuscar').addEventListener('input', () => debounce('listado', () => { estado.pagina = 1; listar(); }, 300));
        ['filtroEstado', 'filtroDesde', 'filtroHasta', 'porPagina'].forEach(id => $(id).addEventListener('change', () => { estado.pagina = 1; listar(); }));
        $('btnAnterior').addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina--; listar(); } });
        $('btnSiguiente').addEventListener('click', () => { if (estado.pagina < estado.paginas) { estado.pagina++; listar(); } });

        $('tablaProducciones').addEventListener('click', e => {
            const ver = e.target.closest('[data-ver]');
            const editarBtn = e.target.closest('[data-editar]');
            if (ver) verDetalle(Number(ver.dataset.ver));
            if (editarBtn) editar(Number(editarBtn.dataset.editar));
        });

        if (PUEDE_REGISTRAR) {
            $('btnNuevaProduccion').addEventListener('click', abrirEditorNuevo);
            $('btnVolverListado').addEventListener('click', cerrarEditor);
            $('almacenProduccion').addEventListener('change', () => {
                estado.resultado = null;
                estado.insumos = [];
                $('buscarResultado').value = '';
                $('buscarInsumo').value = '';
                $('sugerenciasResultado').hidden = true;
                $('sugerenciasInsumo').hidden = true;
                habilitarBuscadores();
                renderResultado();
                renderInsumos();
            });

            $('buscarResultado').addEventListener('input', () => debounce('buscarResultado', () => buscarProductos('resultado')));
            $('buscarInsumo').addEventListener('input', () => debounce('buscarInsumo', () => buscarProductos('insumo')));

            $('sugerenciasResultado').addEventListener('click', e => {
                const b = e.target.closest('[data-producto]');
                if (!b) return;
                try { seleccionarResultado(JSON.parse(b.dataset.producto)); } catch { mensaje('No fue posible seleccionar el producto.', 'error'); }
            });
            $('sugerenciasInsumo').addEventListener('click', e => {
                const b = e.target.closest('[data-producto]');
                if (!b) return;
                try { agregarInsumo(JSON.parse(b.dataset.producto)); } catch { mensaje('No fue posible seleccionar la materia prima.', 'error'); }
            });

            $('btnCambiarResultado').addEventListener('click', () => { estado.resultado = null; renderResultado(); $('buscarResultado').focus(); });
            $('resultadoUnidad').addEventListener('change', () => { if (estado.resultado) estado.resultado.opcion_unidad = $('resultadoUnidad').value; actualizarPreview(); });
            $('resultadoCantidad').addEventListener('input', () => { if (estado.resultado) estado.resultado.cantidad = $('resultadoCantidad').value; actualizarPreview(); });
            $('resultadoObservacion').addEventListener('input', () => { if (estado.resultado) estado.resultado.observaciones = $('resultadoObservacion').value; });

            $('tablaInsumos').addEventListener('input', e => {
                const tr = e.target.closest('[data-insumo-index]');
                if (!tr) return;
                const i = estado.insumos[Number(tr.dataset.insumoIndex)];
                if (!i) return;
                if (e.target.dataset.campo === 'cantidad') i.cantidad = e.target.value;
                if (e.target.dataset.campo === 'observaciones') i.observaciones = e.target.value;
                actualizarPreview();
            });
            $('tablaInsumos').addEventListener('change', e => {
                const tr = e.target.closest('[data-insumo-index]');
                if (!tr) return;
                const i = estado.insumos[Number(tr.dataset.insumoIndex)];
                if (i && e.target.dataset.campo === 'unidad') i.opcion_unidad = e.target.value;
                actualizarPreview();
            });
            $('tablaInsumos').addEventListener('click', e => {
                const b = e.target.closest('[data-quitar]');
                if (!b) return;
                estado.insumos.splice(Number(b.dataset.quitar), 1);
                renderInsumos();
            });

            $('btnGuardarBorrador').addEventListener('click', () => guardar(false));
            $('btnGuardarConfirmar').addEventListener('click', () => guardar(true));
        }

        $('btnCerrarDetalle').addEventListener('click', cerrarDetalle);
        $('modalDetalle').addEventListener('click', e => { if (e.target === $('modalDetalle')) cerrarDetalle(); });
        $('detalleAcciones').addEventListener('click', e => {
            const b = e.target.closest('[data-accion-detalle]');
            if (!b) return;
            if (b.dataset.accionDetalle === 'editar') { const id = estado.detalleId; cerrarDetalle(); editar(id); }
            if (b.dataset.accionDetalle === 'confirmar') confirmarDetalle();
            if (b.dataset.accionDetalle === 'cancelar') { $('panelCancelar').hidden = false; $('motivoCancelacion').focus(); }
        });
        $('btnCerrarCancelar').addEventListener('click', () => { $('panelCancelar').hidden = true; $('motivoCancelacion').value = ''; });
        $('btnConfirmarCancelacion').addEventListener('click', cancelarDetalle);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('modalDetalle').hidden) cerrarDetalle(); });
    }

    enlazarEventos();
    iniciar();
})();
</script>
</body>
</html>
