<?php

declare(strict_types=1);

/*
 * Propuesta (SOLO LECTURA, no escribe nada en la BD) de contratos banda↔paso
 * de Cristo para las 39 hermandades de Jerez 2026 que tienen paso creado
 * (poblar_hermandad_paso_jerez.php). Alcance del sitio: SOLO CCTT/AM y SOLO
 * pasos de Cristo (docs/acompanamientos-nomina-2026.md) — las 10 hermandades
 * sin CCTT/AM (silencio, banda de música, capilla musical o escolanía) no
 * tienen paso creado y por tanto no aparecen aquí.
 *
 * BANDA_RAW_TEXT sale de scripts/tmp_acompanamientos/jerez_bandas_reconciliado.csv
 * (columna BANDA_CRISTO, ya resuelto el cruce musicofrades.com vs
 * mundocofrade.es a favor de mundocofrade en los 5 conflictos reales — ver
 * ese CSV y project_acompanamientos.md para el detalle). Cuando hay dos
 * bandas para el mismo paso (El Transporte, Misión) se generan dos filas.
 *
 * Resolutor: mismo algoritmo que scripts/tmp_acompanamientos/proponer_contratos_cordoba.php
 * (localidad explícita -> estilo AM/CCTT -> juvenil -> por defecto), con el
 * desempate por defecto en 'Jerez de la Frontera' en vez de 'Córdoba'.
 *
 * NO toca la base de datos: no hay transacciones, ni backups, ni escrituras.
 *
 * Uso:
 *   php scripts/tmp_acompanamientos/proponer_contratos_jerez.php
 *   DB_PATH=/ruta/a/mdc.db php .../proponer_contratos_jerez.php
 */

$db = getenv('DB_PATH');
if ($db === false || $db === '') {
    $db = __DIR__ . '/../../php/data/mdc.db';
}
if (!is_file($db)) {
    fwrite(STDERR, "Propuesta abortada: no existe la BD en $db\n");
    exit(1);
}

const LOCALIDAD = 'Jerez de la Frontera';

/**
 * Una fila por cada mención de banda de un paso de Cristo. slug = slugify()
 * del NOMBRE de hermandad usado en poblar_hermandad_paso_jerez.php; paso =
 * el mismo NOMBRE (el paso se creó con el mismo nombre que la hermandad,
 * placeholder pendiente de titular real).
 *
 * @return list<array{slug:string,paso:string,raw:string,nota:string}>
 */
