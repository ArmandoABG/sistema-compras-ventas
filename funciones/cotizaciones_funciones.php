<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

/** @var PDO|null $conexion Conexión creada por inc/conexion.php. */
require_once __DIR__ . '/../inc/tipo_cambio_banxico.php';

si_requerir_permiso('cotizaciones.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_COTIZACIONES')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                cot_catalogos($conexion);
                break;

            case 'LISTAR_COTIZACIONES':
                cot_listar($conexion);
                break;

            case 'DETALLE_COTIZACION':
                cot_detalle($conexion);
                break;

            case 'BUSCAR_CLIENTES':
                cot_buscar_clientes($conexion);
                break;

            case 'BUSCAR_PRODUCTOS':
                cot_buscar_productos($conexion);
                break;

            case 'PRESENTACIONES_PRODUCTO':
                cot_presentaciones_producto($conexion);
                break;

            case 'SUGERIR_PRECIO':
                cot_sugerir_precio($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('cotizaciones.crear')) {
        si_responder_json(
            false,
            'No tienes permiso para crear o modificar cotizaciones.',
            [],
            403
        );
    }

    switch ($accion) {
        case 'GUARDAR_BORRADOR':
            cot_guardar_borrador($conexion);
            break;

        case 'GENERAR_COTIZACION':
            cot_generar($conexion);
            break;

        case 'ACEPTAR_COTIZACION':
            cot_aceptar($conexion);
            break;

        case 'RECHAZAR_COTIZACION':
            cot_rechazar($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'COT-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][COTIZACIONES][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'No fue posible guardar porque existe un dato duplicado o una relación inválida.',
            ['referencia' => $referencia],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible procesar la operación de cotizaciones.',
        ['referencia' => $referencia],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'COT-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][COTIZACIONES] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar cotizaciones.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS Y BÚSQUEDAS
   ========================================================================= */

function cot_catalogos(PDO $conexion): void
{
    cot_marcar_vencidas($conexion);

    $monedas = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, es_base
         FROM monedas
         WHERE activo = 1
         ORDER BY es_base DESC, codigo ASC"
    )->fetchAll();

    foreach ($monedas as &$moneda) {
        $moneda['id'] = (int) $moneda['id'];
        $moneda['es_base'] = (int) $moneda['es_base'];
    }
    unset($moneda);

    $empresa = $conexion->query(
        "SELECT valor_texto
         FROM configuracion_sistema
         WHERE clave = 'empresa.nombre'
         LIMIT 1"
    )->fetchColumn();

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'monedas' => $monedas,
            'empresa' => $empresa ?: 'Sistema Integral',
            'fecha_hoy' => date('Y-m-d'),
            'vigencia_sugerida' => date('Y-m-d', strtotime('+7 days')),
        ]
    );
}

function cot_buscar_clientes(PDO $conexion): void
{
    $q = cot_texto($_GET['q'] ?? '', 180);

    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['clientes' => []]);
    }

    $like = '%' . $q . '%';

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.codigo,
            c.nombre_razon_social,
            c.rfc,
            c.nivel_cliente_id,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct,
            c.descuento_personal_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct,
            CASE
                WHEN c.descuento_personal_pct IS NOT NULL THEN 'ESPECIAL'
                WHEN n.id IS NOT NULL THEN 'NIVEL'
                ELSE 'SIN_DESCUENTO'
            END AS origen_descuento,
            c.dias_credito,
            c.limite_credito
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE 1=1
           AND c.activo = 1
           AND (
                c.codigo LIKE :q_codigo
                OR c.nombre_razon_social LIKE :q_nombre
                OR c.rfc LIKE :q_rfc
                OR c.telefono LIKE :q_telefono
           )
         ORDER BY
            CASE WHEN c.codigo = :q_exacta THEN 0 ELSE 1 END,
            c.nombre_razon_social ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':q_codigo' => $like,
        ':q_nombre' => $like,
        ':q_rfc' => $like,
        ':q_telefono' => $like,
        ':q_exacta' => strtoupper($q),
    ]);

    $clientes = $stmt->fetchAll();

    foreach ($clientes as &$c) {
        $c['id'] = (int) $c['id'];
        $c['nivel_cliente_id'] = $c['nivel_cliente_id'] !== null ? (int) $c['nivel_cliente_id'] : null;
        $c['descuento_default_pct'] = $c['descuento_default_pct'] !== null ? (float) $c['descuento_default_pct'] : 0.0;
        $c['descuento_personal_pct'] = $c['descuento_personal_pct'] !== null ? (float) $c['descuento_personal_pct'] : null;
        $c['descuento_efectivo_pct'] = (float) $c['descuento_efectivo_pct'];
        $c['dias_credito'] = (int) $c['dias_credito'];
        $c['limite_credito'] = $c['limite_credito'] !== null ? (float) $c['limite_credito'] : null;
    }
    unset($c);

    si_responder_json(true, 'Clientes cargados.', ['clientes' => $clientes]);
}

function cot_buscar_productos(PDO $conexion): void
{
    $q = cot_texto($_GET['q'] ?? '', 180);

    if (mb_strlen($q) < 2) {
        si_responder_json(true, 'Escribe al menos dos caracteres.', ['productos' => []]);
    }

    $like = '%' . $q . '%';

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.tasa_impuesto_id,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE((
                SELECT SUM(ea.cantidad_disponible)
                FROM existencias_almacen ea
                WHERE ea.producto_id = p.id
            ), 0) AS disponible_base
         FROM productos p
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         WHERE 1=1
           AND p.activo = 1
           AND (
                p.sku LIKE :q_sku
                OR p.nombre LIKE :q_nombre
           )
         ORDER BY
            CASE WHEN p.sku = :q_exacta THEN 0 ELSE 1 END,
            p.nombre ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':q_sku' => $like,
        ':q_nombre' => $like,
        ':q_exacta' => strtoupper($q),
    ]);

    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_base_id'] = (int) $p['unidad_base_id'];
        $p['tasa_impuesto_id'] = $p['tasa_impuesto_id'] !== null ? (int) $p['tasa_impuesto_id'] : null;
        $p['impuesto_pct'] = (float) $p['impuesto_pct'];
        $p['disponible_base'] = (float) $p['disponible_base'];
    }
    unset($p);

    si_responder_json(true, 'Productos cargados.', ['productos' => $productos]);
}

