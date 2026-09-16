<?php

declare(strict_types=1);

/*
 * Recupera los acompañamientos históricos de Sevilla que la carga del
 * 2026-08-29 perdió por el camino (2026-09-07).
 *
 * QUÉ PASÓ: resolve_acompanamientos.py casaba el nombre de banda del foro
 * contra `banda` por conjuntos de tokens, SIN mirar la localidad. Cuando dos
 * bandas se llaman igual en sitios distintos ("AM Redención" existe en Sevilla,
 * Córdoba y Palma) el script se rendía y tiraba la fila a bandas_ambiguas.csv.
 * Resultado: de 2.385 claves (hermandad, paso, año) en ámbito — paso de Cristo
 * con CCTT o AM — solo entraron 1.428. Se perdió el 40%, y con él la serie
 * histórica entera de La Macarena, La Hiniesta, San Pablo, La Milagrosa,
 * La Redención y La Espiga, que se quedaron con solo el año 2026.
 *
 * CÓMO SE DESAMBIGUA AQUÍ, en este orden:
 *   1. Localidad explícita en el texto de la fuente ("de Arahal", "(Dos
 *      Hermanas)", "de Cádiz") -> la banda de esa localidad.
 *   2. Estilo: si la fuente dice AM/A.M./Agrupación, la banda tiene que ser
 *      una "Agrupación Musical"; si dice CCTT/CyT/BCT, una "Banda de Cornetas
 *      y Tambores". Se mira NOMBRE_COMPLETO, no NOMBRE_BREVE: hay al menos una
 *      banda (id 92) cuyo BREVE empieza por "AM" siendo de cornetas.
 *   3. Juvenil: solo se elige una banda juvenil si la fuente dice "juvenil".
 *   4. Si sigue habiendo empate, gana la de Sevilla — son hermandades de
 *      Sevilla capital y el nombre venía sin localidad justo porque para el
 *      foro era la de casa.
 * Lo que no se resuelve con esas cuatro reglas NO se inventa: sale por
 * pantalla y se queda fuera.
 *
 * DOBLETES: el script viejo descartaba entero cualquier "banda A / banda B"
 * (ida y vuelta). Aquí se parten y se cargan las dos, que es como ya están
 * cargados los dobletes de 2026 (La Hiniesta y San Pablo tienen dos filas para
 * el mismo paso).
 *
 * ÁMBITO: solo pasos de Cristo (se descartan palio y virgen) y solo CCTT/AM
 * (se descartan bandas de música, capillas, escolanías y "sin música").
 *
 * Re-ejecutable: antes de insertar comprueba si ya existe esa combinación
 * (hermandad, titular, año, banda), así que una segunda pasada no duplica.
 *
 * Uso:
 *   php php/app/tools/recuperar_historico_acompanamientos.php --dry-run
 *   php php/app/tools/recuperar_historico_acompanamientos.php
 *   DB_PATH=/ruta/a/mdc.db php .../recuperar_historico_acompanamientos.php
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Recuperación abortada');
require APP_DIR . '/src/Slug.php';

/** @var list<string> $args */
$args = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $args, true);

// El CSV de la extracción vive en el repo (scripts/tmp_acompanamientos/), un
// nivel por encima de php/. Se admite una ruta explícita como argumento para
// poder apuntarlo a otra extracción sin tocar el script.
$csv = '';
foreach ($args as $a) {
    if ($a !== '' && $a[0] !== '-' && str_ends_with($a, '.csv')) {
        $csv = $a;
    }
}
if ($csv === '') {
    foreach ([dirname(__DIR__, 3), dirname(__DIR__, 2)] as $raiz) {
        $try = $raiz . '/scripts/tmp_acompanamientos/resueltos.csv';
        if (is_file($try)) {
            $csv = $try;
            break;
        }
    }
}
if ($csv === '' || !is_file($csv)) {
    fwrite(STDERR, "Recuperación abortada: no se encuentra resueltos.csv (pásalo como argumento)\n");
    exit(1);
}
echo "fuente: $csv\n";

const FUENTE = 'elforocofrade.es - Acompañamiento Musical a lo largo de la Historia';

