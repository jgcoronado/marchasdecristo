<?php
declare(strict_types=1);

/**
 * Resuelve la columna ID_BANDA de un CSV de acompanamientos contra la tabla
 * `banda`, para poder pasarselo despues a seed_contratos_2026.php.
 *
 * Los CSV de Huelva/Cadiz/Granada (2026) se generaron a partir de fuentes web
 * que solo dan el NOMBRE de la banda, asi que llegan con ID_BANDA vacio y una
 * columna BANDA con el nombre tal cual se publico. Este script hace el enlace
 * nombre -> ID_BANDA por slug (Slug::slugify, insensible a acentos, mayusculas
 * y puntuacion) contra NOMBRE_COMPLETO y NOMBRE_BREVE, y deja el resto para
 * revision manual: NO inventa altas de bandas (para eso esta
 * php/tools/seed_bandas_2026.php con un CSV de bandas a crear).
 *
 * USO (desde la RAIZ del proyecto, justo encima de php/):
 *   php php/app/tools/resolver_contratos_banda.php contratos_ss_huelva_2026.csv
 *   php php/app/tools/resolver_contratos_banda.php contratos_ss_huelva_2026.csv --write
 *
 * Sin --write solo informa. Con --write escribe <fichero>.resuelto.csv (mismas
 * columnas, ID_BANDA relleno donde hubo match unico) sin tocar el original.
 * Las filas sin match salen listadas al final: esas son las bandas que faltan
 * por dar de alta antes de volver a lanzar el resolutor.
 *
 * Con --faltantes ademas escribe bandas_a_crear_<nombre>.csv: las bandas sin
 * match, ya en el formato que espera php/tools/seed_bandas_2026.php, sacando
 * NOMBRE_BREVE/LOCALIDAD/PROVINCIA del inventario bandas_ss2026_hue_cad_gra.csv
 * (o del que se le pase en --inventario=<fichero>). Asi no hay que recortar el
 * inventario a mano entre una pasada y la siguiente. REVISA ese CSV antes de
 * darlo de alta: NOMBRE_BREVE es una propuesta derivada del nombre largo.
 *
 * Solo lee de la BD (SELECT); no escribe nada en ella ni deja rastro en admin_log.
 */

$csvPath   = $argv[1] ?? '';
$write     = in_array('--write', $argv, true);
$faltantes = in_array('--faltantes', $argv, true);

$inventarioPath = 'bandas_ss2026_hue_cad_gra.csv';
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--inventario=')) {
        $inventarioPath = substr((string) $arg, strlen('--inventario='));
    }
}

if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "USO: php php/app/tools/resolver_contratos_banda.php <contratos.csv> [--write] [--faltantes] [--inventario=<bandas.csv>]\n");
    exit(1);
}
if ($faltantes && !is_file($inventarioPath)) {
    fwrite(STDERR, "ERROR: --faltantes necesita el inventario de bandas; no encuentro $inventarioPath\n");
    exit(1);
}

define('APP_DIR',  getenv('MDC_APP_DIR') ?: dirname(__DIR__));   // .../php/app
define('BASE_DIR', dirname(APP_DIR));                            // .../php
define('DATA_DIR', BASE_DIR . '/data');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$config = require APP_DIR . '/config.php';
$config['env'] = 'local';
$GLOBALS['config'] = $config;

fwrite(STDERR, "BD: {$config['db_path']}\n");
if (!is_file((string) $config['db_path'])) {
    fwrite(STDERR, "ERROR: no encuentro la BD (DB_PATH / php/data/mdc.db)\n");
    exit(1);
}

// --- indice slug -> [ids] a partir de NOMBRE_COMPLETO y NOMBRE_BREVE ---------
$indice = [];
foreach (\App\Db::all('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD FROM banda') as $b) {
    foreach ([$b['NOMBRE_COMPLETO'], $b['NOMBRE_BREVE']] as $nombre) {
        $s = \App\Slug::slugify((string) $nombre);
        if ($s === '') continue;
        $indice[$s][(int) $b['ID_BANDA']] = $b;
    }
}
fwrite(STDERR, 'bandas en BD: ' . count(\App\Db::all('SELECT ID_BANDA FROM banda')) . "\n\n");

$fh   = fopen($csvPath, 'r');
$head = fgetcsv($fh);
$idx  = array_flip($head);
foreach (['ID_BANDA', 'BANDA'] as $req) {
    if (!isset($idx[$req])) { fwrite(STDERR, "ERROR: falta la columna $req en el CSV\n"); exit(1); }
}

$out = null;
if ($write) {
    $out = fopen($csvPath . '.resuelto.csv', 'w');
    fputcsv($out, $head);
}

