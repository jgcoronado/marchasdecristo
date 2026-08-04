<?php

declare(strict_types=1);

namespace App;

/**
 * Lecturas del panel de curación de enlaces de streaming (candidatos de
 * Spotify / Apple / Deezer generados por tools/music_links/). Solo SELECT —
 * las escrituras (aprobar/rechazar) viven en AdminRepo, como el resto del panel.
 *
 * Fase 1 cura enlaces de DISCO; el modelo (enlace_candidato.TIPO_ENT) admite
 * banda y marcha para las fases siguientes.
 */
final class EnlaceRepo
{
    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado'];
    public const SERVICIOS = ['spotify', 'apple', 'deezer', 'youtube', 'tidal', 'amazon'];
    public const CONFIANZAS = ['ALTA', 'MEDIA', 'BAJA', 'SIN_MATCH'];

    // ── Versiones (original / actual) ────────────────────────────────────────
    /**
     * Una marcha con muchos años suena hoy muy distinta a como se estrenó:
     * cambian los tempos, las plantillas y los arreglos. A partir de cierta
     * antigüedad la ficha deja de presentar "las escuchas" como un bloque único
     * y las separa en dos versiones.
     */
    public const VERSIONES = ['original', 'actual'];

    /** Antigüedad (años) a partir de la cual la ficha separa las dos versiones. */
    public const ANTIGUEDAD_VERSIONES = 25;

    /**
     * Ventana (años tras el estreno) dentro de la cual una grabación cuenta
     * como "de la época". 15 años cubre las grabaciones que todavía beben de la
     * tradición interpretativa del estreno sin exigir que sean literalmente la
     * primera, que casi nunca está en streaming.
     */
    public const VENTANA_ORIGINAL = 15;

    /**
     * Versión que le corresponde a una grabación. Sin año de grabación conocido
     * se devuelve 'actual': el catálogo de streaming es abrumadoramente moderno,
     * así que es la respuesta correcta muchas más veces que la contraria.
     */
    public static function versionDeAnio(?int $anioGrabacion, ?int $anioMarcha): string
    {
        if ($anioGrabacion === null || $anioGrabacion <= 1800) return 'actual';
        if ($anioMarcha === null || $anioMarcha <= 1800) return 'actual';
        return $anioGrabacion <= $anioMarcha + self::VENTANA_ORIGINAL ? 'original' : 'actual';
    }

    /** ¿La marcha es lo bastante antigua como para que su ficha separe versiones? */
    public static function admiteVersiones(?int $anioMarcha): bool
    {
        if ($anioMarcha === null || $anioMarcha <= 1800) return false;
        return (int) date('Y') - $anioMarcha >= self::ANTIGUEDAD_VERSIONES;
    }

    /** Año de composición de una marcha, o null si no consta. */
    public static function anioDeMarcha(int $idMarcha): ?int
    {
        $m = Db::one('SELECT FECHA FROM marcha WHERE ID_MARCHA = ?', [$idMarcha]);
        if ($m === null) return null;
        return preg_match('/^\d{4}$/', (string) $m['FECHA']) === 1 ? (int) $m['FECHA'] : null;
    }

