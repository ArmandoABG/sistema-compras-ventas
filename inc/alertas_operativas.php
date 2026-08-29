<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Motor central de alertas operativas
|--------------------------------------------------------------------------
| Calcula pendientes desde la información real del sistema. No modifica
| inventario, documentos ni estados. El Topbar usa solo resúmenes ligeros;
| el Dashboard solicita además ejemplos de cada alerta.
|--------------------------------------------------------------------------
*/

function si_alertas_operativas_resumen(PDO $conexion, bool $incluirDetalles = true): array
{
    $alertas = [];

    if (si_tiene_permiso('inventario.ver')) {
        array_push($alertas, ...si_alertas_inventario($conexion, $incluirDetalles));
    }

    if (si_tiene_permiso('cuentas_cobrar.ver')) {
        array_push($alertas, ...si_alertas_cuentas_cobrar($conexion, $incluirDetalles));
    }

    if (si_tiene_permiso('cuentas_pagar.ver')) {
        array_push($alertas, ...si_alertas_cuentas_pagar($conexion, $incluirDetalles));
    }

    if (si_tiene_permiso('compras.ver')) {
        $alerta = si_alerta_compras_pendientes($conexion, $incluirDetalles);
        if ($alerta !== null) {
            $alertas[] = $alerta;
        }
    }

    if (si_tiene_permiso('apartados.ver')) {
        array_push($alertas, ...si_alertas_apartados($conexion, $incluirDetalles));
    }

    if (si_tiene_permiso('devoluciones.ver')) {
        $alerta = si_alerta_regularizaciones($conexion, $incluirDetalles);
        if ($alerta !== null) {
            $alertas[] = $alerta;
        }
    }

    if (si_tiene_permiso('qr.verificar') || si_tiene_permiso('ventas.ver')) {
        $alerta = si_alerta_salidas_qr($conexion, $incluirDetalles);
        if ($alerta !== null) {
            $alertas[] = $alerta;
        }
    }

    $notificaciones = si_alerta_notificaciones_internas($conexion, $incluirDetalles);
    if ($notificaciones !== null) {
        $alertas[] = $notificaciones;
    }

    $alertas = si_alertas_aplicar_estado_lectura($conexion, $alertas);

    usort($alertas, static function (array $a, array $b): int {
        if ((bool) ($a['leida'] ?? false) !== (bool) ($b['leida'] ?? false)) {
            return (bool) ($a['leida'] ?? false) ? 1 : -1;
        }

        $orden = ['CRITICA' => 0, 'ALTA' => 1, 'NORMAL' => 2, 'BAJA' => 3];
        $pa = $orden[$a['prioridad'] ?? 'NORMAL'] ?? 2;
        $pb = $orden[$b['prioridad'] ?? 'NORMAL'] ?? 2;

        return $pa !== $pb
            ? $pa <=> $pb
            : ((int) ($b['conteo'] ?? 0)) <=> ((int) ($a['conteo'] ?? 0));
    });

    $prioridades = ['CRITICA' => 0, 'ALTA' => 0, 'NORMAL' => 0, 'BAJA' => 0];
    $prioridadesSinLeer = ['CRITICA' => 0, 'ALTA' => 0, 'NORMAL' => 0, 'BAJA' => 0];
    $total = 0;
    $totalSinLeer = 0;
    $gruposSinLeer = 0;
    $gruposLeidos = 0;

    foreach ($alertas as $alerta) {
        $conteo = max(1, (int) ($alerta['conteo'] ?? 1));
        $prioridad = (string) ($alerta['prioridad'] ?? 'NORMAL');
        $leida = (bool) ($alerta['leida'] ?? false);
        $total += $conteo;

        if (isset($prioridades[$prioridad])) {
            $prioridades[$prioridad] += $conteo;
        }

        if ($leida) {
            $gruposLeidos++;
            continue;
        }

        $gruposSinLeer++;
        $totalSinLeer += $conteo;
        if (isset($prioridadesSinLeer[$prioridad])) {
            $prioridadesSinLeer[$prioridad] += $conteo;
        }
    }

    return [
        'total' => $total,
        'total_sin_leer' => $totalSinLeer,
        'grupos' => count($alertas),
        'grupos_sin_leer' => $gruposSinLeer,
        'grupos_leidos' => $gruposLeidos,
        'prioridades' => $prioridades,
        'prioridades_sin_leer' => $prioridadesSinLeer,
        'alertas' => $alertas,
        'generado_at' => date('Y-m-d H:i:s'),
        'reglas' => [
            'vencimientos_dias' => 7,
            'apartados_horas' => 48,
            'qr_horas_alta' => 24,
            'compras_dias_alta' => 7,
        ],
    ];
}

