<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Carga los acompañamientos históricos de UNA hermandad de Málaga a partir de
 * su etiqueta en malagamusical.blogspot.com. La usan el script de consola
 * php/app/tools/cargar_acompanamientos_malaga_blog.php y el panel
 * /dashboard/acompanamientos-malaga-blog (solo local).
 *
 * El blog publica una entrada por hermandad bajo /search/label/<etiqueta> con
 * el cuerpo en <div class='post-body entry-content'>: bloques encabezados por
 * el tipo de paso ("Cruz de Guía", "Cristo", "Virgen"...) seguidos de líneas
 * "AAAA - Banda" o "AAAA/BBBB - Banda". Reglas de negocio (decididas por el
 * usuario el 2026-09-24):
 *   - Rango AAAA/BBBB se expande año a año, desde 1980 inclusive. 2020/2021
 *     se cargan como cualquier otro año.
 *   - Solo se registran Agrupación Musical (AM) y Cornetas y Tambores (CCTT):
 *     se descarta por TIPO DE BANDA, no por tipo de paso.
 *   - La Cruz de Guía SÍ se registra (paso ES_CRUZ_GUIA=1, ORDEN=0).
 *   - Etiqueta del blog → hermandad y cabecera de bloque → paso viven en
 *     php/data/malaga_blog_mapeo.json. Si falta algo, analizar() devuelve
 *     listo=false y aplicar() se niega a escribir.
 *   - Resolución de banda contra `banda` por nombre sin prefijo de tipo +
 *     localidad entre paréntesis (ver resolverBanda()); alias manuales y
 *     ambigüedades conocidas en php/data/malaga_blog_banda_alias.json. Si no
 *     es inequívoco NO se enlaza: va a `acompanamiento_pendiente` como texto.
 *   - Idempotente. Si para un paso+año la BD ya tiene OTRA banda, no se toca
 *     nada: sale como conflicto.
 *
 * FUENTE de lo insertado = la URL exacta de la etiqueta.
 */
final class MalagaBlogImporter
{
    public const LOCALIDAD = 'Malaga';
    public const ANIO_MINIMO = 1980;
    public const DIA_SIN_ASIGNAR = 'Sin asignar';

    private const YEAR_RE = '/^(\d{4})(?:\s*\/\s*(\d{4}))?\s*-\s*(.+)$/u';

    public static function mapeoPath(): string
    {
        return DATA_DIR . '/malaga_blog_mapeo.json';
    }

    public static function aliasPath(): string
    {
        return DATA_DIR . '/malaga_blog_banda_alias.json';
    }

