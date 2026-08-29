<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/notificaciones_stock_email.php';

si_requerir_sesion(true);
si_requerir_metodo('POST');
si_validar_csrf();

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

try {
    $resultado = si_stock_email_procesar($conexion);
    si_responder_json(true, 'Revisión de alertas de inventario completada.', ['resultado' => $resultado]);
} catch (Throwable $e) {
    $ref = 'EMAIL-STOCK-' . date('Ymd-His');
    error_log('[' . $ref . '] ' . $e->getMessage());
    si_responder_json(false, 'No fue posible procesar las alertas por correo.', ['referencia' => $ref], 500);
}
