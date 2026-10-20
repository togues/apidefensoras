<?php

/**
 * POST /api/v1/dispositivos/enrolar — api_v1.md, sección 3.1.
 * Registro anónimo. No pide ningún dato que identifique a la persona.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

// api_v1.md, sección 2: 5 por hora por IP.
obEnforceRateLimit('dispositivos-enrolar', 5, 3600);

$body = obLeerCuerpoJson();

$codigoComunidad = trim((string) ($body['codigo_comunidad'] ?? ''));
$alias = isset($body['alias']) ? trim((string) $body['alias']) : null;
if ($alias === '') {
    $alias = null;
}

if ($codigoComunidad === '') {
    obDatosInvalidos(['codigo_comunidad' => 'Es obligatorio']);
}

$pdo = obDb();

// vigente_desde/vigente_hasta (migracion 010): las dos opcionales, NULL
// en cualquiera de las dos significa "sin tope de ese lado" — un código
// sin ninguna de las dos fechas se comporta exactamente como antes.
// Mismo mensaje y mismo código de error que un código que nunca existió
// o que está revocado: que un código esté fuera de su ventana no debe
// distinguirse desde afuera de "no es un código válido".
$stmt = $pdo->prepare(
    'SELECT cc.id AS codigo_comunidad_id, c.id AS comunidad_id, c.nombre AS comunidad_nombre
     FROM codigo_comunidad cc
     JOIN comunidad c ON c.id = cc.comunidad_id
     WHERE cc.codigo = :codigo AND cc.activo = TRUE AND c.activo = TRUE
         AND (cc.vigente_desde IS NULL OR cc.vigente_desde <= NOW())
         AND (cc.vigente_hasta IS NULL OR cc.vigente_hasta >= NOW())'
);
$stmt->execute(['codigo' => strtoupper($codigoComunidad)]);
$comunidad = $stmt->fetch();

if ($comunidad === false) {
    obJsonResponse(false, null, 'Código de comunidad inválido o revocado', 404, 'CODIGO_NO_RECONOCIDO');
}

$token = obGenerarTokenDispositivo();
$tokenHash = obHashToken($token);

// El código de recuperación tiene un espacio de ~3.8M combinaciones
// (20 palabras × 10000 × 19 restantes): improbable pero no imposible que
// choque con uno ya emitido. Se reintenta unas pocas veces ante
// violación de UNIQUE antes de rendirse con ERROR_INTERNO.
$dispositivoId = null;
$codigoRecuperacion = null;
$intentosRestantes = 5;

while ($intentosRestantes > 0) {
    $codigoRecuperacion = obGenerarCodigoRecuperacion();
    $hashCodigoRecup = obHashCodigoRecuperacion($codigoRecuperacion);

    try {
        $insertar = $pdo->prepare(
            'INSERT INTO dispositivo (token_dispositivo, hash_codigo_recup, alias, comunidad_id, codigo_comunidad_id)
             VALUES (:token, :hash_recup, :alias, :comunidad_id, :codigo_comunidad_id)
             RETURNING id'
        );
        $insertar->execute([
            'token' => $tokenHash,
            'hash_recup' => $hashCodigoRecup,
            'alias' => $alias,
            'comunidad_id' => $comunidad['comunidad_id'],
            'codigo_comunidad_id' => $comunidad['codigo_comunidad_id'],
        ]);
        $dispositivoId = (int) $insertar->fetchColumn();
        break;
    } catch (PDOException $e) {
        $esViolacionUnica = $e->getCode() === '23505';
        $intentosRestantes--;
        if (!$esViolacionUnica || $intentosRestantes <= 0) {
            obApiLog('Fallo al enrolar dispositivo', ['mensaje' => $e->getMessage()]);
            obJsonResponse(false, null, 'No se pudo completar el registro', 500, 'ERROR_INTERNO');
        }
        // Colisión de token o de código de recuperación: se regeneran los
        // dos y se reintenta.
        $token = obGenerarTokenDispositivo();
        $tokenHash = obHashToken($token);
    }
}

obJsonResponse(true, [
    'token' => $token,
    'codigo_recuperacion' => $codigoRecuperacion,
    'comunidad' => ['id' => (int) $comunidad['comunidad_id'], 'nombre' => $comunidad['comunidad_nombre']],
    'perfil_completo' => false,
], null, 201);
