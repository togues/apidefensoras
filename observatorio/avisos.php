<?php

/**
 * GET /api/v1/avisos — api_v1.md, sección 3.8.
 * Contenido que REDNA publica hacia las comunidades. Nunca casos
 * individuales — la tabla `aviso` no tiene forma de referenciar un
 * `reporte`, así que eso lo garantiza el esquema, no este archivo.
 * Acepta ?canton_id= opcional. Mismo nivel de confianza que /catalogos:
 * solo secreto de aplicación, porque es contenido agregado que la app
 * cachea desde antes de estar enrolada.
 */

require_once __DIR__ . '/config.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$pdo = obDb();
$condiciones = ['a.activo = TRUE'];
$parametros = [];

if (isset($_GET['canton_id']) && $_GET['canton_id'] !== '') {
    $condiciones[] = 'a.canton_id = :canton_id';
    $parametros['canton_id'] = (int) $_GET['canton_id'];
}

$sql = 'SELECT a.id, a.titulo, a.cuerpo, a.fecha_evento, c.nombre AS canton, a.fecha_publicacion
        FROM aviso a
            LEFT JOIN canton c ON c.id = a.canton_id
        WHERE ' . implode(' AND ', $condiciones) . '
        ORDER BY a.fecha_publicacion DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);

obJsonResponse(true, ['avisos' => $stmt->fetchAll()], null, 200);
