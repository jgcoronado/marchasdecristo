<?php

declare(strict_types=1);

namespace App;

/**
 * Tracklist de un álbum a partir de su enlace público de streaming.
 *
 * Es la puerta de entrada del alta asistida de pistas (panel de disco →
 * «Importar pistas»): el usuario pega el enlace del álbum que la banda publica
 * en sus redes y de ahí salen los títulos, el orden y la duración de cada
 * corte. Se admiten los tres servicios que devuelven tracklist completo con
 * duración y sin negociar permisos:
 *
 *   · Spotify — API oficial, exige credenciales (client-credentials).
 *   · Apple Music — `itunes.apple.com/lookup`, sin credenciales.
 *   · Deezer — `api.deezer.com`, sin credenciales.
 *
 * Lo que NO entra aquí a propósito: enlaces de Instagram/Facebook/X. Un post no
 * publica el listado en ningún formato estable (suele ser una imagen de la
 * contraportada) y su HTML público está tras un muro de sesión, así que no hay
 * nada fiable que leer.
 *
 * Las llamadas HTTP y el parseo de cada servicio NO se reimplementan aquí: se
 * reutilizan las de `app/tools/lib/music_match.php`, que es el mismo código que
 * usan `fill_duraciones.php` y `fill_enlaces_odesli.php`. Duplicarlo sería
 * abrir una segunda interpretación del mismo catálogo, que es justo lo que esa
 * librería se extrajo para evitar.
 */
final class Tracklist
{
    /** Servicios que sabemos leer, por orden de preferencia al detectar. */
    public const SERVICIOS = ['spotify', 'apple', 'deezer'];

    /**
     * Punto de inyección para las pruebas: si es un callable, sustituye a la
     * llamada de red. Firma: fn(string $servicio, string $id): list<array>.
     *
     * @var callable|null
     */
    public static $fetcher = null;

    /**
     * Identifica el álbum dentro de una URL de streaming.
     *
     * Mismos patrones que `Media::embedDeUrl` (que resuelve reproductores),
     * pero quedándose con el id nativo, que es lo que piden las APIs. Solo
     * álbumes: un enlace de pista suelta no describe un disco.
     *
     * @return array{servicio:string,id:string}|null
     */
    public static function parseUrl(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') return null;

        // Spotify: open.spotify.com/album/<id>, con posible /intl-xx/ delante.
        if (preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?album/([A-Za-z0-9]+)~i', $url, $m) === 1) {
            return ['servicio' => 'spotify', 'id' => $m[1]];
        }
        // Deezer: deezer.com/<idioma>/album/<id> (el idioma es opcional).
        if (preg_match('~deezer\.com/(?:[a-z]{2}/)?album/(\d+)~i', $url, $m) === 1) {
            return ['servicio' => 'deezer', 'id' => $m[1]];
        }
        // Apple Music: music.apple.com/<pais>/album/<slug>/<idAlbum>. Si trae
        // ?i=<idPista> es el enlace de una pista concreta, pero el álbum al que
        // pertenece sigue siendo el de la ruta, así que vale igual.
        if (preg_match('~music\.apple\.com/[a-z]{2}/album/[^/]*/?(\d+)~i', $url, $m) === 1) {
            return ['servicio' => 'apple', 'id' => $m[1]];
        }
        return null;
    }

    /**
     * Pistas del álbum que hay tras la URL, en el orden que publica el servicio.
     *
     * Cada pista: ['titulo'=>string, 'seg'=>int, 'n'=>?int, 'disco'=>?int, …]
     * (la forma exacta la fija music_match.php, que es quien las construye).
     *
     * @return array{servicio:?string, id:?string, tracks:list<array<string,mixed>>, error:?string}
     */
    public static function de(?string $url): array
    {
        $ref = self::parseUrl($url);
        if ($ref === null) {
            return ['servicio' => null, 'id' => null, 'tracks' => [], 'error' => 'URL_NO_RECONOCIDA'];
        }

        $servicio = $ref['servicio'];
        $id = $ref['id'];

        if (is_callable(self::$fetcher)) {
            $tracks = (self::$fetcher)($servicio, $id);
            return ['servicio' => $servicio, 'id' => $id, 'tracks' => self::ordenar($tracks),
                    'error' => $tracks === [] ? 'SIN_PISTAS' : null];
        }

        if ($servicio === 'spotify' && self::credencialesSpotify() === null) {
            return ['servicio' => $servicio, 'id' => $id, 'tracks' => [], 'error' => 'SPOTIFY_SIN_CREDENCIALES'];
        }

        self::cargarLib();
        $tracks = match ($servicio) {
            'spotify' => tracklistSpotify($id, self::tokenSpotify()),
            'apple'   => tracklistApple($id),
            'deezer'  => tracklistDeezer($id),
            default   => [],
        };

        return ['servicio' => $servicio, 'id' => $id, 'tracks' => self::ordenar($tracks),
                'error' => $tracks === [] ? 'SIN_PISTAS' : null];
    }

    /**
     * Orden de la edición: volumen y, dentro de él, número de pista. Los
     * servicios ya lo devuelven así, pero el importador escribe el número de
     * pista tal cual llega, así que conviene no depender de esa cortesía.
     *
     * @param  list<array<string,mixed>> $tracks
     * @return list<array<string,mixed>>
     */
    private static function ordenar(array $tracks): array
    {
        usort($tracks, static function (array $a, array $b): int {
            $va = (int) ($a['disco'] ?? 1) ?: 1;
            $vb = (int) ($b['disco'] ?? 1) ?: 1;
            if ($va !== $vb) return $va <=> $vb;
            return ((int) ($a['n'] ?? 0)) <=> ((int) ($b['n'] ?? 0));
        });
        return array_values($tracks);
    }

    /** ¿Está el servicio operativo en este host? (Spotify necesita credenciales.) */
    public static function disponible(string $servicio): bool
    {
        if (is_callable(self::$fetcher)) return true;
        return $servicio !== 'spotify' || self::credencialesSpotify() !== null;
    }

    /** @return array{0:string,1:string}|null [clientId, clientSecret] */
    private static function credencialesSpotify(): ?array
    {
        $config = $GLOBALS['config'] ?? [];
        $id = (string) ($config['spotify_client_id'] ?? '');
        $secret = (string) ($config['spotify_client_secret'] ?? '');
        return ($id !== '' && $secret !== '') ? [$id, $secret] : null;
    }

    /** Token de client-credentials, o null si este host no tiene credenciales. */
    public static function tokenSpotify(): ?string
    {
        $cred = self::credencialesSpotify();
        if ($cred === null) return null;
        self::cargarLib();
        return spotifyToken($cred[0], $cred[1]);
    }

    /**
     * Carga la librería compartida de emparejado. Define funciones globales
     * (httpGet, similitud, tracklist*), no ejecuta nada al incluirse, y el
     * guard evita redefinirlas si un script CLI ya la había cargado.
     */
    public static function cargarLib(): void
    {
        if (!function_exists('tracklistSpotify')) {
            require_once APP_DIR . '/tools/lib/music_match.php';
        }
    }

    /**
     * Similitud 0..1 entre el título de una marcha del catálogo y el de una
     * pista del servicio, con el criterio de music_match.php (60% caracteres +
     * 40% palabras, ignorando tildes y sufijos «- En Directo»).
     */
    public static function similitud(string $tituloBd, string $tituloServicio): float
    {
        self::cargarLib();
        return similitud($tituloBd, $tituloServicio);
    }
}
