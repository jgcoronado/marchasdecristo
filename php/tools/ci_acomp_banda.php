<?php

declare(strict_types=1);

/*
 * Pruebas del alta de acompañamientos desde la ficha de banda
 * (/dashboard/banda/{id}, pestaña «Acompañamientos» → NominaRepo::altaDesdeBanda).
 *
 * Por qué importa: ese formulario escribe en la nómina de Semana Santa
 * (/dashboard/semana-santa), no solo en `contrato`. Si una hermandad o un paso
 * nuevos no quedaran en la nómina, el acompañamiento saldría como "fuera de la
 * nómina" en el panel de la localidad; si escribir sin tildes creara una
 * hermandad o un día nuevos, la nómina se llenaría de duplicados. Y el día de
 * una hermandad ya cargada no se cambia desde aquí sin que nadie lo vea.
 *
 * Uso: php php/tools/ci_acomp_banda.php [ruta .db temporal]
 */

use App\Db;
use App\MunicipioRepo;
use App\NominaRepo;
use App\Repo;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

// La fixture no trae los días, ida/vuelta ni las provincias: se aplican las
// migraciones reales para probar contra el esquema de verdad.
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['015_semana_santa_dia.sql', '016_ida_vuelta.sql', '019_localidad_provincia.sql'] as $sql) {
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/app/tools/sql/' . $sql));
}
$pdo = null;
// El catálogo de la fixture solo trae Sevilla y Cádiz capitales; Utrera hace de
// localidad nueva (existe en el catálogo pero no tiene aún acompañamientos).
MunicipioRepo::crear('Sevilla', 'Utrera');

/** Días → hermandades → pasos de la localidad, solo nombres. */
function nomina(string $localidad): array
{
    $out = [];
    foreach (NominaRepo::cargarLocalidad($localidad) as $d) {
        foreach ($d['hermandades'] as $h) {
            $out[$d['NOMBRE']][$h['NOMBRE']] = array_column($h['pasos'], 'NOMBRE');
        }
        $out[$d['NOMBRE']] ??= [];
    }
    return $out;
}

function cuenta(string $sql, array $params = []): int
{
    return (int) (Db::one($sql, $params)['N'] ?? 0);
}

$alta = static fn(array $in, int $banda = 1): array => NominaRepo::altaDesdeBanda($banda, $in + [
    'localidad' => 'Sevilla', 'provincia' => 'Sevilla', 'dia' => 'Martes Santo',
    'hermandad' => 'Los Estudiantes', 'paso' => 'Cristo de la Buena Muerte',
    'anioInicio' => 2024, 'anioFin' => 2025,
]);

$tests = [];

$tests['rango inválido: no deja hermandad ni día a medias en la nómina'] = static function () use ($alta): void {
    $r = $alta(['anioInicio' => 2025, 'anioFin' => 2024]);
    assertIgual('INVALID_RANGO', $r['code'], 'código');
    assertIgual([], nomina('Sevilla'), 'la nómina de Sevilla sigue vacía');
};

$tests['hermandad nueva: se crea en Semana Santa y el contrato cuelga de su paso'] = static function () use ($alta): void {
    $r = $alta([]);
    assertIgual('CREATED', $r['code'], 'código');
    assertIgual(2, $r['creados'], 'un contrato por año');
    assertIgual(['día «Martes Santo»', 'hermandad «Los Estudiantes»', 'paso «Cristo de la Buena Muerte»'], $r['nuevos'], 'avisa de lo creado');
    assertIgual(['Martes Santo' => ['Los Estudiantes' => ['Cristo de la Buena Muerte']]], nomina('Sevilla'), 'nómina');
    assertIgual(2, cuenta(
        "SELECT COUNT(*) AS N FROM contrato_paso cp INNER JOIN paso p ON p.ID_PASO = cp.ID_PASO
         INNER JOIN contrato c ON c.ID_CONTRATO = cp.ID_CONTRATO WHERE p.NOMBRE = ? AND c.ID_BANDA = 1",
        ['Cristo de la Buena Muerte']
    ), 'los dos años enlazados al paso');
};

