<?php

declare(strict_types=1);

/*
 * Pruebas del alta asistida de pistas de un disco (App\Tracklist +
 * App\ImportadorPistas + AdminRepo::addPista), sin red y sin servidor.
 *
 * Complementa a ci_smoke.php, que prueba rutas HTTP: aquí se prueba la lógica
 * que decide QUÉ marcha es cada corte del álbum, con qué número, volumen y
 * duración, y qué acaba escrito en `disco_marcha`. El tracklist de los
 * servicios se inyecta desde fixtures/tracklist_importador.json a través de
 * Tracklist::$fetcher, así que ninguna prueba depende de Spotify/Apple/Deezer
 * ni de tener credenciales.
 *
 * Sin framework de test (cero dependencias de Composer), igual que ci_smoke:
 * un fallo de aserción es una excepción que captura el runner.
 *
 * Uso: php php/tools/ci_importar.php [ruta .db temporal]
 */

use App\AdminRepo;
use App\Db;
use App\ImportadorPistas;
use App\Tracklist;

// ── Arranque de la app sin front controller ──────────────────────────────────
// bootstrap.php despacha el router al final, así que aquí se replica solo lo
// que hace falta: autoload, constantes de rutas y config con la BD de pruebas.
define('BASE_DIR', dirname(__DIR__));
define('APP_DIR', BASE_DIR . '/app');
define('DATA_DIR', BASE_DIR . '/data');

$dbPath = $argv[1] ?? (sys_get_temp_dir() . '/ci-importar-' . getmypid() . '.db');

/** Construye la BD de pruebas reutilizando la fixture de CI (mismo esquema y datos). */
$construirFixture = static function (string $ruta): void {
    $argv = ['ci_fixture.php', $ruta];   // ci_fixture.php lee $argv[1]
    ob_start();
    require __DIR__ . '/ci_fixture.php';
    ob_end_clean();
};
$construirFixture($dbPath);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$GLOBALS['config'] = [
    'db_path' => $dbPath,
    'env' => 'local',            // habilita las escrituras (Db::assertWritable)
    'debug' => true,
    'secret_key' => str_repeat('x', 48),
    // Sin credenciales: es justo lo que exige una de las pruebas de abajo.
    'spotify_client_id' => '',
    'spotify_client_secret' => '',
];

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/tracklist_importador.json'), true);
if (!is_array($fixture)) {
    fwrite(STDERR, "No se pudo leer fixtures/tracklist_importador.json\n");
    exit(2);
}
/** @var array<string,list<array<string,mixed>>> $TRACKLISTS */
$TRACKLISTS = $fixture['tracklists'];

// ── Aserciones ───────────────────────────────────────────────────────────────
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

/** @param list<array<string,mixed>> $filas */
function fila(array $filas, int $idx): array
{
    foreach ($filas as $f) {
        if ((int) $f['idx'] === $idx) return $f;
    }
    throw new RuntimeException("no hay fila con idx=$idx");
}

/** Disco nuevo y vacío, para no arrastrar estado entre pruebas. */
function discoNuevo(string $nombre, ?int $banda = 1, ?string $anio = '2020'): int
{
    $r = AdminRepo::addDisco(['NOMBRE_CD' => $nombre, 'FECHA_CD' => $anio, 'BANDADISCO' => $banda]);
    if (($r['code'] ?? '') !== 'CREATED') throw new RuntimeException('no se pudo crear el disco de prueba: ' . ($r['code'] ?? '?'));
    return (int) $r['discoId'];
}

/** @return list<array<string,mixed>> pistas del disco, en orden de edición */
function pistasDe(int $idDisco): array
{
    return Db::all(
        'SELECT NUMEROMARCHA, N_DISCO, IDMARCHA, DURACION_SEG, PERCUSION
           FROM disco_marcha WHERE ID_DISCO = ? ORDER BY N_DISCO, NUMEROMARCHA',
        [$idDisco]
    );
}

$tests = [];

// ── 1. Reconocimiento del enlace ─────────────────────────────────────────────

