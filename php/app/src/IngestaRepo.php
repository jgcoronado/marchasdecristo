<?php

declare(strict_types=1);

namespace App;

/**
 * Lecturas del panel de revisión de ingesta (candidatos de marcha desde
 * YouTube, ver tools/ingest/). Solo SELECT — las escrituras (aceptar/
 * descartar) viven en AdminRepo, junto al resto de operaciones de escritura
 * del panel.
 */
final class IngestaRepo
{
    public const ESTADOS = ['pendiente', 'aceptado', 'descartado', 'duplicado'];
    public const CLASIFICACIONES = ['estreno', 'novedad', 'recuperacion'];

    /**
     * Fuentes de las que puede venir un candidato. `youtube` es la original
     * (tools/ingest/*.mjs); el resto son catálogos de streaming de la banda
     * (tools/music_links/descubrir_marchas.py). Las que coinciden con
     * EnlaceRepo::SERVICIOS pueden publicarse como enlace de la marcha al
     * aceptar el candidato.
     */
    public const FUENTES = ['youtube', 'spotify', 'deezer', 'apple'];

    /** Etiquetas de fuente para el panel. */
    public const FUENTE_LABEL = [
        'youtube' => 'YouTube',
        'spotify' => 'Spotify',
        'deezer' => 'Deezer',
        'apple' => 'Apple Music',
    ];

    /** Mismos umbrales que tools/ingest/dedup.mjs, para que el criterio sea consistente. */
    private const UMBRAL_MEDIA = 0.75;

    /**
     * Al crear una marcha (a mano o al aceptar un candidato), revisa los
     * demás candidatos aún pendientes o ya descartados de la misma banda de
     * estreno por si alguno coincide por título con la marcha recién creada
     * — p.ej. dos vídeos distintos de la misma marcha, o una marcha que se
     * descartó antes de que existiera y ahora sí existe. Si hay coincidencia
     * ≥75% se anota MATCH_MARCHA_ID/MATCH_SCORE; los descartados que
     * coincidan vuelven a "pendiente" para que el revisor decida de nuevo.
     *
     * Se compara tanto contra $bandaEstreno (la banda de estreno final de la
     * marcha, que el revisor puede haber corregido a mano) como contra
     * $bandaOrigenCand (el canal/artista de origen del candidato aceptado, si
     * aplica) — un candidato duplicado suele compartir origen con el
     * aceptado aunque la banda de estreno real sea otra.
     *
     * Excepción: los candidatos con **veto** (descartados a mano, ver
     * `ingest_veto`) no se reabren nunca. Un descarte manual es una decisión
     * definitiva sobre ese origen concreto; solo el botón de deshacer del
     * panel lo revierte.
     */
    public static function reevaluarTrasCrearMarcha(
        int $marchaId,
        ?int $bandaEstreno,
        string $tituloMarcha,
        ?int $excluirIdCand = null,
        ?int $bandaOrigenCand = null
    ): void {
        $tituloMarcha = trim($tituloMarcha);
        $bandas = array_values(array_unique(array_filter([$bandaEstreno, $bandaOrigenCand])));
        if ($tituloMarcha === '' || $bandas === []) return;

        $ph = implode(',', array_fill(0, count($bandas), '?'));
        $rows = Db::all(
            "SELECT c.ID_CAND, c.P_TITULO, c.VIDEO_TITULO, c.ESTADO, c.MOTIVO
             FROM ingest_candidato c
             WHERE c.ESTADO IN ('pendiente', 'descartado')
               AND (COALESCE(c.P_BANDA_ESTRENO, c.ID_BANDA) IN ($ph) OR c.ID_BANDA IN ($ph))
               AND c.ID_CAND != ?
               AND NOT EXISTS (SELECT 1 FROM ingest_veto v
                               WHERE v.FUENTE = c.FUENTE AND v.FUENTE_ID = c.VIDEO_ID)",
            [...$bandas, ...$bandas, $excluirIdCand ?? 0]
        );

        foreach ($rows as $r) {
            $tituloCand = (string) ($r['P_TITULO'] ?: $r['VIDEO_TITULO']);
            $score = Similarity::ratio($tituloMarcha, $tituloCand);
            if ($score < self::UMBRAL_MEDIA) continue;

            if ($r['ESTADO'] === 'descartado') {
                $nota = 'Reabierto: posible coincidencia con la marcha recién creada #' . $marchaId . ' (similitud ' . (int) round($score * 100) . '%).';
                $motivo = $r['MOTIVO'] ? $r['MOTIVO'] . ' | ' . $nota : $nota;
                Db::run(
                    "UPDATE ingest_candidato
                     SET ESTADO = 'pendiente', MATCH_MARCHA_ID = ?, MATCH_SCORE = ?, MOTIVO = ?, REVIEWED_AT = NULL
                     WHERE ID_CAND = ?",
                    [$marchaId, $score, $motivo, $r['ID_CAND']]
                );
                Db::logAdmin('REOPEN', 'ingest_candidato', (int) $r['ID_CAND'], ['marchaId' => $marchaId, 'score' => $score]);
            } else {
                Db::run(
                    'UPDATE ingest_candidato SET MATCH_MARCHA_ID = ?, MATCH_SCORE = ? WHERE ID_CAND = ?',
                    [$marchaId, $score, $r['ID_CAND']]
                );
            }
        }
    }

