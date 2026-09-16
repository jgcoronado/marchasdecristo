<?php

declare(strict_types=1);

/*
 * Carga los contratos banda↔paso de Cristo de Jerez de la Frontera 2026
 * (2026-09-08), a partir de la propuesta generada por
 * scripts/tmp_acompanamientos/proponer_contratos_jerez.php en
 * scripts/tmp_acompanamientos/jerez_contratos_propuesta.csv (musicofrades.com
 * cruzado con mundocofrade.es, que gana en caso de conflicto — ver
 * project_acompanamientos.md y jerez_bandas_reconciliado.csv).
 *
 * Solo procesa filas del CSV con BANDA_ID_CANDIDATA relleno: las 4 que se
 * quedaron sin banda candidata (banda real que no existe todavía en `banda`)
 * se dejan fuera a propósito y se listan al final.
 *
 * Re-ejecutable: antes de insertar comprueba si ya existe un contrato con la
 * misma (HERMANDAD_SLUG, ANIO, ID_BANDA, TITULAR, FUENTE).
 *
 * Uso:
 *   php php/app/tools/cargar_contratos_jerez.php --dry-run
 *   php php/app/tools/cargar_contratos_jerez.php
 *   DB_PATH=/ruta/a/mdc.db php .../cargar_contratos_jerez.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Carga abortada');

const ANIO = 2026;
const LOCALIDAD = 'Jerez de la Frontera';
const FUENTE = 'musicofrades.com + mundocofrade.es (Semana Santa Jerez 2026)';

$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);

$csv = dirname(__DIR__, 3) . '/scripts/tmp_acompanamientos/jerez_contratos_propuesta.csv';
if (!is_file($csv)) {
    fwrite(STDERR, "Carga abortada: no se encuentra $csv\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:cargar_contratos_jerez' WHERE ID = 1");

    $idHermandad = $pdo->prepare('SELECT ID_HERMANDAD, NOMBRE FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $idPaso = $pdo->prepare('SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ? AND NOMBRE = ?');
    $yaExiste = $pdo->prepare(
        'SELECT ID_CONTRATO FROM contrato
         WHERE HERMANDAD_SLUG = ? AND ANIO = ? AND ID_BANDA = ? AND COALESCE(TITULAR, \'\') = ? AND FUENTE = ?'
    );

    $rows = array_map('str_getcsv', file($csv));
    $header = array_shift($rows);
    if ($header === null) {
        fwrite(STDERR, "Carga abortada: $csv vacío\n");
        exit(1);
    }

    $aInsertar = [];
    $sinBanda = [];
    foreach ($rows as $r) {
        if (count($r) < 2) {
            continue; // línea en blanco final
        }
        $row = array_combine($header, $r);
        if ($row['BANDA_ID_CANDIDATA'] === '') {
            $sinBanda[] = $row['HERMANDAD_SLUG'] . ' / ' . $row['PASO_NOMBRE'] . ' (' . $row['BANDA_RAW_TEXT'] . ')';
            continue;
        }

        $idHermandad->execute([LOCALIDAD, $row['HERMANDAD_SLUG']]);
        $herm = $idHermandad->fetch();
        $idHermandad->closeCursor();
        if ($herm === false) {
            fwrite(STDERR, "Carga abortada: hermandad desconocida \"{$row['HERMANDAD_SLUG']}\"\n");
            exit(1);
        }

        $idPaso->execute([(int) $herm['ID_HERMANDAD'], $row['PASO_NOMBRE']]);
        $paso = $idPaso->fetchColumn();
        $idPaso->closeCursor();
        if ($paso === false) {
            fwrite(
                STDERR,
                "Carga abortada: paso \"{$row['PASO_NOMBRE']}\" no existe para \"{$row['HERMANDAD_SLUG']}\""
                . " (revisar jerez_contratos_propuesta.csv)\n"
            );
            exit(1);
        }

        $aInsertar[] = [
            'ID_BANDA' => (int) $row['BANDA_ID_CANDIDATA'],
            'HERMANDAD' => $herm['NOMBRE'],
            'HERMANDAD_SLUG' => $row['HERMANDAD_SLUG'],
            'TITULAR' => $row['PASO_NOMBRE'],
            'ID_PASO' => (int) $paso,
            'NOTA' => 'banda_raw=' . $row['BANDA_RAW_TEXT'],
        ];
    }

    $yaHay = 0;
    $nuevos = [];
    foreach ($aInsertar as $f) {
        $yaExiste->execute([$f['HERMANDAD_SLUG'], ANIO, $f['ID_BANDA'], $f['TITULAR'], FUENTE]);
        $existente = $yaExiste->fetchColumn();
        $yaExiste->closeCursor();
        if ($existente !== false) {
            $yaHay++;
            continue;
        }
        $nuevos[] = $f;
    }

    echo 'filas del CSV con banda candidata: ' . count($aInsertar) . "\n";
    echo 'ya cargados (re-ejecución): ' . $yaHay . "\n";
    echo 'contratos nuevos a insertar: ' . count($nuevos) . "\n";
    if ($sinBanda !== []) {
        echo 'sin banda candidata, NO se cargan (' . count($sinBanda) . "):\n";
        foreach ($sinBanda as $s) {
            echo "  - $s\n";
        }
    }

    if ($dryRun) {
        echo "--dry-run: no se ha tocado la BD\n";
        exit(0);
    }
    if ($nuevos === []) {
        echo "nada que insertar\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Carga abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-cargar-contratos-jerez.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $insC = $pdo->prepare(
        'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE, NOTA)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insL = $pdo->prepare('INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, ?)');
    $insP = $pdo->prepare('INSERT INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)');
    foreach ($nuevos as $f) {
        $insC->execute([$f['ID_BANDA'], $f['HERMANDAD'], $f['HERMANDAD_SLUG'], $f['TITULAR'], ANIO, FUENTE, $f['NOTA']]);
        $idContrato = (int) $pdo->lastInsertId();
        $insL->execute([$idContrato, LOCALIDAD]);
        $insP->execute([$idContrato, $f['ID_PASO']]);
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo count($nuevos) . " contratos insertados y enlazados a su paso\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Carga falló: ' . $e->getMessage() . "\n");
    exit(1);
}
