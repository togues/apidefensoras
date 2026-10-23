# Observatorio API (`api/observatorio`)

API PHP 8.2 plano —sin framework— del Observatorio Comunitario de Vulneraciones
de Derechos a Personas Defensoras. Calcado de la forma de
`referencia-front/terrosenser/api/apiterrosenser` (ver `CLAUDE.md`, "Stack"):
un archivo físico por endpoint, `lib/` compartida, `.htaccess` para reenviar
`Authorization` y ocultar la extensión `.php`. A diferencia de TerroSenser,
esta API sí tiene base de datos (`lib/Db.php`, PDO/PostgreSQL).

## Requisitos

- PHP 8.2 con extensiones `pdo_pgsql`, `pgsql`
- PostgreSQL + PostGIS con las migraciones de `bd/migraciones/` aplicadas

Para no depender de tener PHP/Postgres instalados en cada máquina, hay un
`docker-compose.dev.yml` en la raíz de `_VIGENTE` — **solo para desarrollo
local**, no es la forma de despliegue en producción (esa sigue siendo el
mismo hosting PHP/Apache que ya usa TerroSenser).

## Arranque con Docker (desarrollo)

```bash
cd _VIGENTE
cp .env.dev.example .env.dev
cp api/observatorio/.env.example api/observatorio/.env
# editar API_SECRET y DB_PASSWORD en api/observatorio/.env

docker compose --env-file .env.dev -f docker-compose.dev.yml up -d --build

# var/ es donde vive el rate limiter (archivos). El contenedor escribe como
# www-data y el volumen es un bind mount del host: sin esto, el rate
# limiter falla en abrir el archivo y deja pasar todo silenciosamente.
# Es un problema de docker-compose en dev, no de producción (ahí se
# despliega como el usuario del hosting, dueño de sus propios archivos).
mkdir -p api/observatorio/var/ratelimit && chmod 777 api/observatorio/var api/observatorio/var/ratelimit

# Aplicar el esquema contra la base del contenedor:
PGPASSWORD=<la de .env.dev> PGHOST=localhost PGPORT=5433 PGDATABASE=observatorio PGUSER=observatorio \
  bash ../bd/migrar.sh
```

La API queda en `http://localhost:8080/api/v1/...` — las rutas son exactamente
las de `contratos/api_v1.md` (`/api/v1/catalogos`, `/api/v1/dispositivos/enrolar`...).

## Configuración (`.env`)

| Variable | Descripción |
|---|---|
| `CORS_ALLOWED_ORIGINS` | Orígenes del cascarón (coma-separados) |
| `API_SECRET` | Secreto compartido — header `X-App-Secret` |
| `API_SECRET_ENFORCE` | `off` \| `monitor` \| `strict`. **En producción, siempre `strict`** — va en todas las peticiones, no solo `/catalogos` (ver `CLAUDE.md`, trampa 2) |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | Conexión a PostgreSQL |
| `DB_SSLMODE` | `prefer` (default, sirve para el Postgres de docker-compose) \| `require` — obligatorio en producción contra una base administrada (DigitalOcean, etc.). Ver `docs/08-despliegue.md` |
| `RATE_LIMIT_*` | Límites de `api_v1.md`, sección 2, por categoría de endpoint |

## Endpoints implementados

