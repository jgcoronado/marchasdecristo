<?php

declare(strict_types=1);

namespace App;

/**
 * Utilidades de medios. `marcha.AUDIO` guarda una URL de vídeo de YouTube,
 * pero desde la ingesta de streaming una marcha puede tener su única escucha
 * en `enlace_streaming` (Spotify/Deezer/Apple), así que la previsualización ya
 * no puede presuponer YouTube: `reproductor()` elige la mejor fuente que haya
 * y `embedDeUrl()` sabe convertir la URL pública de cada servicio en su
 * reproductor incrustable.
 */
final class Media
{
    /**
     * Orden de preferencia al elegir reproductor entre varios servicios.
     * YouTube va primero cuando existe (es vídeo, y es lo que guarda AUDIO);
     * detrás, los que dan reproductor de audio incrustable.
     */
    private const PREFERENCIA = ['youtube', 'spotify', 'deezer', 'apple'];

    /**
     * Reproductor incrustable de una URL pública, o null si el servicio no lo
     * tiene (o la URL no se reconoce). `alto` es la altura en píxeles del
     * reproductor de audio; para el vídeo de YouTube manda la proporción 16:9,
     * así que va a null.
     *
     * @return array{servicio:string, embed:string, url:string, alto:?int, thumb:?string}|null
     */
    public static function embedDeUrl(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') return null;

        $id = self::youtubeId($url);
        if ($id !== null) {
            return ['servicio' => 'youtube', 'embed' => self::youtubeEmbed($id),
                    'url' => $url, 'alto' => null, 'thumb' => self::youtubeThumb($id)];
        }

        // Spotify: open.spotify.com/track|album/<id>, con posible /intl-xx/ delante.
        if (preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?(track|album|episode)/([A-Za-z0-9]+)~i', $url, $m) === 1) {
            return ['servicio' => 'spotify', 'embed' => 'https://open.spotify.com/embed/' . strtolower($m[1]) . '/' . $m[2],
                    'url' => $url, 'alto' => strtolower($m[1]) === 'track' ? 152 : 352, 'thumb' => null];
        }

        // Deezer: deezer.com/<idioma>/track|album/<id>.
        if (preg_match('~deezer\.com/(?:[a-z]{2}/)?(track|album)/(\d+)~i', $url, $m) === 1) {
            return ['servicio' => 'deezer', 'embed' => 'https://widget.deezer.com/widget/auto/' . strtolower($m[1]) . '/' . $m[2],
                    'url' => $url, 'alto' => strtolower($m[1]) === 'track' ? 152 : 300, 'thumb' => null];
        }

        // Apple Music: la pista se enlaza como .../album/<slug>/<idAlbum>?i=<idPista>,
        // y su reproductor es el del álbum posicionado en esa pista.
        if (preg_match('~music\.apple\.com/([a-z]{2})/album/[^/]*/?(\d+)~i', $url, $m) === 1) {
            $embed = 'https://embed.music.apple.com/' . strtolower($m[1]) . '/album/' . $m[2];
            $esPista = preg_match('~[?&]i=(\d+)~', $url, $p) === 1;
            if ($esPista) $embed .= '?i=' . $p[1];
            return ['servicio' => 'apple', 'embed' => $embed, 'url' => $url,
                    'alto' => $esPista ? 175 : 450, 'thumb' => null];
        }

        return null;
    }

    /**
     * Mejor previsualización disponible de una marcha: primero su AUDIO (que
     * hoy es siempre YouTube, pero puede ser cualquier servicio reconocible) y
     * si no da reproductor, sus enlaces publicados de streaming por orden de
     * preferencia. Null = no hay nada que incrustar (solo enlaces externos).
     *
     * @param array<string,string> $enlaces  [servicio => url], ver EnlaceRepo::publicadosDe
     * @return array{servicio:string, embed:string, url:string, alto:?int, thumb:?string}|null
     */
    public static function reproductor(?string $audio, array $enlaces = []): ?array
    {
        $delAudio = self::embedDeUrl($audio);
        if ($delAudio !== null) return $delAudio;

        foreach (self::PREFERENCIA as $servicio) {
            if (!isset($enlaces[$servicio])) continue;
            $r = self::embedDeUrl($enlaces[$servicio]);
            if ($r !== null) return $r;
        }
        return null;
    }

    /**
     * Extrae el ID de 11 caracteres de una URL de YouTube, o null si la cadena
     * no es una URL de YouTube reconocible (p.ej. texto suelto o un enlace de
     * otro servicio). Cubre www./m./youtube-nocookie.com, youtu.be y las rutas
     * watch / embed / shorts / v, con parámetros extra en cualquier orden.
     */
    public static function youtubeId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $re = '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~';
        return preg_match($re, $url, $m) === 1 ? $m[1] : null;
    }

    /**
     * Miniatura del vídeo. hqdefault siempre existe; en un contenedor 16:9 con
     * object-fit: cover se recortan las bandas negras del 4:3 original.
     */
    public static function youtubeThumb(string $id): string
    {
        return 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    }

    /**
     * URL de incrustación sin cookies (youtube-nocookie). No se carga hasta que
     * el usuario pulsa la fachada, así que ninguna cookie de terceros se envía
     * en la primera visita.
     */
    public static function youtubeEmbed(string $id): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . $id;
    }
}
