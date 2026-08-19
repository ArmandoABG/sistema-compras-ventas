<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('productos.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(
        false,
        'No fue posible conectar con la base de datos.',
        [],
        503
    );
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_PRODUCTOS')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'LISTAR_PRODUCTOS':
                cat_listar_productos($conexion);
                break;

            case 'DETALLE_PRODUCTO':
                cat_detalle_producto($conexion);
                break;

            case 'CATALOGOS':
                cat_catalogos($conexion);
                break;

            case 'BUSCAR_PRODUCTOS':
                cat_buscar_productos($conexion);
                break;

            case 'LISTAR_CATEGORIAS':
                cat_listar_categorias($conexion);
                break;

            case 'DETALLE_CATEGORIA':
                cat_detalle_categoria($conexion);
                break;

            case 'LISTAR_UNIDADES':
                cat_listar_unidades($conexion);
                break;

            case 'DETALLE_UNIDAD':
                cat_detalle_unidad($conexion);
                break;

            case 'LISTAR_PRESENTACIONES':
                cat_listar_presentaciones($conexion);
                break;

            case 'DETALLE_PRESENTACION':
                cat_detalle_presentacion($conexion);
                break;

            case 'LISTAR_PRECIOS_VENTA':
                cat_listar_precios_venta($conexion);
                break;

            case 'DETALLE_PRECIO_VENTA':
                cat_detalle_precio_venta($conexion);
                break;

            case 'OPCIONES_PRECIO_PRODUCTO':
                cat_opciones_precio_producto($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('productos.administrar')) {
        si_responder_json(
            false,
            'No tienes permiso para administrar estos catálogos.',
            [],
            403
        );
    }

    switch ($accion) {
        case 'GUARDAR_PRODUCTO':
            cat_guardar_producto($conexion);
            break;

        case 'CAMBIAR_ESTADO_PRODUCTO':
            cat_cambiar_estado_producto($conexion);
            break;

        case 'PAPELERA_PRODUCTO':
            cat_papelera_producto($conexion);
            break;

        case 'GUARDAR_CATEGORIA':
            cat_guardar_categoria($conexion);
            break;

        case 'CAMBIAR_ESTADO_CATEGORIA':
            cat_cambiar_estado_categoria($conexion);
            break;

        case 'PAPELERA_CATEGORIA':
            cat_papelera_categoria($conexion);
            break;

        case 'GUARDAR_UNIDAD':
            cat_guardar_unidad($conexion);
            break;

        case 'CAMBIAR_ESTADO_UNIDAD':
            cat_cambiar_estado_unidad($conexion);
            break;

        case 'GUARDAR_PRESENTACION':
            cat_guardar_presentacion($conexion);
            break;

        case 'CAMBIAR_ESTADO_PRESENTACION':
            cat_cambiar_estado_presentacion($conexion);
            break;

        case 'GUARDAR_PRECIO_VENTA':
            cat_guardar_precio_venta($conexion);
            break;

        case 'DESACTIVAR_PRECIO_VENTA':
            cat_desactivar_precio_venta($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CAT-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CATALOGOS][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'Ya existe un registro con esos datos o el registro está siendo utilizado por otro módulo.',
            ['referencia' => $referencia],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible procesar la operación.',
        ['referencia' => $referencia],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CAT-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CATALOGOS] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar el catálogo.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   PRODUCTOS
   ========================================================================= */

function cat_listar_productos(PDO $conexion): void
{
    $pagina = cat_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cat_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $busqueda = cat_texto($_GET['busqueda'] ?? '', 140);
    $tipo = strtoupper(cat_texto($_GET['tipo'] ?? 'TODOS', 30));
    $estado = strtoupper(cat_texto($_GET['estado'] ?? 'TODOS', 20));
    $categoriaId = cat_entero_rango($_GET['categoria_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($tipo, ['TODOS', 'MATERIA_PRIMA', 'PRODUCTO_TERMINADO'], true)) {
        $tipo = 'TODOS';
    }

    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS'], true)) {
        $estado = 'TODOS';
    }

    $where = ['p.deleted_at IS NULL'];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(
            p.sku = :codigo_exacto
            OR p.sku LIKE :codigo_prefijo
            OR p.nombre LIKE :nombre_contiene
        )";

        $params[':codigo_exacto'] = $busqueda;
        $params[':codigo_prefijo'] = $busqueda . '%';
        $params[':nombre_contiene'] = '%' . $busqueda . '%';
    }

    if ($tipo !== 'TODOS') {
        $where[] = 'p.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }

    if ($estado === 'ACTIVOS') {
        $where[] = 'p.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'p.activo = 0';
    }

    if ($categoriaId > 0) {
        $where[] = 'p.categoria_id = :categoria_id';
        $params[':categoria_id'] = $categoriaId;
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM productos p
         WHERE {$whereSql}"
    );

    cat_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.categoria_id,
            c.nombre AS categoria_nombre,
            p.unidad_base_id,
            um.codigo AS unidad_codigo,
            um.nombre AS unidad_nombre,
            um.simbolo AS unidad_simbolo,
            ti.nombre AS impuesto_nombre,
            ti.porcentaje AS impuesto_porcentaje,
            p.controla_inventario,
            p.permite_fraccion,
            p.activo,
            (
                SELECT COUNT(*)
                FROM presentaciones_producto pp
                WHERE pp.producto_id = p.id
                  AND pp.activo = 1
            ) AS presentaciones_activas,
            (
                SELECT COUNT(*)
                FROM precios_venta_producto pv
                WHERE pv.producto_id = p.id
                  AND pv.activo = 1
                  AND pv.vigente_desde <= NOW()
                  AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
            ) AS precios_vigentes,
            (
                SELECT COUNT(*)
                FROM precios_venta_producto pv
                WHERE pv.producto_id = p.id
                  AND pv.nivel_precio = 'MENUDEO'
                  AND pv.activo = 1
                  AND pv.vigente_desde <= NOW()
                  AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
            ) AS precios_menudeo_vigentes,
            (
                SELECT COUNT(*)
                FROM precios_venta_producto pv
                WHERE pv.producto_id = p.id
                  AND pv.nivel_precio = 'MAYOREO'
                  AND pv.activo = 1
                  AND pv.vigente_desde <= NOW()
                  AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
            ) AS precios_mayoreo_vigentes
         FROM productos p
         LEFT JOIN categorias_productos c
            ON c.id = p.categoria_id
         INNER JOIN unidades_medida um
            ON um.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti
            ON ti.id = p.tasa_impuesto_id
         WHERE {$whereSql}
         ORDER BY
            p.activo DESC,
            p.nombre ASC,
            p.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cat_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['categoria_id'] = $p['categoria_id'] !== null ? (int) $p['categoria_id'] : null;
        $p['unidad_base_id'] = (int) $p['unidad_base_id'];
        $p['impuesto_porcentaje'] = $p['impuesto_porcentaje'] !== null
            ? (float) $p['impuesto_porcentaje']
            : null;
        $p['controla_inventario'] = (int) $p['controla_inventario'];
        $p['permite_fraccion'] = (int) $p['permite_fraccion'];
        $p['activo'] = (int) $p['activo'];
        $p['presentaciones_activas'] = (int) $p['presentaciones_activas'];
        $p['precios_vigentes'] = (int) $p['precios_vigentes'];
        $p['precios_menudeo_vigentes'] = (int) $p['precios_menudeo_vigentes'];
        $p['precios_mayoreo_vigentes'] = (int) $p['precios_mayoreo_vigentes'];
    }
    unset($p);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(p.tipo = 'MATERIA_PRIMA') AS materias_primas,
            SUM(p.tipo = 'PRODUCTO_TERMINADO') AS productos_terminados,
            SUM(p.activo = 1) AS activos,
            SUM(p.activo = 0) AS inactivos,
            SUM(
                p.activo = 1
                AND NOT EXISTS (
                    SELECT 1
                    FROM precios_venta_producto pv
                    WHERE pv.producto_id = p.id
                      AND pv.activo = 1
                      AND pv.vigente_desde <= NOW()
                      AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())
                )
            ) AS sin_precio_vigente
         FROM productos p
         WHERE p.deleted_at IS NULL"
    )->fetch();

    si_responder_json(
        true,
        'Productos cargados correctamente.',
        [
            'productos' => $productos,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => [
                'total' => (int) ($resumen['total'] ?? 0),
                'materias_primas' => (int) ($resumen['materias_primas'] ?? 0),
                'productos_terminados' => (int) ($resumen['productos_terminados'] ?? 0),
                'activos' => (int) ($resumen['activos'] ?? 0),
                'inactivos' => (int) ($resumen['inactivos'] ?? 0),
                'sin_precio_vigente' => (int) ($resumen['sin_precio_vigente'] ?? 0),
            ],
        ]
    );
}

