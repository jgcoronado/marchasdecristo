# Panel de administración — marchasdecristo.com

> Última actualización: 2026-07-27 (§1-6 reescritas en términos de la implementación PHP real, ya no del diseño Next.js heredado) · 2026-07-12 (scripts de herramientas) · 2026-07-10 (curación de estilo CCTT/AM) · 2026-07-08 (relaciones de linaje de bandas)
> Documento complementario de [context.md](context.md) y [roadmap.md](roadmap.md).
> Todo el documento describe ya la implementación PHP actual (`php/app/src/Admin.php`
> + `AdminRepo.php` + `Auth.php` + `Roles.php` + plantillas en `php/app/templates/admin/`).
> El panel Next.js que existió antes del cutover (2026-07-04) ya no tiene ningún
> artefacto correspondiente — no era una traducción 1:1, así que este documento no
> lo describe ni por comparación.

---

## 1. Acceso

| | |
|---|---|
| **URL** | `https://marchasdecristo.com/dashboard` |
| **Login** | `https://marchasdecristo.com/login` |
| **Guard** | Cada ruta `/dashboard/*` empieza con `Auth::requireAuth()` (o `requireAdmin()`/`requireCap()` para las que lo necesitan) — comprobación inline al principio del controlador, sin middleware de framework |
| **Sesión** | Cookie `mdc_session` firmada HMAC-SHA256 (`Auth::signSession`/`verifySession`), HttpOnly (+ Secure si `cookie_secure`), TTL 8h |
| **Rate limit** | 6 intentos / 15 min por IP+usuario; bloqueo de 15 min; persistido a fichero (sin memoria compartida entre peticiones en HelioHost), con purga de entradas expiradas en cada escritura |
| **Contraseñas** | PBKDF2-SHA512 / 210 000 iteraciones; upgrade automático desde MD5 legado en el primer login exitoso |
| **CSRF** | Token derivado de la sesión (`Auth::csrfToken`/`checkCsrf`), validado en todos los POST del panel |
| **Roles** (`App\Roles`) | `admin` (acceso total, comodín `*`) y `editor` (capacidades `marcha.add/edit`, `banda.add/edit`, `autor.add/edit` vía `Roles::EDITOR_CAPS`; sin acceso a ingesta, enlaces, dedicatorias, estilos, linaje de bandas ni gestión de usuarios) |

El editor nunca escribe en la BD de verdad: sus altas/ediciones de marcha, banda y autor se guardan como **propuestas** (`PropuestaRepo::create()`, JSON de fichero) en vez de aplicarse directamente — ver §5 de `context.md`.

Quién escribe directo y quién propone lo decide `Admin::proposalMode($session)`, con **dos** condiciones y basta una:

| | local | PRE | PRO |
|---|---|---|---|
| **admin** | escribe directo | propone | propone |
| **editor** | propone | propone | propone |

El rol es la primera condición (el editor siempre propone). La segunda es el **entorno**: fuera de local no escribe nadie, tampoco el admin, porque la BD maestra es la local y `scripts/sync_db_to_prod.php` reemplaza el `.db` remoto entero — una escritura hecha en PRE o PRO se perdería en el siguiente sync, o pisaría datos buenos. Encolarla como propuesta la conserva (el admin la baja con `sync_propuestas_from_prod.php` y la aplica en local). Ver [entornos.md](entornos.md).

Solo hay propuesta para **marcha, banda y autor**. El resto de pantallas del panel (discos, dedicatorias, estilos, ingesta, enlaces, usuarios, temporada) escriben directo, así que en PRE y PRO chocan con `Db::assertWritable()` y devuelven el 503 de solo lectura. Siguen visibles para el admin —a veces hace falta *mirarlas* con datos reales— pero el panel avisa de dos formas: la cinta roja «PELIGRO: riesgo de desincronización» en todas sus pantallas (`layout.php`) y un aviso en `/dashboard` que detalla qué funciona y qué no.

---

## 2. Funcionalidades existentes

### `/dashboard` — Home
- Buscador (`q` para marcha/autor, `qb` para banda) que devuelve hasta 15 resultados de cada uno directamente en la home del panel.
- Contador de propuestas pendientes (solo visible para admin).
- Enlaces a alta de marcha/autor/banda, y al resto de secciones según capacidad del rol (ingesta, enlaces, dedicatorias, estilos, usuarios, propuestas — ocultos para editor).

### `/dashboard/marcha/{id}` — Edición de marcha
Campos editables (`AdminRepo::EDITABLE_MARCHA`): `TITULO`, `FECHA`, `DEDICATORIA`, `LOCALIDAD`, `PROVINCIA`, `AUDIO`, `BANDA_ESTRENO` (autocomplete, no ID a ciegas), `TIPO` (lista cerrada, `MARCHA_TIPOS`), `ESTILO` (CCTT/AM/sin asignar — ver [db-analysis.md](db-analysis.md)), `DETALLES_MARCHA`. Los autores de la marcha se pueden editar (añadir/quitar) desde el mismo formulario (`AdminRepo::editMarchaAutores`). Localidad/provincia usan el selector en cascada del catálogo `municipio` (§9).

### `/dashboard/marcha/add` — Alta de marcha
Mismos campos que la edición (`AdminRepo::INSERTABLE_MARCHA`) más autores (autocomplete multi, mínimo 3 caracteres). `AdminRepo::addMarcha` valida que todos los `autoresIds` existan (`allAutoresExist()`) y hace el INSERT en `marcha` + `marcha_autor` dentro de una transacción. Tras crear, redirige a `/dashboard/marcha/{id}?created=1` (PRG).

