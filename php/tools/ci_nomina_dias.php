<?php

declare(strict_types=1);

/*
 * Pruebas de una hermandad en varios días (/dashboard/semana-santa/{localidad},
 * 020_hermandad_varios_dias.sql → NominaRepo).
 *
 * Por qué importa: el día de un acompañamiento sale de la nómina. Si una
 * hermandad que sale dos días (un paso el Jueves, otro el Viernes) tuviera un
 * solo día, la ficha de la banda diría que tocó el Jueves detrás del paso del
 * Viernes; y si una hermandad que cambió de día (El Carmen de Sevilla: Viernes
 * de Dolores hasta ~2005, hoy Miércoles Santo) usara el día actual para todo el
 * histórico, los años antiguos caerían en un día en que no salió y parecería
 * que la banda tocó en dos hermandades a la vez. Además, quitar un día no
 * puede perder pasos, y el alta desde la ficha de banda no puede rechazar como
 * "día distinto" un día en que la hermandad sí sale.
 *
 * Uso: php php/tools/ci_nomina_dias.php [ruta .db temporal]
 */

use App\Db;
use App\NominaRepo;
use App\Repo;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['015_semana_santa_dia.sql', '016_ida_vuelta.sql', '019_localidad_provincia.sql', '020_hermandad_varios_dias.sql'] as $sql) {
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/app/tools/sql/' . $sql));
}
$pdo = null;

// Nómina de Sevilla: Viernes de Dolores, Miércoles Santo, Jueves Santo, Viernes Santo.
$dia = [];
foreach (['Viernes de Dolores', 'Miércoles Santo', 'Jueves Santo', 'Viernes Santo'] as $n) {
    $dia[$n] = NominaRepo::addDia('Sevilla', $n)['id'];
}
$herm = static fn(string $n, string $d): int => NominaRepo::addHermandad('Sevilla', $dia[$d], $n)['id'];
$idCarmen = $herm('El Carmen', 'Miércoles Santo');
$idSilencio = $herm('El Silencio', 'Jueves Santo');   // la que sale dos días
$idOtra = $herm('La Otra', 'Viernes Santo');
$pasoJueves = NominaRepo::addPaso($idSilencio, 'Cristo del Jueves', false)['id'];
$pasoCarmen = NominaRepo::addPaso($idCarmen, 'Cristo del Carmen', false)['id'];

/** Día (nombre) → hermandades de ese día, cada una con sus pasos (nombres). */
function vista(): array
{
    $out = [];
    foreach (NominaRepo::cargarLocalidad('Sevilla') as $d) {
        foreach ($d['hermandades'] as $h) {
            $out[$d['NOMBRE']][$h['NOMBRE']] = array_column($h['pasos'], 'NOMBRE');
        }
    }
    return $out;
}

/** Contrato de la banda 1 en Sevilla enlazado a un paso. */
function contrato(int $idPaso, int $anio, int $banda = 1): void
{
    $p = NominaRepo::pasoDeLocalidad($idPaso, 'Sevilla');
    Db::run('INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO) VALUES (?, ?, ?, ?, ?)', [$banda, $p['HERMANDAD'], $p['SLUG'], $p['NOMBRE'], $anio]);
    $id = Db::lastInsertId();
    Db::run("INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, 'Sevilla')", [$id]);
    Db::run('INSERT INTO contrato_paso (ID_CONTRATO, ID_PASO) VALUES (?, ?)', [$id, $idPaso]);
}

/** Días de la ficha pública de la banda 1 en un año: hermandad → días. */
function diasFicha(int $anio): array
{
    $out = [];
    foreach (Repo::acompanamientosDeBanda(1)[$anio] ?? [] as $f) $out[$f['HERMANDAD']][] = $f['DIA'];
    return $out;
}

$tests = [];

$tests['dos días: la hermandad sale en los dos, cada uno con sus pasos'] = static function () use ($dia, $idSilencio): void {
    assertIgual('CREATED', NominaRepo::addDiaExtra($idSilencio, $dia['Viernes Santo'])['code'], 'alta del día extra');
    assertIgual('CREATED', NominaRepo::addPaso($idSilencio, 'Cristo del Viernes', false, $dia['Viernes Santo'])['code'], 'paso desde la caja del Viernes');
    $v = vista();
    assertIgual(['Cristo del Jueves'], $v['Jueves Santo']['El Silencio'] ?? null, 'Jueves: solo su paso');
    assertIgual(['Cristo del Viernes'], $v['Viernes Santo']['El Silencio'] ?? null, 'Viernes: solo el suyo');
    assertIgual(['La Otra', 'El Silencio'], array_keys($v['Viernes Santo']), 'el día extra entra al final del Viernes');
};

