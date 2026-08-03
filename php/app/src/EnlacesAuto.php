<?php

declare(strict_types=1);

namespace App;

/**
 * Cascada automática de enlaces de streaming a partir del enlace de un DISCO.
 *
 * Al guardar (o importar) el enlace de un álbum, el panel completa solo lo que
 * falte, en tres niveles:
 *
 *   1. DISCO   → el resto de servicios de esa misma publicación (1 llamada a
 *                Odesli/song.link, más repesque por UPC para Apple y Deezer).
 *   2. MARCHAS → tracklist del álbum en Spotify/Apple/Deezer (1 llamada por
 *                servicio) emparejado con las pistas del disco: cada marcha se
 *                queda con su enlace en ese servicio, y de paso se rellena
 *                `disco_marcha.DURACION_SEG` si estaba vacía (R-02).
 *   3. BANDA   → el artista de ese álbum en cada servicio, para la banda
 *                propietaria del disco.
 *
 * Tres reglas que gobiernan todo lo demás:
 *
 * · **Identidad, no búsqueda.** Odesli resuelve la MISMA publicación, el UPC es
 *   el código de barras de la edición y el artista sale del propio álbum. En
 *   ningún momento se busca «una banda que se llame así», que es de donde salían
 *   los falsos positivos del pipeline offline («Los Angeles» ≠ BCT Ángeles).
 * · **Nunca se pisa nada.** Todas las escrituras son «solo si falta»
 *   (`AdminRepo::addEnlaceStreamingSiFalta`), así que un enlace curado a mano
 *   sobrevive y repetir la cascada es idempotente.
 * · **Lo dudoso no se publica.** Por debajo del umbral el enlace va a
 *   `enlace_candidato` y se cura en /dashboard/enlaces, con los mismos tramos de
 *   confianza que `tools/fill_enlaces_odesli.php`.
 *
 * Lo que NO hace, a propósito: Amazon, Tidal y YouTube a nivel de PISTA. No
 * tienen tracklist pública, así que cada pista costaría una llamada a Odesli
 * (~7 s por su rate-limit) y un disco de 12 cortes dejaría la petición web
 * colgada un minuto y medio. Eso sigue siendo trabajo del batch
 * `fill_enlaces_odesli.php`, que ya lo hace con caché y sin prisa.
 */
final class EnlacesAuto
{
    /** Servicios que Odesli sabe resolver a nivel de álbum (spotify es la semilla habitual). */
    public const SERVICIOS_DISCO = ['spotify', 'apple', 'deezer', 'youtube', 'tidal', 'amazon'];

    /** Los únicos con tracklist pública: de aquí salen los enlaces de marcha. */
    public const SERVICIOS_CON_TRACKLIST = ['spotify', 'apple', 'deezer'];

    /** Título del álbum vs. nombre del disco. Por debajo, a curar. */
    public const MIN_SIM_DISCO = 0.55;

    /** Título de la pista vs. título de la marcha (mismo umbral que el batch). */
    public const MIN_SIM_PISTA = 0.85;

    /** Por debajo de la anterior pero por encima de esta: candidato de marcha. */
    public const MIN_SIM_PISTA_CANDIDATA = 0.60;

    /** Nombre del artista vs. nombre de la banda. Un recopilatorio no lo pasa. */
    public const MIN_SIM_BANDA = 0.55;

    /**
     * Presupuesto de segundos para las llamadas de red de una cascada. Al
     * agotarse se deja lo que quede para el batch en vez de arriesgar un timeout
     * del hosting compartido a mitad de escritura.
     */
    public const PRESUPUESTO_SEG = 25;

    /**
     * Punto de inyección para las pruebas: sustituye TODA la red.
     * Firma: fn(string $operacion, array $args): mixed
     *
     * @var callable|null
     */
    public static $red = null;

    private static float $inicio = 0.0;
    private static bool $agotado = false;

