<?php

/**
 * GET /api/v1/mis-reportes — api_v1.md, sección 3.7.
 * Historial del dispositivo autenticado. Solo sus propios reportes.
 * No devuelve el estado del caso (en_investigacion, cerrado...): eso es
 * seguimiento interno de REDNA, no algo que vea quien reportó.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();

$stmt = obDb()->prepare(
    'SELECT r.folio, tv.nombre AS tipo, tv.familia, r.fecha_registro
     FROM reporte r
         JOIN tipo_vulneracion tv ON tv.id = r.tipo_vulneracion_id
     WHERE r.dispositivo_id = :dispositivo_id
     ORDER BY r.fecha_registro DESC'
);
$stmt->execute(['dispositivo_id' => $dispositivo['id']]);

$reportes = array_map(static function (array $fila): array {
    // Si el servidor lo tiene, ya viajó: la cola local es lo único que
    // sabe distinguir "enviado" de "esperando señal" (docs/00-diccionario
    // -de-datos.md, sección 2 — son dos máquinas de estados distintas).
    return [
        'folio' => $fila['folio'],
        'tipo' => $fila['tipo'],
        'familia' => $fila['familia'],
        'fecha_registro' => (new DateTime($fila['fecha_registro']))->format(DateTime::ATOM),
        'estado_sync' => 'enviado',
    ];
}, $stmt->fetchAll());

obJsonResponse(true, ['reportes' => $reportes], null, 200);
