<?php

declare(strict_types=1);

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

function si_smtp_enviar(array $config, string $destino, string $nombreDestino, string $asunto, string $html): array
{
    $host = trim((string) ($config['smtp_host'] ?? ''));
    $port = (int) ($config['smtp_port'] ?? 587);
    $usuario = trim((string) ($config['smtp_usuario'] ?? ''));
    $password = (string) ($config['smtp_password'] ?? '');
    $from = trim((string) ($config['remitente_correo'] ?? $usuario));
    $fromNombre = trim((string) ($config['remitente_nombre'] ?? 'Sistema Integral'));
    $timeout = max(5, min(30, (int) ($config['smtp_timeout'] ?? 12)));

    if ($host === '' || $port <= 0 || $usuario === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'La configuración SMTP está incompleta.'];
    }
    if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo destinatario no es válido.'];
    }
    if (!function_exists('stream_socket_client') || !function_exists('stream_socket_enable_crypto')) {
        return ['ok' => false, 'error' => 'PHP no tiene habilitado el soporte de sockets/TLS necesario para SMTP.'];
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        return ['ok' => false, 'error' => 'No fue posible conectar con el servidor SMTP.'];
    }

    stream_set_timeout($socket, $timeout);

    try {
        si_smtp_esperar($socket, [220]);
        si_smtp_comando($socket, 'EHLO sistema-integral.local', [250]);
        si_smtp_comando($socket, 'STARTTLS', [220]);

        $metodoTls = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            : STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (@stream_socket_enable_crypto($socket, true, $metodoTls) !== true) {
            throw new RuntimeException('No fue posible establecer la conexión TLS con el servidor SMTP.');
        }

        si_smtp_comando($socket, 'EHLO sistema-integral.local', [250]);
        si_smtp_comando($socket, 'AUTH LOGIN', [334]);
        si_smtp_comando($socket, base64_encode($usuario), [334]);
        si_smtp_comando($socket, base64_encode($password), [235]);
        si_smtp_comando($socket, 'MAIL FROM:<' . $from . '>', [250]);
        si_smtp_comando($socket, 'RCPT TO:<' . $destino . '>', [250, 251]);
        si_smtp_comando($socket, 'DATA', [354]);

        $boundary = 'si_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@sistema-integral.local>',
            'From: ' . si_smtp_nombre($fromNombre) . ' <' . $from . '>',
            'To: ' . si_smtp_nombre($nombreDestino) . ' <' . $destino . '>',
            'Subject: ' . si_smtp_codificar_cabecera($asunto),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $textoPlano = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $mensaje = implode("\r\n", $headers) . "\r\n\r\n";
        $mensaje .= '--' . $boundary . "\r\n";
        $mensaje .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $mensaje .= quoted_printable_encode($textoPlano) . "\r\n";
        $mensaje .= '--' . $boundary . "\r\n";
        $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $mensaje .= quoted_printable_encode($html) . "\r\n";
        $mensaje .= '--' . $boundary . "--\r\n";

        $mensaje = preg_replace('/(?m)^\./', '..', $mensaje) ?? $mensaje;
        fwrite($socket, $mensaje . "\r\n.\r\n");
        si_smtp_esperar($socket, [250]);
        si_smtp_comando($socket, 'QUIT', [221]);
        fclose($socket);

        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
        }
        return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
    }
}

function si_smtp_nombre(string $valor): string
{
    $valor = trim(preg_replace('/[\r\n]+/', ' ', $valor) ?? '');
    return $valor === '' ? 'Usuario' : si_smtp_codificar_cabecera($valor);
}

function si_smtp_codificar_cabecera(string $valor): string
{
    $valor = trim(preg_replace('/[\r\n]+/', ' ', $valor) ?? '');
    return '=?UTF-8?B?' . base64_encode($valor) . '?=';
}

function si_smtp_comando($socket, string $comando, array $esperados): string
{
    if (@fwrite($socket, $comando . "\r\n") === false) {
        throw new RuntimeException('Se perdió la conexión con el servidor SMTP.');
    }
    return si_smtp_esperar($socket, $esperados);
}

function si_smtp_esperar($socket, array $esperados): string
{
    $respuesta = '';
    while (($linea = fgets($socket, 2048)) !== false) {
        $respuesta .= $linea;
        if (strlen($linea) >= 4 && $linea[3] === ' ') {
            break;
        }
    }

    $meta = stream_get_meta_data($socket);
    if (!empty($meta['timed_out'])) {
        throw new RuntimeException('El servidor SMTP tardó demasiado en responder.');
    }

    $codigo = (int) substr($respuesta, 0, 3);
    if (!in_array($codigo, $esperados, true)) {
        $detalle = trim(preg_replace('/\s+/', ' ', $respuesta) ?? '');
        throw new RuntimeException('SMTP rechazó la operación (' . $codigo . '): ' . mb_substr($detalle, 0, 240));
    }

    return $respuesta;
}
