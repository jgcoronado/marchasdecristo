# Rediseño de marchasdecristo.com — clasificación de los diseños A «Uniforme» y B «Nazareno»

> Documento de entrada para una sesión de investigación de diseño. Describe qué hay hoy, no qué hay que hacer.
> Fecha: 2026-11-13 · Fuentes: capturas con la BD real del 13-11 (actual = `main` 97d5115, A = `rediseno/a-uniforme` 722b101, B = `rediseno/b-nazareno` bd5b41b) y el CSS y las plantillas de las dos ramas.
> Comparador visual con las 132 capturas: artefacto «Rediseño MDC: actual, A «Uniforme» y B «Nazareno»» en claude.ai.

---

## 1. Qué es el sitio y qué no se discute

- **Qué es:** una base de datos de conocimiento sobre música procesional de Semana Santa: marchas, compositores, bandas, discos, dedicatorias y acompañamientos (qué banda tocó detrás de qué paso cada año). Unas 5.500 marchas, 960 compositores, 360 bandas, 450 discos y 6.700 acompañamientos.
- **Función principal:** consultar y navegar datos. Aunque sea de música, no es una web de escucha. La estructura de tabla se mantiene: la ha pedido el mantenedor.
- **Imágenes disponibles:** solo las portadas de los discos (no todos las tienen). No hay fotos de bandas, compositores ni hermandades con permiso. El logotipo actual es genérico, hecho con IA, y puede cambiar.
- **Técnica:** PHP con renderizado en servidor, SQLite, sin framework JS. Modo claro y oscuro. Móvil y escritorio por igual.
- **Ya decidido, al margen del diseño elegido:**
  - en la ficha de marcha, Escuchar va **después** de la información;
  - los enlaces no se subrayan nunca, ni al pasar el ratón: se distinguen solo por color.
- **Reglas fijadas por el mantenedor** en rondas anteriores, recogidas en `docs/rediseno-2026-11.md` §0:
  - sin filetes de color (bordes de acento en tarjetas, pestañas o títulos);
  - sin monoespaciadas, sin rótulos en MAYÚSCULAS espaciadas, sin rótulos tipo «kicker»;
  - sin píldoras;
  - sin hueco vacío a la derecha de los bloques; todo el ancho aprovechado;
  - sin tics de IA: degradados, emojis como iconos, cifras gigantes en mosaico, bandas con eslogan y dos botones, sombras difusas, tarjeta con icono en círculo, numeración 01/02/03, cursivas de titular, crema con terracota, cristal, animaciones de entrada;
  - datos reales siempre.

## 2. Veredicto del mantenedor (13-11-2026)

Ninguno de los dos convence. Los dos conservan muchos elementos habituales de los diseños genéricos y aburridos hechos con IA. En concreto señala:

1. **Varios colores genéricos sin una paleta homogénea.**
2. **Uso indiscriminado de etiquetas (tags).**
3. **Menciones y subtítulos en cada apartado, con datos numéricos por todas partes.**
4. «Etc.»: la lista no es cerrada. El §5 recoge otros patrones de la misma familia detectados en las capturas.

Contexto útil para la siguiente sesión: la revisión visual automática con Fable 5.2 puntuó A con 8,8/10 y B con 8,3/10. Ese criterio **no** coincide con el del mantenedor. La siguiente sesión no debe dar por buenas esas notas ni el gusto por defecto de un modelo: tiene que apoyarse en referencias externas.

## 3. Clasificación

### 3.1 A y B no son dos diseños: son un diseño con dos pieles

B se construyó **encima de A**. Tiene el mismo marcado PHP y las mismas seis hojas de A (`r/base`, `marco`, `controles`, `tablas`, `media`, `fichas`), más dos hojas que las pisan: `r/b-piel.css` (149 líneas) y `r/b-composicion.css` (210). Las plantillas de A y B solo difieren en `layout.php` y en una vista del panel de administración.

