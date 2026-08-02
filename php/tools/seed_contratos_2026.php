<?php
declare(strict_types=1);

/**
 * Alta de contratos (acompanamientos banda<->hermandad) para /temporada/{anio}.
 * Usa App\AdminRepo::addContrato (misma validacion/log que el panel).
 *
 * Idempotente "a mano": addContrato no tiene UNIQUE ni dedup interno, asi que
 * este script comprueba antes (ID_BANDA, HERMANDAD_SLUG via Slug::slugify,
 * TITULAR, ANIO) para poder relanzarse sin duplicar filas.
 *
 * COLOCACION: php/tools/seed_contratos_2026.php
 * USO (desde php/):
 *   php tools/seed_contratos_2026.php ../contratos_ss_sevilla_2026.csv            # DRY-RUN
 *   php tools/seed_contratos_2026.php ../contratos_ss_sevilla_2026.csv --commit   # inserta
 *
 * Espera las columnas del CSV: ID_BANDA,HERMANDAD,TITULAR,ANIO,FUENTE,NOTA,LOCALIDAD
 * (LOCALIDAD es la localidad del ACOMPAÑAMIENTO -- de que Semana Santa
 * procede el contrato, p.ej. "Sevilla" o "Malaga" -- NO la de la banda; ver
 * 006_contrato_localidad.sql). Si el CSV no trae esa columna, se carga sin
 * localidad (cae en "Sin localidad" en /temporada/{anio}) y conviene
 * completarla luego a mano.
 * Filas con ID_BANDA vacio se saltan (bandas aun sin resolver) y se listan al
 * final para que no se pierdan de vista.
 */

$csvPath = $argv[1] ?? 'contratos_ss_sevilla_2026.csv';
$commit  = in_array('--commit', $argv, true);

define('BASE_DIR', dirname(__DIR__));
define('APP_DIR',  getenv('MDC_APP_DIR') ?: BASE_DIR . '/app');
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
if (!is_file((string) $config['db_path'])) { fwrite(STDERR, "ERROR: no encuentro la BD (DB_PATH / php/data/mdc.db)\n"); exit(1); }
\App\Db::setAuditUser('seed-ss2026-contratos');

if (!is_file($csvPath)) { fwrite(STDERR, "ERROR: no encuentro el CSV: $csvPath\n"); exit(1); }

$fh = fopen($csvPath, 'r');
$head = fgetcsv($fh); $idx = array_flip($head);
foreach (['ID_BANDA','HERMANDAD','TITULAR','ANIO'] as $req) {
    if (!isset($idx[$req])) { fwrite(STDERR, "ERROR: falta columna $req en el CSV\n"); exit(1); }
}
fwrite(STDERR, "Modo: " . ($commit ? "COMMIT" : "DRY-RUN (añade --commit para escribir)") . "\n\n");

$creadas = $dup = $sinId = $err = 0;
$sinIdLog = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $idBandaRaw = trim($row[$idx['ID_BANDA']]);
    $hermandad  = trim($row[$idx['HERMANDAD']]);
    $titular    = isset($idx['TITULAR']) ? trim((string) $row[$idx['TITULAR']]) : '';
    $anio       = trim($row[$idx['ANIO']]);
    $fuente     = isset($idx['FUENTE']) ? trim((string) $row[$idx['FUENTE']]) : '';
    $nota       = isset($idx['NOTA'])   ? trim((string) $row[$idx['NOTA']])   : '';
    $localidad  = isset($idx['LOCALIDAD']) ? trim((string) $row[$idx['LOCALIDAD']]) : '';

    if ($idBandaRaw === '') {
        $sinId++; $sinIdLog[] = "$hermandad — $titular";
        continue;
    }
    $idBanda = (int) $idBandaRaw;

    $slug = \App\Slug::slugify($hermandad);
    $existe = \App\Db::one(
        'SELECT ID_CONTRATO FROM contrato
         WHERE ID_BANDA = ? AND HERMANDAD_SLUG = ? AND ANIO = ?
           AND IFNULL(TITULAR,\'\') = ?',
        [$idBanda, $slug, (int) $anio, $titular]
    );
    if ($existe) {
        printf("· YA EXISTE  %-28s %-20s banda=%d\n", $hermandad, $titular, $idBanda);
        $dup++;
        continue;
    }

    if (!$commit) {
        printf("+ [dry-run]  %-28s %-20s banda=%d%s\n", $hermandad, $titular, $idBanda,
               $localidad !== '' ? " ($localidad)" : '');
        continue;
    }

    $res = \App\AdminRepo::addContrato($idBanda, $hermandad, $anio, $titular ?: null, $fuente ?: null, $nota ?: null, $localidad ?: null);
    if (($res['code'] ?? '') === 'CREATED') {
        printf("+ CREADO     %-28s %-20s banda=%d\n", $hermandad, $titular, $idBanda);
        $creadas++;
    } else {
        printf("! ERROR (%s) %s / %s\n", $res['code'] ?? '???', $hermandad, $titular);
        $err++;
    }
}
fclose($fh);

fwrite(STDERR, sprintf(
    "\nResumen: creados=%d · ya_existian=%d · sin_id_banda(saltados)=%d · errores=%d\n",
    $creadas, $dup, $sinId, $err
));
if ($sinId > 0) {
    fwrite(STDERR, "\nSin ID_BANDA (no se cargaron, revisar resolucion primero):\n");
    foreach ($sinIdLog as $l) fwrite(STDERR, "  - $l\n");
}
if (!$commit) fwrite(STDERR, "\n(Dry-run: no se ha escrito nada.)\n");