$tests['escrito sin tildes ni mayúsculas: reutiliza día, hermandad y paso'] = static function () use ($alta): void {
    $r = $alta(['localidad' => 'sevilla', 'dia' => 'martes santo', 'hermandad' => 'los estudiantes',
                'paso' => 'cristo de la buena muerte', 'anioInicio' => 2026, 'anioFin' => 2026]);
    assertIgual('CREATED', $r['code'], 'código');
    assertIgual([], $r['nuevos'], 'nada nuevo en la nómina');
    assertIgual(['Martes Santo' => ['Los Estudiantes' => ['Cristo de la Buena Muerte']]], nomina('Sevilla'), 'sin duplicados');
    assertIgual(0, cuenta("SELECT COUNT(*) AS N FROM contrato_localidad WHERE LOCALIDAD = 'sevilla'"), 'la localidad es la literal existente');
};

$tests['hermandad existente: un día distinto es error, no un cambio de día'] = static function () use ($alta): void {
    $r = $alta(['dia' => 'Lunes Santo', 'anioInicio' => 2020, 'anioFin' => 2020]);
    assertIgual('DIA_DISTINTO_DE_HERMANDAD', $r['code'], 'código');
    assertIgual(['Martes Santo'], array_keys(nomina('Sevilla')), 'no se crea el Lunes Santo');
    assertIgual(0, cuenta('SELECT COUNT(*) AS N FROM contrato WHERE ANIO = 2020'), 'sin contrato');
};

$tests['sin paso: el contrato va a la hermandad sin crear paso'] = static function () use ($alta): void {
    $r = $alta(['paso' => '', 'dia' => '', 'anioInicio' => 2019, 'anioFin' => 2019]);
    assertIgual('CREATED', $r['code'], 'código (día vacío = el de la hermandad)');
    assertIgual(['Martes Santo' => ['Los Estudiantes' => ['Cristo de la Buena Muerte']]], nomina('Sevilla'), 'ningún paso nuevo');
};

$tests['localidad nueva: exige provincia y la guarda'] = static function () use ($alta): void {
    $base = ['localidad' => 'Utrera', 'dia' => 'Jueves Santo', 'hermandad' => 'Los Gitanos', 'paso' => ''];
    assertIgual('PROVINCIA_REQUERIDA', $alta($base + ['provincia' => ''])['code'], 'sin provincia');
    assertIgual([], nomina('Utrera'), 'nada creado sin provincia');

    $r = $alta($base + ['provincia' => 'Sevilla']);
    assertIgual('CREATED', $r['code'], 'con provincia');
    assertIgual('Sevilla', Db::one("SELECT PROVINCIA FROM localidad_provincia WHERE LOCALIDAD = 'Utrera'")['PROVINCIA'] ?? null, 'provincia guardada');
    $filas = Repo::acompanamientosDeBandaAdmin(1)[2024];
    $utrera = array_values(array_filter($filas, static fn(array $f): bool => $f['LOCALIDAD'] === 'Utrera'));
    assertIgual('Sevilla', $utrera[0]['PROVINCIA'] ?? null, 'el listado de la pestaña enseña la provincia');
    assertIgual('Jueves Santo', $utrera[0]['DIA'] ?? null, 'y el día de la nómina');
};

$tests['localidad conocida: otra provincia es error'] = static function () use ($alta): void {
    assertIgual('INVALID_LOCALIDAD', $alta(['provincia' => 'Cádiz'])['code'], 'Sevilla no es de Cádiz');
};

$tests['localidad fuera del listado de municipios: se rechaza sin tocar nada'] = static function () use ($alta): void {
    // Como en bandas y dedicatorias: solo vale lo que está en el catálogo (el
    // admin lo da de alta antes con «+ Añadir» del selector si falta).
    $r = $alta(['localidad' => 'Villainventada', 'dia' => 'Jueves Santo', 'hermandad' => 'La Nueva']);
    assertIgual('INVALID_LOCALIDAD', $r['code'], 'código');
    assertIgual([], nomina('Villainventada'), 'sin nómina');
    assertIgual(null, Db::one("SELECT 1 FROM localidad_provincia WHERE LOCALIDAD = 'Villainventada'"), 'sin provincia guardada');
};

