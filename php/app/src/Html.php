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
    /** Los .png de las portadas solo viven en el docroot de producción (ver 'cover_base_url'). */
    public static function coverSrc(int $idDisco): string
    {
        return (string) ($GLOBALS['config']['cover_base_url'] ?? '') . '/cover/' . $idDisco . '.png';
    }

    public static function cover(string $src, string $alt, string $class = ''): string
    {
        // JS mínimo: oculta la imagen si no existe y desactiva el menú contextual.
        return '<img class="' . self::e($class) . '" src="' . self::e($src) . '" alt="' . self::e($alt)
            . '" oncontextmenu="return false" onerror="this.style.display=\'none\'">';
    }

    // ── Enlaces de streaming (ficha pública) ─────────────────────────────────
    public const STREAMING_LABELS = [
        'spotify' => 'Spotify', 'apple' => 'Apple Music', 'deezer' => 'Deezer',
        'youtube' => 'YouTube', 'tidal' => 'Tidal', 'amazon' => 'Amazon Music',
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
        $btns = '';
        foreach ($enlaces as $servicio => $url) {
            $label = self::STREAMING_LABELS[$servicio] ?? ucfirst($servicio);
            $btns .= '<a class="stream-btn stream-' . self::e($servicio) . '" href="' . self::e($url)
                . '" target="_blank" rel="noopener noreferrer nofollow">' . self::e($label) . '</a>';
        }
        return '<div class="streaming"><span class="streaming-lbl">Escuchar en</span>' . $btns . '</div>';
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

        $out = '<nav class="pagination">';
        if ($currentPage > 1) {
            $out .= '<a class="btn btn-sm btn-ghost" href="' . self::e($url($currentPage - 1)) . '">‹ Anterior</a>';
        }
        foreach (self::pageList($currentPage, $totalPages) as $p) {
            if ($p === '…') {
                $out .= '<span class="ellipsis">…</span>';
            } else {
                $cls = $p === $currentPage ? 'btn btn-sm btn-neutral' : 'btn btn-sm btn-ghost';
                $out .= '<a class="' . $cls . '" href="' . self::e($url((int) $p)) . '">' . $p . '</a>';
            }
        }
        if ($currentPage < $totalPages) {
            $out .= '<a class="btn btn-sm btn-ghost" href="' . self::e($url($currentPage + 1)) . '">Siguiente ›</a>';
        }
        return $out . '</nav>';
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

        return '<ul class="cdlist">'
            . '<li class="cdlist-row">'
            . '<div class="cdlist-cover"><a href="' . self::e($discoPath) . '">'
            . self::cover($coverSrc, "Portada del disco '" . $disco['NOMBRE_CD'] . "'", 'cover-thumb') . '</a></div>'
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
        $endLabel = ($ext !== null && (int) $ext !== 0) ? self::e($ext) : 'Hoy';

        $out = '<ul class="timeline">';
        $out .= '<li><span class="tl-date">' . ($fund > 1800 ? $fund : 's/f') . '</span>'
            . '<span class="tl-dot"></span>'
            . '<span class="tl-box"><a class="link" href="' . self::e($path) . '">' . self::e($banda['NOMBRE_BREVE']) . '</a></span></li>';
        $out .= '<li><span class="tl-date">' . $endLabel . '</span>'
            . '<span class="tl-dot"></span>'
            . (($ext !== null && (int) $ext !== 0) ? '<span class="tl-box">Desaparece la banda</span>' : '') . '</li>';
        return $out . '</ul>';
    }

    // ── Linaje (árbol genealógico de la banda) ───────────────────────────────
    private const LN_CARD = 170;   // ancho de tarjeta (px), debe coincidir con .ln-card en app.css
    private const LN_GAP = 22;     // separación entre tarjetas (px)
    private const LN_SIDE = 210;   // reserva lateral para juveniles/madre (px)

    private static function lnYear(mixed $v): int
    {
        return ($v !== null && $v !== '') ? (int) (float) $v : 0;
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
