<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Pages as P; use App\EnlaceRepo as ER;
use App\C\CabeceraFicha; use App\C\Columna; use App\C\Dato; use App\C\Escuchar; use App\C\FichaTecnica;
use App\C\Migas; use App\C\PieFicha; use App\C\Tabla; use App\C\Texto;
/** @var array<string,mixed> $m
 *  @var array{original: array<string,string>, actual: array<string,string>} $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */
/** @var list<array<string,mixed>> $relacionadas  Repo::marchasRelacionadas */

/* Ficha de marcha del rediseño C, montada con los componentes de App\C (F2,
   plantilla de prueba). Orden de la columna principal (líneas maestras §2,
   principio 6, requisito fijo del mantenedor; plan, «Decisiones tomadas»):
   nombre → tabla de datos (los campos y el orden de pre) → Escuchar → Notas
   → Grabaciones → Véase también; el identificador con el enlace permanente
   al pie. Sin frase bajo el título. La columna derecha solo lleva las
   portadas. Sin etiqueta de estilo, sin compartir y sin recuentos en títulos,
   enlaces ni en la tabla de datos (§5). */

// "truthy" al estilo JS: null, '', 0, 0.0 y false son falsos; '0' (string) es verdadero.
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);

/** 208 → "3 min 28 s" */
$dur = static function ($seg): string {
    $s = (int) $seg;
    return $s > 0 ? intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : '';
};

/** Forma de lectura: "Nombre Apellidos" (o lo que exista). En la ficha se lee
 *  de corrido, así que no se invierte al estilo catálogo ("Apellidos, Nombre"). */
$autoridad = static function (array $a): string {
    $ap = trim((string) ($a['APELLIDOS'] ?? ''));
    $no = trim((string) ($a['NOMBRE'] ?? ''));
    if ($ap !== '' && $no !== '') return $no . ' ' . $ap;
    return $no !== '' ? $no : $ap;
};

$mid = (int) $m['ID_MARCHA'];
$tipo = $t($m['TIPO'] ?? null) ? ucfirst(mb_strtolower((string) $m['TIPO'])) : 'Marcha';
$estilo = match ($m['ESTILO'] ?? null) {
    'CCTT' => 'Cornetas y tambores',
    'AM' => 'Agrupación musical',
    default => '',
};
$duracion = $dur($m['DURACION_SEG'] ?? 0);
$autores = $m['AUTORES_FICHA'] ?? [];

// Localidad (Provincia) — va pegada a la dedicatoria. La provincia se omite
// si coincide con la localidad ("Sevilla").
$localidad = '';
if ($t($m['LOCALIDAD'])) {
    $mismaProvincia = $t($m['PROVINCIA']) && mb_strtolower((string) $m['PROVINCIA'], 'UTF-8') === mb_strtolower((string) $m['LOCALIDAD'], 'UTF-8');
    $localidad = (string) $m['LOCALIDAD'] . ($t($m['PROVINCIA']) && !$mismaProvincia ? ' (' . $m['PROVINCIA'] . ')' : '');
} elseif ($t($m['PROVINCIA'])) {
    $localidad = (string) $m['PROVINCIA'];
}

/* La BD abrevia el tipo de corporación al estilo del boletín impreso
   ("Hdad", "Cofr.", "Agrup Parr"). En la ficha se escriben enteras: el
   visitante no tiene por qué saber la jerga. Solo toca la palabra suelta —
   "Cofrentes" o "Hdades" no se ven afectados. */
$expandir = static fn(string $s): string => (string) preg_replace(
    ['/\bAgrup\.?\s*Parr\.?(?!\p{L})/iu', '/\bHdad\.?(?!\p{L})/iu', '/\bCofr\.?(?!\p{L})/iu'],
    ['Agrupación Parroquial', 'Hermandad', 'Cofradía'],
    $s
);

// Notas: la BD guarda '<br>' literales; se escapan y se restauran solo esos saltos.
$notas = '';
if ($t($m['DETALLES_MARCHA'])) {
    $notas = str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($m['DETALLES_MARCHA']));
}

$nGrab = (int) $m['discosLength'];
// FECHA puede venir normalizada a 's/f': solo los años reales enlazan a su hub.
$anioOk = preg_match('/^\d{4}$/', (string) $m['FECHA']) === 1;
$anioPath = $anioOk ? P::anioHubPath((string) $m['FECHA']) : '';

