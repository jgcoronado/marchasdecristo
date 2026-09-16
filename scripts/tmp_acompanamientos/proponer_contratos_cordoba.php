<?php

declare(strict_types=1);

/*
 * Propuesta (SOLO LECTURA, no escribe nada en la BD) de contratos banda↔paso
 * de Cristo para las 42 hermandades de Córdoba 2026.
 *
 * Por qué el texto banda↔paso está codificado a mano abajo en vez de parsearse
 * de cordoba_extraido.csv o del .txt con regex: el CSV tiene al menos un bug
 * de truncado confirmado (corta "la Agrupación Musical Ntro." a mitad de
 * frase en la ficha de "Penas de Santiago" porque el extractor se detiene en
 * la abreviatura "Ntro."), y el .txt reordena texto entre fichas por saltos
 * de página (ver docs/acompanamientos-nomina-2026.md, sección "Parseo de
 * Córdoba"). Cada fila de NOMINA() de abajo se ha leído a mano contra
 * cordoba_programa_2026_raw.txt (línea a línea) para no arrastrar esos bugs,
 * separando ya el paso de Cristo del de Virgen/palio y descartando Cruz de
 * Guía (ver cordoba_cruces_guia_pendiente.csv aparte).
 *
 * Lo que SÍ hace este script en código (reejecutable si cambia `banda`):
 * resolver cada BANDA_RAW_TEXT contra la tabla `banda`, con el mismo
 * algoritmo de desambiguación que php/app/tools/recuperar_historico_acompanamientos.php
 * (localidad explícita -> estilo AM/CCTT -> juvenil -> por defecto), con UN
 * cambio: el desempate por defecto aquí es "gana Córdoba" (LOCALIDAD =
 * 'Córdoba', con tilde) en vez de "gana Sevilla" — el original ganaba Sevilla
 * porque esas eran hermandades sevillanas y el foro omitía la localidad
 * cuando la banda era de casa; aquí el corpus es de Córdoba.
 *
 * NO toca la base de datos: no hay transacciones, ni backups, ni escrituras.
 * Solo lee `banda` y vuelca un CSV de propuesta para revisión humana.
 *
 * Uso:
 *   php scripts/tmp_acompanamientos/proponer_contratos_cordoba.php
 *   DB_PATH=/ruta/a/mdc.db php .../proponer_contratos_cordoba.php
 */

$db = getenv('DB_PATH');
if ($db === false || $db === '') {
    $db = __DIR__ . '/../../php/data/mdc.db';
}
if (!is_file($db)) {
    fwrite(STDERR, "Propuesta abortada: no existe la BD en $db\n");
    exit(1);
}

/**
 * Una fila por cada mención de banda de un paso de Cristo, leída a mano del
 * programa oficial (scripts/tmp_acompanamientos/cordoba_programa_2026_raw.txt).
 * PASO_NOMBRE tiene que coincidir tal cual con paso.NOMBRE ya cargado por
 * poblar_hermandad_paso_cordoba.php — si no coincide, el script lo reporta
 * como "posible_paso_no_listado" en vez de forzarlo.
 *
 * @return list<array{slug:string,paso:string,raw:string,nota:string}>
 */
