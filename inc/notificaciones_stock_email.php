<?php

declare(strict_types=1);

require_once __DIR__ . '/correo_stock_config.php';
require_once __DIR__ . '/smtp_simple.php';

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

function si_stock_email_procesar(PDO $conexion, bool $forzar = false, bool $forzarResumen = false): array
{
    $config = si_correo_stock_config();
    if (empty($config['activo'])) {
        return ['procesado' => false, 'motivo' => 'desactivado', 'enviados' => 0, 'errores' => 0];
    }

    $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    if (!si_stock_email_tomar_turno($conexion, $ahora, (int) ($config['intervalo_proceso_segundos'] ?? 45), $forzar)) {
        return ['procesado' => false, 'motivo' => 'reciente', 'enviados' => 0, 'errores' => 0];
    }

    $destinatarios = si_stock_email_destinatarios($conexion);
    $inventario = si_stock_email_inventario_controlado($conexion);
    $estados = si_stock_email_estados_actuales($conexion);

    $criticos = [];
    $reorden = [];

    foreach ($inventario as $fila) {
        $clave = (int) $fila['almacen_id'] . ':' . (int) $fila['producto_id'];
        $anterior = $estados[$clave] ?? null;
        $estadoNuevo = si_stock_email_clasificar($fila);
        $episodio = max(0, (int) ($anterior['episodio_critico'] ?? 0));
        $estadoAnterior = (string) ($anterior['estado_actual'] ?? '');

        if ($estadoNuevo === 'CRITICO' && $estadoAnterior !== 'CRITICO') {
            $episodio++;
        } elseif ($estadoNuevo === 'CRITICO' && $episodio <= 0) {
            $episodio = 1;
        }

        if ($anterior === null || $estadoNuevo !== $estadoAnterior || $episodio !== (int) ($anterior['episodio_critico'] ?? 0)) {
            si_stock_email_guardar_estado($conexion, $fila, $estadoNuevo, $episodio);
        }

        $fila['estado_stock'] = $estadoNuevo;
        $fila['episodio_critico'] = $episodio;

        if ($estadoNuevo === 'CRITICO') {
            $criticos[] = $fila;
        }
        if (in_array($estadoNuevo, ['CRITICO', 'REORDEN'], true)) {
            $reorden[] = $fila;
        }
    }

    si_stock_email_cancelar_criticos_recuperados($conexion, $criticos);

    foreach ($criticos as $fila) {
        foreach ($destinatarios as $destinatario) {
            si_stock_email_encolar_critico($conexion, $fila, $destinatario);
        }
    }

    if (($forzarResumen || si_stock_email_es_hora_resumen($ahora, (string) ($config['hora_resumen_diario'] ?? '08:00'))) && $reorden) {
        foreach ($destinatarios as $destinatario) {
            si_stock_email_encolar_resumen($conexion, $reorden, $destinatario, $ahora->format('Y-m-d'));
        }
    }

    $resultadoEnvio = si_stock_email_procesar_cola($conexion, $config, $forzar);

    return [
        'procesado' => true,
        'criticos' => count($criticos),
        'reorden' => count($reorden),
        'destinatarios' => count($destinatarios),
        'enviados' => $resultadoEnvio['enviados'],
        'errores' => $resultadoEnvio['errores'],
        'manual' => $forzar,
        'resumen_forzado' => $forzarResumen,
    ];
}

function si_stock_email_tomar_turno(PDO $conexion, DateTimeImmutable $ahora, int $intervalo, bool $forzar = false): bool
{
    $intervalo = max(30, min(300, $intervalo));
    $conexion->beginTransaction();
    try {
        $stmt = $conexion->query("SELECT ultimo_proceso_at FROM alertas_stock_email_control WHERE id = 1 FOR UPDATE");
        $fila = $stmt->fetch();
        if (!$fila) {
            $conexion->exec("INSERT INTO alertas_stock_email_control (id, ultimo_proceso_at) VALUES (1, NULL)");
            $ultimo = null;
        } else {
            $ultimo = $fila['ultimo_proceso_at'] ?? null;
        }

        if (!$forzar && $ultimo !== null && $ultimo !== '') {
            $ultimaFecha = new DateTimeImmutable((string) $ultimo, new DateTimeZone('America/Mexico_City'));
            if (($ahora->getTimestamp() - $ultimaFecha->getTimestamp()) < $intervalo) {
                $conexion->rollBack();
                return false;
            }
        }

        $stmt = $conexion->prepare("UPDATE alertas_stock_email_control SET ultimo_proceso_at = :ahora WHERE id = 1");
        $stmt->execute([':ahora' => $ahora->format('Y-m-d H:i:s')]);
        $conexion->commit();
        return true;
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        throw $e;
    }
}

