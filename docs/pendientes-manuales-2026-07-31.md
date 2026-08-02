# Pendientes manuales — R-02, duraciones de grabación (2026-07-31)

> Operativo y de corta vida, mismo criterio que
> `pendientes-manuales-2026-07-30.md`: se archiva cuando todo esté resuelto.
> Nace de la primera ejecución de `php/app/tools/fill_duraciones.php`.
>
> Contexto: el sandbox de la sesión en la nube **no tiene PHP ni salida a
> `api.spotify.com` / `itunes.apple.com` / `api.deezer.com`**, así que el
> script se escribió y se validó allí (PHP 8.3 vía WASM, fixture con el
> tracklist real de iTunes del disco 5) pero **lo ejecuta Javier en local**.

---

## 0. Estado

`disco_marcha.DURACION_SEG` estaba a 0/4.451. El dry-run del 2026-07-31 sobre
los 143 discos con enlace aprobado (1.514 pistas) dio:

| | |
|---|---|
| Con duración | **1.325 (87,5%)** — 1.181 vía Spotify, 144 vía Apple |
| Sin match | 189 |
| Discos sin tracklist | 0 |
| Discos al 100% | 65 de 143 |

**Precisión:** 1.306 de los 1.325 aciertos (98,6%) son coincidencia exacta de
título. Los 19 restantes se revisaron uno a uno: todos correctos (erratas del
servicio o del catálogo). Duraciones sanas: mediana 245 s, mínimo 39 s (un
«Toque de oración», real), máximo 480 s.

**Decisión tomada:** commitear estas 1.325 ya, y afinar en una segunda pasada.
Como el script solo toca pistas vacías (salvo `--overwrite`), la segunda pasada
es aditiva y no pisa nada.

---

## 1. Ejecutar el commit  · **actúa: Javier**

```powershell
cd C:\Users\usuario\Documents\mysql-simple
Copy-Item php\data\mdc.db php\data\backups\mdc_pre_R02.db
php php\app\tools\fill_duraciones.php --commit
```

Vuelve a llamar a las APIs (el dry-run no cachea), tarda lo mismo que la
primera vez.

### Qué hará la pasada 2 (`marcha.DURACION_SEG`) — calculado, no estimado

**Regla (decidida 2026-07-31):** la duración de la obra se deriva de sus
grabaciones siempre que las haya. Si la marcha no tiene ninguna grabación con
duración, se respeta el valor de catálogo.

Se usa **mediana** y no media: con 3+ versiones, una toma en directo larga o un
fragmento corto desplazan la media y no la mediana. Con 1 o 2 grabaciones ambas
coinciden de facto.

| Acción | Marchas | Qué hace |
|---|---:|---|
| `RELLENA` | **204** | Estaba a 0/NULL |
| `CAMBIA` | **622** | Tenía valor, se recalcula desde las grabaciones |
| `IGUAL` | 231 | Ya coincidía |
| *de ellas con `AVISO`* | **29** | Salto de más de 60 s respecto al catálogo |

Las 4.211 − 1.057 marchas sin ninguna grabación no se tocan.

Todo cambio queda en `duraciones_<run>_marchas.csv` (valor anterior, mediana,
nº de grabaciones, diferencia y aviso), así que el valor de catálogo no se
pierde en silencio aunque se pise.

**Los 29 avisos son la lista de revisión.** Ahí están los dos extremos: los que
corrigen basura evidente (`La Herencia del Maestro` 2013 s → 257 s,
`En tus Manos Macarenas` 3 s → 229 s) y los que empeoran, típicamente porque la
única grabación hallada es un fragmento — el caso claro es
**`Al Rey de los Gitanos`, 196 s → 69 s**. Conviene mirarlos con el CSV delante
después del commit.

---

## 1 bis. Intro de percusión  · **actúa: Javier**

Muchas grabaciones abren con un fragmento de tambores (~37–42 s) antes de la
marcha. Eso hace que la misma marcha parezca ~40 s más larga en unos discos que
en otros y contamina la mediana.

**No es detectable automáticamente.** Se comprobó: de 268 pares de grabaciones
de la misma marcha, 149 difieren más de 10 s y 31 más de 42 s, sin ningún pico
en 37–42 s. La variación natural entre bandas ya es mayor que la intro, así que
hay que marcarlo **de oído**. Como pista inicial, los dos discos con desfase
medio consistente son **[165] Consuelo Gitano (+46,2 s en 7 pistas)** y
**[259] Toques de Triana (+40,4 s en 4)** — confirmar escuchando.

Modelo:

