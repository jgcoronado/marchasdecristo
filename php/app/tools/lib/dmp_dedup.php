<?php
declare(strict_types=1);

/**
 * Cruce estructural origen (discografiasdemarchasprocesionales.com) → BD por
 * posición de pista: disco del origen → disco de la BD → NUMEROMARCHA → marcha.
 *
 * Por qué por posición: los títulos del origen vienen abreviados, en mayúsculas
 * o con sufijos ("HIMNO A NTRO.PADRE JESÚS NAZARENO DE ARAHAL") y los nombres de
 * disco difieren ("San Bernardo" vs "Salud de San Bernardo"); la posición de la
 * pista dentro de un disco identificado por su lista de pistas es exacta.
 *
 * Todo va en una clase con prefijo propio para poder requerirla desde
 * proponer_discografias*.php sin chocar con sus funciones globales.
 */
final class DmpDedup
{
    /** título de pista parecido para contar solapamiento de disco */
    public const UMBRAL_PISTA = 0.7;
    /** título que confirma una pista ya emparejada por posición */
    public const UMBRAL_CONFIRMA = 0.6;
    /** título que, sin posición, basta dentro del mismo disco */
    public const UMBRAL_MISMO_DISCO = 0.85;
    /** con el mismo autor como única confirmación, el título aún debe parecerse algo */
    public const UMBRAL_CON_AUTOR = 0.45;

