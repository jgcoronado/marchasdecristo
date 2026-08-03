<?php

declare(strict_types=1);

/*
 * Carga/actualiza el mapeo banda ↔ canal de YouTube desde un CSV en `ingest_canal`.
 *
 *   php app/tools/load_canales.php ruta/al/canales.csv
 *   DB_PATH=data/mdc.db php php/app/tools/load_canales.php tools/ingest/config/canales.csv
 *
 * CSV con cabecera: ID_BANDA,CANAL_URL  (se valida que ID_BANDA exista en `banda`).
 * Siempre canal oficial y desde 2019 (DESDE_ANIO usa el default de la tabla).
 * Idempotente por (ID_BANDA, CANAL_URL): re-ejecutar no duplica.
 */

define('APP_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(APP_DIR));
define('DATA_DIR', BASE_DIR . '/data');

$csvPath = $argv[1] ?? '';
if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Uso: php app/tools/load_canales.php <ruta.csv>\n");
    exit(1);
}

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];
if (!is_file($db)) {
    fwrite(STDERR, "Abortado: no existe la BD en $db\n");
    exit(1);
}

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "No se pudo abrir $csvPath\n");
    exit(1);
}

$header = fgetcsv($fh);
$expected = ['ID_BANDA', 'CANAL_URL'];
if ($header === false || array_map('strtoupper', array_map('trim', $header)) !== $expected) {
    fwrite(STDERR, 'Cabecera inválida. Se espera: ' . implode(',', $expected) . "\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $bandaExists = $pdo->prepare('SELECT 1 FROM banda WHERE ID_BANDA = ?');
    $upsert = $pdo->prepare(
        'INSERT INTO ingest_canal (ID_BANDA, CANAL_URL) VALUES (:banda, :url)
         ON CONFLICT (ID_BANDA, CANAL_URL) DO NOTHING'
    );

    $ok = 0; $skip = 0; $line = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if ($row === [null]) continue;                                // línea vacía (fgetcsv devuelve [null], nunca [])
        $idBanda = (int) trim((string) ($row[0] ?? ''));
        $url = trim((string) ($row[1] ?? ''));

        if ($idBanda <= 0 || $url === '') {
            fwrite(STDERR, "Línea $line: ID_BANDA o CANAL_URL vacíos, se omite.\n");
            $skip++;
            continue;
        }
        $bandaExists->execute([$idBanda]);
        if ($bandaExists->fetchColumn() === false) {
            fwrite(STDERR, "Línea $line: la banda $idBanda no existe, se omite.\n");
            $skip++;
            continue;
        }
        $upsert->execute([':banda' => $idBanda, ':url' => $url]);
        $ok++;
    }
    fclose($fh);
} catch (Throwable $e) {
    fwrite(STDERR, 'Carga falló: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM ingest_canal')->fetchColumn();
echo "OK. $ok canales cargados/actualizados" . ($skip ? ", $skip omitidos" : '') . ". Total en ingest_canal: $total\n";
