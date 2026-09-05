# Plan: log interno de cambios (`cambio_log`)

Fecha: 2026-08-06 · Estado: **F1-F4 y F6 implementados y verificados en local.
Falta F5 (aplicar en producción)**.

Registro interno de **quién cambió qué, cuándo y con qué valores**. No se expone en
ninguna pantalla: se consulta directamente sobre `mdc.db` con `sqlite3`.

---

## 1. Punto de partida

Ya existe auditoría, pero incompleta para este objetivo:

| Qué hay | Dónde | Limitación |
|---|---|---|
| Tabla `admin_log` (`accion, tabla, id_registro, usuario, ts, payload`) | `php/data/mdc.db`, 6.352 filas | El `payload` guarda **nombres de campo**, no valores |
| `Db::logAdmin()` | `src/Db.php:179` | Se invoca **a mano** en ~40 sitios |
| `Db::setAuditUser()` / `auditUser()` | `src/Db.php:30` | El usuario ya se propaga desde `Auth::requireAuth` |
| Guard de escritura por entorno | `Db::assertWritable()`, `src/Db.php:134` | Solo `env=local` puede escribir vía `Db::run()` |

Huecos concretos:

- Escrituras **sin log**: `Admin.php:172` (cambio de contraseña), `EnlacesAuto.php:327`.
- Los scripts de `php/app/tools/*.php` escriben con `Db::run()` sin loguear en su mayoría.
- Los scripts Python de `tools/music_links/` abren `mdc.db` con `sqlite3` directamente.
- `admin_log` no tiene migración `.sql` propia (se creó desde código): el esquema no está versionado.

Precedente favorable: **el proyecto ya usa triggers** — `marcha_ai/ad/au` y `autor_ai/ad/au`
para los índices FTS, y `trg_marcha_localidad_sync_alias` como trigger de negocio.
Añadir triggers no introduce un patrón nuevo.

---

## 2. Decisiones tomadas

| Decisión | Elegido |
|---|---|
| Mecanismo | **Triggers SQLite** por tabla |
| Almacenamiento | **Nueva tabla `cambio_log`**; `admin_log` se queda intacto |
| Granularidad | **Una fila por campo cambiado** (UPDATE) |
| Alcance | Entidades de catálogo + relaciones + `usuarios`; **fuera** el staging de ingesta |
| DELETE | **Snapshot JSON** de la fila borrada |
| Autoría CLI | Nombre del script como actor (`cli:...`, `py:...`) |
| Entornos | **Local y producción** |
| Retención | Sin purga por ahora |

`admin_log` sigue registrando **eventos de negocio** (`ACCEPT`, `DISCARD`, `REJECT`,
`REOPEN`…) y `cambio_log` registra **diffs de datos**. Son complementarios, no
redundantes: nada del código actual se toca.

---

## 3. Esquema

```sql
-- Migración 011_cambio_log.sql

CREATE TABLE IF NOT EXISTS cambio_log (
  ID          INTEGER PRIMARY KEY,
  TS          INTEGER NOT NULL,   -- epoch UTC (mismo formato que admin_log.ts)
  ACTOR       TEXT    NOT NULL,   -- 'jaguerra' | 'cli:fill_enlaces_streaming' | 'py:mdc_music'
  ACCION      TEXT    NOT NULL,   -- INSERT | UPDATE | DELETE
  TABLA       TEXT    NOT NULL,
  ID_REGISTRO INTEGER,            -- PK entera de la fila afectada
  CLAVE       TEXT,               -- PK textual, solo para dedicatoria_alias
  CAMPO       TEXT,               -- columna modificada; NULL en INSERT/DELETE
  ANTES       TEXT,
  DESPUES     TEXT
);

CREATE INDEX IF NOT EXISTS idx_cambio_log_reg   ON cambio_log (TABLA, ID_REGISTRO, TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_ts    ON cambio_log (TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_actor ON cambio_log (ACTOR, TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_campo ON cambio_log (TABLA, CAMPO, TS);

-- Actor de la conexión actual. Fila única, reescrita antes de la primera
-- escritura de cada petición/script.
CREATE TABLE IF NOT EXISTS log_actor (
  ID    INTEGER PRIMARY KEY CHECK (ID = 1),
  ACTOR TEXT NOT NULL
);
INSERT OR IGNORE INTO log_actor (ID, ACTOR) VALUES (1, 'desconocido');
```

