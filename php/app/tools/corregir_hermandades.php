<?php

declare(strict_types=1);

/*
 * Corrección puntual sobre contrato.HERMANDAD (2026-09-07), previa a poblar la
 * nómina de N-03 (012_hermandad_paso.sql):
 *
 *   1. TILDES. La carga histórica de elforocofrade venía del texto plano del
 *      foro, sin diacríticos: "La Mision", "Montesion", "San Jeronimo"...
 *      13 hermandades afectadas. Es seguro: Slug::slugify() normaliza a NFD y
 *      quita las marcas diacríticas, así que HERMANDAD_SLUG no cambia y los
 *      contratos siguen agrupando igual (por eso este script NO toca el slug
 *      en las filas de tilde).
 *
 *   2. FUSIÓN "El Carmen" -> "El Carmen Doloroso". Son la misma hermandad
 *      (Miércoles Santo), partida en dos entidades porque el histórico del foro
 *      la llama "EL CARMEN" y musicofrades.com "El Carmen Doloroso": el
 *      histórico quedaba colgando en 1993-2019 y 2026 aparecía como hermandad
 *      nueva. Aquí sí cambia el slug ("el-carmen" -> "el-carmen-doloroso"), que
 *      es justo lo que une las dos series.
 *
 * Re-ejecutable: solo toca filas que sigan teniendo el valor incorrecto exacto.
 *
 * Uso:
 *   php php/app/tools/corregir_hermandades.php
 *   DB_PATH=/ruta/a/mdc.db php .../corregir_hermandades.php
 *
 * Hace copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay algo
 * que corregir.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Corrección abortada');

/** Tildes: [HERMANDAD incorrecta, correcta]. El slug no cambia. */
$TILDES = [
    ['Bendicion y Esperanza', 'Bendición y Esperanza'],
    ['Divino Perdon de Alcosa', 'Divino Perdón de Alcosa'],
    ['El Cerro del Aguila', 'El Cerro del Águila'],
    ['Jesus Despojado', 'Jesús Despojado'],
    ['La Carreteria', 'La Carretería'],
    ['La Exaltacion', 'La Exaltación'],
    ['La Mision', 'La Misión'],
    ['La Redencion', 'La Redención'],
    ['La Resurreccion', 'La Resurrección'],
    ['Montesion', 'Montesión'],
    ['Padre Pio', 'Padre Pío'],
    ['San Jeronimo', 'San Jerónimo'],
    ['San Jose Obrero', 'San José Obrero'],
];

/** Fusión: [slug viejo, HERMANDAD nueva, slug nuevo]. */
$FUSIONES = [
    ['el-carmen', 'El Carmen Doloroso', 'el-carmen-doloroso'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:corregir_hermandades' WHERE ID = 1");

    $pendTildes = [];
    foreach ($TILDES as [$malo, $bueno]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM contrato WHERE HERMANDAD = ?');
        $stmt->execute([$malo]);
        $n = (int) $stmt->fetchColumn();
        $stmt->closeCursor(); // si no, VACUUM INTO falla: "SQL statements in progress"
        if ($n > 0) {
            $pendTildes[] = [$malo, $bueno, $n];
        }
    }

    $pendFus = [];
    foreach ($FUSIONES as [$slugViejo, $nombre, $slugNuevo]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM contrato WHERE HERMANDAD_SLUG = ?');
        $stmt->execute([$slugViejo]);
        $n = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($n > 0) {
            $pendFus[] = [$slugViejo, $nombre, $slugNuevo, $n];
        }
    }

    if ($pendTildes === [] && $pendFus === []) {
        echo "nada que corregir (ya está todo bien)\n";
        exit(0);
    }

    foreach ($pendTildes as [$malo, $bueno, $n]) {
        echo "  tilde:  \"$malo\" -> \"$bueno\" ($n filas)\n";
    }
    foreach ($pendFus as [$slugViejo, $nombre, $slugNuevo, $n]) {
        echo "  fusión: [$slugViejo] -> \"$nombre\" [$slugNuevo] ($n filas)\n";
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Corrección abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-corregir-hermandades.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $total = 0;
    foreach ($pendTildes as [$malo, $bueno]) {
        $upd = $pdo->prepare('UPDATE contrato SET HERMANDAD = ? WHERE HERMANDAD = ?');
        $upd->execute([$bueno, $malo]);
        $total += $upd->rowCount();
    }
    foreach ($pendFus as [$slugViejo, $nombre, $slugNuevo]) {
        $upd = $pdo->prepare('UPDATE contrato SET HERMANDAD = ?, HERMANDAD_SLUG = ? WHERE HERMANDAD_SLUG = ?');
        $upd->execute([$nombre, $slugNuevo, $slugViejo]);
        $total += $upd->rowCount();
    }
    $pdo->commit();
    // Vuelca el WAL al fichero principal (mismo motivo que en normalizar_localidades.php).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$total filas corregidas\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Corrección falló: ' . $e->getMessage() . "\n");
    exit(1);
}
