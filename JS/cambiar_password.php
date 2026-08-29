<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
si_requerir_sesion(false);

if ((int) ($_SESSION['debe_cambiar_password'] ?? 0) !== 1) {
    header('Location: ' . si_url('JS/dashboard.php'));
    exit;
}

$csrf = si_token_csrf();
$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_cambiar_password.css';
$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';
$nombre = trim((string) ($_SESSION['nombre_completo'] ?? $_SESSION['usuario'] ?? 'Usuario'));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Cambiar contraseña | Sistema Integral</title>
    <link rel="stylesheet" href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>">
    <link rel="stylesheet" href="../css/style_cambiar_password.css?v=<?= si_escapar($versionModulo) ?>">
</head>
<body class="forced-password-page">
<main class="forced-password-shell">
    <section class="forced-password-card">
        <header>
            <div class="forced-password-icon">SI</div>
            <div>
                <small>SEGURIDAD DE LA CUENTA</small>
                <h1>Cambia tu contraseña temporal</h1>
            </div>
        </header>

        <p>
            Hola <strong><?= si_escapar($nombre) ?></strong>. Un Administrador restableció tu acceso
            o esta es una cuenta nueva. Antes de continuar debes crear una contraseña personal.
        </p>

        <div id="mensajeCambio" class="forced-password-message" hidden></div>

        <form id="formCambioPassword">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrf) ?>">
            <input type="hidden" name="accion" value="CAMBIAR_PASSWORD">

            <label>
                <span>Contraseña temporal actual</span>
                <input type="password" name="password_actual" minlength="10" maxlength="72" autocomplete="current-password" required autofocus>
            </label>

            <label>
                <span>Nueva contraseña</span>
                <input type="password" name="nueva_password" minlength="10" maxlength="72" autocomplete="new-password" required>
            </label>

            <label>
                <span>Confirmar nueva contraseña</span>
                <input type="password" name="confirmar_password" minlength="10" maxlength="72" autocomplete="new-password" required>
            </label>

            <small class="forced-password-help">Debe tener entre 10 y 72 caracteres y ser diferente de la contraseña temporal.</small>

            <button type="submit" id="btnCambiarPassword">Guardar nueva contraseña</button>
        </form>

        <form action="<?= si_escapar(si_url('funciones/logout.php')) ?>" method="post" class="forced-password-logout">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrf) ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </section>
</main>

<script>
(function () {
    'use strict';
    const form = document.getElementById('formCambioPassword');
    const mensaje = document.getElementById('mensajeCambio');
    const boton = document.getElementById('btnCambiarPassword');

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        mensaje.hidden = true;
        boton.disabled = true;
        const original = boton.textContent;
        boton.textContent = 'Actualizando...';

        try {
            const response = await fetch('../funciones/perfil_funciones.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const text = await response.text();
            let data;
            try { data = JSON.parse(text); } catch (e) { throw new Error('El servidor devolvió una respuesta no válida.'); }
            if (!response.ok || data.success !== true) throw new Error(data.mensaje || 'No fue posible cambiar la contraseña.');
            mensaje.className = 'forced-password-message is-success';
            mensaje.textContent = data.mensaje;
            mensaje.hidden = false;
            window.setTimeout(function () {
                window.location.href = data.redirect || '../JS/dashboard.php';
            }, 500);
        } catch (error) {
            mensaje.className = 'forced-password-message is-error';
            mensaje.textContent = error.message || 'Ocurrió un error inesperado.';
            mensaje.hidden = false;
            boton.disabled = false;
            boton.textContent = original;
        }
    });
})();
</script>
</body>
</html>
