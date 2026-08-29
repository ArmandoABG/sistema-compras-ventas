<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('roles.administrar', false);

if (!($conexion instanceof PDO)) {
    http_response_code(503);
    exit('No hay conexión con MySQL.');
}

$usuarioId = (int) $_SESSION['usuario_id'];

$identidad = si_cargar_identidad_sesion(
    $conexion,
    $usuarioId
);

$stmt = $conexion->prepare(
    "SELECT
        id,
        usuario,
        nombres,
        activo
     FROM usuarios
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute([
    ':id' => $usuarioId,
]);

$usuario = $stmt->fetch();

$bdActual = (string) $conexion->query(
    "SELECT DATABASE()"
)->fetchColumn();

$tieneUsuarios = si_tiene_permiso(
    'usuarios.ver'
);

header(
    'Content-Type: text/html; charset=utf-8'
);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Diagnóstico de seguridad</title>
</head>
<body style="font-family:Arial;padding:30px;line-height:1.5">
    <h1>Diagnóstico de seguridad</h1>

    <p>
        <strong>Base conectada:</strong>
        <?= si_escapar($bdActual) ?>
    </p>

    <p>
        <strong>Usuario:</strong>
        <?= si_escapar($usuario['usuario'] ?? 'desconocido') ?>
        (ID <?= (int) ($usuario['id'] ?? 0) ?>)
    </p>

    <p>
        <strong>Activo:</strong>
        <?= (int) ($usuario['activo'] ?? 0) === 1 ? 'SÍ' : 'NO' ?>
    </p>

    <p>
        <strong>Roles detectados:</strong>
        <?= si_escapar(
            implode(
                ', ',
                array_column(
                    $identidad['roles'],
                    'codigo'
                )
            )
        ) ?>
    </p>

    <p>
        <strong>Permisos cargados:</strong>
        <?= count($identidad['permisos']) ?>
    </p>

    <p>
        <strong>usuarios.ver:</strong>
        <?= $tieneUsuarios ? 'SÍ' : 'NO' ?>
    </p>

    <details>
        <summary>Ver todos los permisos</summary>
        <pre><?= si_escapar(
            implode(
                PHP_EOL,
                $identidad['permisos']
            )
        ) ?></pre>
    </details>

    <p>
        <a href="usuarios.php">
            Probar módulo Usuarios
        </a>
        |
        <a href="dashboard.php">
            Volver al Dashboard
        </a>
    </p>
</body>
</html>
