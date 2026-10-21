<?php

/**
 * POST /api/v1/reportes/sync — api_v1.md, sección 3.5.
 * "Es el endpoint más crítico del sistema." Envío en lote de la cola
 * local, idempotente por uuid_local, resultado por elemento — nunca un
 * error global que obligue a reenviar todo el lote.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispositivo.php';
require_once __DIR__ . '/../lib/FiltroContenido.php';
require_once __DIR__ . '/../lib/MotorAlertas.php';

obSetCorsHeaders();
obRequireAppSecret();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    obJsonResponse(false, null, 'Método no permitido', 405, 'METHOD_NOT_ALLOWED');
}

$dispositivo = obAutenticarDispositivo();

// api_v1.md, sección 2: 60 por hora POR DISPOSITIVO, no por IP.
obEnforceRateLimitPorClave('reportes-sync:dispositivo:' . $dispositivo['id'], RATE_LIMIT_SYNC_MAX, RATE_LIMIT_SYNC_WINDOW);

$body = obLeerCuerpoJson();
if (!isset($body['reportes']) || !is_array($body['reportes'])) {
    obDatosInvalidos(['reportes' => 'Debe ser una lista']);
}

$precisionesValidas = ['exacta', 'dia', 'semana', 'mes', 'desconocida'];
$pdo = obDb();
$resultados = [];
$cantonesTocados = [];

foreach ($body['reportes'] as $item) {
    if (!is_array($item)) {
        $resultados[] = ['uuid_local' => null, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    $uuidLocal = trim((string) ($item['uuid_local'] ?? ''));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuidLocal)) {
        $resultados[] = ['uuid_local' => $uuidLocal !== '' ? $uuidLocal : null, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    // Idempotencia primero: api_v1.md dice explícitamente que ya_recibido
    // no es error y el cliente saca el reporte de la cola igual, así que
    // esto se resuelve antes de validar nada más del cuerpo.
    $existente = $pdo->prepare('SELECT folio FROM reporte WHERE uuid_local = :uuid');
    $existente->execute(['uuid' => $uuidLocal]);
    $folioExistente = $existente->fetchColumn();
    if ($folioExistente !== false) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'ya_recibido', 'folio' => $folioExistente];
        continue;
    }

    $tipoVulneracionId = isset($item['tipo_vulneracion_id']) ? (int) $item['tipo_vulneracion_id'] : 0;
    $tipoStmt = $pdo->prepare(
        'SELECT id, familia, permite_entidad, alerta_inmediata FROM tipo_vulneracion WHERE id = :id AND activo = TRUE'
    );
    $tipoStmt->execute(['id' => $tipoVulneracionId]);
    $tipo = $tipoStmt->fetch();
    if ($tipo === false) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    $entidadMencionada = isset($item['entidad_mencionada']) && $item['entidad_mencionada'] !== null
        ? trim((string) $item['entidad_mencionada'])
        : null;
    if ($entidadMencionada === '') {
        $entidadMencionada = null;
    }
    if ($entidadMencionada !== null && !$tipo['permite_entidad']) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'ENTIDAD_NO_PERMITIDA'];
        continue;
    }

    $precisionFecha = isset($item['precision_fecha']) ? (string) $item['precision_fecha'] : null;
    if ($precisionFecha !== null && !in_array($precisionFecha, $precisionesValidas, true)) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    $lat = isset($item['lat']) ? (float) $item['lat'] : null;
    $lon = isset($item['lon']) ? (float) $item['lon'] : null;
    if (($lat !== null) !== ($lon !== null)) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }
    if ($lat !== null && ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180)) {
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    // Departamento donde ocurrió (migración 012): lo elige la persona en
    // "¿Dónde fue?". comunidad_id se sigue aceptando solo para las apps
    // instaladas antes de la 012.
    $departamentoId = null;
    if (isset($item['departamento_id']) && $item['departamento_id'] !== null) {
        $departamentoId = (int) $item['departamento_id'];
        $existeDepartamento = $pdo->prepare('SELECT 1 FROM departamento WHERE id = :id');
        $existeDepartamento->execute(['id' => $departamentoId]);
        if ($existeDepartamento->fetchColumn() === false) {
            $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
            continue;
        }
    }

    $comunidadId = null;
    if (isset($item['comunidad_id']) && $item['comunidad_id'] !== null) {
        $comunidadId = (int) $item['comunidad_id'];
        $existeComunidad = $pdo->prepare('SELECT 1 FROM comunidad WHERE id = :id AND activo = TRUE');
        $existeComunidad->execute(['id' => $comunidadId]);
        if ($existeComunidad->fetchColumn() === false) {
            $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
            continue;
        }
    }

    $descripcion = isset($item['descripcion']) && $item['descripcion'] !== null
        ? trim((string) $item['descripcion'])
        : null;
    if ($descripcion === '') {
        $descripcion = null;
    }
    $referenciaLugar = isset($item['referencia_lugar']) && $item['referencia_lugar'] !== null
        ? trim((string) $item['referencia_lugar'])
        : null;
    if ($referenciaLugar === '') {
        $referenciaLugar = null;
    }

    $fechaIncidente = isset($item['fecha_incidente']) && $item['fecha_incidente'] !== null
        ? (string) $item['fecha_incidente']
        : null;

    // reporte.prioridad: SIEMPRE la decide el servidor a partir del
    // catálogo, nunca el cliente (api_v1.md, sección 3.5).
    $prioridad = $tipo['alerta_inmediata'] ? 'alta' : 'media';

    // Detectores que se conocen ANTES de insertar (ver
    // contratos/api_v1.md, nota sobre marcado_anomalo). Los que dependen
    // del resultado de fn_resolver_territorio (fuera_de_territorio) se
    // resuelven después del INSERT, con lo que RETURNING entregue.
    $anomaliasPrevias = [];
    if (ObFiltroContenido::tieneCaracteresInvalidos($descripcion)
        || ObFiltroContenido::tieneCaracteresInvalidos($entidadMencionada)
        || ObFiltroContenido::tieneCaracteresInvalidos($referenciaLugar)
    ) {
        $anomaliasPrevias[] = ['tipo' => 'caracteres_invalidos', 'marca' => true];
    }

    $revisionContenido = ObFiltroContenido::revisar($descripcion);
    if ($revisionContenido['resultado'] !== 'limpio') {
        $anomaliasPrevias[] = [
            'tipo' => 'filtro_contenido',
            'marca' => $revisionContenido['resultado'] === 'bloqueo',
        ];
    }

    $marcadoAnomaloInicial = false;
    foreach ($anomaliasPrevias as $a) {
        if ($a['marca']) {
            $marcadoAnomaloInicial = true;
            break;
        }
    }

    try {
        $insertar = $pdo->prepare(
            'INSERT INTO reporte (
                uuid_local, dispositivo_id, tipo_vulneracion_id, descripcion,
                entidad_mencionada, referencia_lugar, geom, comunidad_id, departamento_id,
                fecha_incidente, precision_fecha, prioridad, marcado_anomalo
            ) VALUES (
                :uuid_local, :dispositivo_id, :tipo_vulneracion_id, :descripcion,
                :entidad_mencionada, :referencia_lugar,
                CASE WHEN :tiene_punto THEN ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) ELSE NULL END,
                :comunidad_id, :departamento_id, :fecha_incidente, :precision_fecha, :prioridad, :marcado_anomalo
            )
            RETURNING id, folio, canton_id, departamento_id'
        );
        // PDO nativo (EMULATE_PREPARES=false) con pgsql no soporta repetir
        // el mismo placeholder nombrado en más de un lugar de la consulta
        // de forma confiable, así que ninguno se reutiliza. Y los booleanos
        // van como texto 'true'/'false': un PHP `false` pasado tal cual se
        // convierte en cadena vacía al bindear, que Postgres no acepta como
        // boolean ("invalid input syntax for type boolean").
        $insertar->execute([
            'uuid_local' => $uuidLocal,
            'dispositivo_id' => $dispositivo['id'],
            'tipo_vulneracion_id' => $tipo['id'],
            'descripcion' => $descripcion,
            'entidad_mencionada' => $entidadMencionada,
            'referencia_lugar' => $referenciaLugar,
            'tiene_punto' => $lat !== null ? 'true' : 'false',
            'lat' => $lat,
            'lon' => $lon,
            'comunidad_id' => $comunidadId,
            'departamento_id' => $departamentoId,
            'fecha_incidente' => $fechaIncidente,
            'precision_fecha' => $precisionFecha,
            'prioridad' => $prioridad,
            'marcado_anomalo' => $marcadoAnomaloInicial ? 'true' : 'false',
        ]);
        $fila = $insertar->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            // Carrera: otro dispositivo (o un reintento simultáneo) insertó
            // el mismo uuid_local entre nuestro SELECT y este INSERT.
            $otra = $pdo->prepare('SELECT folio FROM reporte WHERE uuid_local = :uuid');
            $otra->execute(['uuid' => $uuidLocal]);
            $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'ya_recibido', 'folio' => $otra->fetchColumn()];
            continue;
        }
        obApiLog('Fallo al insertar reporte', ['uuid_local' => $uuidLocal, 'mensaje' => $e->getMessage()]);
        $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'rechazado', 'code' => 'DATOS_INVALIDOS'];
        continue;
    }

    $reporteId = (int) $fila['id'];
    $cantonId = $fila['canton_id'] !== null ? (int) $fila['canton_id'] : null;

    foreach ($anomaliasPrevias as $a) {
        $pdo->prepare(
            'INSERT INTO log_anomalia (reporte_id, tipo_anomalia) VALUES (:reporte_id, :tipo)'
        )->execute(['reporte_id' => $reporteId, 'tipo' => $a['tipo']]);
    }

    // Desde la migración 012 el lugar del reporte es su departamento: un
    // reporte sin cantón (sin punto en el mapa) es lo normal. Solo se
    // marca el que llegó sin departamento, que no se puede ubicar.
    if ($fila['departamento_id'] === null) {
        $pdo->prepare(
            'INSERT INTO log_anomalia (reporte_id, tipo_anomalia) VALUES (:reporte_id, :tipo)'
        )->execute(['reporte_id' => $reporteId, 'tipo' => 'fuera_de_territorio']);
    }
    if ($cantonId !== null) {
        $cantonesTocados[] = $cantonId;
    }

    $resultados[] = ['uuid_local' => $uuidLocal, 'estado' => 'recibido', 'folio' => $fila['folio']];
}

if ($cantonesTocados !== []) {
    try {
        ObMotorAlertas::evaluar($pdo, $cantonesTocados);
    } catch (Throwable $e) {
        // El motor de alertas nunca debe tumbar la confirmación de los
        // reportes ya guardados: si falla, se registra y se sigue. Los
        // reportes de este lote ya están confirmados en $resultados.
        obApiLog('Fallo al evaluar alertas', ['mensaje' => $e->getMessage()]);
    }
}

obJsonResponse(true, ['resultados' => $resultados], null, 200);
