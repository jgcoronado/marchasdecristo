# Análisis de base de datos — SQLite (estado actual)

> Actualizado: 2026-07-27 (inventario de tablas sincronizado con las migraciones reales) · 2026-07-10 (estilo de marcha) · 2026-07-08 (modelo de linaje de bandas) · 2026-06-05 (sesión 2)
> El documento original analizaba el esquema MySQL (2026-06-01). Ese análisis es histórico — todos los bugs de motores mixtos, collation y FULLTEXT con `%` quedaron resueltos o irrelevantes al migrar a SQLite en la Fase 3b.
> 2026-07-08: el linaje de bandas dejó de guardarse en columnas `FORMACION_ANT/SIG` y pasó a la tabla `banda_relacion` (ver §Modelo de linaje).
> 2026-07-10: nueva columna `marcha.ESTILO` (`CCTT`/`AM`/`NULL`), ver §Estilo de marcha.
> Las secciones "Puntos fuertes"/"Problemas activos"/"Calidad de datos" de más
> abajo describen el esquema **base** heredado de MySQL (`marcha`, `autor`,
> `banda`, `disco` y sus tablas puente) — las tablas añadidas después del
> cutover a PHP (ingesta, dedicatorias, enlaces, contrato, municipio) tienen
> su propio diseño y ya declaran FK reales; ver el inventario de abajo.

---

## Inventario de tablas

> ⚠️ **Los recuentos de esta tabla son del 2026-07-06** (foto del cutover), salvo
> donde se indique otra fecha. El catálogo ha crecido desde entonces: la pasada
> de la ingesta de streaming del **2026-07-28 contó 5.003 marchas**. Las cifras
> derivadas más abajo («Calidad de datos», porcentajes de campos vacíos) siguen
> calculadas sobre las 4 212 originales y por tanto **sobreestiman la cobertura
> relativa**. Revalidar contra `/health` (sesión admin) antes de usarlas para
> decidir nada.

| Tabla | Filas | Uso en la API |
|-------|-------|---------------|
| `marcha` | 4 212 → **5 003** (2026-07-28) | ✅ lectura + escritura admin |
| `autor` | 827 | ✅ lectura + escritura admin |
| `banda` | 268 | ✅ lectura + escritura admin |
| `banda_relacion` | 14 | ✅ modelo de linaje (creada 2026-07-08; leída por `Repo::fetchBanda`/`bandaLinaje()` para el linaje en la ficha pública) |
| `disco` | 431 | ✅ lectura |
| `marcha_autor` | 4 724 | ✅ lectura + escritura admin |
| `disco_marcha` | 4 478 | ✅ lectura |
| `usuarios` | 3 | ✅ auth |
| `marcha_fts` | virtual | ✅ búsqueda full-text |
| `autor_fts` | virtual | ✅ búsqueda full-text |
| `videos` | 357 | ❌ nunca consultada |
| `users` | 0 | ❌ vacía, nunca usada |
| `ingest_canal` / `ingest_run` / `ingest_candidato` | — | ✅ pipeline de ingesta YouTube (`001_ingest_staging.sql`), alimenta `/dashboard/ingesta` |
| `dedicatoria` / `dedicatoria_alias` | — | ✅ hubs de advocación N-01/N-02 (`003_dedicatoria.sql`), curación en `/dashboard/dedicatorias` |
| `enlace_streaming` / `enlace_candidato` | — | ✅ enlaces Spotify/Apple/Deezer (`004_enlace_streaming.sql`), curación en `/dashboard/enlaces` |
| `contrato` | 0 (esperado) | ✅ contratos banda↔hermandad por año (`005_contrato.sql`, N-04/05), alta manual en `/dashboard/temporada/{año}` — vacía hasta que el admin empiece a rellenarla |
| `municipio` | ~8 112 | ✅ catálogo cerrado de localidad/provincia (`007_municipio.sql`), fuente del selector en cascada del panel y de las coordenadas del mapa — ver [ux-analysis-estado.md](ux-analysis-estado.md) |
| `admin_log` | — | ✅ audit log de escrituras admin (`Db::logAdmin()`, sin migración `.sql` propia — se crea desde código) |

