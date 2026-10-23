<?php

/**
 * GET /api/v1/admin/casos — api_v1.md, sección 4.1.
 * GET /api/v1/admin/casos/{id} — mismo archivo, id llega por .htaccess.
 * PATCH /api/v1/admin/casos/{id} — cambio de estado y/o nota de
 * seguimiento, api_v1.md sección 4 (rol operador). Se agregó junto con
 * el login real (lib/PanelAuth.php) — hasta entonces no había forma
 * honesta de saber quién hacía el cambio para `seguimiento_caso`.
 * DELETE /api/v1/admin/casos/{id} — borra el caso PERMANENTE, solo rol
 * administrador. Pedido de 23 de octubre de 2026: "si van a borrar, van
 * a borrar". Se va todo: el reporte, su bitácora, sus anomalías, sus
 * fotos (fila y archivo, con la miniatura) y, si estaba en el muro, la
 * publicación, porque el muro lee del mismo reporte. Para que no se
 * borre por un toque, el cuerpo trae `confirmar` con el folio exacto.
 * Lo único que queda es una línea en el registro del servidor (folio,
 * quién y cuándo, sin nada del contenido). Los respaldos ya generados
 * lo siguen teniendo hasta que salgan por la retención.
 *
 * Listado con filtros y paginación (4.1) o detalle de un caso. Sobre
 * v_reporte_territorio (migración 005) — nunca dispositivo_id, alias,
 * ni el punto exacto.
 *
 * `prioridad` y `q` (búsqueda de texto libre) se agregaron al construir
 * el buscador del panel — no estaban en la lista de filtros de 4.1.
 * `q` busca en folio, descripción y comunidad. NO hay búsqueda "por
 * persona": no existe ese dato (regla 1) — lo más cerca es que un
 * nombre mencionado en el relato aparezca por la búsqueda de texto,
 * igual que cualquier otra palabra.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/FiltrosReporte.php';
require_once __DIR__ . '/../lib/Seguimiento.php';
require_once __DIR__ . '/../lib/Miniatura.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($metodo, ['GET', 'PATCH', 'DELETE'], true)) {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

/**
 * Coordenada exacta del pin, SOLO para el detalle de un caso puntual —
 * ver docs/decision-05-coordenada-exacta-panel.md. Consulta aparte, no
 * tocar v_reporte_territorio: esa vista alimenta también la lista, y
 * ahí no hace falta ni se pidió cargar una coordenada por fila.
 */
function obCoordenadaExactaDeReporte(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon FROM reporte WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila || $fila['lat'] === null) {
        return null;
    }
    return ['lat' => (float) $fila['lat'], 'lon' => (float) $fila['lon']];
}

