# Revisión de diseño — 15/09/2026

Auditoría del front público sobre la BD real (`php/data/mdc.db`, 5.024 marchas),
sitio levantado con `php -S` y medido con Chromium a 390 / 768 / 820 / 900 / 1440 px,
en tema claro y oscuro. Rutas: `/`, `/marcha`, ficha de marcha, `/banda`, `/autor`,
`/disco`, `/rankings`, `/acompanamientos`, `/mapa`, `/datos`, `/buscar`, 404.

**Estado (15/09/2026, tarde): aplicado todo menos B1 y B3**, que esperan decisión.
Los cambios están en el árbol de trabajo sin commitear, en seis ficheros:
`php/public/assets/app.css`, `php/app/templates/layout.php`, `marcha_detail.php`,
`acompanamientos_index.php`, `mapa.php` y `php/app/src/Pages.php`.
Verificado: `ci_smoke.php` da exactamente el mismo resultado que la copia base
(mismo fallo a fallo), y 17 rutas × 3 anchos sin desbordamiento ni errores de
estado. Se revierte con `git checkout -- php/`.

## Ya resuelto desde la revisión del 13/09

- Doble ancho `--wrap` / `--wrap-ancho` aplicado y funcionando: a 1440 px el
  explorador mide 1216 px de contenedor frente a los 864 de la ficha.
- `.facet-rail` ya no desborda a 390 px (`min-width: 0` en `.results-layout > *`).
- `.cnt` subido a 0,82rem.
- `--mz-faint` separado a 68 %.
- `/rankings` abre con el primer bloque desplegado (4 de 5 cerrados).

## Lo que está bien y no hay que tocar

- El sistema de color por `color-mix` y la tipografía. Un solo serif para títulos,
  sistema para lectura; nada por debajo de 0,8rem en texto de lectura.
- Foco: `:focus-visible` de 2px en todo lo enfocable, incluida la caja de búsqueda
  (vía `:focus-within` en `.site-search`). `skip-link` presente y funcional.
- `<head>`: canonical, OG completo con imagen 1200×630, Twitter card, JSON-LD de
  `MusicComposition` y `BreadcrumbList`, feeds RSS y JSON. No hay nada que añadir.
- `prefers-reduced-motion` respetado.
- Fuera de `/datos`, ninguna ruta desborda horizontalmente en ningún ancho medido.

---

## A · Fallos medidos

### A1 — `/datos` desborda en móvil
A 390 px el documento mide **421 px de scroll**. Dos causas concurrentes:
la URL de la licencia (`a.link`, 362 px, sin `overflow-wrap`) y una tabla de
**574 px** sin contenedor con `overflow-x`.
*Arreglo:* `overflow-wrap: anywhere` en `.link` y meter la tabla de `/datos` en el
mismo contenedor con scroll que ya usan las demás.

### A2 — En móvil hay que pasar toda la barra de facetas antes de ver un resultado
Medido a 390×844:

| Ruta | Alto de `.facet-rail` | Y del primer resultado | Alto del documento |
|---|---|---|---|
| `/marcha` | 888 px | **1.238 px** | 3.331 px |
| `/banda` | 442 px | 747 px | 3.146 px |
| `/disco` | 292 px | 598 px | 2.855 px |

En `/marcha` son casi **1,5 pantallas de scroll** antes de la primera marcha.
*Arreglo:* por debajo de 720 px, plegar `.facet-rail` en un `<details>` cerrado
con el recuento en el resumen, o pasarla detrás de la tabla con `order`.

### A3 — `--faint` solo cumple AA sobre `--card`
Contraste medido del texto atenuado (recuentos, migas, pie), que es justo lo que
casi nunca se pinta sobre la tarjeta limpia:

| Fondo | Tema claro | Tema oscuro |
|---|---|---|
| `--card` | 4,75 | 4,58 |
| `--zebra` (fila alterna) | **4,48** | **4,34** |
| `--bg` (fondo de página) | **4,35** | **4,86** |
| `--s2` (paneles) | **4,29** | **4,17** |

*Arreglo:* `--mz-faint` de 68 → 72 % en claro y de 61 → 65 % en oscuro. Con eso
pasa de 4,5 sobre `--s2` y sigue habiendo escalón respecto a `--muted` (6,4).

---

