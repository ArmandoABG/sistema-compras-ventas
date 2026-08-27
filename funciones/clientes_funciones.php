<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

si_requerir_permiso('clientes.ver', true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$accion = strtoupper(trim((string) (
    $metodo === 'GET'
        ? ($_GET['accion'] ?? 'LISTAR_CLIENTES')
        : ($_POST['accion'] ?? '')
)));

try {
    if ($metodo === 'GET') {
        si_requerir_metodo('GET');

        switch ($accion) {
            case 'CATALOGOS':
                cli_catalogos($conexion);
                break;

            case 'LISTAR_CLIENTES':
                cli_listar_clientes($conexion);
                break;

            case 'DETALLE_CLIENTE':
                cli_detalle_cliente($conexion);
                break;

            case 'LISTAR_NIVELES':
                cli_listar_niveles($conexion);
                break;

            case 'LISTAR_CREDITO':
                cli_listar_credito($conexion);
                break;

            default:
                si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
        }
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    if (!si_tiene_permiso('clientes.administrar')) {
        si_responder_json(
            false,
            'No tienes permiso para administrar clientes.',
            [],
            403
        );
    }

    switch ($accion) {
        case 'GUARDAR_CLIENTE':
            cli_guardar_cliente($conexion);
            break;

        case 'CAMBIAR_ESTADO_CLIENTE':
            cli_cambiar_estado_cliente($conexion);
            break;

        case 'GUARDAR_NIVEL':
            cli_guardar_nivel($conexion);
            break;

        default:
            si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CLI-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CLIENTES][PDO] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    if ((string) $e->getCode() === '23000') {
        si_responder_json(
            false,
            'Ya existe un cliente con ese código o RFC, o el registro está relacionado con otra operación.',
            ['referencia' => $referencia],
            409
        );
    }

    si_responder_json(
        false,
        'No fue posible procesar la operación de clientes.',
        ['referencia' => $referencia],
        500
    );

} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'CLI-' . date('Ymd-His');

    error_log(
        '[' . $referencia . '][CLIENTES] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        'Ocurrió un error interno al procesar clientes.',
        ['referencia' => $referencia],
        500
    );
}

/* =========================================================================
   CATÁLOGOS
   ========================================================================= */

function cli_catalogos(PDO $conexion): void
{
    $niveles = $conexion->query(
        "SELECT
            n.id,
            n.codigo,
            n.nombre,
            n.descuento_default_pct,
            n.activo,
            (
                SELECT COUNT(*)
                FROM clientes c
                WHERE c.nivel_cliente_id = n.id
                  AND c.activo = 1
            ) AS clientes_activos
         FROM niveles_cliente n
         ORDER BY n.id ASC"
    )->fetchAll();

    $monedaBase = $conexion->query(
        "SELECT id, codigo, nombre, simbolo
         FROM monedas
         WHERE es_base = 1
           AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();

    foreach ($niveles as &$nivel) {
        $nivel['id'] = (int) $nivel['id'];
        $nivel['descuento_default_pct'] = (float) $nivel['descuento_default_pct'];
        $nivel['activo'] = (int) $nivel['activo'];
        $nivel['clientes_activos'] = (int) $nivel['clientes_activos'];
    }
    unset($nivel);

    if ($monedaBase) {
        $monedaBase['id'] = (int) $monedaBase['id'];
    }

    si_responder_json(
        true,
        'Catálogos cargados.',
        [
            'niveles' => $niveles,
            'moneda_base' => $monedaBase ?: null,
        ]
    );
}

/* =========================================================================
   DIRECTORIO DE CLIENTES
   ========================================================================= */

function cli_listar_clientes(PDO $conexion): void
{
    $pagina = cli_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cli_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cli_texto($_GET['busqueda'] ?? '', 180);
    $nivelId = cli_entero_rango($_GET['nivel_id'] ?? 0, 0, PHP_INT_MAX, 0);
    $estado = strtoupper(cli_texto($_GET['estado'] ?? 'TODOS', 20));
    $credito = strtoupper(cli_texto($_GET['credito'] ?? 'TODOS', 20));
    $tipoPersona = strtoupper(cli_texto($_GET['tipo_persona'] ?? 'TODOS', 30));

    if (!in_array($estado, ['TODOS', 'ACTIVOS', 'INACTIVOS'], true)) {
        $estado = 'TODOS';
    }

    if (!in_array($credito, ['TODOS', 'CON_CREDITO', 'SIN_CREDITO'], true)) {
        $credito = 'TODOS';
    }

    if (!in_array($tipoPersona, ['TODOS', 'FISICA', 'MORAL', 'NO_ESPECIFICADO'], true)) {
        $tipoPersona = 'TODOS';
    }

    // Condición base: evita generar un WHERE vacío cuando no hay filtros.
    $where = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            c.codigo LIKE :q_codigo
            OR c.nombre_razon_social LIKE :q_nombre
            OR c.rfc LIKE :q_rfc
            OR c.telefono LIKE :q_telefono
            OR c.correo LIKE :q_correo
        )";
        $params[':q_codigo'] = $like;
        $params[':q_nombre'] = $like;
        $params[':q_rfc'] = $like;
        $params[':q_telefono'] = $like;
        $params[':q_correo'] = $like;
    }

    if ($nivelId > 0) {
        $where[] = 'c.nivel_cliente_id = :nivel_id';
        $params[':nivel_id'] = $nivelId;
    }

    if ($estado === 'ACTIVOS') {
        $where[] = 'c.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'c.activo = 0';
    }

    if ($credito === 'CON_CREDITO') {
        $where[] = 'c.dias_credito > 0';
    } elseif ($credito === 'SIN_CREDITO') {
        $where[] = 'c.dias_credito = 0';
    }

    if ($tipoPersona !== 'TODOS') {
        $where[] = 'c.tipo_persona = :tipo_persona';
        $params[':tipo_persona'] = $tipoPersona;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $usoCreditoSubquery = cli_subquery_uso_credito();

    $from = "FROM clientes c
             LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
             LEFT JOIN ({$usoCreditoSubquery}) uc ON uc.cliente_id = c.id";

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*) {$from} {$whereSql}"
    );
    cli_bind($stmtTotal, $params);
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
            c.codigo,
            c.tipo_persona,
            c.nombre_razon_social,
            c.rfc,
            c.nivel_cliente_id,
            n.codigo AS nivel_codigo,
            n.nombre AS nivel_nombre,
            n.descuento_default_pct AS descuento_nivel_pct,
            c.descuento_personal_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct,
            c.telefono,
            c.correo,
            c.dias_credito,
            c.limite_credito,
            COALESCE(uc.saldo_usado_base, 0) AS credito_usado_base,
            COALESCE(uc.cuentas_abiertas, 0) AS cuentas_abiertas,
            c.activo,
            c.created_at
         {$from}
         {$whereSql}
         ORDER BY
            c.activo DESC,
            c.nombre_razon_social ASC,
            c.id DESC
         LIMIT :limite
         OFFSET :offset"
    );

    cli_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $clientes = $stmt->fetchAll();

    foreach ($clientes as &$cliente) {
        cli_cast_cliente_lista($cliente);
    }
    unset($cliente);

    $resumen = $conexion->query(
        "SELECT
            COUNT(*) AS total,
            SUM(c.activo = 1) AS activos,
            SUM(c.activo = 0) AS inactivos,
            SUM(c.dias_credito > 0) AS con_credito,
            SUM(c.dias_credito = 0) AS sin_credito
         FROM clientes c
         WHERE 1=1"
    )->fetch();

    si_responder_json(
        true,
        'Clientes cargados correctamente.',
        [
            'clientes' => $clientes,
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
                'sin_credito' => (int) ($resumen['sin_credito'] ?? 0),
            ],
        ]
    );
}

