<?php

/**
 * POST /api/v1/admin/casos-asignar — asigna la organización
 * responsable del seguimiento a TODOS los casos que cumplen un filtro
 * (los mismos filtros de Casos, lib/FiltrosReporte.php): "de estos 30
 * reportes de la comunidad, los de agua van a la Junta de Agua". Pedido
 * de Marco, 2026-10-20.
 *
 * Cuerpo: { filtros: {...}, organizacion_seguimiento_id: id | null,
 *           esperados: N }
 * `esperados` es cuántos casos mostraba el panel al confirmar: si entre
 * tanto llegaron o cambiaron casos y el filtro ya no da ese número, no
 * se asigna nada (409) — así nadie asigna en lote algo distinto de lo
 * que vio en pantalla.
 *
 * Solo administrador: cambia muchos casos de una vez. Cada caso queda
 * con su propia nota automática en la bitácora (lib/Seguimiento.php).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/FiltrosReporte.php';
require_once __DIR__ . '/../lib/Seguimiento.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$filtros = is_array($body['filtros'] ?? null) ? $body['filtros'] : [];
if (!array_key_exists('organizacion_seguimiento_id', $body) || !isset($body['esperados'])) {
    obJsonResponse(false, null, 'Faltan organizacion_seguimiento_id o esperados', 400, 'DATOS_INCOMPLETOS');
}

$pdo = obDb();
$organizacion = obOrganizacionSeguimiento($pdo, $body['organizacion_seguimiento_id']);
if ($organizacion === null) {
    obJsonResponse(false, null, 'Organización no encontrada', 400, 'DATOS_INVALIDOS');
}

['condiciones' => $condiciones, 'parametros' => $parametros] = obFiltrosReporte($filtros);
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$ids = $pdo->prepare("SELECT id FROM v_reporte_territorio $where ORDER BY id");
$ids->execute($parametros);
$ids = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

if (count($ids) !== (int) $body['esperados']) {
    obJsonResponse(false, ['encontrados' => count($ids)], 'Los casos de ese filtro cambiaron mientras tanto. Revise la lista y vuelva a intentar.', 409, 'CASOS_CAMBIARON');
}
if (count($ids) > 500) {
    obJsonResponse(false, null, 'Son demasiados casos para asignar de una vez (más de 500). Acote el filtro.', 400, 'DEMASIADOS_CASOS');
}

$cambiados = obTransaccion(function (PDO $pdo) use ($ids, $organizacion, $usuarioPanel): int {
    $n = 0;
    foreach ($ids as $id) {
        if (obAsignarSeguimiento($pdo, $id, $organizacion[0], $organizacion[1], (int) $usuarioPanel['id'])) {
            $n++;
        }
    }
    return $n;
});

obJsonResponse(true, ['casos' => count($ids), 'cambiados' => $cambiados], null, 200);
