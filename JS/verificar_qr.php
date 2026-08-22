<?php

declare(strict_types=1);

if (isset($_GET['qr_api'])) {
    $endpoint = __DIR__ . '/../funciones/qr_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/qr_funciones.php.']);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('qr.verificar', false);


$tituloPagina = 'Verificar QR';
$csrfToken = si_token_csrf();
$tokenInicial = trim((string) ($_GET['token'] ?? $_GET['codigo'] ?? ''));

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_qr.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
$lectorFotoLocal = __DIR__ . '/../inc/si_qr_photo_reader.js';
$versionLectorFoto = is_file($lectorFotoLocal) ? (string) filemtime($lectorFotoLocal) : '1';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Verificar QR | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_qr.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>
        <main class="page-content qr-page">
            <header class="module-heading">
                <div>
                    <p class="module-eyebrow">TRAZABILIDAD · CONTROL DE SALIDA</p>
                    <h1>Verificar QR</h1>
                    <p>Consulta primero la venta. La salida solo se registra cuando un usuario la confirma expresamente.</p>
                </div>
            </header>

            <div class="info-banner">
                <strong>Flujo:</strong> escanear o buscar → revisar venta y productos → confirmar salida o registrar rechazo. Consultar un QR por sí solo no consume el código.
            </div>

            <div id="mensajePagina" class="module-message" hidden></div>

            <section class="stats-grid stats-grid--6 qr-stats">
                <article><span>Decisiones hoy</span><strong id="kpiDecisiones">0</strong></article>
                <article><span>Salidas confirmadas</span><strong id="kpiSalidas">0</strong></article>
                <article><span>Rechazos hoy</span><strong id="kpiRechazos">0</strong></article>
                <article><span>Incidencias</span><strong id="kpiIncidencias">0</strong></article>
                <article><span>QR activos</span><strong id="kpiTokensActivos">0</strong></article>
                <article><span>QR utilizados</span><strong id="kpiTokensUsados">0</strong></article>
            </section>

            <section class="qr-workspace">
                <article class="module-card qr-scan-card">
                    <div class="section-heading">
                        <div>
                            <h2>Consultar venta / QR</h2>
                            <p>Acepta QR completo, referencia corta o folio de venta como VTA-0000001.</p>
                        </div>
                    </div>
                    <form id="formVerificar" class="qr-scan-form" autocomplete="off">
                        <label class="field qr-code-field">
                            <span>QR, referencia o folio de venta</span>
                            <input type="text" id="codigoQr" maxlength="1200" placeholder="Escanea el QR o escribe VTA-0000001" autocomplete="off" spellcheck="false" autofocus>
                        </label>
                        <div class="qr-scan-actions">
                            <button type="submit" class="btn-primary" id="btnVerificar">Consultar</button>
                            <button type="button" class="btn-secondary" id="btnFotoQr">Tomar foto / elegir imagen</button>
                            <button type="button" class="btn-secondary" id="btnLimpiar">Limpiar</button>
                        </div>
                        <input type="file" id="archivoQr" accept="image/*" capture="environment" hidden>
                    </form>
                    <p class="qr-help">En celular usa “Tomar foto / elegir imagen”. La foto se procesa dentro del dispositivo, sin Internet. Con lector USB deja el cursor en el campo; si el lector envía Enter, la consulta se ejecuta automáticamente.</p>
                </article>

                <article class="module-card qr-result-card" id="resultadoCard">
                    <div class="qr-result-empty" id="resultadoVacio">
                        <strong>Esperando una consulta</strong>
                        <p>Escanea un QR o escribe el folio de una venta para revisar su estado antes de decidir la salida.</p>
                    </div>
                    <div id="resultadoContenido" hidden>
                        <header class="qr-result-header">
                            <div>
                                <span class="status-badge" id="resultadoBadge">—</span>
                                <h2 id="resultadoTitulo">Resultado</h2>
                                <p id="resultadoMensaje"></p>
                            </div>
                            <div class="qr-reference"><span>Referencia QR</span><strong id="resultadoToken">—</strong></div>
                        </header>

                        <section class="qr-sale-summary" id="resumenVentaQr"></section>
                        <div class="qr-warning" id="avisoVerificacionPrevia" hidden></div>

                        <section class="qr-admin-panel" id="panelRehabilitar" hidden>
                            <div>
                                <strong>Acción exclusiva del Administrador</strong>
                                <p>Si la salida se confirmó por error, puedes rehabilitar este mismo QR. La venta, pagos e inventario no se modifican; únicamente se revierte la marca de salida y la acción queda auditada.</p>
                            </div>
                            <button type="button" class="btn-secondary" id="btnMostrarRehabilitacion">Rehabilitar QR</button>
                        </section>

                        <section class="qr-admin-form" id="panelMotivoRehabilitacion" hidden>
                            <label class="field">
                                <span>Motivo de la rehabilitación</span>
                                <textarea id="motivoRehabilitacion" rows="3" maxlength="255" placeholder="Ej. Se confirmó la salida por error antes de entregar la mercancía"></textarea>
                                <small>Esta acción vuelve a habilitar el QR para una nueva revisión. El registro de la salida anterior se conserva en el historial y la rehabilitación queda en Auditoría.</small>
                            </label>
                            <div class="qr-reject-actions">
                                <button type="button" class="btn-danger" id="btnRehabilitarQr">Confirmar rehabilitación</button>
                                <button type="button" class="btn-secondary" id="btnCancelarRehabilitacion">Cancelar</button>
                            </div>
                        </section>

                        <div class="detail-section-heading"><h3>Productos de la venta</h3></div>
                        <div class="table-wrap">
                            <table class="module-table qr-products-table">
                                <thead><tr><th>Producto</th><th>Almacén</th><th>Cantidad</th><th>Unidad base</th><th class="text-right">Importe</th></tr></thead>
                                <tbody id="productosQr"><tr><td colspan="5" class="empty-cell">Sin información.</td></tr></tbody>
                            </table>
                        </div>

                        <section class="qr-decision-panel" id="panelDecision" hidden>
                            <div>
                                <strong>Decisión de salida</strong>
                                <p id="textoDecision">Revisa físicamente la mercancía antes de confirmar.</p>
                            </div>
                            <div class="qr-decision-actions">
                                <button type="button" class="btn-primary" id="btnConfirmarSalida">Confirmar salida</button>
                                <button type="button" class="btn-danger" id="btnMostrarRechazo">Rechazar salida</button>
                            </div>
                        </section>

                        <section class="qr-reject-panel" id="panelRechazo" hidden>
                            <label class="field">
                                <span>Motivo del rechazo</span>
                                <textarea id="motivoRechazo" rows="3" maxlength="255" placeholder="Ej. La cantidad física no coincide con el comprobante"></textarea>
                                <small>El rechazo queda registrado, pero no consume el QR. Podrá volver a consultarse después de resolver la incidencia.</small>
                            </label>
                            <div class="qr-reject-actions">
                                <button type="button" class="btn-danger" id="btnGuardarRechazo">Registrar rechazo</button>
                                <button type="button" class="btn-secondary" id="btnCancelarRechazo">Cancelar</button>
                            </div>
                        </section>
                    </div>
                </article>
            </section>

            <section class="module-card qr-history-card">
                <div class="section-heading section-heading--history">
                    <div><h2>Historial de decisiones</h2><p>Confirmaciones, rechazos y registros heredados del verificador anterior. Las simples consultas nuevas no llenan este historial.</p></div>
                </div>
                <div class="filters-grid qr-filters">
                    <label class="field field--search"><span>Buscar</span><input type="search" id="filtroBuscar" maxlength="180" placeholder="Venta, cliente o referencia QR"></label>
                    <label class="field"><span>Resultado</span><select id="filtroResultado"><option value="TODOS">Todos</option><option value="VALIDO">Salida confirmada</option><option value="RECHAZADO">Rechazada</option><option value="CONSULTADO">Consulta anterior</option><option value="YA_VERIFICADO">Ya verificado (anterior)</option><option value="NO_PAGADO">No pagado (anterior)</option><option value="CANCELADO">Cancelado (anterior)</option><option value="INVALIDO">Inválido (anterior)</option></select></label>
                    <label class="field"><span>Desde</span><input type="date" id="filtroDesde"></label>
                    <label class="field"><span>Hasta</span><input type="date" id="filtroHasta"></label>
                    <label class="field"><span>Por página</span><select id="porPagina"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>
                </div>
                <div class="table-wrap">
                    <table class="module-table qr-history-table">
                        <thead><tr><th>Fecha</th><th>Venta</th><th>Cliente</th><th>Resultado</th><th>QR</th><th>Usuario</th><th class="text-right">Acción</th></tr></thead>
                        <tbody id="tablaHistorial"><tr><td colspan="7" class="empty-cell">Cargando...</td></tr></tbody>
                    </table>
                </div>
                <footer class="pagination">
                    <span id="textoPagina">0 registros</span>
                    <div><button type="button" class="btn-secondary" id="btnAnterior">Anterior</button><span id="paginaActual">Página 1 de 1</span><button type="button" class="btn-secondary" id="btnSiguiente">Siguiente</button></div>
                </footer>
            </section>
        </main>
    </div>
