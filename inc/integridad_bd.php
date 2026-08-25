<?php

declare(strict_types=1);

/**
 * Mantiene alineados los catálogos de seguridad con la base oficial.
 * No elimina ni fuerza personalizaciones de Vendedor/Supervisor. Los paquetes
 * iniciales se usan solo al crear/recuperar un rol vacío; los cambios de versión
 * que agregan permisos se aplican mediante su SQL de actualización.
 */

function si_roles_oficiales(): array
{
    return [
        ['ADMINISTRADOR', 'Administrador', 'Acceso total al sistema.'],
        ['VENDEDOR', 'Vendedor', 'Ventas, clientes, cotizaciones y apartados.'],
        ['SUPERVISOR_ALMACEN', 'Supervisor de Almacén', 'Compras, inventario, recepciones, producción y validación de salida.'],
    ];
}

function si_permisos_oficiales(): array
{
    return [
        ['dashboard.ver', 'dashboard', 'Ver dashboard'],
        ['usuarios.ver', 'seguridad', 'Ver usuarios'],
        ['usuarios.crear', 'seguridad', 'Crear usuarios'],
        ['usuarios.editar', 'seguridad', 'Editar usuarios'],
        ['usuarios.desactivar', 'seguridad', 'Desactivar usuarios'],
        ['roles.administrar', 'seguridad', 'Administrar roles y permisos'],
        ['productos.ver', 'catalogos', 'Ver productos'],
        ['productos.administrar', 'catalogos', 'Administrar productos'],
        ['proveedores.ver', 'proveedores', 'Ver proveedores'],
        ['proveedores.administrar', 'proveedores', 'Administrar proveedores'],
        ['proveedores.comparar_precios', 'proveedores', 'Comparar precios de proveedores'],
        ['compras.ver', 'compras', 'Ver compras'],
        ['compras.crear', 'compras', 'Crear compras'],
        ['compras.cancelar', 'compras', 'Cancelar compras'],
        ['recepciones.ver', 'compras', 'Ver recepciones'],
        ['recepciones.confirmar', 'compras', 'Confirmar recepciones'],
        ['cuentas_pagar.ver', 'cuentas_por_pagar', 'Ver cuentas por pagar'],
        ['cuentas_pagar.pagar', 'cuentas_por_pagar', 'Registrar pagos a proveedores'],
        ['clientes.ver', 'clientes', 'Ver clientes'],
        ['clientes.administrar', 'clientes', 'Administrar clientes'],
        ['cotizaciones.ver', 'ventas', 'Ver cotizaciones'],
        ['cotizaciones.crear', 'ventas', 'Crear cotizaciones'],
        ['apartados.ver', 'ventas', 'Ver apartados'],
        ['apartados.crear', 'ventas', 'Crear apartados'],
        ['ventas.ver', 'ventas', 'Ver ventas'],
        ['ventas.crear', 'ventas', 'Crear ventas'],
        ['ventas.cancelar', 'ventas', 'Cancelar ventas'],
        ['cuentas_cobrar.ver', 'cuentas_por_cobrar', 'Ver cuentas por cobrar'],
        ['cuentas_cobrar.cobrar', 'cuentas_por_cobrar', 'Registrar cobros'],
        ['devoluciones.ver', 'devoluciones', 'Ver devoluciones y regularizaciones'],
        ['devoluciones.venta', 'devoluciones', 'Registrar devoluciones de cliente'],
        ['devoluciones.compra', 'devoluciones', 'Registrar devoluciones a proveedor'],
        ['devoluciones.regularizar', 'devoluciones', 'Liquidar regularizaciones financieras de devoluciones'],
        ['inventario.ver', 'inventario', 'Ver inventario'],
        ['inventario.kardex', 'inventario', 'Consultar Kardex'],
        ['inventario.ajustar', 'inventario', 'Realizar ajustes'],
        ['inventario.mermas', 'inventario', 'Registrar mermas'],
        ['inventario.transferir', 'inventario', 'Transferir entre almacenes'],
        ['inventario.configurar_stock', 'inventario', 'Configurar stock mínimo y punto de reorden'],
        ['almacenes.ver', 'inventario', 'Ver almacenes'],
        ['almacenes.administrar', 'inventario', 'Administrar almacenes'],
        ['produccion.ver', 'produccion', 'Ver producción'],
        ['produccion.registrar', 'produccion', 'Registrar producción'],
        ['qr.verificar', 'qr', 'Verificar QR de salida'],
        ['reportes.ver', 'reportes', 'Ver reportes'],
        ['contabilidad.exportar', 'contabilidad', 'Exportar información contable'],
        ['auditoria.ver', 'auditoria', 'Ver auditoría'],
        ['papelera.ver', 'papelera', 'Ver papelera'],
        ['papelera.restaurar', 'papelera', 'Restaurar registros'],
        ['configuracion.administrar', 'configuracion', 'Administrar configuración'],
    ];
}

