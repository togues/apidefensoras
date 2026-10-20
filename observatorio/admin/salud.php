<?php

/**
 * GET /api/v1/admin/salud — api_v1.md, sección 4.4. Rol operador
 * (lectura).
 *
 * A diferencia del resto del panel, acá NO se completa lo que el
 * mockup mostraba con números que no se pueden medir todavía:
 * "Disponibilidad del mes" necesita un monitor de actividad que no
 * existe, "Reportes en cola" es un dato que vive en el dispositivo
 * (sincronizador.js), no en este servidor —el servidor solo ve lo que
 * ya llegó—. "Último respaldo" sí se completa: `POST /admin/respaldos`
 * (admin/respaldos.php, lib/Respaldo.php) existe desde
 * docs/decision-04-respaldos.md.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$ultimaSync = $pdo->query('SELECT MAX(fecha_sync) FROM reporte')->fetchColumn();

$anomaliasSinRevisar = (int) $pdo->query(
    'SELECT COUNT(*) FROM log_anomalia WHERE revisado = FALSE'
)->fetchColumn();

$reportesSinTerritorio = (int) $pdo->query(
    'SELECT COUNT(*) FROM reporte WHERE canton_id IS NULL'
)->fetchColumn();

// "Alta" es un número redondo documentado acá mismo, no en el
// contrato: 3 o más recuperaciones de un mismo dispositivo es lo que
// en la práctica distingue "perdió el teléfono, es normal" de "algo
// raro está pasando con esta cuenta".
$umbralRecuperacionesAltas = 3;
$stmtRecuperaciones = $pdo->prepare('SELECT COUNT(*) FROM dispositivo WHERE veces_recuperado >= :umbral');
$stmtRecuperaciones->execute(['umbral' => $umbralRecuperacionesAltas]);
$dispositivosConRecuperacionesAltas = (int) $stmtRecuperaciones->fetchColumn();

$anomaliasSinRevisarDetalle = $pdo->query(
    "SELECT la.id, la.tipo_anomalia, la.detectado_en, r.folio, r.id AS reporte_id
     FROM log_anomalia la
         JOIN reporte r ON r.id = la.reporte_id
     WHERE la.revisado = FALSE
     ORDER BY la.detectado_en DESC
     LIMIT 20"
)->fetchAll();

// Igual que catalogos.php: sin columna de "última modificación" en el
// catálogo, la versión es un hash de su contenido.
$catalogoTexto = $pdo->query(
    "SELECT string_agg(codigo || nombre, '|' ORDER BY codigo) FROM tipo_vulneracion"
)->fetchColumn();
$versionCatalogo = substr(hash('sha256', (string) $catalogoTexto), 0, 16);

$ultimoRespaldo = $pdo->query('SELECT MAX(generado_en) FROM respaldo')->fetchColumn();

obJsonResponse(true, [
    'servicio' => [
        'estado' => 'ok', // si esto responde, la base conecta — obDb() ya habría cortado con 503 si no
        'ultima_sync' => $ultimaSync,
    ],
    'datos' => [
        'anomalias_sin_revisar' => $anomaliasSinRevisar,
        'reportes_sin_territorio' => $reportesSinTerritorio,
        'dispositivos_con_recuperaciones_altas' => $dispositivosConRecuperacionesAltas,
    ],
    'anomalias_sin_revisar_detalle' => $anomaliasSinRevisarDetalle,
    'catalogo' => ['version' => $versionCatalogo],
    'respaldo' => ['ultimo' => $ultimoRespaldo ?: null],
], null, 200);
