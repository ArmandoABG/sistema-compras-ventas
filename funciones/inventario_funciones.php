<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/stock_operativo.php';
require_once __DIR__ . '/../inc/xlsx_simple.php';

si_requerir_permiso('inventario.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_INVENTARIO')
        : ($_POST['accion'] ?? '')
)));

try {
    si_stock_preparar_operacion($conexion);

    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                inv_catalogos($conexion);
                break;

            case 'RESUMEN':
                inv_resumen($conexion);
                break;

            case 'LISTAR_INVENTARIO':
                inv_listar_inventario($conexion);
                break;

            case 'OBTENER_NIVELES_STOCK':
                si_requerir_permiso('inventario.configurar_stock', true);
                inv_obtener_niveles_stock($conexion);
                break;

            case 'LISTAR_KARDEX':
                if (!si_tiene_permiso('inventario.kardex')) {
                    si_responder_json(false, 'No tienes permiso para consultar el Kardex.', [], 403);
                }
                inv_listar_kardex($conexion);
                break;

            case 'EXPORTAR_KARDEX_CSV':
                if (!si_tiene_permiso('inventario.kardex')) {
                    si_responder_json(false, 'No tienes permiso para exportar el Kardex.', [], 403);
                }
                inv_exportar_kardex_csv($conexion);
                break;

            case 'EXPORTAR_KARDEX_XLSX':
                if (!si_tiene_permiso('inventario.kardex')) {
                    si_responder_json(false, 'No tienes permiso para exportar el Kardex.', [], 403);
                }
                inv_exportar_kardex_xlsx($conexion);
                break;

            case 'BUSCAR_PRODUCTOS_OPERACION':
                if (!si_tiene_permiso('inventario.ajustar') && !si_tiene_permiso('inventario.mermas')) {
                    si_responder_json(false, 'No tienes permiso para registrar operaciones de inventario.', [], 403);
                }
                inv_buscar_productos_operacion($conexion);
                break;

            case 'LISTAR_AJUSTES_MERMAS':
                if (!si_tiene_permiso('inventario.ajustar') && !si_tiene_permiso('inventario.mermas')) {
                    si_responder_json(false, 'No tienes permiso para consultar ajustes o mermas.', [], 403);
                }
                inv_listar_ajustes_mermas($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'GUARDAR_NIVELES_STOCK':
            si_requerir_permiso('inventario.configurar_stock', true);
            inv_guardar_niveles_stock($conexion);
            break;

        case 'REGISTRAR_AJUSTE':
            si_requerir_permiso('inventario.ajustar', true);
            inv_registrar_ajuste($conexion);
            break;

        case 'REGISTRAR_MERMA':
            si_requerir_permiso('inventario.mermas', true);
            inv_registrar_merma($conexion);
            break;

        case 'REVERTIR_OPERACION':
            inv_revertir_operacion($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'INV-' . date('Ymd-His');
    error_log('[' . $referencia . '][INVENTARIO][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible procesar la operación de inventario.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'INV-' . date('Ymd-His');
    error_log('[' . $referencia . '][INVENTARIO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar inventario.', ['referencia' => $referencia], 500);
}

/* =========================================================================
   CATÁLOGOS
   ========================================================================= */

function inv_catalogos(PDO $conexion): void
{
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $tiposMovimiento = $conexion->query(
        "SELECT id, codigo, nombre
         FROM tipos_movimiento_inventario
         WHERE activo = 1
         ORDER BY id ASC"
    )->fetchAll();

    foreach ($almacenes as &$almacen) {
        $almacen['id'] = (int) $almacen['id'];
    }
    unset($almacen);

    foreach ($tiposMovimiento as &$tipo) {
        $tipo['id'] = (int) $tipo['id'];
    }
    unset($tipo);

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'almacenes' => $almacenes,
            'tipos_movimiento' => $tiposMovimiento,
            'puede_kardex' => si_tiene_permiso('inventario.kardex'),
            'puede_ajustar' => si_tiene_permiso('inventario.ajustar'),
            'puede_mermas' => si_tiene_permiso('inventario.mermas'),
            'puede_configurar_stock' => si_tiene_permiso('inventario.configurar_stock'),
        ]
    );
}

/* =========================================================================
   RESUMEN DE EXISTENCIAS
   ========================================================================= */

function inv_resumen(PDO $conexion): void
{
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipoProducto = inv_tipo_producto($_GET['tipo_producto'] ?? 'TODOS');
    $estadoProducto = inv_estado_producto($_GET['estado_producto'] ?? 'TODOS');

    [$where, $params] = inv_filtros_base_existencias(
        $almacenId,
        $tipoProducto,
        $estadoProducto,
        ''
    );

    $disponible = inv_sql_disponible();
    $estadoStock = inv_sql_estado_stock();

    $sql = "SELECT
                COUNT(*) AS total_registros,
                SUM(CASE WHEN x.existencia_fisica > 0 THEN 1 ELSE 0 END) AS con_existencia,
                SUM(CASE WHEN x.estado_stock IN ('SIN_STOCK', 'SIN_DISPONIBLE') THEN 1 ELSE 0 END) AS sin_disponible,
                SUM(CASE WHEN x.estado_stock = 'CRITICO' THEN 1 ELSE 0 END) AS criticos,
                SUM(CASE WHEN x.estado_stock = 'REORDEN' THEN 1 ELSE 0 END) AS reorden,
                SUM(CASE WHEN x.cantidad_reservada > 0 THEN 1 ELSE 0 END) AS con_reserva,
                COALESCE(SUM(x.cantidad_reservada), 0) AS reservado_total
            FROM (
                SELECT
                    COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
                    COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
                    {$disponible} AS cantidad_disponible,
                    {$estadoStock} AS estado_stock
                FROM productos p
                INNER JOIN unidades_medida um
                    ON um.id = p.unidad_base_id
                CROSS JOIN almacenes a
                LEFT JOIN existencias_almacen ea
                    ON ea.producto_id = p.id
                   AND ea.almacen_id = a.id
                {$where}
            ) x";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $fila = $stmt->fetch() ?: [];

    si_responder_json(
        true,
        'Resumen cargado.',
        [
            'resumen' => [
                'total_registros' => (int) ($fila['total_registros'] ?? 0),
                'con_existencia' => (int) ($fila['con_existencia'] ?? 0),
                'sin_disponible' => (int) ($fila['sin_disponible'] ?? 0),
                'criticos' => (int) ($fila['criticos'] ?? 0),
                'reorden' => (int) ($fila['reorden'] ?? 0),
                'con_reserva' => (int) ($fila['con_reserva'] ?? 0),
                'reservado_total' => (float) ($fila['reservado_total'] ?? 0),
            ],
        ]
    );
}

/* =========================================================================
   LISTADO DE EXISTENCIAS
   ========================================================================= */

function inv_listar_inventario(PDO $conexion): void
{
    $pagina = inv_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = inv_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = inv_texto($_GET['buscar'] ?? '', 120);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipoProducto = inv_tipo_producto($_GET['tipo_producto'] ?? 'TODOS');
    $estadoProducto = inv_estado_producto($_GET['estado_producto'] ?? 'TODOS');
    $estadoStockFiltro = inv_estado_stock($_GET['estado_stock'] ?? 'TODOS');

    [$where, $params] = inv_filtros_base_existencias(
        $almacenId,
        $tipoProducto,
        $estadoProducto,
        $buscar
    );

    $estadoStock = inv_sql_estado_stock();
    $disponible = inv_sql_disponible();

    if ($estadoStockFiltro !== 'TODOS') {
        $where .= " AND ({$estadoStock}) = :estado_stock";
        $params[':estado_stock'] = $estadoStockFiltro;
    }

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM productos p
         INNER JOIN unidades_medida um
            ON um.id = p.unidad_base_id
         CROSS JOIN almacenes a
         LEFT JOIN existencias_almacen ea
            ON ea.producto_id = p.id
           AND ea.almacen_id = a.id
         {$where}"
    );
    inv_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                p.id AS producto_id,
                p.sku,
                p.codigo_barras,
                p.nombre AS producto,
                p.tipo AS tipo_producto,
                p.activo AS producto_activo,
                um.nombre AS unidad_base,
                um.simbolo AS unidad_simbolo,
                a.id AS almacen_id,
                a.codigo AS almacen_codigo,
                a.nombre AS almacen,
                COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
                COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
                {$disponible} AS cantidad_disponible,
                COALESCE(ea.stock_minimo, 0) AS stock_minimo,
                ea.punto_reorden,
                {$estadoStock} AS estado_stock,
                ea.updated_at
            FROM productos p
            INNER JOIN unidades_medida um
                ON um.id = p.unidad_base_id
            CROSS JOIN almacenes a
            LEFT JOIN existencias_almacen ea
                ON ea.producto_id = p.id
               AND ea.almacen_id = a.id
            {$where}
            ORDER BY
                CASE ({$estadoStock})
                    WHEN 'SIN_STOCK' THEN 0
                    WHEN 'SIN_DISPONIBLE' THEN 1
                    WHEN 'CRITICO' THEN 2
                    WHEN 'REORDEN' THEN 3
                    ELSE 4
                END ASC,
                p.nombre ASC,
                a.nombre ASC,
                p.id ASC
            LIMIT :limite
            OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['producto_activo'] = (int) $fila['producto_activo'];
        $fila['almacen_id'] = (int) $fila['almacen_id'];
        $fila['existencia_fisica'] = (float) $fila['existencia_fisica'];
        $fila['cantidad_reservada'] = (float) $fila['cantidad_reservada'];
        $fila['cantidad_disponible'] = (float) $fila['cantidad_disponible'];
        $fila['stock_minimo'] = (float) $fila['stock_minimo'];
        $fila['punto_reorden'] = $fila['punto_reorden'] !== null
            ? (float) $fila['punto_reorden']
            : null;
    }
    unset($fila);

    si_responder_json(
        true,
        'Inventario cargado.',
        [
            'registros' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

/* =========================================================================
   NIVELES DE STOCK / REORDEN
   ========================================================================= */

function inv_obtener_niveles_stock(PDO $conexion): void
{
    $productoId = inv_entero_rango($_GET['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($productoId <= 0 || $almacenId <= 0) {
        si_responder_json(false, 'Selecciona producto y almacén.', [], 422);
    }

    $stmt = $conexion->prepare(
        "SELECT p.id AS producto_id, p.sku, p.nombre AS producto, p.permite_fraccion, p.activo AS producto_activo,
                um.nombre AS unidad_base, um.simbolo AS unidad_simbolo,
                a.id AS almacen_id, a.codigo AS almacen_codigo, a.nombre AS almacen,
                COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
                COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
                (COALESCE(ea.existencia_fisica, 0) - COALESCE(ea.cantidad_reservada, 0)) AS cantidad_disponible,
                COALESCE(ea.stock_minimo, 0) AS stock_minimo,
                ea.punto_reorden
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         INNER JOIN almacenes a ON a.id = :almacen AND a.activo = 1
         LEFT JOIN existencias_almacen ea ON ea.producto_id = p.id AND ea.almacen_id = a.id
         WHERE p.id = :producto
           AND p.controla_inventario = 1
         LIMIT 1"
    );
    $stmt->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        si_responder_json(false, 'El producto o almacén ya no está disponible para configurar inventario.', [], 404);
    }

    foreach (['producto_id', 'permite_fraccion', 'producto_activo', 'almacen_id'] as $campo) {
        $fila[$campo] = (int) $fila[$campo];
    }
    foreach (['existencia_fisica', 'cantidad_reservada', 'cantidad_disponible', 'stock_minimo'] as $campo) {
        $fila[$campo] = (float) $fila[$campo];
    }
    $fila['punto_reorden'] = $fila['punto_reorden'] !== null ? (float) $fila['punto_reorden'] : null;

    si_responder_json(true, 'Niveles cargados.', ['detalle' => $fila]);
}

function inv_guardar_niveles_stock(PDO $conexion): void
{
    $productoId = inv_entero_rango($_POST['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $almacenId = inv_entero_rango($_POST['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $stockTexto = trim((string) ($_POST['stock_minimo'] ?? ''));
    $reordenTexto = trim((string) ($_POST['punto_reorden'] ?? ''));
    $aplicarTodos = (string) ($_POST['aplicar_todos'] ?? '0') === '1';

    if ($productoId <= 0 || $almacenId <= 0) {
        si_responder_json(false, 'Selecciona producto y almacén.', [], 422);
    }

    $stockMinimo = inv_decimal_no_negativo($stockTexto);
    if ($stockMinimo === null) {
        si_responder_json(false, 'El stock mínimo debe ser un número válido mayor o igual a cero, con máximo 6 decimales.', [], 422);
    }
    $puntoReorden = null;
    if ($reordenTexto !== '') {
        $puntoReorden = inv_decimal_no_negativo($reordenTexto);
        if ($puntoReorden === null) {
            si_responder_json(false, 'El punto de reorden debe ser un número válido mayor o igual a cero, con máximo 6 decimales.', [], 422);
        }
        if ($puntoReorden + 0.000001 < $stockMinimo) {
            si_responder_json(false, 'El punto de reorden debe ser igual o mayor al stock mínimo.', [], 422);
        }
    }

    $conexion->beginTransaction();

    $stmtProducto = $conexion->prepare(
        "SELECT p.id, p.sku, p.nombre, p.permite_fraccion, p.controla_inventario,
                um.simbolo AS unidad_simbolo
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE p.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmtProducto->execute([':id' => $productoId]);
    $producto = $stmtProducto->fetch();
    if (!$producto || (int) $producto['controla_inventario'] !== 1) {
        inv_cancelar_transaccion($conexion, 'El producto ya no está disponible para control de inventario.', 409);
    }

    if ((int) $producto['permite_fraccion'] !== 1) {
        $valores = [$stockMinimo];
        if ($puntoReorden !== null) $valores[] = $puntoReorden;
        foreach ($valores as $valor) {
            if (abs($valor - round($valor)) > 0.000001) {
                inv_cancelar_transaccion($conexion, 'Este producto no permite fracciones; el mínimo y reorden deben ser cantidades enteras.', 422);
            }
        }
    }

    if ($aplicarTodos) {
        $stmtAlmacenes = $conexion->query(
            "SELECT id, codigo, nombre
             FROM almacenes
             WHERE activo = 1
             ORDER BY id ASC
             FOR UPDATE"
        );
        $almacenes = $stmtAlmacenes->fetchAll();
    } else {
        $stmtAlmacenes = $conexion->prepare(
            "SELECT id, codigo, nombre
             FROM almacenes
             WHERE id = :id AND activo = 1
             LIMIT 1
             FOR UPDATE"
        );
        $stmtAlmacenes->execute([':id' => $almacenId]);
        $filaAlmacen = $stmtAlmacenes->fetch();
        $almacenes = $filaAlmacen ? [$filaAlmacen] : [];
    }

    if (!$almacenes) {
        inv_cancelar_transaccion($conexion, 'El almacén seleccionado ya no está activo.', 409);
    }
    if (!$aplicarTodos && (int) $almacenes[0]['id'] !== $almacenId) {
        inv_cancelar_transaccion($conexion, 'El almacén seleccionado ya no está disponible.', 409);
    }

    $insertar = $conexion->prepare(
        "INSERT IGNORE INTO existencias_almacen
            (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden)
         VALUES (:almacen, :producto, 0, 0, 0, NULL)"
    );
    $bloquear = $conexion->prepare(
        "SELECT id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden
         FROM existencias_almacen
         WHERE almacen_id = :almacen AND producto_id = :producto
         LIMIT 1
         FOR UPDATE"
    );
    $actualizar = $conexion->prepare(
        "UPDATE existencias_almacen
         SET stock_minimo = :stock_minimo,
             punto_reorden = :punto_reorden
         WHERE id = :id"
    );

    $actualizados = 0;
    foreach ($almacenes as $almacen) {
        $aid = (int) $almacen['id'];
        $insertar->execute([':almacen' => $aid, ':producto' => $productoId]);
        $bloquear->execute([':almacen' => $aid, ':producto' => $productoId]);
        $existencia = $bloquear->fetch();
        if (!$existencia) {
            inv_cancelar_transaccion($conexion, 'No fue posible preparar la configuración del producto en el almacén.', 409);
        }

        $antes = [
            'stock_minimo' => (float) $existencia['stock_minimo'],
            'punto_reorden' => $existencia['punto_reorden'] !== null ? (float) $existencia['punto_reorden'] : null,
            'existencia_fisica' => (float) $existencia['existencia_fisica'],
            'cantidad_reservada' => (float) $existencia['cantidad_reservada'],
        ];

        $actualizar->bindValue(':stock_minimo', $stockMinimo);
        if ($puntoReorden === null) {
            $actualizar->bindValue(':punto_reorden', null, PDO::PARAM_NULL);
        } else {
            $actualizar->bindValue(':punto_reorden', $puntoReorden);
        }
        $actualizar->bindValue(':id', (int) $existencia['id'], PDO::PARAM_INT);
        $actualizar->execute();

        inv_auditar_operacion(
            $conexion,
            'NIVELES_STOCK_ACTUALIZADOS',
            (int) $existencia['id'],
            'Se actualizaron stock mínimo y punto de reorden de ' . $producto['sku'] . ' en ' . $almacen['codigo'] . '.',
            $antes,
            [
                'stock_minimo' => $stockMinimo,
                'punto_reorden' => $puntoReorden,
                'producto_id' => $productoId,
                'producto' => $producto['nombre'],
                'almacen_id' => $aid,
                'almacen' => $almacen['nombre'],
                'aplicado_a_todos' => $aplicarTodos,
            ],
            'existencias_almacen'
        );
        $actualizados++;
    }

    $conexion->commit();
    si_responder_json(true, $aplicarTodos
        ? 'Niveles actualizados en ' . $actualizados . ' almacén(es) activos.'
        : 'Stock mínimo y punto de reorden actualizados correctamente.', [
        'producto_id' => $productoId,
        'almacenes_actualizados' => $actualizados,
        'stock_minimo' => $stockMinimo,
        'punto_reorden' => $puntoReorden,
    ]);
}

/* =========================================================================
   KARDEX
   ========================================================================= */

function inv_listar_kardex(PDO $conexion): void
{
    $pagina = inv_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = inv_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $filtros = inv_kardex_filtros_peticion();
    [$from, $where, $params] = inv_kardex_contexto_sql($filtros);

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$where}");
    inv_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $sql = inv_kardex_select_sql($from, $where)
        . " ORDER BY COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) DESC, mi.id DESC, mid.renglon DESC, mid.id DESC
            LIMIT :limite OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    inv_kardex_normalizar_filas($filas);

    $resumen = inv_kardex_resumen_filtrado($conexion, $from, $where, $params, $filtros);

    si_responder_json(true, 'Kardex cargado.', [
        'registros' => $filas,
        'resumen' => $resumen,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function inv_kardex_filtros_peticion(): array
{
    $filtros = [
        'buscar' => inv_texto($_GET['buscar'] ?? '', 120),
        'producto_id' => inv_entero_rango($_GET['producto_id'] ?? 0, 0, PHP_INT_MAX, 0),
        'almacen_id' => inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0),
        'tipo_movimiento_id' => inv_entero_rango($_GET['tipo_movimiento_id'] ?? 0, 0, PHP_INT_MAX, 0),
        'estado' => inv_estado_movimiento($_GET['estado'] ?? 'TODOS'),
        'fecha_desde' => inv_fecha($_GET['fecha_desde'] ?? ''),
        'fecha_hasta' => inv_fecha($_GET['fecha_hasta'] ?? ''),
    ];

    if ($filtros['fecha_desde'] !== '' && $filtros['fecha_hasta'] !== '' && $filtros['fecha_desde'] > $filtros['fecha_hasta']) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la fecha final.', [], 422);
    }

    return $filtros;
}

function inv_kardex_contexto_sql(array $filtros): array
{
    $where = 'WHERE 1 = 1';
    $params = [];

    if ($filtros['buscar'] !== '') {
        $where .= " AND (
            p.sku LIKE :buscar_sku
            OR p.nombre LIKE :buscar_producto
            OR mi.folio LIKE :buscar_folio
            OR COALESCE(mi.origen_tipo, '') LIKE :buscar_origen
            OR COALESCE(mi.motivo, '') LIKE :buscar_motivo
        )";
        $patron = '%' . $filtros['buscar'] . '%';
        $params[':buscar_sku'] = $patron;
        $params[':buscar_producto'] = $patron;
        $params[':buscar_folio'] = $patron;
        $params[':buscar_origen'] = $patron;
        $params[':buscar_motivo'] = $patron;
    }

    if ($filtros['producto_id'] > 0) {
        $where .= ' AND mid.producto_id = :producto_id';
        $params[':producto_id'] = $filtros['producto_id'];
    }
    if ($filtros['almacen_id'] > 0) {
        $where .= ' AND mid.almacen_id = :almacen_id';
        $params[':almacen_id'] = $filtros['almacen_id'];
    }
    if ($filtros['tipo_movimiento_id'] > 0) {
        $where .= ' AND mi.tipo_movimiento_id = :tipo_movimiento_id';
        $params[':tipo_movimiento_id'] = $filtros['tipo_movimiento_id'];
    }
    if ($filtros['estado'] !== 'TODOS') {
        $where .= ' AND mi.estado = :estado';
        $params[':estado'] = $filtros['estado'];
    }
    if ($filtros['fecha_desde'] !== '') {
        $where .= ' AND mi.fecha_movimiento >= :fecha_desde';
        $params[':fecha_desde'] = $filtros['fecha_desde'] . ' 00:00:00';
    }
    if ($filtros['fecha_hasta'] !== '') {
        $where .= ' AND mi.fecha_movimiento <= :fecha_hasta';
        $params[':fecha_hasta'] = $filtros['fecha_hasta'] . ' 23:59:59';
    }

    $from = "FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
             INNER JOIN almacenes a ON a.id = mid.almacen_id
             INNER JOIN productos p ON p.id = mid.producto_id
             INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
             LEFT JOIN usuarios u ON u.id = COALESCE(mi.aplicado_by, mi.created_by)
             LEFT JOIN recepciones_compra rc
                ON rc.id = mi.origen_id
               AND mi.origen_tipo IN ('RECEPCION_COMPRA', 'CANCELACION_RECEPCION_COMPRA')
             LEFT JOIN compras c ON c.id = rc.compra_id
             LEFT JOIN ventas v_origen ON v_origen.id = mi.origen_id AND mi.origen_tipo = 'VENTA'
             LEFT JOIN producciones prod_origen ON prod_origen.id = mi.origen_id AND mi.origen_tipo = 'PRODUCCION'
             LEFT JOIN ajustes_inventario aju_origen ON aju_origen.id = mi.origen_id AND mi.origen_tipo = 'AJUSTE_INVENTARIO'
             LEFT JOIN devoluciones_venta devv_origen ON devv_origen.id = mi.origen_id AND mi.origen_tipo = 'DEVOLUCION_VENTA'
             LEFT JOIN devoluciones_compra devc_origen ON devc_origen.id = mi.origen_id AND mi.origen_tipo = 'DEVOLUCION_COMPRA'
             LEFT JOIN movimientos_inventario mov_revertido ON mov_revertido.id = mi.movimiento_revertido_id";

    return [$from, $where, $params];
}

function inv_kardex_select_sql(string $from, string $where): string
{
    return "SELECT
                mid.id AS detalle_id,
                mi.id AS movimiento_id,
                mi.folio,
                mi.fecha_movimiento,
                COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) AS fecha_aplicacion,
                mi.aplicado_at,
                mi.created_at AS movimiento_created_at,
                mi.estado,
                tmi.codigo AS tipo_codigo,
                tmi.nombre AS tipo_movimiento,
                mi.origen_tipo,
                mi.origen_id,
                mi.movimiento_revertido_id,
                CASE
                    WHEN mi.movimiento_revertido_id IS NOT NULL AND mov_revertido.id IS NOT NULL
                    THEN CONCAT('Reverso de ', mov_revertido.folio)
                    WHEN mi.origen_tipo IN ('RECEPCION_COMPRA', 'CANCELACION_RECEPCION_COMPRA') AND rc.id IS NOT NULL
                    THEN CONCAT(rc.folio, IF(c.folio IS NULL, '', CONCAT(' / ', c.folio)))
                    WHEN mi.origen_tipo = 'VENTA' AND v_origen.id IS NOT NULL THEN v_origen.folio
                    WHEN mi.origen_tipo = 'PRODUCCION' AND prod_origen.id IS NOT NULL THEN prod_origen.folio
                    WHEN mi.origen_tipo = 'AJUSTE_INVENTARIO' AND aju_origen.id IS NOT NULL THEN aju_origen.folio
                    WHEN mi.origen_tipo = 'DEVOLUCION_VENTA' AND devv_origen.id IS NOT NULL THEN devv_origen.folio
                    WHEN mi.origen_tipo = 'DEVOLUCION_COMPRA' AND devc_origen.id IS NOT NULL THEN devc_origen.folio
                    WHEN mi.origen_tipo = 'TRANSFERENCIA' THEN mi.folio
                    WHEN mi.origen_tipo IS NOT NULL AND mi.origen_id IS NOT NULL THEN CONCAT(mi.origen_tipo, ' #', mi.origen_id)
                    WHEN mi.origen_tipo IS NOT NULL THEN mi.origen_tipo
                    ELSE 'Movimiento manual / interno'
                END AS origen_referencia,
                p.id AS producto_id,
                p.sku,
                p.nombre AS producto,
                um.nombre AS unidad_base,
                um.simbolo AS unidad_simbolo,
                um.codigo AS unidad_codigo,
                a.id AS almacen_id,
                a.codigo AS almacen_codigo,
                a.nombre AS almacen,
                mid.cantidad_delta,
                CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END AS cantidad_entrada,
                CASE WHEN mid.cantidad_delta < 0 THEN ABS(mid.cantidad_delta) ELSE 0 END AS cantidad_salida,
                mid.existencia_antes,
                mid.existencia_despues,
                mid.costo_unitario_base,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)), ''), u.usuario, 'Sistema') AS usuario_aplico,
                mi.motivo,
                COALESCE(mid.observaciones, mi.observaciones) AS observaciones
            {$from} {$where}";
}

