<?php

declare(strict_types=1);

/*
 * Pruebas del circuito de propuestas (PropuestaRepo + PropuestaOps).
 *
 * Por qué importa: solo local escribe en la BD, que es de donde sale el .db
 * bueno. El editor NO escribe nunca: lo que envía se guarda como propuesta y
 * el admin la aplica en local. El admin fuera de local ni eso: solo mira. Si
 * proponer escribiera algo, o el admin pudiera guardar en PRE/PRO, el cambio
 * se perdería en el siguiente sync; si aceptar hiciera algo distinto del
 * guardado directo, o una aceptación fallida se diera por buena, se perderían
 * o duplicarían cambios sin que nadie lo viera. Y si el editor no ve en qué
 * quedó lo que propuso (también en producción, donde no están los ficheros
 * resueltos), lo vuelve a pedir.
 *
 * Uso: php php/tools/ci_propuestas.php [directorio temporal]
 */

use App\Admin;
use App\Auth;
use App\Db;
use App\NominaRepo;
use App\PropuestaOps;
use App\PropuestaRepo;
use App\Roles;

require __DIR__ . '/ci_boot.php';

// Directorio propio: las propuestas viven junto al .db (dirname(db_path)/propuestas)
// y no pueden mezclarse con las de otra ejecución en el temp compartido.
$dir = $argv[1] ?? sys_get_temp_dir() . '/ci-propuestas-' . getmypid();
@mkdir($dir, 0775, true);
$dbPath = ciBoot($dir . '/mdc.db');
// Las portadas instaladas tampoco pueden caer en el php/public/cover del repo.
define('PUBLIC_DIR', $dir . '/public');

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['013_acompanamiento_duda.sql', '015_semana_santa_dia.sql', '016_ida_vuelta.sql', '017_acompanamiento_pendiente.sql',
          '019_localidad_provincia.sql', '020_hermandad_varios_dias.sql', '021_banda_etapa.sql'] as $sql) {
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/app/tools/sql/' . $sql));
}
$pdo = null;

$idDia = NominaRepo::addDia('Sevilla', 'Jueves Santo')['id'];
$idHerm = NominaRepo::addHermandad('Sevilla', $idDia, 'El Silencio')['id'];
$idPaso = NominaRepo::addPaso($idHerm, 'Cristo de la Sentencia', false)['id'];

$cuenta = static fn(string $sql, array $p = []): int => (int) Db::one($sql, $p)['N'];
$ficheros = static fn(string $estado): array => glob(PropuestaRepo::baseDir() . '/' . $estado . '/*') ?: [];

$tests = [];

$tests['roles: el editor llega a fichas completas, Semana Santa y acompañamientos, pero sigue sin ser admin'] = static function (): void {
    foreach (['marcha.edit', 'banda.edit', 'disco.add', 'disco.edit', 'nomina.edit', 'acompanamientos.edit'] as $cap) {
        assertCierto(Roles::has(Roles::EDITOR, $cap), "el editor debería tener $cap");
    }
    assertCierto(!Roles::isAdmin(Roles::EDITOR), 'el editor no es admin (ingesta, usuarios, dedicatorias… siguen cerradas)');
};

$tests['el editor siempre propone; el admin nunca: en local escribe y fuera de local solo mira'] = static function (): void {
    assertCierto(Admin::proposalMode(['rol' => Roles::EDITOR]), 'el editor propone en local');
    assertCierto(!Admin::proposalMode(['rol' => Roles::ADMIN]), 'el admin escribe directo en local');
    assertCierto(!Auth::soloLectura(['rol' => Roles::ADMIN]), 'en local el admin no está en solo lectura');
    $GLOBALS['config']['env'] = 'production';
    try {
        assertCierto(Auth::soloLectura(['rol' => Roles::ADMIN]), 'en PRE/PRO el admin solo mira: el sync pisaría cualquier cambio');
        assertCierto(!Admin::proposalMode(['rol' => Roles::ADMIN]), 'y tampoco propone');
        assertCierto(!Auth::soloLectura(['rol' => Roles::EDITOR]) && Admin::proposalMode(['rol' => Roles::EDITOR]), 'el editor sigue proponiendo en PRE/PRO');
    } finally {
        $GLOBALS['config']['env'] = 'local';
    }
};

/**
 * Lanza una petición simulada a la barrera de Auth::requireAuth() en un proceso
 * aparte (Http::soloLectura() hace exit) y devuelve lo que imprime: o la página
 * de solo lectura, o la marca de que habría llegado al controlador.
 */
$barrera = static function (string $usuario, string $env, string $metodo) use ($dir): string {
    static $n = 0;
    $n++;
    $script = $dir . "/barrera-$n.php";
    file_put_contents($script, <<<'PHP'
        <?php
        // argv: ci_boot.php, .db desechable, usuario, env, método
        require $argv[1];
        ciBoot($argv[2]);
        define('PUBLIC_DIR', dirname($argv[1], 2) . '/public'); // el layout versiona los assets por su fecha
        $pdo = new PDO('sqlite:' . $GLOBALS['config']['db_path']);
        $pdo->exec('CREATE TABLE usuarios (usuario TEXT, clave TEXT, ROL TEXT)');
        $pdo->exec("INSERT INTO usuarios VALUES ('jefe', 'x', 'admin'), ('ayudante', 'x', 'editor')");
        $pdo = null;
        $GLOBALS['config']['env'] = $argv[4];
        $_SERVER['REQUEST_METHOD'] = $argv[5];
        $ahora = (int) round(microtime(true) * 1000);
        $_COOKIE[App\Auth::cookieName()] = App\Auth::signSession(['user' => $argv[3], 'iat' => $ahora, 'exp' => $ahora + 60000, 'jti' => 'ci', 'pv' => App\Auth::huellaClave('x')]);
        App\Auth::requireAuth();
        echo 'LLEGA AL CONTROLADOR';
        PHP);
    $args = [PHP_BINARY, $script, __DIR__ . '/ci_boot.php', $dir . "/barrera-$n.db", $usuario, $env, $metodo];
    $salida = [];
    exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $salida);
    return implode("\n", $salida);
};

