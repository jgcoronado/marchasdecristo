<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Controladores de las páginas públicas. Ports de los Server Components (app/.../page.tsx).
 */
final class Pages
{
    private static function base(): string
    {
        return rtrim((string) ($GLOBALS['config']['site_url'] ?? 'https://marchasdecristo.com'), '/');
    }

    // Decisión 2026-07-29: /temporada se oculta en PRO mientras `contrato` no
    // tenga datos de calidad suficiente (alta manual, aún vacía en prod), pero
    // se mantiene visible en local (donde se rellena) y en PRE (para
    // validarla visualmente antes de decidir publicarla). "Publicarla" es
    // quitar este gate, no un flag de config nuevo — no hay nada que
    // configurar por host aparte de lo que ya distingue PRE de PRO.
    private static function temporadaVisible(): bool
    {
        $config = $GLOBALS['config'];
        return ($config['env'] ?? 'production') !== 'production' || !empty($config['preproduccion']);
    }

    /** @return array{0:array<string,string>,1:bool,2:int,3:int} [criteria, hasQuery, page, limit] */
    private static function searchParams(): array
    {
        $flat = $_GET;
        unset($flat['page'], $flat['limit']);
        $criteria = [];
        $hasQuery = false;
        foreach ($flat as $k => $v) {
            if (!is_string($v)) continue;
            $criteria[$k] = $v;
            if (trim($v) !== '') $hasQuery = true;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limitIn = (int) ($_GET['limit'] ?? 20);
        $limit = in_array($limitIn, [10, 20, 50], true) ? $limitIn : 20;
        return [$criteria, $hasQuery, $page, $limit];
    }

    // ── Home ──────────────────────────────────────────────────────────────────
    public static function home(): void
    {
        Http::cachePublic(1800);
        $minHub = static fn(array $r): bool => (int) $r['N'] >= Repo::HUB_MIN_MARCHAS;
        $hubAniosVivos = array_values(array_filter(Repo::hubAnios(), $minHub));
        $hubEstilosVivos = array_values(array_filter(Repo::hubEstilos(), $minHub));
        $hubProvinciasVivas = array_values(array_filter(Repo::hubProvincias(), $minHub));
        $marchaDelDia = self::marchaDelDia();

        View::render('home', [
            'ultimas' => Repo::fetchUltimas(),
            'estado' => Repo::fetchEstado(),
            'marchaDelDia' => $marchaDelDia,
            'sugerencias' => self::homeSugerencias(
                $marchaDelDia,
                $hubEstilosVivos,
                // Más recientes primero: hubAnios() viene ordenado ASC por año.
                array_slice(array_reverse($hubAniosVivos), 0, 6),
                $hubProvinciasVivas
            ),
        ], [
            'title' => 'Marchas de Cristo — Música procesional',
            'description' => 'Descubre marchas procesionales, compositores, bandas y discos de música de Semana Santa.',
        ]);
    }

    /**
     * Selección determinista por fecha (UTC): el mismo día del calendario
     * siempre da la misma marcha, sin trabajo editorial ni estado que
     * mantener. Cambia de un día para otro porque cambia el "seed", no por
     * ningún cron ni tabla de programación.
     */
    private static function marchaDelDia(): ?array
    {
        $ids = Repo::marchaDelDiaCandidatos();
        if ($ids === []) {
            return null;
        }
        $seed = (int) gmdate('Ymd');
        $id = (string) $ids[$seed % count($ids)];
        return Repo::fetchMarcha($id);
    }

    /**
     * Hasta 8 accesos de descubrimiento para la columna estrecha de la home.
     * Primero los tres directamente relacionados con la marcha del día: el
     * año de la composición (hub), su compositor y su banda de estreno
     * (fichas directas, no hubs). Completa con enlaces generales de
     * catálogo (estilo/año/provincia/dedicatorias), sin repetir href.
     *
     * @param array<string,mixed>|null $mdd
     * @param list<array{K:string,N:int}> $hubEstilos
     * @param list<array{K:int,N:int}> $hubAniosRecientes
     * @param list<array{K:string,N:int}> $hubProvincias
     * @return list<array{href:string,label:string,cnt:?int,note:?string}>
     */
    private static function homeSugerencias(?array $mdd, array $hubEstilos, array $hubAniosRecientes, array $hubProvincias): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $href, string $label, ?int $cnt, ?string $note = null) use (&$out, &$seen): void {
            if (isset($seen[$href]) || count($out) >= 8) return;
            $seen[$href] = true;
            $out[] = ['href' => $href, 'label' => $label, 'cnt' => $cnt, 'note' => $note];
        };

        if ($mdd !== null) {
            $anio = (string) ($mdd['FECHA'] ?? '');
            if (preg_match('/^\d{4}$/', $anio) === 1) {
                $add(self::anioHubPath($anio), "Marchas de $anio", (int) ($mdd['N_MISMO_ANIO'] ?? 0));
            }
            $primerAutor = ($mdd['AUTOR'] ?? [])[0] ?? null;
            if ($primerAutor !== null) {
                $add(
                    Slug::buildDetailPath('autor', $primerAutor['autorId'], (string) $primerAutor['nombre']),
                    (string) $primerAutor['nombre'],
                    null,
                    'compositor'
                );
            }
            if (!empty($mdd['BANDA_ESTRENO']) && !empty($mdd['BANDA_NOMBRE'])) {
                $add(
                    Slug::buildDetailPath('banda', $mdd['BANDA_ESTRENO'], (string) $mdd['BANDA_NOMBRE']),
                    (string) $mdd['BANDA_NOMBRE'],
                    null,
                    'banda de estreno'
                );
            }
        }

        foreach ($hubEstilos as $e) {
            $path = self::estiloHubPath((string) $e['K']);
            $label = self::estiloHubLabel((string) $e['K']);
            if ($path !== null && $label !== null) $add($path, 'Marchas de ' . $label, (int) $e['N']);
        }
        foreach ($hubAniosRecientes as $a) {
            $add(self::anioHubPath($a['K']), 'Marchas de ' . $a['K'], (int) $a['N']);
        }
        foreach ($hubProvincias as $pr) {
            $add(self::provinciaHubPath((string) $pr['K']), 'Marchas de la provincia de ' . $pr['K'], (int) $pr['N']);
        }
        $add('/dedicatorias', 'Dedicatorias — advocaciones y hermandades', null);