Consecuencias:
- tienen el mismo inventario de componentes, la misma jerarquía de contenido, las mismas etiquetas y los mismos contadores;
- cambian el color, la tipografía, que haya o no tarjetas, la barra superior y dónde se coloca la ficha técnica;
- **las tres quejas del §2 están en el marcado o en el contenido, no en la piel.** Ningún cambio de paleta las arregla.

### 3.2 Familia visual de cada uno

| | A «Uniforme» | B «Nazareno» |
|---|---|---|
| Familia a la que recuerda | Panel de administración o SaaS: tarjetas blancas con borde sobre fondo gris, barra superior oscura y pie oscuro | Sistema de diseño gubernamental o documentación: blanco continuo, sin tarjetas. Public Sans es la tipografía del US Web Design System |
| Idea de la paleta | «Guerrera de banda»: noche, grana y oro | «Túnica y palio»: morado, plata y verde esperanza |
| Tipografía | Archivo variable (grotesca), una sola familia | Public Sans variable, una sola familia |
| Personalidad | Corporativa y genérica: el color va en la barra y en las etiquetas | Neutra y administrativa: el morado solo aparece en enlaces, menú activo y etiquetas |

### 3.3 Puntuación frente a las quejas del mantenedor

Escala: **Alta** = el patrón domina la página · **Media** = aparece en varias páginas · **Baja** = puntual.

| Patrón | A | B | Dónde se ve |
|---|---|---|---|
| Colores sin paleta homogénea | **Alta** | **Alta** | §4.1 y §5.1 |
| Etiquetas indiscriminadas | **Alta** | **Alta** | §5.2 |
| Subtítulos y menciones con números | **Alta** | **Alta**; con las facetas, más que A | §5.3 |
| Plantilla de portada «hero centrado + cifras» | Alta | Alta | Portada |
| Tarjetas como contenedor universal | Alta | Baja (las quitó) | Todas las páginas |
| Iconografía y colores de marcas ajenas | Media | Media | Botones de Escuchar |
| Controles segmentados como barra de herramientas | Media | Media | Listados |
| Meta repetida del sistema (compartir, enlace permanente, n.º de ficha) | Media | Media | Fichas |

## 4. Inventario por diseño

### 4.1 Identidad

**Color de A** (tokens de `r/base.css` y `dark.css`):

| Papel | Claro | Oscuro |
|---|---|---|
| Página | `#f3f4f7` (gris azulado) | `#0e1120` |
| Tarjeta | `#ffffff` | `#171c31` |
| Superficie hundida (cabecera de tabla, cebra) | `#eceef3` | `#212741` |
| Texto | `#1c2033` | `#eef0f6` |
| Texto secundario | `#535a70` | `#aab0c6` |
| Línea | `#d9dce5` | `#2e3553` |
| Enlace | `#8f1d30` grana | `#e0bb63` **oro**: el enlace cambia de tono entre temas |
| Barra superior y pie | `#151b36` noche, en los dos temas | `#151b36` |
| Acento | `#d4a94a` oro | `#e0bb63` |
| Etiqueta CCTT (cornetas y tambores) | grana con texto blanco | `#a8273d` |
| Etiqueta AM (agrupación musical) | oro con texto oscuro | oro |

- Además, el póster del vídeo es un bloque noche macizo, y cada botón de Escuchar lleva su color de marca (Spotify, Apple Music, Deezer, YouTube, Tidal, Amazon).
- **Deuda de color:** la hoja heredada `app.css` sigue definiendo familias de color semánticas (`--est`, `--nov`, `--rec`, `--err`, `--juv`, `--pt-1…4`), y entre `app.css`, `identidad.css`, `dark.css` y las seis hojas de `r/` hay **55 colores hexadecimales distintos**. El CSS está en tres capas que se pisan.

**Color de B** (tokens de `r/b-piel.css` y del bloque B de `dark.css`):

