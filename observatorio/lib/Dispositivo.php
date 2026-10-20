<?php

/**
 * Identidad anónima del dispositivo: generación de token y código de
 * recuperación, y autenticación de nivel 2 (Authorization: Dispositivo
 * <token>) de api_v1.md, sección 2.
 *
 * El servidor SOLO guarda hashes (dispositivo.token_dispositivo,
 * dispositivo.hash_codigo_recup) y compara hashes — nunca almacena el
 * valor en claro. Regla del esquema (001, comentarios de la tabla
 * dispositivo).
 */

const OB_PALABRAS_CODIGO_RECUPERACION = [
    'CEIBA', 'RIO', 'MONTE', 'CAFE', 'MAIZ', 'NUBE', 'PIEDRA', 'VIENTO',
    'VALLE', 'SENDERO', 'ROBLE', 'LAGUNA', 'AURORA', 'MANANTIAL', 'BOSQUE',
    'COLINA', 'ARROYO', 'CEDRO', 'GUAYABA', 'JAGUAR',
];

function obGenerarTokenDispositivo(): string
{
    return bin2hex(random_bytes(32));
}

function obHashToken(string $token): string
{
    return hash('sha256', $token);
}

/**
 * "CEIBA-4471-MONTE" — dos palabras y un número de cuatro dígitos, en el
 * formato del ejemplo de api_v1.md, sección 3.1. Es lo que la persona
 * anota; tiene que ser fácil de copiar a mano y suficientemente amplio
 * para no colisionar en la práctica (20 × 10000 × 19 ≈ 3.8M combinaciones).
 */
function obGenerarCodigoRecuperacion(): string
{
    $palabras = OB_PALABRAS_CODIGO_RECUPERACION;
    $p1 = $palabras[random_int(0, count($palabras) - 1)];
    $p2 = $palabras[random_int(0, count($palabras) - 1)];
    $numero = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    return "{$p1}-{$numero}-{$p2}";
}

function obHashCodigoRecuperacion(string $codigo): string
{
    return hash('sha256', strtoupper(trim($codigo)));
}

/**
 * Lee Authorization: Dispositivo <token>, lo valida contra
 * dispositivo.token_dispositivo (hasheado) y devuelve la fila del
 * dispositivo activo. Corta la petición con TOKEN_INVALIDO si no hay
 * token, no existe o está inactivo.
 *
 * @return array<string, mixed>
 */
function obAutenticarDispositivo(): array
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Dispositivo ') !== 0) {
        obJsonResponse(false, null, 'Token de dispositivo requerido', 401, 'TOKEN_INVALIDO');
    }
    $token = trim(substr($auth, strlen('Dispositivo ')));
    if ($token === '') {
        obJsonResponse(false, null, 'Token de dispositivo requerido', 401, 'TOKEN_INVALIDO');
    }

    $stmt = obDb()->prepare(
        'SELECT id, alias, comunidad_id, codigo_comunidad_id, sexo, departamento_id,
                perfil_completo, activo
         FROM dispositivo
         WHERE token_dispositivo = :hash AND activo = TRUE'
    );
    $stmt->execute(['hash' => obHashToken($token)]);
    $dispositivo = $stmt->fetch();

    if ($dispositivo === false) {
        obJsonResponse(false, null, 'Token de dispositivo inválido o revocado', 401, 'TOKEN_INVALIDO');
    }

    return $dispositivo;
}
