<?php

declare(strict_types=1);

/*
 * Fusiona los duplicados históricos de TITULAR de Sevilla 1980-2019
 * (2026-09-07): el mismo bug que se arregló para 2026
 * (fusionar_titular_duplicado_sevilla_2026.php) resulta que llevaba
 * arrastrándose desde 1980 — 563 grupos (hermandad, año, banda) con DOS
 * TITULAR para la misma banda el mismo año ("Paso de Misterio" vs "Paso de
 * Cristo" y variantes). 2020-2026 ya está limpio (una sola fila por
 * hermandad+año+banda) porque ahí no llegó a duplicarse; ESE tramo se usa
 * aquí como referencia de qué TITULAR es el canónico de cada hermandad.
 *
 * Mismo criterio que la versión de 2026, pero en DOS intentos por grupo (hizo
 * falta ampliarlo para que las 563 filas resolvieran sin ambigüedad):
 *
 *   1º) Canónico = TITULAR de esa misma (HERMANDAD, ID_BANDA) en 2020-2026.
 *       Resuelve el caso normal (la banda no cambia de rol).
 *   2º) Si el 1º no deja EXACTAMENTE una fila que conservar (ni 0 ni 2+), se
 *       repite con el TITULAR de la HERMANDAD en 2020-2026 con CUALQUIER
 *       banda. Resuelve dos casos que el 1º no cubre:
 *         - La banda cambia de un año a otro (contrato normal): "La Espiga"/
 *           "La Milagrosa" no tienen fila con esa banda vieja en 2020-2026.
 *         - La banda cambia de ROL en 40 años: "Santa Genoveva" banda #157
 *           tocaba el Cautivo en 1982-1986 y en 2020-2026 toca la Cruz de
 *           Guía — su canónico "de banda" (1º intento) es "Cruz de Guía", que
 *           no coincide con ninguna de las dos filas de 1982-1986 y por eso
 *           hace falta el 2º intento (canónico "de hermandad": "Paso Cristo
 *           (Cautivo)").
 *
 *   San Pablo es el caso que motivó el 2º intento: usa dos bandas a la vez
 *   para el mismo paso, y una fila de 2026 (banda #6, "comparte paso") hace
 *   que el TITULAR "Paso de Misterio" también sea "canónico a nivel
 *   hermandad" — con el 1er intento (por banda) ya no contamina nada.
 *
 * Si ningún intento deja EXACTAMENTE una fila que conservar, el grupo se deja
 * fuera para revisión manual — no se adivina.
 *
 * Re-ejecutable: solo actúa sobre filas que sigan existiendo.
 *
 * Uso:
 *   php php/app/tools/fusionar_titular_duplicado_sevilla_historico.php --dry-run
 *   php php/app/tools/fusionar_titular_duplicado_sevilla_historico.php
 *   DB_PATH=/ruta/a/mdc.db php .../fusionar_titular_duplicado_sevilla_historico.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_titular_duplicado_sevilla_historico' WHERE ID = 1");

    $grupos = $pdo->query(
        "SELECT c.HERMANDAD, c.ANIO, c.ID_BANDA
         FROM contrato c
         JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO AND cl.LOCALIDAD = 'Sevilla'
         WHERE c.ANIO < 2020
         GROUP BY c.HERMANDAD, c.ANIO, c.ID_BANDA
         HAVING COUNT(DISTINCT COALESCE(c.TITULAR, '')) > 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $filasGrupo = $pdo->prepare(
        'SELECT ID_CONTRATO, TITULAR FROM contrato WHERE HERMANDAD = ? AND ANIO = ? AND ID_BANDA = ?'
    );
    // 2020-2026, no solo 2025: ya limpio en todo ese tramo (ver docblock), y
    // así una hermandad sin fila en 2025 concreto (banda distinta ese año)
    // igualmente encuentra su TITULAR canónico.
    $canonicoBanda = $pdo->prepare(
        'SELECT DISTINCT COALESCE(TITULAR, \'\') AS T FROM contrato
         WHERE HERMANDAD = ? AND ID_BANDA = ? AND ANIO BETWEEN 2020 AND 2026'
    );
    $canonicoHermandad = $pdo->prepare(
        'SELECT DISTINCT COALESCE(TITULAR, \'\') AS T FROM contrato WHERE HERMANDAD = ? AND ANIO BETWEEN 2020 AND 2026'
    );

    /**
     * @param list<array{ID_CONTRATO:int,TITULAR:?string}> $filas
     * @param array<string,true> $canonicos
     * @return array{0:list<array{ID_CONTRATO:int,TITULAR:?string}>,1:list<array{ID_CONTRATO:int,TITULAR:?string}>}
     */
    $partir = static function (array $filas, array $canonicos): array {
        $conservar = [];
        $borrar = [];
        foreach ($filas as $f) {
            $t = (string) ($f['TITULAR'] ?? '');
            if (isset($canonicos[$t])) {
                $conservar[] = $f;
            } else {
                $borrar[] = $f;
            }
        }
        return [$conservar, $borrar];
    };

    $aBorrar = [];
    $porHermandadRevisar = [];
    foreach ($grupos as $g) {
        $filasGrupo->execute([$g['HERMANDAD'], $g['ANIO'], $g['ID_BANDA']]);
        $filas = $filasGrupo->fetchAll(PDO::FETCH_ASSOC);
        $filasGrupo->closeCursor();

        $canonicoBanda->execute([$g['HERMANDAD'], $g['ID_BANDA']]);
        [$conservar, $borrar] = $partir($filas, array_flip($canonicoBanda->fetchAll(PDO::FETCH_COLUMN)));
        $canonicoBanda->closeCursor();

        if (!(count($conservar) === 1 && count($borrar) >= 1)) {
            $canonicoHermandad->execute([$g['HERMANDAD']]);
            [$conservar, $borrar] = $partir($filas, array_flip($canonicoHermandad->fetchAll(PDO::FETCH_COLUMN)));
            $canonicoHermandad->closeCursor();
        }

        if (count($conservar) === 1 && count($borrar) >= 1) {
            foreach ($borrar as $f) {
                $aBorrar[] = (int) $f['ID_CONTRATO'];
            }
        } else {
            $porHermandadRevisar[$g['HERMANDAD']] = ($porHermandadRevisar[$g['HERMANDAD']] ?? 0) + 1;
        }
    }

    echo 'grupos con duplicado (1980-2019): ' . count($grupos) . "\n";
    echo 'contratos a borrar: ' . count($aBorrar) . "\n";
    if ($porHermandadRevisar !== []) {
        echo 'hermandades sin canónico claro, fuera de esta pasada (' . count($porHermandadRevisar) . " grupos afectados):\n";
        foreach ($porHermandadRevisar as $h => $n) {
            echo "  - $h ($n grupos)\n";
        }
    }

    if ($dryRun) {
        echo "--dry-run: no se ha tocado la BD\n";
        exit(0);
    }
    if ($aBorrar === []) {
        echo "nada que borrar\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Fusión abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fusionar-titular-duplicado-sevilla-historico.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    // SQLite: máx. ~999 parámetros por sentencia (SQLITE_MAX_VARIABLE_NUMBER);
    // 539 encaja de sobra, pero se trocea por si esta pasada crece.
    $pdo->beginTransaction();
    $total = 0;
    foreach (array_chunk($aBorrar, 500) as $lote) {
        $placeholders = implode(',', array_fill(0, count($lote), '?'));
        $pdo->prepare("DELETE FROM contrato_localidad WHERE ID_CONTRATO IN ($placeholders)")->execute($lote);
        $pdo->prepare("DELETE FROM contrato_paso WHERE ID_CONTRATO IN ($placeholders)")->execute($lote);
        $del = $pdo->prepare("DELETE FROM contrato WHERE ID_CONTRATO IN ($placeholders)");
        $del->execute($lote);
        $total += $del->rowCount();
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$total contratos borrados\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fusión falló: ' . $e->getMessage() . "\n");
    exit(1);
}
