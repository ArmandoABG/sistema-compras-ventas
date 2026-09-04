<?php

declare(strict_types=1);

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

/**
 * Pulido operativo de inventario.
 *
 * Reglas centrales:
 * - Apartado ACTIVO vencido => libera su reserva una sola vez y pasa a VENCIDO.
 * - Venta confirmada con QR pendiente => mantiene stock físico y reserva la cantidad.
 * - Confirmación QR => consume reserva, descuenta físico y crea SALIDA_VENTA/Kardex.
 * - QR deshabilitado => la salida se aplica de inmediato y no queda token operativo.
 * - Ventas históricas pendientes que ya descontaron stock => se normalizan mediante
 *   un REVERSO trazable y la cantidad vuelve a quedar reservada.
 */

function si_stock_qr_habilitado(PDO $conexion): bool
{
    $stmt = $conexion->prepare(
        "SELECT valor_texto FROM configuracion_sistema WHERE clave = 'qr.validacion_salida' LIMIT 1"
    );
    $stmt->execute();
    $valor = $stmt->fetchColumn();
    if ($valor === false || $valor === null) {
        return true;
    }
    return in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí', 'on'], true);
}

function si_stock_usuario_actual(): ?int
{
    $id = (int) ($_SESSION['usuario_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function si_stock_auditar(
    PDO $conexion,
    string $accion,
    string $modulo,
    ?string $tabla,
    ?int $entidadId,
    string $descripcion,
    ?array $antes = null,
    ?array $despues = null
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, fecha_hora, accion, modulo, entidad_tabla, entidad_id,
             descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, NOW(), :accion, :modulo, :tabla, :entidad_id,
             :descripcion, :antes, :despues, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario_id' => si_stock_usuario_actual(),
        ':accion' => mb_substr($accion, 0, 60),
        ':modulo' => mb_substr($modulo, 0, 80),
        ':tabla' => $tabla !== null ? mb_substr($tabla, 0, 80) : null,
        ':entidad_id' => $entidadId,
        ':descripcion' => mb_substr($descripcion, 0, 500),
        ':antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ':despues' => $despues !== null ? json_encode($despues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ':ip' => function_exists('si_ip_cliente') ? si_ip_cliente() : ($_SERVER['REMOTE_ADDR'] ?? null),
        ':user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

function si_stock_bloquear_existencia(PDO $conexion, int $almacenId, int $productoId): array
{
    $conexion->prepare(
        "INSERT IGNORE INTO existencias_almacen
            (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden, costo_promedio_base)
         VALUES (:almacen_id, :producto_id, 0, 0, 0, NULL, NULL)"
    )->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);

    $stmt = $conexion->prepare(
        "SELECT id, almacen_id, producto_id, existencia_fisica, cantidad_reservada,
                cantidad_disponible, costo_promedio_base
         FROM existencias_almacen
         WHERE almacen_id = :almacen_id AND producto_id = :producto_id
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':almacen_id' => $almacenId, ':producto_id' => $productoId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        throw new RuntimeException('No fue posible bloquear una existencia requerida.');
    }
    return $fila;
}

function si_stock_tipo_movimiento(PDO $conexion, string $codigo): int
{
    $stmt = $conexion->prepare(
        "SELECT id FROM tipos_movimiento_inventario WHERE codigo = :codigo AND activo = 1 LIMIT 1"
    );
    $stmt->execute([':codigo' => $codigo]);
    $id = (int) $stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('No está configurado el tipo de movimiento ' . $codigo . '.');
    }
    return $id;
}

function si_stock_crear_movimiento(
    PDO $conexion,
    int $tipoMovimientoId,
    string $origenTipo,
    int $origenId,
    string $idempotencyKey,
    ?int $movimientoRevertidoId,
    string $motivo,
    ?int $usuarioId = null
): int {
    $stmtExiste = $conexion->prepare(
        "SELECT id FROM movimientos_inventario WHERE idempotency_key = :clave LIMIT 1 FOR UPDATE"
    );
    $stmtExiste->execute([':clave' => $idempotencyKey]);
    $existente = (int) $stmtExiste->fetchColumn();
    if ($existente > 0) {
        return $existente;
    }

    $usuarioId = $usuarioId ?? si_stock_usuario_actual();
    if ($usuarioId === null) {
        $stmtUsuario = $conexion->prepare(
            "SELECT COALESCE(aplicado_by, created_by) FROM movimientos_inventario
             WHERE id = :id LIMIT 1"
        );
        if ($movimientoRevertidoId !== null) {
            $stmtUsuario->execute([':id' => $movimientoRevertidoId]);
            $usuarioId = (int) $stmtUsuario->fetchColumn();
        }
    }
    if (!$usuarioId) {
        $usuarioId = 1;
    }

    $tmp = 'TMP-MOV-' . bin2hex(random_bytes(10));
    $stmt = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id,
             idempotency_key, movimiento_revertido_id, motivo, observaciones, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'BORRADOR', :origen_tipo, :origen_id,
             :clave, :revertido_id, :motivo, NULL, :usuario)"
    );
    $stmt->execute([
        ':folio' => $tmp,
        ':tipo' => $tipoMovimientoId,
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $origenId,
        ':clave' => $idempotencyKey,
        ':revertido_id' => $movimientoRevertidoId,
        ':motivo' => mb_substr($motivo, 0, 3000),
        ':usuario' => $usuarioId,
    ]);

    $id = (int) $conexion->lastInsertId();
    $folio = 'MOV-' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE movimientos_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $id]);
    return $id;
}

