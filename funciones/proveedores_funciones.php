<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('proveedores.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_PROVEEDORES')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'LISTAR_PROVEEDORES':
                prov_listar_proveedores($conexion);
                break;

            case 'DETALLE_PROVEEDOR':
                prov_detalle_proveedor($conexion);
                break;

            case 'CATALOGOS':
                prov_catalogos($conexion);
                break;

            case 'BUSCAR_PROVEEDORES':
                prov_buscar_proveedores($conexion);
                break;

            case 'BUSCAR_MATERIAS_PRIMAS':
                prov_buscar_materias_primas($conexion);
                break;

            case 'OPCIONES_PRODUCTO':
                prov_opciones_producto($conexion);
                break;

            case 'LISTAR_RELACIONES':
                prov_listar_relaciones($conexion);
                break;

            case 'DETALLE_RELACION':
                prov_detalle_relacion($conexion);
                break;

            case 'LISTAR_PRECIOS':
                prov_listar_precios($conexion);
                break;

            case 'TIPO_CAMBIO':
                prov_tipo_cambio($conexion);
                break;

            case 'COMPARADOR':
                prov_comparador($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    switch ($accion) {
        case 'GUARDAR_PROVEEDOR':
            prov_requerir_admin();
            prov_guardar_proveedor($conexion);
            break;

        case 'CAMBIAR_ESTADO_PROVEEDOR':
            prov_requerir_admin();
            prov_cambiar_estado_proveedor($conexion);
            break;

        case 'PAPELERA_PROVEEDOR':
            prov_requerir_admin();
            prov_papelera_proveedor($conexion);
            break;

        case 'GUARDAR_RELACION':
            prov_requerir_admin();
            prov_guardar_relacion($conexion);
            break;

        case 'CAMBIAR_ESTADO_RELACION':
            prov_requerir_admin();
            prov_cambiar_estado_relacion($conexion);
            break;

        case 'REGISTRAR_PRECIO':
            prov_requerir_admin();
            prov_registrar_precio($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'PROV-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][PROVEEDORES][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'Ya existe un registro con esos datos o el registro está relacionado con otra operación.',
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

    $referencia = 'PROV-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][PROVEEDORES] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar proveedores.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   DIRECTORIO
   ========================================================================= */

function prov_listar_proveedores(PDO $conexion): void
{
    $pagina = prov_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prov_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $busqueda = prov_texto($_GET['busqueda'] ?? '', 180);
    $estado = strtoupper(prov_texto($_GET['estado'] ?? 'TODOS', 20));
    $monedaId = prov_entero_rango($_GET['moneda_id'] ?? 0, 0, PHP_INT_MAX, 0);

    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS'], true)) {
        $estado = 'TODOS';
    }

    $where = ['p.deleted_at IS NULL'];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(
            p.codigo = :codigo_exacto
            OR p.codigo LIKE :codigo_prefijo
            OR p.razon_social LIKE :razon
            OR p.nombre_comercial LIKE :comercial
            OR p.rfc LIKE :rfc
            OR p.contacto_nombre LIKE :contacto
        )";

        $params[':codigo_exacto'] = strtoupper($busqueda);
        $params[':codigo_prefijo'] = strtoupper($busqueda) . '%';
        $params[':razon'] = '%' . $busqueda . '%';
        $params[':comercial'] = '%' . $busqueda . '%';
        $params[':rfc'] = strtoupper($busqueda) . '%';
        $params[':contacto'] = '%' . $busqueda . '%';
    }

    if ($estado === 'ACTIVOS') {
        $where[] = 'p.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'p.activo = 0';
    }

    if ($monedaId > 0) {
        $where[] = 'p.moneda_default_id = :moneda_id';
        $params[':moneda_id'] = $monedaId;
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM proveedores p
         WHERE {$whereSql}"
    );

    prov_bind($stmtTotal, $params);
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
            p.codigo,
            p.razon_social,
            p.nombre_comercial,
            p.rfc,
            p.contacto_nombre,
            p.telefono,
            p.correo,
            p.moneda_default_id,
            m.codigo AS moneda_codigo,
            m.simbolo AS moneda_simbolo,
            p.dias_credito,
            p.limite_credito,
            p.activo,
            p.created_at,
            (
                SELECT COUNT(*)
                FROM proveedores_productos pp
                WHERE pp.proveedor_id = p.id
                  AND pp.activo = 1
            ) AS productos_activos,
            (
                SELECT COUNT(*)
                FROM cuentas_por_pagar cp
                WHERE cp.proveedor_id = p.id
                  AND cp.estado <> 'CANCELADA'
                  AND cp.saldo_pendiente > 0
            ) AS cuentas_pendientes
         FROM proveedores p
         LEFT JOIN monedas m
            ON m.id = p.moneda_default_id
         WHERE {$whereSql}
         ORDER BY
            p.activo DESC,
            p.razon_social ASC,
            p.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    prov_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['moneda_default_id'] = $fila['moneda_default_id'] !== null
            ? (int) $fila['moneda_default_id']
            : null;
        $fila['dias_credito'] = (int) $fila['dias_credito'];
        $fila['limite_credito'] = $fila['limite_credito'] !== null
            ? (float) $fila['limite_credito']
            : null;
        $fila['activo'] = (int) $fila['activo'];
        $fila['productos_activos'] = (int) $fila['productos_activos'];
        $fila['cuentas_pendientes'] = (int) $fila['cuentas_pendientes'];
    }
    unset($fila);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(activo = 1) AS activos,
            SUM(activo = 0) AS inactivos,
            SUM(dias_credito > 0) AS con_credito
         FROM proveedores
         WHERE deleted_at IS NULL"
    )->fetch();

    si_responder_json(
        true,
        'Proveedores cargados.',
        [
            'proveedores' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => [
                'total' => (int) ($resumen['total'] ?? 0),
                'activos' => (int) ($resumen['activos'] ?? 0),
                'inactivos' => (int) ($resumen['inactivos'] ?? 0),
                'con_credito' => (int) ($resumen['con_credito'] ?? 0),
            ],
        ]
    );
}

