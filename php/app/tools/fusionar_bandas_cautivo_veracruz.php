<?php

declare(strict_types=1);

/*
 * Fusiona dos bandas duplicadas en `banda` (confirmado por el usuario
 * 2026-09-24; se queda en ambos casos el ID más bajo):
 *   #318 "AM Jesús Cautivo" (Estepona)                    -> #117 "AM Cautivo" (Estepona)
 *   #337 "AM Santísimo Cristo de la Vera Cruz" (Campillos) -> #110 "AM Vera Cruz" (Campillos)
 * Las duplicadas las creó la carga de acompañamientos y solo tienen contratos
 * (ni marchas, ni discos, ni canales, ni enlaces, ni banda_relacion); si
 * alguna tuviera otra cosa colgando, el script se detiene sin tocar nada.
 *
 * QUÉ HACE, por cada contrato de la banda duplicada:
 *  - Si la banda canónica ya tiene contrato en la misma localidad, hermandad
 *    y año, es el mismo acompañamiento cargado dos veces (p. ej. Pollinica
 *    2026: "Paso de Cristo" sin paso vs. el paso real, alta del usuario): se
 *    borra el de la duplicada con sus satélites (contrato_localidad,
 *    contrato_paso, contrato_tramo). A diferencia de
 *    fusionar_bandas_duplicadas_ss2026.php no se exige el mismo TITULAR: la
 *    carga vieja usaba etiquetas genéricas y el alta nueva el nombre del paso.
 *  - Si no, se reasigna ID_BANDA conservando ID_CONTRATO.
 *  - Se borra la fila de `banda` duplicada.
 *
 * Re-ejecutable: solo actúa sobre bandas que sigan existiendo.
 *
 * Uso:
 *   php php/app/tools/fusionar_bandas_cautivo_veracruz.php --dry-run
 *   php php/app/tools/fusionar_bandas_cautivo_veracruz.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

/** @var array<int,int> ID_BANDA duplicada => ID_BANDA que se queda */
const PARES = [318 => 117, 337 => 110];

/** Referencias a banda que NO sean contratos: si la duplicada tiene alguna, se aborta. */
const OTRAS_REFERENCIAS = [
    'marcha' => 'BANDA_ESTRENO = ?',
    'disco' => 'BANDADISCO = ?',
    'disco_marcha' => 'DM_BANDA = ?',
    'ingest_canal' => 'ID_BANDA = ?',
    'ingest_candidato' => '(ID_BANDA = ? OR P_BANDA_ESTRENO = ?)',
    'ingest_veto' => 'ID_BANDA = ?',
    'banda_relacion' => '(ID_ORIGEN = ? OR ID_DESTINO = ?)',
    'enlace_streaming' => "TIPO_ENT = 'banda' AND ID_ENT = ?",
    'enlace_candidato' => "TIPO_ENT = 'banda' AND ID_ENT = ?",
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_bandas_cautivo_veracruz' WHERE ID = 1");

    $reasignar = [];      // ID_CONTRATO => ID_BANDA canónica
    $borrarContrato = []; // ID_CONTRATO
    $bandasABorrar = [];

    foreach (PARES as $dup => $canon) {
        $nombre = $pdo->query('SELECT NOMBRE_BREVE FROM banda WHERE ID_BANDA = ' . $dup)->fetchAll(PDO::FETCH_COLUMN)[0] ?? false;
        if ($nombre === false) {
            echo "banda #$dup ya no existe, se omite\n";
            continue;
        }
        if ($pdo->query('SELECT 1 FROM banda WHERE ID_BANDA = ' . $canon)->fetchAll() === []) {
            throw new RuntimeException("la banda canónica #$canon no existe");
        }
        foreach (OTRAS_REFERENCIAS as $tabla => $where) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $where");
            $st->execute(array_fill(0, substr_count($where, '?'), $dup));
            $hay = (int) $st->fetchColumn();
            $st->closeCursor();
            if ($hay > 0) {
                throw new RuntimeException("#$dup tiene filas en $tabla: revisar a mano antes de fusionar");
            }
        }

        $filas = $pdo->query(
            "SELECT c.ID_CONTRATO, c.HERMANDAD_SLUG, c.ANIO, cl.LOCALIDAD
             FROM contrato c LEFT JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.ID_BANDA = $dup"
        )->fetchAll(PDO::FETCH_ASSOC);
        $choque = $pdo->prepare(
            "SELECT c.ID_CONTRATO FROM contrato c
             LEFT JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.ID_BANDA = ? AND c.HERMANDAD_SLUG = ? AND c.ANIO = ? AND IFNULL(cl.LOCALIDAD, '') = IFNULL(?, '')
             LIMIT 1"
        );
        $nR = 0;
        $nB = 0;
        foreach ($filas as $f) {
            $choque->execute([$canon, $f['HERMANDAD_SLUG'], $f['ANIO'], $f['LOCALIDAD']]);
            $otro = $choque->fetchColumn();
            $choque->closeCursor();
            if ($otro !== false) {
                $borrarContrato[] = (int) $f['ID_CONTRATO'];
                echo "  duplicado: contrato #{$f['ID_CONTRATO']} ({$f['LOCALIDAD']}, {$f['HERMANDAD_SLUG']}, {$f['ANIO']}) = #$otro de la banda #$canon -> se borra\n";
                $nB++;
            } else {
                $reasignar[(int) $f['ID_CONTRATO']] = $canon;
                $nR++;
            }
        }
        $bandasABorrar[] = $dup;
        echo "#$dup \"$nombre\" -> #$canon: $nR contrato(s) reasignados, $nB duplicado(s) borrados\n";
    }

    if ($dryRun) {
        echo "--dry-run: no se ha tocado la BD\n";
        exit(0);
    }
    if ($bandasABorrar === []) {
        echo "nada que fusionar\n";
        exit(0);
    }

    $st = $choque = null; // VACUUM INTO falla con sentencias abiertas
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException("no se pudo crear $backupDir");
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fusionar-bandas-cautivo-veracruz.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    if ($borrarContrato !== []) {
        $in = implode(',', array_fill(0, count($borrarContrato), '?'));
        foreach (['contrato_localidad', 'contrato_paso', 'contrato_tramo', 'contrato'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE ID_CONTRATO IN ($in)")->execute($borrarContrato);
        }
    }
    $upd = $pdo->prepare('UPDATE contrato SET ID_BANDA = ? WHERE ID_CONTRATO = ?');
    foreach ($reasignar as $idContrato => $canon) {
        $upd->execute([$canon, $idContrato]);
    }
    $inB = implode(',', array_fill(0, count($bandasABorrar), '?'));
    $del = $pdo->prepare("DELETE FROM banda WHERE ID_BANDA IN ($inB)");
    $del->execute($bandasABorrar);
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo $del->rowCount() . " banda(s) duplicadas borradas\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fusión abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
