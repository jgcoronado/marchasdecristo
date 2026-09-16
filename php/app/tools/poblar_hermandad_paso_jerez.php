<?php

declare(strict_types=1);

/*
 * Puebla `hermandad` y `paso` (012_hermandad_paso.sql) con la nómina 2026 de
 * Jerez de la Frontera, a partir del cruce de musicofrades.com (parseado por
 * scripts/tmp_acompanamientos/parse_musicofrades.py a musicofrades_extraido.csv)
 * con el artículo de mundocofrade.es "Acompañamientos musicales Semana Santa
 * 2026: bandas que tocan en Jerez" (fuente preferida en caso de conflicto,
 * por decisión del usuario). Ver scripts/tmp_acompanamientos/jerez_bandas_reconciliado.csv
 * para el detalle fila a fila del cruce.
 *
 * Alcance del sitio (docs/acompanamientos-nomina-2026.md): SOLO CCTT/AM y
 * SOLO pasos de Cristo. De las 49 hermandades de Jerez con ficha en
 * musicofrades, 10 van sin acompañamiento válido (silencio, banda de música,
 * capilla musical o escolanía en vez de CCTT/AM) — se crean como hermandad
 * SIN paso, igual que "Dolores de Alcolea" en Córdoba: sin contratos, salen
 * igual listadas como "sin acompañamiento CCTT/AM hasta la fecha".
 *
 * Nombre del paso: a diferencia de Córdoba, ninguna de las dos fuentes da el
 * nombre del titular de Cristo (solo la etiqueta genérica de la hermandad) —
 * bloqueo ya conocido y documentado. Por decisión del usuario (2026-09-08,
 * "añade ya los acompañamientos, los conflictos los resuelvo manualmente en
 * la web"), el paso se crea con el mismo nombre que la hermandad como
 * PLACEHOLDER, pendiente de renombrar a mano con el titular real.
 *
 * Sin orden de carrera oficial para Jerez (ninguna fuente lo da, igual que
 * Sevilla/Málaga) — pero `hermandad.ORDEN` es NOT NULL en el esquema, así
 * que se usa el ORDEN_PAGINA de musicofrades.com (orden en que la página
 * lista la hermandad dentro del día, NO el de carrera oficial — ver
 * reference_fuentes_acompanamientos: "el orden en que lista las hermandades
 * NO es el de carrera oficial") como valor temporal, hasta que se corrija a
 * mano con el orden real. HORA_SALIDA sí queda NULL (no hay dato).
 *
 * Re-ejecutable: `hermandad` tiene UNIQUE(LOCALIDAD,SLUG) y `paso`
 * UNIQUE(ID_HERMANDAD,NOMBRE) — INSERT OR IGNORE no duplica nada.
 *
 * Uso:
 *   php php/app/tools/poblar_hermandad_paso_jerez.php
 *   DB_PATH=/ruta/a/mdc.db php .../poblar_hermandad_paso_jerez.php
 */

require __DIR__ . '/_cli.php';

use App\Slug;

[, $db] = cliBootstrap('Población abortada');
require APP_DIR . '/src/Slug.php';

const LOCALIDAD = 'Jerez de la Frontera';
const FUENTE = 'musicofrades.com + mundocofrade.es (Semana Santa Jerez 2026)';

/**
 * @return list<array{NOMBRE:string,DIA:string,DIA_ORDEN:int,ORDEN:int,TIENE_PASO:bool}>
 */
