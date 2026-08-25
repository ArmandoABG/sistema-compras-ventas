<?php

declare(strict_types=1);

if (isset($_GET['alm_api'])) {
    $endpoint = __DIR__ . '/../funciones/almacenes_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/almacenes_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('almacenes.ver', false);

$tituloPagina = 'Almacenes';
$csrfToken = si_token_csrf();
$puedeAdministrar = si_tiene_permiso('almacenes.administrar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_almacenes.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Almacenes | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_almacenes.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content warehouse-page">
            <header class="warehouse-heading">
                <div>
                    <p class="module-eyebrow">INVENTARIO · UBICACIONES · TRAZABILIDAD</p>
                    <h1>Administración de almacenes</h1>
                    <p>Crea y administra las ubicaciones físicas que utiliza el sistema. Cada almacén mantiene existencias independientes y conserva su historial en Kardex.</p>
                </div>
                <?php if ($puedeAdministrar): ?>
                    <button type="button" class="btn-primary" id="btnNuevoAlmacen">+ Nuevo almacén</button>
                <?php endif; ?>
            </header>

            <section class="warehouse-rules" aria-label="Reglas de almacenes">
                <article><strong>Inventario separado</strong><span>Cada almacén conserva físico, reservado y disponible de manera independiente.</span></article>
                <article><strong>Sin borrado físico</strong><span>Los almacenes con historial se conservan; cuando dejan de operar se desactivan.</span></article>
                <article><strong>Desactivación segura</strong><span>Solo se desactiva un almacén vacío, sin reservas ni operaciones pendientes.</span></article>
                <article><strong>Código estable</strong><span>Después del primer movimiento el código queda fijo para mantener la trazabilidad.</span></article>
            </section>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="warehouse-summary" aria-label="Resumen de almacenes">
                <article><span>Total</span><strong id="kpiTotal">—</strong><small>almacenes registrados</small></article>
                <article><span>Activos</span><strong id="kpiActivos">—</strong><small>disponibles en operaciones</small></article>
                <article><span>Inactivos</span><strong id="kpiInactivos">—</strong><small>solo consulta histórica</small></article>
                <article><span>Con inventario</span><strong id="kpiConStock">—</strong><small>físico o reservado</small></article>
            </section>

            <section class="module-card warehouse-card">
                <div class="warehouse-filters">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input type="search" id="buscarAlmacen" maxlength="160" placeholder="Código, nombre o ubicación" autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Estado</span>
                        <select id="filtroEstado">
                            <option value="TODOS">Todos</option>
                            <option value="ACTIVOS">Activos</option>
                            <option value="INACTIVOS">Inactivos</option>
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
                    <table class="module-table warehouse-table">
                        <thead>
                        <tr>
                            <th>Almacén</th>
                            <th>Ubicación</th>
                            <th>Inventario</th>
                            <th>Alertas</th>
                            <th>Última actividad</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                        </thead>
                        <tbody id="tablaAlmacenes"><tr><td colspan="7" class="empty-cell">Cargando...</td></tr></tbody>
                    </table>
                </div>
                <div class="module-pagination" id="paginacion"></div>
            </section>
        </main>
    </div>
</div>

<?php if ($puedeAdministrar): ?>
<div class="modal-backdrop" id="modalAlmacen" hidden>
    <section class="modal-card warehouse-form-modal" role="dialog" aria-modal="true" aria-labelledby="tituloModalAlmacen">
        <header class="modal-header">
            <div>
                <small>ADMINISTRACIÓN</small>
                <h2 id="tituloModalAlmacen">Nuevo almacén</h2>
                <p id="subtituloModalAlmacen">Registra una nueva ubicación física para el inventario.</p>
            </div>
            <button type="button" class="modal-close" data-cerrar="modalAlmacen" aria-label="Cerrar">×</button>
        </header>
        <form id="formAlmacen" class="warehouse-form" autocomplete="off">
            <div id="mensajeModal" class="module-message" hidden></div>
            <input type="hidden" id="almacenId" value="0">

            <div class="warehouse-form-grid">
                <label class="field">
                    <span>Código *</span>
                    <input type="text" id="codigoAlmacen" maxlength="40" required placeholder="ALM-SECUNDARIO" spellcheck="false">
                    <small id="ayudaCodigo">Identificador único. Se convertirá a mayúsculas automáticamente.</small>
                </label>
                <label class="field">
                    <span>Nombre *</span>
                    <input type="text" id="nombreAlmacen" maxlength="120" required placeholder="Almacén secundario">
                    <small>Nombre claro que aparecerá en ventas, compras, inventario y reportes.</small>
                </label>
                <label class="field field--wide">
                    <span>Ubicación</span>
                    <input type="text" id="ubicacionAlmacen" maxlength="255" placeholder="Ej. Nave 2, zona norte o dirección de la sucursal">
                    <small>Dato informativo para identificar físicamente dónde se encuentra la mercancía.</small>
                </label>
            </div>

            <aside class="warehouse-form-note">
                <strong>¿Qué ocurrirá al crearlo?</strong>
                <p>El almacén aparecerá automáticamente como opción en Recepciones, Ventas, Apartados, Producción, Devoluciones, Inventario y Transferencias. No se crea stock ficticio: comienza en cero hasta que exista un movimiento real.</p>
            </aside>

            <footer class="modal-actions">
                <button type="button" class="btn-secondary" data-cerrar="modalAlmacen">Cancelar</button>
                <button type="submit" class="btn-primary" id="btnGuardarAlmacen">Guardar almacén</button>
            </footer>
        </form>
    </section>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="modalDetalle" hidden>
    <section class="modal-card warehouse-detail-modal" role="dialog" aria-modal="true" aria-labelledby="tituloDetalleAlmacen">
        <header class="modal-header detail-header">
            <div>
                <small>FICHA DEL ALMACÉN</small>
                <h2 id="tituloDetalleAlmacen">Almacén</h2>
                <p id="subtituloDetalleAlmacen">—</p>
            </div>
            <button type="button" class="modal-close" data-cerrar="modalDetalle" aria-label="Cerrar">×</button>
        </header>

        <div class="warehouse-detail-body">
            <div id="mensajeDetalle" class="module-message" hidden></div>

            <div class="warehouse-detail-hero">
                <div>
                    <span class="warehouse-code" id="detalleCodigo">—</span>
                    <h3 id="detalleNombre">—</h3>
                    <p id="detalleUbicacion">—</p>
                </div>
                <span id="detalleEstado" class="status-badge">—</span>
            </div>

            <section class="warehouse-detail-stats">
                <article><span>Con existencia</span><strong id="detalleConExistencia">0</strong><small>productos</small></article>
                <article><span>Con reserva</span><strong id="detalleReservados">0</strong><small>productos</small></article>
                <article><span>Críticos</span><strong id="detalleCriticos">0</strong><small>por disponible</small></article>
                <article><span>Reorden</span><strong id="detalleReorden">0</strong><small>requieren atención</small></article>
                <article><span>Movimientos</span><strong id="detalleMovimientos">0</strong><small>Kardex</small></article>
            </section>

            <div class="warehouse-detail-actions">
                <a class="btn-secondary button-link" id="btnAbrirInventario" href="inventario.php?seccion=existencias">Abrir inventario filtrado</a>
                <?php if ($puedeAdministrar): ?>
                    <button type="button" class="btn-secondary" id="btnEditarDesdeDetalle">Editar datos</button>
                    <button type="button" class="btn-state" id="btnEstadoDesdeDetalle">Cambiar estado</button>
                <?php endif; ?>
            </div>

            <div id="bloqueosDetalle" class="warehouse-blockers" hidden></div>

            <section class="warehouse-stock-section">
                <div class="warehouse-stock-heading">
                    <div>
                        <h3>Inventario de este almacén</h3>
                        <p>El disponible es físico menos reservado. Los productos sin movimientos también se muestran en cero para que la consulta sea completa.</p>
                    </div>
                    <div class="warehouse-stock-filters">
                        <label class="field field--search">
                            <span>Buscar producto</span>
                            <input type="search" id="buscarStock" maxlength="140" placeholder="SKU o nombre" autocomplete="off">
                        </label>
                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroStock">
                                <option value="TODOS">Todos</option>
                                <option value="CON_EXISTENCIA">Con existencia</option>
                                <option value="RESERVADO">Con reservado</option>
                                <option value="SIN_DISPONIBLE">Sin disponible</option>
                                <option value="CRITICO">Crítico</option>
                                <option value="REORDEN">Reorden</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="table-wrap warehouse-stock-wrap">
                    <table class="module-table warehouse-stock-table">
                        <thead><tr><th>Producto</th><th>Tipo</th><th class="text-right">Físico</th><th class="text-right">Reservado</th><th class="text-right">Disponible</th><th class="text-right">Mínimo / reorden</th><th>Estado</th></tr></thead>
                        <tbody id="tablaStock"><tr><td colspan="7" class="empty-cell">Selecciona un almacén.</td></tr></tbody>
                    </table>
                </div>
                <div class="module-pagination" id="paginacionStock"></div>
            </section>
        </div>
    </section>
</div>

<script>
(() => {
    'use strict';
    const CONFIG = Object.freeze({
        endpoint: 'almacenes.php?alm_api=1',
        csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        puedeAdministrar: <?= $puedeAdministrar ? 'true' : 'false' ?>,
    });
    const $ = (id) => document.getElementById(id);
    const dom = {
        mensaje: $('mensajePagina'), buscar: $('buscarAlmacen'), estado: $('filtroEstado'), porPagina: $('porPagina'),
        tabla: $('tablaAlmacenes'), paginacion: $('paginacion'),
        kpiTotal: $('kpiTotal'), kpiActivos: $('kpiActivos'), kpiInactivos: $('kpiInactivos'), kpiConStock: $('kpiConStock'),
        btnNuevo: $('btnNuevoAlmacen'), modal: $('modalAlmacen'), form: $('formAlmacen'), mensajeModal: $('mensajeModal'),
        almacenId: $('almacenId'), codigo: $('codigoAlmacen'), nombre: $('nombreAlmacen'), ubicacion: $('ubicacionAlmacen'),
        tituloModal: $('tituloModalAlmacen'), subtituloModal: $('subtituloModalAlmacen'), ayudaCodigo: $('ayudaCodigo'), btnGuardar: $('btnGuardarAlmacen'),
        modalDetalle: $('modalDetalle'), mensajeDetalle: $('mensajeDetalle'), tituloDetalle: $('tituloDetalleAlmacen'), subtituloDetalle: $('subtituloDetalleAlmacen'),
        detalleCodigo: $('detalleCodigo'), detalleNombre: $('detalleNombre'), detalleUbicacion: $('detalleUbicacion'), detalleEstado: $('detalleEstado'),
        detalleConExistencia: $('detalleConExistencia'), detalleReservados: $('detalleReservados'), detalleCriticos: $('detalleCriticos'), detalleReorden: $('detalleReorden'), detalleMovimientos: $('detalleMovimientos'),
        btnAbrirInventario: $('btnAbrirInventario'), btnEditarDetalle: $('btnEditarDesdeDetalle'), btnEstadoDetalle: $('btnEstadoDesdeDetalle'), bloqueosDetalle: $('bloqueosDetalle'),
        buscarStock: $('buscarStock'), filtroStock: $('filtroStock'), tablaStock: $('tablaStock'), paginacionStock: $('paginacionStock'),
    };
    const estado = { pagina: 1, porPagina: 20, totalPaginas: 1, almacenes: new Map(), detalle: null, stockPagina: 1, stockTotalPaginas: 1, request: 0, requestStock: 0 };

    function esc(v) { return String(v ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
    function num(v) { const n = Number(v ?? 0); return Number.isFinite(n) ? new Intl.NumberFormat('es-MX',{maximumFractionDigits:6}).format(n) : '0'; }
    function fecha(v) {
        const t = String(v ?? '').trim(); if (!t) return 'Sin movimientos';
        const d = new Date(t.replace(' ','T')); if (Number.isNaN(d.getTime())) return esc(t);
        return new Intl.DateTimeFormat('es-MX',{dateStyle:'medium',timeStyle:'short'}).format(d);
    }
    function mostrar(el, texto, tipo='error') { if (!el) return; el.textContent=texto; el.className=`module-message module-message--${tipo}`; el.hidden=false; }
    function ocultar(el) { if (!el) return; el.hidden=true; el.textContent=''; }
    function debounce(fn, ms=300) { let t; return (...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);}; }
    function abrir(modal) { if (!modal) return; modal.hidden=false; document.body.classList.add('modal-open'); }
    function cerrar(modal) { if (!modal) return; modal.hidden=true; if (![dom.modal,dom.modalDetalle].some(m=>m && !m.hidden)) document.body.classList.remove('modal-open'); }

    async function get(accion, params={}) {
        const url = new URL(CONFIG.endpoint, location.href); url.searchParams.set('accion',accion);
        Object.entries(params).forEach(([k,v])=>{ if(v!=='' && v!==null && v!==undefined) url.searchParams.set(k,String(v)); });
        const r = await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
        let d; try { d=await r.json(); } catch { throw new Error('El servidor devolvió una respuesta inválida.'); }
        if (!r.ok || !d.success) { if(d?.sesion_expirada && d?.redirect){location.href=d.redirect;return null;} throw new Error(d?.mensaje||'No fue posible completar la consulta.'); }
        return d;
    }
    async function post(accion, params={}) {
        const b = new URLSearchParams({accion,csrf_token:CONFIG.csrfToken}); Object.entries(params).forEach(([k,v])=>b.set(k,String(v ?? '')));
        const r = await fetch(CONFIG.endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:b.toString(),cache:'no-store'});
        let d; try { d=await r.json(); } catch { throw new Error('El servidor devolvió una respuesta inválida.'); }
        if (!r.ok || !d.success) { if(d?.sesion_expirada && d?.redirect){location.href=d.redirect;return null;} throw new Error(d?.mensaje||'No fue posible completar la operación.'); }
        return d;
    }

    function claseStock(a) {
        if (Number(a.criticos)>0 || Number(a.sin_disponible)>0) return 'warehouse-health warehouse-health--danger';
        if (Number(a.reorden)>0) return 'warehouse-health warehouse-health--warning';
        return 'warehouse-health warehouse-health--ok';
    }
    function textoStock(a) {
        const partes=[]; if(Number(a.criticos)>0) partes.push(`${num(a.criticos)} críticos`); if(Number(a.sin_disponible)>0) partes.push(`${num(a.sin_disponible)} sin disponible`); if(Number(a.reorden)>0) partes.push(`${num(a.reorden)} reorden`);
        return partes.length ? partes.join(' · ') : 'Sin alertas registradas';
    }
    function renderTabla(filas) {
        estado.almacenes.clear(); filas.forEach(a=>estado.almacenes.set(Number(a.id),a));
        if (!filas.length) { dom.tabla.innerHTML='<tr><td colspan="7" class="empty-cell">No hay almacenes con esos filtros.</td></tr>'; return; }
        dom.tabla.innerHTML=filas.map(a=>`<tr>
            <td><strong>${esc(a.nombre)}</strong><span class="cell-secondary warehouse-code-inline">${esc(a.codigo)}</span></td>
            <td>${esc(a.ubicacion || 'Sin ubicación especificada')}</td>
            <td><strong>${num(a.productos_con_existencia)}</strong> con existencia<span class="cell-secondary">${num(a.productos_reservados)} con reserva</span></td>
            <td><span class="${claseStock(a)}">${esc(textoStock(a))}</span></td>
            <td>${esc(fecha(a.ultima_actividad))}</td>
            <td><span class="status-badge ${Number(a.activo)===1?'status-badge--active':'status-badge--inactive'}">${Number(a.activo)===1?'Activo':'Inactivo'}</span></td>
            <td class="text-right actions-cell"><button class="table-action" data-ver="${Number(a.id)}">Ver datos</button>${CONFIG.puedeAdministrar?` <button class="table-action" data-editar="${Number(a.id)}">Editar</button>`:''}</td>
        </tr>`).join('');
    }
    function renderPaginacion(p={}) {
        estado.pagina=Number(p.pagina||1); estado.totalPaginas=Number(p.total_paginas||1);
        dom.paginacion.innerHTML=`<span>${num(p.total_registros||0)} almacenes</span><div><button class="btn-secondary btn-small" data-pag="ant" ${estado.pagina<=1?'disabled':''}>Anterior</button><span>Página ${estado.pagina} de ${estado.totalPaginas}</span><button class="btn-secondary btn-small" data-pag="sig" ${estado.pagina>=estado.totalPaginas?'disabled':''}>Siguiente</button></div>`;
    }
    async function cargar() {
        const req=++estado.request; dom.tabla.innerHTML='<tr><td colspan="7" class="empty-cell">Cargando...</td></tr>'; ocultar(dom.mensaje);
        try {
            const d=await get('LISTAR_ALMACENES',{pagina:estado.pagina,por_pagina:estado.porPagina,buscar:dom.buscar?.value||'',estado:dom.estado?.value||'TODOS'}); if(!d||req!==estado.request)return;
            renderTabla(d.almacenes||[]); renderPaginacion(d.paginacion||{});
            const r=d.resumen||{}; dom.kpiTotal.textContent=num(r.total); dom.kpiActivos.textContent=num(r.activos); dom.kpiInactivos.textContent=num(r.inactivos); dom.kpiConStock.textContent=num(r.con_stock);
        } catch(e){ if(req===estado.request){dom.tabla.innerHTML='<tr><td colspan="7" class="empty-cell">No fue posible cargar los almacenes.</td></tr>';mostrar(dom.mensaje,e.message);} }
    }

    function prepararNuevo() {
        if(!CONFIG.puedeAdministrar)return; dom.form.reset(); dom.almacenId.value='0'; dom.codigo.disabled=false; dom.codigo.value='';
        dom.tituloModal.textContent='Nuevo almacén'; dom.subtituloModal.textContent='Registra una nueva ubicación física para el inventario.'; dom.ayudaCodigo.textContent='Identificador único. Se convertirá a mayúsculas automáticamente.'; ocultar(dom.mensajeModal); abrir(dom.modal); setTimeout(()=>dom.codigo.focus(),50);
    }
    async function editar(id, cerrarDetalle=false) {
        if(!CONFIG.puedeAdministrar)return; try {
            const d=await get('DETALLE_ALMACEN',{almacen_id:id}); const a=d.almacen; if(!a)return;
            dom.almacenId.value=String(a.id); dom.codigo.value=a.codigo||''; dom.nombre.value=a.nombre||''; dom.ubicacion.value=a.ubicacion||''; dom.codigo.disabled=Boolean(a.codigo_bloqueado);
            dom.ayudaCodigo.textContent=a.codigo_bloqueado?'Código protegido: el almacén ya tiene historial operativo.':'Identificador único. Se convertirá a mayúsculas automáticamente.';
            dom.tituloModal.textContent='Editar almacén'; dom.subtituloModal.textContent='Actualiza la información operativa sin alterar su historial.'; ocultar(dom.mensajeModal); if(cerrarDetalle) cerrar(dom.modalDetalle); abrir(dom.modal); setTimeout(()=> (dom.codigo.disabled?dom.nombre:dom.codigo).focus(),50);
        } catch(e){mostrar(dom.mensaje,e.message);}
    }
    async function guardar(ev) {
        ev.preventDefault(); if(!CONFIG.puedeAdministrar)return; ocultar(dom.mensajeModal); dom.btnGuardar.disabled=true;
        try {
            const d=await post('GUARDAR_ALMACEN',{almacen_id:dom.almacenId.value,codigo:dom.codigo.value,nombre:dom.nombre.value,ubicacion:dom.ubicacion.value});
            cerrar(dom.modal); mostrar(dom.mensaje,d.mensaje||'Almacén guardado.','success'); await cargar();
        } catch(e){mostrar(dom.mensajeModal,e.message);} finally {dom.btnGuardar.disabled=false;}
    }

    function claseEstadoStock(s){return {NORMAL:'status-badge--active',REORDEN:'status-badge--warning',CRITICO:'status-badge--danger',SIN_DISPONIBLE:'status-badge--reserved',SIN_STOCK:'status-badge--inactive'}[s]||'status-badge--inactive';}
    function textoEstadoStock(s){return {NORMAL:'Normal',REORDEN:'Reorden',CRITICO:'Crítico',SIN_DISPONIBLE:'Sin disponible',SIN_STOCK:'Sin stock'}[s]||s||'—';}
    function renderStock(filas){
        if(!filas.length){dom.tablaStock.innerHTML='<tr><td colspan="7" class="empty-cell">No hay productos con esos filtros.</td></tr>';return;}
        dom.tablaStock.innerHTML=filas.map(r=>`<tr>
            <td><strong>${esc(r.producto)}</strong><span class="cell-secondary">${esc(r.sku)} · ${esc(r.unidad_simbolo||r.unidad||'')}</span></td>
            <td>${esc(r.tipo==='MATERIA_PRIMA'?'Materia prima':'Producto terminado')}${Number(r.producto_activo)!==1?'<span class="cell-secondary">Producto inactivo</span>':''}</td>
            <td class="text-right">${num(r.existencia_fisica)}</td><td class="text-right">${num(r.cantidad_reservada)}</td><td class="text-right"><strong>${num(r.cantidad_disponible)}</strong></td>
            <td class="text-right">${num(r.stock_minimo)} / ${r.punto_reorden===null?'—':num(r.punto_reorden)}</td><td><span class="status-badge ${claseEstadoStock(r.estado_stock)}">${esc(textoEstadoStock(r.estado_stock))}</span></td>
        </tr>`).join('');
    }
    function renderPagStock(p={}){estado.stockPagina=Number(p.pagina||1);estado.stockTotalPaginas=Number(p.total_paginas||1);dom.paginacionStock.innerHTML=`<span>${num(p.total_registros||0)} productos</span><div><button class="btn-secondary btn-small" data-stock-pag="ant" ${estado.stockPagina<=1?'disabled':''}>Anterior</button><span>Página ${estado.stockPagina} de ${estado.stockTotalPaginas}</span><button class="btn-secondary btn-small" data-stock-pag="sig" ${estado.stockPagina>=estado.stockTotalPaginas?'disabled':''}>Siguiente</button></div>`;}
    async function cargarStock(){if(!estado.detalle)return;const req=++estado.requestStock;dom.tablaStock.innerHTML='<tr><td colspan="7" class="empty-cell">Cargando inventario...</td></tr>';try{const d=await get('INVENTARIO_ALMACEN',{almacen_id:estado.detalle.id,pagina:estado.stockPagina,por_pagina:20,buscar:dom.buscarStock.value||'',estado_stock:dom.filtroStock.value||'TODOS'});if(!d||req!==estado.requestStock)return;renderStock(d.registros||[]);renderPagStock(d.paginacion||{});}catch(e){if(req===estado.requestStock){dom.tablaStock.innerHTML='<tr><td colspan="7" class="empty-cell">No fue posible cargar el inventario.</td></tr>';mostrar(dom.mensajeDetalle,e.message);}}}

    async function verDetalle(id){
        ocultar(dom.mensajeDetalle); estado.stockPagina=1; dom.buscarStock.value=''; dom.filtroStock.value='TODOS';
        try{
            const d=await get('DETALLE_ALMACEN',{almacen_id:id}); const a=d.almacen,m=d.metricas||{}; estado.detalle=a;
            dom.tituloDetalle.textContent=a.nombre; dom.subtituloDetalle.textContent=`Creado ${fecha(a.created_at)} · Última actividad: ${fecha(m.ultima_actividad)}`;
            dom.detalleCodigo.textContent=a.codigo; dom.detalleNombre.textContent=a.nombre; dom.detalleUbicacion.textContent=a.ubicacion||'Sin ubicación especificada';
            dom.detalleEstado.textContent=Number(a.activo)===1?'Activo':'Inactivo'; dom.detalleEstado.className=`status-badge ${Number(a.activo)===1?'status-badge--active':'status-badge--inactive'}`;
            dom.detalleConExistencia.textContent=num(m.productos_con_existencia); dom.detalleReservados.textContent=num(m.productos_reservados); dom.detalleCriticos.textContent=num(m.criticos); dom.detalleReorden.textContent=num(m.reorden); dom.detalleMovimientos.textContent=num(m.movimientos);
            dom.btnAbrirInventario.href=`inventario.php?seccion=existencias&almacen_id=${encodeURIComponent(a.id)}`;
            dom.btnAbrirInventario.hidden=Number(a.activo)!==1;
            if(CONFIG.puedeAdministrar){
                dom.btnEditarDetalle.dataset.id=String(a.id);
                dom.btnEstadoDetalle.dataset.id=String(a.id);
                dom.btnEstadoDetalle.dataset.activo=String(a.activo);
                dom.btnEstadoDetalle.textContent=Number(a.activo)===1?'Desactivar almacén':'Activar almacén';
                dom.btnEstadoDetalle.className=Number(a.activo)===1?'btn-state btn-state--danger':'btn-state btn-state--success';
                dom.btnEstadoDetalle.disabled=Number(a.activo)===1 && !Boolean(a.puede_desactivar);
                dom.btnEstadoDetalle.title=dom.btnEstadoDetalle.disabled?'Resuelve primero los bloqueos indicados abajo.':'';
            }
            const bloqueos=Array.isArray(a.bloqueos_desactivacion)?a.bloqueos_desactivacion:[];
            if(Number(a.activo)===1 && bloqueos.length){dom.bloqueosDetalle.innerHTML=`<strong>Antes de desactivarlo:</strong><ul>${bloqueos.map(x=>`<li>${esc(x)}</li>`).join('')}</ul>`;dom.bloqueosDetalle.hidden=false;}else{dom.bloqueosDetalle.hidden=true;dom.bloqueosDetalle.innerHTML='';}
            abrir(dom.modalDetalle); cargarStock();
        }catch(e){mostrar(dom.mensaje,e.message);}
    }
    async function cambiarEstado(id, activoActual){
        if(!CONFIG.puedeAdministrar)return; const activar=Number(activoActual)!==1;
        const texto=activar?'¿Activar este almacén? Volverá a estar disponible en operaciones nuevas.':'¿Desactivar este almacén? Solo podrá hacerse si está vacío, sin reservas y sin operaciones pendientes.';
        if(!confirm(texto))return;
        try{const d=await post('CAMBIAR_ESTADO_ALMACEN',{almacen_id:id,activo:activar?1:0});mostrar(dom.mensaje,d.mensaje||'Estado actualizado.','success');cerrar(dom.modalDetalle);await cargar();}catch(e){mostrar(dom.mensajeDetalle&&!dom.modalDetalle.hidden?dom.mensajeDetalle:dom.mensaje,e.message);}
    }

    dom.btnNuevo?.addEventListener('click',prepararNuevo); dom.form?.addEventListener('submit',guardar);
    dom.codigo?.addEventListener('input',()=>{if(dom.codigo.disabled)return;dom.codigo.value=dom.codigo.value.toUpperCase().replace(/\s+/g,'-').replace(/[^A-Z0-9._-]/g,'');});
    dom.buscar?.addEventListener('input',debounce(()=>{estado.pagina=1;cargar();})); dom.estado?.addEventListener('change',()=>{estado.pagina=1;cargar();}); dom.porPagina?.addEventListener('change',()=>{estado.porPagina=Number(dom.porPagina.value||20);estado.pagina=1;cargar();});
    dom.paginacion?.addEventListener('click',e=>{const b=e.target.closest('[data-pag]');if(!b)return;if(b.dataset.pag==='ant'&&estado.pagina>1)estado.pagina--;if(b.dataset.pag==='sig'&&estado.pagina<estado.totalPaginas)estado.pagina++;cargar();});
    dom.tabla?.addEventListener('click',e=>{const v=e.target.closest('[data-ver]');if(v){verDetalle(Number(v.dataset.ver));return;}const ed=e.target.closest('[data-editar]');if(ed)editar(Number(ed.dataset.editar));});
    dom.btnEditarDetalle?.addEventListener('click',()=>editar(Number(dom.btnEditarDetalle.dataset.id||0),true)); dom.btnEstadoDetalle?.addEventListener('click',()=>cambiarEstado(Number(dom.btnEstadoDetalle.dataset.id||0),Number(dom.btnEstadoDetalle.dataset.activo||0)));
    dom.buscarStock?.addEventListener('input',debounce(()=>{estado.stockPagina=1;cargarStock();})); dom.filtroStock?.addEventListener('change',()=>{estado.stockPagina=1;cargarStock();}); dom.paginacionStock?.addEventListener('click',e=>{const b=e.target.closest('[data-stock-pag]');if(!b)return;if(b.dataset.stockPag==='ant'&&estado.stockPagina>1)estado.stockPagina--;if(b.dataset.stockPag==='sig'&&estado.stockPagina<estado.stockTotalPaginas)estado.stockPagina++;cargarStock();});
    document.querySelectorAll('[data-cerrar]').forEach(b=>b.addEventListener('click',()=>cerrar($(b.dataset.cerrar)))); document.querySelectorAll('.modal-backdrop').forEach(m=>m.addEventListener('mousedown',e=>{if(e.target===m)cerrar(m);})); document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(dom.modal&&!dom.modal.hidden)cerrar(dom.modal);else if(dom.modalDetalle&&!dom.modalDetalle.hidden)cerrar(dom.modalDetalle);}});

    cargar();
})();
</script>
</body>
</html>
