<?php

declare(strict_types=1);

/*
 * Backfill: completa marcha.PROVINCIA y banda.PROVINCIA a partir de LOCALIDAD
 * cuando la localidad es identificable de forma inequívoca (tabla estática
 * de localidades españolas vistas en la BD → provincia).
 *
 * Re-ejecutable: solo actualiza filas con PROVINCIA vacía/NULL y LOCALIDAD no
 * vacía; no toca asignaciones ya hechas (manuales o de una ejecución previa).
 * Las localidades que no aparecen en la tabla (dato sucio, p.ej. nombre de
 * hermandad en vez de topónimo, o topónimo ambiguo) se listan al final para
 * revisión manual desde el panel admin y no se tocan.
 *
 * Uso:
 *   php php/app/tools/completar_provincia.php
 *   DB_PATH=/ruta/a/mdc.db php .../completar_provincia.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay
 * algo que actualizar.
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];

if (!is_file($db)) {
    fwrite(STDERR, "Backfill abortado: no existe la BD en $db\n");
    exit(1);
}

/** Quita acentos, colapsa espacios y pasa a minúsculas para comparar localidades de forma robusta. */
function normalizaLocalidad(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    return (string) preg_replace('/\s+/', ' ', $s);
}

/**
 * Localidad (normalizada) => provincia. Solo topónimos reales vistos en la
 * BD con provincia ambigua/ausente; localidades ya resueltas o dudosas
 * (nombre de hermandad, entradas vacías, etc.) se dejan fuera a propósito.
 */
