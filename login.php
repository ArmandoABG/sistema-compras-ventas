<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/seguridad.php';
require_once __DIR__ . '/inc/conexion.php';

/*
|--------------------------------------------------------------------------
| Sesión ya iniciada
|--------------------------------------------------------------------------
*/

if (si_sesion_autenticada()) {
    header(
        'Location: ' . si_url('JS/dashboard.php')
    );
    exit;
}

$csrfLogin = si_token_csrf('csrf_login');

$hayUsuarios = false;

if ($conexion instanceof PDO) {
    try {
        $hayUsuarios = (int) $conexion->query(
            "SELECT COUNT(*)
             FROM usuarios
             WHERE 1=1"
        )->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log(
            '[LOGIN][VALIDAR USUARIOS] '
            . $e->getMessage()
        );
    }
}

$mensajeInicial = '';
$tipoMensajeInicial = 'info';

if (
    isset($_GET['sesion'])
    && (string) $_GET['sesion'] === 'expirada'
) {
    $mensajeInicial =
        'Tu sesión terminó por inactividad. Inicia sesión nuevamente.';
    $tipoMensajeInicial = 'warning';

} elseif (isset($_GET['logout'])) {
    $mensajeInicial =
        'La sesión se cerró correctamente.';
    $tipoMensajeInicial = 'success';

} elseif (
    isset($_GET['admin'])
    && (string) $_GET['admin'] === 'creado'
) {
    $mensajeInicial =
        'Administrador creado correctamente. Ya puedes iniciar sesión.';
    $tipoMensajeInicial = 'success';
}

$cssPath = __DIR__ . '/css/style_login.css';
$cssVersion = is_file($cssPath)
    ? (string) filemtime($cssPath)
    : (string) time();
$globalCssPath = __DIR__ . '/css/style_global.css';
$globalCssVersion = is_file($globalCssPath)
    ? (string) filemtime($globalCssPath)
    : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >
    <meta name="robots" content="noindex, nofollow">

    <title>Iniciar sesión | Sistema Integral</title>

    <link
        rel="stylesheet"
        href="css/style_global.css?v=<?= si_escapar($globalCssVersion) ?>"
    >
    <link
        rel="stylesheet"
        href="css/style_login.css?v=<?= si_escapar($cssVersion) ?>"
    >
</head>
<body>