function nomina(): array
{
    $dias = [
        'Sabado de Pasion' => 1, 'Domingo de Ramos' => 2, 'Lunes Santo' => 3,
        'Martes Santo' => 4, 'Miercoles Santo' => 5, 'Jueves Santo' => 6,
        'Noche de Jesus' => 7, 'Viernes Santo' => 8, 'Sabado Santo' => 9,
        'Domingo de Resurreccion' => 10,
    ];
    // [NOMBRE, DIA, ORDEN_PAGINA_musicofrades, TIENE_PASO] — TIENE_PASO=false:
    // sin CCTT/AM válido (silencio, banda de música, capilla musical o
    // escolanía), se crea la hermandad pero no el paso (ver cabecera). ORDEN
    // es el de la página de musicofrades, NO el de carrera oficial (ver
    // cabecera del fichero).
    $filas = [
        ['Entrega de Guadalcacín', 'Sabado de Pasion', 4, true],
        ['Cautivo del Portal', 'Sabado de Pasion', 2, true],
        ['Humildad de Barbadillo', 'Sabado de Pasion', 3, true],
        ['Prendimiento de Torrecera', 'Sabado de Pasion', 1, true],
        ['La Borriquita', 'Domingo de Ramos', 1, true],
        ['La Coronación', 'Domingo de Ramos', 5, true],
        ['El Transporte', 'Domingo de Ramos', 4, true],
        ['Pasión', 'Domingo de Ramos', 2, true],
        ['El Perdón', 'Domingo de Ramos', 3, true],
        ['Las Angustias', 'Domingo de Ramos', 6, false],
        ['La Sed', 'Lunes Santo', 1, true],
        ['La Paz de Fátima', 'Lunes Santo', 2, true],
        ['La Candelaria', 'Lunes Santo', 3, true],
        ['Amor y Sacrificio', 'Lunes Santo', 5, false],
        ['Sagrada Cena', 'Lunes Santo', 4, true],
        ['Cristo de la Viga', 'Lunes Santo', 6, false],
        ['Bondad y Misericordia', 'Martes Santo', 1, true],
        ['La Clemencia', 'Martes Santo', 3, true],
        ['Salud de San Rafael', 'Martes Santo', 4, true],
        ['La Defensión', 'Martes Santo', 5, true],
        ['La Salvación', 'Martes Santo', 2, true],
        ['El Amor', 'Martes Santo', 6, true],
        ['Judíos de San Mateo', 'Martes Santo', 7, true],
        ['Soberano Poder', 'Miercoles Santo', 1, true],
        ['El Consuelo', 'Miercoles Santo', 2, true],
        ['Las Tres Caídas', 'Miercoles Santo', 3, true],
        ['La Flagelación', 'Miercoles Santo', 4, true],
        ['Prendimiento', 'Miercoles Santo', 5, true],
        ['Humildad y Paciencia', 'Jueves Santo', 5, false],
        ['Vera-Cruz', 'Jueves Santo', 1, true],
        ['La Redención', 'Jueves Santo', 2, true],
        ['Oración en el Huerto', 'Jueves Santo', 3, true],
        ['La Lanzada', 'Jueves Santo', 4, true],
        ['Mayor Dolor', 'Jueves Santo', 6, true],
        ['El Silencio', 'Noche de Jesus', 1, false],
        ['Cinco Llagas', 'Noche de Jesus', 3, false],
        ['Nazareno', 'Noche de Jesus', 2, true],
        ['Buena Muerte', 'Noche de Jesus', 4, false],
        ['La Yedra', 'Noche de Jesus', 5, true],
        ['Misión', 'Noche de Jesus', 6, true],
        ['El Loreto', 'Viernes Santo', 1, false],
        ['Las Viñas', 'Viernes Santo', 2, true],
        ['El Cristo', 'Viernes Santo', 3, true],
        ['La Soledad', 'Viernes Santo', 4, true],
        ['Sacramental de Santiago', 'Sabado Santo', 1, true],
        ['La Mortaja', 'Sabado Santo', 2, false],
        ['Santa Marta', 'Sabado Santo', 3, true],
        ['La Piedad', 'Sabado Santo', 4, false],
        ['Resucitado', 'Domingo de Resurreccion', 1, true],
    ];

    return array_map(
        static fn(array $f): array => [
            'NOMBRE' => $f[0], 'DIA' => $f[1], 'DIA_ORDEN' => $dias[$f[1]], 'ORDEN' => $f[2], 'TIENE_PASO' => $f[3],
        ],
        $filas
    );
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:poblar_hermandad_paso_jerez' WHERE ID = 1");

    $nomina = nomina();

    $yaHermandad = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $yaPasos = $pdo->prepare('SELECT COUNT(*) FROM paso WHERE ID_HERMANDAD = ?');
    $pendientes = 0;
    foreach ($nomina as $f) {
        $yaHermandad->execute([LOCALIDAD, Slug::slugify($f['NOMBRE'])]);
        $id = $yaHermandad->fetchColumn();
        if ($id === false) {
            $pendientes++;
            continue;
        }
        if ($f['TIENE_PASO']) {
            $yaPasos->execute([(int) $id]);
            if ((int) $yaPasos->fetchColumn() < 1) $pendientes++;
        }
    }
    $yaHermandad->closeCursor();
    $yaPasos->closeCursor(); // si no, VACUUM INTO falla: "SQL statements in progress"
    if ($pendientes === 0) {
        echo "nada que poblar (ya está toda la nómina de Jerez cargada)\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Población abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-poblar-hermandad-jerez.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $insHermandad = $pdo->prepare(
        'INSERT OR IGNORE INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA, FUENTE)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
    );
    $idHermandad = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $insPaso = $pdo->prepare(
        'INSERT OR IGNORE INTO paso (ID_HERMANDAD, NOMBRE, ORDEN, ES_CRUZ_GUIA) VALUES (?, ?, 1, 0)'
    );

    $pdo->beginTransaction();
    $hermandadesCreadas = 0;
    $pasosCreados = 0;
    foreach ($nomina as $f) {
        $slug = Slug::slugify($f['NOMBRE']);
        $insHermandad->execute([LOCALIDAD, $f['NOMBRE'], $slug, $f['DIA'], $f['DIA_ORDEN'], $f['ORDEN'], FUENTE]);
        $hermandadesCreadas += $insHermandad->rowCount();

        if (!$f['TIENE_PASO']) {
            continue;
        }
        $idHermandad->execute([LOCALIDAD, $slug]);
        $idRaw = $idHermandad->fetchColumn();
        $idHermandad->closeCursor();
        if ($idRaw === false) {
            // No debería pasar nunca: si el INSERT de arriba fue ignorado por
            // violar una constraint (p.ej. NOT NULL), esto evita crear un
            // paso huérfano con ID_HERMANDAD=0 en silencio.
            throw new RuntimeException("hermandad \"{$f['NOMBRE']}\" no existe tras el INSERT (constraint violada e ignorada)");
        }
        $id = (int) $idRaw;

        // Placeholder: mismo nombre que la hermandad, pendiente de renombrar
        // con el titular real (ver cabecera).
        $insPaso->execute([$id, $f['NOMBRE']]);
        $pasosCreados += $insPaso->rowCount();
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$hermandadesCreadas hermandades y $pasosCreados pasos creados (de " . count($nomina) . " hermandades en la nómina)\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Población abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
