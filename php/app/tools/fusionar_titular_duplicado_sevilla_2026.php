<?php

declare(strict_types=1);

/*
 * Fusiona contratos duplicados de Sevilla 2026 (2026-09-07): 23 hermandades
 * tienen DOS filas para el mismo (HERMANDAD, ID_BANDA) en 2026 con TITULAR
 * distinto ("Paso de Misterio" vs "Paso de Cristo", o variantes como "Paso
 * Cristo (Nazareno)"/"Paso del Nazareno") — la misma banda no toca dos veces
 * el mismo paso el mismo año, así que es un duplicado de carga, no dos
 * hechos reales. Encontrado al revisar el panel /dashboard/acompanamientos
 * tras pedir "selección múltiple para borrar duplicados" y cruzar con
 * musicofrades.com (que confirma que estas hermandades tienen un solo paso
 * de Cristo con esa banda — la excepción es "El Cerro del Águila", que sí
 * tiene dos pasos de Cristo reales, pero el duplicado detectado es dentro de
 * uno solo de ellos, ver más abajo).
 *
 * ORIGEN del duplicado: el TITULAR "Paso de Misterio" es el de la carga
 * original de temporada 2026 (ids bajos, 1-60); recuperar_historico_
 * acompanamientos.php (2026-09-07) recuperó el histórico 1980-2025 con el
 * TITULAR tal cual venía en resueltos.csv (p.ej. "Paso de Cristo") y, al
 * llegar a 2026, su clave de deduplicación (hermandad|titular|año|banda_raw)
 * no reconoció la fila ya cargada con OTRO texto de TITULAR — insertó una
 * segunda fila en vez de detectar que ya existía.
 *
 * CANÓNICO: para cada (HERMANDAD, ID_BANDA) con duplicado en 2026, el TITULAR
 * a conservar es el que YA se usaba en años anteriores para esa misma pareja
 * hermandad+banda (normalmente 2020-2025 sin interrupción). El otro se borra.
 * Si ningún TITULAR de 2026 coincide con el histórico (no debería pasar) o
 * coinciden los dos, la fila se deja para revisión manual — no se adivina.
 *
 * Re-ejecutable: solo actúa sobre filas que sigan existiendo.
 *
 * Uso:
 *   php php/app/tools/fusionar_titular_duplicado_sevilla_2026.php --dry-run
 *   php php/app/tools/fusionar_titular_duplicado_sevilla_2026.php
 *   DB_PATH=/ruta/a/mdc.db php .../fusionar_titular_duplicado_sevilla_2026.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_titular_duplicado_sevilla_2026' WHERE ID = 1");

    $grupos = $pdo->query(
        "SELECT c.HERMANDAD, c.ID_BANDA
         FROM contrato c
         JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO AND cl.LOCALIDAD = 'Sevilla'
         WHERE c.ANIO = 2026
         GROUP BY c.HERMANDAD, c.ID_BANDA
         HAVING COUNT(DISTINCT COALESCE(c.TITULAR, '')) > 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $filas2026 = $pdo->prepare(
        'SELECT ID_CONTRATO, TITULAR FROM contrato WHERE HERMANDAD = ? AND ID_BANDA = ? AND ANIO = 2026'
    );
    // Solo ANIO=2025 (no "< 2026" en general): varias de estas parejas
    // hermandad+banda arrastran el MISMO duplicado desde 1980-2019 (esa es la
    // "deuda" de docs/acompanamientos-nomina-2026.md, mucho más grande que
    // esto — no se toca aquí), así que "Paso de Misterio" también aparecería
    // como "histórico" hasta 2019 y el criterio no desempataría nada. 2025 es
    // el último año sin el duplicado: ahí sólo sobrevive el TITULAR realmente
    // vigente. Por HERMANDAD, no por (HERMANDAD, ID_BANDA): la banda cambia de
    // un año a otro (contrato normal), el TITULAR no.
    $historico = $pdo->prepare(
        'SELECT DISTINCT COALESCE(TITULAR, \'\') AS T FROM contrato WHERE HERMANDAD = ? AND ANIO = 2025'
    );

    $aBorrar = [];
    $revisar = [];
    foreach ($grupos as $g) {
        $filas2026->execute([$g['HERMANDAD'], $g['ID_BANDA']]);
        $filas = $filas2026->fetchAll(PDO::FETCH_ASSOC);
        $filas2026->closeCursor();

        $historico->execute([$g['HERMANDAD']]);
        $canonicos = array_flip($historico->fetchAll(PDO::FETCH_COLUMN));
        $historico->closeCursor();

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

        if (count($conservar) === 1 && count($borrar) >= 1) {
            foreach ($borrar as $f) {
                $aBorrar[] = (int) $f['ID_CONTRATO'];
            }
            echo "  {$g['HERMANDAD']} (banda #{$g['ID_BANDA']}): conserva #" . $conservar[0]['ID_CONTRATO']
                . " [\"" . ($conservar[0]['TITULAR'] ?? '(vacío)') . "\"], borra "
                . implode(',', array_column($borrar, 'ID_CONTRATO')) . "\n";
        } else {
            $revisar[] = $g['HERMANDAD'] . ' (banda #' . $g['ID_BANDA'] . ')';
        }
    }

    echo 'grupos con duplicado: ' . count($grupos) . "\n";
    echo 'contratos a borrar: ' . count($aBorrar) . "\n";
    if ($revisar !== []) {
        echo "sin canónico claro, revisar a mano (" . count($revisar) . "):\n";
        foreach ($revisar as $r) {
            echo "  - $r\n";
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
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fusionar-titular-duplicado-sevilla-2026.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $placeholders = implode(',', array_fill(0, count($aBorrar), '?'));
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM contrato_localidad WHERE ID_CONTRATO IN ($placeholders)")->execute($aBorrar);
    $pdo->prepare("DELETE FROM contrato_paso WHERE ID_CONTRATO IN ($placeholders)")->execute($aBorrar);
    $borrados = $pdo->prepare("DELETE FROM contrato WHERE ID_CONTRATO IN ($placeholders)");
    $borrados->execute($aBorrar);
    $n = $borrados->rowCount();
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$n contratos borrados\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fusión falló: ' . $e->getMessage() . "\n");
    exit(1);
}