$tests['localidad escrita sin tildes: se guarda con el nombre del catálogo'] = static function () use ($alta): void {
    $r = $alta(['localidad' => 'cadiz', 'provincia' => 'Cádiz', 'dia' => 'Martes Santo', 'hermandad' => 'La Sed', 'paso' => '', 'anioInicio' => 2001, 'anioFin' => 2001]);
    assertIgual('CREATED', $r['code'], 'código');
    assertIgual(1, cuenta("SELECT COUNT(*) AS N FROM contrato_localidad WHERE LOCALIDAD = 'Cádiz'"), '«Cádiz», no «cadiz»');
    assertIgual('Cádiz', Db::one("SELECT PROVINCIA FROM localidad_provincia WHERE LOCALIDAD = 'Cádiz'")['PROVINCIA'] ?? null, 'provincia');
};

/** Contrato de la banda 1 en Sevilla para un año, con su paso enlazado (o null). */
function contratoDe(int $anio, int $banda = 1): ?array
{
    return Db::one(
        'SELECT c.ID_CONTRATO, c.HERMANDAD, c.HERMANDAD_SLUG, c.TITULAR, c.ANIO, cp.ID_PASO FROM contrato c
         LEFT JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO
         WHERE c.ID_BANDA = ? AND c.ANIO = ? ORDER BY c.ID_CONTRATO LIMIT 1',
        [$banda, $anio]
    );
}

$edita = static fn(int $id, array $in, int $banda = 1): array => NominaRepo::editarDesdeBanda($banda, $id, $in + [
    'localidad' => 'Sevilla', 'provincia' => 'Sevilla', 'dia' => '', 'hermandad' => 'Los Estudiantes',
    'paso' => 'Cristo de la Buena Muerte', 'anio' => 2025,
]);

$tests['editar: cambia año, hermandad y paso, y lo nuevo entra en Semana Santa'] = static function () use ($edita): void {
    $id = (int) contratoDe(2025)['ID_CONTRATO'];
    $r = $edita($id, ['anio' => 2018, 'hermandad' => 'La Cena', 'dia' => 'Domingo de Ramos', 'paso' => 'Sagrada Cena']);
    assertIgual('UPDATED', $r['code'], 'código');
    assertIgual(['día «Domingo de Ramos»', 'hermandad «La Cena»', 'paso «Sagrada Cena»'], $r['nuevos'], 'avisa de lo creado');
    assertIgual(['La Cena' => ['Sagrada Cena']], nomina('Sevilla')['Domingo de Ramos'] ?? null, 'nómina');
    $c = contratoDe(2018);
    assertIgual([$id, 'la-cena', 'Sagrada Cena'], [(int) $c['ID_CONTRATO'], $c['HERMANDAD_SLUG'], $c['TITULAR']], 'contrato reescrito');
    assertIgual('Sagrada Cena', Db::one('SELECT NOMBRE FROM paso WHERE ID_PASO = ?', [$c['ID_PASO']])['NOMBRE'] ?? null, 'enlazado al paso nuevo');
    assertIgual(0, cuenta('SELECT COUNT(*) AS N FROM contrato WHERE ID_CONTRATO = ? AND ANIO = 2025', [$id]), 'ya no está en 2025');
};

$tests['editar: no deja dos contratos de la banda en el mismo paso y año'] = static function () use ($edita): void {
    $id = (int) contratoDe(2018)['ID_CONTRATO'];
    assertIgual('DUPLICADO', $edita($id, ['anio' => 2026])['code'], '2026 ya lo tiene la banda en ese paso');
    assertIgual('la-cena', contratoDe(2018)['HERMANDAD_SLUG'] ?? null, 'el contrato no cambia');
};