function inv_kardex_normalizar_filas(array &$filas): void
{
    foreach ($filas as &$fila) {
        foreach (['detalle_id', 'movimiento_id', 'producto_id', 'almacen_id'] as $campo) {
            $fila[$campo] = (int) $fila[$campo];
        }
        foreach (['origen_id', 'movimiento_revertido_id'] as $campo) {
            $fila[$campo] = $fila[$campo] !== null ? (int) $fila[$campo] : null;
        }
        foreach (['cantidad_delta', 'cantidad_entrada', 'cantidad_salida', 'existencia_antes', 'existencia_despues'] as $campo) {
            $fila[$campo] = (float) $fila[$campo];
        }
        $fila['costo_unitario_base'] = $fila['costo_unitario_base'] !== null ? (float) $fila['costo_unitario_base'] : null;
    }
    unset($fila);
}

function inv_kardex_resumen_filtrado(PDO $conexion, string $from, string $where, array $params, array $filtros): array
{
    $sqlGeneral = "SELECT
            COUNT(DISTINCT mi.id) AS movimientos,
            COUNT(*) AS renglones,
            COUNT(DISTINCT mid.producto_id) AS productos,
            COUNT(DISTINCT mid.almacen_id) AS almacenes,
            SUM(CASE WHEN mid.cantidad_delta > 0 THEN 1 ELSE 0 END) AS renglones_entrada,
            SUM(CASE WHEN mid.cantidad_delta < 0 THEN 1 ELSE 0 END) AS renglones_salida,
            COUNT(DISTINCT CASE WHEN tmi.codigo = 'TRANSFERENCIA' THEN mi.id END) AS transferencias,
            COUNT(DISTINCT CASE WHEN tmi.codigo = 'REVERSO' OR mi.movimiento_revertido_id IS NOT NULL THEN mi.id END) AS reversos,
            COUNT(DISTINCT CASE WHEN mi.estado = 'APLICADO' THEN mi.id END) AS aplicados,
            COUNT(DISTINCT CASE WHEN mi.estado = 'REVERTIDO' THEN mi.id END) AS revertidos
        {$from} {$where}";
    $stmt = $conexion->prepare($sqlGeneral);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $general = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['movimientos','renglones','productos','almacenes','renglones_entrada','renglones_salida','transferencias','reversos','aplicados','revertidos'] as $campo) {
        $general[$campo] = (int) ($general[$campo] ?? 0);
    }

    $sqlTipos = "SELECT tmi.codigo, tmi.nombre,
                        COUNT(DISTINCT mi.id) AS movimientos,
                        COUNT(*) AS renglones,
                        COUNT(DISTINCT mid.producto_id) AS productos,
                        COUNT(DISTINCT mid.almacen_id) AS almacenes
                 {$from} {$where}
                 GROUP BY tmi.id, tmi.codigo, tmi.nombre
                 ORDER BY movimientos DESC, tmi.nombre ASC";
    $stmt = $conexion->prepare($sqlTipos);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $porTipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porTipo as &$fila) {
        foreach (['movimientos','renglones','productos','almacenes'] as $campo) {
            $fila[$campo] = (int) $fila[$campo];
        }
    }
    unset($fila);

    $sqlAlmacenes = "SELECT a.id AS almacen_id, a.codigo, a.nombre,
                            COUNT(DISTINCT mi.id) AS movimientos,
                            COUNT(DISTINCT mid.producto_id) AS productos,
                            SUM(CASE WHEN mid.cantidad_delta > 0 THEN 1 ELSE 0 END) AS renglones_entrada,
                            SUM(CASE WHEN mid.cantidad_delta < 0 THEN 1 ELSE 0 END) AS renglones_salida,
                            COUNT(DISTINCT CASE WHEN tmi.codigo = 'TRANSFERENCIA' THEN mi.id END) AS transferencias
                     {$from} {$where}
                     GROUP BY a.id, a.codigo, a.nombre
                     ORDER BY movimientos DESC, a.nombre ASC";
    $stmt = $conexion->prepare($sqlAlmacenes);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $porAlmacen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porAlmacen as &$fila) {
        foreach (['almacen_id','movimientos','productos','renglones_entrada','renglones_salida','transferencias'] as $campo) {
            $fila[$campo] = (int) $fila[$campo];
        }
    }
    unset($fila);

    $sqlProductos = "SELECT p.id AS producto_id, p.sku, p.nombre AS producto,
                            um.codigo AS unidad_codigo, um.simbolo AS unidad_simbolo,
                            COUNT(DISTINCT mi.id) AS movimientos,
                            COUNT(DISTINCT mid.almacen_id) AS almacenes,
                            SUM(CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END) AS entradas,
                            SUM(CASE WHEN mid.cantidad_delta < 0 THEN ABS(mid.cantidad_delta) ELSE 0 END) AS salidas,
                            SUM(mid.cantidad_delta) AS neto
                     {$from} {$where}
                     GROUP BY p.id, p.sku, p.nombre, um.codigo, um.simbolo
                     ORDER BY movimientos DESC, p.nombre ASC
                     LIMIT 20";
    $stmt = $conexion->prepare($sqlProductos);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $porProducto = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($porProducto as &$fila) {
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['movimientos'] = (int) $fila['movimientos'];
        $fila['almacenes'] = (int) $fila['almacenes'];
        $fila['entradas'] = (float) $fila['entradas'];
        $fila['salidas'] = (float) $fila['salidas'];
        $fila['neto'] = (float) $fila['neto'];
    }
    unset($fila);

    $productoSeleccionado = null;
    if ($filtros['producto_id'] > 0) {
        $sqlProducto = "SELECT p.id, p.sku, p.nombre, um.codigo AS unidad_codigo, um.simbolo AS unidad_simbolo,
                               COALESCE(SUM(CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END),0) AS entradas,
                               COALESCE(SUM(CASE WHEN mid.cantidad_delta < 0 THEN ABS(mid.cantidad_delta) ELSE 0 END),0) AS salidas,
                               COALESCE(SUM(mid.cantidad_delta),0) AS neto
                        {$from} {$where}
                        GROUP BY p.id, p.sku, p.nombre, um.codigo, um.simbolo
                        LIMIT 1";
        $stmt = $conexion->prepare($sqlProducto);
        inv_bind_params($stmt, $params);
        $stmt->execute();
        $productoSeleccionado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($productoSeleccionado) {
            $sqlActual = "SELECT COALESCE(SUM(ea.existencia_fisica),0) AS fisica,
                                 COALESCE(SUM(ea.cantidad_reservada),0) AS reservada,
                                 COALESCE(SUM(ea.cantidad_disponible),0) AS disponible
                          FROM existencias_almacen ea
                          WHERE ea.producto_id = :producto_actual"
                          . ($filtros['almacen_id'] > 0 ? ' AND ea.almacen_id = :almacen_actual' : '');
            $stmtActual = $conexion->prepare($sqlActual);
            $stmtActual->bindValue(':producto_actual', $filtros['producto_id'], PDO::PARAM_INT);
            if ($filtros['almacen_id'] > 0) {
                $stmtActual->bindValue(':almacen_actual', $filtros['almacen_id'], PDO::PARAM_INT);
            }
            $stmtActual->execute();
            $actual = $stmtActual->fetch(PDO::FETCH_ASSOC) ?: ['fisica' => 0, 'reservada' => 0, 'disponible' => 0];
            $productoSeleccionado['id'] = (int) $productoSeleccionado['id'];
            foreach (['entradas','salidas','neto'] as $campo) {
                $productoSeleccionado[$campo] = (float) $productoSeleccionado[$campo];
            }
            $productoSeleccionado['existencia_actual'] = (float) $actual['fisica'];
            $productoSeleccionado['reservada_actual'] = (float) $actual['reservada'];
            $productoSeleccionado['disponible_actual'] = (float) $actual['disponible'];
        }
    }

    return [
        'general' => $general,
        'por_tipo' => $porTipo,
        'por_almacen' => $porAlmacen,
        'por_producto' => $porProducto,
        'producto_seleccionado' => $productoSeleccionado,
    ];
}

