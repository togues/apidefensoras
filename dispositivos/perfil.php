<?php

/**
 * PATCH /api/v1/dispositivos/perfil — api_v1.md, sección 3.3.
 * Segundo tramo del ingreso. Todos los campos opcionales; enviar el
 * endpoint marca perfil_completo = true. Regla 2: sexo y estructura son
 * de uso agregado — este endpoint los guarda pero jamás los devuelve en
 * ninguna otra respuesta.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();
$body = obLeerCuerpoJson();

$sexosValidos = ['mujer', 'hombre', 'no_declara'];
$campos = [];
$errores = [];

if (array_key_exists('sexo', $body) && $body['sexo'] !== null) {
    $sexo = (string) $body['sexo'];
    if (!in_array($sexo, $sexosValidos, true)) {
        $errores['sexo'] = 'Debe ser mujer, hombre o no_declara';
    } else {
        $campos['sexo'] = $sexo;
    }
}

if (array_key_exists('departamento_id', $body) && $body['departamento_id'] !== null) {
    $departamentoId = (int) $body['departamento_id'];
    $existe = obDb()->prepare('SELECT 1 FROM departamento WHERE id = :id');
    $existe->execute(['id' => $departamentoId]);
    if ($existe->fetchColumn() === false) {
        $errores['departamento_id'] = 'No existe ese departamento';
    } else {
        $campos['departamento_id'] = $departamentoId;
    }
}

$estructuras = null;
if (array_key_exists('estructuras', $body) && $body['estructuras'] !== null) {
    if (!is_array($body['estructuras'])) {
        $errores['estructuras'] = 'Debe ser una lista de ids';
    } else {
        $estructuras = array_values(array_unique(array_map('intval', $body['estructuras'])));
        if ($estructuras !== []) {
            $marcadores = implode(',', array_fill(0, count($estructuras), '?'));
            $stmt = obDb()->prepare("SELECT id FROM estructura_organizativa WHERE id IN ($marcadores)");
            $stmt->execute($estructuras);
            $validos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $invalidos = array_diff($estructuras, $validos);
            if ($invalidos !== []) {
                $errores['estructuras'] = 'ids inválidos: ' . implode(',', $invalidos);
            }
        }
    }
}

if ($errores !== []) {
    obDatosInvalidos($errores);
}

obTransaccion(function (PDO $pdo) use ($dispositivo, $campos, $estructuras): void {
    $set = [];
    $parametros = ['id' => $dispositivo['id']];
    foreach ($campos as $columna => $valor) {
        $set[] = "$columna = :$columna";
        $parametros[$columna] = $valor;
    }
    $set[] = 'perfil_completo = TRUE';
    $sql = 'UPDATE dispositivo SET ' . implode(', ', $set) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($parametros);

    if ($estructuras !== null) {
        $pdo->prepare('DELETE FROM dispositivo_estructura WHERE dispositivo_id = :id')
            ->execute(['id' => $dispositivo['id']]);
        if ($estructuras !== []) {
            $insertar = $pdo->prepare(
                'INSERT INTO dispositivo_estructura (dispositivo_id, estructura_id) VALUES (:dispositivo_id, :estructura_id)'
            );
            foreach ($estructuras as $estructuraId) {
                $insertar->execute(['dispositivo_id' => $dispositivo['id'], 'estructura_id' => $estructuraId]);
            }
        }
    }
});

obJsonResponse(true, ['perfil_completo' => true], null, 200);