function si_permisos_vendedor_iniciales(): array
{
    return [
        'dashboard.ver', 'productos.ver', 'clientes.ver', 'clientes.administrar',
        'cotizaciones.ver', 'cotizaciones.crear', 'apartados.ver', 'apartados.crear',
        'ventas.ver', 'ventas.crear', 'cuentas_cobrar.ver', 'cuentas_cobrar.cobrar',
        'devoluciones.ver', 'devoluciones.venta', 'reportes.ver',
    ];
}

function si_permisos_supervisor_iniciales(): array
{
    return [
        'dashboard.ver', 'productos.ver', 'proveedores.ver', 'proveedores.comparar_precios',
        'compras.ver', 'compras.crear', 'recepciones.ver', 'recepciones.confirmar',
        'inventario.ver', 'inventario.kardex', 'inventario.ajustar', 'inventario.mermas', 'inventario.transferir', 'inventario.configurar_stock',
        'almacenes.ver', 'produccion.ver', 'produccion.registrar', 'qr.verificar',
        'devoluciones.ver', 'devoluciones.compra', 'reportes.ver',
    ];
}

function si_sincronizar_seguridad_base(PDO $conexion): void
{
    static $ejecutado = false;
    if ($ejecutado) {
        return;
    }

    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }

    try {
        $stmtRol = $conexion->prepare(
            "INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, :descripcion, 1, 1)
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                descripcion = VALUES(descripcion),
                es_sistema = 1,
                activo = 1"
        );

        foreach (si_roles_oficiales() as $rol) {
            $stmtRol->execute([
                ':codigo' => $rol[0],
                ':nombre' => $rol[1],
                ':descripcion' => $rol[2],
            ]);
        }

        $stmtPermiso = $conexion->prepare(
            "INSERT INTO permisos (codigo, modulo, nombre, descripcion)
             VALUES (:codigo, :modulo, :nombre, NULL)
             ON DUPLICATE KEY UPDATE
                modulo = VALUES(modulo),
                nombre = VALUES(nombre)"
        );

        foreach (si_permisos_oficiales() as $permiso) {
            $stmtPermiso->execute([
                ':codigo' => $permiso[0],
                ':modulo' => $permiso[1],
                ':nombre' => $permiso[2],
            ]);
        }

        // Administrador siempre conserva todos los permisos existentes.
        $conexion->exec(
            "INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permisos p
             WHERE r.codigo = 'ADMINISTRADOR'"
        );

        // Si ninguna cuenta quedó como Administrador, intentamos recuperar
        // la cuenta que fue creada originalmente como ADMIN_INICIAL_CREADO.
        si_recuperar_admin_desde_auditoria($conexion);

        // Solo si el rol quedó completamente vacío se restaura el paquete inicial.
        si_sembrar_rol_si_vacio($conexion, 'VENDEDOR', si_permisos_vendedor_iniciales());
        si_sembrar_rol_si_vacio($conexion, 'SUPERVISOR_ALMACEN', si_permisos_supervisor_iniciales());

        if ($propia) {
            $conexion->commit();
        }

        $ejecutado = true;
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

function si_sembrar_rol_si_vacio(PDO $conexion, string $codigoRol, array $permisos): void
{
    $stmt = $conexion->prepare(
        "SELECT COUNT(*)
         FROM roles_permisos rp
         INNER JOIN roles r ON r.id = rp.rol_id
         WHERE r.codigo = :codigo"
    );
    $stmt->execute([':codigo' => $codigoRol]);

    if ((int) $stmt->fetchColumn() > 0 || $permisos === []) {
        return;
    }

    $marcas = implode(',', array_fill(0, count($permisos), '?'));
    $sql = "INSERT IGNORE INTO roles_permisos (rol_id, permiso_id)
            SELECT r.id, p.id
            FROM roles r
            INNER JOIN permisos p ON p.codigo IN ($marcas)
            WHERE r.codigo = ?";

    $valores = array_values($permisos);
    $valores[] = $codigoRol;
    $conexion->prepare($sql)->execute($valores);
}


function si_recuperar_admin_desde_auditoria(PDO $conexion): bool
{
    $admins = (int) $conexion->query(
        "SELECT COUNT(DISTINCT u.id)
         FROM usuarios u
         INNER JOIN usuarios_roles ur ON ur.usuario_id = u.id
         INNER JOIN roles r ON r.id = ur.rol_id
         WHERE u.deleted_at IS NULL
           AND u.activo = 1
           AND r.activo = 1
           AND r.codigo = 'ADMINISTRADOR'"
    )->fetchColumn();

    if ($admins > 0) {
        return false;
    }

    $stmt = $conexion->query(
        "SELECT a.entidad_id
         FROM auditoria a
         INNER JOIN usuarios u ON u.id = a.entidad_id
         WHERE a.accion = 'ADMIN_INICIAL_CREADO'
           AND a.entidad_tabla = 'usuarios'
           AND a.entidad_id IS NOT NULL
           AND u.deleted_at IS NULL
           AND u.activo = 1
         ORDER BY a.id ASC
         LIMIT 1"
    );

    $usuarioId = (int) $stmt->fetchColumn();
    if ($usuarioId <= 0) {
        return false;
    }

    $rolId = (int) $conexion->query(
        "SELECT id FROM roles
         WHERE codigo = 'ADMINISTRADOR' AND activo = 1
         LIMIT 1"
    )->fetchColumn();

    if ($rolId <= 0) {
        return false;
    }

    $stmt = $conexion->prepare(
        "INSERT IGNORE INTO usuarios_roles (usuario_id, rol_id)
         VALUES (:usuario_id, :rol_id)"
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':rol_id' => $rolId,
    ]);

    return true;
}

