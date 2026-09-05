<?php

declare(strict_types=1);

/*
 * Seed / normalización de dedicatorias para los hubs de advocación (N-01 / N-02).
 *
 * Agrupa los pares (marcha.DEDICATORIA, marcha.LOCALIDAD) en advocaciones canónicas
 * (tabla dedicatoria) mediante una clave heurística, y vuelca el mapeo variante ->
 * canónica en dedicatoria_alias. Ver app/tools/sql/003_dedicatoria.sql.
 *
 * Heurística de agrupación (SLUG_KEY):
 *   1. Quitar el prefijo de tipo de entidad ("Hdad", "Hermandad", "Cofradía"…).
 *   2. Quitar un artículo inicial (La/El/Los/Las) para que "La Estrella" y "Estrella"
 *      caigan juntas.
 *   3. clave = slug(nombre-sin-artículo) | slug(localidad).
 *   El nombre visible conserva su forma con artículo y mayúsculas ("La Estrella").
 *
 * IDEMPOTENTE y respetuoso con la curación manual:
 *   - Solo inserta pares (VARIANTE, LOCALIDAD) que aún NO existan en dedicatoria_alias.
 *     Una reasignación hecha en el panel admin nunca se pisa al re-ejecutar.
 *   - Reutiliza la canónica existente con la misma SLUG_KEY; solo crea las que faltan
 *     y NO reescribe NOMBRE/LOCALIDAD de canónicas ya existentes (renombrados a salvo).
 *   Así, tras cada pasada mensual de ingesta que traiga dedicatorias nuevas, basta
 *   re-ejecutar este script para "ampliar al vuelo" los alias.
 *
 * Uso:
 *   php php/app/tools/seed_dedicatorias.php
 *   DB_PATH=/ruta/a/mdc.db php .../seed_dedicatorias.php
 *   php .../seed_dedicatorias.php --dry-run     # no escribe, solo informa
 *
 * Hace copia de seguridad (VACUUM INTO) antes de tocar nada (salvo --dry-run).
 */

define('APP_DIR', dirname(__DIR__));       // .../app
define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
define('DATA_DIR', BASE_DIR . '/data');

// Autoloader mínimo para reutilizar App\Slug::slugify (idéntico al de bootstrap.php).
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

use App\Slug;

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];
$dryRun = in_array('--dry-run', $argv, true);

if (!is_file($db)) {
    fwrite(STDERR, "Seed abortado: no existe la BD en $db\n");
    exit(1);
}
$ddlFile = __DIR__ . '/sql/003_dedicatoria.sql';
if (!is_file($ddlFile)) {
    fwrite(STDERR, "Seed abortado: no existe $ddlFile\n");
    exit(1);
}

/** Prefijos de tipo de entidad a retirar del inicio (case-insensitive). */
const PREFIJOS = [
    'archicofradía', 'archicofradia', 'hermandad', 'cofradía', 'cofradia',
    'hdad.', 'hdad', 'hna.', 'hna', 'cofr.', 'cofr', 'corf', 'stmo.', 'stmo',
];
/** Artículos iniciales a ignorar SOLO para la clave de agrupación. */
const ARTICULOS = ['la', 'el', 'los', 'las'];

/** Limpia el nombre visible: retira el prefijo de entidad y normaliza espacios. */
function limpiarNombre(string $raw): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    $tokens = $s === '' ? [] : explode(' ', $s);
    if ($tokens !== [] && in_array(mb_strtolower($tokens[0], 'UTF-8'), PREFIJOS, true)) {
        array_shift($tokens);
    }
    $limpio = trim(implode(' ', $tokens));
    return $limpio !== '' ? $limpio : $s; // nunca devolver vacío
}

