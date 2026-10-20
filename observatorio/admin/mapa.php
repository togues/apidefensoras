<?php

/**
 * GET /api/v1/admin/mapa — no está en la tabla de contratos/api_v1.md,
 * sección 4 (solo lista GET /admin/casos, que ni ese tiene endpoint
 * todavía). Se agregó al programar la vista Mapa del panel: hacía
 * falta un punto por reporte y `GET /admin/casos` ni siquiera existe
 * para extenderlo. Mismo patrón que cuando apareció el código
 * REPORTE_NO_ENCONTRADO — una pieza que el contrato no había nombrado
 * y que hizo falta al construir. Documentar en api_v1.md cuando se
 * arme `GET /admin/casos` de verdad.
 *
 * REGLA 5 DE CLAUDE.md — "ubicación nunca exacta en interfaz": el
 * punto que devuelve este endpoint es el CENTRO de la comunidad
 * (ST_Centroid(comunidad.geom)), nunca reporte.geom. Ese campo ni se
 * toca acá. En un caserío de veinte casas, una coordenada exacta señala
 * una vivienda — con el centro de la comunidad, muchos reportes caen
 * en el mismo punto, y de eso se encarga el agrupado (cluster) del
 * lado del cliente, no una jitter fabricada acá.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$puntos = $pdo->query(
    "SELECT r.folio, r.estado, r.familia, r.comunidad, r.canton,
            ST_Y(ST_Centroid(co.geom)) AS lat, ST_X(ST_Centroid(co.geom)) AS lon
     FROM v_reporte_territorio r
         JOIN comunidad co ON co.id = r.comunidad_id
     WHERE co.geom IS NOT NULL
     ORDER BY r.fecha_registro DESC"
)->fetchAll();

$sinUbicacion = (int) $pdo->query(
    "SELECT COUNT(*) FROM v_reporte_territorio r
     LEFT JOIN comunidad co ON co.id = r.comunidad_id
     WHERE co.geom IS NULL"
)->fetchColumn();

obJsonResponse(true, [
    'puntos' => array_map(static function (array $p): array {
        $p['lat'] = (float) $p['lat'];
        $p['lon'] = (float) $p['lon'];
        return $p;
    }, $puntos),
    'sin_ubicacion' => $sinUbicacion,
], null, 200);