function cli_detalle_cliente(PDO $conexion): void
{
    $id = cli_id($_GET['id'] ?? null, 'cliente');

    $stmt = $conexion->prepare(
        "SELECT
            c.id,
            c.codigo,
            c.tipo_persona,
            c.nombre_razon_social,
            c.rfc,
            c.nivel_cliente_id,
            c.descuento_personal_pct,
            c.telefono,
            c.correo,
            c.calle,
            c.numero_exterior,
            c.numero_interior,
            c.colonia,
            c.municipio,
            c.estado,
            c.codigo_postal,
            c.pais,
            c.dias_credito,
            c.limite_credito,
            c.observaciones,
            c.activo,
            n.descuento_default_pct AS descuento_nivel_pct,
            COALESCE(c.descuento_personal_pct, n.descuento_default_pct, 0) AS descuento_efectivo_pct
         FROM clientes c
         LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
         WHERE c.id = :id
         LIMIT 1"
    );

    $stmt->execute([':id' => $id]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        si_responder_json(false, 'No se encontró el cliente seleccionado.', [], 404);
    }

    $cliente['id'] = (int) $cliente['id'];
    $cliente['nivel_cliente_id'] = $cliente['nivel_cliente_id'] !== null
        ? (int) $cliente['nivel_cliente_id']
        : null;
    $cliente['descuento_personal_pct'] = $cliente['descuento_personal_pct'] !== null
        ? (float) $cliente['descuento_personal_pct']
        : null;
    $cliente['descuento_nivel_pct'] = $cliente['descuento_nivel_pct'] !== null
        ? (float) $cliente['descuento_nivel_pct']
        : 0.0;
    $cliente['descuento_efectivo_pct'] = (float) $cliente['descuento_efectivo_pct'];
    $cliente['dias_credito'] = (int) $cliente['dias_credito'];
    $cliente['limite_credito'] = $cliente['limite_credito'] !== null
        ? (float) $cliente['limite_credito']
        : null;
    $cliente['activo'] = (int) $cliente['activo'];
    $cliente['credito_habilitado'] = $cliente['dias_credito'] > 0 ? 1 : 0;

    si_responder_json(true, 'Cliente encontrado.', ['cliente' => $cliente]);
}

