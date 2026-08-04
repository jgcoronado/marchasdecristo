-- Unicidad de los enlaces de streaming, como ÍNDICE en vez de como restricción
-- de tabla.
--
-- Por qué hace falta: 004_enlace_streaming.sql declara UNIQUE dentro del CREATE
-- TABLE, pero con `IF NOT EXISTS`. En las bases donde esas tablas ya existían
-- (creadas antes por el pipeline de music_links) el CREATE no hizo nada y la
-- restricción nunca llegó. Ahí cualquier UPSERT falla con «ON CONFLICT clause
-- does not match any PRIMARY KEY or UNIQUE constraint», y un INSERT OR IGNORE
-- no ignora nada: acumula duplicados en silencio.
--
-- Un índice único sí se puede añadir a una tabla existente, y para SQLite tiene
-- el mismo valor que la restricción (ON CONFLICT lo reconoce igual).
--
-- Idempotente. Si la tabla arrastra duplicados, la creación del índice falla y
-- migrate_ingest.php lo explica y los lista, sin borrar nada por su cuenta: qué
-- fila sobra es una decisión editorial, no automática.

CREATE UNIQUE INDEX IF NOT EXISTS ux_enlace_streaming_ent_srv
    ON enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO);

CREATE UNIQUE INDEX IF NOT EXISTS ux_enlace_candidato_ent_srv_url
    ON enlace_candidato (TIPO_ENT, ID_ENT, SERVICIO, URL);