    /**
     * Completa lo que falte a partir del enlace ya guardado de este disco.
     *
     * @return array{
     *   disco:array{nuevos:list<string>,candidatos:list<string>,sin:list<string>},
     *   marchas:array{enlaces:int,candidatos:int,duraciones:int,sin_match:int},
     *   banda:array{id:?int,nuevos:list<string>,candidatos:list<string>},
     *   avisos:list<string>
     * }
     */
    public static function paraDisco(int $idDisco): array
    {
        self::$inicio = microtime(true);
        self::$agotado = false;

        $r = [
            'disco' => ['nuevos' => [], 'candidatos' => [], 'sin' => []],
            'marchas' => ['enlaces' => 0, 'candidatos' => 0, 'duraciones' => 0, 'sin_match' => 0],
            'banda' => ['id' => null, 'nuevos' => [], 'candidatos' => []],
            'avisos' => [],
        ];

        $disco = Db::one(
            'SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, d.BANDADISCO,
                    b.NOMBRE_BREVE, b.NOMBRE_COMPLETO
               FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
              WHERE d.ID_DISCO = ?',
            [$idDisco]
        );
        if ($disco === null) {
            $r['avisos'][] = 'El disco no existe.';
            return $r;
        }

        $enlaces = AdminRepo::enlacesConIdExt('disco', $idDisco);
        $idsAlbum = self::idsDeAlbum($enlaces);      // servicio => id nativo
        $semilla = self::semilla($enlaces, $idsAlbum);
        if ($semilla === null) {
            $r['avisos'][] = 'Este disco no tiene ningún enlace de álbum del que partir.';
            return $r;
        }
        $runId = 'panel-' . date('Ymd-His');

        // ── 1. Disco ─────────────────────────────────────────────────────────
        $faltanDisco = array_values(array_diff(self::SERVICIOS_DISCO, array_keys($enlaces)));
        if ($faltanDisco !== []) {
            self::completarDisco($disco, $semilla, $faltanDisco, $idsAlbum, $runId, $r);
        }

        // ── 2. Marchas del disco ─────────────────────────────────────────────
        self::completarMarchas($idDisco, $idsAlbum, $runId, $r);

        // ── 3. Banda propietaria ─────────────────────────────────────────────
        if (!empty($disco['BANDADISCO'])) {
            self::completarBanda($disco, $idsAlbum, $runId, $r);
        }

        if (self::$agotado) {
            $r['avisos'][] = 'Se agotó el tiempo de consulta; lo que falte lo completará la pasada de fill_enlaces_odesli.php.';
        }
        return $r;
    }

    // ── Nivel 1: el propio disco ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $disco
     * @param array{servicio:string,url:string,id:string} $semilla
     * @param list<string> $faltan
     * @param array<string,string> $idsAlbum
     * @param array<string,mixed> $r
     */
    private static function completarDisco(array $disco, array $semilla, array $faltan, array &$idsAlbum, string $runId, array &$r): void
    {
        $idDisco = (int) $disco['ID_DISCO'];
        $nombre = (string) $disco['NOMBRE_CD'];
        $anio = (string) ($disco['FECHA_CD'] ?? '');
        $cubiertos = [];

        // Odesli: una llamada resuelve la publicación en el resto de servicios.
        // Se le pide también el servicio de la semilla, para poder comprobar que
        // ha resuelto NUESTRO álbum y no otro que haya agrupado mal.
        $pedidos = array_values(array_unique([...$faltan, $semilla['servicio']]));
        $od = self::red('odesli', ['url' => $semilla['url']]);
        if (is_array($od)) {
            Tracklist::cargarLib();
            $p = odesliParse($od, $pedidos);
            $sim = similitud($nombre, $p['titulo']);
            $mismo = self::mismoAlbum($p, $semilla);

            foreach ($p['enlaces'] as $servicio => $e) {
                if ($e['id'] !== '' && !isset($idsAlbum[$servicio])) $idsAlbum[$servicio] = $e['id'];
                if (!in_array($servicio, $faltan, true)) continue;   // era solo la comprobación de la semilla
                $cubiertos[] = $servicio;

                if (!$mismo || $sim < self::MIN_SIM_DISCO) {
                    if (AdminRepo::addEnlaceCandidato('disco', $idDisco, $servicio, $e['url'], $e['id'],
                        $p['titulo'], $p['artista'], $anio, $sim, $runId)) {
                        $r['disco']['candidatos'][] = $servicio;
                    }
                    continue;
                }
                if (AdminRepo::addEnlaceStreamingSiFalta('disco', $idDisco, $servicio, $e['url'], $e['id'])) {
                    $r['disco']['nuevos'][] = $servicio;
                }
            }
            if (!$mismo) {
                $r['avisos'][] = 'Odesli ha devuelto otra publicación distinta de la enlazada: sus enlaces han ido a curación, no a la ficha.';
            }
        }

        // Repesque por UPC para Apple y Deezer: Odesli tiene cobertura irregular
        // (en el lote del 2026-08-01 no devolvió ni un enlace de Apple para 10
        // discos) y el código de barras es el mismo número en los tres catálogos.
        $porUpc = array_values(array_intersect(['apple', 'deezer'], array_diff($faltan, $cubiertos)));
        if ($porUpc !== []) {
            $upc = self::upcDelAlbum($idsAlbum);
            foreach ($porUpc as $servicio) {
                if ($upc === '') break;
                $hit = self::red('album_upc', ['servicio' => $servicio, 'upc' => $upc]);
                if (!is_array($hit) || ($hit['url'] ?? '') === '') continue;

                $cubiertos[] = $servicio;
                if (($hit['id'] ?? '') !== '') $idsAlbum[$servicio] = (string) $hit['id'];
                $simU = similitud($nombre, (string) ($hit['titulo'] ?? ''));

                if ($simU < self::MIN_SIM_DISCO) {
                    if (AdminRepo::addEnlaceCandidato('disco', $idDisco, $servicio, (string) $hit['url'], (string) ($hit['id'] ?? ''),
                        (string) ($hit['titulo'] ?? ''), (string) ($hit['artista'] ?? ''), $anio, $simU, $runId)) {
                        $r['disco']['candidatos'][] = $servicio;
                    }
                    continue;
                }
                if (AdminRepo::addEnlaceStreamingSiFalta('disco', $idDisco, $servicio, (string) $hit['url'], (string) ($hit['id'] ?? ''))) {
                    $r['disco']['nuevos'][] = $servicio;
                }
            }
        }

        $r['disco']['sin'] = array_values(array_diff($faltan, $cubiertos));
    }

