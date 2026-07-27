# Panel de administración — marchasdecristo.com

> Última actualización: 2026-07-27 (selector de municipio) · 2026-07-12 (scripts de herramientas) · 2026-07-10 (curación de estilo CCTT/AM) · 2026-07-08 (relaciones de linaje de bandas)
> Documento complementario de [context.md](context.md) y [roadmap.md](roadmap.md).
>
> ⚠️ **Nota de implementación**: tras el cutover a PHP (2026-07-04) el panel se sirve
> con el stack PHP (`php/app/src/Admin.php` + `AdminRepo.php` + plantillas en
> `php/app/templates/admin/`). Las secciones 1–6 describen el diseño heredado de
> Next.js (rutas `*.ts` / `page.tsx`); la lógica es equivalente en PHP. La sección 7
> documenta ya directamente la implementación PHP.

---

## 1. Acceso

| | |
|---|---|
| **URL** | `https://marchasdecristo.com/dashboard` |
| **Login** | `https://marchasdecristo.com/login` |
| **Guard** | `nextjs/middleware.ts` — verifica cookie HMAC-SHA256 en todas las rutas `/dashboard/*` antes de servir cualquier página |
| **Sesión** | Cookie `mdc_session`, HttpOnly + Secure + SameSite=lax, TTL 8h |
| **Rate limit** | 6 intentos / 15 min por IP+usuario; bloqueo de 15 min |
| **Contraseñas** | PBKDF2-SHA512 / 210 000 iteraciones; upgrade automático desde MD5 en primer login exitoso |

El middleware verifica el token inline (sin HTTP round-trip). Los Client Components también llaman a `/api/login/verify` al montarse como segunda capa, pero el guard real es el middleware de servidor.

---

## 2. Funcionalidades existentes

### `/dashboard` — Home
- Campo numérico para navegar directamente a la edición de una marcha por ID.
- Botones: "Nueva marcha" → `/dashboard/marcha/add`, "Nuevo autor" → `/dashboard/autor/add`, "Logout".

### `/dashboard/marcha/[id]` — Edición de marcha
Campos editables: `TITULO`, `FECHA`, `DEDICATORIA`, `LOCALIDAD`, `AUDIO`, `BANDA_ESTRENO` (ID numérico a ciegas), `ESTILO` (CCTT/AM/sin asignar — ver `docs/db-analysis.md#estilo-de-marcha`), `DETALLES_MARCHA`.

**Faltante**: `PROVINCIA` está en la BD y en el alta pero no en este formulario. Los autores se muestran en modo lectura — no se pueden añadir ni quitar.

Muestra previsualización de cambios (diff viejo/nuevo) y SQL preparada con parámetros antes de guardar.

### `/dashboard/marcha/add` — Alta de marcha
Campos: `TITULO`, `FECHA`, `DEDICATORIA`, `LOCALIDAD`, `PROVINCIA`, `BANDA_ESTRENO` (autocomplete por nombre), `ESTILO`, `DETALLES_MARCHA`, autores (autocomplete multi, mínimo 6 caracteres).

Muestra SQL y parámetros antes de crear.

### `/dashboard/autor/add` — Alta de autor
Campos: `NOMBRE`, `APELLIDOS`, `F_NAC`, `LUGAR_NAC`, `F_DEF`, `BIO`.

**Faltante**: `NOMBRE_ART` (nombre artístico) no está en el formulario ni en el endpoint, aunque sí está indexado en `autor_fts`.

Muestra SQL y parámetros antes de crear.

---

## 3. API de escritura (Route Handlers)

| Endpoint | Método | Auth | Descripción |
|----------|--------|------|-------------|
| `/api/admin/editMarcha` | POST | cookie/Bearer | Allowlist de 8 campos. Devuelve `UPDATED` / `NOT_FOUND` / `NO_CHANGES` |
| `/api/admin/addMarcha` | POST | cookie/Bearer | INSERT marcha + INSERT marcha_autor (⚠️ sin transacción) |
| `/api/admin/addAutor` | POST | cookie/Bearer | INSERT autor con 6 campos |

Todos verifican sesión con `verifySession(getTokenFromRequest(req))` antes de cualquier operación de BD.

`getTokenFromRequest` acepta tanto cookie como header `Authorization: Bearer`. El middleware solo usa cookies, por lo que el flujo Bearer es un camino alternativo que no pasa por el guard de servidor.

