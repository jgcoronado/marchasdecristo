<?php

declare(strict_types=1);

/*
 * Backup de la base de datos SQLite. Pensado para ejecutarse por cron:
 *
 *   /usr/local/bin/php /home/USUARIO/app/tools/backup.php
 *
 * Hace una copia consistente con VACUUM INTO (segura aunque haya escrituras) en
 * un subdirectorio `backups/` junto al .db (es decir, FUERA del webroot) y borra
 * las copias con más de `backup_keep_days` días.
 */

require __DIR__ . '/_cli.php';
[$config, $db] = cliBootstrap('Backup abortado');

$backupDir = dirname($db) . '/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Backup abortado: no se pudo crear $backupDir\n");
    exit(1);
}

$dest = $backupDir . '/mdc-' . date('Ymd-His') . '.db';

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup falló: ' . $e->getMessage() . "\n");
    exit(1);
}

// Retención.
$keepDays = (int) ($config['backup_keep_days'] ?? 14);
$cutoff = time() - $keepDays * 86400;
$removed = 0;
foreach (glob($backupDir . '/mdc-*.db') ?: [] as $f) {
    if (filemtime($f) < $cutoff) {
        @unlink($f);
        $removed++;
    }
}

echo 'backup OK: ' . $dest . ' (' . number_format(filesize($dest)) . " bytes)"
    . ($removed ? ", $removed antiguos eliminados" : '') . "\n";