## B · Incoherencias del sistema

### B1 — La cabecera cambia de sitio al navegar
A 1440 px, `.header-inner` mide 864 px en portada y ficha y 1216 px en listados:
la marca y el menú **saltan 176 px a la izquierda** al pasar de `/` a `/marcha`.

Lo importante: el código y sus comentarios se contradicen. `layout.php` (l. 95-98)
y `app.css` (l. 196) dicen *«la cabecera y el pie van siempre a `--wrap-ancho`, así
que el menú no cambia de sitio»*, y la regla `body.p-catalogo .header-inner` hace
exactamente lo contrario, con un segundo comentario (l. 320) que justifica lo
contrario del primero.

Hay que elegir una de las dos y corregir los comentarios de la otra. **No lo toco
sin decisión.**

### B2 — Tablas de dos columnas estiradas al ancho de catálogo
En `/rankings` la tabla mide **1.093 px** para «Nombre | Marchas compuestas»: el
número queda a más de 800 px de su nombre y hay que seguir la fila con el dedo.
*Arreglo:* tope propio para tablas de pocas columnas (≈48rem) o número pegado al
nombre en vez de a la derecha del contenedor.

### B3 — Localidades sin tilde en `/acompanamientos`
La página lista «Cadiz», «Cordoba», «Malaga» mientras `/mapa` las escribe con
tilde. No es CSS: `contrato_localidad.LOCALIDAD` y `hermandad.LOCALIDAD` guardan
la forma sin acentuar (Cadiz 109, Cordoba 153, Malaga 251 filas).
**Es dato: no lo toco sin tu visto bueno.** El arreglo limpio es normalizar en BD
y dejar el slug como derivado, no al revés.

### B4 — Emoji 🥁 en la tabla de grabaciones
`span.perc` mete el único glifo en color de todo el sitio dentro de una columna de
duraciones. Se dibuja distinto en cada sistema operativo y choca de frente con la
regla 2 de la hoja (la estructura se dibuja con espacio y fondo, no con adornos).
*Arreglo:* badge tenue como `.badge-1a` con el texto «perc», o el símbolo en `--rec`.

---

## C · Páginas sin estado

### C1 — `/autor` (Compositores) es una pantalla en blanco
Un campo de búsqueda y unos 700 px de vacío hasta el pie. 927 compositores detrás
de un formulario que no dice qué hay dentro, y está en el menú principal.
*Arreglo con piezas que ya existen:* la rejilla A–Z (`ul.azgrid`, ya usada en otras
listas) más los compositores más prolíficos, que `/rankings` ya calcula.

### C2 — Contenido de lectura en contenedor de catálogo
`/acompanamientos` pinta una tarjeta de 1.216 px para una lista de 7 enlaces.
Misma situación en la cabecera de `/mapa`. Están en `$rutasCatalogo` por el primer
segmento, pero su índice no es un catálogo.

### C3 — Doble marcador en la lista de localidades
Cada ítem lleva viñeta `•` y flecha `→`. Sobra uno de los dos.

---

## D · Detalles

- **`color-scheme: light dark` ausente en `:root`**: en tema oscuro la barra de
  scroll y los controles nativos siguen pintándose en claro. Una línea.
- **Sin `<meta name="theme-color">`**: la barra del navegador en móvil no acompaña
  al tema.
- **`--line` tiene 1,25:1 sobre la tarjeta.** Para un filete decorativo vale, pero
  es también el borde de la caja de búsqueda y de los `.btn`, y el límite de un
  control pide 3:1 (WCAG 1.4.11). Un token aparte solo para controles.
- **Flechas de ordenación en tablas de dos filas** (grabaciones de la ficha).
- **`/marcha` duplica la afordancia de orden**: control segmentado arriba y
  cabeceras ordenables en la tabla.
- **«orden» y «por página»** se pintan igual que los botones de su grupo y parecen
  opciones deshabilitadas en vez de etiquetas.

---

## Qué se aplicó y con qué resultado

