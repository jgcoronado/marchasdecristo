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
 * Uso: php ci_smoke.php <base_url, p.ej. http://127.0.0.1:8000> [pro|local]
 *
 * El segundo argumento es el entorno que simula el servidor bajo prueba, porque
 * hay secciones que solo se publican en algunos (App\Secciones):
 *
 *   pro (por defecto) → sin config.local.php: env=production y sin
 *                       preproduccion, o sea producción. Las secciones en
 *                       maduración deben responder 404 y no asomar por el nav,
 *                       el sitemap ni llms.txt.
 *   local             → con un config.local.php de env=local. Ahí se ven todas,
 *                       y es la única pasada que puede probar su contenido.
 *
 * Ambas pasadas corren en CI (ver .github/workflows/ci.yml); el modo se
 * verifica contra /health para no dar por buena una suite que en realidad
 * corrió contra el entorno equivocado.
 */

$base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8000'), '/');
$modo = strtolower((string) ($argv[2] ?? 'pro'));
if (!in_array($modo, ['pro', 'local'], true)) {
    fwrite(STDERR, "Modo no reconocido: '$modo' (esperado 'pro' o 'local')\n");
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
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException("curl error en $url: " . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

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

/**
 * POST sin cuerpo útil: sirve para comprobar que una ruta POST existe y está
 * protegida. No se puede ir más allá sin sesión (y sin CSRF), que es
 * precisamente lo que se quiere verificar.
 *
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function httpPost(string $url, array $campos = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($campos),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException("curl error en $url: " . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => (string) substr($raw, $headerSize)];
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

/**
 * Paths (sin host) de todos los <loc> del sitemap.
 *
 * Por path y no por URL completa a propósito: el sitemap se construye siempre
 * con 'site_url' (producción), no con el $base del servidor de pruebas, así que
 * una aserción sobre la URL entera no casaría nunca — y una comprobación "no
 * contiene" pasaría en vacío sin comprobar nada.
 *
 * @return list<string>
 */
function sitemapPaths(string $base): array
{
    $r = assertStatus('/sitemap.xml', 200, $base);
    preg_match_all('#<loc>(.*?)</loc>#', $r['body'], $m);
    return array_map(
        static fn(string $u): string => (string) parse_url(html_entity_decode($u), PHP_URL_PATH),
        $m[1]
    );
}

/**
 * Paths enlazados en llms.txt (markdown: "- [Etiqueta](url)"). Mismo motivo que
 * sitemapPaths() para quedarse con el path.
 *
 * @return list<string>
 */
function llmsPaths(string $base): array
{
    $r = assertStatus('/llms.txt', 200, $base);
    preg_match_all('#\]\((.*?)\)#', $r['body'], $m);
    return array_map(
        static fn(string $u): string => (string) parse_url($u, PHP_URL_PATH),
        $m[1]
    );
}

/** ¿Hay algún path que sea $indice o cuelgue de él (/mapa → /mapa/provincia/x)? */
function anyUnder(array $paths, string $indice): bool
{
    foreach ($paths as $p) {
        if ($p === $indice || str_starts_with($p, $indice . '/')) {
            return true;
        }
    }
    return false;
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
    if (!str_contains($r['body'], 'name="robots" content="noindex')) {
        throw new RuntimeException("$path → esperaba <meta name=\"robots\" content=\"noindex\">");
    }
}

function assertNotNoIndex(string $path, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (str_contains($r['body'], 'name="robots" content="noindex')) {
        throw new RuntimeException("$path → no debería llevar noindex");
    }
}

/** Explorador limpio: indexable y con canonical propio que termina en $canonicalSuffix. */
function assertIndexableCanonical(string $path, string $canonicalSuffix, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (str_contains($r['body'], 'name="robots"')) {
        throw new RuntimeException("$path → no debería llevar <meta name=\"robots\">");
    }
    if (!preg_match('#<link rel="canonical" href="([^"]*)"#', $r['body'], $m)) {
        throw new RuntimeException("$path → falta <link rel=\"canonical\">");
    }
    $href = html_entity_decode($m[1]);
    $qs = parse_url($href, PHP_URL_QUERY);
    if (parse_url($href, PHP_URL_PATH) . ($qs !== null ? '?' . $qs : '') !== $canonicalSuffix) {
        throw new RuntimeException("$path → canonical '$href', se esperaba '…$canonicalSuffix'");
    }
}

/** Explorador con filtros: noindex, follow (los enlaces a fichas se siguen) y sin canonical. */
function assertNoIndexFollowSinCanonical(string $path, string $base): void
{
    $r = assertStatus($path, 200, $base);
    if (!str_contains($r['body'], '<meta name="robots" content="noindex, follow">')) {
        throw new RuntimeException("$path → esperaba <meta name=\"robots\" content=\"noindex, follow\">");
    }
    if (str_contains($r['body'], 'rel="canonical"')) {
        throw new RuntimeException("$path → no debería llevar canonical");
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
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmods->item(0)->textContent ?? '')) {
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

    // ── Exploradores: la versión limpia (y su ?page=N) es la vía de rastreo
    // hacia las fichas, así que se indexa; con filtros las combinaciones son
    // infinitas → noindex, follow ───────────────────────────────────────────
    'marcha explorador 200' => static fn() => assertStatus('/marcha', 200, $base),
    'exploradores limpios indexables con canonical propio' => static function () use ($base): void {
        foreach (['/marcha', '/autor', '/banda', '/disco'] as $path) {
            assertIndexableCanonical($path, $path, $base);
        }
    },
    // La fixture no llega a 2 páginas de 20: se prueba que ?page=1 explícito
    // apunta a la ruta limpia (sin duplicado).
    'explorador ?page=1 → canonical limpio' => static fn() => assertIndexableCanonical('/marcha?page=1', '/marcha', $base),
    'explorador con filtros → noindex, follow sin canonical' => static function () use ($base): void {
        foreach (['/marcha?q=x', '/marcha?limit=50', '/marcha?titulo=', '/banda?provincia=Sevilla', '/marcha?limit=10&page=2'] as $path) {
            assertNoIndexFollowSinCanonical($path, $base);
        }
    },
    'explorador página fuera de rango o no numérica → noindex, follow' => static function () use ($base): void {
        foreach (['/marcha?page=999', '/marcha?page=abc', '/marcha?page=0'] as $path) {
            assertNoIndexFollowSinCanonical($path, $base);
        }
    },
    'marcha búsqueda no-store' => static fn() => assertHeader('/marcha?titulo=consuelo', 'Cache-Control', 'no-store', $base),

    // ── Ficha de marcha: canónica, redirecciones, JSON-LD ──────────────────
    'marcha canónica 200' => static fn() => assertStatus('/marcha/consuelo-gitano-1', 200, $base),
    'marcha canónica JSON-LD MusicComposition' => static fn() => assertJsonLd('/marcha/consuelo-gitano-1', $base, 'MusicComposition'),
    'marcha solo-ID → 308 canónica' => static fn() => assertRedirect('/marcha/1', '/marcha/consuelo-gitano-1', $base),
    'marcha slug incorrecto → 308 canónica' => static fn() => assertRedirect('/marcha/titulo-erroneo-1', '/marcha/consuelo-gitano-1', $base),
    'marcha inexistente 404' => static fn() => assertStatus('/marcha/nada-999999', 404, $base),
    // Rastreadores y monitores de caída preguntan con HEAD: si el router solo
    // atiende GET, la web entera les parece caída (404).
    'HEAD responde como GET' => static function () use ($base): void {
        foreach (['/' => 200, '/marcha/consuelo-gitano-1' => 200, '/sitemap.xml' => 200, '/marcha/nada-999999' => 404] as $path => $esperado) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status !== $esperado) {
                throw new RuntimeException("HEAD $path → esperado $esperado, obtenido $status");
            }
        }
    },
    // ID 0 («Varias bandas») es un comodín, no una banda: fuera de Google.
    'banda comodín 0 fuera del sitemap y con noindex' => static function () use ($base): void {
        foreach (sitemapPaths($base) as $path) {
            if (preg_match('#^/banda/.*-0$#', $path)) {
                throw new RuntimeException("/sitemap.xml → anuncia el comodín $path");
            }
        }
        $r = assertStatus('/banda/varias-bandas-0', 200, $base);
        if (!str_contains($r['body'], 'name="robots" content="noindex')) {
            throw new RuntimeException('/banda/varias-bandas-0 → debería llevar noindex');
        }
    },
    'marcha coherencia canónica ↔ JSON-LD (M8)' => static fn() => assertJsonLdUrlsCanonical('/marcha/costalero-bueno-3', $base),

    // ── Escuchar: botonera única y pestañas por versión ─────────────────────
    // La marcha 1 es de 1995 (más de 25 años) y tiene enlaces de las dos
    // versiones en la fixture, así que su ficha debe partirlas en pestañas.
    'marcha antigua separa versión original y actual' => static function () use ($base): void {
        assertContains('/marcha/consuelo-gitano-1', 'Versión original', $base);
        assertContains('/marcha/consuelo-gitano-1', 'Versión actual', $base);
    },
    // El enlace de AUDIO es un botón más, no una miniatura de YouTube: la ficha
    // de marcha ya no incrusta reproductores de terceros.
    'marcha no incrusta reproductores' => static function () use ($base): void {
        $r = assertStatus('/marcha/consuelo-gitano-1', 200, $base);
        foreach (['ytembed', 'mdembed'] as $muerto) {
            if (str_contains($r['body'], $muerto)) {
                throw new RuntimeException("/marcha/consuelo-gitano-1 → sigue incrustando '$muerto'");
            }
        }
        if (!str_contains($r['body'], 'stream-youtube')) {
            throw new RuntimeException('/marcha/consuelo-gitano-1 → el enlace de AUDIO no sale como botón');
        }
    },
    // Sin año de composición no hay "época" que distinguir: botonera única.
    'marcha sin año no separa versiones' => static function () use ($base): void {
        $r = assertStatus('/marcha/reina-de-san-roman-5', 200, $base);
        if (str_contains($r['body'], 'Versión original')) {
            throw new RuntimeException('/marcha/reina-de-san-roman-5 → sin año de composición no debería haber pestañas de versión');
        }
        if (!str_contains($r['body'], 'stream-spotify')) {
            throw new RuntimeException('/marcha/reina-de-san-roman-5 → falta el botón de Spotify');
        }
    },

    // ── Autor / Banda / Disco / Dedicatoria ─────────────────────────────────
    'autor listado 200' => static fn() => assertStatus('/autor', 200, $base),
    'autor ficha 200 + JSON-LD Person' => static fn() => assertJsonLd('/autor/jose-garcia-perez-1', $base, 'Person'),
    'banda listado 200' => static fn() => assertStatus('/banda', 200, $base),
    'banda ficha 200 + JSON-LD MusicGroup' => static fn() => assertJsonLd('/banda/banda-de-cctt-ntra-sra-de-la-victoria-las-cigarreras-1', $base, 'MusicGroup'),
    'disco listado 200' => static fn() => assertStatus('/disco', 200, $base),
    'disco ficha 200 + JSON-LD MusicAlbum' => static fn() => assertJsonLd('/disco/sevilla-cofrade-vol-1-1', $base, 'MusicAlbum'),
    // Dedicatorias, estado del catálogo y mapa no se prueban aquí:
    // solo se publican en algunos entornos, así que van al bloque por modo del
    // final del fichero.

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

    // ── Panel: discos (rutas registradas y protegidas) ──────────────────────
    // Sin sesión no se puede probar el alta entera, pero sí que las rutas
    // existen (un 404 aquí significaría que routes.php no las registró) y que
    // el guard de autenticación está puesto.
    'panel: las rutas de disco existen y exigen sesión' => static function () use ($base): void {
        // 302 al login (no el 308/301 canónico que comprueba assertRedirect).
        foreach (['/dashboard/disco/add', '/dashboard/disco/1', '/dashboard/disco/1/importar'] as $ruta) {
            $r = httpGet($base . $ruta);
            if ($r['status'] !== 302) {
                throw new RuntimeException("$ruta → esperado 302 al login, obtenido {$r['status']}");
            }
            $loc = $r['headers']['location'] ?? '';
            if (!str_ends_with($loc, '/login')) {
                throw new RuntimeException("$ruta → redirige a '$loc' en vez de /login");
            }
        }
    },
    // Importación de pistas desde el enlace del álbum: los dos POST del flujo
    // (analizar y confirmar) tienen que existir y exigir sesión. Sin este caso,
    // una ruta mal registrada solo se notaría al usarla a mano en local.
    'panel: los POST del importador de pistas existen y exigen sesión' => static function () use ($base): void {
        foreach (['/dashboard/disco/1/importar', '/dashboard/disco/1/importar/confirmar'] as $ruta) {
            $r = httpPost($base . $ruta, ['url' => 'https://www.deezer.com/album/1']);
            if ($r['status'] !== 302) {
                throw new RuntimeException("POST $ruta → esperado 302 al login, obtenido {$r['status']}");
            }
            if (!str_ends_with($r['headers']['location'] ?? '', '/login')) {
                throw new RuntimeException("POST $ruta → no redirige al login");
            }
        }
    },
    'api: /api/marcha/fastSearch sin sesión → 401 JSON' => static function () use ($base): void {
        $r = assertStatus('/api/marcha/fastSearch?q=Consuelo', 401, $base);
        $j = json_decode($r['body'], true);
        if (!is_array($j) || ($j['code'] ?? '') !== 'AUTH_REQUIRED') {
            throw new RuntimeException('/api/marcha/fastSearch → esperado {"code":"AUTH_REQUIRED"}');
        }
    },

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
    // JPEG y no PNG: la caché private/og-cache pasó de 200 MB con PNG.
    'og marcha .jpg generada' => static function () use ($base): void {
        $r = httpGet($base . '/og/marcha/1.jpg');
        if ($r['status'] !== 200) {
            throw new RuntimeException('/og/marcha/1.jpg → esperado 200, obtenido ' . $r['status']
                . ' (¿GD/FreeType no cargado en el runner?)');
        }
        if (!str_contains($r['headers']['content-type'] ?? '', 'image/jpeg')) {
            throw new RuntimeException('/og/marcha/1.jpg → Content-Type no es image/jpeg');
        }
        if (substr($r['body'], 0, 3) !== "\xFF\xD8\xFF") {
            throw new RuntimeException('/og/marcha/1.jpg → el cuerpo no es un JPEG válido');
        }
    },
    'og de las 4 entidades 200 JPEG' => static function () use ($base): void {
        foreach (['autor/1', 'banda/1', 'disco/1'] as $ruta) {
            $r = httpGet($base . '/og/' . $ruta . '.jpg');
            if ($r['status'] !== 200 || substr($r['body'], 0, 3) !== "\xFF\xD8\xFF") {
                throw new RuntimeException("/og/$ruta.jpg → no devolvió un JPEG 200 (status {$r['status']})");
            }
        }
    },
    // Los enlaces compartidos antes del cambio apuntan a .png: deben seguir
    // mostrando la tarjeta, no un 404.
    'og URL .png antigua sigue sirviendo la tarjeta' => static function () use ($base): void {
        $r = httpGet($base . '/og/marcha/1.png');
        if ($r['status'] !== 200 || !str_contains($r['headers']['content-type'] ?? '', 'image/')) {
            throw new RuntimeException('/og/marcha/1.png → la URL antigua ya no sirve la tarjeta (status ' . $r['status'] . ')');
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
    'ficha de marcha referencia su og dinámica' => static fn() => assertContains('/marcha/consuelo-gitano-1', '/og/marcha/1.jpg', $base),

    // ── Compartir: datos de la tarjeta y botón ──────────────────────────────
    // Lo que se ve en WhatsApp al pegar el enlace. Una marcha a cuatro manos
    // debe acreditar a TODOS sus compositores (antes salía solo el primero).
    'compartir: marcha acredita a todos sus compositores y el año' => static fn() => assertContains('/marcha/costalero-bueno-3',
        'content="Marcha procesional compuesta por Manuel López Ruiz y Rafael O&#039;Donnell (1995)"', $base),
    // La banda se presenta por sus estrenos, no solo por la localidad.
    'compartir: banda lleva localidad y estrenos' => static fn() => assertContains('/banda/banda-de-cctt-ntra-sra-de-la-victoria-las-cigarreras-1',
        'content="Banda de música procesional de Sevilla · 3 estrenos"', $base),
    'compartir: disco lleva banda y año' => static fn() => assertContains('/disco/sevilla-cofrade-vol-1-1',
        'content="Álbum de música procesional de Las Cigarreras (Sevilla), 1996"', $base),
    // Un disco con portada se comparte CON su portada: es lo primero que se
    // reconoce de un disco. Se pinta una portada roja de prueba y se mira un
    // píxel de la zona izquierda de la tarjeta: sin portada sería el fondo
    // índigo. El servidor de esta pasada es local y comparte public/cover/.
    'compartir: tarjeta de disco con portada la muestra' => static function () use ($base): void {
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('el runner necesita GD con WebP para esta prueba');
        }
        $dir = dirname(__DIR__) . '/public/cover';
        $f = $dir . '/1.webp';
        if (is_file($f)) {
            throw new RuntimeException("$f ya existe: esta prueba no pisa portadas reales");
        }
        // La ficha anuncia la tarjeta con una ruta versionada (?v=): si no
        // cambiara al cambiar la imagen, WhatsApp y los navegadores seguirían
        // enseñando la vieja durante los 7 días de caché.
        $ogDe = static function () use ($base): string {
            $html = assertStatus('/disco/sevilla-cofrade-vol-1-1', 200, $base)['body'];
            return preg_match('/property="og:image" content="([^"]*)"/', $html, $mm) === 1 ? $mm[1] : '';
        };
        $antes = $ogDe();
        if (!str_contains($antes, '/og/disco/1.jpg?v=')) {
            throw new RuntimeException("ficha de disco → og:image sin versión: '$antes'");
        }
        @mkdir($dir, 0775, true);
        $img = imagecreatetruecolor(300, 300);
        imagefilledrectangle($img, 0, 0, 300, 300, imagecolorallocate($img, 220, 20, 20));
        imagewebp($img, $f);
        try {
            if ($ogDe() === $antes) {
                throw new RuntimeException('ficha de disco → la ruta de la og:image no cambia al añadir la portada');
            }
            $r = httpGet($base . '/og/disco/1.jpg');
            $card = $r['status'] === 200 ? @imagecreatefromstring($r['body']) : false;
            if ($card === false) {
                throw new RuntimeException('/og/disco/1.jpg → no devolvió una imagen (status ' . $r['status'] . ')');
            }
            $rgb = imagecolorat($card, 300, 315);
            if ((($rgb >> 16) & 0xFF) < 180 || (($rgb >> 8) & 0xFF) > 80) {
                throw new RuntimeException('/og/disco/1.jpg → la portada no aparece a la izquierda de la tarjeta');
            }
        } finally {
            @unlink($f);
        }
    },
    // El botón (catalog.js) solo se monta donde <main> lo permite: en fichas y
    // listados sí; en portada, búsqueda y errores no hay nada útil que compartir.
    'compartir: botón en fichas y listados, no en portada/búsqueda/404' => static function () use ($base): void {
        foreach (['/marcha/costalero-bueno-3', '/marcha', '/contacto'] as $ruta) {
            assertContains($ruta, '<main id="main-content" data-compartir>', $base);
        }
        foreach (['/', '/buscar?q=garcia', '/no-existe-esta-ruta'] as $ruta) {
            if (str_contains(httpGet($base . $ruta)['body'], 'data-compartir')) {
                throw new RuntimeException("$ruta → no debería ofrecer el botón de compartir");
            }
        }
    },
];

// ── Secciones (App\Secciones) ──────────────────────────────────────────────
// Se confirma el entorno que simula el servidor: si una sección vuelve a
// EN_MADURACION, las pruebas por entorno dependen de que esto sea cierto.
$tests['health declara el entorno esperado por esta pasada'] = static function () use ($base, $modo): void {
    $esperado = 'entorno: ' . ($modo === 'local' ? 'local' : 'prod');
    $r = assertStatus('/health', 200, $base);
    if (!str_contains($r['body'], $esperado)) {
        throw new RuntimeException("/health → esta pasada es '$modo' y esperaba '$esperado' (¿config.local.php de más o de menos?)");
    }
};

/** Cada sección con sus rutas: la del índice (la que anuncian nav/sitemap/llms) y las de dentro. */
$secciones = [
    'dedicatorias'    => ['indice' => '/dedicatorias',    'internas' => ['/dedicatoria/hdad-de-los-gitanos-sevilla-1']],
    'estado-catalogo' => ['indice' => '/estado-catalogo', 'internas' => []],
];

{
    // Publicadas en todos los entornos desde el 2026-10-01 (EN_MADURACION
    // vacía): las dos pasadas comprueban lo mismo.
    $tests['sección publicada: todas responden 200'] = static function () use ($base, $secciones): void {
        foreach ($secciones as $s) {
            $rutas = array_merge([$s['indice']], $s['internas']);
            foreach ($rutas as $ruta) {
                assertStatus($ruta, 200, $base);
            }
        }
    };
    $tests['sección publicada: nav, sitemap y llms.txt las anuncian'] = static function () use ($base): void {
        $home = assertStatus('/', 200, $base)['body'];
        foreach (['/dedicatorias'] as $indice) { // estado-catalogo no está en el nav
            if (!str_contains($home, 'href="' . $indice . '"')) {
                throw new RuntimeException("home → falta el enlace del nav a $indice");
            }
        }
        $sitemap = sitemapPaths($base);
        $llms = llmsPaths($base);
        foreach (['/dedicatorias', '/estado-catalogo'] as $indice) {
            if (!in_array($indice, $sitemap, true)) {
                throw new RuntimeException("sitemap.xml → falta $indice");
            }
            if (!in_array($indice, $llms, true)) {
                throw new RuntimeException("llms.txt → falta $indice");
            }
        }
        // Las fichas de dedicatoria con sustancia van al sitemap con su índice.
        if (!anyUnder($sitemap, '/dedicatoria')) {
            throw new RuntimeException('sitemap.xml → faltan las fichas de dedicatoria');
        }
        if (!in_array('/acompanamientos', $sitemap, true)) {
            throw new RuntimeException('sitemap.xml → falta /acompanamientos');
        }
    };
    $tests['dedicatorias: ficha con JSON-LD CollectionPage'] = static fn() => assertJsonLd('/dedicatoria/hdad-de-los-gitanos-sevilla-1', $base, 'CollectionPage');
    $tests['dedicatorias: ficha inexistente 404'] = static fn() => assertStatus('/dedicatoria/nada-999999', 404, $base);
    $tests['estado-catalogo: indexable'] = static fn() => assertNotNoIndex('/estado-catalogo', $base);
    $tests['estado-catalogo: rankings enlaza a él'] = static fn() => assertContains('/rankings', 'href="/estado-catalogo"', $base);
    // /mapa se retiró el 2026-10-01 (fuera de alcance a corto/medio plazo):
    // si una ruta o un enlace vuelve a colarse sin querer, falla aquí.
    $tests['mapa retirado: 404 y fuera de nav y sitemap'] = static function () use ($base): void {
        assertStatus('/mapa', 404, $base);
        assertStatus('/mapa/provincia/sevilla', 404, $base);
        if (str_contains(assertStatus('/', 200, $base)['body'], 'href="/mapa"')) {
            throw new RuntimeException('home → el nav sigue enlazando /mapa');
        }
        if (anyUnder(sitemapPaths($base), '/mapa')) {
            throw new RuntimeException('sitemap.xml → sigue listando /mapa');
        }
    };
}

// Acompañamientos ya está publicada en todos los entornos: se prueba igual en
// las dos pasadas.
$tests['acompanamientos: el nav la enlaza'] = static fn() => assertContains('/', 'href="/acompanamientos"', $base);
$tests['acompanamientos: índice enlaza a la localidad de la fixture'] = static fn() => assertContains('/acompanamientos', 'href="/acompanamientos/sevilla"', $base);
$tests['acompanamientos: la localidad agrupa por hermandad'] = static fn() => assertContains('/acompanamientos/sevilla', 'Hdad de los Gitanos', $base);
$tests['acompanamientos: localidad inexistente 404'] = static fn() => assertStatus('/acompanamientos/no-existe', 404, $base);
// La ficha de banda lista sus acompañamientos y lleva a la localidad donde se
// ven en contexto; si la sección desaparece o pierde el enlace, falla.
$tests['acompanamientos: la ficha de banda los lista y enlaza a la localidad'] = static function () use ($base): void {
    assertContains('/banda/banda-de-cctt-ntra-sra-de-la-victoria-las-cigarreras-1', 'id="acompanamientos"', $base);
    assertContains('/banda/banda-de-cctt-ntra-sra-de-la-victoria-las-cigarreras-1', 'href="/acompanamientos/sevilla"', $base);
};

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