// Portadas de sus grabaciones: el mosaico de la columna lateral.
$portadas = [];
foreach ($m['discos'] as $d) {
    $did = (int) $d['ID_DISCO'];
    if ($did > 0 && !isset($portadas[$did])) {
        $portadas[$did] = ['id' => $did, 'nombre' => (string) $d['NOMBRE_CD'],
            'anio' => (int) ($d['FECHA_CD'] ?? 0) > 1800 ? (int) $d['FECHA_CD'] : null,
            'sub' => (string) ($d['BANDA_BREVE'] ?? '')];
    }
}
$portadas = array_values($portadas);
$autorPath = static fn(array $a): string => S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')));
$hayDedic = $t($m['DEDICATORIA']) && $m['DEDICATORIA'] !== '0';
$bandaPath = $t($m['BANDA_ESTRENO']) ? S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE']) : '';

/* Tabla de datos: los campos y el orden de la ficha de pre (Compositor,
   Estrenada por, Año, Dedicatoria o Localidad, Estilo, Duración, Tipo). Un
   campo sin dato no se pinta. Los valores van enlazados donde hay destino:
   compositor, banda de estreno, hub del año y listado filtrado por estilo.
   Pre añadía «29 marchas compuestas» y «33 marchas estrenadas» junto al
   nombre: son cifras de inventario (§5 «Recuentos») y no se pintan. Tipo,
   como en pre, solo cuando no es la «Marcha procesional» de casi todas. */
$estiloHub = $estilo !== '' ? P::estiloHubPath((string) $m['ESTILO']) : null;
$datos = [];
if ($autores !== []) {
    $compositores = [];
    foreach ($autores as $a) $compositores[] = Texto::de()->enlace($autoridad($a), $autorPath($a));
    $datos[] = new Dato(count($autores) > 1 ? 'Compositores' : 'Compositor', Texto::lineas($compositores));
}
if ($bandaPath !== '') {
    $datos[] = new Dato('Estrenada por', Texto::de()->enlace((string) $m['BANDA_NOMBRE'], $bandaPath)
        ->texto($t($m['BANDA_LOC']) ? ', ' . $m['BANDA_LOC'] : ''));
}
if ($t($m['FECHA'])) $datos[] = new Dato('Año', (string) $m['FECHA'], $anioOk ? $anioPath : null);
if ($hayDedic) {
    $datos[] = new Dato('Dedicatoria', $expandir((string) $m['DEDICATORIA']) . ($localidad !== '' ? ', ' . $localidad : ''));
} elseif ($localidad !== '') {
    $datos[] = new Dato('Localidad', $localidad);
}
if ($estilo !== '') $datos[] = new Dato('Estilo', $estilo, $estiloHub);
if ($duracion !== '') $datos[] = new Dato('Duración', $duracion);
if ($t($m['TIPO']) && mb_strtolower($tipo, 'UTF-8') !== 'marcha procesional') $datos[] = new Dato('Tipo', $tipo);

$escuchar = Escuchar::marcha($enlaces ?? ['original' => [], 'actual' => []], $m['AUDIO'] ?? null,
    ER::admiteVersiones($anioOk ? (int) $m['FECHA'] : null));

/* Grabaciones: la duración mostrada es la REAL del track; si abre con intro
   de percusión, «perc.» va como texto de la celda (§5 «Etiquetas»). */
$filasGrab = [];
foreach ($m['discos'] as $d) {
    $anio = (int) ($d['FECHA_CD'] ?? 0);
    $banda = null;
    if ($t($d['ID_BANDA'])) {
        $banda = Texto::de()->enlace((string) $d['BANDA_BREVE'], S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA_BREVE']))
            ->texto($t($d['BANDA_LOC']) ? ' · ' . $d['BANDA_LOC'] : '');
    }
    $durGrab = null;
    if (!empty($d['DURACION_SEG'])) {
        $durGrab = Texto::de(gmdate('i:s', (int) $d['DURACION_SEG']));
        if (!empty($d['PERCUSION'])) {
            $durGrab = $durGrab->texto(' ')->abreviatura('perc.', 'Empieza con introducción de percusión (unos 40 s de tambores antes de la marcha)');
        }
    }
    $filasGrab[] = [
        Texto::de()->enlace((string) $d['NOMBRE_CD'], S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])),
        $banda,
        $anio > 1800 ? $anio : null,
        $durGrab,
    ];
}
$tablaGrab = new Tabla(
    [new Columna('Grabación'), new Columna('Banda'), new Columna('Año', 'num'), new Columna('Duración', 'num')],
    $filasGrab, 'grab-table', true, 'Filtrar grabaciones'
);