function cli_guardar_cliente(PDO $conexion): void
{
    $idTexto = trim((string) ($_POST['cliente_id'] ?? ''));
    $id = $idTexto === '' ? 0 : cli_id($idTexto, 'cliente');
    $esNuevo = $id === 0;

    $nombre = cli_requerido(
        $_POST['nombre_razon_social'] ?? '',
        'El nombre o razón social es obligatorio.',
        180
    );

    $rfc = cli_normalizar_rfc($_POST['rfc'] ?? '');
    $tipoSolicitado = strtoupper(trim((string) ($_POST['tipo_persona'] ?? 'NO_ESPECIFICADO')));
    $tipoPersona = cli_tipo_persona($rfc, $tipoSolicitado);

    $nivelTexto = trim((string) ($_POST['nivel_cliente_id'] ?? ''));
    $nivelId = $nivelTexto === ''
        ? cli_nivel_general_id($conexion)
        : cli_id($nivelTexto, 'nivel de cliente');

    $descuentoPersonal = cli_decimal_nullable_porcentaje(
        $_POST['descuento_personal_pct'] ?? ''
    );

    $telefono = cli_nullable($_POST['telefono'] ?? '', 40);
    $correo = cli_correo_nullable($_POST['correo'] ?? '');

    $calle = cli_nullable($_POST['calle'] ?? '', 180);
    $numeroExterior = cli_nullable($_POST['numero_exterior'] ?? '', 30);
    $numeroInterior = cli_nullable($_POST['numero_interior'] ?? '', 30);
    $colonia = cli_nullable($_POST['colonia'] ?? '', 120);
    $municipio = cli_nullable($_POST['municipio'] ?? '', 120);
    $estadoDireccion = cli_nullable($_POST['estado'] ?? '', 120);
    $codigoPostal = cli_nullable($_POST['codigo_postal'] ?? '', 15);
    $pais = cli_nullable($_POST['pais'] ?? 'México', 80) ?? 'México';
    $observaciones = cli_nullable($_POST['observaciones'] ?? '', 10000);

    $creditoHabilitado = cli_bool($_POST['credito_habilitado'] ?? 0);

    if ($creditoHabilitado === 1) {
        $diasCredito = cli_entero_obligatorio_rango(
            $_POST['dias_credito'] ?? null,
            1,
            365,
            'Los días de crédito deben estar entre 1 y 365.'
        );

        $limiteCredito = cli_decimal_positivo(
            $_POST['limite_credito'] ?? null,
            'El límite de crédito debe ser mayor que cero.'
        );
    } else {
        $diasCredito = 0;
        $limiteCredito = null;
    }

    $conexion->beginTransaction();

    $anterior = null;

    if (!$esNuevo) {
        $anterior = cli_bloquear_cliente($conexion, $id);

        if (!$anterior) {
            cli_cancelar($conexion, 'El cliente ya no existe.', 404);
        }
    }

    cli_validar_rfc_unico($conexion, $rfc, $id);
    cli_validar_nivel($conexion, $nivelId, !$esNuevo && (int) ($anterior['nivel_cliente_id'] ?? 0) === $nivelId);

    if ($esNuevo) {
        $codigoTemporal = 'TMP-' . bin2hex(random_bytes(12));

        $stmt = $conexion->prepare(
            "INSERT INTO clientes
                (
                    codigo,
                    tipo_persona,
                    nombre_razon_social,
                    rfc,
                    nivel_cliente_id,
                    descuento_personal_pct,
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
                    dias_credito,
                    limite_credito,
                    observaciones,
                    activo,
                    created_by
                )
             VALUES
                (
                    :codigo,
                    :tipo_persona,
                    :nombre,
                    :rfc,
                    :nivel_id,
                    :descuento_personal,
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
                    :dias_credito,
                    :limite_credito,
                    :observaciones,
                    1,
                    :created_by
                )"
        );

        $stmt->execute([
            ':codigo' => $codigoTemporal,
            ':tipo_persona' => $tipoPersona,
            ':nombre' => $nombre,
            ':rfc' => $rfc,
            ':nivel_id' => $nivelId,
            ':descuento_personal' => $descuentoPersonal,
            ':telefono' => $telefono,
            ':correo' => $correo,
            ':calle' => $calle,
            ':numero_exterior' => $numeroExterior,
            ':numero_interior' => $numeroInterior,
            ':colonia' => $colonia,
            ':municipio' => $municipio,
            ':estado' => $estadoDireccion,
            ':codigo_postal' => $codigoPostal,
            ':pais' => $pais,
            ':dias_credito' => $diasCredito,
            ':limite_credito' => $limiteCredito,
            ':observaciones' => $observaciones,
            ':created_by' => (int) $_SESSION['usuario_id'],
        ]);

        $id = (int) $conexion->lastInsertId();
        $codigo = 'CLI-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);

        $conexion->prepare(
            "UPDATE clientes
             SET codigo = :codigo
             WHERE id = :id"
        )->execute([
            ':codigo' => $codigo,
            ':id' => $id,
        ]);

    } else {
        $codigo = (string) $anterior['codigo'];

        $stmt = $conexion->prepare(
            "UPDATE clientes
             SET
                tipo_persona = :tipo_persona,
                nombre_razon_social = :nombre,
                rfc = :rfc,
                nivel_cliente_id = :nivel_id,
                descuento_personal_pct = :descuento_personal,
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
                dias_credito = :dias_credito,
                limite_credito = :limite_credito,
                observaciones = :observaciones
             WHERE id = :id"
        );

        $stmt->execute([
            ':tipo_persona' => $tipoPersona,
            ':nombre' => $nombre,
            ':rfc' => $rfc,
            ':nivel_id' => $nivelId,
            ':descuento_personal' => $descuentoPersonal,
            ':telefono' => $telefono,
            ':correo' => $correo,
            ':calle' => $calle,
            ':numero_exterior' => $numeroExterior,
            ':numero_interior' => $numeroInterior,
            ':colonia' => $colonia,
            ':municipio' => $municipio,
            ':estado' => $estadoDireccion,
            ':codigo_postal' => $codigoPostal,
            ':pais' => $pais,
            ':dias_credito' => $diasCredito,
            ':limite_credito' => $limiteCredito,
            ':observaciones' => $observaciones,
            ':id' => $id,
        ]);
    }

    $nuevo = [
        'codigo' => $codigo,
        'tipo_persona' => $tipoPersona,
        'nombre_razon_social' => $nombre,
        'rfc' => $rfc,
        'nivel_cliente_id' => $nivelId,
        'descuento_personal_pct' => $descuentoPersonal,
        'telefono' => $telefono,
        'correo' => $correo,
        'dias_credito' => $diasCredito,
        'limite_credito' => $limiteCredito,
        'activo' => $esNuevo ? 1 : (int) $anterior['activo'],
    ];

    cli_auditar(
        $conexion,
        $esNuevo ? 'CLIENTE_CREADO' : 'CLIENTE_EDITADO',
        'clientes',
        $id,
        $esNuevo ? 'Se registró un cliente.' : 'Se actualizó un cliente.',
        $anterior ? cli_cliente_auditoria($anterior) : null,
        $nuevo
    );

    $conexion->commit();

    si_responder_json(
        true,
        $esNuevo
            ? 'Cliente registrado correctamente con código ' . $codigo . '.'
            : 'Cliente actualizado correctamente.',
        [
            'cliente_id' => $id,
            'codigo' => $codigo,
        ],
        $esNuevo ? 201 : 200
    );
}

