<?php

/**
 * GET /api/v1/admin/analisis — el tablero de Análisis del panel, con
 * filtros en cascada al estilo Power BI / Metabase: TODO lo que
 * devuelve este endpoint respeta los mismos filtros, así un filtro
 * arriba refresca cada indicador y cada gráfico de abajo. Pedido de
 * Marco, 2026-10-20 (Resumen queda como vista operativa, sin filtros;
 * ver admin/estadisticas.php).
 *
 * Filtros: los mismos de Casos (lib/FiltrosReporte.php) — desde,
 * hasta, departamento_id, comunidad_id, familia, tipo_vulneracion_id,
 * estado. Sin desde/hasta: los últimos 180 días. El período anterior
 * (misma duración, justo antes) solo alimenta la comparación del total.
 *
 * Una sola llamada para todo el tablero, en vez de una por gráfico.
 * Nunca toca dispositivo, sexo ni estructura organizativa: la
 * participación tiene su propio endpoint (admin/participacion.php,
 * umbral de 5) y a propósito NO responde a estos filtros — achicar el
 * universo con filtros de casos achicaría también los grupos.
 *
 * ?opciones=1 agrega las listas para llenar los filtros; ?solo_opciones=1
 * devuelve solo esas listas (ver $opcionesFiltros más abajo).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/FiltrosReporte.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$tz = new DateTimeZone(API_TIMEZONE);

/**
 * Listas para llenar la barra de filtros: departamentos, comunidades
 * con su departamento (para la cascada) y tipos de hecho con su
 * familia. ?solo_opciones=1 devuelve únicamente esto, sin calcular el
 * tablero — la primera visita de la sesión lo pide antes de pintar la
 * barra.
 */
$opcionesFiltros = static function (PDO $pdo): array {
    $enteros = static fn (array $filas, array $campos) => array_map(static function (array $f) use ($campos): array {
        foreach ($campos as $c) {
            $f[$c] = $f[$c] === null ? null : (int) $f[$c];
        }
        return $f;
    }, $filas);
    return [
        'departamentos' => $enteros($pdo->query('SELECT id, nombre FROM departamento ORDER BY nombre')->fetchAll(), ['id']),
        'comunidades' => $enteros($pdo->query(
            "SELECT co.id, co.nombre, mu.departamento_id
             FROM comunidad co
                 LEFT JOIN canton ct ON ct.id = co.canton_id
                 LEFT JOIN distrito di ON di.id = ct.distrito_id
                 LEFT JOIN municipio mu ON mu.id = di.municipio_id
             WHERE co.activo = TRUE ORDER BY co.nombre"
        )->fetchAll(), ['id', 'departamento_id']),
        'tipos' => $enteros($pdo->query(
            'SELECT id, nombre, familia FROM tipo_vulneracion WHERE activo = TRUE ORDER BY familia, orden'
        )->fetchAll(), ['id']),
        'organizaciones' => $enteros($pdo->query(
            'SELECT id, nombre FROM estructura_organizativa WHERE activo = TRUE ORDER BY orden, nombre'
        )->fetchAll(), ['id']),
    ];
};

if (!empty($_GET['solo_opciones'])) {
    obJsonResponse(true, ['opciones' => $opcionesFiltros($pdo)], null, 200);
}


// ------------------------------------------------------------------
// Período: el pedido, o los últimos 180 días.
// ------------------------------------------------------------------
$hoy = new DateTimeImmutable('today', $tz);
$leerDia = static function (?string $v) use ($tz): ?DateTimeImmutable {
    if ($v === null || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], $tz);
    return ($d && $d->format('Y-m-d') === $m[1]) ? $d : null;
};
$hasta = $leerDia($_GET['hasta'] ?? null) ?? $hoy;
$desde = $leerDia($_GET['desde'] ?? null) ?? $hasta->modify('-179 days');
if ($desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}
$dias = (int) $desde->diff($hasta)->days + 1;
$anteriorHasta = $desde->modify('-1 day');
$anteriorDesde = $anteriorHasta->modify('-' . ($dias - 1) . ' days');

// Granularidad de la serie según el largo del período — nunca cientos
// de barras ni una sola.
$unidad = $dias <= 62 ? 'day' : ($dias <= 210 ? 'week' : 'month');

$entrada = $_GET;
$entrada['desde'] = $desde->format('Y-m-d');
$entrada['hasta'] = $hasta->format('Y-m-d');
['condiciones' => $condiciones, 'parametros' => $parametros] = obFiltrosReporte($entrada, 'r');
$where = 'WHERE ' . implode(' AND ', $condiciones);

/** Ejecuta una consulta con los filtros del período y devuelve todas las filas. */
$consultar = static function (string $sql, array $extra = []) use ($pdo, $parametros): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros + $extra);
    return $stmt->fetchAll();
};

