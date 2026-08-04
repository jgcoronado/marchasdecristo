<?php
/**
 * fill_enlaces_streaming.php
 *
 * Para cada banda con >= 2 enlaces de streaming en 'enlace_streaming',
 * usa la API de Spotify para:
 *   1) Obtener los álbumes del artista → cruzar con discos de la BD (fuzzy >=85%)
 *   2) Para cada álbum matcheado, obtener sus pistas → cruzar con marchas (fuzzy >=85%)
 *
 * Modo dry-run por defecto. Pasar --commit para escribir en BD.
 *
 * Con --commit:
 *   - score >= 0.85 → INSERT directo en enlace_streaming (VERIFICADO=1)
 *   - score 0.70–0.84 → INSERT en enlace_candidato (ESTADO='pendiente')
 *     y aparecen en /dashboard/enlaces para aprobar/rechazar manualmente.
 *
 * Uso:
 *   php tools/fill_enlaces_streaming.php [--commit] [--banda=ID]
 */

declare(strict_types=1);

// ── Configuración ─────────────────────────────────────────────────────────────
$dbPath        = __DIR__ . '/../../data/mdc.db';
// Leer .env manualmente (parse_ini_file no maneja valores con comillas simples + $ bien)
$dotenv = [];
foreach (file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $dotenv[trim($k)] = trim($v, " \t'\"");
}
$clientId      = $dotenv['SPOTIFY_CLIENT_ID']     ?? '';
$clientSecret  = $dotenv['SPOTIFY_CLIENT_SECRET'] ?? '';

$commit        = in_array('--commit', $argv ?? [], true);
$runId         = 'spotify-fill-' . date('Ymd-His');
$soloIdBanda   = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--banda=')) {
        $soloIdBanda = (int) substr($arg, 8);
    }
}

if (!$clientId || !$clientSecret) {
    die("ERROR: faltan SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET en .env\n");
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Normaliza un título para comparación fuzzy. */
function normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    // Quitar "vol.", números romanos sueltos al final, artículos, etc.
    $s = preg_replace('/\bvol\.?\s*\d+/i', '', $s) ?? $s;
    // Quitar caracteres no alfanuméricos salvo espacios
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Elimina sufijos de versión en vivo/directo del título de Spotify antes de comparar.
 * p.e. "Alma Mater - En Directo" → "Alma Mater"
 */
function stripLiveSuffix(string $s): string {
    return preg_replace(
        '/\s*[-|]\s*(en\s+(directo|vivo)|live|directo|acoustic version|maqueta|bonus track|estreno \d{4}|en directo|en vivo).*$/iu',
        '',
        $s
    ) ?? $s;
}

/**
 * Score combinado: 60% similar_text (caracteres) + 40% Jaccard (palabras).
 * Esto penaliza casos como "XXV Aniversario" ↔ "X Aniversario" (mismas letras,
 * palabras distintas) y premia coincidencias exactas de tokens.
 *
 * Antes de calcular, elimina sufijos de versión en vivo del título de Spotify.
 */
function similarity(string $webTitle, string $spTitle): float {
    // Quitar sufijo de versión al título de Spotify
    $spClean = stripLiveSuffix($spTitle);

    $a = normalize($webTitle);
    $b = normalize($spClean);
    if ($a === '' || $b === '') return 0.0;

    // similar_text (character similarity)
    similar_text($a, $b, $pct);
    $charScore = $pct / 100;

    // Jaccard de palabras
    $wordsA = array_unique(array_filter(explode(' ', $a)));
    $wordsB = array_unique(array_filter(explode(' ', $b)));
    $inter  = count(array_intersect($wordsA, $wordsB));
    $union  = count(array_unique(array_merge($wordsA, $wordsB)));
    $jaccardScore = $union > 0 ? $inter / $union : 0.0;

    return round(0.6 * $charScore + 0.4 * $jaccardScore, 4);
}

/** Obtiene token de Spotify (client-credentials). */
function spotifyToken(string $id, string $secret): string {
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_USERPWD        => "$id:$secret",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $res = json_decode((string) curl_exec($ch), true);
    return $res['access_token'] ?? throw new \RuntimeException('No se pudo obtener token de Spotify');
}

/** GET a la API de Spotify con reintentos. */
function spotifyGet(string $url, string $token): array {
    static $retries = 3;
    for ($i = 0; $i < $retries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200) return json_decode($body, true) ?? [];
        if ($code === 429) {
            // Rate-limit: espera y reintenta
            sleep(2);
            continue;
        }
        if ($code === 401) throw new \RuntimeException("Token expirado (401)");
        // Otros errores: devolver vacío
        return [];
    }
    return [];
}

