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

    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            fwrite(STDERR, 'No se pudo leer ' . basename($file) . "\n");
            exit(1);
        }
        // Cada fichero es un lote de sentencias; PDO::exec ejecuta múltiples con ';'.
        $pdo->exec($sql);
        echo 'aplicado: ' . basename($file) . "\n";
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
        $existe = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$tabla'")->fetchColumn();
        if ($existe === false) continue;
        $actuales = $pdo->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach ($columnas as $col => $def) {
            if (in_array($col, $actuales, true)) continue;
            $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
            echo "añadida: $tabla.$col\n";
        }
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
