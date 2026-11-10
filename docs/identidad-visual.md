# Identidad visual — propuesta 2026-11

> Generado: 2026-11-10 · rama local `rediseno/identidad-visual` (sin push ni despliegue)
>
> Qué es la identidad, de dónde sale cada decisión y qué errores de rondas anteriores evita.
> El código vive en `php/public/assets/identidad.css`; este documento es su porqué.

## 1. Encargo

- Identidad nueva desde cero, con buena paleta y un logotipo que tenga que ver con el sitio.
- Nada de tics de diseño generado por IA. Referencia pedida: las webs de finales de los 2010 y principios de los 2020.
- Es una base de datos: la estructura de tablas se mantiene.
- Usar todo el ancho; ninguna caja con hueco a la derecha.
- Cabecera atractiva por ficha sin tener fotos: collage de portadas de disco y gráfica generada.
- La portada conserva su estructura y su contenido; solo cambia de piel.

## 2. Referencia

Catálogos web de 2017–2021 (Discogs, MusicBrainz, Setlist.fm): barra de marca en color plano, tarjetas blancas sobre gris claro, una sola sans en caja normal, tablas rayadas con cabecera gris, etiquetas en píldora, esquinas de 6–8 px.

**Lo que no hay, a propósito:** rótulos en mayúsculas espaciadas, monoespaciadas, serif en cursiva de titular, fondo crema con acento terracota, filetes finos de periódico, numeración 01/02/03, cifras gigantes en mosaico, bandas de portada con eslogan y botones, degradados, emojis.

## 3. Sistema

### Color — «uniforme de banda»

| Token | Valor | Papel |
|---|---|---|
| `--noche` | `#151b36` | Paño de la guerrera: barra y pie, **en los dos temas** |
| `--grana` | `#8f1d30` | Cordones y banderín: enlace y acción en claro; estilo CCTT siempre |
| `--oro` | `#d4a94a` | Galones y metal: acento, filete de la barra; enlace en oscuro; estilo AM siempre |
| `--bg` / `--card` | `#f3f4f7` / `#ffffff` | Página gris claro, tarjetas blancas |
| `--ink` | `#1c2033` | Tinta azul noche |

En oscuro la página pasa a `#0e1120` y las tarjetas a `#171c31`. La barra, el pie, el logotipo y las etiquetas de estilo son idénticos en ambos temas: la marca no cambia con la hora.

### Tipografía

Una sola familia: **Archivo** (variable, OFL, autoalojada, 90 KB). Negrita para títulos, normal para texto, cifras tabulares en tablas. Seis tamaños (`--t-xs` … `--t-xxl`) y ninguno fuera de ellos en lo nuevo.

### Logotipo

Una **M entre los dos aros de un tambor**: los trazos de la M son los tensores. Dos colores planos (oro y blanco sobre noche), en SVG en línea en `layout.php` y como `favicon.svg`. Se lee a 16 px.

### Estilo como señal

Toda marcha es de cornetas y tambores o de agrupación musical. Píldora de color fija, **CCTT en grana y AM en oro**, en fichas y en el listado.

### Sello y mosaico (`App\Html`)

- `sello()` — el emblema generado de cada ficha: color del estilo y año (marcha), sigla y año de fundación (banda), iniciales (compositor). Existe para todo el catálogo, sin depender de ninguna foto.
- `mosaico()` / `tesela()` — collage con las portadas de los discos donde está grabada una obra, de la discografía de una banda o de los discos con más obra de un compositor. Cada tesela lleva debajo un marcador (inicial y año): si la portada no existe o no carga, queda el marcador, nunca un hueco. Las que tienen portada van delante.
- `hayPortada()` — mira el fichero cuando las portadas están en el mismo host; en PRE (portadas remotas) devuelve «no se sabe» y decide el navegador.

### Composición

- **Un solo ancho de página** (`--wrap`, 82rem) para todo el sitio.
- **Fichas:** tarjeta de cabecera (sello, título, frase, mosaico) y dos columnas de tarjetas. A la izquierda, lo estrecho por naturaleza: tabla de datos, escuchar, notas. A la derecha, las tablas que necesitan ancho.
- **Disco:** portada grande, título, escucha y la tabla de datos dentro de la cabecera; debajo, el contenido a todo el ancho.
- **Móvil:** en la ficha, «Escuchar» sube por delante de la tabla de datos; el mosaico queda en una fila.
- **Portada:** misma estructura y mismo texto. La marcha del día aprovecha el ancho con los datos a la izquierda y el vídeo a la derecha.