Forma de las filas según la acción:

| Acción | Filas generadas | `CAMPO` | `ANTES` | `DESPUES` |
|---|---|---|---|---|
| `UPDATE` | una por columna realmente modificada | nombre columna | valor viejo | valor nuevo |
| `DELETE` | una sola | `NULL` | fila completa en JSON | `NULL` |
| `INSERT` | una sola | `NULL` | `NULL` | fila completa en JSON |

> **Punto abierto** — para `INSERT` propongo snapshot JSON (simétrico con `DELETE`)
> en lugar de una fila por columna: un alta de `marcha` generaría 13 filas de log
> cuyo "antes" es siempre `NULL`, sin aportar nada frente al JSON. Si prefieres
> fila-por-campo también en las altas, es un cambio de una línea en el generador.

---

## 4. Propagación del actor

SQLite no tiene variables de sesión, y **las tablas `TEMP` no son referenciables
desde un trigger** (verificado: `trigger t cannot reference objects in database temp`).
Por eso `log_actor` es una tabla normal de una fila.

Único cambio en PHP, en `src/Db.php`:

```php
private static bool $actorSynced = false;

public static function setAuditUser(string $user): void
{
    self::$auditUser  = $user !== '' ? $user : 'system';
    self::$actorSynced = false;          // si cambia el actor, hay que reenviarlo
}

/** Publica el actor en la BD para que lo lean los triggers. Una vez por petición. */
private static function syncActor(): void
{
    if (self::$actorSynced) {
        return;
    }
    self::$actorSynced = true;
    self::pdo()->prepare('UPDATE log_actor SET ACTOR = ? WHERE ID = 1')
               ->execute([self::$auditUser]);
}
```

…y una llamada a `self::syncActor()` justo después de `self::assertWritable()` en
`run()` y en `transaction()`.

Propiedades importantes:

- **Coste cero en lectura.** Una petición que solo lee (el 99 % del tráfico público)
  no ejecuta ningún `UPDATE`, no abre transacción de escritura y no toca el WAL.
- **Un solo `UPDATE` extra** por petición que sí escribe, sea cual sea el número de
  escrituras.
- No pasa por `Db::run()`, así que no vuelve a disparar `assertWritable()` ni recursión.

Para los scripts CLI, una línea al arrancar:

```php
Db::setAuditUser('cli:' . basename(__FILE__, '.php'));   // php/app/tools/*.php
```

```python
con.execute("UPDATE log_actor SET ACTOR='py:mdc_music' WHERE ID=1")  # tools/music_links/*.py
```

---

## 5. Los triggers

Forma canónica (ejemplo con dos columnas de `marcha`):

```sql
CREATE TRIGGER trg_log_marcha_u AFTER UPDATE ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, 'TITULO', old.TITULO, new.TITULO
     WHERE old.TITULO IS NOT new.TITULO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, 'ESTILO', old.ESTILO, new.ESTILO
     WHERE old.ESTILO IS NOT new.ESTILO;
END;

CREATE TRIGGER trg_log_marcha_i AFTER INSERT ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'marcha', new.ID_MARCHA,
            json_object('TITULO', new.TITULO, 'ESTILO', new.ESTILO /* … */));
END;

CREATE TRIGGER trg_log_marcha_d AFTER DELETE ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'marcha', old.ID_MARCHA,
            json_object('TITULO', old.TITULO, 'ESTILO', old.ESTILO /* … */));
END;
```

Detalles verificados en prueba real:

- `old.X IS NOT new.X` compara **con semántica NULL-safe**: un `NULL → 'valor'` se
  registra, y un `UPDATE` que reescribe el mismo valor **no genera ninguna fila**.
- El `rowCount()` que devuelve `Db::run()` **no se ve afectado** por las inserciones
  del trigger (medido: 1, no 2). Ningún `if ($changes > 0)` del código actual cambia
  de comportamiento.
- `strftime('%s','now')` en lugar de `unixepoch()`: `unixepoch()` requiere SQLite
  3.38+; local tiene 3.49.2 pero la versión de HelioHost no está verificada.
- Nomenclatura `trg_log_*` para no colisionar con los triggers FTS (`marcha_ai`,
  `autor_au`…) ni con `trg_marcha_localidad_sync_alias`.

### Generación

