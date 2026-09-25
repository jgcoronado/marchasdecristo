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
 * - Eje común a toda la página: de 1980 (o el primer año con datos, si es
 *   anterior) al último año con datos de la localidad. "Vigente" es un rango
 *   que llega a ese último año, no el primero de la lista: con ida y vuelta
 *   hay dos rangos vigentes a la vez.
 * - Rangos que se solapan en años (ida/vuelta, dos bandas o un error de
 *   carga) van a carriles distintos en la tira y se agrupan en una sola fila
 *   de la serie, con la etiqueta del tramo o "—" si no hay tramo. No se
 *   corrige nada: se pinta tal cual está en la base de datos.
 */
final class AcompSerie
{
    public const EJE_INICIO = 1980;

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
        $ini = self::EJE_INICIO;
        $fin = null;
        $nContratos = 0;
        foreach ($norm as $d) {
            foreach ($d['hermandades'] as $h) {
                foreach ($h['pasos'] as $p) {
                    foreach ($p['rangos'] as $rg) {
                        $ini = min($ini, (int) $rg['anioInicio']);
                        $fin = max($fin ?? (int) $rg['anioFin'], (int) $rg['anioFin']);
                        $nContratos += count($rg['contratos']);
                    }
                }
            }
        }
        $fin ??= (int) date('Y');
        $eje = ['ini' => $ini, 'fin' => $fin, 'n' => $fin - $ini + 1];

        // 3) Vista.
        $usados = [];
        $outDias = [];
        foreach ($norm as $d) {
            $slugDia = self::slugUnico('d-' . Slug::slugify($d['nombre']), $usados);
            $outHs = [];
            foreach ($d['hermandades'] as $h) {
                $pasos = [];
                $primero = null;
                $ultimo = null;
                foreach ($h['pasos'] as $p) {
                    $vp = self::paso($p['nombre'], $p['rangos'], $eje);
                    $primero = min($primero ?? $vp['primero'], $vp['primero']);
                    $ultimo = max($ultimo ?? $vp['ultimo'], $vp['ultimo']);
                    $pasos[] = $vp;
                }
                $outHs[] = [
                    'slug' => $h['slug'],
                    'nombre' => $h['nombre'],
                    'nPasos' => count($pasos),
                    'primero' => (int) $primero,
                    'ultimo' => (int) $ultimo,
                    'pasos' => $pasos,
                ];
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

        // Serie: bloques de rangos que se solapan en años + huecos "sin registro".
        $bloques = [];
        foreach ($rs as $r) {
            $u = count($bloques) - 1;
            if ($u >= 0 && $r['ini'] <= $bloques[$u]['fin']) {
                $bloques[$u]['fin'] = max($bloques[$u]['fin'], $r['fin']);
                $bloques[$u]['rangos'][] = $r;
            } else {
                $bloques[] = ['ini' => $r['ini'], 'fin' => $r['fin'], 'rangos' => [$r]];
            }
        }
        $filas = [];
        $cursor = $eje['ini'];
        foreach ($bloques as $b) {
            if ($b['ini'] > $cursor) {
                $filas[] = ['ini' => $cursor, 'fin' => $b['ini'] - 1, 'sinRegistro' => true, 'vigente' => false, 'lineas' => []];
            }
            $multiple = count($b['rangos']) > 1;
            $lineas = [];
            foreach ($b['rangos'] as $r) {
                $lineas[] = [
                    'tramo' => $r['tramo'] ?? ($multiple ? '—' : null),
                    'banda' => $r['banda'],
                    'idBanda' => $r['idBanda'],
                    'anios' => ($r['ini'] !== $b['ini'] || $r['fin'] !== $b['fin']) ? self::anios($r['ini'], $r['fin']) : null,
                    'vigente' => $r['vigente'],
                ];
            }
            usort($lineas, static fn(array $x, array $y): int
                => $ordenTramo($x['tramo'] === '—' ? null : $x['tramo']) <=> $ordenTramo($y['tramo'] === '—' ? null : $y['tramo']));
            $filas[] = [
                'ini' => $b['ini'], 'fin' => $b['fin'], 'sinRegistro' => false,
                'vigente' => $b['fin'] === $eje['fin'], 'lineas' => $lineas,
            ];
            $cursor = $b['fin'] + 1;
        }
        if ($cursor <= $eje['fin']) {
            $filas[] = ['ini' => $cursor, 'fin' => $eje['fin'], 'sinRegistro' => true, 'vigente' => false, 'lineas' => []];
        }

        return [
            'nombre' => $nombre,
            'carriles' => max(1, count($finCarril)),
            'tira' => $tira,
            'serie' => array_reverse($filas),
            'primero' => $rs[0]['ini'],
            'ultimo' => max(array_column($rs, 'fin')),
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
