# Rediseño 2026-11 — decisiones de proyecto

> Generado: 2026-11-11 · parte de [auditoria-2026-11-10.md](auditoria-2026-11-10.md) §2 y de [identidad-visual.md](identidad-visual.md).
> Dos diseños sobre el mismo marcado: **A «Uniforme»** (rama `rediseno/a-uniforme`) y **B «Nazareno»** (rama `rediseno/b-nazareno`).

## 0. Reglas que no se discuten

Salen de las quejas del mantenedor y de las tres rondas de revisión anteriores. Cualquier cambio que las rompa se rechaza.

1. **Nada de filetes de color.** Ni `border-top/left/bottom` de acento en tarjetas, cabecera, pestañas, menú o títulos; ni subrayado de color para «lo activo». Lo activo se marca con **fondo** o **color de texto**. Solo se admiten líneas neutras de 1 px (`--linea`) para separar filas o la cabecera de tabla.
2. **Nada de monoespaciadas**, ni rótulos en MAYÚSCULAS espaciadas, ni rótulos pequeños en negrita tipo «KICKER». Las siglas reales (AM, CCTT) se escriben como siglas, en peso normal o 600.
3. **Nada de píldoras.** Botones, etiquetas, campos y segmentados con el radio del diseño (`--r`, 3–4 px). Ni `border-radius: 999px` ni círculos salvo el botón de tema (icono).
4. **Nada de hueco a la derecha.** Todo bloque ocupa su columna; si el contenido es estrecho, se reparte en columnas o se mete en una rejilla. Sin `max-width` en texto suelto que deje media tarjeta vacía. Sin scroll horizontal de página a 360 px.
5. **Nada de tics de IA:** degradados, emojis como iconos, cifras gigantes en mosaico, bandas con eslogan y dos botones, sombras grandes difusas, «tarjeta con icono en círculo + título + texto», numeración 01/02/03, cursivas de titular, fondo crema con terracota, efectos de cristal, animaciones de entrada.
6. **Datos reales siempre.** Se prueba con el servidor local y la BD real (`php/data/mdc.db`). Nada de maquetas HTML.
7. **La portada conserva estructura y contenido** (encargo del mantenedor del 10-11): solo cambia de piel.
8. **Sin terceros hasta que el visitante pulsa** (ningún iframe ni miniatura de YouTube en las fichas). `ci_smoke` lo comprueba.

## 1. Escala y tokens (los dos diseños)

- **Texto:** seis tamaños `--t-xs .8125rem · --t-s .9rem · --t-m 1rem · --t-l 1.2rem · --t-xl 1.6rem · --t-xxl clamp(1.8rem,3.6vw,2.4rem)`. Ningún `font-size` literal en lo nuevo.
- **Espacio:** `--e1 .25rem · --e2 .5rem · --e3 .75rem · --e4 1rem · --e5 1.5rem · --e6 2rem · --e7 3rem`.
- **Puntos de corte:** dos, `48rem` (móvil/tableta) y `64rem` (escritorio).
- **Ancho:** `--wrap 82rem`, igual en todas las páginas; `--gut clamp(1rem,2.5vw,1.75rem)`.
- **Color (nombres comunes):** `--bg` página, `--card` superficie, `--sup2` superficie hundida (cabecera de tabla, cebra), `--ink` texto, `--muted` texto secundario, `--linea` línea neutra, `--enlace`, `--marca` (barra), `--marca-fg`, `--acento`, `--cctt`, `--cctt-fg`, `--am`, `--am-fg`, `--foco` (anillo de foco). Ningún color literal fuera de `r/base.css` y `dark.css`.
- **Forma:** `--r` (radio único), `--sombra` (una sola, de 1–2 px o ninguna).
- **Táctil:** todo control de 44 px de alto en móvil, 36–40 px en escritorio.
- **Foco:** `outline: 2px solid var(--foco); outline-offset: 2px` en `:focus-visible`, nunca quitado.

## 2. Decisiones por campo

### 2.1 Color

