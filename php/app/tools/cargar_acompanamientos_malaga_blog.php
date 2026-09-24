<?php

declare(strict_types=1);

/*
 * Carga los acompañamientos históricos de UNA hermandad de Málaga a partir de
 * su etiqueta en malagamusical.blogspot.com (ver
 * docs/acompanamientos-nomina-2026.md — "Pendiente" — y 012_hermandad_paso.sql).
 *
 * El blog publica una entrada por hermandad bajo /search/label/<etiqueta> con
 * el cuerpo en <div class='post-body entry-content'>: bloques encabezados por
 * el tipo de paso ("Cruz de Guía", "Cristo", "Virgen"...) seguidos de líneas
 * "AAAA - Banda" o "AAAA/BBBB - Banda". Reglas de negocio completas en el
 * encargo de esta tarea (2026-09-24); resumen:
 *   - Rango AAAA/BBBB se expande año a año, desde 1980 inclusive. 2020/2021
 *     se cargan como cualquier otro año (no hay recorte por pandemia aquí).
 *   - Solo se registran Agrupación Musical (AM) y Banda/Cornetas y Tambores
 *     (CCTT): se descarta por TIPO DE BANDA, no por tipo de paso — una AM/CCTT
 *     en el paso de Virgen SÍ se registra si la hermandad tiene mapeado ese
 *     paso (ver malaga_blog_mapeo.json).
 *   - La Cruz de Guía SÍ se registra (paso ES_CRUZ_GUIA=1, ORDEN=0).
 *   - La correspondencia etiqueta-del-blog → hermandad y cabecera-de-bloque →
 *     paso vive en php/data/malaga_blog_mapeo.json (versionado). Si falta una
 *     hermandad o un tipo de cabecera, el dry-run lo señala y --commit se
 *     niega a escribir nada de esa ejecución.
 *   - Resolución de banda contra `banda`: por nombre (sin el prefijo de tipo:
 *     "Banda de Cornetas y Tambores", "C.T.", "A.M.", "Agrupación Musical"...)
 *     y localidad entre paréntesis — ver resolveBanda(). Alias manuales y
 *     ambigüedades conocidas en php/data/malaga_blog_banda_alias.json. Si no
 *     es inequívoco, NO se enlaza: banda CCTT/AM sin ID_BANDA seguro se guarda
 *     en `acompanamiento_pendiente` (017) como texto literal.
 *   - Idempotente: no duplica contratos, pendientes, ni banderas de conflicto
 *     en dos pasadas. Si para un paso+año la BD ya tiene OTRA banda distinta
 *     de la que propone el blog, no se toca nada — sale como conflicto.
 *
 * FUENTE de los contratos/pendientes nuevos = la URL exacta pasada por
 * argumento (más trazable que el genérico 'malagamusical.blogspot.com' que ya
 * usan otras filas de `contrato`, y necesario para volver a la entrada exacta
 * si hay que revisar una fila).
 *
 * Por defecto dry-run: informa y no toca la BD. Con --commit hace backup
 * (VACUUM INTO en php/data/backups/) y escribe.
 *
 * Uso:
 *   php php/app/tools/cargar_acompanamientos_malaga_blog.php <URL_etiqueta> [--dry-run|--commit]
 *   DB_PATH=/ruta/a/mdc.db php .../cargar_acompanamientos_malaga_blog.php <URL> --commit
 *
 * Ejemplo:
 *   php php/app/tools/cargar_acompanamientos_malaga_blog.php \
 *     'https://malagamusical.blogspot.com/search/label/Dulce%20Nombre'
 */

require __DIR__ . '/_cli.php';

use App\Slug;

[, $db] = cliBootstrap('Carga abortada');
require APP_DIR . '/src/Slug.php';

const LOCALIDAD = 'Malaga';
const ANIO_MINIMO = 1980;

// ─────────────────────────── argumentos ──────────────────────────────────

$args = array_slice($_SERVER['argv'] ?? [], 1);
$commit = in_array('--commit', $args, true);
$url = null;
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) {
        $url = $a;
        break;
    }
}
if ($url === null) {
    fwrite(STDERR, "USO: php php/app/tools/cargar_acompanamientos_malaga_blog.php <URL_etiqueta_del_blog> [--commit]\n");
    exit(1);
}