    /** @return array<string,mixed> */
    private static function leerJson(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException("No se pudo leer $path");
        }
        return $data;
    }

    /** @return array{0:string,1:string} [etiqueta, slug de la etiqueta] */
    public static function etiquetaDeUrl(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $etiqueta = urldecode(basename(rtrim($path, '/')));
        if ($host !== 'malagamusical.blogspot.com' || $etiqueta === '' || !str_contains($path, '/label/')) {
            throw new RuntimeException("La URL no parece una etiqueta de malagamusical.blogspot.com ($url)");
        }
        return [$etiqueta, Slug::slugify($etiqueta)];
    }

    /**
     * Añade o reemplaza la entrada de una etiqueta en malaga_blog_mapeo.json.
     * @param array<string,mixed> $entrada
     */
    public static function guardarEntradaMapeo(string $labelSlug, array $entrada): void
    {
        $mapeo = self::leerJson(self::mapeoPath());
        $mapeo[$labelSlug] = $entrada;
        $json = json_encode($mapeo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo codificar el mapeo: ' . json_last_error_msg());
        }
        if (file_put_contents(self::mapeoPath(), $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir ' . self::mapeoPath() . ': ' . (error_get_last()['message'] ?? '?'));
        }
    }

    // ─────────────────────────── descarga y extracción ───────────────────────

    public static function fetchUrl(string $url): string
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        if (function_exists('curl_init')) {
            // PHP en Windows no trae curl.cainfo configurado, así que curl no
            // encuentra el emisor del certificado. En vez de desactivar la
            // verificación se prueba con el bundle de CA de Git for Windows.
            $caCandidatas = [
                'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
                'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_TIMEOUT => 30,
            ]);
            $body = curl_exec($ch);
            if ($body === false && str_contains(curl_error($ch), 'certificate')) {
                foreach ($caCandidatas as $ca) {
                    if (!is_file($ca)) {
                        continue;
                    }
                    curl_setopt($ch, CURLOPT_CAINFO, $ca);
                    $body = curl_exec($ch);
                    if ($body !== false) {
                        break;
                    }
                }
            }
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException("curl falló ($err)");
            }
            if ($code !== 200) {
                throw new RuntimeException("HTTP $code al descargar $url");
            }
            return (string) $body;
        }
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 30]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException("No se pudo descargar $url");
        }
        return $body;
    }

    /** Extrae el texto de <div class='post-body entry-content'>, una línea por elemento de bloque. */
    public static function extraerTexto(string $html): string
    {
        if (!preg_match(
            '/<div class=([\'"])post-body entry-content\1[^>]*>(.*?)<div class=([\'"])post-footer\3/s',
            $html,
            $m
        )) {
            throw new RuntimeException("No se encuentra <div class='post-body entry-content'> en la página");
        }
        $frag = $m[2];
        $frag = preg_replace('/<br\s*\/?>/i', "\n", $frag) ?? $frag;
        $frag = preg_replace('/<\/(p|div|li)>/i', "\n", $frag) ?? $frag;
        $text = strip_tags($frag);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> líneas no vacías, recortadas. */
    public static function lineasDe(string $text): array
    {
        $lineas = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lineas as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l) ?? $l);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    /**
     * Agrupa las líneas en bloques [cabecera, líneas de año]. Una cabecera sin
     * ninguna línea de año detrás (p.ej. un título general como "Cruz de Guía y
     * Cristo" seguido de la cabecera real "Cruz de Guía") se descarta sola.
     *
     * @param list<string> $lineas
     * @return list<array{cabecera:string, lineas:list<string>, orfanas:list<string>}>
     */
    public static function agruparBloques(array $lineas): array
    {
        $bloques = [];
        $cabecera = null;
        $actuales = [];
        $orfanas = [];
        foreach ($lineas as $l) {
            // Cualquier línea que empiece por dígito es de año, aunque venga mal
            // escrita ("201872019- ..." en Salud): así no se toma por cabecera y
            // analizar() la enseña como línea no reconocida.
            if (preg_match('/^\d/', $l)) {
                if ($cabecera === null) {
                    $orfanas[] = $l;
                } else {
                    $actuales[] = $l;
                }
                continue;
            }
            if ($cabecera !== null && $actuales !== []) {
                $bloques[] = ['cabecera' => $cabecera, 'lineas' => $actuales, 'orfanas' => []];
            }
            $cabecera = $l;
            $actuales = [];
        }
        if ($cabecera !== null && $actuales !== []) {
            $bloques[] = ['cabecera' => $cabecera, 'lineas' => $actuales, 'orfanas' => []];
        }
        if ($orfanas !== []) {
            $bloques[] = ['cabecera' => '(sin cabecera)', 'lineas' => [], 'orfanas' => $orfanas];
        }
        return $bloques;
    }

    /** Clave de una cabecera de bloque en pasos[] del mapeo ("Cruz de Guía" → "cruz de guia"). */
    public static function claveCabecera(string $cabecera): string
    {
        return str_replace('-', ' ', Slug::slugify(mb_strtolower(trim($cabecera), 'UTF-8')));
    }

    // ─────────────────────────── clasificación de banda ──────────────────────

    /**
     * Normaliza el texto de banda que se guarda en acompanamiento_pendiente.BANDA_TEXTO
     * (y con el que se compara/agrupa): quita el punto final y colapsa espacios.
     * A propósito NO hace más que esto — no unifica "C.T." con "Banda de Cornetas
     * y Tambores" ni nada parecido: eso lo decide el admin al enlazar en el panel.
     */
    public static function normalizarBandaTexto(string $t): string
    {
        $t = trim($t);
        if (str_ends_with($t, '.')) {
            $t = rtrim(substr($t, 0, -1));
        }
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /** @return array{0:string,1:?string} [core sin prefijo de tipo, localidad|null] */
    public static function normalizarBanda(string $raw): array
    {
        $t = rtrim(trim($raw), ". \t");
        $localidad = null;
        if (preg_match('/\(([^)]+)\)\s*$/u', $t, $m)) {
            $localidad = trim(explode(',', trim($m[1]))[0]);
            $pos = strrpos($t, '(');
            $t = $pos !== false ? rtrim(substr($t, 0, $pos)) : $t;
        }
        static $prefijos = [
            '/^banda\s+de\s+cornetas\s+y\s+tambores\s+de\s+las\s+/iu',
            '/^banda\s+de\s+cornetas\s+y\s+tambores\s+de\s+la\s+/iu',
            '/^banda\s+de\s+cornetas\s+y\s+tambores\s+del\s+/iu',
            '/^banda\s+de\s+cornetas\s+y\s+tambores\s+de\s+/iu',
            '/^banda\s+de\s+cornetas\s+y\s+tambores\s+/iu',
            '/^cc\.?\s*tt\.?\s+/iu',
            '/^c\.?\s*t\.?\s+/iu',
            '/^bct\s+/iu',
            '/^agrupaci[oó]n\s+musical\s+de\s+las\s+/iu',
            '/^agrupaci[oó]n\s+musical\s+de\s+la\s+/iu',
            '/^agrupaci[oó]n\s+musical\s+del\s+/iu',
            '/^agrupaci[oó]n\s+musical\s+de\s+/iu',
            '/^agrupaci[oó]n\s+musical\s+/iu',
            '/^a\.?\s*m\.?\s+/iu',
        ];
        foreach ($prefijos as $p) {
            if (preg_match($p, $t)) {
                $t = preg_replace($p, '', $t) ?? $t;
                break;
            }
        }
        return [trim($t), $localidad];
    }

    /** Tipo de una banda a partir de su nombre completo (para indexar `banda`). */
    private static function tipoDeNombre(string $nombre): string
    {
        $t = mb_strtolower($nombre, 'UTF-8');
        if (preg_match('/cornetas\s+y\s+tambores|\bcc\.?\s*tt\.?\b|\bbct\b/u', $t)) {
            return 'cctt';
        }
        if (preg_match('/agrupaci[oó]n\s+musical|\bam\b/u', $t)) {
            return 'am';
        }
        return 'otro';
    }

    /**
     * Clasifica el texto de banda tal cual lo escribe el blog.
     * @return string omitir|descartado_explicito|descartado_tipo|cctt|am|desconocido
     */
    public static function clasificarTipo(string $raw): string
    {
        $t = mb_strtolower(trim(rtrim($raw, ". \t")), 'UTF-8');
        if ($t === '' || preg_match('/sin\s+informaci[oó]n|no\s+lleva/u', $t)) {
            return 'omitir';
        }
        if (preg_match('/tambor\s*cola/u', $t) || preg_match('/\bo\.?\s*j\.?\s*e\.?\b/u', $t)) {
            return 'descartado_explicito';
        }
        $excluidos = [
            '/banda\s+municipal/u',
            '/banda\s+sin[fo]{0,1}[oó]nica/u',
            '/banda\s+juvenil\s+de\s+m[uú]sica/u',
            '/\bb\.?\s*m\.?\b/u',
            '/banda\s+de\s+m[uú]sica/u',
            '/capilla/u',
            '/asociaci[oó]n\s+musical/u',
            '/fanfarria/u',
            '/militar/u',
        ];
        foreach ($excluidos as $p) {
            if (preg_match($p, $t)) {
                return 'descartado_tipo';
            }
        }
        if (preg_match('/cornetas\s+y\s+tambores|\bcc\.?\s*tt\.?\b|\bbct\b|\bc\.?\s*t\.?\b/u', $t)) {
            return 'cctt';
        }
        if (preg_match('/agrupaci[oó]n\s+musical|\ba\.?\s*m\.?\b/u', $t)) {
            return 'am';
        }
        return 'desconocido';
    }

    /** @return array<string,list<array{id:int,nombre:string,localidad:string,tipo:string}>> */
    private static function construirIndiceBandas(PDO $pdo): array
    {
        $idx = [];
        foreach ($pdo->query('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD FROM banda', PDO::FETCH_ASSOC) as $b) {
            foreach ([$b['NOMBRE_COMPLETO'], $b['NOMBRE_BREVE']] as $nombre) {
                [$core] = self::normalizarBanda((string) $nombre);
                $slug = Slug::slugify($core);
                if ($slug === '') {
                    continue;
                }
                $idx[$slug][(int) $b['ID_BANDA']] = [
                    'id' => (int) $b['ID_BANDA'],
                    'nombre' => (string) $nombre,
                    'localidad' => (string) $b['LOCALIDAD'],
                    'tipo' => self::tipoDeNombre((string) $b['NOMBRE_COMPLETO']),
                ];
            }
        }
        $out = [];
        foreach ($idx as $slug => $porId) {
            $out[$slug] = array_values($porId);
        }
        return $out;
    }

    /**
     * @param array<string,list<array{id:int,nombre:string,localidad:string,tipo:string}>> $indice
     * @param array<string,mixed> $alias
     * @return array{status:string, id?:int, candidatos?:list<string>, nota?:string}
     */
    private static function resolverBanda(string $raw, string $tipoBlog, array $indice, array $alias): array
    {
        [$core, $localidad] = self::normalizarBanda($raw);
        $coreSlug = Slug::slugify($core);

        foreach ($alias['ambiguos'] ?? [] as $amb) {
            if (Slug::slugify((string) $amb['core']) === $coreSlug) {
                return ['status' => 'ambiguo_conocido', 'candidatos' => $amb['candidatos'], 'nota' => (string) $amb['nota']];
            }
        }

        foreach ($alias['aliases'] ?? [] as $al) {
            if (Slug::slugify((string) $al['core']) !== $coreSlug) {
                continue;
            }
            if (isset($al['localidad'])) {
                if ($localidad === null) {
                    continue;
                }
                $a = Slug::slugify((string) $al['localidad']);
                $b = Slug::slugify($localidad);
                if (!str_starts_with($a, $b) && !str_starts_with($b, $a)) {
                    continue;
                }
            }
            return ['status' => 'ok', 'id' => (int) $al['id_banda']];
        }

        $cands = array_values(array_filter($indice[$coreSlug] ?? [], static fn(array $c): bool => $c['tipo'] === $tipoBlog));
        if ($cands === []) {
            return ['status' => 'sin_match'];
        }
        if (count($cands) === 1) {
            return ['status' => 'ok', 'id' => $cands[0]['id']];
        }
        if ($localidad !== null) {
            $ls = Slug::slugify($localidad);
            $filtrados = array_values(array_filter($cands, static function (array $c) use ($ls): bool {
                $cs = Slug::slugify($c['localidad']);
                return $cs !== '' && ($ls === $cs || str_starts_with($cs, $ls) || str_starts_with($ls, $cs));
            }));
            if (count($filtrados) === 1) {
                return ['status' => 'ok', 'id' => $filtrados[0]['id']];
            }
        }
        return [
            'status' => 'ambiguo',
            'candidatos' => array_map(
                static fn(array $c): string => $c['id'] . ' ' . $c['nombre'] . ' (' . $c['localidad'] . ')',
                $cands
            ),
        ];
    }

    private static function rango(int $ini, int $fin): string
    {
        return $ini . ($fin !== $ini ? "/$fin" : '');
    }

    // ─────────────────────────── análisis (solo lectura) ─────────────────────

    /**
     * Descarga la etiqueta y calcula qué se cargaría. No escribe nada.
     *
     * Si falta mapeo (la etiqueta entera o alguna cabecera) devuelve
     * listo=false con `cabeceras` (las del blog) y `mapeo` (lo que ya haya)
     * para poder completarlo; el resto de claves solo existen con listo=true.
     *
     * @return array<string,mixed>
     */
    public static function analizar(PDO $pdo, string $url): array
    {
        [$etiqueta, $labelSlug] = self::etiquetaDeUrl($url);
        $mapeo = self::leerJson(self::mapeoPath());
        $alias = self::leerJson(self::aliasPath());

        $bloques = self::agruparBloques(self::lineasDe(self::extraerTexto(self::fetchUrl($url))));
        $cabeceras = [];
        foreach ($bloques as $b) {
            if ($b['orfanas'] === [] && !in_array($b['cabecera'], $cabeceras, true)) {
                $cabeceras[] = $b['cabecera'];
            }
        }

        $base = [
            'url' => $url,
            'etiqueta' => $etiqueta,
            'labelSlug' => $labelSlug,
            'cabeceras' => $cabeceras,
            'mapeo' => isset($mapeo[$labelSlug]) && is_array($mapeo[$labelSlug]) ? $mapeo[$labelSlug] : null,
        ];
        if ($base['mapeo'] === null) {
            return $base + ['listo' => false, 'faltaHermandad' => true, 'sinMapeo' => $cabeceras];
        }
        $hermandadCfg = $base['mapeo'];
        $pasosCfg = [];
        foreach ((array) ($hermandadCfg['pasos'] ?? []) as $clave => $cfg) {
            $pasosCfg[self::claveCabecera((string) $clave)] = (array) $cfg;
        }
        $sinMapeo = array_values(array_filter($cabeceras, static fn(string $c): bool => !isset($pasosCfg[self::claveCabecera($c)])));
        if ($sinMapeo !== []) {
            return $base + ['listo' => false, 'faltaHermandad' => false, 'sinMapeo' => $sinMapeo];
        }
        $hermandadSlug = (string) $hermandadCfg['hermandad_slug'];

        $indiceBandas = self::construirIndiceBandas($pdo);

        $lineasNoParseadas = [];
        $descartadas = [];
        $dudasTipo = [];
        $dudasBanda = [];
        $aContrato = [];
        $aPendiente = [];
        $pasosACrear = [];

        foreach ($bloques as $bloque) {
            if ($bloque['orfanas'] !== []) {
                $lineasNoParseadas = array_merge($lineasNoParseadas, $bloque['orfanas']);
                continue;
            }
            $cabecera = $bloque['cabecera'];
            $pasoCfg = $pasosCfg[self::claveCabecera($cabecera)];

            foreach ($bloque['lineas'] as $linea) {
                if (!preg_match(self::YEAR_RE, $linea, $m)) {
                    $lineasNoParseadas[] = $linea;
                    continue;
                }
                $anioIni = (int) $m[1];
                $anioFin = $m[2] !== '' ? (int) $m[2] : $anioIni;
                $rango = self::rango($anioIni, $anioFin);
                $bandaTexto = trim($m[3]);
                $tipo = self::clasificarTipo($bandaTexto);

                if ($tipo === 'omitir') {
                    continue; // "Sin información" / "No lleva"
                }
                if ($tipo === 'descartado_explicito' || $tipo === 'descartado_tipo') {
                    $descartadas[] = ['anio' => $rango, 'paso' => $cabecera, 'texto' => $bandaTexto, 'motivo' => $tipo];
                    continue;
                }
                if ($tipo === 'desconocido') {
                    $dudasTipo[] = "$cabecera $rango: \"$bandaTexto\" (tipo de banda no reconocido)";
                    continue;
                }
                if (!empty($pasoCfg['descartar'])) {
                    $dudasTipo[] = "$cabecera $rango: \"$bandaTexto\" es $tipo pero el paso \"$cabecera\" está marcado como descartado en el mapeo — ponle el nombre real del paso para poder registrarlo";
                    continue;
                }

                $pasoNombre = (string) $pasoCfg['nombre'];
                $pasosACrear[$pasoNombre] = ['nombre' => $pasoNombre, 'es_cruz_guia' => !empty($pasoCfg['es_cruz_guia'])];

                $res = self::resolverBanda($bandaTexto, $tipo, $indiceBandas, $alias);
                $idBanda = null;
                if ($res['status'] === 'ok') {
                    $idBanda = $res['id'];
                } elseif ($res['status'] === 'ambiguo' || $res['status'] === 'ambiguo_conocido') {
                    $dudasBanda[] = "\"$bandaTexto\" ($rango, $pasoNombre): "
                        . ($res['nota'] ?? 'candidatos ambiguos') . ' [' . implode(' | ', array_map('strval', $res['candidatos'])) . ']';
                }
                // 'sin_match' no genera duda: es el caso normal de "no existe en la BD".

                for ($anio = max($anioIni, self::ANIO_MINIMO); $anio <= $anioFin; $anio++) {
                    if ($idBanda !== null) {
                        $aContrato[] = ['paso' => $pasoNombre, 'anio' => $anio, 'idBanda' => $idBanda, 'bandaTexto' => $bandaTexto];
                    } else {
                        $aPendiente[] = ['paso' => $pasoNombre, 'anio' => $anio, 'bandaTexto' => self::normalizarBandaTexto($bandaTexto)];
                    }
                }
            }
        }

        // ── hermandad / paso ───────────────────────────────────────────────
        $selHerm = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
        $selHerm->execute([self::LOCALIDAD, $hermandadSlug]);
        $idHermandad = $selHerm->fetchColumn();
        $selHerm->closeCursor();
        $idHermandad = $idHermandad === false ? null : (int) $idHermandad;

        $pasosExistentes = [];
        if ($idHermandad !== null) {
            $selPasos = $pdo->prepare('SELECT ID_PASO, NOMBRE FROM paso WHERE ID_HERMANDAD = ?');
            $selPasos->execute([$idHermandad]);
            foreach ($selPasos->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $pasosExistentes[$p['NOMBRE']] = (int) $p['ID_PASO'];
            }
        }
        $pasosNuevos = array_values(array_filter($pasosACrear, static fn(array $p): bool => !isset($pasosExistentes[$p['nombre']])));

        // ── conflictos / idempotencia contra `contrato` y `acompanamiento_pendiente` ──
        //
        // No basta con mirar contrato_paso: hay contratos de Málaga anteriores a
        // esta carga (musicofrades 2026, 101tv.es) con TITULAR='Cruz de Guia'
        // (sin tilde) que nunca se enlazaron a un paso. Se buscan también por
        // HERMANDAD_SLUG+ANIO y se compara el TITULAR por slug.
        $existePasoAnio = $pdo->prepare(
            'SELECT c.ID_CONTRATO, c.ID_BANDA, c.TITULAR, cp.ID_PASO AS ID_PASO_ENLAZADO
             FROM contrato c
             LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.HERMANDAD_SLUG = ? AND c.ANIO = ?'
        );
        $existePendiente = $pdo->prepare(
            'SELECT 1 FROM acompanamiento_pendiente
             WHERE LOCALIDAD = ? AND HERMANDAD_SLUG = ? AND ID_PASO = ? AND ANIO = ? AND BANDA_TEXTO = ?'
        );

        /** @return list<array{ID_CONTRATO:int,ID_BANDA:int}> */
        $filasDelPaso = static function (int $idPaso, string $pasoNombre, int $anio) use ($existePasoAnio, $hermandadSlug): array {
            $existePasoAnio->execute([$hermandadSlug, $anio]);
            $filas = $existePasoAnio->fetchAll(PDO::FETCH_ASSOC);
            $existePasoAnio->closeCursor();
            $pasoNombreSlug = Slug::slugify($pasoNombre);
            $out = [];
            foreach ($filas as $r) {
                $pertenece = $r['ID_PASO_ENLAZADO'] !== null
                    ? (int) $r['ID_PASO_ENLAZADO'] === $idPaso
                    : Slug::slugify((string) $r['TITULAR']) === $pasoNombreSlug;
                if ($pertenece) {
                    $out[] = ['ID_CONTRATO' => (int) $r['ID_CONTRATO'], 'ID_BANDA' => (int) $r['ID_BANDA']];
                }
            }
            return $out;
        };

        $nuevosContratos = [];
        $yaCargados = 0;
        $conflictos = [];
        $nuevasPendientes = [];
        $yaPendientes = 0;
        $resueltoPorOtraFuente = [];

        // -1: el paso (o la hermandad) todavía no existe — aplicar() lo crea y
        // sustituye el placeholder. Ningún contrato_paso real apunta a -1, así
        // que las búsquedas dan "nada todavía" y el informe sale en una pasada.
        foreach ($aContrato as $f) {
            $idPaso = $pasosExistentes[$f['paso']] ?? -1;
            $bandas = array_column($filasDelPaso($idPaso, $f['paso'], $f['anio']), 'ID_BANDA');
            $otras = array_values(array_unique(array_diff($bandas, [$f['idBanda']])));
            if ($otras !== []) {
                // Aviso aunque la banda del blog ya esté cargada: hay OTRA banda
                // para el mismo paso+año (caso real: Pollinica 2024).
                $conflictos[] = sprintf(
                    '%s %d: BD tiene %s, blog dice ID_BANDA=%d ("%s")',
                    $f['paso'], $f['anio'], implode(',', array_map(static fn($b) => "#$b", $bandas)), $f['idBanda'], $f['bandaTexto']
                );
            }
            if (in_array($f['idBanda'], $bandas, true)) {
                $yaCargados++;
                continue;
            }
            if ($otras !== []) {
                continue;
            }
            $nuevosContratos[] = $f + ['idPaso' => $idPaso];
        }
        foreach ($aPendiente as $f) {
            $idPaso = $pasosExistentes[$f['paso']] ?? -1;
            if ($filasDelPaso($idPaso, $f['paso'], $f['anio']) !== []) {
                $resueltoPorOtraFuente[] = "{$f['paso']} {$f['anio']}: ya hay contrato en la BD, no se guarda pendiente para \"{$f['bandaTexto']}\"";
                continue;
            }
            $existePendiente->execute([self::LOCALIDAD, $hermandadSlug, $idPaso, $f['anio'], $f['bandaTexto']]);
            $yaEnPendiente = $existePendiente->fetchColumn();
            $existePendiente->closeCursor();
            if ($yaEnPendiente !== false) {
                $yaPendientes++;
                continue;
            }
            $nuevasPendientes[] = $f + ['idPaso' => $idPaso];
        }

        return $base + [
            'listo' => true,
            'hermandadSlug' => $hermandadSlug,
            'hermandadNombre' => (string) ($hermandadCfg['hermandad_nombre'] ?? $hermandadSlug),
            'idHermandad' => $idHermandad,
            'pasosNuevos' => $pasosNuevos,
            'nuevosContratos' => $nuevosContratos,
            'yaCargados' => $yaCargados,
            'nuevasPendientes' => $nuevasPendientes,
            'yaPendientes' => $yaPendientes,
            'resueltoPorOtraFuente' => $resueltoPorOtraFuente,
            'conflictos' => $conflictos,
            'descartadas' => $descartadas,
            'dudasTipo' => $dudasTipo,
            'dudasBanda' => $dudasBanda,
            'lineasNoParseadas' => $lineasNoParseadas,
        ];
    }

    // ─────────────────────────── escritura ───────────────────────────────────

    /**
     * Escribe lo calculado por analizar(): backup (VACUUM INTO en
     * <dir de la BD>/backups/), hermandad/pasos nuevos, contratos y pendientes,
     * todo en una transacción. $actor queda en cambio_log vía log_actor.
     *
     * @param array<string,mixed> $a resultado de analizar() con listo=true
     * @return array{backup:string, hermandadCreada:?int, pasosCreados:list<string>, contratos:int, pendientes:int, fkLimpio:bool}
     */
    public static function aplicar(PDO $pdo, array $a, string $dbPath, string $actor): array
    {
        if (empty($a['listo'])) {
            throw new RuntimeException('Falta completar el mapeo: no se escribe nada.');
        }

        $backupDir = dirname($dbPath) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException("No se pudo crear $backupDir");
        }
        $base = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-cargar-acompanamientos-malaga-blog';
        $dest = $base . '.db';
        for ($n = 2; is_file($dest); $n++) {
            $dest = "$base-$n.db"; // dos cargas en el mismo segundo
        }
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");

        $pdo->prepare('UPDATE log_actor SET ACTOR = ? WHERE ID = 1')->execute([$actor]);

        $url = (string) $a['url'];
        $hermandadSlug = (string) $a['hermandadSlug'];
        $idHermandad = $a['idHermandad'];
        $hermandadCreada = null;
        $pasosCreados = [];
        $nuevosContratos = $a['nuevosContratos'];
        $nuevasPendientes = $a['nuevasPendientes'];

        $pdo->beginTransaction();
        try {
            if ($idHermandad === null) {
                // Día provisional: la nómina del panel (/dashboard/semana-santa)
                // solo pinta los días de semana_santa_dia, así que la hermandad
                // va a un día real «Sin asignar» al final, desde el que el admin
                // la mueve a su día (y luego borra el día vacío).
                $pdo->prepare(
                    "INSERT OR IGNORE INTO semana_santa_dia (LOCALIDAD, NOMBRE, ORDEN)
                     SELECT ?, ?, COALESCE(MAX(ORDEN), 0) + 1 FROM semana_santa_dia WHERE LOCALIDAD = ?"
                )->execute([self::LOCALIDAD, self::DIA_SIN_ASIGNAR, self::LOCALIDAD]);
                $selDia = $pdo->prepare(
                    'SELECT d.ORDEN, (SELECT COALESCE(MAX(h.ORDEN), 0) FROM hermandad h WHERE h.LOCALIDAD = d.LOCALIDAD AND h.DIA = d.NOMBRE)
                     FROM semana_santa_dia d WHERE d.LOCALIDAD = ? AND d.NOMBRE = ?'
                );
                $selDia->execute([self::LOCALIDAD, self::DIA_SIN_ASIGNAR]);
                [$diaOrden, $maxOrden] = $selDia->fetch(PDO::FETCH_NUM);
                $selDia->closeCursor();
                $pdo->prepare(
                    'INSERT INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA, FUENTE)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
                )->execute([self::LOCALIDAD, $a['hermandadNombre'], $hermandadSlug, self::DIA_SIN_ASIGNAR, (int) $diaOrden, (int) $maxOrden + 1, $url]);
                $idHermandad = $hermandadCreada = (int) $pdo->lastInsertId();
            }

            if ($a['pasosNuevos'] !== []) {
                $selMax = $pdo->prepare('SELECT COALESCE(MAX(ORDEN), 0) FROM paso WHERE ID_HERMANDAD = ?');
                $selMax->execute([$idHermandad]);
                $maxOrdenPaso = (int) $selMax->fetchColumn();
                $selMax->closeCursor(); // un cursor abierto bloquea el wal_checkpoint del final
                $insPaso = $pdo->prepare('INSERT INTO paso (ID_HERMANDAD, NOMBRE, ORDEN, ES_CRUZ_GUIA) VALUES (?, ?, ?, ?)');
                $idsNuevos = [];
                foreach ($a['pasosNuevos'] as $p) {
                    $insPaso->execute([$idHermandad, $p['nombre'], $p['es_cruz_guia'] ? 0 : ++$maxOrdenPaso, $p['es_cruz_guia'] ? 1 : 0]);
                    $idsNuevos[$p['nombre']] = (int) $pdo->lastInsertId();
                    $pasosCreados[] = $p['nombre'];
                }
                // Sustituye el placeholder -1 de analizar() por el ID real.
                foreach ($nuevosContratos as &$f) {
                    if ($f['idPaso'] === -1) $f['idPaso'] = $idsNuevos[$f['paso']];
                }
                unset($f);
                foreach ($nuevasPendientes as &$f) {
                    if ($f['idPaso'] === -1) $f['idPaso'] = $idsNuevos[$f['paso']];
                }
                unset($f);
            }

            $selNombre = $pdo->prepare('SELECT NOMBRE FROM hermandad WHERE ID_HERMANDAD = ?');
            $selNombre->execute([$idHermandad]);
            $hermandadNombre = (string) $selNombre->fetchColumn();
            $selNombre->closeCursor();

            $insC = $pdo->prepare(
                'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insL = $pdo->prepare('INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, ?)');
            $insP = $pdo->prepare('INSERT INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)');
            foreach ($nuevosContratos as $f) {
                $insC->execute([$f['idBanda'], $hermandadNombre, $hermandadSlug, $f['paso'], $f['anio'], $url]);
                $idContrato = (int) $pdo->lastInsertId();
                $insL->execute([$idContrato, self::LOCALIDAD]);
                $insP->execute([$idContrato, $f['idPaso']]);
            }

            $insPend = $pdo->prepare(
                'INSERT INTO acompanamiento_pendiente (LOCALIDAD, HERMANDAD_SLUG, ID_PASO, TITULAR, ANIO, BANDA_TEXTO, FUENTE)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($nuevasPendientes as $f) {
                $insPend->execute([self::LOCALIDAD, $hermandadSlug, $f['idPaso'], $f['paso'], $f['anio'], $f['bandaTexto'], $url]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        return [
            'backup' => $dest,
            'hermandadCreada' => $hermandadCreada,
            'pasosCreados' => $pasosCreados,
            'contratos' => count($nuevosContratos),
            'pendientes' => count($nuevasPendientes),
            'fkLimpio' => $pdo->query('PRAGMA foreign_key_check')->fetchAll() === [],
        ];
    }
}
