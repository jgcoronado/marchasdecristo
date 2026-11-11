<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Pages as P; use App\EnlaceRepo as ER;
/** @var array<string,mixed> $m
 *  @var array{original: array<string,string>, actual: array<string,string>} $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */
/** @var list<array<string,mixed>> $relacionadas  Repo::marchasRelacionadas */

// "truthy" al estilo JS: null, '', 0, 0.0 y false son falsos; '0' (string) es verdadero.
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);

$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

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
    'CCTT' => 'Cornetas y Tambores',
    'AM' => 'Agrupación Musical',
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

// Portadas de sus grabaciones: el mosaico de la cabecera.
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
$nombresAutores = [];
foreach ($autores as $a) {
    $nombresAutores[] = '<a href="' . V::e($autorPath($a)) . '">' . V::e($autoridad($a)) . '</a>';
}
$firma = match (count($nombresAutores)) {
    0 => '',
    1 => $nombresAutores[0],
    default => implode(', ', array_slice($nombresAutores, 0, -1)) . ' y ' . end($nombresAutores),
};
$hayDedic = $t($m['DEDICATORIA']) && $m['DEDICATORIA'] !== '0';

/* Frase de cabecera: la ficha se presenta en una oración con lo que se sabe
   («Marcha de agrupación musical de X, 1969. Estrenada por Y. Dedicada a Z.»).
   Solo datos reales: lo que falta se omite, no se rellena. Lo que ya dice la
   frase no se repite en la lista de datos. */
// «Marcha de agrupación musical», pero «Adaptación … para agrupación musical».
$tipoFrase = mb_strtolower($tipo, 'UTF-8') === 'marcha procesional' ? 'Marcha' : $tipo;
$prep = str_starts_with($tipoFrase, 'Marcha') ? ' de ' : ' para ';
$frase = V::e($tipoFrase)
    . match ($m['ESTILO'] ?? null) { 'CCTT' => $prep . 'cornetas y tambores', 'AM' => $prep . 'agrupación musical', default => '' }
    . ($firma !== '' ? ' de ' . $firma : '')
    . ($anioOk ? ', <a href="' . V::e(P::anioHubPath((string) $m['FECHA'])) . '">' . V::e($m['FECHA']) . '</a>' : '') . '.';
if ($t($m['BANDA_ESTRENO'])) {
    $frase .= ' Estrenada por <a href="' . V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) . '">' . V::e($m['BANDA_NOMBRE']) . '</a>'
        . ($t($m['BANDA_LOC']) ? ' (' . V::e($m['BANDA_LOC']) . ')' : '') . '.';
}
if ($hayDedic) {
    $frase .= ' Dedicada a ' . V::e($expandir((string) $m['DEDICATORIA'])) . ($localidad !== '' ? ', ' . V::e($localidad) : '') . '.';
}

/* Escuchar: una sola botonera homogénea, partida en original / actual cuando
   la marcha lleva bastantes años sobre la calle. Ver Html::escuchar. */
$enl = $enlaces ?? ['original' => [], 'actual' => []];
$anioMarcha = $anioOk ? (int) $m['FECHA'] : null;
$escuchar = H::escuchar($enl, $m['AUDIO'] ?? null, ER::admiteVersiones($anioMarcha), $mid);
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/marcha">Marchas</a><?php if ($anioOk): ?> / <a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= V::e($m['FECHA']) ?></a><?php endif; ?></span>
</div>

<article class="ficha ficha-marcha">
    <header class="ficha-cab">
        <p class="ficha-k"><?= H::chipEstilo($m['ESTILO'] ?? null) ?></p>
        <h1><?= V::e($m['TITULO']) ?></h1>
        <p class="ficha-frase"><?= $frase ?></p>
<?php /* Atajos de texto, solo en móvil y solo si la ficha es larga. */ ?>
<?php if ($nGrab >= 12): ?>
        <nav class="ficha-anclas" aria-label="Secciones de la ficha"><a href="#datos">Datos</a> · <a href="#grabaciones">Grabaciones</a></nav>