$tests['parseUrl: álbum de Spotify'] = static function (): void {
    assertIgual(['servicio' => 'spotify', 'id' => '4aawyAB9vmqN3uQ7FjRGTy'],
        Tracklist::parseUrl('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy?si=abc'), 'spotify');
};
$tests['parseUrl: Spotify con prefijo de idioma (/intl-es/)'] = static function (): void {
    assertIgual(['servicio' => 'spotify', 'id' => '1DFixLWuPkv3KT3TnV35m3'],
        Tracklist::parseUrl('https://open.spotify.com/intl-es/album/1DFixLWuPkv3KT3TnV35m3'), 'spotify intl');
};
$tests['parseUrl: álbum de Deezer con y sin idioma'] = static function (): void {
    assertIgual(['servicio' => 'deezer', 'id' => '302127'],
        Tracklist::parseUrl('https://www.deezer.com/es/album/302127'), 'deezer con idioma');
    assertIgual(['servicio' => 'deezer', 'id' => '302127'],
        Tracklist::parseUrl('https://www.deezer.com/album/302127'), 'deezer sin idioma');
};
$tests['parseUrl: álbum de Apple Music, también desde el enlace de una pista'] = static function (): void {
    assertIgual(['servicio' => 'apple', 'id' => '1440857781'],
        Tracklist::parseUrl('https://music.apple.com/es/album/sevilla-cofrade/1440857781'), 'apple álbum');
    assertIgual(['servicio' => 'apple', 'id' => '1440857781'],
        Tracklist::parseUrl('https://music.apple.com/es/album/sevilla-cofrade/1440857781?i=1440857999'), 'apple pista del álbum');
};
$tests['parseUrl: lo que no es un álbum de streaming se rechaza'] = static function (): void {
    foreach ([
        'https://www.instagram.com/p/Cx1234/',
        'https://open.spotify.com/track/4aawyAB9vmqN3uQ7FjRGTy',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'no soy una url',
        '',
        null,
    ] as $url) {
        assertIgual(null, Tracklist::parseUrl($url), 'debería rechazar ' . var_export($url, true));
    }
};

// ── 2. Lectura del tracklist ─────────────────────────────────────────────────

$tests['de(): URL no reconocida no llama a nadie'] = static function (): void {
    Tracklist::$fetcher = static function (): array { throw new RuntimeException('no debería consultarse el servicio'); };
    $r = Tracklist::de('https://www.instagram.com/p/Cx1234/');
    assertIgual('URL_NO_RECONOCIDA', $r['error'], 'error');
    assertIgual([], $r['tracks'], 'tracks');
};

$tests['de(): un álbum sin pistas se reporta como SIN_PISTAS'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $r = Tracklist::de('https://music.apple.com/es/album/vacio/9999');
    assertIgual('SIN_PISTAS', $r['error'], 'error');
};

$tests['de(): las pistas salen ordenadas por volumen y número'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $r = Tracklist::de('https://open.spotify.com/album/4tXdesordenado');
    assertIgual(null, $r['error'], 'error');
    $orden = array_map(static fn(array $t): string => $t['disco'] . ':' . $t['n'], $r['tracks']);
    assertIgual(['1:1', '1:2', '2:1', '2:2'], $orden, 'orden de edición');
    assertIgual('La Madrugá', $r['tracks'][0]['titulo'], 'primera pista');
};

$tests['de(): Spotify sin credenciales avisa en vez de fallar'] = static function (): void {
    Tracklist::$fetcher = null;   // sin inyección → mira la config, que no las tiene
    $r = Tracklist::de('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy');
    assertIgual('SPOTIFY_SIN_CREDENCIALES', $r['error'], 'error');
    assertCierto(!Tracklist::disponible('spotify'), 'spotify no debería estar disponible sin credenciales');
    assertCierto(Tracklist::disponible('deezer'), 'deezer no necesita credenciales');
};

// ── 3. Similitud de títulos (el 80% del enunciado) ───────────────────────────