| | A «Uniforme» | B «Nazareno» |
|---|---|---|
| Idea | Guerrera de banda: paño noche, cordón grana, galón oro | Túnica de nazareno y palio: morado, plata y verde esperanza |
| Marca (barra) | `#151b36` noche, en los dos temas | Barra **blanca/clara** con el logotipo morado; en oscuro, barra `#1a1620` |
| Enlace claro / oscuro | grana `#8f1d30` / oro `#e0bb63` | morado `#5a2d73` / lila `#c9a6dd` |
| Página / superficie | `#f3f4f7` / `#fff` (tarjetas) | `#fff` / `#fff` (sin tarjetas: secciones planas separadas por espacio) |
| CCTT / AM | grana / oro | morado / verde `#1e6b4c` |
| Oscuro | página `#0e1120`, tarjeta `#171c31` | página `#141216`, superficie `#1c1920` |

Contraste mínimo 4,5:1 en texto y 3:1 en controles, medido en los dos temas.

### 2.2 Tipografía

- **A:** Archivo variable (ya autoalojada). Títulos 700, texto 400, cifras tabulares en tablas.
- **B:** Public Sans variable (OFL, USWDS 2019), autoalojada en `assets/fonts/`. Títulos 700 con `letter-spacing:-.01em`, texto 400.
- Interlineado 1,5 en texto, 1,15 en títulos. Medida de prosa (notas, biografías) 70 caracteres **dentro de una columna que no deja hueco** (la columna se estrecha, no el párrafo).

### 2.3 Marco: cabecera, menú, pie, portada

- Cabecera de una sola altura en todo el sitio. **Escritorio:** logotipo + buscador + tema en una fila, menú debajo dentro de la misma barra. **Móvil:** una fila (logotipo, lupa, tema, menú); la lupa despliega el buscador a todo el ancho; el atajo «/» no se enseña en táctil.
- Sección activa: fondo (A: blanco al 10 % sobre noche; B: morado con texto blanco). Sin filete.
- Migas en `--t-s` y `--muted`, sin «Inicio /» repetido en móvil si no cabe.
- Pie: tres grupos en columnas (secciones, datos y licencia, contacto) que llenan el ancho.
- Portada: misma estructura; las cifras dejan de ser «mosaico de números gigantes» y pasan a una línea de datos (`--t-l`), la quinta cifra no queda huérfana en móvil.

### 2.4 Componentes y controles

- Botón primario (fondo `--enlace`), secundario (borde `--linea`, fondo `--card`), de texto. Radio `--r`.
- Segmentados (orden, por página): rectangulares unidos, el elegido con fondo; en móvil pasan a `<select>` nativo o se pliegan con los filtros.
- «Búsqueda avanzada» y «Refinar por»: un botón «Filtros» que despliega el panel; en escritorio B, panel lateral fijo a la izquierda del listado.
- Etiqueta de estilo: rectángulo `--r` de color sólido, texto `--cctt-fg`/`--am-fg`. Etiquetas secundarias: texto `--muted`, sin caja.
- Pestañas (versión original/actual): segmentado con fondo, no subrayado.
- Paginación: números rectangulares, la actual con fondo; «Anterior/Siguiente» con texto.

### 2.5 Tablas

- Cabecera `--sup2`, texto `--ink` 600 en `--t-s`, sin mayúsculas. Cebra suave con `--sup2` al 50 %. Fila al pasar: `--sup2`.
- Solo la **primera columna enlazada** lleva `--enlace` y 600; el resto de enlaces en `--ink` con subrayado al pasar.
- Cifras a la derecha con `font-variant-numeric: tabular-nums`; vacíos «—» en `--muted`.
- Cabeceras ordenables con flecha de texto pequeña (▲/▼) y `aria-sort`.
- **Móvil (<48rem):** la tabla pasa a **lista de filas de dos líneas**: la celda principal arriba en 600; el resto en una línea secundaria `--t-s --muted` separada por « · »; cifras al final de la primera línea. Marcado: `<table class="t">`, celda principal `.t-main`, cifras `.t-num`, y `data-l="Banda"` en cada `<td>` para la etiqueta cuando haga falta (`data-l=""` la oculta).
- Tablas largas (>30 filas): filtro de texto encima (ya existe en la de grabaciones) y cabecera pegajosa en escritorio.

### 2.6 Escuchar y multimedia

