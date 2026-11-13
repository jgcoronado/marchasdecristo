# Rediseño C — plan de ejecución con agentes

> Estado vivo del rediseño. El orquestador lo lee al empezar cada sesión y lo actualiza al terminarla. Nada de estado en `/tmp` ni en el scratchpad.
> Especificación: `docs/rediseno/lineas-maestras.md` (manda) y `docs/rediseno/clasificacion-disenos-a-b.md` §1 (reglas fijas).
> A y B quedan descartados como código. De A se aprovecha el marcado de las plantillas; de B, solo la composición (ficha técnica a la derecha, columna de filtros).

## Decisiones de arquitectura

1. **Rama:** `rediseno/c`, creada desde `origin/pre`. De `rediseno/a-uniforme` se traen las plantillas y los helpers PHP, pero ningún CSS ni ninguna fuente.
2. **CSS nuevo desde cero** en `php/public/assets/c/`, en hojas por área. Las páginas públicas cargan **solo** `c/*.css`. El panel `/admin` sigue cargando `app.css` hasta una fase posterior: se decide en `layout.php` según la ruta. `identidad.css`, `r/*.css`, Archivo y Public Sans no se despliegan.
3. **Capa de componentes PHP con props tipadas** para las piezas repetidas: cabecera de ficha, ficha técnica, tabla, escuchar, paginación, filtros, migas y pie de ficha. Escapan la salida por defecto. Las reglas se aplican en el código: no existe ningún componente de etiqueta de color, y los recuentos solo los admiten la tabla, las facetas y el total de la paginación.
4. **Un worktree por paquete**, creado por el orquestador desde `rediseno/c`. La opción `isolation: worktree` de los agentes no se usa porque crea los worktrees desde `main`. Cada agente recibe la ruta de su worktree y un puerto propio, y mata sus servidores por PID al terminar.
5. **Nadie puntúa el diseño con un modelo.** La revisión compara cada página con su homóloga de MusicBrainz, Wikipedia o Discogs y lista las diferencias que necesitan motivo (líneas maestras §8).
6. **El checkout principal se queda en `pre`** para el trabajo diario: el rediseño vive solo en los worktrees.

## Agentes (`.claude/agents/`)

| Agente | Modelo · esfuerzo | Papel |
|---|---|---|
| Orquestador (sesión principal) | Opus 5.5 · medium | Lee este plan, crea los worktrees, reparte, fusiona y actualiza el estado. No escribe código de producto |
| `integrador` | Sonnet 5.5 · low | Ramas, merges, conflictos triviales, commits y limpieza de worktrees |
| `disenador-tokens` | Opus 5.5 · medium | Cierra los huecos sin verificar de las líneas maestras, propone tres tonos y escribe tokens y fuentes |
| `nucleo-componentes` | Opus 5.5 · medium | Capa de componentes y `layout.php` |
| `maquetador` | Opus 5.5 · low | Un paquete de páginas por invocación, hasta 3 en paralelo |
| `verificador` | Sonnet 5.5 · low · solo lectura | Smoke, `php -l`, PHPStan, capturas y auditor de reglas |
| `revisor-referencias` | Opus 5.5 · high · solo lectura | Comparación con la página homóloga de MB, WP o DG |
| `revisor-seguridad` | Opus 5.5 · high · solo lectura | Diff del núcleo y diff final contra `pre` |

## Fases

Cada fase va en una sesión nueva de Claude Code. Antes de empezar: `/clear`, `/model` Opus 5.5 medium.

- [x] **F0 · Preparación** (`integrador`)
  - Crear `rediseno/c` desde `origin/pre`.
  - Traer de `rediseno/a-uniforme` solo `php/app/templates/`, `php/app/src/{Html,Repo,Pages,View}.php` y los cambios de `php/tools/ci_smoke.php`.
  - Pasar smoke y `php -l`.
  - Dejar el checkout principal en `pre`.
