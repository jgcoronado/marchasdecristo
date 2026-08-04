<?php

declare(strict_types=1);

// Valores por defecto (sin secretos). Los secretos y overrides locales van en
// config.local.php (NO se sube al git). Ver config.local.example.php.

/**
 * Variable del `.env` de la raíz del repo, si existe.
 *
 * Es el fichero que ya usan los scripts de `app/tools/` y `scripts/` (SPOTIFY_*,
 * FTP_*), así que las credenciales que valen para el batch valen también para el
 * panel sin copiarlas en dos sitios. En el hosting ese fichero no existe y esto
 * devuelve null sin más: allí se configura por config.local.php.
 *
 * Orden de precedencia: config.local.php > variable de entorno > .env > vacío.
 */
$envRepo = static function (string $clave): ?string {
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        // BASE_DIR es php/ en local (repo/php) y /home/USER en HelioHost.
        $fichero = dirname(BASE_DIR) . '/.env';
        foreach (is_file($fichero) ? (file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) continue;
            [$k, $v] = explode('=', $linea, 2);
            $vars[trim($k)] = trim($v, " \t'\"");
        }
    }
    return $vars[$clave] ?? null;
};

$defaults = [
    'debug'            => false,
    // Fail-safe: solo 'local' habilita escrituras en la BD desde el panel.
    // Cualquier host que no defina 'env' => 'local' en su config.local.php
    // (staging, producción, o un despliegue mal configurado) queda en
    // modo solo-lectura para el dashboard. Ver Db::assertWritable().
    'env'              => 'production',
    'site_url'         => 'https://marchasdecristo.com',
    'force_canonical_host' => false,          // true tras el cutover → 301 de staging/www a site_url
    'db_path'          => getenv('DB_PATH') ?: (DATA_DIR . '/mdc.db'),
    'secret_key'       => '',                 // Fase 3 (auth) — definir en config.local.php
    'auth_cookie_name' => 'mdc_session',
    'login_ttl_ms'     => 8 * 60 * 60 * 1000, // 8 h
    'cookie_secure'    => false,              // true en producción (o se autodetecta por HTTPS)
    'login_max_attempts' => 6,
    'login_window_ms'    => 15 * 60 * 1000,   // 15 min
    'login_lock_ms'      => 15 * 60 * 1000,   // 15 min
    'password_pbkdf2_iterations' => 210000,
    'backup_keep_days'   => 60,               // retención (tools/backup.php); cron semanal → ~8-9 copias
    'goatcounter_code'   => null,              // subdominio de GoatCounter (p.ej. "marchasdecristo"), null = analítica desactivada
    // Clave de IndexNow (ver routes.php y scripts/sync_db_to_prod.php). Debe
    // ser EXACTAMENTE la misma en el config.local.php de este host (admin, para
    // enviar el ping tras el sync) y en el de producción (para servir el
    // fichero de verificación en /<clave>.txt). null = IndexNow desactivado.
    'indexnow_key'       => null,
    // true SOLO en el host de preproducción (marchasdecristo.jaguerra27.helioho.st):
    // noindex global (meta + X-Robots-Tag), robots.txt en Disallow total y cinta
    // visible «PREPRODUCCIÓN» en todas las páginas. Independiente de 'env' (que
    // controla la escritura en BD): PRE mantiene env=production para tener
    // paridad de solo-lectura con producción. Ver docs/entornos.md.
    // El valor por defecto lo marca el env.php del docroot (ENV_PREPRODUCCION,
    // ver public/index.php), que solo existe en PRE porque lo genera su deploy:
    // así el noindex no depende de que alguien acierte con config.local.php.
    // Las herramientas CLI no pasan por index.php, de ahí el defined().
    'preproduccion'      => defined('ENV_PREPRODUCCION') && ENV_PREPRODUCCION,
    // Secciones de App\Secciones::EN_MADURACION que ESTE host publica pese a
    // no estar aún publicadas en general (lista de slugs, p.ej. ['mapa']). Es
    // el interruptor para enseñar una sección primero en PRE, validarla con
    // datos reales y publicarla luego en PRO sin desplegar código. Vacío = solo
    // se ven en local. Ver App\Secciones y docs/entornos.md.
    'secciones_publicadas' => [],
    // Origen de las portadas. '' = /cover/ del propio host (local y producción,
    // que es donde viven los ficheros). PRE tiene docroot propio y sin portadas,
    // así que apunta al de producción para verlas. Ver docs/entornos.md.
    'cover_base_url'     => '',
    // Credenciales de la API de Spotify (client-credentials, app gratuita en
    // developer.spotify.com). Las necesita el panel cuando el enlace pegado es
    // de Spotify (App\Tracklist) y para el tracklist de Spotify en la cascada
    // (App\EnlacesAuto); Apple Music y Deezer se leen sin credenciales. Vacío =
    // Spotify desactivado, con aviso en pantalla en vez de un fallo opaco.
    //
    // Se leen del MISMO sitio que los scripts de tools/: el `.env` de la raíz
    // del repo. Si ya tienes ahí SPOTIFY_CLIENT_ID/SECRET para el batch, el
    // panel las coge solas y no hay que duplicar nada.
    'spotify_client_id'     => getenv('SPOTIFY_CLIENT_ID') ?: ($envRepo('SPOTIFY_CLIENT_ID') ?? ''),
    'spotify_client_secret' => getenv('SPOTIFY_CLIENT_SECRET') ?: ($envRepo('SPOTIFY_CLIENT_SECRET') ?? ''),
    // M7: notificaciones editoriales por email. Configurar en config.local.php.
    // Si mail_from está vacío, Mailer::send() devuelve false sin intentar nada.
    'mail_from'      => null,               // 'noreply@marchasdecristo.com'
    'mail_from_name' => 'Marchas de Cristo',
    'mail_admin_to'  => null,               // destino del digest semanal
    'notif_emails'   => [],                 // ['nombreusuario' => 'email@ejemplo.com']
];

$localFile = APP_DIR . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

return array_merge($defaults, is_array($local) ? $local : []);
