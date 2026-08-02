-- ─────────────────────────────────────────────────────────────────────────────
-- Ingesta: fuentes de streaming + veto de descartes + deshacer último descarte
--
-- Contexto: la ingesta nació atada a YouTube (`tools/ingest/*.mjs`), pero los
-- candidatos pueden venir ya del catálogo de streaming de la banda (Spotify /
-- Deezer / Apple, ver `tools/music_links/descubrir_marchas.py`). Los campos
-- VIDEO_* del staging pasan a ser genéricos ("el origen"), y `ingest_candidato`
-- gana una columna FUENTE que dice de qué servicio viene cada fila (la añade
-- `migrate_ingest.php`, porque SQLite no tiene ADD COLUMN IF NOT EXISTS).
--
-- Se aplica con: php app/tools/migrate_ingest.php  (idempotente).
-- ─────────────────────────────────────────────────────────────────────────────

-- Veto de descartes: un candidato descartado a mano no debe volver a
-- proponerse nunca más, ni al reimportar el mismo lote ni en pasadas futuras
-- del descubridor. El veto es por **origen exacto** (servicio + id de la
-- pista/vídeo), que es la decisión tomada: la misma marcha vista en otro
-- servicio sí puede volver a proponerse (y ahí el revisor decide otra vez).
--
-- La fila sobrevive aunque se purgue `ingest_candidato` — es el registro
-- permanente de "esto ya se dijo que no".
CREATE TABLE IF NOT EXISTS ingest_veto (
  ID_VETO    INTEGER PRIMARY KEY,
  FUENTE     TEXT NOT NULL,                    -- youtube | spotify | deezer | apple | …
  FUENTE_ID  TEXT NOT NULL,                    -- id del origen (= ingest_candidato.VIDEO_ID)
  ID_BANDA   INTEGER REFERENCES banda(ID_BANDA) ON DELETE SET NULL,
  TITULO     TEXT,                             -- título propuesto, para poder leer el veto sin joins
  MOTIVO     TEXT,
  ID_CAND    INTEGER,                          -- candidato que lo originó (informativo; puede purgarse)
  USUARIO    TEXT,
  CREATED_AT TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_ingest_veto_origen ON ingest_veto (FUENTE, FUENTE_ID);
CREATE INDEX        IF NOT EXISTS ix_ingest_veto_banda  ON ingest_veto (ID_BANDA);

-- Último descarte, para el botón "deshacer" del panel (un solo paso: cada
-- descarte nuevo sustituye al anterior, y deshacer borra la fila). Guarda los
-- ids del descarte —uno o varios, porque el descarte masivo se deshace
-- entero— y el estado previo mínimo necesario para revertirlo.
CREATE TABLE IF NOT EXISTS ingest_descarte_ultimo (
  ID         INTEGER PRIMARY KEY CHECK (ID = 1), -- fila única
  IDS_JSON   TEXT    NOT NULL,                   -- JSON: [ID_CAND, …]
  N          INTEGER NOT NULL,
  USUARIO    TEXT,
  CREATED_AT TEXT    NOT NULL DEFAULT (datetime('now'))
);