    /**
     * Candidatos aún pendientes con el mismo título que $titulo y la misma
     * banda que $idBanda, pero de una fuente distinta a $fuente: el mismo
     * estreno visto a la vez en dos catálogos (p.ej. YouTube y Spotify).
     * Usado para descartarlos en cascada al resolver $idCand — ver
     * AdminRepo::descartarHermanosMismoTitulo().
     *
     * "Mismo título" es iguales tras normalizar (Similarity::ratio === 1:
     * minúsculas, sin acentos ni signos, espacios colapsados) — no similitud
     * aproximada, para no descartar por error dos marchas distintas que solo
     * se parecen.
     *
     * @return list<int>
     */
    public static function hermanosMismoTitulo(int $idCand, ?int $idBanda, string $titulo, string $fuente): array
    {
        $titulo = trim($titulo);
        if ($titulo === '' || $idBanda === null) return [];

        $rows = Db::all(
            "SELECT ID_CAND, P_TITULO, VIDEO_TITULO
             FROM ingest_candidato
             WHERE ESTADO = 'pendiente' AND ID_BANDA = ? AND ID_CAND != ? AND FUENTE != ?",
            [$idBanda, $idCand, $fuente]
        );

        $out = [];
        foreach ($rows as $r) {
            $tituloC = (string) ($r['P_TITULO'] ?: $r['VIDEO_TITULO']);
            if (Similarity::ratio($titulo, $tituloC) >= 1.0) $out[] = (int) $r['ID_CAND'];
        }
        return $out;
    }

    /**
     * Último descarte deshacible, o null si no hay ninguno (nunca se descartó,
     * o el último descarte ya se deshizo). Trae el título del candidato cuando
     * el descarte fue de uno solo, para poder nombrarlo en el botón.
     *
     * @return array{N:int, CREATED_AT:string, USUARIO:?string, TITULO:?string}|null
     */
    public static function ultimoDescarte(): ?array
    {
        try {
            $row = Db::one('SELECT IDS_JSON, N, USUARIO, CREATED_AT FROM ingest_descarte_ultimo WHERE ID = 1');
        } catch (\Throwable) {
            // Tabla de 008_ingest_streaming.sql: si el host todavía no tiene la
            // migración aplicada, no hay nada que deshacer todavía (degradado,
            // no 500 — mismo patrón que Pages::temporada con la tabla contrato).
            return null;
        }
        if ($row === null) return null;

        $ids = json_decode((string) $row['IDS_JSON'], true);
        if (!is_array($ids) || $ids === []) return null;

        $titulo = null;
        if (count($ids) === 1) {
            $c = Db::one('SELECT P_TITULO, VIDEO_TITULO FROM ingest_candidato WHERE ID_CAND = ?', [(int) $ids[0]]);
            if ($c !== null) $titulo = (string) ($c['P_TITULO'] ?: $c['VIDEO_TITULO']);
        }

        return [
            'N' => (int) $row['N'],
            'CREATED_AT' => (string) $row['CREATED_AT'],
            'USUARIO' => $row['USUARIO'] !== null ? (string) $row['USUARIO'] : null,
            'TITULO' => $titulo,
        ];
    }

