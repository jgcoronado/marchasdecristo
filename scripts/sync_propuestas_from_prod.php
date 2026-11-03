<?php

declare(strict_types=1);

/*
 * Baja a local las propuestas de cambio que los editores han creado en
 * producción (HelioHost/Plesk), por FTP. Es el paso simétrico a
 * sync_db_to_prod.php: los editores no tocan la BD remota, solo dejan ficheros
 * JSON en private/propuestas/pendientes/; este script los trae a
 * php/data/propuestas/pendientes/ para que el admin los revise en local y, al
 * aceptarlos, se apliquen sobre la BD local (que luego se sincroniza a remoto).
 *
 * Tras bajar cada propuesta con éxito, la mueve en el servidor a
 * private/propuestas/recibidas/ para no volver a descargarla y para que el
 * editor vea que ya ha sido recogida.
 *
 * Algunas propuestas llevan adjuntos junto al JSON (la portada de un disco:
 * <id>.portada.webp, ver PropuestaRepo::createOperacion). Se bajan y se
 * mueven igual, y siempre ANTES que su JSON: así una propuesta nunca aparece
 * en local sin su adjunto.
 *
 * Requiere .env.ftp en la raíz del repo (gitignored) con:
 *   FTP_HOST, FTP_PORT, FTP_USER, FTP_PASSWORD, FTP_REMOTE_DIR (puede ir vacío)
 *
 * Uso:
 *   php scripts/sync_propuestas_from_prod.php            # baja y marca recibidas
 *   php scripts/sync_propuestas_from_prod.php --dry-run   # solo lista, no baja nada
 *   php scripts/sync_propuestas_from_prod.php --keep      # baja pero NO mueve en remoto
 */

// ── args ─────────────────────────────────────────────────────────────────
$args = ['dryRun' => false, 'keep' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--dry-run') $args['dryRun'] = true;
    elseif ($a === '--keep') $args['keep'] = true;
    else { fwrite(STDERR, "Argumento no reconocido: $a\n"); exit(2); }
}

// ── cargar .env.ftp ──────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env.ftp';
if (!is_file($envFile)) {
    fwrite(STDERR, "No existe .env.ftp en la raíz del repo. Necesito FTP_HOST/FTP_PORT/FTP_USER/FTP_PASSWORD/FTP_REMOTE_DIR.\n");
    exit(1);
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
foreach (['FTP_HOST', 'FTP_USER', 'FTP_PASSWORD'] as $req) {
    if (empty($env[$req])) { fwrite(STDERR, "Falta $req en .env.ftp\n"); exit(1); }
}
$host = $env['FTP_HOST'];
$port = (int) ($env['FTP_PORT'] ?? 21);
$user = $env['FTP_USER'];
$pass = $env['FTP_PASSWORD'];
$remoteDir = trim($env['FTP_REMOTE_DIR'] ?? '', '/');
$prefix = $remoteDir !== '' ? $remoteDir . '/' : '';

$localDir = __DIR__ . '/../php/data/propuestas/pendientes';
if (!is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
    fwrite(STDERR, "No se pudo crear el directorio local: $localDir\n");
    exit(1);
}

// ── helpers curl/FTP (mismos que sync_db_to_prod.php) ────────────────────
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
        // Un directorio inexistente aún (sin propuestas todavía) no es un error fatal.
        fwrite(STDERR, 'Aviso listando ' . $dir . ': ' . curl_error($ch) . "\n");
        return [];
    }
    $names = array_filter(array_map('trim', explode("\n", (string) $out)));
    return array_map('basename', $names);
}

/** Ejecuta comandos FTP crudos (MKD/RNFR/RNTO) con rutas desde la raíz de la cuenta. */
function ftpQuote(string $baseUrl, string $user, string $pass, array $commands): bool
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
    if (!$ok) fwrite(STDERR, 'Error ejecutando comando FTP: ' . curl_error($ch) . "\n");
    return $ok;
}

/** Descarga un fichero remoto a una ruta local. */
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

// ── 1. Listar propuestas pendientes en remoto ────────────────────────────
$baseUrl = ftpBaseUrl($host, $port);
$pendientesDir = $prefix . 'private/propuestas/pendientes';
echo "Listando $pendientesDir por FTP…\n";
$todos = ftpList($baseUrl, $user, $pass, $pendientesDir);
$files = array_values(array_filter($todos, static fn(string $f): bool => (bool) preg_match('/^[A-Za-z0-9-]+\.json$/', $f)));
$adjuntos = array_values(array_filter($todos, static fn(string $f): bool => (bool) preg_match('/^[A-Za-z0-9-]+\.[a-z]+\.[a-z0-9]+$/', $f)));

if ($files === []) {
    echo "No hay propuestas pendientes en remoto.\n";
    exit(0);
}
echo count($files) . " propuesta(s) pendiente(s)" . ($adjuntos !== [] ? ', ' . count($adjuntos) . ' adjunto(s)' : '') . ".\n";

if ($args['dryRun']) {
    foreach ([...$adjuntos, ...$files] as $f) echo "  · $f\n";
    echo "--dry-run: no se baja nada.\n";
    exit(0);
}

// Asegurar el directorio 'recibidas' en remoto (ignora error si ya existe).
if (!$args['keep']) {
    ftpQuote($baseUrl, $user, $pass, ["MKD {$prefix}private/propuestas/recibidas"]);
}

// ── 2. Descargar cada una y (opcional) marcarla recibida en remoto ───────
$bajadas = 0;
$errores = 0;
foreach ($files as $f) {
    // Primero sus adjuntos: si alguno falla, el JSON no se baja (ni se mueve
    // en remoto) y la propuesta entera se reintenta en la próxima pasada.
    $id = substr($f, 0, -strlen('.json'));
    $suyos = array_values(array_filter($adjuntos, static fn(string $a): bool => str_starts_with($a, $id . '.')));
    $okAdjuntos = true;
    foreach ($suyos as $a) {
        echo "Bajando {$a}…\n";
        if (!ftpDownload($baseUrl, $user, $pass, $pendientesDir . '/' . $a, $localDir . '/' . $a)) { $okAdjuntos = false; break; }
    }
    if (!$okAdjuntos) {
        $errores++;
        continue;
    }

    $remotePath = $pendientesDir . '/' . $f;
    $localPath = $localDir . '/' . $f;
    echo "Bajando {$f}…\n";
    if (!ftpDownload($baseUrl, $user, $pass, $remotePath, $localPath)) {
        $errores++;
        continue;
    }
    $bajadas++;
    if (!$args['keep']) {
        foreach ($suyos as $a) {
            ftpQuote($baseUrl, $user, $pass, ["RNFR {$pendientesDir}/{$a}", "RNTO {$prefix}private/propuestas/recibidas/{$a}"]);
        }
    }
    if (!$args['keep']) {
        $moved = ftpQuote($baseUrl, $user, $pass, [
            "RNFR {$pendientesDir}/{$f}",
            "RNTO {$prefix}private/propuestas/recibidas/{$f}",
        ]);
        if (!$moved) {
            fwrite(STDERR, "  Aviso: no se pudo mover $f a recibidas/ en remoto (se volverá a bajar la próxima vez).\n");
        }
    }
}

echo "\n✅ $bajadas propuesta(s) en php/data/propuestas/pendientes/" . ($errores > 0 ? " · $errores con error" : '') . ".\n";
echo "Revísalas en el panel local: /dashboard/propuestas\n";