$tests['editar: hermandad fuera de la nómina guardada sin día no se mete en la nómina'] = static function () use ($edita): void {
    // Contrato 2 de la fixture: banda 2, "Hdad de los Gitanos" / "Cristo de la Salud", sin nómina.
    $antes = nomina('Sevilla');
    $r = $edita(2, ['hermandad' => 'Hdad de los Gitanos', 'paso' => 'Cristo de la Salud', 'anio' => 2024], 2);
    assertIgual('UPDATED', $r['code'], 'código');
    assertIgual([], $r['nuevos'], 'nada nuevo');
    assertIgual($antes, nomina('Sevilla'), 'nómina intacta');
    $c = contratoDe(2024, 2);
    assertIgual(['hdad-de-los-gitanos', 'Cristo de la Salud', null], [$c['HERMANDAD_SLUG'], $c['TITULAR'], $c['ID_PASO']], 'solo cambia el año');
};

$tests['editar: un TITULAR antiguo sin paso se conserva, no se convierte en paso'] = static function () use ($edita): void {
    Db::run("INSERT INTO contrato (ID_BANDA, HERMANDAD, HERMANDAD_SLUG, TITULAR, ANIO) VALUES (2, 'Los Estudiantes', 'los-estudiantes', 'Paso de Misterio', 2010)");
    $id = Db::lastInsertId();
    Db::run("INSERT INTO contrato_localidad (ID_CONTRATO, LOCALIDAD) VALUES (?, 'Sevilla')", [$id]);
    $r = $edita($id, ['paso' => 'Paso de Misterio', 'anio' => 2011], 2);
    assertIgual('UPDATED', $r['code'], 'código');
    assertIgual(['Cristo de la Buena Muerte'], nomina('Sevilla')['Martes Santo']['Los Estudiantes'], 'sin paso «Paso de Misterio»');
    assertIgual(['Paso de Misterio', null], [contratoDe(2011, 2)['TITULAR'], contratoDe(2011, 2)['ID_PASO']], 'TITULAR tal cual');
};

$tests['editar: solo contratos de esa banda'] = static function () use ($edita): void {
    assertIgual('CONTRATO_INVALIDO', $edita(2, [], 1)['code'], 'el 2 es de la banda 2');
};

$tests['borrar: solo contratos de esa banda'] = static function (): void {
    $id = (int) Db::one("SELECT MIN(ID_CONTRATO) AS ID FROM contrato WHERE ID_BANDA = 1 AND ANIO = 2024")['ID'];
    assertIgual('CONTRATO_INVALIDO', NominaRepo::borrarDeBanda(2, [$id])['code'], 'desde otra banda');
    assertIgual('DELETED', NominaRepo::borrarDeBanda(1, [$id])['code'], 'desde la suya');
    assertIgual(0, cuenta('SELECT COUNT(*) AS N FROM contrato_paso WHERE ID_CONTRATO = ?', [$id]), 'sin enlace huérfano');
};

/*
 * Importar un año en otro (botón «Importar a AAAA» de la pestaña): sirve para
 * no volver a teclear una Semana Santa que se repite. Si la copia perdiera el
 * paso o la ida/vuelta, el acompañamiento saldría en otro sitio del panel de la
 * localidad; si duplicara al repetirla, la ficha pública contaría dos veces el
 * mismo paso; si copiara la FUENTE, se citaría una fuente de otro año.
 */

/** Filas de un año tal como las enseña la pestaña, sin el id. */
function filasAnio(int $anio, int $banda = 1): array
{
    return array_map(
        static fn(array $f): array => [$f['LOCALIDAD'], $f['HERMANDAD'], $f['PASO'], $f['TRAMO']],
        Repo::acompanamientosDeBandaAdmin($banda)[$anio] ?? []
    );
}

