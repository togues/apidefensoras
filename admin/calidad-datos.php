<?php

/**
 * GET /api/v1/admin/calidad-datos — el tablero de anomalías de Salud de
 * datos: once revisiones de inconsistencias, uso sospechoso y casos sin
 * atender. Pedido de Marco, 21 de octubre de 2026.
 *
 * Cada revisión devuelve cuántos casos encontró y hasta 50 de ellos
 * (folio, tipo, estado, fecha y un detalle) para abrirlos en Casos.
 * Nada de esto cambia datos: el sistema señala y una persona decide.
 *
 * "Uso sospechoso" (varios reportes del mismo teléfono, recuperaciones
 * altas) es solo para administrador: decir "estos seis casos los mandó
 * el mismo teléfono" ya acerca a quién reportó. Nunca se devuelve el
 * dispositivo, solo los casos o el número.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

const OB_CALIDAD_LIMITE = 50;
const OB_CALIDAD_RELATO_MIN = 20;
const OB_CALIDAD_DUPLICADO_DIAS = 2;
const OB_CALIDAD_DUPLICADO_PARECIDO = 0.5;
const OB_CALIDAD_RAFAGA_REPORTES = 5;
const OB_CALIDAD_RAFAGA_HORAS = 24;
const OB_CALIDAD_RECUPERACIONES = 3;
const OB_CALIDAD_RECIBIDO_DIAS = 7;
const OB_CALIDAD_INVESTIGACION_DIAS = 15;

$pdo = obDb();
$esAdministrador = ($usuarioPanel['rol'] ?? '') === 'administrador';

$columnasCaso = "r.id, r.folio, tv.nombre AS tipo, r.estado, r.fecha_registro";

/** Ejecuta la consulta de una revisión y arma {total, casos}. */
function obRevision(PDO $pdo, string $desde, string $columnas, array $parametros = [], string $detalle = "NULL"): array
{
    $total = $pdo->prepare("SELECT COUNT(*) $desde");
    $total->execute($parametros);
    $casos = $pdo->prepare("SELECT $columnas, $detalle AS detalle $desde ORDER BY r.fecha_registro DESC LIMIT " . OB_CALIDAD_LIMITE);
    $casos->execute($parametros);
    return ['total' => (int) $total->fetchColumn(), 'casos' => $casos->fetchAll()];
}

/** Palabras de un relato, sin tildes ni signos, para comparar relatos. */
function obPalabras(?string $texto): array
{
    $texto = mb_strtolower((string) $texto, 'UTF-8');
    $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $palabras = preg_split('/[^a-z0-9]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($palabras, static fn ($p) => strlen($p) > 2)));
}

$desdeBase = "FROM reporte r JOIN tipo_vulneracion tv ON tv.id = r.tipo_vulneracion_id";
$tarjetas = [];

// --- Datos inconsistentes ----------------------------------------------
$tarjetas[] = ['clave' => 'sin_departamento', 'grupo' => 'datos'] + obRevision($pdo,
    "$desdeBase WHERE r.departamento_id IS NULL", $columnasCaso);

$tarjetas[] = ['clave' => 'fecha_imposible', 'grupo' => 'datos'] + obRevision($pdo,
    "$desdeBase WHERE r.fecha_incidente IS NOT NULL
        AND (r.fecha_incidente::date > (NOW() AT TIME ZONE 'America/El_Salvador')::date
             OR r.fecha_incidente::date > (r.fecha_registro AT TIME ZONE 'America/El_Salvador')::date)",
    $columnasCaso, [], "to_char(r.fecha_incidente, 'DD/MM/YYYY')");

// Posibles duplicados: mismo tipo y departamento, fechas a menos de N
// días; el parecido del relato se mide aquí (palabras en común), sin
// extensiones de Postgres que el hosting quizá no tenga.
$candidatos = $pdo->prepare(
    "SELECT a.id AS id_a, a.descripcion AS rel_a, b.id AS id_b, b.folio AS folio_b, b.descripcion AS rel_b
     FROM reporte a
         JOIN reporte b ON b.id > a.id
             AND b.tipo_vulneracion_id = a.tipo_vulneracion_id
             AND b.departamento_id = a.departamento_id
             AND ABS(COALESCE(b.fecha_incidente::date, b.fecha_registro::date) - COALESCE(a.fecha_incidente::date, a.fecha_registro::date)) <= :dias
     WHERE a.estado <> 'descartado' AND b.estado <> 'descartado'"
);
$candidatos->execute(['dias' => OB_CALIDAD_DUPLICADO_DIAS]);
$duplicadoDe = [];
foreach ($candidatos->fetchAll() as $par) {
    $pa = obPalabras($par['rel_a']);
    $pb = obPalabras($par['rel_b']);
    $union = count(array_unique(array_merge($pa, $pb)));
    $parecido = $union === 0 ? 1.0 : count(array_intersect($pa, $pb)) / $union;
    if ($parecido >= OB_CALIDAD_DUPLICADO_PARECIDO) {
        $duplicadoDe[(int) $par['id_a']][] = $par['folio_b'];
    }
}
$casosDuplicados = [];
if ($duplicadoDe !== []) {
    $ids = implode(',', array_map('intval', array_keys($duplicadoDe)));
    foreach ($pdo->query("SELECT $columnasCaso $desdeBase WHERE r.id IN ($ids) ORDER BY r.fecha_registro DESC")->fetchAll() as $caso) {
        $caso['detalle'] = 'Parecido a ' . implode(', ', array_unique($duplicadoDe[(int) $caso['id']]));
        $casosDuplicados[] = $caso;
    }
}
$tarjetas[] = ['clave' => 'posible_duplicado', 'grupo' => 'datos', 'total' => count($casosDuplicados), 'casos' => array_slice($casosDuplicados, 0, OB_CALIDAD_LIMITE)];

$tarjetas[] = ['clave' => 'relato_corto', 'grupo' => 'datos'] + obRevision($pdo,
    "$desdeBase WHERE COALESCE(LENGTH(TRIM(r.descripcion)), 0) < :minimo",
    $columnasCaso, ['minimo' => OB_CALIDAD_RELATO_MIN]);

// Recuadro amplio de El Salvador: un punto afuera es un error de toque
// o de GPS, no un hecho en otro país.
$tarjetas[] = ['clave' => 'punto_fuera', 'grupo' => 'datos'] + obRevision($pdo,
    "$desdeBase WHERE r.geom IS NOT NULL
        AND NOT (ST_X(r.geom) BETWEEN -90.2 AND -87.6 AND ST_Y(r.geom) BETWEEN 13.1 AND 14.5)",
    $columnasCaso);

// --- Uso sospechoso (solo administrador) -----------------------------
if ($esAdministrador) {
    $rafaga = obRevision($pdo,
        "$desdeBase WHERE (
            SELECT COUNT(*) FROM reporte o
            WHERE o.dispositivo_id = r.dispositivo_id
              AND o.fecha_registro BETWEEN r.fecha_registro - make_interval(hours => :horas_a) AND r.fecha_registro + make_interval(hours => :horas_b)
        ) > :maximo",
        $columnasCaso, ['horas_a' => OB_CALIDAD_RAFAGA_HORAS, 'horas_b' => OB_CALIDAD_RAFAGA_HORAS, 'maximo' => OB_CALIDAD_RAFAGA_REPORTES]);
    $tarjetas[] = ['clave' => 'muchos_reportes', 'grupo' => 'uso'] + $rafaga;

    $recuperaciones = $pdo->prepare('SELECT COUNT(*) FROM dispositivo WHERE veces_recuperado >= :umbral');
    $recuperaciones->execute(['umbral' => OB_CALIDAD_RECUPERACIONES]);
    $tarjetas[] = ['clave' => 'recuperaciones_altas', 'grupo' => 'uso', 'total' => (int) $recuperaciones->fetchColumn(), 'casos' => null];
}

