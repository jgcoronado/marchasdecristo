<?php

declare(strict_types=1);

/*
 * Pruebas del CSV de /rosario (App\Rosario): hora y lugar de cada levantá
 * («arriba») y arriá («abajo») de un paso.
 *
 * Por qué importa: el CSV es el único resultado de la herramienta y se abre a
 * posteriori en Excel. Si la hora sale en la zona del servidor, si los
 * decimales llevan punto (Excel en español los lee como texto) o si un reenvío
 * de la página duplica una pulsación, el dato queda mal sin que nadie lo vea en
 * el momento. Y una pulsación sin ubicación tiene que guardarse igual: perderla
 * es peor que tenerla sin sitio.
 *
 * Uso: php php/tools/ci_rosario.php [ruta .db temporal]
 */

use App\Rosario;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

/** ms Unix de un instante UTC, como lo manda el teléfono (Date.now()). */
function ms(string $utc): string
{
    return (string) ((new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp() * 1000 + 417);
}

/** Espera que Rosario::linea() rechace los campos. */
function rechaza(array $campos, string $que): void
{
    try {
        Rosario::linea($campos);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException("$que → se aceptó y debía rechazarse");
}

$base = ['accion' => 'arriba', 'ts' => ms('2026-10-10 18:15:32'), 'lat' => '37.3891234', 'lon' => '-5.9844589', 'precision' => '4.66'];

$csv = sys_get_temp_dir() . '/ci-rosario-' . getmypid() . '.csv';
@unlink($csv);

$tests = [];

$tests['hora de Madrid, sea cual sea la zona del servidor (verano e invierno)'] = static function () use ($base): void {
    foreach (['UTC', 'America/New_York', 'Asia/Tokyo'] as $zona) {
        date_default_timezone_set($zona);
        // 18:15:32 UTC en octubre = 20:15:32 CEST; en diciembre, 19:00:05 UTC = 20:00:05 CET.
        assertCierto(str_starts_with(Rosario::linea($base), '10/10/2026;20:15:32;'), "verano con el servidor en $zona");
        $invierno = Rosario::linea(['ts' => ms('2026-12-08 19:00:05')] + $base);
        assertCierto(str_starts_with($invierno, '08/12/2026;20:00:05;'), "invierno con el servidor en $zona");
    }
    date_default_timezone_set('UTC');
};

$tests['formato Excel en español: «;» y coma decimal'] = static function () use ($base): void {
    assertIgual('10/10/2026;20:15:32;arriba;37,389123;-5,984459;4,7', Rosario::linea($base), 'línea completa');
    assertIgual(6, substr_count(Rosario::CABECERA, ';') + 1, 'la cabecera tiene las 6 columnas');
};

$tests['sin ubicación: se guarda la pulsación con las columnas vacías'] = static function () use ($base): void {
    $linea = Rosario::linea(['accion' => 'abajo', 'lat' => '', 'lon' => '', 'precision' => ''] + $base);
    assertIgual('10/10/2026;20:15:32;abajo;;;', $linea, 'hora y acción sin sitio');
};

$tests['rechaza lo que no es una pulsación de la página'] = static function () use ($base): void {
    rechaza(['accion' => 'levantar'] + $base, 'acción fuera de arriba/abajo');
    rechaza(['accion' => null] + $base, 'sin acción');
    rechaza(['ts' => '1760000000'] + $base, 'hora en segundos, no en ms');
    rechaza(['ts' => '17600000000x0'] + $base, 'hora no numérica');
    rechaza(['lat' => '91'] + $base, 'latitud fuera de rango');
    rechaza(['lon' => 'abc'] + $base, 'longitud no numérica');
    rechaza(['lon' => ''] + $base, 'latitud sin longitud');
    rechaza(['precision' => '-1'] + $base, 'precisión negativa');
    rechaza(['lat' => 'NAN'] + $base, 'NaN');
};

$tests['el fichero nace con su cabecera y crece una línea por pulsación'] = static function () use ($base, $csv): void {
    assertCierto(Rosario::anotar($csv, Rosario::linea($base)), 'primera pulsación escrita');
    assertCierto(Rosario::anotar($csv, Rosario::linea(['accion' => 'abajo', 'ts' => ms('2026-10-10 18:17:01')] + $base)), 'segunda escrita');
    assertIgual(
        Rosario::CABECERA . "\r\n"
        . "10/10/2026;20:15:32;arriba;37,389123;-5,984459;4,7\r\n"
        . "10/10/2026;20:17:01;abajo;37,389123;-5,984459;4,7\r\n",
        (string) file_get_contents($csv),
        'contenido'
    );
};

$tests['un reenvío de la página no duplica la línea'] = static function () use ($base, $csv): void {
    $antes = (string) file_get_contents($csv);
    // La página reintenta si no le llega la respuesta, aunque el servidor ya escribiera.
    assertIgual(false, Rosario::anotar($csv, Rosario::linea($base)), 'reenvío detectado');
    assertIgual($antes, (string) file_get_contents($csv), 'el CSV no cambia');
    // Otra pulsación en otro momento sí entra, aunque todo lo demás coincida.
    assertCierto(Rosario::anotar($csv, Rosario::linea(['ts' => ms('2026-10-10 18:20:00')] + $base)), 'otra hora, otra línea');
};

$salida = ciEjecuta($tests);
@unlink($csv);
if (!isset($argv[1])) ciLimpia($dbPath);
exit($salida);
