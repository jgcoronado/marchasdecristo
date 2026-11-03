<?php

declare(strict_types=1);

/*
 * Limpieza one-shot: pasa las localidades con el artículo pospuesto entre
 * paréntesis, al estilo del listado INE ("Puebla del Río (La)"), al orden en
 * que se escriben ("La Puebla del Río").
 *
 * Motivación: la tabla `municipio` se sembró con app/geo/municipios_es.php,
 * que traía ~570 nombres en formato INE. El predictivo del panel los ofrecía
 * así y de ahí pasaron a fichas de banda y a acompañamientos ("Molares (Los)"),
 * conviviendo con los escritos a mano en orden normal ("Los Palacios y
 * Villafranca"). La semilla ya está corregida; esto arregla la BD.
 *
 * Nombres bilingües ("Villajoyosa/Vila Joiosa (la)"): el artículo va con su
 * parte, así que se trata cada parte separada por "/" por su cuenta
 * ("Villajoyosa/La Vila Joiosa"). Los paréntesis que no son un artículo
 * ("Noáin (Valle de Elorz)") no se tocan.
 *
 * Columnas: municipio.NOMBRE (+ CLAVE), banda/marcha.LOCALIDAD y la LOCALIDAD
 * de acompañamientos (contrato_localidad, hermandad, semana_santa_dia,
 * localidad_provincia). Los registros de auditoría (admin_log, cambio_log) son
 * historia y no se reescriben.
 *
 * Re-ejecutable: si no encuentra nada que cambiar no toca nada.
 *
 * Uso:
 *   php php/app/tools/normalizar_articulo_localidad.php            (simula)
 *   php php/app/tools/normalizar_articulo_localidad.php --aplicar
 *   DB_PATH=/ruta/a/mdc.db php .../normalizar_articulo_localidad.php --aplicar
 *
 * Con --aplicar hace una copia de seguridad (VACUUM INTO) antes de tocar nada.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Limpieza abortada');

/** "Puebla del Río (La)" → "La Puebla del Río"; "Alcora (l')" → "L'Alcora". */
function anteponerArticulo(string $nombre): string
{
    $partes = array_map(static function (string $p): string {
        if (!preg_match("/^(.+?)\s*\((el|la|los|las|l'|els|les|es|sa|ses|a|as|o|os)\)$/iu", $p, $m)) {
            return $p;
        }
        $art = mb_strtoupper(mb_substr($m[2], 0, 1)) . mb_strtolower(mb_substr($m[2], 1));
        return str_ends_with($art, "'") ? $art . $m[1] : "$art {$m[1]}";
    }, explode('/', $nombre));
    return implode('/', $partes);
}

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

/** Columnas de texto libre con la localidad: [tabla, columna]. */
$OBJETIVOS = [
    ['banda', 'LOCALIDAD'],
    ['marcha', 'LOCALIDAD'],
    ['contrato_localidad', 'LOCALIDAD'],
    ['hermandad', 'LOCALIDAD'],
    ['semana_santa_dia', 'LOCALIDAD'],
    ['localidad_provincia', 'LOCALIDAD'],
];

$aplicar = in_array('--aplicar', $argv, true);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1) municipio: renombrar y recalcular CLAVE. Si el nombre bueno ya existe
    //    como alta heredada/manual (OFICIAL = 0), sobra: se borra y se queda la
    //    oficial, que trae coordenadas. Nada referencia municipio por ID.
    $municipios = [];   // [id, provincia, viejo, nuevo, clave nueva, id a borrar|null]
    $conflictos = [];
    $porClave = $pdo->prepare('SELECT ID_MUNICIPIO, OFICIAL FROM municipio WHERE CLAVE = ?');
    foreach ($pdo->query("SELECT ID_MUNICIPIO, PROVINCIA, NOMBRE FROM municipio WHERE NOMBRE LIKE '%)%'") as $r) {
        $nuevo = anteponerArticulo((string) $r['NOMBRE']);
        if ($nuevo === $r['NOMBRE']) {
            continue;
        }
        $clave = claveMunicipio((string) $r['PROVINCIA'], $nuevo);
        $porClave->execute([$clave]);
        $otro = $porClave->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($otro !== null && (int) $otro['OFICIAL'] !== 0) {
            $conflictos[] = "municipio {$r['ID_MUNICIPIO']} \"{$r['NOMBRE']}\" choca con el oficial {$otro['ID_MUNICIPIO']}";
            continue;
        }
        $municipios[] = [(int) $r['ID_MUNICIPIO'], $r['PROVINCIA'], $r['NOMBRE'], $nuevo, $clave, $otro ? (int) $otro['ID_MUNICIPIO'] : null];
    }

    // 2) Columnas de texto libre: valor viejo → nuevo.
    $plan = [];   // "tabla.col" => [viejo => [nuevo, filas]]
    foreach ($OBJETIVOS as [$tabla, $col]) {
        $rows = $pdo->query("SELECT $col AS v, COUNT(*) AS n FROM $tabla WHERE $col LIKE '%)%' GROUP BY $col");
        foreach ($rows as $r) {
            $nuevo = anteponerArticulo((string) $r['v']);
            if ($nuevo !== $r['v']) {
                $plan["$tabla.$col"][(string) $r['v']] = [$nuevo, (int) $r['n']];
            }
        }
    }

    if ($conflictos !== []) {
        fwrite(STDERR, "Limpieza abortada, choques sin resolver:\n  " . implode("\n  ", $conflictos) . "\n");
        exit(1);
    }
    if ($municipios === [] && $plan === []) {
        echo "nada que cambiar\n";
        exit(0);
    }

    // 3) Informe.
    echo 'municipio.NOMBRE: ' . count($municipios) . " filas\n";
    foreach ($municipios as [$id, $prov, $viejo, $nuevo, , $borrar]) {
        echo "  [$prov] \"$viejo\" -> \"$nuevo\"" . ($borrar ? " (borra el duplicado heredado $borrar)" : '') . "\n";
    }
    foreach ($plan as $donde => $cambios) {
        echo "$donde:\n";
        foreach ($cambios as $viejo => [$nuevo, $n]) {
            echo "  \"$viejo\" -> \"$nuevo\" ($n filas)\n";
        }
    }
    echo "\n";

    if (!$aplicar) {
        echo "simulación: nada escrito (repite con --aplicar)\n";
        exit(0);
    }

    // 4) Backup + aplicar.
    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Limpieza abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-articulo-localidad.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:normalizar_articulo_localidad' WHERE ID = 1");
    $del = $pdo->prepare('DELETE FROM municipio WHERE ID_MUNICIPIO = ? AND OFICIAL = 0');
    $upd = $pdo->prepare('UPDATE municipio SET NOMBRE = ?, CLAVE = ? WHERE ID_MUNICIPIO = ?');
    foreach ($municipios as [$id, , , $nuevo, $clave, $borrar]) {
        if ($borrar !== null) {
            $del->execute([$borrar]);
        }
        $upd->execute([$nuevo, $clave, $id]);
    }
    echo 'municipio: ' . count($municipios) . " filas actualizadas\n";
    foreach ($plan as $donde => $cambios) {
        [$tabla, $col] = explode('.', $donde);
        $u = $pdo->prepare("UPDATE $tabla SET $col = ? WHERE $col = ?");
        $n = 0;
        foreach ($cambios as $viejo => [$nuevo]) {
            $u->execute([$nuevo, $viejo]);
            $n += $u->rowCount();
        }
        echo "$donde: $n filas actualizadas\n";
    }
    $pdo->commit();
    // Vuelca el WAL al fichero principal (ver app/tools/normalizar_localidades.php).
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
