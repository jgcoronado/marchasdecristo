# Arquitectura — marchasdecristo.com

> Última actualización: 2026-07-27 (sincronizado con las rutas/módulos reales tras la auditoría documental; el histórico Next.js/VPS vive solo en `docs/archive/` y en git log)

## 1. Diagrama de componentes

```
                          Internet (HTTPS :443)
                                  │
                                  ▼
              ┌──────────────────────────────────────────┐
              │     Apache (HelioHost, Plesk)             │
              │  TLS · .htaccess (mod_rewrite + caché     │
              │  estáticos 30d) · sirve public_html/      │
              └──┬─────────────────────────────────────┬──┘
                 │                                      │
     estático real (css/js/img)          todo lo demás → index.php
     (servido directo, sin PHP)                         │
                 │                                      ▼
                 │                     ┌───────────────────────────────┐
                 │                     │   public/index.php            │
                 │                     │   (front controller)          │
                 │                     └───────────────┬───────────────┘
                 │                                      │  require
                 │                                      ▼
                 │                     ┌───────────────────────────────┐
                 │                     │   app/bootstrap.php           │
                 │                     │   1. autoload App\*           │
                 │                     │   2. config.php + local       │
                 │                     │   3. check .maintenance ──────┼──► 503 + Retry-After (si activo)
                 │                     │   4. require routes.php       │
                 │                     │   5. Router::dispatch()       │
                 │                     └───────────────┬───────────────┘
                 │                                      │
                 │                     ┌────────────────┴────────────────┐
                 │                     ▼                                 ▼
                 │        Pages / Admin (controladores)       Repo / AdminRepo / *Repo
                 │        renderizan vía View → layout.php     (capa de datos, PDO)
                 │                     │                                 │
                 │                     ▼                                 ▼
                 │              HTML + JSON-LD                 ┌──────────────────┐
                 │                                              │  private/mdc.db  │
                 │                                              │  (SQLite, fuera  │
                 │                                              │  del webroot)    │
                 │                                              └──────────────────┘
                 ▼
            Cliente (navegador / bot de indexación)
```

**Un único proceso PHP por petición**, sin servicios separados: páginas públicas, panel admin y "API" interna (autocompletados, fastSearch) se sirven todos desde el mismo front controller. No hay contenedor, no hay build, no hay proceso persistente — cada request es un arranque de PHP nuevo (modelo estándar de hosting compartido con Apache/PHP-FPM o CGI).

Fuera del ciclo de request, dos procesos **manuales** (ejecutados desde el equipo del mantenedor, no desde el servidor) cierran el ciclo de datos:

```
   equipo local                                    HelioHost (producción)
  ┌──────────────┐   sync_propuestas_from_prod.php  ┌──────────────────────┐
  │ mdc.db local │ ◄──────────── FTP (descarga) ──── │ private/propuestas/  │
  │ (env=local,  │                                   │ pendientes/*.json    │
  │  escribible) │                                   │                       │
  │              │   sync_db_to_prod.php             │                       │
  │              │ ── FTP (sube .db + checksum) ───► │ private/mdc.db        │
  └──────────────┘   [.maintenance activo durante    │ (env=production,      │
                       el swap; rollback si el        │  solo lectura)        │
                       checksum no coincide]          └──────────────────────┘
```

---

## 2. Flujos de petición

### 2.1 Visita pública a `/marcha/consuelo-gitano-330`
1. **Cliente** → Apache → `index.php` (no coincide con ningún fichero estático real).
2. `bootstrap.php`: autoload, config, comprobación de `.maintenance` (si existe, corta aquí con 503), `require routes.php`, `Router::dispatch()`.
3. `Router` matchea `/marcha/{slugAndId}` → `Pages::marchaDetail(['slugAndId' => 'consuelo-gitano-330'])`.
4. `Pages::marchaDetail` extrae `id=330` (vía `App\Slug`), llama a `Repo::fetchMarcha(330)`.
5. `Repo::fetchMarcha` ejecuta SQL preparado contra SQLite vía `App\Db` (PDO, singleton lazy).
6. Si el slug de la URL no coincide con el slug canónico actual del título → `Http::redirect(canónica, 308)` antes de renderizar nada.
7. `Pages` construye `$meta` (title, description, JSON-LD vía `Seo::marcha()`, og:image) y llama a `View::render('marcha_detail', $data, $meta)`.
8. `View` captura la plantilla en un buffer, lo inyecta como `$content` en `templates/layout.php` (que añade `<head>` con meta/OG/JSON-LD, nav, footer).
9. Apache devuelve el HTML con las cabeceras de caché que fije `Http::cachePublic()` (1h para detalles).