    /**
     * Orígenes vetados (servicio + id de pista/vídeo) de una lista de
     * candidatos, para que el panel pueda marcar cuáles no volverán a
     * proponerse. Devuelve un set "fuente|id" => true.
     *
     * @param list<array<string,mixed>> $candidatos
     * @return array<string,true>
     */
    public static function vetosDe(array $candidatos): array
    {
        $claves = [];
        $ids = [];
        foreach ($candidatos as $c) {
            $id = (string) ($c['VIDEO_ID'] ?? '');
            if ($id === '') continue;
            $claves[((string) ($c['FUENTE'] ?? 'youtube')) . '|' . $id] = true;
            $ids[$id] = true;
        }
        if ($claves === []) return [];

        $ids = array_keys($ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $rows = Db::all("SELECT FUENTE, FUENTE_ID FROM ingest_veto WHERE FUENTE_ID IN ($ph)", $ids);
        } catch (\Throwable) {
            // Tabla de 008_ingest_streaming.sql: sin migrar aún en este host,
            // "ningún veto conocido" es la degradación correcta, no un 500.
            return [];
        }
        $out = [];
        foreach ($rows as $v) {
            $k = $v['FUENTE'] . '|' . $v['FUENTE_ID'];
            if (isset($claves[$k])) $out[$k] = true;
        }
        return $out;
    }

