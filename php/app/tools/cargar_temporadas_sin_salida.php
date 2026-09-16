<?php

declare(strict_types=1);

/*
 * Carga en `temporada_sin_salida` (014_temporada_sin_salida.sql) los años en
 * que una localidad no tuvo salida procesional, desde
 * `temporadas_sin_salida.csv` (raíz del repositorio).
 *
 * Hoy son 2020 y 2021 en las siete localidades: la pandemia dejó a todas las
 * hermandades sin estación de penitencia. Sin esta tabla, /acompanamientos
 * enseña un hueco mudo entre "2022-2026" y "2019" porque `contrato` es una
 * fila por año con banda y esos dos años no la tienen. No se inventan
 * contratos: se registra por qué falta el año.
 *
 * Idempotente por la clave primaria (LOCALIDAD, ANIO): se puede reejecutar.
 *
 * Uso:
 *   php php/app/tools/cargar_temporadas_sin_salida.php [--dry-run]
 *   DB_PATH=/ruta/a/mdc.db php .../cargar_temporadas_sin_salida.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Carga de temporadas sin salida abortada');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$csv = dirname(BASE_DIR) . '/temporadas_sin_salida.csv';

/** @return list<array<string,string>> */
function leerCsvSinSalida(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return [];
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return [];
    }
    $rows = [];
    while (($fila = fgetcsv($fh)) !== false) {
        if (count($fila) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $fila);
    }
    fclose($fh);
    return $rows;
}

try {
    $filas = leerCsvSinSalida($csv);
    if ($filas === []) {
        echo "nada que cargar (¿falta $csv?)\n";
        exit(0);
    }

    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:cargar_temporadas_sin_salida' WHERE ID = 1");

    if ($dryRun) {
        foreach ($filas as $f) {
            echo "  {$f['LOCALIDAD']} {$f['ANIO']}: {$f['MOTIVO']}\n";
        }
        echo count($filas) . " filas en el CSV (dry-run, no se ha escrito nada)\n";
        exit(0);
    }

    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO temporada_sin_salida (LOCALIDAD, ANIO, MOTIVO, FUENTE)
         VALUES (?, ?, ?, ?)'
    );
    $creadas = 0;
    foreach ($filas as $f) {
        $ins->execute([
            $f['LOCALIDAD'],
            (int) $f['ANIO'],
            $f['MOTIVO'],
            ($f['FUENTE'] ?? '') !== '' ? $f['FUENTE'] : null,
        ]);
        $creadas += $ins->rowCount();
    }
    echo "$creadas filas nuevas de " . count($filas) . " (el resto ya estaban)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Carga de temporadas sin salida abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
