<?php

declare(strict_types=1);

use App\Db;
use App\Seo;
use App\View;

/** @var string $content              cuerpo ya renderizado de la vista */
/** @var array<string,mixed> $meta    title, description, noindex, og, jsonld */

$title = (string) ($meta['title'] ?? 'Marchas de Cristo');
$description = $meta['description'] ?? null;
$esPre = !empty($GLOBALS['config']['preproduccion']);
$noindex = !empty($meta['noindex']) || $esPre; // en PRE, noindex SIEMPRE
$canonical = $meta['canonical'] ?? null;
$jsonld = $meta['jsonld'] ?? [];

// og:* / twitter:*: base de marca en TODAS las páginas (antes solo existían en
// las fichas de detalle, que pasan $meta['og']); esos valores, si están,
// sustituyen a los genéricos. og:image es la imagen de marca por defecto; las
// fichas de detalle pasan $meta['og']['image'] con su tarjeta dinámica (M4).
$og = array_merge(
    ['type' => 'website', 'title' => $title, 'description' => $description, 'url' => $canonical],
    $meta['og'] ?? []
);
$siteBase = rtrim((string) ($GLOBALS['config']['site_url'] ?? 'https://marchasdecristo.com'), '/');
$ogImage = !empty($og['image']) ? $og['image'] : $siteBase . '/assets/og-image.png';
$ogImageAlt = $og['imageAlt'] ?? 'Marchas de Cristo — base de datos de música procesional';

$siteName = 'Marchas de Cristo';
// app.css/catalog.js se sirven con Cache-Control de 30 días (.htaccess) sin
// nombre de fichero versionado; sin este parámetro, un cambio de CSS/JS no
// llegaría a un navegador que ya los tuviera cacheados hasta que expirase esa
// caché por su cuenta. filemtime cambia solo cuando el fichero cambia de
// verdad, así que no invalida la caché en cada deploy si el asset no se tocó.
$assetVer = static fn(string $rel): string => $rel . '?v=' . (@filemtime(PUBLIC_DIR . $rel) ?: '1');
$nav = [
    '/marcha' => 'Marchas',
    '/autor' => 'Compositores',
    '/banda' => 'Bandas',
    '/disco' => 'Discos',
    '/dedicatorias' => 'Dedicatorias',
    '/rankings' => 'Estadísticas',
    '/aniversarios' => 'Aniversarios',
];
$configEnv = (string) ($GLOBALS['config']['env'] ?? 'production');
$fueraDeProdReal = $configEnv !== 'production' || $esPre;
// Mapa (N-10) oculto en PRO mientras se corrige el solape de dianas de clic
// entre municipios próximos (decisión 2026-07-29). Ver App\Pages::mapaVisible().
if ($fueraDeProdReal) {
    $nav['/mapa'] = 'Mapa';
}
// Temporada (N-04) oculta en PRO hasta que haya datos de calidad (decisión
// 2026-07-29): visible en local (donde se rellena) y en PRE (para validarla
// antes de publicar). Ver App\Pages::temporadaVisible().
if ($fueraDeProdReal) {
    $nav['/temporada'] = 'Temporada';
}

try {
    $counts = Db::counts();
} catch (\Throwable) {
    $counts = null;
}
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Sección activa para aria-current (primer segmento de la ruta, sin query string).
$reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$current = '/' . (explode('/', trim($reqPath, '/'))[0] ?? '');

// Buscador global de cabecera (M3): una sola caja en TODAS las páginas que
// busca a la vez en las cuatro entidades (destino /buscar), con desplegable de
// autocompletado en vivo (catalog.js → /api/buscar). Los listados conservan su
// "Búsqueda avanzada" con facetas para filtrar dentro de un tipo. No se muestra
// dentro del panel de administración.
$showSearch = !str_starts_with($reqPath, '/dashboard') && !str_starts_with($reqPath, '/login');
$searchValue = $current === '/buscar' ? (string) ($_GET['q'] ?? '') : '';
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
<?php if ($description !== null): ?>
    <meta name="description" content="<?= $e($description) ?>">
<?php endif; ?>
<?php if ($noindex): ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
<?php if ($canonical !== null): ?>
    <link rel="canonical" href="<?= $e($canonical) ?>">