    /** Conteos por estado, para las pestañas/badges del panel. */
    public static function counts(): array
    {
        $rows = Db::all('SELECT ESTADO, COUNT(*) AS n FROM ingest_candidato GROUP BY ESTADO');
        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($rows as $r) {
            if (isset($out[$r['ESTADO']])) $out[$r['ESTADO']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * @param array{estado?:string,banda?:string,clasificacion?:string,disco?:string} $filters
     * @return array{rowsReturned:int,totalRows:int,data:list<array<string,mixed>>}
     */
    public static function listCandidatos(array $filters, int $page = 1, int $limit = 30): array
    {
        $conditions = [];
        $values = [];

        $estado = (string) ($filters['estado'] ?? 'pendiente');
        if ($estado !== '' && $estado !== 'todos' && in_array($estado, self::ESTADOS, true)) {
            $conditions[] = 'c.ESTADO = ?';
            $values[] = $estado;
        }
        if (!empty($filters['banda'])) {
            $conditions[] = 'c.ID_BANDA = ?';
            $values[] = (int) $filters['banda'];
        }
        if (!empty($filters['clasificacion']) && in_array($filters['clasificacion'], self::CLASIFICACIONES, true)) {
            $conditions[] = 'c.CLASIFICACION = ?';
            $values[] = $filters['clasificacion'];
        }
        if (!empty($filters['disco'])) {
            $conditions[] = 'c.FUENTE_ALBUM = ?';
            $values[] = (string) $filters['disco'];
        }
        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';

        $countRow = Db::one("SELECT COUNT(*) AS n FROM ingest_candidato c WHERE $where", $values);
        $total = (int) ($countRow['n'] ?? 0);
        $offset = ($page - 1) * $limit;

        // c.* en vez de listar columnas (incluidas FUENTE/FUENTE_ALBUM, añadidas
        // por migrate_ingest.php tras 008_ingest_streaming.sql): así esta query
        // no depende de si ese ALTER TABLE ya se aplicó en este host — igual que
        // fetchCandidato() más abajo. Las plantillas ya leen esas claves con
        // ?? 'youtube' / !empty(), así que su ausencia no rompe nada.
        $rows = Db::all(
            "SELECT c.*, b.NOMBRE_BREVE
             FROM ingest_candidato c
             LEFT JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             WHERE $where
             ORDER BY CASE WHEN c.ESTADO = 'pendiente' THEN 0 ELSE 1 END, c.CONFIANZA DESC, c.ID_CAND DESC
             LIMIT ? OFFSET ?",
            [...$values, $limit, $offset]
        );

        return ['rowsReturned' => count($rows), 'totalRows' => $total, 'data' => $rows];
    }

    /** Ficha completa de un candidato (para el detalle/revisión), con el título de la posible marcha duplicada. */
    public static function fetchCandidato(int $id): ?array
    {
        return Db::one(
            "SELECT c.*, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO AS BANDA_NOMBRE_COMPLETO, b.LOCALIDAD AS BANDA_LOCALIDAD, mm.TITULO AS MATCH_TITULO
             FROM ingest_candidato c
             LEFT JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             LEFT JOIN marcha mm ON mm.ID_MARCHA = c.MATCH_MARCHA_ID
             WHERE c.ID_CAND = ?",
            [$id]
        );
    }

    /**
     * Bandas que tienen al menos un candidato (para el <select> de filtro),
     * con el nº de candidatos que le corresponden (columna N). Si se pasa un
     * $estado válido, solo cuenta/lista candidatos en ese estado — así el
     * desplegable refleja la pestaña activa (p.ej. en Pendientes no aparecen
     * bandas cuyo último pendiente se acaba de descartar, y el nº mostrado
     * es justo lo que queda por resolver en esa banda).
     */
    public static function bandasConCandidatos(?string $estado = null): array
    {
        $where = '1=1';
        $values = [];
        if ($estado !== null && $estado !== '' && $estado !== 'todos' && in_array($estado, self::ESTADOS, true)) {
            $where = 'c.ESTADO = ?';
            $values[] = $estado;
        }
        return Db::all(
            "SELECT c.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD, COUNT(*) AS N
             FROM ingest_candidato c LEFT JOIN banda b ON b.ID_BANDA = c.ID_BANDA
             WHERE $where
             GROUP BY c.ID_BANDA
             ORDER BY b.NOMBRE_BREVE",
            $values
        );
    }

    /**
     * Discos (FUENTE_ALBUM) con al menos un candidato, para el <select> de
     * filtro — mismo patrón que bandasConCandidatos(). Solo lo rellenan los
     * candidatos de streaming (tools/music_links/descubrir_marchas.py); los
     * de YouTube no tienen disco de origen, así que quedan fuera.
     *
     * Si se pasa $banda, solo lista los discos de esa banda (así el
     * desplegable no mezcla discos de bandas distintas cuando ya se ha
     * filtrado por una); sin banda seleccionada, lista todos.
     *
     * @return list<array{FUENTE_ALBUM:string,N:int}>
     */
    public static function discosConCandidatos(?string $estado = null, ?string $banda = null): array
    {
        $where = "c.FUENTE_ALBUM IS NOT NULL AND c.FUENTE_ALBUM != ''";
        $values = [];
        if ($estado !== null && $estado !== '' && $estado !== 'todos' && in_array($estado, self::ESTADOS, true)) {
            $where .= ' AND c.ESTADO = ?';
            $values[] = $estado;
        }
        if ($banda !== null && $banda !== '') {
            $where .= ' AND c.ID_BANDA = ?';
            $values[] = (int) $banda;
        }
        try {
            return Db::all(
                "SELECT c.FUENTE_ALBUM, COUNT(*) AS N
                 FROM ingest_candidato c
                 WHERE $where
                 GROUP BY c.FUENTE_ALBUM
                 ORDER BY c.FUENTE_ALBUM",
                $values
            );
        } catch (\Throwable) {
            // Columna de 008_ingest_streaming.sql/migrate_ingest.php: si el
            // host todavía no la tiene, "sin discos que filtrar" es la
            // degradación correcta — mismo patrón que vetosDe()/ultimoDescarte().
            return [];
        }
    }
}
