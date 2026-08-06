-- Migración 011_cambio_log.sql — GENERADO por php/app/tools/gen_log_triggers.php
-- No editar a mano: cambia el esquema de una tabla del alcance y vuelve a
-- ejecutar el generador. Ver docs/plan-log-cambios.md.

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
-- escritura de cada petición/script (Db::syncActor()).
CREATE TABLE IF NOT EXISTS log_actor (
  ID    INTEGER PRIMARY KEY CHECK (ID = 1),
  ACTOR TEXT NOT NULL
);
INSERT OR IGNORE INTO log_actor (ID, ACTOR) VALUES (1, 'desconocido');

-- marcha ------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_marcha_u AFTER UPDATE ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'TITULO', old.TITULO, new.TITULO
     WHERE old.TITULO IS NOT new.TITULO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'FECHA', old.FECHA, new.FECHA
     WHERE old.FECHA IS NOT new.FECHA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'DETALLES_MARCHA', old.DETALLES_MARCHA, new.DETALLES_MARCHA
     WHERE old.DETALLES_MARCHA IS NOT new.DETALLES_MARCHA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'DEDICATORIA', old.DEDICATORIA, new.DEDICATORIA
     WHERE old.DEDICATORIA IS NOT new.DEDICATORIA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'LOCALIDAD', old.LOCALIDAD, new.LOCALIDAD
     WHERE old.LOCALIDAD IS NOT new.LOCALIDAD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'PROVINCIA', old.PROVINCIA, new.PROVINCIA
     WHERE old.PROVINCIA IS NOT new.PROVINCIA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'TIPO', old.TIPO, new.TIPO
     WHERE old.TIPO IS NOT new.TIPO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'BANDA_ESTRENO', old.BANDA_ESTRENO, new.BANDA_ESTRENO
     WHERE old.BANDA_ESTRENO IS NOT new.BANDA_ESTRENO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'DURACION_SEG', old.DURACION_SEG, new.DURACION_SEG
     WHERE old.DURACION_SEG IS NOT new.DURACION_SEG;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'AUDIO', old.AUDIO, new.AUDIO
     WHERE old.AUDIO IS NOT new.AUDIO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'DATOS_INT', old.DATOS_INT, new.DATOS_INT
     WHERE old.DATOS_INT IS NOT new.DATOS_INT;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha', new.ID_MARCHA, NULL, 'ESTILO', old.ESTILO, new.ESTILO
     WHERE old.ESTILO IS NOT new.ESTILO;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_marcha_i AFTER INSERT ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'marcha', new.ID_MARCHA, NULL,
            json_object('ID_MARCHA', new.ID_MARCHA, 'TITULO', new.TITULO, 'FECHA', new.FECHA, 'DETALLES_MARCHA', new.DETALLES_MARCHA, 'DEDICATORIA', new.DEDICATORIA, 'LOCALIDAD', new.LOCALIDAD, 'PROVINCIA', new.PROVINCIA, 'TIPO', new.TIPO, 'BANDA_ESTRENO', new.BANDA_ESTRENO, 'DURACION_SEG', new.DURACION_SEG, 'AUDIO', new.AUDIO, 'DATOS_INT', new.DATOS_INT, 'ESTILO', new.ESTILO));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_marcha_d AFTER DELETE ON marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'marcha', old.ID_MARCHA, NULL,
            json_object('ID_MARCHA', old.ID_MARCHA, 'TITULO', old.TITULO, 'FECHA', old.FECHA, 'DETALLES_MARCHA', old.DETALLES_MARCHA, 'DEDICATORIA', old.DEDICATORIA, 'LOCALIDAD', old.LOCALIDAD, 'PROVINCIA', old.PROVINCIA, 'TIPO', old.TIPO, 'BANDA_ESTRENO', old.BANDA_ESTRENO, 'DURACION_SEG', old.DURACION_SEG, 'AUDIO', old.AUDIO, 'DATOS_INT', old.DATOS_INT, 'ESTILO', old.ESTILO));
END;

