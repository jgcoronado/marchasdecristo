<?php

declare(strict_types=1);

/*
 * Pruebas de la IP del rate limit de login (Auth::clientIp / Auth::rateKey).
 *
 * Por qué importa: el límite de 6 intentos/15 min protege el login de fuerza
 * bruta. Si la IP se pudiera falsear con una cabecera, el atacante la rota y el
 * límite desaparece — es justo el agujero que había en producción (la app
 * usaba la primera entrada de X-Forwarded-For, escrita por el cliente).
 *
 * Estas pruebas fallan con aquel código: fijan que la clave se ancla en
 * REMOTE_ADDR (capa TCP, no elegible por el cliente) y solo cae a la ÚLTIMA
 * entrada de X-Forwarded-For cuando hay un proxy de confianza delante.
 *
 * Uso: php php/tools/ci_auth.php [ruta .db temporal]
 */

use App\Auth;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

/** Fija $_SERVER a un escenario limpio y devuelve Auth::clientIp(). */
function ipCon(?string $remote, ?string $xff): string
{
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    if ($remote !== null) $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff !== null) $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    return Auth::clientIp();
}

$tests = [];

$tests['IP pública real: se ignora X-Forwarded-For del cliente'] = static function (): void {
    assertIgual('203.0.113.7', ipCon('203.0.113.7', '9.9.9.9, 8.8.8.8'), 'manda REMOTE_ADDR');
    assertIgual('203.0.113.7', ipCon('203.0.113.7', null), 'sin cabecera, igual');
};

$tests['tras proxy local: se toma la ÚLTIMA entrada, no la del cliente'] = static function (): void {
    // La primera la escribe el cliente (falseable), la última la añade el proxy.
    assertIgual('203.0.113.9', ipCon('127.0.0.1', '1.2.3.4, 203.0.113.9'), 'loopback → última entrada');
    assertIgual('203.0.113.9', ipCon('10.0.0.5', '203.0.113.9'), 'IP privada → única entrada');
};

$tests['proxy local sin cabecera: cae a REMOTE_ADDR'] = static function (): void {
    assertIgual('127.0.0.1', ipCon('127.0.0.1', null), 'no hay otra cosa que la IP del proxy');
};

$tests['última entrada inválida: no se usa, cae a REMOTE_ADDR'] = static function (): void {
    assertIgual('10.0.0.5', ipCon('10.0.0.5', '203.0.113.9, no-es-ip'), 'basura → REMOTE_ADDR');
};

$tests['rateKey NO cambia al rotar X-Forwarded-For (el bug arreglado)'] = static function (): void {
    ipCon('203.0.113.7', '10.9.1.1');            // fija REMOTE_ADDR público
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.1.1';
    $k1 = Auth::rateKey('admin');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.2.2';
    $k2 = Auth::rateKey('admin');
    assertIgual($k1, $k2, 'misma IP real y usuario ⇒ misma clave, aunque el atacante rote la cabecera');
    assertCierto(str_starts_with($k1, '203.0.113.7:'), 'la clave se ancla en la IP real');
};

$salida = ciEjecuta($tests);
if ($argc < 2) ciLimpia($dbPath);
exit($salida);