/**
 * Grafía correcta de la hermandad. resueltos.csv trae los nombres tal como los
 * dejó HERM_MAP en el script de 2026-08-29: sin tildes (venían del texto plano
 * del foro) y con "El Carmen" todavía separado de "El Carmen Doloroso". Ambas
 * cosas se arreglaron en corregir_hermandades.php, así que hay que aplicar la
 * misma corrección aquí o las filas nuevas volverían a partir las series.
 */
const CANON = [
    'Bendicion y Esperanza' => 'Bendición y Esperanza',
    'Divino Perdon de Alcosa' => 'Divino Perdón de Alcosa',
    'El Carmen' => 'El Carmen Doloroso',
    'El Cerro del Aguila' => 'El Cerro del Águila',
    'Jesus Despojado' => 'Jesús Despojado',
    'La Carreteria' => 'La Carretería',
    'La Exaltacion' => 'La Exaltación',
    'La Mision' => 'La Misión',
    'La Pasion' => 'La Pasión',
    'La Redencion' => 'La Redención',
    'La Resurreccion' => 'La Resurrección',
    'Montesion' => 'Montesión',
    'Padre Pio' => 'Padre Pío',
    'San Jeronimo' => 'San Jerónimo',
    'San Jose Obrero' => 'San José Obrero',
];

/** Quita diacríticos y pasa a minúsculas, para comparar texto de la fuente. */
function plano(string $s): string
{
    $n = Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}+/u', '', $n === false ? $s : $n) ?? $s;
    return mb_strtolower($s, 'UTF-8');
}

/** 'AM' | 'CCTT' | null, según cómo se presenta la banda en la fuente. */
function estiloFuente(string $raw): ?string
{
    $p = ltrim(plano($raw));
    if (preg_match('/^(a\.?\s?m\.?\b|agrupacion)/', $p)) {
        return 'AM';
    }
    if (preg_match('/^(cctt|cyt|c\.c\.t\.t|bct|b\.c\.t|cornetas|banda de cc|centuria)/', $p)) {
        return 'CCTT';
    }
    return null;
}

/**
 * ¿El texto de la fuente (ya en plano) nombra la localidad de esta banda?
 * Vale también la primera palabra, porque la fuente abrevia: escribe "de
 * Jerez" donde la BD tiene "Jerez de la Frontera".
 */
function localidadNombrada(string $rawPlano, string $localidad): bool
{
    $loc = plano($localidad);
    if ($loc === '') {
        return false;
    }
    $corta = explode(' ', $loc)[0];
    return str_contains($rawPlano, $loc)
        || (mb_strlen($corta) > 3 && str_contains($rawPlano, $corta));
}

