<?php

require_once __DIR__ . '/seguridad.php';

$nombreUsuario = trim((string) (
    $_SESSION['nombre_completo']
    ?? $_SESSION['usuario']
    ?? 'Usuario'
));

$rolUsuario = trim((string) (
    $_SESSION['rol_nombre']
    ?? 'Usuario'
));

$partesNombre = preg_split('/\s+/', $nombreUsuario, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$inicialesUsuario = '';
foreach (array_slice($partesNombre, 0, 2) as $parteNombre) {
    $inicialesUsuario .= mb_strtoupper(mb_substr((string) $parteNombre, 0, 1));
}
if ($inicialesUsuario === '') {
    $inicialesUsuario = 'U';
}
$csrfTopbar = si_token_csrf();
?>
<header class="topbar">
    <div class="topbar-title">
        <strong><?= si_escapar($tituloPagina ?? 'Sistema Integral') ?></strong>
    </div>

    <div class="topbar-user">
        <div class="topbar-alerts" id="topbarAlerts">
            <button
                type="button"
                class="topbar-alerts__button"
                id="topbarAlertsButton"
                aria-label="Abrir centro de alertas"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="topbarAlertsPanel"
            >
                <span class="topbar-alerts__icon" aria-hidden="true">!</span>
                <span class="topbar-alerts__label">Alertas</span>
                <span class="topbar-alerts__badge" id="topbarAlertsBadge" hidden>0</span>
            </button>

            <section
                class="topbar-alerts__panel"
                id="topbarAlertsPanel"
                aria-label="Resumen de alertas operativas"
                hidden
            >
                <header class="topbar-alerts__head">
                    <div>
                        <strong>Centro de alertas</strong>
                        <small id="topbarAlertsSummary">Consultando estado actual...</small>
                    </div>
                    <button
                        type="button"
                        class="topbar-alerts__close"
                        id="topbarAlertsClose"
                        aria-label="Cerrar alertas"
                    >×</button>
                </header>

                <div class="topbar-alerts__priorities" id="topbarAlertsPriorities" hidden></div>

                <div class="topbar-alerts__list" id="topbarAlertsList">
                    <div class="topbar-alerts__empty">Cargando alertas...</div>
                </div>

                <footer class="topbar-alerts__footer">
                    <a href="<?= si_escapar(si_url('JS/dashboard.php#centroAlertas')) ?>">
                        Ver detalle en Dashboard
                    </a>
                </footer>
            </section>
        </div>

        <div class="topbar-account" id="topbarAccount">
            <button
                type="button"
                class="topbar-account__button"
                id="topbarAccountButton"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="topbarAccountMenu"
            >
                <span class="topbar-account__avatar" aria-hidden="true"><?= si_escapar($inicialesUsuario) ?></span>
                <span class="topbar-user__text">
                    <strong id="topbarAccountName"><?= si_escapar($nombreUsuario) ?></strong>
                    <small><?= si_escapar($rolUsuario) ?></small>
                </span>
                <span class="topbar-account__chevron" aria-hidden="true">⌄</span>
            </button>

            <section class="topbar-account__menu" id="topbarAccountMenu" hidden>
                <button type="button" id="topbarOpenProfile">
                    <strong>Mi perfil</strong>
                    <small>Editar mis datos y contraseña</small>
                </button>
                <form action="<?= si_escapar(si_url('funciones/logout.php')) ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfTopbar) ?>">
                    <button type="submit" class="topbar-account__logout">Cerrar sesión</button>
                </form>
            </section>
        </div>
    </div>
</header>

<div class="topbar-profile-modal" id="topbarProfileModal" hidden>
    <section class="topbar-profile-card" role="dialog" aria-modal="true" aria-labelledby="topbarProfileTitle">
        <header class="topbar-profile-card__head">
            <div>
                <small>CUENTA PERSONAL</small>
                <h2 id="topbarProfileTitle">Mi perfil</h2>
            </div>
            <button type="button" class="topbar-profile-card__close" id="topbarProfileClose" aria-label="Cerrar">×</button>
        </header>

        <div class="topbar-profile-card__body">
            <div id="topbarProfileMessage" class="topbar-profile-message" hidden></div>

            <form id="topbarProfileForm">
                <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfTopbar) ?>">
                <input type="hidden" name="accion" value="GUARDAR_PERFIL">

                <div class="topbar-profile-account-summary">
                    <div>
                        <span>Usuario</span>
                        <strong id="topbarProfileUsuario"><?= si_escapar((string) ($_SESSION['usuario'] ?? '')) ?></strong>
                    </div>
                    <div>
                        <span>Rol principal</span>
                        <strong><?= si_escapar($rolUsuario) ?></strong>
                    </div>
                </div>

                <div class="topbar-profile-grid">
                    <label><span>Nombres *</span><input type="text" name="nombres" id="topbarProfileNombres" maxlength="120" required></label>
                    <label><span>Apellido paterno</span><input type="text" name="apellido_paterno" id="topbarProfileApellidoP" maxlength="100"></label>
                    <label><span>Apellido materno</span><input type="text" name="apellido_materno" id="topbarProfileApellidoM" maxlength="100"></label>
                    <label><span>Correo</span><input type="email" name="correo" id="topbarProfileCorreo" maxlength="180"></label>
                    <label><span>Teléfono</span><input type="text" name="telefono" id="topbarProfileTelefono" maxlength="30"></label>
                </div>

                <footer class="topbar-profile-actions">
                    <button type="submit" class="topbar-profile-primary" id="topbarProfileSave">Guardar mis datos</button>
                </footer>
            </form>

            <section class="topbar-profile-password">
                <div>
                    <h3>Cambiar mi contraseña</h3>
                    <p>Para proteger tu cuenta, debes confirmar tu contraseña actual.</p>
                </div>

                <form id="topbarPasswordForm">
                    <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfTopbar) ?>">
                    <input type="hidden" name="accion" value="CAMBIAR_PASSWORD">
                    <div class="topbar-profile-grid">
                        <label><span>Contraseña actual *</span><input type="password" name="password_actual" autocomplete="current-password" required></label>
                        <label><span>Nueva contraseña *</span><input type="password" name="nueva_password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                        <label><span>Confirmar nueva contraseña *</span><input type="password" name="confirmar_password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                    </div>
                    <footer class="topbar-profile-actions">
                        <button type="submit" class="topbar-profile-secondary" id="topbarPasswordSave">Cambiar contraseña</button>
                    </footer>
                </form>
            </section>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';

    const root = document.getElementById('topbarAlerts');
    const button = document.getElementById('topbarAlertsButton');
    const panel = document.getElementById('topbarAlertsPanel');
    const close = document.getElementById('topbarAlertsClose');
    const badge = document.getElementById('topbarAlertsBadge');
    const summary = document.getElementById('topbarAlertsSummary');
    const priorities = document.getElementById('topbarAlertsPriorities');
    const list = document.getElementById('topbarAlertsList');

    if (!root || !button || !panel || !close || !badge || !summary || !priorities || !list) {
        return;
    }

    const endpoint = <?= json_encode(si_url('funciones/alertas_funciones.php?accion=RESUMEN'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function priorityClass(priority) {
        const value = String(priority || 'NORMAL').toUpperCase();
        if (value === 'CRITICA') return 'is-critical';
        if (value === 'ALTA') return 'is-high';
        if (value === 'BAJA') return 'is-low';
        return 'is-normal';
    }

    function priorityText(priority) {
        const value = String(priority || 'NORMAL').toUpperCase();
        if (value === 'CRITICA') return 'Crítica';
        if (value === 'ALTA') return 'Alta';
        if (value === 'BAJA') return 'Baja';
        return 'Atención';
    }

    function render(data) {
        const totalActivo = Number(data.total || 0);
        const total = Number(data.total_sin_leer || 0);
        const items = (Array.isArray(data.alertas) ? data.alertas : []).filter(function (item) {
            return !Boolean(item.leida);
        });
        const p = data.prioridades_sin_leer || {};

        badge.textContent = total > 99 ? '99+' : String(total);
        badge.hidden = total <= 0;
        button.classList.toggle('has-alerts', total > 0);

        if (totalActivo <= 0) {
            summary.textContent = 'Sin pendientes operativos para tu perfil.';
            priorities.hidden = true;
            list.innerHTML = '<div class="topbar-alerts__empty is-ok"><strong>Todo en orden</strong><span>No hay alertas activas que requieran tu atención.</span></div>';
            return;
        }

        if (total <= 0) {
            summary.textContent = totalActivo + (totalActivo === 1 ? ' alerta activa, ya revisada.' : ' alertas activas, todas revisadas.');
            priorities.hidden = true;
            list.innerHTML = '<div class="topbar-alerts__empty is-ok"><strong>Sin alertas nuevas</strong><span>Las condiciones activas ya fueron revisadas. Puedes verlas en el Dashboard.</span></div>';
            return;
        }

        summary.textContent = total + (total === 1 ? ' alerta nueva' : ' alertas nuevas') + ' · ' + totalActivo + ' activas';

        const priorityParts = [];
        if (Number(p.CRITICA || 0) > 0) priorityParts.push('<span class="is-critical">' + Number(p.CRITICA) + ' críticas</span>');
        if (Number(p.ALTA || 0) > 0) priorityParts.push('<span class="is-high">' + Number(p.ALTA) + ' altas</span>');
        if (Number(p.NORMAL || 0) > 0) priorityParts.push('<span class="is-normal">' + Number(p.NORMAL) + ' atención</span>');

        priorities.innerHTML = priorityParts.join('');
        priorities.hidden = priorityParts.length === 0;

        const visible = items.slice(0, 7);
        list.innerHTML = visible.map(function (item) {
            const href = escapeHtml(item.href || '#');
            const priority = priorityClass(item.prioridad);
            const details = Array.isArray(item.detalles) ? item.detalles.slice(0, 2) : [];
            const detailsHtml = details.length
                ? '<div class="topbar-alert-item__details">' + details.map(function (detail) {
                    return '<span><strong>' + escapeHtml(detail.principal || '') + '</strong>'
                        + (detail.secundario ? ' · ' + escapeHtml(detail.secundario) : '')
                        + (detail.meta ? '<small>' + escapeHtml(detail.meta) + '</small>' : '')
                        + '</span>';
                }).join('') + '</div>'
                : '';

            return '<article class="topbar-alert-item ' + priority + '">'
                + '<div class="topbar-alert-item__head">'
                + '<span class="topbar-alert-item__priority">' + escapeHtml(priorityText(item.prioridad)) + '</span>'
                + '<span class="topbar-alert-item__count">' + Number(item.conteo || 0) + '</span>'
                + '</div>'
                + '<strong>' + escapeHtml(item.titulo || 'Alerta') + '</strong>'
                + '<p>' + escapeHtml(item.mensaje || '') + '</p>'
                + detailsHtml
                + '<a href="' + href + '">' + escapeHtml(item.accion || 'Revisar') + '</a>'
                + '</article>';
        }).join('');
    }

    function renderError(message) {
        badge.hidden = true;
        summary.textContent = 'No se pudieron actualizar las alertas.';
        priorities.hidden = true;
        list.innerHTML = '<div class="topbar-alerts__empty is-error">' + escapeHtml(message || 'Error al consultar alertas.') + '</div>';
    }

    async function loadAlerts() {
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            if (!response.ok || data.success !== true) {
                if (data.sesion_expirada && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }

                throw new Error(data.mensaje || 'No fue posible consultar las alertas.');
            }

            render(data.alertas_operativas || {});
        } catch (error) {
            renderError(error.message);
        }
    }

    function openPanel() {
        panel.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    }

    button.addEventListener('click', function () {
        if (panel.hidden) openPanel();
        else closePanel();
    });

    close.addEventListener('click', closePanel);

    document.addEventListener('click', function (event) {
        if (!panel.hidden && !root.contains(event.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closePanel();
    });

    window.addEventListener('si:alertas-actualizadas', loadAlerts);
    loadAlerts();

    // Revisión silenciosa de alertas de inventario por correo.
    // La BD aplica un bloqueo temporal global para que muchos usuarios
    // conectados no provoquen revisiones o envíos duplicados.
    const stockEmailEndpoint = <?= json_encode(si_url('funciones/notificaciones_stock_email_funciones.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const stockEmailCsrf = <?= json_encode(si_token_csrf(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    let stockEmailBusy = false;

    async function processStockEmail() {
        if (stockEmailBusy || document.visibilityState !== 'visible') return;
        stockEmailBusy = true;
        try {
            const form = new FormData();
            form.append('csrf_token', stockEmailCsrf);
            await fetch(stockEmailEndpoint, {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
        } catch (error) {
            // El correo es complementario: un fallo SMTP nunca interrumpe la interfaz.
        } finally {
            stockEmailBusy = false;
        }
    }

    window.setTimeout(processStockEmail, 4000);
    window.setInterval(processStockEmail, 60000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') processStockEmail();
    });
})();
</script>

<script>
(function () {
    'use strict';

    const root = document.getElementById('topbarAccount');
    const button = document.getElementById('topbarAccountButton');
    const menu = document.getElementById('topbarAccountMenu');
    const openProfile = document.getElementById('topbarOpenProfile');
    const modal = document.getElementById('topbarProfileModal');
    const close = document.getElementById('topbarProfileClose');
    const profileForm = document.getElementById('topbarProfileForm');
    const passwordForm = document.getElementById('topbarPasswordForm');
    const message = document.getElementById('topbarProfileMessage');
    const endpoint = <?= json_encode(si_url('funciones/perfil_funciones.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    if (!root || !button || !menu || !openProfile || !modal || !close || !profileForm || !passwordForm || !message) {
        return;
    }

    function showMessage(text, type) {
        message.textContent = text || '';
        message.className = 'topbar-profile-message is-' + (type || 'error');
        message.hidden = false;
    }

    function hideMessage() {
        message.hidden = true;
        message.textContent = '';
    }

    async function api(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }, options || {}));

        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('El servidor devolvió una respuesta no válida.'); }

        if (data.cambio_password_requerido && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
        if (data.sesion_expirada && data.redirect) {
            window.location.href = data.redirect;
            return null;
        }
        if (!response.ok || data.success !== true) {
            throw new Error(data.mensaje || 'No fue posible completar la operación.');
        }
        return data;
    }

    function openMenu() {
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('topbar-profile-open');
        hideMessage();
        passwordForm.reset();
    }

    async function loadProfile() {
        const data = await api(endpoint + '?accion=OBTENER');
        if (!data) return;
        const p = data.perfil || {};
        document.getElementById('topbarProfileUsuario').textContent = p.usuario || '';
        document.getElementById('topbarProfileNombres').value = p.nombres || '';
        document.getElementById('topbarProfileApellidoP').value = p.apellido_paterno || '';
        document.getElementById('topbarProfileApellidoM').value = p.apellido_materno || '';
        document.getElementById('topbarProfileCorreo').value = p.correo || '';
        document.getElementById('topbarProfileTelefono').value = p.telefono || '';
    }

    button.addEventListener('click', function () {
        if (menu.hidden) openMenu();
        else closeMenu();
    });

    openProfile.addEventListener('click', async function () {
        closeMenu();
        hideMessage();
        modal.hidden = false;
        document.body.classList.add('topbar-profile-open');
        try {
            await loadProfile();
        } catch (error) {
            showMessage(error.message, 'error');
        }
    });

    close.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('click', function (event) {
        if (!menu.hidden && !root.contains(event.target)) closeMenu();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
            if (!modal.hidden) closeModal();
        }
    });

    profileForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        hideMessage();
        const save = document.getElementById('topbarProfileSave');
        save.disabled = true;
        const original = save.textContent;
        save.textContent = 'Guardando...';
        try {
            const data = await api(endpoint, {method: 'POST', body: new FormData(profileForm)});
            if (!data) return;
            const name = data.nombre_completo || '';
            if (name) {
                document.getElementById('topbarAccountName').textContent = name;
            }
            showMessage(data.mensaje, 'success');
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            save.disabled = false;
            save.textContent = original;
        }
    });

    passwordForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        hideMessage();
        const save = document.getElementById('topbarPasswordSave');
        save.disabled = true;
        const original = save.textContent;
        save.textContent = 'Actualizando...';
        try {
            const data = await api(endpoint, {method: 'POST', body: new FormData(passwordForm)});
            if (!data) return;
            passwordForm.reset();
            showMessage(data.mensaje, 'success');
        } catch (error) {
            showMessage(error.message, 'error');
        } finally {
            save.disabled = false;
            save.textContent = original;
        }
    });
})();
</script>
