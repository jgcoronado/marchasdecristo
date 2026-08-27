# Hoja de ruta — marchasdecristo.com

> Generado: 2026-06-01 · Reescrito: 2026-07-16 (C8) · **Reorganizado como fuente
> única: 2026-07-29**
>
> **Este documento es el único sitio donde se decide y se consulta qué se hace a
> continuación.** Los dossieres de origen (consejo de sabios, plan de palancas)
> son evaluaciones puntuales con fecha: se citan, no se reescriben ni se
> consultan para planificar. Si algo no está en §2 de este documento, no está
> en el plan.

---

## 1. Cómo leer este documento

| Sección | Qué contiene | ¿Se edita? |
|---|---|---|
| **§2 Plan priorizado** | El backlog único, ordenado por prioridad de ejecución | ✅ Sí — es el tracker vivo |
| **§3 Contraste con el consejo de sabios** | Trazabilidad de C1–C8 / M1–M9 / L1–L6 y en qué discrepa esta revisión | ✅ Al cerrar un ítem del consejo |
| **§4 Ramas abiertas** | Trabajo terminado que aún no está en `main` | ✅ El mismo día que se empuja una rama |
| **§5 Ya en producción** | La base sobre la que se construye | ✅ Al desplegar algo nuevo |
| **§6 Histórico congelado** | Fotografías cerradas (C/M, cola N-*, análisis UX) | ❌ No se tocan |

**Nomenclatura**: cada tarea conserva la referencia de su origen —`C`/`M`/`L`
(consejo de sabios), `N`/`P`/`T` (plan de palancas), `R` (revisión 2026-07-29),
`D` (deuda técnica), `OPS` (operativa)— para poder rastrearla hasta el documento
que la propuso. **No se crean referencias nuevas**: una tarea sin origen es una
tarea que nadie ha justificado.

**Objetivo declarado para Semana Santa 2027** (fija el orden de §2):
**cobertura y calidad del dato** + **experiencia de uso**. El SEO/IA sigue
siendo un beneficio buscado, pero ya no es el criterio de desempate: la base
técnica que exigía el consejo está construida.

---

## 2. Plan priorizado — el backlog único

Coste en horas de trabajo del mantenedor (equipo = 1 persona + asistentes IA).
Foco: 🎺 dato/experiencia marcha-céntrica · 🔍 SEO/robots IA · ⚙️ operativa.

### P0 · Desbloquear lo que ya está hecho — días, no semanas

Nada de esto es trabajo nuevo: es cerrar cosas terminadas o decididas a medias.
Mientras P0 siga abierto, el estado real del proyecto no coincide con el
documentado, que es lo que hace que se planifique mal.