| | Cambio | Medido después |
|---|---|---|
| A1 | `overflow-wrap: anywhere` en `.link` | `/datos` a 390px: 421px de scroll → 390 |
| A2 | `.facet-rail { order: 2 }` por debajo de 720px | primer resultado: `/marcha` 1.238px → **332**, `/banda` 747 → 288, `/disco` 598 → 288 |
| A3 | `--mz-faint` 68→72 % (claro) y 61→65 % (oscuro) | claro 5,35 / 5,05 / 4,91 / 4,83 (card/zebra/bg/s2); oscuro 5,01 / 4,76 / 5,32 / 4,56. Todo por encima de 4,5 y con escalón contra `--muted` |
| B2 | `/autor` y `/rankings` salen de `$rutasCatalogo`; `[data-cols="2"]` topa la tabla de `/mapa` | tablas de dos columnas: 1.093px → 741 (`/rankings`), 830 (`/autor`), 766 (`/mapa`) |
| B4 | el emoji pasa a badge `.perc` en `--rec`, igual que `.badge-1a` | — |
| C1 | `autorList` lista siempre, como `bandaList` y `discoList` | `/autor` pasa de una pantalla en blanco a los 927 compositores paginados |
| C2 | `$indicesLectura`: el índice de `/acompanamientos` es lectura, sus localidades siguen anchas | — |
| C3 | fuera la flecha `→`; el recuento pasa a `.cnt` | — |
| D1 | `color-scheme: light dark` en `:root` | — |
| D2 | `<meta name="theme-color">` por tema | — |
| D3 | `--line-ctrl` (60 % de acento) en campos, botones, buscador y segmentado | borde de control: 1,25:1 → 3,44 sobre `--card`, 3,16 sobre `--bg`, 3,11 sobre `--s2`; 3,33 / 3,53 en oscuro |
| D4 | la tabla de grabaciones solo es ordenable con más de dos filas | — |

### Efecto colateral que hubo que arreglar

Al estrechar `/autor`, `/rankings` y `/acompanamientos` a `--wrap`, el menú se
partía en dos filas **solo en esas páginas**: la negrita del `aria-current`
ensancha esa etiqueta y nueve secciones no caben en 832px si una va en negrita
("Acompañamientos" se caía a la segunda fila en su propia página). La sección
activa se marca ahora con tinta plena y filete, sin negrita, así que el menú mide
lo mismo en todas las páginas. Medido a 1440, 1024, 900 y 860px: una sola fila en
las once rutas.

## Pendiente de decisión

- **B1 — ancho de la cabecera.** Sigue como estaba: la barra se estrecha y se
  ensancha con el contenido, y el salto de 176px entre portada y explorador sigue
  ahí. No lo toco porque la alternativa (barra fija a `--wrap-ancho`) contradice
  el criterio de que la marca caiga sobre el borde de la primera tarjeta. Los
  comentarios contradictorios de `layout.php` y `app.css` siguen sin corregir a
  la espera de cuál de las dos gana.
- **B3 — tildes de las localidades.** «Cadiz», «Cordoba» y «Malaga» siguen así en
  `contrato_localidad` y `hermandad`. Es escritura en base de datos.

---

# Segunda pasada: fuentes, color y maqueta (misma fecha)

Lo anterior arreglaba defectos medibles. Esto ataca lo otro: que el sitio
*parecía generado*. Tres causas, las tres de manual.

## Fuentes: Georgia + fuente de sistema → IBM Plex Serif + IBM Plex Sans

Georgia para los títulos y la fuente de interfaz del sistema para el texto es la
pareja que sale cuando nadie ha elegido nada, y además hacía que el sitio se
leyera como el panel de administración de algo, no como un catálogo.

Se sirven desde `/assets/fonts`: subconjunto latino, pesos 400 y 600, woff2,
**92 KB en total**, `font-display: swap` y la cadena de respaldo intacta (absorbe
los glifos que el subconjunto no trae: → ▾ ↕). El `.htaccess` ya les daba caché
de 30 días (`FilesMatch` incluye `woff2?`) y la CSP los permite por
`default-src 'self'`: no hubo que tocar ninguno de los dos.

El argumento de fondo no es estético: **Og.php ya dibuja las tarjetas de Open
Graph con IBM Plex Serif** (`php/app/fonts/`). Hasta ahora el enlace que se
comparte y la página a la que llevaba parecían de dos sitios distintos.

## Color: gris azulado → papel templado

El fondo (`#f4f5f8`), la tarjeta (blanco puro) y los filetes eran todos gris
azulado. Ese azul frío en los neutros es la huella dactilar de una plantilla.

