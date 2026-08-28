<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Tarjetas sociales (og:image) dinámicas por entidad (M4). Ruta
 * /og/{tipo}/{id}.png. Replica el diseño de la og:image estática de marca
 * (fondo índigo noche, filete acento, serif) pero con el título, subtítulo y
 * tipo de la entidad. Se genera con GD/FreeType y se cachea a disco (una vez
 * por combinación de contenido); las siguientes peticiones sirven el fichero.
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
            || !is_file(self::font('serif-bold'))
            || @imagettfbbox(20, 0, self::font('serif-bold'), 'Aáñ') === false) {
            self::fallback();
        }

        $hash = substr(sha1($tipo . '|' . $id . '|' . $datos['overline'] . '|' . $datos['titulo'] . '|' . $datos['sub']), 0, 10);
        $cacheDir = dirname((string) ($GLOBALS['config']['db_path'] ?? '')) . '/og-cache';
        $cacheFile = $cacheDir . '/' . $tipo . '-' . $id . '-' . $hash . '.png';

        if (is_file($cacheFile)) {
            self::serveFile($cacheFile);
        }

        try {
            $png = self::generar($datos);
        } catch (Throwable) {
            self::fallback();
        }

        // Cachea best-effort (fallo de escritura no impide servir la respuesta).
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile, $png);
        }

        self::serveBytes($png);
    }

    private static function font(string $which): string
    {
        $dir = APP_DIR . '/fonts/';
        return match ($which) {
            'serif-bold'   => $dir . 'IBMPlexSerif-Bold.ttf',
            'serif-italic' => $dir . 'IBMPlexSerif-Italic.ttf',
            'mono'         => $dir . 'IBMPlexMono-Regular.ttf',
            default        => $dir . 'IBMPlexSerif-Bold.ttf',
        };
    }

    /**
     * @param array{overline:string,titulo:string,sub:string} $d
     * @return string  bytes PNG
     */
    private static function generar(array $d): string
    {
        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        // Paleta (tokens del tema oscuro de app.css).
        $bg     = imagecolorallocate($img, 0x12, 0x14, 0x1d);
        $ink    = imagecolorallocate($img, 0xe7, 0xea, 0xf4);
        $muted  = imagecolorallocate($img, 0xaa, 0xb3, 0xca);
        $faint  = imagecolorallocate($img, 0x6a, 0x74, 0x88);
        $acc    = imagecolorallocate($img, 0x55, 0x66, 0xb0);

        imagefilledrectangle($img, 0, 0, self::W, self::H, $bg);

        $serifBold   = self::font('serif-bold');
        $serifItalic = self::font('serif-italic');
        $mono        = self::font('mono');
        $cx = intdiv(self::W, 2);
        $maxW = self::W - 200; // márgenes de 100 px

        // Título: 1–2 líneas, reduce el tamaño si son 2.
        $titSize = 62;
        $lines = self::wrap($serifBold, $titSize, (string) $d['titulo'], $maxW, 2);
        if (count($lines) > 1) {
            $titSize = 52;
            $lines = self::wrap($serifBold, $titSize, (string) $d['titulo'], $maxW, 2);
        }
        $lineH = (int) round($titSize * 1.16);

        // Altura del bloque central (sobretítulo + filete + título + subtítulo).
        $overSize = 19;
        $subSize  = 31;
        $gOver = 18; $gRule = 40; $gSub = 30;
        $ruleH = 4;
        $sub = trim((string) $d['sub']);
        $block = $overSize + $gOver + $ruleH + $gRule + (count($lines) * $lineH)
            + ($sub !== '' ? $gSub + $subSize : 0);
        $y = intdiv(self::H - $block, 2) - 12; // leve sesgo hacia arriba (pie al fondo)

        // Sobretítulo (mono, versalitas, con tracking).
        self::tracked($img, $mono, $overSize, mb_strtoupper((string) $d['overline'], 'UTF-8'), $cx, $y, 6, $faint);
        $y += $overSize + $gOver;

        // Filete acento.
        imagefilledrectangle($img, $cx - 45, $y, $cx + 45, $y + $ruleH, $acc);
        $y += $ruleH + $gRule;

        // Título.
        foreach ($lines as $line) {
            self::centered($img, $serifBold, $titSize, $line, $cx, $y, $ink);
            $y += $lineH;
        }

        // Subtítulo (serif itálico).
        if ($sub !== '') {
            $y += $gSub;
            $sub = self::ellipsize($serifItalic, $subSize, $sub, $maxW);
            self::centered($img, $serifItalic, $subSize, $sub, $cx, $y, $muted);
        }

        // Pie: filete + dominio (mono, tracking), anclado abajo.
        $footY = self::H - 82;
        imagefilledrectangle($img, $cx - 45, $footY, $cx + 45, $footY + $ruleH, $acc);
        self::tracked($img, $mono, 18, 'MARCHASDECRISTO.COM', $cx, $footY + 26, 6, $faint);

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /** Dibuja texto centrado horizontalmente; $topY es el borde superior. */
    private static function centered($img, string $font, int $size, string $text, int $cx, int $topY, int $color): void
    {
        $bbox = imagettfbbox($size, 0, $font, $text);
        $w = $bbox[2] - $bbox[0];
        $ascent = -$bbox[7];
        imagettftext($img, $size, 0, $cx - intdiv($w, 2), $topY + $ascent, $color, $font, $text);
    }

    /** Texto centrado con tracking (espaciado entre caracteres); mono. */
    private static function tracked($img, string $font, int $size, string $text, int $cx, int $topY, int $track, int $color): void
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Avance por carácter (mono → constante): ancho de "MM" menos "M".
        $b1 = imagettfbbox($size, 0, $font, 'M');
        $b2 = imagettfbbox($size, 0, $font, 'MM');
        $adv = ($b2[2] - $b2[0]) - ($b1[2] - $b1[0]);
        if ($adv <= 0) {
            $adv = $b1[2] - $b1[0];
        }
        $total = count($chars) * $adv + (count($chars) - 1) * $track;
        $ascent = -$b1[7];
        $x = $cx - intdiv($total, 2);
        $baseY = $topY + $ascent;
        foreach ($chars as $ch) {
            imagettftext($img, $size, 0, $x, $baseY, $color, $font, $ch);
            $x += $adv + $track;
        }
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
        header('Content-Type: image/png');
        Http::cachePublic(604800); // 7 días (el nombre incluye hash de contenido)
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    private static function serveBytes(string $bytes): never
    {
        header('Content-Type: image/png');
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
