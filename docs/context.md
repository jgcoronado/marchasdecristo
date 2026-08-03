# Contexto del proyecto — marchasdecristo.com

> Última actualización: 2026-07-27 (sincronizado con las rutas/módulos reales tras la auditoría documental)
> Documento de entrada para nuevas sesiones.
> Documentos complementarios en esta misma carpeta:
> - [architecture.md](architecture.md) — diagrama, flujos y decisiones arquitectónicas.
> - [technical-debt.md](technical-debt.md) — deuda pendiente.
> - [roadmap.md](roadmap.md) — ⭐ **fuente única del trabajo futuro**: backlog priorizado, contraste con el consejo de sabios, ramas abiertas e histórico congelado.
> - [db-analysis.md](db-analysis.md) — auditoría de la base de datos.
> - [admin-panel.md](admin-panel.md) — panel de administración.
> - [consejo-de-sabios-2026-07.md](consejo-de-sabios-2026-07.md) — evaluación integral (DAFOs, plan de acción, catálogo de automatizaciones).
> - [ux-analysis-estado.md](ux-analysis-estado.md) — estado del análisis UX comparativo vigente (patrimoniomusical.com): qué está hecho, qué queda pendiente, decisiones de arquitectura clave (mapa, catálogo de municipios).

---

## 1. Visión general