$tests['similitud: idéntico, tildes y sufijos de versión'] = static function (): void {
    assertIgual(1.0, Tracklist::similitud('Consuelo Gitano', 'Consuelo Gitano'), 'idéntico');
    assertCierto(Tracklist::similitud('La Madrugá', 'La Madruga') >= ImportadorPistas::UMBRAL, 'tildes');
    assertCierto(Tracklist::similitud('Cristo de la Sangre', 'Cristo de la Sangre - En Directo') >= ImportadorPistas::UMBRAL, 'sufijo en directo');
    assertCierto(Tracklist::similitud('Consuelo Gitano', 'Costalero Bueno') < ImportadorPistas::UMBRAL, 'marchas distintas');
};

// ── 4. Análisis: qué marcha es cada corte ────────────────────────────────────

$tests['analizar: reconoce, avisa de lo no reconocido y respeta el 1:1'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $idDisco = discoNuevo('Álbum de prueba · análisis');
    $r = Tracklist::de('https://www.deezer.com/es/album/1000');
    $filas = ImportadorPistas::analizar($idDisco, $r['tracks']);

    assertIgual(6, count($filas), 'nº de filas');

    // Exacta, con tilde de por medio y con sufijo de versión.
    assertIgual('reconocida', fila($filas, 0)['estado'], 'pista 1 (exacta)');
    assertIgual(1, fila($filas, 0)['idMarcha'], 'pista 1 → marcha');
    assertIgual('reconocida', fila($filas, 1)['estado'], 'pista 2 (sin tilde)');
    assertIgual(2, fila($filas, 1)['idMarcha'], 'pista 2 → marcha');
    assertIgual('reconocida', fila($filas, 2)['estado'], 'pista 3 (en directo)');
    assertIgual(4, fila($filas, 2)['idMarcha'], 'pista 3 → marcha');

    // Un corte que no está en el catálogo: se avisa y se deja sin marcha.
    $sinCoincidencia = fila($filas, 4);
    assertIgual('sin_coincidencia', $sinCoincidencia['estado'], 'pista 5 (inexistente)');
    assertIgual(null, $sinCoincidencia['idMarcha'], 'pista 5 sin marcha asignada');
    assertCierto(
        $sinCoincidencia['sugerencia'] === null || $sinCoincidencia['sugerencia']['score'] < ImportadorPistas::UMBRAL,
        'la sugerencia de una pista no reconocida tiene que estar por debajo del umbral'
    );

    // Dos cortes del mismo tema: la marcha solo puede ir a uno.
    assertIgual('duplicada', fila($filas, 5)['estado'], 'pista 6 (repetida)');
    assertIgual(null, fila($filas, 5)['idMarcha'], 'la pista repetida no se lleva la marcha');
    $conMarcha = array_filter($filas, static fn(array $f): bool => $f['idMarcha'] === 1);
    assertIgual(1, count($conMarcha), 'la marcha 1 solo puede estar asignada a un corte');
};

$tests['analizar: número, volumen y duración salen del álbum'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $idDisco = discoNuevo('Álbum de prueba · doble');
    $r = Tracklist::de('https://open.spotify.com/album/4tXdesordenado');
    $filas = ImportadorPistas::analizar($idDisco, $r['tracks']);

    $plan = array_map(
        static fn(array $f): array => [$f['volumen'], $f['n'], $f['seg']],
        $filas
    );
    assertIgual([[1, 1, 195], [1, 2, 208], [2, 1, 173], [2, 2, 181]], $plan, 'volumen/número/duración en orden');
};

$tests['analizar: marca lo que ya está en el disco y los números ocupados'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    // El disco 1 de la fixture ya tiene las marchas 1 y 2 en las pistas 1 y 2.
    $r = Tracklist::de('https://www.deezer.com/es/album/1000');
    $filas = ImportadorPistas::analizar(1, $r['tracks']);

    assertIgual('ya_en_disco', fila($filas, 0)['estado'], 'Consuelo Gitano ya está en el disco 1');
    assertIgual('ya_en_disco', fila($filas, 1)['estado'], 'La Madrugá ya está en el disco 1');
    assertCierto(fila($filas, 0)['ocupada'], 'la pista 1 del disco 1 está ocupada');
    assertCierto(!fila($filas, 3)['ocupada'], 'la pista 4 del disco 1 está libre');
    assertIgual('reconocida', fila($filas, 3)['estado'], 'Costalero Bueno sí se puede añadir');
};

