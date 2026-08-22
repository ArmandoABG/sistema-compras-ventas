<?php
require_once __DIR__ . '/seguridad.php';
$scriptActual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
function si_sidebar_activo(string $archivo, string $scriptActual): string
{
    return $archivo === $scriptActual ? 'sidebar-link is-active' : 'sidebar-link';
}
?>
<button
    type="button"
    class="sidebar-mobile-toggle"
    id="sidebarMobileToggle"
    aria-label="Abrir menú principal"
    aria-controls="appSidebar"
    aria-expanded="false"
>☰</button>
<div class="sidebar-mobile-backdrop" id="sidebarMobileBackdrop" hidden></div>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand__mark">SI</div>
        <div class="sidebar-brand__text"><strong>Sistema Integral</strong><small>Gestión empresarial</small></div>
        <button type="button" class="sidebar-mobile-close" id="sidebarMobileClose" aria-label="Cerrar menú">×</button>
    </div>
    <nav class="sidebar-menu" aria-label="Menú principal">
        <?php if (si_tiene_permiso('dashboard.ver')): ?>
            <a class="<?= si_sidebar_activo('dashboard.php', $scriptActual) ?>" href="<?= si_escapar(si_url('JS/dashboard.php')) ?>">Dashboard</a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('usuarios.ver')): ?>
            <a class="<?= si_sidebar_activo('usuarios.php', $scriptActual) ?>" href="<?= si_escapar(si_url('JS/usuarios.php')) ?>">Usuarios</a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('roles.administrar')): ?>
            <a class="<?= si_sidebar_activo('roles_permisos.php', $scriptActual) ?>" href="<?= si_escapar(si_url('JS/roles_permisos.php')) ?>">Roles y permisos</a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('productos.ver')): ?>
            <a
                class="<?= si_sidebar_activo('productos.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/productos.php')) ?>"
            >
                Productos / Catálogos
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('proveedores.ver')): ?>
            <a
                class="<?= si_sidebar_activo('proveedores.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/proveedores.php')) ?>"
            >
                Proveedores
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('compras.ver')): ?>
            <a
                class="<?= si_sidebar_activo('compras.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/compras.php')) ?>"
            >
                Compras
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('cuentas_pagar.ver')): ?>
            <a
                class="<?= si_sidebar_activo('cuentas_pagar.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/cuentas_pagar.php')) ?>"
            >
                Cuentas por pagar
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('clientes.ver')): ?>
            <a
                class="<?= si_sidebar_activo('clientes.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/clientes.php')) ?>"
            >
                Clientes
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('cotizaciones.ver')): ?>
            <a
                class="<?= si_sidebar_activo('cotizaciones.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/cotizaciones.php')) ?>"
            >
                Cotizaciones
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('apartados.ver')): ?>
            <a
                class="<?= si_sidebar_activo('apartados.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/apartados.php')) ?>"
            >
                Apartados
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('ventas.ver')): ?>
            <a
                class="<?= si_sidebar_activo('ventas.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/ventas.php')) ?>"
            >
                Ventas
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('cuentas_cobrar.ver')): ?>
            <a
                class="<?= si_sidebar_activo('cuentas_cobrar.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/cuentas_cobrar.php')) ?>"
            >
                Cuentas por cobrar
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('inventario.ver')): ?>
            <a
                class="<?= si_sidebar_activo('inventario.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/inventario.php')) ?>"
            >
                Inventario
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('produccion.ver')): ?>
            <a
                class="<?= si_sidebar_activo('produccion.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/produccion.php')) ?>"
            >
                Producción
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('qr.verificar')): ?>
            <a
                class="<?= si_sidebar_activo('verificar_qr.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/verificar_qr.php')) ?>"
            >
                Verificar QR
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('reportes.ver')): ?><span class="sidebar-pending">Reportes</span><?php endif; ?>
    </nav>
</aside>
<script>
(function () {
    'use strict';
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarMobileToggle');
    const close = document.getElementById('sidebarMobileClose');
    const backdrop = document.getElementById('sidebarMobileBackdrop');
    if (!sidebar || !toggle || !close || !backdrop) return;

    function abrirMenu() {
        sidebar.classList.add('is-mobile-open');
        backdrop.hidden = false;
        document.body.classList.add('sidebar-mobile-open');
        toggle.setAttribute('aria-expanded', 'true');
    }
    function cerrarMenu() {
        sidebar.classList.remove('is-mobile-open');
        backdrop.hidden = true;
        document.body.classList.remove('sidebar-mobile-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', () => sidebar.classList.contains('is-mobile-open') ? cerrarMenu() : abrirMenu());
    close.addEventListener('click', cerrarMenu);
    backdrop.addEventListener('click', cerrarMenu);
    sidebar.querySelectorAll('a.sidebar-link').forEach((link) => link.addEventListener('click', cerrarMenu));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') cerrarMenu(); });
    window.addEventListener('resize', () => { if (window.innerWidth > 850) cerrarMenu(); });
})();
</script>
