<?php

declare(strict_types=1);

require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/stock_operativo.php';

function si_mantenimiento_cotizaciones_vencidas(PDO $conexion, int $limite = 500): int
{
    $limite = max(1, min(1000, $limite));
    $propia = !$conexion->inTransaction();
    if ($propia) {
        $conexion->beginTransaction();
    }

    try {
        $stmt = $conexion->query(
            "SELECT id, folio FROM cotizaciones
             WHERE estado = 'GENERADA' AND vigencia_hasta IS NOT NULL AND vigencia_hasta < NOW()
             ORDER BY vigencia_hasta ASC, id ASC LIMIT {$limite} FOR UPDATE"
        );
        $filas = $stmt->fetchAll();
        $actualizar = $conexion->prepare(
            "UPDATE cotizaciones SET estado = 'VENCIDA' WHERE id = :id AND estado = 'GENERADA'"
        );
        $auditar = $conexion->prepare(
            "INSERT INTO auditoria
                (usuario_id, accion, modulo, entidad_tabla, entidad_id, descripcion,
                 datos_anteriores, datos_nuevos, ip, user_agent)
             VALUES
                (NULL, 'COTIZACION_VENCIDA_AUTOMATICA', 'cotizaciones', 'cotizaciones', :id, :descripcion,
                 JSON_OBJECT('estado', 'GENERADA'), JSON_OBJECT('estado', 'VENCIDA'), NULL,
                 'Mantenimiento operativo programado')"
        );
        $procesadas = 0;

        foreach ($filas as $fila) {
            $actualizar->execute([':id' => (int) $fila['id']]);
            if ($actualizar->rowCount() !== 1) {
                continue;
            }
            $procesadas++;
            $auditar->execute([
                ':id' => (int) $fila['id'],
                ':descripcion' => 'La cotización ' . $fila['folio'] . ' venció por fecha de vigencia.',
            ]);
        }

        if ($propia) {
            $conexion->commit();
        }
        return $procesadas;
    } catch (Throwable $e) {
        if ($propia && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

function si_mantenimiento_sesiones_expiradas(PDO $conexion): int
{
    $limite = date('Y-m-d H:i:s', time() - SI_TIEMPO_INACTIVIDAD);
    $stmt = $conexion->prepare(
        "UPDATE sesiones_usuario
         SET activa = 0,
             fin_sesion = COALESCE(fin_sesion, ultima_actividad, inicio_sesion),
             motivo_cierre = COALESCE(NULLIF(TRIM(motivo_cierre), ''), 'EXPIRADA_INACTIVIDAD')
         WHERE activa = 1 AND COALESCE(ultima_actividad, inicio_sesion) < :limite"
    );
    $stmt->execute([':limite' => $limite]);
    return $stmt->rowCount();
}

function si_mantenimiento_operativo_ejecutar(PDO $conexion): array
{
    $resultado = si_stock_preparar_operacion($conexion);

    $cotizaciones = 0;
    do {
        $lote = si_mantenimiento_cotizaciones_vencidas($conexion, 500);
        $cotizaciones += $lote;
    } while ($lote === 500);

    $resultado['cotizaciones_vencidas'] = $cotizaciones;
    $resultado['sesiones_expiradas'] = si_mantenimiento_sesiones_expiradas($conexion);
    return $resultado;
}
