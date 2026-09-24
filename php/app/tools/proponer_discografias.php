<?php
declare(strict_types=1);

/**
 * proponer_discografias.php
 *
 * Propone altas de bandas CT/AM, autores, marchas y discos a partir de
 * discografiasdemarchasprocesionales.com, cruzando con mdc.db en SOLO LECTURA.
 * No escribe en la base de datos: genera un JSON con filas listas para insertar
 * (columnas reales de banda, autor, marcha, marcha_autor, disco, disco_marcha)
 * más origen, flags y candidatos para los casos dudosos.
 *
 * Fuentes del origen:
 *   /json/discos, /json/bandas, /json/marchas, /json/autores  (listados completos, pk compartida)
 *   /discos/{pk}/  (solo para saber la banda del disco y sus pistas)
 *
 * Uso:
 *   php php/app/tools/proponer_discografias.php --db=php/data/mdc.db
 *       --cache=DIR --salida=FICHERO.json [--desde=PK] [--hasta=PK]
 *       [--pausa-ms=1000] [--sin-red]
 *
 * --cache        carpeta de caché (json/ y discos/); re-ejecutar no repite peticiones
 * --desde/hasta  filtran por pk de disco del origen
 * --sin-red      solo trabaja con lo que haya en caché
 */

const ORIGEN = 'https://discografiasdemarchasprocesionales.com';
const UMBRAL_DUDA = 0.85;
const UMBRAL_EXISTE = 0.95;
const ESTILOS = ['CT' => 'CCTT', 'AM' => 'AM'];

$opt = getopt('', ['db:', 'cache:', 'salida:', 'desde::', 'hasta::', 'pausa-ms::', 'sin-red']);
foreach (['db', 'cache', 'salida'] as $req) {
    if (empty($opt[$req])) {
        fwrite(STDERR, "Falta --$req\n");
        exit(2);
    }
}
$desde   = (int) ($opt['desde'] ?? 1);
$hasta   = (int) ($opt['hasta'] ?? PHP_INT_MAX);
$pausaMs = (int) ($opt['pausa-ms'] ?? 1000);
$sinRed  = isset($opt['sin-red']);
$cache   = rtrim((string) $opt['cache'], '/\\');
foreach (['discos', 'bandas', 'json'] as $sub) {
    if (!is_dir("$cache/$sub") && !mkdir("$cache/$sub", 0777, true)) {
        fwrite(STDERR, "No se puede crear $cache/$sub\n");
        exit(2);
    }
}

// ---------------------------------------------------------------- normalización

function sin_tildes(string $s): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) {
            return (string) preg_replace('/\p{Mn}+/u', '', $n);
        }
    }
    return strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ]);
}

function norm(?string $s): string
{
    if ($s === null) {
        return '';
    }
    $s = mb_strtolower(sin_tildes($s), 'UTF-8');
    $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
    $abrev = ['ntra' => 'nuestra', 'ntro' => 'nuestro', 'sra' => 'senora', 'sr' => 'senor',
              'stma' => 'santisima', 'smo' => 'santisimo', 'stmo' => 'santisimo', 'sto' => 'santo',
              'sta' => 'santa', 'ma' => 'maria', 'jhs' => 'jesus'];
    $t = array_map(static fn(string $w): string => $abrev[$w] ?? $w, preg_split('/\s+/', trim($s)) ?: []);
    return implode(' ', array_filter($t, static fn(string $w): bool => $w !== ''));
}

/** @return list<string> tokens significativos para comparar nombres de banda */
function tokens_banda(string $n): array
{
    static $stop = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'banda', 'cornetas',
                    'tambores', 'agrupacion', 'musical', 'cctt', 'bct', 'am', 'ct', 'b', 'c', 't', 'a', 'm'];
    return array_values(array_unique(array_diff(explode(' ', $n), $stop, [''])));
}

function ratio(string $a, string $b): float
{
    if ($a === $b) {
        return 1.0;
    }
    $max = max(strlen($a), strlen($b));
    if ($max === 0) {
        return 1.0;
    }
    if ($max <= 255) {
        return 1.0 - levenshtein($a, $b) / $max;
    }
    similar_text($a, $b, $pct);
    return $pct / 100;
}

function sin_info(?string $v): ?string
{
    $v = $v === null ? null : trim($v);
    return ($v === null || $v === '' || strcasecmp($v, 'S/I') === 0) ? null : $v;
}

// ---------------------------------------------------------------- red + caché

/** @param-out 'cache'|'cache404'|'red'|'404'|'error'|'sin_red' $via */
function obtener(string $url, string $fichero, int $pausaMs, bool $sinRed, array &$errores, ?string &$via = null): ?string
{
    if (is_file("$fichero.404")) {
        $via = 'cache404';
        return '';
    }
    if (is_file($fichero)) {
        $via = 'cache';
        return (string) file_get_contents($fichero);
    }
    if ($sinRed) {
        $via = 'sin_red';
        return null;
    }
    usleep($pausaMs * 1000);
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 30, 'ignore_errors' => true,
        'header' => "User-Agent: marchasdecristo.com propuestas (bajo ritmo)\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $codigo = (int) $m[1];
        }
    }
    if ($codigo === 404) {
        touch("$fichero.404");
        $via = '404';
        return '';
    }
    if ($codigo !== 200 || !is_string($body)) {
        $errores[] = ['url' => $url, 'http' => $codigo];
        $via = 'error';
        return null;
    }
    file_put_contents($fichero, $body);
    $via = 'red';
    return $body;
}

// ---------------------------------------------------------------- parseo

function dom(string $html): DOMXPath
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}

/** @return list<string> */
function lineas(string $html): array
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
    $html = (string) preg_replace('#<(/?(p|div|li|ul|ol|nav|header|section|table|tr|td|th|h[1-6])|br|/a)\b[^>]*>#i', "\n$0", $html);
    $txt = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = [];
    foreach (preg_split('/\R/u', $txt) ?: [] as $l) {
        $l = trim((string) preg_replace('/\s+/u', ' ', $l));
        if ($l !== '') {
            $out[] = $l;
        }
    }
    return $out;
}

function campo(array $lineas, string $patron): ?string
{
    foreach ($lineas as $l) {
        if (preg_match('/^' . $patron . '\s*:\s*(.*)$/iu', $l, $m)) {
            return sin_info($m[1]);
        }
    }
    return null;
}

