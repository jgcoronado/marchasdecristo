<?php

declare(strict_types=1);

/*
 * Pruebas de la cascada automática de enlaces de streaming (App\EnlacesAuto):
 * al guardar el enlace de un álbum, el panel completa el resto de servicios del
 * DISCO, los de cada MARCHA del disco y los de su BANDA.
 *
 * Toda la red se sustituye por fixtures/enlaces_auto.json a través de
 * EnlacesAuto::$red, así que estas pruebas no llaman a Odesli, Spotify, Apple ni
 * Deezer, y no necesitan credenciales.
 *
 * Lo que se vigila aquí, por orden de importancia:
 *   · no pisar nunca un enlace ya existente (idempotencia);
 *   · publicar solo lo que es identidad y mandar lo dudoso a la cola de
 *     curación, no a la ficha pública;
 *   · que un álbum que Odesli agrupa mal no contamine el catálogo.
 *
 * Uso: php php/tools/ci_enlaces_auto.php [ruta .db temporal]
 */

use App\AdminRepo;
use App\Db;
use App\EnlacesAuto;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

$F = json_decode((string) file_get_contents(__DIR__ . '/fixtures/enlaces_auto.json'), true);
if (!is_array($F)) {
    fwrite(STDERR, "No se pudo leer fixtures/enlaces_auto.json\n");
    exit(2);
}

/**
 * Red simulada. Devuelve exactamente lo que devolvería cada servicio, y cuenta
 * las llamadas para poder afirmar que no se consulta lo que no hace falta.
 *
 * @var array<string,int> $LLAMADAS
 */
$LLAMADAS = [];
EnlacesAuto::$red = static function (string $op, array $args) use ($F, &$LLAMADAS): mixed {
    $LLAMADAS[$op] = ($LLAMADAS[$op] ?? 0) + 1;
    $clave = ((string) ($args['servicio'] ?? '')) . ':' . ((string) ($args['id'] ?? ''));
    return match ($op) {
        'odesli' => $F['odesli'][(string) $args['url']] ?? null,
        'tracklist' => $F['tracklists'][$clave] ?? [],
        'album' => $F['album'][$clave] ?? [],
        'album_upc' => $F['album_upc'][((string) $args['servicio']) . ':' . ((string) $args['upc'])] ?? null,
        'isrcs' => $F['isrcs'] ?? [],
        default => null,
    };
};

// ── Utilidades del banco de pruebas ──────────────────────────────────────────

/** Disco de pruebas con su enlace semilla ya guardado y las pistas que se le pasen. */
function discoConEnlace(string $nombre, ?string $urlSemilla, array $marchas = [], ?int $banda = 1, string $anio = '1996'): int
{
    $r = AdminRepo::addDisco(['NOMBRE_CD' => $nombre, 'FECHA_CD' => $anio, 'BANDADISCO' => $banda]);
    if (($r['code'] ?? '') !== 'CREATED') throw new RuntimeException('no se pudo crear el disco: ' . ($r['code'] ?? '?'));
    $id = (int) $r['discoId'];

    $n = 1;
    foreach ($marchas as $idMarcha) {
        AdminRepo::addPista($id, (int) $idMarcha, $n++, 1);
    }
    if ($urlSemilla !== null) {
        AdminRepo::setEnlaceStreaming('disco', $id, 'spotify', $urlSemilla);
    }
    return $id;
}

/**
 * Marcha nueva con el título que se le pase. Las pruebas que comprueban que SE
 * PUBLICA algo usan marchas recién creadas: las de la fixture las comparten
 * todas las pruebas (y la marcha 1 ya nace con un enlace de Spotify), así que
 * afirmar sobre ellas mediría el rastro de la prueba anterior.
 */
function marchaNueva(string $titulo): int
{
    $r = AdminRepo::addMarcha(['TITULO' => $titulo, 'TIPO' => 'MARCHA PROCESIONAL'], [1]);
    if (($r['code'] ?? '') !== 'CREATED') throw new RuntimeException('no se pudo crear la marcha: ' . ($r['code'] ?? '?'));
    return (int) $r['marchaId'];
}

/** Banda nueva, por el mismo motivo que marchaNueva(). */
function bandaNueva(string $breve, string $completo): int
{
    $r = AdminRepo::addBanda(['NOMBRE_BREVE' => $breve, 'NOMBRE_COMPLETO' => $completo]);
    if (($r['code'] ?? '') !== 'CREATED') throw new RuntimeException('no se pudo crear la banda: ' . ($r['code'] ?? '?'));
    return (int) $r['bandaId'];
}