function inv_exportar_kardex_csv(PDO $conexion): void
{
    $filtros = inv_kardex_filtros_peticion();
    [$from, $where, $params] = inv_kardex_contexto_sql($filtros);
    $total = inv_kardex_total_exportacion($conexion, $from, $where, $params);
    inv_kardex_validar_limite_exportacion($total);

    $stmt = $conexion->prepare(inv_kardex_select_sql($from, $where) . ' ORDER BY COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) ASC, mi.id ASC, mid.renglon ASC, mid.id ASC');
    inv_bind_params($stmt, $params);
    $stmt->execute();

    $nombre = 'kardex_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('No fue posible preparar la exportación CSV.');
    }
    fputcsv($out, inv_kardex_export_headers(), ',', '"', '');
    while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, inv_kardex_export_row($fila), ',', '"', '');
    }
    fclose($out);
    exit;
}

function inv_exportar_kardex_xlsx(PDO $conexion): void
{
    $filtros = inv_kardex_filtros_peticion();
    [$from, $where, $params] = inv_kardex_contexto_sql($filtros);
    $total = inv_kardex_total_exportacion($conexion, $from, $where, $params);
    inv_kardex_validar_limite_exportacion($total);

    $stmt = $conexion->prepare(inv_kardex_select_sql($from, $where) . ' ORDER BY COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) ASC, mi.id ASC, mid.renglon ASC, mid.id ASC');
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $filasDb = $stmt->fetchAll(PDO::FETCH_ASSOC);
    inv_kardex_normalizar_filas($filasDb);

    $filas = [];
    foreach ($filasDb as $fila) {
        $filas[] = inv_kardex_export_row_assoc($fila);
    }

    $resumen = inv_kardex_resumen_filtrado($conexion, $from, $where, $params, $filtros);
    $filasResumen = [
        ['concepto' => 'Fecha de exportación', 'valor' => date('Y-m-d H:i:s')],
        ['concepto' => 'Movimientos', 'valor' => $resumen['general']['movimientos']],
        ['concepto' => 'Renglones', 'valor' => $resumen['general']['renglones']],
        ['concepto' => 'Productos', 'valor' => $resumen['general']['productos']],
        ['concepto' => 'Almacenes', 'valor' => $resumen['general']['almacenes']],
        ['concepto' => 'Transferencias', 'valor' => $resumen['general']['transferencias']],
        ['concepto' => 'Reversos', 'valor' => $resumen['general']['reversos']],
    ];
    if ($resumen['producto_seleccionado']) {
        $ps = $resumen['producto_seleccionado'];
        $unidad = $ps['unidad_simbolo'] ?: $ps['unidad_codigo'];
        $filasResumen[] = ['concepto' => 'Producto', 'valor' => $ps['sku'] . ' · ' . $ps['nombre']];
        $filasResumen[] = ['concepto' => 'Entradas filtradas (' . $unidad . ')', 'valor' => $ps['entradas']];
        $filasResumen[] = ['concepto' => 'Salidas filtradas (' . $unidad . ')', 'valor' => $ps['salidas']];
        $filasResumen[] = ['concepto' => 'Movimiento neto (' . $unidad . ')', 'valor' => $ps['neto']];
        $filasResumen[] = ['concepto' => 'Existencia física actual (' . $unidad . ')', 'valor' => $ps['existencia_actual']];
        $filasResumen[] = ['concepto' => 'Disponible actual (' . $unidad . ')', 'valor' => $ps['disponible_actual']];
    }

    $filasPorProducto = array_map(static function (array $r): array {
        return [
            'sku' => (string) $r['sku'],
            'producto' => (string) $r['producto'],
            'unidad' => (string) (($r['unidad_simbolo'] ?? '') ?: ($r['unidad_codigo'] ?? '')),
            'movimientos' => (int) $r['movimientos'],
            'almacenes' => (int) $r['almacenes'],
            'entradas' => (float) $r['entradas'],
            'salidas' => (float) $r['salidas'],
            'neto' => (float) $r['neto'],
        ];
    }, inv_kardex_resumen_productos_completo($conexion, $from, $where, $params));

    $filasPorTipo = array_map(static fn(array $r): array => [
        'codigo' => (string) $r['codigo'],
        'tipo' => (string) $r['nombre'],
        'movimientos' => (int) $r['movimientos'],
        'renglones' => (int) $r['renglones'],
        'productos' => (int) $r['productos'],
        'almacenes' => (int) $r['almacenes'],
    ], $resumen['por_tipo']);

    $filasPorAlmacen = array_map(static fn(array $r): array => [
        'codigo' => (string) $r['codigo'],
        'almacen' => (string) $r['nombre'],
        'movimientos' => (int) $r['movimientos'],
        'productos' => (int) $r['productos'],
        'entradas' => (int) $r['renglones_entrada'],
        'salidas' => (int) $r['renglones_salida'],
        'transferencias' => (int) $r['transferencias'],
    ], $resumen['por_almacen']);

    si_xlsx_descargar('kardex_' . date('Ymd_His') . '.xlsx', [
        [
            'nombre' => 'Kardex',
            'columnas' => inv_kardex_xlsx_columnas(),
            'filas' => $filas,
        ],
        [
            'nombre' => 'Por producto',
            'columnas' => [
                ['campo'=>'sku','titulo'=>'SKU','tipo'=>'texto','ancho'=>17],
                ['campo'=>'producto','titulo'=>'Producto','tipo'=>'texto','ancho'=>32],
                ['campo'=>'unidad','titulo'=>'Unidad','tipo'=>'texto','ancho'=>12],
                ['campo'=>'movimientos','titulo'=>'Movimientos','tipo'=>'numero','ancho'=>13],
                ['campo'=>'almacenes','titulo'=>'Almacenes','tipo'=>'numero','ancho'=>12],
                ['campo'=>'entradas','titulo'=>'Entradas','tipo'=>'numero','ancho'=>14],
                ['campo'=>'salidas','titulo'=>'Salidas','tipo'=>'numero','ancho'=>14],
                ['campo'=>'neto','titulo'=>'Neto','tipo'=>'numero','ancho'=>14],
            ],
            'filas' => $filasPorProducto,
        ],
        [
            'nombre' => 'Por tipo',
            'columnas' => [
                ['campo'=>'codigo','titulo'=>'Código','tipo'=>'texto','ancho'=>22],
                ['campo'=>'tipo','titulo'=>'Movimiento','tipo'=>'texto','ancho'=>28],
                ['campo'=>'movimientos','titulo'=>'Movimientos','tipo'=>'numero','ancho'=>13],
                ['campo'=>'renglones','titulo'=>'Renglones','tipo'=>'numero','ancho'=>12],
                ['campo'=>'productos','titulo'=>'Productos','tipo'=>'numero','ancho'=>12],
                ['campo'=>'almacenes','titulo'=>'Almacenes','tipo'=>'numero','ancho'=>12],
            ],
            'filas' => $filasPorTipo,
        ],
        [
            'nombre' => 'Por almacén',
            'columnas' => [
                ['campo'=>'codigo','titulo'=>'Código','tipo'=>'texto','ancho'=>18],
                ['campo'=>'almacen','titulo'=>'Almacén','tipo'=>'texto','ancho'=>28],
                ['campo'=>'movimientos','titulo'=>'Movimientos','tipo'=>'numero','ancho'=>13],
                ['campo'=>'productos','titulo'=>'Productos','tipo'=>'numero','ancho'=>12],
                ['campo'=>'entradas','titulo'=>'Renglones entrada','tipo'=>'numero','ancho'=>16],
                ['campo'=>'salidas','titulo'=>'Renglones salida','tipo'=>'numero','ancho'=>16],
                ['campo'=>'transferencias','titulo'=>'Transferencias','tipo'=>'numero','ancho'=>15],
            ],
            'filas' => $filasPorAlmacen,
        ],
        [
            'nombre' => 'Resumen',
            'columnas' => [
                ['campo' => 'concepto', 'titulo' => 'Concepto', 'tipo' => 'texto', 'ancho' => 34],
                ['campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'texto', 'ancho' => 28],
            ],
            'filas' => $filasResumen,
        ],
    ]);
}

function inv_kardex_resumen_productos_completo(PDO $conexion, string $from, string $where, array $params): array
{
    $sql = "SELECT p.id AS producto_id, p.sku, p.nombre AS producto,
                   um.codigo AS unidad_codigo, um.simbolo AS unidad_simbolo,
                   COUNT(DISTINCT mi.id) AS movimientos,
                   COUNT(DISTINCT mid.almacen_id) AS almacenes,
                   SUM(CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END) AS entradas,
                   SUM(CASE WHEN mid.cantidad_delta < 0 THEN ABS(mid.cantidad_delta) ELSE 0 END) AS salidas,
                   SUM(mid.cantidad_delta) AS neto
            {$from} {$where}
            GROUP BY p.id, p.sku, p.nombre, um.codigo, um.simbolo
            ORDER BY p.nombre ASC";
    $stmt = $conexion->prepare($sql);
    inv_bind_params($stmt, $params);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_kardex_total_exportacion(PDO $conexion, string $from, string $where, array $params): int
{
    $stmt = $conexion->prepare("SELECT COUNT(*) {$from} {$where}");
    inv_bind_params($stmt, $params);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function inv_kardex_validar_limite_exportacion(int $total): void
{
    if ($total > 50000) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'La exportación contiene más de 50,000 renglones. Reduce el periodo o aplica filtros antes de exportar.';
        exit;
    }
}

function inv_kardex_export_headers(): array
{
    return ['Fecha aplicación', 'Fecha operación', 'Folio movimiento', 'Estado', 'Tipo', 'Código tipo', 'SKU', 'Producto', 'Almacén', 'Código almacén', 'Entrada', 'Salida', 'Delta', 'Antes', 'Después', 'Unidad', 'Costo unitario base', 'Origen', 'Usuario', 'Motivo', 'Observaciones'];
}

function inv_kardex_export_row(array $fila): array
{
    $a = inv_kardex_export_row_assoc($fila);
    return array_values($a);
}

function inv_kardex_export_row_assoc(array $fila): array
{
    return [
        'fecha_aplicacion' => (string) ($fila['fecha_aplicacion'] ?? $fila['fecha_movimiento'] ?? ''),
        'fecha_operacion' => (string) ($fila['fecha_movimiento'] ?? ''),
        'folio' => (string) ($fila['folio'] ?? ''),
        'estado' => (string) ($fila['estado'] ?? ''),
        'tipo' => (string) ($fila['tipo_movimiento'] ?? ''),
        'tipo_codigo' => (string) ($fila['tipo_codigo'] ?? ''),
        'sku' => (string) ($fila['sku'] ?? ''),
        'producto' => (string) ($fila['producto'] ?? ''),
        'almacen' => (string) ($fila['almacen'] ?? ''),
        'almacen_codigo' => (string) ($fila['almacen_codigo'] ?? ''),
        'entrada' => (float) ($fila['cantidad_entrada'] ?? 0),
        'salida' => (float) ($fila['cantidad_salida'] ?? 0),
        'delta' => (float) ($fila['cantidad_delta'] ?? 0),
        'antes' => (float) ($fila['existencia_antes'] ?? 0),
        'despues' => (float) ($fila['existencia_despues'] ?? 0),
        'unidad' => (string) (($fila['unidad_simbolo'] ?? '') ?: ($fila['unidad_codigo'] ?? '') ?: ($fila['unidad_base'] ?? '')),
        'costo' => $fila['costo_unitario_base'] !== null ? (float) $fila['costo_unitario_base'] : '',
        'origen' => (string) ($fila['origen_referencia'] ?? ''),
        'usuario' => (string) ($fila['usuario_aplico'] ?? ''),
        'motivo' => (string) ($fila['motivo'] ?? ''),
        'observaciones' => (string) ($fila['observaciones'] ?? ''),
    ];
}

function inv_kardex_xlsx_columnas(): array
{
    return [
        ['campo'=>'fecha_aplicacion','titulo'=>'Fecha aplicación','tipo'=>'texto','ancho'=>20],
        ['campo'=>'fecha_operacion','titulo'=>'Fecha operación','tipo'=>'texto','ancho'=>20],
        ['campo'=>'folio','titulo'=>'Folio movimiento','tipo'=>'texto','ancho'=>20],
        ['campo'=>'estado','titulo'=>'Estado','tipo'=>'texto','ancho'=>12],
        ['campo'=>'tipo','titulo'=>'Movimiento','tipo'=>'texto','ancho'=>25],
        ['campo'=>'tipo_codigo','titulo'=>'Código tipo','tipo'=>'texto','ancho'=>22],
        ['campo'=>'sku','titulo'=>'SKU','tipo'=>'texto','ancho'=>17],
        ['campo'=>'producto','titulo'=>'Producto','tipo'=>'texto','ancho'=>32],
        ['campo'=>'almacen','titulo'=>'Almacén','tipo'=>'texto','ancho'=>24],
        ['campo'=>'almacen_codigo','titulo'=>'Código almacén','tipo'=>'texto','ancho'=>17],
        ['campo'=>'entrada','titulo'=>'Entrada','tipo'=>'numero','ancho'=>14],
        ['campo'=>'salida','titulo'=>'Salida','tipo'=>'numero','ancho'=>14],
        ['campo'=>'delta','titulo'=>'Delta','tipo'=>'numero','ancho'=>14],
        ['campo'=>'antes','titulo'=>'Antes','tipo'=>'numero','ancho'=>14],
        ['campo'=>'despues','titulo'=>'Después','tipo'=>'numero','ancho'=>14],
        ['campo'=>'unidad','titulo'=>'Unidad','tipo'=>'texto','ancho'=>12],
        ['campo'=>'costo','titulo'=>'Costo unitario base','tipo'=>'numero','ancho'=>18],
        ['campo'=>'origen','titulo'=>'Origen','tipo'=>'texto','ancho'=>28],
        ['campo'=>'usuario','titulo'=>'Usuario','tipo'=>'texto','ancho'=>24],
        ['campo'=>'motivo','titulo'=>'Motivo','tipo'=>'texto','ancho'=>32],
        ['campo'=>'observaciones','titulo'=>'Observaciones','tipo'=>'texto','ancho'=>36],
    ];
}


/* =========================================================================
   AJUSTES Y MERMAS
   ========================================================================= */

function inv_buscar_productos_operacion(PDO $conexion): void
{
    $buscar = inv_texto($_GET['buscar'] ?? '', 120);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $tipoOperacion = strtoupper(inv_texto($_GET['tipo_operacion'] ?? 'AJUSTE', 20));
    $tipoAjuste = strtoupper(inv_texto($_GET['tipo_ajuste'] ?? 'POSITIVO', 20));

    if (!in_array($tipoOperacion, ['AJUSTE', 'MERMA'], true)) {
        $tipoOperacion = 'AJUSTE';
    }
    if (!in_array($tipoAjuste, ['POSITIVO', 'NEGATIVO'], true)) {
        $tipoAjuste = 'POSITIVO';
    }

    if ($almacenId <= 0) {
        si_responder_json(false, 'Selecciona un almacén.', [], 422);
    }

    $stmtAlmacen = $conexion->prepare("SELECT id FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1");
    $stmtAlmacen->execute([':id' => $almacenId]);
    if (!$stmtAlmacen->fetchColumn()) {
        si_responder_json(false, 'El almacén seleccionado no está disponible.', [], 409);
    }

    $whereBuscar = '';
    $whereDisponibilidad = '';
    $params = [':almacen_id' => $almacenId];

    $esSalida = $tipoOperacion === 'MERMA' || ($tipoOperacion === 'AJUSTE' && $tipoAjuste === 'NEGATIVO');
    if ($esSalida) {
        $whereDisponibilidad = ' AND (COALESCE(e.existencia_fisica, 0) - COALESCE(e.cantidad_reservada, 0)) > 0';
    }
    if ($buscar !== '') {
        $whereBuscar = " AND (p.sku LIKE :buscar_sku OR p.nombre LIKE :buscar_nombre OR COALESCE(p.codigo_barras, '') LIKE :buscar_barra)";
        $patron = '%' . $buscar . '%';
        $params[':buscar_sku'] = $patron;
        $params[':buscar_nombre'] = $patron;
        $params[':buscar_barra'] = $patron;
    }

    $stmt = $conexion->prepare(
        "SELECT p.id, p.sku, p.nombre, p.tipo, p.permite_fraccion,
                u.nombre AS unidad_base, u.simbolo AS unidad_simbolo,
                COALESCE(e.existencia_fisica, 0) AS existencia_fisica,
                COALESCE(e.cantidad_reservada, 0) AS cantidad_reservada,
                COALESCE(e.existencia_fisica, 0) - COALESCE(e.cantidad_reservada, 0) AS cantidad_disponible
         FROM productos p
         INNER JOIN unidades_medida u ON u.id = p.unidad_base_id
         LEFT JOIN existencias_almacen e ON e.producto_id = p.id AND e.almacen_id = :almacen_id
         WHERE p.activo = 1
           AND p.controla_inventario = 1
           {$whereBuscar}
           {$whereDisponibilidad}
         ORDER BY p.nombre ASC, p.id ASC
         LIMIT 30"
    );
    inv_bind_params($stmt, $params);
    $stmt->execute();
    $productos = $stmt->fetchAll();

    foreach ($productos as &$producto) {
        $producto['id'] = (int) $producto['id'];
        $producto['permite_fraccion'] = (int) $producto['permite_fraccion'] === 1;
        $producto['existencia_fisica'] = (float) $producto['existencia_fisica'];
        $producto['cantidad_reservada'] = (float) $producto['cantidad_reservada'];
        $producto['cantidad_disponible'] = (float) $producto['cantidad_disponible'];
    }
    unset($producto);

    si_responder_json(true, 'Productos cargados.', ['productos' => $productos]);
}

function inv_listar_ajustes_mermas(PDO $conexion): void
{
    $pagina = inv_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = inv_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = inv_texto($_GET['buscar'] ?? '', 120);
    $almacenId = inv_entero_rango($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $tipo = strtoupper(inv_texto($_GET['tipo'] ?? 'TODOS', 30));
    $estado = inv_estado_movimiento($_GET['estado'] ?? 'TODOS');
    $fechaDesde = inv_fecha($_GET['fecha_desde'] ?? '');
    $fechaHasta = inv_fecha($_GET['fecha_hasta'] ?? '');

    if (!in_array($tipo, ['TODOS', 'AJUSTES', 'MERMA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'], true)) {
        $tipo = 'TODOS';
    }
    if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaDesde > $fechaHasta) {
        si_responder_json(false, 'La fecha inicial no puede ser posterior a la fecha final.', [], 422);
    }

    $where = "WHERE t.codigo IN ('MERMA','AJUSTE_POSITIVO','AJUSTE_NEGATIVO')";
    $params = [];

    if ($buscar !== '') {
        $where .= " AND (mi.folio LIKE :folio OR p.sku LIKE :sku OR p.nombre LIKE :producto OR COALESCE(mi.motivo,'') LIKE :motivo)";
        $patron = '%' . $buscar . '%';
        $params[':folio'] = $patron;
        $params[':sku'] = $patron;
        $params[':producto'] = $patron;
        $params[':motivo'] = $patron;
    }
    if ($almacenId > 0) {
        $where .= ' AND d.almacen_id = :almacen_id';
        $params[':almacen_id'] = $almacenId;
    }
    if ($tipo === 'AJUSTES') {
        $where .= " AND t.codigo IN ('AJUSTE_POSITIVO','AJUSTE_NEGATIVO')";
    } elseif ($tipo !== 'TODOS') {
        $where .= ' AND t.codigo = :tipo_codigo';
        $params[':tipo_codigo'] = $tipo;
    }
    if ($estado !== 'TODOS') {
        $where .= ' AND mi.estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($fechaDesde !== '') {
        $where .= ' AND mi.fecha_movimiento >= :desde';
        $params[':desde'] = $fechaDesde . ' 00:00:00';
    }
    if ($fechaHasta !== '') {
        $where .= ' AND mi.fecha_movimiento < :hasta';
        $params[':hasta'] = date('Y-m-d', strtotime($fechaHasta . ' +1 day')) . ' 00:00:00';
    }

    $from = "FROM movimientos_inventario mi
             INNER JOIN movimientos_inventario_detalle d ON d.movimiento_id = mi.id
             INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
             INNER JOIN productos p ON p.id = d.producto_id
             INNER JOIN unidades_medida ubase ON ubase.id = p.unidad_base_id
             INNER JOIN almacenes a ON a.id = d.almacen_id
             LEFT JOIN usuarios usr ON usr.id = COALESCE(mi.aplicado_by, mi.created_by)";

    $stmtTotal = $conexion->prepare("SELECT COUNT(*) {$from} {$where}");
    inv_bind_params($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT mi.id movimiento_id, mi.folio, mi.fecha_movimiento,
                COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) AS fecha_aplicacion,
                mi.estado, mi.motivo, mi.observaciones,
                mi.movimiento_revertido_id, t.codigo tipo_codigo, t.nombre tipo_nombre,
                d.id detalle_id, d.cantidad_delta, d.existencia_antes, d.existencia_despues,
                p.id producto_id, p.sku, p.nombre producto, ubase.nombre unidad_base, ubase.simbolo unidad_simbolo,
                a.id almacen_id, a.codigo almacen_codigo, a.nombre almacen,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', usr.nombres, usr.apellido_paterno, usr.apellido_materno)), ''), usr.usuario, 'Sistema') usuario
         {$from}
         {$where}
         ORDER BY COALESCE(mi.aplicado_at, mi.created_at, mi.fecha_movimiento) DESC, mi.id DESC, d.id DESC
         LIMIT :limite OFFSET :offset"
    );
    inv_bind_params($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $registros = $stmt->fetchAll();

    foreach ($registros as &$r) {
        foreach (['movimiento_id','detalle_id','producto_id','almacen_id'] as $campo) {
            $r[$campo] = (int) $r[$campo];
        }
        $r['movimiento_revertido_id'] = $r['movimiento_revertido_id'] !== null ? (int) $r['movimiento_revertido_id'] : null;
        foreach (['cantidad_delta','existencia_antes','existencia_despues'] as $campo) {
            $r[$campo] = (float) $r[$campo];
        }
        $r['puede_revertir'] = $r['estado'] === 'APLICADO'
            && (($r['tipo_codigo'] === 'MERMA' && si_tiene_permiso('inventario.mermas'))
                || (in_array($r['tipo_codigo'], ['AJUSTE_POSITIVO','AJUSTE_NEGATIVO'], true) && si_tiene_permiso('inventario.ajustar')));
    }
    unset($r);

    si_responder_json(true, 'Operaciones cargadas.', [
        'registros' => $registros,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function inv_registrar_ajuste(PDO $conexion): void
{
    $tipo = strtoupper(inv_texto($_POST['tipo_ajuste'] ?? '', 20));
    if (!in_array($tipo, ['POSITIVO','NEGATIVO'], true)) {
        si_responder_json(false, 'Selecciona si el ajuste aumenta o disminuye la existencia.', [], 422);
    }

    inv_registrar_operacion_stock(
        $conexion,
        $tipo === 'POSITIVO' ? 'AJUSTE_POSITIVO' : 'AJUSTE_NEGATIVO',
        'AJUSTE_INVENTARIO',
        'AJU'
    );
}

function inv_registrar_merma(PDO $conexion): void
{
    inv_registrar_operacion_stock($conexion, 'MERMA', 'MERMA', 'MER');
}

function inv_registrar_operacion_stock(PDO $conexion, string $tipoCodigo, string $origenTipo, string $prefijo): void
{
    $almacenId = inv_entero_rango($_POST['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $productoId = inv_entero_rango($_POST['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $cantidad = inv_decimal_positivo($_POST['cantidad'] ?? null);
    // La tabla ajustes_inventario admite 255 caracteres para el motivo.
    $motivo = inv_texto($_POST['motivo'] ?? '', 255);
    $observaciones = inv_texto($_POST['observaciones'] ?? '', 2000);

    if ($almacenId <= 0 || $productoId <= 0) {
        si_responder_json(false, 'Selecciona almacén y producto.', [], 422);
    }
    if ($cantidad === null) {
        si_responder_json(false, 'Captura una cantidad mayor a cero.', [], 422);
    }
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo claro de al menos 5 caracteres.', [], 422);
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT p.id, p.sku, p.nombre, p.permite_fraccion, p.controla_inventario, p.activo,
                u.nombre unidad_base, u.simbolo unidad_simbolo
         FROM productos p
         INNER JOIN unidades_medida u ON u.id = p.unidad_base_id
         WHERE p.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $productoId]);
    $producto = $stmt->fetch();
    if (!$producto || (int) $producto['activo'] !== 1 || (int) $producto['controla_inventario'] !== 1) {
        inv_cancelar_transaccion($conexion, 'El producto seleccionado ya no está disponible para inventario.', 409);
    }
    if ((int) $producto['permite_fraccion'] !== 1 && abs($cantidad - round($cantidad)) > 0.000001) {
        inv_cancelar_transaccion($conexion, 'Este producto no permite cantidades fraccionadas.', 422);
    }

    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre
         FROM almacenes
         WHERE id = :id AND activo = 1
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $almacenId]);
    $almacen = $stmt->fetch();
    if (!$almacen) {
        inv_cancelar_transaccion($conexion, 'El almacén seleccionado ya no está disponible.', 409);
    }

    $existencia = inv_bloquear_existencia_operacion(
        $conexion,
        $almacenId,
        $productoId,
        $tipoCodigo === 'AJUSTE_POSITIVO'
    );
    $antes = (float) $existencia['existencia_fisica'];
    $reservada = (float) $existencia['cantidad_reservada'];
    $esEntrada = $tipoCodigo === 'AJUSTE_POSITIVO';
    $delta = $esEntrada ? $cantidad : -$cantidad;
    $despues = round($antes + $delta, 6);

    if (!$esEntrada && $despues < $reservada - 0.000001) {
        inv_cancelar_transaccion(
            $conexion,
            'La operación dejaría la existencia física por debajo de la cantidad reservada.',
            409,
            [
                'existencia_fisica' => $antes,
                'cantidad_reservada' => $reservada,
                'cantidad_disponible' => round($antes - $reservada, 6),
                'cantidad_solicitada' => $cantidad,
            ]
        );
    }
    if ($despues > 999999999999.999999) {
        inv_cancelar_transaccion($conexion, 'La existencia resultante excede el máximo permitido.', 422);
    }

    $tipoMovimiento = inv_tipo_movimiento_codigo($conexion, $tipoCodigo);
    if (!$tipoMovimiento) {
        inv_cancelar_transaccion($conexion, 'No está configurado el tipo de movimiento ' . $tipoCodigo . '.', 500);
    }

    $usuarioId = (int) $_SESSION['usuario_id'];
    $folioTemporal = 'TMP-' . $prefijo . '-' . bin2hex(random_bytes(8));

    // Documento operativo: conserva la razón administrativa del ajuste o merma.
    $stmtAjuste = $conexion->prepare(
        "INSERT INTO ajustes_inventario
            (folio, tipo, fecha_ajuste, estado, motivo, observaciones, confirmado_at, confirmado_by, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'CONFIRMADO', :motivo, :observaciones, NOW(), :confirmado_by, :created_by)"
    );
    $stmtAjuste->execute([
        ':folio' => $folioTemporal,
        ':tipo' => $tipoCodigo,
        ':motivo' => $motivo,
        ':observaciones' => $observaciones !== '' ? $observaciones : null,
        ':confirmado_by' => $usuarioId,
        ':created_by' => $usuarioId,
    ]);
    $ajusteId = (int) $conexion->lastInsertId();
    $folio = $prefijo . '-' . str_pad((string) $ajusteId, 7, '0', STR_PAD_LEFT);

    $conexion->prepare("UPDATE ajustes_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folio, ':id' => $ajusteId]);

    $observacionDetalle = $observaciones !== '' ? inv_texto($observaciones, 255) : null;
    $conexion->prepare(
        "INSERT INTO ajustes_inventario_detalle
            (ajuste_id, renglon, almacen_id, producto_id, cantidad_base, observaciones)
         VALUES
            (:ajuste, 1, :almacen, :producto, :cantidad, :observaciones)"
    )->execute([
        ':ajuste' => $ajusteId,
        ':almacen' => $almacenId,
        ':producto' => $productoId,
        // El signo lo determina ajustes_inventario.tipo; aquí se conserva la magnitud.
        ':cantidad' => $cantidad,
        ':observaciones' => $observacionDetalle,
    ]);

    // Kardex: registra el efecto físico con signo y queda ligado al documento.
    $stmtMov = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id, idempotency_key, motivo, observaciones, aplicado_at, aplicado_by, created_by)
         VALUES
            (:folio, :tipo_movimiento, NOW(), 'APLICADO', :origen_tipo, :origen_id, :idempotency_key, :motivo, :observaciones, NOW(), :aplicado_by, :created_by)"
    );
    $stmtMov->execute([
        ':folio' => $folio,
        ':tipo_movimiento' => (int) $tipoMovimiento['id'],
        ':origen_tipo' => $origenTipo,
        ':origen_id' => $ajusteId,
        ':idempotency_key' => 'AJUSTE_INVENTARIO:' . $ajusteId,
        ':motivo' => $motivo,
        ':observaciones' => $observaciones !== '' ? $observaciones : null,
        ':aplicado_by' => $usuarioId,
        ':created_by' => $usuarioId,
    ]);
    $movimientoId = (int) $conexion->lastInsertId();

    $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :fisica
         WHERE id = :id"
    )->execute([
        ':fisica' => $despues,
        ':id' => (int) $existencia['id'],
    ]);

    $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, 1, :almacen, :producto, :delta, :antes, :despues, :costo, :observaciones)"
    )->execute([
        ':movimiento' => $movimientoId,
        ':almacen' => $almacenId,
        ':producto' => $productoId,
        ':delta' => $delta,
        ':antes' => $antes,
        ':despues' => $despues,
        ':costo' => $existencia['costo_promedio_base'],
        ':observaciones' => $observacionDetalle,
    ]);

    inv_auditar_operacion(
        $conexion,
        $tipoCodigo,
        $ajusteId,
        'Se aplicó ' . $folio . ' sobre ' . $producto['nombre'] . '.',
        null,
        [
            'folio' => $folio,
            'movimiento_id' => $movimientoId,
            'almacen_id' => $almacenId,
            'producto_id' => $productoId,
            'cantidad' => $cantidad,
            'delta' => $delta,
            'existencia_antes' => $antes,
            'existencia_despues' => $despues,
            'cantidad_reservada' => $reservada,
            'motivo' => $motivo,
        ],
        'ajustes_inventario'
    );

    $conexion->commit();
    si_responder_json(
        true,
        $tipoCodigo === 'MERMA' ? 'Merma registrada correctamente.' : 'Ajuste registrado correctamente.',
        [
            'ajuste_id' => $ajusteId,
            'movimiento_id' => $movimientoId,
            'folio' => $folio,
            'existencia_antes' => $antes,
            'existencia_despues' => $despues,
        ],
        201
    );
}

