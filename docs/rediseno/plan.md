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

- [ ] **F0 · Preparación** (`integrador`)
  - Crear `rediseno/c` desde `origin/pre`.
  - Traer de `rediseno/a-uniforme` solo `php/app/templates/`, `php/app/src/{Html,Repo,Pages,View}.php` y los cambios de `php/tools/ci_smoke.php`.
  - Pasar smoke y `php -l`.
  - Dejar el checkout principal en `pre`.
- [ ] **F1 · Tono y tipografía** (`disenador-tokens`)
  - Comprobar el tema claro y oscuro de Wikipedia y Standard Ebooks, y las licencias de Crimson Pro y Libertinus en sus repositorios.
  - Proponer tres tonos con sus cinco neutros, en claro y oscuro, con contraste AA medido por script.
  - Generar el listado de marchas real con cada candidato.
  - → **Puerta 1 (tú):** elegir el tono en el artefacto comparador.
  - Después: escribir `c/tokens.css` y alojar las fuentes.
- [ ] **F2 · Núcleo** (`nucleo-componentes`, luego `revisor-seguridad` sobre su diff)
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
- Migas sin el año: el año ya está enlazado en la frase de la ficha y mantiene el enlace interno al hub del año.
- Portada (decidido 2026-11-13): fuera la línea de cifras y el bloque centrado; se mantienen un h1 con el nombre del sitio y el párrafo descriptivo como prosa normal alineada a la izquierda, encima del buscador y de «Últimas incorporaciones». Excepción explícita al principio 10 de las líneas maestras, por SEO.

## Registro

| Fecha | Fase | Resultado | Commit |
|---|---|---|---|