### `/dashboard/autor/add` y `/dashboard/autor/{id}` — Alta y edición de autor
Campos (`EDITABLE_AUTOR`): `NOMBRE`, `APELLIDOS`, `NOMBRE_ART`, `F_NAC`, `LUGAR_NAC`, `F_DEF`, `BIO`.

### `/dashboard/banda/add` y `/dashboard/banda/{id}` — Alta y edición de banda
Campos (`EDITABLE_BANDA`): `NOMBRE_COMPLETO`, `NOMBRE_BREVE`, `LOCALIDAD`, `PROVINCIA`, `FECHA_FUND`, `FECHA_EXT`, `DIRECTOR_ACTUAL`, `DIR_MUS_ACTUAL`, `WEB`. La edición de banda es también donde se gestiona el **linaje** (`banda_relacion`, solo admin) — ver §7.

Ninguno de estos formularios muestra SQL ni preview de la query — solo el diff/resumen del cambio antes de guardar donde aplica.

---

## 3. Rutas de escritura (`php/app/routes.php`)

No hay una "API de escritura" separada tipo Route Handlers: las escrituras son POST de formulario normales, con el mismo patrón **PRG** (Post/Redirect/Get) en todos.

| Ruta | Método | Handler | Descripción |
|------|--------|---------|-------------|
| `/dashboard/marcha/add` | POST | `Admin::marchaAddPost` → `AdminRepo::addMarcha` | Alta (transacción, valida autores) |
| `/dashboard/marcha/{id}` | POST | `Admin::marchaEditPost` → `AdminRepo::editMarcha` | Edición (allowlist, audit log) |
| `/dashboard/autor/add` / `/dashboard/autor/{id}` | POST | `Admin::autorAddPost`/`autorEditPost` | Alta/edición de autor |
| `/dashboard/banda/add` / `/dashboard/banda/{id}` | POST | `Admin::bandaAddPost`/`bandaEditPost` | Alta/edición de banda |

Además de autocompletados de solo lectura (`/api/autor/fastSearch`, `/api/banda/fastSearch`, `/api/municipio/fastSearch`, `/api/marcha/checkDuplicate`, `/api/dedicatoria/fastSearch`, `/api/banda/estilo`), todos con sesión requerida. Cada POST valida CSRF (`Auth::checkCsrf`) antes de tocar `AdminRepo`; antes de cualquier escritura real, `Db::assertWritable()` comprueba `config['env'] === 'local'` (producción es de solo lectura por diseño — ver ADR-003 en `architecture.md`).

---

## 4. Problemas de seguridad — ninguno abierto en esta revisión

Los problemas que este documento describía en versiones anteriores (heredados
del análisis del panel Next.js) están **todos resueltos** en la implementación
PHP actual, verificado en el código:

- ~~Sin transacción en `addMarcha`~~ — `AdminRepo::addMarcha` envuelve el INSERT en `marcha` + `marcha_autor` en `Db::transaction(...)`.
- ~~`autoresIds` no validados~~ — `AdminRepo::allAutoresExist()` los comprueba contra la tabla `autor` antes del INSERT.
- ~~SQL y parámetros expuestos en la UI~~ — no existe ningún preview de SQL en las plantillas actuales.
- ~~Sin audit log~~ — `Db::logAdmin()` registra en `admin_log` (acción, tabla, id, usuario, timestamp, payload) en cada escritura de `AdminRepo`.
- ~~Flujo Bearer alternativo~~ — no aplica: `Auth::currentSession()` solo lee la cookie, no hay ningún header `Authorization` soportado.

Si aparece deuda de seguridad nueva en el panel, va en [technical-debt.md](technical-debt.md), no aquí.

---

## 5. Funciones faltantes

| Prioridad | Función | Notas |
|-----------|---------|-------|
| — | Aviso en UI al crear marcha sin autores | No se encontró validación explícita que avise/bloquee una alta con `autoresIds` vacío (más allá de que la marcha exista igualmente, invisible en búsquedas públicas — ver [db-analysis.md](db-analysis.md) "Problema 3") |

Todo lo demás que este documento marcaba como faltante en versiones anteriores
(`PROVINCIA` en edición de marcha, `NOMBRE_ART` en autor, edición de autores,
editar autores de una marcha, buscador en el dashboard, enlace tras crear,
alta/edición de banda, **alta y edición de discos + pistas**) **ya está
implementado** — ver §2, §3 y §11.

---

## 6. Lo que funciona bien

- **Guard inline por ruta**: `Auth::requireAuth()`/`requireAdmin()`/`requireCap()` al principio de cada controlador, sin capa de framework intermedia.
- **Roles y capacidades** (`App\Roles`): admin con comodín total, editor con `EDITOR_CAPS` explícitas — ver §1.
- **Propuestas en vez de escritura directa para editores**: cero superficie de escritura remota para el rol menos privilegiado.
- **Allowlist de campos**: `EDITABLE_MARCHA`/`INSERTABLE_MARCHA`/`EDITABLE_AUTOR`/`EDITABLE_BANDA` en `AdminRepo` previenen mass-assignment.
- **Fail-safe de solo lectura por entorno**: `Db::assertWritable()` bloquea cualquier escritura si `config['env'] !== 'local'`, incluso si algo más fallara.
- **Audit log**: `Db::logAdmin()` en cada escritura de `AdminRepo`.
- **Autocomplete con mínimo de caracteres** (3) antes de buscar, evita queries triviales.
- **Prepared statements**: sin concatenación de SQL en ningún punto de `AdminRepo`.
- **CSRF derivado de la sesión**, sin tabla ni almacenamiento adicional.

---

## 7. Relaciones de linaje entre bandas (PHP)

