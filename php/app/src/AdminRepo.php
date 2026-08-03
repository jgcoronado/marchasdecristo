<?php

declare(strict_types=1);

namespace App;

/**
 * Operaciones de escritura del panel admin. Ports de app/api/admin/*.
 * Devuelven ['code' => ...] con los mismos códigos que los Route Handlers.
 */
final class AdminRepo
{
    public const EDITABLE_MARCHA = ['TITULO', 'FECHA', 'DEDICATORIA', 'LOCALIDAD', 'PROVINCIA', 'AUDIO', 'BANDA_ESTRENO', 'TIPO', 'ESTILO', 'DETALLES_MARCHA'];
    public const INSERTABLE_MARCHA = ['TITULO', 'FECHA', 'DEDICATORIA', 'LOCALIDAD', 'PROVINCIA', 'BANDA_ESTRENO', 'TIPO', 'ESTILO', 'DETALLES_MARCHA'];

    /**
     * Valores reales de marcha.TIPO en producción (consultado 2026-07:
     * MARCHA PROCESIONAL=4182, vacío=657, el resto son adaptaciones minoritarias).
     * Campo heredado de la importación original — se valida contra esta lista
     * cerrada para no admitir texto libre nuevo, igual que ESTILO.
     */
    public const MARCHA_TIPOS = [
        'MARCHA PROCESIONAL',
        'ADAPTACIÓN MARCHA DE BANDA DE MÚSICA',
        'ADAPTACIÓN MÚSICA RELIGIOSA',
        'ADAPTACIÓN MÚSICA POPULAR',
    ];
    public const EDITABLE_AUTOR = ['NOMBRE', 'APELLIDOS', 'NOMBRE_ART', 'F_NAC', 'LUGAR_NAC', 'F_DEF', 'BIO'];

    /**
     * Campos del disco que se editan desde el panel.
     *
     * NO incluye `disco.DISCOS` (nº de volúmenes) a propósito: la aplicación
     * nunca lee esa columna — Repo la calcula como MAX(disco_marcha.N_DISCO)
     * en las dos consultas que la exponen. Guardarla aquí sería dato muerto que
     * además podría contradecir a las pistas reales. El volumen se fija pista a
     * pista, y el recuento sale solo.
     *
     * Ojo con `d_DETALLES`: la columna heredada va en minúsculas.
     */
    public const EDITABLE_DISCO = ['NOMBRE_CD', 'FECHA_CD', 'BANDADISCO', 'd_DETALLES', 'PERCUSION', 'PERCUSION_SEG'];
    public const EDITABLE_BANDA = ['NOMBRE_COMPLETO', 'NOMBRE_BREVE', 'LOCALIDAD', 'PROVINCIA', 'FECHA_FUND', 'FECHA_EXT', 'DIRECTOR_ACTUAL', 'DIR_MUS_ACTUAL', 'WEB'];

    public static function normalize(mixed $v): mixed
    {
        if ($v === null) return null;
        if (is_string($v)) {
            $t = trim($v);
            return $t === '' ? null : $t;
        }
        return $v;
    }

    /**
     * Fija el par localidad-provincia contra el catálogo `municipio` (ver
     * app/tools/sql/007_municipio.sql). Un municipio pertenece a una sola
     * provincia, así que la provincia NO se toma de lo que mande el
     * formulario: se deriva de la localidad elegida. De paso devuelve la
     * grafía canónica del catálogo, así que guardar "ecija" o "ÉCIJA" escribe
     * "Écija" — el origen de las variantes que hubo que limpiar a mano.
     *
     * - Provincia sola (sin localidad) es válida: muchas fichas solo tienen eso.
     * - Localidad sin provincia se admite si el nombre solo existe en una
     *   provincia; si está repetido (los hay), hace falta la provincia.
     * - Si la tabla aún no existe (BD sin migrar) no valida nada y deja pasar
     *   los valores tal cual: el panel debe seguir usable antes del seed.
     *
     * @return array{0:?string,1:?string,2:?string}  [localidad, provincia, códigoError|null]
     */
    private static function fijarMunicipio(?string $localidad, ?string $provincia): array
    {
        if (!MunicipioRepo::tablaDisponible()) {
            return [$localidad, $provincia, null];
        }
        $localidad = self::normalize($localidad);
        $provincia = self::normalize($provincia);

        if ($localidad === null) {
            if ($provincia !== null && !MunicipioRepo::esProvinciaValida($provincia)) {
                return [null, null, 'INVALID_PROVINCIA'];
            }
            return [null, $provincia, null];
        }

        // Con provincia, el par exacto manda: es lo que desambigua los nombres
        // de municipio que se repiten en varias provincias.
        if ($provincia !== null) {
            $m = MunicipioRepo::buscarPar($provincia, $localidad);
            if ($m !== null) {
                return [(string) $m['NOMBRE'], (string) $m['PROVINCIA'], null];
            }
        }

        // Sin provincia, o con una que no casa con la localidad: manda la
        // localidad y la provincia se deriva de ella (un municipio pertenece a
        // una sola provincia). Solo se rechaza si el nombre no existe, o si
        // está repetido y sin provincia no se puede saber a cuál se refiere.
        $candidatas = MunicipioRepo::provinciasDe($localidad);
        if (count($candidatas) === 1) {
            $m = MunicipioRepo::buscarPar($candidatas[0], $localidad);
            return [(string) $m['NOMBRE'], (string) $m['PROVINCIA'], null];
        }
        return [null, null, $candidatas === [] ? 'INVALID_LOCALIDAD' : 'AMBIGUOUS_LOCALIDAD'];
    }

    /**
     * Aplica fijarMunicipio() sobre el mapa campo=>valor de una escritura,
     * completando con los valores actuales de la fila cuando la edición solo
     * manda uno de los dos campos.
     *
     * @param  array<string,mixed> $safe     campos que se van a escribir (se modifica)
     * @param  array<string,mixed> $actuales valores actuales de la fila ([] en altas)
     * @return string|null                   código de error, o null si todo bien
     */
    private static function aplicarMunicipio(array &$safe, array $actuales = []): ?string
    {
        if (!array_key_exists('LOCALIDAD', $safe) && !array_key_exists('PROVINCIA', $safe)) {
            return null;
        }
        $loc = array_key_exists('LOCALIDAD', $safe) ? $safe['LOCALIDAD'] : ($actuales['LOCALIDAD'] ?? null);
        $prov = array_key_exists('PROVINCIA', $safe) ? $safe['PROVINCIA'] : ($actuales['PROVINCIA'] ?? null);

        [$loc, $prov, $err] = self::fijarMunicipio(
            $loc === null ? null : (string) $loc,
            $prov === null ? null : (string) $prov
        );
        if ($err !== null) {
            return $err;
        }
        // La provincia se escribe siempre que haya localidad, venga o no en el
        // formulario: es la garantía de que el par nunca queda descuadrado.
        $safe['LOCALIDAD'] = $loc;
        $safe['PROVINCIA'] = $prov;
        return null;
    }

    /** @param list<int> $ids */
    private static function allAutoresExist(array $ids): bool
    {
        if ($ids === []) return false;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $row = Db::one("SELECT COUNT(*) AS c FROM autor WHERE ID_AUTOR IN ($ph)", array_values($ids));
        return (int) ($row['c'] ?? 0) === count($ids);
    }

