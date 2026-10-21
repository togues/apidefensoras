<?php

/**
 * Asignación de la organización responsable del seguimiento de un caso
 * (migración 011) — la usan la ficha (admin/casos.php, PATCH) y la
 * asignación en lote (admin/casos-asignar.php), para que las dos dejen
 * exactamente la misma huella en la bitácora.
 *
 * Es una decisión de REDNA sobre el caso, nunca la estructura
 * organizativa de quien reporta (regla 2) — ver
 * docs/decision-07-informe-seguimiento.md.
 */

/**
 * Valida el id recibido: null/'' = quitar la asignación. Devuelve
 * [id|null, nombre|null], o null si el id no es una organización activa.
 */
function obOrganizacionSeguimiento(PDO $pdo, $valor): ?array
{
    if ($valor === null || $valor === '') {
        return [null, null];
    }
    $stmt = $pdo->prepare('SELECT id, nombre FROM estructura_organizativa WHERE id = :id AND activo = TRUE');
    $stmt->execute(['id' => (int) $valor]);
    $fila = $stmt->fetch();
    return $fila ? [(int) $fila['id'], $fila['nombre']] : null;
}

/**
 * Asigna (o quita) dentro de la transacción en curso. Devuelve false si
 * el caso ya tenía esa misma asignación — no deja una nota repetida.
 */
function obAsignarSeguimiento(PDO $pdo, int $reporteId, ?int $organizacionId, ?string $nombre, int $usuarioId): bool
{
    $actual = $pdo->prepare('SELECT organizacion_seguimiento_id FROM reporte WHERE id = :id FOR UPDATE');
    $actual->execute(['id' => $reporteId]);
    $fila = $actual->fetch();
    if (!$fila || ($fila['organizacion_seguimiento_id'] === null ? null : (int) $fila['organizacion_seguimiento_id']) === $organizacionId) {
        return false;
    }

    $pdo->prepare('UPDATE reporte SET organizacion_seguimiento_id = :org WHERE id = :id')
        ->execute(['org' => $organizacionId, 'id' => $reporteId]);

    // Sin estado_anterior/estado_nuevo: no es un cambio de estado, y la
    // bitácora del panel muestra el estado cuando la entrada lo trae.
    $pdo->prepare(
        'INSERT INTO seguimiento_caso (reporte_id, usuario_id, nota, automatica)
         VALUES (:reporte_id, :usuario_id, :nota, TRUE)'
    )->execute([
        'reporte_id' => $reporteId,
        'usuario_id' => $usuarioId,
        'nota' => $organizacionId === null
            ? 'Quitó la asignación de seguimiento.'
            : 'Asignó el seguimiento a ' . $nombre . '.',
    ]);
    return true;
}