- [x] **F1 · Tono y tipografía** (`disenador-tokens`)
  - Comprobar el tema claro y oscuro de Wikipedia y Standard Ebooks, y las licencias de Crimson Pro y Libertinus en sus repositorios.
  - Proponer tres tonos con sus cinco neutros, en claro y oscuro, con contraste AA medido por script.
  - Generar el listado de marchas real con cada candidato.
  - → **Puerta 1 (tú):** elegir el tono en el artefacto comparador.
  - Después: escribir `c/tokens.css` y alojar las fuentes.
  - **Cambio de alcance (2026-11-13):** F1 también deja las páginas públicas renderizables con un CSS nuevo mínimo, porque sin eso los candidatos de tono no se pueden juzgar. `layout.php` carga `c/tokens.css` + `c/base.css` en las rutas públicas (`/admin` sigue con `app.css`) y deja de precargar `archivo-var.woff2` y de enlazar `identidad.css` (sin ningún 404). `c/base.css` es mínimo y solo usa tokens: Crimson Pro en h1, h2 y la frase de la ficha; sans del sistema en el resto; cuatro tamaños de letra; enlaces sin subrayado; tabla `.t` con cebra y primera columna enlazada; cabecera y pie planos en el color de la página. Sin componentes ni estilos por página: los componentes siguen siendo de F2. Los candidatos `c/tokens-{1,2,3}.css` son intercambiables con `c/tokens.css` sin tocar `base.css`.
  - **Cerrada (2026-11-13):** tono Petróleo (candidato 2) en `c/tokens.css`; candidatos descartados borrados. Crimson Pro redonda y cursiva (latin + latin-ext, OFL) alojadas en `php/public/assets/fonts/`; la cursiva aún no la descarga ninguna página (llegará con las Notas). Capturas finales en `.claude/qa/caps/f1-final/`.