<?php endif; ?>
    </header>
<?php if ($portadas !== []): ?>
    <div class="ficha-portadas"><?= H::mosaico($portadas, 8, count($portadas) === 1 ? 'Disco donde está grabada' : 'Discos donde está grabada') ?></div>
<?php endif; ?>

<?php /* Escuchar va primero y sin reproductor incrustado: nada de terceros
         hasta que el visitante decide irse. Sin enlaces, el hueco pide la
         grabación en vez de desaparecer. */ ?>
    <section class="ficha-escuchar blk">
    <div class="shead" id="escuchar"><h2>Escuchar</h2></div>
<?php if ($escuchar !== ''): ?>
    <?= $escuchar ?>
<?php else: ?>
    <?= H::escucharVacio() ?>
<?php endif; ?>
    </section>

    <section class="ficha-datos blk">
    <div class="shead"><h2>Datos</h2></div>
    <dl class="desc" id="datos">
<?php foreach ($autores as $a): if ((int) $a['N_MARCHAS'] > 1): ?>
        <div class="f"><dt>Obra de <?= V::e($autoridad($a)) ?></dt><dd><a href="<?= V::e($autorPath($a)) ?>"><?= $num($a['N_MARCHAS']) ?> marchas</a></dd></div>
<?php endif; endforeach; ?>
<?php if ($t($m['BANDA_ESTRENO']) && (int) $m['BANDA_ESTRENOS'] > 1): ?>
        <div class="f"><dt>Estrenos de la banda</dt><dd><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) ?>"><?= $num($m['BANDA_ESTRENOS']) ?> marchas</a></dd></div>
<?php endif; ?>
<?php if ($anioOk && (int) $m['N_MISMO_ANIO'] > 1): ?>
        <div class="f"><dt>Del mismo año</dt><dd><a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= $num($m['N_MISMO_ANIO']) ?> marchas</a></dd></div>
<?php elseif (!$anioOk && $t($m['FECHA'])): ?>
        <div class="f"><dt>Año</dt><dd><?= V::e($m['FECHA']) ?></dd></div>
<?php endif; ?>
<?php if (!$hayDedic && $localidad !== ''): ?>
        <div class="f"><dt>Localidad</dt><dd><?= V::e($localidad) ?></dd></div>
<?php endif; ?>
<?php if ($estilo !== ''): $estiloHub = P::estiloHubPath((string) $m['ESTILO']); ?>
        <div class="f"><dt>Estilo</dt><dd><?php if ($estiloHub !== null): ?><a href="<?= V::e($estiloHub) ?>"><?= V::e($estilo) ?></a><?php else: ?><?= V::e($estilo) ?><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['PROVINCIA']) && (int) $m['N_MISMA_PROV'] > 1): ?>
        <div class="f"><dt>Provincia</dt><dd><a href="<?= V::e(P::provinciaHubPath((string) $m['PROVINCIA'])) ?>"><?= V::e($m['PROVINCIA']) ?></a> <span class="cnt"><?= $num($m['N_MISMA_PROV']) ?> marchas</span></dd></div>
<?php endif; ?>
<?php if ($duracion !== ''): ?>
        <div class="f"><dt>Duración</dt><dd><?= V::e($duracion) ?></dd></div>
<?php endif; ?>
<?php if ($nGrab > 0): ?>
        <div class="f"><dt>Grabaciones</dt><dd><?= $num($nGrab) ?></dd></div>
<?php endif; ?>
    </dl>
    </section>

<?php if ($notas !== ''): ?>
    <section class="ficha-notas blk">
    <div class="shead"><h2>Notas</h2></div>
    <p class="notas"><?= $notas ?></p>
    </section>
<?php endif; ?>

    <div class="ficha-cuerpo">
    <section class="blk">
    <div class="shead" id="grabaciones">
        <h2>Grabaciones</h2>