$tests['analizar: un álbum vacío no propone nada'] = static function (): void {
    assertIgual([], ImportadorPistas::analizar(1, []), 'sin pistas');
};

$tests['candidatas: preselecciona por palabras significativas del título'] = static function (): void {
    $ids = array_map(static fn(array $m): int => (int) $m['ID_MARCHA'], ImportadorPistas::candidatas('La Madruga'));
    assertCierto(in_array(2, $ids, true), 'debería preseleccionar «La Madrugá» pese a la tilde');
    assertIgual([], ImportadorPistas::candidatas('   '), 'título vacío');
};

// ── 5. Escritura del plan aprobado ───────────────────────────────────────────

$tests['aplicar: escribe las pistas en orden, con duración y percusión'] = static function (): void {
    $idDisco = discoNuevo('Álbum de prueba · escritura');
    $r = ImportadorPistas::aplicar($idDisco, [
        // A propósito desordenadas: aplicar() las ordena antes de escribir.
        ['idMarcha' => 4, 'numero' => 2, 'volumen' => 1, 'seg' => 214, 'percusion' => 1, 'titulo' => 'Cristo de la Sangre'],
        ['idMarcha' => 1, 'numero' => 1, 'volumen' => 1, 'seg' => 208, 'percusion' => null, 'titulo' => 'Consuelo Gitano'],
        ['idMarcha' => 3, 'numero' => 1, 'volumen' => 2, 'seg' => null, 'percusion' => 0, 'titulo' => 'Costalero Bueno'],
    ]);
    assertIgual(3, $r['anadidas'], 'pistas añadidas');
    assertIgual([], $r['errores'], 'sin errores');

    $pistas = pistasDe($idDisco);
    assertIgual([1, 4, 3], array_map(static fn(array $p): int => (int) $p['IDMARCHA'], $pistas), 'orden de las pistas');
    assertIgual([208, 214, null], array_map(static fn(array $p): mixed => $p['DURACION_SEG'] === null ? null : (int) $p['DURACION_SEG'], $pistas), 'duraciones');
    // Percusión por pista: null = hereda del disco, 1/0 = excepción explícita.
    assertIgual([null, 1, 0], array_map(static fn(array $p): mixed => $p['PERCUSION'] === null ? null : (int) $p['PERCUSION'], $pistas), 'percusión');
    assertIgual([1, 1, 2], array_map(static fn(array $p): int => (int) $p['N_DISCO'], $pistas), 'volúmenes');
};

$tests['aplicar: ignora las filas sin marcha (las que el usuario no resolvió)'] = static function (): void {
    $idDisco = discoNuevo('Álbum de prueba · filas sin marcha');
    $r = ImportadorPistas::aplicar($idDisco, [
        ['idMarcha' => 1, 'numero' => 1, 'volumen' => 1, 'seg' => 208, 'percusion' => null, 'titulo' => 'Consuelo Gitano'],
        ['idMarcha' => 0, 'numero' => 2, 'volumen' => 1, 'seg' => 47, 'percusion' => null, 'titulo' => 'Preludio de Tambores'],
    ]);
    assertIgual(1, $r['anadidas'], 'solo se añade la que tiene marcha');
    assertIgual([], $r['errores'], 'una fila sin marcha no es un error');
    assertIgual(1, count(pistasDe($idDisco)), 'pistas en el disco');
};

