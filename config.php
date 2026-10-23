<?php

/**
 * Observatorio API — configuración (.env).
 * Calcada de referencia-front/terrosenser/api/apiterrosenser/config.php:
 * mismo cargador de .env a mano, mismas convenciones de CORS y logging.
 * Se agrega la conexión a base de datos, que TerroSenser no tiene.
 */

define('OB_ENV_FILE', __DIR__ . '/.env');

/**
 * @return array<string, mixed>
 */
function obLoadEnv(?string $path = null): array
{
    $path = $path ?? OB_ENV_FILE;
    $meta = ['path' => $path, 'loaded_from_file' => false];
    if (!file_exists($path)) {
        return ['_meta' => $meta];
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), '"\'');
        if ($key !== '') {
            $env[$key] = $value;
        }
    }
    $meta['loaded_from_file'] = true;
    $env['_meta'] = $meta;
    return $env;
}

/**
 * @param array<string, mixed> $env
 */
function obEnvGet(array $env, string $key, ?string $default = null): ?string
{
    if (isset($env[$key]) && $env[$key] !== '') {
        return (string) $env[$key];
    }
    return $default;
}

/**
 * @param array<string, mixed> $env
 */
function obEnvBool(array $env, string $key, bool $default = false): bool
{
    if (!isset($env[$key]) || $env[$key] === '') {
        return $default;
    }
    $v = strtolower((string) $env[$key]);
    return $v === 'true' || $v === '1' || $v === 'yes';
}

try {
    $env = obLoadEnv();
    if (empty($env['_meta']['loaded_from_file'])) {
        throw new RuntimeException(
            'No se encontró .env en ' . OB_ENV_FILE . '. Copie .env.example → .env'
        );
    }

    define('API_ENV', obEnvGet($env, 'API_ENV', 'development'));
    define('API_DEBUG', obEnvBool($env, 'API_DEBUG', false));
    define('API_TIMEZONE', obEnvGet($env, 'API_TIMEZONE', 'America/El_Salvador'));
    define('CORS_ALLOWED_ORIGINS', obEnvGet($env, 'CORS_ALLOWED_ORIGINS', 'http://localhost:8901'));

    define('API_SECRET', obEnvGet($env, 'API_SECRET', ''));
    // off | monitor | strict. Regla de la casa: en producción siempre strict.
    // X-App-Secret va en TODAS las peticiones (CLAUDE.md, trampa 2), no es
    // opcional aunque el endpoint también pida token de dispositivo o sesión.
    define('API_SECRET_ENFORCE', obEnvGet($env, 'API_SECRET_ENFORCE', 'strict'));

    define('DB_HOST', obEnvGet($env, 'DB_HOST', 'db'));
    define('DB_PORT', obEnvGet($env, 'DB_PORT', '5432'));
    define('DB_NAME', obEnvGet($env, 'DB_NAME', 'observatorio'));
    define('DB_USER', obEnvGet($env, 'DB_USER', 'observatorio'));
    define('DB_PASSWORD', obEnvGet($env, 'DB_PASSWORD', ''));
    // prefer (default de libpq, sirve para el Postgres de docker-compose
    // sin SSL) | require — las bases administradas (DigitalOcean, etc.)
    // exigen require.
    define('DB_SSLMODE', obEnvGet($env, 'DB_SSLMODE', 'prefer'));

    // Cifra los respaldos del panel (lib/Respaldo.php). Sin default: si
    // falta, generar un respaldo debe fallar fuerte, no cifrar con una
    // clave vacía o adivinada. REDNA guarda su propia copia de esta
    // clave fuera del servidor — ver docs/decision-04-respaldos.md.
    define('RESPALDO_CLAVE', obEnvGet($env, 'RESPALDO_CLAVE', ''));

    define('RATE_LIMIT_MAX', (int) obEnvGet($env, 'RATE_LIMIT_MAX', '30'));
    define('RATE_LIMIT_WINDOW', (int) obEnvGet($env, 'RATE_LIMIT_WINDOW', '60'));
    define('RATE_LIMIT_DAILY_MAX', (int) obEnvGet($env, 'RATE_LIMIT_DAILY_MAX', '500'));
    define('RATE_LIMIT_SYNC_MAX', (int) obEnvGet($env, 'RATE_LIMIT_SYNC_MAX', '60'));
    define('RATE_LIMIT_SYNC_WINDOW', (int) obEnvGet($env, 'RATE_LIMIT_SYNC_WINDOW', '3600'));

    date_default_timezone_set(API_TIMEZONE);
} catch (Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
    }
    echo json_encode([
        'success' => false,
        'error' => 'Configuración del servidor incompleta',
        'code' => 'CONFIG_BOOTSTRAP_FAILED',
    ], JSON_UNESCAPED_UNICODE);
    // El detalle (con la ruta del servidor) va al registro, no a quien pidió.
    error_log('[Observatorio API] Configuración incompleta: ' . $e->getMessage());
    exit(1);
}

require_once __DIR__ . '/lib/ApiResponse.php';
require_once __DIR__ . '/lib/AppAuth.php';
require_once __DIR__ . '/lib/PanelAuth.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Respaldo.php';
require_once __DIR__ . '/lib/SeguridadServidor.php';

// No anunciar la versión de PHP: solo le sirve a quien busca fallas conocidas.
header_remove('X-Powered-By');

function obCorsOriginAllowed(string $origin, array $allowedOrigins): bool
{
    if (CORS_ALLOWED_ORIGINS === '*') {
        return true;
    }
    if ($origin === '') {
        return false;
    }
    if (in_array($origin, $allowedOrigins, true)) {
        return true;
    }
    foreach ($allowedOrigins as $allowed) {
        if ($allowed === 'http://localhost' && strpos($origin, 'http://localhost') === 0) {
            return true;
        }
        if ($allowed === 'http://127.0.0.1' && strpos($origin, 'http://127.0.0.1') === 0) {
            return true;
        }
    }
    return false;
}

function obSetCorsHeaders(): void
{
    $allowedOrigins = array_map('trim', explode(',', CORS_ALLOWED_ORIGINS));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (obCorsOriginAllowed($origin, $allowedOrigins)) {
        if (CORS_ALLOWED_ORIGINS === '*') {
            header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
        } elseif ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    }

    // DELETE se agregó para admin/evidencia.php (borrar una fotografía
    // de evidencia, 2026-10-20) — primer endpoint del proyecto que lo
    // necesita; todo lo demás hasta ahora usaba PATCH.
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-App-Secret, X-Admin-Key, Authorization, If-None-Match');
    header('Access-Control-Allow-Credentials: true');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

function obApiLog(string $message, $data = null): void
{
    if (API_DEBUG) {
        error_log('[Observatorio API] ' . $message . ($data ? ' | ' . json_encode($data) : ''));
    }
}
