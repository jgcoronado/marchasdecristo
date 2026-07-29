# Pendientes manuales — arranque del plan (2026-07-29)

> Este documento es **operativo y de corta vida**: nace del arranque de B-01 en
> `docs/roadmap.md` §2/§4 y se archiva (no se borra) en cuanto todo lo de aquí
> quede resuelto — a partir de ahí, el estado vive solo en `roadmap.md`. No es
> un tracker paralelo: es la lista de "qué toca hacer tú, con tus manos, hoy o
> mañana" mientras el trabajo de código avanza.
>
> Cada ítem indica **quién actúa** (tú / una sesión de Claude Code) y **qué
> necesita confirmarse** antes de seguir. Si algo de aquí queda resuelto,
> táchalo con `~~texto~~` y anota fecha — no lo borres, es el registro de qué
> se decidió y cuándo.

---

## 1. Ya hecho (verificado esta sesión, 2026-07-29)

- [x] **Fusión local de las dos ramas** (`claude/diseño-discreto-sencillo-jymud4`
      + `claude/filtrado-candidatas-videos-drdd1y`) en una rama de integración
      local (`local/b01-integracion`, en un worktree temporal, no en el checkout
      principal). El único conflicto previsto, en `docs/admin-panel.md`, era una
      colisión de numeración (las dos ramas añadían una "§11" distinta) — se
      resolvió conservando **ambas** secciones y renumerando la de ingesta a
      **§12**. Sin pérdida de contenido de ninguna de las dos ramas.
- [x] **Lint** (`php -l`) sobre el árbol fusionado completo: limpio.
- [x] **Smoke tests locales** (`ci_fixture.php` + servidor embebido +
      `ci_smoke.php`, exactamente el mismo procedimiento que usa
      `.github/workflows/ci.yml`): **85/85 superadas**.
