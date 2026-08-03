<?php
/**
 * fill_enlaces_odesli.php  —  enlaces en el resto de servicios, a partir del de Spotify
 *
 * Parte de un hecho: el enlace de Spotify del DISCO ya está curado a mano. De ahí
 * se deriva todo lo demás por IDENTIDAD (Odesli / song.link resuelve la misma
 * publicación en Apple, Deezer, Amazon, Tidal y YouTube Music), no por búsqueda
 * difusa. Por eso esto tiene muchísimos menos falsos positivos que
 * `match_discos.py`, que busca por título+artista.
 *
 * Dos niveles:
 *
 *   1. DISCO   → 1 llamada a Odesli con la URL de Spotify del álbum.
 *                Escribe enlace_streaming (TIPO_ENT='disco') de cada servicio.
 *
 *   2. PISTAS  → tracklist del álbum en cada servicio (Spotify/Apple/Deezer dan
 *                la URL de cada pista gratis, 1 llamada por álbum y servicio) y
 *                emparejado por título con `disco_marcha`, con el MISMO matcher
 *                que fill_duraciones.php (lib/music_match.php).
 *                Escribe enlace_streaming (TIPO_ENT='marcha') y, de paso,
 *                `disco_marcha.DURACION_SEG` si estaba vacía.
 *
 *                Amazon/Tidal/YouTube no tienen tracklist pública, así que a
 *                nivel de pista se resuelven con 1 llamada a Odesli POR PISTA.
 *                Es lo caro del script: ver "Coste y rate-limit" abajo.
 *
 * Nada se sobrescribe: todas las escrituras son INSERT OR IGNORE contra
 * UNIQUE(TIPO_ENT, ID_ENT, SERVICIO). Un enlace ya curado a mano NUNCA se pisa,
 * y volver a lanzar el script es idempotente.
 *
 * ── Coste y rate-limit ───────────────────────────────────────────────────────
 * Odesli sin API key admite ~10 peticiones/minuto por IP. El script pausa
 * `--pausa` segundos entre llamadas (6.5 por defecto) y cachea cada respuesta en
 * `php/data/odesli_cache/`, así que una segunda pasada no vuelve a pedir nada.
 * Para los 10 discos nuevos: 10 álbumes + ~99 pistas ≈ 12 min.
 * Para los 139 discos con Spotify serían ~1.500 llamadas (~2,7 h): en ese caso
 * conviene `--servicios-pista=apple,deezer`, que no usa Odesli en absoluto.
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
 *   --servicios=...        servicios a nivel de DISCO
 *                          (apple,deezer,amazon,tidal,youtube)
 *   --servicios-pista=...  servicios a nivel de PISTA (mismos + spotify)
 *   --no-pistas            solo nivel de disco
 *   --no-duraciones        no tocar disco_marcha.DURACION_SEG
 *   --min-sim=0.85         umbral de similitud de título para las pistas
 *   --min-sim-disco=0.55   por debajo, el enlace del disco va a enlace_candidato
 *                          (a curar en /dashboard/enlaces) en vez de publicarse
 *   --pausa=6.5            segundos entre llamadas a Odesli
 *   --limite-odesli=N      corta el run tras N llamadas a Odesli (red de seguridad)
 *   --sin-cache            ignora la caché de Odesli
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
$sinCache     = in_array('--sin-cache', $argvv, true);
$soloDiscos   = [];
$horasNuevos  = null;
$desde        = null;
$minSim       = 0.85;
$minSimDisco  = 0.55;
$pausa        = 6.5;
$limiteOdesli = 0;      // 0 = sin límite
$fixturePath  = null;

/** Servicios que este script sabe derivar. 'spotify' nunca se toca a nivel de disco: es la entrada. */
const SERVICIOS_VALIDOS = ['apple', 'deezer', 'amazon', 'tidal', 'youtube'];

$servicios      = SERVICIOS_VALIDOS;
$serviciosPista = array_merge(['spotify'], SERVICIOS_VALIDOS);