| Campo | Uso |
|---|---|
| `disco.PERCUSION` | 0/1, aplica a todas las pistas del disco. Arranca a 0 en los 431 |
| `disco.PERCUSION_SEG` | Duración estimada, por defecto **40** (editable) |
| `disco_marcha.PERCUSION` | Excepción por pista: `NULL` hereda, `0`/`1` se desvía |

Se usa 40 fijo y **no un aleatorio de 37–42**: la constante tiene menor error
medio (1,25 s frente a 1,67 s), es reproducible, y no deja en la BD un número
que dentro de un año parecerá medido. Si cronometras una intro real, escríbela
en el campo del disco.

`disco_marcha.DURACION_SEG` sigue guardando la duración **real** del track; el
descuento se aplica solo al calcular la mediana. La ficha pública muestra la
duración real con un 🥁 al lado cuando la grabación lleva intro.

### Marcado inicial por año (hecho 2026-07-31)

En vez de marcar 431 discos de oído, se aplicó una **regla por fecha**:
`PERCUSION = 1` en todo disco anterior a **2005**. Son **223 discos** (1963–2004);
los 207 de 2005 en adelante quedan a 0. Los 431 tienen fecha, así que ninguno
se queda sin criterio.

Es una aproximación deliberada: cubre ~85% de los casos y los falsos positivos
se corrigen a mano según vayan apareciendo. El UPDATE **solo pone 1, nunca 0**,
así que no pisa marcados manuales previos — de hecho respetó el disco **56
«XXV Aniversario» (2005)**, marcado a mano antes de esta pasada.

Para revertir el marcado automático sin perder lo manual no hay atajo: el
backup `mdc_pre_limpieza_*.db` es la vía.

### Orden obligatorio

```powershell
# 1. Añadir las columnas (idempotente, se puede repetir sin miedo)
php php\app\tools\migrate_ingest.php

# 2. Marcar los discos a mano en /dashboard/disco/<id> (checkbox + segundos)

# 3. Recalcular SOLO las medianas — no vuelve a llamar a las APIs
php php\app\tools\fill_duraciones.php --solo-marchas --commit
```

El paso 3 se puede repetir cada vez que marques más discos: es idempotente y
solo lee `disco_marcha`, que ya está rellena. Si se ejecuta antes del paso 1,
el script avisa y no descuenta nada en vez de fallar.

---

## 2. Mejoras del matcher, para la segunda pasada  · *pendiente*

Ganancia estimada conjunta: **1.325 → ~1.372 (90,6%)**.

### 2.1 Bug: no se quitan los sufijos de versión entre paréntesis (≈13 pistas)

`stripSufijoVersion()` quita `- En Directo` pero no `(Acoustic Version)`,
`(Directo)` ni `(Piano)`. Casos: las 9 pistas del disco 229 (0,62–0,70),
`Cristo de San Julián (Directo)` 0,82, `Costaleros del Amor (Piano)` 0,82.

### 2.2 Expansión de abreviaturas (≈6 pistas)

`Stmo.`↔`Santísimo`, `Ntro.`/`Ntra.`↔`Nuestro`/`Nuestra`, `Sra.`↔`Señora`.
Ej.: `Santisimo Cristo de la Sagrada Cena` ← `Stmo. Cristo de la Sagrada Cena` (0,8396).

### 2.3 Bajar `--min-sim` a 0.80 (28 pistas)

La franja **0,80–0,85 se revisó entera: son 28 aciertos reales** perdidos por
artículo, singular/plural o errata (`Al Cristo de los Faroles` ←
`El Cristo de los Faroles`; `Bulería en San Román` ← `Bulerías en San Román`).

**0,80 es el suelo seguro. No bajar a 0,70:** en la franja 0,70–0,80 ya hay
falsos positivos claros — `Al Compás del Amor` ← `Al Dios del Amor`,
`Buena Muerte y Misericordia` ← `Buena Fuente y Misericordia`.

---

## 3. Enlaces mal curados en `enlace_streaming`  · **actúa: Javier, en /dashboard/enlaces**

Detectados de rebote: dan 0 aciertos y sus candidatos son ajenos (0,2–0,4), o
sea **el álbum enlazado no es ese disco**. No es un problema del matcher.

| Disco | Título | Banda | Enlace sospechoso |
|---|---|---|---|
| **73** | *Resurrexión* (2010) | BCT Stmo. Cristo del Mar | `open.spotify.com/album/4wY8E1j1saYXYvJnfKha9E` |
| **242** | *«Misericordia» Marchas Procesionales* (1984) | BCT Ntra. Sra. de la Victoria | `music.apple.com/es/album/marchas-procesionales/1721437650` + `open.spotify.com/album/1rxtUUIGhNYlcNljIJFIGs` |

