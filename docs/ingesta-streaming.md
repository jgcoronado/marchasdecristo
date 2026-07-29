# Ingesta de marchas desde el catálogo de streaming de las bandas

> Estado: **operativo**, primera pasada real hecha (2026-07-28) sobre las
> bandas con perfil de artista enlazado en Spotify, Deezer o Apple Music →
> candidatos pendientes de revisar en `/dashboard/ingesta`.

## Por qué

La ingesta original (`tools/ingest/`, yt-dlp sobre los canales de YouTube de
las bandas) sigue existiendo, pero la vía más limpia para descubrir marchas
que le faltan a la BD es el **catálogo de streaming de cada banda**: quien ha
enlazado su perfil de artista en `enlace_streaming` tiene ahí publicada su
discografía entera, disco a disco y pista a pista, con título limpio y año de
edición — sin los títulos de vídeo de YouTube, sin directos de tres horas y
sin vídeos de socios.

El flujo es el mismo de siempre: descubrir fuera → volcar candidatos a
`ingest_candidato` → revisar y aceptar a mano en el panel. Nada entra en
`marcha` sin que lo apruebe un humano.

## El pipeline

```
  enlace_streaming (TIPO_ENT='banda', spotify|deezer|apple — YouTube no)
            ▼
  [descubrir_marchas.py]  discografía → pistas → cotejo contra `marcha`
            ▼
  out/candidatos.ndjson  +  out/informe.md
            ▼
  [import_candidatos.php]  →  ingest_candidato   (salta lo vetado)
            ▼
  panel /dashboard/ingesta: revisar · aceptar · descartar
            ▼
  marcha + marcha_autor + enlace_streaming(marcha, servicio)
```

### 1. Descubrir — `tools/music_links/descubrir_marchas.py`

```bash
# Pasada completa (Deezer + Apple; y Spotify si hay credenciales en .env)
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db

# Acotado a unas bandas, sin escribir el NDJSON
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --solo 16,10 --dry-run

# Pasada rápida: Apple es la parte lenta (rate-limit agresivo de iTunes)
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --sin-apple
```

**YouTube no es fuente de este pipeline**, ni para elegir bandas ni como
catálogo, aunque casi todas lo tengan enlazado en `enlace_streaming`: un canal
mezcla marchas con conciertos, ensayos y vídeos de hermandad que no son música
procesional, así que como catálogo mete más ruido del que aporta. Tampoco entran
Tidal ni Amazon, por falta de API pública de catálogo. El descubrimiento se hace
solo sobre discografías: Spotify, Deezer y Apple Music. (El pipeline de YouTube
sigue existiendo aparte, en `tools/ingest/`, y sus candidatos históricos
conviven en la misma tabla.)

Qué hace, por orden:

1. **Selecciona las bandas** con perfil de artista en Spotify, Deezer o Apple
   (`enlace_streaming`, `TIPO_ENT='banda'`). Tener solo YouTube enlazado no
   basta para entrar.
2. **Recorre el catálogo** de cada una: discos y, dentro de cada disco, todas
   sus pistas. Deezer y Apple no piden credenciales; Spotify usa
   `SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET` del `.env`. Toda respuesta se
   cachea en `out/cache/`, así que repetir la pasada es casi instantáneo.
3. **Limpia el título** de lo que es del disco y no de la marcha: numeración,
   `(En directo)`, `- Remasterizado`, `(feat. …)`, y el evento que muchas
   bandas cuelgan detrás de una barra (`Eterna Expiración I Concierto Moriles 2026`).
4. **Descarta ruido** por diccionario (narraciones, saetas, himnos, entrevistas,
   pregones…).
5. **Coteja contra `marcha`** por título normalizado: exacto primero y, si no,
   similitud sobre las marchas que comparten alguna palabra (índice invertido;
   comparar todo contra todo no es viable con 5.000 marchas). ≥0.90 = ya está
   en la BD. Además, si la pista encadena varias piezas (`A / B`, `A - B`) y
   **alguna parte** ya existe, se da por conocida: es una interpretación en
   directo de lo de siempre, no una marcha nueva.
6. **Rellena lo inferible** de cada candidato nuevo: título, banda, año del
   disco, estilo (CCTT/AM según las marchas que la banda ya tiene estrenadas,
   o su nombre si no tiene ninguna), disco de origen y enlace de la pista.
7. **Salta lo vetado** (`ingest_veto`), o sea, lo que ya se descartó a mano.

Salidas en `tools/music_links/out/` (gitignored): `candidatos.ndjson` (para
importar), `candidatos.csv` (para leer en una hoja de cálculo) e `informe.md`
(resumen por banda, con las marchas que faltan y **los discos del catálogo que
no están en la tabla `disco`**).

### 2. Importar — `php/app/tools/import_candidatos.php`

```bash
DB_PATH=php/data/mdc.db php php/app/tools/import_candidatos.php tools/music_links/out/candidatos.ndjson
```

