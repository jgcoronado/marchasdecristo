<?php

declare(strict_types=1);

namespace App;

/**
 * Presentación de /acompanamientos/{localidad} (rediseño B, sep-2026): por
 * cada paso, una tira cronológica y debajo la serie completa año a año.
 *
 * Solo presentación: recibe lo que ya devuelve NominaRepo::acompanamientosPorNomina()
 * (días → hermandades → pasos con 'rangos' de Repo::agruparAcompanamientos)
 * y no consulta nada. No rellena datos: un año sin contrato sale como
 * "sin registro", nunca como continuación de la banda anterior.
 *
 * - Cada paso empieza en su primer año con registro (petición de Javier: nada
 *   de "sin registro" antes de que haya datos) y acaba en el último año con
 *   datos de la localidad, común a toda la página. "Vigente" es un rango que
 *   llega a ese último año, no el primero de la lista: con ida y vuelta hay
 *   dos rangos vigentes a la vez. Los huecos entre años con registro, y
 *   desde el último registro del paso hasta ese año final, sí salen como
 *   "sin registro".
 * - Rangos que se solapan en años (ida/vuelta, dos bandas o un error de
 *   carga) van a carriles distintos en la tira; en la serie, solo los años
 *   compartidos salen en una fila con las dos bandas, etiquetadas con el
 *   tramo o "—" si no hay tramo. No se corrige nada: se pinta tal cual está
 *   en la base de datos.
 */
final class AcompSerie
{
    /**
     * @param list<array> $dias  NominaRepo::acompanamientosPorNomina()['dias']
     * @param list<array> $fuera NominaRepo::acompanamientosPorNomina()['fuera'] (forma de agruparAcompanamientos)
     * @return array{eje:array{ini:int,fin:int,n:int}, nContratos:int, dias:list<array>}
     */
    public static function construir(array $dias, array $fuera): array
    {
        // 1) Normaliza a día → hermandad → paso{nombre, rangos}, sin vacíos.
        $norm = [];
        foreach ($dias as $d) {
            $hs = [];
            foreach ($d['hermandades'] as $h) {
                $pasos = [];
                foreach ($h['pasos'] as $p) {
                    if (($p['rangos'] ?? []) !== []) {
                        $pasos[] = ['nombre' => (string) $p['NOMBRE'], 'rangos' => $p['rangos']];
                    }
                }
                foreach ($h['sinPaso'] ?? [] as $t) {
                    if ($t['rangos'] !== []) {
                        $pasos[] = ['nombre' => (string) $t['titular'], 'rangos' => $t['rangos']];
                    }
                }
                if ($pasos !== []) {
                    $hs[] = ['slug' => (string) $h['SLUG'], 'nombre' => (string) $h['NOMBRE'], 'pasos' => $pasos];
                }
            }
            if ($hs !== []) {
                $norm[] = ['nombre' => (string) $d['NOMBRE'], 'hermandades' => $hs];
            }
        }
        $hsFuera = [];
        foreach ($fuera as $h) {
            $pasos = [];
            foreach ($h['titulares'] as $t) {
                if ($t['rangos'] !== []) {
                    $pasos[] = ['nombre' => $t['titular'] ?? 'Sin especificar', 'rangos' => $t['rangos']];
                }
            }
            if ($pasos !== []) {
                $hsFuera[] = ['slug' => (string) $h['slug'], 'nombre' => (string) $h['nombre'], 'pasos' => $pasos];
            }
        }
        if ($hsFuera !== []) {
            $norm[] = ['nombre' => 'Sin día asignado', 'hermandades' => $hsFuera];
        }

        // 2) Eje y recuento.
        $ini = null;
        $fin = null;
        $nContratos = 0;
        foreach ($norm as $d) {
            foreach ($d['hermandades'] as $h) {
                foreach ($h['pasos'] as $p) {
                    foreach ($p['rangos'] as $rg) {
                        $ini = min($ini ?? (int) $rg['anioInicio'], (int) $rg['anioInicio']);
                        $fin = max($fin ?? (int) $rg['anioFin'], (int) $rg['anioFin']);
                        $nContratos += count($rg['contratos']);
                    }
                }
            }
        }
        $fin ??= (int) date('Y');
        $ini ??= $fin;
        $eje = ['ini' => $ini, 'fin' => $fin, 'n' => $fin - $ini + 1];

        // 3) Vista.
        $usados = [];
        $outDias = [];
        foreach ($norm as $d) {
            $slugDia = self::slugUnico('d-' . Slug::slugify($d['nombre']), $usados);
            $outHs = [];
            foreach ($d['hermandades'] as $h) {
                $pasos = [];
                foreach ($h['pasos'] as $p) {
                    $pasos[] = self::paso($p['nombre'], $p['rangos'], $eje);
                }
                $outHs[] = ['slug' => $h['slug'], 'nombre' => $h['nombre'], 'pasos' => $pasos];
            }
            $outDias[] = ['nombre' => $d['nombre'], 'slug' => $slugDia, 'hermandades' => $outHs];
        }

        return ['eje' => $eje, 'nContratos' => $nContratos, 'dias' => $outDias];
    }

    /** "1991–2023" o "2003". */
    public static function anios(int $ini, int $fin): string
    {
        return $ini === $fin ? (string) $ini : $ini . '–' . $fin;
    }

