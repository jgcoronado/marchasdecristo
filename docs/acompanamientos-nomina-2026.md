# Nómina de hermandades y pasos + recuperación del histórico (2026-09-07)

Estado del trabajo de acompañamientos musicales para poder retomarlo en otra
sesión. Complementa a [roadmap.md](roadmap.md) §2 (N-03) y a
[technical-debt.md](technical-debt.md).

## Alcance acordado

- **Solo CCTT y AM.** Bandas de cornetas y tambores (CCTT, BCT, CyT) y
  agrupaciones musicales (AM). **Nunca** bandas de música, capillas musicales,
  escolanías ni silencio.
- **Solo pasos de Cristo.** Los palios se descartan — el sitio es «marchas de
  Cristo» y los palios ya se borraron de `contrato` el 2026-08-29 (backup
  `mdc-20260829-123724-pre-borrado-paso-virgen.db`).
- **Localidades:** Sevilla, Málaga, Córdoba y Jerez de la Frontera.
- **Día y orden = foto de la Semana Santa de 2026**, no serie histórica por año.
- **Las hermandades sin CCTT/AM se listan igualmente**, con «no tiene
  acompañamiento de CCTT/AM hasta la fecha».
- **La cruz de guía se lista pero no es un paso**: va siempre la primera.
- **Los pasos se nombran por su titular o su nombre popular**, no con la
  etiqueta genérica de la fuente («Paso de Misterio»).
- **Jerez no tiene Viernes de Dolores.**
- Las vísperas (Viernes de Dolores, Sábado de Pasión) no hacen carrera oficial:
  se ordenan por hora de salida.

## Hecho

### 1. Esquema — `012_hermandad_paso.sql`

Tres tablas, **creadas y vacías**:

- `hermandad` — LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA,
  FUENTE. `SLUG` es la juntura con `contrato.HERMANDAD_SLUG`; como
  `Slug::slugify()` normaliza a NFD y quita diacríticos, corregir las tildes de
  NOMBRE no rompe el enlace con los contratos ya cargados.
- `paso` — NOMBRE (el del titular), ORDEN (0 = cruz de guía, luego 1..n),
  ES_CRUZ_GUIA.
- `contrato_paso` — satélite `ID_CONTRATO → ID_PASO`. Tabla aparte en vez de
  columna nueva por el mismo motivo que `contrato_localidad` (009): SQLite no
  tiene `ADD COLUMN IF NOT EXISTS` y `migrate_ingest.php` reejecuta todos los
  `.sql` a ciegas en cada despliegue.

«Sin acompañamiento CCTT/AM» se deduce de que la hermandad no tenga contratos;
no hay columna que mantener sincronizada.

### 2. `corregir_hermandades.php`

- 13 hermandades sin tildes (253 filas): la carga del foro venía de texto plano.
- Fusión `El Carmen` → `El Carmen Doloroso` (16 filas). Eran la misma hermandad
  (Miércoles Santo) partida en dos porque el foro la llama «EL CARMEN» y
  musicofrades «El Carmen Doloroso»; el histórico se quedaba colgando en
  1993-2019 y 2026 aparecía como hermandad nueva. Aquí sí cambia el slug.

### 3. `recuperar_historico_acompanamientos.php`

**Contratos 1.431 → 2.507.**

El resolutor de 2026-08-29 (`scripts/tmp_acompanamientos/resolve_acompanamientos.py`)
casaba el nombre de banda contra `banda` por conjuntos de tokens **sin mirar la
localidad**; ante dos bandas homónimas en sitios distintos se rendía y tiraba la
fila. Se perdió el 40% de lo que estaba en ámbito.

El nuevo desambigua en este orden: **localidad explícita → estilo AM/CCTT →
juvenil → Sevilla por defecto**. Lo que no encaja no se inventa: sale por
pantalla y se queda fuera.

Además **corrigió 234 filas ya cargadas que apuntaban a la banda equivocada**:

