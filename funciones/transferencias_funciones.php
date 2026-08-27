<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('inventario.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                tra_catalogos($conexion);
                break;

            case 'LISTAR':
                tra_listar($conexion);
                break;

            case 'BUSCAR_PRODUCTOS':
                si_requerir_permiso('inventario.transferir', true);
                tra_buscar_productos($conexion);
                break;

            case 'DETALLE':
                tra_detalle($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'REGISTRAR':
            si_requerir_permiso('inventario.transferir', true);
            tra_registrar($conexion);
            break;

        case 'REVERTIR':
            si_requerir_permiso('inventario.transferir', true);
            tra_revertir($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'TRA-' . date('Ymd-His');
    error_log('[' . $referencia . '][TRANSFERENCIAS][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible procesar la transferencia.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'TRA-' . date('Ymd-His');
    error_log('[' . $referencia . '][TRANSFERENCIAS] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al procesar transferencias.', ['referencia' => $referencia], 500);
}

function tra_catalogos(PDO $conexion): void
{
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE activo = 1
         ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    foreach ($almacenes as &$almacen) {
        $almacen['id'] = (int) $almacen['id'];
    }
    unset($almacen);

    $stmtResumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN mi.estado = 'APLICADO' THEN 1 ELSE 0 END) AS aplicadas,
            SUM(CASE WHEN mi.estado = 'REVERTIDO' THEN 1 ELSE 0 END) AS revertidas
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         WHERE tmi.codigo = 'TRANSFERENCIA'"
    );
    $resumen = $stmtResumen->fetch() ?: [];

    si_responder_json(true, 'Catálogos cargados.', [
        'almacenes' => $almacenes,
        'puede_transferir' => si_tiene_permiso('inventario.transferir'),
        'resumen' => [
            'total' => (int) ($resumen['total'] ?? 0),
            'aplicadas' => (int) ($resumen['aplicadas'] ?? 0),
            'revertidas' => (int) ($resumen['revertidas'] ?? 0),
        ],
    ]);
}

function tra_listar(PDO $conexion): void
{
    $pagina = tra_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = tra_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $buscar = tra_texto($_GET['buscar'] ?? '', 160);
    $almacenId = tra_entero($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $estado = strtoupper(tra_texto($_GET['estado'] ?? 'TODAS', 20));
    if (!in_array($estado, ['TODAS', 'APLICADO', 'REVERTIDO'], true)) {
        $estado = 'TODAS';
    }

    $where = "WHERE tmi.codigo = 'TRANSFERENCIA'";
    $params = [];

    if ($estado !== 'TODAS') {
        $where .= ' AND mi.estado = :estado';
        $params[':estado'] = $estado;
    }
    if ($almacenId > 0) {
        $where .= " AND EXISTS (
            SELECT 1 FROM movimientos_inventario_detalle mida
            WHERE mida.movimiento_id = mi.id AND mida.almacen_id = :almacen_id
        )";
        $params[':almacen_id'] = $almacenId;
    }
    if ($buscar !== '') {
        $where .= " AND (
            mi.folio LIKE :buscar_folio
            OR mi.motivo LIKE :buscar_motivo
            OR mi.observaciones LIKE :buscar_observaciones
            OR EXISTS (
                SELECT 1
                FROM movimientos_inventario_detalle midb
                INNER JOIN productos pb ON pb.id = midb.producto_id
                INNER JOIN almacenes ab ON ab.id = midb.almacen_id
                WHERE midb.movimiento_id = mi.id
                  AND (
                    pb.sku LIKE :buscar_sku
                    OR pb.nombre LIKE :buscar_producto
                    OR ab.codigo LIKE :buscar_almacen_codigo
                    OR ab.nombre LIKE :buscar_almacen_nombre
                  )
            )
        )";
        $patron = '%' . $buscar . '%';
        $params[':buscar_folio'] = $patron;
        $params[':buscar_motivo'] = $patron;
        $params[':buscar_observaciones'] = $patron;
        $params[':buscar_sku'] = $patron;
        $params[':buscar_producto'] = $patron;
        $params[':buscar_almacen_codigo'] = $patron;
        $params[':buscar_almacen_nombre'] = $patron;
    }

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         {$where}"
    );
    tra_bind($stmtTotal, $params);
    $stmtTotal->execute();
    $total = (int) $stmtTotal->fetchColumn();

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $sql = "SELECT
                mi.id,
                mi.folio,
                mi.fecha_movimiento,
                mi.estado,
                mi.motivo,
                mi.observaciones,
                mi.created_by,
                TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)) AS usuario,
                ao.id AS origen_id,
                ao.codigo AS origen_codigo,
                ao.nombre AS origen,
                ad.id AS destino_id,
                ad.codigo AS destino_codigo,
                ad.nombre AS destino,
                x.productos,
                x.total_unidades,
                x.renglones
            FROM movimientos_inventario mi
            INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
            INNER JOIN usuarios u ON u.id = mi.created_by
            INNER JOIN (
                SELECT
                    mid.movimiento_id,
                    MIN(CASE WHEN mid.cantidad_delta < 0 THEN mid.almacen_id END) AS origen_id,
                    MIN(CASE WHEN mid.cantidad_delta > 0 THEN mid.almacen_id END) AS destino_id,
                    COUNT(DISTINCT mid.producto_id) AS productos,
                    COALESCE(SUM(CASE WHEN mid.cantidad_delta > 0 THEN mid.cantidad_delta ELSE 0 END), 0) AS total_unidades,
                    COUNT(*) AS renglones
                FROM movimientos_inventario_detalle mid
                GROUP BY mid.movimiento_id
            ) x ON x.movimiento_id = mi.id
            LEFT JOIN almacenes ao ON ao.id = x.origen_id
            LEFT JOIN almacenes ad ON ad.id = x.destino_id
            {$where}
            ORDER BY mi.fecha_movimiento DESC, mi.id DESC
            LIMIT :limite OFFSET :offset";

    $stmt = $conexion->prepare($sql);
    tra_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['created_by'] = (int) $fila['created_by'];
        $fila['origen_id'] = $fila['origen_id'] !== null ? (int) $fila['origen_id'] : null;
        $fila['destino_id'] = $fila['destino_id'] !== null ? (int) $fila['destino_id'] : null;
        $fila['productos'] = (int) $fila['productos'];
        $fila['total_unidades'] = (float) $fila['total_unidades'];
        $fila['renglones'] = (int) $fila['renglones'];
    }
    unset($fila);

    si_responder_json(true, 'Transferencias cargadas.', [
        'registros' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'total_paginas' => $totalPaginas,
        ],
    ]);
}

