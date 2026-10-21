<?php

/**
 * Código de registro (tabla codigo_comunidad, migración 012): una
 * palabra o frase que REDNA reparte para controlar quién se registra en
 * la app durante una capacitación o una gira. No dice nada de la persona
 * ni de su comunidad. Ver docs/decision-08-codigo-de-registro.md.
 *
 * Se guarda y se compara NORMALIZADO, para que "Monseñor Fiallos",
 * "monsenor  fiallos" y "MONSEÑOR FIALLOS" sean el mismo código: quien
 * lo escribe en un teléfono no siempre pone la eñe ni las tildes. La app
 * normaliza igual (app/js/features/ingreso/enrolar.js).
 */

const OB_CODIGO_REGISTRO_MIN = 6;
const OB_CODIGO_REGISTRO_MAX = 40;

function obNormalizarCodigoRegistro(string $texto): string
{
    $texto = mb_strtoupper(trim($texto), 'UTF-8');
    $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $texto = preg_replace('/[^A-Z0-9 ]+/', ' ', $texto) ?? '';
    return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
}

/**
 * "CEIBA ALTA 47": fácil de dictar en voz alta en un taller. Sin 0/O ni
 * 1/I de por medio porque el número va aparte de las palabras.
 */
function obGenerarCodigoRegistro(PDO $pdo): string
{
    $nombres = ['AGUILA', 'CEIBA', 'RIO', 'MONTE', 'LAGUNA', 'ROBLE', 'CEDRO', 'GARZA', 'VOLCAN', 'QUETZAL', 'TOROGOZ','COLIBRI', 'BOSQUE', 'ARROYO', 'MANGLAR', 'JAGUAR'];
    $adjetivos = ['VERDE', 'ALTA', 'CLARA', 'AZUL', 'FUERTE', 'NUEVA', 'GRANDE', 'BRAVA', 'SERENA', 'VIVA'];
    $existe = $pdo->prepare('SELECT 1 FROM codigo_comunidad WHERE codigo = :codigo');
    for ($intento = 0; $intento < 30; $intento++) {
        $codigo = obNormalizarCodigoRegistro(
            $nombres[random_int(0, count($nombres) - 1)] . ' '
            . $adjetivos[random_int(0, count($adjetivos) - 1)] . ' '
            . random_int(10, 99)
        );
        $existe->execute(['codigo' => $codigo]);
        if (!$existe->fetchColumn()) {
            return $codigo;
        }
    }
    throw new RuntimeException('No se pudo generar un código único después de 30 intentos');
}