| banda_raw | iba a | debía ir a | filas |
|---|---|---|---|
| `CCTT Tres Caídas` | BCT Tres Caídas de **Arcos de la Frontera** | BCT Tres Caídas de **Triana** | 222 |
| `AM Sta. María de la Esperanza` | BCT La Esperanza de **Málaga** | AM Fraternitas (Sevilla) | 9 |
| `AM Sagrada Lanzada` | AM Lanzada de **Granada** | AM Sagrada Lanzada (Sevilla) | 4 |

Tras la pasada, todas las bandas de fuera de Sevilla son plausibles (Arahal, Dos
Hermanas, Cádiz, Utrera, Huelva, Linares, Jerez, Mairena, La Algaba,
Castilleja); desaparecieron León, Palma, Palencia y Granada.

Se revisaron **a mano las 63 resoluciones distintas** antes de escribir nada.

El script es idempotente: la clave de deduplicación es el *hecho*
(hermandad, paso, año, texto de la fuente), **no** el id de banda. Indexar por
banda fue un error intermedio que apiló la fila buena encima de la mala en vez
de corregirla.

## Pendiente

Poblar `hermandad` y `paso` con la nómina 2026 de las cuatro ciudades, y enlazar
`contrato_paso`. Es la mitad más grande del trabajo. **Córdoba ya está hecha**
(ver más abajo); quedan Sevilla, Málaga y Jerez.

### Córdoba: contratos cargados y enlazados a su paso (hecho el 2026-09-07)

`scripts/tmp_acompanamientos/proponer_contratos_cordoba.php` lee
`cordoba_programa_2026_raw.txt` (NUNCA `cordoba_extraido.csv` para el texto de
música: tiene al menos un bug de truncado, ver más abajo) y propone banda +
paso para cada mención de Cristo, reutilizando el resolutor de
`recuperar_historico_acompanamientos.php` con un cambio: el desempate por
defecto es "gana Córdoba" en vez de "gana Sevilla". La propuesta revisada
queda en `scripts/tmp_acompanamientos/cordoba_contratos_propuesta.csv` (32
filas) y `php/app/tools/cargar_contratos_cordoba.php` cargó las **28** con
banda candidata (27 alta + 1 media) en `contrato`/`contrato_localidad`/
`contrato_paso`.

Dos hallazgos durante la revisión que ya quedaron corregidos en la BD:

