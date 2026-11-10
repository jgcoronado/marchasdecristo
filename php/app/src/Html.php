<?php

declare(strict_types=1);

namespace App;

/**
 * Componentes de presentación reutilizables (ports de los componentes React:
 * Pagination, CdList, Timeline, CoverImage). Devuelven HTML como string.
 */
final class Html
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Asegura esquema en una URL externa (banda.WEB y similares). Sin
     * http(s)://, el navegador la trata como ruta relativa y el enlace
     * apunta dentro del propio sitio en vez de salir fuera (visto en
     * producción: marchasdecristo.com/banda/www.amlacena.com).
     */
    public static function externalUrl(?string $url): ?string
    {
        $u = trim((string) $url);
        if ($u === '') return null;
        if (preg_match('~^https?://~i', $u) === 1) return $u;
        if (str_starts_with($u, '//')) return 'https:' . $u;
        return 'https://' . $u;
    }

    // ── Selector Provincia/Localidad (MunicipioRepo) ──────────────────────────
    /**
     * Los dos campos ".field" de Provincia y Localidad, en cascada (elegir
     * provincia habilita/filtra la localidad — ver public/assets/admin.js,
     * data-municipio-picker). El <form> contenedor necesita los atributos
     * data-municipio-picker, data-municipio-admin y data-municipio-csrf — ver
     * self::municipioFormAttrs() — para que el JS los encuentre; estos dos
     * campos no lo son en sí mismos porque envolverlos rompería el
     * .form-grid de dos columnas de los formularios que ya existen.
     */
    public static function municipioFields(string $localidad, ?string $provincia): string
    {
        $opts = '<option value="">— Selecciona una provincia —</option>';
        foreach (MunicipioRepo::provincias() as $p) {
            $sel = $p === $provincia ? ' selected' : '';
            $opts .= '<option value="' . self::e($p) . '"' . $sel . '>' . self::e($p) . '</option>';
        }
        $locVal = self::e($localidad);
        return <<<HTML
        <div class="field">
            <label class="field-label" for="PROVINCIA">Provincia</label>
            <select class="input" id="PROVINCIA" name="PROVINCIA" data-municipio-provincia>{$opts}</select>
        </div>
        <div class="field">
            <label class="field-label" for="LOCALIDAD">Localidad</label>
            <div class="autocomplete">
                <input class="input" id="LOCALIDAD" name="LOCALIDAD" type="text" autocomplete="off"
                       value="{$locVal}" data-municipio-localidad>
                <button type="button" class="autocomplete-clear" data-municipio-clear aria-label="Borrar localidad" title="Borrar localidad" hidden>&times;</button>
                <div class="suggest" hidden data-municipio-suggest></div>
            </div>
            <p class="field-help muted small">Escribe para buscar; si no aparece en la lista, podrás añadirla.</p>
        </div>
        HTML;
    }

    /** Atributos data-* que activan el selector — van en el <form> (o cualquier
     *  ancestro común de los dos campos de self::municipioFields()). */
    public static function municipioFormAttrs(bool $isAdmin, string $csrf): string
    {
        return 'data-municipio-picker data-municipio-admin="' . ($isAdmin ? '1' : '0') . '" data-municipio-csrf="' . self::e($csrf) . '"';
    }

    // ── CoverImage ───────────────────────────────────────────────────────────
    /** Los .webp de las portadas solo viven en el docroot de producción (ver 'cover_base_url'). */
    public static function coverSrc(int $idDisco): string
    {
        return (string) ($GLOBALS['config']['cover_base_url'] ?? '') . '/cover/' . $idDisco . '.webp';
    }

    /**
     * ¿Existe la portada de este disco? true/false cuando las portadas viven en
     * este mismo host (se mira el fichero); null cuando se cargan de otro
     * (cover_base_url, caso de PRE) y no se puede saber sin pedirlas.
     */
    public static function hayPortada(int $idDisco): ?bool
    {
        if ((string) ($GLOBALS['config']['cover_base_url'] ?? '') !== '') return null;
        $raiz = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        return $raiz !== '' ? is_file($raiz . '/cover/' . $idDisco . '.webp') : null;
    }

    /**
     * Funda tipográfica (§2.6): lo que ocupa el cuadrado de una portada que no
     * tenemos. Título del disco y, debajo, banda y año en pequeño. Sustituye al
     * cuadro de inicial + año gigante, que era una cifra en mosaico (§0.5) y no
     * decía de qué disco se trataba.
     */
    public static function funda(string $titulo, string $banda = '', ?int $anio = null): string
    {
        return '<span class="funda" aria-hidden="true"><b>' . self::e($titulo) . '</b>'
            . ($banda !== '' ? '<small>' . self::e($banda) . '</small>' : '')
            . ($anio ? '<small>' . $anio . '</small>' : '')
            . '</span>';
    }

    /** <img> de portada con todo lo que pide §2.6: perezosa, con medidas (sin saltos) y alt. */
    private static function imgPortada(string $src, string $alt, string $onerror = 'this.remove()', int $px = 300): string
    {
        return '<img src="' . self::e($src) . '" alt="' . self::e($alt) . '" loading="lazy" decoding="async"'
            . ' width="' . $px . '" height="' . $px . '" oncontextmenu="return false" onerror="' . self::e($onerror) . '">';
    }

    /**
     * Portada suelta. Con $idDisco y $titulo se pinta dentro de una caja con la
     * funda tipográfica debajo: si la portada no existe (o, en PRE, no carga)
     * queda la funda en vez de un hueco. Sin ellos (llamadas antiguas), la
     * imagen sola, que se oculta si falla.
     */
    public static function cover(string $src, string $alt, string $class = '', ?int $idDisco = null, string $titulo = '', string $banda = '', ?int $anio = null): string
    {
        if ($idDisco === null || $titulo === '') {
            return '<img class="cover-img ' . self::e($class) . '" src="' . self::e($src) . '" alt="' . self::e($alt)
                . '" loading="lazy" decoding="async" width="300" height="300" oncontextmenu="return false" onerror="this.style.display=\'none\'">';
        }
        $hay = self::hayPortada($idDisco);
        return '<span class="cover-box ' . self::e($class) . '">'
            . ($hay === true ? '' : self::funda($titulo, $banda, $anio))
            . ($hay === false ? '' : self::imgPortada($src, $alt))
            . '</span>';
    }

    // ── Identidad visual: mosaico de portadas y chip de estilo ───────────────

    /**
     * Sello: RETIRADO (§2.6). Era un cuadro de año o siglas generado, o sea,
     * una cifra gigante en mosaico (§0.5). Se conserva la firma para que las
     * plantillas que aún lo llaman sigan funcionando; devuelve ''.
     */
    public static function sello(string $grande, string $rotulo = '', string $variante = '', string $extra = ''): string
    {
        return '';
    }

    /** 'CCTT' | 'AM' | otro → clase de variante del sello y del chip. */
    public static function varianteEstilo(?string $estilo): string
    {
        return match ($estilo) { 'CCTT' => 'cctt', 'AM' => 'am', default => '' };
    }

    /** Estilo deducido del nombre breve de una banda ("AM Redención", "BCT …"). */
    public static function varianteBanda(string $nombreBreve): string
    {
        if (preg_match('/^\s*AM\b/u', $nombreBreve) === 1) return 'am';
        if (preg_match('/^\s*(BCT|CCTT|CT)\b/u', $nombreBreve) === 1) return 'cctt';
        return '';
    }

    /** Sigla inicial del nombre breve de una banda ("AM", "BCT"), o sus iniciales. */
    public static function siglaBanda(string $nombreBreve): string
    {
        if (preg_match('/^\s*(AM|BCT|CCTT|CT|BM)\b/u', $nombreBreve, $m) === 1) return $m[1];
        return self::iniciales($nombreBreve);
    }

    /** "Emilio Muñoz Serna" → "EM" (dos primeras palabras con mayúscula inicial). */
    public static function iniciales(string $nombre): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($nombre)) ?: [] as $w) {
            $c = mb_substr($w, 0, 1, 'UTF-8');
            if ($c !== '' && preg_match('/\p{Lu}/u', $c) === 1) $out .= $c;
            if (mb_strlen($out, 'UTF-8') >= 2) break;
        }
        return $out !== '' ? $out : mb_strtoupper(mb_substr(trim($nombre), 0, 1, 'UTF-8'), 'UTF-8');
    }

    /** Chip de estilo enlazado a su hub: la señal de color del catálogo. */
    public static function chipEstilo(?string $estilo, bool $largo = true): string
    {
        $v = self::varianteEstilo($estilo);
        if ($v === '') return '';
        $txt = $largo ? ($estilo === 'CCTT' ? 'Cornetas y tambores' : 'Agrupación musical') : (string) $estilo;
        $href = Pages::estiloHubPath((string) $estilo);
        $attr = 'class="chip-estilo chip-' . $v . '"' . ($largo ? '' : ' title="' . ($estilo === 'CCTT' ? 'Cornetas y tambores' : 'Agrupación musical') . '"');
        return $href !== null ? '<a ' . $attr . ' href="' . self::e($href) . '">' . self::e($txt) . '</a>'
                              : '<span ' . $attr . '>' . self::e($txt) . '</span>';
    }

    /**
     * Una tesela de portada (cuadrada, enlazada al disco). Debajo va siempre la
     * funda tipográfica: si la portada no existe o no carga, la imagen se
     * retira sola y queda la funda, nunca un hueco ni un icono roto. $sub es la
     * banda (o lo que identifique al disco además del título).
     */
    public static function tesela(int $idDisco, string $nombre, ?int $anio, string $sub = ''): string
    {
        $href = Slug::buildDetailPath('disco', $idDisco, $nombre);
        $titulo = $nombre . ($sub !== '' ? ' — ' . $sub : '') . ($anio ? ' (' . $anio . ')' : '');
        $hay = self::hayPortada($idDisco);
        return '<a class="mos' . ($hay === false ? ' mos-sin' : '') . '" href="' . self::e($href) . '" title="' . self::e($titulo) . '">'
            . ($hay === true ? '' : self::funda($nombre, $sub, $anio))
            . ($hay === false ? '' : self::imgPortada(self::coverSrc($idDisco), 'Portada de ' . $nombre))
            . '</a>';
    }

    /**
     * Mosaico de portadas de la cabecera de ficha (§2.6): SOLO portadas reales.
     * Con menos de tres no se pinta: dos cuadros no hacen imagen y una funda
     * tipográfica en mosaico no aporta nada que no diga ya la ficha. En PRE
     * (hayPortada null) se pintan todas y la que falle se retira entera.
     *
     * @param list<array{id:int,nombre:string,anio:?int,sub?:string}> $discos sin repetir
     */
    public static function mosaico(array $discos, int $max = 8, string $pie = ''): string
    {
        $discos = array_values(array_filter($discos, static fn($d) => self::hayPortada((int) $d['id']) !== false));
        $total = count($discos);
        if ($total < 3) return '';
        $ver = array_slice($discos, 0, $max);
        $html = '<div class="mosaico" data-n="' . count($ver) . '">';
        foreach ($ver as $d) {
            $nombre = (string) $d['nombre'];
            $sub = (string) ($d['sub'] ?? '');
            $anio = $d['anio'] ?? null;
            $html .= '<a class="mos" href="' . self::e(Slug::buildDetailPath('disco', (int) $d['id'], $nombre)) . '" title="'
                . self::e($nombre . ($sub !== '' ? ' — ' . $sub : '') . ($anio ? ' (' . $anio . ')' : '')) . '">'
                . self::imgPortada(self::coverSrc((int) $d['id']), 'Portada de ' . $nombre, 'this.parentNode.remove()', 200)
                . '</a>';
        }
        if ($pie !== '') $html .= '<p class="mos-pie">' . self::e($pie) . ($total > $max ? ' (' . $total . ')' : '') . '</p>';
        return $html . '</div>';
    }

    // ── Enlaces de streaming (ficha pública) ─────────────────────────────────
    public const STREAMING_LABELS = [
        'spotify' => 'Spotify', 'apple' => 'Apple Music', 'deezer' => 'Deezer',
        'youtube' => 'YouTube', 'tidal' => 'Tidal', 'amazon' => 'Amazon Music',
    ];

    /**
     * Logotipos monocromos de los servicios (rutas de Simple Icons, CC0, en
     * viewBox 0 0 24 24). Van en línea: ni una petición más ni un tercero antes
     * de que el visitante pulse (§0.8). Deezer sale de simple-icons 9 (lo
     * retiraron después a petición de la marca; la ruta sigue siendo CC0).
     */
    private const STREAMING_ICONOS = [
        'spotify' => 'M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z',
        'apple' => 'M23.994 6.124a9.23 9.23 0 00-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043a5.022 5.022 0 00-1.877-.726 10.496 10.496 0 00-1.564-.15c-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026-.747.043-1.49.123-2.193.4-1.336.53-2.3 1.452-2.865 2.78-.192.448-.292.925-.363 1.408-.056.392-.088.785-.1 1.18 0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.801.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03a12.5 12.5 0 001.57-.1c.822-.106 1.596-.35 2.295-.81a5.046 5.046 0 001.88-2.207c.186-.42.293-.87.37-1.324.113-.675.138-1.358.137-2.04-.002-3.8 0-7.595-.003-11.393zm-6.423 3.99v5.712c0 .417-.058.827-.244 1.206-.29.59-.76.962-1.388 1.14-.35.1-.706.157-1.07.173-.95.045-1.773-.6-1.943-1.536a1.88 1.88 0 011.038-2.022c.323-.16.67-.25 1.018-.324.378-.082.758-.153 1.134-.24.274-.063.457-.23.51-.516a.904.904 0 00.02-.193c0-1.815 0-3.63-.002-5.443a.725.725 0 00-.026-.185c-.04-.15-.15-.243-.304-.234-.16.01-.318.035-.475.066-.76.15-1.52.303-2.28.456l-2.325.47-1.374.278c-.016.003-.032.01-.048.013-.277.077-.377.203-.39.49-.002.042 0 .086 0 .13-.002 2.602 0 5.204-.003 7.805 0 .42-.047.836-.215 1.227-.278.64-.77 1.04-1.434 1.233-.35.1-.71.16-1.075.172-.96.036-1.755-.6-1.92-1.544-.14-.812.23-1.685 1.154-2.075.357-.15.73-.232 1.108-.31.287-.06.575-.116.86-.177.383-.083.583-.323.6-.714v-.15c0-2.96 0-5.922.002-8.882 0-.123.013-.25.042-.37.07-.285.273-.448.546-.518.255-.066.515-.112.774-.165.733-.15 1.466-.296 2.2-.444l2.27-.46c.67-.134 1.34-.27 2.01-.403.22-.043.442-.088.663-.106.31-.025.523.17.554.482.008.073.012.148.012.223.002 1.91.002 3.822 0 5.732z',
        'deezer' => 'M18.81 4.16v3.03H24V4.16h-5.19zM6.27 8.38v3.027h5.189V8.38h-5.19zm12.54 0v3.027H24V8.38h-5.19zM6.27 12.594v3.027h5.189v-3.027h-5.19zm6.271 0v3.027h5.19v-3.027h-5.19zm6.27 0v3.027H24v-3.027h-5.19zM0 16.81v3.029h5.19v-3.03H0zm6.27 0v3.029h5.189v-3.03h-5.19zm6.271 0v3.029h5.19v-3.03h-5.19zm6.27 0v3.029H24v-3.03h-5.19Z',
        'youtube' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        'tidal' => 'M12.012 3.992L8.008 7.996 4.004 3.992 0 7.996 4.004 12l4.004-4.004L12.012 12l-4.004 4.004 4.004 4.004 4.004-4.004L12.012 12l4.004-4.004-4.004-4.004zM16.042 7.996l3.979-3.979L24 7.996l-3.979 3.979z',
        'amazon' => 'M14.8454 9.4083c-1.3907 1.0194-3.405 1.563-5.1424 1.563a9.333 9.333 0 0 1-6.2768-2.3835c-.1313-.117-.0143-.277.1415-.1846a12.693 12.693 0 0 0 6.285 1.6574c1.5384 0 3.2348-.318 4.7917-.9764.2359-.0985.4328.1538.203.324h-.002zm.5784-.6564c-.1784-.2257-1.1753-.1087-1.6225-.0554-.1374.0164-.158-.1026-.0349-.1867.796-.5558 2.0984-.3958 2.2502-.2092.1539.1867-.041 1.4872-.7856 2.1087-.1149.0964-.2236.0451-.1723-.082.1682-.4165.5436-1.3498.3651-1.5754zm-1.5917-4.1702v-.5394c0-.082.0615-.1375.1374-.1375h2.4348c.078 0 .1395.0554.1395.1354v.4636c0 .078-.0656.1805-.1846.3405L15.0997 6.635c.4677-.0102.9641.0595 1.3887.2974.0964.0534.123.1334.1292.2113v.5744c0 .082-.0882.1723-.1784.123a2.8163 2.8163 0 0 0-2.5723.0062c-.0861.0451-.1743-.0451-.1743-.1251v-.5477c0-.0882.002-.238.0902-.3713l1.4626-2.0881h-1.2718c-.078 0-.1415-.0534-.1436-.1354l.002.002zm4.808-.7466c1.0995 0 1.6944.9395 1.6944 2.1333 0 1.1528-.6564 2.0676-1.6943 2.0676-1.079 0-1.6656-.9395-1.6656-2.1087 0-1.1774.5948-2.0922 1.6656-2.0922zm.0062.7713c-.5456 0-.5805.7384-.5805 1.202 0 .4615-.0061 1.4481.5744 1.4481.5743 0 .601-.7958.601-1.282 0-.318-.0144-.6994-.1108-1.001-.082-.2625-.2482-.3671-.4841-.3671zm-6.008 3.3414c-.0493.041-.1395.0451-.1744.0164-.2543-.1949-.4246-.4923-.4246-.4923-.4061.4123-.6954.5374-1.2225.5374-.6215 0-1.1077-.3835-1.1077-1.1486a1.2512 1.2512 0 0 1 .7897-1.2041c.402-.1764.9641-.2072 1.3928-.2564 0 0 .0349-.4615-.0902-.6297a.521.521 0 0 0-.4164-.1908c-.2728 0-.5395.1477-.5928.4328-.0144.082-.0739.1518-.1395.1436L9.945 5.08a.1292.1292 0 0 1-.1108-.1537c.1641-.8657.9498-1.1282 1.6554-1.1282.361 0 .8307.0964 1.1158.3671.359.3344.3262.7795.3262 1.2677v1.1487c0 .3446.1436.4964.279.681.0471.0677.0574.1477-.002.197-.1519.125-.5703.4881-.5703.4881zm-.7467-1.7969v-.16c-.5353 0-1.1015.115-1.1015.7426 0 .318.1662.5333.4513.5333.2051 0 .3938-.1272.5128-.3344.1436-.2564.1374-.4943.1374-.7815zM2.9278 7.948c-.0472.041-.1375.045-.1723.0163-.2544-.1949-.4246-.4923-.4246-.4923-.4082.4123-.6954.5374-1.2226.5374-.6235 0-1.1076-.3835-1.1076-1.1486a1.2512 1.2512 0 0 1 .7897-1.2041c.402-.1764.964-.2072 1.3928-.2564 0 0 .0348-.4615-.0903-.6297a.521.521 0 0 0-.4164-.1908c-.2748 0-.5395.1477-.5928.4328-.0143.082-.0759.1518-.1395.1436L.2345 5.08a.1292.1292 0 0 1-.1087-.1537c.162-.8657.9497-1.1282 1.6553-1.1282.361 0 .8308.0964 1.1159.3671.359.3344.324.7795.324 1.2677v1.1487c0 .3446.1437.4964.279.681.0472.0677.0575.1477-.002.197-.1518.125-.5702.4881-.5702.4881zm-.7446-1.797v-.16c-.5354 0-1.1015.115-1.1015.7426 0 .318.164.5333.4512.5333.2052 0 .3939-.1272.5128-.3344.1436-.2564.1375-.4943.1375-.7815zm2.9127-.3343v2.002a.1379.1379 0 0 1-.1395.1374H4.218a.1374.1374 0 0 1-.1395-.1374v-3.766a.1379.1379 0 0 1 .1395-.1375h.6913a.1374.1374 0 0 1 .1374.1374v.482h.0143c.1805-.4758.519-.6994.9744-.6994.4636 0 .7528.2236.962.6995a1.0523 1.0523 0 0 1 1.0215-.6995c.3118 0 .6502.1272.8574.4143.236.318.1867.7795.1867 1.1857v2.3855c0 .076-.0636.1354-.1436.1354H8.181a.1374.1374 0 0 1-.1334-.1354v-2.004c0-.16.0144-.558-.0205-.7077-.0554-.2564-.2215-.3282-.4369-.3282a.4923.4923 0 0 0-.441.3118c-.076.1908-.0698.5087-.0698.724v2.0041c0 .076-.0635.1354-.1435.1354h-.7385a.1374.1374 0 0 1-.1333-.1354v-2.004c0-.4226.0677-1.042-.4574-1.042-.5334 0-.5128.603-.5128 1.042h.002zm16.8077 2.002a.1374.1374 0 0 1-.1374.1374h-.7405a.1374.1374 0 0 1-.1374-.1374v-3.766a.1374.1374 0 0 1 .1374-.1375h.683c.0821 0 .1396.0636.1396.1067v.5764h.0143c.2051-.517.4964-.7631 1.0092-.7631.3323 0 .6564.119.8636.4451.1928.3036.1928.8123.1928 1.1774V7.837a.1395.1395 0 0 1-.1415.119h-.7426a.1395.1395 0 0 1-.1313-.119V5.552c0-.763-.2933-.7856-.4635-.7856-.197 0-.357.1538-.4246.2953a1.7025 1.7025 0 0 0-.1231.722l.002 2.0349zM.1914 20.0582c-.1271 0-.1907-.0615-.1907-.1907v-4.4491c0-.1272.0636-.1908.1907-.1908H.616c.0616 0 .1129.0144.1477.039.0349.0246.0595.0738.0718.1436l.0575.3035c.6133-.4184 1.2102-.6276 1.7907-.6276.5948 0 .9969.2256 1.2081.6769.6318-.4513 1.2636-.677 1.8954-.677.441 0 .7794.1231 1.0153.3693.236.2502.3549.603.3549 1.0584v3.3538c0 .1271-.0656.1907-.1928.1907h-.5641c-.1272 0-.1928-.0615-.1928-.1907v-3.085c0-.318-.0616-.5539-.1805-.7057-.1231-.1538-.3139-.2297-.5744-.2297-.4677 0-.9353.1436-1.4092.4307a.997.997 0 0 1 .0103.1416v3.448c0 .1272-.0636.1908-.1908.1908H3.297c-.1272 0-.1908-.0615-.1908-.1907v-3.085c0-.318-.0615-.5539-.1825-.7057-.1231-.1538-.3139-.2297-.5744-.2297-.4861 0-.9517.1395-1.399.4205v3.5999c0 .1271-.0615.1907-.1907.1907H.1914zm9.731.1436c-.4533 0-.8-.1272-1.044-.3815-.242-.2544-.3631-.6133-.3631-1.0769v-3.321c0-.1292.0615-.1927.1908-.1927h.564c.1293 0 .1929.0635.1929.1907v3.0215c0 .3425.0656.5948.201.7569.1333.162.3487.242.642.242.4595 0 .923-.1518 1.3887-.4574v-3.565c0-.1272.0615-.1908.1908-.1908h.564c.1293 0 .1929.0636.1929.1908v4.4511c0 .1252-.0636.1887-.1928.1887h-.4103c-.0636 0-.1149-.0123-.1497-.0369-.0349-.0266-.0575-.0738-.0718-.1436l-.0657-.3323c-.5948.437-1.204.6564-1.8297.6564zm5.4399 0c-.5374 0-1.0195-.0882-1.4461-.2666a.3754.3754 0 0 1-.158-.1047c-.0287-.039-.043-.0984-.043-.1805v-.2687c0-.1148.0369-.1723.1148-.1723.0452 0 .1231.0205.238.0575.4225.1333.8615.199 1.3128.199.3138 0 .5517-.0616.7138-.1806.164-.121.244-.2954.244-.523a.4923.4923 0 0 0-.1476-.3734 1.606 1.606 0 0 0-.5415-.285l-.8144-.3037c-.7097-.2605-1.0625-.7056-1.0625-1.3333 0-.4143.16-.7487.484-1.001.3221-.2543.7447-.3815 1.2677-.3815a3.487 3.487 0 0 1 1.2164.2195c.076.0246.1313.0574.1641.0985.0308.041.0472.1025.0472.1846v.2584c0 .1149-.041.1723-.123.1723a.8615.8615 0 0 1-.2216-.0472 3.5495 3.5495 0 0 0-1.0359-.1538c-.6112 0-.919.2072-.919.6195 0 .164.0514.2953.154.3897.1025.0964.3035.201.603.3159l.7466.2872c.3774.1436.6482.318.8144.519.1661.1989.2482.4574.2482.7753 0 .4513-.1682.8102-.5067 1.0769-.3385.2666-.7877.4-1.3497.4v.002zm3.0645-.1436c-.1272 0-.1928-.0615-.1928-.1907v-4.4491c0-.1272.0656-.1908.1928-.1908h.5641c.1272 0 .1928.0636.1928.1908v4.4511c0 .1251-.0656.1887-.1928.1887h-.564zm.2872-5.688c-.1846 0-.3303-.0513-.437-.1559a.558.558 0 0 1-.1579-.4143c0-.1724.0534-.3098.158-.4144a.5907.5907 0 0 1 .4369-.158c.1846 0 .3282.0534.4349.158.1066.1026.1579.242.1579.4144 0 .1702-.0513.3076-.158.4143-.1046.1026-.2502.1559-.4348.1559zm4.002 5.7926c-.7529 0-1.3293-.2133-1.7272-.642-.4-.4307-.599-1.0502-.599-1.8625 0-.8061.2052-1.4318.6175-1.8728.4102-.441.9948-.6625 1.7476-.6625.3446 0 .683.0615 1.0154.1825.0697.0247.119.0554.1477.0944s.043.1026.043.1908v.2564c0 .1271-.041.1907-.123.1907-.0329 0-.082-.0082-.1539-.0287a2.8307 2.8307 0 0 0-.7959-.1128c-.5353 0-.923.1333-1.1589.404s-.3528.6996-.3528 1.2924v.123c0 .5764.119 1.001.359 1.2718.24.2687.6174.404 1.1343.404.2666 0 .5538-.043.8615-.1332.0718-.0206.119-.0288.1436-.0288.082 0 .1251.0636.1251.1908v.2585c0 .082-.0123.1435-.039.1805-.0246.0369-.0759.0718-.1518.1025-.3138.1354-.6769.201-1.0933.201z',
    ];

    /**
     * Botonera "Escuchar en …" a partir de [servicio => url] (ver EnlaceRepo::publicadosDe).
     * Devuelve '' si no hay enlaces, para poder incrustarlo sin comprobaciones extra.
     *
     * @param array<string,string> $enlaces
     */
    public static function streaming(array $enlaces): string
    {
        if ($enlaces === []) return '';
        return self::botonesEscucha($enlaces);
    }

    /**
     * Estado vacío de «Escuchar» (§2.6): ocupa el sitio de los botones y pide
     * la grabación. Las propuestas públicas no admiten aún enlaces de escucha,
     * así que el enlace va a /contacto.
     */
    public static function escucharVacio(string $que = 'esta marcha', string $href = '/contacto'): string
    {
        return '<p class="esc-vacio">Todavía no tenemos ninguna grabación enlazada de ' . self::e($que)
            . '. Si conoces una, <a href="' . self::e($href) . '">propónla</a>.</p>';
    }

    /**
     * Sección "Escuchar" de la ficha de marcha.
     *
     * Todas las escuchas se pintan como el MISMO botón, venga el enlace de
     * `marcha.AUDIO` o de `enlace_streaming`: antes el vídeo de YouTube salía
     * como miniatura grande y el resto como botoncitos, lo que sugería una
     * jerarquía entre servicios que no existe — solo reflejaba de qué columna
     * de la BD había salido cada uno.
     *
     * Cuando la marcha tiene ya sus años ($conVersiones), los enlaces se
     * reparten en dos pestañas: una marcha de 1950 no se toca hoy como se tocó
     * al estrenarse, y mezclar ambas grabaciones en una lista plana engaña
     * sobre lo que se va a oír. Las pestañas son radios + CSS, sin JS, con el
     * aspecto del segmentado (.seg, §2.4): el elegido con fondo, sin subrayado.
     *
     * @param array{original: array<string,string>, actual: array<string,string>} $porVersion
     * @param string|null $audio  marcha.AUDIO — su servicio se detecta; sin año
     *                            conocido cae siempre en la versión actual.
     * @param bool $conVacio  true: sin enlaces devuelve el estado vacío
     *                        (escucharVacio) en vez de ''.
     */
    public static function escuchar(array $porVersion, ?string $audio, bool $conVersiones, int $idMarcha, bool $conVacio = false): string
    {
        $original = $porVersion['original'] ?? [];
        $actual = $porVersion['actual'] ?? [];

        // marcha.AUDIO no lleva año de grabación, así que se le asigna la
        // versión actual. Si ya hay un enlace curado del mismo servicio, gana
        // AUDIO: es el que un humano puso a mano en la ficha.
        $audio = trim((string) $audio);
        if ($audio !== '' && preg_match('~^https?://~i', $audio) === 1) {
            // Servicio no reconocido: se enseña igual, con etiqueta neutra.
            $svc = Media::embedDeUrl($audio)['servicio'] ?? 'otro';
            $actual = [$svc => $audio] + $actual;
        }

        if ($original === [] && $actual === []) return $conVacio ? self::escucharVacio() : '';

        // Sin antigüedad suficiente no hay pestañas: serían dos, una vacía,
        // para no decir nada.
        if (!$conVersiones) {
            // La actual gana cuando el mismo servicio está en las dos.
            return '<div class="listen">' . self::botonesEscucha($actual + $original) . '</div>';
        }

        $n = 'ver-m' . $idMarcha;
        // Se abre en "actual" cuando no hay ninguna grabación de época: abrir en
        // una pestaña vacía sería enseñar un hueco como primera impresión.
        $abreOriginal = $original !== [];

        return '<div class="listen"><div class="vtabs">'
            . '<input class="vtab-in vtab-in-o" type="radio" name="' . self::e($n) . '" id="' . self::e($n) . '-o"'
                . ($abreOriginal ? ' checked' : '') . '>'
            . '<input class="vtab-in vtab-in-a" type="radio" name="' . self::e($n) . '" id="' . self::e($n) . '-a"'
                . ($abreOriginal ? '' : ' checked') . '>'
            . '<div class="seg vtab-bar">'
            . '<label class="seg-op vtab vtab-o" for="' . self::e($n) . '-o">Versión original</label>'
            . '<label class="seg-op vtab vtab-a" for="' . self::e($n) . '-a">Versión actual</label>'
            . '</div>'
            . '<div class="vtab-panel vtab-panel-o">'
            . ($original !== []
                ? self::botonesEscucha($original)
                : '<p class="esc-vacio vtab-vacio">Sin grabaciones de la época documentadas.</p>')
            . '</div>'
            . '<div class="vtab-panel vtab-panel-a">'
            . ($actual !== []
                ? self::botonesEscucha($actual)
                : '<p class="esc-vacio vtab-vacio">Sin grabaciones actuales documentadas.</p>')
            . '</div>'
            . '</div></div>';
    }

    /** @param array<string,string> $enlaces [servicio => url] */
    private static function botonesEscucha(array $enlaces): string
    {
        // Orden canónico de servicios (el de las etiquetas), con los no
        // reconocidos al final. Así las dos pestañas presentan los mismos
        // servicios en la misma posición.
        $ordenado = [];
        foreach (array_keys(self::STREAMING_LABELS) as $s) {
            if (isset($enlaces[$s])) $ordenado[$s] = $enlaces[$s];
        }
        foreach ($enlaces as $s => $url) {
            if (!isset($ordenado[$s])) $ordenado[$s] = $url;
        }

        $btns = '';
        foreach ($ordenado as $servicio => $url) {
            $label = self::STREAMING_LABELS[$servicio]
                ?? ($servicio === 'otro' ? 'Escuchar' : ucfirst((string) $servicio));
            // Servicio sin logotipo: un triángulo de «reproducir» neutro.
            $ruta = self::STREAMING_ICONOS[$servicio] ?? 'M6 3.5v17l14-8.5z';
            $btns .= '<a class="stream-btn stream-' . self::e($servicio) . '" href="' . self::e($url)
                . '" target="_blank" rel="noopener noreferrer nofollow">'
                . '<svg class="stream-ico" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="' . $ruta . '"/></svg>'
                . '<span>' . self::e($label) . '</span></a>';
        }
        // El rótulo queda para lectores de pantalla: la sección ya se titula «Escuchar».
        return '<div class="streaming esc" role="group" aria-label="Escuchar en"><span class="streaming-lbl">Escuchar en</span>' . $btns . '</div>';
    }

    // ── Pagination ───────────────────────────────────────────────────────────
    /**
     * $includeLimit=false omite el parámetro `limit` de las URLs (hubs con tamaño
     * de página fijo: así solo existe una URL por página, sin variantes duplicadas).
     */
    public static function pagination(int $currentPage, int $totalRows, int $limit, string $basePath, array $criteria, bool $includeLimit = true): string
    {
        $totalPages = (int) ceil($totalRows / $limit);
        if ($totalPages <= 1) return '';

        $url = static function (int $page) use ($basePath, $criteria, $limit, $includeLimit): string {
            $params = array_merge($criteria, ['page' => (string) $page]);
            if ($includeLimit) {
                $params['limit'] = (string) $limit;
            }
            return $basePath . '?' . http_build_query($params);
        };

        // Clases pg-*: r/controles.css. «pg-lejos» marca los números que no son
        // ni el actual ni sus extremos: en móvil se ocultan para que la fila
        // quepa en 360 px sin desbordar (Anterior · 1 … 7 … 40 · Siguiente).
        $out = '<nav class="pagination" aria-label="Paginación">';
        if ($currentPage > 1) {
            $out .= '<a class="btn btn-sm btn-ghost pg-prev" href="' . self::e($url($currentPage - 1)) . '" rel="prev">‹ Anterior</a>';
        }
        foreach (self::pageList($currentPage, $totalPages) as $p) {
            if ($p === '…') {
                $out .= '<span class="ellipsis" aria-hidden="true">…</span>';
            } elseif ($p === $currentPage) {
                $out .= '<a class="btn btn-sm btn-neutral pg-cur" href="' . self::e($url((int) $p)) . '" aria-current="page">' . $p . '</a>';
            } else {
                $lejos = $p !== 1 && $p !== $totalPages ? ' pg-lejos' : '';
                $out .= '<a class="btn btn-sm btn-ghost pg-num' . $lejos . '" href="' . self::e($url((int) $p)) . '">' . $p . '</a>';
            }
        }
        if ($currentPage < $totalPages) {
            $out .= '<a class="btn btn-sm btn-ghost pg-next" href="' . self::e($url($currentPage + 1)) . '" rel="next">Siguiente ›</a>';
        }
        return $out . '</nav>';
    }

    /**
     * Selector de resultados por página, como enlaces en la barra de la lista.
     * Antes era un <select> dentro del formulario de búsqueda avanzada: cambiar
     * cuántos resultados ver obligaba a desplegar el panel y enviar el
     * formulario, cuando no es un criterio de búsqueda sino una preferencia de
     * visualización. Va al lado de "orden", que es de la misma naturaleza.
     *
     * @param array<string,string> $criteria criterios activos ('limit' y 'page' NO viajan aquí)
     */
    public static function porPagina(int $limit, string $basePath, array $criteria, array $opciones = [10, 20, 50]): string
    {
        // Control segmentado .seg (contrato en r/controles.css); .sortby se
        // conserva por compatibilidad con las hojas antiguas y catalog.js.
        $out = '<span class="sortby seg"><span class="lbl">por página</span>';
        foreach ($opciones as $opt) {
            $params = array_merge(array_filter($criteria, static fn($v) => (string) $v !== ''), ['limit' => (string) $opt]);
            $href = $basePath . '?' . http_build_query($params);
            $cls = $opt === $limit ? ' class="on" aria-current="true"' : '';
            $out .= '<a href="' . self::e($href) . '"' . $cls . '>' . (int) $opt . '</a>';
        }
        return $out . '</span>';
    }

    /** @return list<int|string> */
    private static function pageList(int $current, int $total): array
    {
        if ($total <= 7) return range(1, $total);
        $pages = [1];
        if ($current > 3) $pages[] = '…';
        for ($p = max(2, $current - 1); $p <= min($total - 1, $current + 1); $p++) $pages[] = $p;
        if ($current < $total - 2) $pages[] = '…';
        $pages[] = $total;
        return $pages;
    }

    // ── CdList ───────────────────────────────────────────────────────────────
    public static function cdList(array $disco): string
    {
        $coverSrc = self::coverSrc((int) $disco['ID_DISCO']);
        $discoPath = Slug::buildDetailPath('disco', $disco['ID_DISCO'], (string) $disco['NOMBRE_CD']);
        $hasBanda = !empty($disco['ID_BANDA']) && !empty($disco['BANDA']);
        $bandaPath = $hasBanda ? Slug::buildDetailPath('banda', $disco['ID_BANDA'], (string) $disco['BANDA']) : null;

        if ($hasBanda) {
            $sub = '<a class="link" href="' . self::e($bandaPath) . '">' . self::e($disco['BANDA']) . '</a>';
        } elseif (!empty($disco['DISCOS']) && (int) $disco['DISCOS'] > 1) {
            $sub = self::e($disco['DISCOS']) . ' CDs, ' . self::e($disco['PISTAS'] ?? '') . ' marchas';
        } else {
            $sub = self::e($disco['PISTAS'] ?? '') . ' marchas';
        }
        $anio = (int) ($disco['FECHA_CD'] ?? 0);

        return '<ul class="cdlist">'
            . '<li class="cdlist-row">'
            . '<div class="cdlist-cover"><a href="' . self::e($discoPath) . '">'
            . self::cover($coverSrc, "Portada del disco '" . $disco['NOMBRE_CD'] . "'", 'cover-thumb',
                (int) $disco['ID_DISCO'], (string) $disco['NOMBRE_CD'], $hasBanda ? (string) $disco['BANDA'] : '', $anio > 1800 ? $anio : null)
            . '</a></div>'
            . '<div class="cdlist-main"><a class="cdlist-title link" href="' . self::e($discoPath) . '">'
            . self::e($disco['NOMBRE_CD']) . '</a>'
            . '<div class="cdlist-sub">' . $sub . '</div></div>'
            . '<div class="cdlist-date">' . self::e($disco['FECHA_CD']) . '</div>'
            . '</li></ul>';
    }

    // ── Timeline (banda) ─────────────────────────────────────────────────────
    public static function timeline(array $banda): string
    {
        $fund = (int) ($banda['FECHA_FUND'] ?? 0);
        $ext = $banda['FECHA_EXT'] ?? null;
        $path = Slug::buildDetailPath('banda', $banda['ID_BANDA'], (string) $banda['NOMBRE_BREVE']);
        $endLabel = $ext !== null ? self::e($ext) : 'Hoy';

        $out = '<ul class="timeline">';
        $out .= '<li><span class="tl-date">' . ($fund > 1800 ? $fund : 's/f') . '</span>'
            . '<span class="tl-dot"></span>'
            . '<span class="tl-box"><a class="link" href="' . self::e($path) . '">' . self::e($banda['NOMBRE_BREVE']) . '</a></span></li>';
        $out .= '<li><span class="tl-date">' . $endLabel . '</span>'
            . '<span class="tl-dot"></span>'
            . ($ext !== null ? '<span class="tl-box">Desaparece la banda</span>' : '') . '</li>';
        return $out . '</ul>';
    }

    // ── Linaje (árbol genealógico de la banda) ───────────────────────────────
    private const LN_CARD = 170;   // ancho de tarjeta (px), debe coincidir con .ln-card en app.css
    private const LN_GAP = 22;     // separación entre tarjetas (px)
    private const LN_SIDE = 210;   // reserva lateral para juveniles/madre (px)

    private static function lnYear(mixed $v): int
    {
        return (int) $v;
    }

    /** "1978–1986", "1991–", "s/f–2000"… */
    private static function lnYears(mixed $fund, mixed $ext): string
    {
        $f = self::lnYear($fund);
        $e = self::lnYear($ext);
        $s = $f > 1800 ? (string) $f : 's/f';
        return $e > 1800 ? $s . '–' . $e : $s . '–';
    }

    private static function lnCard(array $n, bool $focus = false, string $extra = ''): string
    {
        $years = self::lnYears($n['FUND'] ?? null, $n['EXT'] ?? null);
        $name = self::e($n['NOMBRE'] ?? '');
        if ($focus) {
            $inner = '<span class="ln-name">' . $name . '</span>';
        } else {
            $path = Slug::buildDetailPath('banda', $n['ID'], (string) ($n['NOMBRE'] ?? ''));
            $inner = '<a class="ln-name" href="' . self::e($path) . '">' . $name . '</a>';
        }
        $chip = '';
        if (!$focus && isset($n['TIPO'])) {
            $labels = ['renombrado' => 'Renombrada', 'fusion' => 'Fusión', 'division' => 'División'];
            $chip = '<span class="ln-chip ' . self::e($n['TIPO']) . '">' . ($labels[$n['TIPO']] ?? self::e($n['TIPO'])) . '</span>';
        }
        return '<div class="ln-card ' . ($focus ? 'is-focus' : '') . ' ' . $extra . '">'
            . $chip . $inner . '<span class="ln-yr">' . self::e($years) . '</span></div>';
    }

    /** Centros x (px) de las tarjetas de una fila de $n elementos, centrada en $w. */
    private static function lnCenters(int $n, int $w): array
    {
        $rowW = $n * self::LN_CARD + max(0, $n - 1) * self::LN_GAP;
        $left = ($w - $rowW) / 2;
        $c = [];
        for ($k = 0; $k < $n; $k++) {
            $c[] = $left + $k * (self::LN_CARD + self::LN_GAP) + self::LN_CARD / 2;
        }
        return $c;
    }

    /** Conector SVG entre una fila superior y una inferior; color según el tipo de arista. */
    private static function lnBracket(int $nUpper, int $nLower, string $tipo, int $w): string
    {
        $h = 34;
        $rail = 17;
        $cu = self::lnCenters($nUpper, $w);
        $cl = self::lnCenters($nLower, $w);
        $lines = '';
        foreach ($cu as $x) $lines .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $rail . '"/>';
        foreach ($cl as $x) $lines .= '<line x1="' . $x . '" y1="' . $rail . '" x2="' . $x . '" y2="' . $h . '"/>';
        $all = array_merge($cu, $cl);
        if (count($all) > 1) {
            $lines .= '<line x1="' . min($all) . '" y1="' . $rail . '" x2="' . max($all) . '" y2="' . $rail . '"/>';
        }
        $cls = in_array($tipo, ['renombrado', 'fusion', 'division'], true) ? $tipo : 'renombrado';
        return '<svg class="ln-svg ' . $cls . '" width="' . $w . '" height="' . $h . '" aria-hidden="true">' . $lines . '</svg>';
    }

    /** Tipo dominante de una fila (para colorear el conector): fusión > división > renombrado. */
    private static function lnRowTipo(array $nodes): string
    {
        $t = array_column($nodes, 'TIPO');
        if (in_array('fusion', $t, true)) return 'fusion';
        if (in_array('division', $t, true)) return 'division';
        return 'renombrado';
    }

    private static function lnRow(array $nodes): string
    {
        $cards = '';
        foreach ($nodes as $n) $cards .= self::lnCard($n);
        return '<div class="ln-row">' . $cards . '</div>';
    }

    /**
     * Árbol de linaje de la banda. $l viene de Repo::bandaLinaje().
     * @param array{focus:array,up:list<list<array>>,down:list<list<array>>,juveniles:list<array>,madres:list<array>} $l
     */
    public static function linaje(array $l): string
    {
        $up = $l['up'];
        $down = $l['down'];
        $focus = $l['focus'];
        $juv = $l['juveniles'];
        $mad = $l['madres'];

        // Ancho del contenedor: la fila más ancha; y reserva lateral si hay juveniles/madre.
        $w = self::LN_CARD;
        foreach (array_merge($up, $down) as $lvl) {
            $w = max($w, count($lvl) * self::LN_CARD + (count($lvl) - 1) * self::LN_GAP);
        }
        if ($juv !== [] || $mad !== []) $w = max($w, self::LN_CARD + 2 * self::LN_SIDE);
        $w = (int) $w;

        $out = '';

        // Predecesoras: de la más antigua (arriba) a las inmediatas, con conector a la de abajo.
        for ($i = count($up) - 1; $i >= 0; $i--) {
            $out .= self::lnRow($up[$i]);
            $lowerN = $i > 0 ? count($up[$i - 1]) : 1;
            $out .= self::lnBracket(count($up[$i]), $lowerN, self::lnRowTipo($up[$i]), $w);
        }

        // Fila del foco (con juveniles a la derecha y madre a la izquierda).
        $sideR = '';
        foreach ($juv as $j) {
            $sideR .= '<span class="ln-ylink"><span class="ln-dash"></span><span class="ln-ytag">juvenil</span></span>'
                . self::lnCard(['ID' => $j['ID_BANDA'], 'NOMBRE' => $j['NOMBRE_BREVE'], 'LOC' => $j['LOCALIDAD'],
                    'FUND' => $j['FECHA_INICIO'], 'EXT' => $j['FECHA_FIN']], false, 'is-youth');
        }
        $sideL = '';
        foreach ($mad as $m) {
            $sideL .= self::lnCard(['ID' => $m['ID_BANDA'], 'NOMBRE' => $m['NOMBRE_BREVE'], 'LOC' => $m['LOCALIDAD'],
                    'FUND' => $m['FECHA_INICIO'], 'EXT' => $m['FECHA_FIN']], false, 'is-youth')
                . '<span class="ln-ylink"><span class="ln-ytag">juvenil de</span><span class="ln-dash"></span></span>';
        }
        $focusNode = ['ID' => $focus['ID_BANDA'], 'NOMBRE' => $focus['NOMBRE_BREVE'],
            'FUND' => $focus['FECHA_FUND'], 'EXT' => $focus['FECHA_EXT']];
        $out .= '<div class="ln-focusrow">'
            . '<div class="ln-side ln-side-left">' . $sideL . '</div>'
            . self::lnCard($focusNode, true)
            . '<div class="ln-side ln-side-right">' . $sideR . '</div>'
            . '</div>';

        // Sucesoras: de las inmediatas hacia abajo.
        for ($i = 0; $i < count($down); $i++) {
            $upperN = $i === 0 ? 1 : count($down[$i - 1]);
            $out .= self::lnBracket($upperN, count($down[$i]), self::lnRowTipo($down[$i]), $w);
            $out .= self::lnRow($down[$i]);
        }

        return '<div class="lin"><div class="lin-inner" style="width:' . $w . 'px">' . $out . '</div></div>';
    }
}