function prov_detalle_proveedor(PDO $conexion): void
{
    $id = prov_id($_GET['id'] ?? null, 'proveedor');

    $stmt = $conexion->prepare(
        "SELECT
            id,
            codigo,
            razon_social,
            nombre_comercial,
            rfc,
            contacto_nombre,
            telefono,
            correo,
            calle,
            numero_exterior,
            numero_interior,
            colonia,
            municipio,
            estado,
            codigo_postal,
            pais,
            moneda_default_id,
            dias_credito,
            limite_credito,
            observaciones,
            activo,
            created_at,
            updated_at
         FROM proveedores
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $proveedor = $stmt->fetch();

    if (!$proveedor) {
        si_responder_json(false, 'No se encontró el proveedor.', [], 404);
    }

    $proveedor['id'] = (int) $proveedor['id'];
    $proveedor['moneda_default_id'] = $proveedor['moneda_default_id'] !== null
        ? (int) $proveedor['moneda_default_id']
        : null;
    $proveedor['dias_credito'] = (int) $proveedor['dias_credito'];
    $proveedor['limite_credito'] = $proveedor['limite_credito'] !== null
        ? (float) $proveedor['limite_credito']
        : null;
    $proveedor['activo'] = (int) $proveedor['activo'];

    si_responder_json(true, 'Proveedor encontrado.', ['proveedor' => $proveedor]);
}

function prov_guardar_proveedor(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['proveedor_id'] ?? ''));
    $id = $idTexto === '' ? 0 : prov_id($idTexto, 'proveedor');
    $esNuevo = $id === 0;

    $razonSocial = prov_requerido(
        $_POST['razon_social'] ?? '',
        'La razón social es obligatoria.',
        180
    );

    $nombreComercial = prov_nullable($_POST['nombre_comercial'] ?? '', 180);

    $rfc = prov_nullable($_POST['rfc'] ?? '', 20);
    if ($rfc !== null) {
        $rfc = strtoupper(preg_replace('/\s+/', '', $rfc) ?? $rfc);

        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u', $rfc)) {
            si_responder_json(
                false,
                'El RFC no tiene un formato válido. Si el proveedor no cuenta con RFC, déjalo vacío.',
                ['campo' => 'rfc'],
                422
            );
        }
    }

    $contacto = prov_nullable($_POST['contacto_nombre'] ?? '', 160);
    $telefono = prov_nullable($_POST['telefono'] ?? '', 40);
    $correo = prov_nullable($_POST['correo'] ?? '', 180);

    if ($correo !== null && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
        si_responder_json(false, 'El correo electrónico no es válido.', ['campo' => 'correo'], 422);
    }

    $calle = prov_nullable($_POST['calle'] ?? '', 180);
    $numeroExterior = prov_nullable($_POST['numero_exterior'] ?? '', 30);
    $numeroInterior = prov_nullable($_POST['numero_interior'] ?? '', 30);
    $colonia = prov_nullable($_POST['colonia'] ?? '', 120);
    $municipio = prov_nullable($_POST['municipio'] ?? '', 120);
    $estadoDomicilio = prov_nullable($_POST['estado_domicilio'] ?? '', 120);
    $codigoPostal = prov_nullable($_POST['codigo_postal'] ?? '', 15);
    $pais = prov_texto($_POST['pais'] ?? 'México', 80);
    if ($pais === '') {
        $pais = 'México';
    }

    $monedaId = prov_id($_POST['moneda_default_id'] ?? null, 'moneda');
    prov_validar_moneda_activa($conexion, $monedaId);

    $diasCredito = prov_entero_rango($_POST['dias_credito'] ?? 0, 0, 3650, 0);

    $limiteCreditoTexto = trim((string) ($_POST['limite_credito'] ?? ''));
    $limiteCredito = null;

    if ($diasCredito > 0 && $limiteCreditoTexto !== '') {
        $limiteCredito = prov_decimal_no_negativo(
            $limiteCreditoTexto,
            'El límite de crédito no es válido.'
        );
    }

    $observaciones = prov_nullable($_POST['observaciones'] ?? '', 5000);

    $conexion->beginTransaction();

    $anterior = null;

    if (!$esNuevo) {
        $anterior = prov_bloquear_proveedor($conexion, $id);

        if (!$anterior) {
            prov_cancelar($conexion, 'El proveedor ya no existe.', 404);
        }
    }

    prov_validar_rfc_unico($conexion, $rfc, $id);

    if ($esNuevo) {
        $codigoTemporal = 'TMP-' . bin2hex(random_bytes(12));

        $stmt = $conexion->prepare(
            "INSERT INTO proveedores
                (
                    codigo,
                    razon_social,
                    nombre_comercial,
                    rfc,
                    contacto_nombre,
                    telefono,
                    correo,
                    calle,
                    numero_exterior,
                    numero_interior,
                    colonia,
                    municipio,
                    estado,
                    codigo_postal,
                    pais,
                    moneda_default_id,
                    dias_credito,
                    limite_credito,
                    observaciones,
                    activo,
                    created_by
                )
             VALUES
                (
                    :codigo,
                    :razon_social,
                    :nombre_comercial,
                    :rfc,
                    :contacto_nombre,
                    :telefono,
                    :correo,
                    :calle,
                    :numero_exterior,
                    :numero_interior,
                    :colonia,
                    :municipio,
                    :estado,
                    :codigo_postal,
                    :pais,
                    :moneda_default_id,
                    :dias_credito,
                    :limite_credito,
                    :observaciones,
                    1,
                    :created_by
                )"
        );

        $stmt->execute([
            ':codigo' => $codigoTemporal,
            ':razon_social' => $razonSocial,
            ':nombre_comercial' => $nombreComercial,
            ':rfc' => $rfc,
            ':contacto_nombre' => $contacto,
            ':telefono' => $telefono,
            ':correo' => $correo,
            ':calle' => $calle,
            ':numero_exterior' => $numeroExterior,
            ':numero_interior' => $numeroInterior,
            ':colonia' => $colonia,
            ':municipio' => $municipio,
            ':estado' => $estadoDomicilio,
            ':codigo_postal' => $codigoPostal,
            ':pais' => $pais,
            ':moneda_default_id' => $monedaId,
            ':dias_credito' => $diasCredito,
            ':limite_credito' => $limiteCredito,
            ':observaciones' => $observaciones,
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);

        $id = (int) $conexion->lastInsertId();
        $codigo = prov_generar_codigo_proveedor($id);

        $conexion->prepare(
            "UPDATE proveedores
             SET codigo = :codigo
             WHERE id = :id"
        )->execute([
            ':codigo' => $codigo,
            ':id' => $id,
        ]);

    } else {
        $codigo = (string) $anterior['codigo'];

        $stmt = $conexion->prepare(
            "UPDATE proveedores
             SET
                razon_social = :razon_social,
                nombre_comercial = :nombre_comercial,
                rfc = :rfc,
                contacto_nombre = :contacto_nombre,
                telefono = :telefono,
                correo = :correo,
                calle = :calle,
                numero_exterior = :numero_exterior,
                numero_interior = :numero_interior,
                colonia = :colonia,
                municipio = :municipio,
                estado = :estado,
                codigo_postal = :codigo_postal,
                pais = :pais,
                moneda_default_id = :moneda_default_id,
                dias_credito = :dias_credito,
                limite_credito = :limite_credito,
                observaciones = :observaciones
             WHERE id = :id
               AND deleted_at IS NULL"
        );

        $stmt->execute([
            ':razon_social' => $razonSocial,
            ':nombre_comercial' => $nombreComercial,
            ':rfc' => $rfc,
            ':contacto_nombre' => $contacto,
            ':telefono' => $telefono,
            ':correo' => $correo,
            ':calle' => $calle,
            ':numero_exterior' => $numeroExterior,
            ':numero_interior' => $numeroInterior,
            ':colonia' => $colonia,
            ':municipio' => $municipio,
            ':estado' => $estadoDomicilio,
            ':codigo_postal' => $codigoPostal,
            ':pais' => $pais,
            ':moneda_default_id' => $monedaId,
            ':dias_credito' => $diasCredito,
            ':limite_credito' => $limiteCredito,
            ':observaciones' => $observaciones,
            ':id' => $id,
        ]);
    }

    $nuevo = [
        'codigo' => $codigo,
        'razon_social' => $razonSocial,
        'nombre_comercial' => $nombreComercial,
        'rfc' => $rfc,
        'contacto_nombre' => $contacto,
        'telefono' => $telefono,
        'correo' => $correo,
        'moneda_default_id' => $monedaId,
        'dias_credito' => $diasCredito,
        'limite_credito' => $limiteCredito,
        'pais' => $pais,
    ];

    prov_auditar(
        $conexion,
        $esNuevo ? 'PROVEEDOR_CREADO' : 'PROVEEDOR_EDITADO',
        'proveedores',
        $id,
        $esNuevo ? 'Se registró un proveedor.' : 'Se actualizó un proveedor.',
        $anterior ? prov_proveedor_auditoria($anterior) : null,
        $nuevo
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Proveedor registrado correctamente con código ' . $codigo . '.'
            : 'Proveedor actualizado correctamente.',
        [
            'proveedor_id' => $id,
            'codigo' => $codigo,
        ],
        $esNuevo ? 201 : 200
    );
}

