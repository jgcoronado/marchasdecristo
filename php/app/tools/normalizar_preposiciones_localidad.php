<?php

declare(strict_types=1);

/*
 * Limpieza one-shot: ortografía española de las preposiciones/artículos
 * dentro de marcha.LOCALIDAD y banda.LOCALIDAD ("Jerez De La Frontera" ->
 * "Jerez de la Frontera"). Deliberadamente aparte de
 * normalizar_localidades.php: aquella fusiona variantes por mayúsculas/
 * acentos eligiendo la grafía más frecuente (una decisión "qué dato ya
 * existe"); esta reescribe TODAS las filas a la regla ortográfica, sin
 * mirar el recuento — es una decisión de estilo, no una fusión de datos
 * duplicados.
 *
 * Regla aplicada, palabra a palabra:
 *   - "de" / "del": siempre en minúscula (son preposiciones, nunca la
 *     primera palabra real de un topónimo).
 *   - "la" / "las" / "los": en minúscula EXCEPTO si son la primera palabra
 *     del nombre — algunos topónimos empiezan así de verdad ("La Línea de
 *     la Concepción", "Los Palacios y Villafranca") y ahí el artículo forma
 *     parte del nombre oficial, no se toca.
 *   - "y": siempre en minúscula (conjunción, nunca inicial de un topónimo).
 *   - Cualquier otra palabra (el nombre propio en sí): tal cual está, no se
 *     toca — se asume ya fusionada por normalizar_localidades.php.
 *
 * Re-ejecutable: si ya está todo en minúscula donde corresponde, no hace nada.
 *
 * Uso:
 *   php php/app/tools/normalizar_preposiciones_localidad.php
 *   DB_PATH=/ruta/a/mdc.db php .../normalizar_preposiciones_localidad.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay
 * algo que actualizar.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Limpieza abortada');

/** Palabras que van siempre en minúscula, estén donde estén. */
const SIEMPRE_MINUSCULA = ['de', 'del', 'y'];
/** Palabras en minúscula salvo que sean la primera del nombre. */
const MINUSCULA_SALVO_INICIAL = ['la', 'las', 'los'];

/** Reescribe "Jerez De La Frontera" -> "Jerez de la Frontera" (ver reglas arriba). */
function corrigePreposiciones(string $nombre): string
{
    $tokens = preg_split('/(\s+)/u', $nombre, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($tokens === false) {
        return $nombre;
    }
    $out = [];
    $numPalabra = 0;
    foreach ($tokens as $tok) {
        if (trim($tok) === '') {
            $out[] = $tok; // separador (espacios): tal cual
            continue;
        }
        $min = mb_strtolower($tok, 'UTF-8');
        if (in_array($min, SIEMPRE_MINUSCULA, true)) {
            $out[] = $min;
        } elseif ($numPalabra > 0 && in_array($min, MINUSCULA_SALVO_INICIAL, true)) {
            $out[] = $min;
        } else {
            $out[] = $tok;
        }
        $numPalabra++;
    }
    return implode('', $out);
}

/** Columnas a limpiar: [tabla, columna]. Solo LOCALIDAD — las provincias de
 *  Mapa::PROVINCIAS con "de"/"la" ya están bien ("Santa Cruz de Tenerife",
 *  "La Rioja"…), no hace falta tocar PROVINCIA. */
$OBJETIVOS = [
    ['marcha', 'LOCALIDAD'],
    ['banda', 'LOCALIDAD'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:normalizar_preposiciones_localidad' WHERE ID = 1");

    // 1) Calcular el plan de cambios antes de tocar nada.
    $plan = [];
    $totalFilas = 0;
    foreach ($OBJETIVOS as [$tabla, $col]) {
        $stmt = $pdo->query("SELECT $col AS v, COUNT(*) AS n FROM $tabla WHERE $col IS NOT NULL AND $col != '' GROUP BY $col");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $updates = [];
        foreach ($rows as $r) {
            $v = (string) $r['v'];
            $corregido = corrigePreposiciones($v);
            if ($corregido !== $v) {
                $updates[$v] = ['a' => $corregido, 'n' => (int) $r['n']];
                $totalFilas += (int) $r['n'];
            }
        }
        if ($updates !== []) {
            $plan[] = ['tabla' => $tabla, 'col' => $col, 'updates' => $updates];
        }
    }

    if ($plan === []) {
        echo "nada que corregir (ya está todo en minúscula donde corresponde)\n";
        exit(0);
    }

    // 2) Informe.
    echo "cambios de ortografía encontrados:\n";
    foreach ($plan as $p) {
        foreach ($p['updates'] as $de => $info) {
            echo "  [{$p['tabla']}.{$p['col']}] \"$de\" -> \"{$info['a']}\" ({$info['n']} filas)\n";
        }
    }
    echo "total de filas a reescribir: $totalFilas\n\n";

    // 3) Backup + aplicar.
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Limpieza abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-normalizar-preposiciones.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    foreach ($plan as $p) {
        $upd = $pdo->prepare("UPDATE {$p['tabla']} SET {$p['col']} = ? WHERE {$p['col']} = ?");
        $n = 0;
        foreach ($p['updates'] as $de => $info) {
            $upd->execute([$info['a'], $de]);
            $n += $upd->rowCount();
        }
        echo "{$p['tabla']}.{$p['col']}: $n filas actualizadas\n";
    }
    $pdo->commit();
    // Vuelca el WAL al fichero principal (ver mismo comentario en
    // normalizar_localidades.php / corregir_acentos_localidad.php).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Limpieza falló: ' . $e->getMessage() . "\n");
    exit(1);
}