/** @return array<string,string> servicio => URL publicada */
function publicados(string $tipo, int $id): array
{
    $out = [];
    foreach (Db::all('SELECT SERVICIO, URL FROM enlace_streaming WHERE TIPO_ENT = ? AND ID_ENT = ? ORDER BY SERVICIO', [$tipo, $id]) as $r) {
        $out[(string) $r['SERVICIO']] = (string) $r['URL'];
    }
    return $out;
}

/** @return list<array<string,mixed>> candidatos pendientes de una entidad */
function candidatos(string $tipo, int $id): array
{
    return Db::all('SELECT SERVICIO, URL, SCORE, CONFIANZA, ESTADO FROM enlace_candidato WHERE TIPO_ENT = ? AND ID_ENT = ? ORDER BY SERVICIO', [$tipo, $id]);
}

const SEMILLA = 'https://open.spotify.com/album/SP1';

$tests = [];

// ── 1. Nivel disco ───────────────────────────────────────────────────────────

$tests['disco: completa el resto de servicios de la misma publicación'] = static function (): void {
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA);
    $r = EnlacesAuto::paraDisco($id);

    $enlaces = publicados('disco', $id);
    assertIgual(['amazon', 'apple', 'deezer', 'spotify', 'tidal', 'youtube'], array_keys($enlaces), 'servicios del disco');
    assertIgual('https://www.deezer.com/album/DZ1', $enlaces['deezer'], 'enlace de Deezer');
    assertIgual(SEMILLA, $enlaces['spotify'], 'la semilla sigue intacta');
    assertIgual([], candidatos('disco', $id), 'nada dudoso que curar');
    assertIgual([], $r['disco']['sin'], 'ningún servicio se queda fuera');
};

$tests['disco: no pisa un enlace ya puesto y repetir la cascada no cambia nada'] = static function (): void {
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA);
    // Enlace curado a mano que NO es el que devolvería Odesli.
    AdminRepo::setEnlaceStreaming('disco', $id, 'apple', 'https://music.apple.com/es/album/curado-a-mano/999');

    EnlacesAuto::paraDisco($id);
    $primera = publicados('disco', $id);
    assertIgual('https://music.apple.com/es/album/curado-a-mano/999', $primera['apple'], 'el enlace curado a mano manda');

    $r2 = EnlacesAuto::paraDisco($id);
    assertIgual($primera, publicados('disco', $id), 'la segunda pasada no cambia nada');
    assertIgual([], $r2['disco']['nuevos'], 'la segunda pasada no publica nada nuevo');
};

$tests['disco: la semilla puede ser Deezer y de ahí sale también Spotify'] = static function (): void {
    // Caso real del panel: se pega el enlace que la banda comparte, que no
    // siempre es el de Spotify. El resto —Spotify incluido— se deriva igual.
    $r = AdminRepo::addDisco(['NOMBRE_CD' => 'Sevilla Cofrade Vol. 1', 'FECHA_CD' => '1996', 'BANDADISCO' => 1]);
    $id = (int) $r['discoId'];
    AdminRepo::setEnlaceStreaming('disco', $id, 'deezer', 'https://www.deezer.com/album/DZ1');

    EnlacesAuto::paraDisco($id);
    $enlaces = publicados('disco', $id);
    assertIgual('https://open.spotify.com/album/SP1', $enlaces['spotify'] ?? '', 'Spotify derivado de la semilla de Deezer');
    assertIgual('https://music.apple.com/es/album/sevilla-cofrade-vol-1/AP1', $enlaces['apple'] ?? '', 'Apple derivado');
};

$tests['disco: si el título no se parece, va a curación y no a la ficha'] = static function (): void {
    // Mismo álbum en el fixture, pero el disco de la BD se llama de otra forma:
    // es la señal de que el enlace pegado no es de este disco.
    $id = discoConEnlace('Recopilatorio de otra cosa totalmente distinta', SEMILLA);
    $r = EnlacesAuto::paraDisco($id);

    assertIgual(['spotify'], array_keys(publicados('disco', $id)), 'no se publica nada más que la semilla');
    assertCierto(count(candidatos('disco', $id)) >= 3, 'lo encontrado tiene que quedar en la cola de curación');
    assertIgual([], $r['disco']['nuevos'], 'ningún enlace publicado');
    foreach (candidatos('disco', $id) as $c) {
        assertIgual('pendiente', $c['ESTADO'], 'los candidatos nacen pendientes');
    }
};

