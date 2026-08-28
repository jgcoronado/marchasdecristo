<?php

declare(strict_types=1);

/*
 * Arranque común de los runners de pruebas que necesitan la app y una BD, pero
 * no un servidor (ci_importar.php, ci_enlaces_auto.php).
 *
 * bootstrap.php no sirve aquí porque despacha el router al final; esto replica
 * solo lo necesario: constantes de rutas, autoload de App\ y config apuntando a
 * una copia desechable de la fixture de CI, en modo local para poder escribir.
 */

/** @return string ruta de la BD de pruebas ya construida */
function ciBoot(?string $dbPath = null): string
{
    if (!defined('BASE_DIR')) {
        define('BASE_DIR', dirname(__DIR__));
        define('APP_DIR', BASE_DIR . '/app');
        define('DATA_DIR', BASE_DIR . '/data');
    }

    $dbPath ??= sys_get_temp_dir() . '/ci-' . getmypid() . '.db';

    // La fixture de CI es un script standalone que lee $argv[1]; requerido desde
    // dentro de esta función, ve el $argv local y no ensucia el ámbito global.
    $argv = ['ci_fixture.php', $dbPath];
    ob_start();
    require __DIR__ . '/ci_fixture.php';
    ob_end_clean();

    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) return;
        $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    });

    $GLOBALS['config'] = [
        'db_path' => $dbPath,
        'env' => 'local',                  // habilita las escrituras (Db::assertWritable)
        'debug' => true,
        'secret_key' => str_repeat('x', 48),
        // Sin credenciales a propósito: alguna prueba comprueba justo ese aviso.
        'spotify_client_id' => '',
        'spotify_client_secret' => '',
    ];

    return $dbPath;
}

/** Borra la BD de pruebas y sus ficheros auxiliares de WAL. */
function ciLimpia(string $dbPath): void
{
    foreach (['', '-shm', '-wal'] as $sufijo) {
        @unlink($dbPath . $sufijo);
    }
}

// ── Aserciones compartidas ───────────────────────────────────────────────────

function assertIgual(mixed $esperado, mixed $obtenido, string $que): void
{
    if ($esperado !== $obtenido) {
        throw new RuntimeException("$que → esperado " . var_export($esperado, true)
            . ', obtenido ' . var_export($obtenido, true));
    }
}

function assertCierto(bool $cond, string $que): void
{
    if (!$cond) throw new RuntimeException($que);
}

/**
 * Ejecuta el mapa de pruebas e informa como ci_smoke.php.
 *
 * @param array<string,callable> $tests
 */
function ciEjecuta(array $tests): int
{
    $failed = [];
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "  OK   $name\n";
        } catch (Throwable $e) {
            $failed[] = "$name: {$e->getMessage()}";
            echo "  FAIL $name — {$e->getMessage()}\n";
        }
    }
    echo "\n" . (count($tests) - count($failed)) . '/' . count($tests) . " pruebas superadas.\n";
    if ($failed !== []) {
        fwrite(STDERR, "\nFallos:\n" . implode("\n", $failed) . "\n");
        return 1;
    }
    return 0;
}