<main class="login-page">
    <section class="login-shell" aria-label="Acceso a Sistema Integral">
        <aside class="login-brand-panel">
            <div class="login-brand-panel__glow" aria-hidden="true"></div>

            <header class="login-brand">
                <div class="login-logo">SI</div>
                <div>
                    <strong>Sistema Integral</strong>
                    <small>ERP · Gestión empresarial</small>
                </div>
            </header>

            <div class="login-brand-copy">
                <span class="login-brand-copy__eyebrow">TU OPERACIÓN, EN UN SOLO LUGAR</span>
                <h1>Control claro para decisiones más inteligentes.</h1>
                <p>Administra las áreas clave de tu empresa desde una plataforma segura, ordenada y fácil de consultar.</p>
            </div>

            <ul class="login-benefits" aria-label="Ventajas del sistema">
                <li><span aria-hidden="true">✓</span><strong>Ventas y compras</strong><small>Operación comercial conectada</small></li>
                <li><span aria-hidden="true">✓</span><strong>Inventario</strong><small>Existencias y movimientos trazables</small></li>
                <li><span aria-hidden="true">✓</span><strong>Clientes y proveedores</strong><small>Información centralizada</small></li>
                <li><span aria-hidden="true">✓</span><strong>Reportes</strong><small>Visibilidad para tomar decisiones</small></li>
                <li><span aria-hidden="true">✓</span><strong>Seguridad</strong><small>Accesos definidos por permisos</small></li>
            </ul>

            <footer class="login-brand-footer">
                <span></span>
                Plataforma empresarial disponible
            </footer>
        </aside>

        <section class="login-form-panel">
            <div class="login-mobile-brand">
                <div class="login-logo">SI</div>
                <div><strong>Sistema Integral</strong><small>Gestión empresarial</small></div>
            </div>

            <div class="login-form-wrap">
                <header class="login-heading">
                    <span class="login-heading__eyebrow">ACCESO SEGURO</span>
                    <h2>Bienvenido</h2>
                    <p class="login-description">Ingresa tus credenciales para continuar al sistema.</p>
                </header>

                <?php if ($mensajeInicial !== ''): ?>
                    <div class="login-message login-message--<?= si_escapar($tipoMensajeInicial) ?>">
                        <?= si_escapar($mensajeInicial) ?>
                    </div>
                <?php endif; ?>

                <?php if (!($conexion instanceof PDO)): ?>
                    <div class="login-message login-message--error">
                        No fue posible conectar con la base de datos. Revisa <strong>inc/conexion.php</strong>.
                    </div>
                <?php endif; ?>

                <?php if (!$hayUsuarios && $conexion instanceof PDO): ?>
                    <div class="login-message login-message--warning">
                        Todavía no existe ningún usuario.
                        <a href="JS/crear_admin_inicial.php">Crear administrador inicial</a>
                    </div>
                <?php endif; ?>

                <div id="mensajeLogin" class="login-message login-message--error" role="alert" hidden></div>

                <form id="formLogin" action="funciones/login_funciones.php" method="post" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfLogin) ?>">

                    <label for="usuario">Usuario</label>
                    <div class="login-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>
                        <input id="usuario" type="text" name="usuario" maxlength="60" autocomplete="username" placeholder="Escribe tu usuario" required autofocus>
                    </div>

                    <label for="password">Contraseña</label>
                    <div class="password-field login-input-wrap">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>
                        <input id="password" type="password" name="password" maxlength="255" autocomplete="current-password" placeholder="Escribe tu contraseña" required>
                        <button id="btnMostrarPassword" class="password-field__toggle" type="button">Mostrar</button>
                    </div>

                    <button
                        id="btnLogin"
                        class="btn-primary login-submit"
                        type="submit"
                        <?= (!$hayUsuarios || !($conexion instanceof PDO)) ? 'disabled' : '' ?>
                    >
                        <span>Iniciar sesión</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m14 7 5 5-5 5"></path></svg>
                    </button>
                </form>

                <p class="login-support">Acceso exclusivo para personal autorizado.</p>
            </div>
        </section>
    </section>
</main>

<script>
(function () {
    'use strict';

    const form = document.getElementById('formLogin');
    const mensaje = document.getElementById('mensajeLogin');
    const boton = document.getElementById('btnLogin');
    const password = document.getElementById('password');
    const btnMostrar = document.getElementById('btnMostrarPassword');

    if (!form) {
        return;
    }

    btnMostrar.addEventListener('click', function () {
        const visible = password.type === 'text';

        password.type = visible
            ? 'password'
            : 'text';

        btnMostrar.textContent = visible
            ? 'Mostrar'
            : 'Ocultar';
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        mensaje.hidden = true;
        boton.disabled = true;

        const contenidoOriginal = boton.innerHTML;
        boton.textContent = 'Validando...';

        try {
            const respuesta = await fetch(
                form.action,
                {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            const texto = await respuesta.text();

            let datos;

            try {
                datos = JSON.parse(texto);
            } catch (error) {
                throw new Error(
                    'El servidor devolvió una respuesta no válida.'
                );
            }

            if (!respuesta.ok || datos.success !== true) {
                throw new Error(
                    datos.mensaje
                    || 'No fue posible iniciar sesión.'
                );
            }

            window.location.href = datos.redirect;

        } catch (error) {
            mensaje.textContent =
                error.message
                || 'Ocurrió un error inesperado.';

            mensaje.hidden = false;

            boton.disabled = false;
            boton.innerHTML = contenidoOriginal;
        }
    });
})();
</script>

</body>
</html>
