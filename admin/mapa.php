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
 * REGLA 5 DE CLAUDE.md — dos comportamientos distintos según el rol,
 * ver docs/decision-06-coordenadas-exactas-mapa-admin.md:
 *
 *   - Rol administrador: el punto exacto del reporte (reporte.geom)
 *     cuando la persona lo marcó, con el centro de la comunidad como
 *     respaldo cuando no. `es_exacto` le dice al cliente cuál es
 *     cuál, para no mostrar un respaldo como si fuera un dato de
 *     campo real.
 *   - Cualquier otro rol: SIEMPRE el centro de la comunidad
 *     (ST_Centroid(comunidad.geom)), nunca reporte.geom — el
 *     comportamiento original, sin cambios. En un caserío de veinte
 *     casas, el punto exacto de cada reporte a la vez (no uno solo,
 *     como en el detalle de un caso) señalaría viviendas concretas.
 *
 * Tipo, departamento y fechas van para los filtros y la línea de tiempo
 * del mapa (pedido de Marco, 22 oct 2026). El relato no: el mapa no lo
 * muestra, y quien lo necesita abre el caso.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$esAdministrador = ($usuarioPanel['rol'] ?? '') === 'administrador';

if ($esAdministrador) {
    $puntos = $pdo->query(
        "SELECT r.id, r.folio, r.estado, r.familia, r.comunidad, r.canton,
                r.tipo_hecho, r.tipo_vulneracion_id, r.departamento, r.departamento_id, r.prioridad,
                r.fecha_registro, r.fecha_incidente, r.precision_fecha,
                COALESCE(ST_Y(rep.geom), ST_Y(ST_Centroid(co.geom))) AS lat,
                COALESCE(ST_X(rep.geom), ST_X(ST_Centroid(co.geom))) AS lon,
                (rep.geom IS NOT NULL) AS es_exacto
         FROM v_reporte_territorio r
             JOIN reporte rep ON rep.id = r.id
             LEFT JOIN comunidad co ON co.id = r.comunidad_id
         WHERE rep.geom IS NOT NULL OR co.geom IS NOT NULL
         ORDER BY r.fecha_registro DESC"
    )->fetchAll();

    $sinUbicacion = (int) $pdo->query(
        "SELECT COUNT(*) FROM v_reporte_territorio r
             JOIN reporte rep ON rep.id = r.id
             LEFT JOIN comunidad co ON co.id = r.comunidad_id
         WHERE rep.geom IS NULL AND co.geom IS NULL"
    )->fetchColumn();
} else {
    $puntos = $pdo->query(
        "SELECT r.id, r.folio, r.estado, r.familia, r.comunidad, r.canton,
                r.tipo_hecho, r.tipo_vulneracion_id, r.departamento, r.departamento_id, r.prioridad,
                r.fecha_registro, r.fecha_incidente, r.precision_fecha,
                ST_Y(ST_Centroid(co.geom)) AS lat, ST_X(ST_Centroid(co.geom)) AS lon,
                FALSE AS es_exacto
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
}

obJsonResponse(true, [
    'puntos' => array_map(static function (array $p): array {
        $p['lat'] = (float) $p['lat'];
        $p['lon'] = (float) $p['lon'];
        $p['es_exacto'] = (bool) $p['es_exacto'];
        $p['id'] = (int) $p['id'];
        $p['tipo_vulneracion_id'] = $p['tipo_vulneracion_id'] === null ? null : (int) $p['tipo_vulneracion_id'];
        $p['departamento_id'] = $p['departamento_id'] === null ? null : (int) $p['departamento_id'];
        return $p;
    }, $puntos),
    'sin_ubicacion' => $sinUbicacion,
    'puede_ver_exacto' => $esAdministrador,
], null, 200);