function cat_detalle_producto(PDO $conexion): void
{
    $id = cat_id($_GET['id'] ?? null, 'producto');

    $stmt = $conexion->prepare(
        "SELECT
            id,
            sku,
            nombre,
            descripcion,
            tipo,
            categoria_id,
            unidad_base_id,
            tasa_impuesto_id,
            controla_inventario,
            permite_fraccion,
            activo
         FROM productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();

    if (!$p) {
        si_responder_json(false, 'No se encontró el producto seleccionado.', [], 404);
    }

    $p['id'] = (int) $p['id'];
    $p['categoria_id'] = $p['categoria_id'] !== null ? (int) $p['categoria_id'] : null;
    $p['unidad_base_id'] = (int) $p['unidad_base_id'];
    $p['tasa_impuesto_id'] = $p['tasa_impuesto_id'] !== null ? (int) $p['tasa_impuesto_id'] : null;
    $p['controla_inventario'] = (int) $p['controla_inventario'];
    $p['permite_fraccion'] = (int) $p['permite_fraccion'];
    $p['activo'] = (int) $p['activo'];

    si_responder_json(true, 'Producto encontrado.', ['producto' => $p]);
}

function cat_guardar_producto(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['producto_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cat_id($idTexto, 'producto');
    $esNuevo = $id === 0;

    $nombre = cat_requerido(
        $_POST['nombre'] ?? '',
        'El nombre del producto es obligatorio.',
        180
    );

    $descripcion = cat_nullable($_POST['descripcion'] ?? '', 5000);
    $tipo = strtoupper(trim((string) ($_POST['tipo'] ?? '')));

    if (!in_array($tipo, ['MATERIA_PRIMA', 'PRODUCTO_TERMINADO'], true)) {
        si_responder_json(
            false,
            'Selecciona un tipo de producto válido.',
            ['campo' => 'tipo'],
            422
        );
    }

    $categoriaTexto = trim((string) ($_POST['categoria_id'] ?? ''));
    $categoriaId = $categoriaTexto === '' ? null : cat_id($categoriaTexto, 'categoría');

    $unidadBaseId = cat_id($_POST['unidad_base_id'] ?? null, 'unidad base');

    $impuestoTexto = trim((string) ($_POST['tasa_impuesto_id'] ?? ''));
    $tasaImpuestoId = $impuestoTexto === '' ? null : cat_id($impuestoTexto, 'impuesto');

    $controlaInventario = cat_bool($_POST['controla_inventario'] ?? 0);
    $permiteFraccion = cat_bool($_POST['permite_fraccion'] ?? 0);

    $conexion->beginTransaction();

    $anterior = null;
    $sku = '';

    if (!$esNuevo) {
        $anterior = cat_bloquear_producto($conexion, $id);

        if (!$anterior) {
            cat_cancelar($conexion, 'El producto ya no existe.', 404);
        }

        $sku = (string) $anterior['sku'];
    }

    cat_validar_categoria($conexion, $categoriaId);

    cat_validar_unidad(
        $conexion,
        $unidadBaseId,
        !$esNuevo && (int) $anterior['unidad_base_id'] === $unidadBaseId
    );

    cat_validar_impuesto($conexion, $tasaImpuestoId);

    if (
        !$esNuevo
        && (int) $anterior['unidad_base_id'] !== $unidadBaseId
        && cat_producto_tiene_historial_inventario($conexion, $id)
    ) {
        cat_cancelar(
            $conexion,
            'La unidad base ya no puede cambiarse porque el producto tiene existencias o movimientos de inventario.',
            409,
            ['campo' => 'unidad_base_id']
        );
    }

    if ($esNuevo) {
        /*
         * La columna sku es obligatoria en la BD, pero para el usuario es un
         * código interno inteligente. Se usa un valor temporal y después el
         * AUTO_INCREMENT real para generar PROD-000001, PROD-000002, etc.
         */
        $skuTemporal = 'TMP-' . strtoupper(bin2hex(random_bytes(12)));

        $stmt = $conexion->prepare(
            "INSERT INTO productos
                (
                    sku,
                    nombre,
                    descripcion,
                    tipo,
                    categoria_id,
                    unidad_base_id,
                    tasa_impuesto_id,
                    controla_inventario,
                    permite_fraccion,
                    activo
                )
             VALUES
                (
                    :sku,
                    :nombre,
                    :descripcion,
                    :tipo,
                    :categoria_id,
                    :unidad_base_id,
                    :tasa_impuesto_id,
                    :controla_inventario,
                    :permite_fraccion,
                    1
                )"
        );

        $stmt->execute([
            ':sku' => $skuTemporal,
            ':nombre' => $nombre,
            ':descripcion' => $descripcion,
            ':tipo' => $tipo,
            ':categoria_id' => $categoriaId,
            ':unidad_base_id' => $unidadBaseId,
            ':tasa_impuesto_id' => $tasaImpuestoId,
            ':controla_inventario' => $controlaInventario,
            ':permite_fraccion' => $permiteFraccion,
        ]);

        $id = (int) $conexion->lastInsertId();
        $sku = cat_generar_codigo_producto($conexion, $id);

        $conexion->prepare(
            "UPDATE productos
             SET sku = :sku
             WHERE id = :id"
        )->execute([
            ':sku' => $sku,
            ':id' => $id,
        ]);

    } else {
        $stmt = $conexion->prepare(
            "UPDATE productos
             SET
                nombre = :nombre,
                descripcion = :descripcion,
                tipo = :tipo,
                categoria_id = :categoria_id,
                unidad_base_id = :unidad_base_id,
                tasa_impuesto_id = :tasa_impuesto_id,
                controla_inventario = :controla_inventario,
                permite_fraccion = :permite_fraccion
             WHERE id = :id
               AND deleted_at IS NULL"
        );

        $stmt->execute([
            ':nombre' => $nombre,
            ':descripcion' => $descripcion,
            ':tipo' => $tipo,
            ':categoria_id' => $categoriaId,
            ':unidad_base_id' => $unidadBaseId,
            ':tasa_impuesto_id' => $tasaImpuestoId,
            ':controla_inventario' => $controlaInventario,
            ':permite_fraccion' => $permiteFraccion,
            ':id' => $id,
        ]);
    }

    $nuevo = [
        'sku' => $sku,
        'nombre' => $nombre,
        'descripcion' => $descripcion,
        'tipo' => $tipo,
        'categoria_id' => $categoriaId,
        'unidad_base_id' => $unidadBaseId,
        'tasa_impuesto_id' => $tasaImpuestoId,
        'controla_inventario' => $controlaInventario,
        'permite_fraccion' => $permiteFraccion,
    ];

    cat_auditar(
        $conexion,
        $esNuevo ? 'PRODUCTO_CREADO' : 'PRODUCTO_EDITADO',
        'productos',
        $id,
        $esNuevo ? 'Se registró un producto.' : 'Se actualizó un producto.',
        $anterior ? cat_producto_auditoria($anterior) : null,
        $nuevo
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Producto registrado correctamente con código ' . $sku . '.'
            : 'Producto actualizado correctamente.',
        [
            'producto_id' => $id,
            'codigo_producto' => $sku,
        ],
        $esNuevo ? 201 : 200
    );
}

function cat_cambiar_estado_producto(PDO $conexion): void
{
    $id = cat_id($_POST['producto_id'] ?? null, 'producto');
    $activo = cat_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();
    $p = cat_bloquear_producto($conexion, $id);

    if (!$p) {
        cat_cancelar($conexion, 'El producto ya no existe.', 404);
    }

    if ((int) $p['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'El producto ya se encontraba en ese estado.');
    }

    $conexion->prepare(
        "UPDATE productos
         SET activo = :activo
         WHERE id = :id
           AND deleted_at IS NULL"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    /*
     * Desactivar temporalmente el producto no debe destruir la configuración
     * individual de sus presentaciones. Las consultas operativas ya exigen que
     * el producto padre esté activo; al reactivarlo se recupera exactamente la
     * configuración que tenía antes.
     */

    cat_auditar(
        $conexion,
        $activo === 1 ? 'PRODUCTO_ACTIVADO' : 'PRODUCTO_DESACTIVADO',
        'productos',
        $id,
        $activo === 1 ? 'Se activó un producto.' : 'Se desactivó un producto.',
        ['activo' => (int) $p['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Producto activado correctamente.'
            : 'Producto desactivado correctamente.'
    );
}

function cat_papelera_producto(PDO $conexion): void
{
    $id = cat_id($_POST['producto_id'] ?? null, 'producto');

    $conexion->beginTransaction();
    $p = cat_bloquear_producto($conexion, $id);

    if (!$p) {
        cat_cancelar($conexion, 'El producto ya no existe.', 404);
    }

    $stmtStock = $conexion->prepare(
        "SELECT
            COALESCE(SUM(ABS(existencia_fisica)), 0)
            + COALESCE(SUM(ABS(cantidad_reservada)), 0)
         FROM existencias_almacen
         WHERE producto_id = :producto_id"
    );

    $stmtStock->execute([':producto_id' => $id]);

    if ((float) $stmtStock->fetchColumn() > 0.0000001) {
        cat_cancelar(
            $conexion,
            'No puedes enviar a papelera un producto que todavía tiene existencia física o cantidad reservada.',
            409
        );
    }

    $conexion->prepare(
        "UPDATE productos
         SET
            activo = 0,
            deleted_at = NOW(),
            deleted_by = :deleted_by
         WHERE id = :id
           AND deleted_at IS NULL"
    )->execute([
        ':deleted_by' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    $conexion->prepare(
        "UPDATE presentaciones_producto
         SET activo = 0
         WHERE producto_id = :producto_id"
    )->execute([':producto_id' => $id]);

    cat_auditar(
        $conexion,
        'PRODUCTO_PAPELERA',
        'productos',
        $id,
        'Se envió un producto a la papelera.',
        cat_producto_auditoria($p),
        ['activo' => 0, 'deleted_at' => date('Y-m-d H:i:s')]
    );

    $conexion->commit();

    si_responder_json(true, 'Producto enviado a la papelera correctamente.');
}

function cat_buscar_productos(PDO $conexion): void
{
    $q = cat_texto($_GET['q'] ?? '', 140);
    $id = cat_entero_rango($_GET['id'] ?? 0, 0, PHP_INT_MAX, 0);

    $where = [
        'p.deleted_at IS NULL',
        'p.activo = 1',
    ];

    $params = [];

    if ($id > 0) {
        $where[] = 'p.id = :id';
        $params[':id'] = $id;
    } elseif ($q !== '') {
        $where[] = "(
            p.sku = :codigo_exacto
            OR p.sku LIKE :codigo_prefijo
            OR p.nombre LIKE :nombre_contiene
        )";
        $params[':codigo_exacto'] = $q;
        $params[':codigo_prefijo'] = $q . '%';
        $params[':nombre_contiene'] = '%' . $q . '%';
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo
         FROM productos p
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         WHERE {$whereSql}
         ORDER BY
            CASE WHEN p.sku = :orden_codigo THEN 0 ELSE 1 END,
            p.nombre ASC,
            p.id ASC
         LIMIT 20"
    );

    foreach ($params as $clave => $valor) {
        $stmt->bindValue(
            $clave,
            $valor,
            is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $stmt->bindValue(':orden_codigo', $q, PDO::PARAM_STR);
    $stmt->execute();

    $productos = $stmt->fetchAll();

    foreach ($productos as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_base_id'] = (int) $p['unidad_base_id'];
    }
    unset($p);

    si_responder_json(
        true,
        'Productos encontrados.',
        ['productos' => $productos]
    );
}

/* =========================================================================
   CATEGORÍAS
   ========================================================================= */

function cat_listar_categorias(PDO $conexion): void
{
    $pagina = cat_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cat_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cat_texto($_GET['busqueda'] ?? '', 120);

    $where = ['c.deleted_at IS NULL'];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            c.nombre LIKE :categoria_nombre
            OR c.descripcion LIKE :categoria_descripcion
        )';
        $params[':categoria_nombre'] = '%' . $q . '%';
        $params[':categoria_descripcion'] = '%' . $q . '%';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM categorias_productos c
         WHERE {$whereSql}"
    );
    cat_bind($stmtTotal, $params);
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
            c.nombre,
            c.descripcion,
            c.activo,
            (
                SELECT COUNT(*)
                FROM productos p
                WHERE p.categoria_id = c.id
                  AND p.deleted_at IS NULL
            ) AS productos_asignados
         FROM categorias_productos c
         WHERE {$whereSql}
         ORDER BY
            c.activo DESC,
            c.nombre ASC,
            c.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cat_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['activo'] = (int) $f['activo'];
        $f['productos_asignados'] = (int) $f['productos_asignados'];
    }
    unset($f);

    si_responder_json(
        true,
        'Categorías cargadas.',
        [
            'categorias' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cat_detalle_categoria(PDO $conexion): void
{
    $id = cat_id($_GET['id'] ?? null, 'categoría');

    $stmt = $conexion->prepare(
        "SELECT id, nombre, descripcion, activo
         FROM categorias_productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch();

    if (!$c) {
        si_responder_json(false, 'No se encontró la categoría.', [], 404);
    }

    $c['id'] = (int) $c['id'];
    $c['activo'] = (int) $c['activo'];

    si_responder_json(true, 'Categoría encontrada.', ['categoria' => $c]);
}

function cat_guardar_categoria(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['categoria_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cat_id($idTexto, 'categoría');
    $esNuevo = $id === 0;

    $nombre = cat_requerido(
        $_POST['nombre'] ?? '',
        'El nombre de la categoría es obligatorio.',
        120
    );

    $descripcion = cat_nullable($_POST['descripcion'] ?? '', 255);

    $conexion->beginTransaction();
    $anterior = null;

    if (!$esNuevo) {
        $stmt = $conexion->prepare(
            "SELECT id, nombre, descripcion, activo
             FROM categorias_productos
             WHERE id = :id
               AND deleted_at IS NULL
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([':id' => $id]);
        $anterior = $stmt->fetch();

        if (!$anterior) {
            cat_cancelar($conexion, 'La categoría ya no existe.', 404);
        }
    }

    $stmtExiste = $conexion->prepare(
        "SELECT id, deleted_at
         FROM categorias_productos
         WHERE nombre = :nombre
           AND id <> :id
         LIMIT 1"
    );

    $stmtExiste->execute([
        ':nombre' => $nombre,
        ':id' => $id,
    ]);

    $duplicada = $stmtExiste->fetch();

    if ($duplicada) {
        cat_cancelar(
            $conexion,
            $duplicada['deleted_at'] !== null
                ? 'Ya existe una categoría con ese nombre en la papelera.'
                : 'Ya existe una categoría con ese nombre.',
            409
        );
    }

    if ($esNuevo) {
        $stmt = $conexion->prepare(
            "INSERT INTO categorias_productos
                (nombre, descripcion, activo)
             VALUES
                (:nombre, :descripcion, 1)"
        );

        $stmt->execute([
            ':nombre' => $nombre,
            ':descripcion' => $descripcion,
        ]);

        $id = (int) $conexion->lastInsertId();

    } else {
        $stmt = $conexion->prepare(
            "UPDATE categorias_productos
             SET
                nombre = :nombre,
                descripcion = :descripcion
             WHERE id = :id
               AND deleted_at IS NULL"
        );

        $stmt->execute([
            ':nombre' => $nombre,
            ':descripcion' => $descripcion,
            ':id' => $id,
        ]);
    }

    cat_auditar(
        $conexion,
        $esNuevo ? 'CATEGORIA_CREADA' : 'CATEGORIA_EDITADA',
        'categorias_productos',
        $id,
        $esNuevo ? 'Se registró una categoría.' : 'Se actualizó una categoría.',
        $anterior ?: null,
        [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Categoría registrada correctamente.'
            : 'Categoría actualizada correctamente.',
        ['categoria_id' => $id],
        $esNuevo ? 201 : 200
    );
}

function cat_cambiar_estado_categoria(PDO $conexion): void
{
    $id = cat_id($_POST['categoria_id'] ?? null, 'categoría');
    $activo = cat_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT id, nombre, activo
         FROM categorias_productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch();

    if (!$c) {
        cat_cancelar($conexion, 'La categoría ya no existe.', 404);
    }

    if ((int) $c['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'La categoría ya se encontraba en ese estado.');
    }

    $conexion->prepare(
        "UPDATE categorias_productos
         SET activo = :activo
         WHERE id = :id"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    cat_auditar(
        $conexion,
        $activo === 1 ? 'CATEGORIA_ACTIVADA' : 'CATEGORIA_DESACTIVADA',
        'categorias_productos',
        $id,
        $activo === 1 ? 'Se activó una categoría.' : 'Se desactivó una categoría.',
        ['activo' => (int) $c['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Categoría activada correctamente.'
            : 'Categoría desactivada correctamente.'
    );
}

function cat_papelera_categoria(PDO $conexion): void
{
    $id = cat_id($_POST['categoria_id'] ?? null, 'categoría');

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT id, nombre, descripcion, activo
         FROM categorias_productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch();

    if (!$c) {
        cat_cancelar($conexion, 'La categoría ya no existe.', 404);
    }

    $stmtUso = $conexion->prepare(
        "SELECT COUNT(*)
         FROM productos
         WHERE categoria_id = :categoria_id
           AND deleted_at IS NULL"
    );

    $stmtUso->execute([':categoria_id' => $id]);

    if ((int) $stmtUso->fetchColumn() > 0) {
        cat_cancelar(
            $conexion,
            'No puedes enviar esta categoría a papelera porque todavía tiene productos asignados. Puedes desactivarla.',
            409
        );
    }

    $conexion->prepare(
        "UPDATE categorias_productos
         SET
            activo = 0,
            deleted_at = NOW(),
            deleted_by = :deleted_by
         WHERE id = :id"
    )->execute([
        ':deleted_by' => (int) $_SESSION['usuario_id'],
        ':id' => $id,
    ]);

    cat_auditar(
        $conexion,
        'CATEGORIA_PAPELERA',
        'categorias_productos',
        $id,
        'Se envió una categoría a la papelera.',
        $c,
        ['activo' => 0, 'deleted_at' => date('Y-m-d H:i:s')]
    );

    $conexion->commit();

    si_responder_json(true, 'Categoría enviada a la papelera correctamente.');
}

/* =========================================================================
   UNIDADES DE MEDIDA
   ========================================================================= */

function cat_listar_unidades(PDO $conexion): void
{
    $pagina = cat_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cat_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cat_texto($_GET['busqueda'] ?? '', 120);

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            u.codigo LIKE :unidad_codigo
            OR u.nombre LIKE :unidad_nombre
            OR u.simbolo LIKE :unidad_simbolo
            OR u.tipo LIKE :unidad_tipo
        )';

        $like = '%' . $q . '%';
        $params[':unidad_codigo'] = $like;
        $params[':unidad_nombre'] = $like;
        $params[':unidad_simbolo'] = $like;
        $params[':unidad_tipo'] = $like;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM unidades_medida u
         {$whereSql}"
    );

    cat_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            u.id,
            u.codigo,
            u.nombre,
            u.simbolo,
            u.tipo,
            u.activo,
            (
                SELECT COUNT(*)
                FROM productos p
                WHERE p.unidad_base_id = u.id
                  AND p.deleted_at IS NULL
            ) AS productos_base,
            (
                SELECT COUNT(*)
                FROM presentaciones_producto pp
                WHERE pp.unidad_id = u.id
            ) AS presentaciones
         FROM unidades_medida u
         {$whereSql}
         ORDER BY
            u.activo DESC,
            u.tipo ASC,
            u.nombre ASC,
            u.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cat_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['activo'] = (int) $f['activo'];
        $f['productos_base'] = (int) $f['productos_base'];
        $f['presentaciones'] = (int) $f['presentaciones'];
    }
    unset($f);

    si_responder_json(
        true,
        'Unidades cargadas.',
        [
            'unidades' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cat_detalle_unidad(PDO $conexion): void
{
    $id = cat_id($_GET['id'] ?? null, 'unidad');

    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre, simbolo, tipo, activo
         FROM unidades_medida
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();

    if (!$u) {
        si_responder_json(false, 'No se encontró la unidad.', [], 404);
    }

    $u['id'] = (int) $u['id'];
    $u['activo'] = (int) $u['activo'];

    si_responder_json(true, 'Unidad encontrada.', ['unidad' => $u]);
}

function cat_guardar_unidad(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['unidad_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cat_id($idTexto, 'unidad');
    $esNuevo = $id === 0;

    $codigo = strtoupper(cat_codigo(
        $_POST['codigo'] ?? '',
        'Código',
        20
    ));

    $nombre = cat_requerido(
        $_POST['nombre'] ?? '',
        'El nombre de la unidad es obligatorio.',
        80
    );

    $simbolo = cat_requerido(
        $_POST['simbolo'] ?? '',
        'El símbolo de la unidad es obligatorio.',
        20
    );

    $tipo = strtoupper(trim((string) ($_POST['tipo'] ?? '')));

    if (!in_array($tipo, ['MASA', 'VOLUMEN', 'UNIDAD', 'OTRO'], true)) {
        si_responder_json(false, 'Selecciona un tipo de unidad válido.', [], 422);
    }

    $conexion->beginTransaction();
    $anterior = null;

    if (!$esNuevo) {
        $stmt = $conexion->prepare(
            "SELECT id, codigo, nombre, simbolo, tipo, activo
             FROM unidades_medida
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([':id' => $id]);
        $anterior = $stmt->fetch();

        if (!$anterior) {
            cat_cancelar($conexion, 'La unidad ya no existe.', 404);
        }
    }

    $stmtExiste = $conexion->prepare(
        "SELECT id
         FROM unidades_medida
         WHERE codigo = :codigo
           AND id <> :id
         LIMIT 1"
    );

    $stmtExiste->execute([
        ':codigo' => $codigo,
        ':id' => $id,
    ]);

    if ($stmtExiste->fetchColumn()) {
        cat_cancelar($conexion, 'Ya existe una unidad con ese código.', 409);
    }

    if (
        !$esNuevo
        && (string) $anterior['tipo'] !== $tipo
        && cat_unidad_tiene_uso($conexion, $id)
    ) {
        cat_cancelar(
            $conexion,
            'No puedes cambiar el tipo de una unidad que ya está siendo utilizada por productos o presentaciones.',
            409
        );
    }

    if ($esNuevo) {
        $stmt = $conexion->prepare(
            "INSERT INTO unidades_medida
                (codigo, nombre, simbolo, tipo, activo)
             VALUES
                (:codigo, :nombre, :simbolo, :tipo, 1)"
        );

        $stmt->execute([
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':simbolo' => $simbolo,
            ':tipo' => $tipo,
        ]);

        $id = (int) $conexion->lastInsertId();

    } else {
        $stmt = $conexion->prepare(
            "UPDATE unidades_medida
             SET
                codigo = :codigo,
                nombre = :nombre,
                simbolo = :simbolo,
                tipo = :tipo
             WHERE id = :id"
        );

        $stmt->execute([
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':simbolo' => $simbolo,
            ':tipo' => $tipo,
            ':id' => $id,
        ]);
    }

    cat_auditar(
        $conexion,
        $esNuevo ? 'UNIDAD_CREADA' : 'UNIDAD_EDITADA',
        'unidades_medida',
        $id,
        $esNuevo ? 'Se registró una unidad de medida.' : 'Se actualizó una unidad de medida.',
        $anterior ?: null,
        [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'simbolo' => $simbolo,
            'tipo' => $tipo,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo ? 'Unidad registrada correctamente.' : 'Unidad actualizada correctamente.',
        ['unidad_id' => $id],
        $esNuevo ? 201 : 200
    );
}

function cat_cambiar_estado_unidad(PDO $conexion): void
{
    $id = cat_id($_POST['unidad_id'] ?? null, 'unidad');
    $activo = cat_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT id, codigo, nombre, activo
         FROM unidades_medida
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $u = $stmt->fetch();

    if (!$u) {
        cat_cancelar($conexion, 'La unidad ya no existe.', 404);
    }

    if ((int) $u['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'La unidad ya se encontraba en ese estado.');
    }

    if ($activo === 0 && cat_unidad_tiene_uso_activo($conexion, $id)) {
        cat_cancelar(
            $conexion,
            'No puedes desactivar una unidad que está siendo utilizada por un producto o presentación activa.',
            409
        );
    }

    $conexion->prepare(
        "UPDATE unidades_medida
         SET activo = :activo
         WHERE id = :id"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    cat_auditar(
        $conexion,
        $activo === 1 ? 'UNIDAD_ACTIVADA' : 'UNIDAD_DESACTIVADA',
        'unidades_medida',
        $id,
        $activo === 1 ? 'Se activó una unidad.' : 'Se desactivó una unidad.',
        ['activo' => (int) $u['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Unidad activada correctamente.'
            : 'Unidad desactivada correctamente.'
    );
}

/* =========================================================================
   PRESENTACIONES
   ========================================================================= */

function cat_listar_presentaciones(PDO $conexion): void
{
    $pagina = cat_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cat_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cat_texto($_GET['busqueda'] ?? '', 140);

    $where = ['p.deleted_at IS NULL'];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            p.sku LIKE :presentacion_codigo
            OR p.nombre LIKE :presentacion_producto
            OR pp.nombre LIKE :presentacion_nombre
        )';

        $like = '%' . $q . '%';
        $params[':presentacion_codigo'] = $like;
        $params[':presentacion_producto'] = $like;
        $params[':presentacion_nombre'] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM presentaciones_producto pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
         WHERE {$whereSql}"
    );

    cat_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.producto_id,
            p.sku,
            p.nombre AS producto_nombre,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            pp.unidad_id,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            pp.nombre,
            pp.factor_a_unidad_base,
            pp.es_compra,
            pp.es_venta,
            pp.activo
         FROM presentaciones_producto pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
         INNER JOIN unidades_medida u
            ON u.id = pp.unidad_id
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         WHERE {$whereSql}
         ORDER BY
            p.nombre ASC,
            pp.activo DESC,
            pp.nombre ASC,
            pp.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cat_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['producto_id'] = (int) $f['producto_id'];
        $f['unidad_base_id'] = (int) $f['unidad_base_id'];
        $f['unidad_id'] = (int) $f['unidad_id'];
        $f['factor_a_unidad_base'] = (float) $f['factor_a_unidad_base'];
        $f['es_compra'] = (int) $f['es_compra'];
        $f['es_venta'] = (int) $f['es_venta'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    si_responder_json(
        true,
        'Presentaciones cargadas.',
        [
            'presentaciones' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cat_detalle_presentacion(PDO $conexion): void
{
    $id = cat_id($_GET['id'] ?? null, 'presentación');

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.producto_id,
            pp.unidad_id,
            pp.nombre,
            pp.factor_a_unidad_base,
            pp.es_compra,
            pp.es_venta,
            pp.activo,
            p.sku,
            p.nombre AS producto_nombre,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo
         FROM presentaciones_producto pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
           AND p.deleted_at IS NULL
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         WHERE pp.id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();

    if (!$p) {
        si_responder_json(false, 'No se encontró la presentación.', [], 404);
    }

    $p['id'] = (int) $p['id'];
    $p['producto_id'] = (int) $p['producto_id'];
    $p['unidad_id'] = (int) $p['unidad_id'];
    $p['unidad_base_id'] = (int) $p['unidad_base_id'];
    $p['factor_a_unidad_base'] = (float) $p['factor_a_unidad_base'];
    $p['es_compra'] = (int) $p['es_compra'];
    $p['es_venta'] = (int) $p['es_venta'];
    $p['activo'] = (int) $p['activo'];

    si_responder_json(true, 'Presentación encontrada.', ['presentacion' => $p]);
}

function cat_guardar_presentacion(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['presentacion_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cat_id($idTexto, 'presentación');
    $esNuevo = $id === 0;

    $productoId = cat_id($_POST['producto_id'] ?? null, 'producto');
    $unidadId = cat_id($_POST['unidad_id'] ?? null, 'unidad');

    $nombre = cat_requerido(
        $_POST['nombre'] ?? '',
        'El nombre de la presentación es obligatorio.',
        120
    );

    $factor = cat_decimal_positivo($_POST['factor_a_unidad_base'] ?? null);
    $esCompra = cat_bool($_POST['es_compra'] ?? 0);
    $esVenta = cat_bool($_POST['es_venta'] ?? 0);

    if ($esCompra === 0 && $esVenta === 0) {
        si_responder_json(
            false,
            'La presentación debe habilitarse al menos para compra o para venta.',
            [],
            422
        );
    }

    $conexion->beginTransaction();

    $stmtProducto = $conexion->prepare(
        "SELECT id, sku, nombre, unidad_base_id, activo
         FROM productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $stmtProducto->execute([':id' => $productoId]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        cat_cancelar($conexion, 'El producto ya no existe.', 404);
    }

    if ((int) $producto['activo'] !== 1) {
        cat_cancelar(
            $conexion,
            'Activa el producto antes de registrar una presentación.',
            409
        );
    }

    cat_validar_unidad($conexion, $unidadId, false);

    $anterior = null;

    if (!$esNuevo) {
        $stmt = $conexion->prepare(
            "SELECT
                id,
                producto_id,
                unidad_id,
                nombre,
                factor_a_unidad_base,
                es_compra,
                es_venta,
                activo
             FROM presentaciones_producto
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([':id' => $id]);
        $anterior = $stmt->fetch();

        if (!$anterior) {
            cat_cancelar($conexion, 'La presentación ya no existe.', 404);
        }

        if ((int) $anterior['producto_id'] !== $productoId) {
            cat_cancelar(
                $conexion,
                'No puedes mover una presentación de un producto a otro. Crea una nueva presentación.',
                409
            );
        }
    }

    $stmtDuplicada = $conexion->prepare(
        "SELECT id
         FROM presentaciones_producto
         WHERE producto_id = :producto_id
           AND nombre = :nombre
           AND id <> :id
         LIMIT 1"
    );

    $stmtDuplicada->execute([
        ':producto_id' => $productoId,
        ':nombre' => $nombre,
        ':id' => $id,
    ]);

    if ($stmtDuplicada->fetchColumn()) {
        cat_cancelar(
            $conexion,
            'Ese producto ya tiene una presentación con el mismo nombre.',
            409
        );
    }

    if ($esNuevo) {
        $stmt = $conexion->prepare(
            "INSERT INTO presentaciones_producto
                (
                    producto_id,
                    unidad_id,
                    nombre,
                    factor_a_unidad_base,
                    es_compra,
                    es_venta,
                    activo
                )
             VALUES
                (
                    :producto_id,
                    :unidad_id,
                    :nombre,
                    :factor,
                    :es_compra,
                    :es_venta,
                    1
                )"
        );

        $stmt->execute([
            ':producto_id' => $productoId,
            ':unidad_id' => $unidadId,
            ':nombre' => $nombre,
            ':factor' => $factor,
            ':es_compra' => $esCompra,
            ':es_venta' => $esVenta,
        ]);

        $id = (int) $conexion->lastInsertId();

    } else {
        $stmt = $conexion->prepare(
            "UPDATE presentaciones_producto
             SET
                unidad_id = :unidad_id,
                nombre = :nombre,
                factor_a_unidad_base = :factor,
                es_compra = :es_compra,
                es_venta = :es_venta
             WHERE id = :id"
        );

        $stmt->execute([
            ':unidad_id' => $unidadId,
            ':nombre' => $nombre,
            ':factor' => $factor,
            ':es_compra' => $esCompra,
            ':es_venta' => $esVenta,
            ':id' => $id,
        ]);
    }

    cat_auditar(
        $conexion,
        $esNuevo ? 'PRESENTACION_CREADA' : 'PRESENTACION_EDITADA',
        'presentaciones_producto',
        $id,
        $esNuevo ? 'Se registró una presentación.' : 'Se actualizó una presentación.',
        $anterior ?: null,
        [
            'producto_id' => $productoId,
            'unidad_id' => $unidadId,
            'nombre' => $nombre,
            'factor_a_unidad_base' => $factor,
            'es_compra' => $esCompra,
            'es_venta' => $esVenta,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Presentación registrada correctamente.'
            : 'Presentación actualizada correctamente.',
        ['presentacion_id' => $id],
        $esNuevo ? 201 : 200
    );
}

function cat_cambiar_estado_presentacion(PDO $conexion): void
{
    $id = cat_id($_POST['presentacion_id'] ?? null, 'presentación');
    $activo = cat_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.activo,
            p.activo AS producto_activo,
            p.deleted_at AS producto_deleted_at
         FROM presentaciones_producto pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
         WHERE pp.id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();

    if (!$p) {
        cat_cancelar($conexion, 'La presentación ya no existe.', 404);
    }

    if (
        $activo === 1
        && (
            (int) $p['producto_activo'] !== 1
            || $p['producto_deleted_at'] !== null
        )
    ) {
        cat_cancelar(
            $conexion,
            'No puedes activar una presentación de un producto inactivo o enviado a papelera.',
            409
        );
    }

    if ((int) $p['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'La presentación ya se encontraba en ese estado.');
    }

    $conexion->prepare(
        "UPDATE presentaciones_producto
         SET activo = :activo
         WHERE id = :id"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    cat_auditar(
        $conexion,
        $activo === 1 ? 'PRESENTACION_ACTIVADA' : 'PRESENTACION_DESACTIVADA',
        'presentaciones_producto',
        $id,
        $activo === 1 ? 'Se activó una presentación.' : 'Se desactivó una presentación.',
        ['activo' => (int) $p['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Presentación activada correctamente.'
            : 'Presentación desactivada correctamente.'
    );
}

/* =========================================================================
   CATÁLOGOS PEQUEÑOS PARA SELECTS
   ========================================================================= */


/* =========================================================================
   PRECIOS DE VENTA
   ========================================================================= */

function cat_listar_precios_venta(PDO $conexion): void
{
    $pagina = cat_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cat_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $busqueda = cat_texto($_GET['busqueda'] ?? '', 140);
    $nivel = strtoupper(cat_texto($_GET['nivel'] ?? 'TODOS', 20));
    $estado = strtoupper(cat_texto($_GET['estado'] ?? 'ACTUALES', 20));
    $monedaId = cat_entero_rango($_GET['moneda_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($nivel, ['TODOS', 'MENUDEO', 'MAYOREO'], true)) {
        $nivel = 'TODOS';
    }

    if (!in_array($estado, ['TODOS', 'ACTUALES', 'PROGRAMADOS', 'HISTORICOS', 'INACTIVOS'], true)) {
        $estado = 'ACTUALES';
    }

    $where = ['p.deleted_at IS NULL'];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(
            p.sku = :codigo_exacto
            OR p.sku LIKE :codigo_prefijo
            OR p.nombre LIKE :nombre_contiene
            OR COALESCE(pp.nombre, '') LIKE :presentacion_contiene
        )";

        $params[':codigo_exacto'] = $busqueda;
        $params[':codigo_prefijo'] = $busqueda . '%';
        $params[':nombre_contiene'] = '%' . $busqueda . '%';
        $params[':presentacion_contiene'] = '%' . $busqueda . '%';
    }

    if ($nivel !== 'TODOS') {
        $where[] = 'pv.nivel_precio = :nivel';
        $params[':nivel'] = $nivel;
    }

    if ($monedaId > 0) {
        $where[] = 'pv.moneda_id = :moneda_id';
        $params[':moneda_id'] = $monedaId;
    }

    if ($estado === 'ACTUALES') {
        $where[] = 'pv.activo = 1';
        $where[] = 'pv.vigente_desde <= NOW()';
        $where[] = '(pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW())';
    } elseif ($estado === 'PROGRAMADOS') {
        $where[] = 'pv.activo = 1';
        $where[] = 'pv.vigente_desde > NOW()';
    } elseif ($estado === 'HISTORICOS') {
        $where[] = 'pv.activo = 1';
        $where[] = 'pv.vigente_hasta IS NOT NULL';
        $where[] = 'pv.vigente_hasta < NOW()';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'pv.activo = 0';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM precios_venta_producto pv
         INNER JOIN productos p ON p.id = pv.producto_id
         LEFT JOIN presentaciones_producto pp ON pp.id = pv.presentacion_id
         WHERE {$whereSql}"
    );

    cat_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            pv.id,
            pv.producto_id,
            p.sku,
            p.nombre AS producto_nombre,
            pv.presentacion_id,
            CASE
                WHEN pv.presentacion_id IS NULL THEN CONCAT('Unidad base · ', ub.nombre)
                ELSE pp.nombre
            END AS formato_venta,
            CASE
                WHEN pv.presentacion_id IS NULL THEN ub.nombre
                ELSE up.nombre
            END AS unidad_nombre,
            CASE
                WHEN pv.presentacion_id IS NULL THEN ub.simbolo
                ELSE up.simbolo
            END AS unidad_simbolo,
            CASE
                WHEN pv.presentacion_id IS NULL THEN 1
                ELSE pp.factor_a_unidad_base
            END AS factor_a_unidad_base,
            pv.nivel_precio,
            pv.cantidad_minima,
            pv.moneda_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            pv.precio_unitario,
            pv.tasa_impuesto_id,
            COALESCE(ti.nombre, tip.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE(ti.porcentaje, tip.porcentaje, 0) AS impuesto_porcentaje,
            pv.vigente_desde,
            pv.vigente_hasta,
            pv.activo,
            CASE
                WHEN pv.activo = 0 THEN 'INACTIVO'
                WHEN pv.vigente_desde > NOW() THEN 'PROGRAMADO'
                WHEN pv.vigente_hasta IS NOT NULL AND pv.vigente_hasta < NOW() THEN 'HISTORICO'
                ELSE 'ACTUAL'
            END AS estado_calculado
         FROM precios_venta_producto pv
         INNER JOIN productos p ON p.id = pv.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pp ON pp.id = pv.presentacion_id
         LEFT JOIN unidades_medida up ON up.id = pp.unidad_id
         INNER JOIN monedas m ON m.id = pv.moneda_id
         LEFT JOIN tasas_impuesto ti ON ti.id = pv.tasa_impuesto_id
         LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
         WHERE {$whereSql}
         ORDER BY
            CASE
                WHEN pv.activo = 1 AND pv.vigente_desde <= NOW()
                     AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= NOW()) THEN 0
                WHEN pv.activo = 1 AND pv.vigente_desde > NOW() THEN 1
                WHEN pv.activo = 1 THEN 2
                ELSE 3
            END,
            p.nombre ASC,
            pv.vigente_desde DESC,
            pv.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cat_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['producto_id'] = (int) $f['producto_id'];
        $f['presentacion_id'] = $f['presentacion_id'] !== null ? (int) $f['presentacion_id'] : 0;
        $f['factor_a_unidad_base'] = (float) $f['factor_a_unidad_base'];
        $f['cantidad_minima'] = (float) $f['cantidad_minima'];
        $f['moneda_id'] = (int) $f['moneda_id'];
        $f['precio_unitario'] = (float) $f['precio_unitario'];
        $f['tasa_impuesto_id'] = $f['tasa_impuesto_id'] !== null ? (int) $f['tasa_impuesto_id'] : null;
        $f['impuesto_porcentaje'] = (float) $f['impuesto_porcentaje'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    si_responder_json(
        true,
        'Precios de venta cargados.',
        [
            'precios' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function cat_detalle_precio_venta(PDO $conexion): void
{
    $id = cat_id($_GET['id'] ?? null, 'precio de venta');

    $stmt = $conexion->prepare(
        "SELECT
            pv.id,
            pv.producto_id,
            p.sku,
            p.nombre AS producto_nombre,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.tasa_impuesto_id AS producto_tasa_impuesto_id,
            COALESCE(tip.porcentaje, 0) AS producto_impuesto_pct,
            pv.presentacion_id,
            pp.nombre AS presentacion_nombre,
            pv.nivel_precio,
            pv.cantidad_minima,
            pv.moneda_id,
            pv.precio_unitario,
            pv.tasa_impuesto_id,
            pv.vigente_desde,
            pv.vigente_hasta,
            pv.activo
         FROM precios_venta_producto pv
         INNER JOIN productos p ON p.id = pv.producto_id
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto tip ON tip.id = p.tasa_impuesto_id
         LEFT JOIN presentaciones_producto pp ON pp.id = pv.presentacion_id
         WHERE pv.id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        si_responder_json(false, 'No se encontró el precio seleccionado.', [], 404);
    }

    $fila['id'] = (int) $fila['id'];
    $fila['producto_id'] = (int) $fila['producto_id'];
    $fila['unidad_base_id'] = (int) $fila['unidad_base_id'];
    $fila['producto_tasa_impuesto_id'] = $fila['producto_tasa_impuesto_id'] !== null
        ? (int) $fila['producto_tasa_impuesto_id']
        : null;
    $fila['producto_impuesto_pct'] = (float) $fila['producto_impuesto_pct'];
    $fila['presentacion_id'] = $fila['presentacion_id'] !== null ? (int) $fila['presentacion_id'] : 0;
    $fila['cantidad_minima'] = (float) $fila['cantidad_minima'];
    $fila['moneda_id'] = (int) $fila['moneda_id'];
    $fila['precio_unitario'] = (float) $fila['precio_unitario'];
    $fila['tasa_impuesto_id'] = $fila['tasa_impuesto_id'] !== null ? (int) $fila['tasa_impuesto_id'] : null;
    $fila['activo'] = (int) $fila['activo'];

    si_responder_json(true, 'Precio encontrado.', ['precio' => $fila]);
}

function cat_opciones_precio_producto(PDO $conexion): void
{
    $productoId = cat_id($_GET['producto_id'] ?? null, 'producto');

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            p.tasa_impuesto_id,
            COALESCE(ti.nombre, 'Sin impuesto') AS impuesto_nombre,
            COALESCE(ti.porcentaje, 0) AS impuesto_porcentaje
         FROM productos p
         INNER JOIN unidades_medida ub ON ub.id = p.unidad_base_id
         LEFT JOIN tasas_impuesto ti ON ti.id = p.tasa_impuesto_id
         WHERE p.id = :id
           AND p.deleted_at IS NULL
           AND p.activo = 1
         LIMIT 1"
    );

    $stmt->execute([':id' => $productoId]);
    $producto = $stmt->fetch();

    if (!$producto) {
        si_responder_json(false, 'El producto ya no está disponible.', [], 404);
    }

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.unidad_id,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            pp.factor_a_unidad_base
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida u ON u.id = pp.unidad_id
         WHERE pp.producto_id = :producto_id
           AND pp.es_venta = 1
           AND pp.activo = 1
           AND u.activo = 1
         ORDER BY pp.nombre ASC, pp.id ASC"
    );

    $stmt->execute([':producto_id' => $productoId]);
    $presentaciones = $stmt->fetchAll();

    foreach ($presentaciones as &$p) {
        $p['id'] = (int) $p['id'];
        $p['unidad_id'] = (int) $p['unidad_id'];
        $p['factor_a_unidad_base'] = (float) $p['factor_a_unidad_base'];
    }
    unset($p);

    $producto['id'] = (int) $producto['id'];
    $producto['unidad_base_id'] = (int) $producto['unidad_base_id'];
    $producto['tasa_impuesto_id'] = $producto['tasa_impuesto_id'] !== null
        ? (int) $producto['tasa_impuesto_id']
        : null;
    $producto['impuesto_porcentaje'] = (float) $producto['impuesto_porcentaje'];

    si_responder_json(
        true,
        'Opciones de venta cargadas.',
        [
            'producto' => $producto,
            'presentaciones' => $presentaciones,
        ]
    );
}

function cat_guardar_precio_venta(PDO $conexion): void
{
    $productoId = cat_id($_POST['producto_id'] ?? null, 'producto');
    $presentacionId = cat_entero_rango($_POST['presentacion_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $nivel = strtoupper(trim((string) ($_POST['nivel_precio'] ?? '')));
    $cantidadMinimaEntrada = $_POST['cantidad_minima'] ?? null;
    $monedaId = cat_id($_POST['moneda_id'] ?? null, 'moneda');
    $precio = cat_decimal_rango(
        $_POST['precio_unitario'] ?? null,
        'precio unitario',
        0.0001,
        999999999999.0
    );
    $tasaTexto = trim((string) ($_POST['tasa_impuesto_id'] ?? ''));
    $tasaId = $tasaTexto === '' ? null : cat_id($tasaTexto, 'impuesto');
    $vigenteDesde = cat_fecha_hora($_POST['vigente_desde'] ?? null, 'vigente desde');
    $vigenteHasta = cat_fecha_hora_nullable($_POST['vigente_hasta'] ?? null, 'vigente hasta');
    $origenId = cat_entero_rango($_POST['precio_origen_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($nivel, ['MENUDEO', 'MAYOREO'], true)) {
        si_responder_json(false, 'Selecciona un nivel de precio válido.', ['campo' => 'nivel_precio'], 422);
    }

    /*
     * Regla comercial oficial:
     * - MENUDEO aplica desde la primera unidad de la opción de venta.
     * - MAYOREO sí utiliza una cantidad mínima configurable.
     *
     * Esto evita que un precio de menudeo quede accidentalmente inaccesible
     * (por ejemplo, MENUDEO desde 10 cuando una cotización inicia en 1).
     */
    if ($nivel === 'MENUDEO') {
        $cantidadMinima = 1.0;
    } else {
        $cantidadMinima = cat_decimal_rango(
            $cantidadMinimaEntrada,
            'cantidad mínima de mayoreo',
            1.000001,
            999999999999.0
        );
    }

    if ($vigenteHasta !== null && strtotime($vigenteHasta) <= strtotime($vigenteDesde)) {
        si_responder_json(
            false,
            'La fecha final debe ser posterior a la fecha inicial.',
            ['campo' => 'vigente_hasta'],
            422
        );
    }

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.unidad_base_id,
            p.tasa_impuesto_id,
            p.activo,
            p.deleted_at
         FROM productos p
         WHERE p.id = :id
         FOR UPDATE"
    );
    $stmt->execute([':id' => $productoId]);
    $producto = $stmt->fetch();

    if (!$producto || $producto['deleted_at'] !== null || (int) $producto['activo'] !== 1) {
        cat_cancelar($conexion, 'Selecciona un producto activo.', 422, ['campo' => 'producto_id']);
    }

    if ($presentacionId > 0) {
        $stmt = $conexion->prepare(
            "SELECT pp.id
             FROM presentaciones_producto pp
             INNER JOIN unidades_medida u ON u.id = pp.unidad_id
             WHERE pp.id = :id
               AND pp.producto_id = :producto_id
               AND pp.es_venta = 1
               AND pp.activo = 1
               AND u.activo = 1
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $presentacionId,
            ':producto_id' => $productoId,
        ]);

        if (!$stmt->fetchColumn()) {
            cat_cancelar(
                $conexion,
                'La presentación seleccionada no está disponible para venta.',
                422,
                ['campo' => 'presentacion_id']
            );
        }
    }

    $stmt = $conexion->prepare(
        "SELECT activo
         FROM monedas
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $monedaId]);
    $monedaActiva = $stmt->fetchColumn();

    if ($monedaActiva === false || (int) $monedaActiva !== 1) {
        cat_cancelar($conexion, 'La moneda seleccionada no está disponible.', 422, ['campo' => 'moneda_id']);
    }

    cat_validar_impuesto($conexion, $tasaId);

    $finComparacion = $vigenteHasta ?? '9999-12-31 23:59:59';

    $sqlSolapes =
        "SELECT
            pv.id,
            pv.vigente_desde,
            pv.vigente_hasta,
            pv.precio_unitario
         FROM precios_venta_producto pv
         WHERE pv.producto_id = :producto_id
           AND "
        . ($presentacionId > 0 ? 'pv.presentacion_id = :presentacion_id' : 'pv.presentacion_id IS NULL') .
        " AND pv.nivel_precio = :nivel
           AND pv.moneda_id = :moneda_id
           AND pv.activo = 1
           AND pv.vigente_desde <= :fin_nuevo
           AND (pv.vigente_hasta IS NULL OR pv.vigente_hasta >= :inicio_nuevo)
         ORDER BY pv.vigente_desde ASC, pv.id ASC
         FOR UPDATE";

    $stmt = $conexion->prepare($sqlSolapes);
    $params = [
        ':producto_id' => $productoId,
        ':nivel' => $nivel,
        ':moneda_id' => $monedaId,
        ':fin_nuevo' => $finComparacion,
        ':inicio_nuevo' => $vigenteDesde,
    ];

    if ($presentacionId > 0) {
        $params[':presentacion_id'] = $presentacionId;
    }

    $stmt->execute($params);
    $solapes = $stmt->fetchAll();
    $cerrados = [];
    $inicioTs = strtotime($vigenteDesde);

    foreach ($solapes as $solape) {
        $inicioExistente = strtotime((string) $solape['vigente_desde']);

        if ($inicioExistente >= $inicioTs) {
            if ($origenId > 0 && (int) $solape['id'] === $origenId) {
                $conexion->prepare(
                    "UPDATE precios_venta_producto
                     SET activo = 0
                     WHERE id = :id"
                )->execute([':id' => (int) $solape['id']]);

                $cerrados[] = (int) $solape['id'];
                continue;
            }

            cat_cancelar(
                $conexion,
                'Ya existe un precio activo o programado que se cruza con esta vigencia. Desactívalo o elige otra fecha.',
                409
            );
        }

        $nuevoFin = date('Y-m-d H:i:s', $inicioTs - 1);

        $conexion->prepare(
            "UPDATE precios_venta_producto
             SET vigente_hasta = :vigente_hasta
             WHERE id = :id"
        )->execute([
            ':vigente_hasta' => $nuevoFin,
            ':id' => (int) $solape['id'],
        ]);

        $cerrados[] = (int) $solape['id'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO precios_venta_producto
            (
                producto_id,
                presentacion_id,
                nivel_precio,
                cantidad_minima,
                moneda_id,
                precio_unitario,
                tasa_impuesto_id,
                vigente_desde,
                vigente_hasta,
                activo,
                created_by
            )
         VALUES
            (
                :producto_id,
                :presentacion_id,
                :nivel,
                :cantidad_minima,
                :moneda_id,
                :precio,
                :tasa_id,
                :vigente_desde,
                :vigente_hasta,
                1,
                :created_by
            )"
    );

    $stmt->execute([
        ':producto_id' => $productoId,
        ':presentacion_id' => $presentacionId > 0 ? $presentacionId : null,
        ':nivel' => $nivel,
        ':cantidad_minima' => $cantidadMinima,
        ':moneda_id' => $monedaId,
        ':precio' => $precio,
        ':tasa_id' => $tasaId,
        ':vigente_desde' => $vigenteDesde,
        ':vigente_hasta' => $vigenteHasta,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $id = (int) $conexion->lastInsertId();

    cat_auditar(
        $conexion,
        'PRECIO_VENTA_CREADO',
        'precios_venta_producto',
        $id,
        'Se registró una nueva vigencia de precio de venta para ' . (string) $producto['nombre'] . '.',
        $origenId > 0 ? ['precio_origen_id' => $origenId] : null,
        [
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId > 0 ? $presentacionId : null,
            'nivel_precio' => $nivel,
            'cantidad_minima' => $cantidadMinima,
            'moneda_id' => $monedaId,
            'precio_unitario' => $precio,
            'tasa_impuesto_id' => $tasaId,
            'vigente_desde' => $vigenteDesde,
            'vigente_hasta' => $vigenteHasta,
            'precios_anteriores_cerrados' => $cerrados,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Precio de venta registrado. Las cotizaciones nuevas ya pueden utilizarlo automáticamente.',
        ['precio_id' => $id],
        201
    );
}

function cat_desactivar_precio_venta(PDO $conexion): void
{
    $id = cat_id($_POST['precio_id'] ?? null, 'precio de venta');

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT *
         FROM precios_venta_producto
         WHERE id = :id
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $anterior = $stmt->fetch();

    if (!$anterior) {
        cat_cancelar($conexion, 'El precio ya no existe.', 404);
    }

    if ((int) $anterior['activo'] === 0) {
        $conexion->commit();
        si_responder_json(true, 'El precio ya estaba desactivado.', ['precio_id' => $id]);
    }

    $conexion->prepare(
        "UPDATE precios_venta_producto
         SET activo = 0
         WHERE id = :id"
    )->execute([':id' => $id]);

    cat_auditar(
        $conexion,
        'PRECIO_VENTA_DESACTIVADO',
        'precios_venta_producto',
        $id,
        'Se desactivó una vigencia de precio de venta.',
        [
            'producto_id' => (int) $anterior['producto_id'],
            'presentacion_id' => $anterior['presentacion_id'] !== null ? (int) $anterior['presentacion_id'] : null,
            'nivel_precio' => (string) $anterior['nivel_precio'],
            'cantidad_minima' => (float) $anterior['cantidad_minima'],
            'moneda_id' => (int) $anterior['moneda_id'],
            'precio_unitario' => (float) $anterior['precio_unitario'],
            'vigente_desde' => (string) $anterior['vigente_desde'],
            'vigente_hasta' => $anterior['vigente_hasta'],
            'activo' => 1,
        ],
        ['activo' => 0]
    );

    $conexion->commit();

    si_responder_json(true, 'Precio desactivado. El historial se conserva.', ['precio_id' => $id]);
}

function cat_catalogos(PDO $conexion): void
{
    $categorias = $conexion->query(
        "SELECT id, nombre, activo
         FROM categorias_productos
         WHERE deleted_at IS NULL
         ORDER BY activo DESC, nombre ASC"
    )->fetchAll();

    $unidades = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, tipo, activo
         FROM unidades_medida
         ORDER BY activo DESC, tipo ASC, nombre ASC"
    )->fetchAll();

    $impuestos = $conexion->query(
        "SELECT id, codigo, nombre, porcentaje, activo
         FROM tasas_impuesto
         ORDER BY activo DESC, porcentaje ASC, nombre ASC"
    )->fetchAll();

    $monedas = $conexion->query(
        "SELECT id, codigo, nombre, simbolo, es_base, activo
         FROM monedas
         ORDER BY es_base DESC, activo DESC, codigo ASC"
    )->fetchAll();

    foreach ($categorias as &$f) {
        $f['id'] = (int) $f['id'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    foreach ($unidades as &$f) {
        $f['id'] = (int) $f['id'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    foreach ($impuestos as &$f) {
        $f['id'] = (int) $f['id'];
        $f['porcentaje'] = (float) $f['porcentaje'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    foreach ($monedas as &$f) {
        $f['id'] = (int) $f['id'];
        $f['es_base'] = (int) $f['es_base'];
        $f['activo'] = (int) $f['activo'];
    }
    unset($f);

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'categorias' => $categorias,
            'unidades' => $unidades,
            'impuestos' => $impuestos,
            'monedas' => $monedas,
        ]
    );
}

/* =========================================================================
   HELPERS
   ========================================================================= */

function cat_bloquear_producto(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            id,
            sku,
            nombre,
            descripcion,
            tipo,
            categoria_id,
            unidad_base_id,
            tasa_impuesto_id,
            controla_inventario,
            permite_fraccion,
            activo
         FROM productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

function cat_generar_codigo_producto(PDO $conexion, int $id): string
{
    $base = 'PROD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    $codigo = $base;
    $intento = 0;

    while ($intento < 5) {
        $stmt = $conexion->prepare(
            "SELECT 1
             FROM productos
             WHERE sku = :sku
               AND id <> :id
             LIMIT 1"
        );

        $stmt->execute([
            ':sku' => $codigo,
            ':id' => $id,
        ]);

        if (!$stmt->fetchColumn()) {
            return $codigo;
        }

        $intento++;
        $codigo = $base . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    throw new RuntimeException('No fue posible generar un código interno único.');
}

function cat_producto_tiene_historial_inventario(PDO $conexion, int $productoId): bool
{
    $stmt = $conexion->prepare(
        "SELECT
            EXISTS(
                SELECT 1
                FROM existencias_almacen
                WHERE producto_id = :producto_existencia
                  AND (
                    ABS(existencia_fisica) > 0.0000001
                    OR ABS(cantidad_reservada) > 0.0000001
                )
                LIMIT 1
            )
            OR EXISTS(
                SELECT 1
                FROM movimientos_inventario_detalle
                WHERE producto_id = :producto_movimiento
                LIMIT 1
            )"
    );

    $stmt->execute([
        ':producto_existencia' => $productoId,
        ':producto_movimiento' => $productoId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function cat_unidad_tiene_uso(PDO $conexion, int $unidadId): bool
{
    $stmt = $conexion->prepare(
        "SELECT
            EXISTS(
                SELECT 1
                FROM productos
                WHERE unidad_base_id = :unidad_producto
                LIMIT 1
            )
            OR EXISTS(
                SELECT 1
                FROM presentaciones_producto
                WHERE unidad_id = :unidad_presentacion
                LIMIT 1
            )"
    );

    $stmt->execute([
        ':unidad_producto' => $unidadId,
        ':unidad_presentacion' => $unidadId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function cat_unidad_tiene_uso_activo(PDO $conexion, int $unidadId): bool
{
    $stmt = $conexion->prepare(
        "SELECT
            EXISTS(
                SELECT 1
                FROM productos
                WHERE unidad_base_id = :unidad_producto
                  AND deleted_at IS NULL
                  AND activo = 1
                LIMIT 1
            )
            OR EXISTS(
                SELECT 1
                FROM presentaciones_producto pp
                INNER JOIN productos p
                    ON p.id = pp.producto_id
                WHERE pp.unidad_id = :unidad_presentacion
                  AND pp.activo = 1
                  AND p.deleted_at IS NULL
                  AND p.activo = 1
                LIMIT 1
            )"
    );

    $stmt->execute([
        ':unidad_producto' => $unidadId,
        ':unidad_presentacion' => $unidadId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function cat_validar_categoria(PDO $conexion, ?int $categoriaId): void
{
    if ($categoriaId === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT activo
         FROM categorias_productos
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute([':id' => $categoriaId]);
    $activo = $stmt->fetchColumn();

    if ($activo === false) {
        cat_cancelar($conexion, 'La categoría seleccionada ya no existe.', 409);
    }

    if ((int) $activo !== 1) {
        cat_cancelar($conexion, 'La categoría seleccionada está inactiva.', 409);
    }
}

function cat_validar_unidad(PDO $conexion, int $unidadId, bool $permitirInactivaActual): void
{
    $stmt = $conexion->prepare(
        "SELECT activo
         FROM unidades_medida
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $unidadId]);
    $activo = $stmt->fetchColumn();

    if ($activo === false) {
        cat_cancelar($conexion, 'La unidad seleccionada ya no existe.', 409);
    }

    if ((int) $activo !== 1 && !$permitirInactivaActual) {
        cat_cancelar($conexion, 'La unidad seleccionada está inactiva.', 409);
    }
}

function cat_validar_impuesto(PDO $conexion, ?int $tasaId): void
{
    if ($tasaId === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT 1
         FROM tasas_impuesto
         WHERE id = :id
           AND activo = 1
         LIMIT 1"
    );

    $stmt->execute([':id' => $tasaId]);

    if (!$stmt->fetchColumn()) {
        cat_cancelar(
            $conexion,
            'La tasa de impuesto seleccionada no está disponible.',
            409
        );
    }
}

function cat_requerido($valor, string $mensaje, int $maximo): string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        si_responder_json(false, $mensaje, [], 422);
    }

    if (cat_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function cat_codigo($valor, string $campo, int $maximo): string
{
    $codigo = trim((string) $valor);

    if (
        $codigo === ''
        || cat_strlen($codigo) > $maximo
        || !preg_match('/^[A-Za-z0-9._\-\/]+$/', $codigo)
    ) {
        si_responder_json(
            false,
            $campo . ' inválido. Usa letras, números, punto, guion, diagonal o guion bajo.',
            [],
            422
        );
    }

    return $codigo;
}

function cat_nullable($valor, int $maximo): ?string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    if (cat_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function cat_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);

    if (cat_strlen($texto) > $maximo) {
        $texto = cat_substr($texto, 0, $maximo);
    }

    return $texto;
}

function cat_decimal_rango($valor, string $campo, float $minimo, float $maximo): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, 'Ingresa un valor válido para ' . $campo . '.', ['campo' => $campo], 422);
    }

    $numero = (float) $valor;

    if (!is_finite($numero) || $numero < $minimo || $numero > $maximo) {
        si_responder_json(
            false,
            'El valor de ' . $campo . ' está fuera del rango permitido.',
            ['campo' => $campo],
            422
        );
    }

    return $numero;
}

function cat_fecha_hora($valor, string $campo): string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        si_responder_json(false, 'El campo ' . $campo . ' es obligatorio.', ['campo' => $campo], 422);
    }

    $timestamp = strtotime($texto);

    if ($timestamp === false) {
        si_responder_json(false, 'La fecha de ' . $campo . ' no es válida.', ['campo' => $campo], 422);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function cat_fecha_hora_nullable($valor, string $campo): ?string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    $timestamp = strtotime($texto);

    if ($timestamp === false) {
        si_responder_json(false, 'La fecha de ' . $campo . ' no es válida.', ['campo' => $campo], 422);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function cat_decimal_positivo($valor): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, 'Ingresa un factor de conversión válido.', [], 422);
    }

    $numero = (float) $valor;

    if ($numero <= 0 || $numero > 999999999999.0) {
        si_responder_json(
            false,
            'El factor de conversión debe ser mayor que cero.',
            [],
            422
        );
    }

    return $numero;
}

function cat_bool($valor): int
{
    return in_array((string) $valor, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function cat_estado($valor): int
{
    $entero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($entero === false || !in_array((int) $entero, [0, 1], true)) {
        si_responder_json(false, 'Estado inválido.', [], 422);
    }

    return (int) $entero;
}

function cat_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);

    if ($id === false || (int) $id <= 0) {
        si_responder_json(
            false,
            'Identificador de ' . $entidad . ' inválido.',
            [],
            422
        );
    }

    return (int) $id;
}

function cat_entero_rango($valor, int $minimo, int $maximo, int $default): int
{
    $numero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($numero === false) {
        return $default;
    }

    $numero = (int) $numero;

    if ($numero < $minimo || $numero > $maximo) {
        return $default;
    }

    return $numero;
}

function cat_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        $stmt->bindValue(
            $clave,
            $valor,
            is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }
}

function cat_strlen(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto) : strlen($texto);
}

function cat_substr(string $texto, int $inicio, int $longitud): string
{
    return function_exists('mb_substr')
        ? mb_substr($texto, $inicio, $longitud)
        : substr($texto, $inicio, $longitud);
}

function cat_producto_auditoria(array $fila): array
{
    return [
        'sku' => $fila['sku'] ?? null,
        'nombre' => $fila['nombre'] ?? null,
        'descripcion' => $fila['descripcion'] ?? null,
        'tipo' => $fila['tipo'] ?? null,
        'categoria_id' => isset($fila['categoria_id']) ? (int) $fila['categoria_id'] : null,
        'unidad_base_id' => isset($fila['unidad_base_id']) ? (int) $fila['unidad_base_id'] : null,
        'tasa_impuesto_id' => isset($fila['tasa_impuesto_id']) ? (int) $fila['tasa_impuesto_id'] : null,
        'controla_inventario' => isset($fila['controla_inventario']) ? (int) $fila['controla_inventario'] : null,
        'permite_fraccion' => isset($fila['permite_fraccion']) ? (int) $fila['permite_fraccion'] : null,
        'activo' => isset($fila['activo']) ? (int) $fila['activo'] : null,
    ];
}

function cat_auditar(
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
                'catalogos',
                :entidad_tabla,
                :entidad_id,
                :descripcion,
                :datos_anteriores,
                :datos_nuevos,
                :ip,
                :user_agent
            )"
    );

    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':accion' => $accion,
        ':entidad_tabla' => $tabla,
        ':entidad_id' => $entidadId,
        ':descripcion' => $descripcion,
        ':datos_anteriores' => $anterior === null
            ? null
            : json_encode(
                $anterior,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ':datos_nuevos' => $nuevo === null
            ? null
            : json_encode(
                $nuevo,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

function cat_cancelar(
    PDO $conexion,
    string $mensaje,
    int $codigo,
    array $extra = []
): void {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    si_responder_json(false, $mensaje, $extra, $codigo);
}