-- autor -------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_autor_u AFTER UPDATE ON autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'APELLIDOS', old.APELLIDOS, new.APELLIDOS
     WHERE old.APELLIDOS IS NOT new.APELLIDOS;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'NOMBRE', old.NOMBRE, new.NOMBRE
     WHERE old.NOMBRE IS NOT new.NOMBRE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'NOMBRE_ART', old.NOMBRE_ART, new.NOMBRE_ART
     WHERE old.NOMBRE_ART IS NOT new.NOMBRE_ART;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'F_NAC', old.F_NAC, new.F_NAC
     WHERE old.F_NAC IS NOT new.F_NAC;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'F_DEF', old.F_DEF, new.F_DEF
     WHERE old.F_DEF IS NOT new.F_DEF;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'LUGAR_NAC', old.LUGAR_NAC, new.LUGAR_NAC
     WHERE old.LUGAR_NAC IS NOT new.LUGAR_NAC;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'autor', new.ID_AUTOR, NULL, 'BIO', old.BIO, new.BIO
     WHERE old.BIO IS NOT new.BIO;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_autor_i AFTER INSERT ON autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'autor', new.ID_AUTOR, NULL,
            json_object('ID_AUTOR', new.ID_AUTOR, 'APELLIDOS', new.APELLIDOS, 'NOMBRE', new.NOMBRE, 'NOMBRE_ART', new.NOMBRE_ART, 'F_NAC', new.F_NAC, 'F_DEF', new.F_DEF, 'LUGAR_NAC', new.LUGAR_NAC, 'BIO', new.BIO));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_autor_d AFTER DELETE ON autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'autor', old.ID_AUTOR, NULL,
            json_object('ID_AUTOR', old.ID_AUTOR, 'APELLIDOS', old.APELLIDOS, 'NOMBRE', old.NOMBRE, 'NOMBRE_ART', old.NOMBRE_ART, 'F_NAC', old.F_NAC, 'F_DEF', old.F_DEF, 'LUGAR_NAC', old.LUGAR_NAC, 'BIO', old.BIO));
END;

-- banda -------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_banda_u AFTER UPDATE ON banda BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'NOMBRE_COMPLETO', old.NOMBRE_COMPLETO, new.NOMBRE_COMPLETO
     WHERE old.NOMBRE_COMPLETO IS NOT new.NOMBRE_COMPLETO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'NOMBRE_BREVE', old.NOMBRE_BREVE, new.NOMBRE_BREVE
     WHERE old.NOMBRE_BREVE IS NOT new.NOMBRE_BREVE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'LOCALIDAD', old.LOCALIDAD, new.LOCALIDAD
     WHERE old.LOCALIDAD IS NOT new.LOCALIDAD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'PROVINCIA', old.PROVINCIA, new.PROVINCIA
     WHERE old.PROVINCIA IS NOT new.PROVINCIA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'FECHA_FUND', old.FECHA_FUND, new.FECHA_FUND
     WHERE old.FECHA_FUND IS NOT new.FECHA_FUND;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'FECHA_EXT', old.FECHA_EXT, new.FECHA_EXT
     WHERE old.FECHA_EXT IS NOT new.FECHA_EXT;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'DIRECTOR_ACTUAL', old.DIRECTOR_ACTUAL, new.DIRECTOR_ACTUAL
     WHERE old.DIRECTOR_ACTUAL IS NOT new.DIRECTOR_ACTUAL;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'DIR_MUS_ACTUAL', old.DIR_MUS_ACTUAL, new.DIR_MUS_ACTUAL
     WHERE old.DIR_MUS_ACTUAL IS NOT new.DIR_MUS_ACTUAL;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'WEB', old.WEB, new.WEB
     WHERE old.WEB IS NOT new.WEB;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda', new.ID_BANDA, NULL, 'LINK_FORO', old.LINK_FORO, new.LINK_FORO
     WHERE old.LINK_FORO IS NOT new.LINK_FORO;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_banda_i AFTER INSERT ON banda BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'banda', new.ID_BANDA, NULL,
            json_object('ID_BANDA', new.ID_BANDA, 'NOMBRE_COMPLETO', new.NOMBRE_COMPLETO, 'NOMBRE_BREVE', new.NOMBRE_BREVE, 'LOCALIDAD', new.LOCALIDAD, 'PROVINCIA', new.PROVINCIA, 'FECHA_FUND', new.FECHA_FUND, 'FECHA_EXT', new.FECHA_EXT, 'DIRECTOR_ACTUAL', new.DIRECTOR_ACTUAL, 'DIR_MUS_ACTUAL', new.DIR_MUS_ACTUAL, 'WEB', new.WEB, 'LINK_FORO', new.LINK_FORO));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_banda_d AFTER DELETE ON banda BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'banda', old.ID_BANDA, NULL,
            json_object('ID_BANDA', old.ID_BANDA, 'NOMBRE_COMPLETO', old.NOMBRE_COMPLETO, 'NOMBRE_BREVE', old.NOMBRE_BREVE, 'LOCALIDAD', old.LOCALIDAD, 'PROVINCIA', old.PROVINCIA, 'FECHA_FUND', old.FECHA_FUND, 'FECHA_EXT', old.FECHA_EXT, 'DIRECTOR_ACTUAL', old.DIRECTOR_ACTUAL, 'DIR_MUS_ACTUAL', old.DIR_MUS_ACTUAL, 'WEB', old.WEB, 'LINK_FORO', old.LINK_FORO));