| Método | Ruta | Estado |
|---|---|---|
| GET | `/salud` | Hecho — sin datos, sin autenticación (bonus de desarrollo, no es `GET /admin/salud` del panel) |
| GET | `/catalogos` | Hecho — incluye los 5 campos de la migración 003 (`etiqueta_corta`, `ayuda_usar`, `ayuda_no_usar`, `municipio`, `centro`) y `ETag`/`If-None-Match` |
| POST | `/dispositivos/enrolar` | Hecho |
| POST | `/dispositivos/recuperar` | Hecho |
| PATCH | `/dispositivos/perfil` | Hecho |
| POST | `/reportes/sync` | Hecho — idempotente por `uuid_local`, `prioridad` y `marcado_anomalo` los decide el servidor, filtro de contenido (`lib/FiltroContenido.php`, puerto de `src/filtro_contenido.js`) y motor de alertas (`lib/MotorAlertas.php`) tras cada lote |
| POST | `/reportes/{uuid_local}/evidencias` | Hecho — revalida EXIF del lado servidor (`lib/ExifRevalidador.php`), máximo 5 fotos por reporte, primer endpoint con parámetro de ruta (ver `.htaccess`) |
| GET | `/mis-reportes` | Hecho |
| GET | `/avisos` | Hecho — `?canton_id=` opcional, solo secreto de aplicación (contenido agregado, como `/catalogos`) |
| GET | `/videotutoriales` | Hecho — lista vacía hasta que REDNA cargue el primer video |
| GET | `/actividad` | Hecho — agregado del cantón del dispositivo, `mostrar_numero=false` bajo 5 hechos |

**Los diez endpoints de la app comunitaria están hechos y probados contra
Postgres real.**

### Panel de administración (`panel/`, otra aplicación, mismo backend)

| Método | Ruta | Estado |
|---|---|---|
| POST | `/auth/login` | Hecho — correo y clave contra `usuario`, `password_verify`, 10 intentos/hora/IP |
| POST | `/auth/logout` | Hecho — idempotente |
| GET | `/admin/estadisticas` | Hecho — KPIs, serie mensual por familia, casos recientes, alertas activas, tipos frecuentes (vista Resumen) |
| GET | `/admin/mapa` | Hecho — no está en la tabla de `api_v1.md` sección 4, apareció al construir el Mapa; un punto por reporte, siempre el centro de la comunidad (regla 5) |
| GET | `/admin/casos` | Hecho — filtros de 4.1 más `prioridad` y `q` (búsqueda de texto libre en folio/relato/comunidad), tampoco en el contrato original |
| GET | `/admin/casos/{id}` | Hecho — detalle con bitácora |
| PATCH | `/admin/casos/{id}` | Hecho — cambio de estado y/o nota; descartar exige nota |
| POST | `/admin/casos/{id}/publicar` | Hecho — rol administrador, rechaza si el resumen viene vacío o es igual al relato |
| GET | `/admin/analisis` | Hecho — no está en la tabla de `api_v1.md` sección 4, apareció al construir Análisis; evolución mensual total, distribución por familia, todos los tipos de hecho, territorio, estado acumulado |
| GET | `/admin/salud` | Hecho — tal como lo especifica 4.4. Sin inventar lo que no se puede medir todavía (disponibilidad, cola de sincronización, último respaldo) |
| PATCH | `/admin/anomalias/{id}` | Hecho — rol administrador, marca revisado sin borrar el registro |
| GET | `/admin/participacion` | Hecho — 4.3, rol administrador. Sexo/estructura solo vía `v_participacion_agregada` (nunca menos de 5 personas por grupo, regla 2) |
| GET/POST | `/admin/usuarios` | Hecho — no está en la tabla de 4, rol administrador. Alta de cuentas del panel; nunca se borra, solo se desactiva |
| PATCH | `/admin/usuarios/{id}` | Hecho — rol administrador. Activar/desactivar (cierra sesiones abiertas), cambiar rol, resetear clave |
| GET/POST | `/admin/comunidades` | Hecho — no está en la tabla de 4, rol administrador. Alta de comunidad genera su primer código automáticamente |
| POST | `/admin/comunidades/{id}/codigos` | Hecho — rol administrador, código adicional para una comunidad existente |
| PATCH | `/admin/codigos/{id}` | Hecho — rol administrador, revoca sin borrar ni afectar a quien ya se enroló |
| GET | `/admin/evidencias/{id}` | Hecho — no está en la tabla de 4, cualquier rol con sesión. Sirve el archivo de una foto de evidencia (antes el panel solo mostraba un conteo y un cuadro de color decorativo). No es la "URL directa" que api_v1.md 3.6 prohíbe — exige sesión real, nunca es pública |
| GET/POST | `/admin/respaldos` | Hecho — no está en la tabla de 4, rol administrador. `GET` lista y dispara el respaldo automático "perezoso" si ya tocaba; `POST` genera uno manual (rate-limited). Ver `docs/decision-04-respaldos.md` |
| PATCH | `/admin/respaldos/configuracion` | Hecho — rol administrador, activa/desactiva el automático y cambia la retención |
| GET | `/admin/respaldos/{id}/descargar` | Hecho — rol administrador, streamea el `.enc`, registra la descarga en `respaldo_descarga` |