- **"Amor" tenía un paso de Cristo sin poblar.** El texto da banda para tres
  pasos ("paso de misterio" de Nuestro Padre Jesús del Silencio, "el paso del
  Cristo" = Santísimo Cristo del Amor, y el paso de Virgen), pero `paso` solo
  tenía el primero. Confirmado por el usuario: son dos pasos de Cristo
  distintos (como Huerto). Se añadió "Cristo del Amor" (ORDEN 2) y se corrigió
  el chequeo de "qué falta poblar" de `poblar_hermandad_paso_cordoba.php`, que
  solo miraba si la hermandad existía (no si le faltaban pasos) y por eso no
  detectaba este caso como pendiente.
- **Banda partida en dos filas.** El programa cita repetidamente "Banda de
  Cornetas y Tambores Caído y Fuensanta" (Córdoba), pero `banda` la tenía
  partida en `#8` (BCT Fuensanta) y `#98` (BCT Caído) — ambas con FECHA_EXT
  cargada (2008 y 2007), coherente con que se fusionaran por esas fechas.
  Confirmado por el usuario: es una fusión real. `php/app/tools/
  fusionar_banda_caido_fuensanta.php` dio de alta una banda nueva (`#283`,
  no reutiliza el `#8` ni el `#98`) y registró el vínculo en `banda_relacion`
  (`TIPO='fusion'`, año desconocido, `FECHA_INICIO` a NULL con nota) — primer
  uso de `TIPO='fusion'` en esa tabla, hasta ahora solo tenía `renombrado` y
  `juvenil`. Los contratos históricos de `#8`/`#98` NO se reescriben, igual
  que con los `renombrado` ya existentes.

**Bug de truncado confirmado en `cordoba_extraido.csv`:** la ficha de "Penas
de Santiago" corta el texto en `"...la Agrupación Musical Ntro."` — el
extractor se paró en la abreviatura. El texto real (`.txt`, líneas 152-156)
sigue: `"Ntro. Padre Jesús de la Salud - Sección Musical de la Hermandad de
los Gitanos de Sevilla"`. No se ha tocado `parse_cordoba_programa.py` (el CSV
ya no hace falta para esto); solo se documenta por si aparece el mismo patrón
en Sevilla/Málaga/Jerez.

**4 filas quedaron sin banda candidata** (necesitan dar de alta la banda antes
de poder cargar el contrato — no se inventan):
- `huerto` / "El Señor Amarrado a la Columna" — Asociación Musical Utrerana (Utrera)
- `amor` / "Cristo del Amor" — Banda de CCTT Maestro Valero (Aguilar de la Frontera)
- `piedad` / "Cristo de la Piedad" — banda de Quesada (Jaén); el resolver la
  había casado por error con la banda de Jaén *capital* (`#119`, corregido a
  mano en el CSV antes de cargar: Quesada es un pueblo de la provincia, no la
  capital)
- `sagrada-cena` / "Jesús de la Fe" — banda propia de la hermandad, nombrada
  igual que su titular

Reejecutable:
```bash
cd php
DB_PATH=data/mdc.db php app/tools/fusionar_banda_caido_fuensanta.php
DB_PATH=data/mdc.db php app/tools/cargar_contratos_cordoba.php --dry-run
DB_PATH=data/mdc.db php app/tools/cargar_contratos_cordoba.php
```

### Córdoba: `hermandad`/`paso` poblados (hecho el 2026-09-07)

`php/app/tools/poblar_hermandad_paso_cordoba.php` puebla las 42 hermandades y
33 pasos de Cristo de Córdoba (32 en la carga original + "Cristo del Amor",
añadido después al revisar los contratos — ver más abajo). El nombre corto de
cada paso NO sale de un
regex: se revisó ficha a ficha contra `cordoba_extraido.csv` (propuesta
completa con nivel de confianza en
`scripts/tmp_acompanamientos/cordoba_pasos_propuesta.csv`) y el usuario
confirmó las de confianza ALTA/MEDIA antes de escribir nada en la BD.

**Qué se deja fuera a propósito** (no es un olvido — cada una queda anotada
en el propio código del script):
- **La O y Lágrimas** (Sábado de Pasión): sin música detectada — duda
  pendiente en `acompanamiento_duda`, sin paso hasta que se resuelva.
- **Vía Crucis, Ánimas, Universitaria, Nazareno, Buena Muerte, Sepulcro**:
  sin CCTT/AM y sin nombre de titular en ninguna fuente disponible.
- **Angustias**: confirmado por el usuario — el único paso que da el
  programa ("Nuestra Señora de las Angustias") se descarta como palio.
- **Dolores de Alcolea**: confirmado por el usuario — hermandad Virgen-only,
  se crea sin ningún paso (y con `ORDEN`=8, al final del día, porque no hace
  carrera oficial — el resto de Viernes Santo sí).
- **Soledad** y el paso combinado de **Piedad** ("Santísimo Cristo de la
  Piedad y Dulce Nombre de María Santísima de la Esperanza", ¿un grupo o dos
  pasos mal separados por el programa?): dudas de alcance sin resolver.
- **Vera Cruz**: el programa dice que su Cristo es "el Señor de los
  Reyes" — parece un corta-y-pega erróneo del título de Entrada Triunfal
  (misma jornada, hermandad distinta). Se cruzó con yescordoba.es (fuente de
  respaldo) y se cargó como **Cristo del Amor**, que es el titular real.
- **Cruz de guía**: aparece como crédito de banda en varias fichas pero no
  se ha dado de alta como paso propio todavía — pasada aparte.
- **Huerto** tiene **dos** pasos de Cristo, no uno ("La Oración en el
  Huerto" y "El Señor Amarrado a la Columna").

Reejecutable (`INSERT OR IGNORE`, UNIQUE en `hermandad(LOCALIDAD,SLUG)` y
`paso(ID_HERMANDAD,NOMBRE)`):
```bash
cd php
DB_PATH=data/mdc.db php app/tools/poblar_hermandad_paso_cordoba.php
```

### Parseo de Córdoba (hecho el 2026-09-07)

`scripts/tmp_acompanamientos/parse_cordoba_programa.py` extrae de
`cordoba_programa_2026_raw.txt` una fila por hermandad (42, incluida
Dolores de Alcolea que yescordoba.es no listaba) en
`cordoba_extraido.csv`: día, DIA_ORDEN, ORDEN_POR_CO (calculado ordenando
por hora de C.O.), horas de salida/C.O./entrada, el texto de
"acompañamientos musicales" ya aislado del resto de la ficha, y un flag
`MENCIONA_CCTT_AM` de aviso rápido. 40 de 42 tienen música detectada; solo
**La O** y **Lágrimas** (Sábado de Pasión) se quedan sin ella porque
comparten página con un maquetado distinto (usa etiquetas "MÚSICA" en vez
de "ACOMPAÑAMIENTOS MUSICALES") — esas dos requieren mirar el PDF a mano.

Por qué hizo falta un script y no bastaba con leer el .txt: dentro de cada
ficha el PDF apila varios cuadros de texto (túnica, música, estrenos,
curiosidades) sin separador y el extractor no siempre respeta el orden
visual — cambia de una hermandad a otra. El script fue puliéndose contra
casos reales hasta que las 42 fichas salieron limpias; los bugs que
aparecieron y por qué (por si el patrón se repite en Sevilla/Málaga/Jerez):
- `rfind('completo')` cortaba en el "completo" de una frase de estrenos
  ("Dorado **completo** del paso...") en vez del pie de foto del QR →
  ancla más específica ("itinerario completo").
- La cabecera de página ("3DEABRILDE2026 VIERNESSANTO") se repite DENTRO
  de una ficha por salto de página y cortaba el bloque a mitad → ahora solo
  actualiza el día sin cortar.
- El nombre de ruido `Entrada` (pensado para excluir la línea de campo
  "Entrada: HH:MM") también excluía la hermandad real "Entrada Triunfal" →
  se exige el `:`.
- La línea "Salida: HH:MM C.O.: HH:MM Entrada: HH:MM" a veces va ANTES del
  nombre de la hermandad en vez de dentro de su ficha (Vera Cruz, Esperanza,
  Amor...) → cada hermandad se quedaba con las horas de la siguiente. Se
  busca también en la línea previa al nombre, y se recorta la línea de
  horario que queda pegada al final de un bloque (pertenece a la
  hermandad siguiente), para que una vísperas sin C.O. (Dolores de Alcolea)
  no herede por error el C.O. de la que viene después.
- En 3 fichas (Sepulcro, Expiración, Conversión) el cuadro de música está
  maquetado de forma que su texto sale DESPUÉS de las etiquetas de sección
  en vez de antes → hay una segunda pasada que también mira ahí.

Lo que el script NO hace (a propósito, ver más abajo "Forma del nombre de
los pasos"): no separa el paso de Cristo del de palio dentro de la misma
frase, no resuelve el nombre de banda contra la tabla `banda`, y no decide
el nombre corto del paso. Eso necesita criterio humano fila a fila sobre
`cordoba_extraido.csv`, no regex — la redacción es demasiado variable
("en el paso de X la BANDA", "tras el paso de X la BANDA", "Al Señor lo
acompaña la BANDA", "BANDA para el Misterio/Cristo/Señor").

### Parseo de Sevilla/Málaga/Jerez (hecho el 2026-09-07)

`scripts/tmp_acompanamientos/parse_musicofrades.py` parsea con BeautifulSoup
las tres páginas de musicofrades.com (descargadas con `curl` a
`sevilla.html`/`malaga.html`/`jerez.html` en el mismo directorio — la URL de
Jerez es `.../jerez-**de-la-frontera**-2026/`, no `.../jerez-2026/`) a
`musicofrades_extraido.csv`: 382 filas (77+53+49 hermandades). A diferencia
del PDF de Córdoba, aquí el HTML es una lista `<ul><li>` bien anidada y no
hace falta reconstruir el orden de lectura a mano.

Cada localidad usa su propia terminología para las etiquetas de paso
(Sevilla/Jerez: "Paso de Misterio/Palio"; Málaga: "Trono de Cristo/Virgen"),
que el script normaliza a `cristo`/`virgen`/`cruz_guia`. **372 de 382 filas
(97%) se clasifican solas.** Las 10 que no encajan con ningún patrón
conocido —cosas como "Trono de Cristo y Virgen" (Málaga, un único trono con
las dos imágenes), "Paso de San Juan" (Jerez, un tercer paso que no es ni
Cristo ni Virgen) o "Paso Alegórico" (Sevilla, una escena simbólica sin
imagen titular)— se marcan `TIPO_NORMALIZADO=dudoso` en vez de adivinar.

El `ORDEN_PAGINA` de este CSV es solo la posición en que musicofrades lista
cada hermandad — **no es el orden de carrera oficial** (ver trampa conocida
en «Fuentes y sus trampas» más abajo): se guarda por si ayuda de referencia,
pero no hay que confundirlo con `hermandad.ORDEN`.

Lo que tampoco resuelve (igual que con Córdoba): el nombre de banda contra
la tabla `banda`, y **aquí ni siquiera hay nombre de paso** — musicofrades
solo da la etiqueta genérica ("Paso de Misterio"), nunca el titular real
("Nuestro Padre Jesús de..."), así que el nombre corto de cada paso de
Sevilla/Málaga/Jerez habrá que sacarlo de otra fuente al poblar `paso`.

### Panel de revisión: `/dashboard/acompanamientos-dudas` (hecho el 2026-09-07)

Para las filas dudosas de los dos parseos (10 de musicofrades + las 2 fichas
de Córdoba sin música detectada — La O y Lágrimas — 12 en total), en vez de
resolverlas a mano en el CSV:

- Migración `013_acompanamiento_duda.sql`: tabla `acompanamiento_duda`
  (`ESTADO` pendiente/resuelto/descartado, `UNIQUE` por
  localidad+hermandad+etiqueta+motivo para que recargar sea idempotente).
- `php/app/tools/cargar_dudas_acompanamientos.php`: lee
  `musicofrades_extraido.csv` (filas `dudoso`) y `cordoba_extraido.csv`
  (filas sin música) y las inserta como `pendiente`. Reejecutable tras cada
  pasada de los scripts de parseo.
- `AcompanamientoDudaRepo` (lecturas) + `AdminRepo::resolverAcompanamientoDuda`/
  `descartarAcompanamientoDuda` (escrituras) + rutas y plantilla
  `admin/acompanamiento_dudas.php`, siguiendo el mismo patrón que
  `/dashboard/ingesta` (tabla de staging + ESTADO + revisar en el panel).
  Enlace en el nav de `/dashboard` con contador de pendientes, igual que
  "Propuestas".

**Importante:** resolver una duda en el panel solo dice qué es
(Cristo/Virgen/Cruz de Guía/ninguno) y opcionalmente una nota — **todavía no
escribe en `hermandad`/`paso`/`contrato_paso`**, porque esa importación final
sigue pendiente (ver "Pendiente" más abajo) y necesita además el nombre de
banda resuelto y el nombre corto del paso, que no son parte de esta duda.

Para recargar tras un cambio en los scripts de parseo:
```bash
cd php
DB_PATH=data/mdc.db php app/tools/migrate_ingest.php
DB_PATH=data/mdc.db php app/tools/cargar_dudas_acompanamientos.php
```

### Decisiones (2026-09-07)

1. **Córdoba:** fuente = programa oficial en PDF de la Agrupación de
   Hermandades y Cofradías de Córdoba 2026 («PROGRAMA DE HORARIOS E
   ITINERARIOS DE LAS HERMANDADES Y COFRADÍAS DE LA SEMANA SANTA DE CÓRDOBA
   2026»), copiado en `scripts/tmp_acompanamientos/cordoba_programa_2026.pdf`
   (+ su extracción de texto en `cordoba_programa_2026_raw.txt`, sacada con
   `pdftotext -enc UTF-8 -raw`, que sí tiene capa de texto real pese a que la
   conversión Markdown automática de Windows la daba por imagen). Tiene ficha
   de **40 de las 41** hermandades (falta Resucitado, que no hace Carrera
   Oficial de Cristo) con una sección `ACOMPAÑAMIENTOS MUSICALES` en prosa por
   hermandad — mejor fuente que yescordoba.es, que solo daba día/hora y un
   campo de música más corto por scraping.
2. **Orden de carrera en Córdoba SÍ se calcula**, no se deja en blanco: el
   programa da la hora exacta de paso por Carrera Oficial (`C.O.: HH:MM`) de
   cada hermandad, y como todas comparten el mismo tramo, ordenar por esa hora
   reproduce el orden oficial (comprobado en Domingo de Ramos: Entrada
   Triunfal 12:00 → Penas 17:30 → Huerto 18:05 → Rescatado 18:46 → Vera Cruz
   19:22 → Esperanza 19:53 → Amor 20:29, que coincide con el orden ya listado
   en el programa). **Sevilla/Málaga/Jerez siguen sin fuente de orden** → ahí
   sí se deja en blanco si no hay dos fuentes.
3. **Nombre de los pasos:** forma corta/popular («La Sentencia», «El Cachorro»),
   no la completa.
4. **Despliegue:** la nómina se puebla en local y se lleva a producción con el
   script de migración (no se sube el `.db` local encima; ver
   `reference_db_deploy`).

**Confirmado en el propio texto (no solo por falta de fuente):** varias
hermandades cordobesas van genuinamente sin CCTT/AM — Universitaria y
Nazareno en «riguroso silencio», Vía Crucis con «tambores roncos», Ánimas con
«coro de hermanos». Se marcan igual que "sin acompañamiento CCTT/AM hasta la
fecha".

## Fuentes y sus trampas

- **musicofrades.com** — `/acompanamientos-musicales-de-la-semana-santa-de-{ciudad}-{año}/`.
  Sevilla, Málaga y Jerez de 2026 completos, con el estilo de cada formación
  paso a paso. **No hay página de Córdoba.**
- **Programa oficial PDF de Córdoba 2026** (Agrupación de Hermandades y
  Cofradías) — ver `scripts/tmp_acompanamientos/cordoba_programa_2026.pdf`.
  Fuente elegida para Córdoba: ficha por hermandad con salida, C.O., entrada y
  `ACOMPAÑAMIENTOS MUSICALES` en prosa. **Ojo:** parece imagen a simple vista
  (la conversión automática a Markdown de Windows la OCRea mal, "picture
  text" ilegible) pero tiene capa de texto real — extraerla con
  `pdftotext -enc UTF-8 -raw archivo.pdf salida.txt`, nunca fiarse del .md
  generado automáticamente.
- **yescordoba.es/semana-santa-cordoba-2026/** — alternativa de respaldo para
  Córdoba si el programa oficial no cubriera alguna hermandad: página índice +
  una página por hermandad, con el acompañamiento en la cabecera bajo «Música
  2026».
- ⚠️ **El orden en que Musicofrades lista las hermandades NO es el de carrera
  oficial.** Comprobado en el Domingo de Ramos de Sevilla 2026: Musicofrades
  pone La Cena la 5.ª, el orden oficial la 2.ª, y Amargura y Estrella van
  cambiadas. El orden oficial es: Borriquita, Cena, Jesús Despojado, Hiniesta,
  Paz, San Roque, Estrella, Amargura, Amor.
- **hermandades-de-sevilla.org** (Consejo) — la nómina y los horarios solo están
  dentro de PDFs enlazados, no en el HTML.
- **semana-santa.org** — inservible: el dominio es hoy spam de casinos.
- **sevillaactualidad.com** — devuelve 403.
- La prensa solo publica los **cambios** del año, nunca la lista completa.
- Los resúmenes de búsqueda web mienten con soltura sobre el orden: uno devolvió
  el mismo orden para Miércoles y Jueves Santo de Sevilla, que es imposible.
  **Contrastar siempre con una segunda fuente.**

## Deuda que deja este trabajo

- **La Macarena y La Redención siguen solo con 2026**, y no se puede arreglar
  desde estos CSV: la fuente extraída solo trae su paso de palio. El hueco está
  en la extracción del foro, no en la resolución — habría que reparsear el hilo.
- **28 nombres de banda sin resolver** (~125 filas). Varios son bandas que no
  existen en `banda` y habría que dar de alta: «AM San Esteban» (18 filas),
  «AM Santo Domingo El Sabio», «AM La O», «AM El Cachorro»,
  «CCTT Policía Municipal», «CCTT Cruz Roja».
- **Las 6 Cruces de Guía de 2026** de Baratillo, Hiniesta, Gitanos, San José
  Obrero, San Roque y Santa Genoveva apuntan a `AM Angustias` **adulta** (id
  157) cuando musicofrades dice la **juvenil**, que no existe en `banda`.
- **`banda` id 92** se llama `AM Angustias` en NOMBRE_BREVE siendo una *Banda de
  Cornetas y Tambores*. El prefijo del breve no es fiable como estilo; usar
  NOMBRE_COMPLETO.
- **La taxonomía de `contrato.TITULAR` sigue sin ser una entidad real** hasta
  que se pueble `paso` — sigue siendo texto libre, sin FK. **El síntoma más
  visible ya se arregló (2026-09-07):** 586 contratos de Sevilla estaban
  duplicados (misma banda, mismo año, dos TITULAR para el mismo paso real —
  «Paso de Cristo» vs «Paso de Misterio» y variantes; en La Hiniesta la serie
  se veía partida en `/acompanamientos/sevilla`). `php/app/tools/
  fusionar_titular_duplicado_sevilla_2026.php` (23 filas de 2026) y
  `fusionar_titular_duplicado_sevilla_historico.php` (563 filas, 1980-2019)
  los fusionaron usando 2020-2026 como referencia de qué TITULAR es el
  vigente. Sevilla: 2.507 → 1.921 contratos. Sigue habiendo una fila cuyo
  TITULAR arrastra un comentario del foro de origen (Dolores de Torreblanca:
  «Paso Cristo (corregido en post #740, sustituye a la versión del post
  original)») — no es un duplicado, no se ha tocado.
- **`ci_smoke.php` sigue probando `/temporada`**, que esta rama sustituyó por
  `/acompanamientos`: 4 FAIL que no son regresiones.

## Cómo reejecutar

```bash
cd php
DB_PATH=data/mdc.db php app/tools/migrate_ingest.php
DB_PATH=data/mdc.db php app/tools/corregir_hermandades.php
DB_PATH=data/mdc.db php app/tools/recuperar_historico_acompanamientos.php --dry-run
DB_PATH=data/mdc.db php app/tools/recuperar_historico_acompanamientos.php
```

Los tres son idempotentes y hacen copia de seguridad (`VACUUM INTO`) en
`php/data/backups/` antes de tocar nada.

Verificación usada: PHPStan limpio en los ficheros nuevos, y `ci_smoke.php`
contra la fixture de CI da 77 OK / 6 FAIL — los 6 ajenos (2 por GD/FreeType no
cargado en local, 4 por `/temporada`).
