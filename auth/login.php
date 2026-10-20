<?php

/**
 * POST /api/v1/auth/login — api_v1.md, sección 2 y 4.
 *
 * Correo y contraseña contra `usuario`, con hash robusto
 * (password_hash/password_verify, no sha256 — acá sí es una contraseña
 * humana, no un token de alta entropía). 10 intentos por hora por IP,
 * per api_v1.md sección "Control de tasa".
 *
 * MISMO error genérico si el correo no existe o si la clave está mal —
 * nunca decir cuál de las dos falló, o el endpoint se vuelve una forma
 * de averiguar qué correos tienen cuenta.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obEnforceRateLimit('auth-login', 10, 3600);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$correo = trim((string) ($body['correo'] ?? ''));
$clave = (string) ($body['clave'] ?? '');

if ($correo === '' || $clave === '') {
    obJsonResponse(false, null, 'Correo y clave son obligatorios', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$stmt = $pdo->prepare('SELECT id, nombre, correo, hash_clave, rol_id, activo FROM usuario WHERE lower(correo) = lower(:correo)');
$stmt->execute(['correo' => $correo]);
$usuario = $stmt->fetch();

if (!$usuario || !$usuario['activo'] || !password_verify($clave, $usuario['hash_clave'])) {
    obApiLog('Login fallido', ['correo' => $correo]);
    obJsonResponse(false, null, 'Correo o clave incorrectos', 401, 'CREDENCIALES_INVALIDAS');
}

$token = obCrearSesionPanel((int) $usuario['id']);

$actualizar = $pdo->prepare('UPDATE usuario SET ultimo_acceso = NOW() WHERE id = :id');
$actualizar->execute(['id' => $usuario['id']]);

$rol = $pdo->prepare('SELECT nombre FROM rol WHERE id = :id');
$rol->execute(['id' => $usuario['rol_id']]);

obJsonResponse(true, [
    'token' => $token,
    'usuario' => [
        'id' => (int) $usuario['id'],
        'nombre' => $usuario['nombre'],
        'correo' => $usuario['correo'],
        'rol' => $rol->fetchColumn(),
    ],
], null, 200);
