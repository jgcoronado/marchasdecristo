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
    '/acompanamientos' => 'Acompañamientos',
    '/dedicatorias' => 'Dedicatorias',
    '/rankings' => 'Rankings',
    '/aniversarios' => 'Aniversarios',
    '/contacto' => 'Contacto',
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

// Panel en solo lectura: el admin en PRE y PRO. La BD maestra es la local, así
// que ahí no guarda ni propone nada (Auth::soloLectura; sus envíos los corta
// Auth::requireAuth). Como las pantallas son las mismas que en local, se avisa
// con la cinta y se desactivan sus formularios de envío para que ni lo intente.
// El editor no entra aquí: sus envíos SIEMPRE son propuestas, aquí y en local.
// Ver docs/entornos.md.
$soloLectura = false;
if (str_starts_with($reqPath, '/dashboard') && !Entorno::permiteEscrituraDirecta()) {
    $sesionPanel = Auth::currentSession();
    $soloLectura = $sesionPanel !== null
        && Auth::soloLectura(['rol' => Auth::roleOf((string) ($sesionPanel['user'] ?? ''))]);
}

// Propuestas del usuario sobre ESTA pantalla que aún no se han aplicado: la
// pantalla sigue enseñando lo de antes y, sin aviso, quien propone tiende a
// repetir la petición. Solo para quien trabaja en modo propuesta (el editor),
// ver Admin::proposalMode() y PropuestaRepo::pendientesEn().
$pendientesAqui = 0;
if (str_starts_with($reqPath, '/dashboard/')) {
    $sesionPanel ??= Auth::currentSession();
    $usuarioPanel = (string) ($sesionPanel['user'] ?? '');
    if ($usuarioPanel !== '' && App\Admin::proposalMode(['rol' => Auth::roleOf($usuarioPanel)])) {
        try {
            $pendientesAqui = App\PropuestaRepo::pendientesEn($usuarioPanel, rtrim($reqPath, '/'));
        } catch (\Throwable) {
            $pendientesAqui = 0; // el aviso es una ayuda: nunca tumba la página
        }
    }
}

$searchValue = $current === '/buscar' ? (string) ($_GET['q'] ?? '') : '';

// Botón de compartir junto al <h1> (lo inserta catalog.js; sin JS no hay
// nada que compartir, así que tampoco botón). Fuera: portada, resultados de
// búsqueda, panel/login y las páginas de error o mantenimiento (pasan
// $meta['compartir'] = false desde App\Http).
$compartir = ($meta['compartir'] ?? true) !== false
    && $showSearch && $reqPath !== '/' && $current !== '/buscar';

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
// /acompanamientos salió el 26-09-2026 (índice y localidades): son listas de
// una columna (hermandad + paso) y a 76rem dejaban franjas en blanco a los
// lados de cada fila; a --wrap la fila llena la tarjeta.
$rutasCatalogo = ['marcha', 'banda', 'disco', 'dedicatorias', 'aniversarios', 'buscar', 'estado-catalogo'];
$fichasEntidad = ['marcha', 'autor', 'banda', 'disco'];
$segs = array_values(array_filter(explode('/', trim($reqPath, '/')), static fn(string $x): bool => $x !== ''));
$esCatalogo = $segs !== []
    && in_array($segs[0], $rutasCatalogo, true)
    && !(count($segs) === 2 && in_array($segs[0], $fichasEntidad, true));
