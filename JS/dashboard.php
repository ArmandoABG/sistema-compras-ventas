<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API de esta interfaz
|--------------------------------------------------------------------------
| Igual que en varios módulos del sistema de mantenimiento:
| dashboard.php puede consultar su propio ?dashboard_api=1.
|--------------------------------------------------------------------------
*/

if (isset($_GET['dashboard_api'])) {
    $endpoint =
        __DIR__
        . '/../funciones/dashboard_funciones.php';

    if (!is_file($endpoint)) {
        http_response_code(500);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=utf-8'
            );
        }

        echo json_encode([
            'success' => false,
            'mensaje' =>
                'No se encontró funciones/dashboard_funciones.php.',
        ]);

        exit;
    }

    require $endpoint;
    exit;
}

require_once __DIR__ . '/../inc/seguridad.php';

si_requerir_permiso(
    'dashboard.ver',
    false
);

$tituloPagina = 'Dashboard';

$nombreUsuario = trim((string) (
    $_SESSION['nombre_completo']
    ?? $_SESSION['usuario']
    ?? 'Usuario'
));

$cssGeneral =
    __DIR__
    . '/../css/style_global.css';

$cssModulo =
    __DIR__
    . '/../css/style_dashboard.css';

$versionGeneral = is_file($cssGeneral)
    ? (string) filemtime($cssGeneral)
    : '1';

$versionModulo = is_file($cssModulo)
    ? (string) filemtime($cssModulo)
    : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="robots" content="noindex, nofollow">

    <title>
        Dashboard | Sistema Integral
    </title>

    <link
        rel="stylesheet"
        href="../css/style_global.css?v=<?= si_escapar($versionGeneral) ?>"
    >

    <link
        rel="stylesheet"
        href="../css/style_dashboard.css?v=<?= si_escapar($versionModulo) ?>"
    >
</head>
<body>

