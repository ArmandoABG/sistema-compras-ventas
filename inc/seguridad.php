<?php

declare(strict_types=1);

const SI_ZONA_HORARIA = 'America/Mexico_City';
const SI_TIEMPO_INACTIVIDAD = 7200;
const SI_REGENERAR_SESION_CADA = 1800;

/*
|--------------------------------------------------------------------------
| SESIÓN V2
|--------------------------------------------------------------------------
| Cambiar el nombre fuerza una sesión limpia y evita reutilizar cookies /
| archivos de sesión creados por versiones anteriores del login.
|--------------------------------------------------------------------------
*/
const SI_NOMBRE_SESION = 'sistema_integral_session_v2';

date_default_timezone_set(SI_ZONA_HORARIA);

si_iniciar_sesion_segura();
si_cabeceras_seguridad();

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

function si_base_url(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;

    $projectRoot = realpath(dirname(__DIR__));

    if ($documentRoot && $projectRoot) {
        $doc = rtrim(
            str_replace('\\', '/', $documentRoot),
            '/'
        );

        $project = rtrim(
            str_replace('\\', '/', $projectRoot),
            '/'
        );

        if (stripos($project, $doc) === 0) {
            $relativa = trim(
                (string) substr(
                    $project,
                    strlen($doc)
                ),
                '/'
            );

            $base = $relativa === ''
                ? ''
                : '/' . $relativa;

            return $base;
        }
    }

    $base = '/sistema_integral';

    return $base;
}

function si_url(string $ruta = ''): string
{
    $base = rtrim(si_base_url(), '/');
    $ruta = ltrim($ruta, '/');

    return $ruta === ''
        ? ($base !== '' ? $base : '/')
        : $base . '/' . $ruta;
}

