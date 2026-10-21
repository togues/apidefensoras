<?php

/**
 * GET /api/v1/admin/estadisticas — api_v1.md, sección 4, fila
 * "GET /admin/estadisticas | Indicadores agregados | operador".
 *
 * Alimenta la vista Resumen, que desde el 2026-10-20 es OPERATIVA: qué
 * necesita atención hoy, sin filtros. Lo analítico (series largas,
 * distribuciones, territorio, tipos de hecho) se mudó al tablero de
 * Análisis, con filtros en cascada (admin/analisis.php) — antes las dos
 * vistas repetían la misma serie mensual y los mismos tipos frecuentes.
 * Decisión de Marco.
 *
 * Sobre v_reporte_territorio — nunca toca sexo ni estructura.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$tz = $pdo->quote(API_TIMEZONE);

// "Pendiente" = todavía nadie lo cerró ni lo descartó.
$pendiente = "estado IN ('recibido', 'en_investigacion')";

// Por DÍA de El Salvador, no ventanas móviles de horas: así cada
// indicador cuenta exactamente lo mismo que Casos cuando el panel lo
// abre ya filtrado (Casos filtra por día — lib/FiltrosReporte.php).
$dia = "(fecha_registro AT TIME ZONE $tz)::date";
$hoy = "(NOW() AT TIME ZONE $tz)::date";

$kpis = $pdo->query(
    "SELECT
        COUNT(*) FILTER (WHERE estado = 'recibido')                          AS sin_revisar,
        COUNT(*) FILTER (WHERE estado = 'recibido' AND $dia <= $hoy - 8)      AS sin_revisar_mas_7_dias,
        COUNT(*) FILTER (WHERE estado = 'en_investigacion')                  AS en_investigacion,
        COUNT(*) FILTER (WHERE prioridad = 'alta' AND $pendiente)            AS alta_prioridad_pendiente,
        COUNT(*) FILTER (WHERE marcado_anomalo AND $pendiente)               AS anomalos_pendientes,
        COUNT(*) FILTER (WHERE $dia >= $hoy - 6)                             AS recibidos_7_dias,
        COUNT(*) FILTER (WHERE $dia BETWEEN $hoy - 13 AND $hoy - 7)          AS recibidos_7_dias_anteriores
     FROM v_reporte_territorio"
)->fetch();

$alertasAbiertas = (int) $pdo->query("SELECT COUNT(*) FROM alerta WHERE estado = 'abierta'")->fetchColumn();
$dispositivosActivos = (int) $pdo->query('SELECT COUNT(*) FROM dispositivo WHERE activo = TRUE')->fetchColumn();

// Pulso de los últimos 30 días, por día de El Salvador, con los días
// sin reportes en cero.
$actividad = $pdo->query(
    "SELECT to_char(gs.dia, 'YYYY-MM-DD') AS dia, COUNT(r.id) AS total
     FROM generate_series((NOW() AT TIME ZONE $tz)::date - 29, (NOW() AT TIME ZONE $tz)::date, INTERVAL '1 day') AS gs(dia)
     LEFT JOIN v_reporte_territorio r ON (r.fecha_registro AT TIME ZONE $tz)::date = gs.dia::date
     GROUP BY gs.dia ORDER BY gs.dia"
)->fetchAll();

// La bandeja: lo pendiente, primero lo urgente y después lo que más
// lleva esperando — no lo más nuevo (para eso está "Lo último").
$pendientes = $pdo->query(
    "SELECT id, folio, comunidad, tipo_hecho AS tipo, familia, estado, prioridad, fecha_registro, marcado_anomalo,
            EXTRACT(DAY FROM NOW() - fecha_registro)::int AS dias_esperando
     FROM v_reporte_territorio
     WHERE $pendiente
     ORDER BY CASE prioridad WHEN 'alta' THEN 0 WHEN 'media' THEN 1 ELSE 2 END, fecha_registro ASC
     LIMIT 8"
)->fetchAll();

$recientes = $pdo->query(
    "SELECT id, folio, comunidad, tipo_hecho AS tipo, familia, estado, prioridad, fecha_registro
     FROM v_reporte_territorio ORDER BY fecha_registro DESC LIMIT 6"
)->fetchAll();

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

$casos = static fn (array $filas) => array_map(static function (array $c): array {
    $c['id'] = (int) $c['id'];
    if (array_key_exists('dias_esperando', $c)) {
        $c['dias_esperando'] = (int) $c['dias_esperando'];
    }
    if (array_key_exists('marcado_anomalo', $c)) {
        $c['marcado_anomalo'] = (bool) $c['marcado_anomalo'];
    }
    return $c;
}, $filas);

obJsonResponse(true, [
    'kpis' => array_map('intval', $kpis) + [
        'alertas_abiertas' => $alertasAbiertas,
        'dispositivos_activos' => $dispositivosActivos,
    ],
    'actividad' => array_map(static fn (array $a) => ['dia' => $a['dia'], 'total' => (int) $a['total']], $actividad),
    'pendientes' => $casos($pendientes),
    'recientes' => $casos($recientes),
    'alertas_activas' => array_map(static function (array $a): array {
        $a['conteo_actual'] = (int) $a['conteo_actual'];
        $a['umbral'] = (int) $a['umbral'];
        $a['notificados'] = (int) $a['notificados'];
        return $a;
    }, $alertasActivas),
], null, 200);