    /**
     * @param list<array{anioInicio:int,anioFin:int,idBanda:int,banda:string,tramo?:?string}> $rangos
     * @param array{ini:int,fin:int,n:int} $eje
     */
    private static function paso(string $nombre, array $rangos, array $eje): array
    {
        $rs = [];
        foreach ($rangos as $rg) {
            $rs[] = [
                'ini' => (int) $rg['anioInicio'],
                'fin' => (int) $rg['anioFin'],
                'idBanda' => (int) $rg['idBanda'],
                'banda' => (string) $rg['banda'],
                'tramo' => isset($rg['tramo']) && $rg['tramo'] !== '' ? (string) $rg['tramo'] : null,
            ];
        }
        // Eje propio del paso: de su primer año con registro al año final común.
        $eje = ['ini' => min(array_column($rs, 'ini')), 'fin' => $eje['fin']];
        $eje['n'] = $eje['fin'] - $eje['ini'] + 1;
        $ordenTramo = static fn(?string $t): int => match ($t) { 'ida' => 0, 'vuelta' => 1, default => 2 };
        usort($rs, static fn(array $a, array $b): int
            => [$a['ini'], $ordenTramo($a['tramo']), $a['fin']] <=> [$b['ini'], $ordenTramo($b['tramo']), $b['fin']]);

        // Carriles: cada rango al primer carril libre (fin del último < inicio).
        $finCarril = [];
        foreach ($rs as &$r) {
            $lane = null;
            foreach ($finCarril as $k => $f) {
                if ($f < $r['ini']) { $lane = $k; break; }
            }
            $lane ??= count($finCarril);
            $finCarril[$lane] = $r['fin'];
            $r['carril'] = $lane;
            $r['vigente'] = $r['fin'] === $eje['fin'];
        }
        unset($r);

        // Tira: tono alterno dentro de cada carril (para que dos bandas
        // seguidas no se confundan) y desfasado entre carriles (para que ida y
        // vuelta no se lean como un solo bloque); el vigente en acento pleno.
        // Nada de un color por banda.
        $tira = [];
        $alterno = [];
        foreach ($rs as $r) {
            $n = $alterno[$r['carril']] = ($alterno[$r['carril']] ?? -1) + 1;
            $tira[] = [
                'left' => round(($r['ini'] - $eje['ini']) / $eje['n'] * 100, 3),
                'width' => round(($r['fin'] - $r['ini'] + 1) / $eje['n'] * 100, 3),
                'carril' => $r['carril'],
                'tono' => $r['vigente'] ? 'v' : (($n + $r['carril']) % 2 === 0 ? 'a' : 'b'),
                'title' => self::anios($r['ini'], $r['fin']) . ': ' . $r['banda'] . ($r['tramo'] !== null ? ' (' . $r['tramo'] . ')' : ''),
            ];
        }

        // Serie: una fila por tramo de años en que el conjunto de bandas no
        // cambia. Así solo se juntan bandas en los años que de verdad tienen
        // dos contratos (p. ej. 1998 con dos bandas sale como fila propia),
        // en vez de encadenar solapes de un año en un bloque largo. Los años
        // sin ningún contrato salen como "sin registro".
        $firma = static function (int $y) use ($rs): array {
            $idx = [];
            foreach ($rs as $k => $r) {
                if ($r['ini'] <= $y && $y <= $r['fin']) $idx[] = $k;
            }
            return $idx;
        };
        $filas = [];
        $actual = null;
        for ($y = $eje['ini']; $y <= $eje['fin']; $y++) {
            $idx = $firma($y);
            if ($actual !== null && $actual['idx'] === $idx) {
                $actual['fin'] = $y;
                continue;
            }
            if ($actual !== null) $filas[] = $actual;
            $actual = ['ini' => $y, 'fin' => $y, 'idx' => $idx];
        }
        if ($actual !== null) $filas[] = $actual;

        $filas = array_map(static function (array $f) use ($rs, $eje, $ordenTramo): array {
            if ($f['idx'] === []) {
                return ['ini' => $f['ini'], 'fin' => $f['fin'], 'sinRegistro' => true, 'vigente' => false, 'lineas' => []];
            }
            $multiple = count($f['idx']) > 1;
            $lineas = [];
            foreach ($f['idx'] as $k) {
                $r = $rs[$k];
                $lineas[] = [
                    'tramo' => $r['tramo'] ?? ($multiple ? '—' : null),
                    'banda' => $r['banda'],
                    'idBanda' => $r['idBanda'],
                    'anios' => null,
                    'vigente' => $r['vigente'],
                ];
            }
            usort($lineas, static fn(array $x, array $y): int
                => $ordenTramo($x['tramo'] === '—' ? null : $x['tramo']) <=> $ordenTramo($y['tramo'] === '—' ? null : $y['tramo']));
            return [
                'ini' => $f['ini'], 'fin' => $f['fin'], 'sinRegistro' => false,
                'vigente' => $f['fin'] === $eje['fin'], 'lineas' => $lineas,
            ];
        }, $filas);

        return [
            'nombre' => $nombre,
            'carriles' => max(1, count($finCarril)),
            // Líneas de década de la tira: ancho de 10 años y desfase hasta la primera década.
            'dec' => round(10 / $eje['n'] * 100, 4),
            'dec0' => round(((10 - $eje['ini'] % 10) % 10) / $eje['n'] * 100, 4),
            'tira' => $tira,
            'serie' => array_reverse($filas),
        ];
    }

    /** @param array<string,true> $usados */
    private static function slugUnico(string $slug, array &$usados): string
    {
        $s = $slug;
        for ($i = 2; isset($usados[$s]); $i++) {
            $s = $slug . '-' . $i;
        }
        $usados[$s] = true;
        return $s;
    }
}
