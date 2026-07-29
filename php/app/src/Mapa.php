<?php

declare(strict_types=1);

namespace App;

/**
 * Coropleta SVG de provincias (N-10). El SVG base (assets/mapa-provincias.svg,
 * ver su README de origen/licencia) trae un <g id="ES-XX"> vacío por provincia
 * (código ISO 3166-2:ES) sin relleno; aquí se colorea por nivel de recuento y
 * se enlaza al hub de esa provincia.
 */
final class Mapa
{
    /**
     * Código ISO 3166-2:ES => nombre de provincia, tal cual aparece en el SVG
     * base y coincide con marcha.PROVINCIA/banda.PROVINCIA en la BD (ambos
     * heredan la forma castellana histórica: "La Coruña", "Gerona"…).
     */
    public const PROVINCIAS = [
        'ES-A' => 'Alicante', 'ES-AB' => 'Albacete', 'ES-AL' => 'Almería',
        'ES-AV' => 'Ávila', 'ES-B' => 'Barcelona', 'ES-BA' => 'Badajoz',
        'ES-BI' => 'Vizcaya', 'ES-BU' => 'Burgos', 'ES-C' => 'La Coruña',
        'ES-CA' => 'Cádiz', 'ES-CC' => 'Cáceres', 'ES-CE' => 'Ceuta',
        'ES-CO' => 'Córdoba', 'ES-CR' => 'Ciudad Real', 'ES-CS' => 'Castellón',
        'ES-CU' => 'Cuenca', 'ES-GC' => 'Las Palmas', 'ES-GI' => 'Gerona',
        'ES-GR' => 'Granada', 'ES-GU' => 'Guadalajara', 'ES-H' => 'Huelva',
        'ES-HU' => 'Huesca', 'ES-J' => 'Jaén', 'ES-L' => 'Lérida',
        'ES-LE' => 'León', 'ES-LO' => 'La Rioja', 'ES-LU' => 'Lugo',
        'ES-M' => 'Madrid', 'ES-MA' => 'Málaga', 'ES-ML' => 'Melilla',
        'ES-MU' => 'Murcia', 'ES-NA' => 'Navarra', 'ES-O' => 'Asturias',
        'ES-OR' => 'Orense', 'ES-P' => 'Palencia', 'ES-PM' => 'Baleares',
        'ES-PO' => 'Pontevedra', 'ES-S' => 'Cantabria', 'ES-SA' => 'Salamanca',
        'ES-SE' => 'Sevilla', 'ES-SG' => 'Segovia', 'ES-SO' => 'Soria',
        'ES-SS' => 'Guipúzcoa', 'ES-T' => 'Tarragona', 'ES-TE' => 'Teruel',
        'ES-TF' => 'Santa Cruz de Tenerife', 'ES-TO' => 'Toledo', 'ES-V' => 'Valencia',
        'ES-VA' => 'Valladolid', 'ES-VI' => 'Álava', 'ES-Z' => 'Zaragoza',
        'ES-ZA' => 'Zamora',
    ];

