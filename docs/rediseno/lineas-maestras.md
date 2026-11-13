# Líneas maestras del rediseño de marchasdecristo.com

> Fecha: 2026-11-13 · Entrada: `clasificacion-disenos-a-b.md` · Sin código ni maquetas.
> Cada regla cita la referencia observada. Lo que no pude observar está marcado como **sin verificar**.

## 0. Qué se ha mirado y qué no

Visitadas con Claude in Chrome, en escritorio (ventana de 1568 px), una ficha y un listado o búsqueda de cada una:

| Clave | Web | Ficha | Listado |
|---|---|---|---|
| DG | Discogs | `/release/1` | búsqueda «amarguras» |
| MB | MusicBrainz | artista (Mozart) | búsqueda de obras «amarguras» |
| IM | IMSLP | Sinfonía n.º 5 de Beethoven | categoría de Joaquín Turina |
| WP | Wikipedia (es) | Manuel Font de Anta | categoría «Música de la Semana Santa en Sevilla» |
| PM | patrimoniomusical.com | `bd-marcha-5620` | `bd-marchas` (y la portada) |
| RM | Rijksmuseum | un óleo de Vanmour | búsqueda «procession» |
| SE | Standard Ebooks (identidad tipográfica) | *Trafalgar* | obras de Galdós |
| AM | AllMusic | *Kind of Blue* | búsqueda «amarguras» |

Límites que afectan a las conclusiones:

- **datos.bne.es y Cooper Hewitt no se pudieron leer**: la extensión denegó el permiso en esos dominios. No reintenté. Quedan 8 webs, no 10.
- **Solo vi una portada: la de PM.** Del resto vi fichas y listados. Al preguntarte por la portada dije que DG, MB, IM y WP «abren con buscador y contenido»: eso no lo comprobé. El principio 10 es el peor respaldado.
- **No comparé tema claro y oscuro** en ninguna web, ni vi ninguna en **móvil**. La pregunta sobre el tono del enlace entre temas queda sin respuesta observada.
- **Licencias tipográficas**: las doy de memoria (SIL OFL). Hay que confirmarlas en el repositorio de cada fuente antes de alojarlas.

## 1. Lo observado, por queja

| | Tonos de color y papel | Etiquetas | Recuentos | Tipografía | Identidad sin fotos propias |
|---|---|---|---|---|---|
| DG | Un azul de enlace; negro y blanco. Verde solo en el botón de compra | Ninguna de color. Género y estilo: enlaces de texto en la lista clave–valor. Tipo de resultado en rótulo de mayúsculas | Pestañas «Edición (261)», facetas, «1–25 de 292». En títulos de sección solo «Other Versions (1)» | Helvetica Neue única; h1 de 20 px | Portadas de disco |
| MB | Azul de enlace; magenta y naranja de marca en menú, títulos de sección y pestaña activa | Ninguna. Géneros: enlaces separados por comas en la columna lateral | «Found 3 results» una vez; columna «Releases». Ni pestañas ni títulos llevan cifra | Sans del sistema única; h1 de 18 px | Casi ninguna: el color de marca en los títulos |
| IM | Azul marino de enlace sobre neutros cálidos; una banda granate en la cabecera de compositor | Ninguna. «Categorías de estilo»: enlaces separados por punto y coma, con «[50 more…]» | Solo en pestañas: «Partes (85)» | Lucida Grande única; h1 de 24 px sin negrita | Miniatura de la primera página de cada partitura |
| WP | Un azul de enlace; rojo solo para el enlace roto | Ninguna. Categorías: enlaces de texto al pie | Una frase en la categoría («contiene las siguientes 10 páginas»). Ninguno en títulos | Serif en h1 y h2, sans en el texto | El par serif–sans y la estructura |
| PM | Azul marino, crema, azul claro y rosa: cuatro tonos | **Etiqueta rosa «Semana Santa» en cada fila** | Ninguno | Open Sans Condensed + PT Sans | Partitura manuscrita y retrato en la cabecera |
| RM | Superficie oscura neutra, texto blanco; un naranja solo en «tickets» | Ninguna | Pestañas «Artworks (1751)» | Familia propia única; rótulos en mayúsculas | La obra a toda pantalla y la tipografía propia |
| SE | Neutro oscuro; un verde solo en botones de acción | Una palabra en texto («fiction»); cinta «Wanted» en huecos | «55,998 words» dentro de una frase | Crimson Pro (texto, cursivas) + League Spartan (títulos) | Par tipográfico y cubiertas |
| AM | Dos azules de enlace; naranja en botones | Ninguna. «Styles»: enlaces separados por comas | Filtros «Songs (294)», «314 search results» | Inter única; h1 de 18 px | Portada del disco |