| Papel | Claro | Oscuro |
|---|---|---|
| Página y superficie (la misma: sin tarjetas) | `#ffffff` | `#141216` / `#1c1920` |
| Superficie hundida | `#f4f2f6` (gris lila) | `#25212a` |
| Texto | `#1d1a20` | `#ece8f0` |
| Texto secundario | `#625c68` | `#a9a2b0` |
| Línea | `#e3dfe7` | `#332e39` |
| Enlace, menú activo y botón primario | `#5a2d73` morado | `#c9a6dd` **lila** |
| Logotipo y nombre | `#4a2560` | `#c9a6dd` |
| Acento | `#6f6874` plata (aros del logotipo) | `#9a93a1` |
| Etiqueta CCTT | morado con texto blanco | `#4b3460` |
| Etiqueta AM | `#1e6b4c` **verde** con texto blanco | `#2f5e4a` |
| Barra superior | blanca con línea de 1 px | `#1a1620` |
| Pie | `#f4f2f6` | — |

- El póster del vídeo es un bloque morado macizo (`#3a2548` en oscuro), y los botones de Escuchar llevan los mismos colores de marca que en A.
- B carga todas las hojas de A y pisa sus tokens, así que la deuda de A sigue debajo.

**Tipografía:**
- **A:** Archivo, en 400, 600 y 700.
- **B:** Public Sans, en 400, 600 y 700, con `letter-spacing: -.01em` en los títulos.
- **Escala común, seis tamaños:** 0,8125 / 0,9 / 1 / 1,2 / 1,6 / hasta 2,4 rem.
- **Texto secundario y metadatos:** 0,8125 rem en gris.
- **La frase descriptiva** bajo el título de las fichas va a 1,2 rem.

**Logotipo:** una «M» dentro de un aro, con «Marchas de Cristo» y «Base de datos de música procesional» al lado. En A va en oro y blanco sobre noche; en B, en morado y plata sobre blanco.

### 4.2 Marco (común a los dos salvo el color)

- **Cabecera en escritorio:** logotipo, buscador ancho con la pista de teclado «/» y botón de tema en una fila; debajo, las nueve secciones del menú. La sección activa se marca con un bloque de fondo: blanco al 10 % sobre noche en A, morado con texto blanco en B.
- **Cabecera en móvil:** una fila con logotipo, lupa, tema y menú hamburguesa.
- **Migas de pan** en gris: «Inicio / Marchas / 1969». El año forma parte de la ruta.
- **Pie en tres columnas:** secciones; «Datos», con la línea de cifras «5.518 marchas · 964 compositores · 360 bandas · 449 discos», la licencia y el RSS; y «Contacto». En A es noche; en B, gris lila.
- **Ancho:** un único ancho de 82 rem en todas las páginas.

### 4.3 Componentes

| Componente | A | B |
|---|---|---|
| Contenedor | Tarjeta blanca con borde de 1 px, radio de 4 px y sombra de 1–2 px sobre fondo gris | Ninguno: las secciones se separan con espacio |
| Título de sección | h2 en negrita con un **contador gris pequeño al lado** («52 · orden cronológico») | Igual |
| Etiqueta de estilo | Rectángulo macizo de color: «Cornetas y tambores» y «Agrupación musical» encima del título de la ficha; «CCTT» y «AM» en cada fila de los listados | Igual, en morado y verde |
| Controles de orden y paginado | Dos grupos segmentados: «orden · título · año · grabaciones» y «por página · 10 · 20 · 50» | Igual |
| Filtros del listado | Escondidos tras un botón «Filtros ▾» | Columna lateral fija con campos (título, dedicatoria, años, localidad, provincia), botón «Buscar» y **facetas con recuento** (tipo, estilo, provincia, década) |
| Tablas | Cabecera en superficie hundida, cebra suave, primera columna enlazada en color y negrita, cifras alineadas a la derecha, «—» para el vacío, flechas ▲▼ en cabeceras ordenables | Igual |
| Tablas en móvil | Filas de dos líneas: título y año arriba, el resto en gris debajo y separado por «·» | Igual |
| Escuchar | Segmentado «Versión original / Versión actual» y rejilla de botones de 44 px con logotipo y color de cada servicio. Si no hay grabaciones, una caja gris con «Todavía no tenemos ninguna grabación enlazada… proponla» | Igual, con la rejilla en una fila |
| Portadas | Mosaico de portadas reales junto al título de la ficha de marcha: 8 en dos filas en A, 4 en una fila en B. Rejilla de portadas en el listado de discos. «Funda tipográfica» gris con el título cuando no hay portada | Igual |
| Vídeo («Marcha del día») | Bloque de color macizo con botón de play, título y banda (no carga YouTube hasta que se pulsa) | Igual, en morado |
| Compartir | Icono junto a **cada** h1 | Igual |
| Pie de ficha | «Ficha M-30 · enlace permanente: marchasdecristo.com/…» | Igual |
| Botones | Primario macizo en el color de enlace; secundario con borde | Igual |