function si_stock_email_destinatarios(PDO $conexion): array
{
    $sql = "SELECT u.id, u.nombres, u.apellido_paterno, u.apellido_materno, u.usuario, u.correo
            FROM alertas_stock_email_destinatarios d
            INNER JOIN usuarios u ON u.id = d.usuario_id
            WHERE d.activo = 1
              AND u.activo = 1
              AND u.correo IS NOT NULL
              AND TRIM(u.correo) <> ''
            ORDER BY u.nombres, u.apellido_paterno, u.id";
    $filas = $conexion->query($sql)->fetchAll();
    $salida = [];
    foreach ($filas as $fila) {
        $correo = trim((string) ($fila['correo'] ?? ''));
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) continue;
        $nombre = trim(implode(' ', array_filter([
            $fila['nombres'] ?? '',
            $fila['apellido_paterno'] ?? '',
            $fila['apellido_materno'] ?? '',
        ], static fn($v): bool => trim((string) $v) !== '')));
        $salida[] = [
            'id' => (int) $fila['id'],
            'correo' => $correo,
            'nombre' => $nombre !== '' ? $nombre : (string) ($fila['usuario'] ?? 'Usuario'),
        ];
    }
    return $salida;
}

function si_stock_email_inventario_controlado(PDO $conexion): array
{
    $sql = "SELECT
                ea.almacen_id,
                ea.producto_id,
                ea.existencia_fisica,
                ea.cantidad_reservada,
                ea.cantidad_disponible,
                ea.stock_minimo,
                ea.punto_reorden,
                a.nombre AS almacen_nombre,
                p.sku,
                p.nombre AS producto_nombre,
                um.simbolo AS unidad
            FROM existencias_almacen ea
            INNER JOIN almacenes a ON a.id = ea.almacen_id AND a.activo = 1
            INNER JOIN productos p ON p.id = ea.producto_id AND p.activo = 1 AND p.controla_inventario = 1
            INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
            WHERE ea.cantidad_disponible <= 0
               OR ea.stock_minimo > 0
               OR (ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0)
            ORDER BY a.nombre, p.nombre";
    return $conexion->query($sql)->fetchAll();
}

function si_stock_email_estados_actuales(PDO $conexion): array
{
    $filas = $conexion->query("SELECT almacen_id, producto_id, estado_actual, episodio_critico FROM alertas_stock_email_estado")->fetchAll();
    $salida = [];
    foreach ($filas as $fila) {
        $salida[(int) $fila['almacen_id'] . ':' . (int) $fila['producto_id']] = $fila;
    }
    return $salida;
}

function si_stock_email_clasificar(array $fila): string
{
    $disponible = (float) ($fila['cantidad_disponible'] ?? 0);
    $minimo = (float) ($fila['stock_minimo'] ?? 0);
    $reorden = $fila['punto_reorden'] === null ? null : (float) $fila['punto_reorden'];

    if ($disponible <= 0 || ($minimo > 0 && $disponible <= $minimo)) {
        return 'CRITICO';
    }
    if ($reorden !== null && $reorden > 0 && $disponible <= $reorden) {
        return 'REORDEN';
    }
    return 'NORMAL';
}

function si_stock_email_guardar_estado(PDO $conexion, array $fila, string $estado, int $episodio): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO alertas_stock_email_estado
            (almacen_id, producto_id, estado_actual, episodio_critico, cambio_estado_at)
         VALUES (:almacen_id, :producto_id, :estado_actual, :episodio_critico, NOW())
         ON DUPLICATE KEY UPDATE
            estado_actual = VALUES(estado_actual),
            episodio_critico = VALUES(episodio_critico),
            cambio_estado_at = NOW()"
    );
    $stmt->execute([
        ':almacen_id' => (int) $fila['almacen_id'],
        ':producto_id' => (int) $fila['producto_id'],
        ':estado_actual' => $estado,
        ':episodio_critico' => $episodio,
    ]);
}

