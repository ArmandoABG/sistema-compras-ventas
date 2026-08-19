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
             WHERE deleted_at IS NULL"
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
        href="css/style_login.css?v=<?= si_escapar($cssVersion) ?>"
    >
</head>
<body>

<main class="login-page">
    <section class="login-card">
        <header class="login-heading">
            <div class="login-logo">SI</div>

            <div>
                <small>SISTEMA INTEGRAL</small>
                <h1>Iniciar sesión</h1>
            </div>
        </header>

        <p class="login-description">
            Compras, ventas, inventario y control empresarial.
        </p>

        <?php if ($mensajeInicial !== ''): ?>
            <div
                class="login-message login-message--<?= si_escapar($tipoMensajeInicial) ?>"
            >
                <?= si_escapar($mensajeInicial) ?>
            </div>
        <?php endif; ?>

        <?php if (!($conexion instanceof PDO)): ?>
            <div class="login-message login-message--error">
                No fue posible conectar con la base de datos.
                Revisa <strong>inc/conexion.php</strong>.
            </div>
        <?php endif; ?>

        <?php if (!$hayUsuarios && $conexion instanceof PDO): ?>
            <div class="login-message login-message--warning">
                Todavía no existe ningún usuario.
                <a href="JS/crear_admin_inicial.php">
                    Crear administrador inicial
                </a>
            </div>
        <?php endif; ?>

        <div
            id="mensajeLogin"
            class="login-message login-message--error"
            role="alert"
            hidden
        ></div>

        <form
            id="formLogin"
            action="funciones/login_funciones.php"
            method="post"
            autocomplete="on"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= si_escapar($csrfLogin) ?>"
            >

            <label for="usuario">Usuario</label>

            <input
                id="usuario"
                type="text"
                name="usuario"
                maxlength="60"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Contraseña</label>

            <div class="password-field">
                <input
                    id="password"
                    type="password"
                    name="password"
                    maxlength="255"
                    autocomplete="current-password"
                    required
                >

                <button
                    id="btnMostrarPassword"
                    class="password-field__toggle"
                    type="button"
                >
                    Mostrar
                </button>
            </div>

            <button
                id="btnLogin"
                class="btn-primary"
                type="submit"
                <?= (!$hayUsuarios || !($conexion instanceof PDO))
                    ? 'disabled'
                    : '' ?>
            >
                Iniciar sesión
            </button>
        </form>
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

        const textoOriginal = boton.textContent;
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
            boton.textContent = textoOriginal;
        }
    });
})();
</script>

</body>
</html>
