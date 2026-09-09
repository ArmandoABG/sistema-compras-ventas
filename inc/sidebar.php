<?php

require_once __DIR__ . '/seguridad.php';

$scriptActual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

function si_sidebar_activo(string $archivo, string $scriptActual): string
{
    return $archivo === $scriptActual ? 'sidebar-link is-active' : 'sidebar-link';
}

$sidebarIcono = static function (string $nombre): string {
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'ventas' => '<path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20 7H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
        'compras' => '<path d="M6 8V6a6 6 0 0 1 12 0v2"/><path d="M4 8h16l-1 13H5L4 8Z"/>',
        'inventario' => '<path d="m12 3 9 4.5-9 4.5-9-4.5L12 3Z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
        'analisis' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'admin' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
    ];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$nombre] ?? $paths['dashboard'])
        . '</svg>';
};

$gruposSidebar = [
    [
        'id' => 'ventas',
        'titulo' => 'Ventas',
        'icono' => 'ventas',
        'enlaces' => array_values(array_filter([
            si_tiene_permiso('clientes.ver') ? ['archivo' => 'clientes.php', 'ruta' => 'JS/clientes.php', 'titulo' => 'Clientes'] : null,
            si_tiene_permiso('cotizaciones.ver') ? ['archivo' => 'cotizaciones.php', 'ruta' => 'JS/cotizaciones.php', 'titulo' => 'Cotizaciones'] : null,
            si_tiene_permiso('apartados.ver') ? ['archivo' => 'apartados.php', 'ruta' => 'JS/apartados.php', 'titulo' => 'Apartados'] : null,
            si_tiene_permiso('ventas.ver') ? ['archivo' => 'ventas.php', 'ruta' => 'JS/ventas.php', 'titulo' => 'Ventas'] : null,
            si_tiene_permiso('devoluciones.ver') ? ['archivo' => 'devoluciones.php', 'ruta' => 'JS/devoluciones.php', 'titulo' => 'Devoluciones'] : null,
            si_tiene_permiso('cuentas_cobrar.ver') ? ['archivo' => 'cuentas_cobrar.php', 'ruta' => 'JS/cuentas_cobrar.php', 'titulo' => 'Cuentas por cobrar'] : null,
        ])),
    ],
    [
        'id' => 'compras',
        'titulo' => 'Compras',
        'icono' => 'compras',
        'enlaces' => array_values(array_filter([
            si_tiene_permiso('proveedores.ver') ? ['archivo' => 'proveedores.php', 'ruta' => 'JS/proveedores.php', 'titulo' => 'Proveedores'] : null,
            si_tiene_permiso('compras.ver') ? ['archivo' => 'compras.php', 'ruta' => 'JS/compras.php', 'titulo' => 'Compras'] : null,
            si_tiene_permiso('cuentas_pagar.ver') ? ['archivo' => 'cuentas_pagar.php', 'ruta' => 'JS/cuentas_pagar.php', 'titulo' => 'Cuentas por pagar'] : null,
        ])),
    ],
    [
        'id' => 'inventario',
        'titulo' => 'Inventario',
        'icono' => 'inventario',
        'enlaces' => array_values(array_filter([
            si_tiene_permiso('productos.ver') ? ['archivo' => 'productos.php', 'ruta' => 'JS/productos.php', 'titulo' => 'Productos / Catálogos'] : null,
            si_tiene_permiso('inventario.ver') ? ['archivo' => 'inventario.php', 'ruta' => 'JS/inventario.php', 'titulo' => 'Inventario'] : null,
            si_tiene_permiso('almacenes.ver') ? ['archivo' => 'almacenes.php', 'ruta' => 'JS/almacenes.php', 'titulo' => 'Almacenes'] : null,
            si_tiene_permiso('inventario.ver') ? ['archivo' => 'transferencias.php', 'ruta' => 'JS/transferencias.php', 'titulo' => 'Transferencias'] : null,
            si_tiene_permiso('produccion.ver') ? ['archivo' => 'produccion.php', 'ruta' => 'JS/produccion.php', 'titulo' => 'Producción'] : null,
            si_tiene_permiso('qr.verificar') ? ['archivo' => 'verificar_qr.php', 'ruta' => 'JS/verificar_qr.php', 'titulo' => 'Verificar QR'] : null,
        ])),
    ],
    [
        'id' => 'analisis',
        'titulo' => 'Análisis',
        'icono' => 'analisis',
        'enlaces' => array_values(array_filter([
            si_tiene_permiso('reportes.ver') ? ['archivo' => 'reportes.php', 'ruta' => 'JS/reportes.php', 'titulo' => 'Reportes'] : null,
            si_tiene_permiso('auditoria.ver') ? ['archivo' => 'auditoria.php', 'ruta' => 'JS/auditoria.php', 'titulo' => 'Auditoría'] : null,
        ])),
    ],
    [
        'id' => 'administracion',
        'titulo' => 'Administración',
        'icono' => 'admin',
        'enlaces' => array_values(array_filter([
            si_tiene_permiso('usuarios.ver') ? ['archivo' => 'usuarios.php', 'ruta' => 'JS/usuarios.php', 'titulo' => 'Usuarios'] : null,
            si_tiene_permiso('roles.administrar') ? ['archivo' => 'roles_permisos.php', 'ruta' => 'JS/roles_permisos.php', 'titulo' => 'Roles y permisos'] : null,
        ])),
    ],
];
?>
<button
    type="button"
    class="sidebar-mobile-toggle"
    id="sidebarMobileToggle"
    aria-label="Abrir menú principal"
    aria-controls="appSidebar"
    aria-expanded="false"
