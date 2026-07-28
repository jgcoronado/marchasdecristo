<?php

declare(strict_types=1);

// Valores por defecto (sin secretos). Los secretos y overrides locales van en
// config.local.php (NO se sube al git). Ver config.local.example.php.
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
    'preproduccion'      => false,
    // Origen de las portadas. '' = /cover/ del propio host (local y producción,
    // que es donde viven los ficheros). PRE tiene docroot propio y sin portadas,
    // así que apunta al de producción para verlas. Ver docs/entornos.md.
    'cover_base_url'     => '',
];

$localFile = APP_DIR . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

return array_merge($defaults, is_array($local) ? $local : []);