> Añadido 2026-07-08. Gestiona el modelo `banda_relacion` — ver
> [db-analysis.md §Modelo de linaje de bandas](db-analysis.md). Implementación PHP nativa
> (no hay equivalente Next.js: es posterior al cutover).

Sustituye al viejo linaje por columnas `FORMACION_ANT/SIG` (lista enlazada lineal) por
una tabla de aristas tipadas que soporta renombrados (1→1), fusiones (N→1), divisiones
(1→N) y bandas juveniles (vínculo jerárquico con fechas).

### Acceso
La búsqueda del panel (`/dashboard?q=…`) devuelve ahora también **bandas**; cada
resultado enlaza a `/dashboard/banda/{id}`, la página de relaciones de esa banda.

### Página `/dashboard/banda/{id}`
- Lista las relaciones en las que participa la banda (como origen **o** destino), con el
  tipo, la dirección `origen → destino` (marcando en negrita cuál es «esta banda»),
  fecha(s) y nota. Cada fila tiene botón de borrado (POST con confirmación JS).
- Formulario de alta: **tipo** (renombrado/fusion/division/juvenil), **dirección** (esta
  banda es el origen o el destino), **otra banda** (autocomplete contra
  `/api/banda/fastSearch`), **fecha inicio**, **fecha fin** (solo visible para `juvenil`,
  vía `banda-relaciones.js`) y **nota**.

### Rutas
| Ruta | Método | Handler | Descripción |
|------|--------|---------|-------------|
| `/dashboard/banda/{id}` | GET | `Admin::bandaRelacionesForm` | Página de relaciones |
| `/dashboard/banda/{id}/relacion` | POST | `Admin::bandaRelacionAddPost` | Alta |
| `/dashboard/banda/{id}/relacion/{rel}/borrar` | POST | `Admin::bandaRelacionDeletePost` | Borrado |
| `/api/banda/fastSearch?q=` | GET | `Admin::bandaFastSearch` | Autocomplete JSON de bandas (mín. 3 caracteres) |

### Escritura (`AdminRepo`)
- `addRelacion(origen, destino, tipo, fechaInicio, fechaFin, nota)` — valida: `tipo` en
  `RELACION_TIPOS`; ambas bandas existen (FK real a `banda`) y son distintas; año de 4
  dígitos; `FECHA_FIN` solo se guarda en `juvenil` y debe ser ≥ inicio; `DUPLICATE` por el
  `UNIQUE(ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO)`. Códigos: `CREATED`, `INVALID_TIPO`,
  `INVALID_BANDA`, `SAME_BANDA`, `INVALID_FECHA`, `FECHA_FIN_ANTERIOR`, `DUPLICATE`.
- `deleteRelacion(idRelacion)` → `DELETED` / `NOT_FOUND`.
- Ambas registran en `admin_log` (INSERT / DELETE).

### Seguridad
Mismo patrón que el resto del panel: `Auth::requireAuth()` + CSRF (`Auth::checkCsrf`) +
PRG (`?created` / `?deleted` / `?err=CODE`). Prepared statements en todas las queries.

### Pendiente
La ficha **pública** de banda todavía no muestra el linaje: `Repo::fetchBanda` construye
un `timeline` de un solo elemento y `Html::timeline` solo pinta fundación/extinción. El
render público del linaje (recorrer `banda_relacion`) está por hacer.

---

## 8. Curación de estilo de marcha — CCTT/AM (PHP)

> Añadido 2026-07-10. Gestiona `marcha.ESTILO` — ver
> [db-analysis.md §Estilo de marcha](db-analysis.md). Implementación PHP nativa
> (no hay equivalente Next.js: es posterior al cutover).

Página de asignación manual para las marchas que la migración
[`migrate_marcha_estilo.php`](../php/app/tools/migrate_marcha_estilo.php) dejó sin
resolver (sin banda de estreno con estilo claro ni grabación documentada), y para
corregir asignaciones automáticas si hiciera falta.

### Página `/dashboard/estilos`
- Pestañas de filtro por estado: **Pendientes** (`ESTILO IS NULL`, filtro por defecto),
  **Todas**, **Cornetas y Tambores** (`CCTT`), **Agrupación Musical** (`AM`) — cada una
  con el recuento total.
- Buscador por título (`q`, `NOACC(TITULO) LIKE`).
- Tabla paginada (50/página) con, por marcha: título (enlaza a `/dashboard/marcha/{id}`),
  año, contexto para decidir (banda de estreno si la hay; si no, la banda de su primera
  grabación documentada — mismo criterio que usa el backfill) y el estilo actual.
- **Asignación rápida**: dos botones (`CCTT` / `AM`) por fila, sin salir de la página.
- **Asignación por lote**: checkboxes + "Asignar CCTT/AM a seleccionadas", para marcar
  varias marchas del mismo compositor/banda de un vistazo (patrón igual al descarte
  múltiple de `/dashboard/ingesta`).

### Rutas
| Ruta | Método | Handler | Descripción |
|------|--------|---------|-------------|
| `/dashboard/estilos` | GET | `Admin::estiloList` | Listado filtrable y paginado |
| `/dashboard/estilos/asignar` | POST | `Admin::estiloAssignPost` | Asigna `ESTILO` a uno o varios `ids[]` |

### Escritura (`AdminRepo`)
- `assignEstiloVarios(ids, estilo)` — valida `estilo` en `CCTT`/`AM`; `UPDATE ... WHERE
  ID_MARCHA IN (...)`, sobrescribe el valor si ya tenía uno (permite corregir). Código
  `ASSIGNED` (+ `count`), o `INVALID_ESTILO` / `BAD_REQUEST` / `NOT_FOUND`.
- Registra en `admin_log` (`UPDATE marcha`, con los IDs y el estilo asignado).