$tests['disco: si Odesli devuelve otra publicación, nada se publica'] = static function (): void {
    // El fixture responde con un id de Spotify distinto del enlazado: álbum mal
    // agrupado (reediciones, recopilatorios). El título sí coincide, así que sin
    // esta comprobación se publicaría un enlace equivocado.
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', 'https://open.spotify.com/album/SPOTRO');
    $r = EnlacesAuto::paraDisco($id);

    assertIgual(['spotify'], array_keys(publicados('disco', $id)), 'no se publica nada');
    assertCierto($r['disco']['candidatos'] !== [], 'todo debería ir a curación');
    assertCierto(
        (bool) array_filter($r['avisos'], static fn(string $a): bool => str_contains($a, 'otra publicación')),
        'y avisarse en pantalla'
    );
};

$tests['disco: sin Odesli, el código de barras repesca Apple y Deezer'] = static function () use ($F): void {
    // Odesli sin respuesta (su cobertura es irregular). El UPC del álbum es el
    // mismo número en los tres catálogos: sigue siendo identidad, no búsqueda.
    $red = EnlacesAuto::$red;
    EnlacesAuto::$red = static function (string $op, array $args) use ($red): mixed {
        return $op === 'odesli' ? null : $red($op, $args);
    };
    try {
        $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA);
        EnlacesAuto::paraDisco($id);
        $enlaces = publicados('disco', $id);
        assertIgual('https://music.apple.com/es/album/sevilla-cofrade-vol-1/AP2', $enlaces['apple'] ?? '', 'Apple por UPC');
        assertIgual('https://www.deezer.com/album/DZ2', $enlaces['deezer'] ?? '', 'Deezer por UPC');
    } finally {
        EnlacesAuto::$red = $red;
    }
};

$tests['disco: sin ningún enlace del que partir, no se toca nada'] = static function () use (&$LLAMADAS): void {
    $id = discoConEnlace('Disco sin enlaces', null);
    $antes = $LLAMADAS;
    $r = EnlacesAuto::paraDisco($id);
    assertIgual([], publicados('disco', $id), 'sigue sin enlaces');
    assertIgual($antes, $LLAMADAS, 'no debería consultarse ningún servicio');
    assertCierto($r['avisos'] !== [], 'y debería decir por qué no ha hecho nada');
};

// ── 2. Nivel marcha ──────────────────────────────────────────────────────────

$tests['marchas: cada pista se lleva su enlace en cada servicio con tracklist'] = static function (): void {
    $consuelo = marchaNueva('Consuelo Gitano');     // en los tres tracklists del fixture
    $madruga = marchaNueva('La Madrugá');           // solo en Spotify y Apple
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [$consuelo, $madruga]);
    $r = EnlacesAuto::paraDisco($id);

    assertIgual(['apple', 'deezer', 'spotify'], array_keys(publicados('marcha', $consuelo)), 'servicios de la primera marcha');
    assertIgual('https://open.spotify.com/track/sptr1', publicados('marcha', $consuelo)['spotify'], 'enlace de pista de Spotify');
    assertIgual('https://www.deezer.com/track/dztr1', publicados('marcha', $consuelo)['deezer'], 'enlace de pista de Deezer');
    assertIgual(['apple', 'spotify'], array_keys(publicados('marcha', $madruga)), 'servicios de la segunda marcha');
    assertIgual(5, $r['marchas']['enlaces'], 'enlaces de marcha publicados');
};

$tests['marchas: el ISRC de la grabación se guarda cuando el servicio lo da'] = static function (): void {
    $consuelo = marchaNueva('Consuelo Gitano');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [$consuelo]);
    EnlacesAuto::paraDisco($id);

    // Spotify no da el ISRC en el tracklist del álbum: exige una llamada aparte
    // (isrcsSpotify). Deezer sí lo trae de serie.
    $sp = Db::one("SELECT ISRC FROM enlace_streaming WHERE TIPO_ENT='marcha' AND ID_ENT=? AND SERVICIO='spotify'", [$consuelo]);
    $dz = Db::one("SELECT ISRC FROM enlace_streaming WHERE TIPO_ENT='marcha' AND ID_ENT=? AND SERVICIO='deezer'", [$consuelo]);
    assertIgual('ESAAA0000001', (string) ($sp['ISRC'] ?? ''), 'ISRC de la pista de Spotify (R-01)');
    assertIgual('ESAAA0000001', (string) ($dz['ISRC'] ?? ''), 'ISRC de la pista de Deezer');
};

