<?php

/**
 * GET /api/v1/admin/analisis — no está en la tabla de contratos/api_v1.md,
 * sección 4 (solo lista `GET /admin/estadisticas`, que ya cubre el
 * resumen ejecutivo). Se agregó al construir la vista Análisis del
 * panel: necesita más detalle del que /admin/estadisticas trae (todos
 * los tipos de hecho, no solo los 10 más frecuentes; totales por
 * territorio; estado acumulado de todos los casos) — mismo patrón que
 * cuando apareció GET /admin/mapa. Documentar en api_v1.md cuando se
 * revise la sección 4 completa.
 *
 * Ventana fija de 6 meses para lo que es "del período" (serie, familia,
 * tipos) — igual que /admin/estadisticas. "Estado de los casos" es
 * acumulado de SIEMPRE a propósito, calca el mockup ("situación actual
 * del total acumulado"): un caso cerrado hace un año sigue siendo parte
 * de la foto de "cómo está todo hoy", no solo lo de los últimos 6 meses.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

// ------------------------------------------------------------------
// Evolución mensual (total, sin separar por familia — la separación ya
// la tiene /admin/estadisticas para Resumen).
// ------------------------------------------------------------------
$serieRaw = $pdo->query(
    "SELECT to_char(gs.mes_actual, 'YYYY-MM') AS mes, COALESCE(c.total, 0) AS total
     FROM generate_series(date_trunc('month', NOW() - INTERVAL '5 months'), date_trunc('month', NOW()), INTERVAL '1 month') AS gs(mes_actual)
     LEFT JOIN (
         SELECT date_trunc('month', fecha_registro) AS mes, COUNT(*) AS total
         FROM v_reporte_territorio
         WHERE fecha_registro >= date_trunc('month', NOW() - INTERVAL '5 months')
         GROUP BY 1
     ) c ON c.mes = gs.mes_actual
     ORDER BY gs.mes_actual"
)->fetchAll();

// ------------------------------------------------------------------
// Distribución por familia (6 meses)
// ------------------------------------------------------------------
$distribucionFamilia = $pdo->query(
    "SELECT fam.codigo AS familia, COALESCE(c.total, 0) AS total
     FROM familia fam
     LEFT JOIN (
         SELECT familia, COUNT(*) AS total
         FROM v_reporte_territorio
         WHERE fecha_registro >= NOW() - INTERVAL '6 months'
         GROUP BY familia
     ) c ON c.familia = fam.codigo
     WHERE fam.activo = TRUE
     ORDER BY fam.orden"
)->fetchAll();

// ------------------------------------------------------------------
// Todos los tipos de hecho (6 meses) — a diferencia de /admin/estadisticas,
// sin LIMIT.
// ------------------------------------------------------------------
$tipos = $pdo->query(
    "SELECT tipo_hecho AS tipo, familia, COUNT(*) AS total
     FROM v_reporte_territorio
     WHERE fecha_registro >= NOW() - INTERVAL '6 months'
     GROUP BY tipo_hecho, familia
     ORDER BY total DESC"
)->fetchAll();

// ------------------------------------------------------------------
// Territorio: reportes (6 meses) y dispositivos activos (siempre,
// "cuánta gente participa hoy" no tiene ventana de tiempo con sentido).
// ------------------------------------------------------------------
$territorios = $pdo->query(
    "SELECT ct.nombre AS canton,
            (SELECT COUNT(*) FROM v_reporte_territorio r WHERE r.canton_id = ct.id AND r.fecha_registro >= NOW() - INTERVAL '6 months') AS reportes,
            (SELECT COUNT(*) FROM dispositivo d WHERE d.comunidad_id IN (SELECT id FROM comunidad WHERE canton_id = ct.id) AND d.activo = TRUE) AS personas_activas
     FROM canton ct
     WHERE EXISTS (SELECT 1 FROM comunidad co WHERE co.canton_id = ct.id)
     ORDER BY reportes DESC"
)->fetchAll();
// Cantones sin ningún reporte ni dispositivo no aportan nada a la
// vista y solo alargan la lista.
$territorios = array_values(array_filter($territorios, static fn (array $t) => (int) $t['reportes'] > 0 || (int) $t['personas_activas'] > 0));

// ------------------------------------------------------------------
// Estado de los casos — acumulado de siempre, no solo 6 meses.
// ------------------------------------------------------------------
$estados = $pdo->query(
    "SELECT estado, COUNT(*) AS total FROM v_reporte_territorio GROUP BY estado ORDER BY total DESC"
)->fetchAll();

obJsonResponse(true, [
    'serie_total' => [
        'meses' => array_map(static fn (array $f) => $f['mes'], $serieRaw),
        'datos' => array_map(static fn (array $f) => (int) $f['total'], $serieRaw),
    ],
    'distribucion_familia' => array_map(static function (array $f): array {
        $f['total'] = (int) $f['total'];
        return $f;
    }, $distribucionFamilia),
    'tipos' => array_map(static function (array $t): array {
        $t['total'] = (int) $t['total'];
        return $t;
    }, $tipos),
    'territorios' => array_map(static function (array $t): array {
        $t['reportes'] = (int) $t['reportes'];
        $t['personas_activas'] = (int) $t['personas_activas'];
        return $t;
    }, $territorios),
    'estados' => array_map(static function (array $e): array {
        $e['total'] = (int) $e['total'];
        return $e;
    }, $estados),
], null, 200);
