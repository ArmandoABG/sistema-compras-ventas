<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONEXIÓN OFICIAL DEL SISTEMA
|--------------------------------------------------------------------------
| Se elimina cualquier dependencia de variables de entorno para evitar que
| Apache/PHP pueda apuntar accidentalmente a otra base distinta.
|--------------------------------------------------------------------------
*/

date_default_timezone_set('America/Mexico_City');

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

$host = '127.0.0.1';
$puerto = '3306';
$dbname = 'TU_BASE_DE_DATOS';
$usuarioBd = 'TU_USUARIO';
$passwordBd = 'TU_CONTRASEÑA';
$charset = 'utf8mb4';

$conexion = null;
$error_conexion = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $puerto,
        $dbname,
        $charset
    );

    $conexion = new PDO(
        $dsn,
        $usuarioBd,
        $passwordBd,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );

    $offset = (
        new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Mexico_City')
        )
    )->format('P');

    $conexion->exec(
        'SET time_zone = ' . $conexion->quote($offset)
    );

} catch (PDOException $e) {
    $conexion = null;
    $error_conexion =
        'No fue posible establecer conexión con la base de datos.';

    error_log(
        '[SISTEMA INTEGRAL][CONEXION] '
        . $e->getMessage()
    );
}
