<?php

declare(strict_types=1);

/*
 * Normaliza a forma Unicode NFC el texto de la BD.
 *
 * El problema: una tilde puede escribirse de dos formas que se ven idénticas
 * en pantalla pero tienen bytes distintos — precompuesta ("í", U+00ED) o
 * descompuesta ("i" + U+0301, acento combinante). Los títulos que entraron por
 * la ingesta de YouTube traían la forma descompuesta (los metadatos del vídeo
 * respetan la forma de origen) y se guardaron así.
 *
 * Por qué importa: el acento combinante no es una letra para el troceador de
 * tokens, así que partía la palabra en dos ("Lágrimas" → "La" + "grimas") y la
 * marcha no aparecía al buscarla por su propio título. En la BD de producción
 * afectaba a 3 marchas (Cinco Lágrimas, La Negación, No Caer en la tentación).
 *
 * La búsqueda ya es inmune desde Repo::ftsTokens() (normaliza la consulta) y
 * las altas nuevas nacen en NFC desde AdminRepo::normalize(); este script
 * arregla lo que ya está guardado, para que el dato sea uniforme y no haya
 * duplicados invisibles.
 *
 * Al escribir en `marcha` y `autor` saltan los triggers que mantienen
 * marcha_fts / autor_fts, así que el índice queda al día sin reconstruirlo.
 *
 * Re-ejecutable: solo toca las filas cuyo valor cambie al normalizar.
 *
 * Uso:
 *   php php/app/tools/normalizar_unicode_nfc.php            # aplica
 *   php php/app/tools/normalizar_unicode_nfc.php --dry-run  # solo informa
 *   DB_PATH=/ruta/a/mdc.db php .../normalizar_unicode_nfc.php
 *
 * Hace copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay algo
 * que normalizar.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

$dryRun = in_array('--dry-run', $argv, true);

if (!class_exists('Normalizer')) {
    fwrite(STDERR, "Abortado: falta la extensión intl (Normalizer).\n");
    exit(1);
}
if (!is_file($db)) {
    fwrite(STDERR, "Abortado: no existe la BD en $db\n");
    exit(1);
}

/*
 * Columnas de texto libre visibles o buscables, con su clave primaria.
 * Se dejan fuera a propósito: URLs e ids externos (ASCII), y los campos de
 * auditoría en crudo (ingest_candidato.RAW_JSON, VIDEO_DESC), que deben
 * conservarse tal cual llegaron de la fuente.
 */
$OBJETIVO = [
    'marcha'            => ['pk' => 'ID_MARCHA', 'cols' => ['TITULO', 'DETALLES_MARCHA', 'DEDICATORIA', 'LOCALIDAD', 'PROVINCIA', 'BANDA_ESTRENO']],
    'autor'             => ['pk' => 'ID_AUTOR',  'cols' => ['APELLIDOS', 'NOMBRE', 'NOMBRE_ART', 'LUGAR_NAC', 'BIO']],
    'banda'             => ['pk' => 'ID_BANDA',  'cols' => ['NOMBRE_COMPLETO', 'NOMBRE_BREVE', 'LOCALIDAD', 'PROVINCIA', 'DIRECTOR_ACTUAL', 'DIR_MUS_ACTUAL']],
    'disco'             => ['pk' => 'ID_DISCO',  'cols' => ['NOMBRE_CD', 'D_DETALLES']],
    'dedicatoria'       => ['pk' => 'ID_DEDIC',  'cols' => ['NOMBRE', 'LOCALIDAD', 'PROVINCIA']],
    'ingest_candidato'  => ['pk' => 'ID_CAND',   'cols' => ['VIDEO_TITULO', 'P_TITULO', 'P_DEDICATORIA', 'P_LOCALIDAD', 'P_PROVINCIA', 'P_AUTORES', 'P_BANDA_ESTRENO', 'MOTIVO']],
    'ingest_veto'       => ['pk' => 'ID_VETO',   'cols' => ['TITULO', 'MOTIVO']],
    'enlace_candidato'  => ['pk' => 'ID_CAND',   'cols' => ['TITULO_ENC', 'ARTISTA_ENC']],
];

/** ¿La cadena ya está en NFC? */
$esNfc = static fn(string $s): bool => Normalizer::isNormalized($s, Normalizer::FORM_C);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // ── Recuento previo ──────────────────────────────────────────────────────
    $pendientes = [];   // [tabla, pk, col, id, viejo, nuevo]
    $tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($OBJETIVO as $tabla => $def) {
        if (!in_array($tabla, $tablas, true)) {
            continue;   // tabla opcional (fixture de CI reducida)
        }
        $cols = array_map(static fn(array $r): string => $r['name'], $pdo->query("PRAGMA table_info('$tabla')")->fetchAll());
        foreach ($def['cols'] as $col) {
            if (!in_array($col, $cols, true)) {
                continue;
            }
            $sel = $pdo->query("SELECT {$def['pk']} AS id, $col AS v FROM $tabla WHERE $col IS NOT NULL AND $col != ''");
            foreach ($sel as $row) {
                $v = (string) $row['v'];
                if ($esNfc($v)) {
                    continue;
                }
                $n = Normalizer::normalize($v, Normalizer::FORM_C);
                if ($n === false || $n === $v) {
                    continue;
                }
                $pendientes[] = [$tabla, $def['pk'], $col, $row['id'], $v, (string) $n];
            }
            $sel->closeCursor();   // si no, el VACUUM INTO falla: "SQL statements in progress"
        }
    }

    if ($pendientes === []) {
        echo "nada que normalizar: todo el texto ya está en NFC\n";
        exit(0);
    }

    $porColumna = [];
    foreach ($pendientes as [$tabla, , $col]) {
        $porColumna["$tabla.$col"] = ($porColumna["$tabla.$col"] ?? 0) + 1;
    }
    echo 'valores en forma descompuesta: ' . count($pendientes) . "\n";
    foreach ($porColumna as $k => $n) {
        echo "  $k: $n\n";
    }
    echo "muestra:\n";
    foreach (array_slice($pendientes, 0, 10) as [$tabla, $pk, $col, $id, $viejo]) {
        echo "  [$tabla#$id.$col] \"$viejo\"\n";
    }

    if ($dryRun) {
        echo "--dry-run: no se ha escrito nada\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Abortado: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-nfc.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $n = 0;
    foreach ($pendientes as [$tabla, $pk, $col, $id, , $nuevo]) {
        $upd = $pdo->prepare("UPDATE $tabla SET $col = ? WHERE $pk = ?");
        $upd->execute([$nuevo, $id]);
        $n += $upd->rowCount();
    }
    $pdo->commit();
    echo "$n valores normalizados a NFC\n";

    // Vuelca el WAL al fichero principal (mismo motivo que en
    // normalizar_localidades.php: el .db se copia/sube tal cual).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    // Los triggers marcha_au / autor_au ya han refrescado el FTS; se comprueba.
    foreach (['marcha_fts', 'autor_fts'] as $fts) {
        if (!in_array($fts, $tablas, true)) {
            continue;
        }
        $pdo->exec("INSERT INTO $fts($fts) VALUES('integrity-check')");
        echo "$fts: índice íntegro\n";
    }

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Normalización fallida: ' . $e->getMessage() . "\n");
    exit(1);
}
