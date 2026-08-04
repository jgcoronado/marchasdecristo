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
        return self::botonesEscucha($enlaces);
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
     * sobre lo que se va a oír. Las pestañas son radios + CSS, sin JS.
     *
     * @param array{original: array<string,string>, actual: array<string,string>} $porVersion
     * @param string|null $audio  marcha.AUDIO — su servicio se detecta; sin año
     *                            conocido cae siempre en la versión actual.
     */
    public static function escuchar(array $porVersion, ?string $audio, bool $conVersiones, int $idMarcha): string
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

        if ($original === [] && $actual === []) return '';

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
            . '<div class="vtab-bar">'
            . '<label class="vtab vtab-o" for="' . self::e($n) . '-o">Versión original</label>'
            . '<label class="vtab vtab-a" for="' . self::e($n) . '-a">Versión actual</label>'
            . '</div>'
            . '<div class="vtab-panel vtab-panel-o">'
            . ($original !== []
                ? self::botonesEscucha($original)
                : '<p class="vtab-vacio">Sin grabaciones de la época documentadas.</p>')
            . '</div>'
            . '<div class="vtab-panel vtab-panel-a">'
            . ($actual !== []
                ? self::botonesEscucha($actual)
                : '<p class="vtab-vacio">Sin grabaciones actuales documentadas.</p>')
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
        $out = '<span class="sortby">por página:';
        foreach ($opciones as $i => $opt) {
            $params = array_merge(array_filter($criteria, static fn($v) => (string) $v !== ''), ['limit' => (string) $opt]);
            $href = $basePath . '?' . http_build_query($params);
            $cls = $opt === $limit ? ' class="on"' : '';
            $out .= ($i > 0 ? ' ·' : '') . ' <a href="' . self::e($href) . '"' . $cls . '>' . (int) $opt . '</a>';
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
