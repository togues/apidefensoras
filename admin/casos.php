<?php

/**
 * GET /api/v1/admin/casos — api_v1.md, sección 4.1.
 * GET /api/v1/admin/casos/{id} — mismo archivo, id llega por .htaccess.
 * PATCH /api/v1/admin/casos/{id} — cambio de estado y/o nota de
 * seguimiento, api_v1.md sección 4 (rol operador). Se agregó junto con
 * el login real (lib/PanelAuth.php) — hasta entonces no había forma
 * honesta de saber quién hacía el cambio para `seguimiento_caso`.
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

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($metodo, ['GET', 'PATCH'], true)) {
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
    if ($estadoNuevo === null && ($nota === null || $nota === '')) {
        obJsonResponse(false, null, 'Mandá un estado nuevo, una nota, o los dos', 400, 'DATOS_INCOMPLETOS');
    }
    // Descartar sin decir por qué deja a quien revise después sin poder
    // saber si fue por contenido inapropiado, un caso repetido, o un
    // error — la nota es lo único que preserva ese criterio.
    if ($estadoNuevo === 'descartado' && ($nota === null || $nota === '')) {
        obJsonResponse(false, null, 'Descartar un caso requiere anotar el motivo', 400, 'NOTA_REQUERIDA');
    }

    obTransaccion(function (PDO $pdo) use ($id, $estadoActual, $estadoNuevo, $nota, $usuarioPanel) {
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
$condiciones = [];
$parametros = [];

if (!empty($_GET['comunidad_id'])) {
    $condiciones[] = 'comunidad_id = :comunidad_id';
    $parametros['comunidad_id'] = (int) $_GET['comunidad_id'];
}
if (!empty($_GET['departamento_id'])) {
    $condiciones[] = 'departamento_id = :departamento_id';
    $parametros['departamento_id'] = (int) $_GET['departamento_id'];
}
if (!empty($_GET['canton_id'])) {
    $condiciones[] = 'canton_id = :canton_id';
    $parametros['canton_id'] = (int) $_GET['canton_id'];
}
if (!empty($_GET['familia']) && in_array($_GET['familia'], ['persona', 'territorio', 'gestion', 'logro'], true)) {
    $condiciones[] = 'familia = :familia';
    $parametros['familia'] = $_GET['familia'];
}
if (!empty($_GET['tipo_vulneracion_id'])) {
    $condiciones[] = 'tipo_vulneracion_id = :tipo_vulneracion_id';
    $parametros['tipo_vulneracion_id'] = (int) $_GET['tipo_vulneracion_id'];
}
if (!empty($_GET['estado']) && in_array($_GET['estado'], ['recibido', 'en_investigacion', 'cerrado', 'descartado'], true)) {
    $condiciones[] = 'estado = :estado';
    $parametros['estado'] = $_GET['estado'];
}
if (isset($_GET['marcado_anomalo']) && $_GET['marcado_anomalo'] !== '') {
    $condiciones[] = 'marcado_anomalo = :marcado_anomalo';
    $parametros['marcado_anomalo'] = filter_var($_GET['marcado_anomalo'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
}
if (!empty($_GET['prioridad']) && in_array($_GET['prioridad'], ['alta', 'media', 'baja'], true)) {
    $condiciones[] = 'prioridad = :prioridad';
    $parametros['prioridad'] = $_GET['prioridad'];
}
if (!empty($_GET['q'])) {
    // PDO nativo (EMULATE_PREPARES=false) contra pgsql no reutiliza de
    // forma confiable el mismo parámetro con nombre dos veces en una
    // consulta — bug ya encontrado antes en reportes/sync.php y
    // MotorAlertas.php. Tres nombres distintos, mismo valor.
    $condiciones[] = '(folio ILIKE :q1 OR descripcion ILIKE :q2 OR comunidad ILIKE :q3)';
    $textoBusqueda = '%' . $_GET['q'] . '%';
    $parametros['q1'] = $textoBusqueda;
    $parametros['q2'] = $textoBusqueda;
    $parametros['q3'] = $textoBusqueda;
}
if (!empty($_GET['desde'])) {
    $condiciones[] = 'fecha_registro >= :desde';
    $parametros['desde'] = $_GET['desde'];
}
if (!empty($_GET['hasta'])) {
    $condiciones[] = 'fecha_registro <= :hasta';
    $parametros['hasta'] = $_GET['hasta'];
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$porPagina = max(1, min(100, (int) ($_GET['por_pagina'] ?? 25)));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$total = $pdo->prepare("SELECT COUNT(*) FROM v_reporte_territorio $where");
$total->execute($parametros);
$totalFilas = (int) $total->fetchColumn();

$sql = "SELECT id, folio, familia, naturaleza, tipo_hecho AS tipo, estado, prioridad,
               comunidad, canton, distrito, municipio, departamento,
               fecha_registro, evidencias, marcado_anomalo, publicado
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
