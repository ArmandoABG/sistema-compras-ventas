<?php

declare(strict_types=1);

if (isset($_GET['productos_api'])) {
    $endpoint = __DIR__ . '/../funciones/productos_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/productos_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('productos.ver', false);

$tituloPagina = 'Catálogos';
$csrfToken = si_token_csrf();
$puedeAdministrar = si_tiene_permiso('productos.administrar');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_productos.css';

$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'productos')));

if (!in_array($seccionInicial, ['productos', 'categorias', 'unidades', 'presentaciones', 'precios'], true)) {
    $seccionInicial = 'productos';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <title>Productos y catálogos | Sistema Integral</title>

    <link
        rel="stylesheet"
        href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>"
    >

    <link
        rel="stylesheet"
        href="../css/style_productos.css?v=<?= si_escapar($versionModulo) ?>"
    >
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content catalogos-page">

            <header class="catalogos-heading">
                <div>
                    <p class="catalogos-eyebrow">CATÁLOGOS GENERALES</p>
                    <h1>Productos y catálogos</h1>
                    <p>
                        Un solo catálogo para materias primas y productos terminados,
                        preparado para compras, ventas, inventario y producción.
                    </p>
                </div>
            </header>

            <nav class="catalogos-tabs" aria-label="Catálogos">
                <button type="button" class="catalogo-tab" data-seccion="productos">
                    Productos
                </button>

                <button type="button" class="catalogo-tab" data-seccion="categorias">
                    Categorías
                </button>

                <button type="button" class="catalogo-tab" data-seccion="unidades">
                    Unidades
                </button>

                <button type="button" class="catalogo-tab" data-seccion="presentaciones">
                    Presentaciones
                </button>

                <button type="button" class="catalogo-tab" data-seccion="precios">
                    Precios de venta
                </button>
            </nav>

            <div id="mensajePagina" class="catalogos-message" hidden></div>

            <!-- =====================================================
                 PRODUCTOS
                 ===================================================== -->
            <section id="seccionProductos" class="catalogo-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Productos</h2>
                        <p>
                            El código interno se genera automáticamente; tú capturas
                            solamente la información operativa real.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevoProducto">
                            Nuevo producto
                        </button>
                    <?php endif; ?>
                </div>

                <section class="catalogos-kpis">
                    <article>
                        <span>Total</span>
                        <strong id="kpiProductosTotal">0</strong>
                    </article>

                    <article>
                        <span>Materias primas</span>
                        <strong id="kpiMaterias">0</strong>
                    </article>

                    <article>
                        <span>Terminados</span>
                        <strong id="kpiTerminados">0</strong>
                    </article>

                    <article>
                        <span>Activos</span>
                        <strong id="kpiProductosActivos">0</strong>
                    </article>

                    <article>
                        <span>Inactivos</span>
                        <strong id="kpiProductosInactivos">0</strong>
                    </article>

                    <article>
                        <span>Sin precio vigente</span>
                        <strong id="kpiProductosSinPrecio">0</strong>
                    </article>
                </section>

                <section class="catalogos-card">
                    <div class="catalogos-filtros">
                        <label class="field field--search">
                            <span>Buscar</span>
                            <input
                                type="search"
                                id="buscarProducto"
                                maxlength="140"
                                placeholder="Código o nombre del producto"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Tipo</span>
                            <select id="filtroTipoProducto">
                                <option value="TODOS">Todos</option>
                                <option value="MATERIA_PRIMA">Materia prima</option>
                                <option value="PRODUCTO_TERMINADO">Producto terminado</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Categoría</span>
                            <select id="filtroCategoriaProducto">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Estado</span>
                            <select id="filtroEstadoProducto">
                                <option value="TODOS">Todos</option>
                                <option value="ACTIVOS">Activos</option>
                                <option value="INACTIVOS">Inactivos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaProducto">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="catalogos-table catalogos-table--productos">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Producto</th>
                                    <th>Tipo</th>
                                    <th>Categoría</th>
                                    <th>Unidad base</th>
                                    <th>Presentaciones</th>
                                    <th>Precios vigentes</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaProductos">
                                <tr>
                                    <td colspan="9" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="catalogos-pagination">
                        <span id="textoPaginaProductos">0 registros</span>

                        <div>
                            <button type="button" class="btn-secondary" id="btnProductoAnterior">
                                Anterior
                            </button>

                            <span id="paginaProductoActual">Página 1 de 1</span>

                            <button type="button" class="btn-secondary" id="btnProductoSiguiente">
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 CATEGORÍAS
                 ===================================================== -->
            <section id="seccionCategorias" class="catalogo-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Categorías</h2>
                        <p>Organiza el catálogo sin separar innecesariamente materias primas y terminados.</p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevaCategoria">
                            Nueva categoría
                        </button>
                    <?php endif; ?>
                </div>

                <section class="catalogos-card">
                    <div class="simple-search simple-search--two">
                        <label class="field">
                            <span>Buscar</span>
                            <input
                                type="search"
                                id="buscarCategoria"
                                maxlength="120"
                                placeholder="Nombre o descripción"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaCategoria">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="catalogos-table">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Descripción</th>
                                    <th>Productos</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaCategorias">
                                <tr>
                                    <td colspan="5" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="catalogos-pagination">
                        <span id="textoPaginaCategorias">0 registros</span>

                        <div>
                            <button type="button" class="btn-secondary" id="btnCategoriaAnterior">
                                Anterior
                            </button>

                            <span id="paginaCategoriaActual">Página 1 de 1</span>

                            <button type="button" class="btn-secondary" id="btnCategoriaSiguiente">
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 UNIDADES
                 ===================================================== -->
            <section id="seccionUnidades" class="catalogo-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Unidades de medida</h2>
                        <p>
                            Cada producto tiene una unidad base; las presentaciones convierten
                            automáticamente hacia ella.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevaUnidad">
                            Nueva unidad
                        </button>
                    <?php endif; ?>
                </div>

                <section class="catalogos-card">
                    <div class="simple-search simple-search--two">
                        <label class="field">
                            <span>Buscar</span>
                            <input
                                type="search"
                                id="buscarUnidad"
                                maxlength="120"
                                placeholder="Código, nombre, símbolo o tipo"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaUnidad">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="catalogos-table">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Símbolo</th>
                                    <th>Tipo</th>
                                    <th>Productos base</th>
                                    <th>Presentaciones</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaUnidades">
                                <tr>
                                    <td colspan="8" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="catalogos-pagination">
                        <span id="textoPaginaUnidades">0 registros</span>

                        <div>
                            <button type="button" class="btn-secondary" id="btnUnidadAnterior">
                                Anterior
                            </button>

                            <span id="paginaUnidadActual">Página 1 de 1</span>

                            <button type="button" class="btn-secondary" id="btnUnidadSiguiente">
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 PRESENTACIONES
                 ===================================================== -->
            <section id="seccionPresentaciones" class="catalogo-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Presentaciones</h2>
                        <p>
                            Define cómo compras o vendes un producto sin perder su unidad real de inventario.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevaPresentacion">
                            Nueva presentación
                        </button>
                    <?php endif; ?>
                </div>

                <section class="catalogos-card">
                    <div class="simple-search simple-search--two">
                        <label class="field">
                            <span>Buscar</span>
                            <input
                                type="search"
                                id="buscarPresentacion"
                                maxlength="140"
                                placeholder="Código, producto o presentación"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaPresentacion">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="catalogos-table catalogos-table--presentaciones">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Presentación</th>
                                    <th>Unidad</th>
                                    <th>Conversión a base</th>
                                    <th>Uso</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaPresentaciones">
                                <tr>
                                    <td colspan="7" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="catalogos-pagination">
                        <span id="textoPaginaPresentaciones">0 registros</span>

                        <div>
                            <button type="button" class="btn-secondary" id="btnPresentacionAnterior">
                                Anterior
                            </button>

                            <span id="paginaPresentacionActual">Página 1 de 1</span>

                            <button type="button" class="btn-secondary" id="btnPresentacionSiguiente">
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>



            <!-- =====================================================
                 PRECIOS DE VENTA
                 ===================================================== -->
            <section id="seccionPrecios" class="catalogo-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Precios de venta</h2>
                        <p>
                            Configura menudeo y mayoreo por unidad base o presentación.
                            Las cotizaciones y ventas tomarán el precio vigente automáticamente.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button type="button" class="btn-primary" id="btnNuevoPrecioVenta">
                            Nuevo precio
                        </button>
                    <?php endif; ?>
                </div>

                <section class="catalogos-card">
                    <div class="filters-grid filters-grid--prices">
                        <label class="field field--search-wide">
                            <span>Buscar</span>
                            <input
                                type="search"
                                id="buscarPrecioVenta"
                                maxlength="140"
                                placeholder="Código, producto o presentación"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Nivel</span>
                            <select id="filtroNivelPrecio">
                                <option value="TODOS">Todos</option>
                                <option value="MENUDEO">Menudeo</option>
                                <option value="MAYOREO">Mayoreo</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Vigencia</span>
                            <select id="filtroEstadoPrecio">
                                <option value="ACTUALES">Vigentes ahora</option>
                                <option value="PROGRAMADOS">Programados</option>
                                <option value="HISTORICOS">Históricos</option>
                                <option value="INACTIVOS">Desactivados</option>
                                <option value="TODOS">Todos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Moneda</span>
                            <select id="filtroMonedaPrecio">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>
                            <select id="porPaginaPrecio">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="pricing-note">
                        <strong>Regla:</strong>
                        al cambiar un precio no se borra el anterior. Se crea una nueva vigencia para conservar el historial.
                    </div>

                    <div class="table-wrap">
                        <table class="catalogos-table catalogos-table--prices">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Se vende como</th>
                                    <th>Nivel</th>
                                    <th>Desde cantidad</th>
                                    <th>Precio</th>
                                    <th>Impuesto</th>
                                    <th>Vigencia</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaPreciosVenta">
                                <tr>
                                    <td colspan="9" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="catalogos-pagination">
                        <span id="textoPaginaPrecios">0 registros</span>

                        <div>
                            <button type="button" class="btn-secondary" id="btnPrecioAnterior">
                                Anterior
                            </button>

                            <span id="paginaPrecioActual">Página 1 de 1</span>

                            <button type="button" class="btn-secondary" id="btnPrecioSiguiente">
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

        </main>
    </div>
</div>

<!-- ================================================================
     MODAL PRODUCTO
     ================================================================ -->
<div class="modal-backdrop" id="modalProducto" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>CATÁLOGO DE PRODUCTOS</small>
                <h2 id="tituloModalProducto">Nuevo producto</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalProducto">×</button>
        </header>

        <form id="formProducto">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_PRODUCTO">
            <input type="hidden" name="producto_id" id="productoId">

            <div id="mensajeProducto" class="catalogos-message catalogos-message--error" hidden></div>

            <div class="form-grid">
                <label class="field">
                    <span>Código del producto</span>
                    <input
                        type="text"
                        id="productoCodigo"
                        placeholder="Se genera automáticamente al guardar"
                        readonly
                    >
                    <small>Ejemplo: PROD-000001. No necesitas capturarlo.</small>
                </label>

                <label class="field">
                    <span>Tipo *</span>
                    <select name="tipo" id="productoTipo" required>
                        <option value="">Selecciona</option>
                        <option value="MATERIA_PRIMA">Materia prima</option>
                        <option value="PRODUCTO_TERMINADO">Producto terminado</option>
                    </select>
                </label>

                <label class="field field--span-2">
                    <span>Nombre *</span>
                    <input
                        type="text"
                        name="nombre"
                        id="productoNombre"
                        maxlength="180"
                        autocomplete="off"
                        required
                    >
                </label>

                <label class="field">
                    <span>Categoría</span>
                    <select name="categoria_id" id="productoCategoria">
                        <option value="">Sin categoría</option>
                    </select>
                </label>

                <label class="field">
                    <span>Unidad base *</span>
                    <select name="unidad_base_id" id="productoUnidadBase" required>
                        <option value="">Selecciona</option>
                    </select>
                    <small>Es la unidad real en la que se controla el inventario.</small>
                </label>

                <label class="field">
                    <span>Impuesto</span>
                    <select name="tasa_impuesto_id" id="productoImpuesto">
                        <option value="">Sin impuesto definido</option>
                    </select>
                </label>

                <label class="field field--span-2">
                    <span>Descripción</span>
                    <textarea
                        name="descripcion"
                        id="productoDescripcion"
                        rows="3"
                        maxlength="5000"
                    ></textarea>
                </label>
            </div>

            <div class="checks-grid">
                <label class="check-card">
                    <input
                        type="checkbox"
                        name="controla_inventario"
                        value="1"
                        id="productoControlaInventario"
                        checked
                    >
                    <span>
                        <strong>Controla inventario</strong>
                        <small>Participará en existencias, Kardex, mermas y alertas.</small>
                    </span>
                </label>

                <label class="check-card">
                    <input
                        type="checkbox"
                        name="permite_fraccion"
                        value="1"
                        id="productoPermiteFraccion"
                        checked
                    >
                    <span>
                        <strong>Permite fracciones</strong>
                        <small>Ejemplo: 2.5 kg o 0.75 toneladas.</small>
                    </span>
                </label>
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalProducto">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar producto
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL CATEGORÍA
     ================================================================ -->
<div class="modal-backdrop" id="modalCategoria" hidden>
    <section class="modal-card" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>CATÁLOGO</small>
                <h2 id="tituloModalCategoria">Nueva categoría</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalCategoria">×</button>
        </header>

        <form id="formCategoria">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_CATEGORIA">
            <input type="hidden" name="categoria_id" id="categoriaId">

            <div id="mensajeCategoria" class="catalogos-message catalogos-message--error" hidden></div>

            <label class="field">
                <span>Nombre *</span>
                <input type="text" name="nombre" id="categoriaNombre" maxlength="120" required>
            </label>

            <label class="field">
                <span>Descripción</span>
                <textarea name="descripcion" id="categoriaDescripcion" rows="3" maxlength="255"></textarea>
            </label>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalCategoria">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar categoría
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL UNIDAD
     ================================================================ -->
<div class="modal-backdrop" id="modalUnidad" hidden>
    <section class="modal-card" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>UNIDAD DE MEDIDA</small>
                <h2 id="tituloModalUnidad">Nueva unidad</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalUnidad">×</button>
        </header>

        <form id="formUnidad">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_UNIDAD">
            <input type="hidden" name="unidad_id" id="unidadId">

            <div id="mensajeUnidad" class="catalogos-message catalogos-message--error" hidden></div>

            <div class="form-grid">
                <label class="field">
                    <span>Código *</span>
                    <input type="text" name="codigo" id="unidadCodigo" maxlength="20" required>
                    <small>Ejemplo: KG, TON, L, PZA.</small>
                </label>

                <label class="field">
                    <span>Símbolo *</span>
                    <input type="text" name="simbolo" id="unidadSimbolo" maxlength="20" required>
                    <small>Ejemplo: kg, t, L, pza.</small>
                </label>

                <label class="field field--span-2">
                    <span>Nombre *</span>
                    <input type="text" name="nombre" id="unidadNombre" maxlength="80" required>
                </label>

                <label class="field field--span-2">
                    <span>Tipo *</span>
                    <select name="tipo" id="unidadTipo" required>
                        <option value="MASA">Masa</option>
                        <option value="VOLUMEN">Volumen</option>
                        <option value="UNIDAD">Unidad</option>
                        <option value="OTRO">Otro</option>
                    </select>
                </label>
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalUnidad">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar unidad
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL PRESENTACIÓN
     ================================================================ -->
<div class="modal-backdrop" id="modalPresentacion" hidden>
    <section class="modal-card modal-card--medium" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>CONVERSIÓN DE UNIDADES</small>
                <h2 id="tituloModalPresentacion">Nueva presentación</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalPresentacion">×</button>
        </header>

        <form id="formPresentacion">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_PRESENTACION">
            <input type="hidden" name="presentacion_id" id="presentacionId">
            <input type="hidden" name="producto_id" id="presentacionProductoId">

            <div id="mensajePresentacion" class="catalogos-message catalogos-message--error" hidden></div>

            <label class="field autocomplete-field">
                <span>Producto *</span>
                <input
                    type="search"
                    id="presentacionProductoBuscar"
                    maxlength="140"
                    placeholder="Escribe el código o nombre..."
                    autocomplete="off"
                >

                <div id="resultadosProductoPresentacion" class="autocomplete-results" hidden></div>
                <small id="productoSeleccionadoTexto">No hay producto seleccionado.</small>
            </label>

            <label class="field">
                <span>Nombre de la presentación *</span>
                <input
                    type="text"
                    name="nombre"
                    id="presentacionNombre"
                    maxlength="120"
                    placeholder="Ej. Tonelada, Bulto 20 kg, Saco 40 kg"
                    required
                >
            </label>

            <div class="form-grid">
                <label class="field">
                    <span>Unidad de la presentación *</span>
                    <select name="unidad_id" id="presentacionUnidad" required>
                        <option value="">Selecciona</option>
                    </select>
                </label>

                <label class="field">
                    <span>Factor a unidad base *</span>
                    <input
                        type="number"
                        name="factor_a_unidad_base"
                        id="presentacionFactor"
                        min="0.000001"
                        step="0.000001"
                        required
                    >
                </label>
            </div>

            <div id="ayudaConversion" class="conversion-help">
                Selecciona un producto para conocer su unidad base.
            </div>

            <div class="checks-grid">
                <label class="check-card">
                    <input type="checkbox" name="es_compra" value="1" id="presentacionCompra" checked>
                    <span><strong>Usar en compras</strong></span>
                </label>

                <label class="check-card">
                    <input type="checkbox" name="es_venta" value="1" id="presentacionVenta" checked>
                    <span><strong>Usar en ventas</strong></span>
                </label>
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalPresentacion">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar presentación
                </button>
            </footer>
        </form>
    </section>
</div>


<!-- ================================================================
     MODAL PRECIO DE VENTA
     ================================================================ -->
<div class="modal-backdrop" id="modalPrecioVenta" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>PRECIOS COMERCIALES</small>
                <h2 id="tituloModalPrecioVenta">Nuevo precio de venta</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalPrecioVenta">×</button>
        </header>

        <form id="formPrecioVenta">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_PRECIO_VENTA">
            <input type="hidden" name="precio_origen_id" id="precioOrigenId" value="0">
            <input type="hidden" name="producto_id" id="precioProductoId">

            <div id="mensajePrecioVenta" class="catalogos-message catalogos-message--error" hidden></div>

            <div class="price-form-intro">
                <strong>El precio no se guarda directamente en el producto.</strong>
                <span>
                    Se relaciona con el producto, la forma en que se vende, el nivel menudeo/mayoreo y su vigencia.
                </span>
            </div>

            <label class="field autocomplete-field">
                <span>Producto *</span>
                <input
                    type="search"
                    id="precioProductoBuscar"
                    maxlength="140"
                    placeholder="Escribe código o nombre del producto..."
                    autocomplete="off"
                >
                <div id="resultadosProductoPrecio" class="autocomplete-results" hidden></div>
                <small id="precioProductoSeleccionado">Selecciona un producto de los resultados.</small>
            </label>

            <div class="form-grid">
                <label class="field field--span-2">
                    <span>¿Cómo se vende? *</span>
                    <select name="presentacion_id" id="precioPresentacion" required disabled>
                        <option value="0">Primero selecciona un producto</option>
                    </select>
                    <small id="ayudaPrecioPresentacion">
                        Puedes definir precio por unidad base o por una presentación de venta.
                    </small>
                </label>

                <label class="field">
                    <span>Nivel de precio *</span>
                    <select name="nivel_precio" id="precioNivel" required>
                        <option value="MENUDEO">Menudeo</option>
                        <option value="MAYOREO">Mayoreo</option>
                    </select>
                </label>

                <label class="field">
                    <span id="etiquetaCantidadPrecio">Cantidad mínima para mayoreo</span>
                    <input
                        type="number"
                        name="cantidad_minima"
                        id="precioCantidadMinima"
                        min="1"
                        step="0.000001"
                        value="1"
                        readonly
                        required
                    >
                    <small id="ayudaCantidadPrecio">Menudeo aplica desde 1. La cantidad mínima solo se configura para mayoreo.</small>
                </label>

                <label class="field">
                    <span>Moneda *</span>
                    <select name="moneda_id" id="precioMoneda" required>
                        <option value="">Selecciona</option>
                    </select>
                </label>

                <label class="field">
                    <span>Precio unitario *</span>
                    <input
                        type="number"
                        name="precio_unitario"
                        id="precioImporte"
                        min="0.0001"
                        step="0.0001"
                        placeholder="0.0000"
                        required
                    >
                    <small>Es el precio de una unidad de la opción seleccionada arriba.</small>
                </label>

                <label class="field field--span-2">
                    <span>Impuesto</span>
                    <select name="tasa_impuesto_id" id="precioImpuesto">
                        <option value="">Usar el impuesto configurado en el producto</option>
                    </select>
                    <small id="ayudaImpuestoPrecio">Se usará el impuesto del producto salvo que selecciones otro.</small>
                </label>

                <label class="field">
                    <span>Vigente desde *</span>
                    <input type="datetime-local" name="vigente_desde" id="precioVigenteDesde" required>
                </label>

                <label class="field">
                    <span>Vigente hasta</span>
                    <input type="datetime-local" name="vigente_hasta" id="precioVigenteHasta">
                    <small>Déjalo vacío si no tiene fecha de término.</small>
                </label>
            </div>

            <div class="price-rule-preview" id="resumenReglaPrecio">
                Selecciona producto, forma de venta y precio para ver la regla.
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalPrecioVenta">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar nueva vigencia
                </button>
            </footer>
        </form>
    </section>
</div>

<script>
(function () {
    'use strict';

    const puedeAdministrar = <?= $puedeAdministrar ? 'true' : 'false' ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const seccionInicial = <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const estado = {
        seccion: seccionInicial,
        categorias: [],
        unidades: [],
        impuestos: [],
        monedas: [],
        productos: [],
        precios: [],

        producto: { pagina: 1, totalPaginas: 1, porPagina: 20 },
        categoria: { pagina: 1, totalPaginas: 1, porPagina: 20 },
        unidad: { pagina: 1, totalPaginas: 1, porPagina: 20 },
        presentacion: { pagina: 1, totalPaginas: 1, porPagina: 20 },
        precio: { pagina: 1, totalPaginas: 1, porPagina: 20 },

        timerProducto: null,
        timerCategoria: null,
        timerUnidad: null,
        timerPresentacion: null,
        timerPrecio: null,
        timerAutocomplete: null,
        timerAutocompletePrecio: null,

        productoPresentacion: null,
        editandoPresentacion: false,
        productoPrecio: null,
        resultadosPrecioProducto: []
    };

    const $ = function (id) {
        return document.getElementById(id);
    };

    function escapeHtml(valor) {
        return String(valor == null ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function numero(valor, decimales) {
        const n = Number(valor || 0);

        return new Intl.NumberFormat(
            'es-MX',
            {
                minimumFractionDigits: decimales || 0,
                maximumFractionDigits: decimales || 0
            }
        ).format(Number.isFinite(n) ? n : 0);
    }

    function tipoProductoTexto(tipo) {
        return tipo === 'MATERIA_PRIMA'
            ? 'Materia prima'
            : 'Producto terminado';
    }

    function tipoUnidadTexto(tipo) {
        const mapa = {
            MASA: 'Masa',
            VOLUMEN: 'Volumen',
            UNIDAD: 'Unidad',
            OTRO: 'Otro'
        };

        return mapa[tipo] || tipo || '—';
    }

    function status(texto, tipo) {
        return '<span class="status-badge status-badge--'
            + escapeHtml(tipo)
            + '">'
            + escapeHtml(texto)
            + '</span>';
    }

    function botonAccion(texto, accion, id, valor, variante) {
        return '<button '
            + 'type="button" '
            + 'class="table-action'
            + (variante ? ' table-action--' + variante : '')
            + '" '
            + 'data-action="' + escapeHtml(accion) + '" '
            + 'data-id="' + Number(id) + '" '
            + (
                valor === null || typeof valor === 'undefined'
                    ? ''
                    : 'data-value="' + escapeHtml(valor) + '" '
            )
            + '>'
            + escapeHtml(texto)
            + '</button>';
    }

    function mostrarMensaje(elemento, texto, tipo) {
        elemento.textContent = texto;
        elemento.className = 'catalogos-message catalogos-message--' + (tipo || 'error');
        elemento.hidden = false;
    }

    function ocultarMensaje(elemento) {
        elemento.textContent = '';
        elemento.hidden = true;
    }

    function mostrarError(error) {
        mostrarMensaje(
            $('mensajePagina'),
            error && error.message ? error.message : 'Ocurrió un error.',
            'error'
        );
    }

    async function api(url, opciones) {
        const respuesta = await fetch(
            url,
            Object.assign(
                {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                },
                opciones || {}
            )
        );

        const texto = await respuesta.text();
        let datos;

        try {
            datos = JSON.parse(texto);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (datos.sesion_expirada && datos.redirect) {
            window.location.href = datos.redirect;
            return null;
        }

        if (!respuesta.ok || datos.success !== true) {
            const error = new Error(
                datos.mensaje || 'No fue posible completar la operación.'
            );
            error.data = datos;
            throw error;
        }

        return datos;
    }

    function abrirModal(id) {
        $(id).hidden = false;
        document.body.classList.add('modal-open');
    }

    function cerrarModal(id) {
        $(id).hidden = true;

        if (!document.querySelector('.modal-backdrop:not([hidden])')) {
            document.body.classList.remove('modal-open');
        }

        if (id === 'modalPresentacion') {
            cerrarResultadosProducto();
        }

        if (id === 'modalPrecioVenta') {
            cerrarResultadosProductoPrecio();
        }
    }

    async function enviarFormulario(form, mensaje, alExito) {
        ocultarMensaje(mensaje);

        const boton = form.querySelector('button[type="submit"]');
        const original = boton.textContent;

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await api(
                '?productos_api=1',
                {
                    method: 'POST',
                    body: new FormData(form)
                }
            );

            await alExito(datos);

        } catch (error) {
            mostrarMensaje(mensaje, error.message, 'error');

        } finally {
            boton.disabled = false;
            boton.textContent = original;
        }
    }

    async function postSimple(accion, valores) {
        const form = new FormData();
        form.append('csrf_token', csrfToken);
        form.append('accion', accion);

        Object.keys(valores).forEach(
            function (clave) {
                form.append(clave, String(valores[clave]));
            }
        );

        const datos = await api(
            '?productos_api=1',
            {
                method: 'POST',
                body: form
            }
        );

        mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
        return datos;
    }

    function renderPaginacion(tipo, p) {
        const mapa = {
            producto: {
                texto: 'textoPaginaProductos',
                pagina: 'paginaProductoActual',
                anterior: 'btnProductoAnterior',
                siguiente: 'btnProductoSiguiente'
            },
            categoria: {
                texto: 'textoPaginaCategorias',
                pagina: 'paginaCategoriaActual',
                anterior: 'btnCategoriaAnterior',
                siguiente: 'btnCategoriaSiguiente'
            },
            unidad: {
                texto: 'textoPaginaUnidades',
                pagina: 'paginaUnidadActual',
                anterior: 'btnUnidadAnterior',
                siguiente: 'btnUnidadSiguiente'
            },
            presentacion: {
                texto: 'textoPaginaPresentaciones',
                pagina: 'paginaPresentacionActual',
                anterior: 'btnPresentacionAnterior',
                siguiente: 'btnPresentacionSiguiente'
            },
            precio: {
                texto: 'textoPaginaPrecios',
                pagina: 'paginaPrecioActual',
                anterior: 'btnPrecioAnterior',
                siguiente: 'btnPrecioSiguiente'
            }
        };

        const ids = mapa[tipo];
        const local = estado[tipo];

        local.pagina = Number(p.pagina || 1);
        local.totalPaginas = Number(p.total_paginas || 1);

        $(ids.texto).textContent = Number(p.total_registros || 0) + ' registro(s)';
        $(ids.pagina).textContent = 'Página ' + local.pagina + ' de ' + local.totalPaginas;
        $(ids.anterior).disabled = local.pagina <= 1;
        $(ids.siguiente).disabled = local.pagina >= local.totalPaginas;
    }

    async function cargarCatalogos() {
        const datos = await api('?productos_api=1&accion=CATALOGOS');

        estado.categorias = datos.categorias || [];
        estado.unidades = datos.unidades || [];
        estado.impuestos = datos.impuestos || [];
        estado.monedas = datos.monedas || [];

        renderSelectsCatalogos();
    }

    function renderSelectsCatalogos() {
        const categoriasActivas = estado.categorias.filter(c => c.activo === 1);
        const unidadesActivas = estado.unidades.filter(u => u.activo === 1);
        const impuestosActivos = estado.impuestos.filter(i => i.activo === 1);

        $('filtroCategoriaProducto').innerHTML =
            '<option value="0">Todas</option>'
            + estado.categorias.map(
                c => '<option value="' + c.id + '">'
                    + escapeHtml(c.nombre + (c.activo === 1 ? '' : ' (inactiva)'))
                    + '</option>'
            ).join('');

        $('productoCategoria').innerHTML =
            '<option value="">Sin categoría</option>'
            + categoriasActivas.map(
                c => '<option value="' + c.id + '">'
                    + escapeHtml(c.nombre)
                    + '</option>'
            ).join('');

        const opcionesUnidad = unidadesActivas.map(
            u => '<option value="' + u.id + '">'
                + escapeHtml(u.nombre + ' (' + u.simbolo + ')')
                + '</option>'
        ).join('');

        $('productoUnidadBase').innerHTML =
            '<option value="">Selecciona</option>' + opcionesUnidad;

        $('presentacionUnidad').innerHTML =
            '<option value="">Selecciona</option>' + opcionesUnidad;

        $('productoImpuesto').innerHTML =
            '<option value="">Sin impuesto definido</option>'
            + impuestosActivos.map(
                i => '<option value="' + i.id + '">'
                    + escapeHtml(i.nombre + ' · ' + numero(i.porcentaje, 2) + '%')
                    + '</option>'
            ).join('');

        $('precioImpuesto').innerHTML =
            '<option value="">Usar el impuesto configurado en el producto</option>'
            + impuestosActivos.map(
                i => '<option value="' + i.id + '">'
                    + escapeHtml(i.nombre + ' · ' + numero(i.porcentaje, 2) + '%')
                    + '</option>'
            ).join('');

        const monedasActivas = estado.monedas.filter(m => m.activo === 1);
        const opcionesMoneda = monedasActivas.map(
            m => '<option value="' + m.id + '">'
                + escapeHtml(m.codigo + ' · ' + m.nombre)
                + '</option>'
        ).join('');

        $('precioMoneda').innerHTML = '<option value="">Selecciona</option>' + opcionesMoneda;
        $('filtroMonedaPrecio').innerHTML =
            '<option value="0">Todas</option>'
            + estado.monedas.map(
                m => '<option value="' + m.id + '">'
                    + escapeHtml(m.codigo + (m.activo === 1 ? '' : ' (inactiva)'))
                    + '</option>'
            ).join('');
    }

    function cambiarSeccion(seccion) {
        const mapa = {
            productos: 'seccionProductos',
            categorias: 'seccionCategorias',
            unidades: 'seccionUnidades',
            presentaciones: 'seccionPresentaciones',
            precios: 'seccionPrecios'
        };

        const seccionSegura = Object.prototype.hasOwnProperty.call(mapa, seccion)
            ? seccion
            : 'productos';

        estado.seccion = seccionSegura;

        document.querySelectorAll('.catalogo-section').forEach(
            section => section.hidden = true
        );

        document.querySelectorAll('.catalogo-tab').forEach(
            tab => tab.classList.toggle('is-active', tab.dataset.seccion === seccionSegura)
        );

        $(mapa[seccionSegura]).hidden = false;

        const url = new URL(window.location.href);
        url.searchParams.set('seccion', seccionSegura);
        history.replaceState(null, '', url);

        cargarSeccionActual().catch(mostrarError);
    }

    /* ==============================================================
       PRECIOS DE VENTA
       ============================================================== */

    function fechaLocalInput(fecha) {
        const d = fecha || new Date();
        const pad = n => String(n).padStart(2, '0');

        return d.getFullYear()
            + '-' + pad(d.getMonth() + 1)
            + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours())
            + ':' + pad(d.getMinutes());
    }

    function fechaPrecioTexto(valor) {
        if (!valor) return 'Sin límite';

        const d = new Date(String(valor).replace(' ', 'T'));

        if (Number.isNaN(d.getTime())) {
            return String(valor);
        }

        return new Intl.DateTimeFormat(
            'es-MX',
            {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }
        ).format(d);
    }

    function estadoPrecioHtml(estadoPrecio) {
        const mapa = {
            ACTUAL: ['Vigente', 'active'],
            PROGRAMADO: ['Programado', 'info'],
            HISTORICO: ['Histórico', 'history'],
            INACTIVO: ['Desactivado', 'inactive']
        };

        const item = mapa[estadoPrecio] || [estadoPrecio || '—', 'inactive'];
        return status(item[0], item[1]);
    }

    async function cargarPreciosVenta() {
        const params = new URLSearchParams({
            productos_api: '1',
            accion: 'LISTAR_PRECIOS_VENTA',
            pagina: String(estado.precio.pagina),
            por_pagina: String(estado.precio.porPagina),
            busqueda: $('buscarPrecioVenta').value.trim(),
            nivel: $('filtroNivelPrecio').value,
            estado: $('filtroEstadoPrecio').value,
            moneda_id: $('filtroMonedaPrecio').value
        });

        $('tablaPreciosVenta').innerHTML =
            '<tr><td colspan="9" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        estado.precios = datos.precios || [];

        renderPreciosVenta(estado.precios);
        renderPaginacion('precio', datos.paginacion);
    }

    function renderPreciosVenta(filas) {
        if (!filas.length) {
            $('tablaPreciosVenta').innerHTML =
                '<tr><td colspan="9" class="empty-cell">No se encontraron precios de venta.</td></tr>';
            return;
        }

        $('tablaPreciosVenta').innerHTML = filas.map(
            function (p) {
                let acciones = '';

                if (puedeAdministrar) {
                    acciones += botonAccion('Nueva vigencia', 'actualizar-precio', p.id);

                    if (p.activo === 1 && ['ACTUAL', 'PROGRAMADO'].includes(p.estado_calculado)) {
                        acciones += botonAccion('Desactivar', 'desactivar-precio', p.id, null, 'danger');
                    }
                }

                const vigencia = '<strong>Desde:</strong> ' + escapeHtml(fechaPrecioTexto(p.vigente_desde))
                    + '<small class="cell-secondary">Hasta: '
                    + escapeHtml(p.vigente_hasta ? fechaPrecioTexto(p.vigente_hasta) : 'Sin límite')
                    + '</small>';

                return '<tr>'
                    + '<td><strong>' + escapeHtml(p.producto_nombre) + '</strong>'
                    + '<small class="cell-secondary">' + escapeHtml(p.sku) + '</small></td>'
                    + '<td><strong>' + escapeHtml(p.formato_venta) + '</strong>'
                    + '<small class="cell-secondary">Unidad: ' + escapeHtml(p.unidad_simbolo || p.unidad_nombre) + '</small></td>'
                    + '<td>' + status(p.nivel_precio === 'MAYOREO' ? 'Mayoreo' : 'Menudeo', p.nivel_precio === 'MAYOREO' ? 'info' : 'active') + '</td>'
                    + '<td>' + numero(p.cantidad_minima, 6) + ' ' + escapeHtml(p.unidad_simbolo || '') + '</td>'
                    + '<td><strong>' + escapeHtml(p.moneda_simbolo || '') + numero(p.precio_unitario, 4) + '</strong>'
                    + '<small class="cell-secondary">' + escapeHtml(p.moneda_codigo) + '</small></td>'
                    + '<td>' + escapeHtml(p.impuesto_nombre) + '<small class="cell-secondary">' + numero(p.impuesto_porcentaje, 2) + '%</small></td>'
                    + '<td>' + vigencia + '</td>'
                    + '<td>' + estadoPrecioHtml(p.estado_calculado) + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function cerrarResultadosProductoPrecio() {
        const contenedor = $('resultadosProductoPrecio');
        contenedor.innerHTML = '';
        contenedor.hidden = true;
    }

    function limpiarProductoPrecio() {
        estado.productoPrecio = null;
        estado.resultadosPrecioProducto = [];
        $('precioProductoId').value = '';
        $('precioProductoBuscar').value = '';
        $('precioProductoBuscar').readOnly = false;
        $('precioProductoSeleccionado').textContent = 'Selecciona un producto de los resultados.';
        $('precioPresentacion').innerHTML = '<option value="0">Primero selecciona un producto</option>';
        $('precioPresentacion').disabled = true;
        $('ayudaPrecioPresentacion').textContent = 'Puedes definir precio por unidad base o por una presentación de venta.';
        $('ayudaImpuestoPrecio').textContent = 'Se usará el impuesto del producto salvo que selecciones otro.';
        cerrarResultadosProductoPrecio();
        actualizarResumenReglaPrecio();
    }

    async function buscarProductosPrecio(texto) {
        const q = texto.trim();

        if (q.length < 1) {
            cerrarResultadosProductoPrecio();
            return;
        }

        const datos = await api(
            '?productos_api=1&accion=BUSCAR_PRODUCTOS&q=' + encodeURIComponent(q)
        );

        estado.resultadosPrecioProducto = datos.productos || [];
        renderResultadosProductoPrecio(estado.resultadosPrecioProducto);
    }

    function renderResultadosProductoPrecio(productos) {
        const contenedor = $('resultadosProductoPrecio');

        if (!productos.length) {
            contenedor.innerHTML = '<div class="autocomplete-empty">Sin coincidencias.</div>';
            contenedor.hidden = false;
            return;
        }

        contenedor.innerHTML = productos.map(
            p => '<button type="button" class="autocomplete-option" data-precio-producto-id="' + p.id + '">'
                + '<strong>' + escapeHtml(p.nombre) + '</strong>'
                + '<span>' + escapeHtml(p.sku + ' · Unidad base: ' + p.unidad_base_simbolo) + '</span>'
                + '</button>'
        ).join('');

        contenedor.hidden = false;
    }

    async function seleccionarProductoPrecio(producto, bloquear) {
        estado.productoPrecio = producto;
        $('precioProductoId').value = producto.id;
        $('precioProductoBuscar').value = producto.sku + ' · ' + producto.nombre;
        $('precioProductoBuscar').readOnly = Boolean(bloquear);
        $('precioProductoSeleccionado').textContent = 'Producto seleccionado: ' + producto.nombre;
        cerrarResultadosProductoPrecio();

        const datos = await api(
            '?productos_api=1&accion=OPCIONES_PRECIO_PRODUCTO&producto_id='
            + encodeURIComponent(producto.id)
        );

        estado.productoPrecio = datos.producto;

        const opciones = [
            '<option value="0">Unidad base · '
                + escapeHtml(datos.producto.unidad_base_nombre + ' (' + datos.producto.unidad_base_simbolo + ')')
                + '</option>'
        ];

        (datos.presentaciones || []).forEach(
            function (p) {
                opciones.push(
                    '<option value="' + p.id + '">'
                    + escapeHtml(p.nombre + ' · ' + p.unidad_nombre + ' (' + p.unidad_simbolo + ')')
                    + '</option>'
                );
            }
        );

        $('precioPresentacion').innerHTML = opciones.join('');
        $('precioPresentacion').disabled = false;
        $('ayudaPrecioPresentacion').textContent =
            'Unidad base: ' + datos.producto.unidad_base_nombre
            + ' (' + datos.producto.unidad_base_simbolo + ').';

        $('ayudaImpuestoPrecio').textContent =
            'Impuesto del producto: ' + datos.producto.impuesto_nombre
            + ' · ' + numero(datos.producto.impuesto_porcentaje, 2) + '%.';

        actualizarResumenReglaPrecio();
    }

    function monedaBaseId() {
        const base = estado.monedas.find(m => m.activo === 1 && m.es_base === 1);
        return base ? String(base.id) : '';
    }

    function aplicarReglaNivelPrecio() {
        const esMayoreo = $('precioNivel').value === 'MAYOREO';
        const campo = $('precioCantidadMinima');

        if (esMayoreo) {
            campo.readOnly = false;
            campo.min = '1.000001';

            if (Number(campo.value || 0) <= 1) {
                campo.value = '20';
            }

            $('etiquetaCantidadPrecio').textContent = 'Cantidad mínima para mayoreo *';
            $('ayudaCantidadPrecio').textContent =
                'Indica desde cuántas unidades de esta opción de venta debe aplicar el precio de mayoreo.';
        } else {
            campo.value = '1';
            campo.readOnly = true;
            campo.min = '1';
            $('etiquetaCantidadPrecio').textContent = 'Inicio de menudeo';
            $('ayudaCantidadPrecio').textContent =
                'Menudeo aplica automáticamente desde 1. La cantidad mínima solo se configura para mayoreo.';
        }
    }

    function nuevoPrecioVenta(producto) {
        $('formPrecioVenta').reset();
        $('precioOrigenId').value = '0';
        $('tituloModalPrecioVenta').textContent = 'Nuevo precio de venta';
        $('precioNivel').value = 'MENUDEO';
        $('precioCantidadMinima').value = '1';
        aplicarReglaNivelPrecio();
        $('precioVigenteDesde').value = fechaLocalInput(new Date());
        $('precioVigenteHasta').value = '';
        $('precioMoneda').value = monedaBaseId();
        $('precioImpuesto').value = '';
        ocultarMensaje($('mensajePrecioVenta'));
        limpiarProductoPrecio();

        abrirModal('modalPrecioVenta');

        if (producto) {
            seleccionarProductoPrecio(producto, false).catch(
                error => mostrarMensaje($('mensajePrecioVenta'), error.message, 'error')
            );
        }
    }

    async function actualizarPrecioVenta(id) {
        const datos = await api(
            '?productos_api=1&accion=DETALLE_PRECIO_VENTA&id=' + encodeURIComponent(id)
        );
        const p = datos.precio;

        $('formPrecioVenta').reset();
        $('precioOrigenId').value = p.id;
        $('tituloModalPrecioVenta').textContent = 'Nueva vigencia de precio';
        $('precioNivel').value = p.nivel_precio;
        $('precioCantidadMinima').value = p.nivel_precio === 'MENUDEO' ? '1' : p.cantidad_minima;
        aplicarReglaNivelPrecio();
        $('precioMoneda').value = p.moneda_id;
        $('precioImporte').value = Number(p.precio_unitario).toFixed(4);
        $('precioImpuesto').value = p.tasa_impuesto_id || '';
        $('precioVigenteDesde').value = fechaLocalInput(new Date());
        $('precioVigenteHasta').value = '';
        ocultarMensaje($('mensajePrecioVenta'));

        abrirModal('modalPrecioVenta');

        await seleccionarProductoPrecio(
            {
                id: p.producto_id,
                sku: p.sku,
                nombre: p.producto_nombre,
                unidad_base_id: p.unidad_base_id,
                unidad_base_codigo: p.unidad_base_codigo,
                unidad_base_nombre: p.unidad_base_nombre,
                unidad_base_simbolo: p.unidad_base_simbolo
            },
            true
        );

        $('precioPresentacion').value = String(p.presentacion_id || 0);
        actualizarResumenReglaPrecio();
    }

    async function desactivarPrecioVenta(id) {
        if (!window.confirm('¿Desactivar este precio? El historial no se eliminará.')) {
            return;
        }

        await postSimple('DESACTIVAR_PRECIO_VENTA', { precio_id: id });
        await cargarPreciosVenta();

        if (estado.productos.length) {
            await cargarProductos();
        }
    }

    function actualizarResumenReglaPrecio() {
        const producto = estado.productoPrecio;

        if (!producto || !$('precioProductoId').value) {
            $('resumenReglaPrecio').textContent =
                'Selecciona producto, forma de venta y precio para ver la regla.';
            return;
        }

        const presentacion = $('precioPresentacion').selectedOptions[0];
        const nivel = $('precioNivel').value === 'MAYOREO' ? 'Mayoreo' : 'Menudeo';
        const cantidad = Number($('precioCantidadMinima').value || 0);
        const precio = Number($('precioImporte').value || 0);
        const moneda = $('precioMoneda').selectedOptions[0];

        $('resumenReglaPrecio').innerHTML =
            '<strong>' + escapeHtml(producto.nombre) + '</strong> · '
            + escapeHtml(nivel) + ' desde ' + numero(nivel === 'Menudeo' ? 1 : cantidad, 6)
            + ' ' + escapeHtml(presentacion ? presentacion.textContent.trim() : '')
            + (precio > 0
                ? ' → <strong>' + escapeHtml(moneda ? moneda.textContent.split('·')[0].trim() : '')
                    + ' ' + numero(precio, 4) + '</strong>'
                : ' → captura el precio');
    }

    async function cargarSeccionActual() {
        ocultarMensaje($('mensajePagina'));

        if (estado.seccion === 'productos') {
            await cargarProductos();
        } else if (estado.seccion === 'categorias') {
            await cargarCategorias();
        } else if (estado.seccion === 'unidades') {
            await cargarUnidades();
        } else if (estado.seccion === 'presentaciones') {
            await cargarPresentaciones();
        } else if (estado.seccion === 'precios') {
            await cargarPreciosVenta();
        }
    }

    /* ==============================================================
       PRODUCTOS
       ============================================================== */

    async function cargarProductos() {
        const params = new URLSearchParams({
            productos_api: '1',
            accion: 'LISTAR_PRODUCTOS',
            pagina: String(estado.producto.pagina),
            por_pagina: String(estado.producto.porPagina),
            busqueda: $('buscarProducto').value.trim(),
            tipo: $('filtroTipoProducto').value,
            categoria_id: $('filtroCategoriaProducto').value,
            estado: $('filtroEstadoProducto').value
        });

        $('tablaProductos').innerHTML =
            '<tr><td colspan="9" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        estado.productos = datos.productos || [];

        renderProductos(estado.productos);
        renderPaginacion('producto', datos.paginacion);
        renderKpisProductos(datos.resumen || {});
    }

    function renderProductos(filas) {
        if (!filas.length) {
            $('tablaProductos').innerHTML =
                '<tr><td colspan="9" class="empty-cell">No se encontraron productos.</td></tr>';
            return;
        }

        $('tablaProductos').innerHTML = filas.map(
            function (p) {
                let acciones = '';

                if (puedeAdministrar) {
                    acciones += botonAccion('Editar', 'editar-producto', p.id);
                    acciones += botonAccion(
                        p.activo === 1 ? 'Desactivar' : 'Activar',
                        'estado-producto',
                        p.id,
                        p.activo === 1 ? 0 : 1,
                        p.activo === 1 ? 'danger' : 'success'
                    );
                }

                acciones += botonAccion('Presentaciones', 'ver-presentaciones-producto', p.id);
                acciones += botonAccion('Precios', 'ver-precios-producto', p.id);

                if (puedeAdministrar && p.activo === 0) {
                }

                return '<tr>'
                    + '<td><strong>' + escapeHtml(p.sku) + '</strong></td>'
                    + '<td>' + escapeHtml(p.nombre) + '</td>'
                    + '<td>' + escapeHtml(tipoProductoTexto(p.tipo)) + '</td>'
                    + '<td>' + escapeHtml(p.categoria_nombre || 'Sin categoría') + '</td>'
                    + '<td>' + escapeHtml(p.unidad_nombre + ' (' + p.unidad_simbolo + ')') + '</td>'
                    + '<td>' + numero(p.presentaciones_activas) + '</td>'
                    + '<td>'
                    + (
                        Number(p.precios_vigentes || 0) > 0
                            ? '<strong>' + numero(p.precios_vigentes) + ' vigentes</strong>'
                                + '<small class="cell-secondary">Menudeo: '
                                + numero(p.precios_menudeo_vigentes || 0)
                                + ' · Mayoreo: ' + numero(p.precios_mayoreo_vigentes || 0) + '</small>'
                            : status('Sin precio', 'history')
                                + '<small class="cell-secondary">La cotización pedirá captura manual.</small>'
                    )
                    + '</td>'
                    + '<td>'
                    + status(p.activo === 1 ? 'Activo' : 'Inactivo', p.activo === 1 ? 'active' : 'inactive')
                    + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderKpisProductos(r) {
        $('kpiProductosTotal').textContent = r.total || 0;
        $('kpiMaterias').textContent = r.materias_primas || 0;
        $('kpiTerminados').textContent = r.productos_terminados || 0;
        $('kpiProductosActivos').textContent = r.activos || 0;
        $('kpiProductosInactivos').textContent = r.inactivos || 0;
        $('kpiProductosSinPrecio').textContent = r.sin_precio_vigente || 0;
    }

    function nuevoProducto() {
        $('formProducto').reset();
        $('productoId').value = '';
        $('productoCodigo').value = 'Se generará al guardar';
        $('productoControlaInventario').checked = true;
        $('productoPermiteFraccion').checked = true;
        $('tituloModalProducto').textContent = 'Nuevo producto';
        ocultarMensaje($('mensajeProducto'));
        abrirModal('modalProducto');
    }

    async function editarProducto(id) {
        const datos = await api(
            '?productos_api=1&accion=DETALLE_PRODUCTO&id=' + encodeURIComponent(id)
        );

        const p = datos.producto;

        $('formProducto').reset();
        $('productoId').value = p.id;
        $('productoCodigo').value = p.sku || '';
        $('productoNombre').value = p.nombre || '';
        $('productoTipo').value = p.tipo || '';
        $('productoCategoria').value = p.categoria_id || '';
        $('productoUnidadBase').value = p.unidad_base_id || '';
        $('productoImpuesto').value = p.tasa_impuesto_id || '';
        $('productoDescripcion').value = p.descripcion || '';
        $('productoControlaInventario').checked = p.controla_inventario === 1;
        $('productoPermiteFraccion').checked = p.permite_fraccion === 1;
        $('tituloModalProducto').textContent = 'Editar producto';

        ocultarMensaje($('mensajeProducto'));
        abrirModal('modalProducto');
    }

    async function cambiarEstadoProducto(id, activo) {
        const mensaje = activo === 1
            ? '¿Activar este producto?'
            : '¿Desactivar este producto? Sus presentaciones activas también quedarán desactivadas.';

        if (!window.confirm(mensaje)) {
            return;
        }

        await postSimple('CAMBIAR_ESTADO_PRODUCTO', {
            producto_id: id,
            activo: activo
        });

        await cargarProductos();
    }

    /* ==============================================================
       CATEGORÍAS
       ============================================================== */

    async function cargarCategorias() {
        const params = new URLSearchParams({
            productos_api: '1',
            accion: 'LISTAR_CATEGORIAS',
            pagina: String(estado.categoria.pagina),
            por_pagina: String(estado.categoria.porPagina),
            busqueda: $('buscarCategoria').value.trim()
        });

        $('tablaCategorias').innerHTML =
            '<tr><td colspan="5" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        renderCategorias(datos.categorias || []);
        renderPaginacion('categoria', datos.paginacion);
    }

    function renderCategorias(filas) {
        if (!filas.length) {
            $('tablaCategorias').innerHTML =
                '<tr><td colspan="5" class="empty-cell">No se encontraron categorías.</td></tr>';
            return;
        }

        $('tablaCategorias').innerHTML = filas.map(
            function (c) {
                let acciones = '';

                if (puedeAdministrar) {
                    acciones += botonAccion('Editar', 'editar-categoria', c.id);
                    acciones += botonAccion(
                        c.activo === 1 ? 'Desactivar' : 'Activar',
                        'estado-categoria',
                        c.id,
                        c.activo === 1 ? 0 : 1,
                        c.activo === 1 ? 'danger' : 'success'
                    );

                    if (c.activo === 0 && c.productos_asignados === 0) {
                    }
                }

                return '<tr>'
                    + '<td><strong>' + escapeHtml(c.nombre) + '</strong></td>'
                    + '<td>' + escapeHtml(c.descripcion || '—') + '</td>'
                    + '<td>' + numero(c.productos_asignados) + '</td>'
                    + '<td>'
                    + status(c.activo === 1 ? 'Activa' : 'Inactiva', c.activo === 1 ? 'active' : 'inactive')
                    + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function nuevaCategoria() {
        $('formCategoria').reset();
        $('categoriaId').value = '';
        $('tituloModalCategoria').textContent = 'Nueva categoría';
        ocultarMensaje($('mensajeCategoria'));
        abrirModal('modalCategoria');
    }

    async function editarCategoria(id) {
        const datos = await api(
            '?productos_api=1&accion=DETALLE_CATEGORIA&id=' + encodeURIComponent(id)
        );

        const c = datos.categoria;

        $('formCategoria').reset();
        $('categoriaId').value = c.id;
        $('categoriaNombre').value = c.nombre || '';
        $('categoriaDescripcion').value = c.descripcion || '';
        $('tituloModalCategoria').textContent = 'Editar categoría';
        ocultarMensaje($('mensajeCategoria'));
        abrirModal('modalCategoria');
    }

    async function cambiarEstadoCategoria(id, activo) {
        if (!window.confirm(activo === 1 ? '¿Activar esta categoría?' : '¿Desactivar esta categoría?')) {
            return;
        }

        await postSimple('CAMBIAR_ESTADO_CATEGORIA', {
            categoria_id: id,
            activo: activo
        });

        await cargarCatalogos();
        await cargarCategorias();
    }

    /* ==============================================================
       UNIDADES
       ============================================================== */

    async function cargarUnidades() {
        const params = new URLSearchParams({
            productos_api: '1',
            accion: 'LISTAR_UNIDADES',
            pagina: String(estado.unidad.pagina),
            por_pagina: String(estado.unidad.porPagina),
            busqueda: $('buscarUnidad').value.trim()
        });

        $('tablaUnidades').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        renderUnidades(datos.unidades || []);
        renderPaginacion('unidad', datos.paginacion);
    }

    function renderUnidades(filas) {
        if (!filas.length) {
            $('tablaUnidades').innerHTML =
                '<tr><td colspan="8" class="empty-cell">No se encontraron unidades.</td></tr>';
            return;
        }

        $('tablaUnidades').innerHTML = filas.map(
            function (u) {
                let acciones = '';

                if (puedeAdministrar) {
                    acciones += botonAccion('Editar', 'editar-unidad', u.id);
                    acciones += botonAccion(
                        u.activo === 1 ? 'Desactivar' : 'Activar',
                        'estado-unidad',
                        u.id,
                        u.activo === 1 ? 0 : 1,
                        u.activo === 1 ? 'danger' : 'success'
                    );
                }

                return '<tr>'
                    + '<td><strong>' + escapeHtml(u.codigo) + '</strong></td>'
                    + '<td>' + escapeHtml(u.nombre) + '</td>'
                    + '<td>' + escapeHtml(u.simbolo) + '</td>'
                    + '<td>' + escapeHtml(tipoUnidadTexto(u.tipo)) + '</td>'
                    + '<td>' + numero(u.productos_base) + '</td>'
                    + '<td>' + numero(u.presentaciones) + '</td>'
                    + '<td>'
                    + status(u.activo === 1 ? 'Activa' : 'Inactiva', u.activo === 1 ? 'active' : 'inactive')
                    + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function nuevaUnidad() {
        $('formUnidad').reset();
        $('unidadId').value = '';
        $('unidadTipo').value = 'UNIDAD';
        $('tituloModalUnidad').textContent = 'Nueva unidad';
        ocultarMensaje($('mensajeUnidad'));
        abrirModal('modalUnidad');
    }

    async function editarUnidad(id) {
        const datos = await api(
            '?productos_api=1&accion=DETALLE_UNIDAD&id=' + encodeURIComponent(id)
        );

        const u = datos.unidad;

        $('formUnidad').reset();
        $('unidadId').value = u.id;
        $('unidadCodigo').value = u.codigo || '';
        $('unidadNombre').value = u.nombre || '';
        $('unidadSimbolo').value = u.simbolo || '';
        $('unidadTipo').value = u.tipo || 'UNIDAD';
        $('tituloModalUnidad').textContent = 'Editar unidad';
        ocultarMensaje($('mensajeUnidad'));
        abrirModal('modalUnidad');
    }

    async function cambiarEstadoUnidad(id, activo) {
        const mensaje = activo === 1
            ? '¿Activar esta unidad?'
            : '¿Desactivar esta unidad? Solo se permitirá si no está siendo utilizada activamente.';

        if (!window.confirm(mensaje)) {
            return;
        }

        await postSimple('CAMBIAR_ESTADO_UNIDAD', {
            unidad_id: id,
            activo: activo
        });

        await cargarCatalogos();
        await cargarUnidades();
    }

    /* ==============================================================
       PRESENTACIONES
       ============================================================== */

    async function cargarPresentaciones() {
        const params = new URLSearchParams({
            productos_api: '1',
            accion: 'LISTAR_PRESENTACIONES',
            pagina: String(estado.presentacion.pagina),
            por_pagina: String(estado.presentacion.porPagina),
            busqueda: $('buscarPresentacion').value.trim()
        });

        $('tablaPresentaciones').innerHTML =
            '<tr><td colspan="7" class="empty-cell">Cargando...</td></tr>';

        const datos = await api('?' + params.toString());
        renderPresentaciones(datos.presentaciones || []);
        renderPaginacion('presentacion', datos.paginacion);
    }

    function renderPresentaciones(filas) {
        if (!filas.length) {
            $('tablaPresentaciones').innerHTML =
                '<tr><td colspan="7" class="empty-cell">No se encontraron presentaciones.</td></tr>';
            return;
        }

        $('tablaPresentaciones').innerHTML = filas.map(
            function (p) {
                let acciones = '';

                if (puedeAdministrar) {
                    acciones += botonAccion('Editar', 'editar-presentacion', p.id);
                    acciones += botonAccion(
                        p.activo === 1 ? 'Desactivar' : 'Activar',
                        'estado-presentacion',
                        p.id,
                        p.activo === 1 ? 0 : 1,
                        p.activo === 1 ? 'danger' : 'success'
                    );
                }

                const usos = [];
                if (p.es_compra === 1) usos.push('Compra');
                if (p.es_venta === 1) usos.push('Venta');

                return '<tr>'
                    + '<td><strong>' + escapeHtml(p.producto_nombre) + '</strong>'
                    + '<small class="cell-secondary">' + escapeHtml(p.sku) + '</small></td>'
                    + '<td>' + escapeHtml(p.nombre) + '</td>'
                    + '<td>' + escapeHtml(p.unidad_nombre + ' (' + p.unidad_simbolo + ')') + '</td>'
                    + '<td>1 ' + escapeHtml(p.nombre)
                    + ' = ' + numero(p.factor_a_unidad_base, 6)
                    + ' ' + escapeHtml(p.unidad_base_simbolo) + '</td>'
                    + '<td>' + escapeHtml(usos.join(' / ')) + '</td>'
                    + '<td>'
                    + status(p.activo === 1 ? 'Activa' : 'Inactiva', p.activo === 1 ? 'active' : 'inactive')
                    + '</td>'
                    + '<td class="text-right actions-cell">' + acciones + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function limpiarProductoPresentacion() {
        estado.productoPresentacion = null;
        $('presentacionProductoId').value = '';
        $('presentacionProductoBuscar').value = '';
        $('presentacionProductoBuscar').readOnly = false;
        $('productoSeleccionadoTexto').textContent = 'No hay producto seleccionado.';
        $('ayudaConversion').textContent = 'Selecciona un producto para conocer su unidad base.';
        cerrarResultadosProducto();
    }

    function nuevaPresentacion(producto) {
        $('formPresentacion').reset();
        $('presentacionId').value = '';
        $('presentacionCompra').checked = true;
        $('presentacionVenta').checked = true;
        $('tituloModalPresentacion').textContent = 'Nueva presentación';
        estado.editandoPresentacion = false;

        limpiarProductoPresentacion();
        ocultarMensaje($('mensajePresentacion'));

        if (producto) {
            seleccionarProductoPresentacion(producto, true);
        }

        abrirModal('modalPresentacion');
    }

    async function editarPresentacion(id) {
        const datos = await api(
            '?productos_api=1&accion=DETALLE_PRESENTACION&id=' + encodeURIComponent(id)
        );

        const p = datos.presentacion;

        $('formPresentacion').reset();
        $('presentacionId').value = p.id;
        $('presentacionNombre').value = p.nombre || '';
        $('presentacionUnidad').value = p.unidad_id;
        $('presentacionFactor').value = p.factor_a_unidad_base;
        $('presentacionCompra').checked = p.es_compra === 1;
        $('presentacionVenta').checked = p.es_venta === 1;
        $('tituloModalPresentacion').textContent = 'Editar presentación';
        estado.editandoPresentacion = true;

        seleccionarProductoPresentacion(
            {
                id: p.producto_id,
                sku: p.sku,
                nombre: p.producto_nombre,
                unidad_base_id: p.unidad_base_id,
                unidad_base_codigo: p.unidad_base_codigo,
                unidad_base_nombre: p.unidad_base_nombre,
                unidad_base_simbolo: p.unidad_base_simbolo
            },
            false
        );

        $('presentacionProductoBuscar').readOnly = true;
        $('presentacionFactor').value = p.factor_a_unidad_base;
        ocultarMensaje($('mensajePresentacion'));
        abrirModal('modalPresentacion');
    }

    async function cambiarEstadoPresentacion(id, activo) {
        if (!window.confirm(activo === 1 ? '¿Activar esta presentación?' : '¿Desactivar esta presentación?')) {
            return;
        }

        await postSimple('CAMBIAR_ESTADO_PRESENTACION', {
            presentacion_id: id,
            activo: activo
        });

        await cargarPresentaciones();
    }

    async function buscarProductosPresentacion(texto) {
        const q = texto.trim();

        if (q.length < 1) {
            cerrarResultadosProducto();
            return;
        }

        const datos = await api(
            '?productos_api=1&accion=BUSCAR_PRODUCTOS&q=' + encodeURIComponent(q)
        );

        renderResultadosProducto(datos.productos || []);
    }

    function renderResultadosProducto(productos) {
        const contenedor = $('resultadosProductoPresentacion');

        if (!productos.length) {
            contenedor.innerHTML = '<div class="autocomplete-empty">Sin coincidencias.</div>';
            contenedor.hidden = false;
            return;
        }

        contenedor.innerHTML = productos.map(
            function (p) {
                return '<button type="button" class="autocomplete-option" '
                    + 'data-producto-id="' + p.id + '">'
                    + '<strong>' + escapeHtml(p.nombre) + '</strong>'
                    + '<span>' + escapeHtml(p.sku)
                    + ' · Base: ' + escapeHtml(p.unidad_base_nombre + ' (' + p.unidad_base_simbolo + ')')
                    + '</span>'
                    + '</button>';
            }
        ).join('');

        productos.forEach(
            function (p) {
                const boton = contenedor.querySelector('[data-producto-id="' + p.id + '"]');

                if (boton) {
                    boton.addEventListener(
                        'click',
                        function () {
                            seleccionarProductoPresentacion(p, true);
                        }
                    );
                }
            }
        );

        contenedor.hidden = false;
    }

    function cerrarResultadosProducto() {
        $('resultadosProductoPresentacion').hidden = true;
        $('resultadosProductoPresentacion').innerHTML = '';
    }

    function seleccionarProductoPresentacion(producto, sugerir) {
        estado.productoPresentacion = producto;
        $('presentacionProductoId').value = producto.id;
        $('presentacionProductoBuscar').value = producto.sku + ' · ' + producto.nombre;
        $('productoSeleccionadoTexto').textContent =
            'Seleccionado: ' + producto.nombre
            + ' · unidad base: '
            + producto.unidad_base_nombre
            + ' (' + producto.unidad_base_simbolo + ')';

        cerrarResultadosProducto();
        actualizarAyudaConversion();

        if (sugerir) {
            sugerirFactorConversion();
        }
    }

    function actualizarAyudaConversion() {
        const producto = estado.productoPresentacion;
        const unidadId = Number($('presentacionUnidad').value || 0);
        const unidad = estado.unidades.find(u => u.id === unidadId);

        if (!producto) {
            $('ayudaConversion').textContent =
                'Selecciona un producto para conocer su unidad base.';
            return;
        }

        if (!unidad) {
            $('ayudaConversion').textContent =
                'La unidad base de ' + producto.nombre + ' es '
                + producto.unidad_base_nombre + ' (' + producto.unidad_base_simbolo + '). '
                + 'Ahora selecciona la unidad de la presentación.';
            return;
        }

        const sugerido = factorEstandar(
            unidad.codigo,
            producto.unidad_base_codigo
        );

        if (sugerido !== null) {
            $('ayudaConversion').textContent =
                'Conversión conocida: 1 ' + unidad.nombre
                + ' = ' + numero(sugerido, 6)
                + ' ' + producto.unidad_base_simbolo
                + '. El sistema puede llenar el factor automáticamente.';
        } else {
            $('ayudaConversion').textContent =
                'Unidad base: ' + producto.unidad_base_nombre
                + ' (' + producto.unidad_base_simbolo + '). '
                + 'Captura cuánto equivale 1 ' + unidad.nombre
                + ' en la unidad base. Ejemplo: un bulto de 20 kg → factor 20.';
        }
    }

    function factorEstandar(unidadPresentacion, unidadBase) {
        const mapa = {
            'TON>KG': 1000,
            'KG>TON': 0.001,
            'KG>G': 1000,
            'G>KG': 0.001,
            'L>ML': 1000,
            'ML>L': 0.001
        };

        if (unidadPresentacion === unidadBase) {
            return 1;
        }

        const clave = unidadPresentacion + '>' + unidadBase;
        return Object.prototype.hasOwnProperty.call(mapa, clave)
            ? mapa[clave]
            : null;
    }

    function sugerirFactorConversion() {
        const producto = estado.productoPresentacion;
        const unidadId = Number($('presentacionUnidad').value || 0);
        const unidad = estado.unidades.find(u => u.id === unidadId);

        if (!producto || !unidad) {
            actualizarAyudaConversion();
            return;
        }

        const factor = factorEstandar(
            unidad.codigo,
            producto.unidad_base_codigo
        );

        if (factor !== null) {
            $('presentacionFactor').value = factor;
        } else if (!estado.editandoPresentacion) {
            $('presentacionFactor').value = '';
        }

        actualizarAyudaConversion();
    }

    /* ==============================================================
       EVENTOS
       ============================================================== */

    document.querySelectorAll('.catalogo-tab').forEach(
        tab => tab.addEventListener('click', () => cambiarSeccion(tab.dataset.seccion))
    );

    document.querySelectorAll('[data-cerrar-modal]').forEach(
        boton => boton.addEventListener('click', () => cerrarModal(boton.dataset.cerrarModal))
    );

    document.querySelectorAll('.modal-backdrop').forEach(
        modal => modal.addEventListener(
            'click',
            event => {
                if (event.target === modal) {
                    cerrarModal(modal.id);
                }
            }
        )
    );

    document.addEventListener(
        'keydown',
        event => {
            if (event.key !== 'Escape') return;

            document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(
                modal => cerrarModal(modal.id)
            );
        }
    );

    $('btnNuevoProducto')?.addEventListener('click', nuevoProducto);
    $('btnNuevaCategoria')?.addEventListener('click', nuevaCategoria);
    $('btnNuevaUnidad')?.addEventListener('click', nuevaUnidad);
    $('btnNuevaPresentacion')?.addEventListener('click', () => nuevaPresentacion());

    $('formProducto').addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            enviarFormulario(
                event.currentTarget,
                $('mensajeProducto'),
                async function (datos) {
                    cerrarModal('modalProducto');
                    mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
                    await cargarCatalogos();
                    await cargarProductos();
                }
            );
        }
    );

    $('formCategoria').addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            enviarFormulario(
                event.currentTarget,
                $('mensajeCategoria'),
                async function (datos) {
                    cerrarModal('modalCategoria');
                    mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
                    await cargarCatalogos();
                    await cargarCategorias();
                }
            );
        }
    );

    $('formUnidad').addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            enviarFormulario(
                event.currentTarget,
                $('mensajeUnidad'),
                async function (datos) {
                    cerrarModal('modalUnidad');
                    mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
                    await cargarCatalogos();
                    await cargarUnidades();
                }
            );
        }
    );

    $('formPresentacion').addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            if (!$('presentacionProductoId').value) {
                mostrarMensaje(
                    $('mensajePresentacion'),
                    'Selecciona un producto de los resultados de búsqueda.',
                    'error'
                );
                return;
            }

            enviarFormulario(
                event.currentTarget,
                $('mensajePresentacion'),
                async function (datos) {
                    cerrarModal('modalPresentacion');
                    mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
                    await cargarPresentaciones();

                    if (estado.seccion === 'productos') {
                        await cargarProductos();
                    }
                }
            );
        }
    );


    $('formPrecioVenta').addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            if (!$('precioProductoId').value) {
                mostrarMensaje(
                    $('mensajePrecioVenta'),
                    'Selecciona un producto de los resultados de búsqueda.',
                    'error'
                );
                return;
            }

            if ($('precioPresentacion').disabled) {
                mostrarMensaje(
                    $('mensajePrecioVenta'),
                    'Selecciona cómo se venderá el producto.',
                    'error'
                );
                return;
            }

            enviarFormulario(
                event.currentTarget,
                $('mensajePrecioVenta'),
                async function (datos) {
                    cerrarModal('modalPrecioVenta');
                    mostrarMensaje($('mensajePagina'), datos.mensaje, 'success');
                    await cargarPreciosVenta();

                    if (estado.productos.length) {
                        await cargarProductos();
                    }
                }
            );
        }
    );

    $('tablaProductos').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-action]');
            if (!boton) return;

            const id = Number(boton.dataset.id);
            const producto = estado.productos.find(p => p.id === id);

            if (boton.dataset.action === 'editar-producto') {
                editarProducto(id).catch(mostrarError);
            } else if (boton.dataset.action === 'estado-producto') {
                cambiarEstadoProducto(id, Number(boton.dataset.value)).catch(mostrarError);
            } else if (boton.dataset.action === 'ver-presentaciones-producto') {
                if (producto) {
                    $('buscarPresentacion').value = producto.sku;
                    estado.presentacion.pagina = 1;
                    cambiarSeccion('presentaciones');
                }
            } else if (boton.dataset.action === 'ver-precios-producto') {
                if (producto) {
                    $('buscarPrecioVenta').value = producto.sku;
                    estado.precio.pagina = 1;
                    cambiarSeccion('precios');
                }
            }
        }
    );

    $('tablaCategorias').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-action]');
            if (!boton) return;

            const id = Number(boton.dataset.id);

            if (boton.dataset.action === 'editar-categoria') {
                editarCategoria(id).catch(mostrarError);
            } else if (boton.dataset.action === 'estado-categoria') {
                cambiarEstadoCategoria(id, Number(boton.dataset.value)).catch(mostrarError);
            }
        }
    );

    $('tablaUnidades').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-action]');
            if (!boton) return;

            const id = Number(boton.dataset.id);

            if (boton.dataset.action === 'editar-unidad') {
                editarUnidad(id).catch(mostrarError);
            } else if (boton.dataset.action === 'estado-unidad') {
                cambiarEstadoUnidad(id, Number(boton.dataset.value)).catch(mostrarError);
            }
        }
    );

    $('tablaPresentaciones').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-action]');
            if (!boton) return;

            const id = Number(boton.dataset.id);

            if (boton.dataset.action === 'editar-presentacion') {
                editarPresentacion(id).catch(mostrarError);
            } else if (boton.dataset.action === 'estado-presentacion') {
                cambiarEstadoPresentacion(id, Number(boton.dataset.value)).catch(mostrarError);
            }
        }
    );


    $('tablaPreciosVenta').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-action]');
            if (!boton) return;

            const id = Number(boton.dataset.id);

            if (boton.dataset.action === 'actualizar-precio') {
                actualizarPrecioVenta(id).catch(mostrarError);
            } else if (boton.dataset.action === 'desactivar-precio') {
                desactivarPrecioVenta(id).catch(mostrarError);
            }
        }
    );

    $('btnNuevoPrecioVenta')?.addEventListener(
        'click',
        function () {
            nuevoPrecioVenta(null);
        }
    );

    $('buscarProducto').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerProducto);
            estado.timerProducto = setTimeout(
                function () {
                    estado.producto.pagina = 1;
                    cargarProductos().catch(mostrarError);
                },
                350
            );
        }
    );

    ['filtroTipoProducto', 'filtroCategoriaProducto', 'filtroEstadoProducto'].forEach(
        id => $(id).addEventListener(
            'change',
            function () {
                estado.producto.pagina = 1;
                cargarProductos().catch(mostrarError);
            }
        )
    );

    $('porPaginaProducto').addEventListener(
        'change',
        function (event) {
            estado.producto.porPagina = Number(event.target.value);
            estado.producto.pagina = 1;
            cargarProductos().catch(mostrarError);
        }
    );

    $('buscarCategoria').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerCategoria);
            estado.timerCategoria = setTimeout(
                function () {
                    estado.categoria.pagina = 1;
                    cargarCategorias().catch(mostrarError);
                },
                300
            );
        }
    );

    $('porPaginaCategoria').addEventListener(
        'change',
        function (event) {
            estado.categoria.porPagina = Number(event.target.value);
            estado.categoria.pagina = 1;
            cargarCategorias().catch(mostrarError);
        }
    );

    $('buscarUnidad').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerUnidad);
            estado.timerUnidad = setTimeout(
                function () {
                    estado.unidad.pagina = 1;
                    cargarUnidades().catch(mostrarError);
                },
                300
            );
        }
    );

    $('porPaginaUnidad').addEventListener(
        'change',
        function (event) {
            estado.unidad.porPagina = Number(event.target.value);
            estado.unidad.pagina = 1;
            cargarUnidades().catch(mostrarError);
        }
    );

    $('buscarPresentacion').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerPresentacion);
            estado.timerPresentacion = setTimeout(
                function () {
                    estado.presentacion.pagina = 1;
                    cargarPresentaciones().catch(mostrarError);
                },
                300
            );
        }
    );

    $('porPaginaPresentacion').addEventListener(
        'change',
        function (event) {
            estado.presentacion.porPagina = Number(event.target.value);
            estado.presentacion.pagina = 1;
            cargarPresentaciones().catch(mostrarError);
        }
    );


    $('buscarPrecioVenta').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerPrecio);
            estado.timerPrecio = setTimeout(
                function () {
                    estado.precio.pagina = 1;
                    cargarPreciosVenta().catch(mostrarError);
                },
                300
            );
        }
    );

    ['filtroNivelPrecio', 'filtroEstadoPrecio', 'filtroMonedaPrecio'].forEach(
        id => $(id).addEventListener(
            'change',
            function () {
                estado.precio.pagina = 1;
                cargarPreciosVenta().catch(mostrarError);
            }
        )
    );

    $('porPaginaPrecio').addEventListener(
        'change',
        function (event) {
            estado.precio.porPagina = Number(event.target.value);
            estado.precio.pagina = 1;
            cargarPreciosVenta().catch(mostrarError);
        }
    );

    function enlazarPaginacion(tipo, anteriorId, siguienteId, cargar) {
        $(anteriorId).addEventListener(
            'click',
            function () {
                if (estado[tipo].pagina <= 1) return;
                estado[tipo].pagina--;
                cargar().catch(mostrarError);
            }
        );

        $(siguienteId).addEventListener(
            'click',
            function () {
                if (estado[tipo].pagina >= estado[tipo].totalPaginas) return;
                estado[tipo].pagina++;
                cargar().catch(mostrarError);
            }
        );
    }

    enlazarPaginacion('producto', 'btnProductoAnterior', 'btnProductoSiguiente', cargarProductos);
    enlazarPaginacion('categoria', 'btnCategoriaAnterior', 'btnCategoriaSiguiente', cargarCategorias);
    enlazarPaginacion('unidad', 'btnUnidadAnterior', 'btnUnidadSiguiente', cargarUnidades);
    enlazarPaginacion('presentacion', 'btnPresentacionAnterior', 'btnPresentacionSiguiente', cargarPresentaciones);
    enlazarPaginacion('precio', 'btnPrecioAnterior', 'btnPrecioSiguiente', cargarPreciosVenta);


    $('precioProductoBuscar').addEventListener(
        'input',
        function (event) {
            if ($('precioProductoBuscar').readOnly) {
                return;
            }

            estado.productoPrecio = null;
            $('precioProductoId').value = '';
            $('precioProductoSeleccionado').textContent = 'Selecciona un resultado de la búsqueda.';
            $('precioPresentacion').disabled = true;

            clearTimeout(estado.timerAutocompletePrecio);
            estado.timerAutocompletePrecio = setTimeout(
                function () {
                    buscarProductosPrecio(event.target.value).catch(
                        error => mostrarMensaje($('mensajePrecioVenta'), error.message, 'error')
                    );
                },
                250
            );
        }
    );

    $('resultadosProductoPrecio').addEventListener(
        'click',
        function (event) {
            const boton = event.target.closest('[data-precio-producto-id]');
            if (!boton) return;

            const id = Number(boton.dataset.precioProductoId);
            const producto = estado.resultadosPrecioProducto.find(p => p.id === id);

            if (!producto) return;

            seleccionarProductoPrecio(producto, false).catch(
                error => mostrarMensaje($('mensajePrecioVenta'), error.message, 'error')
            );
        }
    );

    $('precioNivel').addEventListener(
        'change',
        function () {
            aplicarReglaNivelPrecio();
            actualizarResumenReglaPrecio();
        }
    );

    ['precioPresentacion', 'precioCantidadMinima', 'precioMoneda', 'precioImporte'].forEach(
        id => $(id).addEventListener('input', actualizarResumenReglaPrecio)
    );

    $('presentacionProductoBuscar').addEventListener(
        'input',
        function (event) {
            if (estado.editandoPresentacion) {
                return;
            }

            estado.productoPresentacion = null;
            $('presentacionProductoId').value = '';
            $('productoSeleccionadoTexto').textContent = 'Selecciona un resultado de la búsqueda.';

            clearTimeout(estado.timerAutocomplete);
            estado.timerAutocomplete = setTimeout(
                function () {
                    buscarProductosPresentacion(event.target.value).catch(
                        function (error) {
                            mostrarMensaje($('mensajePresentacion'), error.message, 'error');
                        }
                    );
                },
                250
            );
        }
    );

    $('presentacionUnidad').addEventListener('change', sugerirFactorConversion);

    document.addEventListener(
        'click',
        function (event) {
            if (!event.target.closest('.autocomplete-field')) {
                cerrarResultadosProducto();
                cerrarResultadosProductoPrecio();
            }
        }
    );

    async function iniciar() {
        try {
            await cargarCatalogos();
            cambiarSeccion(seccionInicial);
        } catch (error) {
            mostrarError(error);
        }
    }

    iniciar();
})();
</script>

</body>
</html>
