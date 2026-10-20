<?php

/**
 * GET /api/v1/salud — sin datos, sin autenticación (00-inventario-api.md,
 * sección 5: "por decidir", molde en terrosenser/api/health.php). Se
 * incluye ya para que docker-compose y quien despliegue tengan cómo
 * comprobar que el servicio y la base responden; no es el
 * `GET /admin/salud` del panel (ese sí requiere sesión y trae anomalías).
 */

require_once __DIR__ . '/config.php';

obSetCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dbOk = true;
try {
    obDb()->query('SELECT 1');
} catch (Throwable $e) {
    $dbOk = false;
}

obJsonResponse(true, [
    'service' => 'observatorio-api',
    'env' => API_ENV,
    'timestamp' => date('c'),
    'db' => $dbOk ? 'ok' : 'error',
], null, 200);
