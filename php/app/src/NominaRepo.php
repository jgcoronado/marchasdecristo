<?php

declare(strict_types=1);

namespace App;

/**
 * Nómina de Semana Santa por localidad → día → hermandad → paso
 * (015_semana_santa_dia.sql, 012_hermandad_paso.sql), panel
 * /dashboard/semana-santa. Sobre esta base se colocan los acompañamientos
 * (contratos) de /dashboard/acompanamientos/{localidad} cuando la localidad
 * tiene nómina: ver "Acompañamientos sobre la nómina" al final.
 *
 * hermandad.DIA / DIA_ORDEN se mantienen como copia desnormalizada del día
 * (los lee la web pública, ver Repo::acompanamientosPorLocalidad) y se
 * sincronizan aquí en cada escritura que toque un día o mueva una hermandad.
 *
 * Una hermandad puede salir más de un día (020_hermandad_varios_dias.sql):
 * hermandad.DIA es su día principal; hermandad_dia_extra, los otros días de
 * la Semana Santa actual (paso_dia dice qué paso sale en cuál), y
 * hermandad_dia_historico, el día que tenía en años pasados.
 */
final class NominaRepo
{
    /** ¿Aplicada la migración 020? Lo público también se lee en hosts aún sin migrar. */
    public static function variosDiasDisponible(): bool
    {
        static $ok = null;
        return $ok ??= Db::one("SELECT 1 AS x FROM sqlite_master WHERE type = 'table' AND name = 'hermandad_dia_historico'") !== null;
    }

