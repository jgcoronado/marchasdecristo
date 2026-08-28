<?php

declare(strict_types=1);

/*
 * Métricas de tamaño y complejidad del código PHP, para tener en una sola
 * pantalla lo que cuesta revisar el repositorio: qué clases se han convertido
 * en god classes y qué métodos tienen la complejidad concentrada.
 *
 * Complementa a PHPMD (que avisa por umbral) dando el ranking completo y la
 * evolución: PHPMD dice "esto pasa de 15", esto dice "y estos son los 15
 * peores, ordenados". Sirve para elegir el siguiente refactor.
 *
 * La complejidad ciclomática es una APROXIMACIÓN por tokens (cuenta if/elseif/
 * for/foreach/while/case/catch, operadores lógicos, ?: y ??). Es la misma
 * definición que usa PHPMD salvo matices, suficiente para ordenar por dolor.
 *
 * Uso:
 *   php scripts/quality_metrics.php                 # top 15 de cada ranking
 *   php scripts/quality_metrics.php --top=30
 *   php scripts/quality_metrics.php --csv > m.csv   # para comparar en el tiempo
 *
 * Ver docs/code-quality.md.
 */

const ROOT = __DIR__ . '/..';

/** Rutas analizadas (código propio). Relativas a la raíz del repositorio. */
const PATHS = ['php/app', 'php/public', 'php/tools', 'scripts'];

/** Datos generados, no código que se revise a mano. */
const EXCLUDE = ['php/app/geo/municipios_es.php'];

/** Umbrales de referencia, alineados con phpmd.xml. */
const CC_WARN = 15;
const METHOD_LOC_WARN = 80;
const CLASS_LOC_WARN = 1200;

$top = 15;
$csv = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--top=')) $top = max(1, (int) substr($arg, 6));
    elseif ($arg === '--csv') $csv = true;
    else {
        fwrite(STDERR, "Argumento no reconocido: $arg\n");
        exit(2);
    }
}

/**
 * Tokeniza un fichero y devuelve una lista normalizada [id|null, texto, línea].
 * token_get_all() no da línea para los tokens de un carácter ('{', '}', '?'…),
 * así que se arrastra la última conocida.
 *
 * @return list<array{0:int|null,1:string,2:int}>
 */
function tokens(string $src): array
{
    $out = [];
    $line = 1;
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            $out[] = [$t[0], $t[1], $t[2]];
            $line = $t[2] + substr_count($t[1], "\n");
        } else {
            $out[] = [null, $t, $line];
        }
    }
    return $out;
}

/** Tokens que suman un camino de decisión. */
const CC_TOKENS = [
    T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH,
    T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_COALESCE, T_MATCH,
];

/**
 * Nombre de la función que empieza en $i (T_FUNCTION). Un '(' antes de
 * cualquier identificador significa closure o función flecha.
 *
 * @param list<array{0:int|null,1:string,2:int}> $flat
 */
function nombreFuncion(array $flat, int $i): string
{
    for ($j = $i + 1, $n = count($flat); $j < $n; $j++) {
        if ($flat[$j][0] === T_STRING) return $flat[$j][1];
        if ($flat[$j][1] === '(') break;
    }
    return '{closure}';
}

/**
 * Cuerpo de la función que empieza en $i: desde la primera '{' de nivel 0
 * hasta su cierre, con la complejidad acumulada. Devuelve null si no hay
 * cuerpo (método abstracto o de interfaz: llega un ';' antes de la '{').
 *
 * @param list<array{0:int|null,1:string,2:int}> $flat
 * @return array{start:int,end:int,cc:int}|null
 */
function cuerpoFuncion(array $flat, int $i): ?array
{
    $depth = 0;
    $start = null;
    $cc = 1;

    for ($j = $i + 1, $n = count($flat); $j < $n; $j++) {
        $txt = $flat[$j][1];

        if ($txt === '{') {
            $depth++;
            $start ??= $j;
        } elseif ($txt === '}') {
            $depth--;
            if ($depth === 0) return ['start' => (int) $start, 'end' => $j, 'cc' => $cc];
        } elseif ($txt === ';' && $start === null) {
            return null;
        }

        if ($start !== null && (in_array($flat[$j][0], CC_TOKENS, true) || $txt === '?')) {
            $cc++;   // '?' cubre el ternario
        }
    }

    return null;
}