function prov_cambiar_estado_proveedor(PDO $conexion): void
{
    $id = prov_id($_POST['proveedor_id'] ?? null, 'proveedor');
    $activo = prov_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $proveedor = prov_bloquear_proveedor($conexion, $id);

    if (!$proveedor) {
        prov_cancelar($conexion, 'El proveedor ya no existe.', 404);
    }

    if ((int) $proveedor['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'El proveedor ya se encontraba en ese estado.');
    }

    if ($activo === 0) {
        $stmtAbiertas = $conexion->prepare(
            "SELECT COUNT(*)
             FROM compras
             WHERE proveedor_id = :proveedor_id
               AND estado IN ('BORRADOR','PENDIENTE_RECEPCION','RECIBIDA_PARCIAL')"
        );

        $stmtAbiertas->execute([':proveedor_id' => $id]);

        if ((int) $stmtAbiertas->fetchColumn() > 0) {
            prov_cancelar(
                $conexion,
                'No puedes desactivar este proveedor porque tiene compras todavía abiertas.',
                409
            );
        }
    }

    $conexion->prepare(
        "UPDATE proveedores
         SET activo = :activo
         WHERE id = :id
           AND deleted_at IS NULL"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    /*
     * Desactivar temporalmente al proveedor no debe borrar el estado de sus
     * relaciones de suministro. Las consultas operativas ya exigen que el
     * proveedor padre esté activo; al reactivarlo se conserva su configuración.
     */

    prov_auditar(
        $conexion,
        $activo === 1 ? 'PROVEEDOR_ACTIVADO' : 'PROVEEDOR_DESACTIVADO',
        'proveedores',
        $id,
        $activo === 1 ? 'Se activó un proveedor.' : 'Se desactivó un proveedor.',
        ['activo' => (int) $proveedor['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Proveedor activado correctamente.'
            : 'Proveedor desactivado correctamente.'
    );
}

function prov_papelera_proveedor(PDO $conexion): void
{
    $id = prov_id($_POST['proveedor_id'] ?? null, 'proveedor');

    $conexion->beginTransaction();

    $proveedor = prov_bloquear_proveedor($conexion, $id);

    if (!$proveedor) {
        prov_cancelar($conexion, 'El proveedor ya no existe.', 404);
    }

    $stmtCompra = $conexion->prepare(
        "SELECT COUNT(*)
         FROM compras
         WHERE proveedor_id = :proveedor_id
           AND estado IN ('BORRADOR','PENDIENTE_RECEPCION','RECIBIDA_PARCIAL')"
    );

    $stmtCompra->execute([':proveedor_id' => $id]);

    if ((int) $stmtCompra->fetchColumn() > 0) {
        prov_cancelar(
            $conexion,
            'No puedes enviar este proveedor a la papelera porque tiene compras abiertas.',
            409
        );
    }

    $stmtCxP = $conexion->prepare(
        "SELECT COUNT(*)
         FROM cuentas_por_pagar
         WHERE proveedor_id = :proveedor_id
           AND estado <> 'CANCELADA'
           AND saldo_pendiente > 0"
    );

    $stmtCxP->execute([':proveedor_id' => $id]);

    if ((int) $stmtCxP->fetchColumn() > 0) {
        prov_cancelar(
            $conexion,
            'No puedes enviar este proveedor a la papelera porque tiene saldo pendiente por pagar.',
            409
        );
    }

    $conexion->prepare(
        "UPDATE proveedores
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
        "UPDATE proveedores_productos
         SET activo = 0
         WHERE proveedor_id = :proveedor_id"
    )->execute([
        ':proveedor_id' => $id,
    ]);

    prov_auditar(
        $conexion,
        'PROVEEDOR_PAPELERA',
        'proveedores',
        $id,
        'Se envió un proveedor a la papelera.',
        prov_proveedor_auditoria($proveedor),
        [
            'activo' => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
        ]
    );

    $conexion->commit();

    si_responder_json(true, 'Proveedor enviado a la papelera correctamente.');
}

/* =========================================================================
   PRODUCTOS SUMINISTRADOS
   ========================================================================= */

function prov_listar_relaciones(PDO $conexion): void
{
    $proveedorId = prov_id($_GET['proveedor_id'] ?? null, 'proveedor');
    $pagina = prov_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prov_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $busqueda = prov_texto($_GET['busqueda'] ?? '', 160);
    $estado = strtoupper(prov_texto($_GET['estado'] ?? 'TODOS', 20));

    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS'], true)) {
        $estado = 'TODOS';
    }

    $where = [
        'pp.proveedor_id = :proveedor_id',
        'p.deleted_at IS NULL',
    ];

    $params = [
        ':proveedor_id' => $proveedorId,
    ];

    if ($busqueda !== '') {
        $where[] = '(
            p.sku LIKE :sku
            OR p.nombre LIKE :nombre
            OR pres.nombre LIKE :presentacion
        )';

        $params[':sku'] = strtoupper($busqueda) . '%';
        $params[':nombre'] = '%' . $busqueda . '%';
        $params[':presentacion'] = '%' . $busqueda . '%';
    }

    if ($estado === 'ACTIVOS') {
        $where[] = 'pp.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'pp.activo = 0';
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM proveedores_productos pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         WHERE {$whereSql}"
    );

    prov_bind($stmtTotal, $params);
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
            pp.proveedor_id,
            pp.producto_id,
            p.sku,
            p.nombre AS producto,
            p.unidad_base_id,
            ub.nombre AS unidad_base,
            ub.simbolo AS unidad_base_simbolo,
            pp.presentacion_id,
            pres.nombre AS presentacion,
            COALESCE(up.nombre, ub.nombre) AS unidad_cotizada,
            COALESCE(up.simbolo, ub.simbolo) AS unidad_cotizada_simbolo,
            COALESCE(pres.factor_a_unidad_base, 1) AS factor_a_unidad_base,
            pp.dias_entrega,
            pp.compra_minima,
            pp.activo,
            (
                SELECT hp.id
                FROM historial_precios_proveedor hp
                WHERE hp.proveedor_producto_id = pp.id
                  AND hp.activo = 1
                ORDER BY hp.fecha_precio DESC, hp.id DESC
                LIMIT 1
            ) AS ultimo_precio_id,
            (
                SELECT hp.precio_unitario
                FROM historial_precios_proveedor hp
                WHERE hp.proveedor_producto_id = pp.id
                  AND hp.activo = 1
                ORDER BY hp.fecha_precio DESC, hp.id DESC
                LIMIT 1
            ) AS ultimo_precio,
            (
                SELECT m.codigo
                FROM historial_precios_proveedor hp
                INNER JOIN monedas m
                    ON m.id = hp.moneda_id
                WHERE hp.proveedor_producto_id = pp.id
                  AND hp.activo = 1
                ORDER BY hp.fecha_precio DESC, hp.id DESC
                LIMIT 1
            ) AS ultimo_precio_moneda,
            (
                SELECT hp.precio_normalizado_base
                FROM historial_precios_proveedor hp
                WHERE hp.proveedor_producto_id = pp.id
                  AND hp.activo = 1
                ORDER BY hp.fecha_precio DESC, hp.id DESC
                LIMIT 1
            ) AS ultimo_precio_normalizado,
            (
                SELECT hp.fecha_precio
                FROM historial_precios_proveedor hp
                WHERE hp.proveedor_producto_id = pp.id
                  AND hp.activo = 1
                ORDER BY hp.fecha_precio DESC, hp.id DESC
                LIMIT 1
            ) AS ultimo_precio_fecha
         FROM proveedores_productos pp
         INNER JOIN productos p
            ON p.id = pp.producto_id
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         LEFT JOIN unidades_medida up
            ON up.id = pres.unidad_id
         WHERE {$whereSql}
         ORDER BY
            pp.activo DESC,
            p.nombre ASC,
            pres.nombre ASC,
            pp.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    prov_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['proveedor_id'] = (int) $fila['proveedor_id'];
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['unidad_base_id'] = (int) $fila['unidad_base_id'];
        $fila['presentacion_id'] = $fila['presentacion_id'] !== null
            ? (int) $fila['presentacion_id']
            : null;
        $fila['factor_a_unidad_base'] = (float) $fila['factor_a_unidad_base'];
        $fila['dias_entrega'] = $fila['dias_entrega'] !== null
            ? (int) $fila['dias_entrega']
            : null;
        $fila['compra_minima'] = $fila['compra_minima'] !== null
            ? (float) $fila['compra_minima']
            : null;
        $fila['activo'] = (int) $fila['activo'];
        $fila['ultimo_precio'] = $fila['ultimo_precio'] !== null
            ? (float) $fila['ultimo_precio']
            : null;
        $fila['ultimo_precio_normalizado'] = $fila['ultimo_precio_normalizado'] !== null
            ? (float) $fila['ultimo_precio_normalizado']
            : null;
    }
    unset($fila);

    si_responder_json(
        true,
        'Productos del proveedor cargados.',
        [
            'relaciones' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

function prov_detalle_relacion(PDO $conexion): void
{
    $id = prov_id($_GET['id'] ?? null, 'relación');

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.proveedor_id,
            pr.codigo AS proveedor_codigo,
            pr.razon_social AS proveedor,
            pp.producto_id,
            p.sku,
            p.nombre AS producto,
            pp.presentacion_id,
            pres.nombre AS presentacion,
            pp.dias_entrega,
            pp.compra_minima,
            pp.activo
         FROM proveedores_productos pp
         INNER JOIN proveedores pr
            ON pr.id = pp.proveedor_id
           AND pr.deleted_at IS NULL
         INNER JOIN productos p
            ON p.id = pp.producto_id
           AND p.deleted_at IS NULL
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         WHERE pp.id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        si_responder_json(false, 'No se encontró la relación seleccionada.', [], 404);
    }

    $fila['id'] = (int) $fila['id'];
    $fila['proveedor_id'] = (int) $fila['proveedor_id'];
    $fila['producto_id'] = (int) $fila['producto_id'];
    $fila['presentacion_id'] = $fila['presentacion_id'] !== null
        ? (int) $fila['presentacion_id']
        : null;
    $fila['dias_entrega'] = $fila['dias_entrega'] !== null
        ? (int) $fila['dias_entrega']
        : null;
    $fila['compra_minima'] = $fila['compra_minima'] !== null
        ? (float) $fila['compra_minima']
        : null;
    $fila['activo'] = (int) $fila['activo'];

    si_responder_json(true, 'Relación encontrada.', ['relacion' => $fila]);
}

function prov_guardar_relacion(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['relacion_id'] ?? ''));
    $id = $idTexto === '' ? 0 : prov_id($idTexto, 'relación');
    $esNuevo = $id === 0;

    $proveedorId = prov_id($_POST['proveedor_id'] ?? null, 'proveedor');
    $productoId = prov_id($_POST['producto_id'] ?? null, 'producto');

    $presentacionTexto = trim((string) ($_POST['presentacion_id'] ?? ''));
    $presentacionId = $presentacionTexto === ''
        ? null
        : prov_id($presentacionTexto, 'presentación');

    $diasEntregaTexto = trim((string) ($_POST['dias_entrega'] ?? ''));
    $diasEntrega = $diasEntregaTexto === ''
        ? null
        : prov_entero_rango($diasEntregaTexto, 0, 3650, -1);

    if ($diasEntrega === -1) {
        si_responder_json(false, 'Los días de entrega no son válidos.', ['campo' => 'dias_entrega'], 422);
    }

    $compraMinimaTexto = trim((string) ($_POST['compra_minima'] ?? ''));
    $compraMinima = $compraMinimaTexto === ''
        ? null
        : prov_decimal_no_negativo($compraMinimaTexto, 'La compra mínima no es válida.');

    $conexion->beginTransaction();

    $proveedor = prov_bloquear_proveedor($conexion, $proveedorId);

    if (!$proveedor || (int) $proveedor['activo'] !== 1) {
        prov_cancelar($conexion, 'Selecciona un proveedor activo.', 409);
    }

    $producto = prov_bloquear_materia_prima($conexion, $productoId);

    if (!$producto) {
        prov_cancelar(
            $conexion,
            'El producto seleccionado no existe, está inactivo o no es materia prima.',
            409
        );
    }

    prov_validar_presentacion_compra(
        $conexion,
        $productoId,
        $presentacionId
    );

    $anterior = null;

    if (!$esNuevo) {
        $stmt = $conexion->prepare(
            "SELECT
                id,
                proveedor_id,
                producto_id,
                presentacion_id,
                dias_entrega,
                compra_minima,
                activo
             FROM proveedores_productos
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->execute([':id' => $id]);
        $anterior = $stmt->fetch();

        if (!$anterior) {
            prov_cancelar($conexion, 'La relación ya no existe.', 404);
        }

        if ((int) $anterior['proveedor_id'] !== $proveedorId) {
            prov_cancelar($conexion, 'No puedes mover una relación a otro proveedor.', 409);
        }

        if ((int) $anterior['producto_id'] !== $productoId) {
            prov_cancelar($conexion, 'No puedes cambiar el producto de una relación existente. Crea una nueva.', 409);
        }

        $presentacionAnterior = $anterior['presentacion_id'] !== null
            ? (int) $anterior['presentacion_id']
            : null;

        if ($presentacionAnterior !== $presentacionId) {
            prov_cancelar(
                $conexion,
                'No puedes cambiar la presentación de una relación existente porque podría tener historial de precios. Crea una nueva.',
                409
            );
        }
    }

    prov_validar_relacion_unica(
        $conexion,
        $proveedorId,
        $productoId,
        $presentacionId,
        $id
    );

    if ($esNuevo) {
        $stmt = $conexion->prepare(
            "INSERT INTO proveedores_productos
                (
                    proveedor_id,
                    producto_id,
                    presentacion_id,
                    codigo_producto_proveedor,
                    dias_entrega,
                    compra_minima,
                    activo
                )
             VALUES
                (
                    :proveedor_id,
                    :producto_id,
                    :presentacion_id,
                    NULL,
                    :dias_entrega,
                    :compra_minima,
                    1
                )"
        );

        $stmt->execute([
            ':proveedor_id' => $proveedorId,
            ':producto_id' => $productoId,
            ':presentacion_id' => $presentacionId,
            ':dias_entrega' => $diasEntrega,
            ':compra_minima' => $compraMinima,
        ]);

        $id = (int) $conexion->lastInsertId();

    } else {
        $stmt = $conexion->prepare(
            "UPDATE proveedores_productos
             SET
                dias_entrega = :dias_entrega,
                compra_minima = :compra_minima
             WHERE id = :id"
        );

        $stmt->execute([
            ':dias_entrega' => $diasEntrega,
            ':compra_minima' => $compraMinima,
            ':id' => $id,
        ]);
    }

    prov_auditar(
        $conexion,
        $esNuevo ? 'PROVEEDOR_PRODUCTO_CREADO' : 'PROVEEDOR_PRODUCTO_EDITADO',
        'proveedores_productos',
        $id,
        $esNuevo
            ? 'Se vinculó una materia prima con un proveedor.'
            : 'Se actualizó la configuración de suministro.',
        $anterior ?: null,
        [
            'proveedor_id' => $proveedorId,
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId,
            'dias_entrega' => $diasEntrega,
            'compra_minima' => $compraMinima,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Producto agregado al proveedor correctamente.'
            : 'Configuración del producto actualizada.',
        ['relacion_id' => $id],
        $esNuevo ? 201 : 200
    );
}

function prov_cambiar_estado_relacion(PDO $conexion): void
{
    $id = prov_id($_POST['relacion_id'] ?? null, 'relación');
    $activo = prov_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.proveedor_id,
            pp.producto_id,
            pp.activo,
            pr.activo AS proveedor_activo,
            pr.deleted_at AS proveedor_deleted_at,
            p.activo AS producto_activo,
            p.deleted_at AS producto_deleted_at
         FROM proveedores_productos pp
         INNER JOIN proveedores pr
            ON pr.id = pp.proveedor_id
         INNER JOIN productos p
            ON p.id = pp.producto_id
         WHERE pp.id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    if (!$fila) {
        prov_cancelar($conexion, 'La relación ya no existe.', 404);
    }

    if (
        $activo === 1
        && (
            (int) $fila['proveedor_activo'] !== 1
            || $fila['proveedor_deleted_at'] !== null
            || (int) $fila['producto_activo'] !== 1
            || $fila['producto_deleted_at'] !== null
        )
    ) {
        prov_cancelar(
            $conexion,
            'No puedes activar esta relación mientras el proveedor o producto esté inactivo.',
            409
        );
    }

    if ((int) $fila['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'La relación ya se encontraba en ese estado.');
    }

    $conexion->prepare(
        "UPDATE proveedores_productos
         SET activo = :activo
         WHERE id = :id"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    prov_auditar(
        $conexion,
        $activo === 1
            ? 'PROVEEDOR_PRODUCTO_ACTIVADO'
            : 'PROVEEDOR_PRODUCTO_DESACTIVADO',
        'proveedores_productos',
        $id,
        $activo === 1
            ? 'Se activó una relación proveedor-producto.'
            : 'Se desactivó una relación proveedor-producto.',
        ['activo' => (int) $fila['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Producto activado para este proveedor.'
            : 'Producto desactivado para este proveedor.'
    );
}

/* =========================================================================
   PRECIOS
   ========================================================================= */

function prov_listar_precios(PDO $conexion): void
{
    $relacionId = prov_id($_GET['relacion_id'] ?? null, 'relación');
    $pagina = prov_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prov_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*)
         FROM historial_precios_proveedor
         WHERE proveedor_producto_id = :relacion_id"
    );

    $stmtTotal->execute([':relacion_id' => $relacionId]);

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            hp.id,
            hp.fecha_precio,
            hp.cantidad_referencia,
            hp.precio_unitario,
            hp.moneda_id,
            m.codigo AS moneda,
            m.simbolo,
            hp.tipo_cambio_a_base,
            hp.factor_a_unidad_base,
            hp.precio_normalizado_base,
            hp.vigencia_hasta,
            hp.fuente,
            hp.referencia,
            hp.activo,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            us.usuario AS creado_por
         FROM historial_precios_proveedor hp
         INNER JOIN monedas m
            ON m.id = hp.moneda_id
         INNER JOIN unidades_medida u
            ON u.id = hp.unidad_id
         LEFT JOIN usuarios us
            ON us.id = hp.created_by
         WHERE hp.proveedor_producto_id = :relacion_id
         ORDER BY
            hp.fecha_precio DESC,
            hp.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    $stmt->bindValue(':relacion_id', $relacionId, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['cantidad_referencia'] = (float) $fila['cantidad_referencia'];
        $fila['precio_unitario'] = (float) $fila['precio_unitario'];
        $fila['moneda_id'] = (int) $fila['moneda_id'];
        $fila['tipo_cambio_a_base'] = (float) $fila['tipo_cambio_a_base'];
        $fila['factor_a_unidad_base'] = (float) $fila['factor_a_unidad_base'];
        $fila['precio_normalizado_base'] = (float) $fila['precio_normalizado_base'];
        $fila['activo'] = (int) $fila['activo'];
    }
    unset($fila);

    si_responder_json(
        true,
        'Historial de precios cargado.',
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

function prov_registrar_precio(PDO $conexion): void
{
    $relacionId = prov_id($_POST['relacion_id'] ?? null, 'relación');
    $precio = prov_decimal_positivo(
        $_POST['precio_unitario'] ?? null,
        'El precio debe ser mayor que cero.'
    );

    $monedaId = prov_id($_POST['moneda_id'] ?? null, 'moneda');
    prov_validar_moneda_activa($conexion, $monedaId);

    $vigenciaHastaTexto = trim((string) ($_POST['vigencia_hasta'] ?? ''));
    $vigenciaHasta = null;

    if ($vigenciaHastaTexto !== '') {
        $vigenciaHasta = prov_fecha_hora($vigenciaHastaTexto);

        if ($vigenciaHasta === null) {
            si_responder_json(
                false,
                'La fecha de vigencia no es válida.',
                ['campo' => 'vigencia_hasta'],
                422
            );
        }
    }

    $referencia = prov_nullable($_POST['referencia'] ?? '', 100);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            pp.id,
            pp.proveedor_id,
            pp.producto_id,
            pp.presentacion_id,
            pp.activo,
            pr.activo AS proveedor_activo,
            pr.deleted_at AS proveedor_deleted_at,
            p.nombre AS producto,
            p.activo AS producto_activo,
            p.deleted_at AS producto_deleted_at,
            p.unidad_base_id,
            ub.codigo AS unidad_base_codigo,
            ub.nombre AS unidad_base_nombre,
            ub.simbolo AS unidad_base_simbolo,
            pres.unidad_id AS presentacion_unidad_id,
            pres.factor_a_unidad_base AS presentacion_factor,
            up.codigo AS presentacion_unidad_codigo,
            up.nombre AS presentacion_unidad_nombre,
            up.simbolo AS presentacion_unidad_simbolo
         FROM proveedores_productos pp
         INNER JOIN proveedores pr
            ON pr.id = pp.proveedor_id
         INNER JOIN productos p
            ON p.id = pp.producto_id
         INNER JOIN unidades_medida ub
            ON ub.id = p.unidad_base_id
         LEFT JOIN presentaciones_producto pres
            ON pres.id = pp.presentacion_id
         LEFT JOIN unidades_medida up
            ON up.id = pres.unidad_id
         WHERE pp.id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $relacionId]);
    $relacion = $stmt->fetch();

    if (!$relacion) {
        prov_cancelar($conexion, 'La relación proveedor-producto ya no existe.', 404);
    }

    if (
        (int) $relacion['activo'] !== 1
        || (int) $relacion['proveedor_activo'] !== 1
        || $relacion['proveedor_deleted_at'] !== null
        || (int) $relacion['producto_activo'] !== 1
        || $relacion['producto_deleted_at'] !== null
    ) {
        prov_cancelar(
            $conexion,
            'No puedes registrar precios en una relación inactiva.',
            409
        );
    }

    if ($relacion['presentacion_id'] !== null) {
        $unidadId = (int) $relacion['presentacion_unidad_id'];
        $factor = (float) $relacion['presentacion_factor'];
    } else {
        $unidadId = (int) $relacion['unidad_base_id'];
        $factor = 1.0;
    }

    if ($factor <= 0) {
        prov_cancelar($conexion, 'La presentación tiene un factor de conversión inválido.', 409);
    }

    $monedaBase = prov_moneda_base($conexion);

    if (!$monedaBase) {
        prov_cancelar($conexion, 'No está configurada la moneda base del sistema.', 500);
    }

    if ($monedaId === (int) $monedaBase['id']) {
        $tipoCambio = 1.0;
    } else {
        $tipoCambioTexto = trim((string) ($_POST['tipo_cambio_a_base'] ?? ''));

        if ($tipoCambioTexto !== '') {
            $tipoCambio = prov_decimal_positivo(
                $tipoCambioTexto,
                'El tipo de cambio debe ser mayor que cero.'
            );
        } else {
            $tipoCambio = prov_ultimo_tipo_cambio(
                $conexion,
                $monedaId,
                (int) $monedaBase['id']
            );

            if ($tipoCambio === null) {
                prov_cancelar(
                    $conexion,
                    'No hay un tipo de cambio registrado para esa moneda. Captúralo manualmente.',
                    422,
                    ['campo' => 'tipo_cambio_a_base']
                );
            }
        }
    }

    $precioNormalizado = ($precio * $tipoCambio) / $factor;

    $stmtInsert = $conexion->prepare(
        "INSERT INTO historial_precios_proveedor
            (
                proveedor_producto_id,
                fecha_precio,
                unidad_id,
                cantidad_referencia,
                precio_unitario,
                moneda_id,
                tipo_cambio_a_base,
                factor_a_unidad_base,
                precio_normalizado_base,
                vigencia_hasta,
                fuente,
                referencia,
                activo,
                created_by
            )
         VALUES
            (
                :relacion_id,
                NOW(),
                :unidad_id,
                1,
                :precio_unitario,
                :moneda_id,
                :tipo_cambio,
                :factor,
                :precio_normalizado,
                :vigencia_hasta,
                'MANUAL',
                :referencia,
                1,
                :created_by
            )"
    );

    $stmtInsert->execute([
        ':relacion_id' => $relacionId,
        ':unidad_id' => $unidadId,
        ':precio_unitario' => $precio,
        ':moneda_id' => $monedaId,
        ':tipo_cambio' => $tipoCambio,
        ':factor' => $factor,
        ':precio_normalizado' => $precioNormalizado,
        ':vigencia_hasta' => $vigenciaHasta,
        ':referencia' => $referencia,
        ':created_by' => (int) $_SESSION['usuario_id'],
    ]);

    $precioId = (int) $conexion->lastInsertId();

    prov_auditar(
        $conexion,
        'PRECIO_PROVEEDOR_REGISTRADO',
        'historial_precios_proveedor',
        $precioId,
        'Se registró un precio de proveedor.',
        null,
        [
            'proveedor_producto_id' => $relacionId,
            'precio_unitario' => $precio,
            'moneda_id' => $monedaId,
            'tipo_cambio_a_base' => $tipoCambio,
            'factor_a_unidad_base' => $factor,
            'precio_normalizado_base' => $precioNormalizado,
            'vigencia_hasta' => $vigenciaHasta,
            'referencia' => $referencia,
        ]
    );

    $conexion->commit();

    si_responder_json(
        true,
        'Precio registrado. Costo normalizado: '
            . number_format($precioNormalizado, 6, '.', ',')
            . ' '
            . $monedaBase['codigo']
            . ' por unidad base.',
        [
            'precio_id' => $precioId,
            'precio_normalizado_base' => $precioNormalizado,
            'moneda_base' => $monedaBase['codigo'],
        ],
        201
    );
}

/* =========================================================================
   COMPARADOR
   ========================================================================= */

function prov_comparador(PDO $conexion): void
{
    if (!si_tiene_permiso('proveedores.comparar_precios')) {
        si_responder_json(false, 'No tienes permiso para comparar precios.', [], 403);
    }

    $productoId = prov_id($_GET['producto_id'] ?? null, 'producto');
    $pagina = prov_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = prov_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $soloVigentes = (string) ($_GET['solo_vigentes'] ?? '1') !== '0';

    /*
     * IMPORTANTE:
     * Para precios vigentes no podemos tomar simplemente "el último precio"
     * y filtrar su vencimiento después. Si el precio más reciente venció pero
     * existe otro precio anterior todavía vigente, el proveedor desaparecería
     * incorrectamente del comparador.
     *
     * Por eso, cuando se solicitan vigentes, primero limitamos el universo a
     * precios realmente vigentes y DENTRO de ese universo elegimos el último
     * por relación proveedor-producto.
     */
    if ($soloVigentes) {
        $fuente = "(
            SELECT
                p.id AS producto_id,
                p.sku,
                p.nombre AS producto,
                pr.id AS proveedor_id,
                pr.razon_social AS proveedor,
                hpp.fecha_precio,
                um.codigo AS unidad_cotizada,
                hpp.precio_unitario,
                mon.codigo AS moneda,
                hpp.tipo_cambio_a_base,
                hpp.factor_a_unidad_base,
                hpp.precio_normalizado_base,
                pp.dias_entrega,
                pr.dias_credito,
                hpp.vigencia_hasta
            FROM historial_precios_proveedor hpp
            INNER JOIN proveedores_productos pp
                ON pp.id = hpp.proveedor_producto_id
            INNER JOIN proveedores pr
                ON pr.id = pp.proveedor_id
            INNER JOIN productos p
                ON p.id = pp.producto_id
            INNER JOIN unidades_medida um
                ON um.id = hpp.unidad_id
            INNER JOIN monedas mon
                ON mon.id = hpp.moneda_id
            WHERE hpp.activo = 1
              AND hpp.fecha_precio <= NOW()
              AND (hpp.vigencia_hasta IS NULL OR hpp.vigencia_hasta >= NOW())
              AND pp.activo = 1
              AND pr.activo = 1
              AND pr.deleted_at IS NULL
              AND p.activo = 1
              AND p.deleted_at IS NULL
              AND NOT EXISTS (
                    SELECT 1
                    FROM historial_precios_proveedor h2
                    WHERE h2.proveedor_producto_id = hpp.proveedor_producto_id
                      AND h2.activo = 1
                      AND h2.fecha_precio <= NOW()
                      AND (h2.vigencia_hasta IS NULL OR h2.vigencia_hasta >= NOW())
                      AND (
                            h2.fecha_precio > hpp.fecha_precio
                            OR (
                                h2.fecha_precio = hpp.fecha_precio
                                AND h2.id > hpp.id
                            )
                      )
              )
        )";
    } else {
        // Modo histórico: conserva el comportamiento de la vista existente.
        $fuente = 'vw_comparador_proveedores';
    }

    $params = [':producto_id' => $productoId];

    /*
     * Un proveedor puede cotizar la misma materia prima en distintas
     * presentaciones. Para que el comparador sea realmente "por proveedor",
     * mostramos solo su mejor opción normalizada a la unidad base.
     */
    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(DISTINCT proveedor_id)
         FROM {$fuente} precios
         WHERE producto_id = :producto_id"
    );

    prov_bind($stmtTotal, $params);
    $stmtTotal->execute();

    $total = (int) $stmtTotal->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));

    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
    }

    $offset = ($pagina - 1) * $porPagina;

    $stmt = $conexion->prepare(
        "SELECT
            producto_id,
            sku,
            producto,
            proveedor_id,
            proveedor,
            fecha_precio,
            unidad_cotizada,
            precio_unitario,
            moneda,
            tipo_cambio_a_base,
            factor_a_unidad_base,
            precio_normalizado_base,
            dias_entrega,
            dias_credito,
            vigencia_hasta
         FROM (
            SELECT
                v.*,
                ROW_NUMBER() OVER (
                    PARTITION BY v.proveedor_id
                    ORDER BY
                        v.precio_normalizado_base ASC,
                        COALESCE(v.dias_entrega, 999999) ASC,
                        v.fecha_precio DESC
                ) AS rn
            FROM {$fuente} v
            WHERE v.producto_id = :producto_id
         ) mejores
         WHERE rn = 1
         ORDER BY
            precio_normalizado_base ASC,
            COALESCE(dias_entrega, 999999) ASC,
            proveedor ASC
         LIMIT :limite
         OFFSET :offset"
    );

    prov_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $filas = $stmt->fetchAll();

    foreach ($filas as $indice => &$fila) {
        $fila['producto_id'] = (int) $fila['producto_id'];
        $fila['proveedor_id'] = (int) $fila['proveedor_id'];
        $fila['precio_unitario'] = (float) $fila['precio_unitario'];
        $fila['tipo_cambio_a_base'] = (float) $fila['tipo_cambio_a_base'];
        $fila['factor_a_unidad_base'] = (float) $fila['factor_a_unidad_base'];
        $fila['precio_normalizado_base'] = (float) $fila['precio_normalizado_base'];
        $fila['dias_entrega'] = $fila['dias_entrega'] !== null
            ? (int) $fila['dias_entrega']
            : null;
        $fila['dias_credito'] = (int) $fila['dias_credito'];
        $fila['posicion'] = $offset + $indice + 1;
    }
    unset($fila);

    si_responder_json(
        true,
        'Comparación cargada.',
        [
            'comparacion' => $filas,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
        ]
    );
}