<div class="app-shell">

    <?php include __DIR__ . '/../inc/sidebar.php'; ?>

    <div class="app-content">

        <?php include __DIR__ . '/../inc/topbar.php'; ?>

        <main class="page-content">

            <header class="dashboard-heading">
                <div>
                    <p class="dashboard-eyebrow">
                        RESUMEN OPERATIVO
                    </p>

                    <h1>
                        Bienvenido,
                        <?= si_escapar(
                            $nombreUsuario !== ''
                                ? $nombreUsuario
                                : 'Usuario'
                        ) ?>
                    </h1>

                    <p>
                        Información rápida de ventas,
                        compras, inventario y cuentas.
                    </p>
                </div>

                <div class="dashboard-actions">
                    <button
                        type="button"
                        id="btnActualizar"
                    >
                        Actualizar
                    </button>

                    <small id="ultimaActualizacion">
                        Sin actualizar
                    </small>
                </div>
            </header>

            <?php if (
                isset($_GET['acceso'])
                && (string) $_GET['acceso'] === 'denegado'
            ): ?>
                <div class="dashboard-message dashboard-message--warning">
                    Tu cuenta no tiene permiso para entrar
                    a la sección solicitada.
                </div>
            <?php endif; ?>

            <div
                id="mensajeDashboard"
                class="dashboard-message dashboard-message--error"
                hidden
            ></div>

            <section class="kpi-grid">

                <article class="kpi-card">
                    <span>Ventas de hoy</span>
                    <strong id="kpiVentasHoy">0</strong>
                    <small id="detalleVentasHoy">
                        Sin ventas confirmadas
                    </small>
                </article>

                <article class="kpi-card">
                    <span>Compras por recibir</span>
                    <strong id="kpiCompras">0</strong>
                    <small>Pendientes o parciales</small>
                </article>

                <article class="kpi-card">
                    <span>Inventario crítico</span>
                    <strong id="kpiInventario">0</strong>
                    <small>En mínimo o por debajo</small>
                </article>

                <article class="kpi-card">
                    <span>Cobros vencidos</span>
                    <strong id="kpiCobros">0</strong>
                    <small>Cuentas de clientes</small>
                </article>

                <article class="kpi-card">
                    <span>Pagos vencidos</span>
                    <strong id="kpiPagos">0</strong>
                    <small>Cuentas a proveedores</small>
                </article>

                <article class="kpi-card">
                    <span>Notificaciones</span>
                    <strong id="kpiNotificaciones">0</strong>
                    <small>Pendientes de leer</small>
                </article>

                <article class="kpi-card">
                    <span>Índice de merma</span>
                    <strong id="kpiMerma">0.00%</strong>
                    <small id="detalleMerma">Costo de merma del mes</small>
                </article>

            </section>

            <section class="dashboard-two-columns">

                <article class="dashboard-panel">
                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Cuentas por cobrar</h2>
                            <p>Saldos pendientes por moneda</p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Moneda</th>
                                    <th>Cuentas</th>
                                    <th>Pendiente</th>
                                    <th>Vencido</th>
                                </tr>
                            </thead>

                            <tbody id="tablaCobrar">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="dashboard-panel">
                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Cuentas por pagar</h2>
                            <p>Saldos pendientes por moneda</p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Moneda</th>
                                    <th>Cuentas</th>
                                    <th>Pendiente</th>
                                    <th>Vencido</th>
                                </tr>
                            </thead>

                            <tbody id="tablaPagar">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

            </section>

            <section class="dashboard-panel">

                <header class="dashboard-panel__head">
                    <div>
                        <h2>Inventario crítico</h2>
                        <p>
                            Primeros 10 productos
                            que requieren atención
                        </p>
                    </div>
                </header>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Tipo</th>
                                <th>Almacén</th>
                                <th>Físico</th>
                                <th>Reservado</th>
                                <th>Disponible</th>
                                <th>Mínimo</th>
                            </tr>
                        </thead>

                        <tbody id="tablaInventario">
                            <tr>
                                <td colspan="8">
                                    Cargando...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="dashboard-two-columns">

                <article class="dashboard-panel">

                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Top 5 del mes</h2>
                            <p>
                                Productos con mayor
                                frecuencia de venta
                            </p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Ventas</th>
                                    <th>Cantidad base</th>
                                </tr>
                            </thead>

                            <tbody id="tablaTopProductos">
                                <tr>
                                    <td colspan="3">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="dashboard-panel">

                    <header class="dashboard-panel__head">
                        <div>
                            <h2>Movimientos recientes</h2>
                            <p>
                                Últimas acciones
                                registradas en auditoría
                            </p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Módulo</th>
                                </tr>
                            </thead>

                            <tbody id="tablaMovimientos">
                                <tr>
                                    <td colspan="4">
                                        Cargando...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

            </section>

            <section class="dashboard-panel">
                <header class="dashboard-panel__head">
                    <div>
                        <h2>Top 5 clientes del mes</h2>
                        <p>Clientes con mayor volumen de compra normalizado a moneda base</p>
                    </div>
                </header>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Nivel</th>
                                <th>Ventas</th>
                                <th>Total base</th>
                                <th>Descuento prom.</th>
                            </tr>
                        </thead>
                        <tbody id="tablaTopClientes">
                            <tr>
                                <td colspan="5">Cargando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
