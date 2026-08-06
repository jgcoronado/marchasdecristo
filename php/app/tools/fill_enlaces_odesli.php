<?php
/**
 * fill_enlaces_odesli.php  —  enlaces en el resto de servicios, a partir del de Spotify
 *
 * Parte de un hecho: el enlace de Spotify del DISCO ya está curado a mano. De ahí
 * se deriva todo lo demás por IDENTIDAD, no por búsqueda difusa.
 *
 * Dos niveles:
 *
 *   1. DISCO   → UPC del álbum de Spotify → busca el mismo álbum en Apple (iTunes),
 *                Deezer y TIDAL por código de barras.
 *                Escribe enlace_streaming (TIPO_ENT='disco') de cada servicio.
 *
 *   2. PISTAS  → tracklist del álbum en Spotify, Apple y Deezer (1 llamada por
 *                álbum y servicio) + emparejado por título con disco_marcha.
 *                Escribe enlace_streaming (TIPO_ENT='marcha') y, de paso,
 *                disco_marcha.DURACION_SEG si estaba vacía.
 *
 *                TIDAL a nivel de pista: ISRC de cada pista (ya vienen de Spotify)
 *                → 1 llamada a TIDAL por pista. Identidad real, no búsqueda difusa.
 *
 *                YouTube a nivel de pista: búsqueda por título + artista en YouTube
 *                Data API v3. Fuzzy; se aplica umbral de similitud. Cuota: 100
 *                búsquedas/día en el plan gratuito. Los resultados se cachean.
 *
 *                Amazon Music no tiene API pública: descartado.
 *
 * Nada se sobrescribe: todas las escrituras son INSERT OR IGNORE contra
 * UNIQUE(TIPO_ENT, ID_ENT, SERVICIO). Un enlace ya curado a mano NUNCA se pisa,
 * y volver a lanzar el script es idempotente.
 *
 * ── Claves en .env (raíz del repo) ───────────────────────────────────────────
 *   SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET   → tracklist y ISRCs de Spotify
 *   TIDAL_CLIENT_ID   / TIDAL_CLIENT_SECRET     → álbum por UPC + pista por ISRC
 *   YOUTUBE_API_KEY                             → búsqueda de vídeos (100/día gratis)
 *
 * ── Uso (PowerShell, desde la raíz del repo) ─────────────────────────────────
 *   php php\app\tools\fill_enlaces_odesli.php --nuevos                # dry-run
 *   php php\app\tools\fill_enlaces_odesli.php --nuevos --commit       # escribe
 *   php php\app\tools\fill_enlaces_odesli.php --disco=232 --commit
 *   php php\app\tools\fill_enlaces_odesli.php --servicios-pista=apple,deezer --commit
 *
 * ── Opciones ─────────────────────────────────────────────────────────────────
 *   --commit               escribe en BD (por defecto: dry-run, no toca nada)
 *   --nuevos[=N]           solo discos cuyo enlace de Spotify se dio de alta en
 *                          las últimas N horas (24 por defecto)
 *   --disco=ID             un solo disco   ·  --discos=1,2,3  varios
 *   --desde=YYYY-MM-DD     discos con enlace de Spotify dado de alta desde esa fecha
 *   --servicios=...        servicios a nivel de DISCO (apple, deezer, tidal)
 *   --servicios-pista=...  servicios a nivel de PISTA (spotify, apple, deezer, tidal, youtube)
 *   --no-pistas            solo nivel de disco
 *   --no-duraciones        no tocar disco_marcha.DURACION_SEG
 *   --sin-tidal            no usar TIDAL (útil si las credenciales no están listas)
 *   --sin-youtube          no usar YouTube Data API (útil si la cuota está agotada)
 *   --sin-cache            ignora la caché de YouTube
 *   --min-sim=0.85         umbral de similitud para emparejar pistas por título
 *   --min-sim-disco=0.55   por debajo, el enlace del disco va a enlace_candidato
 *   --fixture=RUTA         no toca la red: lee respuestas de un JSON (tests)
 *   --csv=RUTA             informe (por defecto php/data/enlaces_<runid>.csv)
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/music_match.php';

// ── Argumentos ────────────────────────────────────────────────────────────────
$argvv        = $argv ?? [];
$commit       = in_array('--commit', $argvv, true);
$hacerPistas  = !in_array('--no-pistas', $argvv, true);
$hacerDurac   = !in_array('--no-duraciones', $argvv, true);
$sinTidal     = in_array('--sin-tidal', $argvv, true);
$sinYoutube   = in_array('--sin-youtube', $argvv, true);
$sinCache     = in_array('--sin-cache', $argvv, true);
$soloDiscos   = [];
$horasNuevos  = null;
$desde        = null;
$minSim       = 0.85;
$minSimDisco  = 0.55;
$fixturePath  = null;

/**
 * Servicios soportados.
 *
 * A nivel de DISCO: apple, deezer, tidal (todos por UPC → identidad).
 * A nivel de PISTA: además spotify (tracklist) y youtube (búsqueda).
 * Amazon no tiene API pública: descartado.
 */
