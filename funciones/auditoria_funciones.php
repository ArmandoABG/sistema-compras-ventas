<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('auditoria.ver', true);
si_requerir_metodo('GET');

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$accion = strtoupper(trim((string) ($_GET['accion'] ?? 'INICIAL')));

try {
    switch ($accion) {
        case 'INICIAL':
            aud_inicial($conexion);
            break;
        case 'LISTAR':
            aud_listar($conexion);
            break;
        case 'DETALLE':
            aud_detalle($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    $referencia = 'AUD-' . date('Ymd-His');
    error_log('[' . $referencia . '][AUDITORIA][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible consultar la auditoría.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    $referencia = 'AUD-' . date('Ymd-His');
    error_log('[' . $referencia . '][AUDITORIA] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al consultar la auditoría.', ['referencia' => $referencia], 500);
}

function aud_inicial(PDO $conexion): void
{
    $usuarios = $conexion->query(
        "SELECT id, usuario, nombres, apellido_paterno, apellido_materno, activo
         FROM usuarios
         ORDER BY nombres, apellido_paterno, usuario"
    )->fetchAll();

    $modulos = $conexion->query(
        "SELECT DISTINCT modulo
         FROM auditoria
         WHERE modulo IS NOT NULL AND TRIM(modulo) <> ''
         ORDER BY modulo"
    )->fetchAll(PDO::FETCH_COLUMN);

    $acciones = $conexion->query(
        "SELECT DISTINCT accion
         FROM auditoria
         WHERE accion IS NOT NULL AND TRIM(accion) <> ''
         ORDER BY accion"
    )->fetchAll(PDO::FETCH_COLUMN);

    $entidades = $conexion->query(
        "SELECT DISTINCT entidad_tabla
         FROM auditoria
         WHERE entidad_tabla IS NOT NULL AND TRIM(entidad_tabla) <> ''
         ORDER BY entidad_tabla"
    )->fetchAll(PDO::FETCH_COLUMN);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(fecha_hora >= CURRENT_DATE()) AS hoy,
            SUM(fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS ultimos_7_dias,
            COUNT(DISTINCT CASE WHEN fecha_hora >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN usuario_id END) AS usuarios_30_dias,
            MAX(fecha_hora) AS ultima_actividad
         FROM auditoria"
    )->fetch() ?: [];

    si_responder_json(true, 'Auditoría preparada correctamente.', [
        'usuarios' => array_map(static function (array $u): array {
            $nombre = trim(implode(' ', array_filter([
                (string) ($u['nombres'] ?? ''),
                (string) ($u['apellido_paterno'] ?? ''),
                (string) ($u['apellido_materno'] ?? ''),
            ], static fn($v) => $v !== '')));
            return [
                'id' => (int) $u['id'],
                'usuario' => (string) $u['usuario'],
                'nombre' => $nombre !== '' ? $nombre : (string) $u['usuario'],
                'activo' => (int) $u['activo'] === 1,
            ];
        }, $usuarios),
        'modulos' => array_values(array_map('strval', $modulos)),
        'acciones' => array_values(array_map('strval', $acciones)),
        'entidades' => array_values(array_map('strval', $entidades)),
        'resumen' => [
            'total' => (int) ($resumen['total'] ?? 0),
            'hoy' => (int) ($resumen['hoy'] ?? 0),
            'ultimos_7_dias' => (int) ($resumen['ultimos_7_dias'] ?? 0),
            'usuarios_30_dias' => (int) ($resumen['usuarios_30_dias'] ?? 0),
            'ultima_actividad' => $resumen['ultima_actividad'] ?? null,
        ],
    ]);
}

function aud_listar(PDO $conexion): void
{
    $f = aud_filtros();
    $pagina = aud_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = aud_entero($_GET['por_pagina'] ?? 25, 10, 100, 25);
    if (!in_array($porPagina, [10, 25, 50, 100], true)) {
        $porPagina = 25;
    }

    [$whereSql, $params] = aud_where($f);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE {$whereSql}");
    aud_execute($stmtTotal, $params);
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                a.id,
                a.fecha_hora,
                a.usuario_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)), ''), u.usuario, 'Sistema') AS usuario_nombre,
                COALESCE(u.usuario, 'sistema') AS usuario_login,
                a.accion,
                a.modulo,
                a.entidad_tabla,
                a.entidad_id,
                a.descripcion,
                a.ip,
                (a.datos_anteriores IS NOT NULL) AS tiene_anteriores,
                (a.datos_nuevos IS NOT NULL) AS tiene_nuevos
            FROM auditoria a
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE {$whereSql}
            ORDER BY a.fecha_hora DESC, a.id DESC
            LIMIT :limite OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    aud_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    si_responder_json(true, 'Auditoría consultada correctamente.', [
        'registros' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'fecha_hora' => (string) $r['fecha_hora'],
            'usuario_id' => $r['usuario_id'] !== null ? (int) $r['usuario_id'] : null,
            'usuario_nombre' => (string) $r['usuario_nombre'],
            'usuario_login' => (string) $r['usuario_login'],
            'accion' => (string) $r['accion'],
            'modulo' => (string) $r['modulo'],
            'entidad_tabla' => $r['entidad_tabla'] !== null ? (string) $r['entidad_tabla'] : null,
            'entidad_id' => $r['entidad_id'] !== null ? (int) $r['entidad_id'] : null,
            'descripcion' => $r['descripcion'] !== null ? (string) $r['descripcion'] : '',
            'ip' => $r['ip'] !== null ? (string) $r['ip'] : '',
            'tiene_cambios' => ((int) $r['tiene_anteriores'] === 1 || (int) $r['tiene_nuevos'] === 1),
        ], $filas),
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function aud_detalle(PDO $conexion): void
{
    $id = aud_entero($_GET['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'Selecciona un registro de auditoría válido.', [], 400);
    }

    $stmt = $conexion->prepare(
        "SELECT
            a.*,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)), ''), u.usuario, 'Sistema') AS usuario_nombre,
            COALESCE(u.usuario, 'sistema') AS usuario_login
         FROM auditoria a
         LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        si_responder_json(false, 'El registro de auditoría ya no está disponible.', [], 404);
    }

    $anteriores = aud_json_seguro($fila['datos_anteriores'] ?? null);
    $nuevos = aud_json_seguro($fila['datos_nuevos'] ?? null);

    si_responder_json(true, 'Detalle de auditoría cargado.', [
        'registro' => [
            'id' => (int) $fila['id'],
            'fecha_hora' => (string) $fila['fecha_hora'],
            'usuario_id' => $fila['usuario_id'] !== null ? (int) $fila['usuario_id'] : null,
            'usuario_nombre' => (string) $fila['usuario_nombre'],
            'usuario_login' => (string) $fila['usuario_login'],
            'accion' => (string) $fila['accion'],
            'modulo' => (string) $fila['modulo'],
            'entidad_tabla' => $fila['entidad_tabla'] !== null ? (string) $fila['entidad_tabla'] : null,
            'entidad_id' => $fila['entidad_id'] !== null ? (int) $fila['entidad_id'] : null,
            'descripcion' => $fila['descripcion'] !== null ? (string) $fila['descripcion'] : '',
            'ip' => $fila['ip'] !== null ? (string) $fila['ip'] : '',
            'user_agent' => $fila['user_agent'] !== null ? (string) $fila['user_agent'] : '',
            'datos_anteriores' => $anteriores,
            'datos_nuevos' => $nuevos,
            'cambios' => aud_comparar($anteriores, $nuevos),
        ],
    ]);
}