## 4. Errores anteriores y cómo quedan

| Error documentado | Dónde | Ahora |
|---|---|---|
| La cabecera saltaba 176 px entre páginas (dos anchos) | `revision-diseno-2026-09-15.md` B1 | Un solo ancho; `.header-inner` fijo en todas las páginas |
| Contenido estrecho, cajas con hueco a la derecha | queja del mantenedor | 82rem y dos columnas en fichas; la tabla de datos ya no ocupa una caja a todo el ancho |
| Tablas de dos columnas estiradas, número lejos del nombre | B2 | Rankings en rejilla de tarjetas; búsqueda con un grupo por columna |
| El menú se partía porque lo activo iba en negrita | efecto colateral de B2 | Lo activo se marca con filete de oro, sin cambiar el ancho del texto |
| Emojis como iconos (🥁, 🔗, 💿) | B4 y listado | Etiquetas de texto: «perc.», «Sí», número de discos |
| Dos colores de marca según el tema | auditoría §2 n.º 5 | Barra, logotipo y etiquetas iguales en claro y oscuro |
| Ficha de disco con tres márgenes izquierdos | auditoría §2 n.º 10 | Cabecera en rejilla; la portada ausente deja su marcador |
| Las cuatro fichas eran la misma plantilla | auditoría §2 n.º 1 | Sello y color distintos por entidad; disco con portada; banda y compositor con mosaico |
| Muro de enlaces del mismo color en tablas | auditoría §2 n.º 8 | Solo la primera columna enlazada lleva color y peso |
| Escuchar eran cuatro píldoras de 13 px | auditoría §2 n.º 3 | Botones de 44 px con el color del servicio; estado vacío que pide la grabación |
| Vocabulario de base de datos | auditoría §2 n.º 12 | «5.459 marchas» en vez de «registros»; el identificador pasa a la etiqueta de la tarjeta Ficha |
| Fuentes que delatan una interfaz generada (Plex Serif) | segunda pasada de septiembre | Una sola sans de época, sin serif |

Decisiones anteriores que **se mantienen**: sin reproductores incrustados en la ficha (nada de terceros hasta que el visitante pulsa), pestañas de versión original y actual, tema elegido en `localStorage`, tokens de color derivados con `color-mix()`.

## 5. Qué toca el cambio

- `php/public/assets/identidad.css` — nuevo; se carga entre `app.css` y `dark.css`.
- `app.css` y `dark.css` — solo tokens de color, familia tipográfica y ancho.
- `layout.php` — logotipo, hoja nueva, pie.
- `marcha_detail.php`, `banda_detail.php`, `autor_detail.php`, `disco_detail.php` — recompuestas.
- `marcha_list.php`, `disco_list.php`, `rankings_index.php` y los títulos de listado — ajustes menores.
- `Html.php` — `sello`, `mosaico`, `tesela`, `chipEstilo`, `hayPortada`. `Repo.php` — `ESTILO` en el listado y `DISCOS_OBRA` en la ficha de compositor.
- `tools/ci_smoke.php` — una aserción adaptada (las relacionadas son ahora tabla). 103 de 103 pasan.
- `home.php` y `Pages.php` — **sin cambios**.

## 6. Pendiente si se acepta

1. Fundir `identidad.css` en `app.css` y borrar las reglas a las que sustituye (hoy conviven para que el cambio sea reversible quitando una línea de `layout.php`).
2. `Og.php` sigue dibujando las tarjetas sociales con IBM Plex Serif (`php/app/fonts/`): cambiar a Archivo y a la paleta nueva para que el enlace compartido y la página coincidan.
3. Borrar las cuatro fuentes Plex de `assets/fonts/` y actualizar su `README.md`.
4. Panel de administración: hereda color y tipografía, no se ha recompuesto.
5. Validar en PRE con capturas a 390 y 1440 px, en claro y oscuro, antes de fusionar.
