<?php

declare(strict_types=1);

/*
 * Smoke tests REMOTOS contra un entorno desplegado (PRE o PRO), con datos
 * reales — a diferencia de ci_smoke.php, que asume los IDs de la fixture de
 * ci_fixture.php y solo vale contra el servidor embebido de CI.
 *
 * Todas las aserciones son independientes de los datos: rutas fijas (home,
 * health, robots, datos, llms.txt, feeds) y una muestra de URLs extraída del
 * propio sitemap del entorno. Lo usa el pipeline de despliegue
 * (.github/workflows/deploy.yml) justo después de cada deploy, y también se
 * puede lanzar a mano.
 *
 * Uso:
 *   php smoke_remote.php https://marchasdecristo.com
 *   php smoke_remote.php https://marchasdecristo.jaguerra27.helioho.st --pre
 *
 *   --pre           el entorno es preproducción: exige noindex global, robots
 *                   en Disallow total, cinta visible y 'entorno: pre' en /health
 *                   (sin --pre exige exactamente lo contrario).
 */

$base = null;
$esPre = false;
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--pre') $esPre = true;
    elseif ($base === null && !str_starts_with($a, '--')) $base = rtrim($a, '/');
    else { fwrite(STDERR, "Argumento no reconocido: $a\n"); exit(2); }
}
if ($base === null) {
    fwrite(STDERR, "Uso: php smoke_remote.php <base_url> [--pre]\n");
    exit(2);
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException("curl error en $url: " . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
}

function get200(string $path, string $base): array
{
    $r = httpGet($base . $path);
    if ($r['status'] !== 200) {
        throw new RuntimeException("$path → esperado 200, obtenido {$r['status']}");
    }
    return $r;
}

// ── Suite ────────────────────────────────────────────────────────────────
$tests = [];

$tests['health: db ok + entorno correcto'] = static function () use ($base, $esPre): void {
    $r = get200('/health', $base);
    if (!str_contains($r['body'], 'db: ok')) {
        throw new RuntimeException('/health → no contiene "db: ok"');
    }
    $esperado = 'entorno: ' . ($esPre ? 'pre' : 'prod');
    if (!str_contains($r['body'], $esperado)) {
        throw new RuntimeException("/health → no contiene '$esperado' (¿desplegado al host equivocado, o config.local.php sin 'preproduccion' correcto?)");
    }
};

$tests['home: 200 + og/twitter'] = static function () use ($base): void {
    $r = get200('/', $base);
    foreach (['og:image', 'twitter:card'] as $tag) {
        if (!str_contains($r['body'], $tag)) {
            throw new RuntimeException("home → falta '$tag'");
        }
    }
};

if ($esPre) {
    $tests['pre: noindex + X-Robots-Tag + cinta visible'] = static function () use ($base): void {
        $r = get200('/', $base);
        if (!str_contains($r['body'], 'name="robots" content="noindex"')) {
            throw new RuntimeException('home PRE → falta <meta name="robots" content="noindex">');
        }
        if (!str_contains($r['body'], 'pre-ribbon')) {
            throw new RuntimeException('home PRE → falta la cinta de preproducción');
        }
        if (!str_contains(strtolower($r['headers']['x-robots-tag'] ?? ''), 'noindex')) {
            throw new RuntimeException('home PRE → falta la cabecera X-Robots-Tag: noindex');
        }
    };
    $tests['pre: robots.txt en Disallow total'] = static function () use ($base): void {
        $r = get200('/robots.txt', $base);
        if (!str_contains($r['body'], "Disallow: /")) {
            throw new RuntimeException('robots.txt PRE → falta "Disallow: /"');
        }
        if (str_contains($r['body'], 'Sitemap:')) {
            throw new RuntimeException('robots.txt PRE → no debería anunciar el sitemap');
        }
    };
} else {
    $tests['prod: home indexable (sin noindex)'] = static function () use ($base): void {
        $r = get200('/', $base);
        if (str_contains($r['body'], 'name="robots" content="noindex"')) {
            throw new RuntimeException('home PROD → lleva noindex (¿config de PRE en el host de PRO?)');
        }
        if (str_contains($r['body'], 'pre-ribbon')) {
            throw new RuntimeException('home PROD → muestra la cinta de preproducción');
        }
    };
    $tests['prod: robots.txt con Sitemap'] = static function () use ($base): void {
        $r = get200('/robots.txt', $base);
        if (!str_contains($r['body'], 'Sitemap:')) {
            throw new RuntimeException('robots.txt PROD → falta la línea Sitemap:');
        }
    };
}

$tests['sitemap: bien formado + muestra de fichas en 200 con JSON-LD'] = static function () use ($base): void {
    $r = get200('/sitemap.xml', $base);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($r['body'])) {
        throw new RuntimeException('/sitemap.xml → XML mal formado');
    }
    $paths = [];
    foreach ($dom->getElementsByTagName('loc') as $node) {
        $paths[] = (string) parse_url($node->textContent, PHP_URL_PATH);
    }
    if (count($paths) < 10) {
        throw new RuntimeException('/sitemap.xml → menos de 10 <loc> (¿BD vacía o rota?)');
    }
    // Una ficha de marcha real (slug-id): 200 directo + JSON-LD presente.
    $marcha = null;
    foreach ($paths as $p) {
        if (preg_match('#^/marcha/[a-z0-9-]+-\d+$#', $p)) { $marcha = $p; break; }
    }
    if ($marcha === null) {
        throw new RuntimeException('/sitemap.xml → no contiene ninguna ficha de marcha');
    }
    $rm = get200($marcha, $base);
    if (!str_contains($rm['body'], 'application/ld+json')) {
        throw new RuntimeException("$marcha → sin JSON-LD");
    }
    // La API de esa misma ficha responde y declara la licencia.
    if (preg_match('/-(\d+)$/', $marcha, $m)) {
        $ra = get200('/api/marcha/' . $m[1] . '.json', $base);
        $d = json_decode($ra['body'], true);
        if (!is_array($d) || empty($d['licencia']['url'])) {
            throw new RuntimeException('/api/marcha/' . $m[1] . '.json → JSON inválido o sin licencia');
        }
    }
};

