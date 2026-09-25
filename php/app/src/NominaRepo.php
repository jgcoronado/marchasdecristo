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
 */
final class NominaRepo
{
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
     * Días de una localidad con sus hermandades y pasos, listos para pintar.
     * PUEDE_BORRAR de una hermandad exige que no tenga contratos (join por
     * HERMANDAD_SLUG, igual que la lectura pública) ni pasos con contrato_paso.
     * PUEDE_BORRAR de un paso exige que no tenga fila en contrato_paso.
     *
     * @return list<array{ID_DIA:int,NOMBRE:string,ORDEN:int,
     *                     hermandades:list<array{ID_HERMANDAD:int,NOMBRE:string,SLUG:string,ORDEN:int,
     *                                             PUEDE_BORRAR:bool,
     *                                             pasos:list<array{ID_PASO:int,NOMBRE:string,ORDEN:int,ES_CRUZ_GUIA:bool,PUEDE_BORRAR:bool}>}>}>
     */
    public static function cargarLocalidad(string $localidad): array
    {
        $dias = Db::all(
            'SELECT ID_DIA, NOMBRE, ORDEN FROM semana_santa_dia WHERE LOCALIDAD = ? ORDER BY ORDEN ASC',
            [$localidad]
        );

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
            $hermandadesPorDia[(string) $h['DIA']][] = [
                'ID_HERMANDAD' => (int) $h['ID_HERMANDAD'],
                'NOMBRE' => (string) $h['NOMBRE'],
                'SLUG' => (string) $h['SLUG'],
                'ORDEN' => (int) $h['ORDEN'],
                'PUEDE_BORRAR' => (int) $h['N_CONTRATOS'] === 0 && array_sum(array_column($pasos, 'N_CONTRATOS')) === 0,
                'pasos' => array_map(static fn(array $p): array => [
                    'ID_PASO' => (int) $p['ID_PASO'],
                    'NOMBRE' => (string) $p['NOMBRE'],
                    'ORDEN' => (int) $p['ORDEN'],
                    'ES_CRUZ_GUIA' => (int) $p['ES_CRUZ_GUIA'] === 1,
                    'PUEDE_BORRAR' => (int) $p['N_CONTRATOS'] === 0,
                ], $pasos),
            ];
        }