// ------------------------------------------------------------------
// Indicadores
// ------------------------------------------------------------------
$kpis = $consultar(
    "SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE r.estado = 'recibido')          AS sin_revisar,
            COUNT(*) FILTER (WHERE r.estado = 'en_investigacion')  AS en_investigacion,
            COUNT(*) FILTER (WHERE r.estado = 'cerrado')           AS cerrados,
            COUNT(*) FILTER (WHERE r.estado = 'descartado')        AS descartados,
            COUNT(*) FILTER (WHERE r.prioridad = 'alta')           AS alta_prioridad,
            COUNT(*) FILTER (WHERE r.marcado_anomalo)              AS anomalos,
            COUNT(DISTINCT r.comunidad_id)                         AS comunidades_afectadas
     FROM v_reporte_territorio r $where"
)[0];

$entradaAnterior = $entrada;
$entradaAnterior['desde'] = $anteriorDesde->format('Y-m-d');
$entradaAnterior['hasta'] = $anteriorHasta->format('Y-m-d');
$filtrosAnteriores = obFiltrosReporte($entradaAnterior, 'r');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM v_reporte_territorio r WHERE ' . implode(' AND ', $filtrosAnteriores['condiciones']));
$stmt->execute($filtrosAnteriores['parametros']);
$totalAnterior = (int) $stmt->fetchColumn();

// Tiempos de atención, sacados de la bitácora: días desde que llegó el
// reporte hasta la primera acción del equipo, y hasta que se cerró o
// descartó. Mediana, no promedio: un caso olvidado meses no debe
// distorsionar el número de todos los demás.
$tiempos = $consultar(
    "SELECT
        percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (s.primera - r.fecha_registro)) / 86400)
            FILTER (WHERE s.primera IS NOT NULL) AS dias_primera_respuesta,
        percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (s.cierre - r.fecha_registro)) / 86400)
            FILTER (WHERE s.cierre IS NOT NULL) AS dias_cierre
     FROM v_reporte_territorio r
         LEFT JOIN LATERAL (
             SELECT MIN(sc.fecha) AS primera,
                    MIN(sc.fecha) FILTER (WHERE sc.estado_nuevo IN ('cerrado', 'descartado')) AS cierre
             FROM seguimiento_caso sc WHERE sc.reporte_id = r.id
         ) s ON TRUE
     $where"
)[0];

// ------------------------------------------------------------------
// Serie en el tiempo, por familia, con los tramos vacíos en cero.
// ------------------------------------------------------------------
$tzSql = $pdo->quote(API_TIMEZONE);
$serieRaw = $consultar(
    "SELECT to_char(gs.tramo, 'YYYY-MM-DD') AS tramo, fam.codigo AS familia, COUNT(b.familia) AS total
     FROM generate_series(date_trunc('$unidad', CAST(:serie_desde AS timestamp)),
                          date_trunc('$unidad', CAST(:serie_hasta AS timestamp)),
                          INTERVAL '1 $unidad') AS gs(tramo)
     CROSS JOIN familia fam
     LEFT JOIN (
         SELECT r.familia, date_trunc('$unidad', r.fecha_registro AT TIME ZONE $tzSql) AS tramo
         FROM v_reporte_territorio r $where
     ) b ON b.tramo = gs.tramo AND b.familia = fam.codigo
     WHERE fam.activo = TRUE
     GROUP BY gs.tramo, fam.codigo, fam.orden
     ORDER BY gs.tramo, fam.orden",
    ['serie_desde' => $desde->format('Y-m-d'), 'serie_hasta' => $hasta->format('Y-m-d')]
);
$tramos = [];
$porFamilia = [];
foreach ($serieRaw as $fila) {
    $tramos[$fila['tramo']] = true;
    $porFamilia[$fila['familia']][] = (int) $fila['total'];
}
$series = [];
foreach ($porFamilia as $familia => $datos) {
    $series[] = ['familia' => $familia, 'datos' => $datos];
}

