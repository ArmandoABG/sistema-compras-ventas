<?php

declare(strict_types=1);

if (isset($_GET['proveedores_api'])) {
    $endpoint = __DIR__ . '/../funciones/proveedores_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'mensaje' => 'No se encontró funciones/proveedores_funciones.php.',
        ]);
        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso('proveedores.ver', false);

$tituloPagina = 'Proveedores';
$csrfToken = si_token_csrf();

$puedeAdministrar = si_tiene_permiso('proveedores.administrar');
$puedeComparar = si_tiene_permiso('proveedores.comparar_precios');

$cssGlobal = __DIR__ . '/../css/style_global.css';
$cssModulo = __DIR__ . '/../css/style_proveedores.css';

$versionGlobal = is_file($cssGlobal) ? (string) filemtime($cssGlobal) : '1';
$versionModulo = is_file($cssModulo) ? (string) filemtime($cssModulo) : '1';

$seccionesPermitidas = ['directorio', 'productos'];

if ($puedeComparar) {
    $seccionesPermitidas[] = 'comparador';
}

$seccionInicial = strtolower(trim((string) ($_GET['seccion'] ?? 'directorio')));

if (!in_array($seccionInicial, $seccionesPermitidas, true)) {
    $seccionInicial = 'directorio';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <title>Proveedores | Sistema Integral</title>

    <link
        rel="stylesheet"
        href="../css/style_global.css?v=<?= si_escapar($versionGlobal) ?>"
    >

    <link
        rel="stylesheet"
        href="../css/style_proveedores.css?v=<?= si_escapar($versionModulo) ?>"
    >
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">
        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content proveedores-page">

            <header class="proveedores-heading">
                <div>
                    <p class="eyebrow">COMPRAS Y ABASTECIMIENTO</p>
                    <h1>Proveedores y análisis de costos</h1>
                    <p>
                        Directorio, materias primas suministradas, historial de precios
                        y comparación normalizada por unidad base.
                    </p>
                </div>
            </header>

            <nav class="module-tabs" aria-label="Secciones de proveedores">
                <button
                    type="button"
                    class="module-tab"
                    data-seccion="directorio"
                >
                    Directorio
                </button>

                <button
                    type="button"
                    class="module-tab"
                    data-seccion="productos"
                >
                    Productos y precios
                </button>

                <?php if ($puedeComparar): ?>
                    <button
                        type="button"
                        class="module-tab"
                        data-seccion="comparador"
                    >
                        Comparador de costos
                    </button>
                <?php endif; ?>
            </nav>

            <div
                id="mensajePagina"
                class="module-message"
                hidden
            ></div>

            <!-- =====================================================
                 DIRECTORIO
                 ===================================================== -->
            <section id="seccionDirectorio" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Directorio de proveedores</h2>
                        <p>
                            El código interno se genera solo. Captura únicamente
                            información real del proveedor.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button
                            type="button"
                            class="btn-primary"
                            id="btnNuevoProveedor"
                        >
                            Nuevo proveedor
                        </button>
                    <?php endif; ?>
                </div>

                <section class="stats-grid">
                    <article>
                        <span>Total</span>
                        <strong id="kpiTotal">0</strong>
                    </article>

                    <article>
                        <span>Activos</span>
                        <strong id="kpiActivos">0</strong>
                    </article>

                    <article>
                        <span>Con crédito</span>
                        <strong id="kpiCredito">0</strong>
                    </article>

                    <article>
                        <span>Inactivos</span>
                        <strong id="kpiInactivos">0</strong>
                    </article>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--directory">
                        <label class="field field--search">
                            <span>Buscar</span>

                            <input
                                type="search"
                                id="buscarProveedor"
                                maxlength="180"
                                placeholder="Código, razón social, RFC o contacto"
                                autocomplete="off"
                            >
                        </label>

                        <label class="field">
                            <span>Moneda</span>

                            <select id="filtroMonedaProveedor">
                                <option value="0">Todas</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Estado</span>

                            <select id="filtroEstadoProveedor">
                                <option value="TODOS">Todos</option>
                                <option value="ACTIVOS">Activos</option>
                                <option value="INACTIVOS">Inactivos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>

                            <select id="porPaginaProveedor">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--providers">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Proveedor</th>
                                    <th>RFC</th>
                                    <th>Contacto</th>
                                    <th>Condición</th>
                                    <th>Productos</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>

                            <tbody id="tablaProveedores">
                                <tr>
                                    <td colspan="8" class="empty-cell">Cargando...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaProveedor">0 registros</span>

                        <div>
                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnProveedorAnterior"
                            >
                                Anterior
                            </button>

                            <span id="paginaProveedorActual">Página 1 de 1</span>

                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnProveedorSiguiente"
                            >
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 PRODUCTOS Y PRECIOS
                 ===================================================== -->
            <section id="seccionProductos" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Productos y precios del proveedor</h2>
                        <p>
                            Relaciona materias primas y registra precios sin alterar
                            los precios históricos.
                        </p>
                    </div>

                    <?php if ($puedeAdministrar): ?>
                        <button
                            type="button"
                            class="btn-primary"
                            id="btnNuevaRelacion"
                            disabled
                        >
                            Agregar materia prima
                        </button>
                    <?php endif; ?>
                </div>

                <section class="selector-card">
                    <label class="field">
                        <span>Proveedor</span>

                        <div class="smart-search">
                            <input
                                type="search"
                                id="buscarProveedorProductos"
                                maxlength="180"
                                placeholder="Escribe código o nombre del proveedor"
                                autocomplete="off"
                            >

                            <div
                                id="resultadosProveedorProductos"
                                class="smart-results"
                                hidden
                            ></div>
                        </div>
                    </label>

                    <div id="proveedorProductosSeleccionado" class="selected-summary" hidden></div>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--relations">
                        <label class="field field--search">
                            <span>Buscar en sus productos</span>

                            <input
                                type="search"
                                id="buscarRelacion"
                                maxlength="160"
                                placeholder="Código, producto o presentación"
                                disabled
                            >
                        </label>

                        <label class="field">
                            <span>Estado</span>

                            <select id="filtroEstadoRelacion" disabled>
                                <option value="TODOS">Todos</option>
                                <option value="ACTIVOS">Activos</option>
                                <option value="INACTIVOS">Inactivos</option>
                            </select>
                        </label>

                        <label class="field">
                            <span>Por página</span>

                            <select id="porPaginaRelacion" disabled>
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--relations">
                            <thead>
                                <tr>
                                    <th>Materia prima</th>
                                    <th>Presentación de compra</th>
                                    <th>Entrega</th>
                                    <th>Compra mínima</th>
                                    <th>Último precio</th>
                                    <th>Costo normalizado</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>

                            <tbody id="tablaRelaciones">
                                <tr>
                                    <td colspan="8" class="empty-cell">
                                        Selecciona un proveedor.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaRelacion">0 registros</span>

                        <div>
                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnRelacionAnterior"
                                disabled
                            >
                                Anterior
                            </button>

                            <span id="paginaRelacionActual">Página 1 de 1</span>

                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnRelacionSiguiente"
                                disabled
                            >
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>

            <!-- =====================================================
                 COMPARADOR
                 ===================================================== -->
            <?php if ($puedeComparar): ?>
            <section id="seccionComparador" class="module-section" hidden>
                <div class="section-actions">
                    <div>
                        <h2>Comparador inteligente de costos</h2>
                        <p>
                            Compara proveedores por costo equivalente en la unidad base
                            del producto, aunque coticen por bulto, saco o tonelada.
                        </p>
                    </div>
                </div>

                <section class="selector-card">
                    <label class="field">
                        <span>Materia prima a comparar</span>

                        <div class="smart-search">
                            <input
                                type="search"
                                id="buscarProductoComparador"
                                maxlength="180"
                                placeholder="Escribe código o nombre: Maíz, Sorgo, Pasta de Soya..."
                                autocomplete="off"
                            >

                            <div
                                id="resultadosProductoComparador"
                                class="smart-results"
                                hidden
                            ></div>
                        </div>
                    </label>

                    <div id="productoComparadorSeleccionado" class="selected-summary" hidden></div>
                </section>

                <section class="module-card">
                    <div class="filters-grid filters-grid--compare">
                        <label class="check-inline">
                            <input
                                type="checkbox"
                                id="soloVigentesComparador"
                                checked
                            >
                            <span>Mostrar solo precios vigentes</span>
                        </label>

                        <label class="field">
                            <span>Por página</span>

                            <select id="porPaginaComparador">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>

                    <div class="table-wrap">
                        <table class="module-table module-table--compare">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Proveedor</th>
                                    <th>Precio cotizado</th>
                                    <th>Unidad</th>
                                    <th>Costo comparable</th>
                                    <th>Entrega</th>
                                    <th>Crédito</th>
                                    <th>Vigencia</th>
                                </tr>
                            </thead>

                            <tbody id="tablaComparador">
                                <tr>
                                    <td colspan="8" class="empty-cell">
                                        Selecciona una materia prima para comparar.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="pagination">
                        <span id="textoPaginaComparador">0 opciones</span>

                        <div>
                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnComparadorAnterior"
                                disabled
                            >
                                Anterior
                            </button>

                            <span id="paginaComparadorActual">Página 1 de 1</span>

                            <button
                                type="button"
                                class="btn-secondary"
                                id="btnComparadorSiguiente"
                                disabled
                            >
                                Siguiente
                            </button>
                        </div>
                    </footer>
                </section>
            </section>
            <?php endif; ?>

        </main>
    </div>
</div>

<!-- ================================================================
     MODAL PROVEEDOR
     ================================================================ -->
<div class="modal-backdrop" id="modalProveedor" hidden>
    <section class="modal-card modal-card--large" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>DIRECTORIO DE PROVEEDORES</small>
                <h2 id="tituloModalProveedor">Nuevo proveedor</h2>
            </div>

            <button
                type="button"
                class="modal-close"
                data-cerrar-modal="modalProveedor"
            >
                ×
            </button>
        </header>

        <form id="formProveedor">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_PROVEEDOR">
            <input type="hidden" name="proveedor_id" id="proveedorId">

            <div id="mensajeProveedor" class="module-message module-message--error" hidden></div>

            <div class="form-grid">
                <label class="field">
                    <span>Código interno</span>

                    <input
                        type="text"
                        id="proveedorCodigo"
                        placeholder="Se genera automáticamente"
                        readonly
                    >
                </label>

                <label class="field">
                    <span>RFC</span>

                    <input
                        type="text"
                        name="rfc"
                        id="proveedorRfc"
                        maxlength="20"
                        placeholder="Opcional"
                        autocomplete="off"
                    >
                </label>

                <label class="field field--span-2">
                    <span>Razón social *</span>

                    <input
                        type="text"
                        name="razon_social"
                        id="proveedorRazonSocial"
                        maxlength="180"
                        required
                    >
                </label>

                <label class="field field--span-2">
                    <span>Nombre comercial</span>

                    <input
                        type="text"
                        name="nombre_comercial"
                        id="proveedorNombreComercial"
                        maxlength="180"
                    >
                </label>

                <label class="field">
                    <span>Contacto</span>

                    <input
                        type="text"
                        name="contacto_nombre"
                        id="proveedorContacto"
                        maxlength="160"
                    >
                </label>

                <label class="field">
                    <span>Teléfono</span>

                    <input
                        type="tel"
                        name="telefono"
                        id="proveedorTelefono"
                        maxlength="40"
                    >
                </label>

                <label class="field field--span-2">
                    <span>Correo</span>

                    <input
                        type="email"
                        name="correo"
                        id="proveedorCorreo"
                        maxlength="180"
                    >
                </label>
            </div>

            <fieldset class="form-fieldset">
                <legend>Condiciones comerciales</legend>

                <div class="form-grid">
                    <label class="field">
                        <span>Moneda habitual *</span>

                        <select
                            name="moneda_default_id"
                            id="proveedorMoneda"
                            required
                        ></select>
                    </label>

                    <label class="field">
                        <span>Días de crédito</span>

                        <input
                            type="number"
                            name="dias_credito"
                            id="proveedorDiasCredito"
                            min="0"
                            max="3650"
                            step="1"
                            value="0"
                        >

                        <small>0 = proveedor de contado.</small>
                    </label>

                    <label class="field field--span-2">
                        <span>Límite de crédito</span>

                        <input
                            type="number"
                            name="limite_credito"
                            id="proveedorLimiteCredito"
                            min="0"
                            step="0.01"
                            disabled
                        >

                        <small id="ayudaLimiteCredito">
                            Se habilita cuando el proveedor tiene días de crédito.
                        </small>
                    </label>
                </div>
            </fieldset>

            <details class="form-details">
                <summary>Domicilio y observaciones</summary>

                <div class="form-grid form-grid--details">
                    <label class="field field--span-2">
                        <span>Calle</span>
                        <input type="text" name="calle" id="proveedorCalle" maxlength="180">
                    </label>

                    <label class="field">
                        <span>Número exterior</span>
                        <input type="text" name="numero_exterior" id="proveedorNumeroExterior" maxlength="30">
                    </label>

                    <label class="field">
                        <span>Número interior</span>
                        <input type="text" name="numero_interior" id="proveedorNumeroInterior" maxlength="30">
                    </label>

                    <label class="field">
                        <span>Colonia</span>
                        <input type="text" name="colonia" id="proveedorColonia" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Municipio</span>
                        <input type="text" name="municipio" id="proveedorMunicipio" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Estado</span>
                        <input type="text" name="estado_domicilio" id="proveedorEstado" maxlength="120">
                    </label>

                    <label class="field">
                        <span>Código postal</span>
                        <input type="text" name="codigo_postal" id="proveedorCodigoPostal" maxlength="15">
                    </label>

                    <label class="field field--span-2">
                        <span>País</span>
                        <input type="text" name="pais" id="proveedorPais" maxlength="80" value="México">
                    </label>

                    <label class="field field--span-2">
                        <span>Observaciones</span>
                        <textarea name="observaciones" id="proveedorObservaciones" rows="3" maxlength="5000"></textarea>
                    </label>
                </div>
            </details>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalProveedor">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar proveedor
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL RELACIÓN
     ================================================================ -->
<div class="modal-backdrop" id="modalRelacion" hidden>
    <section class="modal-card modal-card--medium" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>SUMINISTRO</small>
                <h2 id="tituloModalRelacion">Agregar materia prima</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalRelacion">
                ×
            </button>
        </header>

        <form id="formRelacion">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="GUARDAR_RELACION">
            <input type="hidden" name="relacion_id" id="relacionId">
            <input type="hidden" name="proveedor_id" id="relacionProveedorId">
            <input type="hidden" name="producto_id" id="relacionProductoId">

            <div id="mensajeRelacion" class="module-message module-message--error" hidden></div>

            <div class="selected-summary selected-summary--static" id="relacionProveedorResumen"></div>

            <label class="field">
                <span>Materia prima *</span>

                <div class="smart-search">
                    <input
                        type="search"
                        id="buscarProductoRelacion"
                        maxlength="180"
                        placeholder="Escribe código o nombre de la materia prima"
                        autocomplete="off"
                        required
                    >

                    <div
                        id="resultadosProductoRelacion"
                        class="smart-results"
                        hidden
                    ></div>
                </div>
            </label>

            <div id="relacionProductoResumen" class="selected-summary" hidden></div>

            <label class="field">
                <span>Presentación habitual de compra *</span>

                <select
                    name="presentacion_id"
                    id="relacionPresentacion"
                    disabled
                >
                    <option value="">Selecciona primero una materia prima</option>
                </select>

                <small id="ayudaPresentacionRelacion">
                    Puedes comprar en la unidad base o en una presentación configurada.
                </small>
            </label>

            <div class="form-grid">
                <label class="field">
                    <span>Días de entrega</span>

                    <input
                        type="number"
                        name="dias_entrega"
                        id="relacionDiasEntrega"
                        min="0"
                        max="3650"
                        step="1"
                    >
                </label>

                <label class="field">
                    <span>Compra mínima</span>

                    <input
                        type="number"
                        name="compra_minima"
                        id="relacionCompraMinima"
                        min="0"
                        step="0.000001"
                    >

                    <small id="ayudaCompraMinima">
                        En la presentación seleccionada.
                    </small>
                </label>
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalRelacion">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Guardar
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL PRECIO
     ================================================================ -->
<div class="modal-backdrop" id="modalPrecio" hidden>
    <section class="modal-card modal-card--medium" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>NUEVO PRECIO</small>
                <h2>Registrar precio del proveedor</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalPrecio">
                ×
            </button>
        </header>

        <form id="formPrecio">
            <input type="hidden" name="csrf_token" value="<?= si_escapar($csrfToken) ?>">
            <input type="hidden" name="accion" value="REGISTRAR_PRECIO">
            <input type="hidden" name="relacion_id" id="precioRelacionId">

            <div id="mensajePrecio" class="module-message module-message--error" hidden></div>

            <div id="precioRelacionResumen" class="selected-summary selected-summary--static"></div>

            <div class="form-grid">
                <label class="field">
                    <span>Precio por presentación *</span>

                    <input
                        type="number"
                        name="precio_unitario"
                        id="precioUnitario"
                        min="0.0001"
                        step="0.0001"
                        required
                    >
                </label>

                <label class="field">
                    <span>Moneda *</span>

                    <select
                        name="moneda_id"
                        id="precioMoneda"
                        required
                    ></select>
                </label>

                <label class="field field--span-2" id="campoTipoCambio">
                    <span>Tipo de cambio a moneda base *</span>

                    <input
                        type="number"
                        name="tipo_cambio_a_base"
                        id="precioTipoCambio"
                        min="0.00000001"
                        step="0.00000001"
                    >

                    <small id="ayudaTipoCambio">
                        El sistema intenta cargar el último tipo de cambio registrado.
                    </small>
                </label>

                <label class="field">
                    <span>Vigente hasta</span>

                    <input
                        type="datetime-local"
                        name="vigencia_hasta"
                        id="precioVigenciaHasta"
                    >
                </label>

                <label class="field">
                    <span>Referencia</span>

                    <input
                        type="text"
                        name="referencia"
                        id="precioReferencia"
                        maxlength="100"
                        placeholder="Ej. Cotización 1845"
                    >
                </label>
            </div>

            <div class="calculation-preview" id="vistaCalculoPrecio">
                Captura el precio para ver el costo equivalente.
            </div>

            <footer class="modal-footer">
                <button type="button" class="btn-secondary" data-cerrar-modal="modalPrecio">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary">
                    Registrar precio
                </button>
            </footer>
        </form>
    </section>
</div>

<!-- ================================================================
     MODAL HISTORIAL
     ================================================================ -->
<div class="modal-backdrop" id="modalHistorial" hidden>
    <section class="modal-card modal-card--wide" role="dialog" aria-modal="true">
        <header class="modal-header">
            <div>
                <small>HISTÓRICO INMUTABLE</small>
                <h2 id="tituloHistorial">Historial de precios</h2>
            </div>

            <button type="button" class="modal-close" data-cerrar-modal="modalHistorial">
                ×
            </button>
        </header>

        <div class="modal-body">
            <div class="table-wrap">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Precio</th>
                            <th>Unidad</th>
                            <th>Tipo cambio</th>
                            <th>Costo normalizado</th>
                            <th>Vigencia</th>
                            <th>Referencia</th>
                        </tr>
                    </thead>

                    <tbody id="tablaHistorial">
                        <tr>
                            <td colspan="7" class="empty-cell">Cargando...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="pagination pagination--modal">
                <span id="textoPaginaHistorial">0 registros</span>

                <div>
                    <select id="porPaginaHistorial" aria-label="Precios por página">
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>

                    <button type="button" class="btn-secondary" id="btnHistorialAnterior">
                        Anterior
                    </button>

                    <span id="paginaHistorialActual">Página 1 de 1</span>

                    <button type="button" class="btn-secondary" id="btnHistorialSiguiente">
                        Siguiente
                    </button>
                </div>
            </footer>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';

    const puedeAdministrar =
        <?= $puedeAdministrar ? 'true' : 'false' ?>;

    const puedeComparar =
        <?= $puedeComparar ? 'true' : 'false' ?>;

    const csrfToken =
        <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const seccionInicial =
        <?= json_encode($seccionInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const estado = {
        seccion: seccionInicial,
        monedas: [],
        monedaBase: null,

        proveedores: [],
        paginaProveedor: 1,
        porPaginaProveedor: 20,
        totalPaginasProveedor: 1,
        timerProveedor: null,

        proveedorProductos: null,
        relaciones: [],
        paginaRelacion: 1,
        porPaginaRelacion: 20,
        totalPaginasRelacion: 1,
        timerRelacion: null,
        timerBuscarProveedorProductos: null,

        productoRelacion: null,
        opcionesProductoRelacion: null,
        timerBuscarProductoRelacion: null,

        productoComparador: null,
        paginaComparador: 1,
        porPaginaComparador: 20,
        totalPaginasComparador: 1,
        timerProductoComparador: null,

        relacionPrecio: null,
        paginaHistorial: 1,
        porPaginaHistorial: 20,
        totalPaginasHistorial: 1
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
        ).format(
            Number.isFinite(n) ? n : 0
        );
    }

    function dinero(valor, moneda) {
        const n = Number(valor || 0);

        try {
            return new Intl.NumberFormat(
                'es-MX',
                {
                    style: 'currency',
                    currency: moneda || 'MXN',
                    maximumFractionDigits: 4
                }
            ).format(n);
        } catch (error) {
            return numero(n, 4) + ' ' + (moneda || '');
        }
    }

    function fecha(valor) {
        if (!valor) {
            return '—';
        }

        const normalizada = String(valor).replace(' ', 'T');
        const d = new Date(normalizada);

        if (Number.isNaN(d.getTime())) {
            return escapeHtml(valor);
        }

        return new Intl.DateTimeFormat(
            'es-MX',
            {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }
        ).format(d);
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
                valor === null
                || typeof valor === 'undefined'
                    ? ''
                    : 'data-value="' + escapeHtml(valor) + '" '
            )
            + '>'
            + escapeHtml(texto)
            + '</button>';
    }

    function mostrarMensaje(elemento, texto, tipo) {
        elemento.textContent = texto;
        elemento.className =
            'module-message module-message--'
            + (tipo || 'error');
        elemento.hidden = false;
    }

    function ocultarMensaje(elemento) {
        elemento.textContent = '';
        elemento.hidden = true;
    }

    function mostrarError(error) {
        mostrarMensaje(
            $('mensajePagina'),
            error && error.message
                ? error.message
                : 'Ocurrió un error.',
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
            throw new Error(
                'El servidor devolvió una respuesta no válida.'
            );
        }

        if (
            datos.sesion_expirada
            && datos.redirect
        ) {
            window.location.href = datos.redirect;
            return null;
        }

        if (
            !respuesta.ok
            || datos.success !== true
        ) {
            const error = new Error(
                datos.mensaje
                || 'No fue posible completar la operación.'
            );

            error.data = datos;

            throw error;
        }

        return datos;
    }

    async function postSimple(accion, valores) {
        const form = new FormData();

        form.append('csrf_token', csrfToken);
        form.append('accion', accion);

        Object.keys(valores).forEach(
            function (clave) {
                form.append(
                    clave,
                    String(valores[clave])
                );
            }
        );

        const datos = await api(
            '?proveedores_api=1',
            {
                method: 'POST',
                body: form
            }
        );

        mostrarMensaje(
            $('mensajePagina'),
            datos.mensaje,
            'success'
        );

        return datos;
    }

    async function enviarFormulario(form, mensajeElemento, alExito) {
        ocultarMensaje(mensajeElemento);

        const boton =
            form.querySelector('button[type="submit"]');

        const textoOriginal = boton.textContent;

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        try {
            const datos = await api(
                '?proveedores_api=1',
                {
                    method: 'POST',
                    body: new FormData(form)
                }
            );

            if (alExito) {
                await alExito(datos);
            }

        } catch (error) {
            mostrarMensaje(
                mensajeElemento,
                error.message,
                'error'
            );

            throw error;

        } finally {
            boton.disabled = false;
            boton.textContent = textoOriginal;
        }
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
    }

    function cambiarSeccion(seccion) {
        estado.seccion = seccion;

        document.querySelectorAll('.module-section').forEach(
            function (section) {
                section.hidden = true;
            }
        );

        document.querySelectorAll('.module-tab').forEach(
            function (tab) {
                tab.classList.toggle(
                    'is-active',
                    tab.dataset.seccion === seccion
                );
            }
        );

        const mapa = {
            directorio: 'seccionDirectorio',
            productos: 'seccionProductos',
            comparador: 'seccionComparador'
        };

        if ($(mapa[seccion])) {
            $(mapa[seccion]).hidden = false;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('seccion', seccion);
        history.replaceState(null, '', url);

        cargarSeccionActual().catch(mostrarError);
    }

    async function cargarSeccionActual() {
        if (estado.seccion === 'directorio') {
            await cargarProveedores();
            return;
        }

        if (
            estado.seccion === 'productos'
            && estado.proveedorProductos
        ) {
            await cargarRelaciones();
            return;
        }

        if (
            estado.seccion === 'comparador'
            && estado.productoComparador
        ) {
            await cargarComparador();
        }
    }

    /* ==============================================================
       CATÁLOGOS
       ============================================================== */

    async function cargarCatalogos() {
        const datos = await api(
            '?proveedores_api=1&accion=CATALOGOS'
        );

        estado.monedas = datos.monedas || [];
        estado.monedaBase = datos.moneda_base || null;

        const opciones =
            estado.monedas.map(
                function (m) {
                    return '<option value="'
                        + m.id
                        + '">'
                        + escapeHtml(
                            m.codigo
                            + ' · '
                            + m.nombre
                        )
                        + '</option>';
                }
            ).join('');

        $('proveedorMoneda').innerHTML = opciones;
        $('precioMoneda').innerHTML = opciones;

        $('filtroMonedaProveedor').innerHTML =
            '<option value="0">Todas</option>'
            + opciones;

        const base =
            estado.monedas.find(
                function (m) {
                    return m.es_base === 1;
                }
            );

        if (base) {
            $('proveedorMoneda').value =
                String(base.id);
        }
    }

    /* ==============================================================
       DIRECTORIO
       ============================================================== */

    async function cargarProveedores() {
        $('tablaProveedores').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'LISTAR_PROVEEDORES',
            pagina: String(estado.paginaProveedor),
            por_pagina: String(estado.porPaginaProveedor),
            busqueda: $('buscarProveedor').value.trim(),
            estado: $('filtroEstadoProveedor').value,
            moneda_id: $('filtroMonedaProveedor').value
        });

        const datos = await api(
            '?' + params.toString()
        );

        estado.proveedores =
            datos.proveedores || [];

        estado.totalPaginasProveedor =
            datos.paginacion.total_paginas || 1;

        renderProveedores(
            estado.proveedores
        );

        renderPaginaProveedor(
            datos.paginacion
        );

        renderKpis(
            datos.resumen || {}
        );
    }

    function renderProveedores(filas) {
        if (!filas.length) {
            $('tablaProveedores').innerHTML =
                '<tr><td colspan="8" class="empty-cell">'
                + 'No se encontraron proveedores.'
                + '</td></tr>';
            return;
        }

        $('tablaProveedores').innerHTML =
            filas.map(
                function (p) {
                    let acciones = '';

                    acciones += botonAccion(
                        'Productos',
                        'productos-proveedor',
                        p.id
                    );

                    if (puedeAdministrar) {
                        acciones += botonAccion(
                            'Editar',
                            'editar-proveedor',
                            p.id
                        );

                        acciones += botonAccion(
                            p.activo === 1
                                ? 'Desactivar'
                                : 'Activar',
                            'estado-proveedor',
                            p.id,
                            p.activo === 1 ? 0 : 1,
                            p.activo === 1
                                ? 'danger'
                                : 'success'
                        );

                        if (p.activo === 0) {
                            acciones += botonAccion(
                                'Papelera',
                                'papelera-proveedor',
                                p.id,
                                null,
                                'danger'
                            );
                        }
                    }

                    const condicion =
                        p.dias_credito > 0
                            ? p.dias_credito + ' días crédito'
                            : 'Contado';

                    return ''
                        + '<tr>'
                        + '<td><strong>'
                        + escapeHtml(p.codigo)
                        + '</strong></td>'
                        + '<td><strong>'
                        + escapeHtml(p.razon_social)
                        + '</strong>'
                        + (
                            p.nombre_comercial
                                ? '<small class="cell-secondary">'
                                    + escapeHtml(p.nombre_comercial)
                                    + '</small>'
                                : ''
                        )
                        + '</td>'
                        + '<td>'
                        + escapeHtml(p.rfc || '—')
                        + '</td>'
                        + '<td>'
                        + escapeHtml(p.contacto_nombre || '—')
                        + (
                            p.telefono
                                ? '<small class="cell-secondary">'
                                    + escapeHtml(p.telefono)
                                    + '</small>'
                                : ''
                        )
                        + '</td>'
                        + '<td>'
                        + escapeHtml(condicion)
                        + '<small class="cell-secondary">'
                        + escapeHtml(p.moneda_codigo || 'Sin moneda')
                        + '</small>'
                        + '</td>'
                        + '<td>'
                        + numero(p.productos_activos)
                        + (
                            p.cuentas_pendientes > 0
                                ? '<small class="cell-secondary cell-warning">'
                                    + p.cuentas_pendientes
                                    + ' CxP pendiente(s)'
                                    + '</small>'
                                : ''
                        )
                        + '</td>'
                        + '<td>'
                        + status(
                            p.activo === 1 ? 'Activo' : 'Inactivo',
                            p.activo === 1 ? 'active' : 'inactive'
                        )
                        + '</td>'
                        + '<td class="text-right actions-cell">'
                        + acciones
                        + '</td>'
                        + '</tr>';
                }
            ).join('');
    }

    function renderPaginaProveedor(p) {
        $('textoPaginaProveedor').textContent =
            p.total_registros + ' registro(s)';

        $('paginaProveedorActual').textContent =
            'Página ' + p.pagina + ' de ' + p.total_paginas;

        $('btnProveedorAnterior').disabled =
            p.pagina <= 1;

        $('btnProveedorSiguiente').disabled =
            p.pagina >= p.total_paginas;
    }

    function renderKpis(r) {
        $('kpiTotal').textContent = r.total || 0;
        $('kpiActivos').textContent = r.activos || 0;
        $('kpiCredito').textContent = r.con_credito || 0;
        $('kpiInactivos').textContent = r.inactivos || 0;
    }

    function nuevoProveedor() {
        $('formProveedor').reset();
        $('proveedorId').value = '';
        $('proveedorCodigo').value = 'Se generará al guardar';
        $('proveedorPais').value = 'México';
        $('proveedorDiasCredito').value = '0';
        $('tituloModalProveedor').textContent = 'Nuevo proveedor';

        const base =
            estado.monedas.find(
                function (m) {
                    return m.es_base === 1;
                }
            );

        if (base) {
            $('proveedorMoneda').value =
                String(base.id);
        }

        actualizarCreditoProveedor();
        ocultarMensaje($('mensajeProveedor'));
        abrirModal('modalProveedor');
    }

    async function editarProveedor(id) {
        const datos = await api(
            '?proveedores_api=1&accion=DETALLE_PROVEEDOR&id='
            + encodeURIComponent(id)
        );

        const p = datos.proveedor;

        $('formProveedor').reset();
        $('proveedorId').value = p.id;
        $('proveedorCodigo').value = p.codigo || '';
        $('proveedorRazonSocial').value = p.razon_social || '';
        $('proveedorNombreComercial').value = p.nombre_comercial || '';
        $('proveedorRfc').value = p.rfc || '';
        $('proveedorContacto').value = p.contacto_nombre || '';
        $('proveedorTelefono').value = p.telefono || '';
        $('proveedorCorreo').value = p.correo || '';
        $('proveedorCalle').value = p.calle || '';
        $('proveedorNumeroExterior').value = p.numero_exterior || '';
        $('proveedorNumeroInterior').value = p.numero_interior || '';
        $('proveedorColonia').value = p.colonia || '';
        $('proveedorMunicipio').value = p.municipio || '';
        $('proveedorEstado').value = p.estado || '';
        $('proveedorCodigoPostal').value = p.codigo_postal || '';
        $('proveedorPais').value = p.pais || 'México';
        $('proveedorMoneda').value = p.moneda_default_id || '';
        $('proveedorDiasCredito').value = p.dias_credito || 0;
        $('proveedorLimiteCredito').value =
            p.limite_credito == null
                ? ''
                : p.limite_credito;
        $('proveedorObservaciones').value = p.observaciones || '';

        $('tituloModalProveedor').textContent =
            'Editar proveedor';

        actualizarCreditoProveedor();
        ocultarMensaje($('mensajeProveedor'));
        abrirModal('modalProveedor');
    }

    function actualizarCreditoProveedor() {
        const dias =
            Number($('proveedorDiasCredito').value || 0);

        const tieneCredito =
            Number.isFinite(dias)
            && dias > 0;

        $('proveedorLimiteCredito').disabled =
            !tieneCredito;

        if (!tieneCredito) {
            $('proveedorLimiteCredito').value = '';
            $('ayudaLimiteCredito').textContent =
                'Proveedor de contado: el límite no aplica.';
        } else {
            $('ayudaLimiteCredito').textContent =
                'Opcional. Déjalo vacío si la empresa no maneja un límite definido.';
        }
    }

    async function guardarProveedor(event) {
        event.preventDefault();

        await enviarFormulario(
            event.currentTarget,
            $('mensajeProveedor'),
            async function (datos) {
                cerrarModal('modalProveedor');

                mostrarMensaje(
                    $('mensajePagina'),
                    datos.mensaje,
                    'success'
                );

                await cargarProveedores();
            }
        );
    }

    async function cambiarEstadoProveedor(id, activo) {
        const texto =
            activo === 1
                ? '¿Activar este proveedor? Sus productos deberán reactivarse individualmente si fueron desactivados.'
                : '¿Desactivar este proveedor? También se desactivarán sus relaciones de suministro para nuevas operaciones.';

        if (!window.confirm(texto)) {
            return;
        }

        await postSimple(
            'CAMBIAR_ESTADO_PROVEEDOR',
            {
                proveedor_id: id,
                activo: activo
            }
        );

        await cargarProveedores();

        if (
            estado.proveedorProductos
            && estado.proveedorProductos.id === id
            && activo === 0
        ) {
            limpiarProveedorProductos();
        }
    }

    async function papeleraProveedor(id) {
        if (
            !window.confirm(
                '¿Enviar este proveedor a la papelera? El sistema bloqueará la operación si existen compras abiertas o saldos pendientes.'
            )
        ) {
            return;
        }

        await postSimple(
            'PAPELERA_PROVEEDOR',
            {
                proveedor_id: id
            }
        );

        await cargarProveedores();

        if (
            estado.proveedorProductos
            && estado.proveedorProductos.id === id
        ) {
            limpiarProveedorProductos();
        }
    }

    /* ==============================================================
       BÚSQUEDAS INTELIGENTES
       ============================================================== */

    async function buscarProveedoresSmart(q, contenedor, alSeleccionar) {
        if (q.length < 1) {
            contenedor.hidden = true;
            contenedor.innerHTML = '';
            return;
        }

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'BUSCAR_PROVEEDORES',
            q: q
        });

        const datos = await api(
            '?' + params.toString()
        );

        const filas = datos.proveedores || [];

        if (!filas.length) {
            contenedor.innerHTML =
                '<div class="smart-empty">Sin coincidencias.</div>';
            contenedor.hidden = false;
            return;
        }

        contenedor.innerHTML =
            filas.map(
                function (p) {
                    return '<button '
                        + 'type="button" '
                        + 'class="smart-result" '
                        + 'data-id="' + p.id + '">'
                        + '<strong>'
                        + escapeHtml(p.codigo + ' · ' + p.razon_social)
                        + '</strong>'
                        + '<small>'
                        + escapeHtml(
                            (p.moneda || 'Sin moneda')
                            + ' · '
                            + (
                                p.dias_credito > 0
                                    ? p.dias_credito + ' días crédito'
                                    : 'Contado'
                            )
                        )
                        + '</small>'
                        + '</button>';
                }
            ).join('');

        contenedor.hidden = false;

        contenedor.querySelectorAll('[data-id]').forEach(
            function (boton) {
                boton.addEventListener(
                    'click',
                    function () {
                        const id = Number(boton.dataset.id);
                        const p = filas.find(
                            function (item) {
                                return item.id === id;
                            }
                        );

                        if (p) {
                            alSeleccionar(p);
                        }

                        contenedor.hidden = true;
                        contenedor.innerHTML = '';
                    }
                );
            }
        );
    }

    async function buscarMateriasPrimasSmart(q, contenedor, alSeleccionar) {
        if (q.length < 1) {
            contenedor.hidden = true;
            contenedor.innerHTML = '';
            return;
        }

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'BUSCAR_MATERIAS_PRIMAS',
            q: q
        });

        const datos = await api(
            '?' + params.toString()
        );

        const filas = datos.productos || [];

        if (!filas.length) {
            contenedor.innerHTML =
                '<div class="smart-empty">Sin materias primas coincidentes.</div>';
            contenedor.hidden = false;
            return;
        }

        contenedor.innerHTML =
            filas.map(
                function (p) {
                    return '<button '
                        + 'type="button" '
                        + 'class="smart-result" '
                        + 'data-id="' + p.id + '">'
                        + '<strong>'
                        + escapeHtml(p.sku + ' · ' + p.nombre)
                        + '</strong>'
                        + '<small>'
                        + escapeHtml(
                            'Unidad base: '
                            + p.unidad_base
                            + ' ('
                            + p.unidad_base_simbolo
                            + ')'
                        )
                        + '</small>'
                        + '</button>';
                }
            ).join('');

        contenedor.hidden = false;

        contenedor.querySelectorAll('[data-id]').forEach(
            function (boton) {
                boton.addEventListener(
                    'click',
                    function () {
                        const id = Number(boton.dataset.id);
                        const p = filas.find(
                            function (item) {
                                return item.id === id;
                            }
                        );

                        if (p) {
                            alSeleccionar(p);
                        }

                        contenedor.hidden = true;
                        contenedor.innerHTML = '';
                    }
                );
            }
        );
    }

    /* ==============================================================
       PRODUCTOS DEL PROVEEDOR
       ============================================================== */

    function seleccionarProveedorProductos(p) {
        estado.proveedorProductos = p;
        estado.paginaRelacion = 1;

        $('buscarProveedorProductos').value =
            p.codigo + ' · ' + p.razon_social;

        $('proveedorProductosSeleccionado').innerHTML =
            '<strong>'
            + escapeHtml(p.razon_social)
            + '</strong>'
            + '<span>'
            + escapeHtml(
                p.codigo
                + ' · '
                + (p.moneda || p.moneda_codigo || 'Sin moneda')
                + ' · '
                + (
                    p.dias_credito > 0
                        ? p.dias_credito + ' días de crédito'
                        : 'Contado'
                )
            )
            + '</span>';

        $('proveedorProductosSeleccionado').hidden = false;

        $('buscarRelacion').disabled = false;
        $('filtroEstadoRelacion').disabled = false;
        $('porPaginaRelacion').disabled = false;

        if ($('btnNuevaRelacion')) {
            $('btnNuevaRelacion').disabled = false;
        }

        cargarRelaciones().catch(mostrarError);
    }

    function limpiarProveedorProductos() {
        estado.proveedorProductos = null;
        estado.relaciones = [];
        estado.paginaRelacion = 1;
        estado.totalPaginasRelacion = 1;

        $('buscarProveedorProductos').value = '';
        $('proveedorProductosSeleccionado').hidden = true;
        $('buscarRelacion').value = '';
        $('buscarRelacion').disabled = true;
        $('filtroEstadoRelacion').value = 'TODOS';
        $('filtroEstadoRelacion').disabled = true;
        $('porPaginaRelacion').value = '20';
        $('porPaginaRelacion').disabled = true;

        if ($('btnNuevaRelacion')) {
            $('btnNuevaRelacion').disabled = true;
        }

        $('tablaRelaciones').innerHTML =
            '<tr><td colspan="8" class="empty-cell">'
            + 'Selecciona un proveedor.'
            + '</td></tr>';

        $('textoPaginaRelacion').textContent = '0 registros';
        $('paginaRelacionActual').textContent = 'Página 1 de 1';
        $('btnRelacionAnterior').disabled = true;
        $('btnRelacionSiguiente').disabled = true;
    }

    async function cargarRelaciones() {
        if (!estado.proveedorProductos) {
            return;
        }

        $('tablaRelaciones').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Cargando...</td></tr>';

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'LISTAR_RELACIONES',
            proveedor_id: String(estado.proveedorProductos.id),
            pagina: String(estado.paginaRelacion),
            por_pagina: String(estado.porPaginaRelacion),
            busqueda: $('buscarRelacion').value.trim(),
            estado: $('filtroEstadoRelacion').value
        });

        const datos = await api(
            '?' + params.toString()
        );

        estado.relaciones =
            datos.relaciones || [];

        estado.totalPaginasRelacion =
            datos.paginacion.total_paginas || 1;

        renderRelaciones(
            estado.relaciones
        );

        renderPaginaRelacion(
            datos.paginacion
        );
    }

    function renderRelaciones(filas) {
        if (!filas.length) {
            $('tablaRelaciones').innerHTML =
                '<tr><td colspan="8" class="empty-cell">'
                + 'Este proveedor no tiene materias primas registradas.'
                + '</td></tr>';
            return;
        }

        $('tablaRelaciones').innerHTML =
            filas.map(
                function (r) {
                    let acciones = '';

                    if (r.activo === 1) {
                        acciones += botonAccion(
                            'Historial',
                            'historial-precios',
                            r.id
                        );
                    } else {
                        acciones += botonAccion(
                            'Historial',
                            'historial-precios',
                            r.id
                        );
                    }

                    if (puedeAdministrar) {
                        if (r.activo === 1) {
                            acciones += botonAccion(
                                'Nuevo precio',
                                'nuevo-precio',
                                r.id,
                                null,
                                'success'
                            );
                        }

                        acciones += botonAccion(
                            'Editar',
                            'editar-relacion',
                            r.id
                        );

                        acciones += botonAccion(
                            r.activo === 1
                                ? 'Desactivar'
                                : 'Activar',
                            'estado-relacion',
                            r.id,
                            r.activo === 1 ? 0 : 1,
                            r.activo === 1
                                ? 'danger'
                                : 'success'
                        );
                    }

                    const compraMinima =
                        r.compra_minima == null
                            ? '—'
                            : numero(r.compra_minima, 6)
                                + ' '
                                + (r.presentacion || r.unidad_cotizada_simbolo);

                    const ultimoPrecio =
                        r.ultimo_precio == null
                            ? 'Sin precio'
                            : dinero(
                                r.ultimo_precio,
                                r.ultimo_precio_moneda
                            );

                    const normalizado =
                        r.ultimo_precio_normalizado == null
                            ? '—'
                            : numero(
                                r.ultimo_precio_normalizado,
                                6
                            )
                            + ' '
                            + (
                                estado.monedaBase
                                    ? estado.monedaBase.codigo
                                    : ''
                            )
                            + '/'
                            + r.unidad_base_simbolo;

                    return ''
                        + '<tr>'
                        + '<td><strong>'
                        + escapeHtml(r.producto)
                        + '</strong><small class="cell-secondary">'
                        + escapeHtml(r.sku)
                        + '</small></td>'
                        + '<td>'
                        + escapeHtml(
                            r.presentacion
                            || (
                                r.unidad_base
                                + ' (unidad base)'
                            )
                        )
                        + '<small class="cell-secondary">'
                        + 'Factor: '
                        + numero(r.factor_a_unidad_base, 6)
                        + ' '
                        + escapeHtml(r.unidad_base_simbolo)
                        + '</small>'
                        + '</td>'
                        + '<td>'
                        + (
                            r.dias_entrega == null
                                ? '—'
                                : r.dias_entrega + ' día(s)'
                        )
                        + '</td>'
                        + '<td>'
                        + escapeHtml(compraMinima)
                        + '</td>'
                        + '<td>'
                        + escapeHtml(ultimoPrecio)
                        + (
                            r.ultimo_precio_fecha
                                ? '<small class="cell-secondary">'
                                    + escapeHtml(fecha(r.ultimo_precio_fecha))
                                    + '</small>'
                                : ''
                        )
                        + '</td>'
                        + '<td>'
                        + escapeHtml(normalizado)
                        + '</td>'
                        + '<td>'
                        + status(
                            r.activo === 1 ? 'Activo' : 'Inactivo',
                            r.activo === 1 ? 'active' : 'inactive'
                        )
                        + '</td>'
                        + '<td class="text-right actions-cell">'
                        + acciones
                        + '</td>'
                        + '</tr>';
                }
            ).join('');
    }

    function renderPaginaRelacion(p) {
        $('textoPaginaRelacion').textContent =
            p.total_registros + ' registro(s)';

        $('paginaRelacionActual').textContent =
            'Página ' + p.pagina + ' de ' + p.total_paginas;

        $('btnRelacionAnterior').disabled =
            p.pagina <= 1;

        $('btnRelacionSiguiente').disabled =
            p.pagina >= p.total_paginas;
    }

    function nuevaRelacion() {
        if (!estado.proveedorProductos) {
            return;
        }

        estado.productoRelacion = null;
        estado.opcionesProductoRelacion = null;

        $('formRelacion').reset();
        $('relacionId').value = '';
        $('relacionProveedorId').value =
            estado.proveedorProductos.id;
        $('relacionProductoId').value = '';
        $('buscarProductoRelacion').value = '';
        $('buscarProductoRelacion').disabled = false;

        $('relacionProveedorResumen').innerHTML =
            '<strong>'
            + escapeHtml(
                estado.proveedorProductos.razon_social
            )
            + '</strong><span>'
            + escapeHtml(
                estado.proveedorProductos.codigo
            )
            + '</span>';

        $('relacionProductoResumen').hidden = true;
        $('relacionPresentacion').disabled = true;
        $('relacionPresentacion').innerHTML =
            '<option value="">Selecciona primero una materia prima</option>';

        $('tituloModalRelacion').textContent =
            'Agregar materia prima';

        ocultarMensaje($('mensajeRelacion'));
        abrirModal('modalRelacion');
    }

    async function seleccionarProductoRelacion(p) {
        estado.productoRelacion = p;

        $('relacionProductoId').value = p.id;
        $('buscarProductoRelacion').value =
            p.sku + ' · ' + p.nombre;

        $('relacionProductoResumen').innerHTML =
            '<strong>'
            + escapeHtml(p.nombre)
            + '</strong><span>'
            + escapeHtml(
                'Unidad base: '
                + p.unidad_base
                + ' ('
                + p.unidad_base_simbolo
                + ')'
            )
            + '</span>';

        $('relacionProductoResumen').hidden = false;

        const datos = await api(
            '?proveedores_api=1&accion=OPCIONES_PRODUCTO&producto_id='
            + encodeURIComponent(p.id)
        );

        estado.opcionesProductoRelacion = datos;

        const producto = datos.producto;
        const presentaciones = datos.presentaciones || [];

        $('relacionPresentacion').innerHTML =
            '<option value="">'
            + escapeHtml(
                producto.unidad_base
                + ' ('
                + producto.unidad_base_simbolo
                + ') · unidad base'
            )
            + '</option>'
            + presentaciones.map(
                function (pres) {
                    return '<option value="'
                        + pres.id
                        + '">'
                        + escapeHtml(
                            pres.nombre
                            + ' · 1 = '
                            + numero(
                                pres.factor_a_unidad_base,
                                6
                            )
                            + ' '
                            + producto.unidad_base_simbolo
                        )
                        + '</option>';
                }
            ).join('');

        $('relacionPresentacion').disabled = false;

        actualizarAyudaCompraMinima();
    }

    async function editarRelacion(id) {
        const datos = await api(
            '?proveedores_api=1&accion=DETALLE_RELACION&id='
            + encodeURIComponent(id)
        );

        const r = datos.relacion;

        $('formRelacion').reset();
        $('relacionId').value = r.id;
        $('relacionProveedorId').value = r.proveedor_id;
        $('relacionProductoId').value = r.producto_id;
        $('buscarProductoRelacion').value =
            r.sku + ' · ' + r.producto;
        $('buscarProductoRelacion').disabled = true;

        $('relacionProveedorResumen').innerHTML =
            '<strong>'
            + escapeHtml(r.proveedor)
            + '</strong><span>'
            + escapeHtml(r.proveedor_codigo)
            + '</span>';

        $('relacionProductoResumen').innerHTML =
            '<strong>'
            + escapeHtml(r.producto)
            + '</strong><span>'
            + escapeHtml(r.sku)
            + '</span>';

        $('relacionProductoResumen').hidden = false;

        const opciones = await api(
            '?proveedores_api=1&accion=OPCIONES_PRODUCTO&producto_id='
            + encodeURIComponent(r.producto_id)
        );

        estado.productoRelacion = opciones.producto;
        estado.opcionesProductoRelacion = opciones;

        $('relacionPresentacion').innerHTML =
            '<option value="">'
            + escapeHtml(
                opciones.producto.unidad_base
                + ' ('
                + opciones.producto.unidad_base_simbolo
                + ') · unidad base'
            )
            + '</option>'
            + (opciones.presentaciones || []).map(
                function (pres) {
                    return '<option value="'
                        + pres.id
                        + '">'
                        + escapeHtml(
                            pres.nombre
                            + ' · 1 = '
                            + numero(
                                pres.factor_a_unidad_base,
                                6
                            )
                            + ' '
                            + opciones.producto.unidad_base_simbolo
                        )
                        + '</option>';
                }
            ).join('');

        $('relacionPresentacion').value =
            r.presentacion_id || '';

        /*
         * Producto y presentación se bloquean en edición para conservar
         * coherencia con el historial de precios.
         */
        $('relacionPresentacion').disabled = true;

        $('relacionDiasEntrega').value =
            r.dias_entrega == null
                ? ''
                : r.dias_entrega;

        $('relacionCompraMinima').value =
            r.compra_minima == null
                ? ''
                : r.compra_minima;

        $('tituloModalRelacion').textContent =
            'Editar suministro';

        actualizarAyudaCompraMinima();
        ocultarMensaje($('mensajeRelacion'));
        abrirModal('modalRelacion');
    }

    async function guardarRelacion(event) {
        event.preventDefault();

        if (!$('relacionProductoId').value) {
            mostrarMensaje(
                $('mensajeRelacion'),
                'Selecciona una materia prima.',
                'error'
            );
            return;
        }

        const selectPresentacion =
            $('relacionPresentacion');

        const estabaDeshabilitado =
            selectPresentacion.disabled;

        selectPresentacion.disabled = false;

        try {
            await enviarFormulario(
                event.currentTarget,
                $('mensajeRelacion'),
                async function (datos) {
                    cerrarModal('modalRelacion');

                    mostrarMensaje(
                        $('mensajePagina'),
                        datos.mensaje,
                        'success'
                    );

                    await cargarRelaciones();
                    await cargarProveedores();
                }
            );
        } finally {
            if (
                estabaDeshabilitado
                && !$('modalRelacion').hidden
            ) {
                selectPresentacion.disabled = true;
            }
        }
    }

    function actualizarAyudaCompraMinima() {
        if (!estado.opcionesProductoRelacion) {
            $('ayudaCompraMinima').textContent =
                'En la presentación seleccionada.';
            return;
        }

        const valor =
            $('relacionPresentacion').value;

        const producto =
            estado.opcionesProductoRelacion.producto;

        if (!valor) {
            $('ayudaCompraMinima').textContent =
                'Cantidad mínima en '
                + producto.unidad_base_simbolo
                + '.';
            return;
        }

        const pres =
            (estado.opcionesProductoRelacion.presentaciones || [])
                .find(
                    function (item) {
                        return String(item.id) === String(valor);
                    }
                );

        $('ayudaCompraMinima').textContent =
            pres
                ? 'Cantidad mínima en "' + pres.nombre + '".'
                : 'En la presentación seleccionada.';
    }

    async function cambiarEstadoRelacion(id, activo) {
        if (
            !window.confirm(
                activo === 1
                    ? '¿Activar este producto para el proveedor?'
                    : '¿Desactivar este producto para nuevas compras? El historial de precios se conservará.'
            )
        ) {
            return;
        }

        await postSimple(
            'CAMBIAR_ESTADO_RELACION',
            {
                relacion_id: id,
                activo: activo
            }
        );

        await cargarRelaciones();
        await cargarProveedores();
    }

    /* ==============================================================
       PRECIOS / HISTORIAL
       ============================================================== */

    function abrirNuevoPrecio(id) {
        const r = estado.relaciones.find(
            function (item) {
                return item.id === id;
            }
        );

        if (!r) {
            mostrarMensaje(
                $('mensajePagina'),
                'No se encontró el producto seleccionado.',
                'error'
            );
            return;
        }

        estado.relacionPrecio = r;

        $('formPrecio').reset();
        $('precioRelacionId').value = r.id;

        $('precioRelacionResumen').innerHTML =
            '<strong>'
            + escapeHtml(r.producto)
            + '</strong><span>'
            + escapeHtml(
                (r.presentacion || r.unidad_base)
                + ' · 1 equivale a '
                + numero(r.factor_a_unidad_base, 6)
                + ' '
                + r.unidad_base_simbolo
            )
            + '</span>';

        const monedaProveedor =
            estado.proveedorProductos
                ? estado.proveedorProductos.moneda_default_id
                : null;

        if (monedaProveedor) {
            $('precioMoneda').value =
                String(monedaProveedor);
        } else if (estado.monedaBase) {
            $('precioMoneda').value =
                String(estado.monedaBase.id);
        }

        ocultarMensaje($('mensajePrecio'));
        abrirModal('modalPrecio');

        actualizarTipoCambioPrecio()
            .then(actualizarVistaCalculoPrecio)
            .catch(
                function (error) {
                    mostrarMensaje(
                        $('mensajePrecio'),
                        error.message,
                        'error'
                    );
                }
            );
    }

    async function actualizarTipoCambioPrecio() {
        const monedaId =
            Number($('precioMoneda').value);

        if (!monedaId) {
            return;
        }

        const esBase =
            estado.monedaBase
            && monedaId === Number(estado.monedaBase.id);

        $('campoTipoCambio').hidden =
            Boolean(esBase);

        if (esBase) {
            $('precioTipoCambio').value = '1';
            return;
        }

        const datos = await api(
            '?proveedores_api=1&accion=TIPO_CAMBIO&moneda_id='
            + encodeURIComponent(monedaId)
        );

        $('precioTipoCambio').value =
            datos.encontrado
                ? Number(datos.tipo_cambio).toFixed(8)
                : '';

        $('ayudaTipoCambio').textContent =
            datos.encontrado
                ? 'Se cargó el último tipo de cambio registrado. Puedes ajustarlo para esta cotización.'
                : 'No existe tipo de cambio registrado. Captúralo para esta cotización.';
    }

    function actualizarVistaCalculoPrecio() {
        if (!estado.relacionPrecio) {
            return;
        }

        const precio =
            Number($('precioUnitario').value);

        const tipoCambio =
            Number($('precioTipoCambio').value || 1);

        const factor =
            Number(
                estado.relacionPrecio.factor_a_unidad_base
            );

        if (
            !Number.isFinite(precio)
            || precio <= 0
            || !Number.isFinite(tipoCambio)
            || tipoCambio <= 0
            || !Number.isFinite(factor)
            || factor <= 0
        ) {
            $('vistaCalculoPrecio').textContent =
                'Captura precio y tipo de cambio para ver el costo equivalente.';
            return;
        }

        const normalizado =
            (precio * tipoCambio) / factor;

        $('vistaCalculoPrecio').innerHTML =
            '<strong>Costo comparable:</strong> '
            + numero(normalizado, 6)
            + ' '
            + escapeHtml(
                estado.monedaBase
                    ? estado.monedaBase.codigo
                    : ''
            )
            + ' por '
            + escapeHtml(
                estado.relacionPrecio.unidad_base_simbolo
            )
            + '.';
    }

    async function guardarPrecio(event) {
        event.preventDefault();

        await enviarFormulario(
            event.currentTarget,
            $('mensajePrecio'),
            async function (datos) {
                cerrarModal('modalPrecio');

                mostrarMensaje(
                    $('mensajePagina'),
                    datos.mensaje,
                    'success'
                );

                await cargarRelaciones();

                if (
                    estado.productoComparador
                    && estado.productoComparador.id
                        === estado.relacionPrecio.producto_id
                ) {
                    await cargarComparador();
                }
            }
        );
    }

    function abrirHistorial(id) {
        const r = estado.relaciones.find(
            function (item) {
                return item.id === id;
            }
        );

        estado.relacionPrecio =
            r || { id: id };

        estado.paginaHistorial = 1;

        $('tituloHistorial').textContent =
            r
                ? 'Historial · ' + r.producto
                : 'Historial de precios';

        abrirModal('modalHistorial');

        cargarHistorial().catch(
            function (error) {
                $('tablaHistorial').innerHTML =
                    '<tr><td colspan="7" class="empty-cell">'
                    + escapeHtml(error.message)
                    + '</td></tr>';
            }
        );
    }

    async function cargarHistorial() {
        if (!estado.relacionPrecio) {
            return;
        }

        $('tablaHistorial').innerHTML =
            '<tr><td colspan="7" class="empty-cell">Cargando...</td></tr>';

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'LISTAR_PRECIOS',
            relacion_id: String(estado.relacionPrecio.id),
            pagina: String(estado.paginaHistorial),
            por_pagina: String(estado.porPaginaHistorial)
        });

        const datos = await api(
            '?' + params.toString()
        );

        const filas =
            datos.precios || [];

        estado.totalPaginasHistorial =
            datos.paginacion.total_paginas || 1;

        if (!filas.length) {
            $('tablaHistorial').innerHTML =
                '<tr><td colspan="7" class="empty-cell">'
                + 'Todavía no hay precios registrados.'
                + '</td></tr>';
        } else {
            $('tablaHistorial').innerHTML =
                filas.map(
                    function (p) {
                        return ''
                            + '<tr>'
                            + '<td>' + escapeHtml(fecha(p.fecha_precio)) + '</td>'
                            + '<td><strong>'
                            + escapeHtml(dinero(p.precio_unitario, p.moneda))
                            + '</strong></td>'
                            + '<td>'
                            + escapeHtml(
                                p.unidad_nombre
                                + ' ('
                                + p.unidad_simbolo
                                + ')'
                            )
                            + '</td>'
                            + '<td>'
                            + numero(p.tipo_cambio_a_base, 8)
                            + '</td>'
                            + '<td><strong>'
                            + numero(p.precio_normalizado_base, 6)
                            + ' '
                            + escapeHtml(
                                estado.monedaBase
                                    ? estado.monedaBase.codigo
                                    : ''
                            )
                            + '/base'
                            + '</strong></td>'
                            + '<td>'
                            + escapeHtml(
                                p.vigencia_hasta
                                    ? fecha(p.vigencia_hasta)
                                    : 'Sin fecha límite'
                            )
                            + '</td>'
                            + '<td>'
                            + escapeHtml(p.referencia || '—')
                            + '</td>'
                            + '</tr>';
                    }
                ).join('');
        }

        const pg = datos.paginacion;

        $('textoPaginaHistorial').textContent =
            pg.total_registros + ' registro(s)';

        $('paginaHistorialActual').textContent =
            'Página ' + pg.pagina + ' de ' + pg.total_paginas;

        $('btnHistorialAnterior').disabled =
            pg.pagina <= 1;

        $('btnHistorialSiguiente').disabled =
            pg.pagina >= pg.total_paginas;
    }

    /* ==============================================================
       COMPARADOR
       ============================================================== */

    function seleccionarProductoComparador(p) {
        estado.productoComparador = p;
        estado.paginaComparador = 1;

        $('buscarProductoComparador').value =
            p.sku + ' · ' + p.nombre;

        $('productoComparadorSeleccionado').innerHTML =
            '<strong>'
            + escapeHtml(p.nombre)
            + '</strong><span>'
            + escapeHtml(
                p.sku
                + ' · unidad base: '
                + p.unidad_base
                + ' ('
                + p.unidad_base_simbolo
                + ')'
            )
            + '</span>';

        $('productoComparadorSeleccionado').hidden = false;

        cargarComparador().catch(mostrarError);
    }

    async function cargarComparador() {
        if (!estado.productoComparador) {
            return;
        }

        $('tablaComparador').innerHTML =
            '<tr><td colspan="8" class="empty-cell">Comparando...</td></tr>';

        const params = new URLSearchParams({
            proveedores_api: '1',
            accion: 'COMPARADOR',
            producto_id: String(estado.productoComparador.id),
            pagina: String(estado.paginaComparador),
            por_pagina: String(estado.porPaginaComparador),
            solo_vigentes: $('soloVigentesComparador').checked
                ? '1'
                : '0'
        });

        const datos = await api(
            '?' + params.toString()
        );

        const filas =
            datos.comparacion || [];

        estado.totalPaginasComparador =
            datos.paginacion.total_paginas || 1;

        if (!filas.length) {
            $('tablaComparador').innerHTML =
                '<tr><td colspan="8" class="empty-cell">'
                + 'No hay precios disponibles para comparar.'
                + '</td></tr>';
        } else {
            $('tablaComparador').innerHTML =
                filas.map(
                    function (c) {
                        const mejor =
                            Number(c.posicion) === 1;

                        return ''
                            + '<tr class="'
                            + (mejor ? 'best-price-row' : '')
                            + '">'
                            + '<td>'
                            + (
                                mejor
                                    ? '<span class="ranking ranking--best">1</span>'
                                    : '<span class="ranking">'
                                        + c.posicion
                                        + '</span>'
                            )
                            + '</td>'
                            + '<td><strong>'
                            + escapeHtml(c.proveedor)
                            + '</strong></td>'
                            + '<td>'
                            + escapeHtml(
                                dinero(
                                    c.precio_unitario,
                                    c.moneda
                                )
                            )
                            + '<small class="cell-secondary">'
                            + escapeHtml(
                                'TC '
                                + numero(
                                    c.tipo_cambio_a_base,
                                    8
                                )
                            )
                            + '</small>'
                            + '</td>'
                            + '<td>'
                            + escapeHtml(c.unidad_cotizada)
                            + '<small class="cell-secondary">'
                            + 'factor '
                            + numero(c.factor_a_unidad_base, 6)
                            + '</small>'
                            + '</td>'
                            + '<td><strong>'
                            + numero(c.precio_normalizado_base, 6)
                            + ' '
                            + escapeHtml(
                                estado.monedaBase
                                    ? estado.monedaBase.codigo
                                    : ''
                            )
                            + '/'
                            + escapeHtml(
                                estado.productoComparador.unidad_base_simbolo
                            )
                            + '</strong>'
                            + (
                                mejor
                                    ? '<small class="cell-secondary best-label">'
                                        + 'Mejor costo actual'
                                        + '</small>'
                                    : ''
                            )
                            + '</td>'
                            + '<td>'
                            + (
                                c.dias_entrega == null
                                    ? 'No definido'
                                    : c.dias_entrega + ' día(s)'
                            )
                            + '</td>'
                            + '<td>'
                            + (
                                c.dias_credito > 0
                                    ? c.dias_credito + ' días'
                                    : 'Contado'
                            )
                            + '</td>'
                            + '<td>'
                            + escapeHtml(
                                c.vigencia_hasta
                                    ? fecha(c.vigencia_hasta)
                                    : 'Sin fecha límite'
                            )
                            + '</td>'
                            + '</tr>';
                    }
                ).join('');
        }

        const pg = datos.paginacion;

        $('textoPaginaComparador').textContent =
            pg.total_registros + ' opción(es)';

        $('paginaComparadorActual').textContent =
            'Página ' + pg.pagina + ' de ' + pg.total_paginas;

        $('btnComparadorAnterior').disabled =
            pg.pagina <= 1;

        $('btnComparadorSiguiente').disabled =
            pg.pagina >= pg.total_paginas;
    }

    /* ==============================================================
       EVENTOS
       ============================================================== */

    document.querySelectorAll('.module-tab').forEach(
        function (tab) {
            tab.addEventListener(
                'click',
                function () {
                    cambiarSeccion(
                        tab.dataset.seccion
                    );
                }
            );
        }
    );

    document.querySelectorAll('[data-cerrar-modal]').forEach(
        function (boton) {
            boton.addEventListener(
                'click',
                function () {
                    cerrarModal(
                        boton.dataset.cerrarModal
                    );
                }
            );
        }
    );

    document.querySelectorAll('.modal-backdrop').forEach(
        function (modal) {
            modal.addEventListener(
                'click',
                function (event) {
                    if (event.target === modal) {
                        cerrarModal(modal.id);
                    }
                }
            );
        }
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            document.querySelectorAll(
                '.modal-backdrop:not([hidden])'
            ).forEach(
                function (modal) {
                    cerrarModal(modal.id);
                }
            );
        }
    );

    $('btnNuevoProveedor')?.addEventListener(
        'click',
        nuevoProveedor
    );

    $('btnNuevaRelacion')?.addEventListener(
        'click',
        nuevaRelacion
    );

    $('formProveedor').addEventListener(
        'submit',
        function (event) {
            guardarProveedor(event).catch(function () {});
        }
    );

    $('formRelacion').addEventListener(
        'submit',
        function (event) {
            guardarRelacion(event).catch(function () {});
        }
    );

    $('formPrecio').addEventListener(
        'submit',
        function (event) {
            guardarPrecio(event).catch(function () {});
        }
    );

    $('proveedorDiasCredito').addEventListener(
        'input',
        actualizarCreditoProveedor
    );

    $('proveedorRfc').addEventListener(
        'input',
        function (event) {
            event.target.value =
                event.target.value
                    .toUpperCase()
                    .replace(/\s+/g, '');
        }
    );

    $('tablaProveedores').addEventListener(
        'click',
        function (event) {
            const boton =
                event.target.closest('[data-action]');

            if (!boton) {
                return;
            }

            const id = Number(boton.dataset.id);

            switch (boton.dataset.action) {
                case 'editar-proveedor':
                    editarProveedor(id).catch(mostrarError);
                    break;

                case 'estado-proveedor':
                    cambiarEstadoProveedor(
                        id,
                        Number(boton.dataset.value)
                    ).catch(mostrarError);
                    break;

                case 'papelera-proveedor':
                    papeleraProveedor(id).catch(mostrarError);
                    break;

                case 'productos-proveedor':
                    const p = estado.proveedores.find(
                        function (item) {
                            return item.id === id;
                        }
                    );

                    if (p && p.activo === 1) {
                        seleccionarProveedorProductos(p);
                        cambiarSeccion('productos');
                    } else {
                        mostrarMensaje(
                            $('mensajePagina'),
                            'Activa el proveedor para administrar nuevos suministros.',
                            'error'
                        );
                    }
                    break;
            }
        }
    );

    $('tablaRelaciones').addEventListener(
        'click',
        function (event) {
            const boton =
                event.target.closest('[data-action]');

            if (!boton) {
                return;
            }

            const id = Number(boton.dataset.id);

            switch (boton.dataset.action) {
                case 'editar-relacion':
                    editarRelacion(id).catch(mostrarError);
                    break;

                case 'estado-relacion':
                    cambiarEstadoRelacion(
                        id,
                        Number(boton.dataset.value)
                    ).catch(mostrarError);
                    break;

                case 'nuevo-precio':
                    abrirNuevoPrecio(id);
                    break;

                case 'historial-precios':
                    abrirHistorial(id);
                    break;
            }
        }
    );

    $('buscarProveedor').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerProveedor);

            estado.timerProveedor = setTimeout(
                function () {
                    estado.paginaProveedor = 1;
                    cargarProveedores().catch(mostrarError);
                },
                350
            );
        }
    );

    [
        'filtroMonedaProveedor',
        'filtroEstadoProveedor'
    ].forEach(
        function (id) {
            $(id).addEventListener(
                'change',
                function () {
                    estado.paginaProveedor = 1;
                    cargarProveedores().catch(mostrarError);
                }
            );
        }
    );

    $('porPaginaProveedor').addEventListener(
        'change',
        function (event) {
            estado.porPaginaProveedor =
                Number(event.target.value);

            estado.paginaProveedor = 1;
            cargarProveedores().catch(mostrarError);
        }
    );

    $('btnProveedorAnterior').addEventListener(
        'click',
        function () {
            if (estado.paginaProveedor <= 1) {
                return;
            }

            estado.paginaProveedor--;
            cargarProveedores().catch(mostrarError);
        }
    );

    $('btnProveedorSiguiente').addEventListener(
        'click',
        function () {
            if (
                estado.paginaProveedor
                >= estado.totalPaginasProveedor
            ) {
                return;
            }

            estado.paginaProveedor++;
            cargarProveedores().catch(mostrarError);
        }
    );

    $('buscarProveedorProductos').addEventListener(
        'input',
        function (event) {
            clearTimeout(
                estado.timerBuscarProveedorProductos
            );

            const q = event.target.value.trim();

            if (
                estado.proveedorProductos
                && q !== (
                    estado.proveedorProductos.codigo
                    + ' · '
                    + estado.proveedorProductos.razon_social
                )
            ) {
                limpiarProveedorProductos();
                $('buscarProveedorProductos').value = q;
            }

            estado.timerBuscarProveedorProductos = setTimeout(
                function () {
                    buscarProveedoresSmart(
                        q,
                        $('resultadosProveedorProductos'),
                        seleccionarProveedorProductos
                    ).catch(mostrarError);
                },
                300
            );
        }
    );

    $('buscarRelacion').addEventListener(
        'input',
        function () {
            clearTimeout(estado.timerRelacion);

            estado.timerRelacion = setTimeout(
                function () {
                    estado.paginaRelacion = 1;
                    cargarRelaciones().catch(mostrarError);
                },
                300
            );
        }
    );

    $('filtroEstadoRelacion').addEventListener(
        'change',
        function () {
            estado.paginaRelacion = 1;
            cargarRelaciones().catch(mostrarError);
        }
    );

    $('porPaginaRelacion').addEventListener(
        'change',
        function (event) {
            estado.porPaginaRelacion =
                Number(event.target.value);

            estado.paginaRelacion = 1;
            cargarRelaciones().catch(mostrarError);
        }
    );

    $('btnRelacionAnterior').addEventListener(
        'click',
        function () {
            if (estado.paginaRelacion <= 1) {
                return;
            }

            estado.paginaRelacion--;
            cargarRelaciones().catch(mostrarError);
        }
    );

    $('btnRelacionSiguiente').addEventListener(
        'click',
        function () {
            if (
                estado.paginaRelacion
                >= estado.totalPaginasRelacion
            ) {
                return;
            }

            estado.paginaRelacion++;
            cargarRelaciones().catch(mostrarError);
        }
    );

    $('buscarProductoRelacion').addEventListener(
        'input',
        function (event) {
            if (event.target.disabled) {
                return;
            }

            clearTimeout(
                estado.timerBuscarProductoRelacion
            );

            const q = event.target.value.trim();

            estado.productoRelacion = null;
            estado.opcionesProductoRelacion = null;
            $('relacionProductoId').value = '';
            $('relacionProductoResumen').hidden = true;
            $('relacionPresentacion').disabled = true;
            $('relacionPresentacion').innerHTML =
                '<option value="">Selecciona primero una materia prima</option>';

            estado.timerBuscarProductoRelacion = setTimeout(
                function () {
                    buscarMateriasPrimasSmart(
                        q,
                        $('resultadosProductoRelacion'),
                        function (p) {
                            seleccionarProductoRelacion(p)
                                .catch(mostrarError);
                        }
                    ).catch(mostrarError);
                },
                300
            );
        }
    );

    $('relacionPresentacion').addEventListener(
        'change',
        actualizarAyudaCompraMinima
    );

    $('precioMoneda').addEventListener(
        'change',
        function () {
            actualizarTipoCambioPrecio()
                .then(actualizarVistaCalculoPrecio)
                .catch(
                    function (error) {
                        mostrarMensaje(
                            $('mensajePrecio'),
                            error.message,
                            'error'
                        );
                    }
                );
        }
    );

    $('precioUnitario').addEventListener(
        'input',
        actualizarVistaCalculoPrecio
    );

    $('precioTipoCambio').addEventListener(
        'input',
        actualizarVistaCalculoPrecio
    );

    $('porPaginaHistorial').addEventListener(
        'change',
        function (event) {
            estado.porPaginaHistorial =
                Number(event.target.value);

            estado.paginaHistorial = 1;
            cargarHistorial().catch(mostrarError);
        }
    );

    $('btnHistorialAnterior').addEventListener(
        'click',
        function () {
            if (estado.paginaHistorial <= 1) {
                return;
            }

            estado.paginaHistorial--;
            cargarHistorial().catch(mostrarError);
        }
    );

    $('btnHistorialSiguiente').addEventListener(
        'click',
        function () {
            if (
                estado.paginaHistorial
                >= estado.totalPaginasHistorial
            ) {
                return;
            }

            estado.paginaHistorial++;
            cargarHistorial().catch(mostrarError);
        }
    );

    if ($('buscarProductoComparador')) {
        $('buscarProductoComparador').addEventListener(
            'input',
            function (event) {
                clearTimeout(
                    estado.timerProductoComparador
                );

                const q = event.target.value.trim();

                estado.productoComparador = null;
                $('productoComparadorSeleccionado').hidden = true;
                $('tablaComparador').innerHTML =
                    '<tr><td colspan="8" class="empty-cell">'
                    + 'Selecciona una materia prima para comparar.'
                    + '</td></tr>';

                estado.timerProductoComparador = setTimeout(
                    function () {
                        buscarMateriasPrimasSmart(
                            q,
                            $('resultadosProductoComparador'),
                            seleccionarProductoComparador
                        ).catch(mostrarError);
                    },
                    300
                );
            }
        );

        $('soloVigentesComparador').addEventListener(
            'change',
            function () {
                if (!estado.productoComparador) {
                    return;
                }

                estado.paginaComparador = 1;
                cargarComparador().catch(mostrarError);
            }
        );

        $('porPaginaComparador').addEventListener(
            'change',
            function (event) {
                estado.porPaginaComparador =
                    Number(event.target.value);

                estado.paginaComparador = 1;

                if (estado.productoComparador) {
                    cargarComparador().catch(mostrarError);
                }
            }
        );

        $('btnComparadorAnterior').addEventListener(
            'click',
            function () {
                if (estado.paginaComparador <= 1) {
                    return;
                }

                estado.paginaComparador--;
                cargarComparador().catch(mostrarError);
            }
        );

        $('btnComparadorSiguiente').addEventListener(
            'click',
            function () {
                if (
                    estado.paginaComparador
                    >= estado.totalPaginasComparador
                ) {
                    return;
                }

                estado.paginaComparador++;
                cargarComparador().catch(mostrarError);
            }
        );
    }

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