### 2.2 Búsqueda pública `/marcha?titulo=cristo`
- `Pages::marchaList` → `Repo` ejecuta FTS5 (`marcha_fts MATCH ?`) sin caché (`Http::noStore()` — los resultados de búsqueda no se cachean).

### 2.3 Hub indexable `/marcha/estilo/cctt`
- Ruta registrada **antes** que el catch-all `/marcha/{slugAndId}` en `routes.php` (dos segmentos, no colisiona).
- `Pages::marchaEstiloHub` → `Repo::marchasDeEstilo()` + `Repo::hubEstilos()` (para el selector de otros hubs).
- Si el resultado tiene menos de `Repo::HUB_MIN_MARCHAS` (2) filas → `noindex` en el `<meta>` (evita indexar páginas finas), pero la página se sirve igualmente (no es un 404).
- JSON-LD `CollectionPage` vía `Seo::marchaHub()`.

### 2.4 Login admin
1. **Cliente** → `/login` → `Admin::loginForm` (GET) / `Admin::loginPost` (POST).
2. `Admin::loginPost`: rate limit (`Auth`, persistido a fichero — sin memoria compartida entre peticiones en hosting compartido), busca usuario en SQLite, verifica password (PBKDF2 o MD5 legado con auto-upgrade).
3. Si OK: `Auth::signSession` (HMAC-SHA256) → cookie `mdc_session` HttpOnly (+ Secure si `cookie_secure`) → redirect 302 a `/dashboard` (patrón **PRG**: Post/Redirect/Get, sin reenvíos de formulario).
4. Cada ruta `/dashboard/*` empieza verificando `Auth::currentSession()` — sin middleware de framework, es una comprobación inline al principio del controlador.

### 2.5 Edición de marcha (admin, en local)
1. `Admin::marchaEditForm` (GET) muestra el formulario con los valores actuales.
2. `Admin::marchaEditPost` (POST): valida CSRF, llama a `AdminRepo::updateMarcha($id, $payload)`.
3. `AdminRepo` filtra el payload contra una allowlist de campos editables, arma el `UPDATE` con prepared statement, escribe en `admin_log` (audit log).
4. **Antes de cualquier escritura real**, `Db::assertWritable()` comprueba `config['env'] === 'local'`. Si el host es producción, lanza `ReadOnlyModeException`, que `Router::dispatch()` captura y muestra `templates/readonly.php` en vez de un error crudo.
5. PRG: redirect a la misma edición con un flash de éxito.

### 2.6 Edición de marcha (editor, en producción) — propuesta, no escritura directa
1. Igual que 2.5 hasta el POST, pero `Admin::isAdmin($session)` (que llama a `Roles::isAdmin($rol)`) es `false` para `editor`, así que cada controlador marca `proposalMode = true`.
2. `Admin` desvía a `PropuestaRepo::create(...)`, que serializa el cambio propuesto como JSON en `private/propuestas/pendientes/<id>.json`. **No toca el `.db`.**
3. El admin la revisa después en local — ver §2.7 y `docs/context.md` §5.

