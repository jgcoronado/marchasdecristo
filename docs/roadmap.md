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
| **B-01** | Fusionar `claude/diseño-discreto-sencillo-jymud4` y después `claude/filtrado-candidatas-videos-drdd1y`; resolver el conflicto de `docs/admin-panel.md`; **relanzar el smoke sobre el resultado fusionado**; desplegar. Borrar `…x60kfw` (redundante) | 1–2 h | ⏳ |
| **OPS-01** | Aplicar la migración `008_ingest_streaming.sql` en local e importar los candidatos; subir con `sync_db_to_prod.php` | 30 min | ⏳ Depende de B-01 |
| **OPS-02** | Ejecutar `seed_dedicatorias.php` en **prod** (Plesk → Scheduled Tasks → «Run a PHP script», **seleccionar PHP 8.4 explícitamente**) — pendiente desde el 2026-07-23 | 15 min | ⏳ |
| **OPS-03 · T-03** | Verificar en Plesk si el **cron de backup** existe de verdad y cerrar el dato. Dueño único de esa información: [pendientes-post-cutover.md §2](pendientes-post-cutover.md) | 15 min | ⏳ |
| **DEC-01** | Decidir sobre `/temporada`: **sembrar** una temporada real desde `/dashboard/temporada/{año}` o **despublicarla**. Lleva publicada y vacía desde el 2026-07-23 | decisión | ⏳ |
| **DEC-02** | Decidir sobre el **VPS de rollback**: apagarlo (el plan decía 1–2 semanas tras el cutover; van ~3,5) o declararlo permanente y quitarlo de pendientes | decisión | ⏳ |
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
| **R-07** | **Página pública «estado del catálogo»** con el KPI de cobertura (% de marchas con escucha, por año y por banda) | El issue [#16](https://github.com/jgcoronado/mdc-back/issues/16) pide medir antes/después y **hoy no hay forma de medirlo**. Además es el mapa de curación del admin y contenido indexable honesto sobre lo que falta | 4 h | 🎺🔍 |
| **M2 · P-01** | **Campaña de cobertura de audio**: curar los 616 candidatos + la cola de YouTube | Carril manual, arranca en cuanto R-01 y R-07 estén. Cuello de botella real: **el autor**, que ninguna de las tres APIs devuelve | 15 h+ | 🎺 |
| **R-02** | **Mover la duración de la obra a la grabación** (`disco_marcha.DURACION_SEG`, manteniendo la de `marcha` como referencia) | Hoy `DURACION_SEG` cuelga de `marcha`, que es la **obra**; la duración es propiedad de cada **grabación** y varía entre versiones. La ingesta ya la trae de las tres APIs y se descarta | 3 h | 🎺 |
| **D-2.1** | `PRAGMA integrity_check` sobre el backup recién creado + copia externa fuera de HelioHost | Único ítem 🟡 abierto de [technical-debt.md](technical-debt.md). Un backup que nadie verifica no es un backup | 3 h | ⚙️ |
| **T-02** | Orquestador único de la ingesta mensual (extract → classify → dedup → import + resumen) | Las piezas existen; sin orquestación la campaña depende de recordar seis comandos | 4 h | ⚙️ |

### P2 · Cuaresma 2027 (nov 2026 – feb 2027) — foco: experiencia

El año se juega en ~8 semanas. Lo de este bloque tiene que estar **desplegado y
asentado antes de Cuaresma**, no durante.

| Ref | Tarea | Por qué va aquí | Coste | Foco |
|---|---|---|---|---|
| **M6 · R-05** | **Accesibilidad** (foco visible, skip-link, `aria-sort`, contraste) **+ hoja de impresión** de fichas | Issue [#20](https://github.com/jgcoronado/mdc-back/issues/20). La hoja de impresión da el 80 % del caso «llevar la ficha a la calle» que L5 (PWA, 15 h) resolvería al 100 %. Con la gramática bibliográfica de la ficha, imprimir es casi gratis | 6 h | 🎺 |
| **R-06 · L4** | **Estado vacío de «Escuchar» con CTA** + **formulario público «propón una grabación»** | Convierte el hueco de cobertura en entrada de datos: hoy el visitante que conoce la grabación es tráfico que se pierde. Reutiliza la cola de propuestas existente, sin superficie de escritura nueva. Adelantado desde el largo plazo del consejo | 8–12 h | 🎺 |
| **R-08** | **Búsqueda**: filtro «solo con audio» + tolerancia a acentos/erratas en banda y disco (hoy van por `LIKE`, no por FTS5) | Es el filtro que más usa quien busca algo que escuchar, y llega justo cuando la campaña de audio lo hace útil | 4 h | 🎺 |
| **M7** | **Notificaciones editoriales**: email al aceptar/rechazar propuesta + digest semanal de colas | Issue [#21](https://github.com/jgcoronado/mdc-back/issues/21). Con R-06 abriendo la puerta a propuestas del público, el flujo editorial deja de ser opcional. **Depende de validar email/cron en HelioHost** | 6 h | ⚙️ |
| **R-04** | **Partituras**: enlace/edición por marcha (editorial, año, dominio público, PDF externo) + hub «marchas con partitura disponible» | Hueco funcional más claro frente a [marchasdeprocesion.com](https://www.marchasdeprocesion.com/), y el dato que le falta a quien **toca** la marcha. No requiere alojar nada: basta enlazar y declarar | 6 h | 🎺🔍 |

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
| M6 Accesibilidad + impresión | ⏳ **Promovida** — ver §3.2 punto 2 | **P2** |
| M7 Notificaciones editoriales | ⏳ Pendiente, ahora con dependencia clara | **P2** |
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

## 4. Ramas abiertas (verificado 2026-07-29)

Tres ramas `claude/*` en el remoto **con CI en verde y sin PR**. Ninguna diverge
de `main` (fast-forward posible). Son **dos líneas de trabajo, no tres**:

| Rama | Contenido | Estado |
|---|---|---|
| `claude/filtrado-candidatas-videos-drdd1y` | **Ingesta de marchas desde el catálogo de streaming de las bandas** (Spotify/Deezer/Apple): `tools/music_links/descubrir_marchas.py`, migración `008_ingest_streaming.sql` (`ingest_veto`, `ingest_descarte_ultimo`), descarte definitivo + deshacer, reproductor por servicio en el panel y en la ficha pública, `docs/ingesta-streaming.md`. Filtra directo/vivo, Navidad/cabalgata y exige corroboración en ≥2 catálogos | ✅ Lista para PR |
| `claude/bandas-rrss-discos-sync-x60kfw` | Ancestro estricto de la anterior (sus 4 commits están contenidos en ella) | 🗑 **Redundante** — borrar al fusionar |
| `claude/diseño-discreto-sencillo-jymud4` | Rediseño de pantallas públicas + dos regresiones del mapa corregidas (blanco de clic de 8 px, `pointer-events: all` sobre relleno transparente, rótulos) + **alta/edición de discos con portada y pistas** (`/dashboard/disco/*`), que cierra [technical-debt §5.1](technical-debt.md) | ✅ Lista para PR (85/85 smoke) |

**Cómo integrarlas** (tarea B-01 de P0). Orden recomendado: `diseño…jymud4`
primero (mueve `app.css`, plantillas y `ci_smoke.php`) y después
`filtrado…drdd1y`. Verificado en un merge de prueba: juntas producen **un único
conflicto, en `docs/admin-panel.md`** (ambas añaden secciones); el código funde
solo. Aun así los solapes son semánticamente cercanos —`Media.php` recibe
`guardarPortada()` por un lado y `embedDeUrl()`/`reproductor()` por el otro, y
`marcha_detail.php` se reestructura en una rama mientras la otra le añade el
reproductor de streaming— así que **hay que relanzar el smoke sobre el
resultado fusionado**, no dar por buena la suma de dos CI verdes.

⚠️ **Ninguna se puede validar en PRE**: PRE comparte la BD de producción y ambas
escriben (migración `008`, portadas en el docroot, altas de disco). Validación
en local, como manda [entornos.md](entornos.md) §«Qué vigilar».

---

## 5. Ya en producción (la base sobre la que se construye)

Hubs año/estilo/provincia (C1/P-05) · Dedicatorias **N-01/N-02** (índice + hub +
panel de curación) · Búsqueda global **N-11** (`/buscar` + `/api/buscar`) ·
API + feeds + «Datos» (M1; `/feed.xml` **es** el «novedades» de P-09) ·
`og:image` dinámica (M4) · Vídeo YouTube en ficha (P-02, `App\Media`) ·
GoatCounter opt-in (P-08) · Slugify unificado + CSP/HSTS (M8) · **N-07**
`/rankings` + `/rankings/{año}` · **N-08** «Resumen del año» · **N-09**
`/aniversarios/{año}` · **N-10** `/mapa` + `/mapa/provincia/{slug}` ·
**N-04/05** `/temporada` (⚠️ tabla vacía, ver DEC-01) · CI con smoke tests (C5) ·
uptime externo (C6) · sync endurecido con checksum y rollback (C7) · despliegue
automático PRE/PRO (M5) · catálogo cerrado de municipios y selector en cascada
(análisis UX, prioridad 4).

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
