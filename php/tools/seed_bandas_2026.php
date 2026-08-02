<?php
declare(strict_types=1);

/**
 * Alta de las bandas de la SS de Sevilla 2026 que aún no están en la BD.
 * Usa el mismo camino que el panel (App\AdminRepo::addBanda): valida, respeta
 * el encoding de tu app y escribe en admin_log.
 *
 * COLOCACIÓN: déjalo en  php/tools/seed_bandas_2026.php
 *   (si lo pones en otro sitio, exporta MDC_APP_DIR y/o DB_PATH)
 *
 * USO (desde la carpeta php/):
 *   php tools/seed_bandas_2026.php ../bandas_a_crear.csv            # DRY-RUN (no escribe)
 *   php tools/seed_bandas_2026.php ../bandas_a_crear.csv --commit   # inserta de verdad
 *
 * Es idempotente: si una banda ya existe (mismo NOMBRE_COMPLETO) no la duplica.
 * Al terminar escribe bandas_creadas.csv (clave_normalizada,id,...) para cerrar
 * el enlace con los acompañamientos.
 */

$csvPath = $argv[1] ?? 'bandas_a_crear.csv';
$commit  = in_array('--commit', $argv, true);

// --- Constantes de la app (espejo de php/public/index.php) ---
define('BASE_DIR', dirname(__DIR__));                 // -> php/
define('APP_DIR',  getenv('MDC_APP_DIR') ?: BASE_DIR . '/app');
define('DATA_DIR', BASE_DIR . '/data');

// --- Autoload PSR-4 mínimo (igual que bootstrap.php) ---
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

// --- Config + habilitar escritura local (es tu máquina) ---
$config = require APP_DIR . '/config.php';
$config['env'] = 'local';                             // Db::assertWritable() exige 'local'
$GLOBALS['config'] = $config;

fwrite(STDERR, "BD: {$config['db_path']}\n");
if (!is_file((string) $config['db_path'])) {
    fwrite(STDERR, "ERROR: no encuentro la BD. Exporta DB_PATH=/ruta/mdc.db o revisa php/data/mdc.db\n");
    exit(1);
}
\App\Db::setAuditUser('seed-ss2026-bandas');

if (!is_file($csvPath)) { fwrite(STDERR, "ERROR: no encuentro el CSV: $csvPath\n"); exit(1); }

// --- Leer CSV ---
$fh = fopen($csvPath, 'r');
$head = fgetcsv($fh);
$idx = array_flip($head);
foreach (['NOMBRE_COMPLETO','NOMBRE_BREVE','LOCALIDAD','PROVINCIA'] as $req) {
    if (!isset($idx[$req])) { fwrite(STDERR, "ERROR: falta la columna $req en el CSV\n"); exit(1); }
}

$modo = $commit ? "COMMIT (se escribirá)" : "DRY-RUN (no se escribe; añade --commit para insertar)";
fwrite(STDERR, "Modo: $modo\n\n");

$out = fopen('bandas_creadas.csv', 'w');
fputcsv($out, ['clave_normalizada','id','nombre_breve','estado']);

$creadas = $existentes = $errores = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim($row[0]) === '') continue;
    $completo = trim($row[$idx['NOMBRE_COMPLETO']]);
    $breve    = trim($row[$idx['NOMBRE_BREVE']]);
    $loc      = trim($row[$idx['LOCALIDAD']]);
    $prov     = trim($row[$idx['PROVINCIA']]);
    $clave    = isset($idx['clave_normalizada']) ? trim($row[$idx['clave_normalizada']]) : '';

    $existe = \App\Db::one('SELECT ID_BANDA FROM banda WHERE NOMBRE_COMPLETO = ?', [$completo]);
    if ($existe) {
        $id = (int) $existe['ID_BANDA'];
        printf("· YA EXISTE  id=%-4d  %s\n", $id, $breve);
        fputcsv($out, [$clave, $id, $breve, 'ya_existia']);
        $existentes++;
        continue;
    }

    if (!$commit) {
        printf("+ [dry-run]  insertaría: %s  (%s, %s)\n", $breve, $loc, $prov);
        fputcsv($out, [$clave, '', $breve, 'pendiente_dry_run']);
        continue;
    }

    $res = \App\AdminRepo::addBanda([
        'NOMBRE_COMPLETO' => $completo,
        'NOMBRE_BREVE'    => $breve,
        'LOCALIDAD'       => $loc,
        'PROVINCIA'       => $prov,
    ]);
    if (($res['code'] ?? '') === 'CREATED') {
        $id = (int) $res['bandaId'];
        printf("+ CREADA     id=%-4d  %s\n", $id, $breve);
        fputcsv($out, [$clave, $id, $breve, 'creada']);
        $creadas++;
    } else {
        printf("! ERROR (%s) al crear: %s\n", $res['code'] ?? '???', $breve);
        fputcsv($out, [$clave, '', $breve, 'error:' . ($res['code'] ?? '???')]);
        $errores++;
    }
}
fclose($fh); fclose($out);

fwrite(STDERR, sprintf(
    "\nResumen: creadas=%d · ya_existían=%d · errores=%d\n-> bandas_creadas.csv\n",
    $creadas, $existentes, $errores
));
if (!$commit) fwrite(STDERR, "(Dry-run: no se ha escrito nada. Repite con --commit cuando lo veas bien.)\n");