Mismo importador que el pipeline de YouTube. Upsert por origen, sin tocar lo ya
revisado, y saltando lo vetado.

### 3. Revisar — `/dashboard/ingesta`

El panel dejó de ser "Ingesta desde YouTube" y ahora es "Ingesta de marchas":
cada candidato muestra su fuente y se puede escuchar ahí mismo, sea del
servicio que sea. El reproductor lo resuelve `Media::embedDeUrl()` a partir de
la URL del origen (vídeo de YouTube, pista de Spotify, widget de Deezer o
reproductor de Apple Music), así que el panel no necesita saber cómo se
incrusta cada uno; si una URL no se reconoce, queda el enlace externo.

Al **aceptar**, además de crear la marcha:

- fuente `youtube` → la URL va a `marcha.AUDIO` (el hueco del embed), como siempre;
- fuente `spotify`/`deezer`/`apple` → la URL se publica en `enlace_streaming`
  como enlace **verificado** de la marcha, que es lo que pinta la botonera de la
  ficha pública.

Y la ficha pública ya no presupone YouTube: si la marcha no tiene vídeo en
`AUDIO`, `Media::reproductor()` usa el primer enlace de streaming publicado, de
modo que una marcha nacida de esta ingesta se escucha desde su propia ficha.
Como con el vídeo, el reproductor no se carga hasta que se pulsa: hasta ese
clic no se pide nada a terceros.

## Descarte definitivo (veto) y deshacer

Decisión tomada: **un descarte no vuelve a aparecer**, y el veto es por
**origen exacto** (servicio + id de pista/vídeo).

- Al descartar (uno o varios) se escribe una fila en **`ingest_veto`**. Esa fila
  sobrevive aunque se purgue `ingest_candidato`: es el registro permanente de
  "esto ya se dijo que no".
- El importador **salta** cualquier origen vetado, y el descubridor ni siquiera
  lo propone.
- La reevaluación automática (`IngestaRepo::reevaluarTrasCrearMarcha` y
  `app/tools/reevaluar_ingesta.php`), que reabre descartados cuando se crea una
  marcha parecida, **no reabre los vetados**. Un descarte manual es definitivo.
- La misma marcha vista en **otro** servicio sí puede volver a proponerse: es lo
  que significa "veto por origen exacto", y ahí el revisor vuelve a decidir.

**Deshacer el último descarte**: el listado muestra un botón `↩ Deshacer último
descarte` cuando hay algo que deshacer. Devuelve el candidato a *pendiente* y
levanta su veto. Es de **un solo paso** a propósito (`ingest_descarte_ultimo`,
fila única): cubre el "he pulsado sin querer", no el histórico. Un descarte
masivo se deshace entero de una vez. Para recuperar algo más antiguo está la
pestaña **Descartados**.

## Resultado de la primera pasada (2026-07-28)

49 bandas con catálogo enlazado (Spotify/Deezer/Apple) · 5.003 marchas en la BD
· **616 candidatos nuevos en 38 bandas** (370 vía Deezer, 246 vía Apple).

