<?php

declare(strict_types=1);

/*
 * Puebla `hermandad` (012_hermandad_paso.sql) con las hermandades de Sevilla
 * de Viernes de Dolores y Sábado de Pasión, y corrige 4 TITULAR indefinidos
 * o con texto sucio en `contrato` para esas mismas hermandades.
 *
 * Alcance (docs/acompanamientos-nomina-2026.md): solo CCTT/AM y solo pasos
 * de Cristo. De las 16 hermandades de estos 2 días, 3 no tienen ninguna
 * banda CCTT/AM (Pasión y Muerte, Cristo de la Corona, El Santo Ángel:
 * Capilla Musical/Escolanía/Banda de Música únicamente) — se crean en
 * `hermandad` igual que "Dolores de Alcolea" en Córdoba, pero OJO: a
 * diferencia de Córdoba/Jerez, /acompanamientos/sevilla se construye desde
 * `contrato` hacia arriba (Repo::acompanamientosPorLocalidad), no desde el
 * censo de `hermandad` — estas 3 no van a aparecer en la página pública
 * hasta que ese mecanismo cambie.
 *
 * Orden: NO es Carrera Oficial (estos 2 días son vísperas, no hacen C.O.,
 * van solo por su propio barrio — ver reference_fuentes_acompanamientos).
 * Es el orden de enumeración de es.wikipedia.org/wiki/Semana_Santa_en_Sevilla
 * (confirmado con el usuario 2026-09-09), que casó 7/7 en Viernes de Dolores
 * y 9/9 en Sábado de Pasión una vez confirmado que "El Santo Ángel" (nombre
 * de musicofrades.com, sin contratos) es la misma hermandad que la 9ª de
 * Wikipedia, "Cristo de los Desamparados" — no se creó una hermandad nueva
 * ni se renombró la existente.
 *
 * Corrección de TITULAR: La Misión, Dolores de Torreblanca, La Espiga y Paz
 * y Misericordia tenían el paso de Cristo con TITULAR NULL/"Paso"/texto
 * sucio en varias filas históricas de `recuperar_historico_acompanamientos.php`
 * (FUENTE=elforocofrade.es). Se canoniza TODO el histórico de esas 4
 * hermandades a "Paso de Misterio" (confirmado contra el ETIQUETA_RAW de
 * musicofrades_extraido.csv) — NUNCA solo el año en curso: arreglar solo
 * 2026 partía en dos un paso realmente continuo (misma banda, años
 * consecutivos) en "Sin especificar" + "Paso de Misterio", justo el bug que
 * fusionar_titular_duplicado_sevilla_*.php ya había corregido en otras
 * hermandades.
 *
 * Conflicto de datos encontrado y DEJADO SIN TOCAR (decisión del usuario,
 * 2026-09-09): Dolores de Torreblanca tiene 2 bandas distintas para el
 * mismo año en 1998 y en 2003 (una fila lleva la nota "corregido en post
 * #740 [de elforocofrade.es], sustituye a la versión del post original" —
 * sugiere que la otra es la versión vieja sin borrar, pero hace falta el
 * foro original para confirmarlo). Este script NO fusiona esas filas.
 *
 * Re-ejecutable: `hermandad` tiene UNIQUE(LOCALIDAD,SLUG) — INSERT OR
 * IGNORE no duplica nada. La corrección de TITULAR solo toca filas que
 * todavía tengan el valor viejo conocido, así que tampoco pisa una edición
 * manual posterior.
 *
 * Uso:
 *   php php/app/tools/poblar_hermandad_sevilla_visperas.php
 *   DB_PATH=/ruta/a/mdc.db php .../poblar_hermandad_sevilla_visperas.php
 */

require __DIR__ . '/_cli.php';

use App\Slug;

[, $db] = cliBootstrap('Población abortada');
require APP_DIR . '/src/Slug.php';

const LOCALIDAD = 'Sevilla';
const FUENTE = 'es.wikipedia.org/wiki/Semana_Santa_en_Sevilla (orden de enumeracion del articulo; visperas sin Carrera Oficial)';

/**
 * [NOMBRE, DIA, DIA_ORDEN, ORDEN] — ORDEN es la posición en la enumeración
 * de Wikipedia dentro de cada día, NO Carrera Oficial (ver cabecera).
 * @return list<array{NOMBRE:string,DIA:string,DIA_ORDEN:int,ORDEN:int}>
 */
