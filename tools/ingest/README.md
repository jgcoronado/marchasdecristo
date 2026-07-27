# Ingesta de marchas desde YouTube

Herramienta **offline** para descubrir marchas nuevas (2019→) a partir de los
canales de YouTube de las bandas de la BD, proponerlas como candidatos y
revisarlas/aceptarlas desde el panel de administración PHP.

## Por qué offline

Producción corre en hosting compartido (HelioHost: solo PHP + cron, sin SSH /
Node / Python). El extractor usa **yt-dlp**, que no puede correr en el host, así
que la extracción y el parseo se hacen en tu PC y solo el **resultado**
(candidatos) se lleva al host para revisarlo. El `.db` de producción **nunca** se
sobrescribe: los candidatos viajan como NDJSON y se importan a la tabla de
staging.

## Flujo completo

```
  canales.csv ─┐
               ▼
        [yt-dlp]  descarga vídeos + metadatos desde 2019      (Fase 1, Node)
               ▼
     [clasificar] estreno / novedad / recuperación / otro     (Fase 2, Node)
               ▼
      [extraer]  título, autor(es), año, dedicatoria, duración (Fase 2, Node)
               ▼
       [dedup]   cruce contra `marcha` existentes             (Fase 3, Node)
               ▼
     candidatos.ndjson ──► import ──► ingest_candidato         (Fase 4, PHP)
               ▼
      panel admin: revisar / editar / aceptar / descartar     (Fase 4, PHP)
               ▼
        marcha + marcha_autor  (al aceptar, en transacción)
```

## Estado: Fase 0 — cimientos ✅

Ya entregado:

- **Esquema de staging** (fuente de verdad): [`php/app/tools/sql/001_ingest_staging.sql`](../../php/app/tools/sql/001_ingest_staging.sql)
  - `ingest_canal` — mapeo banda ↔ canal de YouTube.
  - `ingest_candidato` — marchas propuestas (con campos `P_*` editables y estado de revisión).
  - `ingest_run` — traza de cada ejecución del extractor.
- **Aplicador de migración** (PHP, idempotente, corre en el host):
  `php app/tools/migrate_ingest.php`
- **Cargador del mapeo de canales** (PHP): `php app/tools/load_canales.php <csv>`
- **Diccionario de clasificación**: [`config/keywords.json`](config/keywords.json)
- **Plantilla de canales**: [`config/canales.example.csv`](config/canales.example.csv)

### Cómo aplicar la Fase 0

```bash
# 1. Crear las tablas de staging (en local apunta a tu copia con DB_PATH)
DB_PATH=php/data/mdc.db php php/app/tools/migrate_ingest.php

# 2. Preparar tu lista de canales (copia la plantilla y edítala)
cp tools/ingest/config/canales.example.csv tools/ingest/config/canales.csv
#    ...rellena ID_BANDA + CANAL_URL de cada banda...

# 3. Cargarla en ingest_canal
DB_PATH=php/data/mdc.db php php/app/tools/load_canales.php tools/ingest/config/canales.csv
```

En el host es igual pero sin `DB_PATH` (usa el `db_path` de `config.php`):

```bash
/usr/local/bin/php /home/USUARIO/app/tools/migrate_ingest.php
```

## Fase 1 — extractor yt-dlp ✅ (rediseñado: dos pasadas)

[`extract.mjs`](extract.mjs) (Node, sin dependencias nativas; requiere `yt-dlp` en el
PATH). Lee `config/canales.csv`, procesa las pestañas `/videos` y `/streams` de cada
canal (los directos son clave: muchos estrenos son emisiones en vivo) y filtra
desde 2019.

### El problema que obligó al rediseño

La primera versión pedía metadatos completos (`--dump-json`, con descripción) de
**cada vídeo del canal, uno a uno y en serie**. Al probarlo con canales reales
(p.ej. `@lascigarreras`, 4.200+ vídeos históricos) pasó esto:

- Una fracción grande del canal son vídeos **exclusivos para "socios"/members**
  (niveles "Fajín morado", "Ángel de la fama"...). yt-dlp solo puede saberlo
  **abriendo la página del vídeo** — así que cada uno generaba una petición
  entera + un `ERROR: ... members-only ...` en el log. Con cientos de vídeos así,
  el log se llenaba de errores y el proceso apenas avanzaba.