$tests['datos + llms.txt con licencia'] = static function () use ($base): void {
    if (!str_contains(get200('/datos', $base)['body'], 'CC BY 4.0')) {
        throw new RuntimeException('/datos → falta "CC BY 4.0"');
    }
    if (!str_contains(get200('/llms.txt', $base)['body'], 'CC BY 4.0')) {
        throw new RuntimeException('/llms.txt → falta "CC BY 4.0"');
    }
};

$tests['feeds bien formados'] = static function () use ($base): void {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML(get200('/feed.xml', $base)['body'])) {
        throw new RuntimeException('/feed.xml → XML mal formado');
    }
    $d = json_decode(get200('/feed.json', $base)['body'], true);
    if (!is_array($d) || empty($d['items'])) {
        throw new RuntimeException('/feed.json → JSON inválido o sin items');
    }
};

// Secciones que aún no se publican fuera de local (App\Secciones). Aquí no se
// puede leer la config del host, así que en vez de una lista fija —que habría
// que acordarse de tocar al publicar una sección— se comprueba la COHERENCIA:
// lo que el sitio anuncia tiene que responder 200, y lo que no anuncia tiene
// que responder 404. Eso caza los dos fallos reales: publicar a medias (nav o
// sitemap sin ruta) y ocultar a medias (ruta viva sin anunciar, o al revés).
$tests['secciones: lo anunciado responde 200 y lo oculto 404'] = static function () use ($base): void {
    $sitemap = get200('/sitemap.xml', $base)['body'];
    $home = get200('/', $base)['body'];
    foreach (['/dedicatorias', '/estado-catalogo', '/mapa', '/temporada'] as $indice) {
        $anunciada = str_contains($sitemap, '<loc>' . $base . $indice . '</loc>')
            || str_contains($home, 'href="' . $indice . '"');
        $status = httpGet($base . $indice)['status'];
        if ($anunciada && $status !== 200 && $status !== 302) {
            throw new RuntimeException("$indice → el sitio lo anuncia (nav o sitemap) pero responde $status");
        }
        if (!$anunciada && $status !== 404) {
            throw new RuntimeException("$indice → no está anunciado pero responde $status (se esperaba 404: sección oculta)");
        }
    }
};

$tests['404 correcto'] = static function () use ($base): void {
    $s = httpGet($base . '/marcha/no-existe-999999999')['status'];
    if ($s !== 404) {
        throw new RuntimeException("/marcha/no-existe-999999999 → esperado 404, obtenido $s");
    }
};

// ── Runner ───────────────────────────────────────────────────────────────
echo 'Smoke remoto contra ' . $base . ($esPre ? ' [PRE]' : ' [PROD]') . "\n";
$failed = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "  OK   $name\n";
    } catch (Throwable $e) {
        $failed[] = "$name: {$e->getMessage()}";
        echo "  FAIL $name — {$e->getMessage()}\n";
    }
}
echo "\n" . (count($tests) - count($failed)) . '/' . count($tests) . " pruebas superadas.\n";
if ($failed !== []) {
    fwrite(STDERR, "\nFallos:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
