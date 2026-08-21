<?php

declare(strict_types=1);

if (isset($_GET['inv_api'])) {
    $endpoint = __DIR__ . '/../funciones/inventario_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/inventario_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('inventario.ver', false);

$tituloPagina = 'Inventario';
$puedeKardex = si_tiene_permiso('inventario.kardex');
$puedeAjustar = si_tiene_permiso('inventario.ajustar');
$puedeMermas = si_tiene_permiso('inventario.mermas');
$puedeOperaciones = $puedeAjustar || $puedeMermas;
$csrfToken = si_token_csrf();

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_inventario.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'existencias')));
if (!in_array($seccionInicial, ['existencias', 'kardex', 'operaciones'], true)) {
    $seccionInicial = 'existencias';
}
if ($seccionInicial === 'kardex' && !$puedeKardex) {
    $seccionInicial = 'existencias';
}
if ($seccionInicial === 'operaciones' && !$puedeOperaciones) {
    $seccionInicial = 'existencias';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Inventario | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_inventario.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content inv-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">ALMACÉN · EXISTENCIAS Y TRAZABILIDAD</p>
                    <h1>Inventario y Kardex</h1>
                    <p>Consulta la existencia física, lo reservado y lo realmente disponible. Cada entrada o salida aplicada conserva su historial en Kardex.</p>
                </div>
            </header>

            <nav class="module-tabs" aria-label="Inventario">
                <button type="button" class="module-tab" data-seccion="existencias">Existencias</button>
                <?php if ($puedeKardex): ?>
                    <button type="button" class="module-tab" data-seccion="kardex">Kardex</button>
                <?php endif; ?>
                <?php if ($puedeOperaciones): ?>
                    <button type="button" class="module-tab" data-seccion="operaciones">Ajustes y mermas</button>
                <?php endif; ?>
            </nav>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section id="seccionExistencias" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Existencias actuales</h2>
                        <p>El disponible se calcula como existencia física menos cantidad reservada; las cotizaciones no modifican ninguna de estas cantidades.</p>
                    </div>
                </div>

                <section class="stats-grid stats-grid--5">
                    <article>
                        <span>Registros</span>
                        <strong id="kpiTotal">0</strong>
                        <small>producto / almacén</small>
                    </article>
                    <article>
                        <span>Con existencia</span>
                        <strong id="kpiConExistencia">0</strong>
                        <small>existencia física mayor a 0</small>
                    </article>
                    <article>
                        <span>Sin disponible</span>
                        <strong id="kpiSinDisponible">0</strong>
                        <small>sin stock o totalmente reservado</small>
                    </article>
                    <article>
                        <span>Stock crítico</span>
                        <strong id="kpiCriticos">0</strong>
                        <small>disponible ≤ stock mínimo</small>
                    </article>
                    <article>
                        <span>Punto de reorden</span>
                        <strong id="kpiReorden">0</strong>
                        <small>requieren atención próxima</small>
                    </article>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--inventory">
                        <label class="field field--search">
                            <span>Buscar producto</span>
                            <input type="search" id="buscarInventario" maxlength="120" placeholder="SKU, nombre o código de barras" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Almacén</span>
                            <select id="filtroAlmacenInventario">
                                <option value="0">Todos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Tipo</span>
                            <select id="filtroTipoProducto">
                                <option value="TODOS">Todos</option>
                                <option value="MATERIA_PRIMA">Materia prima</option>
                                <option value="PRODUCTO_TERMINADO">Producto terminado</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Estado de stock</span>
                            <select id="filtroEstadoStock">
                                <option value="TODOS">Todos</option>
                                <option value="NORMAL">Normal</option>
                                <option value="REORDEN">Punto de reorden</option>
                                <option value="CRITICO">Crítico</option>
                                <option value="SIN_DISPONIBLE">Sin disponible</option>
                                <option value="SIN_STOCK">Sin stock físico</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Producto</span>
                            <select id="filtroEstadoProducto">
                                <option value="TODOS">Activos e inactivos</option>
                                <option value="ACTIVO">Solo activos</option>
                                <option value="INACTIVO">Solo inactivos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaInventario">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap table-wrap--inventory">
                        <table class="module-table module-table--inventory">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Tipo</th>
                                    <th>Almacén</th>
                                    <th>Unidad</th>
                                    <th class="text-right">Física</th>
                                    <th class="text-right">Reservada</th>
                                    <th class="text-right">Disponible</th>
                                    <th class="text-right">Mínimo / reorden</th>
                                    <th>Estado</th>
                                    <?php if ($puedeKardex): ?><th class="text-right">Acciones</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="tablaInventario">
                                <tr><td colspan="<?= $puedeKardex ? '10' : '9' ?>" class="empty-cell">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaInventario">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnInventarioAnterior">Anterior</button>
                            <span id="paginaInventarioActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnInventarioSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <?php if ($puedeKardex): ?>
            <section id="seccionKardex" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Historial Kardex</h2>
                        <p>Los movimientos aplicados y sus reversos permanecen visibles para conservar la trazabilidad del inventario.</p>
                    </div>
                </div>

                <div id="productoKardexActivo" class="active-filter" hidden>
                    <div>
                        <span>Producto seleccionado</span>
                        <strong id="productoKardexNombre"></strong>
                    </div>
                    <button type="button" class="btn-secondary" id="btnQuitarProductoKardex">Ver todos</button>
                </div>

                <section class="module-card">
                    <div class="filters-grid filters-grid--kardex">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarKardex" maxlength="120" placeholder="SKU, producto, folio u origen" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Almacén</span>
                            <select id="filtroAlmacenKardex">
                                <option value="0">Todos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Movimiento</span>
                            <select id="filtroTipoMovimiento">
                                <option value="0">Todos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoMovimiento">
                                <option value="TODOS">Todos</option>
                                <option value="APLICADO">Aplicados</option>
                                <option value="REVERTIDO">Revertidos</option>
                                <option value="BORRADOR">Borradores</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Desde</span>
                            <input type="date" id="fechaDesdeKardex">
                        </label>

                        <label class="field">
                            <span>Hasta</span>
                            <input type="date" id="fechaHastaKardex">
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaKardex">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap table-wrap--kardex">
                        <table class="module-table module-table--kardex">
                            <thead>
                                <tr>
                                    <th>Fecha / folio</th>
                                    <th>Movimiento</th>
                                    <th>Producto</th>
                                    <th>Almacén</th>
                                    <th class="text-right">Entrada</th>
                                    <th class="text-right">Salida</th>
                                    <th class="text-right">Antes</th>
                                    <th class="text-right">Después</th>
                                    <th>Origen</th>
                                    <th>Usuario</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="tablaKardex">
                                <tr><td colspan="11" class="empty-cell">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaKardex">0 movimientos</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnKardexAnterior">Anterior</button>
                            <span id="paginaKardexActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnKardexSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>
            <?php endif; ?>

            <?php if ($puedeOperaciones): ?>
            <section id="seccionOperaciones" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Ajustes y mermas</h2>
                        <p>Corrige diferencias justificadas o registra pérdidas reales. Cada operación modifica existencia física, genera Kardex y queda auditada.</p>
                    </div>
                </div>

                <div class="operations-layout">
                    <section class="module-card operation-form-card">
                        <div class="operation-form-head">
                            <div>
                                <h3>Nueva operación</h3>
                                <p>Las salidas nunca pueden consumir mercancía reservada.</p>
                            </div>
                            <div class="operation-kind" role="group" aria-label="Tipo de operación">
                                <?php if ($puedeAjustar): ?><button type="button" class="operation-kind-btn is-active" data-operacion="AJUSTE">Ajuste</button><?php endif; ?>
                                <?php if ($puedeMermas): ?><button type="button" class="operation-kind-btn <?= !$puedeAjustar ? 'is-active' : '' ?>" data-operacion="MERMA">Merma</button><?php endif; ?>
                            </div>
                        </div>

                        <form id="formOperacionInventario" class="operation-form" autocomplete="off">
                            <label class="field">
                                <span>Almacén</span>
                                <select id="operacionAlmacen" required></select>
                            </label>

                            <label class="field operation-product-search">
                                <span>Producto</span>
                                <input type="search" id="operacionBuscarProducto" maxlength="120" placeholder="Buscar por SKU o nombre" autocomplete="off">
                                <div id="operacionResultadosProducto" class="search-results" hidden></div>
                            </label>

                            <div id="operacionProductoSeleccionado" class="selected-product" hidden>
                                <div>
                                    <strong id="operacionProductoNombre"></strong>
                                    <span id="operacionProductoStock"></span>
                                </div>
                                <button type="button" class="btn-secondary" id="btnCambiarProductoOperacion">Cambiar</button>
                            </div>

                            <?php if ($puedeAjustar): ?>
                            <label class="field" id="campoTipoAjuste">
                                <span>Tipo de ajuste</span>
                                <select id="operacionTipoAjuste">
                                    <option value="POSITIVO">Aumentar existencia</option>
                                    <option value="NEGATIVO">Disminuir existencia</option>
                                </select>
                            </label>
                            <?php endif; ?>

                            <label class="field" id="campoCantidadOperacion">
                                <span>Cantidad <small id="operacionUnidad"></small></span>
                                <input type="number" id="operacionCantidad" min="0.000001" step="0.000001" inputmode="decimal" required>
                            </label>

                            <label class="field field--wide">
                                <span>Motivo</span>
                                <input type="text" id="operacionMotivo" maxlength="500" placeholder="Ej. Diferencia detectada en conteo físico" required>
                            </label>

                            <label class="field field--wide">
                                <span>Observaciones <small>opcional</small></span>
                                <textarea id="operacionObservaciones" maxlength="2000" rows="3" placeholder="Detalle adicional de la operación"></textarea>
                            </label>

                            <div class="operation-preview field--wide" id="operacionVistaPrevia">
                                Selecciona un producto para revisar el efecto sobre su existencia.
                            </div>

                            <div class="operation-actions field--wide">
                                <button type="submit" class="btn-primary" id="btnGuardarOperacion">Registrar operación</button>
                            </div>
                        </form>
                    </section>

                    <section class="module-card operation-history-card">
                        <div class="filters-grid filters-grid--operations">
                            <label class="field field--search">
                                <span>Buscar</span>
                                <input type="search" id="buscarOperaciones" maxlength="120" placeholder="Folio, producto, SKU o motivo" autocomplete="off">
                            </label>
                            <label class="field">
                                <span>Almacén</span>
                                <select id="filtroAlmacenOperaciones"><option value="0">Todos</option></select>
                            </label>
                            <label class="field">
                                <span>Tipo</span>
                                <select id="filtroTipoOperacion">
                                    <option value="TODOS">Todos</option>
                                    <option value="AJUSTES">Ajustes</option>
                                    <option value="MERMA">Mermas</option>
                                    <option value="AJUSTE_POSITIVO">Ajuste positivo</option>
                                    <option value="AJUSTE_NEGATIVO">Ajuste negativo</option>
                                </select>
                            </label>
                            <label class="field">
                                <span>Estado</span>
                                <select id="filtroEstadoOperacion">
                                    <option value="TODOS">Todos</option>
                                    <option value="APLICADO">Aplicados</option>
                                    <option value="REVERTIDO">Revertidos</option>
                                </select>
                            </label>
                            <label class="field"><span>Desde</span><input type="date" id="fechaDesdeOperaciones"></label>
                            <label class="field"><span>Hasta</span><input type="date" id="fechaHastaOperaciones"></label>
                            <label class="field">
                                <span>Por página</span>
                                <select id="porPaginaOperaciones">
                                    <option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option>
                                </select>
                            </label>
                        </div>

                        <div class="table-wrap table-wrap--operations">
                            <table class="module-table module-table--operations">
                                <thead><tr>
                                    <th>Fecha / folio</th><th>Tipo</th><th>Producto</th><th>Almacén</th>
                                    <th class="text-right">Movimiento</th><th class="text-right">Antes</th><th class="text-right">Después</th>
                                    <th>Motivo</th><th>Usuario</th><th>Estado</th><th class="text-right">Acciones</th>
                                </tr></thead>
                                <tbody id="tablaOperaciones"><tr><td colspan="11" class="empty-cell">Cargando...</td></tr></tbody>
                            </table>
                        </div>
                        <footer class="pagination">
                            <span id="textoPaginaOperaciones">0 operaciones</span>
                            <div><button type="button" class="btn-secondary" id="btnOperacionesAnterior">Anterior</button><span id="paginaOperacionesActual">Página 1 de 1</span><button type="button" class="btn-secondary" id="btnOperacionesSiguiente">Siguiente</button></div>
                        </footer>
                    </section>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
(() => {
    'use strict';

    const CONFIG = Object.freeze({
        endpoint: 'inventario.php?inv_api=1',
        seccionInicial: <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        puedeKardex: <?= $puedeKardex ? 'true' : 'false' ?>,
        puedeAjustar: <?= $puedeAjustar ? 'true' : 'false' ?>,
        puedeMermas: <?= $puedeMermas ? 'true' : 'false' ?>,
        puedeOperaciones: <?= $puedeOperaciones ? 'true' : 'false' ?>,
        csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    });

    const estado = {
        seccion: CONFIG.seccionInicial,
        inventario: {
            pagina: 1,
            porPagina: 20,
            totalPaginas: 1,
        },
        kardex: {
            pagina: 1,
            porPagina: 20,
            totalPaginas: 1,
            productoId: 0,
            productoNombre: '',
        },
        requestInventario: 0,
        requestKardex: 0,
        operaciones: { pagina: 1, porPagina: 20, totalPaginas: 1, tipo: CONFIG.puedeAjustar ? 'AJUSTE' : 'MERMA', producto: null },
        requestOperaciones: 0,
        requestProductosOperacion: 0,
        requestResumen: 0,
    };

    const $ = (id) => document.getElementById(id);

    const dom = {
        mensaje: $('mensajePagina'),
        seccionExistencias: $('seccionExistencias'),
        seccionKardex: $('seccionKardex'),
        seccionOperaciones: $('seccionOperaciones'),
        tabs: Array.from(document.querySelectorAll('.module-tab[data-seccion]')),

        buscarInventario: $('buscarInventario'),
        filtroAlmacenInventario: $('filtroAlmacenInventario'),
        filtroTipoProducto: $('filtroTipoProducto'),
        filtroEstadoStock: $('filtroEstadoStock'),
        filtroEstadoProducto: $('filtroEstadoProducto'),
        porPaginaInventario: $('porPaginaInventario'),
        tablaInventario: $('tablaInventario'),
        textoPaginaInventario: $('textoPaginaInventario'),
        paginaInventarioActual: $('paginaInventarioActual'),
        btnInventarioAnterior: $('btnInventarioAnterior'),
        btnInventarioSiguiente: $('btnInventarioSiguiente'),
        kpiTotal: $('kpiTotal'),
        kpiConExistencia: $('kpiConExistencia'),
        kpiSinDisponible: $('kpiSinDisponible'),
        kpiCriticos: $('kpiCriticos'),
        kpiReorden: $('kpiReorden'),

        buscarKardex: $('buscarKardex'),
        filtroAlmacenKardex: $('filtroAlmacenKardex'),
        filtroTipoMovimiento: $('filtroTipoMovimiento'),
        filtroEstadoMovimiento: $('filtroEstadoMovimiento'),
        fechaDesdeKardex: $('fechaDesdeKardex'),
        fechaHastaKardex: $('fechaHastaKardex'),
        porPaginaKardex: $('porPaginaKardex'),
        tablaKardex: $('tablaKardex'),
        textoPaginaKardex: $('textoPaginaKardex'),
        paginaKardexActual: $('paginaKardexActual'),
        btnKardexAnterior: $('btnKardexAnterior'),
        btnKardexSiguiente: $('btnKardexSiguiente'),
        productoKardexActivo: $('productoKardexActivo'),
        productoKardexNombre: $('productoKardexNombre'),
        btnQuitarProductoKardex: $('btnQuitarProductoKardex'),

        formOperacion: $('formOperacionInventario'),
        operacionAlmacen: $('operacionAlmacen'),
        operacionBuscarProducto: $('operacionBuscarProducto'),
        operacionResultadosProducto: $('operacionResultadosProducto'),
        operacionProductoSeleccionado: $('operacionProductoSeleccionado'),
        operacionProductoNombre: $('operacionProductoNombre'),
        operacionProductoStock: $('operacionProductoStock'),
        btnCambiarProductoOperacion: $('btnCambiarProductoOperacion'),
        campoTipoAjuste: $('campoTipoAjuste'),
        operacionTipoAjuste: $('operacionTipoAjuste'),
        campoCantidadOperacion: $('campoCantidadOperacion'),
        operacionCantidad: $('operacionCantidad'),
        operacionUnidad: $('operacionUnidad'),
        operacionMotivo: $('operacionMotivo'),
        operacionObservaciones: $('operacionObservaciones'),
        operacionVistaPrevia: $('operacionVistaPrevia'),
        btnGuardarOperacion: $('btnGuardarOperacion'),
        botonesTipoOperacion: Array.from(document.querySelectorAll('.operation-kind-btn[data-operacion]')),
        buscarOperaciones: $('buscarOperaciones'),
        filtroAlmacenOperaciones: $('filtroAlmacenOperaciones'),
        filtroTipoOperacion: $('filtroTipoOperacion'),
        filtroEstadoOperacion: $('filtroEstadoOperacion'),
        fechaDesdeOperaciones: $('fechaDesdeOperaciones'),
        fechaHastaOperaciones: $('fechaHastaOperaciones'),
        porPaginaOperaciones: $('porPaginaOperaciones'),
        tablaOperaciones: $('tablaOperaciones'),
        textoPaginaOperaciones: $('textoPaginaOperaciones'),
        paginaOperacionesActual: $('paginaOperacionesActual'),
        btnOperacionesAnterior: $('btnOperacionesAnterior'),
        btnOperacionesSiguiente: $('btnOperacionesSiguiente'),
    };

    function escapar(valor) {
        return String(valor ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function numero(valor) {
        const n = Number(valor ?? 0);
        if (!Number.isFinite(n)) return '0';

        return new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 6,
        }).format(n);
    }

    function fechaHora(valor) {
        const texto = String(valor ?? '').trim();
        if (!texto) return '—';

        const fecha = new Date(texto.replace(' ', 'T'));
        if (Number.isNaN(fecha.getTime())) return escapar(texto);

        return new Intl.DateTimeFormat('es-MX', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).format(fecha);
    }

    function mostrarMensaje(mensaje, tipo = 'error') {
        if (!dom.mensaje) return;
        dom.mensaje.textContent = mensaje;
        dom.mensaje.className = `module-message module-message--${tipo}`;
        dom.mensaje.hidden = false;
    }

    function ocultarMensaje() {
        if (!dom.mensaje) return;
        dom.mensaje.hidden = true;
        dom.mensaje.textContent = '';
    }

    async function apiGet(accion, parametros = {}) {
        const url = new URL(CONFIG.endpoint, window.location.href);
        url.searchParams.set('accion', accion);

        Object.entries(parametros).forEach(([clave, valor]) => {
            if (valor !== undefined && valor !== null && valor !== '') {
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

        if (!respuesta.ok || !data.success) {
            if (data?.sesion_expirada && data?.redirect) {
                window.location.href = data.redirect;
                return null;
            }
            throw new Error(data?.mensaje || 'No fue posible completar la consulta.');
        }

        return data;
    }

    async function apiPost(accion, parametros = {}) {
        const body = new URLSearchParams();
        body.set('accion', accion);
        body.set('csrf_token', CONFIG.csrfToken);
        Object.entries(parametros).forEach(([clave, valor]) => {
            if (valor !== undefined && valor !== null) body.set(clave, String(valor));
        });
        const respuesta = await fetch(CONFIG.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            cache: 'no-store',
        });
        let data;
        try { data = await respuesta.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta inválida.'); }
        if (!respuesta.ok || !data.success) {
            if (data?.sesion_expirada && data?.redirect) { window.location.href = data.redirect; return null; }
            throw new Error(data?.mensaje || 'No fue posible completar la operación.');
        }
        return data;
    }

    function debounce(fn, espera = 350) {
        let timer = null;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), espera);
        };
    }

    function cambiarSeccion(seccion, actualizarUrl = true) {
        if (seccion === 'kardex' && !CONFIG.puedeKardex) {
            seccion = 'existencias';
        }
        if (seccion === 'operaciones' && !CONFIG.puedeOperaciones) seccion = 'existencias';
        if (!['existencias', 'kardex', 'operaciones'].includes(seccion)) {
            seccion = 'existencias';
        }

        estado.seccion = seccion;
        if (dom.seccionExistencias) dom.seccionExistencias.hidden = seccion !== 'existencias';
        if (dom.seccionKardex) dom.seccionKardex.hidden = seccion !== 'kardex';
        if (dom.seccionOperaciones) dom.seccionOperaciones.hidden = seccion !== 'operaciones';

        dom.tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.dataset.seccion === seccion);
        });

        if (actualizarUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('seccion', seccion);
            history.replaceState(null, '', url.toString());
        }

        if (seccion === 'existencias') {
            cargarInventario();
            cargarResumen();
        } else if (seccion === 'kardex' && CONFIG.puedeKardex) {
            cargarKardex();
        } else if (seccion === 'operaciones' && CONFIG.puedeOperaciones) {
            cargarOperaciones();
        }
    }

    function llenarSelect(select, filas, primeraOpcion, valorCampo = 'id', textoFn = null) {
        if (!select) return;
        const valorActual = select.value;
        select.innerHTML = `<option value="${escapar(primeraOpcion.valor)}">${escapar(primeraOpcion.texto)}</option>`;

        filas.forEach((fila) => {
            const opcion = document.createElement('option');
            opcion.value = String(fila[valorCampo]);
            opcion.textContent = textoFn ? textoFn(fila) : String(fila.nombre ?? '');
            select.appendChild(opcion);
        });

        if (Array.from(select.options).some((o) => o.value === valorActual)) {
            select.value = valorActual;
        }
    }

    async function cargarCatalogos() {
        const data = await apiGet('CATALOGOS');
        if (!data) return;

        const almacenes = Array.isArray(data.almacenes) ? data.almacenes : [];
        llenarSelect(
            dom.filtroAlmacenInventario,
            almacenes,
            { valor: 0, texto: 'Todos' },
            'id',
            (a) => `${a.nombre}${a.codigo ? ` · ${a.codigo}` : ''}`
        );

        if (CONFIG.puedeOperaciones) {
            llenarSelect(dom.operacionAlmacen, almacenes, { valor: '', texto: 'Selecciona almacén' }, 'id', (a) => `${a.nombre}${a.codigo ? ` · ${a.codigo}` : ''}`);
            llenarSelect(dom.filtroAlmacenOperaciones, almacenes, { valor: 0, texto: 'Todos' }, 'id', (a) => `${a.nombre}${a.codigo ? ` · ${a.codigo}` : ''}`);
        }

        if (CONFIG.puedeKardex) {
            llenarSelect(
                dom.filtroAlmacenKardex,
                almacenes,
                { valor: 0, texto: 'Todos' },
                'id',
                (a) => `${a.nombre}${a.codigo ? ` · ${a.codigo}` : ''}`
            );

            llenarSelect(
                dom.filtroTipoMovimiento,
                Array.isArray(data.tipos_movimiento) ? data.tipos_movimiento : [],
                { valor: 0, texto: 'Todos' },
                'id',
                (t) => `${t.nombre}${t.codigo ? ` · ${t.codigo}` : ''}`
            );
        }
    }

    function parametrosInventario() {
        return {
            pagina: estado.inventario.pagina,
            por_pagina: estado.inventario.porPagina,
            buscar: dom.buscarInventario?.value.trim() || '',
            almacen_id: dom.filtroAlmacenInventario?.value || 0,
            tipo_producto: dom.filtroTipoProducto?.value || 'TODOS',
            estado_stock: dom.filtroEstadoStock?.value || 'TODOS',
            estado_producto: dom.filtroEstadoProducto?.value || 'TODOS',
        };
    }

    function parametrosResumen() {
        return {
            almacen_id: dom.filtroAlmacenInventario?.value || 0,
            tipo_producto: dom.filtroTipoProducto?.value || 'TODOS',
            estado_producto: dom.filtroEstadoProducto?.value || 'TODOS',
        };
    }

    async function cargarResumen() {
        const requestId = ++estado.requestResumen;
        try {
            const data = await apiGet('RESUMEN', parametrosResumen());
            if (!data || requestId !== estado.requestResumen) return;

            const r = data.resumen || {};
            dom.kpiTotal.textContent = numero(r.total_registros);
            dom.kpiConExistencia.textContent = numero(r.con_existencia);
            dom.kpiSinDisponible.textContent = numero(r.sin_disponible);
            dom.kpiCriticos.textContent = numero(r.criticos);
            dom.kpiReorden.textContent = numero(r.reorden);
        } catch (error) {
            if (requestId === estado.requestResumen) mostrarMensaje(error.message);
        }
    }

    function etiquetaTipoProducto(tipo) {
        if (tipo === 'MATERIA_PRIMA') return 'Materia prima';
        if (tipo === 'PRODUCTO_TERMINADO') return 'Producto terminado';
        return tipo || '—';
    }

    function claseEstadoStock(estadoStock) {
        return {
            NORMAL: 'status-badge--active',
            REORDEN: 'status-badge--warning',
            CRITICO: 'status-badge--danger',
            SIN_DISPONIBLE: 'status-badge--reserved',
            SIN_STOCK: 'status-badge--inactive',
        }[estadoStock] || 'status-badge--inactive';
    }

    function textoEstadoStock(estadoStock) {
        return {
            NORMAL: 'Normal',
            REORDEN: 'Reorden',
            CRITICO: 'Crítico',
            SIN_DISPONIBLE: 'Sin disponible',
            SIN_STOCK: 'Sin stock',
        }[estadoStock] || estadoStock || '—';
    }

    function renderInventario(registros) {
        if (!dom.tablaInventario) return;
        const colspan = CONFIG.puedeKardex ? 10 : 9;

        if (!Array.isArray(registros) || registros.length === 0) {
            dom.tablaInventario.innerHTML = `<tr><td colspan="${colspan}" class="empty-cell">No hay productos que coincidan con los filtros.</td></tr>`;
            return;
        }

        dom.tablaInventario.innerHTML = registros.map((r) => {
            const unidad = r.unidad_simbolo || r.unidad_base || '';
            const activo = Number(r.producto_activo) === 1;
            const reorden = r.punto_reorden === null ? '—' : numero(r.punto_reorden);
            const accion = CONFIG.puedeKardex
                ? `<td class="actions-cell text-right"><button type="button" class="table-action" data-ver-kardex="1" data-producto-id="${Number(r.producto_id)}" data-almacen-id="${Number(r.almacen_id)}" data-producto="${escapar(`${r.sku} · ${r.producto}`)}">Ver Kardex</button></td>`
                : '';

            return `
                <tr>
                    <td>
                        <strong>${escapar(r.producto)}</strong>
                        <span class="cell-secondary">${escapar(r.sku)}${r.codigo_barras ? ` · ${escapar(r.codigo_barras)}` : ''}</span>
                        ${activo ? '' : '<span class="status-badge status-badge--inactive status-badge--inline">Producto inactivo</span>'}
                    </td>
                    <td>${escapar(etiquetaTipoProducto(r.tipo_producto))}</td>
                    <td><strong>${escapar(r.almacen)}</strong><span class="cell-secondary">${escapar(r.almacen_codigo)}</span></td>
                    <td>${escapar(unidad)}</td>
                    <td class="number-cell">${numero(r.existencia_fisica)}</td>
                    <td class="number-cell ${Number(r.cantidad_reservada) > 0 ? 'number-cell--reserved' : ''}">${numero(r.cantidad_reservada)}</td>
                    <td class="number-cell number-cell--available">${numero(r.cantidad_disponible)}</td>
                    <td class="number-cell"><span>${numero(r.stock_minimo)}</span><span class="cell-secondary">Reorden: ${reorden}</span></td>
                    <td><span class="status-badge ${claseEstadoStock(r.estado_stock)}">${escapar(textoEstadoStock(r.estado_stock))}</span></td>
                    ${accion}
                </tr>`;
        }).join('');
    }

    function actualizarPaginacionInventario(paginacion) {
        const p = paginacion || {};
        estado.inventario.pagina = Number(p.pagina || 1);
        estado.inventario.totalPaginas = Math.max(1, Number(p.total_paginas || 1));
        const total = Number(p.total || 0);

        dom.textoPaginaInventario.textContent = `${numero(total)} ${total === 1 ? 'registro' : 'registros'}`;
        dom.paginaInventarioActual.textContent = `Página ${estado.inventario.pagina} de ${estado.inventario.totalPaginas}`;
        dom.btnInventarioAnterior.disabled = estado.inventario.pagina <= 1;
        dom.btnInventarioSiguiente.disabled = estado.inventario.pagina >= estado.inventario.totalPaginas;
    }

    async function cargarInventario() {
        const requestId = ++estado.requestInventario;
        ocultarMensaje();
        dom.tablaInventario.innerHTML = `<tr><td colspan="${CONFIG.puedeKardex ? 10 : 9}" class="empty-cell">Cargando inventario...</td></tr>`;

        try {
            const data = await apiGet('LISTAR_INVENTARIO', parametrosInventario());
            if (!data || requestId !== estado.requestInventario) return;

            renderInventario(data.registros || []);
            actualizarPaginacionInventario(data.paginacion || {});
        } catch (error) {
            if (requestId !== estado.requestInventario) return;
            dom.tablaInventario.innerHTML = `<tr><td colspan="${CONFIG.puedeKardex ? 10 : 9}" class="empty-cell">No fue posible cargar el inventario.</td></tr>`;
            mostrarMensaje(error.message);
        }
    }

    function parametrosKardex() {
        return {
            pagina: estado.kardex.pagina,
            por_pagina: estado.kardex.porPagina,
            buscar: dom.buscarKardex?.value.trim() || '',
            producto_id: estado.kardex.productoId,
            almacen_id: dom.filtroAlmacenKardex?.value || 0,
            tipo_movimiento_id: dom.filtroTipoMovimiento?.value || 0,
            estado: dom.filtroEstadoMovimiento?.value || 'TODOS',
            fecha_desde: dom.fechaDesdeKardex?.value || '',
            fecha_hasta: dom.fechaHastaKardex?.value || '',
        };
    }

    function claseEstadoMovimiento(valor) {
        return {
            APLICADO: 'status-badge--active',
            REVERTIDO: 'status-badge--warning',
            BORRADOR: 'status-badge--inactive',
        }[valor] || 'status-badge--inactive';
    }

    function renderKardex(registros) {
        if (!dom.tablaKardex) return;

        if (!Array.isArray(registros) || registros.length === 0) {
            dom.tablaKardex.innerHTML = '<tr><td colspan="11" class="empty-cell">No hay movimientos que coincidan con los filtros.</td></tr>';
            return;
        }

        dom.tablaKardex.innerHTML = registros.map((r) => {
            const unidad = r.unidad_simbolo || r.unidad_base || '';
            const entrada = Number(r.cantidad_entrada) > 0 ? numero(r.cantidad_entrada) : '—';
            const salida = Number(r.cantidad_salida) > 0 ? numero(r.cantidad_salida) : '—';
            const detalleTexto = [r.motivo, r.observaciones].filter(Boolean).join(' · ');

            return `
                <tr>
                    <td><strong>${fechaHora(r.fecha_movimiento)}</strong><span class="cell-secondary">${escapar(r.folio)}</span></td>
                    <td><strong>${escapar(r.tipo_movimiento)}</strong><span class="cell-secondary">${escapar(r.tipo_codigo)}</span></td>
                    <td><strong>${escapar(r.producto)}</strong><span class="cell-secondary">${escapar(r.sku)} · ${escapar(unidad)}</span></td>
                    <td><strong>${escapar(r.almacen)}</strong><span class="cell-secondary">${escapar(r.almacen_codigo)}</span></td>
                    <td class="number-cell number-cell--entry">${entrada}</td>
                    <td class="number-cell number-cell--exit">${salida}</td>
                    <td class="number-cell">${numero(r.existencia_antes)}</td>
                    <td class="number-cell"><strong>${numero(r.existencia_despues)}</strong></td>
                    <td><strong>${escapar(r.origen_referencia || '—')}</strong>${detalleTexto ? `<span class="cell-secondary cell-secondary--wrap">${escapar(detalleTexto)}</span>` : ''}</td>
                    <td>${escapar(r.usuario_aplico || 'Sistema')}</td>
                    <td><span class="status-badge ${claseEstadoMovimiento(r.estado)}">${escapar(r.estado)}</span></td>
                </tr>`;
        }).join('');
    }

    function actualizarPaginacionKardex(paginacion) {
        const p = paginacion || {};
        estado.kardex.pagina = Number(p.pagina || 1);
        estado.kardex.totalPaginas = Math.max(1, Number(p.total_paginas || 1));
        const total = Number(p.total || 0);

        dom.textoPaginaKardex.textContent = `${numero(total)} ${total === 1 ? 'movimiento' : 'movimientos'}`;
        dom.paginaKardexActual.textContent = `Página ${estado.kardex.pagina} de ${estado.kardex.totalPaginas}`;
        dom.btnKardexAnterior.disabled = estado.kardex.pagina <= 1;
        dom.btnKardexSiguiente.disabled = estado.kardex.pagina >= estado.kardex.totalPaginas;
    }

    function refrescarProductoKardex() {
        if (!dom.productoKardexActivo) return;
        const activo = estado.kardex.productoId > 0;
        dom.productoKardexActivo.hidden = !activo;
        if (activo && dom.productoKardexNombre) {
            dom.productoKardexNombre.textContent = estado.kardex.productoNombre || `Producto #${estado.kardex.productoId}`;
        }
    }

    async function cargarKardex() {
        if (!CONFIG.puedeKardex || !dom.tablaKardex) return;

        const requestId = ++estado.requestKardex;
        ocultarMensaje();
        refrescarProductoKardex();
        dom.tablaKardex.innerHTML = '<tr><td colspan="11" class="empty-cell">Cargando Kardex...</td></tr>';

        try {
            const data = await apiGet('LISTAR_KARDEX', parametrosKardex());
            if (!data || requestId !== estado.requestKardex) return;

            renderKardex(data.registros || []);
            actualizarPaginacionKardex(data.paginacion || {});
        } catch (error) {
            if (requestId !== estado.requestKardex) return;
            dom.tablaKardex.innerHTML = '<tr><td colspan="11" class="empty-cell">No fue posible cargar el Kardex.</td></tr>';
            mostrarMensaje(error.message);
        }
    }

    function seleccionarProductoKardex(productoId, nombre, almacenId = 0) {
        if (!CONFIG.puedeKardex) return;

        estado.kardex.productoId = Number(productoId || 0);
        estado.kardex.productoNombre = String(nombre || '');
        estado.kardex.pagina = 1;

        if (dom.filtroAlmacenKardex && Number(almacenId) > 0) {
            const valor = String(almacenId);
            if (Array.from(dom.filtroAlmacenKardex.options).some((o) => o.value === valor)) {
                dom.filtroAlmacenKardex.value = valor;
            }
        }

        refrescarProductoKardex();
        cambiarSeccion('kardex');
    }


    function actualizarDisponibilidadBuscadorOperacion(enfocar = false) {
        if (!dom.operacionBuscarProducto) return;
        const hayAlmacen = Number(dom.operacionAlmacen?.value || 0) > 0;
        dom.operacionBuscarProducto.disabled = !hayAlmacen;
        dom.operacionBuscarProducto.placeholder = hayAlmacen
            ? 'Buscar por SKU, nombre o código de barras'
            : 'Selecciona un almacén primero';

        if (!hayAlmacen) {
            limpiarProductoOperacion();
            return;
        }

        if (enfocar && !estado.operaciones.producto) {
            dom.operacionBuscarProducto.focus();
        }
    }

    function tipoOperacionActual(tipo) {
        const anterior = estado.operaciones.tipo;
        if (tipo && ((tipo === 'AJUSTE' && CONFIG.puedeAjustar) || (tipo === 'MERMA' && CONFIG.puedeMermas))) {
            estado.operaciones.tipo = tipo;
        }
        dom.botonesTipoOperacion.forEach((b) => b.classList.toggle('is-active', b.dataset.operacion === estado.operaciones.tipo));

        const esAjuste = estado.operaciones.tipo === 'AJUSTE';
        if (dom.campoTipoAjuste) {
            dom.campoTipoAjuste.hidden = !esAjuste;
            dom.campoTipoAjuste.style.display = esAjuste ? '' : 'none';
        }
        if (dom.operacionTipoAjuste) dom.operacionTipoAjuste.disabled = !esAjuste;
        if (dom.campoCantidadOperacion) dom.campoCantidadOperacion.classList.toggle('field--wide', !esAjuste);
        if (dom.btnGuardarOperacion) dom.btnGuardarOperacion.textContent = esAjuste ? 'Registrar ajuste' : 'Registrar merma';
        actualizarVistaPreviaOperacion();

        if (anterior !== estado.operaciones.tipo && dom.operacionResultadosProducto && !dom.operacionResultadosProducto.hidden) {
            buscarProductosOperacion();
        }
    }

    function limpiarProductoOperacion() {
        estado.operaciones.producto = null;
        if (dom.operacionBuscarProducto) { dom.operacionBuscarProducto.value = ''; dom.operacionBuscarProducto.hidden = false; }
        if (dom.operacionResultadosProducto) { dom.operacionResultadosProducto.hidden = true; dom.operacionResultadosProducto.innerHTML = ''; }
        if (dom.operacionProductoSeleccionado) dom.operacionProductoSeleccionado.hidden = true;
        if (dom.operacionUnidad) dom.operacionUnidad.textContent = '';
        actualizarVistaPreviaOperacion();
    }

    function seleccionarProductoOperacion(producto) {
        estado.operaciones.producto = producto;
        dom.operacionBuscarProducto.hidden = true;
        dom.operacionResultadosProducto.hidden = true;
        dom.operacionProductoSeleccionado.hidden = false;
        dom.operacionProductoNombre.textContent = `${producto.nombre} · ${producto.sku}`;
        dom.operacionProductoStock.textContent = `Física ${numero(producto.existencia_fisica)} · Reservada ${numero(producto.cantidad_reservada)} · Disponible ${numero(producto.cantidad_disponible)} ${producto.unidad_simbolo || producto.unidad_base || ''}`;
        dom.operacionUnidad.textContent = producto.unidad_simbolo ? `(${producto.unidad_simbolo})` : '';
        dom.operacionCantidad.step = producto.permite_fraccion ? '0.000001' : '1';
        actualizarVistaPreviaOperacion();
    }

    async function buscarProductosOperacion() {
        if (!CONFIG.puedeOperaciones || !dom.operacionAlmacen?.value) return;
        const requestId = ++estado.requestProductosOperacion;
        const buscar = dom.operacionBuscarProducto?.value.trim() || '';
        if (buscar.length < 1) { dom.operacionResultadosProducto.hidden = true; return; }
        try {
            const data = await apiGet('BUSCAR_PRODUCTOS_OPERACION', { buscar, almacen_id: dom.operacionAlmacen.value, tipo_operacion: estado.operaciones.tipo, tipo_ajuste: dom.operacionTipoAjuste?.value || '' });
            if (!data || requestId !== estado.requestProductosOperacion) return;
            const productos = Array.isArray(data.productos) ? data.productos : [];
            const esSalida = estado.operaciones.tipo === 'MERMA'
                || (estado.operaciones.tipo === 'AJUSTE' && dom.operacionTipoAjuste?.value === 'NEGATIVO');
            const mensajeVacio = esSalida
                ? 'No hay productos con existencia disponible que coincidan en este almacén.'
                : 'No se encontraron productos activos que coincidan.';
            dom.operacionResultadosProducto.innerHTML = productos.length ? productos.map((p, i) => `
                <button type="button" class="search-result-item" data-producto-index="${i}">
                    <strong>${escapar(p.nombre)}</strong><span>${escapar(p.sku)} · Física ${numero(p.existencia_fisica)} · Reservada ${numero(p.cantidad_reservada)} · Disponible ${numero(p.cantidad_disponible)} ${escapar(p.unidad_simbolo || p.unidad_base || '')}</span>
                </button>`).join('') : `<div class="search-result-empty">${escapar(mensajeVacio)}</div>`;
            dom.operacionResultadosProducto.dataset.productos = JSON.stringify(productos);
            dom.operacionResultadosProducto.hidden = false;
        } catch (error) { mostrarMensaje(error.message); }
    }

    function actualizarVistaPreviaOperacion() {
        if (!dom.operacionVistaPrevia) return;
        const p = estado.operaciones.producto;
        if (!p) { dom.operacionVistaPrevia.textContent = 'Selecciona un producto para revisar el efecto sobre su existencia.'; return; }
        const cantidad = Number(dom.operacionCantidad?.value || 0);
        if (!(cantidad > 0)) { dom.operacionVistaPrevia.textContent = `Existencia actual: ${numero(p.existencia_fisica)} · Reservada: ${numero(p.cantidad_reservada)}.`; return; }
        const entrada = estado.operaciones.tipo === 'AJUSTE' && dom.operacionTipoAjuste?.value === 'POSITIVO';
        const despues = Number(p.existencia_fisica) + (entrada ? cantidad : -cantidad);
        const disponibleDespues = despues - Number(p.cantidad_reservada);
        dom.operacionVistaPrevia.innerHTML = `Física: <strong>${numero(p.existencia_fisica)}</strong> → <strong>${numero(despues)}</strong> · Reservada: <strong>${numero(p.cantidad_reservada)}</strong> · Disponible después: <strong>${numero(disponibleDespues)}</strong>`;
        dom.operacionVistaPrevia.classList.toggle('operation-preview--danger', disponibleDespues < -0.000001);
    }

    async function guardarOperacion(event) {
        event.preventDefault();
        ocultarMensaje();
        const p = estado.operaciones.producto;
        if (!p) { mostrarMensaje('Selecciona un producto.'); return; }
        const cantidad = Number(dom.operacionCantidad.value || 0);
        if (!(cantidad > 0)) { mostrarMensaje('Captura una cantidad mayor a cero.'); return; }
        if (!dom.operacionMotivo.value.trim() || dom.operacionMotivo.value.trim().length < 5) { mostrarMensaje('Captura un motivo de al menos 5 caracteres.'); return; }
        const esMerma = estado.operaciones.tipo === 'MERMA';
        const esSalida = esMerma || dom.operacionTipoAjuste?.value === 'NEGATIVO';
        if (esSalida && cantidad > Number(p.cantidad_disponible) + 0.000001) {
            mostrarMensaje('La salida supera la existencia disponible y afectaría mercancía reservada.'); return;
        }
        const accion = esMerma ? 'REGISTRAR_MERMA' : 'REGISTRAR_AJUSTE';
        const confirmar = window.confirm(`${esMerma ? 'Registrar merma' : 'Aplicar ajuste'} de ${numero(cantidad)} ${p.unidad_simbolo || p.unidad_base || ''} en ${p.nombre}?`);
        if (!confirmar) return;
        dom.btnGuardarOperacion.disabled = true;
        try {
            const data = await apiPost(accion, {
                almacen_id: dom.operacionAlmacen.value,
                producto_id: p.id,
                tipo_ajuste: esMerma ? 'NEGATIVO' : (dom.operacionTipoAjuste?.value || 'POSITIVO'),
                cantidad: dom.operacionCantidad.value,
                motivo: dom.operacionMotivo.value.trim(),
                observaciones: dom.operacionObservaciones.value.trim(),
            });
            mostrarMensaje(data.mensaje || 'Operación registrada.', 'success');
            dom.formOperacion.reset();
            limpiarProductoOperacion();
            tipoOperacionActual(estado.operaciones.tipo);
            cargarOperaciones();
            cargarInventario();
            cargarResumen();
            if (CONFIG.puedeKardex) cargarKardex();
        } catch (error) { mostrarMensaje(error.message); }
        finally { dom.btnGuardarOperacion.disabled = false; }
    }

    function parametrosOperaciones() {
        return {
            pagina: estado.operaciones.pagina,
            por_pagina: estado.operaciones.porPagina,
            buscar: dom.buscarOperaciones?.value.trim() || '',
            almacen_id: dom.filtroAlmacenOperaciones?.value || 0,
            tipo: dom.filtroTipoOperacion?.value || 'TODOS',
            estado: dom.filtroEstadoOperacion?.value || 'TODOS',
            fecha_desde: dom.fechaDesdeOperaciones?.value || '',
            fecha_hasta: dom.fechaHastaOperaciones?.value || '',
        };
    }

    function renderOperaciones(registros) {
        if (!dom.tablaOperaciones) return;
        if (!Array.isArray(registros) || !registros.length) { dom.tablaOperaciones.innerHTML = '<tr><td colspan="11" class="empty-cell">No hay ajustes o mermas que coincidan con los filtros.</td></tr>'; return; }
        dom.tablaOperaciones.innerHTML = registros.map((r) => {
            const delta = Number(r.cantidad_delta || 0);
            const mov = delta >= 0 ? `+${numero(delta)}` : `−${numero(Math.abs(delta))}`;
            const clase = delta >= 0 ? 'number-cell--entry' : 'number-cell--exit';
            return `<tr>
                <td><strong>${fechaHora(r.fecha_movimiento)}</strong><span class="cell-secondary">${escapar(r.folio)}</span></td>
                <td><strong>${escapar(r.tipo_nombre)}</strong><span class="cell-secondary">${escapar(r.tipo_codigo)}</span></td>
                <td><strong>${escapar(r.producto)}</strong><span class="cell-secondary">${escapar(r.sku)} · ${escapar(r.unidad_simbolo || r.unidad_base || '')}</span></td>
                <td><strong>${escapar(r.almacen)}</strong><span class="cell-secondary">${escapar(r.almacen_codigo)}</span></td>
                <td class="number-cell ${clase}">${mov}</td><td class="number-cell">${numero(r.existencia_antes)}</td><td class="number-cell"><strong>${numero(r.existencia_despues)}</strong></td>
                <td>${escapar(r.motivo || '—')}${r.observaciones ? `<span class="cell-secondary cell-secondary--wrap">${escapar(r.observaciones)}</span>` : ''}</td>
                <td>${escapar(r.usuario || 'Sistema')}</td><td><span class="status-badge ${claseEstadoMovimiento(r.estado)}">${escapar(r.estado)}</span></td>
                <td class="actions-cell text-right">${r.puede_revertir ? `<button type="button" class="table-action table-action--danger" data-revertir-operacion="${r.movimiento_id}" data-folio="${escapar(r.folio)}">Revertir</button>` : '—'}</td>
            </tr>`;
        }).join('');
    }

    function actualizarPaginacionOperaciones(p) {
        estado.operaciones.pagina = Number(p?.pagina || 1); estado.operaciones.totalPaginas = Math.max(1, Number(p?.total_paginas || 1));
        const total = Number(p?.total || 0);
        dom.textoPaginaOperaciones.textContent = `${numero(total)} ${total === 1 ? 'operación' : 'operaciones'}`;
        dom.paginaOperacionesActual.textContent = `Página ${estado.operaciones.pagina} de ${estado.operaciones.totalPaginas}`;
        dom.btnOperacionesAnterior.disabled = estado.operaciones.pagina <= 1; dom.btnOperacionesSiguiente.disabled = estado.operaciones.pagina >= estado.operaciones.totalPaginas;
    }

    async function cargarOperaciones() {
        if (!CONFIG.puedeOperaciones || !dom.tablaOperaciones) return;
        const requestId = ++estado.requestOperaciones;
        dom.tablaOperaciones.innerHTML = '<tr><td colspan="11" class="empty-cell">Cargando...</td></tr>';
        try {
            const data = await apiGet('LISTAR_AJUSTES_MERMAS', parametrosOperaciones());
            if (!data || requestId !== estado.requestOperaciones) return;
            renderOperaciones(data.registros || []); actualizarPaginacionOperaciones(data.paginacion || {});
        } catch (error) { if (requestId === estado.requestOperaciones) { dom.tablaOperaciones.innerHTML = '<tr><td colspan="11" class="empty-cell">No fue posible cargar las operaciones.</td></tr>'; mostrarMensaje(error.message); } }
    }

    async function revertirOperacion(id, folio) {
        const motivo = window.prompt(`Motivo para revertir ${folio}:`);
        if (motivo === null) return;
        if (motivo.trim().length < 5) { mostrarMensaje('Captura un motivo de al menos 5 caracteres.'); return; }
        if (!window.confirm(`¿Revertir ${folio}? La existencia física se moverá en sentido contrario y el movimiento original quedará REVERTIDO.`)) return;
        try {
            const data = await apiPost('REVERTIR_OPERACION', { movimiento_id: id, motivo: motivo.trim() });
            mostrarMensaje(data.mensaje || 'Movimiento revertido.', 'success');
            cargarOperaciones(); cargarInventario(); cargarResumen(); if (CONFIG.puedeKardex) cargarKardex();
        } catch (error) { mostrarMensaje(error.message); }
    }

    function enlazarEventos() {
        dom.tabs.forEach((tab) => {
            tab.addEventListener('click', () => cambiarSeccion(tab.dataset.seccion || 'existencias'));
        });

        const buscarInventarioDebounced = debounce(() => {
            estado.inventario.pagina = 1;
            cargarInventario();
        });
        dom.buscarInventario?.addEventListener('input', buscarInventarioDebounced);

        [dom.filtroAlmacenInventario, dom.filtroTipoProducto, dom.filtroEstadoProducto].forEach((control) => {
            control?.addEventListener('change', () => {
                estado.inventario.pagina = 1;
                cargarInventario();
                cargarResumen();
            });
        });

        dom.filtroEstadoStock?.addEventListener('change', () => {
            estado.inventario.pagina = 1;
            cargarInventario();
        });

        dom.porPaginaInventario?.addEventListener('change', () => {
            estado.inventario.porPagina = Number(dom.porPaginaInventario.value || 20);
            estado.inventario.pagina = 1;
            cargarInventario();
        });

        dom.btnInventarioAnterior?.addEventListener('click', () => {
            if (estado.inventario.pagina <= 1) return;
            estado.inventario.pagina -= 1;
            cargarInventario();
        });

        dom.btnInventarioSiguiente?.addEventListener('click', () => {
            if (estado.inventario.pagina >= estado.inventario.totalPaginas) return;
            estado.inventario.pagina += 1;
            cargarInventario();
        });

        dom.tablaInventario?.addEventListener('click', (event) => {
            const boton = event.target.closest('[data-ver-kardex="1"]');
            if (!boton) return;
            seleccionarProductoKardex(
                boton.dataset.productoId,
                boton.dataset.producto,
                boton.dataset.almacenId
            );
        });

        if (CONFIG.puedeOperaciones) {
            dom.botonesTipoOperacion.forEach((boton) => {
                boton.addEventListener('click', () => tipoOperacionActual(boton.dataset.operacion || 'AJUSTE'));
            });

            dom.operacionAlmacen?.addEventListener('change', () => {
                limpiarProductoOperacion();
                actualizarDisponibilidadBuscadorOperacion(true);
            });

            dom.operacionBuscarProducto?.addEventListener('input', debounce(buscarProductosOperacion, 280));
            dom.operacionResultadosProducto?.addEventListener('click', (event) => {
                const boton = event.target.closest('[data-producto-index]');
                if (!boton) return;
                try {
                    const productos = JSON.parse(dom.operacionResultadosProducto.dataset.productos || '[]');
                    const producto = productos[Number(boton.dataset.productoIndex)];
                    if (producto) seleccionarProductoOperacion(producto);
                } catch (_) {}
            });

            dom.btnCambiarProductoOperacion?.addEventListener('click', () => {
                limpiarProductoOperacion();
                actualizarDisponibilidadBuscadorOperacion(true);
            });
            dom.operacionCantidad?.addEventListener('input', actualizarVistaPreviaOperacion);
            dom.operacionTipoAjuste?.addEventListener('change', () => {
                actualizarVistaPreviaOperacion();
                if (dom.operacionResultadosProducto && !dom.operacionResultadosProducto.hidden) buscarProductosOperacion();
            });
            dom.formOperacion?.addEventListener('submit', guardarOperacion);

            const recargarOps = () => {
                estado.operaciones.pagina = 1;
                cargarOperaciones();
            };
            dom.buscarOperaciones?.addEventListener('input', debounce(recargarOps));
            [
                dom.filtroAlmacenOperaciones,
                dom.filtroTipoOperacion,
                dom.filtroEstadoOperacion,
                dom.fechaDesdeOperaciones,
                dom.fechaHastaOperaciones,
            ].forEach((control) => control?.addEventListener('change', recargarOps));
            dom.porPaginaOperaciones?.addEventListener('change', () => {
                estado.operaciones.porPagina = Number(dom.porPaginaOperaciones.value || 20);
                recargarOps();
            });
            dom.btnOperacionesAnterior?.addEventListener('click', () => {
                if (estado.operaciones.pagina > 1) {
                    estado.operaciones.pagina -= 1;
                    cargarOperaciones();
                }
            });
            dom.btnOperacionesSiguiente?.addEventListener('click', () => {
                if (estado.operaciones.pagina < estado.operaciones.totalPaginas) {
                    estado.operaciones.pagina += 1;
                    cargarOperaciones();
                }
            });
            dom.tablaOperaciones?.addEventListener('click', (event) => {
                const boton = event.target.closest('[data-revertir-operacion]');
                if (boton) revertirOperacion(Number(boton.dataset.revertirOperacion), boton.dataset.folio || 'movimiento');
            });

            tipoOperacionActual(estado.operaciones.tipo);
            actualizarDisponibilidadBuscadorOperacion(false);
        }

        if (!CONFIG.puedeKardex) return;

        const buscarKardexDebounced = debounce(() => {
            estado.kardex.pagina = 1;
            cargarKardex();
        });
        dom.buscarKardex?.addEventListener('input', buscarKardexDebounced);

        [
            dom.filtroAlmacenKardex,
            dom.filtroTipoMovimiento,
            dom.filtroEstadoMovimiento,
            dom.fechaDesdeKardex,
            dom.fechaHastaKardex,
        ].forEach((control) => {
            control?.addEventListener('change', () => {
                estado.kardex.pagina = 1;
                cargarKardex();
            });
        });

        dom.porPaginaKardex?.addEventListener('change', () => {
            estado.kardex.porPagina = Number(dom.porPaginaKardex.value || 20);
            estado.kardex.pagina = 1;
            cargarKardex();
        });

        dom.btnKardexAnterior?.addEventListener('click', () => {
            if (estado.kardex.pagina <= 1) return;
            estado.kardex.pagina -= 1;
            cargarKardex();
        });

        dom.btnKardexSiguiente?.addEventListener('click', () => {
            if (estado.kardex.pagina >= estado.kardex.totalPaginas) return;
            estado.kardex.pagina += 1;
            cargarKardex();
        });

        dom.btnQuitarProductoKardex?.addEventListener('click', () => {
            estado.kardex.productoId = 0;
            estado.kardex.productoNombre = '';
            estado.kardex.pagina = 1;
            refrescarProductoKardex();
            cargarKardex();
        });
    }

    async function iniciar() {
        enlazarEventos();
        try {
            await cargarCatalogos();
            cambiarSeccion(estado.seccion, false);
        } catch (error) {
            mostrarMensaje(error.message);
            if (dom.tablaInventario) {
                dom.tablaInventario.innerHTML = `<tr><td colspan="${CONFIG.puedeKardex ? 10 : 9}" class="empty-cell">No fue posible iniciar el módulo.</td></tr>`;
            }
        }
    }

    iniciar();
})();
</script>
</body>
</html>