### 2.7 Sync local → producción (`scripts/sync_db_to_prod.php`)
1. Comprueba backup reciente del `.db` local (guardarraíl preexistente, no se toca).
2. Lista `private/propuestas/pendientes/` en producción por FTP (`ftpListOptional` — no aborta si el directorio aún no existe, solo avisa) para asegurarse de que no hay propuestas nuevas sin bajar.
3. Sube el fichero **`.maintenance`** por FTP → a partir de aquí, cualquier request a producción recibe 503 (`Http::maintenance()`, `bootstrap.php` lo comprueba antes de enrutar).
4. Sube el `.db` nuevo por FTP.
5. Re-descarga el `.db` subido y compara su **SHA-256** contra el local. Si no coincide: `DELE` + `RNFR`/`RNTO` para restaurar el `.db` anterior (rollback automático) y aborta con error.
6. Borra `.maintenance` por FTP (`register_shutdown_function` como red de seguridad si el script muere a mitad).
7. Hace ping a **IndexNow** con las URLs del sitemap (a menos que `--skip-indexnow`).
8. Flags: `--skip-verify` (omite el paso 5), `--force` (omite el paso 2), `--skip-indexnow` (omite el paso 7).

### 2.8 Sitemap dinámico
- `Pages::sitemap` → `Repo` recorre marchas/autores/bandas/discos/hubs y genera el XML al vuelo (`Http::cachePublic(3600)`), con `<lastmod>` real por entidad.

### 2.9 API JSON pública `/api/marcha/330.json`
- `App\Api` (M1): mismas lecturas que el HTML servido por `Pages`/`Repo`, en JSON con forma estable. Licencia CC BY 4.0, documentada en `/datos`. El `{id}` admite número o slug-id; `Api` extrae el numérico igual que `Pages`.

### 2.10 og:image dinámica `/og/marcha/330.png`
- `App\Og::render()`: genera una tarjeta social con GD+FreeType a partir de los datos reales de la entidad (título, autor, banda…), la cachea a disco (no se regenera en cada visita) y la sirve como PNG. Si la entidad no existe o la generación falla, redirect 302 al `og-image.png` de marca (el fallback, no el caso normal).

### 2.11 Mapa `/mapa` → `/mapa/provincia/{slug}`
- `Pages::mapa` pinta la coropleta SVG de las 52 provincias (`App\Mapa`, color por concentración de marchas). `Pages::mapaProvincia` recorta el viewBox a una provincia y añade cada municipio como punto clicable, con coordenadas leídas de `App\MunicipioRepo` (tabla `municipio`) en vez de un fichero estático. Detalle completo (zoom/pan, niveles de color, exclusión de Canarias) en `docs/ux-analysis-estado.md` §4.

---

## 3. Decisiones arquitectónicas (ADRs)

### ADR-001 · PHP plano sin framework, un solo front controller ✅ Vigente
- **Contexto**: HelioHost es hosting compartido (sin Docker, sin acceso root, deploy solo por FTP). Componer una app Node/Docker allí no es viable con el plan actual; sí lo es un `.php` plano detrás de Apache.
- **Decisión**: `public/index.php` como único punto de entrada, router propio (`App\Router`) con parámetros nombrados, sin Composer/`vendor/`.
- **Tradeoffs**: cero dependencias que actualizar, deploy = copiar ficheros. `bootstrap.php` registra un autoload PSR-4 mínimo propio (`spl_autoload_register`, `App\Foo\Bar` → `src/Foo/Bar.php`) sin necesidad de Composer. A cambio: sin gestor de paquetes para librerías de terceros (aceptable dado el tamaño del proyecto).
- **Reemplaza**: ADR-001 original (Next.js como único contenedor). Superado por el cutover del 2026-07-04.

### ADR-002 · SQLite embebido vía PDO (antes: better-sqlite3/MySQL) ✅ Vigente
- **Decisión**: `App\Db`, singleton lazy con PDO/SQLite, prepared statements en toda consulta. `.db` fuera del webroot.
- **Tradeoffs**: sin servidor de BD separado, backup = copiar un fichero (`VACUUM INTO`). Sin concurrencia de escritura entre procesos (aceptable: un solo escritor, el admin en local).
- **Diferencia con el port original**: la serialización de autores no usa `json_group_array` (el SQLite 3.34 de HelioHost puede no traer JSON1) — se agrupan en PHP (`Repo::autoresFor()`).

