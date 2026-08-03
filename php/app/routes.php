<?php

declare(strict_types=1);

use App\Admin;
use App\Api;
use App\Auth;
use App\Db;
use App\Http;
use App\Legacy;
use App\Og;
use App\Pages;

/** @var App\Router $router */

// ── Home ─────────────────────────────────────────────────────────────────────
$router->get('/', [Pages::class, 'home']);

// ── Redirects legacy /x/search → /x?... (antes del catch-all) ─────────────────
$legacySearch = static function (string $target): callable {
    return static function () use ($target): void {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        Http::redirect($target . ($qs !== '' ? '?' . $qs : ''), 308);
    };
};
$router->get('/marcha/search', $legacySearch('/marcha'));
$router->get('/autor/search', $legacySearch('/autor'));
$router->get('/banda/search', $legacySearch('/banda'));
$router->get('/disco/search', $legacySearch('/disco'));

// ── Listados / buscadores ─────────────────────────────────────────────────────
$router->get('/marcha', [Pages::class, 'marchaList']);
$router->get('/autor', [Pages::class, 'autorList']);
$router->get('/banda', [Pages::class, 'bandaList']);
$router->get('/disco', [Pages::class, 'discoList']);

// ── Dedicatorias: hubs de advocación (N-01 / N-02) ────────────────────────────
$router->get('/dedicatorias', [Pages::class, 'dedicatoriaList']);
$router->get('/dedicatoria/{slugAndId}', [Pages::class, 'dedicatoriaDetail']);

// ── Hubs de catálogo indexables (C1): año / estilo / provincia ───────────────
// Dos segmentos tras /marcha, así que no chocan con el detalle {slugAndId};
// se registran antes por claridad.
$router->get('/marcha/ano/{anio}', [Pages::class, 'marchaAnioHub']);
$router->get('/marcha/estilo/{slug}', [Pages::class, 'marchaEstiloHub']);
$router->get('/marcha/provincia/{slug}', [Pages::class, 'marchaProvinciaHub']);

// ── Detalles (catch-all por entidad) ──────────────────────────────────────────
$router->get('/marcha/{slugAndId}', [Pages::class, 'marchaDetail']);
$router->get('/autor/{slugAndId}', [Pages::class, 'autorDetail']);
$router->get('/banda/{slugAndId}', [Pages::class, 'bandaDetail']);
$router->get('/disco/{slugAndId}', [Pages::class, 'discoDetail']);

// ── Rankings (N-07): /estadisticas se renombró aquí, con 301 permanente ──────
$router->get('/estadisticas', [Pages::class, 'estadisticas']);
$router->get('/rankings', [Pages::class, 'rankingsIndex']);
// Dos segmentos, así que se registra antes por claridad (mismo patrón que los
// hubs de /marcha).
$router->get('/rankings/{anio}', [Pages::class, 'rankingsAnioHub']);

// ── Aniversarios (N-09): 25/50/75/100+ años, con centenarios destacados ──────
$router->get('/aniversarios', [Pages::class, 'aniversariosIndex']);
$router->get('/aniversarios/{anio}', [Pages::class, 'aniversariosAnioHub']);

// ── Estado del catálogo (R-07): KPI de cobertura de audio ────────────────────
$router->get('/estado-catalogo', [Pages::class, 'estadoCatalogo']);

// ── Mapa (N-10): coropleta SVG por provincia ──────────────────────────────────
$router->get('/mapa', [Pages::class, 'mapa']);
$router->get('/mapa/provincia/{slug}', [Pages::class, 'mapaProvincia']);

// ── Temporada (N-04): contratos banda↔hermandad, alta manual (N-06 pendiente) ──
$router->get('/temporada', [Pages::class, 'temporadaIndex']);
$router->get('/temporada/{anio}', [Pages::class, 'temporada']);

// ── Búsqueda global unificada (M3): página + autocompletado público ──────────
$router->get('/buscar', [Pages::class, 'buscar']);
$router->get('/api/buscar', [Api::class, 'buscar']);

// ── SEO ────────────────────────────────────────────────────────────────────────
$router->get('/sitemap.xml', [Pages::class, 'sitemap']);
$router->get('/robots.txt', [Pages::class, 'robots']);

