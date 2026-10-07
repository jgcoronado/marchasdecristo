<?php

declare(strict_types=1);

/*
 * Años como enteros de cuatro cifras en todas las columnas de año (2026-10-06).
 *
 * El volcado de la era MySQL dejó banda.FECHA_FUND / FECHA_EXT y
 * disco.FECHA_CD como TEXT (con "1998.0" en su día, ya limpiado), y el código
 * arrastraba (int) (float) y un preg_replace del ".0" para defenderse. El
 * resto de columnas de año (marcha.FECHA, contrato.ANIO, banda_relacion,
 * banda_etapa…) ya eran INTEGER y estaban limpias.
 *
 * QUÉ HACE:
 *   1. Rehace `banda` y `disco` con esas tres columnas INTEGER y un CHECK
 *      (entero 1000–9999) (SQLite no deja cambiar el tipo de una columna: tabla nueva,
 *      copiar, borrar, renombrar). Recrea sus índices y triggers (trg_log_*)
 *      tal cual estaban, y los de banda_etapa desde 021 (ya sin CAST AS TEXT).
 *   2. autor.F_NAC / F_DEF fuera de 1000–9999 → NULL: los 0 que hacían de
 *      "desconocido" y dos erratas (#55 nacimiento 12, #129 nacimiento 20).
 *      Queda en cambio_log por los triggers de autor.
 *   3. enlace_candidato.ANIO_ENC '' → NULL (tabla de paso; sigue TEXT).
 *
 * Aborta sin tocar nada si algún valor de banda/disco no es un año de 4 cifras.
 * Re-ejecutable: si ya está todo hecho, no hace nada.
 *
 * Uso:
 *   php php/app/tools/normalizar_anios.php
 *   DB_PATH=/ruta/a/mdc.db php .../normalizar_anios.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Normalización abortada');

/** Columnas que pasan a INTEGER, por tabla. El orden y el resto de columnas se respetan. */
const REHACER = ['banda' => ['FECHA_FUND', 'FECHA_EXT'], 'disco' => ['FECHA_CD']];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = static fn(string $sql): mixed => $pdo->query($sql)->fetchColumn();

    // ── Qué queda por hacer ─────────────────────────────────────────────────
    $tipo = static function (string $tabla, string $col) use ($pdo): string {
        foreach ($pdo->query("PRAGMA table_info($tabla)") as $c) {
            if ($c['name'] === $col) return strtoupper((string) $c['type']);
        }
        throw new RuntimeException("falta la columna $tabla.$col");
    };
    $rehacer = [];
    foreach (REHACER as $tabla => $cols) {
        foreach ($cols as $col) {
            if ($tipo($tabla, $col) !== 'INTEGER') { $rehacer[] = $tabla; break; }
        }
    }
    $autorMal = (int) $q('SELECT COUNT(*) FROM autor WHERE F_NAC NOT BETWEEN 1000 AND 9999 OR F_DEF NOT BETWEEN 1000 AND 9999');
    $anioEncVacio = (int) $q("SELECT COUNT(*) FROM enlace_candidato WHERE trim(ANIO_ENC) = ''");
    if ($rehacer === [] && $autorMal === 0 && $anioEncVacio === 0) {
        echo "nada que hacer (columnas ya INTEGER, autor y ANIO_ENC limpios)\n";
        exit(0);
    }

    // Nada de truncar en silencio: un "1998.0" o un "s/f" se arregla a mano antes.
    foreach (REHACER as $tabla => $cols) {
        foreach ($cols as $col) {
            $raros = $pdo->query(
                "SELECT rowid, $col FROM $tabla WHERE $col IS NOT NULL
                   AND NOT (CAST($col AS TEXT) GLOB '[0-9][0-9][0-9][0-9]' AND CAST($col AS INTEGER) >= 1000) LIMIT 20"
            )->fetchAll(PDO::FETCH_NUM);
            if ($raros !== []) {
                fwrite(STDERR, "Normalización abortada: $tabla.$col con valores que no son un año de 4 cifras:\n");
                foreach ($raros as [$id, $v]) fwrite(STDERR, "  #$id " . var_export($v, true) . "\n");
                exit(1);
            }
        }
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Normalización abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-normalizar-anios.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    // Con las FK activas, DROP TABLE banda borraría en cascada / fallaría por
    // las tablas que la referencian. Este PRAGMA no surte efecto dentro de una
    // transacción, por eso va antes del BEGIN.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $fkAntes = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:normalizar_anios' WHERE ID = 1");
    $pdo->beginTransaction();

    // ── 1. banda / disco ────────────────────────────────────────────────────
    if ($rehacer !== []) {
        // Los triggers de banda_etapa escriben en banda: fuera mientras se
        // rehace, y se vuelven a crear desde 021 (que ya escribe enteros).
        $hayEtapas = $q("SELECT 1 FROM sqlite_master WHERE type='table' AND name='banda_etapa'") !== false;
        foreach (['i', 'u', 'd'] as $s) $pdo->exec("DROP TRIGGER IF EXISTS trg_banda_etapa_$s");

        foreach ($rehacer as $tabla) {
            $filas = (int) $q("SELECT COUNT(*) FROM $tabla");
            $deps = $pdo->query("SELECT sql FROM sqlite_master WHERE tbl_name = '$tabla' AND type IN ('index','trigger') AND sql IS NOT NULL")
                ->fetchAll(PDO::FETCH_COLUMN);

            $defs = [];
            $select = [];
            foreach ($pdo->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $n = $c['name'];
                if (in_array($n, REHACER[$tabla], true)) {
                    // La afinidad INTEGER ya convierte '1998' y '1998.0' en 1998
                    // antes del CHECK; typeof deja fuera 1998.5 y textos como 's/f'.
                    $defs[] = "$n INTEGER CHECK ($n IS NULL OR (typeof($n) = 'integer' AND $n BETWEEN 1000 AND 9999))";
                    $select[] = "CAST($n AS INTEGER)";
                    continue;
                }
                $def = $n . ($c['type'] !== '' ? ' ' . $c['type'] : '');
                if ((int) $c['pk'] === 1) $def .= ' PRIMARY KEY';
                if ((int) $c['notnull'] === 1) $def .= ' NOT NULL';
                if ($c['dflt_value'] !== null) $def .= ' DEFAULT ' . $c['dflt_value'];
                $defs[] = $def;
                $select[] = $n;
            }
            $pdo->exec("CREATE TABLE {$tabla}_nueva (\n  " . implode(",\n  ", $defs) . "\n)");
            $pdo->exec("INSERT INTO {$tabla}_nueva SELECT " . implode(', ', $select) . " FROM $tabla");
            $pdo->exec("DROP TABLE $tabla");
            $pdo->exec("ALTER TABLE {$tabla}_nueva RENAME TO $tabla");
            foreach ($deps as $sql) $pdo->exec($sql);

            $copiadas = (int) $q("SELECT COUNT(*) FROM $tabla");
            if ($copiadas !== $filas) throw new RuntimeException("$tabla: $filas filas antes, $copiadas después");
            echo "$tabla rehecha: $copiadas filas, " . count($deps) . " índices/triggers recreados\n";
        }

        if ($hayEtapas) {
            $sql021 = file_get_contents(__DIR__ . '/sql/021_banda_etapa.sql');
            if ($sql021 === false) throw new RuntimeException('no se pudo leer 021_banda_etapa.sql');
            $pdo->exec($sql021);
            echo "triggers de banda_etapa recreados desde 021\n";
        }
    }

    // ── 2. autor ────────────────────────────────────────────────────────────
    foreach ($pdo->query('SELECT ID_AUTOR, APELLIDOS, NOMBRE, F_NAC, F_DEF FROM autor
                           WHERE F_NAC BETWEEN 1 AND 999 OR F_DEF BETWEEN 1 AND 999') as $r) {
        echo "  autor #{$r['ID_AUTOR']} {$r['NOMBRE']} {$r['APELLIDOS']}: F_NAC=" . var_export($r['F_NAC'], true)
            . ' F_DEF=' . var_export($r['F_DEF'], true) . " → NULL (errata, falta el año real)\n";
    }
    $nNac = $pdo->exec('UPDATE autor SET F_NAC = NULL WHERE F_NAC NOT BETWEEN 1000 AND 9999');
    $nDef = $pdo->exec('UPDATE autor SET F_DEF = NULL WHERE F_DEF NOT BETWEEN 1000 AND 9999');
    echo "autor: F_NAC → NULL en $nNac, F_DEF → NULL en $nDef\n";

    // ── 3. enlace_candidato ─────────────────────────────────────────────────
    $nEnc = $pdo->exec("UPDATE enlace_candidato SET ANIO_ENC = NULL WHERE trim(ANIO_ENC) = ''");
    echo "enlace_candidato: ANIO_ENC '' → NULL en $nEnc\n";

    $fkDespues = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
    if ($fkDespues > $fkAntes) throw new RuntimeException("foreign_key_check: $fkAntes fallos antes, $fkDespues después");

    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $ok = $q('PRAGMA integrity_check');
    if ($ok !== 'ok') throw new RuntimeException("integrity_check: $ok");
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    // ── Resultado ───────────────────────────────────────────────────────────
    foreach ([...array_map(null, ['banda', 'banda', 'disco'], ['FECHA_FUND', 'FECHA_EXT', 'FECHA_CD']),
              ['autor', 'F_NAC'], ['autor', 'F_DEF'], ['enlace_candidato', 'ANIO_ENC']] as [$t, $c]) {
        $tipos = $pdo->query("SELECT typeof($c) || ':' || COUNT(*) FROM $t GROUP BY typeof($c)")->fetchAll(PDO::FETCH_COLUMN);
        echo "$t.$c → " . implode(' ', $tipos) . "\n";
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Normalización fallida: ' . $e->getMessage() . "\n");
    exit(1);
}