$tests['dos días: el día principal y los repetidos se rechazan'] = static function () use ($dia, $idSilencio): void {
    assertIgual('YA_SALE_ESE_DIA', NominaRepo::addDiaExtra($idSilencio, $dia['Jueves Santo'])['code'], 'su día principal');
    assertIgual('YA_SALE_ESE_DIA', NominaRepo::addDiaExtra($idSilencio, $dia['Viernes Santo'])['code'], 'ya lo tiene');
    assertIgual('YA_SALE_ESE_DIA', NominaRepo::moveHermandad($idSilencio, $dia['Viernes Santo'])['code'], 'mover el principal a su día extra');
};

$tests['dos días: el orden del día extra se guarda y convive con las principales'] = static function () use ($dia, $idSilencio, $idOtra): void {
    NominaRepo::reorderHermandades($dia['Viernes Santo'], [$idSilencio, $idOtra]);
    assertIgual(['El Silencio', 'La Otra'], array_keys(vista()['Viernes Santo']), 'arrastrar');
    NominaRepo::swapHermandadConVecino($idSilencio, 'down', $dia['Viernes Santo']);
    assertIgual(['La Otra', 'El Silencio'], array_keys(vista()['Viernes Santo']), 'flecha ↓ en el día extra');
    assertIgual(['El Silencio'], array_keys(vista()['Jueves Santo']), 'el Jueves no se toca');
};

$tests['dos días: cambiar el día de un paso lo cambia de caja'] = static function () use ($dia, $pasoJueves): void {
    assertIgual('UPDATED', NominaRepo::setPasoDia($pasoJueves, $dia['Viernes Santo'])['code'], 'al Viernes');
    assertIgual(['Cristo del Jueves', 'Cristo del Viernes'], vista()['Viernes Santo']['El Silencio'], 'ahora el Viernes');
    assertIgual('UPDATED', NominaRepo::setPasoDia($pasoJueves, $dia['Jueves Santo'])['code'], 'de vuelta');
    assertIgual('DIA_NOT_FOUND', NominaRepo::setPasoDia($pasoJueves, $dia['Miércoles Santo'])['code'], 'un día en que la hermandad no sale');
};

$tests['ficha de banda: cada acompañamiento en el día de su paso'] = static function () use ($pasoJueves, $idSilencio): void {
    $pasoViernes = (int) Db::one("SELECT ID_PASO FROM paso WHERE NOMBRE = 'Cristo del Viernes'")['ID_PASO'];
    contrato($pasoJueves, 2024);
    contrato($pasoViernes, 2024);
    contrato($pasoViernes, 2023);
    assertIgual(['El Silencio' => ['Jueves Santo', 'Viernes Santo']], diasFicha(2024), 'los dos días el mismo año, una fila por día');
    assertIgual(['El Silencio' => ['Viernes Santo']], diasFicha(2023), 'solo el Viernes');
    $admin = array_column(Repo::acompanamientosDeBandaAdmin(1)[2023], 'DIA');
    assertIgual(['Viernes Santo'], $admin, 'la pestaña del panel dice lo mismo');
};

$tests['quitar el día extra: sus pasos vuelven al principal, sin perder nada'] = static function () use ($dia, $idSilencio): void {
    // Un día cuya única hermandad sale en él como extra tampoco está vacío.
    $sabado = NominaRepo::addDia('Sevilla', 'Sábado Santo')['id'];
    NominaRepo::addDiaExtra($idSilencio, $sabado);
    assertIgual('DIA_NO_VACIO', NominaRepo::deleteDia($sabado)['code'], 'día con solo una salida extra');
    NominaRepo::removeDiaExtra($idSilencio, $sabado);
    assertIgual('DELETED', NominaRepo::deleteDia($sabado)['code'], 'ya vacío');
    assertIgual('DELETED', NominaRepo::removeDiaExtra($idSilencio, $dia['Viernes Santo'])['code'], 'quitar');
    $v = vista();
    assertIgual(['Cristo del Jueves', 'Cristo del Viernes'], $v['Jueves Santo']['El Silencio'], 'los dos pasos, en el Jueves');
    assertIgual(false, isset($v['Viernes Santo']['El Silencio']), 'ya no sale el Viernes');
    assertIgual(['El Silencio' => ['Jueves Santo']], diasFicha(2023), 'y la ficha lo sigue');
};

$tests['día de años pasados: los acompañamientos de esos años caen en él'] = static function () use ($dia, $idCarmen, $pasoCarmen): void {
    contrato($pasoCarmen, 2003);
    contrato($pasoCarmen, 2010);
    assertIgual('CREATED', NominaRepo::addDiaHistorico($idCarmen, $dia['Viernes de Dolores'], 1990, 2005)['code'], 'alta');
    assertIgual(['El Carmen' => ['Viernes de Dolores']], diasFicha(2003), '2003: el día de entonces');
    assertIgual(['El Carmen' => ['Miércoles Santo']], diasFicha(2010), '2010: el actual');
    assertIgual(['Miércoles Santo' => ['El Carmen' => ['Cristo del Carmen']]], array_intersect_key(vista(), ['Miércoles Santo' => 1]), 'la nómina actual no cambia');
};

