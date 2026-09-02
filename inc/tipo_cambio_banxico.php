<?php

declare(strict_types=1);

require_once __DIR__ . '/banxico_config.php';

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

/**
 * Devuelve el tipo de cambio de una moneda hacia la moneda base.
 * Para USD -> MXN intenta sincronizar el FIX oficial de Banxico una vez al día.
 * Si Internet/Banxico falla, utiliza el último valor local disponible.
 *
 * @return array{tipo_cambio:float,fecha:string,fuente:?string,es_inverso:bool,desactualizado:bool,dias_habiles_antiguedad:int}|null
 */
function si_tc_resolver_a_base(PDO $conexion, int $monedaId, string $fecha, bool $permitirSincronizacion = true): ?array
{
    $fecha = si_tc_normalizar_fecha($fecha) ?? date('Y-m-d');

    $stmt = $conexion->prepare(
        "SELECT id, codigo, es_base
         FROM monedas
         WHERE id = :id AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $monedaId]);
    $moneda = $stmt->fetch();
    if (!$moneda) {
        return null;
    }

    if ((int) $moneda['es_base'] === 1) {
        return [
            'tipo_cambio' => 1.0,
            'fecha' => $fecha,
            'fuente' => 'Moneda base',
            'es_inverso' => false,
            'desactualizado' => false,
            'dias_habiles_antiguedad' => 0,
        ];
    }

    $base = $conexion->query(
        "SELECT id, codigo
         FROM monedas
         WHERE es_base = 1 AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();

    if (!$base) {
        return null;
    }

    $baseId = (int) $base['id'];
    $codigoOrigen = strtoupper((string) $moneda['codigo']);
    $codigoBase = strtoupper((string) $base['codigo']);

    if (
        $permitirSincronizacion
        && !$conexion->inTransaction()
        && $fecha >= date('Y-m-d')
        && $codigoOrigen === 'USD'
        && $codigoBase === 'MXN'
    ) {
        si_tc_banxico_sincronizar($conexion, false);
    }

    $directo = si_tc_buscar_local($conexion, $monedaId, $baseId, $fecha);
    if ($directo !== null) {
        $dias = si_tc_dias_habiles_entre((string) $directo['fecha'], $fecha);
        return [
            'tipo_cambio' => (float) $directo['tipo_cambio'],
            'fecha' => (string) $directo['fecha'],
            'fuente' => $directo['fuente'] !== null ? (string) $directo['fuente'] : null,
            'es_inverso' => false,
            'desactualizado' => $dias > (int) (si_banxico_config()['dias_habiles_alerta'] ?? 2),
            'dias_habiles_antiguedad' => $dias,
        ];
    }

    $inverso = si_tc_buscar_local($conexion, $baseId, $monedaId, $fecha);
    if ($inverso === null || (float) $inverso['tipo_cambio'] <= 0) {
        return null;
    }

    $dias = si_tc_dias_habiles_entre((string) $inverso['fecha'], $fecha);
    return [
        'tipo_cambio' => 1 / (float) $inverso['tipo_cambio'],
        'fecha' => (string) $inverso['fecha'],
        'fuente' => trim((string) ($inverso['fuente'] ?? '')) !== ''
            ? (string) $inverso['fuente'] . ' · inverso'
            : 'Par inverso',
        'es_inverso' => true,
        'desactualizado' => $dias > (int) (si_banxico_config()['dias_habiles_alerta'] ?? 2),
        'dias_habiles_antiguedad' => $dias,
    ];
}

/**
 * Sincroniza el dato oportuno FIX de Banco de México.
 * Nunca lanza una excepción hacia el flujo comercial: devuelve estado y permite fallback local.
 *
 * @return array<string,mixed>
 */