/* =========================================================================
   BÚSQUEDAS / CATÁLOGOS
   ========================================================================= */

function prov_catalogos(PDO $conexion): void
{
    $monedas = $conexion->query(
        "SELECT
            id,
            codigo,
            nombre,
            simbolo,
            es_base
         FROM monedas
         WHERE activo = 1
         ORDER BY
            es_base DESC,
            codigo ASC"
    )->fetchAll();

    foreach ($monedas as &$moneda) {
        $moneda['id'] = (int) $moneda['id'];
        $moneda['es_base'] = (int) $moneda['es_base'];
    }
    unset($moneda);

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'monedas' => $monedas,
            'moneda_base' => prov_moneda_base($conexion),
        ]
    );
}

function prov_buscar_proveedores(PDO $conexion): void
{
    $q = prov_texto($_GET['q'] ?? '', 180);

    if (prov_strlen($q) < 1) {
        si_responder_json(true, 'Sin búsqueda.', ['proveedores' => []]);
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.codigo,
            p.razon_social,
            p.nombre_comercial,
            p.moneda_default_id,
            m.codigo AS moneda,
            p.dias_credito
         FROM proveedores p
         LEFT JOIN monedas m
            ON m.id = p.moneda_default_id
         WHERE p.deleted_at IS NULL
           AND p.activo = 1
           AND (
                p.codigo = :codigo_exacto
                OR p.codigo LIKE :codigo_prefijo
                OR p.razon_social LIKE :razon
                OR p.nombre_comercial LIKE :comercial
                OR p.rfc LIKE :rfc
           )
         ORDER BY
            CASE WHEN p.codigo = :codigo_orden THEN 0 ELSE 1 END,
            p.razon_social ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':codigo_exacto' => strtoupper($q),
        ':codigo_prefijo' => strtoupper($q) . '%',
        ':razon' => '%' . $q . '%',
        ':comercial' => '%' . $q . '%',
        ':rfc' => strtoupper($q) . '%',
        ':codigo_orden' => strtoupper($q),
    ]);

    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['moneda_default_id'] = $fila['moneda_default_id'] !== null
            ? (int) $fila['moneda_default_id']
            : null;
        $fila['dias_credito'] = (int) $fila['dias_credito'];
    }
    unset($fila);

    si_responder_json(true, 'Proveedores encontrados.', ['proveedores' => $filas]);
}