function nomina(): array
{
    return [
        ['slug' => 'entrega-de-guadalcacin', 'paso' => 'Entrega de Guadalcacín',
            'raw' => 'BCT Santísimo Cristo de la Caridad - Santa Marta (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'cautivo-del-portal', 'paso' => 'Cautivo del Portal',
            'raw' => 'Agrupación Musical Santa Ángela de la Cruz (Las Cabezas de San Juan)', 'nota' => ''],
        ['slug' => 'humildad-de-barbadillo', 'paso' => 'Humildad de Barbadillo',
            'raw' => 'Agrupación Musical Nuestra Señora de Valme (Dos Hermanas)', 'nota' => ''],
        ['slug' => 'prendimiento-de-torrecera', 'paso' => 'Prendimiento de Torrecera',
            'raw' => 'Agrupación Musical Sagrada Resurrección (Sanlúcar de Barrameda)', 'nota' => ''],
        ['slug' => 'la-borriquita', 'paso' => 'La Borriquita',
            'raw' => 'Banda de Cornetas y Tambores Cristo de la Vera-Cruz (Los Palacios y Villafranca)', 'nota' => ''],
        ['slug' => 'la-coronacion', 'paso' => 'La Coronación',
            'raw' => 'Agrupación Musical La Sentencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'el-transporte', 'paso' => 'El Transporte',
            'raw' => 'Banda de Cornetas y Tambores Presentación al Pueblo (Sevilla)',
            'nota' => 'mundocofrade da dos bandas para este paso, ver siguiente fila'],
        ['slug' => 'el-transporte', 'paso' => 'El Transporte',
            'raw' => 'Banda de Cornetas y Tambores Centuria Romana Macarena (Sevilla)',
            'nota' => 'segunda banda del mismo paso'],
        ['slug' => 'pasion', 'paso' => 'Pasión',
            'raw' => 'Banda de Cornetas y Tambores Amor y Sacrificio (Lebrija)', 'nota' => ''],
        ['slug' => 'el-perdon', 'paso' => 'El Perdón',
            'raw' => 'Banda de Cornetas y Tambores Nuestro Padre Jesús de los Remedios (Castilleja de la Cuesta)', 'nota' => ''],
        ['slug' => 'la-sed', 'paso' => 'La Sed',
            'raw' => 'Banda de Cornetas y Tambores Coronación de Campillos (Málaga)', 'nota' => ''],
        ['slug' => 'la-paz-de-fatima', 'paso' => 'La Paz de Fátima',
            'raw' => 'Agrupación Musical La Sentencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'la-candelaria', 'paso' => 'La Candelaria',
            'raw' => 'Agrupación Musical Lágrimas de Dolores (San Fernando)', 'nota' => ''],
        ['slug' => 'sagrada-cena', 'paso' => 'Sagrada Cena',
            'raw' => 'Agrupación Musical Nuestra Señora de la Estrella (Dos Hermanas)', 'nota' => ''],
        ['slug' => 'bondad-y-misericordia', 'paso' => 'Bondad y Misericordia',
            'raw' => 'Agrupación Musical San Juan (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'la-clemencia', 'paso' => 'La Clemencia',
            'raw' => 'Agrupación Musical Santísimo Cristo de la Clemencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'salud-de-san-rafael', 'paso' => 'Salud de San Rafael',
            'raw' => 'Agrupación Musical La Sentencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'la-defension', 'paso' => 'La Defensión',
            'raw' => 'Banda de Cornetas y Tambores Nuestro Padre Jesús en la Presentación al Pueblo (Dos Hermanas)', 'nota' => ''],
        ['slug' => 'la-salvacion', 'paso' => 'La Salvación',
            'raw' => 'Agrupación Musical Polillas (Cádiz)', 'nota' => ''],
        ['slug' => 'el-amor', 'paso' => 'El Amor',
            'raw' => 'Agrupación Musical Nuestra Señora de Valme (Dos Hermanas)', 'nota' => ''],
        ['slug' => 'judios-de-san-mateo', 'paso' => 'Judíos de San Mateo',
            'raw' => 'Agrupación Musical Nuestro Padre Jesús de la Redención (Sevilla)', 'nota' => ''],
        ['slug' => 'soberano-poder', 'paso' => 'Soberano Poder',
            'raw' => 'Agrupación Musical La Sentencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'el-consuelo', 'paso' => 'El Consuelo',
            'raw' => 'Agrupación Musical Sagrada Resurrección (Sanlúcar de Barrameda)', 'nota' => ''],
        ['slug' => 'las-tres-caidas', 'paso' => 'Las Tres Caídas',
            'raw' => 'Banda de Cornetas y Tambores Nuestro Padre Jesús de las Tres Caídas (Arcos de la Frontera)',
            'nota' => 'mundocofrade menciona además una Escolanía/Coral acompañando; fuera de alcance (no es CCTT/AM)'],
        ['slug' => 'la-flagelacion', 'paso' => 'La Flagelación',
            'raw' => 'Banda de Cornetas y Tambores Caridad (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'prendimiento', 'paso' => 'Prendimiento',
            'raw' => 'Agrupación Musical Nuestro Padre Jesús de la Salud Los Gitanos (Sevilla)', 'nota' => ''],
        ['slug' => 'vera-cruz', 'paso' => 'Vera-Cruz',
            'raw' => 'Banda de Cornetas y Tambores Nazareno (Utrera)', 'nota' => ''],
        ['slug' => 'la-redencion', 'paso' => 'La Redención',
            'raw' => 'Banda de Cornetas y Tambores Santísimo Cristo de la Elevación (Campo de Criptana)', 'nota' => ''],
        ['slug' => 'oracion-en-el-huerto', 'paso' => 'Oración en el Huerto',
            'raw' => 'Banda de Cornetas y Tambores Coronación de Campillos (Málaga)', 'nota' => ''],
        ['slug' => 'la-lanzada', 'paso' => 'La Lanzada',
            'raw' => 'Banda de Cornetas y Tambores Esencia (Sevilla)', 'nota' => ''],
        ['slug' => 'mayor-dolor', 'paso' => 'Mayor Dolor',
            'raw' => 'Banda de Cornetas y Tambores Caridad (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'nazareno', 'paso' => 'Nazareno',
            'raw' => 'Agrupación Musical Sagrada Resurrección (Chiclana de la Frontera)',
            'nota' => 'conflicto resuelto a favor de mundocofrade: musicofrades tenía BCT Gran Poder de Coria del Río'],
        ['slug' => 'la-yedra', 'paso' => 'La Yedra',
            'raw' => 'Agrupación Musical La Sentencia (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'mision', 'paso' => 'Misión',
            'raw' => 'Banda de Cornetas y Tambores Jesús Despojado (San Fernando)',
            'nota' => 'mundocofrade da dos bandas para este paso, ver siguiente fila'],
        ['slug' => 'mision', 'paso' => 'Misión',
            'raw' => 'Banda de Cornetas y Tambores Nuestra Señora de Gracia (Carmona)',
            'nota' => 'segunda banda del mismo paso'],
        ['slug' => 'las-vinas', 'paso' => 'Las Viñas',
            'raw' => 'Agrupación Musical Virgen de los Reyes (Sevilla)', 'nota' => ''],
        ['slug' => 'el-cristo', 'paso' => 'El Cristo',
            'raw' => 'Agrupación Musical San Juan (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'la-soledad', 'paso' => 'La Soledad',
            'raw' => 'Banda de Cornetas y Tambores Caridad (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'sacramental-de-santiago', 'paso' => 'Sacramental de Santiago',
            'raw' => 'Banda de Cornetas y Tambores Fundación Zoilo Ruiz Mateos (Rota)', 'nota' => ''],
        ['slug' => 'santa-marta', 'paso' => 'Santa Marta',
            'raw' => 'Banda de Cornetas y Tambores Caridad (Jerez de la Frontera)', 'nota' => ''],
        ['slug' => 'resucitado', 'paso' => 'Resucitado',
            'raw' => 'Agrupación Musical San Juan (Jerez de la Frontera)', 'nota' => ''],
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
    if (preg_match('/^(cctt|cyt|c\.c\.t\.t|bct|b\.c\.t|cornetas|banda de cc|banda de cornetas|centuria|la banda de cornetas)/', $p)) {
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
    $stmt = $pdo->prepare('SELECT ID_HERMANDAD, NOMBRE, SLUG FROM hermandad WHERE LOCALIDAD = ?');
    $stmt->execute([LOCALIDAD]);
    foreach ($stmt as $h) {
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
        // Los tokens de comparación NO incluyen el paréntesis final (es la
        // localidad de la banda fuente, no parte de su nombre): si no se
        // quita, palabras del nombre de la localidad ("Las Cabezas de San
        // Juan") pueden colar como si fueran tokens sustanciales del nombre
        // de una banda no relacionada ("Agrupación Musical San Juan").
        $sinLocalidad = preg_replace('/\s*\([^)]*\)\s*$/', '', $raw) ?? $raw;
        $q = $tokens($sinLocalidad);
        if ($q === []) {
            return [null, 'texto_vacio'];
        }
        $rp = plano($raw);

        $cands = [];
        foreach ($bandas as $i => $b) {
            $locNombrada = localidadNombrada($rp, $b['loc']);
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
            'jerez_por_defecto' => static fn($b) => plano($b['loc']) === plano(LOCALIDAD),
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

    $out = fopen(__DIR__ . '/jerez_contratos_propuesta.csv', 'w');
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
            $incidencias[] = "posible_paso_no_listado: {$herm['NOMBRE']} ({$fila['slug']}) — paso \"{$fila['paso']}\" no existe. Texto: \"{$fila['raw']}\"";
            continue;
        }

        [$idBanda, $motivo] = $resolver($fila['raw']);
        // "Santa Ángela de la Cruz (Las Cabezas de San Juan)" no existe en
        // `banda` (comprobado a mano: 0 resultados por "angela"/"cabezas").
        // El resolutor la empareja por casualidad de tokens ("santa"+"cruz")
        // con bandas sin ninguna relación real (Huelva, Jerez...) — se
        // fuerza sin_match en vez de confiar en el match espurio.
        if ($fila['slug'] === 'cautivo-del-portal') {
            $idBanda = null;
            $motivo = 'excluido_match_espurio';
        }
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

    echo "CSV: scripts/tmp_acompanamientos/jerez_contratos_propuesta.csv\n";
    echo 'filas por confianza: ' . json_encode($porConfianza) . "\n";
    echo count($incidencias) . " incidencias:\n";
    foreach ($incidencias as $i) {
        echo "  - $i\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Propuesta falló: ' . $e->getMessage() . "\n");
    exit(1);
}
