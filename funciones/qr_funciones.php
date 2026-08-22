<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/qr_core.php';

si_requerir_permiso('qr.verificar', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'HISTORIAL')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'RESUMEN':
                qr_resumen($conexion);
                break;
            case 'HISTORIAL':
                qr_historial($conexion);
                break;
            case 'DETALLE_VERIFICACION':
                qr_detalle_verificacion($conexion);
                break;
            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'CONSULTAR':
            qr_consultar($conexion);
            break;
        case 'CONFIRMAR_SALIDA':
            qr_confirmar_salida($conexion);
            break;
        case 'RECHAZAR_SALIDA':
            qr_rechazar_salida($conexion);
            break;
        case 'REHABILITAR_QR':
            qr_rehabilitar_qr($conexion);
            break;
        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'QR-' . date('Ymd-His');
    error_log('[' . $referencia . '][QR][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible procesar la operación QR.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'QR-' . date('Ymd-His');
    error_log('[' . $referencia . '][QR] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar el QR.', ['referencia' => $referencia], 500);
}

function qr_resumen(PDO $conexion): void
{
    $hoy = date('Y-m-d');
    $stmt = $conexion->prepare(
        "SELECT
            COUNT(*) AS decisiones_hoy,
            SUM(resultado = 'VALIDO') AS salidas_hoy,
            SUM(resultado = 'RECHAZADO') AS rechazadas_hoy,
            SUM(resultado IN ('INVALIDO','CANCELADO','NO_PAGADO','YA_VERIFICADO')) AS incidencias_hoy
         FROM verificaciones_qr_venta
         WHERE fecha_verificacion >= :inicio_hoy
           AND fecha_verificacion < :inicio_manana"
    );
    $stmt->execute([
        ':inicio_hoy' => $hoy . ' 00:00:00',
        ':inicio_manana' => date('Y-m-d 00:00:00', strtotime($hoy . ' +1 day')),
    ]);
    $k = $stmt->fetch() ?: [];

    $tokens = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(activo = 1 AND usado_at IS NULL AND revocado_at IS NULL) AS activos,
            SUM(usado_at IS NOT NULL) AS usados,
            SUM(revocado_at IS NOT NULL AND usado_at IS NULL) AS revocados
         FROM tokens_qr_venta"
    )->fetch() ?: [];

    si_responder_json(true, 'Resumen QR cargado.', [
        'habilitado' => si_qr_habilitado($conexion),
        'kpis' => [
            'decisiones_hoy' => (int) ($k['decisiones_hoy'] ?? 0),
            'salidas_hoy' => (int) ($k['salidas_hoy'] ?? 0),
            'rechazadas_hoy' => (int) ($k['rechazadas_hoy'] ?? 0),
            'incidencias_hoy' => (int) ($k['incidencias_hoy'] ?? 0),
            'tokens_activos' => (int) ($tokens['activos'] ?? 0),
            'tokens_usados' => (int) ($tokens['usados'] ?? 0),
            'tokens_revocados' => (int) ($tokens['revocados'] ?? 0),
        ],
    ]);
}

