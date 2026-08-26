<?php

declare(strict_types=1);

if (isset($_GET['aud_api'])) {
    $endpoint = __DIR__ . '/../funciones/auditoria_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/auditoria_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('auditoria.ver', false);

$tituloPagina = 'Auditoría';
$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_auditoria.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Auditoría | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_auditoria.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content audit-page">
            <header class="audit-heading">
                <div>
                    <p class="module-eyebrow">SEGURIDAD · TRAZABILIDAD · SOLO LECTURA</p>
                    <h1>Auditoría del sistema</h1>
                    <p>Consulta quién hizo qué, cuándo y sobre qué registro. Esta pantalla no modifica ni elimina eventos de auditoría.</p>
                </div>
                <div class="audit-readonly-badge" title="Este módulo no tiene acciones de escritura">
                    <span aria-hidden="true">◉</span>
                    Solo lectura
                </div>
            </header>

            <section class="audit-summary" aria-label="Resumen de auditoría">
                <article><span>Registros</span><strong id="kpiTotal">—</strong><small>histórico disponible</small></article>
                <article><span>Hoy</span><strong id="kpiHoy">—</strong><small>eventos registrados</small></article>
                <article><span>7 días</span><strong id="kpiSemana">—</strong><small>actividad reciente</small></article>
                <article><span>Usuarios activos</span><strong id="kpiUsuarios">—</strong><small>con actividad en 30 días</small></article>
            </section>

            <section class="module-card audit-filters-card">
                <div class="audit-filter-head">
                    <div>
                        <h2>Buscar actividad</h2>
                        <p>Combina filtros para localizar una operación concreta sin alterar el historial.</p>
                    </div>
                    <button type="button" class="btn-secondary btn-small" id="btnLimpiar">Limpiar filtros</button>
                </div>

                <div class="audit-filters">
                    <label class="field audit-filter-search">
                        <span>Buscar</span>
                        <input type="search" id="fBuscar" maxlength="160" placeholder="Descripción, usuario, acción, módulo, entidad o IP" autocomplete="off">
                    </label>
                    <label class="field">
                        <span>Usuario</span>
                        <select id="fUsuario"><option value="0">Todos</option><option value="-1">Sistema</option></select>
                    </label>
                    <label class="field">
                        <span>Módulo</span>
                        <select id="fModulo"><option value="">Todos</option></select>
                    </label>
                    <label class="field">
                        <span>Acción</span>
                        <select id="fAccion"><option value="">Todas</option></select>
                    </label>
                    <label class="field">
                        <span>Entidad</span>
                        <select id="fEntidad"><option value="">Todas</option></select>
                    </label>
                    <label class="field">
                        <span>Registro ID</span>
                        <input type="number" id="fEntidadId" min="1" step="1" placeholder="Ej. 25">
                    </label>
                    <label class="field">
                        <span>IP exacta</span>
                        <input type="text" id="fIp" maxlength="45" placeholder="Ej. 192.168.1.20" spellcheck="false">
                    </label>
                    <label class="field">
                        <span>Desde</span>
                        <input type="date" id="fDesde">
                    </label>
                    <label class="field">
                        <span>Hasta</span>
                        <input type="date" id="fHasta">
                    </label>
                    <label class="field audit-filter-page-size">
                        <span>Mostrar</span>
                        <select id="fPorPagina">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                </div>
            </section>

            <div id="mensajePagina" class="module-message" role="status" aria-live="polite" hidden></div>

            <section class="module-card audit-table-card">
                <div class="audit-table-head">
                    <div>
                        <h2>Historial</h2>
                        <p id="textoResultados" aria-live="polite">Cargando actividad...</p>
                    </div>
                    <span class="audit-last" id="ultimaActividad">Última actividad: —</span>
                </div>

                <div class="table-wrap">
                    <table class="module-table audit-table">
                        <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Módulo / acción</th>
                            <th>Registro</th>
                            <th>Descripción</th>
                            <th>IP</th>
                            <th class="text-right">Detalle</th>
                        </tr>
                        </thead>
                        <tbody id="tablaAuditoria"><tr><td colspan="7" class="empty-cell">Cargando...</td></tr></tbody>
                    </table>
                </div>
                <div class="module-pagination" id="paginacion"></div>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop audit-detail-backdrop" id="modalDetalle" hidden>
    <section class="modal-card audit-detail-modal" role="dialog" aria-modal="true" aria-labelledby="detalleTitulo">
        <header class="modal-header audit-detail-header">
            <div>
                <small>EVENTO DE AUDITORÍA <span id="detalleId">#—</span></small>
                <h2 id="detalleTitulo">Detalle</h2>
                <p id="detalleSubtitulo">—</p>
            </div>
            <button type="button" class="modal-close" id="cerrarDetalle" aria-label="Cerrar">×</button>
        </header>
        <div class="audit-detail-body">
            <div id="mensajeDetalle" class="module-message" role="status" aria-live="polite" hidden></div>

            <section class="audit-detail-meta" id="detalleMeta"></section>

            <section class="audit-description-box">
                <span>Descripción registrada</span>
                <p id="detalleDescripcion">—</p>
            </section>

            <nav class="audit-tabs" aria-label="Detalle del evento">
                <button type="button" class="is-active" data-audit-tab="cambios">Cambios</button>
                <button type="button" data-audit-tab="anteriores">Valores anteriores</button>
                <button type="button" data-audit-tab="nuevos">Valores nuevos</button>
                <button type="button" data-audit-tab="tecnico">Técnico</button>
            </nav>

            <section class="audit-tab-panel is-active" data-audit-panel="cambios">
                <div id="detalleCambios" class="audit-changes"></div>
            </section>
            <section class="audit-tab-panel" data-audit-panel="anteriores">
                <pre id="detalleAnteriores" class="audit-json"></pre>
            </section>
            <section class="audit-tab-panel" data-audit-panel="nuevos">
                <pre id="detalleNuevos" class="audit-json"></pre>
            </section>
            <section class="audit-tab-panel" data-audit-panel="tecnico">
                <dl class="audit-technical" id="detalleTecnico"></dl>
            </section>
        </div>
        <footer class="modal-actions audit-detail-actions">
            <small>Los valores sensibles se ocultan automáticamente.</small>
            <button type="button" class="btn-secondary" id="cerrarDetalleFooter">Cerrar</button>
        </footer>
    </section>
</div>

<script>
(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const estado = { pagina: 1, totalPaginas: 1, request: 0, timer: null };
    const idsFiltros = ['fBuscar','fUsuario','fModulo','fAccion','fEntidad','fEntidadId','fIp','fDesde','fHasta','fPorPagina'];

    const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const num = (v) => new Intl.NumberFormat('es-MX').format(Number(v || 0));
    const fecha = (v) => {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? esc(v) : new Intl.DateTimeFormat('es-MX', {dateStyle:'medium', timeStyle:'short'}).format(d);
    };
    const etiqueta = (v) => String(v || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    const mostrar = (id, texto, tipo = 'error') => {
        const el = $(id); if (!el) return;
        if (!texto) { el.hidden = true; el.textContent = ''; el.className = 'module-message'; return; }
        el.hidden = false; el.textContent = texto; el.className = 'module-message ' + (tipo === 'ok' ? 'success' : tipo === 'warning' ? 'warning' : 'error');
    };

    async function api(params) {
        const q = new URLSearchParams({aud_api:'1', ...params});
        const r = await fetch('auditoria.php?' + q.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}, cache:'no-store'});
        const data = await r.json().catch(() => null);
        if (!r.ok || !data?.success) throw new Error(data?.mensaje || 'No fue posible consultar la auditoría.');
        return data;
    }

    function optionSelect(id, valores, valueFn = (x) => x, labelFn = (x) => x) {
        const select = $(id); if (!select) return;
        const base = select.innerHTML;
        select.innerHTML = base + valores.map((x) => `<option value="${esc(valueFn(x))}">${esc(labelFn(x))}</option>`).join('');
    }

    async function cargarInicial() {
        try {
            const d = await api({accion:'INICIAL'});
            const r = d.resumen || {};
            $('kpiTotal').textContent = num(r.total);
            $('kpiHoy').textContent = num(r.hoy);
            $('kpiSemana').textContent = num(r.ultimos_7_dias);
            $('kpiUsuarios').textContent = num(r.usuarios_30_dias);
            $('ultimaActividad').textContent = 'Última actividad: ' + fecha(r.ultima_actividad);

            optionSelect('fUsuario', d.usuarios || [], (u) => u.id, (u) => `${u.nombre} · @${u.usuario}${u.activo ? '' : ' · inactivo'}`);
            optionSelect('fModulo', d.modulos || []);
            optionSelect('fAccion', d.acciones || [], (x) => x, etiqueta);
            optionSelect('fEntidad', d.entidades || []);
            await cargarLista();
        } catch (e) {
            mostrar('mensajePagina', e.message);
            $('tablaAuditoria').innerHTML = '<tr><td colspan="7" class="empty-cell">No fue posible iniciar Auditoría.</td></tr>';
        }
    }

    function filtros() {
        return {
            buscar: $('fBuscar').value.trim(),
            usuario_id: $('fUsuario').value,
            modulo: $('fModulo').value,
            accion_filtro: $('fAccion').value,
            entidad: $('fEntidad').value,
            entidad_id: $('fEntidadId').value,
            ip: $('fIp').value.trim(),
            fecha_desde: $('fDesde').value,
            fecha_hasta: $('fHasta').value,
            por_pagina: $('fPorPagina').value,
            pagina: estado.pagina,
        };
    }

    function renderTabla(filas) {
        const tbody = $('tablaAuditoria');
        if (!filas.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-cell">No hay registros que coincidan con los filtros.</td></tr>';
            return;
        }
        tbody.innerHTML = filas.map((r) => {
            const entidad = r.entidad_tabla ? `<strong>${esc(r.entidad_tabla)}</strong>${r.entidad_id ? `<small>#${num(r.entidad_id)}</small>` : ''}` : '<span class="audit-muted">Sin entidad</span>';
            const cambios = r.tiene_cambios ? '<span class="audit-change-dot" title="Tiene valores anteriores o nuevos">Cambios</span>' : '';
            return `<tr>
                <td data-label="Fecha"><time>${fecha(r.fecha_hora)}</time><small class="audit-row-id">Evento #${num(r.id)}</small></td>
                <td data-label="Usuario"><strong>${esc(r.usuario_nombre)}</strong><small>@${esc(r.usuario_login)}</small></td>
                <td data-label="Módulo / acción"><span class="audit-module">${esc(r.modulo)}</span><strong class="audit-action">${esc(etiqueta(r.accion))}</strong>${cambios}</td>
                <td data-label="Registro" class="audit-entity">${entidad}</td>
                <td data-label="Descripción"><p class="audit-description">${esc(r.descripcion || 'Sin descripción')}</p></td>
                <td data-label="IP"><code>${esc(r.ip || '—')}</code></td>
                <td data-label="Detalle" class="text-right"><button type="button" class="btn-secondary btn-small" data-detalle="${Number(r.id)}">Ver</button></td>
            </tr>`;
        }).join('');
    }

    function renderPaginacion(p) {
        estado.totalPaginas = Number(p.total_paginas || 1);
        $('textoResultados').textContent = `${num(p.total_registros)} registro${Number(p.total_registros) === 1 ? '' : 's'} encontrado${Number(p.total_registros) === 1 ? '' : 's'}.`;
        $('paginacion').innerHTML = `<span>${num(p.total_registros)} registros</span><div><button type="button" class="btn-secondary btn-small" data-pag="ant" ${estado.pagina <= 1 ? 'disabled' : ''}>Anterior</button><span>Página ${num(estado.pagina)} de ${num(estado.totalPaginas)}</span><button type="button" class="btn-secondary btn-small" data-pag="sig" ${estado.pagina >= estado.totalPaginas ? 'disabled' : ''}>Siguiente</button></div>`;
    }

    async function cargarLista() {
        const req = ++estado.request;
        $('tablaAuditoria').innerHTML = '<tr><td colspan="7" class="empty-cell">Cargando auditoría...</td></tr>';
        mostrar('mensajePagina', '');
        try {
            const d = await api({accion:'LISTAR', ...filtros()});
            if (req !== estado.request) return;
            renderTabla(d.registros || []);
            renderPaginacion(d.paginacion || {});
        } catch (e) {
            if (req !== estado.request) return;
            mostrar('mensajePagina', e.message);
            $('tablaAuditoria').innerHTML = '<tr><td colspan="7" class="empty-cell">No fue posible cargar el historial.</td></tr>';
        }
    }

    function valorHtml(v) {
        if (v === null || v === undefined) return '<em>Sin valor</em>';
        return `<code>${esc(v)}</code>`;
    }

    function renderCambios(cambios) {
        const el = $('detalleCambios');
        if (!Array.isArray(cambios) || !cambios.length) {
            el.innerHTML = '<div class="audit-empty-detail"><strong>Sin comparación disponible</strong><p>Este evento no registró valores anteriores/nuevos diferentes. La descripción y los datos técnicos siguen siendo parte del historial.</p></div>';
            return;
        }
        el.innerHTML = `<div class="audit-change-table-head"><span>Campo</span><span>Anterior</span><span>Nuevo</span></div>` + cambios.map((c) => `<div class="audit-change-row"><strong>${esc(c.campo)}</strong><div>${valorHtml(c.anterior)}</div><div>${valorHtml(c.nuevo)}</div></div>`).join('');
    }

    function pretty(v) {
        if (v === null || v === undefined) return 'No se registraron valores.';
        try { return JSON.stringify(v, null, 2); } catch (_) { return String(v); }
    }

    async function abrirDetalle(id) {
        $('modalDetalle').hidden = false;
        document.body.classList.add('modal-open');
        mostrar('mensajeDetalle', '');
        $('detalleTitulo').textContent = 'Cargando...';
        $('detalleMeta').innerHTML = '';
        $('detalleCambios').innerHTML = '<div class="empty-cell">Cargando...</div>';
        try {
            const d = await api({accion:'DETALLE', id});
            const r = d.registro;
            $('detalleId').textContent = '#' + num(r.id);
            $('detalleTitulo').textContent = etiqueta(r.accion);
            $('detalleSubtitulo').textContent = `${r.modulo} · ${fecha(r.fecha_hora)}`;
            $('detalleDescripcion').textContent = r.descripcion || 'Sin descripción registrada.';
            $('detalleMeta').innerHTML = `
                <article><span>Usuario</span><strong>${esc(r.usuario_nombre)}</strong><small>@${esc(r.usuario_login)}</small></article>
                <article><span>Módulo</span><strong>${esc(r.modulo)}</strong><small>${esc(r.accion)}</small></article>
                <article><span>Entidad</span><strong>${esc(r.entidad_tabla || 'Sin entidad')}</strong><small>${r.entidad_id ? '#' + num(r.entidad_id) : 'Sin ID asociado'}</small></article>
                <article><span>Origen</span><strong>${esc(r.ip || 'Sin IP')}</strong><small>${fecha(r.fecha_hora)}</small></article>`;
            renderCambios(r.cambios || []);
            $('detalleAnteriores').textContent = pretty(r.datos_anteriores);
            $('detalleNuevos').textContent = pretty(r.datos_nuevos);
            $('detalleTecnico').innerHTML = `<div><dt>ID auditoría</dt><dd>#${num(r.id)}</dd></div><div><dt>Usuario ID</dt><dd>${r.usuario_id ? '#' + num(r.usuario_id) : 'Sistema'}</dd></div><div><dt>Entidad</dt><dd>${esc(r.entidad_tabla || '—')} ${r.entidad_id ? '#' + num(r.entidad_id) : ''}</dd></div><div><dt>IP</dt><dd><code>${esc(r.ip || '—')}</code></dd></div><div class="audit-tech-wide"><dt>User-Agent</dt><dd>${esc(r.user_agent || 'No registrado')}</dd></div>`;
            activarTab('cambios');
        } catch (e) {
            mostrar('mensajeDetalle', e.message);
            $('detalleTitulo').textContent = 'No disponible';
        }
    }

    function cerrarDetalle() {
        $('modalDetalle').hidden = true;
        document.body.classList.remove('modal-open');
    }

    function activarTab(tab) {
        document.querySelectorAll('[data-audit-tab]').forEach((b) => b.classList.toggle('is-active', b.dataset.auditTab === tab));
        document.querySelectorAll('[data-audit-panel]').forEach((p) => p.classList.toggle('is-active', p.dataset.auditPanel === tab));
    }

    function programarCarga(inmediata = false) {
        clearTimeout(estado.timer);
        estado.pagina = 1;
        estado.timer = setTimeout(cargarLista, inmediata ? 0 : 280);
    }

    $('tablaAuditoria').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-detalle]');
        if (btn) abrirDetalle(btn.dataset.detalle);
    });
    $('paginacion').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-pag]'); if (!btn || btn.disabled) return;
        estado.pagina += btn.dataset.pag === 'sig' ? 1 : -1;
        cargarLista();
    });
    idsFiltros.forEach((id) => {
        const el = $(id); if (!el) return;
        const evento = el.tagName === 'INPUT' && ['search','text','number'].includes(el.type) ? 'input' : 'change';
        el.addEventListener(evento, () => programarCarga(evento === 'change'));
    });
    $('btnLimpiar').addEventListener('click', () => {
        $('fBuscar').value=''; $('fUsuario').value='0'; $('fModulo').value=''; $('fAccion').value=''; $('fEntidad').value=''; $('fEntidadId').value=''; $('fIp').value=''; $('fDesde').value=''; $('fHasta').value=''; $('fPorPagina').value='25'; programarCarga(true);
    });
    document.querySelectorAll('[data-audit-tab]').forEach((b) => b.addEventListener('click', () => activarTab(b.dataset.auditTab)));
    $('cerrarDetalle').addEventListener('click', cerrarDetalle);
    $('cerrarDetalleFooter').addEventListener('click', cerrarDetalle);
    $('modalDetalle').addEventListener('click', (e) => { if (e.target === $('modalDetalle')) cerrarDetalle(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('modalDetalle').hidden) cerrarDetalle(); });

    cargarInicial();
})();
</script>
</body>
</html>
