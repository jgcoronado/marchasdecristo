<?php

declare(strict_types=1);

/*
 * Primer caso de banda_etapa (021_banda_etapa.sql), 2026-10-06:
 *
 *   #8 BCT Fuensanta (Córdoba): 1992–2008, se fusiona con #98 BCT Caído en
 *   #283 BCT Caído y Fuensanta; en 2022 se vuelve a crear con el mismo nombre
 *   mientras la fusionada sigue. Es la misma ficha con dos etapas (decisión
 *   del usuario: una ficha, no dos), así que NO se crea banda nueva.
 *
 * QUÉ HACE:
 *   1. Etapas de #8: 1992–2008 y 2022–(en activo). Los triggers de 021
 *      recalculan banda.FECHA_FUND=1992 y FECHA_EXT=NULL.
 *   2. Año de la fusión: 2008 en las dos relaciones #8 -> #283 y #98 -> #283
 *      (estaba NULL con nota de "año desconocido"; confirmado por el usuario).
 *
 * NO asigna los contratos de 2022 en adelante de la BCT Fuensanta refundada:
 * el usuario confirma que faltan, pero hay que cargarlos con su fuente.
 *
 * Requiere 021 aplicada (migrate_ingest.php). Re-ejecutable: si #8 ya tiene
 * etapas no las toca, y la fecha de la fusión solo se escribe si está vacía.
 *
 * Uso:
 *   php php/app/tools/etapas_banda_fuensanta.php
 *   DB_PATH=/ruta/a/mdc.db php .../etapas_banda_fuensanta.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Etapas abortadas');

const ID_FUENSANTA = 8;
const ID_CAIDO = 98;
const ID_FUSIONADA = 283;
const ANIO_FUSION = 2008;
const NOTA_FUSION = 'fusión en 2008, confirmado por el usuario 2026-10-06 (#8 se extingue en 2008 y #98 en 2007 según `banda`).';

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='banda_etapa'")->fetchColumn() === false) {
        fwrite(STDERR, "Etapas abortadas: falta la tabla banda_etapa — aplica migrate_ingest.php (021_banda_etapa.sql)\n");
        exit(1);
    }
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:etapas_banda_fuensanta' WHERE ID = 1");

    $tieneEtapas = (int) $pdo->query('SELECT COUNT(*) FROM banda_etapa WHERE ID_BANDA = ' . ID_FUENSANTA)->fetchColumn() > 0;
    $sinAnio = (int) $pdo->query(
        'SELECT COUNT(*) FROM banda_relacion WHERE ID_DESTINO = ' . ID_FUSIONADA
        . ' AND ID_ORIGEN IN (' . ID_FUENSANTA . ', ' . ID_CAIDO . ") AND TIPO = 'fusion' AND FECHA_INICIO IS NULL"
    )->fetchColumn();
    if ($tieneEtapas && $sinAnio === 0) {
        echo "nada que hacer (#8 ya tiene etapas y la fusión ya tiene año)\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Etapas abortadas: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-etapas-banda-fuensanta.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();

    if (!$tieneEtapas) {
        $ins = $pdo->prepare('INSERT INTO banda_etapa (ID_BANDA, ANIO_INICIO, ANIO_FIN, NOTA) VALUES (?, ?, ?, ?)');
        $ins->execute([ID_FUENSANTA, 1992, ANIO_FUSION, 'hasta la fusión en BCT Caído y Fuensanta (#283)']);
        $ins->execute([ID_FUENSANTA, 2022, null, 'refundada con el mismo nombre; #283 sigue en activo']);
        echo "etapas de #8: 1992–2008, 2022–hoy\n";
    }

    $upd = $pdo->prepare(
        "UPDATE banda_relacion SET FECHA_INICIO = ?, NOTA = ?
         WHERE ID_ORIGEN = ? AND ID_DESTINO = ? AND TIPO = 'fusion' AND FECHA_INICIO IS NULL"
    );
    foreach ([ID_FUENSANTA, ID_CAIDO] as $origen) {
        $upd->execute([ANIO_FUSION, NOTA_FUSION, $origen, ID_FUSIONADA]);
        echo "fusión #$origen -> #" . ID_FUSIONADA . ': ' . ($upd->rowCount() > 0 ? 'año ' . ANIO_FUSION : 'ya tenía año') . "\n";
    }

    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $b = $pdo->query('SELECT FECHA_FUND, FECHA_EXT FROM banda WHERE ID_BANDA = ' . ID_FUENSANTA)->fetch(PDO::FETCH_ASSOC);
    echo '#8 resumen calculado: FECHA_FUND=' . var_export($b['FECHA_FUND'], true) . ' FECHA_EXT=' . var_export($b['FECHA_EXT'], true) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Etapas fallaron: ' . $e->getMessage() . "\n");
    exit(1);
}
