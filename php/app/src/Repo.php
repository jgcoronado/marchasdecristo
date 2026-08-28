<?php

declare(strict_types=1);

namespace App;

/**
 * Capa de lectura — port de nextjs/lib/api.ts.
 *
 * Diferencia intencionada: la serialización de autores NO usa json_group_array
 * (SQLite 3.34 de HelioHost puede no traer JSON1). En su lugar se agrupan en PHP
 * con autoresFor(). El resto del SQL es fiel al original para garantizar paridad.
 */
final class Repo
{
    // ── Helpers ────────────────────────────────────────────────────────────

    private static function buildFtsQuery(string $raw): ?string
    {
        $cleaned = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $raw));
        if ($cleaned === '') {
            return null;
        }
        $tokens = preg_split('/\s+/', $cleaned) ?: [];
        return implode(' ', array_map(static fn(string $t): string => '"' . $t . '"', $tokens));
    }

    /** Muta la fila: FECHA null o '' → 's/f'. Espejo de normalizeFecha(). */
    private static function normalizeFecha(array &$row): void
    {
        if (!array_key_exists('FECHA', $row)) {
            return;
        }
        if ($row['FECHA'] === null || $row['FECHA'] === '') {
            $row['FECHA'] = 's/f';
        }
    }

    /**
     * Autores por marcha, agrupados en PHP (equivale al json_group_array del SQL).
     * La concatenación del nombre se hace EN SQL para replicar la propagación de
     * NULL de SQLite (NOMBRE || ' ' || APELLIDOS con APELLIDOS NULL → NULL).
     *
     * @param  list<int> $marchaIds
     * @return array<int, list<array{autorId:int, nombre:?string}>>
     */
    private static function autoresFor(array $marchaIds): array
    {
        if ($marchaIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($marchaIds), '?'));
        $rows = Db::all(
            "SELECT ma.ID_MARCHA AS mid, a.ID_AUTOR,
                    (a.NOMBRE || ' ' || a.APELLIDOS) AS nombre
             FROM marcha_autor ma
             INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA IN ($placeholders)
             ORDER BY ma.ID_MARCHA, a.APELLIDOS",
            array_values($marchaIds)
        );

        $map = [];
        $seen = [];
        foreach ($rows as $r) {
            $mid = (int) $r['mid'];
            $aid = (int) $r['ID_AUTOR'];
            $key = $mid . '#' . $aid;
            if (isset($seen[$key])) {
                continue; // DISTINCT
            }
            $seen[$key] = true;
            $map[$mid][] = ['autorId' => $aid, 'nombre' => $r['nombre']];
        }
        return $map;
    }

    /** Asigna la lista de autores a cada fila (por ID_MARCHA). */
    private static function attachAutores(array &$rows): void
    {
        $ids = [];
        foreach ($rows as $r) {
            if (isset($r['ID_MARCHA'])) {
                $ids[] = (int) $r['ID_MARCHA'];
            }
        }
        $map = self::autoresFor($ids);
        foreach ($rows as &$r) {
            $mid = isset($r['ID_MARCHA']) ? (int) $r['ID_MARCHA'] : 0;
            $r['AUTOR'] = $map[$mid] ?? [];
        }
        unset($r);
    }

    // ── Marcha ───────────────────────────────────────────────────────────────

    public static function fetchMarcha(string $id): ?array
    {
        $marcha = Db::one(
            "SELECT m.ID_MARCHA, m.TITULO, m.DEDICATORIA, m.LOCALIDAD, m.PROVINCIA, m.AUDIO, m.FECHA,
                    m.BANDA_ESTRENO, m.DETALLES_MARCHA, m.TIPO, m.ESTILO, m.DURACION_SEG,
                    b.NOMBRE_BREVE AS BANDA_NOMBRE, b.LOCALIDAD AS BANDA_LOC,
                    b.NOMBRE_COMPLETO AS BANDA_NOMBRE_COMPLETO,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
             FROM marcha m
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE m.ID_MARCHA = ?
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)",
            [$id]
        );
        if ($marcha === null) {
            return null;
        }
        self::normalizeFecha($marcha);
        $mid = (int) $marcha['ID_MARCHA'];
        $marcha['AUTOR'] = self::autoresFor([$mid])[$mid] ?? [];

        // Autores en forma de autoridad ("Apellidos, Nombre") con recuento de obra,
        // para el asiento y la descripción de la ficha de catálogo.
        $marcha['AUTORES_FICHA'] = Db::all(
            "SELECT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS, a.F_NAC, a.F_DEF,
                    (SELECT COUNT(*) FROM marcha_autor x WHERE x.ID_AUTOR = a.ID_AUTOR) AS N_MARCHAS
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = ? ORDER BY a.APELLIDOS",
            [$id]
        );

        $marcha['BANDA_ESTRENOS'] = 0;
        if (!empty($marcha['BANDA_ESTRENO'])) {
            $row = Db::one('SELECT COUNT(*) AS n FROM marcha WHERE BANDA_ESTRENO = ?', [$marcha['BANDA_ESTRENO']]);
            $marcha['BANDA_ESTRENOS'] = (int) ($row['n'] ?? 0);
        }

        // Grabaciones: una fila por aparición en disco, con pista y banda intérprete
        // (la del vínculo dm.DM_BANDA si existe; si no, la banda propietaria del disco).
        $discos = Db::all(
            "SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, dm.NUMEROMARCHA, dm.DURACION_SEG,
                    COALESCE(dm.PERCUSION, d.PERCUSION, 0) AS PERCUSION,
                    COALESCE(bi.ID_BANDA, b.ID_BANDA) AS ID_BANDA,
                    COALESCE(bi.NOMBRE_BREVE, b.NOMBRE_BREVE) AS BANDA_BREVE,
                    COALESCE(bi.LOCALIDAD, b.LOCALIDAD) AS BANDA_LOC,
                    (COALESCE(bi.NOMBRE_BREVE, b.NOMBRE_BREVE) || ' (' || COALESCE(bi.LOCALIDAD, b.LOCALIDAD) || ')') AS BANDA
             FROM disco d
             INNER JOIN disco_marcha dm ON dm.ID_DISCO = d.ID_DISCO
             LEFT OUTER JOIN banda b  ON b.ID_BANDA  = d.BANDADISCO
             LEFT OUTER JOIN banda bi ON bi.ID_BANDA = dm.DM_BANDA
             WHERE dm.IDMARCHA = ? ORDER BY CAST(d.FECHA_CD AS REAL) ASC, d.NOMBRE_CD ASC",
            [$id]
        );
        $marcha['discosLength'] = count($discos);
        $marcha['discos'] = $discos;

        $primera = null;
        foreach ($discos as $d) {
            $y = (int) (float) ($d['FECHA_CD'] ?? 0);
            if ($y > 1800 && ($primera === null || $y < $primera)) $primera = $y;
        }
        $marcha['PRIMERA_GRABACION'] = $primera;

        // Posición en el catálogo y registros vecinos (‹ M-x · n de N · M-y ›).
        $valid = 'EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)';
        $marcha['REG_TOTAL'] = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE $valid")['n'] ?? 0);
        $marcha['REG_POS'] = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE m.ID_MARCHA <= ? AND $valid", [$id])['n'] ?? 0);
        $marcha['REG_PREV'] = Db::one("SELECT m.ID_MARCHA, m.TITULO FROM marcha m WHERE m.ID_MARCHA < ? AND $valid ORDER BY m.ID_MARCHA DESC LIMIT 1", [$id]);
        $marcha['REG_NEXT'] = Db::one("SELECT m.ID_MARCHA, m.TITULO FROM marcha m WHERE m.ID_MARCHA > ? AND $valid ORDER BY m.ID_MARCHA ASC LIMIT 1", [$id]);

        // Recuentos para las remisiones «véase también».
        $marcha['N_MISMO_ANIO'] = 0;
        if (!empty($marcha['FECHA'])) {
            $marcha['N_MISMO_ANIO'] = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE m.FECHA = ? AND $valid", [$marcha['FECHA']])['n'] ?? 0);
        }
        $marcha['N_MISMA_PROV'] = 0;
        if (!empty($marcha['PROVINCIA'])) {
            $marcha['N_MISMA_PROV'] = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE m.PROVINCIA = ? AND $valid", [$marcha['PROVINCIA']])['n'] ?? 0);
        }
        $marcha['N_MISMO_ESTILO'] = 0;
        if (!empty($marcha['ESTILO'])) {
            $marcha['N_MISMO_ESTILO'] = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE m.ESTILO = ? AND $valid", [$marcha['ESTILO']])['n'] ?? 0);
        }

        // Fecha de publicación del vídeo (solo para las marchas creadas vía la
        // ingesta de YouTube): habilita uploadDate en el VideoObject, que Google
        // exige para los rich results de vídeo. Sin este dato no se emite el
        // VideoObject (los audios heredados no lo tienen), pero el reproductor
        // embebido se muestra igual.
        $marcha['VIDEO_UPLOAD'] = null;
        $ytid = Media::youtubeId($marcha['AUDIO'] ?? null);
        if ($ytid !== null) {
            $row = Db::one(
                "SELECT PUBLICADO_AT FROM ingest_candidato
                 WHERE MARCHA_CREADA = ? AND VIDEO_ID = ? AND PUBLICADO_AT IS NOT NULL AND PUBLICADO_AT != ''
                 ORDER BY REVIEWED_AT DESC LIMIT 1",
                [$id, $ytid]
            );
            $marcha['VIDEO_UPLOAD'] = $row['PUBLICADO_AT'] ?? null;
        }
        return $marcha;
    }

    /**
     * WHERE + values de la búsqueda de marchas. $exclude omite un criterio
     * (para calcular facetas sin su propio filtro; 'fecha' excluye desde+hasta).
     * @return array{0:string,1:list<mixed>}
     */
    private static function marchaWhere(array $params, ?string $exclude = null): array
    {
        $conditions = [];
        $values = [];
        $on = static fn(string $k): bool => $k !== $exclude && !empty($params[$k]);

        $titulo = $exclude !== 'titulo' ? (string) ($params['titulo'] ?? '') : '';
        $fts = $titulo !== '' ? self::buildFtsQuery($titulo) : null;
        if ($fts !== null) {
            $conditions[] = 'm.ID_MARCHA IN (SELECT rowid FROM marcha_fts WHERE marcha_fts MATCH ?)';
            $values[] = $fts;
        }
        if ($exclude !== 'fecha' && !empty($params['fechaDesde'])) { $conditions[] = 'm.FECHA >= ?'; $values[] = $params['fechaDesde']; }
        if ($exclude !== 'fecha' && !empty($params['fechaHasta'])) { $conditions[] = 'm.FECHA <= ?'; $values[] = $params['fechaHasta']; }
        if ($on('dedicatoria')) { $conditions[] = 'NOACC(m.DEDICATORIA) LIKE ?'; $values[] = '%' . Db::noAcc($params['dedicatoria']) . '%'; }
        if ($on('localidad')) { $conditions[] = 'NOACC(m.LOCALIDAD) LIKE ?'; $values[] = '%' . Db::noAcc($params['localidad']) . '%'; }
        if ($on('provincia')) { $conditions[] = 'NOACC(m.PROVINCIA) LIKE ?'; $values[] = '%' . Db::noAcc($params['provincia']) . '%'; }
        if ($on('tipo')) { $conditions[] = 'm.TIPO = ?'; $values[] = $params['tipo']; }
        if ($on('estilo')) { $conditions[] = 'm.ESTILO = ?'; $values[] = $params['estilo']; }

        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';
        return ["EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA) AND $where", $values];
    }

    public static function searchMarchas(string $query, int $page = 1, int $limit = 20): array
    {
        parse_str($query, $params);
        [$baseWhere, $values] = self::marchaWhere($params);

        $orderBy = match ((string) ($params['orden'] ?? '')) {
            'grabaciones' => 'N_GRAB DESC, m.TITULO ASC',
            'fecha' => 'm.FECHA DESC, m.TITULO ASC',
            default => 'm.TITULO ASC',
        };

        $countRow = Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE $baseWhere", $values);
        $totalRows = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.DEDICATORIA, m.LOCALIDAD, m.PROVINCIA, m.AUDIO, m.FECHA,
                    m.BANDA_ESTRENO, b.NOMBRE_BREVE AS BANDA_BREVE,
                    (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) AS N_GRAB
             FROM marcha m
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE $baseWhere
             ORDER BY $orderBy LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        foreach ($rows as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);
        self::attachAutores($rows);

        return ['rowsReturned' => count($rows), 'totalRows' => $totalRows, 'data' => $rows];
    }

    /**
     * Facetas del explorador de marchas: tipo, estilo, provincia y década, cada
     * una contada sobre el resultado filtrado sin su propio criterio.
     * @return array{tipo:list<array>,estilo:list<array>,provincia:list<array>,decada:list<array>}
     */
    public static function marchaFacets(string $query): array
    {
        parse_str($query, $params);

        [$w, $v] = self::marchaWhere($params, 'tipo');
        $tipo = Db::all("SELECT m.TIPO AS K, COUNT(*) AS N FROM marcha m
                         WHERE $w AND m.TIPO IS NOT NULL AND m.TIPO != ''
                         GROUP BY m.TIPO ORDER BY N DESC LIMIT 6", $v);

        [$w, $v] = self::marchaWhere($params, 'estilo');
        $estilo = Db::all("SELECT m.ESTILO AS K, COUNT(*) AS N FROM marcha m
                           WHERE $w AND m.ESTILO IS NOT NULL AND m.ESTILO != ''
                           GROUP BY m.ESTILO ORDER BY N DESC LIMIT 6", $v);

        [$w, $v] = self::marchaWhere($params, 'provincia');
        $prov = Db::all("SELECT m.PROVINCIA AS K, COUNT(*) AS N FROM marcha m
                         WHERE $w AND m.PROVINCIA IS NOT NULL AND m.PROVINCIA != ''
                         GROUP BY m.PROVINCIA ORDER BY N DESC LIMIT 8", $v);

        [$w, $v] = self::marchaWhere($params, 'fecha');
        $dec = Db::all("SELECT (m.FECHA / 10) * 10 AS K, COUNT(*) AS N FROM marcha m
                        WHERE $w AND m.FECHA > 1900
                        GROUP BY K ORDER BY K DESC LIMIT 8", $v);

        return ['tipo' => $tipo, 'estilo' => $estilo, 'provincia' => $prov, 'decada' => $dec];
    }

    // ── Hubs de catálogo: año / estilo / provincia (C1, indexables) ──────────

    /**
     * Umbral de marchas para que un hub sea indexable y entre en el sitemap
     * (mismo criterio que DEDIC_MIN_MARCHAS: por debajo sería página thin).
     */
    public const HUB_MIN_MARCHAS = 2;

    /** Filas por página en los hubs (fijo: sin selector de límite). */
    public const HUB_PAGE_SIZE = 100;

    /**
     * Listado paginado de un hub: marchas vivas (con autor) que cumplen la
     * condición dada, con banda de estreno y nº de grabaciones, como el
     * explorador. Orden alfabético por título (consistente con /marcha).
     *
     * @param  list<mixed> $values
     * @return array{rowsReturned:int,totalRows:int,data:list<array<string,mixed>>}
     */
    private static function hubMarchas(string $condition, array $values, int $page): array
    {
        $where = "EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA) AND $condition";
        $totalRows = (int) (Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE $where", $values)['n'] ?? 0);
        $limit = self::HUB_PAGE_SIZE;
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.DEDICATORIA, m.LOCALIDAD, m.PROVINCIA, m.FECHA,
                    m.BANDA_ESTRENO, b.NOMBRE_BREVE AS BANDA_BREVE,
                    (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) AS N_GRAB
             FROM marcha m
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE $where
             ORDER BY m.TITULO ASC LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        foreach ($rows as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);
        self::attachAutores($rows);

        return ['rowsReturned' => count($rows), 'totalRows' => $totalRows, 'data' => $rows];
    }

    public static function marchasDeAnio(string $anio, int $page = 1): array
    {
        return self::hubMarchas('m.FECHA = ?', [$anio], $page);
    }

    /** Múltiplos de 25 años cubiertos por /aniversarios/{año} (centenarios incluidos). */
    public const ANIVERSARIO_TRAMOS = [25, 50, 75, 100, 125, 150, 175, 200];

    /**
     * Marchas que cumplen aniversario "redondo" (25/50/.../200 años) en
     * $anio: una entrada por cada tramo con marchas reales ese año de
     * composición (los años sin coincidencia se omiten, no se rellenan
     * vacíos). Reutiliza hubMarchas vía marchasDeAnio, así que cada entrada
     * ya trae compositor/provincia/grabaciones con la misma forma que el hub
     * de año — hasta HUB_PAGE_SIZE por tramo (basta para destacar; el enlace
     * al hub del año de composición cubre el catálogo completo si hay más).
     * @return list<array{ANIOS:int,ANIO_COMPUESTO:int,result:array}>
     */
    public static function aniversariosDe(string $anio): array
    {
        $anioInt = (int) $anio;
        $out = [];
        foreach (self::ANIVERSARIO_TRAMOS as $m) {
            $anioComp = $anioInt - $m;
            if ($anioComp < 1000) continue;
            $result = self::marchasDeAnio((string) $anioComp, 1);
            if ((int) $result['totalRows'] > 0) {
                $out[] = ['ANIOS' => $m, 'ANIO_COMPUESTO' => $anioComp, 'result' => $result];
            }
        }
        return $out;
    }

    /** $estilo es el valor de BD: 'CCTT' o 'AM'. */
    public static function marchasDeEstilo(string $estilo, int $page = 1): array
    {
        return self::hubMarchas('m.ESTILO = ?', [$estilo], $page);
    }

    /** $provincia es el nombre exacto de BD (resuelto antes desde el slug). */
    public static function marchasDeProvincia(string $provincia, int $page = 1): array
    {
        return self::hubMarchas('m.PROVINCIA = ?', [$provincia], $page);
    }

    /**
     * Años con marchas vivas, con recuento. Alimenta el sitemap y los enlaces
     * año anterior/siguiente del hub.
     * @return list<array{K:int,N:int}>
     */
    public static function hubAnios(): array
    {
        return Db::all(
            "SELECT m.FECHA AS K, COUNT(*) AS N FROM marcha m
             WHERE m.FECHA > 1000
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY m.FECHA ORDER BY m.FECHA ASC"
        );
    }

    /**
     * Estilos con marchas vivas, con recuento (solo los dos valores curados).
     * @return list<array{K:string,N:int}>
     */
    public static function hubEstilos(): array
    {
        return Db::all(
            "SELECT m.ESTILO AS K, COUNT(*) AS N FROM marcha m
             WHERE m.ESTILO IN ('CCTT', 'AM')
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY m.ESTILO ORDER BY N DESC"
        );
    }

    /**
     * Provincias con marchas vivas, con recuento, de más a menos marchas.
     * Sirve para el sitemap, para resolver slug → nombre y para la home.
     * @return list<array{K:string,N:int}>
     */
    public static function hubProvincias(): array
    {
        return Db::all(
            "SELECT m.PROVINCIA AS K, COUNT(*) AS N FROM marcha m
             WHERE m.PROVINCIA IS NOT NULL AND m.PROVINCIA != ''
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY m.PROVINCIA ORDER BY N DESC, m.PROVINCIA ASC"
        );
    }

    /**
     * Localidades con marchas vivas, con recuento y su provincia, de más a
     * menos marchas. Para el mapa por localidad (App\Mapa::puntos). Con
     * $provincia, filtra a una sola provincia (mapa ampliado de provincia).
     * @return list<array{LOCALIDAD:string,PROVINCIA:string,N:int}>
     */
    public static function hubLocalidades(?string $provincia = null): array
    {
        $where = "m.LOCALIDAD IS NOT NULL AND m.LOCALIDAD != ''
               AND m.PROVINCIA IS NOT NULL AND m.PROVINCIA != ''
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)";
        $values = [];
        if ($provincia !== null) {
            $where .= ' AND m.PROVINCIA = ?';
            $values[] = $provincia;
        }
        $rows = Db::all(
            "SELECT m.LOCALIDAD, m.PROVINCIA, COUNT(*) AS N FROM marcha m
             WHERE $where
             GROUP BY m.LOCALIDAD, m.PROVINCIA",
            $values
        );

        // Fusiona variantes de mayúsculas/acentos de una misma localidad —
        // dato heredado sin capitalización consistente (p.ej. "Aguilar De La
        // Frontera" y "Aguilar de la Frontera" en la misma provincia): sin
        // esto, GROUP BY las trata como localidades distintas y el mapa
        // acaba pintando dos puntos superpuestos en las mismas coordenadas
        // (misma localidad real → mismo match en municipios_es.php), cada
        // uno con su rótulo, ilegibles al solaparse.
        $grupos = [];
        foreach ($rows as $r) {
            $key = Db::noAcc((string) $r['PROVINCIA']) . '|' . Db::noAcc((string) $r['LOCALIDAD']);
            $grupos[$key]['provincia'] = $r['PROVINCIA'];
            $grupos[$key]['total'] = ($grupos[$key]['total'] ?? 0) + (int) $r['N'];
            $grupos[$key]['variantes'][$r['LOCALIDAD']] = (int) $r['N'];
        }
        $out = [];
        foreach ($grupos as $g) {
            arsort($g['variantes']);
            $out[] = ['LOCALIDAD' => array_key_first($g['variantes']), 'PROVINCIA' => $g['provincia'], 'N' => $g['total']];
        }
        usort($out, static fn(array $a, array $b): int => $b['N'] <=> $a['N'] ?: strcmp((string) $a['LOCALIDAD'], (string) $b['LOCALIDAD']));
        return $out;
    }

    // ── Admin: cargadores en crudo (para formularios de edición) ─────────────

    /** Fila cruda de marcha (sin normalizar FECHA, sin filtrar por autores). */
    public static function fetchMarchaRaw(string $id): ?array
    {
        return Db::one('SELECT * FROM marcha WHERE ID_MARCHA = ?', [$id]);
    }

    // ── Admin: curación de estilo (CCTT / AM) ─────────────────────────────────

    /**
     * Listado paginado de marchas para /dashboard/estilos, con la banda de
     * estreno y la de la primera grabación como contexto para decidir el
     * estilo a mano (mismo criterio que usa migrate_marcha_estilo.php).
     * @param array{estado?:string,q?:string} $filters
     * @return array{rowsReturned:int,totalRows:int,data:list<array<string,mixed>>}
     */
    public static function marchasEstiloAdmin(array $filters, int $page = 1, int $limit = 50): array
    {
        $conditions = [];
        $values = [];

        $estado = (string) ($filters['estado'] ?? 'pendiente');
        if ($estado === 'pendiente') {
            $conditions[] = 'm.ESTILO IS NULL';
        } elseif (in_array($estado, ['CCTT', 'AM'], true)) {
            $conditions[] = 'm.ESTILO = ?';
            $values[] = $estado;
        } // 'todos' → sin condición

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = 'NOACC(m.TITULO) LIKE ?';
            $values[] = '%' . Db::noAcc($q) . '%';
        }
        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';

        $countRow = Db::one("SELECT COUNT(*) AS n FROM marcha m WHERE $where", $values);
        $total = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.ESTILO, m.BANDA_ESTRENO,
                    be.NOMBRE_BREVE AS BANDA_ESTRENO_NOMBRE,
                    (SELECT bg.NOMBRE_BREVE
                       FROM disco_marcha dm
                       INNER JOIN disco d ON d.ID_DISCO = dm.ID_DISCO
                       LEFT OUTER JOIN banda bg ON bg.ID_BANDA = COALESCE(dm.DM_BANDA, d.BANDADISCO)
                      WHERE dm.IDMARCHA = m.ID_MARCHA
                      ORDER BY CAST(d.FECHA_CD AS REAL) ASC, d.NOMBRE_CD ASC LIMIT 1) AS PRIMERA_GRAB_BANDA
             FROM marcha m
             LEFT OUTER JOIN banda be ON be.ID_BANDA = m.BANDA_ESTRENO
             WHERE $where
             ORDER BY m.TITULO ASC
             LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        foreach ($rows as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);

        return ['rowsReturned' => count($rows), 'totalRows' => $total, 'data' => $rows];
    }

    /** Recuentos por estado para las pestañas de /dashboard/estilos. */
    public static function marchaEstiloCounts(): array
    {
        $row = Db::one(
            "SELECT COUNT(*) AS TODOS,
                    SUM(CASE WHEN ESTILO IS NULL THEN 1 ELSE 0 END) AS PENDIENTE,
                    SUM(CASE WHEN ESTILO = 'CCTT' THEN 1 ELSE 0 END) AS CCTT,
                    SUM(CASE WHEN ESTILO = 'AM' THEN 1 ELSE 0 END) AS AM
             FROM marcha"
        ) ?? [];
        return [
            'todos' => (int) ($row['TODOS'] ?? 0),
            'pendiente' => (int) ($row['PENDIENTE'] ?? 0),
            'CCTT' => (int) ($row['CCTT'] ?? 0),
            'AM' => (int) ($row['AM'] ?? 0),
        ];
    }

    /** Autores actuales de una marcha: [{ID_AUTOR, NOMBRE_COMPLETO}]. */
    public static function currentAutoresForMarcha(string $id): array
    {
        return Db::all(
            "SELECT a.ID_AUTOR, (a.NOMBRE || ' ' || a.APELLIDOS) AS NOMBRE_COMPLETO
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = ? ORDER BY a.APELLIDOS",
            [$id]
        );
    }

    /** Fila cruda de autor (para el formulario de edición). */
    public static function fetchAutorRaw(string $id): ?array
    {
        return Db::one('SELECT * FROM autor WHERE ID_AUTOR = ?', [$id]);
    }

    /** @param list<int> $ids  @return list<array{ID_AUTOR:int,NOMBRE_COMPLETO:string}> */
    public static function autoresByIds(array $ids): array
    {
        if ($ids === []) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Db::all(
            "SELECT ID_AUTOR, (NOMBRE || ' ' || APELLIDOS) AS NOMBRE_COMPLETO
             FROM autor WHERE ID_AUTOR IN ($ph) ORDER BY APELLIDOS",
            array_values($ids)
        );
    }

    /**
     * Predictivo de dedicatorias (panel de ingesta): combinaciones distintas de
     * DEDICATORIA/LOCALIDAD/PROVINCIA ya usadas en marchas existentes, para no
     * reescribir a mano hermandades que ya están en la BD. Ordenado por
     * frecuencia (la combinación más repetida primero).
     *
     * @return list<array{DEDICATORIA:string,LOCALIDAD:?string,PROVINCIA:?string,N:int}>
     */
    public static function searchDedicatorias(string $q, int $limit = 10): array
    {
        if (mb_strlen($q) < 7) return [];
        return Db::all(
            "SELECT DEDICATORIA, LOCALIDAD, PROVINCIA, COUNT(*) AS N
             FROM marcha
             WHERE DEDICATORIA IS NOT NULL AND DEDICATORIA <> '' AND DEDICATORIA LIKE ?
             GROUP BY DEDICATORIA, LOCALIDAD, PROVINCIA
             ORDER BY N DESC, DEDICATORIA ASC
             LIMIT ?",
            ['%' . $q . '%', $limit]
        );
    }

    // ── Autor ────────────────────────────────────────────────────────────────

    public static function fetchAutor(string $id): ?array
    {
        $autor = Db::one('SELECT * FROM autor WHERE ID_AUTOR = ?', [$id]);
        if ($autor === null) {
            return null;
        }
        $marchas = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.DEDICATORIA, m.PROVINCIA,
                    m.BANDA_ESTRENO, b.NOMBRE_BREVE AS BANDA_BREVE,
                    (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) AS N_GRAB
             FROM marcha m
             INNER JOIN marcha_autor ma ON ma.ID_MARCHA = m.ID_MARCHA
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE ma.ID_AUTOR = ? ORDER BY m.FECHA ASC",
            [$id]
        );
        foreach ($marchas as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);
        $autor['marchasLength'] = count($marchas);
        $autor['marchas'] = $marchas;

        // Ficha de catálogo: grabaciones totales, periodo de actividad,
        // banda con más estrenos de su obra y posición del registro.
        $autor['N_GRAB_TOTAL'] = array_sum(array_map(static fn(array $x): int => (int) $x['N_GRAB'], $marchas));
        $years = array_values(array_filter(array_map(static fn(array $x): int => (int) $x['FECHA'], $marchas), static fn(int $y): bool => $y > 1000));
        $autor['ACT_DESDE'] = $years !== [] ? min($years) : 0;
        $autor['ACT_HASTA'] = $years !== [] ? max($years) : 0;
        $autor['BANDA_PPAL'] = Db::one(
            "SELECT b.ID_BANDA, b.NOMBRE_BREVE, COUNT(*) AS N
             FROM marcha m
             INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             INNER JOIN marcha_autor ma ON ma.ID_MARCHA = m.ID_MARCHA
             WHERE ma.ID_AUTOR = ? GROUP BY b.ID_BANDA ORDER BY N DESC LIMIT 1",
            [$id]
        );
        $autor['REG_TOTAL'] = (int) (Db::one('SELECT COUNT(*) AS n FROM autor')['n'] ?? 0);
        $autor['REG_POS'] = (int) (Db::one('SELECT COUNT(*) AS n FROM autor WHERE ID_AUTOR <= ?', [$id])['n'] ?? 0);
        return $autor;
    }

    /**
     * Autores cuyo NOMBRE, APELLIDOS o NOMBRE_ART contienen (por subcadena,
     * insensible a mayúsculas/acentos) cada palabra de $q — igual criterio
     * que searchDedicatorias/searchBandas, en vez de FTS5 (que solo hace
     * match de palabra completa y por eso el predictivo de autor iba peor
     * que el de dedicatoria). Pensado para autocompletar desde 3 caracteres.
     *
     * @return list<array{ID_AUTOR:int,NOMBRE_COMPLETO:string}>
     */
    public static function autorCandidatosPorTexto(string $q, int $limit = 15): array
    {
        $tokens = preg_split('/\s+/u', trim(Db::noAcc($q)), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === []) return [];

        $conditions = [];
        $values = [];
        foreach ($tokens as $t) {
            $conditions[] = '(NOACC(NOMBRE) LIKE ? OR NOACC(APELLIDOS) LIKE ? OR NOACC(NOMBRE_ART) LIKE ?)';
            $needle = '%' . $t . '%';
            array_push($values, $needle, $needle, $needle);
        }
        $where = implode(' AND ', $conditions);

        return Db::all(
            "SELECT ID_AUTOR, (NOMBRE || ' ' || APELLIDOS) AS NOMBRE_COMPLETO
             FROM autor WHERE $where
             ORDER BY APELLIDOS ASC LIMIT ?",
            [...$values, $limit]
        );
    }

    /**
     * Mejor autor existente para un nombre de texto libre (p.ej. extraído de
     * un título de YouTube), comparado por similitud de texto. Devuelve null
     * si no hay ningún candidato con solapamiento léxico.
     *
     * @return array{ID_AUTOR:int,NOMBRE_COMPLETO:string,score:float}|null
     */
    public static function mejorAutorPorNombre(string $nombre): ?array
    {
        $rows = self::autorCandidatosPorTexto($nombre, 50);

        $mejor = null;
        foreach ($rows as $r) {
            $score = Similarity::ratio($nombre, (string) $r['NOMBRE_COMPLETO']);
            if ($mejor === null || $score > $mejor['score']) {
                $mejor = ['ID_AUTOR' => (int) $r['ID_AUTOR'], 'NOMBRE_COMPLETO' => (string) $r['NOMBRE_COMPLETO'], 'score' => $score];
            }
        }
        return $mejor;
    }

    public static function searchAutores(string $query, int $page = 1, int $limit = 20): array
    {
        parse_str($query, $params);
        $nombre = (string) ($params['nombre'] ?? '');
        $fts = $nombre !== '' ? self::buildFtsQuery($nombre) : null;
        $where = $fts !== null ? 'a.ID_AUTOR IN (SELECT rowid FROM autor_fts WHERE autor_fts MATCH ?)' : '1=1';
        $values = $fts !== null ? [$fts] : [];

        $countRow = Db::one("SELECT COUNT(*) AS n FROM autor a WHERE $where", $values);
        $totalRows = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT a.*, (a.NOMBRE || ' ' || a.APELLIDOS) AS NOMBRE_COMPLETO,
                    (SELECT COUNT(ma.ID_MARCHA) FROM marcha_autor ma WHERE ma.ID_AUTOR = a.ID_AUTOR) AS MARCHAS
             FROM autor a WHERE $where ORDER BY a.APELLIDOS ASC LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        return ['rowsReturned' => count($rows), 'totalRows' => $totalRows, 'data' => $rows];
    }

    // ── Banda ──────────────────────────────────────────────────────────────

    public static function fetchBanda(string $id): ?array
    {
        $banda = Db::one('SELECT * FROM banda WHERE ID_BANDA = ?', [$id]);
        if ($banda === null) {
            return null;
        }

        $timeline = [[
            'ID_BANDA'    => $banda['ID_BANDA'],
            'FECHA_FUND'  => $banda['FECHA_FUND'],
            'FECHA_EXT'   => $banda['FECHA_EXT'],
            'NOMBRE_BREVE' => $banda['NOMBRE_BREVE'],
        ]];

        $discos = Db::all(
            "SELECT DISTINCT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD,
                    (SELECT COUNT(m.ID_DM) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS PISTAS,
                    (SELECT MAX(m.N_DISCO) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS DISCOS
             FROM disco d WHERE d.BANDADISCO = ? ORDER BY d.FECHA_CD ASC",
            [$id]
        );

        $marchas = Db::all(
            "SELECT m.TITULO, m.ID_MARCHA, m.DEDICATORIA, m.LOCALIDAD, m.FECHA,
                    (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) AS N_GRAB
             FROM marcha m
             WHERE m.BANDA_ESTRENO = ?
               AND EXISTS (SELECT 1 FROM marcha_autor am WHERE am.ID_MARCHA = m.ID_MARCHA)
             ORDER BY m.FECHA DESC, m.TITULO ASC",
            [$id]
        );
        self::attachAutores($marchas); // fetchBanda solo aplica formatAutor, NO normalizeFecha

        usort($timeline, static fn(array $a, array $b): int => (int) $a['FECHA_FUND'] <=> (int) $b['FECHA_FUND']);

        $banda['timeline'] = $timeline;
        $banda['linaje'] = self::bandaLinaje($id);
        $banda['discosLength'] = count($discos);
        $banda['discos'] = $discos;
        $banda['marchasLength'] = count($marchas);
        $banda['marchas'] = $marchas;

        // Estrenos por formación (línea de sucesión) y posición del registro.
        $ids = [(int) $banda['ID_BANDA']];
        if ($banda['linaje'] !== null) {
            foreach (array_merge($banda['linaje']['up'], $banda['linaje']['down']) as $lvl) {
                foreach ($lvl as $n) $ids[] = (int) $n['ID'];
            }
            foreach (array_merge($banda['linaje']['juveniles'], $banda['linaje']['madres']) as $n) {
                $ids[] = (int) $n['ID_BANDA'];
            }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach (Db::all("SELECT BANDA_ESTRENO AS B, COUNT(*) AS N FROM marcha WHERE BANDA_ESTRENO IN ($ph) GROUP BY BANDA_ESTRENO", $ids) as $r) {
            $map[(int) $r['B']] = (int) $r['N'];
        }
        $banda['ESTRENOS_MAP'] = $map;
        $banda['REG_TOTAL'] = (int) (Db::one('SELECT COUNT(*) AS n FROM banda')['n'] ?? 0);
        $banda['REG_POS'] = (int) (Db::one('SELECT COUNT(*) AS n FROM banda WHERE ID_BANDA <= ?', [$id])['n'] ?? 0);
        return $banda;
    }

    /**
     * WHERE + values de la búsqueda de bandas. $exclude omite un criterio
     * (para calcular facetas sin su propio filtro).
     * @return array{0:string,1:list<mixed>}
     */
    private static function bandaWhere(array $params, ?string $exclude = null): array
    {
        $conditions = [];
        $values = [];
        $on = static fn(string $k): bool => $k !== $exclude && !empty($params[$k]);
        if (!empty($params['titulo'])) { $conditions[] = 'NOACC(b.NOMBRE_COMPLETO) LIKE ?'; $values[] = '%' . Db::noAcc($params['titulo']) . '%'; }
        if ($on('localidad')) { $conditions[] = 'NOACC(b.LOCALIDAD) LIKE ?'; $values[] = '%' . Db::noAcc($params['localidad']) . '%'; }
        if ($on('provincia')) { $conditions[] = 'NOACC(b.PROVINCIA) LIKE ?'; $values[] = '%' . Db::noAcc($params['provincia']) . '%'; }
        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';
        return [$where, $values];
    }

    /** Columnas ordenables del explorador de bandas: clave pública → SQL. */
    private const BANDA_ORDEN = ['nombre' => 'b.NOMBRE_BREVE', 'localidad' => 'b.LOCALIDAD', 'fundacion' => 'b.FECHA_FUND'];

    public static function searchBandas(string $query, int $page = 1, int $limit = 20): array
    {
        parse_str($query, $params);
        [$where, $values] = self::bandaWhere($params);

        // Sin 'orden' explícito se preserva el orden histórico (paridad con Next).
        if (isset(self::BANDA_ORDEN[(string) ($params['orden'] ?? '')])) {
            $col = self::BANDA_ORDEN[$params['orden']];
            $dir = ((string) ($params['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
            $orderBy = "$col $dir, b.NOMBRE_BREVE ASC";
        } else {
            $orderBy = 'b.NOMBRE_BREVE ASC';
        }

        $countRow = Db::one("SELECT COUNT(*) AS n FROM banda b WHERE $where", $values);
        $totalRows = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT b.ID_BANDA, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO, b.PROVINCIA,
                    b.LOCALIDAD, b.FECHA_FUND, b.FECHA_EXT,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
             FROM banda b WHERE $where
             GROUP BY b.ID_BANDA ORDER BY $orderBy LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        return ['rowsReturned' => count($rows), 'totalRows' => $totalRows, 'data' => $rows];
    }

    /**
     * Facetas del explorador de bandas: provincia, contada sin su propio filtro.
     * @return array{provincia:list<array>}
     */
    public static function bandaFacets(string $query): array
    {
        parse_str($query, $params);
        [$w, $v] = self::bandaWhere($params, 'provincia');
        $prov = Db::all("SELECT b.PROVINCIA AS K, COUNT(*) AS N FROM banda b
                         WHERE $w AND b.PROVINCIA IS NOT NULL AND b.PROVINCIA != ''
                         GROUP BY b.PROVINCIA ORDER BY N DESC, b.PROVINCIA ASC LIMIT 12", $v);
        return ['provincia' => $prov];
    }

    /** Fila cruda de banda (para el panel de relaciones). */
    public static function fetchBandaRaw(string $id): ?array
    {
        return Db::one('SELECT * FROM banda WHERE ID_BANDA = ?', [$id]);
    }

    /**
     * Bandas cuyo NOMBRE_BREVE, NOMBRE_COMPLETO o LOCALIDAD contienen (subcadena,
     * insensible a mayúsculas/acentos) cada palabra de $q. Para el autocompletar
     * del selector "otra banda" en las relaciones de linaje.
     *
     * @return list<array{ID_BANDA:int,NOMBRE_BREVE:string,LOCALIDAD:?string,LABEL:string}>
     */
    public static function bandaCandidatosPorTexto(string $q, int $limit = 15): array
    {
        $tokens = preg_split('/\s+/u', trim(Db::noAcc($q)), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === []) return [];

        $conditions = [];
        $values = [];
        foreach ($tokens as $t) {
            $conditions[] = '(NOACC(NOMBRE_BREVE) LIKE ? OR NOACC(NOMBRE_COMPLETO) LIKE ? OR NOACC(LOCALIDAD) LIKE ?)';
            $needle = '%' . $t . '%';
            array_push($values, $needle, $needle, $needle);
        }
        $where = implode(' AND ', $conditions);

        return Db::all(
            "SELECT ID_BANDA, NOMBRE_BREVE, LOCALIDAD,
                    (NOMBRE_BREVE || CASE WHEN LOCALIDAD IS NOT NULL AND LOCALIDAD <> ''
                                          THEN ' (' || LOCALIDAD || ')' ELSE '' END) AS LABEL
             FROM banda WHERE $where
             ORDER BY NOMBRE_BREVE ASC LIMIT ?",
            [...$values, $limit]
        );
    }

    /**
     * Relaciones de linaje en las que participa una banda (como origen o destino),
     * con el nombre de las dos puntas resuelto. Ordenadas por tipo y fecha.
     *
     * @return list<array<string,mixed>>
     */
    public static function bandaRelaciones(string $id): array
    {
        return Db::all(
            "SELECT r.ID_RELACION, r.TIPO, r.FECHA_INICIO, r.FECHA_FIN, r.NOTA,
                    r.ID_ORIGEN, r.ID_DESTINO,
                    bo.NOMBRE_BREVE AS ORIGEN_NOMBRE, bo.LOCALIDAD AS ORIGEN_LOC,
                    bd.NOMBRE_BREVE AS DESTINO_NOMBRE, bd.LOCALIDAD AS DESTINO_LOC
             FROM banda_relacion r
             LEFT JOIN banda bo ON bo.ID_BANDA = r.ID_ORIGEN
             LEFT JOIN banda bd ON bd.ID_BANDA = r.ID_DESTINO
             WHERE r.ID_ORIGEN = ? OR r.ID_DESTINO = ?
             ORDER BY r.TIPO ASC, r.FECHA_INICIO ASC",
            [$id, $id]
        );
    }

    /**
     * Linaje de una banda para la ficha pública. Recorre `banda_relacion` por
     * niveles (BFS) hacia atrás (predecesoras) y hacia delante (sucesoras), más
     * las juveniles (lateral) y la madre si la banda es juvenil. Cada nodo lleva
     * el TIPO de la arista que lo conecta hacia el lado del foco (para el chip).
     * De-duplica por banda y corta a 5 niveles y en ciclos.
     *
     * @return array{focus:array,up:list<list<array>>,down:list<list<array>>,juveniles:list<array>,madres:list<array>}|null
     */
    public static function bandaLinaje(string $id): ?array
    {
        $focus = Db::one(
            'SELECT ID_BANDA, NOMBRE_BREVE, LOCALIDAD, FECHA_FUND, FECHA_EXT FROM banda WHERE ID_BANDA = ?',
            [$id]
        );
        if ($focus === null) return null;
        $fid = (int) $focus['ID_BANDA'];

        $bfs = static function (string $dir) use ($fid): array {
            // 'up' = predecesoras (aristas cuyo DESTINO es el nodo → tomamos ORIGEN).
            $anchor = $dir === 'up' ? 'ID_DESTINO' : 'ID_ORIGEN';
            $take   = $dir === 'up' ? 'ID_ORIGEN' : 'ID_DESTINO';
            $levels = [];
            $frontier = [$fid];
            $visited = [$fid => true];
            $depth = 0;
            while ($frontier !== [] && $depth < 5) {
                $ph = implode(',', array_fill(0, count($frontier), '?'));
                $rows = Db::all(
                    "SELECT r.TIPO, r.$take AS BID, b.NOMBRE_BREVE, b.LOCALIDAD, b.FECHA_FUND, b.FECHA_EXT
                     FROM banda_relacion r JOIN banda b ON b.ID_BANDA = r.$take
                     WHERE r.$anchor IN ($ph) AND r.TIPO IN ('renombrado','fusion','division')
                     ORDER BY b.FECHA_FUND ASC, b.NOMBRE_BREVE ASC",
                    $frontier
                );
                $nodes = [];
                $next = [];
                foreach ($rows as $r) {
                    $bid = (int) $r['BID'];
                    if (isset($visited[$bid])) continue;
                    $visited[$bid] = true;
                    $nodes[] = [
                        'ID' => $bid, 'NOMBRE' => $r['NOMBRE_BREVE'], 'LOC' => $r['LOCALIDAD'],
                        'FUND' => $r['FECHA_FUND'], 'EXT' => $r['FECHA_EXT'], 'TIPO' => $r['TIPO'],
                    ];
                    $next[] = $bid;
                }
                if ($nodes === []) break;
                $levels[] = $nodes;
                $frontier = $next;
                $depth++;
            }
            return $levels;
        };

        $up = $bfs('up');
        $down = $bfs('down');
        $juveniles = Db::all(
            "SELECT r.FECHA_INICIO, r.FECHA_FIN, b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
             FROM banda_relacion r JOIN banda b ON b.ID_BANDA = r.ID_DESTINO
             WHERE r.ID_ORIGEN = ? AND r.TIPO = 'juvenil' ORDER BY r.FECHA_INICIO ASC",
            [$fid]
        );
        $madres = Db::all(
            "SELECT r.FECHA_INICIO, r.FECHA_FIN, b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
             FROM banda_relacion r JOIN banda b ON b.ID_BANDA = r.ID_ORIGEN
             WHERE r.ID_DESTINO = ? AND r.TIPO = 'juvenil' ORDER BY r.FECHA_INICIO ASC",
            [$fid]
        );

        if ($up === [] && $down === [] && $juveniles === [] && $madres === []) return null;
        return ['focus' => $focus, 'up' => $up, 'down' => $down, 'juveniles' => $juveniles, 'madres' => $madres];
    }

    // ── Disco ──────────────────────────────────────────────────────────────

    public static function fetchDisco(string $id): ?array
    {
        $disco = Db::one(
            "SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, d.d_DETALLES, b.ID_BANDA,
                    b.NOMBRE_BREVE AS BANDA_BREVE, b.NOMBRE_COMPLETO AS BANDA_COMPLETO, b.LOCALIDAD AS BANDA_LOC,
                    (SELECT MAX(m.N_DISCO) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS DISCOS,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
             FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
             WHERE d.ID_DISCO = ?",
            [$id]
        );
        if ($disco === null) {
            return null;
        }
        $disco['REG_TOTAL'] = (int) (Db::one('SELECT COUNT(*) AS n FROM disco')['n'] ?? 0);
        $disco['REG_POS'] = (int) (Db::one('SELECT COUNT(*) AS n FROM disco WHERE ID_DISCO <= ?', [$id])['n'] ?? 0);
        $marchas = Db::all(
            "SELECT dm.N_DISCO, dm.NUMEROMARCHA, m.ID_MARCHA, m.TITULO, m.FECHA,
                    CASE WHEN dm.DM_ENLAZADA IS NULL THEN 0 ELSE 1 END AS ENLAZADA
             FROM disco d
             INNER JOIN disco_marcha dm ON dm.ID_DISCO = d.ID_DISCO
             INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
             WHERE d.ID_DISCO = ?
               AND EXISTS (SELECT 1 FROM marcha_autor am WHERE am.ID_MARCHA = m.ID_MARCHA)
             ORDER BY dm.N_DISCO ASC, dm.NUMEROMARCHA ASC, dm.DM_ENLAZADA ASC",
            [$id]
        );
        foreach ($marchas as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);
        self::attachAutores($marchas);

        $disco['marchasLength'] = count($marchas);
        $disco['marchas'] = $marchas;
        return $disco;
    }

    /**
     * WHERE + values de la búsqueda de discos. $exclude omite un criterio
     * (para calcular facetas sin su propio filtro).
     * @return array{0:string,1:list<mixed>}
     */
    private static function discoWhere(array $params, ?string $exclude = null): array
    {
        $conditions = [];
        $values = [];
        $nombre = (string) ($params['nombre'] ?? '');
        if ($nombre !== '') { $conditions[] = 'NOACC(d.NOMBRE_CD) LIKE ?'; $values[] = '%' . Db::noAcc($nombre) . '%'; }
        if ($exclude !== 'decada' && !empty($params['decada'])) {
            $d0 = (int) $params['decada'];
            $conditions[] = 'CAST(d.FECHA_CD AS INTEGER) BETWEEN ? AND ?';
            $values[] = $d0; $values[] = $d0 + 9;
        }
        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';
        return [$where, $values];
    }

    /** Columnas ordenables del explorador de discos: clave pública → SQL. */
    private const DISCO_ORDEN = ['nombre' => 'd.NOMBRE_CD', 'banda' => 'b.NOMBRE_BREVE', 'anio' => 'CAST(d.FECHA_CD AS INTEGER)'];

    public static function searchDiscos(string $query, int $page = 1, int $limit = 20): array
    {
        parse_str($query, $params);
        [$where, $values] = self::discoWhere($params);

        // Sin 'orden' explícito se preserva el orden histórico (paridad con Next).
        if (isset(self::DISCO_ORDEN[(string) ($params['orden'] ?? '')])) {
            $col = self::DISCO_ORDEN[$params['orden']];
            $dir = ((string) ($params['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
            $orderBy = "$col $dir, d.NOMBRE_CD ASC";
        } else {
            $orderBy = 'd.FECHA_CD ASC';
        }

        $countRow = Db::one("SELECT COUNT(*) AS n FROM disco d WHERE $where", $values);
        $totalRows = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, b.ID_BANDA,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
                    EXISTS (SELECT 1 FROM enlace_streaming es
                            WHERE es.TIPO_ENT = 'disco' AND es.ID_ENT = d.ID_DISCO) AS TIENE_STREAMING
             FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
             WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );
        return ['rowsReturned' => count($rows), 'totalRows' => $totalRows, 'data' => $rows];
    }

    /**
     * Facetas del explorador de discos: década (de FECHA_CD), sin su propio filtro.
     * @return array{decada:list<array>}
     */
    public static function discoFacets(string $query): array
    {
        parse_str($query, $params);
        [$w, $v] = self::discoWhere($params, 'decada');
        $dec = Db::all("SELECT (CAST(d.FECHA_CD AS INTEGER) / 10) * 10 AS K, COUNT(*) AS N FROM disco d
                        WHERE $w AND CAST(d.FECHA_CD AS INTEGER) > 1900
                        GROUP BY K ORDER BY K DESC LIMIT 10", $v);
        return ['decada' => $dec];
    }

    // ── Búsqueda global unificada (M3) ───────────────────────────────────────

    /**
     * Consulta FTS5 de PREFIJO: cada token pasa a "token"* para que "amarg"
     * encuentre "amargura". Espacio entre términos = AND implícito en FTS5.
     * Solo letras/números (se descartan signos) → sintaxis FTS siempre válida.
     */
    private static function buildFtsPrefixQuery(string $raw): ?string
    {
        $cleaned = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $raw));
        if ($cleaned === '') {
            return null;
        }
        $tokens = preg_split('/\s+/', $cleaned) ?: [];
        return implode(' ', array_map(static fn(string $t): string => '"' . $t . '"*', $tokens));
    }

    /**
     * Búsqueda unificada sobre las cuatro entidades para una sola caja: la usan
     * la página /buscar y el autocompletado público (/api/buscar). Devuelve como
     * mucho $limit resultados por tipo, cada uno con lo justo para pintar una
     * fila (id, etiqueta, subtítulo) y su URL canónica.
     *
     * Matching por tipo — decisión deliberada, no uniforme:
     *   · marchas y compositores: FTS5 de PREFIJO (marcha_fts / autor_fts ya
     *     existen; son las tablas grandes → índice invertido, no full-scan).
     *   · bandas y discos: LIKE por subcadena con NOACC (no tienen tabla FTS y
     *     son diminutas — 270 y 430 filas; el escaneo es instantáneo y permite
     *     coincidencia a mitad de palabra, como el resto de predictivos del
     *     panel, ver autorCandidatosPorTexto).
     * El issue del consejo sugería "prefijos FTS5"; se honra donde hay FTS y se
     * cae a LIKE donde no lo hay, sin bloquear la función por ello.
     *
     * @return array{marchas:list<array>,autores:list<array>,bandas:list<array>,discos:list<array>,total:int}
     */
    public static function buscarGlobal(string $q, int $limit = 8): array
    {
        $out = ['marchas' => [], 'autores' => [], 'bandas' => [], 'discos' => [], 'total' => 0];
        if (mb_strlen(trim($q)) < 2) {
            return $out;
        }

        // ── Marchas y compositores: FTS5 de prefijo ──────────────────────────
        $fts = self::buildFtsPrefixQuery($q);
        if ($fts !== null) {
            $marchas = Db::all(
                "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.PROVINCIA
                 FROM marcha m
                 WHERE m.ID_MARCHA IN (SELECT rowid FROM marcha_fts WHERE marcha_fts MATCH ?)
                   AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
                 ORDER BY m.TITULO ASC LIMIT ?",
                [$fts, $limit]
            );
            foreach ($marchas as &$r) {
                self::normalizeFecha($r);
            }
            unset($r);
            self::attachAutores($marchas);
            $out['marchas'] = $marchas;

            $out['autores'] = Db::all(
                "SELECT a.ID_AUTOR, (a.NOMBRE || ' ' || a.APELLIDOS) AS NOMBRE_COMPLETO,
                        (SELECT COUNT(*) FROM marcha_autor ma WHERE ma.ID_AUTOR = a.ID_AUTOR) AS N_MARCHAS
                 FROM autor a
                 WHERE a.ID_AUTOR IN (SELECT rowid FROM autor_fts WHERE autor_fts MATCH ?)
                 ORDER BY a.APELLIDOS ASC LIMIT ?",
                [$fts, $limit]
            );
        }

        // ── Bandas y discos: LIKE por subcadena (AND de tokens) ──────────────
        $tokens = preg_split('/\s+/u', trim(Db::noAcc($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens !== []) {
            $condsBanda = [];
            $valsBanda = [];
            foreach ($tokens as $t) {
                $condsBanda[] = '(NOACC(b.NOMBRE_BREVE) LIKE ? OR NOACC(b.NOMBRE_COMPLETO) LIKE ? OR NOACC(b.LOCALIDAD) LIKE ?)';
                $needle = '%' . $t . '%';
                array_push($valsBanda, $needle, $needle, $needle);
            }
            $out['bandas'] = Db::all(
                "SELECT b.ID_BANDA, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO, b.LOCALIDAD
                 FROM banda b WHERE " . implode(' AND ', $condsBanda) . "
                 ORDER BY b.NOMBRE_BREVE ASC LIMIT ?",
                [...$valsBanda, $limit]
            );

            $condsDisco = [];
            $valsDisco = [];
            foreach ($tokens as $t) {
                $condsDisco[] = 'NOACC(d.NOMBRE_CD) LIKE ?';
                $valsDisco[] = '%' . $t . '%';
            }
            $out['discos'] = Db::all(
                "SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, b.NOMBRE_BREVE AS BANDA_BREVE
                 FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
                 WHERE " . implode(' AND ', $condsDisco) . "
                 ORDER BY d.NOMBRE_CD ASC LIMIT ?",
                [...$valsDisco, $limit]
            );
        }

        $out['total'] = count($out['marchas']) + count($out['autores'])
            + count($out['bandas']) + count($out['discos']);
        return $out;
    }

    // ── Datos mínimos para la og:image dinámica (M4) ─────────────────────────

    /**
     * Título, subtítulo y sobretítulo (tipo) para pintar la tarjeta social de
     * una entidad. Consultas ligeras (una por entidad), no las fetch* completas.
     *
     * @return array{overline:string,titulo:string,sub:string}|null  null si no existe
     */
    public static function ogDatos(string $tipo, int $id): ?array
    {
        switch ($tipo) {
            case 'marcha':
                $r = Db::one(
                    "SELECT m.TITULO, m.FECHA,
                            (SELECT a.NOMBRE || ' ' || a.APELLIDOS
                             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
                             WHERE ma.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS LIMIT 1) AS COMPOSITOR
                     FROM marcha m
                     WHERE m.ID_MARCHA = ?
                       AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)",
                    [$id]
                );
                if ($r === null) return null;
                $anio = (!empty($r['FECHA'])) ? (int) $r['FECHA'] : null;
                $sub = trim(((string) ($r['COMPOSITOR'] ?? '')) . ($anio ? ' · ' . $anio : ''), ' ·');
                return ['overline' => 'Marcha procesional', 'titulo' => (string) $r['TITULO'], 'sub' => $sub];

            case 'autor':
                $r = Db::one(
                    "SELECT (NOMBRE || ' ' || APELLIDOS) AS NOMBRE,
                            (SELECT COUNT(*) FROM marcha_autor WHERE ID_AUTOR = ?) AS N
                     FROM autor WHERE ID_AUTOR = ?",
                    [$id, $id]
                );
                if ($r === null) return null;
                $n = (int) $r['N'];
                return ['overline' => 'Compositor', 'titulo' => (string) $r['NOMBRE'],
                        'sub' => $n === 1 ? '1 marcha' : $n . ' marchas'];

            case 'banda':
                $r = Db::one('SELECT NOMBRE_BREVE, NOMBRE_COMPLETO, LOCALIDAD FROM banda WHERE ID_BANDA = ?', [$id]);
                if ($r === null) return null;
                $titulo = (string) ($r['NOMBRE_BREVE'] ?: $r['NOMBRE_COMPLETO']);
                return ['overline' => 'Banda', 'titulo' => $titulo, 'sub' => (string) ($r['LOCALIDAD'] ?? '')];

            case 'disco':
                $r = Db::one(
                    "SELECT d.NOMBRE_CD, d.FECHA_CD, b.NOMBRE_BREVE AS BANDA
                     FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
                     WHERE d.ID_DISCO = ?",
                    [$id]
                );
                if ($r === null) return null;
                $anio = (!empty($r['FECHA_CD'])) ? (int) (float) $r['FECHA_CD'] : null;
                $sub = trim(((string) ($r['BANDA'] ?? '')) . ($anio ? ' · ' . $anio : ''), ' ·');
                return ['overline' => 'Disco', 'titulo' => (string) $r['NOMBRE_CD'], 'sub' => $sub];
        }
        return null;
    }

    // ── Últimas incorporaciones ──────────────────────────────────────────────

    public static function fetchUltimas(): array
    {
        $rows = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.BANDA_ESTRENO, b.NOMBRE_BREVE AS BANDA_BREVE
             FROM marcha m
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             ORDER BY m.ID_MARCHA DESC LIMIT 5"
        );
        foreach ($rows as &$r) {
            self::normalizeFecha($r);
        }
        unset($r);
        self::attachAutores($rows);
        return $rows;
    }

    // ── Marcha del día (home, C3) ─────────────────────────────────────────────

    /**
     * IDs candidatos para "marcha del día": marchas vivas (con autor),
     * priorizando las que tienen audio embebido (mejor experiencia, con
     * reproductor visible). Si ninguna marcha tiene audio aún, cae a
     * cualquier marcha viva para no dejar la sección vacía.
     *
     * @return list<int>
     */
    public static function marchaDelDiaCandidatos(): array
    {
        $ids = static fn(array $rows): array => array_map(static fn(array $r): int => (int) $r['ID_MARCHA'], $rows);

        $conAudio = Db::all(
            "SELECT m.ID_MARCHA FROM marcha m
             WHERE m.AUDIO IS NOT NULL AND m.AUDIO != ''
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             ORDER BY m.ID_MARCHA"
        );
        if ($conAudio !== []) {
            return $ids($conAudio);
        }
        return $ids(Db::all(
            "SELECT m.ID_MARCHA FROM marcha m
             WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             ORDER BY m.ID_MARCHA"
        ));
    }

    // ── Dedicatorias: hubs de advocación (N-01 / N-02) ───────────────────────

    /**
     * Umbral de marchas para considerar un hub «con sustancia» y por tanto
     * indexable. Por debajo, el hub duplicaría una única ficha (página thin);
     * resuelve igualmente pero va noindex y no aparece en el índice ni el sitemap.
     */
    public const DEDIC_MIN_MARCHAS = 2;

    /**
     * ¿Es una dedicatoria PARTICULAR (a una persona o grupo concreto: "A Manuel
     * Rodríguez Ruiz", "Al Padre Del Autor", "A La Banda De Las Cigarreras") en
     * vez de institucional (hermandad, cofradía, agrupación, advocación mariana
     * o cristológica: "Hdad Esperanza", "Virgen Del Carmen", "Cristo De La
     * Expiración")? Heurística: el primer token del nombre (sin acentos ni
     * mayúsculas) es exactamente "a" o "al" — la preposición de dedicatoria a
     * alguien concreto. No confunde "Agrupación" o "Asociación" porque exige
     * coincidencia del token completo, no solo el prefijo.
     */
    public static function esDedicatoriaPersonal(string $nombre): bool
    {
        $first = explode(' ', trim(Db::noAcc($nombre)))[0] ?? '';
        return $first === 'a' || $first === 'al';
    }

    /**
     * Índice A–Z de advocaciones con recuento de marchas (pantalla N-02). Solo
     * canónicas institucionales (PERSONAL = 0) con ≥ DEDIC_MIN_MARCHAS marchas
     * vivas (con autor); opcionalmente filtradas por localidad/provincia
     * (LIKE, insensible a mayúsculas/acentos, igual que Repo::searchMarchas).
     * Se ordena por SLUG_KEY, cuya parte de nombre ya viene sin artículo ni
     * prefijo de tipo, de modo que «La Estrella» alfabetiza por «Estrella».
     *
     * @return list<array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,SLUG_KEY:string,N:int}>
     */
    public static function dedicatoriaIndex(?string $localidad = null, ?string $provincia = null): array
    {
        $sql =
            "SELECT d.ID_DEDIC, d.NOMBRE, d.LOCALIDAD, d.PROVINCIA, d.SLUG_KEY,
                    COUNT(m.ID_MARCHA) AS N
             FROM dedicatoria d
             JOIN dedicatoria_alias da ON da.ID_DEDIC = d.ID_DEDIC
             JOIN marcha m ON m.DEDICATORIA = da.VARIANTE
                          AND COALESCE(m.LOCALIDAD, '') = da.LOCALIDAD
             WHERE d.PERSONAL = 0
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)";
        $params = [];
        if ($localidad !== null && trim($localidad) !== '') {
            $sql .= ' AND NOACC(d.LOCALIDAD) LIKE ?';
            $params[] = '%' . Db::noAcc($localidad) . '%';
        }
        if ($provincia !== null && trim($provincia) !== '') {
            $sql .= ' AND NOACC(d.PROVINCIA) LIKE ?';
            $params[] = '%' . Db::noAcc($provincia) . '%';
        }
        $sql .= ' GROUP BY d.ID_DEDIC HAVING N >= ' . self::DEDIC_MIN_MARCHAS . ' ORDER BY d.SLUG_KEY';
        return Db::all($sql, $params);
    }

    /**
     * Hub de una advocación (pantalla N-01): la canónica + sus marchas dedicadas
     * (con autores, año, banda de estreno y nº de grabaciones), en orden
     * cronológico. Devuelve null si no existe o no tiene marchas vivas.
     *
     * @return array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,SLUG_KEY:string,PERSONAL:int,marchas:list<array<string,mixed>>,N:int}|null
     */
    public static function fetchDedicatoria(string $id): ?array
    {
        $d = Db::one(
            'SELECT ID_DEDIC, NOMBRE, LOCALIDAD, PROVINCIA, SLUG_KEY, PERSONAL FROM dedicatoria WHERE ID_DEDIC = ?',
            [$id]
        );
        if ($d === null) {
            return null;
        }
        $marchas = Db::all(
            "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.LOCALIDAD, m.PROVINCIA, m.AUDIO,
                    m.BANDA_ESTRENO, b.NOMBRE_BREVE AS BANDA_BREVE,
                    (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) AS N_GRAB
             FROM marcha m
             JOIN dedicatoria_alias da ON da.VARIANTE = m.DEDICATORIA
                                      AND da.LOCALIDAD = COALESCE(m.LOCALIDAD, '')
             LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE da.ID_DEDIC = ?
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             ORDER BY (m.FECHA IS NULL OR m.FECHA = ''), m.FECHA, m.TITULO COLLATE NOCASE",
            [$id]
        );
        if ($marchas === []) {
            return null;
        }
        self::attachAutores($marchas);
        $d['marchas'] = $marchas;
        $d['N'] = count($marchas);
        return $d;
    }

    /**
     * Listado de canónicas para el panel de curación.
     *   - $soloPersonales: solo las marcadas PERSONAL = 1 (para auditar la
     *     heurística de exclusión del índice público).
     *   - si no, sin `$q` devuelve las que agrupan más de una variante
     *     (candidatas a revisar la fusión automática); con `$q` busca por
     *     nombre, localidad o cualquier variante.
     *
     * @return list<array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,N_VAR:int,N_MAR:int,PERSONAL:int}>
     */
    public static function dedicatoriasAdmin(?string $q, int $limit = 300, bool $soloPersonales = false): array
    {
        $q = trim((string) $q);
        $sel =
            "SELECT d.ID_DEDIC, d.NOMBRE, d.LOCALIDAD, d.PERSONAL,
                    (SELECT COUNT(*) FROM dedicatoria_alias da WHERE da.ID_DEDIC = d.ID_DEDIC) AS N_VAR,
                    (SELECT COUNT(*) FROM dedicatoria_alias da
                       JOIN marcha m ON m.DEDICATORIA = da.VARIANTE
                                    AND COALESCE(m.LOCALIDAD, '') = da.LOCALIDAD
                       WHERE da.ID_DEDIC = d.ID_DEDIC) AS N_MAR
             FROM dedicatoria d ";
        if ($soloPersonales) {
            return Db::all(
                $sel . 'WHERE d.PERSONAL = 1 ORDER BY N_MAR DESC, d.SLUG_KEY LIMIT ' . (int) $limit
            );
        }
        if ($q === '') {
            return Db::all(
                $sel . 'WHERE N_VAR > 1 ORDER BY N_VAR DESC, d.SLUG_KEY LIMIT ' . (int) $limit
            );
        }
        $like = '%' . Db::noAcc($q) . '%';
        return Db::all(
            $sel .
            "WHERE NOACC(d.NOMBRE) LIKE ? OR NOACC(d.LOCALIDAD) LIKE ?
                OR EXISTS (SELECT 1 FROM dedicatoria_alias da
                           WHERE da.ID_DEDIC = d.ID_DEDIC AND NOACC(da.VARIANTE) LIKE ?)
             ORDER BY d.SLUG_KEY LIMIT " . (int) $limit,
            [$like, $like, $like]
        );
    }

    /**
     * Canónica + sus variantes (con recuento de marchas) para el formulario de
     * curación. Devuelve null si la canónica no existe.
     *
     * @return array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,PERSONAL:int,variantes:list<array<string,mixed>>}|null
     */
    public static function fetchDedicatoriaAdmin(string $id): ?array
    {
        $d = Db::one('SELECT ID_DEDIC, NOMBRE, LOCALIDAD, PROVINCIA, PERSONAL FROM dedicatoria WHERE ID_DEDIC = ?', [$id]);
        if ($d === null) {
            return null;
        }
        $d['variantes'] = Db::all(
            "SELECT da.VARIANTE, da.LOCALIDAD,
                    (SELECT COUNT(*) FROM marcha m
                       WHERE m.DEDICATORIA = da.VARIANTE
                         AND COALESCE(m.LOCALIDAD, '') = da.LOCALIDAD) AS N_MAR
             FROM dedicatoria_alias da
             WHERE da.ID_DEDIC = ?
             ORDER BY N_MAR DESC, da.VARIANTE COLLATE NOCASE",
            [$id]
        );
        return $d;
    }

    // ── Stats ────────────────────────────────────────────────────────────────

    public static function fetchEstado(): array
    {
        return Db::counts();
    }

    public static function fetchMasAutor(): array
    {
        return Db::all(
            "SELECT a.ID_AUTOR, COUNT(m.ID_MARCHA) AS MARCHAS,
                    (a.NOMBRE || ' ' || a.APELLIDOS) AS AUTOR
             FROM autor a
             INNER JOIN marcha_autor am ON am.ID_AUTOR = a.ID_AUTOR
             INNER JOIN marcha m ON m.ID_MARCHA = am.ID_MARCHA
             GROUP BY a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             ORDER BY MARCHAS DESC LIMIT 10"
        );
    }

    public static function fetchMasDedica(): array
    {
        return Db::all(
            "SELECT COUNT(DEDICATORIA) AS CUENTA,
                    (DEDICATORIA || ' (' || LOCALIDAD || ')') AS LUGAR
             FROM marcha WHERE DEDICATORIA LIKE '%Hdad%' GROUP BY LUGAR
             HAVING CUENTA >= 15 ORDER BY CUENTA DESC"
        );
    }

    public static function fetchMasEstreno(): array
    {
        return Db::all(
            "SELECT b.ID_BANDA, COUNT(m.ID_MARCHA) AS MARCHAS,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
             FROM marcha m INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE b.ID_BANDA != 0
             GROUP BY b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
             ORDER BY MARCHAS DESC LIMIT 20"
        );
    }

    // ── R-07: estado del catálogo — cobertura de audio ──────────────────────
    // "Tiene escucha" = AUDIO propio en `marcha` (histórico, sobre todo
    // YouTube) o al menos un enlace en `enlace_streaming` (Spotify/Deezer/
    // Apple/YouTube de la ingesta o del panel). Mismo criterio que
    // Media::reproductor() para la ficha pública, pero en SQL agregado.
    private const SQL_TIENE_AUDIO = "(
        (m.AUDIO IS NOT NULL AND TRIM(m.AUDIO) != '')
        OR EXISTS (SELECT 1 FROM enlace_streaming es WHERE es.TIPO_ENT = 'marcha' AND es.ID_ENT = m.ID_MARCHA)
    )";

    /**
     * Cobertura global de audio: total de marchas catalogadas (con autor) y
     * cuántas tienen alguna escucha enlazada. Base del KPI de la página
     * pública "estado del catálogo" (issue #16, R-07 del roadmap).
     *
     * @return array{total:int, conAudio:int, pct:float}
     */
    public static function coberturaGlobal(): array
    {
        $row = Db::one(
            "SELECT COUNT(*) AS TOTAL,
                    SUM(CASE WHEN " . self::SQL_TIENE_AUDIO . " THEN 1 ELSE 0 END) AS CON_AUDIO
             FROM marcha m
             WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)"
        );
        $total = (int) ($row['TOTAL'] ?? 0);
        $conAudio = (int) ($row['CON_AUDIO'] ?? 0);
        return ['total' => $total, 'conAudio' => $conAudio, 'pct' => $total > 0 ? round($conAudio * 100 / $total, 1) : 0.0];
    }

    /**
     * Cobertura de audio por año de estreno, más reciente primero. Años sin
     * ninguna marcha catalogada no aparecen (no hay nada que cubrir).
     *
     * @return list<array{K:int, TOTAL:int, CON_AUDIO:int, PCT:float}>
     */
    public static function coberturaPorAnio(): array
    {
        $rows = Db::all(
            "SELECT m.FECHA AS K, COUNT(*) AS TOTAL,
                    SUM(CASE WHEN " . self::SQL_TIENE_AUDIO . " THEN 1 ELSE 0 END) AS CON_AUDIO
             FROM marcha m
             WHERE m.FECHA > 1000
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY m.FECHA ORDER BY m.FECHA DESC"
        );
        foreach ($rows as &$r) {
            $r['K'] = (int) $r['K'];
            $r['TOTAL'] = (int) $r['TOTAL'];
            $r['CON_AUDIO'] = (int) $r['CON_AUDIO'];
            $r['PCT'] = $r['TOTAL'] > 0 ? round($r['CON_AUDIO'] * 100 / $r['TOTAL'], 1) : 0.0;
        }
        unset($r);
        return $rows;
    }

    /**
     * Cobertura de audio por banda de estreno, peor cobertura primero: es el
     * mapa de curación (qué banda conviene revisar antes en la campaña de
     * audio, P1/M2 del roadmap). Se excluyen las bandas con menos de
     * `$minMarchas` para no destacar ruido de una sola marcha sin escucha.
     *
     * @return list<array{ID_BANDA:int, BANDA:string, TOTAL:int, CON_AUDIO:int, PCT:float}>
     */
    public static function coberturaPorBanda(int $minMarchas = 3): array
    {
        // $minMarchas se interpola directo (no vía "?"): PDO::execute() con un
        // array liga todos los parámetros como TEXT por defecto, y SQLite
        // compara por storage class cuando no hay afinidad de columna
        // declarada — que es el caso de COUNT(*), un valor calculado. Con el
        // entero ligado como '3' (texto), "COUNT(*) >= '3'" da siempre falso
        // (INTEGER se considera menor que TEXT en esa comparación), así que
        // esta HAVING nunca puede ir por bind normal. Seguro porque el tipo
        // `int` en la firma ya garantiza que no es texto arbitrario.
        $rows = Db::all(
            "SELECT b.ID_BANDA, (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
                    COUNT(*) AS TOTAL,
                    SUM(CASE WHEN " . self::SQL_TIENE_AUDIO . " THEN 1 ELSE 0 END) AS CON_AUDIO
             FROM marcha m INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE b.ID_BANDA != 0
               AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
             HAVING COUNT(*) >= {$minMarchas}
             ORDER BY (CAST(CON_AUDIO AS REAL) / TOTAL) ASC, TOTAL DESC"
        );
        foreach ($rows as &$r) {
            $r['ID_BANDA'] = (int) $r['ID_BANDA'];
            $r['TOTAL'] = (int) $r['TOTAL'];
            $r['CON_AUDIO'] = (int) $r['CON_AUDIO'];
            $r['PCT'] = $r['TOTAL'] > 0 ? round($r['CON_AUDIO'] * 100 / $r['TOTAL'], 1) : 0.0;
        }
        unset($r);
        return $rows;
    }

    public static function fetchMasGrabada(): array
    {
        $rows = Db::all(
            "SELECT COUNT(dm.IDMARCHA) AS GRABACIONES, m.ID_MARCHA, m.TITULO
             FROM disco_marcha dm INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
             WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY dm.IDMARCHA, m.ID_MARCHA, m.TITULO
             ORDER BY GRABACIONES DESC LIMIT 20"
        );
        self::attachAutores($rows);
        return $rows;
    }

    // ── Rankings por año (N-07): mismas queries que fetchMas*, acotadas a un
    // año concreto — no son "de siempre" sino el récord de esa temporada. ────
    public static function fetchMasAutorAnio(string $anio): array
    {
        return Db::all(
            "SELECT a.ID_AUTOR, COUNT(m.ID_MARCHA) AS MARCHAS,
                    (a.NOMBRE || ' ' || a.APELLIDOS) AS AUTOR
             FROM autor a
             INNER JOIN marcha_autor am ON am.ID_AUTOR = a.ID_AUTOR
             INNER JOIN marcha m ON m.ID_MARCHA = am.ID_MARCHA
             WHERE m.FECHA = ?
             GROUP BY a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             ORDER BY MARCHAS DESC LIMIT 10",
            [$anio]
        );
    }

    public static function fetchMasEstrenoAnio(string $anio): array
    {
        return Db::all(
            "SELECT b.ID_BANDA, COUNT(m.ID_MARCHA) AS MARCHAS,
                    (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
             FROM marcha m INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
             WHERE b.ID_BANDA != 0 AND m.FECHA = ?
             GROUP BY b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
             ORDER BY MARCHAS DESC LIMIT 20",
            [$anio]
        );
    }

    public static function fetchMasGrabadaAnio(string $anio): array
    {
        $rows = Db::all(
            "SELECT COUNT(dm.IDMARCHA) AS GRABACIONES, m.ID_MARCHA, m.TITULO
             FROM disco_marcha dm INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
             WHERE m.FECHA = ? AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
             GROUP BY dm.IDMARCHA, m.ID_MARCHA, m.TITULO
             ORDER BY GRABACIONES DESC LIMIT 20",
            [$anio]
        );
        self::attachAutores($rows);
        return $rows;
    }

    // ── Temporada / contratos (N-04/N-05) ───────────────────────────────────
    /**
     * Contratos de un año, ordenados para agrupar por hermandad en la
     * plantilla (misma hermandad = filas consecutivas). FUENTE se selecciona
     * por si se necesita más adelante, pero la plantilla ya no la muestra
     * (info pública sin contraste, no aporta como enlace visible); NOTA es
     * interna del admin y no se selecciona.
     * BANDA_LOCALIDAD viaja aparte (no solo dentro de BANDA ya formateado)
     * para que Pages::temporada pueda inferir una "ciudad" aproximada por
     * hermandad (localidad más frecuente entre sus bandas contratadas) sin
     * esperar a la entidad `hermandad` real (N-03, ver docs/n03-hermandad.md).
     * Orden: por ID_CONTRATO (orden de alta), no alfabético — el pipeline de
     * carga inserta las filas en el mismo orden del CSV de origen (día →
     * hermandad → paso), así que el ID autoincremental ya reconstruye ese
     * orden sin necesidad de guardarlo aparte.
     * @return list<array{ID_CONTRATO:int,HERMANDAD:string,HERMANDAD_SLUG:string,
     *                     TITULAR:?string,FUENTE:?string,ID_BANDA:int,BANDA:string,
     *                     BANDA_LOCALIDAD:string}>
     */
    public static function temporada(string $anio): array
    {
        return Db::all(
            "SELECT c.ID_CONTRATO, c.HERMANDAD, c.HERMANDAD_SLUG, c.TITULAR, c.FUENTE,
                    b.ID_BANDA, (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
                    b.LOCALIDAD AS BANDA_LOCALIDAD
             FROM contrato c INNER JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             WHERE c.ANIO = ?
             ORDER BY c.ID_CONTRATO ASC",
            [$anio]
        );
    }

    /** Años con al menos un contrato, para el sitemap y el índice de /temporada. */
    public static function aniosConTemporada(): array
    {
        return Db::all('SELECT ANIO AS K, COUNT(*) AS N FROM contrato GROUP BY ANIO ORDER BY ANIO DESC');
    }
}
