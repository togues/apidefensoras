<?php

/**
 * GET /api/v1/publico/muro — api_v1.md, sección 5.
 *
 * Sin sesión ni token de dispositivo — lo consulta cualquier persona
 * desde `muro/`, la tercera aplicación (front público, mismo backend).
 * Sí exige X-App-Secret, igual que todos los endpoints (CLAUDE.md: "va
 * en todas las peticiones"); acá identifica que la petición viene de
 * `muro/` y no de un cliente cualquiera, no protege nada sensible —
 * este endpoint ya es público por diseño.
 *
 * "Sin coordenada, sin evidencias, sin folio, sin fecha exacta del
 * incidente" (api_v1.md, sección 5). Además, un paso MÁS conservador
 * que el panel interno: el panel muestra la comunidad (`GET
 * /admin/casos` devuelve `comunidad`); acá se sube un nivel, a cantón.
 * Cara al público en general —no solo a REDNA— nombrar la comunidad
 * exacta (a veces un nombre de organización, no solo un lugar) es más
 * de lo que hace falta para "reflejar necesidades del territorio".
 *
 * NO incluye fotografías todavía. Es una pregunta abierta para ACUA
 * (Marco, 2026-09-19): publicar una imagen exige que alguien la revise
 * con el mismo cuidado que el texto —¿se ve un rostro identificable?,
 * ¿un punto de referencia de la vivienda?— y ese paso de moderación de
 * fotos no existe. Mostrar `evidencia.ruta_archivo` sin esa revisión
 * sería saltarse la regla 5 (ubicación nunca exacta) por la puerta de
 * atrás.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$condiciones = ['r.publicado = TRUE'];
$parametros = [];

if (!empty($_GET['familia']) && in_array($_GET['familia'], ['persona', 'territorio', 'gestion', 'logro'], true)) {
    $condiciones[] = 'tv.familia = :familia';
    $parametros['familia'] = $_GET['familia'];
}
if (!empty($_GET['departamento_id'])) {
    $condiciones[] = 'de.id = :departamento_id';
    $parametros['departamento_id'] = (int) $_GET['departamento_id'];
}
if (!empty($_GET['q'])) {
    // Busca en lo que YA es público — resumen_publico, el tipo de
    // hecho, el cantón —, nunca en descripcion (el relato original,
    // que nunca sale de acá — regla 6). Tres nombres de parámetro
    // repitiendo el mismo valor: con EMULATE_PREPARES=false + pgsql,
    // el mismo :nombre no se puede reutilizar dos veces en una
    // consulta (bug ya conocido, ver admin/casos.php).
    $condiciones[] = '(r.resumen_publico ILIKE :q1 OR tv.nombre ILIKE :q2 OR ct.nombre ILIKE :q3)';
    $textoBusqueda = '%' . $_GET['q'] . '%';
    $parametros['q1'] = $textoBusqueda;
    $parametros['q2'] = $textoBusqueda;
    $parametros['q3'] = $textoBusqueda;
}

$where = implode(' AND ', $condiciones);

$porPagina = max(1, min(50, (int) ($_GET['por_pagina'] ?? 20)));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$total = $pdo->prepare(
    "SELECT COUNT(*)
     FROM reporte r
         JOIN tipo_vulneracion tv ON tv.id = r.tipo_vulneracion_id
         LEFT JOIN canton ct        ON ct.id = r.canton_id
         LEFT JOIN distrito di      ON di.id = ct.distrito_id
         LEFT JOIN municipio mu     ON mu.id = di.municipio_id
         LEFT JOIN departamento de  ON de.id = mu.departamento_id
     WHERE $where"
);
$total->execute($parametros);
$totalFilas = (int) $total->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT r.id, tv.familia, tv.naturaleza, tv.nombre AS tipo,
            ct.nombre AS canton, de.nombre AS departamento,
            to_char(r.fecha_incidente, 'YYYY-MM') AS mes_hecho,
            r.resumen_publico
     FROM reporte r
         JOIN tipo_vulneracion tv ON tv.id = r.tipo_vulneracion_id
         LEFT JOIN canton ct        ON ct.id = r.canton_id
         LEFT JOIN distrito di      ON di.id = ct.distrito_id
         LEFT JOIN municipio mu     ON mu.id = di.municipio_id
         LEFT JOIN departamento de  ON de.id = mu.departamento_id
     WHERE $where
     ORDER BY r.fecha_publicacion DESC
     LIMIT :limite OFFSET :offset"
);
foreach ($parametros as $llave => $valor) {
    $stmt->bindValue($llave, $valor);
}
$stmt->bindValue('limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();

obJsonResponse(true, [
    'total' => $totalFilas,
    'pagina' => $pagina,
    'por_pagina' => $porPagina,
    'publicaciones' => $stmt->fetchAll(),
], null, 200);