Todas las tablas nuevas se crean con `php php/app/tools/migrate_ingest.php`
(aplica en orden alfabético todos los `.sql` de `app/tools/sql/`, idempotente).
Recuentos de filas no verificados contra una BD real en esta revisión (no hay
`.db` en el checkout) salvo donde se indica lo contrario.

`login_autor` fue eliminada durante la migración MySQL → SQLite (tenía 9 hashes MD5 sin salt).

---

## Campos principales por tabla

```
marcha      : ID_MARCHA, TITULO, DEDICATORIA, LOCALIDAD, PROVINCIA, AUDIO, FECHA, BANDA_ESTRENO, TIPO, ESTILO, DETALLES_MARCHA
autor       : ID_AUTOR, NOMBRE, APELLIDOS, NOMBRE_ART, F_NAC, LUGAR_NAC, F_DEF, BIO
banda        : ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD, PROVINCIA, FECHA_FUND, FECHA_EXT, DIRECTOR_ACTUAL, DIR_MUS_ACTUAL, WEB, LINK_FORO
banda_relacion: ID_RELACION, ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO, FECHA_FIN, NOTA
disco       : ID_DISCO, NOMBRE_CD, FECHA_CD, BANDADISCO, DISCOS, d_DETALLES
marcha_autor: ID_MARCHA, ID_AUTOR
disco_marcha: ID_DM, ID_DISCO, IDMARCHA, N_DISCO, NUMEROMARCHA, DM_ENLAZADA
usuarios    : USUARIO, CLAVE
```

Inconsistencia de nomenclatura heredada del MySQL: `marcha_autor` usa `ID_MARCHA` pero `disco_marcha` usa `IDMARCHA` (sin guión bajo).

---

## Modelo de linaje de bandas (`banda_relacion`)

Hasta 2026-07-08 el linaje se guardaba como lista enlazada lineal en `banda`
(`FORMACION_ANT` / `FORMACION_SIG`, + los slots `-2` que nunca se usaron). Ese
modelo no admitía fusiones (N→1), divisiones (1→N) ni bandas juveniles. Se
sustituyó por una tabla de aristas tipadas (DDL en
[`app/tools/sql/002_banda_relacion.sql`](../php/app/tools/sql/002_banda_relacion.sql);
migración one-shot en `app/tools/migrate_banda_relacion.php`).

Cada fila es un vínculo dirigido `ID_ORIGEN → ID_DESTINO`; el significado lo da `TIPO`:

| `TIPO` | Dirección | Cardinalidad |
|--------|-----------|--------------|
| `renombrado` | formación anterior → formación nueva | 1→1 |
| `fusion` | cada banda que se une → formación resultante | N→1 |
| `division` | banda que se rompe → cada formación nueva | 1→N |
| `juvenil` | banda madre → banda juvenil (usa `FECHA_INICIO`/`FECHA_FIN`) | 1→N |

- `FECHA_INICIO` = año del evento (sucesión) o inicio del vínculo (juvenil); `FECHA_FIN` solo aplica a `juvenil` (`NULL` = vigente).
- Absorción = `fusion` cuyo destino es una banda preexistente (no requiere nada especial).
- Tiene FK reales a `banda(ID_BANDA)` y `UNIQUE(ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO)`.
- **Migración**: los 15 vínculos lineales previos entraron como `renombrado`, menos la arista inversa anómala `41→68` (par recíproco: se conservó solo `68→41`, 2003). Resultado: 14 filas.
- **Render público**: `Repo::fetchBanda`/`bandaLinaje()` sí recorren esta tabla (predecesoras → foco → sucesoras, con madres/juveniles en ramal punteado) para la ficha pública — ya no es un `timeline` de un solo elemento. Detalle en [admin-panel.md §7](admin-panel.md).

---

