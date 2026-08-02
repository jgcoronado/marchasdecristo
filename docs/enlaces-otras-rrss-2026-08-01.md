# Enlaces en el resto de servicios a partir de Spotify (2026-08-01)

> Operativo, misma vida corta que `pendientes-manuales-2026-07-31.md`: se archiva
> cuando el lote esté cerrado.
>
> Contexto: el sandbox de la sesión en la nube no tiene PHP ni salida a
> `api.spotify.com` / `api.song.link`, así que el script se escribió y se validó
> allí (PHP 8.3 vía WASM, con fixtures) pero **lo ejecuta Javier en local**.

---

## 0. De dónde sale esto

Javier curó a mano el enlace de Spotify de **10 discos** el 2026-08-01 (altas
entre las 11:12 y las 11:24):

`12 · 161 · 189 · 231 · 232 · 239 · 243 · 245 · 336 · 460`

Estado de partida de esos 10:

| | |
|---|---|
| Pistas totales | 99 |
| Pistas con enlace de Spotify | 5 |
| Pistas con `DURACION_SEG` | 0 |
| Discos con Apple / Deezer | 0 / 0 |

Y del catálogo entero: 139 discos con Spotify, de los que **132 no tienen Deezer
y 77 no tienen Apple**; 1.042 marchas con enlace de Spotify frente a **1 sola con
Deezer**. O sea, la cobertura fuera de Spotify es casi inexistente.

---

## 1. El enfoque: identidad, no búsqueda

`match_discos.py` busca por **título + artista** en cada servicio y puntúa. Eso
genera falsos positivos con nombres genéricos (*Sevilla*, *Aniversario*) y por
eso exige curación humana de todo.

