<?php

declare(strict_types=1);

/*
 * Fusiona bandas duplicadas creadas por el seed de acompañamientos SS2026
 * (2026-09-11): `seed-ss2026-bandas` buscaba la banda por NOMBRE_COMPLETO
 * exacto con formato "X de <localidad>" y no encontró la banda ya existente
 * porque en `banda` estaba guardada como "X (<localidad>)" o sin sufijo de
 * localidad — insertó una banda NUEVA en vez de reutilizar la existente. Cada
 * pareja es la misma banda real (mismo nombre, misma localidad, solo cambia
 * el formato). Confirmado por el usuario 2026-09-11 tras revisar el listado.
 *
 * QUÉ HACE, por cada pareja (nueva -> antigua):
 *  - Para cada contrato de la banda nueva, si ya existe un contrato de la
 *    banda antigua con el mismo (HERMANDAD_SLUG, ANIO, TITULAR) se considera
 *    el mismo hecho duplicado dos veces: se borra el contrato de la nueva
 *    (con su contrato_localidad y contrato_paso). Si no colisiona, se
 *    reasigna ID_BANDA al de la antigua conservando ID_CONTRATO (así
 *    contrato_paso/contrato_localidad, que cuelgan de ID_CONTRATO, no hay que
 *    tocarlos).
 *  - Se borra la fila de `banda` nueva.
 *
 * A diferencia de fusionar_banda_caido_fuensanta.php (fusión real de dos
 * entidades históricas distintas en una tercera, con banda_relacion) esto NO
 * es una fusión de lineage: la banda "nueva" no tiene existencia real propia,
 * es un duplicado de carga de los últimos días, así que se borra sin más y
 * sin banda_relacion.
 *
 * Re-ejecutable: solo actúa sobre bandas/contratos que sigan existiendo.
 *
 * Uso:
 *   php php/app/tools/fusionar_bandas_duplicadas_ss2026.php --dry-run
 *   php php/app/tools/fusionar_bandas_duplicadas_ss2026.php
 *   DB_PATH=/ruta/a/mdc.db php .../fusionar_bandas_duplicadas_ss2026.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);

/** @var array<int,int> ID_BANDA nueva (duplicada) => ID_BANDA antigua (canónica) */
const PARES = [
    285 => 200, 288 => 141, 290 => 100, 293 => 10, 302 => 7, 304 => 196,
    305 => 25, 306 => 113, 307 => 143, 309 => 250, 311 => 107, 315 => 30,
    317 => 125, 319 => 76, 320 => 81, 326 => 208, 328 => 34, 329 => 191,
    330 => 48, 333 => 101, 336 => 130, 341 => 195, 342 => 59, 345 => 202,
    349 => 146, 351 => 178, 352 => 57, 287 => 53, 308 => 158, 298 => 26,
    323 => 67, 312 => 42, 313 => 24, 363 => 50,
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_bandas_duplicadas_ss2026' WHERE ID = 1");

    $buscaBanda = $pdo->prepare('SELECT NOMBRE_COMPLETO FROM banda WHERE ID_BANDA = ?');
    $contratosDe = $pdo->prepare(
        'SELECT ID_CONTRATO, HERMANDAD_SLUG, ANIO, TITULAR FROM contrato WHERE ID_BANDA = ?'
    );
    $existeEnAntigua = $pdo->prepare(
        'SELECT ID_CONTRATO FROM contrato
         WHERE ID_BANDA = ? AND HERMANDAD_SLUG = ? AND ANIO = ? AND COALESCE(TITULAR, \'\') = COALESCE(?, \'\')'
    );

    $reasignar = []; // ID_CONTRATO => nueva ID_BANDA (antigua)
    $borrarContrato = []; // ID_CONTRATO
    $bandasNoEncontradas = [];
    $bandasABorrar = [];

    foreach (PARES as $nueva => $antigua) {
        $buscaBanda->execute([$nueva]);
        $nombreNueva = $buscaBanda->fetchColumn();
        $buscaBanda->closeCursor();
        if ($nombreNueva === false) {
            echo "  banda #$nueva ya no existe, se omite la pareja #$nueva -> #$antigua\n";
            continue;
        }

        $contratosDe->execute([$nueva]);
        $filas = $contratosDe->fetchAll(PDO::FETCH_ASSOC);
        $contratosDe->closeCursor();

        $nReasignar = 0;
        $nBorrar = 0;
        foreach ($filas as $f) {
            $existeEnAntigua->execute([$antigua, $f['HERMANDAD_SLUG'], $f['ANIO'], $f['TITULAR']]);
            $colision = $existeEnAntigua->fetchColumn();
            $existeEnAntigua->closeCursor();
            if ($colision !== false) {
                $borrarContrato[] = (int) $f['ID_CONTRATO'];
                $nBorrar++;
            } else {
                $reasignar[(int) $f['ID_CONTRATO']] = $antigua;
                $nReasignar++;
            }
        }

        $bandasABorrar[] = $nueva;
        echo "#$nueva \"$nombreNueva\" -> #$antigua: $nReasignar contrato(s) reasignados, $nBorrar duplicado(s) exacto(s) borrados\n";
    }

    echo "\ntotal bandas a fusionar: " . count($bandasABorrar) . "\n";
    echo "total contratos a reasignar: " . count($reasignar) . "\n";
    echo "total contratos duplicados a borrar: " . count($borrarContrato) . "\n";

    if ($dryRun) {
        echo "--dry-run: no se ha tocado la BD\n";
        exit(0);
    }
    if ($bandasABorrar === []) {
        echo "nada que fusionar\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Fusión abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fusionar-bandas-duplicadas-ss2026.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();

    if ($borrarContrato !== []) {
        $placeholders = implode(',', array_fill(0, count($borrarContrato), '?'));
        $pdo->prepare("DELETE FROM contrato_localidad WHERE ID_CONTRATO IN ($placeholders)")->execute($borrarContrato);
        $pdo->prepare("DELETE FROM contrato_paso WHERE ID_CONTRATO IN ($placeholders)")->execute($borrarContrato);
        $pdo->prepare("DELETE FROM contrato WHERE ID_CONTRATO IN ($placeholders)")->execute($borrarContrato);
    }

    $updContrato = $pdo->prepare('UPDATE contrato SET ID_BANDA = ? WHERE ID_CONTRATO = ?');
    foreach ($reasignar as $idContrato => $idBandaAntigua) {
        $updContrato->execute([$idBandaAntigua, $idContrato]);
    }

    $placeholdersBanda = implode(',', array_fill(0, count($bandasABorrar), '?'));
    $delBanda = $pdo->prepare("DELETE FROM banda WHERE ID_BANDA IN ($placeholdersBanda)");
    $delBanda->execute($bandasABorrar);
    $n = $delBanda->rowCount();

    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$n banda(s) duplicadas borradas\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fusión falló: ' . $e->getMessage() . "\n");
    exit(1);
}