function si_tc_banxico_sincronizar(PDO $conexion, bool $forzar = false): array
{
    static $resultadoPeticion = null;
    if (!$forzar && is_array($resultadoPeticion)) {
        return $resultadoPeticion;
    }

    $config = si_banxico_config();
    $hoy = date('Y-m-d');

    $base = $conexion->query(
        "SELECT id, codigo FROM monedas WHERE es_base = 1 AND activo = 1 ORDER BY id ASC LIMIT 1"
    )->fetch();
    $stmtUsd = $conexion->prepare("SELECT id, codigo FROM monedas WHERE codigo = 'USD' AND activo = 1 LIMIT 1");
    $stmtUsd->execute();
    $usd = $stmtUsd->fetch();

    if (!$base || strtoupper((string) $base['codigo']) !== 'MXN' || !$usd) {
        return $resultadoPeticion = [
            'ok' => false,
            'consultado' => false,
            'codigo' => 'PAR_NO_DISPONIBLE',
            'mensaje' => 'La sincronización Banxico aplica únicamente al par USD/MXN con MXN como moneda base.',
        ];
    }

    $ultimoLocal = si_tc_buscar_local($conexion, (int) $usd['id'], (int) $base['id'], $hoy);

    if (!(bool) ($config['activo'] ?? true)) {
        return $resultadoPeticion = si_tc_estado_desde_local($ultimoLocal, false, 'DESACTIVADO', 'La sincronización automática con Banxico está desactivada.');
    }

    $token = trim((string) ($config['token'] ?? ''));
    if ($token === '') {
        return $resultadoPeticion = si_tc_estado_desde_local(
            $ultimoLocal,
            false,
            'TOKEN_NO_CONFIGURADO',
            'Falta configurar el token SIE de Banco de México en inc/banxico_config.local.php.'
        );
    }

    $ultimoIntentoDia = si_tc_config_valor($conexion, 'banxico.ultima_consulta_dia');
    $ultimoIntentoAt = si_tc_config_valor($conexion, 'banxico.ultima_consulta_at');
    $ultimoEstado = strtoupper((string) (si_tc_config_valor($conexion, 'banxico.ultimo_estado') ?? ''));

    if (!$forzar && $ultimoLocal !== null && (string) $ultimoLocal['fecha'] === $hoy) {
        return $resultadoPeticion = si_tc_estado_desde_local(
            $ultimoLocal,
            false,
            'DATO_DE_HOY',
            'El FIX de hoy ya está guardado localmente.'
        );
    }

    if (!$forzar && $ultimoIntentoDia === $hoy) {
        $ahora = new DateTimeImmutable('now');
        $esFinSemana = (int) $ahora->format('N') >= 6;
        $horaPublicacion = $ahora->setTime(12, 10, 0);
        $ultimoIntento = $ultimoIntentoAt ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ultimoIntentoAt) : false;
        $intentoPosteriorPublicacion = $ultimoIntento instanceof DateTimeImmutable && $ultimoIntento >= $horaPublicacion;
        $minutosDesdeIntento = $ultimoIntento instanceof DateTimeImmutable
            ? max(0, (int) floor(($ahora->getTimestamp() - $ultimoIntento->getTimestamp()) / 60))
            : PHP_INT_MAX;

        $debeReintentar = false;
        if (!$esFinSemana && $ahora >= $horaPublicacion && !$intentoPosteriorPublicacion) {
            // Si se consultó temprano, damos una segunda oportunidad después de la publicación normal del FIX.
            $debeReintentar = true;
        } elseif ($ultimoEstado === 'ERROR' && $minutosDesdeIntento >= 30) {
            // Un error transitorio de red puede reintentarse después de 30 minutos.
            $debeReintentar = true;
        }

        if (!$debeReintentar) {
            return $resultadoPeticion = si_tc_estado_desde_local(
                $ultimoLocal,
                false,
                'YA_CONSULTADO_HOY',
                'Banxico ya fue consultado hoy; se utiliza el dato local guardado.'
            );
        }
    }

    // Marcamos el intento antes de salir a red para reducir consultas duplicadas concurrentes.
    si_tc_config_guardar($conexion, 'banxico.ultima_consulta_dia', $hoy, 'Último día en que se intentó consultar el FIX de Banxico.');
    si_tc_config_guardar($conexion, 'banxico.ultima_consulta_at', date('Y-m-d H:i:s'), 'Fecha y hora del último intento de consulta a Banxico.');

    $serie = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($config['serie_fix'] ?? 'SF43718')));
    if ($serie === '') {
        $serie = 'SF43718';
    }

    $url = 'https://www.banxico.org.mx/SieAPIRest/service/v1/series/' . rawurlencode($serie) . '/datos/oportuno';

    try {
        $respuesta = si_tc_http_get_json(
            $url,
            ['Bmx-Token: ' . $token, 'Accept: application/json'],
            (int) ($config['timeout_segundos'] ?? 8)
        );

        $dato = si_tc_banxico_extraer_dato($respuesta, $serie);
        if ($dato === null) {
            throw new RuntimeException('La respuesta de Banxico no contiene un dato FIX válido.');
        }

        $fechaDato = $dato['fecha'];
        $valor = $dato['valor'];

        $stmt = $conexion->prepare(
            "INSERT INTO tipos_cambio
                (moneda_origen_id, moneda_destino_id, fecha, tipo_cambio, fuente)
             VALUES
                (:origen, :destino, :fecha, :tipo_cambio, :fuente)
             ON DUPLICATE KEY UPDATE
                tipo_cambio = VALUES(tipo_cambio),
                fuente = VALUES(fuente)"
        );
        $stmt->execute([
            ':origen' => (int) $usd['id'],
            ':destino' => (int) $base['id'],
            ':fecha' => $fechaDato,
            ':tipo_cambio' => $valor,
            ':fuente' => 'Banco de México SIE · FIX ' . $serie,
        ]);

        si_tc_config_guardar($conexion, 'banxico.ultimo_dato_fecha', $fechaDato, 'Fecha del último FIX guardado desde Banxico.');
        si_tc_config_guardar($conexion, 'banxico.ultimo_dato_valor', number_format($valor, 8, '.', ''), 'Último FIX USD/MXN guardado desde Banxico.');
        si_tc_config_guardar($conexion, 'banxico.ultimo_estado', 'OK', 'Estado de la última consulta a Banxico.');
        si_tc_config_guardar($conexion, 'banxico.ultimo_mensaje', 'Consulta correcta.', 'Mensaje de la última consulta a Banxico.');

        $ultimoLocal = si_tc_buscar_local($conexion, (int) $usd['id'], (int) $base['id'], $hoy);
        return $resultadoPeticion = si_tc_estado_desde_local(
            $ultimoLocal,
            true,
            'ACTUALIZADO',
            'Tipo de cambio FIX actualizado desde Banco de México.'
        );
    } catch (Throwable $e) {
        $mensajeInterno = substr($e->getMessage(), 0, 220);
        error_log('[SISTEMA INTEGRAL][BANXICO] ' . $mensajeInterno);

        try {
            si_tc_config_guardar($conexion, 'banxico.ultimo_estado', 'ERROR', 'Estado de la última consulta a Banxico.');
            si_tc_config_guardar($conexion, 'banxico.ultimo_mensaje', $mensajeInterno, 'Mensaje de la última consulta a Banxico.');
        } catch (Throwable $ignorado) {
            // El error de bitácora no debe bloquear operaciones comerciales.
        }

        return $resultadoPeticion = si_tc_estado_desde_local(
            $ultimoLocal,
            false,
            'ERROR_RED',
            'No fue posible consultar Banxico. Se conserva el último tipo de cambio local disponible.'
        );
    }
}