function cli_cambiar_estado_cliente(PDO $conexion): void
{
    $id = cli_id($_POST['cliente_id'] ?? null, 'cliente');
    $activo = cli_estado($_POST['activo'] ?? null);

    $conexion->beginTransaction();

    $cliente = cli_bloquear_cliente($conexion, $id);

    if (!$cliente) {
        cli_cancelar($conexion, 'El cliente ya no existe.', 404);
    }

    if ((int) $cliente['activo'] === $activo) {
        $conexion->commit();
        si_responder_json(true, 'El cliente ya se encontraba en ese estado.');
    }

    if ($activo === 1 && $cliente['nivel_cliente_id'] !== null) {
        $stmtNivel = $conexion->prepare(
            "SELECT activo
             FROM niveles_cliente
             WHERE id = :id
             LIMIT 1"
        );
        $stmtNivel->execute([':id' => (int) $cliente['nivel_cliente_id']]);
        $nivelActivo = $stmtNivel->fetchColumn();

        if ($nivelActivo !== false && (int) $nivelActivo !== 1) {
            cli_cancelar(
                $conexion,
                'No puedes activar el cliente mientras su clasificación esté inactiva. Cambia primero su clasificación.',
                409
            );
        }
    }

    $conexion->prepare(
        "UPDATE clientes
         SET activo = :activo
         WHERE id = :id"
    )->execute([
        ':activo' => $activo,
        ':id' => $id,
    ]);

    cli_auditar(
        $conexion,
        $activo === 1 ? 'CLIENTE_ACTIVADO' : 'CLIENTE_DESACTIVADO',
        'clientes',
        $id,
        $activo === 1 ? 'Se activó un cliente.' : 'Se desactivó un cliente.',
        ['activo' => (int) $cliente['activo']],
        ['activo' => $activo]
    );

    $conexion->commit();

    si_responder_json(
        true,
        $activo === 1
            ? 'Cliente activado correctamente.'
            : 'Cliente desactivado correctamente.'
    );
}


/* =========================================================================
   CLASIFICACIÓN DE CLIENTES
   ========================================================================= */