function qr_historial(PDO $conexion): void
{
    $pagina = qr_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = qr_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = qr_texto($_GET['buscar'] ?? '', 180);
    $resultado = strtoupper(qr_texto($_GET['resultado'] ?? 'TODOS', 30));
    $desde = qr_fecha($_GET['desde'] ?? '');
    $hasta = qr_fecha($_GET['hasta'] ?? '');

    $resultadosValidos = [
        'TODOS', 'CONSULTADO', 'VALIDO', 'RECHAZADO', 'INVALIDO',
        'CANCELADO', 'NO_PAGADO', 'YA_VERIFICADO',
    ];
    if (!in_array($resultado, $resultadosValidos, true)) {
        $resultado = 'TODOS';
    }
    if ($desde !== null && $hasta !== null && $desde > $hasta) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la final.', [], 422);
    }

    $where = [];
    $params = [];

    if ($resultado !== 'TODOS') {
        $where[] = 'qv.resultado = :resultado';
        $params[':resultado'] = $resultado;
    }
    if ($desde !== null) {
        $where[] = 'qv.fecha_verificacion >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== null) {
        $where[] = 'qv.fecha_verificacion < :hasta_exclusivo';
        $params[':hasta_exclusivo'] = date('Y-m-d 00:00:00', strtotime($hasta . ' +1 day'));
    }
    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $tokenBuscar = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $buscar));
        if (strlen($tokenBuscar) >= 4) {
            $where[] = '(v.folio LIKE :buscar_folio OR v.cliente_nombre_snapshot LIKE :buscar_cliente OR t.token LIKE :buscar_token)';
            $params[':buscar_token'] = '%' . $tokenBuscar . '%';
        } else {
            $where[] = '(v.folio LIKE :buscar_folio OR v.cliente_nombre_snapshot LIKE :buscar_cliente)';
        }
        $params[':buscar_folio'] = $like;
        $params[':buscar_cliente'] = $like;
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtCount = $conexion->prepare(
        "SELECT COUNT(*)
         FROM verificaciones_qr_venta qv
         INNER JOIN tokens_qr_venta t ON t.id = qv.token_qr_id
         INNER JOIN ventas v ON v.id = qv.venta_id
         {$sqlWhere}"
    );
    qr_bind($stmtCount, $params);
    $stmtCount->execute();
    $total = (int) $stmtCount->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            qv.id,
            qv.fecha_verificacion,
            qv.resultado,
            qv.observaciones,
            v.id AS venta_id,
            v.folio AS venta_folio,
            v.cliente_nombre_snapshot,
            v.condicion_pago,
            v.total,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            t.token,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS verificado_por
         FROM verificaciones_qr_venta qv
         INNER JOIN tokens_qr_venta t ON t.id = qv.token_qr_id
         INNER JOIN ventas v ON v.id = qv.venta_id
         INNER JOIN monedas m ON m.id = v.moneda_id
         LEFT JOIN usuarios u ON u.id = qv.usuario_id
         {$sqlWhere}
         ORDER BY qv.fecha_verificacion DESC, qv.id DESC
         LIMIT :limite OFFSET :offset"
    );
    qr_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['venta_id'] = (int) $fila['venta_id'];
        $fila['total'] = (float) $fila['total'];
        $fila['token_corto'] = si_qr_token_corto((string) $fila['token']);
        unset($fila['token']);
    }
    unset($fila);

    si_responder_json(true, 'Historial cargado.', [
        'verificaciones' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total_registros' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function qr_detalle_verificacion(PDO $conexion): void
{
    $id = qr_entero($_GET['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'La verificación indicada no es válida.', [], 422);
    }

    $stmt = $conexion->prepare(
        "SELECT
            qv.id,
            qv.token_qr_id,
            qv.venta_id,
            qv.fecha_verificacion,
            qv.resultado,
            qv.ip,
            qv.observaciones,
            t.token,
            t.activo AS token_activo,
            t.generado_at,
            t.usado_at,
            t.revocado_at,
            t.motivo_revocacion,
            CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS verificado_por,
            CONCAT_WS(' ', uu.nombres, uu.apellido_paterno, uu.apellido_materno) AS salida_confirmada_por
         FROM verificaciones_qr_venta qv
         INNER JOIN tokens_qr_venta t ON t.id = qv.token_qr_id
         LEFT JOIN usuarios u ON u.id = qv.usuario_id
         LEFT JOIN usuarios uu ON uu.id = t.usado_by
         WHERE qv.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $verificacion = $stmt->fetch();
    if (!$verificacion) {
        si_responder_json(false, 'La verificación ya no existe.', [], 404);
    }

    $venta = si_qr_resumen_venta($conexion, (int) $verificacion['venta_id']);
    $detalles = si_qr_detalles_venta($conexion, (int) $verificacion['venta_id']);
    $verificacion['id'] = (int) $verificacion['id'];
    $verificacion['token_qr_id'] = (int) $verificacion['token_qr_id'];
    $verificacion['venta_id'] = (int) $verificacion['venta_id'];
    $verificacion['token_activo'] = (int) $verificacion['token_activo'];
    $verificacion['token_corto'] = si_qr_token_corto((string) $verificacion['token']);
    unset($verificacion['token']);

    si_responder_json(true, 'Detalle cargado.', [
        'verificacion' => $verificacion,
        'venta' => $venta,
        'detalles' => $detalles,
    ]);
}

function qr_consultar(PDO $conexion): void
{
    if (!si_qr_habilitado($conexion)) {
        si_responder_json(false, 'La validación QR de salida está deshabilitada en la configuración del sistema.', [], 409);
    }

    $entrada = qr_texto($_POST['codigo'] ?? '', 1200);
    if ($entrada === '') {
        si_responder_json(false, 'Escanea, pega o escribe una referencia de venta.', [], 422);
    }

    $referencia = qr_resolver_referencia($conexion, $entrada, false, true);
    if ($referencia === null) {
        si_qr_auditar($conexion, 'QR_CONSULTA_INVALIDA', null, 'Se consultó una referencia QR/venta que no pertenece al sistema.', [
            'entrada_resumida' => mb_substr($entrada, 0, 120),
        ]);
        si_responder_json(true, 'No se encontró una venta o QR emitido por este sistema.', [
            'resultado' => 'INVALIDO',
            'venta' => null,
            'detalles' => [],
            'qr' => null,
            'puede_confirmar_salida' => false,
            'puede_rechazar_salida' => false,
            'puede_rehabilitar_qr' => false,
        ]);
    }

    $respuesta = qr_preparar_consulta($conexion, $referencia);

    si_qr_auditar(
        $conexion,
        'QR_CONSULTADO',
        (int) $respuesta['venta']['id'],
        'Se consultó el estado de salida de la venta ' . $respuesta['venta']['folio'] . '.',
        [
            'resultado' => $respuesta['resultado'],
            'tipo_referencia' => $referencia['tipo_referencia'],
            'token_id' => $referencia['token'] !== null ? (int) $referencia['token']['id'] : null,
        ]
    );

    si_responder_json(true, $respuesta['mensaje'], $respuesta);
}

function qr_confirmar_salida(PDO $conexion): void
{
    if (!si_qr_habilitado($conexion)) {
        si_responder_json(false, 'La validación QR de salida está deshabilitada en la configuración del sistema.', [], 409);
    }

    $entrada = qr_texto($_POST['codigo'] ?? '', 1200);
    if ($entrada === '') {
        si_responder_json(false, 'La referencia de la venta es obligatoria.', [], 422);
    }

    $conexion->beginTransaction();
    $referencia = qr_resolver_referencia($conexion, $entrada, true, true);
    if ($referencia === null) {
        $conexion->rollBack();
        si_responder_json(false, 'La referencia ya no corresponde a una venta o QR válido.', [], 404);
    }

    $respuesta = qr_preparar_consulta($conexion, $referencia);
    if (!$respuesta['puede_confirmar_salida']) {
        $conexion->commit();
        si_responder_json(true, 'La salida no puede confirmarse en el estado actual. Se volvió a validar la venta.', $respuesta);
    }

    $token = $referencia['token'];
    if (!is_array($token)) {
        throw new RuntimeException('La venta no tiene un token QR disponible para confirmar la salida.');
    }

    $usuarioId = (int) $_SESSION['usuario_id'];
    $stmtUso = $conexion->prepare(
        "UPDATE tokens_qr_venta
         SET activo = 0,
             usado_at = NOW(),
             usado_by = :usado_by
         WHERE id = :token_id
           AND activo = 1
           AND usado_at IS NULL
           AND revocado_at IS NULL"
    );
    $stmtUso->execute([
        ':usado_by' => $usuarioId,
        ':token_id' => (int) $token['id'],
    ]);
    if ($stmtUso->rowCount() !== 1) {
        $conexion->rollBack();
        si_responder_json(false, 'El QR cambió de estado mientras se confirmaba. Vuelve a consultarlo antes de continuar.', [], 409);
    }

    $observacion = $respuesta['venta']['condicion_pago'] === 'CREDITO'
        && $respuesta['venta']['estado_pago'] !== 'PAGADO'
        ? 'Salida física confirmada: venta a crédito con saldo pendiente.'
        : 'Salida física confirmada por el usuario.';

    $verificacionId = qr_insertar_verificacion(
        $conexion,
        (int) $token['id'],
        (int) $respuesta['venta']['id'],
        'VALIDO',
        $observacion
    );

    si_qr_auditar(
        $conexion,
        'SALIDA_QR_CONFIRMADA',
        (int) $respuesta['venta']['id'],
        'Se confirmó físicamente la salida de la venta ' . $respuesta['venta']['folio'] . ' mediante QR.',
        [
            'verificacion_id' => $verificacionId,
            'token_id' => (int) $token['id'],
            'estado_pago' => $respuesta['venta']['estado_pago'],
        ]
    );

    $conexion->commit();

    $respuesta['resultado'] = 'VALIDO';
    $respuesta['mensaje'] = 'Salida confirmada. Este QR ya no puede utilizarse para autorizar otra salida.';
    $respuesta['puede_confirmar_salida'] = false;
    $respuesta['puede_rechazar_salida'] = false;
    $respuesta['qr']['activo'] = 0;
    $respuesta['qr']['usado_at'] = date('Y-m-d H:i:s');
    $respuesta['qr']['usado_por'] = (string) ($_SESSION['nombre_completo'] ?? $_SESSION['usuario'] ?? 'Usuario actual');
    $respuesta['verificacion'] = [
        'id' => $verificacionId,
        'resultado' => 'VALIDO',
        'fecha_verificacion' => date('Y-m-d H:i:s'),
        'observaciones' => $observacion,
        'token_corto' => $respuesta['qr']['token_corto'],
    ];

    si_responder_json(true, $respuesta['mensaje'], $respuesta);
}

function qr_rechazar_salida(PDO $conexion): void
{
    if (!si_qr_habilitado($conexion)) {
        si_responder_json(false, 'La validación QR de salida está deshabilitada en la configuración del sistema.', [], 409);
    }

    $entrada = qr_texto($_POST['codigo'] ?? '', 1200);
    $motivo = qr_texto($_POST['motivo'] ?? '', 255);
    if ($entrada === '') {
        si_responder_json(false, 'La referencia de la venta es obligatoria.', [], 422);
    }
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Escribe un motivo de rechazo de al menos 5 caracteres.', [], 422);
    }

    $conexion->beginTransaction();
    $referencia = qr_resolver_referencia($conexion, $entrada, true, true);
    if ($referencia === null) {
        $conexion->rollBack();
        si_responder_json(false, 'La referencia ya no corresponde a una venta o QR válido.', [], 404);
    }

    $respuesta = qr_preparar_consulta($conexion, $referencia);
    if (!$respuesta['puede_rechazar_salida']) {
        $conexion->commit();
        si_responder_json(true, 'Esta salida ya no admite un rechazo manual en su estado actual.', $respuesta);
    }

    $token = $referencia['token'];
    if (!is_array($token)) {
        throw new RuntimeException('No existe un token QR para registrar el rechazo.');
    }

    $observacion = 'Salida rechazada: ' . $motivo;
    $verificacionId = qr_insertar_verificacion(
        $conexion,
        (int) $token['id'],
        (int) $respuesta['venta']['id'],
        'RECHAZADO',
        $observacion
    );

    si_qr_auditar(
        $conexion,
        'SALIDA_QR_RECHAZADA',
        (int) $respuesta['venta']['id'],
        'Se rechazó la salida de la venta ' . $respuesta['venta']['folio'] . '.',
        [
            'verificacion_id' => $verificacionId,
            'token_id' => (int) $token['id'],
            'motivo' => $motivo,
        ]
    );

    $conexion->commit();

    $respuesta['resultado'] = 'RECHAZADO';
    $respuesta['mensaje'] = 'Rechazo registrado. El QR no se consumió; puede volver a consultarse cuando la incidencia se resuelva.';
    $respuesta['puede_confirmar_salida'] = false;
    $respuesta['puede_rechazar_salida'] = false;
    $respuesta['verificacion'] = [
        'id' => $verificacionId,
        'resultado' => 'RECHAZADO',
        'fecha_verificacion' => date('Y-m-d H:i:s'),
        'observaciones' => $observacion,
        'token_corto' => $respuesta['qr']['token_corto'],
    ];

    si_responder_json(true, $respuesta['mensaje'], $respuesta);
}

function qr_rehabilitar_qr(PDO $conexion): void
{
    si_refrescar_identidad_sesion_actual();
    if (!qr_es_administrador_actual()) {
        si_responder_json(false, 'Solo un Administrador puede rehabilitar un QR ya utilizado.', [], 403);
    }

    $entrada = qr_texto($_POST['codigo'] ?? '', 1200);
    $motivo = qr_texto($_POST['motivo'] ?? '', 255);
    if ($entrada === '') {
        si_responder_json(false, 'La referencia de la venta es obligatoria.', [], 422);
    }
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Escribe el motivo de la rehabilitación con al menos 5 caracteres.', [], 422);
    }

    $conexion->beginTransaction();
    $referencia = qr_resolver_referencia($conexion, $entrada, true, false);
    if ($referencia === null || !is_array($referencia['token'] ?? null)) {
        $conexion->rollBack();
        si_responder_json(false, 'No se encontró un QR emitido para esta venta.', [], 404);
    }

    $venta = si_qr_resumen_venta($conexion, (int) $referencia['venta_id']);
    $token = $referencia['token'];
    if (!$venta || $venta['estado'] !== 'CONFIRMADA') {
        $conexion->rollBack();
        si_responder_json(false, 'Solo se puede rehabilitar el QR de una venta confirmada.', [], 409);
    }
    if ($token['revocado_at'] !== null) {
        $conexion->rollBack();
        si_responder_json(false, 'Este QR fue revocado por una cancelación y no puede rehabilitarse desde salida.', [], 409);
    }
    if ($token['usado_at'] === null) {
        $conexion->rollBack();
        si_responder_json(false, 'Este QR no está marcado como utilizado; no necesita rehabilitación.', [], 409);
    }

    $antes = [
        'activo' => (int) $token['activo'],
        'usado_at' => $token['usado_at'],
        'usado_by' => $token['usado_by'],
        'token_corto' => si_qr_token_corto((string) $token['token']),
    ];

    $stmt = $conexion->prepare(
        "UPDATE tokens_qr_venta
         SET activo = 1,
             usado_at = NULL,
             usado_by = NULL
         WHERE id = :token_id
           AND usado_at IS NOT NULL
           AND revocado_at IS NULL"
    );
    $stmt->execute([':token_id' => (int) $token['id']]);
    if ($stmt->rowCount() !== 1) {
        $conexion->rollBack();
        si_responder_json(false, 'El QR cambió de estado mientras se rehabilitaba. Vuelve a consultarlo.', [], 409);
    }

    $despues = [
        'activo' => 1,
        'usado_at' => null,
        'usado_by' => null,
        'motivo_rehabilitacion' => $motivo,
        'token_corto' => $antes['token_corto'],
    ];

    $stmtAudit = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, fecha_hora, accion, modulo, entidad_tabla, entidad_id,
             descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, NOW(), 'QR_REHABILITADO', 'QR', 'ventas', :entidad_id,
             :descripcion, :datos_anteriores, :datos_nuevos, :ip, :user_agent)"
    );
    $stmtAudit->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':entidad_id' => (int) $venta['id'],
        ':descripcion' => mb_substr('El Administrador rehabilitó el QR de la venta ' . $venta['folio'] . '. Motivo: ' . $motivo, 0, 500),
        ':datos_anteriores' => json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ':datos_nuevos' => json_encode($despues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ':ip' => si_ip_cliente(),
        ':user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);

    $conexion->commit();

    $actual = qr_resolver_referencia($conexion, $entrada, false, false);
    if ($actual === null) {
        throw new RuntimeException('El QR fue rehabilitado, pero no pudo recargarse su estado.');
    }
    $respuesta = qr_preparar_consulta($conexion, $actual);
    $respuesta['mensaje'] = 'QR rehabilitado correctamente. La venta vuelve a quedar disponible para una nueva confirmación de salida.';
    $respuesta['rehabilitado'] = true;
    $respuesta['motivo_rehabilitacion'] = $motivo;

    si_responder_json(true, $respuesta['mensaje'], $respuesta);
}

function qr_es_administrador_actual(): bool
{
    $roles = $_SESSION['roles'] ?? [];
    return is_array($roles) && in_array('ADMINISTRADOR', $roles, true);
}

/**
 * Resuelve token completo, referencia corta ABCDEF-123456 o folio de venta.
 * Para ventas CONFIRMADAS antiguas sin QR, crea el token una sola vez.
 */
function qr_resolver_referencia(PDO $conexion, string $entrada, bool $bloquear, bool $crearTokenSiFalta): ?array
{
    $entrada = trim($entrada);
    if ($entrada === '') {
        return null;
    }

    $tokenId = null;
    $ventaId = null;
    $tipo = null;

    $tokenNormalizado = si_qr_normalizar_token($entrada);
    if ($tokenNormalizado !== null) {
        $stmt = $conexion->prepare("SELECT id, venta_id FROM tokens_qr_venta WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $tokenNormalizado]);
        $fila = $stmt->fetch();
        if ($fila) {
            $tokenId = (int) $fila['id'];
            $ventaId = (int) $fila['venta_id'];
            $tipo = 'TOKEN';
        }
    }

    if ($ventaId === null && preg_match('/\b([A-F0-9]{6})-([A-F0-9]{6})\b/i', $entrada, $m) === 1) {
        $stmt = $conexion->prepare(
            "SELECT id, venta_id
             FROM tokens_qr_venta
             WHERE LEFT(token, 6) = :prefijo
               AND RIGHT(token, 6) = :sufijo
             ORDER BY id DESC
             LIMIT 2"
        );
        $stmt->execute([
            ':prefijo' => strtolower($m[1]),
            ':sufijo' => strtolower($m[2]),
        ]);
        $filas = $stmt->fetchAll();
        if (count($filas) === 1) {
            $tokenId = (int) $filas[0]['id'];
            $ventaId = (int) $filas[0]['venta_id'];
            $tipo = 'REFERENCIA_CORTA';
        } elseif (count($filas) > 1) {
            return null;
        }
    }

    $folioCandidato = null;
    if ($ventaId === null) {
        // Compatibilidad: además del folio solo, acepta textos/URLs/QR antiguos que
        // contengan un folio oficial VTA-xxxx. No se aceptan IDs numéricos ambiguos.
        if (preg_match('/\bVTA-[0-9]{1,12}\b/i', $entrada, $mFolio) === 1) {
            $folioCandidato = strtoupper($mFolio[0]);
        } elseif (mb_strlen($entrada) <= 50) {
            $folioCandidato = $entrada;
        }
    }

    if ($ventaId === null && $folioCandidato !== null) {
        $stmt = $conexion->prepare("SELECT id, estado FROM ventas WHERE folio = :folio LIMIT 1");
        $stmt->execute([':folio' => $folioCandidato]);
        $ventaFolio = $stmt->fetch();
        if ($ventaFolio) {
            $ventaId = (int) $ventaFolio['id'];
            $tipo = 'FOLIO_VENTA';

            $actual = si_qr_token_venta_actual($conexion, $ventaId);
            if ($actual === null && $crearTokenSiFalta && $ventaFolio['estado'] === 'CONFIRMADA') {
                $actual = si_qr_asegurar_token_venta($conexion, $ventaId);
            }
            if ($actual !== null) {
                $tokenId = (int) $actual['id'];
            }
        }
    }

    if ($ventaId === null) {
        return null;
    }

    if ($bloquear) {
        $stmtVenta = $conexion->prepare("SELECT id FROM ventas WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmtVenta->execute([':id' => $ventaId]);
        if (!$stmtVenta->fetchColumn()) {
            return null;
        }
    }

    $token = null;
    if ($tokenId !== null) {
        $sqlToken =
            "SELECT
                id,
                venta_id,
                token,
                activo,
                generado_at,
                usado_at,
                usado_by,
                revocado_at,
                revocado_by,
                motivo_revocacion
             FROM tokens_qr_venta
             WHERE id = :id
             LIMIT 1" . ($bloquear ? ' FOR UPDATE' : '');
        $stmtToken = $conexion->prepare($sqlToken);
        $stmtToken->execute([':id' => $tokenId]);
        $token = $stmtToken->fetch() ?: null;
        if ($token !== null) {
            $token['id'] = (int) $token['id'];
            $token['venta_id'] = (int) $token['venta_id'];
            $token['activo'] = (int) $token['activo'];
            $token['usado_by'] = $token['usado_by'] !== null ? (int) $token['usado_by'] : null;
            $token['revocado_by'] = $token['revocado_by'] !== null ? (int) $token['revocado_by'] : null;
            $token['usado_por'] = null;
            if ($token['usado_by'] !== null) {
                $stmtUsuario = $conexion->prepare(
                    "SELECT CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)
                     FROM usuarios
                     WHERE id = :id
                     LIMIT 1"
                );
                $stmtUsuario->execute([':id' => $token['usado_by']]);
                $nombreUso = $stmtUsuario->fetchColumn();
                $token['usado_por'] = $nombreUso !== false ? trim((string) $nombreUso) : null;
            }
        }
    }

    return [
        'venta_id' => $ventaId,
        'token' => $token,
        'tipo_referencia' => $tipo ?? 'VENTA',
        'entrada_original' => $entrada,
    ];
}

function qr_preparar_consulta(PDO $conexion, array $referencia): array
{
    $ventaId = (int) $referencia['venta_id'];
    $venta = si_qr_resumen_venta($conexion, $ventaId);
    if (!$venta) {
        throw new RuntimeException('La referencia no tiene una venta válida relacionada.');
    }
    $detalles = si_qr_detalles_venta($conexion, $ventaId);
    $token = is_array($referencia['token'] ?? null) ? $referencia['token'] : null;

    $qrPublico = null;
    if ($token !== null) {
        $qrPublico = [
            'id' => (int) $token['id'],
            'token_corto' => si_qr_token_corto((string) $token['token']),
            'activo' => (int) $token['activo'],
            'generado_at' => $token['generado_at'],
            'usado_at' => $token['usado_at'],
            'usado_por' => trim((string) ($token['usado_por'] ?? '')) ?: null,
            'revocado_at' => $token['revocado_at'],
            'motivo_revocacion' => $token['motivo_revocacion'],
        ];
    }

    $puedeConfirmar = false;
    $puedeRechazar = false;
    $resultado = 'INVALIDO';
    $mensaje = 'No existe un QR operativo para esta venta.';

    if ($venta['estado'] === 'CANCELADA') {
        $resultado = 'CANCELADO';
        $mensaje = 'La venta está cancelada. No autorices la salida.';
    } elseif ($token === null) {
        $resultado = 'INVALIDO';
        $mensaje = 'La venta no tiene un QR emitido y no puede autorizarse desde este módulo.';
    } elseif ($token['usado_at'] !== null) {
        $resultado = 'YA_VERIFICADO';
        $mensaje = 'La salida física de esta venta ya fue confirmada. Este QR no puede volver a utilizarse.';
    } elseif ($token['revocado_at'] !== null || (int) $token['activo'] !== 1) {
        $resultado = 'INVALIDO';
        $mensaje = 'El QR está revocado o inactivo. No autorices la salida.';
    } elseif ($venta['estado'] !== 'CONFIRMADA') {
        $resultado = 'INVALIDO';
        $mensaje = 'La venta no está confirmada. No autorices la salida.';
    } elseif ($venta['condicion_pago'] === 'CREDITO' && ($venta['cxc_id'] === null || $venta['cxc_estado'] === 'CANCELADA')) {
        $resultado = 'INVALIDO';
        $mensaje = 'La venta a crédito no tiene una cuenta por cobrar válida. Revisa la operación.';
        $puedeRechazar = true;
    } elseif ($venta['condicion_pago'] === 'CONTADO' && $venta['estado_pago'] !== 'PAGADO') {
        $resultado = 'NO_PAGADO';
        $mensaje = 'La venta de contado no está totalmente cubierta. No se puede confirmar la salida.';
        $puedeRechazar = true;
    } else {
        $resultado = 'LISTO';
        $mensaje = $venta['condicion_pago'] === 'CREDITO' && $venta['estado_pago'] !== 'PAGADO'
            ? 'Venta válida para revisión. Es una venta a crédito con saldo pendiente; confirma la salida únicamente después de revisar los productos.'
            : 'Venta válida para revisión. Confirma la salida únicamente después de comprobar que la mercancía corresponde.';
        $puedeConfirmar = true;
        $puedeRechazar = true;
    }

    $ultimoRechazo = null;
    if ($token !== null && $token['usado_at'] === null) {
        $stmtRechazo = $conexion->prepare(
            "SELECT
                qv.id,
                qv.fecha_verificacion,
                qv.observaciones,
                CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS verificado_por
             FROM verificaciones_qr_venta qv
             LEFT JOIN usuarios u ON u.id = qv.usuario_id
             WHERE qv.token_qr_id = :token_id
               AND qv.resultado = 'RECHAZADO'
             ORDER BY qv.fecha_verificacion DESC, qv.id DESC
             LIMIT 1"
        );
        $stmtRechazo->execute([':token_id' => (int) $token['id']]);
        $ultimoRechazo = $stmtRechazo->fetch() ?: null;
    }

    $salidaAnterior = null;
    if ($token !== null && $token['usado_at'] !== null) {
        $stmt = $conexion->prepare(
            "SELECT
                qv.id,
                qv.fecha_verificacion,
                qv.observaciones,
                CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno) AS verificado_por
             FROM verificaciones_qr_venta qv
             LEFT JOIN usuarios u ON u.id = qv.usuario_id
             WHERE qv.token_qr_id = :token_id
               AND qv.resultado = 'VALIDO'
             ORDER BY qv.fecha_verificacion ASC, qv.id ASC
             LIMIT 1"
        );
        $stmt->execute([':token_id' => (int) $token['id']]);
        $salidaAnterior = $stmt->fetch() ?: [
            'id' => null,
            'fecha_verificacion' => $token['usado_at'],
            'observaciones' => 'Salida física confirmada.',
            'verificado_por' => $token['usado_por'] ?? null,
        ];
    }

    return [
        'resultado' => $resultado,
        'mensaje' => $mensaje,
        'venta' => $venta,
        'detalles' => $detalles,
        'qr' => $qrPublico,
        'tipo_referencia' => $referencia['tipo_referencia'] ?? null,
        'puede_confirmar_salida' => $puedeConfirmar,
        'puede_rechazar_salida' => $puedeRechazar,
        'puede_rehabilitar_qr' => (
            qr_es_administrador_actual()
            && $token !== null
            && $token['usado_at'] !== null
            && $token['revocado_at'] === null
            && $venta['estado'] === 'CONFIRMADA'
        ),
        'salida_anterior' => $salidaAnterior,
        'ultimo_rechazo' => $ultimoRechazo,
    ];
}

function qr_insertar_verificacion(
    PDO $conexion,
    int $tokenId,
    int $ventaId,
    string $resultado,
    string $observacion
): int {
    $stmt = $conexion->prepare(
        "INSERT INTO verificaciones_qr_venta
            (token_qr_id, venta_id, usuario_id, fecha_verificacion, resultado, ip, observaciones)
         VALUES
            (:token_qr_id, :venta_id, :usuario_id, NOW(), :resultado, :ip, :observaciones)"
    );
    $stmt->execute([
        ':token_qr_id' => $tokenId,
        ':venta_id' => $ventaId,
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':resultado' => $resultado,
        ':ip' => si_ip_cliente(),
        ':observaciones' => mb_substr($observacion, 0, 255),
    ]);
    return (int) $conexion->lastInsertId();
}

function qr_texto($valor, int $maximo): string
{
    $valor = trim((string) $valor);
    return mb_substr($valor, 0, $maximo);
}

function qr_entero($valor, int $minimo, int $maximo, int $predeterminado): int
{
    $entero = filter_var($valor, FILTER_VALIDATE_INT);
    if ($entero === false || $entero < $minimo || $entero > $maximo) {
        return $predeterminado;
    }
    return (int) $entero;
}

function qr_fecha($valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    return $d && $d->format('Y-m-d') === $valor ? $valor : null;
}

function qr_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