### Seguridad
Mismo patrón que el resto del panel: `Auth::requireAuth()` + CSRF (`Auth::checkCsrf`) +
PRG (`?asignadas=N` / `?err=CODE`), preservando los filtros activos (`ref`) para volver
a la misma pestaña/página tras guardar.

---

## 9. Selector de municipio (localidad/provincia en cascada)

> Añadido 2026-07 (catálogo de municipios, migración ejecutada en producción).
> Gestiona la tabla `municipio` — ver
> [`007_municipio.sql`](../php/app/tools/sql/007_municipio.sql) y
> `docs/ux-analysis-estado.md` (Prioridad 4 del análisis UX). Implementación
> PHP nativa (no hay equivalente Next.js: es posterior al cutover).

Antes de esta tabla, `LOCALIDAD`/`PROVINCIA` eran texto libre en `marcha`,
`banda` y `dedicatoria_alias`: de ahí las variantes de capitalización que hubo
que limpiar a mano (`app/tools/normalizar_localidades.php`). Ahora el panel
ofrece un listado cerrado con predictivo, y **elegir la localidad fija la
provincia** — un municipio pertenece siempre a una única provincia.

### Componente reutilizado (`App\Html` + `public/assets/admin.js`)

`Html::municipioFields($localidad, $provincia)` renderiza los dos campos
(`<select>` de Provincia + `<input>` de Localidad con autocompletado); el
`<form>` que los contiene necesita además `Html::municipioFormAttrs($isAdmin,
$csrf)` para que `initMunicipioPicker()` (en `admin.js`) los active. Los dos
campos no se envuelven en un contenedor propio porque eso rompería el
`.form-grid` de dos columnas de los formularios existentes; el JS los
localiza por sus atributos `data-municipio-*`.

Usado en seis formularios: `marcha_form`, `banda_form`, `banda_add`,
`dedicatoria_form`, `ingesta_detail` y `propuesta_detail`.

**Comportamiento en el navegador** (sin recargar página):
1. El campo Localidad empieza deshabilitado ("Elige antes la provincia").
2. Al elegir provincia se habilita y limpia; al escribir (≥ 2 caracteres)
   consulta `/api/municipio/fastSearch?provincia=&q=` (debounce 200 ms,
   `AbortController` para descartar respuestas obsoletas) y lista las
   localidades de esa provincia que casan por prefijo de palabra sin acentos
   (`NOACC(NOMBRE) LIKE`, p.ej. "guad" encuentra "Alcalá de Guadaíra").
3. Si el texto escrito no coincide con ninguna sugerencia exacta, aparece una
   opción extra al final de la lista:
   - **Admin** (`data-municipio-admin="1"`): "+ Añadir «X» a Provincia" — la
     crea al vuelo vía POST a `/dashboard/municipio/add` (`OFICIAL=0`, sin
     coordenadas) y la deja seleccionada, sin salir del formulario.
   - **Editor**: "Usar «X» (se propondrá al administrador)" — no crea nada;
     deja el texto tal cual en el campo y la fila se guarda a través del
     mismo circuito de propuestas que el resto de sus altas/ediciones (no hay
     validación de existencia en el cliente para editores).

⚠️ `Html::municipioFields()` escapa el valor de `$localidad` internamente
(HTML-escape) — pasarle un valor ya escapado (p.ej. el resultado de
`V::e($localidad)`) produce doble escape; pasar siempre el valor crudo de la
BD. Este fallo apareció en el primer borrador y se comprobó explícitamente en
las pruebas antes de cerrar la Prioridad 4 (`View::capture` sobre las 6
plantillas que usan el componente, con localidades con tilde/eñe).

### Regla de negocio: la localidad manda sobre la provincia (`AdminRepo`)

`AdminRepo::fijarMunicipio($localidad, $provincia)` (privado, se invoca desde
los métodos de escritura de marcha/banda/dedicatoria) es quien de verdad
decide qué se guarda — el JS de arriba es solo UX, no autoridad:

- Solo provincia (sin localidad): válida tal cual, muchas fichas solo tienen eso.
- Localidad **con** provincia: si el par exacto existe en el catálogo, manda
  ese par (corrige mayúsculas/acentos a la grafía canónica) — así una
  provincia "equivocada" enviada por error no se cuela si la localidad ya
  fija la correcta.
- Localidad **sin** provincia, o con una que no casa: se busca en qué
  provincia(s) existe esa localidad. Si es una sola, se deriva de ahí. Si
  son varias (hay nombres de municipio repetidos entre provincias) o
  ninguna, falla.
- Errores devueltos: `INVALID_PROVINCIA` (provincia no está en las 52 de
  `Mapa::PROVINCIAS`), `INVALID_LOCALIDAD` (el nombre no existe en el
  catálogo), `AMBIGUOUS_LOCALIDAD` (existe en más de una provincia y no se
  especificó cuál).
- Si la tabla `municipio` todavía no existe (BD sin migrar) no valida nada y
  deja pasar los valores tal cual — el panel debe seguir usable antes del
  seed.

### Rutas

| Ruta | Método | Handler | Descripción |
|------|--------|---------|-------------|
| `/api/municipio/fastSearch?provincia=&q=` | GET | `Admin::municipioFastSearch` | Predictivo de localidades dentro de una provincia (requiere sesión; sin ella, 401) |
| `/dashboard/municipio/add` | POST | `Admin::municipioAddPost` | Alta directa de un par nuevo — **solo admin** (`Auth::requireAdmin()`) + CSRF |

### Alta de pares nuevos (`MunicipioRepo::crear`)