Dos lecturas de conjunto:

- La única referencia que usa etiqueta de color por fila es PM, la del mismo dominio. No es un patrón de bases de datos: es un hábito del nicho.
- Ninguna ficha observada pasa de 29 px en el título (SE aparte, 44 px). A y B llegan a 2,4 rem.

## 2. Principios

| # | Principio | Regla concreta | Referencia | Queja que resuelve |
|---|---|---|---|---|
| 1 | Un tono, un significado | Un solo tono más neutros. El tono significa «se puede pulsar» (enlace, botón primario, foco) y nada más. Barra, pie y póster del vídeo pasan a neutro | DG, WP, IM: un tono de enlace. RM, SE: un acento solo en la acción | §2.1 colores sin paleta |
| 2 | La categoría se escribe | El estilo (cornetas y tambores, agrupación musical) es texto del color del texto, o enlace al listado filtrado. Ningún rectángulo de color, en ninguna página | DG, AM, IM, MB lo dan como enlace de texto. Contraejemplo: PM | §2.2 etiquetas; §5.1 código de color por estilo |
| 3 | La cifra vive donde ayuda a elegir | Recuento solo en: total de resultados (una vez), facetas del filtro, pestañas y columnas que son dato. Nunca en h1, h2, enlaces sueltos ni pie | DG, AM (facetas, total); IM, RM (pestañas); MB (columna «Releases», secciones sin cifra) | §2.3 subtítulos y cifras |
| 4 | El título es un nombre y no grita | El h1 es siempre un nombre («Marchas», «Amarguras»), nunca una cifra. Tamaño máximo entre 1,6 y 1,8 rem. La jerarquía la da el cambio de familia, no el tamaño | h1 de 18 a 29 px en DG, MB, IM, WP, AM, RM. WP: serif en títulos, sans en texto | §5.3 «5.518 marchas» como h1; §5.4 tipografía sin voz |
| 5 | Página plana | Sin tarjetas ni sombras. Las secciones se separan con espacio y una línea neutra de 1 px. Cabecera del sitio en el color de la página | DG, MB, WP, IM: contenido sobre fondo continuo | §5.4 tarjetas, «dashboard» |
| 6 | Frase arriba, datos al lado, sistema al pie | Ficha: nombre, frase descriptiva a tamaño de texto, contenido. Ficha técnica clave–valor en columna lateral. Identificador y enlace permanente en una línea al final. Sin icono de compartir | WP (entradilla, identificadores al pie); MB (columna de datos); RM («Persistent URL» al final de «Data») | §3.3 meta repetida; §5.4 compartir |
| 7 | Lo ajeno entra como texto | Escuchar es una lista de nombres de servicio en el color de enlace, con icono monocromo pequeño como mucho. Sin colores ni botones de marca. El vídeo es una fila con título y duración | MB (lista de enlaces externos con icono de 16 px); SE («Read free» tras la descripción, en texto); DG (vídeos como lista). Contraejemplo: AM, insignias de marca dos veces por ficha | §5.1 colores de terceros; §5.4 insignias |
| 8 | Controles de tabla, no barra de herramientas | Se ordena pulsando la cabecera de la columna. Tamaño de página y vista, en un desplegable o enlaces de texto. Paginación numerada. Sin segmentados | MB (cabeceras, desplegable «Results per page», paginación numerada); DG (un desplegable «Ordenar») | §3.3 segmentados; §5.2 elementos con aspecto de etiqueta |
| 9 | Identidad por tipografía, portadas y orden | Carácter con: serif en los nombres, portadas de disco como único multicolor, y agrupación por letra o década en los índices. Sin ornamento ni imagen de cabecera | WP (par tipográfico); SE, DG (cubiertas); IM, WP (índices alfabéticos por letra) | §2 «genérico»; pregunta 5 |
| 10 | La portada es una página de consulta | Buscador, «Últimas incorporaciones» en tabla, la marcha del día como una entrada más e índice de secciones. Sin saludo, sin párrafo centrado, sin cifras. La interfaz no se explica a sí misma | PM («Últimas actualizaciones» fechadas en portada); listados de DG, MB, AM, que abren con el campo de búsqueda. **Respaldo débil**, ver §0 | §5.4 hero centrado; explicaciones de interfaz |

