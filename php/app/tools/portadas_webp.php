<?php

declare(strict_types=1);

/*
 * Convierte las portadas {ID}.png de cover/ a {ID}.webp (lo que sirve ya
 * Html::coverSrc) para ahorrar espacio: ~80 KB → ~10 KB por portada.
 *
 *   php app/tools/portadas_webp.php RUTA/cover              # convierte, deja los .png
 *   php app/tools/portadas_webp.php RUTA/cover --borrar-png # borra cada .png con su .webp válido
 *
 * La ruta se pasa a mano porque en el host el docroot (marchasdecristo.com/) no
 * cuelga de app/. Es idempotente: salta las que ya tienen .webp. Mientras
 * queden .png, el .htaccess sirve el .png al pedir el .webp.
 */

$dir = $argv[1] ?? '';
$borrarPng = in_array('--borrar-png', $argv, true);

if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Uso: php portadas_webp.php RUTA/cover [--borrar-png]\n");
    exit(1);
}
if (!function_exists('imagewebp')) {
    fwrite(STDERR, "Abortado: GD sin soporte WebP en este PHP.\n");
    exit(1);
}

$calidad = 80; // = Media::PORTADA_CALIDAD_WEBP
$convertidas = $saltadas = $borradas = $errores = 0;
$antes = $despues = 0;

foreach (glob(rtrim($dir, '/') . '/*.png') ?: [] as $png) {
    if (!preg_match('/^\d+$/', basename($png, '.png'))) continue;
    $webp = substr($png, 0, -4) . '.webp';

    if (!is_file($webp)) {
        $img = @imagecreatefrompng($png);
        if ($img === false) { fwrite(STDERR, "ERROR leyendo $png\n"); $errores++; continue; }
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $tmp = $webp . '.tmp';
        $ok = imagewebp($img, $tmp, $calidad) && @rename($tmp, $webp);
        imagedestroy($img);
        if (!$ok) { @unlink($tmp); fwrite(STDERR, "ERROR escribiendo $webp\n"); $errores++; continue; }
        @chmod($webp, 0o644);
        $convertidas++;
        $antes += filesize($png);
        $despues += filesize($webp);
    } else {
        $saltadas++;
    }

    // Solo se borra el .png si el .webp se puede leer de vuelta.
    if ($borrarPng && @getimagesize($webp) !== false && @unlink($png)) $borradas++;
}

printf(
    "Convertidas: %d (%.1f MB → %.1f MB) · ya tenían .webp: %d · .png borrados: %d · errores: %d\n",
    $convertidas, $antes / 1048576, $despues / 1048576, $saltadas, $borradas, $errores
);
exit($errores > 0 ? 1 : 0);