/* Véase también: enlaces de exploración, sin recuento entre paréntesis (§5
   «Recuentos»). La condición de más de una marcha se mantiene: un enlace a
   un listado que solo tiene esta no lleva a ningún sitio. */
$explorar = [];
foreach ($autores as $a) {
    if ((int) $a['N_MARCHAS'] > 1) $explorar[] = Texto::de()->enlace('Obra de ' . $autoridad($a), $autorPath($a));
}
if ($bandaPath !== '' && (int) $m['BANDA_ESTRENOS'] > 1) $explorar[] = Texto::de()->enlace('Estrenos de ' . $m['BANDA_NOMBRE'], $bandaPath);
if ($anioOk && (int) $m['N_MISMO_ANIO'] > 1) $explorar[] = Texto::de()->enlace('Marchas de ' . $m['FECHA'], $anioPath);
if ($estiloHub !== null) $explorar[] = Texto::de()->enlace('Marchas de ' . mb_strtolower($estilo, 'UTF-8'), $estiloHub);
if ($t($m['PROVINCIA']) && (int) $m['N_MISMA_PROV'] > 1) $explorar[] = Texto::de()->enlace('Marchas de la provincia de ' . $m['PROVINCIA'], P::provinciaHubPath((string) $m['PROVINCIA']));
$filasRel = [];
foreach ($relacionadas as $r) {
    $filasRel[] = [
        Texto::de()->enlace((string) $r['TITULO'], S::buildDetailPath('marcha', $r['ID_MARCHA'], (string) $r['TITULO'])),
        $r['AUTOR'] !== [] ? V::listaY(array_map(static fn(array $a): string => (string) $a['nombre'], $r['AUTOR'])) : null,
        preg_match('/^\d{4}$/', (string) $r['FECHA']) === 1 ? (string) $r['FECHA'] : null,
    ];
}

/* $mosaico se imprime sin escapar porque ya es HTML seguro: Html::mosaico()
   escapa con Html::e() el título y la banda de cada disco (title y alt), y
   los href y src salen de Slug::buildDetailPath() y de coverSrc() con el
   ID entero del disco. No entra ningún dato sin escapar. */
$mosaico = H::mosaico($portadas, 8);
?>
<?= new Migas([['Inicio', '/'], ['Marchas', '/marcha']]) ?>

<article class="c-ficha<?= $mosaico === '' ? ' c-ficha-sin-lateral' : '' ?>">
    <?= new CabeceraFicha((string) $m['TITULO'], $nGrab >= 12 ? [['Grabaciones', '#grabaciones']] : []) ?>

    <div class="c-ficha-principal">
        <?= new FichaTecnica($datos) ?>

        <section>
            <h2 id="escuchar">Escuchar</h2>
            <?= $escuchar ?>
        </section>

<?php if ($notas !== ''): ?>
        <section>
            <h2>Notas</h2>
            <p class="notas"><?= $notas ?></p>
        </section>
<?php endif; ?>

<?php /* Sin grabaciones no hay bloque: Escuchar ya dice que falta. */ ?>
<?php if ($nGrab > 0): ?>
        <section>
            <h2 id="grabaciones">Grabaciones</h2>
            <?= $tablaGrab ?>
        </section>
<?php endif; ?>

<?php if ($filasRel !== [] || $explorar !== []): ?>
        <section>
            <h2>Véase también</h2>
<?php if ($explorar !== []): ?>
            <p class="c-vease"><?= Texto::unir($explorar, ' · ') ?></p>
<?php endif; ?>
            <?= new Tabla([new Columna('Marcha'), new Columna('Compositor'), new Columna('Año', 'num')], $filasRel) ?>
        </section>
<?php endif; ?>
    </div>

<?php /* Columna derecha: solo las portadas. En el documento va después de la
         principal: en móvil, una sola columna con el nombre, la tabla de
         datos y lo demás; las portadas, al final. */ ?>
<?php if ($mosaico !== ''): ?>
    <aside class="c-ficha-lateral">
        <section class="c-portadas">
            <h2>Discos donde está grabada</h2>
            <?= $mosaico ?>
        </section>
    </aside>
<?php endif; ?>

    <?= new PieFicha('M', $mid, $url ?? null) ?>
</article>
