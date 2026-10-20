<?php

/**
 * POST /api/v1/reportes/{uuid_local}/evidencias — api_v1.md, sección 3.6.
 * multipart/form-data, campo `archivo`. Una petición por fotografía,
 * después de que el reporte fue confirmado por /reportes/sync.
 *
 * `uuid_local` llega como query string por la reescritura de .htaccess
 * (no hay router: ver CLAUDE.md, "Stack").
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispositivo.php';
require_once __DIR__ . '/../lib/ExifRevalidador.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();

$uuidLocal = trim((string) ($_GET['uuid_local'] ?? ''));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuidLocal)) {
    obDatosInvalidos(['uuid_local' => 'No tiene forma de UUID']);
}

$pdo = obDb();

// No se distingue "no existe" de "existe pero es de otro dispositivo":
// distinguirlo dejaría adivinar uuid_local ajenos por fuerza bruta.
$reporteStmt = $pdo->prepare(
    'SELECT id FROM reporte WHERE uuid_local = :uuid AND dispositivo_id = :dispositivo_id'
);
$reporteStmt->execute(['uuid' => $uuidLocal, 'dispositivo_id' => $dispositivo['id']]);
$reporte = $reporteStmt->fetch();
if ($reporte === false) {
    // api_v1.md no trae un `code` para este caso — se extiende el
    // vocabulario del contrato acá y se deja anotado, igual que con
    // ENTIDAD_NO_PERMITIDA o DATOS_INVALIDOS: el cliente decide por
    // `code`, nunca por el texto.
    obJsonResponse(false, null, 'Reporte no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
}
$reporteId = (int) $reporte['id'];

$conteoStmt = $pdo->prepare('SELECT COUNT(*) FROM evidencia WHERE reporte_id = :id');
$conteoStmt->execute(['id' => $reporteId]);
if ((int) $conteoStmt->fetchColumn() >= 5) {
    obJsonResponse(false, null, 'Este reporte ya tiene el máximo de 5 fotografías', 422, 'ARCHIVO_NO_ADMITIDO');
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
    obDatosInvalidos(['archivo' => 'Es obligatorio']);
}
if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    obJsonResponse(false, null, 'Fallo al recibir el archivo', 422, 'ARCHIVO_NO_ADMITIDO');
}

$rutaTemporal = $_FILES['archivo']['tmp_name'];
$tamano = (int) $_FILES['archivo']['size'];
$MAX_BYTES = 5 * 1024 * 1024;
if ($tamano <= 0 || $tamano > $MAX_BYTES) {
    obJsonResponse(false, null, 'El archivo supera los 5 MB permitidos', 422, 'ARCHIVO_NO_ADMITIDO');
}

// El Content-Type que manda el cliente no es de fiar: se detecta el tipo
// real leyendo la cabecera del archivo.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($rutaTemporal);
$tiposAdmitidos = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeReal, $tiposAdmitidos, true)) {
    obJsonResponse(false, null, 'Solo se admiten imágenes JPEG, PNG o WEBP. Audio no, en ningún caso.', 422, 'ARCHIVO_NO_ADMITIDO');
}

if (ObExifRevalidador::tieneExif($rutaTemporal, $mimeReal)) {
    obJsonResponse(false, null, 'La imagen llegó con metadatos sin depurar', 422, 'EXIF_PRESENTE');
}

$hashArchivo = hash_file('sha256', $rutaTemporal);

$extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$directorioReporte = __DIR__ . '/../var/evidencias/' . $reporteId;
if (!is_dir($directorioReporte) && !@mkdir($directorioReporte, 0700, true) && !is_dir($directorioReporte)) {
    obApiLog('No se pudo crear el directorio de evidencias', ['reporte_id' => $reporteId]);
    obJsonResponse(false, null, 'No se pudo guardar la fotografía', 500, 'ERROR_INTERNO');
}
$nombreArchivo = $hashArchivo . '.' . $extensiones[$mimeReal];
$rutaDestino = $directorioReporte . '/' . $nombreArchivo;

if (!@move_uploaded_file($rutaTemporal, $rutaDestino)) {
    obApiLog('No se pudo mover el archivo subido', ['destino' => $rutaDestino]);
    obJsonResponse(false, null, 'No se pudo guardar la fotografía', 500, 'ERROR_INTERNO');
}

// Ruta guardada RELATIVA a var/, nunca la absoluta del servidor ni una
// URL: evidencia no es accesible por URL directa (api_v1.md, 3.6).
$rutaRelativa = 'evidencias/' . $reporteId . '/' . $nombreArchivo;

try {
    $insertar = $pdo->prepare(
        'INSERT INTO evidencia (reporte_id, ruta_archivo, hash_archivo, exif_depurado)
         VALUES (:reporte_id, :ruta_archivo, :hash_archivo, :exif_depurado)
         RETURNING id'
    );
    $insertar->execute([
        'reporte_id' => $reporteId,
        'ruta_archivo' => $rutaRelativa,
        'hash_archivo' => $hashArchivo,
        'exif_depurado' => 'true',
    ]);
    $evidenciaId = (int) $insertar->fetchColumn();
} catch (PDOException $e) {
    @unlink($rutaDestino);
    obApiLog('Fallo al insertar evidencia', ['mensaje' => $e->getMessage()]);
    obJsonResponse(false, null, 'No se pudo guardar la fotografía', 500, 'ERROR_INTERNO');
}

obJsonResponse(true, ['id' => $evidenciaId, 'hash_archivo' => $hashArchivo], null, 201);