- [x] **F2 · Núcleo** (`nucleo-componentes`, luego `revisor-seguridad` sobre su diff)
  - **Cerrada (2026-11-13).** Componentes en `php/app/src/C/` (espacio `App\C`, autoload sin cambios), con estilos en `c/componentes.css` (solo tokens):
    - base: `Componente` (render + reglas en el docblock), `Texto` (único HTML admitido, ya escapado), `Seguro` (`e()` y `href()` con lista blanca de esquemas; rechaza `//`, `\` y caracteres de control);
    - `CabeceraFicha` (solo h1; sin frase, etiqueta ni compartir);
    - `FichaTecnica` + `Dato` (tabla de datos clave–valor bajo el h1, a todo el ancho de la columna principal; valor enlazable);
    - `Tabla` + `Columna` (contrato `.t`, `data-l` para móvil, orden desde 3 filas, filtro desde 8);
    - `Escuchar` (lista de texto con icono monocromo, estado vacío en una frase, servicio deducido por host exacto);
    - `Paginacion` (total «1–20 de 5.518» y números; solo rutas propias);
    - `Filtros` + `Campo` + `Faceta` + `GrupoFacetas`;
    - `Migas` (rechaza eslabones de año);
    - `PieFicha` (identificador y enlace permanente).
    - Recuentos solo en `Tabla`, `Filtros` y `Paginacion` (marcados con `data-cifras`). No hay componente de etiqueta.
  - Ficha de marcha migrada como prueba (`marcha_detail.php`). Cabecera y pie públicos rehechos en `layout.php`: planos, sección activa en negrita y color de texto, pie sin cifras ni compartir. `ci_smoke.php` exige ahora que no haya botón de compartir.
  - Auditor `.claude/qa/auditor.mjs` (no versionado). Contra `pre` detecta etiquetas (botones de streaming, segmentados) y recuentos (h1 «5.518 registros», «Grabaciones (52)», fila de cifras de portada), y 27+1 colores. Contra la ficha migrada (/marcha/la-saeta-1584 y /marcha/una-saeta-al-cielo-2625, claro, 1440/390) todo pasa: 6+1 colores por tema, 0 etiquetas, 0 recuentos, h1 1,75 rem sin dígitos, 0 subrayados, 0 scroll horizontal, 0 errores de consola. Capturas en `.claude/qa/caps/f2-nucleo/`.
  - Revisión de seguridad: 3 hallazgos corregidos en f7ee2bd y verificados en una segunda pasada (ruta de `Paginacion` sin filtrar, `/\host` en `href()`, servicio de AUDIO deducido sin anclar el host).
  - Abierto para F3/F4:
    - `$` → `\z` en la regex de `Paginacion`;
    - `Escuchar` pinta un div vacío si `href()` rechaza todos los enlaces;
    - `Html::escuchar` y `Media::embedDeUrl` antiguos siguen sin anclar el host;
    - código de compartir muerto en `catalog.js`;
    - lema de la cabecera por decidir (§6);
    - hamburguesa en segunda fila a 390 px;
    - orden por Duración con « perc.»;
    - 25 marchas con AUDIO sin esquema, que no se muestran.
    - PHPStan: `quality.sh` sigue en rojo por 181 errores y desajustes de baseline que ya traía la base (la rama baja a 179; baseline intacta).
- [ ] **F3 · Piloto en claro** (3 × `maquetador` → `verificador` → `revisor-referencias`)
  - Páginas: listado de marchas, ficha de marcha y ficha de banda.
  - → **Puerta 2 (tú):** aprobar las tres páginas en el artefacto antes de extender nada.
- [ ] **F4 · Extensión** por lotes de hasta 3 paquetes, cada lote verificado y revisado
  - Páginas restantes: portada, compositor, disco, listado de discos, búsqueda, rankings, acompañamientos, dedicatorias, aniversarios, estado del catálogo y páginas de error.
  - Luego tema oscuro y móvil.
- [ ] **F5 · Cierre**
  - `revisor-seguridad` sobre el diff completo contra `pre`.
  - → **Puerta 3 (tú)**.
  - Despliegue a PRE y después a PRO por el orquestador. Antes de subir, comprobar la cuota de HelioHost (estaba al 97 %).

## Decisiones tomadas (no reabrir)

- Escuchar va después de la información. Los enlaces no se subrayan nunca.
- Migas sin el año: el año ya está enlazado en la tabla de datos de la ficha y mantiene el enlace interno al hub del año.
- Fichas (requisito fijo del mantenedor, 2026-11-13): toda ficha abre con el nombre y su tabla de datos clave–valor en la columna principal, con los mismos campos que en `pre` (marcha: Compositor(es), Estrenada por, Año, Dedicatoria o Localidad, Estilo, Duración y Tipo si no es «Marcha procesional»; sin los recuentos «29 marchas compuestas» que `pre` ponía junto al valor). Sin frase descriptiva bajo el título. Ningún agente puede pasar esos datos a prosa ni quitar campos de la tabla. Vale para marcha, compositor, banda y disco.
- Portada (decidido 2026-11-13): fuera la línea de cifras y el bloque centrado; se mantienen un h1 con el nombre del sitio y el párrafo descriptivo como prosa normal alineada a la izquierda, encima del buscador y de «Últimas incorporaciones». Excepción explícita al principio 10 de las líneas maestras, por SEO.
- Tono (Puerta 1, 2026-11-13): Petróleo, `#007386` en claro y `#4a91a1` en oscuro, con sus neutros en `c/tokens.css`.
- Los aros del logotipo se quedan en dorado `#d4a94a`: es logotipo, no tono de interfaz, y no cuenta en el límite de colores.

## Registro

| Fecha | Fase | Resultado | Commit |
|---|---|---|---|
| 2026-11-13 | F0 | php -l 25/25; smoke pro 103/103 ok; smoke local 103/103 ok; sin CSS ni fuentes traídos (páginas sin estilo, esperado) | 8c239a7 |
| 2026-11-13 | F1 (hasta Puerta 1) | php -l 1/1; smoke pro 103/103 y local 103/103; 0 respuestas ≥400, 0 fallos de carga y 0 errores de consola en /marcha y /marcha/alma-de-dios-193 (claro/oscuro, 1440/390); contrastes AA en los tres candidatos | c07148e |
| 2026-11-13 | F1 (cierre) | Petróleo; php -l 1/1; smoke pro 103/103 y local 103/103; 0 respuestas ≥400, 0 fallos de carga y 0 errores de consola en /marcha y /marcha/alma-de-dios-193 (claro/oscuro, 1440/390) | a8ba2d6 |
| 2026-11-13 | F2 | php -l 20/20; smoke pro 103/103 y local 103/103; PHPStan 179 errores frente a 181 de la base, 0 nuevos, baseline intacta; auditor 9/9 reglas pasan en la ficha de marcha (2 páginas × 1440/390, claro); seguridad: 3 hallazgos corregidos y re-revisados | 0f737d7 |
| 2026-11-13 | F2 (corrección) | Ficha de marcha: tabla de datos de `pre` bajo el h1, a todo el ancho de la columna principal; sin frase (requisito del mantenedor). php -l 4/4; PHPStan 179, 0 nuevos, baseline intacta; smoke local 103/103 y pro 103/103 (sobre 1fbbbbc; 74ca13b solo CSS, local 103/103); auditor 4/4 filas pasan; seguridad sin hallazgos | fada518 |
