<?php

declare(strict_types=1);

/*
 * Construye una base de datos SQLite mínima y determinista para CI (lint +
 * smoke tests, ver ci_smoke.php). NO usa App\* — es un script standalone que
 * solo necesita PDO/SQLite, para no depender de que el resto de la app ya
 * cargue correctamente.
 *
 * Uso: php ci_fixture.php <ruta destino .db>
 */

$path = $argv[1] ?? null;
if ($path === null || $path === '') {
    fwrite(STDERR, "Uso: php ci_fixture.php <ruta destino .db>\n");
    exit(2);
}

@unlink($path);
@unlink($path . '-shm');
@unlink($path . '-wal');

$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec(<<<'SQL'
CREATE TABLE marcha (
  ID_MARCHA INTEGER PRIMARY KEY, TITULO TEXT, DEDICATORIA TEXT, LOCALIDAD TEXT,
  PROVINCIA TEXT, AUDIO TEXT, FECHA INTEGER, BANDA_ESTRENO INTEGER,
  DETALLES_MARCHA TEXT, TIPO TEXT, ESTILO TEXT, DURACION_SEG INTEGER
);
CREATE TABLE autor (
  ID_AUTOR INTEGER PRIMARY KEY, NOMBRE TEXT, APELLIDOS TEXT, NOMBRE_ART TEXT,
  F_NAC INTEGER, F_DEF INTEGER, LUGAR_NAC TEXT, BIO TEXT
);
CREATE TABLE banda (
  ID_BANDA INTEGER PRIMARY KEY, NOMBRE_BREVE TEXT, NOMBRE_COMPLETO TEXT,
  LOCALIDAD TEXT, PROVINCIA TEXT, FECHA_FUND INTEGER, FECHA_EXT INTEGER,
  FORMACION_ANT INTEGER, FORMACION_SIG INTEGER
);
CREATE TABLE disco (
  ID_DISCO INTEGER PRIMARY KEY, NOMBRE_CD TEXT, FECHA_CD TEXT,
  BANDADISCO INTEGER, d_DETALLES TEXT,
  -- Intro de percusión del disco (espejo de migrate_ingest.php). Repo::marcha
  -- las lee en la consulta de grabaciones: si faltan aquí, la ficha de marcha
  -- revienta en CI y no en local.
  PERCUSION INTEGER NOT NULL DEFAULT 0,
  PERCUSION_SEG INTEGER NOT NULL DEFAULT 40
);
CREATE TABLE marcha_autor (ID_MA INTEGER PRIMARY KEY, ID_MARCHA INTEGER, ID_AUTOR INTEGER);
CREATE TABLE disco_marcha (
  ID_DM INTEGER PRIMARY KEY, ID_DISCO INTEGER, IDMARCHA INTEGER,
  NUMEROMARCHA INTEGER, N_DISCO INTEGER, DM_BANDA INTEGER, DM_ENLAZADA INTEGER,
  DURACION_SEG INTEGER,  -- R-02: duración de la grabación (espejo de migrate_ingest.php)
  PERCUSION INTEGER      -- excepción por pista al flag del disco (NULL = hereda)
);
CREATE TABLE banda_relacion (
  ID_RELACION INTEGER PRIMARY KEY, TIPO TEXT, FECHA_INICIO INTEGER, FECHA_FIN INTEGER,
  NOTA TEXT, ID_ORIGEN INTEGER, ID_DESTINO INTEGER
);
CREATE TABLE dedicatoria (
  ID_DEDIC INTEGER PRIMARY KEY, NOMBRE TEXT, LOCALIDAD TEXT DEFAULT '',
  PROVINCIA TEXT, SLUG_KEY TEXT, PERSONAL INTEGER DEFAULT 0
);
CREATE TABLE dedicatoria_alias (ID_ALIAS INTEGER PRIMARY KEY, ID_DEDIC INTEGER, VARIANTE TEXT, LOCALIDAD TEXT DEFAULT '');
-- Espejo reducido de 001_ingest_staging.sql: solo las columnas que consulta la
-- app, pero con SUS nombres. La clave se llama ID_CAND, no ID: crear una marcha
-- reevalúa los candidatos pendientes (IngestaRepo::reevaluarTrasCrearMarcha) y
-- con la clave mal nombrada esa consulta reventaba solo contra la fixture.
CREATE TABLE ingest_candidato (
  ID_CAND INTEGER PRIMARY KEY, MARCHA_CREADA INTEGER, VIDEO_ID TEXT, VIDEO_URL TEXT,
  VIDEO_TITULO TEXT, P_TITULO TEXT, P_BANDA_ESTRENO INTEGER, ID_BANDA INTEGER,
  FUENTE TEXT NOT NULL DEFAULT 'youtube',
  ESTADO TEXT NOT NULL DEFAULT 'pendiente', MOTIVO TEXT,
  PUBLICADO_AT TEXT, REVIEWED_AT TEXT,
  ISRC TEXT  -- R-01: espejo de migrate_ingest.php
);
CREATE TABLE ingest_veto (
  ID_VETO INTEGER PRIMARY KEY, FUENTE TEXT NOT NULL, FUENTE_ID TEXT NOT NULL,
  ID_BANDA INTEGER, TITULO TEXT, MOTIVO TEXT, ID_CAND INTEGER, USUARIO TEXT,
  CREATED_AT TEXT DEFAULT (datetime('now'))
);
CREATE TABLE ingest_descarte_ultimo (
  ID INTEGER PRIMARY KEY, IDS_JSON TEXT NOT NULL, N INTEGER NOT NULL,
  USUARIO TEXT, CREATED_AT TEXT DEFAULT (datetime('now'))
);
-- Espejo de 004_enlace_streaming.sql en lo que la app usa. La unicidad incluye
-- VERSION: una marcha antigua tiene una escucha por versión (original/actual)
-- en cada servicio, así que la clave de 3 columnas impediría justo el caso que
-- la ficha quiere enseñar. Aquí va como UNIQUE de tabla en vez de como índice
-- (migración 010) porque el fixture crea la base entera de cero.
CREATE TABLE enlace_streaming (
  ID_ENLACE INTEGER PRIMARY KEY, TIPO_ENT TEXT, ID_ENT INTEGER, SERVICIO TEXT, URL TEXT,
  ID_EXT TEXT,        -- id nativo del servicio: de aquí sale el tracklist del álbum
  ISRC TEXT,
  VERSION TEXT NOT NULL DEFAULT 'actual',   -- 'original' | 'actual'
  ANIO INTEGER,                             -- año de la grabación enlazada
  VERSION_AUTO INTEGER NOT NULL DEFAULT 1,  -- 0 = versión fijada a mano
  VERIFICADO INTEGER NOT NULL DEFAULT 1,
  FECHA_ALTA TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (TIPO_ENT, ID_ENT, SERVICIO, VERSION)
);
-- Espejo reducido de 004_enlace_streaming.sql (§2). Igual que arriba: la clave
-- es ID_CAND y el UNIQUE es el que hace idempotente el INSERT OR IGNORE de la
-- cascada automática (App\EnlacesAuto).
CREATE TABLE enlace_candidato (
  ID_CAND INTEGER PRIMARY KEY, TIPO_ENT TEXT, ID_ENT INTEGER, SERVICIO TEXT, URL TEXT, ID_EXT TEXT,
  TITULO_ENC TEXT, ARTISTA_ENC TEXT, ANIO_ENC TEXT, SCORE REAL NOT NULL DEFAULT 0,
  CONFIANZA TEXT, ESTADO TEXT NOT NULL DEFAULT 'pendiente', RUN_ID TEXT,
  FECHA TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (TIPO_ENT, ID_ENT, SERVICIO, URL)
);
CREATE TABLE admin_log (ID INTEGER PRIMARY KEY, accion TEXT, tabla TEXT, id_registro INTEGER, usuario TEXT, ts INTEGER, payload TEXT);
CREATE TABLE contrato (
  ID_CONTRATO INTEGER PRIMARY KEY, ID_BANDA INTEGER, HERMANDAD TEXT, HERMANDAD_SLUG TEXT,
  TITULAR TEXT, ANIO INTEGER, FUENTE TEXT, NOTA TEXT, CREATED_AT TEXT DEFAULT (datetime('now'))
);
CREATE VIRTUAL TABLE marcha_fts USING fts5(TITULO, content=marcha, content_rowid=ID_MARCHA, tokenize="unicode61 remove_diacritics 2");
CREATE VIRTUAL TABLE autor_fts USING fts5(NOMBRE, APELLIDOS, NOMBRE_ART, content=autor, content_rowid=ID_AUTOR, tokenize="unicode61 remove_diacritics 2");
CREATE TABLE municipio (
    ID_MUNICIPIO INTEGER PRIMARY KEY,
    PROVINCIA    TEXT NOT NULL,
    NOMBRE       TEXT NOT NULL,
    LAT          REAL,
    LNG          REAL,
    OFICIAL      INTEGER NOT NULL DEFAULT 1,
    CLAVE        TEXT NOT NULL UNIQUE,
    CREATED_AT   TEXT DEFAULT (datetime('now'))
);
CREATE INDEX idx_municipio_provincia ON municipio (PROVINCIA, NOMBRE);
SQL);

