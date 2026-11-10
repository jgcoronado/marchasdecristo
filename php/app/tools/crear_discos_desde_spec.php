<?php

declare(strict_types=1);

/*
 * Crea discos y enlaza sus pistas a marchas que YA existen, a partir de una
 * especificación JSON revisada a mano (no descubre ni crea marchas):
 *
 *   [{ "nombre": "Rosario", "anio": 2016, "banda": 4, "id_disco": null,
 *      "notas": "La pista 1 es una locución.",
 *      "pistas": [{ "n": 2, "marcha": 5538 }, …] }, …]
 *
 * · id_disco null → se crea el disco (si ya hay uno de esa banda con el mismo
 *   nombre, se reutiliza en vez de duplicarlo).
 * · id_disco N → solo se añaden a ese disco las pistas que le falten.
 * · Una pista se salta si esa marcha ya está en el disco o el número ya está
 *   ocupado (mismas reglas que AdminRepo::addPista); el número ocupado por
 *   OTRA marcha se informa como conflicto, no se pisa.
 *
 * Todo en una transacción; cambio_log queda con el actor cli:crear_discos.
 *
 * Uso:
 *   php php/app/tools/crear_discos_desde_spec.php --spec=discos.json [--dry-run]
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Alta de discos abortada');

$opt = getopt('', ['spec:', 'dry-run']);
$dryRun = isset($opt['dry-run']);
$spec = json_decode((string) @file_get_contents((string) ($opt['spec'] ?? '')), true);
if (!is_array($spec)) {
    fwrite(STDERR, "Falta --spec=FICHERO.json válido\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("UPDATE log_actor SET ACTOR = 'cli:crear_discos' WHERE ID = 1");

$one = static function (string $sql, array $p) use ($pdo): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
};

$pdo->beginTransaction();
try {
    $conflictos = 0;
    foreach ($spec as $d) {
        $banda = (int) $d['banda'];
        if ($one('SELECT 1 AS x FROM banda WHERE ID_BANDA = ?', [$banda]) === null) throw new RuntimeException("La banda $banda no existe");

        $idDisco = isset($d['id_disco']) ? (int) $d['id_disco'] : 0;
        $accion = 'pistas añadidas a';
        if ($idDisco > 0) {
            $row = $one('SELECT NOMBRE_CD FROM disco WHERE ID_DISCO = ?', [$idDisco]);
            if ($row === null) throw new RuntimeException("El disco $idDisco no existe");
            $nombre = (string) $row['NOMBRE_CD'];
        } else {
            $nombre = trim((string) $d['nombre']);
            $ya = $one('SELECT ID_DISCO FROM disco WHERE BANDADISCO = ? AND lower(NOMBRE_CD) = lower(?)', [$banda, $nombre]);
            if ($ya !== null) {
                $idDisco = (int) $ya['ID_DISCO'];
                $accion = 'ya existía, pistas añadidas a';
            } else {
                $anio = isset($d['anio']) ? (int) $d['anio'] : null;
                if ($anio !== null && ($anio < 1000 || $anio > 9999)) throw new RuntimeException("Año no válido en «{$nombre}»");
                $notas = trim((string) ($d['notas'] ?? ''));
                $pdo->prepare('INSERT INTO disco (NOMBRE_CD, FECHA_CD, BANDADISCO, D_DETALLES) VALUES (?, ?, ?, ?)')
                    ->execute([$nombre, $anio, $banda, $notas !== '' ? $notas : null]);
                $idDisco = (int) $pdo->lastInsertId();
                $accion = 'creado';
            }
        }

        $añadidas = [];
        foreach ($d['pistas'] as $p) {
            $n = (int) $p['n'];
            $m = (int) $p['marcha'];
            if ($n < 1 || $n > 999) throw new RuntimeException("Pista $n no válida en «{$nombre}»");
            if ($one('SELECT 1 AS x FROM marcha WHERE ID_MARCHA = ?', [$m]) === null) throw new RuntimeException("La marcha $m (pista $n de «{$nombre}») no existe");
            if ($one('SELECT 1 AS x FROM disco_marcha WHERE ID_DISCO = ? AND IDMARCHA = ?', [$idDisco, $m]) !== null) continue;
            $ocupa = $one('SELECT IDMARCHA FROM disco_marcha WHERE ID_DISCO = ? AND N_DISCO = 1 AND NUMEROMARCHA = ?', [$idDisco, $n]);
            if ($ocupa !== null) {
                $conflictos++;
                echo "  ⚠ «{$nombre}» pista $n ya la ocupa la marcha {$ocupa['IDMARCHA']}; no se enlaza la $m\n";
                continue;
            }
            $pdo->prepare('INSERT INTO disco_marcha (ID_DISCO, N_DISCO, NUMEROMARCHA, IDMARCHA) VALUES (?, 1, ?, ?)')->execute([$idDisco, $n, $m]);
            $añadidas[] = $n . '→' . $m;
        }
        printf("%s #%d «%s» (banda %d): %s · %d pista(s): %s\n", $dryRun ? '[simulado]' : '✓', $idDisco, $nombre, $banda, $accion,
            count($añadidas), implode(' ', $añadidas));
    }
    $dryRun ? $pdo->rollBack() : $pdo->commit();
    if ($conflictos > 0) echo "$conflictos conflicto(s) de número de pista: revísalos a mano.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '✗ ' . $e->getMessage() . " — no se ha guardado nada\n");
    exit(1);
}
