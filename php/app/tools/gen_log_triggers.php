<?php

declare(strict_types=1);

/*
 * Genera php/app/tools/sql/011_cambio_log.sql: la tabla cambio_log, la tabla
 * log_actor y tres triggers (I/U/D) por cada tabla del alcance, leídos vía
 * PRAGMA table_info en vez de escritos a mano (~88 columnas entre las 12
 * tablas). Ver docs/plan-log-cambios.md.
 *
 * El .sql generado se commitea; este script solo se vuelve a ejecutar cuando
 * cambia el esquema de alguna tabla del alcance (columna añadida/renombrada).
 *
 * Uso:
 *   php php/app/tools/gen_log_triggers.php
 *   DB_PATH=/ruta/a/mdc.db php php/app/tools/gen_log_triggers.php
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Abortado: no existe la BD en $db\n");
    exit(1);
}

/**
 * Alcance: tabla => [pk entera | null, columnas de clave textual (si no hay
 * PK entera), columnas excluidas del log].
 *
 * @var array<string,array{pk:?string,clave:list<string>,excluir:list<string>}>
 */
$alcance = [
    'marcha'             => ['pk' => 'ID_MARCHA',   'clave' => [], 'excluir' => []],
    'autor'              => ['pk' => 'ID_AUTOR',    'clave' => [], 'excluir' => []],
    'banda'              => ['pk' => 'ID_BANDA',    'clave' => [], 'excluir' => []],
    'disco'              => ['pk' => 'ID_DISCO',    'clave' => [], 'excluir' => []],
    'dedicatoria'        => ['pk' => 'ID_DEDIC',    'clave' => [], 'excluir' => []],
    'dedicatoria_alias'  => ['pk' => null,          'clave' => ['VARIANTE', 'LOCALIDAD'], 'excluir' => []],
    'municipio'          => ['pk' => 'ID_MUNICIPIO', 'clave' => [], 'excluir' => []],
    'contrato'           => ['pk' => 'ID_CONTRATO', 'clave' => [], 'excluir' => []],
    'marcha_autor'       => ['pk' => 'ID_MA',       'clave' => [], 'excluir' => []],
    'disco_marcha'       => ['pk' => 'ID_DM',       'clave' => [], 'excluir' => []],
    'banda_relacion'     => ['pk' => 'ID_RELACION', 'clave' => [], 'excluir' => []],
    'usuarios'           => ['pk' => 'id',          'clave' => [], 'excluir' => ['clave']],
];

$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$out = <<<'SQL'
-- Migración 011_cambio_log.sql — GENERADO por php/app/tools/gen_log_triggers.php
-- No editar a mano: cambia el esquema de una tabla del alcance y vuelve a
-- ejecutar el generador. Ver docs/plan-log-cambios.md.

CREATE TABLE IF NOT EXISTS cambio_log (
  ID          INTEGER PRIMARY KEY,
  TS          INTEGER NOT NULL,   -- epoch UTC (mismo formato que admin_log.ts)
  ACTOR       TEXT    NOT NULL,   -- 'jaguerra' | 'cli:fill_enlaces_streaming' | 'py:mdc_music'
  ACCION      TEXT    NOT NULL,   -- INSERT | UPDATE | DELETE
  TABLA       TEXT    NOT NULL,
  ID_REGISTRO INTEGER,            -- PK entera de la fila afectada
  CLAVE       TEXT,               -- PK textual, solo para dedicatoria_alias
  CAMPO       TEXT,               -- columna modificada; NULL en INSERT/DELETE
  ANTES       TEXT,
  DESPUES     TEXT
);

