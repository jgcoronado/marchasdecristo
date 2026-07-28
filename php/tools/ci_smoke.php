<?php

declare(strict_types=1);

/*
 * Smoke tests de CI sobre un servidor ya arrancado con la fixture de
 * ci_fixture.php (ver .github/workflows/ci.yml). Cubre las rutas doradas de
 * las 5 entidades públicas: 200, redirección 308 de slug/alias incorrecto a
 * la canónica, 404, JSON-LD parseable y sitemap bien formado con una muestra
 * de URLs comprobadas.
 *
 * No usa ningún framework de test (cero dependencias de Composer): un fallo
 * de aserción es una excepción capturada por el runner.
 *
 * Uso: php ci_smoke.php <base_url, p.ej. http://127.0.0.1:8000>
 */

$base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8000'), '/');

/** @return array{status:int,headers:array<string,string>,body:string} */
function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
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

function assertStatus(string $path, int $expected, string $base): array
{
    $r = httpGet($base . $path);
    if ($r['status'] !== $expected) {
        throw new RuntimeException("$path → esperado $expected, obtenido {$r['status']}");
    }
    return $r;
}

function assertRedirect(string $path, string $expectedLocationSuffix, string $base): void
{
    $r = httpGet($base . $path);
    if ($r['status'] !== 308 && $r['status'] !== 301) {
        throw new RuntimeException("$path → esperado 308/301, obtenido {$r['status']}");
    }
    $loc = $r['headers']['location'] ?? '';
    if (!str_ends_with($loc, $expectedLocationSuffix)) {
        throw new RuntimeException("$path → Location '$loc' no termina en '$expectedLocationSuffix'");
    }
}

/** Extrae y valida cada bloque <script type="application/ld+json">, exige @type. */
function assertJsonLd(string $path, string $base, ?string $expectType = null): void
{
    $r = assertStatus($path, 200, $base);
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $r['body'], $m);
    if ($m[1] === []) {
        throw new RuntimeException("$path → sin bloques JSON-LD");
    }
    $foundType = false;
    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("$path → JSON-LD inválido: " . json_last_error_msg());
        }
        if ($expectType !== null && ($decoded['@type'] ?? null) === $expectType) {
            $foundType = true;
        }
    }
    if ($expectType !== null && !$foundType) {
        throw new RuntimeException("$path → ningún bloque JSON-LD con @type=$expectType");
    }
}

/**
 * M8: coherencia canónica ↔ JSON-LD. Recorre el JSON-LD de la página, extrae
 * cada 'url' que apunte a una ficha de entidad (por su path, no por host: el
 * JSON-LD usa siempre 'site_url' —producción—, no el $base local de CI) y
 * comprueba que resuelve en 200 directo contra el servidor de pruebas. Si la
 * URL embebida en el JSON-LD no fuera exactamente la canónica real,
 * resolvería con una redirección 308 en vez de 200 (el routing redirige por
 * ID a la canónica aunque el slug sea distinto).
 */
function assertJsonLdUrlsCanonical(string $path, string $base): void
{
    $r = assertStatus($path, 200, $base);
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $r['body'], $m);
    if ($m[1] === []) {
        throw new RuntimeException("$path → sin bloques JSON-LD");
    }
    $prefixes = ['/marcha/', '/autor/', '/banda/', '/disco/', '/dedicatoria/'];
    $paths = [];
    $collect = static function (mixed $node) use (&$collect, &$paths, $prefixes): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['url']) && is_string($node['url'])) {
            $p = (string) parse_url($node['url'], PHP_URL_PATH);
            foreach ($prefixes as $prefix) {
                if (str_starts_with($p, $prefix)) {
                    $paths[] = $p;
                    break;
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $collect($v);
            }
        }
    };
    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $collect($decoded);
        }
    }
    if ($paths === []) {
        throw new RuntimeException("$path → ninguna URL de entidad en el JSON-LD para comprobar coherencia");
    }
    foreach (array_unique($paths) as $p) {
        $status = httpGet($base . $p)['status'];
        if ($status !== 200) {
            throw new RuntimeException("$path → URL de JSON-LD '$p' no resuelve en 200 directo (status=$status) — no es la canónica real");
        }
    }
}

