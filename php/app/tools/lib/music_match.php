<?php
/**
 * lib/music_match.php — utilidades compartidas de emparejado con servicios de streaming.
 *
 * Extraído literalmente de `fill_duraciones.php` (2026-08-01) para que
 * `fill_enlaces_odesli.php` use EXACTAMENTE el mismo matcher y no se bifurquen
 * dos criterios de similitud sobre el mismo catálogo. El comportamiento no ha
 * cambiado: son las mismas funciones, movidas de sitio.
 *
 * Contiene:
 *   · normalización y similitud de títulos  (sinTildes … similitud)
 *   · HTTP con reintentos + token de Spotify (httpGet, spotifyToken)
 *   · tracklists por servicio               (tracklistSpotify/Apple/Deezer)
 *   · emparejado greedy 1:1                 (emparejar, mejorDescartado)
 *
 * Ninguna función aquí escribe en BD ni imprime nada.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
//  Normalización y similitud
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Quita diacríticos.
 *
 * Necesario: sin esto "Cristo del Perdon" (BD) vs "Cristo del Perdón" (Apple)
 * puntúa 0.74 y se perdería un match evidente. En el catálogo hay bastantes
 * títulos sin tildar, así que esto no es un caso raro.
 *
 * Se usa una tabla explícita en vez de Normalizer/iconv a propósito:
 *   - Normalizer exige la extensión intl (Slug.php ya la usa, pero este script
 *     debe poder correr en cualquier CLI, incluida la del hosting).
 *   - iconv('ASCII//TRANSLIT') sin locale adecuado BORRA la vocal acentuada
 *     ("Perdón" → "Perdn"), que es peor que no hacer nada.
 * La tabla cubre lo que aparece de verdad en títulos ES/PT/CA/IT.
 */
function sinTildes(string $s): string {
    static $mapa = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a','ā'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','ē'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ī'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ō'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ū'=>'u',
        'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y',
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','Õ'=>'O',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
        'Ñ'=>'N','Ç'=>'C','Ý'=>'Y',
    ];
    return strtr($s, $mapa);
}