function inv_revertir_operacion(PDO $conexion): void
{
    $movimientoId = inv_entero_rango($_POST['movimiento_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $motivo = inv_texto($_POST['motivo'] ?? '', 1000);
    if ($movimientoId <= 0) {
        si_responder_json(false, 'El movimiento no es válido.', [], 422);
    }
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo de reverso de al menos 5 caracteres.', [], 422);
    }

    $conexion->beginTransaction();
    $stmt = $conexion->prepare(
        "SELECT mi.*, t.codigo tipo_codigo, t.nombre tipo_nombre
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario t ON t.id = mi.tipo_movimiento_id
         WHERE mi.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $movimientoId]);
    $movimiento = $stmt->fetch();
    if (!$movimiento) {
        inv_cancelar_transaccion($conexion, 'El movimiento ya no existe.', 404);
    }
    if (!in_array($movimiento['tipo_codigo'], ['MERMA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'], true)) {
        inv_cancelar_transaccion($conexion, 'Este movimiento no puede revertirse desde Ajustes y mermas.', 409);
    }
    if ($movimiento['tipo_codigo'] === 'MERMA') {
        if (!si_tiene_permiso('inventario.mermas')) {
            inv_cancelar_transaccion($conexion, 'No tienes permiso para revertir mermas.', 403);
        }
    } elseif (!si_tiene_permiso('inventario.ajustar')) {
        inv_cancelar_transaccion($conexion, 'No tienes permiso para revertir ajustes.', 403);
    }

    if ($movimiento['estado'] === 'REVERTIDO') {
        $conexion->commit();
        si_responder_json(true, 'El movimiento ya estaba revertido.');
    }
    if ($movimiento['estado'] !== 'APLICADO') {
        inv_cancelar_transaccion($conexion, 'Solo los movimientos APLICADOS pueden revertirse.', 409);
    }

    // Los movimientos nuevos de este módulo deben apuntar a su documento operativo.
    $ajuste = null;
    if (
        in_array((string) $movimiento['origen_tipo'], ['AJUSTE_INVENTARIO', 'MERMA'], true)
        && (int) ($movimiento['origen_id'] ?? 0) > 0
    ) {
        $stmtAjuste = $conexion->prepare(
            "SELECT *
             FROM ajustes_inventario
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmtAjuste->execute([':id' => (int) $movimiento['origen_id']]);
        $ajuste = $stmtAjuste->fetch() ?: null;

        if (!$ajuste) {
            inv_cancelar_transaccion($conexion, 'El documento de ajuste relacionado no existe. No se realizará un reverso incompleto.', 409);
        }
        if ((string) $ajuste['tipo'] !== (string) $movimiento['tipo_codigo']) {
            inv_cancelar_transaccion($conexion, 'El documento y el Kardex no coinciden en el tipo de operación.', 409);
        }
        if ((string) $ajuste['estado'] !== 'CONFIRMADO') {
            inv_cancelar_transaccion($conexion, 'El documento de ajuste ya no está confirmado.', 409);
        }
    }

    $stmtDet = $conexion->prepare(
        "SELECT *
         FROM movimientos_inventario_detalle
         WHERE movimiento_id = :id
         ORDER BY renglon ASC
         FOR UPDATE"
    );
    $stmtDet->execute([':id' => $movimientoId]);
    $detalles = $stmtDet->fetchAll();
    if (!$detalles) {
        inv_cancelar_transaccion($conexion, 'El movimiento no contiene detalle de inventario.', 409);
    }

    $tipoReverso = inv_tipo_movimiento_codigo($conexion, 'REVERSO');
    if (!$tipoReverso) {
        inv_cancelar_transaccion($conexion, 'No está configurado el tipo de movimiento REVERSO.', 500);
    }

    $usuarioId = (int) $_SESSION['usuario_id'];
    $folioTemporal = 'TMP-REV-' . bin2hex(random_bytes(8));
    $stmtRev = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id, idempotency_key, movimiento_revertido_id, motivo, aplicado_at, aplicado_by, created_by)
         VALUES
            (:folio, :tipo_movimiento, NOW(), 'APLICADO', 'REVERSO_AJUSTE_MERMA', :origen_id, :idempotency_key, :movimiento_revertido_id, :motivo, NOW(), :aplicado_by, :created_by)"
    );
    $stmtRev->execute([
        ':folio' => $folioTemporal,
        ':tipo_movimiento' => (int) $tipoReverso['id'],
        ':origen_id' => $ajuste ? (int) $ajuste['id'] : $movimientoId,
        ':idempotency_key' => 'REVERSO_AJUSTE_MERMA:' . $movimientoId,
        ':movimiento_revertido_id' => $movimientoId,
        ':motivo' => $motivo,
        ':aplicado_by' => $usuarioId,
        ':created_by' => $usuarioId,
    ]);
    $reversoId = (int) $conexion->lastInsertId();
    $folioReverso = 'REV-' . str_pad((string) $reversoId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare("UPDATE movimientos_inventario SET folio = :folio WHERE id = :id")
        ->execute([':folio' => $folioReverso, ':id' => $reversoId]);

    $insert = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, :renglon, :almacen, :producto, :delta, :antes, :despues, :costo, :observaciones)"
    );

    foreach ($detalles as $i => $d) {
        $existencia = inv_bloquear_existencia_operacion(
            $conexion,
            (int) $d['almacen_id'],
            (int) $d['producto_id'],
            false
        );
        $antes = (float) $existencia['existencia_fisica'];
        $reservada = (float) $existencia['cantidad_reservada'];
        $delta = -((float) $d['cantidad_delta']);
        $despues = round($antes + $delta, 6);

        if ($despues < $reservada - 0.000001) {
            inv_cancelar_transaccion(
                $conexion,
                'El reverso dejaría existencia física por debajo de lo reservado. Libera o regulariza las reservas antes de revertir.',
                409
            );
        }
        if ($despues > 999999999999.999999) {
            inv_cancelar_transaccion($conexion, 'El reverso excedería el máximo de existencia permitido.', 422);
        }

        $conexion->prepare(
            "UPDATE existencias_almacen
             SET existencia_fisica = :fisica
             WHERE id = :id"
        )->execute([
            ':fisica' => $despues,
            ':id' => (int) $existencia['id'],
        ]);

        $insert->execute([
            ':movimiento' => $reversoId,
            ':renglon' => $i + 1,
            ':almacen' => (int) $d['almacen_id'],
            ':producto' => (int) $d['producto_id'],
            ':delta' => $delta,
            ':antes' => $antes,
            ':despues' => $despues,
            ':costo' => $d['costo_unitario_base'],
            ':observaciones' => inv_texto('Reverso de ' . $movimiento['folio'], 255),
        ]);
    }

    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET estado = 'REVERTIDO'
         WHERE id = :id"
    )->execute([':id' => $movimientoId]);

    if ($ajuste) {
        $conexion->prepare(
            "UPDATE ajustes_inventario
             SET estado = 'CANCELADO',
                 motivo_cancelacion = :motivo_cancelacion,
                 cancelado_at = NOW(),
                 cancelado_by = :cancelado_by
             WHERE id = :id"
        )->execute([
            ':motivo_cancelacion' => $motivo,
            ':cancelado_by' => $usuarioId,
            ':id' => (int) $ajuste['id'],
        ]);
    }

    $entidadId = $ajuste ? (int) $ajuste['id'] : $movimientoId;
    $entidadTabla = $ajuste ? 'ajustes_inventario' : 'movimientos_inventario';
    inv_auditar_operacion(
        $conexion,
        'REVERSO_INVENTARIO',
        $entidadId,
        'Se revirtió el movimiento ' . $movimiento['folio'] . '.',
        [
            'movimiento_id' => $movimientoId,
            'folio' => $movimiento['folio'],
            'estado' => 'APLICADO',
            'documento_estado' => $ajuste['estado'] ?? null,
        ],
        [
            'movimiento_id' => $movimientoId,
            'estado' => 'REVERTIDO',
            'reverso_id' => $reversoId,
            'reverso_folio' => $folioReverso,
            'documento_estado' => $ajuste ? 'CANCELADO' : null,
            'motivo' => $motivo,
        ],
        $entidadTabla
    );

    $conexion->commit();
    si_responder_json(true, 'Movimiento revertido correctamente.', [
        'ajuste_id' => $ajuste ? (int) $ajuste['id'] : null,
        'reverso_id' => $reversoId,
        'folio' => $folioReverso,
    ]);
}