    /** Conteos por estado, para las pestañas/badges del panel. */
    public static function counts(): array
    {
        $rows = Db::all('SELECT ESTADO, COUNT(*) AS n FROM enlace_candidato GROUP BY ESTADO');
        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($rows as $r) {
            if (isset($out[$r['ESTADO']])) $out[$r['ESTADO']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * @param array{estado?:string,servicio?:string,confianza?:string,banda?:string} $filters
     * @return array{rowsReturned:int,totalRows:int,data:list<array<string,mixed>>}
     */
    public static function listCandidatos(array $filters, int $page = 1, int $limit = 40): array
    {
        $conditions = [];
        $values = [];

        $estado = (string) ($filters['estado'] ?? 'pendiente');
        if ($estado !== '' && $estado !== 'todos' && in_array($estado, self::ESTADOS, true)) {
            $conditions[] = 'c.ESTADO = ?';
            $values[] = $estado;
        }
        if (!empty($filters['servicio']) && in_array($filters['servicio'], self::SERVICIOS, true)) {
            $conditions[] = 'c.SERVICIO = ?';
            $values[] = $filters['servicio'];
        }
        if (!empty($filters['confianza']) && in_array($filters['confianza'], self::CONFIANZAS, true)) {
            $conditions[] = 'c.CONFIANZA = ?';
            $values[] = $filters['confianza'];
        }
        if (!empty($filters['banda'])) {
            // La banda de un candidato es: la dueña del disco, la propia entidad
            // (TIPO_ENT='banda'), o la banda de estreno de la marcha (TIPO_ENT='marcha').
            $conditions[] = "(d.BANDADISCO = ? OR (c.TIPO_ENT = 'banda' AND c.ID_ENT = ?) OR mm.BANDA_ESTRENO = ?)";
            $values[] = (int) $filters['banda'];
            $values[] = (int) $filters['banda'];
            $values[] = (int) $filters['banda'];
        }
        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';

        // Contexto polimórfico: disco (fase 1), banda (fase 2) o marcha (fase 3).
        // Los LEFT JOIN dejan NULL el que no aplica; ENT_* unifican la vista.
        $from = "FROM enlace_candidato c
                 LEFT JOIN disco  d  ON c.TIPO_ENT = 'disco'  AND d.ID_DISCO  = c.ID_ENT
                 LEFT JOIN banda  b  ON b.ID_BANDA = d.BANDADISCO
                 LEFT JOIN banda  bb ON c.TIPO_ENT = 'banda'  AND bb.ID_BANDA = c.ID_ENT
                 LEFT JOIN marcha mm ON c.TIPO_ENT = 'marcha' AND mm.ID_MARCHA = c.ID_ENT
                 LEFT JOIN banda  bm ON bm.ID_BANDA = mm.BANDA_ESTRENO";

        $countRow = Db::one("SELECT COUNT(*) AS n $from WHERE $where", $values);
        $total = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        $rows = Db::all(
            "SELECT c.ID_CAND, c.TIPO_ENT, c.ID_ENT, c.SERVICIO, c.URL, c.TITULO_ENC, c.ARTISTA_ENC,
                    c.ANIO_ENC, c.SCORE, c.CONFIANZA, c.ESTADO,
                    COALESCE(d.NOMBRE_CD, bb.NOMBRE_BREVE, mm.TITULO)   AS ENT_NOMBRE,
                    COALESCE(b.NOMBRE_BREVE, bb.NOMBRE_BREVE, bm.NOMBRE_BREVE) AS ENT_BANDA,
                    COALESCE(b.LOCALIDAD, bb.LOCALIDAD, bm.LOCALIDAD)   AS ENT_BANDA_LOCALIDAD,
                    COALESCE(d.FECHA_CD, mm.FECHA)                      AS ENT_ANIO
             $from
             WHERE $where
             ORDER BY CASE WHEN c.ESTADO = 'pendiente' THEN 0 ELSE 1 END,
                      c.TIPO_ENT, c.ID_ENT, c.SCORE DESC
             LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );

        return ['rowsReturned' => count($rows), 'totalRows' => $total, 'data' => $rows];
    }

    /**
     * Enlaces PUBLICADOS (aprobados) de una entidad, para la ficha pública.
     * Devuelve [servicio => url] en el orden canónico de SERVICIOS.
     *
     * @return array<string,string>
     */
    public static function publicadosDe(string $tipo, int $id): array
    {
        $porVersion = self::publicadosPorVersionDe($tipo, $id);
        // Aplana las dos versiones en una sola lista quedándose con la actual,
        // que es la que un visitante espera si no le damos a elegir. Las fichas
        // de banda y disco (donde el concepto de versión no aplica) siguen
        // usando esta función tal cual; la de marcha usa la de abajo.
        $map = $porVersion['actual'] + $porVersion['original'];

        $out = [];
        foreach (self::SERVICIOS as $s) {
            if (isset($map[$s])) $out[$s] = $map[$s];
        }
        return $out;
    }

    /**
     * Igual que publicadosDe pero separando las dos versiones. Ambas claves
     * existen siempre (posiblemente vacías), para que quien lo consuma no tenga
     * que comprobarlas.
     *
     * @return array{original: array<string,string>, actual: array<string,string>}
     */
    public static function publicadosPorVersionDe(string $tipo, int $id): array
    {
        $rows = Db::all(
            'SELECT SERVICIO, URL, VERSION FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ?',
            [$tipo, $id]
        );

        $map = ['original' => [], 'actual' => []];
        foreach ($rows as $r) {
            $v = (string) ($r['VERSION'] ?? 'actual');
            if (!isset($map[$v])) $v = 'actual';
            $map[$v][(string) $r['SERVICIO']] = (string) $r['URL'];
        }

        // Reordenar cada versión al orden canónico de SERVICIOS, para que la
        // botonera salga siempre igual venga como venga de la BD.
        $out = ['original' => [], 'actual' => []];
        foreach ($map as $version => $porServicio) {
            foreach (self::SERVICIOS as $s) {
                if (isset($porServicio[$s])) $out[$version][$s] = $porServicio[$s];
            }
        }
        return $out;
    }

    /**
     * Como publicadosPorVersionDe, pero con el año de cada grabación y si su
     * versión se fijó a mano. Es lo que necesita el formulario del panel; la
     * ficha pública solo quiere la URL, así que usa la otra.
     *
     * @return array{original: array<string,array{url:string,anio:?int,manual:bool}>,
     *               actual:   array<string,array{url:string,anio:?int,manual:bool}>}
     */
    public static function detalleDe(string $tipo, int $id): array
    {
        $rows = Db::all(
            'SELECT SERVICIO, URL, VERSION, ANIO, VERSION_AUTO FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ?',
            [$tipo, $id]
        );
        $out = ['original' => [], 'actual' => []];
        foreach ($rows as $r) {
            $v = (string) ($r['VERSION'] ?? 'actual');
            if (!isset($out[$v])) $v = 'actual';
            $out[$v][(string) $r['SERVICIO']] = [
                'url' => (string) $r['URL'],
                'anio' => $r['ANIO'] !== null ? (int) $r['ANIO'] : null,
                'manual' => (int) ($r['VERSION_AUTO'] ?? 1) === 0,
            ];
        }
        return $out;
    }

    /** Bandas con al menos un candidato (disco propio o enlace de banda), para el <select> de filtro. */
    public static function bandasConCandidatos(): array
    {
        return Db::all(
            "SELECT DISTINCT ID_BANDA, NOMBRE_BREVE, LOCALIDAD FROM (
                SELECT d.BANDADISCO AS ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
                FROM enlace_candidato c
                JOIN disco d ON c.TIPO_ENT = 'disco' AND d.ID_DISCO = c.ID_ENT
                LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
                UNION
                SELECT bb.ID_BANDA, bb.NOMBRE_BREVE, bb.LOCALIDAD
                FROM enlace_candidato c
                JOIN banda bb ON c.TIPO_ENT = 'banda' AND bb.ID_BANDA = c.ID_ENT
                UNION
                SELECT bm.ID_BANDA, bm.NOMBRE_BREVE, bm.LOCALIDAD
                FROM enlace_candidato c
                JOIN marcha mm ON c.TIPO_ENT = 'marcha' AND mm.ID_MARCHA = c.ID_ENT
                JOIN banda bm ON bm.ID_BANDA = mm.BANDA_ESTRENO
             )
             WHERE ID_BANDA IS NOT NULL
             ORDER BY NOMBRE_BREVE"
        );
    }
}