function si_es_https(): bool
{
    return (
        (
            !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        )
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower(
            (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
        ) === 'https'
    );
}

function si_iniciar_sesion_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (!headers_sent()) {
        session_name(SI_NOMBRE_SESION);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => si_base_url() !== ''
                ? si_base_url()
                : '/',
            'domain' => '',
            'secure' => si_es_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

function si_cabeceras_seguridad(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function si_es_ajax(): bool
{
    return strtolower(
        (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
    ) === 'xmlhttprequest';
}

function si_sesion_autenticada(): bool
{
    return isset($_SESSION['usuario_id'])
        && filter_var(
            $_SESSION['usuario_id'],
            FILTER_VALIDATE_INT
        ) !== false
        && (int) $_SESSION['usuario_id'] > 0;
}

function si_requerir_sesion(?bool $json = null): void
{
    if ($json === null) {
        $json = si_es_ajax();
    }

    if (!si_sesion_autenticada()) {
        si_sesion_invalida(
            $json,
            'Debes iniciar sesión para continuar.'
        );
    }

    $ultimaActividad = (int) (
        $_SESSION['ultima_actividad']
        ?? time()
    );

    if (
        (time() - $ultimaActividad)
        > SI_TIEMPO_INACTIVIDAD
    ) {
        si_cerrar_sesion_local();

        si_sesion_invalida(
            $json,
            'Tu sesión expiró por inactividad.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | La BD manda
    |--------------------------------------------------------------------------
    | Cada petición protegida confirma que:
    | - el usuario sigue activo;
    | - la sesión registrada sigue activa.
    |--------------------------------------------------------------------------
    */
    si_validar_sesion_en_bd($json);
    si_forzar_cambio_password_si_aplica($json);

    $_SESSION['ultima_actividad'] = time();

    $ultimaRegeneracion = (int) (
        $_SESSION['sesion_regenerada_en']
        ?? 0
    );

    if (
        $ultimaRegeneracion === 0
        || (
            time() - $ultimaRegeneracion
        ) >= SI_REGENERAR_SESION_CADA
    ) {
        session_regenerate_id(true);

        $_SESSION['sesion_regenerada_en'] = time();

        si_actualizar_token_sesion_bd();
    }
}

function si_obtener_conexion_seguridad(): ?PDO
{
    require_once __DIR__ . '/conexion.php';

    global $conexion;

    return $conexion instanceof PDO
        ? $conexion
        : null;
}

function si_validar_sesion_en_bd(bool $json): void
{
    $usuarioId = (int) (
        $_SESSION['usuario_id']
        ?? 0
    );

    $sesionDbId = (int) (
        $_SESSION['sesion_db_id']
        ?? 0
    );

    if ($usuarioId <= 0 || $sesionDbId <= 0) {
        si_cerrar_sesion_local();

        si_sesion_invalida(
            $json,
            'Tu sesión ya no es válida.'
        );
    }

    $conexion = si_obtener_conexion_seguridad();

    if (!$conexion) {
        /*
         * No destruimos la sesión por una caída momentánea de MySQL.
         * El módulo mostrará el error de conexión correspondiente.
         */
        return;
    }

    try {
        $stmt = $conexion->prepare(
            "SELECT
                u.activo,
                u.debe_cambiar_password,
                s.activa
             FROM sesiones_usuario s
             INNER JOIN usuarios u
                ON u.id = s.usuario_id
             WHERE s.id = :sesion_id
               AND s.usuario_id = :usuario_id
             LIMIT 1"
        );

        $stmt->execute([
            ':sesion_id' => $sesionDbId,
            ':usuario_id' => $usuarioId,
        ]);

        $estado = $stmt->fetch();

        if (
            !$estado
            || (int) $estado['activo'] !== 1
            || (int) $estado['activa'] !== 1
        ) {
            si_cerrar_sesion_local();

            si_sesion_invalida(
                $json,
                'Tu acceso fue desactivado o la sesión fue cerrada.'
            );
        }

        $_SESSION['debe_cambiar_password'] = (int) ($estado['debe_cambiar_password'] ?? 0);

    } catch (Throwable $e) {
        error_log(
            '[SISTEMA INTEGRAL][VALIDAR SESION] '
            . $e->getMessage()
        );
    }
}

function si_forzar_cambio_password_si_aplica(bool $json): void
{
    if ((int) ($_SESSION['debe_cambiar_password'] ?? 0) !== 1) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $permitidas = [
        '/JS/cambiar_password.php',
        '/funciones/perfil_funciones.php',
        '/funciones/logout.php',
    ];

    foreach ($permitidas as $permitida) {
        if ($script !== '' && str_ends_with($script, $permitida)) {
            return;
        }
    }

    $redirect = si_url('JS/cambiar_password.php');

    if ($json) {
        si_responder_json(
            false,
            'Debes cambiar tu contraseña temporal antes de continuar.',
            [
                'cambio_password_requerido' => true,
                'redirect' => $redirect,
            ],
            403
        );
    }

    header('Location: ' . $redirect);
    exit;
}

function si_actualizar_token_sesion_bd(): void
{
    $usuarioId = (int) (
        $_SESSION['usuario_id']
        ?? 0
    );

    $sesionDbId = (int) (
        $_SESSION['sesion_db_id']
        ?? 0
    );

    if ($usuarioId <= 0 || $sesionDbId <= 0) {
        return;
    }

    $conexion = si_obtener_conexion_seguridad();

    if (!$conexion) {
        return;
    }

    try {
        $stmt = $conexion->prepare(
            "UPDATE sesiones_usuario
             SET token_hash = :token_hash
             WHERE id = :id
               AND usuario_id = :usuario_id
               AND activa = 1"
        );

        $stmt->execute([
            ':token_hash' => hash('sha256', session_id()),
            ':id' => $sesionDbId,
            ':usuario_id' => $usuarioId,
        ]);

    } catch (Throwable $e) {
        error_log(
            '[SISTEMA INTEGRAL][TOKEN SESION] '
            . $e->getMessage()
        );
    }
}

function si_token_csrf(
    string $clave = 'csrf_token'
): string {
    if (
        empty($_SESSION[$clave])
        || !is_string($_SESSION[$clave])
        || strlen($_SESSION[$clave]) < 64
    ) {
        $_SESSION[$clave] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION[$clave];
}

function si_csrf_valido(
    ?string $token,
    string $clave = 'csrf_token'
): bool {
    $tokenSesion = $_SESSION[$clave] ?? '';

    return is_string($token)
        && is_string($tokenSesion)
        && $token !== ''
        && $tokenSesion !== ''
        && hash_equals(
            $tokenSesion,
            $token
        );
}

function si_validar_csrf(
    string $clave = 'csrf_token'
): void {
    $token = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;

    if (
        !si_csrf_valido(
            is_string($token)
                ? $token
                : null,
            $clave
        )
    ) {
        si_responder_json(
            false,
            'La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.',
            ['csrf_invalido' => true],
            419
        );
    }
}

function si_requerir_metodo(string $metodo): void
{
    $metodo = strtoupper(trim($metodo));

    $actual = strtoupper(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

    if ($actual !== $metodo) {
        if (!headers_sent()) {
            header('Allow: ' . $metodo);
        }

        si_responder_json(
            false,
            'Método de solicitud no permitido.',
            [],
            405
        );
    }
}

/*
|--------------------------------------------------------------------------
| IDENTIDAD AUTORITATIVA
|--------------------------------------------------------------------------
| NO se confía únicamente en $_SESSION['permisos'].
| La identidad se reconstruye directamente desde MySQL.
|--------------------------------------------------------------------------
*/

function si_cargar_identidad_sesion(
    PDO $conexion,
    int $usuarioId
): array {
    $stmtRoles = $conexion->prepare(
        "SELECT
            r.id,
            r.codigo,
            r.nombre
         FROM usuarios_roles ur
         INNER JOIN roles r
            ON r.id = ur.rol_id
         INNER JOIN usuarios u
            ON u.id = ur.usuario_id
         WHERE ur.usuario_id = :usuario_id
           AND u.activo = 1
           AND r.activo = 1
         ORDER BY
            CASE r.codigo
                WHEN 'ADMINISTRADOR' THEN 1
                WHEN 'VENDEDOR' THEN 2
                WHEN 'SUPERVISOR_ALMACEN' THEN 3
                ELSE 99
            END,
            r.nombre"
    );

    $stmtRoles->execute([
        ':usuario_id' => $usuarioId,
    ]);

    $roles = $stmtRoles->fetchAll();

    $stmtPermisos = $conexion->prepare(
        "SELECT DISTINCT
            p.codigo
         FROM usuarios_roles ur
         INNER JOIN roles r
            ON r.id = ur.rol_id
           AND r.activo = 1
         INNER JOIN roles_permisos rp
            ON rp.rol_id = r.id
         INNER JOIN permisos p
            ON p.id = rp.permiso_id
         INNER JOIN usuarios u
            ON u.id = ur.usuario_id
         WHERE ur.usuario_id = :usuario_id
           AND u.activo = 1
         ORDER BY p.codigo"
    );

    $stmtPermisos->execute([
        ':usuario_id' => $usuarioId,
    ]);

    $permisos = array_column(
        $stmtPermisos->fetchAll(),
        'codigo'
    );

    $_SESSION['roles'] = array_column(
        $roles,
        'codigo'
    );

    $_SESSION['rol_codigo'] = (string) (
        $roles[0]['codigo']
        ?? ''
    );

    $_SESSION['rol_nombre'] = (string) (
        $roles[0]['nombre']
        ?? 'Usuario'
    );

    $_SESSION['permisos'] = $permisos;

    return [
        'roles' => $roles,
        'permisos' => $permisos,
    ];
}

function si_refrescar_identidad_sesion_actual(): array
{
    if (!si_sesion_autenticada()) {
        return [
            'roles' => [],
            'permisos' => [],
        ];
    }

    $conexion = si_obtener_conexion_seguridad();

    if (!$conexion) {
        return [
            'roles' => $_SESSION['roles'] ?? [],
            'permisos' => $_SESSION['permisos'] ?? [],
        ];
    }

    /*
     * La identidad se refresca desde la BD, pero no se vuelve a sembrar ni
     * actualizar el catálogo de roles/permisos en cada petición. Ese trabajo
     * corresponde al login, a la administración de roles y a la instalación.
     */
    return si_cargar_identidad_sesion(
        $conexion,
        (int) $_SESSION['usuario_id']
    );
}

function si_tiene_permiso(string $codigo): bool
{
    $roles = $_SESSION['roles'] ?? [];

    if (
        is_array($roles)
        && in_array(
            'ADMINISTRADOR',
            $roles,
            true
        )
    ) {
        return true;
    }

    $permisos = $_SESSION['permisos'] ?? [];

    return is_array($permisos)
        && in_array(
            $codigo,
            $permisos,
            true
        );
}

function si_requerir_permiso(
    string $codigo,
    ?bool $json = null
): void {
    si_requerir_sesion($json);

    /*
    |--------------------------------------------------------------------------
    | CLAVE DE LA CORRECCIÓN
    |--------------------------------------------------------------------------
    | SIEMPRE recargamos roles y permisos desde la base real ANTES de decidir.
    | No importa qué haya quedado guardado en una sesión anterior.
    |--------------------------------------------------------------------------
    */
    try {
        si_refrescar_identidad_sesion_actual();

    } catch (Throwable $e) {
        error_log(
            '[SISTEMA INTEGRAL][AUTORIZACION] '
            . $e->getMessage()
        );

        if ($json ?? si_es_ajax()) {
            si_responder_json(
                false,
                'No fue posible validar tus permisos en la base de datos.',
                [],
                500
            );
        }

        http_response_code(500);

        echo '<!doctype html><html lang="es"><meta charset="utf-8">'
            . '<title>Error de autorización</title>'
            . '<body style="font-family:Arial;padding:30px">'
            . '<h1>No fue posible validar los permisos</h1>'
            . '<p>Revisa el log de PHP. El sistema ya no oculta este error como "acceso denegado".</p>'
            . '<p><a href="' . si_escapar(si_url('JS/dashboard.php')) . '">Volver al dashboard</a></p>'
            . '</body></html>';

        exit;
    }

    if (si_tiene_permiso($codigo)) {
        return;
    }

    /*
     * Si de verdad no tiene permiso, ahora sí es un 403 real.
     */
    if ($json ?? si_es_ajax()) {
        si_responder_json(
            false,
            'No tienes permiso para realizar esta acción.',
            [
                'permiso_requerido' => $codigo,
                'roles_detectados' => $_SESSION['roles'] ?? [],
            ],
            403
        );
    }

    http_response_code(403);

    $roles = implode(
        ', ',
        array_map(
            'strval',
            $_SESSION['roles'] ?? []
        )
    );

    echo '<!doctype html><html lang="es"><meta charset="utf-8">'
        . '<title>Acceso denegado</title>'
        . '<body style="font-family:Arial;padding:30px">'
        . '<h1>Acceso denegado</h1>'
        . '<p>Permiso requerido: <strong>'
        . si_escapar($codigo)
        . '</strong></p>'
        . '<p>Rol(es) detectado(s): <strong>'
        . si_escapar($roles !== '' ? $roles : 'ninguno')
        . '</strong></p>'
        . '<p><a href="'
        . si_escapar(si_url('JS/dashboard.php'))
        . '">Volver al dashboard</a></p>'
        . '</body></html>';

    exit;
}

function si_sesion_invalida(
    bool $json,
    string $mensaje
): void {
    if ($json) {
        si_responder_json(
            false,
            $mensaje,
            [
                'sesion_expirada' => true,
                'redirect' => si_url(
                    'login.php?sesion=expirada'
                ),
            ],
            401
        );
    }

    header(
        'Location: '
        . si_url(
            'login.php?sesion=expirada'
        )
    );

    exit;
}

function si_responder_json(
    bool $success,
    string $mensaje,
    array $extra = [],
    int $codigoHttp = 200
): void {
    if (!headers_sent()) {
        http_response_code($codigoHttp);
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );
    }

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'mensaje' => $mensaje,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function si_escapar($valor): string
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function si_ip_cliente(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    return is_string($ip)
        && $ip !== ''
        ? substr($ip, 0, 45)
        : null;
}

function si_user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    return is_string($ua)
        && $ua !== ''
        ? substr($ua, 0, 500)
        : null;
}

function si_cerrar_sesion_local(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (
        session_status()
        === PHP_SESSION_ACTIVE
    ) {
        session_destroy();
    }
}

function si_destruir_sesion(): void
{
    si_cerrar_sesion_local();
}