function inv_bloquear_existencia_operacion(PDO $conexion, int $almacenId, int $productoId, bool $crearSiNoExiste): array
{
    $stmt = $conexion->prepare(
        "SELECT id, existencia_fisica, cantidad_reservada, costo_promedio_base
         FROM existencias_almacen
         WHERE almacen_id = :almacen AND producto_id = :producto
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    $fila = $stmt->fetch();
    if ($fila) {
        return $fila;
    }
    if (!$crearSiNoExiste) {
        inv_cancelar_transaccion($conexion, 'No existe un registro de existencia para este producto en el almacén.', 409);
    }

    try {
        $conexion->prepare(
            "INSERT INTO existencias_almacen (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo)
             VALUES (:almacen, :producto, 0, 0, 0)"
        )->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23000') {
            throw $e;
        }
    }

    $stmt->execute([':almacen' => $almacenId, ':producto' => $productoId]);
    $fila = $stmt->fetch();
    if (!$fila) {
        inv_cancelar_transaccion($conexion, 'No fue posible preparar la existencia del producto.', 409);
    }
    return $fila;
}

function inv_tipo_movimiento_codigo(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare("SELECT id, codigo, nombre FROM tipos_movimiento_inventario WHERE codigo = :codigo AND activo = 1 LIMIT 1");
    $stmt->execute([':codigo' => $codigo]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function inv_cancelar_transaccion(PDO $conexion, string $mensaje, int $status = 409, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $datos, $status);
}

function inv_auditar_operacion(
    PDO $conexion,
    string $accion,
    int $entidadId,
    string $descripcion,
    ?array $antes,
    ?array $nuevos,
    string $entidadTabla = 'movimientos_inventario'
): void {
    if (!in_array($entidadTabla, ['movimientos_inventario', 'ajustes_inventario', 'existencias_almacen'], true)) {
        $entidadTabla = 'movimientos_inventario';
    }

    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario, :accion, 'Inventario', :entidad_tabla, :entidad_id, :descripcion, :datos_anteriores, :datos_nuevos, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':entidad_tabla' => $entidadTabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => inv_texto($descripcion, 500),
        ':datos_anteriores' => $antes !== null
            ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ':datos_nuevos' => $nuevos !== null
            ? json_encode($nuevos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ':ip' => inv_texto($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
        ':user_agent' => inv_texto($_SERVER['HTTP_USER_AGENT'] ?? '', 500) ?: null,
    ]);
}

function inv_decimal_no_negativo($valor): ?float
{
    $texto = trim((string) $valor);
    if ($texto === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $texto)) {
        return null;
    }
    $numero = (float) $texto;
    if (!is_finite($numero) || $numero < 0 || $numero > 999999999999.999999) {
        return null;
    }
    return round($numero, 6);
}