$tests['importar año: copia localidad, paso y tramo sin tocar la nómina ni copiar la fuente'] = static function () use ($alta): void {
    $alta(['anioInicio' => 1990, 'anioFin' => 1990]);
    $alta(['localidad' => 'Cádiz', 'provincia' => 'Cádiz', 'dia' => 'Martes Santo', 'hermandad' => 'La Sed', 'paso' => '', 'anioInicio' => 1990, 'anioFin' => 1990]);
    $conPaso = (int) Db::one('SELECT c.ID_CONTRATO AS ID FROM contrato c INNER JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO WHERE c.ID_BANDA = 1 AND c.ANIO = 1990')['ID'];
    Db::run("INSERT INTO contrato_tramo (ID_CONTRATO, TRAMO) VALUES (?, 'ida')", [$conPaso]);
    Db::run("UPDATE contrato SET FUENTE = 'https://fuente-de-1990' WHERE ID_BANDA = 1 AND ANIO = 1990");
    $nomina = [nomina('Sevilla'), nomina('Cádiz')];

    $r = NominaRepo::copiarAnioDeBanda(1, 1990, 1995);
    assertIgual(['CREATED', 2, 0], [$r['code'], $r['creados'] ?? null, $r['existentes'] ?? null], 'código y cuentas');
    assertIgual(filasAnio(1990), filasAnio(1995), 'mismas filas: localidad, hermandad, paso y tramo');
    assertIgual(1, cuenta('SELECT COUNT(*) AS N FROM contrato c INNER JOIN contrato_paso cp ON cp.ID_CONTRATO = c.ID_CONTRATO WHERE c.ID_BANDA = 1 AND c.ANIO = 1995'), 'el paso va enlazado, no solo como texto');
    assertIgual(0, cuenta('SELECT COUNT(*) AS N FROM contrato WHERE ID_BANDA = 1 AND ANIO = 1995 AND FUENTE IS NOT NULL'), 'sin la fuente de 1990');
    assertIgual($nomina, [nomina('Sevilla'), nomina('Cádiz')], 'nómina intacta');
    assertIgual(2, count(filasAnio(1990)), 'el año de origen sigue igual');
};

$tests['importar año: repetirlo no duplica y completa lo que falte'] = static function (): void {
    $r = NominaRepo::copiarAnioDeBanda(1, 1990, 1995);
    assertIgual([0, 2], [$r['creados'] ?? null, $r['existentes'] ?? null], 'segunda vez: nada nuevo');
    assertIgual(2, count(filasAnio(1995)), 'siguen siendo dos');

    $uno = (int) Db::one("SELECT MIN(ID_CONTRATO) AS ID FROM contrato WHERE ID_BANDA = 1 AND ANIO = 1995")['ID'];
    NominaRepo::borrarDeBanda(1, [$uno]);
    $r = NominaRepo::copiarAnioDeBanda(1, 1990, 1995);
    assertIgual([1, 1], [$r['creados'] ?? null, $r['existentes'] ?? null], 'repone solo el que se borró');
    assertIgual(filasAnio(1990), filasAnio(1995), 'vuelve a estar completo');
};

$tests['importar año: mismo año, año vacío o de otra banda es error sin escribir'] = static function (): void {
    $antes = cuenta('SELECT COUNT(*) AS N FROM contrato');
    assertIgual('MISMO_ANIO', NominaRepo::copiarAnioDeBanda(1, 1990, 1990)['code'], 'mismo año');
    assertIgual('SIN_ACOMPANAMIENTOS', NominaRepo::copiarAnioDeBanda(1, 1950, 1996)['code'], 'año sin acompañamientos');
    assertIgual('SIN_ACOMPANAMIENTOS', NominaRepo::copiarAnioDeBanda(2, 1990, 1996)['code'], '1990 es de la banda 1, no de la 2');
    assertIgual('INVALID_ANIO', NominaRepo::copiarAnioDeBanda(1, 1990, 0)['code'], 'destino sin año');
    assertIgual($antes, cuenta('SELECT COUNT(*) AS N FROM contrato'), 'ningún contrato nuevo');
};

$code = ciEjecuta($tests);
ciLimpia($dbPath);
exit($code);
