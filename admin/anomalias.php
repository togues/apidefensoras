<?php

/**
 * PATCH /api/v1/admin/anomalias/{id} — api_v1.md, sección 4, fila
 * "Marca una anomalía como revisada | administrador". `id` llega por
 * .htaccess.
 *
 * Marcar revisado=TRUE no borra ni cambia nada del reporte — la
 * anomalía sigue en `log_anomalia` como registro de que alguien la
 * miró, con quién y cuándo. Nunca se "resuelve" en el sentido de
 * desaparecer: eso sería perder la bitácora de por qué un reporte se
 * marcó, algo importante en un sistema que existe para dejar rastro.
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
    obJsonResponse(false, null, 'Falta el id de la anomalía', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$existe = $pdo->prepare('SELECT id FROM log_anomalia WHERE id = :id');
$existe->execute(['id' => $id]);
if (!$existe->fetchColumn()) {
    obJsonResponse(false, null, 'Anomalía no encontrada', 404, 'ANOMALIA_NO_ENCONTRADA');
}

$actualizar = $pdo->prepare(
    'UPDATE log_anomalia SET revisado = TRUE, usuario_revision = :usuario WHERE id = :id'
);
$actualizar->execute(['usuario' => $usuarioPanel['id'], 'id' => $id]);

obJsonResponse(true, ['revisado' => true], null, 200);