function si_alertas_inventario(PDO $conexion, bool $incluirDetalles): array
{
    $sqlBase = "
        FROM existencias_almacen ea
        INNER JOIN productos p ON p.id = ea.producto_id
        INNER JOIN almacenes a ON a.id = ea.almacen_id
        INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
        WHERE 1=1
          AND p.activo = 1
          AND p.controla_inventario = 1
          AND a.activo = 1
    ";

    $conteos = $conexion->query(
        "SELECT
            COALESCE(SUM(CASE WHEN ea.cantidad_disponible <= 0 THEN 1 ELSE 0 END), 0) AS sin_disponible,
            COALESCE(SUM(CASE
                WHEN ea.cantidad_disponible > 0
                 AND ea.stock_minimo > 0
                 AND ea.cantidad_disponible <= ea.stock_minimo
                THEN 1 ELSE 0 END), 0) AS critico,
            COALESCE(SUM(CASE
                WHEN ea.cantidad_disponible > COALESCE(ea.stock_minimo, 0)
                 AND ea.punto_reorden IS NOT NULL
                 AND ea.punto_reorden > 0
                 AND ea.cantidad_disponible <= ea.punto_reorden
                THEN 1 ELSE 0 END), 0) AS reorden
         {$sqlBase}"
    )->fetch() ?: [];

    $detalles = ['SIN_DISPONIBLE' => [], 'CRITICO' => [], 'REORDEN' => []];

    if ($incluirDetalles) {
        $stmt = $conexion->query(
            "SELECT
                p.nombre AS producto,
                a.nombre AS almacen,
                um.simbolo AS unidad,
                ea.existencia_fisica,
                ea.cantidad_disponible,
                CASE
                    WHEN ea.existencia_fisica <= 0 THEN 'SIN_STOCK'
                    WHEN ea.cantidad_disponible <= 0 THEN 'SIN_DISPONIBLE'
                    WHEN ea.stock_minimo > 0 AND ea.cantidad_disponible <= ea.stock_minimo THEN 'CRITICO'
                    WHEN ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND ea.cantidad_disponible <= ea.punto_reorden THEN 'REORDEN'
                    ELSE 'NORMAL'
                END AS estado_stock
             {$sqlBase}
               AND (
                    ea.cantidad_disponible <= 0
                    OR (ea.stock_minimo > 0 AND ea.cantidad_disponible <= ea.stock_minimo)
                    OR (ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND ea.cantidad_disponible <= ea.punto_reorden)
               )
             ORDER BY
                CASE
                    WHEN ea.existencia_fisica <= 0 THEN 0
                    WHEN ea.cantidad_disponible <= 0 THEN 1
                    WHEN ea.stock_minimo > 0 AND ea.cantidad_disponible <= ea.stock_minimo THEN 2
                    ELSE 3
                END,
                p.nombre,
                a.nombre
             LIMIT 18"
        );

        foreach ($stmt->fetchAll() as $fila) {
            $estado = (string) ($fila['estado_stock'] ?? '');
            $grupo = in_array($estado, ['SIN_STOCK', 'SIN_DISPONIBLE'], true)
                ? 'SIN_DISPONIBLE'
                : $estado;

            if (!isset($detalles[$grupo]) || count($detalles[$grupo]) >= 5) {
                continue;
            }

            $unidad = trim((string) ($fila['unidad'] ?? ''));
            $detalles[$grupo][] = [
                'principal' => (string) $fila['producto'],
                'secundario' => (string) $fila['almacen'],
                'meta' => si_alerta_numero((float) $fila['cantidad_disponible'])
                    . ($unidad !== '' ? ' ' . $unidad : '')
                    . ' disponibles',
            ];
        }
    }

    $salida = [];
    $sinDisponible = (int) ($conteos['sin_disponible'] ?? 0);
    $criticos = (int) ($conteos['critico'] ?? 0);
    $reorden = (int) ($conteos['reorden'] ?? 0);

    if ($sinDisponible > 0) {
        $salida[] = si_alerta_crear(
            'inventario-sin-disponible', 'INVENTARIO', 'CRITICA',
            'Inventario sin disponibilidad',
            $sinDisponible === 1
                ? 'Hay 1 producto/almacén sin cantidad disponible para operar.'
                : "Hay {$sinDisponible} productos/almacenes sin cantidad disponible para operar.",
            $sinDisponible, si_url('JS/inventario.php'), 'Revisar inventario', $detalles['SIN_DISPONIBLE']
        );
    }

    if ($criticos > 0) {
        $salida[] = si_alerta_crear(
            'inventario-critico', 'INVENTARIO', 'ALTA',
            'Stock en nivel crítico',
            $criticos === 1
                ? '1 producto/almacén está en su stock mínimo o por debajo.'
                : "{$criticos} productos/almacenes están en su stock mínimo o por debajo.",
            $criticos, si_url('JS/inventario.php'), 'Atender stock crítico', $detalles['CRITICO']
        );
    }

    if ($reorden > 0) {
        $salida[] = si_alerta_crear(
            'inventario-reorden', 'INVENTARIO', 'NORMAL',
            'Productos en punto de reorden',
            $reorden === 1
                ? '1 producto/almacén ya llegó al nivel recomendado para reabastecer.'
                : "{$reorden} productos/almacenes ya llegaron al nivel recomendado para reabastecer.",
            $reorden, si_url('JS/inventario.php'), 'Planear reabastecimiento', $detalles['REORDEN']
        );
    }

    return $salida;
}

