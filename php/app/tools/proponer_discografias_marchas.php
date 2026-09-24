<?php
declare(strict_types=1);

/**
 * proponer_discografias_marchas.php
 *
 * Recorre https://discografiasdemarchasprocesionales.com/marchas/{pk} para pk
 * entre --desde y --hasta (por defecto 100..5999; hay muchos huecos, no todos
 * los pk existen). Guarda la marcha solo si, en su tabla "Marcha Incluida en",
 * alguna fila de banda empieza por (CT) o por (AM) — el resto de prefijos
 * (BM, etc.) quedan fuera de scope. Descarta también los títulos "(Ordinario)".
 *
 * Cruce contra duplicados, en este orden:
 *   1. título+autor vs. marcha/marcha_autor de la BD: ratio > 0.9 → ya existe, se descarta.
 *   2. título+autor vs. ingest_candidato (FUENTE='dmp', pendiente|aceptado) ya
 *      cargados hoy desde el barrido por disco: ratio > 0.9 → colisión.
 *        - si el existente está 'pendiente': en vez de descartar sin más, se
 *          queda la versión con más información — se rellenan en el candidato
 *          YA existente los campos (P_FECHA, P_DEDICATORIA, P_LOCALIDAD,
 *          P_PROVINCIA, P_AUTORES, P_ESTILO, DURACION_SEG) que tenga vacíos y
 *          la ficha nueva sí trae; si un campo tiene valor distinto en ambos
 *          lados no se pisa, se anota como discrepancia en FLAGS para que lo
 *          resuelva el revisor. No se inserta una fila nueva en este caso.
 *        - si ya está 'aceptado' (la marcha real ya se creó) o 'descartado'/
 *          'duplicado' (decisión ya tomada): no se toca, se descarta.
 *   3. si ninguno de los dos anteriores supera 0.9 (duda): por cada fila CT/AM de
 *      "incluida en", intenta resolver la banda y el disco de esa fila contra la
 *      BD; si los resuelve, comprueba disco_marcha — si esa marcha ya está
 *      enlazada a ese disco/banda, se descarta (ya está cargada bajo otro título).
 *   Si nada de lo anterior encuentra coincidencia: candidata nueva.
 *
 * Salida: candidatos nuevos como filas FUENTE='dmp' en ingest_candidato (mismo
 * cauce que el panel /dashboard/ingesta ya usa para las 264 filas del barrido
 * por disco de hoy), con VIDEO_ID='dmp-marcha-{pk}' — namespace propio, no
 * choca con los 'dmp-M{n}' del barrido anterior; más, cuando aplica, los UPDATE
 * de enriquecimiento descritos arriba sobre candidatos 'dmp' ya pendientes. NO
 * escribe en la BD salvo que se pase --aplicar: sin ese flag genera solo el
 * informe (JSON + resumen por consola) con lo que se propondría. Con --aplicar:
 *   - las candidatas nuevas quedan agrupadas bajo un ingest_run propio; para
 *     deshacer esa tanda: DELETE FROM ingest_candidato WHERE ID_RUN = <id impreso al final>;
 *   - los enriquecimientos son UPDATE sobre filas que ya existían: no hay un
 *     ID_RUN que los agrupe, pero cada uno queda anotado en FLAGS (con fecha y
 *     URL de origen) y los campos exactos que se guardan salen listados en el
 *     informe JSON antes de aplicarlos, para poder revisar qué cambió.
 *
 * Uso (PowerShell):
 *   php php\app\tools\proponer_discografias_marchas.php --db=php\data\mdc.db
 *       --cache="$env:TEMP\dmp_marchas_cache" --informe="$env:TEMP\dmp_marchas_informe.json" `
 *       --cache-discos="$env:TEMP\dmp_cache"   (opcional: cruce por posición de pista, ver lib/dmp_dedup.php)
 *       [--desde=100] [--hasta=5999] [--pausa-ms=1000] [--sin-red] [--aplicar]
 */

const ORIGEN = 'https://discografiasdemarchasprocesionales.com';
const UMBRAL_DUPLICADO = 0.9;   // título+autor vs. BD / ingest_candidato dmp: por encima, ya existe
const UMBRAL_BANDA = 0.85;      // banda/disco/título en el chequeo secundario de "duda"
const ESTILOS = ['CT' => 'CCTT', 'AM' => 'AM'];