function si_stock_movimiento_salida_venta(PDO $conexion, int $ventaId, bool $soloAplicado = true): ?array
{
    $estado = $soloAplicado ? "AND mi.estado = 'APLICADO'" : '';
    $lock = $conexion->inTransaction() ? ' FOR UPDATE' : '';
    $stmt = $conexion->prepare(
        "SELECT mi.*
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         WHERE mi.origen_tipo = 'VENTA'
           AND mi.origen_id = :venta_id
           AND tmi.codigo = 'SALIDA_VENTA'
           {$estado}
         ORDER BY mi.id DESC
         LIMIT 1{$lock}"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function si_stock_detalles_venta_controlados(PDO $conexion, int $ventaId): array
{
    $stmt = $conexion->prepare(
        "SELECT vd.id, vd.renglon, vd.almacen_id, vd.producto_id, vd.producto_nombre_snapshot,
                vd.cantidad_base, vd.costo_unitario_base_snapshot
         FROM ventas_detalle vd
         INNER JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = :venta_id
           AND p.controla_inventario = 1
         ORDER BY vd.renglon ASC, vd.id ASC"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    return $stmt->fetchAll();
}

function si_stock_agrupar_detalles(array $detalles): array
{
    $grupos = [];
    foreach ($detalles as $d) {
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'almacen_id' => (int) $d['almacen_id'],
                'producto_id' => (int) $d['producto_id'],
                'producto' => (string) ($d['producto_nombre_snapshot'] ?? ''),
                'cantidad' => 0.0,
            ];
        }
        $grupos[$clave]['cantidad'] = round($grupos[$clave]['cantidad'] + (float) $d['cantidad_base'], 6);
    }
    uasort($grupos, static fn(array $a, array $b): int =>
        [$a['almacen_id'], $a['producto_id']] <=> [$b['almacen_id'], $b['producto_id']]
    );
    return $grupos;
}

/** Consume la reserva de una venta y registra su salida física/Kardex. Idempotente. */
function si_stock_aplicar_salida_venta(PDO $conexion, int $ventaId, string $motivo = '', ?string $idempotencyKey = null): ?int
{
    $ventaStmt = $conexion->prepare("SELECT id, folio, estado, created_by FROM ventas WHERE id = :id LIMIT 1 FOR UPDATE");
    $ventaStmt->execute([':id' => $ventaId]);
    $venta = $ventaStmt->fetch();
    if (!$venta || $venta['estado'] !== 'CONFIRMADA') {
        throw new RuntimeException('La venta no está confirmada para aplicar su salida física.');
    }

    $existente = si_stock_movimiento_salida_venta($conexion, $ventaId, true);
    if ($existente) {
        return (int) $existente['id'];
    }

    $detalles = si_stock_detalles_venta_controlados($conexion, $ventaId);
    if (!$detalles) {
        return null;
    }
    $grupos = si_stock_agrupar_detalles($detalles);
    $bloqueadas = [];
    foreach ($grupos as $clave => $g) {
        $e = si_stock_bloquear_existencia($conexion, $g['almacen_id'], $g['producto_id']);
        $fisica = (float) $e['existencia_fisica'];
        $reservada = (float) $e['cantidad_reservada'];
        $necesario = (float) $g['cantidad'];
        if ($fisica + 0.000001 < $necesario) {
            throw new RuntimeException('La existencia física ya no alcanza para surtir ' . $g['producto'] . '.');
        }
        if ($reservada + 0.000001 < $necesario) {
            throw new RuntimeException('La reserva de la venta ya no alcanza para surtir ' . $g['producto'] . '.');
        }
        $bloqueadas[$clave] = $e;
    }

    $tipoSalida = si_stock_tipo_movimiento($conexion, 'SALIDA_VENTA');
    $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
        ? mb_substr(trim($idempotencyKey), 0, 120)
        : ('SALIDA_FISICA_VENTA:' . $ventaId);
    $movimientoId = si_stock_crear_movimiento(
        $conexion,
        $tipoSalida,
        'VENTA',
        $ventaId,
        $idempotencyKey,
        null,
        $motivo !== '' ? $motivo : ('Salida física por ' . $venta['folio'])
    );

    $estado = [];
    foreach ($bloqueadas as $clave => $e) {
        $estado[$clave] = [
            'id' => (int) $e['id'],
            'fisica' => (float) $e['existencia_fisica'],
            'reservada' => (float) $e['cantidad_reservada'],
        ];
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes,
             existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento_id, :renglon, :almacen_id, :producto_id, :delta, :antes,
             :despues, :costo, :observaciones)"
    );

    $renglon = 1;
    foreach ($detalles as $d) {
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        $salida = (float) $d['cantidad_base'];
        $antes = $estado[$clave]['fisica'];
        $despues = round($antes - $salida, 6);
        $estado[$clave]['fisica'] = $despues;
        $stmtDet->execute([
            ':movimiento_id' => $movimientoId,
            ':renglon' => $renglon++,
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':delta' => -$salida,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $d['costo_unitario_base_snapshot'],
            ':observaciones' => 'Salida física de ' . $venta['folio'] . ' · renglón ' . (int) $d['renglon'],
        ]);
    }

    foreach ($grupos as $clave => $g) {
        $e = $estado[$clave];
        $reservadaFinal = round(max(0.0, $e['reservada'] - (float) $g['cantidad']), 6);
        if ($e['fisica'] + 0.000001 < $reservadaFinal) {
            throw new RuntimeException('La salida dejaría existencia física por debajo de otras reservas vigentes.');
        }
        $conexion->prepare(
            "UPDATE existencias_almacen SET existencia_fisica = :fisica, cantidad_reservada = :reservada WHERE id = :id"
        )->execute([
            ':fisica' => $e['fisica'],
            ':reservada' => $reservadaFinal,
            ':id' => $e['id'],
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario SET estado = 'APLICADO', aplicado_at = NOW(), aplicado_by = :usuario WHERE id = :id"
    )->execute([
        ':usuario' => si_stock_usuario_actual() ?? (int) ($venta['created_by'] ?? 1),
        ':id' => $movimientoId,
    ]);

    return $movimientoId;
}

/** Libera una reserva de venta pendiente que nunca tuvo salida física. */
function si_stock_liberar_reserva_venta(PDO $conexion, int $ventaId): void
{
    $detalles = si_stock_detalles_venta_controlados($conexion, $ventaId);
    if (!$detalles) {
        return;
    }
    foreach (si_stock_agrupar_detalles($detalles) as $g) {
        $e = si_stock_bloquear_existencia($conexion, $g['almacen_id'], $g['producto_id']);
        $reservada = (float) $e['cantidad_reservada'];
        $cantidad = (float) $g['cantidad'];
        if ($reservada + 0.000001 < $cantidad) {
            throw new RuntimeException('La reserva pendiente de la venta ya no coincide con el inventario.');
        }
        $conexion->prepare("UPDATE existencias_almacen SET cantidad_reservada = :reservada WHERE id = :id")
            ->execute([
                ':reservada' => round(max(0.0, $reservada - $cantidad), 6),
                ':id' => (int) $e['id'],
            ]);
    }
}

/**
 * Revierte una SALIDA_VENTA aplicada y convierte exactamente esa cantidad en reserva.
 * Se usa para normalizar datos históricos y para rehabilitar un QR marcado por error.
 */
function si_stock_revertir_salida_venta_a_reserva(
    PDO $conexion,
    int $ventaId,
    string $idempotencyKey,
    string $motivo
): ?int {
    $original = si_stock_movimiento_salida_venta($conexion, $ventaId, true);
    if (!$original) {
        return null;
    }

    $stmtExistente = $conexion->prepare(
        "SELECT id FROM movimientos_inventario WHERE idempotency_key = :clave LIMIT 1 FOR UPDATE"
    );
    $stmtExistente->execute([':clave' => $idempotencyKey]);
    $ya = (int) $stmtExistente->fetchColumn();
    if ($ya > 0) {
        return $ya;
    }

    $stmt = $conexion->prepare(
        "SELECT mid.*
         FROM movimientos_inventario_detalle mid
         WHERE mid.movimiento_id = :id
         ORDER BY mid.renglon ASC, mid.id ASC
         FOR UPDATE"
    );
    $stmt->execute([':id' => (int) $original['id']]);
    $detalles = $stmt->fetchAll();
    if (!$detalles) {
        throw new RuntimeException('La salida de venta no contiene detalle de inventario para revertir.');
    }

    $tipoReverso = si_stock_tipo_movimiento($conexion, 'REVERSO');
    $movReverso = si_stock_crear_movimiento(
        $conexion,
        $tipoReverso,
        'NORMALIZACION_SALIDA_VENTA',
        $ventaId,
        $idempotencyKey,
        (int) $original['id'],
        $motivo,
        (int) ($original['aplicado_by'] ?? $original['created_by'] ?? 1)
    );

    $grupos = [];
    foreach ($detalles as $d) {
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        $grupos[$clave] = ($grupos[$clave] ?? 0.0) + abs((float) $d['cantidad_delta']);
    }
    ksort($grupos, SORT_NATURAL);

    $existencias = [];
    foreach ($grupos as $clave => $cantidad) {
        [$almacenId, $productoId] = array_map('intval', explode(':', $clave, 2));
        $existencias[$clave] = si_stock_bloquear_existencia($conexion, $almacenId, $productoId);
    }

    $stmtDet = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes,
             existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento_id, :renglon, :almacen_id, :producto_id, :delta, :antes,
             :despues, :costo, :observaciones)"
    );

    $estadoFisico = [];
    foreach ($existencias as $clave => $e) {
        $estadoFisico[$clave] = (float) $e['existencia_fisica'];
    }
    foreach ($detalles as $i => $d) {
        $clave = (int) $d['almacen_id'] . ':' . (int) $d['producto_id'];
        $delta = abs((float) $d['cantidad_delta']);
        $antes = $estadoFisico[$clave];
        $despues = round($antes + $delta, 6);
        $estadoFisico[$clave] = $despues;
        $stmtDet->execute([
            ':movimiento_id' => $movReverso,
            ':renglon' => $i + 1,
            ':almacen_id' => (int) $d['almacen_id'],
            ':producto_id' => (int) $d['producto_id'],
            ':delta' => $delta,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $d['costo_unitario_base'],
            ':observaciones' => mb_substr($motivo, 0, 500),
        ]);
    }

    foreach ($grupos as $clave => $cantidad) {
        $e = $existencias[$clave];
        $nuevaReserva = round((float) $e['cantidad_reservada'] + (float) $cantidad, 6);
        $nuevaFisica = $estadoFisico[$clave];
        if ($nuevaFisica + 0.000001 < $nuevaReserva) {
            throw new RuntimeException('La normalización dejaría una reserva mayor que la existencia física.');
        }
        $conexion->prepare(
            "UPDATE existencias_almacen SET existencia_fisica = :fisica, cantidad_reservada = :reservada WHERE id = :id"
        )->execute([
            ':fisica' => $nuevaFisica,
            ':reservada' => $nuevaReserva,
            ':id' => (int) $e['id'],
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario SET estado = 'APLICADO', aplicado_at = NOW(), aplicado_by = :usuario WHERE id = :id"
    )->execute([
        ':usuario' => si_stock_usuario_actual() ?? (int) ($original['aplicado_by'] ?? $original['created_by'] ?? 1),
        ':id' => $movReverso,
    ]);
    $conexion->prepare("UPDATE movimientos_inventario SET estado = 'REVERTIDO' WHERE id = :id AND estado = 'APLICADO'")
        ->execute([':id' => (int) $original['id']]);

    return $movReverso;
}

function si_stock_liberar_apartados_vencidos(PDO $conexion, int $limite = 100): int
{
    $limite = max(1, min(500, $limite));
    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }

    try {
        $stmt = $conexion->query(
            "SELECT id, folio, reservado_hasta
             FROM apartados
             WHERE estado = 'ACTIVO'
               AND reservado_hasta IS NOT NULL
               AND reservado_hasta < NOW()
             ORDER BY reservado_hasta ASC, id ASC
             LIMIT {$limite}
             FOR UPDATE"
        );
        $apartados = $stmt->fetchAll();
        $procesados = 0;

        foreach ($apartados as $a) {
            $det = $conexion->prepare(
                "SELECT ad.almacen_id, ad.producto_id, SUM(ad.cantidad_base) AS cantidad_base
                 FROM apartados_detalle ad
                 INNER JOIN productos p ON p.id = ad.producto_id
                 WHERE ad.apartado_id = :id
                   AND p.controla_inventario = 1
                 GROUP BY ad.almacen_id, ad.producto_id
                 ORDER BY ad.almacen_id ASC, ad.producto_id ASC"
            );
            $det->execute([':id' => (int) $a['id']]);
            foreach ($det->fetchAll() as $d) {
                $e = si_stock_bloquear_existencia($conexion, (int) $d['almacen_id'], (int) $d['producto_id']);
                $cantidad = (float) $d['cantidad_base'];
                $reservada = (float) $e['cantidad_reservada'];
                if ($reservada + 0.000001 < $cantidad) {
                    throw new RuntimeException(
                        'La reserva del apartado ' . $a['folio'] . ' ya no coincide con la reserva global de inventario. No se liberó nada para evitar afectar otras reservas.'
                    );
                }
                $nueva = round($reservada - $cantidad, 6);
                $conexion->prepare("UPDATE existencias_almacen SET cantidad_reservada = :cantidad WHERE id = :id")
                    ->execute([':cantidad' => $nueva, ':id' => (int) $e['id']]);
            }

            $upd = $conexion->prepare(
                "UPDATE apartados SET estado = 'VENCIDO' WHERE id = :id AND estado = 'ACTIVO' AND reservado_hasta < NOW()"
            );
            $upd->execute([':id' => (int) $a['id']]);
            if ($upd->rowCount() === 1) {
                $procesados++;
                si_stock_auditar(
                    $conexion,
                    'APARTADO_VENCIDO',
                    'apartados',
                    'apartados',
                    (int) $a['id'],
                    'El apartado ' . $a['folio'] . ' venció y su reserva fue liberada automáticamente.',
                    ['estado' => 'ACTIVO', 'reservado_hasta' => $a['reservado_hasta']],
                    ['estado' => 'VENCIDO']
                );
            }
        }

        if ($propia) {
            $conexion->commit();
        }
        return $procesados;
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

/** Normaliza únicamente ventas antiguas con QR pendiente y SALIDA_VENTA ya aplicada. */
function si_stock_normalizar_qr_pendientes_legacy(PDO $conexion, int $limite = 50): int
{
    $limite = max(1, min(200, $limite));
    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }
    try {
        $stmt = $conexion->query(
            "SELECT v.id, v.folio, tq.id AS token_id
             FROM ventas v
             INNER JOIN tokens_qr_venta tq ON tq.venta_id = v.id
             WHERE v.estado = 'CONFIRMADA'
               AND tq.activo = 1
               AND tq.usado_at IS NULL
               AND tq.revocado_at IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM movimientos_inventario mi
                   INNER JOIN tipos_movimiento_inventario tmi
                     ON tmi.id = mi.tipo_movimiento_id AND tmi.codigo = 'SALIDA_VENTA'
                   WHERE mi.origen_tipo = 'VENTA'
                     AND mi.origen_id = v.id
                     AND mi.estado = 'APLICADO'
               )
             ORDER BY v.id ASC
             LIMIT {$limite}
             FOR UPDATE"
        );
        $ventas = $stmt->fetchAll();
        $procesadas = 0;
        foreach ($ventas as $v) {
            $reverso = si_stock_revertir_salida_venta_a_reserva(
                $conexion,
                (int) $v['id'],
                'NORMALIZA_QR_PENDIENTE:' . (int) $v['id'],
                'Normalización Pulido Final 1: la venta conserva reserva hasta confirmar físicamente su QR.'
            );
            if ($reverso !== null) {
                $procesadas++;
                si_stock_auditar(
                    $conexion,
                    'VENTA_QR_NORMALIZADA',
                    'ventas',
                    'ventas',
                    (int) $v['id'],
                    'Se normalizó ' . $v['folio'] . ': la salida histórica pendiente de QR se revirtió y quedó como reserva.',
                    ['salida_aplicada_antes_del_qr' => true],
                    ['reserva_hasta_confirmacion_qr' => true, 'movimiento_reverso_id' => $reverso]
                );
            }
        }
        if ($propia) {
            $conexion->commit();
        }
        return $procesadas;
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

/** Si el Administrador deshabilitó QR, consume reservas pendientes y revoca esos tokens. */
function si_stock_resolver_qr_pendientes_deshabilitados(PDO $conexion, int $limite = 50): int
{
    if (si_stock_qr_habilitado($conexion)) {
        return 0;
    }
    $limite = max(1, min(200, $limite));
    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }
    try {
        $stmt = $conexion->query(
            "SELECT v.id, v.folio, tq.id AS token_id
             FROM ventas v
             INNER JOIN tokens_qr_venta tq ON tq.venta_id = v.id
             WHERE v.estado = 'CONFIRMADA'
               AND tq.activo = 1
               AND tq.usado_at IS NULL
               AND tq.revocado_at IS NULL
             ORDER BY v.id ASC
             LIMIT {$limite}
             FOR UPDATE"
        );
        $ventas = $stmt->fetchAll();
        $procesadas = 0;
        foreach ($ventas as $v) {
            $stmtCiclo = $conexion->prepare(
                "SELECT COUNT(*)
                 FROM verificaciones_qr_venta
                 WHERE token_qr_id = :token_id
                   AND resultado IN ('VALIDO','CANCELADO')"
            );
            $stmtCiclo->execute([':token_id' => (int) $v['token_id']]);
            $ciclo = (int) $stmtCiclo->fetchColumn() + 1;
            $mov = si_stock_aplicar_salida_venta(
                $conexion,
                (int) $v['id'],
                'Salida física automática porque la validación QR está deshabilitada.',
                'SALIDA_SIN_QR_TOKEN:' . (int) $v['token_id'] . ':' . $ciclo
            );
            $upd = $conexion->prepare(
                "UPDATE tokens_qr_venta
                 SET activo = 0, revocado_at = NOW(), revocado_by = :usuario,
                     motivo_revocacion = 'Validación QR deshabilitada: salida aplicada sin escaneo.'
                 WHERE id = :id AND activo = 1 AND usado_at IS NULL AND revocado_at IS NULL"
            );
            $upd->execute([
                ':usuario' => si_stock_usuario_actual(),
                ':id' => (int) $v['token_id'],
            ]);
            if ($upd->rowCount() === 1) {
                $procesadas++;
                si_stock_auditar(
                    $conexion,
                    'SALIDA_SIN_QR_APLICADA',
                    'ventas',
                    'ventas',
                    (int) $v['id'],
                    'Se aplicó la salida física de ' . $v['folio'] . ' porque la validación QR está deshabilitada.',
                    ['qr_pendiente' => true],
                    ['qr_revocado' => true, 'movimiento_inventario_id' => $mov]
                );
            }
        }
        if ($propia) {
            $conexion->commit();
        }
        return $procesadas;
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

/** Mantenimiento ligero previo a cualquier flujo que dependa de disponibilidad. */
function si_stock_preparar_operacion(PDO $conexion): array
{
    static $ejecutado = [];
    $clave = spl_object_id($conexion);
    if (isset($ejecutado[$clave])) {
        return $ejecutado[$clave];
    }

    $vencidos = 0;
    do {
        $lote = si_stock_liberar_apartados_vencidos($conexion, 500);
        $vencidos += $lote;
    } while ($lote === 500);

    $normalizadas = 0;
    do {
        $lote = si_stock_normalizar_qr_pendientes_legacy($conexion, 200);
        $normalizadas += $lote;
    } while ($lote === 200);

    $sinQr = 0;
    do {
        $lote = si_stock_resolver_qr_pendientes_deshabilitados($conexion, 200);
        $sinQr += $lote;
    } while ($lote === 200);

    return $ejecutado[$clave] = [
        'apartados_vencidos' => $vencidos,
        'ventas_qr_normalizadas' => $normalizadas,
        'ventas_salida_sin_qr' => $sinQr,
    ];
}
