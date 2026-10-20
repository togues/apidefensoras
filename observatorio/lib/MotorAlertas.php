<?php

/**
 * Motor de alertas — evalúa regla_alerta tras cada POST /reportes/sync,
 * como manda api_v1.md sección 3.5. Ver docs/decision-03-alertas.md: "el
 * motor de la v1 es una cosa sola y simple: cuenta reportes por regla y
 * territorio dentro de una ventana, y cuando pasa el umbral, avisa."
 *
 * La migración 003 le dio a `alerta` ciclo de vida y el índice único
 * `uq_alerta_abierta` para que la misma regla no dispare dos veces sobre
 * el mismo territorio y la misma ventana, pero la migración 003 deja
 * explícito que NO programa la lógica ("la logica que decide actualizar
 * en vez de insertar se programa en el API; esta migracion solo le da la
 * estructura"). Eso es lo que hace este archivo.
 *
 * CÓMO SE DECIDE "MISMA VENTANA"
 *   Mientras exista una alerta 'abierta' para (regla, cantón), CUALQUIER
 *   sincronización que vuelva a cruzar el umbral la actualiza —sube
 *   conteo_actual— en vez de crear otra. `ventana_inicio` queda fija en
 *   el momento en que se abrió y no se recalcula ni se compara: es
 *   registro histórico de cuándo empezó el episodio, igual que
 *   conteo_detectado. La única forma de que se abra una alerta NUEVA
 *   para el mismo (regla, cantón) es que una persona cierre o atienda la
 *   anterior desde el panel (`PATCH /admin/alertas/{id}`, todavía sin
 *   programar) — así lo dice decision-03-alertas.md: "la alerta va al
 *   panel... y el panel gana algo que hoy no tiene: poder decir que una
 *   alerta ya fue atendida." No hay expiración automática por tiempo.
 *
 * LÍMITE CONOCIDO, Y QUEDA ANOTADO A PROPÓSITO
 *   `uq_alerta_abierta` protege contra reinsertar la ventana exacta,
 *   pero acá `ventana_inicio` se calcula como NOW() - ventana_horas en
 *   cada evaluación: dos sincronizaciones concurrentes que abren la
 *   misma alerta por primera vez casi con certeza calculan
 *   `ventana_inicio` con microsegundos de diferencia, así que el índice
 *   no las va a atrapar. En la escala de esta plataforma —sincronización
 *   por lotes, no miles de dispositivos por segundo— el riesgo real es
 *   bajo, pero si algún día hace falta cerrarlo del todo, la solución es
 *   cuantizar ventana_inicio a un bucle fijo (por ejemplo, redondear a
 *   la hora), no tocar el índice.
 */

final class ObMotorAlertas
{
    /**
     * @param list<int> $cantones cantones tocados por este lote de sync
     */
    public static function evaluar(PDO $pdo, array $cantones): void
    {
        $cantones = array_values(array_unique(array_filter($cantones, static fn($c) => $c !== null)));
        if ($cantones === []) {
            return;
        }

        $reglas = $pdo->query(
            'SELECT id, familia, umbral, ventana_horas, canton_id
             FROM regla_alerta
             WHERE activo = TRUE'
        )->fetchAll();

        foreach ($reglas as $regla) {
            // canton_id en regla_alerta acota la regla a un solo cantón
            // (comentario de la 001: "Si los tres ámbitos son NULL,
            // recibe alertas de todo el país" es de actor_clave, no de
            // regla_alerta — acá NULL en canton_id significa "todos").
            $cantonesAEvaluar = $regla['canton_id'] !== null
                ? (in_array((int) $regla['canton_id'], $cantones, true) ? [(int) $regla['canton_id']] : [])
                : $cantones;

            foreach ($cantonesAEvaluar as $cantonId) {
                self::evaluarReglaEnCanton($pdo, $regla, $cantonId);
            }
        }
    }

    /**
     * @param array<string, mixed> $regla
     */
    private static function evaluarReglaEnCanton(PDO $pdo, array $regla, int $cantonId): void
    {
        $condicionFamilia = '';
        $parametros = ['canton_id' => $cantonId, 'horas' => (int) $regla['ventana_horas']];
        if ($regla['familia'] !== null) {
            $condicionFamilia = ' AND tv.familia = :familia';
            $parametros['familia'] = $regla['familia'];
        }

        $conteo = self::ultimoConteo($pdo, $condicionFamilia, $parametros);

        if ($conteo < (int) $regla['umbral']) {
            return;
        }

        $abierta = $pdo->prepare(
            "SELECT id FROM alerta
             WHERE regla_alerta_id = :regla_id AND canton_id = :canton_id AND estado = 'abierta'
             LIMIT 1"
        );
        $abierta->execute(['regla_id' => $regla['id'], 'canton_id' => $cantonId]);
        $alertaAbierta = $abierta->fetch();

        if ($alertaAbierta !== false) {
            $pdo->prepare(
                'UPDATE alerta SET conteo_actual = GREATEST(conteo_actual, :conteo), actualizada_en = NOW()
                 WHERE id = :id'
            )->execute(['conteo' => $conteo, 'id' => $alertaAbierta['id']]);
            return;
        }

        try {
            // Los dos :conteo* van por separado: PDO nativo con pgsql no
            // soporta repetir el mismo placeholder nombrado con confianza.
            $pdo->prepare(
                "INSERT INTO alerta (regla_alerta_id, canton_id, conteo_detectado, conteo_actual, ventana_inicio, estado)
                 VALUES (:regla_id, :canton_id, :conteo_detectado, :conteo_actual, NOW() - (:horas || ' hours')::interval, 'abierta')"
            )->execute([
                'regla_id' => $regla['id'],
                'canton_id' => $cantonId,
                'conteo_detectado' => $conteo,
                'conteo_actual' => $conteo,
                'horas' => (int) $regla['ventana_horas'],
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Carrera real contra otra sincronización concurrente: alguien
            // más acaba de abrir la misma alerta. Se suma el conteo en vez
            // de perder la señal.
            $pdo->prepare(
                "UPDATE alerta SET conteo_actual = GREATEST(conteo_actual, :conteo), actualizada_en = NOW()
                 WHERE regla_alerta_id = :regla_id AND canton_id = :canton_id AND estado = 'abierta'"
            )->execute(['conteo' => $conteo, 'regla_id' => $regla['id'], 'canton_id' => $cantonId]);
        }
    }

    /**
     * @param array<string, mixed> $parametros
     */
    private static function ultimoConteo(PDO $pdo, string $condicionFamilia, array $parametros): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reporte r
             JOIN tipo_vulneracion tv ON tv.id = r.tipo_vulneracion_id
             WHERE r.canton_id = :canton_id
               AND r.fecha_registro >= NOW() - (:horas || ' hours')::interval
               $condicionFamilia"
        );
        $stmt->execute($parametros);
        return (int) $stmt->fetchColumn();
    }
}