## 3. Paleta: papeles y número de tonos

**Un tono y cinco neutros. Tono por decidir en la fase de hex**, como pediste.

| Papel | Qué pinta | Tonos |
|---|---|---|
| Página | Fondo de todo, cabecera y pie incluidos | Neutro 1 |
| Superficie hundida | Cabecera de tabla, cebra, campo de formulario, funda de disco sin portada | Neutro 2 |
| Línea | Separadores de 1 px y bordes de campo | Neutro 3 |
| Texto secundario | Metadatos, migas, columnas de apoyo | Neutro 4 |
| Texto | Todo lo demás, incluido el estilo musical y la sección activa del menú (en negrita) | Neutro 5 |
| Interactivo | Enlaces, botón primario, anillo de foco | **El tono** |

Reglas:

- El tono no rellena superficies, salvo el botón primario. Menú activo: negrita en color de texto, como la pestaña activa de DG.
- El único multicolor de la página son las portadas de disco (DG, SE).
- Neutros con la misma temperatura que el tono; ningún gris azulado si el tono es cálido. IM lo hace: marino sobre neutros cálidos coherentes entre sí.
- Excepción funcional: un color de error en formularios, como el rojo del enlace roto de WP. No cuenta como tono de marca.
- **Tema oscuro, sin verificar**: propongo el mismo tono aclarado, no otro tono. Lo apoya solo la lógica del principio 1 (si el enlace cambia de tono, el tono deja de significar una cosa). Hay que contrastarlo con WP y SE en sus dos temas.
- Criterios para elegir el tono: contraste AA como texto sobre Neutro 1 y Neutro 2 en los dos temas, y que no coincida con ningún color con significado propio en el dominio que obligue a explicarlo.

## 4. Tipografía

**Una familia autoalojada (serif) y la sans del sistema.**

- **Nombres y prosa: Crimson Pro** (variable, con cursiva; SIL OFL, por confirmar). Para h1, h2, la frase descriptiva y las Notas. Es la serif de texto de SE, donde la vi funcionar en títulos, en párrafo y en metadatos pequeños sobre fondo oscuro. El reparto serif para nombres y sans para datos es el de WP.
- **Tablas, formularios y menú: pila sans del sistema**, sin fuente web. Es lo que hacen DG, MB, IM y WP. Quita de en medio la «sans neutra de catálogo» (Archivo, Public Sans), ahorra una descarga y deja la voz a la serif. Cifras tabulares activadas en las columnas numéricas.
- Alternativa si Crimson Pro queda fina en títulos largos con tildes: **Libertinus Serif** (OFL, heredera de la Linux Libertine de los títulos de WP). No es variable.
- Si la sans del sistema se ve demasiado distinta entre Windows, macOS y Android: **Source Sans 3** (OFL) como segunda familia autoalojada. No la pongo de entrada.
- Títulos en redonda (regla tuya: sin cursivas de titular). Cursiva solo dentro de la prosa, para títulos de obra.
- Sin versalitas: no las vi en ninguna referencia; RM y SE usan mayúsculas, que tus reglas excluyen.
- Escala: cuatro tamaños en lugar de seis. El texto secundario baja de color, no de tamaño, salvo en la segunda línea de las filas en móvil.

## 5. Políticas

### Etiquetas

