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
                <strong>Flujo:</strong> BORRADOR no modifica inventario. Las recetas son plantillas por 1 presentación del producto final; al confirmar se registra el consumo real y se generan SALIDA_PRODUCCION y ENTRADA_PRODUCCION en Kardex.
            </div>

            <div id="mensajePagina" class="module-message" hidden></div>

            <nav class="prod-tabs" aria-label="Secciones de producción">
                <button type="button" class="prod-tab is-active" id="tabProducciones">Producciones</button>
                <button type="button" class="prod-tab" id="tabRecetas">Recetas</button>
            </nav>

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



            <section id="vistaRecetas" hidden>
                <div class="section-actions recipe-titlebar">
                    <div><p class="module-eyebrow">PLANTILLAS DE PRODUCCIÓN</p><h2>Recetas</h2><p>Define qué ingredientes necesita <strong>1 presentación</strong> de cada producto terminado. Al producir varios bultos, sacos o unidades, el sistema multiplica automáticamente las cantidades.</p></div>
                    <?php if ($puedeRegistrar): ?><button type="button" class="btn-primary" id="btnNuevaReceta">Nueva receta</button><?php endif; ?>
                </div>
                <section class="module-card">
                    <div class="filters-grid recipe-filters">
                        <label class="field field--search"><span>Buscar</span><input type="search" id="recetaBuscar" maxlength="160" placeholder="Nombre, código, producto o SKU" autocomplete="off"></label>
                        <label class="field"><span>Estado</span><select id="recetaEstado"><option value="TODAS" selected>Todas</option><option value="ACTIVAS">Activas</option><option value="INACTIVAS">Inactivas</option></select><small>Las recetas inactivas permanecen en el catálogo para conservar historial y poder reactivarlas.</small></label>
                        <label class="field"><span>Por página</span><select id="recetaPorPagina"><option value="6">6</option><option value="12" selected>12</option><option value="24">24</option><option value="48">48</option></select></label>
                    </div>
                </section>
                <div class="recipe-grid" id="recetaCards"><div class="recipe-empty">Cargando recetas...</div></div>
                <footer class="pagination recipe-pagination"><span id="recetaTextoPaginacion">0 recetas</span><div><button type="button" class="btn-secondary" id="recetaAnterior">Anterior</button><span id="recetaPaginaActual">Página 1 de 1</span><button type="button" class="btn-secondary" id="recetaSiguiente">Siguiente</button></div></footer>
            </section>

            <?php if ($puedeRegistrar): ?>
            <section id="vistaRecetaEditor" hidden>
                <div class="section-actions editor-titlebar"><div><p class="module-eyebrow">PLANTILLA DE PRODUCCIÓN</p><h2 id="recetaEditorTitulo">Nueva receta</h2><p>La receta se define para exactamente <strong>1 presentación</strong> del producto terminado.</p></div><button type="button" class="btn-secondary" id="btnVolverRecetas">Volver a recetas</button></div>
                <section class="module-card recipe-editor-card">
                    <div class="form-grid form-grid--2">
                        <label class="field"><span>Nombre de la receta</span><input type="text" id="recetaNombre" maxlength="160" placeholder="Ej. Chapetes Premium estándar"></label>
                        <label class="field"><span>Estado</span><select id="recetaActivo"><option value="1">Activa</option><option value="0">Inactiva</option></select></label>
                        <label class="field field--wide"><span>Observaciones <small>opcional</small></span><input type="text" id="recetaObservaciones" maxlength="4000" placeholder="Notas de preparación, rendimiento esperado, etc."></label>
                    </div>
                    <div class="editor-block"><div class="block-heading"><div><span class="step-number">1</span><div><h3>Producto terminado de referencia</h3><p>Selecciona el producto y la presentación para la cual definirás la fórmula. La base será siempre 1.</p></div></div></div>
                        <div id="recetaResultadoBuscador" class="product-picker"><label class="field"><span>Buscar producto terminado</span><input type="search" id="recetaBuscarResultado" maxlength="180" placeholder="SKU o nombre" autocomplete="off"></label><div id="recetaSugerenciasResultado" class="suggestions" hidden></div></div>
                        <div id="recetaResultadoSeleccionado" class="selected-product recipe-reference-selected" hidden>
                            <div class="selected-product__identity"><strong id="recetaResultadoNombre">—</strong><small id="recetaResultadoMeta">—</small></div>
                            <button type="button" class="btn-secondary btn-small" id="recetaCambiarResultado">Cambiar</button>
                            <label class="field"><span>Presentación de referencia</span><select id="recetaResultadoUnidad"></select></label>
                            <div class="recipe-reference-box"><span>Esta receta corresponde a</span><strong id="recetaReferenciaTexto">1 unidad</strong><small>Las cantidades de los ingredientes de abajo son las necesarias para elaborar exactamente esta referencia.</small></div>
                        </div>
                    </div>
                    <div class="editor-block"><div class="block-heading"><div><span class="step-number">2</span><div><h3>Ingredientes por presentación</h3><p id="recetaInsumosAyuda">Captura cuánto se utiliza de cada materia prima para producir 1 presentación del producto final.</p></div></div><span class="counter-badge" id="recetaContadorInsumos">0 ingredientes</span></div>
                        <div class="product-picker"><label class="field"><span>Agregar materia prima</span><input type="search" id="recetaBuscarInsumo" maxlength="180" placeholder="SKU o nombre" autocomplete="off"></label><div id="recetaSugerenciasInsumo" class="suggestions" hidden></div></div>
                        <div class="table-wrap ingredients-wrap"><table class="module-table ingredients-table"><thead><tr><th>Materia prima</th><th>Unidad / presentación</th><th id="recetaCantidadCabecera">Cantidad por unidad</th><th>Observación</th><th></th></tr></thead><tbody id="recetaTablaInsumos"><tr><td colspan="5" class="empty-cell">Todavía no has agregado materias primas.</td></tr></tbody></table></div>
                        <div id="recetaBalance" class="recipe-balance is-neutral">Selecciona el producto final y agrega ingredientes para revisar la relación entre insumos y rendimiento.</div>
                    </div>
                    <div class="editor-actions"><button type="button" class="btn-primary" id="btnGuardarReceta">Guardar receta</button></div>
                </section>
            </section>
            <?php endif; ?>

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

                        <div class="recipe-apply-panel">
                            <div><strong>Usar receta <small>opcional</small></strong><p>Elige una receta y cuántas presentaciones quieres fabricar. El sistema calculará automáticamente las materias primas necesarias.</p></div>
                            <label class="field"><span>Receta</span><select id="produccionReceta"><option value="">Captura manual</option></select></label>
                            <label class="field"><span id="produccionRecetaCantidadLabel">Cantidad a producir</span><input type="number" id="produccionRecetaCantidad" min="0" step="1" placeholder="0" disabled></label>
                            <button type="button" class="btn-secondary" id="btnAplicarReceta" disabled>Calcular ingredientes</button>
                            <small id="produccionRecetaEstado" class="recipe-apply-status">Puedes seguir capturando la producción manualmente si no utilizas una receta.</small>
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