// La ficha de autor va a ancho de catálogo (05-10-2026): su tabla de obra lleva
// cinco columnas (marcha, año, banda y dedicatoria con localidad, grabaciones)
// y a --wrap se partían en varias líneas. Las demás fichas siguen estrechas.
$esCatalogo = $esCatalogo || (count($segs) === 2 && $segs[0] === 'autor');

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
             son --bg de cada tema (BLOQUE 1 de app.css y BLOQUE 1-bis de
             dark.css): si cambian allí, hay que cambiarlos aquí y en COLOR del
             script de tema de más abajo, que son los únicos sitios del
             proyecto donde un color de la paleta se repite fuera de las hojas.
             El oscuro es --bg (#141413), que desde 2026-09-27 es también el
             fondo de la cabecera. */ ?>
    <meta name="theme-color" content="#f4f5f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#141413" media="(prefers-color-scheme: dark)">
    <title><?= $e($title) ?></title>
<?php if ($description !== null): ?>
    <meta name="description" content="<?= $e($description) ?>">
<?php endif; ?>
<?php if ($noindex): ?>
    <meta name="robots" content="noindex, follow">
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
    <meta property="og:image:type" content="<?= str_ends_with((string) parse_url($ogImage, PHP_URL_PATH), '.jpg') ? 'image/jpeg' : 'image/png' ?>">
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
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="<?= $e($assetVer('/assets/app.css')) ?>">
    <?php /* Tema oscuro: una hoja aparte que se enciende o apaga con su
             atributo media (ver la cabecera de dark.css). Por defecto sigue al
             sistema; el script siguiente la fuerza si el visitante eligió un
             tema con el botón. */ ?>
    <link rel="stylesheet" id="css-oscuro" href="<?= $e($assetVer('/assets/dark.css')) ?>" media="screen and (prefers-color-scheme: dark)">
    <?php /* Tema elegido con el botón (.tema-toggle). En línea y antes de
             <body> para que la página no salga un instante con el tema del
             sistema y luego cambie. La elección se guarda en localStorage
             ('tema' = 'claro' | 'oscuro'), no en una cookie: no viaja al
             servidor y el HTML es el mismo para todos, así que la caché
             pública de las páginas (Http::cachePublic) no se ve afectada. Si
             la elección coincide con el tema del sistema se borra, y la página
             vuelve a seguir al sistema. Sin localStorage (navegación privada
             estricta) el botón funciona igual, solo que no se recuerda. */ ?>
    <script>
    (function () {
        var SISTEMA = 'screen and (prefers-color-scheme: dark)';
        var COLOR = { claro: '#f4f5f8', oscuro: '#141413' };
        var raiz = document.documentElement;
        var hoja = document.getElementById('css-oscuro');
        var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        function guardado() {
            try { var t = localStorage.getItem('tema'); return t === 'claro' || t === 'oscuro' ? t : null; } catch (e) { return null; }
        }
        function sistema() { return mq && mq.matches ? 'oscuro' : 'claro'; }
        var elegido = guardado();
        function aplicar() {
            hoja.media = elegido === 'oscuro' ? 'screen' : elegido === 'claro' ? 'not all' : SISTEMA;
            if (elegido) { raiz.setAttribute('data-tema', elegido); } else { raiz.removeAttribute('data-tema'); }
            var metas = document.querySelectorAll('meta[name="theme-color"]');
            for (var i = 0; i < metas.length; i++) {
                metas[i].content = COLOR[elegido || (metas[i].media.indexOf('dark') >= 0 ? 'oscuro' : 'claro')];
            }
            var boton = document.querySelector('.tema-toggle');
            if (boton) { boton.setAttribute('aria-pressed', (elegido || sistema()) === 'oscuro' ? 'true' : 'false'); }
        }
        aplicar();
        document.addEventListener('DOMContentLoaded', aplicar);
        document.addEventListener('click', function (ev) {
            if (!ev.target.closest || !ev.target.closest('.tema-toggle')) { return; }
            var nuevo = (elegido || sistema()) === 'oscuro' ? 'claro' : 'oscuro';
            elegido = nuevo === sistema() ? null : nuevo;
            try { if (elegido) { localStorage.setItem('tema', elegido); } else { localStorage.removeItem('tema'); } } catch (e) {}
            aplicar();
        });
        window.addEventListener('storage', function (ev) {
            if (ev.key === 'tema') { elegido = guardado(); aplicar(); }
        });
        if (mq && mq.addEventListener) { mq.addEventListener('change', aplicar); }
    })();
    </script>
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
<?php if ($soloLectura): ?>
    <div class="danger-ribbon" role="alert">SOLO LECTURA: en este entorno el panel no modifica nada. Los cambios se hacen en local.</div>
<?php endif; ?>
    <header>
        <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="/"><?= $siteName ?><span class="brand-sub">Base de datos de música procesional</span></a>
<?php if ($showSearch): ?>
            <div class="site-search-row">
                <form class="site-search" action="/buscar" method="get" role="search" aria-label="Buscar en todo el sitio" autocomplete="off">
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
            <button type="button" class="tema-toggle" aria-pressed="false" aria-label="Modo oscuro" title="Cambiar entre modo claro y oscuro">
                <svg class="tema-luna" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                <svg class="tema-sol" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </button>
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
        <nav aria-label="Secciones del sitio">
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
    <main id="main-content"<?= $compartir ? ' data-compartir' : '' ?><?= $soloLectura ? ' data-solo-lectura' : '' ?>><?php if ($pendientesAqui > 0): ?>
        <div class="alert alert-info" role="status" style="margin-bottom:1rem"><?= $pendientesAqui === 1
                ? 'Tienes 1 propuesta pendiente sobre esta pantalla: hasta que se acepte no verás aquí ese cambio, no hace falta repetirlo.'
                : 'Tienes ' . $pendientesAqui . ' propuestas pendientes sobre esta pantalla: hasta que se acepten no verás aquí esos cambios, no hace falta repetirlos.' ?>
            <a href="/dashboard/mis-propuestas">Ver mis propuestas</a></div>
<?php endif; ?><?= $content ?></main>
<?php if ($soloLectura): ?>
    <?php /* Solo lectura (ver $soloLectura arriba): los datos se siguen viendo en
             sus campos, pero desactivados y sin botón de enviar. Es solo una
             ayuda — la barrera real es Auth::requireAuth, que rechaza el POST.
             El cierre de sesión es el único envío que sigue valiendo. */ ?>
    <style>
        main[data-solo-lectura] form[method="post" i]:not([action="/logout"]) :is(button, input[type="submit"]) { display: none !important; }
    </style>
    <script>
        document.querySelectorAll('main[data-solo-lectura] form[method="post" i]:not([action="/logout"])').forEach(function (f) {
            f.querySelectorAll('input, select, textarea, button').forEach(function (el) { el.disabled = true; });
            f.addEventListener('submit', function (e) { e.preventDefault(); });
        });
    </script>
<?php endif; ?>

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