---

## 4. Problemas de seguridad identificados

### 4.1 Sin transacción en `addMarcha` 🔴
`addMarcha/route.ts:25-41` — dos `dbRun` separados. Si el INSERT en `marcha_autor` falla, la marcha queda en la BD sin autores. Las búsquedas públicas la filtran (por `EXISTS marcha_autor`), pero es basura silenciosa en la BD. Ver [roadmap.md §U1](roadmap.md).

### 4.2 `autoresIds` no validados contra la BD 🔴
Los IDs de autor se parsean del body pero no se verifica que existan en la tabla `autor`. Sin FK constraints activos, se crean relaciones huérfanas. Ver [roadmap.md §U2](roadmap.md).

### 4.3 SQL y parámetros expuestos en la UI 🟡
Los formularios muestran la SQL preparada y los valores en pantalla. Sin riesgo de ataque directo (solo el admin accede), pero puede quedar en capturas de pantalla o en el historial del navegador. Ver [roadmap.md §M6](roadmap.md).

### 4.4 Sin audit log 🟠
No hay registro de qué cambió, cuándo y quién. Ver [roadmap.md §M1](roadmap.md).

### 4.5 Flujo Bearer alternativo 🟡
`getTokenFromRequest` acepta `Authorization: Bearer` además de la cookie. Es un camino que no pasa por el middleware, aunque sí pasa por `verifySession`. Si se quiere simplificar la superficie: restringir a cookie-only en los Route Handlers admin.

---

## 5. Funciones faltantes (por prioridad)

| Prioridad | Función | Ficheros a crear/modificar |
|-----------|---------|---------------------------|
| 🔴 U3 | Aviso en UI al crear marcha sin autores | `marcha/add/page.tsx` |
| 🟠 A2 | `PROVINCIA` en edición de marcha | `marcha/[id]/page.tsx`, `editMarcha/route.ts` |
| 🟠 A3 | `NOMBRE_ART` en alta/edición de autor | `addAutor/route.ts`, `autor/add/page.tsx` |
| 🟠 A4 | Edición de autores (`/dashboard/autor/[id]`) | nuevo page + `editAutor/route.ts` |
| 🟠 A5 | Editar autores de una marcha | `marcha/[id]/page.tsx` + `editMarchaAutores/route.ts` |
| 🟡 M4 | Buscador de marchas/autores en el dashboard | `/dashboard/page.tsx` |
| 🟡 M5 | Enlace a edición/público tras crear | `marcha/add`, `autor/add` |
| 🟡 M6 | Eliminar SQL preview de la UI | `marcha/add`, `autor/add`, `marcha/[id]` |
| 🟢 B3 | Alta y edición de bandas (metadatos) | `/dashboard/banda/*` — las **relaciones de linaje** ya existen (§7); falta editar los campos propios de la banda |
| 🟢 B4 | Alta y edición de discos + pistas | nuevo `/dashboard/disco/*` |

---

## 6. Lo que funciona bien

- **Middleware server-side**: el guard en `middleware.ts` es síncrono y no tiene red — es la capa de protección real.
- **Allowlist de campos**: `EDITABLE_FIELDS` en `editMarcha` y `INSERTABLE_FIELDS` en `addMarcha`/`addAutor` previenen mass-assignment.
- **Timing-safe en verificación**: `verifySession` usa `crypto.timingSafeEqual`.
- **Autocomplete con mínimo de caracteres**: 6 chars mínimos antes de buscar evitan queries triviales.
- **Previsualización de cambios**: el diff viejo/nuevo antes de guardar en edición de marcha es útil para revisión manual.
- **Prepared statements**: sin concatenación de SQL en ningún Route Handler.

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

### `import_candidatos.php` — Importar candidatos de YouTube al panel de ingesta

Carga el fichero `candidatos.ndjson` generado por el pipeline de ingesta (Fase 2/3) en
la tabla `ingest_candidato`. Upsert por `VIDEO_ID`: no sobreescribe candidatos ya
revisados (aceptados/descartados/duplicados).

```bash
php php/app/tools/import_candidatos.php tools/ingest/out/candidatos.ndjson
DB_PATH=php/data/mdc.db php php/app/tools/import_candidatos.php tools/ingest/out/candidatos.ndjson
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
Los `.sql` son todos `CREATE ... IF NOT EXISTS`, así que es idempotente.

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