### 4.4 Página a página

**Portada:**
- **Estructura** (por encargo del mantenedor del 10-11, solo cambió de piel): h1 centrado «Bienvenido a Marchas de Cristo», párrafo centrado y **línea de cinco cifras**.
- **Fila de tarjetas:** «Marcha del día» (título, compositor, banda, año, etiqueta de estilo, dedicatoria, «Ver ficha» y vídeo) y «Explorar el catálogo», una lista de enlaces con sufijo gris («113 registros», «compositor», «banda de estreno»).
- **Debajo:** la tabla «Últimas incorporaciones».

**Listado de marchas:**
- **Cabecera:** el h1 es la cifra: «5.518 marchas».
- **Tabla:** columnas Marcha, Compositor, Año, Estilo (con etiqueta), Dedicatoria, Localidad, Redes («Sí»/«—») y Discos (recuento).
- **Filtros:** en B, la columna lateral de facetas con recuento.

**Ficha de marcha:**
- **Cabecera:** etiqueta de estilo, h1, compartir y una frase («Adaptación música popular para agrupación musical de X, 1969. Dura 3 min 59 s.»).
- **Bloques:** mosaico de portadas con «Discos donde está grabada (19)»; Escuchar; Notas (prosa); «Grabaciones 52 · orden cronológico» con un campo de filtro y una tabla de grabación, banda, año y duración, con la sub-etiqueta «perc.» bajo la duración.
- **Si no hay audio:** «Véase también · mismo compositor, dedicatoria o banda», con una fila de enlaces que llevan recuentos entre paréntesis («Obra de X (31) · Estrenos de Y (74) · Marchas de 2009 (209) · … (1.377)») y una tabla.
- **Colocación:** en A, columna estrecha a la izquierda (Escuchar, Notas) y ancha a la derecha (tablas); en B, columna principal y ficha técnica a la derecha.

**Ficha de compositor:**
- **Cabecera:** frase con cuatro cifras («Compositor de 189 marchas, entre 1997 y 2026. … estrenó 18 de sus marchas. Su obra suma 112 grabaciones.»).
- **Tabla «Obra · 189 marchas · por décadas»:** las filas de década llevan recuento («2000–2009 · 57») y la columna «Grab.» lleva cifra.

**Ficha de banda:**
- **Cabecera:** etiqueta de estilo, h1, compartir y frase («fundada en 1980. Ha estrenado 106 marchas.»).
- **Columna de datos:** Escuchar con seis servicios, y Datos (nombre completo, «Discos propios 15», «Acompañamientos 266», web).
- **Bloques de contenido:**
  - «Acompañamientos de 2026 · 6» (tabla);
  - «Marchas estrenadas · 106 · más recientes primero», que se despliega con «Ver las 106 marchas»;
  - «Discografía propia · 15», con «· 2024 · 10 pistas» en cada disco;
  - «Acompañamientos anteriores»: **una rejilla de 45 casillas con año y recuento.**

**Ficha de disco:**
- **Cabecera:** portada, h1 y frase («84 pistas en 4 volúmenes»).
- **Bloques:** Escuchar, con el estado vacío; «Contenido · 84 pistas», con filas de volumen «Volumen 1 · 26 pistas» y una tabla de pista, marcha, compositor y año.

**Listado de discos:** h1 «449 discos», el mismo par de segmentados, un conmutador «Portadas / Tabla» y una rejilla de portadas con título, banda y año debajo.

**Búsqueda:** campo con botón, «1 resultado para «amarguras»» y un grupo «Marchas (1)». La página queda casi vacía.