if ($metodo === 'DELETE') {
    obRequireRolAdministrador($usuarioPanel);
    if ($id === null) {
        obJsonResponse(false, null, 'Falta el id del caso', 400, 'DATOS_INCOMPLETOS');
    }

    $caso = $pdo->prepare('SELECT folio FROM reporte WHERE id = :id');
    $caso->execute(['id' => $id]);
    $folio = $caso->fetchColumn();
    if ($folio === false) {
        obJsonResponse(false, null, 'Caso no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (trim((string) ($body['confirmar'] ?? '')) !== (string) $folio) {
        obJsonResponse(false, null, 'Para borrar, escriba el folio exacto del caso', 400, 'CONFIRMACION_NO_COINCIDE');
    }

    $fotos = $pdo->prepare('SELECT ruta_archivo FROM evidencia WHERE reporte_id = :id');
    $fotos->execute(['id' => $id]);
    $rutas = $fotos->fetchAll(PDO::FETCH_COLUMN);

    // seguimiento_caso y log_anomalia no tienen cascada; evidencia sí.
    obTransaccion(function (PDO $pdo) use ($id) {
        foreach (['seguimiento_caso', 'log_anomalia'] as $tabla) {
            $pdo->prepare("DELETE FROM $tabla WHERE reporte_id = :id")->execute(['id' => $id]);
        }
        $pdo->prepare('DELETE FROM reporte WHERE id = :id')->execute(['id' => $id]);
    });

    // Los archivos, después de confirmar la base (mismo criterio que
    // admin/evidencia.php): mejor un archivo huérfano que una fila
    // apuntando a nada. Solo dentro de var/evidencias.
    $base = realpath(__DIR__ . '/../var/evidencias');
    foreach ($rutas as $ruta) {
        $absoluta = realpath(__DIR__ . '/../var/' . $ruta);
        if ($base !== false && $absoluta !== false && str_starts_with($absoluta, $base . DIRECTORY_SEPARATOR)) {
            @unlink($absoluta);
            @unlink(obRutaMiniatura($absoluta));
            // rmdir solo borra carpetas vacías: si quedara algo, no se toca.
            @rmdir(dirname(obRutaMiniatura($absoluta)));
            @rmdir(dirname($absoluta));
        }
    }

    error_log(sprintf('[observatorio] caso %s borrado por el usuario %d', $folio, $usuarioPanel['id']));
    obJsonResponse(true, ['folio' => $folio], null, 200);
}

if ($metodo === 'PATCH') {
    if ($id === null) {
        obJsonResponse(false, null, 'Falta el id del caso', 400, 'DATOS_INCOMPLETOS');
    }

    $existe = $pdo->prepare('SELECT estado FROM reporte WHERE id = :id');
    $existe->execute(['id' => $id]);
    $estadoActual = $existe->fetchColumn();
    if ($estadoActual === false) {
        obJsonResponse(false, null, 'Caso no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $estadoNuevo = isset($body['estado']) ? (string) $body['estado'] : null;
    $nota = isset($body['nota']) ? trim((string) $body['nota']) : null;

    if ($estadoNuevo !== null && !in_array($estadoNuevo, ['recibido', 'en_investigacion', 'cerrado', 'descartado'], true)) {
        obJsonResponse(false, null, 'Estado inválido', 400, 'DATOS_INVALIDOS');
    }
    // organizacion_seguimiento_id (migración 011): null o '' = quitar.
    $cambiaOrganizacion = array_key_exists('organizacion_seguimiento_id', $body);
    $organizacion = [null, null];
    if ($cambiaOrganizacion) {
        $organizacion = obOrganizacionSeguimiento($pdo, $body['organizacion_seguimiento_id']);
        if ($organizacion === null) {
            obJsonResponse(false, null, 'Organización no encontrada', 400, 'DATOS_INVALIDOS');
        }
    }
    if ($estadoNuevo === null && ($nota === null || $nota === '') && !$cambiaOrganizacion) {
        obJsonResponse(false, null, 'Mandá un estado nuevo, una nota, una organización de seguimiento, o varios', 400, 'DATOS_INCOMPLETOS');
    }
    // Descartar sin decir por qué deja a quien revise después sin poder
    // saber si fue por contenido inapropiado, un caso repetido, o un
    // error — la nota es lo único que preserva ese criterio.
    if ($estadoNuevo === 'descartado' && ($nota === null || $nota === '')) {
        obJsonResponse(false, null, 'Descartar un caso requiere anotar el motivo', 400, 'NOTA_REQUERIDA');
    }

    obTransaccion(function (PDO $pdo) use ($id, $estadoActual, $estadoNuevo, $nota, $usuarioPanel, $cambiaOrganizacion, $organizacion) {
        if ($cambiaOrganizacion) {
            obAsignarSeguimiento($pdo, $id, $organizacion[0], $organizacion[1], (int) $usuarioPanel['id']);
        }
        if ($estadoNuevo === null && ($nota === null || $nota === '')) {
            return; // solo cambió la organización
        }
        if ($estadoNuevo !== null && $estadoNuevo !== $estadoActual) {
            $actualizar = $pdo->prepare('UPDATE reporte SET estado = :estado WHERE id = :id');
            $actualizar->execute(['estado' => $estadoNuevo, 'id' => $id]);
        }

        $seguimiento = $pdo->prepare(
            'INSERT INTO seguimiento_caso (reporte_id, usuario_id, estado_anterior, estado_nuevo, nota)
             VALUES (:reporte_id, :usuario_id, :estado_anterior, :estado_nuevo, :nota)'
        );
        $seguimiento->execute([
            'reporte_id' => $id,
            'usuario_id' => $usuarioPanel['id'],
            'estado_anterior' => $estadoActual,
            'estado_nuevo' => $estadoNuevo ?? $estadoActual,
            'nota' => $nota !== '' ? $nota : null,
        ]);
    });

    $caso = $pdo->prepare('SELECT * FROM v_reporte_territorio WHERE id = :id');
    $caso->execute(['id' => $id]);
    $fila = $caso->fetch();
    $fila['marcado_anomalo'] = (bool) $fila['marcado_anomalo'];
    $fila['publicado'] = (bool) $fila['publicado'];
    $fila['evidencias'] = (int) $fila['evidencias'];
    $fila['coordenada_exacta'] = obCoordenadaExactaDeReporte($pdo, $id);

    obJsonResponse(true, $fila, null, 200);
}

if ($id !== null) {
    $caso = $pdo->prepare(
        'SELECT * FROM v_reporte_territorio WHERE id = :id'
    );
    $caso->execute(['id' => $id]);
    $fila = $caso->fetch();

    if (!$fila) {
        obJsonResponse(false, null, 'Caso no encontrado', 404, 'REPORTE_NO_ENCONTRADO');
    }

    $seguimiento = $pdo->prepare(
        "SELECT sc.estado_anterior, sc.estado_nuevo, sc.nota, sc.fecha, u.nombre AS usuario
         FROM seguimiento_caso sc
             JOIN usuario u ON u.id = sc.usuario_id
         WHERE sc.reporte_id = :id
         ORDER BY sc.fecha DESC"
    );
    $seguimiento->execute(['id' => $id]);

    // Solo el id: la ruta y el hash del archivo no salen del servidor
    // (api_v1.md 3.6, "evidencia no es accesible por URL directa") — la
    // imagen se sirve aparte, por GET /admin/evidencias/{id}, con sesión
    // real, nunca por una URL que cualquiera pueda adivinar o compartir.
    $evidenciasStmt = $pdo->prepare('SELECT id FROM evidencia WHERE reporte_id = :id ORDER BY id');
    $evidenciasStmt->execute(['id' => $id]);

    $fila['marcado_anomalo'] = (bool) $fila['marcado_anomalo'];
    $fila['publicado'] = (bool) $fila['publicado'];
    $fila['evidencias'] = (int) $fila['evidencias'];
    $fila['evidencias_ids'] = array_map('intval', $evidenciasStmt->fetchAll(PDO::FETCH_COLUMN));
    $fila['seguimiento'] = $seguimiento->fetchAll();
    $fila['coordenada_exacta'] = obCoordenadaExactaDeReporte($pdo, $id);

    obJsonResponse(true, $fila, null, 200);
}

// ------------------------------------------------------------------
// Listado con filtros
// ------------------------------------------------------------------
// Mismos filtros que el tablero de Análisis (incluida la búsqueda por
// texto `q`) — lib/FiltrosReporte.php.
['condiciones' => $condiciones, 'parametros' => $parametros] = obFiltrosReporte($_GET);


$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 25)));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$total = $pdo->prepare("SELECT COUNT(*) FROM v_reporte_territorio $where");
$total->execute($parametros);
$totalFilas = (int) $total->fetchColumn();

$sql = "SELECT id, folio, familia, naturaleza, tipo_hecho AS tipo, estado, prioridad,
               comunidad, canton, distrito, municipio, departamento,
               fecha_registro, evidencias, marcado_anomalo, publicado, organizacion_seguimiento
        FROM v_reporte_territorio
        $where
        ORDER BY fecha_registro DESC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($parametros as $llave => $valor) {
    $stmt->bindValue($llave, $valor);
}
$stmt->bindValue('limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$casos = $stmt->fetchAll();

foreach ($casos as &$c) {
    $c['evidencias'] = (int) $c['evidencias'];
    $c['marcado_anomalo'] = (bool) $c['marcado_anomalo'];
    $c['publicado'] = (bool) $c['publicado'];
}
unset($c);

obJsonResponse(true, [
    'total' => $totalFilas,
    'pagina' => $pagina,
    'por_pagina' => $porPagina,
    'casos' => $casos,
], null, 200);