function tra_buscar_productos(PDO $conexion): void
{
    $almacenId = tra_entero($_GET['almacen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $buscar = tra_texto($_GET['q'] ?? '', 120);
    if ($almacenId <= 0) {
        si_responder_json(false, 'Selecciona un almacén de origen.', [], 422);
    }
    if (mb_strlen($buscar) < 2) {
        si_responder_json(true, 'Captura al menos 2 caracteres.', ['productos' => []]);
    }

    $stmtAlmacen = $conexion->prepare('SELECT id FROM almacenes WHERE id = :id AND activo = 1 LIMIT 1');
    $stmtAlmacen->execute([':id' => $almacenId]);
    if (!$stmtAlmacen->fetchColumn()) {
        si_responder_json(false, 'El almacén de origen ya no está disponible.', [], 409);
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.permite_fraccion,
            um.nombre AS unidad_base,
            um.simbolo AS unidad_simbolo,
            COALESCE(ea.existencia_fisica, 0) AS existencia_fisica,
            COALESCE(ea.cantidad_reservada, 0) AS cantidad_reservada,
            COALESCE(ea.cantidad_disponible, 0) AS cantidad_disponible
         FROM productos p
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         LEFT JOIN existencias_almacen ea
            ON ea.producto_id = p.id
           AND ea.almacen_id = :almacen_id
         WHERE p.activo = 1
           AND p.controla_inventario = 1
           AND (p.sku LIKE :buscar_sku OR p.nombre LIKE :buscar_nombre)
         ORDER BY
            CASE WHEN COALESCE(ea.cantidad_disponible, 0) > 0 THEN 0 ELSE 1 END,
            p.nombre ASC,
            p.id ASC
         LIMIT 20"
    );
    $stmt->execute([
        ':almacen_id' => $almacenId,
        ':buscar_sku' => '%' . $buscar . '%',
        ':buscar_nombre' => '%' . $buscar . '%',
    ]);
    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['permite_fraccion'] = (int) $p['permite_fraccion'];
        $p['existencia_fisica'] = (float) $p['existencia_fisica'];
        $p['cantidad_reservada'] = (float) $p['cantidad_reservada'];
        $p['cantidad_disponible'] = (float) $p['cantidad_disponible'];
    }
    unset($p);

    si_responder_json(true, 'Productos encontrados.', ['productos' => $productos]);
}

function tra_detalle(PDO $conexion): void
{
    $id = tra_entero($_GET['id'] ?? 0, 1, PHP_INT_MAX, 0);
    if ($id <= 0) {
        si_responder_json(false, 'La transferencia no es válida.', [], 422);
    }

    $stmt = $conexion->prepare(
        "SELECT
            mi.id, mi.folio, mi.fecha_movimiento, mi.estado, mi.motivo, mi.observaciones,
            mi.aplicado_at, mi.created_at,
            TRIM(CONCAT_WS(' ', u.nombres, u.apellido_paterno, u.apellido_materno)) AS usuario
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         INNER JOIN usuarios u ON u.id = mi.created_by
         WHERE mi.id = :id AND tmi.codigo = 'TRANSFERENCIA'
         LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $cabecera = $stmt->fetch();
    if (!$cabecera) {
        si_responder_json(false, 'No se encontró la transferencia.', [], 404);
    }

    $stmtDet = $conexion->prepare(
        "SELECT
            mid.renglon,
            mid.almacen_id,
            a.codigo AS almacen_codigo,
            a.nombre AS almacen,
            mid.producto_id,
            p.sku,
            p.nombre AS producto,
            um.simbolo AS unidad_simbolo,
            mid.cantidad_delta,
            mid.existencia_antes,
            mid.existencia_despues,
            mid.costo_unitario_base,
            mid.observaciones
         FROM movimientos_inventario_detalle mid
         INNER JOIN almacenes a ON a.id = mid.almacen_id
         INNER JOIN productos p ON p.id = mid.producto_id
         INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
         WHERE mid.movimiento_id = :id
         ORDER BY mid.producto_id ASC, mid.cantidad_delta ASC, mid.renglon ASC"
    );
    $stmtDet->execute([':id' => $id]);
    $filas = $stmtDet->fetchAll();

    $productos = [];
    $origen = null;
    $destino = null;
    foreach ($filas as $fila) {
        $productoId = (int) $fila['producto_id'];
        if (!isset($productos[$productoId])) {
            $productos[$productoId] = [
                'producto_id' => $productoId,
                'sku' => $fila['sku'],
                'producto' => $fila['producto'],
                'unidad_simbolo' => $fila['unidad_simbolo'],
                'cantidad' => 0.0,
                'origen_antes' => null,
                'origen_despues' => null,
                'destino_antes' => null,
                'destino_despues' => null,
                'costo_unitario_base' => $fila['costo_unitario_base'] !== null ? (float) $fila['costo_unitario_base'] : null,
            ];
        }

        $delta = (float) $fila['cantidad_delta'];
        if ($delta < 0) {
            $origen = [
                'id' => (int) $fila['almacen_id'],
                'codigo' => $fila['almacen_codigo'],
                'nombre' => $fila['almacen'],
            ];
            $productos[$productoId]['cantidad'] += abs($delta);
            $productos[$productoId]['origen_antes'] = (float) $fila['existencia_antes'];
            $productos[$productoId]['origen_despues'] = (float) $fila['existencia_despues'];
        } elseif ($delta > 0) {
            $destino = [
                'id' => (int) $fila['almacen_id'],
                'codigo' => $fila['almacen_codigo'],
                'nombre' => $fila['almacen'],
            ];
            $productos[$productoId]['destino_antes'] = (float) $fila['existencia_antes'];
            $productos[$productoId]['destino_despues'] = (float) $fila['existencia_despues'];
        }
    }

    $cabecera['id'] = (int) $cabecera['id'];
    si_responder_json(true, 'Detalle cargado.', [
        'transferencia' => $cabecera,
        'origen' => $origen,
        'destino' => $destino,
        'productos' => array_values($productos),
        'puede_revertir' => si_tiene_permiso('inventario.transferir') && $cabecera['estado'] === 'APLICADO',
    ]);
}

function tra_registrar(PDO $conexion): void
{
    $origenId = tra_entero($_POST['origen_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $destinoId = tra_entero($_POST['destino_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $motivo = tra_texto($_POST['motivo'] ?? '', 255);
    $observaciones = tra_texto($_POST['observaciones'] ?? '', 2000);
    $idempotencyKey = tra_idempotency_key($_POST['idempotency_key'] ?? '');
    $detalles = tra_detalles_post($_POST['detalles'] ?? '');

    if ($origenId <= 0 || $destinoId <= 0) {
        si_responder_json(false, 'Selecciona almacén de origen y destino.', [], 422);
    }
    if ($origenId === $destinoId) {
        si_responder_json(false, 'El almacén de origen y el destino deben ser diferentes.', [], 422);
    }
    if (mb_strlen($motivo) < 3) {
        si_responder_json(false, 'Captura un motivo de al menos 3 caracteres.', [], 422);
    }
    if ($idempotencyKey === '') {
        si_responder_json(false, 'No fue posible identificar de forma segura esta solicitud. Cierra y vuelve a abrir el formulario.', [], 422);
    }
    if ($detalles === []) {
        si_responder_json(false, 'Agrega al menos un producto a la transferencia.', [], 422);
    }

    // Si el navegador reintenta exactamente la misma confirmación, devolvemos el movimiento ya creado.
    $existente = tra_buscar_por_idempotencia($conexion, $idempotencyKey);
    if ($existente) {
        si_responder_json(true, 'La transferencia ya había sido registrada.', [
            'movimiento_id' => (int) $existente['id'],
            'folio' => $existente['folio'],
            'reutilizada' => true,
        ]);
    }

    $conexion->beginTransaction();

    $almacenes = tra_bloquear_almacenes($conexion, [$origenId, $destinoId]);
    if (!isset($almacenes[$origenId]) || !isset($almacenes[$destinoId])) {
        tra_cancelar($conexion, 'Uno de los almacenes ya no está activo o disponible.', 409);
    }

    $productoIds = array_column($detalles, 'producto_id');
    $productos = tra_bloquear_productos($conexion, $productoIds);
    if (count($productos) !== count($productoIds)) {
        tra_cancelar($conexion, 'Uno de los productos ya no está disponible para inventario.', 409);
    }

    // Prepara filas de destino sin alterar cantidades. El UNIQUE almacén/producto evita duplicados concurrentes.
    foreach ($productoIds as $productoId) {
        try {
            $conexion->prepare(
                "INSERT INTO existencias_almacen
                    (almacen_id, producto_id, existencia_fisica, cantidad_reservada, stock_minimo, punto_reorden, costo_promedio_base)
                 VALUES
                    (:almacen, :producto, 0, 0, 0, NULL, NULL)"
            )->execute([':almacen' => $destinoId, ':producto' => $productoId]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    $existencias = tra_bloquear_existencias($conexion, [$origenId, $destinoId], $productoIds);

    $tipoMovimiento = tra_tipo_movimiento($conexion, 'TRANSFERENCIA');
    if (!$tipoMovimiento) {
        tra_cancelar($conexion, 'No está configurado el tipo de movimiento TRANSFERENCIA.', 500);
    }

    $preparados = [];
    foreach ($detalles as $detalle) {
        $productoId = $detalle['producto_id'];
        $cantidad = $detalle['cantidad'];
        $producto = $productos[$productoId];
        $claveOrigen = $origenId . ':' . $productoId;
        $claveDestino = $destinoId . ':' . $productoId;

        if (!isset($existencias[$claveOrigen])) {
            tra_cancelar($conexion, 'El producto ' . $producto['nombre'] . ' no tiene existencia registrada en el almacén de origen.', 409);
        }
        if (!isset($existencias[$claveDestino])) {
            tra_cancelar($conexion, 'No fue posible preparar el inventario del almacén destino.', 409);
        }
        if ((int) $producto['permite_fraccion'] !== 1 && abs($cantidad - round($cantidad)) > 0.000001) {
            tra_cancelar($conexion, 'El producto ' . $producto['nombre'] . ' no permite cantidades fraccionadas.', 422);
        }

        $origen = $existencias[$claveOrigen];
        $destino = $existencias[$claveDestino];
        $origenAntes = (float) $origen['existencia_fisica'];
        $reservadaOrigen = (float) $origen['cantidad_reservada'];
        $disponibleOrigen = round($origenAntes - $reservadaOrigen, 6);
        if ($cantidad > $disponibleOrigen + 0.000001) {
            tra_cancelar($conexion, 'No hay existencia disponible suficiente de ' . $producto['nombre'] . ' en el almacén de origen.', 409, [
                'producto_id' => $productoId,
                'existencia_fisica' => $origenAntes,
                'cantidad_reservada' => $reservadaOrigen,
                'cantidad_disponible' => $disponibleOrigen,
                'cantidad_solicitada' => $cantidad,
            ]);
        }

        $origenDespues = round($origenAntes - $cantidad, 6);
        $destinoAntes = (float) $destino['existencia_fisica'];
        $destinoDespues = round($destinoAntes + $cantidad, 6);
        if ($destinoDespues > 999999999999.999999) {
            tra_cancelar($conexion, 'La existencia destino de ' . $producto['nombre'] . ' excedería el máximo permitido.', 422);
        }

        $costoTransferencia = $origen['costo_promedio_base'] !== null
            ? (float) $origen['costo_promedio_base']
            : ($destino['costo_promedio_base'] !== null ? (float) $destino['costo_promedio_base'] : null);
        $costoDestinoNuevo = tra_costo_promedio_entrada(
            $destinoAntes,
            $destino['costo_promedio_base'] !== null ? (float) $destino['costo_promedio_base'] : null,
            $cantidad,
            $costoTransferencia
        );

        $preparados[] = [
            'producto_id' => $productoId,
            'producto' => $producto['nombre'],
            'sku' => $producto['sku'],
            'cantidad' => $cantidad,
            'origen' => $origen,
            'destino' => $destino,
            'origen_antes' => $origenAntes,
            'origen_despues' => $origenDespues,
            'reservada_origen' => $reservadaOrigen,
            'destino_antes' => $destinoAntes,
            'destino_despues' => $destinoDespues,
            'costo_transferencia' => $costoTransferencia,
            'costo_destino_nuevo' => $costoDestinoNuevo,
        ];
    }

    $usuarioId = (int) $_SESSION['usuario_id'];
    $folioTemporal = 'TMP-TRF-' . bin2hex(random_bytes(8));
    $stmtMov = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id, idempotency_key, motivo, observaciones, aplicado_at, aplicado_by, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'APLICADO', 'TRANSFERENCIA', NULL, :idempotency_key, :motivo, :observaciones, NOW(), :aplicado_by, :created_by)"
    );

    try {
        $stmtMov->execute([
            ':folio' => $folioTemporal,
            ':tipo' => (int) $tipoMovimiento['id'],
            ':idempotency_key' => $idempotencyKey,
            ':motivo' => $motivo,
            ':observaciones' => $observaciones !== '' ? $observaciones : null,
            ':aplicado_by' => $usuarioId,
            ':created_by' => $usuarioId,
        ]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            $conexion->rollBack();
            $existente = tra_buscar_por_idempotencia($conexion, $idempotencyKey);
            if ($existente) {
                si_responder_json(true, 'La transferencia ya había sido registrada.', [
                    'movimiento_id' => (int) $existente['id'],
                    'folio' => $existente['folio'],
                    'reutilizada' => true,
                ]);
            }
        }
        throw $e;
    }

    $movimientoId = (int) $conexion->lastInsertId();
    $folio = 'TRF-' . str_pad((string) $movimientoId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare(
        "UPDATE movimientos_inventario
         SET folio = :folio, origen_id = :origen_id
         WHERE id = :id"
    )->execute([
        ':folio' => $folio,
        ':origen_id' => $movimientoId,
        ':id' => $movimientoId,
    ]);

    $stmtUpdate = $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :fisica,
             costo_promedio_base = :costo
         WHERE id = :id"
    );
    $stmtDetalle = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, :renglon, :almacen, :producto, :delta, :antes, :despues, :costo, :observaciones)"
    );

    $renglon = 1;
    $auditoriaDetalles = [];
    foreach ($preparados as $p) {
        $stmtUpdate->execute([
            ':fisica' => $p['origen_despues'],
            ':costo' => $p['origen']['costo_promedio_base'],
            ':id' => (int) $p['origen']['id'],
        ]);
        $stmtUpdate->execute([
            ':fisica' => $p['destino_despues'],
            ':costo' => $p['costo_destino_nuevo'],
            ':id' => (int) $p['destino']['id'],
        ]);

        $obsSalida = tra_texto('Transferencia ' . $folio . ' hacia ' . $almacenes[$destinoId]['nombre'], 255);
        $stmtDetalle->execute([
            ':movimiento' => $movimientoId,
            ':renglon' => $renglon++,
            ':almacen' => $origenId,
            ':producto' => $p['producto_id'],
            ':delta' => -$p['cantidad'],
            ':antes' => $p['origen_antes'],
            ':despues' => $p['origen_despues'],
            ':costo' => $p['costo_transferencia'],
            ':observaciones' => $obsSalida,
        ]);

        $obsEntrada = tra_texto('Transferencia ' . $folio . ' desde ' . $almacenes[$origenId]['nombre'], 255);
        $stmtDetalle->execute([
            ':movimiento' => $movimientoId,
            ':renglon' => $renglon++,
            ':almacen' => $destinoId,
            ':producto' => $p['producto_id'],
            ':delta' => $p['cantidad'],
            ':antes' => $p['destino_antes'],
            ':despues' => $p['destino_despues'],
            ':costo' => $p['costo_transferencia'],
            ':observaciones' => $obsEntrada,
        ]);

        $auditoriaDetalles[] = [
            'producto_id' => $p['producto_id'],
            'sku' => $p['sku'],
            'producto' => $p['producto'],
            'cantidad' => $p['cantidad'],
            'origen_antes' => $p['origen_antes'],
            'origen_despues' => $p['origen_despues'],
            'reservado_origen' => $p['reservada_origen'],
            'destino_antes' => $p['destino_antes'],
            'destino_despues' => $p['destino_despues'],
        ];
    }

    tra_auditar($conexion, 'TRANSFERENCIA_ALMACEN', $movimientoId, 'Se registró la transferencia ' . $folio . '.', null, [
        'folio' => $folio,
        'almacen_origen_id' => $origenId,
        'almacen_origen' => $almacenes[$origenId]['nombre'],
        'almacen_destino_id' => $destinoId,
        'almacen_destino' => $almacenes[$destinoId]['nombre'],
        'motivo' => $motivo,
        'detalles' => $auditoriaDetalles,
    ]);

    $conexion->commit();
    si_responder_json(true, 'Transferencia registrada correctamente.', [
        'movimiento_id' => $movimientoId,
        'folio' => $folio,
    ], 201);
}

function tra_revertir(PDO $conexion): void
{
    $movimientoId = tra_entero($_POST['movimiento_id'] ?? 0, 1, PHP_INT_MAX, 0);
    $motivo = tra_texto($_POST['motivo'] ?? '', 1000);
    if ($movimientoId <= 0) {
        si_responder_json(false, 'La transferencia no es válida.', [], 422);
    }
    if (mb_strlen($motivo) < 5) {
        si_responder_json(false, 'Captura un motivo de reverso de al menos 5 caracteres.', [], 422);
    }

    $conexion->beginTransaction();
    $stmt = $conexion->prepare(
        "SELECT mi.*, tmi.codigo AS tipo_codigo
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         WHERE mi.id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $movimientoId]);
    $mov = $stmt->fetch();
    if (!$mov || $mov['tipo_codigo'] !== 'TRANSFERENCIA') {
        tra_cancelar($conexion, 'No se encontró una transferencia válida.', 404);
    }
    if ($mov['estado'] === 'REVERTIDO') {
        $stmtRevExistente = $conexion->prepare(
            "SELECT id, folio
             FROM movimientos_inventario
             WHERE movimiento_revertido_id = :id
             ORDER BY id DESC LIMIT 1"
        );
        $stmtRevExistente->execute([':id' => $movimientoId]);
        $rev = $stmtRevExistente->fetch();
        $conexion->commit();
        si_responder_json(true, 'La transferencia ya estaba revertida.', [
            'reverso_id' => $rev ? (int) $rev['id'] : null,
            'folio' => $rev['folio'] ?? null,
        ]);
    }
    if ($mov['estado'] !== 'APLICADO') {
        tra_cancelar($conexion, 'Solo las transferencias aplicadas pueden revertirse.', 409);
    }

    $stmtDet = $conexion->prepare(
        "SELECT *
         FROM movimientos_inventario_detalle
         WHERE movimiento_id = :id
         ORDER BY almacen_id ASC, producto_id ASC, renglon ASC
         FOR UPDATE"
    );
    $stmtDet->execute([':id' => $movimientoId]);
    $detalles = $stmtDet->fetchAll();
    if (!$detalles || count($detalles) < 2 || count($detalles) % 2 !== 0) {
        tra_cancelar($conexion, 'El detalle de la transferencia está incompleto; no se hará un reverso parcial.', 409);
    }

    $almacenIds = array_values(array_unique(array_map(static fn(array $d): int => (int) $d['almacen_id'], $detalles)));
    $productoIds = array_values(array_unique(array_map(static fn(array $d): int => (int) $d['producto_id'], $detalles)));
    if (count($almacenIds) !== 2) {
        tra_cancelar($conexion, 'La transferencia no tiene exactamente un almacén de origen y uno de destino.', 409);
    }

    $almacenes = tra_bloquear_almacenes($conexion, $almacenIds, false);
    if (count($almacenes) !== 2) {
        tra_cancelar($conexion, 'Uno de los almacenes relacionados ya no existe; no se hará un reverso incompleto.', 409);
    }
    $existencias = tra_bloquear_existencias($conexion, $almacenIds, $productoIds);

    // Primero validamos todo; ningún renglón se aplica hasta saber que el reverso completo es posible.
    $cambios = [];
    foreach ($detalles as $d) {
        $almacenId = (int) $d['almacen_id'];
        $productoId = (int) $d['producto_id'];
        $clave = $almacenId . ':' . $productoId;
        if (!isset($existencias[$clave])) {
            tra_cancelar($conexion, 'Falta la existencia actual de uno de los productos de la transferencia.', 409);
        }

        $exist = $existencias[$clave];
        $deltaReverso = -((float) $d['cantidad_delta']);
        $antes = (float) $exist['existencia_fisica'];
        $reservada = (float) $exist['cantidad_reservada'];
        $despues = round($antes + $deltaReverso, 6);

        if ($despues < $reservada - 0.000001) {
            tra_cancelar($conexion, 'No se puede revertir: parte del inventario recibido por la transferencia ya está reservado o no está disponible.', 409, [
                'almacen_id' => $almacenId,
                'producto_id' => $productoId,
                'existencia_fisica' => $antes,
                'cantidad_reservada' => $reservada,
                'cantidad_disponible' => round($antes - $reservada, 6),
                'salida_requerida' => abs(min(0.0, $deltaReverso)),
            ]);
        }
        if ($despues > 999999999999.999999) {
            tra_cancelar($conexion, 'El reverso excedería el máximo de existencia permitido.', 422);
        }

        $costoEntrada = $d['costo_unitario_base'] !== null ? (float) $d['costo_unitario_base'] : null;
        $costoNuevo = $exist['costo_promedio_base'] !== null ? (float) $exist['costo_promedio_base'] : null;
        if ($deltaReverso > 0) {
            $costoNuevo = tra_costo_promedio_entrada($antes, $costoNuevo, $deltaReverso, $costoEntrada);
        }

        $cambios[] = [
            'detalle' => $d,
            'existencia' => $exist,
            'delta' => $deltaReverso,
            'antes' => $antes,
            'despues' => $despues,
            'costo_nuevo' => $costoNuevo,
        ];
    }

    // Un transfer válido debe balancear cada producto a cero entre sus dos almacenes.
    $balance = [];
    foreach ($detalles as $d) {
        $pid = (int) $d['producto_id'];
        $balance[$pid] = round(($balance[$pid] ?? 0.0) + (float) $d['cantidad_delta'], 6);
    }
    foreach ($balance as $pid => $delta) {
        if (abs($delta) > 0.000001) {
            tra_cancelar($conexion, 'La transferencia original no está balanceada; no se hará un reverso inseguro.', 409, ['producto_id' => $pid]);
        }
    }

    $tipoReverso = tra_tipo_movimiento($conexion, 'REVERSO');
    if (!$tipoReverso) {
        tra_cancelar($conexion, 'No está configurado el tipo de movimiento REVERSO.', 500);
    }

    $usuarioId = (int) $_SESSION['usuario_id'];
    $folioTmp = 'TMP-REV-TRF-' . bin2hex(random_bytes(8));
    $stmtRev = $conexion->prepare(
        "INSERT INTO movimientos_inventario
            (folio, tipo_movimiento_id, fecha_movimiento, estado, origen_tipo, origen_id, idempotency_key, movimiento_revertido_id, motivo, aplicado_at, aplicado_by, created_by)
         VALUES
            (:folio, :tipo, NOW(), 'APLICADO', 'REVERSO_TRANSFERENCIA', :origen_id, :idempotency_key, :revertido_id, :motivo, NOW(), :aplicado_by, :created_by)"
    );
    $stmtRev->execute([
        ':folio' => $folioTmp,
        ':tipo' => (int) $tipoReverso['id'],
        ':origen_id' => $movimientoId,
        ':idempotency_key' => 'REVERSO_TRANSFERENCIA:' . $movimientoId,
        ':revertido_id' => $movimientoId,
        ':motivo' => $motivo,
        ':aplicado_by' => $usuarioId,
        ':created_by' => $usuarioId,
    ]);
    $reversoId = (int) $conexion->lastInsertId();
    $folioReverso = 'REV-' . str_pad((string) $reversoId, 7, '0', STR_PAD_LEFT);
    $conexion->prepare('UPDATE movimientos_inventario SET folio = :folio WHERE id = :id')
        ->execute([':folio' => $folioReverso, ':id' => $reversoId]);

    $stmtUpdate = $conexion->prepare(
        "UPDATE existencias_almacen
         SET existencia_fisica = :fisica, costo_promedio_base = :costo
         WHERE id = :id"
    );
    $stmtInsert = $conexion->prepare(
        "INSERT INTO movimientos_inventario_detalle
            (movimiento_id, renglon, almacen_id, producto_id, cantidad_delta, existencia_antes, existencia_despues, costo_unitario_base, observaciones)
         VALUES
            (:movimiento, :renglon, :almacen, :producto, :delta, :antes, :despues, :costo, :observaciones)"
    );

    foreach ($cambios as $i => $c) {
        $d = $c['detalle'];
        $stmtUpdate->execute([
            ':fisica' => $c['despues'],
            ':costo' => $c['costo_nuevo'],
            ':id' => (int) $c['existencia']['id'],
        ]);
        $stmtInsert->execute([
            ':movimiento' => $reversoId,
            ':renglon' => $i + 1,
            ':almacen' => (int) $d['almacen_id'],
            ':producto' => (int) $d['producto_id'],
            ':delta' => $c['delta'],
            ':antes' => $c['antes'],
            ':despues' => $c['despues'],
            ':costo' => $d['costo_unitario_base'],
            ':observaciones' => tra_texto('Reverso de transferencia ' . $mov['folio'], 255),
        ]);
    }

    $conexion->prepare("UPDATE movimientos_inventario SET estado = 'REVERTIDO' WHERE id = :id")
        ->execute([':id' => $movimientoId]);

    tra_auditar($conexion, 'REVERSO_TRANSFERENCIA', $movimientoId, 'Se revirtió la transferencia ' . $mov['folio'] . '.', [
        'estado' => 'APLICADO',
        'folio' => $mov['folio'],
    ], [
        'estado' => 'REVERTIDO',
        'reverso_id' => $reversoId,
        'reverso_folio' => $folioReverso,
        'motivo' => $motivo,
    ]);

    $conexion->commit();
    si_responder_json(true, 'Transferencia revertida correctamente.', [
        'reverso_id' => $reversoId,
        'folio' => $folioReverso,
    ]);
}

function tra_detalles_post($valor): array
{
    if (is_string($valor)) {
        $decodificado = json_decode($valor, true);
    } elseif (is_array($valor)) {
        $decodificado = $valor;
    } else {
        $decodificado = null;
    }
    if (!is_array($decodificado)) {
        return [];
    }

    $salida = [];
    $vistos = [];
    foreach ($decodificado as $fila) {
        if (!is_array($fila)) {
            continue;
        }
        $productoId = tra_entero($fila['producto_id'] ?? 0, 1, PHP_INT_MAX, 0);
        $cantidad = tra_decimal_positivo($fila['cantidad'] ?? null);
        if ($productoId <= 0 || $cantidad === null) {
            si_responder_json(false, 'Hay un producto o una cantidad inválida en la transferencia.', [], 422);
        }
        if (isset($vistos[$productoId])) {
            si_responder_json(false, 'Un mismo producto no puede repetirse en la transferencia.', ['producto_id' => $productoId], 422);
        }
        $vistos[$productoId] = true;
        $salida[] = ['producto_id' => $productoId, 'cantidad' => $cantidad];
        if (count($salida) > 100) {
            si_responder_json(false, 'Una transferencia no puede contener más de 100 productos.', [], 422);
        }
    }

    usort($salida, static fn(array $a, array $b): int => $a['producto_id'] <=> $b['producto_id']);
    return $salida;
}

function tra_bloquear_almacenes(PDO $conexion, array $ids, bool $soloActivos = true): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    if ($ids === []) {
        return [];
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $condicionActivo = $soloActivos ? 'activo = 1 AND ' : '';
    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre, ubicacion
         FROM almacenes
         WHERE {$condicionActivo}id IN ({$marcas})
         ORDER BY id ASC
         FOR UPDATE"
    );
    foreach ($ids as $i => $id) {
        $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $filas = [];
    foreach ($stmt->fetchAll() as $f) {
        $filas[(int) $f['id']] = $f;
    }
    return $filas;
}

function tra_bloquear_productos(PDO $conexion, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    if ($ids === []) {
        return [];
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conexion->prepare(
        "SELECT id, sku, nombre, permite_fraccion
         FROM productos
         WHERE id IN ({$marcas})
           AND activo = 1
           AND controla_inventario = 1
         ORDER BY id ASC
         FOR UPDATE"
    );
    foreach ($ids as $i => $id) {
        $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $filas = [];
    foreach ($stmt->fetchAll() as $f) {
        $f['id'] = (int) $f['id'];
        $f['permite_fraccion'] = (int) $f['permite_fraccion'];
        $filas[$f['id']] = $f;
    }
    return $filas;
}

function tra_bloquear_existencias(PDO $conexion, array $almacenIds, array $productoIds): array
{
    $almacenIds = array_values(array_unique(array_map('intval', $almacenIds)));
    $productoIds = array_values(array_unique(array_map('intval', $productoIds)));
    sort($almacenIds, SORT_NUMERIC);
    sort($productoIds, SORT_NUMERIC);
    if ($almacenIds === [] || $productoIds === []) {
        return [];
    }

    $ma = implode(',', array_fill(0, count($almacenIds), '?'));
    $mp = implode(',', array_fill(0, count($productoIds), '?'));
    $stmt = $conexion->prepare(
        "SELECT id, almacen_id, producto_id, existencia_fisica, cantidad_reservada, costo_promedio_base
         FROM existencias_almacen
         WHERE almacen_id IN ({$ma}) AND producto_id IN ({$mp})
         ORDER BY almacen_id ASC, producto_id ASC
         FOR UPDATE"
    );
    $pos = 1;
    foreach ($almacenIds as $id) {
        $stmt->bindValue($pos++, $id, PDO::PARAM_INT);
    }
    foreach ($productoIds as $id) {
        $stmt->bindValue($pos++, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $salida = [];
    foreach ($stmt->fetchAll() as $f) {
        $salida[(int) $f['almacen_id'] . ':' . (int) $f['producto_id']] = $f;
    }
    return $salida;
}

function tra_tipo_movimiento(PDO $conexion, string $codigo): ?array
{
    $stmt = $conexion->prepare(
        'SELECT id, codigo, nombre FROM tipos_movimiento_inventario WHERE codigo = :codigo AND activo = 1 LIMIT 1'
    );
    $stmt->execute([':codigo' => $codigo]);
    return $stmt->fetch() ?: null;
}

function tra_buscar_por_idempotencia(PDO $conexion, string $clave): ?array
{
    $stmt = $conexion->prepare(
        "SELECT mi.id, mi.folio, mi.estado
         FROM movimientos_inventario mi
         INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
         WHERE mi.idempotency_key = :clave
           AND tmi.codigo = 'TRANSFERENCIA'
         LIMIT 1"
    );
    $stmt->execute([':clave' => $clave]);
    return $stmt->fetch() ?: null;
}

function tra_costo_promedio_entrada(float $existenciaActual, ?float $costoActual, float $entrada, ?float $costoEntrada): ?float
{
    if ($costoEntrada === null) {
        return $costoActual;
    }
    if ($existenciaActual <= 0.000001 || $costoActual === null) {
        return round($costoEntrada, 6);
    }
    $denominador = $existenciaActual + $entrada;
    if ($denominador <= 0.000001) {
        return $costoActual;
    }
    return round((($existenciaActual * $costoActual) + ($entrada * $costoEntrada)) / $denominador, 6);
}

function tra_auditar(PDO $conexion, string $accion, int $entidadId, string $descripcion, ?array $antes, ?array $nuevos): void
{
    $stmt = $conexion->prepare(
        "INSERT INTO auditoria
            (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
         VALUES
            (:usuario, :accion, 'Inventario', 'movimientos_inventario', :entidad_id, :descripcion, :anteriores, :nuevos, :ip, :user_agent)"
    );
    $stmt->execute([
        ':usuario' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':entidad_id' => $entidadId,
        ':descripcion' => tra_texto($descripcion, 500),
        ':anteriores' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':nuevos' => $nuevos !== null ? json_encode($nuevos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':ip' => tra_texto($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
        ':user_agent' => tra_texto($_SERVER['HTTP_USER_AGENT'] ?? '', 500) ?: null,
    ]);
}

function tra_cancelar(PDO $conexion, string $mensaje, int $status = 409, array $datos = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    si_responder_json(false, $mensaje, $datos, $status);
}

function tra_idempotency_key($valor): string
{
    $valor = trim((string) $valor);
    if ($valor === '' || strlen($valor) > 120 || !preg_match('/^[A-Za-z0-9._:-]{16,120}$/', $valor)) {
        return '';
    }
    return $valor;
}

function tra_decimal_positivo($valor): ?float
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

function tra_entero($valor, int $min, int $max, int $default): int
{
    $filtro = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    return $filtro === false ? $default : (int) $filtro;
}

function tra_texto($valor, int $max): string
{
    $texto = trim((string) $valor);
    if (mb_strlen($texto) > $max) {
        $texto = mb_substr($texto, 0, $max);
    }
    return $texto;
}

function tra_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