$ok = $ya = $ambiguas = 0;
$sinMatch = [];
$ambiguasLog = [];
$claveDe = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim((string) $row[0]) === '') continue;

    $banda = trim((string) $row[$idx['BANDA']]);
    if (trim((string) $row[$idx['ID_BANDA']]) !== '') {
        $ya++;
        if ($out) fputcsv($out, $row);
        continue;
    }

    $cands = $indice[\App\Slug::slugify($banda)] ?? [];
    if (count($cands) === 1) {
        $row[$idx['ID_BANDA']] = (string) array_key_first($cands);
        $ok++;
    } elseif (count($cands) > 1) {
        $ambiguas++;
        $ambiguasLog[$banda] = implode(', ', array_map(
            static fn(array $b): string => $b['ID_BANDA'] . ' ' . $b['NOMBRE_BREVE'] . ' (' . $b['LOCALIDAD'] . ')',
            $cands
        ));
    } else {
        $sinMatch[$banda] = ($sinMatch[$banda] ?? 0) + 1;
    }
    if (isset($idx['CLAVE_BANDA'])) {
        $claveDe[$banda] = trim((string) $row[$idx['CLAVE_BANDA']]);
    }
    if ($out) fputcsv($out, $row);
}
fclose($fh);
if ($out) fclose($out);

fwrite(STDERR, sprintf(
    "Resumen: resueltas=%d · ya_tenian_id=%d · ambiguas=%d · sin_match=%d filas (%d bandas)\n",
    $ok, $ya, $ambiguas, array_sum($sinMatch), count($sinMatch)
));

if ($ambiguasLog) {
    fwrite(STDERR, "\nAmbiguas (mas de una banda con ese nombre, resolver a mano):\n");
    foreach ($ambiguasLog as $nombre => $lista) fwrite(STDERR, "  - $nombre -> $lista\n");
}
if ($sinMatch) {
    fwrite(STDERR, "\nSin match (dar de alta con seed_bandas_2026.php y repetir):\n");
    arsort($sinMatch);
    foreach ($sinMatch as $nombre => $n) fwrite(STDERR, sprintf("  - %-90s (%d filas)\n", $nombre, $n));
}
if ($faltantes && $sinMatch) {
    // Inventario indexado por clave_normalizada Y por slug del nombre completo,
    // para que cruce igual aunque el CSV de contratos no traiga CLAVE_BANDA.
    $inv = [];
    $ih  = fopen($inventarioPath, 'r');
    $ihead = fgetcsv($ih);
    $iidx  = array_flip($ihead);
    while (($ir = fgetcsv($ih)) !== false) {
        if (count($ir) === 1 && trim((string) $ir[0]) === '') continue;
        $completo = trim((string) $ir[$iidx['NOMBRE_COMPLETO']]);
        $inv[\App\Slug::slugify($completo)] = $ir;
        if (isset($iidx['clave_normalizada'])) {
            $inv[trim((string) $ir[$iidx['clave_normalizada']])] = $ir;
        }
    }
    fclose($ih);

    $destino = 'bandas_a_crear_' . preg_replace('/\.csv$/', '', basename($csvPath)) . '.csv';
    $fo = fopen($destino, 'w');
    fputcsv($fo, ['NOMBRE_COMPLETO', 'NOMBRE_BREVE', 'LOCALIDAD', 'PROVINCIA', 'clave_normalizada']);
    $escritas = 0;
    $sinFicha = [];
    foreach (array_keys($sinMatch) as $nombre) {
        $ir = $inv[\App\Slug::slugify($nombre)] ?? $inv[$claveDe[$nombre] ?? ''] ?? null;
        if ($ir === null) { $sinFicha[] = $nombre; continue; }
        fputcsv($fo, [
            $ir[$iidx['NOMBRE_COMPLETO']],
            $ir[$iidx['NOMBRE_BREVE']],
            $ir[$iidx['LOCALIDAD']],
            $ir[$iidx['PROVINCIA']],
            $iidx['clave_normalizada'] !== null ? ($ir[$iidx['clave_normalizada']] ?? '') : '',
        ]);
        $escritas++;
    }
    fclose($fo);
    fwrite(STDERR, "\n-> $destino ($escritas bandas a dar de alta; revisa NOMBRE_BREVE antes)\n");
    foreach ($sinFicha as $n) {
        fwrite(STDERR, "  ! sin ficha en $inventarioPath, anadela a mano: $n\n");
    }
}

if ($write) {
    fwrite(STDERR, "\n-> " . $csvPath . ".resuelto.csv\n");
} else {
    fwrite(STDERR, "\n(Solo informe: anade --write para generar el CSV con ID_BANDA relleno.)\n");
}