function cli_listar_niveles(PDO $conexion): void
{
    $niveles = $conexion->query(
        "SELECT
            n.id,
            n.codigo,
            n.nombre,
            n.descuento_default_pct,
            n.activo,
            n.created_at,
            n.updated_at,
            (
                SELECT COUNT(*)
                FROM clientes c
                WHERE c.nivel_cliente_id = n.id
            ) AS clientes_asignados,
            (
                SELECT COUNT(*)
                FROM clientes c
                WHERE c.nivel_cliente_id = n.id
                  AND c.activo = 1
            ) AS clientes_activos
         FROM niveles_cliente n
         ORDER BY n.id ASC"
    )->fetchAll();

    foreach ($niveles as &$nivel) {
        $nivel['id'] = (int) $nivel['id'];
        $nivel['descuento_default_pct'] = (float) $nivel['descuento_default_pct'];
        $nivel['activo'] = (int) $nivel['activo'];
        $nivel['clientes_asignados'] = (int) $nivel['clientes_asignados'];
        $nivel['clientes_activos'] = (int) $nivel['clientes_activos'];
    }
    unset($nivel);

    si_responder_json(true, 'Clasificaciones cargadas.', ['niveles' => $niveles]);
}

function cli_guardar_nivel(PDO $conexion): void
{
    $id = cli_id($_POST['nivel_id'] ?? null, 'clasificación');
    $descuento = cli_decimal_porcentaje(
        $_POST['descuento_default_pct'] ?? null,
        'El descuento debe estar entre 0% y 100%.'
    );
    $activo = cli_estado($_POST['activo'] ?? 0);

    $conexion->beginTransaction();

    $stmt = $conexion->prepare(
        "SELECT
            id,
            codigo,
            nombre,
            descuento_default_pct,
            activo
         FROM niveles_cliente
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id]);
    $nivel = $stmt->fetch();

    if (!$nivel) {
        cli_cancelar($conexion, 'La clasificación ya no existe.', 404);
    }

    if (!in_array((string) $nivel['codigo'], ['GENERAL', 'DISTINGUIDO', 'PREFERENCIAL'], true)) {
        cli_cancelar(
            $conexion,
            'Esta clasificación no pertenece a las categorías oficiales del sistema.',
            409
        );
    }

    if ($activo === 0 && (int) $nivel['activo'] === 1) {
        $stmtUso = $conexion->prepare(
            "SELECT COUNT(*)
             FROM clientes
             WHERE nivel_cliente_id = :nivel_id
               AND activo = 1"
        );
        $stmtUso->execute([':nivel_id' => $id]);
        $clientesActivos = (int) $stmtUso->fetchColumn();

        if ($clientesActivos > 0) {
            cli_cancelar(
                $conexion,
                'No puedes desactivar esta clasificación porque tiene ' . $clientesActivos . ' cliente(s) activo(s) asignado(s). Reasígnalos primero.',
                409
            );
        }
    }

    $conexion->prepare(
        "UPDATE niveles_cliente
         SET
            descuento_default_pct = :descuento,
            activo = :activo
         WHERE id = :id"
    )->execute([
        ':descuento' => $descuento,
        ':activo' => $activo,
        ':id' => $id,
    ]);

    cli_auditar(
        $conexion,
        'CLASIFICACION_CLIENTE_EDITADA',
        'niveles_cliente',
        $id,
        'Se actualizó la clasificación de clientes ' . (string) $nivel['nombre'] . '.',
        [
            'codigo' => $nivel['codigo'],
            'nombre' => $nivel['nombre'],
            'descuento_default_pct' => (float) $nivel['descuento_default_pct'],
            'activo' => (int) $nivel['activo'],
        ],
        [
            'codigo' => $nivel['codigo'],
            'nombre' => $nivel['nombre'],
            'descuento_default_pct' => $descuento,
            'activo' => $activo,
        ]
    );

    $conexion->commit();

    si_responder_json(true, 'Clasificación actualizada correctamente.');
}

/* =========================================================================
   CRÉDITO DE CLIENTES
   ========================================================================= */