function nomina(): array
{
    return [
        ['slug' => 'entrada-triunfal', 'paso' => 'El Señor de los Reyes',
            'raw' => 'Banda de Cornetas y Tambores Caído y Fuensanta. (Córdoba)', 'nota' => ''],
        ['slug' => 'penas-de-santiago', 'paso' => 'Cristo de las Penas',
            'raw' => 'Agrupación Musical Ntro. Padre Jesús de la Salud - Sección Musical de la Hermandad de los Gitanos de Sevilla',
            'nota' => 'texto completo tomado del .txt: cordoba_extraido.csv lo trae truncado en "Ntro."'],
        ['slug' => 'huerto', 'paso' => 'La Oración en el Huerto',
            'raw' => 'Agrupación Musical de Nuestro Padre Jesús de la Redención (Córdoba)', 'nota' => ''],
        ['slug' => 'huerto', 'paso' => 'El Señor Amarrado a la Columna',
            'raw' => 'Asociación Musical Utrerana (Utrera)', 'nota' => ''],
        ['slug' => 'rescatado', 'paso' => 'El Rescatado',
            'raw' => 'Banda de Cornetas y Tambores Nuestro Padre Jesús Nazareno de Arahal', 'nota' => ''],
        ['slug' => 'vera-cruz', 'paso' => 'Cristo del Amor',
            'raw' => 'Banda de cornetas y tambores de Nuestra Señora del Rosario de Linares',
            'nota' => 'la ficha llama a este paso "el Señor de los Reyes" (parece copia errónea del título de Entrada Triunfal); ya se cargó como "Cristo del Amor" tras cruzar con yescordoba.es, ver docs/acompanamientos-nomina-2026.md'],
        ['slug' => 'esperanza', 'paso' => 'Jesús de las Penas',
            'raw' => 'Agrupación Musical Pasión de Linares', 'nota' => ''],
        ['slug' => 'amor', 'paso' => 'Jesús del Silencio',
            'raw' => 'Banda de CCTT Nuestra Señora de la Salud',
            'nota' => 'paso de misterio; el texto no da localidad para esta banda'],
        ['slug' => 'amor', 'paso' => 'Cristo del Amor',
            'raw' => 'Banda de CCTT Maestro Valero (Aguilar de la Fra.)', 'nota' => ''],
        ['slug' => 'merced', 'paso' => 'Jesús Humilde',
            'raw' => 'Banda de Cornetas y Tambores de la Coronación de Espinas de Córdoba', 'nota' => ''],
        ['slug' => 'presentacion-al-pueblo', 'paso' => 'Jesús de los Afligidos',
            'raw' => 'la banda de cornetas y tambores Santísimo Cristo de la Elevación de Campo de Criptana (Ciudad Real)', 'nota' => ''],
        ['slug' => 'redencion', 'paso' => 'La Redención',
            'raw' => 'Agrupación musical de Nuestro Padre jesús de la Redención de Córdoba', 'nota' => ''],
        ['slug' => 'sentencia', 'paso' => 'La Sentencia',
            'raw' => 'Banda de cornetas y tambores Nuestra Señora del Sol', 'nota' => ''],
        ['slug' => 'agonia', 'paso' => 'Cristo de la Agonía',
            'raw' => 'la banda de Cornetas y Tambores Nuestra Señora de la Salud de Córdoba', 'nota' => ''],
        ['slug' => 'sangre', 'paso' => 'Jesús de la Sangre',
            'raw' => 'la Banda de Cornetas y Tambores "Esencia", de Sevilla', 'nota' => ''],
        ['slug' => 'buen-suceso', 'paso' => 'Jesús del Buen Suceso',
            'raw' => 'la BCT. "Monte Calvario" de Martos (Jaén)', 'nota' => ''],
        ['slug' => 'santa-faz', 'paso' => 'La Santa Faz',
            'raw' => 'la Agrupación Musical La Pasión de Linares (Jaén)', 'nota' => ''],
        ['slug' => 'prendimiento', 'paso' => 'El Prendimiento',
            'raw' => 'la Agrupación Musical Santísimo Cristo de Gracia (Córdoba)', 'nota' => ''],
        ['slug' => 'perdon', 'paso' => 'El Perdón',
            'raw' => 'la BCT. "Coronación de Espinas" de Córdoba', 'nota' => ''],
        ['slug' => 'paz-y-esperanza', 'paso' => 'Jesús de la Humildad y Paciencia',
            'raw' => 'la Banda CCTT la Salud (Córdoba)', 'nota' => ''],
        ['slug' => 'calvario', 'paso' => 'El Calvario',
            'raw' => 'la Banda de Cornetas y Tambores de Nuestro Padre Jesús Nazareno de Arahal', 'nota' => ''],
        ['slug' => 'misericordia', 'paso' => 'Cristo de la Misericordia',
            'raw' => 'la CCTT Caído y Fuensanta de Córdoba', 'nota' => ''],
        ['slug' => 'pasion', 'paso' => 'Jesús de la Pasión',
            'raw' => 'la Agrupación Musical Santo Tomás de Villanueva', 'nota' => ''],
        ['slug' => 'piedad', 'paso' => 'Cristo de la Piedad',
            'raw' => 'la Banda de Cornetas y Tambores del Santísimo Cristo de la Expiración de Quesada, Jaén',
            'nota' => 'el paso real junta Cristo de la Piedad y Virgen (Dulce Nombre de María Stma. de la Esperanza) en una sola imagen/paso combinado; esta banda acompaña el paso entero'],
        ['slug' => 'caridad', 'paso' => 'Cristo de la Caridad',
            'raw' => 'la Banda de CC y TT Coronación de Espinas de Córdoba', 'nota' => ''],
        ['slug' => 'caido', 'paso' => 'Jesús Caído',
            'raw' => 'La Banda de Cornetas y Tambores de Nuestro Padre Jesús Caído y Ntra Señora de la Fuensanta', 'nota' => ''],
        ['slug' => 'sagrada-cena', 'paso' => 'Jesús de la Fe',
            'raw' => 'la Agrupación Musical Nuestro Padre Jesús de la Fe en su Sagrada Cena',
            'nota' => 'banda propia de la propia hermandad, nombrada igual que su titular'],
        ['slug' => 'cristo-de-gracia', 'paso' => 'Cristo de Gracia',
            'raw' => 'la Agrupación Musical Santísimo Cristo de Gracia (Córdoba)', 'nota' => ''],
        ['slug' => 'descendimiento', 'paso' => 'Cristo del Descendimiento',
            'raw' => 'la Banda de Cornetas y Tambores Caído y Fuensanta de Córdoba', 'nota' => ''],
        ['slug' => 'conversion', 'paso' => 'Cristo de la Conversión',
            'raw' => 'la Agrupación Musical Nuestro Padre Jesús de la Redención (Córdoba)', 'nota' => ''],
        ['slug' => 'dolores', 'paso' => 'Cristo de la Clemencia',
            'raw' => 'la Banda de Cornetas y Tambores Esencia (Sevilla)', 'nota' => ''],
        ['slug' => 'resucitado', 'paso' => 'El Resucitado',
            'raw' => 'Agrupacion Musical Santisimo Cristo de Gracia de Cordoba', 'nota' => 'para "el Misterio"'],
        // Sin fila propuesta a propósito (paso existe pero la banda de la
        // ficha NO es CCTT/AM, así que se queda fuera de alcance):
        //   expiracion: "Capilla Musical Ars Sacra de Écija (Sevilla)".
    ];
}