// ── Datos abiertos (M1): página «Datos», feeds y llms.txt ────────────────────
$router->get('/datos', [Pages::class, 'datos']);
$router->get('/feed.xml', [Pages::class, 'feedRss']);
$router->get('/feed.json', [Pages::class, 'feedJson']);
$router->get('/llms.txt', [Pages::class, 'llms']);

// API JSON de solo lectura (mismas lecturas que el HTML, forma estable + licencia).
// El {id} admite número o slug-id; se extrae el id numérico en Api.
$router->get('/api/marcha/{id}.json', [Api::class, 'marcha']);
$router->get('/api/autor/{id}.json', [Api::class, 'autor']);
$router->get('/api/banda/{id}.json', [Api::class, 'banda']);
$router->get('/api/disco/{id}.json', [Api::class, 'disco']);

// og:image dinámica por entidad (M4): tarjeta social generada con GD y cacheada.
$router->get('/og/{tipo}/{id}.png', [Og::class, 'render']);

// Verificación de IndexNow (C2): solo se registra si hay clave configurada.
// $config ya está en el scope de bootstrap.php cuando se hace require de este
// fichero, así que la ruta puede depender de su valor sin pasos extra.
if (!empty($config['indexnow_key'])) {
    $router->get('/' . $config['indexnow_key'] . '.txt', static function () use ($config): void {
        header('Content-Type: text/plain; charset=UTF-8');
        Http::cachePublic(86400);
        echo $config['indexnow_key'];
    });
}

// ── Diagnóstico ──────────────────────────────────────────────────────────────
// Público: solo "ok" + versión. El detalle (rutas, conteos, FTS) requiere sesión
// admin para no filtrar la ruta del .db.
$router->get('/health', static function (): void {
    header('Content-Type: text/plain; charset=utf-8');
    Http::noStore();
    echo "status: ok\n";
    echo 'php: ' . PHP_VERSION . "\n";
    // Identificador de entorno: confirma de un vistazo (o desde el smoke
    // remoto del pipeline) contra qué host se ha resuelto la petición. Es la
    // misma deducción que decide qué secciones se publican y si el panel puede
    // escribir, así que sale de App\Entorno y no de una copia local.
    echo 'entorno: ' . App\Entorno::nombre() . "\n";

    // Chequeo de BD visible para cualquiera (incluido un monitor externo):
    // solo ok/error, sin ruta ni mensaje de excepción — el detalle completo
    // sigue reservado a sesión admin, más abajo. Código 503 si falla, para
    // que un monitor que solo mire el status HTTP (sin keyword) también lo
    // detecte.
    try {
        Db::pdo()->query('SELECT 1')->fetchColumn();
        echo "db: ok\n";
    } catch (\Throwable) {
        echo "db: error\n";
        http_response_code(503);
    }

    if (Auth::currentSession() === null) {
        return; // sin sesión no revelamos más
    }

    $config = $GLOBALS['config'];
    echo 'db_path: ' . $config['db_path'] . "\n";
    echo 'db_exists: ' . (is_file($config['db_path']) ? 'YES' : 'NO') . "\n";
    if (!is_file($config['db_path'])) {
        return;
    }
    try {
        $pdo = Db::pdo();
        echo 'sqlite: ' . $pdo->query('SELECT sqlite_version()')->fetchColumn() . "\n";
        echo 'journal_mode: ' . $pdo->query('PRAGMA journal_mode')->fetchColumn() . "\n";
        $c = Db::counts();
        echo "counts: marchas={$c['MARCHAS']} autores={$c['AUTORES']} bandas={$c['BANDAS']} discos={$c['DISCOS']}\n";
        $pdo->query('SELECT rowid FROM marcha_fts WHERE marcha_fts MATCH \'"amargura"\' LIMIT 1')->fetchAll();
        echo "fts5: OK\n";
    } catch (\Throwable $e) {
        echo 'db_error: ' . $e->getMessage() . "\n";
    }
});