$LOCALIDAD_PROVINCIA = [
    'andorra - teruel' => 'Teruel',
    'aracena' => 'Huelva',
    'carmona' => 'Sevilla',
    'cadiz' => 'Cádiz',
    'cordoba' => 'Córdoba',
    'dos hermanas' => 'Sevilla',
    'el puerto de santa maria' => 'Cádiz',
    'el vendrell' => 'Tarragona',
    'estepa' => 'Sevilla',
    'granada' => 'Granada',
    'jerez de la frontera' => 'Cádiz',
    'mairena del alcor' => 'Sevilla',
    'malaga' => 'Málaga',
    'san jacinto' => 'Sevilla',
    'santiago de compostela' => 'La Coruña',
    'sevilla' => 'Sevilla',
    'alcala de guadaira' => 'Sevilla',
    'almeria' => 'Almería',
    'andujar' => 'Jaén',
    'arriate' => 'Málaga',
    'bollullos par del condado' => 'Huelva',
    'brenes' => 'Sevilla',
    'campillos' => 'Málaga',
    'campo de criptana' => 'Ciudad Real',
    'carcabuey' => 'Córdoba',
    'castilleja de la cuesta' => 'Sevilla',
    'ciudad real' => 'Ciudad Real',
    'coria del rio' => 'Sevilla',
    'crevillente' => 'Alicante',
    'caceres' => 'Cáceres',
    'daimiel' => 'Ciudad Real',
    'estepona' => 'Málaga',
    'ferrol' => 'La Coruña',
    'guadalcanal' => 'Sevilla',
    'huelva' => 'Huelva',
    'huescar' => 'Granada',
    'jaen' => 'Jaén',
    'jodar' => 'Jaén',
    'la algaba' => 'Sevilla',
    'la carlota' => 'Córdoba',
    'la puebla de cazalla' => 'Sevilla',
    'la rambla' => 'Córdoba',
    'la roda de andalucia' => 'Sevilla',
    'lebrija' => 'Sevilla',
    'leon' => 'León',
    'linares' => 'Jaén',
    'lorca' => 'Murcia',
    'los palacios y villafranca' => 'Sevilla',
    'marchena' => 'Sevilla',
    'martos' => 'Jaén',
    'montilla' => 'Córdoba',
    'moron de la frontera' => 'Sevilla',
    'ocana' => 'Toledo',
    'palma de mallorca' => 'Baleares',
    'palma del rio' => 'Córdoba',
    'pilas' => 'Sevilla',
    'pozoblanco' => 'Córdoba',
    'san juan de aznalfarache' => 'Sevilla',
    'sanlucar de barrameda' => 'Cádiz',
    'sanlucar la mayor' => 'Sevilla',
    'talavera de la reina' => 'Toledo',
    'ubeda' => 'Jaén',
    'valencia' => 'Valencia',
    'valladolid' => 'Valladolid',
    'avila' => 'Ávila',
    'ecija' => 'Sevilla',
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:completar_provincia' WHERE ID = 1");

    $pendientes = []; // localidad original => tabla

    foreach (['marcha', 'banda'] as $tabla) {
        $idCol = $tabla === 'marcha' ? 'ID_MARCHA' : 'ID_BANDA';
        $rows = $pdo->query(
            "SELECT $idCol AS id, LOCALIDAD FROM $tabla
             WHERE (PROVINCIA IS NULL OR PROVINCIA = '') AND LOCALIDAD IS NOT NULL AND TRIM(LOCALIDAD) != ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $key = normalizaLocalidad((string) $r['LOCALIDAD']);
            if (!isset($LOCALIDAD_PROVINCIA[$key])) {
                $pendientes[(string) $r['LOCALIDAD']] = $tabla;
            }
        }
    }

    // ¿Hay algo que actualizar? Si no, no tiene sentido ni backup ni transacción.
    $porActualizar = 0;
    foreach (['marcha', 'banda'] as $tabla) {
        $idCol = $tabla === 'marcha' ? 'ID_MARCHA' : 'ID_BANDA';
        $rows = $pdo->query(
            "SELECT $idCol AS id, LOCALIDAD FROM $tabla
             WHERE (PROVINCIA IS NULL OR PROVINCIA = '') AND LOCALIDAD IS NOT NULL AND TRIM(LOCALIDAD) != ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (isset($LOCALIDAD_PROVINCIA[normalizaLocalidad((string) $r['LOCALIDAD'])])) {
                $porActualizar++;
            }
        }
    }

    if ($porActualizar === 0) {
        echo "nada que actualizar (0 filas resolubles)\n";
    } else {
        $backupDir = dirname($db) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            fwrite(STDERR, "Backfill abortado: no se pudo crear $backupDir\n");
            exit(1);
        }
        $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-completar-provincia.db';
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        echo 'backup: ' . $dest . "\n";

        $pdo->beginTransaction();
        foreach (['marcha', 'banda'] as $tabla) {
            $idCol = $tabla === 'marcha' ? 'ID_MARCHA' : 'ID_BANDA';
            $upd = $pdo->prepare("UPDATE $tabla SET PROVINCIA = ? WHERE $idCol = ?");
            $rows = $pdo->query(
                "SELECT $idCol AS id, LOCALIDAD FROM $tabla
                 WHERE (PROVINCIA IS NULL OR PROVINCIA = '') AND LOCALIDAD IS NOT NULL AND TRIM(LOCALIDAD) != ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            $n = 0;
            foreach ($rows as $r) {
                $prov = $LOCALIDAD_PROVINCIA[normalizaLocalidad((string) $r['LOCALIDAD'])] ?? null;
                if ($prov === null) {
                    continue;
                }
                $upd->execute([$prov, $r['id']]);
                $n++;
            }
            echo "$tabla: $n filas actualizadas\n";
        }
        $pdo->commit();

        $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
    }

    if ($pendientes !== []) {
        echo "\npendientes de revisión manual (" . count($pendientes) . " localidad(es), sin match en la tabla):\n";
        foreach ($pendientes as $loc => $tabla) {
            echo "  [$tabla] " . ($loc === '' ? '(vacío)' : $loc) . "\n";
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Backfill falló: ' . $e->getMessage() . "\n");
    exit(1);
}
