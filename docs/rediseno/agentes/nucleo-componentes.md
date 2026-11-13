---
name: nucleo-componentes
description: Fase 2 del rediseño C. Crea la capa de componentes PHP con props tipadas y adapta layout.php para cargar el CSS nuevo en páginas públicas y el antiguo en /admin.
model: claude-opus-5-5
effort: medium
---
Construyes la base sobre la que trabajarán los maquetadores.

1. Lee `php/app/src/Html.php`, `View.php` y las plantillas de ficha y listado, y el autoload de ADR-001 (`spl_autoload_register`, sin composer).
2. Crea componentes como clases con constructor de props tipadas y un `render(): string` que escape por defecto:
   - cabecera de ficha (nombre, frase; sin etiqueta y sin compartir);
   - ficha técnica (clave–valor, valor opcionalmente enlazado);
   - tabla (contrato `.t`: celda principal, cifras, `data-l` para móvil);
   - escuchar (lista de texto con icono monocromo y estado vacío en una frase);
   - paginación (total «1–20 de 5.518» y números);
   - filtros y facetas;
   - migas;
   - pie de ficha (identificador y enlace permanente).
3. Las reglas van en el código: no crees ningún componente de etiqueta de color; los recuentos solo los admiten la tabla, las facetas y el total de la paginación.
4. En `layout.php`: las rutas públicas cargan `c/*.css`; `/admin` sigue con `app.css`. Cabecera y pie, según §2 y §3 de las líneas maestras.
5. Aplica los componentes solo a una plantilla como prueba (la ficha de marcha) y deja el resto a los maquetadores.
6. Pasa PHPStan con `scripts/quality.sh`, sin añadir entradas a la baseline.
7. Escribe `.claude/qa/auditor.mjs` (Node + Playwright con el Chrome del sistema, como `shoot.mjs`). Recibe una URL base y una lista de rutas y mide las reglas que enumera `verificador.md`, paso 3, con salida JSON y una tabla legible. Pruébalo contra la ficha de marcha ya migrada y contra `pre`: en `pre` tiene que detectar las etiquetas y las cifras.

## Reglas comunes
- Responde, comenta el código y escribe los commits en español.
- Trabaja solo en el worktree y el puerto que te indique el orquestador. Nunca toques `main`, `pre`, el checkout principal ni PRO.
- Especificación: `docs/rediseno/lineas-maestras.md` (manda) y `docs/rediseno/clasificacion-disenos-a-b.md` §1 (reglas fijas). Si algo choca con ellas, para y repórtalo; no lo resuelvas por tu cuenta.
- Datos reales: servidor con una COPIA de `php/data/mdc.db` (`cp` a tu carpeta temporal y `DB_PATH` apuntando a ella). Nada de maquetas HTML.
- Arranca los servidores con `scripts/dev_server.sh <puerto>`, guarda el PID y mátalo al terminar.
- No versiones `php/app/config.local.php` ni el enlace `php/public/cover`; revisa `git status` antes de cada commit.
- Al terminar, devuelve: qué has cambiado (ficheros), cómo lo has comprobado (cifras) y qué queda abierto.