Valida nombre no vacío, provincia en la lista cerrada, coordenadas en rango
si se pasan, y que el par no exista ya (`DUPLICATE`, por el `CLAVE` único).
Inserta con `OFICIAL = 0` (para distinguirlo de los ~8.112 municipios
oficiales del INE que trajo el seed) y registra en `admin_log`. Códigos:
`CREATED` (+ `municipioId`), `INVALID_NOMBRE`, `INVALID_PROVINCIA`,
`INVALID_COORDS`, `DUPLICATE`.

### Consumo en el mapa público

El mapa (`/mapa`, `/mapa/provincia/{slug}`) lee las coordenadas de esta misma
tabla (`MunicipioRepo::conCoordenadas`) en vez del fichero estático
`app/geo/municipios_es.php` — ver `docs/ux-analysis-estado.md` §4 para el
detalle de la navegación en dos niveles y el cálculo de zoom.

---

## 10. Scripts de herramientas (`php/app/tools/`)

Todos los scripts se ejecutan desde la raíz del repo (`mysql-simple/`). La variable de
entorno `DB_PATH` permite apuntar a una BD distinta de la que resuelve `config.php`
(útil para pruebas). Salvo que se indique lo contrario, todos son **solo lectura** o
cuentan con un modo dry-run por defecto.

### `fill_enlaces_streaming.php` — Completar enlaces Spotify de discos y marchas

Obtiene los álbumes/pistas del artista en Spotify y los cruza (fuzzy) con los discos y
marchas de la BD para cada banda que ya tenga ≥ 2 servicios de streaming enlazados.

| Score | Acción en `--commit` |
|-------|----------------------|
| ≥ 85 % | INSERT directo en `enlace_streaming` (VERIFICADO=1) |
| 70–84 % | INSERT en `enlace_candidato` (ESTADO='pendiente') → visible en `/dashboard/enlaces` |
| < 70 % | Solo se lista en el resumen, sin escribir |

Re-ejecuciones son seguras: excluye entidades ya enlazadas o ya encoladas (pendientes/aprobadas).

```bash
# Dry-run (sin escribir nada):
php php/app/tools/fill_enlaces_streaming.php

# Escritura real para todas las bandas:
php php/app/tools/fill_enlaces_streaming.php --commit

# Solo una banda:
php php/app/tools/fill_enlaces_streaming.php --commit --banda=28
```

Requiere `SPOTIFY_CLIENT_ID` y `SPOTIFY_CLIENT_SECRET` en `.env`.

---

### `backup.php` — Copia de seguridad de la BD

Hace una copia consistente con `VACUUM INTO` en `data/backups/` y borra las copias con
más de `backup_keep_days` días (configurado en `config.php`). Pensado para cron.

```bash
# En producción (HelioHost):
/usr/local/bin/php /home/USUARIO/app/tools/backup.php

# En local:
DB_PATH=php/data/mdc.db php php/app/tools/backup.php
```

---

### `export_marchas.php` — Exportar marchas a JSON para el pipeline de ingesta

Solo lectura. Vuelca las marchas de las bandas que tienen canal de YouTube en
`ingest_canal` como JSON, para que `tools/ingest/dedup.mjs` las use en el dedup.

```bash
php php/app/tools/export_marchas.php > tools/ingest/out/marchas.json
# Con BD explícita:
DB_PATH=php/data/mdc.db php php/app/tools/export_marchas.php > tools/ingest/out/marchas.json
```

---

### `import_candidatos.php` — Importar candidatos al panel de ingesta

Carga un `candidatos.ndjson` en la tabla `ingest_candidato`. Sirve a los dos
descubridores: el de YouTube (`tools/ingest/*.mjs`) y el del catálogo de streaming
de las bandas (`tools/music_links/descubrir_marchas.py`, ver
[ingesta-streaming.md](ingesta-streaming.md)).

Upsert por origen (`VIDEO_ID`): no sobreescribe candidatos ya revisados
(aceptados/descartados/duplicados) y **salta los orígenes vetados**
(`ingest_veto`), aunque su fila de candidato ya no exista.

```bash
php php/app/tools/import_candidatos.php tools/ingest/out/candidatos.ndjson
DB_PATH=php/data/mdc.db php php/app/tools/import_candidatos.php tools/music_links/out/candidatos.ndjson
```

---

### `load_canales.php` — Cargar/actualizar el mapeo banda ↔ canal de YouTube

Lee un CSV con cabecera `ID_BANDA,CANAL_URL` e inserta o actualiza filas en
`ingest_canal`. Idempotente: re-ejecutar no duplica.

```bash
php php/app/tools/load_canales.php tools/ingest/config/canales.csv
DB_PATH=php/data/mdc.db php php/app/tools/load_canales.php tools/ingest/config/canales.csv
```

---

### `migrate_ingest.php` — Aplicar migraciones de staging (tablas de ingesta)

Ejecuta en orden alfabético todos los `.sql` de `php/app/tools/sql/` contra la BD.
Los `.sql` son todos `CREATE ... IF NOT EXISTS`, así que es idempotente. Las
columnas nuevas sobre tablas ya existentes (SQLite no tiene `ADD COLUMN IF NOT
EXISTS`) se aplican al final del script comprobando antes `PRAGMA table_info`,
que mantiene esa misma promesa.

```bash
DB_PATH=php/data/mdc.db php php/app/tools/migrate_ingest.php
# En producción:
/usr/local/bin/php /home/USUARIO/app/tools/migrate_ingest.php
```

---

### `reevaluar_ingesta.php` — Reevaluar candidatos de YouTube pendientes

Backfill: cruza los candidatos aún pendientes/descartados contra todas las marchas de
su banda, por si hay coincidencias que se escaparon del chequeo automático inicial.
Dry-run por defecto.

