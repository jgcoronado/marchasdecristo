<?php

declare(strict_types=1);

/*
 * Depuración de `banda` revisada con el usuario el 2026-09-24 (sigue a
 * fusionar_bandas_cautivo_veracruz.php, mismo criterio):
 *
 *  1. FUSIONES: bandas duplicadas que creó la carga de acompañamientos (la
 *     misma banda real, misma localidad, nombre con otro formato). Se queda
 *     el ID más bajo. Por cada contrato de la duplicada: si la canónica ya
 *     tiene contrato en la misma localidad+hermandad+año, es el mismo
 *     acompañamiento dos veces y se borra (con sus satélites); si no, se
 *     reasigna ID_BANDA conservando ID_CONTRATO. Si la duplicada tuviera algo
 *     más que contratos (marchas, discos, canales, enlaces, relaciones), se
 *     aborta sin tocar nada.
 *  2. RENOMBRES: la localidad fuera del nombre. La web ya pinta
 *     "NOMBRE_BREVE (LOCALIDAD)", así que un nombre corto con "(Melilla)" salía
 *     "(Melilla) (Melilla)"; y los nombres completos de la carga acababan en
 *     "de <Localidad>", al revés que el resto de fichas. Excepciones que NO se
 *     tocan (la localidad es parte del nombre oficial): #21 San Juan de
 *     Aznalfarache, #221 AM Córdoba, #245 Punta Umbría, #248 Ciudad de Toro,
 *     #355 Aracena, #364 La Victoria, #366 Dos Torres, #372 (completo) La
 *     Fusión de Marmolejo-Lopera. Solo se renombra si el valor actual es
 *     exactamente el esperado (si en otro host difiere, se informa y se omite).
 *  3. LINAJE (banda_relacion), confirmado por el usuario: fusiones en Huelva
 *     (#210 Expiración + #209 Salud -> #286) y Sanlúcar la Mayor (#268 Cautivo
 *     -> #13 Cautivo y Santiago), sin año confirmado; y #243 BCT JHS es la
 *     juvenil de #242 AM JHS (León). Mismo sentido que las existentes:
 *     fusión origen->resultante, juvenil grande->juvenil.
 *
 * Re-ejecutable: cada paso comprueba si ya está hecho.
 *
 * Uso:
 *   php php/app/tools/depurar_bandas_2026_09_24.php --dry-run
 *   php php/app/tools/depurar_bandas_2026_09_24.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Depuración abortada');

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

/** @var array<int,int> ID_BANDA duplicada => ID_BANDA que se queda */
const FUSIONES = [331 => 37, 344 => 69, 327 => 152, 332 => 166, 334 => 240, 300 => 88, 343 => 104];

/** Referencias a banda que NO sean contratos: si la duplicada tiene alguna, se aborta. */
const OTRAS_REFERENCIAS = [
    'marcha' => 'BANDA_ESTRENO = ?',
    'disco' => 'BANDADISCO = ?',
    'disco_marcha' => 'DM_BANDA = ?',
    'ingest_canal' => 'ID_BANDA = ?',
    'ingest_candidato' => '(ID_BANDA = ? OR P_BANDA_ESTRENO = ?)',
    'ingest_veto' => 'ID_BANDA = ?',
    'banda_relacion' => '(ID_ORIGEN = ? OR ID_DESTINO = ?)',
    'enlace_streaming' => "TIPO_ENT = 'banda' AND ID_ENT = ?",
    'enlace_candidato' => "TIPO_ENT = 'banda' AND ID_ENT = ?",
];