END;

-- disco -------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_disco_u AFTER UPDATE ON disco BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'NOMBRE_CD', old.NOMBRE_CD, new.NOMBRE_CD
     WHERE old.NOMBRE_CD IS NOT new.NOMBRE_CD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'FECHA_CD', old.FECHA_CD, new.FECHA_CD
     WHERE old.FECHA_CD IS NOT new.FECHA_CD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'BANDADISCO', old.BANDADISCO, new.BANDADISCO
     WHERE old.BANDADISCO IS NOT new.BANDADISCO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'D_DETALLES', old.D_DETALLES, new.D_DETALLES
     WHERE old.D_DETALLES IS NOT new.D_DETALLES;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'PERCUSION', old.PERCUSION, new.PERCUSION
     WHERE old.PERCUSION IS NOT new.PERCUSION;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco', new.ID_DISCO, NULL, 'PERCUSION_SEG', old.PERCUSION_SEG, new.PERCUSION_SEG
     WHERE old.PERCUSION_SEG IS NOT new.PERCUSION_SEG;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_disco_i AFTER INSERT ON disco BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'disco', new.ID_DISCO, NULL,
            json_object('ID_DISCO', new.ID_DISCO, 'NOMBRE_CD', new.NOMBRE_CD, 'FECHA_CD', new.FECHA_CD, 'BANDADISCO', new.BANDADISCO, 'D_DETALLES', new.D_DETALLES, 'PERCUSION', new.PERCUSION, 'PERCUSION_SEG', new.PERCUSION_SEG));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_disco_d AFTER DELETE ON disco BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'disco', old.ID_DISCO, NULL,
            json_object('ID_DISCO', old.ID_DISCO, 'NOMBRE_CD', old.NOMBRE_CD, 'FECHA_CD', old.FECHA_CD, 'BANDADISCO', old.BANDADISCO, 'D_DETALLES', old.D_DETALLES, 'PERCUSION', old.PERCUSION, 'PERCUSION_SEG', old.PERCUSION_SEG));
END;

-- dedicatoria -------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_u AFTER UPDATE ON dedicatoria BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria', new.ID_DEDIC, NULL, 'NOMBRE', old.NOMBRE, new.NOMBRE
     WHERE old.NOMBRE IS NOT new.NOMBRE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria', new.ID_DEDIC, NULL, 'LOCALIDAD', old.LOCALIDAD, new.LOCALIDAD
     WHERE old.LOCALIDAD IS NOT new.LOCALIDAD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria', new.ID_DEDIC, NULL, 'PROVINCIA', old.PROVINCIA, new.PROVINCIA
     WHERE old.PROVINCIA IS NOT new.PROVINCIA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria', new.ID_DEDIC, NULL, 'SLUG_KEY', old.SLUG_KEY, new.SLUG_KEY
     WHERE old.SLUG_KEY IS NOT new.SLUG_KEY;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria', new.ID_DEDIC, NULL, 'PERSONAL', old.PERSONAL, new.PERSONAL
     WHERE old.PERSONAL IS NOT new.PERSONAL;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_i AFTER INSERT ON dedicatoria BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'dedicatoria', new.ID_DEDIC, NULL,
            json_object('ID_DEDIC', new.ID_DEDIC, 'NOMBRE', new.NOMBRE, 'LOCALIDAD', new.LOCALIDAD, 'PROVINCIA', new.PROVINCIA, 'SLUG_KEY', new.SLUG_KEY, 'PERSONAL', new.PERSONAL));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_d AFTER DELETE ON dedicatoria BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'dedicatoria', old.ID_DEDIC, NULL,
            json_object('ID_DEDIC', old.ID_DEDIC, 'NOMBRE', old.NOMBRE, 'LOCALIDAD', old.LOCALIDAD, 'PROVINCIA', old.PROVINCIA, 'SLUG_KEY', old.SLUG_KEY, 'PERSONAL', old.PERSONAL));
