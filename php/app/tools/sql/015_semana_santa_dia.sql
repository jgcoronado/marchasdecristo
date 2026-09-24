-- Los días de Semana Santa como entidad propia (panel /dashboard/semana-santa).
-- Hasta ahora un día solo existía *a través de* sus hermandades (hermandad.DIA
-- / DIA_ORDEN, ver 012_hermandad_paso.sql): no podía haber un día vacío, así
-- que dar de alta la primera hermandad de una jornada nueva era el único modo
-- de crearla, y reordenar los días exigía tocar todas sus hermandades a mano.
--
-- hermandad.DIA / DIA_ORDEN se MANTIENEN (la web pública los sigue leyendo tal
-- cual, ver Repo::acompanamientosPorLocalidad) y el panel los sincroniza en
-- cada escritura: esta tabla es la fuente de verdad del nombre/orden del día,
-- y hermandad guarda una copia desnormalizada para no tocar la lectura pública.
--
-- Backfill idempotente (INSERT OR IGNORE): agrupa las hermandades ya cargadas
-- por LOCALIDAD+DIA y toma el DIA_ORDEN mínimo que ya tenían asignado.
CREATE TABLE IF NOT EXISTS semana_santa_dia (
    ID_DIA    INTEGER PRIMARY KEY,
    LOCALIDAD TEXT    NOT NULL,
    NOMBRE    TEXT    NOT NULL,
    ORDEN     INTEGER NOT NULL,
    UNIQUE (LOCALIDAD, NOMBRE)
);
CREATE INDEX IF NOT EXISTS idx_semana_santa_dia_orden ON semana_santa_dia (LOCALIDAD, ORDEN);

INSERT OR IGNORE INTO semana_santa_dia (LOCALIDAD, NOMBRE, ORDEN)
SELECT LOCALIDAD, DIA, MIN(DIA_ORDEN) FROM hermandad GROUP BY LOCALIDAD, DIA;
