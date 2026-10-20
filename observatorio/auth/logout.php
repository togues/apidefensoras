<?php

/**
 * POST /api/v1/auth/logout — api_v1.md, sección 4.
 *
 * Idempotente a propósito: si el token ya no existe (sesión vencida,
 * cerrada dos veces desde dos pestañas), igual responde éxito — el
 * resultado que la persona quiere ("que no funcione más") ya es
 * cierto, no hace falta que el token existiera para eso.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$token = obTokenSesionDeCabecera();
if ($token !== '') {
    $pdo = obDb();
    $stmt = $pdo->prepare('DELETE FROM sesion_panel WHERE token_hash = :hash');
    $stmt->execute(['hash' => obHashTokenSesion($token)]);
}

obJsonResponse(true, null, null, 200);