/** @return array<string,mixed> */
function si_tc_banxico_estado(PDO $conexion, bool $intentarSincronizar = false): array
{
    if ($intentarSincronizar) {
        return si_tc_banxico_sincronizar($conexion, false);
    }

    $base = $conexion->query(
        "SELECT id, codigo FROM monedas WHERE es_base = 1 AND activo = 1 ORDER BY id ASC LIMIT 1"
    )->fetch();
    $stmtUsd = $conexion->prepare("SELECT id FROM monedas WHERE codigo = 'USD' AND activo = 1 LIMIT 1");
    $stmtUsd->execute();
    $usdId = (int) ($stmtUsd->fetchColumn() ?: 0);

    if (!$base || strtoupper((string) $base['codigo']) !== 'MXN' || $usdId <= 0) {
        return ['ok' => false, 'codigo' => 'PAR_NO_DISPONIBLE', 'mensaje' => 'El par USD/MXN no está configurado.'];
    }

    $local = si_tc_buscar_local($conexion, $usdId, (int) $base['id'], date('Y-m-d'));
    return si_tc_estado_desde_local($local, false, 'LOCAL', 'Estado del último tipo de cambio local.');
}

/** @return array<string,mixed> */
function si_tc_estado_desde_local(?array $local, bool $consultado, string $codigo, string $mensaje): array
{
    $config = si_banxico_config();
    $fecha = $local['fecha'] ?? null;
    $dias = $fecha ? si_tc_dias_habiles_entre((string) $fecha, date('Y-m-d')) : PHP_INT_MAX;
    $limite = (int) ($config['dias_habiles_alerta'] ?? 2);

    return [
        'ok' => $local !== null,
        'consultado' => $consultado,
        'codigo' => $codigo,
        'mensaje' => $mensaje,
        'fecha' => $fecha,
        'tipo_cambio' => $local !== null ? (float) $local['tipo_cambio'] : null,
        'fuente' => $local['fuente'] ?? null,
        'dias_habiles_antiguedad' => $local !== null ? $dias : null,
        'desactualizado' => $local === null || $dias > $limite,
        'token_configurado' => trim((string) ($config['token'] ?? '')) !== '',
        'serie' => (string) ($config['serie_fix'] ?? 'SF43718'),
    ];
}

/** @return array{tipo_cambio:float,fecha:string,fuente:?string}|null */
function si_tc_buscar_local(PDO $conexion, int $origenId, int $destinoId, string $fecha): ?array
{
    $stmt = $conexion->prepare(
        "SELECT tipo_cambio, fecha, fuente
         FROM tipos_cambio
         WHERE moneda_origen_id = :origen
           AND moneda_destino_id = :destino
           AND fecha <= :fecha
         ORDER BY fecha DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':origen' => $origenId,
        ':destino' => $destinoId,
        ':fecha' => $fecha,
    ]);
    $fila = $stmt->fetch();
    if (!$fila || (float) $fila['tipo_cambio'] <= 0) {
        return null;
    }

    return [
        'tipo_cambio' => (float) $fila['tipo_cambio'],
        'fecha' => (string) $fila['fecha'],
        'fuente' => $fila['fuente'] !== null ? (string) $fila['fuente'] : null,
    ];
}

