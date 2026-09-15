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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnóstico de seguridad</title>
    <style>
        :root{color-scheme:dark;--bg:#141916;--panel:#080d0a;--surface:#111813;--field:#0b120e;--border:rgba(147,171,156,.18);--text:#f1f5f2;--soft:#bdc8c1;--muted:#829087;--green:#7bcda0}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:28px;color:var(--soft);background:radial-gradient(circle at 15% 15%,rgba(41,112,73,.12),transparent 26%),var(--bg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.55}
        main{width:min(820px,100%);margin:0 auto;padding:30px;border:1px solid var(--border);border-radius:22px;background:var(--panel);box-shadow:0 28px 80px rgba(0,0,0,.3)}
        header{padding-bottom:18px;border-bottom:1px solid var(--border)}header small{color:var(--green);font-size:11px;font-weight:800;letter-spacing:.09em}h1{margin:5px 0 4px;color:var(--text);font-size:clamp(28px,5vw,40px);letter-spacing:-.04em}header p{margin:0;color:var(--muted)}
        .diagnostic-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:20px 0}.diagnostic-item{margin:0;padding:14px;border:1px solid var(--border);border-radius:13px;background:var(--surface)}.diagnostic-item strong{display:block;margin-bottom:4px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em}.diagnostic-item span{color:var(--text);overflow-wrap:anywhere}
        details{border:1px solid var(--border);border-radius:13px;background:var(--surface);overflow:hidden}summary{padding:14px 16px;color:var(--text);font-weight:750;cursor:pointer}pre{max-height:360px;margin:0;padding:16px;overflow:auto;border-top:1px solid var(--border);color:var(--soft);background:var(--field);font-size:12px;line-height:1.6}
        nav{display:flex;flex-wrap:wrap;gap:9px;margin-top:20px}a{display:inline-flex;align-items:center;min-height:40px;padding:8px 13px;border:1px solid var(--border);border-radius:10px;color:var(--soft);background:var(--surface);font-size:13px;font-weight:750;text-decoration:none;transition:border-color .16s ease,color .16s ease,background-color .16s ease}a:hover,a:focus-visible{border-color:rgba(123,205,160,.45);color:var(--green);background:rgba(52,114,77,.13);outline:none}
        @media(max-width:600px){body{padding:12px}main{padding:21px 17px;border-radius:18px}.diagnostic-grid{grid-template-columns:1fr}nav a{width:100%;justify-content:center}}@media(prefers-reduced-motion:reduce){*{transition-duration:.01ms!important}}
    </style>
</head>
<body>
<main>
    <header>
        <small>SEGURIDAD · SOLO LECTURA</small>
        <h1>Diagnóstico de seguridad</h1>
        <p>Identidad, acceso y permisos efectivos de la sesión actual.</p>
    </header>

    <section class="diagnostic-grid">
    <p class="diagnostic-item">
        <strong>Base conectada:</strong>
        <span><?= si_escapar($bdActual) ?></span>
    </p>

    <p class="diagnostic-item">
        <strong>Usuario:</strong>
        <span><?= si_escapar($usuario['usuario'] ?? 'desconocido') ?>
        (ID <?= (int) ($usuario['id'] ?? 0) ?>)</span>
    </p>

    <p class="diagnostic-item">
        <strong>Activo:</strong>
        <span><?= (int) ($usuario['activo'] ?? 0) === 1 ? 'SÍ' : 'NO' ?></span>
    </p>

    <p class="diagnostic-item">
        <strong>Roles detectados:</strong>
        <span><?= si_escapar(
            implode(
                ', ',
                array_column(
                    $identidad['roles'],
                    'codigo'
                )
            )
        ) ?></span>
    </p>

    <p class="diagnostic-item">
        <strong>Permisos cargados:</strong>
        <span><?= count($identidad['permisos']) ?></span>
    </p>

    <p class="diagnostic-item">
        <strong>usuarios.ver:</strong>
        <span><?= $tieneUsuarios ? 'SÍ' : 'NO' ?></span>
    </p>
    </section>

    <details>
        <summary>Ver todos los permisos</summary>
        <pre><?= si_escapar(
            implode(
                PHP_EOL,
                $identidad['permisos']
            )
        ) ?></pre>
    </details>

    <nav>
        <a href="usuarios.php">
            Probar módulo Usuarios
        </a>
        <a href="dashboard.php">
            Volver al Dashboard
        </a>
    </nav>
</main>
</body>
</html>
