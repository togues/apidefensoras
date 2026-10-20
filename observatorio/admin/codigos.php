<?php

/**
 * PATCH /api/v1/admin/codigos/{id} — revoca un código de comunidad
 * (activo = FALSE). Tampoco está en el contrato; ver la nota larga en
 * admin/comunidades.php.
 *
 * Revocar NUNCA borra el código ni desconecta a los dispositivos ya
 * enrolados con él — solo impide enrolar dispositivos NUEVOS
 * (dispositivos/enrolar.php ya filtra por codigo_comunidad.activo).
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
if ($id === null) {
    obJsonResponse(false, null, 'Falta el id del código', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$existe = $pdo->prepare('SELECT id FROM codigo_comunidad WHERE id = :id');
$existe->execute(['id' => $id]);
if (!$existe->fetchColumn()) {
    obJsonResponse(false, null, 'Código no encontrado', 404, 'CODIGO_NO_ENCONTRADO');
}

$revocar = $pdo->prepare('UPDATE codigo_comunidad SET activo = FALSE, fecha_revocacion = NOW() WHERE id = :id');
$revocar->execute(['id' => $id]);

obJsonResponse(true, ['revocado' => true], null, 200);