$opt = getopt('', ['db:', 'cache:', 'informe:', 'desde::', 'hasta::', 'pausa-ms::', 'sin-red', 'aplicar', 'cache-discos::']);
foreach (['db', 'cache', 'informe'] as $req) {
    if (empty($opt[$req])) {
        fwrite(STDERR, "Falta --$req\n");
        exit(2);
    }
}
$desde   = (int) ($opt['desde'] ?? 100);
$hasta   = (int) ($opt['hasta'] ?? 5999);
$pausaMs = (int) ($opt['pausa-ms'] ?? 1000);
$sinRed  = isset($opt['sin-red']);
$aplicar = isset($opt['aplicar']);
$cache   = rtrim((string) $opt['cache'], '/\\');
if (!is_dir($cache) && !mkdir($cache, 0777, true)) {
    fwrite(STDERR, "No se puede crear $cache\n");
    exit(2);
}

// ---------------------------------------------------------------- normalización (igual que proponer_discografias.php)

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

function norm_titulo(?string $t): string
{
    return (string) preg_replace('/^(el|la|los|las) /', '', norm($t));
}

/**
 * Apellidos normalizados de uno o varios autores en formato origen
 * ("Apellidos, Nombre" o "Apellidos1, Nombre1 / Apellidos2, Nombre2"),
 * en el mismo formato que GROUP_CONCAT(a.APELLIDOS) sobre marcha_autor:
 * solo apellidos, unidos por ' / ', para que la clave de duplicado sea
 * comparable entre BD, ingest_candidato y las fichas del origen.
 */
