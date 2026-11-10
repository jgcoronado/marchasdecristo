<?php

declare(strict_types=1);

/*
 * Poda de la caché de og:image (private/og-cache). Pensado para cron:
 *
 *   /usr/local/bin/php /home/USUARIO/app/tools/og_prune.php [--dry]
 *
 * Og::render() ya deja UNA tarjeta por ficha (borra la versión anterior al
 * regenerar), así que la caché no crece sin límite: se estabiliza en
 * ~nº de fichas × ~40 KB. Un TTL por antigüedad no sirve (la regeneraría
 * entera en cuanto un rastreador la pidiera). Lo que sí acota el disco es un
 * tope de tamaño: si la caché lo supera, se borran las tarjetas menos
 * recientemente servidas (Og::serveFile toca el mtime en cada acierto) hasta
 * quedar por debajo. Las borradas se regeneran bajo demanda.
 *
 * Tope: config 'og_cache_max_mb' (120 por defecto). También borra los .png
 * heredados de antes de pasar a JPEG.
 */

require __DIR__ . '/_cli.php';
[$config, $db] = cliBootstrap('Poda abortada');

$dry = in_array('--dry', $argv, true);
$dir = dirname($db) . '/og-cache';
$maxBytes = (int) ($config['og_cache_max_mb'] ?? 120) * 1048576;

if (!is_dir($dir)) {
    echo "og-cache: no existe $dir, nada que podar\n";
    exit(0);
}

$files = [];   // ruta => [mtime, tamaño]
$total = 0;
foreach (glob($dir . '/*') ?: [] as $f) {
    if (!is_file($f)) {
        continue;
    }
    $size = (int) filesize($f);
    $files[$f] = [(int) filemtime($f), $size];
    $total += $size;
}

$removed = 0;
$freed = 0;
$borrar = static function (string $f, int $size) use ($dry, &$removed, &$freed, &$total): void {
    if ($dry || @unlink($f)) {
        $removed++;
        $freed += $size;
        $total -= $size;
    }
};

// 1) PNG heredados.
foreach ($files as $f => [, $size]) {
    if (str_ends_with($f, '.png')) {
        $borrar($f, $size);
        unset($files[$f]);
    }
}

// 2) Tope de tamaño: fuera las menos recientes primero.
uasort($files, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
foreach ($files as $f => [, $size]) {
    if ($total <= $maxBytes) {
        break;
    }
    $borrar($f, $size);
}

printf(
    "og-cache%s: %d ficheros borrados (%.1f MB), quedan %.1f MB (tope %d MB)\n",
    $dry ? ' [dry-run]' : '',
    $removed,
    $freed / 1048576,
    $total / 1048576,
    (int) ($maxBytes / 1048576)
);
