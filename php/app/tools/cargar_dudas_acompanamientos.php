<?php

declare(strict_types=1);

/*
 * Carga en `acompanamiento_duda` (013_acompanamiento_duda.sql) las filas
 * dudosas que dejaron los scripts de parseo de scripts/tmp_acompanamientos/:
 *
 *   - musicofrades_extraido.csv (Sevilla/Málaga/Jerez, parse_musicofrades.py):
 *     filas con TIPO_NORMALIZADO=dudoso — una etiqueta de paso que no encaja
 *     con los patrones conocidos (Cristo/Virgen/Cruz de Guía), como "Trono de
 *     Cristo y Virgen" o "Paso de San Juan".
 *   - cordoba_extraido.csv (parse_cordoba_programa.py): fichas sin música
 *     detectada (La O, Lágrimas — comparten página con un maquetado que la
 *     ficha del resto no tiene).
 *
 * No escribe en `hermandad`/`paso`/`contrato_paso`: solo dejar la duda
 * disponible en /dashboard/acompanamientos-dudas para que un admin decida.
 * Idempotente por el UNIQUE de la tabla — puede re-ejecutarse tras cada
 * pasada de los scripts de parseo sin duplicar filas ya cargadas.
 *
 * Uso:
 *   php php/app/tools/cargar_dudas_acompanamientos.php
 *   DB_PATH=/ruta/a/mdc.db php .../cargar_dudas_acompanamientos.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Carga de dudas abortada');

// Los CSV de origen viven fuera de php/ (no se despliegan, ver
// docs/acompanamientos-nomina-2026.md §"Cómo llega a producción"): hay que
// llevarlos al host junto con este script si se ejecuta in situ.
$srcDir = dirname(BASE_DIR) . '/scripts/tmp_acompanamientos';
$musicofradesCsv = $srcDir . '/musicofrades_extraido.csv';
$cordobaCsv = $srcDir . '/cordoba_extraido.csv';

/** @return list<array<string,string>> */
function leerCsv(string $path): array
{
    if (!is_file($path)) return [];
    $fh = fopen($path, 'r');
    if ($fh === false) return [];
    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); return []; }
    $rows = [];
    while (($fila = fgetcsv($fh)) !== false) {
        if (count($fila) !== count($header)) continue;
        $rows[] = array_combine($header, $fila);
    }
    fclose($fh);
    return $rows;
}

/** @return list<array{LOCALIDAD:string,DIA:?string,HERMANDAD:string,ETIQUETA_RAW:?string,BANDA_TEXTO:?string,BANDA_URL:?string,FUENTE:string,MOTIVO:string}> */
function candidatos(string $musicofradesCsv, string $cordobaCsv): array
{
    $out = [];
    foreach (leerCsv($musicofradesCsv) as $r) {
        if (($r['TIPO_NORMALIZADO'] ?? '') !== 'dudoso') continue;
        $out[] = [
            'LOCALIDAD' => $r['LOCALIDAD'] ?? '',
            'DIA' => $r['DIA'] ?? null,
            'HERMANDAD' => $r['HERMANDAD'] ?? '',
            'ETIQUETA_RAW' => $r['ETIQUETA_RAW'] ?? null,
            'BANDA_TEXTO' => $r['BANDA_TEXTO'] ?: null,
            'BANDA_URL' => $r['BANDA_URL'] ?: null,
            'FUENTE' => 'musicofrades',
            'MOTIVO' => 'tipo_paso_ambiguo',
        ];
    }
    foreach (leerCsv($cordobaCsv) as $r) {
        if (($r['ACOMPANAMIENTO_RAW'] ?? '') !== '') continue;
        $out[] = [
            'LOCALIDAD' => 'Cordoba',
            'DIA' => $r['DIA'] ?? null,
            'HERMANDAD' => $r['NOMBRE'] ?? '',
            'ETIQUETA_RAW' => null,
            'BANDA_TEXTO' => null,
            'BANDA_URL' => null,
            'FUENTE' => 'cordoba_programa',
            'MOTIVO' => 'sin_musica_detectada',
        ];
    }
    return $out;
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:cargar_dudas_acompanamientos' WHERE ID = 1");

    $filas = candidatos($musicofradesCsv, $cordobaCsv);
    if ($filas === []) {
        echo "nada que cargar (¿faltan los CSV en $srcDir?)\n";
        exit(0);
    }

    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO acompanamiento_duda
            (LOCALIDAD, DIA, HERMANDAD, ETIQUETA_RAW, BANDA_TEXTO, BANDA_URL, FUENTE, MOTIVO)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $creadas = 0;
    foreach ($filas as $f) {
        $ins->execute([
            $f['LOCALIDAD'], $f['DIA'], $f['HERMANDAD'], $f['ETIQUETA_RAW'],
            $f['BANDA_TEXTO'], $f['BANDA_URL'], $f['FUENTE'], $f['MOTIVO'],
        ]);
        $creadas += $ins->rowCount();
    }
    echo "$creadas dudas nuevas de " . count($filas) . " candidatas (el resto ya estaban cargadas)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Carga de dudas abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
