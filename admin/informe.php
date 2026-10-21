<?php

/**
 * GET /api/v1/admin/informe — la lista de casos del informe de
 * seguimiento en PDF que REDNA entrega a una organización comunitaria
 * (Junta de Agua, Mesa Territorial...). Pedido de Marco, 2026-10-20;
 * ver docs/decision-07-informe-seguimiento.md.
 *
 * Mismos filtros que Casos y el tablero (lib/FiltrosReporte.php) — el
 * panel arma el informe con lo que está filtrando en Análisis. Los
 * totales y gráficos del PDF salen de admin/analisis.php con esos
 * mismos filtros; esto agrega solo la lista final, caso por caso.
 *
 * Lo que NUNCA sale de acá, porque el PDF viaja fuera del panel:
 * el relato (descripcion), la referencia del lugar, la entidad
 * mencionada, coordenadas y fotografías. "Lo actuado" son solo las
 * entradas de bitácora escritas por personas de REDNA
 * (seguimiento_caso.automatica = FALSE, migración 011) — nunca quién
 * las escribió.
 *
 * Solo administrador. Hasta 500 casos por informe.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/FiltrosReporte.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$tz = $pdo->quote(API_TIMEZONE);
['condiciones' => $condiciones, 'parametros' => $parametros] = obFiltrosReporte($_GET, 'r');
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM v_reporte_territorio r $where");
$total->execute($parametros);
$total = (int) $total->fetchColumn();
if ($total > 500) {
    obJsonResponse(false, ['total' => $total], "El filtro trae $total casos: un informe admite hasta 500. Acote el período o el territorio.", 400, 'DEMASIADOS_CASOS');
}

$casos = $pdo->prepare(
    "SELECT r.id, r.folio, r.tipo_hecho AS tipo, r.familia, r.estado, r.prioridad,
            r.comunidad, r.departamento, r.organizacion_seguimiento,
            to_char(r.fecha_registro AT TIME ZONE $tz, 'YYYY-MM-DD') AS fecha
     FROM v_reporte_territorio r $where
     ORDER BY r.fecha_registro"
);
$casos->execute($parametros);
$casos = $casos->fetchAll();

$actuado = [];
if ($casos) {
    $ids = implode(',', array_map(static fn (array $c) => (int) $c['id'], $casos));
    $filas = $pdo->query(
        "SELECT reporte_id, to_char(fecha AT TIME ZONE $tz, 'YYYY-MM-DD') AS fecha, estado_anterior, estado_nuevo, nota
         FROM seguimiento_caso
         WHERE reporte_id IN ($ids) AND automatica = FALSE
         ORDER BY fecha"
    )->fetchAll();
    foreach ($filas as $f) {
        $cambioEstado = $f['estado_nuevo'] !== null && $f['estado_nuevo'] !== $f['estado_anterior'];
        if (!$cambioEstado && ($f['nota'] === null || trim($f['nota']) === '')) {
            continue;
        }
        $actuado[(int) $f['reporte_id']][] = [
            'fecha' => $f['fecha'],
            'estado_nuevo' => $cambioEstado ? $f['estado_nuevo'] : null,
            'nota' => $f['nota'] !== null && trim($f['nota']) !== '' ? trim($f['nota']) : null,
        ];
    }
}

obJsonResponse(true, [
    'total' => $total,
    'casos' => array_map(static function (array $c) use ($actuado): array {
        $id = (int) $c['id'];
        unset($c['id']); // el informe identifica por folio, nunca por id interno
        $c['actuado'] = $actuado[$id] ?? [];
        return $c;
    }, $casos),
], null, 200);