END;

-- dedicatoria_alias -------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_alias_u AFTER UPDATE ON dedicatoria_alias BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'dedicatoria_alias', NULL, new.VARIANTE || '|' || new.LOCALIDAD, 'ID_DEDIC', old.ID_DEDIC, new.ID_DEDIC
     WHERE old.ID_DEDIC IS NOT new.ID_DEDIC;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_alias_i AFTER INSERT ON dedicatoria_alias BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'dedicatoria_alias', NULL, new.VARIANTE || '|' || new.LOCALIDAD,
            json_object('VARIANTE', new.VARIANTE, 'LOCALIDAD', new.LOCALIDAD, 'ID_DEDIC', new.ID_DEDIC));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_dedicatoria_alias_d AFTER DELETE ON dedicatoria_alias BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'dedicatoria_alias', NULL, old.VARIANTE || '|' || old.LOCALIDAD,
            json_object('VARIANTE', old.VARIANTE, 'LOCALIDAD', old.LOCALIDAD, 'ID_DEDIC', old.ID_DEDIC));
END;

-- municipio ---------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_municipio_u AFTER UPDATE ON municipio BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'PROVINCIA', old.PROVINCIA, new.PROVINCIA
     WHERE old.PROVINCIA IS NOT new.PROVINCIA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'NOMBRE', old.NOMBRE, new.NOMBRE
     WHERE old.NOMBRE IS NOT new.NOMBRE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'LAT', old.LAT, new.LAT
     WHERE old.LAT IS NOT new.LAT;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'LNG', old.LNG, new.LNG
     WHERE old.LNG IS NOT new.LNG;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'OFICIAL', old.OFICIAL, new.OFICIAL
     WHERE old.OFICIAL IS NOT new.OFICIAL;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'CLAVE', old.CLAVE, new.CLAVE
     WHERE old.CLAVE IS NOT new.CLAVE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'municipio', new.ID_MUNICIPIO, NULL, 'CREATED_AT', old.CREATED_AT, new.CREATED_AT
     WHERE old.CREATED_AT IS NOT new.CREATED_AT;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_municipio_i AFTER INSERT ON municipio BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'municipio', new.ID_MUNICIPIO, NULL,
            json_object('ID_MUNICIPIO', new.ID_MUNICIPIO, 'PROVINCIA', new.PROVINCIA, 'NOMBRE', new.NOMBRE, 'LAT', new.LAT, 'LNG', new.LNG, 'OFICIAL', new.OFICIAL, 'CLAVE', new.CLAVE, 'CREATED_AT', new.CREATED_AT));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_municipio_d AFTER DELETE ON municipio BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'municipio', old.ID_MUNICIPIO, NULL,
            json_object('ID_MUNICIPIO', old.ID_MUNICIPIO, 'PROVINCIA', old.PROVINCIA, 'NOMBRE', old.NOMBRE, 'LAT', old.LAT, 'LNG', old.LNG, 'OFICIAL', old.OFICIAL, 'CLAVE', old.CLAVE, 'CREATED_AT', old.CREATED_AT));
END;