function cli_listar_credito(PDO $conexion): void
{
    $pagina = cli_entero_rango($_GET['pagina'] ?? 1, 1, PHP_INT_MAX, 1);
    $porPagina = cli_entero_rango($_GET['por_pagina'] ?? 20, 10, 100, 20);
    $q = cli_texto($_GET['busqueda'] ?? '', 180);
    $filtro = strtoupper(cli_texto($_GET['filtro'] ?? 'TODOS', 30));

    $validos = ['TODOS', 'CON_CREDITO', 'SIN_CREDITO', 'CERCA_LIMITE', 'EXCEDIDO'];
    if (!in_array($filtro, $validos, true)) {
        $filtro = 'TODOS';
    }

    $where = [
        'c.activo = 1',
    ];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(
            c.codigo LIKE :q_codigo
            OR c.nombre_razon_social LIKE :q_nombre
            OR c.rfc LIKE :q_rfc
        )';
        $params[':q_codigo'] = $like;
        $params[':q_nombre'] = $like;
        $params[':q_rfc'] = $like;
    }

    $usoCreditoSubquery = cli_subquery_uso_credito();
    $from = "FROM clientes c
             LEFT JOIN niveles_cliente n ON n.id = c.nivel_cliente_id
             LEFT JOIN ({$usoCreditoSubquery}) uc ON uc.cliente_id = c.id";

    if ($filtro === 'CON_CREDITO') {
        $where[] = 'c.dias_credito > 0';
    } elseif ($filtro === 'SIN_CREDITO') {
        $where[] = 'c.dias_credito = 0';
    } elseif ($filtro === 'CERCA_LIMITE') {
        $where[] = 'c.dias_credito > 0';
        $where[] = 'c.limite_credito IS NOT NULL';
        $where[] = 'c.limite_credito > 0';
        $where[] = 'COALESCE(uc.saldo_usado_base, 0) < c.limite_credito';
        $where[] = 'COALESCE(uc.saldo_usado_base, 0) >= (c.limite_credito * 0.80)';
    } elseif ($filtro === 'EXCEDIDO') {
        $where[] = 'c.dias_credito > 0';
        $where[] = 'c.limite_credito IS NOT NULL';
        $where[] = 'COALESCE(uc.saldo_usado_base, 0) > c.limite_credito';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmtTotal = $conexion->prepare(
        "SELECT COUNT(*) {$from} {$whereSql}"
    );
    cli_bind($stmtTotal, $params);
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
            c.codigo,
            c.nombre_razon_social,
            c.rfc,
            n.nombre AS nivel_nombre,
            c.dias_credito,
            c.limite_credito,
            COALESCE(uc.saldo_usado_base, 0) AS credito_usado_base,
            CASE
                WHEN c.limite_credito IS NULL THEN NULL
                ELSE c.limite_credito - COALESCE(uc.saldo_usado_base, 0)
            END AS credito_disponible_base,
            COALESCE(uc.cuentas_abiertas, 0) AS cuentas_abiertas,
            CASE
                WHEN c.dias_credito = 0 THEN 'SIN_CREDITO'
                WHEN c.limite_credito IS NOT NULL AND COALESCE(uc.saldo_usado_base, 0) > c.limite_credito THEN 'EXCEDIDO'
                WHEN c.limite_credito IS NOT NULL AND c.limite_credito > 0 AND COALESCE(uc.saldo_usado_base, 0) >= (c.limite_credito * 0.80) THEN 'CERCA_LIMITE'
                ELSE 'NORMAL'
            END AS estado_credito
         {$from}
         {$whereSql}
         ORDER BY
            CASE
                WHEN c.limite_credito IS NOT NULL AND COALESCE(uc.saldo_usado_base, 0) > c.limite_credito THEN 0
                WHEN c.limite_credito IS NOT NULL AND c.limite_credito > 0 AND COALESCE(uc.saldo_usado_base, 0) >= (c.limite_credito * 0.80) THEN 1
                WHEN c.dias_credito > 0 THEN 2
                ELSE 3
            END,
            c.nombre_razon_social ASC
         LIMIT :limite
         OFFSET :offset"
    );

    cli_bind($stmt, $params);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $clientes = $stmt->fetchAll();

    foreach ($clientes as &$cliente) {
        $cliente['id'] = (int) $cliente['id'];
        $cliente['dias_credito'] = (int) $cliente['dias_credito'];
        $cliente['limite_credito'] = $cliente['limite_credito'] !== null
            ? (float) $cliente['limite_credito']
            : null;
        $cliente['credito_usado_base'] = (float) $cliente['credito_usado_base'];
        $cliente['credito_disponible_base'] = $cliente['credito_disponible_base'] !== null
            ? (float) $cliente['credito_disponible_base']
            : null;
        $cliente['cuentas_abiertas'] = (int) $cliente['cuentas_abiertas'];
    }
    unset($cliente);

    $resumen = $conexion->query(
        "SELECT
            SUM(c.activo = 1 AND c.dias_credito > 0) AS clientes_credito,
            COALESCE(SUM(CASE WHEN c.activo = 1 AND c.dias_credito > 0 THEN c.limite_credito ELSE 0 END), 0) AS limite_total,
            COALESCE(SUM(CASE WHEN c.activo = 1 THEN uc.saldo_usado_base ELSE 0 END), 0) AS usado_total,
            SUM(
                c.activo = 1
                AND c.dias_credito > 0
                AND c.limite_credito IS NOT NULL
                AND COALESCE(uc.saldo_usado_base, 0) > c.limite_credito
            ) AS excedidos
         FROM clientes c
         LEFT JOIN ({$usoCreditoSubquery}) uc ON uc.cliente_id = c.id"
    )->fetch();

    $limiteTotal = (float) ($resumen['limite_total'] ?? 0);
    $usadoTotal = (float) ($resumen['usado_total'] ?? 0);

    $monedaBase = $conexion->query(
        "SELECT codigo, simbolo
         FROM monedas
         WHERE es_base = 1
           AND activo = 1
         ORDER BY id ASC
         LIMIT 1"
    )->fetch();

    si_responder_json(
        true,
        'Crédito de clientes cargado.',
        [
            'clientes' => $clientes,
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total_registros' => $total,
                'total_paginas' => $totalPaginas,
            ],
            'resumen' => [
                'clientes_credito' => (int) ($resumen['clientes_credito'] ?? 0),
                'limite_total' => $limiteTotal,
                'usado_total' => $usadoTotal,
                'disponible_total' => $limiteTotal - $usadoTotal,
                'excedidos' => (int) ($resumen['excedidos'] ?? 0),
            ],
            'moneda_base' => $monedaBase ?: null,
        ]
    );
}

