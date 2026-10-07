<?php

declare(strict_types=1);

namespace App;

final class Http
{
    /** Redirección permanente (canónica slug-id). 308 preserva el método GET. */
    public static function redirect(string $location, int $status = 308): never
    {
        header('Location: ' . $location, true, $status);
        exit;
    }

    /** 404 con la plantilla propia dentro del layout. */
    public static function notFound(): never
    {
        http_response_code(404);
        View::render('404', [], ['title' => 'Página no encontrada — Marchas de Cristo', 'compartir' => false]);
        exit;
    }

    /** 403 para accesos sin la capacidad requerida (rol insuficiente). */
    public static function forbidden(): never
    {
        http_response_code(403);
        self::noStore();
        View::render('403', [], ['title' => 'Acceso restringido — Marchas de Cristo', 'noindex' => true, 'compartir' => false]);
        exit;
    }

    /** 503 cuando el panel intenta escribir fuera del entorno local (ver Db::assertWritable). */
    public static function readOnly(): never
    {
        http_response_code(503);
        self::noStore();
        View::render('readonly', [], ['title' => 'Solo lectura — Marchas de Cristo', 'noindex' => true, 'compartir' => false]);
        exit;
    }

    /**
     * 503 mientras scripts/sync_db_to_prod.php reemplaza el .db (ver el
     * fichero centinela que crea/borra ese script, comprobado en bootstrap.php).
     * Retry-After orienta a clientes y monitores de uptime: la ventana es de
     * segundos, no un caído real.
     */
    public static function maintenance(): never
    {
        http_response_code(503);
        header('Retry-After: 120');
        self::noStore();
        View::render('maintenance', [], ['title' => 'Actualizando — Marchas de Cristo', 'noindex' => true, 'compartir' => false]);
        exit;
    }

    /**
     * 500 ante una excepción o error fatal no capturado (ver bootstrap.php).
     * Descarta la salida a medias; si las cabeceras ya salieron no se puede
     * cambiar el código, así que no se pinta nada más. Si el propio layout
     * falla, cae a un HTML mínimo sin dependencias.
     */
    public static function serverError(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        self::noStore();
        try {
            ob_start();
            View::render('500', [], ['title' => 'Error del servidor — Marchas de Cristo', 'noindex' => true, 'compartir' => false]);
            ob_end_flush();
        } catch (\Throwable) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="robots" content="noindex">'
                . '<title>Error del servidor — Marchas de Cristo</title>'
                . '<h1>Algo ha fallado</h1><p>Ha ocurrido un error en el servidor. Prueba de nuevo en unos minutos '
                . 'o vuelve a la <a href="/">página de inicio</a>.</p></html>';
        }
    }

    /** Cacheable por navegador/proxy (páginas públicas estables). */
    public static function cachePublic(int $seconds): void
    {
        header('Cache-Control: public, max-age=' . $seconds);
    }

    /** No cachear (búsquedas y páginas de admin). */
    public static function noStore(): void
    {
        header('Cache-Control: no-store, max-age=0');
    }

    /**
     * URL de destino si hay que redirigir al host canónico (301), o null si no.
     * Solo actúa si config['force_canonical_host'] es true (activar tras el cutover).
     * Redirige cualquier host != el de site_url → site_url + ruta (cubre staging y www).
     */
    public static function canonicalRedirectTarget(array $config, string $host, string $uri): ?string
    {
        if (empty($config['force_canonical_host'])) {
            return null;
        }
        $canonical = parse_url((string) ($config['site_url'] ?? ''), PHP_URL_HOST);
        if (!$canonical) {
            return null;
        }
        $host = preg_replace('/:\d+$/', '', $host); // quitar puerto
        if (strcasecmp((string) $host, $canonical) === 0) {
            return null;
        }
        return rtrim((string) $config['site_url'], '/') . ($uri !== '' ? $uri : '/');
    }
}