function si_stock_email_encolar_critico(PDO $conexion, array $fila, array $destinatario): void
{
    $episodio = max(1, (int) ($fila['episodio_critico'] ?? 1));
    $clave = sprintf('STOCK-CRITICO:A%d:P%d:E%d:U%d', (int) $fila['almacen_id'], (int) $fila['producto_id'], $episodio, (int) $destinatario['id']);
    $asunto = 'Stock crítico: ' . (string) $fila['producto_nombre'] . ' · ' . (string) $fila['almacen_nombre'];
    $html = si_stock_email_html_critico($fila, (string) $destinatario['nombre']);

    si_stock_email_crear_notificacion_y_cola(
        $conexion,
        $clave,
        (int) $destinatario['id'],
        (string) $destinatario['correo'],
        'STOCK_CRITICO',
        'CRITICA',
        'Stock crítico',
        'El producto ' . (string) $fila['producto_nombre'] . ' alcanzó un nivel crítico en ' . (string) $fila['almacen_nombre'] . '.',
        'CRITICO',
        (int) $fila['almacen_id'],
        (int) $fila['producto_id'],
        $episodio,
        null,
        $asunto,
        $html
    );
}

function si_stock_email_encolar_resumen(PDO $conexion, array $filas, array $destinatario, string $fecha): void
{
    $clave = sprintf('STOCK-RESUMEN:%s:U%d', $fecha, (int) $destinatario['id']);
    $asunto = 'Resumen diario de reabastecimiento · ' . date('d/m/Y', strtotime($fecha));
    $html = si_stock_email_html_resumen($filas, (string) $destinatario['nombre'], $fecha);

    si_stock_email_crear_notificacion_y_cola(
        $conexion,
        $clave,
        (int) $destinatario['id'],
        (string) $destinatario['correo'],
        'STOCK_REORDEN_RESUMEN',
        'NORMAL',
        'Resumen diario de reabastecimiento',
        count($filas) . ' producto(s)/almacén requieren revisión de reabastecimiento.',
        'RESUMEN_REORDEN',
        null,
        null,
        null,
        $fecha,
        $asunto,
        $html
    );
}

function si_stock_email_crear_notificacion_y_cola(
    PDO $conexion,
    string $clave,
    int $usuarioId,
    string $correo,
    string $tipoNotificacion,
    string $prioridad,
    string $titulo,
    string $mensaje,
    string $tipoEnvio,
    ?int $almacenId,
    ?int $productoId,
    ?int $episodio,
    ?string $fechaResumen,
    string $asunto,
    string $html
): void {
    $conexion->beginTransaction();
    try {
        $stmt = $conexion->prepare(
            "INSERT IGNORE INTO notificaciones
                (usuario_id, tipo, prioridad, titulo, mensaje, canal, correo_destino, entidad_tipo, entidad_id,
                 clave_deduplicacion, estado_envio, intentos_envio, leida_at)
             VALUES
                (:usuario_id, :tipo, :prioridad, :titulo, :mensaje, 'EMAIL', :correo_destino, :entidad_tipo, :entidad_id,
                 :clave, 'PENDIENTE', 0, NOW())"
        );
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':tipo' => $tipoNotificacion,
            ':prioridad' => $prioridad,
            ':titulo' => $titulo,
            ':mensaje' => $mensaje,
            ':correo_destino' => $correo,
            ':entidad_tipo' => $productoId !== null ? 'productos' : 'inventario',
            ':entidad_id' => $productoId,
            ':clave' => $clave,
        ]);

        $stmt = $conexion->prepare("SELECT id FROM notificaciones WHERE clave_deduplicacion = :clave LIMIT 1");
        $stmt->execute([':clave' => $clave]);
        $notificacionId = (int) ($stmt->fetchColumn() ?: 0);

        if ($notificacionId > 0) {
            $stmt = $conexion->prepare(
                "INSERT IGNORE INTO alertas_stock_email_envios
                    (clave_deduplicacion, notificacion_id, usuario_id, tipo, almacen_id, producto_id, episodio_critico,
                     fecha_resumen, correo_destino, asunto, cuerpo_html, estado)
                 VALUES
                    (:clave, :notificacion_id, :usuario_id, :tipo, :almacen_id, :producto_id, :episodio_critico,
                     :fecha_resumen, :correo_destino, :asunto, :cuerpo_html, 'PENDIENTE')"
            );
            $stmt->execute([
                ':clave' => $clave,
                ':notificacion_id' => $notificacionId,
                ':usuario_id' => $usuarioId,
                ':tipo' => $tipoEnvio,
                ':almacen_id' => $almacenId,
                ':producto_id' => $productoId,
                ':episodio_critico' => $episodio,
                ':fecha_resumen' => $fechaResumen,
                ':correo_destino' => $correo,
                ':asunto' => $asunto,
                ':cuerpo_html' => $html,
            ]);
        }
        $conexion->commit();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        throw $e;
    }
}