function inv_decimal_positivo($valor): ?float
{
    $texto = trim((string) $valor);
    if ($texto === '' || !preg_match('/^\d+(?:\.\d{1,6})?$/', $texto)) {
        return null;
    }
    $numero = (float) $texto;
    if (!is_finite($numero) || $numero <= 0 || $numero > 999999999999.0) {
        return null;
    }
    return round($numero, 6);
}

/* =========================================================================
   FILTROS / SQL COMPARTIDO
   ========================================================================= */

function inv_filtros_base_existencias(
    int $almacenId,
    string $tipoProducto,
    string $estadoProducto,
    string $buscar
): array {
    $where = "WHERE p.controla_inventario = 1
              AND a.activo = 1";
    $params = [];

    if ($almacenId > 0) {
        $where .= ' AND a.id = :almacen_id';
        $params[':almacen_id'] = $almacenId;
    }

    if ($tipoProducto !== 'TODOS') {
        $where .= ' AND p.tipo = :tipo_producto';
        $params[':tipo_producto'] = $tipoProducto;
    }

    if ($estadoProducto === 'ACTIVO') {
        $where .= ' AND p.activo = 1';
    } elseif ($estadoProducto === 'INACTIVO') {
        $where .= ' AND p.activo = 0';
    }

    if ($buscar !== '') {
        $where .= " AND (
            p.sku LIKE :buscar_sku
            OR p.nombre LIKE :buscar_producto
            OR COALESCE(p.codigo_barras, '') LIKE :buscar_barra
        )";
        $patron = '%' . $buscar . '%';
        $params[':buscar_sku'] = $patron;
        $params[':buscar_producto'] = $patron;
        $params[':buscar_barra'] = $patron;
    }

    return [$where, $params];
}

