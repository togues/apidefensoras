<?php

/**
 * PATCH /api/v1/admin/codigos/{id} — dos cosas distintas por el mismo
 * verbo, igual que admin/usuarios.php con `activo`:
 *
 *   1. Revocar (como siempre): cuerpo vacío, o {"activo": false}.
 *      Nunca borra el código ni desconecta a los dispositivos ya
 *      enrolados con él — solo impide enrolar dispositivos NUEVOS
 *      (dispositivos/enrolar.php ya filtra por codigo_comunidad.activo).
 *   2. Fijar o extender la ventana de vigencia (migración 010):
 *      {"vigente_desde": "...", "vigente_hasta": "..."} — cualquiera
 *      de las dos, con `null` para quitarle el tope a ese lado. El
 *      panel manda las fechas ya resueltas a las 23:59:59 del día
 *      final; acá no se reinterpreta nada, se guarda tal cual llega.
 *
 * Nunca las dos cosas en la misma llamada: revocar es permanente y no
 * tiene sentido combinarlo con una fecha.
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Cuerpo vacío sigue significando "revocar" — así lo llama el panel
// desde que existe este endpoint, no hay que romper eso.
if ($body === [] || (array_key_exists('activo', $body) && $body['activo'] === false)) {
    $revocar = $pdo->prepare('UPDATE codigo_comunidad SET activo = FALSE, fecha_revocacion = NOW() WHERE id = :id');
    $revocar->execute(['id' => $id]);
    obJsonResponse(true, ['revocado' => true], null, 200);
}

$campos = [];
$parametros = ['id' => $id];
if (array_key_exists('vigente_desde', $body)) {
    $campos[] = 'vigente_desde = :vigente_desde';
    $parametros['vigente_desde'] = $body['vigente_desde'];
}
if (array_key_exists('vigente_hasta', $body)) {
    $campos[] = 'vigente_hasta = :vigente_hasta';
    $parametros['vigente_hasta'] = $body['vigente_hasta'];
}

if ($campos === []) {
    obJsonResponse(false, null, 'Mande {"activo":false} para revocar, o vigente_desde/vigente_hasta para programar la vigencia', 400, 'DATOS_INCOMPLETOS');
}

$pdo->prepare('UPDATE codigo_comunidad SET ' . implode(', ', $campos) . ' WHERE id = :id')->execute($parametros);
obJsonResponse(true, ['actualizado' => true], null, 200);