<div class="modal-backdrop" id="modalReceta" hidden>
    <section class="detail-modal recipe-detail-modal" role="dialog" aria-modal="true" aria-labelledby="recetaDetalleTitulo">
        <header class="detail-modal__header"><div><span class="module-eyebrow">DETALLE DE RECETA</span><h2 id="recetaDetalleTitulo">Receta</h2></div><button type="button" class="modal-close" id="btnCerrarRecetaDetalle" aria-label="Cerrar">×</button></header>
        <div class="detail-modal__body" id="recetaDetalleContenido"></div>
        <footer class="detail-modal__footer" id="recetaDetalleAcciones"></footer>
    </section>
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
        recetaPagina: 1,
        recetaPaginas: 1,
        recetaEditorId: 0,
        recetaResultado: null,
        recetaInsumos: [],
        recetaDetalleId: 0,
        recetaAplicada: null,
        recetasSelector: [],
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
        if (!response.ok || !data.success) {
            const error = new Error(data.mensaje || 'No fue posible completar la operación.');
            error.data = data;
            error.status = response.status;
            throw error;
        }
        return data;
    }

    async function iniciar() {
        try {
            estado.catalogos = await apiGet('CATALOGOS');
            cargarAlmacenes();
            if ($('fechaProduccion')) {
                $('fechaProduccion').max = estado.catalogos.fecha_hora || '';
            }
            await cargarRecetasSelector();
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
        estado.recetaAplicada = null;
        if ($('produccionReceta')) $('produccionReceta').value = '';
        if ($('produccionRecetaCantidad')) { $('produccionRecetaCantidad').value = ''; $('produccionRecetaCantidad').disabled = true; $('produccionRecetaCantidad').step = '1'; }
        if ($('produccionRecetaCantidadLabel')) $('produccionRecetaCantidadLabel').textContent = 'Cantidad a producir';
        if ($('btnAplicarReceta')) $('btnAplicarReceta').disabled = true;
        if ($('produccionRecetaEstado')) $('produccionRecetaEstado').textContent = 'Puedes seguir capturando la producción manualmente si no utilizas una receta.';
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
            receta_id: estado.recetaAplicada?.id || 0,
            receta_version: estado.recetaAplicada?.version || 0,
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
            if (Number(r.produccion.receta_id || 0) > 0) {
                estado.recetaAplicada = { id: Number(r.produccion.receta_id), version: Number(r.produccion.receta_version || 0) };
                $('produccionReceta').value = String(r.produccion.receta_id);
                $('produccionRecetaCantidad').disabled = false;
                $('btnAplicarReceta').disabled = false;
                $('produccionRecetaEstado').textContent = `Borrador vinculado a receta v${Number(r.produccion.receta_version || 0)}. Las cantidades guardadas son la captura real.`;
            }

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
                if (Number(r.produccion.receta_id || 0) > 0) { $('produccionRecetaCantidad').value = String(Number(resultado.cantidad)); actualizarSelectorProduccionReceta(); }
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


    /* ======================== RECETAS ======================== */
    function recetaSeleccionadaProduccion() {
        const id = Number($('produccionReceta')?.value || 0);
        return estado.recetasSelector.find(x => Number(x.id) === id) || null;
    }

    function opcionSeleccionada(item) {
        if (!item) return null;
        return (item.opciones || []).find(o => o.valor === (item.opcion_unidad || 'BASE')) || item.opciones?.[0] || null;
    }

    function referenciaRecetaEditor() {
        const r = estado.recetaResultado;
        const op = opcionSeleccionada(r);
        return op ? `1 ${op.nombre}` : '1 presentación';
    }

    function masaKg(cantidadBase, codigoUnidad) {
        const factores = { KG: 1, G: 0.001, TON: 1000 };
        const factor = factores[String(codigoUnidad || '').toUpperCase()];
        return factor === undefined ? null : Number(cantidadBase || 0) * factor;
    }

    function actualizarBalanceReceta() {
        const box = $('recetaBalance');
        if (!box) return;
        const res = estado.recetaResultado;
        const opRes = opcionSeleccionada(res);
        if (!res || !opRes || !estado.recetaInsumos.length) {
            box.className = 'recipe-balance is-neutral';
            box.textContent = 'Selecciona el producto final y agrega ingredientes para revisar la relación entre insumos y rendimiento.';
            return;
        }
        const salidaKg = masaKg(Number(opRes.factor || 0), res.codigo_unidad_base);
        if (!(salidaKg > 0)) {
            box.className = 'recipe-balance is-neutral';
            box.textContent = `La receta está definida para ${referenciaRecetaEditor()}. El balance de masa no puede calcularse automáticamente para esta unidad base.`;
            return;
        }
        let entradaKg = 0, noComparables = 0;
        estado.recetaInsumos.forEach(i => {
            const op = opcionSeleccionada(i);
            const cantidad = Number(i.cantidad || 0);
            if (!op || !(cantidad > 0)) return;
            const kg = masaKg(cantidad * Number(op.factor || 0), i.codigo_unidad_base);
            if (kg === null) noComparables++; else entradaKg += kg;
        });
        const ratio = salidaKg > 0 ? entradaKg / salidaKg : 0;
        const extremo = ratio > 1.5 || (noComparables === 0 && ratio > 0 && ratio < 0.5);
        const aviso = ratio > 1.1 || (noComparables === 0 && ratio > 0 && ratio < 0.9);
        box.className = `recipe-balance ${extremo ? 'is-danger' : aviso ? 'is-warning' : 'is-ok'}`;
        let texto = `Referencia: ${referenciaRecetaEditor()} = ${numero(salidaKg)} kg. Ingredientes comparables: ${numero(entradaKg)} kg.`;
        if (noComparables) texto += ` ${noComparables} ingrediente(s) usan unidades que no se convierten directamente a kg.`;
        if (extremo) texto += ' La relación parece desproporcionada; revisa las cantidades antes de guardar.';
        else if (aviso) texto += ' Revisa la fórmula: existe una diferencia importante entre insumos y producto final.';
        else texto += ' La relación capturada es razonable como referencia.';
        box.textContent = texto;
    }

    async function cargarRecetasSelector() {
        if (!$('produccionReceta')) return;
        try {
            const r = await apiGet('RECETAS_SELECTOR');
            estado.recetasSelector = r.recetas || [];
            $('produccionReceta').innerHTML = '<option value="">Captura manual</option>' + estado.recetasSelector.map(x =>
                `<option value="${x.id}">${escapar(x.nombre)} · ${escapar(x.producto)} · ${escapar(x.referencia)} · v${x.version}</option>`
            ).join('');
        } catch (e) { mensaje(e.message, 'error'); }
    }

    function mostrarSeccion(seccion) {
        const recetas = seccion === 'recetas';
        $('tabProducciones').classList.toggle('is-active', !recetas);
        $('tabRecetas').classList.toggle('is-active', recetas);
        $('vistaListado').hidden = recetas;
        $('vistaRecetas').hidden = !recetas;
        if ($('vistaEditor')) $('vistaEditor').hidden = true;
        if ($('vistaRecetaEditor')) $('vistaRecetaEditor').hidden = true;
        if ($('btnNuevaProduccion')) {
            $('btnNuevaProduccion').hidden = recetas;
            $('btnNuevaProduccion').disabled = false;
        }
        if (recetas) listarRecetas(); else listar();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function listarRecetas() {
        $('recetaCards').innerHTML = '<div class="recipe-empty">Cargando recetas...</div>';
        try {
            const r = await apiGet('RECETAS_LISTAR', {
                pagina: estado.recetaPagina,
                por_pagina: $('recetaPorPagina').value,
                buscar: $('recetaBuscar').value.trim(),
                estado: $('recetaEstado').value,
            });
            estado.recetaPagina = Number(r.paginacion.pagina || 1);
            estado.recetaPaginas = Number(r.paginacion.paginas || 1);
            const rows = r.registros || [];
            $('recetaCards').innerHTML = rows.length ? rows.map(x => {
                const resumen = Array.isArray(x.ingredientes_resumen) && x.ingredientes_resumen.length
                    ? x.ingredientes_resumen.map(v => escapar(v)).join(' · ')
                    : 'Sin ingredientes';
                const extra = Number(x.insumos) > 3 ? ` +${Number(x.insumos) - 3}` : '';
                return `<article class="recipe-card ${Number(x.activo) === 1 ? '' : 'is-inactive'}" data-receta-card="${x.id}">
                    <div class="recipe-card__top"><span class="recipe-code">${escapar(x.codigo)}</span><span class="status-badge ${Number(x.activo) === 1 ? 'is-ok' : 'is-cancelled'}">${Number(x.activo) === 1 ? 'ACTIVA' : 'INACTIVA'}</span></div>
                    <h3>${escapar(x.nombre)}</h3>
                    <p class="recipe-product">${escapar(x.producto)} <small>${escapar(x.sku)}</small></p>
                    <div class="recipe-reference-card"><span>Receta para</span><strong>${escapar(x.referencia)}</strong></div>
                    <div class="recipe-card__ingredients"><span>Ingredientes</span><p>${resumen}${extra}</p></div>
                    <div class="recipe-metrics"><div><span>Total ingredientes</span><strong>${Number(x.insumos)}</strong></div><div><span>Versión</span><strong>v${Number(x.version)}</strong></div></div>
                    ${x.observaciones ? `<p class="recipe-note">${escapar(x.observaciones)}</p>` : ''}
                    <button type="button" class="btn-secondary recipe-view" data-ver-receta="${x.id}">Ver detalles</button>
                </article>`;
            }).join('') : '<div class="recipe-empty">No hay recetas que coincidan con los filtros.</div>';
            const p = r.paginacion;
            $('recetaTextoPaginacion').textContent = `${p.total} ${Number(p.total) === 1 ? 'receta' : 'recetas'}`;
            $('recetaPaginaActual').textContent = `Página ${p.pagina} de ${p.paginas}`;
            $('recetaAnterior').disabled = estado.recetaPagina <= 1;
            $('recetaSiguiente').disabled = estado.recetaPagina >= estado.recetaPaginas;
        } catch (e) {
            $('recetaCards').innerHTML = `<div class="recipe-empty error-text">${escapar(e.message)}</div>`;
        }
    }

    function limpiarRecetaEditor() {
        estado.recetaEditorId = 0;
        estado.recetaResultado = null;
        estado.recetaInsumos = [];
        $('recetaEditorTitulo').textContent = 'Nueva receta';
        $('recetaNombre').value = '';
        $('recetaActivo').value = '1';
        $('recetaObservaciones').value = '';
        $('recetaBuscarResultado').value = '';
        $('recetaBuscarInsumo').value = '';
        renderRecetaResultado();
        renderRecetaInsumos();
        actualizarBalanceReceta();
    }

    function abrirRecetaEditor() { limpiarRecetaEditor(); $('vistaRecetas').hidden = true; $('vistaRecetaEditor').hidden = false; window.scrollTo({ top: 0, behavior: 'smooth' }); }
    function cerrarRecetaEditor() { $('vistaRecetaEditor').hidden = true; $('vistaRecetas').hidden = false; estado.recetaEditorId = 0; listarRecetas(); }

    async function recetaBuscarProductos(tipo) {
        const resultado = tipo === 'resultado';
        const input = $(resultado ? 'recetaBuscarResultado' : 'recetaBuscarInsumo');
        const cont = $(resultado ? 'recetaSugerenciasResultado' : 'recetaSugerenciasInsumo');
        const q = input.value.trim();
        if (q.length < 2) { cont.hidden = true; return; }
        try {
            const r = await apiGet('RECETA_BUSCAR_PRODUCTOS', { q, tipo: resultado ? 'RESULTADO' : 'INSUMO' });
            const rows = r.productos || [];
            cont.innerHTML = rows.length ? rows.map(x => `<button type="button" class="suggestion-item" data-receta-producto='${escapar(JSON.stringify(x))}'><strong>${escapar(x.nombre)}</strong><span>${escapar(x.sku)} · ${escapar(x.unidad_base)} (${escapar(x.simbolo_base)})</span></button>`).join('') : '<div class="suggestion-empty">No se encontraron productos.</div>';
            cont.hidden = false;
        } catch (e) {
            cont.innerHTML = `<div class="suggestion-empty error-text">${escapar(e.message)}</div>`;
            cont.hidden = false;
        }
    }

    async function recetaSeleccionarResultado(x) {
        x.opciones = await cargarOpcionesProducto(x);
        const presentacion = (x.opciones || []).find(o => Number(o.es_base) !== 1) || x.opciones?.[0];
        x.opcion_unidad = presentacion?.valor || 'BASE';
        estado.recetaResultado = x;
        $('recetaBuscarResultado').value = '';
        $('recetaSugerenciasResultado').hidden = true;
        renderRecetaResultado();
        renderRecetaInsumos();
    }

    async function recetaAgregarInsumo(x) {
        if (estado.recetaInsumos.some(i => Number(i.id) === Number(x.id))) {
            mensaje('Esa materia prima ya está agregada a la receta.', 'error');
            return;
        }
        x.opciones = await cargarOpcionesProducto(x);
        x.opcion_unidad = 'BASE';
        x.cantidad = '';
        x.observaciones = '';
        estado.recetaInsumos.push(x);
        $('recetaBuscarInsumo').value = '';
        $('recetaSugerenciasInsumo').hidden = true;
        renderRecetaInsumos();
    }

    function renderRecetaResultado() {
        const r = estado.recetaResultado;
        $('recetaResultadoBuscador').hidden = Boolean(r);
        $('recetaResultadoSeleccionado').hidden = !r;
        if (!r) {
            $('recetaReferenciaTexto').textContent = '1 presentación';
            actualizarBalanceReceta();
            return;
        }
        $('recetaResultadoNombre').textContent = `${r.nombre} · ${r.sku}`;
        $('recetaResultadoMeta').textContent = `Unidad base: ${r.unidad_base || ''} (${r.simbolo_base || ''})`;
        $('recetaResultadoUnidad').innerHTML = (r.opciones || []).map(o => `<option value="${escapar(o.valor)}">${escapar(o.nombre)}</option>`).join('');
        $('recetaResultadoUnidad').value = r.opcion_unidad || 'BASE';
        const referencia = referenciaRecetaEditor();
        $('recetaReferenciaTexto').textContent = referencia;
        $('recetaInsumosAyuda').textContent = `Captura cuánto se utiliza de cada materia prima para producir ${referencia}.`;
        $('recetaCantidadCabecera').textContent = `Cantidad por ${referencia}`;
        actualizarBalanceReceta();
    }

    function renderRecetaInsumos() {
        const referencia = referenciaRecetaEditor();
        $('recetaContadorInsumos').textContent = `${estado.recetaInsumos.length} ${estado.recetaInsumos.length === 1 ? 'ingrediente' : 'ingredientes'}`;
        $('recetaInsumosAyuda').textContent = `Captura cuánto se utiliza de cada materia prima para producir ${referencia}.`;
        $('recetaCantidadCabecera').textContent = `Cantidad por ${referencia}`;
        if (!estado.recetaInsumos.length) {
            $('recetaTablaInsumos').innerHTML = '<tr><td colspan="5" class="empty-cell">Todavía no has agregado materias primas.</td></tr>';
            actualizarBalanceReceta();
            return;
        }
        $('recetaTablaInsumos').innerHTML = estado.recetaInsumos.map((i, n) => `<tr data-receta-insumo="${n}">
            <td><strong>${escapar(i.nombre)}</strong><small>${escapar(i.sku)}</small></td>
            <td><select class="line-select" data-receta-campo="unidad">${(i.opciones || []).map(o => `<option value="${escapar(o.valor)}" ${o.valor === i.opcion_unidad ? 'selected' : ''}>${escapar(o.nombre)}</option>`).join('')}</select></td>
            <td><input class="line-input" data-receta-campo="cantidad" type="number" min="0" step="${Number(i.permite_fraccion) === 1 ? '0.000001' : '1'}" value="${escapar(i.cantidad ?? '')}" placeholder="0"></td>
            <td><input class="line-input" data-receta-campo="observaciones" maxlength="255" value="${escapar(i.observaciones || '')}" placeholder="Opcional"></td>
            <td><button type="button" class="btn-remove" data-receta-quitar="${n}">×</button></td>
        </tr>`).join('');
        actualizarBalanceReceta();
    }

    function recetaPayload() {
        if (estado.recetaResultado) estado.recetaResultado.opcion_unidad = $('recetaResultadoUnidad').value;
        return {
            id: estado.recetaEditorId || 0,
            nombre: $('recetaNombre').value.trim(),
            activo: Number($('recetaActivo').value),
            observaciones: $('recetaObservaciones').value.trim(),
            resultado: estado.recetaResultado ? {
                producto_id: Number(estado.recetaResultado.id),
                opcion_unidad: estado.recetaResultado.opcion_unidad,
                cantidad: 1,
            } : {},
            insumos: estado.recetaInsumos.map(i => ({
                producto_id: Number(i.id),
                opcion_unidad: i.opcion_unidad || 'BASE',
                cantidad: i.cantidad ?? '',
                observaciones: i.observaciones || '',
            })),
        };
    }

    async function enviarReceta(p) {
        return apiPost('RECETA_GUARDAR', { payload: JSON.stringify(p) });
    }

    async function guardarReceta() {
        const p = recetaPayload();
        if (p.nombre.length < 3) { mensaje('Captura el nombre de la receta.', 'error'); return; }
        if (!p.resultado.producto_id) { mensaje('Selecciona el producto terminado y su presentación de referencia.', 'error'); return; }
        if (!p.insumos.length || p.insumos.some(i => !(Number(i.cantidad) > 0))) { mensaje('Agrega las materias primas y captura todas sus cantidades por presentación.', 'error'); return; }
        $('btnGuardarReceta').disabled = true;
        try {
            const r = await enviarReceta(p);
            // La administración debe conservar visibles las recetas inactivas.
            // El selector de Nueva producción continúa cargando únicamente recetas activas.
            $('recetaEstado').value = 'TODAS';
            estado.recetaPagina = 1;
            mensaje(r.mensaje, 'success');
            await cargarRecetasSelector();
            cerrarRecetaEditor();
        } catch (e) {
            mensaje(e.message, 'error');
        } finally {
            $('btnGuardarReceta').disabled = false;
        }
    }

    async function verReceta(id) {
        try {
            const r = await apiGet('RECETA_DETALLE', { id });
            estado.recetaDetalleId = Number(id);
            const x = r.receta, ins = r.insumos || [], bal = r.balance || {};
            $('recetaDetalleTitulo').textContent = `${x.codigo} · ${x.nombre}`;
            const usos = Number(x.producciones_vinculadas || 0);
            const puedeEliminar = Boolean(x.puede_eliminar) && usos === 0;
            $('recetaDetalleContenido').innerHTML = `<div class="detail-summary-grid">
                <div><span>Producto terminado</span><strong>${escapar(x.producto)}</strong></div>
                <div><span>Referencia</span><strong>${escapar(x.referencia)}</strong></div>
                <div><span>Versión</span><strong>v${Number(x.version)}</strong></div>
                <div><span>Estado</span><strong>${Number(x.activo) === 1 ? 'ACTIVA' : 'INACTIVA'}</strong></div>
            </div>
            <div class="recipe-rule-note"><strong>Cómo se usa</strong><p>Las cantidades de abajo corresponden a <strong>${escapar(x.referencia)}</strong>. Si produces 100 presentaciones, cada ingrediente se multiplica por 100.</p></div>
            ${usos > 0 ? `<div class="recipe-balance is-neutral"><strong>Historial protegido:</strong> esta receta está vinculada a ${usos} ${usos === 1 ? 'producción' : 'producciones'}. Puede editarse o quedar inactiva, pero no eliminarse porque forma parte de la trazabilidad.</div>` : '<div class="recipe-balance is-neutral">Esta receta todavía no ha sido utilizada en ninguna producción y puede eliminarse definitivamente.</div>'}
            ${x.observaciones ? `<div class="detail-note"><strong>Observaciones</strong><p>${escapar(x.observaciones)}</p></div>` : ''}
            <section class="detail-section"><h3>Ingredientes por ${escapar(x.referencia)}</h3><div class="recipe-detail-ingredients">${ins.map(i => `<div><div><strong>${escapar(i.producto)}</strong><small>${escapar(i.sku)}</small></div><span>${numero(i.cantidad)} ${escapar(i.presentacion || i.unidad || i.simbolo)} <small>= ${numero(i.cantidad_base)} ${escapar(i.simbolo_base)} base</small></span></div>`).join('')}</div></section>
            ${bal.mensaje ? `<div class="recipe-balance ${Number(bal.requiere_confirmacion) === 1 ? 'is-danger' : 'is-neutral'}">${escapar(bal.mensaje)}</div>` : ''}`;
            $('recetaDetalleAcciones').innerHTML = PUEDE_REGISTRAR
                ? `${Number(x.activo) === 1 ? '<button type="button" class="btn-primary" data-receta-accion="usar">Usar en producción</button>' : ''}<button type="button" class="btn-secondary" data-receta-accion="editar">Editar</button><button type="button" class="btn-danger" data-receta-accion="eliminar" ${puedeEliminar ? '' : 'disabled title="No se puede eliminar porque ya tiene producciones vinculadas"'}>Eliminar receta</button>`
                : '';
            $('modalReceta').hidden = false;
            document.body.classList.add('modal-open');
        } catch (e) { mensaje(e.message, 'error'); }
    }

    function cerrarRecetaDetalle() { $('modalReceta').hidden = true; document.body.classList.remove('modal-open'); estado.recetaDetalleId = 0; }

    async function eliminarReceta(id) {
        if (!(Number(id) > 0)) return;
        const confirmar = window.confirm('¿Eliminar definitivamente esta receta? Solo se permite cuando nunca ha sido utilizada en una producción. Esta acción no se puede deshacer.');
        if (!confirmar) return;
        try {
            const r = await apiPost('RECETA_ELIMINAR', { id });
            cerrarRecetaDetalle();
            await cargarRecetasSelector();
            await listarRecetas();
            mensaje(r.mensaje || 'Receta eliminada correctamente.', 'success');
        } catch (e) {
            mensaje(e.message, 'error');
        }
    }

    async function editarReceta(id) {
        try {
            const r = await apiGet('RECETA_DETALLE', { id });
            cerrarRecetaDetalle();
            limpiarRecetaEditor();
            estado.recetaEditorId = Number(id);
            $('recetaEditorTitulo').textContent = `Editar ${r.receta.codigo} · v${r.receta.version}`;
            $('recetaNombre').value = r.receta.nombre || '';
            $('recetaActivo').value = String(Number(r.receta.activo));
            $('recetaObservaciones').value = r.receta.observaciones || '';
            const ro = r.receta, ops = await cargarOpcionesProducto({ id: ro.producto_resultado_id });
            const op = ops.find(o => Number(o.presentacion_id || 0) === Number(ro.presentacion_resultado_id || 0) && Math.abs(Number(o.factor) - Number(ro.factor_resultado_base)) < .000001)
                || ops.find(o => Number(o.unidad_id) === Number(ro.unidad_resultado_id) && Math.abs(Number(o.factor) - Number(ro.factor_resultado_base)) < .000001)
                || ops[0];
            estado.recetaResultado = {
                id: Number(ro.producto_resultado_id), nombre: ro.producto, sku: ro.sku,
                permite_fraccion: Number(ro.permite_fraccion), unidad_base: ro.unidad_base,
                simbolo_base: ro.simbolo_base, codigo_unidad_base: ro.codigo_unidad_base,
                opciones: ops, opcion_unidad: op?.valor || 'BASE',
            };
            estado.recetaInsumos = await Promise.all((r.insumos || []).map(async i => {
                const oo = await cargarOpcionesProducto({ id: i.producto_id });
                const opi = oo.find(o => Number(o.presentacion_id || 0) === Number(i.presentacion_id || 0) && Math.abs(Number(o.factor) - Number(i.factor_a_unidad_base)) < .000001)
                    || oo.find(o => Number(o.unidad_id) === Number(i.unidad_id) && Math.abs(Number(o.factor) - Number(i.factor_a_unidad_base)) < .000001)
                    || oo[0];
                return {
                    id: Number(i.producto_id), nombre: i.producto, sku: i.sku,
                    permite_fraccion: Number(i.permite_fraccion), unidad_base: i.unidad_base,
                    simbolo_base: i.simbolo_base, codigo_unidad_base: i.codigo_unidad_base,
                    opciones: oo, opcion_unidad: opi?.valor || 'BASE', cantidad: Number(i.cantidad), observaciones: i.observaciones || '',
                };
            }));
            renderRecetaResultado();
            renderRecetaInsumos();
            $('vistaRecetas').hidden = true;
            $('vistaRecetaEditor').hidden = false;
        } catch (e) { mensaje(e.message, 'error'); }
    }

    function actualizarSelectorProduccionReceta() {
        const rec = recetaSeleccionadaProduccion();
        const activo = Boolean(rec);
        $('produccionRecetaCantidad').disabled = !activo;
        if (!activo) {
            estado.recetaAplicada = null;
            $('produccionRecetaCantidad').value = '';
            $('produccionRecetaCantidad').step = '1';
            $('produccionRecetaCantidadLabel').textContent = 'Cantidad a producir';
            $('btnAplicarReceta').disabled = true;
            $('produccionRecetaEstado').textContent = 'Captura manual: puedes seleccionar libremente el producto terminado y sus materias primas.';
            return;
        }
        const ref = String(rec.referencia || '1 presentación').replace(/^1\s+/, '');
        $('produccionRecetaCantidadLabel').textContent = `Cantidad a producir (${ref})`;
        $('produccionRecetaCantidad').step = String(rec.tipo_unidad_referencia || '') === 'UNIDAD' ? '1' : '0.000001';
        if (!(Number($('produccionRecetaCantidad').value) > 0)) $('produccionRecetaCantidad').value = '1';
        $('btnAplicarReceta').disabled = !$('almacenProduccion').value || !(Number($('produccionRecetaCantidad').value) > 0);
        $('produccionRecetaEstado').textContent = `La receta está definida para ${rec.referencia}. Captura cuántas presentaciones quieres producir y calcula los ingredientes.`;
    }

    function usarReceta(id) {
        cerrarRecetaDetalle();
        mostrarSeccion('producciones');
        abrirEditorNuevo();
        $('produccionReceta').value = String(id);
        $('produccionRecetaCantidad').value = '1';
        actualizarSelectorProduccionReceta();
    }

    async function aplicarReceta() {
        const rid = Number($('produccionReceta').value || 0);
        const almacen = Number($('almacenProduccion').value || 0);
        const cantidad = Number($('produccionRecetaCantidad').value || 0);
        const rec = recetaSeleccionadaProduccion();
        if (!rid || !almacen || !(cantidad > 0)) { mensaje('Selecciona almacén, receta y cantidad a producir.', 'error'); return; }
        $('btnAplicarReceta').disabled = true;
        try {
            const r = await apiGet('RECETA_ESCALAR', { receta_id: rid, almacen_id: almacen, cantidad });
            const rr = r.resultado;
            const ops = await cargarOpcionesProducto({ id: rr.producto_id });
            const op = ops.find(o => Number(o.presentacion_id || 0) === Number(rr.presentacion_id || 0) && Math.abs(Number(o.factor) - Number(rr.factor_a_unidad_base)) < .000001)
                || ops.find(o => Number(o.unidad_id) === Number(rr.unidad_id) && Math.abs(Number(o.factor) - Number(rr.factor_a_unidad_base)) < .000001)
                || ops[0];
            estado.resultado = {
                id: Number(rr.producto_id), nombre: rr.producto, sku: rr.sku,
                permite_fraccion: Number(rr.permite_fraccion), existencia_fisica: 0, cantidad_reservada: 0, cantidad_disponible: 0,
                simbolo_base: rr.simbolo_base, opciones: ops, opcion_unidad: op?.valor || 'BASE', cantidad: rr.cantidad, observaciones: '',
            };
            estado.insumos = await Promise.all((r.insumos || []).map(async i => {
                const oo = await cargarOpcionesProducto({ id: i.producto_id });
                const oi = oo.find(o => Number(o.presentacion_id || 0) === Number(i.presentacion_id || 0) && Math.abs(Number(o.factor) - Number(i.factor_a_unidad_base)) < .000001)
                    || oo.find(o => Number(o.unidad_id) === Number(i.unidad_id) && Math.abs(Number(o.factor) - Number(i.factor_a_unidad_base)) < .000001)
                    || oo[0];
                return {
                    id: Number(i.producto_id), nombre: i.producto, sku: i.sku,
                    permite_fraccion: Number(i.permite_fraccion), existencia_fisica: Number(i.existencia_fisica),
                    cantidad_reservada: Number(i.cantidad_reservada), cantidad_disponible: Number(i.cantidad_disponible),
                    simbolo_base: i.simbolo_base, opciones: oo, opcion_unidad: oi?.valor || 'BASE', cantidad: Number(i.cantidad), observaciones: i.observaciones || '',
                };
            }));
            estado.recetaAplicada = { id: Number(r.receta.id), version: Number(r.receta.version), nombre: r.receta.nombre };
            renderResultado();
            renderInsumos();
            const ref = rec?.referencia ? String(rec.referencia).replace(/^1\s+/, '') : 'presentaciones';
            if (r.puede_producir) {
                $('produccionRecetaEstado').textContent = `${numero(cantidad)} ${ref}: receta ${r.receta.nombre} v${r.receta.version} calculada. Hay materia prima suficiente según la disponibilidad actual.`;
                mensaje('Ingredientes calculados correctamente.', 'success');
            } else {
                const faltantes = (r.faltantes || []).slice(0, 3).map(f => `${f.producto}: faltan ${numero(f.faltante)} ${f.simbolo}`).join(' · ');
                $('produccionRecetaEstado').textContent = `${numero(cantidad)} ${ref}: faltan ${r.faltantes.length} materia(s) prima(s). ${faltantes}`;
                mensaje('La receta se calculó, pero no hay suficiente materia prima para completar toda la producción.', 'error');
            }
        } catch (e) {
            mensaje(e.message, 'error');
        } finally {
            $('btnAplicarReceta').disabled = false;
        }
    }

    function enlazarEventos() {
        $('tabProducciones').addEventListener('click',()=>mostrarSeccion('producciones'));
        $('tabRecetas').addEventListener('click',()=>mostrarSeccion('recetas'));
        $('recetaBuscar').addEventListener('input',()=>debounce('recetas',()=>{estado.recetaPagina=1;listarRecetas();},300));
        $('recetaEstado').addEventListener('change',()=>{estado.recetaPagina=1;listarRecetas();});
        $('recetaPorPagina').addEventListener('change',()=>{estado.recetaPagina=1;listarRecetas();});
        $('recetaAnterior').addEventListener('click',()=>{if(estado.recetaPagina>1){estado.recetaPagina--;listarRecetas();}});
        $('recetaSiguiente').addEventListener('click',()=>{if(estado.recetaPagina<estado.recetaPaginas){estado.recetaPagina++;listarRecetas();}});
        $('recetaCards').addEventListener('click',e=>{const b=e.target.closest('[data-ver-receta]');if(b)verReceta(Number(b.dataset.verReceta));});
        $('btnCerrarRecetaDetalle').addEventListener('click',cerrarRecetaDetalle);
        $('recetaDetalleAcciones').addEventListener('click',e=>{const a=e.target.closest('[data-receta-accion]');if(!a||a.disabled)return;if(a.dataset.recetaAccion==='usar')usarReceta(estado.recetaDetalleId);if(a.dataset.recetaAccion==='editar')editarReceta(estado.recetaDetalleId);if(a.dataset.recetaAccion==='eliminar')eliminarReceta(estado.recetaDetalleId);});
        if (PUEDE_REGISTRAR) {
            $('btnNuevaReceta').addEventListener('click',abrirRecetaEditor);
            $('btnVolverRecetas').addEventListener('click',cerrarRecetaEditor);
            $('recetaBuscarResultado').addEventListener('input',()=>debounce('rbres',()=>recetaBuscarProductos('resultado')));
            $('recetaBuscarInsumo').addEventListener('input',()=>debounce('rbins',()=>recetaBuscarProductos('insumo')));
            $('recetaSugerenciasResultado').addEventListener('click',e=>{const b=e.target.closest('[data-receta-producto]');if(b)recetaSeleccionarResultado(JSON.parse(b.dataset.recetaProducto));});
            $('recetaSugerenciasInsumo').addEventListener('click',e=>{const b=e.target.closest('[data-receta-producto]');if(b)recetaAgregarInsumo(JSON.parse(b.dataset.recetaProducto));});
            $('recetaCambiarResultado').addEventListener('click',()=>{estado.recetaResultado=null;renderRecetaResultado();});
            $('recetaResultadoUnidad').addEventListener('change',()=>{if(estado.recetaResultado){estado.recetaResultado.opcion_unidad=$('recetaResultadoUnidad').value;renderRecetaResultado();renderRecetaInsumos();}});
            $('recetaTablaInsumos').addEventListener('input',e=>{const tr=e.target.closest('[data-receta-insumo]');if(!tr)return;const i=estado.recetaInsumos[Number(tr.dataset.recetaInsumo)];if(!i)return;if(e.target.dataset.recetaCampo==='cantidad'){i.cantidad=e.target.value;actualizarBalanceReceta();}if(e.target.dataset.recetaCampo==='observaciones')i.observaciones=e.target.value;});
            $('recetaTablaInsumos').addEventListener('change',e=>{const tr=e.target.closest('[data-receta-insumo]');if(!tr)return;const i=estado.recetaInsumos[Number(tr.dataset.recetaInsumo)];if(i&&e.target.dataset.recetaCampo==='unidad'){i.opcion_unidad=e.target.value;actualizarBalanceReceta();}});
            $('recetaTablaInsumos').addEventListener('click',e=>{const b=e.target.closest('[data-receta-quitar]');if(!b)return;estado.recetaInsumos.splice(Number(b.dataset.recetaQuitar),1);renderRecetaInsumos();actualizarBalanceReceta();});
            $('btnGuardarReceta').addEventListener('click',guardarReceta);
            $('produccionReceta').addEventListener('change',()=>{estado.recetaAplicada=null;actualizarSelectorProduccionReceta();});
            $('produccionRecetaCantidad').addEventListener('input',()=>{$('btnAplicarReceta').disabled=!$('produccionReceta').value||!$('almacenProduccion').value||!(Number($('produccionRecetaCantidad').value)>0);});
            $('btnAplicarReceta').addEventListener('click',aplicarReceta);
        }

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
                estado.recetaAplicada = null;
                if ($('produccionReceta').value) $('produccionRecetaEstado').textContent = 'El almacén cambió. Vuelve a aplicar la receta para recalcular disponibilidad.';
                $('btnAplicarReceta').disabled = !$('produccionReceta').value || !(Number($('produccionRecetaCantidad').value) > 0) || !$('almacenProduccion').value;
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