function aud_filtros(): array
{
    $usuario = aud_entero($_GET['usuario_id'] ?? 0, -1, PHP_INT_MAX, 0);
    return [
        'buscar' => aud_texto($_GET['buscar'] ?? '', 160),
        'usuario_id' => $usuario,
        'modulo' => aud_texto($_GET['modulo'] ?? '', 80),
        'accion' => aud_texto($_GET['accion_filtro'] ?? '', 60),
        'entidad' => aud_texto($_GET['entidad'] ?? '', 80),
        'entidad_id' => aud_entero($_GET['entidad_id'] ?? 0, 0, PHP_INT_MAX, 0),
        'ip' => aud_texto($_GET['ip'] ?? '', 45),
        'fecha_desde' => aud_fecha($_GET['fecha_desde'] ?? ''),
        'fecha_hasta' => aud_fecha($_GET['fecha_hasta'] ?? ''),
    ];
}

function aud_where(array $f): array
{
    $where = ['1=1'];
    $params = [];

    if ($f['usuario_id'] === -1) {
        $where[] = 'a.usuario_id IS NULL';
    } elseif ($f['usuario_id'] > 0) {
        $where[] = 'a.usuario_id = :usuario_id';
        $params[':usuario_id'] = $f['usuario_id'];
    }
    if ($f['modulo'] !== '') {
        $where[] = 'a.modulo = :modulo';
        $params[':modulo'] = $f['modulo'];
    }
    if ($f['accion'] !== '') {
        $where[] = 'a.accion = :accion';
        $params[':accion'] = $f['accion'];
    }
    if ($f['entidad'] !== '') {
        $where[] = 'a.entidad_tabla = :entidad';
        $params[':entidad'] = $f['entidad'];
    }
    if ($f['entidad_id'] > 0) {
        $where[] = 'a.entidad_id = :entidad_id';
        $params[':entidad_id'] = $f['entidad_id'];
    }
    if ($f['ip'] !== '') {
        $where[] = 'a.ip = :ip';
        $params[':ip'] = $f['ip'];
    }
    if ($f['fecha_desde'] !== '') {
        $where[] = 'a.fecha_hora >= :fecha_desde';
        $params[':fecha_desde'] = $f['fecha_desde'] . ' 00:00:00';
    }
    if ($f['fecha_hasta'] !== '') {
        $where[] = 'a.fecha_hora < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)';
        $params[':fecha_hasta'] = $f['fecha_hasta'] . ' 00:00:00';
    }
    if ($f['buscar'] !== '') {
        $where[] = "(a.descripcion LIKE :buscar ESCAPE '\\\\' OR a.accion LIKE :buscar_acc ESCAPE '\\\\' OR a.modulo LIKE :buscar_mod ESCAPE '\\\\' OR a.entidad_tabla LIKE :buscar_ent ESCAPE '\\\\' OR a.ip LIKE :buscar_ip ESCAPE '\\\\' OR u.usuario LIKE :buscar_usr ESCAPE '\\\\' OR u.nombres LIKE :buscar_nom ESCAPE '\\\\')";
        $like = '%' . aud_like($f['buscar']) . '%';
        $params[':buscar'] = $like;
        $params[':buscar_acc'] = $like;
        $params[':buscar_mod'] = $like;
        $params[':buscar_ent'] = $like;
        $params[':buscar_ip'] = $like;
        $params[':buscar_usr'] = $like;
        $params[':buscar_nom'] = $like;
    }

    return [implode(' AND ', $where), $params];
}

