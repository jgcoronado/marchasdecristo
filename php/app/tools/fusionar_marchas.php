<?php

declare(strict_types=1);

/*
 * Fusiona marchas duplicadas: para cada par CONSERVAR:BORRAR, todo lo que
 * cuelga de la marcha que sobra pasa a la que se conserva y luego se borra.
 *
 *   · campos de `marcha`: la conservada manda; solo se rellenan sus huecos
 *     (NULL, cadena vacía, o DURACION_SEG = 0) con lo que traiga la otra.
 *     DATOS_INT no se pisa: la nota de la borrada se añade detrás, con su id.
 *   · marcha_autor: se añaden los autores que falten.
 *   · disco_marcha: las pistas pasan a la conservada (si ese disco ya la tiene,
 *     la pista sobrante se borra).
 *   · enlace_streaming / enlace_candidato (TIPO_ENT='marcha'): pasan a la
 *     conservada salvo que ya tenga ese mismo servicio+versión (o URL).
 *   · ingest_candidato: MARCHA_CREADA y MATCH_MARCHA_ID apuntan a la conservada.
 *
 * Todo en una transacción por par; cambio_log registra cada paso con el actor
 * cli:fusionar_marchas. Re-ejecutable: un par cuya marcha a borrar ya no existe
 * se salta.
 *
 * Uso:
 *   php php/app/tools/fusionar_marchas.php --pares=3996:5539,2147:5545 [--dry-run]
 *   DB_PATH=/ruta/a/mdc.db php php/app/tools/fusionar_marchas.php --pares=...
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Fusión abortada');

$opt = getopt('', ['pares:', 'dry-run']);
$dryRun = isset($opt['dry-run']);
$pares = [];
foreach (array_filter(explode(',', (string) ($opt['pares'] ?? ''))) as $par) {
    if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $par, $m) !== 1 || $m[1] === $m[2]) {
        fwrite(STDERR, "Par no válido: «{$par}» (formato CONSERVAR:BORRAR)\n");
        exit(2);
    }
    $pares[] = [(int) $m[1], (int) $m[2]];
}
if ($pares === []) {
    fwrite(STDERR, "Falta --pares=CONSERVAR:BORRAR[,...]\n");
    exit(2);
}

const CAMPOS = ['TITULO', 'FECHA', 'DETALLES_MARCHA', 'DEDICATORIA', 'LOCALIDAD', 'PROVINCIA', 'TIPO',
    'BANDA_ESTRENO', 'DURACION_SEG', 'AUDIO', 'ESTILO'];

function vacio(string $campo, mixed $v): bool
{
    if ($v === null || trim((string) $v) === '') return true;
    return $campo === 'DURACION_SEG' && (int) $v === 0;
}

$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("UPDATE log_actor SET ACTOR = 'cli:fusionar_marchas' WHERE ID = 1");

$marcha = $pdo->prepare('SELECT * FROM marcha WHERE ID_MARCHA = ?');

foreach ($pares as [$keep, $drop]) {
    $marcha->execute([$keep]);
    $k = $marcha->fetch();
    $marcha->execute([$drop]);
    $d = $marcha->fetch();
    if ($d === false) { echo "· $keep ← $drop: la $drop ya no existe, nada que hacer\n"; continue; }
    if ($k === false) { fwrite(STDERR, "✗ $keep ← $drop: la marcha a conservar $keep no existe\n"); exit(1); }

    $log = [];
    $pdo->beginTransaction();
    try {
        // 1. Campos: solo huecos de la conservada.
        $sets = [];
        foreach (CAMPOS as $c) {
            if (vacio($c, $k[$c]) && !vacio($c, $d[$c])) $sets[$c] = $d[$c];
        }
        if (!vacio('DATOS_INT', $d['DATOS_INT']) && !str_contains((string) $k['DATOS_INT'], (string) $d['DATOS_INT'])) {
            $nota = "Fusionada con la #$drop: " . $d['DATOS_INT'];
            $sets['DATOS_INT'] = vacio('DATOS_INT', $k['DATOS_INT']) ? $nota : $k['DATOS_INT'] . "\n" . $nota;
        }
        if ($sets !== []) {
            $pdo->prepare('UPDATE marcha SET ' . implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($sets)))
                . ' WHERE ID_MARCHA = ?')->execute([...array_values($sets), $keep]);
            $log[] = 'campos rellenados: ' . implode(', ', array_map(static fn(string $c, mixed $v): string => "$c=" . mb_strimwidth((string) $v, 0, 60, '…'), array_keys($sets), $sets));
        }

        // 2. Autores que falten.
        $n = $pdo->prepare('INSERT INTO marcha_autor (ID_MARCHA, ID_AUTOR)
                            SELECT ?, ID_AUTOR FROM marcha_autor WHERE ID_MARCHA = ?
                              AND ID_AUTOR NOT IN (SELECT ID_AUTOR FROM marcha_autor WHERE ID_MARCHA = ?)');
        $n->execute([$keep, $drop, $keep]);
        if ($n->rowCount() > 0) $log[] = $n->rowCount() . ' autor(es) añadido(s)';
        $pdo->prepare('DELETE FROM marcha_autor WHERE ID_MARCHA = ?')->execute([$drop]);

        // 3. Pistas de disco.
        $q = $pdo->prepare('DELETE FROM disco_marcha WHERE IDMARCHA = ? AND ID_DISCO IN (SELECT ID_DISCO FROM disco_marcha WHERE IDMARCHA = ?)');
        $q->execute([$drop, $keep]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' pista(s) repetida(s) en el mismo disco, borrada(s)';
        $q = $pdo->prepare('UPDATE disco_marcha SET IDMARCHA = ? WHERE IDMARCHA = ?');
        $q->execute([$keep, $drop]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' pista(s) de disco movida(s)';

        // 4. Enlaces publicados y en curación.
        $q = $pdo->prepare("DELETE FROM enlace_streaming WHERE TIPO_ENT = 'marcha' AND ID_ENT = ?
                              AND EXISTS (SELECT 1 FROM enlace_streaming e2 WHERE e2.TIPO_ENT = 'marcha' AND e2.ID_ENT = ?
                                            AND e2.SERVICIO = enlace_streaming.SERVICIO AND e2.VERSION = enlace_streaming.VERSION)");
        $q->execute([$drop, $keep]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' enlace(s) de streaming ya cubierto(s), borrado(s)';
        $q = $pdo->prepare("UPDATE enlace_streaming SET ID_ENT = ? WHERE TIPO_ENT = 'marcha' AND ID_ENT = ?");
        $q->execute([$keep, $drop]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' enlace(s) de streaming movido(s)';
        $q = $pdo->prepare("UPDATE enlace_candidato SET ID_ENT = ? WHERE TIPO_ENT = 'marcha' AND ID_ENT = ?");
        $q->execute([$keep, $drop]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' enlace(s) en curación movido(s)';

        // 5. Ingesta.
        $q = $pdo->prepare('UPDATE ingest_candidato SET MARCHA_CREADA = ? WHERE MARCHA_CREADA = ?');
        $q->execute([$keep, $drop]);
        if ($q->rowCount() > 0) $log[] = $q->rowCount() . ' candidato(s) de ingesta reapuntado(s)';
        $pdo->prepare('UPDATE ingest_candidato SET MATCH_MARCHA_ID = ? WHERE MATCH_MARCHA_ID = ?')->execute([$keep, $drop]);

        $pdo->prepare('DELETE FROM marcha WHERE ID_MARCHA = ?')->execute([$drop]);

        $dryRun ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "✗ $keep ← $drop: " . $e->getMessage() . "\n");
        exit(1);
    }
    printf("%s %d «%s» ← %d «%s»%s\n", $dryRun ? '[simulado]' : '✓', $keep, $k['TITULO'], $drop, $d['TITULO'],
        $log !== [] ? "\n    " . implode("\n    ", $log) : '');
}