| Dónde | Regla | Referencia |
|---|---|---|
| Sobre el h1 de la ficha | No. Lo dice la frase | WP, SE |
| Ficha técnica | «Estilo: Agrupación musical», valor enlazado al listado filtrado | DG, AM |
| Filas de listado | Columna «Estilo» en texto, palabra completa. Se oculta si el listado ya está filtrado por estilo. En móvil va en la segunda línea | MB (columna «Type» en texto) |
| Marcha del día | No | — |
| «perc.», «Sí» de Redes, pista «/» | «perc.» pasa a texto de la celda. Redes: «Sí» y «—» en color de texto. La pista «/» se quita o queda como texto del campo | MB (celdas de texto plano) |

### Recuentos

| Sí | No |
|---|---|
| Total de resultados, una vez, junto a la paginación («1–20 de 5.518») (DG, MB) | h1 con cifra |
| Facetas del filtro, con los grupos plegados salvo el primero (DG) | h2 con «52 · orden cronológico» (MB, WP: secciones sin cifra) |
| Pestañas, si las hay (IM, RM, DG) | Paréntesis en enlaces de «Véase también» y sufijos «113 registros» |
| Columnas que son dato: Discos, Grab., Año, Duración (MB) | Pie del sitio y portada |
| «Ver las 106 marchas»: el número informa de lo que se despliega | «· 10 pistas» por disco, «Volumen 1 · 26 pistas», recuento por década |
| | Rejilla de 45 años y los 80 pares localidad–cifra: quedan como índices sin cifra (IM, WP) |

La frase de la ficha puede llevar las cifras que son biografía («entre 1997 y 2026», «fundada en 1980»), no las que son inventario («su obra suma 112 grabaciones»).

### Metadatos del sistema

| Elemento | Regla | Referencia |
|---|---|---|
| Compartir | Se elimina. El enlace permanente y el navegador ya lo cubren | Ninguna ficha observada lo lleva junto al título |
| N.º de ficha y enlace permanente | Una línea al final de la ficha, en texto secundario | RM, WP |
| Migas | Se mantienen, sin el año como eslabón | — (no observado; decisión propia) |
| Licencia, RSS, contacto | Pie, en una línea de enlaces | WP, MB |

## 6. Qué se conserva y qué se elimina

**Punto de partida:** B está construido sobre A y arrastra su CSS (55 colores, tres capas). Una tercera piel repetiría el problema: las quejas están en el marcado. Hojas nuevas y plantillas revisadas; de B se toma la composición, no el código.

| Página | Se conserva | Se elimina o cambia |
|---|---|---|
| Marco | Buscador ancho, modo claro y oscuro, migas, ancho completo | Barra y pie de color (A); bloque de fondo en menú activo; cifras del pie; lema junto al logotipo si repite lo evidente |
| Portada | Tabla «Últimas incorporaciones»; marcha del día sin cargar nada de terceros | h1 de bienvenida, párrafo centrado, línea de cinco cifras, tarjetas, sufijos «113 registros», etiqueta de estilo, póster de color macizo |
| Listado de marchas | Tabla con cebra y primera columna enlazada; filas de dos líneas en móvil; columna lateral de filtros de B | h1 «5.518 marchas» pasa a «Marchas»; etiquetas CCTT/AM pasan a texto; los dos segmentados; facetas todas abiertas |
| Ficha de marcha | Frase descriptiva; Escuchar después de la información; columna principal más ficha técnica a la derecha (B); campo de filtro sobre la tabla larga; mosaico de portadas | Etiqueta sobre el h1; compartir; frase a 1,2 rem; «(19)» y «52 · orden cronológico»; botones de marca; caja gris del estado vacío (pasa a una frase); paréntesis de «Véase también» y su subtítulo explicativo |
| Compositor | Agrupación por décadas; columna «Grab.» | Las cifras de inventario de la frase; «Obra · 189 marchas · por décadas»; recuento en cada fila de década |
| Banda | Frase; tablas de acompañamientos y estrenos; «Ver las 106 marchas» | Etiqueta; «· 6», «· 15», «· 10 pistas»; «Discos propios 15» y «Acompañamientos 266» en Datos; cifras de la rejilla de años |
| Disco | Portada junto al título; tabla de pistas | «84 pistas» repetido en frase y título (queda una vez, en la frase); «· 26 pistas» por volumen |
| Listado de discos | Rejilla de portadas con título, banda y año; funda tipográfica sin portada | h1 «449 discos»; segmentados; el conmutador pasa a dos enlaces de texto |
| Búsqueda | «1 resultado para «amarguras»» | «Marchas (1)» cuando hay un solo grupo; con un único resultado, ir a la ficha |
| Rankings | Las tablas con su cifra: aquí el recuento es el contenido. Títulos abiertos (B) | Cajas plegables (A) |
| Acompañamientos | Agrupación por provincia | Las 80 cifras y la frase que las explica |