/** Clave de agrupación: nombre sin prefijo ni artículo inicial + localidad. */
function claveAgrupacion(string $nombreLimpio, string $localidad): string
{
    $tokens = $nombreLimpio === '' ? [] : explode(' ', $nombreLimpio);
    while ($tokens !== [] && in_array(mb_strtolower($tokens[0], 'UTF-8'), ARTICULOS, true)) {
        array_shift($tokens);
    }
    $base = implode(' ', $tokens);
    if ($base === '') $base = $nombreLimpio;
    return Slug::slugify($base) . '|' . Slug::slugify($localidad);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:seed_dedicatorias' WHERE ID = 1");

    if (!$dryRun) {
        $backupDir = dirname($db) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            fwrite(STDERR, "Seed abortado: no se pudo crear $backupDir\n");
            exit(1);
        }
        $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-dedicatoria.db';
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        echo 'backup: ' . $dest . "\n";
    }

    // Asegurar el esquema (idempotente).
    $pdo->exec((string) file_get_contents($ddlFile));

    // Pares (DEDICATORIA, LOCALIDAD) crudos + una provincia y frecuencia representativas.
    $rows = $pdo->query(
        "SELECT DEDICATORIA AS ded, COALESCE(LOCALIDAD, '') AS loc,
                MAX(PROVINCIA) AS prov, COUNT(*) AS n
         FROM marcha
         WHERE DEDICATORIA IS NOT NULL AND TRIM(DEDICATORIA) <> ''
         GROUP BY DEDICATORIA, loc"
    )->fetchAll();

    // Agrupar pares por SLUG_KEY.
    $grupos = []; // clave => ['nombre'=>..,'loc'=>..,'prov'=>..,'peso'=>int, 'pares'=>[[ded,loc,n],...]]
    foreach ($rows as $r) {
        $ded = (string) $r['ded'];
        $loc = (string) $r['loc'];
        $n = (int) $r['n'];
        $nombre = limpiarNombre($ded);
        $clave = claveAgrupacion($nombre, $loc);
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = ['nombre' => $nombre, 'loc' => $loc, 'prov' => $r['prov'], 'peso' => -1, 'pares' => []];
        }
        $grupos[$clave]['pares'][] = [$ded, $loc, $n];
        // El representante (nombre/localidad visibles) es el par más frecuente del grupo.
        if ($n > $grupos[$clave]['peso']) {
            $grupos[$clave]['peso'] = $n;
            $grupos[$clave]['nombre'] = $nombre;
            $grupos[$clave]['loc'] = $loc;
            $grupos[$clave]['prov'] = $r['prov'];
        }
    }

    // Alias ya presentes (para no pisar la curación manual).
    $yaExiste = [];
    foreach ($pdo->query('SELECT VARIANTE, LOCALIDAD FROM dedicatoria_alias')->fetchAll() as $a) {
        $yaExiste[$a['VARIANTE'] . "\x00" . $a['LOCALIDAD']] = true;
    }
    // Canónicas ya presentes (por SLUG_KEY).
    $canonPorClave = [];
    foreach ($pdo->query('SELECT ID_DEDIC, SLUG_KEY FROM dedicatoria')->fetchAll() as $d) {
        $canonPorClave[(string) $d['SLUG_KEY']] = (int) $d['ID_DEDIC'];
    }

    if (!$dryRun) $pdo->beginTransaction();
    $insCanon = $pdo->prepare('INSERT INTO dedicatoria (NOMBRE, LOCALIDAD, PROVINCIA, SLUG_KEY, PERSONAL) VALUES (?, ?, ?, ?, ?)');
    $insAlias = $pdo->prepare('INSERT INTO dedicatoria_alias (VARIANTE, LOCALIDAD, ID_DEDIC) VALUES (?, ?, ?)');

    $nuevasCanon = 0;
    $nuevosAlias = 0;
    foreach ($grupos as $clave => $g) {
        $idDedic = $canonPorClave[$clave] ?? null;
        if ($idDedic === null) {
            $nuevasCanon++;
            if (!$dryRun) {
                $personal = \App\Repo::esDedicatoriaPersonal($g['nombre']) ? 1 : 0;
                $insCanon->execute([$g['nombre'], $g['loc'], $g['prov'], $clave, $personal]);
                $idDedic = (int) $pdo->lastInsertId();
                $canonPorClave[$clave] = $idDedic;
            }
        }
        foreach ($g['pares'] as [$ded, $loc, $n]) {
            if (isset($yaExiste[$ded . "\x00" . $loc])) continue; // curado o ya sembrado
            $nuevosAlias++;
            if (!$dryRun && $idDedic !== null) {
                $insAlias->execute([$ded, $loc, $idDedic]);
            }
        }
    }
    if (!$dryRun) $pdo->commit();

    $totCanon = (int) $pdo->query('SELECT COUNT(*) FROM dedicatoria')->fetchColumn();
    $totAlias = (int) $pdo->query('SELECT COUNT(*) FROM dedicatoria_alias')->fetchColumn();
    echo ($dryRun ? "[dry-run] " : "")
        . "grupos={" . count($grupos) . "} canónicas nuevas=$nuevasCanon alias nuevos=$nuevosAlias\n";
    echo "totales: dedicatoria=$totCanon · dedicatoria_alias=$totAlias\n";
    echo "OK.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Seed falló: ' . $e->getMessage() . "\n");
    exit(1);
}
