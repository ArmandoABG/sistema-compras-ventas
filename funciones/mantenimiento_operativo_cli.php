<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/mantenimiento_operativo.php';

if (!($conexion instanceof PDO)) {
    fwrite(STDERR, "No fue posible conectar con la base de datos.\n");
    exit(1);
}

try {
    $resultado = si_mantenimiento_operativo_ejecutar($conexion);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[MANTENIMIENTO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
