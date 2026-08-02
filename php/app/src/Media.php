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

    // ── Portadas de disco ───────────────────────────────────────────────────

    /** Lado máximo de la portada guardada. Las fichas la pintan a 16rem. */
    private const PORTADA_LADO = 600;

    /** Tope del fichero subido (los .png de producción rondan los 200 KB). */
    public const PORTADA_MAX_BYTES = 6 * 1024 * 1024;

    /** Directorio donde viven las portadas: public/cover/{ID_DISCO}.png. */
    public static function portadaDir(): string
    {
        return dirname(__DIR__, 2) . '/public/cover';
    }

    public static function portadaPath(int $idDisco): string
    {
        return self::portadaDir() . '/' . $idDisco . '.png';
    }

    public static function portadaExiste(int $idDisco): bool
    {
        return is_file(self::portadaPath($idDisco));
    }

    /**
     * Guarda la portada subida como public/cover/{ID_DISCO}.png.
     *
     * El fichero NO se mueve tal cual: se descodifica con GD y se vuelve a
     * codificar a PNG. Eso normaliza el formato (se aceptan JPEG/PNG/WebP/GIF y
     * todos acaban en .png, que es lo que espera Html::coverSrc) y, de paso,
     * descarta cualquier carga útil incrustada en el fichero original — un
     * .jpg con PHP dentro deja de serlo al reencodificarlo.
     *
     * El tipo se decide por el contenido real (getimagesize), nunca por la
     * extensión ni por el Content-Type que manda el navegador, que el cliente
     * controla.
     *
     * @param  array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file entrada de $_FILES
     * @return string|null código de error, o null si se guardó bien
     */
    public static function guardarPortada(array $file, int $idDisco): ?string
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'PORTADA_DEMASIADO_GRANDE';
        if ($err !== UPLOAD_ERR_OK) return 'PORTADA_SUBIDA_FALLIDA';

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return 'PORTADA_SUBIDA_FALLIDA';
        if ((int) ($file['size'] ?? 0) > self::PORTADA_MAX_BYTES) return 'PORTADA_DEMASIADO_GRANDE';

        $info = @getimagesize($tmp);
        if ($info === false) return 'PORTADA_NO_ES_IMAGEN';
        [$w, $h] = $info;
        if ($w < 50 || $h < 50) return 'PORTADA_DEMASIADO_PEQUENA';
        // Tope de píxeles antes de descodificar: una imagen de 20 000×20 000
        // ocuparía gigabytes en memoria aunque el fichero pese poco.
        if ($w * $h > 50_000_000) return 'PORTADA_DEMASIADO_GRANDE';

        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($src === false) return 'PORTADA_NO_ES_IMAGEN';

        // Cuadrada y con el lado limitado, como el resto del catálogo.
        $lado = min(self::PORTADA_LADO, max($w, $h));
        $dst = imagecreatetruecolor($lado, $lado);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        // Recorte central al cuadrado, sin deformar la imagen original.
        $corte = min($w, $h);
        $ok = imagecopyresampled($dst, $src, 0, 0, (int) (($w - $corte) / 2), (int) (($h - $corte) / 2), $lado, $lado, $corte, $corte);
        imagedestroy($src);
        if (!$ok) { imagedestroy($dst); return 'PORTADA_NO_ES_IMAGEN'; }

        $dir = self::portadaDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) { imagedestroy($dst); return 'PORTADA_DIR_NO_ESCRIBIBLE'; }
        if (!is_writable($dir)) { imagedestroy($dst); return 'PORTADA_DIR_NO_ESCRIBIBLE'; }

        // Escritura atómica: si el proceso muere a medias, la portada anterior
        // sigue intacta en vez de quedar un PNG truncado servido a todo el mundo.
        $tmpOut = $dir . '/.' . $idDisco . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $guardado = imagepng($dst, $tmpOut, 6);
        imagedestroy($dst);
        if (!$guardado) { @unlink($tmpOut); return 'PORTADA_ESCRITURA_FALLIDA'; }
        if (!@rename($tmpOut, self::portadaPath($idDisco))) { @unlink($tmpOut); return 'PORTADA_ESCRITURA_FALLIDA'; }
        @chmod(self::portadaPath($idDisco), 0o644);
        return null;
    }
}