/** Extrae el ID nativo de Spotify de una URL de artista. */
function spotifyArtistId(string $url): string {
    // https://open.spotify.com/intl-es/artist/XXXXX  o  /artist/XXXXX
    if (preg_match('#/artist/([A-Za-z0-9]+)#', $url, $m)) return $m[1];
    return '';
}

// ── Obtener token ─────────────────────────────────────────────────────────────
echo "Obteniendo token de Spotify...\n";
$token = spotifyToken($clientId, $clientSecret);
echo "Token OK.\n\n";

// ── Obtener bandas con >= 2 servicios ─────────────────────────────────────────
$sql = "
  SELECT b.ID_BANDA, b.NOMBRE_COMPLETO,
         GROUP_CONCAT(e.SERVICIO || '|' || e.URL, '||') as enlaces
  FROM banda b
  JOIN enlace_streaming e ON e.TIPO_ENT='banda' AND e.ID_ENT=b.ID_BANDA
  GROUP BY b.ID_BANDA
  HAVING COUNT(DISTINCT e.SERVICIO) >= 2
  ORDER BY b.NOMBRE_COMPLETO
";
$bandas = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if ($soloIdBanda !== null) {
    $bandas = array_filter($bandas, fn($b) => (int)$b['ID_BANDA'] === $soloIdBanda);
}

echo sprintf("Bandas a procesar: %d\n\n", count($bandas));

// ── Estructuras de resultados ─────────────────────────────────────────────────
$insertados  = [];  // ['tipo', 'id_ent', 'servicio', 'url', 'id_ext', 'titulo_wb', 'titulo_sp']
$dudas       = [];  // igual pero score entre 0.70 y 0.84
$sinMatch    = [];  // discos/marchas sin candidato en Spotify

// ── Insertar confirmado (>=85%) en enlace_streaming ──────────────────────────
function insertEnlace(PDO $db, bool $commit, string $tipo, int $idEnt, string $servicio, string $url, string $idExt): void {
    if (!$commit) return;
    $st = $db->prepare("
        INSERT OR IGNORE INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, VERIFICADO)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $st->execute([$tipo, $idEnt, $servicio, $url, $idExt]);
}

