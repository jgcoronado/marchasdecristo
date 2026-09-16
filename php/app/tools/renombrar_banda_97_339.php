<?php

declare(strict_types=1);

/*
 * Registra el renombrado de la banda #97 (2026-09-11): la "Banda De Cornetas
 * Y Tambores De Las Reales Cofradías Fusionadas" (Málaga) pasó a llamarse
 * "Agrupación Musical de las Reales Cofradías Fusionadas de San Juan" desde
 * 2024 — el seed de acompañamientos SS2026 la dio de alta como banda #339 al
 * no encontrar coincidencia por NOMBRE_COMPLETO. A diferencia de las parejas
 * de fusionar_bandas_duplicadas_ss2026.php (duplicados de carga sin historia
 * propia), el usuario confirmó 2026-09-11 que aquí sí hay lineage real: es la
 * MISMA entidad con nombre nuevo, así que se registra como 'renombrado' en
 * `banda_relacion` (mismo patrón que las 14 filas 'renombrado' ya existentes:
 * ID_ORIGEN = identidad antigua, ID_DESTINO = identidad vigente) en vez de
 * fusionar contratos o borrar ninguna de las dos filas de `banda`. Los
 * contratos ya cargados bajo #97 o #339 no se tocan (la banda de un contrato
 * histórico es la que firmó ESE año).
 *
 * También marca el año de cambio en `banda` (FECHA_EXT de la antigua,
 * FECHA_FUND de la nueva) siguiendo el mismo patrón que las demás filas
 * 'renombrado' de banda_relacion (p.ej. #89 FECHA_EXT=1991 / #6
 * FECHA_FUND=1991).
 *
 * Re-ejecutable: comprueba la relación (ID_ORIGEN, ID_DESTINO, TIPO) antes de
 * insertar (además es el UNIQUE de la tabla) y solo pisa FECHA_EXT/FECHA_FUND
 * si están a NULL.
 *
 * Uso:
 *   php php/app/tools/renombrar_banda_97_339.php
 *   DB_PATH=/ruta/a/mdc.db php .../renombrar_banda_97_339.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Renombrado abortado');

const ID_ANTIGUA = 97;
const ID_NUEVA = 339;
const ANIO_CAMBIO = 2024;
const NOTA_RELACION = 'la banda #97 ("Banda De Cornetas Y Tambores De Las Reales Cofradías Fusionadas") '
    . 'pasó a llamarse "Agrupación Musical de las Reales Cofradías Fusionadas de San Juan" desde 2024; '
    . 'el seed de acompañamientos SS2026 la dio de alta como banda nueva (#339) al no reconocer el nombre. '
    . 'Confirmado por el usuario 2026-09-11.';

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:renombrar_banda_97_339' WHERE ID = 1");

    $buscaRel = $pdo->prepare(
        'SELECT COUNT(*) FROM banda_relacion WHERE ID_ORIGEN = ? AND ID_DESTINO = ? AND TIPO = ?'
    );
    $buscaRel->execute([ID_ANTIGUA, ID_NUEVA, 'renombrado']);
    $yaExiste = (int) $buscaRel->fetchColumn() > 0;
    $buscaRel->closeCursor();
    if ($yaExiste) {
        echo "nada que hacer (la relación 'renombrado' #" . ID_ANTIGUA . " -> #" . ID_NUEVA . " ya existe)\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Renombrado abortado: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-renombrar-banda-97-339.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();

    $pdo->prepare('INSERT INTO banda_relacion (ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO, NOTA) VALUES (?, ?, ?, ?, ?)')
        ->execute([ID_ANTIGUA, ID_NUEVA, 'renombrado', ANIO_CAMBIO, NOTA_RELACION]);

    $pdo->prepare('UPDATE banda SET FECHA_EXT = ? WHERE ID_BANDA = ? AND FECHA_EXT IS NULL')
        ->execute([(string) ANIO_CAMBIO, ID_ANTIGUA]);
    $pdo->prepare('UPDATE banda SET FECHA_FUND = ? WHERE ID_BANDA = ? AND FECHA_FUND IS NULL')
        ->execute([(string) ANIO_CAMBIO, ID_NUEVA]);

    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo 'relación de renombrado insertada: #' . ID_ANTIGUA . ' -> #' . ID_NUEVA . "\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Renombrado falló: ' . $e->getMessage() . "\n");
    exit(1);
}