- [x] Issue [#23](https://github.com/jgcoronado/mdc-back/issues/23) (M9) cerrado
      — cubierto por N-07/N-08/N-09/N-10.
- [x] Contradicción del cron de backup resuelta como problema documental: dueño
      único en `pendientes-post-cutover.md §2` (la verificación en sí sigue
      pendiente, ver §2.3 más abajo).
- [x] Recuentos de catálogo fechados en `context.md` y `db-analysis.md` (la BD
      tiene ~5.000 marchas, no 4.212 — pendiente de recontar con precisión, ver
      §2 más abajo).

## 2. Puedes avanzar tú ahora mismo (sin esperar a nada)

Estas tareas no dependen de que yo termine nada. Puedes hacerlas en cualquier
momento, en el orden que prefieras.

### 2.1 · Verificar el cron de backup en Plesk (OPS-03 · T-03)
- **Dónde**: Plesk → Scheduled Tasks.
- **Qué mirar**: que exista una tarea tipo *"Run a PHP script"* apuntando a
  `/home/jaguerra27.helioho.st/app/tools/backup.php`, en **PHP 8.4 explícito**
  (el CLI por defecto del host es PHP 5.x y falla con
  `Unsupported declare 'strict_types'`).
- **Qué confirmar además**: que aparece al menos un
  `private/backups/mdc-*.db` reciente (no solo que la tarea existe — que se
  ha ejecutado).
- **Al terminar**: anota el resultado en `docs/pendientes-post-cutover.md §2` (es
  el dueño único de este dato) y en `docs/roadmap.md` (tarea `T-03`/`OPS-03`).

### 2.2 · Decidir sobre `/temporada` (DEC-01)
- **Contexto**: la tabla `contrato` está migrada en producción desde el
  2026-07-23 pero **vacía**. La pantalla pública `/temporada/{año}` está
  publicada y no muestra nada.
- **Decide una de dos**:
  - **(a)** Empezar a rellenarla a mano desde `/dashboard/temporada/{año actual}`
    (alta manual, banda + hermandad + fecha del contrato). Es trabajo de
    curación tuyo, no de código.
  - **(b)** Despublicarla temporalmente hasta tener datos reales que mostrar
    (pídeme que la oculte del menú/sitemap si eliges esto).
- **Al terminar**: anota la decisión en `docs/roadmap.md` (tarea `DEC-01`, bloque
  P0) para que deje de aparecer como pendiente.

### 2.3 · Decidir sobre el VPS de rollback (DEC-02)
- **Contexto**: el plan de cutover decía mantenerlo 1–2 semanas como rollback;
  llevan ya ~3,5 semanas desde el 2026-07-04.
- **Decide**: apagarlo (`docker compose down` en el VPS + dar de baja el
  servidor + subir el TTL del DNS a su valor normal — pasos en
  `docs/pendientes-post-cutover.md §5`) o declararlo permanente.
- **Al terminar**: anota la decisión en `docs/roadmap.md` (tarea `DEC-02`).

### 2.4 · Correo del dominio (pendiente histórico, sin dueño de tarea)
- Si `marchasdecristo.com` tiene email, confirmar que sigue funcionando (el
  cutover solo debía tocar el registro `A`, no el `MX`). Enviar/recibir una
  prueba. Está en `docs/pendientes-post-cutover.md §4` desde el cutover, sin
  urgencia declarada — decide si te importa cerrarlo ahora o lo dejas.

### 2.5 · Repasar el veredicto de `docs/roadmap.md` §2 y §3
- No es una tarea de sistema, es una revisión de criterio: lee el plan
  priorizado (P0–P3) y el contraste con el consejo de sabios, y dime si el
  orden y las prioridades reflejan lo que tú quieres, antes de que empecemos a
  ejecutar P1/P2 en serio. Es más barato ajustar el orden ahora que a mitad de
  camino.

## 3. Pendiente de que yo continúe (siguiente sesión / ahora mismo si confirmas)

Estas tareas están listas para avanzar pero necesitan tu confirmación explícita
antes de tocar un entorno compartido (repositorio remoto, CI, PRE o PRO) —
según las reglas de esta sesión, un push o un deploy no se hace sin que lo
pidas tú.

### 3.1 · Push de la integración a la rama `pre` — **necesita tu confirmación**
- Estado: la fusión local está lista, lint limpio, 85/85 smoke. Lo que falta:
  1. Empujar el resultado a la rama `pre` del remoto (dispara CI + deploy
     automático a preproducción).
  2. Smoke remoto contra PRE + que **tú** lo mires en el navegador (cinta de
     preproducción, `/health` con `entorno: pre`, y sobre todo probar a mano el
     rediseño y el alta de discos con portada).
  3. Si todo va bien: PR de `pre` a `main`, fusionar, deploy automático a PRO.
  4. Borrar la rama `claude/bandas-rrss-discos-sync-x60kfw` (redundante, ya
     contenida en `filtrado-candidatas-videos-drdd1y`).
- **Dime cuándo quieres que dé el paso 1.** No lo hago solo porque hayamos
  terminado la parte local: es la primera acción que toca un entorno
  compartido.

### 3.2 · OPS-01 — Migración `008` + importar candidatos (tras 3.1)
- Depende de que el código esté fusionado y desplegado. Se hace **en local**,
  nunca sobre PRE (PRE comparte la BD de producción y es solo lectura).
- Pasos: `migrate_ingest.php` (aplica `008_ingest_streaming.sql`) →
  `import_candidatos.php` con el NDJSON de `tools/music_links/out/` →
  `sync_db_to_prod.php` para subir el resultado.

### 3.3 · OPS-02 — `seed_dedicatorias.php` en producción
- Pendiente desde el 2026-07-23, independiente de B-01. Se ejecuta vía Plesk
  Scheduled Tasks ("Run a PHP script", **PHP 8.4 explícito**). Puedo dejarte
  el comando exacto cuando quieras ejecutarlo — es una tarea tuya en el panel
  de Plesk, no algo que yo pueda hacer desde aquí.

### 3.4 · R-01 — Capturar el ISRC (antes de curar los 616 candidatos)
- Trabajo de código: columna nueva en `enlace_streaming`/`ingest_candidato` +
  ajustar `tools/music_links/descubrir_marchas.py` para leer
  `external_ids.isrc` (Spotify) e `isrc` (Deezer). No depende de 3.1, pero
  tiene más sentido hacerlo **antes** de que empieces a curar candidatos a
  mano, para no repetir trabajo.
- **Necesito que confirmes** que quieres que lo desarrolle ya, o que prefieres
  esperar a que 3.1–3.3 estén cerrados primero.

### 3.5 · R-07 — Página pública de cobertura
- Trabajo de código, sin bloqueos. Útil tenerla lista antes de terminar la
  campaña de curación (M2), para medir antes/después como pide el issue
  [#16](https://github.com/jgcoronado/mdc-back/issues/16).

## 4. Qué necesito confirmar de ti para poder seguir con precisión

Preguntas concretas, no retóricas — respóndelas cuando puedas y las dejo
resueltas en `roadmap.md`:

1. **¿Confirmas el push a `pre` (§3.1)?** Es la siguiente acción y toca un
   entorno compartido.
2. **`/temporada` (§2.2)**: ¿rellenar a mano o despublicar por ahora?
3. **VPS de rollback (§2.3)**: ¿apagarlo ya o mantenerlo?
4. **R-01 (ISRC, §3.4)**: ¿lo desarrollo ya o esperamos a que termine B-01/OPS?
5. **Orden de P1–P2 en `roadmap.md`**: ¿te sirve tal como quedó, o quieres mover
   algo (por ejemplo, adelantar accesibilidad/impresión si te preocupa más la
   experiencia que el dato en este momento)?

---

## Cómo cerrar este documento

Cuando todos los ítems de §2 y §3 estén resueltos (tachados con fecha) y no
queden preguntas abiertas en §4, este fichero se archiva: renómbralo a
`docs/archive/` o pide que lo haga, y `docs/roadmap.md` vuelve a ser la única
fuente de estado. No lo dejes creciendo indefinidamente como un tracker
paralelo — es una lista de arranque, no el plan.
