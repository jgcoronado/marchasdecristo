<?php

declare(strict_types=1);

/*
 * Migración one-shot: roles de usuario.
 *
 *   usuarios.ROL TEXT NOT NULL DEFAULT 'editor'
 *
 * Asigna 'admin' al usuario indicado (por defecto "estprocesional") y deja al
 * resto como 'editor'. Con roles, el editor no escribe en la BD: sus cambios se
 * guardan como propuestas (ver PropuestaRepo) que el admin revisa en local.
 *
 * Re-ejecutable: si la columna ROL ya existe, no la vuelve a crear; solo
 * reafirma que el admin indicado tiene rol 'admin' (idempotente).
 *
 * Uso:
 *   php php/app/tools/migrate_roles.php
 *   php php/app/tools/migrate_roles.php --admin estprocesional
 *   DB_PATH=/ruta/a/mdc.db php .../migrate_roles.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar el esquema.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

$adminUser = 'estprocesional';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--admin' && isset($argv[$i + 1])) { $adminUser = $argv[++$i]; }
}

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Migración abortada: no existe la BD en $db\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:migrate_roles' WHERE ID = 1");

    $cols = $pdo->query('PRAGMA table_info(usuarios)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $yaExiste = in_array('ROL', $cols, true);

    if (!$yaExiste) {
        // Backup consistente antes de tocar el esquema.
        $backupDir = dirname($db) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            fwrite(STDERR, "Migración abortada: no se pudo crear $backupDir\n");
            exit(1);
        }
        $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-roles.db';
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        echo 'backup: ' . $dest . "\n";

        $pdo->exec("ALTER TABLE usuarios ADD COLUMN ROL TEXT NOT NULL DEFAULT 'editor'");
        echo "columna ROL añadida (por defecto 'editor')\n";
    } else {
        echo "columna ROL ya existe; se reafirma el admin\n";
    }

    // El admin indicado pasa a rol 'admin'.
    $stmt = $pdo->prepare('UPDATE usuarios SET ROL = ? WHERE usuario = ?');
    $stmt->execute(['admin', $adminUser]);
    $n = $stmt->rowCount();
    if ($n === 0) {
        fwrite(STDERR, "AVISO: no existe el usuario '$adminUser'; ningún admin asignado.\n");
    } else {
        echo "usuario '$adminUser' → admin\n";
    }

    // Resumen.
    $rows = $pdo->query('SELECT usuario, ROL FROM usuarios ORDER BY usuario')->fetchAll(PDO::FETCH_ASSOC);
    echo "\nusuarios:\n";
    foreach ($rows as $r) {
        echo '  ' . str_pad((string) $r['usuario'], 20) . ' ' . $r['ROL'] . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migración falló: ' . $e->getMessage() . "\n");
    exit(1);
}