    /**
     * Caja delimitadora (x, y, ancho, alto, en unidades del viewBox "0 0 569
     * 392") de cada <g id="ES-XX"> en mapa-provincias.svg — calculada una vez
     * con getBBox() en un navegador (la geometría del SVG es fija, no cambia
     * entre peticiones). Se usa para encuadrar el zoom de App\Mapa::renderProvincia.
     */
    private const PROVINCIA_BBOX = [
        'ES-A' => [392.86, 208.48, 41.76, 42.96],
        'ES-AB' => [335.98, 188.08, 62.16, 56.88],
        'ES-AL' => [327.82, 249.04, 48.72, 50.16],
        'ES-AV' => [247.66, 117.51, 48.96, 43.44],
        'ES-B' => [464.38, 67.11, 42.96, 45.84],
        'ES-BA' => [194.62, 184.96, 85.92, 61.68],
        'ES-BI' => [318.94, 25.34, 30.47, 19.44],
        'ES-BU' => [292.31, 35.91, 54.47, 70.56],
        'ES-C' => [143.02, 8.31, 52.79, 48.97],
        'ES-CA' => [220.54, 283.13, 44.16, 42.24],
        'ES-CC' => [190.54, 143.68, 80.64, 58.8],
        'ES-CE' => [245.19, 328.97, 21.22, 9.73],
        'ES-CO' => [249.82, 215.68, 50.16, 62.88],
        'ES-CR' => [267.59, 182.09, 76.06, 49.66],
        'ES-CS' => [399.34, 131.92, 47.95, 43.68],
        'ES-CU' => [327.1, 138.4, 62.88, 57.84],
        'ES-GC' => [92.16, 313.12, 85.66, 68.16],
        'ES-GI' => [473.5, 58.72, 48.47, 33.84],
        'ES-GR' => [289.42, 242.56, 68.16, 55.92],
        'ES-GU' => [315.82, 111.51, 61.44, 47.52],
        'ES-H' => [186.94, 234.88, 45.36, 57.84],
        'ES-HU' => [388.31, 46, 57.09, 63.12],
        'ES-J' => [291.1, 224.32, 59.04, 46.56],
        'ES-L' => [432.94, 47.19, 44.88, 64.31],
        'ES-LE' => [210.46, 33.75, 70.56, 48.48],
        'ES-LO' => [328.3, 58.23, 43.68, 29.28],
        'ES-LU' => [183.58, 9.75, 35.76, 58.32],
        'ES-M' => [283.41, 118, 47.28, 51.59],
        'ES-MA' => [247.18, 274.49, 60.24, 38.64],
        'ES-ML' => [322.59, 353.69, 22.53, 10.13],
        'ES-MU' => [353.26, 214.96, 53.28, 55.92],
        'ES-NA' => [347.26, 30.87, 52.56, 56.64],
        'ES-O' => [209.02, 15.75, 78.72, 30.48],
        'ES-OR' => [171.82, 56.55, 48.48, 32.16],
        'ES-P' => [270.17, 41.19, 35.33, 52.56],
        'ES-PM' => [465.09, 152.81, 95.28, 63.84],
        'ES-PO' => [143.59, 44.79, 43.83, 38.64],
        'ES-S' => [277.41, 23.19, 50.41, 30.25],
        'ES-SA' => [211.9, 110.79, 56.4, 42.24],
        'ES-SE' => [218.62, 236.56, 60.24, 54.72],
        'ES-SG' => [279.58, 100.95, 46.32, 38.16],
        'ES-SO' => [315.58, 78.39, 53.76, 43.92],
        'ES-SS' => [335.74, 27.75, 37.93, 20.4],
        'ES-T' => [429.34, 97.83, 44.88, 43.92],
        'ES-TE' => [369.1, 109.35, 64.08, 61.2],
        'ES-TF' => [7.63, 334.72, 80.79, 49.22],
        'ES-TO' => [257.5, 151.84, 77.76, 43.2],
        'ES-V' => [378.47, 156.16, 47.75, 60.97],
        'ES-VA' => [256.53, 70.94, 46.09, 49.47],
        'ES-VI' => [323.75, 35.19, 31.44, 30],
        'ES-Z' => [357.58, 53.43, 78.0, 73.45],
        'ES-ZA' => [210.94, 71.43, 53.76, 47.52],
    ];

    /**
     * Cortes de recuento => nivel de intensidad (1-4; 0 = sin datos, 5 = por
     * encima del último corte). Ajustados a la distribución real del catálogo
     * (muy concentrado en Andalucía: Sevilla sola pasa de 1.300) en vez de una
     * escala lineal, que dejaría todo salvo Sevilla casi en blanco.
     */
    private const CORTES = [1 => 9, 2 => 49, 3 => 149, 4 => 399];

    private static function nivel(int $n): int
    {
        if ($n === 0) {
            return 0;
        }
        foreach (self::CORTES as $nivel => $max) {
            if ($n <= $max) {
                return $nivel;
            }
        }
        return 5;
    }