/* =========================================================================
   HELPERS
   ========================================================================= */

function cli_subquery_uso_credito(): string
{
    return "SELECT
                cx.cliente_id,
                SUM(cx.saldo_pendiente * v.tipo_cambio_a_base) AS saldo_usado_base,
                COUNT(*) AS cuentas_abiertas
            FROM cuentas_por_cobrar cx
            INNER JOIN ventas v ON v.id = cx.venta_id
            WHERE cx.estado <> 'CANCELADA'
              AND cx.saldo_pendiente > 0
            GROUP BY cx.cliente_id";
}

function cli_bloquear_cliente(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare(
        "SELECT
            id,
            codigo,
            tipo_persona,
            nombre_razon_social,
            rfc,
            nivel_cliente_id,
            descuento_personal_pct,
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
            dias_credito,
            limite_credito,
            observaciones,
            activo
         FROM clientes
         WHERE id = :id
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

function cli_nivel_general_id(PDO $conexion): int
{
    $stmt = $conexion->query(
        "SELECT id
         FROM niveles_cliente
         WHERE codigo = 'GENERAL'
           AND activo = 1
         LIMIT 1"
    );

    $id = $stmt->fetchColumn();

    if ($id === false) {
        si_responder_json(
            false,
            'La clasificación GENERAL no está disponible. Actívala antes de registrar clientes.',
            [],
            409
        );
    }

    return (int) $id;
}

function cli_validar_nivel(PDO $conexion, int $nivelId, bool $permitirInactivoActual): void
{
    $stmt = $conexion->prepare(
        "SELECT activo
         FROM niveles_cliente
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $nivelId]);
    $activo = $stmt->fetchColumn();

    if ($activo === false) {
        cli_cancelar($conexion, 'La clasificación seleccionada ya no existe.', 409);
    }

    if ((int) $activo !== 1 && !$permitirInactivoActual) {
        cli_cancelar($conexion, 'La clasificación seleccionada está inactiva.', 409);
    }
}

function cli_validar_rfc_unico(PDO $conexion, ?string $rfc, int $excluirId): void
{
    if ($rfc === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT id
         FROM clientes
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
        cli_cancelar(
            $conexion,
            'Ya existe un cliente registrado con ese RFC.',
            409,
            ['campo' => 'rfc']
        );
    }
}

function cli_normalizar_rfc($valor): ?string
{
    $rfc = strtoupper(trim((string) $valor));
    $rfc = str_replace([' ', '-'], '', $rfc);

    if ($rfc === '') {
        return null;
    }

    if (!in_array(cli_strlen($rfc), [12, 13], true)) {
        si_responder_json(
            false,
            'El RFC debe contener 12 caracteres para persona moral o 13 para persona física.',
            ['campo' => 'rfc'],
            422
        );
    }

    if (!preg_match('/^[A-ZÑ&0-9]{12,13}$/u', $rfc)) {
        si_responder_json(
            false,
            'El RFC contiene caracteres no válidos.',
            ['campo' => 'rfc'],
            422
        );
    }

    return $rfc;
}

function cli_tipo_persona(?string $rfc, string $tipoSolicitado): string
{
    if ($rfc !== null) {
        if (cli_strlen($rfc) === 12) {
            return 'MORAL';
        }

        if (cli_strlen($rfc) === 13) {
            return 'FISICA';
        }
    }

    if (!in_array($tipoSolicitado, ['FISICA', 'MORAL', 'NO_ESPECIFICADO'], true)) {
        return 'NO_ESPECIFICADO';
    }

    return $tipoSolicitado;
}

function cli_cast_cliente_lista(array &$cliente): void
{
    $cliente['id'] = (int) $cliente['id'];
    $cliente['nivel_cliente_id'] = $cliente['nivel_cliente_id'] !== null
        ? (int) $cliente['nivel_cliente_id']
        : null;
    $cliente['descuento_nivel_pct'] = $cliente['descuento_nivel_pct'] !== null
        ? (float) $cliente['descuento_nivel_pct']
        : 0.0;
    $cliente['descuento_personal_pct'] = $cliente['descuento_personal_pct'] !== null
        ? (float) $cliente['descuento_personal_pct']
        : null;
    $cliente['descuento_efectivo_pct'] = (float) $cliente['descuento_efectivo_pct'];
    $cliente['dias_credito'] = (int) $cliente['dias_credito'];
    $cliente['limite_credito'] = $cliente['limite_credito'] !== null
        ? (float) $cliente['limite_credito']
        : null;
    $cliente['credito_usado_base'] = (float) $cliente['credito_usado_base'];
    $cliente['cuentas_abiertas'] = (int) $cliente['cuentas_abiertas'];
    $cliente['activo'] = (int) $cliente['activo'];
}

function cli_requerido($valor, string $mensaje, int $maximo): string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        si_responder_json(false, $mensaje, [], 422);
    }

    if (cli_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function cli_nullable($valor, int $maximo): ?string
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    if (cli_strlen($texto) > $maximo) {
        si_responder_json(false, 'El campo supera la longitud permitida.', [], 422);
    }

    return $texto;
}

