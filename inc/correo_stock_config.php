<?php

declare(strict_types=1);

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

/**
 * Configuración central del remitente para alertas de inventario.
 *
 * Los secretos SMTP se cargan desde correo_stock_config.local.php,
 * que debe permanecer fuera del control de versiones.
 */
function si_correo_stock_config(): array
{
    $config = [
        'activo' => false,
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_usuario' => 'gymportezuelo585@gmail.com',
        'smtp_password' => 'pesr akmw dlhl zqrh',
        'smtp_timeout' => 12,
        'remitente_correo' => 'gymportezuelo585@gmail.com',
        'remitente_nombre' => 'Sistema Integral',
        'hora_resumen_diario' => '08:00',
        'reintento_minutos' => 30,
        'intervalo_proceso_segundos' => 45,
    ];

    $archivoLocal = __DIR__ . '/correo_stock_config.local.php';
    if (!is_file($archivoLocal)) {
        return $config;
    }

    $local = require $archivoLocal;
    if (!is_array($local)) {
        return $config;
    }

    return array_replace($config, $local);
}