## Estilo de marcha (`marcha.ESTILO`)

Columna nueva (`TEXT CHECK (ESTILO IN ('CCTT','AM'))`, `NULL` = sin asignar),
añadida y rellenada por la migración one-shot
[`app/tools/migrate_marcha_estilo.php`](../php/app/tools/migrate_marcha_estilo.php).
El estilo no se guarda en `banda` — no hay columna de tipo de banda — sino que
se deriva por nombre cada vez que se ejecuta el backfill:

1. Se clasifica cada banda por su nombre: `NOMBRE_COMPLETO` con "Cornetas y
   Tambores" → `CCTT`; con "Agrupación Musical" (o el prefijo `AM `) → `AM`.
   Si el nombre completo no lo deja claro se cae a `NOMBRE_BREVE` (prefijo
   `AM `/`BCT `). Sin ninguna de las dos señales, la banda queda sin estilo
   (2 de 268: `banda#0` "Varias bandas" y `banda#80`, banda militar sin
   nomenclatura CCTT/AM).
2. Cada marcha toma el estilo de su banda de estreno (`marcha.BANDA_ESTRENO`).
3. Si no hay estreno (o la banda de estreno no tiene estilo claro), toma el
   estilo de la banda de su primera grabación documentada — mismo criterio y
   orden que usa `Repo::fetchMarcha()` para "primera grabación": `disco_marcha`
   + `disco`, `ORDER BY FECHA_CD ASC, NOMBRE_CD ASC`, banda = `DM_BANDA` si
   existe, si no `BANDADISCO`.
4. Si ninguna de las dos resuelve, la marcha queda `ESTILO = NULL` (pendiente
   de asignar a mano desde el panel admin).

Resultado del backfill (2026-07-10, 4 271 marchas): 1 586 `CCTT`, 2 087 `AM`,
598 pendientes. Editable por marcha desde `/dashboard/marcha/{id}` (campo
"Estilo"), o en bloque desde `/dashboard/estilos` (ver
[admin-panel.md §8](admin-panel.md)) — pensada para resolver las pendientes;
se muestra en la ficha pública cuando está asignado.

---

## Configuración SQLite (`App\Db`)

Puerto directo de la configuración original (`lib/db.ts` en el histórico
Next.js), ahora en `php/app/src/Db.php` vía PDO:

```php
PRAGMA journal_mode = WAL;    // lecturas concurrentes sin bloquear escrituras
PRAGMA foreign_keys = ON;     // sin efecto en las tablas heredadas de MySQL (sin FK declaradas);
                              // sí lo tiene en las tablas nuevas post-cutover (ver Problema 1)
PRAGMA busy_timeout = 5000;   // 5s antes de SQLITE_BUSY en contención
```

---

## Puntos fuertes

### FTS5 con triggers sincronizados
`schema.sql` define `marcha_fts` y `autor_fts` con triggers AFTER INSERT / UPDATE / DELETE. Cuando el panel de admin edita un título o un nombre de autor, el índice FTS5 se actualiza automáticamente. No hay desincronización posible.

FTS configurado con `tokenize="unicode61 remove_diacritics 2"` — ignora tildes y normaliza Unicode. Las búsquedas de `garcia` encuentran `García`.

### Índices completos
Todos los índices identificados como faltantes en el análisis MySQL ya están en `schema.sql`:

| Índice | Columna | Para qué sirve |
|--------|---------|----------------|
| `idx_dm_disco` | `disco_marcha.ID_DISCO` | Listar marchas de un disco |
| `idx_dm_marcha` | `disco_marcha.IDMARCHA` | Discos de una marcha |
| `idx_disco_banda` | `disco.BANDADISCO` | Discos de una banda |
| `idx_marcha_banda_estreno` | `marcha.BANDA_ESTRENO` | Marchas estrenadas por una banda |
| `idx_ma_marcha` | `marcha_autor.ID_MARCHA` | Autores de una marcha |
| `idx_ma_autor` | `marcha_autor.ID_AUTOR` | Marchas de un autor |
| `idx_rel_origen` | `banda_relacion.ID_ORIGEN` | Linaje hacia delante / juveniles de una banda |
| `idx_rel_destino` | `banda_relacion.ID_DESTINO` | Linaje hacia atrás / madre de una juvenil |
| `idx_rel_tipo` | `banda_relacion.TIPO` | Filtrar por tipo de relación |

