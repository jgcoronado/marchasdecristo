<?php

declare(strict_types=1);

/*
 * Puebla `hermandad` y `paso` (012_hermandad_paso.sql) con la nómina 2026 de
 * Córdoba, a partir del programa oficial de la Agrupación de Hermandades
 * (scripts/tmp_acompanamientos/cordoba_programa_2026.pdf, parseado por
 * parse_cordoba_programa.py a cordoba_extraido.csv).
 *
 * El nombre corto de cada paso NO sale por regex del CSV: la redacción del
 * programa es demasiado variable ("en el paso de X la BANDA", "tras el paso
 * de X la BANDA"...) y el nombre popular no siempre coincide con el titular
 * literal. Se revisó a mano ficha a ficha (ver
 * scripts/tmp_acompanamientos/cordoba_pasos_propuesta.csv, la propuesta
 * completa con el nivel de confianza de cada una) y el usuario confirmó las
 * de confianza ALTA/MEDIA — son las que están codificadas abajo.
 *
 * Lo que se deja fuera A PROPÓSITO (no es un olvido):
 *   - La O y Lágrimas: sin música detectada en el programa (comparten página
 *     con un maquetado distinto) — duda pendiente en acompanamiento_duda.
 *   - Vía Crucis, Ánimas, Universitaria, Nazareno, Buena Muerte, Sepulcro: sin
 *     CCTT/AM y sin nombre de titular en ninguna fuente disponible.
 *   - Angustias: el único paso que da el programa es "Nuestra Señora de las
 *     Angustias" — confirmado por el usuario que se descarta como palio.
 *   - Soledad y Piedad-con-dos-imágenes: dudas de alcance sin resolver
 *     todavía (¿tiene esta hermandad paso de Cristo propio?).
 *   - La cruz de guía: se ve en el texto de varias hermandades pero como
 *     crédito de banda, no como entidad propia — queda para una pasada
 *     posterior, no se inventa aquí.
 *   - Dolores de Alcolea SÍ se crea como hermandad (confirmado Virgen-only)
 *     pero sin ningún paso de Cristo.
 *
 * Re-ejecutable: `hermandad` tiene UNIQUE(LOCALIDAD,SLUG) y `paso`
 * UNIQUE(ID_HERMANDAD,NOMBRE) — INSERT OR IGNORE no duplica nada.
 *
 * Uso:
 *   php php/app/tools/poblar_hermandad_paso_cordoba.php
 *   DB_PATH=/ruta/a/mdc.db php .../poblar_hermandad_paso_cordoba.php
 */

require __DIR__ . '/_cli.php';

use App\Slug;

[, $db] = cliBootstrap('Población abortada');
require APP_DIR . '/src/Slug.php';

const FUENTE = 'Programa oficial Semana Santa Córdoba 2026 (Agrupación de Hermandades)';

/**
 * @return list<array{NOMBRE:string,DIA:string,DIA_ORDEN:int,ORDEN:int,HORA_SALIDA:?string,PASOS:list<string>}>
 */