function si_stock_email_cancelar_criticos_recuperados(PDO $conexion, array $criticos): void
{
    $activas = [];
    foreach ($criticos as $fila) {
        $activas[(int) $fila['almacen_id'] . ':' . (int) $fila['producto_id']] = true;
    }

    $pendientes = $conexion->query(
        "SELECT id, notificacion_id, almacen_id, producto_id
         FROM alertas_stock_email_envios
         WHERE tipo = 'CRITICO' AND estado IN ('PENDIENTE','ERROR')"
    )->fetchAll();

    foreach ($pendientes as $fila) {
        $clave = (int) $fila['almacen_id'] . ':' . (int) $fila['producto_id'];
        if (isset($activas[$clave])) continue;
        $stmt = $conexion->prepare("UPDATE alertas_stock_email_envios SET estado = 'CANCELADA', error_envio = 'El stock se recuperó antes del envío.' WHERE id = :id");
        $stmt->execute([':id' => (int) $fila['id']]);
        if (!empty($fila['notificacion_id'])) {
            $stmt = $conexion->prepare("UPDATE notificaciones SET estado_envio = 'NO_APLICA', error_envio = 'El stock se recuperó antes del envío.' WHERE id = :id AND estado_envio <> 'ENVIADA'");
            $stmt->execute([':id' => (int) $fila['notificacion_id']]);
        }
    }
}

function si_stock_email_procesar_cola(PDO $conexion, array $config, bool $forzar = false): array
{
    $reintento = max(5, min(1440, (int) ($config['reintento_minutos'] ?? 30)));
    $condicionReintento = $forzar
        ? ''
        : " AND (e.ultimo_intento_at IS NULL OR e.ultimo_intento_at <= DATE_SUB(NOW(), INTERVAL {$reintento} MINUTE))";
    $sql = "SELECT e.*, u.activo AS usuario_activo, u.correo AS correo_actual,
                   CASE WHEN d.usuario_id IS NULL OR d.activo <> 1 THEN 0 ELSE 1 END AS sigue_seleccionado
            FROM alertas_stock_email_envios e
            INNER JOIN usuarios u ON u.id = e.usuario_id
            LEFT JOIN alertas_stock_email_destinatarios d ON d.usuario_id = e.usuario_id
            WHERE e.estado IN ('PENDIENTE','ERROR')"
            . $condicionReintento
            . " ORDER BY CASE e.tipo WHEN 'CRITICO' THEN 1 ELSE 2 END, e.created_at
                LIMIT 30";

    $filas = $conexion->query($sql)->fetchAll();
    $enviados = 0;
    $errores = 0;

    foreach ($filas as $fila) {
        $id = (int) $fila['id'];
        $notificacionId = (int) ($fila['notificacion_id'] ?? 0);
        $correoActual = trim((string) ($fila['correo_actual'] ?? ''));

        if ((int) $fila['usuario_activo'] !== 1 || (int) $fila['sigue_seleccionado'] !== 1 || !filter_var($correoActual, FILTER_VALIDATE_EMAIL)) {
            $stmt = $conexion->prepare("UPDATE alertas_stock_email_envios SET estado = 'CANCELADA', error_envio = 'El destinatario ya no está activo, seleccionado o no tiene un correo válido.' WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if ($notificacionId > 0) {
                $conexion->prepare("UPDATE notificaciones SET estado_envio = 'NO_APLICA', error_envio = 'Destinatario no disponible.' WHERE id = :id AND estado_envio <> 'ENVIADA'")->execute([':id' => $notificacionId]);
            }
            continue;
        }

        $stmt = $conexion->prepare("UPDATE alertas_stock_email_envios SET correo_destino = :correo, intentos = intentos + 1, ultimo_intento_at = NOW() WHERE id = :id");
        $stmt->execute([':correo' => $correoActual, ':id' => $id]);

        $nombre = si_stock_email_nombre_usuario($conexion, (int) $fila['usuario_id']);
        $resultado = si_smtp_enviar($config, $correoActual, $nombre, (string) $fila['asunto'], (string) $fila['cuerpo_html']);

        if (!empty($resultado['ok'])) {
            $enviados++;
            $conexion->prepare("UPDATE alertas_stock_email_envios SET estado = 'ENVIADA', enviada_at = NOW(), error_envio = NULL WHERE id = :id")->execute([':id' => $id]);
            if ($notificacionId > 0) {
                $conexion->prepare("UPDATE notificaciones SET correo_destino = :correo, estado_envio = 'ENVIADA', intentos_envio = intentos_envio + 1, error_envio = NULL, enviada_at = NOW() WHERE id = :id")
                    ->execute([':correo' => $correoActual, ':id' => $notificacionId]);
            }
            si_stock_email_auditar_envio($conexion, $fila, $correoActual, true, null);
        } else {
            $errores++;
            $error = mb_substr((string) ($resultado['error'] ?? 'Error SMTP no especificado.'), 0, 1000);
            $conexion->prepare("UPDATE alertas_stock_email_envios SET estado = 'ERROR', error_envio = :error WHERE id = :id")->execute([':error' => $error, ':id' => $id]);
            if ($notificacionId > 0) {
                $conexion->prepare("UPDATE notificaciones SET correo_destino = :correo, estado_envio = 'ERROR', intentos_envio = intentos_envio + 1, error_envio = :error WHERE id = :id")
                    ->execute([':correo' => $correoActual, ':error' => $error, ':id' => $notificacionId]);
            }
            error_log('[SISTEMA INTEGRAL][EMAIL STOCK] ' . $error);
        }
    }

    return ['enviados' => $enviados, 'errores' => $errores];
}

