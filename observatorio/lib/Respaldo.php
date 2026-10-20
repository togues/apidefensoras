<?php

/**
 * Respaldos cifrados del panel — ver docs/decision-04-respaldos.md para
 * el porqué de cada decisión de diseño de este archivo.
 *
 * Sin pg_dump: el hosting de destino (docs/08-despliegue.md) es
 * compartido, sin terminal, con la base en un Postgres administrado
 * aparte — nada en esta API shellea nunca a un binario externo, y este
 * archivo no es la excepción. El respaldo se arma leyendo tabla por
 * tabla con la misma conexión PDO de siempre, se serializa a JSON, se
 * comprime y se cifra en PHP puro.
 *
 * Funciones sin dependencia de las constantes de config.php a
 * propósito (reciben PDO y la clave como parámetros): tanto
 * admin/respaldos.php (la API, con su propia conexión y su propio
 * RESPALDO_CLAVE) como bd/restaurar-respaldo.php (un script de línea
 * de comandos aparte, con su propia conexión que puede apuntar a una
 * base totalmente distinta) usan este mismo archivo.
 */

// Tablas que NUNCA van en un respaldo:
//   sesion_panel     — tokens de sesión efímeros, sin valor pasado un rato.
//   spatial_ref_sys  — catálogo de PostGIS (miles de filas de definiciones
//                      de proyección estándar), no es dato de la aplicación
//                      y se recrea solo con la extensión.
const OB_RESPALDO_TABLAS_EXCLUIDAS = ['sesion_panel', 'spatial_ref_sys'];

/**
 * Tablas reales de la aplicación, tal como existen en este momento —
 * nunca una lista escrita a mano, para que una tabla nueva de una
 * migración futura entre sola sin tener que acordarse de agregarla acá.
 *
 * @return string[]
 */
function obListarTablasRespaldo(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    );
    $todas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($todas, OB_RESPALDO_TABLAS_EXCLUIDAS));
}

/**
 * Mapa tabla => [columnas de geometría]. Filtrado a $tablas para no
 * arrastrar las tablas de referencia de postgis_tiger_geocoder (county,
 * state, edges...), que geometry_columns también lista pero que ni
 * siquiera están en information_schema.tables como tablas propias de
 * esta base.
 *
 * @param string[] $tablas
 * @return array<string, string[]>
 */
function obColumnasGeometriaPorTabla(PDO $pdo, array $tablas): array
{
    $stmt = $pdo->query(
        "SELECT f_table_name, f_geometry_column FROM geometry_columns WHERE f_table_schema = 'public'"
    );
    $mapa = [];
    foreach ($stmt->fetchAll() as $fila) {
        if (in_array($fila['f_table_name'], $tablas, true)) {
            $mapa[$fila['f_table_name']][] = $fila['f_geometry_column'];
        }
    }
    return $mapa;
}

/**
 * Todas las columnas de una tabla, en orden — para armar el SELECT
 * explícito (nunca `SELECT *` a ciegas: así se sabe exactamente cuáles
 * hay que envolver en encode(ST_AsEWKB(...),'hex')).
 *
 * @return string[]
 */
