<?php

declare(strict_types=1);

namespace App;

/**
 * Alta asistida de las pistas de un disco a partir del tracklist de su álbum
 * en streaming (ver `Tracklist`).
 *
 * Reparto de responsabilidades:
 *   · `Tracklist`  → de una URL a la lista de cortes del servicio.
 *   · esta clase   → de esa lista al plan de pistas del disco (qué marcha del
 *                    catálogo es cada corte, con qué número, volumen y duración),
 *                    y la escritura del plan una vez el usuario lo aprueba.
 *   · `AdminRepo::addPista` → la escritura real, con sus validaciones. El
 *                    importador no inserta por su cuenta: pasa por la misma
 *                    puerta que el alta manual, así que ni duplica reglas ni
 *                    puede saltárselas.
 *
 * El emparejado es una PROPUESTA: nada se escribe sin que el usuario revise la
 * tabla y confirme. Por eso el umbral no descarta nada, solo decide qué llega
 * marcado y qué llega señalado como dudoso.
 */
final class ImportadorPistas
{
    /**
     * Umbral de reconocimiento. Por debajo, la pista se marca como «sin
     * coincidencia» y se ofrece crear la marcha: el criterio de similitud es el
     * de music_match.php, donde 0.80 ya tolera tildes, artículos y sufijos de
     * versión, pero no confunde dos marchas distintas.
     */
    public const UMBRAL = 0.80;