    /**
     * Cortes de recuento por municipio (1-4), muy distintos de los de
     * provincia: la mayoría de localidades tiene 1-3 marchas, y solo las
     * "ciudades cofrades" grandes (Sevilla, Écija…) llegan a decenas.
     */
    private const CORTES_LOCALIDAD = [1 => 1, 2 => 3, 3 => 8];

    private static function nivelLocalidad(int $n): int
    {
        foreach (self::CORTES_LOCALIDAD as $nivel => $max) {
            if ($n <= $max) {
                return $nivel;
            }
        }
        return 4;
    }

    /**
     * Transformación afín lat/lng → coordenadas del viewBox de mapa-provincias.svg
     * (0 0 569 392), ajustada por mínimos cuadrados entre el centro geográfico
     * real de cada provincia (calculado a partir de app/geo/municipios_es.php)
     * y el centro (bbox) de su <g id="ES-XX"> en el SVG. Error medio ~5.5
     * unidades de las 569×392 en las provincias peninsulares/Baleares/Ceuta/
     * Melilla usadas para calibrar — aproximado, no una proyección cartográfica
     * exacta (el usuario aceptó esta imprecisión a cambio de no depender de
     * mapas/tiles externos). Canarias no se calibra: en este SVG se dibuja
     * como recuadro aparte, fuera de su posición geográfica real.
     * x = AFFINE_X[0]*lng + AFFINE_X[1]*lat + AFFINE_X[2]  (e igual para y)
     */
    private const AFFINE_X = [30.344918062123682, 0.5291605450524717, 401.85804883522326];
    private const AFFINE_Y = [-0.006727446051473969, -40.566595540504814, 1786.7949572953016];

    /**
     * Provincias cuyos municipios NO se pintan como punto: en
     * mapa-provincias.svg Canarias es un recuadro aparte, colocado fuera de su
     * posición geográfica real, así que la transformación afín (calibrada
     * sobre la península + Baleares + Ceuta/Melilla) no vale ahí. Sus
     * coordenadas sí están en la tabla `municipio` — son reales y las usa el
     * panel —, simplemente no se proyectan sobre este SVG.
     */
    public const PROVINCIAS_SIN_GEO = ['Las Palmas', 'Santa Cruz de Tenerife'];

    /** @var array<string,array{0:float,1:float}>|null provincia|localidad normalizados → [lat,lng] */
    private static ?array $municipiosIndex = null;

    /**
     * Índice de coordenadas desde la tabla `municipio` (fuente de verdad; ver
     * app/tools/sql/007_municipio.sql). app/geo/municipios_es.php es solo la
     * semilla de esa tabla, no se lee aquí: si se leyera, los municipios dados
     * de alta desde el panel nunca podrían salir en el mapa.
     *
     * @param list<string> $provincias  provincias que hacen falta (se consulta solo eso)
     * @return array<string,array{0:float,1:float}>
     */
    private static function municipiosIndex(array $provincias): array
    {
        if (self::$municipiosIndex === null) {
            self::$municipiosIndex = [];
        }
        foreach (array_unique($provincias) as $provincia) {
            $cacheKey = '#' . Db::noAcc($provincia);
            if (isset(self::$municipiosIndex[$cacheKey])) {
                continue; // esta provincia ya se cargó
            }
            self::$municipiosIndex[$cacheKey] = [1.0, 1.0]; // centinela de "ya consultada"
            foreach (MunicipioRepo::conCoordenadas($provincia) as $m) {
                $key = Db::noAcc((string) $m['PROVINCIA']) . '|' . Db::noAcc((string) $m['NOMBRE']);
                self::$municipiosIndex[$key] = [(float) $m['LAT'], (float) $m['LNG']];
            }
        }
        return self::$municipiosIndex;
    }

