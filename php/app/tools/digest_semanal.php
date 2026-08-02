<?php

declare(strict_types=1);

/*
 * Digest semanal de colas editoriales (M7). Envía al admin un resumen con el
 * estado de las tres colas: propuestas pendientes de revisión, candidatos de
 * ingesta de YouTube y candidatos de enlaces de streaming por curar.
 *
 * Ejecutar con cron semanal (p.ej. lunes a las 08:00):
 *   0 8 * * 1 /usr/local/bin/php8.4 /home/USUARIO/app/tools/digest_semanal.php
 *
 * ⚠️ HelioHost: en Scheduled Tasks seleccionar PHP 8.4 explícitamente —
 *    el CLI por defecto es PHP 5.x y falla con `Unsupported declare 'strict_types'`.
 *
 * Requiere en config.local.php:
 *   'mail_from'     => 'noreply@marchasdecristo.com',
 *   'mail_admin_to' => 'admin@ejemplo.com',
 */

define('APP_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(APP_DIR));
define('DATA_DIR', BASE_DIR . '/data');

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$GLOBALS['config'] = $config;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$adminTo = trim((string) ($config['mail_admin_to'] ?? ''));
if ($adminTo === '') {
    echo "digest: mail_admin_to no configurado en config.local.php — omitido\n";
    exit(0);
}

// 1. Propuestas pendientes de revisión (almacenadas como ficheros JSON)
$pendPropuestas = \App\PropuestaRepo::countPendientes();

// 2. Candidatos de ingesta y de enlaces (en la BD SQLite)
$pendIngesta = 0;
$pendEnlaces = 0;
try {
    $pdo = new PDO(
        'sqlite:' . $config['db_path'],
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pendIngesta = (int) $pdo->query(
        "SELECT COUNT(*) FROM ingest_candidato WHERE ESTADO='pendiente'"
    )->fetchColumn();
    $pendEnlaces = (int) $pdo->query(
        "SELECT COUNT(*) FROM enlace_candidato WHERE ESTADO='pendiente'"
    )->fetchColumn();
} catch (Throwable $e) {
    // Las tablas pueden no existir en entornos sin ingesta/enlaces — no es fatal.
    fwrite(STDERR, 'digest: aviso BD — ' . $e->getMessage() . "\n");
}

$fecha     = date('d/m/Y');
$siteUrl   = rtrim((string) ($config['site_url'] ?? 'https://marchasdecristo.com'), '/');
$eSiteUrl  = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');
$hayTrabajo = $pendPropuestas > 0 || $pendIngesta > 0 || $pendEnlaces > 0;

$colorProp = $pendPropuestas > 0 ? '#8a1f1f' : '#1c6b3c';
$colorIng  = $pendIngesta > 0    ? '#8a5a1f' : '#181b24';
$colorEnl  = $pendEnlaces > 0    ? '#8a5a1f' : '#181b24';
$intro     = $hayTrabajo
    ? '<p>Hay elementos pendientes en las colas editoriales.</p>'
    : '<p>No hay elementos pendientes en ninguna cola editorial. ¡Todo al día!</p>';

$html = '<!doctype html><html lang="es"><body style="font-family:sans-serif;color:#181b24;'
    . 'max-width:38rem;margin:2rem auto;padding:0 1rem;line-height:1.55">'
    . '<p style="font-size:.8rem;color:#8890a1;font-family:monospace;margin:0 0 1rem">'
    . 'MARCHAS DE CRISTO · DIGEST SEMANAL</p>'
    . '<h1 style="font-size:1.3rem;border-bottom:2px solid #181b24;padding-bottom:.4rem;margin:0 0 1rem">'
    . 'Resumen editorial — ' . $fecha . '</h1>'
    . $intro
    . '<table style="width:100%;border-collapse:collapse;font-size:.9rem;margin:1rem 0">'
    . '<tr><td style="padding:.5rem 0;border-bottom:1px solid #dce0eb">Propuestas pendientes de revisión</td>'
    . '<td style="padding:.5rem 0;border-bottom:1px solid #dce0eb;text-align:right;font-family:monospace;'
    . 'font-weight:700;color:' . $colorProp . '">' . $pendPropuestas . '</td></tr>'
    . '<tr><td style="padding:.5rem 0;border-bottom:1px solid #dce0eb">Candidatos de ingesta pendientes</td>'
    . '<td style="padding:.5rem 0;border-bottom:1px solid #dce0eb;text-align:right;font-family:monospace;'
    . 'font-weight:700;color:' . $colorIng . '">' . $pendIngesta . '</td></tr>'
    . '<tr><td style="padding:.5rem 0">Candidatos de streaming por curar</td>'
    . '<td style="padding:.5rem 0;text-align:right;font-family:monospace;'
    . 'font-weight:700;color:' . $colorEnl . '">' . $pendEnlaces . '</td></tr>'
    . '</table>'
    . '<p><a href="' . $eSiteUrl . '/dashboard" style="color:#3a4d9e">Ir al panel de administración →</a></p>'
    . '<hr style="border:0;border-top:1px solid #dce0eb;margin:1.5rem 0">'
    . '<p style="font-size:.8rem;color:#8890a1">Marchas de Cristo · '
    . '<a href="' . $eSiteUrl . '" style="color:#3a4d9e">' . $eSiteUrl . '</a></p>'
    . '</body></html>';

$asunto = $hayTrabajo
    ? "Digest semanal — {$pendPropuestas} prop. / {$pendIngesta} ingesta / {$pendEnlaces} enlaces — Marchas de Cristo"
    : 'Digest semanal — todo al día — Marchas de Cristo';

if (\App\Mailer::send($adminTo, $asunto, $html)) {
    echo "digest enviado a {$adminTo} ({$fecha})\n";
} else {
    fwrite(STDERR, "digest: fallo al enviar (comprobar mail_from en config.local.php)\n");
    exit(1);
}
