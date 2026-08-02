<?php
/**
 * fill_duraciones.php  —  roadmap R-02
 *
 * Rellena `disco_marcha.DURACION_SEG` (duración de la GRABACIÓN) a partir del
 * tracklist de los álbumes ya enlazados en `enlace_streaming` (TIPO_ENT='disco').
 *
 * Alcance: solo discos con enlace de streaming APROBADO. No busca álbumes nuevos
 * ni crea enlaces — esto solo lee tracklists y escribe duraciones.
 *
 * Orden de servicios: spotify → apple → deezer. Se consulta el siguiente servicio
 * únicamente si quedan pistas del disco sin duración tras el anterior.
 *
 * Match: SOLO por título (>= --min-sim, por defecto 0.85). El número de pista se
 * registra en el CSV como información, pero NO influye en el match: en el catálogo
 * NUMEROMARCHA no siempre refleja el orden real de la edición digital.
 * La asignación es 1:1 y greedy por score descendente (una pista de BD no puede
 * llevarse dos tracks, ni un track ir a dos pistas).
 *
 * Segunda pasada (activa por defecto): `marcha.DURACION_SEG` = MEDIANA de las
 * duraciones de sus grabaciones, siempre que tenga alguna. Si no tiene ninguna,
 * se respeta el valor de catálogo. Todo cambio queda registrado en
 * `<csv>_marchas.csv`, con AVISO en los que convenga revisar a mano.
 *
 * Uso (PowerShell, desde la raíz del repo):
 *   php php\app\tools\fill_duraciones.php                       # dry-run completo
 *   php php\app\tools\fill_duraciones.php --disco=5             # dry-run de 1 disco
 *   php php\app\tools\fill_duraciones.php --commit              # escribe en BD
 *   php php\app\tools\fill_duraciones.php --solo-marchas --commit   # solo pasada 2
 *
 * Opciones:
 *   --commit          escribe en BD (por defecto: dry-run, no toca nada)
 *   --disco=ID        procesar un solo disco
 *   --min-sim=0.85    umbral de similitud de título
 *   --overwrite       recalcular pistas que ya tienen DURACION_SEG
 *   --servicios=...   lista/orden de servicios (por defecto spotify,apple,deezer)
 *   --excluir=IDs     discos a saltar (por defecto: los de $excluidos)
 *   --sin-exclusiones procesar también los discos excluidos por defecto
 *   --no-marchas      saltar la pasada 2 (no tocar marcha.DURACION_SEG)
 *   --solo-marchas    saltar las APIs y ejecutar solo la pasada 2
 *   --csv=RUTA        informe (por defecto php/data/duraciones_<runid>.csv)
 */

declare(strict_types=1);

// ── Argumentos ────────────────────────────────────────────────────────────────
$argvv       = $argv ?? [];
$commit      = in_array('--commit', $argvv, true);
$overwrite   = in_array('--overwrite', $argvv, true);
// Nombres nuevos (--solo-marchas / --no-marchas); se aceptan los antiguos por compatibilidad.
$soloMedian  = in_array('--solo-marchas', $argvv, true) || in_array('--solo-medianas', $argvv, true);
$hacerMedian = !in_array('--no-marchas', $argvv, true) && !in_array('--no-medianas', $argvv, true);
$soloDisco   = null;
$minSim      = 0.85;
$servicios   = ['spotify', 'apple', 'deezer'];

/**
 * Discos excluidos a propósito. El enlace está aprobado y es correcto como
 * ENLACE, pero su duración no describe la grabación de este disco.
 *   229 «Y fui tu costalero» → enlazado a la edición "(Acoustic Versions)":
 *       son regrabaciones acústicas, no el CD de 2004.
 * Ver docs/pendientes-manuales-2026-07-31.md
 */
$excluidos   = [229];
$runId       = 'dur-' . date('Ymd-His');
$csvPath     = __DIR__ . '/../../data/duraciones_' . $runId . '.csv';

foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--disco='))      $soloDisco = (int) substr($arg, 8);
    if (str_starts_with($arg, '--min-sim='))    $minSim    = (float) substr($arg, 10);
    if (str_starts_with($arg, '--csv='))        $csvPath   = substr($arg, 6);
    if (str_starts_with($arg, '--servicios=')) {
        $servicios = array_values(array_filter(array_map('trim', explode(',', substr($arg, 12)))));
    }
    if (str_starts_with($arg, '--excluir=')) {
        $excluidos = array_map('intval', array_filter(explode(',', substr($arg, 10))));
    }
    if ($arg === '--sin-exclusiones') $excluidos = [];
}

