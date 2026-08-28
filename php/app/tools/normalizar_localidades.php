<?php

declare(strict_types=1);

/*
 * Limpieza one-shot: unifica variantes de mayúsculas/acentos/espacios de una
 * misma localidad o provincia dentro de marcha.LOCALIDAD, marcha.PROVINCIA,
 * banda.LOCALIDAD y banda.PROVINCIA.
 *
 * Motivación: la carga histórica del catálogo dejó la misma localidad escrita
 * de formas distintas (p.ej. "Aguilar De La Frontera" / "Aguilar de la
 * Frontera"). App\Repo::hubLocalidades() ya las fusiona en tiempo de
 * petición para el mapa, pero el dato de origen sigue duplicado — esto lo
 * limpia en la propia BD.
 *
 * Dos pasadas:
 *   1) Espacios: TRIM incondicional (sin esto, "Sevilla" y "Sevilla " con
 *      espacio de más contarían como "variantes" a decidir en la pasada 2,
 *      cuando en realidad es solo suciedad de espacios, no una decisión).
 *   2) Mayúsculas/acentos: agrupa por clave normalizada (minúsculas, sin
 *      acentos — igual criterio que app/tools/completar_provincia.php).
 *      Cuando un grupo tiene más de una grafía exacta, se queda con la más
 *      frecuente (más filas la usan); en empate, prefiere la grafía con
 *      mayúsculas/minúsculas mixtas ("Sevilla") sobre TODO MAYÚSCULAS o todo
 *      minúsculas; último desempate, alfabético.
 *
 * Re-ejecutable: si no encuentra nada que limpiar no toca nada.
 *
 * Uso:
 *   php php/app/tools/normalizar_localidades.php
 *   DB_PATH=/ruta/a/mdc.db php .../normalizar_localidades.php
 *
 * Hace una copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay
 * algo que actualizar.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Limpieza abortada');

/** Quita acentos y pasa a minúsculas para agrupar variantes de capitalización. */
function normalizaClave(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
}

/** true si $v tiene mayúsculas Y minúsculas mezcladas (grafía "normal", tipo
 *  "Sevilla" o "Aguilar de la Frontera") — se prefiere frente a TODO
 *  MAYÚSCULAS o todo minúsculas cuando hay que desempatar por recuento. */
function pareceBienEscrito(string $v): bool
{
    return $v !== mb_strtoupper($v, 'UTF-8') && $v !== mb_strtolower($v, 'UTF-8');
}

/** true si el primer carácter es una mayúscula (un topónimo nunca debería
 *  empezar en minúscula: "ávila" es un error de captura, no una variante
 *  legítima de "Ávila", por muchas filas que tenga). */
function empiezaEnMayuscula(string $v): bool
{
    $primera = mb_substr($v, 0, 1, 'UTF-8');
    return $primera === mb_strtoupper($primera, 'UTF-8') && $primera !== mb_strtolower($primera, 'UTF-8');
}

/** Nº de vocales/ñ con tilde — para preferir "Cáceres" sobre "Caceres": la
 *  ausencia de tilde suele ser el descuido, no la variante "buena". */
function nAcentos(string $v): int
{
    return (int) preg_match_all('/[áéíóúñÁÉÍÓÚÑ]/u', $v);
}