    /**
     * ¿La respuesta de Odesli describe el álbum que le hemos dado? Si el id que
     * devuelve para el servicio de la semilla no es el nuestro, algo ha agrupado
     * mal (reediciones, recopilatorios) y no se publica nada sin mirarlo.
     *
     * @param array{enlaces:array<string,array{url:string,id:string}>,id_spotify:string} $p
     * @param array{servicio:string,url:string,id:string} $semilla
     */
    private static function mismoAlbum(array $p, array $semilla): bool
    {
        if ($semilla['id'] === '') return true;                 // sin id no hay nada que comparar
        if ($semilla['servicio'] === 'spotify') {
            return $p['id_spotify'] === '' || $p['id_spotify'] === $semilla['id'];
        }
        $suyo = $p['enlaces'][$semilla['servicio']] ?? null;
        if ($suyo === null) return true;                        // Odesli no cubre ese servicio: no se puede comprobar
        return $suyo['id'] === '' || $suyo['id'] === $semilla['id']
            || str_contains($suyo['url'], $semilla['id']);
    }

    /**
     * UPC del álbum, del primer servicio que lo publique. Deezer lo da gratis;
     * Spotify solo con credenciales; Apple no lo publica.
     *
     * @param array<string,string> $idsAlbum
     */
    private static function upcDelAlbum(array $idsAlbum): string
    {
        foreach (['deezer', 'spotify'] as $servicio) {
            if (empty($idsAlbum[$servicio])) continue;
            $info = self::infoAlbum($servicio, $idsAlbum[$servicio]);
            if (($info['upc'] ?? '') !== '') return (string) $info['upc'];
        }
        return '';
    }

    // ── Nivel 2: las marchas del disco ───────────────────────────────────────

