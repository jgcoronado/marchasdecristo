-- Una hermandad en más de un día (2026-09-29). Hasta ahora hermandad.DIA era
-- EL día de la hermandad (012), y eso no cubría dos casos reales:
--   1. Salir dos días la misma Semana Santa (un paso un día, otro paso otro).
--   2. Haber cambiado de día con los años (El Carmen de Sevilla salía el
--      Viernes de Dolores hasta ~2005 y hoy sale el Miércoles Santo): con el día
--      actual para todo el histórico, los acompañamientos antiguos caían en un
--      día que no era y aparecían choques falsos de una banda en dos hermandades
--      el mismo día.
--
-- hermandad.DIA / DIA_ORDEN / ORDEN siguen siendo el día PRINCIPAL (el actual)
-- y no cambian de significado: lo que ya los lee sigue igual. Lo nuevo va en
-- tablas satélite, por el mismo motivo que contrato_paso (012): SQLite no tiene
-- "ADD COLUMN IF NOT EXISTS" y migrate_ingest.php re-ejecuta los .sql a ciegas.

-- Caso 1: otros días en que sale la hermandad la Semana Santa actual, con su
-- orden de paso en ese día (el ORDEN de hermandad es el del día principal).
-- Nunca el día principal: eso sigue en hermandad.DIA.
CREATE TABLE IF NOT EXISTS hermandad_dia_extra (
    ID_HERMANDAD INTEGER NOT NULL REFERENCES hermandad(ID_HERMANDAD),
    ID_DIA       INTEGER NOT NULL REFERENCES semana_santa_dia(ID_DIA),
    ORDEN        INTEGER NOT NULL,
    PRIMARY KEY (ID_HERMANDAD, ID_DIA)
);
CREATE INDEX IF NOT EXISTS idx_hermandad_dia_extra_dia ON hermandad_dia_extra (ID_DIA, ORDEN);

-- Qué paso sale en un día extra. Sin fila, el paso sale el día principal de su
-- hermandad. ID_DIA es siempre uno de hermandad_dia_extra de su hermandad.
CREATE TABLE IF NOT EXISTS paso_dia (
    ID_PASO INTEGER PRIMARY KEY REFERENCES paso(ID_PASO),
    ID_DIA  INTEGER NOT NULL REFERENCES semana_santa_dia(ID_DIA)
);

-- Caso 2: en los años DESDE..HASTA (ambos incluidos) la hermandad salía el
-- día ID_DIA en vez del principal. Afecta a los pasos del día principal (los
-- de un día extra no cambian). Fuera de esos rangos manda hermandad.DIA.
CREATE TABLE IF NOT EXISTS hermandad_dia_historico (
    ID_HISTORICO INTEGER PRIMARY KEY,
    ID_HERMANDAD INTEGER NOT NULL REFERENCES hermandad(ID_HERMANDAD),
    ID_DIA       INTEGER NOT NULL REFERENCES semana_santa_dia(ID_DIA),
    DESDE        INTEGER NOT NULL,
    HASTA        INTEGER NOT NULL,
    CHECK (DESDE <= HASTA)
);
CREATE INDEX IF NOT EXISTS idx_hermandad_dia_historico ON hermandad_dia_historico (ID_HERMANDAD, DESDE);
