# Guía de transferencia a nueva IA — marchasdecristo.com

> **Propósito de este documento**: permitir que una nueva IA (o un nuevo
> desarrollador) tome el relevo del proyecto sin necesitar contexto previo de la
> sesión anterior. Es la lectura obligada antes de cualquier otra cosa.
>
> **Última actualización**: 2026-08-04
>
> **Documentos complementarios** (léelos en este orden):
> 1. Este documento (`ai-handoff-guide.md`) — punto de entrada y guía operativa
> 2. [`context.md`](context.md) — visión general del proyecto, stack y flujo de datos
> 3. [`architecture.md`](architecture.md) — diagrama de componentes, flujos de petición y ADRs
> 4. [`roadmap.md`](roadmap.md) — ⭐ **fuente única del trabajo futuro**
> 5. [`technical-debt.md`](technical-debt.md) — deuda técnica activa
> 6. [`entornos.md`](entornos.md) — entornos PRE/PRO y pipeline de deploy

---

## 1. Resumen ejecutivo del proyecto

**Marchas de Cristo** ([marchasdecristo.com](https://marchasdecristo.com)) es una base de datos de **música procesional española** mantenida por una sola persona (Javier Guerra, `@JaviWarSVQ`). El público son aficionados a la música cofrade, con picos de tráfico en Cuaresma/Semana Santa.

### Cuatro entidades principales + dedicatorias

| Entidad | Volumen (2026-07-28) | Descripción |
|---|---|---|
| `marcha` | ~5.000 | Obra musical (título, fecha, estilo CCTT/AM, dedicatoria, localidad) |
| `autor` | ~830 | Compositores |
| `banda` | ~270 | Formaciones musicales (con linaje entre bandas) |
| `disco` | ~430 | Grabaciones (CDs), con portada y pistas |
| `dedicatoria` | — | Hubs de advocación con alias unificados |

El recuento real y actualizado siempre en `/health` con sesión admin.

### Stack en una línea

**PHP 8.4 plano + PDO/SQLite + plantillas PHP nativas**, sin Composer, sin framework, sin build step. Deploy por FTP a **HelioHost** (hosting compartido). Sin Docker, sin servidor de BD separado. La BD es un fichero SQLite (`mdc.db`) que vive fuera del webroot.

### Invariantes del sistema que nunca debes romper

1. **Producción es de solo lectura** — `Db::assertWritable()` mata cualquier escritura si `config['env'] !== 'local'`. Esto es un fail-safe, no un flag de mantenimiento.
2. **La BD maestra es la local** — jamás se edita directamente en producción.
3. **El `.db` nunca entra en el webroot** (`public_html/` o `php/public/`).
4. **Los editores nunca escriben en la BD** — sus propuestas son ficheros JSON.
5. **El sync de BD incluye checksum + rollback automático** — si el SHA-256 no coincide al re-descargar, se restaura el anterior.

---

## 2. Estado actual del proyecto (2026-08-04)

### Lo que está en producción

Todo lo descrito en `roadmap.md §5`:
- Páginas de catálogo completas: listados, detalles, búsqueda global (`/buscar`), hubs de año/estilo/provincia, dedicatorias, rankings, aniversarios, mapa SVG de España, temporada (oculta en PRO hasta tener datos)
- Panel de administración completo (marchas, autores, bandas, discos con portada y pistas, dedicatorias, estilos, ingesta YouTube, enlaces de streaming, propuestas de editores, gestión de usuarios, temporada)
- API JSON pública (`/api/{marcha,autor,banda,disco}/{id}.json`, CC BY 4.0)
- `og:image` dinámica por entidad (GD+FreeType)
- CI con 82 smoke tests en cada push
- Deploy automático PRE/PRO por FTP (GitHub Actions)
- UptimeRobot monitorizando `/health`
- Pipeline de ingesta desde streaming (Spotify/Deezer/Apple) — `tools/music_links/`

### Lo que está pendiente (backlog priorizado)

Ver `roadmap.md §2` para el estado exacto. Resumen:

**P0 — Cierre operativo inmediato:**
- `B-01`: PR #27 (`pre` → `main`) abierto, pendiente de validación visual del mantenedor en navegador. Una vez validado: fusionar → deploy a PRO → borrar ramas `claude/*` ya integradas.
- `OPS-01`: Aplicar migración `008_ingest_streaming.sql` en local e importar candidatos (solo ejecutable por el mantenedor, requiere `.env.ftp` y BD local real).
- `OPS-02`: Ejecutar `seed_dedicatorias.php` en prod (Plesk Scheduled Tasks, PHP 8.4 explícito).

**P1 — Antes de octubre 2026:**
- `R-01`: Capturar ISRC en la ingesta de streaming (columna nueva en `enlace_streaming`/`ingest_candidato`)
- `R-07`: Página pública de estado del catálogo (% marchas con escucha)
- `M2/P-01`: Campaña de cobertura de audio (curar 616 candidatos + YouTube)
- `R-02`: Mover `DURACION_SEG` de `marcha` a `disco_marcha`
- `D-2.1`: `PRAGMA integrity_check` sobre backup + copia externa
- `T-02`: Orquestador único de ingesta mensual

**P2 — Antes de Cuaresma 2027:** Accesibilidad/impresión (M6, ya en `pre`), formulario público de propuesta de grabación (R-06/L4), filtro «solo con audio» en búsqueda (R-08), notificaciones editoriales (M7, ya en `pre`), partituras (R-04).

---

## 3. Estructura del repositorio

```
marchasdecristo/                     ← raíz del repositorio
├── .github/
│   └── workflows/
│       ├── ci.yml                   ← lint + smoke tests (cada push/PR salvo main/pre)
│       └── deploy.yml               ← FTP mirror automático (pre → PRE, main → PRO)
├── docs/                            ← TODA la documentación del proyecto
│   ├── ai-handoff-guide.md          ← ESTE DOCUMENTO
│   ├── context.md                   ← visión general, stack, flujo de datos
│   ├── architecture.md              ← diagrama, flujos de petición, ADRs
│   ├── roadmap.md                   ← ⭐ backlog priorizado (fuente única)
│   ├── technical-debt.md            ← deuda técnica activa
│   ├── entornos.md                  ← PRE/PRO, deploy, rollback
│   ├── admin-panel.md               ← panel de administración
│   ├── db-analysis.md               ← auditoría de la BD
│   ├── monitoring.md                ← UptimeRobot, /health
│   ├── ingesta-streaming.md         ← pipeline de ingesta desde streaming
│   └── archive/                     ← histórico (stack Next.js/VPS — obsoleto)
├── php/                             ← TODA la aplicación PHP
│   ├── public/                      → se sube a public_html/ en HelioHost
│   │   ├── index.php                ← front controller (único punto de entrada HTTP)
│   │   ├── .htaccess                ← mod_rewrite + caché 30d estáticos + CSP
│   │   └── assets/                  ← CSS y JS sin compilación
│   │       ├── app.css
│   │       ├── admin.js
│   │       ├── catalog.js
│   │       ├── banda-relaciones.js
│   │       ├── disco.js
│   │       ├── mapa.js
│   │       └── mapa-provincias.svg  ← mapa SVG de 52 provincias de España
│   ├── app/                         → se sube a app/ FUERA de public_html
│   │   ├── bootstrap.php            ← autoload + config + mantenimiento + dispatch
│   │   ├── config.php               ← defaults (sin secretos)
│   │   ├── config.local.example.php ← plantilla de config.local.php
│   │   ├── routes.php               ← TODAS las rutas del sitio
│   │   ├── src/                     ← clases PHP (namespace App\)
│   │   ├── templates/               ← plantillas PHP nativas
│   │   ├── tools/                   ← scripts operativos (backfill, migraciones)
│   │   ├── fonts/                   ← fuentes IBM Plex para og:image (GD+FreeType)
│   │   └── geo/                     ← municipios_es.php (seed de ~8.112 municipios)
│   ├── data/                        → BD local (gitignored: mdc.db)
│   └── tools/                       ← utilidades de CI (fixture + smoke tests)
│       ├── ci_fixture.php           ← genera BD determinista para CI
│       ├── ci_smoke.php             ← suite de 82 smoke tests
│       └── smoke_remote.php         ← smoke contra PRE/PRO con datos reales
├── scripts/
│   ├── dev_server.sh                ← servidor de desarrollo local (4 workers)
│   ├── sync_db_to_prod.php          ← sube mdc.db a producción (checksum + rollback)
│   └── sync_propuestas_from_prod.php← baja propuestas de editores desde prod
└── tools/
    ├── ingest/                      ← ingesta YouTube (Node.js, sin deps)
    │   ├── extract.mjs              ← fase 1: yt-dlp wrapper
    │   ├── classify.mjs             ← fase 2: clasificador heurístico
    │   └── dedup.mjs                ← fase 3: deduplicación contra BD
    └── music_links/                 ← matching streaming (Python)
        ├── descubrir_marchas.py
        ├── match_bandas.py
        ├── match_discos.py
        └── match_marchas.py
```

---

## 4. Flujo de una petición HTTP

```
Navegador/bot → Apache → .htaccess (mod_rewrite)
                               │
                    ¿fichero estático real?
                    ├── SÍ → Apache lo sirve directamente (caché 30d)
                    └── NO → php/public/index.php
                                    │
                            php/app/bootstrap.php
                            1. spl_autoload_register (PSR-4: App\Foo → src/Foo.php)
                            2. config.php + config.local.php
                            3. ¿existe .maintenance? → Http::maintenance() → 503 + Retry-After
                            4. require routes.php
                            5. Router::dispatch($_SERVER['REQUEST_URI'])
                                    │
                         ┌──────────┴──────────────┐
                         ▼                          ▼
                  Pages::método()           Admin::método()
                  (páginas públicas)        (panel /dashboard)
                         │                          │
                         ▼                          ▼
                    Repo::query()         AdminRepo::write()
                    (solo lectura)        (solo si env=local)
                         │
                         ▼
                    App\Db (PDO/SQLite singleton)
                    → private/mdc.db (fuera del webroot)
                         │
                    View::render('plantilla', $data, $meta)
                    → templates/plantilla.php + layout.php
                         │
                    HTML con JSON-LD / JSON / PNG
```

**Puntos clave:**
- Sin middleware — cada controlador comprueba `Auth::currentSession()` inline al principio.
- PRG en todos los formularios admin (Post → Redirect → Get).
- Redirect 308 a URL canónica (`slug-id`) antes de renderizar.

---

## 5. Desarrollo local — puesta en marcha

### Requisitos

- PHP 8.4 con extensiones: `pdo_sqlite`, `sqlite3`, `mbstring`, `gd`, `intl`, `curl`, `json`
- El fichero `php/data/mdc.db` (no versionado — pedir al mantenedor o generar con `ci_fixture.php` para pruebas sin datos reales)

### Pasos

```bash
# 1. Clonar el repositorio
git clone https://github.com/jgcoronado/marchasdecristo.git
cd marchasdecristo

# 2. Crear la config local (ajustar secret_key y debug)
cp php/app/config.local.example.php php/app/config.local.php
# Editar config.local.php:
#   'env'        => 'local',       ← OBLIGATORIO para habilitar escrituras
#   'debug'      => true,
#   'secret_key' => '...96 chars...',  ← cualquier cadena aleatoria larga

# 3. Obtener la BD (o generar fixture para desarrollo sin datos reales)
#   Opción A: pedir mdc.db al mantenedor → php/data/mdc.db
#   Opción B: fixture de CI (sin datos reales, suficiente para smoke tests)
php php/tools/ci_fixture.php php/data/mdc.db

# 4. Arrancar el servidor
./scripts/dev_server.sh            # localhost:8000
./scripts/dev_server.sh 8080       # puerto alternativo
DB_PATH=php/data/mi.db ./scripts/dev_server.sh

# 5. Verificar
curl http://localhost:8000/health  # debe responder: db: ok
```

> **IMPORTANTE**: Usar siempre `dev_server.sh`, nunca `php -S` a pelo. El servidor embebido con 1 worker procesa peticiones en serie — los assets CSS/JS que el navegador pide en paralelo se encolan y la página llega sin estilos. `dev_server.sh` lanza 4 workers con `PHP_CLI_SERVER_WORKERS=4`.

### Config local relevante

```php
<?php
return [
    'env'        => 'local',        // habilita escrituras en la BD
    'debug'      => true,           // errores visibles en pantalla
    'secret_key' => 'una-cadena-aleatoria-de-al-menos-32-caracteres',
    'db_path'    => __DIR__ . '/../data/mdc.db',  // ruta por defecto
    // Opcionales:
    // 'goatcounter_code' => null,
    // 'indexnow_key'     => null,
    // 'mail_from'        => 'dev@local.test',
];
```

---

## 6. Ciclo de trabajo normal (código)

```
1. Crear rama desde pre:
   git fetch origin pre
   git checkout -b claude/mi-feature origin/pre

2. Desarrollar y probar en local

3. Lint (obligatorio antes de push):
   find php -name "*.php" | xargs php -l

4. Smoke tests en local (reproduces exactamente el CI):
   php php/tools/ci_fixture.php /tmp/ci.db
   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 -t php/public php/public/index.php &
   php php/tools/ci_smoke.php http://127.0.0.1:8001
   # Debe dar 82/82. Detener el servidor.

5. Push a la rama (NO a pre ni a main directamente):
   git push -u origin claude/mi-feature

6. PR hacia pre (no hacia main):
   - CI se ejecuta automáticamente
   - Revisar que CI pase (lint + 82 smoke tests)

7. Al fusionar a pre → deploy automático a PRE
   - Verificar en https://marchasdecristo.jaguerra27.helioho.st
   - /health debe decir entorno: pre, db: ok
   - Debe verse la cinta «Entorno de preproducción»

8. PR de pre → main → validación visual del mantenedor → fusionar
   → deploy automático a PRO con mantenimiento ON/OFF
   → smoke remoto contra PRO
```

**Restricciones de la sesión actual:**
- El proxy git de la sesión no permite borrar refs remotas (`git push --delete` da 403). Dejar el borrado de ramas `claude/*` ya integradas para que lo haga el mantenedor a mano o desde su máquina.

---

## 7. Ciclo de datos (BD)

El código y los datos son procesos **completamente separados**. El pipeline de código (GitHub Actions / FTP) **nunca toca la BD**.

### Flujo de propuestas de editor

```
Editor (en producción, solo lectura de BD)
    → Admin::marchaEditPost() → rol editor → PropuestaRepo::create()
    → private/propuestas/pendientes/<id>.json  (fichero JSON, no BD)

Mantenedor (en local, BD escribible):
    → scripts/sync_propuestas_from_prod.php  (baja propuestas por FTP)
    → /dashboard/propuestas  (revisión visual)
    → «Aceptar» → AdminRepo (escribe en mdc.db local)
    → scripts/sync_db_to_prod.php  (sube la BD a producción)
```

### Sync de BD a producción

```bash
php scripts/sync_db_to_prod.php
# Pasos internos:
# 1. Comprueba que no hay propuestas pendientes sin bajar
# 2. Activa .maintenance en el FTP (503 hasta que termine)
# 3. Sube mdc.db por FTP
# 4. Descarga lo que subió y compara SHA-256
#    → Si no coincide: restaura el .db anterior (rollback automático) y aborta
# 5. Borra .maintenance (register_shutdown_function como red de seguridad)
# 6. Ping a IndexNow con URLs del sitemap

# Flags útiles:
php scripts/sync_db_to_prod.php --skip-verify      # omite checksum
php scripts/sync_db_to_prod.php --skip-indexnow    # omite ping IndexNow
php scripts/sync_db_to_prod.php --force            # omite guardarraíl de propuestas
```

### Migraciones de esquema

Las migraciones son ficheros SQL en `php/app/tools/sql/` con `CREATE TABLE IF NOT EXISTS` (idempotentes). Se aplican con:

```bash
# En local
php php/app/tools/migrate_ingest.php

# En producción (Plesk → Scheduled Tasks → PHP 8.4 explícito)
php /home/USUARIO/app/tools/migrate_ingest.php
```

El script aplica en orden alfabético todos los `.sql` del directorio. Es seguro ejecutarlo varias veces (idempotente).

---

## 8. Clases PHP — referencia rápida

Todas en `php/app/src/`, namespace `App\`. El autoload PSR-4 de `bootstrap.php` resuelve `App\Foo` → `src/Foo.php`.

| Clase | Rol | ¿Escribe en BD? |
|---|---|---|
| `Router` | Matching de rutas `{param}` y dispatch | No |
| `Db` | PDO/SQLite singleton, `assertWritable()`, función `NOACC()` | Solo si `env=local` |
| `Repo` | TODAS las lecturas públicas (marchas, autores, bandas, discos, hubs, búsqueda, rankings, home, aniversarios, dedicatorias, temporada, mapa) | No |
| `Pages` | Controladores de páginas públicas. Un método estático por ruta. | No |
| `Admin` | Controladores del panel admin. PRG en todos los formularios. | Solo si `env=local` |
| `AdminRepo` | Escrituras admin con allowlist + transacción + audit log | Solo si `env=local` |
| `Api` | API JSON pública (`/api/{entidad}/{id}.json`) | No |
| `Auth` | Sesión HMAC-SHA256, PBKDF2/MD5, rate limit a fichero, CSRF | No (escribe ficheros de rate limit) |
| `Roles` | Capacidades por rol: `admin` (total) / `editor` (propuestas) | No |
| `PropuestaRepo` | Serializar/listar propuestas de editor como JSON en disco | No (escribe ficheros JSON) |
| `IngestaRepo` | Lecturas de candidatos de ingesta (YouTube/streaming) | No |
| `EnlaceRepo` | Lecturas de candidatos de enlace de streaming | No |
| `UserRepo` | Gestión de usuarios del panel | Solo si `env=local` |
| `Seo` | JSON-LD schema.org (MusicComposition, Person, MusicGroup, MusicAlbum, CollectionPage) | No |
| `Og` | og:image dinámica con GD+FreeType, cacheada a disco | No (escribe PNG a disco) |
| `Slug` | Slugify y parsing de URLs `slug-id` | No |
| `Html` | Componentes HTML reutilizables (paginación, streaming, portadas, selector de municipio) | No |
| `Http` | Helpers HTTP: redirect, Cache-Control, 404, 503 mantenimiento, cabeceras de seguridad | No |
| `View` | Render de plantillas: buffer → inyectar como `$content` en `layout.php` | No |
| `Legacy` | 301 bridge de URLs `.html` del sitio MySQL anterior | No |
| `Mapa` | Coropleta SVG de 52 provincias + puntos por municipio | No |
| `MunicipioRepo` | Catálogo de ~8.112 municipios con coordenadas | No |
| `Media` | Extracción de ID de YouTube de una URL | No |
| `Mailer` | Email via `mail()` nativo: notificaciones editoriales + digest semanal | No |
| `Similarity` | Ratio de similitud textual (dedup, matching) | No |

### Patrones que siempre se siguen

```php
// Controlador público: 404 inmediato si no existe
$marcha = Repo::fetchMarcha($id);
if (!$marcha) Http::notFound();

// Redirect a canónica ANTES de renderizar
$canonical = Slug::marchUrl($marcha);
if ($canonical !== $_SERVER['REQUEST_URI']) Http::redirect($canonical, 308);

// Controlador admin: comprobar sesión inline (sin middleware de framework)
$session = Auth::currentSession($config);
if (!$session) Http::redirect('/login');

// Escritura con allowlist (en AdminRepo)
$allowed = ['TITULO', 'FECHA', 'LOCALIDAD', ...];
$payload = array_intersect_key($data, array_flip($allowed));
// Luego UPDATE con prepared statement

// Render de vista
View::render('marcha_detail', ['marcha' => $marcha, ...], [
    'title'       => $marcha['TITULO'] . ' — Marchas de Cristo',
    'description' => '...',
    'jsonld'      => Seo::marcha($marcha, $autores),
    'og'          => ['image' => '/og/marcha/' . $marcha['ID_MARCHA'] . '.png'],
]);
```

---

## 9. Esquema de la BD

### Tablas principales (heredadas del esquema MySQL original)

```sql
-- marcha: la entidad central
marcha (
    ID_MARCHA        INTEGER PRIMARY KEY,
    TITULO           TEXT NOT NULL,
    FECHA            TEXT,        -- 'YYYY' o 'YYYY-MM-DD'
    LOCALIDAD        TEXT,        -- texto libre normalizado
    PROVINCIA        TEXT,        -- backfill de completar_provincia.php
    BANDA_ESTRENO    INTEGER,     -- FK → banda.ID_BANDA (0 = desconocida, no NULL)
    ESTILO           TEXT,        -- 'CCTT' | 'AM' | NULL
    ID_DEDICATORIA   INTEGER,     -- FK → dedicatoria.ID
    DURACION_SEG     INTEGER,
    URL_AUDIO        TEXT,        -- URL externa de referencia (no archivo alojado)
    YOUTUBE_ID       TEXT,
    ...
)

autor (ID_AUTOR, NOMBRE, ...)
banda (ID_BANDA, NOMBRE, LOCALIDAD, PROVINCIA, FORMACION, DISOLUCION, ESTILO, ...)
disco (ID_DISCO, TITULO, BANDADISCO, ANIO, COVER_URL, ...)

-- Relaciones N:N
marcha_autor (ID_MARCHA, ID_AUTOR)
disco_marcha (ID_DISCO, ID_MARCHA, PISTA)

-- FTS5 (búsqueda full-text)
marcha_fts (rowid → marcha.ID_MARCHA, TITULO, ...)
autor_fts  (rowid → autor.ID_AUTOR, NOMBRE)

-- Audit log
admin_log (ID, TABLA, CAMPO, VALOR_ANT, VALOR_NUE, USUARIO, TS)

-- Usuarios del panel
usuarios (ID, EMAIL, PASSWORD_HASH, ROL)  -- ROL: 'admin' | 'editor'
```

### Tablas de migración (`sql/001–008`)

| Migración | Tablas |
|---|---|
| `001_ingest_staging.sql` | `ingest_canal`, `ingest_candidato`, `ingest_run` |
| `002_banda_relacion.sql` | `banda_relacion` (linaje: renombrado/fusion/division/juvenil) |
| `003_dedicatoria.sql` | `dedicatoria`, `dedicatoria_alias` |
| `004_enlace_streaming.sql` | `enlace_streaming`, `enlace_candidato` |
| `005_contrato.sql` | `contrato` (banda↔hermandad por temporada/año) |
| `006_sync_dedicatoria_alias_localidad.sql` | trigger de sincronización alias↔localidad |
| `007_municipio.sql` | `municipio` (~8.112 municipios INE con lat/lng) |
| `008_ingest_streaming.sql` | `ingest_veto`, `ingest_descarte_ultimo` |

### Función SQL personalizada

`App\Db` registra `NOACC(texto)` via `PDO::sqliteCreateFunction()`: normaliza acentos + minúsculas. Permite búsquedas insensibles a acentos sin FTS5. Usarla así:

```sql
WHERE NOACC(TITULO) LIKE NOACC(:q)
```

---

## 10. Rutas (referencia completa)

Definidas en `php/app/routes.php`. La clase `Router` registra rutas con `{param}` nombrados.

### Páginas públicas

| Método | Patrón | Controlador |
|---|---|---|
| GET | `/` | `Pages::home` |
| GET | `/marcha` | `Pages::marchaList` |
| GET | `/marcha/ano/{anio}` | `Pages::marchaAnioHub` |
| GET | `/marcha/estilo/{slug}` | `Pages::marchaEstiloHub` |
| GET | `/marcha/provincia/{slug}` | `Pages::marchaProvinciaHub` |
| GET | `/marcha/{slugAndId}` | `Pages::marchaDetail` |
| GET | `/autor` | `Pages::autorList` |
| GET | `/autor/{slugAndId}` | `Pages::autorDetail` |
| GET | `/banda` | `Pages::bandaList` |
| GET | `/banda/{slugAndId}` | `Pages::bandaDetail` |
| GET | `/disco` | `Pages::discoList` |
| GET | `/disco/{slugAndId}` | `Pages::discoDetail` |
| GET | `/dedicatorias` | `Pages::dedicatoriaList` |
| GET | `/dedicatoria/{slugAndId}` | `Pages::dedicatoriaDetail` |
| GET | `/rankings` | `Pages::rankingsIndex` |
| GET | `/rankings/{anio}` | `Pages::rankingsAnio` |
| GET | `/aniversarios` | `Pages::aniversariosIndex` (→ 302 año actual) |
| GET | `/aniversarios/{anio}` | `Pages::aniversariosAnio` |
| GET | `/mapa` | `Pages::mapa` |
| GET | `/mapa/provincia/{slug}` | `Pages::mapaProvincia` |
| GET | `/temporada` | `Pages::temporadaIndex` (oculta en PRO) |
| GET | `/temporada/{anio}` | `Pages::temporadaAnio` (oculta en PRO) |
| GET | `/buscar` | `Pages::buscar` |
| GET | `/datos` | `Pages::datos` |

### SEO y feeds

| GET | `/sitemap.xml` | `Pages::sitemap` |
| GET | `/robots.txt` | `Pages::robots` |
| GET | `/feed.xml` | `Pages::feedXml` (RSS) |
| GET | `/feed.json` | `Pages::feedJson` |
| GET | `/llms.txt` | `Pages::llmsTxt` |
| GET | `/health` | `Pages::health` |

### API pública

| GET | `/api/marcha/{id}.json` | `Api::marcha` |
| GET | `/api/autor/{id}.json` | `Api::autor` |
| GET | `/api/banda/{id}.json` | `Api::banda` |
| GET | `/api/disco/{id}.json` | `Api::disco` |
| GET | `/api/buscar` | `Api::buscar` (autocompletado) |
| GET | `/og/{tipo}/{id}.png` | `Og::render` |

### Panel admin (`/dashboard/*`)

Todas requieren sesión válida (`Auth::currentSession()`). El rol `editor` ve un subconjunto.

| Método | Patrón | Controlador | Admin | Editor |
|---|---|---|---|---|
| GET/POST | `/login` | `Admin::login*` | ✓ | ✓ |
| POST | `/logout` | `Admin::logout` | ✓ | ✓ |
| GET | `/dashboard` | `Admin::dashboard` | ✓ | ✓ |
| GET/POST | `/dashboard/marcha/{id}` | `Admin::marchaEdit*` | escribe | propuesta |
| GET/POST | `/dashboard/marcha/add` | `Admin::marchaAdd*` | escribe | propuesta |
| GET/POST | `/dashboard/autor/{id}` | `Admin::autorEdit*` | escribe | propuesta |
| GET/POST | `/dashboard/banda/{id}` | `Admin::bandaEdit*` | escribe | propuesta |
| GET/POST | `/dashboard/disco/{id}` | `Admin::discoEdit*` | escribe | ✗ |
| GET/POST | `/dashboard/disco/add` | `Admin::discoAdd*` | escribe | ✗ |
| GET/POST | `/dashboard/dedicatoria*` | `Admin::dedicatoria*` | ✓ | ✗ |
| GET/POST | `/dashboard/estilos*` | `Admin::estilos*` | ✓ | ✗ |
| GET | `/dashboard/ingesta*` | `Admin::ingesta*` | ✓ | ✗ |
| GET | `/dashboard/enlaces*` | `Admin::enlaces*` | ✓ | ✗ |
| GET | `/dashboard/propuestas` | `Admin::propuestas` | ✓ | ✗ |
| GET | `/dashboard/propuesta/{id}` | `Admin::propuesta` | ✓ | ✗ |
| POST | `/dashboard/propuesta/{id}/aceptar` | `Admin::propuestaAceptar` | ✓ | ✗ |
| POST | `/dashboard/propuesta/{id}/rechazar` | `Admin::propuestaRechazar` | ✓ | ✗ |
| GET/POST | `/dashboard/usuarios*` | `Admin::usuarios*` | ✓ | ✗ |
| GET/POST | `/dashboard/temporada*` | `Admin::temporada*` | ✓ | ✗ |

### Autocomplete interno (AJAX, solo admin)

| GET | `/api/marcha/fastSearch` |
| GET | `/api/autor/fastSearch` |
| GET | `/api/banda/fastSearch` |

---

## 11. Añadir una nueva funcionalidad — patrón estándar

Ejemplo: añadir una nueva página pública `/hermandad/{slugAndId}`.

### Paso 1 — Añadir la query en `Repo.php`

```php
// php/app/src/Repo.php
public static function fetchHermandad(int $id): ?array {
    $db = Db::get();
    $stmt = $db->prepare('SELECT * FROM hermandad WHERE ID = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

### Paso 2 — Añadir el controlador en `Pages.php`

```php
// php/app/src/Pages.php
public static function hermandadDetail(array $params): void {
    [$slug, $id] = Slug::parse($params['slugAndId']);
    $hermandad = Repo::fetchHermandad($id);
    if (!$hermandad) Http::notFound();

    // Redirect a URL canónica si el slug no coincide
    $canonical = '/hermandad/' . Slug::make($hermandad['NOMBRE']) . '-' . $id;
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== $canonical) {
        Http::redirect($canonical, 308);
    }

    Http::cachePublic(3600);
    View::render('hermandad_detail', ['hermandad' => $hermandad], [
        'title'       => $hermandad['NOMBRE'] . ' — Marchas de Cristo',
        'description' => 'Hermandad ' . $hermandad['NOMBRE'],
        'jsonld'      => Seo::hermandad($hermandad),
    ]);
}
```

### Paso 3 — Registrar la ruta en `routes.php`

```php
// php/app/routes.php
$router->get('/hermandad/{slugAndId}', [Pages::class, 'hermandadDetail']);
```

### Paso 4 — Crear la plantilla en `templates/`

```php
<!-- php/app/templates/hermandad_detail.php -->
<h1><?= htmlspecialchars($hermandad['NOMBRE']) ?></h1>
...
```

### Paso 5 — Añadir al sitemap si procede

```php
// php/app/src/Pages.php → method sitemap()
// Añadir el recorrido de hermandades al XML generado
```

### Paso 6 — Añadir casos al smoke test

```php
// php/tools/ci_smoke.php
// Añadir fixture en ci_fixture.php y aserciones en ci_smoke.php
```

### Paso 7 — Lint y smoke antes de push

```bash
find php -name "*.php" | xargs php -l
php php/tools/ci_fixture.php /tmp/ci.db
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 -t php/public php/public/index.php &
php php/tools/ci_smoke.php http://127.0.0.1:8001
```

---

## 12. CI/CD — qué hace GitHub Actions

### `ci.yml` (en cada push/PR, excepto `main`/`pre`)

1. PHP 8.4 + extensiones: `pdo_sqlite`, `sqlite3`, `curl`, `mbstring`, `dom`, `intl`, `json`, `gd`
2. Lint: `find php -name "*.php" | xargs php -l`
3. Generar fixture: `php php/tools/ci_fixture.php /tmp/ci-mdc.db`
4. Levantar servidor embebido (4 workers)
5. Smoke tests: `php php/tools/ci_smoke.php http://127.0.0.1:8000` — debe pasar 82/82

### `deploy.yml` (push a `pre` o merge a `main`)

```
push a pre → ci (verify) → FTP mirror a PRE (lftp) → smoke remoto PRE
merge a main → ci → maintenance ON → FTP mirror a PRO → maintenance OFF → smoke remoto PRO
```

El pipeline **nunca toca**: `config.local.php`, `cover/`, `private/`, `.well-known/`, `env.php`.

**Secrets necesarios en GitHub**: `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`.

**Rollback de código**: en GitHub Actions → Deploy → *Run workflow* → elegir ref del commit anterior.

---

## 13. Seguridad — decisiones vigentes

| Decisión | Implementación |
|---|---|
| Producción de solo lectura | `Db::assertWritable()` → lanza `ReadOnlyModeException` si `env !== 'local'` |
| Allowlist de campos editables | `AdminRepo` — array explícito por entidad |
| Prepared statements | En toda query de `Repo`, `AdminRepo` y demás |
| Sesión HMAC-SHA256 | `Auth::signSession/verifySession` — sin JWT ni sesiones PHP |
| Passwords: PBKDF2-SHA512, 210.000 iteraciones | Auto-upgrade desde MD5 legado en primer login |
| Rate limiting de login | Persistido a fichero (compatible con PHP-FPM sin memoria compartida): 6 intentos / 15 min, bloqueo 15 min |
| CSRF | Token derivado de la sesión, sin tabla adicional |
| Headers de seguridad | `Http::securityHeaders()` — CSP, HSTS, X-Frame-Options, etc. |
| Propuestas como ficheros | Editores sin vía de escritura directa a la BD |
| `.db` fuera del webroot | `private/mdc.db` en HelioHost, `php/data/mdc.db` en local |

---

## 14. Herramientas externas (offline, no en el servidor)

### Ingesta YouTube (`tools/ingest/`, Node.js ≥ 18)

```bash
cd tools/ingest
# Necesita yt-dlp instalado en el PATH del sistema
node extract.mjs     # fase 1: descarga metadatos de canales en config/canales.csv
node classify.mjs    # fase 2: clasifica y extrae campos (título, autor, banda...)
node dedup.mjs       # fase 3: dedup contra el mdc.db local
# Resultado: candidatos listos para importar con php/app/tools/import_candidatos.php
```

### Matching streaming (`tools/music_links/`, Python 3)

```bash
cd tools/music_links
python descubrir_marchas.py   # lee catálogo de Spotify/Deezer/Apple por banda
python match_bandas.py        # empareja bandas por similitud
python match_discos.py        # empareja discos
python match_marchas.py       # empareja marchas → genera candidatos de enlace
# Resultado: candidatos en enlace_candidato para curar en /dashboard/enlaces
```

### Scripts de sync (requieren `.env.ftp` con credenciales de HelioHost)

```bash
php scripts/sync_db_to_prod.php             # subir BD a producción
php scripts/sync_propuestas_from_prod.php   # bajar propuestas de editores
```

---

## 15. Variables de configuración — referencia

Fichero: `php/app/config.local.php` (no versionado). Template en `config.local.example.php`.

| Clave | Default | Descripción |
|---|---|---|
| `env` | `'production'` | `'local'` habilita escrituras. El default bloquea por seguridad. |
| `debug` | `false` | Mostrar errores en pantalla. Nunca `true` en producción. |
| `db_path` | `DATA_DIR . '/mdc.db'` | Ruta al SQLite. No usar rutas absolutas de HelioHost. |
| `secret_key` | `''` | Firma HMAC de sesión. Mínimo 32 chars. Diferente en cada host. |
| `auth_cookie_name` | `'mdc_session'` | Nombre de la cookie de sesión. |
| `login_ttl_ms` | `28800000` | Duración de sesión: 8 horas. |
| `cookie_secure` | `false` | `true` en producción (HTTPS). |
| `login_max_attempts` | `6` | Intentos de login antes de bloqueo. |
| `login_window_ms` | `900000` | Ventana de rate limit: 15 min. |
| `login_lock_ms` | `900000` | Duración del bloqueo: 15 min. |
| `password_pbkdf2_iterations` | `210000` | Iteraciones PBKDF2-SHA512. |
| `backup_keep_days` | `60` | Días de retención de backups. |
| `site_url` | `'https://marchasdecristo.com'` | URL canónica. Sin trailing slash. |
| `force_canonical_host` | `false` | `true` en PRO: 301 de staging/www a `site_url`. |
| `cover_base_url` | `''` | En PRE: URL de PRO para cargar portadas. |
| `preproduccion` | `false` | `true` fuerza noindex. Lo pone `env.php`, no `config.local.php`. |
| `goatcounter_code` | `null` | Código de GoatCounter. `null` = desactivado. |
| `indexnow_key` | `null` | Clave IndexNow. Debe coincidir entre el host que hace ping y producción. |
| `mail_from` | `null` | `From:` de emails (M7). `null` = emails desactivados. |
| `mail_from_name` | `'Marchas de Cristo'` | Nombre del remitente. |
| `mail_admin_to` | `null` | Email del admin para notificaciones. |
| `notif_emails` | `[]` | Lista de emails adicionales para notificaciones. |

---

## 16. Deuda técnica activa (resumen)

Ver `technical-debt.md` para el detalle completo.

| Ref | Deuda | Severidad |
|---|---|---|
| `D-2.1` | Sin `PRAGMA integrity_check` sobre el backup + sin copia externa fuera de HelioHost | 🟡 Media |
| `D-4.1` | Tablas heredadas sin limpiar: sentinela `BANDA_ESTRENO = 0`, tablas muertas `videos` y `users` | 🟢 Baja |

No hay deuda 🔴 ni 🟠 abierta.

---

## 17. Qué NO hacer

Estas cosas están **explícitamente descartadas** o tienen restricciones:

- **No añadir Composer/vendor/**: el diseño sin dependencias externas es una decisión arquitectónica (ADR-001). Si necesitas una librería, impleméntala mínimamente en `src/`.
- **No instalar Tidal ni Amazon Music** como fuentes de ingesta: sin API pública de catálogo (ver `docs/plan-music-apps.md §3`).
- **No usar YouTube como fuente de descubrimiento de marchas** (solo como fuente de audio de una marcha ya conocida).
- **No añadir incipit musical (Plaine & Easie)**: coste desproporcionado para un mantenedor único (~5.000 incipits a mano).
- **No empezar nada de P3** del roadmap sin el tablero de KPIs activo (GoatCounter + Search Console revisados con cadencia fija + `R-07` en producción).
- **No escribir en la BD de producción directamente**, nunca, ni con scripts manuales.
- **No subir la BD desde producción a local** (el flujo es siempre local → producción).
- **No instalar certificados ni configurar DNS** desde código — eso es Plesk manual.

---

## 18. Lecturas recomendadas por tarea

| Si vas a... | Lee primero... |
|---|---|
| Entender el proyecto de cero | Este documento + `context.md` |
| Decidir qué hacer a continuación | `roadmap.md §2` (el backlog activo) |
| Añadir una nueva pantalla pública | `architecture.md §2` (flujo de petición) + `php/app/src/Pages.php` |
| Tocar el panel admin | `docs/admin-panel.md` + `php/app/src/Admin.php` + `php/app/src/AdminRepo.php` |
| Añadir una migración de BD | `docs/db-analysis.md` + `php/app/tools/sql/` |
| Trabajar con la ingesta | `docs/ingesta-streaming.md` + `tools/music_links/` |
| Configurar el entorno PRE/PRO | `docs/entornos.md` |
| Resolver un incidente de producción | `docs/monitoring.md` + `scripts/sync_db_to_prod.php` |
| Hacer una migración de datos one-shot | `php/app/tools/` (ver tabla en `context.md §3`) |
| Entender la autenticación | `php/app/src/Auth.php` + `php/app/src/Roles.php` |
| Añadir JSON-LD a una página nueva | `php/app/src/Seo.php` |

---

## 19. Contacto y accesos

- **Mantenedor**: Javier Guerra (`@JaviWarSVQ` en X/Twitter, `jaguerra27@gmail.com`)
- **Repositorio**: `https://github.com/jgcoronado/marchasdecristo`
- **HelioHost (hosting)**: credenciales en `.env.ftp` en la máquina del mantenedor — no versionadas
- **GoatCounter**: panel en `marchasdecristo.goatcounter.com` (acceso del mantenedor)
- **UptimeRobot**: panel del mantenedor, monitor sobre `https://marchasdecristo.com/health`

---

## Apéndice A — árbol de ficheros de configuración por entorno

```
LOCAL:
  php/app/config.php             ← defaults (versionado)
  php/app/config.local.php       ← secretos + env=local (NO versionado)
  php/data/mdc.db                ← BD maestra (NO versionada)

PRE (HelioHost):
  marchasdecristo.jaguerra27.helioho.st/  ← docroot
    index.php                             ← del repo (idéntico a PRO)
    env.php                               ← generado por deploy-pre job, NO en repo
  pre/app/                                ← código de PRE (espejo de php/app/)
    config.local.php                      ← creado a mano, NO versionado
  private/mdc.db                          ← BD compartida con PRO (solo lectura)

PRO (HelioHost):
  marchasdecristo.com/            ← docroot
    index.php
    .htaccess
    assets/
  app/                            ← código de PRO (espejo de php/app/)
    config.local.php              ← creado a mano, NO versionado
  private/mdc.db                  ← BD en producción
  private/.maintenance            ← fichero sentinela (activo = 503 servido)
  private/propuestas/
    pendientes/*.json             ← propuestas de editores pendientes
    aplicadas/*.json
    rechazadas/*.json
  backups/                        ← backups VACUUM INTO (retención 60 días)
```

---

## Apéndice B — Checklist de arranque de sesión

Cuando una nueva IA (o desarrollador) toma el relevo, esta es la secuencia mínima de verificación antes de hacer cualquier cosa:

```
[ ] 1. Leer este documento completo
[ ] 2. git log --oneline -20  → entender el estado reciente del repositorio
[ ] 3. git status             → verificar que no hay trabajo sin commitear
[ ] 4. Leer roadmap.md §2 y §4 → qué hay en curso y qué ramas están abiertas
[ ] 5. Verificar CI:
       - Abrir GitHub Actions → ver que el último CI está verde
       - Si hay una rama en PR, ver su estado
[ ] 6. Verificar PRE:
       - curl https://marchasdecristo.jaguerra27.helioho.st/health
       - Debe responder: entorno: pre, db: ok
[ ] 7. Identificar la rama de trabajo correcta:
       - Ramas activas en §4 del roadmap
       - La sesión actual trabaja en: claude/technical-docs-handoff-equjdd
[ ] 8. Verificar que la BD local está disponible:
       - ls php/data/mdc.db  (en sesiones cloud: no estará, solicitar al mantenedor)
```

---

*Este documento debe actualizarse cuando cambie algo fundamental del stack, el flujo de trabajo o el estado del proyecto. La frecuencia objetivo es: al abrir una sesión de trabajo larga, o cuando se completa un bloque P0/P1 del roadmap.*
