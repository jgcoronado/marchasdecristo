<?php

declare(strict_types=1);

/**
 * fill_enlaces_cascada.php — pasa por TODO el catálogo la misma cascada que el
 * panel dispara al guardar el enlace de un disco (App\EnlacesAuto).
 *
 * Para cada disco que ya tenga al menos un enlace de streaming, completa lo que
 * falte en tres niveles:
 *
 *   1. DISCO   → el resto de servicios de esa misma publicación (Odesli + UPC).
 *   2. MARCHAS → el enlace de cada marcha del disco, vía el tracklist del álbum,
 *                más su ISRC y la duración de la grabación si faltaba (R-01/R-02).
 *   3. BANDA   → el perfil de artista de la banda propietaria del disco.
 *
 * No reimplementa nada: llama a EnlacesAuto::paraDisco(), que es exactamente lo
 * que corre en el panel. Si cambia el criterio allí, cambia aquí.
 *
 * Nunca pisa un enlace existente y lo dudoso va a `enlace_candidato` para
 * curarlo en /dashboard/enlaces. Relanzarlo es idempotente.
 *
 * ── Diferencia con fill_enlaces_odesli.php ───────────────────────────────────
 * Aquel parte SIEMPRE del enlace de Spotify y baja a Amazon/Tidal/YouTube pista
 * a pista (1 llamada a Odesli por pista: caro, pero cubre servicios sin
 * tracklist público). Este parte de CUALQUIER servicio que el disco tenga y se
 * queda en lo barato: 1 llamada a Odesli por disco. Son complementarios — lo
 * normal es pasar este primero, que cubre casi todo, y dejar aquel para el
 * repaso fino.
 *
 * ── Uso (desde la raíz del repo) ─────────────────────────────────────────────
 *   php php/app/tools/fill_enlaces_cascada.php                  # dry-run de todo
 *   php php/app/tools/fill_enlaces_cascada.php --commit         # escribe
 *   php php/app/tools/fill_enlaces_cascada.php --disco=232 --commit
 *   php php/app/tools/fill_enlaces_cascada.php --desde=300 --limite=50 --commit
 *
 * ── Opciones ─────────────────────────────────────────────────────────────────
 *   --commit          escribe en BD (por defecto: dry-run; se revierte todo)
 *   --disco=ID        un solo disco  ·  --discos=1,2,3  varios
 *   --desde=ID        empieza en ese ID de disco (para reanudar tras un corte)
 *   --limite=N        procesa como mucho N discos
 *   --pausa=6.5       segundos entre discos (Odesli admite ~10 llamadas/minuto)
 *   --sin-pausa       equivale a --pausa=0 (solo si todo va de caché)
 *   --csv=RUTA        informe por disco (por defecto php/data/cascada-<fecha>.csv)
 *
 * El dry-run ejecuta el MISMO código y revierte la transacción al final de cada
 * disco: lo que informa es lo que escribiría, no una simulación aparte.
 *
 * Requiere `env => local` en config.local.php, como toda escritura del panel.
 */

define('APP_DIR', dirname(__DIR__));        // .../app
define('BASE_DIR', dirname(APP_DIR));       // .../php
define('DATA_DIR', BASE_DIR . '/data');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$GLOBALS['config'] = require APP_DIR . '/config.php';

use App\Db;
use App\EnlacesAuto;

// ── Argumentos ───────────────────────────────────────────────────────────────
$argvv = $argv ?? [];
$commit = in_array('--commit', $argvv, true);
$soloDiscos = [];
$desde = 0;
$limite = 0;
$pausa = 6.5;
$runId = 'cascada-' . date('Ymd-His');
$csvPath = DATA_DIR . '/' . $runId . '.csv';

foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--disco='))  $soloDiscos = [(int) substr($arg, 8)];
    if (str_starts_with($arg, '--discos=')) $soloDiscos = array_map('intval', array_filter(explode(',', substr($arg, 9))));
    if (str_starts_with($arg, '--desde='))  $desde = (int) substr($arg, 8);
    if (str_starts_with($arg, '--limite=')) $limite = (int) substr($arg, 9);
    if (str_starts_with($arg, '--pausa='))  $pausa = (float) substr($arg, 8);
    if (str_starts_with($arg, '--csv='))    $csvPath = substr($arg, 6);
    if ($arg === '--sin-pausa')             $pausa = 0.0;
}