    /**
     * Localidades con coordenadas conocidas, ya proyectadas a x/y del SVG.
     * Se omiten sin más las que no tengan coordenadas en la tabla `municipio`
     * (altas manuales sin lat/lng, variantes de nombre no cubiertas, pedanías)
     * y las de Canarias (ver PROVINCIAS_SIN_GEO) — no hay dónde pintarlas.
     *
     * @param  list<array{LOCALIDAD:string,PROVINCIA:string,N:int}> $porLocalidad  Repo::hubLocalidades()
     * @return list<array{x:float,y:float,localidad:string,provincia:string,n:int}>
     */
    public static function puntos(array $porLocalidad): array
    {
        $porLocalidad = array_values(array_filter(
            $porLocalidad,
            static fn(array $r): bool => !in_array((string) $r['PROVINCIA'], self::PROVINCIAS_SIN_GEO, true)
        ));
        if ($porLocalidad === []) {
            return [];
        }
        $idx = self::municipiosIndex(array_map(static fn(array $r): string => (string) $r['PROVINCIA'], $porLocalidad));
        $out = [];
        foreach ($porLocalidad as $r) {
            $localidad = (string) $r['LOCALIDAD'];
            $provincia = (string) $r['PROVINCIA'];
            $key = Db::noAcc($provincia) . '|' . Db::noAcc($localidad);
            if (!isset($idx[$key])) {
                continue;
            }
            [$lat, $lng] = $idx[$key];
            $out[] = [
                'x' => self::AFFINE_X[0] * $lng + self::AFFINE_X[1] * $lat + self::AFFINE_X[2],
                'y' => self::AFFINE_Y[0] * $lng + self::AFFINE_Y[1] * $lat + self::AFFINE_Y[2],
                'localidad' => $localidad,
                'provincia' => $provincia,
                'n' => (int) $r['N'],
            ];
        }
        return $out;
    }

    /** Radio del punto, fijo (no varía con el recuento — eso ahora lo indica
     *  el color, ver self::nivelLocalidad) y como fracción del ancho del
     *  viewBox visible: un tamaño absoluto en unidades del SVG se ve minúsculo
     *  o gigante según lo grande que sea la provincia recortada. Pequeño a
     *  propósito: con decenas de municipios cercanos, puntos y etiquetas
     *  grandes se solapan y dejan de poder pulsarse. */
    private static function radio(float $viewBoxWidth): float
    {
        return $viewBoxWidth * 0.0055;
    }

    /** Radio del círculo transparente que recibe el clic. Reducido el
     *  2026-07-29 (de 0.018 a 0.012: era un blanco casi 3.3× el punto visible
     *  y se sentía desproporcionado en pantalla, sobre todo en localidades de
     *  nombre largo, donde además el rótulo quedaba dentro del mismo enlace
     *  -ver más abajo, ya corregido-). Con 0.012 sigue siendo ~2.2× el punto
     *  visible: más que los 8 px del punto, pero sin invadir a sus vecinos.
     *  En zonas muy densas las dianas se solapan; la salida es el zoom, que
     *  separa los puntos manteniendo su tamaño aparente (mapa.js: rescalePuntos). */
    private static function radioHit(float $viewBoxWidth): float
    {
        return $viewBoxWidth * 0.012;
    }