foreach ($argvv as $arg) {
    if ($arg === '--nuevos')                        $horasNuevos  = 24;
    if (str_starts_with($arg, '--nuevos='))         $horasNuevos  = (int) substr($arg, 9);
    if (str_starts_with($arg, '--disco='))          $soloDiscos[] = (int) substr($arg, 8);
    if (str_starts_with($arg, '--discos='))         $soloDiscos   = array_map('intval', array_filter(explode(',', substr($arg, 9))));
    if (str_starts_with($arg, '--desde='))          $desde        = substr($arg, 8);
    if (str_starts_with($arg, '--min-sim='))        $minSim       = (float) substr($arg, 10);
    if (str_starts_with($arg, '--min-sim-disco='))  $minSimDisco  = (float) substr($arg, 16);
    if (str_starts_with($arg, '--pausa='))          $pausa        = (float) substr($arg, 8);
    if (str_starts_with($arg, '--limite-odesli='))  $limiteOdesli = (int) substr($arg, 16);
    if (str_starts_with($arg, '--fixture='))        $fixturePath  = substr($arg, 10);
    if (str_starts_with($arg, '--servicios=')) {
        $servicios = array_values(array_intersect(
            array_map('trim', explode(',', substr($arg, 12))), SERVICIOS_VALIDOS
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

// ── .env (mismo parseo manual que fill_duraciones.php) ────────────────────────
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

// ── Fixtures (tests: sin red) ─────────────────────────────────────────────────
/** @var array{odesli?:array<string,array>, tracklists?:array<string,array>, token?:string}|null */
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
//  Odesli
// ─────────────────────────────────────────────────────────────────────────────

// PLATAFORMAS, spotifyIdDesdeUrl() y odesliParse() se movieron a
// lib/music_match.php (2026-08-03) para que el panel las use tal cual:
// el alta de un enlace de disco dispara la misma cascada que este script.

/**
 * Llama a Odesli con caché en disco. Devuelve el JSON decodificado o null.
 *
 * Contadores globales para respetar el rate-limit y poder cortar el run.
 */
function odesliLookup(string $url, float $pausa, bool $sinCache, ?array $fixture): ?array {
    global $ODESLI_LLAMADAS, $ODESLI_CACHE_HITS, $ODESLI_LIMITE, $ODESLI_CORTADO,
           $ODESLI_APAGADO, $ODESLI_FALLOS;

    if ($fixture !== null) {
        return $fixture['odesli'][$url] ?? null;      // modo test: ni red ni caché
    }

    // La caché se consulta SIEMPRE, aunque Odesli esté apagado: lo ya preguntado
    // sigue sirviendo y no cuesta nada.
    $dir  = __DIR__ . '/../../data/odesli_cache';
    $file = $dir . '/' . sha1($url) . '.json';
    if (!$sinCache && is_file($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j)) { $ODESLI_CACHE_HITS++; return $j; }
    }

    if ($ODESLI_APAGADO) return null;

    if ($ODESLI_LIMITE > 0 && $ODESLI_LLAMADAS >= $ODESLI_LIMITE) {
        $ODESLI_CORTADO = true;
        return null;
    }

    $api = 'https://api.song.link/v1-alpha.1/links?userCountry=ES&songIfSingle=true&url=' . rawurlencode($url);

    // Reintentos propios, más largos que los de httpGet(): el 429 de Odesli
    // exige esperar del orden de decenas de segundos, no de 2.
    $body = null;
    for ($i = 0; $i < 3; $i++) {
        $ODESLI_LLAMADAS++;
        $body = httpGet($api, ['Accept: application/json'], 1);
        if ($body !== null) break;
        $espera = 20 * ($i + 1);
        fwrite(STDERR, "     · Odesli no responde (¿429?), esperando {$espera}s…\n");
        sleep($espera);
    }
    usleep((int) ($pausa * 1_000_000));

    if ($body === null) {
        // Cuando Odesli entra en rate-limit duro no se recupera en el mismo run:
        // cada álbum pasa a costar 2 minutos de esperas para devolver nada. En vez
        // de arrastrar eso por todo el catálogo, se apaga y el run sigue con el
        // repesque por UPC, que no depende de Odesli y cubre Apple y Deezer.
        if (++$ODESLI_FALLOS >= 3) {
            $ODESLI_APAGADO = true;
            fwrite(STDERR,
                "\n⚠ Odesli ha fallado 3 veces seguidas: lo apago para el resto del run.\n"
              . "  Se sigue con UPC (Apple y Deezer) y con lo que ya haya en caché.\n"
              . "  Amazon y Tidal se quedarán fuera; relanza más tarde para completarlos.\n\n");
        }
        return null;
    }
    $ODESLI_FALLOS = 0;
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['linksByPlatform'])) return null;

    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, $body);
    return $json;
}

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