/** Quita diacríticos y pasa a minúsculas. */
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
    if (preg_match('/^(a\.?\s?m\.?\b|agrupacion|asociacion musical)/', $p)) {
        return 'AM';
    }
    if (preg_match('/^(cctt|cyt|c\.c\.t\.t|bct|b\.c\.t|cornetas|banda de cc|centuria|la banda de cornetas)/', $p)) {
        return 'CCTT';
    }
    return null;
}

/** 'AM' | 'CCTT' | null, según el NOMBRE_COMPLETO de la banda en la BD. */
function estiloBanda(string $completo): ?string
{
    $p = plano($completo);
    if (str_starts_with($p, 'agrupacion musical') || str_starts_with($p, 'asociacion musical')) {
        return 'AM';
    }
    if (str_contains($p, 'cornetas y tambores')) {
        return 'CCTT';
    }
    return null;
}

/** ¿El texto de la fuente (ya en plano) nombra la localidad de esta banda? */
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

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /** @var array<string,array{ID_HERMANDAD:int,NOMBRE:string}> $hermandades */
    $hermandades = [];
    foreach ($pdo->query("SELECT ID_HERMANDAD, NOMBRE, SLUG FROM hermandad WHERE LOCALIDAD = 'Cordoba'") as $h) {
        $hermandades[(string) $h['SLUG']] = ['ID_HERMANDAD' => (int) $h['ID_HERMANDAD'], 'NOMBRE' => (string) $h['NOMBRE']];
    }

    /** @var array<int,array<string,int>> $pasosPorHermandad NOMBRE(plano) -> ID_PASO */
    $pasosPorHermandad = [];
    foreach ($pdo->query('SELECT ID_PASO, ID_HERMANDAD, NOMBRE FROM paso') as $p) {
        $pasosPorHermandad[(int) $p['ID_HERMANDAD']][plano((string) $p['NOMBRE'])] = (int) $p['ID_PASO'];
    }

    /** @var list<array{id:int,breve:string,completo:string,loc:string}> $bandas */
    $bandas = [];
    foreach ($pdo->query('SELECT ID_BANDA, NOMBRE_BREVE, NOMBRE_COMPLETO, LOCALIDAD FROM banda WHERE ID_BANDA > 0') as $b) {
        $bandas[] = [
            'id' => (int) $b['ID_BANDA'],
            'breve' => (string) $b['NOMBRE_BREVE'],
            'completo' => (string) ($b['NOMBRE_COMPLETO'] ?? $b['NOMBRE_BREVE']),
            'loc' => (string) ($b['LOCALIDAD'] ?? ''),
        ];
    }

    $vacias = ['banda', 'de', 'del', 'la', 'las', 'el', 'los', 'y', 'musical', 'musica',
        'agrupacion', 'asociacion', 'sociedad', 'municipal', 'ntra', 'ntro', 'nuestra',
        'nuestro', 'sra', 'sr', 'padre', 'jesus', 'stma', 'stmo', 'santisima',
        'santisimo', 'cristo', 'senor', 'senora', 'virgen', 'am', 'bm', 'cctt', 'cyt',
        'bct', 'cornetas', 'tambores', 'filarmonica', 'sagrada', 'sagrado'];
    $abrev = ['sta' => 'santa', 'sto' => 'santo', 'stas' => 'santas', 'stos' => 'santos',
        'ma' => 'maria', 'mo' => 'maria', 'sn' => 'san', 'pto' => 'puerto'];

    $tokens = static function (string $s) use ($vacias, $abrev): array {
        $p = str_replace(['mª', 'mº'], 'maria ', plano($s));
        $p = preg_replace('/[^a-z0-9 ]+/', ' ', $p) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($p)) ?: [] as $t) {
            $t = $abrev[$t] ?? $t;
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
        $bandaTokens[$i] = ['breve' => $tb, 'completo' => $tc, 'ambos' => $tb + $tc];
    }

    /** Resuelve un nombre suelto de banda -> [id|null, motivo]. */
    $resolver = static function (string $raw) use ($bandas, $bandaTokens, $tokens): array {
        $q = $tokens($raw);
        if ($q === []) {
            return [null, 'texto_vacio'];
        }
        $rp = plano($raw);

        $cands = [];
        foreach ($bandas as $i => $b) {
            $locNombrada = localidadNombrada($rp, $b['loc']);
            // Tokens que vienen del propio nombre de la localidad ("cordoba",
            // "sevilla"...) no cuentan como palabra distintiva de la banda:
            // si no se descartan, una banda cuyo nombre entero es solo la
            // ciudad ("Agrupación Musical de Córdoba", id 221) colisiona con
            // CUALQUIER otra banda de esa misma ciudad mencionada con
            // "(Córdoba)", porque su único token útil ya "cabe" por la
            // excepción de localidad nombrada.
            $tokensLocalidad = $tokens($b['loc']);
            foreach (['breve', 'completo', 'ambos'] as $campo) {
                $ts = $bandaTokens[$i][$campo];
                if ($ts === []) {
                    continue;
                }
                $inter = count(array_intersect_key($q, $ts));
                $fuenteCabe = $inter === count($q);
                $tsSustancial = array_diff_key($ts, $tokensLocalidad);
                $bandaCabe = $inter === count($ts)
                    && $tsSustancial !== []
                    && (count($ts) >= 2 || $locNombrada);
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

        $quiereJuvenil = str_contains($rp, 'juvenil');
        $ef = estiloFuente($raw);
        $criterios = [
            'localidad_explicita' => static fn($b) => localidadNombrada($rp, $b['loc']),
            'estilo' => static fn($b) => $ef === null || estiloBanda($b['completo']) === $ef,
            'juvenil' => static fn($b) => str_contains(plano($b['completo'] . ' ' . $b['breve']), 'juvenil') === $quiereJuvenil,
            // Cambio respecto a recuperar_historico_acompanamientos.php: aquí
            // el corpus es de Córdoba, así que el desempate por defecto es
            // Córdoba, no Sevilla.
            'cordoba_por_defecto' => static fn($b) => plano($b['loc']) === plano('Córdoba'),
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

    $out = fopen(__DIR__ . '/cordoba_contratos_propuesta.csv', 'w');
    if ($out === false) {
        fwrite(STDERR, "Propuesta abortada: no se pudo crear el CSV de salida\n");
        exit(1);
    }
    fputcsv($out, ['HERMANDAD_SLUG', 'HERMANDAD_NOMBRE', 'PASO_NOMBRE', 'BANDA_RAW_TEXT',
        'BANDA_ID_CANDIDATA', 'BANDA_NOMBRE_CANDIDATA', 'BANDA_LOCALIDAD_CANDIDATA',
        'MOTIVO_MATCH', 'CONFIANZA', 'NOTA']);

    $porConfianza = ['alta' => 0, 'media' => 0, 'baja' => 0];
    $incidencias = [];
    $bandaPorId = [];
    foreach ($bandas as $b) {
        $bandaPorId[$b['id']] = $b;
    }

    foreach (nomina() as $fila) {
        $herm = $hermandades[$fila['slug']] ?? null;
        if ($herm === null) {
            $incidencias[] = "slug_hermandad_no_encontrado: {$fila['slug']}";
            continue;
        }
        $idPaso = $pasosPorHermandad[$herm['ID_HERMANDAD']][plano($fila['paso'])] ?? null;
        if ($idPaso === null) {
            $incidencias[] = "posible_paso_no_listado: {$herm['NOMBRE']} ({$fila['slug']}) — paso \"{$fila['paso']}\" no existe en la tabla paso para esta hermandad. Texto: \"{$fila['raw']}\"";
            continue;
        }

        [$idBanda, $motivo] = $resolver($fila['raw']);
        if ($idBanda === null) {
            $confianza = 'baja';
            $bandaNombre = '';
            $bandaLoc = '';
        } else {
            $b = $bandaPorId[$idBanda];
            $bandaNombre = $b['completo'];
            $bandaLoc = $b['loc'];
            $confianza = $motivo === 'localidad_explicita' || $motivo === 'unico' ? 'alta' : 'media';
        }
        $porConfianza[$confianza]++;
        if ($confianza === 'baja') {
            $incidencias[] = "sin_match: {$herm['NOMBRE']} / {$fila['paso']} — \"{$fila['raw']}\"";
        }

        fputcsv($out, [
            $fila['slug'], $herm['NOMBRE'], $fila['paso'], $fila['raw'],
            $idBanda ?? '', $bandaNombre, $bandaLoc, $motivo, $confianza, $fila['nota'],
        ]);
    }
    fclose($out);

    echo "CSV: scripts/tmp_acompanamientos/cordoba_contratos_propuesta.csv\n";
    echo 'filas por confianza: ' . json_encode($porConfianza) . "\n";
    echo count($incidencias) . " incidencias:\n";
    foreach ($incidencias as $i) {
        echo "  - $i\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Propuesta falló: ' . $e->getMessage() . "\n");
    exit(1);
}