$ins = static function (string $sql, array $rows) use ($pdo): void {
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $r) {
        $stmt->execute($r);
    }
};

$ins('INSERT INTO banda (ID_BANDA, NOMBRE_BREVE, NOMBRE_COMPLETO, LOCALIDAD, PROVINCIA, FECHA_FUND) VALUES (?,?,?,?,?,?)', [
    [1, 'Las Cigarreras', 'Banda de CCTT Ntra. Sra. de la Victoria (Las Cigarreras)', 'Sevilla', 'Sevilla', 1977],
    [2, 'Tres Caídas', 'Agrupación Musical Ntro. Padre Jesús de las Tres Caídas', 'Sevilla', 'Sevilla', 1984],
]);

$ins('INSERT INTO autor (ID_AUTOR, NOMBRE, APELLIDOS, F_NAC, F_DEF, LUGAR_NAC) VALUES (?,?,?,?,?,?)', [
    [1, 'José', 'García Pérez', 1950, null, 'Sevilla'],
    [2, 'Manuel', 'López Ruiz', 1962, null, 'Cádiz'],
    // Nombre con apóstrofe (M8): caso adversarial para el test de coherencia
    // canónica↔JSON-LD — con el slugify legado que Seo.php tenía antes de
    // unificarse con Slug.php, este nombre generaba una URL de JSON-LD
    // distinta de la canónica real.
    [3, 'Rafael', "O'Donnell", null, null, null],
]);

