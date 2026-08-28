<?php

declare(strict_types=1);

/*
 * Sincroniza el .db local con producción (HelioHost/Plesk) por FTP, con
 * guardarraíles obligatorios:
 *   1. Solo sube si hay un backup de producción con menos de N días
 *      (10 por defecto) en private/backups/. Si no lo hay, para sin tocar
 *      nada y avisa de que hace falta lanzar un backup primero.
 *   2. Aborta si hay propuestas de editores pendientes en producción que
 *      aún no se hayan bajado (sync_propuestas_from_prod.php): subir el .db
 *      local las pisaría sin que el admin las haya visto nunca.
 *   3. Activa un centinela de mantenimiento (private/.maintenance) mientras
 *      dura la ventana de escritura, para que la web muestre un 503 amable
 *      en vez de leer un .db a medio subir o un 500 crudo si desaparece un
 *      instante. Se desactiva siempre al terminar (incluso si el script
 *      aborta a mitad), vía register_shutdown_function.
 *   4. Tras subir, verifica por checksum (SHA-256, re-descargando el
 *      fichero remoto) que lo que quedó en producción coincide byte a byte
 *      con el local. Si no coincide (o si la propia subida falla), restaura
 *      automáticamente el .db anterior movido a backups/ en el paso 2.
 *   5. Con el .db ya verificado y la web fuera de mantenimiento, avisa a
 *      IndexNow (Bing/Yandex/…) con la lista completa de URLs del sitemap ya
 *      publicado — requiere 'indexnow_key' en config.local.php (ver
 *      config.local.example.php). Google deprecó su ping de sitemaps en
 *      2023; para Google el <lastmod> del propio sitemap.xml es la señal.
 *
 * Requiere .env.ftp en la raíz del repo (gitignored) con:
 *   FTP_HOST, FTP_PORT, FTP_USER, FTP_PASSWORD, FTP_REMOTE_DIR (puede ir vacío)
 *
 * Usa curl (no la extensión ftp de PHP, que no está disponible en todos los
 * entornos) para listar, renombrar (RNFR/RNTO), subir y descargar por FTP.
 *
 * Uso:
 *   php scripts/sync_db_to_prod.php                    # comprueba y sube si procede
 *   php scripts/sync_db_to_prod.php --dry-run           # solo comprueba, no sube nada
 *   php scripts/sync_db_to_prod.php --max-days 15       # umbral distinto a 10 días
 *   php scripts/sync_db_to_prod.php --local ruta/mdc.db # otro fichero local
 *   php scripts/sync_db_to_prod.php --skip-move         # no mueve el .db actual a backups/
 *                                                         (la red de seguridad extra es
 *                                                          redundante si ya hay un backup
 *                                                          reciente, que es lo que exige el
 *                                                          guardarraíl de todas formas —
 *                                                          OJO: con --skip-move el rollback
 *                                                          automático por checksum no está
 *                                                          disponible, hay que restaurar a mano)
 *   php scripts/sync_db_to_prod.php --skip-verify       # no re-descarga para comprobar el checksum
 *   php scripts/sync_db_to_prod.php --force             # sube aunque haya propuestas pendientes
 *                                                         sin bajar en producción (úsalo solo si
 *                                                         ya sabes que no importan)
 *   php scripts/sync_db_to_prod.php --skip-indexnow     # no avisa a IndexNow tras sincronizar
 */

// ── args ─────────────────────────────────────────────────────────────────
$args = [
    'dryRun' => false, 'maxDays' => 10, 'local' => __DIR__ . '/../php/data/mdc.db',
    'skipMove' => false, 'skipVerify' => false, 'force' => false, 'skipIndexNow' => false,
];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--dry-run') $args['dryRun'] = true;
    elseif ($a === '--max-days') $args['maxDays'] = (int) $argv[++$i];
    elseif ($a === '--local') $args['local'] = $argv[++$i];
    elseif ($a === '--skip-move') $args['skipMove'] = true;
    elseif ($a === '--skip-verify') $args['skipVerify'] = true;
    elseif ($a === '--force') $args['force'] = true;
    elseif ($a === '--skip-indexnow') $args['skipIndexNow'] = true;
    else { fwrite(STDERR, "Argumento no reconocido: $a\n"); exit(2); }
}
echo "Entorno: PRODUCCIÓN (.env.ftp)\n";