function nomina(): array
{
    $dias = [
        'Sabado de Pasion' => 1, 'Domingo de Ramos' => 2, 'Lunes Santo' => 3,
        'Martes Santo' => 4, 'Miercoles Santo' => 5, 'Jueves Santo' => 6,
        'Viernes Santo' => 7, 'Domingo de Resurreccion' => 8,
    ];
    // [NOMBRE, DIA, ORDEN, HORA_SALIDA|null, PASOS[]]
    $filas = [
        ['La O', 'Sabado de Pasion', 1, '18:00', []],
        ['Lágrimas', 'Sabado de Pasion', 2, '19:00', []],
        ['Entrada Triunfal', 'Domingo de Ramos', 1, null, ['El Señor de los Reyes']],
        ['Penas de Santiago', 'Domingo de Ramos', 2, null, ['Cristo de las Penas']],
        ['Huerto', 'Domingo de Ramos', 3, null, ['La Oración en el Huerto', 'El Señor Amarrado a la Columna']],
        ['Rescatado', 'Domingo de Ramos', 4, null, ['El Rescatado']],
        ['Vera Cruz', 'Domingo de Ramos', 5, null, ['Cristo del Amor']],
        ['Esperanza', 'Domingo de Ramos', 6, null, ['Jesús de las Penas']],
        // Dos pasos de Cristo, no uno: la ficha da banda por separado para "el
        // paso de misterio de Nuestro Padre Jesús del Silencio" y para "el
        // paso del Santísimo Cristo del Amor" (confirmado por el usuario tras
        // ver los dos estrenos de la ficha, cada uno mencionando su propio
        // paso: "Dorado completo del paso de misterio de Nuestro Padre Jesús
        // del Silencio" / "Restauración del dorado del paso del Santísimo
        // Cristo del Amor").
        ['Amor', 'Domingo de Ramos', 7, null, ['Jesús del Silencio', 'Cristo del Amor']],
        ['Merced', 'Lunes Santo', 1, null, ['Jesús Humilde']],
        ['Presentación al Pueblo', 'Lunes Santo', 2, null, ['Jesús de los Afligidos']],
        ['Redención', 'Lunes Santo', 3, null, ['La Redención']],
        ['Sentencia', 'Lunes Santo', 4, null, ['La Sentencia']],
        ['Vía Crucis', 'Lunes Santo', 5, null, []],
        ['Ánimas', 'Lunes Santo', 6, null, []],
        ['Agonía', 'Martes Santo', 1, null, ['Cristo de la Agonía']],
        ['Universitaria', 'Martes Santo', 2, null, []],
        ['Sangre', 'Martes Santo', 3, null, ['Jesús de la Sangre']],
        ['Buen Suceso', 'Martes Santo', 4, null, ['Jesús del Buen Suceso']],
        ['Santa Faz', 'Martes Santo', 5, null, ['La Santa Faz']],
        ['Prendimiento', 'Martes Santo', 6, null, ['El Prendimiento']],
        ['Perdón', 'Miercoles Santo', 1, null, ['El Perdón']],
        ['Paz y Esperanza', 'Miercoles Santo', 2, null, ['Jesús de la Humildad y Paciencia']],
        ['Calvario', 'Miercoles Santo', 3, null, ['El Calvario']],
        ['Misericordia', 'Miercoles Santo', 4, null, ['Cristo de la Misericordia']],
        ['Pasión', 'Miercoles Santo', 5, null, ['Jesús de la Pasión']],
        ['Piedad', 'Miercoles Santo', 6, null, ['Cristo de la Piedad']],
        ['Nazareno', 'Jueves Santo', 1, null, []],
        ['Caridad', 'Jueves Santo', 2, null, ['Cristo de la Caridad']],
        ['Caído', 'Jueves Santo', 3, null, ['Jesús Caído']],
        ['Sagrada Cena', 'Jueves Santo', 4, null, ['Jesús de la Fe']],
        ['Angustias', 'Jueves Santo', 5, null, []],
        ['Cristo de Gracia', 'Jueves Santo', 6, null, ['Cristo de Gracia']],
        ['Sepulcro', 'Viernes Santo', 1, null, []],
        ['Soledad', 'Viernes Santo', 2, null, []],
        ['Expiración', 'Viernes Santo', 3, null, ['Cristo de la Expiración']],
        ['Descendimiento', 'Viernes Santo', 4, null, ['Cristo del Descendimiento']],
        ['Conversión', 'Viernes Santo', 5, null, ['Cristo de la Conversión']],
        ['Dolores', 'Viernes Santo', 6, null, ['Cristo de la Clemencia']],
        ['Buena Muerte', 'Viernes Santo', 7, null, []],
        // No hace carrera oficial (barrio de Alcolea): va al final del día,
        // HORA_SALIDA documenta por qué el ORDEN es el que es.
        ['Dolores de Alcolea', 'Viernes Santo', 8, '19:30', []],
        ['Resucitado', 'Domingo de Resurreccion', 1, null, ['El Resucitado']],
    ];

    return array_map(
        static fn(array $f): array => [
            'NOMBRE' => $f[0], 'DIA' => $f[1], 'DIA_ORDEN' => $dias[$f[1]],
            'ORDEN' => $f[2], 'HORA_SALIDA' => $f[3], 'PASOS' => $f[4],
        ],
        $filas
    );
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:poblar_hermandad_paso_cordoba' WHERE ID = 1");

    $nomina = nomina();

    // Comprueba hermandad Y número de pasos: si solo se mirara la hermandad,
    // añadir un paso nuevo a una hermandad ya cargada (como pasó con "Amor",
    // que resultó tener dos Cristos) no se detectaría como pendiente y el
    // script saldría sin insertar nada.
    $yaHermandad = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $yaPasos = $pdo->prepare('SELECT COUNT(*) FROM paso WHERE ID_HERMANDAD = ?');
    $pendientes = 0;
    foreach ($nomina as $f) {
        $yaHermandad->execute(['Cordoba', Slug::slugify($f['NOMBRE'])]);
        $id = $yaHermandad->fetchColumn();
        if ($id === false) {
            $pendientes++;
            continue;
        }
        $yaPasos->execute([(int) $id]);
        if ((int) $yaPasos->fetchColumn() < count($f['PASOS'])) $pendientes++;
    }
    $yaHermandad->closeCursor();
    $yaPasos->closeCursor(); // si no, VACUUM INTO falla: "SQL statements in progress"
    if ($pendientes === 0) {
        echo "nada que poblar (ya está toda la nómina de Córdoba cargada)\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Población abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-poblar-hermandad-cordoba.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $insHermandad = $pdo->prepare(
        'INSERT OR IGNORE INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA, FUENTE)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $idHermandad = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $insPaso = $pdo->prepare(
        'INSERT OR IGNORE INTO paso (ID_HERMANDAD, NOMBRE, ORDEN, ES_CRUZ_GUIA) VALUES (?, ?, ?, 0)'
    );

    $pdo->beginTransaction();
    $hermandadesCreadas = 0;
    $pasosCreados = 0;
    foreach ($nomina as $f) {
        $slug = Slug::slugify($f['NOMBRE']);
        $insHermandad->execute(['Cordoba', $f['NOMBRE'], $slug, $f['DIA'], $f['DIA_ORDEN'], $f['ORDEN'], $f['HORA_SALIDA'], FUENTE]);
        $hermandadesCreadas += $insHermandad->rowCount();

        $idHermandad->execute(['Cordoba', $slug]);
        $id = (int) $idHermandad->fetchColumn();
        $idHermandad->closeCursor();

        foreach ($f['PASOS'] as $i => $nombrePaso) {
            $insPaso->execute([$id, $nombrePaso, $i + 1]);
            $pasosCreados += $insPaso->rowCount();
        }
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$hermandadesCreadas hermandades y $pasosCreados pasos creados (de " . count($nomina) . " hermandades en la nómina)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Población abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
