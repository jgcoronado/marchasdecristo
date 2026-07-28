<?php

declare(strict_types=1);

// Autoload PSR-4 mínimo para el namespace App\ (sin composer).
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4));
    $file = APP_DIR . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$GLOBALS['config'] = $config;

if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// Preproducción: además del noindex por página (layout) y del robots.txt en
// Disallow total (Pages::robots), la cabecera cubre TODA respuesta PHP —
// sitemap, API JSON, feeds, llms.txt — para que ningún rastreador indexe el
// host de pruebas aunque llegue por una ruta que no emite HTML.
if (!empty($config['preproduccion'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Modo mantenimiento: scripts/sync_db_to_prod.php crea/borra este centinela
// (mismo directorio que el .db, sin config nueva) mientras reemplaza el
// fichero por FTP. Se comprueba antes que nada más para no leer un .db a
// medio subir ni mostrar un 500 crudo durante la ventana de sync.
$maintenanceFlag = dirname((string) $config['db_path']) . '/.maintenance';
if (is_file($maintenanceFlag)) {
    App\Http::maintenance();
}

// Redirección al host canónico (staging/www → marchasdecristo.com). Solo si está
// activado en config (force_canonical_host) — normalmente tras el cutover.
$canonicalTarget = App\Http::canonicalRedirectTarget(
    $config,
    $_SERVER['HTTP_HOST'] ?? '',
    $_SERVER['REQUEST_URI'] ?? '/'
);
if ($canonicalTarget !== null) {
    header('Location: ' . $canonicalTarget, true, 301);
    exit;
}

$router = new App\Router();
require APP_DIR . '/routes.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