function si_stock_email_nombre_usuario(PDO $conexion, int $usuarioId): string
{
    $stmt = $conexion->prepare("SELECT usuario, nombres, apellido_paterno, apellido_materno FROM usuarios WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $usuarioId]);
    $fila = $stmt->fetch() ?: [];
    $nombre = trim(implode(' ', array_filter([
        $fila['nombres'] ?? '',
        $fila['apellido_paterno'] ?? '',
        $fila['apellido_materno'] ?? '',
    ], static fn($v): bool => trim((string) $v) !== '')));
    return $nombre !== '' ? $nombre : (string) ($fila['usuario'] ?? 'Usuario');
}

function si_stock_email_es_hora_resumen(DateTimeImmutable $ahora, string $hora): bool
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', trim($hora), $m)) return false;
    $minutosObjetivo = ((int) $m[1] * 60) + (int) $m[2];
    $minutosAhora = ((int) $ahora->format('H') * 60) + (int) $ahora->format('i');
    return $minutosAhora >= $minutosObjetivo;
}

function si_stock_email_numero(float $valor): string
{
    $decimales = abs($valor - round($valor)) < 0.000001 ? 0 : 2;
    return number_format($valor, $decimales, '.', ',');
}

function si_stock_email_html_critico(array $fila, string $nombreDestino): string
{
    $producto = htmlspecialchars((string) $fila['producto_nombre'], ENT_QUOTES, 'UTF-8');
    $sku = htmlspecialchars((string) $fila['sku'], ENT_QUOTES, 'UTF-8');
    $almacen = htmlspecialchars((string) $fila['almacen_nombre'], ENT_QUOTES, 'UTF-8');
    $unidad = htmlspecialchars((string) $fila['unidad'], ENT_QUOTES, 'UTF-8');
    $nombre = htmlspecialchars($nombreDestino, ENT_QUOTES, 'UTF-8');
    $disponible = si_stock_email_numero((float) $fila['cantidad_disponible']);
    $minimo = si_stock_email_numero((float) $fila['stock_minimo']);
    $reorden = $fila['punto_reorden'] === null ? 'No configurado' : si_stock_email_numero((float) $fila['punto_reorden']) . ' ' . $unidad;

    return '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;color:#26352c">'
        . '<h2 style="margin-bottom:8px;color:#991b1b">Stock crítico</h2>'
        . '<p>Hola <strong>' . $nombre . '</strong>. Un producto alcanzó un nivel que requiere atención inmediata.</p>'
        . '<div style="background:#fff1f2;border-left:5px solid #dc2626;padding:18px;border-radius:8px;margin:18px 0">'
        . '<strong style="font-size:18px">' . $producto . '</strong><br>'
        . '<span style="color:#647067">' . $sku . ' · ' . $almacen . '</span>'
        . '<p style="margin:14px 0 4px"><strong>Disponible:</strong> ' . $disponible . ' ' . $unidad . '</p>'
        . '<p style="margin:4px 0"><strong>Stock mínimo:</strong> ' . $minimo . ' ' . $unidad . '</p>'
        . '<p style="margin:4px 0"><strong>Punto de reorden:</strong> ' . $reorden . '</p>'
        . '</div>'
        . '<p>Revisa Inventario para decidir el reabastecimiento correspondiente. Este aviso no modifica existencias ni genera compras automáticamente.</p>'
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0">'
        . '<small style="color:#6b7280">Mensaje automático del Sistema Integral.</small>'
        . '</div>';
}