### ADR-003 · Producción de solo lectura por entorno (`env=local`) ✅ Vigente
- **Contexto**: con un único mantenedor y deploy manual, escribir por accidente en el `.db` de producción (o dejar un "modo mantenimiento" olvidado activado) es el riesgo operativo más alto.
- **Decisión**: `Db::assertWritable()` comprueba `config['env'] === 'local'` antes de cualquier escritura. El default de `config.php` es `'production'` — cualquier host mal configurado (staging, un despliegue nuevo sin `config.local.php`) cae en modo lectura por defecto, no al revés.
- **Tradeoffs**: el admin solo puede escribir de verdad trabajando en local; en producción ve `readonly.php` si algo intenta escribir. A cambio: imposible corromper producción desde un fallo de configuración.

### ADR-004 · Propuestas de editor como ficheros JSON, no filas de BD ✅ Vigente
- **Contexto**: los editores trabajan contra producción (solo lectura), pero necesitan poder proponer cambios sin que eso implique una vía de escritura a la BD real.
- **Decisión**: `PropuestaRepo` serializa cada propuesta como `<id>.json` en `private/propuestas/{pendientes,aplicadas,rechazadas}/`. El admin las baja por FTP, las revisa en `/dashboard/propuestas` y, si las acepta, se aplican sobre el `.db` local reutilizando `AdminRepo`.
- **Tradeoffs**: el ciclo propuesta → aplicación tiene latencia (depende de que el admin sincronice). A cambio: cero superficie de escritura remota, sin necesidad de una tabla de "cambios pendientes" con su propia lógica de concurrencia.

### ADR-005 · Modo mantenimiento por fichero sentinela ✅ Vigente
- **Decisión**: `bootstrap.php` comprueba `is_file(dirname($db_path) . '/.maintenance')` como primer paso, antes de cargar rutas. Si existe, `Http::maintenance()` responde 503 + `Retry-After` + `noindex`.
- **Uso**: activado/desactivado por `sync_db_to_prod.php` (sube/borra el fichero por FTP) durante el swap del `.db`, con `register_shutdown_function` como red de seguridad si el script termina de forma anómala.
- **Tradeoffs**: ningún estado en BD ni caché que pueda quedar inconsistente — es un `is_file()`. A cambio: si el borrado del fichero por FTP falla silenciosamente, hay que poder detectarlo manualmente (no hay alerta automática todavía — ver `technical-debt.md`).

### ADR-006 · Sync con verificación por checksum y rollback automático ✅ Vigente
- **Decisión**: tras subir el `.db` a producción, `sync_db_to_prod.php` lo re-descarga y compara SHA-256 contra el local. Si difiere, restaura automáticamente el `.db` anterior por FTP (`DELE` + `RNFR`/`RNTO`).
- **Tradeoffs**: doble transferencia FTP (sube + re-descarga) en cada sync — coste aceptable frente al riesgo de un `.db` corrupto o truncado sirviendo en producción sin que nadie lo note.

### ADR-007 · Sesiones firmadas HMAC-SHA256 propias (sin JWT) ✅ Vigente
- **Decisión**: `Auth::signSession`/`verifySession` con las funciones nativas de hash de PHP. Sin dependencias externas.
- **Tradeoffs**: cero deps, control total; hay que mantener el código a mano.

### ADR-008 · URLs `slug-id` con redirect 308 desde slug/id no canónico ✅ Vigente
- **Decisión**: `/marcha/<slug>-<id>`. Si llega un slug distinto al actual (o falta), `Http::redirect(canónica, 308)`.
- **Tradeoffs**: un redirect extra si el título cambia. A cambio: SEO estable y enlaces históricos siguen funcionando. 308 (no 301) porque preserva el método HTTP.

### ADR-009 · Hubs de catálogo con `noindex` condicional (`HUB_MIN_MARCHAS`) ✅ Vigente
- **Decisión**: `/marcha/ano/{yyyy}`, `/marcha/estilo/{slug}`, `/marcha/provincia/{slug}` siempre responden 200 y son navegables, pero llevan `noindex` si tienen menos de `Repo::HUB_MIN_MARCHAS` (2) resultados.
- **Tradeoffs**: hay hubs "huérfanos" navegables pero no indexados — aceptable, evita que Google penalice por páginas finas masivas (miles de combinaciones año/estilo/provincia con 0-1 resultado).

