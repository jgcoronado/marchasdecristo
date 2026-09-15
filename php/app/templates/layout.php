<?php

declare(strict_types=1);

use App\Auth;
use App\Db;
use App\Entorno;
use App\Roles;
use App\Secciones;
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
// Orden definitivo del nav, publicadas o no: una sección que aún no se enseña
// en este entorno se cae del menú abajo, sin perder su sitio para cuando se
// publique (ver App\Secciones — misma decisión que apaga su ruta, el sitemap y
// llms.txt).
$nav = [
    '/marcha' => 'Marchas',
    '/autor' => 'Compositores',
    '/banda' => 'Bandas',
    '/disco' => 'Discos',
    '/dedicatorias' => 'Dedicatorias',
    '/rankings' => 'Estadísticas',
    '/aniversarios' => 'Aniversarios',
    '/mapa' => 'Mapa',
    '/acompanamientos' => 'Acompañamientos',
];
$nav = array_filter(
    $nav,
    static fn(string $href): bool => Secciones::visible(ltrim($href, '/')),
    ARRAY_FILTER_USE_KEY
);

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

// Aviso de desincronización en el panel de PRE y PRO. La BD maestra es la
// local: lo que se toque aquí o se pierde en el próximo sync_db_to_prod.php o
// pisa datos buenos. El editor no lo necesita (sus envíos SIEMPRE son
// propuestas, aquí y en local); el admin sí, porque en local está acostumbrado
// a escribir directo y estas pantallas son las mismas. Ver docs/entornos.md.
$avisoDesync = false;
if (str_starts_with($reqPath, '/dashboard') && !Entorno::permiteEscrituraDirecta()) {
    $sesionPanel = Auth::currentSession();
    $avisoDesync = $sesionPanel !== null
        && Roles::isAdmin(Auth::roleOf((string) ($sesionPanel['user'] ?? '')));
}

$searchValue = $current === '/buscar' ? (string) ($_GET['q'] ?? '') : '';

// Ancho de la página. Dos anchos, no uno (ver --wrap / --wrap-ancho en
// app.css): las pantallas de catálogo son tabla + facetas y a 54rem se
// recortan, mientras que las fichas y la prosa se leen mejor estrechas. La
// cabecera y el pie van siempre a --wrap-ancho, así que el menú no cambia de
// sitio al pasar de un listado a una ficha. $meta['ancho'] (panel) manda sobre
// esto.
//
// Un mismo primer segmento sirve a las dos cosas: /marcha es el explorador
// (ancho) y /marcha/{slug-id} es la ficha (estrecho). Los hubs de tres
// segmentos (/marcha/ano/2024, /marcha/estilo/…, /marcha/provincia/…) vuelven
// a ser listados, así que solo se exceptuan las fichas de entidad, que son
// exactamente las de dos segmentos.
// /autor y /rankings salieron de esta lista el 15-09-2026: no son exploradores
// de facetas sino listas de dos columnas. A 76rem la tabla de /rankings medía
// 1.093px y el número quedaba a más de 800 del nombre al que pertenece; a
// --wrap la tabla llena el panel y el dato vuelve al lado de su fila.
$rutasCatalogo = ['marcha', 'banda', 'disco', 'dedicatorias', 'aniversarios', 'acompanamientos', 'buscar', 'mapa', 'estado-catalogo'];
$fichasEntidad = ['marcha', 'autor', 'banda', 'disco'];
// Secciones cuyo índice (un solo segmento) no es un catálogo sino una portada
// de lectura: /acompanamientos es un párrafo y siete enlaces, y a 76rem se
// quedaba en una tarjeta de 1.216px con una lista de siete líneas dentro. Las
// localidades (/acompanamientos/sevilla) sí son tabla y siguen anchas.
$indicesLectura = ['acompanamientos'];
$segs = array_values(array_filter(explode('/', trim($reqPath, '/')), static fn(string $x): bool => $x !== ''));
$esCatalogo = $segs !== []
    && in_array($segs[0], $rutasCatalogo, true)
    && !(count($segs) === 2 && in_array($segs[0], $fichasEntidad, true))
    && !(count($segs) === 1 && in_array($segs[0], $indicesLectura, true));

// La clase va en <body>, no en <main>: la cabecera y el pie tienen que
// estrecharse y ensancharse con el contenido, o la marca y el menú dejan de
// caer sobre el borde de la primera tarjeta y se nota a simple vista.
$bodyClass = '';
if (!empty($meta['ancho'])) {
    $bodyClass = ' class="p-ancho"';
} elseif ($esCatalogo) {
    $bodyClass = ' class="p-catalogo"';
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* La barra del navegador en móvil acompaña al tema. Los dos valores
             son --bg de cada tema en app.css (BLOQUE 1 y BLOQUE 1-bis): si
             cambian allí, hay que cambiarlos aquí, que es el único sitio del
             proyecto donde un color de la paleta se repite fuera de la hoja. */ ?>
    <meta name="theme-color" content="#f4f5f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#12151c" media="(prefers-color-scheme: dark)">
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
<body<?= $bodyClass ?>>
    <a class="skip-link" href="#main-content">Saltar al contenido</a>
<?php if ($esPre): ?>
    <div class="pre-ribbon" role="status">Entorno de preproducción — los cambios aquí no afectan a marchasdecristo.com</div>
<?php endif; ?>
<?php if ($avisoDesync): ?>
    <div class="danger-ribbon" role="alert">PELIGRO: riesgo de desincronización. No actuar en este entorno salvo urgencia.</div>
<?php endif; ?>
    <header>
        <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="/"><?= $siteName ?><span class="brand-sub">Base de datos de música procesional</span></a>
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
        </div>
        <nav>
            <ul class="nav-links">
<?php foreach ($nav as $href => $label): ?>
                <li><a href="<?= $href ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>><?= $label ?></a></li>
<?php endforeach; ?>
            </ul>
        </nav>
        </div>
    </header>

    <?php /* $meta['ancho'] ensancha el contenido para las pantallas del panel
             que son tablas de trabajo, no lectura: con el ancho de lectura
             (--wrap, 54rem) sus columnas se recortan. Ver .main-ancho.
             .main-catalogo hace lo propio, más contenido, con los listados
             públicos: ver RUTAS_CATALOGO arriba. */ ?>
    <main id="main-content"><?= $content ?></main>

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
