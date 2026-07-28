# Ingesta de marchas desde el catálogo de streaming de las bandas

> Estado: **operativo**, primera pasada real hecha (2026-07-28) sobre las 43
> bandas con perfil de artista en Spotify o Deezer → **420 candidatos**
> pendientes de revisar en `/dashboard/ingesta`.

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
  enlace_streaming (TIPO_ENT='banda', spotify|deezer)
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
# Pasada completa (Deezer; + Spotify si hay credenciales en .env)
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db

# Acotado a unas bandas, sin escribir el NDJSON
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --solo 16,10 --dry-run

# Añadir iTunes/Apple como catálogo (lento: rate-limit agresivo)
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --apple
```

Qué hace, por orden:

1. **Selecciona las bandas** con perfil de artista en Spotify o Deezer
   (`enlace_streaming`, `TIPO_ENT='banda'`).
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
cada candidato muestra su fuente y, según ella, el reproductor de YouTube, el
widget de Deezer, el reproductor de Spotify o simplemente el enlace externo.

Al **aceptar**, además de crear la marcha:

- fuente `youtube` → la URL va a `marcha.AUDIO` (el hueco del embed), como siempre;
- fuente `spotify`/`deezer`/`apple` → la URL se publica en `enlace_streaming`
  como enlace **verificado** de la marcha, que es lo que pinta la botonera de la
  ficha pública.

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

43 bandas con Spotify o Deezer · 5.003 marchas en la BD · **420 candidatos
nuevos en 30 bandas**.

| Banda | Discos | Pistas únicas | Ya en BD | Nuevas |
|---|---:|---:|---:|---:|
| AM Redención (#11, Sevilla) | 47 | 153 | 95 | 41 |
| BCT Sol (#7, Sevilla) | 23 | 117 | 77 | 39 |
| AM Estrella (#81, Granada) | 14 | 79 | 42 | 33 |
| BCT La Esperanza (#166, Málaga) | 33 | 61 | 28 | 33 |
| AM Cautivo (#117, Estepona) | 21 | 49 | 18 | 30 |
| AM Jesús Despojado (#37, Jaén) | 16 | 92 | 57 | 30 |
| AM Virgen de los Reyes (#6, Sevilla) | 7 | 89 | 59 | 26 |
| AM Estrella (#27, Jaén) | 15 | 51 | 35 | 16 |
| AM San Juan (#33, Jerez) | 5 | 42 | 27 | 15 |
| AM La Salud (#26, Sevilla) | 7 | 80 | 60 | 14 |
| AM Santa María Magdalena (#22, Arahal) | 12 | 65 | 50 | 14 |
| AM Afligidos (#10, Puente Genil) | 13 | 52 | 39 | 13 |
| BCT Rosario (#24, Cádiz) | 24 | 72 | 58 | 13 |
| BCT Las Cigarreras (#16, Sevilla) | 33 | 113 | 98 | 12 |
| BCT Tres Caidas (#20, Sevilla) | 21 | 78 | 66 | 10 |
| AM Estrella (#57, Dos Hermanas) | 10 | 91 | 80 | 9 |
| AM Pasión (#48, Linares) | 15 | 59 | 50 | 8 |
| AM Encarnación (#64, Sevilla) | 20 | 80 | 71 | 7 |
| AM Paz y Caridad (#9, Estepa) | 3 | 23 | 16 | 7 |
| AM Polillas (#25, Cádiz) | 23 | 92 | 83 | 7 |
| BCT Esencia (#113, Sevilla) | 24 | 52 | 40 | 7 |
| BCT Presentación al Pueblo (#23, Dos Hermanas) | 17 | 65 | 58 | 7 |
| AM Redención (#2, Córdoba) | 10 | 27 | 22 | 5 |
| AM Vera Cruz (#110, Campillos) | 4 | 16 | 11 | 5 |
| BCT Centuria Romana Macarena (#5, Sevilla) | 2 | 20 | 16 | 4 |
| AM Cristo de Gracia (#40, Córdoba) | 8 | 38 | 32 | 3 |
| AM Dulce Nombre de Jesús (#67, Granada) | 16 | 16 | 13 | 3 |
| AM Lágrimas de Dolores (#69, San Fernando) | 23 | 74 | 70 | 3 |
| BCT Columna y Azotes (#91, Sevilla) | 1 | 11 | 7 | 3 |
| BCT Gracia (#120, Carmona) | 9 | 27 | 23 | 3 |

Sin candidatos: AM Santa Cecilia (#106), AM Santo Tomás de Villanueva (#70),
BCT Amor de Cristo (#122) y BCT San Eustaquio (#116) — su catálogo entero ya
está en la BD.

### Bandas que quedan pendientes de cubrir

Nueve bandas del listado **solo tienen Spotify enlazado** (sin Deezer), así que
esta pasada no pudo leer su catálogo: AM Cristo de la Salud (#49), AM Sentencia
(#28), AM Valme (#85), AM Virgen de la Oliva (#30), AM Santa Cruz (#200),
BCT Cristo del Mar (#46), BCT Despojado (#76), BCT Penas (#93) y BCT Vera+Cruz
(#42). **Con `SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET` en el `.env`, volver a
lanzar el script las cubre** sin tocar nada más (los candidatos ya importados no
se duplican: el upsert es por origen).

Para las bandas sin Deezer, el script intenta localizar su artista **por
nombre**, pero solo se fía si además el catálogo encontrado se solapa con lo que
la BD ya sabe de esa banda. Es una guarda necesaria: en esta pasada, "AM Santa
Cruz" enganchó por nombre con un grupo homónimo (su "discografía" eran temas
como *10 Shots* o *The Return of the Kings*) y el lote entero — 60 candidatos
falsos — se descartó solo.

## Limitaciones conocidas

- **Autor**: ninguna de las tres APIs devuelve el compositor de estas
  grabaciones (comprobado también en iTunes, que sí tiene campo `composerName`
  pero lo trae vacío). Los 420 candidatos llevan `sin_autor_detectado`, y el
  panel sigue exigiendo al menos un autor para aceptar: se elige a mano con el
  autocompletado (o se crea con "＋ crear compositor").
- **Año**: es el del disco, no el de composición. Para un estreno coinciden;
  para una recuperación en un recopilatorio, no. Revisable en el formulario.
- **Banda de estreno**: se propone la banda dueña del catálogo, que es quien
  graba, no necesariamente quien estrenó. Va marcado con el flag
  `banda_estreno_sin_verificar`.
- **Popurrís**: las pistas que encadenan piezas y no se pudieron resolver
  quedan marcadas `posible_popurri` (8 casos) para trocearlas o descartarlas.
- **Localidad/provincia y dedicatoria** se dejan vacías: inferirlas de la banda
  daría datos plausibles pero falsos con demasiada frecuencia.