/** @return array<string,mixed>|null */
function parse_disco(string $html): ?array
{
    $ls = lineas($html);
    $i = array_search('Grabado por', $ls, true);
    if ($i === false || $i === 0) {
        return null;
    }
    $titulo = (string) preg_replace('/^[-•·]\s*/u', '', $ls[$i - 1]);
    if (!preg_match('#/bandas/(\d+)/?"[^>]*>\s*([^<]+?)\s*</a>#u', $html, $b)) {
        return null;
    }
    $bandaTxt = html_entity_decode($b[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pistas = [];
    $xp = dom($html);
    foreach ($xp->query('//table') ?: [] as $tabla) {
        if (!$tabla instanceof DOMElement || stripos($tabla->textContent, 'Pista') === false) {
            continue;
        }
        foreach ($xp->query('.//tr[td]', $tabla) ?: [] as $tr) {
            $td = $xp->query('./td', $tr);
            if ($td === false || $td->length < 3) {
                continue;
            }
            $enl = static function (DOMNode $n, string $tipo) use ($xp): ?int {
                $a = $xp->query('.//a[contains(@href,"/' . $tipo . '/")]', $n);
                $href = ($a !== false && $a->length > 0 && $a->item(0) instanceof DOMElement)
                    ? $a->item(0)->getAttribute('href') : '';
                return preg_match('#/' . $tipo . '/(\d+)#', $href, $m) ? (int) $m[1] : null;
            };
            // el enlace a la marcha va en el onclick de la fila (window.location='/marchas/N/'), no en un <a>
            $idMarcha = $enl($td->item(1), 'marchas');
            if ($idMarcha === null && $tr instanceof DOMElement
                && preg_match('#/marchas/(\d+)#', $tr->getAttribute('onclick'), $mo)) {
                $idMarcha = (int) $mo[1];
            }
            $pistas[] = [
                'n'         => ctype_digit(trim($td->item(0)->textContent)) ? (int) trim($td->item(0)->textContent) : null,
                'titulo'    => trim($td->item(1)->textContent),
                'autor'     => sin_info($td->item(2)->textContent),
                'id_marcha' => $idMarcha,
                'id_autor'  => $enl($td->item(2), 'autores'),
            ];
        }
        break;
    }
    $portada = null;
    foreach ($xp->query('//img/@src') ?: [] as $src) {
        if (!str_contains($src->nodeValue ?? '', '/static/')) {
            $portada = $src->nodeValue;
        }
    }
    return [
        'titulo'      => $titulo,
        'id_banda'    => (int) $b[1],
        'banda_texto' => $bandaTxt,
        'anio'        => campo($ls, 'A[ñn]o de Grabaci[oó]n'),
        'discografica'=> campo($ls, 'Discogr[aá]fica'),
        'codigo'      => campo($ls, 'C[oó]digo'),
        'grabado_en'  => campo($ls, 'Grabado en'),
        'notas'       => campo($ls, 'Notas'),
        'portada'     => $portada,
        'pistas'      => $pistas,
    ];
}

/** @return array<string,mixed>|null */
function parse_banda(string $html): ?array
{
    $ls = lineas($html);
    $nombre = null;
    foreach ($ls as $l) {
        if (preg_match('/^\((CT|AM|BM|[A-Z]{1,4})\)\s*(.+)$/u', $l, $m)) {
            $nombre = ['prefijo' => $m[1], 'resto' => trim($m[2])];
            break;
        }
    }
    if ($nombre === null) {
        return null;
    }
    $ciudad = campo($ls, 'Ciudad');
    $loc = $prov = null;
    if ($ciudad !== null && preg_match('/^(.*?)\.?\s*\(([^)]+)\)\s*$/u', $ciudad, $m)) {
        [$loc, $prov] = [trim($m[1]), trim($m[2])];
    } elseif ($ciudad !== null) {
        $loc = rtrim($ciudad, '. ');
    }
    $nucleo = $nombre['resto'];
    if (preg_match('/^(.*\S)\s*\(([^)]+)\)$/u', $nucleo, $m)) {
        $nucleo = $m[1];
    }
    return [
        'prefijo' => $nombre['prefijo'], 'nombre' => $nombre['resto'], 'nucleo' => $nucleo,
        'ciudad_raw' => $ciudad, 'localidad' => $loc, 'provincia' => $prov,
        'estilo_txt' => campo($ls, 'Estilo'),
    ];
}

// ---------------------------------------------------------------- BD (solo lectura)

$pdo = new PDO('sqlite:' . $opt['db'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$q = static fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$bdBandas = $q('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD, PROVINCIA FROM banda');
foreach ($bdBandas as &$r) {
    $tokNombre = tokens_banda(norm(($r['NOMBRE_COMPLETO'] ?? '') . ' ' . $r['NOMBRE_BREVE']));
    $sinLoc = array_values(array_diff($tokNombre, tokens_banda(norm($r['LOCALIDAD']))));
    $r['_tok'] = $sinLoc !== [] ? $sinLoc : $tokNombre;
    $r['_loc'] = norm($r['LOCALIDAD']);
}
unset($r);

$bdAutores = [];
$autorPorClave = [];
foreach ($q('SELECT ID_AUTOR, APELLIDOS, NOMBRE, NOMBRE_ART FROM autor') as $r) {
    $r['_k'] = norm(($r['APELLIDOS'] ?? '') . ' ' . ($r['NOMBRE'] ?? ''));
    $r['_ka'] = norm($r['NOMBRE_ART']);
    $bdAutores[(int) $r['ID_AUTOR']] = $r;
    $autorPorClave[$r['_k']][] = (int) $r['ID_AUTOR'];
    if ($r['_ka'] !== '') {
        $autorPorClave[$r['_ka']][] = (int) $r['ID_AUTOR'];
    }
}

$apellidoAutorBd = [];
foreach ($bdAutores as $aid => $a) {
    $apellidoAutorBd[$aid] = primer_apellido($a['APELLIDOS']);
}

$bdMarchas = [];
$marchaPorToken = [];
foreach ($q("SELECT m.ID_MARCHA, m.TITULO, m.ESTILO, GROUP_CONCAT(ma.ID_AUTOR) AS AUTORES
             FROM marcha m LEFT JOIN marcha_autor ma ON ma.ID_MARCHA = m.ID_MARCHA
             GROUP BY m.ID_MARCHA") as $r) {
    $id = (int) $r['ID_MARCHA'];
    $r['_n'] = norm($r['TITULO']);
    $r['_nt'] = norm_titulo($r['TITULO']);
    $r['_aut'] = $r['AUTORES'] ? array_map('intval', explode(',', $r['AUTORES'])) : [];
    $bdMarchas[$id] = $r;
    foreach (array_unique(explode(' ', $r['_nt'])) as $t) {
        if (strlen($t) >= 4) {
            $marchaPorToken[$t][] = $id;
        }
    }
    $marchaPorToken['=' . $r['_nt']][] = $id;
}

$discosPorBanda = [];
foreach ($q('SELECT ID_DISCO, NOMBRE_CD, FECHA_CD, BANDADISCO FROM disco') as $r) {
    $discosPorBanda[(int) $r['BANDADISCO']][] = $r;
}

$municipios = [];
foreach ($q('SELECT PROVINCIA, NOMBRE FROM municipio') as $r) {
    $municipios[norm($r['PROVINCIA']) . '|' . norm($r['NOMBRE'])] = true;
}

// ---------------------------------------------------------------- cruce

/** Localidad comparable: sin tildes, "fra" → "frontera", sin espacios ni guiones */
function norm_loc(?string $s): string
{
    $n = ' ' . norm($s) . ' ';
    $n = str_replace([' fra ', ' ftra '], ' frontera ', $n);
    return str_replace(' ', '', $n);
}

function loc_igual(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b || (min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a)))) {
        return true;
    }
    return ratio($a, $b) >= UMBRAL_DUDA;
}

/** Tokens de $a presentes en $b, admitiendo erratas (≥0.85 por token de 5+ letras: "amarrao" ~ "amarrado") */
function inter_tokens(array $a, array $b): int
{
    $n = 0;
    foreach ($a as $t) {
        if (in_array($t, $b, true)) {
            $n++;
            continue;
        }
        if (strlen($t) < 5) {
            continue;
        }
        foreach ($b as $u) {
            if (strlen($u) >= 5 && ratio($t, $u) >= UMBRAL_DUDA) {
                $n++;
                break;
            }
        }
    }
    return $n;
}

/** Estilo que declara el prefijo de NOMBRE_BREVE en la BD ("AM ...", "BCT ...", "BM ..."), o null */
function estilo_bd(string $breve): ?string
{
    if (!preg_match('/^(AM|BCT|CCTT|BM)\b/u', trim($breve), $m)) {
        return null;
    }
    return ['AM' => 'AM', 'BCT' => 'CT', 'CCTT' => 'CT', 'BM' => 'BM'][$m[1]];
}

/**
 * Criterio:
 *  - estilo de la BD incompatible con el prefijo del origen → descartado
 *  - misma localidad y un nombre contiene al otro (≥0.85 en algún sentido) → alto
 *  - misma localidad y ≥0.5 → medio
 *  - localidad desconocida en algún lado y ≥0.85 → medio
 *  - localidades distintas: solo medio si los nombres son idénticos (mismos tokens); si no, descartado
 *  1 alto → existe · varios altos o algún medio → duda · nada → nuevo
 *
 * @return array{estado:string,id:?int,candidatos:list<array<string,mixed>>}
 */
function cruzar_banda(array $o, array $bdBandas): array
{
    // advocaciones genéricas: por sí solas no identifican una banda
    static $debiles = ['nuestro', 'nuestra', 'padre', 'senor', 'senora', 'jesus', 'cristo', 'santisimo', 'santisima',
                       'maria', 'virgen', 'nazareno', 'santo', 'santa', 'san', 'madre', 'dios'];
    $tok0 = tokens_banda(norm($o['nucleo']));
    $juvO = in_array('juvenil', $tok0, true);
    $tok = array_values(array_diff($tok0, ['juvenil']));
    $fuertesO = array_values(array_diff($tok, $debiles));
    $loc = norm_loc($o['localidad']);
    $altos = $medios = $matrices = [];
    if ($tok === []) {
        return ['estado' => 'duda', 'id' => null, 'candidatos' => [], 'motivo' => 'nombre_vacio', 'matriz' => null];
    }
    foreach ($bdBandas as $b) {
        $juvB = in_array('juvenil', $b['_tok'], true);
        $tokB = array_values(array_diff($b['_tok'], ['juvenil']));
        if ($tokB === []) {
            continue;
        }
        $eb = estilo_bd((string) $b['NOMBRE_BREVE']);
        $estiloOk = $eb === null || $eb === $o['prefijo'];
        $inter = inter_tokens($tok, $tokB);
        if ($inter === 0) {
            continue;
        }
        $fuertes = $fuertesO === [] ? 0 : inter_tokens($fuertesO, $tokB);
        $cob = max($inter / count($tok), $inter / count($tokB));
        $identico = $inter === count($tok) && inter_tokens($tokB, $tok) === count($tokB);
        $bloc = norm_loc($b['LOCALIDAD']);
        $mismaLoc = $loc !== '' && $bloc !== '' && loc_igual($loc, $bloc);
        $c = ['ID_BANDA' => (int) $b['ID_BANDA'], 'NOMBRE_COMPLETO' => $b['NOMBRE_COMPLETO'],
              'NOMBRE_BREVE' => $b['NOMBRE_BREVE'], 'LOCALIDAD' => $b['LOCALIDAD'], 'cobertura' => round($cob, 2),
              'cob_origen' => round($inter / count($tok), 2)];

        if ($juvO !== $juvB) {
            // juvenil frente a no juvenil: nunca es la misma banda; si el origen es juvenil, posible matriz
            if ($juvO && $mismaLoc && $cob >= UMBRAL_DUDA && $estiloOk) {
                $matrices[] = $c;
            }
            continue;
        }
        if ($loc !== '' && $bloc !== '') {
            if ($mismaLoc) {
                if ($cob >= UMBRAL_DUDA && $estiloOk) {
                    $altos[] = $c + ['motivo' => 'misma_localidad'];
                } elseif ($cob >= UMBRAL_DUDA) {
                    $medios[] = $c + ['motivo' => 'misma_localidad_estilo_distinto_en_bd'];
                } elseif ($cob >= 0.5 && $fuertes > 0) {
                    $medios[] = $c + ['motivo' => 'misma_localidad_nombre_parcial'];
                }
            } elseif ($identico && $estiloOk && $fuertes > 0) {
                $medios[] = $c + ['motivo' => 'mismo_nombre_otra_localidad'];
            }
        } elseif ($cob >= UMBRAL_DUDA && $estiloOk && $fuertes > 0) {
            $medios[] = $c + ['motivo' => 'localidad_desconocida'];
        }
    }
    $orden = static fn(array $x, array $y): int => [$y['cobertura'], $y['cob_origen']] <=> [$x['cobertura'], $x['cob_origen']];
    usort($matrices, $orden);
    $matriz = (count($matrices) === 1 || (count($matrices) > 1
        && [$matrices[0]['cobertura'], $matrices[0]['cob_origen']] > [$matrices[1]['cobertura'], $matrices[1]['cob_origen']]))
        ? $matrices[0]['ID_BANDA'] : null;

    if ($altos !== []) {
        // gana la coincidencia fuerte con más cobertura; parciales y estilos distintos no bloquean; empate → duda
        usort($altos, $orden);
        if (count($altos) === 1 || [$altos[0]['cobertura'], $altos[0]['cob_origen']] > [$altos[1]['cobertura'], $altos[1]['cob_origen']]) {
            return ['estado' => 'existe', 'id' => $altos[0]['ID_BANDA'], 'candidatos' => [], 'matriz' => null];
        }
    }
    if ($altos !== [] || $medios !== []) {
        usort($medios, static fn(array $x, array $y): int
            => [!str_starts_with($x['motivo'], 'misma_localidad'), -$x['cobertura']]
            <=> [!str_starts_with($y['motivo'], 'misma_localidad'), -$y['cobertura']]);
        $todos = array_merge($altos, $medios);
        $mot = count($altos) > 1 ? 'varias_misma_localidad' : $todos[0]['motivo'];
        return ['estado' => 'duda', 'id' => null, 'candidatos' => array_slice($todos, 0, 5), 'motivo' => $mot,
                'matriz' => $matriz, 'matrices' => array_slice($matrices, 0, 3)];
    }
    return ['estado' => 'nuevo', 'id' => null, 'candidatos' => [], 'matriz' => $matriz, 'matrices' => array_slice($matrices, 0, 3)];
}

/** @return array{estado:string,id:?int,candidatos:list<array<string,mixed>>,apellidos:?string,nombre:?string,flags:list<string>} */
function cruzar_autor(string $raw, array $bdAutores, array $autorPorClave): array
{
    $flags = [];
    if (preg_match('#\s(y|/|-|;)\s|/#u', $raw)) {
        $flags[] = 'posibles_varios_autores';
    }
    $ap = $no = null;
    if (substr_count($raw, ',') === 1) {
        [$ap, $no] = array_map('trim', explode(',', $raw));
    } else {
        $flags[] = 'autor_sin_formato_apellidos_nombre';
    }
    $k = norm($ap !== null ? "$ap $no" : $raw);
    $base = ['apellidos' => $ap, 'nombre' => $no, 'flags' => $flags];
    $exactos = array_values(array_unique($autorPorClave[$k] ?? []));
    if (count($exactos) === 1) {
        return $base + ['estado' => 'existe', 'id' => $exactos[0], 'candidatos' => []];
    }
    $cand = [];
    foreach ($bdAutores as $id => $a) {
        $s = max(ratio($k, $a['_k']), $a['_ka'] !== '' ? ratio($k, $a['_ka']) : 0.0);
        if ($s >= UMBRAL_DUDA) {
            $cand[] = ['ID_AUTOR' => $id, 'APELLIDOS' => $a['APELLIDOS'], 'NOMBRE' => $a['NOMBRE'],
                       'NOMBRE_ART' => $a['NOMBRE_ART'], 'score' => round($s, 3)];
        }
    }
    usort($cand, static fn(array $x, array $y): int => $y['score'] <=> $x['score']);
    if ($cand !== []) {
        return $base + ['estado' => 'duda', 'id' => null, 'candidatos' => array_slice($cand, 0, 5)];
    }
    return $base + ['estado' => 'nuevo', 'id' => null, 'candidatos' => []];
}

/** Título comparable: norm() sin artículo inicial */
function norm_titulo(?string $t): string
{
    return (string) preg_replace('/^(el|la|los|las) /', '', norm($t));
}

function limpiar_titulo(string $t, ?string $apellidosAutor): array
{
    $flags = [];
    if (preg_match('/^(.*\S)\s*\(([^)]+)\)\s*$/u', $t, $m)) {
        $par = norm($m[2]);
        if ($apellidosAutor !== null && $par !== '' && str_contains(norm($apellidosAutor), $par)) {
            $t = $m[1];
            $flags[] = 'desambiguador_de_autor_quitado';
        } else {
            $flags[] = 'titulo_con_parentesis';
        }
    }
    if ($t === mb_strtoupper($t, 'UTF-8')) {
        $flags[] = 'titulo_en_mayusculas_origen';
    }
    return [$t, $flags];
}

/**
 * Autor genérico del origen: 'varios' ("Varios Autores": no identifica a nadie, no se crea autor)
 * o 'generico' (Popular, Anónimo, Tradicional: puede existir como autor, pero no sirve para distinguir obras)
 */
function autor_generico(string $raw): ?string
{
    $n = norm($raw);
    if (in_array($n, ['varios autores', 'varios', 'varios compositores', 'autores varios'], true)) {
        return 'varios';
    }
    if (in_array($n, ['popular', 'anonimo', 'tradicional', 'popular anonimo'], true)) {
        return 'generico';
    }
    return null;
}

/** Primer apellido comparable ("de la Rosa García" → "rosa") */
function primer_apellido(?string $apellidos): string
{
    foreach (explode(' ', norm($apellidos)) as $t) {
        if (!in_array($t, ['', 'de', 'del', 'la', 'las', 'los', 'y', 'san'], true)) {
            return $t;
        }
    }
    return '';
}

/**
 * @param list<int> $autoresIds   autores de la pista que ya existen en BD
 * @param list<int> $autorCands   candidatos en BD de autores de la pista que están en duda
 * @param bool      $autorDesconocido la pista trae autor pero no está en BD ni tiene candidatos
 * @return array{estado:string,id:?int,candidatos:list<array<string,mixed>>,motivo:?string,autor_confirmado:?int}
 */
function cruzar_marcha(string $titulo, array $autoresIds, array $autorCands, bool $autorDesconocido,
                       array $apellidosPista, array $apellidoAutorBd, array $bdMarchas, array $marchaPorToken,
                       bool $autorGenerico = false): array
{
    $n = norm_titulo($titulo);
    $ids = $marchaPorToken['=' . $n] ?? [];
    foreach (array_unique(explode(' ', $n)) as $t) {
        if (strlen($t) >= 4) {
            array_push($ids, ...($marchaPorToken[$t] ?? []));
        }
    }
    $cand = [];
    foreach (array_unique($ids) as $id) {
        $m = $bdMarchas[$id];
        $s = ratio($n, $m['_nt']);
        if ($s < UMBRAL_DUDA) {
            continue;
        }
        $cand[] = ['ID_MARCHA' => $id, 'TITULO' => $m['TITULO'], 'ESTILO' => $m['ESTILO'], 'autores' => $m['_aut'],
                   'score' => round($s, 3),
                   'comparte_autor' => array_intersect($autoresIds, $m['_aut']) !== [],
                   'comparte_autor_candidato' => array_values(array_intersect($autorCands, $m['_aut']))];
    }
    usort($cand, static fn(array $x, array $y): int => [$y['comparte_autor'], $y['score']] <=> [$x['comparte_autor'], $x['score']]);
    $r = static fn(string $e, ?int $id, ?string $mot, ?int $ac = null, array $c = []): array
        => ['estado' => $e, 'id' => $id, 'candidatos' => array_slice($c, 0, 5), 'motivo' => $mot, 'autor_confirmado' => $ac];

    if ($cand === []) {
        return $r('nuevo', null, null);
    }
    if ($autorGenerico) {
        // "Varios Autores" / Popular: el autor no discrimina; decide solo el título
        $ex = array_values(array_filter($cand, static fn(array $c): bool => $c['score'] >= UMBRAL_EXISTE));
        if (count($ex) === 1) {
            return $r('existe', $ex[0]['ID_MARCHA'], null);
        }
        return $r('duda', null, 'V_autor_generico_origen', null, $cand);
    }
    $exMismo = array_values(array_filter($cand, static fn(array $c): bool => $c['comparte_autor'] && $c['score'] >= UMBRAL_EXISTE));
    if (count($exMismo) === 1) {
        return $r('existe', $exMismo[0]['ID_MARCHA'], null);
    }
    if (count($exMismo) > 1) {
        return $r('duda', null, 'C_varias_en_bd_mismo_autor', null, $cand);
    }
    $casiMismo = array_values(array_filter($cand, static fn(array $c): bool => $c['comparte_autor'] && $c['score'] >= 0.90));
    if (count($casiMismo) === 1) {
        return $r('existe', $casiMismo[0]['ID_MARCHA'], null);
    }
    $exCand = array_values(array_filter($cand, static fn(array $c): bool => $c['comparte_autor_candidato'] !== [] && $c['score'] >= UMBRAL_EXISTE));
    if (count($exCand) === 1 && count($exCand[0]['comparte_autor_candidato']) === 1) {
        return $r('existe', $exCand[0]['ID_MARCHA'], null, $exCand[0]['comparte_autor_candidato'][0]);
    }
    $exactas = array_values(array_filter($cand, static fn(array $c): bool => $c['score'] >= UMBRAL_EXISTE));
    $algunaSinAutor = array_filter($cand, static fn(array $c): bool => $c['autores'] === []);
    $hayAutorPista = $autoresIds !== [] || $autorCands !== [] || $autorDesconocido;
    if ($exactas !== [] && $hayAutorPista && array_filter($cand, static fn(array $c): bool => $c['comparte_autor']) === []) {
        // mismo título y ningún autor en común: ¿coincide el primer apellido del compositor?
        $porApellido = [];
        foreach ($exactas as $c) {
            foreach ($c['autores'] as $aid) {
                $ap = $apellidoAutorBd[$aid] ?? '';
                if ($ap !== '' && in_array($ap, $apellidosPista, true)) {
                    $porApellido[] = ['ID_MARCHA' => $c['ID_MARCHA'], 'ID_AUTOR' => $aid];
                }
            }
        }
        if ($porApellido !== []) {
            if ($autoresIds === [] && count($porApellido) === 1) {
                // autor de la pista no está en BD (o en duda) pero el apellido casa con el de esa marcha
                return $r('existe', $porApellido[0]['ID_MARCHA'], null, $porApellido[0]['ID_AUTOR']);
            }
            return $r('duda', null, 'H_mismo_apellido_otro_autor_bd', null, $cand);
        }
        if ($algunaSinAutor === []) {
            // mismo título, pero todas las de la BD son de compositores con otro apellido: obra distinta
            return $r('nuevo', null, 'E_homonima_otro_autor', null, $cand);
        }
    }
    $mot = match (true) {
        $algunaSinAutor !== []          => 'F_candidata_en_bd_sin_autor',
        $autorCands !== []              => 'A_autor_en_duda',
        $cand[0]['comparte_autor']      => 'D_mismo_autor_titulo_parecido',
        $exactas !== []                 => 'E_mismo_titulo_otro_autor',
        default                         => 'B_titulo_parecido_otro_autor',
    };
    return $r('duda', null, $mot, null, $cand);
}

// ---------------------------------------------------------------- utilidades del origen

/** "Virgen de la Asunción. (Jodar)" → ['Virgen de la Asunción', 'Jodar'] */
function texto_y_lugar(?string $v): array
{
    $v = sin_info($v);
    if ($v === null) {
        return [null, null];
    }
    if (preg_match('/^(.*?)\.?\s*\(([^)]+)\)\s*$/u', $v, $m)) {
        return [sin_info(rtrim($m[1], '. ')), sin_info($m[2])];
    }
    return [rtrim($v, '. '), null];
}

/** "2´40´´" → 160 */
function duracion_seg(?string $v): ?int
{
    $v = sin_info($v);
    if ($v === null || !preg_match('/^\s*(\d{1,2})\D+(\d{1,2})\D*$/u', $v, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/** @return array<string,mixed>|null */
function banda_desde_json(array $f): ?array
{
    if (!preg_match('/^\((CT|AM|BM|[A-Z]{1,4})\)\s*(.+)$/u', trim((string) ($f['nombre'] ?? '')), $m)) {
        return null;
    }
    $resto = trim($m[2]);
    [$loc, $prov] = texto_y_lugar($f['ciudad'] ?? null);
    $nucleo = preg_match('/^(.*\S)\s*\(([^)]+)\)$/u', $resto, $n) ? $n[1] : $resto;
    return ['prefijo' => $m[1], 'nombre' => $resto, 'nucleo' => $nucleo, 'ciudad_raw' => $f['ciudad'] ?? null,
            'localidad' => $loc, 'provincia' => $prov, 'estilo_codigo' => $f['estilo'] ?? null];
}

function clave_titulo(string $t): string
{
    return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $t)), 'UTF-8');
}

function hms(float $seg): string
{
    $seg = (int) round($seg);
    return sprintf('%02d:%02d:%02d', intdiv($seg, 3600), intdiv($seg % 3600, 60), $seg % 60);
}

function log_linea(string $txt): void
{
    fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $txt . PHP_EOL);
}

// ---------------------------------------------------------------- JSON del origen

$errores = [];
$t0 = microtime(true);
$org = [];
foreach (['discos', 'bandas', 'marchas', 'autores'] as $t) {
    $via = null;
    $raw = obtener(ORIGEN . "/json/$t", "$cache/json/$t.json", $pausaMs, $sinRed, $errores, $via);
    $j = ($raw !== null && $raw !== '') ? json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw), true) : null;
    if (!is_array($j) || $j === []) {
        fwrite(STDERR, "No se pudo leer /json/$t (vía: $via)\n");
        exit(1);
    }
    foreach ($j as $row) {
        $org[$t][(int) $row['pk']] = $row['fields'];
    }
}
// cruce por posición de pista contra discos ya existentes (ver lib/dmp_dedup.php)
require_once __DIR__ . '/lib/dmp_dedup.php';
$dedup = new DmpDedup($pdo, "$cache/discos", "$cache/json");

