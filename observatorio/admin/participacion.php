<?php

/**
 * GET /api/v1/admin/participacion — api_v1.md, sección 4.3. Rol
 * administrador (tabla de la sección 4).
 *
 * "Única vía para consultar sexo y estructura organizativa. Lee de
 * v_participacion_agregada, que descarta todo grupo de menos de 5
 * personas." Nada acá cruza con reportes ni con casos individuales —
 * ni podría, la vista ya no tiene ese vínculo (migración 001).
 *
 * `dispositivos_activos` y `con_al_menos_un_reporte` van aparte, fuera
 * de `grupos`: no cruzan con sexo/estructura, así que no tienen el
 * riesgo que protege el umbral de 5 y se pueden mostrar exactos —
 * "cuántas personas participan" en total, la pregunta que hizo Marco
 * pensando en informes a donantes.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();

$grupos = $pdo->query(
    'SELECT departamento, sexo, estructura, personas FROM v_participacion_agregada ORDER BY departamento, sexo'
)->fetchAll();

$dispositivosActivos = (int) $pdo->query('SELECT COUNT(*) FROM dispositivo WHERE activo = TRUE')->fetchColumn();

$conAlMenosUnReporte = (int) $pdo->query(
    'SELECT COUNT(DISTINCT dispositivo_id) FROM reporte'
)->fetchColumn();

obJsonResponse(true, [
    'dispositivos_activos' => $dispositivosActivos,
    'con_al_menos_un_reporte' => $conAlMenosUnReporte,
    'grupos' => array_map(static function (array $g): array {
        $g['personas'] = (int) $g['personas'];
        return $g;
    }, $grupos),
], null, 200);