**Rankings:** tres tablas en columnas (autores, hermandades, bandas) con su recuento, y debajo «Marchas más grabadas». En A van en cajas plegables; en B, como títulos abiertos.

**Acompañamientos:** una frase que explica que «la cifra indica cuántos…», las capitales con su recuento y «Otras localidades» agrupadas por provincia, cada una con su recuento. Es una rejilla de unos 80 pares nombre–número.

### 4.5 Tema oscuro y móvil

- **Oscuro A:** azul noche casi negro. Los enlaces pasan de grana a oro, y la etiqueta CCTT sigue en grana, de modo que en una misma fila conviven oro, grana y el azul.
- **Oscuro B:** carbón con un tinte violeta. Enlaces en lila; las etiquetas pasan a morado y verde apagados.
- **Móvil (los dos):** una sola columna. Debajo de la frase de la ficha hay un ancla «Grabaciones». Las tablas pasan a filas de dos líneas y las etiquetas se mantienen. En A, cada bloque sigue siendo una tarjeta.

## 5. Patrones genéricos detectados

### 5.1 Color

- **Cada estilo musical tiene un código de color propio** (CCTT y AM), y es lo más saturado de las tablas: compite con los enlaces. En A, esos códigos son además los colores de marca (grana y oro), así que el mismo color significa «enlace», «CCTT» y «marca» a la vez. En B, el verde AM es un tono que no aparece en ningún otro sitio.
- **El color del enlace cambia de tono entre temas:** grana a oro en A, morado a lila en B.
- **Hay colores de terceros** en cada ficha: los de los servicios de streaming y el multicolor de las portadas.
- **El color de marca solo tiene un papel de relleno:** barra, pie y póster del vídeo en A; menú activo y póster en B. No hay un sistema que diga dónde va el color y dónde no.
- **En el CSS:** 55 colores hexadecimales distintos y familias semánticas heredadas que siguen cargándose.

### 5.2 Etiquetas

- Una etiqueta de estilo encima de cada título de ficha, aunque la frase de debajo ya lo dice («Marcha de agrupación musical de…»).
- «CCTT» o «AM» en **cada fila** de los listados: 20 etiquetas por pantalla.
- Una etiqueta de estilo dentro de la tarjeta «Marcha del día».
- Elementos con aspecto de etiqueta que no lo son: los segmentados de orden y paginado, la pista «/» del buscador, la sub-etiqueta «perc.» y el «Sí» de la columna Redes.

### 5.3 Subtítulos y metadatos numéricos

Patrón repetido: **h2 + recuento gris pequeño + criterio de orden**. Más un recuento entre paréntesis o como sufijo en casi cada enlace o grupo.

| Página | Cifras de metadatos visibles |
|---|---|
| Todas | Pie «5.518 marchas · 964 compositores · 360 bandas · 449 discos» |
| Portada | Línea de cinco cifras; «113 registros», «3.504 registros», «2.014 registros»… en cada enlace de Explorar |
| Listado de marchas | h1 «5.518 marchas»; columna Discos; en B, unos 30 recuentos de faceta |
| Ficha de marcha | «Grabaciones 52 · orden cronológico», «Discos donde está grabada (19)», «Dura 3 min 59 s.», duración + «perc.» por fila; sin audio, «(31) · (74) · (209) · (1.377)» en Véase también; «Ficha M-30» |
| Compositor | Cuatro cifras en la frase; «Obra 189 marchas · por décadas»; recuento en cada fila de década; columna «Grab.» |
| Banda | «Ha estrenado 106», «Acompañamientos de 2026 · 6», «106 · más recientes primero», «Ver las 106», «Discografía propia · 15», «· 10 pistas» por disco, «Discos propios 15», «Acompañamientos 266», rejilla de 45 años con su recuento |
| Disco | «84 pistas en 4 volúmenes», «Contenido · 84 pistas», «Volumen 1 · 26 pistas» |
| Listado de discos | h1 «449 discos» |
| Búsqueda | «1 resultado», «Marchas (1)» |
| Acompañamientos | Unos 80 pares localidad–recuento, más una frase que explica qué significa la cifra |