function cot_presentaciones_producto(PDO $conexion): void
{
    $productoId = cot_id($_GET['producto_id'] ?? null, 'producto');

    $producto = cot_producto($conexion, $productoId);

    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.unidad_id,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            pp.factor_a_unidad_base
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida u ON u.id = pp.unidad_id
         WHERE pp.producto_id = :producto_id
           AND pp.es_venta = 1
           AND pp.activo = 1
           AND u.activo = 1
         ORDER BY pp.factor_a_unidad_base ASC, pp.nombre ASC"
    );
    $stmt->execute([':producto_id' => $productoId]);
    $presentaciones = $stmt->fetchAll();

    foreach ($presentaciones as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_id'] = (int) $p['unidad_id'];
        $p['factor_a_unidad_base'] = (float) $p['factor_a_unidad_base'];
        $p['es_unidad_base_virtual'] = 0;
    }
    unset($p);

    /*
     * La opción de unidad base siempre se ofrece explícitamente porque en
     * Productos / Catálogos se puede configurar un precio con
     * presentacion_id = NULL. Una presentación física con factor 1 puede tener
     * una regla comercial distinta y no debe ocultar el precio de unidad base.
     */
    array_unshift(
        $presentaciones,
        [
            'id' => 0,
            'nombre' => 'Unidad base (' . $producto['unidad_base_codigo'] . ')',
            'unidad_id' => (int) $producto['unidad_base_id'],
            'unidad_codigo' => (string) $producto['unidad_base_codigo'],
            'unidad_nombre' => (string) $producto['unidad_base_nombre'],
            'unidad_simbolo' => (string) $producto['unidad_base_simbolo'],
            'factor_a_unidad_base' => 1.0,
            'es_unidad_base_virtual' => 1,
        ]
    );

    si_responder_json(
        true,
        'Presentaciones cargadas.',
        [
            'producto' => [
                'id' => (int) $producto['id'],
                'sku' => $producto['sku'],
                'nombre' => $producto['nombre'],
                'unidad_base_id' => (int) $producto['unidad_base_id'],
                'unidad_base_codigo' => $producto['unidad_base_codigo'],
                'unidad_base_nombre' => $producto['unidad_base_nombre'],
                'unidad_base_simbolo' => $producto['unidad_base_simbolo'],
                'tasa_impuesto_id' => $producto['tasa_impuesto_id'] !== null ? (int) $producto['tasa_impuesto_id'] : null,
                'impuesto_pct' => (float) $producto['impuesto_pct'],
                'impuesto_nombre' => $producto['impuesto_nombre'],
            ],
            'presentaciones' => $presentaciones,
        ]
    );
}

