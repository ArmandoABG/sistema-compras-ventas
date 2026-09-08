<?php

declare(strict_types=1);

/**
 * Idempotencia transaccional para operaciones con efectos financieros.
 *
 * La reserva y la operación de negocio deben ejecutarse dentro de la misma
 * transacción. Así, una operación fallida tampoco deja una clave huérfana.
 */

function si_idempotencia_desde_post(): array
{
    $clave = trim((string) ($_POST['idempotency_key'] ?? ''));
    if ($clave === '') {
        return ['clave' => null, 'hash' => null];
    }

    if (
        strlen($clave) < 16
        || strlen($clave) > 100
        || !preg_match('/^[A-Za-z0-9._:-]+$/', $clave)
    ) {
        si_responder_json(
            false,
            'La clave de seguridad de la operación no es válida. Recarga el formulario e inténtalo nuevamente.',
            ['campo' => 'idempotency_key'],
            422
        );
    }

    $contenido = $_POST;
    unset($contenido['csrf_token'], $contenido['accion'], $contenido['idempotency_key']);

    return [
        'clave' => $clave,
        'hash' => hash('sha256', si_idempotencia_json_canonico($contenido)),
    ];
}

function si_idempotencia_json_canonico(mixed $valor): string
{
    $normalizado = si_idempotencia_normalizar($valor);
    $json = json_encode(
        $normalizado,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );

    return $json;
}

function si_idempotencia_normalizar(mixed $valor): mixed
{
    if (!is_array($valor)) {
        return $valor;
    }

    if (!array_is_list($valor)) {
        ksort($valor, SORT_STRING);
    }

    foreach ($valor as $clave => $elemento) {
        $valor[$clave] = si_idempotencia_normalizar($elemento);
    }

    return $valor;
}

/**
 * Reserva la clave. Devuelve la respuesta anterior cuando la misma solicitud
 * ya terminó; devuelve null cuando la llamada actual obtuvo la reserva.
 */
function si_idempotencia_reservar(
    PDO $conexion,
    string $operacion,
    ?string $clave,
    ?string $hash,
    int $usuarioId
): ?array {
    if ($clave === null) {
        return null;
    }
    if (!$conexion->inTransaction()) {
        throw new LogicException('La idempotencia debe reservarse dentro de una transacción.');
    }

    $stmt = $conexion->prepare(
        "INSERT INTO operaciones_idempotentes
            (operacion, clave, solicitud_hash, estado, created_by)
         VALUES
            (:operacion, :clave, :hash, 'EN_PROCESO', :usuario)
         ON DUPLICATE KEY UPDATE clave = VALUES(clave)"
    );
    $stmt->execute([
        ':operacion' => $operacion,
        ':clave' => $clave,
        ':hash' => $hash,
        ':usuario' => $usuarioId > 0 ? $usuarioId : null,
    ]);

    if ($stmt->rowCount() === 1) {
        return null;
    }

    $existente = $conexion->prepare(
        "SELECT solicitud_hash, estado, mensaje, respuesta_json, http_status, created_by
         FROM operaciones_idempotentes
         WHERE operacion = :operacion AND clave = :clave
         LIMIT 1
         FOR UPDATE"
    );
    $existente->execute([':operacion' => $operacion, ':clave' => $clave]);
    $fila = $existente->fetch();

    if (!$fila) {
        throw new RuntimeException('No fue posible recuperar la reserva idempotente.');
    }

    if (
        !hash_equals((string) $fila['solicitud_hash'], (string) $hash)
        || (int) ($fila['created_by'] ?? 0) !== $usuarioId
    ) {
        $conexion->rollBack();
        si_responder_json(
            false,
            'Esta clave de operación ya fue utilizada con información diferente. Recarga el formulario antes de continuar.',
            ['campo' => 'idempotency_key'],
            409
        );
    }

    if ((string) $fila['estado'] !== 'COMPLETADA') {
        throw new RuntimeException('La operación idempotente anterior no terminó correctamente.');
    }

    $datos = json_decode((string) ($fila['respuesta_json'] ?? '{}'), true);

    return [
        'mensaje' => (string) ($fila['mensaje'] ?? 'La operación ya había sido procesada.'),
        'datos' => is_array($datos) ? $datos : [],
        'http_status' => (int) ($fila['http_status'] ?? 200),
    ];
}

function si_idempotencia_completar(
    PDO $conexion,
    string $operacion,
    ?string $clave,
    string $recursoTipo,
    int $recursoId,
    string $mensaje,
    array $datos,
    int $httpStatus = 201
): void {
    if ($clave === null) {
        return;
    }

    $stmt = $conexion->prepare(
        "UPDATE operaciones_idempotentes
         SET estado = 'COMPLETADA', recurso_tipo = :tipo, recurso_id = :recurso_id,
             mensaje = :mensaje, respuesta_json = :respuesta, http_status = :http_status
         WHERE operacion = :operacion AND clave = :clave AND estado = 'EN_PROCESO'"
    );
    $stmt->execute([
        ':tipo' => $recursoTipo,
        ':recurso_id' => $recursoId,
        ':mensaje' => $mensaje,
        ':respuesta' => si_idempotencia_json_canonico($datos),
        ':http_status' => $httpStatus,
        ':operacion' => $operacion,
        ':clave' => $clave,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('No fue posible completar el registro idempotente.');
    }
}

function si_idempotencia_responder_repetida(PDO $conexion, ?array $respuesta): void
{
    if ($respuesta === null) {
        return;
    }

    if ($conexion->inTransaction()) {
        $conexion->commit();
    }

    $datos = $respuesta['datos'];
    $datos['idempotencia_reutilizada'] = true;
    si_responder_json(true, $respuesta['mensaje'], $datos, 200);
}
