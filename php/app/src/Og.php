<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Tarjetas sociales (og:image) dinámicas por entidad (M4). Ruta
 * /og/{tipo}/{id}.jpg (y la antigua .png). Tema claro del sitio (papel, tinta
 * e índigo de app.css) y sus mismas fuentes (IBM Plex Serif 600 para el
 * título, IBM Plex Sans para el resto), todo centrado y sin adornos: portada
 * arriba en los discos que la tienen (si no, el tipo en índigo), título, una
 * o dos líneas de datos y el dominio al pie. Se genera con GD/FreeType y se
 * cachea a disco (una vez por combinación de contenido); las siguientes
 * peticiones sirven el fichero.
 *
 * Degrada con elegancia: si falta GD/FreeType o las fuentes, o algo falla,
 * redirige (302) a la og:image estática — así compartir una ficha nunca sale
 * sin imagen aunque el host no tenga FreeType.
 */
final class Og
{
    private const W = 1200;
    private const H = 630;
    private const ALLOWED = ['marcha', 'autor', 'banda', 'disco'];
    /**
     * Versión del diseño de las tarjetas. Entra en la clave de caché: la clave
     * solo dependía de los datos, así que un cambio de diseño seguía sirviendo
     * las tarjetas viejas. Súbela al tocar cómo se pintan.
     */
    private const DISENO = 3;

    public static function render(array $p): void
    {
        $tipo = (string) ($p['tipo'] ?? '');
        if (!in_array($tipo, self::ALLOWED, true)) {
            Http::notFound();
        }
        $id = Slug::extractId((string) ($p['id'] ?? ''));
        if ($id === null) {
            self::fallback();
        }

        try {
            $datos = Repo::ogDatos($tipo, (int) $id);
        } catch (Throwable) {
            $datos = null;
        }
        if ($datos === null) {
            self::fallback(); // entidad inexistente → imagen de marca
        }

        // Requisitos de generación. Sin ellos, imagen estática de marca.
        // El probe con imagettfbbox confirma que GD trae FreeType Y que la
        // fuente es legible: si FreeType falta, imagettfbbox devuelve false sin
        // lanzar excepción (no bastaría un try/catch más abajo).
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettfbbox')
            || !is_file(self::font('serif')) || !is_file(self::font('sans')) || !is_file(self::font('sans-bold'))
            || @imagettfbbox(20, 0, self::font('serif'), 'Aáñ') === false) {
            self::fallback();
        }

        $claveCover = self::claveCover($tipo, (int) $id);
        $hash = self::huella($tipo, (int) $id, $datos, $claveCover);
        $cacheDir = dirname((string) ($GLOBALS['config']['db_path'] ?? '')) . '/og-cache';
        $cacheFile = $cacheDir . '/' . $tipo . '-' . $id . '-' . $hash . '.jpg';

        if (is_file($cacheFile)) {
            self::serveFile($cacheFile);
        }

        try {
            $cover = $claveCover !== '' ? self::portada((int) $id) : null;
            $bytes = self::generar($datos, $cover);
        } catch (Throwable) {
            self::fallback();
        }