$ODESLI_LLAMADAS   = 0;
$ODESLI_CACHE_HITS = 0;
$ODESLI_LIMITE     = $limiteOdesli;
$ODESLI_CORTADO    = false;
$ODESLI_FALLOS     = 0;
$ODESLI_APAGADO    = in_array('--sin-odesli', $argvv, true);

// ─────────────────────────────────────────────────────────────────────────────
//  Selección de discos
// ─────────────────────────────────────────────────────────────────────────────

echo "══ fill_enlaces_odesli · run $runId ══\n";
echo $commit ? "MODO: COMMIT (escribe en BD)\n" : "MODO: DRY-RUN (no escribe nada)\n";
if ($FIXTURE !== null) echo "FIXTURE: $fixturePath (sin red)\n";
echo "Servicios  disco: " . implode(', ', $servicios) . "\n";
echo "Servicios  pista: " . ($hacerPistas ? implode(', ', $serviciosPista) : '— (--no-pistas)') . "\n";
echo "Umbrales: pista >= $minSim · disco >= $minSimDisco\n";

// Excluir Spotify del nivel de pista no es un ajuste menor: se lleva por delante
// media funcionalidad, y en silencio. Conviene decirlo antes de gastar el run.
if ($hacerPistas && !in_array('spotify', $serviciosPista, true)) {
    echo "\nAVISO: 'spotify' NO está en --servicios-pista. Eso implica:\n"
       . "  · ningún enlace de pista de Spotify\n"
       . "  · ni Amazon/Tidal/YouTube de pista — parten de la URL de la pista en Spotify\n"
       . "  · duraciones solo de los discos que tengan Apple o Deezer\n"
       . "Es lo correcto para una pasada masiva (no gasta Odesli), pero para un lote\n"
       . "pequeño quítalo: es de donde sale la mayor parte del trabajo.\n";
}
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
// INSERT OR IGNORE: la UNIQUE(TIPO_ENT, ID_ENT, SERVICIO) hace de guarda. Un
// enlace ya curado no se pisa nunca, y relanzar el script es idempotente.
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

/**
 * Por qué un servicio no ha devuelto tracklist, en una línea.
 *
 * Repite la llamada sin `httpGet()` para poder leer el código HTTP y el cuerpo
 * del error. Solo se invoca cuando ya ha fallado, así que no añade tráfico al
 * camino normal.
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
    curl_close($ch);

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

/** Servicios que ya tiene una entidad. */
function yaTiene(PDOStatement $st, string $tipo, int $id): array {
    $st->execute([$tipo, $id]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'SERVICIO');
}