function nomina(): array
{
    $filas = [
        ['Pino Montano', 'Viernes de Dolores', 1, 1],
        ['Pasión y Muerte', 'Viernes de Dolores', 1, 2],
        ['Cristo de la Corona', 'Viernes de Dolores', 1, 3],
        ['La Misión', 'Viernes de Dolores', 1, 4],
        ['Dulce Nombre de Bellavista', 'Viernes de Dolores', 1, 5],
        ['Bendición y Esperanza', 'Viernes de Dolores', 1, 6],
        ['Paz y Misericordia', 'Viernes de Dolores', 1, 7],
        ['Dolores de Torreblanca', 'Sabado de Pasion', 2, 1],
        ['Divino Perdón de Alcosa', 'Sabado de Pasion', 2, 2],
        ['La Milagrosa', 'Sabado de Pasion', 2, 3],
        ['San José Obrero', 'Sabado de Pasion', 2, 4],
        ['Padre Pío', 'Sabado de Pasion', 2, 5],
        ['San Jerónimo', 'Sabado de Pasion', 2, 6],
        ['Las Maravillas', 'Sabado de Pasion', 2, 7],
        ['La Espiga', 'Sabado de Pasion', 2, 8],
        ['El Santo Ángel', 'Sabado de Pasion', 2, 9],
    ];

    return array_map(
        static fn(array $f): array => ['NOMBRE' => $f[0], 'DIA' => $f[1], 'DIA_ORDEN' => $f[2], 'ORDEN' => $f[3]],
        $filas
    );
}

/**
 * [HERMANDAD_SLUG, TITULAR viejo conocido] -> se corrige a "Paso de Misterio".
 * @return list<array{0:string,1:?string}>
 */
function titularesRotos(): array
{
    return [
        ['la-mision', null],
        ['paz-y-misericordia', 'Paso'],
        ['la-espiga', 'Paso'],
        ['dolores-de-torreblanca', 'Paso Cristo (corregido en post #740, sustituye a la versión del post original)'],
    ];
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:poblar_hermandad_sevilla_visperas' WHERE ID = 1");

    $nomina = nomina();
    $roto = titularesRotos();

    $yaHermandad = $pdo->prepare('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?');
    $pendientesHermandad = 0;
    foreach ($nomina as $f) {
        $yaHermandad->execute([LOCALIDAD, Slug::slugify($f['NOMBRE'])]);
        if ($yaHermandad->fetchColumn() === false) {
            $pendientesHermandad++;
        }
    }
    $yaHermandad->closeCursor();

    $pendientesTitular = 0;
    $checkTitular = $pdo->prepare('SELECT COUNT(*) FROM contrato WHERE HERMANDAD_SLUG = ? AND TITULAR IS ?');
    foreach ($roto as [$slug, $viejo]) {
        $checkTitular->bindValue(1, $slug);
        $checkTitular->bindValue(2, $viejo);
        $checkTitular->execute();
        $pendientesTitular += (int) $checkTitular->fetchColumn();
    }
    $checkTitular->closeCursor();

    if ($pendientesHermandad === 0 && $pendientesTitular === 0) {
        echo "nada que poblar ni corregir (ya aplicado)\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Población abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-poblar-hermandad-sevilla-visperas.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $insHermandad = $pdo->prepare(
        'INSERT OR IGNORE INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, HORA_SALIDA, FUENTE)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
    );
    $fixTitular = $pdo->prepare(
        'UPDATE contrato SET TITULAR = ? WHERE HERMANDAD_SLUG = ? AND TITULAR IS ?'
    );

    $pdo->beginTransaction();
    $hermandadesCreadas = 0;
    foreach ($nomina as $f) {
        $slug = Slug::slugify($f['NOMBRE']);
        $insHermandad->execute([LOCALIDAD, $f['NOMBRE'], $slug, $f['DIA'], $f['DIA_ORDEN'], $f['ORDEN'], FUENTE]);
        $hermandadesCreadas += $insHermandad->rowCount();
    }
    $filasCorregidas = 0;
    foreach ($roto as [$slug, $viejo]) {
        $fixTitular->bindValue(1, 'Paso de Misterio');
        $fixTitular->bindValue(2, $slug);
        $fixTitular->bindValue(3, $viejo);
        $fixTitular->execute();
        $filasCorregidas += $fixTitular->rowCount();
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo "$hermandadesCreadas hermandades creadas (de " . count($nomina) . " en la nómina), "
        . "$filasCorregidas filas de TITULAR corregidas a \"Paso de Misterio\"\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Población abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
