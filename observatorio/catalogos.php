<?php

/**
 * GET /api/v1/catalogos — api_v1.md, sección 3.4.
 *
 * Único endpoint que acepta solo el secreto de aplicación: la app lo
 * consulta antes de estar enrolada. Trae los cinco campos que la
 * migración 003 agregó y que api_v1.md todavía no documentaba con
 * ejemplo: etiqueta_corta, ayuda_usar, ayuda_no_usar (familia y
 * tipo_vulneracion), municipio (JOIN, no columna) y centro
 * (ST_Centroid(comunidad.geom), no columna) — ver
 * contratos/00-inventario-api.md, sección "Lo que le falta a GET /catalogos".
 */

require_once __DIR__ . '/config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$familias = $pdo->query(
    "SELECT codigo, naturaleza, texto_app, etiqueta_corta, ayuda_usar, ayuda_no_usar,
            icono, orden
     FROM familia
     WHERE activo = TRUE
     ORDER BY orden"
)->fetchAll();

$tipos = $pdo->query(
    "SELECT id, codigo, familia, naturaleza, nombre, texto_app, ayuda_usar, ayuda_no_usar,
            icono, orden, alerta_inmediata, permite_entidad
     FROM tipo_vulneracion
     WHERE activo = TRUE
     ORDER BY familia, orden"
)->fetchAll();

// alerta_inmediata/permite_entidad llegan de Postgres como 't'/'f' via PDO
// pgsql en algunos entornos; se normalizan a booleano real para el JSON.
foreach ($tipos as &$tipo) {
    $tipo['alerta_inmediata'] = (bool) $tipo['alerta_inmediata'];
    $tipo['permite_entidad'] = (bool) $tipo['permite_entidad'];
}
unset($tipo);

$estructuras = $pdo->query(
    "SELECT id, codigo, nombre, icono, orden
     FROM estructura_organizativa
     WHERE activo = TRUE
     ORDER BY orden"
)->fetchAll();

$comunidadesRaw = $pdo->query(
    "SELECT co.id, co.nombre,
            ca.nombre AS canton,
            mu.nombre AS municipio,
            de.nombre AS departamento,
            ST_Y(ST_Centroid(co.geom)) AS centro_lat,
            ST_X(ST_Centroid(co.geom)) AS centro_lon
     FROM comunidad co
         LEFT JOIN canton      ca ON ca.id = co.canton_id
         LEFT JOIN distrito    di ON di.id = ca.distrito_id
         LEFT JOIN municipio   mu ON mu.id = di.municipio_id
         LEFT JOIN departamento de ON de.id = mu.departamento_id
     WHERE co.activo = TRUE
     ORDER BY co.nombre"
)->fetchAll();

$comunidades = array_map(static function (array $fila): array {
    $centro = null;
    if ($fila['centro_lat'] !== null && $fila['centro_lon'] !== null) {
        $centro = ['lat' => (float) $fila['centro_lat'], 'lon' => (float) $fila['centro_lon']];
    }
    return [
        'id' => (int) $fila['id'],
        'nombre' => $fila['nombre'],
        'canton' => $fila['canton'],
        'municipio' => $fila['municipio'],
        'departamento' => $fila['departamento'],
        'centro' => $centro,
    ];
}, $comunidadesRaw);

$departamentos = $pdo->query('SELECT id, nombre FROM departamento ORDER BY nombre')->fetchAll();

$data = [
    'familias' => $familias,
    'tipos' => $tipos,
    'estructuras' => $estructuras,
    'comunidades' => $comunidades,
    'departamentos' => $departamentos,
];

// No hay columna de "última modificación" en ninguna tabla del catálogo
// (ninguna migración la agrega). En vez de inventar una fecha que no
// significaría nada, la versión es un hash del contenido: cambia cuando
// el catálogo cambia, y es estable entre llamadas idénticas — que es lo
// único que ETag/If-None-Match necesitan para funcionar de verdad.
$version = substr(hash('sha256', json_encode($data)), 0, 16);
$data = ['version' => $version] + $data;

$etag = '"' . $version . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache');

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
    http_response_code(304);
    exit();
}

obJsonResponse(true, $data, null, 200);
