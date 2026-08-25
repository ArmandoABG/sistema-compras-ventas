<?php

declare(strict_types=1);

if (isset($_GET['tra_api'])) {
    $endpoint = __DIR__ . '/../funciones/transferencias_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/transferencias_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('inventario.ver', false);

$tituloPagina = 'Transferencias entre almacenes';
$csrfToken = si_token_csrf();
$puedeTransferir = si_tiene_permiso('inventario.transferir');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_transferencias.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Transferencias | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_transferencias.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content transfer-page">
            <header class="module-heading transfer-heading">
                <div>
                    <p class="module-eyebrow">INVENTARIO · MOVIMIENTO INTERNO · KARDEX</p>
                    <h1>Transferencias entre almacenes</h1>
                    <p>Mueve existencia física entre almacenes sin tocar cantidades reservadas. La salida del origen y la entrada al destino se aplican juntas o no se aplica ninguna.</p>
                </div>
                <?php if ($puedeTransferir): ?>
                    <button type="button" class="btn-primary" id="btnNuevaTransferencia">Nueva transferencia</button>
                <?php endif; ?>
            </header>

            <section class="flow-notes transfer-rules" aria-label="Reglas de transferencias">
                <article><strong>Origen ≠ destino</strong><span>No se permite transferir dentro del mismo almacén.</span></article>
                <article><strong>Disponible real</strong><span>Solo se puede mover existencia física no reservada.</span></article>
                <article><strong>Atómica</strong><span>Salida y entrada quedan en el mismo movimiento TRANSFERENCIA.</span></article>
                <article><strong>Sin borrado</strong><span>Un error se corrige mediante reverso y queda auditado.</span></article>
            </section>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="transfer-summary" aria-label="Resumen">
                <article><span>Almacenes activos</span><strong id="resAlmacenes">—</strong></article>
                <article><span>Transferencias</span><strong id="resTotal">—</strong></article>
                <article><span>Aplicadas</span><strong id="resAplicadas">—</strong></article>
                <article><span>Revertidas</span><strong id="resRevertidas">—</strong></article>
            </section>

            <section class="module-card transfer-card">
                <div class="filters-grid transfer-filters">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input type="search" id="buscarTransferencia" maxlength="160" placeholder="Folio, producto, almacén o motivo" autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Almacén</span>
                        <select id="filtroAlmacen"><option value="0">Todos</option></select>
                    </label>
                    <label class="field">
                        <span>Estado</span>
                        <select id="filtroEstado">
                            <option value="TODAS">Todas</option>
                            <option value="APLICADO">Aplicadas</option>
                            <option value="REVERTIDO">Revertidas</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Mostrar</span>
                        <select id="porPagina">
                            <option value="20">20 por página</option>
                            <option value="50">50 por página</option>
                            <option value="100">100 por página</option>
                        </select>
                    </label>
                </div>

                <div class="table-wrap">
                    <table class="module-table transfer-table">
                        <thead>
                        <tr>
                            <th>Transferencia</th>
                            <th>Origen</th>
                            <th>Destino</th>
                            <th>Contenido</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                            <th>Usuario</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                        </thead>
                        <tbody id="tablaTransferencias"><tr><td colspan="8" class="empty-cell">Cargando...</td></tr></tbody>
                    </table>
                </div>
                <div class="module-pagination" id="paginacion"></div>
            </section>
        </main>
    </div>
</div>

<?php if ($puedeTransferir): ?>
<div class="modal-backdrop" id="modalTransferencia" hidden>
    <section class="modal-card transfer-modal" role="dialog" aria-modal="true" aria-labelledby="tituloTransferencia">
        <header class="modal-header">
            <div>
                <small>TRANSFERENCIA DE INVENTARIO</small>
                <h2 id="tituloTransferencia">Nueva transferencia</h2>
                <p>Selecciona origen, destino y los productos que realmente se moverán.</p>
            </div>
            <button type="button" class="modal-close" data-cerrar="modalTransferencia" aria-label="Cerrar">×</button>
        </header>
        <div class="transfer-modal__body">
            <div id="mensajeTransferencia" class="module-message" hidden></div>

            <form id="formTransferencia" autocomplete="off">
                <div class="transfer-route">
                    <label class="field">
                        <span>Almacén de origen *</span>
                        <select id="almacenOrigen" required></select>
                        <small>La disponibilidad se calcula descontando lo reservado.</small>
                    </label>
                    <div class="route-arrow" aria-hidden="true">→</div>
                    <label class="field">
                        <span>Almacén destino *</span>
                        <select id="almacenDestino" required></select>
                        <small>La existencia se incrementará en este almacén.</small>
                    </label>
                </div>

                <div class="transfer-meta-grid">
                    <label class="field field--wide">
                        <span>Motivo *</span>
                        <input type="text" id="motivoTransferencia" maxlength="255" required placeholder="Ej. Reabastecimiento de almacén secundario">
                    </label>
                    <label class="field field--wide">
                        <span>Observaciones</span>
                        <textarea id="observacionesTransferencia" maxlength="2000" rows="2" placeholder="Información adicional opcional"></textarea>
                    </label>
                </div>

                <section class="product-picker">
                    <div class="product-picker__head">
                        <div><strong>Productos</strong><span>Captura SKU o nombre. La cantidad siempre se registra en la unidad base.</span></div>
                        <label class="field field--search">
                            <span>Buscar producto</span>
                            <input type="search" id="buscarProducto" maxlength="120" placeholder="Primero selecciona el origen" autocomplete="off" disabled>
                        </label>
                    </div>
                    <div id="resultadosProductos" class="product-results" hidden></div>
                </section>

                <div class="table-wrap transfer-lines-wrap">
                    <table class="module-table transfer-lines-table">
                        <thead><tr><th>Producto</th><th>Disponible origen</th><th>Cantidad a transferir</th><th>Unidad</th><th class="text-right">Acción</th></tr></thead>
                        <tbody id="lineasTransferencia"><tr><td colspan="5" class="empty-cell">Agrega al menos un producto.</td></tr></tbody>
                    </table>
                </div>

                <footer class="modal-actions">
                    <button type="button" class="btn-secondary" data-cerrar="modalTransferencia">Cancelar</button>
                    <button type="submit" class="btn-primary" id="btnConfirmarTransferencia">Confirmar transferencia</button>
                </footer>
            </form>
        </div>
    </section>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="modalDetalle" hidden>
    <section class="modal-card transfer-modal transfer-modal--detail" role="dialog" aria-modal="true" aria-labelledby="tituloDetalle">
        <header class="modal-header">
            <div><small>DETALLE</small><h2 id="tituloDetalle">Transferencia</h2><p id="subtituloDetalle"></p></div>
            <button type="button" class="modal-close" data-cerrar="modalDetalle" aria-label="Cerrar">×</button>
        </header>
        <div class="transfer-modal__body">
            <div id="contenidoDetalle"><div class="empty-state">Cargando...</div></div>
        </div>
    </section>
</div>

<?php if ($puedeTransferir): ?>
<div class="modal-backdrop" id="modalReverso" hidden>
    <section class="modal-card modal-card--compact" role="dialog" aria-modal="true" aria-labelledby="tituloReverso">
        <header class="modal-header">
            <div><small>REGULARIZACIÓN</small><h2 id="tituloReverso">Revertir transferencia</h2><p id="textoReverso"></p></div>
            <button type="button" class="modal-close" data-cerrar="modalReverso" aria-label="Cerrar">×</button>
        </header>
        <form id="formReverso" class="transfer-modal__body" autocomplete="off">
            <input type="hidden" id="reversoMovimientoId">
            <div id="mensajeReverso" class="module-message" hidden></div>
            <label class="field">
                <span>Motivo del reverso *</span>
                <textarea id="motivoReverso" maxlength="1000" rows="4" required placeholder="Explica por qué debe revertirse esta transferencia"></textarea>
                <small>No se borra el movimiento original. Se crea un movimiento REVERSO.</small>
            </label>
            <footer class="modal-actions">
                <button type="button" class="btn-secondary" data-cerrar="modalReverso">Cancelar</button>
                <button type="submit" class="btn-danger">Revertir transferencia</button>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>

<script>
window.TRANSFERENCIAS_CONFIG = <?= json_encode([
    'csrf' => $csrfToken,
    'puedeTransferir' => $puedeTransferir,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
    'use strict';

    const CONFIG = window.TRANSFERENCIAS_CONFIG || {};
    const $ = (id) => document.getElementById(id);
    const estado = {
        almacenes: [],
        lineas: [],
        pagina: 1,
        totalPaginas: 1,
        idempotencyKey: '',
        buscarTimer: null,
        listaTimer: null,
        enviando: false,
    };

    function escapar(v) {
        return String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    }
    function numero(v, d = 3) {
        return Number(v || 0).toLocaleString('es-MX', {minimumFractionDigits: 0, maximumFractionDigits: d});
    }
    function fecha(v) {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? escapar(v) : d.toLocaleString('es-MX', {dateStyle:'medium', timeStyle:'short'});
    }
    function nuevaClave() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return 'TRF:' + window.crypto.randomUUID();
        return 'TRF:' + Date.now() + ':' + Math.random().toString(36).slice(2) + ':' + Math.random().toString(36).slice(2);
    }
    function mostrar(id, mensaje, tipo = 'error') {
        const el = $(id); if (!el) return;
        el.textContent = mensaje; el.className = 'module-message module-message--' + tipo; el.hidden = !mensaje;
    }
    function ocultarMensaje(id) { const el = $(id); if (el) el.hidden = true; }
    function abrir(id) { const el = $(id); if (el) { el.hidden = false; document.body.classList.add('modal-open'); } }
    function cerrar(id) { const el = $(id); if (el) { el.hidden = true; if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open'); } }

    async function jsonRespuesta(resp) {
        let data;
        try { data = await resp.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!resp.ok || !data.success) throw new Error(data.mensaje || 'No fue posible completar la operación.');
        return data;
    }
    async function apiGet(accion, params = {}) {
        const q = new URLSearchParams({tra_api:'1', accion, ...params});
        const resp = await fetch('transferencias.php?' + q.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}, cache:'no-store'});
        return jsonRespuesta(resp);
    }
    async function apiPost(accion, params = {}) {
        const body = new URLSearchParams({accion, csrf_token: CONFIG.csrf || '', ...params});
        const resp = await fetch('transferencias.php?tra_api=1', {
            method:'POST',
            headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:body.toString(),
        });
        return jsonRespuesta(resp);
    }

    async function cargarCatalogos() {
        const r = await apiGet('CATALOGOS');
        estado.almacenes = r.almacenes || [];
        const opciones = estado.almacenes.map(a => `<option value="${Number(a.id)}">${escapar(a.codigo)} · ${escapar(a.nombre)}</option>`).join('');
        if ($('filtroAlmacen')) $('filtroAlmacen').innerHTML = '<option value="0">Todos</option>' + opciones;
        if ($('almacenOrigen')) $('almacenOrigen').innerHTML = '<option value="">Selecciona origen</option>' + opciones;
        if ($('almacenDestino')) $('almacenDestino').innerHTML = '<option value="">Selecciona destino</option>' + opciones;

        $('resAlmacenes').textContent = String(estado.almacenes.length);
        $('resTotal').textContent = String(r.resumen?.total || 0);
        $('resAplicadas').textContent = String(r.resumen?.aplicadas || 0);
        $('resRevertidas').textContent = String(r.resumen?.revertidas || 0);

        if (CONFIG.puedeTransferir && estado.almacenes.length < 2) {
            $('btnNuevaTransferencia').disabled = true;
            mostrar('mensajePagina', 'Para registrar transferencias deben existir al menos dos almacenes activos. El módulo ya está preparado; al activar un segundo almacén se habilitará automáticamente.', 'warning');
        }
    }

    function paramsLista() {
        return {
            pagina: estado.pagina,
            por_pagina: $('porPagina')?.value || 20,
            buscar: $('buscarTransferencia')?.value.trim() || '',
            almacen_id: $('filtroAlmacen')?.value || 0,
            estado: $('filtroEstado')?.value || 'TODAS',
        };
    }

    async function cargarLista() {
        const tbody = $('tablaTransferencias');
        tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';
        try {
            const r = await apiGet('LISTAR', paramsLista());
            const filas = r.registros || [];
            if (!filas.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">No hay transferencias con los filtros seleccionados.</td></tr>';
            } else {
                tbody.innerHTML = filas.map(x => {
                    const estadoClase = x.estado === 'APLICADO' ? 'status-badge status-badge--ok' : 'status-badge status-badge--muted';
                    const btnRevertir = CONFIG.puedeTransferir && x.estado === 'APLICADO'
                        ? `<button type="button" class="table-action table-action--danger" data-revertir="${Number(x.id)}" data-folio="${escapar(x.folio)}">Revertir</button>` : '';
                    return `<tr>
                        <td><strong>${escapar(x.folio)}</strong><span class="cell-secondary">${fecha(x.fecha_movimiento)}</span></td>
                        <td><strong>${escapar(x.origen || '—')}</strong><span class="cell-secondary">${escapar(x.origen_codigo || '')}</span></td>
                        <td><strong>${escapar(x.destino || '—')}</strong><span class="cell-secondary">${escapar(x.destino_codigo || '')}</span></td>
                        <td><strong>${Number(x.productos || 0)} producto(s)</strong><span class="cell-secondary">${Number(x.renglones || 0)} renglones de Kardex</span></td>
                        <td>${escapar(x.motivo || '—')}</td>
                        <td><span class="${estadoClase}">${escapar(x.estado)}</span></td>
                        <td>${escapar(x.usuario || '—')}</td>
                        <td class="actions-cell text-right"><button type="button" class="table-action" data-detalle="${Number(x.id)}">Ver</button>${btnRevertir}</td>
                    </tr>`;
                }).join('');
            }
            renderPaginacion(r.paginacion || {});
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">No fue posible cargar las transferencias.</td></tr>';
            mostrar('mensajePagina', e.message);
        }
    }

    function renderPaginacion(p) {
        estado.pagina = Number(p.pagina || 1);
        estado.totalPaginas = Math.max(1, Number(p.total_paginas || 1));
        const el = $('paginacion');
        el.innerHTML = `<span>${Number(p.total || 0)} registro(s) · Página ${estado.pagina} de ${estado.totalPaginas}</span>
            <div><button type="button" class="btn-secondary btn-small" id="pagAnterior" ${estado.pagina <= 1 ? 'disabled' : ''}>Anterior</button>
            <button type="button" class="btn-secondary btn-small" id="pagSiguiente" ${estado.pagina >= estado.totalPaginas ? 'disabled' : ''}>Siguiente</button></div>`;
        $('pagAnterior')?.addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina--; cargarLista(); } });
        $('pagSiguiente')?.addEventListener('click', () => { if (estado.pagina < estado.totalPaginas) { estado.pagina++; cargarLista(); } });
    }

    function abrirNueva() {
        if (estado.almacenes.length < 2) return;
        estado.lineas = [];
        estado.idempotencyKey = nuevaClave();
        estado.enviando = false;
        $('formTransferencia').reset();
        $('almacenOrigen').value = String(estado.almacenes[0]?.id || '');
        $('almacenDestino').value = String(estado.almacenes[1]?.id || '');
        $('buscarProducto').disabled = !$('almacenOrigen').value;
        $('buscarProducto').placeholder = $('almacenOrigen').value ? 'SKU o nombre' : 'Primero selecciona el origen';
        $('resultadosProductos').hidden = true;
        renderLineas();
        ocultarMensaje('mensajeTransferencia');
        abrir('modalTransferencia');
    }

    function renderLineas() {
        const tbody = $('lineasTransferencia');
        if (!tbody) return;
        if (!estado.lineas.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty-cell">Agrega al menos un producto.</td></tr>';
            return;
        }
        tbody.innerHTML = estado.lineas.map((l, i) => `<tr>
            <td><strong>${escapar(l.nombre)}</strong><span class="cell-secondary">${escapar(l.sku)}</span></td>
            <td><strong>${numero(l.cantidad_disponible, 6)}</strong><span class="cell-secondary">Físico ${numero(l.existencia_fisica, 6)} · Reservado ${numero(l.cantidad_reservada, 6)}</span></td>
            <td><input class="line-qty" type="number" min="${l.permite_fraccion ? '0.000001' : '1'}" step="${l.permite_fraccion ? '0.000001' : '1'}" max="${Number(l.cantidad_disponible)}" value="${escapar(l.cantidad)}" data-cantidad="${i}"></td>
            <td>${escapar(l.unidad_simbolo || l.unidad_base)}</td>
            <td class="text-right"><button type="button" class="table-action table-action--danger" data-quitar="${i}">Quitar</button></td>
        </tr>`).join('');
    }

    async function buscarProductos() {
        const q = $('buscarProducto').value.trim();
        const origen = Number($('almacenOrigen').value || 0);
        const box = $('resultadosProductos');
        if (!origen || q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
        try {
            const r = await apiGet('BUSCAR_PRODUCTOS', {almacen_id: origen, q});
            const productos = r.productos || [];
            box.hidden = false;
            if (!productos.length) {
                box.innerHTML = '<div class="empty-state">No se encontraron productos.</div>';
                return;
            }
            box.innerHTML = productos.map(p => {
                const agregado = estado.lineas.some(l => Number(l.id) === Number(p.id));
                const disponible = Number(p.cantidad_disponible || 0);
                const disabled = agregado || disponible <= 0;
                return `<button type="button" class="product-result" data-producto='${escapar(JSON.stringify(p))}' ${disabled ? 'disabled' : ''}>
                    <span><strong>${escapar(p.nombre)}</strong><small>${escapar(p.sku)} · ${escapar(p.unidad_simbolo || p.unidad_base)}</small></span>
                    <span><b>${numero(disponible, 6)}</b><small>disponible</small></span>
                </button>`;
            }).join('');
        } catch (e) {
            box.hidden = false; box.innerHTML = '<div class="empty-state">' + escapar(e.message) + '</div>';
        }
    }

    function agregarProducto(p) {
        if (!p || !p.id || estado.lineas.some(l => Number(l.id) === Number(p.id))) return;
        const disponible = Number(p.cantidad_disponible || 0);
        if (disponible <= 0) return;
        estado.lineas.push({...p, cantidad: p.permite_fraccion ? Math.min(1, disponible) : 1});
        renderLineas();
        $('buscarProducto').value = '';
        $('resultadosProductos').hidden = true;
    }

    function validarLineas() {
        if (!estado.lineas.length) throw new Error('Agrega al menos un producto.');
        return estado.lineas.map(l => {
            const cantidad = Number(l.cantidad);
            if (!Number.isFinite(cantidad) || cantidad <= 0) throw new Error('Todas las cantidades deben ser mayores que cero.');
            if (!Number(l.permite_fraccion) && Math.abs(cantidad - Math.round(cantidad)) > 0.000001) throw new Error(`${l.nombre} no permite cantidades fraccionadas.`);
            if (cantidad > Number(l.cantidad_disponible) + 0.000001) throw new Error(`La cantidad de ${l.nombre} supera la disponibilidad mostrada.`);
            return {producto_id:Number(l.id), cantidad:Number(cantidad.toFixed(6))};
        });
    }

    async function registrar(event) {
        event.preventDefault();
        if (estado.enviando) return;
        ocultarMensaje('mensajeTransferencia');
        const origen = Number($('almacenOrigen').value || 0);
        const destino = Number($('almacenDestino').value || 0);
        const motivo = $('motivoTransferencia').value.trim();
        try {
            if (!origen || !destino) throw new Error('Selecciona almacén de origen y destino.');
            if (origen === destino) throw new Error('El almacén de origen y destino deben ser diferentes.');
            if (motivo.length < 3) throw new Error('Captura un motivo de al menos 3 caracteres.');
            const detalles = validarLineas();
            estado.enviando = true;
            $('btnConfirmarTransferencia').disabled = true;
            const r = await apiPost('REGISTRAR', {
                origen_id: origen,
                destino_id: destino,
                motivo,
                observaciones: $('observacionesTransferencia').value.trim(),
                idempotency_key: estado.idempotencyKey,
                detalles: JSON.stringify(detalles),
            });
            cerrar('modalTransferencia');
            mostrar('mensajePagina', `${r.mensaje} Folio: ${r.folio || ''}`, 'success');
            await cargarCatalogos();
            estado.pagina = 1;
            await cargarLista();
        } catch (e) {
            mostrar('mensajeTransferencia', e.message);
        } finally {
            estado.enviando = false;
            $('btnConfirmarTransferencia').disabled = false;
        }
    }

    async function verDetalle(id) {
        abrir('modalDetalle');
        $('contenidoDetalle').innerHTML = '<div class="empty-state">Cargando...</div>';
        try {
            const r = await apiGet('DETALLE', {id});
            const t = r.transferencia || {};
            $('tituloDetalle').textContent = t.folio || 'Transferencia';
            $('subtituloDetalle').textContent = `${fecha(t.fecha_movimiento)} · ${t.estado || ''}`;
            const productos = r.productos || [];
            $('contenidoDetalle').innerHTML = `
                <section class="detail-route"><article><span>Origen</span><strong>${escapar(r.origen?.nombre || '—')}</strong><small>${escapar(r.origen?.codigo || '')}</small></article><div>→</div><article><span>Destino</span><strong>${escapar(r.destino?.nombre || '—')}</strong><small>${escapar(r.destino?.codigo || '')}</small></article></section>
                <section class="detail-meta"><div><span>Motivo</span><strong>${escapar(t.motivo || '—')}</strong></div><div><span>Usuario</span><strong>${escapar(t.usuario || '—')}</strong></div><div class="detail-meta--wide"><span>Observaciones</span><strong>${escapar(t.observaciones || '—')}</strong></div></section>
                <div class="table-wrap"><table class="module-table"><thead><tr><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th></tr></thead><tbody>${productos.map(p => `<tr><td><strong>${escapar(p.producto)}</strong><span class="cell-secondary">${escapar(p.sku)}</span></td><td>${numero(p.cantidad, 6)} ${escapar(p.unidad_simbolo || '')}</td><td>${numero(p.origen_antes, 6)} → <strong>${numero(p.origen_despues, 6)}</strong></td><td>${numero(p.destino_antes, 6)} → <strong>${numero(p.destino_despues, 6)}</strong></td></tr>`).join('')}</tbody></table></div>`;
        } catch (e) {
            $('contenidoDetalle').innerHTML = '<div class="empty-state">' + escapar(e.message) + '</div>';
        }
    }

    function abrirReverso(id, folio) {
        $('reversoMovimientoId').value = String(id);
        $('motivoReverso').value = '';
        $('textoReverso').textContent = `Se creará un movimiento inverso para ${folio}. No se eliminará el historial.`;
        ocultarMensaje('mensajeReverso');
        abrir('modalReverso');
    }

    async function revertir(event) {
        event.preventDefault();
        const id = Number($('reversoMovimientoId').value || 0);
        const motivo = $('motivoReverso').value.trim();
        if (!id || motivo.length < 5) return mostrar('mensajeReverso', 'Captura un motivo de al menos 5 caracteres.');
        const boton = event.submitter;
        if (boton) boton.disabled = true;
        try {
            const r = await apiPost('REVERTIR', {movimiento_id:id, motivo});
            cerrar('modalReverso');
            mostrar('mensajePagina', `${r.mensaje}${r.folio ? ' Folio: ' + r.folio : ''}`, 'success');
            await cargarCatalogos();
            await cargarLista();
        } catch (e) {
            mostrar('mensajeReverso', e.message);
        } finally { if (boton) boton.disabled = false; }
    }

    function eventos() {
        $('btnNuevaTransferencia')?.addEventListener('click', abrirNueva);
        document.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', () => cerrar(b.dataset.cerrar)));
        document.querySelectorAll('.modal-backdrop').forEach(m => m.addEventListener('click', e => { if (e.target === m) cerrar(m.id); }));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(m => cerrar(m.id)); });

        ['filtroAlmacen','filtroEstado','porPagina'].forEach(id => $(id)?.addEventListener('change', () => { estado.pagina = 1; cargarLista(); }));
        $('buscarTransferencia')?.addEventListener('input', () => { clearTimeout(estado.listaTimer); estado.listaTimer = setTimeout(() => { estado.pagina = 1; cargarLista(); }, 280); });

        $('almacenOrigen')?.addEventListener('change', () => {
            estado.lineas = [];
            renderLineas();
            $('buscarProducto').value = '';
            $('resultadosProductos').hidden = true;
            $('buscarProducto').disabled = !$('almacenOrigen').value;
            $('buscarProducto').placeholder = $('almacenOrigen').value ? 'SKU o nombre' : 'Primero selecciona el origen';
            if ($('almacenDestino').value === $('almacenOrigen').value) {
                const otro = estado.almacenes.find(a => String(a.id) !== $('almacenOrigen').value);
                $('almacenDestino').value = otro ? String(otro.id) : '';
            }
        });
        $('almacenDestino')?.addEventListener('change', () => {
            if ($('almacenDestino').value && $('almacenDestino').value === $('almacenOrigen').value) {
                mostrar('mensajeTransferencia', 'El almacén de destino debe ser diferente al origen.');
            } else ocultarMensaje('mensajeTransferencia');
        });
        $('buscarProducto')?.addEventListener('input', () => { clearTimeout(estado.buscarTimer); estado.buscarTimer = setTimeout(buscarProductos, 260); });
        $('resultadosProductos')?.addEventListener('click', e => {
            const btn = e.target.closest('[data-producto]'); if (!btn || btn.disabled) return;
            try { agregarProducto(JSON.parse(btn.dataset.producto)); } catch (_) {}
        });
        $('lineasTransferencia')?.addEventListener('input', e => {
            const input = e.target.closest('[data-cantidad]'); if (!input) return;
            const i = Number(input.dataset.cantidad); if (!estado.lineas[i]) return;
            estado.lineas[i].cantidad = input.value;
        });
        $('lineasTransferencia')?.addEventListener('click', e => {
            const btn = e.target.closest('[data-quitar]'); if (!btn) return;
            estado.lineas.splice(Number(btn.dataset.quitar), 1); renderLineas();
        });
        $('formTransferencia')?.addEventListener('submit', registrar);

        $('tablaTransferencias').addEventListener('click', e => {
            const detalle = e.target.closest('[data-detalle]');
            if (detalle) { verDetalle(Number(detalle.dataset.detalle)); return; }
            const rev = e.target.closest('[data-revertir]');
            if (rev) abrirReverso(Number(rev.dataset.revertir), rev.dataset.folio || 'esta transferencia');
        });
        $('formReverso')?.addEventListener('submit', revertir);
    }

    async function iniciar() {
        eventos();
        try {
            await cargarCatalogos();
            await cargarLista();
        } catch (e) {
            mostrar('mensajePagina', e.message);
            $('tablaTransferencias').innerHTML = '<tr><td colspan="8" class="empty-cell">No fue posible iniciar el módulo.</td></tr>';
        }
    }

    iniciar();
})();
</script>
</body>
</html>
