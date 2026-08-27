<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/alertas_operativas.php';

si_requerir_permiso(
    'dashboard.ver',
    true
);

if (!($conexion instanceof PDO)) {
    si_responder_json(
        false,
        'No fue posible conectar con la base de datos.',
        [],
        503
    );
}

si_requerir_metodo('GET');

$accion = strtoupper(
    trim((string) ($_GET['accion'] ?? 'RESUMEN'))
);

if ($accion !== 'RESUMEN') {
    si_responder_json(
        false,
        'La acción solicitada no es válida.',
        [],
        400
    );
}

try {
    $usuarioId = (int) $_SESSION['usuario_id'];


    $monedaBase = (string) ($conexion->query(
        "SELECT codigo
         FROM monedas
         WHERE es_base = 1
           AND activo = 1
         ORDER BY id
         LIMIT 1"
    )->fetchColumn() ?: 'MXN');

    $calcularVariacion = static function (float $actual, float $anterior): ?float {
        if (abs($anterior) < 0.000001) {
            return abs($actual) < 0.000001 ? 0.0 : null;
        }

        return (($actual - $anterior) / abs($anterior)) * 100;
    };

    /*
    |--------------------------------------------------------------------------
    | KPIs
    |--------------------------------------------------------------------------
    | Se usan rangos de fechas y agregados.
    | No se descargan miles de filas al navegador.
    |--------------------------------------------------------------------------
    */

    $ventasHoy = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM ventas
         WHERE estado = 'CONFIRMADA'
           AND fecha_venta >= CURDATE()
           AND fecha_venta < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
    )->fetchColumn();

    $stmt = $conexion->query(
        "SELECT
            m.codigo,
            COUNT(*) AS operaciones,
            COALESCE(SUM(v.total), 0) AS importe
         FROM ventas v
         INNER JOIN monedas m
            ON m.id = v.moneda_id
         WHERE v.estado = 'CONFIRMADA'
           AND v.fecha_venta >= CURDATE()
           AND v.fecha_venta < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY
            m.id,
            m.codigo,
            m.es_base
         ORDER BY
            m.es_base DESC,
            m.codigo"
    );
    $ventasHoyMonedas = $stmt->fetchAll();

    $comprasPorRecibir = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM compras
         WHERE estado IN (
            'PENDIENTE_RECEPCION',
            'RECIBIDA_PARCIAL'
         )"
    )->fetchColumn();

    $stockCritico = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM existencias_almacen ea
         INNER JOIN productos p
            ON p.id = ea.producto_id
         INNER JOIN almacenes a
            ON a.id = ea.almacen_id
         WHERE 1=1
           AND p.activo = 1
           AND p.controla_inventario = 1
           AND a.activo = 1
           AND (
                ea.cantidad_disponible <= 0
                OR (
                    ea.stock_minimo > 0
                    AND ea.cantidad_disponible <= ea.stock_minimo
                )
           )"
    )->fetchColumn();

    $cobrosVencidos = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM cuentas_por_cobrar
         WHERE estado IN (
            'PENDIENTE',
            'PARCIAL',
            'VENCIDA'
         )
           AND saldo_pendiente > 0
           AND fecha_vencimiento < CURDATE()"
    )->fetchColumn();

    $pagosVencidos = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM cuentas_por_pagar
         WHERE estado IN (
            'PENDIENTE',
            'PARCIAL',
            'VENCIDA'
         )
           AND saldo_pendiente > 0
           AND fecha_vencimiento < CURDATE()"
    )->fetchColumn();

    $stmt = $conexion->prepare(
        "SELECT COUNT(*)
         FROM notificaciones
         WHERE leida_at IS NULL
           AND (
                usuario_id = :usuario_id
                OR usuario_id IS NULL
           )"
    );

    $stmt->execute([
        ':usuario_id' => $usuarioId,
    ]);

    $notificaciones = (int) $stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Cuentas por cobrar
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            m.codigo,
            COUNT(*) AS cuentas,
            COALESCE(SUM(cpc.saldo_pendiente), 0) AS saldo_pendiente,
            COALESCE(
                SUM(
                    CASE
                        WHEN cpc.fecha_vencimiento < CURDATE()
                        THEN cpc.saldo_pendiente
                        ELSE 0
                    END
                ),
                0
            ) AS saldo_vencido
         FROM cuentas_por_cobrar cpc
         INNER JOIN monedas m
            ON m.id = cpc.moneda_id
         WHERE cpc.estado IN (
            'PENDIENTE',
            'PARCIAL',
            'VENCIDA'
         )
           AND cpc.saldo_pendiente > 0
         GROUP BY
            m.id,
            m.codigo,
            m.es_base
         ORDER BY
            m.es_base DESC,
            m.codigo"
    );

    $resumenCobrar = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Cuentas por pagar
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            m.codigo,
            COUNT(*) AS cuentas,
            COALESCE(SUM(cpp.saldo_pendiente), 0) AS saldo_pendiente,
            COALESCE(
                SUM(
                    CASE
                        WHEN cpp.fecha_vencimiento < CURDATE()
                        THEN cpp.saldo_pendiente
                        ELSE 0
                    END
                ),
                0
            ) AS saldo_vencido
         FROM cuentas_por_pagar cpp
         INNER JOIN monedas m
            ON m.id = cpp.moneda_id
         WHERE cpp.estado IN (
            'PENDIENTE',
            'PARCIAL',
            'VENCIDA'
         )
           AND cpp.saldo_pendiente > 0
         GROUP BY
            m.id,
            m.codigo,
            m.es_base
         ORDER BY
            m.es_base DESC,
            m.codigo"
    );

    $resumenPagar = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Inventario crítico - máximo 10
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            p.sku,
            p.nombre AS producto,
            p.tipo,
            a.nombre AS almacen,
            um.simbolo AS unidad,
            ea.existencia_fisica,
            ea.cantidad_reservada,
            ea.cantidad_disponible,
            ea.stock_minimo,
            ea.punto_reorden,
            CASE
                WHEN ea.existencia_fisica <= 0 THEN 'SIN_STOCK'
                WHEN ea.cantidad_disponible <= 0 THEN 'SIN_DISPONIBLE'
                WHEN ea.stock_minimo > 0
                 AND ea.cantidad_disponible <= ea.stock_minimo THEN 'CRITICO'
                ELSE 'NORMAL'
            END AS estado_stock
         FROM existencias_almacen ea
         INNER JOIN productos p
            ON p.id = ea.producto_id
         INNER JOIN almacenes a
            ON a.id = ea.almacen_id
         INNER JOIN unidades_medida um
            ON um.id = p.unidad_base_id
         WHERE 1=1
           AND p.activo = 1
           AND p.controla_inventario = 1
           AND a.activo = 1
           AND (
                ea.cantidad_disponible <= 0
                OR (
                    ea.stock_minimo > 0
                    AND ea.cantidad_disponible <= ea.stock_minimo
                )
           )
         ORDER BY
            CASE
                WHEN ea.existencia_fisica <= 0 THEN 0
                WHEN ea.cantidad_disponible <= 0 THEN 1
                ELSE 2
            END,
            CASE
                WHEN ea.stock_minimo > 0
                THEN ea.cantidad_disponible / NULLIF(ea.stock_minimo, 0)
                ELSE 999999
            END ASC,
            p.nombre ASC
         LIMIT 10"
    );

    $inventarioCritico = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Top 5 vendidos del mes
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            p.sku,
            p.nombre AS producto,
            um.simbolo AS unidad,
            COUNT(DISTINCT v.id) AS operaciones,
            COALESCE(SUM(vd.cantidad_base), 0) AS cantidad_base
         FROM ventas_detalle vd
         INNER JOIN ventas v
            ON v.id = vd.venta_id
         INNER JOIN productos p
            ON p.id = vd.producto_id
         INNER JOIN unidades_medida um
            ON um.id = p.unidad_base_id
         WHERE v.estado = 'CONFIRMADA'
           AND v.fecha_venta >= DATE_FORMAT(
                CURDATE(),
                '%Y-%m-01'
           )
           AND v.fecha_venta < DATE_ADD(
                DATE_FORMAT(CURDATE(), '%Y-%m-01'),
                INTERVAL 1 MONTH
           )
         GROUP BY
            p.id,
            p.sku,
            p.nombre,
            um.simbolo
         ORDER BY
            operaciones DESC,
            cantidad_base DESC,
            p.nombre ASC
         LIMIT 5"
    );

    $topProductos = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Top 5 clientes del mes, normalizado a moneda base.
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            c.codigo,
            c.nombre_razon_social AS cliente,
            COALESCE(n.nombre, 'General') AS nivel,
            COUNT(v.id) AS operaciones,
            COALESCE(SUM(v.total * v.tipo_cambio_a_base), 0) AS total_base,
            COALESCE(AVG(v.descuento_cliente_pct_snapshot), 0) AS descuento_promedio_pct
         FROM ventas v
         INNER JOIN clientes c
            ON c.id = v.cliente_id
         LEFT JOIN niveles_cliente n
            ON n.id = v.nivel_cliente_id
         WHERE v.estado = 'CONFIRMADA'
           AND v.cliente_id IS NOT NULL
           AND v.fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND v.fecha_venta < DATE_ADD(
                DATE_FORMAT(CURDATE(), '%Y-%m-01'),
                INTERVAL 1 MONTH
           )
         GROUP BY
            c.id,
            c.codigo,
            c.nombre_razon_social,
            n.nombre
         ORDER BY
            total_base DESC,
            operaciones DESC,
            c.nombre_razon_social ASC
         LIMIT 5"
    );

    $topClientes = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Tendencias semanales y mensuales
    |--------------------------------------------------------------------------
    | Todo se normaliza a la moneda base usando el tipo de cambio histórico
    | guardado en cada operación. Las compras BORRADOR/CANCELADA no cuentan.
    |--------------------------------------------------------------------------
    */

    $hoy = new DateTimeImmutable('today');
    $inicioSemana = $hoy->modify('-6 days');
    $finSemana = $hoy->modify('+1 day');
    $inicioSemanaAnterior = $inicioSemana->modify('-7 days');

    $stmt = $conexion->prepare(
        "SELECT
            DATE(fecha_venta) AS periodo,
            COUNT(*) AS operaciones,
            COALESCE(SUM(total * tipo_cambio_a_base), 0) AS importe_base
         FROM ventas
         WHERE estado = 'CONFIRMADA'
           AND fecha_venta >= :inicio
           AND fecha_venta < :fin
         GROUP BY DATE(fecha_venta)"
    );
    $stmt->execute([
        ':inicio' => $inicioSemanaAnterior->format('Y-m-d 00:00:00'),
        ':fin' => $finSemana->format('Y-m-d 00:00:00'),
    ]);
    $ventasDias = [];
    foreach ($stmt->fetchAll() as $fila) {
        $ventasDias[(string) $fila['periodo']] = [
            'importe' => (float) $fila['importe_base'],
            'operaciones' => (int) $fila['operaciones'],
        ];
    }

    $stmt = $conexion->prepare(
        "SELECT
            DATE(fecha_compra) AS periodo,
            COUNT(*) AS operaciones,
            COALESCE(SUM(total * tipo_cambio_a_base), 0) AS importe_base
         FROM compras
         WHERE estado IN ('PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL', 'RECIBIDA')
           AND fecha_compra >= :inicio
           AND fecha_compra < :fin
         GROUP BY DATE(fecha_compra)"
    );
    $stmt->execute([
        ':inicio' => $inicioSemanaAnterior->format('Y-m-d 00:00:00'),
        ':fin' => $finSemana->format('Y-m-d 00:00:00'),
    ]);
    $comprasDias = [];
    foreach ($stmt->fetchAll() as $fila) {
        $comprasDias[(string) $fila['periodo']] = [
            'importe' => (float) $fila['importe_base'],
            'operaciones' => (int) $fila['operaciones'],
        ];
    }

    $diasCortos = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $serieSemanal = [];
    $totalVentasSemana = 0.0;
    $totalComprasSemana = 0.0;
    $totalVentasSemanaAnterior = 0.0;
    $totalComprasSemanaAnterior = 0.0;

    for ($i = 0; $i < 14; $i++) {
        $fecha = $inicioSemanaAnterior->modify('+' . $i . ' days');
        $clave = $fecha->format('Y-m-d');
        $venta = (float) ($ventasDias[$clave]['importe'] ?? 0);
        $compra = (float) ($comprasDias[$clave]['importe'] ?? 0);

        if ($fecha < $inicioSemana) {
            $totalVentasSemanaAnterior += $venta;
            $totalComprasSemanaAnterior += $compra;
            continue;
        }

        $totalVentasSemana += $venta;
        $totalComprasSemana += $compra;
        $serieSemanal[] = [
            'periodo' => $clave,
            'etiqueta' => $diasCortos[(int) $fecha->format('w')] . ' ' . $fecha->format('d'),
            'ventas' => round($venta, 2),
            'compras' => round($compra, 2),
            'ventas_operaciones' => (int) ($ventasDias[$clave]['operaciones'] ?? 0),
            'compras_operaciones' => (int) ($comprasDias[$clave]['operaciones'] ?? 0),
        ];
    }

    $inicioMeses = $hoy->modify('first day of this month')->modify('-5 months');
    $finMeses = $hoy->modify('first day of next month');

    $stmt = $conexion->prepare(
        "SELECT
            DATE_FORMAT(fecha_venta, '%Y-%m') AS periodo,
            COUNT(*) AS operaciones,
            COALESCE(SUM(total * tipo_cambio_a_base), 0) AS importe_base
         FROM ventas
         WHERE estado = 'CONFIRMADA'
           AND fecha_venta >= :inicio
           AND fecha_venta < :fin
         GROUP BY DATE_FORMAT(fecha_venta, '%Y-%m')"
    );
    $stmt->execute([
        ':inicio' => $inicioMeses->format('Y-m-d 00:00:00'),
        ':fin' => $finMeses->format('Y-m-d 00:00:00'),
    ]);
    $ventasMeses = [];
    foreach ($stmt->fetchAll() as $fila) {
        $ventasMeses[(string) $fila['periodo']] = [
            'importe' => (float) $fila['importe_base'],
            'operaciones' => (int) $fila['operaciones'],
        ];
    }

    $stmt = $conexion->prepare(
        "SELECT
            DATE_FORMAT(fecha_compra, '%Y-%m') AS periodo,
            COUNT(*) AS operaciones,
            COALESCE(SUM(total * tipo_cambio_a_base), 0) AS importe_base
         FROM compras
         WHERE estado IN ('PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL', 'RECIBIDA')
           AND fecha_compra >= :inicio
           AND fecha_compra < :fin
         GROUP BY DATE_FORMAT(fecha_compra, '%Y-%m')"
    );
    $stmt->execute([
        ':inicio' => $inicioMeses->format('Y-m-d 00:00:00'),
        ':fin' => $finMeses->format('Y-m-d 00:00:00'),
    ]);
    $comprasMeses = [];
    foreach ($stmt->fetchAll() as $fila) {
        $comprasMeses[(string) $fila['periodo']] = [
            'importe' => (float) $fila['importe_base'],
            'operaciones' => (int) $fila['operaciones'],
        ];
    }

    $mesesCortos = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];
    $serieMensual = [];

    for ($i = 0; $i < 6; $i++) {
        $fecha = $inicioMeses->modify('+' . $i . ' months');
        $clave = $fecha->format('Y-m');
        $serieMensual[] = [
            'periodo' => $clave,
            'etiqueta' => $mesesCortos[(int) $fecha->format('n')] . ' ' . $fecha->format('y'),
            'ventas' => round((float) ($ventasMeses[$clave]['importe'] ?? 0), 2),
            'compras' => round((float) ($comprasMeses[$clave]['importe'] ?? 0), 2),
            'ventas_operaciones' => (int) ($ventasMeses[$clave]['operaciones'] ?? 0),
            'compras_operaciones' => (int) ($comprasMeses[$clave]['operaciones'] ?? 0),
        ];
    }

    $mesActualClave = $hoy->format('Y-m');
    $mesAnteriorClave = $hoy->modify('-1 month')->format('Y-m');
    $ventasMesActual = (float) ($ventasMeses[$mesActualClave]['importe'] ?? 0);
    $comprasMesActual = (float) ($comprasMeses[$mesActualClave]['importe'] ?? 0);
    $ventasMesAnterior = (float) ($ventasMeses[$mesAnteriorClave]['importe'] ?? 0);
    $comprasMesAnterior = (float) ($comprasMeses[$mesAnteriorClave]['importe'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Índice de merma del mes calculado por costo base histórico.
    | Evita mezclar kg, toneladas, piezas, etc. en un mismo porcentaje.
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            COALESCE(SUM(
                CASE
                    WHEN tmi.codigo = 'MERMA'
                    THEN ABS(mid.cantidad_delta) * COALESCE(mid.costo_unitario_base, 0)
                    ELSE 0
                END
            ), 0) AS costo_merma_base,
            COALESCE(SUM(
                CASE
                    WHEN tmi.codigo IN (
                        'SALIDA_VENTA',
                        'SALIDA_PRODUCCION',
                        'MERMA',
                        'AJUSTE_NEGATIVO',
                        'DEVOLUCION_COMPRA'
                    )
                    AND mid.cantidad_delta < 0
                    THEN ABS(mid.cantidad_delta) * COALESCE(mid.costo_unitario_base, 0)
                    ELSE 0
                END
            ), 0) AS costo_salidas_base
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi
            ON tmi.id = mi.tipo_movimiento_id
         INNER JOIN movimientos_inventario_detalle mid
            ON mid.movimiento_id = mi.id
         WHERE mi.estado = 'APLICADO'
           AND mi.fecha_movimiento >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND mi.fecha_movimiento < DATE_ADD(
                DATE_FORMAT(CURDATE(), '%Y-%m-01'),
                INTERVAL 1 MONTH
           )"
    );

    $merma = $stmt->fetch() ?: [
        'costo_merma_base' => 0,
        'costo_salidas_base' => 0,
    ];

    $costoMermaBase = (float) ($merma['costo_merma_base'] ?? 0);
    $costoSalidasBase = (float) ($merma['costo_salidas_base'] ?? 0);
    $indiceMermaPct = $costoSalidasBase > 0
        ? ($costoMermaBase / $costoSalidasBase) * 100
        : 0.0;

    /*
    |--------------------------------------------------------------------------
    | Auditoría reciente - máximo 12
    |--------------------------------------------------------------------------
    */

    $stmt = $conexion->query(
        "SELECT
            a.fecha_hora,
            a.accion,
            a.modulo,
            a.descripcion,
            COALESCE(
                NULLIF(
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            u.nombres,
                            u.apellido_paterno,
                            u.apellido_materno
                        )
                    ),
                    ''
                ),
                u.usuario,
                'Sistema'
            ) AS usuario
         FROM auditoria a
         LEFT JOIN usuarios u
            ON u.id = a.usuario_id
         ORDER BY a.id DESC
         LIMIT 12"
    );

    $movimientos = $stmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Alertas operativas compartidas
    |--------------------------------------------------------------------------
    | El mismo motor alimenta Dashboard y Topbar.
    |--------------------------------------------------------------------------
    */
    $alertasOperativas = si_alertas_operativas_resumen($conexion);

    si_responder_json(
        true,
        'Dashboard cargado correctamente.',
        [
            'kpis' => [
                'ventas_hoy' => $ventasHoy,
                'compras_por_recibir' => $comprasPorRecibir,
                'stock_critico' => $stockCritico,
                'cobros_vencidos' => $cobrosVencidos,
                'pagos_vencidos' => $pagosVencidos,
                'notificaciones' => $notificaciones,
                'alertas_activas' => (int) ($alertasOperativas['total_sin_leer'] ?? 0),
                'alertas_criticas' => (int) (($alertasOperativas['prioridades_sin_leer']['CRITICA'] ?? 0)),
                'alertas_altas' => (int) (($alertasOperativas['prioridades_sin_leer']['ALTA'] ?? 0)),
            ],
            'ventas_hoy_monedas' => $ventasHoyMonedas,
            'resumen_cobrar' => $resumenCobrar,
            'resumen_pagar' => $resumenPagar,
            'inventario_critico' => $inventarioCritico,
            'top_productos' => $topProductos,
            'top_clientes' => $topClientes,
            'grafica_semanal' => [
                'moneda_base' => $monedaBase,
                'periodo' => $inicioSemana->format('d/m/Y') . ' - ' . $hoy->format('d/m/Y'),
                'serie' => $serieSemanal,
                'totales' => [
                    'ventas' => round($totalVentasSemana, 2),
                    'compras' => round($totalComprasSemana, 2),
                    'ventas_anterior' => round($totalVentasSemanaAnterior, 2),
                    'compras_anterior' => round($totalComprasSemanaAnterior, 2),
                    'variacion_ventas_pct' => $calcularVariacion($totalVentasSemana, $totalVentasSemanaAnterior),
                    'variacion_compras_pct' => $calcularVariacion($totalComprasSemana, $totalComprasSemanaAnterior),
                ],
            ],
            'grafica_mensual' => [
                'moneda_base' => $monedaBase,
                'periodo' => $mesesCortos[(int) $inicioMeses->format('n')] . ' ' . $inicioMeses->format('Y')
                    . ' - ' . $mesesCortos[(int) $hoy->format('n')] . ' ' . $hoy->format('Y'),
                'serie' => $serieMensual,
                'totales' => [
                    'ventas_actual' => round($ventasMesActual, 2),
                    'compras_actual' => round($comprasMesActual, 2),
                    'ventas_anterior' => round($ventasMesAnterior, 2),
                    'compras_anterior' => round($comprasMesAnterior, 2),
                    'variacion_ventas_pct' => $calcularVariacion($ventasMesActual, $ventasMesAnterior),
                    'variacion_compras_pct' => $calcularVariacion($comprasMesActual, $comprasMesAnterior),
                ],
            ],
            'merma_mes' => [
                'costo_merma_base' => $costoMermaBase,
                'costo_salidas_base' => $costoSalidasBase,
                'indice_pct' => $indiceMermaPct,
            ],
            'movimientos_recientes' => $movimientos,
            'alertas_operativas' => $alertasOperativas,
            'fecha_servidor' => date('Y-m-d H:i:s'),
        ]
    );

} catch (Throwable $e) {
    $referencia = 'DASH-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][DASHBOARD] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'No fue posible cargar la información del dashboard.',
        ['referencia' => $referencia],
        500
    );
}
