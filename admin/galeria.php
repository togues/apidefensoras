<?php

/**
 * GET /api/v1/admin/galeria — la evidencia fotográfica de todos los
 * casos, de la más reciente a la más vieja, para recorrerla como
 * galería: de la foto al caso, al revés que en Casos. Pedido del
 * cliente (ACUA) en reunión, transmitido por Marco el 2026-10-20.
 *
 * Solo rol administrador — más restrictivo que ver una foto desde el
 * detalle de un caso (admin/evidencia.php, cualquier rol con sesión):
 * una vista de conjunto de todas las fotos a la vez es distinta de
 * abrir un caso puntual, mismo razonamiento que la decisión 06 para
 * las coordenadas exactas en el mapa general.
 *
 * Devuelve solo METADATOS de cada foto y del caso al que pertenece —
 * los bytes de cada imagen se piden aparte, uno por uno, a
 * admin/evidencia.php (?tam=miniatura). Nunca coordenadas: nada de
 * esto alimenta el muro público ni ninguna exportación.
 *
 * Filtros opcionales: familia, departamento_id, comunidad_id.
 * Paginado: pagina (desde 1), por_pagina (24 por defecto, máx. 60).
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$condiciones = [];
$parametros = [];
if (!empty($_GET['familia'])) {
    $condiciones[] = 'r.familia = :familia';
    $parametros['familia'] = (string) $_GET['familia'];
}
if (!empty($_GET['departamento_id'])) {
    $condiciones[] = 'r.departamento_id = :departamento_id';
    $parametros['departamento_id'] = (int) $_GET['departamento_id'];
}
if (!empty($_GET['comunidad_id'])) {
    $condiciones[] = 'r.comunidad_id = :comunidad_id';
    $parametros['comunidad_id'] = (int) $_GET['comunidad_id'];
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$porPagina = max(1, min(60, (int) ($_GET['por_pagina'] ?? 24)));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$total = $pdo->prepare("SELECT COUNT(*) FROM evidencia e JOIN v_reporte_territorio r ON r.id = e.reporte_id $where");
$total->execute($parametros);

$consulta = $pdo->prepare(
    "SELECT e.id, e.reporte_id, e.fecha_carga,
            r.folio, r.estado, r.familia, r.tipo_hecho, r.comunidad, r.departamento
     FROM evidencia e
         JOIN v_reporte_territorio r ON r.id = e.reporte_id
     $where
     ORDER BY e.fecha_carga DESC, e.id DESC
     LIMIT :limite OFFSET :offset"
);
foreach ($parametros as $clave => $valor) {
    $consulta->bindValue(':' . $clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
$consulta->execute();

obJsonResponse(true, [
    'total' => (int) $total->fetchColumn(),
    'pagina' => $pagina,
    'por_pagina' => $porPagina,
    'fotos' => array_map(static function (array $f): array {
        $f['id'] = (int) $f['id'];
        $f['reporte_id'] = (int) $f['reporte_id'];
        return $f;
    }, $consulta->fetchAll()),
], null, 200);
