<?php

declare(strict_types=1);

/*
 * Borra de `contrato` los acompañamientos de un año en el que esa localidad no
 * hizo estación de penitencia (los que estén en `temporada_sin_salida`, ver
 * 014_temporada_sin_salida.sql).
 *
 * Por qué existe: la carga histórica de Sevilla (elforocofrade.es) trae 27
 * filas de 2020 y 2021 — el contrato se firmó, pero la pandemia dejó a todas
 * las hermandades sin salir. Una fila en `contrato` significa "esta banda tocó
 * tras este paso ese año", que es justo lo que no pasó. Además tapa el aviso
 * de /acompanamientos: Repo::agruparAcompanamientos() sólo anota "2020-2021 sin
 * salida" cuando el hueco está entero, y una sola fila de 2020 lo rompe.
 *
 * Qué cambia: borra esas filas de `contrato` y sus satélites
 * (`contrato_localidad`, `contrato_paso`). Si sale mal, la serie de esa
 * hermandad pierde un año que sí debería estar; se recupera restaurando el
 * backup que hace `php/app/tools/backup.php` antes de tocar nada.
 *
 * Uso (no escribe nada sin --commit):
 *   php php/app/tools/purgar_contratos_sin_salida.php
 *   php php/app/tools/purgar_contratos_sin_salida.php --commit
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Purga de contratos sin salida abortada');

$commit = in_array('--commit', $argv ?? [], true);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:purgar_contratos_sin_salida' WHERE ID = 1");

    // la localidad del contrato está en contrato_localidad (009), no en contrato
    $sql = 'SELECT c.ID_CONTRATO, c.ANIO, c.HERMANDAD, c.TITULAR, cl.LOCALIDAD, b.NOMBRE_COMPLETO AS NOMBRE
              FROM contrato c
              JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
              JOIN temporada_sin_salida t
                ON t.LOCALIDAD = cl.LOCALIDAD AND t.ANIO = c.ANIO
              LEFT JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             ORDER BY cl.LOCALIDAD, c.ANIO, c.HERMANDAD';
    /** @var list<array<string,mixed>> $filas */
    $filas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if ($filas === []) {
        echo "no hay contratos en años sin salida procesional\n";
        exit(0);
    }

    foreach ($filas as $f) {
        printf(
            "  %-22s %s  %-28s %-24s %s\n",
            (string) $f['LOCALIDAD'],
            (string) $f['ANIO'],
            (string) $f['HERMANDAD'],
            (string) ($f['TITULAR'] ?? ''),
            (string) ($f['NOMBRE'] ?? '?')
        );
    }

    if (!$commit) {
        echo count($filas) . " filas a borrar (dry-run, no se ha escrito nada; --commit para aplicar)\n";
        exit(0);
    }

    $ids = array_map(static fn (array $f): int => (int) $f['ID_CONTRATO'], $filas);
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $pdo->beginTransaction();
    foreach (['contrato_paso', 'contrato_localidad', 'contrato'] as $tabla) {
        $pdo->prepare("DELETE FROM $tabla WHERE ID_CONTRATO IN ($marcas)")->execute($ids);
    }
    $pdo->commit();

    echo count($ids) . " contratos borrados\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Purga de contratos sin salida abortada: ' . $e->getMessage() . "\n");
    exit(1);
}
