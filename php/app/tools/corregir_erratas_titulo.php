<?php

declare(strict_types=1);

/*
 * Corrección puntual de erratas en marcha.TITULO.
 *
 * Salieron al investigar por qué "El Santísimo Cristo del Amor" no aparecía en
 * el buscador (agosto 2026): no era un problema de tildes, sino que el título
 * estaba mal escrito en la BD. Como la búsqueda exige que TODOS los tokens
 * casen por prefijo, un título con una letra de menos es invisible si el
 * visitante escribe el nombre bien — que es justo lo que hará.
 *
 * Se detectaron comparando cada palabra rara (≤2 títulos) con las frecuentes
 * (≥20 títulos) a distancia de edición 1. De los ~30 candidatos, solo estos dos
 * son erratas; el resto son latinismos (Christo, Iesus, Sancta, Mariae...),
 * plurales legítimos o la "V" romana deliberada de Jesvs / Salvd / Amargvra
 * (4 títulos de autores distintos: es estilo, no error). NO se tocan.
 *
 * Al escribir en `marcha` salta el trigger marcha_au, que mantiene marcha_fts,
 * así que el índice de búsqueda queda al día sin reconstruirlo.
 *
 * Re-ejecutable: solo actualiza la fila si sigue teniendo el valor incorrecto
 * exacto; si ya se corrigió (a mano o en una ejecución previa), no toca nada.
 *
 * Uso:
 *   php php/app/tools/corregir_erratas_titulo.php
 *   php php/app/tools/corregir_erratas_titulo.php --dry-run
 *   DB_PATH=/ruta/a/mdc.db php .../corregir_erratas_titulo.php
 *
 * Hace copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay algo
 * que corregir.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

$dryRun = in_array('--dry-run', $argv, true);

if (!is_file($db)) {
    fwrite(STDERR, "Corrección abortada: no existe la BD en $db\n");
    exit(1);
}

/*
 * [ID_MARCHA, título incorrecto exacto, título correcto].
 *
 * Se comprueba el id Y el texto viejo: si alguien ya lo arregló a mano con otra
 * grafía, este script no pisa su decisión.
 */
$CORRECCIONES = [
    // Alberto Escámez López, 1944, Málaga (Hdad del Amor): falta la "i" de
    // "Santísimo". Es el caso que destapó todo esto.
    [1693, 'El Santísmo Cristo del Amor', 'El Santísimo Cristo del Amor'],
    // Sergio Ferrete Muñoz, 2008, La Línea de la Concepción (Hdad del Cautivo):
    // falta la "t" de "Cautivo". No confundir con "Cautivo de la Trinidad"
    // (#196, Cristóbal López Gándara, Jaén), que es otra marcha distinta.
    [3815, 'Cauivo y Trinidad', 'Cautivo y Trinidad'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:corregir_erratas_titulo' WHERE ID = 1");

    $pendientes = [];
    foreach ($CORRECCIONES as $c) {
        [$id, $malo] = $c;
        $stmt = $pdo->prepare('SELECT TITULO FROM marcha WHERE ID_MARCHA = ?');
        $stmt->execute([$id]);
        $actual = $stmt->fetchColumn();
        $stmt->closeCursor(); // si no, el VACUUM INTO falla: "SQL statements in progress"
        if ($actual === false) {
            echo "  aviso: no existe la marcha #$id (¿BD distinta?), se omite\n";
            continue;
        }
        if ($actual === $malo) {
            $pendientes[] = $c;
        }
    }

    if ($pendientes === []) {
        echo "nada que corregir (ya está todo bien)\n";
        exit(0);
    }

    echo "erratas pendientes:\n";
    foreach ($pendientes as [$id, $malo, $bueno]) {
        echo "  #$id  \"$malo\" -> \"$bueno\"\n";
    }

    if ($dryRun) {
        echo "--dry-run: no se ha escrito nada\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Corrección abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-erratas-titulo.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    foreach ($pendientes as [$id, $malo, $bueno]) {
        $upd = $pdo->prepare('UPDATE marcha SET TITULO = ? WHERE ID_MARCHA = ? AND TITULO = ?');
        $upd->execute([$bueno, $id, $malo]);
        echo "  #$id: {$upd->rowCount()} fila corregida\n";
    }
    $pdo->commit();

    // Vuelca el WAL al fichero principal (mismo motivo que en
    // normalizar_localidades.php: el .db se copia/sube tal cual).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    // El trigger marcha_au ya ha refrescado marcha_fts; se comprueba y, de paso,
    // se verifica que ahora el título corregido SÍ se encuentra buscándolo.
    $pdo->exec("INSERT INTO marcha_fts(marcha_fts) VALUES('integrity-check')");
    echo "marcha_fts: índice íntegro\n";
    foreach ($pendientes as [$id, , $bueno]) {
        $tokens = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $bueno)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $match = implode(' ', array_map(static fn(string $t): string => '"' . $t . '"*', $tokens));
        $chk = $pdo->prepare('SELECT 1 FROM marcha_fts WHERE marcha_fts MATCH ? AND rowid = ?');
        $chk->execute([$match, $id]);
        echo "  #$id se encuentra buscando \"$bueno\": " . ($chk->fetchColumn() ? 'sí' : 'NO (revisar)') . "\n";
        $chk->closeCursor();
    }

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Corrección falló: ' . $e->getMessage() . "\n");
    exit(1);
}
