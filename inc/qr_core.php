<?php

declare(strict_types=1);

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

function si_qr_habilitado(PDO $conexion): bool
{
    $stmt = $conexion->prepare(
        "SELECT valor_texto
         FROM configuracion_sistema
         WHERE clave = :clave
         LIMIT 1"
    );
    $stmt->execute([':clave' => 'qr.validacion_salida']);
    $valor = $stmt->fetchColumn();

    if ($valor === false || $valor === null) {
        return true;
    }

    return in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí', 'on'], true);
}

function si_qr_payload(string $token): string
{
    return 'SIQR:' . strtolower($token);
}

function si_qr_normalizar_token(string $entrada): ?string
{
    $entrada = trim($entrada);
    if ($entrada === '') {
        return null;
    }

    // Acepta el payload oficial SIQR:<token>, el token solo o incluso una URL
    // que contenga el token. El dato significativo siempre son 64 hexadecimales.
    if (preg_match('/(?:SIQR:)?([a-f0-9]{64})/i', $entrada, $m) === 1) {
        return strtolower($m[1]);
    }

    return null;
}

function si_qr_token_corto(string $token): string
{
    $token = strtolower($token);
    return strtoupper(substr($token, 0, 6) . '-' . substr($token, -6));
}

function si_qr_token_venta_actual(PDO $conexion, int $ventaId): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            t.id,
            t.venta_id,
            t.token,
            t.activo,
            t.generado_at,
            t.usado_at,
            t.usado_by,
            t.revocado_at,
            t.revocado_by,
            t.motivo_revocacion,
            CONCAT_WS(' ', uu.nombres, uu.apellido_paterno, uu.apellido_materno) AS usado_por
         FROM tokens_qr_venta t
         LEFT JOIN usuarios uu ON uu.id = t.usado_by
         WHERE t.venta_id = :venta_id
         ORDER BY t.id DESC
         LIMIT 1"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    $fila['venta_id'] = (int) $fila['venta_id'];
    $fila['activo'] = (int) $fila['activo'];
    $fila['usado_by'] = $fila['usado_by'] !== null ? (int) $fila['usado_by'] : null;
    $fila['revocado_by'] = $fila['revocado_by'] !== null ? (int) $fila['revocado_by'] : null;
    return $fila;
}

function si_qr_token_activo_venta(PDO $conexion, int $ventaId): ?array
{
    $fila = si_qr_token_venta_actual($conexion, $ventaId);
    if ($fila === null) {
        return null;
    }

    if ((int) $fila['activo'] !== 1 || $fila['usado_at'] !== null || $fila['revocado_at'] !== null) {
        return null;
    }

    return $fila;
}