$tests['marchas: rellena la duración de la grabación solo si estaba vacía'] = static function (): void {
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [1]);
    // La segunda pista entra con una duración medida a mano: no debe tocarse.
    AdminRepo::addPista($id, 2, 2, 1, 999, null);

    $r = EnlacesAuto::paraDisco($id);
    $filas = Db::all('SELECT IDMARCHA, DURACION_SEG FROM disco_marcha WHERE ID_DISCO = ? ORDER BY NUMEROMARCHA', [$id]);
    assertIgual(208, (int) $filas[0]['DURACION_SEG'], 'la que faltaba se rellena del tracklist');
    assertIgual(999, (int) $filas[1]['DURACION_SEG'], 'la medida a mano no se pisa');
    assertIgual(1, $r['marchas']['duraciones'], 'solo cuenta la que ha rellenado');
};

$tests['marchas: una coincidencia floja no se publica, se encola'] = static function (): void {
    // «Cristo de la Sangre» (marcha 4) contra «Cristo de la Sangre y Agua» del
    // álbum: 0.77, por debajo del 0.85 de identidad pero por encima del 0.60 que
    // merece una mirada humana.
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [4]);
    $r = EnlacesAuto::paraDisco($id);

    assertIgual([], publicados('marcha', 4), 'no se publica un enlace que no es seguro');
    $cand = candidatos('marcha', 4);
    assertIgual(1, count($cand), 'debería quedar un candidato para curar');
    assertCierto((float) $cand[0]['SCORE'] < EnlacesAuto::MIN_SIM_PISTA, 'y por debajo del umbral de identidad');
    assertIgual(1, $r['marchas']['sin_match'], 'contada como pista sin emparejar');
};

$tests['marchas: un enlace de marcha ya existente no se pisa'] = static function (): void {
    AdminRepo::setEnlaceStreaming('marcha', 5, 'spotify', 'https://open.spotify.com/track/curado-a-mano');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [5, 1]);
    EnlacesAuto::paraDisco($id);
    assertIgual('https://open.spotify.com/track/curado-a-mano', publicados('marcha', 5)['spotify'], 'el enlace curado sigue ahí');
};

// ── 3. Nivel banda ───────────────────────────────────────────────────────────

$tests['banda: el artista del álbum rellena los servicios que le faltan'] = static function (): void {
    $banda = bandaNueva('Las Cigarreras', 'Banda de CCTT Ntra. Sra. de la Victoria (Las Cigarreras)');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [], $banda);
    $r = EnlacesAuto::paraDisco($id);

    $enlaces = publicados('banda', $banda);
    // Spotify y Deezer acreditan el álbum a la banda (nombre parecido) → se publican.
    assertIgual('https://open.spotify.com/artist/SPART1', $enlaces['spotify'] ?? '', 'artista de Spotify');
    assertIgual('https://www.deezer.com/artist/DZART1', $enlaces['deezer'] ?? '', 'artista de Deezer');
    assertCierto(in_array('spotify', $r['banda']['nuevos'], true), 'el resumen lo refleja');
};

$tests['banda: un álbum acreditado a «Various Artists» no le cuelga a la banda'] = static function (): void {
    $banda = bandaNueva('Las Cigarreras', 'Banda de CCTT Ntra. Sra. de la Victoria (Las Cigarreras)');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [], $banda);
    EnlacesAuto::paraDisco($id);

    assertCierto(!isset(publicados('banda', $banda)['apple']), 'el artista genérico de Apple no se publica');
    $cand = array_values(array_filter(candidatos('banda', $banda), static fn(array $c): bool => $c['SERVICIO'] === 'apple'));
    assertIgual(1, count($cand), 'queda en la cola de curación');
    assertCierto((float) $cand[0]['SCORE'] < EnlacesAuto::MIN_SIM_BANDA, 'con su puntuación por debajo del umbral');
};

$tests['banda: un enlace de banda ya puesto no se pisa'] = static function (): void {
    $banda = bandaNueva('Las Cigarreras', 'Banda de CCTT Las Cigarreras');
    AdminRepo::setEnlaceStreaming('banda', $banda, 'spotify', 'https://open.spotify.com/artist/CURADO');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [], $banda);
    EnlacesAuto::paraDisco($id);
    assertIgual('https://open.spotify.com/artist/CURADO', publicados('banda', $banda)['spotify'], 'el perfil curado a mano manda');
};