/** @var array<int,array{0:string,1:string,2:string,3:string}> ID => [breve actual, breve nuevo, completo actual, completo nuevo] */
const RENOMBRES = [
    273 => ['AM Juvenil Rocío', 'AM Juvenil Rocío', 'Agrupación Musical Juvenil María Santísima del Rocío de Sevilla', 'Agrupación Musical Juvenil María Santísima del Rocío'],
    274 => ['AM Juvenil Sagrada Presentación', 'AM Juvenil Sagrada Presentación', 'Agrupación Musical Juvenil Sagrada Presentación de Sevilla', 'Agrupación Musical Juvenil Sagrada Presentación'],
    275 => ['AM Juvenil Santa María Magdalena', 'AM Juvenil Santa María Magdalena', 'Agrupación Musical Juvenil Santa María Magdalena de Arahal', 'Agrupación Musical Juvenil Santa María Magdalena'],
    276 => ['AM Juvenil Virgen de los Reyes', 'AM Juvenil Virgen de los Reyes', 'Agrupación Musical Juvenil Virgen de los Reyes de Sevilla', 'Agrupación Musical Juvenil Virgen de los Reyes'],
    277 => ['AM Pasión y Resurrección', 'AM Pasión y Resurrección', 'Agrupación Musical Pasión y Resurrección de Sevilla', 'Agrupación Musical Pasión y Resurrección'],
    278 => ['BCT Jesús Nazareno (Sevilla)', 'BCT Jesús Nazareno', 'Banda de Cornetas y Tambores Jesús Nazareno de Sevilla', 'Banda de Cornetas y Tambores Jesús Nazareno'],
    279 => ['BCT Juvenil Centuria Romana Macarena', 'BCT Juvenil Centuria Romana Macarena', 'Banda de Cornetas y Tambores Juvenil Centuria Romana Macarena de Sevilla', 'Banda de Cornetas y Tambores Juvenil Centuria Romana Macarena'],
    280 => ['BCT Prendimiento', 'BCT Prendimiento', 'Banda de Cornetas y Tambores Nuestro Padre Jesús en su Prendimiento de Dos Hermanas', 'Banda de Cornetas y Tambores Nuestro Padre Jesús en su Prendimiento'],
    281 => ['BCT Sagrado Corazón (Marchena)', 'BCT Sagrado Corazón', 'Banda de Cornetas y Tambores Sagrado Corazón de Jesús de Marchena', 'Banda de Cornetas y Tambores Sagrado Corazón de Jesús'],
    286 => ['BCT Santísimo Cristo de la Expiración Salud y Esperanza', 'BCT Santísimo Cristo de la Expiración Salud y Esperanza', 'Banda de Cornetas y Tambores Santísimo Cristo de la Expiración – Salud y Esperanza – de Huelva', 'Banda de Cornetas y Tambores Santísimo Cristo de la Expiración – Salud y Esperanza'],
    299 => ['BCT Santa María de la Granada', 'BCT Santa María de la Granada', 'Banda de Cornetas y Tambores Santa María de la Granada de Moguer', 'Banda de Cornetas y Tambores Santa María de la Granada'],
    303 => ['AM Nuestro Padre Jesús de la Salud', 'AM Nuestro Padre Jesús de la Salud', 'Agrupación Musical Nuestro Padre Jesús de la Salud de Cádiz', 'Agrupación Musical Nuestro Padre Jesús de la Salud'],
    310 => ['AM Nuestro Padre Jesús Nazareno', 'AM Nuestro Padre Jesús Nazareno', 'Agrupación Musical Nuestro Padre Jesús Nazareno de Campo de Criptana', 'Agrupación Musical Nuestro Padre Jesús Nazareno'],
    314 => ['BCT Nuestro Padre Jesús de la Columna y María Santísima de la O Los Gitanos', 'BCT Nuestro Padre Jesús de la Columna y María Santísima de la O Los Gitanos', 'Banda de Cornetas y Tambores Nuestro Padre Jesús de la Columna y María Santísima de la O – Los Gitanos – de Málaga', 'Banda de Cornetas y Tambores Nuestro Padre Jesús de la Columna y María Santísima de la O – Los Gitanos'],
    316 => ['BCT Santísima Trinidad', 'BCT Santísima Trinidad', 'Banda de Cornetas y Tambores Santísima Trinidad de Palencia', 'Banda de Cornetas y Tambores Santísima Trinidad'],
    321 => ['BCT María Santísima de la Victoria', 'BCT María Santísima de la Victoria', 'Banda de Cornetas y Tambores María Santísima de la Victoria de Granada', 'Banda de Cornetas y Tambores María Santísima de la Victoria'],
    325 => ['AM Nuestro Padre Jesús del Rescate', 'AM Nuestro Padre Jesús del Rescate', 'Agrupación Musical Nuestro Padre Jesús del Rescate de Granada', 'Agrupación Musical Nuestro Padre Jesús del Rescate'],
    335 => ['BCT Nuestro Padre Jesús Nazareno (Almogía)', 'BCT Nuestro Padre Jesús Nazareno', 'Banda de Cornetas y Tambores Nuestro Padre Jesús Nazareno de Almogía', 'Banda de Cornetas y Tambores Nuestro Padre Jesús Nazareno'],
    338 => ['AM San Lorenzo Mártir', 'AM San Lorenzo Mártir', 'Agrupación Musical San Lorenzo Mártir de Málaga', 'Agrupación Musical San Lorenzo Mártir'],
    339 => ['AM Reales Cofradías Fusionadas de San Juan', 'AM Reales Cofradías Fusionadas de San Juan', 'Agrupación Musical de las Reales Cofradías Fusionadas de San Juan de Málaga', 'Agrupación Musical de las Reales Cofradías Fusionadas de San Juan'],
    346 => ['BCT Flor entre Espinas', 'BCT Flor entre Espinas', 'Banda de Cornetas y Tambores Flor entre Espinas de Loja', 'Banda de Cornetas y Tambores Flor entre Espinas'],
    347 => ['BCT Dolores Coronada', 'BCT Dolores Coronada', 'Banda de Cornetas y Tambores Dolores Coronada de Álora', 'Banda de Cornetas y Tambores Dolores Coronada'],
    348 => ['BCT Suspiros de Pasión', 'BCT Suspiros de Pasión', 'Banda de Cornetas y Tambores Suspiros de Pasión de Alameda', 'Banda de Cornetas y Tambores Suspiros de Pasión'],
    350 => ['BCT Vera-Cruz (Almogía)', 'BCT Vera-Cruz', 'Banda de Cornetas y Tambores Vera-Cruz de Almogía', 'Banda de Cornetas y Tambores Vera-Cruz'],
    353 => ['BCT San Juan Evangelista (Las Cabezas)', 'BCT San Juan Evangelista', 'Banda de Cornetas y Tambores San Juan Evangelista de Las Cabezas de San Juan', 'Banda de Cornetas y Tambores San Juan Evangelista'],
    356 => ['BCT Humildad (Huelva)', 'BCT Humildad', 'Banda de Cornetas y Tambores Nuestro Padre Jesús de la Humildad', 'Banda de Cornetas y Tambores Nuestro Padre Jesús de la Humildad'],
    357 => ['AM Sagrada Resurrección (Chiclana)', 'AM Sagrada Resurrección', 'Agrupación Musical Sagrada Resurrección de Chiclana', 'Agrupación Musical Sagrada Resurrección de Chiclana'],
    358 => ['AM Esperanza (Olvera)', 'AM Esperanza', 'Agrupación Musical Esperanza de Olvera', 'Agrupación Musical Esperanza'],
    359 => ['BCT Cristo de la Expiración (Granada)', 'BCT Cristo de la Expiración', 'Banda de Cornetas y Tambores del Cristo de la Expiración', 'Banda de Cornetas y Tambores del Cristo de la Expiración'],
    361 => ['BCT Redención (Benalmádena)', 'BCT Redención', 'Banda de Cornetas y Tambores Santísimo Cristo de la Redención', 'Banda de Cornetas y Tambores Santísimo Cristo de la Redención'],
    365 => ['AM Sagrada Cena (Córdoba)', 'AM Sagrada Cena', 'Agrupación Musical Nuestro Padre Jesús de la Fe en su Sagrada Cena', 'Agrupación Musical Nuestro Padre Jesús de la Fe en su Sagrada Cena'],
    367 => ['BCT Maestro Valero (Aguilar de la Frontera)', 'BCT Maestro Valero', 'Banda de Cornetas y Tambores Maestro Valero', 'Banda de Cornetas y Tambores Maestro Valero'],
    368 => ['BCT Dolores del Rosario (Baeza)', 'BCT Dolores del Rosario', 'Banda de Cornetas y Tambores de Nuestra Señora de los Dolores del Rosario', 'Banda de Cornetas y Tambores de Nuestra Señora de los Dolores del Rosario'],
    369 => ['AM Niño Perdido (Estepa)', 'AM Niño Perdido', 'Agrupación Musical Niño perdido', 'Agrupación Musical Niño perdido'],
    370 => ['BCT Expiración (Quesada)', 'BCT Expiración', 'Banda de Cornetas y Tambores Santísimo Cristo de la Expiración de Quesada', 'Banda de Cornetas y Tambores Santísimo Cristo de la Expiración'],
    371 => ['BCT Tercio Gran Capitán (Melilla)', 'BCT Tercio Gran Capitán', 'Banda de Cornetas y Tambores del Tercio Gran Capitán 1º de la Legión', 'Banda de Cornetas y Tambores del Tercio Gran Capitán 1º de la Legión'],
    372 => ['BCT La Fusión (Marmolejo-Lopera)', 'BCT La Fusión', 'Banda de Cornetas y Tambores La Fusión de Marmolejo-Lopera', 'Banda de Cornetas y Tambores La Fusión de Marmolejo-Lopera'],
    373 => ['AM Cristo del Calvario (Ubrique)', 'AM Cristo del Calvario', 'Agrupación Musical Cristo del Calvario', 'Agrupación Musical Cristo del Calvario'],
    374 => ['BCT Nazareno (Utrera)', 'BCT Nazareno', 'Banda de Cornetas y Tambores Nazareno', 'Banda de Cornetas y Tambores Nazareno'],
    375 => ['AM Maestro Agripino Lozano (San Fernando)', 'AM Maestro Agripino Lozano', 'Agrupación Musical Maestro Agripino Lozano', 'Agrupación Musical Maestro Agripino Lozano'],
];