    private const ABREV = ['ntra' => 'nuestra', 'ntro' => 'nuestro', 'nstra' => 'nuestra', 'sra' => 'senora',
        'sr' => 'senor', 'stma' => 'santisima', 'smo' => 'santisimo', 'stmo' => 'santisimo', 'sto' => 'santo',
        'sta' => 'santa', 'ma' => 'maria', 'jhs' => 'jesus'];
    private const STOP_TITULO = ['a', 'al', 'de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'marcha',
        'procesional', 'nuestro', 'nuestra', 'padre', 'senor', 'senora'];
    private const STOP_BANDA = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'banda', 'cornetas',
        'tambores', 'agrupacion', 'musical', 'cctt', 'bct', 'am', 'ct', 'b', 'c', 't', 'a', 'm', 'juvenil'];

    /** @var array<int,array{id:int,tok:list<string>,loc:string,estilo:?string}> */
    private array $bandas = [];
    /** @var array<int,list<array{id:int,nombre:string,anio:?string}>> */
    private array $discosPorBanda = [];
    /** @var array<int,list<array{n:int,seq:int,cd:?int,id_marcha:int,titulo:string,autores:list<int>}>> */
    private array $pistas = [];
    /** @var list<int> autores genéricos (Popular, Anónimo…): no sirven para confirmar una pista */
    private array $autoresGenericos = [];
    /** @var list<int> autores "Desconocido"/"Varios": ni confirman ni chocan con otra atribución */
    private array $autoresSinInfo = [];
    /** @var array<int,array{ap:list<string>,no:list<string>}> tokens de apellidos y nombre por autor */
    private array $autorTok = [];
    /** @var array<string,list<int>> clave norm(apellidos nombre) → ids */
    private array $autorPorClave = [];
    /** @var array<int,array<string,mixed>> caché de discos del origen parseados */
    private array $orgDiscos = [];
    /** @var array<int,array<string,mixed>> /json/bandas del origen */
    private array $orgBandas;
    /** @var array<int,array<string,mixed>> /json/discos del origen */
    private array $orgDiscosJson;
    /** @var array<int,list<array{disco:int,n:int}>>|null id_marcha origen → apariciones */
    private ?array $indiceMarcha = null;
    /** @var array<int,array<string,mixed>|null> memo disco origen → disco BD */
    private array $memoDisco = [];

    public function __construct(PDO $pdo, private string $cacheDiscos, string $cacheJson)
    {
        foreach ($pdo->query('SELECT ID_BANDA, NOMBRE_COMPLETO, NOMBRE_BREVE, LOCALIDAD FROM banda')
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tok = self::tokensBanda(($r['NOMBRE_COMPLETO'] ?? '') . ' ' . $r['NOMBRE_BREVE']);
            $sinLoc = array_values(array_diff($tok, self::tokensBanda((string) $r['LOCALIDAD'])));
            $this->bandas[(int) $r['ID_BANDA']] = [
                'id' => (int) $r['ID_BANDA'], 'tok' => $sinLoc !== [] ? $sinLoc : $tok,
                'loc' => self::normLoc($r['LOCALIDAD']), 'estilo' => self::estiloBd((string) $r['NOMBRE_BREVE']),
                'juvenil' => str_contains(self::norm(($r['NOMBRE_COMPLETO'] ?? '') . ' ' . $r['NOMBRE_BREVE']), 'juvenil'),
            ];
        }
        foreach ($pdo->query('SELECT ID_DISCO, NOMBRE_CD, FECHA_CD, BANDADISCO FROM disco')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $anio = preg_match('/^(\d{4})/', (string) $r['FECHA_CD'], $m) ? $m[1] : null;
            $this->discosPorBanda[(int) $r['BANDADISCO']][] = ['id' => (int) $r['ID_DISCO'], 'nombre' => (string) $r['NOMBRE_CD'], 'anio' => $anio];
        }
        $rows = $pdo->query("SELECT dm.ID_DISCO, dm.N_DISCO, dm.NUMEROMARCHA, m.ID_MARCHA, m.TITULO,
                                    (SELECT GROUP_CONCAT(ma.ID_AUTOR) FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA) AS AUT
                             FROM disco_marcha dm JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
                             ORDER BY dm.ID_DISCO, COALESCE(dm.N_DISCO, 1), dm.NUMEROMARCHA")->fetchAll(PDO::FETCH_ASSOC);
        $seq = [];
        foreach ($rows as $r) {
            $d = (int) $r['ID_DISCO'];
            $seq[$d] = ($seq[$d] ?? 0) + 1;
            $this->pistas[$d][] = [
                'n' => (int) $r['NUMEROMARCHA'], 'seq' => $seq[$d], 'cd' => $r['N_DISCO'] !== null ? (int) $r['N_DISCO'] : null,
                'id_marcha' => (int) $r['ID_MARCHA'], 'titulo' => (string) $r['TITULO'],
                'autores' => $r['AUT'] ? array_map('intval', explode(',', (string) $r['AUT'])) : [],
            ];
        }
        foreach ($pdo->query('SELECT ID_AUTOR, APELLIDOS, NOMBRE, NOMBRE_ART FROM autor')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->autorPorClave[self::norm(($r['APELLIDOS'] ?? '') . ' ' . ($r['NOMBRE'] ?? ''))][] = (int) $r['ID_AUTOR'];
            $this->autorTok[(int) $r['ID_AUTOR']] = ['ap' => self::tokensNombre($r['APELLIDOS']), 'no' => self::tokensNombre($r['NOMBRE'])];
            $k1 = ($r['NOMBRE'] ?? '') . ' ' . ($r['APELLIDOS'] ?? '');
            $k2 = ($r['APELLIDOS'] ?? '') . ' ' . ($r['NOMBRE'] ?? '');
            if (self::esAutorGenerico($k1) || self::esAutorGenerico($k2)) {
                $this->autoresGenericos[] = (int) $r['ID_AUTOR'];
            }
            if (self::esAutorSinInfo($k1) || self::esAutorSinInfo($k2)) {
                $this->autoresSinInfo[] = (int) $r['ID_AUTOR'];
            }
            if (($r['NOMBRE_ART'] ?? '') !== '') {
                $this->autorPorClave[self::norm($r['NOMBRE_ART'])][] = (int) $r['ID_AUTOR'];
            }
        }
        $this->orgBandas = self::leerJson("$cacheJson/bandas.json");
        $this->orgDiscosJson = self::leerJson("$cacheJson/discos.json");
    }

    // ------------------------------------------------------------ normalización

    public static function norm(?string $s): string
    {
        if ($s === null) {
            return '';
        }
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        $s = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', is_string($n) ? $n : $s), 'UTF-8');
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        $t = array_map(static fn(string $w): string => self::ABREV[$w] ?? $w, preg_split('/\s+/', trim($s)) ?: []);
        return implode(' ', array_filter($t, static fn(string $w): bool => $w !== ''));
    }

    public static function ratio(string $a, string $b): float
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

    /** @return list<string> */
    private static function tokensBanda(string $s): array
    {
        return array_values(array_unique(array_diff(explode(' ', self::norm($s)), self::STOP_BANDA, [''])));
    }

    private static function normLoc(?string $s): string
    {
        $n = ' ' . self::norm($s) . ' ';
        return str_replace(' ', '', str_replace([' fra ', ' ftra '], ' frontera ', $n));
    }

    private static function locIgual(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || (min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a)))
            || self::ratio($a, $b) >= 0.85;
    }

    private static function estiloBd(string $breve): ?string
    {
        return preg_match('/^(AM|BCT|CCTT|BM)\b/u', trim($breve), $m)
            ? ['AM' => 'AM', 'BCT' => 'CT', 'CCTT' => 'CT', 'BM' => 'BM'][$m[1]] : null;
    }

    /**
     * Variantes comparables de un título: sin artículo inicial, sin paréntesis
     * final ("PADRE NUESTRO (Pascual González)") y sin "de <localidad>" final.
     * @return list<string>
     */
    private static function variantes(string $t, ?string $loc = null): array
    {
        $base = (string) preg_replace('/^(el|la|los|las) /', '', self::norm($t));
        $v = [$base];
        $sinPar = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $t));
        if ($sinPar !== '' && $sinPar !== trim($t)) {
            $v[] = (string) preg_replace('/^(el|la|los|las) /', '', self::norm($sinPar));
        }
        $l = self::norm($loc);
        if ($l !== '') {
            foreach ($v as $x) {
                if (str_ends_with($x, " de $l") || str_ends_with($x, " en $l")) {
                    $v[] = substr($x, 0, -strlen(" de $l"));
                }
            }
        }
        return array_values(array_unique(array_filter($v, static fn(string $x): bool => $x !== '')));
    }

    /** @return list<string> */
    private static function tokensTitulo(string $n): array
    {
        return array_values(array_unique(array_diff(explode(' ', $n), self::STOP_TITULO, [''])));
    }

    /**
     * Similitud de títulos: mejor ratio entre variantes y si los tokens
     * significativos de uno están contenidos en el otro.
     * @return array{ratio:float,contenido:bool}
     */
    public static function simTitulo(string $a, string $b, ?string $loc = null): array
    {
        $best = 0.0;
        $cont = false;
        foreach (self::variantes($a, $loc) as $x) {
            foreach (self::variantes($b, $loc) as $y) {
                $best = max($best, self::ratio($x, $y));
                $tx = self::tokensTitulo($x);
                $ty = self::tokensTitulo($y);
                if ($tx !== [] && $ty !== []
                    && (array_diff($tx, $ty) === [] || array_diff($ty, $tx) === [])) {
                    $cont = true;
                }
            }
        }
        return ['ratio' => $best, 'contenido' => $cont];
    }

    private static function pistaParecida(string $a, string $b): bool
    {
        $s = self::simTitulo($a, $b);
        return $s['ratio'] >= self::UMBRAL_PISTA || ($s['contenido'] && $s['ratio'] >= 0.3);
    }

    /** @return array<int,array<string,mixed>> */
    private static function leerJson(string $f): array
    {
        $raw = is_file($f) ? (string) file_get_contents($f) : '';
        $j = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw), true);
        $out = [];
        foreach (is_array($j) ? $j : [] as $row) {
            $out[(int) $row['pk']] = $row['fields'];
        }
        return $out;
    }

    // ------------------------------------------------------------ origen

    /** "Apellidos, Nombre" → ids de autor en BD (vacío si no se resuelve de forma exacta) @return list<int> */
    public function autoresBd(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(' / ', $raw) as $a) {
            $p = explode(',', trim($a), 2);
            $k = self::norm(count($p) === 2 ? trim($p[0]) . ' ' . trim($p[1]) : $a);
            foreach (array_unique($this->autorPorClave[$k] ?? []) as $id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** Sin dato de autor: vacío, el literal "None" del origen, S/I, Desconocido, Varios… */
    public static function esAutorSinInfo(?string $s): bool
    {
        return in_array(self::norm($s), ['', 'none', 's i', 'varios', 'varios autores', 'autores varios',
            'desconocido', 'autor desconocido', 'desconocido autor'], true);
    }

    /**
     * Autor que no identifica a nadie: sin dato, o Popular/Anónimo/Tradicional. Estos últimos sí son una
     * atribución (chocan con un compositor concreto), pero no sirven para confirmar que dos pistas son la misma.
     */
    public static function esAutorGenerico(?string $s): bool
    {
        return self::esAutorSinInfo($s)
            || in_array(self::norm($s), ['popular', 'anonimo', 'anonima', 'tradicional', 'popular anonimo'], true);
    }

    /** @return list<string> */
    private static function tokensNombre(?string $s): array
    {
        return array_values(array_diff(explode(' ', self::norm($s)), ['', 'de', 'del', 'la', 'las', 'los', 'y', 'i']));
    }

    /**
     * El autor del origen ("Apellidos, Nombre") es compatible con alguno de los
     * autores de BD aunque no case exacto: primer apellido de la BD presente en
     * el origen y algún token de nombre en común ("Sebastian Bach, Juan" ~ Bach,
     * Johann Sebastian; "Jurado Marín, Manuel Jesús" ~ Jesús Jurado, Manuel).
     * @param list<int> $idsBd
     */
    private function autorCompatible(?string $origen, array $idsBd): bool
    {
        if ($origen === null || trim($origen) === '') {
            return false;
        }
        $tok = self::tokensNombre(str_replace(',', ' ', $origen));
        foreach ($idsBd as $id) {
            $a = $this->autorTok[$id] ?? null;
            if ($a === null || $a['ap'] === [] || in_array($id, $this->autoresGenericos, true)) {
                continue;
            }
            // primer apellido y algún nombre, admitiendo erratas ("Gámez"~"Gómez", "Masson"~"Mason", "Berlamino"~"Belarmino")
            $parecido = static function (string $t, array $lista): bool {
                foreach ($lista as $u) {
                    if ($t === $u || (min(strlen($t), strlen($u)) >= 4 && self::ratio($t, $u) >= 0.75)) {
                        return true;
                    }
                }
                return false;
            };
            if ($parecido($a['ap'][0], $tok)) {
                foreach ($a['no'] as $n) {
                    if ($parecido($n, $tok)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Disco del origen parseado desde la caché (null si no está o no se entiende) @return array<string,mixed>|null */
    public function discoOrigen(int $pk): ?array
    {
        if (array_key_exists($pk, $this->orgDiscos)) {
            return $this->orgDiscos[$pk];
        }
        $f = "{$this->cacheDiscos}/$pk.html";
        $html = is_file($f) ? (string) file_get_contents($f) : '';
        $this->orgDiscos[$pk] = $html !== '' ? $this->parseDisco($pk, $html) : null;
        return $this->orgDiscos[$pk];
    }

    /** @return array<string,mixed>|null */
    private function parseDisco(int $pk, string $html): ?array
    {
        if (!preg_match('#/bandas/(\d+)/?"#u', $html, $b)) {
            return null;
        }
        $d = new DOMDocument();
        libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($d);
        $pistas = [];
        foreach ($xp->query('//table') ?: [] as $tabla) {
            if (!$tabla instanceof DOMElement || stripos($tabla->textContent, 'Pista') === false) {
                continue;
            }
            foreach ($xp->query('.//tr[td]', $tabla) ?: [] as $tr) {
                $td = $xp->query('./td', $tr);
                if ($td === false || $td->length < 3) {
                    continue;
                }
                // el enlace a la marcha va en el onclick de la fila (window.location='/marchas/N/'), no en un <a>
                $a = $xp->query('.//a[contains(@href,"/marchas/")]', $td->item(1));
                $href = ($a !== false && $a->length > 0 && $a->item(0) instanceof DOMElement) ? $a->item(0)->getAttribute('href') : '';
                if ($href === '' && $tr instanceof DOMElement) {
                    $href = $tr->getAttribute('onclick');
                }
                $n = trim($td->item(0)->textContent);
                $autor = trim($td->item(2)->textContent);
                $pistas[] = [
                    'n' => ctype_digit($n) ? (int) $n : null,
                    'titulo' => trim($td->item(1)->textContent),
                    'autor' => self::esAutorGenerico($autor) ? null : $autor,
                    'id_marcha' => preg_match('#/marchas/(\d+)#', $href, $m) ? (int) $m[1] : null,
                ];
            }
            break;
        }
        $fj = $this->orgDiscosJson[$pk] ?? [];
        $anio = isset($fj['ano_grabacion']) && preg_match('/^(1[89]|20)\d\d$/', (string) $fj['ano_grabacion'])
            ? (string) $fj['ano_grabacion'] : null;
        $banda = $this->orgBandas[(int) $b[1]] ?? [];
        return ['pk' => $pk, 'nombre' => (string) ($fj['nombre'] ?? ''), 'anio' => $anio,
                'banda_pk' => (int) $b[1], 'banda_nombre' => (string) ($banda['nombre'] ?? ''),
                'banda_ciudad' => (string) ($banda['ciudad'] ?? ''), 'pistas' => $pistas];
    }

    /** id de marcha del origen → apariciones [disco, n] en los discos cacheados @return list<array{disco:int,n:?int}> */
    public function aparicionesMarcha(int $pkMarcha): array
    {
        if ($this->indiceMarcha === null) {
            $this->indiceMarcha = [];
            foreach (glob("{$this->cacheDiscos}/*.html") ?: [] as $f) {
                $pk = (int) basename($f, '.html');
                foreach ($this->discoOrigen($pk)['pistas'] ?? [] as $p) {
                    if ($p['id_marcha'] !== null) {
                        $this->indiceMarcha[$p['id_marcha']][] = ['disco' => $pk, 'n' => $p['n']];
                    }
                }
            }
        }
        return $this->indiceMarcha[$pkMarcha] ?? [];
    }

    // ------------------------------------------------------------ disco origen → disco BD

    /**
     * Bandas de la BD compatibles con la banda del origen: misma localidad,
     * estilo compatible y al menos la mitad de los tokens en común. Permisivo a
     * propósito: lo que decide es el solapamiento de pistas del disco.
     * @return list<int>
     */
    private function bandasCompatibles(string $nombreOrigen, string $ciudadOrigen): array
    {
        if (!preg_match('/^\((CT|AM|BM|[A-Z]{1,4})\)\s*(.+)$/u', trim($nombreOrigen), $m)) {
            return [];
        }
        $prefijo = $m[1];
        $resto = trim($m[2]);
        $loc = preg_match('/^(.*\S)\s*\(([^)]+)\)$/u', $resto, $mm) ? $mm[2] : null;
        $nucleo = $loc !== null ? $mm[1] : $resto;
        $ciudad = trim((string) preg_replace('/\(.*$/u', '', $ciudadOrigen));
        $locN = self::normLoc($loc ?? $ciudad);
        $tok = array_values(array_diff(self::tokensBanda($nucleo), self::tokensBanda((string) $loc)));
        $juv = str_contains(self::norm($nucleo), 'juvenil');
        if ($tok === [] || $locN === '') {
            return [];
        }
        $out = [];
        foreach ($this->bandas as $b) {
            if (!self::locIgual($locN, $b['loc']) || $b['juvenil'] !== $juv) {
                continue;
            }
            if ($b['estilo'] !== null && $b['estilo'] !== $prefijo) {
                continue;
            }
            $inter = count(array_intersect($tok, $b['tok']));
            if ($inter > 0 && max($inter / count($tok), $inter / max(count($b['tok']), 1)) >= 0.5) {
                $out[] = $b['id'];
            }
        }
        return $out;
    }

    /**
     * Disco de la BD que corresponde a un disco del origen, por solapamiento
     * de pistas en la misma posición. Exige ≥2 posiciones coincidentes y ≥50 %
     * de las comparables; con año igual basta ≥40 %. Empate → null.
     * 'fiable' indica que además es el mismo disco (año a ±1 o nombre parecido, solape ≥0.8),
     * no una antología/reedición: solo entonces sirve para decir "el disco ya está en BD".
     * @return array{id_disco:int,id_banda:int,solape:float,coinciden:int,modo:string,fiable:bool}|null
     */
    public function discoBd(int $pkOrigen): ?array
    {
        if (array_key_exists($pkOrigen, $this->memoDisco)) {
            return $this->memoDisco[$pkOrigen];
        }
        $o = $this->discoOrigen($pkOrigen);
        $res = null;
        if ($o !== null && $o['pistas'] !== []) {
            $cands = [];
            foreach ($this->bandasCompatibles($o['banda_nombre'], $o['banda_ciudad']) as $bid) {
                foreach ($this->discosPorBanda[$bid] ?? [] as $d) {
                    foreach (['n', 'seq'] as $modo) {
                        [$coinc, $comparables] = $this->solape($o['pistas'], $this->pistas[$d['id']] ?? [], $modo);
                        if ($comparables === 0) {
                            continue;
                        }
                        $f = $coinc / $comparables;
                        $mismoAnio = $o['anio'] !== null && $o['anio'] === $d['anio'];
                        if ($coinc >= 2 && ($f >= 0.5 || ($mismoAnio && $f >= 0.4))) {
                            // "fiable" = es el MISMO disco, no una antología o reedición con las mismas pistas:
                            // año a ±1 o nombre parecido. Para deduplicar marchas basta el solapamiento.
                            $anioCerca = $o['anio'] !== null && $d['anio'] !== null && abs((int) $o['anio'] - (int) $d['anio']) <= 1;
                            $sn = self::simTitulo($o['nombre'], $d['nombre']);
                            $cands[] = ['id_disco' => $d['id'], 'id_banda' => $bid, 'solape' => round($f, 3),
                                        'coinciden' => $coinc, 'modo' => $modo, 'anio' => $mismoAnio,
                                        'fiable' => $f >= 0.8 && ($anioCerca || $sn['ratio'] >= 0.6 || $sn['contenido'])];
                        }
                    }
                }
            }
            usort($cands, static fn(array $x, array $y): int
                => [$y['coinciden'], $y['solape'], $y['anio']] <=> [$x['coinciden'], $x['solape'], $x['anio']]);
            // quita el duplicado por modo del mismo disco
            $vistos = [];
            $cands = array_values(array_filter($cands, static function (array $c) use (&$vistos): bool {
                if (isset($vistos[$c['id_disco']])) {
                    return false;
                }
                return $vistos[$c['id_disco']] = true;
            }));
            if ($cands !== [] && (count($cands) === 1
                || [$cands[0]['coinciden'], $cands[0]['solape']] > [$cands[1]['coinciden'], $cands[1]['solape']])) {
                unset($cands[0]['anio']);
                $res = $cands[0];
            }
        }
        return $this->memoDisco[$pkOrigen] = $res;
    }

    /** @return array{0:int,1:int} [posiciones con título parecido, posiciones comparables] */
    private function solape(array $pOrigen, array $pBd, string $modo): array
    {
        $porPos = [];
        foreach ($pBd as $p) {
            $porPos[$p[$modo]][] = $p;
        }
        $coinc = $comp = 0;
        foreach ($pOrigen as $i => $p) {
            $pos = $modo === 'n' ? $p['n'] : $i + 1;
            if ($pos === null || !isset($porPos[$pos])) {
                continue;
            }
            $comp++;
            foreach ($porPos[$pos] as $q) {
                if (self::pistaParecida($p['titulo'], $q['titulo'])) {
                    $coinc++;
                    break;
                }
            }
        }
        return [$coinc, $comp];
    }

    // ------------------------------------------------------------ pista origen → marcha BD

    /**
     * Marcha de la BD que ocupa en el disco equivalente la posición de una pista
     * del origen. Devuelve la clase:
     *  - 'existe'  : posición + (título parecido/contenido, o mismo autor no genérico con título
     *                algo parecido), o título casi igual en ese disco
     *  - 'revisar' : la posición cae en otra marcha y ni título ni autor lo confirman, o casa
     *                pero el origen da otro compositor (ver 'discrepancia_autor')
     * o null si el disco del origen no está en la BD.
     * @param list<int> $autoresHint ids de autor ya resueltos por quien llama
     * @return array<string,mixed>|null
     */
    public function cruzarPista(int $pkDisco, int $idx, string $titulo, array $autoresHint = []): ?array
    {
        $r = $this->cruzarPistaPosicion($pkDisco, $idx, $titulo, $autoresHint);
        if ($r === null) {
            return null;
        }
        // Mismo disco y posición, pero el origen atribuye la pista a un compositor concreto distinto del de la
        // marcha en BD (Popular/Tradicional cuentan como atribución; Desconocido/Varios no): puede ser una homónima
        // de otro autor ("La Salve" de Martín Martín frente a la popular) y el enlace de la BD estar mal.
        // Es duda entre dos registros → 'revisar', nunca 'existe'.
        $r['discrepancia_autor'] = !$r['mismo_autor'] && !self::esAutorGenerico($r['autor_origen'])
            && $r['autores_bd'] !== [];
        if ($r['clase'] === 'existe' && $r['discrepancia_autor']) {
            $r['clase'] = 'revisar';
            $r['via'] .= '+autor_distinto';
        }
        return $r;
    }

    /** @return array<string,mixed>|null */
    private function cruzarPistaPosicion(int $pkDisco, int $idx, string $titulo, array $autoresHint): ?array
    {
        $o = $this->discoOrigen($pkDisco);
        $d = $this->discoBd($pkDisco);
        if ($o === null || $d === null || !isset($o['pistas'][$idx])) {
            return null;
        }
        $po = $o['pistas'][$idx];
        $pos = $d['modo'] === 'n' ? $po['n'] : $idx + 1;
        $autores = array_values(array_diff(array_unique(array_merge($autoresHint, $this->autoresBd($po['autor']))),
            $this->autoresGenericos));
        $loc = preg_match('/\(([^)]+)\)\s*$/u', $o['banda_nombre'], $m) ? $m[1] : null;
        $base = ['id_disco' => $d['id_disco'], 'id_banda' => $d['id_banda'], 'solape_disco' => $d['solape'],
                 'disco_origen' => $pkDisco, 'pista_origen' => $po['n'], 'autor_origen' => $po['autor']];

        $enPos = null;
        foreach ($this->pistas[$d['id_disco']] ?? [] as $q) {
            if ($pos !== null && $q[$d['modo']] === $pos) {
                $s = self::simTitulo($titulo, $q['titulo'], $loc);
                $mismoAutor = ($autores !== [] && array_intersect($autores, $q['autores']) !== [])
                    || $this->autorCompatible($po['autor'], $q['autores']);
                $c = $base + ['id_marcha' => $q['id_marcha'], 'titulo_bd' => $q['titulo'], 'pista' => $q['n'],
                              'cd' => $q['cd'], 'ratio' => round($s['ratio'], 3), 'contenido' => $s['contenido'],
                              'mismo_autor' => $mismoAutor,
                              'autores_bd' => array_values(array_diff($q['autores'], $this->autoresSinInfo))];
                if ($s['ratio'] >= self::UMBRAL_CONFIRMA || $s['contenido']
                    || ($mismoAutor && $s['ratio'] >= self::UMBRAL_CON_AUTOR)) {
                    return $c + ['clase' => 'existe', 'via' => 'posicion'];
                }
                $enPos ??= $c;
            }
        }
        // numeración desplazada: título casi igual (o contenido + mismo autor) en otra pista del mismo disco
        foreach ($this->pistas[$d['id_disco']] ?? [] as $q) {
            $s = self::simTitulo($titulo, $q['titulo'], $loc);
            $mismoAutor = ($autores !== [] && array_intersect($autores, $q['autores']) !== [])
                    || $this->autorCompatible($po['autor'], $q['autores']);
            if ($s['ratio'] >= self::UMBRAL_MISMO_DISCO || ($s['contenido'] && $mismoAutor)) {
                return $base + ['id_marcha' => $q['id_marcha'], 'titulo_bd' => $q['titulo'], 'pista' => $q['n'],
                                'cd' => $q['cd'], 'ratio' => round($s['ratio'], 3), 'contenido' => $s['contenido'],
                                'mismo_autor' => $mismoAutor,
                              'autores_bd' => array_values(array_diff($q['autores'], $this->autoresSinInfo)), 'clase' => 'existe', 'via' => 'titulo_mismo_disco'];
            }
        }
        return $enPos !== null ? $enPos + ['clase' => 'revisar', 'via' => 'posicion_sin_confirmar'] : null;
    }

    /** Índice de la pista del disco del origen que mejor casa con un título (≥0.9) */
    public function indicePorTitulo(int $pkDisco, string $titulo): ?int
    {
        $best = null;
        $bs = 0.0;
        foreach ($this->discoOrigen($pkDisco)['pistas'] ?? [] as $i => $p) {
            $s = self::simTitulo($titulo, $p['titulo'])['ratio'];
            if ($s > $bs) {
                [$best, $bs] = [$i, $s];
            }
        }
        return $bs >= 0.9 ? $best : null;
    }

    /** Índice de la pista cuyo enlace apunta a la marcha $pkMarcha del origen */
    public function indicePorMarcha(int $pkDisco, int $pkMarcha): ?int
    {
        foreach ($this->discoOrigen($pkDisco)['pistas'] ?? [] as $i => $p) {
            if ($p['id_marcha'] === $pkMarcha) {
                return $i;
            }
        }
        return null;
    }
}
