<?php

/**
 * Filtros de reportes compartidos por Casos (admin/casos.php) y el
 * tablero de Análisis (admin/analisis.php) — mismos nombres de
 * parámetro, misma semántica, una sola implementación. Así "ver estos
 * casos" desde un gráfico del tablero muestra exactamente los mismos
 * reportes que contó el gráfico.
 *
 * Todas las condiciones se escriben sobre las columnas de
 * v_reporte_territorio, con el alias que se pase (por defecto ninguno).
 *
 * FECHAS — `desde`/`hasta` llegan como día (AAAA-MM-DD, opcionalmente
 * con hora que se ignora) y se interpretan como días de El Salvador
 * (API_TIMEZONE), con su offset explícito. Antes se pasaban tal cual a
 * Postgres, cuya sesión está en UTC: "hasta el 20" cortaba a las 6 de
 * la tarde del 20 en El Salvador, y "desde el 20" incluía casos de las
 * 6 de la tarde del 19. Mismo bug que ya se encontró con la vigencia
 * de los códigos (config.js, inicioDeDiaSV), 2026-10-20.
 */

const OB_FAMILIAS = ['persona', 'territorio', 'gestion', 'logro'];
const OB_ESTADOS = ['recibido', 'en_investigacion', 'cerrado', 'descartado'];
const OB_PRIORIDADES = ['alta', 'media', 'baja'];

/** AAAA-MM-DD (lo demás se ignora) → límite del día en hora de El Salvador, o null si no es una fecha válida. */
function obLimiteDeDia(string $valor, bool $finDelDia): ?string
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $valor, $m)) {
        return null;
    }
    $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], new DateTimeZone(API_TIMEZONE));
    if ($dia === false || $dia->format('Y-m-d') !== $m[1]) {
        return null;
    }
    return ($finDelDia ? $dia->setTime(23, 59, 59, 999999) : $dia)->format('Y-m-d\TH:i:s.uP');
}

/**
 * @return array{condiciones: list<string>, parametros: array<string, scalar>}
 */
function obFiltrosReporte(array $entrada, string $alias = ''): array
{
    $col = static fn (string $c) => $alias === '' ? $c : $alias . '.' . $c;
    $condiciones = [];
    $parametros = [];

    foreach (['comunidad_id', 'departamento_id', 'canton_id', 'tipo_vulneracion_id'] as $campo) {
        if (!empty($entrada[$campo]) && (int) $entrada[$campo] > 0) {
            $condiciones[] = $col($campo) . " = :$campo";
            $parametros[$campo] = (int) $entrada[$campo];
        }
    }
    if (!empty($entrada['familia']) && in_array($entrada['familia'], OB_FAMILIAS, true)) {
        $condiciones[] = $col('familia') . ' = :familia';
        $parametros['familia'] = $entrada['familia'];
    }
    if (!empty($entrada['estado']) && in_array($entrada['estado'], OB_ESTADOS, true)) {
        $condiciones[] = $col('estado') . ' = :estado';
        $parametros['estado'] = $entrada['estado'];
    }
    if (!empty($entrada['prioridad']) && in_array($entrada['prioridad'], OB_PRIORIDADES, true)) {
        $condiciones[] = $col('prioridad') . ' = :prioridad';
        $parametros['prioridad'] = $entrada['prioridad'];
    }
    if (isset($entrada['marcado_anomalo']) && $entrada['marcado_anomalo'] !== '') {
        // Texto 'true'/'false', no bool de PHP: PDO nativo contra pgsql
        // manda false como '' y la columna boolean lo rechaza.
        $condiciones[] = $col('marcado_anomalo') . ' = :marcado_anomalo';
        $parametros['marcado_anomalo'] = filter_var($entrada['marcado_anomalo'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
    if (!empty($entrada['desde']) && ($desde = obLimiteDeDia((string) $entrada['desde'], false)) !== null) {
        $condiciones[] = $col('fecha_registro') . ' >= :desde';
        $parametros['desde'] = $desde;
    }
    if (!empty($entrada['hasta']) && ($hasta = obLimiteDeDia((string) $entrada['hasta'], true)) !== null) {
        $condiciones[] = $col('fecha_registro') . ' <= :hasta';
        $parametros['hasta'] = $hasta;
    }

    return ['condiciones' => $condiciones, 'parametros' => $parametros];
}