## 7. Respuestas a las ocho preguntas del §7

1. **Paleta.** Un tono más neutros en DG, WP, IM, RM y SE. Su papel es marcar lo interactivo, o solo la acción principal. MB y AM usan dos o tres, y PM cuatro. Si el enlace cambia de tono entre temas: sin verificar.
2. **Estilo musical.** Texto: enlace en la lista clave–valor (DG, AM, IM) o columna de texto en la tabla (MB). Ninguna usa icono. Por fila: MB lo muestra como columna; DG lo deja a las facetas. Columna de texto, y fuera cuando sobra.
3. **Recuentos.** Los muestran en facetas, pestañas, total de resultados y columnas. Los omiten en títulos de sección y en enlaces. Única excepción vista: «Other Versions (1)» en DG.
4. **Tipografía.** Lo que da voz en lo observado es el contraste entre dos familias con papeles fijos (WP, SE) o una familia propia (RM), no el tamaño. Las bases de datos puras (DG, MB, IM, AM) no tienen voz tipográfica: su identidad va por otro lado.
5. **Identidad sin fotos.** Cubiertas (DG, SE, AM), miniaturas del propio documento (IM), par tipográfico (WP, SE) e índices por letra (IM, WP). PM usa una partitura manuscrita: es la única idea de imagen propia del dominio, pero depende de derechos y choca con tu regla de no decorar; no la propongo.
6. **Portada.** Solo vi la de PM: imagen, lista fechada de novedades y accesos a secciones, sin cifras. El resto, sin verificar.
7. **Escuchar.** MB: lista de texto con icono pequeño y un único botón neutro de reproducción. SE: sección de texto tras la descripción. AM hace lo contrario y es justo el escaparate que criticas.
8. **Metadatos de sistema.** Compartir no aparece junto al título en ninguna. El identificador y el enlace permanente van al final o en un apartado de datos (RM, WP, MB).

## 8. Riesgos de recaer en lo genérico y cómo comprobarlo

| Riesgo | Comprobación |
|---|---|
| Que el tono se use otra vez de relleno | Captura en escala de grises: la página debe leerse igual. Contar superficies pintadas con el tono: solo el botón primario |
| Que vuelvan las etiquetas con otro nombre | Buscar en el CSS reglas con fondo de color y texto corto en línea. Contar elementos con fondo propio por pantalla de listado: cero |
| Que las cifras se cuelen una a una | Repetir la tabla del §5.3 del documento de entrada sobre el diseño nuevo, página por página, y comparar el número de cifras de metadatos |
| Deuda de color | Contar hexadecimales distintos en el CSS final: como mucho 12 (seis papeles por dos temas), más el color de error |
| Serif «editorial» de plantilla (crema, cursivas, titulares enormes) | Fondo neutro, títulos en redonda y h1 por debajo de 1,8 rem. Probar con títulos reales largos y con tildes |
| Sans del sistema desigual | Capturas en Windows, macOS y Android de un listado y una ficha antes de dar por buena la decisión |
| Validar con el gusto de un modelo | No puntuar con un modelo. Poner cada página junto a su homóloga de MB, WP o DG y marcar todo lo que el diseño nuevo tiene y la referencia no: cada diferencia necesita un motivo |
| Prueba del logotipo | Tapar el logotipo: si la página podría ser de cualquier producto, falta el principio 9 |
| Respaldo débil | Antes de la fase de hex, ver las portadas de MB, IM y DG, el par claro–oscuro de WP y SE, y una ficha de cada una en móvil. Reintentar datos.bne.es y Cooper Hewitt si das permiso a la extensión |
| Revisión tardía | Enseñarte tres páginas (listado de marchas, ficha de marcha, ficha de banda) en claro, antes de extender nada al resto |