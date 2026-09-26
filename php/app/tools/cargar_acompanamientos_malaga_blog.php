<?php

declare(strict_types=1);

/*
 * Carga los acompañamientos históricos de UNA hermandad de Málaga a partir de
 * su etiqueta en malagamusical.blogspot.com. La lógica y las reglas de negocio
 * viven en App\MalagaBlogImporter (compartida con el panel
 * /dashboard/acompanamientos-malaga-blog); esto solo la ejecuta desde consola.
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

use App\MalagaBlogImporter as M;

[, $db] = cliBootstrap('Carga abortada');
require APP_DIR . '/src/Slug.php';
require APP_DIR . '/src/MalagaBlogImporter.php';

$args = array_slice($_SERVER['argv'] ?? [], 1);
$commit = in_array('--commit', $args, true);
$url = null;
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        $url = $arg;
        break;
    }
}
if ($url === null) {
    fwrite(STDERR, "USO: php php/app/tools/cargar_acompanamientos_malaga_blog.php <URL_etiqueta_del_blog> [--commit]\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $a = M::analizar($pdo, $url);

    if (!$a['listo']) {
        if ($a['faltaHermandad']) {
            echo "FALTA MAPEO: la etiqueta \"{$a['etiqueta']}\" (slug \"{$a['labelSlug']}\") no está en " . M::mapeoPath() . "\n";
            $pasos = [];
            foreach ($a['cabeceras'] as $c) {
                $pasos[M::claveCabecera($c)] = ['nombre' => '???'];
            }
            echo "Propuesta a añadir (edítala con el NOMBRE real de la hermandad y de cada paso antes de rejecutar),\n";
            echo "o complétala desde /dashboard/acompanamientos-malaga-blog:\n";
            echo json_encode([$a['labelSlug'] => ['hermandad_slug' => $a['labelSlug'], 'hermandad_nombre' => '???', 'pasos' => $pasos]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "FALTA MAPEO de paso para \"{$a['etiqueta']}\": cabecera(s) sin entrada en pasos[] de malaga_blog_mapeo.json:\n";
            foreach ($a['sinMapeo'] as $c) {
                echo "  - \"$c\"\n";
            }
        }
        echo "No se ha tocado la BD.\n";
        exit(1);
    }

    echo "=== {$a['etiqueta']} ({$a['url']}) ===\n";
    if ($a['idHermandad'] === null) {
        echo "Hermandad NUEVA a crear: LOCALIDAD=Malaga SLUG={$a['hermandadSlug']} NOMBRE={$a['hermandadNombre']} (DIA='Sin asignar', provisional hasta que el usuario la ordene)\n";
    }
    if ($a['pasosNuevos'] !== []) {
        echo 'Pasos nuevos a crear: ' . implode(', ', array_map(static fn($p) => $p['nombre'] . ($p['es_cruz_guia'] ? ' [cruz de guía]' : ''), $a['pasosNuevos'])) . "\n";
    }
    echo 'Contratos nuevos a insertar: ' . count($a['nuevosContratos']) . "\n";
    foreach ($a['nuevosContratos'] as $f) {
        echo "  - {$f['paso']} {$f['anio']}: ID_BANDA={$f['idBanda']} (\"{$f['bandaTexto']}\")\n";
    }
    echo "Ya cargados (re-ejecución, idénticos): {$a['yaCargados']}\n";
    echo 'Pendientes nuevas (banda sin enlazar): ' . count($a['nuevasPendientes']) . "\n";
    foreach ($a['nuevasPendientes'] as $f) {
        echo "  - {$f['paso']} {$f['anio']}: \"{$f['bandaTexto']}\"\n";
    }
    echo "Ya en pendientes (re-ejecución): {$a['yaPendientes']}\n";
    $listas = [
        'resueltoPorOtraFuente' => 'Ya resuelto por otra fuente (no se guarda pendiente)',
        'conflictos' => 'CONFLICTOS (no se toca nada)',
        'dudasTipo' => 'Dudas — tipo de banda / paso no reconocido',
        'dudasBanda' => 'Dudas — banda ambigua, no enlazada',
    ];
    foreach ($listas as $clave => $titulo) {
        if ($a[$clave] !== []) {
            echo "$titulo (" . count($a[$clave]) . "):\n";
            foreach ($a[$clave] as $s) {
                echo "  - $s\n";
            }
        }
    }
    if ($a['descartadas'] !== []) {
        echo 'Descartadas por tipo de banda (' . count($a['descartadas']) . "):\n";
        foreach ($a['descartadas'] as $d) {
            echo "  - {$d['paso']} {$d['anio']}: \"{$d['texto']}\" ({$d['motivo']})\n";
        }
    }
    if ($a['lineasNoParseadas'] !== []) {
        echo 'Líneas no reconocidas (' . count($a['lineasNoParseadas']) . "):\n";
        foreach ($a['lineasNoParseadas'] as $l) {
            echo "  - \"$l\"\n";
        }
    }

    if (!$commit) {
        echo "\n--dry-run (por defecto): no se ha tocado la BD. Añade --commit para escribir.\n";
        exit(0);
    }

    $r = M::aplicar($pdo, $a, $db, 'cli:cargar_acompanamientos_malaga_blog');
    echo "backup: {$r['backup']}\n";
    if ($r['hermandadCreada'] !== null) {
        echo "hermandad creada: ID_HERMANDAD={$r['hermandadCreada']} ({$a['hermandadSlug']})\n";
    }
    foreach ($r['pasosCreados'] as $p) {
        echo "paso creado: $p\n";
    }
    echo "{$r['contratos']} contratos y {$r['pendientes']} pendientes insertados\n";
    echo 'FK check: ' . ($r['fkLimpio'] ? 'limpio' : 'REVISAR (PRAGMA foreign_key_check)') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Carga abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