$marchaOrgPorTitulo = [];
foreach ($org['marchas'] as $pk => $f) {
    $marchaOrgPorTitulo[clave_titulo((string) $f['nombre'])][] = $pk;
}
$autorOrgPorNombre = [];
foreach ($org['autores'] as $pk => $f) {
    $autorOrgPorNombre[clave_titulo((string) $f['nombre'])][] = $pk;
}
$idsDisco = array_values(array_filter(array_keys($org['discos']), static fn(int $pk): bool => $pk >= $desde && $pk <= $hasta));
sort($idsDisco);
$total = count($idsDisco);

$mapaEstilo = [];
foreach ($org['bandas'] as $f) {
    $pb = banda_desde_json($f);
    $k = ($pb['prefijo'] ?? '?') . ' → estilo ' . ($f['estilo'] ?? 'null');
    $mapaEstilo[$k] = ($mapaEstilo[$k] ?? 0) + 1;
}
ksort($mapaEstilo);

log_linea(sprintf('Inicio · %d discos en /json/discos (filtrados %d) · %d bandas · %d marchas · %d autores · pausa %d ms%s',
    count($org['discos']), $total, count($org['bandas']), count($org['marchas']), count($org['autores']),
    $pausaMs, $sinRed ? ' · sin red' : ''));

