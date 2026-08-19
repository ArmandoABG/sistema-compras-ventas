<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/seguridad.php';

if (si_sesion_autenticada()) {
    header('Location: ' . si_url('JS/dashboard.php'));
    exit;
}

header('Location: ' . si_url('login.php'));
exit;
