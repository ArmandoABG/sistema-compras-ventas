<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('reportes.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

si_requerir_metodo('GET');

$accion = strtoupper(trim((string) ($_GET['accion'] ?? 'CATALOGOS')));

try {
    switch ($accion) {
        case 'CATALOGOS':
            rep_catalogos($conexion);
            break;

        case 'LISTAR':
            rep_listar($conexion);
            break;

        case 'EXPORTAR_CSV':
            if (!si_tiene_permiso('contabilidad.exportar')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'No tienes permiso para exportar información.';
                exit;
            }
            rep_exportar_csv($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }
} catch (PDOException $e) {
    $referencia = 'REP-' . date('Ymd-His');
    error_log('[' . $referencia . '][REPORTES][PDO] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'No fue posible consultar el reporte.', ['referencia' => $referencia], 500);
} catch (Throwable $e) {
    $referencia = 'REP-' . date('Ymd-His');
    error_log('[' . $referencia . '][REPORTES] ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    si_responder_json(false, 'Ocurrió un error interno al consultar reportes.', ['referencia' => $referencia], 500);
}

function rep_col(string $campo, string $titulo, string $tipo = 'texto'): array
{
    return ['campo' => $campo, 'titulo' => $titulo, 'tipo' => $tipo];
}

function rep_definiciones(): array
{
    $estadosCompra = ['BORRADOR', 'PENDIENTE_RECEPCION', 'RECIBIDA_PARCIAL', 'RECIBIDA', 'CANCELADA'];
    $estadosVenta = ['BORRADOR', 'CONFIRMADA', 'CANCELADA'];
    $estadosCuenta = ['PENDIENTE', 'PARCIAL', 'PAGADA', 'VENCIDA', 'CANCELADA'];
    $estadosPago = ['APLICADO', 'CANCELADO'];
    $estadosCotizacion = ['BORRADOR', 'GENERADA', 'ACEPTADA', 'RECHAZADA', 'VENCIDA', 'CONVERTIDA'];
    $estadosApartado = ['ACTIVO', 'COMPLETADO', 'VENCIDO', 'CANCELADO'];
    $estadosProduccion = ['BORRADOR', 'CONFIRMADA', 'CANCELADA'];
    $estadosInventario = ['NORMAL', 'REORDEN', 'CRITICO', 'SIN_DISPONIBLE', 'SIN_STOCK'];
    $estadosKardex = ['BORRADOR', 'APLICADO', 'REVERTIDO'];
    $accionesAuditoria = ['CREAR', 'MODIFICAR', 'CANCELAR', 'RESTAURAR', 'DESACTIVAR', 'REALIZAR_AJUSTE', 'REGISTRAR_PAGO', 'INICIAR_SESION', 'INICIAR_SESIÓN'];

    return [
        'COMPRAS' => [
            'codigo' => 'COMPRAS', 'nombre' => 'Compras',
            'descripcion' => 'Compras registradas con proveedor, condición de pago e importes históricos.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'estado', 'usuario'], 'estados' => $estadosCompra,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('proveedor', 'Proveedor'),
                rep_col('factura', 'Factura'), rep_col('condicion_pago', 'Condición'), rep_col('estado', 'Estado', 'estado'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
                rep_col('usuario', 'Usuario'),
            ],
        ],
        'VENTAS' => [
            'codigo' => 'VENTAS', 'nombre' => 'Ventas',
            'descripcion' => 'Ventas registradas con cliente, condición financiera e importes históricos.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosVenta,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'),
                rep_col('condicion_pago', 'Condición'), rep_col('estado', 'Estado', 'estado'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
                rep_col('usuario', 'Usuario'),
            ],
        ],
        'COMPRAS_PROVEEDOR' => [
            'codigo' => 'COMPRAS_PROVEEDOR', 'nombre' => 'Compras por proveedor',
            'descripcion' => 'Resumen acumulado de compras agrupado por proveedor.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'estado'], 'estados' => $estadosCompra,
            'columnas' => [
                rep_col('proveedor', 'Proveedor'), rep_col('rfc', 'RFC'), rep_col('operaciones', 'Compras', 'entero'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
            ],
        ],
        'VENTAS_CLIENTE' => [
            'codigo' => 'VENTAS_CLIENTE', 'nombre' => 'Ventas por cliente',
            'descripcion' => 'Resumen acumulado de ventas agrupado por cliente.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado'], 'estados' => $estadosVenta,
            'columnas' => [
                rep_col('cliente', 'Cliente'), rep_col('rfc', 'RFC'), rep_col('operaciones', 'Ventas', 'entero'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
            ],
        ],
        'INVENTARIO' => [
            'codigo' => 'INVENTARIO', 'nombre' => 'Inventario actual',
            'descripcion' => 'Existencia física, reservada y disponible por producto y almacén.',
            'filtros' => ['almacen', 'producto', 'estado'], 'estados' => $estadosInventario,
            'columnas' => [
                rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'), rep_col('producto', 'Producto'), rep_col('tipo_producto', 'Tipo'),
                rep_col('existencia_fisica', 'Física', 'cantidad'), rep_col('reservada', 'Reservada', 'cantidad'), rep_col('disponible', 'Disponible', 'cantidad'),
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'),
                rep_col('unidad', 'Unidad'), rep_col('costo_promedio', 'Costo prom.', 'moneda'), rep_col('valor_inventario', 'Valor físico', 'moneda'), rep_col('estado', 'Stock', 'estado'),
            ],
        ],
        'KARDEX' => [
            'codigo' => 'KARDEX', 'nombre' => 'Kardex',
            'descripcion' => 'Historial de entradas y salidas de inventario con antes y después.',
            'filtros' => ['fecha', 'almacen', 'producto', 'estado', 'usuario'], 'estados' => $estadosKardex,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Movimiento'), rep_col('tipo_movimiento', 'Tipo'), rep_col('almacen', 'Almacén'),
                rep_col('sku', 'SKU'), rep_col('producto', 'Producto'), rep_col('cantidad_delta', 'Movimiento', 'cantidad'),
                rep_col('existencia_antes', 'Antes', 'cantidad'), rep_col('existencia_despues', 'Después', 'cantidad'), rep_col('unidad', 'Unidad'),
                rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'MATERIA_PRIMA' => [
            'codigo' => 'MATERIA_PRIMA', 'nombre' => 'Materia prima',
            'descripcion' => 'Existencias actuales exclusivamente de materias primas.',
            'filtros' => ['almacen', 'producto', 'estado'], 'estados' => $estadosInventario,
            'columnas' => [
                rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'), rep_col('producto', 'Materia prima'),
                rep_col('existencia_fisica', 'Física', 'cantidad'), rep_col('reservada', 'Reservada', 'cantidad'), rep_col('disponible', 'Disponible', 'cantidad'),
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'),
                rep_col('unidad', 'Unidad'), rep_col('costo_promedio', 'Costo prom.', 'moneda'), rep_col('estado', 'Stock', 'estado'),
            ],
        ],
        'PRODUCTO_TERMINADO' => [
            'codigo' => 'PRODUCTO_TERMINADO', 'nombre' => 'Producto terminado',
            'descripcion' => 'Existencias actuales exclusivamente de productos terminados.',
            'filtros' => ['almacen', 'producto', 'estado'], 'estados' => $estadosInventario,
            'columnas' => [
                rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'), rep_col('producto', 'Producto terminado'),
                rep_col('existencia_fisica', 'Física', 'cantidad'), rep_col('reservada', 'Reservada', 'cantidad'), rep_col('disponible', 'Disponible', 'cantidad'),
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'),
                rep_col('unidad', 'Unidad'), rep_col('costo_promedio', 'Costo prom.', 'moneda'), rep_col('estado', 'Stock', 'estado'),
            ],
        ],
        'MERMAS' => [
            'codigo' => 'MERMAS', 'nombre' => 'Mermas',
            'descripcion' => 'Salidas registradas como pérdida o merma, conservadas en Kardex.',
            'filtros' => ['fecha', 'almacen', 'producto', 'estado', 'usuario'], 'estados' => $estadosKardex,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'),
                rep_col('producto', 'Producto'), rep_col('cantidad', 'Cantidad', 'cantidad'), rep_col('unidad', 'Unidad'), rep_col('motivo', 'Motivo'),
                rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'PRODUCCION' => [
            'codigo' => 'PRODUCCION', 'nombre' => 'Producción',
            'descripcion' => 'Producciones registradas, producto obtenido, receta y cantidades reales.',
            'filtros' => ['fecha', 'almacen', 'producto', 'estado', 'usuario'], 'estados' => $estadosProduccion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('estado', 'Estado', 'estado'), rep_col('receta', 'Receta'),
                rep_col('version', 'Versión'), rep_col('producto_resultado', 'Resultado'), rep_col('cantidad_resultado', 'Cantidad', 'cantidad'),
                rep_col('unidad_resultado', 'Unidad'), rep_col('almacen', 'Almacén'), rep_col('insumos', 'Insumos', 'entero'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'CUENTAS_PAGAR' => [
            'codigo' => 'CUENTAS_PAGAR', 'nombre' => 'Cuentas por pagar',
            'descripcion' => 'Deudas con proveedores, pagos acumulados, saldos y vencimientos.',
            'filtros' => ['fecha', 'proveedor', 'estado'], 'estados' => $estadosCuenta,
            'columnas' => [
                rep_col('fecha', 'Documento', 'fecha'), rep_col('folio', 'Cuenta'), rep_col('compra', 'Compra'), rep_col('proveedor', 'Proveedor'),
                rep_col('importe_original', 'Original', 'moneda'), rep_col('pagado', 'Pagado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'),
                rep_col('vencimiento', 'Vencimiento', 'fecha'), rep_col('estado', 'Estado', 'estado'),
            ],
        ],
        'CUENTAS_COBRAR' => [
            'codigo' => 'CUENTAS_COBRAR', 'nombre' => 'Cuentas por cobrar',
            'descripcion' => 'Créditos de clientes, abonos acumulados, saldos y vencimientos.',
            'filtros' => ['fecha', 'cliente', 'estado'], 'estados' => $estadosCuenta,
            'columnas' => [
                rep_col('fecha', 'Documento', 'fecha'), rep_col('folio', 'Cuenta'), rep_col('venta', 'Venta'), rep_col('cliente', 'Cliente'),
                rep_col('importe_original', 'Original', 'moneda'), rep_col('pagado', 'Pagado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'),
                rep_col('vencimiento', 'Vencimiento', 'fecha'), rep_col('estado', 'Estado', 'estado'),
            ],
        ],
        'PAGOS' => [
            'codigo' => 'PAGOS', 'nombre' => 'Pagos a proveedores',
            'descripcion' => 'Pagos realizados a proveedores y su estado.',
            'filtros' => ['fecha', 'proveedor', 'estado', 'usuario'], 'estados' => $estadosPago,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('proveedor', 'Proveedor'), rep_col('metodo', 'Método'),
                rep_col('importe', 'Importe', 'moneda'), rep_col('moneda', 'Moneda'), rep_col('referencia', 'Referencia'), rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'ABONOS' => [
            'codigo' => 'ABONOS', 'nombre' => 'Abonos de clientes',
            'descripcion' => 'Pagos y abonos recibidos de clientes para sus cuentas por cobrar.',
            'filtros' => ['fecha', 'cliente', 'estado', 'usuario'], 'estados' => $estadosPago,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('metodo', 'Método'),
                rep_col('importe', 'Importe', 'moneda'), rep_col('moneda', 'Moneda'), rep_col('referencia', 'Referencia'), rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'COTIZACIONES' => [
            'codigo' => 'COTIZACIONES', 'nombre' => 'Cotizaciones',
            'descripcion' => 'Propuestas comerciales y su estado sin afectar inventario.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosCotizacion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('vigencia', 'Vigencia', 'fecha'),
                rep_col('estado', 'Estado', 'estado'), rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'APARTADOS' => [
            'codigo' => 'APARTADOS', 'nombre' => 'Apartados',
            'descripcion' => 'Reservas de productos con anticipos y saldo pendiente.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosApartado,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('reservado_hasta', 'Reservado hasta', 'fecha_hora'),
                rep_col('estado', 'Estado', 'estado'), rep_col('total', 'Total', 'moneda'), rep_col('anticipado', 'Anticipado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'MOVIMIENTOS_USUARIOS' => [
            'codigo' => 'MOVIMIENTOS_USUARIOS', 'nombre' => 'Movimientos de usuarios',
            'descripcion' => 'Actividad registrada en auditoría: quién hizo qué, cuándo y en qué módulo.',
            'filtros' => ['fecha', 'estado', 'usuario'], 'estados' => $accionesAuditoria, 'estado_label' => 'Acción', 'estado_abierto' => true,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('usuario', 'Usuario'), rep_col('accion', 'Acción', 'estado'), rep_col('modulo', 'Módulo'),
                rep_col('entidad', 'Entidad'), rep_col('registro_id', 'Registro'), rep_col('descripcion', 'Descripción'), rep_col('ip', 'IP'),
            ],
        ],
    ];
}

function rep_catalogos(PDO $conexion): void
{
    $definiciones = rep_definiciones();

    // Reportes es histórico: los filtros también permiten localizar registros
    // relacionados con catálogos que actualmente están inactivos.
    $almacenes = $conexion->query(
        "SELECT id, codigo, nombre, activo FROM almacenes ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $productos = $conexion->query(
        "SELECT id, sku, nombre, tipo, activo FROM productos ORDER BY nombre ASC, id ASC"
    )->fetchAll();

    $proveedores = $conexion->query(
        "SELECT id, codigo, razon_social AS nombre, activo FROM proveedores ORDER BY razon_social ASC, id ASC"
    )->fetchAll();

    $clientes = $conexion->query(
        "SELECT id, codigo, nombre_razon_social AS nombre, activo FROM clientes ORDER BY nombre_razon_social ASC, id ASC"
    )->fetchAll();

    $usuarios = $conexion->query(
        "SELECT id, usuario, TRIM(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)) AS nombre, activo
         FROM usuarios
         ORDER BY usuario ASC, id ASC"
    )->fetchAll();

    $accionesAuditoria = $conexion->query(
        "SELECT DISTINCT accion FROM auditoria WHERE accion IS NOT NULL AND accion <> '' ORDER BY accion ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($accionesAuditoria) {
        $definiciones['MOVIMIENTOS_USUARIOS']['estados'] = array_values(array_map('strval', $accionesAuditoria));
    }

    foreach ($almacenes as &$fila) { $fila['id'] = (int) $fila['id']; } unset($fila);
    foreach ($productos as &$fila) { $fila['id'] = (int) $fila['id']; } unset($fila);
    foreach ($proveedores as &$fila) { $fila['id'] = (int) $fila['id']; } unset($fila);
    foreach ($clientes as &$fila) { $fila['id'] = (int) $fila['id']; } unset($fila);
    foreach ($usuarios as &$fila) { $fila['id'] = (int) $fila['id']; } unset($fila);

    si_responder_json(true, 'Catálogos de reportes cargados.', [
        'reportes' => array_values($definiciones),
        'almacenes' => $almacenes,
        'productos' => $productos,
        'proveedores' => $proveedores,
        'clientes' => $clientes,
        'usuarios' => $usuarios,
        'puede_exportar' => si_tiene_permiso('contabilidad.exportar'),
    ]);
}

function rep_listar(PDO $conexion): void
{
    $codigo = rep_codigo_reporte($_GET['reporte'] ?? '');
    $definiciones = rep_definiciones();
    $def = $definiciones[$codigo];
    $filtros = rep_filtros($def);
    $pagina = rep_entero($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = rep_entero($_GET['por_pagina'] ?? 20, 10, 100, 20);

    [$sqlBase, $params, $orden] = rep_sql($codigo, $filtros);

    $stmtConteo = $conexion->prepare("SELECT COUNT(*) FROM ({$sqlBase}) rep_count");
    rep_bind($stmtConteo, $params);
    $stmtConteo->execute();
    $total = (int) $stmtConteo->fetchColumn();

    $paginas = max(1, (int) ceil($total / $porPagina));
    if ($pagina > $paginas) {
        $pagina = $paginas;
    }
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare($sqlBase . ' ' . $orden . ' LIMIT ' . (int) $porPagina . ' OFFSET ' . (int) $offset);
    rep_bind($stmt, $params);
    $stmt->execute();
    $filas = $stmt->fetchAll();

    si_responder_json(true, 'Reporte cargado.', [
        'reporte' => [
            'codigo' => $def['codigo'],
            'nombre' => $def['nombre'],
            'descripcion' => $def['descripcion'],
        ],
        'columnas' => $def['columnas'],
        'filas' => $filas,
        'paginacion' => [
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'total' => $total,
            'paginas' => $paginas,
        ],
    ]);
}

function rep_exportar_csv(PDO $conexion): void
{
    $codigo = rep_codigo_reporte($_GET['reporte'] ?? '');
    $definiciones = rep_definiciones();
    $def = $definiciones[$codigo];
    $filtros = rep_filtros($def);
    [$sqlBase, $params, $orden] = rep_sql($codigo, $filtros);

    $stmt = $conexion->prepare($sqlBase . ' ' . $orden . ' LIMIT 50000');
    rep_bind($stmt, $params);
    $stmt->execute();

    $archivo = 'reporte_' . strtolower($codigo) . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $salida = fopen('php://output', 'wb');
    if ($salida === false) {
        throw new RuntimeException('No fue posible abrir la salida CSV.');
    }

    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, array_map(static fn(array $c): string => (string) $c['titulo'], $def['columnas']), ',', '"', '');

    while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $valores = [];
        foreach ($def['columnas'] as $columna) {
            $valor = $fila[$columna['campo']] ?? '';
            $valores[] = rep_csv_seguro($valor, (string) ($columna['tipo'] ?? 'texto'));
        }
        fputcsv($salida, $valores, ',', '"', '');
    }

    fclose($salida);
    exit;
}

function rep_sql(string $codigo, array $f): array
{
    $where = [];
    $params = [];
    $where[] = '1=1';

    switch ($codigo) {
        case 'COMPRAS':
            rep_fecha($where, $params, 'c.fecha_compra', $f);
            rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'c.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'c.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM compras_detalle cd_f WHERE cd_f.compra_id = c.id AND cd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['c.folio', 'c.proveedor_nombre_snapshot', 'c.proveedor_rfc_snapshot', 'c.numero_factura']);
            $sql = "SELECT c.fecha_compra AS fecha, c.folio,
                           COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                           COALESCE(c.numero_factura, '') AS factura, c.condicion_pago, c.estado,
                           (c.total - c.impuesto_total) AS subtotal, c.impuesto_total AS impuesto, c.total,
                           COALESCE(u.usuario, '—') AS usuario
                    FROM compras c
                    LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                    LEFT JOIN usuarios u ON u.id = c.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY c.fecha_compra DESC, c.id DESC'];

        case 'VENTAS':
            rep_fecha($where, $params, 'v.fecha_venta', $f);
            rep_id($where, $params, 'v.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'v.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'v.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM ventas_detalle vd_f WHERE vd_f.venta_id = v.id AND vd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['v.folio', 'v.cliente_nombre_snapshot', 'v.cliente_rfc_snapshot']);
            $sql = "SELECT v.fecha_venta AS fecha, v.folio,
                           COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                           v.condicion_pago, v.estado, (v.total - v.impuesto_total) AS subtotal, v.impuesto_total AS impuesto, v.total,
                           COALESCE(u.usuario, '—') AS usuario
                    FROM ventas v
                    LEFT JOIN clientes cl ON cl.id = v.cliente_id
                    LEFT JOIN usuarios u ON u.id = v.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY v.fecha_venta DESC, v.id DESC'];

        case 'COMPRAS_PROVEEDOR':
            rep_fecha($where, $params, 'c.fecha_compra', $f);
            rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_estado($where, $params, 'c.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM compras_detalle cd_f WHERE cd_f.compra_id = c.id AND cd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['c.proveedor_nombre_snapshot', 'c.proveedor_rfc_snapshot', 'pr.razon_social', 'pr.rfc']);
            $sql = "SELECT COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                           COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                           COUNT(*) AS operaciones,
                           COALESCE(SUM(c.total - c.impuesto_total),0) AS subtotal,
                           COALESCE(SUM(c.impuesto_total),0) AS impuesto,
                           COALESCE(SUM(c.total),0) AS total
                    FROM compras c
                    LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY c.proveedor_id,
                             COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor'),
                             COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '')";
            return [$sql, $params, 'ORDER BY total DESC, proveedor ASC'];

        case 'VENTAS_CLIENTE':
            rep_fecha($where, $params, 'v.fecha_venta', $f);
            rep_id($where, $params, 'v.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_estado($where, $params, 'v.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM ventas_detalle vd_f WHERE vd_f.venta_id = v.id AND vd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['v.cliente_nombre_snapshot', 'v.cliente_rfc_snapshot', 'cl.nombre_razon_social', 'cl.rfc']);
            $sql = "SELECT COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                           COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                           COUNT(*) AS operaciones,
                           COALESCE(SUM(v.total - v.impuesto_total),0) AS subtotal,
                           COALESCE(SUM(v.impuesto_total),0) AS impuesto,
                           COALESCE(SUM(v.total),0) AS total
                    FROM ventas v
                    LEFT JOIN clientes cl ON cl.id = v.cliente_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY v.cliente_id,
                             COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general'),
                             COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '')";
            return [$sql, $params, 'ORDER BY total DESC, cliente ASC'];

        case 'INVENTARIO':
        case 'MATERIA_PRIMA':
        case 'PRODUCTO_TERMINADO':
            rep_id($where, $params, 'ea.almacen_id', 'almacen_id', $f['almacen_id']);
            rep_id($where, $params, 'ea.producto_id', 'producto_id', $f['producto_id']);
            if ($codigo === 'MATERIA_PRIMA') {
                $where[] = "p.tipo = 'MATERIA_PRIMA'";
            } elseif ($codigo === 'PRODUCTO_TERMINADO') {
                $where[] = "p.tipo = 'PRODUCTO_TERMINADO'";
            }
            if ($f['estado'] !== '') {
                $where[] = "(CASE
                    WHEN ea.existencia_fisica <= 0 THEN 'SIN_STOCK'
                    WHEN ea.cantidad_disponible <= 0 THEN 'SIN_DISPONIBLE'
                    WHEN ea.stock_minimo > 0 AND ea.cantidad_disponible <= ea.stock_minimo THEN 'CRITICO'
                    WHEN ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND ea.cantidad_disponible <= ea.punto_reorden THEN 'REORDEN'
                    ELSE 'NORMAL'
                END) = :estado_filtro";
                $params['estado_filtro'] = $f['estado'];
            }
            rep_buscar($where, $params, $f['buscar'], ['p.sku', 'p.nombre', 'a.nombre']);
            $sql = "SELECT a.nombre AS almacen, p.sku, p.nombre AS producto, p.tipo AS tipo_producto,
                           ea.existencia_fisica, ea.cantidad_reservada AS reservada, ea.cantidad_disponible AS disponible,
                           ea.stock_minimo, ea.punto_reorden,
                           um.codigo AS unidad, ea.costo_promedio_base AS costo_promedio,
                           (ea.existencia_fisica * COALESCE(ea.costo_promedio_base,0)) AS valor_inventario,
                           CASE
                               WHEN ea.existencia_fisica <= 0 THEN 'SIN_STOCK'
                               WHEN ea.cantidad_disponible <= 0 THEN 'SIN_DISPONIBLE'
                               WHEN ea.stock_minimo > 0 AND ea.cantidad_disponible <= ea.stock_minimo THEN 'CRITICO'
                               WHEN ea.punto_reorden IS NOT NULL AND ea.punto_reorden > 0 AND ea.cantidad_disponible <= ea.punto_reorden THEN 'REORDEN'
                               ELSE 'NORMAL'
                           END AS estado
                    FROM existencias_almacen ea
                    INNER JOIN almacenes a ON a.id = ea.almacen_id
                    INNER JOIN productos p ON p.id = ea.producto_id
                    INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY p.nombre ASC, a.nombre ASC'];

        case 'KARDEX':
        case 'MERMAS':
            rep_fecha($where, $params, 'mi.fecha_movimiento', $f);
            rep_id($where, $params, 'mid.almacen_id', 'almacen_id', $f['almacen_id']);
            rep_id($where, $params, 'mid.producto_id', 'producto_id', $f['producto_id']);
            rep_id($where, $params, 'mi.aplicado_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'mi.estado', $f['estado']);
            if ($codigo === 'MERMAS') {
                $where[] = "tmi.codigo = 'MERMA'";
            }
            rep_buscar($where, $params, $f['buscar'], ['mi.folio', 'tmi.nombre', 'p.sku', 'p.nombre', 'a.nombre', 'mi.motivo']);
            if ($codigo === 'MERMAS') {
                $sql = "SELECT mi.fecha_movimiento AS fecha, mi.folio, a.nombre AS almacen, p.sku, p.nombre AS producto,
                               ABS(mid.cantidad_delta) AS cantidad, um.codigo AS unidad,
                               COALESCE(mi.motivo,'') AS motivo, mi.estado, COALESCE(u.usuario,'—') AS usuario
                        FROM movimientos_inventario mi
                        INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
                        INNER JOIN movimientos_inventario_detalle mid ON mid.movimiento_id = mi.id
                        INNER JOIN almacenes a ON a.id = mid.almacen_id
                        INNER JOIN productos p ON p.id = mid.producto_id
                        INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                        LEFT JOIN usuarios u ON u.id = mi.aplicado_by
                        WHERE " . implode(' AND ', $where);
            } else {
                $sql = "SELECT mi.fecha_movimiento AS fecha, mi.folio, tmi.nombre AS tipo_movimiento, a.nombre AS almacen,
                               p.sku, p.nombre AS producto, mid.cantidad_delta, mid.existencia_antes, mid.existencia_despues,
                               um.codigo AS unidad, mi.estado, COALESCE(u.usuario,'—') AS usuario
                        FROM movimientos_inventario mi
                        INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
                        INNER JOIN movimientos_inventario_detalle mid ON mid.movimiento_id = mi.id
                        INNER JOIN almacenes a ON a.id = mid.almacen_id
                        INNER JOIN productos p ON p.id = mid.producto_id
                        INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                        LEFT JOIN usuarios u ON u.id = mi.aplicado_by
                        WHERE " . implode(' AND ', $where);
            }
            return [$sql, $params, 'ORDER BY mi.fecha_movimiento DESC, mi.id DESC, mid.renglon ASC'];

        case 'PRODUCCION':
            rep_fecha($where, $params, 'pd.fecha_produccion', $f);
            rep_id($where, $params, 'pd.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'pd.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = '(EXISTS (SELECT 1 FROM producciones_resultados pr_f WHERE pr_f.produccion_id = pd.id AND pr_f.producto_id = :producto_res_id) OR EXISTS (SELECT 1 FROM producciones_insumos pi_f WHERE pi_f.produccion_id = pd.id AND pi_f.producto_id = :producto_ins_id))';
                $params['producto_res_id'] = $f['producto_id'];
                $params['producto_ins_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = '(EXISTS (SELECT 1 FROM producciones_resultados pr_a WHERE pr_a.produccion_id = pd.id AND pr_a.almacen_id = :almacen_res_id) OR EXISTS (SELECT 1 FROM producciones_insumos pi_a WHERE pi_a.produccion_id = pd.id AND pi_a.almacen_id = :almacen_ins_id))';
                $params['almacen_res_id'] = $f['almacen_id'];
                $params['almacen_ins_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['pd.folio', 'rp.nombre', 'p_res.nombre']);
            $sql = "SELECT pd.fecha_produccion AS fecha, pd.folio, pd.estado,
                           COALESCE(rp.nombre, 'Captura manual') AS receta,
                           COALESCE(pd.receta_version, '') AS version,
                           COALESCE(MAX(p_res.nombre), '—') AS producto_resultado,
                           COALESCE(MAX(pr_res.cantidad), 0) AS cantidad_resultado,
                           COALESCE(MAX(um_res.codigo), '') AS unidad_resultado,
                           COALESCE(MAX(a_res.nombre), '—') AS almacen,
                           COUNT(DISTINCT pi.id) AS insumos,
                           COALESCE(u.usuario, '—') AS usuario
                    FROM producciones pd
                    LEFT JOIN recetas_produccion rp ON rp.id = pd.receta_id
                    LEFT JOIN producciones_resultados pr_res ON pr_res.produccion_id = pd.id
                    LEFT JOIN productos p_res ON p_res.id = pr_res.producto_id
                    LEFT JOIN unidades_medida um_res ON um_res.id = pr_res.unidad_id
                    LEFT JOIN almacenes a_res ON a_res.id = pr_res.almacen_id
                    LEFT JOIN producciones_insumos pi ON pi.produccion_id = pd.id
                    LEFT JOIN usuarios u ON u.id = pd.created_by
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY pd.id, pd.fecha_produccion, pd.folio, pd.estado, rp.nombre, pd.receta_version, u.usuario";
            return [$sql, $params, 'ORDER BY fecha DESC, folio DESC'];

        case 'CUENTAS_PAGAR':
            rep_fecha($where, $params, 'cxp.fecha_documento', $f);
            rep_id($where, $params, 'cxp.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_estado($where, $params, 'cxp.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['cxp.folio', 'c.folio', 'pr.razon_social']);
            $sql = "SELECT cxp.fecha_documento AS fecha, cxp.folio, c.folio AS compra, pr.razon_social AS proveedor,
                           cxp.importe_original, cxp.importe_pagado AS pagado, cxp.saldo_pendiente AS saldo,
                           cxp.fecha_vencimiento AS vencimiento, cxp.estado
                    FROM cuentas_por_pagar cxp
                    INNER JOIN compras c ON c.id = cxp.compra_id
                    INNER JOIN proveedores pr ON pr.id = cxp.proveedor_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY cxp.fecha_documento DESC, cxp.id DESC'];

        case 'CUENTAS_COBRAR':
            rep_fecha($where, $params, 'cxc.fecha_documento', $f);
            rep_id($where, $params, 'cxc.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_estado($where, $params, 'cxc.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['cxc.folio', 'v.folio', 'cl.nombre_razon_social']);
            $sql = "SELECT cxc.fecha_documento AS fecha, cxc.folio, v.folio AS venta, cl.nombre_razon_social AS cliente,
                           cxc.importe_original, cxc.importe_pagado AS pagado, cxc.saldo_pendiente AS saldo,
                           cxc.fecha_vencimiento AS vencimiento, cxc.estado
                    FROM cuentas_por_cobrar cxc
                    INNER JOIN ventas v ON v.id = cxc.venta_id
                    INNER JOIN clientes cl ON cl.id = cxc.cliente_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY cxc.fecha_documento DESC, cxc.id DESC'];

        case 'PAGOS':
            rep_fecha($where, $params, 'pp.fecha_pago', $f);
            rep_id($where, $params, 'pp.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'pp.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'pp.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['pp.folio', 'pr.razon_social', 'pp.referencia']);
            $sql = "SELECT pp.fecha_pago AS fecha, pp.folio, pr.razon_social AS proveedor, mp.nombre AS metodo,
                           pp.importe, mon.codigo AS moneda, COALESCE(pp.referencia,'') AS referencia, pp.estado,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM pagos_proveedor pp
                    INNER JOIN proveedores pr ON pr.id = pp.proveedor_id
                    INNER JOIN metodos_pago mp ON mp.id = pp.metodo_pago_id
                    INNER JOIN monedas mon ON mon.id = pp.moneda_id
                    LEFT JOIN usuarios u ON u.id = pp.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY pp.fecha_pago DESC, pp.id DESC'];

        case 'ABONOS':
            rep_fecha($where, $params, 'pc.fecha_pago', $f);
            rep_id($where, $params, 'pc.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'pc.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'pc.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['pc.folio', 'cl.nombre_razon_social', 'pc.referencia']);
            $sql = "SELECT pc.fecha_pago AS fecha, pc.folio, cl.nombre_razon_social AS cliente, mp.nombre AS metodo,
                           pc.importe, mon.codigo AS moneda, COALESCE(pc.referencia,'') AS referencia, pc.estado,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM pagos_cliente pc
                    INNER JOIN clientes cl ON cl.id = pc.cliente_id
                    INNER JOIN metodos_pago mp ON mp.id = pc.metodo_pago_id
                    INNER JOIN monedas mon ON mon.id = pc.moneda_id
                    LEFT JOIN usuarios u ON u.id = pc.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY pc.fecha_pago DESC, pc.id DESC'];

        case 'COTIZACIONES':
            rep_fecha($where, $params, 'ct.fecha_cotizacion', $f);
            rep_id($where, $params, 'ct.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'ct.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'ct.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM cotizaciones_detalle ctd_f WHERE ctd_f.cotizacion_id = ct.id AND ctd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['ct.folio', 'ct.cliente_nombre_snapshot', 'cl.nombre_razon_social']);
            $sql = "SELECT ct.fecha_cotizacion AS fecha, ct.folio,
                           COALESCE(NULLIF(ct.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                           ct.vigencia_hasta AS vigencia, ct.estado, (ct.total - ct.impuesto_total) AS subtotal, ct.impuesto_total AS impuesto, ct.total,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM cotizaciones ct
                    LEFT JOIN clientes cl ON cl.id = ct.cliente_id
                    LEFT JOIN usuarios u ON u.id = ct.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY ct.fecha_cotizacion DESC, ct.id DESC'];

        case 'APARTADOS':
            rep_fecha($where, $params, 'ap.fecha_apartado', $f);
            rep_id($where, $params, 'ap.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'ap.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'ap.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM apartados_detalle ad_f WHERE ad_f.apartado_id = ap.id AND ad_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['ap.folio', 'cl.nombre_razon_social']);
            $sql = "SELECT ap.fecha_apartado AS fecha, ap.folio, cl.nombre_razon_social AS cliente,
                           ap.reservado_hasta, ap.estado, ap.total, ap.importe_anticipado AS anticipado,
                           ap.saldo_pendiente AS saldo, COALESCE(u.usuario,'—') AS usuario
                    FROM apartados ap
                    INNER JOIN clientes cl ON cl.id = ap.cliente_id
                    LEFT JOIN usuarios u ON u.id = ap.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY ap.fecha_apartado DESC, ap.id DESC'];

        case 'MOVIMIENTOS_USUARIOS':
            rep_fecha($where, $params, 'au.fecha_hora', $f);
            rep_id($where, $params, 'au.usuario_id', 'usuario_id', $f['usuario_id']);
            if ($f['estado'] !== '') {
                $where[] = 'au.accion = :accion_auditoria';
                $params['accion_auditoria'] = $f['estado'];
            }
            rep_buscar($where, $params, $f['buscar'], ['u.usuario', 'au.accion', 'au.modulo', 'au.entidad_tabla', 'au.descripcion', 'au.ip']);
            $sql = "SELECT au.fecha_hora AS fecha, COALESCE(u.usuario,'Sistema') AS usuario, au.accion,
                           au.modulo, au.entidad_tabla AS entidad, COALESCE(CAST(au.entidad_id AS CHAR), '') AS registro_id,
                           COALESCE(au.descripcion,'') AS descripcion, COALESCE(au.ip,'') AS ip
                    FROM auditoria au
                    LEFT JOIN usuarios u ON u.id = au.usuario_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY au.fecha_hora DESC, au.id DESC'];
    }

    throw new InvalidArgumentException('Reporte no soportado.');
}

function rep_filtros(array $def): array
{
    $permitidos = $def['filtros'] ?? [];
    $estado = rep_texto($_GET['estado'] ?? '', 60);
    $estados = array_map('strval', $def['estados'] ?? []);
    $estadoAbierto = !empty($def['estado_abierto']);
    if ($estado !== '' && !$estadoAbierto && !in_array($estado, $estados, true)) {
        $estado = '';
    }

    return [
        'buscar' => rep_texto($_GET['buscar'] ?? '', 120),
        'fecha_desde' => in_array('fecha', $permitidos, true) ? rep_fecha_valida($_GET['fecha_desde'] ?? '') : '',
        'fecha_hasta' => in_array('fecha', $permitidos, true) ? rep_fecha_valida($_GET['fecha_hasta'] ?? '') : '',
        'producto_id' => in_array('producto', $permitidos, true) ? rep_entero($_GET['producto_id'] ?? 0, 0, PHP_INT_MAX, 0) : 0,
        'proveedor_id' => in_array('proveedor', $permitidos, true) ? rep_entero($_GET['proveedor_id'] ?? 0, 0, PHP_INT_MAX, 0) : 0,
        'cliente_id' => in_array('cliente', $permitidos, true) ? rep_entero($_GET['cliente_id'] ?? 0, 0, PHP_INT_MAX, 0) : 0,
        'usuario_id' => in_array('usuario', $permitidos, true) ? rep_entero($_GET['usuario_id'] ?? 0, 0, PHP_INT_MAX, 0) : 0,
        'almacen_id' => in_array('almacen', $permitidos, true) ? rep_entero($_GET['almacen_id'] ?? 0, 0, PHP_INT_MAX, 0) : 0,
        'estado' => in_array('estado', $permitidos, true) ? $estado : '',
    ];
}

function rep_codigo_reporte($valor): string
{
    $codigo = strtoupper(rep_texto($valor, 60));
    $def = rep_definiciones();
    if (!isset($def[$codigo])) {
        si_responder_json(false, 'Selecciona un reporte válido.', [], 400);
    }
    return $codigo;
}

function rep_fecha(array &$where, array &$params, string $campo, array $f): void
{
    if ($f['fecha_desde'] !== '') {
        $where[] = $campo . ' >= :fecha_desde';
        $params['fecha_desde'] = $f['fecha_desde'];
    }
    if ($f['fecha_hasta'] !== '') {
        $where[] = $campo . ' < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)';
        $params['fecha_hasta'] = $f['fecha_hasta'];
    }
}

function rep_id(array &$where, array &$params, string $campo, string $param, int $valor): void
{
    if ($valor > 0) {
        $where[] = $campo . ' = :' . $param;
        $params[$param] = $valor;
    }
}

function rep_estado(array &$where, array &$params, string $campo, string $estado): void
{
    if ($estado !== '') {
        $where[] = $campo . ' = :estado_filtro';
        $params['estado_filtro'] = $estado;
    }
}

function rep_buscar(array &$where, array &$params, string $buscar, array $campos): void
{
    if ($buscar === '' || !$campos) {
        return;
    }

    $or = [];
    foreach (array_values($campos) as $i => $campo) {
        $nombre = 'buscar_' . ($i + 1);
        $or[] = $campo . ' LIKE :' . $nombre;
        $params[$nombre] = '%' . $buscar . '%';
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}

function rep_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $nombre => $valor) {
        $stmt->bindValue(':' . $nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function rep_entero($valor, int $min, int $max, int $predeterminado): int
{
    if (filter_var($valor, FILTER_VALIDATE_INT) === false) {
        return $predeterminado;
    }
    $numero = (int) $valor;
    return max($min, min($max, $numero));
}

function rep_texto($valor, int $max): string
{
    $texto = trim((string) $valor);
    if (mb_strlen($texto) > $max) {
        $texto = mb_substr($texto, 0, $max);
    }
    return $texto;
}

function rep_fecha_valida($valor): string
{
    $fecha = trim((string) $valor);
    if ($fecha === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return ($d && $d->format('Y-m-d') === $fecha) ? $fecha : '';
}

function rep_csv_seguro($valor, string $tipo = 'texto'): string
{
    if ($valor === null) {
        return '';
    }
    $texto = (string) $valor;
    if (in_array($tipo, ['moneda', 'cantidad', 'entero'], true)) {
        return $texto;
    }
    if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
        return "'" . $texto;
    }
    return $texto;
}
