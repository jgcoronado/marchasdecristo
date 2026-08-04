<?php

declare(strict_types=1);

/*
 * Aplica las migraciones de la herramienta de ingesta (tablas de staging).
 * Idempotente: se puede ejecutar tantas veces como haga falta.
 *
 *   /usr/local/bin/php /home/USUARIO/app/tools/migrate_ingest.php
 *
 * En local, apuntando a una BD concreta:
 *   DB_PATH=data/mdc.db php php/app/tools/migrate_ingest.php
 *
 * Lee todos los .sql de app/tools/sql/ en orden alfabético y los ejecuta contra
 * la BD de `config.php` (respeta DB_PATH). Los .sql son CREATE ... IF NOT EXISTS,
 * así que re-ejecutar no rompe nada ni pierde datos.
 *
 * Las columnas añadidas a tablas ya existentes no caben en ese esquema (SQLite
 * no tiene ADD COLUMN IF NOT EXISTS): van al final de este script, guardadas
 * por un PRAGMA table_info que comprueba si ya están.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Migración abortada: no existe la BD en $db\n");
    exit(1);
}

/** Avisos no fatales (p. ej. un índice único que no cabe por duplicados). */
$avisos = [];

$sqlDir = __DIR__ . '/sql';
$files = glob($sqlDir . '/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    fwrite(STDERR, "Migración abortada: no hay .sql en $sqlDir\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    /** Columnas de una tabla, o [] si la tabla no existe. @return list<string> */
    $columnasDe = static function (string $tabla) use ($pdo): array {
        $existe = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$tabla'")->fetchColumn();
        if ($existe === false) return [];
        return $pdo->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_COLUMN, 1);
    };

    // ── Versión de la grabación en enlace_streaming ──────────────────────────
    // Va ANTES del bucle de .sql, al revés que el resto de columnas nuevas,
    // porque 010_enlace_unicos.sql indexa VERSION: si la columna no está puesta
    // todavía, el índice no se puede crear y la migración se quedaría a medias
    // hasta la siguiente pasada.
    $colsEnlace = $columnasDe('enlace_streaming');
    if ($colsEnlace !== []) {
        $versionCols = [
            'VERSION' => "TEXT NOT NULL DEFAULT 'actual'",  // 'original' | 'actual'
            'ANIO' => 'INTEGER',                            // año de la grabación enlazada
            'VERSION_AUTO' => 'INTEGER NOT NULL DEFAULT 1', // 0 = clasificada a mano
        ];
        foreach ($versionCols as $col => $def) {
            if (in_array($col, $colsEnlace, true)) continue;
            // Sin CHECK: SQLite no deja añadirlo por ALTER, y las filas que ya
            // había son todas de grabaciones modernas del catálogo de streaming,
            // que es justo lo que da el DEFAULT.
            $pdo->exec("ALTER TABLE enlace_streaming ADD COLUMN $col $def");
            echo "añadida: enlace_streaming.$col\n";
        }
    }

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            fwrite(STDERR, 'No se pudo leer ' . basename($file) . "\n");
            exit(1);
        }
        // Cada fichero es un lote de sentencias; PDO::exec ejecuta múltiples con ';'.
        try {
            $pdo->exec($sql);
            echo 'aplicado: ' . basename($file) . "\n";
        } catch (PDOException $e) {
            // Un lote puede fallar por una sola sentencia (el caso real: el
            // índice único de 010 contra una tabla que ya arrastra duplicados).
            // Se reintenta sentencia a sentencia para aplicar lo que sí cabe y
            // poder decir exactamente qué ha quedado fuera y por qué.
            echo 'parcial:  ' . basename($file) . ' — ' . $e->getMessage() . "\n";
            foreach (explode(";\n", $sql) as $sentencia) {
                // Fuera los comentarios de cabecera: si se descarta el trozo
                // entero por empezar con "--", se pierde la sentencia que va
                // detrás (el fichero empieza siempre explicándose).
                $sentencia = trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $sentencia));
                if ($sentencia === '') continue;
                try {
                    $pdo->exec($sentencia);
                } catch (PDOException $e2) {
                    $avisos[] = basename($file) . ': ' . $e2->getMessage();
                    $tabla = preg_match('/\bON\s+(\w+)\s*\(/i', $sentencia, $m) === 1 ? $m[1] : null;
                    $cols = preg_match('/\(([^)]+)\)\s*;?$/', $sentencia, $m2) === 1 ? trim($m2[1]) : null;
                    if ($tabla !== null && $cols !== null && str_contains($e2->getMessage(), 'UNIQUE')) {
                        // Los duplicados que impiden el índice, para poder
                        // resolverlos a mano: cuál sobra es decisión editorial.
                        $dups = $pdo->query(
                            "SELECT $cols, COUNT(*) AS n FROM $tabla GROUP BY $cols HAVING COUNT(*) > 1 LIMIT 20"
                        )->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($dups as $d) {
                            $avisos[] = '   duplicado en ' . $tabla . ': ' . implode(' · ', array_map(
                                static fn($k, $v): string => "$k=$v",
                                array_keys($d),
                                array_values($d)
                            ));
                        }
                    }
                }
            }
        }
    }

    // Columnas nuevas sobre tablas que ya existen. SQLite no tiene
    // "ALTER TABLE … ADD COLUMN IF NOT EXISTS" (menos aún la 3.34.1 del host),
    // así que se comprueba con PRAGMA table_info antes de añadir: así este
    // script sigue siendo idempotente sin necesidad de un registro de
    // migraciones aplicadas.
    $columnasNuevas = [
        'ingest_candidato' => [
            // De qué servicio viene el candidato. Las filas anteriores a la
            // ingesta de streaming son todas de YouTube, de ahí el DEFAULT.
            'FUENTE' => "TEXT NOT NULL DEFAULT 'youtube'",
            // Contexto del origen cuando la fuente es un catálogo de streaming:
            // disco al que pertenece la pista (nombre y enlace).
            'FUENTE_ALBUM' => 'TEXT',
            'FUENTE_ALBUM_URL' => 'TEXT',
            // Estilo propuesto (CCTT/AM) inferido por el descubridor.
            'P_ESTILO' => 'TEXT',
            // R-01 (roadmap): ISRC de la grabación, cuando el servicio lo da
            // gratis (Spotify, Deezer — Apple/YouTube no). Permite reconocer
            // la misma grabación aunque el título difiera entre catálogos;
            // ver tools/music_links/descubrir_marchas.py.
            'ISRC' => 'TEXT',
        ],
        // Mismo motivo que arriba: guardar el ISRC del enlace ya aceptado,
        // no solo del candidato pendiente, para que sobreviva a la curación.
        'enlace_streaming' => [
            'ISRC' => 'TEXT',
        ],
        // R-02 (roadmap): la duración es propiedad de la GRABACIÓN, no de la
        // obra — varía entre versiones/discos. marcha.DURACION_SEG se
        // mantiene tal cual, como referencia general; esta columna nueva
        // guarda la duración específica de cada aparición en disco.
        'disco_marcha' => [
            'DURACION_SEG' => 'INTEGER',
            // Excepción por pista al flag de percusión del disco:
            //   NULL = hereda de disco.PERCUSION (lo normal)
            //   0/1  = esta pista concreta se desvía de la norma del disco
            'PERCUSION' => 'INTEGER',
        ],
        // Muchas grabaciones abren con un fragmento de percusión (tambores)
        // antes de la marcha, de unos 37–42 s. Eso hace que la misma marcha
        // parezca ~40 s más larga en unos discos que en otros y contamina la
        // mediana de disco_marcha.DURACION_SEG.
        //
        // PERCUSION     = 1 si el disco abre sus pistas con esa intro.
        // PERCUSION_SEG = duración estimada de la intro, en segundos.
        //   Por defecto 40 (punto medio de 37–42: error medio 1,25 s, la mejor
        //   estimación posible sin medirla). Es EDITABLE por disco: si alguna
        //   se cronometra de verdad, se escribe aquí y deja de ser estimación.
        //   No se sortea un valor aleatorio a propósito — un aleatorio del
        //   mismo rango tiene MÁS error medio (1,67 s) y encima no es
        //   reproducible ni distinguible de un dato medido.
        'disco' => [
            'PERCUSION'     => 'INTEGER NOT NULL DEFAULT 0',
            'PERCUSION_SEG' => 'INTEGER NOT NULL DEFAULT 40',
        ],
    ];
    foreach ($columnasNuevas as $tabla => $columnas) {
        $actuales = $columnasDe($tabla);
        if ($actuales === []) continue;
        foreach ($columnas as $col => $def) {
            if (in_array($col, $actuales, true)) continue;
            $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
            echo "añadida: $tabla.$col\n";
        }
    }

    // ── Restricción de unicidad vieja en enlace_streaming ────────────────────
    // Las bases creadas por la primera versión de 004 llevan la UNIQUE
    // (TIPO_ENT, ID_ENT, SERVICIO) DENTRO del CREATE TABLE. Esa restricción
    // impide justo lo que la versión pretende: dos escuchas del mismo servicio,
    // una de época y otra actual. Una restricción de tabla no se puede quitar
    // con ALTER, así que hay que rehacer la tabla — la unicidad buena ya vive en
    // el índice de 010.
    //
    // El guardián es la propia restricción: en cuanto se ha quitado una vez,
    // este bloque no vuelve a entrar.
    $uniqueVieja = null;
    foreach ($pdo->query('PRAGMA index_list(enlace_streaming)')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        // origin 'u' = viene de una cláusula UNIQUE del CREATE TABLE.
        if ((int) ($idx['unique'] ?? 0) !== 1 || ($idx['origin'] ?? '') !== 'u') continue;
        $cols = $pdo->query("PRAGMA index_info('" . $idx['name'] . "')")->fetchAll(PDO::FETCH_COLUMN, 2);
        if ($cols === ['TIPO_ENT', 'ID_ENT', 'SERVICIO']) $uniqueVieja = $idx['name'];
    }
    if ($uniqueVieja !== null) {
        $cols = implode(', ', $columnasDe('enlace_streaming'));
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        // Se copia con la lista explícita de columnas, no con SELECT *, para no
        // depender del orden en que cada base las fue acumulando.
        $pdo->exec('ALTER TABLE enlace_streaming RENAME TO enlace_streaming__viejo');
        $pdo->exec("CREATE TABLE enlace_streaming (
            ID_ENLACE   INTEGER PRIMARY KEY,
            TIPO_ENT    TEXT    NOT NULL CHECK (TIPO_ENT IN ('banda','disco','marcha')),
            ID_ENT      INTEGER NOT NULL,
            SERVICIO    TEXT    NOT NULL CHECK (SERVICIO IN ('spotify','apple','deezer','youtube','tidal','amazon')),
            URL         TEXT    NOT NULL,
            ID_EXT      TEXT,
            ISRC        TEXT,
            VERSION     TEXT    NOT NULL DEFAULT 'actual' CHECK (VERSION IN ('original','actual')),
            ANIO        INTEGER,
            VERSION_AUTO INTEGER NOT NULL DEFAULT 1,
            VERIFICADO  INTEGER NOT NULL DEFAULT 1,
            FECHA_ALTA  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("INSERT INTO enlace_streaming ($cols) SELECT $cols FROM enlace_streaming__viejo");
        $pdo->exec('DROP TABLE enlace_streaming__viejo');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_enl_ent ON enlace_streaming (TIPO_ENT, ID_ENT)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_enlace_streaming_ent_srv_ver
                        ON enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, VERSION)');
        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
        echo "reconstruida: enlace_streaming (fuera la UNIQUE de tabla sin VERSION)\n";
    }

    // Verificación: listar las tablas ingest_* resultantes.
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ingest_%' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migración falló: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'OK. Tablas de ingesta: ' . (empty($tables) ? '(ninguna)' : implode(', ', $tables)) . "\n";

// Lo que no se ha podido aplicar se cuenta al final, no se esconde. El caso
// típico: la unicidad de enlace_streaming (migración 010) contra una base que
// ya tiene dos enlaces del mismo servicio para la misma entidad. Hasta que se
// resuelva, el panel sigue funcionando (comprueba la unicidad en PHP), pero la
// base no la garantiza.
if ($avisos !== []) {
    echo "\nAVISOS (nada se ha borrado; requieren decisión manual):\n";
    foreach ($avisos as $a) echo '  · ' . $a . "\n";
    echo "\nPara la unicidad de enlaces: revisa cada duplicado, borra el que sobre\n"
       . "y vuelve a ejecutar esta migración.\n";
}