**Marchas de Cristo** ([marchasdecristo.com](https://marchasdecristo.com)) es una base de datos de **música procesional** española. Permite consultar cuatro entidades relacionadas más dedicatorias/advocaciones:

| Entidad | Volumen | Descripción |
|---------|-----------------|-------------|
| Marchas | **~5 000** (2026-07-28) | Marchas procesionales (título, fecha, dedicatoria, banda de estreno, autores, estilo CCTT/AM) |
| Autores | ~830 (2026-07-06) | Compositores |
| Bandas | ~270 (2026-07-06) | Formaciones musicales (con linaje/relaciones entre bandas) |
| Discos | ~430 (2026-07-06) | Grabaciones (CDs) que contienen marchas |
| Dedicatorias | — | Hubs de advocación con alias unificados |

> **Los recuentos llevan fecha a propósito.** El de marchas se actualizó con la
> primera pasada de la ingesta de streaming (2026-07-28, que leyó la BD local:
> 5.003 marchas). Los otros tres son del cutover y **no se han vuelto a medir**;
> la cifra de ~4 200 marchas que aún aparece en `consejo-de-sabios-2026-07.md`
> es de esa misma foto y no se corrige ahí porque ese documento es una
> evaluación puntual, no un tracker. Para el dato real de hoy: `/health` con
> sesión admin.

Relaciones principales: `marcha_autor` (N:N marcha↔autor), `disco_marcha` (N:N disco↔marcha, con pista), `marcha.BANDA_ESTRENO → banda.ID_BANDA`, `disco.BANDADISCO → banda.ID_BANDA`, `banda_relacion` (linaje entre bandas), `marcha.ID_DEDICATORIA → dedicatoria`.

**Audiencia**: aficionados a la música cofrade, picos de tráfico en Cuaresma/Semana Santa.
**Mantenedor único**: Javier Guerra ([@JaviWarSVQ](https://x.com/JaviWarSVQ)).

**Estado**: sitio migrado y **en producción** en `https://marchasdecristo.com` sobre el stack descrito abajo desde el cutover del 2026-07-04 (ver [pendientes-post-cutover.md](pendientes-post-cutover.md)). El stack Next.js/VPS/MySQL descrito en versiones anteriores de este documento es **historia**, no el sistema actual — ver §7.

---

## 2. Stack tecnológico (estado actual)

### Aplicación — PHP 8.4 plano, sin build step

- **PHP 8.4**, sin Composer ni `vendor/` — namespace `App\*` con un autoload PSR-4 mínimo propio en `bootstrap.php` (`spl_autoload_register`, `App\Foo\Bar` → `src/Foo/Bar.php`).
- **Front controller** (`public/index.php`) + **`App\Router`** minimalista: rutas registradas en `app/routes.php` con patrones `{param}` nombrados.
- **Plantillas PHP nativas** (`App\View`): cada vista se renderiza a un buffer y se inyecta como `$content` en `templates/layout.php`. Sin motor de plantillas externo.
- **Sin SSR/hidratación/JS framework**: HTML servido directo por PHP; JS del lado cliente es vanilla (`public/assets/admin.js`, `catalog.js`, `banda-relaciones.js`) solo para el panel admin y mejoras progresivas del catálogo público.
- **Deploy**: FTP manual a HelioHost (hosting compartido, panel Plesk). No hay build ni contenedor — los ficheros PHP se suben tal cual.

### Base de datos — SQLite embebido (PDO)

- **SQLite** vía **PDO** (`App\Db`, singleton lazy con prepared statements).
- Fichero `.db` **fuera del webroot** (`private/mdc.db` en producción, `php/data/mdc.db` en local) — nunca dentro de `public_html`.
- FTS5 (`marcha_fts`, `autor_fts`, …) para búsqueda full-text.
- **Producción es de solo lectura por diseño**: `Db::assertWritable()` lanza `ReadOnlyModeException` si `config['env'] !== 'local'`. El panel admin en HelioHost puede leer y navegar, pero cualquier escritura real (alta/edición) solo funciona en local — ver §5 "Flujo de datos: propuestas y sync".

### Auth / sesiones (`App\Auth`)

- Sesión firmada **HMAC-SHA256** propia (mismo formato que el Next.js original — compatibilidad de hashes conservada intencionadamente durante la migración, aunque ya no hay sistema Next.js corriendo en paralelo).
- Passwords: **PBKDF2-SHA512 / 210 000 iteraciones**, con auto-upgrade transparente desde MD5 legado en el primer login.
- **Rate limiting persistido a fichero** (no hay memoria compartida entre peticiones PHP-FPM/CGI de HelioHost): 6 intentos / 15 min, bloqueo 15 min.
- CSRF: token derivado de la sesión, validado en los formularios del panel.
- Roles (`App\Roles`): `admin` (acceso total) y `editor` (alta/edición de marchas, bandas y autores **vía propuestas**, sin acceso a ingesta, enlaces, dedicatorias, estilos, linaje ni gestión de usuarios).

### SEO

- URLs **slug-id** (`/marcha/consuelo-gitano-330`) con redirect **308** a la canónica si el slug no coincide o falta.
- JSON-LD (`App\Seo`) en todas las fichas de detalle y en los hubs de catálogo.
- `sitemap.xml` dinámico con `<lastmod>` real (derivado del último sync/edición) y `robots.txt`.
- **Ping IndexNow** tras cada sync a producción (`scripts/sync_db_to_prod.php`), más verificación de clave servida en `/{indexnow_key}.txt` (ruta condicional en `routes.php`, solo si `config['indexnow_key']` está definida).
- **Hubs indexables** de catálogo: `/marcha/ano/{yyyy}`, `/marcha/estilo/{slug}`, `/marcha/provincia/{slug}` — con `noindex` automático si el hub tiene menos de `Repo::HUB_MIN_MARCHAS` (2) resultados, para no publicar páginas finas.
- **`og:image` dinámica por entidad** (`App\Og`, ruta `/og/{tipo}/{id}.png`): tarjeta social generada con GD+FreeType a partir de los datos reales de la ficha (título, autor, banda…), cacheada a disco. El `og-image.png` de marca es solo el **fallback** — se usa cuando la generación falla o la entidad no existe (redirect 302), no el caso normal.
- **Puente de URLs heredadas** (`App\Legacy`): las URLs `.html` del sitio MySQL original (p.ej. `…-marcha-730.html`) que Google tenga indexadas se traducen a su ficha canónica nueva con **301**, para no perder el posicionamiento histórico. Se intenta justo antes de servir el 404 (`routes.php`, `notFound`).
- **API JSON pública de solo lectura** (`App\Api`, M1): `/api/{marcha,autor,banda,disco}/{id}.json` — mismas lecturas que el HTML, forma estable, licencia CC BY 4.0, documentada en `/datos`. Además `/feed.xml` (RSS), `/feed.json` y `/llms.txt`.

### Otras páginas de catálogo (más allá de las 4 entidades)

- **Búsqueda global** (M3): `/buscar` + `/api/buscar` (autocompletado JSON).
- **Rankings** (N-07): `/rankings` (índice) + `/rankings/{anio}` (drill-down).
- **Aniversarios** (N-09): `/aniversarios` + `/aniversarios/{anio}` (tramos de 25 en 25, centenarios destacados). `/estadisticas` es ahora un 301 permanente a `/rankings`.
- **Mapa** (N-10, `App\Mapa`): `/mapa` (coropleta SVG por provincia) + `/mapa/provincia/{slug}` (municipios como puntos clicables, zoom/pan). Lee coordenadas del catálogo `municipio` (`App\MunicipioRepo`) — ver `docs/ux-analysis-estado.md` §4 para el detalle.
- **Temporada** (N-04/N-05): `/temporada` + `/temporada/{anio}` — contratos banda↔hermandad de una Semana Santa, con alta manual desde el panel (`/dashboard/temporada/{anio}`).
- **Dedicatorias** (N-01/N-02): `/dedicatorias` (índice A-Z) + `/dedicatoria/{slugAndId}` (hub de advocación con alias unificados).

> ⚠️ **Cuatro de esas secciones solo se publican en local** mientras maduran: dedicatorias, estado del catálogo, mapa y temporada. En PRE y PRO responden 404 y no aparecen en el nav, el sitemap ni `llms.txt`. La lista, el motivo de cada una y cómo republicarlas están en `App\Secciones` y en [entornos.md](entornos.md).

### Infraestructura

- **HelioHost** (hosting compartido) con panel **Plesk**, dominio `marchasdecristo.com`.
- Webroot del dominio (`public_html`-equivalente) recibe el contenido de `php/public/`; `app/` y `private/` (con el `.db`) quedan **fuera** del webroot, como hermanos.
- Apache sirve los estáticos (caché 30 días vía `.htaccess`); Plesk tiene desactivado "Serve static files directly by nginx".
- **Sin Docker, sin CI/CD de deploy** (el deploy de código y de BD son procesos manuales por FTP — ver §5).
- **Backups**: `app/tools/backup.php` (VACUUM INTO + retención `backup_keep_days`, 60 días por defecto) vía cron de Plesk.
- **CI de verificación** (no de deploy): GitHub Actions en cada push/PR — lint (`php -l`) + smoke tests contra una BD fixture con el servidor embebido de PHP. Ver [technical-debt.md](technical-debt.md) y `.github/workflows/ci.yml`.

---

## 3. Estructura del repositorio

```
mdc-back/
├── .github/workflows/ci.yml       # Lint + fixture + smoke tests en cada push/PR
├── docs/                          # Esta carpeta
├── scripts/
│   ├── sync_db_to_prod.php        # Sube el .db local → HelioHost por FTP (con guardarraíles, ver arquitectura §3.6)
│   └── sync_propuestas_from_prod.php  # Baja las propuestas de editores desde producción
│
└── php/
    ├── README.md                  # Guía de desarrollo local y deploy (más detallada que este documento)
    ├── public/                    → contenido de public_html/ en HelioHost
    │   ├── index.php                front controller
    │   ├── .htaccess                mod_rewrite: todo → index.php (estáticos reales tal cual)
    │   └── assets/                  app.css, admin.js, catalog.js, banda-relaciones.js, mapa.js,
    │                                 mapa-provincias.svg (+.README.md), og-image.png, favicon.svg
    ├── app/                        → FUERA de public_html (hermano, p.ej. /home/USER/app)
    │   ├── bootstrap.php             autoload + config + maintenance check + dispatch
    │   ├── config.php                defaults (sin secretos)
    │   ├── config.local.example.php  plantilla de config.local.php (no versionado, tiene los secretos)
    │   ├── routes.php                todas las rutas (públicas, admin, API interna, SEO)
    │   ├── src/
    │   │   ├── Router.php            router mínimo con parámetros nombrados
    │   │   ├── Db.php                PDO/SQLite singleton + ReadOnlyModeException
    │   │   ├── Repo.php              lecturas públicas (marchas, autores, bandas, discos, hubs, home)
    │   │   ├── Pages.php             controladores de páginas públicas (incluye rankings, aniversarios, mapa, temporada, dedicatorias, buscar)
    │   │   ├── Api.php               API JSON pública de solo lectura (M1, licencia CC BY 4.0)
    │   │   ├── Og.php                og:image dinámica por entidad (GD+FreeType, cacheada a disco)
    │   │   ├── Legacy.php            puente 301 de URLs .html del sitio MySQL original
    │   │   ├── Mapa.php              coropleta SVG de provincias + puntos por municipio (N-10)
    │   │   ├── MunicipioRepo.php     catálogo cerrado de municipios (localidad→provincia, coords del mapa)
    │   │   ├── Seo.php                JSON-LD (schema.org)
    │   │   ├── Slug.php              slugify + construcción/parseo de slug-id
    │   │   ├── Html.php               componentes de presentación reutilizables (paginación, streaming, portadas, selector de municipio)
    │   │   ├── Http.php               helpers HTTP (redirect, cache-control, 404, maintenance 503)
    │   │   ├── View.php               renderizado de plantillas
    │   │   ├── Auth.php               sesión HMAC, PBKDF2/MD5, rate limit a fichero (con purga de entradas expiradas), CSRF
    │   │   ├── Admin.php              controladores del panel (PRG)
    │   │   ├── AdminRepo.php          escrituras admin (allowlists, transacciones, audit log, fijarMunicipio)
    │   │   ├── Roles.php              capacidades admin/editor (`Roles::has($rol, $cap)`)
    │   │   ├── PropuestaRepo.php      propuestas de editor en JSON de fichero (no en BD) — `PropuestaRepo::create()`
    │   │   ├── IngestaRepo.php        lecturas de candidatos de ingesta (YouTube)
    │   │   ├── EnlaceRepo.php         lecturas de candidatos de streaming (Spotify/Apple/Deezer)
    │   │   ├── UserRepo.php           gestión de usuarios del panel
    │   │   ├── Similarity.php         similitud de texto (dedup, ingesta)
    │   │   └── Media.php              extracción de ID de YouTube
    │   ├── templates/                 layout.php + vistas públicas + admin/
    │   └── tools/                     scripts de mantenimiento/backfill (ver tabla abajo)
    ├── data/                       → FUERA de public_html en local (hermano de public/ y app/)
    │   └── mdc.db                    BD de desarrollo (no versionada)
    └── tools/                      # Utilidades de desarrollo/CI (no de producción)
        ├── ci_fixture.php            genera una BD SQLite determinista para CI
        ├── ci_smoke.php               suite de smoke tests contra el servidor embebido (81 casos y creciendo con cada
        │                              feature — ver el conteo real que imprime el propio script en vez de fiarse de un
        │                              número fijo en la documentación)
        ├── parity_compare.php         (histórico) comparaba Repo.php vs. api.ts durante la migración
        └── parity_expected.cjs        (histórico) generador del JSON esperado desde better-sqlite3
```

### `php/app/tools/` — scripts operativos (ejecución manual, fuera del ciclo de request)

| Script | Qué hace |
|---|---|
| `migrate_ingest.php` | Runner genérico: aplica en orden alfabético todos los `.sql` de `app/tools/sql/` (`CREATE ... IF NOT EXISTS`, idempotente). Es como se crean todas las tablas nuevas (ingesta, dedicatoria, enlaces, contrato, municipio). |
| `backup.php` | Copia consistente del `.db` (`VACUUM INTO`) + purga por retención. Vía cron de Plesk. |
| `completar_provincia.php` | Backfill de `PROVINCIA` en marchas/bandas a partir de `LOCALIDAD`. |
| `export_marchas.php` | Exporta el catálogo a un formato plano (soporte a tareas puntuales). |
| `import_candidatos.php` / `load_canales.php` / `reevaluar_ingesta.php` | Pipeline de ingesta de candidatos de marcha desde YouTube (alimentan `/dashboard/ingesta`). |
| `migrate_banda_relacion.php` | Migración puntual (one-shot) del linaje de bandas: de columnas `FORMACION_ANT/SIG` a la tabla `banda_relacion`. |
| `migrate_marcha_estilo.php` | Migración puntual (one-shot) de clasificación de estilo (CCTT/AM): añade `marcha.ESTILO`. |
| `migrate_roles.php` | Migración puntual (one-shot) de roles de usuario: añade `usuarios.ROL`. |
| `seed_dedicatorias.php` | Siembra inicial de dedicatorias/alias para los hubs N-01/N-02. |
| `seed_municipios.php` | Siembra el catálogo `municipio`: ~8.112 municipios oficiales (INE) + los pares LOCALIDAD/PROVINCIA ya usados en marcha/banda que no encajen. |
| `fill_estilo_por_banda.php` | Backfill de `ESTILO` en marchas sin clasificar, por el estilo mayoritario de su banda de estreno. Re-ejecutable, con backup previo. |
| `fill_enlaces_streaming.php` | Cruza el catálogo de Spotify de cada banda (álbumes → discos, pistas → marchas) por similitud difusa; inserta enlaces verificados o candidatos a curar en `/dashboard/enlaces`. |
| `normalizar_localidades.php` / `normalizar_preposiciones_localidad.php` / `corregir_acentos_localidad.php` | Limpieza puntual (one-shot) de variantes de mayúsculas/acentos/preposiciones en `LOCALIDAD`/`PROVINCIA`, previas al catálogo `municipio`. |
| `reconciliar_alias_localidad.php` | Resuelve a mano los casos ambiguos que el trigger de sincronización `dedicatoria_alias`↔`marcha.LOCALIDAD` no puede decidir solo. |

---

## 4. Configuración (`app/config.local.php`, no versionado)

Plantilla en `app/config.local.example.php`. Claves relevantes (con sus defaults en `app/config.php`):

```php
'debug'            => false,
'env'              => 'production',   // SOLO 'local' habilita escrituras — ver Db::assertWritable()
'site_url'         => 'https://marchasdecristo.com',
'force_canonical_host' => false,      // true en producción → 301 de staging/www a site_url
'db_path'          => ...,            // ruta al .db, fuera del webroot
'secret_key'       => '',             // firma HMAC de sesión — generar por host, no compartir con el histórico Next.js
'auth_cookie_name' => 'mdc_session',
'login_ttl_ms'     => 8h,
'cookie_secure'    => false,          // true en producción
'login_max_attempts' / 'login_window_ms' / 'login_lock_ms',
'password_pbkdf2_iterations' => 210000,
'backup_keep_days' => 60,
'goatcounter_code' => null,           // analítica opcional (GoatCounter), null = desactivada
'indexnow_key'     => null,           // debe coincidir entre el host admin (envía el ping) y producción (sirve /{clave}.txt)
```

`DATA_DIR`/`APP_DIR` se definen en `bootstrap.php` a partir de `dirname(__DIR__)`, así que `app/` y `data/`(o `private/` en el host) deben ser siempre hermanos del document root.

---

## 5. Flujo de datos: propuestas y sync (patrón central del proyecto)

Producción es **de solo lectura** para la base de datos (`Db::assertWritable()`). El ciclo editorial real es:

1. **Editores** trabajan contra producción, pero sus altas/ediciones no tocan el `.db`: `AdminRepo`/`Roles` las desvía a **`PropuestaRepo`**, que las serializa como JSON en `private/propuestas/pendientes/<id>.json` (fichero, no fila de BD). **Fuera de local esto vale para todos**: desde el 2026-08-03 el administrador tampoco escribe directo en PRE ni en PRO (`Admin::proposalMode()`), porque sus cambios ahí se perderían en el siguiente sync. Ver [entornos.md](entornos.md).
2. El **admin** baja esas propuestas a local con `scripts/sync_propuestas_from_prod.php` (FTP), las revisa en `/dashboard/propuestas` y, al aceptarlas, se aplican sobre el `.db` **local** reutilizando `AdminRepo` (donde `env=local` sí permite escribir).
3. Cuando hay cambios acumulados en local (propuestas aplicadas, altas manuales, backfills de `app/tools/`), el admin sincroniza con **`scripts/sync_db_to_prod.php`**, que:
   - Verifica que no queden propuestas pendientes sin bajar de producción (guardarraíl no bloqueante: avisa si el listado FTP falla, p.ej. la primera vez que el directorio no existe).
   - Activa un **modo mantenimiento** (fichero sentinela `.maintenance` junto al `.db`, comprobado en `bootstrap.php` antes de enrutar cualquier petición — sirve un 503 con `Retry-After`) durante el reemplazo del `.db` en el host.
   - Sube el `.db` por FTP y verifica su integridad por **checksum SHA-256** re-descargando el fichero subido; si no coincide, hace **rollback automático** (renombra de vuelta el `.db` anterior en el FTP).
   - Desactiva el modo mantenimiento (con `register_shutdown_function` como red de seguridad si el script termina de forma anómala).
   - Hace **ping a IndexNow** con las URLs del sitemap (opcional, con `--skip-indexnow`).
   - Flags disponibles: `--skip-verify`, `--skip-indexnow`, `--force`.

Este patrón evita que el admin pise nunca una propuesta de editor no revisada, y evita que producción quede nunca con un `.db` a medio subir.

---

## 6. Patrones y convenciones vigentes

- **Fail-safe de solo lectura por entorno** (`env=local`) en vez de por flag de "modo mantenimiento" manual — imposible olvidarse de desactivarlo en producción.
- **Allowlist explícita** de campos editables en `AdminRepo` — previene mass-assignment.
- **Prepared statements** siempre vía `App\Db`.
- **Slug + ID en URLs**, con 308 a la canónica — SEO-friendly, robusto frente a renombres.
- **Propuestas como ficheros JSON**, no filas de BD — el editor nunca tiene una vía de escritura directa a producción, ni siquiera indirecta a través de una tabla.
- **Modo mantenimiento por sentinela de fichero**, comprobado antes que ninguna otra cosa en `bootstrap.php`.
- **Sync con checksum + rollback automático** — la operación más peligrosa del sistema (reemplazar el `.db` de producción) es la más verificada.
- **CSRF derivado de la sesión**, sin tabla ni almacenamiento adicional.
- **Auto-upgrade** de contraseñas MD5 → PBKDF2 en primer login.
- **Rate limiting a fichero** (no en memoria — PHP en HelioHost no garantiza proceso persistente entre requests).
- **Hubs con `noindex` condicional** (`HUB_MIN_MARCHAS`) — evita publicar páginas finas que dañarían el SEO en vez de ayudarlo.
- **CI de verificación en cada push** (lint + smoke tests con fixture determinista) — ver [technical-debt.md](technical-debt.md) para lo que **no** cubre todavía.

Antipatrones/deuda activa: ver [technical-debt.md](technical-debt.md).

---

## 7. Nota histórica — stack anterior (Next.js/VPS/MySQL)

Antes del cutover a PHP (2026-07-04), el proyecto corrió sobre **Next.js 15 + React 19 + better-sqlite3 (y antes, MySQL) en un VPS con Docker Compose + Nginx**. Ese stack está **completamente desmantelado** (o pendiente de desmantelar el VPS como rollback temporal — ver [pendientes-post-cutover.md](pendientes-post-cutover.md), tarea 5). No queda código Next.js en este repositorio salvo en el historial de git.

Documentos que describían ese stack en detalle y que se conservan como **archivo histórico** (no vigentes, no describen el sistema actual):
- [archive/redesign-options.md](archive/redesign-options.md) — evaluación de alternativas tecnológicas que llevó a la decisión de migrar a PHP.
- [archive/vps-migration-3b.md](archive/vps-migration-3b.md) — guía de la migración de Express → Next.js Route Handlers en el VPS (fase intermedia, previa al cutover a PHP).

Para la migración a PHP en sí, ver `php/README.md` (fases 0-5, todas completadas salvo el cierre operativo de [pendientes-post-cutover.md](pendientes-post-cutover.md)) y [cutover-fase5.md](cutover-fase5.md) (runbook ejecutado).