function assertContains(string $path, string $needle, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (!str_contains($r['body'], $needle)) {
        throw new RuntimeException("$path → no contiene '$needle'");
    }
}

/**
 * M1: valida un recurso de la API JSON. 200 + Content-Type JSON + bloque de
 * licencia + url canónica; y coherencia — cada 'url' de entidad embebida
 * (incluida la de la banda en un disco) resuelve en 200 directo, no en 308.
 */
function assertApi(string $path, string $expectRecurso, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (!str_contains($r['headers']['content-type'] ?? '', 'application/json')) {
        throw new RuntimeException("$path → Content-Type no es application/json");
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        throw new RuntimeException("$path → cuerpo no es JSON válido");
    }
    if (($d['recurso'] ?? null) !== $expectRecurso) {
        throw new RuntimeException("$path → recurso='" . ($d['recurso'] ?? 'null') . "', esperado '$expectRecurso'");
    }
    if (empty($d['licencia']['url']) || empty($d['url'])) {
        throw new RuntimeException("$path → falta el bloque 'licencia' o la 'url' canónica");
    }
    $prefixes = ['/marcha/', '/autor/', '/banda/', '/disco/'];
    $paths = [];
    $walk = static function (mixed $node) use (&$walk, &$paths, $prefixes): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['url']) && is_string($node['url'])) {
            $p = (string) parse_url($node['url'], PHP_URL_PATH);
            foreach ($prefixes as $pre) {
                if (str_starts_with($p, $pre)) {
                    $paths[] = $p;
                    break;
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($d);
    foreach (array_unique($paths) as $p) {
        $s = httpGet($base . $p)['status'];
        if ($s !== 200) {
            throw new RuntimeException("$path → url interna '$p' no resuelve en 200 directo (status=$s)");
        }
    }
}

function assertHeader(string $path, string $headerName, string $needle, string $base): void
{
    $r = assertStatus($path, 200, $base);
    $val = $r['headers'][strtolower($headerName)] ?? '';
    if (!str_contains($val, $needle)) {
        throw new RuntimeException("$path → cabecera $headerName='$val' no contiene '$needle'");
    }
}

function assertNoIndex(string $path, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (!str_contains($r['body'], 'name="robots" content="noindex"')) {
        throw new RuntimeException("$path → esperaba <meta name=\"robots\" content=\"noindex\">");
    }
}

function assertNotNoIndex(string $path, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (str_contains($r['body'], 'name="robots" content="noindex"')) {
        throw new RuntimeException("$path → no debería llevar noindex");
    }
}

function assertSitemap(string $base): void
{
    $r = assertStatus('/sitemap.xml', 200, $base);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($r['body']);
    if (!$ok) {
        $errs = array_map(static fn($e) => trim($e->message), libxml_get_errors());
        throw new RuntimeException('/sitemap.xml → XML mal formado: ' . implode('; ', $errs));
    }
    $locs = [];
    foreach ($dom->getElementsByTagName('loc') as $node) {
        $locs[] = $node->textContent;
    }
    if (count($locs) < 5) {
        throw new RuntimeException('/sitemap.xml → se esperaban al menos 5 <loc>, hay ' . count($locs));
    }
    $lastmods = $dom->getElementsByTagName('lastmod');
    if ($lastmods->length !== count($locs)) {
        throw new RuntimeException('/sitemap.xml → cada <url> debería llevar su <lastmod> (C2)');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmods->item(0)?->textContent ?? '')) {
        throw new RuntimeException('/sitemap.xml → <lastmod> no tiene forma YYYY-MM-DD');
    }
    // Muestra: home + primeras 4 URLs de detalle/hub, comprobadas con GET real.
    $sample = array_slice($locs, 0, 5);
    foreach ($sample as $loc) {
        $path = (string) parse_url($loc, PHP_URL_PATH);
        $qs = parse_url($loc, PHP_URL_QUERY);
        $rel = $path . ($qs ? '?' . $qs : '');
        $s = httpGet($base . $rel)['status'];
        if ($s !== 200) {
            throw new RuntimeException("/sitemap.xml → muestra '$rel' devolvió $s (se esperaba 200)");
        }
    }
}

// ── Suite ────────────────────────────────────────────────────────────────
$tests = [
    'home 200' => static fn() => assertStatus('/', 200, $base),
    'robots.txt 200 + Sitemap:' => static fn() => assertContains('/robots.txt', 'Sitemap:', $base),
    'health 200' => static fn() => assertStatus('/health', 200, $base),

    // ── og:image / twitter:card en TODAS las páginas (C4), no solo fichas ──
    'home og:image + twitter:card' => static function () use ($base): void {
        $r = assertStatus('/', 200, $base);
        foreach (['og:image', 'og:image:width', 'og:image:height', 'twitter:card', 'twitter:image'] as $tag) {
            if (!str_contains($r['body'], $tag)) {
                throw new RuntimeException("home → falta la etiqueta '$tag'");
            }
        }
    },
    'og-image.png servida' => static fn() => assertStatus('/assets/og-image.png', 200, $base),

    // ── Explorador (noindex, sin caché con query) ──────────────────────────
    'marcha explorador 200' => static fn() => assertStatus('/marcha', 200, $base),
    'marcha explorador noindex' => static fn() => assertNoIndex('/marcha', $base),
    'marcha búsqueda no-store' => static fn() => assertHeader('/marcha?titulo=consuelo', 'Cache-Control', 'no-store', $base),

    // ── Ficha de marcha: canónica, redirecciones, JSON-LD ──────────────────
    'marcha canónica 200' => static fn() => assertStatus('/marcha/consuelo-gitano-1', 200, $base),
    'marcha canónica JSON-LD MusicComposition' => static fn() => assertJsonLd('/marcha/consuelo-gitano-1', $base, 'MusicComposition'),
    'marcha solo-ID → 308 canónica' => static fn() => assertRedirect('/marcha/1', '/marcha/consuelo-gitano-1', $base),
    'marcha slug incorrecto → 308 canónica' => static fn() => assertRedirect('/marcha/titulo-erroneo-1', '/marcha/consuelo-gitano-1', $base),
    'marcha inexistente 404' => static fn() => assertStatus('/marcha/nada-999999', 404, $base),
    'marcha coherencia canónica ↔ JSON-LD (M8)' => static fn() => assertJsonLdUrlsCanonical('/marcha/costalero-bueno-3', $base),

    // ── Autor / Banda / Disco / Dedicatoria ─────────────────────────────────
    'autor listado 200' => static fn() => assertStatus('/autor', 200, $base),
    'autor ficha 200 + JSON-LD Person' => static fn() => assertJsonLd('/autor/jose-garcia-perez-1', $base, 'Person'),
    'banda listado 200' => static fn() => assertStatus('/banda', 200, $base),
    'banda ficha 200 + JSON-LD MusicGroup' => static fn() => assertJsonLd('/banda/banda-de-cctt-ntra-sra-de-la-victoria-las-cigarreras-1', $base, 'MusicGroup'),
    'disco listado 200' => static fn() => assertStatus('/disco', 200, $base),
    'disco ficha 200 + JSON-LD MusicAlbum' => static fn() => assertJsonLd('/disco/sevilla-cofrade-vol-1-1', $base, 'MusicAlbum'),
    'dedicatorias índice 200' => static fn() => assertStatus('/dedicatorias', 200, $base),
    'dedicatoria ficha 200 + JSON-LD CollectionPage' => static fn() => assertJsonLd('/dedicatoria/hdad-de-los-gitanos-sevilla-1', $base, 'CollectionPage'),
    'dedicatoria inexistente 404' => static fn() => assertStatus('/dedicatoria/nada-999999', 404, $base),

    'estadisticas → 301 rankings' => static fn() => assertRedirect('/estadisticas', '/rankings', $base),

    // ── Rankings (N-07): de siempre + drill-down por año ────────────────────
    'rankings 200' => static fn() => assertStatus('/rankings', 200, $base),
    'rankings año con sustancia 200 + indexable' => static fn() => assertNotNoIndex('/rankings/1995', $base),
    'rankings año con sustancia JSON-LD CollectionPage' => static fn() => assertJsonLd('/rankings/1995', $base, 'CollectionPage'),
    'rankings año thin → noindex' => static fn() => assertNoIndex('/rankings/1990', $base),
    'rankings año inexistente 404' => static fn() => assertStatus('/rankings/1800', 404, $base),
    'hub año enlaza a rankings del año' => static fn() => assertContains('/marcha/ano/1995', 'href="/rankings/1995"', $base),

    // ── Aniversarios (N-09): tramos fijos (no atados a la fecha real de hoy,
    // para que la suite no dependa del año en que se ejecute CI) ────────────
    'aniversarios sin año → 302 al año en curso' => static function () use ($base): void {
        $r = httpGet($base . '/aniversarios');
        if ($r['status'] !== 302) {
            throw new RuntimeException("/aniversarios → esperado 302, obtenido {$r['status']}");
        }
        $anioActual = gmdate('Y');
        $loc = $r['headers']['location'] ?? '';
        if (!str_ends_with($loc, "/aniversarios/$anioActual")) {
            throw new RuntimeException("/aniversarios → Location '$loc' no apunta al año en curso ($anioActual)");
        }
    },
    // 2020-25=1995 (3 marchas en la fixture) → con sustancia, indexable.
    'aniversarios 2020 (25 años → 1995) 200 + indexable' => static fn() => assertNotNoIndex('/aniversarios/2020', $base),
    'aniversarios 2020 JSON-LD CollectionPage' => static fn() => assertJsonLd('/aniversarios/2020', $base, 'CollectionPage'),
    // 2015-25=1990 (1 sola marcha) → thin, noindex pero no 404.
    'aniversarios 2015 (25 años → 1990) → noindex' => static fn() => assertNoIndex('/aniversarios/2015', $base),
    // 2010: ningún tramo de 25 en 25 cae en 1990 ni 1995 → sin coincidencias.
    'aniversarios 2010 (sin coincidencias) 404' => static fn() => assertStatus('/aniversarios/2010', 404, $base),
    'aniversarios año fuera de rango (1500) 404' => static fn() => assertStatus('/aniversarios/1500', 404, $base),

    // ── Anuario (N-08): resumen editorial dentro del hub de año existente ──
    'hub año con sustancia muestra resumen del año' => static function () use ($base): void {
        $r = assertStatus('/marcha/ano/1995', 200, $base);
        foreach (['Resumen del año', 'José García Pérez', 'Las Cigarreras'] as $needle) {
            if (!str_contains($r['body'], $needle)) {
                throw new RuntimeException("/marcha/ano/1995 → falta '$needle' en el resumen del año (N-08)");
            }
        }
    },
    'hub año thin no muestra resumen del año' => static function () use ($base): void {
        $r = assertStatus('/marcha/ano/1990', 200, $base);
        if (str_contains($r['body'], 'Resumen del año')) {
            throw new RuntimeException('/marcha/ano/1990 → año thin no debería mostrar el resumen (N-08)');
        }
    },

    // ── Mapa (N-10): coropleta SVG por provincia ────────────────────────────
    'mapa 200 + JSON-LD breadcrumbs' => static fn() => assertJsonLd('/mapa', $base, 'BreadcrumbList'),
    'mapa: provincia con datos es un enlace con recuento' => static function () use ($base): void {
        $r = assertStatus('/mapa', 200, $base);
        if (!str_contains($r['body'], '<a href="/mapa/provincia/sevilla"><g id="ES-SE" class="prov prov-1"><title>Sevilla: 4 marchas</title>')) {
            throw new RuntimeException('/mapa → Sevilla (ES-SE) no aparece como región enlazada con su recuento (mapa ampliado)');
        }
        if (!str_contains($r['body'], '<td><a href="/marcha/provincia/sevilla">Sevilla</a></td>')) {
            throw new RuntimeException('/mapa → falta la fila de Sevilla en la tabla accesible (catálogo directo)');
        }
        if (str_contains($r['body'], 'mapa-punto')) {
            throw new RuntimeException('/mapa → el mapa nacional no debería pintar puntos de localidad');
        }
    },
    'mapa: provincia sin datos no es un enlace' => static function () use ($base): void {
        $r = assertStatus('/mapa', 200, $base);
        if (!str_contains($r['body'], '<g id="ES-M" class="prov prov-0">')) {
            throw new RuntimeException('/mapa → Madrid (ES-M, sin marchas) debería quedar sin enlazar (nivel 0)');
        }
        if (str_contains($r['body'], '<a href="/mapa/provincia/madrid">')) {
            throw new RuntimeException('/mapa → Madrid no debería tener enlace de provincia (0 marchas en la fixture)');
        }
    },
    'mapa de provincia: municipio clicable, rotulado y con color de contraste + viewBox recortado' => static function () use ($base): void {
        $r = assertStatus('/mapa/provincia/sevilla', 200, $base);
        if (!str_contains($r['body'], '<a href="/marcha?localidad=Sevilla"><circle class="mapa-punto mapa-punto-n')) {
            throw new RuntimeException('/mapa/provincia/sevilla → el municipio Sevilla debería ser un punto clicable');
        }
        if (!str_contains($r['body'], '<text class="mapa-punto-label"') || !str_contains($r['body'], '>Sevilla</text>')) {
            throw new RuntimeException('/mapa/provincia/sevilla → el municipio Sevilla debería llevar su nombre rotulado');
        }
        if (!str_contains($r['body'], 'class="prov prov-provincia"')) {
            throw new RuntimeException('/mapa/provincia/sevilla → la provincia debería pintarse con el color de contraste, no la coropleta nacional');
        }
        if (str_contains($r['body'], 'viewBox="0 0 569 392"')) {
            throw new RuntimeException('/mapa/provincia/sevilla → el viewBox debería estar recortado a la provincia, no ser el del mapa nacional');
        }
    },
    'mapa de provincia: slug incorrecto → 308 canónico' => static fn() => assertRedirect('/mapa/provincia/SEVILLA', '/mapa/provincia/sevilla', $base),
    'mapa de provincia: inexistente → 404' => static fn() => assertStatus('/mapa/provincia/marte', 404, $base),

    // ── Temporada (N-04): contratos banda↔hermandad, alta manual ────────────
    'temporada sin año → 302 al año en curso' => static function () use ($base): void {
        $r = httpGet($base . '/temporada');
        if ($r['status'] !== 302) {
            throw new RuntimeException("/temporada → esperado 302, obtenido {$r['status']}");
        }
        $anioActual = gmdate('Y');
        $loc = $r['headers']['location'] ?? '';
        if (!str_ends_with($loc, "/temporada/$anioActual")) {
            throw new RuntimeException("/temporada → Location '$loc' no apunta al año en curso ($anioActual)");
        }
    },
    'temporada 2026 (con contratos) 200 + indexable, agrupado por hermandad' => static function () use ($base): void {
        assertNotNoIndex('/temporada/2026', $base);
        $r = assertStatus('/temporada/2026', 200, $base);
        foreach (['Hdad de los Gitanos', 'Virgen de las Angustias', 'Las Cigarreras', 'Cristo de la Salud', 'Tres Caídas', 'fuente'] as $needle) {
            if (!str_contains($r['body'], $needle)) {
                throw new RuntimeException("/temporada/2026 → falta '$needle'");
            }
        }
    },
    'temporada 2026 JSON-LD breadcrumbs' => static fn() => assertJsonLd('/temporada/2026', $base, 'BreadcrumbList'),
    'temporada sin contratos → noindex + mensaje vacío' => static function () use ($base): void {
        assertNoIndex('/temporada/2025', $base);
        $r = assertStatus('/temporada/2025', 200, $base);
        if (!str_contains($r['body'], 'Todavía no hay contratos registrados')) {
            throw new RuntimeException('/temporada/2025 → falta el mensaje de estado vacío');
        }
    },
    'temporada año fuera de rango (1500) 404' => static fn() => assertStatus('/temporada/1500', 404, $base),

    // ── Hubs de catálogo indexables (C1) ────────────────────────────────────
    'hub año con sustancia 200 + indexable' => static fn() => assertNotNoIndex('/marcha/ano/1995', $base),
    'hub año thin → noindex' => static fn() => assertNoIndex('/marcha/ano/1990', $base),
    'hub año inexistente 404' => static fn() => assertStatus('/marcha/ano/1800', 404, $base),
    'hub estilo alias cctt → 308 canónica' => static fn() => assertRedirect('/marcha/estilo/cctt', '/marcha/estilo/cornetas-y-tambores', $base),
    'hub estilo canónico 200 + JSON-LD CollectionPage' => static fn() => assertJsonLd('/marcha/estilo/cornetas-y-tambores', $base, 'CollectionPage'),
    'hub estilo desconocido 404' => static fn() => assertStatus('/marcha/estilo/inexistente', 404, $base),
    'hub provincia con mayúsculas → 308 canónica' => static fn() => assertRedirect('/marcha/provincia/Sevilla', '/marcha/provincia/sevilla', $base),
    'hub provincia canónica 200' => static fn() => assertStatus('/marcha/provincia/sevilla', 200, $base),
    'hub provincia desconocida 404' => static fn() => assertStatus('/marcha/provincia/nada', 404, $base),

    'sitemap.xml bien formado + muestra 200' => static fn() => assertSitemap($base),

    // ── Datos abiertos: API JSON, feeds, página «Datos», llms.txt (M1) ──────
    'API marcha .json + licencia + coherencia' => static fn() => assertApi('/api/marcha/1.json', 'marcha', $base),
    'API autor .json + coherencia' => static fn() => assertApi('/api/autor/1.json', 'autor', $base),
    'API banda .json + coherencia' => static fn() => assertApi('/api/banda/1.json', 'banda', $base),
    'API disco .json + coherencia (banda canónica)' => static fn() => assertApi('/api/disco/1.json', 'disco', $base),
    'API inexistente → 404 JSON' => static function () use ($base): void {
        $r = httpGet($base . '/api/marcha/999999.json');
        if ($r['status'] !== 404) {
            throw new RuntimeException('/api/marcha/999999.json → esperado 404, obtenido ' . $r['status']);
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d) || ($d['error'] ?? null) !== 'not_found') {
            throw new RuntimeException('/api/marcha/999999.json → JSON de error inesperado');
        }
    },
    'feed.xml bien formado + items' => static function () use ($base): void {
        $r = assertStatus('/feed.xml', 200, $base);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($r['body'])) {
            throw new RuntimeException('/feed.xml → XML mal formado');
        }
        if ($dom->getElementsByTagName('item')->length < 1) {
            throw new RuntimeException('/feed.xml → sin <item>');
        }
    },
    'feed.json es JSON Feed con items' => static function () use ($base): void {
        $r = assertStatus('/feed.json', 200, $base);
        $d = json_decode($r['body'], true);
        if (!is_array($d) || !str_contains((string) ($d['version'] ?? ''), 'jsonfeed.org')) {
            throw new RuntimeException('/feed.json → no declara versión de JSON Feed');
        }
        if (!isset($d['items']) || !is_array($d['items']) || $d['items'] === []) {
            throw new RuntimeException('/feed.json → sin items');
        }
    },
    'datos 200 + JSON-LD Dataset' => static fn() => assertJsonLd('/datos', $base, 'Dataset'),
    'datos indexable + licencia CC BY' => static function () use ($base): void {
        assertNotNoIndex('/datos', $base);
        assertContains('/datos', 'CC BY 4.0', $base);
    },
    'llms.txt licencia + patrón de API' => static function () use ($base): void {
        assertContains('/llms.txt', 'CC BY 4.0', $base);
        assertContains('/llms.txt', '/api/marcha/{id}.json', $base);
    },

    // ── Búsqueda global unificada (M3) ──────────────────────────────────────
    'buscar sin query 200 + noindex' => static function () use ($base): void {
        assertStatus('/buscar', 200, $base);
        assertNoIndex('/buscar', $base);
    },
    'buscar encuentra la marcha (título)' => static fn() => assertContains('/buscar?q=consuelo', 'Consuelo Gitano', $base),
    'buscar agrupa por entidad (compositor)' => static fn() => assertContains('/buscar?q=garcia', 'García Pérez', $base),
    'buscar sin resultados 200' => static fn() => assertStatus('/buscar?q=zzzznoexiste', 200, $base),
    'robots.txt bloquea /buscar' => static fn() => assertContains('/robots.txt', 'Disallow: /buscar', $base),
    'api/buscar JSON agrupado + prefijo' => static function () use ($base): void {
        $r = assertStatus('/api/buscar?q=cons', 200, $base);
        if (!str_contains($r['headers']['content-type'] ?? '', 'application/json')) {
            throw new RuntimeException('/api/buscar → Content-Type no es application/json');
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d) || !isset($d['grupos']['marchas'])) {
            throw new RuntimeException('/api/buscar → estructura de grupos inesperada');
        }
        // Prefijo "cons" debe encontrar "Consuelo Gitano" (FTS5 prefix).
        $titulos = array_column($d['grupos']['marchas']['items'] ?? [], 'titulo');
        if (!in_array('Consuelo Gitano', $titulos, true)) {
            throw new RuntimeException('/api/buscar?q=cons → no encontró "Consuelo Gitano" por prefijo');
        }
        // Cada item debe traer una url canónica que resuelva en 200 directo.
        $url = $d['grupos']['marchas']['items'][0]['url'] ?? '';
        if ($url === '' || httpGet($base . $url)['status'] !== 200) {
            throw new RuntimeException("/api/buscar → url de item '$url' no resuelve en 200");
        }
    },
    'api/buscar query corta → vacío' => static function () use ($base): void {
        $r = assertStatus('/api/buscar?q=c', 200, $base);
        $d = json_decode($r['body'], true);
        if (!is_array($d) || (int) ($d['total'] ?? -1) !== 0) {
            throw new RuntimeException('/api/buscar?q=c → debería devolver total 0 (query < 2 chars)');
        }
    },

    // ── og:image dinámica por entidad (M4) ──────────────────────────────────
    'og marcha .png generada' => static function () use ($base): void {
        $r = httpGet($base . '/og/marcha/1.png');
        if ($r['status'] !== 200) {
            throw new RuntimeException('/og/marcha/1.png → esperado 200, obtenido ' . $r['status']
                . ' (¿GD/FreeType no cargado en el runner?)');
        }
        if (!str_contains($r['headers']['content-type'] ?? '', 'image/png')) {
            throw new RuntimeException('/og/marcha/1.png → Content-Type no es image/png');
        }
        if (substr($r['body'], 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('/og/marcha/1.png → el cuerpo no es un PNG válido');
        }
    },
    'og de las 4 entidades 200 PNG' => static function () use ($base): void {
        foreach (['autor/1', 'banda/1', 'disco/1'] as $ruta) {
            $r = httpGet($base . '/og/' . $ruta . '.png');
            if ($r['status'] !== 200 || substr($r['body'], 0, 4) !== "\x89PNG") {
                throw new RuntimeException("/og/$ruta.png → no devolvió un PNG 200 (status {$r['status']})");
            }
        }
    },
    'og entidad inexistente → 302 a imagen de marca' => static function () use ($base): void {
        $r = httpGet($base . '/og/marcha/999999.png');
        if ($r['status'] !== 302) {
            throw new RuntimeException('/og/marcha/999999.png → esperado 302, obtenido ' . $r['status']);
        }
        if (!str_contains($r['headers']['location'] ?? '', '/assets/og-image.png')) {
            throw new RuntimeException('/og/marcha/999999.png → no redirige a la imagen de marca');
        }
    },
    'og tipo desconocido → 404' => static fn() => assertStatus('/og/nope/1.png', 404, $base),
    'ficha de marcha referencia su og dinámica' => static fn() => assertContains('/marcha/consuelo-gitano-1', '/og/marcha/1.png', $base),
];

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

echo "\n" . count($tests) - count($failed) . '/' . count($tests) . " pruebas superadas.\n";
if ($failed !== []) {
    fwrite(STDERR, "\nFallos:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