function si_stock_email_html_resumen(array $filas, string $nombreDestino, string $fecha): string
{
    $nombre = htmlspecialchars($nombreDestino, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($filas as $fila) {
        $unidad = htmlspecialchars((string) $fila['unidad'], ENT_QUOTES, 'UTF-8');
        $estado = (string) $fila['estado_stock'] === 'CRITICO' ? 'CRÍTICO' : 'REORDEN';
        $rows .= '<tr>'
            . '<td style="padding:9px;border-bottom:1px solid #e5e7eb"><strong>' . htmlspecialchars((string) $fila['producto_nombre'], ENT_QUOTES, 'UTF-8') . '</strong><br><small>' . htmlspecialchars((string) $fila['sku'], ENT_QUOTES, 'UTF-8') . '</small></td>'
            . '<td style="padding:9px;border-bottom:1px solid #e5e7eb">' . htmlspecialchars((string) $fila['almacen_nombre'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:9px;border-bottom:1px solid #e5e7eb">' . si_stock_email_numero((float) $fila['cantidad_disponible']) . ' ' . $unidad . '</td>'
            . '<td style="padding:9px;border-bottom:1px solid #e5e7eb">' . ($fila['punto_reorden'] === null ? '—' : si_stock_email_numero((float) $fila['punto_reorden']) . ' ' . $unidad) . '</td>'
            . '<td style="padding:9px;border-bottom:1px solid #e5e7eb;font-weight:700">' . $estado . '</td>'
            . '</tr>';
    }

    return '<div style="font-family:Arial,sans-serif;max-width:760px;margin:0 auto;color:#26352c">'
        . '<h2 style="margin-bottom:8px;color:#14532d">Resumen diario de reabastecimiento</h2>'
        . '<p>Hola <strong>' . $nombre . '</strong>. Estos productos ya alcanzaron su punto de reorden o un nivel crítico al ' . htmlspecialchars(date('d/m/Y', strtotime($fecha)), ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<thead><tr style="background:#f3f7f4"><th style="padding:9px;text-align:left">Producto</th><th style="padding:9px;text-align:left">Almacén</th><th style="padding:9px;text-align:left">Disponible</th><th style="padding:9px;text-align:left">Reorden</th><th style="padding:9px;text-align:left">Estado</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>'
        . '<p style="margin-top:18px">El resumen es informativo y no genera compras automáticamente.</p>'
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0">'
        . '<small style="color:#6b7280">Mensaje automático del Sistema Integral.</small>'
        . '</div>';
}

function si_stock_email_auditar_envio(PDO $conexion, array $fila, string $correo, bool $exitoso, ?string $error): void
{
    if (!$exitoso) return;
    try {
        $stmt = $conexion->prepare(
            "INSERT INTO auditoria
                (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_nuevos, ip, user_agent)
             VALUES
                (NULL, :accion, 'inventario', 'notificaciones', :entidad_id, :descripcion, :datos, '127.0.0.1', 'Proceso automático de alertas de stock')"
        );
        $stmt->execute([
            ':accion' => (string) $fila['tipo'] === 'CRITICO' ? 'EMAIL_STOCK_CRITICO_ENVIADO' : 'EMAIL_REORDEN_RESUMEN_ENVIADO',
            ':entidad_id' => (int) ($fila['notificacion_id'] ?? 0) ?: null,
            ':descripcion' => (string) $fila['tipo'] === 'CRITICO'
                ? 'Se envió una alerta de stock crítico por correo.'
                : 'Se envió el resumen diario de reabastecimiento por correo.',
            ':datos' => json_encode([
                'usuario_id' => (int) $fila['usuario_id'],
                'correo_destino' => $correo,
                'almacen_id' => $fila['almacen_id'] !== null ? (int) $fila['almacen_id'] : null,
                'producto_id' => $fila['producto_id'] !== null ? (int) $fila['producto_id'] : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        error_log('[SISTEMA INTEGRAL][AUDITORIA EMAIL STOCK] ' . $e->getMessage());
    }
}
