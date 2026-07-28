<?php

declare(strict_types=1);

namespace App;

/**
 * Utilidades de medios. Por ahora solo YouTube: el campo marcha.AUDIO guarda
 * una URL de vídeo (formato watch?v=, youtu.be/, embed/…). A medio plazo esto
 * se sustituirá por una tabla marcha_audio multi-servicio (ver plan P-02).
 */
final class Media
{
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