$tests['fuera de local ningún envío del admin llega al controlador; el del editor sí'] = static function () use ($barrera): void {
    $r = $barrera('jefe', 'production', 'POST');
    assertCierto(!str_contains($r, 'LLEGA AL CONTROLADOR'), "el POST del admin en PRE/PRO no pasa la barrera. Salida:\n$r");
    assertCierto(str_contains($r, 'Panel en modo solo lectura') && str_contains($r, '</html>'), "y en su lugar pinta entera la página de solo lectura. Salida:
$r");
    assertCierto(str_contains($barrera('jefe', 'production', 'GET'), 'LLEGA AL CONTROLADOR'), 'consultar sí puede (GET)');
    assertCierto(str_contains($barrera('ayudante', 'production', 'POST'), 'LLEGA AL CONTROLADOR'), 'el editor sí envía: su envío es una propuesta');
    assertCierto(str_contains($barrera('jefe', 'local', 'POST'), 'LLEGA AL CONTROLADOR'), 'en local el admin guarda');
};

$tests['proponer no toca la BD; aceptar aplica lo mismo que el guardado directo'] = static function () use ($idHerm, $cuenta, $ficheros): void {
    $antes = $cuenta('SELECT COUNT(*) AS N FROM paso');
    $params = ['idHermandad' => $idHerm, 'nombre' => 'Virgen de la Amargura', 'esCruzGuia' => false, 'idDia' => 0];
    $id = PropuestaRepo::createOperacion('nomina.paso.add', $params, 'editor1',
        PropuestaOps::describir('nomina.paso.add', $params), '/dashboard/semana-santa/sevilla');

    assertIgual($antes, $cuenta('SELECT COUNT(*) AS N FROM paso'), 'proponer no escribe en la BD');
    assertCierto(PropuestaRepo::fetchPendiente($id) !== null, 'queda pendiente para el admin');

    $r = PropuestaRepo::aplicar($id, 'admin');
    assertIgual('CREATED', $r['code'] ?? null, 'se aplica con el mismo NominaRepo::addPaso del guardado directo');
    assertIgual(1, $cuenta("SELECT COUNT(*) AS N FROM paso WHERE ID_HERMANDAD = ? AND NOMBRE = 'Virgen de la Amargura'", [$idHerm]), 'el paso existe');
    assertCierto(PropuestaRepo::fetchPendiente($id) === null, 'deja de estar pendiente (no se puede aplicar dos veces)');
    assertCierto(in_array(PropuestaRepo::baseDir() . "/aplicadas/$id.json", $ficheros('aplicadas'), true), 'archivada en aplicadas/');
};

$tests['una aceptación que falla no se da por buena: sigue pendiente y no se apunta'] = static function () use ($cuenta): void {
    $id = PropuestaRepo::createOperacion('nomina.paso.rename', ['idPaso' => 999999, 'nombre' => 'X'], 'editor1', 'renombrar', '/dashboard/semana-santa/sevilla');
    $r = PropuestaRepo::aplicar($id, 'admin');
    assertCierto(!PropuestaOps::esExito($r), 'un paso que no existe no se puede renombrar');
    assertCierto(PropuestaRepo::fetchPendiente($id) !== null, 'sigue pendiente para reintentar o rechazar');
    assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM admin_log WHERE tabla = 'propuesta' AND payload LIKE ?", ['%' . $id . '%']), 'sin rastro de resolución');
    PropuestaRepo::rechazar($id, 'admin', 'limpieza de la prueba');
};

$tests['el editor ve en qué quedó lo suyo, también en producción (por admin_log, que viaja en el .db)'] = static function () use ($idPaso): void {
    $params = ['idPaso' => $idPaso, 'nombre' => 'Cristo de la Sentencia (nuevo)'];
    $aceptada = PropuestaRepo::createOperacion('nomina.paso.rename', $params, 'editor2', PropuestaOps::describir('nomina.paso.rename', $params), '/dashboard/semana-santa/sevilla');
    $rechazada = PropuestaRepo::createOperacion('nomina.paso.delete', ['idPaso' => $idPaso], 'editor2', 'borrar paso', '/dashboard/semana-santa/sevilla');
    PropuestaRepo::aplicar($aceptada, 'admin');
    PropuestaRepo::rechazar($rechazada, 'admin', 'ese paso sigue saliendo');

    // Producción no tiene aplicadas/ ni rechazadas/ (se resuelve en local), pero sí
    // el JSON en recibidas/ (lo dejó allí el sync) y el admin_log del .db subido.
    foreach (['aplicadas', 'rechazadas'] as $estado) {
        foreach (glob(PropuestaRepo::baseDir() . "/$estado/*") ?: [] as $f) unlink($f);
    }
    @mkdir(PropuestaRepo::baseDir() . '/recibidas', 0775, true);
    file_put_contents(PropuestaRepo::baseDir() . "/recibidas/$aceptada.json", json_encode(['id' => $aceptada, 'autor' => 'editor2', 'entidad' => 'operacion']));

    $resueltas = array_column(PropuestaRepo::resueltasDe('editor2'), null, 'id');
    assertIgual('aplicada', $resueltas[$aceptada]['estado'] ?? null, 'la aceptada se ve como aceptada');
    assertIgual('rechazada', $resueltas[$rechazada]['estado'] ?? null, 'la rechazada se ve como rechazada');
    assertIgual('ese paso sigue saliendo', $resueltas[$rechazada]['motivo'] ?? null, 'con el motivo que dio el admin');
    assertCierto(str_contains($resueltas[$aceptada]['resumen'] ?? '', 'Cristo de la Sentencia'), 'con la descripción legible');
    assertIgual([], PropuestaRepo::pendientesDe('editor2'), 'lo recibido y ya resuelto no sale como pendiente');
};

$tests['«pendientes aquí» cuenta solo las del usuario y de esa pantalla'] = static function (): void {
    foreach ([['editor3', '/dashboard/semana-santa/sevilla'], ['editor3', '/dashboard/semana-santa/sevilla'],
              ['editor3', '/dashboard/disco/1'], ['otro', '/dashboard/semana-santa/sevilla']] as [$autor, $seccion]) {
        PropuestaRepo::createOperacion('nomina.dia.rename', ['idDia' => 1, 'nombre' => 'X'], $autor, 'renombrar', $seccion);
    }
    // Una edición de ficha también cuenta en la pantalla de ese registro.
    PropuestaRepo::create('banda', 'edit', 1, ['NOMBRE_BREVE' => 'X'], [], 'editor3');
    assertIgual(2, PropuestaRepo::pendientesEn('editor3', '/dashboard/semana-santa/sevilla'), 'dos en Semana Santa de Sevilla');
    assertIgual(1, PropuestaRepo::pendientesEn('editor3', '/dashboard/disco/1'), 'una en el disco');
    assertIgual(1, PropuestaRepo::pendientesEn('editor3', '/dashboard/banda/1'), 'la edición de la ficha de banda');
    assertIgual(0, PropuestaRepo::pendientesEn('editor3', '/dashboard/acompanamientos/sevilla'), 'ninguna en otra pantalla');
};

$tests['la descripción nombra lo que ve el editor, no ids'] = static function () use ($idHerm): void {
    $txt = PropuestaOps::describir('nomina.hermandad.rename', ['idHermandad' => $idHerm, 'nombre' => 'Hermandad del Silencio']);
    foreach (['Sevilla', 'El Silencio', 'Hermandad del Silencio'] as $parte) {
        assertCierto(str_contains($txt, $parte), "«{$txt}» debería mencionar «{$parte}»");
    }
};

$tests['la portada viaja con la propuesta y se instala al aceptar'] = static function () use ($dir): void {
    // WebP de 1×1 ya normalizado (lo que deja Media::guardarPortadaEn al proponer).
    $webp = PropuestaRepo::rutaAdjuntoTemporal('webp');
    file_put_contents($webp, base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA='));
    $id = PropuestaRepo::createOperacion('disco.edit', ['idDisco' => 1, 'campos' => [], 'conPortada' => true], 'editor4', 'portada nueva', '/dashboard/disco/1', ['portada' => $webp]);
    assertCierto(!is_file($webp), 'el adjunto se mueve junto al JSON');
    assertCierto(is_file(PropuestaRepo::baseDir() . "/pendientes/$id.portada.webp"), "queda como $id.portada.webp");
    assertCierto(!is_file($dir . '/public/cover/1.webp'), 'proponer no toca la portada publicada');

    $r = PropuestaRepo::aplicar($id, 'admin');
    assertIgual('UPDATED', $r['code'] ?? null, 'se acepta');
    assertCierto(is_file($dir . '/public/cover/1.webp'), 'la portada queda instalada');
    assertCierto(is_file(PropuestaRepo::baseDir() . "/aplicadas/$id.portada.webp"), 'y el adjunto se archiva con la propuesta');
};

// ── Sesión: el rol sale de una cuenta que existe y con la clave del login ────

$tests['sesión: cuenta borrada, clave cambiada o token sin huella no valen; un fallo de BD no da rol'] = static function (): void {
    Db::run('CREATE TABLE IF NOT EXISTS usuarios (id INTEGER PRIMARY KEY, usuario TEXT, clave TEXT, ROL TEXT)');
    Db::run("INSERT INTO usuarios (usuario, clave, ROL) VALUES ('ed-ok', 'h1', 'editor'), ('ed-reset', 'h1', 'editor')");
    $ahora = (int) round(microtime(true) * 1000);
    $token = static fn(string $u, ?string $clave): string => Auth::signSession(['user' => $u, 'iat' => $ahora, 'exp' => $ahora + 60000, 'jti' => 'ci']
        + ($clave !== null ? ['pv' => Auth::huellaClave($clave)] : []));
    $con = static function (string $tok): ?array { $_COOKIE[Auth::cookieName()] = $tok; try { return Auth::currentSession(); } finally { unset($_COOKIE[Auth::cookieName()]); } };

    $s = $con($token('ed-ok', 'h1'));
    assertIgual('editor', $s['rol'] ?? null, 'una sesión válida trae el rol de la BD');
    assertCierto($con($token('nadie', 'h1')) === null, 'una cuenta que ya no existe no tiene sesión (antes valía como editor)');
    Db::run("UPDATE usuarios SET clave = 'h2' WHERE usuario = 'ed-reset'");
    assertCierto($con($token('ed-reset', 'h1')) === null, 'tras un reset de clave, las sesiones de antes dejan de valer');
    assertCierto($con($token('ed-ok', null)) === null, 'un token sin huella de clave (emitido antes de este cambio) no vale');
    assertCierto(!Roles::has(null, 'marcha.edit') && !Roles::has('', 'marcha.edit') && !Roles::has('otro', 'marcha.edit'),
        'sin rol, o con uno desconocido, no hay ninguna capacidad');

    Db::run('ALTER TABLE usuarios RENAME TO usuarios_fuera');
    try {
        $rol = null;
        try { $rol = Auth::roleOf('ed-roto'); } catch (\PDOException) { $rol = 'EXCEPCION'; }
        assertIgual('EXCEPCION', $rol, 'si la BD falla al leer el rol, la petición falla: nunca se da por admin');
    } finally {
        Db::run('ALTER TABLE usuarios_fuera RENAME TO usuarios');
    }
};

// ── Almacén: cupo, tamaño y doble aceptación ─────────────────────────────────

$tests['cupo por autor y tamaño máximo: no se escribe nada fuera de límites'] = static function () use ($ficheros): void {
    $antes = count($ficheros('pendientes'));
    $grande = ['NOTAS' => str_repeat('x', PropuestaRepo::MAX_BYTES + 10)];
    $codigo = null;
    try { PropuestaRepo::create('autor', 'add', null, $grande, [], 'ed-grande'); } catch (\App\PropuestaNoAdmitida $e) { $codigo = $e->getMessage(); }
    assertIgual('PROPUESTA_DEMASIADO_GRANDE', $codigo, 'una propuesta enorme se rechaza');
    assertIgual($antes, count($ficheros('pendientes')), 'y no deja fichero');

    for ($n = 0; $n < PropuestaRepo::MAX_PENDIENTES_POR_AUTOR; $n++) {
        PropuestaRepo::createOperacion('nomina.dia.rename', ['idDia' => 1, 'nombre' => "x$n"], 'ed-cupo', 'x', '/dashboard');
    }
    $codigo = null;
    try { PropuestaRepo::createOperacion('nomina.dia.rename', ['idDia' => 1, 'nombre' => 'una más'], 'ed-cupo', 'x', '/dashboard'); }
    catch (\App\PropuestaNoAdmitida $e) { $codigo = $e->getMessage(); }
    assertIgual('CUPO_PROPUESTAS', $codigo, 'agotado el cupo, no se admiten más del mismo autor');
    PropuestaRepo::createOperacion('nomina.dia.rename', ['idDia' => 1, 'nombre' => 'otro'], 'ed-otro', 'x', '/dashboard');
    assertCierto(true, 'otro autor sigue pudiendo');
    foreach (PropuestaRepo::pendientes() as $p) {
        if (in_array($p['autor'] ?? '', ['ed-cupo', 'ed-otro'], true)) PropuestaRepo::rechazar((string) $p['id'], 'admin', 'limpieza');
    }
};

$tests['aceptar dos veces la misma propuesta no la aplica dos veces'] = static function () use ($idHerm, $cuenta): void {
    $id = PropuestaRepo::createOperacion('nomina.paso.add', ['idHermandad' => $idHerm, 'nombre' => 'Paso doble', 'esCruzGuia' => false, 'idDia' => 0], 'ed1', 'x', '/dashboard');
    assertIgual('CREATED', PropuestaRepo::aplicar($id, 'admin')['code'] ?? null, 'la primera se aplica');
    assertIgual('NOT_FOUND', PropuestaRepo::aplicar($id, 'admin')['code'] ?? null, 'la segunda ya no la encuentra');
    assertIgual('NOT_FOUND', PropuestaRepo::rechazar($id, 'admin', null)['code'] ?? null, 'ni se puede rechazar después');
    assertIgual(1, $cuenta("SELECT COUNT(*) AS N FROM paso WHERE NOMBRE = 'Paso doble'"), 'un solo paso');
};

$tests['transacciones anidadas: un fallo dentro deshace solo lo de dentro'] = static function () use ($cuenta): void {
    Db::run('CREATE TABLE IF NOT EXISTS ci_tx (v TEXT)');
    Db::transaction(static function (): void {
        Db::run("INSERT INTO ci_tx VALUES ('fuera')");
        try {
            Db::transaction(static function (): void { Db::run("INSERT INTO ci_tx VALUES ('dentro')"); throw new \RuntimeException('x'); });
        } catch (\RuntimeException) {
        }
    });
    assertIgual(1, $cuenta("SELECT COUNT(*) AS N FROM ci_tx WHERE v = 'fuera'"), 'lo de fuera se confirma');
    assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM ci_tx WHERE v = 'dentro'"), 'lo de dentro se deshace');
};

// ── Fallo 1: propuestas que cuelgan de otra aún pendiente ────────────────────

$tests['un editor encadena día → hermandad → paso sin esperar; se aplican en orden y con los ids reales'] = static function () use ($cuenta): void {
    $dia = PropuestaRepo::createOperacion('nomina.dia.add', ['localidad' => 'Sevilla', 'nombre' => 'Sábado de Pasión'], 'ed-cadena', 'x', '/dashboard');
    $herm = PropuestaRepo::createOperacion('nomina.hermandad.add',
        ['localidad' => 'Sevilla', 'idDia' => 0, 'nombre' => 'Hermandad de prueba', 'refs' => ['idDia' => $dia], 'diaNombre' => 'Sábado de Pasión'], 'ed-cadena', 'x', '/dashboard');
    $paso = PropuestaRepo::createOperacion('nomina.paso.add',
        ['idHermandad' => 0, 'nombre' => 'Paso de prueba', 'esCruzGuia' => false, 'idDia' => 0, 'refs' => ['idHermandad' => $herm]], 'ed-cadena', 'x', '/dashboard');
    assertCierto(PropuestaRepo::propiaSinResolver('ed-cadena', $dia) !== null, 'la madre es del editor y está sin resolver');
    assertCierto(PropuestaRepo::propiaSinResolver('otro', $dia) === null, 'de una ajena no se puede colgar');

    assertIgual('DEPENDE_DE_PENDIENTE', PropuestaRepo::aplicar($herm, 'admin')['code'] ?? null, 'la hija antes que la madre no se aplica');
    assertCierto(PropuestaRepo::fetchPendiente($herm) !== null, 'y sigue pendiente');

    $rDia = PropuestaRepo::aplicar($dia, 'admin');
    assertIgual('CREATED', $rDia['code'] ?? null, 'se aplica el día');
    $rHerm = PropuestaRepo::aplicar($herm, 'admin');
    assertIgual('CREATED', $rHerm['code'] ?? null, 'ahora la hermandad sí');
    assertIgual(1, $cuenta('SELECT COUNT(*) AS N FROM hermandad WHERE ID_HERMANDAD = ? AND DIA = (SELECT NOMBRE FROM semana_santa_dia WHERE ID_DIA = ?)',
        [(int) $rHerm['id'], (int) $rDia['id']]), 'en el día que creó la madre');
    assertIgual('CREATED', PropuestaRepo::aplicar($paso, 'admin')['code'] ?? null, 'y el paso, en la hermandad recién creada');
    assertIgual(1, $cuenta("SELECT COUNT(*) AS N FROM paso WHERE ID_HERMANDAD = ? AND NOMBRE = 'Paso de prueba'", [(int) $rHerm['id']]), 'paso colgado del id real');

    $dia2 = PropuestaRepo::createOperacion('nomina.dia.add', ['localidad' => 'Sevilla', 'nombre' => 'Lunes de Pascua'], 'ed-cadena', 'x', '/dashboard');
    $herm2 = PropuestaRepo::createOperacion('nomina.hermandad.add',
        ['localidad' => 'Sevilla', 'idDia' => 0, 'nombre' => 'Huérfana', 'refs' => ['idDia' => $dia2]], 'ed-cadena', 'x', '/dashboard');
    PropuestaRepo::rechazar($dia2, 'admin', 'no');
    assertIgual('DEPENDENCIA_NO_APLICADA', PropuestaRepo::aplicar($herm2, 'admin')['code'] ?? null, 'si la madre se rechaza, la hija no se aplica');
    PropuestaRepo::rechazar($herm2, 'admin', 'limpieza');
};

// ── Fallo 4: operaciones de rango con foto de las filas ──────────────────────

$tests['una operación de rango no se aplica si las filas cambiaron después de proponerla'] = static function () use ($cuenta): void {
    $banda = (int) Db::one('SELECT ID_BANDA FROM banda ORDER BY ID_BANDA LIMIT 1')['ID_BANDA'];
    $alta = \App\AdminRepo::addContratoRango($banda, 'Hermandad Foto', null, 2010, 2012, null, null, 'Sevilla');
    assertIgual('CREATED', $alta['code'], 'contratos de prueba');
    $ids = array_map('intval', array_column(Db::all("SELECT ID_CONTRATO FROM contrato WHERE HERMANDAD = 'Hermandad Foto' ORDER BY ANIO"), 'ID_CONTRATO'));

    $params = PropuestaOps::conFoto('acomp.borrar', ['ids' => $ids]);
    assertIgual(count($ids), count($params['foto'] ?? []), 'la propuesta lleva la foto de la línea (aquí, sus tres años)');
    $id = PropuestaRepo::createOperacion('acomp.borrar', $params, 'ed-rango', 'x', '/dashboard');

    // Entre medias, el admin alarga el rango: se recrean/funden filas.
    \App\AdminRepo::editarAniosRango($ids, 2009, 2012);
    $r = PropuestaRepo::aplicar($id, 'admin');
    assertIgual('CAMBIADO_DESDE_PROPUESTA', $r['code'] ?? null, 'las filas ya no son las de la foto');
    assertCierto($cuenta("SELECT COUNT(*) AS N FROM contrato WHERE HERMANDAD = 'Hermandad Foto'") >= 3, 'no se ha borrado nada');
    PropuestaRepo::rechazar($id, 'admin', 'limpieza');

    // Cambio de un año en la misma línea (tramo) también invalida la foto.
    $ids = array_map('intval', array_column(Db::all("SELECT ID_CONTRATO FROM contrato WHERE HERMANDAD = 'Hermandad Foto'"), 'ID_CONTRATO'));
    $id = PropuestaRepo::createOperacion('acomp.banda', PropuestaOps::conFoto('acomp.banda', ['ids' => [$ids[0]], 'idBanda' => $banda]), 'ed-rango', 'x', '/dashboard');
    Db::run('INSERT INTO contrato_tramo (ID_CONTRATO, TRAMO) VALUES (?, ?)', [$ids[1], 'ida']);
    assertIgual('CAMBIADO_DESDE_PROPUESTA', PropuestaRepo::aplicar($id, 'admin')['code'] ?? null, 'otro año de la línea cambió de tramo');
    PropuestaRepo::rechazar($id, 'admin', 'limpieza');
    Db::run('DELETE FROM contrato_tramo WHERE ID_CONTRATO = ?', [$ids[1]]);

    $ids = array_map('intval', array_column(Db::all("SELECT ID_CONTRATO FROM contrato WHERE HERMANDAD = 'Hermandad Foto'"), 'ID_CONTRATO'));
    $id = PropuestaRepo::createOperacion('acomp.borrar', PropuestaOps::conFoto('acomp.borrar', ['ids' => $ids]), 'ed-rango', 'x', '/dashboard');
    assertIgual('DELETED', PropuestaRepo::aplicar($id, 'admin')['code'] ?? null, 'sin cambios entre medias, se aplica');
    assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM contrato WHERE HERMANDAD = 'Hermandad Foto'"), 'y borra exactamente esas filas');
};

// ── Fallo 3: la edición de una ficha solo escribe lo que cambia ──────────────

$tests['aceptar una edición de ficha no pisa lo cambiado después en otros campos, y va entera o no va'] = static function (): void {
    $m = Db::one('SELECT ID_MARCHA, TITULO, FECHA FROM marcha ORDER BY ID_MARCHA LIMIT 1');
    $mid = (int) $m['ID_MARCHA'];
    $id = PropuestaRepo::create('marcha', 'edit', $mid, ['TITULO' => 'Título propuesto'], [], 'ed-ficha',
        ['formato' => 2, 'base' => ['TITULO' => $m['TITULO']]]);
    // Después de proponer, el admin cambia otro campo.
    \App\AdminRepo::editMarcha($mid, ['FECHA'], ['1999']);
    assertIgual('UPDATED', PropuestaRepo::aplicar($id, 'admin')['code'] ?? null, 'se aplica');
    $ahora = Db::one('SELECT TITULO, FECHA FROM marcha WHERE ID_MARCHA = ?', [$mid]);
    assertIgual('Título propuesto', (string) $ahora['TITULO'], 'el campo propuesto cambia');
    assertIgual('1999', (string) $ahora['FECHA'], 'el que no tocaba la propuesta conserva el cambio posterior');

    $id = PropuestaRepo::create('marcha', 'edit', $mid, ['TITULO' => 'No debe quedar'], [999999999], 'ed-ficha',
        ['formato' => 2, 'base' => ['TITULO' => 'Título propuesto'], 'autoresBase' => []]);
    $r = PropuestaRepo::aplicar($id, 'admin');
    assertIgual('INVALID_AUTORES', $r['code'] ?? null, 'un autor inexistente hace fallar la aceptación');
    assertIgual('Título propuesto', (string) Db::one('SELECT TITULO FROM marcha WHERE ID_MARCHA = ?', [$mid])['TITULO'],
        'y el título no se queda a medias: la ficha va entera o no va');
    assertCierto(PropuestaRepo::fetchPendiente($id) !== null, 'sigue pendiente');
    PropuestaRepo::rechazar($id, 'admin', 'limpieza');
};

// ── Altas de acompañamiento con destino libre (acomp.alta) ────────────────────

// Municipios del catálogo que usan estas pruebas (el fixture solo trae las capitales).
// «Lebrija» también en Cádiz: mismo nombre en dos provincias, para PROVINCIA_DISTINTA.
foreach ([['Sevilla', 'Lebrija'], ['Sevilla', 'Utrera'], ['Cádiz', 'Lebrija']] as [$prov, $mun]) {
    assertIgual('CREATED', \App\MunicipioRepo::crear($prov, $mun)['code'] ?? null, "municipio de prueba $mun ($prov)");
}

$tests['acompañamiento en una localidad sin Semana Santa: una sola propuesta crea localidad, día, hermandad, paso y contratos'] = static function () use ($cuenta): void {
    $banda = (int) Db::one('SELECT ID_BANDA FROM banda ORDER BY ID_BANDA LIMIT 1')['ID_BANDA'];
    $destino = ['localidad' => 'Lebrija', 'provincia' => 'Sevilla', 'dia' => 'Domingo de Ramos',
                'hermandad' => 'Hermandad de Prueba de Lebrija', 'paso' => 'Santísimo Cristo de Prueba'];
    $sim = NominaRepo::simularDestino($destino);
    assertIgual('OK', $sim['code'], 'el destino es válido');
    assertIgual(4, count($sim['nuevos'] ?? []), 'se crean localidad, día, hermandad y paso: ' . implode(' | ', $sim['nuevos'] ?? []));

    $params = ['idBanda' => $banda, 'destino' => $destino, 'anioInicio' => 2024, 'anioFin' => 2026, 'tramo' => 'ida', 'fuente' => 'https://ejemplo.invalid/x', 'nuevos' => $sim['nuevos']];
    $desc = PropuestaOps::describir('acomp.alta', $params);
    assertCierto(str_contains($desc, 'Se crea en Semana Santa') && str_contains($desc, 'Lebrija'), 'la descripción dice qué se crea: ' . $desc);
    $id = PropuestaRepo::createOperacion('acomp.alta', $params, 'ed-alta', $desc, '/dashboard/acompanamientos/lebrija');
    assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM hermandad WHERE LOCALIDAD = 'Lebrija'"), 'proponer no crea nada');

    $r = PropuestaRepo::aplicar($id, 'admin');
    assertIgual('CREATED', $r['code'] ?? null, 'se aplica');
    assertIgual(3, (int) ($r['creados'] ?? 0), 'tres años');
    assertIgual(1, $cuenta("SELECT COUNT(*) AS N FROM semana_santa_dia WHERE LOCALIDAD = 'Lebrija' AND NOMBRE = 'Domingo de Ramos'"), 'día creado');
    $paso = Db::one("SELECT p.ID_PASO FROM paso p JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE h.LOCALIDAD = 'Lebrija' AND p.NOMBRE = 'Santísimo Cristo de Prueba'");
    assertCierto($paso !== null, 'hermandad y paso creados');
    assertIgual(3, $cuenta('SELECT COUNT(*) AS N FROM contrato_paso WHERE ID_PASO = ?', [(int) $paso['ID_PASO']]), 'los contratos cuelgan del paso nuevo');
    assertIgual(3, $cuenta("SELECT COUNT(*) AS N FROM contrato_tramo t JOIN contrato_paso cp ON cp.ID_CONTRATO = t.ID_CONTRATO WHERE cp.ID_PASO = ? AND t.TRAMO = 'ida'", [(int) $paso['ID_PASO']]), 'con su tramo');
    assertIgual(3, $cuenta("SELECT COUNT(*) AS N FROM contrato c JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO WHERE cp.ID_PASO = ? AND c.FUENTE = 'https://ejemplo.invalid/x'", [(int) $paso['ID_PASO']]), 'y su fuente');
    assertIgual('Sevilla', (string) (Db::one("SELECT PROVINCIA FROM localidad_provincia WHERE LOCALIDAD = 'Lebrija'")['PROVINCIA'] ?? ''), 'la localidad queda con su provincia');

    // Una segunda propuesta sobre lo ya creado no duplica la nómina.
    $sim2 = NominaRepo::simularDestino($destino);
    assertIgual([], $sim2['nuevos'] ?? null, 'ya no hay nada nuevo que crear');
};

// El paso de El Silencio se llama «Cristo de la Sentencia (nuevo)» desde la prueba de lo resuelto.
$tests['el destino se valida al proponer con los mismos códigos que al aplicar; reglas de Virgen y de días'] = static function (): void {
    $base = ['localidad' => 'Sevilla', 'provincia' => 'Sevilla'];
    assertIgual('DIA_REQUERIDO', NominaRepo::simularDestino($base + ['hermandad' => 'Hermandad Inexistente'])['code'], 'hermandad nueva sin día');
    assertIgual('DIA_DISTINTO_DE_HERMANDAD', NominaRepo::simularDestino($base + ['hermandad' => 'El Silencio', 'dia' => 'Lunes Santo'])['code'], 'hermandad que sale otro día');
    assertIgual('PROVINCIA_DISTINTA', NominaRepo::simularDestino(['localidad' => 'Lebrija', 'provincia' => 'Cádiz', 'hermandad' => 'Otra', 'dia' => 'Lunes Santo'])['code'], 'localidad ya registrada en otra provincia');
    assertIgual('INVALID_LOCALIDAD', NominaRepo::simularDestino(['localidad' => 'Villanueva Inventada', 'provincia' => 'Sevilla', 'hermandad' => 'X', 'dia' => 'Lunes Santo'])['code'], 'solo municipios del catálogo');
    $ok = NominaRepo::simularDestino($base + ['hermandad' => 'el silencio', 'paso' => 'cristo de la sentencia (nuevo)']);
    assertIgual([], $ok['nuevos'] ?? null, 'sin tildes ni mayúsculas encuentra lo existente');
    assertIgual('El Silencio', $ok['hermandad'] ?? null, 'con su nombre bueno');

    $banda = (int) Db::one('SELECT ID_BANDA FROM banda ORDER BY ID_BANDA LIMIT 1')['ID_BANDA'];
    assertIgual('TRAMO_SIN_PASO', NominaRepo::validarAlta($banda, 2020, 2020, 'ida', '')['code'] ?? null, 'ida/vuelta exige paso');
    assertIgual('INVALID_BANDA', NominaRepo::validarAlta(999999, 2020, 2020, '', '')['code'] ?? null, 'banda inexistente');
    assertIgual(null, NominaRepo::validarAlta(null, 2020, 2021, '', ''), 'banda propuesta (null): se comprueba al aceptar');

    foreach (['Paso de Palio', 'Paso de Virgen', 'María Santísima de la Esperanza', 'Nuestra Señora de los Dolores', 'Virgen de la Amargura'] as $v) {
        assertCierto(NominaRepo::esPasoDeVirgen($v), "«{$v}» es de Virgen");
    }
    foreach (['Santísimo Cristo de la Sangre', 'Sagrada Presentación de Jesús al Pueblo', 'Nuestro Padre Jesús Nazareno', 'Cruz de Guía', ''] as $c) {
        assertCierto(!NominaRepo::esPasoDeVirgen($c), "«{$c}» no es de Virgen");
    }
    foreach (['Viernes de Dolores', 'Madrugá', 'Viernes Santo (tarde)', 'Sabado de Pasion', 'Domingo de Resurrección'] as $d) {
        assertCierto(!NominaRepo::diaFueraDeRango($d), "«{$d}» está en rango");
    }
    foreach (['Domingo de Pasión', 'Jueves de Pasion', 'Lunes de Pascua'] as $d) {
        assertCierto(NominaRepo::diaFueraDeRango($d), "«{$d}» avisa");
    }
};

$tests['si el alta del acompañamiento falla, no queda Semana Santa creada a medias'] = static function () use ($cuenta): void {
    $banda = (int) Db::one('SELECT ID_BANDA FROM banda ORDER BY ID_BANDA LIMIT 1')['ID_BANDA'];
    Db::run("CREATE TEMP TRIGGER ci_falla BEFORE INSERT ON contrato WHEN NEW.HERMANDAD = 'Hermandad Que Falla' BEGIN SELECT RAISE(ABORT, 'fallo simulado'); END");
    try {
        $lanzo = false;
        try {
            NominaRepo::altaDesdeBanda($banda, ['localidad' => 'Utrera', 'provincia' => 'Sevilla', 'dia' => 'Martes Santo',
                'hermandad' => 'Hermandad Que Falla', 'paso' => 'Cristo Que Falla', 'anioInicio' => 2020, 'anioFin' => 2020]);
        } catch (Throwable) {
            $lanzo = true;
        }
        assertCierto($lanzo, 'el fallo llega a quien aplica');
        assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM hermandad WHERE NOMBRE = 'Hermandad Que Falla'"), 'ni hermandad');
        assertIgual(0, $cuenta("SELECT COUNT(*) AS N FROM semana_santa_dia WHERE LOCALIDAD = 'Utrera'"), 'ni día');
    } finally {
        Db::run('DROP TRIGGER IF EXISTS ci_falla');
    }
};

$tests['un acompañamiento puede colgar de una banda propuesta y aún sin aceptar'] = static function () use ($cuenta): void {
    $bandaProp = PropuestaRepo::create('banda', 'add', null, ['NOMBRE_BREVE' => 'Banda Propuesta CI', 'NOMBRE_COMPLETO' => 'Banda Propuesta CI'], [], 'ed-ref');
    $params = ['idBanda' => 0, 'refs' => ['idBanda' => $bandaProp], 'bandaNombre' => 'Banda Propuesta CI',
               'destino' => ['localidad' => 'Sevilla', 'provincia' => 'Sevilla', 'hermandad' => 'El Silencio', 'paso' => 'Cristo de la Sentencia (nuevo)'],
               'anioInicio' => 2025, 'anioFin' => 2025];
    assertCierto(str_contains(PropuestaOps::describir('acomp.alta', $params), 'Banda Propuesta CI'), 'la descripción nombra la banda propuesta');
    $acomp = PropuestaRepo::createOperacion('acomp.alta', $params, 'ed-ref', 'x', '/dashboard');
    assertIgual('DEPENDE_DE_PENDIENTE', PropuestaRepo::aplicar($acomp, 'admin')['code'] ?? null, 'antes que la banda no');
    $rb = PropuestaRepo::aplicar($bandaProp, 'admin');
    assertIgual('CREATED', $rb['code'] ?? null, 'se acepta la banda');
    assertIgual('CREATED', PropuestaRepo::aplicar($acomp, 'admin')['code'] ?? null, 'y luego el acompañamiento');
    assertIgual(1, $cuenta('SELECT COUNT(*) AS N FROM contrato WHERE ID_BANDA = ? AND ANIO = 2025', [(int) $rb['bandaId']]), 'con el id real de la banda nueva');
};

// La última: ejecuta cada operación con parámetros vacíos sobre la BD de usar y tirar.
$tests['cada operación que encola el panel existe en PropuestaOps (sin erratas en la clave)'] = static function (): void {
    preg_match_all("/self::operar\\(\\\$session, '([a-zA-Z.]+)'/", (string) file_get_contents(dirname(__DIR__) . '/app/src/Admin.php'), $m);
    $ops = array_values(array_unique($m[1]));
    assertCierto(count($ops) >= 40, 'se encuentran las operaciones del panel (' . count($ops) . ')');
    foreach ($ops as $op) {
        $desc = PropuestaOps::describir($op, []);
        assertCierto($desc !== PropuestaOps::seccion($op) . ': ' . $op, "describir() no conoce «{$op}»");
        try {
            $r = PropuestaOps::aplicar($op, []);
            assertCierto(($r['code'] ?? '') !== 'OPERACION_DESCONOCIDA', "aplicar() no conoce «{$op}»");
        } catch (Throwable) {
            // Lanzar con parámetros vacíos es aceptable: la rama existe.
        }
    }
};

$salida = ciEjecuta($tests);
if (!isset($argv[1])) {
    ciLimpia($dbPath);
    $borrar = static function (string $d) use (&$borrar): void {
        foreach (scandir($d) ?: [] as $x) {
            if ($x === '.' || $x === '..') continue;
            is_dir("$d/$x") ? $borrar("$d/$x") : @unlink("$d/$x");
        }
        @rmdir($d);
    };
    $borrar($dir);
}
exit($salida);