-- contrato ----------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_contrato_u AFTER UPDATE ON contrato BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'ID_BANDA', old.ID_BANDA, new.ID_BANDA
     WHERE old.ID_BANDA IS NOT new.ID_BANDA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'HERMANDAD', old.HERMANDAD, new.HERMANDAD
     WHERE old.HERMANDAD IS NOT new.HERMANDAD;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'HERMANDAD_SLUG', old.HERMANDAD_SLUG, new.HERMANDAD_SLUG
     WHERE old.HERMANDAD_SLUG IS NOT new.HERMANDAD_SLUG;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'TITULAR', old.TITULAR, new.TITULAR
     WHERE old.TITULAR IS NOT new.TITULAR;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'ANIO', old.ANIO, new.ANIO
     WHERE old.ANIO IS NOT new.ANIO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'FUENTE', old.FUENTE, new.FUENTE
     WHERE old.FUENTE IS NOT new.FUENTE;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'NOTA', old.NOTA, new.NOTA
     WHERE old.NOTA IS NOT new.NOTA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'contrato', new.ID_CONTRATO, NULL, 'CREATED_AT', old.CREATED_AT, new.CREATED_AT
     WHERE old.CREATED_AT IS NOT new.CREATED_AT;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_contrato_i AFTER INSERT ON contrato BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'contrato', new.ID_CONTRATO, NULL,
            json_object('ID_CONTRATO', new.ID_CONTRATO, 'ID_BANDA', new.ID_BANDA, 'HERMANDAD', new.HERMANDAD, 'HERMANDAD_SLUG', new.HERMANDAD_SLUG, 'TITULAR', new.TITULAR, 'ANIO', new.ANIO, 'FUENTE', new.FUENTE, 'NOTA', new.NOTA, 'CREATED_AT', new.CREATED_AT));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_contrato_d AFTER DELETE ON contrato BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'contrato', old.ID_CONTRATO, NULL,
            json_object('ID_CONTRATO', old.ID_CONTRATO, 'ID_BANDA', old.ID_BANDA, 'HERMANDAD', old.HERMANDAD, 'HERMANDAD_SLUG', old.HERMANDAD_SLUG, 'TITULAR', old.TITULAR, 'ANIO', old.ANIO, 'FUENTE', old.FUENTE, 'NOTA', old.NOTA, 'CREATED_AT', old.CREATED_AT));
END;

-- marcha_autor ------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_marcha_autor_u AFTER UPDATE ON marcha_autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha_autor', new.ID_MA, NULL, 'ID_MARCHA', old.ID_MARCHA, new.ID_MARCHA
     WHERE old.ID_MARCHA IS NOT new.ID_MARCHA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'marcha_autor', new.ID_MA, NULL, 'ID_AUTOR', old.ID_AUTOR, new.ID_AUTOR
     WHERE old.ID_AUTOR IS NOT new.ID_AUTOR;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_marcha_autor_i AFTER INSERT ON marcha_autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'marcha_autor', new.ID_MA, NULL,
            json_object('ID_MA', new.ID_MA, 'ID_MARCHA', new.ID_MARCHA, 'ID_AUTOR', new.ID_AUTOR));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_marcha_autor_d AFTER DELETE ON marcha_autor BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'marcha_autor', old.ID_MA, NULL,
            json_object('ID_MA', old.ID_MA, 'ID_MARCHA', old.ID_MARCHA, 'ID_AUTOR', old.ID_AUTOR));
END;

-- disco_marcha ------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_disco_marcha_u AFTER UPDATE ON disco_marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'ID_DISCO', old.ID_DISCO, new.ID_DISCO
     WHERE old.ID_DISCO IS NOT new.ID_DISCO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'N_DISCO', old.N_DISCO, new.N_DISCO
     WHERE old.N_DISCO IS NOT new.N_DISCO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'NUMEROMARCHA', old.NUMEROMARCHA, new.NUMEROMARCHA
     WHERE old.NUMEROMARCHA IS NOT new.NUMEROMARCHA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'IDMARCHA', old.IDMARCHA, new.IDMARCHA
     WHERE old.IDMARCHA IS NOT new.IDMARCHA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'DM_DETALLES', old.DM_DETALLES, new.DM_DETALLES
     WHERE old.DM_DETALLES IS NOT new.DM_DETALLES;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'DM_BANDA', old.DM_BANDA, new.DM_BANDA
     WHERE old.DM_BANDA IS NOT new.DM_BANDA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'DM_ENLAZADA', old.DM_ENLAZADA, new.DM_ENLAZADA
     WHERE old.DM_ENLAZADA IS NOT new.DM_ENLAZADA;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'DURACION_SEG', old.DURACION_SEG, new.DURACION_SEG
     WHERE old.DURACION_SEG IS NOT new.DURACION_SEG;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'disco_marcha', new.ID_DM, NULL, 'PERCUSION', old.PERCUSION, new.PERCUSION
     WHERE old.PERCUSION IS NOT new.PERCUSION;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_disco_marcha_i AFTER INSERT ON disco_marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'disco_marcha', new.ID_DM, NULL,
            json_object('ID_DM', new.ID_DM, 'ID_DISCO', new.ID_DISCO, 'N_DISCO', new.N_DISCO, 'NUMEROMARCHA', new.NUMEROMARCHA, 'IDMARCHA', new.IDMARCHA, 'DM_DETALLES', new.DM_DETALLES, 'DM_BANDA', new.DM_BANDA, 'DM_ENLAZADA', new.DM_ENLAZADA, 'DURACION_SEG', new.DURACION_SEG, 'PERCUSION', new.PERCUSION));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_disco_marcha_d AFTER DELETE ON disco_marcha BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'disco_marcha', old.ID_DM, NULL,
            json_object('ID_DM', old.ID_DM, 'ID_DISCO', old.ID_DISCO, 'N_DISCO', old.N_DISCO, 'NUMEROMARCHA', old.NUMEROMARCHA, 'IDMARCHA', old.IDMARCHA, 'DM_DETALLES', old.DM_DETALLES, 'DM_BANDA', old.DM_BANDA, 'DM_ENLAZADA', old.DM_ENLAZADA, 'DURACION_SEG', old.DURACION_SEG, 'PERCUSION', old.PERCUSION));