    // ── editMarcha ─────────────────────────────────────────────────────────
    /**
     * @param list<string> $keys
     * @param list<mixed>  $values
     * @return array{code:string, changes?:int}
     */
    public static function editMarcha(int $marchaId, array $keys, array $values): array
    {
        $safe = [];
        foreach ($keys as $i => $k) {
            if (in_array($k, self::EDITABLE_MARCHA, true)) $safe[$k] = self::normalize($values[$i] ?? null);
        }
        if ($safe === []) return ['code' => 'INVALID_FIELDS'];

        if (array_key_exists('FECHA', $safe) && $safe['FECHA'] !== null && !preg_match('/^\d{4}$/', (string) $safe['FECHA'])) {
            return ['code' => 'INVALID_FECHA'];
        }
        if (array_key_exists('ESTILO', $safe) && $safe['ESTILO'] !== null && !in_array($safe['ESTILO'], ['CCTT', 'AM'], true)) {
            return ['code' => 'INVALID_ESTILO'];
        }
        if (array_key_exists('TIPO', $safe) && $safe['TIPO'] !== null && !in_array($safe['TIPO'], self::MARCHA_TIPOS, true)) {
            return ['code' => 'INVALID_TIPO'];
        }
        $actual = Db::one('SELECT LOCALIDAD, PROVINCIA FROM marcha WHERE ID_MARCHA = ?', [$marchaId]) ?? [];
        if (($err = self::aplicarMunicipio($safe, $actual)) !== null) {
            return ['code' => $err];
        }

        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($safe)));
        $changes = Db::run("UPDATE marcha SET $set WHERE ID_MARCHA = ?", [...array_values($safe), $marchaId]);
        if ($changes === 0) return ['code' => 'NOT_FOUND'];

        Db::logAdmin('UPDATE', 'marcha', $marchaId, ['campos' => array_keys($safe)]);
        return ['code' => 'UPDATED', 'changes' => $changes];
    }

    // ── addMarcha ──────────────────────────────────────────────────────────
    /**
     * @param array<string,mixed> $fields  campo => valor
     * @param list<int> $autoresIds
     * @param int|null $excluirIdCand      candidato de ingesta que se está aceptando (si aplica), para no reevaluarse a sí mismo
     * @param int|null $bandaOrigenCand    ID_BANDA del candidato aceptado (si aplica), por si BANDA_ESTRENO se corrigió a mano en el formulario
     * @return array{code:string, marchaId?:int}
     */
    public static function addMarcha(array $fields, array $autoresIds, ?int $excluirIdCand = null, ?int $bandaOrigenCand = null): array
    {
        $safe = [];
        foreach (self::INSERTABLE_MARCHA as $f) {
            if (array_key_exists($f, $fields)) $safe[$f] = self::normalize($fields[$f]);
        }
        if ($safe === []) return ['code' => 'INVALID_PAYLOAD'];

        if (array_key_exists('FECHA', $safe) && $safe['FECHA'] !== null && !preg_match('/^\d{4}$/', (string) $safe['FECHA'])) {
            return ['code' => 'INVALID_FECHA'];
        }
        if (array_key_exists('ESTILO', $safe) && $safe['ESTILO'] !== null && !in_array($safe['ESTILO'], ['CCTT', 'AM'], true)) {
            return ['code' => 'INVALID_ESTILO'];
        }
        if (array_key_exists('TIPO', $safe) && $safe['TIPO'] !== null && !in_array($safe['TIPO'], self::MARCHA_TIPOS, true)) {
            return ['code' => 'INVALID_TIPO'];
        }
        if (($err = self::aplicarMunicipio($safe)) !== null) {
            return ['code' => $err];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $autoresIds),
            static fn(int $n): bool => $n > 0
        )));
        if ($ids === []) return ['code' => 'AUTHORS_REQUIRED'];
        if (!self::allAutoresExist($ids)) return ['code' => 'INVALID_AUTHORS'];

        $cols = array_keys($safe);
        $marchaId = Db::transaction(static function () use ($cols, $safe, $ids): int {
            Db::run(
                'INSERT INTO marcha (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
                array_values($safe)
            );
            $newId = Db::lastInsertId();
            if (!$newId) throw new \RuntimeException('Could not create marcha');
            $rowsPh = implode(', ', array_fill(0, count($ids), '(?, ?)'));
            $params = [];
            foreach ($ids as $aid) { $params[] = $newId; $params[] = $aid; }
            Db::run("INSERT INTO marcha_autor (ID_MARCHA, ID_AUTOR) VALUES $rowsPh", $params);
            return $newId;
        });

        Db::logAdmin('INSERT', 'marcha', $marchaId, ['campos' => $cols, 'autores' => $ids]);
        IngestaRepo::reevaluarTrasCrearMarcha(
            $marchaId,
            isset($safe['BANDA_ESTRENO']) ? (int) $safe['BANDA_ESTRENO'] : null,
            (string) ($safe['TITULO'] ?? ''),
            $excluirIdCand,
            $bandaOrigenCand
        );
        return ['code' => 'CREATED', 'marchaId' => $marchaId];
    }

    // ── editMarchaAutores ──────────────────────────────────────────────────
    /**
     * @param list<int> $autoresIds
     * @return array{code:string}
     */
    public static function editMarchaAutores(int $marchaId, array $autoresIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $autoresIds),
            static fn(int $n): bool => $n > 0
        )));
        if ($ids === []) return ['code' => 'BAD_REQUEST'];
        if (!self::allAutoresExist($ids)) return ['code' => 'INVALID_AUTORES'];

        Db::transaction(static function () use ($marchaId, $ids): void {
            Db::run('DELETE FROM marcha_autor WHERE ID_MARCHA = ?', [$marchaId]);
            foreach ($ids as $aid) {
                Db::run('INSERT INTO marcha_autor (ID_MARCHA, ID_AUTOR) VALUES (?, ?)', [$marchaId, $aid]);
            }
        });

        Db::logAdmin('UPDATE', 'marcha_autor', $marchaId, ['autoresIds' => $ids]);
        return ['code' => 'UPDATED'];
    }

    // ── editAutor ──────────────────────────────────────────────────────────
    /**
     * @param list<string> $keys
     * @param list<mixed>  $values  (ya normalizados por el controlador)
     * @return array{code:string}
     */
    public static function editAutor(int $autorId, array $keys, array $values): array
    {
        if ($keys === []) return ['code' => 'BAD_REQUEST'];
        foreach ($keys as $k) {
            if (!in_array($k, self::EDITABLE_AUTOR, true)) return ['code' => 'BAD_REQUEST'];
        }
        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", $keys));
        Db::run("UPDATE autor SET $set WHERE ID_AUTOR = ?", [...$values, $autorId]);
        Db::logAdmin('UPDATE', 'autor', $autorId, ['keysToUpdate' => $keys, 'valuesToUpdate' => $values]);
        return ['code' => 'UPDATED'];
    }

    // ── addAutor ───────────────────────────────────────────────────────────
    /**
     * @param array<string,mixed> $autor  campo => valor
     * @return array{code:string, autorId?:int}
     */
    public static function addAutor(array $autor): array
    {
        $values = array_map(static fn(string $f) => self::normalize($autor[$f] ?? null), self::EDITABLE_AUTOR);
        $ph = implode(', ', array_fill(0, count(self::EDITABLE_AUTOR), '?'));
        Db::run('INSERT INTO autor (' . implode(', ', self::EDITABLE_AUTOR) . ") VALUES ($ph)", $values);
        $autorId = Db::lastInsertId();
        if (!$autorId) return ['code' => 'INTERNAL_ERROR'];
        Db::logAdmin('INSERT', 'autor', $autorId);
        return ['code' => 'CREATED', 'autorId' => $autorId];
    }

    // ── editBanda ──────────────────────────────────────────────────────────
    /**
     * @param list<string> $keys
     * @param list<mixed>  $values  (ya normalizados por el controlador)
     * @return array{code:string}
     */
    public static function editBanda(int $bandaId, array $keys, array $values): array
    {
        if ($keys === []) return ['code' => 'BAD_REQUEST'];
        $safe = [];
        foreach ($keys as $i => $k) {
            if (!in_array($k, self::EDITABLE_BANDA, true)) return ['code' => 'BAD_REQUEST'];
            $safe[$k] = self::normalize($values[$i] ?? null);
        }
        foreach (['FECHA_FUND', 'FECHA_EXT'] as $f) {
            if (array_key_exists($f, $safe) && $safe[$f] !== null && !preg_match('/^\d{4}$/', (string) $safe[$f])) {
                return ['code' => 'INVALID_FECHA'];
            }
        }
        $actual = Db::one('SELECT LOCALIDAD, PROVINCIA FROM banda WHERE ID_BANDA = ?', [$bandaId]) ?? [];
        if (($err = self::aplicarMunicipio($safe, $actual)) !== null) {
            return ['code' => $err];
        }
        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($safe)));
        Db::run("UPDATE banda SET $set WHERE ID_BANDA = ?", [...array_values($safe), $bandaId]);
        Db::logAdmin('UPDATE', 'banda', $bandaId, ['campos' => array_keys($safe)]);
        return ['code' => 'UPDATED'];
    }

    // ── addBanda ───────────────────────────────────────────────────────────
    /**
     * Alta de banda. NOMBRE_BREVE es obligatorio (es lo que se muestra en todo
     * el catálogo); el resto de campos son opcionales. Mismos años de 4 dígitos
     * que editBanda.
     *
     * @param array<string,mixed> $banda  campo => valor
     * @return array{code:string, bandaId?:int}
     */
    public static function addBanda(array $banda): array
    {
        $safe = [];
        foreach (self::EDITABLE_BANDA as $f) {
            if (array_key_exists($f, $banda)) $safe[$f] = self::normalize($banda[$f]);
        }
        if (self::normalize($safe['NOMBRE_BREVE'] ?? null) === null) return ['code' => 'NOMBRE_REQUERIDO'];
        foreach (['FECHA_FUND', 'FECHA_EXT'] as $f) {
            if (array_key_exists($f, $safe) && $safe[$f] !== null && !preg_match('/^\d{4}$/', (string) $safe[$f])) {
                return ['code' => 'INVALID_FECHA'];
            }
        }
        if (($err = self::aplicarMunicipio($safe)) !== null) {
            return ['code' => $err];
        }
        $cols = array_keys($safe);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        Db::run('INSERT INTO banda (' . implode(', ', $cols) . ") VALUES ($ph)", array_values($safe));
        $bandaId = Db::lastInsertId();
        if (!$bandaId) return ['code' => 'INTERNAL_ERROR'];
        Db::logAdmin('INSERT', 'banda', $bandaId, ['campos' => $cols]);
        return ['code' => 'CREATED', 'bandaId' => $bandaId];
    }

    // ── Relaciones de linaje entre bandas (banda_relacion) ──────────────────
    public const RELACION_TIPOS = ['renombrado', 'fusion', 'division', 'juvenil'];

    private static function bandaExiste(int $id): bool
    {
        return Db::one('SELECT 1 AS x FROM banda WHERE ID_BANDA = ?', [$id]) !== null;
    }

    /**
     * Alta de una relación dirigida ORIGEN → DESTINO. `FECHA_FIN` sólo se guarda
     * para `juvenil` (en el resto de tipos no tiene sentido).
     *
     * @return array{code:string, relacionId?:int}
     */
    public static function addRelacion(int $origen, int $destino, string $tipo, ?string $fechaInicio, ?string $fechaFin, ?string $nota): array
    {
        if (!in_array($tipo, self::RELACION_TIPOS, true)) return ['code' => 'INVALID_TIPO'];
        if ($origen <= 0 || $destino <= 0) return ['code' => 'INVALID_BANDA'];
        if ($origen === $destino) return ['code' => 'SAME_BANDA'];
        if (!self::bandaExiste($origen) || !self::bandaExiste($destino)) return ['code' => 'INVALID_BANDA'];

        $fi = self::normalize($fechaInicio);
        $ff = $tipo === 'juvenil' ? self::normalize($fechaFin) : null;
        foreach ([$fi, $ff] as $f) {
            if ($f !== null && !preg_match('/^\d{4}$/', (string) $f)) return ['code' => 'INVALID_FECHA'];
        }
        $iFi = $fi !== null ? (int) $fi : null;
        $iFf = $ff !== null ? (int) $ff : null;
        if ($iFi !== null && $iFf !== null && $iFf < $iFi) return ['code' => 'FECHA_FIN_ANTERIOR'];

        try {
            Db::run(
                'INSERT INTO banda_relacion (ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO, FECHA_FIN, NOTA)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$origen, $destino, $tipo, $iFi, $iFf, self::normalize($nota)]
            );
        } catch (\PDOException $e) {
            // UNIQUE (ID_ORIGEN, ID_DESTINO, TIPO, FECHA_INICIO)
            if (str_contains($e->getMessage(), 'UNIQUE')) return ['code' => 'DUPLICATE'];
            throw $e;
        }

        $relacionId = Db::lastInsertId();
        Db::logAdmin('INSERT', 'banda_relacion', $relacionId, ['origen' => $origen, 'destino' => $destino, 'tipo' => $tipo]);
        return ['code' => 'CREATED', 'relacionId' => $relacionId];
    }

    /** @return array{code:string} */
    public static function deleteRelacion(int $idRelacion): array
    {
        $changes = Db::run('DELETE FROM banda_relacion WHERE ID_RELACION = ?', [$idRelacion]);
        if ($changes === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('DELETE', 'banda_relacion', $idRelacion);
        return ['code' => 'DELETED'];
    }

    // ── Temporada / contratos (N-04/N-05) — alta manual, sin edición: borrar
    // y volver a crear si hay un error, es más simple que un formulario de
    // edición para el volumen bajo que tiene esto de momento. ────────────────

    /** @return array{code:string, contratoId?:int} */
    public static function addContrato(int $idBanda, string $hermandad, string $anio, ?string $titular, ?string $fuente, ?string $nota): array
    {
        if (!self::bandaExiste($idBanda)) return ['code' => 'INVALID_BANDA'];
        $hermandad = trim($hermandad);
        if ($hermandad === '') return ['code' => 'HERMANDAD_REQUERIDA'];
        if (!preg_match('/^\d{4}$/', $anio)) return ['code' => 'INVALID_ANIO'];

        Db::run(
            'INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO, FUENTE, NOTA)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$idBanda, $hermandad, Slug::slugify($hermandad), self::normalize($titular), (int) $anio, self::normalize($fuente), self::normalize($nota)]
        );
        $contratoId = Db::lastInsertId();
        Db::logAdmin('INSERT', 'contrato', $contratoId, ['banda' => $idBanda, 'hermandad' => $hermandad, 'anio' => $anio]);
        return ['code' => 'CREATED', 'contratoId' => $contratoId];
    }

    /** @return array{code:string} */
    public static function deleteContrato(int $idContrato): array
    {
        $changes = Db::run('DELETE FROM contrato WHERE ID_CONTRATO = ?', [$idContrato]);
        if ($changes === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('DELETE', 'contrato', $idContrato);
        return ['code' => 'DELETED'];
    }

    // ── Ingesta (candidatos de YouTube, ver tools/ingest/) ──────────────────

    /**
     * Acepta un candidato: crea la marcha (con el mismo camino que addMarcha)
     * y, si $guardarOrigen es true (por defecto), además guarda el enlace del
     * que salió, en el sitio que le corresponde según la fuente:
     *
     *  - `youtube` → `marcha.AUDIO` (el hueco del embed; addMarcha no admite
     *    AUDIO al crear porque una marcha añadida a mano no suele tener vídeo).
     *  - `spotify` / `deezer` / `apple` → `enlace_streaming` como enlace
     *    verificado de la marcha, que es lo que pinta la botonera de la ficha
     *    pública. Así la marcha nace ya escuchable en su servicio de origen.
     *
     * @param array<string,mixed> $fields
     * @param list<int> $autoresIds
     * @return array{code:string, marchaId?:int}
     */
    public static function aceptarCandidato(int $idCand, array $fields, array $autoresIds, bool $guardarOrigen = true): array
    {
        $cand = Db::one('SELECT ESTADO, FUENTE, VIDEO_URL, ID_BANDA, ISRC, P_TITULO, VIDEO_TITULO FROM ingest_candidato WHERE ID_CAND = ?', [$idCand]);
        if ($cand === null) return ['code' => 'NOT_FOUND'];
        if ($cand['ESTADO'] !== 'pendiente') return ['code' => 'NOT_PENDING'];

        // La banda de origen (canal de YouTube) del candidato puede no coincidir
        // con BANDA_ESTRENO si el revisor la corrigió en el formulario (p.ej. el
        // vídeo lo sube un canal de grabación distinto de la banda de estreno
        // real). Reevaluamos por ambas para no perder duplicados del mismo canal.
        $r = self::addMarcha($fields, $autoresIds, $idCand, $cand['ID_BANDA'] !== null ? (int) $cand['ID_BANDA'] : null);
        if (($r['code'] ?? '') !== 'CREATED') return $r;

        $fuente = (string) ($cand['FUENTE'] ?? 'youtube');
        if ($guardarOrigen && !empty($cand['VIDEO_URL'])) {
            if ($fuente === 'youtube') {
                self::editMarcha($r['marchaId'], ['AUDIO'], [$cand['VIDEO_URL']]);
            } elseif (in_array($fuente, EnlaceRepo::SERVICIOS, true)) {
                self::setEnlaceStreaming('marcha', $r['marchaId'], $fuente, (string) $cand['VIDEO_URL'], $cand['ISRC'] ?? null);
            }
        }

        Db::run(
            "UPDATE ingest_candidato SET ESTADO = 'aceptado', MARCHA_CREADA = ?, REVIEWED_AT = datetime('now') WHERE ID_CAND = ?",
            [$r['marchaId'], $idCand]
        );
        Db::logAdmin('ACCEPT', 'ingest_candidato', $idCand, ['marchaId' => $r['marchaId']]);

        // Si el mismo estreno estaba pendiente también en otro catálogo (mismo
        // título + banda, otra fuente), ya no aporta nada nuevo: se descarta
        // también, como si el revisor lo hubiera hecho a mano.
        $titulo = (string) ($cand['P_TITULO'] ?: $cand['VIDEO_TITULO']);
        $hermanos = self::descartarHermanosMismoTitulo(
            $idCand,
            $cand['ID_BANDA'] !== null ? (int) $cand['ID_BANDA'] : null,
            $titulo,
            (string) ($cand['FUENTE'] ?? 'youtube')
        );
        if ($hermanos !== []) self::registrarUltimoDescarte($hermanos);

        return $r;
    }

    /**
     * Descarta en cascada los "hermanos" de $idCand: candidatos pendientes con
     * el mismo título y la misma banda pero de otra fuente — el mismo estreno
     * visto a la vez en dos catálogos (p.ej. YouTube y Spotify). Se llama tras
     * aceptar o descartar $idCand: a partir de ahí no aportan nada y dejarlos
     * pendientes solo sería trabajo doble para el revisor. Mismo tratamiento
     * que un descarte manual (vetados, con motivo propio).
     *
     * @param list<int> $idsPrevios  ids que la acción que llama ya va a incluir
     *        en su propio registro de "último descarte" (p.ej. el propio
     *        $idCand si viene de un descarte manual) — se fusionan con los
     *        hermanos encontrados para que deshacer esa acción recupere
     *        también a estos.
     * @return list<int> $idsPrevios + los hermanos descartados (sin duplicados)
     */
    private static function descartarHermanosMismoTitulo(int $idCand, ?int $idBanda, string $titulo, string $fuente, array $idsPrevios = []): array
    {
        $hermanos = IngestaRepo::hermanosMismoTitulo($idCand, $idBanda, $titulo, $fuente);
        if ($hermanos !== []) {
            $motivo = "Descarte automático: mismo título y banda que el candidato #$idCand en otra fuente, ya resuelto";
            $ph = implode(',', array_fill(0, count($hermanos), '?'));
            Db::run(
                "UPDATE ingest_candidato SET ESTADO = 'descartado', MOTIVO = ?, REVIEWED_AT = datetime('now')
                 WHERE ID_CAND IN ($ph) AND ESTADO = 'pendiente'",
                [$motivo, ...$hermanos]
            );
            self::vetarCandidatos($hermanos, $motivo);
            Db::logAdmin('DISCARD', 'ingest_candidato', null, ['ids' => $hermanos, 'motivo' => 'hermano_mismo_titulo', 'origen' => $idCand]);
        }
        return array_values(array_unique([...$idsPrevios, ...$hermanos]));
    }

    /** @return array{code:string} */
    public static function descartarCandidato(int $idCand, ?string $motivo): array
    {
        $motivo = self::normalize($motivo);

        return Db::transaction(static function () use ($idCand, $motivo): array {
            $cand = Db::one('SELECT ID_BANDA, FUENTE, P_TITULO, VIDEO_TITULO FROM ingest_candidato WHERE ID_CAND = ?', [$idCand]);

            $changes = Db::run(
                "UPDATE ingest_candidato SET ESTADO = 'descartado', MOTIVO = ?, REVIEWED_AT = datetime('now')
                 WHERE ID_CAND = ? AND ESTADO = 'pendiente'",
                [$motivo, $idCand]
            );
            if ($changes === 0) return ['code' => 'NOT_FOUND_OR_NOT_PENDING'];

            self::vetarCandidatos([$idCand], $motivo);

            $idsDescarte = [$idCand];
            if ($cand !== null) {
                $titulo = (string) ($cand['P_TITULO'] ?: $cand['VIDEO_TITULO']);
                $idsDescarte = self::descartarHermanosMismoTitulo(
                    $idCand,
                    $cand['ID_BANDA'] !== null ? (int) $cand['ID_BANDA'] : null,
                    $titulo,
                    (string) ($cand['FUENTE'] ?? 'youtube'),
                    $idsDescarte
                );
            }
            self::registrarUltimoDescarte($idsDescarte);
            Db::logAdmin('DISCARD', 'ingest_candidato', $idCand, ['motivo' => $motivo]);
            return ['code' => 'DISCARDED'];
        });
    }

    /**
     * Deja constancia permanente de que estos orígenes ya se rechazaron, para
     * que ni la reimportación del lote ni las pasadas futuras del descubridor
     * los vuelvan a proponer (ver `ingest_veto` en 008_ingest_streaming.sql).
     * El veto es por origen exacto: mismo servicio + mismo id de pista/vídeo.
     *
     * @param list<int> $ids
     */
    private static function vetarCandidatos(array $ids, ?string $motivo = null): void
    {
        if ($ids === []) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT ID_CAND, FUENTE, VIDEO_ID, ID_BANDA, P_TITULO, VIDEO_TITULO, MOTIVO
             FROM ingest_candidato WHERE ID_CAND IN ($ph)",
            $ids
        );
        $usuario = Db::auditUser();

        foreach ($rows as $r) {
            if ((string) $r['VIDEO_ID'] === '') continue;
            Db::run(
                'INSERT INTO ingest_veto (FUENTE, FUENTE_ID, ID_BANDA, TITULO, MOTIVO, ID_CAND, USUARIO)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (FUENTE, FUENTE_ID) DO UPDATE SET
                    ID_BANDA = excluded.ID_BANDA, TITULO = excluded.TITULO, MOTIVO = excluded.MOTIVO,
                    ID_CAND = excluded.ID_CAND, USUARIO = excluded.USUARIO, CREATED_AT = datetime(\'now\')',
                [
                    (string) ($r['FUENTE'] ?? 'youtube'),
                    (string) $r['VIDEO_ID'],
                    $r['ID_BANDA'] !== null ? (int) $r['ID_BANDA'] : null,
                    (string) ($r['P_TITULO'] ?: $r['VIDEO_TITULO']),
                    $motivo ?? self::normalize($r['MOTIVO']),
                    (int) $r['ID_CAND'],
                    $usuario,
                ]
            );
        }
    }

    /**
     * Guarda el descarte recién hecho como "el último", que es lo que deshace
     * el botón del panel. Es un solo paso a propósito: cada descarte nuevo
     * sustituye al anterior (fila única, ID = 1).
     *
     * @param list<int> $ids
     */
    private static function registrarUltimoDescarte(array $ids): void
    {
        if ($ids === []) return;
        Db::run(
            "INSERT INTO ingest_descarte_ultimo (ID, IDS_JSON, N, USUARIO, CREATED_AT)
             VALUES (1, ?, ?, ?, datetime('now'))
             ON CONFLICT (ID) DO UPDATE SET
                IDS_JSON = excluded.IDS_JSON, N = excluded.N,
                USUARIO = excluded.USUARIO, CREATED_AT = datetime('now')",
            [json_encode(array_values($ids)), count($ids), Db::auditUser()]
        );
    }

    /**
     * Deshace el último descarte (un solo paso): devuelve los candidatos a
     * "pendiente", levanta su veto y consume el registro, de modo que el
     * botón desaparece hasta el siguiente descarte. Pensado para el error de
     * click, no para revisar el histórico — para eso está la pestaña
     * "Descartados".
     *
     * @return array{code:string, count?:int}
     */
    public static function deshacerUltimoDescarte(): array
    {
        return Db::transaction(static function (): array {
            $row = Db::one('SELECT IDS_JSON FROM ingest_descarte_ultimo WHERE ID = 1');
            if ($row === null) return ['code' => 'NOTHING_TO_UNDO'];

            $ids = json_decode((string) $row['IDS_JSON'], true);
            $ids = is_array($ids)
                ? array_values(array_filter(array_map('intval', $ids), static fn(int $n): bool => $n > 0))
                : [];
            Db::run('DELETE FROM ingest_descarte_ultimo WHERE ID = 1');
            if ($ids === []) return ['code' => 'NOTHING_TO_UNDO'];

            $ph = implode(',', array_fill(0, count($ids), '?'));
            // El veto se levanta por el origen del candidato, no por su id de
            // fila: es la clave con la que se bloquean las reimportaciones.
            Db::run(
                "DELETE FROM ingest_veto WHERE (FUENTE, FUENTE_ID) IN (
                    SELECT FUENTE, VIDEO_ID FROM ingest_candidato WHERE ID_CAND IN ($ph)
                 )",
                $ids
            );
            $changes = Db::run(
                "UPDATE ingest_candidato
                 SET ESTADO = 'pendiente', MOTIVO = NULL, REVIEWED_AT = NULL
                 WHERE ID_CAND IN ($ph) AND ESTADO = 'descartado'",
                $ids
            );
            if ($changes === 0) return ['code' => 'NOTHING_TO_UNDO'];

            Db::logAdmin('UNDO_DISCARD', 'ingest_candidato', count($ids) === 1 ? $ids[0] : null, ['ids' => $ids, 'count' => $changes]);
            return ['code' => 'UNDONE', 'count' => $changes];
        });
    }

    /**
     * Descarta varios candidatos pendientes a la vez (desde el listado, sin motivo).
     *
     * @param list<int> $ids
     * @return array{code:string, count:int}
     */
    public static function descartarVarios(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $n): bool => $n > 0)));
        if ($ids === []) return ['code' => 'BAD_REQUEST', 'count' => 0];

        return Db::transaction(static function () use ($ids): array {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Solo se descartan (y por tanto se vetan y se pueden deshacer) los
            // que estaban pendientes de verdad: si en la lista venía alguno ya
            // revisado, queda fuera de las tres operaciones.
            $rows = Db::all(
                "SELECT ID_CAND, ID_BANDA, FUENTE, P_TITULO, VIDEO_TITULO
                 FROM ingest_candidato WHERE ID_CAND IN ($ph) AND ESTADO = 'pendiente'",
                $ids
            );
            if ($rows === []) return ['code' => 'NOT_FOUND_OR_NOT_PENDING', 'count' => 0];
            $pendientes = array_map(static fn(array $r): int => (int) $r['ID_CAND'], $rows);

            $phP = implode(',', array_fill(0, count($pendientes), '?'));
            $changes = Db::run(
                "UPDATE ingest_candidato SET ESTADO = 'descartado', REVIEWED_AT = datetime('now')
                 WHERE ID_CAND IN ($phP)",
                $pendientes
            );
            self::vetarCandidatos($pendientes);

            // Cada uno de los recién descartados puede tener a su vez un hermano
            // (mismo título/banda, otra fuente) que todavía estuviera pendiente
            // y no viniera en la selección: se arrastra también.
            $idsDescarte = $pendientes;
            foreach ($rows as $r) {
                $titulo = (string) ($r['P_TITULO'] ?: $r['VIDEO_TITULO']);
                $idsDescarte = self::descartarHermanosMismoTitulo(
                    (int) $r['ID_CAND'],
                    $r['ID_BANDA'] !== null ? (int) $r['ID_BANDA'] : null,
                    $titulo,
                    (string) ($r['FUENTE'] ?? 'youtube'),
                    $idsDescarte
                );
            }
            self::registrarUltimoDescarte($idsDescarte);
            Db::logAdmin('DISCARD', 'ingest_candidato', null, ['ids' => $pendientes, 'count' => $changes]);
            return ['code' => 'DISCARDED', 'count' => $changes];
        });
    }

    // ── Enlaces de streaming: curación (aprobar / rechazar) ───────────────────

    /**
     * Aprueba un candidato de enlace: lo publica en enlace_streaming (upsert por
     * entidad+servicio) y marca el candidato como aprobado. Distintos servicios
     * conviven para una misma entidad; re-aprobar sustituye la URL anterior.
     *
     * @return array{code:string}
     */
    public static function aprobarEnlace(int $idCand): array
    {
        $c = Db::one(
            'SELECT TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, ESTADO FROM enlace_candidato WHERE ID_CAND = ?',
            [$idCand]
        );
        if ($c === null) return ['code' => 'NOT_FOUND'];
        if ($c['ESTADO'] !== 'pendiente') return ['code' => 'NOT_PENDING'];

        return Db::transaction(static function () use ($c, $idCand): array {
            Db::run(
                "INSERT INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, VERIFICADO)
                 VALUES (?, ?, ?, ?, ?, 1)
                 ON CONFLICT(TIPO_ENT, ID_ENT, SERVICIO)
                 DO UPDATE SET URL = excluded.URL, ID_EXT = excluded.ID_EXT,
                               VERIFICADO = 1, FECHA_ALTA = datetime('now')",
                [$c['TIPO_ENT'], (int) $c['ID_ENT'], $c['SERVICIO'], $c['URL'], $c['ID_EXT']]
            );
            Db::run("UPDATE enlace_candidato SET ESTADO = 'aprobado' WHERE ID_CAND = ?", [$idCand]);
            Db::logAdmin('APPROVE', 'enlace_candidato', $idCand,
                ['servicio' => $c['SERVICIO'], 'ent' => $c['TIPO_ENT'] . ':' . $c['ID_ENT']]);
            return ['code' => 'APPROVED'];
        });
    }

    /**
     * Alta/edición/baja manual de un enlace de streaming de una entidad (banda, disco,
     * marcha), al margen del flujo de candidatos — para vincular a mano un perfil oficial
     * que la ingesta automática no encontró. $url vacío borra el enlace si existía.
     *
     * @return array{code:string}
     */
    public static function setEnlaceStreaming(string $tipoEnt, int $idEnt, string $servicio, ?string $url, ?string $isrc = null): array
    {
        if (!in_array($tipoEnt, ['banda', 'disco', 'marcha'], true)) return ['code' => 'BAD_REQUEST'];
        if (!in_array($servicio, EnlaceRepo::SERVICIOS, true)) return ['code' => 'BAD_REQUEST'];
        $url = self::normalize($url);
        $isrc = self::normalize($isrc);

        if ($url === null) {
            Db::run('DELETE FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ? AND SERVICIO = ?', [$tipoEnt, $idEnt, $servicio]);
            Db::logAdmin('DELETE', 'enlace_streaming', $idEnt, ['tipo' => $tipoEnt, 'servicio' => $servicio]);
            return ['code' => 'DELETED'];
        }

        // R-01: el ISRC solo llega desde la ingesta (Spotify/Deezer); una
        // edición manual del enlace en el panel no lo toca — por eso solo se
        // sobrescribe cuando se pasa uno nuevo, nunca se borra a NULL aquí.
        Db::run(
            "INSERT INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, ISRC, VERIFICADO)
             VALUES (?, ?, ?, ?, ?, 1)
             ON CONFLICT(TIPO_ENT, ID_ENT, SERVICIO)
             DO UPDATE SET URL = excluded.URL, VERIFICADO = 1, FECHA_ALTA = datetime('now'),
                            ISRC = COALESCE(excluded.ISRC, enlace_streaming.ISRC)",
            [$tipoEnt, $idEnt, $servicio, $url, $isrc]
        );
        Db::logAdmin('UPDATE', 'enlace_streaming', $idEnt, ['tipo' => $tipoEnt, 'servicio' => $servicio]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Publica un enlace SOLO si esa entidad no tenía ya uno de ese servicio.
     *
     * Es la escritura de la cascada automática (App\EnlacesAuto): el guardarraíl
     * es la UNIQUE(TIPO_ENT, ID_ENT, SERVICIO), así que un enlace curado a mano
     * nunca se pisa y repetir la cascada es idempotente. Mismo criterio (y misma
     * sentencia) que el INSERT OR IGNORE de tools/fill_enlaces_odesli.php.
     *
     * @return bool  true si se ha escrito una fila nueva
     */
    public static function addEnlaceStreamingSiFalta(
        string $tipoEnt,
        int $idEnt,
        string $servicio,
        string $url,
        ?string $idExt = null,
        ?string $isrc = null
    ): bool {
        if (!in_array($tipoEnt, ['banda', 'disco', 'marcha'], true)) return false;
        if (!in_array($servicio, EnlaceRepo::SERVICIOS, true)) return false;
        $url = (string) self::normalize($url);
        if ($url === '') return false;

        $filas = Db::run(
            'INSERT OR IGNORE INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, VERIFICADO, ISRC)
             VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$tipoEnt, $idEnt, $servicio, $url, self::normalize($idExt), self::normalize($isrc)]
        );
        if ($filas > 0) {
            Db::logAdmin('INSERT', 'enlace_streaming', $idEnt,
                ['tipo' => $tipoEnt, 'servicio' => $servicio, 'origen' => 'cascada']);
        }
        return $filas > 0;
    }

    /**
     * Encola un enlace dudoso para curarlo en /dashboard/enlaces en vez de
     * publicarlo. Lo usa la cascada automática cuando la coincidencia no llega
     * al umbral de identidad (recopilatorios, álbumes mal agrupados en Odesli,
     * artistas con nombre genérico).
     *
     * @return bool true si se ha encolado uno nuevo
     */
    public static function addEnlaceCandidato(
        string $tipoEnt,
        int $idEnt,
        string $servicio,
        string $url,
        ?string $idExt,
        ?string $tituloEnc,
        ?string $artistaEnc,
        ?string $anioEnc,
        float $score,
        string $runId
    ): bool {
        if (!in_array($tipoEnt, ['banda', 'disco', 'marcha'], true)) return false;
        if (!in_array($servicio, EnlaceRepo::SERVICIOS, true)) return false;
        $url = (string) self::normalize($url);
        if ($url === '') return false;

        // Mismos tramos que el batch: por encima de 0.40 merece una mirada,
        // por debajo se guarda igual pero marcado como BAJA.
        $confianza = $score >= 0.40 ? 'MEDIA' : 'BAJA';
        $filas = Db::run(
            "INSERT OR IGNORE INTO enlace_candidato
                (TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, TITULO_ENC, ARTISTA_ENC, ANIO_ENC, SCORE, CONFIANZA, ESTADO, RUN_ID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)",
            [$tipoEnt, $idEnt, $servicio, $url, self::normalize($idExt), self::normalize($tituloEnc),
             self::normalize($artistaEnc), self::normalize($anioEnc), $score, $confianza, $runId]
        );
        return $filas > 0;
    }

    /**
     * Enlaces publicados de una entidad, con su id nativo — la cascada necesita
     * el ID_EXT para pedir el tracklist del álbum sin volver a resolver la URL.
     *
     * @return array<string,array{url:string,id_ext:string}>
     */
    public static function enlacesConIdExt(string $tipoEnt, int $idEnt): array
    {
        $out = [];
        foreach (Db::all('SELECT SERVICIO, URL, ID_EXT FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ?', [$tipoEnt, $idEnt]) as $r) {
            $out[(string) $r['SERVICIO']] = ['url' => (string) $r['URL'], 'id_ext' => (string) ($r['ID_EXT'] ?? '')];
        }
        return $out;
    }

    /** @return array{code:string} */
    public static function rechazarEnlace(int $idCand): array
    {
        $changes = Db::run(
            "UPDATE enlace_candidato SET ESTADO = 'rechazado' WHERE ID_CAND = ? AND ESTADO = 'pendiente'",
            [$idCand]
        );
        if ($changes === 0) return ['code' => 'NOT_FOUND_OR_NOT_PENDING'];
        Db::logAdmin('REJECT', 'enlace_candidato', $idCand);
        return ['code' => 'REJECTED'];
    }

    /**
     * Rechaza varios candidatos pendientes a la vez (desde el listado).
     *
     * @param list<int> $ids
     * @return array{code:string, count:int}
     */
    public static function rechazarEnlaces(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $n): bool => $n > 0)));
        if ($ids === []) return ['code' => 'BAD_REQUEST', 'count' => 0];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $changes = Db::run(
            "UPDATE enlace_candidato SET ESTADO = 'rechazado' WHERE ID_CAND IN ($ph) AND ESTADO = 'pendiente'",
            $ids
        );
        if ($changes === 0) return ['code' => 'NOT_FOUND_OR_NOT_PENDING', 'count' => 0];
        Db::logAdmin('REJECT', 'enlace_candidato', null, ['ids' => $ids, 'count' => $changes]);
        return ['code' => 'REJECTED', 'count' => $changes];
    }

    // ── Marchas: curación de estilo (CCTT / AM) ───────────────────────────────

    /**
     * Asigna un estilo a varias marchas a la vez, desde /dashboard/estilos
     * (clic rápido por fila o selección múltiple). Sobrescribe el ESTILO
     * actual si ya tenía uno, para permitir corregir asignaciones previas.
     *
     * @param list<int> $ids
     * @return array{code:string, count:int}
     */
    public static function assignEstiloVarios(array $ids, string $estilo): array
    {
        if (!in_array($estilo, ['CCTT', 'AM'], true)) return ['code' => 'INVALID_ESTILO', 'count' => 0];
        $ids = array_values(array_unique(array_filter($ids, static fn(int $n): bool => $n > 0)));
        if ($ids === []) return ['code' => 'BAD_REQUEST', 'count' => 0];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $changes = Db::run("UPDATE marcha SET ESTILO = ? WHERE ID_MARCHA IN ($ph)", [$estilo, ...$ids]);
        if ($changes === 0) return ['code' => 'NOT_FOUND', 'count' => 0];
        Db::logAdmin('UPDATE', 'marcha', null, ['campos' => ['ESTILO'], 'ids' => $ids, 'estilo' => $estilo, 'count' => $changes]);
        return ['code' => 'ASSIGNED', 'count' => $changes];
    }

    // ── Dedicatorias: curación de advocaciones (hubs N-01 / N-02) ────────────

    /** Elimina la canónica $id si ya no le queda ninguna variante asociada. */
    private static function borrarCanonicaSiVacia(int $id): void
    {
        $n = Db::one('SELECT COUNT(*) AS c FROM dedicatoria_alias WHERE ID_DEDIC = ?', [$id]);
        if ((int) ($n['c'] ?? 0) === 0) {
            Db::run('DELETE FROM dedicatoria WHERE ID_DEDIC = ?', [$id]);
        }
    }

    /**
     * Renombra una canónica (NOMBRE / LOCALIDAD / PROVINCIA) y fija si es
     * PERSONAL (dedicatoria particular, excluida del índice público N-02 y del
     * sitemap) — override manual sobre la heurística de Repo::esDedicatoriaPersonal.
     * No toca SLUG_KEY: es solo la identidad interna de agrupación del seed, y
     * recalcularla podría colisionar con el UNIQUE de otra canónica.
     *
     * @return array{code:string}
     */
    public static function renameDedicatoria(int $id, string $nombre, string $localidad, ?string $provincia, bool $personal): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') return ['code' => 'NOMBRE_REQUERIDO'];
        // Mismo catálogo que marcha/banda. La localidad vacía sigue siendo
        // válida aquí ('' = advocación sin localidad), y la columna es NOT NULL.
        $campos = ['LOCALIDAD' => trim($localidad), 'PROVINCIA' => $provincia];
        if (($err = self::aplicarMunicipio($campos)) !== null) {
            return ['code' => $err];
        }
        $changes = Db::run(
            'UPDATE dedicatoria SET NOMBRE = ?, LOCALIDAD = ?, PROVINCIA = ?, PERSONAL = ? WHERE ID_DEDIC = ?',
            [$nombre, (string) ($campos['LOCALIDAD'] ?? ''), $campos['PROVINCIA'], $personal ? 1 : 0, $id]
        );
        if ($changes === 0) return ['code' => 'NOT_FOUND'];
        Db::logAdmin('UPDATE', 'dedicatoria', $id, ['nombre' => $nombre, 'localidad' => $localidad, 'personal' => $personal]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Reasigna la variante (VARIANTE, LOCALIDAD) a otra canónica $destino
     * (fusión). Si la canónica de origen se queda sin variantes, se elimina.
     *
     * @return array{code:string}
     */
    public static function moverAlias(string $variante, string $localidad, int $destino): array
    {
        if ($destino <= 0) return ['code' => 'INVALID_DESTINO'];
        if (Db::one('SELECT 1 AS x FROM dedicatoria WHERE ID_DEDIC = ?', [$destino]) === null) {
            return ['code' => 'DESTINO_NO_EXISTE'];
        }
        $alias = Db::one(
            'SELECT ID_DEDIC FROM dedicatoria_alias WHERE VARIANTE = ? AND LOCALIDAD = ?',
            [$variante, $localidad]
        );
        if ($alias === null) return ['code' => 'ALIAS_NO_EXISTE'];
        $origen = (int) $alias['ID_DEDIC'];
        if ($origen === $destino) return ['code' => 'SIN_CAMBIOS'];

        Db::transaction(static function () use ($variante, $localidad, $destino, $origen): void {
            Db::run(
                'UPDATE dedicatoria_alias SET ID_DEDIC = ? WHERE VARIANTE = ? AND LOCALIDAD = ?',
                [$destino, $variante, $localidad]
            );
            self::borrarCanonicaSiVacia($origen);
        });
        Db::logAdmin('UPDATE', 'dedicatoria_alias', $destino, ['variante' => $variante, 'localidad' => $localidad, 'origen' => $origen]);
        return ['code' => 'MOVED'];
    }

    /**
     * Separa la variante en una canónica NUEVA (deshace una fusión errónea). El
     * NOMBRE inicial es la propia variante (editable después). Si el origen se
     * queda vacío, se elimina.
     *
     * @return array{code:string, idDedic?:int}
     */
    public static function separarAlias(string $variante, string $localidad): array
    {
        $alias = Db::one(
            'SELECT ID_DEDIC FROM dedicatoria_alias WHERE VARIANTE = ? AND LOCALIDAD = ?',
            [$variante, $localidad]
        );
        if ($alias === null) return ['code' => 'ALIAS_NO_EXISTE'];
        $origen = (int) $alias['ID_DEDIC'];

        // SLUG_KEY única: base derivada + sufijo incremental si colisiona.
        $base = Slug::slugify($variante) . '|' . Slug::slugify($localidad);
        $key = $base;
        $i = 2;
        while (Db::one('SELECT 1 AS x FROM dedicatoria WHERE SLUG_KEY = ?', [$key]) !== null) {
            $key = $base . '-' . $i++;
        }

        $idDedic = Db::transaction(static function () use ($variante, $localidad, $key, $origen): int {
            Db::run(
                'INSERT INTO dedicatoria (NOMBRE, LOCALIDAD, PROVINCIA, SLUG_KEY, PERSONAL) VALUES (?, ?, NULL, ?, ?)',
                [trim($variante), $localidad, $key, Repo::esDedicatoriaPersonal($variante) ? 1 : 0]
            );
            $nuevo = Db::lastInsertId();
            Db::run(
                'UPDATE dedicatoria_alias SET ID_DEDIC = ? WHERE VARIANTE = ? AND LOCALIDAD = ?',
                [$nuevo, $variante, $localidad]
            );
            self::borrarCanonicaSiVacia($origen);
            return $nuevo;
        });
        Db::logAdmin('INSERT', 'dedicatoria', $idDedic, ['separada_de' => $origen, 'variante' => $variante]);
        return ['code' => 'SPLIT', 'idDedic' => $idDedic];
    }

    /**
     * Unifica todas las variantes de una canónica en la grafía elegida: reescribe
     * el par (DEDICATORIA, LOCALIDAD) de las marchas de las demás variantes al
     * objetivo y deja una sola fila en dedicatoria_alias. Es limpieza real del
     * texto libre (mismo tipo de UPDATE que editMarcha), no solo reagrupar.
     *
     * @return array{code:string, marchas?:int, variantes?:int}
     */
    public static function unificarVariantes(int $idDedic, string $varianteObjetivo, string $localidadObjetivo): array
    {
        $objetivo = Db::one(
            'SELECT 1 AS x FROM dedicatoria_alias WHERE ID_DEDIC = ? AND VARIANTE = ? AND LOCALIDAD = ?',
            [$idDedic, $varianteObjetivo, $localidadObjetivo]
        );
        if ($objetivo === null) return ['code' => 'OBJETIVO_INVALIDO'];

        $otras = Db::all(
            'SELECT VARIANTE, LOCALIDAD FROM dedicatoria_alias
             WHERE ID_DEDIC = ? AND NOT (VARIANTE = ? AND LOCALIDAD = ?)',
            [$idDedic, $varianteObjetivo, $localidadObjetivo]
        );
        if ($otras === []) return ['code' => 'SIN_CAMBIOS'];

        // Guardamos '' en dedicatoria_alias pero NULL en marcha (como el resto del
        // catálogo); el join usa COALESCE(m.LOCALIDAD,'') así que ambos casan.
        $locDestino = $localidadObjetivo !== '' ? $localidadObjetivo : null;
        $marchas = Db::transaction(static function () use ($otras, $idDedic, $varianteObjetivo, $localidadObjetivo, $locDestino): int {
            $n = 0;
            foreach ($otras as $o) {
                $n += Db::run(
                    "UPDATE marcha SET DEDICATORIA = ?, LOCALIDAD = ?
                     WHERE DEDICATORIA = ? AND COALESCE(LOCALIDAD, '') = ?",
                    [$varianteObjetivo, $locDestino, $o['VARIANTE'], $o['LOCALIDAD']]
                );
            }
            Db::run(
                'DELETE FROM dedicatoria_alias WHERE ID_DEDIC = ? AND NOT (VARIANTE = ? AND LOCALIDAD = ?)',
                [$idDedic, $varianteObjetivo, $localidadObjetivo]
            );
            return $n;
        });

        Db::logAdmin('UPDATE', 'dedicatoria', $idDedic, [
            'accion' => 'unificar', 'objetivo' => $varianteObjetivo,
            'variantes_absorbidas' => count($otras), 'marchas' => $marchas,
        ]);
        return ['code' => 'UNIFIED', 'marchas' => $marchas, 'variantes' => count($otras)];
    }

    // ── Discos ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $disco @return array{code:string,discoId?:int} */
    public static function addDisco(array $disco): array
    {
        $safe = self::saneaDisco($disco);
        if (isset($safe['code'])) return $safe;
        if (($safe['NOMBRE_CD'] ?? null) === null) return ['code' => 'NOMBRE_REQUERIDO'];

        $cols = array_keys($safe);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        Db::run('INSERT INTO disco (' . implode(', ', $cols) . ") VALUES ($ph)", array_values($safe));
        $discoId = Db::lastInsertId();
        if (!$discoId) return ['code' => 'INTERNAL_ERROR'];
        Db::logAdmin('INSERT', 'disco', $discoId, ['campos' => $cols]);
        return ['code' => 'CREATED', 'discoId' => $discoId];
    }

    /** @param array<string,mixed> $disco @return array{code:string} */
    public static function updateDisco(int $idDisco, array $disco): array
    {
        if (self::discoExiste($idDisco) === false) return ['code' => 'DISCO_NO_EXISTE'];
        $safe = self::saneaDisco($disco);
        if (isset($safe['code'])) return $safe;
        if ($safe === []) return ['code' => 'INVALID_FIELDS'];

        $sets = implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($safe)));
        Db::run("UPDATE disco SET $sets WHERE ID_DISCO = ?", [...array_values($safe), $idDisco]);
        Db::logAdmin('UPDATE', 'disco', $idDisco, ['campos' => array_keys($safe)]);
        return ['code' => 'UPDATED'];
    }

    /**
     * Normaliza y valida los campos del disco. Devuelve ['code' => ...] si algo
     * no cuadra, o el array de columnas listo para el INSERT/UPDATE.
     *
     * @param  array<string,mixed> $disco
     * @return array<string,mixed>
     */
    private static function saneaDisco(array $disco): array
    {
        $safe = [];
        foreach (self::EDITABLE_DISCO as $f) {
            if (array_key_exists($f, $disco)) $safe[$f] = self::normalize($disco[$f]);
        }
        // FECHA_CD es TEXT en el esquema heredado, pero solo guarda años.
        if (($safe['FECHA_CD'] ?? null) !== null && preg_match('/^\d{4}$/', (string) $safe['FECHA_CD']) !== 1) {
            return ['code' => 'INVALID_FECHA'];
        }
        if (($safe['BANDADISCO'] ?? null) !== null) {
            $b = (int) $safe['BANDADISCO'];
            if ($b <= 0) { $safe['BANDADISCO'] = null; }
            elseif (!self::bandaExiste($b)) { return ['code' => 'BANDA_NO_EXISTE']; }
            else { $safe['BANDADISCO'] = $b; }
        }
        // Intro de percusión. El checkbox llega siempre (hay un hidden a 0
        // delante), así que un null aquí significa "no marcado", no "sin dato".
        if (array_key_exists('PERCUSION', $safe)) {
            $safe['PERCUSION'] = ((int) $safe['PERCUSION'] === 1) ? 1 : 0;
        }
        if (array_key_exists('PERCUSION_SEG', $safe)) {
            $seg = (int) $safe['PERCUSION_SEG'];
            if ($seg <= 0) { $seg = 40; }                       // vacío → estimación por defecto
            if ($seg < 5 || $seg > 180) return ['code' => 'INVALID_PERCUSION_SEG'];
            $safe['PERCUSION_SEG'] = $seg;
        }
        return $safe;
    }

    private static function discoExiste(int $id): bool
    {
        return Db::one('SELECT 1 AS x FROM disco WHERE ID_DISCO = ?', [$id]) !== null;
    }

    /**
     * Añade una marcha al disco como una pista.
     *
     * El número de pista NO se autoincrementa ni tiene que ser consecutivo (un
     * disco puede documentarse a trozos, o saltarse pistas que no son marchas),
     * pero sí debe ser único dentro de su volumen: dos pistas 4 en el mismo
     * volumen es siempre un error de captura.
     *
     * @return array{code:string,dmId?:int}
     */
    /**
     * Excepción de percusión por pista: null = hereda del disco (lo normal),
     * 0 = esta pista NO lleva intro aunque el disco sí, 1 = al revés.
     */
    private static function saneaPercusionPista(?int $percusion): ?int
    {
        if ($percusion === null) return null;
        return $percusion === 1 ? 1 : 0;
    }

    public static function addPista(int $idDisco, int $idMarcha, int $numero, int $nDisco = 1, ?int $duracionSeg = null, ?int $percusion = null): array
    {
        if (!self::discoExiste($idDisco)) return ['code' => 'DISCO_NO_EXISTE'];
        if (Db::one('SELECT 1 AS x FROM marcha WHERE ID_MARCHA = ?', [$idMarcha]) === null) {
            return ['code' => 'MARCHA_NO_EXISTE'];
        }
        if ($numero < 1 || $numero > 999) return ['code' => 'PISTA_INVALIDA'];
        if ($nDisco < 1 || $nDisco > 99) return ['code' => 'VOLUMEN_INVALIDO'];
        // R-02: duración de esta grabación en concreto (no la de la obra en
        // `marcha`), opcional — 0 o negativo se trata como "no informada".
        if ($duracionSeg !== null && $duracionSeg <= 0) $duracionSeg = null;

        if (Db::one('SELECT 1 AS x FROM disco_marcha WHERE ID_DISCO = ? AND IDMARCHA = ?', [$idDisco, $idMarcha]) !== null) {
            return ['code' => 'MARCHA_YA_EN_DISCO'];
        }
        if (Db::one('SELECT 1 AS x FROM disco_marcha WHERE ID_DISCO = ? AND N_DISCO = ? AND NUMEROMARCHA = ?', [$idDisco, $nDisco, $numero]) !== null) {
            return ['code' => 'PISTA_OCUPADA'];
        }

        Db::run(
            'INSERT INTO disco_marcha (ID_DISCO, IDMARCHA, NUMEROMARCHA, N_DISCO, DURACION_SEG, PERCUSION) VALUES (?, ?, ?, ?, ?, ?)',
            [$idDisco, $idMarcha, $numero, $nDisco, $duracionSeg, self::saneaPercusionPista($percusion)]
        );
        $dmId = Db::lastInsertId();
        Db::logAdmin('INSERT', 'disco_marcha', $dmId, [
            'disco' => $idDisco, 'marcha' => $idMarcha, 'pista' => $numero, 'volumen' => $nDisco,
        ]);
        return ['code' => 'CREATED', 'dmId' => $dmId];
    }

    /**
     * Edita una pista ya existente: marcha, número, volumen o duración. Misma
     * validación que addPista, pero las comprobaciones de unicidad excluyen
     * la propia fila (si no cambias el número o la marcha, no debe fallar
     * contra sí misma).
     *
     * @return array{code:string}
     */
    public static function updatePista(int $idDisco, int $idDm, int $idMarcha, int $numero, int $nDisco = 1, ?int $duracionSeg = null, ?int $percusion = null): array
    {
        if (Db::one('SELECT 1 AS x FROM disco_marcha WHERE ID_DM = ? AND ID_DISCO = ?', [$idDm, $idDisco]) === null) {
            return ['code' => 'PISTA_NO_EXISTE'];
        }
        if (Db::one('SELECT 1 AS x FROM marcha WHERE ID_MARCHA = ?', [$idMarcha]) === null) {
            return ['code' => 'MARCHA_NO_EXISTE'];
        }
        if ($numero < 1 || $numero > 999) return ['code' => 'PISTA_INVALIDA'];
        if ($nDisco < 1 || $nDisco > 99) return ['code' => 'VOLUMEN_INVALIDO'];
        if ($duracionSeg !== null && $duracionSeg <= 0) $duracionSeg = null;

        if (Db::one('SELECT 1 AS x FROM disco_marcha WHERE ID_DISCO = ? AND IDMARCHA = ? AND ID_DM != ?', [$idDisco, $idMarcha, $idDm]) !== null) {
            return ['code' => 'MARCHA_YA_EN_DISCO'];
        }
        if (Db::one('SELECT 1 AS x FROM disco_marcha WHERE ID_DISCO = ? AND N_DISCO = ? AND NUMEROMARCHA = ? AND ID_DM != ?', [$idDisco, $nDisco, $numero, $idDm]) !== null) {
            return ['code' => 'PISTA_OCUPADA'];
        }

        Db::run(
            'UPDATE disco_marcha SET IDMARCHA = ?, NUMEROMARCHA = ?, N_DISCO = ?, DURACION_SEG = ?, PERCUSION = ? WHERE ID_DM = ?',
            [$idMarcha, $numero, $nDisco, $duracionSeg, self::saneaPercusionPista($percusion), $idDm]
        );
        Db::logAdmin('UPDATE', 'disco_marcha', $idDm, [
            'disco' => $idDisco, 'marcha' => $idMarcha, 'pista' => $numero, 'volumen' => $nDisco,
        ]);
        return ['code' => 'UPDATED'];
    }

    /** @return array{code:string} */
    public static function deletePista(int $idDisco, int $idDm): array
    {
        $fila = Db::one('SELECT ID_DM, IDMARCHA FROM disco_marcha WHERE ID_DM = ? AND ID_DISCO = ?', [$idDm, $idDisco]);
        if ($fila === null) return ['code' => 'PISTA_NO_EXISTE'];
        Db::run('DELETE FROM disco_marcha WHERE ID_DM = ?', [$idDm]);
        Db::logAdmin('DELETE', 'disco_marcha', $idDm, ['disco' => $idDisco, 'marcha' => (int) $fila['IDMARCHA']]);
        return ['code' => 'DELETED'];
    }

    /**
     * Disco + sus pistas, para el formulario de edición y su vista previa.
     *
     * @return array{disco:array<string,mixed>,pistas:list<array<string,mixed>>}|null
     */
    public static function discoConPistas(int $idDisco): ?array
    {
        // VOLUMENES se deriva de las pistas, igual que hace Repo para la ficha
        // pública: la columna disco.DISCOS no se lee en ningún sitio.
        $disco = Db::one(
            'SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, d.BANDADISCO, d.d_DETALLES,
                    d.PERCUSION, d.PERCUSION_SEG,
                    b.NOMBRE_BREVE AS BANDA_BREVE,
                    (SELECT MAX(dm.N_DISCO) FROM disco_marcha dm WHERE dm.ID_DISCO = d.ID_DISCO) AS VOLUMENES
             FROM disco d
             LEFT OUTER JOIN banda b ON b.ID_BANDA = d.BANDADISCO
             WHERE d.ID_DISCO = ?',
            [$idDisco]
        );
        if ($disco === null) return null;

        $pistas = Db::all(
            "SELECT dm.ID_DM, dm.IDMARCHA, dm.NUMEROMARCHA, dm.N_DISCO, dm.DURACION_SEG,
                    dm.PERCUSION AS PERCUSION_PISTA,
                    m.TITULO, m.FECHA,
                    (SELECT GROUP_CONCAT(a.NOMBRE || ' ' || a.APELLIDOS, ', ')
                       FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
                      WHERE ma.ID_MARCHA = m.ID_MARCHA) AS AUTORES
             FROM disco_marcha dm
             LEFT OUTER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
             WHERE dm.ID_DISCO = ?
             ORDER BY dm.N_DISCO ASC, dm.NUMEROMARCHA ASC",
            [$idDisco]
        );
        return ['disco' => $disco, 'pistas' => $pistas];
    }

    /**
     * Discos que casan con el texto, para el buscador del panel. Igual que con
     * las marchas, un número se prueba primero como ID.
     *
     * @return list<array<string,mixed>>
     */
    public static function discoCandidatosPorTexto(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if ($q === '') return [];

        $sel = 'SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD,
                       (SELECT COUNT(*) FROM disco_marcha dm WHERE dm.ID_DISCO = d.ID_DISCO) AS PISTAS
                FROM disco d';
        $out = [];
        if (ctype_digit($q)) {
            $porId = Db::one("$sel WHERE d.ID_DISCO = ?", [(int) $q]);
            if ($porId !== null) $out[] = $porId;
        }
        $tokens = preg_split('/\s+/u', Db::noAcc($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens !== []) {
            $cond = [];
            $vals = [];
            foreach ($tokens as $t) {
                $cond[] = 'NOACC(d.NOMBRE_CD) LIKE ?';
                $vals[] = '%' . $t . '%';
            }
            $rows = Db::all("$sel WHERE " . implode(' AND ', $cond) . ' ORDER BY d.NOMBRE_CD ASC LIMIT ?', [...$vals, $limit]);
            $vistos = array_column($out, 'ID_DISCO');
            foreach ($rows as $r) {
                if (!in_array($r['ID_DISCO'], $vistos, true)) $out[] = $r;
            }
        }
        return array_slice($out, 0, $limit);
    }

    /**
     * Candidatas para añadir como pista. Acepta el ID exacto o trozos del
     * título: en el panel se busca de las dos formas según lo que se tenga a
     * mano (el número que trae la carátula, o el nombre a medias).
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Asocia un candidato pendiente a una marcha ya existente en lugar de
     * crear una nueva: guarda el enlace de origen (igual que aceptarCandidato)
     * y marca el candidato como aceptado.
     *
     * @return array{code:string, marchaId?:int}
     */
    public static function asociarCandidato(int $idCand, int $marchaId, bool $guardarOrigen = true): array
    {
        $cand = Db::one('SELECT ESTADO, FUENTE, VIDEO_URL, ID_BANDA, ISRC FROM ingest_candidato WHERE ID_CAND = ?', [$idCand]);
        if ($cand === null) return ['code' => 'NOT_FOUND'];
        if ($cand['ESTADO'] !== 'pendiente') return ['code' => 'NOT_PENDING'];

        $marcha = Db::one('SELECT ID_MARCHA FROM marcha WHERE ID_MARCHA = ?', [$marchaId]);
        if ($marcha === null) return ['code' => 'MARCHA_NOT_FOUND'];

        if ($guardarOrigen && !empty($cand['VIDEO_URL'])) {
            $fuente = (string) ($cand['FUENTE'] ?? 'youtube');
            if ($fuente === 'youtube') {
                self::editMarcha($marchaId, ['AUDIO'], [$cand['VIDEO_URL']]);
            } elseif (in_array($fuente, EnlaceRepo::SERVICIOS, true)) {
                self::setEnlaceStreaming('marcha', $marchaId, $fuente, (string) $cand['VIDEO_URL'], $cand['ISRC'] ?? null);
            }
        }

        Db::run(
            "UPDATE ingest_candidato SET ESTADO = 'aceptado', MARCHA_CREADA = ?, REVIEWED_AT = datetime('now') WHERE ID_CAND = ?",
            [$marchaId, $idCand]
        );
        Db::logAdmin('ASSOCIATE', 'ingest_candidato', $idCand, ['marchaId' => $marchaId]);

        // Reevaluar otros candidatos de la misma banda por si hay duplicados
        $marcha2 = Db::one('SELECT TITULO, BANDA_ESTRENO FROM marcha WHERE ID_MARCHA = ?', [$marchaId]);
        if ($marcha2 !== null) {
            IngestaRepo::reevaluarTrasCrearMarcha(
                $marchaId,
                $marcha2['BANDA_ESTRENO'] !== null ? (int) $marcha2['BANDA_ESTRENO'] : null,
                (string) $marcha2['TITULO'],
                $idCand,
                $cand['ID_BANDA'] !== null ? (int) $cand['ID_BANDA'] : null
            );
        }

        return ['code' => 'ASSOCIATED', 'marchaId' => $marchaId];
    }

    public static function marchaCandidatosPorTexto(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if ($q === '') return [];

        // AUDIO viaja en la respuesta para que "asociar a marcha existente" (panel
        // de ingesta) pueda avisar si la marcha ya tiene audio insertado, con
        // enlace para escucharlo, antes de que el revisor lo pise sin querer.
        $sel = "SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.AUDIO,
                       (SELECT GROUP_CONCAT(a.NOMBRE || ' ' || a.APELLIDOS, ', ')
                          FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
                         WHERE ma.ID_MARCHA = m.ID_MARCHA) AS AUTORES,
                       b.NOMBRE_BREVE AS BANDA_ESTRENO_NOMBRE
                FROM marcha m
                LEFT JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO";

        // Un número puede ser tanto el ID como parte del título ("Saeta 3"):
        // se busca por ID primero y se completa con las coincidencias de texto.
        $out = [];
        if (ctype_digit($q)) {
            $porId = Db::one("$sel WHERE m.ID_MARCHA = ?", [(int) $q]);
            if ($porId !== null) $out[] = $porId;
        }

        $tokens = preg_split('/\s+/u', Db::noAcc($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens !== []) {
            $cond = [];
            $vals = [];
            foreach ($tokens as $t) {
                $cond[] = 'NOACC(m.TITULO) LIKE ?';
                $vals[] = '%' . $t . '%';
            }
            $rows = Db::all(
                "$sel WHERE " . implode(' AND ', $cond) . ' ORDER BY m.TITULO ASC LIMIT ?',
                [...$vals, $limit]
            );
            $vistos = array_column($out, 'ID_MARCHA');
            foreach ($rows as $r) {
                if (!in_array($r['ID_MARCHA'], $vistos, true)) $out[] = $r;
            }
        }
        return array_slice($out, 0, $limit);
    }
}