// ── Admin (auth + panel) ──────────────────────────────────────────────────────
$router->get('/login', [Admin::class, 'loginForm']);
$router->post('/login', [Admin::class, 'loginPost']);
$router->post('/logout', [Admin::class, 'logout']);
$router->get('/dashboard', [Admin::class, 'dashboard']);
// /add antes del catch-all /{id}
$router->get('/dashboard/marcha/add', [Admin::class, 'marchaAddForm']);
$router->post('/dashboard/marcha/add', [Admin::class, 'marchaAddPost']);
$router->get('/dashboard/marcha/{id}', [Admin::class, 'marchaEditForm']);
$router->post('/dashboard/marcha/{id}', [Admin::class, 'marchaEditPost']);
// Curación de estilo (CCTT/AM), asignación manual por lote.
$router->get('/dashboard/estilos', [Admin::class, 'estiloList']);
$router->post('/dashboard/estilos/asignar', [Admin::class, 'estiloAssignPost']);
$router->get('/dashboard/autor/add', [Admin::class, 'autorAddForm']);
$router->post('/dashboard/autor/add', [Admin::class, 'autorAddPost']);
$router->get('/dashboard/autor/{id}', [Admin::class, 'autorEditForm']);
$router->post('/dashboard/autor/{id}', [Admin::class, 'autorEditPost']);
// Alta de banda (antes del catch-all /{id}).
$router->get('/dashboard/banda/add', [Admin::class, 'bandaAddForm']);
$router->post('/dashboard/banda/add', [Admin::class, 'bandaAddPost']);
// Edición de banda + relaciones de linaje (banda_relacion).
$router->get('/dashboard/banda/{id}', [Admin::class, 'bandaEditForm']);
$router->post('/dashboard/banda/{id}', [Admin::class, 'bandaEditPost']);
$router->post('/dashboard/banda/{id}/relacion', [Admin::class, 'bandaRelacionAddPost']);
$router->post('/dashboard/banda/{id}/relacion/{rel}/borrar', [Admin::class, 'bandaRelacionDeletePost']);
$router->post('/dashboard/banda/{id}/social', [Admin::class, 'bandaSocialPost']);
// Discos: alta (con portada) y edición + pistas. 'add' antes que {id} para que
// /dashboard/disco/add no se interprete como el disco de id 0.
$router->get('/dashboard/disco/add', [Admin::class, 'discoAddForm']);
$router->post('/dashboard/disco/add', [Admin::class, 'discoAddPost']);
$router->get('/dashboard/disco/{id}', [Admin::class, 'discoEditForm']);
$router->post('/dashboard/disco/{id}', [Admin::class, 'discoEditPost']);
// Alta asistida: del enlace del álbum en streaming a las pistas del disco.
// Es lo que se ofrece nada más crear un disco; el alta manual sigue en /{id}.
$router->get('/dashboard/disco/{id}/importar', [Admin::class, 'discoImportarForm']);
$router->post('/dashboard/disco/{id}/importar', [Admin::class, 'discoImportarPost']);
$router->post('/dashboard/disco/{id}/importar/confirmar', [Admin::class, 'discoImportarConfirmar']);
$router->post('/dashboard/disco/{id}/pista', [Admin::class, 'discoPistaAddPost']);
$router->post('/dashboard/disco/{id}/pista/{dm}/borrar', [Admin::class, 'discoPistaDeletePost']);
$router->post('/dashboard/disco/{id}/pista/{dm}/editar', [Admin::class, 'discoPistaEditPost']);
$router->post('/dashboard/disco/{id}/social', [Admin::class, 'discoSocialPost']);
$router->get('/api/marcha/fastSearch', [Admin::class, 'marchaFastSearch']);
$router->get('/api/autor/fastSearch', [Admin::class, 'autorFastSearch']);
$router->get('/api/banda/fastSearch', [Admin::class, 'bandaFastSearch']);
$router->get('/api/banda/estilo', [Admin::class, 'bandaEstiloSugerido']);
$router->get('/api/municipio/fastSearch', [Admin::class, 'municipioFastSearch']);
$router->post('/dashboard/municipio/add', [Admin::class, 'municipioAddPost']);
$router->get('/api/marcha/checkDuplicate', [Admin::class, 'marchaCheckDuplicate']);
$router->get('/api/dedicatoria/fastSearch', [Admin::class, 'dedicatoriaFastSearch']);
// Curación de dedicatorias (hubs N-01/N-02). Lista antes que el detalle {id}.
$router->get('/dashboard/dedicatorias', [Admin::class, 'dedicatoriasList']);
$router->get('/dashboard/dedicatoria/{id}', [Admin::class, 'dedicatoriaEditForm']);
$router->post('/dashboard/dedicatoria/{id}', [Admin::class, 'dedicatoriaEditPost']);
$router->post('/dashboard/dedicatoria/{id}/alias/mover', [Admin::class, 'dedicatoriaAliasMovePost']);
$router->post('/dashboard/dedicatoria/{id}/alias/separar', [Admin::class, 'dedicatoriaAliasSplitPost']);
$router->post('/dashboard/dedicatoria/{id}/unificar', [Admin::class, 'dedicatoriaUnifyPost']);

