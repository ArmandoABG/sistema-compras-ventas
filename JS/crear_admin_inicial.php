<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Endpoint interno del módulo
|--------------------------------------------------------------------------
| Mantiene el mismo estilo del sistema de mantenimiento:
| la interfaz puede llamar a esta misma página y esta reenvía al archivo
| de funciones.
|--------------------------------------------------------------------------
*/

if (isset($_GET['admin_inicial_api'])) {
    $endpoint =
        __DIR__
        . '/../funciones/crear_admin_inicial_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode([
            'success' => false,
            'mensaje' =>
                'No se encontró el archivo de funciones del módulo.',
        ]);

        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

if (!($conexion instanceof PDO)) {
    $errorConexion = true;
    $hayUsuarios = false;
} else {
    $errorConexion = false;

    $hayUsuarios = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM usuarios
         WHERE deleted_at IS NULL"
    )->fetchColumn() > 0;
}

if ($hayUsuarios) {
    header(
        'Location: ' . si_url('login.php')
    );
    exit;
}

$csrf = si_token_csrf(
    'csrf_admin_inicial'
);

$cssPath =
    __DIR__
    . '/../css/style_login.css';

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
        content="width=device-width, initial-scale=1.0"
    >

    <meta name="robots" content="noindex, nofollow">

    <title>
        Administrador inicial | Sistema Integral
    </title>

    <link
        rel="stylesheet"
        href="../css/style_login.css?v=<?= si_escapar($cssVersion) ?>"
    >
</head>
<body>

<main class="login-page">
    <section class="login-card login-card--wide">

        <header class="login-heading">
            <div class="login-logo">SI</div>

            <div>
                <small>CONFIGURACIÓN INICIAL</small>
                <h1>Administrador principal</h1>
            </div>
        </header>

        <p class="login-description">
            Esta pantalla solamente funciona mientras
            la tabla de usuarios esté vacía.
        </p>

        <?php if ($errorConexion): ?>
            <div class="login-message login-message--error">
                No fue posible conectar con la base de datos.
            </div>
        <?php endif; ?>

        <div
            id="mensajeAdmin"
            class="login-message login-message--error"
            hidden
        ></div>

        <form
            id="formAdmin"
            action="?admin_inicial_api=1"
            method="post"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= si_escapar($csrf) ?>"
            >

            <div class="form-grid">
                <label>
                    <span>Usuario *</span>
                    <input
                        type="text"
                        name="usuario"
                        maxlength="60"
                        required
                    >
                </label>

                <label>
                    <span>Nombres *</span>
                    <input
                        type="text"
                        name="nombres"
                        maxlength="120"
                        required
                    >
                </label>

                <label>
                    <span>Apellido paterno</span>
                    <input
                        type="text"
                        name="apellido_paterno"
                        maxlength="100"
                    >
                </label>

                <label>
                    <span>Apellido materno</span>
                    <input
                        type="text"
                        name="apellido_materno"
                        maxlength="100"
                    >
                </label>

                <label>
                    <span>Correo</span>
                    <input
                        type="email"
                        name="correo"
                        maxlength="180"
                    >
                </label>

                <label>
                    <span>Teléfono</span>
                    <input
                        type="text"
                        name="telefono"
                        maxlength="30"
                    >
                </label>

                <label>
                    <span>Contraseña *</span>
                    <input
                        type="password"
                        name="password"
                        minlength="10"
                        maxlength="72"
                        required
                    >
                </label>

                <label>
                    <span>Confirmar contraseña *</span>
                    <input
                        type="password"
                        name="confirmar_password"
                        minlength="10"
                        maxlength="72"
                        required
                    >
                </label>
            </div>

            <button
                id="btnCrearAdmin"
                class="btn-primary"
                type="submit"
                <?= $errorConexion ? 'disabled' : '' ?>
            >
                Crear administrador
            </button>
        </form>

        <a
            class="secondary-link"
            href="../login.php"
        >
            Volver al login
        </a>
    </section>
</main>

<script>
(function () {
    'use strict';

    const form = document.getElementById('formAdmin');
    const mensaje = document.getElementById('mensajeAdmin');
    const boton = document.getElementById('btnCrearAdmin');

    if (!form) {
        return;
    }

    form.addEventListener(
        'submit',
        async function (event) {
            event.preventDefault();

            mensaje.hidden = true;
            boton.disabled = true;

            const textoOriginal = boton.textContent;
            boton.textContent = 'Guardando...';

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

                if (
                    !respuesta.ok
                    || datos.success !== true
                ) {
                    throw new Error(
                        datos.mensaje
                        || 'No fue posible crear el administrador.'
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
        }
    );
})();
</script>

</body>
</html>
