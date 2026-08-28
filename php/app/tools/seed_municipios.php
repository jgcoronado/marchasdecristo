<?php

declare(strict_types=1);

/*
 * Siembra la tabla `municipio` (ver app/tools/sql/007_municipio.sql), que
 * alimenta los desplegables de localidad/provincia del panel y los puntos del
 * mapa.
 *
 * Dos orígenes, en este orden:
 *
 *   1) OFICIALES — los 8.112 municipios de app/geo/municipios_es.php, con sus
 *      coordenadas (OFICIAL = 1).
 *   2) HEREDADOS — los pares (LOCALIDAD, PROVINCIA) que ya usan marcha y banda
 *      y que no casen con ninguno oficial (OFICIAL = 0, sin coordenadas).
 *      Así ninguna ficha existente deja de poder guardarse el primer día,
 *      aunque su localidad sea una errata, una pedanía o texto libre antiguo;
 *      quedan marcados para que puedas depurarlos cuando quieras:
 *
 *        SELECT PROVINCIA, NOMBRE FROM municipio WHERE OFICIAL = 0;
 *
 * Requiere que la tabla exista: aplica antes las migraciones con
 *   php php/app/tools/migrate_ingest.php
 *
 * Re-ejecutable: solo inserta lo que falta, nunca pisa filas existentes (no
 * toca las altas manuales hechas desde el panel ni sus coordenadas).
 *
 * Uso:
 *   php php/app/tools/seed_municipios.php
 *   DB_PATH=/ruta/a/mdc.db php .../seed_municipios.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Seed abortado');

/** Misma normalización que App\Db::noAcc / MunicipioRepo::clave. */
function claveMunicipio(string $provincia, string $nombre): string
{
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
        return $d === false ? $s : (string) preg_replace('/\p{Mn}/u', '', $d);
    };
    return $norm($provincia) . '|' . $norm($nombre);
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $tabla = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='municipio'")->fetchColumn();
    if ($tabla === false) {
        fwrite(STDERR, "Seed abortado: no existe la tabla `municipio`.\n"
            . "Aplica antes las migraciones: php php/app/tools/migrate_ingest.php\n");
        exit(1);
    }

    // Claves ya presentes: el seed nunca pisa nada existente.
    $existentes = [];
    foreach ($pdo->query('SELECT CLAVE FROM municipio')->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $existentes[(string) $c] = true;
    }

    $ins = $pdo->prepare(
        'INSERT INTO municipio (PROVINCIA, NOMBRE, LAT, LNG, OFICIAL, CLAVE) VALUES (?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();

    // 1) Oficiales del fichero semilla.
    /** @var list<array{0:string,1:string,2:float,3:float}> $oficiales */
    $oficiales = require APP_DIR . '/geo/municipios_es.php';
    $nOficiales = 0;
    foreach ($oficiales as [$provincia, $nombre, $lat, $lng]) {
        $clave = claveMunicipio($provincia, $nombre);
        if (isset($existentes[$clave])) {
            continue;
        }
        $ins->execute([$provincia, $nombre, $lat, $lng, 1, $clave]);
        $existentes[$clave] = true;
        $nOficiales++;
    }

    // 2) Pares heredados de las fichas que no casen con ningún oficial.
    $heredados = $pdo->query(
        "SELECT DISTINCT LOCALIDAD, PROVINCIA FROM (
             SELECT LOCALIDAD, PROVINCIA FROM marcha
             UNION
             SELECT LOCALIDAD, PROVINCIA FROM banda
         )
         WHERE LOCALIDAD IS NOT NULL AND TRIM(LOCALIDAD) != ''
           AND PROVINCIA IS NOT NULL AND TRIM(PROVINCIA) != ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $nHeredados = 0;
    $listaHeredados = [];
    foreach ($heredados as $r) {
        $provincia = trim((string) $r['PROVINCIA']);
        $nombre = trim((string) $r['LOCALIDAD']);
        $clave = claveMunicipio($provincia, $nombre);
        if (isset($existentes[$clave])) {
            continue;
        }
        $ins->execute([$provincia, $nombre, null, null, 0, $clave]);
        $existentes[$clave] = true;
        $nHeredados++;
        $listaHeredados[] = "$nombre ($provincia)";
    }

    $pdo->commit();
    // Vuelca el WAL al fichero principal (ver app/tools/normalizar_localidades.php).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $total = (int) $pdo->query('SELECT COUNT(*) FROM municipio')->fetchColumn();
    echo "municipios oficiales insertados: $nOficiales\n";
    echo "pares heredados de las fichas:   $nHeredados\n";
    echo "total en la tabla:               $total\n";

    if ($listaHeredados !== []) {
        sort($listaHeredados, SORT_LOCALE_STRING);
        echo "\nheredados (no están en el listado oficial — revisa si son erratas o pedanías):\n";
        foreach ($listaHeredados as $h) {
            echo "  $h\n";
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed falló: ' . $e->getMessage() . "\n");
    exit(1);
}