    /** Añade la capa de puntos (uno por localidad) a un <svg> ya cargado en
     *  $dom, coloreados por recuento (self::nivelLocalidad) y con el nombre
     *  del municipio rotulado encima — solo para el mapa ampliado de una
     *  provincia (self::renderProvincia); el mapa nacional no pinta puntos.
     *  $viewBoxWidth: ancho del viewBox ya recortado, para dimensionar puntos
     *  y etiquetas en proporción al zoom (ver self::radio). */
    private static function pintarPuntos(\DOMDocument $dom, \DOMElement $svgEl, array $puntos, float $viewBoxWidth): void
    {
        if ($puntos === []) {
            return;
        }
        $fontSize = $viewBoxWidth * 0.015;
        $r = self::radio($viewBoxWidth);
        $rHit = self::radioHit($viewBoxWidth);
        $capa = $dom->createElement('g');
        $capa->setAttribute('class', 'mapa-puntos');
        $capa->setAttribute('style', "--mapa-punto-font: {$fontSize}px");

        // Orden de pintado FIJO, decidido aquí y no al pasar el ratón: los
        // municipios con menos marchas van primero, así los de más recuento
        // (los que más se buscan) quedan por delante donde se solapen. Antes
        // esto se hacía moviendo el <a> en el DOM en cada pointerenter, lo que
        // deja el cursor parpadeando y se come el clic — ver mapa.js.
        usort($puntos, static fn(array $a, array $b): int => $a['n'] <=> $b['n']);

        foreach ($puntos as $p) {
            $cx = (string) round($p['x'], 2);
            $cy = (string) round($p['y'], 2);

            // Diana invisible: el punto visible es deliberadamente pequeño
            // (con un centenar de municipios, puntos grandes se funden unos con
            // otros), pero un blanco de 8 px es imposible de pulsar. Este
            // círculo transparente le da un área cómoda sin ocupar tinta.
            $hit = $dom->createElement('circle');
            $hit->setAttribute('class', 'mapa-punto-hit');
            $hit->setAttribute('cx', $cx);
            $hit->setAttribute('cy', $cy);
            $hit->setAttribute('r', (string) round($rHit, 2));

            $title = $dom->createElement('title');
            $title->appendChild($dom->createTextNode(
                $p['localidad'] . ' (' . $p['provincia'] . '): ' . number_format($p['n'], 0, ',', '.') . ' marcha' . ($p['n'] === 1 ? '' : 's')
            ));
            $hit->appendChild($title);

            $c = $dom->createElement('circle');
            $c->setAttribute('class', 'mapa-punto mapa-punto-n' . self::nivelLocalidad($p['n']));
            $c->setAttribute('cx', $cx);
            $c->setAttribute('cy', $cy);
            $c->setAttribute('r', (string) round($r, 2));

            // Nombre del municipio, siempre visible (ver app.css). No es
            // pulsable: con un centenar de rótulos solapados, pulsar un nombre
            // acabaría llevando al municipio de al lado. El blanco de clic es
            // la diana de arriba.
            $label = $dom->createElement('text');
            $label->setAttribute('class', 'mapa-punto-label');
            $label->setAttribute('x', $cx);
            $label->setAttribute('y', (string) round($p['y'] - $r - $fontSize * 0.35, 2));
            $label->setAttribute('text-anchor', 'middle');
            $label->appendChild($dom->createTextNode($p['localidad']));

            // El rótulo va fuera del <a>: dentro quedaba pulsable pese al
            // comentario de arriba ("no es pulsable"), así que el blanco de
            // clic real era tan ancho como el nombre del municipio, no la
            // diana. Fuera del enlace, el orden de pintado en el <g> no
            // cambia (el SVG pinta por orden de documento, no por anidación),
            // así que no hay diferencia visual.
            $a = $dom->createElement('a');
            $a->setAttribute('href', '/marcha?' . http_build_query(['localidad' => $p['localidad']]));
            $a->appendChild($hit);
            $a->appendChild($c);
            $capa->appendChild($a);
            $capa->appendChild($label);
        }
        $svgEl->appendChild($capa);
    }