function cli_correo_nullable($valor): ?string
{
    $correo = cli_nullable($valor, 180);

    if ($correo === null) {
        return null;
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        si_responder_json(
            false,
            'El correo electrónico no tiene un formato válido.',
            ['campo' => 'correo'],
            422
        );
    }

    return strtolower($correo);
}

function cli_texto($valor, int $maximo): string
{
    $texto = trim((string) $valor);

    if (cli_strlen($texto) > $maximo) {
        return cli_substr($texto, 0, $maximo);
    }

    return $texto;
}

function cli_decimal_nullable_porcentaje($valor): ?float
{
    $texto = trim((string) $valor);

    if ($texto === '') {
        return null;
    }

    return cli_decimal_porcentaje($texto, 'El descuento personal debe estar entre 0% y 100%.');
}

function cli_decimal_porcentaje($valor, string $mensaje): float
{
    if (!is_scalar($valor) || !is_numeric((string) $valor)) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $numero = (float) $valor;

    if ($numero < 0 || $numero > 100) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return $numero;
}

function cli_decimal_positivo($valor, string $mensaje): float
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

function cli_bool($valor): int
{
    return in_array((string) $valor, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function cli_estado($valor): int
{
    $numero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($numero === false || !in_array((int) $numero, [0, 1], true)) {
        si_responder_json(false, 'Estado inválido.', [], 422);
    }

    return (int) $numero;
}

function cli_id($valor, string $entidad): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);

    if ($id === false || (int) $id <= 0) {
        si_responder_json(false, 'Identificador de ' . $entidad . ' inválido.', [], 422);
    }

    return (int) $id;
}

function cli_entero_rango($valor, int $minimo, int $maximo, int $default): int
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

function cli_entero_obligatorio_rango($valor, int $minimo, int $maximo, string $mensaje): int
{
    $numero = filter_var($valor, FILTER_VALIDATE_INT);

    if ($numero === false) {
        si_responder_json(false, $mensaje, [], 422);
    }

    $numero = (int) $numero;

    if ($numero < $minimo || $numero > $maximo) {
        si_responder_json(false, $mensaje, [], 422);
    }

    return $numero;
}

function cli_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $clave => $valor) {
        if (is_int($valor)) {
            $stmt->bindValue($clave, $valor, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($clave, (string) $valor, PDO::PARAM_STR);
        }
    }
}

function cli_strlen(string $texto): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

function cli_substr(string $texto, int $inicio, int $longitud): string
{
    return function_exists('mb_substr')
        ? mb_substr($texto, $inicio, $longitud, 'UTF-8')
        : substr($texto, $inicio, $longitud);
}

function cli_cliente_auditoria(array $fila): array
{
    return [
        'codigo' => $fila['codigo'] ?? null,
        'tipo_persona' => $fila['tipo_persona'] ?? null,
        'nombre_razon_social' => $fila['nombre_razon_social'] ?? null,
        'rfc' => $fila['rfc'] ?? null,
        'nivel_cliente_id' => isset($fila['nivel_cliente_id']) && $fila['nivel_cliente_id'] !== null
            ? (int) $fila['nivel_cliente_id']
            : null,
        'descuento_personal_pct' => isset($fila['descuento_personal_pct']) && $fila['descuento_personal_pct'] !== null
            ? (float) $fila['descuento_personal_pct']
            : null,
        'telefono' => $fila['telefono'] ?? null,
        'correo' => $fila['correo'] ?? null,
        'dias_credito' => isset($fila['dias_credito']) ? (int) $fila['dias_credito'] : 0,
        'limite_credito' => isset($fila['limite_credito']) && $fila['limite_credito'] !== null
            ? (float) $fila['limite_credito']
            : null,
        'activo' => isset($fila['activo']) ? (int) $fila['activo'] : null,
    ];
}

function cli_auditar(
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
                'clientes',
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

function cli_cancelar(PDO $conexion, string $mensaje, int $codigo, array $extra = []): void
{
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }

    si_responder_json(false, $mensaje, $extra, $codigo);
}