// ── Encolar duda (70-84%) en enlace_candidato para revisión en dashboard ─────
function insertCandidato(PDO $db, bool $commit, string $runId,
    string $tipo, int $idEnt, string $url, string $idExt,
    string $tituloSp, string $artistaSp, string $anioSp, float $score): void
{
    if (!$commit) return;
    $st = $db->prepare("
        INSERT OR IGNORE INTO enlace_candidato
            (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, TITULO_ENC, ARTISTA_ENC, ANIO_ENC, SCORE, CONFIANZA, ESTADO, RUN_ID)
        VALUES (?, ?, 'spotify', ?, ?, ?, ?, ?, ?, 'MEDIA', 'pendiente', ?)
    ");
    $st->execute([$tipo, $idEnt, $url, $idExt, $tituloSp, $artistaSp, $anioSp, $score, $runId]);
}

// ── Proceso principal ─────────────────────────────────────────────────────────
foreach ($bandas as $banda) {
    $idBanda   = (int) $banda['ID_BANDA'];
    $nombre    = $banda['NOMBRE_COMPLETO'];

    // Parsear sus servicios
    $servicios = [];
    foreach (explode('||', $banda['enlaces']) as $par) {
        [$srv, $url] = explode('|', $par, 2);
        $servicios[$srv] = $url;
    }

    echo "── $nombre (ID=$idBanda) ──\n";

    // Solo procesamos Spotify de momento (API abierta)
    $spotifyUrl = $servicios['spotify'] ?? '';
    $artistId   = spotifyArtistId($spotifyUrl);
    if (!$artistId) {
        echo "   Sin URL de Spotify, saltando.\n\n";
        continue;
    }

    // ── Obtener discos de la BD para esta banda ───────────────────────────────
    $discosDB = $db->prepare("
        SELECT d.ID_DISCO, d.NOMBRE_CD
        FROM disco d
        WHERE d.BANDADISCO = ?
          AND NOT EXISTS (
            SELECT 1 FROM enlace_streaming e
            WHERE e.TIPO_ENT='disco' AND e.ID_ENT=d.ID_DISCO AND e.SERVICIO='spotify'
          )
          AND NOT EXISTS (
            SELECT 1 FROM enlace_candidato c
            WHERE c.TIPO_ENT='disco' AND c.ID_ENT=d.ID_DISCO AND c.SERVICIO='spotify'
              AND c.ESTADO != 'rechazado'
          )
    ");
    $discosDB->execute([$idBanda]);
    $discosDB = $discosDB->fetchAll(PDO::FETCH_ASSOC);

    // ── Obtener marchas estrenadas por esta banda sin enlace Spotify ──────────
    $marchasDB = $db->prepare("
        SELECT m.ID_MARCHA, m.TITULO
        FROM marcha m
        WHERE m.BANDA_ESTRENO = ?
          AND NOT EXISTS (
            SELECT 1 FROM enlace_streaming e
            WHERE e.TIPO_ENT='marcha' AND e.ID_ENT=m.ID_MARCHA AND e.SERVICIO='spotify'
          )
          AND NOT EXISTS (
            SELECT 1 FROM enlace_candidato c
            WHERE c.TIPO_ENT='marcha' AND c.ID_ENT=m.ID_MARCHA AND c.SERVICIO='spotify'
              AND c.ESTADO != 'rechazado'
          )
    ");
    $marchasDB->execute([$idBanda]);
    $marchasDB = $marchasDB->fetchAll(PDO::FETCH_ASSOC);

    if (!$discosDB && !$marchasDB) {
        echo "   Todo enlazado. Saltando.\n\n";
        continue;
    }

    // ── Obtener álbumes del artista en Spotify ────────────────────────────────
    $albumsSpotify = [];
    $url = "https://api.spotify.com/v1/artists/$artistId/albums?include_groups=album,single&limit=50&market=ES";
    while ($url) {
        $data = spotifyGet($url, $token);
        foreach ($data['items'] ?? [] as $item) {
            $albumsSpotify[] = [
                'id'   => $item['id'],
                'name' => $item['name'],
                'url'  => $item['external_urls']['spotify'] ?? "https://open.spotify.com/album/{$item['id']}",
                'year' => substr((string) ($item['release_date'] ?? ''), 0, 4),
            ];
        }
        $url = $data['next'] ?? null;
    }
    echo sprintf("   Álbumes en Spotify: %d, discos en BD sin enlace: %d, marchas sin enlace: %d\n",
        count($albumsSpotify), count($discosDB), count($marchasDB));

    // ── Cruzar discos BD ↔ álbumes Spotify ───────────────────────────────────
    // Para cada disco de la BD, buscar el mejor álbum de Spotify
    $discosMatcheados = []; // idDisco => albumSpotify (para luego buscar pistas)
    foreach ($discosDB as $disco) {
        $bestScore = 0.0;
        $bestAlbum = null;
        foreach ($albumsSpotify as $album) {
            $score = similarity($disco['NOMBRE_CD'], $album['name']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAlbum = $album;
            }
        }
        if ($bestAlbum === null) {
            $sinMatch[] = ['tipo' => 'disco', 'id' => $disco['ID_DISCO'], 'titulo_wb' => $disco['NOMBRE_CD'], 'banda' => $nombre];
            continue;
        }
        if ($bestScore >= 0.85) {
            echo sprintf("   DISCO OK  (%.0f%%) «%s» → «%s»\n", $bestScore*100, $disco['NOMBRE_CD'], $bestAlbum['name']);
            insertEnlace($db, $commit, 'disco', (int)$disco['ID_DISCO'], 'spotify', $bestAlbum['url'], $bestAlbum['id']);
            $insertados[] = [
                'tipo' => 'disco', 'id' => $disco['ID_DISCO'], 'titulo_wb' => $disco['NOMBRE_CD'],
                'titulo_sp' => $bestAlbum['name'], 'score' => $bestScore, 'url' => $bestAlbum['url'],
            ];
            $discosMatcheados[$disco['ID_DISCO']] = $bestAlbum;
        } elseif ($bestScore >= 0.70) {
            echo sprintf("   DISCO ?   (%.0f%%) «%s» → «%s»\n", $bestScore*100, $disco['NOMBRE_CD'], $bestAlbum['name']);
            $dudas[] = [
                'tipo' => 'disco', 'id' => $disco['ID_DISCO'], 'titulo_wb' => $disco['NOMBRE_CD'],
                'titulo_sp' => $bestAlbum['name'], 'score' => $bestScore, 'url' => $bestAlbum['url'],
                'banda' => $nombre,
            ];
            insertCandidato($db, $commit, $runId, 'disco', (int)$disco['ID_DISCO'],
                $bestAlbum['url'], $bestAlbum['id'], $bestAlbum['name'], $nombre, $bestAlbum['year'], $bestScore);
        } else {
            echo sprintf("   DISCO SIN_MATCH (%.0f%%) «%s»\n", $bestScore*100, $disco['NOMBRE_CD']);
            $sinMatch[] = ['tipo' => 'disco', 'id' => $disco['ID_DISCO'], 'titulo_wb' => $disco['NOMBRE_CD'], 'banda' => $nombre, 'mejor_sp' => $bestAlbum['name'], 'score' => $bestScore];
        }
    }

    // ── Obtener pistas de todos los álbumes que haya que recorrer ─────────────
    // Para buscar marchas necesitamos pistas; las buscamos de TODOS los álbumes del artista
    if ($marchasDB) {
        // Construir mapa de pistas: [track_name => [id, url]]
        $tracksSpotify = [];
        foreach ($albumsSpotify as $album) {
            $turl = "https://api.spotify.com/v1/albums/{$album['id']}/tracks?limit=50&market=ES";
            while ($turl) {
                $data = spotifyGet($turl, $token);
                foreach ($data['items'] ?? [] as $track) {
                    $tracksSpotify[] = [
                        'id'        => $track['id'],
                        'name'      => $track['name'],
                        'url'       => $track['external_urls']['spotify'] ?? "https://open.spotify.com/track/{$track['id']}",
                        'album_id'  => $album['id'],
                        'album'     => $album['name'],
                        'album_year'=> $album['year'],
                    ];
                }
                $turl = $data['next'] ?? null;
            }
            usleep(100000); // 100ms entre peticiones para evitar rate-limit
        }

        echo sprintf("   Pistas en Spotify: %d\n", count($tracksSpotify));

        // ── Cruzar marchas BD ↔ pistas Spotify ───────────────────────────────
        foreach ($marchasDB as $marcha) {
            $bestScore = 0.0;
            $bestTrack = null;
            $bestAlbumName = '';
            foreach ($tracksSpotify as $track) {
                // IMPORTANTE: no confundir el título del álbum con el de la pista.
                // Solo comparamos marcha.TITULO con track.name (no con album.name).
                $score = similarity($marcha['TITULO'], $track['name']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTrack = $track;
                    $bestAlbumName = $track['album'];
                }
            }
            if ($bestTrack === null) {
                $sinMatch[] = ['tipo' => 'marcha', 'id' => $marcha['ID_MARCHA'], 'titulo_wb' => $marcha['TITULO'], 'banda' => $nombre];
                continue;
            }
            if ($bestScore >= 0.85) {
                echo sprintf("   MARCHA OK (%.0f%%) «%s» → «%s» (álbum: %s)\n",
                    $bestScore*100, $marcha['TITULO'], $bestTrack['name'], $bestAlbumName);
                insertEnlace($db, $commit, 'marcha', (int)$marcha['ID_MARCHA'], 'spotify', $bestTrack['url'], $bestTrack['id']);
                $insertados[] = [
                    'tipo' => 'marcha', 'id' => $marcha['ID_MARCHA'], 'titulo_wb' => $marcha['TITULO'],
                    'titulo_sp' => $bestTrack['name'], 'score' => $bestScore, 'url' => $bestTrack['url'],
                    'album' => $bestAlbumName,
                ];
            } elseif ($bestScore >= 0.70) {
                $dudas[] = [
                    'tipo' => 'marcha', 'id' => $marcha['ID_MARCHA'], 'titulo_wb' => $marcha['TITULO'],
                    'titulo_sp' => $bestTrack['name'], 'score' => $bestScore, 'url' => $bestTrack['url'],
                    'album' => $bestAlbumName, 'banda' => $nombre,
                ];
                insertCandidato($db, $commit, $runId, 'marcha', (int)$marcha['ID_MARCHA'],
                    $bestTrack['url'], $bestTrack['id'], $bestTrack['name'], $nombre,
                    $bestTrack['album_year'] ?? '', $bestScore);
            } else {
                $sinMatch[] = ['tipo' => 'marcha', 'id' => $marcha['ID_MARCHA'], 'titulo_wb' => $marcha['TITULO'],
                    'banda' => $nombre, 'mejor_sp' => $bestTrack['name'] ?? '', 'score' => $bestScore];
            }
        }
    }

    echo "\n";
    usleep(200000); // 200ms entre bandas
}

// ── Resumen ───────────────────────────────────────────────────────────────────
echo "\n";
echo "═══════════════════════════════════════════\n";
echo "RESUMEN\n";
echo "═══════════════════════════════════════════\n";
echo sprintf("Modo: %s\n", $commit ? 'COMMIT (escritura real)' : 'DRY-RUN (sin escritura)');
echo sprintf("Insertados automáticos (>=85%%): %d en enlace_streaming\n", count($insertados));
echo sprintf("Encolados para revisión (70-84%%): %d en enlace_candidato → /dashboard/enlaces\n", count($dudas));
echo sprintf("Sin match (<70%%): %d\n", count($sinMatch));

if ($dudas && !$commit) {
    echo "\n── DUDAS (dry-run; con --commit se encolan en /dashboard/enlaces) ──────\n";
    foreach ($dudas as $d) {
        $extra = isset($d['album']) ? " (álbum: {$d['album']})" : '';
        echo sprintf("[%s ID=%d score=%.0f%%] WEB: «%s»  →  SP: «%s»%s\n  URL: %s\n",
            strtoupper($d['tipo']), $d['id'], $d['score']*100,
            $d['titulo_wb'], $d['titulo_sp'], $extra, $d['url']);
    }
} elseif ($dudas && $commit) {
    echo "\n── DUDAS encoladas en /dashboard/enlaces ───────────────────────────────\n";
    foreach ($dudas as $d) {
        $extra = isset($d['album']) ? " (álbum: {$d['album']})" : '';
        echo sprintf("[%s ID=%d score=%.0f%%] «%s» → «%s»%s\n",
            strtoupper($d['tipo']), $d['id'], $d['score']*100,
            $d['titulo_wb'], $d['titulo_sp'], $extra);
    }
}

echo "\n── SIN MATCH ───────────────────────────────\n";
foreach ($sinMatch as $s) {
    $mejor = isset($s['mejor_sp']) ? " (mejor Spotify: «{$s['mejor_sp']}» {$s['score']})" : '';
    echo sprintf("[%s ID=%d] WEB: «%s» — Banda: %s%s\n",
        strtoupper($s['tipo']), $s['id'], $s['titulo_wb'], $s['banda'], $mejor);
}