$tests['aplicar: informa de cada pista que rechaza AdminRepo y sigue con el resto'] = static function (): void {
    $idDisco = discoNuevo('Álbum de prueba · conflictos');
    AdminRepo::addPista($idDisco, 1, 1, 1, 208, null);        // la marcha 1 ya está en el disco

    $r = ImportadorPistas::aplicar($idDisco, [
        ['idMarcha' => 1, 'numero' => 5, 'volumen' => 1, 'seg' => 208, 'percusion' => null, 'titulo' => 'Consuelo Gitano'],
        ['idMarcha' => 2, 'numero' => 1, 'volumen' => 1, 'seg' => 195, 'percusion' => null, 'titulo' => 'La Madrugá'],
        ['idMarcha' => 4, 'numero' => 3, 'volumen' => 1, 'seg' => 214, 'percusion' => null, 'titulo' => 'Cristo de la Sangre'],
        ['idMarcha' => 999, 'numero' => 9, 'volumen' => 1, 'seg' => 100, 'percusion' => null, 'titulo' => 'Marcha inexistente'],
    ]);

    assertIgual(1, $r['anadidas'], 'solo la pista 3 es válida');
    $codigos = array_map(static fn(array $e): string => $e['code'], $r['errores']);
    assertCierto(in_array('MARCHA_YA_EN_DISCO', $codigos, true), 'debería avisar de la marcha repetida');
    assertCierto(in_array('PISTA_OCUPADA', $codigos, true), 'debería avisar del número de pista ocupado');
    assertCierto(in_array('MARCHA_NO_EXISTE', $codigos, true), 'debería avisar de la marcha inexistente');
    $titulosConError = array_map(static fn(array $e): string => $e['titulo'], $r['errores']);
    assertCierto(!in_array('Cristo de la Sangre', $titulosConError, true), 'la pista válida no puede estar entre los errores');
    assertIgual(2, count(pistasDe($idDisco)), 'la que ya estaba más la válida');
};

// ── 6. Recorrido completo: enlace → plan → disco ─────────────────────────────

$tests['de extremo a extremo: del enlace del álbum a las pistas del disco'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $idDisco = discoNuevo('Álbum de prueba · extremo a extremo');

    $r = Tracklist::de('https://www.deezer.com/es/album/1000');
    $filas = ImportadorPistas::analizar($idDisco, $r['tracks']);

    // Lo que haría el usuario: confirmar lo reconocido tal cual.
    $aprobadas = [];
    foreach ($filas as $f) {
        if ($f['estado'] !== 'reconocida') continue;
        $aprobadas[] = [
            'idMarcha' => $f['idMarcha'], 'numero' => $f['n'], 'volumen' => $f['volumen'],
            'seg' => $f['seg'], 'percusion' => null, 'titulo' => $f['titulo'],
        ];
    }
    $res = ImportadorPistas::aplicar($idDisco, $aprobadas);

    assertIgual(4, $res['anadidas'], 'pistas añadidas');
    assertIgual([], $res['errores'], 'sin errores');
    $pistas = pistasDe($idDisco);
    assertIgual([1, 2, 3, 4], array_map(static fn(array $p): int => (int) $p['NUMEROMARCHA'], $pistas), 'números de pista');
    assertIgual([1, 2, 4, 3], array_map(static fn(array $p): int => (int) $p['IDMARCHA'], $pistas), 'marchas, en el orden del álbum');
    assertIgual([208, 195, 214, 181], array_map(static fn(array $p): int => (int) $p['DURACION_SEG'], $pistas), 'duraciones');

    // Segunda pasada del mismo enlace: ya no propone nada nuevo.
    $filas2 = ImportadorPistas::analizar($idDisco, $r['tracks']);
    $reconocidas2 = array_filter($filas2, static fn(array $f): bool => $f['estado'] === 'reconocida');
    assertIgual([], array_values($reconocidas2), 'reimportar el mismo álbum no debe volver a proponer las mismas pistas');
};

// ── 7. Pantalla de revisión ──────────────────────────────────────────────────
// El HTML es parte del contrato: si un campo del formulario cambia de nombre,
// el paso de confirmación deja de recibir esa columna en silencio.

