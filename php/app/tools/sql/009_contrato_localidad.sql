-- Localidad del ACOMPAÑAMIENTO (la ciudad de cuya Semana Santa procede ese
-- contrato — Sevilla, Málaga, ...), no la localidad de la banda contratada.
-- Son cosas distintas: una hermandad de Sevilla capital puede contratar una
-- banda de otra localidad, y agrupar /temporada/{año} por la LOCALIDAD de la
-- banda la coloca en el sitio equivocado.
--
-- NOTA 2026-07-31: este fichero llegó al repo como "006_contrato_localidad.sql"
-- (instalador local `instalar_temporada_2026.php`, 2026-07-27), pero ese
-- número ya lo ocupaba "006_sync_dedicatoria_alias_localidad.sql" (fusionado
-- el 2026-07-28) y nunca se llegó a commitear — se renumera aquí a 009 (el
-- siguiente libre tras 008_ingest_streaming.sql). El comentario original decía
-- "ver Pages::temporada, ya corregido para leer esta tabla en su lugar": **eso
-- no es cierto en el código actual** — Repo::temporada() sigue agrupando por
-- b.LOCALIDAD (localidad de la banda), no por esta tabla. La tabla existe en
-- el `mdc.db` local (creada al ejecutar el instalador) con las 92 filas de
-- `contrato` de la carga de Sevilla 2026, pero la columna quedó sin
-- poblar y sin usar — ver `docs/technical-debt.md` §2.2 para el detalle y la
-- decisión pendiente de si merece la pena terminarlo.
--
-- Tabla satélite en vez de columna nueva en `contrato`: SQLite no tiene
-- "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" y migrate_ingest.php re-ejecuta
-- todos los .sql a ciegas en cada despliegue (mismo motivo por el que
-- 002/003/004/005 son todas CREATE TABLE, nunca ALTER sobre una tabla
-- existente). PRIMARY KEY = ID_CONTRATO -> relación 1:1 opcional: un contrato
-- sin fila aquí simplemente no tiene localidad conocida ('Sin localidad' en
-- la plantilla, si algún día se usa), en vez de forzar NOT NULL en el alta.
--
-- Texto libre, mismo espíritu que HERMANDAD (sin FK a una entidad `localidad`
-- que no existe): el pipeline de acompañamientos procesa una localidad a la
-- vez, así que se conoce con certeza al cargar cada CSV y se podría pasar
-- directa a AdminRepo::addContrato si se retoma este trabajo.
--
-- Idempotente (CREATE ... IF NOT EXISTS): seguro re-ejecutar desde migrate_ingest.php.
CREATE TABLE IF NOT EXISTS contrato_localidad (
    ID_CONTRATO INTEGER PRIMARY KEY REFERENCES contrato(ID_CONTRATO),
    LOCALIDAD   TEXT NOT NULL
);
