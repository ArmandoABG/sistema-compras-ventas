<?php
require_once __DIR__ . '/seguridad.php';
$scriptActual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
function si_sidebar_activo(string $archivo, string $scriptActual): string
{
    return $archivo === $scriptActual ? 'sidebar-link is-active' : 'sidebar-link';
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand__mark">SI</div>
        <div><strong>Sistema Integral</strong><small>Gestión empresarial</small></div>
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
        <?php if (si_tiene_permiso('apartados.ver')): ?><span class="sidebar-pending">Apartados</span><?php endif; ?>
        <?php if (si_tiene_permiso('ventas.ver')): ?><span class="sidebar-pending">Ventas</span><?php endif; ?>
        <?php if (si_tiene_permiso('cuentas_cobrar.ver')): ?><span class="sidebar-pending">Cuentas por cobrar</span><?php endif; ?>
        <?php if (si_tiene_permiso('inventario.ver')): ?>
            <a
                class="<?= si_sidebar_activo('inventario.php', $scriptActual) ?>"
                href="<?= si_escapar(si_url('JS/inventario.php')) ?>"
            >
                Inventario
            </a>
        <?php endif; ?>
        <?php if (si_tiene_permiso('produccion.ver')): ?><span class="sidebar-pending">Producción</span><?php endif; ?>
        <?php if (si_tiene_permiso('qr.verificar')): ?><span class="sidebar-pending">Verificar QR</span><?php endif; ?>
        <?php if (si_tiene_permiso('reportes.ver')): ?><span class="sidebar-pending">Reportes</span><?php endif; ?>
    </nav>
</aside>
