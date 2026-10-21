<?php

/**
 * POST /api/v1/admin/casos/{id}/publicar — api_v1.md, sección 4.2.
 * Rol administrador (tabla de la sección 4), no alcanza con operador.
 *
 * `resumen_publico` es el texto que sale en el muro. Desde la decisión 09
 * (21 de octubre de 2026, autorizada por Marco) puede ser el relato de la
 * persona revisado por el administrador: ortografía corregida, datos que
 * identifican quitados, una línea agregada. `reporte.descripcion` no se
 * toca nunca (regla 6): la versión pública vive aparte.
 *
 * Antes de guardar, el texto pasa por el filtro de contenido y por una
 * revisión de datos de contacto:
 *   - "bloqueo" (partidos, figuras políticas, pandillas): no se publica.
 *   - "advertencia" (gobierno, alcalde, un teléfono, un correo): se
 *     publica solo si llega `advertencias_revisadas: true`.
 * Con `solo_revisar: true` devuelve lo que encontró sin guardar nada:
 * es lo que usa el botón "Revisar texto" del panel.
 *
 * Si el caso ya estaba publicado, reemplaza el texto (queda en la
 * bitácora como "Texto publicado editado").
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/FiltroContenido.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
if ($id === null) {
    obJsonResponse(false, null, 'Falta el id del caso', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$existe = $pdo->prepare('SELECT publicado FROM reporte WHERE id = :id');
$existe->execute(['id' => $id]);
$caso = $existe->fetch();
if ($caso === false) {
    obJsonResponse(false, null, 'Caso no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$resumenPublico = trim((string) ($body['resumen_publico'] ?? ''));

if ($resumenPublico === '') {
    obJsonResponse(false, null, 'El texto para el muro no puede quedar vacío', 400, 'DATOS_INCOMPLETOS');
}
if (ObFiltroContenido::tieneCaracteresInvalidos($resumenPublico)) {
    obJsonResponse(false, null, 'El texto tiene caracteres inválidos', 400, 'CARACTERES_INVALIDOS');
}

$revision = ObFiltroContenido::revisarParaMuro($resumenPublico);
$hallazgos = $revision['hallazgos'];
// Un teléfono o un correo en el muro señala a alguien: se avisa siempre.
if (preg_match('/(?<!\d)\d{4}[\s.-]?\d{4}(?!\d)/', $resumenPublico) === 1) {
    $hallazgos[] = ['termino' => 'número de teléfono', 'categoria' => 'contacto', 'nivel' => 'advertencia'];
}
if (preg_match('/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', $resumenPublico) === 1) {
    $hallazgos[] = ['termino' => 'correo electrónico', 'categoria' => 'contacto', 'nivel' => 'advertencia'];
}
$bloqueos = array_values(array_filter($hallazgos, static fn ($h) => $h['nivel'] === 'bloqueo'));
$advertencias = array_values(array_filter($hallazgos, static fn ($h) => $h['nivel'] === 'advertencia'));

if (!empty($body['solo_revisar'])) {
    obJsonResponse(true, ['bloqueos' => $bloqueos, 'advertencias' => $advertencias], null, 200);
}
if ($bloqueos !== []) {
    obJsonResponse(false, null,
        'El texto menciona partidos, figuras políticas o pandillas: quítelo antes de publicar', 400, 'FILTRO_CONTENIDO');
}
if ($advertencias !== [] && empty($body['advertencias_revisadas'])) {
    obJsonResponse(false, null,
        'Revise las advertencias antes de publicar', 400, 'ADVERTENCIAS_SIN_REVISAR');
}

$editando = (bool) $caso['publicado'];

obTransaccion(function (PDO $pdo) use ($id, $resumenPublico, $usuarioPanel, $editando) {
    $publicar = $pdo->prepare(
        'UPDATE reporte SET publicado = TRUE, fecha_publicacion = COALESCE(fecha_publicacion, NOW()), resumen_publico = :resumen WHERE id = :id'
    );
    $publicar->execute(['resumen' => $resumenPublico, 'id' => $id]);

    $seguimiento = $pdo->prepare(
        'INSERT INTO seguimiento_caso (reporte_id, usuario_id, estado_anterior, estado_nuevo, nota, automatica)
         VALUES (:reporte_id, :usuario_id, NULL, NULL, :nota, TRUE)'
    );
    $seguimiento->execute([
        'reporte_id' => $id,
        'usuario_id' => $usuarioPanel['id'],
        'nota' => $editando ? 'Texto publicado editado.' : 'Publicado al muro público.',
    ]);
});

obJsonResponse(true, ['publicado' => true, 'editado' => $editando], null, 200);
