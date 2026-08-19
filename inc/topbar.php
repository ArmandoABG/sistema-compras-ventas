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
