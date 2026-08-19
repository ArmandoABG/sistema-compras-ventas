<?php

declare(strict_types=1);

if (isset($_GET['roles_api'])) {
    $endpoint = __DIR__ . '/../funciones/roles_permisos_funciones.php';
    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensaje' => 'No se encontró funciones/roles_permisos_funciones.php.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_permiso('roles.administrar', false);

$csrfToken = si_token_csrf();
$tituloPagina = 'Roles y permisos';
$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_roles_permisos.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Roles y permisos | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_roles_permisos.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>
    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>
        <main class="page-content roles-page">
            <header class="roles-heading">
                <div>
                    <p class="roles-eyebrow">SEGURIDAD</p>
                    <h1>Roles y permisos</h1>
                    <p>Define qué puede consultar o modificar cada perfil del sistema.</p>
                </div>
                <a href="usuarios.php" class="btn-secondary link-button">Volver a usuarios</a>
            </header>

            <div id="mensajeRoles" class="roles-message" hidden></div>

            <section class="roles-layout">
                <aside class="roles-list-card">
                    <h2>Roles</h2>
                    <div id="listaRoles">Cargando...</div>
                </aside>

                <section class="permissions-card">
                    <header class="permissions-head">
                        <div>
                            <small id="rolCodigo">SELECCIONA UN ROL</small>
                            <h2 id="rolNombre">Permisos</h2>
                            <p id="rolDescripcion">Elige un rol para consultar sus accesos.</p>
                        </div>
                        <div class="permissions-actions">
                            <button type="button" class="btn-secondary" id="btnSeleccionarTodos" disabled>Seleccionar todos</button>
                            <button type="button" class="btn-primary" id="btnGuardarPermisos" disabled>Guardar cambios</button>
                        </div>
                    </header>

                    <div id="avisoAdministrador" class="roles-message roles-message--info" hidden>
                        El rol Administrador siempre conserva todos los permisos del sistema.
                    </div>

                    <div id="gruposPermisos" class="permissions-groups"><p>Selecciona un rol.</p></div>
                </section>
            </section>
        </main>
    </div>
</div>

<script>
(function () {
    'use strict';
    const estado = {roles: [], permisos: [], rolActual: null};
    const $ = id => document.getElementById(id);

    function escapeHtml(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function mostrarMensaje(texto, tipo) {
        const el = $('mensajeRoles');
        el.textContent = texto;
        el.className = 'roles-message roles-message--' + (tipo || 'error');
        el.hidden = false;
    }

    function ocultarMensaje() { $('mensajeRoles').hidden = true; }

    async function api(url, opciones) {
        const r = await fetch(url, Object.assign({credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}}, opciones || {}));
        const text = await r.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (data.sesion_expirada && data.redirect) { window.location.href = data.redirect; return null; }
        if (!r.ok || data.success !== true) throw new Error(data.mensaje || 'No fue posible completar la operación.');
        return data;
    }

    async function cargarInicial(seleccionarId) {
        ocultarMensaje();
        const data = await api('?roles_api=1&accion=INICIAL');
        if (!data) return;
        estado.roles = data.roles || [];
        estado.permisos = data.permisos || [];
        renderRoles();
        const id = seleccionarId || (estado.roles[0] ? estado.roles[0].id : 0);
        if (id) await seleccionarRol(id);
    }

    function renderRoles() {
        $('listaRoles').innerHTML = estado.roles.length ? estado.roles.map(rol =>
            '<button type="button" class="role-card" data-role-id="' + rol.id + '">' +
            '<strong>' + escapeHtml(rol.nombre) + '</strong>' +
            '<small>' + escapeHtml(rol.codigo) + '</small>' +
            '<span>' + rol.total_permisos + ' permisos · ' + rol.usuarios_activos + ' usuario(s) activos</span>' +
            '</button>'
        ).join('') : '<p>No hay roles.</p>';
    }

    async function seleccionarRol(id) {
        ocultarMensaje();
        const data = await api('?roles_api=1&accion=DETALLE&rol_id=' + encodeURIComponent(id));
        if (!data) return;
        estado.rolActual = data.rol;
        document.querySelectorAll('.role-card').forEach(b => b.classList.toggle('is-active', Number(b.dataset.roleId) === Number(id)));
        renderDetalle();
    }

    function renderDetalle() {
        const rol = estado.rolActual;
        $('rolCodigo').textContent = rol.codigo;
        $('rolNombre').textContent = rol.nombre;
        $('rolDescripcion').textContent = rol.descripcion || '';
        const bloqueado = rol.bloqueado === true || rol.bloqueado === 1;
        $('avisoAdministrador').hidden = !bloqueado;
        $('btnSeleccionarTodos').disabled = bloqueado;
        $('btnGuardarPermisos').disabled = bloqueado;

        const seleccionados = new Set((rol.permiso_ids || []).map(Number));
        const grupos = {};
        estado.permisos.forEach(p => { if (!grupos[p.modulo]) grupos[p.modulo] = []; grupos[p.modulo].push(p); });

        $('gruposPermisos').innerHTML = Object.keys(grupos).sort().map(modulo =>
            '<section class="permission-group">' +
            '<header><h3>' + escapeHtml(modulo.replace(/_/g, ' ')) + '</h3><span>' + grupos[modulo].length + ' permiso(s)</span></header>' +
            '<div class="permission-list">' + grupos[modulo].map(p => {
                const checked = bloqueado || seleccionados.has(Number(p.id));
                return '<label class="permission-item"><input type="checkbox" value="' + p.id + '" ' + (checked ? 'checked ' : '') + (bloqueado ? 'disabled ' : '') + '><span><strong>' + escapeHtml(p.nombre) + '</strong><small>' + escapeHtml(p.codigo) + '</small></span></label>';
            }).join('') + '</div></section>'
        ).join('');
    }

    $('listaRoles').addEventListener('click', e => {
        const b = e.target.closest('[data-role-id]');
        if (b) seleccionarRol(Number(b.dataset.roleId)).catch(err => mostrarMensaje(err.message, 'error'));
    });

    $('btnSeleccionarTodos').addEventListener('click', () => {
        document.querySelectorAll('#gruposPermisos input[type="checkbox"]:not(:disabled)').forEach(c => c.checked = true);
    });

    $('btnGuardarPermisos').addEventListener('click', async function () {
        if (!estado.rolActual) return;
        const ids = Array.from(document.querySelectorAll('#gruposPermisos input[type="checkbox"]:checked')).map(i => i.value);
        if (!ids.length) { mostrarMensaje('Selecciona al menos un permiso.', 'error'); return; }
        if (!window.confirm('¿Guardar los permisos de ' + estado.rolActual.nombre + '?')) return;

        const rolId = estado.rolActual.id;
        const form = new FormData();
        form.append('csrf_token', '<?= si_escapar($csrfToken) ?>');
        form.append('accion', 'GUARDAR_PERMISOS');
        form.append('rol_id', String(rolId));
        ids.forEach(id => form.append('permiso_ids[]', id));
        this.disabled = true;

        try {
            const data = await api('?roles_api=1', {method: 'POST', body: form});
            mostrarMensaje(data.mensaje, 'success');
            await cargarInicial(rolId);
        } catch (error) {
            mostrarMensaje(error.message, 'error');
        } finally {
            if (estado.rolActual && !estado.rolActual.bloqueado) this.disabled = false;
        }
    });

    cargarInicial().catch(error => mostrarMensaje(error.message, 'error'));
})();
</script>
</body>
</html>