    /**
     * Palabras que no discriminan un título. Solo se usan para elegir por qué
     * tokens preseleccionar candidatas en SQL — la similitud posterior sí las
     * tiene en cuenta.
     */
    private const VACIAS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'a', 'al', 'en', 'un', 'una', 'su', 'mi', 'por', 'con'];

    /**
     * Propone un plan de pistas para el disco a partir del tracklist.
     *
     * @param  list<array<string,mixed>> $tracks  cortes del servicio (ver Tracklist::de)
     * @return list<array<string,mixed>>          una fila por corte, en orden de edición
     */
    public static function analizar(int $idDisco, array $tracks): array
    {
        $yaEnDisco = self::marchasDelDisco($idDisco);
        $ocupadas = self::pistasOcupadas($idDisco);

        // 1) Candidatas por título (preselección en SQL) y su similitud.
        $puntuadas = [];   // idx => list<array{id:int,score:float,fila:array}>
        foreach ($tracks as $i => $t) {
            $titulo = (string) ($t['titulo'] ?? '');
            $filas = [];
            foreach (self::candidatas($titulo) as $m) {
                $filas[] = [
                    'id' => (int) $m['ID_MARCHA'],
                    'score' => Tracklist::similitud((string) $m['TITULO'], $titulo),
                    'fila' => $m,
                ];
            }
            usort($filas, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $puntuadas[$i] = $filas;
        }

        // 2) Asignación 1:1 y greedy por score descendente: ni dos cortes se
        //    llevan la misma marcha (los discos repiten títulos: "…(en directo)"),
        //    ni una marcha se reparte entre dos cortes.
        $pares = [];
        foreach ($puntuadas as $i => $filas) {
            foreach ($filas as $c) {
                if ($c['score'] >= self::UMBRAL) $pares[] = [$c['score'], $i, $c];
            }
        }
        usort($pares, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $asignado = [];
        $marchaUsada = [];
        foreach ($pares as [$score, $i, $c]) {
            if (isset($asignado[$i]) || isset($marchaUsada[$c['id']])) continue;
            $asignado[$i] = $c;
            $marchaUsada[$c['id']] = true;
        }

        // 3) Filas de revisión, en el orden en que vienen del servicio.
        $out = [];
        foreach ($tracks as $i => $t) {
            $numero = (int) ($t['n'] ?? 0) ?: ($i + 1);
            $volumen = max(1, (int) ($t['disco'] ?? 1));
            $seg = isset($t['seg']) ? (int) $t['seg'] : null;
            if ($seg !== null && $seg <= 0) $seg = null;

            $match = $asignado[$i] ?? null;
            $mejor = $puntuadas[$i][0] ?? null;   // el mejor de todos, aunque no llegue al umbral

            $estado = 'sin_coincidencia';
            if ($match !== null) {
                $estado = isset($yaEnDisco[$match['id']]) ? 'ya_en_disco' : 'reconocida';
            } elseif ($mejor !== null && $mejor['score'] >= self::UMBRAL) {
                // Reconocida de sobra, pero otra pista se llevó esa marcha: es
                // el caso de los discos que repiten una marcha (toma de estudio
                // y toma en directo). Una marcha solo puede estar una vez en el
                // disco, así que se avisa en vez de proponer un alta imposible.
                $estado = 'duplicada';
            }

            $out[] = [
                'idx' => $i,
                'n' => $numero,
                'volumen' => $volumen,
                'titulo' => (string) ($t['titulo'] ?? ''),
                'seg' => $seg,
                'idMarcha' => $match !== null ? $match['id'] : null,
                'marcha' => $match !== null ? $match['fila'] : null,
                'score' => $match !== null ? (float) $match['score'] : 0.0,
                'estado' => $estado,
                // Mejor descartada: lo que hay que enseñar cuando NO se reconoce,
                // para que el usuario juzgue si es la misma marcha mal escrita.
                'sugerencia' => ($match === null && $mejor !== null && $mejor['score'] > 0)
                    ? ['titulo' => (string) $mejor['fila']['TITULO'], 'id' => (int) $mejor['fila']['ID_MARCHA'], 'score' => (float) $mejor['score']]
                    : null,
                'ocupada' => isset($ocupadas[$volumen . ':' . $numero]),
            ];
        }
        return $out;
    }

    /**
     * Escribe el plan aprobado. Las pistas se insertan en orden (volumen,
     * número) para que un fallo a mitad deje el disco completo hasta ese punto
     * y no un revoltijo.
     *
     * Cada elemento de $filas: ['idMarcha'=>int, 'numero'=>int, 'volumen'=>int,
     * 'seg'=>?int, 'percusion'=>?int, 'titulo'=>string (solo para el informe)].
     *
     * @param  list<array<string,mixed>> $filas
     * @return array{anadidas:int, errores:list<array{titulo:string,code:string}>}
     */
    public static function aplicar(int $idDisco, array $filas): array
    {
        usort($filas, static function (array $a, array $b): int {
            $va = (int) ($a['volumen'] ?? 1);
            $vb = (int) ($b['volumen'] ?? 1);
            if ($va !== $vb) return $va <=> $vb;
            return ((int) ($a['numero'] ?? 0)) <=> ((int) ($b['numero'] ?? 0));
        });

        $anadidas = 0;
        $errores = [];
        foreach ($filas as $f) {
            $idMarcha = (int) ($f['idMarcha'] ?? 0);
            if ($idMarcha <= 0) continue;
            $r = AdminRepo::addPista(
                $idDisco,
                $idMarcha,
                (int) ($f['numero'] ?? 0),
                (int) ($f['volumen'] ?? 1),
                isset($f['seg']) && $f['seg'] !== null ? (int) $f['seg'] : null,
                isset($f['percusion']) && $f['percusion'] !== null ? (int) $f['percusion'] : null
            );
            if (($r['code'] ?? '') === 'CREATED') {
                $anadidas++;
            } else {
                $errores[] = ['titulo' => (string) ($f['titulo'] ?? ('#' . $idMarcha)), 'code' => (string) ($r['code'] ?? 'ERROR')];
            }
        }
        return ['anadidas' => $anadidas, 'errores' => $errores];
    }

    /**
     * Marchas del catálogo que podrían ser este corte. Preselección barata en
     * SQL (comparte alguna palabra significativa del título) antes de puntuar
     * en PHP: sin ella habría que recorrer las ~5.000 marchas por cada pista.
     *
     * El ORDEN importa tanto como el filtro. Un título con palabras comunes
     * («Señor», «Virgen», «Cristo») casa con cientos de marchas, así que el
     * LIMIT recorta; sin ORDER BY, SQLite devuelve por rowid y el recorte se
     * lleva por delante justo a las marchas de alta reciente. Eso hacía que una
     * marcha con el título EXACTO —y creada la semana pasada— saliera como «sin
     * coincidencia». Por eso se puntúa en SQL: coincidencia exacta primero,
     * luego cuántas palabras comparte, y a igualdad la de longitud más parecida.
     *
     * @return list<array<string,mixed>>
     */
    public static function candidatas(string $titulo, int $limit = 120): array
    {
        $norm = Db::noAcc(trim($titulo));
        if ($norm === '') return [];

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn(string $t): bool => mb_strlen($t) >= 4 && !in_array(mb_strtolower($t), self::VACIAS, true)
        ));
        // Título corto o todo palabras vacías ("A Ti Manué"): se busca por el
        // título entero, que sigue siendo mejor que no buscar nada.
        if ($tokens === []) $tokens = [$norm];

        $like = array_map(static fn(string $t): string => '%' . $t . '%', $tokens);
        $cond = implode(' OR ', array_fill(0, count($tokens), 'NOACC(m.TITULO) LIKE ?'));
        // Un punto por palabra compartida; el título exacto se lleva más que
        // cualquier suma de palabras y por tanto nunca se queda fuera del LIMIT.
        $puntos = 'CASE WHEN NOACC(m.TITULO) = ? THEN 100 ELSE 0 END'
            . str_repeat(' + CASE WHEN NOACC(m.TITULO) LIKE ? THEN 1 ELSE 0 END', count($tokens));

        return Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.FECHA,
                    (SELECT GROUP_CONCAT(a.NOMBRE || ' ' || a.APELLIDOS, ', ')
                       FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
                      WHERE ma.ID_MARCHA = m.ID_MARCHA) AS AUTORES
               FROM marcha m
              WHERE $cond
              ORDER BY ($puntos) DESC, ABS(LENGTH(NOACC(m.TITULO)) - ?) ASC, m.ID_MARCHA ASC
              LIMIT ?",
            [...$like, $norm, ...$like, mb_strlen($norm), $limit]
        );
    }

    /** @return array<int,true> ids de marcha ya presentes en el disco */
    private static function marchasDelDisco(int $idDisco): array
    {
        $out = [];
        foreach (Db::all('SELECT IDMARCHA FROM disco_marcha WHERE ID_DISCO = ?', [$idDisco]) as $r) {
            $out[(int) $r['IDMARCHA']] = true;
        }
        return $out;
    }

    /** @return array<string,true> claves "volumen:numero" ya ocupadas en el disco */
    private static function pistasOcupadas(int $idDisco): array
    {
        $out = [];
        foreach (Db::all('SELECT N_DISCO, NUMEROMARCHA FROM disco_marcha WHERE ID_DISCO = ?', [$idDisco]) as $r) {
            $out[((int) $r['N_DISCO']) . ':' . ((int) $r['NUMEROMARCHA'])] = true;
        }
        return $out;
    }
}