const NOTA_FUSION = 'año de fusión desconocido; confirmado por el usuario 2026-09-24 al revisar bandas duplicadas.';
/** @var list<array{0:int,1:int,2:string,3:?string}> [origen, destino, tipo, nota] */
const RELACIONES = [
    [210, 286, 'fusion', NOTA_FUSION],
    [209, 286, 'fusion', NOTA_FUSION],
    [268, 13, 'fusion', NOTA_FUSION . ' Por los contratos (El Carmen: #268 hasta 2006, #13 desde 2007) la fusión con Santiago de Aznalcázar sería hacia 2007.'],
    [242, 243, 'juvenil', 'Confirmado por el usuario 2026-09-24: la BCT JHS es la juvenil de la AM JHS. Ojo: #243 tiene FECHA_EXT=2005 en `banda`, revisar.'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:depurar_bandas_2026_09_24' WHERE ID = 1");
    $existe = static function (int $id) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM banda WHERE ID_BANDA = ?');
        $st->execute([$id]);
        $ok = $st->fetchColumn() !== false;
        $st->closeCursor();
        return $ok;
    };

    // ── 1. Fusiones ──────────────────────────────────────────────────────
    echo "== Fusiones\n";
    $reasignar = [];
    $borrarContrato = [];
    $bandasABorrar = [];
    $choque = $pdo->prepare(
        "SELECT c.ID_CONTRATO FROM contrato c
         LEFT JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
         WHERE c.ID_BANDA = ? AND c.HERMANDAD_SLUG = ? AND c.ANIO = ? AND IFNULL(cl.LOCALIDAD, '') = IFNULL(?, '')
         LIMIT 1"
    );
    foreach (FUSIONES as $dup => $canon) {
        if (!$existe($dup)) { echo "  #$dup ya no existe, se omite\n"; continue; }
        if (!$existe($canon)) throw new RuntimeException("la banda canónica #$canon no existe");
        foreach (OTRAS_REFERENCIAS as $tabla => $where) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $where");
            $st->execute(array_fill(0, substr_count($where, '?'), $dup));
            $hay = (int) $st->fetchColumn();
            $st->closeCursor();
            if ($hay > 0) throw new RuntimeException("#$dup tiene filas en $tabla: revisar a mano antes de fusionar");
        }
        $st = $pdo->prepare(
            'SELECT c.ID_CONTRATO, c.HERMANDAD_SLUG, c.ANIO, cl.LOCALIDAD
             FROM contrato c LEFT JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO WHERE c.ID_BANDA = ?'
        );
        $st->execute([$dup]);
        $nR = 0; $nB = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $choque->execute([$canon, $f['HERMANDAD_SLUG'], $f['ANIO'], $f['LOCALIDAD']]);
            $otro = $choque->fetchColumn();
            $choque->closeCursor();
            if ($otro !== false) {
                $borrarContrato[] = (int) $f['ID_CONTRATO'];
                echo "  duplicado: contrato #{$f['ID_CONTRATO']} ({$f['LOCALIDAD']}, {$f['HERMANDAD_SLUG']}, {$f['ANIO']}) = #$otro -> se borra\n";
                $nB++;
            } else {
                $reasignar[(int) $f['ID_CONTRATO']] = $canon;
                $nR++;
            }
        }
        $bandasABorrar[] = $dup;
        echo "  #$dup -> #$canon: $nR contrato(s) reasignados, $nB duplicado(s) borrados\n";
    }

    // ── 2. Renombres ─────────────────────────────────────────────────────
    echo "== Renombres\n";
    $renombrar = [];
    $lee = $pdo->prepare('SELECT NOMBRE_BREVE, NOMBRE_COMPLETO FROM banda WHERE ID_BANDA = ?');
    foreach (RENOMBRES as $id => [$b0, $b1, $c0, $c1]) {
        $lee->execute([$id]);
        $act = $lee->fetch(PDO::FETCH_ASSOC);
        $lee->closeCursor();
        if ($act === false) { echo "  #$id no existe, se omite\n"; continue; }
        if ($act['NOMBRE_BREVE'] === $b1 && $act['NOMBRE_COMPLETO'] === $c1) { echo "  #$id ya renombrada\n"; continue; }
        if ($act['NOMBRE_BREVE'] !== $b0 || $act['NOMBRE_COMPLETO'] !== $c0) {
            echo "  #$id NO coincide con lo esperado (\"{$act['NOMBRE_BREVE']}\" / \"{$act['NOMBRE_COMPLETO']}\"), se omite\n";
            continue;
        }
        $renombrar[$id] = [$b1, $c1];
        echo "  #$id " . ($b0 !== $b1 ? "\"$b0\" -> \"$b1\" " : '') . ($c0 !== $c1 ? "| \"$c0\" -> \"$c1\"" : '') . "\n";
    }

    // ── 3. Linaje ────────────────────────────────────────────────────────
    echo "== Linaje\n";
    $relacionar = [];
    $yaRel = $pdo->prepare('SELECT 1 FROM banda_relacion WHERE ID_ORIGEN = ? AND ID_DESTINO = ? AND TIPO = ?');
    foreach (RELACIONES as [$o, $dst, $tipo, $nota]) {
        if (!$existe($o) || !$existe($dst)) { echo "  #$o/#$dst no existe, se omite\n"; continue; }
        $yaRel->execute([$o, $dst, $tipo]);
        $ya = $yaRel->fetchColumn() !== false;
        $yaRel->closeCursor();
        if ($ya) { echo "  #$o -> #$dst ($tipo) ya existe\n"; continue; }
        $relacionar[] = [$o, $dst, $tipo, $nota];
        echo "  #$o -> #$dst ($tipo)\n";
    }

    if ($dryRun) { echo "--dry-run: no se ha tocado la BD\n"; exit(0); }
    if ($bandasABorrar === [] && $renombrar === [] && $relacionar === []) { echo "nada que hacer\n"; exit(0); }

    $st = $choque = $lee = $yaRel = null; // VACUUM INTO falla con sentencias abiertas
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException("no se pudo crear $backupDir");
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-depurar-bandas-2026-09-24.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    if ($borrarContrato !== []) {
        $in = implode(',', array_fill(0, count($borrarContrato), '?'));
        foreach (['contrato_localidad', 'contrato_paso', 'contrato_tramo', 'contrato'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE ID_CONTRATO IN ($in)")->execute($borrarContrato);
        }
    }
    $upd = $pdo->prepare('UPDATE contrato SET ID_BANDA = ? WHERE ID_CONTRATO = ?');
    foreach ($reasignar as $idContrato => $canon) $upd->execute([$canon, $idContrato]);
    if ($bandasABorrar !== []) {
        $inB = implode(',', array_fill(0, count($bandasABorrar), '?'));
        $pdo->prepare("DELETE FROM banda WHERE ID_BANDA IN ($inB)")->execute($bandasABorrar);
    }
    $ren = $pdo->prepare('UPDATE banda SET NOMBRE_BREVE = ?, NOMBRE_COMPLETO = ? WHERE ID_BANDA = ?');
    foreach ($renombrar as $id => [$b1, $c1]) $ren->execute([$b1, $c1, $id]);
    $rel = $pdo->prepare('INSERT INTO banda_relacion (ID_ORIGEN, ID_DESTINO, TIPO, NOTA) VALUES (?, ?, ?, ?)');
    foreach ($relacionar as $r) $rel->execute($r);
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    echo count($bandasABorrar) . ' banda(s) fusionadas, ' . count($renombrar) . ' renombradas, ' . count($relacionar) . " relaciones nuevas\n";
    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Depuración abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
