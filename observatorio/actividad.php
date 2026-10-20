<?php

/**
 * GET /api/v1/actividad — api_v1.md, sección 3.9.
 * Número héroe del Inicio: agregado del cantón del dispositivo, nunca
 * personal. mostrar_numero es FALSE con menos de 5 hechos en el periodo
 * —en un cantón pequeño, un número bajo y exacto permite inferir quién
 * reportó (mismo umbral de v_participacion_agregada, regla 2)—.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();

$cantonStmt = obDb()->prepare('SELECT canton_id FROM comunidad WHERE id = :id');
$cantonStmt->execute(['id' => $dispositivo['comunidad_id']]);
$cantonId = $cantonStmt->fetchColumn();

$hechos = 0;
if ($cantonId !== false && $cantonId !== null) {
    $stmt = obDb()->prepare(
        "SELECT COUNT(*) FROM reporte
         WHERE canton_id = :canton_id
           AND fecha_registro >= date_trunc('month', NOW())"
    );
    $stmt->execute(['canton_id' => $cantonId]);
    $hechos = (int) $stmt->fetchColumn();
}

obJsonResponse(true, [
    'periodo' => 'mes',
    'hechos' => $hechos,
    'mostrar_numero' => $hechos >= 5,
], null, 200);