function si_qr_asegurar_token_venta(PDO $conexion, int $ventaId): ?array
{
    if ($ventaId <= 0 || !si_qr_habilitado($conexion)) {
        return null;
    }

    $transaccionPropia = !$conexion->inTransaction();
    if ($transaccionPropia) {
        $conexion->beginTransaction();
    }

    try {
        $stmtVenta = $conexion->prepare(
            "SELECT id, folio, estado
             FROM ventas
             WHERE id = :venta_id
             LIMIT 1
             FOR UPDATE"
        );
        $stmtVenta->execute([':venta_id' => $ventaId]);
        $venta = $stmtVenta->fetch();

        if (!$venta || $venta['estado'] !== 'CONFIRMADA') {
            if ($transaccionPropia) {
                $conexion->commit();
            }
            return null;
        }

        /*
         * Una venta conserva un único QR operativo. Si ya fue usado o revocado,
         * NUNCA se genera otro automáticamente: hacerlo permitiría una segunda
         * salida física con la misma venta.
         */
        $existente = si_qr_token_venta_actual($conexion, $ventaId);
        if ($existente !== null) {
            if ($transaccionPropia) {
                $conexion->commit();
            }
            return $existente;
        }

        $insert = $conexion->prepare(
            "INSERT INTO tokens_qr_venta (venta_id, token, activo, generado_at)
             VALUES (:venta_id, :token, 1, NOW())"
        );

        $token = null;
        $tokenId = null;
        for ($intento = 0; $intento < 4; $intento++) {
            $candidato = bin2hex(random_bytes(32));
            try {
                $insert->execute([
                    ':venta_id' => $ventaId,
                    ':token' => $candidato,
                ]);
                $token = $candidato;
                $tokenId = (int) $conexion->lastInsertId();
                break;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000' || $intento === 3) {
                    throw $e;
                }
            }
        }

        if ($token === null || $tokenId === null) {
            throw new RuntimeException('No fue posible generar un token QR único.');
        }

        $resultado = [
            'id' => $tokenId,
            'venta_id' => $ventaId,
            'token' => $token,
            'activo' => 1,
            'generado_at' => date('Y-m-d H:i:s'),
            'usado_at' => null,
            'usado_by' => null,
            'usado_por' => null,
            'revocado_at' => null,
            'revocado_by' => null,
            'motivo_revocacion' => null,
        ];

        if ($transaccionPropia) {
            $conexion->commit();
        }

        return $resultado;
    } catch (Throwable $e) {
        if ($transaccionPropia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

function si_qr_resumen_venta(PDO $conexion, int $ventaId): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            v.id,
            v.folio,
            v.cliente_id,
            v.cliente_nombre_snapshot,
            v.cliente_rfc_snapshot,
            v.fecha_venta,
            v.condicion_pago,
            v.estado,
            v.total,
            v.moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            COALESCE(a.importe_anticipado, 0) AS importe_anticipado,
            COALESCE(pv.pagado_directo, 0) AS pagado_directo,
            cx.id AS cxc_id,
            cx.folio AS cxc_folio,
            cx.importe_original AS cxc_importe_original,
            COALESCE(cx.importe_pagado, 0) AS cxc_importe_pagado,
            COALESCE(cx.saldo_pendiente, 0) AS cxc_saldo_pendiente,
            cx.fecha_vencimiento AS cxc_fecha_vencimiento,
            cx.estado AS cxc_estado
         FROM ventas v
         INNER JOIN monedas m ON m.id = v.moneda_id
         LEFT JOIN apartados a ON a.id = v.apartado_id
         LEFT JOIN (
            SELECT venta_id, SUM(importe) AS pagado_directo
            FROM pagos_venta
            WHERE estado = 'APLICADO'
            GROUP BY venta_id
         ) pv ON pv.venta_id = v.id
         LEFT JOIN cuentas_por_cobrar cx ON cx.venta_id = v.id
         WHERE v.id = :venta_id
         LIMIT 1"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $v = $stmt->fetch();

    if (!$v) {
        return null;
    }

    $v['id'] = (int) $v['id'];
    $v['cliente_id'] = $v['cliente_id'] !== null ? (int) $v['cliente_id'] : null;
    $v['moneda_id'] = (int) $v['moneda_id'];
    $v['total'] = (float) $v['total'];
    $v['importe_anticipado'] = (float) $v['importe_anticipado'];
    $v['pagado_directo'] = (float) $v['pagado_directo'];
    $v['cxc_importe_original'] = $v['cxc_importe_original'] !== null ? (float) $v['cxc_importe_original'] : null;
    $v['cxc_importe_pagado'] = (float) $v['cxc_importe_pagado'];
    $v['cxc_saldo_pendiente'] = (float) $v['cxc_saldo_pendiente'];

    $cubierto = round(
        $v['importe_anticipado']
        + $v['pagado_directo']
        + $v['cxc_importe_pagado'],
        4
    );
    $saldo = max(0.0, round($v['total'] - $cubierto, 4));
    $tolerancia = 0.01;

    if ($v['condicion_pago'] === 'CREDITO') {
        if ($saldo <= $tolerancia) {
            $estadoPago = 'PAGADO';
            $estadoPagoTexto = 'Crédito liquidado';
        } elseif ($v['cxc_importe_pagado'] > $tolerancia || $v['importe_anticipado'] > $tolerancia) {
            $estadoPago = 'CREDITO_PARCIAL';
            $estadoPagoTexto = 'Crédito con saldo pendiente';
        } else {
            $estadoPago = 'CREDITO_PENDIENTE';
            $estadoPagoTexto = 'Venta autorizada a crédito';
        }
    } else {
        $estadoPago = $saldo <= $tolerancia ? 'PAGADO' : 'NO_PAGADO';
        $estadoPagoTexto = $saldo <= $tolerancia ? 'Pagado' : 'Pago incompleto';
    }

    $v['importe_cubierto'] = $cubierto;
    $v['saldo_pago'] = $saldo;
    $v['estado_pago'] = $estadoPago;
    $v['estado_pago_texto'] = $estadoPagoTexto;
    $v['salida_financieramente_autorizada'] = (
        $v['estado'] === 'CONFIRMADA'
        && (
            $v['condicion_pago'] === 'CREDITO'
            || $estadoPago === 'PAGADO'
        )
    );

    return $v;
}

function si_qr_detalles_venta(PDO $conexion, int $ventaId): array
{
    $stmt = $conexion->prepare(
        "SELECT
            d.renglon,
            d.producto_id,
            d.producto_nombre_snapshot,
            d.sku_snapshot,
            d.cantidad,
            d.cantidad_base,
            d.unidad_nombre_snapshot,
            d.total,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.controla_inventario
         FROM ventas_detalle d
         INNER JOIN almacenes a ON a.id = d.almacen_id
         INNER JOIN productos p ON p.id = d.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         WHERE d.venta_id = :venta_id
         ORDER BY d.renglon ASC, d.id ASC"
    );
    $stmt->execute([':venta_id' => $ventaId]);
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['renglon'] = (int) $fila['renglon'];
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['cantidad'] = (float) $fila['cantidad'];
        $fila['cantidad_base'] = (float) $fila['cantidad_base'];
        $fila['total'] = (float) $fila['total'];
        $fila['controla_inventario'] = (int) $fila['controla_inventario'];
    }
    unset($fila);

    return $filas;
}

function si_qr_auditar(
    PDO $conexion,
    string $accion,
    ?int $entidadId,
    string $descripcion,
    ?array $datos = null,
    ?array $datosAnteriores = null
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, fecha_hora, accion, modulo, entidad_tabla, entidad_id,
             descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, NOW(), :accion, 'QR', :entidad_tabla, :entidad_id,
             :descripcion, :datos_anteriores, :datos_nuevos, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario_id' => isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null,
        ':accion' => mb_substr($accion, 0, 60),
        ':entidad_tabla' => $entidadId !== null ? 'ventas' : null,
        ':entidad_id' => $entidadId,
        ':descripcion' => mb_substr($descripcion, 0, 500),
        ':datos_anteriores' => $datosAnteriores !== null
            ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : null,
        ':datos_nuevos' => $datos !== null
            ? json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : null,
        ':ip' => function_exists('si_ip_cliente') ? si_ip_cliente() : ($_SERVER['REMOTE_ADDR'] ?? null),
        ':user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}
