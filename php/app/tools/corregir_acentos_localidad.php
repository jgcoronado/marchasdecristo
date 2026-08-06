<?php

declare(strict_types=1);

/*
 * Corrección puntual: normalizar_localidades.php (primera versión, antes de
 * anteponer "empieza en mayúscula" y "más tildes" al recuento de filas) fusionó
 * mal 3 casos por elegir la variante más frecuente o el desempate alfabético
 * en vez de la grafía correcta:
 *
 *   marcha.LOCALIDAD: "ávila"  debería ser "Ávila"  (perdió por tener menos filas)
 *   marcha.LOCALIDAD: "Caceres" debería ser "Cáceres" (empate alfabético, ganó la sin tilde)
 *   marcha.LOCALIDAD: "Huescar" debería ser "Huéscar" (mismo motivo)
 *
 * Como ya están fusionadas (una sola grafía por fila), normalizar_localidades.php
 * ya no las detecta como "variantes" — de ahí este corrector puntual, aparte.
 *
 * Re-ejecutable: solo actualiza filas que sigan teniendo el valor incorrecto
 * exacto; si ya se corrigieron (a mano o con una ejecución previa), no toca nada.
 *
 * Uso:
 *   php php/app/tools/corregir_acentos_localidad.php
 *   DB_PATH=/ruta/a/mdc.db php .../corregir_acentos_localidad.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay
 * algo que corregir.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Corrección abortada: no existe la BD en $db\n");
    exit(1);
}

/** [tabla, columna, valor incorrecto exacto, valor correcto]. */
$CORRECCIONES = [
    ['marcha', 'LOCALIDAD', 'ávila', 'Ávila'],
    ['marcha', 'LOCALIDAD', 'Caceres', 'Cáceres'],
    ['marcha', 'LOCALIDAD', 'Huescar', 'Huéscar'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:corregir_acentos_localidad' WHERE ID = 1");

    $pendientes = [];
    foreach ($CORRECCIONES as $c) {
        [$tabla, $col, $malo] = $c;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $col = ?");
        $stmt->execute([$malo]);
        $n = (int) $stmt->fetchColumn();
        $stmt->closeCursor(); // si no, VACUUM INTO falla más abajo: "SQL statements in progress"
        if ($n > 0) {
            $pendientes[] = [...$c, 'n' => $n];
        }
    }

    if ($pendientes === []) {
        echo "nada que corregir (ya está todo bien)\n";
        exit(0);
    }

    echo "correcciones pendientes:\n";
    foreach ($pendientes as $p) {
        echo "  [{$p[0]}.{$p[1]}] \"{$p[2]}\" -> \"{$p[3]}\" ({$p['n']} filas)\n";
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Corrección abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-corregir-acentos.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    foreach ($pendientes as $p) {
        [$tabla, $col, $malo, $bueno] = $p;
        $upd = $pdo->prepare("UPDATE $tabla SET $col = ? WHERE $col = ?");
        $upd->execute([$bueno, $malo]);
        echo "  {$tabla}.{$col}: {$upd->rowCount()} filas corregidas (\"$malo\" -> \"$bueno\")\n";
    }
    $pdo->commit();
    // Vuelca el WAL al fichero principal (ver mismo comentario en
    // normalizar_localidades.php).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Corrección falló: ' . $e->getMessage() . "\n");
    exit(1);
}