$tarjetas[] = ['clave' => 'filtro_contenido', 'grupo' => 'uso'] + obRevision($pdo,
    "$desdeBase WHERE EXISTS (SELECT 1 FROM log_anomalia la WHERE la.reporte_id = r.id AND la.tipo_anomalia = 'filtro_contenido' AND la.revisado = FALSE)",
    $columnasCaso);

// --- Casos sin atender -------------------------------------------------
$tarjetas[] = ['clave' => 'recibido_viejo', 'grupo' => 'atencion'] + obRevision($pdo,
    "$desdeBase WHERE r.estado = 'recibido' AND r.fecha_registro < NOW() - make_interval(days => :dias)",
    $columnasCaso, ['dias' => OB_CALIDAD_RECIBIDO_DIAS],
    "(CURRENT_DATE - r.fecha_registro::date) || ' días'");

$ultimaNota = "COALESCE((SELECT MAX(s.fecha) FROM seguimiento_caso s WHERE s.reporte_id = r.id AND s.automatica = FALSE), r.fecha_registro)";
$tarjetas[] = ['clave' => 'investigacion_sin_notas', 'grupo' => 'atencion'] + obRevision($pdo,
    "$desdeBase WHERE r.estado = 'en_investigacion' AND $ultimaNota < NOW() - make_interval(days => :dias)",
    $columnasCaso, ['dias' => OB_CALIDAD_INVESTIGACION_DIAS],
    "'Última nota hace ' || (CURRENT_DATE - ($ultimaNota)::date) || ' días'");

$tarjetas[] = ['clave' => 'sin_organizacion', 'grupo' => 'atencion'] + obRevision($pdo,
    "$desdeBase WHERE r.organizacion_seguimiento_id IS NULL AND r.estado NOT IN ('cerrado', 'descartado')",
    $columnasCaso);

foreach ($tarjetas as &$t) {
    if ($t['casos'] !== null) {
        $t['casos'] = array_map(static function (array $c): array {
            $c['id'] = (int) $c['id'];
            return $c;
        }, $t['casos']);
    }
}
unset($t);

obJsonResponse(true, [
    'umbrales' => [
        'relato_min' => OB_CALIDAD_RELATO_MIN,
        'duplicado_dias' => OB_CALIDAD_DUPLICADO_DIAS,
        'rafaga_reportes' => OB_CALIDAD_RAFAGA_REPORTES,
        'rafaga_horas' => OB_CALIDAD_RAFAGA_HORAS,
        'recuperaciones' => OB_CALIDAD_RECUPERACIONES,
        'recibido_dias' => OB_CALIDAD_RECIBIDO_DIAS,
        'investigacion_dias' => OB_CALIDAD_INVESTIGACION_DIAS,
    ],
    'tarjetas' => $tarjetas,
], null, 200);