function cot_sugerir_precio(PDO $conexion): void
{
    $productoId = cot_id($_GET['producto_id'] ?? null, 'producto');
    $presentacionId = cot_entero_rango($_GET['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $monedaId = cot_id($_GET['moneda_id'] ?? null, 'moneda');
    $cantidad = cot_decimal($_GET['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0);

    $producto = cot_producto($conexion, $productoId);

    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $impuestoId = $producto['tasa_impuesto_id'] !== null ? (int) $producto['tasa_impuesto_id'] : null;
    $impuestoPct = (float) $producto['impuesto_pct'];
    $impuestoNombre = (string) $producto['impuesto_nombre'];

    if ($presentacionId > 0) {
        $stmtPres = $conexion->prepare(
            "SELECT id
             FROM presentaciones_producto
             WHERE id = :id
               AND producto_id = :producto_id
               AND es_venta = 1
               AND activo = 1"
        );
        $stmtPres->execute([
            ':id' => $presentacionId,
            ':producto_id' => $productoId,
        ]);

        if (!$stmtPres->fetchColumn()) {
            si_responder_json(false, 'La presentación seleccionada no es válida para venta.', [], 422);
        }
    }

    $condicionPresentacion = $presentacionId > 0
        ? 'pv.presentacion_id = :presentacion_id'
        : 'pv.presentacion_id IS NULL';

    $stmt = $conexion->prepare(
        "SELECT
            pv.id,
            pv.nivel_precio,
            pv.cantidad_minima,
            pv.moneda_id,
            pv.precio_unitario,
            pv.tasa_impuesto_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            COALESCE(ti.porcentaje, tip.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, tip.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE(pv.tasa_impuesto_id, p.tasa_impuesto_id) AS impuesto_id_efectivo
         FROM precios_venta_producto pv
         INNER JOIN productos p ON p.id = pv.producto_id
         INNER JOIN monedas m ON m.id = pv.moneda_id AND m.activo = 1
         LEFT JOIN tasas_impuesto ti ON ti.id = pv.tasa_impuesto_id
         LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
         WHERE pv.producto_id = :producto_id
           AND {$condicionPresentacion}
           AND pv.activo = 1
           AND (pv.nivel_precio = 'MENUDEO' OR pv.cantidad_minima <= :cantidad)
           AND pv.vigente_desde <= NOW()
           AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
         ORDER BY
            CASE pv.nivel_precio WHEN 'MAYOREO' THEN 0 ELSE 1 END,
            CASE WHEN pv.nivel_precio = 'MAYOREO' THEN pv.cantidad_minima ELSE 1 END DESC,
            pv.id DESC"
    );

    $params = [
        ':producto_id' => $productoId,
        ':cantidad' => $cantidad,
    ];

    if ($presentacionId > 0) {
        $params[':presentacion_id'] = $presentacionId;
    }

    $stmt->execute($params);
    $candidatos = $stmt->fetchAll();

    if (!$candidatos) {
        si_responder_json(
            true,
            $presentacionId > 0
                ? 'No hay precio de venta vigente para esta presentación. Captura el precio manualmente o configúralo en Productos / Catálogos → Precios de venta.'
                : 'No hay precio vigente para la unidad base. Captura el precio manualmente o configúralo en Productos / Catálogos → Precios de venta.',
            [
                'precio' => null,
                'precio_venta_id' => 0,
                'nivel_precio' => 'MANUAL',
                'origen' => 'MANUAL',
                'tasa_impuesto_id' => $impuestoId,
                'impuesto_pct' => $impuestoPct,
                'impuesto_nombre' => $impuestoNombre,
            ]
        );
    }

    usort(
        $candidatos,
        static function (array $a, array $b) use ($monedaId): int {
            /*
             * La regla comercial se resuelve por nivel y después por escalón:
             * 1) si existe un MAYOREO cuyo mínimo ya se alcanzó, gana sobre
             *    MENUDEO;
             * 2) entre varios mayoreos, gana el mínimo más alto alcanzado;
             * 3) dentro del mismo escalón se prefiere la moneda de la cotización;
             * 4) al final gana la vigencia más reciente (id mayor).
             *
             * MENUDEO se considera aplicable desde 1 aunque exista un registro
             * legado con cantidad_minima distinta. Esto hace compatible la
             * corrección con datos capturados antes de esta revisión.
             */
            $aMayoreo = (string) $a['nivel_precio'] === 'MAYOREO' ? 1 : 0;
            $bMayoreo = (string) $b['nivel_precio'] === 'MAYOREO' ? 1 : 0;

            if ($aMayoreo !== $bMayoreo) {
                return $bMayoreo <=> $aMayoreo;
            }

            if ($aMayoreo === 1) {
                $cmpCantidad = (float) $b['cantidad_minima'] <=> (float) $a['cantidad_minima'];

                if ($cmpCantidad !== 0) {
                    return $cmpCantidad;
                }
            }

            $aMisma = (int) $a['moneda_id'] === $monedaId ? 1 : 0;
            $bMisma = (int) $b['moneda_id'] === $monedaId ? 1 : 0;

            if ($aMisma !== $bMisma) {
                return $bMisma <=> $aMisma;
            }

            return (int) $b['id'] <=> (int) $a['id'];
        }
    );

    $elegido = $candidatos[0];
    $fecha = date('Y-m-d');

    $origenABase = cot_tipo_cambio_a_base($conexion, (int) $elegido['moneda_id'], $fecha);
    $destinoABase = cot_tipo_cambio_a_base($conexion, $monedaId, $fecha);

    if ($origenABase === null || $destinoABase === null || $destinoABase <= 0) {
        si_responder_json(
            true,
            'Existe un precio configurado, pero falta un tipo de cambio para expresarlo en la moneda de la cotización.',
            [
                'precio' => null,
                'precio_venta_id' => 0,
                'nivel_precio' => 'MANUAL',
                'origen' => 'MANUAL',
                'tasa_impuesto_id' => $impuestoId,
                'impuesto_pct' => $impuestoPct,
                'impuesto_nombre' => $impuestoNombre,
            ]
        );
    }

    $precioConvertido = ((float) $elegido['precio_unitario'] * $origenABase) / $destinoABase;

    si_responder_json(
        true,
        'Precio sugerido automáticamente.',
        [
            'precio' => round($precioConvertido, 4),
            'precio_venta_id' => (int) $elegido['id'],
            'nivel_precio' => (string) $elegido['nivel_precio'],
            'origen' => (int) $elegido['moneda_id'] === $monedaId ? 'CONFIGURADO' : 'CONVERTIDO',
            'moneda_origen' => (string) $elegido['moneda_codigo'],
            'precio_origen' => (float) $elegido['precio_unitario'],
            'cantidad_minima' => (string) $elegido['nivel_precio'] === 'MENUDEO' ? 1.0 : (float) $elegido['cantidad_minima'],
            'tasa_impuesto_id' => $elegido['impuesto_id_efectivo'] !== null ? (int) $elegido['impuesto_id_efectivo'] : null,
            'impuesto_pct' => (float) $elegido['impuesto_pct'],
            'impuesto_nombre' => (string) $elegido['impuesto_nombre'],
        ]
    );
}

/* =========================================================================
   LISTADO Y DETALLE
   ========================================================================= */

function cot_listar(PDO $conexion): void
{
    cot_marcar_vencidas($conexion);

    $pagina = cot_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cot_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cot_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(cot_texto($_GET['estado'] ?? 'TODOS', 30));
    $desde = cot_fecha_opcional($_GET['desde'] ?? null);
    $hasta = cot_fecha_opcional($_GET['hasta'] ?? null);

    $estados = ['TODOS', 'BORRADOR', 'GENERADA', 'ACEPTADA', 'RECHAZADA', 'VENCIDA', 'CONVERTIDA'];
    if (!in_array($estado, $estados, true)) {
        $estado = 'TODOS';
    }

    $where = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            c.folio LIKE :q_folio
            OR c.cliente_nombre_snapshot LIKE :q_cliente
            OR cl.codigo LIKE :q_codigo_cliente
        )";
        $params[':q_folio'] = $like;
        $params[':q_cliente'] = $like;
        $params[':q_codigo_cliente'] = $like;
    }

    if ($estado !== 'TODOS') {
        $where[] = 'c.estado = :estado';
        $params[':estado'] = $estado;
    }

    if ($desde !== null) {
        $where[] = 'DATE(c.fecha_cotizacion) >= :desde';
        $params[':desde'] = $desde;
    }

    if ($hasta !== null) {
        $where[] = 'DATE(c.fecha_cotizacion) <= :hasta';
        $params[':hasta'] = $hasta;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $from = "FROM cotizaciones c
             LEFT JOIN clientes cl ON cl.id = c.cliente_id
             INNER JOIN monedas m ON m.id = c.moneda_id
             LEFT JOIN usuarios u ON u.id = c.created_by";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    cot_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.folio,
            c.cliente_id,
            c.cliente_nombre_snapshot,
            cl.codigo AS cliente_codigo,
            c.fecha_cotizacion,
            c.vigencia_hasta,
            c.estado,
            c.moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            c.tipo_cambio_a_base,
            c.subtotal,
            c.descuento_total,
            c.impuesto_total,
            c.total,
            c.observaciones,
            u.usuario AS creado_por,
            (
                SELECT COUNT(*)
                FROM cotizaciones_detalle cd
                WHERE cd.cotizacion_id = c.id
            ) AS renglones,
            (
                SELECT MIN(v.folio)
                FROM ventas v
                WHERE v.cotizacion_id = c.id
            ) AS venta_folio,
            (
                SELECT MIN(a.folio)
                FROM apartados a
                WHERE a.cotizacion_id = c.id
            ) AS apartado_folio
         {$from}
         {$whereSql}
         ORDER BY c.fecha_cotizacion DESC, c.id DESC
         LIMIT :limite OFFSET :offset"
    );

    cot_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $cotizaciones = $stmt->fetchAll();

    foreach ($cotizaciones as &$c) {
        cot_tipar_cotizacion($c);
    }
    unset($c);

    $kpis = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(estado = 'BORRADOR') AS borradores,
            SUM(estado = 'GENERADA') AS generadas,
            SUM(estado = 'ACEPTADA') AS aceptadas,
            SUM(estado = 'VENCIDA') AS vencidas
         FROM cotizaciones"
    )->fetch();

    foreach ($kpis as $clave => $valor) {
        $kpis[$clave] = (int) ($valor ?? 0);
    }

    si_responder_json(
        true,
        'Cotizaciones cargadas.',
        [
            'cotizaciones' => $cotizaciones,
            'kpis' => $kpis,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cot_detalle(PDO $conexion): void
{
    cot_marcar_vencidas($conexion);

    $id = cot_id($_GET['cotizacion_id'] ?? null, 'cotización');

    $stmt = $conexion->prepare(
        "SELECT
            c.*,
            cl.codigo AS cliente_codigo,
            cl.rfc AS cliente_rfc_actual,
            cl.nivel_cliente_id,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct,
            cl.descuento_personal_pct,
            COALESCE(cl.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_actual_cliente,
            m.codigo AS moneda_codigo,
            m.nombre AS moneda_nombre,
            m.simbolo AS moneda_simbolo,
            u.usuario AS creado_por,
            (
                SELECT MIN(v.folio)
                FROM ventas v
                WHERE v.cotizacion_id = c.id
            ) AS venta_folio,
            (
                SELECT MIN(a.folio)
                FROM apartados a
                WHERE a.cotizacion_id = c.id
            ) AS apartado_folio
         FROM cotizaciones c
         LEFT JOIN clientes cl ON cl.id = c.cliente_id
         LEFT JOIN niveles_cliente n ON n.id = cl.nivel_cliente_id
         INNER JOIN monedas m ON m.id = c.moneda_id
         LEFT JOIN usuarios u ON u.id = c.created_by
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);

    $cotizacion = $stmt->fetch();

    if (!$cotizacion) {
        si_responder_json(false, 'La cotización ya no existe.', [], 404);
    }

    cot_tipar_cotizacion($cotizacion);
    $cotizacion['nivel_cliente_id'] = $cotizacion['nivel_cliente_id'] !== null ? (int) $cotizacion['nivel_cliente_id'] : null;
    $cotizacion['descuento_default_pct'] = $cotizacion['descuento_default_pct'] !== null ? (float) $cotizacion['descuento_default_pct'] : 0.0;
    $cotizacion['descuento_personal_pct'] = $cotizacion['descuento_personal_pct'] !== null ? (float) $cotizacion['descuento_personal_pct'] : null;
    $cotizacion['descuento_actual_cliente'] = (float) $cotizacion['descuento_actual_cliente'];

    $stmtDet = $conexion->prepare(
        "SELECT
            cd.id,
            cd.renglon,
            cd.producto_id,
            cd.presentacion_id,
            cd.producto_nombre_snapshot,
            p.sku,
            cd.unidad_id,
            cd.unidad_nombre_snapshot,
            u.codigo AS unidad_codigo,
            u.simbolo AS unidad_simbolo,
            ub.codigo AS unidad_base_codigo,
            ub.simbolo AS unidad_base_simbolo,
            cd.cantidad,
            cd.factor_a_unidad_base,
            cd.cantidad_base,
            cd.precio_unitario,
            cd.descuento_pct,
            cd.descuento_importe,
            cd.tasa_impuesto_id,
            cd.impuesto_pct_snapshot,
            cd.subtotal,
            cd.impuesto_importe,
            cd.total,
            pp.nombre AS presentacion_nombre,
            COALESCE(ea.disponible_actual, 0) AS disponible_base_actual
         FROM cotizaciones_detalle cd
         INNER JOIN productos p ON p.id = cd.producto_id
         INNER JOIN unidades_medida u ON u.id = cd.unidad_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = cd.presentacion_id
         LEFT JOIN (
            SELECT producto_id, SUM(cantidad_disponible) AS disponible_actual
            FROM existencias_almacen
            GROUP BY producto_id
         ) ea ON ea.producto_id = cd.producto_id
         WHERE cd.cotizacion_id = :id
         ORDER BY cd.renglon ASC"
    );
    $stmtDet->execute([':id' => $id]);
    $detalles = $stmtDet->fetchAll();

    foreach ($detalles as &$d) {
        $d['id'] = (int) $d['id'];
        $d['renglon'] = (int) $d['renglon'];
        $d['producto_id'] = (int) $d['producto_id'];
        $d['presentacion_id'] = $d['presentacion_id'] !== null ? (int) $d['presentacion_id'] : 0;
        $d['unidad_id'] = (int) $d['unidad_id'];

        foreach ([
            'cantidad',
            'factor_a_unidad_base',
            'cantidad_base',
            'precio_unitario',
            'descuento_pct',
            'descuento_importe',
            'impuesto_pct_snapshot',
            'subtotal',
            'impuesto_importe',
            'total',
            'disponible_base_actual',
        ] as $campo) {
            $d[$campo] = (float) $d[$campo];
        }

        $d['tasa_impuesto_id'] = $d['tasa_impuesto_id'] !== null ? (int) $d['tasa_impuesto_id'] : null;
    }
    unset($d);

    si_responder_json(
        true,
        'Detalle cargado.',
        [
            'cotizacion' => $cotizacion,
            'detalles' => $detalles,
        ]
    );
}

/* =========================================================================
   GUARDADO Y FLUJO
   ========================================================================= */

function cot_guardar_borrador(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['cotizacion_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cot_id($idTexto, 'cotización');
    $esNuevo = $id === 0;

    $clienteId = cot_id($_POST['cliente_id'] ?? null, 'cliente');
    $monedaId = cot_id($_POST['moneda_id'] ?? null, 'moneda');
    $vigencia = cot_fecha_requerida($_POST['vigencia_hasta'] ?? null, 'vigencia');
    $observaciones = cot_nullable($_POST['observaciones'] ?? '', 5000);

    $lineasJson = (string) ($_POST['lineas'] ?? '');
    $lineasEntrada = json_decode($lineasJson, true);

    if (!is_array($lineasEntrada) || count($lineasEntrada) < 1) {
        si_responder_json(false, 'Agrega al menos un producto a la cotización.', ['campo' => 'lineas'], 422);
    }

    if (count($lineasEntrada) > 200) {
        si_responder_json(false, 'Una cotización no puede contener más de 200 renglones.', [], 422);
    }

    /*
     * Validamos los datos básicos antes de abrir la transacción.
     * Así una captura incorrecta nunca deja una transacción abierta.
     */
    $lineasNormalizadas = [];

    foreach ($lineasEntrada as $entrada) {
        if (!is_array($entrada)) {
            si_responder_json(false, 'Uno de los renglones no es válido.', [], 422);
        }

        $lineasNormalizadas[] = [
            'producto_id' => cot_id($entrada['producto_id'] ?? null, 'producto'),
            'presentacion_id' => cot_entero_rango($entrada['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0),
            'cantidad' => cot_decimal($entrada['cantidad'] ?? null, 'cantidad', 0.000001, 999999999999.0),
            'precio_unitario' => cot_decimal($entrada['precio_unitario'] ?? null, 'precio unitario', 0.0001, 999999999999.0),
            'precio_venta_id' => cot_entero_rango($entrada['precio_venta_id'] ?? 0, 0, PHP_INT_MAX, 0),
        ];
    }

    $conexion->beginTransaction();

    $anterior = null;
    $fechaCotizacion = date('Y-m-d H:i:s');

    if (!$esNuevo) {
        $stmtLock = $conexion->prepare(
            "SELECT *
             FROM cotizaciones
             WHERE id = :id
             FOR UPDATE"
        );
        $stmtLock->execute([':id' => $id]);
        $anterior = $stmtLock->fetch();

        if (!$anterior) {
            cot_cancelar($conexion, 'La cotización ya no existe.', 404);
        }

        if ((string) $anterior['estado'] !== 'BORRADOR') {
            cot_cancelar(
                $conexion,
                'Solo las cotizaciones en borrador pueden modificarse.',
                409
            );
        }

        $fechaCotizacion = (string) $anterior['fecha_cotizacion'];
    }

    $cliente = cot_cliente_activo($conexion, $clienteId);

    if (!$cliente) {
        cot_cancelar(
            $conexion,
            'Selecciona un cliente activo.',
            422,
            ['campo' => 'cliente_id']
        );
    }

    $moneda = cot_moneda_activa($conexion, $monedaId);

    if (!$moneda) {
        cot_cancelar(
            $conexion,
            'Selecciona una moneda activa.',
            422,
            ['campo' => 'moneda_id']
        );
    }

    $fechaBase = substr($fechaCotizacion, 0, 10);
    $tipoCambio = cot_tipo_cambio_a_base($conexion, $monedaId, $fechaBase);

    if ($tipoCambio === null || $tipoCambio <= 0) {
        cot_cancelar(
            $conexion,
            'No existe un tipo de cambio válido para la moneda seleccionada.',
            422,
            ['campo' => 'moneda_id']
        );
    }

    $descuentoCliente = (float) $cliente['descuento_efectivo_pct'];

    if ($descuentoCliente < 0 || $descuentoCliente > 100) {
        cot_cancelar(
            $conexion,
            'El descuento configurado para el cliente no es válido.',
            409
        );
    }

    $detalles = [];
    $claves = [];
    $subtotalHeader = 0.0;
    $descuentoHeader = 0.0;
    $impuestoHeader = 0.0;
    $totalHeader = 0.0;

    foreach ($lineasNormalizadas as $indice => $entrada) {
        $productoId = (int) $entrada['producto_id'];
        $presentacionId = (int) $entrada['presentacion_id'];
        $cantidad = (float) $entrada['cantidad'];
        $precio = (float) $entrada['precio_unitario'];
        $precioVentaId = (int) $entrada['precio_venta_id'];

        $clave = $productoId . ':' . $presentacionId;
        if (isset($claves[$clave])) {
            cot_cancelar(
                $conexion,
                'No repitas el mismo producto con la misma presentación. Modifica la cantidad del renglón existente.',
                422
            );
        }
        $claves[$clave] = true;

        $producto = cot_producto($conexion, $productoId);

        if (!$producto) {
            cot_cancelar($conexion, 'Uno de los productos ya no está disponible.', 409);
        }

        if ($presentacionId > 0) {
            $stmtPres = $conexion->prepare(
                "SELECT
                    pp.id,
                    pp.unidad_id,
                    pp.nombre,
                    pp.factor_a_unidad_base,
                    u.nombre AS unidad_nombre
                 FROM presentaciones_producto pp
                 INNER JOIN unidades_medida u ON u.id = pp.unidad_id
                 WHERE pp.id = :id
                   AND pp.producto_id = :producto_id
                   AND pp.es_venta = 1
                   AND pp.activo = 1
                   AND u.activo = 1
                 LIMIT 1"
            );
            $stmtPres->execute([
                ':id' => $presentacionId,
                ':producto_id' => $productoId,
            ]);
            $presentacion = $stmtPres->fetch();

            if (!$presentacion) {
                cot_cancelar($conexion, 'Una presentación seleccionada ya no está disponible.', 409);
            }

            $unidadId = (int) $presentacion['unidad_id'];
            $unidadNombre = (string) $presentacion['unidad_nombre'];
            $factor = (float) $presentacion['factor_a_unidad_base'];

        } else {
            $unidadId = (int) $producto['unidad_base_id'];
            $unidadNombre = (string) $producto['unidad_base_nombre'];
            $factor = 1.0;
        }

        if ($factor <= 0) {
            cot_cancelar($conexion, 'La conversión de una presentación no es válida.', 409);
        }

        $cantidadBase = round($cantidad * $factor, 6);

        $tasaId = $producto['tasa_impuesto_id'] !== null ? (int) $producto['tasa_impuesto_id'] : null;
        $impuestoPct = (float) $producto['impuesto_pct'];

        if ($precioVentaId > 0) {
            $condicionPrecioPresentacion = $presentacionId > 0
                ? 'pv.presentacion_id = :presentacion_id'
                : 'pv.presentacion_id IS NULL';

            $stmtPrecio = $conexion->prepare(
                "SELECT
                    pv.id,
                    COALESCE(pv.tasa_impuesto_id, p.tasa_impuesto_id) AS tasa_id,
                    COALESCE(ti.porcentaje, tip.porcentaje, 0) AS impuesto_pct
                 FROM precios_venta_producto pv
                 INNER JOIN productos p ON p.id = pv.producto_id
                 LEFT JOIN tasas_impuesto ti ON ti.id = pv.tasa_impuesto_id
                 LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
                 WHERE pv.id = :id
                   AND pv.producto_id = :producto_id
                   AND {$condicionPrecioPresentacion}
                   AND pv.activo = 1
                   AND (pv.nivel_precio = 'MENUDEO' OR pv.cantidad_minima <= :cantidad)
                   AND pv.vigente_desde <= NOW()
                   AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
                 LIMIT 1"
            );

            $paramsPrecio = [
                ':id' => $precioVentaId,
                ':producto_id' => $productoId,
                ':cantidad' => $cantidad,
            ];

            if ($presentacionId > 0) {
                $paramsPrecio[':presentacion_id'] = $presentacionId;
            }

            $stmtPrecio->execute($paramsPrecio);
            $precioConfigurado = $stmtPrecio->fetch();

            if ($precioConfigurado) {
                $tasaId = $precioConfigurado['tasa_id'] !== null ? (int) $precioConfigurado['tasa_id'] : null;
                $impuestoPct = (float) $precioConfigurado['impuesto_pct'];
            } else {
                $precioVentaId = 0;
            }
        }

        $importeBruto = round($cantidad * $precio, 4);
        $descuentoImporte = round($importeBruto * ($descuentoCliente / 100), 4);
        $subtotal = round($importeBruto - $descuentoImporte, 4);
        $impuestoImporte = round($subtotal * ($impuestoPct / 100), 4);
        $total = round($subtotal + $impuestoImporte, 4);

        $subtotalHeader += $subtotal;
        $descuentoHeader += $descuentoImporte;
        $impuestoHeader += $impuestoImporte;
        $totalHeader += $total;

        $detalles[] = [
            'renglon' => $indice + 1,
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId > 0 ? $presentacionId : null,
            'producto_nombre_snapshot' => (string) $producto['nombre'],
            'unidad_id' => $unidadId,
            'unidad_nombre_snapshot' => $unidadNombre,
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $factor,
            'cantidad_base' => $cantidadBase,
            'precio_unitario' => $precio,
            'descuento_pct' => $descuentoCliente,
            'descuento_importe' => $descuentoImporte,
            'tasa_impuesto_id' => $tasaId,
            'impuesto_pct_snapshot' => $impuestoPct,
            'subtotal' => $subtotal,
            'impuesto_importe' => $impuestoImporte,
            'total' => $total,
        ];
    }

    $subtotalHeader = round($subtotalHeader, 4);
    $descuentoHeader = round($descuentoHeader, 4);
    $impuestoHeader = round($impuestoHeader, 4);
    $totalHeader = round($totalHeader, 4);

    $vigenciaHasta = $vigencia . ' 23:59:59';

    if (strtotime($vigenciaHasta) <= strtotime($fechaCotizacion)) {
        cot_cancelar(
            $conexion,
            'La vigencia debe ser posterior a la fecha de la cotización.',
            422,
            ['campo' => 'vigencia_hasta']
        );
    }

    if ($esNuevo) {
        $folioTmp = 'TMP-COT-' . bin2hex(random_bytes(10));

        $stmt = $conexion->prepare(
            "INSERT INTO cotizaciones
                (
                    folio,
                    cliente_id,
                    cliente_nombre_snapshot,
                    fecha_cotizacion,
                    vigencia_hasta,
                    moneda_id,
                    tipo_cambio_a_base,
                    estado,
                    subtotal,
                    descuento_total,
                    impuesto_total,
                    total,
                    observaciones,
                    created_by
                )
             VALUES
                (
                    :folio,
                    :cliente_id,
                    :cliente_nombre,
                    NOW(),
                    :vigencia,
                    :moneda_id,
                    :tipo_cambio,
                    'BORRADOR',
                    :subtotal,
                    :descuento,
                    :impuesto,
                    :total,
                    :observaciones,
                    :created_by
                )"
        );

        $stmt->execute([
            ':folio' => $folioTmp,
            ':cliente_id' => $clienteId,
            ':cliente_nombre' => $cliente['nombre_razon_social'],
            ':vigencia' => $vigenciaHasta,
            ':moneda_id' => $monedaId,
            ':tipo_cambio' => $tipoCambio,
            ':subtotal' => $subtotalHeader,
            ':descuento' => $descuentoHeader,
            ':impuesto' => $impuestoHeader,
            ':total' => $totalHeader,
            ':observaciones' => $observaciones,
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);

        $id = (int) $conexion->lastInsertId();
        $folio = 'COT-' . str_pad((string) $id, 7, '0', STR_PAD_LEFT);

        $conexion->prepare(
            "UPDATE cotizaciones
             SET folio = :folio
             WHERE id = :id"
        )->execute([
            ':folio' => $folio,
            ':id' => $id,
        ]);

    } else {
        $folio = (string) $anterior['folio'];

        $stmt = $conexion->prepare(
            "UPDATE cotizaciones
             SET
                cliente_id = :cliente_id,
                cliente_nombre_snapshot = :cliente_nombre,
                vigencia_hasta = :vigencia,
                moneda_id = :moneda_id,
                tipo_cambio_a_base = :tipo_cambio,
                subtotal = :subtotal,
                descuento_total = :descuento,
                impuesto_total = :impuesto,
                total = :total,
                observaciones = :observaciones
             WHERE id = :id"
        );

        $stmt->execute([
            ':cliente_id' => $clienteId,
            ':cliente_nombre' => $cliente['nombre_razon_social'],
            ':vigencia' => $vigenciaHasta,
            ':moneda_id' => $monedaId,
            ':tipo_cambio' => $tipoCambio,
            ':subtotal' => $subtotalHeader,
            ':descuento' => $descuentoHeader,
            ':impuesto' => $impuestoHeader,
            ':total' => $totalHeader,
            ':observaciones' => $observaciones,
            ':id' => $id,
        ]);

        $conexion->prepare(
            "DELETE FROM cotizaciones_detalle
             WHERE cotizacion_id = :id"
        )->execute([':id' => $id]);
    }

    $stmtDetalle = $conexion->prepare(
        "INSERT INTO cotizaciones_detalle
            (
                cotizacion_id,
                renglon,
                producto_id,
                presentacion_id,
                producto_nombre_snapshot,
                unidad_id,
                unidad_nombre_snapshot,
                cantidad,
                factor_a_unidad_base,
                cantidad_base,
                precio_unitario,
                descuento_pct,
                descuento_importe,
                tasa_impuesto_id,
                impuesto_pct_snapshot,
                subtotal,
                impuesto_importe,
                total
            )
         VALUES
            (
                :cotizacion_id,
                :renglon,
                :producto_id,
                :presentacion_id,
                :producto_nombre,
                :unidad_id,
                :unidad_nombre,
                :cantidad,
                :factor,
                :cantidad_base,
                :precio,
                :descuento_pct,
                :descuento_importe,
                :tasa_id,
                :impuesto_pct,
                :subtotal,
                :impuesto_importe,
                :total
            )"
    );

    foreach ($detalles as $d) {
        $stmtDetalle->execute([
            ':cotizacion_id' => $id,
            ':renglon' => $d['renglon'],
            ':producto_id' => $d['producto_id'],
            ':presentacion_id' => $d['presentacion_id'],
            ':producto_nombre' => $d['producto_nombre_snapshot'],
            ':unidad_id' => $d['unidad_id'],
            ':unidad_nombre' => $d['unidad_nombre_snapshot'],
            ':cantidad' => $d['cantidad'],
            ':factor' => $d['factor_a_unidad_base'],
            ':cantidad_base' => $d['cantidad_base'],
            ':precio' => $d['precio_unitario'],
            ':descuento_pct' => $d['descuento_pct'],
            ':descuento_importe' => $d['descuento_importe'],
            ':tasa_id' => $d['tasa_impuesto_id'],
            ':impuesto_pct' => $d['impuesto_pct_snapshot'],
            ':subtotal' => $d['subtotal'],
            ':impuesto_importe' => $d['impuesto_importe'],
            ':total' => $d['total'],
        ]);
    }

    cot_auditar(
        $conexion,
        $esNuevo ? 'COTIZACION_CREADA' : 'COTIZACION_EDITADA',
        'cotizaciones',
        $id,
        $esNuevo
            ? 'Se creó la cotización ' . $folio . ' en borrador.'
            : 'Se actualizó la cotización ' . $folio . '.',
        $anterior ? cot_resumen_auditoria($anterior) : null,
        [
            'folio' => $folio,
            'cliente_id' => $clienteId,
            'cliente' => $cliente['nombre_razon_social'],
            'moneda' => $moneda['codigo'],
            'vigencia_hasta' => $vigenciaHasta,
            'descuento_cliente_pct' => $descuentoCliente,
            'renglones' => count($detalles),
            'total' => $totalHeader,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Cotización guardada como borrador con folio ' . $folio . '.'
            : 'Borrador actualizado correctamente.',
        [
            'cotizacion_id' => $id,
            'folio' => $folio,
            'estado' => 'BORRADOR',
        ],
        $esNuevo ? 201 : 200
    );
}

function cot_generar(PDO $conexion): void
{
    $id = cot_id($_POST['cotizacion_id'] ?? null, 'cotización');

    $conexion->beginTransaction();

    $cot = cot_bloquear($conexion, $id);

    if (!$cot) {
        cot_cancelar($conexion, 'La cotización ya no existe.', 404);
    }

    if ((string) $cot['estado'] !== 'BORRADOR') {
        cot_cancelar($conexion, 'Solo un borrador puede generarse formalmente.', 409);
    }

    if (strtotime((string) $cot['vigencia_hasta']) <= time()) {
        cot_cancelar($conexion, 'La vigencia ya terminó. Edita el borrador y asigna una nueva fecha.', 409);
    }

    $stmt = $conexion->prepare(
        "SELECT COUNT(*)
         FROM cotizaciones_detalle
         WHERE cotizacion_id = :id"
    );
    $stmt->execute([':id' => $id]);

    if ((int) $stmt->fetchColumn() < 1) {
        cot_cancelar($conexion, 'La cotización no contiene productos.', 409);
    }

    $conexion->prepare(
        "UPDATE cotizaciones
         SET estado = 'GENERADA'
         WHERE id = :id"
    )->execute([':id' => $id]);

    cot_auditar(
        $conexion,
        'COTIZACION_GENERADA',
        'cotizaciones',
        $id,
        'Se generó formalmente la cotización ' . $cot['folio'] . '.',
        ['estado' => 'BORRADOR'],
        ['estado' => 'GENERADA']
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Cotización generada. Ya no puede editarse; ahora puede aceptarse o rechazarse.',
        ['cotizacion_id' => $id, 'estado' => 'GENERADA']
    );
}

function cot_aceptar(PDO $conexion): void
{
    $id = cot_id($_POST['cotizacion_id'] ?? null, 'cotización');

    $conexion->beginTransaction();
    $cot = cot_bloquear($conexion, $id);

    if (!$cot) {
        cot_cancelar($conexion, 'La cotización ya no existe.', 404);
    }

    if ((string) $cot['estado'] !== 'GENERADA') {
        cot_cancelar($conexion, 'Solo una cotización generada puede marcarse como aceptada.', 409);
    }

    if (strtotime((string) $cot['vigencia_hasta']) < time()) {
        $conexion->prepare(
            "UPDATE cotizaciones
             SET estado = 'VENCIDA'
             WHERE id = :id"
        )->execute([':id' => $id]);

        cot_auditar(
            $conexion,
            'COTIZACION_VENCIDA',
            'cotizaciones',
            $id,
            'La cotización ' . $cot['folio'] . ' venció antes de ser aceptada.',
            ['estado' => 'GENERADA'],
            ['estado' => 'VENCIDA']
        );

        $conexion->commit();

        si_responder_json(false, 'La cotización ya venció y no puede aceptarse.', [], 409);
    }

    $conexion->prepare(
        "UPDATE cotizaciones
         SET estado = 'ACEPTADA'
         WHERE id = :id"
    )->execute([':id' => $id]);

    cot_auditar(
        $conexion,
        'COTIZACION_ACEPTADA',
        'cotizaciones',
        $id,
        'El cliente aceptó la cotización ' . $cot['folio'] . '.',
        ['estado' => 'GENERADA'],
        ['estado' => 'ACEPTADA']
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Cotización marcada como aceptada.',
        ['cotizacion_id' => $id, 'estado' => 'ACEPTADA']
    );
}

function cot_rechazar(PDO $conexion): void
{
    $id = cot_id($_POST['cotizacion_id'] ?? null, 'cotización');

    $conexion->beginTransaction();
    $cot = cot_bloquear($conexion, $id);

    if (!$cot) {
        cot_cancelar($conexion, 'La cotización ya no existe.', 404);
    }

    if ((string) $cot['estado'] !== 'GENERADA') {
        cot_cancelar($conexion, 'Solo una cotización generada puede rechazarse.', 409);
    }

    $conexion->prepare(
        "UPDATE cotizaciones
         SET estado = 'RECHAZADA'
         WHERE id = :id"
    )->execute([':id' => $id]);

    cot_auditar(
        $conexion,
        'COTIZACION_RECHAZADA',
        'cotizaciones',
        $id,
        'La cotización ' . $cot['folio'] . ' fue marcada como rechazada.',
        ['estado' => 'GENERADA'],
        ['estado' => 'RECHAZADA']
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Cotización marcada como rechazada.',
        ['cotizacion_id' => $id, 'estado' => 'RECHAZADA']
    );
}

/* =========================================================================
   REGLAS Y AUXILIARES
   ========================================================================= */

function cot_marcar_vencidas(PDO $conexion): void
{
    $stmt = $conexion->query(
        "SELECT id, folio
         FROM cotizaciones
         WHERE estado = 'GENERADA'
           AND vigencia_hasta IS NOT NULL
           AND vigencia_hasta < NOW()
         LIMIT 200"
    );

    $vencidas = $stmt->fetchAll();

    if (!$vencidas) {
        return;
    }

    $propia = !$conexion->inTransaction();

    if ($propia) {
        $conexion->beginTransaction();
    }

    $update = $conexion->prepare(
        "UPDATE cotizaciones
         SET estado = 'VENCIDA'
         WHERE id = :id
           AND estado = 'GENERADA'"
    );

    $audit = $conexion->prepare(
        "INSERT INTO auditoria
            (
                usuario_id,
                accion,
                modulo,
                entidad_tabla,
                entidad_id,
                descripcion,
                datos_anteriores,
                datos_nuevos,
                ip,
                user_agent
            )
         VALUES
            (
                NULL,
                'COTIZACION_VENCIDA_AUTOMATICA',
                'cotizaciones',
                'cotizaciones',
                :id,
                :descripcion,
                JSON_OBJECT('estado', 'GENERADA'),
                JSON_OBJECT('estado', 'VENCIDA'),
                NULL,
                'Proceso automático por vigencia'
            )"
    );

    foreach ($vencidas as $fila) {
        $update->execute([':id' => (int) $fila['id']]);

        if ($update->rowCount() > 0) {
            $audit->execute([
                ':id' => (int) $fila['id'],
                ':descripcion' => 'La cotización ' . $fila['folio'] . ' cambió a VENCIDA por fecha de vigencia.',
            ]);
        }
    }

    if ($propia) {
        $conexion->commit();
    }
}

function cot_cliente_activo(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.codigo,
            c.nombre_razon_social,
            c.rfc,
            c.nivel_cliente_id,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct,
            c.descuento_personal_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE c.id = :id
           AND c.activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    $fila['nivel_cliente_id'] = $fila['nivel_cliente_id'] !== null ? (int) $fila['nivel_cliente_id'] : null;
    $fila['descuento_default_pct'] = $fila['descuento_default_pct'] !== null ? (float) $fila['descuento_default_pct'] : 0.0;
    $fila['descuento_personal_pct'] = $fila['descuento_personal_pct'] !== null ? (float) $fila['descuento_personal_pct'] : null;
    $fila['descuento_efectivo_pct'] = (float) $fila['descuento_efectivo_pct'];

    return $fila;
}

function cot_moneda_activa(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre, simbolo, es_base
         FROM monedas
         WHERE id = :id
           AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    $fila['es_base'] = (int) $fila['es_base'];

    return $fila;
}

function cot_producto(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.tasa_impuesto_id,
            COALESCE(ti.porcentaje, 0) AS impuesto_pct,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre
         FROM productos p
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         WHERE p.id = :id
           AND p.activo = 1
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];
    $fila['unidad_base_id'] = (int) $fila['unidad_base_id'];
    $fila['tasa_impuesto_id'] = $fila['tasa_impuesto_id'] !== null ? (int) $fila['tasa_impuesto_id'] : null;
    $fila['impuesto_pct'] = (float) $fila['impuesto_pct'];

    return $fila;
}

function cot_bloquear(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT *
         FROM cotizaciones
         WHERE id = :id
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function cot_tipo_cambio_a_base(PDO $conexion, int $monedaId, string $fecha): ?float
{
    $tipo = si_tc_resolver_a_base($conexion, $monedaId, $fecha, true);
    return $tipo !== null ? (float) $tipo['tipo_cambio'] : null;
}

function cot_tipar_cotizacion(array &$c): void
{
    $c['id'] = (int) $c['id'];
    $c['cliente_id'] = $c['cliente_id'] !== null ? (int) $c['cliente_id'] : null;
    $c['moneda_id'] = (int) $c['moneda_id'];

    foreach ([
        'tipo_cambio_a_base',
        'subtotal',
        'descuento_total',
        'impuesto_total',
        'total',
    ] as $campo) {
        $c[$campo] = (float) $c[$campo];
    }

    if (isset($c['renglones'])) {
        $c['renglones'] = (int) $c['renglones'];
    }
}

function cot_resumen_auditoria(array $cot): array
{
    return [
        'folio' => $cot['folio'] ?? null,
        'cliente_id' => $cot['cliente_id'] ?? null,
        'vigencia_hasta' => $cot['vigencia_hasta'] ?? null,
        'moneda_id' => $cot['moneda_id'] ?? null,
        'estado' => $cot['estado'] ?? null,
        'subtotal' => $cot['subtotal'] ?? null,
        'descuento_total' => $cot['descuento_total'] ?? null,
        'impuesto_total' => $cot['impuesto_total'] ?? null,
        'total' => $cot['total'] ?? null,
    ];
}

function cot_auditar(
    PDO $conexion,
    string $accion,
    string $tabla,
    int $entidadId,
    string $descripcion,
    ?array $anterior,
    ?array $nuevo
): void {
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (
                usuario_id,
                accion,
                modulo,
                entidad_tabla,
                entidad_id,
                descripcion,
                datos_anteriores,
                datos_nuevos,
                ip,
                user_agent
            )
         VALUES
            (
                :usuario_id,
                :accion,
                'cotizaciones',
                :tabla,
                :entidad_id,
                :descripcion,
                :anterior,
                :nuevo,
                :ip,
                :user_agent
            )"
    );

    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':anterior' => $anterior !== null
            ? json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ':nuevo' => $nuevo !== null
            ? json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

function cot_cancelar(PDO $conexion, string $mensaje, int $codigo = 422, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    si_responder_json(false, $mensaje, $datos, $codigo);
}

function cot_id($valor, string $nombre): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);

    if ($id === false || $id < 1) {
        si_responder_json(false, 'Selecciona un ' . $nombre . ' válido.', ['campo' => $nombre], 422);
    }

    return (int) $id;
}

function cot_entero_rango($valor, int $minimo, int $maximo, int $defecto): int
{
    if ($valor === null || $valor === '') {
        return $defecto;
    }

    $entero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($entero === false) {
        return $defecto;
    }

    return max($minimo, min($maximo, (int) $entero));
}

function cot_decimal($valor, string $nombre, float $minimo, float $maximo): float
{
    if ($valor === null || trim((string) $valor) === '') {
        si_responder_json(false, 'Captura ' . $nombre . '.', ['campo' => $nombre], 422);
    }

    $texto = str_replace(',', '', trim((string) $valor));

    if (!is_numeric($texto)) {
        si_responder_json(false, 'El valor de ' . $nombre . ' no es válido.', ['campo' => $nombre], 422);
    }

    $numero = (float) $texto;

    if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
        si_responder_json(false, 'El valor de ' . $nombre . ' está fuera del rango permitido.', ['campo' => $nombre], 422);
    }

    return $numero;
}

function cot_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);

    if (mb_strlen($texto) > $maximo) {
        $texto = mb_substr($texto, 0, $maximo);
    }

    return $texto;
}

function cot_nullable($valor, int $maximo): ?string
{
    $texto = cot_texto($valor, $maximo);
    return $texto === '' ? null : $texto;
}

function cot_fecha_opcional($valor): ?string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d', $texto);
    $errores = DateTimeImmutable::getLastErrors();

    if (!$d || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
        si_responder_json(false, 'La fecha indicada no es válida.', [], 422);
    }

    return $d->format('Y-m-d');
}

function cot_fecha_requerida($valor, string $nombre): string
{
    $fecha = cot_fecha_opcional($valor);

    if ($fecha === null) {
        si_responder_json(false, 'Selecciona ' . $nombre . '.', ['campo' => $nombre], 422);
    }

    return $fecha;
}

function cot_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } elseif ($valor === null) {
            $stmt->bindValue($clave, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}