function prov_buscar_materias_primas(PDO $conexion): void
{
    $q = prov_texto($_GET['q'] ?? '', 180);

    if (prov_strlen($q) < 1) {
        si_responder_json(true, 'Sin búsqueda.', ['productos' => []]);
    }

    $stmt = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.unidad_base_id,
            u.nombre AS unidad_base,
            u.simbolo AS unidad_base_simbolo
         FROM productos p
         INNER JOIN unidades_medida u
            ON u.id = p.unidad_base_id
         WHERE p.deleted_at IS NULL
           AND p.activo = 1
           AND p.tipo = 'MATERIA_PRIMA'
           AND (
                p.sku = :codigo_exacto
                OR p.sku LIKE :codigo_prefijo
                OR p.nombre LIKE :nombre
           )
         ORDER BY
            CASE WHEN p.sku = :codigo_orden THEN 0 ELSE 1 END,
            p.nombre ASC
         LIMIT 20"
    );

    $stmt->execute([
        ':codigo_exacto' => strtoupper($q),
        ':codigo_prefijo' => strtoupper($q) . '%',
        ':nombre' => '%' . $q . '%',
        ':codigo_orden' => strtoupper($q),
    ]);

    $filas = $stmt->fetchAll();

    foreach ($filas as &$fila) {
        $fila['id'] = (int) $fila['id'];
        $fila['unidad_base_id'] = (int) $fila['unidad_base_id'];
    }
    unset($fila);

    si_responder_json(true, 'Materias primas encontradas.', ['productos' => $filas]);
}

