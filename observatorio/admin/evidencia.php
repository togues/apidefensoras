<?php

/**
 * GET /api/v1/admin/evidencias/{id} — no está en api_v1.md sección 4
 * todavía (mismo criterio que /admin/mapa o /admin/analisis: apareció
 * al construir esto; documentar cuando se revise la sección completa).
 *
 * Sirve el ARCHIVO de una fotografía de evidencia, no su metadato. Hasta
 * ahora el panel solo mostraba cuántas fotos tenía un caso (`evidencias`
 * en GET /admin/casos/{id}) con un cuadro de color en vez de la imagen
 * real — quien decide "descartar" o "publicar" un caso lo hacía sin
 * poder verla. api_v1.md 3.6 es explícito: "evidencia no es accesible
 * por URL directa" — eso sigue siendo cierto, esto NO es esa URL
 * directa. Exige sesión real de panel (lib/PanelAuth.php) en vez de ser
 * un archivo estático servido por Apache: sin sesión, 401, igual que
 * cualquier otro endpoint de /admin.
 *
 * No hay `obRequireRolAdministrador()` acá a propósito: revisar
 * evidencia es parte del trabajo de cualquier operador, no solo de
 * quien administra el panel.
 *
 * El EXIF ya se depuró y revalidó en reportes/evidencias.php antes de
 * guardarse (chk_evidencia_exif en la base) — servirla acá no repite
 * ese chequeo, confía en la garantía que ya existe.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
obRequireSesionPanel();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    obJsonResponse(false, null, 'Falta el id de la evidencia', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$stmt = $pdo->prepare('SELECT ruta_archivo FROM evidencia WHERE id = :id');
$stmt->execute(['id' => $id]);
$rutaRelativa = $stmt->fetchColumn();

if ($rutaRelativa === false) {
    obJsonResponse(false, null, 'Evidencia no encontrada', 404, 'EVIDENCIA_NO_ENCONTRADA');
}

// `ruta_archivo` la construye el propio servidor al subir la foto
// (reportes/evidencias.php: reporteId numérico + hash sha256 + una de
// tres extensiones fijas) — no viene de ningún dato que la persona
// usuaria controle. Aun así, se valida con realpath() que el archivo
// resuelto siga dentro de var/evidencias antes de servirlo: defensa en
// profundidad, no porque haya una vía conocida de escaparse de ahí.
$baseEvidencias = realpath(__DIR__ . '/../var/evidencias');
$rutaAbsoluta = realpath(__DIR__ . '/../var/' . $rutaRelativa);

if ($baseEvidencias === false || $rutaAbsoluta === false || !str_starts_with($rutaAbsoluta, $baseEvidencias . DIRECTORY_SEPARATOR)) {
    obApiLog('Ruta de evidencia fuera de lo esperado', ['evidencia_id' => $id]);
    obJsonResponse(false, null, 'Evidencia no encontrada', 404, 'EVIDENCIA_NO_ENCONTRADA');
}

$extension = strtolower(pathinfo($rutaAbsoluta, PATHINFO_EXTENSION));
$tiposPorExtension = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mime = $tiposPorExtension[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($rutaAbsoluta));
// Nunca en un caché compartido (proxy, CDN) — es evidencia sensible; el
// navegador de quien tiene sesión sí puede quedársela un rato para no
// repetir la descarga al reabrir el mismo caso.
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($rutaAbsoluta);