/**
 * Recuperación controlada: solo si existe UN único usuario activo y ningún
 * Administrador activo. Con dos o más usuarios no eleva a nadie.
 */
function si_recuperar_administrador_unico(PDO $conexion, int $usuarioId): bool
{
    $totalActivos = (int) $conexion->query(
        "SELECT COUNT(*)
         FROM usuarios
         WHERE deleted_at IS NULL AND activo = 1"
    )->fetchColumn();

    if ($totalActivos !== 1) {
        return false;
    }

    $admins = (int) $conexion->query(
        "SELECT COUNT(DISTINCT u.id)
         FROM usuarios u
         INNER JOIN usuarios_roles ur ON ur.usuario_id = u.id
         INNER JOIN roles r ON r.id = ur.rol_id
         WHERE u.deleted_at IS NULL
           AND u.activo = 1
           AND r.activo = 1
           AND r.codigo = 'ADMINISTRADOR'"
    )->fetchColumn();

    if ($admins > 0) {
        return false;
    }

    $rolId = (int) $conexion->query(
        "SELECT id FROM roles
         WHERE codigo = 'ADMINISTRADOR' AND activo = 1
         LIMIT 1"
    )->fetchColumn();

    if ($rolId <= 0) {
        return false;
    }

    $stmt = $conexion->prepare(
        "INSERT IGNORE INTO usuarios_roles (usuario_id, rol_id)
         VALUES (:usuario_id, :rol_id)"
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':rol_id' => $rolId,
    ]);

    return true;
}
