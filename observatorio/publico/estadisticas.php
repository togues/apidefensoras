<?php

/**
 * GET /api/v1/publico/estadisticas — api_v1.md, sección 5.
 * "Conteos agregados por familia y departamento, con el mismo umbral
 * de 5 que aplica en el resto del sistema" — el mismo criterio que
 * v_participacion_agregada (migración 001): un grupo de menos de 5
 * deja de protegerse a sí mismo, así que ese grupo no se muestra.
 *
 * A diferencia de GET /publico/muro (que solo muestra reportes
 * PUBLICADOS uno por uno), esto agrega TODO v_reporte_territorio, no
 * solo lo publicado — un conteo con k≥5 no identifica a nadie aunque
 * el caso individual nunca se haya hecho público, y la razón de ser de
 * un observatorio es mostrar la escala real, no solo la fracción que
 * REDNA decidió publicar caso por caso. Interpretación de Marco al
 * programar esto, sin confirmar con ACUA todavía — si lo quieren
 * distinto, es un cambio de un WHERE, no de diseño.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$conteos = $pdo->query(
    "SELECT familia, departamento, COUNT(*) AS total
     FROM v_reporte_territorio
     WHERE departamento IS NOT NULL
     GROUP BY familia, departamento
     HAVING COUNT(*) >= 5
     ORDER BY departamento, familia"
)->fetchAll();

$totalGeneral = (int) $pdo->query('SELECT COUNT(*) FROM reporte')->fetchColumn();

obJsonResponse(true, [
    'total_general' => $totalGeneral,
    'conteos' => array_map(static function (array $c): array {
        $c['total'] = (int) $c['total'];
        return $c;
    }, $conteos),
], null, 200);
