<?php

/**
 * GET /api/v1/admin/estadisticas — api_v1.md, sección 4, fila
 * "GET /admin/estadisticas | Indicadores agregados | operador".
 *
 * Primer endpoint del panel. Todo lo que necesita la vista "Resumen" de
 * diseno/tablero_completo.html en una sola llamada: KPIs, la serie
 * mensual por familia, los casos más recientes, las alertas abiertas y
 * los tipos de hecho más frecuentes. Sobre v_reporte_territorio
 * (migración 005) — nunca toca dispositivo, sexo ni estructura.
 *
 * Ventana fija de 6 meses para todo lo que es "del período" (serie,
 * tipos frecuentes, total_periodo): es lo que pide el mockup y no hay
 * todavía un selector de rango en el contrato. Cuando lo haya, este
 * archivo es el que se actualiza — no hace falta migración.
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
// KPIs
// ------------------------------------------------------------------
$kpis = $pdo->query(
    "SELECT
        COUNT(*) FILTER (WHERE estado = 'recibido')                                   AS sin_revisar,
        COUNT(*) FILTER (WHERE estado = 'en_investigacion')                           AS en_investigacion,
        COUNT(*) FILTER (WHERE fecha_registro >= NOW() - INTERVAL '6 months')         AS total_periodo,
        COUNT(*) FILTER (WHERE fecha_registro >= date_trunc('month', NOW()))          AS total_mes,
        COUNT(*) FILTER (WHERE marcado_anomalo = TRUE)                                AS marcados_anomalos
     FROM v_reporte_territorio"
)->fetch();

$dispositivosActivos = (int) $pdo->query(
    "SELECT COUNT(*) FROM dispositivo WHERE activo = TRUE"
)->fetchColumn();

// ------------------------------------------------------------------
// Serie mensual por familia — 6 meses, con los meses sin datos en cero
// (generate_series para no perder un mes solo porque no llegó nada).
// ------------------------------------------------------------------
$serieRaw = $pdo->query(
    "SELECT to_char(gs.mes_actual, 'YYYY-MM') AS mes, fam.codigo AS familia, COALESCE(c.total, 0) AS total
     FROM generate_series(date_trunc('month', NOW() - INTERVAL '5 months'), date_trunc('month', NOW()), INTERVAL '1 month') AS gs(mes_actual)
     CROSS JOIN familia fam
     LEFT JOIN (
         SELECT date_trunc('month', fecha_registro) AS mes, familia, COUNT(*) AS total
         FROM v_reporte_territorio
         WHERE fecha_registro >= date_trunc('month', NOW() - INTERVAL '5 months')
         GROUP BY 1, 2
     ) c ON c.mes = gs.mes_actual AND c.familia = fam.codigo
     WHERE fam.activo = TRUE
     ORDER BY gs.mes_actual, fam.orden"
)->fetchAll();

$meses = [];
$porFamilia = [];
foreach ($serieRaw as $fila) {
    if (!in_array($fila['mes'], $meses, true)) {
        $meses[] = $fila['mes'];
    }
    $porFamilia[$fila['familia']][] = (int) $fila['total'];
}
$series = [];
foreach ($porFamilia as $familia => $datos) {
    $series[] = ['familia' => $familia, 'datos' => $datos];
}

// ------------------------------------------------------------------
// Casos recientes
// ------------------------------------------------------------------
$casosRecientes = $pdo->query(
    "SELECT folio, comunidad, canton, tipo_hecho AS tipo, familia, estado, prioridad, fecha_registro
     FROM v_reporte_territorio
     ORDER BY fecha_registro DESC
     LIMIT 6"
)->fetchAll();

// ------------------------------------------------------------------
// Alertas activas
// ------------------------------------------------------------------
$alertasActivas = $pdo->query(
    "SELECT a.id, ra.nombre AS regla, ct.nombre AS canton, a.conteo_actual, ra.umbral,
            a.fecha_disparo,
            (SELECT COUNT(*) FROM notificacion n WHERE n.alerta_id = a.id AND n.estado_envio = 'enviado') AS notificados
     FROM alerta a
         JOIN regla_alerta ra ON ra.id = a.regla_alerta_id
         LEFT JOIN canton ct  ON ct.id = a.canton_id
     WHERE a.estado = 'abierta'
     ORDER BY a.fecha_disparo DESC"
)->fetchAll();

// ------------------------------------------------------------------
// Tipos de hecho más frecuentes (6 meses)
// ------------------------------------------------------------------
$tiposFrecuentes = $pdo->query(
    "SELECT tipo_hecho AS tipo, familia, COUNT(*) AS total
     FROM v_reporte_territorio
     WHERE fecha_registro >= NOW() - INTERVAL '6 months'
     GROUP BY tipo_hecho, familia
     ORDER BY total DESC
     LIMIT 10"
)->fetchAll();

obJsonResponse(true, [
    'kpis' => [
        'sin_revisar' => (int) $kpis['sin_revisar'],
        'en_investigacion' => (int) $kpis['en_investigacion'],
        'total_periodo' => (int) $kpis['total_periodo'],
        'total_mes' => (int) $kpis['total_mes'],
        'marcados_anomalos' => (int) $kpis['marcados_anomalos'],
        'dispositivos_activos' => $dispositivosActivos,
    ],
    'serie_mensual' => [
        'meses' => $meses,
        'series' => $series,
    ],
    'casos_recientes' => $casosRecientes,
    'alertas_activas' => array_map(static function (array $a): array {
        $a['conteo_actual'] = (int) $a['conteo_actual'];
        $a['umbral'] = (int) $a['umbral'];
        $a['notificados'] = (int) $a['notificados'];
        return $a;
    }, $alertasActivas),
    'tipos_frecuentes' => array_map(static function (array $t): array {
        $t['total'] = (int) $t['total'];
        return $t;
    }, $tiposFrecuentes),
], null, 200);