    /**
     * @param array<string,string> $idsAlbum
     * @param array<string,mixed> $r
     */
    private static function completarMarchas(int $idDisco, array $idsAlbum, string $runId, array &$r): void
    {
        $pistas = Db::all(
            'SELECT dm.ID_DM, dm.IDMARCHA, dm.DURACION_SEG, m.TITULO
               FROM disco_marcha dm INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
              WHERE dm.ID_DISCO = ?
              ORDER BY dm.N_DISCO, dm.NUMEROMARCHA',
            [$idDisco]
        );
        if ($pistas === []) return;

        // Qué servicios tiene ya cada marcha: una marcha vive en varios discos,
        // así que puede venir enlazada de otro.
        $tiene = [];
        foreach ($pistas as $p) {
            $idM = (int) $p['IDMARCHA'];
            $tiene[$idM] = array_keys(AdminRepo::enlacesConIdExt('marcha', $idM));
        }

        $emparejadas = [];
        $vistos = [];
        foreach (self::SERVICIOS_CON_TRACKLIST as $servicio) {
            if (empty($idsAlbum[$servicio])) continue;
            $tracks = self::red('tracklist', ['servicio' => $servicio, 'id' => $idsAlbum[$servicio]]);
            if (!is_array($tracks) || $tracks === []) continue;

            // R-01: el ISRC identifica la GRABACIÓN. Deezer ya lo trae en el
            // tracklist; Spotify exige una llamada más (50 pistas por llamada).
            if ($servicio === 'spotify') {
                $isrcs = self::red('isrcs', ['ids' => array_column($tracks, 'id')]);
                if (is_array($isrcs)) {
                    foreach ($tracks as $k => $t) {
                        if (isset($isrcs[$t['id'] ?? ''])) $tracks[$k]['isrc'] = $isrcs[$t['id']];
                    }
                }
            }

            Tracklist::cargarLib();
            [$asig, ] = emparejar($pistas, $tracks, self::MIN_SIM_PISTA);
            foreach ($asig as $i => $a) {
                $emparejadas[$i] = true;
                $pista = $pistas[$i];
                $idM = (int) $pista['IDMARCHA'];
                $track = $a['track'];

                // Duración de ESTA grabación, ya que el tracklist está delante.
                // Solo si faltaba: una duración medida a mano no se pisa.
                if ((int) ($pista['DURACION_SEG'] ?? 0) === 0 && (int) ($track['seg'] ?? 0) > 0) {
                    Db::run('UPDATE disco_marcha SET DURACION_SEG = ? WHERE ID_DM = ? AND COALESCE(DURACION_SEG, 0) = 0',
                        [(int) $track['seg'], (int) $pista['ID_DM']]);
                    $pistas[$i]['DURACION_SEG'] = (int) $track['seg'];
                    $r['marchas']['duraciones']++;
                }

                if (($track['url'] ?? '') === '' || in_array($servicio, $tiene[$idM], true)) continue;
                if (AdminRepo::addEnlaceStreamingSiFalta('marcha', $idM, $servicio, (string) $track['url'],
                    (string) ($track['id'] ?? ''), $track['isrc'] ?? null)) {
                    $tiene[$idM][] = $servicio;
                    $r['marchas']['enlaces']++;
                }
            }
            // De qué servicio venía cada corte: se necesita después para encolar
            // el candidato en el servicio correcto (la URL de una PISTA no pasa
            // por Tracklist::parseUrl, que solo entiende álbumes).
            foreach ($tracks as $t) $vistos[] = ['servicio' => $servicio, 'track' => $t];
        }

        // Pistas que ningún servicio ha sabido emparejar. Si hay algo parecido de
        // verdad, se encola para curar; si no, solo se cuenta en el resumen.
        foreach ($pistas as $i => $pista) {
            if (isset($emparejadas[$i]) || $vistos === []) continue;
            $r['marchas']['sin_match']++;

            $mejor = ['score' => 0.0, 'servicio' => '', 'track' => null];
            foreach ($vistos as $v) {
                $s = Tracklist::similitud((string) $pista['TITULO'], (string) ($v['track']['titulo'] ?? ''));
                if ($s > $mejor['score']) $mejor = ['score' => $s, 'servicio' => $v['servicio'], 'track' => $v['track']];
            }
            if ($mejor['track'] === null || $mejor['score'] < self::MIN_SIM_PISTA_CANDIDATA) continue;
            if (($mejor['track']['url'] ?? '') === '') continue;

            if (AdminRepo::addEnlaceCandidato('marcha', (int) $pista['IDMARCHA'], $mejor['servicio'],
                (string) $mejor['track']['url'], (string) ($mejor['track']['id'] ?? ''),
                (string) $mejor['track']['titulo'], null, null, (float) $mejor['score'], $runId)) {
                $r['marchas']['candidatos']++;
            }
        }
    }