function si_tc_config_valor(PDO $conexion, string $clave): ?string
{
    try {
        $stmt = $conexion->prepare("SELECT valor_texto FROM configuracion_sistema WHERE clave = :clave LIMIT 1");
        $stmt->execute([':clave' => $clave]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? null : (string) $valor;
    } catch (Throwable $e) {
        return null;
    }
}

function si_tc_config_guardar(PDO $conexion, string $clave, string $valor, string $descripcion): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO configuracion_sistema
            (clave, valor_texto, tipo, descripcion, es_publica)
         VALUES
            (:clave, :valor, 'TEXTO', :descripcion, 0)
         ON DUPLICATE KEY UPDATE
            valor_texto = VALUES(valor_texto),
            descripcion = VALUES(descripcion),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':clave' => $clave,
        ':valor' => $valor,
        ':descripcion' => $descripcion,
    ]);
}

/** @return array<string,mixed> */
function si_tc_http_get_json(string $url, array $headers, int $timeout): array
{
    $cuerpo = '';
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No fue posible inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SistemaIntegral/1.0 BanxicoSIE',
        ]);

        $respuesta = curl_exec($ch);
        if ($respuesta === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Error de conexión con Banxico: ' . $error);
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $cuerpo = (string) $respuesta;
    } else {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", array_merge($headers, ['User-Agent: SistemaIntegral/1.0 BanxicoSIE'])),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $respuesta = @file_get_contents($url, false, $contexto);
        if ($respuesta === false) {
            throw new RuntimeException('No fue posible establecer conexión HTTPS con Banxico.');
        }
        $cuerpo = (string) $respuesta;

        $encabezados = $http_response_header ?? [];
        foreach ($encabezados as $linea) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $linea, $m)) {
                $httpCode = (int) $m[1];
            }
        }
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Banxico respondió con HTTP ' . $httpCode . '.');
    }

    $json = json_decode($cuerpo, true);
    if (!is_array($json)) {
        throw new RuntimeException('Banxico devolvió una respuesta JSON inválida.');
    }

    return $json;
}

/** @return array{fecha:string,valor:float}|null */
function si_tc_banxico_extraer_dato(array $respuesta, string $serieEsperada): ?array
{
    $series = $respuesta['bmx']['series'] ?? null;
    if (!is_array($series)) {
        return null;
    }

    foreach ($series as $serie) {
        if (!is_array($serie)) {
            continue;
        }
        $idSerie = strtoupper(trim((string) ($serie['idSerie'] ?? '')));
        if ($idSerie !== '' && $idSerie !== strtoupper($serieEsperada)) {
            continue;
        }

        $datos = $serie['datos'] ?? null;
        if (!is_array($datos) || $datos === []) {
            continue;
        }

        $ultimo = end($datos);
        if (!is_array($ultimo)) {
            continue;
        }

        $fecha = si_tc_normalizar_fecha((string) ($ultimo['fecha'] ?? ''));
        $textoDato = str_replace([',', ' '], '', (string) ($ultimo['dato'] ?? ''));
        if ($fecha === null || !is_numeric($textoDato)) {
            continue;
        }

        $valor = (float) $textoDato;
        if ($valor <= 0 || $valor > 1000) {
            continue;
        }

        return ['fecha' => $fecha, 'valor' => $valor];
    }

    return null;
}

function si_tc_normalizar_fecha(string $fecha): ?string
{
    $fecha = trim($fecha);
    if ($fecha === '') {
        return null;
    }

    foreach (['Y-m-d', 'd/m/Y'] as $formato) {
        $dt = DateTimeImmutable::createFromFormat('!' . $formato, $fecha);
        $errores = DateTimeImmutable::getLastErrors();
        if ($dt instanceof DateTimeImmutable && ($errores === false || (($errores['warning_count'] ?? 0) === 0 && ($errores['error_count'] ?? 0) === 0))) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function si_tc_dias_habiles_entre(string $desde, string $hasta): int
{
    $inicioTexto = si_tc_normalizar_fecha($desde);
    $finTexto = si_tc_normalizar_fecha($hasta);
    if ($inicioTexto === null || $finTexto === null || $inicioTexto >= $finTexto) {
        return 0;
    }

    $inicio = new DateTimeImmutable($inicioTexto);
    $fin = new DateTimeImmutable($finTexto);
    $dias = 0;

    for ($d = $inicio->modify('+1 day'); $d <= $fin; $d = $d->modify('+1 day')) {
        $n = (int) $d->format('N');
        if ($n <= 5) {
            $dias++;
        }
    }

    return $dias;
}