Escribir 3 triggers × 12 tablas ≈ 88 columnas a mano es inviable de mantener.
Un generador, `php/app/tools/gen_log_triggers.php`, lee `PRAGMA table_info` de cada
tabla del alcance y emite `php/app/tools/sql/011_cambio_log.sql`.
**El `.sql` generado se commitea**; el generador solo se vuelve a ejecutar cuando
cambia el esquema.

---

## 6. Alcance

| Tabla | Filas hoy | PK | Notas |
|---|---:|---|---|
| `marcha` | 5.022 | `ID_MARCHA` | |
| `autor` | 927 | `ID_AUTOR` | |
| `banda` | 277 | `ID_BANDA` | |
| `disco` | 439 | `ID_DISCO` | |
| `dedicatoria` | 1.228 | `ID_DEDIC` | |
| `dedicatoria_alias` | 1.242 | `(VARIANTE, LOCALIDAD)` | **PK textual compuesta** → va en `CLAVE`, no en `ID_REGISTRO` |
| `municipio` | 8.157 | `ID_MUNICIPIO` | |
| `contrato` | 92 | `ID_CONTRATO` | |
| `marcha_autor` | 5.591 | `ID_MA` | |
| `disco_marcha` | 4.541 | `ID_DM` | |
| `banda_relacion` | 19 | `ID_RELACION` | |
| `usuarios` | 5 | `id` | **`clave` excluida** (hash de contraseña) |

Fuera de alcance: `ingest_candidato` (2.904), `enlace_candidato` (935),
`ingest_run`, `ingest_canal`, `ingest_veto`, `ingest_descarte_ultimo`,
`enlace_streaming` (5.557), las tablas `*_fts*` y el propio `admin_log`.

Columnas excluidas siempre: `usuarios.clave`. Las PK no se registran como campo
modificado en `UPDATE` (van en `ID_REGISTRO`/`CLAVE`).

---

## 7. Impacto en el proyecto

Esto es lo que hace que la opción de triggers sea la de mínimo impacto:

| Área | Cambio |
|---|---|
| `src/Db.php` | ~15 líneas (`syncActor()` + 2 llamadas + reset en `setAuditUser`) |
| `src/AdminRepo.php`, `Repo.php`, `Admin.php`, resto de `src/` | **ninguno** |
| `templates/`, `routes.php` | **ninguno** |
| `php/app/tools/*.php` | 1 línea por script (autoría; opcional, sin ella quedan como el actor anterior) |
| `tools/music_links/*.py` | 1 línea por script (autoría) |
| Esquema | 1 migración nueva (`011_cambio_log.sql`) |

Los ~122 puntos de escritura existentes quedan cubiertos **sin tocarlos**, incluidos
los dos que hoy no loguean nada.

Rendimiento: una edición típica del panel modifica 1-3 campos → 1-3 `INSERT` extra
sobre una tabla sin claves foráneas, dentro de la misma transacción. Imperceptible.
El caso más pesado es `AdminRepo::actualizarEstiloLote()` (`AdminRepo.php:1031`),
que escribe 1 fila de log por marcha afectada — sigue siendo trivial.

Volumen: `admin_log` acumuló 6.352 filas desde julio de 2026. `cambio_log` crecerá
del orden de 3-5× eso, unos pocos MB al año. Sin purga, como se decidió.

---

## 8. Riesgos y gotchas

1. **Atribución cruzada por `log_actor` obsoleto.** Si un script Python escribe sin
   fijar el actor, sus cambios se atribuyen al último que lo fijó. Mitigación:
   instrumentar los scripts que escriben; el valor inicial es `'desconocido'`.
   Con un solo administrador escribiendo y producción en solo lectura, la ventana
   de colisión concurrente es despreciable, pero **conviene documentarlo**: el log
   es trazabilidad interna, no prueba forense.
2. **Los migradores que reconstruyen tablas borran sus triggers.** `migrate_banda_relacion.php`
   hace `ALTER TABLE … DROP COLUMN` y reconstrucciones; SQLite elimina los triggers
   asociados a una tabla que se dropea. Todo migrador futuro debe reaplicar la
   sección de triggers de `011_cambio_log.sql`. Añadir aviso en `docs/architecture.md`.
3. **Cargas masivas.** Un `seed_municipios.php` completo generaría 8.157 filas de log.
   Para backfills puntuales: `DROP TRIGGER` → cargar → recrear.
4. **Producción.** La migración se aplica **in situ** en el host (no subir el `.db`
   local encima; ver `docs/` sobre despliegue de BD). Verificar antes la versión de
   SQLite del host.