function inv_sql_disponible(): string
{
    return '(COALESCE(ea.existencia_fisica, 0) - COALESCE(ea.cantidad_reservada, 0))';
}

function inv_sql_estado_stock(): string
{
    $disponible = inv_sql_disponible();

    return "CASE
        WHEN COALESCE(ea.existencia_fisica, 0) <= 0 THEN 'SIN_STOCK'
        WHEN {$disponible} <= 0 THEN 'SIN_DISPONIBLE'
        WHEN COALESCE(ea.stock_minimo, 0) > 0
             AND {$disponible} <= COALESCE(ea.stock_minimo, 0) THEN 'CRITICO'
        WHEN ea.punto_reorden IS NOT NULL
             AND ea.punto_reorden > 0
             AND {$disponible} <= ea.punto_reorden THEN 'REORDEN'
        ELSE 'NORMAL'
    END";
}

/* =========================================================================
   VALIDACIÓN / CONVERSIÓN
   ========================================================================= */

function inv_entero_rango($valor, int $min, int $max, int $predeterminado): int
{
    if (is_int($valor)) {
        $numero = $valor;
    } elseif (is_string($valor) && preg_match('/^-?\d+$/', trim($valor))) {
        $numero = (int) trim($valor);
    } else {
        return $predeterminado;
    }

    if ($numero < $min || $numero > $max) {
        return $predeterminado;
    }

    return $numero;
}

function inv_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($texto, 0, $maximo, 'UTF-8');
    }

    return substr($texto, 0, $maximo);
}

function inv_tipo_producto($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'MATERIA_PRIMA', 'PRODUCTO_TERMINADO'], true)
        ? $valor
        : 'TODOS';
}

function inv_estado_producto($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'ACTIVO', 'INACTIVO'], true)
        ? $valor
        : 'TODOS';
}

function inv_estado_stock($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array(
        $valor,
        ['TODOS', 'NORMAL', 'REORDEN', 'CRITICO', 'SIN_DISPONIBLE', 'SIN_STOCK'],
        true
    ) ? $valor : 'TODOS';
}

function inv_estado_movimiento($valor): string
{
    $valor = strtoupper(trim((string) $valor));
    return in_array($valor, ['TODOS', 'APLICADO', 'REVERTIDO', 'BORRADOR'], true)
        ? $valor
        : 'TODOS';
}

function inv_fecha($valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '';
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $errores = DateTimeImmutable::getLastErrors();

    if (!$fecha || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
        return '';
    }

    return $fecha->format('Y-m-d') === $valor ? $valor : '';
}

function inv_bind_params(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}