- Además, sin filtrar antes, había que abrir **todos** los vídeos del canal
  (incluso de 2008) para poder leer su fecha real y descartarlos — carísimo.
- En 5 minutos de prueba real, el extractor no llegó ni a terminar la pestaña
  `/videos` de un solo canal.

### La solución: filtrar barato antes de abrir nada

1. **Pasada 1 (listado):** `yt-dlp --flat-playlist --dump-json --extractor-args
   youtubetab:approximate_date` sobre la pestaña completa. Esto es **una
   respuesta grande por pestaña**, no una petición por vídeo, y ya trae
   `availability` (permite detectar `subscriber_only`/`premium_only`/`private`
   **sin abrir el vídeo**) y una fecha aproximada. Con esto se descartan de raíz:
   vídeos solo-para-miembros, vídeos anteriores a 2019, y títulos que ya delatan
   ruido (`ensayo`, `cover`, `tutorial`... de `keywords.json`).
2. **Pasada 2 (extracción completa):** solo para los vídeos que sobreviven al
   filtro se pide `--dump-json` normal (con descripción, necesaria para la
   Fase 2), y esto se hace con un **pool de workers concurrentes** (por defecto
   4) en vez de ir uno a uno.
3. **Caché por vídeo, no por canal:** `out/raw/<ID_BANDA>-<slug>.ndjson` se
   escribe línea a línea a medida que llegan resultados. Cortar el proceso a
   media extracción no pierde nada — la siguiente ejecución solo pide los
   vídeos que faltan.

Resultado medido sobre `@lascigarreras` (canal real, muy activo): la pasada 1
tarda ~45s y lista los 4.200+ vídeos sin un solo error; tras filtrar quedan
~3.200 candidatos (se descartaron 280 solo-miembros con **cero** peticiones a
esos vídeos). La pasada 2, con concurrencia 4, extrae a ~2,9 vídeos/segundo →
toda la extracción de las 3 bandas de ejemplo (~4.240 candidatos) se completa en
**~25 minutos**, sin errores.

```bash
cd tools/ingest
node extract.mjs --dry-run                     # cuenta candidatos por canal, sin descargar nada
node extract.mjs --only 16 --max 20             # smoke test acotado (real, pero pequeño)
node extract.mjs                                # pasada real completa (reanudable)
node extract.mjs --force                        # ignora la caché por vídeo y repite todo
node extract.mjs --concurrency 6 --sleep 0.3    # más rápido (a tu propio riesgo de cortesía con YouTube)
node extract.mjs --months 10-3                  # solo octubre→marzo (temporada de estrenos), cruza fin de año
```

**Cobertura (no repetir pasadas ya hechas):** cada vez que un canal se procesa
completo (sin `--max`, sin errores de vídeo) se anota en `out/coverage.json`
con el `--since`/`--months` usados. En la siguiente ejecución, si el rango
pedido ya está cubierto por una pasada anterior (mismo `--since` o más
reciente, y `--months` igual o cobertura de año completo), esa banda se
**salta sin tocar YouTube**. `--force` ignora la cobertura y la repite.
`out/coverage.json` no está versionado (vive en `out/`, gitignored) — es
local a cada máquina donde se ejecuta el extractor.

**`--months INICIO-FIN`**: los estrenos de estas bandas se concentran entre
octubre y marzo (temporada previa a Semana Santa). Filtrar por ese rango antes
de la extracción completa reduce drásticamente el volumen en canales muy
activos: en las 8 bandas de ejemplo, `--months 10-3` bajó el total a extraer
de ~7.300 a ~800 vídeos (viable en minutos en vez de horas). El filtro se
aplica dos veces: barato en la pasada 1 (con 1 mes de colchón, porque la fecha
ahí es aproximada) y exacto tras la pasada 2 (con la fecha real).

Cada registro final: `id_banda, video_id, url, titulo, descripcion, publicado (ISO),
duracion_seg, live_status, tab, channel, channel_id`. Los vídeos de solo-miembros,
fuera de fecha o cuyo título ya delata ruido se descartan **antes** de pedir su
descripción; los próximos estrenos (`is_upcoming`) también se omiten.

