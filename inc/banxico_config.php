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
 * Configuración segura para la consulta del FIX de Banco de México.
 * El token NO debe guardarse en este archivo versionable.
 */
function si_banxico_config(): array
{
    $config = [
        'activo' => true,
        'serie_fix' => 'SF43718',
        'token' => trim((string) (getenv('BANXICO_TOKEN') ?: '')),
        'timeout_segundos' => 8,
        'dias_habiles_alerta' => 2,
    ];

    $archivoLocal = __DIR__ . '/banxico_config.local.php';
    if (is_file($archivoLocal)) {
        $local = require $archivoLocal;
        if (is_array($local)) {
            $config = array_replace($config, $local);
        }
    }

    $config['activo'] = (bool) ($config['activo'] ?? true);
    $config['serie_fix'] = strtoupper(trim((string) ($config['serie_fix'] ?? 'SF43718')));
    $config['token'] = trim((string) ($config['token'] ?? ''));
    $config['timeout_segundos'] = max(3, min(20, (int) ($config['timeout_segundos'] ?? 8)));
    $config['dias_habiles_alerta'] = max(1, min(10, (int) ($config['dias_habiles_alerta'] ?? 2)));

    return $config;
}
