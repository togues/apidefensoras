<?php

/**
 * GET/POST /api/v1/admin/respaldos, PATCH /admin/respaldos/configuracion,
 * GET /admin/respaldos/{id}/descargar — no está en contratos/api_v1.md
 * sección 4 todavía (mismo criterio que /admin/mapa, /admin/usuarios:
 * apareció al construir la vista, se documenta cuando se revise esa
 * sección completa). Ver docs/decision-04-respaldos.md para el porqué
 * de cada decisión de diseño (custodia de la clave, alcance, por qué
 * "automático" es perezoso y no un cron real).
 *
 * Rol administrador para TODO — descargar la base de datos completa
 * (aunque cifrada) no es una acción de operador.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

$pdo = obDb();
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$directorioVar = __DIR__ . '/../var';

function obConfiguracionRespaldos(PDO $pdo): array
{
    $config = $pdo->query('SELECT automatico_activo, retencion FROM respaldo_configuracion WHERE id = 1')->fetch();
    $config['automatico_activo'] = (bool) $config['automatico_activo'];
    $config['retencion'] = (int) $config['retencion'];
    return $config;
}

// ------------------------------------------------------------------
// GET /admin/respaldos/{id}/descargar
// ------------------------------------------------------------------
if ($metodo === 'GET' && isset($_GET['descargar_id'])) {
    $id = (int) $_GET['descargar_id'];
    $stmt = $pdo->prepare('SELECT nombre_archivo, ruta_archivo FROM respaldo WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        obJsonResponse(false, null, 'Respaldo no encontrado', 404, 'RESPALDO_NO_ENCONTRADO');
    }

    // ruta_archivo la construye el propio servidor (obGenerarRespaldo) —
    // aun así se valida con realpath() que quede dentro de
    // var/respaldos, mismo criterio de defensa en profundidad que
    // admin/evidencia.php.
    $baseRespaldos = realpath($directorioVar . '/respaldos');
    $rutaAbsoluta = realpath($directorioVar . '/' . $fila['ruta_archivo']);
    if ($baseRespaldos === false || $rutaAbsoluta === false || !str_starts_with($rutaAbsoluta, $baseRespaldos . DIRECTORY_SEPARATOR)) {
        obApiLog('Ruta de respaldo fuera de lo esperado', ['respaldo_id' => $id]);
        obJsonResponse(false, null, 'Respaldo no encontrado', 404, 'RESPALDO_NO_ENCONTRADO');
    }

    // "Cada descarga se registra en bitácora" — un respaldo descargado
    // sale del perímetro protegido de la plataforma.
    $registrar = $pdo->prepare('INSERT INTO respaldo_descarga (respaldo_id, usuario_id) VALUES (:respaldo_id, :usuario_id)');
    $registrar->execute(['respaldo_id' => $id, 'usuario_id' => $usuarioPanel['id']]);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fila['nombre_archivo'] . '"');
    header('Content-Length: ' . filesize($rutaAbsoluta));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($rutaAbsoluta);
    exit;
}

// ------------------------------------------------------------------
// PATCH /admin/respaldos/configuracion
// ------------------------------------------------------------------
if ($metodo === 'PATCH' && ($_GET['accion'] ?? '') === 'configuracion') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $campos = [];
    $valores = [];

    if (array_key_exists('automatico_activo', $body)) {
        $campos[] = 'automatico_activo = :automatico_activo';
        $valores['automatico_activo'] = $body['automatico_activo'] ? 'true' : 'false';
    }
    if (array_key_exists('retencion', $body)) {
        $retencion = (int) $body['retencion'];
        if ($retencion < 1 || $retencion > 50) {
            obJsonResponse(false, null, 'La retención debe estar entre 1 y 50', 400, 'DATOS_INVALIDOS');
        }
        $campos[] = 'retencion = :retencion';
        $valores['retencion'] = $retencion;
    }
    if (!$campos) {
        obJsonResponse(false, null, 'Nada que actualizar', 400, 'DATOS_INCOMPLETOS');
    }

    $stmt = $pdo->prepare('UPDATE respaldo_configuracion SET ' . implode(', ', $campos) . ' WHERE id = 1');
    $stmt->execute($valores);

    // Si bajaron la retención, se aplica ya — no hay que esperar al
    // próximo respaldo para que se note.
    obAplicarRetencionRespaldos($pdo, $directorioVar);

    obJsonResponse(true, obConfiguracionRespaldos($pdo), null, 200);
}

// ------------------------------------------------------------------
// POST /admin/respaldos — generar ahora
// ------------------------------------------------------------------
if ($metodo === 'POST') {
    obEnforceRateLimit('respaldo-generar', 5, 3600);

    try {
        $fila = obGenerarRespaldo($pdo, 'manual', (int) $usuarioPanel['id'], $directorioVar);
    } catch (Throwable $e) {
        obApiLog('Fallo al generar respaldo manual', ['mensaje' => $e->getMessage()]);
        obJsonResponse(false, null, 'No se pudo generar el respaldo', 500, 'ERROR_INTERNO');
    }

    obJsonResponse(true, [
        'id' => (int) $fila['id'],
        'nombre_archivo' => $fila['nombre_archivo'],
        'tamano_bytes' => (int) $fila['tamano_bytes'],
        'generado_en' => $fila['generado_en'],
        'metodo' => $fila['metodo'],
    ], null, 201);
}

// ------------------------------------------------------------------
// GET /admin/respaldos — lista + dispara el automático perezoso si toca
// ------------------------------------------------------------------
if ($metodo === 'GET') {
    $config = obConfiguracionRespaldos($pdo);

    if ($config['automatico_activo']) {
        $ultimo = $pdo->query('SELECT generado_en FROM respaldo ORDER BY generado_en DESC LIMIT 1')->fetchColumn();
        $tocaGenerar = $ultimo === false || strtotime((string) $ultimo) <= strtotime('-7 days');
        if ($tocaGenerar) {
            try {
                obGenerarRespaldo($pdo, 'automatico', null, $directorioVar);
            } catch (Throwable $e) {
                // No corta la respuesta: la vista igual puede listar lo
                // que ya había, y el intento queda en el log para
                // revisar por qué falló el automático.
                obApiLog('Fallo al generar respaldo automático perezoso', ['mensaje' => $e->getMessage()]);
            }
        }
    }

    $lista = $pdo->query(
        "SELECT r.id, r.nombre_archivo, r.tamano_bytes, r.generado_en, r.metodo,
                COALESCE(u.nombre, 'Sistema') AS generado_por
         FROM respaldo r
             LEFT JOIN usuario u ON u.id = r.generado_por
         ORDER BY r.generado_en DESC"
    )->fetchAll();
    foreach ($lista as &$fila) {
        $fila['tamano_bytes'] = (int) $fila['tamano_bytes'];
    }
    unset($fila);

    $generados = $pdo->query(
        "SELECT r.generado_en AS fecha, 'generado' AS tipo, r.metodo, COALESCE(u.nombre, 'Sistema') AS usuario, r.nombre_archivo
         FROM respaldo r LEFT JOIN usuario u ON u.id = r.generado_por"
    )->fetchAll();
    $descargados = $pdo->query(
        "SELECT rd.fecha, 'descargado' AS tipo, NULL AS metodo, u.nombre AS usuario, r.nombre_archivo
         FROM respaldo_descarga rd
             JOIN usuario u ON u.id = rd.usuario_id
             JOIN respaldo r ON r.id = rd.respaldo_id"
    )->fetchAll();
    $bitacora = array_merge($generados, $descargados);
    usort($bitacora, static fn (array $a, array $b): int => strcmp($b['fecha'], $a['fecha']));

    obJsonResponse(true, [
        'respaldos' => $lista,
        'configuracion' => obConfiguracionRespaldos($pdo),
        'bitacora' => array_slice($bitacora, 0, 20),
    ], null, 200);
}

obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