<?php endif; ?>
    <meta property="og:site_name" content="<?= $e($siteName) ?>">
    <meta property="og:type" content="<?= $e($og['type']) ?>">
    <meta property="og:title" content="<?= $e($og['title']) ?>">
<?php if (!empty($og['description'])): ?>
    <meta property="og:description" content="<?= $e($og['description']) ?>">
<?php endif; ?>
<?php if (!empty($og['url'])): ?>
    <meta property="og:url" content="<?= $e($og['url']) ?>">
<?php endif; ?>
    <meta property="og:image" content="<?= $e($ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="<?= $e($ogImageAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@JaviWarSVQ">
    <meta name="twitter:title" content="<?= $e($og['title']) ?>">
<?php if (!empty($og['description'])): ?>
    <meta name="twitter:description" content="<?= $e($og['description']) ?>">
<?php endif; ?>
    <meta name="twitter:image" content="<?= $e($ogImage) ?>">
    <meta name="twitter:image:alt" content="<?= $e($ogImageAlt) ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($assetVer('/assets/app.css')) ?>">
    <link rel="alternate" type="application/rss+xml" title="Marchas de Cristo — últimas incorporaciones" href="/feed.xml">
    <link rel="alternate" type="application/feed+json" title="Marchas de Cristo — últimas incorporaciones" href="/feed.json">
<?php foreach ($jsonld as $schema): ?>
    <script type="application/ld+json"><?= Seo::json($schema) ?></script>
<?php endforeach; ?>
</head>
<body>
<?php if ($esPre): ?>
    <div class="pre-ribbon" role="status">Entorno de preproducción — los cambios aquí no afectan a marchasdecristo.com</div>
<?php endif; ?>
    <header>
        <nav>
            <a class="brand" href="/"><?= $siteName ?><span class="brand-sub">Base de datos de música procesional</span></a>
            <ul class="nav-links">
<?php foreach ($nav as $href => $label): ?>
                <li><a href="<?= $href ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>><?= $label ?></a></li>
<?php endforeach; ?>
            </ul>
            <details class="nav-mobile">
                <summary aria-label="Menú">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </summary>
                <ul>
                    <li><a href="/">Inicio</a></li>
<?php foreach ($nav as $href => $label): ?>
                    <li><a href="<?= $href ?>"><?= $label ?></a></li>
<?php endforeach; ?>
                </ul>
            </details>
        </nav>
<?php if ($showSearch): ?>
        <div class="site-search-row">
            <form class="site-search" action="/buscar" method="get" role="search" autocomplete="off">
                <span aria-hidden="true">⌕</span>
                <input id="site-q" type="search" name="q" value="<?= $e($searchValue) ?>"
                       placeholder="Buscar marchas, compositores, bandas, discos…"
                       aria-label="Buscar en el catálogo"
                       role="combobox" aria-expanded="false" aria-controls="site-ac"
                       aria-autocomplete="list" autocomplete="off">
                <span class="kbd">/</span>
                <div id="site-ac" class="ac-panel" role="listbox" aria-label="Sugerencias" hidden></div>
            </form>
        </div>
<?php endif; ?>
    </header>

    <main><?= $content ?></main>

    <footer>
        <div class="inner">
<?php if ($counts !== null): ?>
            <?= $counts['MARCHAS'] ?> marchas · <?= $counts['AUTORES'] ?> compositores · <?= $counts['BANDAS'] ?> bandas · <?= $counts['DISCOS'] ?> discos
<?php else: ?>
            <?= $siteName ?>
<?php endif; ?>
            <span class="foot-sep">·</span> <a href="/datos">Datos y licencia (CC BY 4.0)</a>
        </div>
    </footer>
    <script src="<?= $e($assetVer('/assets/catalog.js')) ?>" defer></script>
<?php if (!empty($GLOBALS['config']['goatcounter_code'])): ?>
    <script data-goatcounter="https://<?= $e($GLOBALS['config']['goatcounter_code']) ?>.goatcounter.com/count"
            async src="//gc.zgo.at/count.js"></script>
<?php endif; ?>
</body>
</html>
