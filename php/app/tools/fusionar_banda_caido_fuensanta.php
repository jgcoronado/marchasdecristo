<?php

declare(strict_types=1);

/*
 * Corrección puntual sobre `banda` (2026-09-07), encontrada al proponer los
 * contratos de la nómina de Córdoba: el programa oficial 2026 cita varias
 * veces una única banda, "Banda de Cornetas y Tambores de Nuestro Padre Jesús
 * Caído y Ntra. Sra. de la Fuensanta" (así la nombra la propia hermandad
 * "Caído", cuyos titulares son justo esos dos), pero `banda` la tiene partida
 * en dos entidades:
 *
 *   #8  Banda De Cornetas Y Tambores Nuestra Señora De La Fuensanta (Córdoba)
 *   #98 Banda De Cornetas Y Tambores Nuestro Padre Jesús Caído (Córdoba)
 *
 * Pista que confirma la fusión: las dos tienen FECHA_EXT (2008 y 2007) ya
 * cargada, casi seguida una de otra — coherente con que se fusionaran por
 * esas fechas en vez de con que cada una desapareciera sin más. Confirmado
 * por el usuario 2026-09-07.
 *
 * QUÉ HACE: da de alta una banda NUEVA (no reutiliza el #8 ni el #98, tal
 * como pidió el usuario) para la formación fusionada, y registra el vínculo
 * en `banda_relacion` (002_banda_relacion.sql) con TIPO='fusion': #8 -> nueva,
 * #98 -> nueva. NO toca los contratos históricos que ya apuntan a #8 o #98
 * (igual que las filas 'renombrado' ya existentes en banda_relacion: la
 * banda de un contrato histórico es la que firmó ESE año, no la formación
 * resultante — el lineage se consulta por banda_relacion, no reescribiendo
 * contrato.ID_BANDA).
 *
 * FECHA_INICIO de la fusión: no se conoce el año exacto (podría ser 2007 u
 * 2008, las dos fechas de extinción de origen); se deja NULL con nota en vez
 * de inventar una.
 *
 * Re-ejecutable: comprueba por NOMBRE_COMPLETO antes de insertar la banda, y
 * por (ID_ORIGEN, ID_DESTINO, TIPO) antes de insertar cada relación (además
 * es el UNIQUE de la tabla).
 *
 * Uso:
 *   php php/app/tools/fusionar_banda_caido_fuensanta.php
 *   DB_PATH=/ruta/a/mdc.db php .../fusionar_banda_caido_fuensanta.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

const ID_FUENSANTA = 8;
const ID_CAIDO = 98;
const NOMBRE_COMPLETO = 'Banda De Cornetas Y Tambores Nuestro Padre Jesús Caído Y Nuestra Señora De La Fuensanta';
const NOMBRE_BREVE = 'BCT Caído y Fuensanta';
const NOTA_RELACION = 'año de fusión desconocido: #8 se extingue en 2008 y #98 en 2007 según `banda`, '
    . 'coherente con la fusión pero sin fecha exacta confirmada. Encontrado al proponer '
    . 'los contratos de Córdoba 2026 (el programa oficial cita la banda combinada varias veces).';

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_banda_caido_fuensanta' WHERE ID = 1");

    $buscaBanda = $pdo->prepare('SELECT ID_BANDA FROM banda WHERE NOMBRE_COMPLETO = ?');
    $buscaBanda->execute([NOMBRE_COMPLETO]);
    $idNueva = $buscaBanda->fetchColumn();
    $buscaBanda->closeCursor();

    $buscaRel = $pdo->prepare(
        'SELECT COUNT(*) FROM banda_relacion WHERE ID_ORIGEN = ? AND ID_DESTINO = ? AND TIPO = ?'
    );

    if ($idNueva !== false) {
        $buscaRel->execute([ID_FUENSANTA, (int) $idNueva, 'fusion']);
        $relFuensanta = (int) $buscaRel->fetchColumn();
        $buscaRel->execute([ID_CAIDO, (int) $idNueva, 'fusion']);
        $relCaido = (int) $buscaRel->fetchColumn();
        $buscaRel->closeCursor();
        if ($relFuensanta > 0 && $relCaido > 0) {
            echo "nada que hacer (banda #$idNueva y sus dos relaciones de fusión ya existen)\n";
            exit(0);
        }
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Fusión abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-fusionar-banda-caido-fuensanta.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();

    if ($idNueva === false) {
        $origen = $pdo->query('SELECT LOCALIDAD, PROVINCIA FROM banda WHERE ID_BANDA = ' . ID_FUENSANTA)->fetch();
        $insBanda = $pdo->prepare(
            'INSERT INTO banda (NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD, PROVINCIA) VALUES (?, ?, ?, ?)'
        );
        $insBanda->execute([NOMBRE_COMPLETO, NOMBRE_BREVE, $origen['LOCALIDAD'], $origen['PROVINCIA']]);
        $idNueva = (int) $pdo->lastInsertId();
        echo 'banda creada: #' . $idNueva . ' ' . NOMBRE_COMPLETO . "\n";
    } else {
        $idNueva = (int) $idNueva;
        echo "banda ya existía: #$idNueva\n";
    }

    $insRel = $pdo->prepare(
        'INSERT OR IGNORE INTO banda_relacion (ID_ORIGEN, ID_DESTINO, TIPO, NOTA) VALUES (?, ?, ?, ?)'
    );
    $insRel->execute([ID_FUENSANTA, $idNueva, 'fusion', NOTA_RELACION]);
    $insRel->execute([ID_CAIDO, $idNueva, 'fusion', NOTA_RELACION]);

    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "relaciones de fusión insertadas: #" . ID_FUENSANTA . " -> #$idNueva, #" . ID_CAIDO . " -> #$idNueva\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Fusión falló: ' . $e->getMessage() . "\n");
    exit(1);
}