const SERVICIOS_VALIDOS      = ['apple', 'deezer', 'tidal', 'youtube'];
const SERVICIOS_VALIDOS_DISCO = ['apple', 'deezer', 'tidal'];

$servicios      = SERVICIOS_VALIDOS_DISCO;
$serviciosPista = array_merge(['spotify'], SERVICIOS_VALIDOS);

foreach ($argvv as $arg) {
    if ($arg === '--nuevos')                        $horasNuevos  = 24;
    if (str_starts_with($arg, '--nuevos='))         $horasNuevos  = (int) substr($arg, 9);
    if (str_starts_with($arg, '--disco='))          $soloDiscos[] = (int) substr($arg, 8);
    if (str_starts_with($arg, '--discos='))         $soloDiscos   = array_map('intval', array_filter(explode(',', substr($arg, 9))));
    if (str_starts_with($arg, '--desde='))          $desde        = substr($arg, 8);
    if (str_starts_with($arg, '--min-sim='))        $minSim       = (float) substr($arg, 10);
    if (str_starts_with($arg, '--min-sim-disco='))  $minSimDisco  = (float) substr($arg, 16);
    if (str_starts_with($arg, '--fixture='))        $fixturePath  = substr($arg, 10);
    if (str_starts_with($arg, '--servicios=')) {
        $servicios = array_values(array_intersect(
            array_map('trim', explode(',', substr($arg, 12))), SERVICIOS_VALIDOS_DISCO
        ));
    }
    if (str_starts_with($arg, '--servicios-pista=')) {
        $serviciosPista = array_values(array_intersect(
            array_map('trim', explode(',', substr($arg, 18))),
            array_merge(['spotify'], SERVICIOS_VALIDOS)
        ));
    }
}

$runId   = 'enl-' . date('Ymd-His');
$csvPath = __DIR__ . '/../../data/enlaces_' . $runId . '.csv';
foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--csv=')) $csvPath = substr($arg, 6);
}

$dbPath = __DIR__ . '/../../data/mdc.db';
if (!is_file($dbPath)) {
    fwrite(STDERR, "ERROR: no encuentro la BD en $dbPath\n");
    exit(1);
}

// ── .env ──────────────────────────────────────────────────────────────────────
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
$tidalId       = $dotenv['TIDAL_CLIENT_ID']       ?? '';
$tidalSecret   = $dotenv['TIDAL_CLIENT_SECRET']   ?? '';
$ytKey         = $dotenv['YOUTUBE_API_KEY']        ?? '';

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Fixtures (tests: sin red) ─────────────────────────────────────────────────
/**
 * Formato del fixture JSON:
 *   tracklists   → { "spotify:ID": [...], "apple:ID": [...], "deezer:ID": [...] }
 *   album_info   → { "spotifyAlbumId": { upc, titulo, artista, anio } }
 *   upc_apple    → { "UPC": { url, id, titulo, artista } }
 *   upc_deezer   → { "UPC": { url, id, titulo, artista } }
 *   upc_tidal    → { "UPC": { url, id, titulo, artista } }
 *   isrc_tidal   → { "ISRC": { url, id, titulo } }
 *   youtube      → { "titulo artista": { url, id, titulo } }
 *   token        → "fake-token"
 */
