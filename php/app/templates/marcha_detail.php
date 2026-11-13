<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Pages as P; use App\EnlaceRepo as ER;
use App\C\CabeceraFicha; use App\C\Columna; use App\C\Dato; use App\C\Escuchar; use App\C\FichaTecnica;
use App\C\Migas; use App\C\PieFicha; use App\C\Tabla; use App\C\Texto;
/** @var array<string,mixed> $m
 *  @var array{original: array<string,string>, actual: array<string,string>} $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */
/** @var list<array<string,mixed>> $relacionadas  Repo::marchasRelacionadas */

/* Ficha de marcha del rediseño C, montada con los componentes de App\C (F2,
   plantilla de prueba). Orden (líneas maestras §2, principio 6; plan,
   «Decisiones tomadas»): nombre y frase; ficha técnica al lado; Notas;
   Escuchar después de la información; Grabaciones; Véase también; y el
   identificador con el enlace permanente al pie. Sin etiqueta de estilo, sin
   compartir y sin recuentos en títulos ni enlaces (§5). */

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

/* Frase de cabecera: la ficha se presenta en una oración con lo que se sabe
   («Marcha de agrupación musical de X, 1969. Estrenada por Y. Dedicada a Z.»).
   Solo datos reales: lo que falta se omite, no se rellena. La duración ya no
   va aquí: es un dato de la ficha técnica (§5.3 de la clasificación). */
// «Marcha de agrupación musical», pero «Adaptación … para agrupación musical».
$tipoFrase = mb_strtolower($tipo, 'UTF-8') === 'marcha procesional' ? 'Marcha' : $tipo;
$prep = str_starts_with($tipoFrase, 'Marcha') ? ' de ' : ' para ';
$frase = Texto::de($tipoFrase . ($estilo !== '' ? $prep . mb_strtolower($estilo, 'UTF-8') : ''));
if ($autores !== []) {
    $firmas = [];
    foreach ($autores as $a) $firmas[] = Texto::de()->enlace($autoridad($a), $autorPath($a));
    $ultima = array_pop($firmas);
    $frase = $frase->texto(' de ')->mas($firmas === [] ? $ultima : Texto::unir($firmas, ', ')->texto(' y ')->mas($ultima));
}
$frase = ($anioOk ? $frase->texto(', ')->enlace((string) $m['FECHA'], $anioPath) : $frase)->texto('.');
if ($bandaPath !== '') {
    $frase = $frase->texto(' Estrenada por ')->enlace((string) $m['BANDA_NOMBRE'], $bandaPath)
        ->texto(($t($m['BANDA_LOC']) ? ' (' . $m['BANDA_LOC'] . ')' : '') . '.');
}
if ($hayDedic) {
    $frase = $frase->texto(' Dedicada a ' . $expandir((string) $m['DEDICATORIA']) . ($localidad !== '' ? ', ' . $localidad : '') . '.');
}

/* Ficha técnica: lo que se consulta de un vistazo. El estilo es texto
   enlazado al listado filtrado, nunca una etiqueta (§5 «Etiquetas»). */
$estiloHub = $estilo !== '' ? P::estiloHubPath((string) $m['ESTILO']) : null;
$datos = [];
if ($estilo !== '') $datos[] = new Dato('Estilo', $estilo, $estiloHub);
if (!$anioOk && $t($m['FECHA'])) $datos[] = new Dato('Año', (string) $m['FECHA']);
if (!$hayDedic && $localidad !== '') $datos[] = new Dato('Localidad', $localidad);
if ($duracion !== '') $datos[] = new Dato('Duración', $duracion);

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

<article class="c-ficha">
    <?= new CabeceraFicha((string) $m['TITULO'], $frase, $nGrab >= 12 ? [['Grabaciones', '#grabaciones']] : []) ?>

<?php /* La columna lateral va antes en el orden del documento: en móvil la
         ficha técnica sigue a la frase; en escritorio la hoja la pasa a la
         derecha. */ ?>
<?php if ($datos !== [] || $mosaico !== ''): ?>
    <aside class="c-ficha-lateral">
        <?= new FichaTecnica($datos) ?>
<?php if ($mosaico !== ''): ?>
        <section class="c-portadas">
            <h2>Discos donde está grabada</h2>
            <?= $mosaico ?>
        </section>
<?php endif; ?>
    </aside>
<?php endif; ?>

    <div class="c-ficha-principal">
<?php if ($notas !== ''): ?>
        <section>
            <h2>Notas</h2>
            <p class="notas"><?= $notas ?></p>
        </section>
<?php endif; ?>

        <section>
            <h2 id="escuchar">Escuchar</h2>
            <?= $escuchar ?>
        </section>

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

    <?= new PieFicha('M', $mid, $url ?? null) ?>
</article>
