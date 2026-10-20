<?php

/**
 * Conexión PDO a PostgreSQL. Una sola instancia por petición.
 * No existe en TerroSenser (es "sin base de datos en Fase 3 lite"); esto
 * es nuevo para el Observatorio.
 */

function obDb(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // DB_SSLMODE: las bases administradas (DigitalOcean, RDS, etc.)
    // rechazan la conexión sin SSL. En el Postgres del docker-compose de
    // desarrollo no hace falta —"prefer" no exige nada si el servidor no
    // ofrece SSL—, así que un DSN sin esto nunca se había notado hasta
    // que hizo falta apuntar a un servidor administrado de verdad.
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSLMODE
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        obApiLog('No se pudo conectar a la base de datos', ['mensaje' => $e->getMessage()]);
        obJsonResponse(false, null, 'Servicio no disponible', 503, 'ERROR_INTERNO');
    }

    return $pdo;
}

/**
 * Envuelve una operación en una transacción. Si $fn lanza, revierte y
 * relanza — quien llama decide cómo convertir eso en una respuesta HTTP.
 *
 * @template T
 * @param callable(PDO):T $fn
 * @return T
 */
function obTransaccion(callable $fn)
{
    $pdo = obDb();
    $pdo->beginTransaction();
    try {
        $resultado = $fn($pdo);
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