$tests['pantalla de revisión: campos del formulario, avisos y alta de la marcha que falta'] = static function () use ($TRACKLISTS): void {
    Tracklist::$fetcher = static fn(string $s, string $id): array => $TRACKLISTS["$s:$id"] ?? [];
    $idDisco = discoNuevo('Álbum de prueba · pantalla');
    $r = Tracklist::de('https://www.deezer.com/es/album/1000');
    $data = AdminRepo::discoConPistas($idDisco);

    $html = App\View::capture('admin/disco_importar', [
        'session' => ['user' => 'tester', 'jti' => 'jti-de-prueba'],
        'disco' => $data['disco'],
        'pistas' => $data['pistas'],
        'fase' => 'revision',
        'url' => 'https://www.deezer.com/es/album/1000',
        'servicio' => 'deezer',
        'filas' => ImportadorPistas::analizar($idDisco, $r['tracks']),
        'error' => null,
        'creado' => false,
    ]);

    // Una fila por corte, con todos los campos que lee discoImportarConfirmar.
    foreach (['[titulo]', '[seg]', '[add]', '[numero]', '[volumen]', '[idMarcha]', '[percusion]'] as $campo) {
        assertCierto(str_contains($html, 'name="p[0]' . $campo), "falta el campo p[0]$campo en el formulario");
    }
    assertIgual(6, substr_count($html, 'data-marcha-picker-edit'), 'un buscador de marcha por corte');

    // Lo reconocido llega marcado y con su marcha puesta; lo demás, no.
    assertIgual(1, preg_match('/name="p\[0\]\[idMarcha\]"\s+value="1"/', $html), 'la pista 1 debería traer la marcha 1 preseleccionada');
    assertIgual(4, preg_match_all('/name="p\[\d+\]\[add\]" value="1" checked/', $html), 'solo las 4 pistas reconocidas llegan marcadas');
    assertIgual(0, preg_match('/name="p\[5\]\[add\]" value="1" checked/', $html), 'la pista repetida no puede llegar marcada');

    // Avisos: lo no reconocido, lo repetido y la duración legible.
    assertCierto(str_contains($html, 'Sin coincidencia en el catálogo'), 'falta el aviso de pistas no reconocidas');
    assertCierto(str_contains($html, 'Preludio de Tambores'), 'el aviso debe nombrar la pista no reconocida');
    assertCierto(str_contains($html, 'Otra pista ya se lleva'), 'falta el aviso de pista repetida');
    assertCierto(str_contains($html, '>3:28<'), 'la duración debería salir como mm:ss');

    // Alta de la marcha que falta: prerrellenada con lo que ya se sabe del disco
    // y con la vuelta a esta misma pantalla.
    assertCierto(str_contains($html, 'href="/dashboard/marcha/add?TITULO=Preludio+de+Tambores'), 'falta el enlace de alta con el título');
    assertCierto(str_contains($html, 'FECHA=2020'), 'el alta debería llevar el año del disco');
    assertCierto(str_contains($html, 'BANDA_ESTRENO=1'), 'el alta debería llevar la banda del disco');
    assertCierto(str_contains($html, 'volver=%2Fdashboard%2Fdisco%2F' . $idDisco . '%2Fimportar'), 'el alta debería volver al importador');
};

$tests['pantalla del enlace: explica el error y deja seguir a mano'] = static function (): void {
    $data = AdminRepo::discoConPistas(1);
    $html = App\View::capture('admin/disco_importar', [
        'session' => ['user' => 'tester', 'jti' => 'jti-de-prueba'],
        'disco' => $data['disco'], 'pistas' => $data['pistas'],
        'fase' => 'url', 'url' => 'https://www.instagram.com/p/Cx1234/',
        'servicio' => null, 'filas' => [], 'error' => 'URL_NO_RECONOCIDA', 'creado' => false,
    ]);
    assertCierto(str_contains($html, 'No reconozco ese enlace'), 'el error tiene que explicarse en cristiano');
    assertCierto(str_contains($html, '/dashboard/disco/1?tab=pistas'), 'siempre tiene que haber salida al alta manual');
};

// ── Runner ───────────────────────────────────────────────────────────────────
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

if ($argc < 2) {
    @unlink($dbPath);
    @unlink($dbPath . '-shm');
    @unlink($dbPath . '-wal');
}

if ($failed !== []) {
    fwrite(STDERR, "\nFallos:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
