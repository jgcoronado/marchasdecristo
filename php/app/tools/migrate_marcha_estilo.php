<?php

declare(strict_types=1);

/*
 * Migración one-shot: estilo de cada marcha (CCTT / AM).
 *
 *   marcha.ESTILO TEXT CHECK (ESTILO IN ('CCTT','AM'))
 *
 * El estilo no se guarda en banda: se deriva del nombre de la banda que
 * estrenó la marcha (marcha.BANDA_ESTRENO) y, si no hay estreno, de la
 * banda de su primera grabación documentada (mismo criterio y orden que
 * Repo::fetchMarcha() usa para "primera grabación": disco_marcha + disco,
 * ORDER BY CAST(FECHA_CD AS REAL) ASC, NOMBRE_CD ASC, banda = DM_BANDA si
 * existe si no BANDADISCO). Si ninguna de las dos resuelve, o el nombre de
 * la banda no indica el estilo con claridad, la marcha queda sin asignar
 * (ESTILO = NULL) para revisión manual desde el panel admin.
 *
 * Re-ejecutable: si la columna ESTILO ya existe se aborta sin tocar nada
 * (el backfill no se repite para no pisar asignaciones manuales hechas
 * desde entonces).
 *
 * Uso:
 *   php php/app/tools/migrate_marcha_estilo.php
 *   DB_PATH=/ruta/a/mdc.db php .../migrate_marcha_estilo.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Migración abortada');

/** Quita acentos y pasa a minúsculas para comparar nombres de banda de forma robusta. */
function normalizaNombre(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
}

/**
 * Estilo a partir del nombre de una banda, o null si el nombre no lo deja claro.
 * "Banda de Cornetas y Tambores…" -> CCTT ; "Agrupación Musical…" (con la
 * variante mal tildada "Agrpación") o el prefijo "AM " -> AM. Cuando el
 * nombre completo indica un estilo se prioriza sobre el nombre breve
 * (algunas fichas tienen el NOMBRE_BREVE desactualizado, p.ej. banda #92).
 */
function estiloPorNombre(?string $nombreCompleto, ?string $nombreBreve): ?string
{
    $full = normalizaNombre($nombreCompleto ?? '');
    $breve = normalizaNombre($nombreBreve ?? '');

    $esCCTT = str_contains($full, 'cornetas y tambores');
    $esAM = (bool) preg_match('/\bagr[a-z]{0,3}paci[o0]n musical\b/', $full) || (bool) preg_match('/^am\b/', $full);
    if ($esCCTT && !$esAM) return 'CCTT';
    if ($esAM && !$esCCTT) return 'AM';
    // Llegar aquí con $esCCTT implica que $esAM también es cierto: las dos líneas
    // de arriba ya han descartado los casos excluyentes. Nombre contradictorio,
    // se revisa a mano.
    if ($esCCTT) return null;

    if (preg_match('/^am\b/', $breve)) return 'AM';
    if (preg_match('/^bct\b/', $breve)) return 'CCTT';

    return null;
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1) ¿Ya migrado? La columna ESTILO no existe todavía si no.
    $cols = $pdo->query('PRAGMA table_info(marcha)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('ESTILO', $cols, true)) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM marcha WHERE ESTILO IS NOT NULL")->fetchColumn();
        echo "columna ESTILO ya existe; nada que migrar ($n marchas con estilo asignado)\n";
        exit(0);
    }

    // 2) Backup consistente antes de nada.
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Migración abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-marcha-estilo.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    // 3) Clasificar cada banda por su nombre.
    $bandas = $pdo->query('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE FROM banda')->fetchAll(PDO::FETCH_ASSOC);
    $estiloBanda = []; // ID_BANDA => 'CCTT'|'AM'|null
    foreach ($bandas as $b) {
        $estiloBanda[(int) $b['ID_BANDA']] = estiloPorNombre($b['NOMBRE_COMPLETO'], $b['NOMBRE_BREVE']);
    }
    $sinEstilo = array_keys(array_filter($estiloBanda, static fn($v) => $v === null));
    echo 'bandas clasificadas: ' . count($bandas) . ' (' . count($sinEstilo) . ' sin estilo claro por nombre: ' . implode(',', $sinEstilo) . ")\n";

    $pdo->beginTransaction();

    // 4) Columna nueva.
    $pdo->exec("ALTER TABLE marcha ADD COLUMN ESTILO TEXT CHECK (ESTILO IN ('CCTT','AM'))");

    // 5) Primera banda que grabó cada marcha (mismo criterio que Repo::fetchMarcha):
    //    disco_marcha + disco, ORDER BY año ASC, nombre de disco ASC; banda = DM_BANDA
    //    si existe, si no BANDADISCO. Nos quedamos con la primera fila por marcha.
    $primeraGrabacion = []; // IDMARCHA => ID_BANDA
    $stmt = $pdo->query(
        "SELECT dm.IDMARCHA, COALESCE(dm.DM_BANDA, d.BANDADISCO) AS ID_BANDA
         FROM disco_marcha dm
         INNER JOIN disco d ON d.ID_DISCO = dm.ID_DISCO
         ORDER BY dm.IDMARCHA ASC, CAST(d.FECHA_CD AS REAL) ASC, d.NOMBRE_CD ASC"
    );
    foreach ($stmt as $r) {
        $mid = (int) $r['IDMARCHA'];
        if (!isset($primeraGrabacion[$mid]) && $r['ID_BANDA'] !== null) {
            $primeraGrabacion[$mid] = (int) $r['ID_BANDA'];
        }
    }

    // 6) Backfill: BANDA_ESTRENO primero, si no la banda de la primera grabación.
    $marchas = $pdo->query('SELECT ID_MARCHA, BANDA_ESTRENO FROM marcha')->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare('UPDATE marcha SET ESTILO = ? WHERE ID_MARCHA = ?');
    $porEstreno = 0;
    $porGrabacion = 0;
    $pendientes = 0;
    foreach ($marchas as $m) {
        $mid = (int) $m['ID_MARCHA'];
        $bandaEstreno = $m['BANDA_ESTRENO'] !== null && (int) $m['BANDA_ESTRENO'] !== 0 ? (int) $m['BANDA_ESTRENO'] : null;

        $bandaId = null;
        $viaGrabacion = false;
        if ($bandaEstreno !== null && array_key_exists($bandaEstreno, $estiloBanda)) {
            $bandaId = $bandaEstreno;
        } elseif (isset($primeraGrabacion[$mid]) && array_key_exists($primeraGrabacion[$mid], $estiloBanda)) {
            $bandaId = $primeraGrabacion[$mid];
            $viaGrabacion = true;
        }

        $estilo = $bandaId !== null ? $estiloBanda[$bandaId] : null;
        if ($estilo === null) {
            $pendientes++;
            continue;
        }
        $upd->execute([$estilo, $mid]);
        if ($viaGrabacion) $porGrabacion++; else $porEstreno++;
    }

    $pdo->commit();

    echo "marchas: " . count($marchas) . "\n";
    echo "  asignadas por banda de estreno:    $porEstreno\n";
    echo "  asignadas por primera grabación:   $porGrabacion\n";
    echo "  pendientes de asignar:             $pendientes\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migración falló: ' . $e->getMessage() . "\n");
    exit(1);
}