>
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
</button>

<div class="sidebar-mobile-backdrop" id="sidebarMobileBackdrop" hidden></div>

<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand__mark">SI</div>
        <div class="sidebar-brand__text">
            <strong>Sistema Integral</strong>
            <small>ERP · Gestión empresarial</small>
        </div>
        <button type="button" class="sidebar-mobile-close" id="sidebarMobileClose" aria-label="Cerrar menú">×</button>
    </div>

    <nav class="sidebar-menu" aria-label="Menú principal">
        <p class="sidebar-menu__label">ESPACIO DE TRABAJO</p>

        <?php if (si_tiene_permiso('dashboard.ver')): ?>
            <a
                class="<?= si_sidebar_activo('dashboard.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/dashboard.php')) ?>"
                data-nav-label="Dashboard"
                data-nav-group="Principal"
            >
                <span class="sidebar-link__icon"><?= $sidebarIcono('dashboard') ?></span>
                <span>Dashboard</span>
            </a>
        <?php endif; ?>

        <?php foreach ($gruposSidebar as $grupo): ?>
            <?php
            if (!$grupo['enlaces']) {
                continue;
            }
            $grupoActivo = false;
            foreach ($grupo['enlaces'] as $enlace) {
                if ($enlace['archivo'] === $scriptActual) {
                    $grupoActivo = true;
                    break;
                }
            }
            $panelId = 'sidebarGroup-' . $grupo['id'];
            ?>
            <section class="sidebar-group<?= $grupoActivo ? ' is-current' : '' ?>">
                <button
                    type="button"
                    class="sidebar-group__toggle"
                    data-sidebar-group-toggle
                    aria-expanded="<?= $grupoActivo ? 'true' : 'false' ?>"
                    aria-controls="<?= si_escapar($panelId) ?>"
                >
                    <span class="sidebar-link__icon"><?= $sidebarIcono($grupo['icono']) ?></span>
                    <span class="sidebar-group__title"><?= si_escapar($grupo['titulo']) ?></span>
                    <svg class="sidebar-group__chevron" viewBox="0 0 20 20" aria-hidden="true">
                        <path d="m6.5 8 3.5 3.5L13.5 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div
                    class="sidebar-group__panel<?= $grupoActivo ? ' is-open' : '' ?>"
                    id="<?= si_escapar($panelId) ?>"
                    aria-hidden="<?= $grupoActivo ? 'false' : 'true' ?>"
                >
                    <div class="sidebar-group__panel-inner">
                        <?php foreach ($grupo['enlaces'] as $enlace): ?>
                            <a
                                class="<?= si_sidebar_activo($enlace['archivo'], $scriptActual) ?>"
                                href="<?= si_escapar(si_url($enlace['ruta'])) ?>"
                                data-nav-label="<?= si_escapar($enlace['titulo']) ?>"
                                data-nav-group="<?= si_escapar($grupo['titulo']) ?>"
                            >
                                <span class="sidebar-link__dot" aria-hidden="true"></span>
                                <span><?= si_escapar($enlace['titulo']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <span class="sidebar-footer__status" aria-hidden="true"></span>
        <div>
            <strong>Sistema disponible</strong>
            <small>Operación protegida</small>
        </div>
    </div>
</aside>

<script>
(function () {
    'use strict';

    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarMobileToggle');
    const close = document.getElementById('sidebarMobileClose');
    const backdrop = document.getElementById('sidebarMobileBackdrop');
    if (!sidebar || !toggle || !close || !backdrop) return;

    let backdropTimer = 0;

    function abrirMenu() {
        window.clearTimeout(backdropTimer);
        backdrop.hidden = false;
        window.requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sidebar.classList.add('is-mobile-open');
        });
        document.body.classList.add('sidebar-mobile-open');
        toggle.setAttribute('aria-expanded', 'true');
    }

    function cerrarMenu() {
        sidebar.classList.remove('is-mobile-open');
        backdrop.classList.remove('is-visible');
        document.body.classList.remove('sidebar-mobile-open');
        toggle.setAttribute('aria-expanded', 'false');
        window.clearTimeout(backdropTimer);
        backdropTimer = window.setTimeout(function () {
            if (!sidebar.classList.contains('is-mobile-open')) backdrop.hidden = true;
        }, 190);
    }

    sidebar.querySelectorAll('[data-sidebar-group-toggle]').forEach(function (button) {
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;

        button.addEventListener('click', function () {
            const abrir = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            panel.classList.toggle('is-open', abrir);
            panel.setAttribute('aria-hidden', abrir ? 'false' : 'true');
        });
    });

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('is-mobile-open')) cerrarMenu();
        else abrirMenu();
    });
    close.addEventListener('click', cerrarMenu);
    backdrop.addEventListener('click', cerrarMenu);
    sidebar.querySelectorAll('a.sidebar-link').forEach(function (link) {
        link.addEventListener('click', cerrarMenu);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') cerrarMenu();
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 850) cerrarMenu();
    });
})();
</script>