### ADR-010 · CI de verificación (lint + smoke), sin CI de deploy ✅ Vigente
- **Contexto**: no hay forma de desplegar automáticamente a HelioHost (solo FTP manual), pero sí se puede verificar automáticamente cada push.
- **Decisión**: GitHub Actions ejecuta `php -l` sobre todo el código + levanta el servidor embebido de PHP contra una BD fixture determinista (`php/tools/ci_fixture.php`) y corre `php/tools/ci_smoke.php` (81 casos y creciendo: home, listados, detalles, hubs, redirects, 404, JSON-LD, sitemap/`lastmod`, API pública, og:image, mapa, rankings, aniversarios, temporada — no fijarse en el número, crece con cada feature).
- **Tradeoffs**: no protege producción en tiempo real (nadie bloquea un FTP manual si CI está roja) — depende de que el mantenedor mire el estado antes de sincronizar. Documentado como deuda operativa, no arquitectónica.

---

## 4. Patrones del código

### Controladores (`Pages`, `Admin`)
- **Un método estático por ruta**, recibe el array de parámetros capturado por el `Router`.
- **`fetchEntity(id) → Http::notFound()`** como patrón estándar de 404.
- **PRG** (Post/Redirect/Get) en todos los formularios admin — sin reenvío accidental.
- **Redirect a canónica** antes de renderizar, no después.

### Capa de datos (`Repo`, `AdminRepo`, `*Repo`)
- **Solo lectura vs. solo escritura, separadas por clase**: `Repo`/`IngestaRepo`/`EnlaceRepo` son de lectura pública o de panel; `AdminRepo` concentra las escrituras reales con allowlist + transacción + audit log.
- **Prepared statements** en cada query, sin excepción.
- **Sin ORM** — SQL explícito, mantenido fiel a la paridad con el sistema Next.js original donde aplicaba (ya no es una restricción activa, pero explica por qué algunas queries se ven "traducidas" en vez de reescritas desde cero).

### Vistas (`View`, `templates/`)
- **Buffer + inyección en layout** — sin motor de plantillas, PHP nativo con `<?= ?>` escapado por convención (`H::e()`/`htmlspecialchars` en los puntos de salida).
- **`$meta`** uniforme (`title`, `description`, `noindex`, `og`, `jsonld`) construido por el controlador, consumido por `layout.php`.
- **Componentes reutilizables en `Html.php`** (paginación, botonera de streaming, imagen de portada) en vez de duplicar HTML entre plantillas.

---

## 5. Buenas prácticas vigentes

1. Fail-safe de solo lectura por entorno, no por flag manual.
2. Allowlist explícita de columnas editables/insertables en `AdminRepo`.
3. Prepared statements en todas las queries.
4. Propuestas de editor como ficheros, no como vía de escritura a BD.
5. Modo mantenimiento por sentinela de fichero, verificado antes que ninguna otra cosa.
6. Sync con checksum + rollback automático.
7. CSRF derivado de la sesión, sin almacenamiento adicional.
8. Auto-upgrade transparente MD5 → PBKDF2 en primer login.
9. Rate limiting a fichero (correcto para hosting compartido sin memoria persistente).
10. Cache-Control diferenciado por tipo de contenido (detalle 1h, home/estadísticas 30min, búsquedas `no-store`, sitemap 1h, estáticos 30d).
11. `noindex` condicional en hubs finos — protege la calidad media indexada, no solo la cantidad.
12. CI de lint + smoke tests en cada push (red de seguridad del activo SEO — 81 aserciones y creciendo sobre rutas doradas).

---

## 6. Deuda técnica pendiente

Detallada y priorizada en [technical-debt.md](technical-debt.md). El resumen ejecutivo vive ahí; este documento no la duplica para no desincronizarse.
