<?php

declare(strict_types=1);

if (isset($_GET['usuarios_api'])) {
    $endpoint = __DIR__ . '/../funciones/usuarios_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/usuarios_funciones.php.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('usuarios.ver', false);

$csrfToken = si_token_csrf();
$tituloPagina = 'Usuarios';
$puedeCrear = si_tiene_permiso('usuarios.crear');
$puedeEditar = si_tiene_permiso('usuarios.editar');
$puedeDesactivar = si_tiene_permiso('usuarios.desactivar');
$puedeRoles = si_tiene_permiso('roles.administrar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_usuarios.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Usuarios | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_usuarios.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>
        <main class="page-content usuarios-page">
            <header class="usuarios-heading">
                <div>
                    <p class="usuarios-eyebrow">SEGURIDAD Y CONTROL</p>
                    <h1>Usuarios</h1>
                    <p>Administra cuentas, roles, estados, credenciales y sesiones del sistema.</p>
                </div>
                <div class="usuarios-heading__actions">
                    <button type="button" class="btn-secondary" id="btnActualizar">Actualizar</button>
                    <?php if ($puedeRoles): ?>
                        <a class="btn-secondary link-button" href="roles_permisos.php">Roles y permisos</a>
                    <?php endif; ?>
                    <?php if ($puedeCrear): ?>
                        <button type="button" class="btn-primary" id="btnNuevo">Nuevo usuario</button>
                    <?php endif; ?>
                </div>
            </header>

            <div id="estadoPagina" class="usuarios-message" hidden></div>

            <section class="usuarios-kpis">
                <article><span>Total</span><strong id="kpiTotal">0</strong></article>
                <article><span>Activos</span><strong id="kpiActivos">0</strong></article>
                <article><span>Inactivos</span><strong id="kpiInactivos">0</strong></article>
                <article><span>Bloqueados</span><strong id="kpiBloqueados">0</strong></article>
                <article><span>Sin ingreso</span><strong id="kpiSinIngreso">0</strong></article>
            </section>

            <section class="usuarios-card">
                <div class="usuarios-filtros">
                    <label class="field field--search"><span>Buscar</span><input type="search" id="filtroBusqueda" maxlength="120" placeholder="Nombre, usuario, correo o teléfono" autocomplete="off"></label>
                    <label class="field"><span>Estado</span><select id="filtroEstado"><option value="TODOS">Todos</option><option value="ACTIVOS">Activos</option><option value="INACTIVOS">Inactivos</option><option value="BLOQUEADOS">Bloqueados</option></select></label>
                    <label class="field"><span>Rol</span><select id="filtroRol"><option value="0">Todos los roles</option></select></label>
                    <label class="field"><span>Por página</span><select id="porPagina"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>
                </div>

                <div class="usuarios-table-wrap">
                    <table class="usuarios-table">
                        <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol(es)</th><th>Contacto</th><th>Estado</th><th>Último acceso</th><th class="text-right">Acciones</th></tr></thead>
                        <tbody id="tablaUsuarios"><tr><td colspan="7" class="empty-cell">Cargando usuarios...</td></tr></tbody>
                    </table>
                </div>

                <footer class="usuarios-paginacion">
                    <span id="textoPaginacion">0 registros</span>
                    <div><button type="button" class="btn-secondary" id="btnAnterior">Anterior</button><span id="paginaActual">Página 1 de 1</span><button type="button" class="btn-secondary" id="btnSiguiente">Siguiente</button></div>
                </footer>
            </section>
        </main>
    </div>
</div>

<div class="modal-backdrop" id="modalUsuario" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true" aria-labelledby="modalUsuarioTitulo">
        <header class="modal-header"><div><small>CUENTA DE ACCESO</small><h2 id="modalUsuarioTitulo">Nuevo usuario</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalUsuario" aria-label="Cerrar">×</button></header>
        <form id="formUsuario">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR">
            <input type="hidden" name="usuario_id" id="usuarioId" value="">
            <div id="mensajeFormUsuario" class="usuarios-message usuarios-message--error" hidden></div>

            <div class="form-grid">
                <label class="field"><span>Usuario *</span><input type="text" name="usuario" id="usuario" maxlength="60" required></label>
                <label class="field"><span>Nombres *</span><input type="text" name="nombres" id="nombres" maxlength="120" required></label>
                <label class="field"><span>Apellido paterno</span><input type="text" name="apellido_paterno" id="apellidoPaterno" maxlength="100"></label>
                <label class="field"><span>Apellido materno</span><input type="text" name="apellido_materno" id="apellidoMaterno" maxlength="100"></label>
                <label class="field"><span>Correo</span><input type="email" name="correo" id="correo" maxlength="180"></label>
                <label class="field"><span>Teléfono</span><input type="text" name="telefono" id="telefono" maxlength="30"></label>
            </div>

            <section class="form-section">
                <h3>Roles</h3>
                <p>Selecciona uno o más perfiles. Los permisos efectivos serán la unión de los roles asignados.</p>
                <div class="roles-grid" id="rolesFormulario"></div>
            </section>

            <section class="form-section" id="seccionPasswordNuevo">
                <h3>Contraseña inicial</h3>
                <div class="form-grid">
                    <label class="field"><span>Contraseña *</span><input type="password" name="password" id="password" minlength="10" maxlength="72" autocomplete="new-password"></label>
                    <label class="field"><span>Confirmar contraseña *</span><input type="password" name="confirmar_password" id="confirmarPassword" minlength="10" maxlength="72" autocomplete="new-password"></label>
                </div>
            </section>

            <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalUsuario">Cancelar</button><button type="submit" class="btn-primary" id="btnGuardarUsuario">Guardar usuario</button></footer>
        </form>
    </section>
</div>

<div class="modal-backdrop" id="modalPassword" hidden>
    <section class="modal-card" role="dialog" aria-modal="true">
        <header class="modal-header"><div><small>SEGURIDAD</small><h2>Restablecer contraseña</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalPassword">×</button></header>
        <form id="formPassword">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="CAMBIAR_PASSWORD">
            <input type="hidden" name="usuario_id" id="passwordUsuarioId">
            <div id="mensajePassword" class="usuarios-message usuarios-message--error" hidden></div>
            <label class="field"><span>Nueva contraseña *</span><input type="password" name="nueva_password" minlength="10" maxlength="72" required></label>
            <label class="field"><span>Confirmar nueva contraseña *</span><input type="password" name="confirmar_password" minlength="10" maxlength="72" required></label>
            <label class="field"><span>Tu contraseña actual *</span><input type="password" name="password_actor" autocomplete="current-password" required><small>Se solicita para autorizar el cambio de credenciales.</small></label>
            <footer class="modal-footer"><button type="button" class="btn-secondary" data-cerrar-modal="modalPassword">Cancelar</button><button type="submit" class="btn-primary">Actualizar contraseña</button></footer>
        </form>
    </section>
</div>

<div class="modal-backdrop" id="modalSesiones" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header"><div><small>CONTROL DE SESIONES</small><h2 id="tituloSesiones">Historial de sesiones</h2></div><button type="button" class="modal-close" data-cerrar-modal="modalSesiones">×</button></header>
        <div class="usuarios-table-wrap"><table class="usuarios-table"><thead><tr><th>Inicio</th><th>Fin</th><th>IP</th><th>Estado</th><th>Motivo</th></tr></thead><tbody id="tablaSesiones"><tr><td colspan="5">Cargando...</td></tr></tbody></table></div>
        <footer class="modal-footer modal-footer--sessions">
            <div class="session-pagination">
                <select id="porPaginaSesion" aria-label="Sesiones por página">
                    <option value="10">10</option>
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <button type="button" class="btn-secondary" id="btnSesionAnterior">Anterior</button>
                <span id="paginaSesionActual">Página 1 de 1</span>
                <button type="button" class="btn-secondary" id="btnSesionSiguiente">Siguiente</button>
                <small id="textoSesiones">0 registros</small>
            </div>

            <button type="button" class="btn-secondary" data-cerrar-modal="modalSesiones">
                Cerrar
            </button>
        </footer>
    </section>
</div>

<script>
(function () {
    'use strict';

    const permisos = {
        crear: <?= $puedeCrear ? 'true' : 'false' ?>,
        editar: <?= $puedeEditar ? 'true' : 'false' ?>,
        desactivar: <?= $puedeDesactivar ? 'true' : 'false' ?>
    };

    const estado = {
        pagina: 1,
        porPagina: 20,
        totalPaginas: 1,
        busqueda: '',
        filtroEstado: 'TODOS',
        rolId: 0,
        roles: [],
        usuarios: [],
        timerBusqueda: null,

        sesionUsuarioId: 0,
        paginaSesion: 1,
        porPaginaSesion: 20,
        totalPaginasSesion: 1
    };
    const $ = id => document.getElementById(id);
    const tabla = $('tablaUsuarios');
    const mensajePagina = $('estadoPagina');

    function escapeHtml(valor) {
        return String(valor == null ? '' : valor).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function mostrarMensaje(elemento, texto, tipo) {
        elemento.textContent = texto;
        elemento.className = 'usuarios-message usuarios-message--' + (tipo || 'error');
        elemento.hidden = false;
    }

    function ocultarMensaje(elemento) { elemento.hidden = true; elemento.textContent = ''; }

    async function api(url, opciones) {
        const respuesta = await fetch(url, Object.assign({credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}}, opciones || {}));
        const texto = await respuesta.text();
        let datos;
        try { datos = JSON.parse(texto); } catch (e) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (datos.sesion_expirada && datos.redirect) { window.location.href = datos.redirect; return null; }
        if (!respuesta.ok || datos.success !== true) { const error = new Error(datos.mensaje || 'No fue posible completar la operación.'); error.data = datos; throw error; }
        return datos;
    }

    async function cargarCatalogos() {
        const datos = await api('?usuarios_api=1&accion=CATALOGOS');
        if (!datos) return;
        estado.roles = datos.roles || [];
        $('filtroRol').innerHTML = '<option value="0">Todos los roles</option>' + estado.roles.map(r => '<option value="' + r.id + '">' + escapeHtml(r.nombre) + '</option>').join('');
        $('rolesFormulario').innerHTML = estado.roles.map(r => '<label class="role-option"><input type="checkbox" name="rol_ids[]" value="' + r.id + '"><span><strong>' + escapeHtml(r.nombre) + '</strong><small>' + escapeHtml(r.descripcion || '') + '</small></span></label>').join('');
    }

    async function cargarUsuarios() {
        ocultarMensaje(mensajePagina);
        tabla.innerHTML = '<tr><td colspan="7" class="empty-cell">Cargando usuarios...</td></tr>';
        const params = new URLSearchParams({usuarios_api: '1', accion: 'LISTAR', pagina: String(estado.pagina), por_pagina: String(estado.porPagina), busqueda: estado.busqueda, estado: estado.filtroEstado, rol_id: String(estado.rolId)});
        try {
            const datos = await api('?' + params.toString());
            if (!datos) return;
            estado.usuarios = datos.usuarios || [];
            estado.totalPaginas = datos.paginacion.total_paginas || 1;
            renderUsuarios(estado.usuarios);
            renderPaginacion(datos.paginacion);
            renderKpis(datos.resumen || {});
        } catch (error) {
            tabla.innerHTML = '<tr><td colspan="7" class="empty-cell">' + escapeHtml(error.message) + '</td></tr>';
            mostrarMensaje(mensajePagina, error.message, 'error');
        }
    }

    function renderUsuarios(usuarios) {
        if (!usuarios.length) { tabla.innerHTML = '<tr><td colspan="7" class="empty-cell">No se encontraron usuarios.</td></tr>'; return; }
        tabla.innerHTML = usuarios.map(u => {
            const estadoTexto = u.activo !== 1 ? 'Inactivo' : (u.bloqueado ? 'Bloqueado' : 'Activo');
            const estadoClase = u.activo !== 1 ? 'status-badge status-badge--inactive' : (u.bloqueado ? 'status-badge status-badge--blocked' : 'status-badge status-badge--active');
            const contacto = [u.correo || '', u.telefono || ''].filter(Boolean).map(escapeHtml).join('<br>');
            let acciones = '';
            if (permisos.editar) {
                acciones += '<button class="table-action" data-action="editar" data-id="' + u.id + '">Editar</button>';
                acciones += '<button class="table-action" data-action="password" data-id="' + u.id + '">Contraseña</button>';
            }
            acciones += '<button class="table-action" data-action="sesiones" data-id="' + u.id + '">Sesiones</button>';
            if (permisos.desactivar && !u.es_usuario_actual) {
                acciones += '<button class="table-action ' + (u.activo === 1 ? 'table-action--danger' : 'table-action--success') + '" data-action="estado" data-id="' + u.id + '" data-activo="' + (u.activo === 1 ? '0' : '1') + '">' + (u.activo === 1 ? 'Desactivar' : 'Activar') + '</button>';
            }
            return '<tr>'
                + '<td><strong>' + escapeHtml(u.usuario) + '</strong>' + (u.es_usuario_actual ? '<small class="cell-secondary">Tu cuenta</small>' : '') + '</td>'
                + '<td>' + escapeHtml(u.nombre_completo) + '</td>'
                + '<td>' + escapeHtml(u.roles_nombres || 'Sin rol') + '</td>'
                + '<td>' + (contacto || '<span class="cell-muted">Sin contacto</span>') + '</td>'
                + '<td><span class="' + estadoClase + '">' + estadoTexto + '</span></td>'
                + '<td>' + escapeHtml(u.ultimo_acceso_texto) + '</td>'
                + '<td class="text-right actions-cell">' + acciones + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginacion(p) {
        $('textoPaginacion').textContent = String(p.total_registros) + ' registro(s)';
        $('paginaActual').textContent = 'Página ' + p.pagina + ' de ' + p.total_paginas;
        $('btnAnterior').disabled = p.pagina <= 1;
        $('btnSiguiente').disabled = p.pagina >= p.total_paginas;
    }

    function renderKpis(r) {
        $('kpiTotal').textContent = r.total || 0;
        $('kpiActivos').textContent = r.activos || 0;
        $('kpiInactivos').textContent = r.inactivos || 0;
        $('kpiBloqueados').textContent = r.bloqueados || 0;
        $('kpiSinIngreso').textContent = r.sin_ingreso || 0;
    }

    function abrirModal(id) { $(id).hidden = false; document.body.classList.add('modal-open'); }
    function cerrarModal(id) { $(id).hidden = true; if (!document.querySelector('.modal-backdrop:not([hidden])')) document.body.classList.remove('modal-open'); }

    function prepararNuevo() {
        const form = $('formUsuario'); form.reset(); $('usuarioId').value = ''; $('modalUsuarioTitulo').textContent = 'Nuevo usuario';
        $('seccionPasswordNuevo').hidden = false; $('password').required = true; $('confirmarPassword').required = true;
        ocultarMensaje($('mensajeFormUsuario')); abrirModal('modalUsuario');
    }

    async function editarUsuario(id) {
        try {
            const datos = await api('?usuarios_api=1&accion=DETALLE&id=' + encodeURIComponent(id)); if (!datos) return;
            const u = datos.usuario; $('formUsuario').reset(); $('usuarioId').value = u.id; $('usuario').value = u.usuario || ''; $('nombres').value = u.nombres || ''; $('apellidoPaterno').value = u.apellido_paterno || ''; $('apellidoMaterno').value = u.apellido_materno || ''; $('correo').value = u.correo || ''; $('telefono').value = u.telefono || '';
            document.querySelectorAll('#rolesFormulario input[type="checkbox"]').forEach(c => { c.checked = u.rol_ids.includes(Number(c.value)); });
            $('modalUsuarioTitulo').textContent = 'Editar usuario'; $('seccionPasswordNuevo').hidden = true; $('password').required = false; $('confirmarPassword').required = false; $('password').value = ''; $('confirmarPassword').value = '';
            ocultarMensaje($('mensajeFormUsuario')); abrirModal('modalUsuario');
        } catch (error) { mostrarMensaje(mensajePagina, error.message, 'error'); }
    }

    async function guardarUsuario(event) {
        event.preventDefault(); const form = event.currentTarget; const boton = $('btnGuardarUsuario'); const msg = $('mensajeFormUsuario'); ocultarMensaje(msg); boton.disabled = true; const original = boton.textContent; boton.textContent = 'Guardando...';
        try { const datos = await api('?usuarios_api=1', {method: 'POST', body: new FormData(form)}); if (!datos) return; cerrarModal('modalUsuario'); mostrarMensaje(mensajePagina, datos.mensaje, 'success'); await cargarUsuarios(); }
        catch (error) { mostrarMensaje(msg, error.message, 'error'); }
        finally { boton.disabled = false; boton.textContent = original; }
    }

    async function cambiarEstado(id, activo) {
        if (!window.confirm(activo === 1 ? '¿Activar esta cuenta?' : '¿Desactivar esta cuenta? Sus sesiones activas serán cerradas.')) return;
        const form = new FormData(); form.append('csrf_token', '<?= si_escapar($csrfToken) ?>'); form.append('accion', 'CAMBIAR_ESTADO'); form.append('usuario_id', String(id)); form.append('activo', String(activo));
        try { const datos = await api('?usuarios_api=1', {method: 'POST', body: form}); mostrarMensaje(mensajePagina, datos.mensaje, 'success'); await cargarUsuarios(); }
        catch (error) { mostrarMensaje(mensajePagina, error.message, 'error'); }
    }

    function abrirPassword(id) { $('formPassword').reset(); $('passwordUsuarioId').value = id; ocultarMensaje($('mensajePassword')); abrirModal('modalPassword'); }

    async function cambiarPassword(event) {
        event.preventDefault(); const form = event.currentTarget; const msg = $('mensajePassword'); ocultarMensaje(msg); const boton = form.querySelector('button[type="submit"]'); boton.disabled = true;
        try { const datos = await api('?usuarios_api=1', {method: 'POST', body: new FormData(form)}); cerrarModal('modalPassword'); mostrarMensaje(mensajePagina, datos.mensaje, 'success'); }
        catch (error) { mostrarMensaje(msg, error.message, 'error'); }
        finally { boton.disabled = false; }
    }

    async function verSesiones(id, pagina) {
        estado.sesionUsuarioId = Number(id);
        estado.paginaSesion = Number(pagina || 1);

        const u = estado.usuarios.find(
            x => x.id === estado.sesionUsuarioId
        );

        $('tituloSesiones').textContent =
            'Sesiones · ' + (u ? u.usuario : 'Usuario');

        const tbody = $('tablaSesiones');

        tbody.innerHTML =
            '<tr><td colspan="5">Cargando...</td></tr>';

        abrirModal('modalSesiones');

        try {
            const params = new URLSearchParams({
                usuarios_api: '1',
                accion: 'SESIONES',
                usuario_id: String(estado.sesionUsuarioId),
                pagina: String(estado.paginaSesion),
                por_pagina: String(estado.porPaginaSesion)
            });

            const datos = await api(
                '?' + params.toString()
            );

            const sesiones = datos.sesiones || [];
            const p = datos.paginacion || {};

            estado.paginaSesion = p.pagina || 1;
            estado.totalPaginasSesion = p.total_paginas || 1;

            $('paginaSesionActual').textContent =
                'Página '
                + estado.paginaSesion
                + ' de '
                + estado.totalPaginasSesion;

            $('textoSesiones').textContent =
                (p.total_registros || 0) + ' registro(s)';

            $('btnSesionAnterior').disabled =
                estado.paginaSesion <= 1;

            $('btnSesionSiguiente').disabled =
                estado.paginaSesion >= estado.totalPaginasSesion;

            if (!sesiones.length) {
                tbody.innerHTML =
                    '<tr><td colspan="5" class="empty-cell">'
                    + 'Sin sesiones registradas.'
                    + '</td></tr>';
                return;
            }

            tbody.innerHTML = sesiones.map(
                s => '<tr>'
                    + '<td>' + escapeHtml(s.inicio_sesion) + '</td>'
                    + '<td>' + escapeHtml(s.fin_sesion || '—') + '</td>'
                    + '<td>' + escapeHtml(s.ip || '—') + '</td>'
                    + '<td>'
                    + (
                        s.activa === 1
                            ? '<span class="status-badge status-badge--active">Activa</span>'
                            : '<span class="status-badge status-badge--inactive">Cerrada</span>'
                    )
                    + '</td>'
                    + '<td>' + escapeHtml(s.motivo_cierre || '—') + '</td>'
                    + '</tr>'
            ).join('');

        } catch (error) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="empty-cell">'
                + escapeHtml(error.message)
                + '</td></tr>';
        }
    }

    $('porPaginaSesion').addEventListener(
        'change',
        function (event) {
            estado.porPaginaSesion = Number(event.target.value);

            if (estado.sesionUsuarioId > 0) {
                verSesiones(estado.sesionUsuarioId, 1);
            }
        }
    );

    $('btnSesionAnterior').addEventListener(
        'click',
        function () {
            if (
                estado.sesionUsuarioId <= 0
                || estado.paginaSesion <= 1
            ) {
                return;
            }

            verSesiones(
                estado.sesionUsuarioId,
                estado.paginaSesion - 1
            );
        }
    );

    $('btnSesionSiguiente').addEventListener(
        'click',
        function () {
            if (
                estado.sesionUsuarioId <= 0
                || estado.paginaSesion >= estado.totalPaginasSesion
            ) {
                return;
            }

            verSesiones(
                estado.sesionUsuarioId,
                estado.paginaSesion + 1
            );
        }
    );

    document.querySelectorAll('[data-cerrar-modal]').forEach(b => b.addEventListener('click', () => cerrarModal(b.dataset.cerrarModal)));
    document.querySelectorAll('.modal-backdrop').forEach(m => m.addEventListener('click', e => { if (e.target === m) cerrarModal(m.id); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(m => cerrarModal(m.id)); });

    $('btnNuevo')?.addEventListener('click', prepararNuevo);
    $('btnActualizar').addEventListener('click', cargarUsuarios);
    $('formUsuario').addEventListener('submit', guardarUsuario);
    $('formPassword').addEventListener('submit', cambiarPassword);
    tabla.addEventListener('click', e => {
        const b = e.target.closest('[data-action]'); if (!b) return; const id = Number(b.dataset.id);
        if (b.dataset.action === 'editar') editarUsuario(id);
        if (b.dataset.action === 'password') abrirPassword(id);
        if (b.dataset.action === 'sesiones') verSesiones(id);
        if (b.dataset.action === 'estado') cambiarEstado(id, Number(b.dataset.activo));
    });

    $('btnAnterior').addEventListener('click', () => { if (estado.pagina > 1) { estado.pagina--; cargarUsuarios(); } });
    $('btnSiguiente').addEventListener('click', () => { if (estado.pagina < estado.totalPaginas) { estado.pagina++; cargarUsuarios(); } });
    $('filtroBusqueda').addEventListener('input', e => { clearTimeout(estado.timerBusqueda); estado.timerBusqueda = setTimeout(() => { estado.busqueda = e.target.value.trim(); estado.pagina = 1; cargarUsuarios(); }, 350); });
    $('filtroEstado').addEventListener('change', e => { estado.filtroEstado = e.target.value; estado.pagina = 1; cargarUsuarios(); });
    $('filtroRol').addEventListener('change', e => { estado.rolId = Number(e.target.value); estado.pagina = 1; cargarUsuarios(); });
    $('porPagina').addEventListener('change', e => { estado.porPagina = Number(e.target.value); estado.pagina = 1; cargarUsuarios(); });

    (async function iniciar() {
        try { await cargarCatalogos(); await cargarUsuarios(); }
        catch (error) { mostrarMensaje(mensajePagina, error.message, 'error'); }
    })();
})();
</script>
</body>
</html>