| | Antes | Ahora |
|---|---|---|
| `--bg` | `#f4f5f8` | `#f2f0ea` |
| `--card` | `#ffffff` | `#fffefb` |
| `--ink` | `#22262f` | `#232019` |
| `--acc` | `#2c3a77` | `#2e3a6e` |
| `--sombra-base` | `#141c3c` | `#2a2118` |

Y un cambio de fondo en el BLOQUE 3: `--s2`, `--zebra`, `--line` y `--line-ctrl`
**se mezclan desde `--ink`, no desde `--acc`**. Mezclarlos desde el acento teñía
de azul la fila alterna y los filetes; con papel cálido eso es exactamente la
mezcla de grises fríos y cálidos que hay que evitar. Las proporciones son
tokens nuevos del BLOQUE 2 (`--mz-s2`, `--mz-zebra`, `--mz-line`, `--mz-ctrl`),
con sus valores propios en oscuro. El único color frío que queda es el acento:
tinta sobre papel, que es un contraste de imprenta, no de plantilla.

El tema oscuro va en paralelo: `--bg #181614`, `--card #211e1b`, `--ink #ebe7e0`,
`--acc #9aacee`, `--mz-faint` al 70 %.

Contraste medido después, sobre las cuatro superficies (card / zebra / bg / s2):

- claro — ink 16,1 / 15,2 / 14,3 / 14,5 · muted 6,7 / 6,3 / 5,9 / 6,0 ·
  faint 5,5 / 5,2 / 4,9 / 5,0 · acc 10,7 / 10,1 / 9,5 / 9,6
- oscuro — ink 13,5 / 12,2 / 14,6 / 11,3 · muted 6,7 / 6,1 / 7,3 / 5,6 ·
  faint 5,7 / 5,1 / 6,2 / 4,7 · acc 7,5 / 6,8 / 8,2 / 6,3

Los cinco semánticos (`--est`, `--nov`, `--rec`, `--err`, `--juv`) siguen igual y
todos pasan de 5:1 sobre las cuatro. El filete de control queda en 3,71 / 3,28 /
3,34 (claro) y 3,80 / 4,14 / 3,18 (oscuro), por encima del 3:1 que pide 1.4.11.

## Maqueta

- **La portada tiene cabecera y `<h1>`.** No tenía ninguno de los dos: empezaba
  por un párrafo de bienvenida dentro de una tarjeta, que es la forma más
  reconocible de "esto lo ha generado algo". Ahora es una cabecera sobre el
  papel: título en el cuerpo de display (hasta 2,8rem), entradilla debajo y las
  cuatro cifras separadas por un filete.
- **Los exploradores y los hubs también tienen `<h1>`.** El recuento
  («Marchas — 5.024 registros») era un `<span>`: es el título de la página, así
  que ahora es el encabezado. Siete plantillas. Ninguna página queda sin h1.
- **Las tarjetas se despegan con sombra, no con filete.** Un borde de 1px
  alrededor de cada bloque hacía que la página se leyera como una rejilla de
  cajas. Las tablas conservan el suyo: ahí el filete enmarca datos.
- **Escala tipográfica.** El h1 pasa de 1,55rem fijos a `clamp()` hasta 2,4rem en
  ficha y 2,8 en portada, con interletrado apretado. Los `<h2>` de sección a
  1,28rem. La entradilla y la prosa se topan a 46rem y llevan `text-wrap: pretty`.
- **`body > header`.** El selector de elemento a secas pintaba el fondo y el
  filete de la barra de navegación sobre la nueva cabecera de portada.
- **Los enlaces de "Explorar el catálogo" se separan con filete, no con viñeta.**

## Coste y riesgo

92 KB de fuentes en la primera visita, cacheadas 30 días. Cero consultas nuevas.
`ci_smoke.php` sigue dando exactamente el mismo resultado que la copia base y las
16 rutas siguen sin desbordarse a 390, 768 y 1440. Todo se revierte con
`git checkout -- php/` más borrar `php/public/assets/fonts/`.

## Visto y no tocado

- A 390px la tabla de `/marcha` sigue partiendo los nombres de compositor en
  cuatro o cinco líneas dentro de su contenedor con scroll. Es anterior a esta
  revisión y arreglarlo pasa por decidir qué columna se esconde en móvil.