/** Normaliza un título para comparación fuzzy. */
function normalizeTitulo(string $s): string {
    $s = sinTildes($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\bvol\.?\s*\d+/i', '', $s) ?? $s;
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/** Elimina sufijos de versión ("- En Directo", "(Live)", "feat. …") del título del servicio. */
function stripSufijoVersion(string $s): string {
    $s = preg_replace(
        '/\s*[-|]\s*(en\s+(directo|vivo)|live|directo|acoustic version|maqueta|bonus track|remaster(ed)?|version\s+\d{4}|estreno\s+\d{4}).*$/iu',
        '', $s
    ) ?? $s;
    $s = preg_replace('/\s*[\(\[](en\s+(directo|vivo)|live|remaster(ed)?[^\)\]]*|feat\.[^\)\]]*)[\)\]]\s*$/iu', '', $s) ?? $s;
    return trim($s);
}

/** 60% similar_text + 40% Jaccard de palabras (mismo criterio que fill_enlaces_streaming.php). */
function similitud(string $tituloBd, string $tituloSrv): float {
    $a = normalizeTitulo($tituloBd);
    $b = normalizeTitulo(stripSufijoVersion($tituloSrv));
    if ($a === '' || $b === '') return 0.0;

    similar_text($a, $b, $pct);
    $charScore = $pct / 100;

    $wordsA = array_unique(array_filter(explode(' ', $a)));
    $wordsB = array_unique(array_filter(explode(' ', $b)));
    $union  = count(array_unique(array_merge($wordsA, $wordsB)));
    $jac    = $union > 0 ? count(array_intersect($wordsA, $wordsB)) / $union : 0.0;

    return round(0.6 * $charScore + 0.4 * $jac, 4);
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTTP
// ─────────────────────────────────────────────────────────────────────────────

/** GET genérico con reintentos y backoff. Devuelve el body o null. */
function httpGet(string $url, array $headers = [], int $intentos = 4): ?string {
    for ($i = 0; $i < $intentos; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: marchasdecristo/1.0'], $headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($body)) return $body;
        if ($code === 429 || $code >= 500 || $code === 0) {
            sleep(2 * ($i + 1));   // backoff: iTunes limita ~20 req/min
            continue;
        }
        return null;                // 404, 403… no reintentar
    }
    return null;
}

/** Token de Spotify (client-credentials). */
function spotifyToken(string $id, string $secret): ?string {
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_USERPWD        => "$id:$secret",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Tracklists por servicio
//  Todas devuelven: [ ['titulo'=>string, 'seg'=>int, 'n'=>int|null, 'disco'=>int|null], ... ]
//
//  Campos añadidos en la extracción (2026-08-01), opcionales y aditivos — los
//  consumidores antiguos los ignoran:
//     'url'  → enlace público de la PISTA en ese servicio
//     'id'   → id nativo de la pista
//     'isrc' → solo Deezer lo da en el listado; Spotify exige otra llamada
// ─────────────────────────────────────────────────────────────────────────────

function tracklistSpotify(string $albumId, ?string $token): array {
    if (!$token || $albumId === '') return [];
    $out    = [];
    $offset = 0;
    do {
        $url  = "https://api.spotify.com/v1/albums/$albumId/tracks?limit=50&offset=$offset&market=ES";
        $body = httpGet($url, ["Authorization: Bearer $token"]);
        if ($body === null) break;
        $json  = json_decode($body, true);
        $items = $json['items'] ?? [];
        foreach ($items as $it) {
            if (!isset($it['name'], $it['duration_ms'])) continue;
            $out[] = [
                'titulo' => (string) $it['name'],
                'seg'    => (int) round(((int) $it['duration_ms']) / 1000),
                'n'      => isset($it['track_number']) ? (int) $it['track_number'] : null,
                'disco'  => isset($it['disc_number'])  ? (int) $it['disc_number']  : null,
                'id'     => (string) ($it['id'] ?? ''),
                'url'    => (string) ($it['external_urls']['spotify'] ?? ''),
                'isrc'   => null,   // el endpoint de álbum devuelve el track simplificado
            ];
        }
        $offset += 50;
        $hayMas = !empty($json['next']);
    } while ($hayMas && $offset < 500);
    return $out;
}

function tracklistApple(string $collectionId): array {
    if ($collectionId === '') return [];
    $url  = "https://itunes.apple.com/lookup?id=" . rawurlencode($collectionId)
          . "&entity=song&limit=200&country=ES";
    $body = httpGet($url);
    usleep(400000);                                   // cortesía con el rate-limit de iTunes
    if ($body === null) return [];
    $json = json_decode($body, true);
    $out  = [];
    foreach ($json['results'] ?? [] as $r) {
        if (($r['wrapperType'] ?? '') !== 'track') continue;
        if (!isset($r['trackName'], $r['trackTimeMillis'])) continue;
        $out[] = [
            'titulo' => (string) $r['trackName'],
            'seg'    => (int) round(((int) $r['trackTimeMillis']) / 1000),
            'n'      => isset($r['trackNumber']) ? (int) $r['trackNumber'] : null,
            'disco'  => isset($r['discNumber'])  ? (int) $r['discNumber']  : null,
            'id'     => (string) ($r['trackId'] ?? ''),
            'url'    => (string) ($r['trackViewUrl'] ?? ''),
            'isrc'   => null,
        ];
    }
    return $out;
}

function tracklistDeezer(string $albumId): array {
    if ($albumId === '') return [];
    $body = httpGet("https://api.deezer.com/album/" . rawurlencode($albumId) . "/tracks?limit=200");
    usleep(250000);
    if ($body === null) return [];
    $json = json_decode($body, true);
    $out  = [];
    foreach ($json['data'] ?? [] as $t) {
        if (!isset($t['title'], $t['duration'])) continue;
        $out[] = [
            'titulo' => (string) $t['title'],
            'seg'    => (int) $t['duration'],          // Deezer ya da segundos
            'n'      => isset($t['track_position']) ? (int) $t['track_position'] : null,
            'disco'  => isset($t['disk_number'])    ? (int) $t['disk_number']    : null,
            'id'     => (string) ($t['id'] ?? ''),
            'url'    => (string) ($t['link'] ?? ''),
            'isrc'   => isset($t['isrc']) ? (string) $t['isrc'] : null,
        ];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Identidad por código de barras (UPC/EAN)
//
//  Añadido 2026-08-01. Odesli resuelve la publicación en otros servicios, pero
//  su cobertura es irregular: en el lote de 10 discos de esa fecha no devolvió
//  NINGÚN enlace de Apple ni de YouTube, y solo 1 de Deezer.
//
//  El UPC es el mismo número en todos los catálogos — es el código de barras de
//  la edición. Buscar por UPC es tan identidad como Odesli, gratis y sin clave,
//  y cubre justo el hueco: Apple e iTunes aceptan `?upc=`, Deezer acepta
//  `/album/upc:`. No es una búsqueda difusa: o el código está o no está.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ficha del álbum en Spotify: UPC, título, artista y año.
 *
 * @return array{upc:string, titulo:string, artista:string, anio:string}
 */
function spotifyAlbumInfo(string $albumId, ?string $token): array {
    $vacio = ['upc' => '', 'titulo' => '', 'artista' => '', 'anio' => ''];
    if (!$token || $albumId === '') return $vacio;
    $body = httpGet("https://api.spotify.com/v1/albums/$albumId?market=ES", ["Authorization: Bearer $token"]);
    if ($body === null) return $vacio;
    $j = json_decode($body, true);
    if (!is_array($j)) return $vacio;
    return [
        'upc'     => (string) ($j['external_ids']['upc'] ?? ''),
        'titulo'  => (string) ($j['name'] ?? ''),
        'artista' => (string) ($j['artists'][0]['name'] ?? ''),
        'anio'    => substr((string) ($j['release_date'] ?? ''), 0, 4),
    ];
}

/**
 * Álbum de Apple por UPC.
 *
 * @return array{url:string, id:string, titulo:string, artista:string}|null
 */
function albumPorUpcApple(string $upc): ?array {
    if ($upc === '') return null;
    // Los UPC de 12 dígitos se guardan a veces como EAN de 13 con un 0 delante.
    // iTunes indexa uno u otro según la distribuidora, así que se prueban ambos.
    $variantes = [$upc];
    if (strlen($upc) === 13 && $upc[0] === '0') $variantes[] = substr($upc, 1);
    if (strlen($upc) === 12)                    $variantes[] = '0' . $upc;

    foreach ($variantes as $u) {
        $body = httpGet("https://itunes.apple.com/lookup?upc=" . rawurlencode($u) . "&country=ES&entity=album");
        usleep(400000);                                   // rate-limit de iTunes
        if ($body === null) continue;
        $j = json_decode($body, true);
        foreach ($j['results'] ?? [] as $r) {
            if (($r['wrapperType'] ?? '') !== 'collection') continue;
            return [
                'url'     => (string) ($r['collectionViewUrl'] ?? ''),
                'id'      => (string) ($r['collectionId'] ?? ''),
                'titulo'  => (string) ($r['collectionName'] ?? ''),
                'artista' => (string) ($r['artistName'] ?? ''),
            ];
        }
    }
    return null;
}

/**
 * Álbum de Deezer por UPC.
 *
 * @return array{url:string, id:string, titulo:string, artista:string}|null
 */
function albumPorUpcDeezer(string $upc): ?array {
    if ($upc === '') return null;
    $variantes = [$upc];
    if (strlen($upc) === 13 && $upc[0] === '0') $variantes[] = substr($upc, 1);
    if (strlen($upc) === 12)                    $variantes[] = '0' . $upc;

    foreach ($variantes as $u) {
        $body = httpGet("https://api.deezer.com/album/upc:" . rawurlencode($u));
        usleep(250000);
        if ($body === null) continue;
        $j = json_decode($body, true);
        if (!is_array($j) || isset($j['error']) || !isset($j['id'])) continue;
        return [
            'url'     => (string) ($j['link'] ?? ('https://www.deezer.com/album/' . $j['id'])),
            'id'      => (string) $j['id'],
            'titulo'  => (string) ($j['title'] ?? ''),
            'artista' => (string) ($j['artist']['name'] ?? ''),
        ];
    }
    return null;
}

/**
 * ISRC de cada pista de un álbum de Spotify, indexado por id de pista.
 *
 * El endpoint de álbum devuelve el track SIMPLIFICADO, que no trae `external_ids`.
 * Hay que pedir los objetos completos (50 por llamada, así que 1-2 llamadas por
 * álbum). El ISRC es el identificador de la GRABACIÓN, no de la obra: es lo que
 * pide R-01 para poder cruzar grabaciones entre servicios.
 *
 * @return array<string,string> id de pista => ISRC
 */
function isrcsSpotify(array $ids, ?string $token): array {
    if (!$token || !$ids) return [];
    $out = [];
    foreach (array_chunk(array_values(array_filter($ids)), 50) as $lote) {
        $body = httpGet('https://api.spotify.com/v1/tracks?market=ES&ids=' . implode(',', $lote),
                        ["Authorization: Bearer $token"]);
        if ($body === null) continue;
        $j = json_decode($body, true);
        foreach ($j['tracks'] ?? [] as $t) {
            if (!is_array($t)) continue;
            $isrc = (string) ($t['external_ids']['isrc'] ?? '');
            if ($isrc !== '') $out[(string) ($t['id'] ?? '')] = $isrc;
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Matching greedy 1:1 solo por título
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array $pistas  filas con ID_DM y TITULO
 * @param array $tracks  tracklist del servicio
 * @return array{0: array<int,array>, 1: array<int,bool>}  [asignaciones por índice de pista, tracks usados]
 */
function emparejar(array $pistas, array $tracks, float $minSim): array {
    $pares = [];
    foreach ($pistas as $i => $p) {
        foreach ($tracks as $j => $t) {
            $s = similitud((string) $p['TITULO'], $t['titulo']);
            if ($s >= $minSim) $pares[] = [$s, $i, $j];
        }
    }
    usort($pares, fn($a, $b) => $b[0] <=> $a[0]);

    $asig = [];
    $usoT = [];
    foreach ($pares as [$s, $i, $j]) {
        if (isset($asig[$i]) || isset($usoT[$j])) continue;
        $asig[$i] = ['score' => $s, 'track' => $tracks[$j]];
        $usoT[$j] = true;
    }
    return [$asig, $usoT];
}

/** Mejor score por debajo del umbral, para el informe de descartes. */
function mejorDescartado(array $pista, array $tracks): array {
    $best = ['score' => 0.0, 'titulo' => ''];
    foreach ($tracks as $t) {
        $s = similitud((string) $pista['TITULO'], $t['titulo']);
        if ($s > $best['score']) $best = ['score' => $s, 'titulo' => $t['titulo']];
    }
    return $best;
}

function mmss(?int $seg): string {
    return $seg === null ? '' : sprintf('%d:%02d', intdiv($seg, 60), $seg % 60);
}