Aquí se parte de otra cosa: **el enlace de Spotify del disco ya está curado**. Se
le pasa esa URL a [Odesli / song.link](https://odesli.co) y devuelve la MISMA
publicación en Apple, Deezer, Amazon, Tidal y YouTube Music. No hay similitud de
por medio: es resolución de identidad. Los falsos positivos que quedan son los de
Odesli agrupando mal, que son raros y están cubiertos por la guarda del §3.

Script: **`php/app/tools/fill_enlaces_odesli.php`**.

### Qué escribe

| Nivel | Cómo se obtiene | Coste |
|---|---|---|
| **Disco** → apple, deezer, amazon, tidal, youtube | 1 llamada a Odesli con la URL del álbum | 10 llamadas |
| **Disco** → apple y deezer que Odesli no dé | repesque por **UPC** (ver §1 bis) | 1-2 llamadas por disco, sin rate-limit de Odesli |
| **Pista** → spotify, apple, deezer | tracklist público del álbum en cada servicio + emparejado por título | 3 llamadas por disco, sin Odesli |
| **Pista** → amazon, tidal, youtube | 1 llamada a Odesli **por pista** (no hay tracklist público) | ~99 llamadas |
| `disco_marcha.DURACION_SEG` | de paso, del mismo tracklist que ya se ha traído | gratis |

El emparejado de pistas es **el mismo matcher que `fill_duraciones.php`**: ambos
scripts comparten ahora `php/app/tools/lib/music_match.php`, extraído tal cual de
`fill_duraciones.php` para que no se bifurquen dos criterios de similitud sobre
el mismo catálogo. Umbral 0,85, asignación greedy 1:1, solo por título.

---

## 1 bis. Resultado del primer dry-run (2026-08-01, 11:57)

13 enlaces de disco de 50 posibles, 7 de pista, 0 llamadas a Odesli a nivel de
pista. Dos causas, muy distintas entre sí:

### a) Odesli no tiene estos discos en Apple ni en YouTube — es real

Contando lo que devolvió de verdad (está en `php/data/odesli_cache/`):

| Plataforma | Discos de 10 |
|---|---:|
| Tidal | 8 |
| Amazon | 4 |
| Deezer | **1** |
| **Apple** | **0** |
| **YouTube** | **0** |

No es un fallo del parseo: las respuestas literalmente no traen `appleMusic`.
Son reediciones digitales de discos de los 80 y Odesli no las tiene agrupadas.

**Arreglo aplicado: repesque por UPC.** El UPC es el código de barras de la
edición, el mismo número en todos los catálogos. Spotify lo da en la ficha del
álbum (`external_ids.upc`), y tanto iTunes (`lookup?upc=`) como Deezer
(`album/upc:`) permiten buscar por él. Sigue siendo identidad exacta, no
búsqueda difusa, y ataca justo el hueco. Se prueba también la variante de 12/13
dígitos, porque hay distribuidoras que indexan el EAN con el cero delante.

### b) Ni una sola pista vía Spotify — esto sí es un problema

`pista:spotify` salió a **0**, y de ahí en cascada: sin tracklist de Spotify no
hay enlaces de pista, no hay duraciones y **no se llega siquiera a la fase de
Odesli por pista** (que parte de la URL de la pista en Spotify). Por eso el run
hizo solo 10 llamadas en vez de ~109 y las 7 pistas que salieron vienen todas
del único disco que sí tenía Deezer.

Comprobado con `diag_spotify.php --nuevos`: las credenciales están en `.env`, el
token se obtiene, y **los 10 álbumes devuelven HTTP 200 con su tracklist
completa**. O sea, en el momento del diagnóstico Spotify respondía bien. Queda
como hipótesis que en el run de las 11:57 el token no llegara a obtenerse
(fallo puntual de `accounts.spotify.com`), porque es la única rama que devuelve
tracklist vacía sin llegar a hacer ninguna petición.

Para que no vuelva a pasar desapercibido se han hecho tres cosas:

1. **El script aborta** si va a hacer nivel de pista y no tiene token, en vez de
   completar un run pobre y silencioso. Para saltárselo a propósito:
   `--no-pistas` (solo discos) o `--sin-spotify` (Apple/Deezer sin Spotify).
2. Cuando un servicio no devuelve tracklist, **dice por qué**: repite la llamada
   en crudo e imprime el código HTTP (401 token caducado, 404 id inexistente o
   fuera del mercado ES, 429 rate-limit, 0 problema de red/TLS…). Antes decía un
   escueto «sin tracklist» porque `httpGet()` se traga el código.
3. `diag_spotify.php` queda en el repo para volver a preguntarlo en cualquier
   momento sin tocar la BD.

**Hasta que el nivel de pista funcione, el grueso del trabajo no se hace.** Las
99 pistas, sus duraciones y todo Amazon/Tidal/YouTube a nivel de pista dependen
de tener el tracklist de Spotify, porque es la URL de la pista la que se le pasa
a Odesli.

---

## 2. Ejecutar  · **actúa: Javier**

```powershell
cd C:\Users\usuario\Documents\mysql-simple
Copy-Item php\data\mdc.db php\data\backups\mdc_pre_enlaces.db

# 1. Dry-run del lote de hoy — no escribe nada, deja el CSV
php php\app\tools\fill_enlaces_odesli.php --nuevos

# 2. Si el CSV convence
php php\app\tools\fill_enlaces_odesli.php --nuevos --commit
```

`--nuevos` = discos cuyo enlace de Spotify se dio de alta en las últimas 24 h.
Para rehacerlo más adelante: `--desde=2026-08-01` o `--discos=12,161,189,…`.

**Tarda ~12 minutos** por el rate-limit de Odesli (~10 peticiones/minuto sin API
key; el script pausa 6,5 s entre llamadas). Las respuestas se cachean en
`php/data/odesli_cache/`, así que el `--commit` tras el dry-run **no vuelve a
pedir nada**: va casi instantáneo.

Si Odesli responde 429, el script espera 20/40/60 s y reintenta; si aun así
falla, lo anota como `SIN_ODESLI` en el CSV y sigue. Relanzar retoma donde se
quedó, porque lo ya pedido está en caché y lo ya escrito no se duplica.

### Ampliar al resto del catálogo

```powershell
# Los 139 discos con Spotify, sin gastar Odesli a nivel de pista
php php\app\tools\fill_enlaces_odesli.php --servicios-pista=spotify,apple,deezer --commit
```

Esos tres servicios tienen tracklist público, así que **no cuestan ni una llamada
a Odesli por pista**: solo 139 de álbum (~15 min). Añadir amazon/tidal/youtube a
nivel de pista serían ~1.500 llamadas, unas **2,7 horas** — hacedero con la
caché, pero para otro rato. `--limite-odesli=N` corta el run tras N llamadas.

> **No quites `spotify` de `--servicios-pista`.** No ahorra nada (su tracklist es
> gratis) y se lleva por delante los enlaces de pista de Spotify, las duraciones
> de los discos que solo estén ahí, y de rebote Amazon/Tidal/YouTube a nivel de
> pista, que parten de la URL de la pista en Spotify. Pasó en el run de las 12:16
> del 2026-08-01: 0 enlaces de pista de Spotify y ninguna llamada a Odesli por
> pista, sin ningún mensaje de error. El script ahora avisa al arrancar si
> Spotify no está en la lista.

---

## 2 bis. Si se corta a mitad (incidencia del 2026-08-01, pasada masiva)

**Lo ya procesado se queda.** Cada disco va en su propia transacción y se
confirma al terminarlo, así que un Ctrl+C solo pierde el disco en curso — y solo
si se lanzó con `--commit`. La caché de Odesli guarda cada respuesta según llega,
así que lo preguntado no se vuelve a preguntar aunque el run muera.

La pasada sobre el catálogo entero se topó con dos cosas a la vez:

**1. Odesli en rate-limit duro.** Devolvía 429 en todo, y cada álbum costaba dos
minutos de esperas para no dar nada. Ahora, tras **3 fallos seguidos el script
apaga Odesli** para el resto del run y sigue con el repesque por UPC, que no lo
necesita. También hay `--sin-odesli` para no usarlo desde el principio.

**2. El token de Spotify caduca a la hora.** El de client-credentials dura 3.600 s
y una pasada de 139 discos tarda más, así que a mitad de camino todo pasó a
`HTTP 401` y se perdieron los tracklists de los discos restantes. Ahora **se
renueva solo** cada 45 minutos, y si aun así aparece un 401 se renueva y reintenta
en el sitio en vez de perder el disco.

### Cómo hacer la pasada grande sin pelearse con Odesli

```powershell
# 1. Todo lo que no necesita Odesli: Apple y Deezer por UPC, disco y pista,
#    más Spotify de pista y duraciones. Sin rate-limit, va del tirón.
php php\app\tools\fill_enlaces_odesli.php --sin-odesli --commit

# 2. Amazon y Tidal, otro día y a trocitos (la caché conserva lo hecho)
php php\app\tools\fill_enlaces_odesli.php --limite-odesli=200 --commit
```

El paso 1 es el que trae el grueso: el UPC cubre Apple y Deezer, y el tracklist
de Spotify da los enlaces de pista y las duraciones. Amazon y Tidal son la guinda
y son justo lo que depende de Odesli.

---

## 2 ter. Resultado de la pasada completa (2026-08-01, 15:18) · **hecho**

139 discos, 1.476 pistas, con `--sin-odesli --commit`. Escrito en BD:

| | Antes | Después |
|---|---:|---:|
| Discos con Apple | 76 | **133** |
| Discos con Deezer | 7 | **128** |
| Discos con Tidal / Amazon | 0 / 0 | 38 / 15 |
| Marchas con Apple | 0 | **911** |
| Marchas con Deezer | 1 | **957** |
| Marchas con Spotify | 1.042 | **1.398** |

231 enlaces de disco nuevos, **177 de ellos por UPC** — o sea, tres de cada cuatro
no los habría dado Odesli. 2.267 enlaces de pista. 1.408 marchas de 5.005 tienen
ya algún enlace.

Las duraciones solo subieron 66 porque `fill_duraciones.php` ya había rellenado
1.325 el 31 de julio; ahora hay 1.391 de 4.451 pistas.

**196 pistas sin emparejar** (13%), en línea con el 12,5% de `fill_duraciones.php`
— es el mismo matcher y las mismas causas: pistas que no están en la edición
digital, popurrís y las erratas del §2 de `pendientes-manuales-2026-07-31.md`.

### Pasada de Amazon y Tidal (2026-08-02, 17:40)

Odesli volvió a responder al día siguiente. Con
`--servicios-pista=spotify,apple,deezer --commit` (sin gastar Odesli por pista):
**Tidal 38 → 53 discos, Amazon 15 → 19**.

Se apagó solo tras 3 fallos a mitad de camino, así que **quedaron 67 álbumes sin
preguntar**. No es un problema: la caché guarda lo pedido (103 respuestas ya) y
cada relanzamiento avanza desde donde se quedó. Quedan 86 discos sin Tidal.

### Pendiente

- **15 candidatos a curar** en `/dashboard/enlaces` (4 tidal, 5 deezer, 3 apple,
  3 amazon). Es la única acción manual que dejan estas pasadas.
- Relanzar el mismo comando de vez en cuando para ir arañando los 67 álbumes que
  Odesli no llegó a contestar. Rinde ~15-19 enlaces por intento: rendimientos
  decrecientes, y Amazon/Tidal son los servicios menos usados.
- Subir el `.db` a producción con `sync_db_to_prod.php`.

---

## 3. Qué NO puede romper

- **Nada se sobrescribe.** Todas las escrituras son `INSERT OR IGNORE` contra
  `UNIQUE(TIPO_ENT, ID_ENT, SERVICIO)`. Un enlace curado a mano no se pisa jamás,
  y relanzar el script es idempotente (verificado: la BD queda byte a byte igual).
- **Dry-run por defecto.** Sin `--commit` no se toca la BD (verificado por hash).
- **Una transacción por disco**, no una por lote: un corte a mitad no deja el
  lote a medias.
- **Guarda anti-álbum-equivocado.** Si Odesli devuelve un id de Spotify distinto
  del que se le ha dado, o el título que devuelve se parece menos de 0,55 al
  `NOMBRE_CD`, los enlaces **no se publican**: van a `enlace_candidato` como
  pendientes y aparecen en `/dashboard/enlaces` para aprobarlos o rechazarlos a
  mano. Verificado con un fixture que simula ese caso.
- **`DURACION_SEG` solo se rellena si estaba a 0**, nunca se recalcula.

### YouTube a nivel de pista

`marcha.AUDIO` ya guarda una URL de YouTube por marcha (1.277 de 4.413) y la
ficha pinta ese embed. Para no acabar con el mismo vídeo dos veces en la misma
página, **el script no escribe enlace de YouTube en una marcha que ya tenga
`AUDIO`**. Sale contado en el resumen como «YouTube saltado (AUDIO)».

Sigue pendiente la decisión de fondo, que viene de `plan-music-apps.md §5`:
¿migrar `marcha.AUDIO` a `enlace_streaming` y dejar una sola fuente de verdad?
Hasta que se decida, esta regla es un parche razonable.

---

## 4. Qué mirar en el CSV

`php/data/enlaces_<runid>.csv` (`;` y BOM, se abre directo en Excel).

| ESTADO | Qué significa | Acción |
|---|---|---|
| `DISCO_NUEVO` | Enlace de álbum publicado (vía Odesli) | mirar por encima el `TITULO_SERVICIO` |
| `DISCO_NUEVO_UPC` | Enlace de álbum publicado (vía código de barras) | ídem; la identidad es aún más fuerte |
| `DISCO_CANDIDATO_UPC` | UPC encontrado pero el título no se parece | **curar en `/dashboard/enlaces`** |
| `DISCO_CANDIDATO` | La guarda del §3 lo ha frenado | **curar en `/dashboard/enlaces`** |
| `DISCO_SIN_ENLACE` | El disco no está en ese servicio | nada, es normal |
| `PISTA_NUEVO` | Enlace de pista publicado | revisar los `SCORE` más bajos |
| `PISTA_SIN_MATCH` | La pista no está en la edición digital, o el título difiere demasiado | ver `SCORE`: por encima de 0,80 suele ser un acierto perdido |
| `SIN_ODESLI` | Odesli no respondió | relanzar |

Las mejoras de matcher pendientes de `pendientes-manuales-2026-07-31.md §2`
(sufijos entre paréntesis, abreviaturas `Stmo.`/`Ntra.`, bajar `--min-sim` a
0,80) **aplican igual aquí**, porque el matcher es literalmente el mismo. Si se
arreglan allí, este script gana lo mismo sin tocar nada.

---

## 5. Validación hecha en el sandbox

PHP 8.3 vía WASM sobre una copia de `mdc.db`, con fixtures en vez de red
(`--fixture=RUTA`, pensado también para CI). Disco 232 «X Aniversario», 10
pistas, con casos plantados a propósito:

| Caso | Resultado |
|---|---|
| Dry-run | BD idéntica byte a byte ✔ |
| Título con tilde y caja distintas (*Cristo de las siete palabras*) | emparejado ✔ |
| Sufijo de versión (*Corona de Espinas - En Directo*) | emparejado ✔ |
| Pista que no está en el álbum | `PISTA_SIN_MATCH`, no se inventa nada ✔ |
| 3 marchas que ya tenían Spotify | respetadas, contadas como «ya tenía» ✔ |
| 6 marchas con `marcha.AUDIO` | sin enlace de YouTube ✔ |
| ISRC de Deezer | guardado en `enlace_streaming.ISRC` ✔ |
| Segundo `--commit` seguido | BD byte a byte igual ✔ |
| Odesli devolviendo otro álbum | 0 publicados, 5 a `enlace_candidato` ✔ |

Lo único que **no** se ha podido probar aquí es la llamada real a
`api.song.link`: el proxy del sandbox la bloquea igual que a Spotify y Apple. La
primera ejecución en local es, de hecho, la primera vez que se habla con Odesli
de verdad — de ahí que el dry-run no sea opcional.

---

## 6. Cuando esté

Subir a producción con el `sync_db_to_prod.php` de siempre (sube el `.db`
entero). La ficha pública ya lee `enlace_streaming` y pinta la botonera por
servicio (`Html::streaming` + `EnlaceRepo::publicadosDe`), así que no hace falta
tocar nada del frontend: los botones nuevos aparecen solos.
