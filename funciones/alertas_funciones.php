<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/seguridad.php';
require_once __DIR__ . '/../inc/conexion.php';

/** @var PDO|null $conexion Conexión creada por inc/conexion.php. */
require_once __DIR__ . '/../inc/alertas_operativas.php';

si_requerir_sesion(true);

if (!($conexion instanceof PDO)) {
    si_responder_json(false, 'No fue posible conectar con la base de datos.', [], 503);
}

try {
    si_refrescar_identidad_sesion_actual();

    $accion = strtoupper(trim((string) ($_GET['accion'] ?? $_POST['accion'] ?? 'RESUMEN')));
    $puedeActualizarTipoCambio = si_tiene_permiso('ventas.crear')
        || si_tiene_permiso('compras.crear')
        || si_tiene_permiso('cuentas_cobrar.cobrar')
        || si_tiene_permiso('cuentas_pagar.pagar');

    if ($accion === 'TIPO_CAMBIO_ESTADO') {
        si_requerir_metodo('GET');
        $estado = si_tc_banxico_estado($conexion, false);
        si_responder_json(true, 'Tipo de cambio consultado.', [
            'tipo_cambio' => $estado['tipo_cambio'] ?? null,
            'fecha' => $estado['fecha'] ?? null,
            'fuente' => $estado['fuente'] ?? null,
            'codigo' => $estado['codigo'] ?? null,
            'desactualizado' => (bool) ($estado['desactualizado'] ?? true),
            'dias_habiles_antiguedad' => $estado['dias_habiles_antiguedad'] ?? null,
            'puede_actualizar' => $puedeActualizarTipoCambio,
        ]);
    }

    if ($accion === 'RESUMEN') {
        si_requerir_metodo('GET');
        $resumen = si_alertas_operativas_resumen($conexion, false);
        si_responder_json(true, 'Alertas cargadas correctamente.', [
            'alertas_operativas' => $resumen,
        ]);
    }

    if ($accion === 'ACTUALIZAR_TIPO_CAMBIO') {
        si_requerir_metodo('POST');
        si_validar_csrf();

        if (!$puedeActualizarTipoCambio) {
            si_responder_json(false, 'No tienes permiso para actualizar el tipo de cambio.', [], 403);
        }

        $estado = si_tc_banxico_sincronizar($conexion, true);
        $codigo = strtoupper((string) ($estado['codigo'] ?? ''));
        $detalleTecnico = trim((string) (si_tc_config_valor($conexion, 'banxico.ultimo_mensaje') ?? ''));

        $datos = [
            'tipo_cambio' => $estado['tipo_cambio'] ?? null,
            'fecha' => $estado['fecha'] ?? null,
            'fuente' => $estado['fuente'] ?? null,
            'codigo' => $codigo,
            'alertas_operativas' => si_alertas_operativas_resumen($conexion, true),
        ];

        if ($codigo === 'ACTUALIZADO') {
            si_responder_json(true, 'Tipo de cambio actualizado correctamente desde Banco de México.', $datos);
        }

        $mensajeError = 'No fue posible actualizar el tipo de cambio desde Banco de México. Se conserva el último valor local disponible.';
        if ($detalleTecnico !== '' && strcasecmp($detalleTecnico, 'Consulta correcta.') !== 0) {
            $mensajeError .= ' Detalle: ' . $detalleTecnico;
        }

        si_responder_json(false, $mensajeError, $datos, 502);
    }

    if (!in_array($accion, ['MARCAR_LEIDA', 'MARCAR_TODAS_LEIDAS'], true)) {
        si_responder_json(false, 'La acción solicitada no es válida.', [], 400);
    }

    si_requerir_metodo('POST');
    si_validar_csrf();

    $resumenActual = si_alertas_operativas_resumen($conexion, false);
    $alertasActuales = is_array($resumenActual['alertas'] ?? null)
        ? $resumenActual['alertas']
        : [];

    if ($accion === 'MARCAR_LEIDA') {
        $clave = trim((string) ($_POST['clave'] ?? ''));
        if ($clave === '' || strlen($clave) > 120) {
            si_responder_json(false, 'La alerta seleccionada no es válida.', [], 422);
        }

        $alerta = null;
        foreach ($alertasActuales as $item) {
            if ((string) ($item['clave'] ?? '') === $clave) {
                $alerta = $item;
                break;
            }
        }

        if ($alerta === null) {
            si_responder_json(false, 'La alerta ya no está activa o no está disponible para tu usuario.', [], 404);
        }

        $conexion->beginTransaction();
        si_alertas_marcar_leida($conexion, $alerta);
        si_alertas_auditar_lectura(
            $conexion,
            'El usuario marcó una alerta operativa como leída.',
            [
                'clave' => (string) $alerta['clave'],
                'categoria' => (string) ($alerta['categoria'] ?? ''),
                'prioridad' => (string) ($alerta['prioridad'] ?? ''),
                'conteo' => (int) ($alerta['conteo'] ?? 0),
            ]
        );
        $conexion->commit();

        $resumen = si_alertas_operativas_resumen($conexion, true);
        si_responder_json(true, 'Alerta marcada como leída.', [
            'alertas_operativas' => $resumen,
        ]);
    }

    $pendientes = array_values(array_filter(
        $alertasActuales,
        static fn(array $item): bool => !(bool) ($item['leida'] ?? false)
    ));

    if (!$pendientes) {
        si_responder_json(true, 'No hay alertas nuevas por marcar.', [
            'alertas_operativas' => si_alertas_operativas_resumen($conexion, true),
        ]);
    }

    $conexion->beginTransaction();
    foreach ($pendientes as $alerta) {
        si_alertas_marcar_leida($conexion, $alerta);
    }
    si_alertas_auditar_lectura(
        $conexion,
        'El usuario marcó todas sus alertas operativas visibles como leídas.',
        [
            'grupos' => count($pendientes),
            'claves' => array_values(array_map(
                static fn(array $item): string => (string) ($item['clave'] ?? ''),
                $pendientes
            )),
        ]
    );
    $conexion->commit();

    $resumen = si_alertas_operativas_resumen($conexion, true);
    si_responder_json(true, 'Alertas marcadas como leídas.', [
        'alertas_operativas' => $resumen,
    ]);
} catch (Throwable $e) {
    if (($conexion instanceof PDO) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    $referencia = 'ALT-' . date('Ymd-His');
    error_log(
        '[' . $referencia . '][ALERTAS] '
        . $e->getMessage()
        . ' | '
        . $e->getFile()
        . ':'
        . $e->getLine()
    );

    si_responder_json(
        false,
        $e instanceof RuntimeException
            ? $e->getMessage()
            : 'No fue posible procesar las alertas operativas.',
        ['referencia' => $referencia],
        500
    );
}
