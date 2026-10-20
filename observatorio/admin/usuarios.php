<?php

/**
 * GET/POST /api/v1/admin/usuarios, PATCH /api/v1/admin/usuarios/{id}
 * — no está en la tabla de contratos/api_v1.md sección 4. Apareció al
 * preguntarle Marco a REDNA cómo se registra el personal del panel: la
 * respuesta hoy era "a mano, por SQL", y eso no es sostenible una vez
 * que el proyecto pase de piloto a operación real. Documentar en
 * api_v1.md cuando se revise la sección 4 completa — mismo patrón que
 * GET /admin/mapa o GET /admin/analisis.
 *
 * Rol administrador para TODO este archivo: ver o crear cuentas de
 * panel es tan sensible como publicar al muro o cambiar configuración.
 *
 * Nunca se borra una cuenta — se desactiva (`activo = FALSE`). Un
 * usuario desactivado no puede iniciar sesión (login.php ya filtra por
 * `activo`), pero su nombre real sigue apareciendo en la bitácora de
 * los casos que gestionó — borrar la cuenta borraría ese rastro.
 */

require_once __DIR__ . '/../config.php';

obSetCorsHeaders();
obRequireAppSecret();
$usuarioPanel = obRequireSesionPanel();
obRequireRolAdministrador($usuarioPanel);

$pdo = obDb();
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($metodo === 'GET') {
    $usuarios = $pdo->query(
        "SELECT u.id, u.nombre, u.correo, r.nombre AS rol, u.activo, u.ultimo_acceso
         FROM usuario u JOIN rol r ON r.id = u.rol_id
         ORDER BY u.activo DESC, u.nombre"
    )->fetchAll();
    obJsonResponse(true, ['usuarios' => array_map(static function (array $u): array {
        $u['activo'] = (bool) $u['activo'];
        return $u;
    }, $usuarios)], null, 200);
}

if ($metodo === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $correo = trim((string) ($body['correo'] ?? ''));
    $clave = (string) ($body['clave'] ?? '');
    $rolNombre = (string) ($body['rol'] ?? '');

    if ($nombre === '' || $correo === '' || $clave === '' || $rolNombre === '') {
        obJsonResponse(false, null, 'Nombre, correo, clave y rol son obligatorios', 400, 'DATOS_INCOMPLETOS');
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        obJsonResponse(false, null, 'Correo inválido', 400, 'DATOS_INVALIDOS');
    }
    if (strlen($clave) < 8) {
        obJsonResponse(false, null, 'La clave debe tener al menos 8 caracteres', 400, 'CLAVE_DEBIL');
    }

    $rol = $pdo->prepare('SELECT id FROM rol WHERE nombre = :nombre');
    $rol->execute(['nombre' => $rolNombre]);
    $rolId = $rol->fetchColumn();
    if (!$rolId) {
        obJsonResponse(false, null, 'Rol inválido (administrador u operador)', 400, 'DATOS_INVALIDOS');
    }

    $existe = $pdo->prepare('SELECT id FROM usuario WHERE lower(correo) = lower(:correo)');
    $existe->execute(['correo' => $correo]);
    if ($existe->fetchColumn()) {
        obJsonResponse(false, null, 'Ya existe una cuenta con ese correo', 409, 'CORREO_DUPLICADO');
    }

    $crear = $pdo->prepare(
        'INSERT INTO usuario (nombre, correo, hash_clave, rol_id, activo) VALUES (:nombre, :correo, :hash, :rol_id, TRUE) RETURNING id'
    );
    $crear->execute([
        'nombre' => $nombre,
        'correo' => $correo,
        'hash' => password_hash($clave, PASSWORD_DEFAULT),
        'rol_id' => $rolId,
    ]);

    obJsonResponse(true, ['id' => (int) $crear->fetchColumn(), 'nombre' => $nombre, 'correo' => $correo, 'rol' => $rolNombre], null, 201);
}

if ($metodo === 'PATCH') {
    if ($id === null) {
        obJsonResponse(false, null, 'Falta el id de la cuenta', 400, 'DATOS_INCOMPLETOS');
    }
    $existe = $pdo->prepare('SELECT id FROM usuario WHERE id = :id');
    $existe->execute(['id' => $id]);
    if (!$existe->fetchColumn()) {
        obJsonResponse(false, null, 'Cuenta no encontrada', 404, 'USUARIO_NO_ENCONTRADO');
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $cambios = [];
    $parametros = ['id' => $id];

    if (array_key_exists('activo', $body)) {
        $cambios[] = 'activo = :activo';
        $parametros['activo'] = filter_var($body['activo'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        // Desactivar revoca cualquier sesión abierta de esa cuenta — de
        // lo contrario, alguien ya conectado seguiría trabajando hasta
        // que la sesión caducara sola por inactividad (hasta 8 horas).
        if ($parametros['activo'] === 'false') {
            $pdo->prepare('DELETE FROM sesion_panel WHERE usuario_id = :id')->execute(['id' => $id]);
        }
    }
    if (!empty($body['rol'])) {
        $rol = $pdo->prepare('SELECT id FROM rol WHERE nombre = :nombre');
        $rol->execute(['nombre' => $body['rol']]);
        $rolId = $rol->fetchColumn();
        if (!$rolId) {
            obJsonResponse(false, null, 'Rol inválido', 400, 'DATOS_INVALIDOS');
        }
        $cambios[] = 'rol_id = :rol_id';
        $parametros['rol_id'] = $rolId;
    }
    if (!empty($body['clave'])) {
        if (strlen($body['clave']) < 8) {
            obJsonResponse(false, null, 'La clave debe tener al menos 8 caracteres', 400, 'CLAVE_DEBIL');
        }
        $cambios[] = 'hash_clave = :hash_clave';
        $parametros['hash_clave'] = password_hash($body['clave'], PASSWORD_DEFAULT);
    }

    if (!$cambios) {
        obJsonResponse(false, null, 'Nada para actualizar', 400, 'DATOS_INCOMPLETOS');
    }

    $stmt = $pdo->prepare('UPDATE usuario SET ' . implode(', ', $cambios) . ' WHERE id = :id');
    $stmt->execute($parametros);

    obJsonResponse(true, ['actualizado' => true], null, 200);
}

obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
