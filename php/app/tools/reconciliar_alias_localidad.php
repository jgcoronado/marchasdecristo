<?php

declare(strict_types=1);

/*
 * Reconciliación: dedicatoria_alias.LOCALIDAD puede quedar desincronizada de
 * marcha.LOCALIDAD tras normalizar_localidades.php,
 * normalizar_preposiciones_localidad.php o corregir_acentos_localidad.php —
 * esos scripts renombran marcha.LOCALIDAD pero no tocan dedicatoria_alias, y
 * el enlace entre una marcha y su dedicatoria es un JOIN por texto exacto:
 *
 *   marcha m JOIN dedicatoria_alias da
 *     ON da.VARIANTE = m.DEDICATORIA AND da.LOCALIDAD = COALESCE(m.LOCALIDAD, '')
 *
 * Si el alias queda con la grafía vieja, las marchas afectadas desaparecen en
 * silencio de la ficha de su dedicatoria (Repo::fetchDedicatoria devuelve
 * menos filas, o 404 si eran las únicas).
 *
 * En vez de repetir a mano el historial de cada renombrado, este script
 * compara directamente contra el estado actual de marcha (fuente de verdad):
 * para cada VARIANTE de dedicatoria_alias, si hay exactamente un alias que ya
 * no coincide con ninguna LOCALIDAD real de esa variante ("huérfano") y
 * exactamente una LOCALIDAD real sin alias que la cubra ("hueco"), es un
 * renombrado 1:1 sin ambigüedad — se actualiza el alias. Cualquier caso menos
 * evidente (varios huérfanos/huecos a la vez) se deja para revisión manual
 * en vez de adivinar.
 *
 * Re-ejecutable: si no hay huérfanos no toca nada.
 *
 * Uso:
 *   php php/app/tools/reconciliar_alias_localidad.php
 *   DB_PATH=/ruta/a/mdc.db php .../reconciliar_alias_localidad.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay
 * algo que actualizar.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Reconciliación abortada');

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // LOCALIDAD real (coalesce a '', igual que el JOIN) por cada DEDICATORIA
    // presente en marcha.
    $marchaPorVariante = []; // VARIANTE => set de LOCALIDAD actuales
    $stmt = $pdo->query(
        "SELECT DISTINCT DEDICATORIA AS v, COALESCE(LOCALIDAD, '') AS loc FROM marcha
         WHERE DEDICATORIA IS NOT NULL AND DEDICATORIA != ''"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $marchaPorVariante[$r['v']][$r['loc']] = true;
    }
    $stmt->closeCursor();

    // Alias actuales por variante.
    $aliasPorVariante = []; // VARIANTE => [LOCALIDAD => ID_DEDIC]
    $stmt = $pdo->query('SELECT VARIANTE, LOCALIDAD, ID_DEDIC FROM dedicatoria_alias');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aliasPorVariante[$r['VARIANTE']][$r['LOCALIDAD']] = (int) $r['ID_DEDIC'];
    }
    $stmt->closeCursor();

    $renombrar = []; // [variante, viejo, nuevo, dedic_id]
    $ambiguos = [];  // variantes con más de un hueco/huérfano a la vez, para revisar a mano
    foreach ($aliasPorVariante as $variante => $alias) {
        $marchaLocs = array_keys($marchaPorVariante[$variante] ?? []);
        $aliasLocs = array_keys($alias);

        $huerfanos = array_values(array_diff($aliasLocs, $marchaLocs));
        $huecos = array_values(array_diff($marchaLocs, $aliasLocs));

        if ($huerfanos === []) {
            continue; // todo cubierto, nada que hacer con esta variante
        }
        if (count($huerfanos) === 1 && count($huecos) === 1) {
            $renombrar[] = [$variante, $huerfanos[0], $huecos[0], $alias[$huerfanos[0]]];
        } else {
            $ambiguos[] = ['variante' => $variante, 'huerfanos' => $huerfanos, 'huecos' => $huecos];
        }
    }

    if ($renombrar === [] && $ambiguos === []) {
        echo "nada que reconciliar (todos los alias coinciden con marcha)\n";
        exit(0);
    }

    if ($ambiguos !== []) {
        echo "casos ambiguos (varios huérfanos/huecos a la vez) — revisar a mano en el panel admin:\n";
        foreach ($ambiguos as $a) {
            echo "  [\"{$a['variante']}\"] huérfanos: " . implode(', ', array_map(fn($v) => "\"$v\"", $a['huerfanos']))
                . ' | huecos: ' . implode(', ', array_map(fn($v) => "\"$v\"", $a['huecos'])) . "\n";
        }
        echo "\n";
    }

    if ($renombrar === []) {
        echo "sin renombrados 1:1 claros — nada más que hacer automáticamente\n";
        exit(0);
    }

    echo "alias a renombrar (coincide 1 huérfano con 1 hueco, sin ambigüedad):\n";
    foreach ($renombrar as $r) {
        echo "  [\"{$r[0]}\"] \"{$r[1]}\" -> \"{$r[2]}\"\n";
    }
    echo "\n";

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Reconciliación abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-reconciliar-alias.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE dedicatoria_alias SET LOCALIDAD = ? WHERE VARIANTE = ? AND LOCALIDAD = ?');
    $n = 0;
    foreach ($renombrar as [$variante, $viejo, $nuevo]) {
        $upd->execute([$nuevo, $variante, $viejo]);
        $n += $upd->rowCount();
    }
    $pdo->commit();
    // Fuerza el volcado del WAL al fichero principal: sin esto, en bases en
    // modo journal_mode=WAL (las que ha abierto la app en local/producción)
    // los cambios pueden quedar solo en el -wal y no verse desde otra
    // conexión (p.ej. una copia del fichero hecha justo después) hasta el
    // próximo checkpoint automático.
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "dedicatoria_alias: $n filas actualizadas\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Reconciliación falló: ' . $e->getMessage() . "\n");
    exit(1);
}
