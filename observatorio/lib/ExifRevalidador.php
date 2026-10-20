<?php

/**
 * Revalida que una imagen llegue sin metadatos EXIF. La app ya depura
 * antes de encolar (regla 4); esto es la segunda llave del servidor, y
 * el CHECK `chk_evidencia_exif` en la tabla `evidencia` es la tercera
 * —esa es la que de verdad hace imposible insertar sin depurar. Esta
 * clase es lo que decide el valor que ese CHECK exige.
 *
 * LÍMITE CONOCIDO, documentado a propósito. `exif_read_data()` de PHP
 * solo entiende JPEG/TIFF de verdad: para esos dos, revisa las etiquetas
 * de cámara y GPS. PNG y WEBP no tienen soporte nativo en PHP para esto,
 * así que se busca la firma binaria de un bloque EXIF embebido
 * (`eXIf` en PNG, `EXIF` en el contenedor RIFF de WEBP). Es más débil
 * que un parseo real, pero una cámara que produce PNG/WEBP con EXIF es
 * el caso raro: el caso real y común —foto de cámara de teléfono— es
 * JPEG, y ahí la revisión es completa.
 */
final class ObExifRevalidador
{
    /**
     * @return bool true si encontró EXIF (rechazar), false si está limpia
     */
    public static function tieneExif(string $rutaArchivo, string $mimeType): bool
    {
        if ($mimeType === 'image/jpeg') {
            return self::tieneExifJpeg($rutaArchivo);
        }
        if ($mimeType === 'image/png') {
            return self::contieneFirma($rutaArchivo, 'eXIf');
        }
        if ($mimeType === 'image/webp') {
            return self::contieneFirma($rutaArchivo, 'EXIF');
        }
        // Tipo no reconocido: ARCHIVO_NO_ADMITIDO lo filtra antes de
        // llegar acá. Si de algún modo llega, no se confía: se trata
        // como si tuviera EXIF, para no dejar pasar por default.
        return true;
    }

    private static function tieneExifJpeg(string $rutaArchivo): bool
    {
        $datos = @exif_read_data($rutaArchivo, 'ANY_TAG', true, false);
        if ($datos === false) {
            // Sin secciones EXIF que leer: es justo lo que se espera de
            // una imagen depurada.
            return false;
        }
        if (!empty($datos['GPS'])) {
            return true;
        }
        $ifd0 = $datos['IFD0'] ?? [];
        foreach (['Make', 'Model', 'DateTime', 'Software'] as $etiqueta) {
            if (!empty($ifd0[$etiqueta])) {
                return true;
            }
        }
        $exif = $datos['EXIF'] ?? [];
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'LensModel'] as $etiqueta) {
            if (!empty($exif[$etiqueta])) {
                return true;
            }
        }
        return false;
    }

    private static function contieneFirma(string $rutaArchivo, string $firma): bool
    {
        $contenido = @file_get_contents($rutaArchivo);
        if ($contenido === false) {
            return true;
        }
        return str_contains($contenido, $firma);
    }
}