if (($GLOBALS['config']['env'] ?? '') !== 'local') {
    fwrite(STDERR, "Abortado: este script escribe en la BD y config['env'] no es 'local'.\n"
        . "Es el mismo fail-safe del panel (Db::assertWritable): la BD maestra es la local.\n");
    exit(1);
}

// Nadie espera delante de la pantalla: el presupuesto del panel (25 s) solo
// serviría aquí para dejar discos a medias.
EnlacesAuto::$presupuestoSeg = 600;
Db::setAuditUser('cascada-cli');

// ── Selección de discos ──────────────────────────────────────────────────────
// Solo los que ya tienen de qué partir: sin ningún enlace no hay semilla, y
// buscarla es justo lo que esta cascada NO hace (identidad, no búsqueda).
$where = ['EXISTS (SELECT 1 FROM enlace_streaming e WHERE e.TIPO_ENT = \'disco\' AND e.ID_ENT = d.ID_DISCO)'];
$params = [];
if ($soloDiscos !== []) {
    $where[] = 'd.ID_DISCO IN (' . implode(',', array_map('intval', $soloDiscos)) . ')';
}
if ($desde > 0) {
    $where[] = 'd.ID_DISCO >= ?';
    $params[] = $desde;
}
$sql = 'SELECT d.ID_DISCO, d.NOMBRE_CD, b.NOMBRE_BREVE AS BANDA
          FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY d.ID_DISCO';
if ($limite > 0) {
    $sql .= ' LIMIT ' . $limite;
}
$discos = Db::all($sql, $params);

$totalConEnlace = (int) (Db::one(
    "SELECT COUNT(DISTINCT ID_ENT) AS n FROM enlace_streaming WHERE TIPO_ENT = 'disco'"
)['n'] ?? 0);
$totalDiscos = (int) (Db::one('SELECT COUNT(*) AS n FROM disco')['n'] ?? 0);

echo "══ fill_enlaces_cascada · run $runId ══\n";
echo $commit ? "MODO: COMMIT (escribe en BD)\n" : "MODO: DRY-RUN (revierte cada disco; nada se guarda)\n";
echo "Discos con enlace: $totalConEnlace de $totalDiscos · en este lote: " . count($discos) . "\n";
echo 'Pausa entre discos: ' . $pausa . " s\n\n";

if ($discos === []) {
    echo "Nada que hacer.\n";
    exit(0);
}

// ── Pasada ───────────────────────────────────────────────────────────────────
$tot = [
    'discos' => 0, 'disco_nuevos' => 0, 'disco_cand' => 0, 'disco_sin' => 0,
    'marcha_enlaces' => 0, 'marcha_cand' => 0, 'marcha_sin_match' => 0, 'duraciones' => 0,
    'banda_nuevos' => 0, 'banda_cand' => 0, 'errores' => 0,
];
$porServicio = [];
$sinPorServicio = [];
$csv = [];

