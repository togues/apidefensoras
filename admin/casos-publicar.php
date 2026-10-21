<?php

/**
 * POST /api/v1/admin/casos/{id}/publicar — api_v1.md, sección 4.2.
 * Rol administrador (tabla de la sección 4), no alcanza con operador.
 *
 * `resumen_publico` es obligatorio y lo redacta REDNA — el API rechaza
 * la publicación si viene vacío o si coincide con `descripcion` (el
 * relato original NUNCA se publica tal cual, regla 6 de CLAUDE.md).
 */

require_once __DIR__ . '/../config.php';

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
$existe = $pdo->prepare('SELECT descripcion FROM reporte WHERE id = :id');
$existe->execute(['id' => $id]);
$descripcionOriginal = $existe->fetchColumn();
if ($descripcionOriginal === false) {
    obJsonResponse(false, null, 'Caso no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$resumenPublico = trim((string) ($body['resumen_publico'] ?? ''));

if ($resumenPublico === '') {
    obJsonResponse(false, null, 'resumen_publico es obligatorio', 400, 'DATOS_INCOMPLETOS');
}
if ($resumenPublico === trim((string) $descripcionOriginal)) {
    obJsonResponse(false, null, 'El resumen público no puede ser el relato original', 400, 'RESUMEN_IGUAL_AL_RELATO');
}

obTransaccion(function (PDO $pdo) use ($id, $resumenPublico, $usuarioPanel) {
    $publicar = $pdo->prepare(
        'UPDATE reporte SET publicado = TRUE, fecha_publicacion = NOW(), resumen_publico = :resumen WHERE id = :id'
    );
    $publicar->execute(['resumen' => $resumenPublico, 'id' => $id]);

    $seguimiento = $pdo->prepare(
        'INSERT INTO seguimiento_caso (reporte_id, usuario_id, estado_anterior, estado_nuevo, nota, automatica)
         VALUES (:reporte_id, :usuario_id, NULL, NULL, :nota, TRUE)'
    );
    $seguimiento->execute([
        'reporte_id' => $id,
        'usuario_id' => $usuarioPanel['id'],
        'nota' => 'Publicado al muro público.',
    ]);
});

obJsonResponse(true, ['publicado' => true], null, 200);
