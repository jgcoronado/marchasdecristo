-- Cola de bandas sin enlazar de la carga de acompanamientos históricos de
-- Málaga desde malagamusical.blogspot.com (N-03, ver
-- docs/acompanamientos-nomina-2026.md y
-- php/app/tools/cargar_acompanamientos_malaga_blog.php).
--
-- `contrato.ID_BANDA` es NOT NULL: una banda CCTT/AM que aparece en el blog
-- pero no existe todavía en `banda` no se puede insertar ahí, así que se
-- guarda aquí como texto literal hasta que un admin la enlace a mano (o
-- confirme que hace falta dar de alta la banda). Solo visible en el panel
-- admin, nunca en la web pública.
--
-- Idempotente por (LOCALIDAD, HERMANDAD_SLUG, ID_PASO, ANIO, BANDA_TEXTO):
-- volver a cargar la misma etiqueta del blog no duplica la pendiente.
--
-- Idempotente (CREATE ... IF NOT EXISTS): lo aplica migrate_ingest.php, que
-- re-ejecuta todos los .sql a ciegas en cada despliegue.
CREATE TABLE IF NOT EXISTS acompanamiento_pendiente (
    ID_PENDIENTE   INTEGER PRIMARY KEY,
    LOCALIDAD      TEXT    NOT NULL,
    HERMANDAD_SLUG TEXT    NOT NULL,
    ID_PASO        INTEGER NOT NULL REFERENCES paso(ID_PASO),
    TITULAR        TEXT    NOT NULL,          -- = paso.NOMBRE, para no repetir el JOIN al listar
    ANIO           INTEGER NOT NULL,
    BANDA_TEXTO    TEXT    NOT NULL,          -- tal cual la escribe el blog
    FUENTE         TEXT    NOT NULL,
    CREATED_AT     TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (LOCALIDAD, HERMANDAD_SLUG, ID_PASO, ANIO, BANDA_TEXTO)
);
CREATE INDEX IF NOT EXISTS idx_acomp_pendiente_banda ON acompanamiento_pendiente (BANDA_TEXTO);
