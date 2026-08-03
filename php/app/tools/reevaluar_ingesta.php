<?php

declare(strict_types=1);

/*
 * Backfill: reevalúa TODOS los candidatos aún pendientes/descartados contra
 * TODAS las marchas de su banda, por si hay coincidencias de título que se
 * escaparon del chequeo automático (candidatos duplicados aceptados antes de
 * que ese chequeo existiera, o marchas dadas de alta fuera del panel de
 * ingesta). Es el equivalente, para datos ya existentes, de
 * IngestaRepo::reevaluarTrasCrearMarcha() (que solo corre hacia adelante, al
 * crear una marcha nueva desde el panel).
 *
 * Por defecto es un dry-run (solo lista lo que cambiaría). Para aplicar los
 * cambios:
 *
 *   php app/tools/reevaluar_ingesta.php --aplicar
 *   DB_PATH=data/mdc.db php php/app/tools/reevaluar_ingesta.php --aplicar
 *
 * Idempotente: si un candidato ya tiene anotado el mismo MATCH_MARCHA_ID con
 * el mismo score, no se toca (se puede re-ejecutar sin re-abrir descartados
 * ni duplicar la nota en MOTIVO).
 */

define('APP_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(APP_DIR));
define('DATA_DIR', BASE_DIR . '/data');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$GLOBALS['config'] = $config;
if (!is_file((string) $config['db_path'])) {
    fwrite(STDERR, "Abortado: no existe la BD en {$config['db_path']}\n");
    exit(1);
}

$aplicar = in_array('--aplicar', $argv, true);
const UMBRAL_MEDIA = 0.75;

$candidatos = App\Db::all(
    "SELECT ID_CAND, ID_BANDA, P_BANDA_ESTRENO, P_TITULO, VIDEO_TITULO, ESTADO, MOTIVO, MATCH_MARCHA_ID, MATCH_SCORE
     FROM ingest_candidato WHERE ESTADO IN ('pendiente', 'descartado')"
);

$revisados = 0;
$cambios = 0;
foreach ($candidatos as $c) {
    $bandas = array_values(array_unique(array_filter([
        $c['ID_BANDA'] !== null ? (int) $c['ID_BANDA'] : null,
        $c['P_BANDA_ESTRENO'] !== null ? (int) $c['P_BANDA_ESTRENO'] : null,
    ])));
    if ($bandas === []) continue;
    $revisados++;

    $ph = implode(',', array_fill(0, count($bandas), '?'));
    $marchas = App\Db::all("SELECT ID_MARCHA, TITULO FROM marcha WHERE BANDA_ESTRENO IN ($ph)", $bandas);
    if ($marchas === []) continue;

    $tituloCand = (string) ($c['P_TITULO'] ?: $c['VIDEO_TITULO']);
    $mejor = null;
    foreach ($marchas as $m) {
        $score = App\Similarity::ratio($tituloCand, (string) $m['TITULO']);
        if ($mejor === null || $score > $mejor['score']) {
            $mejor = ['id' => (int) $m['ID_MARCHA'], 'titulo' => (string) $m['TITULO'], 'score' => $score];
        }
    }
    // $marchas no está vacío (comprobado arriba), así que el bucle siempre fija $mejor.
    if ($mejor['score'] < UMBRAL_MEDIA) continue;

    $yaAnotado = ((int) ($c['MATCH_MARCHA_ID'] ?? 0)) === $mejor['id']
        && abs(((float) ($c['MATCH_SCORE'] ?? -1)) - $mejor['score']) < 0.005;
    if ($yaAnotado) continue;

    $cambios++;
    $pct = (int) round($mejor['score'] * 100);
    printf(
        "#%d [%s] \"%s\" ~ marcha #%d \"%s\" (%d%%)%s\n",
        $c['ID_CAND'],
        $c['ESTADO'],
        $tituloCand,
        $mejor['id'],
        $mejor['titulo'],
        $pct,
        $c['ESTADO'] === 'descartado' ? ' -> vuelve a pendiente' : ''
    );

    if (!$aplicar) continue;

    if ($c['ESTADO'] === 'descartado') {
        $nota = "Reabierto (backfill): posible coincidencia con la marcha #{$mejor['id']} (similitud {$pct}%).";
        $motivo = $c['MOTIVO'] ? $c['MOTIVO'] . ' | ' . $nota : $nota;
        App\Db::run(
            "UPDATE ingest_candidato
             SET ESTADO = 'pendiente', MATCH_MARCHA_ID = ?, MATCH_SCORE = ?, MOTIVO = ?, REVIEWED_AT = NULL
             WHERE ID_CAND = ?",
            [$mejor['id'], $mejor['score'], $motivo, $c['ID_CAND']]
        );
        App\Db::logAdmin('REOPEN', 'ingest_candidato', (int) $c['ID_CAND'], ['marchaId' => $mejor['id'], 'score' => $mejor['score'], 'origen' => 'backfill']);
    } else {
        App\Db::run(
            'UPDATE ingest_candidato SET MATCH_MARCHA_ID = ?, MATCH_SCORE = ? WHERE ID_CAND = ?',
            [$mejor['id'], $mejor['score'], $c['ID_CAND']]
        );
    }
}

echo "\n$revisados candidatos revisados, $cambios coincidencias " . ($aplicar ? 'aplicadas' : 'encontradas (dry-run, usa --aplicar para escribir)') . ".\n";