/** @return list<array{file:string,name:string,loc:int,cc:int,line:int}> */
function analizarFichero(string $path, string $rel): array
{
    $flat = tokens((string) file_get_contents($path));
    $rows = [];

    for ($i = 0, $n = count($flat); $i < $n; $i++) {
        if ($flat[$i][0] !== T_FUNCTION) continue;

        $cuerpo = cuerpoFuncion($flat, $i);
        if ($cuerpo === null) continue;

        $rows[] = [
            'file' => $rel,
            'name' => nombreFuncion($flat, $i),
            'loc'  => $flat[$cuerpo['end']][2] - $flat[$cuerpo['start']][2] + 1,
            'cc'   => $cuerpo['cc'],
            'line' => $flat[$i][2],
        ];
        $i = $cuerpo['end'];   // no recontar las funciones anidadas dentro
    }

    return $rows;
}

// ── Recorrido ────────────────────────────────────────────────────────────────

$metodos = [];
$ficheros = [];

foreach (PATHS as $sub) {
    $dir = ROOT . '/' . $sub;
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || $f->getExtension() !== 'php') continue;
        $rel = str_replace(realpath(ROOT) . '/', '', (string) realpath($f->getPathname()));
        if (in_array($rel, EXCLUDE, true)) continue;

        $ficheros[$rel] = count(file($f->getPathname()));
        $metodos = [...$metodos, ...analizarFichero($f->getPathname(), $rel)];
    }
}

if ($metodos === []) {
    fwrite(STDERR, "No se ha encontrado código PHP que analizar.\n");
    exit(1);
}

if ($csv) {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['fichero', 'metodo', 'linea', 'loc', 'cc']);
    foreach ($metodos as $m) fputcsv($out, [$m['file'], $m['name'], $m['line'], $m['loc'], $m['cc']]);
    fclose($out);
    exit(0);
}

// ── Salida ───────────────────────────────────────────────────────────────────

$totalLoc = array_sum($ficheros);
$ccAltos = array_filter($metodos, static fn(array $m): bool => $m['cc'] > CC_WARN);
$locAltos = array_filter($metodos, static fn(array $m): bool => $m['loc'] > METHOD_LOC_WARN);
$clasesGrandes = array_filter($ficheros, static fn(int $l): bool => $l > CLASS_LOC_WARN);

printf("Métricas del código PHP — %s\n", date('Y-m-d'));
printf("%d ficheros · %s líneas · %d funciones/métodos\n", count($ficheros), number_format($totalLoc), count($metodos));
printf("Fuera de umbral: %d métodos con cc>%d · %d métodos de más de %d líneas · %d ficheros de más de %d líneas\n",
    count($ccAltos), CC_WARN, count($locAltos), METHOD_LOC_WARN, count($clasesGrandes), CLASS_LOC_WARN);

$porCc = $metodos;
usort($porCc, static fn(array $a, array $b): int => $b['cc'] <=> $a['cc']);
printf("\n── Top %d por complejidad ciclomática ──\n", $top);
foreach (array_slice($porCc, 0, $top) as $m) {
    printf("  cc=%-4d loc=%-5d %s::%s (L%d)%s\n", $m['cc'], $m['loc'], $m['file'], $m['name'], $m['line'],
        $m['cc'] > CC_WARN ? '  ⚠' : '');
}

$porLoc = $metodos;
usort($porLoc, static fn(array $a, array $b): int => $b['loc'] <=> $a['loc']);
printf("\n── Top %d por longitud de método ──\n", $top);
foreach (array_slice($porLoc, 0, $top) as $m) {
    printf("  loc=%-5d cc=%-4d %s::%s (L%d)%s\n", $m['loc'], $m['cc'], $m['file'], $m['name'], $m['line'],
        $m['loc'] > METHOD_LOC_WARN ? '  ⚠' : '');
}

arsort($ficheros);
printf("\n── Top %d ficheros por líneas ──\n", $top);
foreach (array_slice($ficheros, 0, $top, true) as $rel => $loc) {
    printf("  %-6d %s%s\n", $loc, $rel, $loc > CLASS_LOC_WARN ? '  ⚠' : '');
}