// ─────────────────────────── descarga y extracción ───────────────────────

function fetchUrl(string $url): string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    if (function_exists('curl_init')) {
        // PHP en Windows no trae curl.cainfo configurado (verificado con
        // `php -i`), así que curl no encuentra el emisor del certificado. En
        // vez de desactivar la verificación (CURLOPT_SSL_VERIFYPEER=false, lo
        // que haría fill_enlaces_odesli.php/diag_spotify.php), se prueba con
        // el bundle de CA de Git for Windows si existe — no aplica en el host
        // de producción (Linux), que ya trae su propio CA store del sistema.
        $caCandidatas = [
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
            'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_TIMEOUT => 30,
        ];
        curl_setopt_array($ch, $opts);
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
            fwrite(STDERR, "Carga abortada: curl falló ($err)\n");
            exit(1);
        }
        if ($code !== 200) {
            fwrite(STDERR, "Carga abortada: HTTP $code al descargar $url\n");
            exit(1);
        }
        return $body;
    }
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\n", 'timeout' => 30]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        fwrite(STDERR, "Carga abortada: no se pudo descargar $url\n");
        exit(1);
    }
    return $body;
}

/** Extrae el texto de <div class='post-body entry-content'>, una línea por elemento de bloque. */
function extraerTexto(string $html): string
{
    if (!preg_match(
        '/<div class=([\'"])post-body entry-content\1[^>]*>(.*?)<div class=([\'"])post-footer\3/s',
        $html,
        $m
    )) {
        fwrite(STDERR, "Carga abortada: no se encuentra <div class='post-body entry-content'> en la página\n");
        exit(1);
    }
    $frag = $m[2];
    $frag = preg_replace('/<br\s*\/?>/i', "\n", $frag) ?? $frag;
    $frag = preg_replace('/<\/(p|div|li)>/i', "\n", $frag) ?? $frag;
    $text = strip_tags($frag);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $text;
}

/** @return list<string> líneas no vacías, recortadas. */
function lineasDe(string $text): array
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
 * Cristo" seguido inmediatamente de la cabecera real "Cruz de Guía") se
 * descarta sola, sin perder datos: solo se abre bloque cuando hay contenido.
 *
 * @param list<string> $lineas
 * @return list<array{cabecera:string, lineas:list<string>, orfanas:list<string>}>
 */