```bash
# Ver qué cambiaría (sin escribir):
DB_PATH=php/data/mdc.db php php/app/tools/reevaluar_ingesta.php

# Aplicar los cambios:
DB_PATH=php/data/mdc.db php php/app/tools/reevaluar_ingesta.php --aplicar
```

---

### `migrate_banda_relacion.php` — Migración one-shot: linaje de bandas

Mueve los datos de `FORMACION_ANT`/`FORMACION_SIG` a la tabla `banda_relacion` y hace
DROP COLUMN de los campos legacy. Hace backup con `VACUUM INTO` antes de tocar nada.
Re-ejecutable con seguridad (INSERT OR IGNORE + columnas ya eliminadas → se salta).

```bash
php php/app/tools/migrate_banda_relacion.php
DB_PATH=/ruta/a/mdc.db php php/app/tools/migrate_banda_relacion.php
```

---

### `seed_dedicatorias.php` — Seed/normalización de advocaciones

Agrupa los valores de `marcha.DEDICATORIA` en advocaciones canónicas (`dedicatoria`) y
crea los alias (`dedicatoria_alias`). Idempotente: no sobreescribe curación manual
existente. Ver `php/app/tools/sql/003_dedicatoria.sql`.

```bash
DB_PATH=php/data/mdc.db php php/app/tools/seed_dedicatorias.php
```

---

### `migrate_marcha_estilo.php` — Migración one-shot: campo ESTILO en marcha

Añade `marcha.ESTILO TEXT CHECK (ESTILO IN ('CCTT','AM'))` y lo rellena derivándolo
del nombre de la banda que estrenó la marcha. Las que no resuelven quedan con
`ESTILO = NULL` para revisión manual en el panel. Re-ejecutable: aborta si la columna
ya existe.

```bash
DB_PATH=php/data/mdc.db php php/app/tools/migrate_marcha_estilo.php
```

---

### `completar_provincia.php` — Backfill de PROVINCIA en marchas y bandas

Rellena `marcha.PROVINCIA` y `banda.PROVINCIA` a partir de `LOCALIDAD` usando una
tabla estática de localidades → provincia. Solo actualiza filas con `PROVINCIA` vacía;
no toca asignaciones ya hechas. Hace backup previo si hay cambios pendientes. Lista al
final las localidades no reconocidas para revisión manual.

```bash
php php/app/tools/completar_provincia.php
DB_PATH=/ruta/a/mdc.db php php/app/tools/completar_provincia.php
```

---

### `migrate_roles.php` — Migración one-shot: columna ROL en usuarios

Añade `usuarios.ROL TEXT NOT NULL DEFAULT 'editor'` y marca como `admin` al usuario
indicado (por defecto `estprocesional`). Re-ejecutable: solo reafirma el rol admin si
la columna ya existe.

```bash
php php/app/tools/migrate_roles.php
php php/app/tools/migrate_roles.php --admin estprocesional
DB_PATH=/ruta/a/mdc.db php php/app/tools/migrate_roles.php
```

---

## 11. Discos: alta, portada y pistas

`/dashboard/disco/add` (alta) y `/dashboard/disco/{id}` (datos + pistas + vista
previa). **Solo administrador**: a diferencia de marcha/banda/autor, el disco no
pasa por la cola de propuestas (`PropuestaRepo` no conoce esta entidad) y su
alta escribe un fichero en el docroot.

### Portada

Se sube en el mismo formulario (`enctype="multipart/form-data"`) y la guarda
`Media::guardarPortada()` como `public/cover/{ID_DISCO}.png`, que es donde
`Html::coverSrc()` las busca. Tres decisiones que conviene no deshacer:

- **El fichero no se mueve tal cual**: se descodifica con GD y se vuelve a
  codificar a PNG. Eso normaliza el formato (entra JPEG/PNG/WebP/GIF, sale
  siempre PNG) y descarta cualquier carga útil incrustada — un `.jpg` con PHP
  dentro deja de serlo al reencodificarlo.
- **El tipo se decide por el contenido** (`getimagesize`), nunca por la
  extensión ni por el `Content-Type` del navegador, que el cliente controla.
  Además hay tope de bytes *y* de píxeles: una imagen de 20 000×20 000 pesa poco
  comprimida pero reventaría la memoria al descodificarla.
- **Escritura atómica** (fichero temporal + `rename`): si el proceso muere a
  medias, la portada anterior sigue intacta en vez de quedar un PNG truncado
  servido a todo el mundo.

La portada se guarda **después** de crear el disco, porque el nombre del fichero
es su ID. Si la subida falla, el disco ya existe: se avisa en la pantalla de
edición en vez de deshacer el alta.

`public/cover/` está en `php/.gitignore`: las portadas viven solo en el
servidor y el mirror del deploy excluye ese directorio
(`.github/workflows/deploy.yml`), así que un despliegue nunca las pisa.

### Pistas

Se busca la marcha por **identificador exacto o por título**
(`/api/marcha/fastSearch`, `AdminRepo::marchaCandidatosPorTexto()`): en la
carátula suele venir el número, pero no siempre se tiene a mano. Al elegir una
aparece una **vista previa** de lo que se va a añadir (pista, título, ID) antes
de enviar.

El número de pista **no se autoincrementa ni tiene que ser consecutivo** — se
propone el siguiente libre como sugerencia, pero un disco puede documentarse a
trozos o llevar cortes que no son marchas. Sí se valida que sea único dentro de
su volumen (`PISTA_OCUPADA`) y que la marcha no esté ya en el disco
(`MARCHA_YA_EN_DISCO`): las dos cosas son siempre errores de captura.

