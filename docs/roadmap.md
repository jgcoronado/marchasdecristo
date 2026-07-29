# Hoja de ruta — marchasdecristo.com

> Generado: 2026-06-01 · Reescrito: 2026-07-16 (C8) · Revisado: 2026-07-29
> (revisión integral de ramas, documentación y siguientes pasos — ver
> [§ Revisión del roadmap](#revisión-del-roadmap-2026-07-29))
>
> Las fases 0–6 originales (limpieza de seguridad, migración MySQL→Docker,
> migración Next.js/Express→Route Handlers, integridad de BD, tests/CI/CD sobre
> Next.js, mejoras opcionales) están **completadas y superadas**: el cutover del
> 2026-07-04 sustituyó ese stack entero por PHP 8.4 + SQLite (ver
> [context.md](context.md) y [architecture.md](architecture.md)). El detalle de
> esas fases se conserva solo como referencia histórica en el historial de git
> de este fichero (`git log -p -- docs/roadmap.md`); no se reproduce aquí para
> no confundirlo con el plan vigente.

## Marco vigente (consolidado 2026-07-23)

El proyecto tenía **dos planes solapados** redactados casi a la vez:

- **Plan de palancas 2026-27** (2026-07-09): P-01…P-09 + transversales T-01…T-03
  + 11 pantallas nuevas **N-01…N-11**, con calendario estacional hacia Semana
  Santa 2027. Dossier: artefacto `1a31cc69`.
- **Consejo de sabios** (2026-07-12): DAFO integral + plan C1–C8 / M1–M9 / L1–L6.
  Dossier: [consejo-de-sabios-2026-07.md](consejo-de-sabios-2026-07.md).

Solapan ~70%. **Decisión (2026-07-23): el marco forward es el plan de palancas
(pantallas N-*)**, porque es más granular y tiene el ritmo estacional correcto;
la lista M-x del consejo está mayormente **absorbida o completada**. Los dos
únicos ítems del consejo que las palancas no cubrían —M6 (accesibilidad +
impresión) y M7 (notificaciones editoriales)— se **pliegan** aquí como tareas
de calidad. Este documento es el **tracker único**: enlaza ambos dossieres y
mantiene el estado real verificado, no reescribe los informes.

El detalle histórico del avance C1–C8 / M1–M9 (todos cerrados o absorbidos) se
conserva en las tablas de más abajo y en `git log -p -- docs/roadmap.md`.

### Trabajo en ramas sin fusionar (verificado 2026-07-29)

Tres ramas `claude/*` están empujadas al remoto **con CI en verde, sin PR
abierto y sin reflejo en este tracker** hasta esta revisión. Ninguna diverge de
`main` (fast-forward posible). Son **dos líneas de trabajo, no tres**:

| Rama | Contenido | Estado |
|---|---|---|
| `claude/filtrado-candidatas-videos-drdd1y` | **Ingesta de marchas desde el catálogo de streaming de las bandas** (Spotify/Deezer/Apple; YouTube queda excluido como fuente de descubrimiento): `tools/music_links/descubrir_marchas.py`, migración `008_ingest_streaming.sql` (`ingest_veto`, `ingest_descarte_ultimo`), descarte definitivo + deshacer, reproductor por servicio en el panel y en la ficha pública, `docs/ingesta-streaming.md`. Filtra directo/vivo, Navidad/cabalgata y exige corroboración en ≥2 catálogos | ✅ CI verde · **lista para PR** |
| `claude/bandas-rrss-discos-sync-x60kfw` | Ancestro estricto de la anterior (sus 4 commits están contenidos en ella) | 🗑 **Redundante** — se borra al fusionar la anterior |
| `claude/diseño-discreto-sencillo-jymud4` | Rediseño de pantallas públicas + dos regresiones del mapa corregidas (blanco de clic de 8 px, `pointer-events: all` sobre relleno transparente, rótulos) + **alta/edición de discos con portada y pistas** (`/dashboard/disco/*`), que cierra [technical-debt §5.1](technical-debt.md) | ✅ CI verde (85/85 smoke) · **lista para PR** |

**Cómo integrarlas.** Orden recomendado: `diseño…jymud4` primero (mueve
`app.css`, plantillas y `ci_smoke.php`) y después `filtrado…drdd1y`. Verificado
en un merge de prueba: las dos juntas producen **un único conflicto, en
`docs/admin-panel.md`** (ambas añaden secciones); el código funde solo. Aun así,
los solapes son semánticamente cercanos —`Media.php` recibe `guardarPortada()`
por un lado y `embedDeUrl()`/`reproductor()` por el otro, y `marcha_detail.php`
se reestructura en una rama mientras la otra le añade el reproductor de
streaming— así que **hay que relanzar el smoke sobre el resultado fusionado**,
no dar por buena la suma de dos CI verdes.

⚠️ **Ninguna de las dos se puede validar en PRE**: PRE comparte la BD de
producción y ambas escriben (migración `008`, portadas en el docroot, altas de
disco). Validación en local, como manda [entornos.md](entornos.md) §«Qué
vigilar». La migración `008` y la campaña de curación de los candidatos entran
en el ciclo normal (local → `sync_db_to_prod.php`).

### Corto plazo (0–1 mes) — issues `consejo-sabios` + `corto-plazo`

| # | Tarea | Estado | Issue |
|---|-------|--------|-------|
| C1 | Hubs indexables por año / estilo / provincia | ✅ Completado | [#7](https://github.com/jgcoronado/mdc-back/issues/7) |
| C2 | `lastmod` en sitemap + ping IndexNow/Google tras el sync | ✅ Completado | [#8](https://github.com/jgcoronado/mdc-back/issues/8) |
| C3 | «Marcha del día» + bloque de descubrimiento en la home | ✅ Completado | [#9](https://github.com/jgcoronado/mdc-back/issues/9) |
| C4 | `og:image` de marca + Twitter Card | ✅ Completado | [#10](https://github.com/jgcoronado/mdc-back/issues/10) |
| C5 | CI con smoke tests (GitHub Actions) | ✅ Completado | [#11](https://github.com/jgcoronado/mdc-back/issues/11) |
| C6 | Monitorización externa de uptime con alerta | ✅ Completado — monitor UptimeRobot activo sobre `/health`, ver [monitoring.md](monitoring.md) | [#12](https://github.com/jgcoronado/mdc-back/issues/12) |
| C7 | Endurecer `sync_db_to_prod.php`: checksum, chequeo de propuestas, modo mantenimiento | ✅ Completado | [#13](https://github.com/jgcoronado/mdc-back/issues/13) |
| C8 | Actualizar documentación (`context.md`/`architecture.md`/`roadmap.md`/`technical-debt.md`) al stack PHP real | ✅ Completado (este documento es parte del entregable) | [#14](https://github.com/jgcoronado/mdc-back/issues/14) |

**8 de 8 tareas de corto plazo completadas.** El plan de acción de corto
plazo del consejo de sabios está cerrado.

### Medio plazo del consejo (M1–M9) — fotografía histórica cerrada

> Tabla congelada: 5 de 9 completadas. Los 4 pendientes se reencauzan en el
> **Plan forward activo** más abajo — M2 al carril manual de audio, M6/M7 como
> tareas de calidad, M9 dentro de las pantallas N-07/N-08/N-09. No se marca ni
> se añade nada más en esta tabla.

| # | Tarea | Coste | Repercusión | Foco | Estado | Issue |
|---|-------|-------|-------------|------|--------|-------|
| M1 | API JSON de solo lectura + `llms.txt` + feed de novedades + página «Datos» | 10 h | Alta | 🔍 | ✅ Completado — licencia CC BY 4.0; de paso corrigió otro caso de URL de banda no canónica (308) en la ficha de disco | [#15](https://github.com/jgcoronado/mdc-back/issues/15) |
| M2 | Campaña de cobertura de audio (ingesta + curación) | 15 h+ | Alta | 🎺 | ⏳ Pendiente — trabajo mayoritariamente manual del admin, no solo código | [#16](https://github.com/jgcoronado/mdc-back/issues/16) |
| M3 | Búsqueda global unificada + autocompletado público | 10 h | Media-alta | 🎺 | ✅ Completado — una caja global (cabecera) + `/buscar` + endpoint `/api/buscar` con desplegable accesible; FTS5 prefijo (marcha/autor) + LIKE (banda/disco) | [#17](https://github.com/jgcoronado/mdc-back/issues/17) |
| M4 | `og:image` dinámica por entidad | 8 h | Media | 🔍 | ✅ Completado — `/og/{tipo}/{id}.png` con GD/FreeType (IBM Plex, OFL), cacheada a disco, fallback a la imagen de marca si no hay FreeType | [#18](https://github.com/jgcoronado/mdc-back/issues/18) |
| M5 | Deploy FTP automatizado desde CI en `main` verde | 5 h | Media-alta | ⚙️ | ✅ Completado: push a `pre` → CI (`verify`) → deploy automático a PRE; fusión de `pre` en `main` → deploy automático a PRO con modo mantenimiento. Ambos con smoke remoto. El entorno de preproducción se retiró el 2026-07-23 (Plesk no deja mover el document root del subdominio) y se **reintrodujo el 2026-07-28** aislándolo desde el código (`env.php` desvía `APP_DIR`), sin tocar Plesk | [#19](https://github.com/jgcoronado/mdc-back/issues/19) |
| M6 | Accesibilidad + hoja de impresión de fichas | 6 h | Media | 🎺 | ⏳ Pendiente | [#20](https://github.com/jgcoronado/mdc-back/issues/20) |
| M7 | Notificaciones editoriales (email + digest semanal) | 6 h | Media | ⚙️ | ⏳ Pendiente | [#21](https://github.com/jgcoronado/mdc-back/issues/21) |
| M8 | Unificar slugify + test canónica↔JSON-LD + CSP/HSTS | 4 h | Media | 🔍⚙️ | ✅ Completado — de paso corrigió un bug real (URL de banda en JSON-LD nunca coincidía con la canónica) | [#22](https://github.com/jgcoronado/mdc-back/issues/22) |
| M9 | Estadísticas ampliadas como contenido indexable | 6 h | Media | 🔍 | ⏳ Pendiente | [#23](https://github.com/jgcoronado/mdc-back/issues/23) |

Detalle completo de cada tarea en `consejo-de-sabios-2026-07.md` §7 y en el
cuerpo de cada issue. Regla de secuencia del consejo: "nada del largo plazo
empieza sin el tablero de KPIs activo" — L1-L6 siguen sin issues por eso.

**Nota**: la numeración M-x queda **cerrada** como fotografía histórica. El
tracker activo desde el 2026-07-23 es la sección siguiente (pantallas N-* del
plan de palancas + M6/M7 plegados).

## Plan forward activo — pantallas N-* + calidad (tracker vivo)

> Estado verificado contra `php/app/routes.php` el 2026-07-23. Detalle de cada
> N-* en el dossier de palancas (artefacto `1a31cc69`, §08). Todos los cambios
> de BD son **aditivos**, migrables in situ (patrón de `001`/`002`).

### Ya en producción (base sobre la que se construye)
- Hubs año/estilo/provincia (C1/P-05) · Dedicatorias **N-01/N-02** (índice +
  hub + panel de curación) · Búsqueda global **N-11** (`/buscar` + `/api/buscar`)
  · API+feeds+«Datos» (M1; el feed `/feed.xml` **es** el «novedades» de P-09) ·
  og:image dinámica (M4) · Vídeo YouTube en ficha (P-02, `App\Media`) ·
  GoatCounter opt-in (P-08) · Slugify unificado + CSP/HSTS (M8) · **N-07
  `/rankings`** (rankings de siempre + drill-down `/rankings/{año}`; ver detalle
  abajo).

### Cola de código (agosto–septiembre) — solo queries sobre datos existentes — ✅ CERRADA 2026-07-23
| # | Pantalla / tarea | Depende de | Estado |
|---|------------------|-----------|--------|
| N-07 | `/rankings` — parametrizar por año las queries `fetchMas*` existentes | — | ✅ Completado 2026-07-23 — `/estadisticas` renombrado con 301 permanente; `/rankings/{año}` con umbral `HUB_MIN_MARCHAS` (thin → noindex, como los demás hubs), índice por décadas, cross-link con `/marcha/ano/{año}` |
| N-09 | `/aniversarios/{año}` — 25/50/75/100 años, centenarios | — | ✅ Completado 2026-07-23 — tramos de 25 en 25 hasta 200 (centenarios destacados 🎉); `/aniversarios` redirige 302 al año en curso; `/aniversarios/{año}` fuera de [1900, actual+1] → 404 (evita espacio infinito de URLs); cross-link recíproco desde `/marcha/ano/{año}` cuando ese año cumple aniversario redondo hoy |
| N-08 | Anuario `/marchas/{año}` (ampliar el hub `/marcha/ano/{año}` actual) | — | ✅ Completado 2026-07-23 — sin ruta nueva: panel «Resumen del año» en el hub existente (compositor con más marchas, banda con más estrenos, marcha más grabada), reutilizando las queries de N-07; se omite en años thin (< `HUB_MIN_MARCHAS`) |
| N-10 | `/mapa` — coropleta SVG por provincia | ~~P-07 en prod~~ | ✅ Completado 2026-07-23 — mapa base SVG (52 provincias, ISO 3166-2:ES) adaptado de [jboekesteijn/provinces-of-spain](https://github.com/jboekesteijn/provinces-of-spain) (CC BY-SA 4.0, atribución en `assets/mapa-provincias.README.md`); `App\Mapa` colorea 5 niveles de intensidad (cortes no lineales: 1-9/10-49/50-149/150-399/400+, ajustados a lo concentrado del catálogo en Andalucía) y enlaza cada provincia con marchas a su hub; tabla accesible bajo el mapa con los mismos datos, sin depender del SVG. Verificado en navegador real, claro y oscuro |
| — | Ejecutar P-07 (`completar_provincia.php`) en **prod** | deploy hecho | ✅ Completado 2026-07-23 vía Plesk Scheduled Tasks ("Run a PHP script", requiere seleccionar PHP 8.4 explícitamente — el CLI por defecto del host es PHP 5.x y falla con `Unsupported declare 'strict_types'`). Resultado: 0 filas por actualizar (ya llegadas a prod en un sync anterior), 2 localidades sucias pendientes de curación manual («Hdad Cristo De Gracia», «El Sol») — no bloquean nada |
| — | Ejecutar `seed_dedicatorias.php` en **prod** | deploy hecho | ⏳ Pendiente in situ (mismo mecanismo que P-07, recordar seleccionar PHP 8.4) |

Cubren también **M9** (estadísticas ampliadas como contenido indexable).

**Las 4 pantallas de esta cola están completadas.** Siguiente bloque del plan de
palancas: entidades nuevas — ver el dossier del artefacto `1a31cc69` (§08).

### Análisis UX comparativo (patrimoniomusical.com) — ✅ CERRADO 2026-07-27

Plan aparte, no derivado del consejo ni de las palancas: comparativa de UX con
patrimoniomusical.com que arrancó con un diagnóstico de infraestructura del
servidor local. **Las 6 prioridades (0-5) están completadas** — ficha de
marcha compactada con anclas, legibilidad global, filtros facetados y tablas
ordenables en listados, catálogo cerrado de municipios (con selector en
cascada localidad→provincia) y mapa por localidad, y las mismas anclas de
navegación llevadas a compositor/banda/disco. Detalle completo, decisiones de
arquitectura (mapa, catálogo de municipios) y estado de cada prioridad en
[ux-analysis-estado.md](ux-analysis-estado.md); log narrativo en
`../ANALISIS_UX.md`.

**Corrección sobre el orden**: el dossier real secuencia **N-06 → N-03 → N-04/05**
(no N-03 primero, como se dijo en un resumen anterior de esta tabla), y **N-03
(hermandad) está condicionado explícitamente a que N-01 (dedicatorias) demuestre
tráfico real** — algo que no se puede verificar sin acceso a GoatCounter/Search
Console y muy probablemente prematuro a solo 2 semanas de publicarse N-01. El
propio dossier ofrece la vía intermedia adoptada: `/temporada/{año}` con alta
manual ya, hermandad como texto normalizado (sin entidad `hermandad` todavía, sin
N-06 automático todavía).

| # | Pantalla / tarea | Estado |
|---|------------------|--------|
| N-04/05 | Contratos banda↔hermandad↔año — tabla `contrato`, `/temporada/{año}`, alta manual desde `/dashboard/temporada/{año}` | ✅ Completado y migrado en prod 2026-07-23 — `HERMANDAD` es texto libre + `HERMANDAD_SLUG` normalizado (mismo espíritu que `dedicatoria_alias`, sin FK a una entidad `hermandad` que no existe aún); agrupado por hermandad en la página pública; noindex si hay menos de `HUB_MIN_MARCHAS` contratos ese año; rango válido [2020, actual+2]. **Incidente en el primer deploy**: la query nueva del sitemap rompió las ~5.700 URLs reales al no estar la tabla migrada — arreglado (try/catch aislado + degradado con gracia) y **migración 005 aplicada en prod** el mismo día. Tabla ya existe pero **vacía**: falta que el admin empiece a rellenar `/dashboard/temporada/{año actual}` a mano para que la pantalla pública muestre algo |
| N-06 | Ingesta semi-automática de anuncios de contrato (extender `tools/ingest`) | ⏳ Diferido — tarea grande y abierta (clasificador de texto sobre YouTube), no encaja en el patrón de "solo queries" del resto de pantallas de hoy |
| N-03 | Ficha de hermandad (entidad `hermandad` + `marcha_hermandad`) | ⏳ Bloqueada por el dossier — condicionada a tráfico real de N-01, no verificable ahora mismo |

### Calidad (plegado del consejo)
| # | Tarea | Depende de | Estado |
|---|-------|-----------|--------|
| M6 | Accesibilidad (foco, skip-link, `aria-sort`, contraste) + hoja de impresión | rediseño frontend | ⏳ |
| M7 | Notificaciones editoriales (email al aceptar/rechazar + digest semanal) | validar email/cron en HelioHost | ⏳ |
| T-03 | Vigilancia: cron backup (⚠️ estado contradictorio entre docs, ver [pendientes-post-cutover.md §2](pendientes-post-cutover.md)), uptime (✅), link-checker mensual | — | Parcial |

### Carril manual en paralelo (lo conduce el admin, no es código)
- **P-01 / M2** — curación de candidatos de ingesta y campaña de cobertura de
  audio. **Cambio de escala el 2026-07-28**: la ingesta desde el catálogo de
  streaming (rama `claude/filtrado-candidatas-videos-drdd1y`, ver arriba) hizo
  su primera pasada real sobre 49 bandas con perfil enlazado y produjo **616
  candidatos nuevos en 38 bandas** — cifra previa a los filtros de
  directo/Navidad/corroboración añadidos después, así que una repetición dará
  menos. El cuello de botella deja de ser *encontrar* marchas y pasa a ser
  *curarlas*: cada candidato necesita autor a mano (ninguna de las tres APIs
  devuelve compositor). Detalle en [ingesta-streaming.md](ingesta-streaming.md)
  (existe solo en esa rama hasta que se fusione).
- Las ramas `feat/ingest-youtube`/`feature/music-apps` citadas en versiones
  anteriores de este documento **siguen sin existir en el remoto** (verificado
  otra vez el 2026-07-29): el remoto solo tiene `main`, `pre` y ramas de sesión
  `claude/*`. Ese trabajo está fusionado o vive en local; no contar con él.
- **T-02** — pipeline de ingesta mensual semi-automático (piezas existen, falta
  orquestación).

### Largo plazo (4–12 meses) — no iniciado
L1–L6 del consejo: dumps abiertos versionados (L1), hubs enriquecidos por
advocación/hermandad con playlist (L2), biografías de compositores vía editores
(L3), formulario público «propón una grabación» (L4), PWA básica offline (L5),
revisión del hosting si el tráfico lo justifica (L6). Regla del consejo: nada
del largo plazo empieza sin el tablero de KPIs activo.

---

## Revisión del roadmap (2026-07-29)

> Alcance: las 5 ramas del remoto, los 18 documentos de `docs/` + `ANALISIS_UX.md`
> + `php/README.md`, los 4 issues abiertos y una comparativa con bases de datos
> especializadas externas. Objetivo declarado para Semana Santa 2027:
> **cobertura y calidad del dato** + **experiencia de uso**.

### Veredicto

**El roadmap es bueno y se puede confirmar como marco de trabajo.** Las razones,
en orden de peso:

1. **La secuencia del consejo se ha respetado de verdad.** La regla «cada mejora
   visible con su red de seguridad» no se quedó en el papel: CI, sync endurecido,
   monitorización y despliegue automático están en producción *antes* de la
   expansión de superficie pública. Es lo contrario de lo habitual.
2. **La decisión de 2026-07-23 (un solo tracker, pantallas N-*) fue acertada** y
   ha aguantado: no hay planes solapados compitiendo, y las tablas C/M están
   congeladas como fotografía en vez de reescribirse.
3. **El plan tiene el ritmo estacional correcto.** Lo caro y lo lento (cobertura
   de audio, curación) está en el carril manual, que es el que necesita meses;
   lo barato está en código.

Lo que **no** está bien es el desfase entre el tracker y la realidad: el trabajo
de los días 28–29 de julio (dos líneas completas, ~3.400 líneas, CI verde) vivía
fuera de este documento. Corregido arriba.

### Incoherencias y cabos sueltos detectados

| # | Hallazgo | Dónde | Acción |
|---|---|---|---|
| 1 | Dos líneas de trabajo terminadas y verdes **sin PR ni registro** en el tracker | ramas `claude/*` | ✅ Registradas arriba; abrir PR |
| 2 | **Recuentos de catálogo obsoletos**: `context.md`, `db-analysis.md` y el consejo dicen ~4.212 marchas; la pasada real del 2026-07-28 contó **5.003** en la BD | docs varios | Actualizar los tres al cerrar la próxima migración |
| 3 | **Cron de backup**: `cutover-fase5.md` lo da por configurado, `roadmap` T-03 lo marca «Parcial», `pendientes-post-cutover` §2 avisa de la contradicción — **sin resolver desde el 2026-07-06**. Es el único ítem 🟡 abierto de deuda | T-03 | Mirar Plesk una vez y cerrar el tema en los tres sitios |
| 4 | **`seed_dedicatorias.php` sigue pendiente en prod** desde el 2026-07-23 | tabla de la cola de código | Ejecutar por Scheduled Task (PHP 8.4 explícito) |
| 5 | **`/temporada` está publicada con la tabla `contrato` vacía** desde el 2026-07-23 | N-04/05 | O se siembra una temporada real antes de Cuaresma, o se despublica: una sección pública vacía es peor que no tenerla |
| 6 | **VPS de rollback sin desmantelar** ~3,5 semanas después del cutover, cuando el plan decía 1–2 | [pendientes-post-cutover §5](pendientes-post-cutover.md) | Decidir: apagarlo o declarar que se queda |
| 7 | Issue **#23 (M9)** sigue abierto aunque el roadmap lo da por cubierto con N-07/N-08/N-09 | GitHub | Cerrar con referencia a las tres pantallas |
| 8 | Tablas muertas `videos` (357 filas, nunca consultada) y `users` (0 filas) | [db-analysis.md](db-analysis.md) | Limpiar en la próxima migración (deuda 🟢 4.1) |

### Recomendaciones de producto (comparativa con bases de datos externas)

Referencias consultadas: [patrimoniomusical.com](https://www.patrimoniomusical.com/bd-marchas)
(el comparable directo: revista + BD + fonoteca + agenda + foro),
[marchasdeprocesion.com](https://www.marchasdeprocesion.com/) (mismo nicho, con
alcance internacional —España, Guatemala, Italia, Malta, Portugal, Perú— y foco
en compositores y partituras), la app *Música Cofrade* (streaming del nicho), y
tres modelos de referencia fuera del nicho:
[MusicBrainz](https://musicbrainz.org/doc/How_to_Use_Works) (separación
obra/grabación e identificadores ISWC/ISRC), [RISM](https://rism.info/)
(catalogación de fuentes musicales con incipit codificado) y Wikidata (enlazado
de autoridades).

Ordenadas por el objetivo declarado (dato + experiencia), no por coste:

| # | Recomendación | Por qué | Coste | Foco |
|---|---|---|---|---|
| **R-01** | **Capturar el ISRC** en la ingesta de streaming y guardarlo por grabación (`enlace_streaming`/`ingest_candidato`, columna nueva) | Spotify (`external_ids.isrc`) y Deezer (campo `isrc`) lo devuelven gratis; Apple/iTunes no. Es la **clave exacta** que hoy falta: la corroboración entre catálogos se hace por título normalizado y por eso exige ≥2 servicios, dejando fuera a las bandas con uno solo. Con ISRC, la misma grabación se reconoce aunque el título difiera, y desaparece el ruido de recopilatorios (una grabación en cinco discos). Además es el puente para enlazar con MusicBrainz | 3–4 h | 🎺 dato |
| **R-02** | **Mover la duración de la obra a la grabación** (`disco_marcha.DURACION_SEG`, manteniendo la de `marcha` como valor de referencia) | Hoy `DURACION_SEG` cuelga de `marcha`, que es la **obra**; la duración es una propiedad de cada **grabación** y varía entre versiones. La ingesta ya la trae de las tres APIs y se está tirando. patrimoniomusical publica duración por grabación | 3 h | 🎺 dato |
| **R-03** | **Identificadores externos y `sameAs`**: tabla genérica (mismo patrón que `enlace_streaming`) con Wikidata/MusicBrainz/VIAF para compositores y bandas, volcados al JSON-LD | Es lo que convierte una ficha en una **entidad reconciliable** para Google y para los LLM: dejar de ser «una web que habla de Font de Anta» y pasar a ser un nodo identificable. Encaja con la apuesta M1/L1 ya hecha (API + CC BY + `llms.txt`) sin cambiar de estrategia | 6 h | 🔍 dato |
| **R-04** | **Partituras**: campo/enlace de edición por marcha (editorial, año, dominio público, PDF externo) + hub «marchas con partitura disponible» | Es el hueco funcional más claro frente a marchasdeprocesion.com, y el dato que le falta a la audiencia que **toca** la marcha, no solo la escucha. No requiere alojar nada: basta enlazar y declarar | 6 h | 🎺 dato |
| **R-05** | **Promover M6 (accesibilidad + hoja de impresión)** de «calidad plegada» a tarea con fecha, antes de Cuaresma | Es la única pendiente del consejo con impacto directo en experiencia, y la hoja de impresión da el 80 % del caso de uso «llevar la ficha a la calle» que L5 (PWA, 15 h) resolvería al 100 %. Con la gramática bibliográfica de la ficha, imprimir es casi gratis | 6 h | 🎺 uso |
| **R-06** | **Estado vacío de «Escuchar» con CTA + formulario público «propón una grabación»** (L4, adelantado) | Convierte el hueco de cobertura en entrada de datos: el visitante estacional que conoce la grabación es hoy tráfico que se pierde. Alimenta la misma cola de propuestas que ya existe, sin superficie de escritura nueva | 8–12 h | 🎺 dato + uso |
| **R-07** | **Página pública «estado del catálogo»** con el KPI de cobertura (% de marchas con escucha, por año y por banda) | El issue [#16](https://github.com/jgcoronado/mdc-back/issues/16) pide medir antes/después y **no hay forma de medirlo hoy**. Publicarlo, además, es contenido indexable y honesto sobre lo que falta — y sirve de mapa de curación para el admin | 4 h | 🎺 dato |
| **R-08** | **Búsqueda: filtro «solo con audio» y tolerancia a acentos/erratas** en banda y disco (hoy van por `LIKE`, no por FTS5) | Es la queja clásica de las BD del nicho y el filtro que más usa quien busca algo que escuchar | 4 h | 🎺 uso |

**Descartado explícitamente** (para que no vuelva a proponerse): **incipit
musical codificado** al estilo RISM ([Plaine & Easie](https://rism.digital/plaine-and-easie/v2/)).
Es el estándar correcto para catalogar fuentes musicales y encaja con la
vocación bibliográfica de la ficha, pero exige transcribir a mano ~5.000
incipits y un renderizador de notación. Con un mantenedor único, el coste no
guarda ninguna proporción con el beneficio.

### Siguientes pasos propuestos (orden de ejecución)

1. **Fusionar las dos ramas abiertas** (diseño → ingesta), relanzar el smoke
   sobre el resultado y desplegar. Borrar `…x60kfw`.
2. **Cerrar los cabos sueltos 3, 4, 5 y 7** de la tabla de arriba: son horas
   sueltas que llevan semanas abiertas y ensucian el criterio de «qué está hecho».
3. **Curar los 616 candidatos** (carril manual) con R-01 y R-07 ya dentro: el
   ISRC y el KPI valen mucho más *antes* de la campaña que después.
4. **R-05 + R-06** como bloque de experiencia antes de Cuaresma 2027.
5. R-02, R-03, R-04, R-08 según quede margen; ninguna bloquea a las demás.

---

## Cómo mantener este roadmap

- El tracker vivo es la sección **«Plan forward activo»** (pantallas N-* + M6/M7).
  Al cerrar una N-*/tarea: marcarla ✅ aquí y actualizar el dossier de palancas
  (artefacto `1a31cc69`, misma URL) — **no** reescribir los informes de origen
  (consejo/palancas son evaluaciones puntuales, no trackers).
- Las tablas C1–C8 / M1–M9 quedan como **fotografía histórica cerrada**; no se
  añaden filas nuevas ahí.
- Si surge una decisión arquitectónica nueva → `architecture.md` (ADRs), no aquí.
- Si se descubre deuda técnica nueva → `technical-debt.md`, no aquí.
- **Una rama empujada al remoto con trabajo terminado se registra aquí el mismo
  día**, aunque no tenga PR todavía. La revisión del 2026-07-29 encontró dos
  líneas completas invisibles para el tracker; el coste de eso no es el olvido,
  es que cualquier sesión nueva planifica sobre un estado falso.