END;

-- banda_relacion ----------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_banda_relacion_u AFTER UPDATE ON banda_relacion BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'ID_ORIGEN', old.ID_ORIGEN, new.ID_ORIGEN
     WHERE old.ID_ORIGEN IS NOT new.ID_ORIGEN;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'ID_DESTINO', old.ID_DESTINO, new.ID_DESTINO
     WHERE old.ID_DESTINO IS NOT new.ID_DESTINO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'TIPO', old.TIPO, new.TIPO
     WHERE old.TIPO IS NOT new.TIPO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'FECHA_INICIO', old.FECHA_INICIO, new.FECHA_INICIO
     WHERE old.FECHA_INICIO IS NOT new.FECHA_INICIO;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'FECHA_FIN', old.FECHA_FIN, new.FECHA_FIN
     WHERE old.FECHA_FIN IS NOT new.FECHA_FIN;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'banda_relacion', new.ID_RELACION, NULL, 'NOTA', old.NOTA, new.NOTA
     WHERE old.NOTA IS NOT new.NOTA;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_banda_relacion_i AFTER INSERT ON banda_relacion BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'banda_relacion', new.ID_RELACION, NULL,
            json_object('ID_RELACION', new.ID_RELACION, 'ID_ORIGEN', new.ID_ORIGEN, 'ID_DESTINO', new.ID_DESTINO, 'TIPO', new.TIPO, 'FECHA_INICIO', new.FECHA_INICIO, 'FECHA_FIN', new.FECHA_FIN, 'NOTA', new.NOTA));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_banda_relacion_d AFTER DELETE ON banda_relacion BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'banda_relacion', old.ID_RELACION, NULL,
            json_object('ID_RELACION', old.ID_RELACION, 'ID_ORIGEN', old.ID_ORIGEN, 'ID_DESTINO', old.ID_DESTINO, 'TIPO', old.TIPO, 'FECHA_INICIO', old.FECHA_INICIO, 'FECHA_FIN', old.FECHA_FIN, 'NOTA', old.NOTA));
END;

-- usuarios ----------------------------------------------------
CREATE TRIGGER IF NOT EXISTS trg_log_usuarios_u AFTER UPDATE ON usuarios BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'usuarios', new.id, NULL, 'usuario', old.usuario, new.usuario
     WHERE old.usuario IS NOT new.usuario;
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)
    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
           'UPDATE', 'usuarios', new.id, NULL, 'ROL', old.ROL, new.ROL
     WHERE old.ROL IS NOT new.ROL;
END;

CREATE TRIGGER IF NOT EXISTS trg_log_usuarios_i AFTER INSERT ON usuarios BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'INSERT', 'usuarios', new.id, NULL,
            json_object('id', new.id, 'usuario', new.usuario, 'ROL', new.ROL));
END;

CREATE TRIGGER IF NOT EXISTS trg_log_usuarios_d AFTER DELETE ON usuarios BEGIN
  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)
    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),
            'DELETE', 'usuarios', old.id, NULL,
            json_object('id', old.id, 'usuario', old.usuario, 'ROL', old.ROL));
END;