// ── cargar .env.ftp ──────────────────────────────────────────────────────
$envFileName = '.env.ftp';
$envFile = __DIR__ . '/../' . $envFileName;
if (!is_file($envFile)) {
    fwrite(STDERR, "No existe $envFileName en la raíz del repo. Necesito FTP_HOST/FTP_PORT/FTP_USER/FTP_PASSWORD/FTP_REMOTE_DIR.\n");
    exit(1);
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
foreach (['FTP_HOST', 'FTP_USER', 'FTP_PASSWORD'] as $req) {
    if (empty($env[$req])) { fwrite(STDERR, "Falta $req en $envFileName\n"); exit(1); }
}
$host = $env['FTP_HOST'];
$port = (int) ($env['FTP_PORT'] ?? 21);
// OJO: CURLOPT_USERPWD quiere el valor literal, NO codificado en URL (a
// diferencia de credenciales embebidas directamente en una URL ftp://user:pass@host).
$user = $env['FTP_USER'];
$pass = $env['FTP_PASSWORD'];
$remoteDir = trim($env['FTP_REMOTE_DIR'] ?? '', '/'); // vacío = raíz de la cuenta (enjaulada)
$prefix = $remoteDir !== '' ? $remoteDir . '/' : '';

if (!is_file($args['local'])) {
    fwrite(STDERR, "No existe el fichero local: {$args['local']}\n");
    exit(1);
}

// ── helpers curl/FTP ─────────────────────────────────────────────────────
function ftpBaseUrl(string $host, int $port): string
{
    return "ftp://$host:$port/";
}

/** Lista nombres de fichero (sin ruta) de un directorio remoto. */
function ftpList(string $baseUrl, string $user, string $pass, string $dir): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . $dir . '/',
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_FTP_USE_EPSV => false,
        CURLOPT_FTPLISTONLY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $out = curl_exec($ch);
    if ($out === false) {
        fwrite(STDERR, 'Error listando ' . $dir . ': ' . curl_error($ch) . "\n");
        exit(1);
    }
    $names = array_filter(array_map('trim', explode("\n", $out)));
    return array_map('basename', $names); // por si el servidor devuelve rutas completas
}

/**
 * Como ftpList(), pero un directorio inexistente o un fallo de listado no es
 * fatal: devuelve [] con un aviso. Para directorios opcionales — como
 * private/propuestas/pendientes/, que no existe hasta que un editor manda su
 * primera propuesta — donde "no hay nada que listar" es el caso normal, no
 * un error que deba abortar la sincronización del .db.
 */
function ftpListOptional(string $baseUrl, string $user, string $pass, string $dir): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . $dir . '/',
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_FTP_USE_EPSV => false,
        CURLOPT_FTPLISTONLY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $out = curl_exec($ch);
    if ($out === false) {
        fwrite(STDERR, "Aviso: no se pudo listar $dir (probablemente aún no existe) — se asume sin propuestas pendientes.\n");
        return [];
    }
    $names = array_filter(array_map('trim', explode("\n", (string) $out)));
    return array_map('basename', $names);
}

/**
 * Ejecuta comandos FTP crudos (RNFR/RNTO, etc.) con rutas completas desde la
 * raíz de la cuenta.
 *
 * OJO: CURLOPT_QUOTE se ejecuta justo tras el login, ANTES de que curl haga
 * el CWD implícito al directorio de CURLOPT_URL (ese CWD forma parte de la
 * preparación de la transferencia, no del paso de "quote"). Por eso los
 * comandos deben llevar siempre la ruta completa (p.ej. "RNFR private/mdc.db")
 * y la URL debe apuntar a la raíz — si no, RNFR/RNTO fallan con 550 porque
 * se ejecutan estando aún en "/", no en el directorio esperado.
 */
