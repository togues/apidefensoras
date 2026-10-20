<?php

/**
 * POST /api/v1/dispositivos/recuperar — api_v1.md, sección 3.2.
 * Restaura identidad en un teléfono nuevo. Restaura identidad, NO
 * historial: obligar a alguien a entregar su código no expone lo que
 * denunció (GET /mis-reportes en el dispositivo restaurado da vacío,
 * porque los reportes ya sincronizados quedan asociados al dispositivo_id
 * original, no al restaurado, y este endpoint no los reasigna).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

// api_v1.md, sección 2: 5 por hora por IP.
obEnforceRateLimit('dispositivos-recuperar', 5, 3600);

$body = obLeerCuerpoJson();
$codigoRecuperacion = trim((string) ($body['codigo_recuperacion'] ?? ''));

if ($codigoRecuperacion === '') {
    obDatosInvalidos(['codigo_recuperacion' => 'Es obligatorio']);
}

$pdo = obDb();

$stmt = $pdo->prepare(
    'SELECT d.id, d.perfil_completo, c.id AS comunidad_id, c.nombre AS comunidad_nombre
     FROM dispositivo d
         LEFT JOIN comunidad c ON c.id = d.comunidad_id
     WHERE d.hash_codigo_recup = :hash AND d.activo = TRUE'
);
$stmt->execute(['hash' => obHashCodigoRecuperacion($codigoRecuperacion)]);
$dispositivo = $stmt->fetch();

if ($dispositivo === false) {
    obJsonResponse(false, null, 'Código de recuperación inválido', 404, 'CODIGO_RECUPERACION_INVALIDO');
}

$tokenNuevo = obGenerarTokenDispositivo();
$codigoRecuperacionNuevo = null;
$intentosRestantes = 5;

while ($intentosRestantes > 0) {
    $codigoRecuperacionNuevo = obGenerarCodigoRecuperacion();
    try {
        $actualizar = $pdo->prepare(
            'UPDATE dispositivo
             SET token_dispositivo = :token,
                 hash_codigo_recup = :hash_recup,
                 fecha_ultima_recup = NOW(),
                 veces_recuperado = veces_recuperado + 1
             WHERE id = :id'
        );
        $actualizar->execute([
            'token' => obHashToken($tokenNuevo),
            'hash_recup' => obHashCodigoRecuperacion($codigoRecuperacionNuevo),
            'id' => $dispositivo['id'],
        ]);
        break;
    } catch (PDOException $e) {
        $esViolacionUnica = $e->getCode() === '23505';
        $intentosRestantes--;
        if (!$esViolacionUnica || $intentosRestantes <= 0) {
            obApiLog('Fallo al recuperar dispositivo', ['mensaje' => $e->getMessage()]);
            obJsonResponse(false, null, 'No se pudo completar la recuperación', 500, 'ERROR_INTERNO');
        }
        $tokenNuevo = obGenerarTokenDispositivo();
    }
}

obJsonResponse(true, [
    'token' => $tokenNuevo,
    'codigo_recuperacion' => $codigoRecuperacionNuevo,
    'comunidad' => $dispositivo['comunidad_id'] !== null
        ? ['id' => (int) $dispositivo['comunidad_id'], 'nombre' => $dispositivo['comunidad_nombre']]
        : null,
    'perfil_completo' => (bool) $dispositivo['perfil_completo'],
], null, 200);