### Prepared statements
`App\Db` (`Db::all`/`Db::run`, PDO) usa siempre parámetros preparados. No hay concatenación de SQL en ningún punto del código.

### WAL mode
Permite lecturas sin bloquear escrituras. En un servidor de un solo usuario admin esto no es crítico, pero es la configuración correcta para SQLite en producción.

---

## Problemas activos

### 1. `foreign_keys = ON` sin FK constraints declaradas — solo en las tablas heredadas de MySQL 🟠
El PRAGMA activa la verificación, pero si las tablas no tienen `FOREIGN KEY`/`REFERENCES` en sus `CREATE TABLE`, no hay nada que verificar. Esto sigue siendo cierto para **`marcha`, `autor`, `banda`, `disco`, `marcha_autor`, `disco_marcha`** — el esquema original heredado de MySQL. Las tablas creadas **después** del cutover a PHP sí declaran FK reales con `REFERENCES` (`banda_relacion`, `ingest_candidato`, `dedicatoria_alias`, `contrato`, ver `app/tools/sql/*.sql`), así que este problema no se ha extendido a ellas.

**Huérfanos heredados de la migración MySQL** (presentes en la BD de producción):

| Relación | Huérfanos |
|----------|-----------|
| `disco_marcha.IDMARCHA` → marchas inexistentes | 27 |
| `disco_marcha.ID_DISCO` → discos inexistentes | 2 |
| `marcha_autor.ID_MARCHA` → marchas inexistentes | 4 |
| `marcha_autor.ID_AUTOR` → autores inexistentes | 10 |

Script para verificar estado actual:
```sql
-- Huérfanos en disco_marcha → marcha
SELECT COUNT(*) FROM disco_marcha dm
  WHERE NOT EXISTS (SELECT 1 FROM marcha m WHERE m.ID_MARCHA = dm.IDMARCHA);

-- Huérfanos en disco_marcha → disco
SELECT COUNT(*) FROM disco_marcha dm
  WHERE NOT EXISTS (SELECT 1 FROM disco d WHERE d.ID_DISCO = dm.ID_DISCO);

-- Huérfanos en marcha_autor → marcha
SELECT COUNT(*) FROM marcha_autor ma
  WHERE NOT EXISTS (SELECT 1 FROM marcha m WHERE m.ID_MARCHA = ma.ID_MARCHA);

-- Huérfanos en marcha_autor → autor
SELECT COUNT(*) FROM marcha_autor ma
  WHERE NOT EXISTS (SELECT 1 FROM autor a WHERE a.ID_AUTOR = ma.ID_AUTOR);
```

Plan de acción en [roadmap.md §A1](roadmap.md).

### ~~2. Serialización `GROUP_CONCAT` de autores frágil~~ ✅ Resuelto en el puerto a PHP
El problema original (parseo frágil de `ID#NOMBRE|ID#NOMBRE` por separadores que un nombre con `#`/`|` podría romper) era de `lib/db.ts`/`lib/api.ts` (Next.js). El puerto a PHP no lo heredó: `Repo::autoresFor()` agrupa los autores por marcha en PHP directamente desde filas normales (sin `GROUP_CONCAT` ni parseo de string), evitando tanto ese riesgo como la alternativa `json_group_array` que se había planteado como fix (innecesaria: el SQLite de HelioHost puede no traer JSON1).

### 3. Marchas sin autores son invisibles en búsquedas 🟡
Todas las queries públicas filtran con:
```sql
EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
```
Una marcha creada sin autores no aparece en ninguna búsqueda pública. Esta es una regla de negocio válida, pero combinada con el bug U1 (sin transacción en addMarcha), puede dejar marchas invisibles en la BD sin que el admin lo sepa.