$dbPath = __DIR__ . '/../../data/mdc.db';
if (!is_file($dbPath)) {
    fwrite(STDERR, "ERROR: no encuentro la BD en $dbPath\n");
    exit(1);
}

// ── .env (mismo parseo manual que fill_enlaces_streaming.php) ─────────────────
$dotenv  = [];
$envFile = __DIR__ . '/../../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $dotenv[trim($k)] = trim($v, " \t'\"");
    }
}
$spotifyId     = $dotenv['SPOTIFY_CLIENT_ID']     ?? '';
$spotifySecret = $dotenv['SPOTIFY_CLIENT_SECRET'] ?? '';

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ─────────────────────────────────────────────────────────────────────────────
//  Normalización, similitud, HTTP, tracklists y emparejado
//
//  Viven en lib/music_match.php desde 2026-08-01: son las mismas funciones que
//  estaban aquí, movidas para que `fill_enlaces_odesli.php` use el mismo
//  matcher y no se bifurquen dos criterios de similitud sobre el catálogo.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/lib/music_match.php';

// ─────────────────────────────────────────────────────────────────────────────
//  Pasada 1 — duraciones de grabación
// ─────────────────────────────────────────────────────────────────────────────

$csvRows = [];
$stats   = ['discos' => 0, 'pistas' => 0, 'match' => 0, 'sin_match' => 0, 'sin_tracklist' => 0, 'excluidos' => 0];
$porServicio = [];