/** Columnas a limpiar: [tabla, columna id, columna a normalizar]. */
$OBJETIVOS = [
    ['marcha', 'ID_MARCHA', 'LOCALIDAD'],
    ['marcha', 'ID_MARCHA', 'PROVINCIA'],
    ['banda', 'ID_BANDA', 'LOCALIDAD'],
    ['banda', 'ID_BANDA', 'PROVINCIA'],
];

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1) Espacios de más: incondicional, sin necesidad de decidir nada.
    $espacios = [];
    foreach ($OBJETIVOS as [$tabla, , $col]) {
        $n = $pdo->exec("UPDATE $tabla SET $col = TRIM($col) WHERE $col != TRIM($col)");
        if ($n > 0) {
            $espacios["$tabla.$col"] = $n;
        }
    }

    // 2) Detectar grupos con variantes de mayúsculas/acentos, por tabla+columna.
    // $plan[i] = ['tabla'=>, 'col'=>, 'updates'=>[variante_original => canonica], 'detalle'=>[...]]
    $plan = [];
    $totalFilas = 0;
    foreach ($OBJETIVOS as [$tabla, $idCol, $col]) {
        $rows = $pdo->query(
            "SELECT $col AS v, COUNT(*) AS n FROM $tabla
             WHERE $col IS NOT NULL AND $col != ''
             GROUP BY $col"
        )->fetchAll(PDO::FETCH_ASSOC);

        $grupos = []; // clave normalizada => [variante => n]
        foreach ($rows as $r) {
            $v = (string) $r['v'];
            $grupos[normalizaClave($v)][$v] = (int) $r['n'];
        }

        $updates = [];
        $detalle = [];
        foreach ($grupos as $variantes) {
            if (count($variantes) < 2) {
                continue; // sin variantes, nada que fusionar
            }
            // Canónica, en este orden (los dos primeros criterios van ANTES
            // que el recuento a propósito: una mayúscula inicial correcta o
            // una tilde no deben perder solo porque la variante "sucia"
            // tenga más filas — "ávila" no debe ganarle a "Ávila" por tener
            // más filas, ni "Caceres" a "Cáceres" en un empate alfabético):
            //   1) empieza en mayúscula (un topónimo no debería no hacerlo)
            //   2) más letras con tilde (la ausencia de tilde suele ser el
            //      descuido, no la variante "buena")
            //   3) más usada
            //   4) mayúsculas/minúsculas mezcladas, no TODO MAYÚSCULAS/minúsculas
            //   5) alfabética (último desempate, ya sin más criterio objetivo)
            $ordenadas = array_keys($variantes);
            usort($ordenadas, static function ($a, $b) use ($variantes) {
                return (int) empiezaEnMayuscula($b) <=> (int) empiezaEnMayuscula($a)
                    ?: nAcentos($b) <=> nAcentos($a)
                    ?: $variantes[$b] <=> $variantes[$a]
                    ?: (int) pareceBienEscrito($b) <=> (int) pareceBienEscrito($a)
                    ?: strcmp($a, $b);
            });
            $canonica = $ordenadas[0];
            $detalle[] = ['canonica' => $canonica, 'variantes' => $variantes];
            foreach ($variantes as $v => $n) {
                if ($v !== $canonica) {
                    $updates[$v] = $canonica;
                    $totalFilas += $n;
                }
            }
        }

        if ($updates !== []) {
            $plan[] = ['tabla' => $tabla, 'col' => $col, 'updates' => $updates, 'detalle' => $detalle];
        }
    }

    if ($espacios === [] && $plan === []) {
        echo "nada que limpiar\n";
        exit(0);
    }

    // 3) Informe antes de tocar nada (los espacios ya se aplicaron: son
    //    incondicionales y no requieren revisión).
    if ($espacios !== []) {
        echo "espacios de más recortados:\n";
        foreach ($espacios as $k => $n) {
            echo "  [$k] $n filas\n";
        }
    }
    if ($plan !== []) {
        echo "variantes de mayúsculas/acentos encontradas:\n";
        foreach ($plan as $p) {
            foreach ($p['detalle'] as $d) {
                $partes = [];
                foreach ($d['variantes'] as $v => $n) {
                    $partes[] = "\"$v\" ($n)";
                }
                echo "  [{$p['tabla']}.{$p['col']}] " . implode(' + ', $partes) . " -> \"{$d['canonica']}\"\n";
            }
        }
        echo "total de filas a reescribir: $totalFilas\n";
    }
    echo "\n";

    if ($plan === []) {
        // Los TRIM ya se aplicaron (fuera de transacción, son incondicionales);
        // sin variantes de capitalización, no hace falta backup ni más.
        exit(0);
    }

    // 4) Backup + aplicar la fusión de variantes.
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Limpieza abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-normalizar-localidades.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    foreach ($plan as $p) {
        $upd = $pdo->prepare("UPDATE {$p['tabla']} SET {$p['col']} = ? WHERE {$p['col']} = ?");
        $n = 0;
        foreach ($p['updates'] as $variante => $canonica) {
            $upd->execute([$canonica, $variante]);
            $n += $upd->rowCount();
        }
        echo "{$p['tabla']}.{$p['col']}: $n filas actualizadas\n";
    }
    $pdo->commit();
    // Vuelca el WAL al fichero principal: en modo journal_mode=WAL (el que
    // deja la app tras usarse en local/producción) los cambios pueden
    // quedar solo en el -wal y no verse desde otra conexión hasta el
    // próximo checkpoint automático — p.ej. si copias el .db justo después.
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
