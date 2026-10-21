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

    /**
     * Vetos extra SOLO para el muro público (decisión 09). Al reportar no
     * se usan: bloquear "dictadura" o "régimen de excepción" en la app
     * haría que una persona capturada no pudiera ni denunciarlo, y eso es
     * evidencia. En el muro sí se veta: ahí no se pierde nada.
     * Pedido de Marco, 21 de octubre de 2026.
     */
    private const TERMINOS_MURO = [
        // Figuras políticas
        ['t' => 'bukelismo', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'bukelista', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'bukelistas', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'bukelato', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'nayibista', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'ulloa', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'villatoro', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'osiris luna', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'rene merino', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'ernesto castro', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'christian guevara', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'claudia ortiz', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'mauricio funes', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'sanchez ceren', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'tony saca', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'cristiani', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        // Partidos y militancia
        ['t' => 'nuevasideas', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'farabundo', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'efemelenista', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'areneros', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'arenero', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'cambio democratico', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'oficialismo', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'oficialista', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'partido politico', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'partido de gobierno', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'partido', 'cat' => 'politico', 'nivel' => 'advertencia'],
        // Calificaciones del régimen
        ['t' => 'dictadura', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'dictador', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'dictatorial', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'tirano', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'tirania', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'autoritarismo', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'autoritario', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'golpe de estado', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'reeleccion', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'inconstitucional', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'fraude electoral', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'preso politico', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'presos politicos', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'persecucion politica', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'campana electoral', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'campana politica', 'cat' => 'politico', 'nivel' => 'bloqueo'],
        ['t' => 'guerra contra las pandillas', 'cat' => 'seguridad', 'nivel' => 'bloqueo'],
        // Pueden ser un hecho real: se publican solo si alguien confirma
        ['t' => 'regimen', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'regimen de excepcion', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'estado de excepcion', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'cecot', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'elecciones', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'electoral', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'votar', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'votos', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'oposicion', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'opositor', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'opositores', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'asamblea legislativa', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'casa presidencial', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'ministro', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'ministra', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'corrupcion', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'corrupto', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'corruptos', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'represion', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'propaganda', 'cat' => 'politico', 'nivel' => 'advertencia'],
        ['t' => 'fraude', 'cat' => 'politico', 'nivel' => 'advertencia'],
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
        'partido' => ['partido de futbol', 'partido en dos', 'se ha partido', 'esta partido', 'partido el tubo', 'partida'],
        'regimen' => ['regimen de lluvias', 'regimen hidrico', 'regimen del rio'],
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
        return self::revisarCon(self::normalizar((string) $texto), self::TERMINOS);
    }

    /**
     * Revisión para el muro: los términos de siempre más TERMINOS_MURO, y
     * también con las letras sueltas juntadas ("b u k e l e", "d.i.c.t.a.d.u.r.a").
     */
    public static function revisarParaMuro(?string $texto): array
    {
        $norm = self::normalizar((string) $texto);
        $juntado = preg_replace_callback('/(?<![a-z0-9])(?:[a-z0-9] ){2,}[a-z0-9](?![a-z0-9])/',
            static fn ($m) => str_replace(' ', '', $m[0]), $norm) ?? $norm;
        return self::revisarCon($juntado, array_merge(self::TERMINOS, self::TERMINOS_MURO));
    }

    private static function revisarCon(string $norm, array $terminos): array
    {
        $hallazgos = [];
        $vistos = [];

        foreach ($terminos as $item) {
            $termNorm = self::normalizar($item['t']);
            if (isset($vistos[$termNorm])) {
                continue;
            }
            $patron = '(^|\s)' . preg_replace('/\s+/', '\\\\s+', preg_quote($termNorm, '/')) . '($|\s)';
            if (preg_match('/' . $patron . '/u', $norm) === 1) {
                if (self::tieneExcepcion($termNorm, $norm)) {
                    continue;
                }
                $vistos[$termNorm] = true;
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