foreach ($discos as $i => $d) {
    $idDisco = (int) $d['ID_DISCO'];
    $tot['discos']++;
    printf("── [%d/%d] #%d %s · %s\n", $i + 1, count($discos), $idDisco,
        (string) $d['NOMBRE_CD'], (string) ($d['BANDA'] ?? '—'));

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $r = EnlacesAuto::paraDisco($idDisco);
        // El dry-run corre el mismo código y deshace: así lo que se informa es
        // exactamente lo que se escribiría, no una estimación paralela.
        $commit ? $pdo->commit() : $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $tot['errores']++;
        echo '     ERROR: ' . $e->getMessage() . "\n";
        $csv[] = [$idDisco, (string) $d['NOMBRE_CD'], 'ERROR', $e->getMessage(), '', '', '', '', ''];
        continue;
    }

    $tot['disco_nuevos'] += count($r['disco']['nuevos']);
    $tot['disco_cand'] += count($r['disco']['candidatos']);
    $tot['disco_sin'] += count($r['disco']['sin']);
    $tot['marcha_enlaces'] += $r['marchas']['enlaces'];
    $tot['marcha_cand'] += $r['marchas']['candidatos'];
    $tot['marcha_sin_match'] += $r['marchas']['sin_match'];
    $tot['duraciones'] += $r['marchas']['duraciones'];
    $tot['banda_nuevos'] += count($r['banda']['nuevos']);
    $tot['banda_cand'] += count($r['banda']['candidatos']);
    foreach ([...$r['disco']['nuevos'], ...$r['banda']['nuevos']] as $servicio) {
        $porServicio[$servicio] = ($porServicio[$servicio] ?? 0) + 1;
    }
    foreach ($r['disco']['sin'] as $servicio) {
        $sinPorServicio[$servicio] = ($sinPorServicio[$servicio] ?? 0) + 1;
    }

    $resumen = EnlacesAuto::resumen($r);
    echo '     ' . ($resumen ?? 'nada que añadir') . "\n";
    foreach ($r['avisos'] as $aviso) echo '     ! ' . $aviso . "\n";

    $csv[] = [
        $idDisco, (string) $d['NOMBRE_CD'], 'OK',
        implode(' ', $r['disco']['nuevos']),
        implode(' ', $r['disco']['candidatos']),
        (string) $r['marchas']['enlaces'],
        (string) $r['marchas']['duraciones'],
        implode(' ', $r['banda']['nuevos']),
        implode(' | ', $r['avisos']),
    ];

    // Cortesía con Odesli: sin clave admite del orden de 10 peticiones por
    // minuto. Solo se espera si de verdad quedan discos por delante.
    if ($pausa > 0 && $i < count($discos) - 1) {
        usleep((int) ($pausa * 1_000_000));
    }
}

// ── Informe ──────────────────────────────────────────────────────────────────
$fh = @fopen($csvPath, 'w');
if ($fh !== false) {
    fputcsv($fh, ['ID_DISCO', 'NOMBRE_CD', 'ESTADO', 'DISCO_NUEVOS', 'DISCO_CANDIDATOS',
                  'MARCHA_ENLACES', 'DURACIONES', 'BANDA_NUEVOS', 'AVISOS'], ',', '"', '\\');
    foreach ($csv as $fila) fputcsv($fh, $fila, ',', '"', '\\');
    fclose($fh);
}

echo "\n══ Resumen ══\n";
printf("Discos procesados:   %d (errores: %d)\n", $tot['discos'], $tot['errores']);
printf("Disco  → publicados: %d · a curar: %d · sin encontrar: %d\n",
    $tot['disco_nuevos'], $tot['disco_cand'], $tot['disco_sin']);
if ($sinPorServicio !== []) {
    ksort($sinPorServicio);
    echo '   Sin encontrar por servicio: ';
    echo implode(' · ', array_map(static fn(string $s, int $n): string => "$s=$n",
        array_keys($sinPorServicio), array_values($sinPorServicio))) . "\n";
}
printf("Marcha → publicados: %d · a curar: %d · pistas sin emparejar: %d\n",
    $tot['marcha_enlaces'], $tot['marcha_cand'], $tot['marcha_sin_match']);
printf("Banda  → publicados: %d · a curar: %d\n", $tot['banda_nuevos'], $tot['banda_cand']);
printf("Duraciones rellenadas (R-02): %d\n", $tot['duraciones']);
if ($porServicio !== []) {
    ksort($porServicio);
    echo 'Por servicio (disco+banda): ';
    echo implode(' · ', array_map(static fn(string $s, int $n): string => "$s=$n",
        array_keys($porServicio), array_values($porServicio))) . "\n";
}
if ($fh !== false) echo "Informe: $csvPath\n";

if (!$commit) {
    echo "\nDRY-RUN: no se ha escrito nada. Repite con --commit cuando el informe cuadre.\n";
} elseif ($tot['disco_cand'] + $tot['marcha_cand'] + $tot['banda_cand'] > 0) {
    echo "\nHay enlaces dudosos esperando en /dashboard/enlaces.\n";
}