5. **`VACUUM INTO`** de `backup.php` copia triggers y datos: los backups quedan
   consistentes sin cambios.
6. **`dedicatoria_alias`** no tiene PK entera; sus triggers rellenan `CLAVE` con
   `old.VARIANTE || '|' || old.LOCALIDAD` y dejan `ID_REGISTRO` a `NULL`.

---

## 9. Plan de ejecución

1. ✅ **F1 — Generador y DDL.** `php/app/tools/gen_log_triggers.php` + `sql/011_cambio_log.sql`
   generado y commiteado.
2. ✅ **F2 — `Db::syncActor()`.** Añadido a `src/Db.php` (`setAuditUser` resetea
   `$actorSynced`; `run()`/`transaction()` llaman a `syncActor()` tras `assertWritable()`).
3. ✅ **F3 — Aplicar en local.** Backup (`mdc.db.bak-pre-cambio-log`), migración aplicada vía
   `migrate_ingest.php`, verificado con UPDATE/INSERT/DELETE reales: diffs correctos,
   `rowCount()` sin alterar (1, no 2), UPDATE sin cambio real no genera filas.
4. ✅ **F4 — Autoría en scripts.** Línea `UPDATE log_actor SET ACTOR = 'cli:<script>' WHERE ID = 1`
   añadida a los 13 scripts de `php/app/tools/*.php` que escriben en tablas del alcance
   (`completar_provincia`, `corregir_acentos_localidad`, `corregir_erratas_titulo`,
   `fill_duraciones`, `fill_enlaces_odesli`, `fill_estilo_por_banda`, `migrate_banda_relacion`,
   `migrate_marcha_estilo`, `migrate_roles`, `normalizar_localidades`,
   `normalizar_preposiciones_localidad`, `normalizar_unicode_nfc`, `reconciliar_alias_localidad`,
   `seed_dedicatorias`, `seed_municipios`). Los `.py` de `tools/music_links/` solo escriben en
   `enlace_candidato`/`ingest_*` (fuera de alcance) — no necesitan instrumentación.
5. ⬜ **F5 — Producción.** Verificar versión de SQLite del host, backup, aplicar la
   migración in situ. Pendiente — requiere acceso al host.
6. ✅ **F6 — Documentación.** §7 nueva en `docs/architecture.md` (incluye el aviso del punto 8.2),
   fila `cambio_log`/`log_actor` en el inventario de `docs/db-analysis.md`, §13 con las
   consultas de referencia en `docs/admin-panel.md`.

Sin dependencias con la cola de palancas ni con el carril de diseño frontend:
F1-F3 son autocontenidas y reversibles con un `DROP TRIGGER`.

---

## 10. Consultas de referencia

```sql
-- Historial completo de una marcha
SELECT datetime(TS,'unixepoch','localtime') AS cuando, ACTOR, ACCION, CAMPO, ANTES, DESPUES
  FROM cambio_log WHERE TABLA='marcha' AND ID_REGISTRO=1234 ORDER BY TS;

-- Qué tocó cada usuario en los últimos 7 días
SELECT ACTOR, TABLA, COUNT(*) FROM cambio_log
 WHERE TS > strftime('%s','now','-7 days') GROUP BY 1,2 ORDER BY 3 DESC;

-- Quién ha cambiado títulos de marcha alguna vez
SELECT datetime(TS,'unixepoch','localtime'), ACTOR, ID_REGISTRO, ANTES, DESPUES
  FROM cambio_log WHERE TABLA='marcha' AND CAMPO='TITULO' ORDER BY TS DESC;

-- Todo lo borrado, con su contenido
SELECT datetime(TS,'unixepoch','localtime'), ACTOR, TABLA, ID_REGISTRO, ANTES
  FROM cambio_log WHERE ACCION='DELETE' ORDER BY TS DESC;

-- Cambios de un actor concreto cruzados con el evento de negocio de admin_log
SELECT c.TS, c.TABLA, c.CAMPO, c.ANTES, c.DESPUES, a.accion
  FROM cambio_log c
  LEFT JOIN admin_log a ON a.tabla=c.TABLA AND a.id_registro=c.ID_REGISTRO
                       AND ABS(a.ts - c.TS) <= 2
 WHERE c.ACTOR='jaguerra' ORDER BY c.TS DESC;
```