function si_alertas_cuentas_cobrar(PDO $conexion, bool $incluirDetalles): array
{
    return si_alertas_financieras(
        $conexion,
        $incluirDetalles,
        'cuentas_por_cobrar',
        'clientes',
        'cpc',
        'c',
        'c.nombre_razon_social',
        'COBROS',
        'Cuentas por cobrar vencidas',
        'Cobros próximos a vencer',
        si_url('JS/cuentas_cobrar.php'),
        'Revisar cuentas por cobrar'
    );
}

function si_alertas_cuentas_pagar(PDO $conexion, bool $incluirDetalles): array
{
    return si_alertas_financieras(
        $conexion,
        $incluirDetalles,
        'cuentas_por_pagar',
        'proveedores',
        'cpp',
        'p',
        'p.razon_social',
        'PAGOS',
        'Cuentas por pagar vencidas',
        'Pagos próximos a vencer',
        si_url('JS/cuentas_pagar.php'),
        'Revisar cuentas por pagar'
    );
}

function si_alertas_financieras(
    PDO $conexion,
    bool $incluirDetalles,
    string $tabla,
    string $tablaTercero,
    string $alias,
    string $aliasTercero,
    string $columnaTercero,
    string $categoria,
    string $tituloVencidas,
    string $tituloProximas,
    string $href,
    string $accion
): array {
    $fkTercero = $categoria === 'COBROS' ? 'cliente_id' : 'proveedor_id';

    $base = "
        FROM {$tabla} {$alias}
        INNER JOIN {$tablaTercero} {$aliasTercero}
            ON {$aliasTercero}.id = {$alias}.{$fkTercero}
        INNER JOIN monedas m
            ON m.id = {$alias}.moneda_id
        WHERE {$alias}.estado IN ('PENDIENTE', 'PARCIAL', 'VENCIDA')
          AND {$alias}.saldo_pendiente > 0
    ";

    $conteos = $conexion->query(
        "SELECT
            COALESCE(SUM(CASE WHEN {$alias}.fecha_vencimiento < CURDATE() THEN 1 ELSE 0 END), 0) AS vencidas,
            COALESCE(SUM(CASE
                WHEN {$alias}.fecha_vencimiento >= CURDATE()
                 AND {$alias}.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                THEN 1 ELSE 0 END), 0) AS proximas,
            COALESCE(MAX(CASE
                WHEN {$alias}.fecha_vencimiento < CURDATE()
                THEN DATEDIFF(CURDATE(), {$alias}.fecha_vencimiento)
                ELSE 0 END), 0) AS max_dias_vencida
         {$base}"
    )->fetch() ?: [];

    $detVencidas = [];
    $detProximas = [];

    if ($incluirDetalles) {
        $filas = $conexion->query(
            "SELECT
                {$alias}.folio,
                {$columnaTercero} AS tercero,
                m.codigo AS moneda,
                {$alias}.saldo_pendiente,
                {$alias}.fecha_vencimiento,
                DATEDIFF(CURDATE(), {$alias}.fecha_vencimiento) AS dias_vencida
             {$base}
               AND {$alias}.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY {$alias}.fecha_vencimiento, {$alias}.id
             LIMIT 10"
        )->fetchAll();

        foreach ($filas as $fila) {
            $detalle = [
                'principal' => (string) ($fila['tercero'] ?? $fila['folio'] ?? ''),
                'secundario' => (string) ($fila['folio'] ?? ''),
                'meta' => si_alerta_importe((float) ($fila['saldo_pendiente'] ?? 0), (string) ($fila['moneda'] ?? ''))
                    . ' · vence ' . (string) ($fila['fecha_vencimiento'] ?? ''),
            ];

            if ((int) ($fila['dias_vencida'] ?? 0) > 0) {
                if (count($detVencidas) < 5) {
                    $detVencidas[] = $detalle;
                }
            } elseif (count($detProximas) < 5) {
                $detProximas[] = $detalle;
            }
        }
    }

    $salida = [];
    $vencidas = (int) ($conteos['vencidas'] ?? 0);
    $proximas = (int) ($conteos['proximas'] ?? 0);
    $maxDias = (int) ($conteos['max_dias_vencida'] ?? 0);

    if ($vencidas > 0) {
        $salida[] = si_alerta_crear(
            strtolower($categoria) . '-vencidas', $categoria, $maxDias >= 7 ? 'CRITICA' : 'ALTA',
            $tituloVencidas,
            $vencidas === 1 ? 'Hay 1 cuenta vencida con saldo pendiente.' : "Hay {$vencidas} cuentas vencidas con saldo pendiente.",
            $vencidas, $href, $accion, $detVencidas
        );
    }

    if ($proximas > 0) {
        $salida[] = si_alerta_crear(
            strtolower($categoria) . '-proximas', $categoria, 'NORMAL',
            $tituloProximas,
            $proximas === 1
                ? '1 cuenta vence hoy o dentro de los próximos 7 días.'
                : "{$proximas} cuentas vencen hoy o dentro de los próximos 7 días.",
            $proximas, $href, $accion, $detProximas
        );
    }

    return $salida;
}