// ---------------------------------------------------------------- recorrido

$bandasOrigen = [];
$out = ['bandas' => [], 'autores' => [], 'marchas' => [], 'discos' => []];
$refAutor = [];
$refMarcha = [];
$autorConfirmado = [];   // ref de autor en duda => [ID_AUTOR BD => nº de marchas que lo confirman]
$cont = ['discos_leidos' => 0, 'discos_ct_am' => 0, 'fallos_parseo' => 0, 'paginas_vacias' => 0,
         'descargados' => 0, 'de_cache' => 0, 'no_existen_404' => 0, 'errores_http' => 0,
         'discos_ya_en_bd' => 0, 'pistas_sin_marcha_origen' => 0, 'discos_sin_banda_origen' => 0];

foreach ($idsDisco as $k => $id) {
    $via = null;
    $html = obtener(ORIGEN . "/discos/$id/", "$cache/discos/$id.html", $pausaMs, $sinRed, $errores, $via);
    match ($via) {
        'red'             => $cont['descargados']++,
        'cache'           => $cont['de_cache']++,
        '404', 'cache404' => $cont['no_existen_404']++,
        'error'           => $cont['errores_http']++,
        default           => null,
    };
    if ($via === 'red' && $cont['descargados'] % 5 === 0) {
        $trans = microtime(true) - $t0;
        log_linea(sprintf(
            'disco %d (%d/%d) · descargados %d · caché %d · CT/AM %d · errores %d · %s transcurrido · ~%s restante',
            $id, $k + 1, $total, $cont['descargados'], $cont['de_cache'], $cont['discos_ct_am'], count($errores),
            hms($trans), hms(($total - $k - 1) * ($trans / ($k + 1)))
        ));
    }
    if ($html === null || $html === '') {
        continue;
    }
    $cont['discos_leidos']++;
    if (stripos($html, 'Grabado por') === false) {
        $cont['paginas_vacias']++;
        $errores[] = ['url' => ORIGEN . "/discos/$id/", 'error' => 'pagina_vacia_pese_a_estar_en_json'];
        continue;
    }
    if (!preg_match('#/bandas/\d+#', $html)) {
        // ficha sin banda ni pistas en el propio origen (p. ej. disco 5358): no es un fallo de parseo
        $cont['discos_sin_banda_origen']++;
        continue;
    }
    $d = parse_disco($html);
    if ($d === null) {
        $cont['fallos_parseo']++;
        $errores[] = ['url' => ORIGEN . "/discos/$id/", 'error' => 'parseo_disco'];
        continue;
    }

    // ---- banda (desde /json/bandas)
    $ob = $d['id_banda'];
    $pb = isset($org['bandas'][$ob]) ? banda_desde_json($org['bandas'][$ob]) : null;
    if ($pb === null) {
        $errores[] = ['url' => ORIGEN . "/bandas/$ob/", 'error' => 'banda_no_en_json_o_sin_prefijo'];
        continue;
    }
    if (!isset(ESTILOS[$pb['prefijo']])) {
        continue; // BM y demás: fuera de scope
    }
    $cont['discos_ct_am']++;
    $estilo = ESTILOS[$pb['prefijo']];

    if (!isset($bandasOrigen[$ob])) {
        $c = cruzar_banda($pb, $bdBandas);
        $ref = null;
        if ($c['estado'] !== 'existe') {
            $flags = ['pendiente_nombre_oficial'];
            if ($pb['localidad'] !== null && $pb['provincia'] !== null
                && !isset($municipios[norm($pb['provincia']) . '|' . norm($pb['localidad'])])) {
                $flags[] = 'localidad_no_en_municipio';
            }
            $ref = 'B' . (count($out['bandas']) + 1);
            $out['bandas'][] = [
                'ref' => $ref, 'estado' => $c['estado'], 'motivo' => $c['motivo'] ?? null,
                'fila' => ['NOMBRE_COMPLETO' => null, 'NOMBRE_BREVE' => null,
                           'LOCALIDAD' => $pb['localidad'], 'PROVINCIA' => $pb['provincia'],
                           'FECHA_FUND' => null, 'FECHA_EXT' => null, 'DIRECTOR_ACTUAL' => null,
                           'DIR_MUS_ACTUAL' => null, 'WEB' => null, 'LINK_FORO' => null],
                'origen' => ['url' => ORIGEN . "/bandas/$ob/", 'pk' => $ob] + $pb,
                'flags' => $flags, 'candidatos' => $c['candidatos'],
            ];
            $idx = count($out['bandas']) - 1;
            if ($c['matriz'] !== null) {
                // convención del pipeline: relación juvenil → matriz
                $out['bandas'][$idx]['banda_relacion'] = ['ID_ORIGEN' => null, 'ref_origen' => $ref, 'ID_DESTINO' => $c['matriz'],
                    'TIPO' => 'juvenil', 'FECHA_INICIO' => null, 'FECHA_FIN' => null, 'NOTA' => null];
                $out['bandas'][$idx]['flags'][] = 'matriz_por_confirmar';
            } elseif (($c['matrices'] ?? []) !== []) {
                $out['bandas'][$idx]['matrices_candidatas'] = $c['matrices'];
                $out['bandas'][$idx]['flags'][] = 'juvenil_varias_matrices_posibles';
            } elseif (in_array('juvenil', tokens_banda(norm($pb['nucleo'])), true)) {
                $out['bandas'][$idx]['flags'][] = 'juvenil_sin_matriz_en_bd';
            }
        }
        $bandasOrigen[$ob] = ['ref' => $ref, 'id' => $c['id']];
    }
    $banda = $bandasOrigen[$ob];

    // ---- disco (datos desde /json/discos)
    $fd = $org['discos'][$id];
    $titulo = sin_info((string) ($fd['nombre'] ?? '')) ?? $d['titulo'];
    $anio = sin_info(isset($fd['ano_grabacion']) ? (string) $fd['ano_grabacion'] : null) ?? $d['anio'];
    if ($anio !== null && !preg_match('/^(1[89]|20)\d\d$/', $anio)) {
        $anio = null; // el origen usa 0 como "sin año"
    }

    $discoExiste = null;
    $discoCand = [];
    if ($banda['id'] !== null) {
        foreach ($discosPorBanda[$banda['id']] ?? [] as $bd) {
            $s = ratio(norm($titulo), norm($bd['NOMBRE_CD']));
            if ($s >= UMBRAL_EXISTE && ($anio === null || $bd['FECHA_CD'] === null
                || substr((string) $bd['FECHA_CD'], 0, 4) === $anio)) {
                $discoExiste = (int) $bd['ID_DISCO'];
            } elseif ($s >= UMBRAL_DUDA) {
                $discoCand[] = $bd + ['score' => round($s, 3)];
            }
        }
    }

    // disco ya en BD aunque el nombre difiera ("San Bernardo" vs "Salud de San Bernardo"): banda + solapamiento de
    // pistas. Una antología/reedición con las mismas pistas no cuenta como el mismo disco, pero sí sirve para
    // reconocer por posición las marchas que ya existen.
    $porPistas = $dedup->discoBd($id);
    if ($discoExiste === null && $porPistas !== null && $porPistas['fiable']) {
        $discoExiste = $porPistas['id_disco'];
    }

    // ---- pistas
    $pistasFila = [];
    foreach ($d['pistas'] as $iPista => $p) {
        $autorIds = [];
        $autorRefs = [];
        $autorCands = [];
        $autorDesconocido = false;
        $tipoGen = $p['autor'] !== null ? autor_generico($p['autor']) : null;
        $autorGenerico = $tipoGen !== null;
        $apellidos = null;
        $apellidosPista = [];
        if ($p['autor'] !== null && $tipoGen !== 'varios') {
            $ka = norm($p['autor']);
            if (!isset($refAutor[$ka])) {
                $ca = cruzar_autor($p['autor'], $bdAutores, $autorPorClave);
                if ($ca['estado'] === 'existe') {
                    $refAutor[$ka] = ['id' => $ca['id'], 'ref' => null, 'apellidos' => $ca['apellidos'], 'cands' => [], 'estado' => 'existe'];
                } else {
                    $r = 'A' . (count($out['autores']) + 1);
                    $pkA = $p['id_autor'] ?? (count($autorOrgPorNombre[clave_titulo($p['autor'])] ?? []) === 1
                        ? $autorOrgPorNombre[clave_titulo($p['autor'])][0] : null);
                    $out['autores'][] = [
                        'ref' => $r, 'estado' => $ca['estado'],
                        'fila' => ['APELLIDOS' => $ca['apellidos'], 'NOMBRE' => $ca['nombre'], 'NOMBRE_ART' => null,
                                   'F_NAC' => null, 'F_DEF' => null, 'LUGAR_NAC' => null, 'BIO' => null],
                        'origen' => ['texto' => $p['autor'], 'pk' => $pkA],
                        'flags' => $ca['flags'], 'candidatos' => $ca['candidatos'],
                    ];
                    $refAutor[$ka] = ['id' => null, 'ref' => $r, 'apellidos' => $ca['apellidos'],
                                      'cands' => array_column($ca['candidatos'], 'ID_AUTOR'), 'estado' => $ca['estado']];
                }
            }
            $apellidos = $refAutor[$ka]['apellidos'];
            $ap1 = primer_apellido($apellidos ?? $p['autor']);
            if ($ap1 !== '') {
                $apellidosPista[] = $ap1;
            }
            if ($refAutor[$ka]['id'] !== null) {
                $autorIds[] = $refAutor[$ka]['id'];
            } else {
                $autorRefs[] = $refAutor[$ka]['ref'];
                array_push($autorCands, ...$refAutor[$ka]['cands']);
                $autorDesconocido = $autorDesconocido || $refAutor[$ka]['estado'] === 'nuevo';
            }
        }

        [$tituloM, $tflags] = limpiar_titulo($p['titulo'], $apellidos);
        $km = norm_titulo($tituloM) . '|' . ($autorIds ? implode(',', $autorIds) : implode(',', $autorRefs));
        $porPos = !isset($refMarcha[$km]) && $porPistas !== null
            ? $dedup->cruzarPista($id, $iPista, $tituloM, $autorIds) : null;
        if ($porPos !== null && $porPos['clase'] === 'existe') {
            // la pista ocupa en un disco que ya está en BD la posición de una marcha existente: no es marcha nueva
            $refMarcha[$km] = ['id' => $porPos['id_marcha'], 'ref' => null];
        }
        if (!isset($refMarcha[$km])) {
            $cm = cruzar_marcha($tituloM, $autorIds, $autorCands, $autorDesconocido, $apellidosPista, $apellidoAutorBd,
                                $bdMarchas, $marchaPorToken, $autorGenerico);
            if ($cm['autor_confirmado'] !== null) {
                foreach ($autorRefs as $ar) {
                    $autorConfirmado[$ar][$cm['autor_confirmado']] = ($autorConfirmado[$ar][$cm['autor_confirmado']] ?? 0) + 1;
                }
            }
            if ($cm['estado'] === 'existe') {
                $refMarcha[$km] = ['id' => $cm['id'], 'ref' => null];
            } else {
                // datos de la marcha en /json/marchas: por enlace de la pista o por título exacto único
                $pkM = $p['id_marcha'];
                $flagsM = $tflags;
                if ($pkM === null) {
                    $cands = $marchaOrgPorTitulo[clave_titulo($p['titulo'])] ?? [];
                    if (count($cands) === 1) {
                        $pkM = $cands[0];
                    } elseif (count($cands) > 1) {
                        $flagsM[] = 'varias_marchas_origen_mismo_titulo';
                    } else {
                        $cont['pistas_sin_marcha_origen']++;
                        $flagsM[] = 'sin_ficha_marcha_origen';
                    }
                }
                $fm = $pkM !== null ? ($org['marchas'][$pkM] ?? null) : null;
                [$dedic, $locDedic] = texto_y_lugar($fm['dedicada'] ?? null);
                $anioC = sin_info(isset($fm['ano_composicion']) ? (string) $fm['ano_composicion'] : null);
                if ($anioC !== null && !preg_match('/^\d{4}$/', $anioC)) {
                    $flagsM[] = 'fecha_origen_no_es_anio';
                }
                if ($dedic !== null) {
                    $flagsM[] = 'dedicatoria_por_normalizar';
                }
                $durM = duracion_seg($fm['duracion'] ?? null);
                if ($durM !== null) {
                    $flagsM[] = 'duracion_de_una_grabacion_origen';
                }

                $r = 'M' . (count($out['marchas']) + 1);
                if ($cm['motivo'] !== null) {
                    $flagsM[] = $cm['motivo'];
                }
                if ($tipoGen === 'varios') {
                    $flagsM[] = 'autores_sin_detallar_en_origen';
                }
                $out['marchas'][] = [
                    'ref' => $r, 'estado' => $cm['estado'], 'motivo' => $cm['motivo'],
                    'fila' => ['TITULO' => $tituloM,
                               'FECHA' => ($anioC !== null && preg_match('/^\d{4}$/', $anioC)) ? (int) $anioC : null,
                               'DETALLES_MARCHA' => null, 'DEDICATORIA' => $dedic, 'LOCALIDAD' => $locDedic,
                               'PROVINCIA' => null, 'TIPO' => null, 'BANDA_ESTRENO' => null,
                               'DURACION_SEG' => $durM, 'AUDIO' => null, 'DATOS_INT' => null, 'ESTILO' => $estilo],
                    'marcha_autor' => ['ID_AUTOR' => $autorIds, 'ref_autor' => $autorRefs],
                    'origen' => ['titulo' => $p['titulo'], 'pk' => $pkM, 'campos' => $fm,
                                 'discos' => [ORIGEN . "/discos/$id/"]],
                    'flags' => array_merge($flagsM, ['estilo_segun_banda_que_graba']),
                    'candidatos' => $cm['candidatos'],
                ];
                $refMarcha[$km] = ['id' => null, 'ref' => $r, 'idx' => count($out['marchas']) - 1];
            }
        } elseif (isset($refMarcha[$km]['idx'])) {
            $mm = &$out['marchas'][$refMarcha[$km]['idx']];
            $mm['origen']['discos'][] = ORIGEN . "/discos/$id/";
            if ($mm['fila']['ESTILO'] !== null && $mm['fila']['ESTILO'] !== $estilo) {
                $mm['fila']['ESTILO'] = null;
                $mm['flags'][] = 'estilo_conflicto_ct_am';
            }
            unset($mm);
        }

        $pistasFila[] = [
            'N_DISCO' => null, 'NUMEROMARCHA' => $p['n'],
            'IDMARCHA' => $refMarcha[$km]['id'], 'ref_marcha' => $refMarcha[$km]['ref'],
            'DM_DETALLES' => null, 'DM_BANDA' => null, 'DM_ENLAZADA' => null,
            'DURACION_SEG' => null, 'PERCUSION' => null,
        ];
    }

    if ($discoExiste !== null) {
        $cont['discos_ya_en_bd']++;
        continue;
    }
    $flagsD = [];
    if ($d['pistas'] === []) {
        $flagsD[] = 'sin_pistas_parseadas';
    }
    if (array_filter($d['pistas'], static fn(array $p): bool => $p['n'] === null)) {
        $flagsD[] = 'pista_sin_numero';
    }
    if ($d['titulo'] !== '' && norm($d['titulo']) !== norm($titulo)) {
        $flagsD[] = 'titulo_pagina_distinto_de_json';
    }
    $out['discos'][] = [
        'ref' => 'D' . (count($out['discos']) + 1),
        'estado' => $discoCand !== [] ? 'duda' : 'nuevo',
        'fila' => ['NOMBRE_CD' => $titulo, 'FECHA_CD' => $anio,
                   'BANDADISCO' => $banda['id'], 'D_DETALLES' => null, 'PERCUSION' => 0, 'PERCUSION_SEG' => 40],
        'ref_banda' => $banda['ref'],
        'disco_marcha' => $pistasFila,
        'origen' => ['url' => ORIGEN . "/discos/$id/", 'pk' => $id, 'banda_texto' => $d['banda_texto'],
                     'discografica' => sin_info($fd['discografica'] ?? null), 'codigo' => sin_info($fd['codigo'] ?? null),
                     'grabado_en' => sin_info($fd['grabado_en'] ?? null), 'notas' => sin_info($fd['notas'] ?? null),
                     'portada' => sin_info($fd['foto_disco'] ?? null)],
        'flags' => $flagsD, 'candidatos' => $discoCand,
    ];
}

