<?php

declare(strict_types=1);

/*
 * Rellena ESTILO en marchas que aún tienen ESTILO = NULL usando el estilo
 * mayoritario de las marchas existentes de su misma BANDA_ESTRENO.
 *
 * Lógica (igual que /api/banda/estilo):
 *   SELECT ESTILO FROM marcha WHERE BANDA_ESTRENO = ? AND ESTILO IN ('CCTT','AM')
 *   GROUP BY ESTILO ORDER BY COUNT(*) DESC LIMIT 1
 *
 * Solo toca marchas con ESTILO IS NULL.  Si la banda no tiene ninguna marcha
 * ya clasificada la marcha queda pendiente y se lista al final.
 *
 * Re-ejecutable: no modifica marchas que ya tienen estilo asignado.
 *
 * Uso:
 *   php php/app/tools/fill_estilo_por_banda.php
 *   php php/app/tools/fill_estilo_por_banda.php --dry-run
 */

define('APP_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(APP_DIR));
define('DATA_DIR', BASE_DIR . '/data');

$dryRun = in_array('--dry-run', $argv ?? [], true);

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Abortado: no existe la BD en $db\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fill_estilo_por_banda' WHERE ID = 1");

// Backup antes de escribir.
if (!$dryRun) {
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Abortado: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fill-estilo.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";
}

// Marchas sin estilo que tienen BANDA_ESTRENO asignada.
$pendientes = $pdo->query(
    "SELECT ID_MARCHA, BANDA_ESTRENO FROM marcha
     WHERE (ESTILO IS NULL OR ESTILO = '') AND BANDA_ESTRENO IS NOT NULL AND BANDA_ESTRENO != 0
     ORDER BY ID_MARCHA"
)->fetchAll(PDO::FETCH_ASSOC);

echo 'marchas sin estilo con BANDA_ESTRENO: ' . count($pendientes) . "\n";

if ($pendientes === []) {
    echo "nada que hacer\n";
    exit(0);
}

// Estilo mayoritario por banda (de las marchas que ya tienen estilo).
$estilosBanda = [];
$rows = $pdo->query(
    "SELECT BANDA_ESTRENO, ESTILO, COUNT(*) AS n FROM marcha
     WHERE ESTILO IN ('CCTT','AM') AND BANDA_ESTRENO IS NOT NULL AND BANDA_ESTRENO != 0
     GROUP BY BANDA_ESTRENO, ESTILO
     ORDER BY BANDA_ESTRENO, n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $bid = (int) $r['BANDA_ESTRENO'];
    // Solo guardamos la primera fila (la de mayor COUNT) por banda.
    if (!isset($estilosBanda[$bid])) {
        $estilosBanda[$bid] = (string) $r['ESTILO'];
    }
}

$upd = $dryRun ? null : $pdo->prepare('UPDATE marcha SET ESTILO = ? WHERE ID_MARCHA = ?');

$asignadas = [];
$sinDatos = [];

foreach ($pendientes as $m) {
    $mid = (int) $m['ID_MARCHA'];
    $bid = (int) $m['BANDA_ESTRENO'];
    if (isset($estilosBanda[$bid])) {
        $estilo = $estilosBanda[$bid];
        $asignadas[] = ['id' => $mid, 'banda' => $bid, 'estilo' => $estilo];
        if (!$dryRun) {
            $upd->execute([$estilo, $mid]);
        }
    } else {
        $sinDatos[] = $mid;
    }
}

$modo = $dryRun ? '[DRY-RUN] ' : '';
echo $modo . 'asignadas: ' . count($asignadas) . "\n";
foreach ($asignadas as $a) {
    echo '  M-' . $a['id'] . '  banda #' . $a['banda'] . '  → ' . $a['estilo'] . "\n";
}

if ($sinDatos) {
    echo $modo . 'sin datos suficientes (banda sin marchas ya clasificadas): ' . count($sinDatos) . "\n";
    echo '  IDs: ' . implode(', ', $sinDatos) . "\n";
}