### ~~4. `autor.NOMBRE_ART` indexado pero no gestionable~~ ✅ Resuelto en el puerto a PHP
`NOMBRE_ART` ya está en `AdminRepo::EDITABLE_AUTOR` y se escribe desde `addAutor`/`editAutor` — los compositores con nombre artístico (p.ej. Abel Moreno "Miguelito") sí pueden registrarlo desde el panel. Ver [admin-panel.md §2](admin-panel.md).

### 5. `FECHA` como INTEGER con `0` = "sin fecha" 🟡
247 de 4 212 marchas tenían `FECHA = 0` en el análisis original (MySQL). `Repo::normalizeFecha()` en el puerto PHP solo convierte `NULL`/`''` a `'s/f'` — **no** convierte `0` explícitamente. Si sigue habiendo filas con `FECHA = 0` en la BD real, se mostrarían como `"0"` en vez de `"s/f"`. No reverificado contra la BD real en esta revisión (sin `.db` en el checkout); semánticamente lo correcto seguiría siendo `NULL`.

No tiene impacto en rendimiento ni en búsquedas actuales (las búsquedas por fecha usan `>=` y `<=`, y `0` no interfiere con esos rangos en la práctica). Plan en [roadmap.md §B2](roadmap.md).

### 6. Tablas muertas 🟢
- `videos` (357 filas): existía en MySQL para vídeos de YouTube. No hay Route Handler que la exponga. Ninguna página la usa.
- `users` (0 filas): tabla vacía sin uso conocido.

Plan en [roadmap.md §B1](roadmap.md).

---

## Calidad de datos

| Campo | Vacíos / Total | % | Impacto |
|-------|---------------|---|---------|
| `marcha.AUDIO` | ~2 082 / 4 212 | ~49% | Sin impacto en búsquedas; campo informativo |
| `marcha.PROVINCIA` | ~1 652 / 4 212 | ~39% | Filtro de búsqueda por provincia menos efectivo |
| `marcha.BANDA_ESTRENO` | ~782 / 4 212 | ~19% | No enlaza a la banda en la página de detalle |
| `marcha.DEDICATORIA` | ~650 / 4 212 | ~15% | Normal para marchas antiguas |
| `marcha.FECHA` | 247 / 4 212 | 6% | Aparece "s/f" en la UI |

Los títulos duplicados (Misericordia ×10, Jesús Nazareno ×7, etc.) son legítimos — distintas marchas con el mismo nombre compuestas por distintos autores.

---

## Prioridades de acción

Ver plan detallado en [roadmap.md §Fase 4](roadmap.md).

| Prioridad | Ítem |
|-----------|------|
| ✅ U1 | Transacción en `addMarcha` — resuelto (verificado en `AdminRepo::addMarcha`, con `Db::transaction`) |
| ✅ U2 | Validar existencia de `autoresIds` — resuelto (`AdminRepo::allAutoresExist()`) |
| ✅ A3 | `NOMBRE_ART` en alta/edición de autor — resuelto (`EDITABLE_AUTOR`, `addAutor` en `AdminRepo.php`) |
| ✅ M2 | Migrar serialización de autores — resuelto de otra forma: el puerto a PHP agrupa en PHP (`Repo::autoresFor()`), no hizo falta JSON1 (ver arriba) |
| 🟠 A1 | Limpiar huérfanos + declarar FK constraints en las tablas heredadas de MySQL (`marcha`, `autor`, `banda`, `disco`, `marcha_autor`, `disco_marcha`) |
| 🟡 B2 | Normalizar `FECHA = 0` → `NULL` — no reverificado contra la BD real en esta revisión (sin `.db` en el checkout); `Repo::normalizeFecha()` solo convierte `NULL`/`''` a `'s/f'`, no `0` |
| 🟢 B1 | Eliminar tablas muertas (`videos`, `users`) |