$ins('INSERT INTO marcha (ID_MARCHA, TITULO, DEDICATORIA, LOCALIDAD, PROVINCIA, AUDIO, FECHA, BANDA_ESTRENO, TIPO, ESTILO, DURACION_SEG) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
    [1, 'Consuelo Gitano', 'Hdad de los Gitanos', 'Sevilla', 'Sevilla', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 1995, 1, 'MARCHA', 'CCTT', 208],
    [2, 'La Madrugá', 'Hdad de los Gitanos', 'Sevilla', 'Sevilla', null, 1995, 1, 'MARCHA', 'CCTT', 195],
    [3, 'Costalero Bueno', null, 'Cádiz', 'Cádiz', null, 1995, 2, 'MARCHA', 'AM', 180],
    [4, 'Cristo de la Sangre', null, 'Sevilla', 'Sevilla', null, 1990, 2, 'MARCHA', 'AM', 200],
    [5, 'Reina de San Román', null, 'Sevilla', 'Sevilla', null, null, 1, 'MARCHA', null, null],
]);

$ins('INSERT INTO marcha_autor (ID_MARCHA, ID_AUTOR) VALUES (?,?)', [
    [1, 1], [2, 1], [3, 2], [3, 3], [4, 2], [5, 1],
]);

$ins('INSERT INTO disco (ID_DISCO, NOMBRE_CD, FECHA_CD, BANDADISCO) VALUES (?,?,?,?)', [
    [1, 'Sevilla Cofrade Vol. 1', '1996', 1],
]);
$ins('INSERT INTO disco_marcha (ID_DISCO, IDMARCHA, NUMEROMARCHA, N_DISCO) VALUES (?,?,?,?)', [
    [1, 1, 1, 1], [1, 2, 2, 1],
]);

$ins('INSERT INTO dedicatoria (ID_DEDIC, NOMBRE, LOCALIDAD, PROVINCIA, SLUG_KEY, PERSONAL) VALUES (?,?,?,?,?,?)', [
    [1, 'Hdad de los Gitanos', 'Sevilla', 'Sevilla', 'gitanos sevilla', 0],
]);
$ins('INSERT INTO dedicatoria_alias (ID_DEDIC, VARIANTE, LOCALIDAD) VALUES (?,?,?)', [
    [1, 'Hdad de los Gitanos', 'Sevilla'],
]);

$ins('INSERT INTO ingest_candidato (MARCHA_CREADA, VIDEO_ID, PUBLICADO_AT, REVIEWED_AT) VALUES (?,?,?,?)', [
    [1, 'dQw4w9WgXcQ', '2021-03-15', '2026-01-01'],
]);
// La marcha 1 es de 1995: pasa de los 25 años, así que su ficha separa versión
// original y actual (ver Html::escuchar). Se le dan enlaces de las DOS para que
// el smoke ejercite las pestañas y no solo la botonera plana.
// La 5 no tiene año de composición: sin él no hay "época" que distinguir y sale
// la botonera única, que es el otro camino.
$ins('INSERT INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, VERSION, ANIO) VALUES (?,?,?,?,?,?)', [
    ['marcha', 1, 'spotify', 'https://open.spotify.com/track/x', 'actual', 2022],
    ['marcha', 1, 'deezer', 'https://www.deezer.com/es/track/1', 'original', 1996],
    ['marcha', 5, 'spotify', 'https://open.spotify.com/track/y', 'actual', null],
]);

$ins('INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE) VALUES (?,?,?,?,?,?)', [
    [1, 'Hdad de los Gitanos', 'hdad-de-los-gitanos', 'Virgen de las Angustias', 2026, 'https://example.org/anuncio'],
    [2, 'Hdad de los Gitanos', 'hdad-de-los-gitanos', 'Cristo de la Salud', 2026, null],
]);

$ins('INSERT INTO municipio (PROVINCIA, NOMBRE, LAT, LNG, OFICIAL, CLAVE) VALUES (?,?,?,?,?,?)', [
    ['Sevilla', 'Sevilla', 37.3891, -5.9845, 1, 'sevilla|sevilla'],
    ['Cádiz', 'Cádiz', 36.5297, -6.2925, 1, 'cadiz|cadiz'],
]);

$pdo->exec('INSERT INTO marcha_fts(rowid, TITULO) SELECT ID_MARCHA, TITULO FROM marcha');
$pdo->exec('INSERT INTO autor_fts(rowid, NOMBRE, APELLIDOS, NOMBRE_ART) SELECT ID_AUTOR, NOMBRE, APELLIDOS, NOMBRE_ART FROM autor');

fwrite(STDOUT, "fixture OK: $path\n");