function aud_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function aud_execute(PDOStatement $stmt, array $params): void
{
    aud_bind($stmt, $params);
    $stmt->execute();
}

function aud_json_seguro($valor)
{
    if ($valor === null || $valor === '') {
        return null;
    }
    if (is_array($valor)) {
        return aud_redactar($valor);
    }
    if (!is_string($valor)) {
        return aud_redactar($valor);
    }
    $decodificado = json_decode($valor, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['_valor_no_json' => mb_substr($valor, 0, 4000)];
    }
    return aud_redactar($decodificado);
}

function aud_redactar($valor, ?string $clave = null)
{
    if ($clave !== null && aud_clave_sensible($clave)) {
        return '[OCULTO POR SEGURIDAD]';
    }
    if (!is_array($valor)) {
        return $valor;
    }
    $salida = [];
    foreach ($valor as $k => $v) {
        $salida[$k] = aud_redactar($v, is_string($k) ? $k : null);
    }
    return $salida;
}

function aud_clave_sensible(string $clave): bool
{
    $normal = strtolower(trim($clave));
    $sensibles = ['password', 'password_hash', 'contrasena', 'contraseña', 'csrf', 'csrf_token', 'secret', 'secreto', 'api_key', 'apikey', 'access_token', 'refresh_token', 'token_sesion', 'session_token', 'token'];
    $contieneTokenSensible = str_contains($normal, 'token') && !str_ends_with($normal, '_id');
    return in_array($normal, $sensibles, true)
        || str_contains($normal, 'password')
        || str_contains($normal, 'contrasena')
        || str_contains($normal, 'contraseña')
        || str_contains($normal, 'secret')
        || $contieneTokenSensible;
}

