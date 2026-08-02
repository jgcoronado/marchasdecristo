<?php
/**
 * diag_spotify.php — por qué `fill_enlaces_odesli.php` no saca tracklist de Spotify
 *
 * El primer dry-run del 2026-08-01 emparejó 0 pistas vía Spotify en los 10 discos
 * del lote, con el token OK. `httpGet()` devuelve null ante cualquier error y se
 * come el código HTTP, así que esto pregunta lo mismo pero enseñando la respuesta
 * cruda: código, cabecera de error de Spotify y nº de pistas.
 *
 * No escribe nada en ningún sitio. Es puro diagnóstico.
 *
 * Uso:
 *   php php\app\tools\diag_spotify.php              # los discos con enlace de Spotify
 *   php php\app\tools\diag_spotify.php --nuevos     # solo los de las últimas 24 h
 *   php php\app\tools\diag_spotify.php --disco=232
 *   php php\app\tools\diag_spotify.php --sin-market # repite sin ?market=ES
 */

declare(strict_types=1);

$argvv     = $argv ?? [];
$soloDisco = null;
$nuevos    = in_array('--nuevos', $argvv, true);
$sinMarket = in_array('--sin-market', $argvv, true);
foreach ($argvv as $a) {
    if (str_starts_with($a, '--disco=')) $soloDisco = (int) substr($a, 8);
}

// ── .env ──────────────────────────────────────────────────────────────────────
$dotenv  = [];
$envFile = __DIR__ . '/../../../.env';
echo "· .env: $envFile — " . (is_file($envFile) ? "existe\n" : "NO EXISTE\n");
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $dotenv[trim($k)] = trim($v, " \t'\"");
    }
}
$id     = $dotenv['SPOTIFY_CLIENT_ID']     ?? '';
$secret = $dotenv['SPOTIFY_CLIENT_SECRET'] ?? '';
printf("· CLIENT_ID leído: %s (%d caracteres)\n", $id === '' ? '—' : substr($id, 0, 6) . '…', strlen($id));
printf("· SECRET leído   : %s (%d caracteres)\n\n", $secret === '' ? '—' : '…', strlen($secret));

// ── Token, enseñando el error si lo hay ──────────────────────────────────────
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
$body  = (string) curl_exec($ch);
$code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr  = curl_error($ch);
curl_close($ch);
$token = json_decode($body, true)['access_token'] ?? null;

printf("· POST /api/token → HTTP %d %s\n", $code, $cerr !== '' ? "(curl: $cerr)" : '');
if (!$token) { echo "  respuesta: " . substr($body, 0, 300) . "\n\nSin token no se puede seguir.\n"; exit(1); }
printf("  token OK, %d caracteres, caduca en %s s\n\n", strlen($token), json_decode($body, true)['expires_in'] ?? '?');

// ── Discos ────────────────────────────────────────────────────────────────────
$db = new PDO("sqlite:" . __DIR__ . '/../../data/mdc.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$where = ["e.TIPO_ENT='disco'", "e.SERVICIO='spotify'"];
if ($soloDisco !== null) $where[] = 'd.ID_DISCO = ' . (int) $soloDisco;
if ($nuevos)             $where[] = "e.FECHA_ALTA >= datetime('now','-24 hours')";

$discos = $db->query("
    SELECT d.ID_DISCO, d.NOMBRE_CD, e.URL, e.ID_EXT
    FROM disco d JOIN enlace_streaming e ON e.TIPO_ENT='disco' AND e.ID_ENT=d.ID_DISCO
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.ID_DISCO
")->fetchAll(PDO::FETCH_ASSOC);

echo "Discos a comprobar: " . count($discos) . "\n";
echo str_repeat('─', 78) . "\n";

$market = $sinMarket ? '' : '?market=ES';

foreach ($discos as $d) {
    $albumId = (string) ($d['ID_EXT'] ?: '');
    if ($albumId === '' && preg_match('#album/([A-Za-z0-9]+)#', (string) $d['URL'], $m)) $albumId = $m[1];

    printf("\n[%d] %s\n", (int) $d['ID_DISCO'], (string) $d['NOMBRE_CD']);
    printf("    URL guardada : %s\n", (string) $d['URL']);
    printf("    ID_EXT en BD : %s\n", (string) ($d['ID_EXT'] ?? '—'));
    printf("    id que se usa: %s (%d caracteres)\n", $albumId === '' ? '—' : $albumId, strlen($albumId));
    if ($albumId === '') { echo "    ⚠ la URL no encaja con el patrón album/<id>\n"; continue; }

    foreach ([
        "https://api.spotify.com/v1/albums/$albumId$market"                              => 'álbum',
        "https://api.spotify.com/v1/albums/$albumId/tracks" . ($sinMarket ? '?limit=50' : '?limit=50&market=ES') => 'tracks',
    ] as $url => $etiqueta) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['User-Agent: marchasdecristo/1.0', "Authorization: Bearer $token"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $b    = curl_exec($ch);
        $c    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $j = is_string($b) ? json_decode($b, true) : null;
        if ($c === 200 && $etiqueta === 'tracks') {
            printf("    %-6s → HTTP %d · %d pistas · primera: «%s»\n", $etiqueta, $c,
                count($j['items'] ?? []), (string) ($j['items'][0]['name'] ?? '—'));
        } elseif ($c === 200) {
            printf("    %-6s → HTTP %d · «%s» · %s · UPC %s · %d pistas · mercados %d\n", $etiqueta, $c,
                (string) ($j['name'] ?? '?'), (string) ($j['artists'][0]['name'] ?? '?'),
                (string) ($j['external_ids']['upc'] ?? '—'), (int) ($j['total_tracks'] ?? 0),
                count($j['available_markets'] ?? []));
        } else {
            printf("    %-6s → HTTP %d %s · %s\n", $etiqueta, $c, $err !== '' ? "(curl: $err)" : '',
                substr(preg_replace('/\s+/', ' ', (string) $b) ?? '', 0, 200));
        }
    }
}

echo "\n" . str_repeat('─', 78) . "\n";
echo "Qué significa cada cosa:\n";
echo "  401 → el token no viaja o no vale (mira el 'Authorization' o vuelve a generarlo)\n";
echo "  404 → el id de álbum no existe para Spotify, o no está en el mercado ES\n";
echo "        → repite con --sin-market para descartar lo segundo\n";
echo "  403 → la app de developer.spotify.com está restringida o en modo desarrollo\n";
echo "  429 → rate-limit; espera lo que diga la cabecera Retry-After\n";
echo "  0   → no hay salida a internet o falla el TLS (proxy, antivirus, certificados)\n";
