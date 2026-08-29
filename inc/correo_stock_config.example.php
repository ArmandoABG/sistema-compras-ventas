<?php

declare(strict_types=1);

// Copia este archivo como correo_stock_config.local.php y completa tus datos.
return [
    'activo' => false,
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_usuario' => '',
    'smtp_password' => '',
    'smtp_timeout' => 12,
    'remitente_correo' => '',
    'remitente_nombre' => 'Sistema Integral',
    'hora_resumen_diario' => '08:00',
    'reintento_minutos' => 30,
    'intervalo_proceso_segundos' => 45,
];