function aud_comparar($anterior, $nuevo): array
{
    $a = aud_aplanar($anterior);
    $n = aud_aplanar($nuevo);
    $claves = array_values(array_unique(array_merge(array_keys($a), array_keys($n))));
    sort($claves, SORT_NATURAL | SORT_FLAG_CASE);
    $cambios = [];
    foreach ($claves as $clave) {
        $existeA = array_key_exists($clave, $a);
        $existeN = array_key_exists($clave, $n);
        $va = $existeA ? $a[$clave] : null;
        $vn = $existeN ? $n[$clave] : null;
        if ($existeA && $existeN && $va === $vn) {
            continue;
        }
        $cambios[] = [
            'campo' => $clave,
            'anterior' => $existeA ? $va : null,
            'nuevo' => $existeN ? $vn : null,
            'tipo' => !$existeA ? 'AGREGADO' : (!$existeN ? 'ELIMINADO' : 'CAMBIADO'),
        ];
        if (count($cambios) >= 250) {
            break;
        }
    }
    return $cambios;
}

function aud_aplanar($valor, string $prefijo = ''): array
{
    if ($valor === null) {
        return [];
    }
    if (!is_array($valor)) {
        return [$prefijo !== '' ? $prefijo : 'valor' => aud_valor_mostrable($valor)];
    }
    $salida = [];
    foreach ($valor as $k => $v) {
        $clave = $prefijo === '' ? (string) $k : $prefijo . '.' . $k;
        if (is_array($v)) {
            $salida += aud_aplanar($v, $clave);
        } else {
            $salida[$clave] = aud_valor_mostrable($v);
        }
        if (count($salida) >= 500) {
            break;
        }
    }
    return $salida;
}

function aud_valor_mostrable($valor)
{
    if (is_bool($valor)) {
        return $valor ? 'true' : 'false';
    }
    if ($valor === null) {
        return null;
    }
    if (is_scalar($valor)) {
        return (string) $valor;
    }
    return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function aud_texto($valor, int $max): string
{
    if (!is_scalar($valor)) {
        return '';
    }
    return trim(mb_substr((string) $valor, 0, $max));
}

function aud_entero($valor, int $min, int $max, int $default): int
{
    $filtrado = filter_var($valor, FILTER_VALIDATE_INT);
    if ($filtrado === false) {
        return $default;
    }
    $entero = (int) $filtrado;
    return ($entero >= $min && $entero <= $max) ? $entero : $default;
}

function aud_fecha($valor): string
{
    $texto = aud_texto($valor, 10);
    if ($texto === '') {
        return '';
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
    return $fecha && $fecha->format('Y-m-d') === $texto ? $texto : '';
}

function aud_like(string $texto): string
{
    return strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
}