> **El nº de volúmenes no es un campo editable.** La columna `disco.DISCOS`
> existe en el esquema heredado pero **la aplicación nunca la lee**: `Repo` lo
> calcula como `MAX(disco_marcha.N_DISCO)` en las dos consultas que lo exponen.
> Por eso `EDITABLE_DISCO` no la incluye — guardarla sería dato muerto que
> además podría contradecir a las pistas reales. El volumen se fija pista a
> pista y el recuento sale solo.

### Importar las pistas desde el enlace del álbum

`/dashboard/disco/{id}/importar`. Es **lo primero que se ofrece tras crear un
disco** (`discoAddPost` redirige aquí, no a la ficha): a partir del enlace que la
banda publica en sus redes salen de una vez las pistas, su orden y su duración.
El alta manual de arriba no cambia y sigue a un clic desde esta pantalla.

Tres pasos, sin estado en servidor — el plan viaja en el propio formulario, que
es lo que permite que funcione en un hosting compartido sin sesiones de trabajo
ni tablas temporales:

| Paso | Ruta | Qué hace |
|------|------|----------|
| 1 | GET `…/importar` | Pide el enlace (precargado con el de `enlace_streaming` si ya lo hay) |
| 2 | POST `…/importar` | Lee el tracklist (`App\Tracklist`) y propone el plan (`App\ImportadorPistas::analizar`) |
| 3 | POST `…/importar/confirmar` | Escribe lo aprobado (`ImportadorPistas::aplicar` → `AdminRepo::addPista`) |

- **Servicios**: Spotify, Apple Music y Deezer, que son los que devuelven
  tracklist con duración. Apple y Deezer no necesitan credenciales; Spotify sí, y
  sin ellas la pantalla lo dice en vez de fallar. Salen del **`.env` de la raíz
  del repo** (`SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET`), el mismo que ya usan
  los scripts de `app/tools/`, así que no hay que duplicarlas; en el hosting, que
  no tiene `.env`, se ponen como `spotify_client_id`/`spotify_client_secret` en
  `config.local.php`. Precedencia: `config.local.php` > entorno > `.env`. **Instagram/Facebook/X quedan fuera a
  propósito**: un post no publica el listado en ningún formato estable (suele ser
  una foto de la contraportada) y su HTML público está tras un muro de sesión.
- **Nada de red nueva**: las llamadas y el parseo por servicio son los de
  `app/tools/lib/music_match.php`, el mismo código que usan `fill_duraciones.php`
  y `fill_enlaces_odesli.php`. Un solo criterio de similitud para todo el
  catálogo, que es justo para lo que se extrajo esa librería.
- **Umbral 80 %** (`ImportadorPistas::UMBRAL`): por encima la pista llega
  emparejada y marcada; por debajo se avisa (con la marcha más parecida y su
  porcentaje) y se ofrece **crear la marcha que falta**, precargando título, año
  y banda del disco — el autor lo pone el usuario, porque ninguna API de
  streaming lo devuelve. Al guardarla se vuelve a esta pantalla (`volver`, que
  solo admite rutas `/dashboard/…`).
- **Asignación 1:1 y greedy** por puntuación: ni dos cortes se llevan la misma
  marcha (los discos repiten tomas: «… (En Directo)») ni una marcha va a dos
  cortes. El segundo corte se marca como *repetido*, no como *no reconocido*.
- **Nada se escribe sin revisar**: la propuesta se enseña en una tabla editable
  (marcha, número, volumen, duración y la excepción de 🥁 por pista) y la
  escritura pasa por `AdminRepo::addPista`, con sus mismas validaciones —
  `MARCHA_YA_EN_DISCO` y `PISTA_OCUPADA` se informan pista a pista y el resto se
  añade igual.

### Cascada automática de enlaces (`App\EnlacesAuto`)

Guardar el enlace de un álbum —en la pestaña «Streaming» o al importar pistas—
dispara la búsqueda del resto. **Solo rellena huecos**, en tres niveles:

| Nivel | De dónde sale | Servicios |
|-------|---------------|-----------|
| Disco | 1 llamada a Odesli sobre el enlace guardado + repesque por UPC | los 6 |
| Marchas del disco | tracklist del álbum, emparejado con `disco_marcha` (≥ 85 %) | spotify, apple, deezer |
| Banda propietaria | el artista **de ese álbum** en cada servicio | spotify, apple, deezer |

Tres reglas que explican todo lo demás:

- **Identidad, no búsqueda.** Odesli resuelve la misma publicación, el UPC es el
  código de barras de la edición y el artista se lee del propio álbum. Nunca se
  busca «una banda que se llame así», que es de donde salían los falsos
  positivos del pipeline offline («Los Angeles» ≠ BCT Ángeles).
- **Nunca se pisa nada** (`AdminRepo::addEnlaceStreamingSiFalta`, INSERT OR
  IGNORE contra la UNIQUE): un enlace curado a mano sobrevive y repetir la
  cascada es idempotente. Por eso «Guardar enlaces» la lanza siempre y sirve
  también de reintento.
- **Lo dudoso no se publica**: por debajo del umbral va a `enlace_candidato`
  (pendiente, con su score) y se cura en `/dashboard/enlaces`. Umbrales:
  disco 0,55 · pista 0,85 (candidato desde 0,60) · banda 0,55. Un recopilatorio
  acreditado a «Various Artists» o un álbum que Odesli agrupa mal acaban ahí, no
  en la ficha pública.

De paso rellena `disco_marcha.DURACION_SEG` cuando está vacía (R-02): el
tracklist ya está delante y es el mismo dato que escribe `fill_duraciones.php`.
Una duración medida a mano no se toca.

