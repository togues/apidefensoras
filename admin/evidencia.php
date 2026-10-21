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
 * DELETE /api/v1/admin/evidencias/{id} — la borra PERMANENTE: la fila
 * de `evidencia` y el archivo en disco. Sin papelera ni estado
 * "eliminada" — pedido explícito de Marco (2026-10-20): "no debería
 * quedar en la base de datos, la eliminó permanentemente". Dos motivos
 * para que así sea: la foto puede haberse subido por error (lugar
 * equivocado, foto repetida) y no tiene sentido conservarla solo
 * porque sí; y a diferencia del relato (regla 6, nunca se edita, todo
 * el valor probatorio vive en el texto), una fotografía mal tomada o
 * que ya no hace falta no tiene ese mismo peso — lo que si queda es el
 * RASTRO de que se borró, como nota en `seguimiento_caso` (abajo), no
 * la imagen en sí.
 *
 * No hay `obRequireRolAdministrador()` para ninguno de los dos métodos,
 * a propósito: revisar y gestionar la evidencia de un caso —igual que
 * cambiar su estado o anotar algo— es trabajo de cualquier operador con
 * sesión, no una acción de administración del sistema como crear
 * usuarios o comunidades (esas sí la exigen, ver admin/usuarios.php y
 * admin/comunidades.php).
 *
 * El EXIF ya se depuró y revalidó en reportes/evidencias.php antes de
 * guardarse (chk_evidencia_exif en la base) — servirla acá no repite
 * ese chequeo, confía en la garantía que ya existe.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Miniatura.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
if ($metodo !== 'GET' && $metodo !== 'DELETE') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    obJsonResponse(false, null, 'Falta el id de la evidencia', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$stmt = $pdo->prepare('SELECT reporte_id, ruta_archivo FROM evidencia WHERE id = :id');
$stmt->execute(['id' => $id]);
$fila = $stmt->fetch();

if ($fila === false) {
    obJsonResponse(false, null, 'Evidencia no encontrada', 404, 'EVIDENCIA_NO_ENCONTRADA');
}
$rutaRelativa = $fila['ruta_archivo'];

// `ruta_archivo` la construye el propio servidor al subir la foto
// (reportes/evidencias.php: reporteId numérico + hash sha256 + una de
// tres extensiones fijas) — no viene de ningún dato que la persona
// usuaria controle. Aun así, se valida con realpath() que el archivo
// resuelto siga dentro de var/evidencias antes de servirlo o borrarlo:
// defensa en profundidad, no porque haya una vía conocida de escaparse
// de ahí.
$baseEvidencias = realpath(__DIR__ . '/../var/evidencias');
$rutaAbsoluta = realpath(__DIR__ . '/../var/' . $rutaRelativa);

if ($baseEvidencias === false || $rutaAbsoluta === false || !str_starts_with($rutaAbsoluta, $baseEvidencias . DIRECTORY_SEPARATOR)) {
    obApiLog('Ruta de evidencia fuera de lo esperado', ['evidencia_id' => $id]);
    obJsonResponse(false, null, 'Evidencia no encontrada', 404, 'EVIDENCIA_NO_ENCONTRADA');
}

if ($metodo === 'DELETE') {
    // La fila sale de la base en la MISMA transacción que la nota de
    // bitácora, para que nunca quede un seguimiento huérfano sin la
    // fila que lo originó ni viceversa. El archivo en disco se borra
    // DESPUÉS de que la transacción ya confirmó: si el unlink fallara
    // (permisos, carrera), es preferible un archivo huérfano en disco
    // —se puede limpiar después, sin urgencia— a una fila fantasma en
    // la base apuntando a un archivo que ya no está.
    obTransaccion(function (PDO $pdo) use ($id, $fila, $usuarioPanel) {
        $borrar = $pdo->prepare('DELETE FROM evidencia WHERE id = :id');
        $borrar->execute(['id' => $id]);

        $seguimiento = $pdo->prepare(
            'INSERT INTO seguimiento_caso (reporte_id, usuario_id, nota, automatica)
             VALUES (:reporte_id, :usuario_id, :nota, TRUE)'
        );
        $seguimiento->execute([
            'reporte_id' => $fila['reporte_id'],
            'usuario_id' => $usuarioPanel['id'],
            'nota' => 'Eliminó una fotografía de evidencia.',
        ]);
    });

    @unlink($rutaAbsoluta);
    @unlink(obRutaMiniatura($rutaAbsoluta)); // "permanente" incluye la copia chica
    obJsonResponse(true, null, null, 200);
}

// ?tam=miniatura — versión chica para la galería y los cuadritos del
// detalle (lib/Miniatura.php). Si el servidor no puede generarla, se
// sirve el original: X-Miniatura dice cuál fue, para poder confirmar
// en producción que GD está disponible sin adivinar.
$rutaServida = $rutaAbsoluta;
if (($_GET['tam'] ?? '') === 'miniatura') {
    $miniatura = obMiniaturaDeEvidencia($rutaAbsoluta);
    header('X-Miniatura: ' . ($miniatura !== null ? 'generada' : 'original'));
    if ($miniatura !== null) {
        $rutaServida = $miniatura;
    }
}

$extension = strtolower(pathinfo($rutaServida, PATHINFO_EXTENSION));
$tiposPorExtension = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mime = $tiposPorExtension[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($rutaServida));
// Nunca en un caché compartido (proxy, CDN) — es evidencia sensible; el
// navegador de quien tiene sesión sí puede quedársela un rato para no
// repetir la descarga al reabrir el mismo caso.
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($rutaServida);
