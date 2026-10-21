<?php

/**
 * Miniatura de una fotografía de evidencia — para la galería del panel
 * y las miniaturas del detalle de un caso. Antes cada cuadrito pedía el
 * archivo original completo (hasta 5 MB, ver reportes/evidencias.php);
 * con una galería de decenas de fotos a la vez, en las conexiones que
 * tiene REDNA, eso no es viable.
 *
 * Se genera una sola vez y queda en caché en disco, al lado del
 * original, dentro de var/evidencias (nunca accesible por URL directa —
 * mismo criterio que el original, api_v1.md 3.6). Reencodear con GD
 * descarta cualquier metadato de paso; el original ya llegó sin EXIF
 * (chk_evidencia_exif), así que esto no es una garantía nueva, es una
 * consecuencia.
 *
 * Si el servidor no tiene GD (o no soporta el formato), devuelve null y
 * quien llama sirve el original: más lento, pero nunca roto.
 */

const OB_MINIATURA_LADO_MAX = 400; // ~200px en pantalla a densidad 2x
const OB_MINIATURA_CALIDAD = 72;

function obRutaMiniatura(string $rutaOriginal): string
{
    return dirname($rutaOriginal) . '/miniaturas/' . pathinfo($rutaOriginal, PATHINFO_FILENAME) . '.jpg';
}

function obMiniaturaDeEvidencia(string $rutaOriginal): ?string
{
    $rutaMiniatura = obRutaMiniatura($rutaOriginal);
    if (is_file($rutaMiniatura)) {
        return $rutaMiniatura;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $extension = strtolower(pathinfo($rutaOriginal, PATHINFO_EXTENSION));
    $abrir = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$extension] ?? null;
    if ($abrir === null || !function_exists($abrir)) {
        return null;
    }

    $origen = @$abrir($rutaOriginal);
    if ($origen === false) {
        return null;
    }

    $ancho = imagesx($origen);
    $alto = imagesy($origen);
    $escala = min(1, OB_MINIATURA_LADO_MAX / max($ancho, $alto));
    $anchoNuevo = max(1, (int) round($ancho * $escala));
    $altoNuevo = max(1, (int) round($alto * $escala));

    $destino = imagecreatetruecolor($anchoNuevo, $altoNuevo);
    // Fondo blanco: un PNG con transparencia pasado a JPEG quedaría negro.
    imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $anchoNuevo, $altoNuevo, $ancho, $alto);

    $directorio = dirname($rutaMiniatura);
    if (!is_dir($directorio) && !@mkdir($directorio, 0700, true) && !is_dir($directorio)) {
        return null;
    }
    // Se escribe a un temporal y se renombra: dos peticiones simultáneas
    // por la misma foto nunca dejan una miniatura a medio escribir.
    $temporal = $rutaMiniatura . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = imagejpeg($destino, $temporal, OB_MINIATURA_CALIDAD);
    if (!$ok || !@rename($temporal, $rutaMiniatura)) {
        @unlink($temporal);
        return null;
    }
    return $rutaMiniatura;
}
