<?php

/**
 * GET /api/v1/actividad — api_v1.md, sección 3.9.
 * Número héroe del Inicio: reportes del mes en el DEPARTAMENTO que la
 * persona eligió al registrarse, nunca personal. Antes era el cantón de
 * la comunidad del código de ingreso; desde la migración 012 el código
 * no dice de qué comunidad es nadie. Si la persona no dijo su
 * departamento, cuenta todo el país.
 * mostrar_numero es FALSE con menos de 5 hechos en el periodo —un número
 * bajo y exacto permite inferir quién reportó (mismo umbral de
 * v_participacion_agregada, regla 2)—.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Dispositivo.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();
$departamentoId = $dispositivo['departamento_id'] !== null ? (int) $dispositivo['departamento_id'] : null;

$sql = "SELECT COUNT(*) FROM v_reporte_territorio
        WHERE fecha_registro >= date_trunc('month', NOW() AT TIME ZONE 'America/El_Salvador') AT TIME ZONE 'America/El_Salvador'";
$parametros = [];
if ($departamentoId !== null) {
    $sql .= ' AND departamento_id = :departamento_id';
    $parametros['departamento_id'] = $departamentoId;
}
$stmt = obDb()->prepare($sql);
$stmt->execute($parametros);
$hechos = (int) $stmt->fetchColumn();

obJsonResponse(true, [
    'periodo' => 'mes',
    'ambito' => $departamentoId !== null ? 'departamento' : 'pais',
    'hechos' => $hechos,
    'mostrar_numero' => $hechos >= 5,
], null, 200);