function agruparBloques(array $lineas): array
{
    $yearRe = '/^(\d{4})(?:\s*\/\s*(\d{4}))?\s*-\s*(.+)$/u';
    $bloques = [];
    $cabecera = null;
    $actuales = [];
    $orfanas = [];
    foreach ($lineas as $l) {
        if (preg_match($yearRe, $l)) {
            if ($cabecera === null) {
                $orfanas[] = $l;
            } else {
                $actuales[] = $l;
            }
            continue;
        }
        // línea de cabecera
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

// ─────────────────────────── clasificación de banda ──────────────────────

/**
 * @return array{0:string,1:?string} [core sin prefijo de tipo, localidad|null]
 */
function normalizarBanda(string $raw): array
{
    $t = trim($raw);
    $t = rtrim($t, ". \t");
    $localidad = null;
    if (preg_match('/\(([^)]+)\)\s*$/u', $t, $m)) {
        $localidad = trim($m[1]);
        $pos = strrpos($t, '(');
        $t = $pos !== false ? rtrim(substr($t, 0, $pos)) : $t;
    }
    if ($localidad !== null) {
        $partes = explode(',', $localidad);
        $localidad = trim($partes[0]);
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
function tipoDeNombre(string $nombre): string
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
function clasificarTipo(string $raw): string
{
    $t = mb_strtolower(trim(rtrim($raw, ". \t")), 'UTF-8');
    if ($t === '') {
        return 'omitir';
    }
    if (preg_match('/sin\s+informaci[oó]n|no\s+lleva/u', $t)) {
        return 'omitir';
    }
    if (preg_match('/tambor\s*cola/u', $t)) {
        return 'descartado_explicito';
    }
    if (preg_match('/\bo\.?\s*j\.?\s*e\.?\b/u', $t)) {
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
function construirIndiceBandas(PDO $pdo): array
{
    $idx = [];
    foreach ($pdo->query('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD FROM banda') as $b) {
        foreach ([$b['NOMBRE_COMPLETO'], $b['NOMBRE_BREVE']] as $nombre) {
            [$core] = normalizarBanda((string) $nombre);
            $slug = Slug::slugify($core);
            if ($slug === '') {
                continue;
            }
            $idx[$slug][(int) $b['ID_BANDA']] = [
                'id' => (int) $b['ID_BANDA'],
                'nombre' => (string) $nombre,
                'localidad' => (string) $b['LOCALIDAD'],
                'tipo' => tipoDeNombre((string) $b['NOMBRE_COMPLETO']),
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
 * @param array{aliases:list<array<string,mixed>>,ambiguos:list<array<string,mixed>>} $alias
 * @return array{status:string, id?:int, candidatos?:list<string>, nota?:string}
 */
function resolverBanda(string $raw, string $tipoBlog, array $indice, array $alias): array
{
    [$core, $localidad] = normalizarBanda($raw);
    $coreSlug = Slug::slugify($core);

    foreach ($alias['ambiguos'] as $amb) {
        if (Slug::slugify((string) $amb['core']) === $coreSlug) {
            return ['status' => 'ambiguo_conocido', 'candidatos' => $amb['candidatos'], 'nota' => (string) $amb['nota']];
        }
    }

    foreach ($alias['aliases'] as $al) {
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

    $cands = $indice[$coreSlug] ?? [];
    $cands = array_values(array_filter($cands, static fn(array $c): bool => $c['tipo'] === $tipoBlog));
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

// ─────────────────────────── programa principal ──────────────────────────

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:cargar_acompanamientos_malaga_blog' WHERE ID = 1");

    // etiqueta del blog = último segmento de la URL, urldecodeado
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $etiqueta = urldecode(basename(rtrim($path, '/')));
    if ($etiqueta === '' || !str_contains($path, '/label/')) {
        fwrite(STDERR, "Carga abortada: la URL no parece una etiqueta de malagamusical.blogspot.com ($url)\n");
        exit(1);
    }
    $labelSlug = Slug::slugify($etiqueta);

    $mapeoPath = dirname(__DIR__, 2) . '/data/malaga_blog_mapeo.json';
    $aliasPath = dirname(__DIR__, 2) . '/data/malaga_blog_banda_alias.json';
    $mapeo = json_decode((string) file_get_contents($mapeoPath), true);
    $alias = json_decode((string) file_get_contents($aliasPath), true);
    if (!is_array($mapeo) || !is_array($alias)) {
        fwrite(STDERR, "Carga abortada: no se pudo leer $mapeoPath o $aliasPath\n");
        exit(1);
    }

    if (!isset($mapeo[$labelSlug])) {
        echo "FALTA MAPEO: la etiqueta \"$etiqueta\" (slug \"$labelSlug\") no está en $mapeoPath\n";
        echo "Propuesta a añadir (edítala con el NOMBRE real de la hermandad y de cada paso antes de rejecutar):\n";
        echo json_encode([$labelSlug => [
            'hermandad_slug' => $labelSlug,
            'pasos' => ['cruz de guia' => ['nombre' => 'Cruz de Guía', 'es_cruz_guia' => true], 'cristo' => ['nombre' => '???'], 'virgen' => ['descartar' => true]],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        echo "No se ha tocado la BD.\n";
        exit(1);
    }
    $hermandadCfg = $mapeo[$labelSlug];
    $hermandadSlug = (string) $hermandadCfg['hermandad_slug'];

    $html = fetchUrl($url);
    $texto = extraerTexto($html);
    $lineas = lineasDe($texto);
    $bloques = agruparBloques($lineas);

    $indiceBandas = construirIndiceBandas($pdo);

    $sinMapeo = [];      // cabeceras de bloque sin entrada en pasos[]
    $sinCabecera = [];   // líneas de año sin ninguna cabecera antes
    $lineasNoParseadas = [];
    $descartadas = [];   // ['anio'=>, 'motivo'=>, 'texto'=>, 'paso'=>]
    $dudasTipo = [];     // tipo de banda no reconocido
    $dudasBanda = [];    // ambiguo / ambiguo_conocido

    // filas resueltas a insertar como contrato, o como pendiente
    $aContrato = [];    // [idPaso, pasoNombre, anio, idBanda, bandaTexto]
    $aPendiente = [];   // [idPaso, pasoNombre, anio, bandaTexto]
    $pasosACrear = [];  // pasoKey => ['nombre'=>, 'es_cruz_guia'=>bool]

    $yearRe = '/^(\d{4})(?:\s*\/\s*(\d{4}))?\s*-\s*(.+)$/u';

    foreach ($bloques as $bloque) {
        if ($bloque['orfanas'] !== []) {
            $sinCabecera = array_merge($sinCabecera, $bloque['orfanas']);
            continue;
        }
        $cabecera = $bloque['cabecera'];
        $claveCabecera = mb_strtolower(trim($cabecera), 'UTF-8');
        // normaliza tildes para casar "Cruz de Guía" con la clave "cruz de guia" del JSON
        $claveCabeceraSlug = str_replace('-', ' ', Slug::slugify($claveCabecera));
        $pasoCfg = null;
        foreach ($hermandadCfg['pasos'] as $clave => $cfg) {
            if (str_replace('-', ' ', Slug::slugify($clave)) === $claveCabeceraSlug) {
                $pasoCfg = $cfg;
                $pasoClave = $clave;
                break;
            }
        }
        if ($pasoCfg === null) {
            $sinMapeo[] = $cabecera;
            continue;
        }
        if (!empty($pasoCfg['descartar'])) {
            // Igual se registran CCTT/AM aquí si hay nombre; si no lo hay, no
            // se puede — se avisa por cada línea con banda CCTT/AM real.
        }

        foreach ($bloque['lineas'] as $linea) {
            if (!preg_match($yearRe, $linea, $m)) {
                $lineasNoParseadas[] = $linea;
                continue;
            }
            $anioIni = (int) $m[1];
            $anioFin = $m[2] !== '' ? (int) $m[2] : $anioIni;
            $bandaTexto = trim($m[3]);
            $tipo = clasificarTipo($bandaTexto);

            if ($tipo === 'omitir') {
                continue; // "Sin información" / "No lleva": nada, ni siquiera aviso
            }
            if ($tipo === 'descartado_explicito' || $tipo === 'descartado_tipo') {
                $descartadas[] = ['anio' => "$anioIni" . ($anioFin !== $anioIni ? "/$anioFin" : ''), 'paso' => $cabecera, 'texto' => $bandaTexto, 'motivo' => $tipo];
                continue;
            }
            if ($tipo === 'desconocido') {
                $dudasTipo[] = "$cabecera $anioIni" . ($anioFin !== $anioIni ? "/$anioFin" : '') . ": \"$bandaTexto\" (tipo de banda no reconocido)";
                continue;
            }

            if (!empty($pasoCfg['descartar'])) {
                $dudasTipo[] = "$cabecera $anioIni" . ($anioFin !== $anioIni ? "/$anioFin" : '') . ": \"$bandaTexto\" es $tipo pero el paso \"$cabecera\" está marcado como descartado sin nombre en malaga_blog_mapeo.json — añade el NOMBRE real del paso para poder registrarlo";
                continue;
            }

            $pasoNombre = (string) $pasoCfg['nombre'];
            $esCruzGuia = !empty($pasoCfg['es_cruz_guia']);
            $pasosACrear[$pasoNombre] = ['nombre' => $pasoNombre, 'es_cruz_guia' => $esCruzGuia];

            $res = resolverBanda($bandaTexto, $tipo, $indiceBandas, $alias);
            $idBanda = null;
            if ($res['status'] === 'ok') {
                $idBanda = $res['id'];
            } elseif ($res['status'] === 'ambiguo' || $res['status'] === 'ambiguo_conocido') {
                $dudasBanda[] = "\"$bandaTexto\" ($anioIni" . ($anioFin !== $anioIni ? "/$anioFin" : '') . ", $pasoNombre): "
                    . ($res['nota'] ?? 'candidatos ambiguos') . ' [' . implode(' | ', array_map('strval', $res['candidatos'])) . ']';
            }
            // 'sin_match' no genera duda: es el caso normal de "no existe en la BD".

            for ($anio = $anioIni; $anio <= $anioFin; $anio++) {
                if ($anio < ANIO_MINIMO) {
                    continue;
                }
                if ($idBanda !== null) {
                    $aContrato[] = ['paso' => $pasoNombre, 'anio' => $anio, 'idBanda' => $idBanda, 'bandaTexto' => $bandaTexto];
                } else {
                    $aPendiente[] = ['paso' => $pasoNombre, 'anio' => $anio, 'bandaTexto' => $bandaTexto];
                }
            }
        }
    }

    if ($sinMapeo !== []) {
        echo "FALTA MAPEO de paso para \"$etiqueta\": cabecera(s) sin entrada en pasos[] de malaga_blog_mapeo.json:\n";
        foreach (array_unique($sinMapeo) as $c) {
            echo "  - \"$c\"\n";
        }
        echo "No se ha escrito nada de esta ejecución.\n";
        exit(1);
    }

    // ── hermandad / paso ───────────────────────────────────────────────
    $selHerm = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $selHerm->execute([LOCALIDAD, $hermandadSlug]);
    $idHermandad = $selHerm->fetchColumn();
    $selHerm->closeCursor();
    $hermandadNueva = false;
    if ($idHermandad === false) {
        $hermandadNueva = true;
        $idHermandad = null; // se crea en --commit
    } else {
        $idHermandad = (int) $idHermandad;
    }

    $pasosExistentes = [];
    if ($idHermandad !== null) {
        foreach ($pdo->query('SELECT ID_PASO, NOMBRE FROM paso WHERE ID_HERMANDAD = ' . (int) $idHermandad) as $p) {
            $pasosExistentes[$p['NOMBRE']] = (int) $p['ID_PASO'];
        }
    }
    $pasosNuevos = [];
    foreach ($pasosACrear as $nombre => $cfg) {
        if (!isset($pasosExistentes[$nombre])) {
            $pasosNuevos[] = $cfg;
        }
    }

    // ── conflictos / idempotencia contra `contrato` y `acompanamiento_pendiente` ──
    //
    // No basta con mirar contrato_paso: hay contratos de Málaga anteriores a
    // esta carga (musicofrades 2026, 101tv.es) con TITULAR='Cruz de Guia'
    // (sin tilde) que nunca se enlazaron a un paso porque `paso` "Cruz de
    // Guía" no existía todavía — comprobado con Pollinica 2022-2026. Se
    // buscan también por HERMANDAD_SLUG+ANIO y se compara el TITULAR por
    // slug (insensible a tildes) para no duplicarlos ni tratarlos como
    // conflicto por un simple acento distinto.
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

    /**
     * Filas de `contrato` de esta hermandad/año que ya pertenecen a este
     * paso: enlazadas por contrato_paso, o (contratos anteriores a que
     * existiera el paso) por TITULAR igual al nombre del paso salvo tildes.
     * @return list<array{ID_CONTRATO:int,ID_BANDA:int}>
     */
    $filasDelPaso = static function (int $idPaso, string $pasoNombre, int $anio) use ($existePasoAnio, $hermandadSlug): array {
        $existePasoAnio->execute([$hermandadSlug, $anio]);
        $filas = $existePasoAnio->fetchAll();
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

    if ($idHermandad !== null && $pasosNuevos === []) {
        foreach ($aContrato as $f) {
            $idPaso = $pasosExistentes[$f['paso']];
            $filas = $filasDelPaso($idPaso, $f['paso'], $f['anio']);
            $bandas = array_column($filas, 'ID_BANDA');
            $otras = array_values(array_unique(array_diff($bandas, [$f['idBanda']])));
            if ($otras !== []) {
                // Aviso informativo aunque la banda del blog ya esté cargada:
                // hay OTRA banda distinta para el mismo paso+año en la BD
                // (caso real: Pollinica 2024, #3714 Clemencia y #4296 Vera
                // Cruz) — no se toca nada de ninguna de las dos.
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
                continue; // conflicto real: ni la banda del blog está cargada, no se inserta
            }
            $nuevosContratos[] = $f + ['idPaso' => $idPaso];
        }
        foreach ($aPendiente as $f) {
            $idPaso = $pasosExistentes[$f['paso']];
            $filas = $filasDelPaso($idPaso, $f['paso'], $f['anio']);
            if ($filas !== []) {
                $resueltoPorOtraFuente[] = "{$f['paso']} {$f['anio']}: ya hay contrato en la BD, no se guarda pendiente para \"{$f['bandaTexto']}\"";
                continue;
            }
            $existePendiente->execute([LOCALIDAD, $hermandadSlug, $idPaso, $f['anio'], $f['bandaTexto']]);
            $yaEnPendiente = $existePendiente->fetchColumn();
            $existePendiente->closeCursor();
            if ($yaEnPendiente !== false) {
                $yaPendientes++;
                continue;
            }
            $nuevasPendientes[] = $f + ['idPaso' => $idPaso];
        }
    }

    // ── informe ──────────────────────────────────────────────────────
    echo "=== $etiqueta ($url) ===\n";
    if ($hermandadNueva) {
        echo "Hermandad NUEVA a crear: LOCALIDAD=Malaga SLUG=$hermandadSlug (DIA='Sin asignar', provisional hasta que el usuario la ordene)\n";
    }
    if ($pasosNuevos !== []) {
        echo 'Pasos nuevos a crear: ' . implode(', ', array_map(static fn($p) => $p['nombre'] . ($p['es_cruz_guia'] ? ' [cruz de guía]' : ''), $pasosNuevos)) . "\n";
    }
    if ($hermandadNueva || $pasosNuevos !== []) {
        echo "(hermandad/pasos nuevos: no se calculan conflictos/pendientes contra una BD que aún no los tiene — vuelve a ejecutar tras crearlos con --commit)\n";
    }
    echo 'Contratos nuevos a insertar: ' . count($nuevosContratos) . "\n";
    foreach ($nuevosContratos as $f) {
        echo "  - {$f['paso']} {$f['anio']}: ID_BANDA={$f['idBanda']} (\"{$f['bandaTexto']}\")\n";
    }
    echo "Ya cargados (re-ejecución, idénticos): $yaCargados\n";
    echo 'Pendientes nuevas (banda sin enlazar): ' . count($nuevasPendientes) . "\n";
    foreach ($nuevasPendientes as $f) {
        echo "  - {$f['paso']} {$f['anio']}: \"{$f['bandaTexto']}\"\n";
    }
    echo "Ya en pendientes (re-ejecución): $yaPendientes\n";
    if ($resueltoPorOtraFuente !== []) {
        echo 'Ya resuelto por otra fuente (no se guarda pendiente) (' . count($resueltoPorOtraFuente) . "):\n";
        foreach ($resueltoPorOtraFuente as $s) {
            echo "  - $s\n";
        }
    }
    if ($conflictos !== []) {
        echo 'CONFLICTOS (no se toca nada) (' . count($conflictos) . "):\n";
        foreach ($conflictos as $c) {
            echo "  - $c\n";
        }
    }
    if ($descartadas !== []) {
        echo 'Descartadas por tipo de banda (' . count($descartadas) . "):\n";
        foreach ($descartadas as $d) {
            echo "  - {$d['paso']} {$d['anio']}: \"{$d['texto']}\" ({$d['motivo']})\n";
        }
    }
    if ($dudasTipo !== []) {
        echo 'Dudas — tipo de banda / paso no reconocido (' . count($dudasTipo) . "):\n";
        foreach ($dudasTipo as $d) {
            echo "  - $d\n";
        }
    }
    if ($dudasBanda !== []) {
        echo 'Dudas — banda ambigua, no enlazada (' . count($dudasBanda) . "):\n";
        foreach ($dudasBanda as $d) {
            echo "  - $d\n";
        }
    }
    if ($lineasNoParseadas !== [] || $sinCabecera !== []) {
        echo 'Líneas no reconocidas (' . (count($lineasNoParseadas) + count($sinCabecera)) . "):\n";
        foreach (array_merge($lineasNoParseadas, $sinCabecera) as $l) {
            echo "  - \"$l\"\n";
        }
    }

    if (!$commit) {
        echo "\n--dry-run (por defecto): no se ha tocado la BD. Añade --commit para escribir.\n";
        exit(0);
    }

    if ($sinMapeo !== []) {
        // ya se salió arriba, inalcanzable — por claridad de lectura
        exit(1);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Carga abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-cargar-acompanamientos-malaga-blog.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo "backup: $dest\n";

    $pdo->beginTransaction();

    if ($hermandadNueva) {
        $maxOrden = (int) $pdo->query(
            "SELECT COALESCE(MAX(DIA_ORDEN), 0) FROM hermandad WHERE LOCALIDAD = '" . LOCALIDAD . "'"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA, FUENTE)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        )->execute([LOCALIDAD, $hermandadCfg['hermandad_slug'], $hermandadSlug, 'Sin asignar', $maxOrden + 1, 99, $url]);
        $idHermandad = (int) $pdo->lastInsertId();
        echo "hermandad creada: ID_HERMANDAD=$idHermandad ($hermandadSlug)\n";
    }

    if ($pasosNuevos !== []) {
        $maxOrdenPaso = (int) $pdo->query('SELECT COALESCE(MAX(ORDEN), 0) FROM paso WHERE ID_HERMANDAD = ' . (int) $idHermandad)->fetchColumn();
        $insPaso = $pdo->prepare('INSERT INTO paso (ID_HERMANDAD, NOMBRE, ORDEN, ES_CRUZ_GUIA) VALUES (?, ?, ?, ?)');
        foreach ($pasosNuevos as $p) {
            $orden = $p['es_cruz_guia'] ? 0 : ++$maxOrdenPaso;
            $insPaso->execute([$idHermandad, $p['nombre'], $orden, $p['es_cruz_guia'] ? 1 : 0]);
            $pasosExistentes[$p['nombre']] = (int) $pdo->lastInsertId();
            echo "paso creado: {$p['nombre']} (ID_PASO={$pasosExistentes[$p['nombre']]})\n";
        }
        $pdo->commit();
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        echo "Hermandad/pasos nuevos creados. Vuelve a ejecutar el script (dry-run o --commit) para cargar los contratos/pendientes ahora que ya existen.\n";
        exit(0);
    }

    $insC = $pdo->prepare(
        'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insL = $pdo->prepare('INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, ?)');
    $insP = $pdo->prepare('INSERT INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)');
    $selHermNombre = $pdo->prepare('SELECT NOMBRE FROM hermandad WHERE ID_HERMANDAD = ?');
    $selHermNombre->execute([$idHermandad]);
    $hermandadNombre = (string) $selHermNombre->fetchColumn();
    $selHermNombre->closeCursor();

    foreach ($nuevosContratos as $f) {
        $insC->execute([$f['idBanda'], $hermandadNombre, $hermandadSlug, $f['paso'], $f['anio'], $url]);
        $idContrato = (int) $pdo->lastInsertId();
        $insL->execute([$idContrato, LOCALIDAD]);
        $insP->execute([$idContrato, $f['idPaso']]);
    }

    $insPend = $pdo->prepare(
        'INSERT INTO acompanamiento_pendiente (LOCALIDAD, HERMANDAD_SLUG, ID_PASO, TITULAR, ANIO, BANDA_TEXTO, FUENTE)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($nuevasPendientes as $f) {
        $insPend->execute([LOCALIDAD, $hermandadSlug, $f['idPaso'], $f['paso'], $f['anio'], $f['bandaTexto'], $url]);
    }

    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo count($nuevosContratos) . ' contratos y ' . count($nuevasPendientes) . " pendientes insertados\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Carga falló: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