## Fase 2 — clasificador + extractor heurístico ✅

[`classify.mjs`](classify.mjs) lee `out/videos.ndjson` (salida de la Fase 1) y por
cada vídeo:

1. **Clasifica** por keywords de [`config/keywords.json`](config/keywords.json):
   `estreno` / `novedad` / `recuperacion` / `otro` (los `otro` se descartan y no
   pasan a candidatos — la mayoría de vídeos de un canal son conciertos de
   marchas ya existentes, no estrenos).
2. **Extrae** los campos propuestos (`P_*`) de título/descripción:
   - `P_TITULO` — primero busca texto entrecomillado; si no hay, corta por el
     primer separador (`|`, `-`, `:`, …) tras quitar prefijos tipo "ESTRENO MUNDIAL:".
   - `P_AUTORES` — busca el nombre del compositor tras el título usando
     conectores habituales ("de", "autoría de", "compuesta por", "original de"…).
   - `P_LOCALIDAD` — patrón "... en `<Localidad>` `<Año>`" del título.
   - `P_FECHA` — año de publicación del vídeo (por defecto; el texto puede
     mencionar un año distinto de composición, que queda para revisión manual).
3. Asigna **confianza** (0–1) y **flags** (`sin_autor_detectado`,
   `sin_localidad_detectada`, `titulo_sin_comillas`, …) para que el panel
   resalte lo que hay que revisar a mano.

Salida: `out/candidatos.ndjson` (solo estreno/novedad/recuperación).

```bash
cd tools/ingest
node classify.mjs                 # out/videos.ndjson → out/candidatos.ndjson
node classify.mjs --debug         # + out/descartados.ndjson con el motivo de cada descarte
```

## Fase 3 — dedup contra marchas existentes ✅

1. Exporta las marchas de las bandas con canal registrado (solo lectura):
   ```bash
   php php/app/tools/export_marchas.php > tools/ingest/out/marchas.json
   ```
2. Cruza cada candidato contra las marchas de **su misma banda de estreno**
   por similitud de título (Levenshtein normalizado, sin dependencias):
   ```bash
   cd tools/ingest
   node dedup.mjs
   ```

Regla acordada: una **recuperación** con coincidencia fuerte (≥0.9) ya existe
en la BD → se marca `estado=duplicado` y no llega al panel como candidato
nuevo. Un **estreno/novedad** con coincidencia **nunca se autodescarta** (por
definición debería ser nuevo — un match es una señal de alerta para el
revisor, no una prueba de duplicado): queda `pendiente` con el match anotado
(`match_marcha_id`, `match_titulo`, `match_score`) para que decida un humano.
Coincidencias medias (0.75–0.9) también quedan pendientes, solo con aviso.

`dedup.mjs` enriquece `out/candidatos.ndjson` in-place, dejándolo listo para
la Fase 4 (import al panel admin).

## Fase 4 — panel de revisión (PHP) ✅

1. Importa los candidatos ya deduplicados a la tabla de staging:
   ```bash
   php php/app/tools/import_candidatos.php tools/ingest/out/candidatos.ndjson
   ```
   Upsert por `VIDEO_ID`: si un candidato ya fue revisado (aceptado/descartado/
   duplicado), **no se toca** al reimportar — solo se refrescan los que
   siguen `pendiente`. Así puedes repetir Fases 1-4 sin perder decisiones ya
   tomadas.
2. Entra en `/dashboard/ingesta` (con sesión admin): lista con filtros por
   estado/banda/clasificación y badges de confianza/duplicado.
3. Abre un candidato para revisarlo: vídeo embebido, campos propuestos
   editables (mismo formulario que "Añadir marcha"), selector de autor con
   el autocomplete existente y un enlace "＋ crear compositor" (con el nombre
   detectado precargado) para los que aún no existen en la BD.
4. **Aceptar** crea la marcha (reutilizando la misma lógica de alta que el
   resto del panel) y además fija `AUDIO` con la URL del vídeo de origen.
   **Descartar** admite un motivo opcional. Ambas acciones exigen que el
   candidato siga `pendiente` (comprobado en el servidor, no solo en la
   interfaz) y quedan registradas en el audit log.

## Próximas fases

- **Fase 5** — primera pasada real con tu lista de bandas.
