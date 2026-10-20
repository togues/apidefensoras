<?php

/**
 * GET/POST /api/v1/admin/comunidades, POST
 * /api/v1/admin/comunidades/{id}/codigos — tampoco está en
 * contratos/api_v1.md sección 4, mismo motivo que admin/usuarios.php:
 * hoy cada comunidad y cada código de enrolamiento se crea a mano por
 * SQL (ver bd/piloto/, bd/demo/), y eso no es sostenible una vez que
 * REDNA empiece a capacitar comunidades reales sin un desarrollador
 * al lado. Rol administrador para todo — generar el código que abre
 * la puerta de la app comunitaria es tan sensible como el control de
 * acceso mismo.
 *
 * Revocar un código NUNCA borra el dispositivo ya enrolado con él
 * (api_v1.md ya lo documenta para el contrato de la app: "revocar uno
 * no afecta los reportes ya enviados"). Solo impide que alguien enrole
 * un dispositivo NUEVO con ese código — ver
 * lib/Dispositivo.php/dispositivos/enrolar.php, que ya filtra por
 * codigo_comunidad.activo.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

$pdo = obDb();
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null; // id de COMUNIDAD

/**
 * EXACTAMENTE 6 caracteres, sin separador — la pantalla de ingreso de
 * la app comunitaria exige `length === 6` a rajatabla
 * (app/js/features/ingreso/pasos.js: `maxlength="6"` y el botón
 * "Seguir" deshabilitado si no son 6; el mismo criterio del fixture
 * ZAP001 usado en toda la suite de pruebas). Antes esta función
 * devolvía "PREFIJO-SUFIJO" (7 caracteres, con guión) — un código que
 * ninguna persona podría haber escrito nunca en el campo real.
 * Encontrado al probar un enrolamiento real de punta a punta contra un
 * despliegue de producción (Marco, 20 de septiembre de 2026), no en
 * una prueba automatizada — ninguna de las pruebas existentes hasta
 * ahora había generado un código por este camino y después intentado
 * enrolarse con él de verdad en la app.
 */
function obGenerarCodigoComunidad(PDO $pdo, string $nombreCanton): string
{
    $prefijo = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nombreCanton) ?: 'COM', 0, 3));
    $prefijo = str_pad($prefijo, 3, 'X');
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I, se confunden al dictarlos

    for ($intento = 0; $intento < 20; $intento++) {
        $sufijo = '';
        for ($i = 0; $i < 3; $i++) {
            $sufijo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $codigo = $prefijo . $sufijo;
        $existe = $pdo->prepare('SELECT 1 FROM codigo_comunidad WHERE codigo = :codigo');
        $existe->execute(['codigo' => $codigo]);
        if (!$existe->fetchColumn()) {
            return $codigo;
        }
    }
    throw new RuntimeException('No se pudo generar un código único después de 20 intentos');
}

if ($metodo === 'GET') {
    // Sin WHERE co.activo = TRUE: igual que admin/usuarios.php, una
    // comunidad desactivada sigue en la lista (al final) para poder
    // reactivarla — nunca desaparece de golpe.
    $comunidades = $pdo->query(
        "SELECT co.id, co.nombre, co.tipo, co.activo, ct.nombre AS canton,
                (SELECT COUNT(*) FROM dispositivo d WHERE d.comunidad_id = co.id AND d.activo = TRUE) AS personas_inscritas
         FROM comunidad co
             LEFT JOIN canton ct ON ct.id = co.canton_id
         ORDER BY co.activo DESC, co.nombre"
    )->fetchAll();

    $codigos = $pdo->query(
        "SELECT id, comunidad_id, codigo, activo, fecha_emision, vigente_desde, vigente_hasta
         FROM codigo_comunidad ORDER BY fecha_emision DESC"
    )->fetchAll();

    $cantones = $pdo->query(
        "SELECT ct.id, ct.nombre AS canton, di.nombre AS distrito, mu.nombre AS municipio, de.nombre AS departamento
         FROM canton ct
             JOIN distrito di ON di.id = ct.distrito_id
             JOIN municipio mu ON mu.id = di.municipio_id
             JOIN departamento de ON de.id = mu.departamento_id
         ORDER BY de.nombre, ct.nombre"
    )->fetchAll();

    $codigosPorComunidad = [];
    foreach ($codigos as $c) {
        $c['activo'] = (bool) $c['activo'];
        $codigosPorComunidad[$c['comunidad_id']][] = $c;
    }

    obJsonResponse(true, [
        'comunidades' => array_map(static function (array $co) use ($codigosPorComunidad): array {
            $co['activo'] = (bool) $co['activo'];
            $co['personas_inscritas'] = (int) $co['personas_inscritas'];
            $co['codigos'] = $codigosPorComunidad[$co['id']] ?? [];
            return $co;
        }, $comunidades),
        'cantones_disponibles' => $cantones,
    ], null, 200);
}