| Banda | Servicios | Discos | Pistas únicas | Ya en BD | Nuevas |
|---|---|---:|---:|---:|---:|
| AM Virgen de los Reyes (#6, Sevilla) | deezer, apple | 61 | 221 | 117 | 97 |
| BCT Las Cigarreras (#16, Sevilla) | deezer, apple | 66 | 170 | 100 | 65 |
| BCT Sol (#7, Sevilla) | deezer, apple | 39 | 129 | 85 | 42 |
| AM Redención (#11, Sevilla) | deezer, apple | 49 | 153 | 95 | 39 |
| AM Estrella (#81, Granada) | deezer, apple | 30 | 95 | 55 | 34 |
| AM Jesús Despojado (#37, Jaén) | deezer, apple | 34 | 94 | 57 | 31 |
| AM Cautivo (#117, Estepona) | deezer, apple | 37 | 43 | 18 | 23 |
| AM Santa Cruz (#200, Huelva) | apple | 17 | 51 | 25 | 23 |
| BCT Centuria Romana Macarena (#5, Sevilla) | deezer, apple | 40 | 124 | 102 | 21 |
| AM La Salud (#26, Sevilla) | deezer, apple | 16 | 88 | 61 | 18 |
| BCT La Esperanza (#166, Málaga) | deezer, apple | 64 | 46 | 28 | 18 |
| BCT Tres Caidas (#20, Sevilla) | deezer, apple | 28 | 140 | 119 | 17 |
| AM Santa María Magdalena (#22, Arahal) | deezer, apple | 18 | 99 | 80 | 16 |
| AM San Juan (#33, Jerez) | deezer, apple | 11 | 52 | 37 | 15 |
| AM Afligidos (#10, Puente Genil) | deezer, apple | 26 | 54 | 41 | 13 |
| AM Paz y Caridad (#9, Estepa) | deezer, apple | 8 | 45 | 29 | 13 |
| BCT Rosario (#24, Cádiz) | deezer, apple | 48 | 77 | 58 | 13 |
| AM Lágrimas de Dolores (#69, San Fernando) | deezer, apple | 45 | 84 | 70 | 11 |
| AM Dulce Nombre de Jesús (#35, Marchena) | apple | 1 | 18 | 7 | 10 |
| AM Estrella (#57, Dos Hermanas) | deezer, apple | 15 | 102 | 90 | 9 |
| AM Encarnación (#64, Sevilla) | deezer, apple | 44 | 104 | 92 | 8 |
| AM Pasión (#48, Linares) | deezer, apple | 30 | 59 | 50 | 8 |
| AM Sentencia (#28, Jerez) | apple | 23 | 53 | 45 | 8 |
| AM Cristo de la Salud (#49, Alcalá la Real) | apple | 1 | 16 | 9 | 7 |
| AM Estrella (#27, Jaén) | deezer, apple | 30 | 51 | 44 | 7 |
| AM Polillas (#25, Cádiz) | deezer, apple | 41 | 92 | 83 | 7 |
| BCT Esencia (#113, Sevilla) | deezer, apple | 48 | 52 | 40 | 7 |
| BCT Presentación al Pueblo (#23, Dos Hermanas) | deezer, apple | 34 | 65 | 58 | 6 |
| AM Redención (#2, Córdoba) | deezer, apple | 20 | 27 | 22 | 5 |
| AM Vera Cruz (#110, Campillos) | deezer, apple | 5 | 16 | 11 | 5 |
| AM Cristo de Gracia (#40, Córdoba) | deezer, apple | 17 | 39 | 32 | 4 |
| AM Dulce Nombre de Jesús (#67, Granada) | deezer, apple | 27 | 19 | 15 | 4 |
| BCT Columna y Azotes (#91, Sevilla) | deezer, apple | 2 | 11 | 7 | 3 |
| BCT Gracia (#120, Carmona) | deezer, apple | 18 | 27 | 23 | 3 |
| AM Carlos III (#74, La Carlota) | apple | 1 | 12 | 10 | 2 |
| AM Misericordia (#3, Lepe) | apple | 1 | 13 | 11 | 2 |
| AM María Inmaculada (#105, Castilleja) | apple | 1 | 8 | 7 | 1 |
| AM Soledad (#84, Pozoblanco) | apple | 1 | 10 | 8 | 1 |

Sin candidatos porque su catálogo entero ya está en la BD: AM Angustias (#62),
AM Santa Cecilia (#106), AM Santo Tomás de Villanueva (#70), BCT Amor de Cristo
(#122) y BCT San Eustaquio (#116).

### Bandas que quedan pendientes de cubrir

Seis bandas **solo tienen Spotify enlazado** (ni Deezer ni Apple), así que esta
pasada no pudo leer su catálogo: AM Valme (#85), AM Virgen de la Oliva (#30),
BCT Cristo del Mar (#46), BCT Despojado (#76), BCT Penas (#93) y BCT Vera+Cruz
(#42). **Con `SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET` en el `.env`, volver a
lanzar el script las cubre** sin tocar nada más (los candidatos ya importados no
se duplican: el upsert es por origen).

Para las bandas sin Deezer ni Apple, el script intenta localizar su artista
**por nombre**, pero solo se fía si además el catálogo encontrado se solapa con
lo que la BD ya sabe de esa banda. Es una guarda necesaria: en una pasada previa
"AM Santa Cruz" enganchó por nombre con un grupo homónimo (su "discografía" eran
temas como *10 Shots* o *The Return of the Kings*) y el lote entero se descartó
solo. Esa banda ahora entra bien por su enlace real de Apple.

## Limitaciones conocidas

- **Autor**: ninguna de las tres APIs devuelve el compositor de estas
  grabaciones (comprobado también en iTunes, que sí tiene campo `composerName`
  pero lo trae vacío). Los 616 candidatos llevan `sin_autor_detectado`, y el
  panel sigue exigiendo al menos un autor para aceptar: se elige a mano con el
  autocompletado (o se crea con "＋ crear compositor").
- **Año**: es el del disco, no el de composición. Para un estreno coinciden;
  para una recuperación en un recopilatorio, no. Revisable en el formulario.
- **Banda de estreno**: se propone la banda dueña del catálogo, que es quien
  graba, no necesariamente quien estrenó. Va marcado con el flag
  `banda_estreno_sin_verificar`.
- **Popurrís**: las pistas que encadenan piezas y no se pudieron resolver
  quedan marcadas `posible_popurri` (15 casos) para trocearlas o descartarlas.
- **Ruido de los discos en directo y de Navidad**: se filtran por diccionario
  los discos navideños/de cabalgata y los tramos de recorrido ("… llegando al
  Postigo 2025"), que Apple sirve mezclados con las marchas. Lo que se escape
  se descarta en el panel, y el veto se encarga de que no vuelva.
- **Localidad/provincia y dedicatoria** se dejan vacías: inferirlas de la banda
  daría datos plausibles pero falsos con demasiada frecuencia.