function prov_opciones_producto(PDO $conexion): void
{
    $productoId = prov_id($_GET['producto_id'] ?? null, 'producto');

    $stmtProducto = $conexion->prepare(
        "SELECT
            p.id,
            p.sku,
            p.nombre,
            p.tipo,
            p.unidad_base_id,
            u.codigo AS unidad_base_codigo,
            u.nombre AS unidad_base,
            u.simbolo AS unidad_base_simbolo
         FROM productos p
         INNER JOIN unidades_medida u
            ON u.id = p.unidad_base_id
         WHERE p.id = :id
           AND p.deleted_at IS NULL
           AND p.activo = 1
           AND p.tipo = 'MATERIA_PRIMA'
         LIMIT 1"
    );

    $stmtProducto->execute([':id' => $productoId]);
    $producto = $stmtProducto->fetch();

    if (!$producto) {
        si_responder_json(false, 'La materia prima seleccionada no está disponible.', [], 404);
    }

    $stmtPresentaciones = $conexion->prepare(
        "SELECT
            pp.id,
            pp.nombre,
            pp.unidad_id,
            u.codigo AS unidad_codigo,
            u.nombre AS unidad_nombre,
            u.simbolo AS unidad_simbolo,
            pp.factor_a_unidad_base
         FROM presentaciones_producto pp
         INNER JOIN unidades_medida u
            ON u.id = pp.unidad_id
         WHERE pp.producto_id = :producto_id
           AND pp.activo = 1
           AND pp.es_compra = 1
         ORDER BY pp.nombre ASC"
    );

    $stmtPresentaciones->execute([':producto_id' => $productoId]);
    $presentaciones = $stmtPresentaciones->fetchAll();

    foreach ($presentaciones as &$presentacion) {
        $presentacion['id'] = (int) $presentacion['id'];
        $presentacion['unidad_id'] = (int) $presentacion['unidad_id'];
        $presentacion['factor_a_unidad_base'] = (float) $presentacion['factor_a_unidad_base'];
    }
    unset($presentacion);

    $producto['id'] = (int) $producto['id'];
    $producto['unidad_base_id'] = (int) $producto['unidad_base_id'];

    si_responder_json(
        true,
        'Opciones del producto cargadas.',
        [
            'producto' => $producto,
            'presentaciones' => $presentaciones,
        ]
    );
}