    // ── Nivel 3: la banda propietaria ────────────────────────────────────────

    /**
     * @param array<string,mixed> $disco
     * @param array<string,string> $idsAlbum
     * @param array<string,mixed> $r
     */
    private static function completarBanda(array $disco, array $idsAlbum, string $runId, array &$r): void
    {
        $idBanda = (int) $disco['BANDADISCO'];
        $r['banda']['id'] = $idBanda;
        $tiene = array_keys(AdminRepo::enlacesConIdExt('banda', $idBanda));

        foreach (self::SERVICIOS_CON_TRACKLIST as $servicio) {
            if (empty($idsAlbum[$servicio]) || in_array($servicio, $tiene, true)) continue;
            $info = self::infoAlbum($servicio, $idsAlbum[$servicio]);
            $url = (string) ($info['artista_url'] ?? '');
            if ($url === '') continue;

            // El artista sale del álbum (identidad), pero el álbum puede ser un
            // recopilatorio o estar acreditado a otra formación: el nombre tiene
            // que parecerse al de la banda o el enlace va a curación.
            $nombre = (string) ($info['artista'] ?? '');
            $sim = max(
                similitud((string) ($disco['NOMBRE_BREVE'] ?? ''), $nombre),
                similitud((string) ($disco['NOMBRE_COMPLETO'] ?? ''), $nombre)
            );

            if ($sim < self::MIN_SIM_BANDA) {
                if (AdminRepo::addEnlaceCandidato('banda', $idBanda, $servicio, $url, (string) ($info['artista_id'] ?? ''),
                    null, $nombre, null, $sim, $runId)) {
                    $r['banda']['candidatos'][] = $servicio;
                }
                continue;
            }
            if (AdminRepo::addEnlaceStreamingSiFalta('banda', $idBanda, $servicio, $url, (string) ($info['artista_id'] ?? ''))) {
                $r['banda']['nuevos'][] = $servicio;
            }
        }
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    /**
     * Id nativo del álbum en cada servicio: el guardado en ID_EXT si lo hay, y
     * si no el que se pueda sacar de la propia URL.
     *
     * @param  array<string,array{url:string,id_ext:string}> $enlaces
     * @return array<string,string>
     */
    private static function idsDeAlbum(array $enlaces): array
    {
        $out = [];
        foreach ($enlaces as $servicio => $e) {
            if ($e['id_ext'] !== '') { $out[$servicio] = $e['id_ext']; continue; }
            $ref = Tracklist::parseUrl($e['url']);
            if ($ref !== null && $ref['servicio'] === $servicio) $out[$servicio] = $ref['id'];
        }
        return $out;
    }

    /**
     * Enlace del que parte la cascada. Se prefiere Spotify (es el catálogo con
     * mejor cobertura en Odesli), luego Deezer (da UPC gratis) y luego Apple.
     *
     * @param  array<string,array{url:string,id_ext:string}> $enlaces
     * @param  array<string,string> $idsAlbum
     * @return array{servicio:string,url:string,id:string}|null
     */
    private static function semilla(array $enlaces, array $idsAlbum): ?array
    {
        foreach (['spotify', 'deezer', 'apple'] as $servicio) {
            if (!isset($enlaces[$servicio])) continue;
            return ['servicio' => $servicio, 'url' => $enlaces[$servicio]['url'], 'id' => $idsAlbum[$servicio] ?? ''];
        }
        foreach ($enlaces as $servicio => $e) {
            return ['servicio' => (string) $servicio, 'url' => $e['url'], 'id' => $idsAlbum[$servicio] ?? ''];
        }
        return null;
    }

    /**
     * Ficha del álbum en un servicio, memoizada: la piden el repesque por UPC y
     * el nivel de banda, y es la misma llamada.
     *
     * @return array<string,string>
     */
    private static function infoAlbum(string $servicio, string $id): array
    {
        static $cache = [];
        $clave = $servicio . ':' . $id;
        if (!isset($cache[$clave])) {
            $info = self::red('album', ['servicio' => $servicio, 'id' => $id]);
            $cache[$clave] = is_array($info) ? $info : [];
        }
        return $cache[$clave];
    }

    /**
     * Única puerta a la red. Respeta el presupuesto de tiempo y, en pruebas, se
     * sustituye entera con self::$red.
     */
    private static function red(string $operacion, array $args): mixed
    {
        if (is_callable(self::$red)) {
            return (self::$red)($operacion, $args);
        }
        if (self::$agotado) return null;
        if ((microtime(true) - self::$inicio) > self::PRESUPUESTO_SEG) {
            self::$agotado = true;
            return null;
        }
        return self::redReal($operacion, $args);
    }

    private static function redReal(string $operacion, array $args): mixed
    {
        Tracklist::cargarLib();
        $servicio = (string) ($args['servicio'] ?? '');
        $id = (string) ($args['id'] ?? '');

        return match ($operacion) {
            'odesli' => self::odesliLookup((string) $args['url']),
            'tracklist' => match ($servicio) {
                'spotify' => tracklistSpotify($id, Tracklist::tokenSpotify()),
                'apple' => tracklistApple($id),
                'deezer' => tracklistDeezer($id),
                default => [],
            },
            'album' => match ($servicio) {
                'spotify' => spotifyAlbumInfo($id, Tracklist::tokenSpotify()),
                'apple' => appleAlbumInfo($id),
                'deezer' => deezerAlbumInfo($id),
                default => [],
            },
            'album_upc' => match ($servicio) {
                'apple' => albumPorUpcApple((string) $args['upc']),
                'deezer' => albumPorUpcDeezer((string) $args['upc']),
                default => null,
            },
            'isrcs' => isrcsSpotify((array) ($args['ids'] ?? []), Tracklist::tokenSpotify()),
            default => null,
        };
    }

    /**
     * Odesli con la misma caché en disco que usa el batch (`data/odesli_cache/`):
     * lo que uno pregunta le sirve al otro, y eso importa porque la API sin clave
     * admite del orden de 10 peticiones por minuto y por IP.
     *
     * Un solo intento: aquí hay un usuario esperando, y si Odesli no responde el
     * repesque por UPC cubre Apple y Deezer igualmente.
     */
    private static function odesliLookup(string $url): ?array
    {
        if ($url === '') return null;
        $dir = DATA_DIR . '/odesli_cache';
        $fichero = $dir . '/' . sha1($url) . '.json';
        if (is_file($fichero)) {
            $j = json_decode((string) file_get_contents($fichero), true);
            if (is_array($j)) return $j;
        }

        $body = httpGet(
            'https://api.song.link/v1-alpha.1/links?userCountry=ES&songIfSingle=true&url=' . rawurlencode($url),
            ['Accept: application/json'],
            1
        );
        if ($body === null) return null;
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['linksByPlatform'])) return null;

        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($fichero, $body);
        return $json;
    }

    /**
     * Resumen en una línea para el aviso del panel. Devuelve null cuando no hubo
     * nada que añadir: en ese caso no merece la pena decir nada.
     *
     * @param array<string,mixed> $r
     */
    public static function resumen(array $r): ?string
    {
        $partes = [];
        if ($r['disco']['nuevos'] !== []) {
            $partes[] = 'disco: ' . implode(', ', $r['disco']['nuevos']);
        }
        if ($r['marchas']['enlaces'] > 0) {
            $partes[] = $r['marchas']['enlaces'] . ' enlaces de marcha';
        }
        if ($r['marchas']['duraciones'] > 0) {
            $partes[] = $r['marchas']['duraciones'] . ' duraciones';
        }
        if ($r['banda']['nuevos'] !== []) {
            $partes[] = 'banda: ' . implode(', ', $r['banda']['nuevos']);
        }
        $candidatos = count($r['disco']['candidatos']) + $r['marchas']['candidatos'] + count($r['banda']['candidatos']);

        if ($partes === [] && $candidatos === 0) return null;

        $msg = $partes === []
            ? 'Búsqueda automática: nada que publicar'
            : 'Búsqueda automática: ' . implode(' · ', $partes);
        if ($candidatos > 0) {
            $msg .= ' · ' . $candidatos . ($candidatos === 1 ? ' enlace dudoso' : ' enlaces dudosos')
                  . ' a revisar en /dashboard/enlaces';
        }
        return $msg . '.';
    }
}