Lectura: el sitio describe su propio volumen en cada nivel de la página (en el título, en el subtítulo, en la frase, en el enlace y en la fila), en lugar de dejar que el contenido hable.

### 5.4 Otros patrones de la misma familia

- **Portada:** el h1 centrado de bienvenida, con un párrafo centrado y una fila de estadísticas, es la plantilla de landing más repetida.
- **Tarjetas en A:** todo bloque es una caja, la página es un «dashboard».
- **Icono de compartir junto a cada h1:** decoración de sistema, no contenido.
- **Botones con logotipos de marcas:** el patrón de las insignias de tienda de aplicaciones.
- **Póster del vídeo:** un bloque de color macizo con play centrado, el típico relleno de vídeo.
- **Tipografía:** las dos son sans-serif «neutras» de catálogo (Archivo, Public Sans), sin contraste ni voz. Hay una sola familia y la jerarquía depende solo del tamaño y de la negrita.
- **Explicaciones de la propia interfaz:** frases que explican cómo leerla («la cifra indica cuántos…», «mismo compositor, dedicatoria o banda»).

## 6. Lo que no ha recibido quejas

Esto no es una recomendación: es material para contrastar con las referencias.

- La tabla como forma principal, con cebra suave y la primera columna enlazada.
- Las filas de dos líneas en móvil.
- La frase descriptiva bajo el título de la ficha («Marcha de agrupación musical de X, 2009. Estrenada por Y. Dedicada a Z.») en lugar de una tabla de clave–valor.
- La rejilla de portadas en el listado de discos y la portada junto al título en la ficha de disco.
- La agrupación por décadas en la obra del compositor.
- El campo de filtro encima de las tablas largas.
- Que no se cargue nada de terceros hasta que el visitante lo pide.
- El ancho completo sin huecos a la derecha: B lo consigue mejor que A en las fichas.

## 7. Preguntas que la siguiente sesión tiene que contestar con referencias

1. **Paleta:** ¿cuántos tonos tienen los sitios de referencia (uno más neutros, o dos)? ¿Qué papel tiene cada uno? ¿Cambia el tono del enlace entre claro y oscuro?
2. **Estilo musical:** ¿cómo distinguen las referencias una categoría así (CCTT/AM) sin etiquetas de color: texto, columna, icono, nada? ¿Hace falta en cada fila?
3. **Recuentos:** ¿dónde los muestran las referencias de bases de datos (Discogs, MusicBrainz, Wikipedia…), y dónde los omiten? ¿Recuento en el título de la sección, en el enlace o en ningún sitio?
4. **Tipografía:** ¿qué hace que un catálogo de conocimiento tenga voz propia (serif editorial, combinación de dos familias, versalitas…), sin caer en lo prohibido del §1?
5. **Identidad sin fotos:** ¿cómo dan carácter las referencias sin imágenes propias: tipografía, portadas de disco, ornamento, retícula?
6. **Portada:** ¿qué alternativas hay al «hero centrado con cifras» en sitios de consulta?
7. **Escuchar:** ¿cómo presentan los enlaces a servicios externos sin convertir la ficha en un escaparate de logotipos?
8. **Metadatos de sistema** (compartir, enlace permanente, n.º de ficha): ¿se muestran o se esconden?

## 8. Anexo: ubicación del material

- Repositorio: `~/Developer/marchasdecristo` (rama `rediseno/a-uniforme`); B en el worktree `.claude/worktrees/b-nazareno`.
- Decisiones del rediseño: `docs/rediseno-2026-11.md`; identidad A: `docs/identidad-visual.md`; auditoría con Fable: `docs/auditoria-2026-11-10.md`.
- CSS: `php/public/assets/app.css` (heredado), `identidad.css`, `dark.css`, `r/{base,marco,controles,tablas,media,fichas}.css`; en B además `r/b-piel.css` y `r/b-composicion.css`.
- Capturas: `~/Desktop/capturas-rediseno.zip` (132 JPG; actual/A/B × 11 rutas × PC/móvil × claro/oscuro, con `manifest.json`).
