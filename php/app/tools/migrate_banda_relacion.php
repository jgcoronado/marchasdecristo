<?php

declare(strict_types=1);

/*
 * Migración one-shot: modelo de linaje de bandas.
 *
 *   FORMACION_ANT / FORMACION_SIG (+ slots -2)  ->  tabla banda_relacion
 *
 * A diferencia de los .sql de tools/sql/ (idempotentes, los aplica migrate_ingest.php),
 * este script hace un backfill de datos y un DROP COLUMN, que NO son idempotentes por
 * sí mismos. Es re-ejecutable con seguridad igualmente:
 *   - el backfill usa INSERT OR IGNORE contra el UNIQUE de banda_relacion;
 *   - si las columnas FORMACION_* ya no existen, se salta el backfill y los DROP.
 *
 * Uso:
 *   php php/app/tools/migrate_banda_relacion.php            # sobre php/data/mdc.db
 *   DB_PATH=/ruta/a/mdc.db php .../migrate_banda_relacion.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Migración abortada');

$ddlFile = __DIR__ . '/sql/002_banda_relacion.sql';
if (!is_file($ddlFile)) {
    fwrite(STDERR, "Migración abortada: no existe $ddlFile\n");
    exit(1);
}

/** Aristas inversas anómalas a descartar en el backfill (pares recíprocos). */
const ARISTAS_EXCLUIDAS = ['41-68']; // conservamos sólo 68->41 (2003); ver docs/db-analysis.md

/** ¿El valor de una columna FORMACION_* apunta a una banda real? */
function esRef(mixed $v): bool
{
    return $v !== null && !in_array((string) $v, ['0', '0.0', ''], true);
}

/** Normaliza '2003.0' / '2003' -> 2003 ; vacío -> null. */
function aAnio(mixed $v): ?int
{
    return esRef($v) ? (int) (float) $v : null;
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:migrate_banda_relacion' WHERE ID = 1");

    // 1) Backup consistente antes de nada.
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Migración abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-relacion.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    // 2) Asegurar el esquema nuevo (idempotente).
    $pdo->exec((string) file_get_contents($ddlFile));
    echo "tabla banda_relacion asegurada\n";

    // 3) ¿Siguen existiendo las columnas FORMACION_*? Si no, ya se migró.
    $cols = $pdo->query('PRAGMA table_info(banda)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('FORMACION_ANT', $cols, true)) {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM banda_relacion')->fetchColumn();
        echo "columnas FORMACION_* ya eliminadas; nada que migrar (banda_relacion: $n filas)\n";
        exit(0);
    }

    $pdo->exec('PRAGMA foreign_keys = OFF'); // recomendado durante ALTER TABLE
    $pdo->beginTransaction();

    // 4) Backfill: derivar aristas de ANT/SIG y deduplicar.
    $rows = $pdo->query('SELECT ID_BANDA, FORMACION_ANT, FORMACION_SIG, FECHA_FUND FROM banda')
        ->fetchAll(PDO::FETCH_ASSOC);
    $fund = [];
    foreach ($rows as $r) {
        $fund[(int) $r['ID_BANDA']] = $r['FECHA_FUND'];
    }
    $edges = []; // "origen-destino" => [origen, destino]
    foreach ($rows as $r) {
        $id = (int) $r['ID_BANDA'];
        if (esRef($r['FORMACION_ANT'])) {
            $o = (int) (float) $r['FORMACION_ANT'];
            $edges["$o-$id"] = [$o, $id];   // ANT: formación anterior -> esta
        }
        if (esRef($r['FORMACION_SIG'])) {
            $d = (int) (float) $r['FORMACION_SIG'];
            $edges["$id-$d"] = [$id, $d];   // SIG: esta -> formación siguiente
        }
    }
    foreach (ARISTAS_EXCLUIDAS as $k) {
        unset($edges[$k]);
    }

    $ins = $pdo->prepare(
        "INSERT OR IGNORE INTO banda_relacion (ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO)
         VALUES (?, ?, 'renombrado', ?)"
    );
    $insertadas = 0;
    foreach ($edges as [$o, $d]) {
        $ins->execute([$o, $d, aAnio($fund[$d] ?? null)]);
        $insertadas += $ins->rowCount();
    }
    echo 'aristas derivadas: ' . count($edges) . " (excluidas: " . implode(', ', ARISTAS_EXCLUIDAS) . ")\n";
    echo "aristas insertadas (renombrado): $insertadas\n";

    // 5) Retirar índices que dependen de las columnas a borrar, luego borrarlas.
    $pdo->exec('DROP INDEX IF EXISTS idx_banda_formacion_ant');
    $pdo->exec('DROP INDEX IF EXISTS idx_banda_formacion_sig');
    foreach (['FORMACION_ANT', 'FORMACION_ANT2', 'FORMACION_SIG', 'FORMACION_SIG2'] as $c) {
        if (in_array($c, $cols, true)) {
            $pdo->exec("ALTER TABLE banda DROP COLUMN $c");
            echo "columna eliminada: $c\n";
        }
    }

    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');

    // 6) Verificación de integridad.
    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($fk !== []) {
        fwrite(STDERR, "AVISO: foreign_key_check reporta problemas:\n" . print_r($fk, true));
    }
    $total = (int) $pdo->query('SELECT COUNT(*) FROM banda_relacion')->fetchColumn();
    echo "OK. banda_relacion: $total filas. FK check: " . ($fk === [] ? 'limpio' : 'REVISAR') . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migración falló: ' . $e->getMessage() . "\n");
    exit(1);
}
