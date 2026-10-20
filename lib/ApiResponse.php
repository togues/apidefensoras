<?php

/**
 * Respuestas JSON uniformes — Observatorio API.
 * Calcado de terrosenser/api/apiterrosenser/lib/ApiResponse.php.
 * api_v1.md, sección 1: {success,data} / {success:false,error,code}.
 */

function obJsonResponse(bool $success, $data, ?string $error, int $status, ?string $code = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['success' => $success];
    if ($success) {
        $payload['data'] = $data;
    } else {
        $payload['error'] = $error ?? 'Error';
        if ($code !== null) {
            $payload['code'] = $code;
        }
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Atajo para DATOS_INVALIDOS (422) con el detalle de campos que exige
 * api_v1.md sección "Códigos de error": data.campos detalla cuáles.
 *
 * @param array<string, string> $campos nombre de campo => motivo
 */
function obDatosInvalidos(array $campos): void
{
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Datos inválidos',
        'code' => 'DATOS_INVALIDOS',
        'data' => ['campos' => $campos],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Lee y decodifica el cuerpo JSON de la petición. Rechaza con
 * DATOS_INVALIDOS si no es JSON válido — mejor eso que un error 500
 * o un array vacío que oculte el problema real.
 *
 * @return array<string, mixed>
 */
function obLeerCuerpoJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        obDatosInvalidos(['_cuerpo' => 'El cuerpo de la petición no es JSON válido']);
    }
    return $decoded;
}
