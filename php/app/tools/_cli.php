<?php

declare(strict_types=1);

/*
 * Bootstrap común de los scripts CLI de app/tools/.
 *
 * Todos necesitan exactamente lo mismo antes de empezar: las tres constantes de
 * ruta, la configuración, la ruta del .db y abortar si la BD no está. Estaba
 * copiado literalmente en diez scripts (14 líneas × 10); ver
 * docs/code-quality.md §6.2.
 *
 * Uso, al principio de cada script:
 *
 *     require __DIR__ . '/_cli.php';
 *     [, $db] = cliBootstrap('Seed abortado');            // solo la ruta del .db
 *     [$config, $db] = cliBootstrap('Backup abortado');   // si además hace falta la config
 *
 * Devuelve los valores en vez de dejarlos en el scope por efecto lateral del
 * `require`: así el contrato es explícito y el análisis estático sabe que
 * $config y $db existen y de qué tipo son (ver docs/code-quality.md §4.3).
 *
 * Las rutas son SIEMPRE relativas a este fichero: el home de HelioHost está
 * enjaulado y su ruta absoluta no es la que se ve en el panel. __DIR__ es
 * app/tools, así que dirname(__DIR__) es app/ tanto en local como en el host.
 *
 * NO carga el autoload de bootstrap.php a propósito: la mayoría de estos
 * scripts hablan con PDO directamente y los que necesitan clases de App\ las
 * requieren ellos mismos. Añadirlo aquí arrastraría la sesión y el dispatch de
 * una petición web a un proceso de consola.
 */

/**
 * Prepara el entorno de un script de consola y devuelve config + ruta del .db.
 * Aborta con código 1 si la base de datos no existe.
 *
 * @param  string $label Prefijo de los mensajes de error ("Backup abortado", …).
 * @return array{0:array<string,mixed>,1:string} [$config, ruta del .db]
 */
function cliBootstrap(string $label): array
{
    define('APP_DIR', dirname(__DIR__));       // .../app
    define('BASE_DIR', dirname(APP_DIR));      // .../ (home en el host)
    define('DATA_DIR', BASE_DIR . '/data');

    /** @var array<string,mixed> $config */
    $config = require APP_DIR . '/config.php';
    $db = (string) $config['db_path'];

    if (!is_file($db)) {
        fwrite(STDERR, "$label: no existe la BD en $db\n");
        exit(1);
    }

    return [$config, $db];
}
