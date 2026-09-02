<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';
require_once __DIR__ . '/../inc/xlsx_simple.php';

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
        case 'EXPORTAR_XLSX':
            // La exportación normal conserva exactamente los permisos del reporte.
            // rep_exportar_* vuelve a validar el reporte con rep_codigo_reporte().
            if ($accion === 'EXPORTAR_XLSX') {
                rep_exportar_xlsx($conexion);
            } else {
                rep_exportar_csv($conexion);
            }
            break;

        case 'EXPORTAR_CONTABLE_XLSX':
            // El paquete contable sí requiere el permiso financiero específico.
            if (!si_tiene_permiso('contabilidad.exportar')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'No tienes permiso para exportar información contable.';
                exit;
            }
            rep_exportar_contable_xlsx($conexion);
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
    $estadosRecepcion = ['BORRADOR', 'CONFIRMADA', 'CANCELADA'];
    $estadosCuenta = ['PENDIENTE', 'PARCIAL', 'PAGADA', 'VENCIDA', 'CANCELADA'];
    $estadosPago = ['APLICADO', 'CANCELADO'];
    $estadosCotizacion = ['BORRADOR', 'GENERADA', 'ACEPTADA', 'RECHAZADA', 'VENCIDA', 'CONVERTIDA'];
    $estadosApartado = ['ACTIVO', 'COMPLETADO', 'VENCIDO', 'CANCELADO'];
    $estadosProduccion = ['BORRADOR', 'CONFIRMADA', 'CANCELADA'];
    $estadosAjuste = ['BORRADOR', 'CONFIRMADO', 'CANCELADO'];
    $estadosDevolucion = ['BORRADOR', 'CONFIRMADA', 'CANCELADA'];
    $estadosRegularizacion = ['PENDIENTE', 'LIQUIDADA', 'CANCELADA'];
    $estadosInventario = ['NORMAL', 'REORDEN', 'CRITICO', 'SIN_DISPONIBLE', 'SIN_STOCK'];
    $estadosKardex = ['BORRADOR', 'APLICADO', 'REVERTIDO'];
    $estadosTransferencia = ['BORRADOR', 'APLICADO', 'REVERTIDO'];
    $accionesAuditoria = ['CREAR', 'MODIFICAR', 'CANCELAR', 'RESTAURAR', 'DESACTIVAR', 'REALIZAR_AJUSTE', 'REGISTRAR_PAGO', 'INICIAR_SESION', 'INICIAR_SESIÓN'];

    return [
        'COMPRAS' => [
            'codigo' => 'COMPRAS', 'nombre' => 'Compras',
            'descripcion' => 'Compras registradas con proveedor, condición de pago, moneda e importes históricos.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'estado', 'usuario'], 'estados' => $estadosCompra,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('proveedor', 'Proveedor'),
                rep_col('factura', 'Factura'), rep_col('condicion_pago', 'Condición'), rep_col('estado', 'Estado', 'estado'), rep_col('moneda_codigo', 'Moneda'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('descuento', 'Descuento', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
                rep_col('usuario', 'Usuario'),
            ],
        ],
        'VENTAS' => [
            'codigo' => 'VENTAS', 'nombre' => 'Ventas',
            'descripcion' => 'Ventas registradas con cliente, condición financiera, moneda e importes históricos.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosVenta,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'),
                rep_col('condicion_pago', 'Condición'), rep_col('estado', 'Estado', 'estado'), rep_col('moneda_codigo', 'Moneda'),
                rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('descuento', 'Descuento', 'moneda'), rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'),
                rep_col('usuario', 'Usuario'),
            ],
        ],
        'COMPRAS_PROVEEDOR' => [
            'codigo' => 'COMPRAS_PROVEEDOR', 'nombre' => 'Compras por proveedor',
            'descripcion' => 'Resumen de compras operativas por proveedor convertido a moneda base para poder comparar importes.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'estado'], 'estados' => $estadosCompra,
            'columnas' => [
                rep_col('proveedor', 'Proveedor'), rep_col('rfc', 'RFC'), rep_col('operaciones', 'Compras', 'entero'), rep_col('moneda_codigo', 'Moneda base'),
                rep_col('subtotal', 'Subtotal base', 'moneda_base'), rep_col('descuento', 'Descuento base', 'moneda_base'), rep_col('impuesto', 'Impuesto base', 'moneda_base'), rep_col('total', 'Total base', 'moneda_base'),
            ],
        ],
        'VENTAS_CLIENTE' => [
            'codigo' => 'VENTAS_CLIENTE', 'nombre' => 'Ventas por cliente',
            'descripcion' => 'Resumen de ventas confirmadas por cliente convertido a moneda base para poder comparar importes.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado'], 'estados' => $estadosVenta,
            'columnas' => [
                rep_col('cliente', 'Cliente'), rep_col('rfc', 'RFC'), rep_col('operaciones', 'Ventas', 'entero'), rep_col('moneda_codigo', 'Moneda base'),
                rep_col('subtotal', 'Subtotal base', 'moneda_base'), rep_col('descuento', 'Descuento base', 'moneda_base'), rep_col('impuesto', 'Impuesto base', 'moneda_base'), rep_col('total', 'Total base', 'moneda_base'),
            ],
        ],
        'RECEPCIONES' => [
            'codigo' => 'RECEPCIONES', 'nombre' => 'Recepciones de compra',
            'descripcion' => 'Recepciones físicas de compras por proveedor, documento, productos y almacenes involucrados.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'almacen', 'estado', 'usuario'], 'estados' => $estadosRecepcion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Recepción'), rep_col('compra', 'Compra'), rep_col('proveedor', 'Proveedor'),
                rep_col('estado', 'Estado', 'estado'), rep_col('documento', 'Documento'), rep_col('productos', 'Productos', 'entero'), rep_col('almacenes', 'Almacenes', 'entero'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'INVENTARIO' => [
            'codigo' => 'INVENTARIO', 'nombre' => 'Inventario actual',
            'descripcion' => 'Existencia física, reservada y disponible por producto y almacén.',
            'filtros' => ['almacen', 'producto', 'estado'], 'estados' => $estadosInventario,
            'columnas' => [
                rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'), rep_col('producto', 'Producto'), rep_col('tipo_producto', 'Tipo'),
                rep_col('existencia_fisica', 'Física', 'cantidad'), rep_col('reservada', 'Reservada', 'cantidad'), rep_col('disponible', 'Disponible', 'cantidad'),
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'), rep_col('unidad', 'Unidad'), rep_col('moneda_codigo', 'Moneda base'),
                rep_col('costo_promedio', 'Costo prom.', 'moneda_base'), rep_col('valor_inventario', 'Valor físico', 'moneda_base'), rep_col('estado', 'Stock', 'estado'),
            ],
        ],
        'KARDEX' => [
            'codigo' => 'KARDEX', 'nombre' => 'Kardex',
            'descripcion' => 'Consulta rápida de movimientos. Para análisis y exportación avanzada usa Inventario → Kardex.',
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
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'), rep_col('unidad', 'Unidad'), rep_col('moneda_codigo', 'Moneda base'),
                rep_col('costo_promedio', 'Costo prom.', 'moneda_base'), rep_col('estado', 'Stock', 'estado'),
            ],
        ],
        'PRODUCTO_TERMINADO' => [
            'codigo' => 'PRODUCTO_TERMINADO', 'nombre' => 'Producto terminado',
            'descripcion' => 'Existencias actuales exclusivamente de productos terminados.',
            'filtros' => ['almacen', 'producto', 'estado'], 'estados' => $estadosInventario,
            'columnas' => [
                rep_col('almacen', 'Almacén'), rep_col('sku', 'SKU'), rep_col('producto', 'Producto terminado'),
                rep_col('existencia_fisica', 'Física', 'cantidad'), rep_col('reservada', 'Reservada', 'cantidad'), rep_col('disponible', 'Disponible', 'cantidad'),
                rep_col('stock_minimo', 'Mínimo', 'cantidad'), rep_col('punto_reorden', 'Punto reorden', 'cantidad'), rep_col('unidad', 'Unidad'), rep_col('moneda_codigo', 'Moneda base'),
                rep_col('costo_promedio', 'Costo prom.', 'moneda_base'), rep_col('estado', 'Stock', 'estado'),
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
        'AJUSTES' => [
            'codigo' => 'AJUSTES', 'nombre' => 'Ajustes de inventario',
            'descripcion' => 'Ajustes positivos y negativos de inventario, excluyendo mermas que tienen su propio reporte.',
            'filtros' => ['fecha', 'almacen', 'producto', 'estado', 'usuario'], 'estados' => $estadosAjuste,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('tipo', 'Tipo'), rep_col('estado', 'Estado', 'estado'),
                rep_col('productos', 'Productos', 'entero'), rep_col('almacenes', 'Almacenes', 'entero'), rep_col('motivo', 'Motivo'), rep_col('usuario', 'Usuario'),
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
        'DEVOLUCIONES_VENTA' => [
            'codigo' => 'DEVOLUCIONES_VENTA', 'nombre' => 'Devoluciones de clientes',
            'descripcion' => 'Devoluciones vinculadas a ventas con entrada física, compensación de CxC y reembolso cuando aplica.',
            'filtros' => ['fecha', 'cliente', 'producto', 'almacen', 'estado', 'usuario'], 'estados' => $estadosDevolucion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Devolución'), rep_col('venta', 'Venta'), rep_col('cliente', 'Cliente'), rep_col('estado', 'Estado', 'estado'),
                rep_col('moneda_codigo', 'Moneda'), rep_col('total', 'Total devuelto', 'moneda'), rep_col('compensado', 'Compensado CxC', 'moneda'), rep_col('reembolso', 'Reembolso', 'moneda'),
                rep_col('regularizacion_estado', 'Regularización', 'estado'), rep_col('motivo', 'Motivo'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'DEVOLUCIONES_COMPRA' => [
            'codigo' => 'DEVOLUCIONES_COMPRA', 'nombre' => 'Devoluciones a proveedores',
            'descripcion' => 'Devoluciones vinculadas a compras con salida física, compensación de CxP y reintegro cuando aplica.',
            'filtros' => ['fecha', 'proveedor', 'producto', 'almacen', 'estado', 'usuario'], 'estados' => $estadosDevolucion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Devolución'), rep_col('compra', 'Compra'), rep_col('proveedor', 'Proveedor'), rep_col('estado', 'Estado', 'estado'),
                rep_col('moneda_codigo', 'Moneda'), rep_col('total', 'Total devuelto', 'moneda'), rep_col('compensado', 'Compensado CxP', 'moneda'), rep_col('reintegro', 'Reintegro', 'moneda'),
                rep_col('regularizacion_estado', 'Regularización', 'estado'), rep_col('motivo', 'Motivo'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'REGULARIZACIONES_DEVOLUCIONES' => [
            'codigo' => 'REGULARIZACIONES_DEVOLUCIONES', 'nombre' => 'Regularizaciones de devoluciones',
            'descripcion' => 'Reembolsos a clientes y reintegros de proveedores generados por devoluciones.',
            'filtros' => ['fecha', 'cliente', 'proveedor', 'estado', 'usuario'], 'estados' => $estadosRegularizacion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Regularización'), rep_col('tipo', 'Tipo'), rep_col('devolucion', 'Devolución'), rep_col('tercero', 'Cliente / proveedor'),
                rep_col('moneda_codigo', 'Moneda'), rep_col('importe', 'Importe', 'moneda'), rep_col('estado', 'Estado', 'estado'), rep_col('metodo', 'Método'), rep_col('referencia', 'Referencia'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'TRANSFERENCIAS' => [
            'codigo' => 'TRANSFERENCIAS', 'nombre' => 'Transferencias entre almacenes',
            'descripcion' => 'Transferencias internas entre almacenes. No suma cantidades de productos con unidades diferentes.',
            'filtros' => ['fecha', 'almacen', 'producto', 'estado', 'usuario'], 'estados' => $estadosTransferencia,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Transferencia'), rep_col('estado', 'Estado', 'estado'), rep_col('origen', 'Origen'), rep_col('destino', 'Destino'),
                rep_col('productos', 'Productos', 'entero'), rep_col('reverso', 'Reverso'), rep_col('motivo', 'Motivo'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'CUENTAS_PAGAR' => [
            'codigo' => 'CUENTAS_PAGAR', 'nombre' => 'Cuentas por pagar',
            'descripcion' => 'Deudas con proveedores, pagos acumulados, saldos y vencimientos en su moneda original.',
            'filtros' => ['fecha', 'proveedor', 'estado'], 'estados' => $estadosCuenta,
            'columnas' => [
                rep_col('fecha', 'Documento', 'fecha'), rep_col('folio', 'Cuenta'), rep_col('compra', 'Compra'), rep_col('proveedor', 'Proveedor'), rep_col('moneda_codigo', 'Moneda'),
                rep_col('importe_original', 'Original', 'moneda'), rep_col('pagado', 'Pagado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'),
                rep_col('vencimiento', 'Vencimiento', 'fecha'), rep_col('estado', 'Estado', 'estado'),
            ],
        ],
        'CUENTAS_COBRAR' => [
            'codigo' => 'CUENTAS_COBRAR', 'nombre' => 'Cuentas por cobrar',
            'descripcion' => 'Créditos de clientes, abonos acumulados, saldos y vencimientos en su moneda original.',
            'filtros' => ['fecha', 'cliente', 'estado'], 'estados' => $estadosCuenta,
            'columnas' => [
                rep_col('fecha', 'Documento', 'fecha'), rep_col('folio', 'Cuenta'), rep_col('venta', 'Venta'), rep_col('cliente', 'Cliente'), rep_col('moneda_codigo', 'Moneda'),
                rep_col('importe_original', 'Original', 'moneda'), rep_col('pagado', 'Pagado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'),
                rep_col('vencimiento', 'Vencimiento', 'fecha'), rep_col('estado', 'Estado', 'estado'),
            ],
        ],
        'PAGOS' => [
            'codigo' => 'PAGOS', 'nombre' => 'Pagos a proveedores',
            'descripcion' => 'Pagos realizados a proveedores en la moneda histórica del pago.',
            'filtros' => ['fecha', 'proveedor', 'estado', 'usuario'], 'estados' => $estadosPago,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('proveedor', 'Proveedor'), rep_col('metodo', 'Método'),
                rep_col('importe', 'Importe', 'moneda'), rep_col('moneda_codigo', 'Moneda'), rep_col('referencia', 'Referencia'), rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'ABONOS' => [
            'codigo' => 'ABONOS', 'nombre' => 'Abonos de clientes',
            'descripcion' => 'Pagos y abonos recibidos de clientes en la moneda histórica del pago.',
            'filtros' => ['fecha', 'cliente', 'estado', 'usuario'], 'estados' => $estadosPago,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('metodo', 'Método'),
                rep_col('importe', 'Importe', 'moneda'), rep_col('moneda_codigo', 'Moneda'), rep_col('referencia', 'Referencia'), rep_col('estado', 'Estado', 'estado'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'COTIZACIONES' => [
            'codigo' => 'COTIZACIONES', 'nombre' => 'Cotizaciones',
            'descripcion' => 'Propuestas comerciales y su estado sin afectar inventario, respetando moneda y descuentos históricos.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosCotizacion,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('vigencia', 'Vigencia', 'fecha'),
                rep_col('estado', 'Estado', 'estado'), rep_col('moneda_codigo', 'Moneda'), rep_col('subtotal', 'Subtotal', 'moneda'), rep_col('descuento', 'Descuento', 'moneda'),
                rep_col('impuesto', 'Impuesto', 'moneda'), rep_col('total', 'Total', 'moneda'), rep_col('usuario', 'Usuario'),
            ],
        ],
        'APARTADOS' => [
            'codigo' => 'APARTADOS', 'nombre' => 'Apartados',
            'descripcion' => 'Reservas de productos con anticipos y saldo pendiente en la moneda del apartado.',
            'filtros' => ['fecha', 'cliente', 'producto', 'estado', 'usuario'], 'estados' => $estadosApartado,
            'columnas' => [
                rep_col('fecha', 'Fecha', 'fecha_hora'), rep_col('folio', 'Folio'), rep_col('cliente', 'Cliente'), rep_col('reservado_hasta', 'Reservado hasta', 'fecha_hora'),
                rep_col('estado', 'Estado', 'estado'), rep_col('moneda_codigo', 'Moneda'), rep_col('total', 'Total', 'moneda'), rep_col('anticipado', 'Anticipado', 'moneda'), rep_col('saldo', 'Saldo', 'moneda'), rep_col('usuario', 'Usuario'),
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


function rep_reporte_autorizado(string $codigo): bool
{
    $permisos = [
        'COMPRAS' => 'compras.ver',
        'COMPRAS_PROVEEDOR' => 'compras.ver',
        'RECEPCIONES' => 'recepciones.ver',
        'VENTAS' => 'ventas.ver',
        'VENTAS_CLIENTE' => 'ventas.ver',
        'INVENTARIO' => 'inventario.ver',
        'MATERIA_PRIMA' => 'inventario.ver',
        'PRODUCTO_TERMINADO' => 'inventario.ver',
        'KARDEX' => 'inventario.kardex',
        'MERMAS' => 'inventario.kardex',
        'AJUSTES' => 'inventario.kardex',
        'TRANSFERENCIAS' => 'inventario.kardex',
        'PRODUCCION' => 'produccion.ver',
        'DEVOLUCIONES_VENTA' => 'devoluciones.venta',
        'DEVOLUCIONES_COMPRA' => 'devoluciones.compra',
        'REGULARIZACIONES_DEVOLUCIONES' => 'devoluciones.regularizar',
        'CUENTAS_PAGAR' => 'cuentas_pagar.ver',
        'PAGOS' => 'cuentas_pagar.ver',
        'CUENTAS_COBRAR' => 'cuentas_cobrar.ver',
        'ABONOS' => 'cuentas_cobrar.ver',
        'COTIZACIONES' => 'cotizaciones.ver',
        'APARTADOS' => 'apartados.ver',
        'MOVIMIENTOS_USUARIOS' => 'auditoria.ver',
    ];

    $permiso = $permisos[$codigo] ?? '';
    return $permiso !== '' && si_tiene_permiso($permiso);
}

function rep_catalogos(PDO $conexion): void
{
    $todas = rep_definiciones();
    $definiciones = array_filter(
        $todas,
        static fn(array $def, string $codigo): bool => rep_reporte_autorizado($codigo),
        ARRAY_FILTER_USE_BOTH
    );

    $filtrosNecesarios = [];
    foreach ($definiciones as $def) {
        foreach (($def['filtros'] ?? []) as $filtro) {
            $filtrosNecesarios[(string) $filtro] = true;
        }
    }

    $almacenes = isset($filtrosNecesarios['almacen'])
        ? $conexion->query("SELECT id, codigo, nombre, activo FROM almacenes ORDER BY nombre ASC, id ASC")->fetchAll()
        : [];
    $productos = isset($filtrosNecesarios['producto'])
        ? $conexion->query("SELECT id, sku, nombre, tipo, activo FROM productos ORDER BY nombre ASC, id ASC")->fetchAll()
        : [];
    $proveedores = isset($filtrosNecesarios['proveedor'])
        ? $conexion->query("SELECT id, codigo, razon_social AS nombre, activo FROM proveedores ORDER BY razon_social ASC, id ASC")->fetchAll()
        : [];
    $clientes = isset($filtrosNecesarios['cliente'])
        ? $conexion->query("SELECT id, codigo, nombre_razon_social AS nombre, activo FROM clientes ORDER BY nombre_razon_social ASC, id ASC")->fetchAll()
        : [];
    $usuarios = isset($filtrosNecesarios['usuario'])
        ? $conexion->query(
            "SELECT id, usuario, TRIM(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)) AS nombre, activo
             FROM usuarios
             ORDER BY usuario ASC, id ASC"
        )->fetchAll()
        : [];
    $monedaBase = (string) ($conexion->query(
        "SELECT codigo FROM monedas WHERE es_base = 1 ORDER BY activo DESC, id ASC LIMIT 1"
    )->fetchColumn() ?: 'MXN');

    if (isset($definiciones['MOVIMIENTOS_USUARIOS'])) {
        $accionesAuditoria = $conexion->query(
            "SELECT DISTINCT accion FROM auditoria WHERE accion IS NOT NULL AND accion <> '' ORDER BY accion ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($accionesAuditoria) {
            $definiciones['MOVIMIENTOS_USUARIOS']['estados'] = array_values(array_map('strval', $accionesAuditoria));
        }
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
        'moneda_base' => strtoupper($monedaBase),
        'puede_exportar' => si_tiene_permiso('reportes.ver'),
        'puede_exportar_contable' => si_tiene_permiso('contabilidad.exportar'),
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
    $total = rep_total_filas($conexion, $sqlBase, $params);

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
    $def = rep_definiciones()[$codigo];
    $filtros = rep_filtros($def);
    [$sqlBase, $params, $orden] = rep_sql($codigo, $filtros);

    $total = rep_total_filas($conexion, $sqlBase, $params);
    rep_validar_limite_exportacion($total);

    $stmt = $conexion->prepare($sqlBase . ' ' . $orden);
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

function rep_exportar_xlsx(PDO $conexion): void
{
    $codigo = rep_codigo_reporte($_GET['reporte'] ?? '');
    $def = rep_definiciones()[$codigo];
    $filtros = rep_filtros($def);
    [$sqlBase, $params, $orden] = rep_sql($codigo, $filtros);

    $total = rep_total_filas($conexion, $sqlBase, $params);
    rep_validar_limite_exportacion($total);

    $stmt = $conexion->prepare($sqlBase . ' ' . $orden);
    rep_bind($stmt, $params);
    $stmt->execute();
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $monedaBase = (string) ($conexion->query(
        "SELECT codigo FROM monedas WHERE es_base = 1 ORDER BY activo DESC, id ASC LIMIT 1"
    )->fetchColumn() ?: 'MXN');

    $hojas = [[
        'nombre' => 'Reporte',
        'columnas' => rep_xlsx_columnas($def['columnas']),
        'filas' => $filas,
    ]];

    foreach (rep_xlsx_detalles_especiales($conexion, $codigo, $filtros) as $hoja) {
        $hojas[] = $hoja;
    }

    $hojas[] = [
        'nombre' => 'Resumen',
        'columnas' => [
            ['campo' => 'concepto', 'titulo' => 'Concepto', 'tipo' => 'texto', 'ancho' => 28],
            ['campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'texto', 'ancho' => 45],
        ],
        'filas' => rep_xlsx_resumen($def, $filtros, $total, strtoupper($monedaBase)),
    ];

    si_xlsx_descargar('reporte_' . strtolower($codigo) . '_' . date('Ymd_His') . '.xlsx', $hojas);
}

function rep_exportar_contable_xlsx(PDO $conexion): void
{
    $hoy = new DateTimeImmutable('today');
    $desdeDefecto = $hoy->modify('first day of this month')->format('Y-m-d');
    $hastaDefecto = $hoy->format('Y-m-d');

    $desde = rep_fecha_valida($_GET['fecha_desde'] ?? '') ?: $desdeDefecto;
    $hasta = rep_fecha_valida($_GET['fecha_hasta'] ?? '') ?: $hastaDefecto;

    $dDesde = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
    $dHasta = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
    if (!$dDesde || !$dHasta || $dDesde > $dHasta) {
        rep_error_descarga('El periodo contable no es válido.', 422);
    }
    if ($dDesde->diff($dHasta)->days > 366) {
        rep_error_descarga('El paquete contable admite periodos de hasta 367 días. Divide la exportación en periodos más pequeños.', 422);
    }

    $monedaBase = strtoupper((string) ($conexion->query(
        "SELECT codigo FROM monedas WHERE es_base = 1 ORDER BY activo DESC, id ASC LIMIT 1"
    )->fetchColumn() ?: 'MXN'));

    $params = ['desde' => $desde, 'hasta' => $hasta];

    $ventas = rep_consultar_todo($conexion, rep_contable_sql_ventas(false), $params);
    $ventasDetalle = rep_consultar_todo($conexion, rep_contable_sql_ventas(true), $params);
    $compras = rep_consultar_todo($conexion, rep_contable_sql_compras(false), $params);
    $comprasDetalle = rep_consultar_todo($conexion, rep_contable_sql_compras(true), $params);
    $devVentas = rep_consultar_todo($conexion, rep_contable_sql_devoluciones_venta(), $params);
    $devCompras = rep_consultar_todo($conexion, rep_contable_sql_devoluciones_compra(), $params);
    $impuestos = rep_contable_resumen_impuestos($conexion, $params);

    $totalFilas = count($ventasDetalle) + count($comprasDetalle) + count($devVentas) + count($devCompras);
    rep_validar_limite_exportacion($totalFilas, 100000);

    $totales = rep_contable_totales($ventas, $compras, $devVentas, $devCompras);
    $devVentasOps = rep_contar_distintos($devVentas, 'devolucion');
    $devComprasOps = rep_contar_distintos($devCompras, 'devolucion');
    $resumen = rep_contable_resumen(
        $desde,
        $hasta,
        $monedaBase,
        $totales,
        count($ventas),
        count($compras),
        $devVentasOps,
        count($devVentas),
        $devComprasOps,
        count($devCompras)
    );

    $archivo = 'entrega_contable_' . str_replace('-', '', $desde) . '_a_' . str_replace('-', '', $hasta) . '_' . date('His') . '.xlsx';

    rep_registrar_exportacion_contable($conexion, $desde, $hasta, $archivo, [
        'ventas' => count($ventas),
        'compras' => count($compras),
        'devoluciones_venta' => count($devVentas),
        'devoluciones_compra' => count($devCompras),
        'moneda_base' => $monedaBase,
    ]);

    $hojas = [
        [
            'nombre' => 'Resumen',
            'columnas' => [
                ['campo' => 'seccion', 'titulo' => 'Sección', 'tipo' => 'texto', 'ancho' => 24],
                ['campo' => 'concepto', 'titulo' => 'Concepto', 'tipo' => 'texto', 'ancho' => 38],
                ['campo' => 'valor', 'titulo' => 'Valor', 'tipo' => 'texto', 'ancho' => 34],
            ],
            'filas' => $resumen,
        ],
        [
            'nombre' => 'Ventas',
            'columnas' => rep_contable_columnas_ventas(false),
            'filas' => $ventas,
        ],
        [
            'nombre' => 'Detalle ventas',
            'columnas' => rep_contable_columnas_ventas(true),
            'filas' => $ventasDetalle,
        ],
        [
            'nombre' => 'Compras',
            'columnas' => rep_contable_columnas_compras(false),
            'filas' => $compras,
        ],
        [
            'nombre' => 'Detalle compras',
            'columnas' => rep_contable_columnas_compras(true),
            'filas' => $comprasDetalle,
        ],
        [
            'nombre' => 'Impuestos por tasa',
            'columnas' => [
                ['campo' => 'origen', 'titulo' => 'Origen', 'tipo' => 'texto', 'ancho' => 24],
                ['campo' => 'tasa_codigo', 'titulo' => 'Impuesto', 'tipo' => 'texto', 'ancho' => 18],
                ['campo' => 'tasa_pct', 'titulo' => 'Tasa %', 'tipo' => 'numero', 'ancho' => 12],
                ['campo' => 'base_base', 'titulo' => 'Base en moneda base', 'tipo' => 'numero', 'ancho' => 22],
                ['campo' => 'impuesto_base', 'titulo' => 'Impuesto en moneda base', 'tipo' => 'numero', 'ancho' => 24],
                ['campo' => 'total_base', 'titulo' => 'Total en moneda base', 'tipo' => 'numero', 'ancho' => 22],
            ],
            'filas' => $impuestos,
        ],
        [
            'nombre' => 'Dev ventas',
            'columnas' => rep_contable_columnas_devolucion('VENTA'),
            'filas' => $devVentas,
        ],
        [
            'nombre' => 'Dev compras',
            'columnas' => rep_contable_columnas_devolucion('COMPRA'),
            'filas' => $devCompras,
        ],
    ];

    si_xlsx_descargar($archivo, $hojas);
}

function rep_error_descarga(string $mensaje, int $codigo = 400): never
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mensaje;
    exit;
}

function rep_consultar_todo(PDO $conexion, string $sql, array $params): array
{
    $stmt = $conexion->prepare($sql);
    rep_bind($stmt, $params);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rep_contable_sql_ventas(bool $detalle): string
{
    $where = "v.estado = 'CONFIRMADA' AND v.fecha_venta >= :desde AND v.fecha_venta < DATE_ADD(:hasta, INTERVAL 1 DAY)";
    if (!$detalle) {
        return "SELECT v.fecha_venta AS fecha, v.folio,
                       COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                       COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                       v.condicion_pago, mon.codigo AS moneda, v.tipo_cambio_a_base,
                       (v.subtotal + v.descuento_total) AS importe_bruto,
                       v.descuento_total AS descuento, v.subtotal AS base_sin_impuesto,
                       v.impuesto_total AS impuesto, v.total,
                       (v.subtotal + v.descuento_total) * v.tipo_cambio_a_base AS importe_bruto_base,
                       v.descuento_total * v.tipo_cambio_a_base AS descuento_base,
                       v.subtotal * v.tipo_cambio_a_base AS base_sin_impuesto_base,
                       v.impuesto_total * v.tipo_cambio_a_base AS impuesto_base,
                       v.total * v.tipo_cambio_a_base AS total_base,
                       COALESCE(u.usuario,'—') AS usuario
                FROM ventas v
                LEFT JOIN clientes cl ON cl.id = v.cliente_id
                INNER JOIN monedas mon ON mon.id = v.moneda_id
                LEFT JOIN usuarios u ON u.id = v.created_by
                WHERE {$where}
                ORDER BY v.fecha_venta ASC, v.id ASC";
    }

    return "SELECT v.fecha_venta AS fecha, v.folio,
                   COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                   COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                   vd.renglon, COALESCE(a.nombre,'') AS almacen, vd.sku_snapshot AS sku, vd.producto_nombre_snapshot AS producto,
                   COALESCE(pr.nombre, vd.unidad_nombre_snapshot) AS presentacion, vd.cantidad,
                   vd.precio_unitario, vd.descuento_pct, vd.descuento_importe,
                   COALESCE(ti.codigo, CASE WHEN vd.impuesto_pct_snapshot = 0 THEN 'TASA 0%' ELSE CONCAT('TASA ', vd.impuesto_pct_snapshot, '%') END) AS tasa_codigo,
                   vd.impuesto_pct_snapshot AS tasa_pct,
                   (vd.subtotal + vd.descuento_importe) AS importe_bruto, vd.subtotal AS base_sin_impuesto,
                   vd.impuesto_importe AS impuesto, vd.total,
                   mon.codigo AS moneda, v.tipo_cambio_a_base,
                   vd.subtotal * v.tipo_cambio_a_base AS base_sin_impuesto_base,
                   vd.impuesto_importe * v.tipo_cambio_a_base AS impuesto_base,
                   vd.total * v.tipo_cambio_a_base AS total_base
            FROM ventas v
            INNER JOIN ventas_detalle vd ON vd.venta_id = v.id
            LEFT JOIN clientes cl ON cl.id = v.cliente_id
            LEFT JOIN almacenes a ON a.id = vd.almacen_id
            LEFT JOIN presentaciones_producto pr ON pr.id = vd.presentacion_id
            LEFT JOIN tasas_impuesto ti ON ti.id = vd.tasa_impuesto_id
            INNER JOIN monedas mon ON mon.id = v.moneda_id
            WHERE {$where}
            ORDER BY v.fecha_venta ASC, v.id ASC, vd.renglon ASC";
}

function rep_contable_sql_compras(bool $detalle): string
{
    $where = "c.estado IN ('PENDIENTE_RECEPCION','RECIBIDA_PARCIAL','RECIBIDA') AND c.fecha_compra >= :desde AND c.fecha_compra < DATE_ADD(:hasta, INTERVAL 1 DAY)";
    if (!$detalle) {
        return "SELECT c.fecha_compra AS fecha, c.fecha_factura, c.folio,
                       COALESCE(NULLIF(c.numero_factura,''),'') AS documento_proveedor,
                       COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                       COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                       c.condicion_pago, c.estado, mon.codigo AS moneda, c.tipo_cambio_a_base,
                       c.subtotal AS importe_bruto,
                       c.descuento_total AS descuento, (c.subtotal - c.descuento_total) AS base_sin_impuesto,
                       c.impuesto_total AS impuesto, c.total,
                       c.subtotal * c.tipo_cambio_a_base AS importe_bruto_base,
                       c.descuento_total * c.tipo_cambio_a_base AS descuento_base,
                       (c.subtotal - c.descuento_total) * c.tipo_cambio_a_base AS base_sin_impuesto_base,
                       c.impuesto_total * c.tipo_cambio_a_base AS impuesto_base,
                       c.total * c.tipo_cambio_a_base AS total_base,
                       COALESCE(u.usuario,'—') AS usuario
                FROM compras c
                LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                INNER JOIN monedas mon ON mon.id = c.moneda_id
                LEFT JOIN usuarios u ON u.id = c.created_by
                WHERE {$where}
                ORDER BY c.fecha_compra ASC, c.id ASC";
    }

    return "SELECT c.fecha_compra AS fecha, c.fecha_factura, c.folio,
                   COALESCE(NULLIF(c.numero_factura,''),'') AS documento_proveedor,
                   COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                   COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                   cd.renglon, cd.sku_snapshot AS sku, cd.producto_nombre_snapshot AS producto,
                   COALESCE(pres.nombre, cd.unidad_nombre_snapshot) AS presentacion, cd.cantidad,
                   cd.precio_unitario, cd.descuento_pct, cd.descuento_importe,
                   COALESCE(ti.codigo, CASE WHEN cd.impuesto_pct_snapshot = 0 THEN 'TASA 0%' ELSE CONCAT('TASA ', cd.impuesto_pct_snapshot, '%') END) AS tasa_codigo,
                   cd.impuesto_pct_snapshot AS tasa_pct,
                   (cd.subtotal + cd.descuento_importe) AS importe_bruto, cd.subtotal AS base_sin_impuesto,
                   cd.impuesto_importe AS impuesto, cd.total,
                   mon.codigo AS moneda, c.tipo_cambio_a_base,
                   cd.subtotal * c.tipo_cambio_a_base AS base_sin_impuesto_base,
                   cd.impuesto_importe * c.tipo_cambio_a_base AS impuesto_base,
                   cd.total * c.tipo_cambio_a_base AS total_base
            FROM compras c
            INNER JOIN compras_detalle cd ON cd.compra_id = c.id
            LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
            LEFT JOIN presentaciones_producto pres ON pres.id = cd.presentacion_id
            LEFT JOIN tasas_impuesto ti ON ti.id = cd.tasa_impuesto_id
            INNER JOIN monedas mon ON mon.id = c.moneda_id
            WHERE {$where}
            ORDER BY c.fecha_compra ASC, c.id ASC, cd.renglon ASC";
}

function rep_contable_sql_devoluciones_venta(): string
{
    return "SELECT dv.fecha_devolucion AS fecha, dv.folio AS devolucion, v.folio AS operacion_origen,
                   COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS tercero,
                   COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                   vd.sku_snapshot AS sku, vd.producto_nombre_snapshot AS producto, dvd.cantidad_base,
                   um.codigo AS unidad_base,
                   CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END AS proporcion,
                   vd.impuesto_pct_snapshot AS tasa_pct,
                   (vd.subtotal * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) AS base_sin_impuesto,
                   (vd.impuesto_importe * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) AS impuesto,
                   dvd.importe AS total,
                   mon.codigo AS moneda, v.tipo_cambio_a_base,
                   (vd.subtotal * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) * v.tipo_cambio_a_base AS base_sin_impuesto_base,
                   (vd.impuesto_importe * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) * v.tipo_cambio_a_base AS impuesto_base,
                   dvd.importe * v.tipo_cambio_a_base AS total_base,
                   dv.motivo
            FROM devoluciones_venta dv
            INNER JOIN ventas v ON v.id = dv.venta_id
            INNER JOIN devoluciones_venta_detalle dvd ON dvd.devolucion_id = dv.id
            INNER JOIN ventas_detalle vd ON vd.id = dvd.venta_detalle_id
            INNER JOIN productos p ON p.id = dvd.producto_id
            LEFT JOIN clientes cl ON cl.id = dv.cliente_id
            INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
            INNER JOIN monedas mon ON mon.id = v.moneda_id
            WHERE dv.estado = 'CONFIRMADA'
              AND dv.fecha_devolucion >= :desde
              AND dv.fecha_devolucion < DATE_ADD(:hasta, INTERVAL 1 DAY)
            ORDER BY dv.fecha_devolucion ASC, dv.id ASC, dvd.id ASC";
}

function rep_contable_sql_devoluciones_compra(): string
{
    return "SELECT dc.fecha_devolucion AS fecha, dc.folio AS devolucion, c.folio AS operacion_origen,
                   COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS tercero,
                   COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                   cd.sku_snapshot AS sku, cd.producto_nombre_snapshot AS producto, dcd.cantidad_base,
                   um.codigo AS unidad_base,
                   CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END AS proporcion,
                   cd.impuesto_pct_snapshot AS tasa_pct,
                   (cd.subtotal * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) AS base_sin_impuesto,
                   (cd.impuesto_importe * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) AS impuesto,
                   dcd.importe AS total,
                   mon.codigo AS moneda, c.tipo_cambio_a_base,
                   (cd.subtotal * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) * c.tipo_cambio_a_base AS base_sin_impuesto_base,
                   (cd.impuesto_importe * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) * c.tipo_cambio_a_base AS impuesto_base,
                   dcd.importe * c.tipo_cambio_a_base AS total_base,
                   dc.motivo
            FROM devoluciones_compra dc
            INNER JOIN compras c ON c.id = dc.compra_id
            INNER JOIN devoluciones_compra_detalle dcd ON dcd.devolucion_id = dc.id
            INNER JOIN compras_detalle cd ON cd.id = dcd.compra_detalle_id
            INNER JOIN productos p ON p.id = dcd.producto_id
            LEFT JOIN proveedores pr ON pr.id = dc.proveedor_id
            INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
            INNER JOIN monedas mon ON mon.id = c.moneda_id
            WHERE dc.estado = 'CONFIRMADA'
              AND dc.fecha_devolucion >= :desde
              AND dc.fecha_devolucion < DATE_ADD(:hasta, INTERVAL 1 DAY)
            ORDER BY dc.fecha_devolucion ASC, dc.id ASC, dcd.id ASC";
}

function rep_contable_resumen_impuestos(PDO $conexion, array $params): array
{
    $sql = "SELECT origen, tasa_codigo, tasa_pct,
                   ROUND(SUM(base_base), 4) AS base_base,
                   ROUND(SUM(impuesto_base), 4) AS impuesto_base,
                   ROUND(SUM(total_base), 4) AS total_base
            FROM (
                SELECT 'VENTAS' AS origen,
                       COALESCE(ti.codigo, CONCAT('TASA ', vd.impuesto_pct_snapshot, '%')) AS tasa_codigo,
                       vd.impuesto_pct_snapshot AS tasa_pct,
                       vd.subtotal * v.tipo_cambio_a_base AS base_base,
                       vd.impuesto_importe * v.tipo_cambio_a_base AS impuesto_base,
                       vd.total * v.tipo_cambio_a_base AS total_base
                FROM ventas v
                INNER JOIN ventas_detalle vd ON vd.venta_id = v.id
                LEFT JOIN tasas_impuesto ti ON ti.id = vd.tasa_impuesto_id
                WHERE v.estado = 'CONFIRMADA' AND v.fecha_venta >= :desde_v AND v.fecha_venta < DATE_ADD(:hasta_v, INTERVAL 1 DAY)

                UNION ALL

                SELECT 'DEVOLUCIONES VENTA' AS origen,
                       COALESCE(ti.codigo, CONCAT('TASA ', vd.impuesto_pct_snapshot, '%')) AS tasa_codigo,
                       vd.impuesto_pct_snapshot AS tasa_pct,
                       -(vd.subtotal * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) * v.tipo_cambio_a_base AS base_base,
                       -(vd.impuesto_importe * CASE WHEN vd.cantidad_base > 0 THEN dvd.cantidad_base / vd.cantidad_base ELSE 0 END) * v.tipo_cambio_a_base AS impuesto_base,
                       -(dvd.importe * v.tipo_cambio_a_base) AS total_base
                FROM devoluciones_venta dv
                INNER JOIN ventas v ON v.id = dv.venta_id
                INNER JOIN devoluciones_venta_detalle dvd ON dvd.devolucion_id = dv.id
                INNER JOIN ventas_detalle vd ON vd.id = dvd.venta_detalle_id
                LEFT JOIN tasas_impuesto ti ON ti.id = vd.tasa_impuesto_id
                WHERE dv.estado = 'CONFIRMADA' AND dv.fecha_devolucion >= :desde_dv AND dv.fecha_devolucion < DATE_ADD(:hasta_dv, INTERVAL 1 DAY)

                UNION ALL

                SELECT 'COMPRAS' AS origen,
                       COALESCE(ti.codigo, CONCAT('TASA ', cd.impuesto_pct_snapshot, '%')) AS tasa_codigo,
                       cd.impuesto_pct_snapshot AS tasa_pct,
                       cd.subtotal * c.tipo_cambio_a_base AS base_base,
                       cd.impuesto_importe * c.tipo_cambio_a_base AS impuesto_base,
                       cd.total * c.tipo_cambio_a_base AS total_base
                FROM compras c
                INNER JOIN compras_detalle cd ON cd.compra_id = c.id
                LEFT JOIN tasas_impuesto ti ON ti.id = cd.tasa_impuesto_id
                WHERE c.estado IN ('PENDIENTE_RECEPCION','RECIBIDA_PARCIAL','RECIBIDA') AND c.fecha_compra >= :desde_c AND c.fecha_compra < DATE_ADD(:hasta_c, INTERVAL 1 DAY)

                UNION ALL

                SELECT 'DEVOLUCIONES COMPRA' AS origen,
                       COALESCE(ti.codigo, CONCAT('TASA ', cd.impuesto_pct_snapshot, '%')) AS tasa_codigo,
                       cd.impuesto_pct_snapshot AS tasa_pct,
                       -(cd.subtotal * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) * c.tipo_cambio_a_base AS base_base,
                       -(cd.impuesto_importe * CASE WHEN cd.cantidad_base > 0 THEN dcd.cantidad_base / cd.cantidad_base ELSE 0 END) * c.tipo_cambio_a_base AS impuesto_base,
                       -(dcd.importe * c.tipo_cambio_a_base) AS total_base
                FROM devoluciones_compra dc
                INNER JOIN compras c ON c.id = dc.compra_id
                INNER JOIN devoluciones_compra_detalle dcd ON dcd.devolucion_id = dc.id
                INNER JOIN compras_detalle cd ON cd.id = dcd.compra_detalle_id
                LEFT JOIN tasas_impuesto ti ON ti.id = cd.tasa_impuesto_id
                WHERE dc.estado = 'CONFIRMADA' AND dc.fecha_devolucion >= :desde_dc AND dc.fecha_devolucion < DATE_ADD(:hasta_dc, INTERVAL 1 DAY)
            ) t
            GROUP BY origen, tasa_codigo, tasa_pct
            ORDER BY CASE origen WHEN 'VENTAS' THEN 1 WHEN 'DEVOLUCIONES VENTA' THEN 2 WHEN 'COMPRAS' THEN 3 ELSE 4 END, tasa_pct DESC";

    $p = [
        'desde_v' => $params['desde'], 'hasta_v' => $params['hasta'],
        'desde_dv' => $params['desde'], 'hasta_dv' => $params['hasta'],
        'desde_c' => $params['desde'], 'hasta_c' => $params['hasta'],
        'desde_dc' => $params['desde'], 'hasta_dc' => $params['hasta'],
    ];
    return rep_consultar_todo($conexion, $sql, $p);
}

function rep_contable_totales(array $ventas, array $compras, array $devVentas, array $devCompras): array
{
    $suma = static function (array $filas, string $campo): float {
        $total = 0.0;
        foreach ($filas as $fila) {
            $total += (float) ($fila[$campo] ?? 0);
        }
        return round($total, 4);
    };

    $vBase = $suma($ventas, 'base_sin_impuesto_base');
    $vImp = $suma($ventas, 'impuesto_base');
    $vTotal = $suma($ventas, 'total_base');
    $dvBase = $suma($devVentas, 'base_sin_impuesto_base');
    $dvImp = $suma($devVentas, 'impuesto_base');
    $dvTotal = $suma($devVentas, 'total_base');
    $cBase = $suma($compras, 'base_sin_impuesto_base');
    $cImp = $suma($compras, 'impuesto_base');
    $cTotal = $suma($compras, 'total_base');
    $dcBase = $suma($devCompras, 'base_sin_impuesto_base');
    $dcImp = $suma($devCompras, 'impuesto_base');
    $dcTotal = $suma($devCompras, 'total_base');

    return [
        'ventas_base' => $vBase, 'ventas_impuesto' => $vImp, 'ventas_total' => $vTotal,
        'dev_ventas_base' => $dvBase, 'dev_ventas_impuesto' => $dvImp, 'dev_ventas_total' => $dvTotal,
        'ventas_netas_base' => round($vBase - $dvBase, 4), 'ventas_netas_impuesto' => round($vImp - $dvImp, 4), 'ventas_netas_total' => round($vTotal - $dvTotal, 4),
        'compras_base' => $cBase, 'compras_impuesto' => $cImp, 'compras_total' => $cTotal,
        'dev_compras_base' => $dcBase, 'dev_compras_impuesto' => $dcImp, 'dev_compras_total' => $dcTotal,
        'compras_netas_base' => round($cBase - $dcBase, 4), 'compras_netas_impuesto' => round($cImp - $dcImp, 4), 'compras_netas_total' => round($cTotal - $dcTotal, 4),
    ];
}

function rep_contar_distintos(array $filas, string $campo): int
{
    $valores = [];
    foreach ($filas as $fila) {
        $valor = trim((string) ($fila[$campo] ?? ''));
        if ($valor !== '') {
            $valores[$valor] = true;
        }
    }
    return count($valores);
}

function rep_contable_resumen(string $desde, string $hasta, string $monedaBase, array $t, int $nVentas, int $nCompras, int $nDevVentasOps, int $nDevVentasLineas, int $nDevComprasOps, int $nDevComprasLineas): array
{
    $dinero = static fn(float $v): string => number_format($v, 2, '.', ',');
    return [
        ['seccion' => 'Alcance', 'concepto' => 'Periodo', 'valor' => $desde . ' a ' . $hasta],
        ['seccion' => 'Alcance', 'concepto' => 'Moneda base para totales comparables', 'valor' => $monedaBase],
        ['seccion' => 'Alcance', 'concepto' => 'Uso', 'valor' => 'Entrega de datos al área contable. No genera, timbra ni sustituye CFDI/SAT.'],
        ['seccion' => 'Ventas', 'concepto' => 'Operaciones confirmadas', 'valor' => (string) $nVentas],
        ['seccion' => 'Ventas', 'concepto' => 'Base sin impuesto', 'valor' => $dinero($t['ventas_base']) . ' ' . $monedaBase],
        ['seccion' => 'Ventas', 'concepto' => 'Impuestos', 'valor' => $dinero($t['ventas_impuesto']) . ' ' . $monedaBase],
        ['seccion' => 'Ventas', 'concepto' => 'Total', 'valor' => $dinero($t['ventas_total']) . ' ' . $monedaBase],
        ['seccion' => 'Devoluciones venta', 'concepto' => 'Devoluciones confirmadas', 'valor' => (string) $nDevVentasOps],
        ['seccion' => 'Devoluciones venta', 'concepto' => 'Renglones devueltos', 'valor' => (string) $nDevVentasLineas],
        ['seccion' => 'Devoluciones venta', 'concepto' => 'Total devuelto', 'valor' => $dinero($t['dev_ventas_total']) . ' ' . $monedaBase],
        ['seccion' => 'Ventas netas informativas', 'concepto' => 'Base después de devoluciones', 'valor' => $dinero($t['ventas_netas_base']) . ' ' . $monedaBase],
        ['seccion' => 'Ventas netas informativas', 'concepto' => 'Impuestos después de devoluciones', 'valor' => $dinero($t['ventas_netas_impuesto']) . ' ' . $monedaBase],
        ['seccion' => 'Ventas netas informativas', 'concepto' => 'Total después de devoluciones', 'valor' => $dinero($t['ventas_netas_total']) . ' ' . $monedaBase],
        ['seccion' => 'Compras', 'concepto' => 'Operaciones válidas', 'valor' => (string) $nCompras],
        ['seccion' => 'Compras', 'concepto' => 'Base sin impuesto', 'valor' => $dinero($t['compras_base']) . ' ' . $monedaBase],
        ['seccion' => 'Compras', 'concepto' => 'Impuestos', 'valor' => $dinero($t['compras_impuesto']) . ' ' . $monedaBase],
        ['seccion' => 'Compras', 'concepto' => 'Total', 'valor' => $dinero($t['compras_total']) . ' ' . $monedaBase],
        ['seccion' => 'Devoluciones compra', 'concepto' => 'Devoluciones confirmadas', 'valor' => (string) $nDevComprasOps],
        ['seccion' => 'Devoluciones compra', 'concepto' => 'Renglones devueltos', 'valor' => (string) $nDevComprasLineas],
        ['seccion' => 'Devoluciones compra', 'concepto' => 'Total devuelto', 'valor' => $dinero($t['dev_compras_total']) . ' ' . $monedaBase],
        ['seccion' => 'Compras netas informativas', 'concepto' => 'Base después de devoluciones', 'valor' => $dinero($t['compras_netas_base']) . ' ' . $monedaBase],
        ['seccion' => 'Compras netas informativas', 'concepto' => 'Impuestos después de devoluciones', 'valor' => $dinero($t['compras_netas_impuesto']) . ' ' . $monedaBase],
        ['seccion' => 'Compras netas informativas', 'concepto' => 'Total después de devoluciones', 'valor' => $dinero($t['compras_netas_total']) . ' ' . $monedaBase],
    ];
}

function rep_contable_columnas_ventas(bool $detalle): array
{
    if (!$detalle) {
        return [
            ['campo'=>'fecha','titulo'=>'Fecha','tipo'=>'texto','ancho'=>20], ['campo'=>'folio','titulo'=>'Folio','tipo'=>'texto','ancho'=>18],
            ['campo'=>'cliente','titulo'=>'Cliente','tipo'=>'texto','ancho'=>34], ['campo'=>'rfc','titulo'=>'RFC','tipo'=>'texto','ancho'=>18],
            ['campo'=>'condicion_pago','titulo'=>'Condición pago','tipo'=>'texto','ancho'=>16], ['campo'=>'moneda','titulo'=>'Moneda','tipo'=>'texto','ancho'=>10],
            ['campo'=>'tipo_cambio_a_base','titulo'=>'Tipo cambio a base','tipo'=>'numero','ancho'=>18], ['campo'=>'importe_bruto','titulo'=>'Importe bruto','tipo'=>'numero','ancho'=>16],
            ['campo'=>'descuento','titulo'=>'Descuento','tipo'=>'numero','ancho'=>14], ['campo'=>'base_sin_impuesto','titulo'=>'Base sin impuesto','tipo'=>'numero','ancho'=>18],
            ['campo'=>'impuesto','titulo'=>'Impuesto','tipo'=>'numero','ancho'=>14], ['campo'=>'total','titulo'=>'Total','tipo'=>'numero','ancho'=>14],
            ['campo'=>'base_sin_impuesto_base','titulo'=>'Base moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto_base','titulo'=>'Impuesto moneda base','tipo'=>'numero','ancho'=>20],
            ['campo'=>'total_base','titulo'=>'Total moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'usuario','titulo'=>'Usuario','tipo'=>'texto','ancho'=>16],
        ];
    }
    return [
        ['campo'=>'fecha','titulo'=>'Fecha','tipo'=>'texto','ancho'=>20], ['campo'=>'folio','titulo'=>'Venta','tipo'=>'texto','ancho'=>18], ['campo'=>'cliente','titulo'=>'Cliente','tipo'=>'texto','ancho'=>32], ['campo'=>'rfc','titulo'=>'RFC','tipo'=>'texto','ancho'=>18],
        ['campo'=>'renglon','titulo'=>'Renglón','tipo'=>'numero','ancho'=>10], ['campo'=>'almacen','titulo'=>'Almacén','tipo'=>'texto','ancho'=>20], ['campo'=>'sku','titulo'=>'SKU','tipo'=>'texto','ancho'=>16], ['campo'=>'producto','titulo'=>'Producto','tipo'=>'texto','ancho'=>30],
        ['campo'=>'presentacion','titulo'=>'Presentación','tipo'=>'texto','ancho'=>18], ['campo'=>'cantidad','titulo'=>'Cantidad','tipo'=>'numero','ancho'=>14], ['campo'=>'precio_unitario','titulo'=>'Precio unitario','tipo'=>'numero','ancho'=>16],
        ['campo'=>'descuento_pct','titulo'=>'Descuento %','tipo'=>'numero','ancho'=>14], ['campo'=>'descuento_importe','titulo'=>'Descuento importe','tipo'=>'numero','ancho'=>18], ['campo'=>'tasa_codigo','titulo'=>'Impuesto','tipo'=>'texto','ancho'=>14], ['campo'=>'tasa_pct','titulo'=>'Tasa %','tipo'=>'numero','ancho'=>10],
        ['campo'=>'importe_bruto','titulo'=>'Importe bruto','tipo'=>'numero','ancho'=>16], ['campo'=>'base_sin_impuesto','titulo'=>'Base sin impuesto','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto','titulo'=>'Impuesto','tipo'=>'numero','ancho'=>14], ['campo'=>'total','titulo'=>'Total','tipo'=>'numero','ancho'=>14],
        ['campo'=>'moneda','titulo'=>'Moneda','tipo'=>'texto','ancho'=>10], ['campo'=>'tipo_cambio_a_base','titulo'=>'Tipo cambio a base','tipo'=>'numero','ancho'=>18], ['campo'=>'base_sin_impuesto_base','titulo'=>'Base moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto_base','titulo'=>'Impuesto moneda base','tipo'=>'numero','ancho'=>20], ['campo'=>'total_base','titulo'=>'Total moneda base','tipo'=>'numero','ancho'=>18],
    ];
}

function rep_contable_columnas_compras(bool $detalle): array
{
    if (!$detalle) {
        return [
            ['campo'=>'fecha','titulo'=>'Fecha compra','tipo'=>'texto','ancho'=>20], ['campo'=>'fecha_factura','titulo'=>'Fecha documento','tipo'=>'texto','ancho'=>15], ['campo'=>'folio','titulo'=>'Compra','tipo'=>'texto','ancho'=>18], ['campo'=>'documento_proveedor','titulo'=>'Factura / documento proveedor','tipo'=>'texto','ancho'=>26],
            ['campo'=>'proveedor','titulo'=>'Proveedor','tipo'=>'texto','ancho'=>34], ['campo'=>'rfc','titulo'=>'RFC','tipo'=>'texto','ancho'=>18], ['campo'=>'condicion_pago','titulo'=>'Condición pago','tipo'=>'texto','ancho'=>16], ['campo'=>'estado','titulo'=>'Estado','tipo'=>'texto','ancho'=>20],
            ['campo'=>'moneda','titulo'=>'Moneda','tipo'=>'texto','ancho'=>10], ['campo'=>'tipo_cambio_a_base','titulo'=>'Tipo cambio a base','tipo'=>'numero','ancho'=>18], ['campo'=>'importe_bruto','titulo'=>'Importe bruto','tipo'=>'numero','ancho'=>16],
            ['campo'=>'descuento','titulo'=>'Descuento','tipo'=>'numero','ancho'=>14], ['campo'=>'base_sin_impuesto','titulo'=>'Base sin impuesto','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto','titulo'=>'Impuesto','tipo'=>'numero','ancho'=>14], ['campo'=>'total','titulo'=>'Total','tipo'=>'numero','ancho'=>14],
            ['campo'=>'base_sin_impuesto_base','titulo'=>'Base moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto_base','titulo'=>'Impuesto moneda base','tipo'=>'numero','ancho'=>20], ['campo'=>'total_base','titulo'=>'Total moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'usuario','titulo'=>'Usuario','tipo'=>'texto','ancho'=>16],
        ];
    }
    return [
        ['campo'=>'fecha','titulo'=>'Fecha compra','tipo'=>'texto','ancho'=>20], ['campo'=>'fecha_factura','titulo'=>'Fecha documento','tipo'=>'texto','ancho'=>15], ['campo'=>'folio','titulo'=>'Compra','tipo'=>'texto','ancho'=>18], ['campo'=>'documento_proveedor','titulo'=>'Factura / documento proveedor','tipo'=>'texto','ancho'=>26], ['campo'=>'proveedor','titulo'=>'Proveedor','tipo'=>'texto','ancho'=>32], ['campo'=>'rfc','titulo'=>'RFC','tipo'=>'texto','ancho'=>18],
        ['campo'=>'renglon','titulo'=>'Renglón','tipo'=>'numero','ancho'=>10], ['campo'=>'sku','titulo'=>'SKU','tipo'=>'texto','ancho'=>16], ['campo'=>'producto','titulo'=>'Producto','tipo'=>'texto','ancho'=>30], ['campo'=>'presentacion','titulo'=>'Presentación','tipo'=>'texto','ancho'=>18], ['campo'=>'cantidad','titulo'=>'Cantidad','tipo'=>'numero','ancho'=>14], ['campo'=>'precio_unitario','titulo'=>'Precio unitario','tipo'=>'numero','ancho'=>16],
        ['campo'=>'descuento_pct','titulo'=>'Descuento %','tipo'=>'numero','ancho'=>14], ['campo'=>'descuento_importe','titulo'=>'Descuento importe','tipo'=>'numero','ancho'=>18], ['campo'=>'tasa_codigo','titulo'=>'Impuesto','tipo'=>'texto','ancho'=>14], ['campo'=>'tasa_pct','titulo'=>'Tasa %','tipo'=>'numero','ancho'=>10], ['campo'=>'importe_bruto','titulo'=>'Importe bruto','tipo'=>'numero','ancho'=>16], ['campo'=>'base_sin_impuesto','titulo'=>'Base sin impuesto','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto','titulo'=>'Impuesto','tipo'=>'numero','ancho'=>14], ['campo'=>'total','titulo'=>'Total','tipo'=>'numero','ancho'=>14],
        ['campo'=>'moneda','titulo'=>'Moneda','tipo'=>'texto','ancho'=>10], ['campo'=>'tipo_cambio_a_base','titulo'=>'Tipo cambio a base','tipo'=>'numero','ancho'=>18], ['campo'=>'base_sin_impuesto_base','titulo'=>'Base moneda base','tipo'=>'numero','ancho'=>18], ['campo'=>'impuesto_base','titulo'=>'Impuesto moneda base','tipo'=>'numero','ancho'=>20], ['campo'=>'total_base','titulo'=>'Total moneda base','tipo'=>'numero','ancho'=>18],
    ];
}

function rep_contable_columnas_devolucion(string $tipo): array
{
    $tercero = $tipo === 'VENTA' ? 'Cliente' : 'Proveedor';
    return [
        ['campo'=>'fecha','titulo'=>'Fecha','tipo'=>'texto','ancho'=>20], ['campo'=>'devolucion','titulo'=>'Devolución','tipo'=>'texto','ancho'=>20], ['campo'=>'operacion_origen','titulo'=>'Operación origen','tipo'=>'texto','ancho'=>20],
        ['campo'=>'tercero','titulo'=>$tercero,'tipo'=>'texto','ancho'=>32], ['campo'=>'rfc','titulo'=>'RFC','tipo'=>'texto','ancho'=>18], ['campo'=>'sku','titulo'=>'SKU','tipo'=>'texto','ancho'=>16], ['campo'=>'producto','titulo'=>'Producto','tipo'=>'texto','ancho'=>30],
        ['campo'=>'cantidad_base','titulo'=>'Cantidad devuelta base','tipo'=>'numero','ancho'=>20], ['campo'=>'unidad_base','titulo'=>'Unidad base','tipo'=>'texto','ancho'=>14], ['campo'=>'proporcion','titulo'=>'Proporción original','tipo'=>'numero','ancho'=>18], ['campo'=>'tasa_pct','titulo'=>'Tasa % original','tipo'=>'numero','ancho'=>14],
        ['campo'=>'base_sin_impuesto','titulo'=>'Base estimada devolución','tipo'=>'numero','ancho'=>22], ['campo'=>'impuesto','titulo'=>'Impuesto estimado devolución','tipo'=>'numero','ancho'=>24], ['campo'=>'total','titulo'=>'Total devolución','tipo'=>'numero','ancho'=>18], ['campo'=>'moneda','titulo'=>'Moneda','tipo'=>'texto','ancho'=>10], ['campo'=>'tipo_cambio_a_base','titulo'=>'Tipo cambio a base','tipo'=>'numero','ancho'=>18],
        ['campo'=>'base_sin_impuesto_base','titulo'=>'Base devolución moneda base','tipo'=>'numero','ancho'=>24], ['campo'=>'impuesto_base','titulo'=>'Impuesto devolución moneda base','tipo'=>'numero','ancho'=>26], ['campo'=>'total_base','titulo'=>'Total devolución moneda base','tipo'=>'numero','ancho'=>22], ['campo'=>'motivo','titulo'=>'Motivo','tipo'=>'texto','ancho'=>34],
    ];
}

function rep_registrar_exportacion_contable(PDO $conexion, string $desde, string $hasta, string $archivo, array $filtros): void
{
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    if ($usuarioId <= 0) {
        return;
    }

    try {
        $json = json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $conexion->prepare(
            "INSERT INTO exportaciones_contables
             (tipo, formato, fecha_desde, fecha_hasta, filtros_json, nombre_archivo, ruta_relativa, generado_by)
             VALUES ('COMPRAS_Y_VENTAS', 'XLSX', :desde, :hasta, :filtros, :archivo, NULL, :usuario_id)"
        );
        $stmt->execute([
            ':desde' => $desde,
            ':hasta' => $hasta,
            ':filtros' => $json !== false ? $json : null,
            ':archivo' => $archivo,
            ':usuario_id' => $usuarioId,
        ]);
        $exportacionId = (int) $conexion->lastInsertId();

        $aud = $conexion->prepare(
            "INSERT INTO auditoria
             (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion, datos_anteriores, datos_nuevos, ip, user_agent)
             VALUES (:usuario_id, 'EXPORTAR_CONTABILIDAD', 'reportes', 'exportaciones_contables', :entidad_id,
                     'Se generó una entrega contable de ventas y compras.', NULL, :datos, :ip, :ua)"
        );
        $aud->execute([
            ':usuario_id' => $usuarioId,
            ':entidad_id' => $exportacionId > 0 ? $exportacionId : null,
            ':datos' => $json !== false ? $json : null,
            ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        // La descarga no debe fallar solo porque no pudo registrarse la bitácora de exportación.
        error_log('[REPORTES][EXPORTACION_CONTABLE][BITACORA] ' . $e->getMessage());
    }
}

function rep_total_filas(PDO $conexion, string $sqlBase, array $params): int
{
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM ({$sqlBase}) rep_count");
    rep_bind($stmt, $params);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function rep_validar_limite_exportacion(int $total, int $limite = 50000): void
{
    if ($total <= $limite) {
        return;
    }
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'La exportación contiene ' . number_format($total, 0, '.', ',')
        . ' registros. Aplica un periodo o filtros más específicos; el máximo por exportación es '
        . number_format($limite, 0, '.', ',') . '.';
    exit;
}

function rep_xlsx_columnas(array $columnas): array
{
    $salida = [];
    foreach ($columnas as $columna) {
        $tipo = (string) ($columna['tipo'] ?? 'texto');
        $salida[] = [
            'campo' => (string) $columna['campo'],
            'titulo' => (string) $columna['titulo'],
            'tipo' => in_array($tipo, ['moneda', 'moneda_base', 'cantidad', 'entero'], true) ? 'numero' : 'texto',
            'ancho' => rep_xlsx_ancho_columna((string) $columna['titulo'], $tipo),
        ];
    }
    return $salida;
}

function rep_xlsx_ancho_columna(string $titulo, string $tipo): int
{
    if (in_array($tipo, ['moneda', 'moneda_base', 'cantidad', 'entero'], true)) {
        return 16;
    }
    if (in_array($tipo, ['fecha', 'fecha_hora'], true)) {
        return 20;
    }
    return max(12, min(34, mb_strlen($titulo) + 10));
}

function rep_xlsx_resumen(array $def, array $f, int $total, string $monedaBase): array
{
    $filas = [
        ['concepto' => 'Reporte', 'valor' => (string) $def['nombre']],
        ['concepto' => 'Generado', 'valor' => date('Y-m-d H:i:s')],
        ['concepto' => 'Registros', 'valor' => (string) $total],
        ['concepto' => 'Moneda base del sistema', 'valor' => $monedaBase],
    ];

    $mapa = [
        'buscar' => 'Búsqueda', 'fecha_desde' => 'Fecha desde', 'fecha_hasta' => 'Fecha hasta',
        'producto_id' => 'Producto ID', 'proveedor_id' => 'Proveedor ID', 'cliente_id' => 'Cliente ID',
        'usuario_id' => 'Usuario ID', 'almacen_id' => 'Almacén ID', 'estado' => 'Estado / acción',
    ];
    foreach ($mapa as $clave => $titulo) {
        $valor = $f[$clave] ?? '';
        if ($valor === '' || $valor === 0 || $valor === '0') {
            continue;
        }
        $filas[] = ['concepto' => $titulo, 'valor' => (string) $valor];
    }
    if (count($filas) === 4) {
        $filas[] = ['concepto' => 'Filtros', 'valor' => 'Sin filtros adicionales'];
    }
    return $filas;
}

function rep_xlsx_detalles_especiales(PDO $conexion, string $codigo, array $f): array
{
    [$sql, $params, $columnas, $nombre, $orden] = rep_xlsx_detalle_sql($codigo, $f);
    if ($sql === '') {
        return [];
    }

    $total = rep_total_filas($conexion, $sql, $params);
    rep_validar_limite_exportacion($total);
    $stmt = $conexion->prepare($sql . ' ' . $orden);
    rep_bind($stmt, $params);
    $stmt->execute();

    return [[
        'nombre' => $nombre,
        'columnas' => rep_xlsx_columnas($columnas),
        'filas' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]];
}

function rep_xlsx_detalle_sql(string $codigo, array $f): array
{
    $where = ['1=1'];
    $params = [];

    if ($codigo === 'COMPRAS') {
        rep_fecha($where, $params, 'c.fecha_compra', $f);
        rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
        rep_id($where, $params, 'c.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'c.estado', $f['estado']);
        rep_id($where, $params, 'cd.producto_id', 'producto_id', $f['producto_id']);
        rep_buscar($where, $params, $f['buscar'], ['c.folio', 'c.proveedor_nombre_snapshot', 'c.proveedor_rfc_snapshot', 'c.numero_factura', 'cd.sku_snapshot', 'cd.producto_nombre_snapshot']);
        $sql = "SELECT c.fecha_compra AS fecha, c.folio,
                       COALESCE(NULLIF(c.numero_factura,''),'') AS factura,
                       COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                       COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                       c.estado, cd.renglon, cd.sku_snapshot AS sku, cd.producto_nombre_snapshot AS producto,
                       COALESCE(pres.nombre, cd.unidad_nombre_snapshot) AS presentacion, cd.cantidad,
                       cd.precio_unitario, cd.descuento_pct, cd.descuento_importe,
                       cd.impuesto_pct_snapshot AS tasa_impuesto_pct, cd.subtotal,
                       cd.impuesto_importe AS impuesto, cd.total,
                       mon.codigo AS moneda_codigo, c.tipo_cambio_a_base
                FROM compras c
                INNER JOIN compras_detalle cd ON cd.compra_id = c.id
                LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                LEFT JOIN presentaciones_producto pres ON pres.id = cd.presentacion_id
                INNER JOIN monedas mon ON mon.id = c.moneda_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Compra'), rep_col('factura','Factura / documento'), rep_col('proveedor','Proveedor'), rep_col('rfc','RFC'), rep_col('estado','Estado'),
            rep_col('renglon','Renglón','entero'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('presentacion','Presentación'), rep_col('cantidad','Cantidad','cantidad'),
            rep_col('precio_unitario','Precio unitario','moneda'), rep_col('descuento_pct','Descuento %','cantidad'), rep_col('descuento_importe','Descuento','moneda'), rep_col('tasa_impuesto_pct','Impuesto %','cantidad'),
            rep_col('subtotal','Subtotal','moneda'), rep_col('impuesto','Impuesto','moneda'), rep_col('total','Total','moneda'), rep_col('moneda_codigo','Moneda'), rep_col('tipo_cambio_a_base','Tipo cambio a base','cantidad'),
        ];
        return [$sql, $params, $cols, 'Detalle compras', 'ORDER BY c.fecha_compra ASC, c.id ASC, cd.renglon ASC'];
    }

    if ($codigo === 'VENTAS') {
        rep_fecha($where, $params, 'v.fecha_venta', $f);
        rep_id($where, $params, 'v.cliente_id', 'cliente_id', $f['cliente_id']);
        rep_id($where, $params, 'v.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'v.estado', $f['estado']);
        rep_id($where, $params, 'vd.producto_id', 'producto_id', $f['producto_id']);
        rep_buscar($where, $params, $f['buscar'], ['v.folio', 'v.cliente_nombre_snapshot', 'v.cliente_rfc_snapshot', 'vd.sku_snapshot', 'vd.producto_nombre_snapshot']);
        $sql = "SELECT v.fecha_venta AS fecha, v.folio,
                       COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                       COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                       v.estado, vd.renglon, a.nombre AS almacen, vd.sku_snapshot AS sku, vd.producto_nombre_snapshot AS producto,
                       COALESCE(pres.nombre, vd.unidad_nombre_snapshot) AS presentacion, vd.cantidad,
                       vd.precio_unitario, vd.descuento_pct, vd.descuento_importe,
                       vd.impuesto_pct_snapshot AS tasa_impuesto_pct, vd.subtotal,
                       vd.impuesto_importe AS impuesto, vd.total,
                       mon.codigo AS moneda_codigo, v.tipo_cambio_a_base
                FROM ventas v
                INNER JOIN ventas_detalle vd ON vd.venta_id = v.id
                LEFT JOIN clientes cl ON cl.id = v.cliente_id
                INNER JOIN almacenes a ON a.id = vd.almacen_id
                LEFT JOIN presentaciones_producto pres ON pres.id = vd.presentacion_id
                INNER JOIN monedas mon ON mon.id = v.moneda_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Venta'), rep_col('cliente','Cliente'), rep_col('rfc','RFC'), rep_col('estado','Estado'),
            rep_col('renglon','Renglón','entero'), rep_col('almacen','Almacén'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('presentacion','Presentación'), rep_col('cantidad','Cantidad','cantidad'),
            rep_col('precio_unitario','Precio unitario','moneda'), rep_col('descuento_pct','Descuento %','cantidad'), rep_col('descuento_importe','Descuento','moneda'), rep_col('tasa_impuesto_pct','Impuesto %','cantidad'),
            rep_col('subtotal','Subtotal','moneda'), rep_col('impuesto','Impuesto','moneda'), rep_col('total','Total','moneda'), rep_col('moneda_codigo','Moneda'), rep_col('tipo_cambio_a_base','Tipo cambio a base','cantidad'),
        ];
        return [$sql, $params, $cols, 'Detalle ventas', 'ORDER BY v.fecha_venta ASC, v.id ASC, vd.renglon ASC'];
    }

    if ($codigo === 'RECEPCIONES') {
        rep_fecha($where, $params, 'rc.fecha_recepcion', $f);
        rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
        rep_id($where, $params, 'rc.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'rc.estado', $f['estado']);
        rep_id($where, $params, 'rcd.producto_id', 'producto_id', $f['producto_id']);
        rep_id($where, $params, 'rcd.almacen_id', 'almacen_id', $f['almacen_id']);
        rep_buscar($where, $params, $f['buscar'], ['rc.folio', 'c.folio', 'c.proveedor_nombre_snapshot', 'pr.razon_social', 'rc.documento_recepcion']);
        $sql = "SELECT rc.fecha_recepcion AS fecha, rc.folio, c.folio AS compra,
                       COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                       rc.estado, a.nombre AS almacen, p.sku, p.nombre AS producto,
                       rcd.cantidad_recibida, COALESCE(cd.unidad_nombre_snapshot,'') AS unidad_compra,
                       rcd.cantidad_base, um.codigo AS unidad_base, COALESCE(rcd.observaciones,'') AS observaciones
                FROM recepciones_compra rc
                INNER JOIN compras c ON c.id = rc.compra_id
                LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                INNER JOIN recepciones_compra_detalle rcd ON rcd.recepcion_id = rc.id
                INNER JOIN compras_detalle cd ON cd.id = rcd.compra_detalle_id
                INNER JOIN almacenes a ON a.id = rcd.almacen_id
                INNER JOIN productos p ON p.id = rcd.producto_id
                INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Recepción'), rep_col('compra','Compra'), rep_col('proveedor','Proveedor'), rep_col('estado','Estado'),
            rep_col('almacen','Almacén'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('cantidad_recibida','Cantidad recibida','cantidad'),
            rep_col('unidad_compra','Unidad compra'), rep_col('cantidad_base','Cantidad base','cantidad'), rep_col('unidad_base','Unidad base'), rep_col('observaciones','Observaciones'),
        ];
        return [$sql, $params, $cols, 'Detalle recepciones', 'ORDER BY rc.fecha_recepcion ASC, rc.id ASC, rcd.id ASC'];
    }

    if ($codigo === 'AJUSTES') {
        rep_fecha($where, $params, 'ai.fecha_ajuste', $f);
        rep_id($where, $params, 'ai.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'ai.estado', $f['estado']);
        $where[] = "ai.tipo IN ('AJUSTE_POSITIVO','AJUSTE_NEGATIVO')";
        rep_id($where, $params, 'aid.producto_id', 'producto_id', $f['producto_id']);
        rep_id($where, $params, 'aid.almacen_id', 'almacen_id', $f['almacen_id']);
        rep_buscar($where, $params, $f['buscar'], ['ai.folio', 'ai.motivo', 'ai.observaciones']);
        $sql = "SELECT ai.fecha_ajuste AS fecha, ai.folio, ai.tipo, ai.estado, ai.motivo,
                       a.nombre AS almacen, p.sku, p.nombre AS producto, aid.cantidad_base AS cantidad,
                       um.codigo AS unidad, COALESCE(aid.observaciones,'') AS observaciones
                FROM ajustes_inventario ai
                INNER JOIN ajustes_inventario_detalle aid ON aid.ajuste_id = ai.id
                INNER JOIN almacenes a ON a.id = aid.almacen_id
                INNER JOIN productos p ON p.id = aid.producto_id
                INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Folio'), rep_col('tipo','Tipo'), rep_col('estado','Estado'), rep_col('motivo','Motivo'),
            rep_col('almacen','Almacén'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('cantidad','Cantidad','cantidad'), rep_col('unidad','Unidad'), rep_col('observaciones','Observaciones'),
        ];
        return [$sql, $params, $cols, 'Detalle ajustes', 'ORDER BY ai.fecha_ajuste ASC, ai.id ASC, aid.renglon ASC'];
    }

    if ($codigo === 'DEVOLUCIONES_VENTA') {
        rep_fecha($where, $params, 'dv.fecha_devolucion', $f);
        rep_id($where, $params, 'dv.cliente_id', 'cliente_id', $f['cliente_id']);
        rep_id($where, $params, 'dv.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'dv.estado', $f['estado']);
        rep_id($where, $params, 'dvd.producto_id', 'producto_id', $f['producto_id']);
        rep_id($where, $params, 'dvd.almacen_id', 'almacen_id', $f['almacen_id']);
        rep_buscar($where, $params, $f['buscar'], ['dv.folio', 'v.folio', 'v.cliente_nombre_snapshot', 'cl.nombre_razon_social', 'dv.motivo']);
        $sql = "SELECT dv.fecha_devolucion AS fecha, dv.folio, v.folio AS venta,
                       COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                       dv.estado, a.nombre AS almacen, p.sku, p.nombre AS producto,
                       dvd.cantidad_base AS cantidad, um.codigo AS unidad, dvd.importe, mon.codigo AS moneda_codigo,
                       dv.motivo
                FROM devoluciones_venta dv
                INNER JOIN ventas v ON v.id = dv.venta_id
                LEFT JOIN clientes cl ON cl.id = dv.cliente_id
                INNER JOIN devoluciones_venta_detalle dvd ON dvd.devolucion_id = dv.id
                INNER JOIN almacenes a ON a.id = dvd.almacen_id
                INNER JOIN productos p ON p.id = dvd.producto_id
                INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                INNER JOIN monedas mon ON mon.id = v.moneda_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Devolución'), rep_col('venta','Venta'), rep_col('cliente','Cliente'), rep_col('estado','Estado'),
            rep_col('almacen','Almacén'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('cantidad','Cantidad base','cantidad'), rep_col('unidad','Unidad base'),
            rep_col('importe','Importe','moneda'), rep_col('moneda_codigo','Moneda'), rep_col('motivo','Motivo'),
        ];
        return [$sql, $params, $cols, 'Detalle devolución', 'ORDER BY dv.fecha_devolucion ASC, dv.id ASC, dvd.id ASC'];
    }

    if ($codigo === 'DEVOLUCIONES_COMPRA') {
        rep_fecha($where, $params, 'dc.fecha_devolucion', $f);
        rep_id($where, $params, 'dc.proveedor_id', 'proveedor_id', $f['proveedor_id']);
        rep_id($where, $params, 'dc.created_by', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'dc.estado', $f['estado']);
        rep_id($where, $params, 'dcd.producto_id', 'producto_id', $f['producto_id']);
        rep_id($where, $params, 'dcd.almacen_id', 'almacen_id', $f['almacen_id']);
        rep_buscar($where, $params, $f['buscar'], ['dc.folio', 'c.folio', 'c.proveedor_nombre_snapshot', 'pr.razon_social', 'dc.motivo']);
        $sql = "SELECT dc.fecha_devolucion AS fecha, dc.folio, c.folio AS compra,
                       COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                       dc.estado, a.nombre AS almacen, p.sku, p.nombre AS producto,
                       dcd.cantidad_base AS cantidad, um.codigo AS unidad, dcd.importe, mon.codigo AS moneda_codigo,
                       COALESCE(rc.folio,'') AS recepcion, dc.motivo
                FROM devoluciones_compra dc
                INNER JOIN compras c ON c.id = dc.compra_id
                LEFT JOIN proveedores pr ON pr.id = dc.proveedor_id
                INNER JOIN devoluciones_compra_detalle dcd ON dcd.devolucion_id = dc.id
                LEFT JOIN recepciones_compra_detalle rcd ON rcd.id = dcd.recepcion_detalle_id
                LEFT JOIN recepciones_compra rc ON rc.id = rcd.recepcion_id
                INNER JOIN almacenes a ON a.id = dcd.almacen_id
                INNER JOIN productos p ON p.id = dcd.producto_id
                INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                INNER JOIN monedas mon ON mon.id = c.moneda_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Devolución'), rep_col('compra','Compra'), rep_col('recepcion','Recepción origen'), rep_col('proveedor','Proveedor'), rep_col('estado','Estado'),
            rep_col('almacen','Almacén'), rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('cantidad','Cantidad base','cantidad'), rep_col('unidad','Unidad base'),
            rep_col('importe','Importe','moneda'), rep_col('moneda_codigo','Moneda'), rep_col('motivo','Motivo'),
        ];
        return [$sql, $params, $cols, 'Detalle devolución', 'ORDER BY dc.fecha_devolucion ASC, dc.id ASC, dcd.id ASC'];
    }

    if ($codigo === 'TRANSFERENCIAS') {
        rep_fecha($where, $params, 'mi.fecha_movimiento', $f);
        rep_id($where, $params, 'COALESCE(mi.aplicado_by, mi.created_by)', 'usuario_id', $f['usuario_id']);
        rep_estado($where, $params, 'mi.estado', $f['estado']);
        $where[] = "tmi.codigo = 'TRANSFERENCIA'";
        if ($f['producto_id'] > 0) {
            $where[] = 'mo.producto_id = :producto_id';
            $params['producto_id'] = $f['producto_id'];
        }
        if ($f['almacen_id'] > 0) {
            $where[] = '(mo.almacen_id = :almacen_id OR md.almacen_id = :almacen_id)';
            $params['almacen_id'] = $f['almacen_id'];
        }
        rep_buscar($where, $params, $f['buscar'], ['mi.folio', 'mi.motivo', 'mi.observaciones', 'ao.nombre', 'ad.nombre', 'p.sku', 'p.nombre']);
        $sql = "SELECT mi.fecha_movimiento AS fecha, mi.folio, mi.estado,
                       ao.nombre AS origen, ad.nombre AS destino, p.sku, p.nombre AS producto,
                       ABS(mo.cantidad_delta) AS cantidad, um.codigo AS unidad,
                       mo.existencia_antes AS origen_antes, mo.existencia_despues AS origen_despues,
                       md.existencia_antes AS destino_antes, md.existencia_despues AS destino_despues,
                       mo.costo_unitario_base AS costo_unitario_base,
                       COALESCE((SELECT r.folio FROM movimientos_inventario r WHERE r.movimiento_revertido_id = mi.id ORDER BY r.id DESC LIMIT 1), '') AS reverso,
                       COALESCE(mi.motivo,'') AS motivo
                FROM movimientos_inventario mi
                INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
                INNER JOIN movimientos_inventario_detalle mo ON mo.movimiento_id = mi.id AND mo.cantidad_delta < 0
                INNER JOIN movimientos_inventario_detalle md ON md.movimiento_id = mi.id AND md.producto_id = mo.producto_id AND md.cantidad_delta > 0
                INNER JOIN almacenes ao ON ao.id = mo.almacen_id
                INNER JOIN almacenes ad ON ad.id = md.almacen_id
                INNER JOIN productos p ON p.id = mo.producto_id
                INNER JOIN unidades_medida um ON um.id = p.unidad_base_id
                WHERE " . implode(' AND ', $where);
        $cols = [
            rep_col('fecha','Fecha','fecha_hora'), rep_col('folio','Transferencia'), rep_col('estado','Estado'), rep_col('origen','Origen'), rep_col('destino','Destino'),
            rep_col('sku','SKU'), rep_col('producto','Producto'), rep_col('cantidad','Cantidad','cantidad'), rep_col('unidad','Unidad'),
            rep_col('origen_antes','Origen antes','cantidad'), rep_col('origen_despues','Origen después','cantidad'), rep_col('destino_antes','Destino antes','cantidad'), rep_col('destino_despues','Destino después','cantidad'),
            rep_col('costo_unitario_base','Costo unitario base','moneda_base'), rep_col('reverso','Reverso'), rep_col('motivo','Motivo'),
        ];
        return [$sql, $params, $cols, 'Detalle transferencias', 'ORDER BY mi.fecha_movimiento ASC, mi.id ASC, p.nombre ASC'];
    }

    return ['', [], [], '', ''];
}


function rep_sql(string $codigo, array $f): array
{
    $where = ['1=1'];
    $params = [];
    $monedaBaseSql = "COALESCE((SELECT mbase.codigo FROM monedas mbase WHERE mbase.es_base = 1 ORDER BY mbase.activo DESC, mbase.id ASC LIMIT 1), 'MXN')";

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
                           COALESCE(c.numero_factura, '') AS factura, c.condicion_pago, c.estado, mon.codigo AS moneda_codigo,
                           c.subtotal, c.descuento_total AS descuento, c.impuesto_total AS impuesto, c.total,
                           COALESCE(u.usuario, '—') AS usuario
                    FROM compras c
                    LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                    INNER JOIN monedas mon ON mon.id = c.moneda_id
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
                           v.condicion_pago, v.estado, mon.codigo AS moneda_codigo,
                           v.subtotal, v.descuento_total AS descuento, v.impuesto_total AS impuesto, v.total,
                           COALESCE(u.usuario, '—') AS usuario
                    FROM ventas v
                    LEFT JOIN clientes cl ON cl.id = v.cliente_id
                    INNER JOIN monedas mon ON mon.id = v.moneda_id
                    LEFT JOIN usuarios u ON u.id = v.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY v.fecha_venta DESC, v.id DESC'];

        case 'COMPRAS_PROVEEDOR':
            rep_fecha($where, $params, 'c.fecha_compra', $f);
            rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            if ($f['estado'] !== '') {
                rep_estado($where, $params, 'c.estado', $f['estado']);
            } else {
                $where[] = "c.estado IN ('PENDIENTE_RECEPCION','RECIBIDA_PARCIAL','RECIBIDA')";
            }
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM compras_detalle cd_f WHERE cd_f.compra_id = c.id AND cd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['c.proveedor_nombre_snapshot', 'c.proveedor_rfc_snapshot', 'pr.razon_social', 'pr.rfc']);
            $sql = "SELECT COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                           COALESCE(NULLIF(c.proveedor_rfc_snapshot,''), pr.rfc, '') AS rfc,
                           COUNT(*) AS operaciones, {$monedaBaseSql} AS moneda_codigo,
                           COALESCE(SUM(c.subtotal * c.tipo_cambio_a_base),0) AS subtotal,
                           COALESCE(SUM(c.descuento_total * c.tipo_cambio_a_base),0) AS descuento,
                           COALESCE(SUM(c.impuesto_total * c.tipo_cambio_a_base),0) AS impuesto,
                           COALESCE(SUM(c.total * c.tipo_cambio_a_base),0) AS total
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
            if ($f['estado'] !== '') {
                rep_estado($where, $params, 'v.estado', $f['estado']);
            } else {
                $where[] = "v.estado = 'CONFIRMADA'";
            }
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM ventas_detalle vd_f WHERE vd_f.venta_id = v.id AND vd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['v.cliente_nombre_snapshot', 'v.cliente_rfc_snapshot', 'cl.nombre_razon_social', 'cl.rfc']);
            $sql = "SELECT COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                           COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '') AS rfc,
                           COUNT(*) AS operaciones, {$monedaBaseSql} AS moneda_codigo,
                           COALESCE(SUM(v.subtotal * v.tipo_cambio_a_base),0) AS subtotal,
                           COALESCE(SUM(v.descuento_total * v.tipo_cambio_a_base),0) AS descuento,
                           COALESCE(SUM(v.impuesto_total * v.tipo_cambio_a_base),0) AS impuesto,
                           COALESCE(SUM(v.total * v.tipo_cambio_a_base),0) AS total
                    FROM ventas v
                    LEFT JOIN clientes cl ON cl.id = v.cliente_id
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY v.cliente_id,
                             COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general'),
                             COALESCE(NULLIF(v.cliente_rfc_snapshot,''), cl.rfc, '')";
            return [$sql, $params, 'ORDER BY total DESC, cliente ASC'];

        case 'RECEPCIONES':
            rep_fecha($where, $params, 'rc.fecha_recepcion', $f);
            rep_id($where, $params, 'c.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'rc.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'rc.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM recepciones_compra_detalle rcd_f WHERE rcd_f.recepcion_id = rc.id AND rcd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM recepciones_compra_detalle rca_f WHERE rca_f.recepcion_id = rc.id AND rca_f.almacen_id = :almacen_id)';
                $params['almacen_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['rc.folio', 'c.folio', 'c.proveedor_nombre_snapshot', 'pr.razon_social', 'rc.documento_recepcion']);
            $sql = "SELECT rc.fecha_recepcion AS fecha, rc.folio, c.folio AS compra,
                           COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                           rc.estado, COALESCE(rc.documento_recepcion,'') AS documento,
                           COUNT(DISTINCT rcd.producto_id) AS productos, COUNT(DISTINCT rcd.almacen_id) AS almacenes,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM recepciones_compra rc
                    INNER JOIN compras c ON c.id = rc.compra_id
                    LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
                    LEFT JOIN recepciones_compra_detalle rcd ON rcd.recepcion_id = rc.id
                    LEFT JOIN usuarios u ON u.id = rc.created_by
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY rc.id, rc.fecha_recepcion, rc.folio, c.folio, c.proveedor_nombre_snapshot, pr.razon_social,
                             rc.estado, rc.documento_recepcion, u.usuario";
            return [$sql, $params, 'ORDER BY fecha DESC, rc.id DESC'];

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
                           ea.stock_minimo, ea.punto_reorden, um.codigo AS unidad, {$monedaBaseSql} AS moneda_codigo,
                           ea.costo_promedio_base AS costo_promedio,
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

        case 'AJUSTES':
            rep_fecha($where, $params, 'ai.fecha_ajuste', $f);
            rep_id($where, $params, 'ai.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'ai.estado', $f['estado']);
            $where[] = "ai.tipo IN ('AJUSTE_POSITIVO','AJUSTE_NEGATIVO')";
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM ajustes_inventario_detalle aid_f WHERE aid_f.ajuste_id = ai.id AND aid_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM ajustes_inventario_detalle aia_f WHERE aia_f.ajuste_id = ai.id AND aia_f.almacen_id = :almacen_id)';
                $params['almacen_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['ai.folio', 'ai.motivo', 'ai.observaciones']);
            $sql = "SELECT ai.fecha_ajuste AS fecha, ai.folio, ai.tipo, ai.estado,
                           COUNT(DISTINCT aid.producto_id) AS productos, COUNT(DISTINCT aid.almacen_id) AS almacenes,
                           ai.motivo, COALESCE(u.usuario,'—') AS usuario
                    FROM ajustes_inventario ai
                    LEFT JOIN ajustes_inventario_detalle aid ON aid.ajuste_id = ai.id
                    LEFT JOIN usuarios u ON u.id = ai.created_by
                    WHERE " . implode(' AND ', $where) . "
                    GROUP BY ai.id, ai.fecha_ajuste, ai.folio, ai.tipo, ai.estado, ai.motivo, u.usuario";
            return [$sql, $params, 'ORDER BY fecha DESC, ai.id DESC'];

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

        case 'DEVOLUCIONES_VENTA':
            rep_fecha($where, $params, 'dv.fecha_devolucion', $f);
            rep_id($where, $params, 'dv.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'dv.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'dv.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM devoluciones_venta_detalle dvd_f WHERE dvd_f.devolucion_id = dv.id AND dvd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM devoluciones_venta_detalle dva_f WHERE dva_f.devolucion_id = dv.id AND dva_f.almacen_id = :almacen_id)';
                $params['almacen_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['dv.folio', 'v.folio', 'v.cliente_nombre_snapshot', 'cl.nombre_razon_social', 'dv.motivo']);
            $sql = "SELECT dv.fecha_devolucion AS fecha, dv.folio, v.folio AS venta,
                           COALESCE(NULLIF(v.cliente_nombre_snapshot,''), cl.nombre_razon_social, 'Público general') AS cliente,
                           dv.estado, mon.codigo AS moneda_codigo, dv.total,
                           dv.importe_compensado_cxc AS compensado, dv.importe_reembolso AS reembolso,
                           dv.regularizacion_estado, dv.motivo, COALESCE(u.usuario,'—') AS usuario
                    FROM devoluciones_venta dv
                    INNER JOIN ventas v ON v.id = dv.venta_id
                    LEFT JOIN clientes cl ON cl.id = dv.cliente_id
                    INNER JOIN monedas mon ON mon.id = v.moneda_id
                    LEFT JOIN usuarios u ON u.id = dv.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY dv.fecha_devolucion DESC, dv.id DESC'];

        case 'DEVOLUCIONES_COMPRA':
            rep_fecha($where, $params, 'dc.fecha_devolucion', $f);
            rep_id($where, $params, 'dc.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'dc.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'dc.estado', $f['estado']);
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM devoluciones_compra_detalle dcd_f WHERE dcd_f.devolucion_id = dc.id AND dcd_f.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM devoluciones_compra_detalle dca_f WHERE dca_f.devolucion_id = dc.id AND dca_f.almacen_id = :almacen_id)';
                $params['almacen_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['dc.folio', 'c.folio', 'c.proveedor_nombre_snapshot', 'pr.razon_social', 'dc.motivo']);
            $sql = "SELECT dc.fecha_devolucion AS fecha, dc.folio, c.folio AS compra,
                           COALESCE(NULLIF(c.proveedor_nombre_snapshot,''), pr.razon_social, 'Sin proveedor') AS proveedor,
                           dc.estado, mon.codigo AS moneda_codigo, dc.total,
                           dc.importe_compensado_cxp AS compensado, dc.importe_reintegro AS reintegro,
                           dc.regularizacion_estado, dc.motivo, COALESCE(u.usuario,'—') AS usuario
                    FROM devoluciones_compra dc
                    INNER JOIN compras c ON c.id = dc.compra_id
                    LEFT JOIN proveedores pr ON pr.id = dc.proveedor_id
                    INNER JOIN monedas mon ON mon.id = c.moneda_id
                    LEFT JOIN usuarios u ON u.id = dc.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY dc.fecha_devolucion DESC, dc.id DESC'];

        case 'REGULARIZACIONES_DEVOLUCIONES':
            rep_fecha($where, $params, 'rf.created_at', $f);
            rep_id($where, $params, 'rf.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_id($where, $params, 'rf.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'rf.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'rf.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['rf.folio', 'dv.folio', 'dc.folio', 'cl.nombre_razon_social', 'pr.razon_social', 'rf.referencia']);
            $sql = "SELECT rf.created_at AS fecha, rf.folio, rf.tipo,
                           COALESCE(dv.folio, dc.folio, '—') AS devolucion,
                           COALESCE(cl.nombre_razon_social, pr.razon_social, '—') AS tercero,
                           mon.codigo AS moneda_codigo, rf.importe, rf.estado,
                           COALESCE(mp.nombre,'—') AS metodo, COALESCE(rf.referencia,'') AS referencia,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM regularizaciones_financieras rf
                    LEFT JOIN devoluciones_venta dv ON dv.id = rf.devolucion_venta_id
                    LEFT JOIN devoluciones_compra dc ON dc.id = rf.devolucion_compra_id
                    LEFT JOIN clientes cl ON cl.id = rf.cliente_id
                    LEFT JOIN proveedores pr ON pr.id = rf.proveedor_id
                    INNER JOIN monedas mon ON mon.id = rf.moneda_id
                    LEFT JOIN metodos_pago mp ON mp.id = rf.metodo_pago_id
                    LEFT JOIN usuarios u ON u.id = rf.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY rf.created_at DESC, rf.id DESC'];

        case 'TRANSFERENCIAS':
            rep_fecha($where, $params, 'mi.fecha_movimiento', $f);
            rep_id($where, $params, 'COALESCE(mi.aplicado_by, mi.created_by)', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'mi.estado', $f['estado']);
            $where[] = "tmi.codigo = 'TRANSFERENCIA'";
            if ($f['producto_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM movimientos_inventario_detalle mit_p WHERE mit_p.movimiento_id = mi.id AND mit_p.producto_id = :producto_id)';
                $params['producto_id'] = $f['producto_id'];
            }
            if ($f['almacen_id'] > 0) {
                $where[] = 'EXISTS (SELECT 1 FROM movimientos_inventario_detalle mit_a WHERE mit_a.movimiento_id = mi.id AND mit_a.almacen_id = :almacen_id)';
                $params['almacen_id'] = $f['almacen_id'];
            }
            rep_buscar($where, $params, $f['buscar'], ['mi.folio', 'mi.motivo', 'mi.observaciones', 'ao.nombre', 'ad.nombre']);
            $sql = "SELECT mi.fecha_movimiento AS fecha, mi.folio, mi.estado,
                           COALESCE(ao.nombre,'—') AS origen, COALESCE(ad.nombre,'—') AS destino,
                           x.productos,
                           COALESCE((SELECT r.folio FROM movimientos_inventario r WHERE r.movimiento_revertido_id = mi.id ORDER BY r.id DESC LIMIT 1), '') AS reverso,
                           COALESCE(mi.motivo,'') AS motivo,
                           COALESCE(ua.usuario, uc.usuario, '—') AS usuario
                    FROM movimientos_inventario mi
                    INNER JOIN tipos_movimiento_inventario tmi ON tmi.id = mi.tipo_movimiento_id
                    INNER JOIN (
                        SELECT mid.movimiento_id,
                               MIN(CASE WHEN mid.cantidad_delta < 0 THEN mid.almacen_id END) AS origen_id,
                               MIN(CASE WHEN mid.cantidad_delta > 0 THEN mid.almacen_id END) AS destino_id,
                               COUNT(DISTINCT mid.producto_id) AS productos
                        FROM movimientos_inventario_detalle mid
                        GROUP BY mid.movimiento_id
                    ) x ON x.movimiento_id = mi.id
                    LEFT JOIN almacenes ao ON ao.id = x.origen_id
                    LEFT JOIN almacenes ad ON ad.id = x.destino_id
                    LEFT JOIN usuarios ua ON ua.id = mi.aplicado_by
                    LEFT JOIN usuarios uc ON uc.id = mi.created_by
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY mi.fecha_movimiento DESC, mi.id DESC'];

        case 'CUENTAS_PAGAR':
            $estadoCuenta = rep_estado_cuenta_expr('cxp');
            rep_fecha($where, $params, 'cxp.fecha_documento', $f);
            rep_id($where, $params, 'cxp.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_estado($where, $params, '(' . $estadoCuenta . ')', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['cxp.folio', 'c.folio', 'pr.razon_social']);
            $sql = "SELECT cxp.fecha_documento AS fecha, cxp.folio, c.folio AS compra, pr.razon_social AS proveedor,
                           mon.codigo AS moneda_codigo, cxp.importe_original, cxp.importe_pagado AS pagado, cxp.saldo_pendiente AS saldo,
                           cxp.fecha_vencimiento AS vencimiento, {$estadoCuenta} AS estado
                    FROM cuentas_por_pagar cxp
                    INNER JOIN compras c ON c.id = cxp.compra_id
                    INNER JOIN proveedores pr ON pr.id = cxp.proveedor_id
                    INNER JOIN monedas mon ON mon.id = cxp.moneda_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY cxp.fecha_documento DESC, cxp.id DESC'];

        case 'CUENTAS_COBRAR':
            $estadoCuenta = rep_estado_cuenta_expr('cxc');
            rep_fecha($where, $params, 'cxc.fecha_documento', $f);
            rep_id($where, $params, 'cxc.cliente_id', 'cliente_id', $f['cliente_id']);
            rep_estado($where, $params, '(' . $estadoCuenta . ')', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['cxc.folio', 'v.folio', 'cl.nombre_razon_social']);
            $sql = "SELECT cxc.fecha_documento AS fecha, cxc.folio, v.folio AS venta, cl.nombre_razon_social AS cliente,
                           mon.codigo AS moneda_codigo, cxc.importe_original, cxc.importe_pagado AS pagado, cxc.saldo_pendiente AS saldo,
                           cxc.fecha_vencimiento AS vencimiento, {$estadoCuenta} AS estado
                    FROM cuentas_por_cobrar cxc
                    INNER JOIN ventas v ON v.id = cxc.venta_id
                    INNER JOIN clientes cl ON cl.id = cxc.cliente_id
                    INNER JOIN monedas mon ON mon.id = cxc.moneda_id
                    WHERE " . implode(' AND ', $where);
            return [$sql, $params, 'ORDER BY cxc.fecha_documento DESC, cxc.id DESC'];

        case 'PAGOS':
            rep_fecha($where, $params, 'pp.fecha_pago', $f);
            rep_id($where, $params, 'pp.proveedor_id', 'proveedor_id', $f['proveedor_id']);
            rep_id($where, $params, 'pp.created_by', 'usuario_id', $f['usuario_id']);
            rep_estado($where, $params, 'pp.estado', $f['estado']);
            rep_buscar($where, $params, $f['buscar'], ['pp.folio', 'pr.razon_social', 'pp.referencia']);
            $sql = "SELECT pp.fecha_pago AS fecha, pp.folio, pr.razon_social AS proveedor, mp.nombre AS metodo,
                           pp.importe, mon.codigo AS moneda_codigo, COALESCE(pp.referencia,'') AS referencia, pp.estado,
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
                           pc.importe, mon.codigo AS moneda_codigo, COALESCE(pc.referencia,'') AS referencia, pc.estado,
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
                           ct.vigencia_hasta AS vigencia, ct.estado, mon.codigo AS moneda_codigo,
                           ct.subtotal, ct.descuento_total AS descuento, ct.impuesto_total AS impuesto, ct.total,
                           COALESCE(u.usuario,'—') AS usuario
                    FROM cotizaciones ct
                    LEFT JOIN clientes cl ON cl.id = ct.cliente_id
                    INNER JOIN monedas mon ON mon.id = ct.moneda_id
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
                           ap.reservado_hasta, ap.estado, mon.codigo AS moneda_codigo, ap.total,
                           ap.importe_anticipado AS anticipado, ap.saldo_pendiente AS saldo, COALESCE(u.usuario,'—') AS usuario
                    FROM apartados ap
                    INNER JOIN clientes cl ON cl.id = ap.cliente_id
                    INNER JOIN monedas mon ON mon.id = ap.moneda_id
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
    if (!rep_reporte_autorizado($codigo)) {
        si_responder_json(false, 'No tienes permiso para consultar este reporte.', [], 403);
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

function rep_estado_cuenta_expr(string $alias): string
{
    if (!in_array($alias, ['cxp', 'cxc'], true)) {
        throw new InvalidArgumentException('Alias de cuenta no permitido.');
    }

    return "CASE
                WHEN {$alias}.estado = 'CANCELADA' THEN 'CANCELADA'
                WHEN {$alias}.saldo_pendiente <= 0.00005 THEN 'PAGADA'
                WHEN {$alias}.fecha_vencimiento < CURDATE() THEN 'VENCIDA'
                WHEN {$alias}.importe_pagado > 0.00005 THEN 'PARCIAL'
                ELSE 'PENDIENTE'
            END";
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
    if (in_array($tipo, ['moneda', 'moneda_base', 'cantidad', 'entero'], true)) {
        return $texto;
    }
    if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
        return "'" . $texto;
    }
    return $texto;
}