| Ref | Tarea | Coste | Estado |
|---|---|---|---|
| ~~B-01~~ | ~~Fusionar todas las ramas `claude/*` sueltas + M6/M7, relanzar smoke, PR `pre`→`main`~~ | — | ✅ 2026-08-02 — PR [#27](https://github.com/jgcoronado/mdc-back/pull/27) fusionado a `main` (`1569fed`). No verificable desde aquí si el deploy automático a PRO se completó (sin red saliente para comprobar `/health` en producción). Sigue bloqueado borrar del remoto las 4 ramas `claude/*` ya integradas (permiso), pendiente a mano del mantenedor |
| **B-02** | `pre` volvió a acumular trabajo sin subir a `main`: **29 commits entre el 2026-07-31 y el 2026-08-06** (sin actividad desde hace 3 semanas) — detalle completo en §4.2 | 1–2 h revisión | 🟡 Pendiente de abrir PR y validar |
| **B-03** | Fusionar `design/paleta-variables` (rama suelta, 1 commit, 2026-08-12, ni siquiera en `pre`) — refactor de la paleta CSS a variables editables, sin cambio de comportamiento, contraste verificado. Detalle en §4.3 | 15 min | 🟡 Rama suelta sin fusionar |
| ~~OPS-01~~ | ~~Aplicar la migración `008_ingest_streaming.sql` en local e importar los candidatos; subir con `sync_db_to_prod.php`~~ | — | ✅ 2026-08-27 — migración 008 aplicada y candidatos importados en local (339 de streaming: 336 Spotify + 3 Deezer, sobre 2.565 de YouTube; 803 aceptados, 1.681 descartados, 17 duplicados, 403 aún pendientes de revisión — eso es M2, no esto), `mdc.db` sincronizado a producción a mano por el mantenedor |
| **OPS-02** | Ejecutar `seed_dedicatorias.php` en **prod** (Plesk → Scheduled Tasks → «Run a PHP script», **seleccionar PHP 8.4 explícitamente**) — pendiente desde el 2026-07-23 | 15 min | ⏳ 2026-08-27: primer intento falló en el parseo (`declare(strict_types=1)` y tipos de retorno no soportados) — la tarea de Plesk corrió con una versión de PHP vieja, no con la 8.4. No llegó a tocar la BD, sin efectos secundarios. Repetir seleccionando PHP 8.4 en el desplegable de la propia tarea (no basta con el comando) |
| **OPS-04** | Activar **M7** en producción de verdad: `mail_from`/`mail_admin_to`/`notif_emails` en el `config.local.php` del servidor + `digest_semanal.php` en Plesk Scheduled Tasks (PHP 8.4, lunes 08:00). El código ya está en `main` desde el 2026-08-02 (`71cabb9`/`7975e02`) — sin esto no manda ningún email | 20 min | ⏳ |
| ~~OPS-03 · T-03~~ | ~~Verificar en Plesk si el cron de backup existe de verdad~~ | — | ✅ 2026-07-29 — confirmado en Plesk, backup manual de comprobación ejecutado (`mdc-20260729-132640.db`, 9,6 MB). Detalle en [pendientes-post-cutover.md §2](pendientes-post-cutover.md) |
| ~~DEC-01~~ | ~~Decidir sobre `/temporada`~~ | — | ✅ 2026-07-29 — **se oculta en producción** (404 + fuera de nav/sitemap/`llms.txt`), pero **queda visible en PRE** para rellenarla y validarla antes de publicar. **Revisado el 2026-08-03**: también se oculta en PRE, junto con dedicatorias, estado del catálogo y mapa; las cuatro se publican solo en local hasta que maduren (`App\Secciones`, ver [entornos.md](entornos.md)) |
| ~~DEC-02~~ | ~~Decidir sobre el VPS de rollback~~ | — | ✅ 2026-07-29 — **apagado por completo** (contenedor, servidor y TTL de DNS revertido). El runbook de rollback de infraestructura de [cutover-fase5.md §7](cutover-fase5.md) queda obsoleto: no hay ya destino al que volver |
| ~~R-00a~~ | ~~Cerrar el issue #23 (M9), cubierto por N-07/N-08/N-09/N-10~~ | — | ✅ 2026-07-29 |
| ~~R-00b~~ | ~~Resolver la contradicción documental del cron de backup (tres documentos, tres versiones)~~ | — | ✅ 2026-07-29 |
| ~~R-00c~~ | ~~Fechar los recuentos de catálogo (`context.md`, `db-analysis.md`): la BD tiene ~5.000 marchas, no 4.212~~ | — | ✅ 2026-07-29 |

### P1 · Antes de octubre 2026 — preparar la temporada (foco: dato)

La campaña de cobertura de audio es el trabajo más largo del año y el único que
no se puede acelerar con código. Todo lo de este bloque existe **para que esa
campaña se haga una vez y bien**, no dos veces.

| Ref | Tarea | Por qué va aquí | Coste | Foco |
|---|---|---|---|---|
| **R-01** | **Capturar el ISRC** en la ingesta de streaming (columna nueva en `enlace_streaming` / `ingest_candidato`) | Spotify (`external_ids.isrc`) y Deezer (campo `isrc`) lo devuelven gratis y hoy se tira. Es la clave exacta que falta: la corroboración entre catálogos se hace por título normalizado y por eso exige ≥2 servicios, dejando fuera a las bandas con uno solo. Con ISRC la misma grabación se reconoce aunque el título difiera, y desaparece el ruido de recopilatorios. **Vale mucho más antes de curar 616 candidatos que después** | 3–4 h | 🎺 |
| **R-07** | **Página pública «estado del catálogo»** con el KPI de cobertura (% de marchas con escucha, por año y por banda) — 🟡 **código completo en `pre`** (`/estado-catalogo`, `Pages::estadoCatalogo`), se publica al fusionar **B-02** | El issue [#16](https://github.com/jgcoronado/mdc-back/issues/16) pide medir antes/después y **hoy sigue sin verse en producción**. Además es el mapa de curación del admin y contenido indexable honesto sobre lo que falta | 4 h → hecho, falta desplegar | 🎺🔍 |
| **M2 · P-01** | **Campaña de cobertura de audio**: curar los 616 candidatos + la cola de YouTube | Carril manual, arranca en cuanto R-01 y R-07 estén. Cuello de botella real: **el autor**, que ninguna de las tres APIs devuelve | 15 h+ | 🎺 |
| **R-02** | **Mover la duración de la obra a la grabación** (`disco_marcha.DURACION_SEG`, manteniendo la de `marcha` como referencia) — *herramienta hecha, falta ejecutarla* | Hoy `DURACION_SEG` cuelga de `marcha`, que es la **obra**; la duración es propiedad de cada **grabación** y varía entre versiones. La ingesta ya la trae de las tres APIs y se descarta. `app/tools/fill_duraciones.php` lee el tracklist de los álbumes ya enlazados en `enlace_streaming` y rellena `disco_marcha` (1.325 de 1.514 pistas en el primer dry-run, precisión 98,6%). Segunda pasada: `marcha.DURACION_SEG` = mediana de sus grabaciones cuando las tiene (826 marchas), y valor de catálogo intacto cuando no. Ver [pendientes-manuales-2026-07-31.md](pendientes-manuales-2026-07-31.md) | 3 h | 🎺 |
| **D-2.1** | `PRAGMA integrity_check` sobre el backup recién creado + copia externa fuera de HelioHost | Único ítem 🟡 abierto de [technical-debt.md](technical-debt.md). Un backup que nadie verifica no es un backup | 3 h | ⚙️ |
| **T-02** | Orquestador único de la ingesta mensual (extract → classify → dedup → import + resumen) | Las piezas existen; sin orquestación la campaña depende de recordar seis comandos | 4 h | ⚙️ |

### P2 · Cuaresma 2027 (nov 2026 – feb 2027) — foco: experiencia

El año se juega en ~8 semanas. Lo de este bloque tiene que estar **desplegado y
asentado antes de Cuaresma**, no durante.

| Ref | Tarea | Por qué va aquí | Coste | Foco |
|---|---|---|---|---|
| **R-06 · L4** | **Estado vacío de «Escuchar» con CTA** + **formulario público «propón una grabación»** | Convierte el hueco de cobertura en entrada de datos: hoy el visitante que conoce la grabación es tráfico que se pierde. Reutiliza la cola de propuestas existente, sin superficie de escritura nueva. Adelantado desde el largo plazo del consejo | 8–12 h | 🎺 |
| **R-08** | **Búsqueda**: filtro «solo con audio» + tolerancia a acentos/erratas en banda y disco (hoy van por `LIKE`, no por FTS5) — el cálculo de cobertura (`Repo::conAudio`, usado por R-07) ya existe, falta exponerlo como filtro en `/buscar` | Es el filtro que más usa quien busca algo que escuchar, y llega justo cuando la campaña de audio lo hace útil | 3–4 h (bajó de 4 h: la mitad ya está) | 🎺 |
| **R-04** | **Partituras**: enlace/edición por marcha (editorial, año, dominio público, PDF externo) + hub «marchas con partitura disponible» — el campo ya existe en `templates/admin/marcha_form.php`, falta el hub público | Hueco funcional más claro frente a [marchasdeprocesion.com](https://www.marchasdeprocesion.com/), y el dato que le falta a quien **toca** la marcha. No requiere alojar nada: basta enlazar y declarar | 4–5 h (bajó: falta solo el hub) | 🎺🔍 |

> M6 y M7 se cerraron el 2026-08-02 (código en `main`) — ver §3.1 y §5. M7
> necesita además **OPS-04** (config de correo + cron) para activarse de
> verdad.

### P3 · Después de Semana Santa 2027, o condicionado

**Regla del consejo, aún vigente y aún incumplida**: *nada de este bloque empieza
sin el tablero de KPIs activo*. R-07 cubre la mitad (cobertura); falta la otra
mitad — GoatCounter y Search Console revisados con **cadencia fija**. Sin eso,
P3 no se planifica.

| Ref | Tarea | Condición / motivo de aplazamiento |
|---|---|---|
| **R-03** | Identificadores externos (Wikidata / MusicBrainz / VIAF) + `sameAs` en JSON-LD | Alto valor SEO/IA, pero el objetivo declarado de 2027 es dato + experiencia. Rinde más con el catálogo ya curado |
| **N-03** | Ficha de hermandad (entidad `hermandad` + `marcha_hermandad`) | Bloqueada por el dossier de palancas: condicionada a que N-01 (dedicatorias) demuestre tráfico real — no verificable sin el tablero |
| **N-06** | Ingesta semi-automática de anuncios de contrato | Diferida: clasificador de texto sobre YouTube, tarea grande y abierta. **No confundir con la ingesta de streaming ya construida**: aquella descubre marchas, esta descubriría contratos |
| **L1** | Dumps abiertos versionados (CSV/SQLite) con CC BY | El prerrequisito (licencia + página «Datos») ya está hecho en M1 |
| **L2** | Hubs enriquecidos por advocación/hermandad con playlist | Depende de cobertura de audio (P1) |
| **L3** | Biografías de compositores vía editores | Continuo, depende de tener editores activos |
| **L5** | PWA básica offline | M6 (impresión) cubre el caso de uso barato primero |
| **L6** | Revisión del hosting | Decisión con datos del tablero, no antes |
| **D-4.1** | Limpiar esquema heredado: sentinelas `BANDA_ESTRENO = 0`, tablas muertas `videos` (357 filas, nunca consultada) y `users` (0 filas) | 🟢 Baja: no bloquea nada. Aprovechar la siguiente migración |

### Descartado explícitamente

Para que no vuelva a proponerse en una sesión futura:

- **Incipit musical codificado** al estilo RISM ([Plaine & Easie](https://rism.digital/plaine-and-easie/v2/)).
  Es el estándar correcto para catalogar fuentes musicales y encaja con la
  vocación bibliográfica de la ficha, pero exige transcribir a mano ~5.000
  incipits y un renderizador de notación. Con un mantenedor único, el coste no
  guarda ninguna proporción con el beneficio.
- **Tidal y Amazon Music** como fuentes de ingesta: sin API pública de catálogo
  (ya decidido en [plan-music-apps.md](plan-music-apps.md) §3).
- **YouTube como fuente de descubrimiento** de marchas: un canal mezcla marchas
  con conciertos, ensayos y vídeos de hermandad, y mete más ruido del que
  aporta. Sigue siendo válido como **origen de audio** de una marcha concreta.

---

## 3. Contraste con el plan del consejo de sabios

El [consejo de sabios](consejo-de-sabios-2026-07.md) (2026-07-12) es el plan más
completo que ha tenido el proyecto y su diagnóstico se ha sostenido: las cinco
perspectivas acertaron. Esta sección cierra la trazabilidad —qué fue de cada
ítem— y deja por escrito **en qué discrepa esta revisión**, para que la
discrepancia sea una decisión y no un olvido.

### 3.1 Trazabilidad completa

| Consejo | Estado hoy | Dónde vive ahora |
|---|---|---|
| C1 Hubs indexables | ✅ En producción | §5 |
| C2 `lastmod` + IndexNow | ✅ En producción | §5 |
| C3 Marcha del día + home | ✅ En producción | §5 |
| C4 `og:image` de marca | ✅ Superado por M4 | §5 |
| C5 CI con smoke tests | ✅ En producción | §5 |
| C6 Uptime externo | ✅ En producción ([monitoring.md](monitoring.md)) | §5 |
| C7 Sync endurecido | ✅ En producción | §5 |
| C8 Docs al stack real | ✅ Hecho — pero ver §3.2, punto 4 | §5 |
| M1 API + `llms.txt` + feeds + «Datos» | ✅ En producción | §5 |
| M2 Cobertura de audio | ⏳ **Activo** — cambió de naturaleza, ver §3.2 punto 1 | **P1** |
| M3 Búsqueda global | ✅ En producción (N-11) | §5 · ampliación en **R-08** (P2) |
| M4 `og:image` dinámica | ✅ En producción | §5 |
| M5 Deploy automatizado | ✅ En producción (PRE + PRO, [entornos.md](entornos.md)) | §5 |
| M6 Accesibilidad + impresión | ✅ En `main` desde 2026-08-02 (`71cabb9`) | §5 |
| M7 Notificaciones editoriales | ✅ Código en `main` desde 2026-08-02 (`7975e02`) — activación real pendiente de **OPS-04** | §5 (código) / P0 (activación) |
| M8 Slugify unificado + CSP/HSTS | ✅ En producción | §5 |
| M9 Estadísticas indexables | ✅ Cubierta por N-07/N-08/N-09/N-10 — issue #23 cerrado el 2026-07-29 | §6 |
| L1 Dumps versionados | ⏳ No iniciada | **P3** |
| L2 Hubs enriquecidos + playlist | ⏳ No iniciada | **P3** |
| L3 Biografías vía editores | ⏳ No iniciada | **P3** |
| L4 «Propón una grabación» | ⏳ **Adelantada** — ver §3.2 punto 3 | **P2** (como R-06) |
| L5 PWA offline | ⏳ No iniciada, parcialmente sustituida | **P3** |
| L6 Revisión de hosting | ⏳ Condicionada al tablero | **P3** |

**Balance: 13 de 23 ítems del consejo están en producción en 17 días** (del
2026-07-12 al 2026-07-29), incluidas las 8 tareas de corto plazo completas. El
plan del consejo no falló en ningún punto: se ejecutó.

### 3.2 En qué discrepa esta revisión (y por qué)

1. **M2 ya no es el problema que el consejo describió.** El consejo la planteó
   como «ejecutar la ingesta de YouTube y curar», 15 h. La ingesta desde el
   catálogo de streaming (2026-07-28) cambió el cuello de botella: descubrir
   marchas dejó de ser difícil —616 candidatos en una pasada— y ahora lo caro es
   **atribuir el autor**, que ninguna API devuelve. Consecuencia práctica: R-01
   (ISRC) y R-07 (KPI) se ejecutan **antes** de la campaña, no después. El
   consejo no podía preverlo porque ese pipeline no existía.
2. **M6 sube de prioridad.** El consejo la puso en medio plazo con repercusión
   «media». Esta revisión la lleva a P2 porque, con el objetivo declarado de
   experiencia de uso, la hoja de impresión es la ruta barata (6 h) al caso de
   uso de calle que L5 (PWA, 15 h) resolvería caro — y la accesibilidad es
   transversal a todo lo que se ha construido desde entonces.
3. **L4 se adelanta del largo plazo a P2.** El consejo la ordenó por coste
   (12 h). Esta revisión la sube porque sirve a los **dos** objetivos declarados
   a la vez —dato y experiencia— y porque la infraestructura que necesitaba (la
   cola de propuestas) ya está en producción, cosa que no era cierta cuando se
   escribió el plan.
4. **C8 se dio por hecha y el problema era otro.** El consejo pedía «docs
   actualizadas al stack real», y eso se hizo. Pero la revisión del 2026-07-29
   encontró dos líneas de trabajo terminadas, con CI verde, **invisibles para el
   tracker**: el fallo no era el contenido de la documentación sino su
   **frescura**. De ahí la regla nueva de §7.
5. **Dos huecos que el consejo no vio**, detectados al comparar con bases de
   datos externas del nicho y de fuera de él: **identificadores externos**
   (R-03) y **partituras** (R-04). Ninguna de las cinco perspectivas del consejo
   miró hacia fuera del proyecto; el DAFO se hizo sobre el código propio.
6. **La regla de secuencia del consejo sigue sin cumplirse.** «Nada del largo
   plazo empieza sin el tablero de KPIs activo»: no hay tablero. Se respeta —P3
   está formalmente bloqueado— pero conviene decirlo en voz alta: es la única
   condición del consejo que lleva 17 días incumplida, y R-07 solo cubre la
   mitad.

**Referencias externas consultadas en la comparativa** (2026-07-29):
[patrimoniomusical.com](https://www.patrimoniomusical.com/bd-marchas) (el
comparable directo: revista + BD + fonoteca + agenda + foro),
[marchasdeprocesion.com](https://www.marchasdeprocesion.com/) (mismo nicho, con
alcance internacional y foco en compositores y partituras), la app *Música
Cofrade* (streaming del nicho), [MusicBrainz](https://musicbrainz.org/doc/How_to_Use_Works)
(separación obra/grabación, ISWC/[ISRC](https://musicbrainz.org/doc/ISRC)),
[RISM](https://rism.info/) (catalogación de fuentes con incipit codificado) y
Wikidata (enlazado de autoridades).

---

## 4. Ramas abiertas (última actualización 2026-08-27)

**El ciclo de B-01 se cerró — y volvió a abrirse.** El PR
[#27](https://github.com/jgcoronado/mdc-back/pull/27) (`pre`→`main`) se
fusionó el 2026-08-02 (`1569fed`). Desde entonces **`pre` ha vuelto a
acumular 29 commits sin subir a `main`** (2026-07-31 → 2026-08-06, sin
actividad desde hace tres semanas), más una rama suelta que no ha llegado ni
a `pre`. Es el mismo patrón que motivó B-01 la primera vez — ver «Patrón
detectado» al final de esta sección.

### 4.1 `main` — última fusión: PR #27 (2026-08-02, `1569fed`)

Incluye M6 (accesibilidad + hoja de impresión, `71cabb9`) y M7
(notificaciones editoriales, `7975e02`), la ingesta desde el catálogo de
streaming de las bandas, el rediseño discreto/sencillo y el alta de discos
con portada y pistas. Detalle completo en el histórico de §6.1–§6.2.
**No verificable desde aquí** si el deploy automático a PRO llegó a
completarse (sin salida de red para comprobar `/health` en producción desde
esta sesión) — confírmalo cuando puedas.

### 4.2 `pre` — 29 commits por delante de `main`, sin PR abierto (**B-02**)

| Bloque | Contenido | Dónde está en el código |
|---|---|---|
| R-07 | Página pública «estado del catálogo» completa: KPI de cobertura global, por año y por banda | `routes.php` (`/estado-catalogo`), `Pages::estadoCatalogo`, `templates/estado_catalogo.php` |
| Migraciones | `009_contrato_localidad.sql`, `010_enlace_unicos.sql` | `php/app/tools/sql/` |
| Alta asistida de discos | Importar las pistas desde el enlace del álbum en streaming, con confirmación | `App\ImportadorPistas`, `App\Tracklist`, `templates/admin/disco_importar.php`, `/dashboard/disco/{id}/importar*` |
| Cascada de enlaces | Enlace automático al guardar el enlace de un disco + desglose por servicio de lo no encontrado | `App\EnlacesAuto`, `app/tools/fill_enlaces_cascada.php` |
| Ingesta | Asociar un candidato pendiente a una marcha ya catalogada, en vez de alta duplicada o descarte | `Admin::ingestaAsociar`, pestaña nueva en `templates/admin/ingesta_detail.php` |
| Ficha pública | Versión original/actual de escucha, separadas | `/dashboard/marcha/{id}/social`, `templates/marcha_detail.php` |
| Visibilidad de secciones | `/temporada`, dedicatorias, estado del catálogo y mapa fuera de local, centralizado (ya no se lee `config['preproduccion']` a mano) | `App\Secciones`, `App\Entorno` |
| Calidad | Auditoría PHPStan/jscpd/PHPMD con gate en CI; bootstrap CLI unificado (53→43 clones dup., 3,91%→3,20%) | `docs/code-quality.md`, `phpstan.neon.dist`, `phpmd.xml`, `scripts/quality.sh`, `app/tools/_cli.php` |
| Fixes | `curl_close()` obsoleto en PHP 8.5; `aprobarEnlace` sin depender de una `UNIQUE` que no todas las bases tienen; la preselección de importación perdía altas recientes | `935070e`, `1fb471a`/`0924b5a`, `e483989` |
| Docs | `ai-handoff-guide.md` (nuevo, punto de entrada para otra IA), `technical-debt.md` ampliado, `duplicados-2026-07-31.md`, `enlaces-otras-rrss-2026-08-01.md`, `pendientes-manuales-2026-07-31.md` | `docs/` |

**Falta para cerrar B-02**: confirmar lint + smoke 82/82 en local (o que el CI
de `pre` esté verde — no verificable desde aquí sin acceso a GitHub Actions),
validación visual del mantenedor en PRE, PR `pre`→`main`, fusionar → deploy a
PRO + smoke remoto PRO.

### 4.3 Rama suelta sin fusionar ni a `pre` — `design/paleta-variables` (**B-03**)

1 commit (`2c4d2fc`, 2026-08-12). Reorganiza `app.css` en cuatro bloques de
variables editables (colores base por tema, proporciones de mezcla, tonos
derivados con `color-mix()`, excepciones declaradas de marca/impresión) sin
cambio de comportamiento — contraste verificado token a token, todas las
combinaciones dentro de ±0,5 del valor anterior y ninguna baja de 4,5:1. De
paso corrige tres colores que caían al valor de reserva (`--accent` en
`.link-btn`, `--color-primary`/`--color-danger` en `ingesta_detail.php`).
Bajo riesgo — fusionable primero y sin esperar a B-02.

### Patrón detectado

Es la segunda vez que `pre` acumula semanas de trabajo terminado antes de
promoverlo a `main` — el mismo problema que motivó B-01. La causa no es
técnica (el pipeline `pre`→PRE es automático en cada push); es que nadie abre
el PR `pre`→`main` hasta que se acumula un bloque grande. Recomendación:
abrir ese PR con cadencia corta (cada 1–2 semanas, o al cerrar un bloque de
trabajo) en vez de esperar a que se note.

---

## 5. Ya en producción (la base sobre la que se construye)

Hubs año/estilo/provincia (C1/P-05) · Dedicatorias **N-01/N-02** (índice + hub +
panel de curación) · Búsqueda global **N-11** (`/buscar` + `/api/buscar`) ·
API + feeds + «Datos» (M1; `/feed.xml` **es** el «novedades» de P-09) ·
`og:image` dinámica (M4) · Vídeo YouTube en ficha (P-02, `App\Media`) ·
GoatCounter opt-in (P-08) · Slugify unificado + CSP/HSTS (M8) · **N-07**
`/rankings` + `/rankings/{año}` · **N-08** «Resumen del año» · **N-09**
`/aniversarios/{año}` · **N-10** `/mapa` + `/mapa/provincia/{slug}` ·
**N-04/05** `/temporada` · CI
con smoke tests (C5) ·
uptime externo (C6) · sync endurecido con checksum y rollback (C7) · despliegue
automático PRE/PRO (M5) · catálogo cerrado de municipios y selector en cascada
(análisis UX, prioridad 4) · **M6** accesibilidad + hoja de impresión (`71cabb9`) · **M7** notificaciones editoriales — código en `main` (`7975e02`; activación real pendiente de **OPS-04**).

---

## 6. Histórico congelado

> Fotografías cerradas. **No se marca ni se añade nada en estas tablas**: si un
> ítem revive, entra en §2 con su referencia de origen.

### 6.1 Corto plazo del consejo (C1–C8) — cerrado 2026-07-16

8 de 8 completadas: hubs indexables (C1, [#7](https://github.com/jgcoronado/mdc-back/issues/7)),
`lastmod` + IndexNow (C2, [#8](https://github.com/jgcoronado/mdc-back/issues/8)),
marcha del día (C3, [#9](https://github.com/jgcoronado/mdc-back/issues/9)),
`og:image` de marca (C4, [#10](https://github.com/jgcoronado/mdc-back/issues/10)),
CI con smoke tests (C5, [#11](https://github.com/jgcoronado/mdc-back/issues/11)),
uptime externo (C6, [#12](https://github.com/jgcoronado/mdc-back/issues/12)),
sync endurecido (C7, [#13](https://github.com/jgcoronado/mdc-back/issues/13)) y
documentación al stack real (C8, [#14](https://github.com/jgcoronado/mdc-back/issues/14)).

### 6.2 Medio plazo del consejo (M1–M9) — congelado 2026-07-23

5 de 9 completadas en su momento (M1, M3, M4, M5, M8). Las 4 restantes se
reencauzaron: **M2** al carril de audio (P1), **M6** y **M7** a P2, **M9**
absorbida por las pantallas N-07/N-08/N-09/N-10 (issue #23 cerrado el
2026-07-29). Detalle de cada una en
[consejo-de-sabios-2026-07.md §7](consejo-de-sabios-2026-07.md) y en el cuerpo
de sus issues.

Nota sobre M5: el entorno de preproducción se retiró el 2026-07-23 (Plesk no
deja mover el document root del subdominio) y se **reintrodujo el 2026-07-28**
aislándolo desde el código (`env.php` desvía `APP_DIR`), sin tocar Plesk.

### 6.3 Cola de código N-* (agosto–septiembre) — cerrada 2026-07-23

Las 4 pantallas completadas, todas con «solo queries sobre datos existentes»:

- **N-07** `/rankings` — `/estadisticas` renombrado con 301 permanente;
  `/rankings/{año}` con umbral `HUB_MIN_MARCHAS` (thin → noindex), índice por
  décadas, cross-link con `/marcha/ano/{año}`.
- **N-09** `/aniversarios/{año}` — tramos de 25 en 25 hasta 200 (centenarios
  destacados 🎉); `/aniversarios` redirige 302 al año en curso; fuera de
  [1900, actual+1] → 404 (evita un espacio infinito de URLs).
- **N-08** Anuario — sin ruta nueva: panel «Resumen del año» dentro del hub
  `/marcha/ano/{año}`, reutilizando las queries de N-07; se omite en años thin.
- **N-10** `/mapa` — coropleta SVG de 52 provincias (ISO 3166-2:ES) adaptada de
  [jboekesteijn/provinces-of-spain](https://github.com/jboekesteijn/provinces-of-spain)
  (CC BY-SA 4.0, atribución en `assets/mapa-provincias.README.md`), 5 niveles de
  intensidad con cortes no lineales, y tabla accesible con los mismos datos sin
  depender del SVG.
- **P-07** (`completar_provincia.php`) ejecutado en prod el 2026-07-23 vía Plesk
  Scheduled Tasks: 0 filas por actualizar, 2 localidades sucias pendientes de
  curación manual («Hdad Cristo De Gracia», «El Sol») — no bloquean nada.

**N-04/05** (contratos banda↔hermandad) se completó y migró en prod el
2026-07-23: `HERMANDAD` es texto libre + `HERMANDAD_SLUG` normalizado (mismo
espíritu que `dedicatoria_alias`, sin FK a una entidad `hermandad` que no existe
aún). **Incidente del primer deploy**: la query nueva del sitemap rompió las
~5.700 URLs reales al no estar la tabla migrada — arreglado (try/catch aislado +
degradado con gracia) y migración `005` aplicada el mismo día.

### 6.4 Análisis UX comparativo (patrimoniomusical.com) — cerrado 2026-07-27

Plan aparte, no derivado del consejo ni de las palancas. **Las 6 prioridades
(0–5) completadas**: ficha de marcha compactada con anclas, legibilidad global,
filtros facetados y tablas ordenables, catálogo cerrado de municipios con
selector en cascada y mapa por localidad, y las mismas anclas de navegación
llevadas a compositor/banda/disco. Detalle y decisiones de arquitectura en
[ux-analysis-estado.md](ux-analysis-estado.md); log narrativo en
`../ANALISIS_UX.md`.

### 6.5 Los dos planes solapados — resuelto 2026-07-23

El proyecto tuvo **dos planes redactados casi a la vez** que solapaban ~70 %: el
**plan de palancas 2026-27** (2026-07-09; P-01…P-09, T-01…T-03, pantallas
N-01…N-11; dossier en el artefacto `1a31cc69`) y el **consejo de sabios**
(2026-07-12). Se decidió que el marco forward eran las pantallas N-*, y desde el
2026-07-29 **ninguno de los dos es el plan**: los dos son fuentes históricas y
el plan es §2 de este documento.

### 6.6 Fases 0–6 originales — superadas por el cutover

Limpieza de seguridad, migración MySQL→Docker, Next.js/Express→Route Handlers,
integridad de BD, tests/CI sobre Next.js y mejoras opcionales. El cutover del
2026-07-04 sustituyó ese stack entero por PHP 8.4 + SQLite. Detalle en
`git log -p -- docs/roadmap.md`.

---

## 7. Cómo mantener este roadmap

- **El plan es §2.** Al cerrar una tarea: marcarla ✅ ahí con fecha. Al terminar
  un bloque de prioridad, mover lo que quede al siguiente en vez de dejarlo
  colgando.
- **Una rama empujada al remoto con trabajo terminado se registra en §4 el mismo
  día**, aunque no tenga PR. La revisión del 2026-07-29 encontró dos líneas
  completas invisibles para el tracker; el daño no es el olvido, es que
  cualquier sesión nueva planifica sobre un estado falso.
- **Las referencias no se inventan.** Una tarea nueva hereda la referencia del
  documento que la propuso, o entra como `R-xx` de una revisión fechada. Sin
  origen, no entra.
- **No se reescriben los dossieres de origen** (consejo, palancas): son
  evaluaciones puntuales. Lo que cambia es §2 y §3.1.
- **§6 no se toca.** Si un ítem congelado revive, entra en §2, no se descongela
  la tabla.
- Decisión arquitectónica nueva → [architecture.md](architecture.md) (ADRs).
  Deuda técnica nueva → [technical-debt.md](technical-debt.md). Cambio de
  esquema → [db-analysis.md](db-analysis.md). Aquí solo va el **plan**.