/** @var array|null */
$FIXTURE = null;
if ($fixturePath !== null) {
    if (!is_file($fixturePath)) {
        fwrite(STDERR, "ERROR: fixture no encontrado: $fixturePath\n");
        exit(1);
    }
    $FIXTURE = json_decode((string) file_get_contents($fixturePath), true);
    if (!is_array($FIXTURE)) {
        fwrite(STDERR, "ERROR: fixture ilegible: $fixturePath\n");
        exit(1);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Funciones locales
// ─────────────────────────────────────────────────────────────────────────────

/** Tracklist del servicio, con desvío a fixture en modo test. */
function tracklistDe(string $servicio, string $idExt, ?string $token, ?array $fixture): array {
    if ($fixture !== null) {
        return $fixture['tracklists'][$servicio . ':' . $idExt] ?? [];
    }
    return match ($servicio) {
        'spotify' => tracklistSpotify($idExt, $token),
        'apple'   => tracklistApple($idExt),
        'deezer'  => tracklistDeezer($idExt),
        default   => [],
    };
}

/**
 * Por qué un servicio no ha devuelto tracklist, en una línea.
 * Solo se invoca cuando ya ha fallado, así que no añade tráfico al camino normal.
 */
function porQueNoHayTracklist(string $srv, string $idExt, ?string $token): string {
    if ($srv === 'spotify' && $token === null) {
        return 'NO HAY TOKEN DE SPOTIFY (revisa SPOTIFY_CLIENT_ID/SECRET en .env)';
    }
    $url = match ($srv) {
        'spotify' => "https://api.spotify.com/v1/albums/$idExt/tracks?limit=50&market=ES",
        'apple'   => "https://itunes.apple.com/lookup?id=" . rawurlencode($idExt) . "&entity=song&limit=200&country=ES",
        'deezer'  => "https://api.deezer.com/album/" . rawurlencode($idExt) . "/tracks?limit=200",
        default   => '',
    };
    if ($url === '') return 'servicio desconocido';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: marchasdecristo/1.0'],
                                              $srv === 'spotify' ? ["Authorization: Bearer $token"] : []),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    $extra = substr(preg_replace('/\s+/', ' ', (string) $body) ?? '', 0, 140);
    return match (true) {
        $code === 0   => "sin respuesta (curl: $err) — proxy, TLS o falta de red",
        $code === 401 => 'HTTP 401: el token no vale o ha caducado a mitad del run',
        $code === 403 => 'HTTP 403: la app de Spotify está restringida',
        $code === 404 => "HTTP 404: el id «$idExt» no existe en ese servicio o no está en el mercado ES",
        $code === 429 => 'HTTP 429: rate-limit; baja el ritmo o reintenta más tarde',
        $code === 200 => "HTTP 200 pero 0 pistas útiles — respuesta: $extra",
        default       => "HTTP $code — $extra",
    };
}

/** Servicios que ya tiene una entidad en la BD. */
function yaTiene(PDOStatement $st, string $tipo, int $id): array {
    $st->execute([$tipo, $id]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'SERVICIO');
}

// ─────────────────────────────────────────────────────────────────────────────
//  Selección de discos
// ─────────────────────────────────────────────────────────────────────────────

echo "══ fill_enlaces_odesli · run $runId ══\n";
echo $commit ? "MODO: COMMIT (escribe en BD)\n" : "MODO: DRY-RUN (no escribe nada)\n";
if ($FIXTURE !== null) echo "FIXTURE: $fixturePath (sin red)\n";
echo "Servicios  disco: " . implode(', ', $servicios) . "\n";
echo "Servicios  pista: " . ($hacerPistas ? implode(', ', $serviciosPista) : '— (--no-pistas)') . "\n";
echo "Umbrales: pista >= $minSim · disco/youtube >= $minSimDisco\n";

if ($sinTidal) echo "AVISO: --sin-tidal activo (TIDAL deshabilitado)\n";
if ($sinYoutube) echo "AVISO: --sin-youtube activo (YouTube deshabilitado)\n";
echo "\n";

$where  = ["e.TIPO_ENT = 'disco'", "e.SERVICIO = 'spotify'"];
$params = [];
if ($soloDiscos) {
    $where[] = 'd.ID_DISCO IN (' . implode(',', array_map('intval', $soloDiscos)) . ')';
}
if ($horasNuevos !== null) {
    $where[]  = "e.FECHA_ALTA >= datetime('now', :horas)";
    $params[':horas'] = '-' . (int) $horasNuevos . ' hours';
}
if ($desde !== null) {
    $where[]  = 'e.FECHA_ALTA >= :desde';
    $params[':desde'] = $desde;
}

$st = $db->prepare("
    SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, b.NOMBRE_BREVE AS BANDA,
           e.URL AS URL_SPOTIFY, e.ID_EXT AS ID_SPOTIFY, e.FECHA_ALTA
    FROM disco d
    JOIN enlace_streaming e ON e.TIPO_ENT = 'disco' AND e.ID_ENT = d.ID_DISCO
    LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.ID_DISCO
");
$st->execute($params);
$discos = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$discos) {
    echo "No hay discos que encajen con el filtro. Nada que hacer.\n";
    exit(0);
}
echo "Discos en el lote: " . count($discos) . "\n\n";

// ── Sentencias ────────────────────────────────────────────────────────────────
$stEnlace = $db->prepare(
    "INSERT OR IGNORE INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, VERIFICADO, ISRC)
     VALUES (?, ?, ?, ?, ?, 1, ?)"
);
$stCand = $db->prepare(
    "INSERT OR IGNORE INTO enlace_candidato
        (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, TITULO_ENC, ARTISTA_ENC, ANIO_ENC, SCORE, CONFIANZA, ESTADO, RUN_ID)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)"
);
$stDur = $db->prepare("UPDATE disco_marcha SET DURACION_SEG = ? WHERE ID_DM = ? AND COALESCE(DURACION_SEG, 0) = 0");