if (!$soloMedian) {
    echo "══ fill_duraciones · run $runId ══\n";
    echo $commit ? "MODO: COMMIT (escribe en BD)\n" : "MODO: DRY-RUN (no escribe nada)\n";
    echo "Umbral de similitud: $minSim · Servicios: " . implode(' → ', $servicios) . "\n\n";

    $token = null;
    if (in_array('spotify', $servicios, true)) {
        if ($spotifyId && $spotifySecret) {
            $token = spotifyToken($spotifyId, $spotifySecret);
            echo $token ? "Token de Spotify OK.\n\n" : "AVISO: no se pudo obtener token de Spotify; se salta ese servicio.\n\n";
        } else {
            echo "AVISO: faltan SPOTIFY_CLIENT_ID/SECRET en .env; se salta Spotify.\n\n";
        }
    }

    $sqlDiscos = "
        SELECT d.ID_DISCO, d.NOMBRE_CD, b.NOMBRE_BREVE AS BANDA,
               GROUP_CONCAT(e.SERVICIO || '=' || COALESCE(e.ID_EXT, ''), '||') AS ENLACES
        FROM disco d
        JOIN enlace_streaming e ON e.TIPO_ENT = 'disco' AND e.ID_ENT = d.ID_DISCO
        LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
        " . ($soloDisco !== null ? "WHERE d.ID_DISCO = :id" : "") . "
        GROUP BY d.ID_DISCO
        ORDER BY d.ID_DISCO
    ";
    $st = $db->prepare($sqlDiscos);
    if ($soloDisco !== null) $st->bindValue(':id', $soloDisco, PDO::PARAM_INT);
    $st->execute();
    $discos = $st->fetchAll(PDO::FETCH_ASSOC);

    echo "Discos con enlace: " . count($discos) . "\n\n";

    $stPistas = $db->prepare("
        SELECT dm.ID_DM, dm.NUMEROMARCHA, dm.N_DISCO, dm.DURACION_SEG, dm.IDMARCHA, m.TITULO
        FROM disco_marcha dm
        JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
        WHERE dm.ID_DISCO = ?
        ORDER BY dm.N_DISCO, dm.NUMEROMARCHA
    ");
    $stUpd = $db->prepare("UPDATE disco_marcha SET DURACION_SEG = ? WHERE ID_DM = ?");

    if ($commit) $db->beginTransaction();

    foreach ($discos as $d) {
        $idDisco = (int) $d['ID_DISCO'];
        if (in_array($idDisco, $excluidos, true)) {
            printf("── [%d] %s · EXCLUIDO (ver \$excluidos en la cabecera)\n", $idDisco, (string) $d['NOMBRE_CD']);
            $stats['excluidos']++;
            continue;
        }
        $stPistas->execute([$idDisco]);
        $todas = $stPistas->fetchAll(PDO::FETCH_ASSOC);

        // Solo las que faltan (salvo --overwrite)
        $pistas = $overwrite
            ? $todas
            : array_values(array_filter($todas, fn($p) => $p['DURACION_SEG'] === null || (int) $p['DURACION_SEG'] === 0));

        if (!$pistas) continue;
        $stats['discos']++;
        $stats['pistas'] += count($pistas);

        // Enlaces disponibles de este disco
        $ext = [];
        foreach (explode('||', (string) $d['ENLACES']) as $par) {
            if (!str_contains($par, '=')) continue;
            [$srv, $id] = explode('=', $par, 2);
            if ($id !== '') $ext[$srv] = $id;
        }

        printf("── [%d] %s · %s (%d pistas por rellenar)\n",
            $idDisco, (string) $d['NOMBRE_CD'], (string) ($d['BANDA'] ?? '—'), count($pistas));

        $pendientes  = $pistas;
        $resueltas   = [];   // ID_DM => [seg, servicio, score, titulo_srv, n_srv]
        $vistos      = [];   // todos los tracks vistos, de todos los servicios (para el informe)

        foreach ($servicios as $srv) {
            if (!$pendientes) break;
            if (!isset($ext[$srv])) continue;

            $tracks = match ($srv) {
                'spotify' => tracklistSpotify($ext[$srv], $token),
                'apple'   => tracklistApple($ext[$srv]),
                'deezer'  => tracklistDeezer($ext[$srv]),
                default   => [],
            };
            if (!$tracks) {
                printf("     %-8s sin tracklist\n", $srv);
                continue;
            }
            foreach ($tracks as $t) $vistos[] = $t;

            [$asig, ] = emparejar($pendientes, $tracks, $minSim);
            $nuevas = 0;
            foreach ($asig as $i => $a) {
                $p = $pendientes[$i];
                $resueltas[(int) $p['ID_DM']] = [
                    'seg'        => $a['track']['seg'],
                    'servicio'   => $srv,
                    'score'      => $a['score'],
                    'titulo_srv' => $a['track']['titulo'],
                    'n_srv'      => $a['track']['n'],
                    'pista'      => $p,
                ];
                $nuevas++;
            }
            printf("     %-8s %d/%d pistas (%d tracks)\n", $srv, $nuevas, count($pendientes), count($tracks));

            $pendientes = array_values(array_filter(
                $pendientes,
                fn($p) => !isset($resueltas[(int) $p['ID_DM']])
            ));
        }

        if (!$vistos) $stats['sin_tracklist']++;

        // Escribir + informe
        foreach ($resueltas as $idDm => $r) {
            $stats['match']++;
            $porServicio[$r['servicio']] = ($porServicio[$r['servicio']] ?? 0) + 1;
            if ($commit) $stUpd->execute([$r['seg'], $idDm]);

            $csvRows[] = [
                'MATCH', $idDisco, (string) $d['NOMBRE_CD'], $idDm,
                (string) $r['pista']['NUMEROMARCHA'], (string) $r['pista']['TITULO'],
                $r['servicio'], number_format($r['score'], 4, '.', ''),
                $r['titulo_srv'], (string) ($r['n_srv'] ?? ''),
                (string) $r['seg'], mmss($r['seg']),
            ];
        }

        foreach ($pendientes as $p) {
            $stats['sin_match']++;
            $best = mejorDescartado($p, $vistos);
            $csvRows[] = [
                'SIN_MATCH', $idDisco, (string) $d['NOMBRE_CD'], (int) $p['ID_DM'],
                (string) $p['NUMEROMARCHA'], (string) $p['TITULO'],
                '', number_format($best['score'], 4, '.', ''),
                $best['titulo'], '', '', '',
            ];
        }
    }

    if ($commit) $db->commit();

    echo "\n── Resumen pasada 1 ──\n";
    printf("Discos procesados      : %d\n", $stats['discos']);
    printf("Pistas candidatas      : %d\n", $stats['pistas']);
    printf("Con duración           : %d (%.1f%%)\n",
        $stats['match'], $stats['pistas'] ? 100 * $stats['match'] / $stats['pistas'] : 0);
    printf("Sin match              : %d\n", $stats['sin_match']);
    printf("Discos sin tracklist   : %d\n", $stats['sin_tracklist']);
    printf("Discos excluidos       : %d\n", $stats['excluidos']);
    foreach ($porServicio as $s => $n) printf("   via %-8s        : %d\n", $s, $n);

    // CSV
    $fh = fopen($csvPath, 'w');
    if ($fh) {
        fwrite($fh, "\xEF\xBB\xBF");   // BOM: Excel en Windows
        fputcsv($fh, ['ESTADO','ID_DISCO','DISCO','ID_DM','N_PISTA','TITULO_BD',
                      'SERVICIO','SCORE','TITULO_SERVICIO','N_SERVICIO','SEG','MM:SS'], ';');
        foreach ($csvRows as $row) fputcsv($fh, $row, ';');
        fclose($fh);
        echo "\nInforme: $csvPath\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Pasada 2 — marcha.DURACION_SEG
//
//  Regla (decidida 2026-07-31): la duración de la OBRA se deriva de sus
//  GRABACIONES siempre que las haya.
//
//    · la marcha tiene >=1 grabación con duración → DURACION_SEG = MEDIANA
//      de ellas, se sobreescriba lo que se sobreescriba
//    · la marcha no tiene ninguna → se deja intacto el valor de catálogo
//
//  Se usa mediana y no media: con 3+ versiones, una toma en directo larga o un
//  fragmento corto desplazan la media y no la mediana. Con 1 o 2 grabaciones
//  ambas coinciden de facto (con 2, la mediana es la media de las dos).
//
//  El valor anterior no se pierde en silencio: TODO cambio queda en
//  `<csv>_marchas.csv`, y se marcan con AVISO los casos que conviene mirar
//  (duración resultante fuera de 30–600 s, o salto de más de 60 s respecto al
//  valor de catálogo), que es donde se concentran los fragmentos y popurrís.
// ─────────────────────────────────────────────────────────────────────────────

const DUR_MIN_PLAUSIBLE = 30;    // por debajo: fragmento, toque suelto o error
const DUR_MAX_PLAUSIBLE = 600;   // por encima: popurrí, disco entero o error

function esPlausible(int $seg): bool {
    return $seg >= DUR_MIN_PLAUSIBLE && $seg <= DUR_MAX_PLAUSIBLE;
}

if ($hacerMedian || $soloMedian) {
    echo "\n── Pasada 2 · marcha.DURACION_SEG ──\n";
    if (!$commit) {
        echo "  (en dry-run se calcula sobre las duraciones YA guardadas en BD;\n"
           . "   las de la pasada 1 aún no están escritas, así que estas cifras\n"
           . "   se quedan cortas. Con --commit salen las reales.)\n";
    }

    // Descontar la intro de percusión donde la haya: muchas grabaciones abren
    // con ~40 s de tambores antes de la marcha, y eso inflaría la mediana.
    // `disco_marcha.PERCUSION` (NULL = hereda) permite excepciones por pista.
    // Lo guardado en DURACION_SEG sigue siendo la duración REAL del track; el
    // descuento se aplica solo aquí, para el cálculo.
    $tienePercusion = (int) $db->query(
        "SELECT COUNT(*) FROM pragma_table_info('disco') WHERE name='PERCUSION'"
    )->fetchColumn() > 0;

    $sqlDur = $tienePercusion
        ? "SELECT dm.IDMARCHA,
                  dm.DURACION_SEG,
                  CASE WHEN COALESCE(dm.PERCUSION, d.PERCUSION, 0) = 1
                       THEN COALESCE(d.PERCUSION_SEG, 40) ELSE 0 END AS RESTA
           FROM disco_marcha dm
           JOIN disco d ON d.ID_DISCO = dm.ID_DISCO
           WHERE dm.DURACION_SEG IS NOT NULL AND dm.DURACION_SEG > 0
           ORDER BY dm.IDMARCHA"
        : "SELECT dm.IDMARCHA, dm.DURACION_SEG, 0 AS RESTA
           FROM disco_marcha dm
           WHERE dm.DURACION_SEG IS NOT NULL AND dm.DURACION_SEG > 0
           ORDER BY dm.IDMARCHA";

    if (!$tienePercusion) {
        echo "  AVISO: la columna disco.PERCUSION no existe todavía; no se descuenta\n"
           . "         ninguna intro. Ejecuta antes: php app/tools/migrate_ingest.php\n";
    }

    $filas = $db->query($sqlDur)->fetchAll(PDO::FETCH_ASSOC);

    $porMarcha = []; $conPercusion = 0;
    foreach ($filas as $f) {
        $resta = (int) $f['RESTA'];
        // Guarda de sensatez: si al descontar quedara una duración absurda,
        // el flag o la duración están mal — mejor usar el valor crudo que
        // meter un negativo o 12 s en la mediana.
        $neta = (int) $f['DURACION_SEG'] - $resta;
        if ($resta > 0 && $neta >= DUR_MIN_PLAUSIBLE) {
            $conPercusion++;
        } else {
            $neta = (int) $f['DURACION_SEG'];
        }
        $porMarcha[(int) $f['IDMARCHA']][] = $neta;
    }
    if ($conPercusion) printf("Grabaciones con intro descontada: %d\n", $conPercusion);

    $stActual = $db->prepare("SELECT TITULO, DURACION_SEG FROM marcha WHERE ID_MARCHA = ?");
    $stUpdM   = $db->prepare("UPDATE marcha SET DURACION_SEG = ? WHERE ID_MARCHA = ?");

    $nuevas = 0; $cambiadas = 0; $iguales = 0; $avisos = 0;
    $filasM = [];

    if ($commit) $db->beginTransaction();

    foreach ($porMarcha as $idMarcha => $vals) {
        sort($vals);
        $n = count($vals);
        $mediana = $n % 2
            ? $vals[intdiv($n, 2)]
            : (int) round(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2);

        $stActual->execute([$idMarcha]);
        $row = $stActual->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $titulo    = (string) $row['TITULO'];
        $actual    = $row['DURACION_SEG'];
        $actualInt = ($actual === null) ? null : (int) $actual;
        $vacia     = ($actualInt === null || $actualInt === 0);

        if ($vacia)                        { $accion = 'RELLENA';   $nuevas++; }
        elseif ($actualInt === $mediana)   { $accion = 'IGUAL';     $iguales++; }
        else                               { $accion = 'CAMBIA';    $cambiadas++; }

        // Marcar para revisión: resultado implausible, o salto grande respecto
        // al catálogo. No bloquea la escritura, solo la deja señalada.
        $aviso = '';
        if (!esPlausible($mediana)) {
            $aviso = sprintf('duración fuera de %d-%d s', DUR_MIN_PLAUSIBLE, DUR_MAX_PLAUSIBLE);
        } elseif (!$vacia && abs($actualInt - $mediana) > 60) {
            $aviso = 'salto >60 s respecto al catálogo';
        }
        if ($aviso !== '' && $accion !== 'IGUAL') $avisos++;

        if ($commit && $accion !== 'IGUAL') {
            $stUpdM->execute([$mediana, $idMarcha]);
        }

        if ($accion !== 'IGUAL') {
            $filasM[] = [
                $accion, $idMarcha, $titulo,
                $vacia ? '' : (string) $actualInt, $vacia ? '' : mmss($actualInt),
                (string) $mediana, mmss($mediana), (string) $n,
                $vacia ? '' : (string) abs($actualInt - $mediana),
                $aviso,
            ];
        }
    }

    if ($commit) $db->commit();

    printf("Marchas con grabación         : %d\n", count($porMarcha));
    printf("   rellenadas (estaban 0/NULL): %d\n", $nuevas);
    printf("   recalculadas               : %d\n", $cambiadas);
    printf("   ya coincidían              : %d\n", $iguales);
    printf("   marcadas con AVISO         : %d   ← revisar en el CSV\n", $avisos);
    printf("Las marchas sin ninguna grabación no se tocan.\n");

    $csvM = preg_replace('/\.csv$/', '', $csvPath) . '_marchas.csv';
    $fh2  = fopen($csvM, 'w');
    if ($fh2) {
        fwrite($fh2, "\xEF\xBB\xBF");
        fputcsv($fh2, ['ACCION','ID_MARCHA','TITULO','ACTUAL_SEG','ACTUAL_MMSS',
                       'MEDIANA_SEG','MEDIANA_MMSS','N_GRABACIONES','DIF_SEG','AVISO'], ';');
        foreach ($filasM as $row) fputcsv($fh2, $row, ';');
        fclose($fh2);
        echo "Informe de marchas: $csvM\n";
    }
}

echo "\n" . ($commit ? "Hecho. Cambios escritos en la BD.\n" : "Dry-run terminado. Nada escrito. Añade --commit para aplicar.\n");