Lo que **no** hace, a propósito: Amazon, Tidal y YouTube *a nivel de pista*. No
tienen tracklist pública, así que cada pista costaría una llamada a Odesli (~7 s
por su rate-limit) y un disco de 12 cortes dejaría la petición web colgada más de
un minuto. Eso sigue siendo trabajo de `fill_enlaces_odesli.php`, que va en
batch, con caché y sin usuario esperando. La cascada del panel tiene además un
presupuesto de 25 s (`EnlacesAuto::PRESUPUESTO_SEG`): al agotarse deja el resto
para el batch en vez de arriesgar un timeout a mitad de escritura.

El parser de Odesli (`PLATAFORMAS`, `odesliParse`) y las fichas de álbum por
servicio viven en `app/tools/lib/music_match.php`, compartidos con el batch: dos
copias acabarían interpretando distinto la misma respuesta. La caché de Odesli en
`php/data/odesli_cache/` también es común, y eso importa porque la API sin clave
admite del orden de 10 peticiones por minuto y por IP.

### Cobertura

Los smoke tests de CI no pueden autenticarse, así que comprueban lo que sí se
puede sin sesión: que las rutas existen (un 404 significaría que `routes.php` no
las registró) y que el guard redirige al login — incluidos los dos POST del
importador. El flujo completo —alta con portada, búsqueda por nombre y por ID,
pistas no consecutivas, rechazo de duplicados— se verificó de punta a punta con
un navegador contra una BD de pruebas con un usuario administrador.

La lógica sí está cubierta por pruebas automáticas, en dos runners que son pasos
propios del workflow de CI y que **no tocan la red ni piden credenciales** (toda
respuesta de servicio se inyecta desde `php/tools/fixtures/`):

- `php/tools/ci_importar.php` — reconocimiento de enlaces, lectura de tracklists
  (`Tracklist::$fetcher`), umbral y asignación 1:1, escritura en `disco_marcha`
  con duración y percusión, y el HTML de la pantalla de revisión.
- `php/tools/ci_enlaces_auto.php` — la cascada (`EnlacesAuto::$red`): que no pisa
  enlaces existentes y es idempotente, que un álbum mal agrupado o un título que
  no casa acaban en la cola y no en la ficha, el repesque por UPC cuando Odesli
  calla, los enlaces e ISRC por marcha, el relleno de duraciones y el artista de
  la banda (con «Various Artists» a curación).

Ambos arrancan la app sin servidor con `php/tools/ci_boot.php`, sobre una copia
desechable de la fixture de CI en modo local.

---

## 12. Ingesta de marchas — fuentes, veto de descartes y deshacer

`/dashboard/ingesta` (solo rol `admin`) revisa los candidatos de
`ingest_candidato` y los convierte en marchas. Desde 2026-07-28 deja de ser
específico de YouTube: cada candidato lleva una columna `FUENTE`
(`youtube` | `spotify` | `deezer` | `apple`). El pipeline completo del origen
de streaming está en [ingesta-streaming.md](ingesta-streaming.md).

### Qué cambia según la fuente

| | `youtube` | `spotify` / `deezer` / `apple` |
|---|---|---|
| Reproductor en el detalle | embed de YouTube | widget de Deezer / reproductor de Spotify / reproductor de Apple Music |
| Contexto extra | descripción del vídeo | disco de origen (`FUENTE_ALBUM`, con enlace) |
| Al aceptar, la URL va a… | `marcha.AUDIO` | `enlace_streaming` (marcha + servicio, `VERIFICADO = 1`) |

El reproductor no se elige por la fuente declarada sino por la URL del origen
(`Media::embedDeUrl()`), que es la misma función que usan la ficha pública y el
campo *Audio* del formulario de marcha. Si una URL no se reconoce, se ofrece el
enlace externo y se dice por qué.

El resto del formulario es idéntico: mismos campos que "Añadir marcha",
autocompletado de autor y dedicatoria, selector de municipio y estilo sugerido
(ahora respeta el `P_ESTILO` que propone el descubridor, y si no lo hay lo
deduce de las marchas previas de la banda, como antes).

### Veto: un descarte no vuelve a aparecer

Descartar (individual o masivo) escribe en **`ingest_veto`** una fila con el
origen exacto (`FUENTE` + `FUENTE_ID`), la banda, el título y el motivo. Efectos:

- `import_candidatos.php` salta ese origen en cualquier importación futura,
  incluso si la fila de `ingest_candidato` se purgó.
- `descubrir_marchas.py` no lo propone.
- La reevaluación automática (`IngestaRepo::reevaluarTrasCrearMarcha`,
  `reevaluar_ingesta.php`) **no reabre** candidatos vetados, aunque después se
  cree una marcha de título parecido.

El veto es **por origen exacto**: la misma marcha vista en otro servicio puede
volver a proponerse, y ahí el revisor vuelve a decidir.

### Deshacer el último descarte

`POST /dashboard/ingesta/deshacer-descarte` (botón `↩ Deshacer último descarte`
en el listado, visible solo cuando hay algo que deshacer). Devuelve los
candidatos a `pendiente`, borra su `MOTIVO`/`REVIEWED_AT` y levanta su veto.

- **Un solo paso**: `ingest_descarte_ultimo` es una fila única (`ID = 1`) que
  cada descarte nuevo sustituye; deshacer la consume y el botón desaparece.
- Un descarte masivo se deshace **entero** (guarda la lista de ids).
- Queda registrado en `admin_log` como `UNDO_DISCARD`.
- Para recuperar algo más antiguo: pestaña **Descartados** (el candidato sigue
  ahí con su motivo).

### Rutas de escritura añadidas

| Ruta | Handler | Qué hace |
|---|---|---|
| `POST /dashboard/ingesta/deshacer-descarte` | `Admin::ingestaDeshacerDescarte` | Deshace el último descarte (CSRF + rol admin) |