function si_alerta_compras_pendientes(PDO $conexion, bool $incluirDetalles): ?array
{
    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(MAX(DATEDIFF(CURDATE(), DATE(fecha_compra))), 0) AS antiguedad_maxima
         FROM compras
         WHERE estado IN ('PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL')"
    )->fetch() ?: [];

    $total = (int) ($resumen['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $detalles = [];
    if ($incluirDetalles) {
        $filas = $conexion->query(
            "SELECT folio, proveedor_nombre_snapshot AS proveedor, estado, DATE(fecha_compra) AS fecha_compra
             FROM compras
             WHERE estado IN ('PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL')
             ORDER BY fecha_compra, id
             LIMIT 5"
        )->fetchAll();

        foreach ($filas as $fila) {
            $detalles[] = [
                'principal' => (string) $fila['folio'],
                'secundario' => (string) $fila['proveedor'],
                'meta' => ((string) $fila['estado'] === 'RECIBIDA_PARCIAL' ? 'Recepción parcial' : 'Pendiente de recibir')
                    . ' · ' . (string) $fila['fecha_compra'],
            ];
        }
    }

    $dias = (int) ($resumen['antiguedad_maxima'] ?? 0);

    return si_alerta_crear(
        'compras-pendientes-recepcion', 'COMPRAS', $dias >= 7 ? 'ALTA' : 'NORMAL',
        'Compras pendientes de recepción',
        $total === 1
            ? 'Hay 1 compra que todavía no ha sido recibida completamente.'
            : "Hay {$total} compras que todavía no han sido recibidas completamente.",
        $total, si_url('JS/compras.php'), 'Revisar recepciones', $detalles
    );
}

function si_alertas_apartados(PDO $conexion, bool $incluirDetalles): array
{
    $resumen = $conexion->query(
        "SELECT
            COALESCE(SUM(CASE WHEN reservado_hasta IS NOT NULL AND reservado_hasta < NOW() THEN 1 ELSE 0 END), 0) AS vencidos,
            COALESCE(SUM(CASE
                WHEN reservado_hasta IS NOT NULL
                 AND reservado_hasta >= NOW()
                 AND reservado_hasta <= DATE_ADD(NOW(), INTERVAL 48 HOUR)
                THEN 1 ELSE 0 END), 0) AS proximos
         FROM apartados
         WHERE estado = 'ACTIVO'"
    )->fetch() ?: [];

    $detVencidos = [];
    $detProximos = [];

    if ($incluirDetalles) {
        $filas = $conexion->query(
            "SELECT
                ap.folio,
                c.nombre_razon_social AS cliente,
                ap.reservado_hasta,
                TIMESTAMPDIFF(HOUR, NOW(), ap.reservado_hasta) AS horas_restantes
             FROM apartados ap
             INNER JOIN clientes c ON c.id = ap.cliente_id
             WHERE ap.estado = 'ACTIVO'
               AND ap.reservado_hasta IS NOT NULL
               AND ap.reservado_hasta <= DATE_ADD(NOW(), INTERVAL 48 HOUR)
             ORDER BY ap.reservado_hasta, ap.id
             LIMIT 10"
        )->fetchAll();

        foreach ($filas as $fila) {
            $detalle = [
                'principal' => (string) $fila['folio'],
                'secundario' => (string) $fila['cliente'],
                'meta' => 'Reserva hasta ' . (string) $fila['reservado_hasta'],
            ];

            if ((int) ($fila['horas_restantes'] ?? 0) < 0) {
                if (count($detVencidos) < 5) {
                    $detVencidos[] = $detalle;
                }
            } elseif (count($detProximos) < 5) {
                $detProximos[] = $detalle;
            }
        }
    }

    $salida = [];
    $vencidos = (int) ($resumen['vencidos'] ?? 0);
    $proximos = (int) ($resumen['proximos'] ?? 0);

    if ($vencidos > 0) {
        $salida[] = si_alerta_crear(
            'apartados-vencidos-activos', 'APARTADOS', 'CRITICA',
            'Apartados vencidos todavía activos',
            $vencidos === 1
                ? 'Hay 1 apartado cuya reserva ya venció y todavía aparece activo.'
                : "Hay {$vencidos} apartados cuya reserva ya venció y todavía aparecen activos.",
            $vencidos, si_url('JS/apartados.php'), 'Revisar apartados', $detVencidos
        );
    }

    if ($proximos > 0) {
        $salida[] = si_alerta_crear(
            'apartados-por-vencer', 'APARTADOS', 'ALTA',
            'Apartados próximos a vencer',
            $proximos === 1
                ? '1 apartado vence dentro de las próximas 48 horas.'
                : "{$proximos} apartados vencen dentro de las próximas 48 horas.",
            $proximos, si_url('JS/apartados.php'), 'Atender apartados', $detProximos
        );
    }

    return $salida;
}

function si_alerta_regularizaciones(PDO $conexion, bool $incluirDetalles): ?array
{
    $total = (int) $conexion->query(
        "SELECT COUNT(*) FROM regularizaciones_financieras WHERE estado = 'PENDIENTE'"
    )->fetchColumn();

    if ($total <= 0) {
        return null;
    }

    $detalles = [];
    if ($incluirDetalles) {
        $filas = $conexion->query(
            "SELECT
                rf.folio,
                rf.tipo,
                rf.importe,
                m.codigo AS moneda,
                COALESCE(c.nombre_razon_social, p.razon_social, 'Sin tercero') AS tercero
             FROM regularizaciones_financieras rf
             INNER JOIN monedas m ON m.id = rf.moneda_id
             LEFT JOIN clientes c ON c.id = rf.cliente_id
             LEFT JOIN proveedores p ON p.id = rf.proveedor_id
             WHERE rf.estado = 'PENDIENTE'
             ORDER BY rf.created_at, rf.id
             LIMIT 5"
        )->fetchAll();

        foreach ($filas as $fila) {
            $detalles[] = [
                'principal' => (string) $fila['folio'],
                'secundario' => (string) $fila['tercero'],
                'meta' => si_alerta_importe((float) $fila['importe'], (string) $fila['moneda'])
                    . ' · ' . ((string) $fila['tipo'] === 'REEMBOLSO_CLIENTE' ? 'Reembolso cliente' : 'Reintegro proveedor'),
            ];
        }
    }

    return si_alerta_crear(
        'regularizaciones-pendientes', 'DEVOLUCIONES', 'ALTA',
        'Regularizaciones financieras pendientes',
        $total === 1
            ? 'Hay 1 reembolso o reintegro pendiente de liquidar.'
            : "Hay {$total} reembolsos o reintegros pendientes de liquidar.",
        $total, si_url('JS/devoluciones.php'), 'Revisar regularizaciones', $detalles
    );
}

function si_alerta_salidas_qr(PDO $conexion, bool $incluirDetalles): ?array
{
    $config = $conexion->prepare(
        "SELECT valor_texto FROM configuracion_sistema WHERE clave = :clave LIMIT 1"
    );
    $config->execute([':clave' => 'qr.validacion_salida']);

    $valor = $config->fetchColumn();
    if ($valor !== false && !in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí'], true)) {
        return null;
    }

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(MAX(TIMESTAMPDIFF(HOUR, tq.generado_at, NOW())), 0) AS horas_maximas
         FROM tokens_qr_venta tq
         INNER JOIN ventas v ON v.id = tq.venta_id
         WHERE tq.activo = 1
           AND tq.usado_at IS NULL
           AND tq.revocado_at IS NULL
           AND v.estado = 'CONFIRMADA'"
    )->fetch() ?: [];

    $total = (int) ($resumen['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $detalles = [];
    if ($incluirDetalles) {
        $filas = $conexion->query(
            "SELECT
                v.folio,
                COALESCE(v.cliente_nombre_snapshot, 'Público general') AS cliente,
                TIMESTAMPDIFF(HOUR, tq.generado_at, NOW()) AS horas
             FROM tokens_qr_venta tq
             INNER JOIN ventas v ON v.id = tq.venta_id
             WHERE tq.activo = 1
               AND tq.usado_at IS NULL
               AND tq.revocado_at IS NULL
               AND v.estado = 'CONFIRMADA'
             ORDER BY tq.generado_at, tq.id
             LIMIT 5"
        )->fetchAll();

        foreach ($filas as $fila) {
            $detalles[] = [
                'principal' => (string) $fila['folio'],
                'secundario' => (string) $fila['cliente'],
                'meta' => ((int) $fila['horas']) . ' h pendiente de salida',
            ];
        }
    }

    $horas = (int) ($resumen['horas_maximas'] ?? 0);

    return si_alerta_crear(
        'ventas-salida-qr-pendiente', 'QR', $horas >= 24 ? 'ALTA' : 'NORMAL',
        'Ventas pendientes de salida QR',
        $total === 1
            ? 'Hay 1 venta confirmada cuya salida física todavía no ha sido verificada.'
            : "Hay {$total} ventas confirmadas cuya salida física todavía no ha sido verificada.",
        $total, si_url('JS/verificar_qr.php'), 'Verificar salidas', $detalles
    );
}

function si_alerta_notificaciones_internas(PDO $conexion, bool $incluirDetalles): ?array
{
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($usuarioId <= 0) {
        return null;
    }

    $stmt = $conexion->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN prioridad = 'CRITICA' THEN 1 ELSE 0 END), 0) AS criticas,
            COALESCE(SUM(CASE WHEN prioridad = 'ALTA' THEN 1 ELSE 0 END), 0) AS altas
         FROM notificaciones
         WHERE leida_at IS NULL
           AND (usuario_id = :usuario_id OR usuario_id IS NULL)"
    );
    $stmt->execute([':usuario_id' => $usuarioId]);

    $resumen = $stmt->fetch() ?: [];
    $total = (int) ($resumen['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $detalles = [];
    if ($incluirDetalles) {
        $stmt = $conexion->prepare(
            "SELECT titulo, mensaje, created_at
             FROM notificaciones
             WHERE leida_at IS NULL
               AND (usuario_id = :usuario_id OR usuario_id IS NULL)
             ORDER BY
                CASE prioridad
                    WHEN 'CRITICA' THEN 0
                    WHEN 'ALTA' THEN 1
                    WHEN 'NORMAL' THEN 2
                    ELSE 3
                END,
                created_at DESC,
                id DESC
             LIMIT 5"
        );
        $stmt->execute([':usuario_id' => $usuarioId]);

        foreach ($stmt->fetchAll() as $fila) {
            $detalles[] = [
                'principal' => (string) $fila['titulo'],
                'secundario' => (string) $fila['mensaje'],
                'meta' => (string) $fila['created_at'],
            ];
        }
    }

    $prioridad = (int) ($resumen['criticas'] ?? 0) > 0
        ? 'CRITICA'
        : ((int) ($resumen['altas'] ?? 0) > 0 ? 'ALTA' : 'NORMAL');

    return si_alerta_crear(
        'notificaciones-internas', 'SISTEMA', $prioridad,
        'Notificaciones internas pendientes',
        $total === 1
            ? 'Tienes 1 notificación interna pendiente de revisar.'
            : "Tienes {$total} notificaciones internas pendientes de revisar.",
        $total, si_url('JS/dashboard.php#centroAlertas'), 'Ver centro de alertas', $detalles
    );
}

function si_alertas_tabla_lecturas_disponible(PDO $conexion): bool
{
    static $disponible = null;

    if ($disponible !== null) {
        return $disponible;
    }

    try {
        $stmt = $conexion->query(
            "SELECT 1
             FROM alertas_operativas_lecturas
             LIMIT 1"
        );
        // Si la tabla existe, la consulta es válida aunque todavía no tenga filas.
        $disponible = $stmt !== false;
    } catch (Throwable $e) {
        $disponible = false;
    }

    return $disponible;
}

function si_alertas_aplicar_estado_lectura(PDO $conexion, array $alertas): array
{
    if (!$alertas) {
        return [];
    }

    foreach ($alertas as &$alerta) {
        $alerta['leida'] = false;
        $alerta['leida_at'] = null;
    }
    unset($alerta);

    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($usuarioId <= 0 || !si_alertas_tabla_lecturas_disponible($conexion)) {
        return $alertas;
    }

    $claves = array_values(array_unique(array_filter(array_map(
        static fn(array $alerta): string => trim((string) ($alerta['clave'] ?? '')),
        $alertas
    ))));

    if (!$claves) {
        return $alertas;
    }

    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $stmt = $conexion->prepare(
        "SELECT alerta_clave, conteo_snapshot, prioridad_snapshot, leida_at
         FROM alertas_operativas_lecturas
         WHERE usuario_id = ?
           AND alerta_clave IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$usuarioId], $claves));

    $lecturas = [];
    foreach ($stmt->fetchAll() as $fila) {
        $lecturas[(string) $fila['alerta_clave']] = $fila;
    }

    $limite = time() - 86400;

    foreach ($alertas as &$alerta) {
        $clave = (string) ($alerta['clave'] ?? '');
        $lectura = $lecturas[$clave] ?? null;
        if (!$lectura) {
            continue;
        }

        $mismaCantidad = (int) ($lectura['conteo_snapshot'] ?? -1) === (int) ($alerta['conteo'] ?? 0);
        $mismaPrioridad = strtoupper((string) ($lectura['prioridad_snapshot'] ?? ''))
            === strtoupper((string) ($alerta['prioridad'] ?? 'NORMAL'));
        $leidaAt = strtotime((string) ($lectura['leida_at'] ?? '')) ?: 0;

        // Una alerta operativa no queda silenciada indefinidamente: si sigue
        // activa, vuelve a considerarse pendiente después de 24 horas.
        if ($mismaCantidad && $mismaPrioridad && $leidaAt >= $limite) {
            $alerta['leida'] = true;
            $alerta['leida_at'] = (string) $lectura['leida_at'];
        }
    }
    unset($alerta);

    return $alertas;
}