function prov_tipo_cambio(PDO $conexion): void
{
    $monedaId = prov_id($_GET['moneda_id'] ?? null, 'moneda');

    $base = prov_moneda_base($conexion);

    if (!$base) {
        si_responder_json(false, 'No está configurada la moneda base.', [], 500);
    }

    if ($monedaId === (int) $base['id']) {
        si_responder_json(
            true,
            'La moneda seleccionada es la moneda base.',
            [
                'tipo_cambio' => 1,
                'moneda_base' => $base,
                'encontrado' => true,
            ]
        );
    }

    $tipo = prov_ultimo_tipo_cambio(
        $conexion,
        $monedaId,
        (int) $base['id']
    );

    si_responder_json(
        true,
        $tipo !== null
            ? 'Tipo de cambio encontrado.'
            : 'No hay tipo de cambio registrado.',
        [
            'tipo_cambio' => $tipo,
            'moneda_base' => $base,
            'encontrado' => $tipo !== null,
        ]
    );
}

/* =========================================================================
   HELPERS
   ========================================================================= */

function prov_requerir_admin(): void
{
    if (!si_tiene_permiso('proveedores.administrar')) {
        si_responder_json(false, 'No tienes permiso para administrar proveedores.', [], 403);
    }
}

function prov_bloquear_proveedor(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            id,
            codigo,
            razon_social,
            nombre_comercial,
            rfc,
            contacto_nombre,
            telefono,
            correo,
            calle,
            numero_exterior,
            numero_interior,
            colonia,
            municipio,
            estado,
            codigo_postal,
            pais,
            moneda_default_id,
            dias_credito,
            limite_credito,
            observaciones,
            activo
         FROM proveedores
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);

    $fila = $stmt->fetch();

    return $fila ?: null;
}

