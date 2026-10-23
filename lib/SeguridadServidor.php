<?php

/**
 * Chequeos de configuración del servidor para "Salud" del panel, solo
 * rol administrador (admin/salud.php). Pedido de Marco, 23 de octubre
 * de 2026, a raíz de PHP2Shell (CVE-2026-109618 y CVE-2026-109619).
 *
 * Avisan, no bloquean: una API que se niega a arrancar por un secreto
 * débil deja sin servicio a quien está reportando. Nunca devuelven el
 * valor de un secreto, solo si está bien.
 *
 * @return list<array{clave:string, nivel:string, titulo:string, texto:string}>
 */
function obChequeosSeguridadServidor(): array
{
    $chequeos = [];
    $agregar = function (string $clave, string $nivel, string $titulo, string $texto) use (&$chequeos): void {
        $chequeos[] = ['clave' => $clave, 'nivel' => $nivel, 'titulo' => $titulo, 'texto' => $texto];
    };
    $esProduccion = API_ENV === 'production';

    // Primera versión de cada rama con el parche de PHP2Shell (21 de
    // octubre de 2026). Las ramas anteriores a 8.2 ya no reciben parches.
    $parchadas = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
    $rama = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    if (version_compare($rama, '8.2', '<')) {
        $agregar('php', 'mal', 'PHP ' . PHP_VERSION, 'Esta versión ya no recibe parches de seguridad y está expuesta a PHP2Shell. Cámbiela a 8.3 u 8.4 en cPanel.');
    } elseif (isset($parchadas[$rama]) && version_compare(PHP_VERSION, $parchadas[$rama], '<')) {
        $agregar('php', 'mal', 'PHP ' . PHP_VERSION, 'Le falta el parche de PHP2Shell: necesita ' . $parchadas[$rama] . ' o más nueva.');
    } else {
        $agregar('php', 'bien', 'PHP ' . PHP_VERSION, 'Versión con los parches de seguridad conocidos.');
    }

    // El secreto de la aplicación viaja dentro de app, panel y muro: si
    // se cambia, se cambia en los cuatro a la vez.
    $agregar(...obChequeoSecreto('secreto_app', 'Secreto de la aplicación', API_SECRET, 'la API acepta peticiones de cualquier programa'));
    $agregar(...obChequeoSecreto('clave_respaldos', 'Clave de los respaldos', RESPALDO_CLAVE, 'no se pueden generar respaldos cifrados'));

    if (API_SECRET_ENFORCE === 'strict') {
        $agregar('exigir_secreto', 'bien', 'Exigir el secreto', 'Se exige en todas las peticiones.');
    } else {
        $agregar('exigir_secreto', 'mal', 'Exigir el secreto', 'Está en «' . API_SECRET_ENFORCE . '». Póngalo en strict en el .env.');
    }

    if (!$esProduccion) {
        $agregar('entorno', 'aviso', 'Entorno', 'El .env dice «' . API_ENV . '». En el servidor publicado debe decir production.');
    } else {
        $agregar('entorno', 'bien', 'Entorno', 'Marcado como producción.');
    }

    if (API_DEBUG && $esProduccion) {
        $agregar('depuracion', 'aviso', 'Modo depuración', 'Está encendido en producción. Apáguelo en el .env (API_DEBUG=false).');
    }

    $origenes = array_map('trim', explode(',', CORS_ALLOWED_ORIGINS));
    $abiertos = array_filter($origenes, fn ($o) => $o === '*' || str_contains($o, 'localhost') || str_contains($o, '127.0.0.1'));
    if ($esProduccion && $abiertos) {
        $agregar('origenes', 'aviso', 'Orígenes permitidos', 'Incluye «*» o direcciones locales. En producción deben ser solo las del sitio.');
    } else {
        $agregar('origenes', 'bien', 'Orígenes permitidos', 'Solo los sitios del observatorio.');
    }

    $candado = __DIR__ . '/../var/.htaccess';
    if (is_readable($candado) && str_contains((string) file_get_contents($candado), 'Require all denied')) {
        $agregar('carpeta_var', 'bien', 'Carpeta de fotos y respaldos', 'Cerrada: no se puede pedir por internet.');
    } else {
        $agregar('carpeta_var', 'mal', 'Carpeta de fotos y respaldos', 'Falta var/.htaccess con «Require all denied». Ver docs/08-despliegue.md.');
    }

    $permisos = @fileperms(OB_ENV_FILE);
    if ($permisos !== false && ($permisos & 0o007)) {
        $agregar('permisos_env', 'aviso', 'Archivo .env', 'Cualquier usuario del servidor puede leerlo. En cPanel déjelo en 600.');
    } else {
        $agregar('permisos_env', 'bien', 'Archivo .env', 'Solo lo lee la cuenta del sitio.');
    }

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $agregar('https', 'bien', 'Conexión segura', 'La API se sirve por HTTPS.');
    } elseif ($esProduccion) {
        $agregar('https', 'mal', 'Conexión segura', 'La API se está sirviendo sin HTTPS.');
    }

    return $chequeos;
}

/** @return array{0:string,1:string,2:string,3:string} */
function obChequeoSecreto(string $clave, string $titulo, string $valor, string $siFalta): array
{
    if ($valor === '') {
        return [$clave, 'mal', $titulo, 'No está puesta: ' . $siFalta . '.'];
    }
    if (str_starts_with($valor, 'cambiar_') || strlen($valor) < 32) {
        return [$clave, 'aviso', $titulo, 'Es la de ejemplo o es corta. Genere una nueva con openssl rand -hex 32.'];
    }
    return [$clave, 'bien', $titulo, 'Puesta y de largo suficiente.'];
}