function si_alertas_marcar_leida(PDO $conexion, array $alerta): void
{
    if (!si_alertas_tabla_lecturas_disponible($conexion)) {
        throw new RuntimeException('Falta ejecutar el SQL de actualización de alertas.');
    }

    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $clave = trim((string) ($alerta['clave'] ?? ''));
    if ($usuarioId <= 0 || $clave === '') {
        throw new RuntimeException('No fue posible identificar la alerta o el usuario.');
    }

    $stmt = $conexion->prepare(
        "INSERT INTO alertas_operativas_lecturas
            (usuario_id, alerta_clave, conteo_snapshot, prioridad_snapshot, leida_at, created_at, updated_at)
         VALUES
            (:usuario_id, :alerta_clave, :conteo_snapshot, :prioridad_snapshot, NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            conteo_snapshot = VALUES(conteo_snapshot),
            prioridad_snapshot = VALUES(prioridad_snapshot),
            leida_at = NOW(),
            updated_at = NOW()"
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':alerta_clave' => $clave,
        ':conteo_snapshot' => max(1, (int) ($alerta['conteo'] ?? 1)),
        ':prioridad_snapshot' => strtoupper((string) ($alerta['prioridad'] ?? 'NORMAL')),
    ]);
}

function si_alertas_auditar_lectura(PDO $conexion, string $descripcion, array $datos): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario_id, 'ALERTA_LEIDA', 'dashboard', 'alertas_operativas_lecturas', NULL, :descripcion, :datos, :ip, :ua)"
    );
    $stmt->execute([
        ':usuario_id' => (int) ($_SESSION['usuario_id'] ?? 0) ?: null,
        ':descripcion' => $descripcion,
        ':datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ':ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}

function si_alerta_crear(
    string $clave,
    string $categoria,
    string $prioridad,
    string $titulo,
    string $mensaje,
    int $conteo,
    string $href,
    string $accion,
    array $detalles = []
): array {
    return [
        'clave' => $clave,
        'categoria' => $categoria,
        'prioridad' => $prioridad,
        'titulo' => $titulo,
        'mensaje' => $mensaje,
        'conteo' => max(1, $conteo),
        'href' => $href,
        'accion' => $accion,
        'detalles' => $detalles,
    ];
}

function si_alerta_numero(float $valor): string
{
    $redondeado = round($valor, 3);
    $decimales = abs($redondeado - round($redondeado)) < 0.000001 ? 0 : 3;

    return number_format($redondeado, $decimales, '.', ',');
}

function si_alerta_importe(float $valor, string $moneda): string
{
    return number_format($valor, 2, '.', ',')
        . ($moneda !== '' ? ' ' . $moneda : '');
}
