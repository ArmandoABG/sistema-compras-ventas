<?php

declare(strict_types=1);

if (isset($_GET['rep_api'])) {
    $endpoint = __DIR__ . '/../funciones/reportes_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/reportes_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('reportes.ver', false);

$tituloPagina = 'Reportes';
$puedeExportar = si_tiene_permiso('contabilidad.exportar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_reportes.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Reportes | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_reportes.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content reportes-page">
            <header class="module-heading reportes-heading">
                <div>
                    <p class="module-eyebrow">CONSULTA · CONTROL · ANÁLISIS</p>
                    <h1>Reportes</h1>
                    <p>Consulta información formal derivada de los módulos operativos. Este apartado no modifica inventario ni movimientos financieros.</p>
                </div>
                <?php if ($puedeExportar): ?>
                    <button type="button" class="btn-secondary" id="btnExportar" disabled>Exportar CSV</button>
                <?php endif; ?>
            </header>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="module-card" id="panelSelector">
                <div class="section-actions">
                    <div>
                        <h2>Selecciona un reporte</h2>
                        <p>Los filtros disponibles cambian de acuerdo con la información consultada.</p>
                    </div>
                </div>
                <div id="reportesGrid" class="reportes-grid" aria-live="polite">
                    <p class="reportes-loading">Cargando reportes...</p>
                </div>
            </section>

            <section class="module-card" id="panelReporte" hidden>
                <div class="reportes-selected-head">
                    <div>
                        <button type="button" class="link-button" id="btnCambiarReporte">← Cambiar reporte</button>
                        <h2 id="reporteTitulo">Reporte</h2>
                        <p id="reporteDescripcion"></p>
                    </div>
                </div>

                <div class="reportes-filtros" id="filtrosReporte">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input type="search" id="filtroBuscar" placeholder="Folio, nombre, producto o referencia">
                    </label>

                    <label class="field" data-filtro="fecha">
                        <span>Desde</span>
                        <input type="date" id="filtroDesde">
                    </label>

                    <label class="field" data-filtro="fecha">
                        <span>Hasta</span>
                        <input type="date" id="filtroHasta">
                    </label>

                    <label class="field" data-filtro="almacen">
                        <span>Almacén</span>
                        <select id="filtroAlmacen"><option value="0">Todos</option></select>
                    </label>

                    <label class="field" data-filtro="producto">
                        <span>Producto</span>
                        <select id="filtroProducto"><option value="0">Todos</option></select>
                    </label>

                    <label class="field" data-filtro="proveedor">
                        <span>Proveedor</span>
                        <select id="filtroProveedor"><option value="0">Todos</option></select>
                    </label>

                    <label class="field" data-filtro="cliente">
                        <span>Cliente</span>
                        <select id="filtroCliente"><option value="0">Todos</option></select>
                    </label>

                    <label class="field" data-filtro="usuario">
                        <span>Usuario</span>
                        <select id="filtroUsuario"><option value="0">Todos</option></select>
                    </label>

                    <label class="field" data-filtro="estado">
                        <span id="estadoLabel">Estado</span>
                        <select id="filtroEstado"><option value="">Todos</option></select>
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

                    <div class="filter-actions">
                        <button type="button" class="btn-secondary" id="btnLimpiar">Limpiar filtros</button>
                        <button type="button" class="btn-primary" id="btnConsultar">Consultar</button>
                    </div>
                </div>

                <div class="reportes-results-head">
                    <p id="resultadoResumen">0 registros</p>
                </div>

                <div class="table-wrap reportes-table-wrap">
                    <table class="module-table reportes-table">
                        <thead id="tablaHead"></thead>
                        <tbody id="tablaBody">
                            <tr><td class="empty-cell">Selecciona filtros y consulta el reporte.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination reportes-pagination">
                    <button type="button" class="btn-secondary" id="btnAnterior" disabled>Anterior</button>
                    <span id="paginaInfo">Página 1 de 1</span>
                    <button type="button" class="btn-secondary" id="btnSiguiente" disabled>Siguiente</button>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
(() => {
    'use strict';

    const CONFIG = {
        endpoint: 'reportes.php?rep_api=1',
        puedeExportar: <?= $puedeExportar ? 'true' : 'false' ?>,
    };

    const $ = (id) => document.getElementById(id);
    const dom = {
        mensaje: $('mensajePagina'),
        panelSelector: $('panelSelector'),
        panelReporte: $('panelReporte'),
        grid: $('reportesGrid'),
        btnExportar: $('btnExportar'),
        btnCambiar: $('btnCambiarReporte'),
        titulo: $('reporteTitulo'),
        descripcion: $('reporteDescripcion'),
        filtros: Array.from(document.querySelectorAll('[data-filtro]')),
        buscar: $('filtroBuscar'),
        desde: $('filtroDesde'),
        hasta: $('filtroHasta'),
        almacen: $('filtroAlmacen'),
        producto: $('filtroProducto'),
        proveedor: $('filtroProveedor'),
        cliente: $('filtroCliente'),
        usuario: $('filtroUsuario'),
        estado: $('filtroEstado'),
        estadoLabel: $('estadoLabel'),
        porPagina: $('porPagina'),
        btnLimpiar: $('btnLimpiar'),
        btnConsultar: $('btnConsultar'),
        head: $('tablaHead'),
        body: $('tablaBody'),
        resumen: $('resultadoResumen'),
        anterior: $('btnAnterior'),
        siguiente: $('btnSiguiente'),
        paginaInfo: $('paginaInfo'),
    };

    const estado = {
        catalogos: { reportes: [], almacenes: [], productos: [], proveedores: [], clientes: [], usuarios: [] },
        reporte: null,
        pagina: 1,
        paginas: 1,
        total: 0,
        cargando: false,
        solicitud: 0,
    };

    function payload(data) {
        if (!data || typeof data !== 'object') return {};
        if (data.datos && typeof data.datos === 'object' && !Array.isArray(data.datos)) {
            return { ...data, ...data.datos };
        }
        return data;
    }

    async function apiGet(accion, parametros = {}) {
        const url = new URL(CONFIG.endpoint, window.location.href);
        url.searchParams.set('accion', accion);
        Object.entries(parametros).forEach(([clave, valor]) => {
            if (valor !== undefined && valor !== null && String(valor) !== '') {
                url.searchParams.set(clave, String(valor));
            }
        });

        const respuesta = await fetch(url.toString(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        });

        let data;
        try {
            data = await respuesta.json();
        } catch (_) {
            throw new Error('El servidor devolvió una respuesta inválida.');
        }
        data = payload(data);

        if (!respuesta.ok || !data.success) {
            if (data?.sesion_expirada && data?.redirect) {
                window.location.href = data.redirect;
                return null;
            }
            throw new Error(data?.mensaje || 'No fue posible consultar reportes.');
        }
        return data;
    }

    function mostrarMensaje(texto, tipo = 'error') {
        if (!dom.mensaje) return;
        if (!texto) {
            dom.mensaje.hidden = true;
            dom.mensaje.textContent = '';
            dom.mensaje.className = 'module-message';
            return;
        }
        dom.mensaje.textContent = texto;
        dom.mensaje.className = `module-message is-${tipo}`;
        dom.mensaje.hidden = false;
    }

    function escapar(valor) {
        return String(valor ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function etiquetaHistorica(fila) {
        if (fila?.deleted_at) return ' · En papelera';
        if (Number(fila?.activo ?? 1) !== 1) return ' · Inactivo';
        return '';
    }

    function llenarSelect(select, filas, textoFn) {
        if (!select) return;
        select.innerHTML = '<option value="0">Todos</option>';
        (Array.isArray(filas) ? filas : []).forEach((fila) => {
            const option = document.createElement('option');
            option.value = String(fila.id ?? 0);
            option.textContent = `${textoFn(fila)}${etiquetaHistorica(fila)}`;
            select.appendChild(option);
        });
    }

    function estadoBonito(valor) {
        return String(valor ?? '')
            .replaceAll('_', ' ')
            .toLowerCase()
            .replace(/(^|\s)\S/g, (l) => l.toUpperCase());
    }

    function renderReportes() {
        if (!estado.catalogos.reportes.length) {
            dom.grid.innerHTML = '<p class="reportes-loading">No hay reportes disponibles.</p>';
            return;
        }
        dom.grid.innerHTML = estado.catalogos.reportes.map((r) => `
            <button type="button" class="report-card" data-reporte="${escapar(r.codigo)}">
                <span class="report-card__title">${escapar(r.nombre)}</span>
                <span class="report-card__desc">${escapar(r.descripcion)}</span>
                <span class="report-card__action">Consultar →</span>
            </button>
        `).join('');

        dom.grid.querySelectorAll('[data-reporte]').forEach((btn) => {
            btn.addEventListener('click', () => seleccionarReporte(btn.dataset.reporte));
        });
    }

    async function cargarCatalogos() {
        mostrarMensaje('');
        const data = await apiGet('CATALOGOS');
        if (!data) return;

        estado.catalogos.reportes = Array.isArray(data.reportes) ? data.reportes : [];
        estado.catalogos.almacenes = Array.isArray(data.almacenes) ? data.almacenes : [];
        estado.catalogos.productos = Array.isArray(data.productos) ? data.productos : [];
        estado.catalogos.proveedores = Array.isArray(data.proveedores) ? data.proveedores : [];
        estado.catalogos.clientes = Array.isArray(data.clientes) ? data.clientes : [];
        estado.catalogos.usuarios = Array.isArray(data.usuarios) ? data.usuarios : [];
        CONFIG.puedeExportar = Boolean(data.puede_exportar ?? CONFIG.puedeExportar);

        llenarSelect(dom.almacen, estado.catalogos.almacenes, (x) => `${x.codigo || ''}${x.codigo ? ' · ' : ''}${x.nombre || ''}`);
        llenarSelect(dom.producto, estado.catalogos.productos, (x) => `${x.sku || ''}${x.sku ? ' · ' : ''}${x.nombre || ''}`);
        llenarSelect(dom.proveedor, estado.catalogos.proveedores, (x) => `${x.codigo || ''}${x.codigo ? ' · ' : ''}${x.nombre || ''}`);
        llenarSelect(dom.cliente, estado.catalogos.clientes, (x) => `${x.codigo || ''}${x.codigo ? ' · ' : ''}${x.nombre || ''}`);
        llenarSelect(dom.usuario, estado.catalogos.usuarios, (x) => `${x.usuario || ''}${x.nombre ? ` · ${x.nombre}` : ''}`);

        renderReportes();
    }

    function seleccionarReporte(codigo) {
        const reporte = estado.catalogos.reportes.find((r) => r.codigo === codigo);
        if (!reporte) {
            mostrarMensaje('No fue posible localizar el reporte seleccionado.');
            return;
        }

        estado.reporte = reporte;
        estado.pagina = 1;
        estado.paginas = 1;
        estado.total = 0;
        limpiarFiltros(false);

        dom.titulo.textContent = reporte.nombre || 'Reporte';
        dom.descripcion.textContent = reporte.descripcion || '';
        const filtrosPermitidos = Array.isArray(reporte.filtros) ? reporte.filtros : [];
        dom.filtros.forEach((label) => {
            label.hidden = !filtrosPermitidos.includes(label.dataset.filtro);
        });

        dom.estadoLabel.textContent = reporte.estado_label || 'Estado';
        dom.estado.innerHTML = '<option value="">Todos</option>';
        (Array.isArray(reporte.estados) ? reporte.estados : []).forEach((valor) => {
            const option = document.createElement('option');
            option.value = String(valor);
            option.textContent = estadoBonito(valor);
            dom.estado.appendChild(option);
        });

        dom.panelSelector.hidden = true;
        dom.panelReporte.hidden = false;
        if (dom.btnExportar) dom.btnExportar.disabled = !CONFIG.puedeExportar;
        cargarReporte();
    }

    function cambiarReporte() {
        estado.reporte = null;
        estado.pagina = 1;
        estado.paginas = 1;
        estado.total = 0;
        dom.panelReporte.hidden = true;
        dom.panelSelector.hidden = false;
        if (dom.btnExportar) dom.btnExportar.disabled = true;
        mostrarMensaje('');
    }

    function parametros() {
        return {
            reporte: estado.reporte?.codigo || '',
            pagina: estado.pagina,
            por_pagina: dom.porPagina.value || 20,
            buscar: dom.buscar.value.trim(),
            fecha_desde: dom.desde.value,
            fecha_hasta: dom.hasta.value,
            almacen_id: dom.almacen.value || 0,
            producto_id: dom.producto.value || 0,
            proveedor_id: dom.proveedor.value || 0,
            cliente_id: dom.cliente.value || 0,
            usuario_id: dom.usuario.value || 0,
            estado: dom.estado.value,
        };
    }

    function formatNumero(valor, decimales = 6) {
        const n = Number(valor);
        if (!Number.isFinite(n)) return '—';
        return new Intl.NumberFormat('es-MX', { maximumFractionDigits: decimales }).format(n);
    }

    function formatMoneda(valor) {
        const n = Number(valor);
        if (!Number.isFinite(n)) return '—';
        return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(n);
    }

    function formatFecha(valor, conHora = false) {
        if (!valor) return '—';
        const original = String(valor);
        let d;
        const soloFecha = original.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (soloFecha) {
            d = new Date(Number(soloFecha[1]), Number(soloFecha[2]) - 1, Number(soloFecha[3]));
        } else {
            d = new Date(original.replace(' ', 'T'));
        }
        if (Number.isNaN(d.getTime())) return escapar(valor);
        return new Intl.DateTimeFormat('es-MX', conHora
            ? { dateStyle: 'short', timeStyle: 'short' }
            : { dateStyle: 'short' }
        ).format(d);
    }

    function renderValor(valor, tipo) {
        if (tipo === 'moneda') return escapar(formatMoneda(valor));
        if (tipo === 'cantidad') return escapar(formatNumero(valor, 6));
        if (tipo === 'entero') return escapar(formatNumero(valor, 0));
        if (tipo === 'fecha') return escapar(formatFecha(valor, false));
        if (tipo === 'fecha_hora') return escapar(formatFecha(valor, true));
        if (tipo === 'estado') return `<span class="status-badge">${escapar(estadoBonito(valor))}</span>`;
        return escapar(valor === null || valor === '' ? '—' : valor);
    }

    function renderTabla(columnas, filas) {
        const cols = Array.isArray(columnas) ? columnas : [];
        const rows = Array.isArray(filas) ? filas : [];

        dom.head.innerHTML = `<tr>${cols.map((c) => `<th>${escapar(c.titulo)}</th>`).join('')}</tr>`;
        if (!rows.length) {
            dom.body.innerHTML = `<tr><td colspan="${Math.max(1, cols.length)}" class="empty-cell">No hay registros que coincidan con los filtros.</td></tr>`;
            return;
        }

        dom.body.innerHTML = rows.map((fila) => `
            <tr>${cols.map((c) => `<td>${renderValor(fila[c.campo], c.tipo)}</td>`).join('')}</tr>
        `).join('');
    }

    async function cargarReporte() {
        if (!estado.reporte) return;
        const solicitud = ++estado.solicitud;
        estado.cargando = true;
        dom.btnConsultar.disabled = true;
        mostrarMensaje('');

        try {
            const data = await apiGet('LISTAR', parametros());
            if (!data || solicitud !== estado.solicitud) return;
            const pag = data.paginacion && typeof data.paginacion === 'object' ? data.paginacion : {};
            estado.pagina = Number(pag.pagina || 1);
            estado.paginas = Math.max(1, Number(pag.paginas || 1));
            estado.total = Number(pag.total || 0);

            renderTabla(data.columnas || estado.reporte.columnas || [], data.filas || []);
            dom.resumen.textContent = `${new Intl.NumberFormat('es-MX').format(estado.total)} registro${estado.total === 1 ? '' : 's'}`;
            dom.paginaInfo.textContent = `Página ${estado.pagina} de ${estado.paginas}`;
            dom.anterior.disabled = estado.pagina <= 1;
            dom.siguiente.disabled = estado.pagina >= estado.paginas;
        } catch (error) {
            if (solicitud === estado.solicitud) {
                mostrarMensaje(error.message || 'No fue posible cargar el reporte.');
            }
        } finally {
            if (solicitud === estado.solicitud) {
                estado.cargando = false;
                dom.btnConsultar.disabled = false;
            }
        }
    }

    function limpiarFiltros(recargar = true) {
        dom.buscar.value = '';
        dom.desde.value = '';
        dom.hasta.value = '';
        dom.almacen.value = '0';
        dom.producto.value = '0';
        dom.proveedor.value = '0';
        dom.cliente.value = '0';
        dom.usuario.value = '0';
        dom.estado.value = '';
        dom.porPagina.value = '20';
        estado.pagina = 1;
        if (recargar && estado.reporte) cargarReporte();
    }

    function exportar() {
        if (!estado.reporte || !CONFIG.puedeExportar) return;
        const url = new URL(CONFIG.endpoint, window.location.href);
        url.searchParams.set('accion', 'EXPORTAR_CSV');
        Object.entries(parametros()).forEach(([clave, valor]) => {
            if (clave === 'pagina' || clave === 'por_pagina') return;
            if (valor !== undefined && valor !== null && String(valor) !== '') url.searchParams.set(clave, String(valor));
        });
        window.location.href = url.toString();
    }

    function debounce(fn, espera = 350) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), espera);
        };
    }

    dom.btnCambiar.addEventListener('click', cambiarReporte);
    dom.btnConsultar.addEventListener('click', () => { estado.pagina = 1; cargarReporte(); });
    dom.btnLimpiar.addEventListener('click', () => limpiarFiltros(true));
    dom.anterior.addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina -= 1; cargarReporte(); } });
    dom.siguiente.addEventListener('click', () => { if (estado.pagina < estado.paginas) { estado.pagina += 1; cargarReporte(); } });
    if (dom.btnExportar) dom.btnExportar.addEventListener('click', exportar);

    dom.porPagina.addEventListener('change', () => { estado.pagina = 1; cargarReporte(); });
    [dom.desde, dom.hasta, dom.almacen, dom.producto, dom.proveedor, dom.cliente, dom.usuario, dom.estado].forEach((control) => {
        control.addEventListener('change', () => { if (estado.reporte) { estado.pagina = 1; cargarReporte(); } });
    });
    dom.buscar.addEventListener('input', debounce(() => { if (estado.reporte) { estado.pagina = 1; cargarReporte(); } }, 450));

    cargarCatalogos().catch((error) => {
        dom.grid.innerHTML = '<p class="reportes-loading">No fue posible cargar los reportes.</p>';
        mostrarMensaje(error.message || 'No fue posible cargar Reportes.');
    });
})();
</script>
</body>
</html>
