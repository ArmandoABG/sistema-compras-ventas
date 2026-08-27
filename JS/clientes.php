<?php

declare(strict_types=1);

if (isset($_GET['clientes_api'])) {
    $endpoint = __DIR__ . '/../funciones/clientes_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/clientes_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('clientes.ver', false);

$tituloPagina = 'Clientes';
$csrfToken = si_token_csrf();
$puedeAdministrar = si_tiene_permiso('clientes.administrar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_clientes.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'directorio')));
if (!in_array($seccionInicial, ['directorio', 'clasificacion', 'credito'], true)) {
    $seccionInicial = 'directorio';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Clientes | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_clientes.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content clientes-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">GESTIÓN COMERCIAL · CLIENTES</p>
                    <h1>Clientes</h1>
                    <p>Directorio comercial, clasificación de descuentos y condiciones de crédito en un solo módulo.</p>
                </div>
            </header>

            <nav class="module-tabs" aria-label="Clientes">
                <button type="button" class="module-tab" data-seccion="directorio">Directorio</button>
                <button type="button" class="module-tab" data-seccion="clasificacion">Clasificación</button>
                <button type="button" class="module-tab" data-seccion="credito">Crédito</button>
            </nav>

            <div id="mensajePagina" class="module-message" hidden></div>

            <!-- =====================================================
                 DIRECTORIO
                 ===================================================== -->
            <section id="seccionDirectorio" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Directorio de clientes</h2>
                        <p>El código se genera automáticamente. Los datos fiscales solo se capturan cuando realmente se necesitan.</p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevoCliente">Nuevo cliente</button>
                    <?php endif; ?>
                </div>

                <section class="stats-grid stats-grid--5">
                    <article><span>Total</span><strong id="kpiClientesTotal">0</strong></article>
                    <article><span>Activos</span><strong id="kpiClientesActivos">0</strong></article>
                    <article><span>Con crédito</span><strong id="kpiClientesCredito">0</strong></article>
                    <article><span>Solo contado</span><strong id="kpiClientesContado">0</strong></article>
                    <article><span>Inactivos</span><strong id="kpiClientesInactivos">0</strong></article>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--clients">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarCliente" maxlength="180" placeholder="Código, nombre, RFC, teléfono o correo" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Clasificación</span>
                            <select id="filtroNivelCliente">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Crédito</span>
                            <select id="filtroCreditoCliente">
                                <option value="TODOS">Todos</option>
                                <option value="CON_CREDITO">Con crédito</option>
                                <option value="SIN_CREDITO">Solo contado</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoCliente">
                                <option value="TODOS">Todos</option>
                                <option value="ACTIVOS">Activos</option>
                                <option value="INACTIVOS">Inactivos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaCliente">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--clients">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Cliente</th>
                                    <th>Clasificación</th>
                                    <th>Descuento</th>
                                    <th>Contacto</th>
                                    <th>Crédito</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaClientes">
                                <tr><td colspan="8" class="empty-cell">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaCliente">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnClienteAnterior">Anterior</button>
                            <span id="paginaClienteActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnClienteSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 CLASIFICACIÓN
                 ===================================================== -->
            <section id="seccionClasificacion" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Clasificación comercial</h2>
                        <p>General, Distinguido y Preferencial son las categorías oficiales. Aquí se configura el descuento predeterminado de cada una.</p>
                    </div>
                </div>

                <div class="info-banner">
                    <strong>Regla:</strong>
                    el porcentaje no está escrito directamente en el código. Se toma de la base de datos y puede modificarse aquí.
                    Un cliente puede tener un descuento especial que sustituye al de su clasificación.
                </div>

                <section class="classification-grid" id="tarjetasNiveles">
                    <div class="empty-card">Cargando clasificaciones...</div>
                </section>
            </section>

            <!-- =====================================================
                 CRÉDITO
                 ===================================================== -->
            <section id="seccionCredito" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Crédito de clientes</h2>
                        <p>Las condiciones se configuran en el cliente. El uso real se alimentará automáticamente de las cuentas por cobrar de ventas a crédito.</p>
                    </div>
                </div>

                <section class="stats-grid stats-grid--5">
                    <article><span>Clientes con crédito</span><strong id="kpiCreditoClientes">0</strong></article>
                    <article><span>Línea autorizada</span><strong id="kpiCreditoLimite">$0.00</strong></article>
                    <article><span>Crédito utilizado</span><strong id="kpiCreditoUsado">$0.00</strong></article>
                    <article><span>Disponible</span><strong id="kpiCreditoDisponible">$0.00</strong></article>
                    <article><span>Excedidos</span><strong id="kpiCreditoExcedidos">0</strong></article>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--credit">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input type="search" id="buscarCredito" maxlength="180" placeholder="Código, nombre o RFC" autocomplete="off">
                        </label>

                        <label class="field">
                            <span>Situación</span>
                            <select id="filtroCreditoSituacion">
                                <option value="TODOS">Todos</option>
                                <option value="CON_CREDITO">Con crédito habilitado</option>
                                <option value="SIN_CREDITO">Solo contado</option>
                                <option value="CERCA_LIMITE">80% o más del límite</option>
                                <option value="EXCEDIDO">Límite excedido</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaCredito">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--credit">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Clasificación</th>
                                    <th>Días</th>
                                    <th>Límite</th>
                                    <th>Utilizado</th>
                                    <th>Disponible</th>
                                    <th>CxC abiertas</th>
                                    <th>Situación</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaCredito">
                                <tr><td colspan="9" class="empty-cell">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaCredito">0 registros</span>
                        <div>
                            <button type="button" class="btn-secondary" id="btnCreditoAnterior">Anterior</button>
                            <span id="paginaCreditoActual">Página 1 de 1</span>
                            <button type="button" class="btn-secondary" id="btnCreditoSiguiente">Siguiente</button>
                        </div>
                    </footer>
                </section>
            </section>
        </main>
    </div>
</div>

<!-- ================================================================
     MODAL CLIENTE
     ================================================================ -->
<div class="modal-backdrop" id="modalCliente" hidden>
    <section class="modal-card modal-card--xl" role="dialog" aria-modal="true" aria-labelledby="tituloModalCliente">
        <header class="modal-header">
            <div>
                <small>DIRECTORIO COMERCIAL</small>
                <h2 id="tituloModalCliente">Nuevo cliente</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalCliente" aria-label="Cerrar">×</button>
        </header>

        <form id="formCliente">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_CLIENTE">
            <input type="hidden" name="cliente_id" id="clienteId">

            <div id="mensajeCliente" class="module-message module-message--error" hidden></div>

            <div class="form-section-title">
                <strong>Datos principales</strong>
                <span>Lo indispensable para identificar al cliente.</span>
            </div>

            <div class="form-grid form-grid--3">
                <label class="field">
                    <span>Código</span>
                    <input type="text" id="clienteCodigo" value="Se genera al guardar" readonly>
                    <small>Ejemplo: CLI-000001.</small>
                </label>

                <label class="field field--span-2">
                    <span>Nombre / razón social *</span>
                    <input type="text" name="nombre_razon_social" id="clienteNombre" maxlength="180" required autocomplete="organization">
                </label>

                <label class="field">
                    <span>RFC <em>opcional</em></span>
                    <input type="text" name="rfc" id="clienteRfc" maxlength="20" placeholder="Solo si se necesita" autocomplete="off">
                    <small id="ayudaRfc">Si lo capturas, el tipo de persona se detecta automáticamente.</small>
                </label>

                <label class="field">
                    <span>Tipo de persona</span>
                    <select name="tipo_persona" id="clienteTipoPersona">
                        <option value="NO_ESPECIFICADO">No especificado</option>
                        <option value="FISICA">Persona física</option>
                        <option value="MORAL">Persona moral</option>
                    </select>
                </label>

                <label class="field">
                    <span>Clasificación *</span>
                    <select name="nivel_cliente_id" id="clienteNivel" required></select>
                </label>
            </div>

            <div class="discount-helper" id="resumenDescuento">
                Descuento del nivel: 0.00% · Descuento efectivo: 0.00%
            </div>

            <div class="form-grid form-grid--3">
                <label class="field">
                    <span>Descuento especial (%) <em>opcional</em></span>
                    <input type="number" name="descuento_personal_pct" id="clienteDescuentoPersonal" min="0" max="100" step="0.01" placeholder="Usar descuento del nivel">
                    <small>Si queda vacío se usa el porcentaje de la clasificación.</small>
                </label>

                <label class="field">
                    <span>Teléfono</span>
                    <input type="tel" name="telefono" id="clienteTelefono" maxlength="40" autocomplete="tel">
                </label>

                <label class="field">
                    <span>Correo</span>
                    <input type="email" name="correo" id="clienteCorreo" maxlength="180" autocomplete="email">
                </label>
            </div>

            <div class="form-section-title">
                <strong>Condiciones de crédito</strong>
                <span>Si no se habilita, el cliente queda configurado para ventas de contado.</span>
            </div>

            <label class="smart-toggle">
                <input type="checkbox" name="credito_habilitado" value="1" id="clienteCreditoHabilitado">
                <span>
                    <strong>Permitir ventas a crédito</strong>
                    <small>Activa días y límite de crédito. El sistema no inventa estos valores.</small>
                </span>
            </label>

            <div class="form-grid form-grid--2 credit-fields" id="camposCredito">
                <label class="field">
                    <span>Días de crédito *</span>
                    <input type="number" name="dias_credito" id="clienteDiasCredito" min="1" max="365" step="1" placeholder="Ej. 30">
                </label>

                <label class="field">
                    <span>Límite de crédito *</span>
                    <div class="money-input">
                        <span id="simboloMonedaBase">$</span>
                        <input type="number" name="limite_credito" id="clienteLimiteCredito" min="0.01" step="0.01" placeholder="Ej. 50000.00">
                    </div>
                    <small id="ayudaMonedaCredito">Expresado en la moneda base del sistema.</small>
                </label>
            </div>

            <details class="optional-details">
                <summary>Dirección <span>opcional</span></summary>

                <div class="form-grid form-grid--3 details-body">
                    <label class="field field--span-2">
                        <span>Calle</span>
                        <input type="text" name="calle" id="clienteCalle" maxlength="180" autocomplete="street-address">
                    </label>

                    <label class="field">
                        <span>No. exterior</span>
                        <input type="text" name="numero_exterior" id="clienteNumeroExterior" maxlength="30">
                    </label>

                    <label class="field">
                        <span>No. interior</span>
                        <input type="text" name="numero_interior" id="clienteNumeroInterior" maxlength="30">
                    </label>

                    <label class="field">
                        <span>Colonia</span>
                        <input type="text" name="colonia" id="clienteColonia" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Municipio</span>
                        <input type="text" name="municipio" id="clienteMunicipio" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Estado</span>
                        <input type="text" name="estado" id="clienteEstadoDireccion" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Código postal</span>
                        <input type="text" name="codigo_postal" id="clienteCodigoPostal" maxlength="15" inputmode="numeric" autocomplete="postal-code">
                    </label>

                    <label class="field">
                        <span>País</span>
                        <input type="text" name="pais" id="clientePais" maxlength="80" value="México" autocomplete="country-name">
                    </label>
                </div>
            </details>

            <label class="field">
                <span>Observaciones</span>
                <textarea name="observaciones" id="clienteObservaciones" rows="3" maxlength="10000" placeholder="Notas comerciales relevantes para futuras operaciones"></textarea>
            </label>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalCliente">Cancelar</button>
                <button type="submit" class="btn-primary">Guardar cliente</button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL CLASIFICACIÓN
     ================================================================ -->
<div class="modal-backdrop" id="modalNivel" hidden>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="tituloModalNivel">
        <header class="modal-header">
            <div>
                <small>CLASIFICACIÓN COMERCIAL</small>
                <h2 id="tituloModalNivel">Editar clasificación</h2>
            </div>
            <button type="button" class="modal-close" data-cerrar-modal="modalNivel" aria-label="Cerrar">×</button>
        </header>

        <form id="formNivel">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_NIVEL">
            <input type="hidden" name="nivel_id" id="nivelId">

            <div id="mensajeNivel" class="module-message module-message--error" hidden></div>

            <div class="readonly-card">
                <span id="nivelCodigo">GENERAL</span>
                <strong id="nivelNombre">General</strong>
                <small id="nivelClientesAsignados">0 clientes asignados</small>
            </div>

            <label class="field">
                <span>Descuento predeterminado (%) *</span>
                <input type="number" name="descuento_default_pct" id="nivelDescuento" min="0" max="100" step="0.01" required>
                <small>Se aplicará a los clientes de este nivel que no tengan un descuento especial.</small>
            </label>

            <label class="smart-toggle">
                <input type="checkbox" name="activo" value="1" id="nivelActivo">
                <span>
                    <strong>Clasificación activa</strong>
                    <small>No podrá desactivarse mientras tenga clientes activos asignados.</small>
                </span>
            </label>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalNivel">Cancelar</button>
                <button type="submit" class="btn-primary">Guardar clasificación</button>
            </footer>
        </form>
    </section>
</div>

<script>
(function () {
    'use strict';

    const puedeAdministrar = <?= $puedeAdministrar ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const seccionInicial = <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const estado = {
        seccion: seccionInicial,
        niveles: [],
        monedaBase: null,
        clientes: [],
        paginaCliente: 1,
        porPaginaCliente: 20,
        totalPaginasCliente: 1,
        paginaCredito: 1,
        porPaginaCredito: 20,
        totalPaginasCredito: 1,
        timerCliente: null,
        timerCredito: null
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
        return new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: decimales || 0,
            maximumFractionDigits: decimales || 0
        }).format(Number.isFinite(n) ? n : 0);
    }

    function moneda(valor, codigo, simbolo) {
        const n = Number(valor || 0);
        const formato = new Intl.NumberFormat('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Number.isFinite(n) ? n : 0);

        return (simbolo || '$') + formato + (codigo ? ' ' + codigo : '');
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

    function botonAccion(texto, accion, id, valor, variante) {
        return '<button type="button" class="table-action'
            + (variante ? ' table-action--' + variante : '')
            + '" data-action="' + escapeHtml(accion) + '"'
            + ' data-id="' + Number(id) + '"'
            + (valor === null || typeof valor === 'undefined'
                ? ''
                : ' data-value="' + escapeHtml(valor) + '"')
            + '>' + escapeHtml(texto) + '</button>';
    }

    async function cargarCatalogos() {
        const datos = await api('?clientes_api=1&accion=CATALOGOS');

        estado.niveles = datos.niveles || [];
        estado.monedaBase = datos.moneda_base || null;

        renderSelectNiveles();
        renderMonedaBase();
    }

    function renderSelectNiveles() {
        $('filtroNivelCliente').innerHTML =
            '<option value="0">Todas</option>'
            + estado.niveles.map(function (n) {
                return '<option value="' + n.id + '">'
                    + escapeHtml(n.nombre)
                    + (n.activo === 1 ? '' : ' (inactiva)')
                    + '</option>';
            }).join('');

        const activos = estado.niveles.filter(function (n) {
            return n.activo === 1;
        });

        $('clienteNivel').innerHTML = activos.map(function (n) {
            return '<option value="' + n.id + '">'
                + escapeHtml(n.nombre)
                + ' · ' + porcentaje(n.descuento_default_pct)
                + '</option>';
        }).join('');
    }

    function renderMonedaBase() {
        const m = estado.monedaBase || {};
        $('simboloMonedaBase').textContent = m.simbolo || '$';
        $('ayudaMonedaCredito').textContent =
            'Expresado en ' + (m.codigo || 'la moneda base del sistema') + '.';
    }

    function cambiarSeccion(seccion) {
        estado.seccion = seccion;

        document.querySelectorAll('.module-section').forEach(function (section) {
            section.hidden = true;
        });

        document.querySelectorAll('.module-tab').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.dataset.seccion === seccion);
        });

        const mapa = {
            directorio: 'seccionDirectorio',
            clasificacion: 'seccionClasificacion',
            credito: 'seccionCredito'
        };

        $(mapa[seccion]).hidden = false;

        const url = new URL(window.location.href);
        url.searchParams.set('seccion', seccion);
        history.replaceState(null, '', url);

        cargarSeccionActual().catch(mostrarErrorGlobal);
    }

    async function cargarSeccionActual() {
        switch (estado.seccion) {
            case 'directorio':
                await cargarClientes();
                break;
            case 'clasificacion':
                await cargarNiveles();
                break;
            case 'credito':
                await cargarCredito();
                break;
        }
    }

    /* ==============================================================
       DIRECTORIO
       ============================================================== */

    async function cargarClientes() {
        const params = new URLSearchParams({
            clientes_api: '1',
            accion: 'LISTAR_CLIENTES',
            pagina: String(estado.paginaCliente),
            por_pagina: String(estado.porPaginaCliente),
            busqueda: $('buscarCliente').value.trim(),
            nivel_id: $('filtroNivelCliente').value,
            credito: $('filtroCreditoCliente').value,
            estado: $('filtroEstadoCliente').value
        });

        $('tablaClientes').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());

        estado.clientes = datos.clientes || [];
        estado.totalPaginasCliente = datos.paginacion.total_paginas || 1;

        renderClientes(estado.clientes);
        renderPaginaCliente(datos.paginacion);
        renderKpisClientes(datos.resumen || {});
    }

    function renderClientes(filas) {
        const tbody = $('tablaClientes');

        if (!filas.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty-cell">No se encontraron clientes.</td></tr>';
            return;
        }

        tbody.innerHTML = filas.map(function (c) {
            let acciones = '';

            if (puedeAdministrar) {
                acciones += botonAccion('Editar', 'editar-cliente', c.id);
                acciones += botonAccion(
                    c.activo === 1 ? 'Desactivar' : 'Activar',
                    'estado-cliente',
                    c.id,
                    c.activo === 1 ? 0 : 1,
                    c.activo === 1 ? 'danger' : 'success'
                );

                if (c.activo === 0) {
                }
            }

            const contacto = [c.telefono, c.correo].filter(Boolean);
            const credito = c.dias_credito > 0
                ? '<strong>' + c.dias_credito + ' días</strong>'
                    + '<small class="cell-secondary">Límite: '
                    + (c.limite_credito !== null
                        ? moneda(c.limite_credito, (estado.monedaBase || {}).codigo, (estado.monedaBase || {}).simbolo)
                        : 'sin definir')
                    + '</small>'
                : '<span class="muted-text">Contado</span>';

            return '<tr>'
                + '<td><strong>' + escapeHtml(c.codigo) + '</strong></td>'
                + '<td><strong>' + escapeHtml(c.nombre_razon_social) + '</strong>'
                + (c.rfc ? '<small class="cell-secondary">RFC ' + escapeHtml(c.rfc) + '</small>' : '')
                + '</td>'
                + '<td>' + escapeHtml(c.nivel_nombre || 'Sin clasificación') + '</td>'
                + '<td><strong>' + porcentaje(c.descuento_efectivo_pct) + '</strong>'
                + (c.descuento_personal_pct !== null ? '<small class="cell-secondary">Especial</small>' : '<small class="cell-secondary">Por nivel</small>')
                + '</td>'
                + '<td>' + (contacto.length ? contacto.map(escapeHtml).join('<br>') : '<span class="muted-text">—</span>') + '</td>'
                + '<td>' + credito + '</td>'
                + '<td>' + status(c.activo === 1 ? 'Activo' : 'Inactivo', c.activo === 1 ? 'active' : 'inactive') + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginaCliente(p) {
        $('textoPaginaCliente').textContent = p.total_registros + ' registro(s)';
        $('paginaClienteActual').textContent = 'Página ' + p.pagina + ' de ' + p.total_paginas;
        $('btnClienteAnterior').disabled = p.pagina <= 1;
        $('btnClienteSiguiente').disabled = p.pagina >= p.total_paginas;
    }

    function renderKpisClientes(r) {
        $('kpiClientesTotal').textContent = r.total || 0;
        $('kpiClientesActivos').textContent = r.activos || 0;
        $('kpiClientesCredito').textContent = r.con_credito || 0;
        $('kpiClientesContado').textContent = r.sin_credito || 0;
        $('kpiClientesInactivos').textContent = r.inactivos || 0;
    }

    function nuevoCliente() {
        $('formCliente').reset();
        $('clienteId').value = '';
        $('clienteCodigo').value = 'Se genera al guardar';
        $('tituloModalCliente').textContent = 'Nuevo cliente';
        $('clientePais').value = 'México';
        $('clienteTipoPersona').value = 'NO_ESPECIFICADO';

        const general = estado.niveles.find(function (n) {
            return n.codigo === 'GENERAL' && n.activo === 1;
        });

        if (general) {
            $('clienteNivel').value = String(general.id);
        }

        $('clienteCreditoHabilitado').checked = false;
        actualizarCamposCredito();
        actualizarResumenDescuento();
        ocultarMensaje($('mensajeCliente'));
        abrirModal('modalCliente');
    }

    async function editarCliente(id, enfocarCredito) {
        const datos = await api(
            '?clientes_api=1&accion=DETALLE_CLIENTE&id=' + encodeURIComponent(id)
        );

        const c = datos.cliente;
        $('formCliente').reset();
        $('clienteId').value = c.id;
        $('clienteCodigo').value = c.codigo || '';
        $('clienteNombre').value = c.nombre_razon_social || '';
        $('clienteRfc').value = c.rfc || '';
        $('clienteTipoPersona').value = c.tipo_persona || 'NO_ESPECIFICADO';

        asegurarNivelDisponible(c.nivel_cliente_id);
        $('clienteNivel').value = c.nivel_cliente_id || '';
        $('clienteDescuentoPersonal').value = c.descuento_personal_pct === null ? '' : c.descuento_personal_pct;
        $('clienteTelefono').value = c.telefono || '';
        $('clienteCorreo').value = c.correo || '';
        $('clienteCalle').value = c.calle || '';
        $('clienteNumeroExterior').value = c.numero_exterior || '';
        $('clienteNumeroInterior').value = c.numero_interior || '';
        $('clienteColonia').value = c.colonia || '';
        $('clienteMunicipio').value = c.municipio || '';
        $('clienteEstadoDireccion').value = c.estado || '';
        $('clienteCodigoPostal').value = c.codigo_postal || '';
        $('clientePais').value = c.pais || 'México';
        $('clienteObservaciones').value = c.observaciones || '';
        $('clienteCreditoHabilitado').checked = c.credito_habilitado === 1;
        $('clienteDiasCredito').value = c.dias_credito > 0 ? c.dias_credito : '';
        $('clienteLimiteCredito').value = c.limite_credito === null ? '' : c.limite_credito;
        $('tituloModalCliente').textContent = 'Editar cliente';

        actualizarCamposCredito();
        actualizarResumenDescuento();
        ocultarMensaje($('mensajeCliente'));
        abrirModal('modalCliente');

        if (enfocarCredito) {
            setTimeout(function () {
                $('clienteCreditoHabilitado').focus();
            }, 50);
        }
    }

    function asegurarNivelDisponible(nivelId) {
        if (!nivelId) {
            return;
        }

        const existe = Array.from($('clienteNivel').options).some(function (option) {
            return Number(option.value) === Number(nivelId);
        });

        if (existe) {
            return;
        }

        const nivel = estado.niveles.find(function (n) {
            return n.id === Number(nivelId);
        });

        if (nivel) {
            const option = document.createElement('option');
            option.value = String(nivel.id);
            option.textContent = nivel.nombre + ' (inactiva)';
            $('clienteNivel').appendChild(option);
        }
    }

    async function guardarCliente(event) {
        event.preventDefault();
        ocultarMensaje($('mensajeCliente'));

        const form = event.currentTarget;
        const boton = form.querySelector('button[type="submit"]');
        const textoOriginal = boton.textContent;
        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await api('?clientes_api=1', {
                method: 'POST',
                body: new FormData(form)
            });

            cerrarModal('modalCliente');
            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
            await cargarCatalogos();
            await cargarSeccionActual();

        } catch (error) {
            mostrarMensaje($('mensajeCliente'), error.message, 'error');
        } finally {
            boton.disabled = false;
            boton.textContent = textoOriginal;
        }
    }

    async function cambiarEstadoCliente(id, activo) {
        if (!window.confirm(activo === 1 ? '¿Activar este cliente?' : '¿Desactivar este cliente?')) {
            return;
        }

        await postSimple('CAMBIAR_ESTADO_CLIENTE', {
            cliente_id: id,
            activo: activo
        });

        await cargarCatalogos();
        await cargarSeccionActual();
    }

    function actualizarTipoPersonaDesdeRfc() {
        const rfc = $('clienteRfc').value
            .toUpperCase()
            .replace(/[\s-]/g, '');

        $('clienteRfc').value = rfc;

        if (rfc.length === 12) {
            $('clienteTipoPersona').value = 'MORAL';
            $('ayudaRfc').textContent = '12 caracteres: se detectó Persona moral.';
        } else if (rfc.length === 13) {
            $('clienteTipoPersona').value = 'FISICA';
            $('ayudaRfc').textContent = '13 caracteres: se detectó Persona física.';
        } else if (rfc.length === 0) {
            $('ayudaRfc').textContent = 'Si lo capturas, el tipo de persona se detecta automáticamente.';
        } else {
            $('ayudaRfc').textContent = 'RFC incompleto: debe tener 12 o 13 caracteres.';
        }
    }

    function actualizarCamposCredito() {
        const habilitado = $('clienteCreditoHabilitado').checked;
        const campos = [$('clienteDiasCredito'), $('clienteLimiteCredito')];

        campos.forEach(function (input) {
            input.disabled = !habilitado;
            input.required = habilitado;
        });

        $('camposCredito').classList.toggle('is-disabled', !habilitado);

        if (!habilitado) {
            $('clienteDiasCredito').value = '';
            $('clienteLimiteCredito').value = '';
        }
    }

    function actualizarResumenDescuento() {
        const nivelId = Number($('clienteNivel').value || 0);
        const nivel = estado.niveles.find(function (n) {
            return n.id === nivelId;
        });
        const descuentoNivel = nivel ? Number(nivel.descuento_default_pct || 0) : 0;
        const especialTexto = $('clienteDescuentoPersonal').value.trim();
        const especial = especialTexto === '' ? null : Number(especialTexto);
        const efectivo = especial === null || !Number.isFinite(especial) ? descuentoNivel : especial;

        $('resumenDescuento').textContent =
            'Descuento del nivel: ' + porcentaje(descuentoNivel)
            + ' · Descuento efectivo: ' + porcentaje(efectivo)
            + (especial !== null ? ' (especial)' : '');
    }

    /* ==============================================================
       CLASIFICACIÓN
       ============================================================== */

    async function cargarNiveles() {
        const datos = await api('?clientes_api=1&accion=LISTAR_NIVELES');
        estado.niveles = datos.niveles || [];
        renderNiveles();
        renderSelectNiveles();
    }

    function renderNiveles() {
        const contenedor = $('tarjetasNiveles');

        if (!estado.niveles.length) {
            contenedor.innerHTML = '<div class="empty-card">No hay clasificaciones configuradas.</div>';
            return;
        }

        contenedor.innerHTML = estado.niveles.map(function (n) {
            const clases = {
                GENERAL: 'level-card--general',
                DISTINGUIDO: 'level-card--distinguished',
                PREFERENCIAL: 'level-card--preferred'
            };

            return '<article class="level-card ' + (clases[n.codigo] || '') + '">'
                + '<div class="level-card__head">'
                + '<span>' + escapeHtml(n.codigo) + '</span>'
                + status(n.activo === 1 ? 'Activa' : 'Inactiva', n.activo === 1 ? 'active' : 'inactive')
                + '</div>'
                + '<h3>' + escapeHtml(n.nombre) + '</h3>'
                + '<div class="level-discount"><strong>' + porcentaje(n.descuento_default_pct) + '</strong><span>descuento predeterminado</span></div>'
                + '<div class="level-meta">'
                + '<span><strong>' + n.clientes_activos + '</strong> clientes activos</span>'
                + '<span><strong>' + n.clientes_asignados + '</strong> asignados en total</span>'
                + '</div>'
                + (puedeAdministrar
                    ? '<button type="button" class="btn-secondary level-edit" data-action="editar-nivel" data-id="' + n.id + '">Configurar</button>'
                    : '')
                + '</article>';
        }).join('');
    }

    function editarNivel(id) {
        const n = estado.niveles.find(function (fila) {
            return fila.id === Number(id);
        });

        if (!n) {
            mostrarMensaje($('mensajePagina'), 'No se encontró la clasificación.', 'error');
            return;
        }

        $('formNivel').reset();
        $('nivelId').value = n.id;
        $('nivelCodigo').textContent = n.codigo;
        $('nivelNombre').textContent = n.nombre;
        $('nivelDescuento').value = n.descuento_default_pct;
        $('nivelActivo').checked = n.activo === 1;
        $('nivelClientesAsignados').textContent =
            n.clientes_activos + ' cliente(s) activo(s) · '
            + n.clientes_asignados + ' asignado(s) en total';
        $('tituloModalNivel').textContent = 'Configurar ' + n.nombre;
        ocultarMensaje($('mensajeNivel'));
        abrirModal('modalNivel');
    }

    async function guardarNivel(event) {
        event.preventDefault();
        ocultarMensaje($('mensajeNivel'));

        const form = event.currentTarget;
        const boton = form.querySelector('button[type="submit"]');
        const textoOriginal = boton.textContent;
        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await api('?clientes_api=1', {
                method: 'POST',
                body: new FormData(form)
            });

            cerrarModal('modalNivel');
            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
            await cargarCatalogos();
            await cargarNiveles();

        } catch (error) {
            mostrarMensaje($('mensajeNivel'), error.message, 'error');
        } finally {
            boton.disabled = false;
            boton.textContent = textoOriginal;
        }
    }

    /* ==============================================================
       CRÉDITO
       ============================================================== */

    async function cargarCredito() {
        const params = new URLSearchParams({
            clientes_api: '1',
            accion: 'LISTAR_CREDITO',
            pagina: String(estado.paginaCredito),
            por_pagina: String(estado.porPaginaCredito),
            busqueda: $('buscarCredito').value.trim(),
            filtro: $('filtroCreditoSituacion').value
        });

        $('tablaCredito').innerHTML =
            '<tr><td colspan="9" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());

        estado.totalPaginasCredito = datos.paginacion.total_paginas || 1;
        estado.monedaBase = datos.moneda_base || estado.monedaBase;

        renderCredito(datos.clientes || []);
        renderPaginaCredito(datos.paginacion);
        renderKpisCredito(datos.resumen || {});
    }

    function renderCredito(filas) {
        const tbody = $('tablaCredito');
        const m = estado.monedaBase || {};

        if (!filas.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="empty-cell">No se encontraron clientes para este filtro.</td></tr>';
            return;
        }

        tbody.innerHTML = filas.map(function (c) {
            let situacion;

            switch (c.estado_credito) {
                case 'EXCEDIDO':
                    situacion = status('Excedido', 'danger');
                    break;
                case 'CERCA_LIMITE':
                    situacion = status('Cerca del límite', 'warning');
                    break;
                case 'SIN_CREDITO':
                    situacion = status('Solo contado', 'neutral');
                    break;
                default:
                    situacion = status('Normal', 'active');
            }

            const limite = c.limite_credito === null
                ? '—'
                : moneda(c.limite_credito, m.codigo, m.simbolo);

            const disponible = c.credito_disponible_base === null
                ? '—'
                : moneda(c.credito_disponible_base, m.codigo, m.simbolo);

            const accion = puedeAdministrar
                ? botonAccion('Configurar', 'configurar-credito', c.id)
                : '';

            return '<tr>'
                + '<td><strong>' + escapeHtml(c.nombre_razon_social) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(c.codigo) + (c.rfc ? ' · ' + escapeHtml(c.rfc) : '') + '</small></td>'
                + '<td>' + escapeHtml(c.nivel_nombre || 'Sin clasificación') + '</td>'
                + '<td>' + (c.dias_credito > 0 ? c.dias_credito + ' días' : '—') + '</td>'
                + '<td>' + limite + '</td>'
                + '<td>' + moneda(c.credito_usado_base, m.codigo, m.simbolo) + '</td>'
                + '<td>' + disponible + '</td>'
                + '<td>' + numero(c.cuentas_abiertas) + '</td>'
                + '<td>' + situacion + '</td>'
                + '<td class="text-right actions-cell">' + accion + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginaCredito(p) {
        $('textoPaginaCredito').textContent = p.total_registros + ' registro(s)';
        $('paginaCreditoActual').textContent = 'Página ' + p.pagina + ' de ' + p.total_paginas;
        $('btnCreditoAnterior').disabled = p.pagina <= 1;
        $('btnCreditoSiguiente').disabled = p.pagina >= p.total_paginas;
    }

    function renderKpisCredito(r) {
        const m = estado.monedaBase || {};
        $('kpiCreditoClientes').textContent = r.clientes_credito || 0;
        $('kpiCreditoLimite').textContent = moneda(r.limite_total || 0, m.codigo, m.simbolo);
        $('kpiCreditoUsado').textContent = moneda(r.usado_total || 0, m.codigo, m.simbolo);
        $('kpiCreditoDisponible').textContent = moneda(r.disponible_total || 0, m.codigo, m.simbolo);
        $('kpiCreditoExcedidos').textContent = r.excedidos || 0;
    }

    /* ==============================================================
       POST COMÚN
       ============================================================== */

    async function postSimple(accion, valores) {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', accion);

        Object.keys(valores).forEach(function (clave) {
            form.append(clave, String(valores[clave]));
        });

        try {
            const datos = await api('?clientes_api=1', {
                method: 'POST',
                body: form
            });

            mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
            return datos;

        } catch (error) {
            mostrarMensaje($('mensajePagina'), error.message, 'error');
            throw error;
        }
    }

    function mostrarErrorGlobal(error) {
        mostrarMensaje(
            $('mensajePagina'),
            error && error.message ? error.message : 'Ocurrió un error.',
            'error'
        );
    }

    /* ==============================================================
       EVENTOS
       ============================================================== */

    document.querySelectorAll('.module-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            cambiarSeccion(tab.dataset.seccion);
        });
    });

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
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(function (modal) {
            cerrarModal(modal.id);
        });
    });

    $('btnNuevoCliente')?.addEventListener('click', nuevoCliente);
    $('formCliente').addEventListener('submit', guardarCliente);
    $('formNivel').addEventListener('submit', guardarNivel);

    $('clienteRfc').addEventListener('input', actualizarTipoPersonaDesdeRfc);
    $('clienteCreditoHabilitado').addEventListener('change', actualizarCamposCredito);
    $('clienteNivel').addEventListener('change', actualizarResumenDescuento);
    $('clienteDescuentoPersonal').addEventListener('input', actualizarResumenDescuento);

    $('tablaClientes').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-action]');
        if (!boton) return;

        const id = Number(boton.dataset.id);

        switch (boton.dataset.action) {
            case 'editar-cliente':
                editarCliente(id, false).catch(mostrarErrorGlobal);
                break;
            case 'estado-cliente':
                cambiarEstadoCliente(id, Number(boton.dataset.value)).catch(function () {});
                break;
                break;
        }
    });

    $('tarjetasNiveles').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-action="editar-nivel"]');
        if (!boton) return;
        editarNivel(Number(boton.dataset.id));
    });

    $('tablaCredito').addEventListener('click', function (event) {
        const boton = event.target.closest('[data-action="configurar-credito"]');
        if (!boton) return;
        editarCliente(Number(boton.dataset.id), true).catch(mostrarErrorGlobal);
    });

    $('buscarCliente').addEventListener('input', function () {
        clearTimeout(estado.timerCliente);
        estado.timerCliente = setTimeout(function () {
            estado.paginaCliente = 1;
            cargarClientes().catch(mostrarErrorGlobal);
        }, 350);
    });

    ['filtroNivelCliente', 'filtroCreditoCliente', 'filtroEstadoCliente'].forEach(function (id) {
        $(id).addEventListener('change', function () {
            estado.paginaCliente = 1;
            cargarClientes().catch(mostrarErrorGlobal);
        });
    });

    $('porPaginaCliente').addEventListener('change', function (event) {
        estado.porPaginaCliente = Number(event.target.value);
        estado.paginaCliente = 1;
        cargarClientes().catch(mostrarErrorGlobal);
    });

    $('btnClienteAnterior').addEventListener('click', function () {
        if (estado.paginaCliente <= 1) return;
        estado.paginaCliente--;
        cargarClientes().catch(mostrarErrorGlobal);
    });

    $('btnClienteSiguiente').addEventListener('click', function () {
        if (estado.paginaCliente >= estado.totalPaginasCliente) return;
        estado.paginaCliente++;
        cargarClientes().catch(mostrarErrorGlobal);
    });

    $('buscarCredito').addEventListener('input', function () {
        clearTimeout(estado.timerCredito);
        estado.timerCredito = setTimeout(function () {
            estado.paginaCredito = 1;
            cargarCredito().catch(mostrarErrorGlobal);
        }, 350);
    });

    $('filtroCreditoSituacion').addEventListener('change', function () {
        estado.paginaCredito = 1;
        cargarCredito().catch(mostrarErrorGlobal);
    });

    $('porPaginaCredito').addEventListener('change', function (event) {
        estado.porPaginaCredito = Number(event.target.value);
        estado.paginaCredito = 1;
        cargarCredito().catch(mostrarErrorGlobal);
    });

    $('btnCreditoAnterior').addEventListener('click', function () {
        if (estado.paginaCredito <= 1) return;
        estado.paginaCredito--;
        cargarCredito().catch(mostrarErrorGlobal);
    });

    $('btnCreditoSiguiente').addEventListener('click', function () {
        if (estado.paginaCredito >= estado.totalPaginasCredito) return;
        estado.paginaCredito++;
        cargarCredito().catch(mostrarErrorGlobal);
    });

    async function iniciar() {
        try {
            await cargarCatalogos();
            cambiarSeccion(seccionInicial);
        } catch (error) {
            mostrarErrorGlobal(error);
        }
    }

    iniciar();
})();
</script>
</body>
</html>