CREATE INDEX IF NOT EXISTS idx_cambio_log_reg   ON cambio_log (TABLA, ID_REGISTRO, TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_ts    ON cambio_log (TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_actor ON cambio_log (ACTOR, TS);
CREATE INDEX IF NOT EXISTS idx_cambio_log_campo ON cambio_log (TABLA, CAMPO, TS);

-- Actor de la conexión actual. Fila única, reescrita antes de la primera
-- escritura de cada petición/script (Db::syncActor()).
CREATE TABLE IF NOT EXISTS log_actor (
  ID    INTEGER PRIMARY KEY CHECK (ID = 1),
  ACTOR TEXT NOT NULL
);
INSERT OR IGNORE INTO log_actor (ID, ACTOR) VALUES (1, 'desconocido');

SQL;

/** SQL para citar un identificador de columna dentro de json_object(). */
$jsonObject = static function (array $cols, string $prefix): string {
    $partes = [];
    foreach ($cols as $c) {
        $partes[] = "'{$c}', {$prefix}.{$c}";
    }
    return 'json_object(' . implode(', ', $partes) . ')';
};

foreach ($alcance as $tabla => $def) {
    $stTableInfo = $pdo->query("PRAGMA table_info({$tabla})");
    $cols = $stTableInfo !== false ? $stTableInfo->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($cols === []) {
        fwrite(STDERR, "Aviso: tabla '{$tabla}' no encontrada en la BD, se omite.\n");
        continue;
    }

    $pk = $def['pk'];
    $claveCols = $def['clave'];
    $excluir = $def['excluir'];

    $todasCols = array_column($cols, 'name');
    $snapshotCols = array_values(array_diff($todasCols, $excluir));

    // Columnas "de negocio" a vigilar en UPDATE: todo menos la PK entera,
    // las columnas de clave textual, y las excluidas.
    $pkCols = $pk !== null ? [$pk] : $claveCols;
    $campoCols = array_values(array_diff($todasCols, $pkCols, $claveCols, $excluir));

    $idRegNew = $pk !== null ? "new.{$pk}" : 'NULL';
    $idRegOld = $pk !== null ? "old.{$pk}" : 'NULL';
    if ($claveCols !== []) {
        $claveExprNew = implode(" || '|' || ", array_map(static fn ($c) => "new.{$c}", $claveCols));
        $claveExprOld = implode(" || '|' || ", array_map(static fn ($c) => "old.{$c}", $claveCols));
    } else {
        $claveExprNew = 'NULL';
        $claveExprOld = 'NULL';
    }

    $out .= "\n-- {$tabla} " . str_repeat('-', max(1, 60 - strlen($tabla))) . "\n";

    // UPDATE: una fila por columna realmente modificada.
    $out .= "CREATE TRIGGER IF NOT EXISTS trg_log_{$tabla}_u AFTER UPDATE ON {$tabla} BEGIN\n";
    foreach ($campoCols as $c) {
        $out .= "  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, CAMPO, ANTES, DESPUES)\n";
        $out .= "    SELECT strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),\n";
        $out .= "           'UPDATE', '{$tabla}', {$idRegNew}, {$claveExprNew}, '{$c}', old.{$c}, new.{$c}\n";
        $out .= "     WHERE old.{$c} IS NOT new.{$c};\n";
    }
    $out .= "END;\n\n";

    // INSERT: snapshot JSON completo de la fila.
    $out .= "CREATE TRIGGER IF NOT EXISTS trg_log_{$tabla}_i AFTER INSERT ON {$tabla} BEGIN\n";
    $out .= "  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, DESPUES)\n";
    $out .= "    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),\n";
    $out .= "            'INSERT', '{$tabla}', {$idRegNew}, {$claveExprNew},\n";
    $out .= '            ' . $jsonObject($snapshotCols, 'new') . ");\n";
    $out .= "END;\n\n";

    // DELETE: snapshot JSON completo de la fila borrada.
    $out .= "CREATE TRIGGER IF NOT EXISTS trg_log_{$tabla}_d AFTER DELETE ON {$tabla} BEGIN\n";
    $out .= "  INSERT INTO cambio_log (TS, ACTOR, ACCION, TABLA, ID_REGISTRO, CLAVE, ANTES)\n";
    $out .= "    VALUES (strftime('%s','now'), (SELECT ACTOR FROM log_actor WHERE ID=1),\n";
    $out .= "            'DELETE', '{$tabla}', {$idRegOld}, {$claveExprOld},\n";
    $out .= '            ' . $jsonObject($snapshotCols, 'old') . ");\n";
    $out .= "END;\n";
}

$outFile = __DIR__ . '/sql/011_cambio_log.sql';
file_put_contents($outFile, $out);
echo "Generado: {$outFile} (" . strlen($out) . " bytes)\n";