// autores en duda que las marchas confirman con un único autor de la BD → 'probable'
foreach ($out['autores'] as &$au) {
    $conf = $autorConfirmado[$au['ref']] ?? [];
    if ($conf === []) {
        continue;
    }
    $au['confirmado_por_marchas'] = $conf;
    if (in_array($au['estado'], ['duda', 'nuevo'], true) && count($conf) === 1) {
        $au['estado'] = 'probable';
        $au['ID_AUTOR_PROBABLE'] = (int) array_key_first($conf);
    }
}
unset($au);

$resumen = $cont;
foreach (['bandas', 'autores', 'marchas', 'discos'] as $kk) {
    $resumen[$kk] = array_count_values(array_column($out[$kk], 'estado'));
}
$resumen['errores'] = count($errores);
$resumen['bandas_por_motivo'] = array_count_values(array_filter(array_column($out['bandas'], 'motivo')));
ksort($resumen['bandas_por_motivo']);
$resumen['marchas_por_motivo'] = array_count_values(array_filter(array_column($out['marchas'], 'motivo')));
ksort($resumen['marchas_por_motivo']);
$resumen['prefijo_vs_estilo_origen'] = $mapaEstilo;

file_put_contents((string) $opt['salida'], json_encode(
    ['generado' => date('c'), 'rango_pk' => [$desde, $hasta === PHP_INT_MAX ? null : $hasta], 'resumen' => $resumen]
        + $out + ['errores' => $errores],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

$fmt = static fn(array $e): string => sprintf('%d nuevos · %d dudas', $e['nuevo'] ?? 0, $e['duda'] ?? 0)
    . (isset($e['probable']) ? sprintf(' · %d probables', $e['probable']) : '');
log_linea('Fin · ' . hms(microtime(true) - $t0));
echo PHP_EOL, 'RESUMEN', PHP_EOL, str_repeat('-', 60), PHP_EOL;
printf("Discos en /json/discos   %d (procesados %d)%s", count($org['discos']), $total, PHP_EOL);
printf("Descargados              %d%s", $cont['descargados'], PHP_EOL);
printf("Leídos de caché          %d%s", $cont['de_cache'], PHP_EOL);
printf("Páginas vacías           %d%s", $cont['paginas_vacias'], PHP_EOL);
printf("Discos CT/AM             %d (ya en BD: %d)%s", $cont['discos_ct_am'], $cont['discos_ya_en_bd'], PHP_EOL);
printf("Fallos de parseo         %d%s", $cont['fallos_parseo'], PHP_EOL);
printf("Fichas sin banda origen  %d%s", $cont['discos_sin_banda_origen'], PHP_EOL);
printf("Pistas sin ficha origen  %d%s", $cont['pistas_sin_marcha_origen'], PHP_EOL);
printf("Errores                  %d%s", count($errores), PHP_EOL);
printf("Bandas                   %s%s", $fmt($resumen['bandas']), PHP_EOL);
printf("Autores                  %s%s", $fmt($resumen['autores']), PHP_EOL);
printf("Marchas                  %s%s", $fmt($resumen['marchas']), PHP_EOL);
printf("Discos                   %s%s", $fmt($resumen['discos']), PHP_EOL);
echo 'Bandas por motivo        ', json_encode($resumen['bandas_por_motivo'], JSON_UNESCAPED_UNICODE), PHP_EOL;
echo 'Marchas por motivo       ', json_encode($resumen['marchas_por_motivo'], JSON_UNESCAPED_UNICODE), PHP_EOL;
echo 'Prefijo → estilo origen  ', json_encode($mapaEstilo, JSON_UNESCAPED_UNICODE), PHP_EOL;
printf("Salida                   %s%s", $opt['salida'], PHP_EOL);