function obColumnasDeTabla(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = :tabla
         ORDER BY ordinal_position"
    );
    $stmt->execute(['tabla' => $tabla]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Columnas que forman la llave primaria de una tabla, en orden — NO
 * asumir que siempre es "id": `familia` usa `codigo`,
 * `contador_folio` y `dispositivo_estructura` tienen llave compuesta.
 * Se usa para el ON CONFLICT (...) de bd/restaurar-respaldo.php.
 *
 * @return string[]
 */
function obLlavePrimariaDeTabla(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        "SELECT kcu.column_name
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
         WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = :tabla
         ORDER BY kcu.ordinal_position"
    );
    $stmt->execute(['tabla' => $tabla]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Exporta el contenido de todas las tablas de respaldo a un arreglo
 * asociativo tabla => filas. Las columnas de geometría salen como
 * texto hexadecimal de su EWKB (el mismo formato binario que usa
 * pg_dump por debajo) vía encode(ST_AsEWKB(col),'hex') — se hace en el
 * propio Postgres, no convirtiendo un bytea a mano en PHP, para no
 * depender de cómo esta instalación tenga configurado bytea_output.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function obExportarDatosRespaldo(PDO $pdo): array
{
    $tablas = obListarTablasRespaldo($pdo);
    $geometrias = obColumnasGeometriaPorTabla($pdo, $tablas);

    $datos = [];
    foreach ($tablas as $tabla) {
        $columnas = obColumnasDeTabla($pdo, $tabla);
        $colsGeom = $geometrias[$tabla] ?? [];

        $expresiones = array_map(
            fn (string $col) => in_array($col, $colsGeom, true)
                ? sprintf('encode(ST_AsEWKB("%s"), \'hex\') AS "%s"', $col, $col)
                : sprintf('"%s"', $col),
            $columnas
        );

        $filas = $pdo->query(sprintf('SELECT %s FROM "%s"', implode(', ', $expresiones), $tabla))->fetchAll();
        $datos[$tabla] = $filas;
    }
    return $datos;
}

/**
 * Cifra $datos con AES-256-GCM (autenticado: si el archivo se altera,
 * descifrar falla en vez de devolver basura en silencio). La llave sale
 * de $clave vía PBKDF2 con una sal aleatoria por archivo — nunca se usa
 * $clave directo como llave de AES.
 *
 * Formato del blob: [sal 16B][iv 12B][tag 16B][texto cifrado].
 */
function obCifrarRespaldo(string $datos, string $clave): string
{
    $sal = random_bytes(16);
    $iv = random_bytes(12);
    $llave = hash_pbkdf2('sha256', $clave, $sal, 100000, 32, true);

    $tag = '';
    $cifrado = openssl_encrypt($datos, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cifrado === false) {
        throw new RuntimeException('Fallo al cifrar el respaldo');
    }

    return $sal . $iv . $tag . $cifrado;
}

/**
 * Reversa de obCifrarRespaldo(). Lanza si la clave es incorrecta o el
 * archivo fue alterado (openssl_decrypt devuelve false y GCM no
 * autentica).
 */
function obDescifrarRespaldo(string $blob, string $clave): string
{
    if (strlen($blob) < 44) {
        throw new RuntimeException('Archivo de respaldo demasiado corto para ser válido');
    }
    $sal = substr($blob, 0, 16);
    $iv = substr($blob, 16, 12);
    $tag = substr($blob, 28, 16);
    $cifrado = substr($blob, 44);
    $llave = hash_pbkdf2('sha256', $clave, $sal, 100000, 32, true);

    $datos = openssl_decrypt($cifrado, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $iv, $tag);
    if ($datos === false) {
        throw new RuntimeException('No se pudo descifrar — clave incorrecta o archivo alterado');
    }
    return $datos;
}

/**
 * Orden de tablas seguro para RESTAURAR (insertar primero lo que otras
 * tablas referencian). Se calcula de verdad con las llaves foráneas
 * reales de la base (ordenamiento topológico), no una lista escrita a
 * mano — una lista a mano queda vieja apenas una migración agrega una
 * relación nueva. Ejemplo real de por qué esto importa:
 * tipo_vulneracion.familia referencia familia(codigo) desde la
 * migración 003, aunque la tabla `familia` se creó bastante después
 * que `tipo_vulneracion` — el orden de creación NO es el orden correcto
 * para restaurar.
 *
 * @param string[] $tablas
 * @return string[]
 */
function obOrdenTablasParaRestaurar(PDO $pdo, array $tablas): array
{
    $stmt = $pdo->query(
        "SELECT tc.table_name AS dependiente, ccu.table_name AS referenciada
         FROM information_schema.table_constraints tc
         JOIN information_schema.constraint_column_usage ccu
           ON tc.constraint_name = ccu.constraint_name AND tc.table_schema = ccu.table_schema
         WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'"
    );

    // dependencias[tabla] = tablas que deben insertarse ANTES que tabla.
    $dependencias = array_fill_keys($tablas, []);
    foreach ($stmt->fetchAll() as $fila) {
        $dependiente = $fila['dependiente'];
        $referenciada = $fila['referenciada'];
        if ($dependiente === $referenciada) {
            continue; // auto-referencia (ninguna hoy, pero no debe trabar el orden si aparece una)
        }
        if (isset($dependencias[$dependiente]) && in_array($referenciada, $tablas, true)) {
            $dependencias[$dependiente][] = $referenciada;
        }
    }

    $orden = [];
    $visitando = [];
    $visitar = function (string $tabla) use (&$visitar, &$orden, &$visitando, $dependencias): void {
        if (in_array($tabla, $orden, true)) {
            return;
        }
        if (isset($visitando[$tabla])) {
            throw new RuntimeException("Ciclo de llaves foráneas detectado en $tabla — no se puede ordenar para restaurar");
        }
        $visitando[$tabla] = true;
        foreach ($dependencias[$tabla] ?? [] as $previa) {
            $visitar($previa);
        }
        unset($visitando[$tabla]);
        $orden[] = $tabla;
    };
    foreach ($tablas as $tabla) {
        $visitar($tabla);
    }
    return $orden;
}

/**
 * Genera un respaldo de verdad: exporta, comprime, cifra, guarda el
 * archivo en var/respaldos/, registra la fila en `respaldo`, y aplica
 * la retención configurada. Devuelve la fila insertada.
 *
 * @return array<string, mixed>
 */
function obGenerarRespaldo(PDO $pdo, string $metodo, ?int $usuarioId, string $directorioVar): array
{
    if (RESPALDO_CLAVE === '') {
        throw new RuntimeException('RESPALDO_CLAVE no está configurada — no se puede generar un respaldo sin cifrarlo');
    }

    $datos = obExportarDatosRespaldo($pdo);
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar el respaldo a JSON: ' . json_last_error_msg());
    }
    $comprimido = gzencode($json, 9);
    $cifrado = obCifrarRespaldo($comprimido, RESPALDO_CLAVE);

    $directorioRespaldos = rtrim($directorioVar, '/') . '/respaldos';
    if (!is_dir($directorioRespaldos) && !@mkdir($directorioRespaldos, 0700, true) && !is_dir($directorioRespaldos)) {
        throw new RuntimeException('No se pudo crear var/respaldos/');
    }

    $nombreArchivo = 'respaldo_' . date('Y-m-d_His') . '.enc';
    $rutaRelativa = 'respaldos/' . $nombreArchivo;
    $rutaAbsoluta = $directorioRespaldos . '/' . $nombreArchivo;
    if (file_put_contents($rutaAbsoluta, $cifrado) === false) {
        throw new RuntimeException('No se pudo escribir el archivo de respaldo');
    }
    chmod($rutaAbsoluta, 0600);

    $insertar = $pdo->prepare(
        'INSERT INTO respaldo (nombre_archivo, ruta_archivo, tamano_bytes, metodo, generado_por)
         VALUES (:nombre, :ruta, :tamano, :metodo, :usuario)
         RETURNING *'
    );
    $insertar->execute([
        'nombre' => $nombreArchivo,
        'ruta' => $rutaRelativa,
        'tamano' => strlen($cifrado),
        'metodo' => $metodo,
        'usuario' => $usuarioId,
    ]);
    $fila = $insertar->fetch();

    obAplicarRetencionRespaldos($pdo, $directorioVar);

    return $fila;
}

/**
 * Borra los respaldos más viejos —fila y archivo— hasta dejar como
 * máximo la cantidad configurada en respaldo_configuracion.retencion.
 */
function obAplicarRetencionRespaldos(PDO $pdo, string $directorioVar): void
{
    $retencion = (int) $pdo->query('SELECT retencion FROM respaldo_configuracion WHERE id = 1')->fetchColumn();
    if ($retencion <= 0) {
        return;
    }

    $sobrantes = $pdo->prepare(
        'SELECT id, ruta_archivo FROM respaldo ORDER BY generado_en DESC OFFSET :retencion'
    );
    $sobrantes->bindValue('retencion', $retencion, PDO::PARAM_INT);
    $sobrantes->execute();

    foreach ($sobrantes->fetchAll() as $fila) {
        $ruta = rtrim($directorioVar, '/') . '/' . $fila['ruta_archivo'];
        if (is_file($ruta)) {
            @unlink($ruta);
        }
        $borrar = $pdo->prepare('DELETE FROM respaldo WHERE id = :id');
        $borrar->execute(['id' => $fila['id']]);
    }
}