/** 'AM' | 'CCTT' | null, según el NOMBRE_COMPLETO de la banda en la BD. */
function estiloBanda(string $completo): ?string
{
    $p = plano($completo);
    if (str_starts_with($p, 'agrupacion musical')) {
        return 'AM';
    }
    if (str_contains($p, 'cornetas y tambores')) {
        return 'CCTT';
    }
    return null;
}

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:recuperar_historico_acompanamientos' WHERE ID = 1");

    /** @var list<array{id:int,breve:string,completo:string,loc:string}> $bandas */
    $bandas = [];
    foreach ($pdo->query('SELECT ID_BANDA, NOMBRE_BREVE, NOMBRE_COMPLETO, LOCALIDAD FROM banda') as $b) {
        $bandas[] = [
            'id' => (int) $b['ID_BANDA'],
            'breve' => (string) $b['NOMBRE_BREVE'],
            'completo' => (string) ($b['NOMBRE_COMPLETO'] ?? $b['NOMBRE_BREVE']),
            'loc' => (string) ($b['LOCALIDAD'] ?? ''),
        ];
    }

    // Palabras que no distinguen a una banda de otra: sobran al comparar.
    $vacias = ['banda', 'de', 'del', 'la', 'las', 'el', 'los', 'y', 'musical', 'musica',
        'agrupacion', 'asociacion', 'sociedad', 'municipal', 'ntra', 'ntro', 'nuestra',
        'nuestro', 'sra', 'sr', 'padre', 'jesus', 'stma', 'stmo', 'santisima',
        'santisimo', 'cristo', 'senor', 'senora', 'virgen', 'am', 'bm', 'cctt', 'cyt',
        'bct', 'cornetas', 'tambores', 'filarmonica', 'sagrada', 'sagrado'];

    // 'maria' NO va en la lista de vacías: es lo único que separaba
    // "CCTT Dulce Nombre de María" (una banda que no está en `banda`) de la
    // "BCT Dulce Nombre de Jesús Nazareno" de León, a 700 km de la Semana
    // Santa de Sevilla.
    //
    // Abreviaturas del foro que hay que desplegar antes de comparar, o
    // "AM Sta. Mª Magdalena (Arahal)" no casa con "Agrupación Musical Santa
    // María Magdalena" (comparten solo "magdalena" y ninguno contiene al otro).
    $abrev = ['sta' => 'santa', 'sto' => 'santo', 'stas' => 'santas', 'stos' => 'santos',
        'ma' => 'maria', 'mo' => 'maria', 'sn' => 'san', 'pto' => 'puerto'];

    /** @return array<string,true> */
    $tokens = static function (string $s) use ($vacias, $abrev): array {
        // "Mª" -> "maria" ANTES de limpiar símbolos: si no, la ª se va como
        // separador y queda una "m" suelta que no casa con nada.
        $p = str_replace(['mª', 'mº'], 'maria ', plano($s));
        $p = preg_replace('/[^a-z0-9 ]+/', ' ', $p) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($p)) ?: [] as $t) {
            $t = $abrev[$t] ?? $t;
            // Las letras sueltas son restos de siglas puntuadas ("A. M.",
            // "C. y T."), nunca parte del nombre.
            if (mb_strlen($t) > 1 && !in_array($t, $vacias, true)) {
                $out[$t] = true;
            }
        }
        return $out;
    };

    $bandaTokens = [];
    foreach ($bandas as $i => $b) {
        $tb = $tokens($b['breve']);
        $tc = $tokens($b['completo']);
        // 'ambos' = breve + completo. Hace falta porque hay bandas cuyo apodo
        // está solo en el breve y su advocación solo en el completo: la
        // "BCT Las Cigarreras" es {cigarreras} en breve y {victoria} en
        // completo, y la fuente la escribe "CyT Ntra. Sra. de la Victoria
        // (Cigarreras)" — con los dos campos juntos casa exacto.
        $bandaTokens[$i] = ['breve' => $tb, 'completo' => $tc, 'ambos' => $tb + $tc];
    }

    /** Resuelve un nombre suelto de banda -> [id|null, motivo]. */
    $resolver = static function (string $raw) use ($bandas, $bandaTokens, $tokens): array {
        $q = $tokens($raw);
        if ($q === []) {
            return [null, 'texto_vacio'];
        }
        $rp = plano($raw);

        // Contención de tokens en cualquiera de los dos sentidos: la fuente
        // abrevia ("AM Redención" -> "Agrupación Musical Nuestro Padre Jesús de
        // la Redención") o añade coletilla ("... de Arahal").
        //
        // La dirección "los tokens de la BANDA caben dentro de los de la fuente"
        // es la peligrosa: con una sola palabra significativa engancha cualquier
        // cosa — "CCTT Cruz Roja" {cruz,roja} se llevaba a "BCT La Cruz" {cruz}
        // de Tomares. Por eso en ese sentido se exigen 2 tokens de la banda.
        // Al revés (la fuente cabe dentro de la banda) no hace falta: ahí es la
        // fuente la que abrevia, que es el caso normal.
        $cands = [];
        foreach ($bandas as $i => $b) {
            // Excepción a la regla de los 2 tokens: si la fuente nombra la
            // localidad de la banda, una sola palabra ya basta. Hay bandas cuyo
            // nombre entero es una sola palabra significativa y lo único que
            // las distingue es de dónde son ("AM Sentencia" de Jerez, "BCT
            // Rosario" de Cádiz, "AM Victoria" de Arahal).
            $locNombrada = localidadNombrada($rp, $b['loc']);
            foreach (['breve', 'completo', 'ambos'] as $campo) {
                $ts = $bandaTokens[$i][$campo];
                if ($ts === []) {
                    continue;
                }
                $inter = count(array_intersect_key($q, $ts));
                $fuenteCabe = $inter === count($q);
                $bandaCabe = $inter === count($ts) && (count($ts) >= 2 || $locNombrada);
                if ($fuenteCabe || $bandaCabe) {
                    $cands[$b['id']] = $b;
                    break;
                }
            }
        }
        if ($cands === []) {
            return [null, 'sin_match'];
        }
        if (count($cands) === 1) {
            return [array_key_first($cands), 'unico'];
        }

        // Cadena de desempate: cada criterio filtra los que quedan. Si deja
        // exactamente uno, ese es; si deja varios, se sigue con esa lista más
        // corta; si no deja ninguno, ese criterio no sabe decidir y se ignora.
        $quiereJuvenil = str_contains($rp, 'juvenil');
        $ef = estiloFuente($raw);
        $criterios = [
            // La fuente dice de dónde es la banda.
            'localidad_explicita' => static fn($b) => localidadNombrada($rp, $b['loc']),
            // AM contra CCTT: una agrupación no es una banda de cornetas.
            'estilo' => static fn($b) => $ef === null || estiloBanda($b['completo']) === $ef,
            // Solo se elige la juvenil si la fuente la nombra juvenil.
            'juvenil' => static fn($b) => str_contains(plano($b['completo'] . ' ' . $b['breve']), 'juvenil') === $quiereJuvenil,
            // Último recurso: son hermandades de Sevilla capital, y el nombre
            // venía sin localidad justo porque para el foro era la de casa.
            'sevilla_por_defecto' => static fn($b) => plano($b['loc']) === 'sevilla',
        ];
        foreach ($criterios as $motivo => $pasa) {
            $filtrados = array_filter($cands, $pasa);
            if (count($filtrados) === 1) {
                return [array_key_first($filtrados), $motivo];
            }
            if ($filtrados !== []) {
                $cands = $filtrados;
            }
        }

        return [null, 'ambiguo_' . count($cands)];
    };

    // Lo ya cargado, indexado por el HECHO (qué hermandad, qué paso, qué año y
    // qué decía la fuente), NO por la banda a la que se resolvió. Indexar por
    // ID_BANDA sería un error sutil: la carga del 2026-08-29 resolvió mal
    // bastantes nombres (mandó "AM Sta. María de la Esperanza" a la BCT La
    // Esperanza de Málaga en vez de a la AM de Sevilla), y con la banda en la
    // clave esas filas no se reconocen como el mismo hecho — se quedarían las
    // dos, la mala y la buena.
    $yaHay = [];
    $sqlYa = "SELECT ID_CONTRATO, HERMANDAD_SLUG, COALESCE(TITULAR, '') T, ANIO, ID_BANDA, NOTA
              FROM contrato WHERE FUENTE = ?";
    $stYa = $pdo->prepare($sqlYa);
    $stYa->execute([FUENTE]);
    foreach ($stYa as $r) {
        $raw = str_starts_with((string) $r['NOTA'], 'banda_raw=') ? substr((string) $r['NOTA'], 10) : '';
        $k = $r['HERMANDAD_SLUG'] . '|' . $r['T'] . '|' . $r['ANIO'] . '|' . plano($raw);
        $yaHay[$k] = ['id' => (int) $r['ID_CONTRATO'], 'banda' => (int) $r['ID_BANDA']];
    }

    $insertar = [];
    $corregir = [];
    $corregir = [];
    $porMotivo = [];
    $muestra = [];
    $noResueltas = [];
    $f = fopen($csv, 'r');
    if ($f === false) {
        fwrite(STDERR, "Recuperación abortada: no se pudo leer $csv\n");
        exit(1);
    }
    $cab = fgetcsv($f);
    if ($cab === false) {
        fwrite(STDERR, "Recuperación abortada: $csv vacío\n");
        exit(1);
    }
    $ix = array_flip($cab);

    while (($r = fgetcsv($f)) !== false) {
        $titular = (string) $r[$ix['titular']];
        $tp = plano($titular);
        if (str_contains($tp, 'palio') || str_contains($tp, 'virgen')) {
            continue;   // fuera del ámbito del sitio
        }

        $bandaRaw = (string) $r[$ix['banda_raw']];
        $herm = (string) $r[$ix['hermandad']];
        $herm = CANON[$herm] ?? $herm;
        $slug = App\Slug::slugify($herm);
        $anio = (int) $r[$ix['anio']];

        // Un doblete ("A / B", "A (ida) / B (vuelta)") son dos contratos.
        // Se parte por '/' solo fuera de paréntesis: "(Sanlúcar / Aznalcázar)"
        // es la localidad de UNA banda, no dos bandas.
        $partes = preg_split('#\s*/\s*(?![^()]*\))#', $bandaRaw) ?: [$bandaRaw];
        foreach ($partes as $parte) {
            $parte = trim($parte);
            if ($parte === '' || estiloFuente($parte) === null) {
                continue;   // BM, capilla, escolanía, silencio…
            }
            [$id, $motivo] = $resolver($parte);
            if ($id === null) {
                $noResueltas[$parte] = ['motivo' => $motivo, 'n' => ($noResueltas[$parte]['n'] ?? 0) + 1];
                continue;
            }
            $k = $slug . '|' . $titular . '|' . $anio . '|' . plano($parte);
            if (isset($yaHay[$k])) {
                // Mismo hecho ya cargado. Si la carga vieja lo mandó a otra
                // banda, se corrige aquí: este resolutor mira localidad, estilo
                // y juvenil, cosa que el de 2026-08-29 no hacía.
                if ($yaHay[$k]['banda'] !== $id && $yaHay[$k]['id'] > 0) {
                    $corregir[] = [$yaHay[$k]['id'], $yaHay[$k]['banda'], $id, $parte];
                }
                continue;
            }
            $yaHay[$k] = ['id' => 0, 'banda' => $id];
            $porMotivo[$motivo] = ($porMotivo[$motivo] ?? 0) + 1;
            $muestra[$motivo][$parte] = $id;
            $insertar[] = [$id, $herm, $slug, $titular === '' ? null : $titular, $anio, 'banda_raw=' . $parte];
        }
    }
    fclose($f);

    echo 'filas a insertar: ' . count($insertar) . "\n";
    echo 'filas ya cargadas con la banda equivocada, a corregir: ' . count($corregir) . "\n";
    if ($dryRun) {
        echo "por regla de desambiguación:\n";
        arsort($porMotivo);
        foreach ($porMotivo as $m => $n) {
            echo "  $m: $n filas\n";
            foreach ($muestra[$m] as $raw => $id) {
                $s = $pdo->prepare('SELECT NOMBRE_BREVE, LOCALIDAD FROM banda WHERE ID_BANDA = ?');
                $s->execute([$id]);
                $b = $s->fetch();
                echo "      \"$raw\" -> #$id {$b['NOMBRE_BREVE']} ({$b['LOCALIDAD']})\n";
            }
        }
    }
    if ($noResueltas !== []) {
        uasort($noResueltas, static fn($a, $b) => $b['n'] <=> $a['n']);
        echo 'nombres de banda SIN resolver (no se cargan, revisar a mano): ' . count($noResueltas) . "\n";
        foreach (array_slice($noResueltas, 0, 40, true) as $raw => $d) {
            echo "  [{$d['motivo']}] \"$raw\" ({$d['n']} filas)\n";
        }
    }
    if ($dryRun) {
        echo "--dry-run: no se ha tocado la BD\n";
        exit(0);
    }
    if ($insertar === [] && $corregir === []) {
        echo "nada que hacer\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Recuperación abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-recuperar-historico.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $insC = $pdo->prepare(
        'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE, NOTA)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insL = $pdo->prepare('INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, ?)');
    foreach ($insertar as [$idBanda, $herm, $slug, $titular, $anio, $nota]) {
        $insC->execute([$idBanda, $herm, $slug, $titular, $anio, FUENTE, $nota]);
        $insL->execute([(int) $pdo->lastInsertId(), 'Sevilla']);
    }
    $upd = $pdo->prepare('UPDATE contrato SET ID_BANDA = ? WHERE ID_CONTRATO = ?');
    foreach ($corregir as [$idContrato, , $idNuevo]) {
        $upd->execute([$idNuevo, $idContrato]);
    }
    $pdo->commit();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo count($insertar) . ' contratos insertados, ' . count($corregir) . " corregidos\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Recuperación falló: ' . $e->getMessage() . "\n");
    exit(1);
}