// ------------------------------------------------------------------
// Desgloses — cada uno con el id que el panel usa para el filtro
// cruzado (clic en una barra = agregar ese filtro).
// ------------------------------------------------------------------
$familias = $consultar(
    "SELECT r.familia, COUNT(*) AS total FROM v_reporte_territorio r $where GROUP BY r.familia ORDER BY total DESC"
);
$tipos = $consultar(
    "SELECT r.tipo_vulneracion_id, r.tipo_hecho AS tipo, r.familia, COUNT(*) AS total
     FROM v_reporte_territorio r $where
     GROUP BY r.tipo_vulneracion_id, r.tipo_hecho, r.familia ORDER BY total DESC, tipo"
);
$departamentos = $consultar(
    "SELECT r.departamento_id, r.departamento, COUNT(*) AS total
     FROM v_reporte_territorio r $where AND r.departamento_id IS NOT NULL
     GROUP BY r.departamento_id, r.departamento ORDER BY total DESC, r.departamento"
);
$comunidades = $consultar(
    "SELECT r.comunidad_id, r.comunidad, r.departamento, COUNT(*) AS total
     FROM v_reporte_territorio r $where AND r.comunidad_id IS NOT NULL
     GROUP BY r.comunidad_id, r.comunidad, r.departamento ORDER BY total DESC, r.comunidad LIMIT 15"
);
// A qué organización le toca el seguimiento (migración 011) — incluye
// "Sin asignar", lo que REDNA todavía tiene que repartir.
$organizaciones = $consultar(
    "SELECT COALESCE(r.organizacion_seguimiento_id::text, 'sin_asignar') AS organizacion_id,
            COALESCE(r.organizacion_seguimiento, 'Sin asignar') AS organizacion, COUNT(*) AS total
     FROM v_reporte_territorio r $where
     GROUP BY 1, 2
     ORDER BY (COALESCE(r.organizacion_seguimiento_id::text, 'sin_asignar') = 'sin_asignar'), total DESC"
);
$estados = $consultar(
    "SELECT r.estado, COUNT(*) AS total FROM v_reporte_territorio r $where GROUP BY r.estado ORDER BY total DESC"
);
// Empresa, lugar o institución nombrada en el reporte — nunca personas
// (fn_validar_entidad lo impide en la familia persona). Se agrupa sin
// distinguir mayúsculas ni espacios de más.
$entidades = $consultar(
    "SELECT MIN(btrim(r.entidad_mencionada)) AS entidad, COUNT(*) AS total
     FROM v_reporte_territorio r $where AND NULLIF(btrim(r.entidad_mencionada), '') IS NOT NULL
     GROUP BY lower(btrim(r.entidad_mencionada)) ORDER BY total DESC, entidad LIMIT 10"
);
// Mismo tipo de hecho, en la misma comunidad, más de una vez en el
// período: lo que se repite, no lo aislado. Base también del informe
// PDF para las juntas de agua.
$reincidencias = $consultar(
    "SELECT r.comunidad_id, r.comunidad, r.tipo_vulneracion_id, r.tipo_hecho AS tipo, r.familia,
            COUNT(*) AS total,
            -- Día de El Salvador, no la marca UTC: un reporte de las
            -- 10 de la noche del 20 es del 20, no del 21.
            to_char(MIN(r.fecha_registro) AT TIME ZONE $tzSql, 'YYYY-MM-DD') AS primera,
            to_char(MAX(r.fecha_registro) AT TIME ZONE $tzSql, 'YYYY-MM-DD') AS ultima
     FROM v_reporte_territorio r $where AND r.comunidad_id IS NOT NULL
     GROUP BY r.comunidad_id, r.comunidad, r.tipo_vulneracion_id, r.tipo_hecho, r.familia
     HAVING COUNT(*) >= 2
     ORDER BY total DESC, MAX(r.fecha_registro) DESC LIMIT 10"
);

$enteros = static function (array $filas, array $campos): array {
    return array_map(static function (array $f) use ($campos): array {
        foreach ($campos as $c) {
            $f[$c] = $f[$c] === null ? null : (int) $f[$c];
        }
        return $f;
    }, $filas);
};
$redondear = static fn ($v) => $v === null ? null : round((float) $v, 1);

$respuesta = [
    'periodo' => [
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
        'dias' => $dias,
        'granularidad' => ['day' => 'dia', 'week' => 'semana', 'month' => 'mes'][$unidad],
        'anterior' => ['desde' => $anteriorDesde->format('Y-m-d'), 'hasta' => $anteriorHasta->format('Y-m-d')],
    ],
    'kpis' => array_map('intval', $kpis) + [
        'total_anterior' => $totalAnterior,
        'dias_primera_respuesta' => $redondear($tiempos['dias_primera_respuesta']),
        'dias_cierre' => $redondear($tiempos['dias_cierre']),
    ],
    'serie' => ['tramos' => array_keys($tramos), 'series' => $series],
    'familias' => $enteros($familias, ['total']),
    'tipos' => $enteros($tipos, ['tipo_vulneracion_id', 'total']),
    'departamentos' => $enteros($departamentos, ['departamento_id', 'total']),
    'comunidades' => $enteros($comunidades, ['comunidad_id', 'total']),
    'estados' => $enteros($estados, ['total']),
    'organizaciones' => $enteros($organizaciones, ['total']),
    'entidades' => $enteros($entidades, ['total']),
    'reincidencias' => $enteros($reincidencias, ['comunidad_id', 'tipo_vulneracion_id', 'total']),
];

if (!empty($_GET['opciones'])) {
    $respuesta['opciones'] = $opcionesFiltros($pdo);
}

obJsonResponse(true, $respuesta, null, 200);