---

## 4. Disco 229 — excluido a propósito  · *decidido 2026-07-31*

*«Y fui tu costalero»* (AM Ntro. Padre Jesús de los Afligidos, 2004) está
enlazado a la edición **«(Acoustic Versions)»**. El enlace es válido como
enlace, pero sus duraciones son de **regrabaciones acústicas**, no del CD de
2004. Guardarlas sería meter un dato falso en `disco_marcha`.

Está en la constante `$excluidos` de la cabecera del script. Para forzarlo:
`--sin-exclusiones`, o `--excluir=` con otra lista.

---

## 5. Fuera de alcance, no es un fallo

El **disco 33** *«Cuando La Semana Santa Empieza»* son popurrís: cada track
del álbum contiene dos marchas (`Recorre la Cofradía de Barrio / Santa Marta`).
Asignar esa duración a una sola pista sería incorrecto. **El rechazo es el
comportamiento deseado** — no intentar "arreglarlo".

Lo mismo con `Himno Nacional / Al Señor de la Redención` (2 pistas).

Y 38 pistas con score < 0,40: simplemente **no están en la edición digital**
del álbum. No hay nada que recuperar.

---

## 5 bis. Años de edición truncados  · *hecho 2026-07-31, falta subir a producción*

`disco.FECHA_CD` guardaba `"2012.0"` en 429 de los 431 discos (herencia del
import original; sigue siendo TEXT). Truncado a `"2012"` — sin pérdida: los 431
valores tenían parte decimal 0 y el rango sigue siendo 1963–2020.

Arregla dos cosas de paso:

- `Html::cdList` imprimía `FECHA_CD` **en crudo**, así que el listado de discos
  mostraba literalmente "2012.0" al usuario.
- `AdminRepo::saneaDisco` valida el año con `^\d{4}$`, que rechaza `"2012.0"`.
  Guardar un disco sin tocar el año podía fallar con `INVALID_FECHA`.

`parity_expected.json` esperaba los `.0`: corregidos 41 `FECHA_CD` + 1
`datePublished` (que deriva de `FECHA_CD` en `Seo.php`). Los `FECHA_FUND` /
`foundingDate` que quedan salen de `banda`, que **no** se ha tocado.

> Verificado ejecutando `parity_compare.php` antes y después: salida idéntica
> (4 OK / 24 fallos, los mismos casos). Ese suite ya estaba en rojo por otras
> razones — el expected es un snapshot antiguo del API de Node y la app PHP ha
> añadido campos desde entonces. **Merece una revisión aparte**, porque hoy no
> protege de nada.

Llega a producción con el `sync_db_to_prod.php` habitual (sube el `.db` entero).

### Mismo tratamiento en `banda` (hecho 2026-07-31)

`FECHA_FUND` y `FECHA_EXT` arrastraban el mismo vicio. Criterio: los ceros
significan "no consta" y pasan a **NULL**; el resto se trunca a año entero.

| Columna | Antes | Ahora |
|---|---|---|
| `FECHA_FUND` | 225 `AAAA.0` · 29 ceros · 19 NULL · 4 limpios | **229 años + 48 NULL** |
| `FECHA_EXT` | 56 `AAAA.0` · 121 ceros · 100 NULL | **56 años + 221 NULL** |

Sin pérdida: ningún año real se alteró (comprobado fila a fila).

**Esto arreglaba un bug de SEO real.** `Seo.php:139` hace
`if (!empty($data['FECHA_EXT']))`, y en PHP `"0.0"` es *truthy* — se estaba
emitiendo `"dissolutionDate": "0.0"` en el JSON-LD de **121 bandas activas**,
diciéndole a Google que se disolvieron en el año 0. El resto del código sí se
defendía (`(int)(float)` en las plantillas, `Api::anio`, `Html::timeline`);
`Seo.php` era el único que no. Ya no hay ceros que emitir, pero **la guarda de
`Seo.php` sigue siendo frágil** si algún día vuelve a entrar un `"0.0"`.

`parity_expected.json`: 8 valores truncados y 2 `"0.0"` convertidos a `null`.
Verificado con `parity_compare.php` — salida idéntica al baseline previo.

---

## 6. Errata de catálogo detectada de rebote

`marcha.TITULO` = **«El Santísmo Cristo del Amor»** — falta una «i»
(*Santísimo*). Corregir a mano en el panel.
