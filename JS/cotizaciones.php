<?php

declare(strict_types=1);

if (isset($_GET['cotizaciones_api'])) {
    $endpoint = __DIR__ . '/../funciones/cotizaciones_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/cotizaciones_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('cotizaciones.ver', false);

$tituloPagina = 'Cotizaciones';
$csrfToken = si_token_csrf();
$puedeCrear = si_tiene_permiso('cotizaciones.crear');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_cotizaciones.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cotizaciones | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_cotizaciones.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content cotizaciones-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">GESTIÓN COMERCIAL · PROPUESTAS</p>
                    <h1>Cotizaciones</h1>
                    <p>Genera propuestas comerciales con precios, descuentos e impuestos sin afectar inventario.</p>
                </div>

                <?php if ($puedeCrear): ?>
                    <button type="button" class="btn-primary" id="btnNuevaCotizacion">
                        Nueva cotización
                    </button>
                <?php endif; ?>
            </header>

            <div class="info-banner">
                <strong>Importante:</strong>
                una cotización no descuenta ni reserva existencias.
                El descuento del cliente se toma automáticamente de su clasificación o de su descuento especial y queda guardado como histórico en cada renglón.
            </div>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="stats-grid stats-grid--5">
                <article><span>Total</span><strong id="kpiTotal">0</strong></article>
                <article><span>Borradores</span><strong id="kpiBorradores">0</strong></article>
                <article><span>Generadas</span><strong id="kpiGeneradas">0</strong></article>
                <article><span>Aceptadas</span><strong id="kpiAceptadas">0</strong></article>
                <article><span>Vencidas</span><strong id="kpiVencidas">0</strong></article>
            </section>

            <section class="module-card">
                <div class="filters-grid filters-grid--quotes">
                    <label class="field field--search">
                        <span>Buscar</span>
                        <input
                            type="search"
                            id="buscarCotizacion"
                            maxlength="180"
                            placeholder="Folio, cliente o código de cliente"
                            autocomplete="off"
                        >
                    </label>

                    <label class="field">
                        <span>Estado</span>
                        <select id="filtroEstado">
                            <option value="TODOS">Todos</option>
                            <option value="BORRADOR">Borrador</option>
                            <option value="GENERADA">Generada</option>
                            <option value="ACEPTADA">Aceptada</option>
                            <option value="RECHAZADA">Rechazada</option>
                            <option value="VENCIDA">Vencida</option>
                            <option value="CONVERTIDA">Convertida</option>
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

                <div class="table-wrap">
                    <table class="module-table module-table--quotes">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Cliente</th>
                                <th>Fecha / vigencia</th>
                                <th>Estado</th>
                                <th>Productos</th>
                                <th>Total</th>
                                <th>Creada por</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaCotizaciones">
                            <tr><td colspan="8" class="empty-cell">Cargando...</td></tr>
                        </tbody>
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

<!-- ==========================================================
     MODAL CREAR / EDITAR COTIZACIÓN
     ========================================================== -->
<div class="modal-backdrop" id="modalCotizacion" hidden>
    <section class="modal-card modal-card--quote" role="dialog" aria-modal="true" aria-labelledby="tituloModalCotizacion">
        <header class="modal-header">
            <div>
                <h2 id="tituloModalCotizacion">Nueva cotización</h2>
                <p id="subtituloModalCotizacion">El folio se genera automáticamente al guardar.</p>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCotizacion" aria-label="Cerrar">×</button>
        </header>

        <form id="formCotizacion" class="quote-form" autocomplete="off">
            <input type="hidden" id="cotizacionId" name="cotizacion_id">

            <div id="mensajeCotizacion" class="module-message" hidden></div>

            <section class="quote-header-grid">
                <label class="field">
                    <span>Folio</span>
                    <input type="text" id="cotizacionFolio" value="Se generará al guardar" readonly>
                </label>

                <label class="field field--client-search">
                    <span>Cliente *</span>
                    <input
                        type="search"
                        id="buscarClienteCotizacion"
                        placeholder="Escribe código, nombre, RFC o teléfono"
                        autocomplete="off"
                    >
                    <input type="hidden" id="cotizacionClienteId">
                    <div id="resultadosClientes" class="smart-results" hidden></div>
                </label>

                <label class="field">
                    <span>Moneda *</span>
                    <select id="cotizacionMoneda" required></select>
                </label>

                <label class="field">
                    <span>Vigencia *</span>
                    <input type="date" id="cotizacionVigencia" required>
                </label>
            </section>

            <section class="client-summary" id="resumenCliente">
                <div>
                    <span>Cliente seleccionado</span>
                    <strong id="clienteNombreResumen">Ninguno</strong>
                    <small id="clienteCodigoResumen">Selecciona un cliente para aplicar su descuento.</small>
                </div>
                <div>
                    <span>Clasificación</span>
                    <strong id="clienteNivelResumen">—</strong>
                    <small>Se toma directamente de la ficha del cliente.</small>
                </div>
                <div>
                    <span>Descuento aplicado</span>
                    <strong id="clienteDescuentoResumen">0.00%</strong>
                    <small id="clienteOrigenDescuento">Sin cliente seleccionado.</small>
                </div>
                <div>
                    <span>Tipo de cambio</span>
                    <strong id="tipoCambioResumen">1.00000000</strong>
                    <small>Moneda de cotización → moneda base.</small>
                </div>
            </section>

            <section class="quote-products">
                <div class="quote-products__heading">
                    <div>
                        <h3>Productos</h3>
                        <p>Busca un producto. El sistema intenta sugerir el precio vigente; si no existe, puedes capturarlo manualmente.</p>
                    </div>
                </div>

                <label class="field product-search-field">
                    <span>Agregar producto</span>
                    <input
                        type="search"
                        id="buscarProductoCotizacion"
                        placeholder="Escribe código o nombre del producto"
                        autocomplete="off"
                    >
                    <div id="resultadosProductos" class="smart-results smart-results--products" hidden></div>
                </label>

                <div class="table-wrap quote-lines-wrap">
                    <table class="module-table quote-lines-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Presentación</th>
                                <th>Cantidad</th>
                                <th>Precio unitario</th>
                                <th>Desc.</th>
                                <th>IVA</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tablaLineasCotizacion">
                            <tr><td colspan="8" class="empty-cell">Agrega al menos un producto.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="quote-bottom-grid">
                <label class="field">
                    <span>Observaciones</span>
                    <textarea
                        id="cotizacionObservaciones"
                        rows="5"
                        maxlength="5000"
                        placeholder="Condiciones, notas comerciales o información que deba aparecer en la cotización"
                    ></textarea>
                </label>

                <section class="totals-card">
                    <div><span>Importe antes de descuento</span><strong id="totalBruto">$0.00</strong></div>
                    <div><span>Descuento</span><strong id="totalDescuento">-$0.00</strong></div>
                    <div><span>Subtotal</span><strong id="totalSubtotal">$0.00</strong></div>
                    <div><span>Impuestos</span><strong id="totalImpuesto">$0.00</strong></div>
                    <div class="totals-card__grand"><span>Total</span><strong id="totalFinal">$0.00</strong></div>
                </section>
            </div>

            <footer class="modal-footer quote-modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalCotizacion">Cancelar</button>

                <?php if ($puedeCrear): ?>
                    <div>
                        <button type="submit" class="btn-secondary" data-modo-guardar="BORRADOR">
                            Guardar borrador
                        </button>
                        <button type="submit" class="btn-primary" data-modo-guardar="GENERAR">
                            Guardar y generar
                        </button>
                    </div>
                <?php endif; ?>
            </footer>
        </form>
    </section>
</div>

<!-- ==========================================================
     MODAL DETALLE
     ========================================================== -->
<div class="modal-backdrop" id="modalDetalleCotizacion" hidden>
    <section class="modal-card modal-card--detail" role="dialog" aria-modal="true" aria-labelledby="tituloDetalleCotizacion">
        <header class="modal-header">
            <div>
                <h2 id="tituloDetalleCotizacion">Cotización</h2>
                <p id="subtituloDetalleCotizacion">Detalle comercial</p>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalDetalleCotizacion" aria-label="Cerrar">×</button>
        </header>

        <div id="contenidoDetalleCotizacion" class="quote-detail-content">
            <div class="empty-cell">Cargando...</div>
        </div>

        <footer class="modal-footer">
            <a class="btn-secondary btn-link" id="btnImprimirDetalle" href="#" target="_blank">Imprimir</a>
            <button type="button" class="btn-secondary" data-cerrar-modal="modalDetalleCotizacion">Cerrar</button>
        </footer>
    </section>
</div>

<script>
(function () {
    'use strict';

    const puedeCrear = <?= $puedeCrear ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const estado = {
        monedas: [],
        empresa: '',
        pagina: 1,
        porPagina: 20,
        totalPaginas: 1,
        cotizaciones: [],
        timerListado: null,
        timerCliente: null,
        timerProducto: null,
        clienteSeleccionado: null,
        lineas: [],
        lineaSecuencia: 1,
        modoGuardar: 'BORRADOR',
        editando: false
    };

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(valor) {
        return String(valor == null ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function numero(valor, decimales) {
        const n = Number(valor || 0);
        const d = typeof decimales === 'number' ? decimales : 2;

        return new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: d,
            maximumFractionDigits: d
        }).format(Number.isFinite(n) ? n : 0);
    }

    function fechaCorta(valor) {
        if (!valor) return '—';

        const s = String(valor).replace(' ', 'T');
        const d = new Date(s);

        if (Number.isNaN(d.getTime())) {
            return String(valor).slice(0, 10);
        }

        return new Intl.DateTimeFormat('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        }).format(d);
    }

    function fechaHora(valor) {
        if (!valor) return '—';

        const d = new Date(String(valor).replace(' ', 'T'));

        if (Number.isNaN(d.getTime())) {
            return valor;
        }

        return new Intl.DateTimeFormat('es-MX', {
            dateStyle: 'short',
            timeStyle: 'short'
        }).format(d);
    }

    function moneda(valor, codigo, simbolo) {
        const n = Number(valor || 0);
        const txt = new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Number.isFinite(n) ? n : 0);

        return (simbolo || '$') + txt + (codigo ? ' ' + codigo : '');
    }

    function porcentaje(valor) {
        return numero(valor, 2) + '%';
    }

    function mostrarMensaje(elemento, texto, tipo) {
        elemento.textContent = texto;
        elemento.className = 'module-message module-message--' + (tipo || 'error');
        elemento.hidden = false;
    }

    function ocultarMensaje(elemento) {
        elemento.textContent = '';
        elemento.hidden = true;
    }

    async function api(url, opciones) {
        const respuesta = await fetch(
            url,
            Object.assign({
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }, opciones || {})
        );

        const texto = await respuesta.text();
        let datos;

        try {
            datos = JSON.parse(texto);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (datos.sesion_expirada && datos.redirect) {
            window.location.href = datos.redirect;
            return null;
        }

        if (!respuesta.ok || datos.success !== true) {
            const error = new Error(datos.mensaje || 'No fue posible completar la operación.');
            error.data = datos;
            throw error;
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

    function status(texto, tipo) {
        return '<span class="status-badge status-badge--'
            + escapeHtml(tipo)
            + '">'
            + escapeHtml(texto)
            + '</span>';
    }

    function estadoVisual(valor) {
        const mapa = {
            BORRADOR: ['Borrador', 'neutral'],
            GENERADA: ['Generada', 'warning'],
            ACEPTADA: ['Aceptada', 'success'],
            RECHAZADA: ['Rechazada', 'danger'],
            VENCIDA: ['Vencida', 'danger'],
            CONVERTIDA: ['Convertida', 'active']
        };

        return mapa[valor] || [valor, 'neutral'];
    }

    function monedaActual() {
        const id = Number($('cotizacionMoneda').value || 0);

        return estado.monedas.find(function (m) {
            return Number(m.id) === id;
        }) || estado.monedas[0] || {
            id: 0,
            codigo: 'MXN',
            simbolo: '$',
            es_base: 1
        };
    }

    function mostrarErrorGlobal(error) {
        mostrarMensaje(
            $('mensajePagina'),
            error && error.message ? error.message : 'Ocurrió un error.',
            'error'
        );
    }

    async function post(accion, valores) {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', accion);

        Object.keys(valores || {}).forEach(function (clave) {
            const valor = valores[clave];
            form.append(clave, valor == null ? '' : String(valor));
        });

        return api('?cotizaciones_api=1', {
            method: 'POST',
            body: form
        });
    }

    /* ==========================================================
       CATÁLOGOS Y LISTADO
       ========================================================== */

    async function cargarCatalogos() {
        const datos = await api('?cotizaciones_api=1&accion=CATALOGOS');

        estado.monedas = datos.monedas || [];
        estado.empresa = datos.empresa || '';

        $('cotizacionMoneda').innerHTML = estado.monedas.map(function (m) {
            return '<option value="' + Number(m.id) + '">'
                + escapeHtml(m.codigo)
                + ' · '
                + escapeHtml(m.nombre)
                + '</option>';
        }).join('');

        const base = estado.monedas.find(function (m) {
            return Number(m.es_base) === 1;
        });

        if (base) {
            $('cotizacionMoneda').value = String(base.id);
        }

        $('cotizacionVigencia').value = datos.vigencia_sugerida || '';
    }

    async function cargarCotizaciones() {
        const params = new URLSearchParams({
            cotizaciones_api: '1',
            accion: 'LISTAR_COTIZACIONES',
            pagina: String(estado.pagina),
            por_pagina: String(estado.porPagina),
            busqueda: $('buscarCotizacion').value.trim(),
            estado: $('filtroEstado').value,
            desde: $('filtroDesde').value,
            hasta: $('filtroHasta').value
        });

        $('tablaCotizaciones').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());

        estado.cotizaciones = datos.cotizaciones || [];
        estado.totalPaginas = datos.paginacion.total_paginas || 1;
        estado.pagina = datos.paginacion.pagina || 1;

        renderCotizaciones();
        renderPaginacion(datos.paginacion);
        renderKpis(datos.kpis || {});
    }

    function renderKpis(k) {
        $('kpiTotal').textContent = Number(k.total || 0);
        $('kpiBorradores').textContent = Number(k.borradores || 0);
        $('kpiGeneradas').textContent = Number(k.generadas || 0);
        $('kpiAceptadas').textContent = Number(k.aceptadas || 0);
        $('kpiVencidas').textContent = Number(k.vencidas || 0);
    }

    function renderPaginacion(p) {
        $('textoPagina').textContent =
            Number(p.total_registros || 0) + ' registro(s)';

        $('paginaActual').textContent =
            'Página ' + Number(p.pagina || 1)
            + ' de ' + Number(p.total_paginas || 1);

        $('btnAnterior').disabled =
            Number(p.pagina || 1) <= 1;

        $('btnSiguiente').disabled =
            Number(p.pagina || 1) >= Number(p.total_paginas || 1);
    }

    function renderCotizaciones() {
        if (!estado.cotizaciones.length) {
            $('tablaCotizaciones').innerHTML =
                '<tr><td colspan="8" class="empty-cell">No hay cotizaciones con esos filtros.</td></tr>';
            return;
        }

        $('tablaCotizaciones').innerHTML = estado.cotizaciones.map(function (c) {
            const ev = estadoVisual(c.estado);
            let acciones =
                '<button type="button" class="table-action" data-action="ver" data-id="' + c.id + '">Ver</button>';

            if (puedeCrear && c.estado === 'BORRADOR') {
                acciones +=
                    '<button type="button" class="table-action" data-action="editar" data-id="' + c.id + '">Editar</button>'
                    + '<button type="button" class="table-action table-action--success" data-action="generar" data-id="' + c.id + '">Generar</button>';
            }

            if (puedeCrear && c.estado === 'GENERADA') {
                acciones +=
                    '<button type="button" class="table-action table-action--success" data-action="aceptar" data-id="' + c.id + '">Aceptar</button>'
                    + '<button type="button" class="table-action table-action--danger" data-action="rechazar" data-id="' + c.id + '">Rechazar</button>';
            }

            acciones +=
                '<a class="table-action table-action--link" target="_blank" href="cotizacion_imprimir.php?id='
                + c.id
                + '">Imprimir</a>';

            const conversion = c.venta_folio
                ? '<small class="cell-secondary">Venta: ' + escapeHtml(c.venta_folio) + '</small>'
                : (c.apartado_folio
                    ? '<small class="cell-secondary">Apartado: ' + escapeHtml(c.apartado_folio) + '</small>'
                    : '');

            return ''
                + '<tr>'
                + '<td><strong>' + escapeHtml(c.folio) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(c.moneda_codigo) + '</small></td>'
                + '<td><strong>' + escapeHtml(c.cliente_nombre_snapshot || '—') + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(c.cliente_codigo || '') + '</small></td>'
                + '<td>' + fechaHora(c.fecha_cotizacion)
                + '<small class="cell-secondary">Vence: ' + fechaCorta(c.vigencia_hasta) + '</small></td>'
                + '<td>' + status(ev[0], ev[1]) + conversion + '</td>'
                + '<td>' + Number(c.renglones || 0) + '</td>'
                + '<td><strong>' + moneda(c.total, c.moneda_codigo, c.moneda_simbolo) + '</strong></td>'
                + '<td>' + escapeHtml(c.creado_por || '—') + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    /* ==========================================================
       NUEVA / EDICIÓN
       ========================================================== */

    function resetCotizacion() {
        estado.clienteSeleccionado = null;
        estado.lineas = [];
        estado.lineaSecuencia = 1;
        estado.editando = false;
        estado.modoGuardar = 'BORRADOR';

        $('formCotizacion').reset();
        $('cotizacionId').value = '';
        $('cotizacionFolio').value = 'Se generará al guardar';
        $('buscarClienteCotizacion').value = '';
        $('cotizacionClienteId').value = '';
        $('buscarProductoCotizacion').value = '';
        $('cotizacionObservaciones').value = '';

        const base = estado.monedas.find(function (m) {
            return Number(m.es_base) === 1;
        });

        if (base) {
            $('cotizacionMoneda').value = String(base.id);
        }

        const hoy = new Date();
        hoy.setDate(hoy.getDate() + 7);
        $('cotizacionVigencia').value = hoy.toISOString().slice(0, 10);

        $('resultadosClientes').hidden = true;
        $('resultadosProductos').hidden = true;

        renderClienteResumen();
        renderLineas();
        recalcularTotales();
        ocultarMensaje($('mensajeCotizacion'));

        $('tituloModalCotizacion').textContent = 'Nueva cotización';
        $('subtituloModalCotizacion').textContent =
            'El folio se genera automáticamente al guardar.';
    }

    function nuevaCotizacion() {
        resetCotizacion();
        abrirModal('modalCotizacion');
        setTimeout(function () {
            $('buscarClienteCotizacion').focus();
        }, 60);
    }

    async function editarCotizacion(id) {
        resetCotizacion();

        const datos = await api(
            '?cotizaciones_api=1&accion=DETALLE_COTIZACION&cotizacion_id='
            + encodeURIComponent(id)
        );

        const c = datos.cotizacion;

        if (c.estado !== 'BORRADOR') {
            throw new Error('Solo los borradores pueden editarse.');
        }

        estado.editando = true;
        $('cotizacionId').value = String(c.id);
        $('cotizacionFolio').value = c.folio;
        $('cotizacionMoneda').value = String(c.moneda_id);
        $('cotizacionVigencia').value = String(c.vigencia_hasta || '').slice(0, 10);
        $('cotizacionObservaciones').value = c.observaciones || '';

        estado.clienteSeleccionado = {
            id: c.cliente_id,
            codigo: c.cliente_codigo || '',
            nombre_razon_social: c.cliente_nombre_snapshot || '',
            rfc: c.cliente_rfc_actual || '',
            nivel_cliente_id: c.nivel_cliente_id,
            nivel_codigo: c.nivel_codigo || '',
            nivel_nombre: c.nivel_nombre || 'Sin clasificación',
            descuento_default_pct: c.descuento_default_pct || 0,
            descuento_personal_pct: c.descuento_personal_pct,
            descuento_efectivo_pct: Number(c.descuento_actual_cliente || 0),
            origen_descuento: c.descuento_personal_pct !== null
                ? 'ESPECIAL'
                : 'NIVEL'
        };

        $('buscarClienteCotizacion').value =
            (c.cliente_codigo ? c.cliente_codigo + ' · ' : '')
            + (c.cliente_nombre_snapshot || '');

        $('cotizacionClienteId').value = String(c.cliente_id || '');

        estado.lineas = [];

        for (const d of datos.detalles) {
            const presentaciones = await obtenerPresentaciones(d.producto_id);

            estado.lineas.push({
                uid: estado.lineaSecuencia++,
                producto_id: d.producto_id,
                sku: d.sku || '',
                producto_nombre: d.producto_nombre_snapshot,
                unidad_base_codigo: d.unidad_base_codigo || '',
                unidad_base_simbolo: d.unidad_base_simbolo || '',
                disponible_base: d.disponible_base_actual || 0,
                presentaciones: presentaciones.presentaciones || [],
                presentacion_id: Number(d.presentacion_id || 0),
                cantidad: Number(d.cantidad || 0),
                precio_unitario: Number(d.precio_unitario || 0),
                precio_venta_id: 0,
                precio_origen: 'HISTORICO',
                precio_revision: 0,
                nivel_precio: 'HISTÓRICO',
                descuento_pct: Number(c.descuento_actual_cliente || 0),
                impuesto_pct: Number(d.impuesto_pct_snapshot || 0),
                impuesto_nombre: 'Impuesto histórico',
                tasa_impuesto_id: d.tasa_impuesto_id,
                factor: Number(d.factor_a_unidad_base || 1),
                unidad_nombre: d.unidad_nombre_snapshot || '',
                unidad_simbolo: d.unidad_simbolo || ''
            });
        }

        renderClienteResumen();
        renderLineas();
        recalcularTotales();

        $('tituloModalCotizacion').textContent = 'Editar ' + c.folio;
        $('subtituloModalCotizacion').textContent =
            'Al guardar se recalculan importes usando los valores visibles del borrador.';

        abrirModal('modalCotizacion');
    }

    /* ==========================================================
       CLIENTE
       ========================================================== */

    async function buscarClientes(texto) {
        if (texto.trim().length < 2) {
            $('resultadosClientes').hidden = true;
            return;
        }

        const datos = await api(
            '?cotizaciones_api=1&accion=BUSCAR_CLIENTES&q='
            + encodeURIComponent(texto.trim())
        );

        const clientes = datos.clientes || [];

        if (!clientes.length) {
            $('resultadosClientes').innerHTML =
                '<div class="smart-result smart-result--empty">No se encontraron clientes activos.</div>';
            $('resultadosClientes').hidden = false;
            return;
        }

        $('resultadosClientes').innerHTML = clientes.map(function (c) {
            return '<button type="button" class="smart-result" data-client-id="' + c.id + '"'
                + ' data-client-json="' + encodeURIComponent(JSON.stringify(c)) + '">'
                + '<strong>' + escapeHtml(c.codigo) + ' · ' + escapeHtml(c.nombre_razon_social) + '</strong>'
                + '<small>' + escapeHtml(c.nivel_nombre || 'Sin clasificación')
                + ' · descuento ' + porcentaje(c.descuento_efectivo_pct)
                + (c.rfc ? ' · RFC ' + escapeHtml(c.rfc) : '')
                + '</small>'
                + '</button>';
        }).join('');

        $('resultadosClientes').hidden = false;
    }

    function seleccionarCliente(cliente) {
        estado.clienteSeleccionado = cliente;
        $('cotizacionClienteId').value = String(cliente.id);
        $('buscarClienteCotizacion').value =
            cliente.codigo + ' · ' + cliente.nombre_razon_social;
        $('resultadosClientes').hidden = true;

        const descuento = Number(cliente.descuento_efectivo_pct || 0);

        estado.lineas.forEach(function (linea) {
            linea.descuento_pct = descuento;
        });

        renderClienteResumen();
        renderLineas();
        recalcularTotales();
    }

    function renderClienteResumen() {
        const c = estado.clienteSeleccionado;

        if (!c) {
            $('clienteNombreResumen').textContent = 'Ninguno';
            $('clienteCodigoResumen').textContent =
                'Selecciona un cliente para aplicar su descuento.';
            $('clienteNivelResumen').textContent = '—';
            $('clienteDescuentoResumen').textContent = '0.00%';
            $('clienteOrigenDescuento').textContent = 'Sin cliente seleccionado.';
            return;
        }

        $('clienteNombreResumen').textContent = c.nombre_razon_social || '—';
        $('clienteCodigoResumen').textContent =
            (c.codigo || '') + (c.rfc ? ' · RFC ' + c.rfc : '');
        $('clienteNivelResumen').textContent =
            c.nivel_nombre || c.nivel_codigo || 'Sin clasificación';
        $('clienteDescuentoResumen').textContent =
            porcentaje(c.descuento_efectivo_pct || 0);

        if (c.origen_descuento === 'ESPECIAL') {
            $('clienteOrigenDescuento').textContent =
                'Descuento especial del cliente. Sustituye al de su clasificación.';
        } else {
            $('clienteOrigenDescuento').textContent =
                'Descuento de la clasificación del cliente.';
        }
    }

    /* ==========================================================
       PRODUCTOS Y LÍNEAS
       ========================================================== */

    async function buscarProductos(texto) {
        if (texto.trim().length < 2) {
            $('resultadosProductos').hidden = true;
            return;
        }

        const datos = await api(
            '?cotizaciones_api=1&accion=BUSCAR_PRODUCTOS&q='
            + encodeURIComponent(texto.trim())
        );

        const productos = datos.productos || [];

        if (!productos.length) {
            $('resultadosProductos').innerHTML =
                '<div class="smart-result smart-result--empty">No se encontraron productos disponibles para venta.</div>';
            $('resultadosProductos').hidden = false;
            return;
        }

        $('resultadosProductos').innerHTML = productos.map(function (p) {
            return '<button type="button" class="smart-result"'
                + ' data-product-json="' + encodeURIComponent(JSON.stringify(p)) + '">'
                + '<strong>' + escapeHtml(p.sku) + ' · ' + escapeHtml(p.nombre) + '</strong>'
                + '<small>' + escapeHtml(p.tipo.replaceAll('_', ' '))
                + ' · Disponible hoy: ' + numero(p.disponible_base, 2)
                + ' ' + escapeHtml(p.unidad_base_simbolo)
                + '</small>'
                + '</button>';
        }).join('');

        $('resultadosProductos').hidden = false;
    }

    async function obtenerPresentaciones(productoId) {
        return api(
            '?cotizaciones_api=1&accion=PRESENTACIONES_PRODUCTO&producto_id='
            + encodeURIComponent(productoId)
        );
    }

    async function agregarProducto(producto) {
        if (!estado.clienteSeleccionado) {
            mostrarMensaje(
                $('mensajeCotizacion'),
                'Selecciona primero el cliente para que el descuento se aplique correctamente.',
                'error'
            );
            return;
        }

        ocultarMensaje($('mensajeCotizacion'));

        const datos = await obtenerPresentaciones(producto.id);
        const presentaciones = datos.presentaciones || [];

        if (!presentaciones.length) {
            mostrarMensaje(
                $('mensajeCotizacion'),
                'No fue posible determinar una unidad de venta para este producto.',
                'error'
            );
            return;
        }

        const primera = presentaciones[0];

        const yaExiste = estado.lineas.some(function (l) {
            return Number(l.producto_id) === Number(producto.id)
                && Number(l.presentacion_id) === Number(primera.id);
        });

        if (yaExiste) {
            mostrarMensaje(
                $('mensajeCotizacion'),
                'Ese producto con esa presentación ya está agregado. Modifica su cantidad.',
                'error'
            );
            return;
        }

        const linea = {
            uid: estado.lineaSecuencia++,
            producto_id: Number(producto.id),
            sku: producto.sku,
            producto_nombre: producto.nombre,
            unidad_base_codigo: producto.unidad_base_codigo,
            unidad_base_simbolo: producto.unidad_base_simbolo,
            disponible_base: Number(producto.disponible_base || 0),
            presentaciones: presentaciones,
            presentacion_id: Number(primera.id),
            cantidad: 1,
            precio_unitario: 0,
            precio_venta_id: 0,
            precio_origen: 'BUSCANDO',
            precio_revision: 0,
            nivel_precio: '—',
            descuento_pct: Number(estado.clienteSeleccionado.descuento_efectivo_pct || 0),
            impuesto_pct: Number(producto.impuesto_pct || 0),
            impuesto_nombre: producto.impuesto_nombre || 'Sin impuesto',
            tasa_impuesto_id: producto.tasa_impuesto_id,
            factor: Number(primera.factor_a_unidad_base || 1),
            unidad_nombre: primera.unidad_nombre,
            unidad_simbolo: primera.unidad_simbolo
        };

        estado.lineas.push(linea);
        $('buscarProductoCotizacion').value = '';
        $('resultadosProductos').hidden = true;

        renderLineas();
        await actualizarPrecioLinea(linea.uid, true);
    }

    function buscarLinea(uid) {
        return estado.lineas.find(function (l) {
            return Number(l.uid) === Number(uid);
        }) || null;
    }

    async function actualizarPrecioLinea(uid, forzar) {
        const linea = buscarLinea(uid);

        if (!linea) return;

        const monedaId = Number($('cotizacionMoneda').value || 0);

        if (!monedaId || !linea.producto_id || Number(linea.cantidad) <= 0) {
            return;
        }

        // Si el usuario decidió capturar un precio manual, cambiar la cantidad
        // no debe volver a apropiarse del renglón ni sobrescribirlo después.
        // Un cambio de presentación/moneda sí usa forzar=true y vuelve a buscar.
        if (
            !forzar
            && linea.precio_origen === 'MANUAL'
            && Number(linea.precio_unitario) > 0
        ) {
            recalcularTotales();
            return;
        }

        /*
         * presentacion_id = 0 representa la unidad base virtual.
         * El backend ya sabe resolverla como pv.presentacion_id IS NULL,
         * por lo que también debe consultar precios automáticos.
         */
        linea.precio_revision = Number(linea.precio_revision || 0) + 1;
        const revisionPrecio = linea.precio_revision;

        linea.precio_origen = 'BUSCANDO';
        renderLineas();

        try {
            const params = new URLSearchParams({
                cotizaciones_api: '1',
                accion: 'SUGERIR_PRECIO',
                producto_id: String(linea.producto_id),
                presentacion_id: String(linea.presentacion_id),
                moneda_id: String(monedaId),
                cantidad: String(linea.cantidad)
            });

            const datos = await api('?' + params.toString());

            // Evita que una respuesta vieja pise el precio de una cantidad,
            // presentación o moneda que el usuario ya cambió.
            if (revisionPrecio !== Number(linea.precio_revision || 0)) {
                return;
            }

            if (datos.precio !== null && typeof datos.precio !== 'undefined') {
                if (forzar || linea.precio_venta_id > 0 || Number(linea.precio_unitario) <= 0) {
                    linea.precio_unitario = Number(datos.precio);
                }

                linea.precio_venta_id = Number(datos.precio_venta_id || 0);
                linea.precio_origen = datos.origen || 'CONFIGURADO';
                linea.nivel_precio = datos.nivel_precio || '—';
            } else {
                if (forzar && linea.precio_origen !== 'HISTORICO') {
                    linea.precio_unitario = 0;
                }

                linea.precio_venta_id = 0;
                linea.precio_origen = 'SIN_CONFIGURAR';
                linea.nivel_precio = '—';
            }

            linea.tasa_impuesto_id = datos.tasa_impuesto_id;
            linea.impuesto_pct = Number(datos.impuesto_pct || 0);
            linea.impuesto_nombre = datos.impuesto_nombre || 'Sin impuesto';

        } catch (error) {
            linea.precio_venta_id = 0;
            linea.precio_origen = 'MANUAL';
            mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
        }

        renderLineas();
        recalcularTotales();
    }

    function renderLineas() {
        if (!estado.lineas.length) {
            $('tablaLineasCotizacion').innerHTML =
                '<tr><td colspan="8" class="empty-cell">Agrega al menos un producto.</td></tr>';
            recalcularTotales();
            return;
        }

        $('tablaLineasCotizacion').innerHTML = estado.lineas.map(function (l) {
            const presentaciones = l.presentaciones.map(function (p) {
                return '<option value="' + Number(p.id) + '"'
                    + (Number(p.id) === Number(l.presentacion_id) ? ' selected' : '')
                    + '>'
                    + escapeHtml(p.nombre)
                    + ' · ' + escapeHtml(p.unidad_simbolo)
                    + '</option>';
            }).join('');

            const importeBruto = Number(l.cantidad || 0) * Number(l.precio_unitario || 0);
            const descuento = importeBruto * Number(l.descuento_pct || 0) / 100;
            const subtotal = importeBruto - descuento;
            const impuesto = subtotal * Number(l.impuesto_pct || 0) / 100;
            const total = subtotal + impuesto;

            let origen = 'Precio manual';

            if (l.precio_origen === 'CONFIGURADO') {
                origen = 'Precio configurado · ' + (l.nivel_precio || '');
            } else if (l.precio_origen === 'CONVERTIDO') {
                origen = 'Precio configurado convertido · ' + (l.nivel_precio || '');
            } else if (l.precio_origen === 'BUSCANDO') {
                origen = 'Buscando precio...';
            } else if (l.precio_origen === 'HISTORICO') {
                origen = 'Precio histórico del borrador';
            } else if (l.precio_origen === 'SIN_CONFIGURAR') {
                origen = 'Sin precio configurado · captura manual o ve a Productos > Precios de venta';
            }

            return ''
                + '<tr data-linea-uid="' + l.uid + '">'
                + '<td><strong>' + escapeHtml(l.producto_nombre) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(l.sku)
                + ' · disponible actual: ' + numero(l.disponible_base, 2)
                + ' ' + escapeHtml(l.unidad_base_simbolo) + '</small></td>'
                + '<td><select class="line-input line-presentacion" data-uid="' + l.uid + '">' + presentaciones + '</select></td>'
                + '<td><input type="number" class="line-input line-cantidad" data-uid="' + l.uid + '" min="0.000001" step="0.000001" value="' + l.cantidad + '"></td>'
                + '<td><div class="price-cell">'
                + '<input type="number" class="line-input line-precio" data-uid="' + l.uid + '" min="0.0001" step="0.0001" value="' + Number(l.precio_unitario || 0).toFixed(4) + '">'
                + '<small>' + escapeHtml(origen) + '</small>'
                + '</div></td>'
                + '<td><strong>' + porcentaje(l.descuento_pct) + '</strong>'
                + '<small class="cell-secondary">Automático</small></td>'
                + '<td>' + porcentaje(l.impuesto_pct)
                + '<small class="cell-secondary">' + escapeHtml(l.impuesto_nombre || '') + '</small></td>'
                + '<td><strong>' + moneda(total, monedaActual().codigo, monedaActual().simbolo) + '</strong></td>'
                + '<td><button type="button" class="table-action table-action--danger line-remove" data-uid="' + l.uid + '">Quitar</button></td>'
                + '</tr>';
        }).join('');
    }

    function recalcularTotales() {
        let bruto = 0;
        let descuento = 0;
        let subtotal = 0;
        let impuesto = 0;
        let total = 0;

        estado.lineas.forEach(function (l) {
            const b = Number(l.cantidad || 0) * Number(l.precio_unitario || 0);
            const d = b * Number(l.descuento_pct || 0) / 100;
            const s = b - d;
            const i = s * Number(l.impuesto_pct || 0) / 100;

            bruto += b;
            descuento += d;
            subtotal += s;
            impuesto += i;
            total += s + i;
        });

        const m = monedaActual();

        $('totalBruto').textContent = moneda(bruto, m.codigo, m.simbolo);
        $('totalDescuento').textContent = '-' + moneda(descuento, m.codigo, m.simbolo);
        $('totalSubtotal').textContent = moneda(subtotal, m.codigo, m.simbolo);
        $('totalImpuesto').textContent = moneda(impuesto, m.codigo, m.simbolo);
        $('totalFinal').textContent = moneda(total, m.codigo, m.simbolo);
    }

    async function cambiarPresentacion(uid, presentacionId) {
        const l = buscarLinea(uid);
        if (!l) return;

        const nueva = l.presentaciones.find(function (p) {
            return Number(p.id) === Number(presentacionId);
        });

        if (!nueva) return;

        const duplicada = estado.lineas.some(function (otra) {
            return Number(otra.uid) !== Number(uid)
                && Number(otra.producto_id) === Number(l.producto_id)
                && Number(otra.presentacion_id) === Number(presentacionId);
        });

        if (duplicada) {
            mostrarMensaje(
                $('mensajeCotizacion'),
                'Ese producto ya está agregado con la presentación seleccionada.',
                'error'
            );
            renderLineas();
            return;
        }

        l.presentacion_id = Number(nueva.id);
        l.factor = Number(nueva.factor_a_unidad_base || 1);
        l.unidad_nombre = nueva.unidad_nombre;
        l.unidad_simbolo = nueva.unidad_simbolo;
        l.precio_venta_id = 0;
        l.precio_unitario = 0;

        await actualizarPrecioLinea(uid, true);
    }

    function quitarLinea(uid) {
        estado.lineas = estado.lineas.filter(function (l) {
            return Number(l.uid) !== Number(uid);
        });

        renderLineas();
        recalcularTotales();
    }

    /* ==========================================================
       GUARDAR Y ESTADOS
       ========================================================== */

    async function guardarCotizacion(event) {
        event.preventDefault();

        if (!puedeCrear) return;

        if (!estado.clienteSeleccionado || !$('cotizacionClienteId').value) {
            mostrarMensaje($('mensajeCotizacion'), 'Selecciona un cliente.', 'error');
            return;
        }

        if (!estado.lineas.length) {
            mostrarMensaje($('mensajeCotizacion'), 'Agrega al menos un producto.', 'error');
            return;
        }

        const invalidas = estado.lineas.filter(function (l) {
            return Number(l.cantidad) <= 0 || Number(l.precio_unitario) <= 0;
        });

        if (invalidas.length) {
            mostrarMensaje(
                $('mensajeCotizacion'),
                'Revisa las cantidades y precios. Todos deben ser mayores que cero.',
                'error'
            );
            return;
        }

        ocultarMensaje($('mensajeCotizacion'));

        const lineas = estado.lineas.map(function (l) {
            return {
                producto_id: l.producto_id,
                presentacion_id: l.presentacion_id,
                cantidad: l.cantidad,
                precio_unitario: l.precio_unitario,
                precio_venta_id: l.precio_venta_id
            };
        });

        try {
            const datos = await post('GUARDAR_BORRADOR', {
                cotizacion_id: $('cotizacionId').value,
                cliente_id: $('cotizacionClienteId').value,
                moneda_id: $('cotizacionMoneda').value,
                vigencia_hasta: $('cotizacionVigencia').value,
                observaciones: $('cotizacionObservaciones').value,
                lineas: JSON.stringify(lineas)
            });

            $('cotizacionId').value = String(datos.cotizacion_id);
            $('cotizacionFolio').value = datos.folio;

            if (estado.modoGuardar === 'GENERAR') {
                await post('GENERAR_COTIZACION', {
                    cotizacion_id: datos.cotizacion_id
                });

                cerrarModal('modalCotizacion');

                mostrarMensaje(
                    $('mensajePagina'),
                    'Cotización ' + datos.folio + ' guardada y generada correctamente.',
                    'success'
                );
            } else {
                cerrarModal('modalCotizacion');
                mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
            }

            await cargarCotizaciones();

        } catch (error) {
            mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
        }
    }

    async function cambiarEstadoCotizacion(id, accion, mensajeConfirmacion) {
        if (!window.confirm(mensajeConfirmacion)) {
            return;
        }

        try {
            const datos = await post(accion, {
                cotizacion_id: id
            });

            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
            await cargarCotizaciones();

        } catch (error) {
            mostrarErrorGlobal(error);
        }
    }

    /* ==========================================================
       DETALLE
       ========================================================== */

    async function verDetalle(id) {
        $('contenidoDetalleCotizacion').innerHTML =
            '<div class="empty-cell">Cargando...</div>';

        abrirModal('modalDetalleCotizacion');

        try {
            const datos = await api(
                '?cotizaciones_api=1&accion=DETALLE_COTIZACION&cotizacion_id='
                + encodeURIComponent(id)
            );

            const c = datos.cotizacion;
            const detalles = datos.detalles || [];
            const ev = estadoVisual(c.estado);

            $('tituloDetalleCotizacion').textContent = c.folio;
            $('subtituloDetalleCotizacion').textContent =
                (c.cliente_nombre_snapshot || 'Sin cliente') + ' · ' + fechaHora(c.fecha_cotizacion);

            $('btnImprimirDetalle').href =
                'cotizacion_imprimir.php?id=' + encodeURIComponent(c.id);

            const lineasHtml = detalles.map(function (d) {
                return '<tr>'
                    + '<td><strong>' + escapeHtml(d.producto_nombre_snapshot) + '</strong>'
                    + '<small class="cell-secondary">' + escapeHtml(d.sku || '') + '</small></td>'
                    + '<td>' + escapeHtml(d.presentacion_nombre || d.unidad_nombre_snapshot) + '</td>'
                    + '<td>' + numero(d.cantidad, 6).replace(/0+$/, '').replace(/\.$/, '') + '</td>'
                    + '<td>' + moneda(d.precio_unitario, c.moneda_codigo, c.moneda_simbolo) + '</td>'
                    + '<td>' + porcentaje(d.descuento_pct) + '</td>'
                    + '<td>' + porcentaje(d.impuesto_pct_snapshot) + '</td>'
                    + '<td><strong>' + moneda(d.total, c.moneda_codigo, c.moneda_simbolo) + '</strong></td>'
                    + '</tr>';
            }).join('');

            let conversion = '';

            if (c.venta_folio) {
                conversion = '<div class="conversion-banner">Convertida a venta <strong>'
                    + escapeHtml(c.venta_folio) + '</strong>.</div>';
            } else if (c.apartado_folio) {
                conversion = '<div class="conversion-banner">Convertida a apartado <strong>'
                    + escapeHtml(c.apartado_folio) + '</strong>.</div>';
            } else if (c.estado === 'ACEPTADA') {
                conversion = '<div class="conversion-banner conversion-banner--pending">'
                    + 'Cotización aceptada. Está lista para convertirse a venta o apartado cuando se ejecute ese flujo comercial.'
                    + '</div>';
            }

            $('contenidoDetalleCotizacion').innerHTML =
                '<section class="detail-summary-grid">'
                + '<div><span>Cliente</span><strong>' + escapeHtml(c.cliente_nombre_snapshot || '—') + '</strong>'
                + '<small>' + escapeHtml(c.cliente_codigo || '') + '</small></div>'
                + '<div><span>Estado</span><strong>' + status(ev[0], ev[1]) + '</strong></div>'
                + '<div><span>Moneda</span><strong>' + escapeHtml(c.moneda_codigo) + '</strong>'
                + '<small>TC a base: ' + numero(c.tipo_cambio_a_base, 8) + '</small></div>'
                + '<div><span>Vigencia</span><strong>' + fechaCorta(c.vigencia_hasta) + '</strong></div>'
                + '</section>'
                + conversion
                + '<div class="table-wrap"><table class="module-table detail-lines-table">'
                + '<thead><tr><th>Producto</th><th>Presentación</th><th>Cantidad</th><th>Precio</th><th>Desc.</th><th>IVA</th><th>Total</th></tr></thead>'
                + '<tbody>' + lineasHtml + '</tbody></table></div>'
                + '<div class="detail-bottom">'
                + '<div><h3>Observaciones</h3><p>' + escapeHtml(c.observaciones || 'Sin observaciones.') + '</p></div>'
                + '<section class="totals-card">'
                + '<div><span>Descuento</span><strong>-' + moneda(c.descuento_total, c.moneda_codigo, c.moneda_simbolo) + '</strong></div>'
                + '<div><span>Subtotal</span><strong>' + moneda(c.subtotal, c.moneda_codigo, c.moneda_simbolo) + '</strong></div>'
                + '<div><span>Impuestos</span><strong>' + moneda(c.impuesto_total, c.moneda_codigo, c.moneda_simbolo) + '</strong></div>'
                + '<div class="totals-card__grand"><span>Total</span><strong>' + moneda(c.total, c.moneda_codigo, c.moneda_simbolo) + '</strong></div>'
                + '</section>'
                + '</div>';

        } catch (error) {
            $('contenidoDetalleCotizacion').innerHTML =
                '<div class="module-message module-message--error">'
                + escapeHtml(error.message)
                + '</div>';
        }
    }

    /* ==========================================================
       EVENTOS
       ========================================================== */

    $('btnNuevaCotizacion')?.addEventListener('click', nuevaCotizacion);

    document.querySelectorAll('[data-cerrar-modal]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            cerrarModal(boton.dataset.cerrarModal);
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                cerrarModal(modal.id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;

        document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(function (modal) {
            cerrarModal(modal.id);
        });
    });

    $('buscarCotizacion').addEventListener('input', function () {
        clearTimeout(estado.timerListado);
        estado.timerListado = setTimeout(function () {
            estado.pagina = 1;
            cargarCotizaciones().catch(mostrarErrorGlobal);
        }, 350);
    });

    ['filtroEstado', 'filtroDesde', 'filtroHasta'].forEach(function (id) {
        $(id).addEventListener('change', function () {
            estado.pagina = 1;
            cargarCotizaciones().catch(mostrarErrorGlobal);
        });
    });

    $('porPagina').addEventListener('change', function (event) {
        estado.porPagina = Number(event.target.value);
        estado.pagina = 1;
        cargarCotizaciones().catch(mostrarErrorGlobal);
    });

    $('btnAnterior').addEventListener('click', function () {
        if (estado.pagina <= 1) return;
        estado.pagina--;
        cargarCotizaciones().catch(mostrarErrorGlobal);
    });

    $('btnSiguiente').addEventListener('click', function () {
        if (estado.pagina >= estado.totalPaginas) return;
        estado.pagina++;
        cargarCotizaciones().catch(mostrarErrorGlobal);
    });

    $('tablaCotizaciones').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-action]');
        if (!boton) return;

        const id = Number(boton.dataset.id);

        switch (boton.dataset.action) {
            case 'ver':
                verDetalle(id);
                break;
            case 'editar':
                editarCotizacion(id).catch(mostrarErrorGlobal);
                break;
            case 'generar':
                cambiarEstadoCotizacion(
                    id,
                    'GENERAR_COTIZACION',
                    '¿Generar formalmente esta cotización? Después ya no podrá editarse.'
                );
                break;
            case 'aceptar':
                cambiarEstadoCotizacion(
                    id,
                    'ACEPTAR_COTIZACION',
                    '¿Confirmas que el cliente aceptó esta cotización?'
                );
                break;
            case 'rechazar':
                cambiarEstadoCotizacion(
                    id,
                    'RECHAZAR_COTIZACION',
                    '¿Confirmas que esta cotización fue rechazada?'
                );
                break;
        }
    });

    $('buscarClienteCotizacion').addEventListener('input', function () {
        const texto = this.value;

        if (
            estado.clienteSeleccionado
            && texto !== estado.clienteSeleccionado.codigo + ' · ' + estado.clienteSeleccionado.nombre_razon_social
        ) {
            estado.clienteSeleccionado = null;
            $('cotizacionClienteId').value = '';
            renderClienteResumen();
        }

        clearTimeout(estado.timerCliente);
        estado.timerCliente = setTimeout(function () {
            buscarClientes(texto).catch(function (error) {
                mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
            });
        }, 300);
    });

    $('resultadosClientes').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-client-json]');
        if (!boton) return;

        const cliente = JSON.parse(decodeURIComponent(boton.dataset.clientJson));
        seleccionarCliente(cliente);
    });

    $('buscarProductoCotizacion').addEventListener('input', function () {
        const texto = this.value;

        clearTimeout(estado.timerProducto);
        estado.timerProducto = setTimeout(function () {
            buscarProductos(texto).catch(function (error) {
                mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
            });
        }, 300);
    });

    $('resultadosProductos').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-product-json]');
        if (!boton) return;

        const producto = JSON.parse(decodeURIComponent(boton.dataset.productJson));

        agregarProducto(producto).catch(function (error) {
            mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
        });
    });

    $('tablaLineasCotizacion').addEventListener('change', function (event) {
        const uid = Number(event.target.dataset.uid || 0);
        const linea = buscarLinea(uid);

        if (!linea) return;

        if (event.target.classList.contains('line-presentacion')) {
            cambiarPresentacion(uid, Number(event.target.value)).catch(function (error) {
                mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
            });
            return;
        }

        if (event.target.classList.contains('line-cantidad')) {
            linea.cantidad = Number(event.target.value || 0);

            actualizarPrecioLinea(uid, false).catch(function (error) {
                mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
            });
            return;
        }

        if (event.target.classList.contains('line-precio')) {
            linea.precio_unitario = Number(event.target.value || 0);
            linea.precio_revision = Number(linea.precio_revision || 0) + 1;
            linea.precio_venta_id = 0;
            linea.precio_origen = 'MANUAL';
            linea.nivel_precio = 'MANUAL';
            renderLineas();
            recalcularTotales();
        }
    });

    $('tablaLineasCotizacion').addEventListener('input', function (event) {
        const uid = Number(event.target.dataset.uid || 0);
        const linea = buscarLinea(uid);

        if (!linea) return;

        if (event.target.classList.contains('line-cantidad')) {
            linea.cantidad = Number(event.target.value || 0);
            recalcularTotales();
        }

        if (event.target.classList.contains('line-precio')) {
            linea.precio_unitario = Number(event.target.value || 0);
            linea.precio_revision = Number(linea.precio_revision || 0) + 1;
            linea.precio_venta_id = 0;
            linea.precio_origen = 'MANUAL';
            linea.nivel_precio = 'MANUAL';
            recalcularTotales();
        }
    });

    $('tablaLineasCotizacion').addEventListener('click', function (event) {
        const boton = event.target.closest('.line-remove');
        if (!boton) return;
        quitarLinea(Number(boton.dataset.uid));
    });

    $('cotizacionMoneda').addEventListener('change', function () {
        estado.lineas.forEach(function (l) {
            l.precio_unitario = 0;
            l.precio_revision = Number(l.precio_revision || 0) + 1;
            l.precio_venta_id = 0;
            l.precio_origen = 'BUSCANDO';
        });

        renderLineas();
        recalcularTotales();

        Promise.all(
            estado.lineas.map(function (l) {
                return actualizarPrecioLinea(l.uid, true);
            })
        ).catch(function (error) {
            mostrarMensaje($('mensajeCotizacion'), error.message, 'error');
        });

        $('tipoCambioResumen').textContent =
            Number(monedaActual().es_base) === 1
                ? '1.00000000'
                : 'Se determina al guardar';
    });

    $('formCotizacion').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-modo-guardar]');
        if (!boton) return;
        estado.modoGuardar = boton.dataset.modoGuardar || 'BORRADOR';
    });

    $('formCotizacion').addEventListener('submit', guardarCotizacion);

    async function iniciar() {
        try {
            await cargarCatalogos();
            await cargarCotizaciones();
        } catch (error) {
            mostrarErrorGlobal(error);
        }
    }

    iniciar();
})();
</script>
</body>
</html>
