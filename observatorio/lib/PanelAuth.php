<?php

/**
 * Sesión de panel — nivel 3 de autenticación de api_v1.md, sección 2
 * ("Authorization: Bearer <token>", personal de REDNA). Reemplaza a
 * lib/AdminAuth.php y su ADMIN_DEV_KEY, que era un candado provisional
 * mientras no existía esto — ver la nota larga en
 * bd/migraciones/006_sesion_panel.sql.
 *
 * El token va hasheado en sesion_panel.token_hash (sha256, igual que
 * dispositivo.token_dispositivo): es alta entropía generada acá mismo,
 * no una contraseña humana — eso es lo que sí necesita password_hash(),
 * y ya lo usa usuario.hash_clave (ver auth/login.php).
 */

const SESION_PANEL_INACTIVIDAD_MINUTOS = 480; // 8 horas sin uso

function obHashTokenSesion(string $token): string
{
    return hash('sha256', $token);
}

function obCrearSesionPanel(int $usuarioId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo = obDb();
    $stmt = $pdo->prepare('INSERT INTO sesion_panel (usuario_id, token_hash) VALUES (:uid, :hash)');
    $stmt->execute(['uid' => $usuarioId, 'hash' => obHashTokenSesion($token)]);
    return $token;
}

function obTokenSesionDeCabecera(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

/**
 * Exige una sesión de panel válida y no inactiva. Devuelve el usuario
 * autenticado (con su rol) o corta la petición con 401.
 *
 * @return array{id:int, nombre:string, correo:string, rol:string, sesion_id:int}
 */
function obRequireSesionPanel(): array
{
    $token = obTokenSesionDeCabecera();
    if ($token === '') {
        obJsonResponse(false, null, 'Sesión requerida', 401, 'SESION_REQUERIDA');
    }

    $pdo = obDb();
    $stmt = $pdo->prepare(
        "SELECT sp.id AS sesion_id, u.id, u.nombre, u.correo, r.nombre AS rol
         FROM sesion_panel sp
             JOIN usuario u ON u.id = sp.usuario_id
             JOIN rol r ON r.id = u.rol_id
         WHERE sp.token_hash = :hash
             AND sp.ultima_actividad > NOW() - make_interval(mins => :minutos)
             AND u.activo = TRUE"
    );
    $stmt->execute(['hash' => obHashTokenSesion($token), 'minutos' => SESION_PANEL_INACTIVIDAD_MINUTOS]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        obJsonResponse(false, null, 'Sesión inválida o expirada', 401, 'SESION_INVALIDA');
    }

    // Cada petición autenticada renueva la ventana de inactividad —
    // "caduca por inactividad", no a una hora fija.
    $renovar = $pdo->prepare('UPDATE sesion_panel SET ultima_actividad = NOW() WHERE id = :id');
    $renovar->execute(['id' => $usuario['sesion_id']]);

    return $usuario;
}

/**
 * @param array{rol:string} $usuario lo que devuelve obRequireSesionPanel()
 */
function obRequireRolAdministrador(array $usuario): void
{
    if ($usuario['rol'] !== 'administrador') {
        obJsonResponse(false, null, 'Esta acción requiere rol administrador', 403, 'ROL_INSUFICIENTE');
    }
}
