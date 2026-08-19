<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

if (
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
    !== 'POST'
) {
    header(
        'Location: ' . si_url('login.php')
    );
    exit;
}

if (!si_sesion_autenticada()) {
    header(
        'Location: ' . si_url('login.php')
    );
    exit;
}

$token = $_POST['csrf_token'] ?? null;

if (!si_csrf_valido(
    is_string($token) ? $token : null
)) {
    header(
        'Location: ' . si_url('JS/dashboard.php')
    );
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$sesionDbId = (int) (
    $_SESSION['sesion_db_id'] ?? 0
);

try {
    if ($conexion instanceof PDO) {
        $conexion->beginTransaction();

        if ($sesionDbId > 0) {
            $conexion->prepare(
                "UPDATE sesiones_usuario
                 SET fin_sesion = NOW(),
                     motivo_cierre = 'LOGOUT',
                     activa = 0
                 WHERE id = :id
                   AND usuario_id = :usuario_id
                   AND activa = 1"
            )->execute([
                ':id' => $sesionDbId,
                ':usuario_id' => $usuarioId,
            ]);
        }

        $conexion->prepare(
            "INSERT INTO auditoria
                (
                    usuario_id,
                    accion,
                    modulo,
                    entidad_tabla,
                    entidad_id,
                    descripcion,
                    ip,
                    user_agent
                )
             VALUES
                (
                    :usuario_id,
                    'LOGOUT',
                    'seguridad',
                    'usuarios',
                    :entidad_id,
                    'Cierre de sesión realizado por el usuario.',
                    :ip,
                    :user_agent
                )"
        )->execute([
            ':usuario_id' => $usuarioId,
            ':entidad_id' => $usuarioId,
            ':ip' => si_ip_cliente(),
            ':user_agent' => si_user_agent(),
        ]);

        $conexion->commit();
    }
} catch (Throwable $e) {
    if (
        $conexion instanceof PDO
        && $conexion->inTransaction()
    ) {
        $conexion->rollBack();
    }

    error_log(
        '[SISTEMA INTEGRAL][LOGOUT] '
        . $e->getMessage()
    );
}

si_destruir_sesion();

header(
    'Location: ' . si_url('login.php?logout=1')
);
exit;