        return array_map(static function (array $d) use ($hermandadesPorDia): array {
            return [
                'ID_DIA' => (int) $d['ID_DIA'],
                'NOMBRE' => (string) $d['NOMBRE'],
                'ORDEN' => (int) $d['ORDEN'],
                'hermandades' => $hermandadesPorDia[(string) $d['NOMBRE']] ?? [],
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

    /** @return array{code:string, id?:int} */
    public static function addHermandad(string $localidad, int $idDia, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $dia = Db::one('SELECT NOMBRE, ORDEN FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDia, $localidad]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        $slug = Slug::slugify($nombre);
        if ($slug === '') return ['code' => 'NOMBRE_INVALIDO'];
        $dup = Db::one('SELECT ID_HERMANDAD FROM hermandad WHERE LOCALIDAD = ? AND SLUG = ?', [$localidad, $slug]);
        if ($dup !== null) return ['code' => 'HERMANDAD_DUPLICADA'];
        $siguiente = (int) (Db::one('SELECT MAX(ORDEN) AS m FROM hermandad WHERE LOCALIDAD = ? AND DIA = ?', [$localidad, $dia['NOMBRE']])['m'] ?? 0) + 1;
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
            Db::run('DELETE FROM paso WHERE ID_HERMANDAD = ?', [$idHermandad]);
            Db::run('DELETE FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        });
        Db::logAdmin('DELETE', 'hermandad', $idHermandad, null);
        return ['code' => 'DELETED'];
    }

    /** Mueve la hermandad a otro día de la misma localidad: DIA/DIA_ORDEN al del destino, ORDEN = último+1. */
    public static function moveHermandad(int $idHermandad, int $idDiaDestino): array
    {
        $h = Db::one('SELECT LOCALIDAD FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT NOMBRE, ORDEN FROM semana_santa_dia WHERE ID_DIA = ? AND LOCALIDAD = ?', [$idDiaDestino, $h['LOCALIDAD']]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        $siguiente = (int) (Db::one('SELECT MAX(ORDEN) AS m FROM hermandad WHERE LOCALIDAD = ? AND DIA = ?', [$h['LOCALIDAD'], $dia['NOMBRE']])['m'] ?? 0) + 1;
        Db::run(
            'UPDATE hermandad SET DIA = ?, DIA_ORDEN = ?, ORDEN = ? WHERE ID_HERMANDAD = ?',
            [$dia['NOMBRE'], $dia['ORDEN'], $siguiente, $idHermandad]
        );
        Db::logAdmin('UPDATE', 'hermandad', $idHermandad, ['dia_destino' => $dia['NOMBRE']]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Reordena las hermandades de un día: reescribe ORDEN = 1..n según el
     * orden de $idsHermandad.
     * @param list<int> $idsHermandad
     */
    public static function reorderHermandades(int $idDia, array $idsHermandad): array
    {
        if ($idsHermandad === []) return ['code' => 'NOT_FOUND'];
        $dia = Db::one('SELECT LOCALIDAD, NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?', [$idDia]);
        if ($dia === null) return ['code' => 'DIA_NOT_FOUND'];
        Db::transaction(function () use ($dia, $idsHermandad) {
            $orden = 1;
            foreach ($idsHermandad as $idHermandad) {
                Db::run(
                    'UPDATE hermandad SET ORDEN = ? WHERE ID_HERMANDAD = ? AND LOCALIDAD = ? AND DIA = ?',
                    [$orden, $idHermandad, $dia['LOCALIDAD'], $dia['NOMBRE']]
                );
                $orden++;
            }
        });
        Db::logAdmin('REORDER', 'hermandad', null, ['dia' => $idDia, 'ids' => $idsHermandad]);
        return ['code' => 'REORDERED'];
    }

    /** Respaldo sin JS: intercambia el ORDEN de una hermandad con su vecina en el mismo día. */
    public static function swapHermandadConVecino(int $idHermandad, string $direccion): array
    {
        $h = Db::one('SELECT LOCALIDAD, DIA, ORDEN FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
        $cmp = $direccion === 'up' ? '<' : '>';
        $dir = $direccion === 'up' ? 'DESC' : 'ASC';
        $vecina = Db::one(
            "SELECT ID_HERMANDAD, ORDEN FROM hermandad WHERE LOCALIDAD = ? AND DIA = ? AND ORDEN $cmp ? ORDER BY ORDEN $dir LIMIT 1",
            [$h['LOCALIDAD'], $h['DIA'], $h['ORDEN']]
        );
        if ($vecina === null) return ['code' => 'SIN_VECINO'];
        Db::transaction(function () use ($h, $vecina, $idHermandad) {
            Db::run('UPDATE hermandad SET ORDEN = ? WHERE ID_HERMANDAD = ?', [$vecina['ORDEN'], $idHermandad]);
            Db::run('UPDATE hermandad SET ORDEN = ? WHERE ID_HERMANDAD = ?', [$h['ORDEN'], $vecina['ID_HERMANDAD']]);
        });
        Db::logAdmin('REORDER', 'hermandad', $idHermandad, ['swap_con' => $vecina['ID_HERMANDAD']]);
        return ['code' => 'REORDERED'];
    }

    // ── Pasos ────────────────────────────────────────────────────────────

    /** @return array{code:string, id?:int} */
    public static function addPaso(int $idHermandad, string $nombre, bool $esCruzGuia): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        $h = Db::one('SELECT ID_HERMANDAD FROM hermandad WHERE ID_HERMANDAD = ?', [$idHermandad]);
        if ($h === null) return ['code' => 'NOT_FOUND'];
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
        Db::logAdmin('INSERT', 'paso', $id, ['hermandad' => $idHermandad, 'nombre' => $nombre]);
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

    /** Respaldo sin JS: intercambia el ORDEN de un paso (nunca la cruz de guía) con su vecino. */
    public static function swapPasoConVecino(int $idPaso, string $direccion): array
    {
        $p = Db::one('SELECT ID_HERMANDAD, ORDEN FROM paso WHERE ID_PASO = ? AND ES_CRUZ_GUIA = 0', [$idPaso]);
        if ($p === null) return ['code' => 'NOT_FOUND'];
        $cmp = $direccion === 'up' ? '<' : '>';
        $dir = $direccion === 'up' ? 'DESC' : 'ASC';
        $vecino = Db::one(
            "SELECT ID_PASO, ORDEN FROM paso WHERE ID_HERMANDAD = ? AND ES_CRUZ_GUIA = 0 AND ORDEN $cmp ? ORDER BY ORDEN $dir LIMIT 1",
            [$p['ID_HERMANDAD'], $p['ORDEN']]
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
             WHERE cl.LOCALIDAD = ?$filtroAnio
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

        // Contratos cuyo SLUG no es de la nómina pero su nombre sí casa con
        // una única hermandad de ella quitando tildes, artículos y
        // «Sagrada/Santísimo…» («La Lanzada» ≈ «Sagrada Lanzada», «Los
        // Mutilados» ≈ «Mutilados»): se pintan en ella, sin paso. Solo
        // presentación; el enlace se escribe si se mueven.
        $tokensHermandad = [];
        foreach (Db::all('SELECT ID_HERMANDAD, NOMBRE FROM hermandad WHERE LOCALIDAD = ?', [$localidad]) as $h) {
            $tokensHermandad[(int) $h['ID_HERMANDAD']] = self::tokensNombre((string) $h['NOMBRE']);
        }
        $hermandadParecida = static function (string $nombre) use ($tokensHermandad): ?int {
            $t = self::tokensNombre($nombre);
            if (!$t) return null;
            $ids = array_keys(array_filter($tokensHermandad, static fn($th) => $th && (!array_diff($t, $th) || !array_diff($th, $t))));
            return count($ids) === 1 ? $ids[0] : null;
        };

        $porPaso = [];
        $sinPaso = [];
        $fuera = [];
        foreach ($rows as $r) {
            // El tramo solo cuenta si la hermandad está marcada como ida/vuelta:
            // desmarcarla no borra el dato, pero deja de partir las líneas.
            if (!(int) $r['IDA_VUELTA']) $r['TRAMO'] = null;
            if ($r['ID_HERMANDAD'] === null) {
                $r['ID_HERMANDAD'] = $hermandadParecida((string) $r['HERMANDAD']);
            }
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
                foreach ($h['pasos'] as &$p) {
                    $g = isset($porPaso[$p['ID_PASO']]) ? Repo::agruparAcompanamientos($porPaso[$p['ID_PASO']]) : [];
                    $p['rangos'] = $g[0]['titulares'][0]['rangos'] ?? [];
                }
                unset($p);
                $h['IDA_VUELTA'] = isset($idaVuelta[$h['ID_HERMANDAD']]);
                $h['sinPaso'] = [];
                if (isset($sinPaso[$h['ID_HERMANDAD']])) {
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

    /** Palabras significativas de un nombre de hermandad, sin tildes ni artículos. */
    private static function tokensNombre(string $s): array
    {
        static $vacias = ['el', 'la', 'los', 'las', 'de', 'del', 'y', 'en', 'su', 'sus', 'sagrada', 'sagrado',
                          'santisimo', 'santisima', 'hermandad', 'cofradia', 'real', 'ilustre'];
        $palabras = preg_split('/[^a-z0-9]+/', strtolower(Db::noAcc($s)), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_diff($palabras, $vacias));
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