$stTiene = $db->prepare(
    "SELECT SERVICIO FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ?"
);
$stPistas = $db->prepare("
    SELECT dm.ID_DM, dm.NUMEROMARCHA, dm.N_DISCO, dm.DURACION_SEG, dm.IDMARCHA, m.TITULO, m.AUDIO
    FROM disco_marcha dm
    JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
    WHERE dm.ID_DISCO = ?
    ORDER BY dm.N_DISCO, dm.NUMEROMARCHA
");

// ── Tokens ────────────────────────────────────────────────────────────────────

// Spotify
$token   = null;
$tokenTs = time();
if ($hacerPistas && $FIXTURE === null) {
    if ($spotifyId && $spotifySecret) {
        $token   = spotifyToken($spotifyId, $spotifySecret);
        $tokenTs = time();
        echo $token
            ? "Token de Spotify OK.\n"
            : "AVISO: sin token de Spotify; no habrá tracklist ni duraciones.\n";
    } else {
        echo "AVISO: faltan SPOTIFY_CLIENT_ID/SECRET en .env; no habrá tracklist ni duraciones.\n";
    }

    if ($token === null && !in_array('--sin-spotify', $argvv, true)) {
        fwrite(STDERR,
            "\nABORTADO: sin token de Spotify no se puede hacer el nivel de pista.\n"
          . "Diagnostica con:\n"
          . "    php php\\app\\tools\\diag_spotify.php --nuevos\n"
          . "Si quieres solo nivel de disco: añade --no-pistas.\n"
          . "O --sin-spotify para continuar con Apple/Deezer/TIDAL y sin Spotify.\n");
        exit(1);
    }
}

// TIDAL
$tidalToken = null;
if (!$sinTidal && $FIXTURE === null) {
    if ($tidalId && $tidalSecret) {
        $tidalToken = tidalToken($tidalId, $tidalSecret);
        echo $tidalToken
            ? "Token de TIDAL  OK.\n"
            : "AVISO: no se ha podido obtener token de TIDAL (revisa TIDAL_CLIENT_ID/SECRET en .env).\n";
    } else {
        echo "AVISO: faltan TIDAL_CLIENT_ID/SECRET en .env; TIDAL deshabilitado.\n";
    }
} elseif ($sinTidal) {
    // ya se informó arriba
} elseif ($FIXTURE !== null && isset($FIXTURE['token'])) {
    $tidalToken = $FIXTURE['token'];
}

// YouTube
if (!$sinYoutube && $ytKey === '' && $FIXTURE === null) {
    echo "AVISO: falta YOUTUBE_API_KEY en .env; YouTube deshabilitado.\n";
}

echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
//  Pasada principal
// ─────────────────────────────────────────────────────────────────────────────

$csvRows = [];
$stats = [
    'discos'         => 0,
    'disco_nuevos'   => 0,
    'disco_ya'       => 0,
    'disco_cand'     => 0,
    'disco_sin'      => 0,
    'pistas'         => 0,
    'pista_nuevos'   => 0,
    'pista_ya'       => 0,
    'pista_sin_match'=> 0,
    'duraciones'     => 0,
    'youtube_saltado'=> 0,
    'youtube_baja_sim' => 0,
    'por_upc'        => 0,
    'tidal_isrc'     => 0,
];
$porServicio = [];

foreach ($discos as $d) {
    $idDisco = (int) $d['ID_DISCO'];
    $albumId = (string) ($d['ID_SPOTIFY'] ?: spotifyIdDesdeUrl((string) $d['URL_SPOTIFY'], 'album'));
    $stats['discos']++;

    printf("── [%d] %s · %s (%s)\n", $idDisco, (string) $d['NOMBRE_CD'],
        (string) ($d['BANDA'] ?? '—'), (string) $d['FECHA_CD']);

    // Renovar token de Spotify si lleva más de 45 min (caduca a la hora).
    if ($token !== null && (time() - $tokenTs) > 2700) {
        $nuevo = spotifyToken($spotifyId, $spotifySecret);
        if ($nuevo !== null) {
            $token   = $nuevo;
            $tokenTs = time();
            echo "     · token de Spotify renovado (llevaba 45 min)\n";
        }
    }

    if ($albumId === '') {
        echo "     enlace de Spotify sin id de álbum reconocible; se salta\n";
        $csvRows[] = ['ERROR', $idDisco, (string) $d['NOMBRE_CD'], '', '', 'spotify', '', '', 'URL sin id de álbum'];
        continue;
    }

    if ($commit) $db->beginTransaction();

    // ── Nivel disco ──────────────────────────────────────────────────────────
    $tieneDisco = yaTiene($stTiene, 'disco', $idDisco);
    $cubiertos  = $tieneDisco;

    if (!array_diff($servicios, $tieneDisco)) {
        printf("     disco: ya tenía los %d servicios\n", count($servicios));
        $stats['disco_ya'] += count($servicios);
    }

    // Rellena $idsPorServicio con lo que ya está en BD (se usa en nivel de pista).
    $idsPorServicio = [];
    foreach ($tieneDisco as $srv) {
        $q = $db->prepare("SELECT ID_EXT FROM enlace_streaming WHERE TIPO_ENT='disco' AND ID_ENT=? AND SERVICIO=?");
        $q->execute([$idDisco, $srv]);
        $ext = (string) ($q->fetchColumn() ?: '');
        if ($ext !== '') $idsPorServicio[$srv] = $ext;
    }

    // ── Repesque por UPC (Apple, Deezer, TIDAL) ───────────────────────────────
    // El UPC es el código de barras de la edición: el mismo número en todos los
    // catálogos. Buscar por UPC es identidad, no búsqueda difusa.
    $porUpc = array_values(array_intersect(
        ['apple', 'deezer', 'tidal'],
        array_diff($servicios, $cubiertos)
    ));

    if ($porUpc) {
        $info = $FIXTURE !== null
            ? ($FIXTURE['album_info'][$albumId] ?? ['upc' => '', 'titulo' => '', 'artista' => '', 'anio' => ''])
            : spotifyAlbumInfo($albumId, $token);

        if (($info['upc'] ?? '') === '') {
            printf("     upc:    Spotify no da UPC de este álbum; %s se quedan sin repesque\n",
                implode('/', $porUpc));
        } else {
            foreach ($porUpc as $srv) {
                $hit = null;
                if ($FIXTURE !== null) {
                    $hit = $FIXTURE['upc_' . $srv][$info['upc']] ?? null;
                } else {
                    $hit = match ($srv) {
                        'apple'  => albumPorUpcApple($info['upc']),
                        'deezer' => albumPorUpcDeezer($info['upc']),
                        'tidal'  => ($tidalToken !== null ? albumPorUpcTidal($info['upc'], $tidalToken) : null),
                        default  => null,
                    };
                }

                if ($hit === null || ($hit['url'] ?? '') === '') continue;

                $idsPorServicio[$srv] = (string) $hit['id'];
                $cubiertos[]          = $srv;
                $simU                 = similitud((string) $d['NOMBRE_CD'], (string) $hit['titulo']);
                $stats['por_upc']++;

                if ($simU < $minSimDisco) {
                    if ($commit) {
                        $stCand->execute([
                            'disco', $idDisco, $srv, $hit['url'], (string) $hit['id'],
                            (string) $hit['titulo'], (string) $hit['artista'], (string) $d['FECHA_CD'],
                            $simU, $simU >= 0.40 ? 'MEDIA' : 'BAJA', $runId,
                        ]);
                    }
                    $stats['disco_cand']++;
                    $csvRows[] = ['DISCO_CANDIDATO_UPC', $idDisco, (string) $d['NOMBRE_CD'], '', '', $srv,
                                  number_format($simU, 4, '.', ''), (string) $hit['titulo'], (string) $hit['url']];
                    continue;
                }

                if ($commit) $stEnlace->execute(['disco', $idDisco, $srv, $hit['url'], (string) $hit['id'], null]);
                $stats['disco_nuevos']++;
                $porServicio['disco:' . $srv] = ($porServicio['disco:' . $srv] ?? 0) + 1;
                $csvRows[] = ['DISCO_NUEVO_UPC', $idDisco, (string) $d['NOMBRE_CD'], '', '', $srv,
                              number_format($simU, 4, '.', ''), (string) $hit['titulo'], (string) $hit['url']];
            }
            printf("     upc:    %s → resueltos: %s\n", $info['upc'],
                implode(', ', array_values(array_intersect($porUpc, $cubiertos))) ?: 'sin resultados');
        }
    }

    // Servicios que siguen sin aparecer en ningún sitio.
    foreach (array_values(array_diff($servicios, $cubiertos)) as $srv) {
        $stats['disco_sin']++;
        $csvRows[] = ['DISCO_SIN_ENLACE', $idDisco, (string) $d['NOMBRE_CD'], '', '', $srv, '', '', ''];
    }

    // ── Nivel pista ──────────────────────────────────────────────────────────
    if ($hacerPistas) {
        $idsPorServicio['spotify'] = $albumId;

        $stPistas->execute([$idDisco]);
        $pistas = $stPistas->fetchAll(PDO::FETCH_ASSOC);
        $stats['pistas'] += count($pistas);

        $tienePista = [];
        foreach ($pistas as $p2) {
            $tienePista[(int) $p2['IDMARCHA']] = yaTiene($stTiene, 'marcha', (int) $p2['IDMARCHA']);
        }

        // ── 1) Servicios con tracklist pública: 1 llamada por servicio ─────
        $matchSpotify = [];     // ID_DM => ['track' => ..., 'pista' => ...]
        $matchAlguno  = [];
        $vistos       = [];
        foreach (array_intersect(['spotify', 'apple', 'deezer'], $serviciosPista) as $srv) {
            if (!isset($idsPorServicio[$srv]) || $idsPorServicio[$srv] === '') continue;

            if ($srv === 'spotify' && $token === null && $FIXTURE === null) {
                echo "     spotify  SIN TOKEN: no hay tracklist, ni ISRCs para TIDAL, ni duraciones.\n"
                   . "              Revisa SPOTIFY_CLIENT_ID/SECRET en .env.\n";
                continue;
            }

            $tracks = tracklistDe($srv, $idsPorServicio[$srv], $token, $FIXTURE);
            if (!$tracks) {
                $motivo = $FIXTURE !== null
                    ? 'fixture sin datos'
                    : porQueNoHayTracklist($srv, $idsPorServicio[$srv], $token);

                // 401 de Spotify: renovar token y reintentar una vez.
                if ($srv === 'spotify' && str_contains($motivo, '401') && $spotifyId && $spotifySecret) {
                    $nuevo = spotifyToken($spotifyId, $spotifySecret);
                    if ($nuevo !== null) {
                        $token   = $nuevo;
                        $tokenTs = time();
                        echo "     spotify  token caducado → renovado, reintentando\n";
                        $tracks = tracklistDe($srv, $idsPorServicio[$srv], $token, $FIXTURE);
                    }
                }

                if (!$tracks) {
                    printf("     %-8s SIN TRACKLIST · %s\n", $srv, $motivo);
                    continue;
                }
            }

            // ISRCs (solo Spotify; Deezer los da en el listado directo).
            if ($srv === 'spotify') {
                $isrcs = $FIXTURE !== null
                    ? ($FIXTURE['isrcs'] ?? [])
                    : isrcsSpotify(array_column($tracks, 'id'), $token);
                foreach ($tracks as $k => $t) {
                    if (isset($isrcs[$t['id']])) $tracks[$k]['isrc'] = $isrcs[$t['id']];
                }
            }

            [$asig, ] = emparejar($pistas, $tracks, $minSim);
            $nuevos = $ya = 0;
            foreach ($asig as $i => $a) {
                $pi    = $pistas[$i];
                $idM   = (int) $pi['IDMARCHA'];
                $track = $a['track'];
                if ($srv === 'spotify') $matchSpotify[(int) $pi['ID_DM']] = ['track' => $track, 'pista' => $pi];

                if ($hacerDurac && (int) ($pi['DURACION_SEG'] ?? 0) === 0 && (int) $track['seg'] > 0) {
                    if ($commit) $stDur->execute([(int) $track['seg'], (int) $pi['ID_DM']]);
                    $pistas[$i]['DURACION_SEG'] = (int) $track['seg'];
                    $stats['duraciones']++;
                }

                if (($track['url'] ?? '') === '') continue;
                if (in_array($srv, $tienePista[$idM], true)) { $ya++; $stats['pista_ya']++; continue; }

                if ($commit) {
                    $stEnlace->execute(['marcha', $idM, $srv, $track['url'], (string) ($track['id'] ?? ''), $track['isrc'] ?? null]);
                }
                $tienePista[$idM][] = $srv;
                $nuevos++;
                $stats['pista_nuevos']++;
                $porServicio['pista:' . $srv] = ($porServicio['pista:' . $srv] ?? 0) + 1;
                $csvRows[] = ['PISTA_NUEVO', $idDisco, (string) $d['NOMBRE_CD'], (int) $pi['ID_DM'],
                              (string) $pi['TITULO'], $srv, number_format($a['score'], 4, '.', ''),
                              (string) $track['titulo'], (string) $track['url']];
            }
            printf("     %-8s %d pistas nuevas · %d ya tenía · %d/%d emparejadas\n",
                $srv, $nuevos, $ya, count($asig), count($pistas));

            foreach ($asig as $i => $a) $matchAlguno[$i] = true;
            foreach ($tracks as $t)     $vistos[] = $t;
        }

        // Pistas sin emparejar por ningún servicio → informe.
        foreach ($pistas as $i => $pi) {
            if (isset($matchAlguno[$i]) || !$vistos) continue;
            $best = mejorDescartado($pi, $vistos);
            $stats['pista_sin_match']++;
            $csvRows[] = ['PISTA_SIN_MATCH', $idDisco, (string) $d['NOMBRE_CD'], (int) $pi['ID_DM'],
                          (string) $pi['TITULO'], '', number_format($best['score'], 4, '.', ''),
                          $best['titulo'], ''];
        }

        // ── 2) TIDAL por ISRC ────────────────────────────────────────────────
        // Solo sobre las pistas que Spotify ha emparejado: son las que tienen ISRC.
        // 1 llamada a la API de TIDAL por pista; sin tracklist pública propia.
        if (in_array('tidal', $serviciosPista) && !$sinTidal && $matchSpotify) {
            $nuevosTidal = $yaTidal = 0;
            foreach ($matchSpotify as $idDm => $mm) {
                $pi   = $mm['pista'];
                $idM  = (int) $pi['IDMARCHA'];
                $isrc = (string) ($mm['track']['isrc'] ?? '');

                if (in_array('tidal', $tienePista[$idM], true)) { $yaTidal++; $stats['pista_ya']++; continue; }
                if ($isrc === '') continue;   // pista sin ISRC: no se puede resolver

                $hit = $FIXTURE !== null
                    ? ($FIXTURE['isrc_tidal'][$isrc] ?? null)
                    : ($tidalToken !== null ? pistaPorIsrcTidal($isrc, $tidalToken) : null);

                if ($hit === null) continue;

                if ($commit) $stEnlace->execute(['marcha', $idM, 'tidal', $hit['url'], $hit['id'], $isrc]);
                $tienePista[$idM][] = 'tidal';
                $nuevosTidal++;
                $stats['pista_nuevos']++;
                $stats['tidal_isrc']++;
                $porServicio['pista:tidal'] = ($porServicio['pista:tidal'] ?? 0) + 1;
                $csvRows[] = ['PISTA_NUEVO', $idDisco, (string) $d['NOMBRE_CD'], $idDm,
                              (string) $pi['TITULO'], 'tidal', '1.0000', (string) $hit['titulo'], (string) $hit['url']];
            }
            if ($tidalToken !== null || $FIXTURE !== null) {
                printf("     %-8s %d pistas nuevas · %d ya tenía (vía ISRC)\n",
                    'tidal', $nuevosTidal, $yaTidal);
            }
        }

        // ── 3) YouTube por búsqueda ──────────────────────────────────────────
        // Búsqueda título + artista en YouTube Data API v3.
        // Se aplica umbral $minSimDisco para filtrar resultados erróneos.
        // Se salta si la marcha ya tiene AUDIO (embed de YouTube ya en la ficha).
        $banda = (string) ($d['BANDA'] ?? '');
        if (in_array('youtube', $serviciosPista) && !$sinYoutube && $matchSpotify
            && ($ytKey !== '' || $FIXTURE !== null)) {
            $nuevosYt = $yaTieneYt = $saltadosYt = $bajaSimYt = 0;
            foreach ($matchSpotify as $idDm => $mm) {
                $pi  = $mm['pista'];
                $idM = (int) $pi['IDMARCHA'];

                // Si la marcha tiene AUDIO ya hay un embed de YouTube en la ficha.
                if (trim((string) ($pi['AUDIO'] ?? '')) !== '') {
                    $saltadosYt++;
                    $stats['youtube_saltado']++;
                    continue;
                }
                if (in_array('youtube', $tienePista[$idM], true)) { $yaTieneYt++; $stats['pista_ya']++; continue; }

                $titulo = (string) $pi['TITULO'];
                $hit    = $FIXTURE !== null
                    ? ($FIXTURE['youtube'][trim("$titulo $banda")] ?? null)
                    : pistaYoutube($titulo, $banda, $ytKey, $sinCache);

                if ($hit === null) continue;

                // Control de calidad: el título del vídeo debe parecerse al de la pista.
                $sim = similitud($titulo, (string) $hit['titulo']);
                if ($sim < $minSimDisco) {
                    $bajaSimYt++;
                    $stats['youtube_baja_sim']++;
                    $csvRows[] = ['PISTA_YT_BAJA_SIM', $idDisco, (string) $d['NOMBRE_CD'], $idDm,
                                  $titulo, 'youtube', number_format($sim, 4, '.', ''),
                                  (string) $hit['titulo'], (string) $hit['url']];
                    continue;
                }

                if ($commit) $stEnlace->execute(['marcha', $idM, 'youtube', $hit['url'], $hit['id'], null]);
                $tienePista[$idM][] = 'youtube';
                $nuevosYt++;
                $stats['pista_nuevos']++;
                $porServicio['pista:youtube'] = ($porServicio['pista:youtube'] ?? 0) + 1;
                $csvRows[] = ['PISTA_NUEVO', $idDisco, (string) $d['NOMBRE_CD'], $idDm,
                              $titulo, 'youtube', number_format($sim, 4, '.', ''),
                              (string) $hit['titulo'], (string) $hit['url']];
            }
            printf("     %-8s %d pistas nuevas · %d ya tenía · %d saltadas (AUDIO) · %d sim baja\n",
                'youtube', $nuevosYt, $yaTieneYt, $saltadosYt, $bajaSimYt);
        }
    }

    if ($commit) $db->commit();
}

// ─────────────────────────────────────────────────────────────────────────────
//  Resumen + CSV
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── Resumen ──\n";
printf("Discos procesados        : %d\n", $stats['discos']);
printf("Enlaces de disco nuevos  : %d (%d por UPC)\n", $stats['disco_nuevos'], $stats['por_upc']);
printf("   a curar en el panel   : %d\n", $stats['disco_cand']);
printf("   sin resolución        : %d\n", $stats['disco_sin']);
printf("Pistas del lote          : %d\n", $stats['pistas']);
printf("Enlaces de pista nuevos  : %d  (tidal vía ISRC: %d)\n", $stats['pista_nuevos'], $stats['tidal_isrc']);
printf("   ya tenían enlace      : %d\n", $stats['pista_ya']);
printf("   sin emparejar         : %d\n", $stats['pista_sin_match']);
printf("   youtube sim baja      : %d\n", $stats['youtube_baja_sim']);
printf("Duraciones rellenadas    : %d\n", $stats['duraciones']);
printf("YouTube saltado (AUDIO)  : %d\n", $stats['youtube_saltado']);
foreach ($porServicio as $k => $v) printf("   %-16s      : %d\n", $k, $v);

if (!$commit) echo "\nDRY-RUN: no se ha escrito nada. Repite con --commit cuando el CSV convenza.\n";

$fh = fopen($csvPath, 'w');
if ($fh) {
    fwrite($fh, "\xEF\xBB\xBF");   // BOM para Excel en Windows
    fputcsv($fh, ['ESTADO','ID_DISCO','DISCO','ID_DM','TITULO_BD','SERVICIO','SCORE','TITULO_SERVICIO','URL'], ';');
    foreach ($csvRows as $row) fputcsv($fh, $row, ';');
    fclose($fh);
    echo "\nInforme: $csvPath\n";
}
