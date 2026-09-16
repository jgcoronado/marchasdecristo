-- Nómina de hermandades y sus pasos (N-03, construida por fin el 2026-09-07).
-- Hasta ahora una hermandad sólo existía *a través de* un contrato: `contrato`
-- guarda HERMANDAD como texto libre + HERMANDAD_SLUG para agrupar (ver
-- 005_contrato.sql, que ya anticipaba "cuando llegue N-03, migrar esta columna
-- a una referencia real"). Eso dejaba fuera dos cosas que sí queremos enseñar:
--   1. El orden real de la Semana Santa (día de salida y orden de paso por la
--      carrera oficial), que no cabía en ninguna columna.
--   2. Las hermandades que NO llevan CCTT/AM. Sin contrato no había fila, así
--      que eran invisibles; ahora se listan y la ficha dice "sin acompañamiento
--      de CCTT/AM hasta la fecha" (se deduce de la ausencia de contratos, no
--      hace falta columna que mantener sincronizada).
--
-- Foto fija de la Semana Santa de 2026: DIA y ORDEN son los de ese año, no un
-- histórico por temporada. Cambian cada año (en 2026 Córdoba movió cinco
-- hermandades y Sevilla reordenó el Domingo de Ramos), así que si algún día se
-- quiere la serie completa esto crece a `hermandad_nomina(ANIO, ...)`; mientras
-- tanto una tabla por localidad es lo mínimo que resuelve el problema.
--
-- ALCANCE: sólo pasos de Cristo. Los palios se descartan a propósito — el sitio
-- es "marchas de Cristo" y los palios ya se borraron de `contrato` el
-- 2026-08-29 (backup mdc-20260829-123724-pre-borrado-paso-virgen.db).
--
-- Idempotente (CREATE ... IF NOT EXISTS): lo aplica migrate_ingest.php, que
-- re-ejecuta todos los .sql a ciegas en cada despliegue.

-- LOCALIDAD es texto libre, igual que en contrato_localidad (009): el pipeline
-- procesa una localidad cada vez y no hay entidad `localidad` a la que apuntar.
-- SLUG = Slug::slugify(NOMBRE) y es la juntura con contrato.HERMANDAD_SLUG:
-- slugify() quita los diacríticos, así que corregir las tildes de NOMBRE no
-- rompe el enlace con los contratos ya cargados.
--
-- DIA_ORDEN ordena las jornadas (1 = la primera de la localidad) porque el
-- nombre del día no es ordenable ni es el mismo en todas partes: Jerez llama
-- "Noche de Jesús" a su madrugá y no tiene Viernes de Dolores.
--
-- ORDEN es el orden de paso por la carrera oficial dentro del día. Las vísperas
-- (Viernes de Dolores, Sábado de Pasión) no hacen carrera oficial: ahí ORDEN
-- refleja la hora de salida, que se guarda además en HORA_SALIDA para poder
-- justificar el orden. HORA_SALIDA es NULL en los días de carrera oficial.
CREATE TABLE IF NOT EXISTS hermandad (
    ID_HERMANDAD INTEGER PRIMARY KEY,
    LOCALIDAD    TEXT    NOT NULL,
    NOMBRE       TEXT    NOT NULL,             -- nombre popular, con tildes
    SLUG         TEXT    NOT NULL,             -- slugify(NOMBRE) = contrato.HERMANDAD_SLUG
    DIA          TEXT    NOT NULL,             -- 'Domingo de Ramos', 'Noche de Jesús', ...
    DIA_ORDEN    INTEGER NOT NULL,             -- 1..n, ordena las jornadas
    ORDEN        INTEGER NOT NULL,             -- orden en carrera oficial (vísperas: por hora)
    HORA_SALIDA  TEXT,                         -- 'HH:MM', sólo vísperas
    FUENTE       TEXT,                         -- de dónde salen DIA/ORDEN
    UNIQUE (LOCALIDAD, SLUG)
);
CREATE INDEX IF NOT EXISTS idx_hermandad_orden ON hermandad (LOCALIDAD, DIA_ORDEN, ORDEN);

-- Un paso por fila, sólo los de Cristo. NOMBRE es el nombre popular o el del
-- titular ("Nuestro Padre Jesús de la Sentencia"), NO la etiqueta genérica de
-- la fuente ("Paso de Misterio"): esa nomenclatura era incoherente entre
-- fuentes y dentro de la propia BD (había 15 variantes de TITULAR para lo
-- mismo, incluida una que arrastraba un comentario del foro de origen).
--
-- La cruz de guía NO es un paso, pero se lista porque es donde tocan casi
-- todas las agrupaciones juveniles. Va siempre la primera: ES_CRUZ_GUIA = 1 y
-- ORDEN = 0, y los pasos de verdad numeran desde 1.
CREATE TABLE IF NOT EXISTS paso (
    ID_PASO      INTEGER PRIMARY KEY,
    ID_HERMANDAD INTEGER NOT NULL REFERENCES hermandad(ID_HERMANDAD),
    NOMBRE       TEXT    NOT NULL,             -- nombre popular o del titular
    ORDEN        INTEGER NOT NULL,             -- 0 = cruz de guía, luego 1..n
    ES_CRUZ_GUIA INTEGER NOT NULL DEFAULT 0,
    UNIQUE (ID_HERMANDAD, NOMBRE)
);
CREATE INDEX IF NOT EXISTS idx_paso_hermandad ON paso (ID_HERMANDAD, ORDEN);

-- Juntura contrato -> paso. Tabla satélite en vez de columna nueva en
-- `contrato` por el mismo motivo que contrato_localidad (009): SQLite no tiene
-- "ADD COLUMN IF NOT EXISTS" y migrate_ingest.php re-ejecuta los .sql a ciegas.
--
-- Relación 1:1 opcional (PRIMARY KEY = ID_CONTRATO): un contrato sin fila aquí
-- es uno cuyo TITULAR no se ha podido casar con ningún paso — se sigue viendo
-- por el camino viejo (HERMANDAD_SLUG + TITULAR) en vez de desaparecer.
CREATE TABLE IF NOT EXISTS contrato_paso (
    ID_CONTRATO INTEGER PRIMARY KEY REFERENCES contrato(ID_CONTRATO),
    ID_PASO     INTEGER NOT NULL REFERENCES paso(ID_PASO)
);
CREATE INDEX IF NOT EXISTS idx_contrato_paso_paso ON contrato_paso (ID_PASO);