function prov_bloquear_materia_prima(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            id,
            sku,
            nombre,
            unidad_base_id,
            activo,
            tipo
         FROM productos
         WHERE id = :id
           AND deleted_at IS NULL
           AND activo = 1
           AND tipo = 'MATERIA_PRIMA'
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);

    $fila = $stmt->fetch();

    return $fila ?: null;
}

function prov_validar_presentacion_compra(
    PDO $conexion,
    int $productoId,
    ?int $presentacionId
): void {
    if ($presentacionId === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT 1
         FROM presentaciones_producto
         WHERE id = :id
           AND producto_id = :producto_id
           AND activo = 1
           AND es_compra = 1
         LIMIT 1"
    );

    $stmt->execute([
        ':id' => $presentacionId,
        ':producto_id' => $productoId,
    ]);

    if (!$stmt->fetchColumn()) {
        prov_cancelar(
            $conexion,
            'La presentación seleccionada no está disponible para compras.',
            409
        );
    }
}

function prov_validar_relacion_unica(
    PDO $conexion,
    int $proveedorId,
    int $productoId,
    ?int $presentacionId,
    int $excluirId
): void {
    if ($presentacionId === null) {
        $stmt = $conexion->prepare(
            "SELECT id
             FROM proveedores_productos
             WHERE proveedor_id = :proveedor_id
               AND producto_id = :producto_id
               AND presentacion_id IS NULL
               AND id <> :id
             LIMIT 1"
        );

        $stmt->execute([
            ':proveedor_id' => $proveedorId,
            ':producto_id' => $productoId,
            ':id' => $excluirId,
        ]);
    } else {
        $stmt = $conexion->prepare(
            "SELECT id
             FROM proveedores_productos
             WHERE proveedor_id = :proveedor_id
               AND producto_id = :producto_id
               AND presentacion_id = :presentacion_id
               AND id <> :id
             LIMIT 1"
        );

        $stmt->execute([
            ':proveedor_id' => $proveedorId,
            ':producto_id' => $productoId,
            ':presentacion_id' => $presentacionId,
            ':id' => $excluirId,
        ]);
    }

    if ($stmt->fetchColumn()) {
        prov_cancelar(
            $conexion,
            'Ese proveedor ya tiene registrada la misma materia prima y presentación.',
            409
        );
    }
}

function prov_validar_moneda_activa(PDO $conexion, int $monedaId): void
{
    $stmt = $conexion->prepare(
        "SELECT 1
         FROM monedas
         WHERE id = :id
           AND activo = 1
         LIMIT 1"
    );

    $stmt->execute([':id' => $monedaId]);

    if (!$stmt->fetchColumn()) {
        if ($conexion->inTransaction()) {
            prov_cancelar($conexion, 'La moneda seleccionada no está disponible.', 409);
        }

        si_responder_json(false, 'La moneda seleccionada no está disponible.', [], 409);
    }
}

function prov_validar_rfc_unico(PDO $conexion, ?string $rfc, int $excluirId): void
{
    if ($rfc === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT id, deleted_at
         FROM proveedores
         WHERE rfc = :rfc
           AND id <> :id
         LIMIT 1"
    );

    $stmt->execute([
        ':rfc' => $rfc,
        ':id' => $excluirId,
    ]);

    $fila = $stmt->fetch();

    if ($fila) {
        prov_cancelar(
            $conexion,
            $fila['deleted_at'] !== null
                ? 'Ese RFC pertenece a un proveedor que está en la papelera.'
                : 'Ya existe un proveedor con ese RFC.',
            409,
            ['campo' => 'rfc']
        );
    }
}

function prov_moneda_base(PDO $conexion): ?array
{
    $fila = $conexion->query(
        "SELECT
            id,
            codigo,
            nombre,
            simbolo
         FROM monedas
         WHERE es_base = 1
           AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();

    if (!$fila) {
        return null;
    }

    $fila['id'] = (int) $fila['id'];

    return $fila;
}

function prov_ultimo_tipo_cambio(
    PDO $conexion,
    int $monedaOrigenId,
    int $monedaDestinoId
): ?float {
    $stmt = $conexion->prepare(
        "SELECT tipo_cambio
         FROM tipos_cambio
         WHERE moneda_origen_id = :origen
           AND moneda_destino_id = :destino
           AND fecha <= CURDATE()
         ORDER BY fecha DESC, id DESC
         LIMIT 1"
    );

    $stmt->execute([
        ':origen' => $monedaOrigenId,
        ':destino' => $monedaDestinoId,
    ]);

    $valor = $stmt->fetchColumn();

    return $valor === false
        ? null
        : (float) $valor;
}

function prov_generar_codigo_proveedor(int $id): string
{
    return 'PROV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function prov_requerido($valor, string $mensaje, int $maximo): string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        si_responder_json(false, $mensaje, [], 422);
    }

    if (prov_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function prov_nullable($valor, int $maximo): ?string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    if (prov_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function prov_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);

    if (prov_strlen($texto) > $maximo) {
        return prov_substr($texto, 0, $maximo);
    }

    return $texto;
}

function prov_decimal_positivo($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $numero = (float) $valor;

    if ($numero <= 0 || $numero > 999999999999.0) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return $numero;
}

function prov_decimal_no_negativo($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $numero = (float) $valor;

    if ($numero < 0 || $numero > 999999999999.0) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return $numero;
}

function prov_estado($valor): int
{
    $numero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($numero === false || !in_array((int) $numero, [0, 1], true)) {
        si_responder_json(false, 'Estado inválido.', [], 422);
    }

    return (int) $numero;
}

function prov_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);

    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Identificador de ' . $entidad . ' inválido.', [], 422);
    }

    return (int) $id;
}

function prov_entero_rango(
    $valor,
    int $minimo,
    int $maximo,
    int $default
): int {
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

function prov_fecha_hora(string $valor): ?string
{
    $valor = trim($valor);

    if ($valor === '') {
        return null;
    }

    $formatos = [
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
    ];

    foreach ($formatos as $formato) {
        $fecha = DateTime::createFromFormat($formato, $valor);

        if ($fecha instanceof DateTime) {
            return $fecha->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function prov_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}

function prov_strlen(string $texto): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

function prov_substr(string $texto, int $inicio, int $longitud): string
{
    return function_exists('mb_substr')
        ? mb_substr($texto, $inicio, $longitud, 'UTF-8')
        : substr($texto, $inicio, $longitud);
}

function prov_proveedor_auditoria(array $fila): array
{
    return [
        'codigo' => $fila['codigo'] ?? null,
        'razon_social' => $fila['razon_social'] ?? null,
        'nombre_comercial' => $fila['nombre_comercial'] ?? null,
        'rfc' => $fila['rfc'] ?? null,
        'contacto_nombre' => $fila['contacto_nombre'] ?? null,
        'telefono' => $fila['telefono'] ?? null,
        'correo' => $fila['correo'] ?? null,
        'moneda_default_id' => isset($fila['moneda_default_id'])
            ? (int) $fila['moneda_default_id']
            : null,
        'dias_credito' => isset($fila['dias_credito'])
            ? (int) $fila['dias_credito']
            : null,
        'limite_credito' => isset($fila['limite_credito'])
            ? (float) $fila['limite_credito']
            : null,
        'activo' => isset($fila['activo'])
            ? (int) $fila['activo']
            : null,
    ];
}

function prov_auditar(
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
                'proveedores',
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
            : json_encode($anterior, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':datos_nuevos' => $nuevo === null
            ? null
            : json_encode($nuevo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => si_ip_cliente(),
        ':user_agent' => si_user_agent(),
    ]);
}

function prov_cancelar(
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