(function () {
    'use strict';

    const endpoint =
        '?dashboard_api=1&accion=RESUMEN';

    const botonActualizar =
        document.getElementById('btnActualizar');

    const mensaje =
        document.getElementById('mensajeDashboard');

    const ultimaActualizacion =
        document.getElementById('ultimaActualizacion');

    function numero(valor, decimales) {
        const cantidadDecimales =
            typeof decimales === 'number'
                ? decimales
                : 0;

        const n = Number(valor || 0);

        return new Intl.NumberFormat(
            'es-MX',
            {
                minimumFractionDigits:
                    cantidadDecimales,

                maximumFractionDigits:
                    cantidadDecimales
            }
        ).format(
            Number.isFinite(n) ? n : 0
        );
    }

    function dinero(valor, moneda) {
        const n = Number(valor || 0);
        const codigo = moneda || 'MXN';

        try {
            return new Intl.NumberFormat(
                'es-MX',
                {
                    style: 'currency',
                    currency: codigo,
                    maximumFractionDigits: 2
                }
            ).format(
                Number.isFinite(n) ? n : 0
            );
        } catch (error) {
            return numero(n, 2) + ' ' + codigo;
        }
    }

    function escapeHtml(valor) {
        return String(
            valor == null ? '' : valor
        )
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function filaVacia(
        tbody,
        columnas,
        texto
    ) {
        tbody.innerHTML =
            '<tr>'
            + '<td colspan="'
            + columnas
            + '" class="empty-cell">'
            + escapeHtml(texto)
            + '</td>'
            + '</tr>';
    }

    function renderKpis(datos) {
        const kpis = datos.kpis || {};

        document.getElementById(
            'kpiVentasHoy'
        ).textContent =
            numero(kpis.ventas_hoy);

        document.getElementById(
            'kpiCompras'
        ).textContent =
            numero(kpis.compras_por_recibir);

        document.getElementById(
            'kpiInventario'
        ).textContent =
            numero(kpis.stock_critico);

        document.getElementById(
            'kpiCobros'
        ).textContent =
            numero(kpis.cobros_vencidos);

        document.getElementById(
            'kpiPagos'
        ).textContent =
            numero(kpis.pagos_vencidos);

        document.getElementById(
            'kpiNotificaciones'
        ).textContent =
            numero(kpis.notificaciones);

        const merma = datos.merma_mes || {};
        document.getElementById('kpiMerma').textContent =
            numero(merma.indice_pct || 0, 2) + '%';
        document.getElementById('detalleMerma').textContent =
            'Costo base: ' + dinero(merma.costo_merma_base || 0, 'MXN');

        const ventas =
            datos.ventas_hoy_monedas || [];

        const detalle =
            document.getElementById(
                'detalleVentasHoy'
            );

        detalle.textContent =
            ventas.length
                ? ventas.map(
                    function (item) {
                        return item.codigo
                            + ': '
                            + dinero(
                                item.importe,
                                item.codigo
                            );
                    }
                ).join(' · ')
                : 'Sin ventas confirmadas';
    }

    function renderCuentas(
        id,
        filas
    ) {
        const tbody =
            document.getElementById(id);

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                4,
                'Sin cuentas pendientes.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr>'
                    + '<td><strong>'
                    + escapeHtml(item.codigo)
                    + '</strong></td>'
                    + '<td>'
                    + numero(item.cuentas)
                    + '</td>'
                    + '<td>'
                    + dinero(
                        item.saldo_pendiente,
                        item.codigo
                    )
                    + '</td>'
                    + '<td>'
                    + dinero(
                        item.saldo_vencido,
                        item.codigo
                    )
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderInventario(filas) {
        const tbody =
            document.getElementById(
                'tablaInventario'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                8,
                'No hay productos en nivel crítico.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                const tipo =
                    item.tipo === 'MATERIA_PRIMA'
                        ? 'Materia prima'
                        : 'Producto terminado';

                return ''
                    + '<tr>'
                    + '<td>'
                    + escapeHtml(item.sku)
                    + '</td>'
                    + '<td><strong>'
                    + escapeHtml(item.producto)
                    + '</strong></td>'
                    + '<td>'
                    + escapeHtml(tipo)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.almacen)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.existencia_fisica,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_reservada,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_disponible,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.stock_minimo,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderTopProductos(filas) {
        const tbody =
            document.getElementById(
                'tablaTopProductos'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                3,
                'Todavía no hay ventas confirmadas este mes.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr>'
                    + '<td>'
                    + '<strong>'
                    + escapeHtml(item.producto)
                    + '</strong>'
                    + '<small class="cell-secondary">'
                    + escapeHtml(item.sku)
                    + '</small>'
                    + '</td>'
                    + '<td>'
                    + numero(item.operaciones)
                    + '</td>'
                    + '<td>'
                    + numero(
                        item.cantidad_base,
                        3
                    )
                    + ' '
                    + escapeHtml(item.unidad)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    function renderTopClientes(filas) {
        const tbody = document.getElementById('tablaTopClientes');

        if (!filas || !filas.length) {
            filaVacia(tbody, 5, 'Todavía no hay ventas de clientes este mes.');
            return;
        }

        tbody.innerHTML = filas.map(function (item) {
            return ''
                + '<tr>'
                + '<td><strong>' + escapeHtml(item.cliente) + '</strong>'
                + '<small class="cell-secondary">' + escapeHtml(item.codigo || '') + '</small></td>'
                + '<td>' + escapeHtml(item.nivel || 'General') + '</td>'
                + '<td>' + numero(item.operaciones) + '</td>'
                + '<td>' + dinero(item.total_base, 'MXN') + '</td>'
                + '<td>' + numero(item.descuento_promedio_pct, 2) + '%</td>'
                + '</tr>';
        }).join('');
    }

    function renderMovimientos(filas) {
        const tbody =
            document.getElementById(
                'tablaMovimientos'
            );

        if (!filas || !filas.length) {
            filaVacia(
                tbody,
                4,
                'Todavía no hay movimientos registrados.'
            );
            return;
        }

        tbody.innerHTML = filas.map(
            function (item) {
                return ''
                    + '<tr title="'
                    + escapeHtml(
                        item.descripcion || ''
                    )
                    + '">'
                    + '<td>'
                    + escapeHtml(item.fecha_hora)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.usuario)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.accion)
                    + '</td>'
                    + '<td>'
                    + escapeHtml(item.modulo)
                    + '</td>'
                    + '</tr>';
            }
        ).join('');
    }

    async function cargarDashboard() {
        mensaje.hidden = true;

        botonActualizar.disabled = true;

        const textoOriginal =
            botonActualizar.textContent;

        botonActualizar.textContent =
            'Actualizando...';

        try {
            const respuesta =
                await fetch(
                    endpoint,
                    {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest'
                        }
                    }
                );

            const texto =
                await respuesta.text();

            let datos;

            try {
                datos = JSON.parse(texto);
            } catch (error) {
                throw new Error(
                    'El servidor devolvió una respuesta no válida.'
                );
            }

            if (
                !respuesta.ok
                || datos.success !== true
            ) {
                if (
                    datos.sesion_expirada
                    && datos.redirect
                ) {
                    window.location.href =
                        datos.redirect;

                    return;
                }

                throw new Error(
                    datos.mensaje
                    || 'No fue posible cargar el dashboard.'
                );
            }

            renderKpis(datos);

            renderCuentas(
                'tablaCobrar',
                datos.resumen_cobrar
            );

            renderCuentas(
                'tablaPagar',
                datos.resumen_pagar
            );

            renderInventario(
                datos.inventario_critico
            );

            renderTopProductos(
                datos.top_productos
            );

            renderTopClientes(
                datos.top_clientes || []
            );

            renderMovimientos(
                datos.movimientos_recientes
            );

            ultimaActualizacion.textContent =
                'Actualizado: '
                + (datos.fecha_servidor || '');

        } catch (error) {
            mensaje.textContent =
                error.message
                || 'Ocurrió un error inesperado.';

            mensaje.hidden = false;

        } finally {
            botonActualizar.disabled = false;

            botonActualizar.textContent =
                textoOriginal;
        }
    }

    botonActualizar.addEventListener(
        'click',
        cargarDashboard
    );

    cargarDashboard();
})();
</script>

</body>
</html>