function ftpQuote(string $baseUrl, string $user, string $pass, array $commands, bool $silencioso = false): bool
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl,
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_QUOTE => $commands,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $ok = curl_exec($ch) !== false;
    if (!$ok && !$silencioso) fwrite(STDERR, 'Error ejecutando comando FTP: ' . curl_error($ch) . "\n");
    return $ok;
}

/** Sube (crea/sobrescribe) un fichero local a una ruta remota. */
function ftpUpload(string $baseUrl, string $user, string $pass, string $remotePath, string $localPath): bool
{
    $fh = fopen($localPath, 'rb');
    if ($fh === false) return false;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . $remotePath,
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ]);
    $ok = curl_exec($ch) !== false;
    if (!$ok) fwrite(STDERR, 'Error subiendo: ' . curl_error($ch) . "\n");
    fclose($fh);
    return $ok;
}

/** Descarga un fichero remoto a una ruta local (para la verificación por checksum). */
function ftpDownload(string $baseUrl, string $user, string $pass, string $remotePath, string $localPath): bool
{
    $fh = fopen($localPath, 'wb');
    if ($fh === false) return false;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl . $remotePath,
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_FILE => $fh,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
    ]);
    $ok = curl_exec($ch) !== false;
    if (!$ok) fwrite(STDERR, 'Error descargando: ' . curl_error($ch) . "\n");
    fclose($fh);
    if (!$ok) @unlink($localPath);
    return $ok;
}

/** Descarga y parsea un sitemap.xml, devolviendo la lista de <loc>. [] si falla. */
function fetchSitemapUrls(string $sitemapUrl): array
{
    $ch = curl_init($sitemapUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML((string) $body)) return [];
    $urls = [];
    foreach ($dom->getElementsByTagName('loc') as $node) {
        $urls[] = $node->textContent;
    }
    return $urls;
}

/**
 * Envía la lista completa de URLs a IndexNow (protocolo bulk, hasta 10 000
 * URLs por petición — de sobra para el catálogo). $keyLocation es la URL
 * pública donde IndexNow puede verificar la clave (la ruta que registra
 * routes.php cuando hay indexnow_key configurada). No fatal si falla: la
 * sincronización ya se ha completado con éxito antes de llegar aquí.
 */