    /**
     * Mapa nacional: solo las provincias, coloreadas por recuento y con su
     * nombre, enlazadas a su mapa ampliado (self::renderProvincia, vía
     * Pages::mapaProvinciaPath). Sin puntos de localidad — el desglose por
     * municipio vive en la vista de provincia.
     *
     * @param list<array{K:string,N:int}> $porProvincia  Repo::hubProvincias()
     * @return string  Markup <svg>…</svg> listo para imprimir sin escapar
     *                 (se construye aquí, no viene de entrada de usuario).
     */
    public static function render(array $porProvincia): string
    {
        $conteo = [];
        foreach ($porProvincia as $r) {
            $conteo[(string) $r['K']] = (int) $r['N'];
        }

        $dom = new \DOMDocument();
        $dom->loadXML((string) file_get_contents(PUBLIC_DIR . '/assets/mapa-provincias.svg'));
        $svgEl = $dom->documentElement;

        foreach (iterator_to_array($svgEl->childNodes) as $g) {
            if (!($g instanceof \DOMElement) || $g->nodeName !== 'g') {
                continue;
            }
            $iso = $g->getAttribute('id');
            $nombre = self::PROVINCIAS[$iso] ?? null;
            if ($nombre === null) {
                continue;
            }
            $n = $conteo[$nombre] ?? 0;
            $g->setAttribute('class', 'prov prov-' . self::nivel($n));

            $title = $dom->createElement('title');
            $title->appendChild($dom->createTextNode(
                $nombre . ': ' . number_format($n, 0, ',', '.') . ' marcha' . ($n === 1 ? '' : 's')
            ));
            $g->insertBefore($title, $g->firstChild);

            if ($n > 0) {
                $a = $dom->createElement('a');
                $a->setAttribute('href', Pages::mapaProvinciaPath($nombre));
                $svgEl->replaceChild($a, $g);
                $a->appendChild($g);
            }
        }

        return (string) $dom->saveXML($svgEl);
    }

    /**
     * Mapa ampliado de una sola provincia: recorta el viewBox a su caja
     * delimitadora (con margen) y muestra solo su <g>, en un color de
     * contraste (no la coropleta del mapa nacional, aquí no hay nada que
     * comparar), con los municipios rotulados y clicables (enlazan al
     * buscador filtrado por localidad).
     *
     * @param  list<array{x:float,y:float,localidad:string,provincia:string,n:int}> $puntos  self::puntos(), ya filtrado a esta provincia
     * @return string|null  Markup <svg>…</svg>, o null si $nombreProvincia no es una provincia conocida.
     */
    public static function renderProvincia(string $nombreProvincia, array $puntos): ?string
    {
        $iso = array_search($nombreProvincia, self::PROVINCIAS, true);
        if ($iso === false || !isset(self::PROVINCIA_BBOX[$iso])) {
            return null;
        }

        $dom = new \DOMDocument();
        $dom->loadXML((string) file_get_contents(PUBLIC_DIR . '/assets/mapa-provincias.svg'));
        $svgEl = $dom->documentElement;

        $target = null;
        foreach (iterator_to_array($svgEl->childNodes) as $g) {
            if (!($g instanceof \DOMElement) || $g->nodeName !== 'g') {
                continue;
            }
            if ($g->getAttribute('id') === $iso) {
                $target = $g;
            } else {
                $svgEl->removeChild($g);
            }
        }
        if ($target === null) {
            return null;
        }
        $target->setAttribute('class', 'prov prov-provincia');

        // La etiqueta <text> del nombre viene en unidades absolutas del SVG:
        // al recortar el viewBox a una sola provincia se vería gigante y
        // taparía los puntos. Se quita aquí; el nombre ya está en el <h1>.
        foreach (iterator_to_array($target->getElementsByTagName('text')) as $text) {
            $text->parentNode->removeChild($text);
        }

        // Margen del 12% alrededor de la provincia para que no quede pegada al borde.
        [$x, $y, $w, $h] = self::PROVINCIA_BBOX[$iso];
        $pad = max($w, $h) * 0.12;
        $viewBoxWidth = $w + 2 * $pad;
        $svgEl->setAttribute('viewBox', sprintf('%.2f %.2f %.2f %.2f', $x - $pad, $y - $pad, $viewBoxWidth, $h + 2 * $pad));
        // Marca este SVG para mapa.js (zoom/pan): el mapa nacional no lo lleva.
        $svgEl->setAttribute('data-zoom', '1');

        self::pintarPuntos($dom, $svgEl, $puntos, $viewBoxWidth);

        return (string) $dom->saveXML($svgEl);
    }
}