// ── Token de Spotify (solo si hace falta bajar a pistas) ──────────────────────
$token   = null;
$tokenTs = time();
if ($hacerPistas && $FIXTURE === null) {
    if ($spotifyId && $spotifySecret) {
        $token   = spotifyToken($spotifyId, $spotifySecret);
        $tokenTs = time();
        echo $token ? "Token de Spotify OK.\n\n" : "AVISO: sin token de Spotify; no habrá tracklist ni duraciones.\n\n";
    } else {
        echo "AVISO: faltan SPOTIFY_CLIENT_ID/SECRET en .env; no habrá tracklist ni duraciones.\n\n";
    }

    // Sin token, el nivel de pista entero se cae: no hay tracklist, ni duraciones,
    // ni URL de pista de la que partir para Amazon/Tidal/YouTube. El run daría un
    // resultado pobre y silencioso — como el del 2026-08-01 11:57. Mejor parar.
    if ($token === null && !in_array('--sin-spotify', $argvv, true)) {
        fwrite(STDERR,
            "\nABORTADO: sin token de Spotify no se puede hacer el nivel de pista,\n"
          . "que es el 90% del trabajo. Diagnostica con:\n"
          . "    php php\\app\\tools\\diag_spotify.php --nuevos\n"
          . "Si aun así quieres la pasada solo a nivel de disco: --no-pistas,\n"
          . "o --sin-spotify para seguir con Apple/Deezer y sin Spotify.\n");
        exit(1);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Pasada principal
// ─────────────────────────────────────────────────────────────────────────────

$csvRows = [];
$stats = [
    'discos' => 0, 'disco_nuevos' => 0, 'disco_ya' => 0, 'disco_cand' => 0, 'disco_sin' => 0,
    'pistas' => 0, 'pista_nuevos' => 0, 'pista_ya' => 0, 'pista_sin_match' => 0,
    'duraciones' => 0, 'youtube_saltado' => 0, 'sin_odesli' => 0, 'odesli_off' => 0, 'por_upc' => 0,
];
$porServicio = [];

foreach ($discos as $d) {
    $idDisco = (int) $d['ID_DISCO'];
    $albumId = (string) ($d['ID_SPOTIFY'] ?: spotifyIdDesdeUrl((string) $d['URL_SPOTIFY'], 'album'));
    $stats['discos']++;

    printf("── [%d] %s · %s (%s)\n", $idDisco, (string) $d['NOMBRE_CD'],
        (string) ($d['BANDA'] ?? '—'), (string) $d['FECHA_CD']);

    // El token de client-credentials dura 3.600 s. Una pasada sobre el catálogo
    // entero tarda más que eso, así que se renueva antes de que caduque en vez de
    // descubrirlo con un 401 a mitad de camino (pasó el 2026-08-01).
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
    $faltan     = array_values(array_diff($servicios, $tieneDisco));

    $od = null;
    if ($faltan) {
        $od = odesliLookup((string) $d['URL_SPOTIFY'], $pausa, $sinCache, $FIXTURE);
        if ($od === null) {
            // Saltárselo a propósito (--sin-odesli, o apagado tras 3 fallos) no es
            // un fallo. Contarlo como tal llenaba el resumen de alarmas falsas.
            if ($ODESLI_APAGADO || $ODESLI_CORTADO) {
                $stats['odesli_off']++;
            } else {
                $stats['sin_odesli']++;
                echo "     Odesli no devuelve nada para este álbum\n";
                $csvRows[] = ['SIN_ODESLI', $idDisco, (string) $d['NOMBRE_CD'], '', '', '', '', '', (string) $d['URL_SPOTIFY']];
            }
        }
    } else {
        printf("     disco: ya tenía los %d servicios\n", count($servicios));
        $stats['disco_ya'] += count($servicios);
    }

    $idsPorServicio = [];      // servicio => id nativo del ÁLBUM (para su tracklist)
    foreach ($tieneDisco as $srv) {
        // Reaprovecha los ID_EXT que ya estuvieran guardados de pasadas anteriores.
        $q = $db->prepare("SELECT ID_EXT FROM enlace_streaming WHERE TIPO_ENT='disco' AND ID_ENT=? AND SERVICIO=?");
        $q->execute([$idDisco, $srv]);
        $ext = (string) ($q->fetchColumn() ?: '');
        if ($ext !== '') $idsPorServicio[$srv] = $ext;
    }

    $cubiertos = $tieneDisco;      // servicios ya resueltos (por BD u Odesli), para el repesque por UPC

    if ($od !== null) {
        $p   = odesliParse($od, $faltan);
        $sim = similitud((string) $d['NOMBRE_CD'], $p['titulo']);

        // Control de sanidad: que Odesli haya resuelto NUESTRO álbum y no otro.
        // Si el id de Spotify que devuelve no es el que le hemos dado, algo ha
        // agrupado mal y no se publica nada sin mirarlo.
        $mismoAlbum = ($p['id_spotify'] === '' || $p['id_spotify'] === $albumId);

        foreach ($p['enlaces'] as $srv => $e) {
            $idsPorServicio[$srv] = $e['id'];
            $cubiertos[] = $srv;

            // Por debajo del umbral (o álbum dudoso) → cola de curación, no publicación.
            if (!$mismoAlbum || $sim < $minSimDisco) {
                if ($commit) {
                    $stCand->execute([
                        'disco', $idDisco, $srv, $e['url'], $e['id'],
                        $p['titulo'], $p['artista'], (string) $d['FECHA_CD'],
                        $sim, $sim >= 0.40 ? 'MEDIA' : 'BAJA', $runId,
                    ]);
                }
                $stats['disco_cand']++;
                $csvRows[] = ['DISCO_CANDIDATO', $idDisco, (string) $d['NOMBRE_CD'], '', '', $srv,
                              number_format($sim, 4, '.', ''), $p['titulo'], $e['url']];
                continue;
            }

            if ($commit) $stEnlace->execute(['disco', $idDisco, $srv, $e['url'], $e['id'], null]);
            $stats['disco_nuevos']++;
            $porServicio['disco:' . $srv] = ($porServicio['disco:' . $srv] ?? 0) + 1;
            $csvRows[] = ['DISCO_NUEVO', $idDisco, (string) $d['NOMBRE_CD'], '', '', $srv,
                          number_format($sim, 4, '.', ''), $p['titulo'], $e['url']];
        }

        printf("     odesli: %d de %d servicios · sim=%.2f «%s»\n",
            count($p['enlaces']), count($faltan), $sim, $p['titulo']);
        if (!$mismoAlbum) {
            echo "     AVISO: Odesli devuelve otro álbum de Spotify ({$p['id_spotify']} ≠ $albumId) → todo a curación\n";
        }
    }

    // ── Repesque por UPC (Apple y Deezer) ────────────────────────────────────
    // Odesli tiene cobertura irregular: en el lote del 2026-08-01 no devolvió
    // ni un solo enlace de Apple para 10 discos. El UPC es el código de barras
    // de la edición, el mismo número en todos los catálogos, y ambos servicios
    // permiten buscar por él. Sigue siendo identidad, no búsqueda difusa.
    $porUpc = array_values(array_intersect(['apple', 'deezer'], array_diff($servicios, $cubiertos)));
    if ($porUpc) {
        $info = $FIXTURE !== null
            ? ($FIXTURE['album_info'][$albumId] ?? ['upc' => '', 'titulo' => '', 'artista' => '', 'anio' => ''])
            : spotifyAlbumInfo($albumId, $token);

        if (($info['upc'] ?? '') === '') {
            printf("     upc:    Spotify no da UPC de este álbum; %s se quedan sin repesque\n", implode('/', $porUpc));
        } else {
            foreach ($porUpc as $srv) {
                $hit = $FIXTURE !== null
                    ? ($FIXTURE['upc_' . $srv][$info['upc']] ?? null)
                    : ($srv === 'apple' ? albumPorUpcApple($info['upc']) : albumPorUpcDeezer($info['upc']));
                if ($hit === null || ($hit['url'] ?? '') === '') continue;

                $idsPorServicio[$srv] = (string) $hit['id'];
                $cubiertos[]          = $srv;
                $simU                 = similitud((string) $d['NOMBRE_CD'], (string) $hit['titulo']);
                $stats['por_upc']++;

                // Mismo listón que con Odesli: por debajo del umbral no se publica.
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
            printf("     upc:    %s → %s\n", $info['upc'],
                implode(', ', array_values(array_intersect($porUpc, $cubiertos))) ?: 'sin resultados');
        }
    }

    // Lo que sigue sin aparecer en ningún sitio, al informe.
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

        // Qué servicios tiene ya cada marcha (una marcha puede estar en varios discos).
        $tienePista = [];
        foreach ($pistas as $p2) {
            $tienePista[(int) $p2['IDMARCHA']] = yaTiene($stTiene, 'marcha', (int) $p2['IDMARCHA']);
        }

        // 1) Servicios con tracklist pública: 1 llamada por servicio, sin Odesli.
        $matchSpotify = [];   // ID_DM => track de Spotify (para el paso 2)
        $matchAlguno  = [];   // índice de pista emparejada por CUALQUIER servicio
        $vistos       = [];   // todos los tracks vistos, para medir los descartes
        foreach (array_intersect(['spotify', 'apple', 'deezer'], $serviciosPista) as $srv) {
            if (!isset($idsPorServicio[$srv]) || $idsPorServicio[$srv] === '') continue;

            if ($srv === 'spotify' && $token === null && $FIXTURE === null) {
                echo "     spotify  SIN TOKEN: no hay tracklist, ni enlaces de pista, ni duraciones.\n"
                   . "              Revisa SPOTIFY_CLIENT_ID/SECRET en .env — es el paso que más aporta.\n";
                continue;
            }
            $tracks = tracklistDe($srv, $idsPorServicio[$srv], $token, $FIXTURE);
            if (!$tracks) {
                // Sin esto el fallo es mudo: `httpGet()` devuelve null ante cualquier
                // error y se come el código HTTP. Preguntamos otra vez, en crudo, solo
                // para poder decir POR QUÉ no hay tracklist.
                $motivo = $FIXTURE !== null
                    ? 'fixture sin datos'
                    : porQueNoHayTracklist($srv, $idsPorServicio[$srv], $token);

                // Un 401 es token caducado: se renueva y se reintenta una vez, en vez
                // de perder este disco y todos los siguientes.
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
            // ISRC de la grabación (R-01). Solo Spotify lo exige aparte; Deezer ya lo trae.
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

                // Duración: se aprovecha el tracklist que ya hemos traído.
                if ($hacerDurac && (int) ($pi['DURACION_SEG'] ?? 0) === 0 && (int) $track['seg'] > 0) {
                    if ($commit) $stDur->execute([(int) $track['seg'], (int) $pi['ID_DM']]);
                    $pistas[$i]['DURACION_SEG'] = (int) $track['seg'];   // no repetirlo con el siguiente servicio
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

        // Las que no ha emparejado NINGÚN servicio, al informe.
        //
        // Antes esto solo se calculaba en la rama de Spotify, así que una pasada
        // con `--servicios-pista=apple,deezer` daba «sin emparejar: 0» aunque no
        // hubiera emparejado nada — un cero que mentía. Ahora se mide contra
        // todos los tracklists vistos, venga el match de donde venga.
        foreach ($pistas as $i => $pi) {
            if (isset($matchAlguno[$i]) || !$vistos) continue;
            $best = mejorDescartado($pi, $vistos);
            $stats['pista_sin_match']++;
            $csvRows[] = ['PISTA_SIN_MATCH', $idDisco, (string) $d['NOMBRE_CD'], (int) $pi['ID_DM'],
                          (string) $pi['TITULO'], '', number_format($best['score'], 4, '.', ''),
                          $best['titulo'], ''];
        }

        // 2) Servicios sin tracklist pública: Odesli pista a pista.
        //    Solo sobre las pistas que Spotify sí ha emparejado — sin URL de
        //    Spotify de la pista no hay nada que resolver.
        $odesliPista = array_values(array_intersect(['amazon', 'tidal', 'youtube'], $serviciosPista));
        if ($odesliPista && $matchSpotify) {
            $n = 0;
            foreach ($matchSpotify as $idDm => $mm) {
                if ($ODESLI_CORTADO) break;
                $pi  = $mm['pista'];
                $idM = (int) $pi['IDMARCHA'];

                // YouTube a nivel de pista: si la marcha ya tiene `marcha.AUDIO`,
                // la ficha ya pinta un embed de YouTube. Añadir otro enlace del
                // mismo servicio duplicaría el mismo vídeo en la misma página.
                $faltanP = array_values(array_diff($odesliPista, $tienePista[$idM]));
                if (in_array('youtube', $faltanP, true) && trim((string) ($pi['AUDIO'] ?? '')) !== '') {
                    $faltanP = array_values(array_diff($faltanP, ['youtube']));
                    $stats['youtube_saltado']++;
                }
                if (!$faltanP) continue;

                $urlTrack = (string) ($mm['track']['url'] ?? '');
                if ($urlTrack === '') continue;

                $odT = odesliLookup($urlTrack, $pausa, $sinCache, $FIXTURE);
                if ($odT === null) { $stats[($ODESLI_APAGADO || $ODESLI_CORTADO) ? 'odesli_off' : 'sin_odesli']++; continue; }

                $pt = odesliParse($odT, $faltanP);
                foreach ($pt['enlaces'] as $srv => $e) {
                    if ($commit) $stEnlace->execute(['marcha', $idM, $srv, $e['url'], $e['id'], null]);
                    $tienePista[$idM][] = $srv;
                    $stats['pista_nuevos']++;
                    $porServicio['pista:' . $srv] = ($porServicio['pista:' . $srv] ?? 0) + 1;
                    $csvRows[] = ['PISTA_NUEVO', $idDisco, (string) $d['NOMBRE_CD'], $idDm,
                                  (string) $pi['TITULO'], $srv, '', $pt['titulo'], $e['url']];
                }
                $n++;
            }
            if (!$ODESLI_APAGADO || $n > 0) {
                printf("     odesli   %d pistas resueltas en %s\n", $n, implode('/', $odesliPista));
            }
        }
    }

    if ($commit) $db->commit();      // una transacción por disco: un corte a mitad no deja el lote a medias

    if ($ODESLI_CORTADO) {
        echo "\nAVISO: alcanzado --limite-odesli=$limiteOdesli. Run cortado; relanza para continuar (la caché conserva lo pedido).\n";
        break;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Resumen + CSV
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── Resumen ──\n";
printf("Discos procesados        : %d\n", $stats['discos']);
printf("Enlaces de disco nuevos  : %d (%d repescados por UPC)\n", $stats['disco_nuevos'], $stats['por_upc']);
printf("   a curar en el panel   : %d\n", $stats['disco_cand']);
printf("   sin edición ahí       : %d\n", $stats['disco_sin']);
printf("Pistas del lote          : %d\n", $stats['pistas']);
printf("Enlaces de pista nuevos  : %d\n", $stats['pista_nuevos']);
printf("   ya tenían enlace      : %d\n", $stats['pista_ya']);
printf("   sin emparejar         : %d\n", $stats['pista_sin_match']);
printf("Duraciones rellenadas    : %d\n", $stats['duraciones']);
printf("YouTube saltado (AUDIO)  : %d\n", $stats['youtube_saltado']);
printf("Fallos de Odesli         : %d\n", $stats['sin_odesli']);
if ($stats['odesli_off'] > 0) {
    printf("Consultas omitidas       : %d (Odesli apagado; relanza sin --sin-odesli para Amazon/Tidal)\n",
        $stats['odesli_off']);
}
printf("Llamadas a Odesli        : %d (%d servidas de caché)\n", $ODESLI_LLAMADAS, $ODESLI_CACHE_HITS);
foreach ($porServicio as $k => $v) printf("   %-16s      : %d\n", $k, $v);

if (!$commit) echo "\nDRY-RUN: no se ha escrito nada. Repite con --commit cuando el CSV convenza.\n";

$fh = fopen($csvPath, 'w');
if ($fh) {
    fwrite($fh, "\xEF\xBB\xBF");   // BOM: Excel en Windows
    fputcsv($fh, ['ESTADO','ID_DISCO','DISCO','ID_DM','TITULO_BD','SERVICIO','SCORE','TITULO_SERVICIO','URL'], ';');
    foreach ($csvRows as $row) fputcsv($fh, $row, ';');
    fclose($fh);
    echo "\nInforme: $csvPath\n";
}