function indexNowPing(string $host, string $key, string $keyLocation, array $urls): bool
{
    $payload = json_encode([
        'host' => $host,
        'key' => $key,
        'keyLocation' => $keyLocation,
        'urlList' => $urls,
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        fwrite(STDERR, '  Aviso IndexNow: error de red — ' . curl_error($ch) . "\n");
        return false;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status === 200 || $status === 202) return true; // 202 Accepted es la respuesta normal
    fwrite(STDERR, "  Aviso IndexNow: respuesta HTTP $status.\n");
    return false;
}

// ── 1. Buscar el backup más reciente en private/backups/ ────────────────
$baseUrl = ftpBaseUrl($host, $port);
$backupsDir = $prefix . 'private/backups';
echo "Listando $backupsDir por FTP…\n";
$files = ftpList($baseUrl, $user, $pass, $backupsDir);

$masReciente = null; // ['fecha' => DateTimeImmutable, 'nombre' => string]
foreach ($files as $f) {
    if (preg_match('/^mdc-(\d{8})-(\d{6})\.db$/', $f, $m)) {
        $fecha = DateTimeImmutable::createFromFormat('Ymd-His', $m[1] . '-' . $m[2]);
        if ($fecha !== false && ($masReciente === null || $fecha > $masReciente['fecha'])) {
            $masReciente = ['fecha' => $fecha, 'nombre' => $f];
        }
    }
}

if ($masReciente === null) {
    fwrite(STDERR, "\n⛔ ABORTADO: no se encontró ningún backup (mdc-YYYYMMDD-HHMMSS.db) en $backupsDir.\n");
    fwrite(STDERR, "   RECORDATORIO: lanza un backup manual antes de sincronizar (Scheduled Tasks → backup.php, o ejecútalo tú vía cron/Plesk) y vuelve a intentarlo.\n");
    exit(1);
}

$dias = (new DateTimeImmutable())->diff($masReciente['fecha'])->days;
echo "Backup más reciente: {$masReciente['nombre']} ({$dias} día(s))\n";

if ($dias > $args['maxDays']) {
    fwrite(STDERR, "\n⛔ ABORTADO: el backup más reciente tiene {$dias} días (máximo permitido: {$args['maxDays']}).\n");
    fwrite(STDERR, "   RECORDATORIO: lanza un backup manual antes de sincronizar (Scheduled Tasks → backup.php, o ejecútalo tú vía cron/Plesk) y vuelve a intentarlo.\n");
    exit(1);
}

echo "✓ Backup reciente (≤ {$args['maxDays']} días) — se puede sincronizar.\n";

// ── 1b. Propuestas de editores pendientes en producción sin bajar ────────
// Si las hay, subir el .db local las pisaría sin que el admin las haya visto
// nunca (sync_propuestas_from_prod.php es el paso simétrico que hay que
// correr antes). --force lo salta a propósito.
$pendientesDir = $prefix . 'private/propuestas/pendientes';
echo "Comprobando propuestas pendientes en {$pendientesDir}…\n";
$propuestas = array_filter(ftpListOptional($baseUrl, $user, $pass, $pendientesDir), static fn(string $f): bool => str_ends_with($f, '.json'));
if ($propuestas !== [] && !$args['force']) {
    fwrite(STDERR, "\n⛔ ABORTADO: hay " . count($propuestas) . " propuesta(s) pendiente(s) en producción sin bajar:\n");
    foreach ($propuestas as $p) fwrite(STDERR, "   · $p\n");
    fwrite(STDERR, "   Ejecuta primero: php scripts/sync_propuestas_from_prod.php\n");
    fwrite(STDERR, "   (o repite con --force si ya sabes que no importan).\n");
    exit(1);
}
// Si hay propuestas y hemos pasado el bloque anterior, es que venimos con --force.
if ($propuestas !== []) {
    echo '⚠ ' . count($propuestas) . " propuesta(s) pendiente(s) en producción — continuando por --force.\n";
} else {
    echo "✓ Sin propuestas pendientes sin bajar.\n";
}

if ($args['dryRun']) {
    echo "--dry-run: no se sube nada.\n";
    exit(0);
}

// ── 1c. Modo mantenimiento durante la ventana de escritura ───────────────
// Centinela subido por FTP; bootstrap.php lo comprueba antes de leer el .db
// (ver App\Http::maintenance()). $disableMaintenance se llama explícitamente
// en cuanto la escritura queda en un estado estable (éxito verificado, o
// rollback resuelto) para no tener la web caída más tiempo del necesario; el
// shutdown function es la red de seguridad para cualquier salida que no pase
// por ahí (checksum incorrecto sin rollback posible, error inesperado, etc.).
$privateDir = $prefix . 'private';
$maintenanceOn = false;
$disableMaintenance = static function () use (&$maintenanceOn, $baseUrl, $user, $pass, $privateDir): void {
    if (!$maintenanceOn) return;
    echo "Desactivando modo mantenimiento…\n";
    if (ftpQuote($baseUrl, $user, $pass, ["DELE {$privateDir}/.maintenance"])) {
        $maintenanceOn = false;
    } else {
        fwrite(STDERR, "  ⚠ No se pudo borrar {$privateDir}/.maintenance por FTP — bórralo a mano cuanto antes (la web se queda en mantenimiento hasta entonces).\n");
    }
};
register_shutdown_function($disableMaintenance);
$tmpFlag = tempnam(sys_get_temp_dir(), 'mdc-maint-');
file_put_contents($tmpFlag, 'sync en curso desde ' . date('c') . "\n");
if (ftpUpload($baseUrl, $user, $pass, $privateDir . '/.maintenance', $tmpFlag)) {
    $maintenanceOn = true;
    echo "✓ Modo mantenimiento activado.\n";
} else {
    fwrite(STDERR, "  ⚠ No se pudo activar el modo mantenimiento (se continúa igualmente; la ventana de escritura queda sin el aviso 503).\n");
}
@unlink($tmpFlag);

// ── 2. Mover el .db actual de producción a backups/ antes de sobrescribir ──
// (red de seguridad extra, sin coste: renombrado en el propio FTP, no hace
// falta descargar+resubir. Queda con el mismo patrón de nombre que usa
// backup.php, así futuras ejecuciones de este script lo cuentan también
// como backup válido.)
$tsAhora = (new DateTimeImmutable())->format('Ymd-His');
$nombrePreSync = "mdc-$tsAhora.db";
if ($args['skipMove']) {
    echo "--skip-move: no se mueve el .db actual (ya hay un backup reciente que cubre el rollback).\n";
} else {
    echo "Moviendo private/mdc.db → private/backups/$nombrePreSync (red de seguridad)…\n";
    $renombrado = ftpQuote($baseUrl, $user, $pass, [
        "RNFR $privateDir/mdc.db",
        "RNTO $privateDir/backups/$nombrePreSync",
    ]);
    if (!$renombrado) {
        fwrite(STDERR, "\n⛔ ABORTADO: no se pudo mover el .db actual a backups/ antes de sobrescribir. No se ha subido nada.\n");
        fwrite(STDERR, "   (Si ya hay un backup reciente, puedes reintentar con --skip-move para saltarte este paso.)\n");
        exit(1);
    }
}

/**
 * Intenta deshacer el swap devolviendo la copia previa a su sitio. Solo
 * posible si se hizo el paso 2 (sin --skip-move no hay copia que restaurar
 * por este medio). Devuelve true si el rollback se completó.
 */
$intentarRollback = static function () use ($baseUrl, $user, $pass, $privateDir, $nombrePreSync, $args): bool {
    if ($args['skipMove']) {
        fwrite(STDERR, "  No hay rollback automático posible (--skip-move): restaura a mano desde private/backups/ si hace falta.\n");
        return false;
    }
    fwrite(STDERR, "  Intentando rollback automático: private/backups/$nombrePreSync → private/mdc.db…\n");
    $ok = ftpQuote($baseUrl, $user, $pass, [
        "DELE $privateDir/mdc.db",
        "RNFR $privateDir/backups/$nombrePreSync",
        "RNTO $privateDir/mdc.db",
    ]);
    fwrite(STDERR, $ok
        ? "  ✓ Rollback completado: producción ha vuelto a la copia previa.\n"
        : "  ⚠ El rollback automático falló. Restaura a mano desde private/backups/$nombrePreSync — es urgente, producción puede haber quedado sin .db.\n");
    return $ok;
};

// ── 3. Subir el .db local a private/mdc.db ───────────────────────────────
echo "Subiendo {$args['local']} → private/mdc.db…\n";
$subido = ftpUpload($baseUrl, $user, $pass, $privateDir . '/mdc.db', $args['local']);
if (!$subido) {
    fwrite(STDERR, "\n⛔ La subida falló.\n");
    $intentarRollback();
    exit(1);
}

// ── 4. Verificación por checksum (SHA-256, re-descarga) ──────────────────
if ($args['skipVerify']) {
    echo "--skip-verify: no se comprueba el checksum de lo subido.\n";
} else {
    echo "Verificando checksum de lo subido (re-descarga)…\n";
    $localHash = hash_file('sha256', $args['local']);
    $tmpVerify = tempnam(sys_get_temp_dir(), 'mdc-verify-');
    $descargado = ftpDownload($baseUrl, $user, $pass, $privateDir . '/mdc.db', $tmpVerify);
    $remoteHash = $descargado ? hash_file('sha256', $tmpVerify) : null;
    @unlink($tmpVerify);

    if ($remoteHash === null) {
        fwrite(STDERR, "\n⛔ No se pudo re-descargar private/mdc.db para verificar el checksum.\n");
        $intentarRollback();
        exit(1);
    }
    if ($remoteHash !== $localHash) {
        fwrite(STDERR, "\n⛔ CHECKSUM NO COINCIDE: local=$localHash remoto=$remoteHash — la subida quedó corrupta o incompleta.\n");
        $intentarRollback();
        exit(1);
    }
    echo "✓ Checksum verificado (SHA-256 coincide).\n";
}

// El .db ya está en un estado estable y verificado: se puede reabrir la web
// antes de gastar tiempo en el ping (que es un extra, no parte de la
// escritura). $disableMaintenance es idempotente si el shutdown function
// vuelve a intentarlo al terminar el script.
$disableMaintenance();

if ($args['skipMove']) {
    echo "\n✅ Sincronizado: private/mdc.db actualizado. Rollback disponible desde el backup reciente en private/backups/.\n";
} else {
    echo "\n✅ Sincronizado: private/mdc.db actualizado. Copia previa a salvo en private/backups/$nombrePreSync.\n";
}

// ── 5. Aviso a buscadores (IndexNow) del contenido recién sincronizado ────
// Google deprecó su endpoint clásico de "ping de sitemap" en 2023 — ya no
// hace nada, así que no se reproduce aquí ese cargo-cult; para Google el
// <lastmod> del sitemap (ver Pages::sitemap) es la señal correcta y la
// recolecta en su propio calendario de rastreo. IndexNow sí sigue vigente y
// lo consultan Bing, Yandex y otros: un POST bulk con toda la lista de URLs
// del sitemap ya en producción (recién liberado del modo mantenimiento).
if ($args['skipIndexNow']) {
    echo "--skip-indexnow: no se avisa a IndexNow.\n";
} else {
    // try/catch de cinturón y tirantes: el sync ya ha terminado con éxito a
    // estas alturas, así que un fallo aquí (p.ej. config.local.php roto en la
    // máquina del admin) no debe hacer parecer que la sincronización falló.
    try {
        define('APP_DIR', __DIR__ . '/../php/app');
        define('DATA_DIR', __DIR__ . '/../php/data'); // config.php la referencia para el db_path por defecto; no se usa aquí.
        $appConfig = require APP_DIR . '/config.php';
        $indexNowKey = (string) ($appConfig['indexnow_key'] ?? '');

        if ($indexNowKey === '') {
            echo "IndexNow no configurado (indexnow_key) — omitiendo ping. Ver config.local.example.php.\n";
        } else {
            $siteUrl = rtrim((string) $appConfig['site_url'], '/');
            $host = (string) parse_url($siteUrl, PHP_URL_HOST);
            echo "Avisando a IndexNow ($siteUrl/sitemap.xml)…\n";
            $urls = fetchSitemapUrls($siteUrl . '/sitemap.xml');
            if ($urls === []) {
                fwrite(STDERR, "  ⚠ No se pudo leer el sitemap recién publicado — no se avisa a IndexNow esta vez.\n");
            } else {
                $ok = indexNowPing($host, $indexNowKey, $siteUrl . '/' . $indexNowKey . '.txt', $urls);
                echo $ok
                    ? '✓ IndexNow avisado con ' . count($urls) . " URLs.\n"
                    : "  (no fatal: la sincronización ya se completó igualmente)\n";
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, '  ⚠ Aviso IndexNow omitido por un error: ' . $e->getMessage() . "\n");
    }
}