$tests['banda: un disco sin banda propietaria no inventa ninguna'] = static function (): void {
    $id = discoConEnlace('Recopilatorio sin banda', SEMILLA, [], null);
    $r = EnlacesAuto::paraDisco($id);
    assertIgual(null, $r['banda']['id'], 'no hay banda que completar');
};

// ── 4. Resumen para el panel ─────────────────────────────────────────────────

$tests['resumen: dice lo que ha hecho, y calla cuando no ha hecho nada'] = static function (): void {
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [marchaNueva('Consuelo Gitano')]);
    $texto = EnlacesAuto::resumen(EnlacesAuto::paraDisco($id));
    assertCierto($texto !== null, 'la primera pasada tiene algo que contar');
    assertCierto(str_contains((string) $texto, 'disco:'), 'menciona los servicios del disco');
    assertCierto(str_contains((string) $texto, 'enlaces de marcha'), 'menciona los enlaces de marcha');

    // Segunda pasada: ya no queda nada que añadir → nada que decir.
    assertIgual(null, EnlacesAuto::resumen(EnlacesAuto::paraDisco($id)), 'la segunda pasada no dice nada');
};

$tests['resumen: los dudosos se anuncian con su sitio de curación'] = static function (): void {
    $id = discoConEnlace('Recopilatorio de otra cosa totalmente distinta', SEMILLA);
    $texto = (string) EnlacesAuto::resumen(EnlacesAuto::paraDisco($id));
    assertCierto(str_contains($texto, '/dashboard/enlaces'), 'debería decir dónde se curan');
};

// ── 5. Bases sin la UNIQUE (las anteriores a la migración 010) ───────────────
//
// Van al final a propósito: rehacen `enlace_streaming` sin su restricción de
// unicidad y ese cambio se queda hecho para lo que venga detrás.
//
// El caso es real: si las tablas ya existían cuando se aplicó
// 004_enlace_streaming.sql, su `CREATE TABLE IF NOT EXISTS` no hizo nada y la
// UNIQUE nunca llegó. Ahí el UPSERT de setEnlaceStreaming reventaba («ON
// CONFLICT clause does not match any PRIMARY KEY or UNIQUE constraint») y un
// INSERT OR IGNORE habría ido acumulando duplicados sin decir nada.

