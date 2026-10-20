<?php

/**
 * Rate limiting basado en archivos — sin APCu/Redis/SQLite.
 * Calcado de terrosenser/api/apiterrosenser/lib/RateLimiter.php, con la
 * clave cambiada: ahí es IP + X-App-Secret, acá es IP + una "categoria"
 * (el nombre del endpoint), porque api_v1.md pone un límite propio por
 * endpoint sensible (enrolar, recuperar, sync, login) y no uno solo
 * global. Un archivo por (IP, categoria), con flock() para concurrencia.
 */
final class ObRateLimiter
{
    private static function dir(): string
    {
        $dir = __DIR__ . '/../var/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $htaccess = __DIR__ . '/../var/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
        return $dir;
    }

    private static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '') {
            return $ip;
        }
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $parts = explode(',', $fwd);
            return trim($parts[0]);
        }
        return 'unknown';
    }

    /**
     * @return array{allowed: bool, reason: ?string, retryAfter: int}
     */
    public static function check(string $categoria, int $max, int $windowSeconds): array
    {
        return self::checkClaveCruda(self::clientIp() . '|' . $categoria, $max, $windowSeconds);
    }

    /**
     * @return array{allowed: bool, reason: ?string, retryAfter: int}
     */
    public static function checkClaveCruda(string $clave, int $max, int $windowSeconds): array
    {
        $file = self::dir() . '/' . md5($clave) . '.json';

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Si no se puede abrir el archivo (permisos), no bloqueamos al
            // usuario legítimo por un problema de infraestructura.
            obApiLog('RateLimiter: no se pudo abrir ' . $file);
            return ['allowed' => true, 'reason' => null, 'retryAfter' => 0];
        }

        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $state = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = [];
        }

        $now = time();
        $windowStart = isset($state['window_start']) ? (int) $state['window_start'] : $now;
        $windowCount = isset($state['window_count']) ? (int) $state['window_count'] : 0;
        if ($now - $windowStart >= $windowSeconds) {
            $windowStart = $now;
            $windowCount = 0;
        }

        $windowCount++;

        $allowed = $windowCount <= $max;
        $retryAfter = $allowed ? 0 : max(1, $windowSeconds - ($now - $windowStart));

        $state = ['window_start' => $windowStart, 'window_count' => $windowCount];

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        return ['allowed' => $allowed, 'reason' => $allowed ? null : 'window', 'retryAfter' => $retryAfter];
    }
}

/**
 * Igual que check(), pero sin mezclar la IP en la clave: para límites
 * "por dispositivo" (api_v1.md, sección 2: POST /reportes/sync es
 * 60/hora por dispositivo, no por IP — la persona puede cambiar de red
 * a mitad de una cola sin señal estable).
 *
 * @return array{allowed: bool, reason: ?string, retryAfter: int}
 */
function obRateLimitPorClave(string $clave, int $max, int $windowSeconds): array
{
    return ObRateLimiter::checkClaveCruda($clave, $max, $windowSeconds);
}

/**
 * Aplica el límite y responde 429 LIMITE_EXCEDIDO si se excedió — el
 * mismo code que documenta api_v1.md, sección "Códigos de error".
 */
function obEnforceRateLimit(string $categoria, int $max, int $windowSeconds): void
{
    $result = ObRateLimiter::check($categoria, $max, $windowSeconds);
    obResponderSiExcedido($result);
}

function obEnforceRateLimitPorClave(string $clave, int $max, int $windowSeconds): void
{
    $result = obRateLimitPorClave($clave, $max, $windowSeconds);
    obResponderSiExcedido($result);
}

/**
 * @param array{allowed: bool, reason: ?string, retryAfter: int} $result
 */
function obResponderSiExcedido(array $result): void
{
    if ($result['allowed']) {
        return;
    }
    obApiLog('Rate limit excedido', ['retryAfter' => $result['retryAfter']]);
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: ' . $result['retryAfter']);
    echo json_encode([
        'success' => false,
        'error' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.',
        'code' => 'LIMITE_EXCEDIDO',
        'data' => ['reintentar_en' => $result['retryAfter']],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