    /**
     * Expresión SQL con el día (nombre) en que salió un contrato: el día extra
     * de su paso si lo tiene; si no, el día histórico de la hermandad para el
     * año del contrato; si no, el día principal. Necesita los alias de
     * contrato, hermandad y contrato_paso de la consulta.
     */
    public static function sqlDiaDeContrato(string $c = 'c', string $h = 'h', string $cp = 'cp'): string
    {
        if (!self::variosDiasDisponible()) return "$h.DIA";
        return "COALESCE(
            (SELECT dx.NOMBRE FROM paso_dia pdx INNER JOIN semana_santa_dia dx ON dx.ID_DIA = pdx.ID_DIA WHERE pdx.ID_PASO = $cp.ID_PASO),
            (SELECT dh.NOMBRE FROM hermandad_dia_historico hh INNER JOIN semana_santa_dia dh ON dh.ID_DIA = hh.ID_DIA
              WHERE hh.ID_HERMANDAD = $h.ID_HERMANDAD AND $c.ANIO BETWEEN hh.DESDE AND hh.HASTA LIMIT 1),
            $h.DIA)";
    }

    /**
     * Localidades con nómina o acompañamientos, para el índice del panel:
     * unión de semana_santa_dia, hermandad y contrato_localidad — una
     * localidad puede tener contratos sin tener aún ningún día cargado.
     * @return list<string>
     */
    public static function localidades(): array
    {
        $rows = Db::all(
            "SELECT LOCALIDAD FROM semana_santa_dia
             UNION SELECT LOCALIDAD FROM hermandad
             UNION SELECT LOCALIDAD FROM contrato_localidad
             ORDER BY LOCALIDAD ASC"
        );
        return array_map(static fn(array $r): string => (string) $r['LOCALIDAD'], $rows);
    }

    /** Slug de ruta → LOCALIDAD literal, mismo criterio que Repo::resolverLocalidadPorSlug. */
    public static function resolverLocalidadPorSlug(string $slug): ?string
    {
        foreach (self::localidades() as $localidad) {
            if (Slug::slugify($localidad) === $slug) return $localidad;
        }
        return null;
    }

    /**
     * LOCALIDAD → PROVINCIA de localidad_provincia (019). Vacío si la tabla
     * aún no existe en el host.
     * @return array<string,string>
     */
    public static function provinciasDeLocalidades(): array
    {
        try {
            $rows = Db::all('SELECT LOCALIDAD, PROVINCIA FROM localidad_provincia');
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $r) $map[(string) $r['LOCALIDAD']] = (string) $r['PROVINCIA'];
        return $map;
    }

    /** Guarda la provincia de una localidad que aún no la tiene (no pisa la que haya). */
    public static function fijarProvincia(string $localidad, string $provincia): void
    {
        if (Db::one('SELECT 1 AS x FROM localidad_provincia WHERE LOCALIDAD = ?', [$localidad]) !== null) return;
        Db::run('INSERT INTO localidad_provincia (LOCALIDAD, PROVINCIA) VALUES (?, ?)', [$localidad, $provincia]);
        Db::logAdmin('INSERT', 'localidad_provincia', null, ['localidad' => $localidad, 'provincia' => $provincia]);
    }

    /**
     * Días de una localidad con sus hermandades y pasos, listos para pintar.
     * PUEDE_BORRAR de una hermandad exige que no tenga contratos (join por
     * HERMANDAD_SLUG, igual que la lectura pública) ni pasos con contrato_paso.
     * PUEDE_BORRAR de un paso exige que no tenga fila en contrato_paso.
     *
     * Una hermandad que sale varios días aparece en cada uno (ES_EXTRA en los
     * que no son el principal), cada vez con los pasos de ese día y su orden
     * en él. DIAS: todos sus días actuales, el principal primero;
     * historico: días de años pasados (solo en la entrada del día principal).
     *
     * @return list<array{ID_DIA:int,NOMBRE:string,ORDEN:int,
     *                     hermandades:list<array{ID_HERMANDAD:int,NOMBRE:string,SLUG:string,ORDEN:int,
     *                                             PUEDE_BORRAR:bool,ES_EXTRA:bool,
     *                                             DIAS:list<array{ID_DIA:int,NOMBRE:string}>,
     *                                             historico:list<array{ID_HISTORICO:int,ID_DIA:int,DIA:string,DESDE:int,HASTA:int}>,
     *                                             pasos:list<array{ID_PASO:int,NOMBRE:string,ORDEN:int,ES_CRUZ_GUIA:bool,PUEDE_BORRAR:bool,ID_DIA:?int}>}>}>
     */
    public static function cargarLocalidad(string $localidad): array
    {
        $dias = Db::all(
            'SELECT ID_DIA, NOMBRE, ORDEN FROM semana_santa_dia WHERE LOCALIDAD = ? ORDER BY ORDEN ASC',
            [$localidad]
        );
        $idDiaPorNombre = array_column($dias, 'ID_DIA', 'NOMBRE');
        $nombreDia = array_column($dias, 'NOMBRE', 'ID_DIA');

        $extras = [];
        $pasoDia = [];
        $historico = [];
        if (self::variosDiasDisponible()) {
            foreach (Db::all(
                'SELECT e.ID_HERMANDAD, e.ID_DIA, e.ORDEN FROM hermandad_dia_extra e
                 INNER JOIN hermandad h ON h.ID_HERMANDAD = e.ID_HERMANDAD WHERE h.LOCALIDAD = ?',
                [$localidad]
            ) as $e) {
                $extras[(int) $e['ID_HERMANDAD']][(int) $e['ID_DIA']] = (int) $e['ORDEN'];
            }
            foreach (Db::all(
                'SELECT pd.ID_PASO, pd.ID_DIA FROM paso_dia pd INNER JOIN paso p ON p.ID_PASO = pd.ID_PASO
                 INNER JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE h.LOCALIDAD = ?',
                [$localidad]
            ) as $pd) {
                $pasoDia[(int) $pd['ID_PASO']] = (int) $pd['ID_DIA'];
            }
            foreach (Db::all(
                'SELECT hh.ID_HISTORICO, hh.ID_HERMANDAD, hh.ID_DIA, d.NOMBRE AS DIA, hh.DESDE, hh.HASTA
                 FROM hermandad_dia_historico hh INNER JOIN semana_santa_dia d ON d.ID_DIA = hh.ID_DIA
                 INNER JOIN hermandad h ON h.ID_HERMANDAD = hh.ID_HERMANDAD
                 WHERE h.LOCALIDAD = ? ORDER BY hh.DESDE',
                [$localidad]
            ) as $hh) {
                $historico[(int) $hh['ID_HERMANDAD']][] = [
                    'ID_HISTORICO' => (int) $hh['ID_HISTORICO'], 'ID_DIA' => (int) $hh['ID_DIA'], 'DIA' => (string) $hh['DIA'],
                    'DESDE' => (int) $hh['DESDE'], 'HASTA' => (int) $hh['HASTA'],
                ];
            }
        }

        $hermandadesPorDia = [];
        $hermandades = Db::all(
            "SELECT h.ID_HERMANDAD, h.NOMBRE, h.SLUG, h.DIA, h.ORDEN,
                    (SELECT COUNT(*) FROM contrato c
                       INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
                      WHERE cl.LOCALIDAD = h.LOCALIDAD AND c.HERMANDAD_SLUG = h.SLUG) AS N_CONTRATOS
             FROM hermandad h WHERE h.LOCALIDAD = ? ORDER BY h.DIA_ORDEN ASC, h.ORDEN ASC",
            [$localidad]
        );
        foreach ($hermandades as $h) {
            $pasos = Db::all(
                "SELECT p.ID_PASO, p.NOMBRE, p.ORDEN, p.ES_CRUZ_GUIA,
                        (SELECT COUNT(*) FROM contrato_paso cp WHERE cp.ID_PASO = p.ID_PASO) AS N_CONTRATOS
                 FROM paso p WHERE p.ID_HERMANDAD = ? ORDER BY p.ORDEN ASC",
                [$h['ID_HERMANDAD']]
            );
            $idH = (int) $h['ID_HERMANDAD'];
            $principal = isset($idDiaPorNombre[(string) $h['DIA']]) ? (int) $idDiaPorNombre[(string) $h['DIA']] : null;
            $puedeBorrar = (int) $h['N_CONTRATOS'] === 0 && array_sum(array_column($pasos, 'N_CONTRATOS')) === 0;
            $pasos = array_map(static fn(array $p): array => [
                'ID_PASO' => (int) $p['ID_PASO'],
                'NOMBRE' => (string) $p['NOMBRE'],
                'ORDEN' => (int) $p['ORDEN'],
                'ES_CRUZ_GUIA' => (int) $p['ES_CRUZ_GUIA'] === 1,
                'PUEDE_BORRAR' => (int) $p['N_CONTRATOS'] === 0,
                'ID_DIA' => $pasoDia[(int) $p['ID_PASO']] ?? $principal,
            ], $pasos);
            $diasH = $principal !== null ? [['ID_DIA' => $principal, 'NOMBRE' => (string) $h['DIA']]] : [];
            foreach (array_keys($extras[$idH] ?? []) as $idDia) {
                if (isset($nombreDia[$idDia])) $diasH[] = ['ID_DIA' => $idDia, 'NOMBRE' => (string) $nombreDia[$idDia]];
            }
            $base = [
                'ID_HERMANDAD' => $idH,
                'NOMBRE' => (string) $h['NOMBRE'],
                'SLUG' => (string) $h['SLUG'],
                'PUEDE_BORRAR' => $puedeBorrar,
                'DIAS' => $diasH,
            ];
            $hermandadesPorDia[(string) $h['DIA']][] = $base + [
                'ORDEN' => (int) $h['ORDEN'],
                'ES_EXTRA' => false,
                'historico' => $historico[$idH] ?? [],
                'pasos' => array_values(array_filter($pasos, static fn(array $p): bool => $p['ID_DIA'] === $principal)),
            ];
            foreach ($extras[$idH] ?? [] as $idDia => $orden) {
                if (!isset($nombreDia[$idDia])) continue;
                $hermandadesPorDia[(string) $nombreDia[$idDia]][] = $base + [
                    'ORDEN' => $orden,
                    'ES_EXTRA' => true,
                    'historico' => [],
                    'pasos' => array_values(array_filter($pasos, static fn(array $p): bool => $p['ID_DIA'] === $idDia)),
                ];
            }
        }

        return array_map(static function (array $d) use ($hermandadesPorDia): array {
            $hs = $hermandadesPorDia[(string) $d['NOMBRE']] ?? [];
            // Las de otro día van detrás de las principales con el mismo ORDEN (usort es estable).
            usort($hs, static fn(array $a, array $b): int => [$a['ORDEN'], $a['ES_EXTRA']] <=> [$b['ORDEN'], $b['ES_EXTRA']]);
            return [
                'ID_DIA' => (int) $d['ID_DIA'],
                'NOMBRE' => (string) $d['NOMBRE'],
                'ORDEN' => (int) $d['ORDEN'],
                'hermandades' => $hs,
            ];
        }, $dias);
    }

    // ── Días ─────────────────────────────────────────────────────────────

    /** @return array{code:string, id?:int} */
    public static function addDia(string $localidad, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $existe = Db::one('SELECT ID_DIA FROM semana_santa_dia WHERE LOCALIDAD = ? AND NOMBRE = ?', [$localidad, $nombre]);
        if ($existe !== null) return ['code' => 'DIA_DUPLICADO'];
        $siguiente = (int) (Db::one('SELECT MAX(ORDEN) AS m FROM semana_santa_dia WHERE LOCALIDAD = ?', [$localidad])['m'] ?? 0) + 1;
        Db::run('INSERT INTO semana_santa_dia (LOCALIDAD, NOMBRE, ORDEN) VALUES (?, ?, ?)', [$localidad, $nombre, $siguiente]);
        $id = Db::lastInsertId();
        Db::logAdmin('INSERT', 'semana_santa_dia', $id, ['localidad' => $localidad, 'nombre' => $nombre]);
        return ['code' => 'CREATED', 'id' => $id];
    }

    /** Renombra el día y sincroniza hermandad.DIA de sus hermandades. */
    public static function renameDia(int $idDia, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $dia = Db::one('SELECT LOCALIDAD, NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        if ($dia === null) return ['code' => 'NOT_FOUND'];
        if ((string) $dia['NOMBRE'] === $nombre) return ['code' => 'CREATED'];
        $dup = Db::one('SELECT ID_DIA FROM semana_santa_dia WHERE LOCALIDAD = ? AND NOMBRE = ? AND ID_DIA != ?', [$dia['LOCALIDAD'], $nombre, $idDia]);
        if ($dup !== null) return ['code' => 'DIA_DUPLICADO'];
        Db::transaction(function () use ($idDia, $dia, $nombre) {
            Db::run('UPDATE semana_santa_dia SET NOMBRE = ? WHERE ID_DIA = ?', [$nombre, $idDia]);
            Db::run('UPDATE hermandad SET DIA = ? WHERE LOCALIDAD = ? AND DIA = ?', [$nombre, $dia['LOCALIDAD'], $dia['NOMBRE']]);
        });
        Db::logAdmin('UPDATE', 'semana_santa_dia', $idDia, ['nombre' => $nombre]);
        return ['code' => 'CREATED'];
    }

    /** Solo si no tiene hermandades — igual que borrar una hermandad con contratos, no se inventa un vaciado en cascada. */
    public static function deleteDia(int $idDia): array
    {
        $dia = Db::one('SELECT LOCALIDAD, NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        if ($dia === null) return ['code' => 'NOT_FOUND'];
        $tiene = Db::one('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND DIA = ?', [$dia['LOCALIDAD'], $dia['NOMBRE']]);
        if ($tiene !== null) return ['code' => 'DIA_NO_VACIO'];
        // Tampoco si lo usa una hermandad como día extra o como día de otros años.
        if (self::variosDiasDisponible() && Db::one(
            'SELECT 1 FROM hermandad_dia_extra WHERE ID_DIA = ? UNION ALL SELECT 1 FROM hermandad_dia_historico WHERE ID_DIA = ? LIMIT 1',
            [$idDia, $idDia]
        ) !== null) return ['code' => 'DIA_NO_VACIO'];
        Db::run('DELETE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        Db::logAdmin('DELETE', 'semana_santa_dia', $idDia, null);
        return ['code' => 'DELETED'];
    }

    /**
     * Reordena los días de una localidad: reescribe ORDEN = 1..n según el
     * orden de $idsDia, y sincroniza hermandad.DIA_ORDEN de cada uno.
     * @param list<int> $idsDia
     */
    public static function reorderDias(string $localidad, array $idsDia): array
    {
        if ($idsDia === []) return ['code' => 'NOT_FOUND'];
        Db::transaction(function () use ($localidad, $idsDia) {
            $orden = 1;
            foreach ($idsDia as $idDia) {
                $dia = Db::one('SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $localidad]);
                if ($dia === null) continue;
                Db::run('UPDATE semana_santa_dia SET ORDEN = ? WHERE ID_DIA = ?', [$orden, $idDia]);
                Db::run('UPDATE hermandad SET DIA_ORDEN = ? WHERE LOCALIDAD = ? AND DIA = ?', [$orden, $localidad, $dia['NOMBRE']]);
                $orden++;
            }
        });
        Db::logAdmin('REORDER', 'semana_santa_dia', null, ['localidad' => $localidad, 'ids' => $idsDia]);
        return ['code' => 'REORDERED'];
    }

    /** Respaldo sin JS: intercambia el ORDEN de un día con su vecino inmediato. */
    public static function swapDiaConVecino(int $idDia, string $direccion): array
    {
        $dia = Db::one('SELECT LOCALIDAD, NOMBRE, ORDEN FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        if ($dia === null) return ['code' => 'NOT_FOUND'];
        $cmp = $direccion === 'up' ? '<' : '>';
        $dir = $direccion === 'up' ? 'DESC' : 'ASC';
        $vecino = Db::one(
            "SELECT ID_DIA, NOMBRE, ORDEN FROM semana_santa_dia WHERE LOCALIDAD = ? AND ORDEN $cmp ? ORDER BY ORDEN $dir LIMIT 1",
            [$dia['LOCALIDAD'], $dia['ORDEN']]
        );
        if ($vecino === null) return ['code' => 'SIN_VECINO'];
        Db::transaction(function () use ($dia, $vecino, $idDia) {
            $localidad = $dia['LOCALIDAD'];
            Db::run('UPDATE semana_santa_dia SET ORDEN = ? WHERE ID_DIA = ?', [$vecino['ORDEN'], $idDia]);
            Db::run('UPDATE semana_santa_dia SET ORDEN = ? WHERE ID_DIA = ?', [$dia['ORDEN'], $vecino['ID_DIA']]);
            Db::run('UPDATE hermandad SET DIA_ORDEN = ? WHERE LOCALIDAD = ? AND DIA = ?', [$vecino['ORDEN'], $localidad, $dia['NOMBRE']]);
            Db::run('UPDATE hermandad SET DIA_ORDEN = ? WHERE LOCALIDAD = ? AND DIA = ?', [$dia['ORDEN'], $localidad, $vecino['NOMBRE']]);
        });
        Db::logAdmin('REORDER', 'semana_santa_dia', $idDia, ['swap_con' => $vecino['ID_DIA']]);
        return ['code' => 'REORDERED'];
    }

    // ── Hermandades ──────────────────────────────────────────────────────

    /**
     * Si la hermandad ya existe en la localidad (por SLUG o por nombre, sin
     * tildes) y sale otro día, no se duplica: pasa a salir también este día
     * (addDiaExtra) y se devuelve su id con diaExtra = true.
     * @return array{code:string, id?:int, diaExtra?:bool}
     */
    public static function addHermandad(string $localidad, int $idDia, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $dia = Db::one('SELECT NOMBRE, ORDEN FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $localidad]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        $slug = Slug::slugify($nombre);
        if ($slug === '') return ['code' => 'NOMBRE_INVALIDO'];
        $dup = self::buscarHermandad($localidad, $nombre);
        if ($dup !== null) {
            if (!self::variosDiasDisponible()) return ['code' => 'HERMANDAD_DUPLICADA'];
            $r = self::addDiaExtra((int) $dup['ID_HERMANDAD'], $idDia);
            return $r['code'] === 'CREATED' ? ['code' => 'CREATED', 'id' => (int) $dup['ID_HERMANDAD'], 'diaExtra' => true] : $r;
        }
        $siguiente = self::siguienteOrdenEnDia($localidad, $idDia, (string) $dia['NOMBRE']);
        Db::run(
            'INSERT INTO hermandad (LOCALIDAD, NOMBRE, SLUG, DIA, DIA_ORDEN, ORDEN, FUENTE) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$localidad, $nombre, $slug, $dia['NOMBRE'], $dia['ORDEN'], $siguiente, 'panel admin']
        );
        $id = Db::lastInsertId();
        Db::logAdmin('INSERT', 'hermandad', $id, ['localidad' => $localidad, 'nombre' => $nombre]);
        return ['code' => 'CREATED', 'id' => $id];
    }

    /** Renombra la hermandad. El SLUG queda fijo: es la juntura con contrato.HERMANDAD_SLUG. */
    public static function renameHermandad(int $idHermandad, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $cambios = Db::run('UPDATE hermandad SET NOMBRE = ? WHERE ID_HERMANDAD = ?', [$nombre, $idHermandad]);
        if ($cambios === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('UPDATE', 'hermandad', $idHermandad, ['nombre' => $nombre]);
        return ['code' => 'UPDATED'];
    }

    /** Bloqueada si hay contratos (por HERMANDAD_SLUG) o pasos con contrato_paso; si no, borra sus pasos y la hermandad. */
    public static function deleteHermandad(int $idHermandad): array
    {
        $h = Db::one('SELECT LOCALIDAD, SLUG FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $contratos = Db::one(
            "SELECT c.ID_CONTRATO FROM contrato c
               INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
              WHERE cl.LOCALIDAD = ? AND c.HERMANDAD_SLUG = ? LIMIT 1",
            [$h['LOCALIDAD'], $h['SLUG']]
        );
        if ($contratos !== null) return ['code' => 'BLOQUEADO_CONTRATOS'];
        $pasoConContrato = Db::one(
            'SELECT cp.ID_CONTRATO FROM contrato_paso cp
               INNER JOIN paso p ON p.ID_PASO = cp.ID_PASO
              WHERE p.ID_HERMANDAD = ? LIMIT 1',
            [$idHermandad]
        );
        if ($pasoConContrato !== null) return ['code' => 'BLOQUEADO_CONTRATOS'];
        Db::transaction(function () use ($idHermandad) {
            Db::run('DELETE FROM hermandad_ida_vuelta WHERE ID_HERMANDAD = ?', [$idHermandad]);
            if (self::variosDiasDisponible()) {
                Db::run('DELETE FROM paso_dia WHERE ID_PASO IN (SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ?)', [$idHermandad]);
                Db::run('DELETE FROM hermandad_dia_extra WHERE ID_HERMANDAD = ?', [$idHermandad]);
                Db::run('DELETE FROM hermandad_dia_historico WHERE ID_HERMANDAD = ?', [$idHermandad]);
            }
            Db::run('DELETE FROM paso WHERE ID_HERMANDAD = ?', [$idHermandad]);
            Db::run('DELETE FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        });
        Db::logAdmin('DELETE', 'hermandad', $idHermandad, null);
        return ['code' => 'DELETED'];
    }

    /** Siguiente ORDEN libre en un día: detrás de sus hermandades principales y de las que salen ese día como extra. */
    private static function siguienteOrdenEnDia(string $localidad, int $idDia, string $nombreDia): int
    {
        $max = (int) (Db::one('SELECT MAX(ORDEN) AS m FROM hermandad WHERE LOCALIDAD = ? AND DIA = ?', [$localidad, $nombreDia])['m'] ?? 0);
        if (self::variosDiasDisponible()) {
            $max = max($max, (int) (Db::one('SELECT MAX(ORDEN) AS m FROM hermandad_dia_extra WHERE ID_DIA = ?', [$idDia])['m'] ?? 0));
        }
        return $max + 1;
    }

    /** Días extra de la hermandad: ID_DIA → ORDEN. */
    private static function diasExtra(int $idHermandad): array
    {
        if (!self::variosDiasDisponible()) return [];
        return array_map('intval', array_column(
            Db::all('SELECT ID_DIA, ORDEN FROM hermandad_dia_extra WHERE ID_HERMANDAD = ?', [$idHermandad]), 'ORDEN', 'ID_DIA'
        ));
    }

    /** Mueve la hermandad a otro día de la misma localidad: DIA/DIA_ORDEN al del destino, ORDEN = último+1. */
    public static function moveHermandad(int $idHermandad, int $idDiaDestino): array
    {
        $h = Db::one('SELECT LOCALIDAD FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT NOMBRE, ORDEN FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDiaDestino, $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        // Ya sale ese día como extra: serían dos entradas en el mismo día.
        if (isset(self::diasExtra($idHermandad)[$idDiaDestino])) return ['code' => 'YA_SALE_ESE_DIA'];
        $siguiente = self::siguienteOrdenEnDia((string) $h['LOCALIDAD'], $idDiaDestino, (string) $dia['NOMBRE']);
        Db::run(
            'UPDATE hermandad SET DIA = ?, DIA_ORDEN = ?, ORDEN = ? WHERE ID_HERMANDAD = ?',
            [$dia['NOMBRE'], $dia['ORDEN'], $siguiente, $idHermandad]
        );
        Db::logAdmin('UPDATE', 'hermandad', $idHermandad, ['dia_destino' => $dia['NOMBRE']]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Reordena las hermandades de un día: reescribe ORDEN = 1..n según el
     * orden de $idsHermandad — en hermandad si ese es su día principal, en
     * hermandad_dia_extra si sale ese día como extra.
     * @param list<int> $idsHermandad
     */
    public static function reorderHermandades(int $idDia, array $idsHermandad): array
    {
        if ($idsHermandad === []) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT LOCALIDAD, NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        $extra = self::variosDiasDisponible();
        Db::transaction(function () use ($dia, $idDia, $idsHermandad, $extra) {
            $orden = 1;
            foreach ($idsHermandad as $idHermandad) {
                $n = Db::run(
                    'UPDATE hermandad SET ORDEN = ? WHERE ID_HERMANDAD = ? AND LOCALIDAD = ? AND DIA = ?',
                    [$orden, $idHermandad, $dia['LOCALIDAD'], $dia['NOMBRE']]
                );
                if ($n === 0 && $extra) {
                    Db::run('UPDATE hermandad_dia_extra SET ORDEN = ? WHERE ID_HERMANDAD = ? AND ID_DIA = ?', [$orden, $idHermandad, $idDia]);
                }
                $orden++;
            }
        });
        Db::logAdmin('REORDER', 'hermandad', null, ['dia' => $idDia, 'ids' => $idsHermandad]);
        return ['code' => 'REORDERED'];
    }

    /**
     * Respaldo sin JS: intercambia una hermandad con su vecina en un día
     * ($idDia; null = su día principal). Reescribe el orden del día entero
     * con reorderHermandades(), porque principales y extras comparten día.
     */
    public static function swapHermandadConVecino(int $idHermandad, string $direccion, ?int $idDia = null): array
    {
        $h = Db::one('SELECT LOCALIDAD, DIA FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $dia = $idDia !== null
            ? Db::one('SELECT ID_DIA, NOMBRE FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $h['LOCALIDAD']])
            : Db::one('SELECT ID_DIA, NOMBRE FROM semana_santa_dia WHERE NOMBRE = ? AND LOCALIDAD = ?', [$h['DIA'], $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        $ids = [];
        foreach (self::cargarLocalidad((string) $h['LOCALIDAD']) as $d) {
            if ($d['ID_DIA'] === (int) $dia['ID_DIA']) $ids = array_column($d['hermandades'], 'ID_HERMANDAD');
        }
        $i = array_search($idHermandad, $ids, true);
        if ($i === false) return ['code' => 'NOT_FOUND'];
        $j = $direccion === 'up' ? $i - 1 : $i + 1;
        if (!isset($ids[$j])) return ['code' => 'SIN_VECINO'];
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        return self::reorderHermandades((int) $dia['ID_DIA'], $ids);
    }

    // ── Hermandad en varios días (020_hermandad_varios_dias.sql) ─────────

    /** La hermandad sale también $idDia (al final de ese día). Nunca su día principal. */
    public static function addDiaExtra(int $idHermandad, int $idDia): array
    {
        $h = Db::one('SELECT LOCALIDAD, DIA FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        if ((string) $dia['NOMBRE'] === (string) $h['DIA'] || isset(self::diasExtra($idHermandad)[$idDia])) return ['code' => 'YA_SALE_ESE_DIA'];
        Db::run(
            'INSERT INTO hermandad_dia_extra (ID_HERMANDAD, ID_DIA, ORDEN) VALUES (?, ?, ?)',
            [$idHermandad, $idDia, self::siguienteOrdenEnDia((string) $h['LOCALIDAD'], $idDia, (string) $dia['NOMBRE'])]
        );
        Db::logAdmin('INSERT', 'hermandad_dia_extra', $idHermandad, ['dia' => $dia['NOMBRE']]);
        return ['code' => 'CREATED'];
    }

    /** Deja de salir ese día extra; sus pasos vuelven al día principal (no se borra nada más). */
    public static function removeDiaExtra(int $idHermandad, int $idDia): array
    {
        if (!isset(self::diasExtra($idHermandad)[$idDia])) return ['code' => 'NOT_FOUND'];
        Db::transaction(function () use ($idHermandad, $idDia) {
            Db::run(
                'DELETE FROM paso_dia WHERE ID_DIA = ? AND ID_PASO IN (SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ?)',
                [$idDia, $idHermandad]
            );
            Db::run('DELETE FROM hermandad_dia_extra WHERE ID_HERMANDAD = ? AND ID_DIA = ?', [$idHermandad, $idDia]);
        });
        Db::logAdmin('DELETE', 'hermandad_dia_extra', $idHermandad, ['dia' => $idDia]);
        return ['code' => 'DELETED'];
    }

    /** Cambia un día extra por otro, con sus pasos (al final del día destino). */
    public static function moveDiaExtra(int $idHermandad, int $idDiaOrigen, int $idDiaDestino): array
    {
        if ($idDiaOrigen === $idDiaDestino) return ['code' => 'UPDATED'];
        if (!isset(self::diasExtra($idHermandad)[$idDiaOrigen])) return ['code' => 'NOT_FOUND'];
        $h = Db::one('SELECT LOCALIDAD, DIA FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        $dia = Db::one('SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDiaDestino, $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        if ((string) $dia['NOMBRE'] === (string) $h['DIA'] || isset(self::diasExtra($idHermandad)[$idDiaDestino])) return ['code' => 'YA_SALE_ESE_DIA'];
        $orden = self::siguienteOrdenEnDia((string) $h['LOCALIDAD'], $idDiaDestino, (string) $dia['NOMBRE']);
        Db::transaction(function () use ($idHermandad, $idDiaOrigen, $idDiaDestino, $orden) {
            Db::run(
                'UPDATE hermandad_dia_extra SET ID_DIA = ?, ORDEN = ? WHERE ID_HERMANDAD = ? AND ID_DIA = ?',
                [$idDiaDestino, $orden, $idHermandad, $idDiaOrigen]
            );
            Db::run(
                'UPDATE paso_dia SET ID_DIA = ? WHERE ID_DIA = ? AND ID_PASO IN (SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ?)',
                [$idDiaDestino, $idDiaOrigen, $idHermandad]
            );
        });
        Db::logAdmin('UPDATE', 'hermandad_dia_extra', $idHermandad, ['de' => $idDiaOrigen, 'a' => $idDiaDestino]);
        return ['code' => 'UPDATED'];
    }

    /** Día en que sale un paso: el principal de su hermandad o uno de sus días extra. */
    public static function setPasoDia(int $idPaso, int $idDia): array
    {
        $p = Db::one(
            'SELECT p.ID_HERMANDAD, h.LOCALIDAD, h.DIA FROM paso p INNER JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE p.ID_PASO = ?',
            [$idPaso]
        );
        if ($p === null) return ['code' => 'NOT_FOUND'];
        $principal = Db::one('SELECT ID_DIA FROM semana_santa_dia WHERE LOCALIDAD = ? AND NOMBRE = ?', [$p['LOCALIDAD'], $p['DIA']]);
        if ($principal !== null && (int) $principal['ID_DIA'] === $idDia) {
            Db::run('DELETE FROM paso_dia WHERE ID_PASO = ?', [$idPaso]);
        } elseif (isset(self::diasExtra((int) $p['ID_HERMANDAD'])[$idDia])) {
            Db::run('INSERT OR REPLACE INTO paso_dia (ID_PASO, ID_DIA) VALUES (?, ?)', [$idPaso, $idDia]);
        } else {
            return ['code' => 'DIA_NOT_FOUND'];
        }
        Db::logAdmin('UPDATE', 'paso_dia', $idPaso, ['dia' => $idDia]);
        return ['code' => 'UPDATED'];
    }

    /**
     * En los años $desde..$hasta la hermandad salía $idDia en vez de su día
     * principal. No se solapa con otro rango suyo: en un año solo hay un día.
     */
    public static function addDiaHistorico(int $idHermandad, int $idDia, int $desde, int $hasta): array
    {
        $h = Db::one('SELECT LOCALIDAD, DIA FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        if ((string) $dia['NOMBRE'] === (string) $h['DIA']) return ['code' => 'ES_EL_DIA_ACTUAL'];
        if ($desde < 1900 || $hasta > 2100 || $desde > $hasta) return ['code' => 'INVALID_RANGO'];
        $solapa = Db::one(
            'SELECT 1 FROM hermandad_dia_historico WHERE ID_HERMANDAD = ? AND DESDE <= ? AND HASTA >= ?',
            [$idHermandad, $hasta, $desde]
        );
        if ($solapa !== null) return ['code' => 'RANGO_SOLAPADO'];
        Db::run(
            'INSERT INTO hermandad_dia_historico (ID_HERMANDAD, ID_DIA, DESDE, HASTA) VALUES (?, ?, ?, ?)',
            [$idHermandad, $idDia, $desde, $hasta]
        );
        $id = Db::lastInsertId();
        Db::logAdmin('INSERT', 'hermandad_dia_historico', $id, ['hermandad' => $idHermandad, 'dia' => $dia['NOMBRE'], 'desde' => $desde, 'hasta' => $hasta]);
        return ['code' => 'CREATED', 'id' => $id];
    }

    public static function deleteDiaHistorico(int $idHistorico): array
    {
        $n = Db::run('DELETE FROM hermandad_dia_historico WHERE ID_HISTORICO = ?', [$idHistorico]);
        if ($n === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('DELETE', 'hermandad_dia_historico', $idHistorico, null);
        return ['code' => 'DELETED'];
    }

    // ── Pasos ────────────────────────────────────────────────────────────

    /**
     * $idDia: día extra de la hermandad en que sale el paso (null = el principal).
     * @return array{code:string, id?:int}
     */
    public static function addPaso(int $idHermandad, string $nombre, bool $esCruzGuia, ?int $idDia = null): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $h = Db::one('SELECT ID_HERMANDAD FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        if ($idDia !== null && !isset(self::diasExtra($idHermandad)[$idDia])) $idDia = null; // su día principal
        $dup = Db::one('SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ? AND NOMBRE = ?', [$idHermandad, $nombre]);
        if ($dup !== null) return ['code' => 'PASO_DUPLICADO'];
        if ($esCruzGuia) {
            $yaTiene = Db::one('SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ? AND ES_CRUZ_GUIA = 1', [$idHermandad]);
            if ($yaTiene !== null) return ['code' => 'YA_TIENE_CRUZ_GUIA'];
            $orden = 0;
        } else {
            $orden = (int) (Db::one('SELECT MAX(ORDEN) AS m FROM paso WHERE ID_HERMANDAD = ? AND ES_CRUZ_GUIA = 0', [$idHermandad])['m'] ?? 0) + 1;
        }
        Db::run(
            'INSERT INTO paso (ID_HERMANDAD, NOMBRE, ORDEN, ES_CRUZ_GUIA) VALUES (?, ?, ?, ?)',
            [$idHermandad, $nombre, $orden, $esCruzGuia ? 1 : 0]
        );
        $id = Db::lastInsertId();
        if ($idDia !== null) Db::run('INSERT INTO paso_dia (ID_PASO, ID_DIA) VALUES (?, ?)', [$id, $idDia]);
        Db::logAdmin('INSERT', 'paso', $id, ['hermandad' => $idHermandad, 'nombre' => $nombre, 'dia' => $idDia]);
        return ['code' => 'CREATED', 'id' => $id];
    }

    public static function renamePaso(int $idPaso, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $p = Db::one('SELECT ID_HERMANDAD FROM paso WHERE ID_PASO = ?', [$idPaso]);
        if ($p === null) return ['code' => 'NOT_FOUND'];
        $dup = Db::one('SELECT ID_PASO FROM paso WHERE ID_HERMANDAD = ? AND NOMBRE = ? AND ID_PASO != ?', [$p['ID_HERMANDAD'], $nombre, $idPaso]);
        if ($dup !== null) return ['code' => 'PASO_DUPLICADO'];
        Db::run('UPDATE paso SET NOMBRE = ? WHERE ID_PASO = ?', [$nombre, $idPaso]);
        Db::logAdmin('UPDATE', 'paso', $idPaso, ['nombre' => $nombre]);
        return ['code' => 'UPDATED'];
    }

    /** Bloqueado si tiene filas en contrato_paso. */
    public static function deletePaso(int $idPaso): array
    {
        $tiene = Db::one('SELECT ID_CONTRATO FROM contrato_paso WHERE ID_PASO = ? LIMIT 1', [$idPaso]);
        if ($tiene !== null) return ['code' => 'BLOQUEADO_CONTRATOS'];
        if (self::variosDiasDisponible()) Db::run('DELETE FROM paso_dia WHERE ID_PASO = ?', [$idPaso]);
        $borrados = Db::run('DELETE FROM paso WHERE ID_PASO = ?', [$idPaso]);
        if ($borrados === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('DELETE', 'paso', $idPaso, null);
        return ['code' => 'DELETED'];
    }

    /**
     * Reordena los pasos reales (sin cruz de guía) de una hermandad: 1..n.
     * La cruz de guía nunca se reordena (siempre ORDEN=0, primera).
     * @param list<int> $idsPaso
     */
    public static function reorderPasos(int $idHermandad, array $idsPaso): array
    {
        if ($idsPaso === []) return ['code' => 'NOT_FOUND'];
        Db::transaction(function () use ($idHermandad, $idsPaso) {
            $orden = 1;
            foreach ($idsPaso as $idPaso) {
                Db::run(
                    'UPDATE paso SET ORDEN = ? WHERE ID_PASO = ? AND ID_HERMANDAD = ? AND ES_CRUZ_GUIA = 0',
                    [$orden, $idPaso, $idHermandad]
                );
                $orden++;
            }
        });
        Db::logAdmin('REORDER', 'paso', null, ['hermandad' => $idHermandad, 'ids' => $idsPaso]);
        return ['code' => 'REORDERED'];
    }

    /** Respaldo sin JS: intercambia el ORDEN de un paso (nunca la cruz de guía) con su vecino del mismo día. */
    public static function swapPasoConVecino(int $idPaso, string $direccion): array
    {
        $p = Db::one('SELECT ID_HERMANDAD, ORDEN FROM paso WHERE ID_PASO = ? AND ES_CRUZ_GUIA = 0', [$idPaso]);
        if ($p === null) return ['code' => 'NOT_FOUND'];
        $cmp = $direccion === 'up' ? '<' : '>';
        $dir = $direccion === 'up' ? 'DESC' : 'ASC';
        // Solo entre pasos del mismo día: los de un día extra se ven en otra caja.
        $mismoDia = self::variosDiasDisponible()
            ? ' AND IFNULL((SELECT ID_DIA FROM paso_dia WHERE ID_PASO = paso.ID_PASO), 0) = IFNULL((SELECT ID_DIA FROM paso_dia WHERE ID_PASO = ?), 0)'
            : '';
        $vecino = Db::one(
            "SELECT ID_PASO, ORDEN FROM paso WHERE ID_HERMANDAD = ? AND ES_CRUZ_GUIA = 0 AND ORDEN $cmp ?$mismoDia ORDER BY ORDEN $dir LIMIT 1",
            $mismoDia !== '' ? [$p['ID_HERMANDAD'], $p['ORDEN'], $idPaso] : [$p['ID_HERMANDAD'], $p['ORDEN']]
        );
        if ($vecino === null) return ['code' => 'SIN_VECINO'];
        Db::transaction(function () use ($p, $vecino, $idPaso) {
            Db::run('UPDATE paso SET ORDEN = ? WHERE ID_PASO = ?', [$vecino['ORDEN'], $idPaso]);
            Db::run('UPDATE paso SET ORDEN = ? WHERE ID_PASO = ?', [$p['ORDEN'], $vecino['ID_PASO']]);
        });
        Db::logAdmin('REORDER', 'paso', $idPaso, ['swap_con' => $vecino['ID_PASO']]);
        return ['code' => 'REORDERED'];
    }

    // ── Acompañamientos sobre la nómina ──────────────────────────────────
    // Un contrato cuelga de un paso por contrato_paso (012). Convención ya
    // usada por las cargas de Córdoba/Jerez: un contrato enlazado lleva
    // TITULAR = paso.NOMBRE y HERMANDAD_SLUG = hermandad.SLUG, así la página
    // pública (que agrupa por HERMANDAD_SLUG + TITULAR) refleja el paso.

    public static function tieneNomina(string $localidad): bool
    {
        return Db::one('SELECT 1 FROM hermandad WHERE LOCALIDAD = ? LIMIT 1', [$localidad]) !== null;
    }

    /**
     * Contratos de la localidad colocados sobre su nómina: cargarLocalidad()
     * con 'rangos' en cada paso y 'sinPaso' en cada hermandad (contratos de
     * una hermandad de la nómina sin enlace a paso, agrupados por su TITULAR
     * tal cual), más 'fuera': hermandades con contratos cuyo SLUG no está en
     * la nómina, con la forma de Repo::agruparAcompanamientos().
     * @return array{dias:list<array>, fuera:list<array>}
     */
    public static function acompanamientosPorNomina(string $localidad, ?int $anio = null): array
    {
        // $anio: vista por año del panel — solo los contratos de ese año, pero
        // la nómina entera (días, hermandades y pasos) se pinta igual.
        $filtroAnio = $anio !== null ? ' AND c.ANIO = ?' : '';
        $rows = Db::all(
            "SELECT c.ID_CONTRATO, c.HERMANDAD, c.HERMANDAD_SLUG, c.TITULAR, c.ANIO,
                    b.ID_BANDA, (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
                    h.ID_HERMANDAD, p.ID_PASO, ct.TRAMO,
                    (hiv.ID_HERMANDAD IS NOT NULL) AS IDA_VUELTA
             FROM contrato c
             INNER JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN hermandad h ON h.LOCALIDAD = cl.LOCALIDAD AND h.SLUG = c.HERMANDAD_SLUG
             LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN paso p ON p.ID_PASO = cp.ID_PASO AND p.ID_HERMANDAD = h.ID_HERMANDAD
             LEFT JOIN contrato_tramo ct ON ct.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN hermandad_ida_vuelta hiv ON hiv.ID_HERMANDAD = h.ID_HERMANDAD
             WHERE cl.LOCALIDAD = ?$filtroAnio AND " . Repo::sqlContratoSinCruzDeGuia() . "
             ORDER BY c.HERMANDAD_SLUG ASC, c.ANIO ASC",
            $anio !== null ? [$localidad, $anio] : [$localidad]
        );

        // Contratos sin fila en contrato_paso cuyo TITULAR es el nombre de un
        // paso de su hermandad (sin tildes/mayúsculas): es el mismo paso — la
        // página pública ya los agrupa juntos por TITULAR —, así que se pintan
        // en él. Solo presentación; el enlace se escribe si se mueven.
        $pasoPorNombre = [];
        foreach (Db::all(
            'SELECT p.ID_PASO, p.NOMBRE, p.ID_HERMANDAD FROM paso p
             INNER JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE h.LOCALIDAD = ?',
            [$localidad]
        ) as $p) {
            $pasoPorNombre[(int) $p['ID_HERMANDAD']][Slug::slugify((string) $p['NOMBRE'])] = (int) $p['ID_PASO'];
        }

        $porPaso = [];
        $sinPaso = [];
        $fuera = [];
        foreach ($rows as $r) {
            // El tramo solo cuenta si la hermandad está marcada como ida/vuelta:
            // desmarcarla no borra el dato, pero deja de partir las líneas.
            if (!(int) $r['IDA_VUELTA']) $r['TRAMO'] = null;
            if ($r['ID_PASO'] === null && $r['ID_HERMANDAD'] !== null) {
                $r['ID_PASO'] = $pasoPorNombre[(int) $r['ID_HERMANDAD']][Slug::slugify((string) $r['TITULAR'])] ?? null;
            }
            if ($r['ID_PASO'] !== null) {
                $r['TITULAR'] = null; // un solo grupo por paso, sea cual sea la redacción del TITULAR
                $porPaso[(int) $r['ID_PASO']][] = $r;
            } elseif ($r['ID_HERMANDAD'] !== null) {
                $sinPaso[(int) $r['ID_HERMANDAD']][] = $r;
            } else {
                $fuera[] = $r;
            }
        }

        $idaVuelta = array_flip(array_map('intval', array_column(Db::all(
            'SELECT hiv.ID_HERMANDAD FROM hermandad_ida_vuelta hiv
             INNER JOIN hermandad h ON h.ID_HERMANDAD = hiv.ID_HERMANDAD WHERE h.LOCALIDAD = ?',
            [$localidad]
        ), 'ID_HERMANDAD')));

        $dias = self::cargarLocalidad($localidad);
        foreach ($dias as &$d) {
            foreach ($d['hermandades'] as &$h) {
                // Cruces de guía ocultas de momento (ver Repo::sqlSinCruzDeGuia).
                $h['pasos'] = array_values(array_filter($h['pasos'], static fn(array $p): bool => !$p['ES_CRUZ_GUIA']));
                foreach ($h['pasos'] as &$p) {
                    $g = isset($porPaso[$p['ID_PASO']]) ? Repo::agruparAcompanamientos($porPaso[$p['ID_PASO']]) : [];
                    $p['rangos'] = $g[0]['titulares'][0]['rangos'] ?? [];
                }
                unset($p);
                $h['IDA_VUELTA'] = isset($idaVuelta[$h['ID_HERMANDAD']]);
                $h['sinPaso'] = [];
                // Sin paso no se sabe el día: van solo con la entrada del día principal.
                if (isset($sinPaso[$h['ID_HERMANDAD']]) && !$h['ES_EXTRA']) {
                    $filas = $sinPaso[$h['ID_HERMANDAD']];
                    foreach (Repo::agruparAcompanamientos($filas)[0]['titulares'] as $t) {
                        // Con un único TITULAR agruparAcompanamientos() lo deja en null: aquí sí se enseña.
                        if ($t['titular'] === null) {
                            $t['titular'] = trim((string) ($filas[0]['TITULAR'] ?? '')) ?: 'Sin especificar';
                        }
                        $h['sinPaso'][] = $t;
                    }
                }
            }
            unset($h);
        }
        unset($d);

        return ['dias' => $dias, 'fuera' => Repo::agruparAcompanamientos($fuera)];
    }

    /** Paso + su hermandad, sólo si es de esta localidad. */
    public static function pasoDeLocalidad(int $idPaso, string $localidad): ?array
    {
        return Db::one(
            'SELECT p.ID_PASO, p.NOMBRE, h.ID_HERMANDAD, h.NOMBRE AS HERMANDAD, h.SLUG
             FROM paso p INNER JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD
             WHERE p.ID_PASO = ? AND h.LOCALIDAD = ?',
            [$idPaso, $localidad]
        );
    }

    /**
     * Mueve contratos (una línea de rango o una selección) a un paso de la
     * nómina de la misma localidad, también de otra hermandad o de otro día:
     * reescribe HERMANDAD/HERMANDAD_SLUG/TITULAR según la convención de
     * arriba y crea o cambia su fila de contrato_paso. No borra nada. Se
     * niega si en el paso destino ya hay la misma banda el mismo año (sería
     * un duplicado; se resuelve borrando a mano uno de los dos).
     * @param list<int> $ids
     */
    public static function moverContratosAPaso(string $localidad, array $ids, int $idPaso): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) return ['code' => 'SIN_SELECCION'];
        $paso = self::pasoDeLocalidad($idPaso, $localidad);
        if ($paso === null) return ['code' => 'PASO_INVALIDO'];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = Db::one("SELECT COUNT(*) AS N FROM contrato_localidad WHERE LOCALIDAD = ? AND ID_CONTRATO IN ($in)", [$localidad, ...$ids]);
        if ((int) ($n['N'] ?? 0) !== count($ids)) return ['code' => 'CONTRATO_INVALIDO'];

        // En el destino cuentan los enlazados por contrato_paso y los que se
        // pintan en él por nombre (TITULAR = paso.NOMBRE, sin enlace).
        $dup = Db::one(
            "SELECT 1 FROM contrato c
             WHERE c.ID_CONTRATO IN ($in)
               AND EXISTS (SELECT 1 FROM contrato c2
                           INNER JOIN contrato_localidad cl2 ON cl2.ID_CONTRATO = c2.ID_CONTRATO AND cl2.LOCALIDAD = ?
                           LEFT JOIN contrato_paso cp2 ON cp2.ID_CONTRATO = c2.ID_CONTRATO
                           WHERE c2.ID_BANDA = c.ID_BANDA AND c2.ANIO = c.ANIO
                             AND c2.ID_CONTRATO NOT IN ($in)
                             AND (cp2.ID_PASO = ?
                                  OR (cp2.ID_CONTRATO IS NULL AND c2.HERMANDAD_SLUG = ? AND c2.TITULAR = ?)))
             LIMIT 1",
            [...$ids, $localidad, ...$ids, $idPaso, $paso['SLUG'], $paso['NOMBRE']]
        );
        if ($dup !== null) return ['code' => 'DUPLICADO_EN_DESTINO'];

        Db::transaction(function () use ($ids, $in, $paso, $idPaso) {
            Db::run(
                "UPDATE contrato SET HERMANDAD = ?, HERMANDAD_SLUG = ?, TITULAR = ? WHERE ID_CONTRATO IN ($in)",
                [$paso['HERMANDAD'], $paso['SLUG'], $paso['NOMBRE'], ...$ids]
            );
            foreach ($ids as $id) {
                Db::run('INSERT OR REPLACE INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)', [$id, $idPaso]);
            }
        });
        Db::logAdmin('MOVER_A_PASO', 'contrato', null, ['paso' => $idPaso, 'contratos' => $ids]);
        return ['code' => 'MOVED', 'movidos' => count($ids)];
    }

    /**
     * Tras un alta por rango desde la nómina (AdminRepo::addContratoRango con
     * HERMANDAD/TITULAR/SLUG del paso), enlaza a ese paso los contratos del
     * rango que aún no tengan paso — los recién creados y los que ya existían.
     */
    public static function enlazarRangoAPaso(string $localidad, int $idBanda, array $paso, int $anioInicio, int $anioFin): void
    {
        Db::run(
            'INSERT OR IGNORE INTO contrato_paso (ID_CONTRATO, ID_PASO)
             SELECT c.ID_CONTRATO, ? FROM contrato c
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             WHERE cl.LOCALIDAD = ? AND c.ID_BANDA = ? AND c.HERMANDAD_SLUG = ? AND c.TITULAR = ?
               AND c.ANIO BETWEEN ? AND ?',
            [$paso['ID_PASO'], $localidad, $idBanda, $paso['SLUG'], $paso['NOMBRE'], $anioInicio, $anioFin]
        );
    }

    // ── Ida / vuelta (016_ida_vuelta.sql) ────────────────────────────────

    /** Marca o desmarca una hermandad de la localidad como "ida / vuelta". Desmarcar no borra los tramos ya puestos. */
    public static function setIdaVuelta(string $localidad, int $idHermandad, bool $activo): array
    {
        $h = Db::one('SELECT ID_HERMANDAD FROM hermandad WHERE ID_HERMANDAD = ? AND LOCALIDAD = ?', [$idHermandad, $localidad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        if ($activo) {
            Db::run('INSERT OR IGNORE INTO hermandad_ida_vuelta (ID_HERMANDAD) VALUES (?)', [$idHermandad]);
        } else {
            Db::run('DELETE FROM hermandad_ida_vuelta WHERE ID_HERMANDAD = ?', [$idHermandad]);
        }
        Db::logAdmin('IDA_VUELTA', 'hermandad', $idHermandad, ['activo' => $activo]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Pone el tramo ('ida' / 'vuelta') a los contratos de una línea, o lo
     * quita con null. Solo contratos de esta localidad.
     * @param list<int> $ids
     */
    public static function setTramo(string $localidad, array $ids, ?string $tramo): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) return ['code' => 'SIN_SELECCION'];
        if ($tramo !== null && !in_array($tramo, ['ida', 'vuelta'], true)) return ['code' => 'TRAMO_INVALIDO'];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = Db::one("SELECT COUNT(*) AS N FROM contrato_localidad WHERE LOCALIDAD = ? AND ID_CONTRATO IN ($in)", [$localidad, ...$ids]);
        if ((int) ($n['N'] ?? 0) !== count($ids)) return ['code' => 'CONTRATO_INVALIDO'];
        Db::transaction(function () use ($ids, $in, $tramo) {
            if ($tramo === null) {
                Db::run("DELETE FROM contrato_tramo WHERE ID_CONTRATO IN ($in)", $ids);
                return;
            }
            foreach ($ids as $id) {
                Db::run('INSERT OR REPLACE INTO contrato_tramo (ID_CONTRATO, TRAMO) VALUES (?, ?)', [$id, $tramo]);
            }
        });
        Db::logAdmin('TRAMO', 'contrato', null, ['contratos' => $ids, 'tramo' => $tramo]);
        return ['code' => 'UPDATED'];
    }

    // ── Alta desde la ficha de banda (/dashboard/banda/{id}) ─────────────
    // El camino inverso al de /dashboard/acompanamientos/{localidad}: se parte
    // de la banda y se escribe localidad, día, hermandad y paso. Lo que no
    // exista en la nómina se da de alta con addDia/addHermandad/addPaso, así
    // que aparece en /dashboard/semana-santa igual que si se hubiera creado allí.

    /**
     * Días (nombres) en que sale o ha salido cada hermandad, sin repetir: el
     * principal primero, luego los extra y los de años pasados.
     * @param list<int>|null $ids null = todas
     * @return array<int, list<string>>
     */
    private static function diasDeHermandades(?array $ids = null): array
    {
        $filtro = $ids !== null ? ' WHERE h.ID_HERMANDAD IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')' : '';
        $out = [];
        foreach (Db::all("SELECT h.ID_HERMANDAD, h.DIA FROM hermandad h$filtro") as $h) {
            $out[(int) $h['ID_HERMANDAD']] = [(string) $h['DIA']];
        }
        if (self::variosDiasDisponible()) {
            foreach (Db::all(
                "SELECT h.ID_HERMANDAD, d.NOMBRE FROM hermandad h
                 INNER JOIN hermandad_dia_extra e ON e.ID_HERMANDAD = h.ID_HERMANDAD
                 INNER JOIN semana_santa_dia d ON d.ID_DIA = e.ID_DIA$filtro
                 UNION ALL
                 SELECT h.ID_HERMANDAD, d.NOMBRE FROM hermandad h
                 INNER JOIN hermandad_dia_historico hh ON hh.ID_HERMANDAD = h.ID_HERMANDAD
                 INNER JOIN semana_santa_dia d ON d.ID_DIA = hh.ID_DIA$filtro"
            ) as $r) {
                $id = (int) $r['ID_HERMANDAD'];
                if (!in_array((string) $r['NOMBRE'], $out[$id], true)) $out[$id][] = (string) $r['NOMBRE'];
            }
        }
        return $out;
    }

    /**
     * Nómina de todas las localidades para los desplegables del formulario.
     * dia: el principal (el que se propone al elegir la hermandad); dias: todos
     * los que valen para ella, también los extra y los de años pasados.
     * @return array<string, array{dias:list<string>,
     *                             hermandades:list<array{nombre:string,dia:string,dias:list<string>,pasos:list<string>}>}>
     */
    public static function datosAltaBanda(): array
    {
        $out = [];
        foreach (self::localidades() as $loc) {
            $out[$loc] = ['dias' => [], 'hermandades' => []];
        }
        foreach (Db::all('SELECT LOCALIDAD, NOMBRE FROM semana_santa_dia ORDER BY LOCALIDAD, ORDEN') as $d) {
            $out[(string) $d['LOCALIDAD']]['dias'][] = (string) $d['NOMBRE'];
        }
        $pasos = [];
        foreach (Db::all('SELECT ID_HERMANDAD, NOMBRE FROM paso ORDER BY ID_HERMANDAD, ORDEN') as $p) {
            $pasos[(int) $p['ID_HERMANDAD']][] = (string) $p['NOMBRE'];
        }
        $dias = self::diasDeHermandades();
        foreach (Db::all('SELECT ID_HERMANDAD, LOCALIDAD, NOMBRE, DIA FROM hermandad ORDER BY LOCALIDAD, DIA_ORDEN, ORDEN') as $h) {
            $out[(string) $h['LOCALIDAD']]['hermandades'][] = [
                'nombre' => (string) $h['NOMBRE'],
                'dia' => (string) $h['DIA'],
                'dias' => $dias[(int) $h['ID_HERMANDAD']] ?? [(string) $h['DIA']],
                'pasos' => $pasos[(int) $h['ID_HERMANDAD']] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Da de alta los acompañamientos de una banda en [anioInicio, anioFin].
     * Localidad, día, hermandad y paso se resuelven con planDestino() y lo que
     * falte se crea en la nómina con crearDestino().
     *
     * @param array{localidad?:string,provincia?:string,dia?:string,hermandad?:string,paso?:string,
     *              anioInicio?:int,anioFin?:int} $in
     * @return array{code:string, creados?:int, existentes?:int, nuevos?:list<string>}
     */
    public static function altaDesdeBanda(int $idBanda, array $in): array
    {
        $anioInicio = (int) ($in['anioInicio'] ?? 0);
        $anioFin = (int) ($in['anioFin'] ?? $anioInicio);
        // Mismo criterio que AdminRepo::addContratoRango, pero antes de tocar la nómina.
        if ($anioInicio < 1900 || $anioFin < $anioInicio || $anioFin - $anioInicio > 100) return ['code' => 'INVALID_RANGO'];

        $plan = self::planDestino($in);
        if ($plan['code'] !== 'OK') return $plan;
        $d = self::crearDestino($plan);
        if ($d['code'] !== 'OK') return $d;
        [$h, $paso] = [$d['h'], $d['paso']];

        $r = AdminRepo::addContratoRango($idBanda, (string) $h['NOMBRE'], $paso['NOMBRE'] ?? null, $anioInicio, $anioFin, null, null, $plan['localidad'], (string) $h['SLUG']);
        if (($r['code'] ?? '') === 'CREATED' && $paso !== null) {
            self::enlazarRangoAPaso($plan['localidad'], $idBanda, $paso, $anioInicio, $anioFin);
        }
        return $r + ['nuevos' => $d['nuevos']];
    }

    /**
     * Cambia año, localidad, hermandad y paso de UN contrato de la banda.
     * Mismas reglas que el alta, con dos excepciones para que guardar sin
     * tocar nada no invente nómina a partir de datos viejos:
     *   - hermandad fuera de la nómina que sigue igual y sin día: el contrato
     *     se queda fuera (solo cambian año y TITULAR como texto);
     *   - TITULAR sin paso enlazado que sigue igual (p. ej. "Paso de Misterio"
     *     de cargas antiguas): se conserva como texto, sin crear ese paso.
     * Se niega si la banda ya tiene ese mismo paso ese año (sería duplicado).
     *
     * @param array{anio?:int,localidad?:string,provincia?:string,dia?:string,hermandad?:string,paso?:string} $in
     * @return array{code:string, nuevos?:list<string>}
     */
    public static function editarDesdeBanda(int $idBanda, int $idContrato, array $in): array
    {
        $old = Db::one(
            'SELECT c.HERMANDAD, c.HERMANDAD_SLUG, c.TITULAR, cl.LOCALIDAD, cp.ID_PASO
             FROM contrato c
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.ID_CONTRATO = ? AND c.ID_BANDA = ?',
            [$idContrato, $idBanda]
        );
        if ($old === null) return ['code' => 'CONTRATO_INVALIDO'];
        $anio = (int) ($in['anio'] ?? 0);
        if ($anio < 1900 || $anio > 2100) return ['code' => 'INVALID_ANIO'];

        $txt = static fn(string $k): string => trim((string) ($in[$k] ?? ''));
        $oldLoc = (string) $old['LOCALIDAD'];
        $mismaLocalidad = $txt('localidad') !== '' && Slug::slugify($txt('localidad')) === Slug::slugify($oldLoc);
        $herSlug = Slug::slugify($txt('hermandad'));
        $mismaHermandad = $mismaLocalidad && $herSlug !== ''
            && ($herSlug === (string) $old['HERMANDAD_SLUG'] || $herSlug === Slug::slugify((string) $old['HERMANDAD']));

        $nuevos = [];
        if ($mismaHermandad && $txt('dia') === '' && self::buscarHermandad($oldLoc, $txt('hermandad')) === null) {
            // Fuera de la nómina y sin día para meterla: se queda fuera.
            [$localidad, $hermandad, $slug, $idPaso] = [$oldLoc, (string) $old['HERMANDAD'], (string) $old['HERMANDAD_SLUG'], null];
            $titular = $txt('paso') !== '' ? $txt('paso') : null;
        } else {
            $plan = self::planDestino($in);
            if ($plan['code'] !== 'OK') return $plan;
            $titularLibre = $plan['paso'] === null && $plan['pasoIn'] !== '' && $old['ID_PASO'] === null
                && $plan['h'] !== null && $plan['localidad'] === $oldLoc && (string) $plan['h']['SLUG'] === (string) $old['HERMANDAD_SLUG']
                && Slug::slugify($plan['pasoIn']) === Slug::slugify((string) $old['TITULAR']);
            // Duplicado comprobable antes de escribir: sin hermandad ni paso nuevos.
            if ($plan['h'] !== null && ($plan['pasoIn'] === '' || $plan['paso'] !== null || $titularLibre)) {
                $t = $plan['paso']['NOMBRE'] ?? ($titularLibre ? (string) $old['TITULAR'] : null);
                if (self::hayDuplicado($idBanda, $idContrato, $plan['localidad'], (string) $plan['h']['SLUG'], $t, $plan['paso']['ID_PASO'] ?? null, $anio)) {
                    return ['code' => 'DUPLICADO'];
                }
            }
            $d = self::crearDestino($plan, !$titularLibre);
            if ($d['code'] !== 'OK') return $d;
            $nuevos = $d['nuevos'];
            [$localidad, $hermandad, $slug] = [$plan['localidad'], (string) $d['h']['NOMBRE'], (string) $d['h']['SLUG']];
            $idPaso = $d['paso'] !== null ? (int) $d['paso']['ID_PASO'] : null;
            $titular = $d['paso']['NOMBRE'] ?? ($titularLibre ? (string) $old['TITULAR'] : null);
        }
        if ($idPaso === null && self::hayDuplicado($idBanda, $idContrato, $localidad, $slug, $titular, null, $anio)) {
            return ['code' => 'DUPLICADO'];
        }

        // Ida/vuelta es de la hermandad: si el contrato cambia de hermandad, su tramo ya no vale.
        $otraHermandad = $localidad !== $oldLoc || $slug !== (string) $old['HERMANDAD_SLUG'];
        Db::transaction(function () use ($idContrato, $hermandad, $slug, $titular, $anio, $localidad, $idPaso, $otraHermandad) {
            Db::run(
                'UPDATE contrato SET HERMANDAD = ?, HERMANDAD_SLUG = ?, TITULAR = ?, ANIO = ? WHERE ID_CONTRATO = ?',
                [$hermandad, $slug, $titular, $anio, $idContrato]
            );
            Db::run('UPDATE contrato_localidad SET LOCALIDAD = ? WHERE ID_CONTRATO = ?', [$localidad, $idContrato]);
            if ($idPaso !== null) {
                Db::run('INSERT OR REPLACE INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)', [$idContrato, $idPaso]);
            } else {
                Db::run('DELETE FROM contrato_paso WHERE ID_CONTRATO = ?', [$idContrato]);
            }
            if ($otraHermandad) Db::run('DELETE FROM contrato_tramo WHERE ID_CONTRATO = ?', [$idContrato]);
        });
        Db::logAdmin('UPDATE', 'contrato', $idContrato, [
            'anio' => $anio, 'localidad' => $localidad, 'hermandad' => $hermandad, 'titular' => $titular, 'paso' => $idPaso,
        ]);
        return ['code' => 'UPDATED', 'nuevos' => $nuevos];
    }

    /**
     * Valida localidad, provincia, día, hermandad y paso sin escribir nada.
     * El par provincia/localidad tiene que estar en el catálogo de municipios.
     * Lo demás se busca sin tildes ni mayúsculas (por slug); la hermandad también
     * por su SLUG fijo, que tras un renombrado ya no es slugify(NOMBRE). Si la
     * hermandad ya existe manda su día: uno distinto es un error, no un cambio
     * de día (eso se hace en /dashboard/semana-santa).
     * @return array{code:string, localidad?:string, provNueva?:?string, h?:?array, paso?:?array,
     *               diaIn?:string, herIn?:string, pasoIn?:string}
     */
    private static function planDestino(array $in): array
    {
        $txt = static fn(string $k): string => trim((string) ($in[$k] ?? ''));
        [$locIn, $provIn, $diaIn, $herIn, $pasoIn] = [$txt('localidad'), $txt('provincia'), $txt('dia'), $txt('hermandad'), $txt('paso')];

        if ($locIn === '') return ['code' => 'LOCALIDAD_REQUERIDA'];
        if (!MunicipioRepo::esProvinciaValida($provIn)) return ['code' => 'PROVINCIA_REQUERIDA'];
        // Solo localidades del catálogo de municipios, como en bandas y
        // dedicatorias (AdminRepo::fijarMunicipio); el nombre bueno es el suyo.
        if (MunicipioRepo::tablaDisponible()) {
            $m = MunicipioRepo::buscarPar($provIn, $locIn);
            if ($m === null) return ['code' => 'INVALID_LOCALIDAD'];
            $locIn = (string) $m['NOMBRE'];
        }
        if (Slug::slugify($locIn) === '') return ['code' => 'LOCALIDAD_INVALIDA'];
        if ($herIn === '') return ['code' => 'HERMANDAD_REQUERIDA'];
        if (Slug::slugify($herIn) === '') return ['code' => 'NOMBRE_INVALIDO'];

        $localidad = self::resolverLocalidadPorSlug(Slug::slugify($locIn)) ?? $locIn;
        $prov = Db::one('SELECT PROVINCIA FROM localidad_provincia WHERE LOCALIDAD = ?', [$localidad]);
        if ($prov !== null && $provIn !== (string) $prov['PROVINCIA']) return ['code' => 'PROVINCIA_DISTINTA'];

        $h = self::buscarHermandad($localidad, $herIn);
        $idDiaExtra = null;
        if ($h !== null) {
            // Vale cualquiera de sus días (principal, extra o de años pasados).
            $diasH = self::diasDeHermandades([(int) $h['ID_HERMANDAD']])[(int) $h['ID_HERMANDAD']] ?? [(string) $h['DIA']];
            if ($diaIn !== '' && !in_array(Slug::slugify($diaIn), array_map([Slug::class, 'slugify'], $diasH), true)) {
                return ['code' => 'DIA_DISTINTO_DE_HERMANDAD'];
            }
            // Un paso nuevo escrito con un día extra sale ese día.
            foreach (self::diasExtra((int) $h['ID_HERMANDAD']) as $idDia => $_) {
                $n = Db::one('SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
                if ($diaIn !== '' && $n !== null && Slug::slugify((string) $n['NOMBRE']) === Slug::slugify($diaIn)) $idDiaExtra = $idDia;
            }
        } elseif ($diaIn === '') {
            return ['code' => 'DIA_REQUERIDO'];
        }

        return [
            'code' => 'OK', 'localidad' => $localidad, 'provNueva' => $prov === null ? $provIn : null,
            'h' => $h, 'paso' => $h !== null && $pasoIn !== '' ? self::buscarPaso((int) $h['ID_HERMANDAD'], $pasoIn, $localidad) : null,
            'diaIn' => $diaIn, 'herIn' => $herIn, 'pasoIn' => $pasoIn, 'idDiaExtra' => $idDiaExtra,
        ];
    }

    /**
     * Escribe en la nómina lo que falte de un plan de planDestino(): provincia,
     * día, hermandad y (si $crearPaso) paso. Así lo nuevo aparece en
     * /dashboard/semana-santa igual que si se hubiera creado allí.
     * @return array{code:string, h?:array, paso?:?array, nuevos?:list<string>}
     */
    private static function crearDestino(array $plan, bool $crearPaso = true): array
    {
        $localidad = $plan['localidad'];
        $nuevos = [];
        if ($plan['provNueva'] !== null) {
            Db::run('INSERT INTO localidad_provincia (LOCALIDAD, PROVINCIA) VALUES (?, ?)', [$localidad, $plan['provNueva']]);
            Db::logAdmin('INSERT', 'localidad_provincia', null, ['localidad' => $localidad, 'provincia' => $plan['provNueva']]);
        }
        $h = $plan['h'];
        if ($h === null) {
            $idDia = null;
            foreach (Db::all('SELECT ID_DIA, NOMBRE FROM semana_santa_dia WHERE LOCALIDAD = ?', [$localidad]) as $d) {
                if (Slug::slugify((string) $d['NOMBRE']) === Slug::slugify($plan['diaIn'])) $idDia = (int) $d['ID_DIA'];
            }
            if ($idDia === null) {
                $r = self::addDia($localidad, $plan['diaIn']);
                if ($r['code'] !== 'CREATED') return $r;
                $idDia = (int) $r['id'];
                $nuevos[] = "día «{$plan['diaIn']}»";
            }
            $r = self::addHermandad($localidad, $idDia, $plan['herIn']);
            if ($r['code'] !== 'CREATED') return $r;
            $h = Db::one('SELECT ID_HERMANDAD, NOMBRE, SLUG, DIA FROM hermandad WHERE ID_HERMANDAD = ?', [$r['id']]);
            $nuevos[] = "hermandad «{$plan['herIn']}»";
        }

        $paso = $plan['paso'];
        if ($paso === null && $plan['pasoIn'] !== '' && $crearPaso) {
            $r = self::addPaso((int) $h['ID_HERMANDAD'], $plan['pasoIn'], Slug::slugify($plan['pasoIn']) === 'cruz-de-guia', $plan['idDiaExtra'] ?? null);
            if ($r['code'] !== 'CREATED') return $r;
            $paso = self::pasoDeLocalidad((int) $r['id'], $localidad);
            $nuevos[] = "paso «{$plan['pasoIn']}»";
        }
        return ['code' => 'OK', 'h' => $h, 'paso' => $paso, 'nuevos' => $nuevos];
    }

    /** Paso de la hermandad por slugify(NOMBRE), con los datos de pasoDeLocalidad(). */
    private static function buscarPaso(int $idHermandad, string $nombre, string $localidad): ?array
    {
        foreach (Db::all('SELECT ID_PASO, NOMBRE FROM paso WHERE ID_HERMANDAD = ?', [$idHermandad]) as $p) {
            if (Slug::slugify((string) $p['NOMBRE']) === Slug::slugify($nombre)) return self::pasoDeLocalidad((int) $p['ID_PASO'], $localidad);
        }
        return null;
    }

    /**
     * ¿La banda ya tiene otro contrato ese año en ese paso? Cuenta el enlazado
     * por contrato_paso y el que casa por HERMANDAD_SLUG + TITULAR sin enlace,
     * igual que NominaRepo::moverContratosAPaso.
     */
    private static function hayDuplicado(int $idBanda, int $excluir, string $localidad, string $slug, ?string $titular, ?int $idPaso, int $anio): bool
    {
        return Db::one(
            "SELECT 1 FROM contrato c
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.ID_BANDA = ? AND c.ANIO = ? AND cl.LOCALIDAD = ? AND c.ID_CONTRATO != ?
               AND ((? IS NOT NULL AND cp.ID_PASO = ?)
                    OR (cp.ID_CONTRATO IS NULL AND c.HERMANDAD_SLUG = ? AND IFNULL(c.TITULAR, '') = ?))
             LIMIT 1",
            [$idBanda, $anio, $localidad, $excluir, $idPaso, $idPaso, $slug, $titular ?? '']
        ) !== null;
    }

    /** Hermandad de la localidad por SLUG o por slugify(NOMBRE). */
    private static function buscarHermandad(string $localidad, string $nombre): ?array
    {
        $slug = Slug::slugify($nombre);
        foreach (Db::all('SELECT ID_HERMANDAD, NOMBRE, SLUG, DIA FROM hermandad WHERE LOCALIDAD = ?', [$localidad]) as $h) {
            if ((string) $h['SLUG'] === $slug || Slug::slugify((string) $h['NOMBRE']) === $slug) return $h;
        }
        return null;
    }

    /**
     * Borra contratos desde la ficha de banda, solo si todos son de esa banda.
     * @param list<int> $ids
     */
    public static function borrarDeBanda(int $idBanda, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) return ['code' => 'SIN_SELECCION'];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = Db::one("SELECT COUNT(*) AS N FROM contrato WHERE ID_BANDA = ? AND ID_CONTRATO IN ($in)", [$idBanda, ...$ids]);
        if ((int) ($n['N'] ?? 0) !== count($ids)) return ['code' => 'CONTRATO_INVALIDO'];
        return AdminRepo::deleteContratoRango($ids);
    }

    /**
     * Copia a $destino todos los acompañamientos de la banda en $origen, con
     * su localidad, paso enlazado y tramo (ida/vuelta). Solo usa nómina que ya
     * existe, así que no crea nada en Semana Santa. FUENTE y NOTA no se copian:
     * son de aquel año. Se salta lo que la banda ya tenga en $destino en ese
     * paso (o misma hermandad y TITULAR), así que repetir la importación no duplica.
     * @return array{code:string, creados?:int, existentes?:int}
     */
    public static function copiarAnioDeBanda(int $idBanda, int $origen, int $destino): array
    {
        if ($destino < 1900 || $destino > 2100) return ['code' => 'INVALID_ANIO'];
        if ($origen === $destino) return ['code' => 'MISMO_ANIO'];
        $filas = Db::all(
            'SELECT c.HERMANDAD, c.HERMANDAD_SLUG, c.TITULAR, cl.LOCALIDAD, cp.ID_PASO, ct.TRAMO
             FROM contrato c
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             LEFT JOIN contrato_tramo ct ON ct.ID_CONTRATO = c.ID_CONTRATO
             WHERE c.ID_BANDA = ? AND c.ANIO = ?
             ORDER BY c.ID_CONTRATO',
            [$idBanda, $origen]
        );
        if ($filas === []) return ['code' => 'SIN_ACOMPANAMIENTOS'];

        return Db::transaction(function () use ($idBanda, $origen, $destino, $filas) {
            $creados = 0;
            $existentes = 0;
            foreach ($filas as $f) {
                $idPaso = $f['ID_PASO'] !== null ? (int) $f['ID_PASO'] : null;
                $existe = Db::one(
                    "SELECT 1 FROM contrato c
                     INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
                     LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
                     WHERE c.ID_BANDA = ? AND c.ANIO = ? AND cl.LOCALIDAD = ?
                       AND ((? IS NOT NULL AND cp.ID_PASO = ?)
                            OR (c.HERMANDAD_SLUG = ? AND IFNULL(c.TITULAR, '') = ?))
                     LIMIT 1",
                    [$idBanda, $destino, $f['LOCALIDAD'], $idPaso, $idPaso, $f['HERMANDAD_SLUG'], $f['TITULAR'] ?? '']
                );
                if ($existe !== null) {
                    $existentes++;
                    continue;
                }
                Db::run(
                    'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO) VALUES (?, ?, ?, ?, ?)',
                    [$idBanda, $f['HERMANDAD'], $f['HERMANDAD_SLUG'], $f['TITULAR'], $destino]
                );
                $id = Db::lastInsertId();
                Db::run('INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, ?)', [$id, $f['LOCALIDAD']]);
                if ($idPaso !== null) Db::run('INSERT INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)', [$id, $idPaso]);
                if ($f['TRAMO'] !== null) Db::run('INSERT INTO contrato_tramo (ID_CONTRATO, TRAMO) VALUES (?, ?)', [$id, $f['TRAMO']]);
                $creados++;
            }
            Db::logAdmin('COPIA_ANIO', 'contrato', null, [
                'banda' => $idBanda, 'origen' => $origen, 'destino' => $destino, 'creados' => $creados, 'existentes' => $existentes,
            ]);
            return ['code' => 'CREATED', 'creados' => $creados, 'existentes' => $existentes];
        });
    }

    /** Alta rápida con tramo: se lo pone a los contratos del rango recién dado de alta en el paso. */
    public static function marcarTramoRango(string $localidad, int $idBanda, array $paso, int $anioInicio, int $anioFin, string $tramo): void
    {
        if (!in_array($tramo, ['ida', 'vuelta'], true)) return;
        Db::run(
            'INSERT OR REPLACE INTO contrato_tramo (ID_CONTRATO, TRAMO)
             SELECT c.ID_CONTRATO, ? FROM contrato c
             INNER JOIN contrato_localidad cl ON cl.ID_CONTRATO = c.ID_CONTRATO
             INNER JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
             WHERE cl.LOCALIDAD = ? AND c.ID_BANDA = ? AND cp.ID_PASO = ? AND c.ANIO BETWEEN ? AND ?',
            [$tramo, $localidad, $idBanda, $paso['ID_PASO'], $anioInicio, $anioFin]
        );
    }
}