</div>

<canvas id="canvasQr" hidden></canvas>

<!-- Lector fotográfico local: no usa CDN, Internet ni cámara en vivo. -->
<script src="../inc/si_qr_photo_reader.js?v=<?= si_escapar($versionLectorFoto) ?>"></script>
<script>
(function () {
    'use strict';

    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const tokenInicial = <?= json_encode($tokenInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const $ = (id) => document.getElementById(id);
    const estado = {
        pagina: 1,
        totalPaginas: 1,
        detector: null,
        codigoActual: '',
        puedeConfirmar: false,
        puedeRechazar: false,
        puedeRehabilitar: false
    };

    function escapeHtml(v) {
        return String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    }
    function numero(v, d = 2) { return Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: d, maximumFractionDigits: d }); }
    function moneda(v, codigo, simbolo) { return (simbolo || '$') + numero(v, 2) + (codigo ? ' ' + codigo : ''); }
    function fechaHora(v) { if (!v) return '—'; const d = new Date(String(v).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? v : d.toLocaleString('es-MX'); }
    function mostrarMensaje(id, texto, tipo) {
        const el = $(id); if (!el) return;
        if (!texto) { el.hidden = true; el.textContent = ''; el.className = 'module-message'; return; }
        el.hidden = false; el.textContent = texto; el.className = 'module-message module-message--' + (tipo || 'error');
    }
    function resultadoClase(r) {
        return ({LISTO:'warning',VALIDO:'success',RECHAZADO:'danger',CONSULTADO:'neutral',YA_VERIFICADO:'neutral',NO_PAGADO:'danger',CANCELADO:'danger',INVALIDO:'danger'})[r] || 'neutral';
    }
    function resultadoTexto(r) {
        return ({LISTO:'LISTO PARA REVISIÓN',VALIDO:'SALIDA CONFIRMADA',RECHAZADO:'SALIDA RECHAZADA',CONSULTADO:'CONSULTA ANTERIOR',YA_VERIFICADO:'SALIDA YA CONFIRMADA',NO_PAGADO:'NO AUTORIZAR',CANCELADO:'VENTA CANCELADA',INVALIDO:'NO VÁLIDO'})[r] || r;
    }
    function pagoTexto(v) {
        return ({PAGADO:'Pagado',CREDITO_PARCIAL:'Crédito con saldo',CREDITO_PENDIENTE:'Crédito autorizado',NO_PAGADO:'Pago incompleto'})[v] || v || '—';
    }

    async function apiGet(accion, params = {}) {
        const url = new URL(window.location.href); url.search = ''; url.searchParams.set('qr_api', '1'); url.searchParams.set('accion', accion);
        Object.entries(params).forEach(([k,v]) => { if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v)); });
        const r = await fetch(url.toString(), { headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, credentials:'same-origin' });
        const data = await r.json().catch(() => ({success:false,mensaje:'La respuesta del servidor no es válida.'}));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), {data,status:r.status});
        return data;
    }
    async function apiPost(accion, datos = {}) {
        const body = new URLSearchParams(); body.set('accion', accion); body.set('csrf_token', csrfToken);
        Object.entries(datos).forEach(([k,v]) => body.set(k, v === null || v === undefined ? '' : String(v)));
        const url = new URL(window.location.href); url.search = ''; url.searchParams.set('qr_api', '1');
        const r = await fetch(url.toString(), { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, credentials:'same-origin', body:body.toString() });
        const data = await r.json().catch(() => ({success:false,mensaje:'La respuesta del servidor no es válida.'}));
        if (!r.ok || !data.success) throw Object.assign(new Error(data.mensaje || 'No fue posible completar la operación.'), {data,status:r.status});
        return data;
    }

    async function cargarResumen() {
        try {
            const r = await apiGet('RESUMEN');
            $('kpiDecisiones').textContent = r.kpis.decisiones_hoy;
            $('kpiSalidas').textContent = r.kpis.salidas_hoy;
            $('kpiRechazos').textContent = r.kpis.rechazadas_hoy;
            $('kpiIncidencias').textContent = r.kpis.incidencias_hoy;
            $('kpiTokensActivos').textContent = r.kpis.tokens_activos;
            $('kpiTokensUsados').textContent = r.kpis.tokens_usados;
            if (!r.habilitado) mostrarMensaje('mensajePagina', 'La validación QR está deshabilitada en configuración.', 'warning');
        } catch (e) { mostrarMensaje('mensajePagina', e.message, 'error'); }
    }

    function ocultarDecisiones() {
        estado.puedeConfirmar = false;
        estado.puedeRechazar = false;
        estado.puedeRehabilitar = false;
        $('panelDecision').hidden = true;
        $('panelRechazo').hidden = true;
        $('panelRehabilitar').hidden = true;
        $('panelMotivoRehabilitacion').hidden = true;
        $('motivoRechazo').value = '';
        $('motivoRehabilitacion').value = '';
    }

    function renderResultado(r, permitirAcciones = true) {
        $('resultadoVacio').hidden = true;
        $('resultadoContenido').hidden = false;
        ocultarDecisiones();

        const resultado = r.resultado || (r.verificacion && r.verificacion.resultado) || 'INVALIDO';
        const clase = resultadoClase(resultado);
        $('resultadoBadge').className = 'status-badge status-badge--' + clase;
        $('resultadoBadge').textContent = resultadoTexto(resultado);
        $('resultadoTitulo').textContent = r.venta ? (r.venta.folio || 'Venta') : 'Código no reconocido';
        $('resultadoMensaje').textContent = r.mensaje || (r.verificacion && r.verificacion.observaciones) || 'Consulta realizada.';
        $('resultadoToken').textContent = (r.qr && r.qr.token_corto) || (r.verificacion && r.verificacion.token_corto) || '—';

        const v = r.venta;
        if (!v) {
            $('resumenVentaQr').innerHTML = '<div><span>Resultado</span><strong>No existe una venta asociada</strong><small>Revisa el código o el folio capturado.</small></div>';
            $('productosQr').innerHTML = '<tr><td colspan="5" class="empty-cell">No hay productos asociados.</td></tr>';
            $('avisoVerificacionPrevia').hidden = true;
            return;
        }

        $('resumenVentaQr').innerHTML = '<div><span>Cliente</span><strong>' + escapeHtml(v.cliente_nombre_snapshot || 'Público general') + '</strong><small>' + escapeHtml(v.cliente_rfc_snapshot || 'Sin RFC') + '</small></div>'
            + '<div><span>Fecha de venta</span><strong>' + escapeHtml(fechaHora(v.fecha_venta)) + '</strong><small>' + escapeHtml(v.condicion_pago) + '</small></div>'
            + '<div><span>Total</span><strong>' + moneda(v.total, v.moneda_codigo, v.moneda_simbolo) + '</strong><small>Cubierto ' + moneda(v.importe_cubierto, v.moneda_codigo, v.moneda_simbolo) + '</small></div>'
            + '<div><span>Estado de pago</span><strong>' + escapeHtml(pagoTexto(v.estado_pago)) + '</strong><small>Saldo ' + moneda(v.saldo_pago, v.moneda_codigo, v.moneda_simbolo) + '</small></div>'
            + '<div><span>Estado venta</span><strong>' + escapeHtml(v.estado) + '</strong><small>' + (v.cxc_folio ? 'CxC ' + escapeHtml(v.cxc_folio) : 'Sin cuenta por cobrar') + '</small></div>';

        const detalles = r.detalles || [];
        $('productosQr').innerHTML = detalles.length ? detalles.map((d) => '<tr><td><strong>' + escapeHtml(d.producto_nombre_snapshot) + '</strong><small class="cell-secondary">' + escapeHtml(d.sku_snapshot) + '</small></td><td>' + escapeHtml(d.almacen_nombre) + '</td><td>' + numero(d.cantidad, 3) + ' ' + escapeHtml(d.unidad_nombre_snapshot) + '</td><td>' + numero(d.cantidad_base, 3) + ' ' + escapeHtml(d.unidad_base_simbolo) + '</td><td class="text-right"><strong>' + moneda(d.total, v.moneda_codigo, v.moneda_simbolo) + '</strong></td></tr>').join('') : '<tr><td colspan="5" class="empty-cell">La venta no tiene productos.</td></tr>';

        const salida = r.salida_anterior || (r.qr && r.qr.usado_at ? {fecha_verificacion:r.qr.usado_at, verificado_por:r.qr.usado_por} : null);
        const rechazo = r.ultimo_rechazo || null;
        $('avisoVerificacionPrevia').hidden = true;
        if (resultado === 'YA_VERIFICADO' && salida) {
            $('avisoVerificacionPrevia').hidden = false;
            $('avisoVerificacionPrevia').innerHTML = '<strong>Salida ya registrada:</strong> ' + escapeHtml(fechaHora(salida.fecha_verificacion)) + ' por ' + escapeHtml(salida.verificado_por || 'usuario no disponible') + '. Este QR no puede autorizar otra salida.';
        } else if (rechazo && resultado !== 'RECHAZADO') {
            $('avisoVerificacionPrevia').hidden = false;
            $('avisoVerificacionPrevia').innerHTML = '<strong>Rechazo anterior:</strong> ' + escapeHtml(fechaHora(rechazo.fecha_verificacion)) + ' por ' + escapeHtml(rechazo.verificado_por || 'usuario no disponible') + '. ' + escapeHtml(rechazo.observaciones || 'Revisa la incidencia antes de continuar.');
        }

        if (permitirAcciones) {
            estado.puedeConfirmar = Boolean(r.puede_confirmar_salida);
            estado.puedeRechazar = Boolean(r.puede_rechazar_salida);
            if (estado.puedeConfirmar || estado.puedeRechazar) {
                $('panelDecision').hidden = false;
                $('btnConfirmarSalida').hidden = !estado.puedeConfirmar;
                $('btnMostrarRechazo').hidden = !estado.puedeRechazar;
                $('textoDecision').textContent = estado.puedeConfirmar
                    ? 'La venta pasó las validaciones del sistema. Compara físicamente la mercancía y confirma solo si todo coincide.'
                    : 'La salida no puede confirmarse; puedes registrar un rechazo para dejar trazabilidad de la incidencia.';
            }

            estado.puedeRehabilitar = Boolean(r.puede_rehabilitar_qr);
            if (estado.puedeRehabilitar) {
                $('panelRehabilitar').hidden = false;
            }
        }
    }

    async function consultar(codigo) {
        codigo = String(codigo || '').trim();
        if (!codigo) return mostrarMensaje('mensajePagina', 'Escanea o escribe un QR, referencia o folio de venta.', 'error');
        $('btnVerificar').disabled = true;
        mostrarMensaje('mensajePagina', '');
        try {
            const r = await apiPost('CONSULTAR', { codigo });
            estado.codigoActual = codigo;
            renderResultado(r, true);
            $('codigoQr').value = '';
            $('codigoQr').focus();
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
        } finally { $('btnVerificar').disabled = false; }
    }

    async function confirmarSalida() {
        if (!estado.codigoActual || !estado.puedeConfirmar) return;
        if (!window.confirm('¿Confirmar la salida física de esta venta? Después de confirmar, el QR no podrá utilizarse para otra salida.')) return;
        $('btnConfirmarSalida').disabled = true;
        $('btnMostrarRechazo').disabled = true;
        try {
            const r = await apiPost('CONFIRMAR_SALIDA', { codigo: estado.codigoActual });
            renderResultado(r, true);
            mostrarMensaje('mensajePagina', r.mensaje, r.resultado === 'VALIDO' ? 'success' : 'warning');
            await Promise.all([cargarResumen(), cargarHistorial()]);
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
        } finally {
            $('btnConfirmarSalida').disabled = false;
            $('btnMostrarRechazo').disabled = false;
        }
    }

    async function rechazarSalida() {
        if (!estado.codigoActual || !estado.puedeRechazar) return;
        const motivo = $('motivoRechazo').value.trim();
        if (motivo.length < 5) return mostrarMensaje('mensajePagina', 'Escribe un motivo de rechazo de al menos 5 caracteres.', 'error');
        $('btnGuardarRechazo').disabled = true;
        try {
            const r = await apiPost('RECHAZAR_SALIDA', { codigo: estado.codigoActual, motivo });
            renderResultado(r, false);
            mostrarMensaje('mensajePagina', r.mensaje, 'warning');
            await Promise.all([cargarResumen(), cargarHistorial()]);
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
        } finally { $('btnGuardarRechazo').disabled = false; }
    }


    async function rehabilitarQr() {
        if (!estado.codigoActual || !estado.puedeRehabilitar) return;

        const motivo = $('motivoRehabilitacion').value.trim();
        if (motivo.length < 5) {
            return mostrarMensaje(
                'mensajePagina',
                'Escribe un motivo de rehabilitación de al menos 5 caracteres.',
                'error'
            );
        }

        if (!window.confirm(
            '¿Rehabilitar este QR? Volverá a permitir una nueva confirmación de salida. ' +
            'La salida anterior seguirá registrada y esta acción quedará auditada.'
        )) return;

        $('btnRehabilitarQr').disabled = true;
        $('btnCancelarRehabilitacion').disabled = true;

        try {
            const r = await apiPost('REHABILITAR_QR', {
                codigo: estado.codigoActual,
                motivo
            });

            renderResultado(r, true);
            mostrarMensaje('mensajePagina', r.mensaje, 'success');

            if (r.rehabilitacion) {
                $('avisoVerificacionPrevia').hidden = false;
                $('avisoVerificacionPrevia').innerHTML =
                    '<strong>QR rehabilitado:</strong> ' +
                    escapeHtml(fechaHora(r.rehabilitacion.rehabilitado_at)) +
                    ' por ' + escapeHtml(r.rehabilitacion.rehabilitado_por || 'Administrador') +
                    '. Motivo: ' + escapeHtml(r.rehabilitacion.motivo || '—');
            }

            await Promise.all([cargarResumen(), cargarHistorial()]);
        } catch (e) {
            mostrarMensaje('mensajePagina', e.message, 'error');
        } finally {
            $('btnRehabilitarQr').disabled = false;
            $('btnCancelarRehabilitacion').disabled = false;
        }
    }

    async function cargarHistorial() {
        try {
            const r = await apiGet('HISTORIAL', {
                pagina: estado.pagina,
                por_pagina: $('porPagina').value,
                buscar: $('filtroBuscar').value.trim(),
                resultado: $('filtroResultado').value,
                desde: $('filtroDesde').value,
                hasta: $('filtroHasta').value
            });
            estado.pagina = r.paginacion.pagina;
            estado.totalPaginas = r.paginacion.total_paginas;
            const filas = r.verificaciones || [];
            $('tablaHistorial').innerHTML = filas.length ? filas.map((v) => '<tr><td>' + escapeHtml(fechaHora(v.fecha_verificacion)) + '</td><td><strong>' + escapeHtml(v.venta_folio) + '</strong><small class="cell-secondary">' + escapeHtml(v.condicion_pago) + '</small></td><td>' + escapeHtml(v.cliente_nombre_snapshot || 'Público general') + '</td><td><span class="status-badge status-badge--' + resultadoClase(v.resultado) + '">' + escapeHtml(resultadoTexto(v.resultado)) + '</span></td><td><code>' + escapeHtml(v.token_corto) + '</code></td><td>' + escapeHtml(v.verificado_por || '—') + '</td><td class="text-right"><button type="button" class="table-action" data-verificacion-id="' + v.id + '">Ver</button></td></tr>').join('') : '<tr><td colspan="7" class="empty-cell">No hay decisiones con estos filtros.</td></tr>';
            $('textoPagina').textContent = r.paginacion.total_registros + ' registro(s)';
            $('paginaActual').textContent = 'Página ' + estado.pagina + ' de ' + estado.totalPaginas;
            $('btnAnterior').disabled = estado.pagina <= 1;
            $('btnSiguiente').disabled = estado.pagina >= estado.totalPaginas;
        } catch (e) { mostrarMensaje('mensajePagina', e.message, 'error'); }
    }

    async function verHistorial(id) {
        try {
            const r = await apiGet('DETALLE_VERIFICACION', { id });
            estado.codigoActual = '';
            renderResultado({
                resultado: r.verificacion.resultado,
                mensaje: r.verificacion.observaciones,
                verificacion: r.verificacion,
                venta: r.venta,
                detalles: r.detalles,
                qr: { token_corto:r.verificacion.token_corto, usado_at:r.verificacion.usado_at, usado_por:r.verificacion.salida_confirmada_por },
                salida_anterior: r.verificacion.usado_at ? {fecha_verificacion:r.verificacion.usado_at, verificado_por:r.verificacion.salida_confirmada_por} : null
            }, false);
            window.scrollTo({ top: Math.max(0, $('resultadoCard').offsetTop - 20), behavior:'smooth' });
        } catch (e) { mostrarMensaje('mensajePagina', e.message, 'error'); }
    }

    async function prepararDetectorNativo() {
        if (!('BarcodeDetector' in window)) return null;
        try {
            const formatos = await BarcodeDetector.getSupportedFormats();
            if (!formatos.includes('qr_code')) return null;
            return new BarcodeDetector({ formats:['qr_code'] });
        } catch (_) { return null; }
    }

    async function leerQrDesdeCanvas(canvas) {
        if (estado.detector) {
            try {
                const detectados = await estado.detector.detect(canvas);
                if (detectados && detectados[0] && detectados[0].rawValue) return detectados[0].rawValue;
            } catch (_) {}
        }
        if (window.SiQrPhotoReader && typeof window.SiQrPhotoReader.decodeCanvas === 'function') {
            try {
                const encontrado = window.SiQrPhotoReader.decodeCanvas(canvas);
                if (encontrado) return encontrado;
            } catch (_) {}
        }
        return null;
    }

    async function decodificarArchivo(file) {
        if (!file) return;
        mostrarMensaje('mensajePagina', 'Procesando fotografía QR localmente...', 'warning');
        try {
            estado.detector = estado.detector || await prepararDetectorNativo();
            const canvas = $('canvasQr');
            let bitmap = null;
            if ('createImageBitmap' in window) {
                bitmap = await createImageBitmap(file);
                const maxLado = 2000;
                const escala = Math.min(1, maxLado / Math.max(bitmap.width, bitmap.height));
                canvas.width = Math.max(1, Math.round(bitmap.width * escala));
                canvas.height = Math.max(1, Math.round(bitmap.height * escala));
                canvas.getContext('2d', { willReadFrequently:true }).drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                if (bitmap.close) bitmap.close();
            } else {
                const url = URL.createObjectURL(file);
                try {
                    const img = new Image();
                    await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = reject; img.src = url; });
                    const maxLado = 2000;
                    const escala = Math.min(1, maxLado / Math.max(img.naturalWidth, img.naturalHeight));
                    canvas.width = Math.max(1, Math.round(img.naturalWidth * escala));
                    canvas.height = Math.max(1, Math.round(img.naturalHeight * escala));
                    canvas.getContext('2d', { willReadFrequently:true }).drawImage(img, 0, 0, canvas.width, canvas.height);
                } finally { URL.revokeObjectURL(url); }
            }
            const raw = await leerQrDesdeCanvas(canvas);
            if (!raw) {
                mostrarMensaje('mensajePagina', (window.SiQrPhotoReader || estado.detector) ? 'No se encontró un QR legible. Procura que el QR completo se vea dentro de la foto, con buena luz y sin reflejos.' : 'No se encontró el lector QR local. Reemplaza también inc/si_qr_photo_reader.js del parche.', 'warning');
                return;
            }
            mostrarMensaje('mensajePagina', 'QR leído correctamente. Consultando venta...', 'success');
            $('codigoQr').value = raw;
            await consultar(raw);
        } catch (_) {
            mostrarMensaje('mensajePagina', 'No fue posible procesar esa imagen. Intenta otra foto con el QR completo, enfocado y ocupando una parte clara de la imagen.', 'warning');
        } finally {
            $('archivoQr').value = '';
        }
    }

    function limpiar() {
        estado.codigoActual = '';
        ocultarDecisiones();
        $('codigoQr').value = '';
        $('resultadoContenido').hidden = true;
        $('resultadoVacio').hidden = false;
        mostrarMensaje('mensajePagina', '');
        $('codigoQr').focus();
    }

    let timerBuscar = null;
    $('formVerificar').addEventListener('submit', (e) => { e.preventDefault(); consultar($('codigoQr').value); });
    $('codigoQr').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); consultar($('codigoQr').value); } });
    $('btnLimpiar').addEventListener('click', limpiar);
    $('btnConfirmarSalida').addEventListener('click', confirmarSalida);
    $('btnMostrarRechazo').addEventListener('click', () => { $('panelRechazo').hidden = false; $('motivoRechazo').focus(); });
    $('btnGuardarRechazo').addEventListener('click', rechazarSalida);
    $('btnCancelarRechazo').addEventListener('click', () => { $('panelRechazo').hidden = true; $('motivoRechazo').value = ''; });
    $('btnMostrarRehabilitacion').addEventListener('click', () => {
        $('panelMotivoRehabilitacion').hidden = false;
        $('motivoRehabilitacion').focus();
    });
    $('btnRehabilitarQr').addEventListener('click', rehabilitarQr);
    $('btnCancelarRehabilitacion').addEventListener('click', () => {
        $('panelMotivoRehabilitacion').hidden = true;
        $('motivoRehabilitacion').value = '';
    });
    $('btnFotoQr').addEventListener('click', () => $('archivoQr').click());
    $('archivoQr').addEventListener('change', () => decodificarArchivo($('archivoQr').files && $('archivoQr').files[0]));
    $('tablaHistorial').addEventListener('click', (e) => { const b=e.target.closest('[data-verificacion-id]'); if(b) verHistorial(Number(b.dataset.verificacionId)); });
    $('btnAnterior').addEventListener('click', () => { if(estado.pagina>1){estado.pagina--;cargarHistorial();} });
    $('btnSiguiente').addEventListener('click', () => { if(estado.pagina<estado.totalPaginas){estado.pagina++;cargarHistorial();} });
    ['filtroResultado','filtroDesde','filtroHasta','porPagina'].forEach((id) => $(id).addEventListener('change', () => { estado.pagina=1;cargarHistorial(); }));
    $('filtroBuscar').addEventListener('input', () => { clearTimeout(timerBuscar); timerBuscar=setTimeout(() => {estado.pagina=1;cargarHistorial();},350); });

    Promise.all([cargarResumen(), cargarHistorial()]).then(() => {
        if (tokenInicial) { $('codigoQr').value = tokenInicial; consultar(tokenInicial); }
        else $('codigoQr').focus();
    });
})();
</script>
</body>
</html>
