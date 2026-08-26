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

        <div class="topbar-user__text">
            <strong><?= si_escapar($nombreUsuario) ?></strong>
            <small><?= si_escapar($rolUsuario) ?></small>
        </div>

        <form
            action="<?= si_escapar(si_url('funciones/logout.php')) ?>"
            method="post"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= si_escapar(si_token_csrf()) ?>"
            >

            <button type="submit" class="topbar-logout">
                Cerrar sesión
            </button>
        </form>
    </div>
</header>

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
})();
</script>