        // Cachea best-effort (fallo de escritura no impide servir la respuesta).
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            // Una sola copia por ficha: al cambiar el contenido cambia el hash,
            // y la versión anterior (o el PNG de antes de pasar a JPEG) sobra.
            foreach (glob($cacheDir . '/' . $tipo . '-' . $id . '-*') ?: [] as $viejo) {
                @unlink($viejo);
            }
            @file_put_contents($cacheFile, $bytes);
        }

        self::serveBytes($bytes);
    }

    private static function font(string $which): string
    {
        $dir = APP_DIR . '/fonts/';
        return match ($which) {
            'sans'      => $dir . 'IBMPlexSans-Regular.ttf',
            'sans-bold' => $dir . 'IBMPlexSans-SemiBold.ttf',
            default     => $dir . 'IBMPlexSerif-SemiBold.ttf',
        };
    }

    /**
     * Ruta pública de la tarjeta, con su huella de contenido (?v=). La imagen
     * se sirve con caché de 7 días y la ruta sin versión no cambiaba al cambiar
     * la tarjeta: WhatsApp, navegadores y proxies seguían con la vieja. Con la
     * misma huella que la caché interna, la ruta cambia justo cuando cambia la
     * imagen. Sin datos (entidad sin tarjeta), la ruta sin versión.
     */
    public static function url(string $tipo, int $id): string
    {
        $ruta = '/og/' . $tipo . '/' . $id . '.jpg';
        try {
            $datos = Repo::ogDatos($tipo, $id);
        } catch (Throwable) {
            $datos = null;
        }
        return $datos === null ? $ruta : $ruta . '?v=' . self::huella($tipo, $id, $datos, self::claveCover($tipo, $id));
    }

    /**
     * Huella de la tarjeta: diseño + datos + portada. Nombra el fichero de
     * caché y versiona la ruta pública (url()).
     *
     * @param array{overline:string,titulo:string,lineas:list<string>} $datos
     */
    private static function huella(string $tipo, int $id, array $datos, string $claveCover): string
    {
        return substr(sha1(self::DISENO . '|' . $tipo . '|' . $id . '|' . $datos['overline'] . '|' . $datos['titulo'] . '|' . implode("\n", $datos['lineas']) . '|' . $claveCover), 0, 10);
    }

    /**
     * Disco con portada: la tarjeta la lleva a la izquierda. La portada entra
     * en la huella (fecha y tamaño del fichero), así que subir o cambiar una
     * portada regenera la tarjeta. Si solo se puede descargar (PRE, ver
     * portadaRemota), la huella no la conoce y la tarjeta se renueva solo
     * cuando cambian los textos. '' = sin portada.
     */
    private static function claveCover(string $tipo, int $id): string
    {
        if ($tipo !== 'disco') {
            return '';
        }
        $local = Media::portadaPath($id);
        if (is_file($local)) {
            return 'L' . (string) @filemtime($local) . '-' . (string) @filesize($local);
        }
        return self::coverRemotaBase() !== '' ? 'R' : '';
    }

    /** Origen remoto de portadas (solo PRE: 'cover_base_url'); '' si no hay. */
    private static function coverRemotaBase(): string
    {
        return rtrim((string) ($GLOBALS['config']['cover_base_url'] ?? ''), '/');
    }

    /**
     * Portada del disco como imagen GD: del disco del servidor si está (PRO) o,
     * si no, descargada del origen de portadas (PRE no las tiene en disco).
     * null si no hay portada o no se puede leer: la tarjeta sale sin ella.
     */
    private static function portada(int $idDisco): ?\GdImage
    {
        $local = Media::portadaPath($idDisco);
        $bytes = is_file($local) ? (string) @file_get_contents($local) : self::portadaRemota($idDisco);
        if ($bytes === '') {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        return $img instanceof \GdImage ? $img : null;
    }

    /** Descarga corta (4 s, ≤ 2 MB): la pide un rastreador que no espera mucho. */
    private static function portadaRemota(int $idDisco): string
    {
        $base = self::coverRemotaBase();
        if ($base === '' || !function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init($base . '/cover/' . $idDisco . '.webp');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        // PHP en Windows (local) no trae curl.cainfo: mismo recurso que
        // MalagaBlogImporter, el bundle de CA de Git for Windows.
        if ($body === false && str_contains(curl_error($ch), 'certificate')) {
            foreach (['C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt', 'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt'] as $ca) {
                if (is_file($ca)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca);
                    $body = curl_exec($ch);
                    break;
                }
            }
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!is_string($body) || $code !== 200 || strlen($body) > 2_000_000) {
            return '';
        }
        return $body;
    }

    /**
     * Pinta la tarjeta. Con portada (discos): portada centrada arriba, a
     * 210 px (las portadas miden 200: casi sin ampliar; 170 si el texto no
     * cabe). Sin portada: el tipo en índigo encima del título. Debajo, título
     * (hasta cuatro líneas, se reduce si no cabe), líneas de datos y, anclado
     * al pie, el dominio.
     *
     * @param array{overline:string,titulo:string,lineas:list<string>} $d
     * @return string  bytes JPEG
     */
    private static function generar(array $d, ?\GdImage $cover): string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        // Tokens del tema claro de app.css, con los color-mix resueltos:
        // --bg, --ink, --acc, --muted-c y --faint.
        $bg    = imagecolorallocate($img, 0xf2, 0xf0, 0xea);
        $ink   = imagecolorallocate($img, 0x23, 0x20, 0x19);
        $acc   = imagecolorallocate($img, 0x2e, 0x3a, 0x6e);
        $muted = imagecolorallocate($img, 0x58, 0x5b, 0x6c);
        $faint = imagecolorallocate($img, 0x65, 0x68, 0x77);
        imagefilledrectangle($img, 0, 0, self::W, self::H, $bg);

        $serif    = self::font('serif');
        $sans     = self::font('sans');
        $sansBold = self::font('sans-bold');
        $cx = intdiv(self::W, 2);
        $maxW = self::W - 240; // márgenes de 120 px

        // Datos: cada línea puede partirse en dos (los nombres oficiales de
        // banda son largos y no se abrevian); solo si ni así cabe, «…».
        $metaSize = 26;
        $metaH = 44;
        $meta = [];
        foreach (array_slice($d['lineas'], 0, 2) as $l) {
            array_push($meta, ...self::balance($sans, $metaSize, $l, $maxW, 2));
        }

        // Título y portada: la combinación más grande que quepa entera (título
        // en ≤ 4 líneas sin recortar y bloque dentro del alto útil). Tamaños
        // en puntos (GD a 96 ppp): 1 pt ≈ 1,333 px.
        $alto = self::H - 40 - 96; // margen arriba y zona del pie
        $metaAlto = $meta !== [] ? 20 + count($meta) * $metaH : 0;
        $opciones = [];
        foreach ($cover !== null ? [210, 170] : [0] as $l) {
            foreach ($cover !== null ? [50, 44, 40, 36] : [72, 60, 52, 46, 40, 36] as $t) {
                $opciones[] = [$l, $t];
            }
        }
        $lado = 0; $titSize = 40; $lines = []; $lineH = 0; $cabeza = 0;
        foreach ($opciones as [$lado, $titSize]) {
            $lines = self::balance($serif, $titSize, (string) $d['titulo'], $maxW, 4);
            $lineH = (int) round($titSize * 1.333 * 1.14);
            $cabeza = $cover !== null ? $lado + 36 : 26 + 34;
            if (!str_ends_with((string) end($lines), '…')
                && $cabeza + count($lines) * $lineH + $metaAlto <= $alto) {
                break;
            }
        }

        $block = $cabeza + count($lines) * $lineH + $metaAlto;
        $y = 40 + intdiv($alto - $block, 2);

        if ($cover !== null) {
            // Recorte central al cuadrado (ya lo son, por si acaso).
            $cw = imagesx($cover);
            $ch = imagesy($cover);
            $corte = min($cw, $ch);
            imagecopyresampled($img, $cover, $cx - intdiv($lado, 2), $y, intdiv($cw - $corte, 2), intdiv($ch - $corte, 2), $lado, $lado, $corte, $corte);
        } else {
            self::centered($img, $sansBold, 24, (string) $d['overline'], $cx, $y + 24, $acc);
        }
        $y += $cabeza;

        foreach ($lines as $line) {
            $y += $lineH;
            self::centered($img, $serif, $titSize, $line, $cx, $y - (int) round($titSize * 0.36), $ink);
        }
        if ($meta !== []) {
            $y += 20;
            foreach ($meta as $m) {
                $y += $metaH;
                self::centered($img, $sans, $metaSize, $m, $cx, $y - 12, $muted);
            }
        }

        self::centered($img, $sans, 22, 'marchasdecristo.com', $cx, self::H - 46, $faint);

        ob_start();
        imagejpeg($img, null, 85);
        return (string) ob_get_clean();
    }

    /** Dibuja texto centrado horizontalmente en $cx sobre la línea base $baseY. */
    private static function centered($img, string $font, int $size, string $text, int $cx, int $baseY, int $color): void
    {
        $bbox = imagettfbbox($size, 0, $font, $text);
        $w = $bbox[2] - $bbox[0];
        imagettftext($img, $size, 0, $cx - intdiv($w, 2) - $bbox[0], $baseY, $color, $font, $text);
    }

    /**
     * Parte $text en como mucho $maxLines líneas que quepan en $maxW. Si sobra,
     * la última línea se recorta con «…».
     *
     * Solo mide (imagettfbbox), no dibuja: no necesita el lienzo.
     *
     * @return list<string>
     */
    private static function wrap(string $font, int $size, string $text, int $maxW, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $bb = imagettfbbox($size, 0, $font, $try);
            if (($bb[2] - $bb[0]) <= $maxW || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $w;
                if (count($lines) === $maxLines) {
                    // El resto no cabe: recorta esta línea (que ya es la última+1).
                    break;
                }
            }
        }
        if (count($lines) < $maxLines && $cur !== '') {
            $lines[] = $cur;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        // Si quedaron palabras fuera (título muy largo), marca la última con «…».
        $consumed = implode(' ', $lines);
        if (mb_strlen($consumed) < mb_strlen(trim($text))) {
            $last = array_pop($lines);
            $lines[] = self::ellipsize($font, $size, $last . '…', $maxW);
        }
        return $lines === [] ? [''] : $lines;
    }

    /**
     * Como wrap(), pero con líneas de longitud pareja: estrecha el ancho
     * mientras no aparezca una línea más, para no dejar una palabra huérfana.
     *
     * @return list<string>
     */
    private static function balance(string $font, int $size, string $text, int $maxW, int $maxLines): array
    {
        $lines = self::wrap($font, $size, $text, $maxW, $maxLines);
        $n = count($lines);
        if ($n < 2 || str_ends_with((string) end($lines), '…')) {
            return $lines;
        }
        for ($w = $maxW - 10; $w > intdiv($maxW, 2); $w -= 10) {
            $prueba = self::wrap($font, $size, $text, $w, $maxLines);
            if (count($prueba) > $n || str_ends_with((string) end($prueba), '…')) {
                break;
            }
            $lines = $prueba;
        }
        return $lines;
    }

    /** Recorta $text con «…» hasta que quepa en $maxW. */
    private static function ellipsize(string $font, int $size, string $text, int $maxW): string
    {
        $bb = imagettfbbox($size, 0, $font, $text);
        if (($bb[2] - $bb[0]) <= $maxW) {
            return $text;
        }
        $s = rtrim($text, '…');
        while (mb_strlen($s) > 1) {
            $s = mb_substr($s, 0, mb_strlen($s) - 1);
            $try = rtrim($s) . '…';
            $bb = imagettfbbox($size, 0, $font, $try);
            if (($bb[2] - $bb[0]) <= $maxW) {
                return $try;
            }
        }
        return '…';
    }

    private static function serveFile(string $file): never
    {
        header('Content-Type: image/jpeg');
        Http::cachePublic(604800); // 7 días (el nombre incluye hash de contenido)
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    private static function serveBytes(string $bytes): never
    {
        header('Content-Type: image/jpeg');
        Http::cachePublic(604800);
        header('Content-Length: ' . (string) strlen($bytes));
        echo $bytes;
        exit;
    }

    /** Sin GD / entidad inexistente / error → imagen de marca estática (302). */
    private static function fallback(): never
    {
        Http::redirect('/assets/og-image.png', 302);
    }
}