function apellidos_de(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    $partes = array_map(static function (string $autor): string {
        $p = explode(',', trim($autor), 2);
        return trim($p[0]);
    }, explode(' / ', $raw));
    return norm(implode(' / ', array_filter($partes, static fn(string $a): bool => $a !== '')));
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

/** "2´40´´" → 160 */
function duracion_seg(?string $v): ?int
{
    $v = sin_info($v);
    if ($v === null || !preg_match('/^\s*(\d{1,2})\D+(\d{1,2})\D*$/u', $v, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

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

/** "Marchas Procesionales (1987)" → ['Marchas Procesionales', '1987'] (año puede faltar) */
function texto_y_anio(string $v): array
{
    if (preg_match('/^(.*\S)\s*\((\d{4})\)\s*$/u', $v, $m)) {
        return [trim($m[1]), $m[2]];
    }
    return [trim($v), null];
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

// ---------------------------------------------------------------- red + caché (igual que proponer_discografias.php)

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
    if ($codigo !== 200 || !is_string($body)) {
        $errores[] = ['url' => $url, 'http' => $codigo];
        $via = 'error';
        return null;
    }
    file_put_contents($fichero, $body);
    $via = 'red';
    return $body;
}

// ---------------------------------------------------------------- parseo de /marchas/{pk}

function dom(string $html): DOMXPath
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}

/** @return array<string,mixed>|null null si la ficha no existe (hueco) */
function parse_marcha(string $html): ?array
{
    if (stripos($html, 'no existe') !== false) {
        return null;
    }
    $xp = dom($html);

    $tituloNode = $xp->query('//div[@class="container_titulo"]//a[@class="titulo_disco"]');
    if ($tituloNode === false || $tituloNode->length === 0) {
        return null;
    }
    $titulo = trim($tituloNode->item(0)->textContent);

    $autores = [];
    foreach ($xp->query('//div[@class="linea_detalle"]/a[contains(@href,"/autores/")]') ?: [] as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $href = $a->getAttribute('href');
        if (preg_match('#/autores/(\d+)/?#', $href, $m)) {
            $autores[] = ['id' => (int) $m[1], 'nombre' => trim($a->textContent)];
        }
    }

    $campos = [];
    foreach ($xp->query('//div[@class="linea_detalle"]/a[b]') ?: [] as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $texto = trim($a->textContent);
        if (preg_match('/^([^:]+):\s*(.*)$/u', $texto, $m)) {
            $campos[trim($m[1])] = sin_info($m[2]);
        }
    }
    $dedicada = $campos['Dedicada'] ?? null;
    $anio = $campos['Año de Composicion'] ?? $campos['Año de Composición'] ?? null;
    $duracion = $campos['Duracion'] ?? $campos['Duración'] ?? null;

    $incluidaEn = [];
    foreach ($xp->query('//table[contains(@class,"tabla-marchas")]//tbody/tr') ?: [] as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds === false || $tds->length < 2) {
            continue;
        }
        $disco = trim($tds->item(0)->textContent);
        $banda = trim($tds->item(1)->textContent);
        $idDisco = null;
        if ($tr instanceof DOMElement && preg_match('#/discos/(\d+)#', $tr->getAttribute('onclick'), $m)) {
            $idDisco = (int) $m[1];
        }
        $prefijo = preg_match('/^\(([A-Z]+)\)/', $banda, $m) ? $m[1] : null;
        $incluidaEn[] = ['id_disco' => $idDisco, 'disco' => $disco, 'banda' => $banda, 'prefijo' => $prefijo];
    }

    return [
        'titulo' => $titulo, 'autores' => $autores, 'dedicada' => $dedicada,
        'anio' => $anio, 'duracion' => $duracion, 'incluida_en' => $incluidaEn,
    ];
}

function es_ordinario(string $titulo): bool
{
    return (bool) preg_match('/\(\s*ordinario\s*\)/iu', $titulo);
}

/** @param list<array<string,mixed>> $incluidaEn @return list<array<string,mixed>> solo filas (CT)/(AM) */
function filas_ct_am(array $incluidaEn): array
{
    return array_values(array_filter($incluidaEn, static fn(array $f): bool => in_array($f['prefijo'], ['CT', 'AM'], true)));
}

// ---------------------------------------------------------------- BD (lectura para el cruce; escritura solo con --aplicar)

$pdo = new PDO('sqlite:' . $opt['db'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => $aplicar ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY,
]);
$q = static fn(string $sql, array $p = []): array => ($st = $pdo->prepare($sql)) && $st->execute($p) ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

// -- marchas existentes: clave título normalizado + primer apellido de cada autor, para el ratio() de duplicado
$bdAutores = [];
foreach ($q('SELECT ID_AUTOR, APELLIDOS, NOMBRE, NOMBRE_ART FROM autor') as $r) {
    $bdAutores[] = [
        'id' => (int) $r['ID_AUTOR'],
        'clave' => norm(($r['APELLIDOS'] ?? '') . ' ' . ($r['NOMBRE'] ?? '')),
        'clave_art' => norm($r['NOMBRE_ART']),
    ];
}

/** "Apellidos, Nombre" del origen → ['id' => int, 'ya_en_bd' => bool] o null si no se resuelve */
function resolver_autor(string $rawNombre, array $bdAutores): ?int
{
    $partes = explode(',', $rawNombre, 2);
    $clave = norm(count($partes) === 2 ? trim($partes[0]) . ' ' . trim($partes[1]) : $rawNombre);
    foreach ($bdAutores as $a) {
        if ($a['clave'] === $clave || ($a['clave_art'] !== '' && $a['clave_art'] === $clave)) {
            return $a['id'];
        }
    }
    return null;
}

$bdMarchas = [];
foreach ($q("SELECT m.ID_MARCHA, m.TITULO, m.ESTILO,
                    (SELECT GROUP_CONCAT(a.APELLIDOS, ' / ') FROM marcha_autor ma
                     JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR WHERE ma.ID_MARCHA = m.ID_MARCHA) AS AUTORES
             FROM marcha m") as $r) {
    $bdMarchas[] = [
        'id' => (int) $r['ID_MARCHA'], 'titulo' => $r['TITULO'], 'estilo' => $r['ESTILO'],
        'clave' => norm_titulo($r['TITULO']) . '|' . norm((string) $r['AUTORES']),
    ];
}

// -- candidatos dmp ya pendientes/aceptados de hoy (barrido por disco), para no duplicar la cola del panel;
//    se guardan completos para poder enriquecer el existente si el nuevo trae más datos (ver completar_campos())
$dmpPendientes = [];
foreach ($q("SELECT ID_CAND, ESTADO, VIDEO_URL, FLAGS, P_TITULO, P_FECHA, P_DEDICATORIA, P_LOCALIDAD,
                    P_PROVINCIA, P_AUTORES, P_ESTILO, DURACION_SEG
             FROM ingest_candidato WHERE FUENTE = 'dmp' AND ESTADO IN ('pendiente', 'aceptado')") as $r) {
    $dmpPendientes[] = $r + [
        'id' => (int) $r['ID_CAND'],
        'clave' => norm_titulo((string) $r['P_TITULO']) . '|' . apellidos_de($r['P_AUTORES']),
    ];
}

// -- bandas + sus discos, para el chequeo secundario banda/disco en caso de duda
$bdBandas = [];
foreach ($q('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD FROM banda') as $r) {
    if (!preg_match('/^(AM|BCT|CCTT|BM)\b/u', trim((string) $r['NOMBRE_BREVE']), $m)) {
        continue;
    }
    $estilo = ['AM' => 'AM', 'BCT' => 'CT', 'CCTT' => 'CT', 'BM' => 'BM'][$m[1]];
    if (!isset(ESTILOS[$estilo])) {
        continue; // BM y demás fuera de scope también aquí
    }
    $tokNombre = tokens_banda(norm(($r['NOMBRE_COMPLETO'] ?? '') . ' ' . $r['NOMBRE_BREVE']));
    $sinLoc = array_values(array_diff($tokNombre, tokens_banda(norm($r['LOCALIDAD']))));
    $bdBandas[] = [
        'id' => (int) $r['ID_BANDA'], 'estilo' => $estilo,
        'tok' => $sinLoc !== [] ? $sinLoc : $tokNombre, 'nombre' => $r['NOMBRE_COMPLETO'] ?? $r['NOMBRE_BREVE'],
    ];
}
$discosPorBanda = [];
foreach ($q('SELECT ID_DISCO, NOMBRE_CD, BANDADISCO FROM disco') as $r) {
    $discosPorBanda[(int) $r['BANDADISCO']][] = ['id' => (int) $r['ID_DISCO'], 'nombre' => $r['NOMBRE_CD']];
}

// --cache-discos: carpeta dmp_cache de proponer_discografias.php (discos/ y json/); activa el cruce por posición
$dedup = null;
if (!empty($opt['cache-discos'])) {
    $cd = rtrim((string) $opt['cache-discos'], '/\\');
    require_once __DIR__ . '/lib/dmp_dedup.php';
    $dedup = new DmpDedup($pdo, "$cd/discos", "$cd/json");
}

log_linea(sprintf('BD cargada: %d marchas, %d bandas CT/AM, %d dmp pendientes de hoy',
    count($bdMarchas), count($bdBandas), count($dmpPendientes)));

// ---------------------------------------------------------------- cruce de duplicados

/** @return array<string,mixed>|null la fila de $lista más parecida (+'ratio'), si supera UMBRAL_DUPLICADO */
function mejor_ratio_duplicado(string $clave, array $lista): ?array
{
    $mejor = null;
    foreach ($lista as $r) {
        if ($r['clave'] === '' || $r['clave'] === '|') {
            continue;
        }
        $s = ratio($clave, $r['clave']);
        if ($mejor === null || $s > $mejor['ratio']) {
            $mejor = $r + ['ratio' => $s];
        }
    }
    return ($mejor !== null && $mejor['ratio'] > UMBRAL_DUPLICADO) ? $mejor : null;
}

/**
 * Compara los campos "de contenido" de un candidato dmp ya pendiente contra
 * los datos recién sacados de la ficha, para quedarnos con la versión más
 * completa: si el existente tiene el campo vacío y el nuevo trae valor, se
 * propone rellenarlo; si ambos tienen valor distinto, se anota como
 * discrepancia (no se pisa automáticamente, lo decide el revisor).
 * @return array{cambios:array<string,mixed>,conflictos:array<string,array{actual:mixed,nuevo:mixed}>}
 */
function completar_campos(array $existente, array $nuevo): array
{
    $cambios = [];
    $conflictos = [];
    foreach (['P_FECHA', 'P_DEDICATORIA', 'P_LOCALIDAD', 'P_PROVINCIA', 'P_AUTORES', 'P_ESTILO', 'DURACION_SEG'] as $campo) {
        $act = $existente[$campo] ?? null;
        $act = ($act === '' ) ? null : $act;
        $nvo = $nuevo[$campo] ?? null;
        $nvo = ($nvo === '') ? null : $nvo;
        if ($nvo === null) {
            continue;
        }
        if ($act === null) {
            $cambios[$campo] = $nvo;
        } elseif ((string) $act !== (string) $nvo) {
            $conflictos[$campo] = ['actual' => $act, 'nuevo' => $nvo];
        }
    }
    return ['cambios' => $cambios, 'conflictos' => $conflictos];
}

/**
 * Chequeo secundario de "duda": para cada fila CT/AM de la ficha, intenta
 * resolver banda y disco contra la BD y comprueba si esa marcha ya está
 * enlazada (disco_marcha) a ese disco/banda con título parecido.
 * @return array{id_marcha:int,id_disco:int,id_banda:int}|null
 */
function ya_enlazada_por_banda_disco(string $tituloNorm, array $filasCtAm, array $bdBandas, array $discosPorBanda, PDO $pdo): ?array
{
    foreach ($filasCtAm as $fila) {
        [$bandaTxt] = preg_match('/^\(([A-Z]+)\)\s*(.+)$/u', $fila['banda'], $m) ? [$m[2]] : [null];
        if ($bandaTxt === null) {
            continue;
        }
        $prefijo = $m[1];
        $estilo = ['CT' => 'CT', 'AM' => 'AM'][$prefijo] ?? null;
        if ($estilo === null) {
            continue;
        }
        [$nucleo, $loc] = preg_match('/^(.*\S)\s*\(([^)]+)\)$/u', $bandaTxt, $mm) ? [$mm[1], $mm[2]] : [$bandaTxt, null];
        $tok = tokens_banda(norm($nucleo));
        if ($tok === []) {
            continue;
        }
        $bandaId = null;
        foreach ($bdBandas as $b) {
            if ($b['estilo'] !== $estilo) {
                continue;
            }
            $inter = count(array_intersect($tok, $b['tok']));
            if ($inter === 0) {
                continue;
            }
            $cob = max($inter / count($tok), $inter / max(count($b['tok']), 1));
            if ($cob >= UMBRAL_BANDA) {
                $bandaId = $b['id'];
                break;
            }
        }
        if ($bandaId === null) {
            continue;
        }
        [$discoTxt] = texto_y_anio($fila['disco']);
        $discoNorm = norm($discoTxt);
        $discoId = null;
        foreach ($discosPorBanda[$bandaId] ?? [] as $d) {
            if (ratio($discoNorm, norm((string) $d['nombre'])) >= UMBRAL_BANDA) {
                $discoId = $d['id'];
                break;
            }
        }
        if ($discoId === null) {
            continue;
        }
        $pistas = $pdo->prepare('SELECT m.ID_MARCHA, m.TITULO FROM disco_marcha dm
                                  JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA WHERE dm.ID_DISCO = ?');
        $pistas->execute([$discoId]);
        foreach ($pistas->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (ratio($tituloNorm, norm_titulo($p['TITULO'])) >= UMBRAL_BANDA) {
                return ['id_marcha' => (int) $p['ID_MARCHA'], 'id_disco' => $discoId, 'id_banda' => $bandaId];
            }
        }
    }
    return null;
}

// ---------------------------------------------------------------- recorrido 100..5999

$errores = [];
$t0 = microtime(true);
$cont = ['leidas' => 0, 'huecos' => 0, 'descargadas' => 0, 'de_cache' => 0, 'errores' => 0,
         'descartadas_bm' => 0, 'descartadas_ordinario' => 0, 'descartadas_dup_bd' => 0,
         'descartadas_dup_dmp_pendiente_sin_novedad' => 0, 'enriquecidas_dmp_pendiente' => 0,
         'descartadas_ya_enlazada' => 0, 'candidatas_nuevas' => 0];
$candidatas = [];
$enriquecidas = [];
$total = $hasta - $desde + 1;

for ($pk = $desde; $pk <= $hasta; $pk++) {
    $via = null;
    $html = obtener(ORIGEN . "/marchas/$pk", "$cache/$pk.html", $pausaMs, $sinRed, $errores, $via);
    match ($via) {
        'red'   => $cont['descargadas']++,
        'cache' => $cont['de_cache']++,
        'error' => $cont['errores']++,
        default => null,
    };
    if ($via === 'red' && $cont['descargadas'] % 50 === 0) {
        $k = $pk - $desde;
        $trans = microtime(true) - $t0;
        log_linea(sprintf('pk %d (%d/%d) · descargadas %d · caché %d · candidatas %d · %s transcurrido · ~%s restante',
            $pk, $k + 1, $total, $cont['descargadas'], $cont['de_cache'], $cont['candidatas_nuevas'],
            hms($trans), hms(($total - $k - 1) * ($trans / max($k + 1, 1)))));
    }
    if ($html === null || $html === '') {
        continue;
    }
    $m = parse_marcha($html);
    if ($m === null) {
        $cont['huecos']++;
        continue;
    }
    $cont['leidas']++;

    $filasCtAm = filas_ct_am($m['incluida_en']);
    if ($filasCtAm === []) {
        $cont['descartadas_bm']++;
        continue;
    }
    if (es_ordinario($m['titulo'])) {
        $cont['descartadas_ordinario']++;
        continue;
    }

    $tituloNorm = norm_titulo($m['titulo']);
    // clave de comparación: apellidos de los autores (antes de la primera coma de cada nombre "Apellidos, Nombre")
    $clave = $tituloNorm . '|' . apellidos_de(implode(' / ', array_column($m['autores'], 'nombre')));

    // ---- datos derivados de la ficha (se usan tanto para una candidata nueva como para enriquecer una existente)
    $estilosFilas = array_values(array_unique(array_column($filasCtAm, 'prefijo')));
    $estiloAmbiguo = count($estilosFilas) > 1;
    $pEstilo = $estiloAmbiguo ? null : ESTILOS[$estilosFilas[0]];

    $flagsAutor = [];
    if ($m['autores'] === []) {
        $pAutores = '';
    } else {
        $partes = [];
        foreach ($m['autores'] as $a) {
            $idBd = resolver_autor($a['nombre'], $bdAutores);
            $partes[] = $a['nombre'];
            $flagsAutor[] = $idBd !== null
                ? "Autor: {$a['nombre']} (id $idBd, ya en BD)"
                : "Autor: {$a['nombre']} — sin resolver, no encontrado en BD: comprobar/crear en el desplegable";
        }
        $pAutores = implode(' / ', $partes);
    }
    $listaBandas = implode('; ', array_map(
        static fn(array $f): string => $f['banda'] . ' — disco "' . $f['disco'] . '"',
        $filasCtAm
    ));
    [$dedicTxt, $dedicLoc] = texto_y_lugar($m['dedicada']);
    $anioC = ($m['anio'] !== null && preg_match('/^\d{4}$/', $m['anio'])) ? (int) $m['anio'] : null;
    $duracionC = duracion_seg($m['duracion']);
    $nuevoDatos = ['P_FECHA' => $anioC, 'P_DEDICATORIA' => $dedicTxt, 'P_LOCALIDAD' => $dedicLoc,
                   'P_PROVINCIA' => null, 'P_AUTORES' => $pAutores, 'P_ESTILO' => $pEstilo, 'DURACION_SEG' => $duracionC];

    $dupBd = mejor_ratio_duplicado($clave, $bdMarchas);
    if ($dupBd !== null) {
        $cont['descartadas_dup_bd']++;
        continue;
    }
    $dupDmp = mejor_ratio_duplicado($clave, $dmpPendientes);
    if ($dupDmp !== null) {
        if ($dupDmp['ESTADO'] !== 'pendiente') {
            // ya aceptado/descartado/duplicado: no se toca, la ficha real ya se creó o la decisión ya está tomada
            $cont['descartadas_dup_dmp_' . $dupDmp['ESTADO']] = ($cont['descartadas_dup_dmp_' . $dupDmp['ESTADO']] ?? 0) + 1;
            continue;
        }
        $comp = completar_campos($dupDmp, $nuevoDatos);
        if ($comp['cambios'] === [] && $comp['conflictos'] === []) {
            $cont['descartadas_dup_dmp_pendiente_sin_novedad']++;
            continue;
        }
        $enriquecidas[] = [
            'ID_CAND' => $dupDmp['id'], 'P_TITULO_existente' => $dupDmp['P_TITULO'], 'FLAGS_actual' => $dupDmp['FLAGS'],
            'origen_pk' => $pk, 'origen_url' => ORIGEN . "/marchas/$pk/",
            'cambios' => $comp['cambios'], 'conflictos' => $comp['conflictos'],
        ];
        $cont['enriquecidas_dmp_pendiente']++;
        continue;
    }
    $enlazada = null;
    if ($dedup !== null) {
        // por posición de pista: disco del origen → disco de la BD → NUMEROMARCHA (ver lib/dmp_dedup.php)
        $hintAutores = array_values(array_filter(array_map(
            static fn(array $a): ?int => resolver_autor($a['nombre'], $bdAutores), $m['autores'])));
        foreach ($filasCtAm as $fila) {
            $iP = $fila['id_disco'] !== null ? $dedup->indicePorMarcha($fila['id_disco'], $pk) : null;
            $r = $iP !== null ? $dedup->cruzarPista($fila['id_disco'], $iP, $m['titulo'], $hintAutores) : null;
            if ($r !== null && $r['clase'] === 'existe') {
                $enlazada = $r;
                break;
            }
        }
    }
    $enlazada ??= ya_enlazada_por_banda_disco($tituloNorm, $filasCtAm, $bdBandas, $discosPorBanda, $pdo);
    if ($enlazada !== null) {
        $cont['descartadas_ya_enlazada']++;
        continue;
    }

    // ---- candidata nueva
    $flags = $flagsAutor;
    if ($m['autores'] === []) {
        $flags[] = 'Sin autor en el origen';
    }
    if ($estiloAmbiguo) {
        $flags[] = 'Aparece con banda CT y AM a la vez en "incluida en": revisar estilo a mano';
    }
    $flags[] = 'Banda(s)/disco(s) CT o AM donde aparece (candidatas, sin cruzar con la BD): ' . $listaBandas;
    $primeraFila = $filasCtAm[0];

    $candidatas[] = [
        'VIDEO_ID' => "dmp-marcha-$pk",
        'VIDEO_URL' => ORIGEN . "/marchas/$pk/",
        'VIDEO_TITULO' => $m['titulo'],
        'VIDEO_DESC' => 'Importado desde discografiasdemarchasprocesionales.com (barrido por marcha, ' . date('Y-m-d') . ').',
        'DURACION_SEG' => $duracionC,
        'CLASIFICACION' => 'recuperacion',
        'CONFIANZA' => $m['autores'] !== [] && !$estiloAmbiguo ? 0.7 : 0.4,
        'FLAGS' => json_encode($flags, JSON_UNESCAPED_UNICODE),
        'P_TITULO' => $m['titulo'],
        'P_ESTILO' => $pEstilo,
        'FUENTE' => 'dmp',
        'FUENTE_ALBUM' => $primeraFila['disco'],
        'FUENTE_ALBUM_URL' => $primeraFila['id_disco'] !== null ? ORIGEN . '/discos/' . $primeraFila['id_disco'] . '/' : null,
        'origen_pk' => $pk,
    ] + $nuevoDatos;
    $cont['candidatas_nuevas']++;
}

// ---------------------------------------------------------------- informe / inserción

file_put_contents((string) $opt['informe'], json_encode(
    ['generado' => date('c'), 'rango_pk' => [$desde, $hasta], 'resumen' => $cont,
     'candidatas' => $candidatas, 'enriquecidas' => $enriquecidas, 'errores' => $errores],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

log_linea('Fin recorrido · ' . hms(microtime(true) - $t0));
echo PHP_EOL, 'RESUMEN', PHP_EOL, str_repeat('-', 60), PHP_EOL;
foreach ($cont as $k => $v) {
    printf("%-32s %d%s", $k, $v, PHP_EOL);
}
printf("Informe                         %s%s", $opt['informe'], PHP_EOL);

$conConflictos = array_filter($enriquecidas, static fn(array $e): bool => $e['conflictos'] !== []);
if ($conConflictos !== []) {
    echo PHP_EOL, count($conConflictos), ' de las enriquecidas tienen algún campo con valor distinto (no se pisa solo, revisar a mano):', PHP_EOL;
    foreach ($conConflictos as $e) {
        printf("  ID_CAND %d (pk origen %d): %s%s", $e['ID_CAND'], $e['origen_pk'], json_encode($e['conflictos'], JSON_UNESCAPED_UNICODE), PHP_EOL);
    }
}

if (!$aplicar) {
    echo PHP_EOL, count($candidatas), ' candidatas nuevas y ', count($enriquecidas),
        ' existentes para enriquecer, NO aplicadas (falta --aplicar). Revisa el informe y repite con --aplicar cuando estés conforme.', PHP_EOL;
    exit(0);
}

if ($candidatas === [] && $enriquecidas === []) {
    echo PHP_EOL, 'Nada que aplicar.', PHP_EOL;
    exit(0);
}

$pdo->beginTransaction();
try {
    $idRun = null;
    $insertadas = 0;
    if ($candidatas !== []) {
        $pdo->exec("INSERT INTO ingest_run (STARTED_AT, FINISHED_AT, FUENTE, N_CANDIDATOS, NOTAS) VALUES (
            datetime('now'), datetime('now'), 'dmp',
            " . count($candidatas) . ",
            'Barrido por marcha (/marchas/' || " . $pdo->quote((string) $desde) . " || '..' || " . $pdo->quote((string) $hasta) . " || ')')");
        $idRun = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO ingest_candidato
             (ID_RUN, VIDEO_ID, VIDEO_URL, VIDEO_TITULO, VIDEO_DESC, DURACION_SEG, CLASIFICACION, CONFIANZA, FLAGS,
              P_TITULO, P_FECHA, P_DEDICATORIA, P_LOCALIDAD, P_PROVINCIA, P_AUTORES, P_ESTILO, ESTADO, FUENTE, FUENTE_ALBUM, FUENTE_ALBUM_URL)
             VALUES (:ID_RUN, :VIDEO_ID, :VIDEO_URL, :VIDEO_TITULO, :VIDEO_DESC, :DURACION_SEG, :CLASIFICACION, :CONFIANZA, :FLAGS,
              :P_TITULO, :P_FECHA, :P_DEDICATORIA, :P_LOCALIDAD, :P_PROVINCIA, :P_AUTORES, :P_ESTILO, \'pendiente\', :FUENTE, :FUENTE_ALBUM, :FUENTE_ALBUM_URL)'
        );
        foreach ($candidatas as $c) {
            $ins->execute([
                ':ID_RUN' => $idRun, ':VIDEO_ID' => $c['VIDEO_ID'], ':VIDEO_URL' => $c['VIDEO_URL'],
                ':VIDEO_TITULO' => $c['VIDEO_TITULO'], ':VIDEO_DESC' => $c['VIDEO_DESC'], ':DURACION_SEG' => $c['DURACION_SEG'],
                ':CLASIFICACION' => $c['CLASIFICACION'], ':CONFIANZA' => $c['CONFIANZA'], ':FLAGS' => $c['FLAGS'],
                ':P_TITULO' => $c['P_TITULO'], ':P_FECHA' => $c['P_FECHA'], ':P_DEDICATORIA' => $c['P_DEDICATORIA'],
                ':P_LOCALIDAD' => $c['P_LOCALIDAD'], ':P_PROVINCIA' => $c['P_PROVINCIA'], ':P_AUTORES' => $c['P_AUTORES'],
                ':P_ESTILO' => $c['P_ESTILO'], ':FUENTE' => $c['FUENTE'], ':FUENTE_ALBUM' => $c['FUENTE_ALBUM'],
                ':FUENTE_ALBUM_URL' => $c['FUENTE_ALBUM_URL'],
            ]);
            $insertadas += $ins->rowCount();
        }
    }

    $enriquecidasAplicadas = 0;
    foreach ($enriquecidas as $e) {
        // solo se rellenan huecos (los campos de $cambios ya vienen filtrados así); los conflictos no se tocan
        $sets = [];
        $params = [':id' => $e['ID_CAND']];
        foreach ($e['cambios'] as $campo => $valor) {
            $sets[] = "$campo = :v_$campo";
            $params[":v_$campo"] = $valor;
        }
        if ($sets === []) {
            continue; // solo tenía conflictos, sin campos vacíos que rellenar: nada que aplicar, queda anotado en el informe
        }
        $flagsActuales = json_decode((string) ($e['FLAGS_actual'] ?? '[]'), true);
        if (!is_array($flagsActuales)) {
            $flagsActuales = [];
        }
        $flagsActuales[] = 'Enriquecida desde el barrido por marcha (' . date('Y-m-d') . '), origen: ' . $e['origen_url']
            . ' — campos añadidos: ' . implode(', ', array_keys($e['cambios']));
        if ($e['conflictos'] !== []) {
            $flagsActuales[] = 'Discrepancia sin resolver (revisar a mano): ' . json_encode($e['conflictos'], JSON_UNESCAPED_UNICODE);
        }
        $sets[] = 'FLAGS = :flags';
        $params[':flags'] = json_encode($flagsActuales, JSON_UNESCAPED_UNICODE);

        $upd = $pdo->prepare('UPDATE ingest_candidato SET ' . implode(', ', $sets) . " WHERE ID_CAND = :id AND ESTADO = 'pendiente'");
        $upd->execute($params);
        $enriquecidasAplicadas += $upd->rowCount();
    }

    $pdo->commit();
    if ($idRun !== null) {
        echo PHP_EOL, "Insertadas $insertadas de " . count($candidatas) . " candidatas nuevas en ingest_candidato · ID_RUN=$idRun", PHP_EOL;
        echo "Para deshacer esta tanda: DELETE FROM ingest_candidato WHERE ID_RUN = $idRun;", PHP_EOL;
    }
    echo "Enriquecidas $enriquecidasAplicadas de " . count($enriquecidas) . " candidatas dmp pendientes existentes (campos vacíos rellenados con datos de la ficha).", PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error aplicando, se revierte todo: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
