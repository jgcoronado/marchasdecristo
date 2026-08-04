-- Enlaces a servicios de streaming (Spotify, Apple, Deezer, YouTube, ...).
-- Modelo genérico: una fila enlaza CUALQUIER entidad (banda, disco o marcha)
-- con UN servicio. Así las 3 fases del proyecto (discos, bandas, singles/marchas)
-- y cualquier servicio nuevo caben sin volver a tocar el esquema.
--
--   TIPO_ENT = 'banda'  -> ID_ENT referencia banda(ID_BANDA)    (página de artista)
--   TIPO_ENT = 'disco'  -> ID_ENT referencia disco(ID_DISCO)    (álbum)
--   TIPO_ENT = 'marcha' -> ID_ENT referencia marcha(ID_MARCHA)  (single / pista)
--
-- Nota: marcha.AUDIO ya guarda 1 URL de YouTube por marcha. Esta tabla es
-- aditiva; la migración de esos valores a (marcha, youtube) es opcional (ver plan).
--
-- Idempotente (CREATE ... IF NOT EXISTS): seguro re-ejecutar desde migrate_ingest.php.

-- 1) Enlaces aprobados / publicados (lo que consume la ficha pública).
--
-- VERSION: una marcha con muchos años se interpreta hoy de forma muy distinta a
-- como sonaba al estrenarse, así que su ficha separa las escuchas en "versión
-- original" (grabaciones de la época) y "versión actual". Por eso la unicidad
-- incluye VERSION: una misma marcha puede tener DOS enlaces de Spotify, uno por
-- versión. Para banda y disco el concepto no aplica y todas las filas se quedan
-- en el DEFAULT 'actual', que reproduce el comportamiento anterior.
--
-- ANIO / VERSION_AUTO: la versión se DERIVA del año de la grabación enlazada
-- (ver EnlaceRepo::versionDeAnio) y esa derivación se recalcula al reingestar.
-- VERSION_AUTO = 0 marca las filas que un administrador clasificó a mano, para
-- que ningún recálculo automático las pise.
--
-- La unicidad NO va aquí dentro: es el índice de 010_enlace_unicos.sql. Un
-- CREATE TABLE IF NOT EXISTS no toca las bases donde la tabla ya existía, así
-- que declararla aquí no garantizaba nada (ver el porqué en esa migración).
CREATE TABLE IF NOT EXISTS enlace_streaming (
    ID_ENLACE   INTEGER PRIMARY KEY,
    TIPO_ENT    TEXT    NOT NULL CHECK (TIPO_ENT IN ('banda','disco','marcha')),
    ID_ENT      INTEGER NOT NULL,
    SERVICIO    TEXT    NOT NULL CHECK (SERVICIO IN ('spotify','apple','deezer','youtube','tidal','amazon')),
    URL         TEXT    NOT NULL,
    ID_EXT      TEXT,                       -- id nativo del servicio (album/artist/track)
    ISRC        TEXT,                       -- ISRC de la grabación, si el servicio lo da
    VERSION     TEXT    NOT NULL DEFAULT 'actual' CHECK (VERSION IN ('original','actual')),
    ANIO        INTEGER,                    -- año de la grabación enlazada (deriva VERSION)
    VERSION_AUTO INTEGER NOT NULL DEFAULT 1,-- 1 = versión derivada; 0 = fijada a mano
    VERIFICADO  INTEGER NOT NULL DEFAULT 1, -- 1 = revisado por admin; 0 = auto sin revisar
    FECHA_ALTA  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_enl_ent ON enlace_streaming (TIPO_ENT, ID_ENT);

-- 2) Cola de candidatos pendientes de curación (igual patrón que ingest_candidato).
--    El pipeline escribe aquí; el panel admin aprueba -> pasa a enlace_streaming.
CREATE TABLE IF NOT EXISTS enlace_candidato (
    ID_CAND     INTEGER PRIMARY KEY,
    TIPO_ENT    TEXT    NOT NULL CHECK (TIPO_ENT IN ('banda','disco','marcha')),
    ID_ENT      INTEGER NOT NULL,
    SERVICIO    TEXT    NOT NULL CHECK (SERVICIO IN ('spotify','apple','deezer','youtube','tidal','amazon')),
    URL         TEXT    NOT NULL,
    ID_EXT      TEXT,
    TITULO_ENC  TEXT,                        -- título devuelto por el servicio
    ARTISTA_ENC TEXT,                        -- artista devuelto por el servicio
    ANIO_ENC    TEXT,
    SCORE       REAL    NOT NULL DEFAULT 0,
    CONFIANZA   TEXT    NOT NULL CHECK (CONFIANZA IN ('ALTA','MEDIA','BAJA','SIN_MATCH')),
    ESTADO      TEXT    NOT NULL DEFAULT 'pendiente' CHECK (ESTADO IN ('pendiente','aprobado','rechazado')),
    RUN_ID      TEXT,                         -- lote de ejecución
    FECHA       TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (TIPO_ENT, ID_ENT, SERVICIO, URL)
);
CREATE INDEX IF NOT EXISTS idx_cand_estado ON enlace_candidato (ESTADO);
CREATE INDEX IF NOT EXISTS idx_cand_ent    ON enlace_candidato (TIPO_ENT, ID_ENT);