<?php if ($nGrab > 0): ?>
        <span class="n" id="grab-count"><?= $num($nGrab) ?> · orden cronológico</span>
<?php if ($nGrab >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar grabaciones…" aria-label="Filtrar grabaciones" data-filter="grab-table" data-count="grab-count" data-total="<?= $nGrab ?>">
<?php endif; ?>
<?php endif; ?>
    </div>
<?php if ($nGrab === 0): ?>
    <p class="bio-empty">Aún sin grabaciones documentadas.</p>
<?php else: ?>
<?php /* Ordenar una tabla de dos filas no ordena nada: con menos de tres
         grabaciones la cabecera se queda quieta y sin flechas. */
$ordenable = count($m['discos']) > 2; $ar = $ordenable ? ' <span class="ar">↕</span>' : ''; ?>
    <div class="scrollx">
    <table class="t" id="grab-table"<?= $ordenable ? ' data-sortable' : '' ?>>
        <thead><tr>
            <th scope="col">Grabación<?= $ar ?></th>
            <th scope="col">Banda<?= $ar ?></th>
            <th scope="col" class="t-num" data-type="num">Año<?= $ar ?></th>
            <th scope="col" class="t-num" data-type="num">Duración<?= $ar ?></th>
        </tr></thead>
        <tbody>
<?php foreach ($m['discos'] as $d):
    $anio = (int) ($d['FECHA_CD'] ?? 0);
?>
            <tr>
                <td class="t-main"><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></td>
                <td data-l=""><?php if ($t($d['ID_BANDA'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA_BREVE'])) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?> · <?= V::e($d['BANDA_LOC']) ?><?php endif; ?><?php else: ?><span class="t-vacio">—</span><?php endif; ?></td>
                <td class="t-num" data-l=""><?= $anio > 1800 ? $anio : '<span class="t-vacio">—</span>' ?></td>
                <?php /* La duración mostrada es la REAL del track. Si abre con
                         intro de percusión se marca con una etiqueta de texto. */ ?>
                <td class="t-num" data-l=""><?php if (!empty($d['DURACION_SEG'])): ?><?= gmdate('i:s', (int) $d['DURACION_SEG']) ?><?php if (!empty($d['PERCUSION'])): ?> <abbr class="perc" title="Empieza con introducción de percusión (unos 40 s de tambores antes de la marcha)">perc.</abbr><?php endif; ?><?php else: ?><span class="t-vacio">—</span><?php endif; ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
    </section>

<?php if (!empty($relacionadas)): ?>
    <section class="blk">
    <div class="shead"><h2>Véase también</h2><span class="n">mismo compositor, dedicatoria o banda</span></div>
    <div class="scrollx">
    <table class="t">
        <thead><tr><th scope="col">Marcha</th><th scope="col">Compositor</th><th scope="col" class="t-num" data-type="num">Año</th></tr></thead>
        <tbody>
<?php foreach ($relacionadas as $r): ?>
            <tr>
                <td class="t-main"><a href="<?= V::e(S::buildDetailPath('marcha', $r['ID_MARCHA'], (string) $r['TITULO'])) ?>"><?= V::e($r['TITULO']) ?></a></td>
                <td data-l=""><?= $r['AUTOR'] !== [] ? V::e(V::listaY(array_map(static fn(array $a): string => (string) $a['nombre'], $r['AUTOR']))) : '<span class="t-vacio">—</span>' ?></td>
                <td class="t-num" data-l=""><?= preg_match('/^\d{4}$/', (string) $r['FECHA']) === 1 ? V::e($r['FECHA']) : '<span class="t-vacio">—</span>' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>
<?php endif; ?>
    </div>

    <footer class="ficha-pie">Ficha M-<?= $mid ?><?php if (!empty($url)): ?> · enlace permanente: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a><?php endif; ?></footer>
</article>
