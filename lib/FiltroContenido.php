<?php

/**
 * Filtro de contenido — puerto a PHP de src/filtro_contenido.js.
 *
 * El front ya filtra antes de encolar (regla 9 de CLAUDE.md). Esto es la
 * segunda llave: un cliente que hable directo con el API —modificado o
 * mal actualizado— salta el filtro del front, y esta plataforma resguarda
 * información sensible como para confiar en un solo punto de control.
 *
 * IMPORTANTE — acá NUNCA se rechaza el reporte por esto. rechazar es
 * perder evidencia, y esa evidencia puede ser justo un caso real de
 * criminalización o de amenaza de un grupo armado (ver la nota 4 al
 * final de filtro_contenido.js). Lo único que hace este filtro del lado
 * servidor es marcar para revisión humana: log_anomalia con
 * tipo_anomalia = 'filtro_contenido', y reporte.marcado_anomalo = TRUE
 * solo para el nivel 'bloqueo' — 'advertencia' queda en la bitácora sin
 * encender la marca principal, para no ahogar el panel de salud con
 * falsos positivos de baja severidad.
 *
 * Los términos y excepciones deben mantenerse IGUALES a
 * src/filtro_contenido.js. Si uno cambia, cambia el otro — dos copias
 * del mismo criterio que divergen es peor que no tener la segunda.
 */

final class ObFiltroContenido
{
    private const TERMINOS = [
        ['t' => 'bukele', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'nayib', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'presidente', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'gobierno', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'nuevas ideas', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'arena', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'fmln', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'gana', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'pcn', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'pdc', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'alcalde', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'diputado', 'cat' => 'politico', 'nivel' => 'advertencia'],

        ['t' => 'mara', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'maras', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'pandilla', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'pandillero', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'mara salvatrucha', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'ms13', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'ms 13', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'barrio 18', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'terrorista', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        ['t' => 'terrorismo', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
    ];

    private const EXCEPCIONES = [
        'arena' => [
            'extraccion', 'extraen', 'sacando', 'sacan', 'saco', 'rio', 'playa', 'cantera',
            'material', 'camion', 'camiones', 'draga', 'banco', 'ribera', 'quebrada', 'metros',
            'cubicos', 'acarreo', 'extractiva', 'saqueo',
        ],
        'gana' => ['gana dinero', 'se gana', 'ganar', 'ganado', 'le gana', 'nos gana', 'gana la'],
        'presidente' => [
            'presidente de la junta', 'presidente de la adesco', 'presidente del comite',
            'presidente de la directiva', 'presidente de la asociacion', 'presidente de la cooperativa',
        ],
        'gobierno' => ['gobierno local', 'gobierno comunitario', 'buen gobierno', 'gobierno del agua'],
    ];

    private const LEET = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's',
    ];

    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        $descompuesto = Normalizer::normalize($texto, Normalizer::FORM_D) ?: $texto;
        $sinAcentos = preg_replace('/\p{Mn}/u', '', $descompuesto) ?? $descompuesto;
        // "Lenguaje leet" (BUK3L3, @rena): evasión sintáctica obvia, sin
        // IA y sin costo. Debe quedar IGUAL que app/js/domain/filtro-
        // contenido.js — misma lista, mismo mapa. No intenta la evasión
        // semántica: esa la sigue atrapando la persona que revisa en el panel.
        $sustituido = strtr($sinAcentos, self::LEET);
        $limpio = preg_replace('/[^a-z0-9\s]/', ' ', $sustituido) ?? $sustituido;
        return trim(preg_replace('/\s+/', ' ', $limpio) ?? $limpio);
    }

    private static function tieneExcepcion(string $termino, string $textoNorm): bool
    {
        $lista = self::EXCEPCIONES[$termino] ?? null;
        if ($lista === null) {
            return false;
        }
        foreach ($lista as $frase) {
            if (str_contains($textoNorm, self::normalizar($frase))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{resultado: string, hallazgos: list<array{termino: string, categoria: string, nivel: string}>}
     */
    public static function revisar(?string $texto): array
    {
        $norm = self::normalizar((string) $texto);
        $hallazgos = [];

        foreach (self::TERMINOS as $item) {
            $termNorm = self::normalizar($item['t']);
            $patron = '(^|\s)' . preg_replace('/\s+/', '\\\\s+', preg_quote($termNorm, '/')) . '($|\s)';
            if (preg_match('/' . $patron . '/u', $norm) === 1) {
                if (self::tieneExcepcion($termNorm, $norm)) {
                    continue;
                }
                $hallazgos[] = ['termino' => $item['t'], 'categoria' => $item['cat'], 'nivel' => $item['nivel']];
            }
        }

        if ($hallazgos === []) {
            return ['resultado' => 'limpio', 'hallazgos' => []];
        }

        $hayBloqueo = false;
        foreach ($hallazgos as $h) {
            if ($h['nivel'] === 'bloqueo') {
                $hayBloqueo = true;
                break;
            }
        }

        return ['resultado' => $hayBloqueo ? 'bloqueo' : 'advertencia', 'hallazgos' => $hallazgos];
    }

    /**
     * Caracteres de control fuera de tabulación/salto de línea — texto
     * corrupto o inyectado, no una opinión sobre el contenido.
     */
    public static function tieneCaracteresInvalidos(?string $texto): bool
    {
        if ($texto === null || $texto === '') {
            return false;
        }
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $texto) === 1;
    }
}