// ── Gestión de usuarios y roles (solo admin) ─────────────────────────────────
$router->get('/dashboard/usuarios', [Admin::class, 'usuariosList']);
$router->post('/dashboard/usuarios/crear', [Admin::class, 'usuariosCrearPost']);
$router->post('/dashboard/usuarios/{id}/rol', [Admin::class, 'usuariosRolPost']);
$router->post('/dashboard/usuarios/{id}/reset', [Admin::class, 'usuariosResetPost']);

// ── Propuestas de editores (revisión, solo admin) ────────────────────────────
$router->get('/dashboard/propuestas', [Admin::class, 'propuestaList']);
$router->get('/dashboard/propuesta/{id}', [Admin::class, 'propuestaDetail']);
$router->post('/dashboard/propuesta/{id}/aceptar', [Admin::class, 'propuestaAceptar']);
$router->post('/dashboard/propuesta/{id}/rechazar', [Admin::class, 'propuestaRechazar']);

// ── Ingesta (revisión de candidatos de YouTube, ver tools/ingest/) ───────────
$router->get('/dashboard/ingesta', [Admin::class, 'ingestaList']);
$router->post('/dashboard/ingesta/descartar-multiple', [Admin::class, 'ingestaDescartarMultiple']);
$router->post('/dashboard/ingesta/deshacer-descarte', [Admin::class, 'ingestaDeshacerDescarte']);
$router->get('/dashboard/ingesta/{id}', [Admin::class, 'ingestaDetail']);
$router->post('/dashboard/ingesta/{id}/aceptar', [Admin::class, 'ingestaAceptar']);
$router->post('/dashboard/ingesta/{id}/asociar', [Admin::class, 'ingestaAsociar']);
$router->post('/dashboard/ingesta/{id}/descartar', [Admin::class, 'ingestaDescartar']);

// ── Temporada / contratos (N-04/N-05): alta manual desde el panel ───────────
$router->get('/dashboard/temporada/{anio}', [Admin::class, 'temporadaAdmin']);
$router->post('/dashboard/temporada/{anio}/add', [Admin::class, 'temporadaAddPost']);
$router->post('/dashboard/temporada/{anio}/{contrato}/borrar', [Admin::class, 'temporadaDeletePost']);

// ── Enlaces de streaming (curación de candidatos Spotify/Apple/Deezer) ───────
$router->get('/dashboard/enlaces', [Admin::class, 'enlaceList']);
$router->post('/dashboard/enlaces/rechazar-multiple', [Admin::class, 'enlaceRechazarMultiple']);
$router->post('/dashboard/enlaces/{id}/aprobar', [Admin::class, 'enlaceAprobar']);
$router->post('/dashboard/enlaces/{id}/rechazar', [Admin::class, 'enlaceRechazar']);

// ── 404 (con puente de URLs heredadas .html → ficha nueva, 301) ──────────────
// Antes de rendir el 404 se intenta traducir la URL del sitio MySQL original
// (…-marcha-730.html) a su canónica nueva y redirigir 301, para no perder la
// indexación histórica de Google. Ver App\Legacy.
$router->notFound(static function (string $path = '/'): void {
    $target = Legacy::resolve($path);
    if ($target !== null) {
        Http::redirect($target, 301);
    }
    Http::notFound();
});
