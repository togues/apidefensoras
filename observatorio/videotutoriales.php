<?php

/**
 * GET /api/v1/videotutoriales — api_v1.md, sección 3.8.
 * Guías para la pestaña Ayuda. Hoy `videotutorial` está vacía —los
 * videos se graban con las comunidades (cascaron/docs/07-pendientes.md,
 * punto 6)—, así que esto devuelve lista vacía hasta que REDNA cargue el
 * primero. No bloquea nada: el front ya maneja la lista vacía.
 */

require_once __DIR__ . '/config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$videos = obDb()->query(
    'SELECT id, titulo, descripcion, url, orden
     FROM videotutorial
     WHERE activo = TRUE
     ORDER BY orden'
)->fetchAll();

obJsonResponse(true, ['videotutoriales' => $videos], null, 200);