$tests['día de años pasados: sin solapes ni el día actual'] = static function () use ($dia, $idCarmen): void {
    assertIgual('RANGO_SOLAPADO', NominaRepo::addDiaHistorico($idCarmen, $dia['Jueves Santo'], 2005, 2006)['code'], 'solapa en 2005');
    assertIgual('ES_EL_DIA_ACTUAL', NominaRepo::addDiaHistorico($idCarmen, $dia['Miércoles Santo'], 1980, 1985)['code'], 'es el de hoy');
    assertIgual('INVALID_RANGO', NominaRepo::addDiaHistorico($idCarmen, $dia['Jueves Santo'], 1985, 1980)['code'], 'al revés');
    assertIgual('DIA_NO_VACIO', NominaRepo::deleteDia($dia['Viernes de Dolores'])['code'], 'el Viernes de Dolores lo usa el histórico');
};

$alta = static fn(array $in): array => NominaRepo::altaDesdeBanda(2, $in + [
    'localidad' => 'Sevilla', 'provincia' => 'Sevilla', 'paso' => '', 'anioInicio' => 2025, 'anioFin' => 2025,
]);

$tests['alta desde la ficha de banda: vale cualquier día de la hermandad'] = static function () use ($alta, $dia, $idSilencio): void {
    assertIgual('CREATED', $alta(['hermandad' => 'El Carmen', 'dia' => 'Viernes de Dolores', 'anioInicio' => 2000, 'anioFin' => 2000])['code'], 'día de años pasados');
    NominaRepo::addDiaExtra($idSilencio, $dia['Viernes Santo']);
    $r = $alta(['hermandad' => 'El Silencio', 'dia' => 'Viernes Santo', 'paso' => 'Paso nuevo del Viernes']);
    assertIgual('CREATED', $r['code'], 'día extra');
    assertIgual(['Paso nuevo del Viernes'], vista()['Viernes Santo']['El Silencio'], 'el paso nuevo sale el Viernes, no el Jueves');
    assertIgual('DIA_DISTINTO_DE_HERMANDAD', $alta(['hermandad' => 'El Silencio', 'dia' => 'Miércoles Santo'])['code'], 'un día en que no sale sigue siendo error');
};

$tests['«+ Hermandad» con un nombre que ya está en otro día: sale también ese día, sin duplicarla'] = static function () use ($dia, $idOtra): void {
    $antes = (int) Db::one("SELECT COUNT(*) AS N FROM hermandad WHERE LOCALIDAD = 'Sevilla'")['N'];
    $r = NominaRepo::addHermandad('Sevilla', $dia['Jueves Santo'], 'la otra'); // sin mayúsculas: es La Otra
    assertIgual(['CREATED', $idOtra, true], [$r['code'], $r['id'] ?? null, $r['diaExtra'] ?? null], 'código');
    assertIgual($antes, (int) Db::one("SELECT COUNT(*) AS N FROM hermandad WHERE LOCALIDAD = 'Sevilla'")['N'], 'ninguna hermandad nueva');
    $v = vista();
    assertIgual([true, true], [isset($v['Jueves Santo']['La Otra']), isset($v['Viernes Santo']['La Otra'])], 'en los dos días');
    assertIgual('YA_SALE_ESE_DIA', NominaRepo::addHermandad('Sevilla', $dia['Viernes Santo'], 'La Otra')['code'], 'en su mismo día sí es error');
};

$tests['borrar la hermandad limpia sus días extra y de otros años'] = static function () use ($dia): void {
    $id = NominaRepo::addHermandad('Sevilla', $dia['Jueves Santo'], 'Para Borrar')['id'];
    NominaRepo::addDiaExtra($id, $dia['Viernes Santo']);
    NominaRepo::addPaso($id, 'Su paso', false, $dia['Viernes Santo']);
    NominaRepo::addDiaHistorico($id, $dia['Miércoles Santo'], 2000, 2001);
    assertIgual('DELETED', NominaRepo::deleteHermandad($id)['code'], 'borrado');
    $resto = (int) Db::one(
        'SELECT (SELECT COUNT(*) FROM hermandad_dia_extra WHERE ID_HERMANDAD = ?) + (SELECT COUNT(*) FROM hermandad_dia_historico WHERE ID_HERMANDAD = ?)
              + (SELECT COUNT(*) FROM paso_dia WHERE ID_PASO NOT IN (SELECT ID_PASO FROM paso)) AS N',
        [$id, $id]
    )['N'];
    assertIgual(0, $resto, 'sin filas huérfanas');
};

$code = ciEjecuta($tests);
ciLimpia($dbPath);
exit($code);