if ($metodo === 'POST' && $id === null) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $tipo = trim((string) ($body['tipo'] ?? 'comunidad'));
    $cantonId = (int) ($body['canton_id'] ?? 0);

    if ($nombre === '' || $cantonId === 0) {
        obJsonResponse(false, null, 'Nombre y cantón son obligatorios', 400, 'DATOS_INCOMPLETOS');
    }
    $canton = $pdo->prepare('SELECT nombre FROM canton WHERE id = :id');
    $canton->execute(['id' => $cantonId]);
    $nombreCanton = $canton->fetchColumn();
    if ($nombreCanton === false) {
        obJsonResponse(false, null, 'Cantón no encontrado', 404, 'CANTON_NO_ENCONTRADO');
    }

    $resultado = obTransaccion(function (PDO $pdo) use ($nombre, $tipo, $cantonId, $nombreCanton) {
        $crear = $pdo->prepare('INSERT INTO comunidad (canton_id, nombre, tipo, activo) VALUES (:canton_id, :nombre, :tipo, TRUE) RETURNING id');
        $crear->execute(['canton_id' => $cantonId, 'nombre' => $nombre, 'tipo' => $tipo]);
        $comunidadId = (int) $crear->fetchColumn();

        $codigo = obGenerarCodigoComunidad($pdo, $nombreCanton);
        $insertarCodigo = $pdo->prepare('INSERT INTO codigo_comunidad (comunidad_id, codigo, activo) VALUES (:comunidad_id, :codigo, TRUE)');
        $insertarCodigo->execute(['comunidad_id' => $comunidadId, 'codigo' => $codigo]);

        return ['comunidad_id' => $comunidadId, 'codigo' => $codigo];
    });

    obJsonResponse(true, ['id' => $resultado['comunidad_id'], 'nombre' => $nombre, 'codigo' => $resultado['codigo']], null, 201);
}

if ($metodo === 'POST' && $id !== null) {
    // POST /admin/comunidades/{id}/codigos — un código adicional para
    // una comunidad que ya existe (p. ej. después de revocar el
    // anterior).
    $comunidad = $pdo->prepare('SELECT co.nombre, ct.nombre AS canton FROM comunidad co JOIN canton ct ON ct.id = co.canton_id WHERE co.id = :id');
    $comunidad->execute(['id' => $id]);
    $fila = $comunidad->fetch();
    if (!$fila) {
        obJsonResponse(false, null, 'Comunidad no encontrada', 404, 'COMUNIDAD_NO_ENCONTRADA');
    }

    $codigo = obGenerarCodigoComunidad($pdo, $fila['canton']);
    $insertar = $pdo->prepare('INSERT INTO codigo_comunidad (comunidad_id, codigo, activo) VALUES (:id, :codigo, TRUE) RETURNING id');
    $insertar->execute(['id' => $id, 'codigo' => $codigo]);

    obJsonResponse(true, ['id' => (int) $insertar->fetchColumn(), 'codigo' => $codigo], null, 201);
}

if ($metodo === 'PATCH') {
    // PATCH /admin/comunidades/{id} — editar nombre/tipo, o
    // desactivar/reactivar (igual que admin/usuarios.php: nunca se
    // borra, se desactiva). Nunca toca canton_id ni geom acá: mudar
    // una comunidad de cantón es un caso raro y mejor hecho a mano,
    // con cuidado, no de un campo de texto suelto en este formulario.
    if ($id === null) {
        obJsonResponse(false, null, 'Falta el id de la comunidad', 400, 'DATOS_INCOMPLETOS');
    }
    $existe = $pdo->prepare('SELECT id FROM comunidad WHERE id = :id');
    $existe->execute(['id' => $id]);
    if (!$existe->fetchColumn()) {
        obJsonResponse(false, null, 'Comunidad no encontrada', 404, 'COMUNIDAD_NO_ENCONTRADA');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $campos = [];
    $parametros = ['id' => $id];

    if (array_key_exists('nombre', $body)) {
        $nombre = trim((string) $body['nombre']);
        if ($nombre === '') {
            obJsonResponse(false, null, 'El nombre no puede quedar vacío', 400, 'DATOS_INVALIDOS');
        }
        $campos[] = 'nombre = :nombre';
        $parametros['nombre'] = $nombre;
    }
    if (array_key_exists('tipo', $body)) {
        $campos[] = 'tipo = :tipo';
        $parametros['tipo'] = trim((string) $body['tipo']);
    }
    if (array_key_exists('activo', $body)) {
        // PHP false -> PDO lo manda como '' con EMULATE_PREPARES=false
        // contra pgsql, y una columna boolean rechaza esa cadena vacía
        // (mismo bug ya encontrado en bd/restaurar-respaldo.php) — se
        // castea a texto 'true'/'false' antes de bindearlo.
        $campos[] = 'activo = :activo';
        $parametros['activo'] = $body['activo'] ? 'true' : 'false';
    }

    if ($campos === []) {
        obJsonResponse(false, null, 'Nada que actualizar', 400, 'DATOS_INCOMPLETOS');
    }

    $pdo->prepare('UPDATE comunidad SET ' . implode(', ', $campos) . ' WHERE id = :id')->execute($parametros);
    obJsonResponse(true, ['actualizado' => true], null, 200);
}

obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