- **Escuchar va primero** en la ficha de marcha y de disco (en móvil, justo bajo el título).
- Servicios: rejilla de botones rectangulares de 44 px, **logotipo monocromo del servicio** (SVG en línea, rutas de Simple Icons, CC0) en el color de marca del servicio + nombre. Sin puntos de color.
- Versión original / actual: segmentado (§2.4) encima de los botones.
- **Estado vacío diseñado:** «Todavía no tenemos ninguna grabación enlazada de esta marcha. Si conoces una, [propónla]». Ocupa el mismo sitio que tendrían los botones.
- **Portadas:** `<img loading="lazy" decoding="async" width height alt>`, cuadradas, radio `--r`, borde `--linea`. Sin portada → **funda tipográfica** (cuadrado `--sup2` con el título del disco y la banda en texto), nunca un cuadro de letra+año.
- **Mosaico** de la cabecera de ficha: solo portadas reales; si hay menos de 3, no se pinta. En PRE (portadas remotas), la que falle se retira con `onerror`.
- **Sello** (cuadro de año/siglas generado): **se retira**. Es una cifra gigante en mosaico (regla 5).
- **Listado de discos:** rejilla de fundas (portada o funda tipográfica) con título, banda y año debajo. Tabla como alternativa (`?vista=tabla`).

### 2.7 Fichas

Regiones con nombre para que cada diseño las coloque con `grid-template-areas`:

```
.ficha
  .ficha-cab       título, etiqueta de estilo, frase («Marcha de agrupación musical de X, 1969. Estrenada por Y. Dedicada a Z.»), compartir
  .ficha-escuchar  §2.6
  .ficha-datos     <dl> clave-valor (lo que no cabe en la frase)
  .ficha-portadas  mosaico §2.6
  .ficha-cuerpo    tablas grandes (grabaciones, obra, estrenos, contenido…)
  .ficha-pie       «Ficha n.º 1584 · enlace permanente …», en --muted
```

- **A:** cabecera a todo el ancho (título + frase a la izquierda, portadas a la derecha); debajo dos columnas: izquierda estrecha (escuchar, datos, notas), derecha ancha (cuerpo).
- **B:** estilo enciclopedia: columna principal (cabecera, escuchar, cuerpo) y **ficha técnica a la derecha** (portadas arriba + datos), pegajosa en escritorio.
- **Móvil (los dos):** título → escuchar → datos → cuerpo.
- **Por entidad:** disco con portada grande junto al título; compositor con la obra **agrupada por décadas**; banda con los **acompañamientos del último año arriba** y la discografía como fundas; marcha con «Véase también» sin repetir lo que ya está en la frase.
- El identificador («M-1584») sale de la cabecera y va al pie de la ficha.

### 2.8 Revisión no-IA (barrido)

Barrer `app.css`, `identidad.css` y `dark.css` y anular en `r/base.css` todo lo que rompa §0: filetes de color, píldoras (`999px`, `2em`, `50%` en no-iconos), mayúsculas espaciadas, sombras difusas, el puntito «·» de las píldoras de streaming, el cuadro del atajo «/» en táctil.

## 3. Reparto (fase 1, diseño A)

| Área | Agente | Es dueño de | Hoja |
|---|---|---|---|
| Color, tipo, escala, barrido no-IA | dev-base | `dark.css`, tokens | `r/base.css` |
| Marco y portada | dev-marco | `layout.php`, `home.php`, buscador móvil en `catalog.js` | `r/marco.css` |
| Controles | dev-controles | `Html::chipEstilo`, `pagination`, `porPagina` | `r/controles.css` |
| Listados y tablas | dev-tablas | `*_list.php` (menos disco), `rankings*`, `aniversarios*`, `acompanamientos*`, `dedicatoria_*`, `estado_catalogo`, `marcha_hub`, plantilla de `/buscar` | `r/tablas.css` |
| Escuchar e imágenes | dev-media | `Html::escuchar/streaming/cover/tesela/mosaico/sello/cdList`, `disco_list.php`, `disco.js` | `r/media.css` |
| Fichas | dev-fichas | `marcha_detail`, `banda_detail`, `autor_detail`, `disco_detail`, lo que necesiten de `Repo.php` | `r/fichas.css` |

Nadie toca `app.css` ni `identidad.css`: se anula en la hoja propia. La integración final funde las seis hojas.

**Fase 2:** diseño B sobre lo integrado (tokens + marco + colocación de regiones). **Fase 3:** QA (Fable 5.2, esfuerzo medio) con capturas reales a 1440 y 390 px, claro y oscuro; se itera hasta 9,5/10.
