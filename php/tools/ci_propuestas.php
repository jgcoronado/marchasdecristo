<?php

declare(strict_types=1);

/*
 * Pruebas del circuito de propuestas (PropuestaRepo + PropuestaOps).
 *
 * Por qué importa: el editor —y el admin fuera de local— NO escribe nunca en
 * la BD: lo que envía se guarda como propuesta y el admin la aplica en local,
 * que es de donde sale el .db bueno. Si proponer escribiera algo, el cambio se
 * perdería en el siguiente sync; si aceptar hiciera algo distinto del guardado
 * directo, o una aceptación fallida se diera por buena, se perderían o
 * duplicarían cambios sin que nadie lo viera. Y si el editor no ve en qué
 * quedó lo que propuso (también en producción, donde no están los ficheros
 * resueltos), lo vuelve a pedir.
 *
 * Uso: php php/tools/ci_propuestas.php [directorio temporal]
 */

use App\Admin;
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

$tests['modo propuesta: el editor siempre; el admin solo fuera de local'] = static function (): void {
    assertCierto(Admin::proposalMode(['rol' => Roles::EDITOR]), 'el editor propone en local');
    assertCierto(!Admin::proposalMode(['rol' => Roles::ADMIN]), 'el admin escribe directo en local');
    $GLOBALS['config']['env'] = 'production';
    try {
        assertCierto(Admin::proposalMode(['rol' => Roles::ADMIN]), 'en PRE/PRO tampoco escribe el admin: el sync pisaría el cambio');
    } finally {
        $GLOBALS['config']['env'] = 'local';
    }
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