/** Deja `enlace_streaming` como en una base anterior a la migración 010. */
function sinRestriccionDeUnicidad(): void
{
    Db::run('ALTER TABLE enlace_streaming RENAME TO enlace_streaming_legacy');
    Db::run('CREATE TABLE enlace_streaming (
        ID_ENLACE INTEGER PRIMARY KEY, TIPO_ENT TEXT, ID_ENT INTEGER, SERVICIO TEXT, URL TEXT,
        ID_EXT TEXT, ISRC TEXT,
        VERSION TEXT NOT NULL DEFAULT \'actual\', ANIO INTEGER, VERSION_AUTO INTEGER NOT NULL DEFAULT 1,
        VERIFICADO INTEGER NOT NULL DEFAULT 1,
        FECHA_ALTA TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    // Lista explícita de columnas, no SELECT *: la tabla ha ido acumulando
    // columnas (ISRC, y ahora las de versión) y el orden no tiene por qué
    // coincidir entre la base real y la de este espejo.
    Db::run('INSERT INTO enlace_streaming
                 (ID_ENLACE, TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, ISRC, VERSION, ANIO, VERSION_AUTO, VERIFICADO, FECHA_ALTA)
             SELECT ID_ENLACE, TIPO_ENT, ID_ENT, SERVICIO, URL, ID_EXT, ISRC, VERSION, ANIO, VERSION_AUTO, VERIFICADO, FECHA_ALTA
             FROM enlace_streaming_legacy');
    Db::run('DROP TABLE enlace_streaming_legacy');
}

$tests['base sin UNIQUE: guardar un enlace no revienta y no duplica'] = static function (): void {
    sinRestriccionDeUnicidad();

    $id = discoConEnlace('Sevilla Cofrade Vol. 1', null, [], 1);
    // Esto es lo que devolvía el 500 en la pantalla de importación.
    AdminRepo::setEnlaceStreaming('disco', $id, 'spotify', SEMILLA);
    AdminRepo::setEnlaceStreaming('disco', $id, 'spotify', 'https://open.spotify.com/album/SP1?si=otra');

    $filas = Db::all("SELECT URL FROM enlace_streaming WHERE TIPO_ENT='disco' AND ID_ENT=? AND SERVICIO='spotify'", [$id]);
    assertIgual(1, count($filas), 'guardar dos veces el mismo servicio deja UNA fila');
    assertIgual('https://open.spotify.com/album/SP1?si=otra', (string) $filas[0]['URL'], 'y con el último valor');
};

$tests['base sin UNIQUE: la cascada sigue siendo idempotente'] = static function (): void {
    $marcha = marchaNueva('Consuelo Gitano');
    $id = discoConEnlace('Sevilla Cofrade Vol. 1', SEMILLA, [$marcha]);

    EnlacesAuto::paraDisco($id);
    $primera = publicados('disco', $id);
    $r2 = EnlacesAuto::paraDisco($id);

    assertIgual($primera, publicados('disco', $id), 'la segunda pasada no añade nada');
    assertIgual([], $r2['disco']['nuevos'], 'ni lo cree');
    $porServicio = Db::all("SELECT SERVICIO, COUNT(*) n FROM enlace_streaming WHERE TIPO_ENT='disco' AND ID_ENT=? GROUP BY SERVICIO", [$id]);
    foreach ($porServicio as $fila) {
        assertIgual(1, (int) $fila['n'], 'un solo enlace por servicio (' . $fila['SERVICIO'] . ')');
    }
    $marchas = Db::all("SELECT SERVICIO, COUNT(*) n FROM enlace_streaming WHERE TIPO_ENT='marcha' AND ID_ENT=? GROUP BY SERVICIO", [$marcha]);
    foreach ($marchas as $fila) {
        assertIgual(1, (int) $fila['n'], 'y un solo enlace por servicio en la marcha (' . $fila['SERVICIO'] . ')');
    }
};

$tests['migración 010: crea la unicidad que faltaba'] = static function (): void {
    $indices = static fn(): array => array_column(
        Db::all("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'ux_enlace%'"), 'name'
    );
    assertIgual([], $indices(), 'la tabla recreada no tiene el índice');

    // Se aplica el .sql tal cual lo aplicaría migrate_ingest.php.
    foreach (explode(";\n", (string) file_get_contents(APP_DIR . '/tools/sql/010_enlace_unicos.sql')) as $sentencia) {
        $sentencia = trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $sentencia));
        if ($sentencia === '') continue;
        Db::run($sentencia);
    }
    assertIgual(['ux_enlace_streaming_ent_srv_ver', 'ux_enlace_candidato_ent_srv_url'], $indices(), 'índices creados');

    // Y con el índice puesto, la base también rechaza el duplicado por su cuenta.
    $fallo = false;
    try {
        Db::run("INSERT INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL) VALUES ('disco', 1, 'spotify', 'https://x')");
        Db::run("INSERT INTO enlace_streaming (TIPO_ENT, ID_ENT, SERVICIO, URL) VALUES ('disco', 1, 'spotify', 'https://y')");
    } catch (PDOException) {
        $fallo = true;
    }
    assertCierto($fallo, 'la base debería rechazar el segundo enlace del mismo servicio');
};

// ── 6. El script de pasada masiva ────────────────────────────────────────────

$tests['fill_enlaces_cascada: arranca y respeta el fail-safe de solo-lectura'] = static function () use ($dbPath): void {
    $script = APP_DIR . '/tools/fill_enlaces_cascada.php';
    assertCierto(is_file($script), 'el script tiene que existir');

    // Sin config.local.php el entorno es 'production': el script debe negarse a
    // escribir, igual que Db::assertWritable. Comprueba de paso que arranca
    // (autoload, constantes y config) sin tocar la red ni la BD.
    $salida = [];
    $codigo = 0;
    exec('DB_PATH=' . escapeshellarg($dbPath) . ' php ' . escapeshellarg($script) . ' --disco=1 2>&1', $salida, $codigo);
    $texto = implode("\n", $salida);

    assertIgual(1, $codigo, "el script debería salir con error fuera de local. Salida:\n$texto");
    assertCierto(str_contains($texto, "'local'"), 'y explicar que solo el entorno local escribe');
};

$salida = ciEjecuta($tests);
if ($argc < 2) ciLimpia($dbPath);
exit($salida);