Siguen: reglas de alerta, actores clave, catálogo
(`contratos/00-inventario-api.md`, sección 3).

### Muro público (`muro/`, tercera aplicación, sin sesión)

| Método | Ruta | Estado |
|---|---|---|
| GET | `/publico/muro` | Hecho — sección 5, solo `resumen_publico` y territorio a nivel cantón (un paso más conservador que el panel interno) |
| GET | `/publico/estadisticas` | Hecho — agrega todo `v_reporte_territorio`, mismo umbral de 5 que `v_participacion_agregada`; interpretación de Marco, sin confirmar con ACUA |

### Decisiones que salieron de programar, no de diseñar antes

- **`REPORTE_NO_ENCONTRADO`** se agregó al vocabulario de errores
  (`api_v1.md`, sección "Códigos de error"): el contrato no traía un
  `code` para "ese `uuid_local` no existe o no es tuyo", y hacía falta
  para `POST /reportes/{uuid}/evidencias`. No distingue los dos casos a
  propósito.
- **El motor de alertas reutiliza la alerta abierta indefinidamente**,
  hasta que una persona la cierre desde el panel (`PATCH
  /admin/alertas/{id}`, sin programar todavía). No hay expiración
  automática por tiempo — ver el comentario largo al inicio de
  `lib/MotorAlertas.php` si hace falta el porqué.
- **El filtro de contenido nunca rechaza un `POST /reportes/sync`.** Solo
  marca (`log_anomalia` + `marcado_anomalo`) para revisión humana. Regla 9
  de `CLAUDE.md`: "nunca cancela cuentas automáticamente", y rechazar acá
  sería perder evidencia real.
- **El login real (`lib/PanelAuth.php`, migración 006) se construyó antes
  de lo planeado.** El plan era una llave fija de desarrollo hasta el
  final; se adelantó porque `PATCH /admin/casos/{id}` y `POST
  .../publicar` escriben en `seguimiento_caso`, que exige `usuario_id`
  (NOT NULL) — atribuir esos cambios a un usuario inventado habría sido
  una bitácora falsa. Decisión de Marco.
- **Descartar un caso exige nota** (`PATCH /admin/casos/{id}` con
  `estado: 'descartado'` sin `nota` responde `NOTA_REQUERIDA`). No está
  en el contrato explícitamente; salió de construir el botón — sin el
  motivo por escrito, nadie que revise después sabe si fue contenido
  inapropiado, un duplicado, o un error.

## Decisiones que valen para todo lo que se agregue acá

- **`huella_dispositivo` no existe.** Se quitó del contrato (`api_v1.md`) por
  decisión de Marco: en una app de denuncia anónima, guardar metadata de
  aparato es un arma de doble filo. `veces_recuperado` y los límites por
  IP (registro 100/hora, recuperar 5/hora) son el control de abuso de la v1.
- **`X-App-Secret` va primero, en todos los endpoints**, incluidos los que
  además piden token de dispositivo o sesión de panel.
- **Nada de framework.** Si un endpoint necesita parámetros de ruta
  (`{id}`, `{uuid_local}`), la carpeta refleja la URL (como
  `dispositivos/enrolar.php` ↔ `/dispositivos/enrolar`) y, cuando haga
  falta, se agrega una regla explícita en `.htaccess` — no un router
  genérico.