        return $out;
    }

    // ── Listados / buscadores ─────────────────────────────────────────────────
    public static function marchaList(): void
    {
        [$criteria, $hasQuery, $page, $limit] = self::searchParams();
        // El explorador lista siempre (sin filtros = catálogo completo paginado).
        $qs = http_build_query($criteria);
        $result = Repo::searchMarchas($qs, $page, $limit);
        $facets = Repo::marchaFacets($qs);
        $hasQuery ? Http::noStore() : Http::cachePublic(3600);
        View::render('marcha_list', compact('criteria', 'result', 'page', 'limit', 'facets'), [
            'title' => 'Buscador de marchas procesionales — Marchas de Cristo',
            'description' => 'Busca marchas procesionales por título, fecha, dedicatoria, localidad y provincia.',
            'noindex' => true,
        ]);
    }

    public static function autorList(): void
    {
        [$criteria, $hasQuery, $page, $limit] = self::searchParams();
        $result = $hasQuery ? Repo::searchAutores(http_build_query($criteria), $page, $limit) : null;
        $result !== null ? Http::noStore() : Http::cachePublic(3600);
        View::render('autor_list', compact('criteria', 'result', 'page', 'limit'), [
            'title' => 'Buscador de compositores — Marchas de Cristo',
            'description' => 'Busca compositores de música procesional por nombre.',
            'noindex' => true,
        ]);
    }

    public static function bandaList(): void
    {
        [$criteria, $hasQuery, $page, $limit] = self::searchParams();
        // Explorador: lista siempre (sin filtros = catálogo completo paginado).
        $qs = http_build_query($criteria);
        $result = Repo::searchBandas($qs, $page, $limit);
        $facets = Repo::bandaFacets($qs);
        $hasQuery ? Http::noStore() : Http::cachePublic(3600);
        View::render('banda_list', compact('criteria', 'result', 'page', 'limit', 'facets'), [
            'title' => 'Buscador de bandas — Marchas de Cristo',
            'description' => 'Busca bandas de cornetas y tambores y agrupaciones musicales por nombre, localidad y provincia.',
            'noindex' => true,
        ]);
    }

    public static function discoList(): void
    {
        [$criteria, $hasQuery, $page, $limit] = self::searchParams();
        // Explorador: lista siempre (sin filtros = catálogo completo paginado).
        $qs = http_build_query($criteria);
        $result = Repo::searchDiscos($qs, $page, $limit);
        $facets = Repo::discoFacets($qs);
        $hasQuery ? Http::noStore() : Http::cachePublic(3600);
        View::render('disco_list', compact('criteria', 'result', 'page', 'limit', 'facets'), [
            'title' => 'Buscador de discos — Marchas de Cristo',
            'description' => 'Busca discos de música procesional de Semana Santa por nombre.',
            'noindex' => true,
        ]);
    }

    // ── Hubs de catálogo indexables: año / estilo / provincia (C1) ───────────
    // A diferencia del explorador /marcha (noindex: combinaciones de query
    // infinitas), cada hub tiene URL propia estable, título/description propios
    // y entra en el sitemap. Son la escalera de indexación hacia las fichas.

    /** Slug público ↔ valor de BD de los hubs de estilo. */
    private const ESTILO_HUBS = [
        'cornetas-y-tambores' => ['db' => 'CCTT', 'label' => 'cornetas y tambores'],
        'agrupacion-musical'  => ['db' => 'AM', 'label' => 'agrupación musical'],
    ];

    /** Alias cortos aceptados con redirección 308 al slug canónico. */
    private const ESTILO_ALIAS = ['cctt' => 'cornetas-y-tambores', 'am' => 'agrupacion-musical'];

    public static function anioHubPath(string|int $anio): string
    {
        return '/marcha/ano/' . $anio;
    }

    public static function rankingsAnioPath(string|int $anio): string
    {
        return '/rankings/' . $anio;
    }

    public static function aniversariosAnioPath(string|int $anio): string
    {
        return '/aniversarios/' . $anio;
    }

    public static function provinciaHubPath(string $provincia): string
    {
        return '/marcha/provincia/' . Slug::slugify($provincia);
    }

    public static function mapaProvinciaPath(string $provincia): string
    {
        return '/mapa/provincia/' . Slug::slugify($provincia);
    }

    /** Ruta del hub para un valor de BD ('CCTT'/'AM'), o null si no es un estilo conocido. */
    public static function estiloHubPath(?string $db): ?string
    {
        foreach (self::ESTILO_HUBS as $slug => $def) {
            if ($def['db'] === $db) {
                return '/marcha/estilo/' . $slug;
            }
        }
        return null;
    }

    /** Etiqueta pública de un estilo de BD ('CCTT' → 'cornetas y tambores'). */
    public static function estiloHubLabel(?string $db): ?string
    {
        foreach (self::ESTILO_HUBS as $def) {
            if ($def['db'] === $db) {
                return $def['label'];
            }
        }
        return null;
    }

    public static function marchaAnioHub(array $p): void
    {
        $anio = (string) $p['anio'];
        if (preg_match('/^\d{4}$/', $anio) !== 1) {
            Http::notFound();
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = Repo::marchasDeAnio($anio, $page);
        $n = (int) $result['totalRows'];

        // Año anterior/siguiente con marchas, para tejer la malla de hubs.
        $prev = null;
        $next = null;
        foreach (Repo::hubAnios() as $a) {
            if ((int) $a['K'] < (int) $anio) {
                $prev = $a;
            } elseif ((int) $a['K'] > (int) $anio) {
                $next = $a;
                break;
            }
        }
        $vease = [];
        if ($prev !== null) {
            $vease[] = ['href' => self::anioHubPath($prev['K']), 'label' => 'Marchas de ' . $prev['K'], 'cnt' => (int) $prev['N']];
        }
        if ($next !== null) {
            $vease[] = ['href' => self::anioHubPath($next['K']), 'label' => 'Marchas de ' . $next['K'], 'cnt' => (int) $next['N']];
        }
        $vease[] = ['href' => self::rankingsAnioPath($anio), 'label' => "Rankings de $anio", 'cnt' => null];
        // Aniversario redondo (N-09): si las marchas de este año cumplen 25/50/
        // .../200 años en el año en curso, tejer el enlace hacia esa página.
        $anioActual = (int) gmdate('Y');
        $cumple = $anioActual - (int) $anio;
        if ($cumple > 0 && $cumple % 25 === 0 && in_array($cumple, Repo::ANIVERSARIO_TRAMOS, true)) {
            $vease[] = ['href' => self::aniversariosAnioPath($anioActual), 'label' => "Cumplen $cumple años en $anioActual", 'cnt' => null];
        }
        $vease[] = ['href' => '/marcha?fechaDesde=' . $anio . '&fechaHasta=' . $anio, 'label' => 'Afinar la búsqueda en el explorador', 'cnt' => null];

        $desc = $n === 1
            ? "La marcha procesional compuesta en $anio, con su compositor, banda de estreno y grabaciones."
            : "Catálogo de las $n marchas procesionales compuestas en $anio, con sus compositores, bandas de estreno y grabaciones.";

        // Anuario (N-08): resumen editorial del año, reutilizando las mismas
        // queries que alimentan /rankings/{año} — solo el primer puesto de
        // cada una, a modo de titular. Se omite en años thin: con 1-2
        // marchas el "compositor con más obras" es solo el autor de la única
        // fila que ya se ve en la tabla, no aporta nada nuevo.
        $anuario = null;
        if ($n >= Repo::HUB_MIN_MARCHAS) {
            $anuario = [
                'autor' => Repo::fetchMasAutorAnio($anio)[0] ?? null,
                'estreno' => Repo::fetchMasEstrenoAnio($anio)[0] ?? null,
                'grabada' => (Repo::fetchMasGrabadaAnio($anio)[0] ?? null),
            ];
        }

        self::renderMarchaHub([
            'path' => self::anioHubPath($anio),
            'h1' => "Marchas procesionales de $anio",
            'crumb' => $anio,
            'title' => "Marchas procesionales de $anio — Marchas de Cristo",
            'description' => $desc,
            'result' => $result,
            'page' => $page,
            'vease' => $vease,
            'anuario' => $anuario,
        ]);
    }

    public static function marchaEstiloHub(array $p): void
    {
        $slug = mb_strtolower((string) $p['slug'], 'UTF-8');
        if (isset(self::ESTILO_ALIAS[$slug])) {
            Http::redirect('/marcha/estilo/' . self::ESTILO_ALIAS[$slug]);
        }
        $def = self::ESTILO_HUBS[$slug] ?? null;
        if ($def === null) {
            Http::notFound();
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = Repo::marchasDeEstilo($def['db'], $page);
        $n = (int) $result['totalRows'];

        $vease = [];
        foreach (Repo::hubEstilos() as $e) {
            if ($e['K'] !== $def['db'] && ($path = self::estiloHubPath((string) $e['K'])) !== null) {
                $otro = self::ESTILO_HUBS[substr($path, strlen('/marcha/estilo/'))]['label'] ?? (string) $e['K'];
                $vease[] = ['href' => $path, 'label' => 'Marchas de ' . $otro, 'cnt' => (int) $e['N']];
            }
        }
        $vease[] = ['href' => '/marcha', 'label' => 'Explorador completo de marchas', 'cnt' => null];

        $desc = "Las $n marchas para {$def['label']} del catálogo, con sus compositores, bandas de estreno y grabaciones.";
        self::renderMarchaHub([
            'path' => '/marcha/estilo/' . $slug,
            'h1' => 'Marchas de ' . $def['label'],
            'crumb' => ucfirst($def['label']),
            'title' => 'Marchas de ' . $def['label'] . ' — Marchas de Cristo',
            'description' => $desc,
            'result' => $result,
            'page' => $page,
            'vease' => $vease,
        ]);
    }

    public static function marchaProvinciaHub(array $p): void
    {
        $raw = (string) $p['slug'];
        $slug = Slug::slugify($raw);
        $prov = null;
        foreach (Repo::hubProvincias() as $r) {
            if (Slug::slugify((string) $r['K']) === $slug) {
                $prov = $r;
                break;
            }
        }
        if ($prov === null) {
            Http::notFound();
        }
        if ($raw !== $slug) {
            Http::redirect(self::provinciaHubPath((string) $prov['K']));
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $nombre = (string) $prov['K'];
        $result = Repo::marchasDeProvincia($nombre, $page);
        $n = (int) $result['totalRows'];

        $vease = [['href' => '/marcha?provincia=' . rawurlencode($nombre), 'label' => 'Afinar la búsqueda en el explorador', 'cnt' => null]];

        $desc = $n === 1
            ? "La marcha procesional de la provincia de $nombre, con su compositor, banda de estreno y grabaciones."
            : "Catálogo de las $n marchas procesionales de la provincia de $nombre, con sus compositores, bandas de estreno y grabaciones.";
        self::renderMarchaHub([
            'path' => self::provinciaHubPath($nombre),
            'h1' => "Marchas procesionales de $nombre",
            'crumb' => $nombre,
            'title' => "Marchas procesionales de la provincia de $nombre — Marchas de Cristo",
            'description' => $desc,
            'result' => $result,
            'page' => $page,
            'vease' => $vease,
        ]);
    }

    /**
     * Render común de los hubs: 404 si no hay marchas o la página se pasa,
     * canónica limpia (page 1) o con ?page=N, noindex si el hub es thin,
     * JSON-LD CollectionPage + breadcrumbs.
     *
     * @param array{path:string,h1:string,crumb:string,title:string,description:string,
     *              result:array,page:int,vease:list<array{href:string,label:string,cnt:?int}>,
     *              anuario?:?array{autor:?array,estreno:?array,grabada:?array}} $o
     */
    private static function renderMarchaHub(array $o): void
    {
        $result = $o['result'];
        $total = (int) $result['totalRows'];
        if ($total === 0) {
            Http::notFound();
        }
        $page = (int) $o['page'];
        if ($page > (int) ceil($total / Repo::HUB_PAGE_SIZE)) {
            Http::notFound();
        }

        $base = self::base();
        $canonical = $base . $o['path'] . ($page > 1 ? '?page=' . $page : '');

        Http::cachePublic(3600);
        View::render('marcha_hub', [
            'h1' => $o['h1'],
            'intro' => $o['description'],
            'result' => $result,
            'page' => $page,
            'basePath' => $o['path'],
            'vease' => $o['vease'],
            'anuario' => $o['anuario'] ?? null,
        ], [
            'title' => $o['title'],
            'description' => $o['description'],
            'canonical' => $canonical,
            'noindex' => $total < Repo::HUB_MIN_MARCHAS,
            'og' => ['type' => 'website', 'title' => $o['h1'], 'description' => $o['description'], 'url' => $canonical],
            'jsonld' => [
                Seo::marchaHub($o['h1'], $o['description'], $result['data'], $total, $canonical),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Marchas', 'url' => $base . '/marcha'],
                    ['name' => $o['crumb'], 'url' => $base . $o['path']],
                ]),
            ],
        ]);
    }

    // ── Detalles ──────────────────────────────────────────────────────────────
    public static function marchaDetail(array $p): void
    {
        $id = Slug::extractId($p['slugAndId']);
        if ($id === null) Http::notFound();
        $m = Repo::fetchMarcha($id);
        if ($m === null) Http::notFound();

        $canonical = Slug::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO']);
        if ('/marcha/' . $p['slugAndId'] !== $canonical) Http::redirect($canonical);

        $base = self::base();
        $url = $base . $canonical;
        $autores = implode(', ', array_map(static fn(array $a): string => (string) $a['nombre'], $m['AUTOR']));
        $enlaces = EnlaceRepo::publicadosDe('marcha', (int) $m['ID_MARCHA']);

        Http::cachePublic(3600);
        View::render('marcha_detail', ['m' => $m, 'url' => $url, 'enlaces' => $enlaces], [
            'title' => $m['TITULO'] . ' — Marchas de Cristo',
            'canonical' => $url,
            'description' => 'Marcha procesional "' . $m['TITULO'] . '" compuesta por ' . $autores . '.'
                . (!empty($m['DEDICATORIA']) ? ' Dedicada a ' . $m['DEDICATORIA'] . '.' : ''),
            'og' => ['type' => 'music.song', 'title' => $m['TITULO'], 'description' => 'Marcha procesional compuesta por ' . $autores, 'url' => $url,
                     'image' => $base . '/og/marcha/' . $m['ID_MARCHA'] . '.png', 'imageAlt' => 'Marcha procesional «' . $m['TITULO'] . '»'],
            'jsonld' => [
                Seo::marcha($m, $url),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Marchas', 'url' => $base . '/marcha'],
                    ['name' => $m['TITULO'], 'url' => $url],
                ]),
            ],
        ]);
    }

    public static function autorDetail(array $p): void
    {
        $id = Slug::extractId($p['slugAndId']);
        if ($id === null) Http::notFound();
        $a = Repo::fetchAutor($id);
        if ($a === null) Http::notFound();

        $fullName = trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? ''));
        $canonical = Slug::buildDetailPath('autor', $a['ID_AUTOR'], $fullName);
        if ('/autor/' . $p['slugAndId'] !== $canonical) Http::redirect($canonical);

        $base = self::base();
        $url = $base . $canonical;

        Http::cachePublic(3600);
        View::render('autor_detail', ['a' => $a, 'fullName' => $fullName, 'url' => $url], [
            'title' => $fullName . ' — Marchas de Cristo',
            'canonical' => $url,
            'description' => 'Compositor de música procesional. Ha compuesto ' . $a['marchasLength'] . ' marchas.'
                . (!empty($a['LUGAR_NAC']) ? ' Natural de ' . $a['LUGAR_NAC'] . '.' : ''),
            'og' => ['type' => 'profile', 'title' => $fullName, 'description' => 'Compositor de ' . $a['marchasLength'] . ' marchas de música procesional', 'url' => $url,
                     'image' => $base . '/og/autor/' . $a['ID_AUTOR'] . '.png', 'imageAlt' => 'Compositor ' . $fullName],
            'jsonld' => [
                Seo::autor($a, $url),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Autores', 'url' => $base . '/autor'],
                    ['name' => $fullName, 'url' => $url],
                ]),
            ],
        ]);
    }

    public static function bandaDetail(array $p): void
    {
        $id = Slug::extractId($p['slugAndId']);
        if ($id === null) Http::notFound();
        $b = Repo::fetchBanda($id);
        if ($b === null) Http::notFound();

        $canonical = Slug::buildDetailPath('banda', $b['ID_BANDA'], (string) $b['NOMBRE_COMPLETO']);
        if ('/banda/' . $p['slugAndId'] !== $canonical) Http::redirect($canonical);

        $base = self::base();
        $url = $base . $canonical;

        $enlaces = EnlaceRepo::publicadosDe('banda', (int) $b['ID_BANDA']);

        Http::cachePublic(3600);
        View::render('banda_detail', ['b' => $b, 'url' => $url, 'enlaces' => $enlaces], [
            'title' => $b['NOMBRE_BREVE'] . ' — Marchas de Cristo',
            'canonical' => $url,
            'description' => $b['NOMBRE_COMPLETO'] . ', banda de ' . $b['LOCALIDAD'] . '. Ha grabado ' . $b['discosLength'] . ' discos y estrenado ' . $b['marchasLength'] . ' marchas.',
            'og' => ['type' => 'music.playlist', 'title' => $b['NOMBRE_BREVE'], 'description' => 'Banda de música procesional de ' . $b['LOCALIDAD'], 'url' => $url,
                     'image' => $base . '/og/banda/' . $b['ID_BANDA'] . '.png', 'imageAlt' => 'Banda ' . $b['NOMBRE_BREVE']],
            'jsonld' => [
                Seo::banda($b, $url),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Bandas', 'url' => $base . '/banda'],
                    ['name' => $b['NOMBRE_BREVE'], 'url' => $url],
                ]),
            ],
        ]);
    }

    public static function discoDetail(array $p): void
    {
        $id = Slug::extractId($p['slugAndId']);
        if ($id === null) Http::notFound();
        $d = Repo::fetchDisco($id);
        if ($d === null) Http::notFound();

        $canonical = Slug::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD']);
        if ('/disco/' . $p['slugAndId'] !== $canonical) Http::redirect($canonical);

        $base = self::base();
        $url = $base . $canonical;

        $enlaces = EnlaceRepo::publicadosDe('disco', (int) $d['ID_DISCO']);

        Http::cachePublic(3600);
        View::render('disco_detail', ['d' => $d, 'url' => $url, 'enlaces' => $enlaces], [
            'title' => $d['NOMBRE_CD'] . ' — Marchas de Cristo',
            'canonical' => $url,
            'description' => 'Disco de música procesional "' . $d['NOMBRE_CD'] . '" de ' . $d['BANDA'] . '. Contiene ' . $d['marchasLength'] . ' marchas.',
            'og' => ['type' => 'music.album', 'title' => $d['NOMBRE_CD'], 'description' => 'Álbum de música procesional de ' . $d['BANDA'], 'url' => $url,
                     'image' => $base . '/og/disco/' . $d['ID_DISCO'] . '.png', 'imageAlt' => 'Disco «' . $d['NOMBRE_CD'] . '»'],
            'jsonld' => [
                Seo::disco($d, $url),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Discos', 'url' => $base . '/disco'],
                    ['name' => $d['NOMBRE_CD'], 'url' => $url],
                ]),
            ],
        ]);
    }

    // ── Dedicatorias: hubs de advocación (N-01 / N-02) ──────────────────────────

    /** N-02 · índice A–Z de advocaciones, filtrable por localidad/provincia. */
    public static function dedicatoriaList(): void
    {
        $localidad = trim((string) ($_GET['localidad'] ?? ''));
        $provincia = trim((string) ($_GET['provincia'] ?? ''));
        $hasQuery = $localidad !== '' || $provincia !== '';
        $criteria = ['localidad' => $localidad, 'provincia' => $provincia];

        $base = self::base();
        $items = Repo::dedicatoriaIndex($localidad !== '' ? $localidad : null, $provincia !== '' ? $provincia : null);
        // Filtrado = vista de utilidad sobre el índice; el índice sin filtrar es
        // el que se indexa y entra en el sitemap (mismo criterio que /marcha).
        $hasQuery ? Http::noStore() : Http::cachePublic(3600);
        View::render('dedicatoria_list', compact('items', 'criteria'), [
            'title' => 'Dedicatorias — advocaciones y hermandades · Marchas de Cristo',
            'description' => 'Directorio de advocaciones y hermandades a las que están dedicadas '
                . 'las marchas procesionales del catálogo, con el número de marchas de cada una.',
            'noindex' => $hasQuery,
            'jsonld' => $hasQuery ? [] : [
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Dedicatorias', 'url' => $base . '/dedicatorias'],
                ]),
            ],
        ]);
    }

    /** N-01 · hub de una advocación: todas sus marchas dedicadas. */
    public static function dedicatoriaDetail(array $p): void
    {
        $id = Slug::extractId($p['slugAndId']);
        if ($id === null) Http::notFound();
        $d = Repo::fetchDedicatoria($id);
        if ($d === null) Http::notFound();

        $loc = trim((string) $d['LOCALIDAD']);
        $label = $d['NOMBRE'] . ($loc !== '' ? ' ' . $loc : '');
        $canonical = Slug::buildDetailPath('dedicatoria', $d['ID_DEDIC'], $label);
        if ('/dedicatoria/' . $p['slugAndId'] !== $canonical) Http::redirect($canonical);

        $base = self::base();
        $url = $base . $canonical;
        $titular = $d['NOMBRE'] . ($loc !== '' ? ' (' . $loc . ')' : '');
        $thin = $d['N'] < Repo::DEDIC_MIN_MARCHAS;
        $personal = (int) ($d['PERSONAL'] ?? 0) === 1;

        Http::cachePublic(3600);
        View::render('dedicatoria_detail', ['d' => $d, 'url' => $url], [
            'title' => 'Marchas dedicadas a ' . $titular . ' — Marchas de Cristo',
            'canonical' => $url,
            'description' => 'Catálogo de ' . $d['N'] . ' marcha' . ($d['N'] === 1 ? '' : 's')
                . ' procesional' . ($d['N'] === 1 ? '' : 'es') . ' dedicada' . ($d['N'] === 1 ? '' : 's')
                . ' a ' . $titular . ', con sus compositores y grabaciones.',
            'noindex' => $thin || $personal,
            'og' => ['type' => 'website', 'title' => 'Marchas dedicadas a ' . $d['NOMBRE'],
                'description' => $d['N'] . ' marchas procesionales dedicadas a ' . $titular, 'url' => $url],
            'jsonld' => [
                Seo::dedicatoria($d, $url),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Dedicatorias', 'url' => $base . '/dedicatorias'],
                    ['name' => $titular, 'url' => $url],
                ]),
            ],
        ]);
    }

    // ── Rankings (N-07) ──────────────────────────────────────────────────────
    // /estadisticas se renombra a /rankings (301 permanente): mismo contenido,
    // ahora con drill-down por año (rankingsAnioHub) bajo el mismo espacio de
    // nombres.
    public static function estadisticas(): void
    {
        Http::redirect('/rankings', 301);
    }

    public static function rankingsIndex(): void
    {
        $base = self::base();
        $anios = array_reverse(Repo::hubAnios()); // más reciente primero
        Http::cachePublic(1800);
        View::render('rankings_index', [
            'masAutor' => Repo::fetchMasAutor(),
            'masDedica' => Repo::fetchMasDedica(),
            'masEstreno' => Repo::fetchMasEstreno(),
            'masGrabada' => Repo::fetchMasGrabada(),
            'anios' => $anios,
        ], [
            'title' => 'Rankings de música procesional — Marchas de Cristo',
            'description' => 'Los compositores con más marchas, bandas con más estrenos y marchas más grabadas de siempre, y los récords de cada año.',
            'canonical' => $base . '/rankings',
            'jsonld' => [
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Rankings', 'url' => $base . '/rankings'],
                ]),
            ],
        ]);
    }

    public static function rankingsAnioHub(array $p): void
    {
        $anio = (string) $p['anio'];
        if (preg_match('/^\d{4}$/', $anio) !== 1) {
            Http::notFound();
        }

        // Solo existen rankings para años con marchas reales (mismo universo
        // que el hub de año, C1); reutiliza esa lista para el 404 y para
        // tejer prev/next, en vez de otra consulta.
        $n = null;
        $prev = null;
        $next = null;
        foreach (Repo::hubAnios() as $a) {
            if ((int) $a['K'] === (int) $anio) {
                $n = (int) $a['N'];
            } elseif ((int) $a['K'] < (int) $anio) {
                $prev = $a;
            } elseif ($next === null) {
                $next = $a;
            }
        }
        if ($n === null) {
            Http::notFound();
        }

        $masAutor = Repo::fetchMasAutorAnio($anio);
        $masEstreno = Repo::fetchMasEstrenoAnio($anio);
        $masGrabada = Repo::fetchMasGrabadaAnio($anio);

        $vease = [];
        if ($prev !== null) {
            $vease[] = ['href' => self::rankingsAnioPath($prev['K']), 'label' => 'Rankings de ' . $prev['K'], 'cnt' => null];
        }
        if ($next !== null) {
            $vease[] = ['href' => self::rankingsAnioPath($next['K']), 'label' => 'Rankings de ' . $next['K'], 'cnt' => null];
        }
        $vease[] = ['href' => self::anioHubPath($anio), 'label' => "Catálogo completo de $anio", 'cnt' => $n];
        $vease[] = ['href' => '/rankings', 'label' => 'Rankings de siempre', 'cnt' => null];

        $base = self::base();
        $canonical = $base . self::rankingsAnioPath($anio);
        $h1 = "Récords de $anio";
        $desc = "Compositores, bandas de estreno y marchas más grabadas de $anio: los récords de la temporada.";

        Http::cachePublic(3600);
        View::render('rankings_anio', [
            'h1' => $h1,
            'anio' => $anio,
            'masAutor' => $masAutor,
            'masEstreno' => $masEstreno,
            'masGrabada' => $masGrabada,
            'vease' => $vease,
        ], [
            'title' => "$h1 — Marchas de Cristo",
            'description' => $desc,
            'canonical' => $canonical,
            // Mismo umbral que los hubs de catálogo (C1): un año con muy pocas
            // marchas da rankings triviales (todo empatado a 1), thin content.
            'noindex' => $n < Repo::HUB_MIN_MARCHAS,
            'jsonld' => [
                Seo::marchaHub($h1, $desc, $masGrabada, count($masGrabada), $canonical),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Rankings', 'url' => $base . '/rankings'],
                    ['name' => $anio, 'url' => $canonical],
                ]),
            ],
        ]);
    }

    // ── Aniversarios (N-09) ──────────────────────────────────────────────────
    // /aniversarios sin año es un punto de entrada estable pero cambia cada
    // 1 de enero, así que redirige (temporal, no 301) al año en curso — el
    // contenido real e indexable vive en /aniversarios/{año}.
    public static function aniversariosIndex(): void
    {
        Http::redirect(self::aniversariosAnioPath(gmdate('Y')), 302);
    }

    public static function aniversariosAnioHub(array $p): void
    {
        $anio = (string) $p['anio'];
        if (preg_match('/^\d{4}$/', $anio) !== 1) {
            Http::notFound();
        }
        // Fuera de este rango el espacio de URLs sería infinito (cualquier
        // año de 4 cifras) sin contenido real que mostrar — 404 en vez de
        // dejarlo abierto a un rastreador.
        $anioActual = (int) gmdate('Y');
        if ((int) $anio < 1900 || (int) $anio > $anioActual + 1) {
            Http::notFound();
        }

        $tramos = Repo::aniversariosDe($anio);
        if ($tramos === []) {
            Http::notFound();
        }
        $total = array_sum(array_map(static fn(array $t): int => (int) $t['result']['totalRows'], $tramos));

        $muestra = [];
        foreach ($tramos as $t) {
            foreach ($t['result']['data'] as $m) {
                $muestra[] = $m;
                if (count($muestra) >= 30) break 2;
            }
        }

        $vease = [
            ['href' => self::aniversariosAnioPath((int) $anio - 1), 'label' => 'Aniversarios de ' . ((int) $anio - 1), 'cnt' => null],
            ['href' => self::aniversariosAnioPath((int) $anio + 1), 'label' => 'Aniversarios de ' . ((int) $anio + 1), 'cnt' => null],
        ];

        $base = self::base();
        $canonical = $base . self::aniversariosAnioPath($anio);
        $h1 = "Aniversarios de $anio";
        $desc = "Marchas procesionales que cumplen 25, 50, 75, 100 años o más en $anio, agrupadas por el año en que se compusieron.";

        Http::cachePublic(3600);
        View::render('aniversarios_anio', [
            'h1' => $h1,
            'anio' => $anio,
            'tramos' => $tramos,
            'vease' => $vease,
        ], [
            'title' => "$h1 — Marchas de Cristo",
            'description' => $desc,
            'canonical' => $canonical,
            'noindex' => $total < Repo::HUB_MIN_MARCHAS,
            'jsonld' => [
                Seo::marchaHub($h1, $desc, $muestra, $total, $canonical),
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Aniversarios', 'url' => $canonical],
                ]),
            ],
        ]);
    }

    // ── Mapa (N-10) ──────────────────────────────────────────────────────────
    public static function mapa(): void
    {
        $porProvincia = Repo::hubProvincias();
        $svgMapa = Mapa::render($porProvincia);

        $base = self::base();
        $canonical = $base . '/mapa';
        $desc = 'Mapa de España con el número de marchas procesionales del catálogo por provincia: pulsa una provincia para ver sus municipios.';

        Http::cachePublic(3600);
        View::render('mapa', [
            'svgMapa' => $svgMapa,
            // Ya viene ordenado N DESC (Repo::hubProvincias): la misma lista
            // alimenta la tabla accesible bajo el mapa (sin JS ni SVG).
            'porProvincia' => $porProvincia,
        ], [
            'title' => 'Mapa de provincias — Marchas de Cristo',
            'description' => $desc,
            'canonical' => $canonical,
            'jsonld' => [
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Mapa', 'url' => $canonical],
                ]),
            ],
        ]);
    }

    /** Mapa ampliado de una provincia: municipios clicables (self::mapaProvinciaPath). */
    public static function mapaProvincia(array $p): void
    {
        $raw = (string) $p['slug'];
        $slug = Slug::slugify($raw);
        $prov = null;
        foreach (Repo::hubProvincias() as $r) {
            if (Slug::slugify((string) $r['K']) === $slug) {
                $prov = $r;
                break;
            }
        }
        if ($prov === null) {
            Http::notFound();
        }
        if ($raw !== $slug) {
            Http::redirect(self::mapaProvinciaPath((string) $prov['K']));
        }
        $nombre = (string) $prov['K'];

        $porLocalidad = Repo::hubLocalidades($nombre);
        $puntos = Mapa::puntos($porLocalidad);
        $svgMapa = Mapa::renderProvincia($nombre, $puntos);
        if ($svgMapa === null) {
            Http::notFound();
        }

        $base = self::base();
        $canonical = $base . self::mapaProvinciaPath($nombre);

        Http::cachePublic(3600);
        View::render('mapa_provincia', [
            'svgMapa' => $svgMapa,
            'provincia' => $nombre,
            'porLocalidad' => $porLocalidad,
            'total' => (int) $prov['N'],
        ], [
            'title' => 'Mapa de ' . $nombre . ' — Marchas de Cristo',
            'description' => 'Localidades con marchas procesionales en la provincia de ' . $nombre . ': pulsa un municipio para ver su catálogo.',
            'canonical' => $canonical,
            'jsonld' => [
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => 'Mapa', 'url' => $base . '/mapa'],
                    ['name' => $nombre, 'url' => $canonical],
                ]),
            ],
        ]);
    }

    // ── Temporada (N-04): contratos banda↔hermandad, alta manual por ahora ──
    public static function temporadaIndex(): void
    {
        if (!self::temporadaVisible()) {
            Http::notFound();
        }
        Http::redirect('/temporada/' . gmdate('Y'), 302);
    }

    public static function temporada(array $p): void
    {
        if (!self::temporadaVisible()) {
            Http::notFound();
        }
        $anio = (string) $p['anio'];
        if (preg_match('/^\d{4}$/', $anio) !== 1) {
            Http::notFound();
        }
        // Igual que /aniversarios: no hay un universo cerrado de "años válidos"
        // (cualquier temporada, pasada o futura, es un destino legítimo una vez
        // haya contratos), así que se acota el rango en vez de dejarlo abierto a
        // un espacio infinito de URLs sin contenido.
        $anioActual = (int) gmdate('Y');
        if ((int) $anio < 2020 || (int) $anio > $anioActual + 2) {
            Http::notFound();
        }

        // Fallback defensivo: la tabla `contrato` (005_contrato.sql) necesita
        // una migración manual en el host que puede no haberse aplicado aún
        // (mismo mecanismo que P-07, ver docs/pendientes-post-cutover.md). Sin
        // esto, visitar la página antes de migrar da un 500 crudo en vez de
        // "todavía no hay contratos" — que es, en la práctica, el mismo estado.
        try {
            $contratos = Repo::temporada($anio);
        } catch (\Throwable $e) {
            error_log('[temporada] ' . $e->getMessage());
            $contratos = [];
        }
        // Agrupado por hermandad (comportamiento original) + una "ciudad"
        // heurística por hermandad: la localidad más frecuente entre las
        // bandas que le tocan ese año. Es una aproximación deliberada
        // mientras no existe la entidad `hermandad` real (N-03): sirve para
        // no mezclar en una sola lista plana las hermandades de varias
        // localidades (Sevilla, Málaga, ...) a medida que se cargan más
        // temporadas. Ver docs/n03-hermandad.md para el reemplazo definitivo.
        $grupos = [];
        foreach ($contratos as $c) {
            $key = (string) $c['HERMANDAD_SLUG'];
            $grupos[$key]['nombre'] ??= $c['HERMANDAD'];
            $grupos[$key]['items'][] = $c;
            $loc = trim((string) ($c['BANDA_LOCALIDAD'] ?? ''));
            if ($loc !== '') {
                $grupos[$key]['localidades'][$loc] = ($grupos[$key]['localidades'][$loc] ?? 0) + 1;
            }
        }
        foreach ($grupos as $key => &$g) {
            $locs = $g['localidades'] ?? [];
            arsort($locs);
            $g['ciudad'] = $locs === [] ? 'Sin localidad' : (string) array_key_first($locs);
            unset($g['localidades']);
        }
        unset($g);

        $porCiudad = [];
        foreach ($grupos as $key => $g) {
            $porCiudad[$g['ciudad']][$key] = $g;
        }
        ksort($porCiudad, SORT_STRING);
        // Dentro de cada ciudad, las hermandades NO se alfabetizan: se dejan
        // en el orden en que llegaron desde Repo::temporada() (ID_CONTRATO
        // ASC = orden del CSV de origen: día → hermandad → paso). Mismo
        // criterio para los acompañamientos de cada hermandad (ya venían así
        // en $g['items']).

        $base = self::base();
        $canonical = $base . '/temporada/' . $anio;
        $h1 = "Temporada $anio";
        $desc = "Qué banda toca este año tras cada paso: contratos de la temporada $anio por hermandad y localidad.";

        Http::cachePublic(3600);
        View::render('temporada', [
            'h1' => $h1,
            'anio' => $anio,
            'porCiudad' => $porCiudad,
        ], [
            'title' => "$h1 — Marchas de Cristo",
            'description' => $desc,
            'canonical' => $canonical,
            // Alta manual, todavía sin datos casi siempre: no indexar una
            // temporada vacía o con apenas 1-2 contratos (thin), igual que
            // los demás hubs con Repo::HUB_MIN_MARCHAS.
            'noindex' => count($contratos) < Repo::HUB_MIN_MARCHAS,
            'jsonld' => [
                Seo::breadcrumbs([
                    ['name' => 'Inicio', 'url' => $base],
                    ['name' => $h1, 'url' => $canonical],
                ]),
            ],
        ]);
    }

    // ── Búsqueda global unificada (M3) ────────────────────────────────────────
    public static function buscar(): void
    {
        // Página de utilidad, no de contenido: noindex (como el explorador) y
        // sin caché (depende de la query). El desplegable en vivo va por
        // /api/buscar; esta página es el destino sin-JS del formulario y el
        // "ver todo" con más resultados por tipo.
        Http::noStore();
        $q = trim((string) ($_GET['q'] ?? ''));
        // Se pasan las claves del resultado como variables separadas ($q,
        // $total, $grupos): View::capture usa extract(EXTR_SKIP) y un
        // 'data' => … colisionaría con su propio parámetro $data (se saltaría).
        $res = Api::buscarItems($q, 20);

        $titulo = $q !== '' ? '“' . $q . '” — Buscar' : 'Buscar';
        View::render('buscar', $res, [
            'title' => $titulo . ' — Marchas de Cristo',
            'description' => 'Busca marchas procesionales, compositores, bandas y discos en el catálogo de Marchas de Cristo.',
            'noindex' => true,
        ]);
    }

    // ── sitemap.xml ───────────────────────────────────────────────────────────
    public static function sitemap(): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        Http::cachePublic(3600);
        $base = self::base();

        // Todo el catálogo se actualiza de golpe (scripts/sync_db_to_prod.php
        // reemplaza el .db entero), así que el mtime del fichero es un lastmod
        // honesto y uniforme para las 5.700+ URLs — no hay tracking por fila.
        $dbPath = (string) ($GLOBALS['config']['db_path'] ?? '');
        $lastmod = is_file($dbPath) ? gmdate('Y-m-d', (int) filemtime($dbPath)) : null;

        $urls = [
            [$base . '/', 'daily', '1.0'],
            [$base . '/marcha', 'weekly', '0.9'],
            [$base . '/autor', 'weekly', '0.8'],
            [$base . '/banda', 'weekly', '0.8'],
            [$base . '/disco', 'weekly', '0.8'],
            [$base . '/dedicatorias', 'weekly', '0.8'],
            [$base . '/rankings', 'weekly', '0.7'],
            // Solo el año en curso (N-09): a diferencia de los hubs de año, no
            // hay un universo cerrado de "años válidos" para aniversarios —
            // cualquier año lo es — así que solo se anuncia el vigente; los
            // años pasados siguen accesibles (y rastreables) vía prev/next.
            [$base . self::aniversariosAnioPath(gmdate('Y')), 'monthly', '0.6'],
            [$base . '/mapa', 'monthly', '0.6'],
            [$base . '/datos', 'monthly', '0.5'],
        ];

        try {
            // Hubs de catálogo (C1): solo los que tienen sustancia (≥ HUB_MIN_MARCHAS).
            foreach (Repo::hubAnios() as $r) {
                if ((int) $r['N'] >= Repo::HUB_MIN_MARCHAS) {
                    $urls[] = [$base . self::anioHubPath($r['K']), 'monthly', '0.7'];
                    // Rankings por año (N-07): mismo umbral de sustancia que el hub.
                    $urls[] = [$base . self::rankingsAnioPath($r['K']), 'monthly', '0.6'];
                }
            }
            foreach (Repo::hubEstilos() as $r) {
                $path = self::estiloHubPath((string) $r['K']);
                if ($path !== null && (int) $r['N'] >= Repo::HUB_MIN_MARCHAS) {
                    $urls[] = [$base . $path, 'weekly', '0.7'];
                }
            }
            foreach (Repo::hubProvincias() as $r) {
                if ((int) $r['N'] >= Repo::HUB_MIN_MARCHAS) {
                    $urls[] = [$base . self::provinciaHubPath((string) $r['K']), 'weekly', '0.7'];
                }
            }
            foreach (Db::all('SELECT ID_MARCHA AS id, TITULO AS label FROM marcha') as $r) {
                $urls[] = [$base . Slug::buildDetailPath('marcha', $r['id'], (string) $r['label']), 'monthly', '0.7'];
            }
            foreach (Db::all("SELECT ID_AUTOR AS id, (NOMBRE || ' ' || APELLIDOS) AS label FROM autor") as $r) {
                $urls[] = [$base . Slug::buildDetailPath('autor', $r['id'], (string) $r['label']), 'monthly', '0.6'];
            }
            // NOMBRE_COMPLETO para que el slug coincida con la canónica del detalle
            // (el detalle usa NOMBRE_COMPLETO; con NOMBRE_BREVE el sitemap daba 308).
            foreach (Db::all('SELECT ID_BANDA AS id, NOMBRE_COMPLETO AS label FROM banda') as $r) {
                $urls[] = [$base . Slug::buildDetailPath('banda', $r['id'], (string) $r['label']), 'monthly', '0.6'];
            }
            foreach (Db::all('SELECT ID_DISCO AS id, NOMBRE_CD AS label FROM disco') as $r) {
                $urls[] = [$base . Slug::buildDetailPath('disco', $r['id'], (string) $r['label']), 'monthly', '0.6'];
            }
            // Hubs de dedicatoria con sustancia (≥ DEDIC_MIN_MARCHAS marchas).
            foreach (Repo::dedicatoriaIndex() as $r) {
                $label = $r['NOMBRE'] . ($r['LOCALIDAD'] !== '' ? ' ' . $r['LOCALIDAD'] : '');
                $urls[] = [$base . Slug::buildDetailPath('dedicatoria', $r['ID_DEDIC'], $label), 'monthly', '0.6'];
            }
        } catch (Throwable $e) {
            error_log('[sitemap] ' . $e->getMessage());
        }

        // Temporada (N-04) en su propio try: tabla nueva (005_contrato.sql) que
        // necesita una migración manual en el host (ver docs/pendientes-post-cutover.md)
        // — si aún no se ha aplicado, esto no debe tumbar el resto del sitemap
        // (ya pasó: el primer deploy de N-04 dejó el sitemap sin fichas de marcha
        // porque la consulta vivía dentro del try principal, más arriba).
        // Oculta en PRO (ver Pages::temporadaVisible): listar aquí una URL que
        // el propio sitio responde con 404 sería peor para el sitemap que
        // omitirla.
        if (self::temporadaVisible()) {
            try {
                foreach (Repo::aniosConTemporada() as $r) {
                    if ((int) $r['N'] >= Repo::HUB_MIN_MARCHAS) {
                        $urls[] = [$base . '/temporada/' . $r['K'], 'weekly', '0.5'];
                    }
                }
            } catch (Throwable $e) {
                error_log('[sitemap:temporada] ' . $e->getMessage());
            }
        }

        $lastmodTag = $lastmod !== null ? '<lastmod>' . $lastmod . '</lastmod>' : '';
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $freq, $prio]) {
            echo '<url><loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>'
                . $lastmodTag
                . '<changefreq>' . $freq . '</changefreq>'
                . '<priority>' . $prio . '</priority></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    // ── robots.txt ────────────────────────────────────────────────────────────
    public static function robots(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        Http::cachePublic(86400);

        // Preproducción: bloqueo total, sin anunciar el sitemap. El host de
        // pruebas jamás debe competir con producción como contenido duplicado.
        if (!empty($GLOBALS['config']['preproduccion'])) {
            echo "User-Agent: *\n";
            echo "Disallow: /\n";
            return;
        }

        $base = self::base();
        echo "User-Agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /login\n";
        echo "Disallow: /dashboard\n";
        echo "Disallow: /buscar\n"; // página de utilidad (noindex): no gastar crawl budget
        echo "\n";
        echo "Sitemap: $base/sitemap.xml\n";
    }

    // ── Datos abiertos: página, feeds y llms.txt (M1) ─────────────────────────

    /** Página «Datos»: licencia CC BY 4.0, política de citación y accesos. */
    public static function datos(): void
    {
        Http::cachePublic(86400);
        $base = self::base();
        try {
            $counts = Db::counts();
        } catch (Throwable) {
            $counts = null;
        }
        // Ejemplo real de la API: la marcha del día candidata (existe y suele
        // tener datos ricos); si no hubiera, se omite el enlace de ejemplo.
        $ejemploApi = $base . '/api/marcha/1.json';
        try {
            $cand = Repo::marchaDelDiaCandidatos();
            if ($cand !== []) {
                $ejemploApi = $base . '/api/marcha/' . $cand[0] . '.json';
            }
        } catch (Throwable) {
            // se queda el ejemplo por defecto
        }

        $dataset = [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => 'Marchas de Cristo — base de datos de música procesional',
            'description' => 'Catálogo de marchas procesionales españolas con sus compositores, '
                . 'bandas de estreno, grabaciones y dedicatorias. Datos abiertos bajo CC BY 4.0.',
            'url' => $base . '/datos',
            'license' => Api::licencia()['url'],
            'isAccessibleForFree' => true,
            'inLanguage' => 'es',
            'creator' => [
                '@type' => 'Person',
                'name' => 'Javier Guerra',
                'url' => 'https://x.com/JaviWarSVQ',
            ],
            'keywords' => ['música procesional', 'marchas de Semana Santa', 'cofradías', 'bandas', 'compositores'],
            'distribution' => [
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/rss+xml', 'contentUrl' => $base . '/feed.xml'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/feed+json', 'contentUrl' => $base . '/feed.json'],
            ],
        ];

        View::render('datos', [
            'counts' => $counts,
            'licencia' => Api::licencia(),
            'base' => $base,
            'ejemploApi' => $ejemploApi,
        ], [
            'title' => 'Datos y licencia — Marchas de Cristo',
            'canonical' => $base . '/datos',
            'description' => 'Los datos de música procesional de marchasdecristo.com se publican bajo '
                . 'licencia CC BY 4.0: API JSON, feeds de novedades y cómo citarlos.',
            'jsonld' => [$dataset],
        ]);
    }

    /** @return list<array<string,mixed>> Últimas marchas para los feeds. */
    private static function feedItems(): array
    {
        try {
            return Repo::fetchUltimas();
        } catch (Throwable $e) {
            error_log('[feed] ' . $e->getMessage());
            return [];
        }
    }

    /** Descripción común de una marcha para RSS/JSON. */
    private static function feedDesc(array $m): string
    {
        $autores = implode(', ', array_map(
            static fn(array $a): string => (string) ($a['nombre'] ?? ''),
            $m['AUTOR'] ?? []
        ));
        $anio = (!empty($m['FECHA']) && $m['FECHA'] !== 's/f') ? (int) $m['FECHA'] : null;
        return 'Marcha procesional'
            . ($autores !== '' ? ' de ' . $autores : '')
            . (!empty($m['BANDA_BREVE']) ? ', estrenada por ' . $m['BANDA_BREVE'] : '')
            . ($anio !== null ? ' (' . $anio . ')' : '') . '.';
    }

    /** Feed RSS 2.0 de últimas incorporaciones. */
    public static function feedRss(): void
    {
        header('Content-Type: application/rss+xml; charset=UTF-8');
        Http::cachePublic(1800);
        $base = self::base();
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

        // No hay timestamp por fila (el .db se reemplaza entero en cada sync),
        // así que lastBuildDate = mtime del .db es la señal de frescura honesta;
        // los items no llevan pubDate individual.
        $dbPath = (string) ($GLOBALS['config']['db_path'] ?? '');
        $built = is_file($dbPath) ? (int) filemtime($dbPath) : time();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        echo '<channel>' . "\n";
        echo '<title>Marchas de Cristo — últimas incorporaciones</title>' . "\n";
        echo '<link>' . $x($base . '/') . '</link>' . "\n";
        echo '<atom:link href="' . $x($base . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";
        echo '<description>Últimas marchas procesionales añadidas al catálogo de marchasdecristo.com.</description>' . "\n";
        echo '<language>es</language>' . "\n";
        echo '<lastBuildDate>' . gmdate(DATE_RSS, $built) . '</lastBuildDate>' . "\n";
        echo '<copyright>CC BY 4.0 — marchasdecristo.com</copyright>' . "\n";
        foreach (self::feedItems() as $m) {
            $url = $base . Slug::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO']);
            echo '<item>' . "\n";
            echo '<title>' . $x((string) $m['TITULO']) . '</title>' . "\n";
            echo '<link>' . $x($url) . '</link>' . "\n";
            echo '<guid isPermaLink="true">' . $x($url) . '</guid>' . "\n";
            echo '<description>' . $x(self::feedDesc($m)) . '</description>' . "\n";
            echo '</item>' . "\n";
        }
        echo '</channel>' . "\n</rss>\n";
    }

    /** Feed JSON (JSON Feed 1.1) de últimas incorporaciones. */
    public static function feedJson(): void
    {
        header('Content-Type: application/feed+json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        Http::cachePublic(1800);
        $base = self::base();

        $items = [];
        foreach (self::feedItems() as $m) {
            $url = $base . Slug::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO']);
            $items[] = [
                'id' => $url,
                'url' => $url,
                'title' => (string) $m['TITULO'],
                'content_text' => self::feedDesc($m),
            ];
        }

        echo (string) json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Marchas de Cristo — últimas incorporaciones',
            'home_page_url' => $base . '/',
            'feed_url' => $base . '/feed.json',
            'description' => 'Últimas marchas procesionales añadidas al catálogo de marchasdecristo.com.',
            'language' => 'es',
            'authors' => [['name' => 'marchasdecristo.com', 'url' => $base . '/']],
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** llms.txt: guía de datos/licencia y puntos de acceso, para agentes/LLMs. */
    public static function llms(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        Http::cachePublic(86400);
        $base = self::base();
        $lic = Api::licencia();

        $lines = [
            '# Marchas de Cristo',
            '',
            '> Base de datos de música procesional española (marchas, compositores, bandas y '
                . 'discos). Contenido en español. Datos bajo licencia ' . $lic['nombre'] . '.',
            '',
            'marchasdecristo.com cataloga marchas procesionales con sus compositores, bandas de '
                . 'estreno, grabaciones y dedicatorias/advocaciones. Puedes usar y citar estos datos '
                . 'enlazando a marchasdecristo.com (' . $lic['nombre'] . ').',
            '',
            '## Datos y licencia',
            '',
            '- [Datos y licencia](' . $base . '/datos): licencia y política de citación.',
            '- Licencia: ' . $lic['url'],
            '- Atribución requerida: ' . $lic['atribucion'],
            '',
            '## API JSON (solo lectura)',
            '',
            '- Marcha: ' . $base . '/api/marcha/{id}.json',
            '- Compositor: ' . $base . '/api/autor/{id}.json',
            '- Banda: ' . $base . '/api/banda/{id}.json',
            '- Disco: ' . $base . '/api/disco/{id}.json',
            '',
            'El {id} es el número al final de la URL de cada ficha '
                . '(p. ej. /marcha/consuelo-gitano-330 tiene id 330).',
            '',
            '## Novedades',
            '',
            '- [Feed RSS](' . $base . '/feed.xml)',
            '- [Feed JSON](' . $base . '/feed.json)',
            '',
            '## Índice del sitio',
            '',
            '- [Marchas](' . $base . '/marcha)',
            '- [Compositores](' . $base . '/autor)',
            '- [Bandas](' . $base . '/banda)',
            '- [Discos](' . $base . '/disco)',
            '- [Dedicatorias](' . $base . '/dedicatorias)',
            '- [Rankings](' . $base . '/rankings)',
            '- [Aniversarios](' . $base . '/aniversarios)',
            '- [Mapa](' . $base . '/mapa)',
        ];
        // Temporada oculta en PRO hasta que haya datos de calidad (ver
        // Pages::temporadaVisible) — no listar aquí una URL que la propia web
        // responde con 404.
        if (self::temporadaVisible()) {
            $lines[] = '- [Temporada](' . $base . '/temporada)';
        }
        $lines[] = '- [Mapa del sitio](' . $base . '/sitemap.xml)';
        $lines[] = '';
        echo implode("\n", $lines);
    }
}
