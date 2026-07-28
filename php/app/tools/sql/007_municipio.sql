-- Catálogo de municipios (localidad + provincia) para los desplegables del
-- panel de administración y para los puntos del mapa.
--
-- Antes, marcha.LOCALIDAD / banda.LOCALIDAD / dedicatoria.LOCALIDAD eran texto
-- libre: de ahí las variantes de capitalización que hubo que limpiar a mano
-- (ver app/tools/normalizar_localidades.php). Con esta tabla el panel ofrece un
-- listado cerrado con predictivo, y una localidad determina siempre su
-- provincia — que es la realidad: un municipio pertenece a una sola provincia.
--
-- Fuente de verdad: esta tabla. app/geo/municipios_es.php es solo la SEMILLA
-- (los 8.112 municipios oficiales con sus coordenadas); app/tools/
-- seed_municipios.php la vuelca aquí y añade además los pares (LOCALIDAD,
-- PROVINCIA) que ya usaban las fichas, marcados OFICIAL = 0 para que nada deje
-- de poder guardarse el primer día y puedas depurarlos cuando quieras.
--
-- CLAVE: par normalizado (sin acentos, minúsculas) "provincia|municipio", con
-- UNIQUE, para que no entren dos veces "Cádiz|Jerez de la Frontera" y
-- "cadiz|jerez de la frontera". Se calcula en PHP (App\MunicipioRepo::clave)
-- porque NOACC() es una función de aplicación y SQLite no admite funciones
-- propias dentro de un índice — mismo patrón que dedicatoria.SLUG_KEY.
CREATE TABLE IF NOT EXISTS municipio (
    ID_MUNICIPIO INTEGER PRIMARY KEY,
    PROVINCIA    TEXT NOT NULL,
    NOMBRE       TEXT NOT NULL,
    LAT          REAL,                      -- NULL en altas manuales sin coordenadas
    LNG          REAL,
    OFICIAL      INTEGER NOT NULL DEFAULT 1, -- 1 = del listado INE; 0 = heredado de datos antiguos o alta manual
    CLAVE        TEXT NOT NULL UNIQUE,
    CREATED_AT   TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_municipio_provincia ON municipio (PROVINCIA, NOMBRE);
