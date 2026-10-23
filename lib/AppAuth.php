<?php

/**
 * Validación X-App-Secret — nivel 1 de los tres de api_v1.md, sección 2.
 * Calcado de terrosenser/api/apiterrosenser/lib/AppAuth.php.
 *
 * A diferencia de TerroSenser, acá NO es opcional por endpoint: CLAUDE.md
 * dice "X-App-Secret va en todas las peticiones, incluidas las que ya
 * llevan token de dispositivo o sesión". obRequireAppSecret() se llama
 * primero, en config.php de cada endpoint, antes que cualquier otra cosa
 * de autenticación.
 */

function obGetAppSecretFromRequest(): string
{
    $header = $_SERVER['HTTP_X_APP_SECRET'] ?? '';
    if ($header !== '') {
        return trim($header);
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'App ') === 0) {
        return trim(substr($auth, 4));
    }
    return '';
}

function obValidateAppRequest(): bool
{
    if (!defined('API_SECRET') || API_SECRET === '') {
        return true;
    }
    $provided = obGetAppSecretFromRequest();
    if ($provided === '') {
        return false;
    }
    return hash_equals(API_SECRET, $provided);
}

function obRequireAppSecret(): void
{
    $enforce = defined('API_SECRET_ENFORCE') ? API_SECRET_ENFORCE : 'strict';
    if ($enforce === 'off') {
        return;
    }
    // Sin secreto configurado y en modo estricto se cierra, no se abre:
    // un .env incompleto no puede dejar la API a merced de cualquiera.
    if (!defined('API_SECRET') || API_SECRET === '') {
        if ($enforce === 'monitor') {
            return;
        }
        obJsonResponse(false, null, 'Configuración del servidor incompleta', 503, 'APP_SECRET_NO_CONFIGURADO');
    }
    if (obValidateAppRequest()) {
        return;
    }
    if ($enforce === 'monitor') {
        obApiLog('APP_SECRET ausente o inválido (modo monitor)', [
            'path' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        return;
    }
    obJsonResponse(false, null, 'Acceso no autorizado', 403, 'APP_SECRET_FORBIDDEN');
}
