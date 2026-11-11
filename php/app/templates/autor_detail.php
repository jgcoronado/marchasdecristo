<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,mixed> $a @var string $fullName @var string|null $url @var int|null $pm */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$aid = (int) $a['ID_AUTOR'];
$ap = trim((string) ($a['APELLIDOS'] ?? ''));
$nac = (int) ($a['F_NAC'] ?? 0);
$def = (int) ($a['F_DEF'] ?? 0);
$letra = $ap !== '' ? mb_strtoupper(mb_substr($ap, 0, 1)) : '';

$nM = (int) $a['marchasLength'];
$nG = (int) $a['N_GRAB_TOTAL'];
$ppal = $a['BANDA_PPAL'] ?? null;
?>
<?php $actividad = (int) $a['ACT_DESDE'] > 0 ? ($a['ACT_DESDE'] === $a['ACT_HASTA'] ? (string) $a['ACT_DESDE'] : $a['ACT_DESDE'] . '–' . $a['ACT_HASTA']) : ''; ?>
<?php
/* Frase de cabecera: «Compositor de N marchas, entre 1969 y 2010.» y, si la
   hay, su banda principal. Solo datos reales. */
$frase = 'Compositor de ' . ($nM === 1 ? 'una marcha' : $num($nM) . ' marchas')
    . ($actividad !== '' ? ((int) $a['ACT_DESDE'] === (int) $a['ACT_HASTA'] ? ', en ' . $actividad : ', entre ' . (int) $a['ACT_DESDE'] . ' y ' . (int) $a['ACT_HASTA']) : '') . '.';
if ($ppal && (int) $ppal['N'] > 1) {
    $frase .= ' Vinculado sobre todo a <a href="' . V::e(S::buildDetailPath('banda', $ppal['ID_BANDA'], (string) $ppal['NOMBRE_BREVE'])) . '">' . V::e($ppal['NOMBRE_BREVE']) . '</a>'
        . ($t($ppal['LOCALIDAD']) ? ' (' . V::e($ppal['LOCALIDAD']) . ')' : '') . ', que estrenó ' . $num($ppal['N']) . ' de sus marchas.';
}
/* Los pocos datos personales (nombre artístico, nacimiento, muerte) y el
   total de grabaciones van en la frase: una tarjeta «Datos» de uno o dos
   renglones dejaba la columna estrecha vacía miles de px (§0.4). */
$vida = [];
if ($t($a['NOMBRE_ART'])) $vida[] = 'Conocido como ' . V::e($a['NOMBRE_ART']);
if ($nac > 1000 || $t($a['LUGAR_NAC'])) $vida[] = 'Nacido' . ($t($a['LUGAR_NAC']) ? ' en ' . V::e($a['LUGAR_NAC']) : '') . ($nac > 1000 ? ($t($a['LUGAR_NAC']) ? ', ' : ' en ') . $nac : '');
if ($def > 1000) $vida[] = 'fallecido en ' . $def;
if ($vida !== []) $frase .= ' ' . ucfirst(implode('; ', $vida)) . '.';
if ($nG > 0) $frase .= ' Su obra suma ' . ($nG === 1 ? 'una grabación' : $num($nG) . ' grabaciones') . '.';

/* Obra agrupada por décadas: una fila de subtítulo por década dentro de la
   misma tabla (se lee bien a 390 px y el filtro sigue valiendo). Sin fecha, al
   final. Por eso la tabla ya no se ordena por columnas. */
$porDecada = [];
foreach ($a['marchas'] as $m) {
    $y = preg_match('/^\d{4}$/', (string) $m['FECHA']) === 1 ? (int) $m['FECHA'] : 0;
    $porDecada[$y > 0 ? intdiv($y, 10) * 10 : 0][] = $m + ['_Y' => $y];
}
ksort($porDecada);
if (isset($porDecada[0])) { $sf = $porDecada[0]; unset($porDecada[0]); $porDecada[0] = $sf; }
foreach ($porDecada as &$filasD) usort($filasD, static fn(array $x, array $y): int => [$x['_Y'], (string) $x['TITULO']] <=> [$y['_Y'], (string) $y['TITULO']]);
unset($filasD);

$portadas = [];
foreach ($a['DISCOS_OBRA'] ?? [] as $d) {
    $portadas[] = ['id' => (int) $d['ID_DISCO'], 'nombre' => (string) $d['NOMBRE_CD'],
        'anio' => (int) ($d['FECHA_CD'] ?? 0) > 1800 ? (int) $d['FECHA_CD'] : null];
}
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/autor">Compositores</a></span>
</div>

<article class="ficha ficha-autor">
    <header class="ficha-cab">
        <h1><?= V::e($fullName) ?></h1>
        <p class="ficha-frase"><?= $frase ?></p>
<?php if ($nM >= 12): ?>
        <nav class="ficha-anclas" aria-label="Secciones de la ficha"><a href="#obra">Obra</a><?php if ($t($a['BIO'])): ?> · <a href="#bio">Biografía</a><?php endif; ?></nav>
<?php endif; ?>
    </header>
<?php $mosaico = H::mosaico($portadas, 8, 'Discos con su obra'); if ($mosaico !== ''): ?>
    <div class="ficha-portadas"><?= $mosaico ?></div>
<?php endif; ?>

<?php if ($t($a['BIO']) || !empty($pm)): ?>
    <div class="ficha-lado"><div class="ficha-notas">
<?php if ($t($a['BIO'])): ?>
    <section class="blk">
    <div class="shead" id="bio"><h2>Biografía</h2></div>
    <p class="notas"><?= str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($a['BIO'])) ?></p>
    </section>
<?php endif; ?>
<?php if (!empty($pm)): ?>
    <section class="blk">
    <div class="shead"><h2>En banda de música</h2></div>
    <p class="notas">Las marchas de <?= V::e($fullName) ?> para banda de música están en Patrimonio Musical. <a href="https://www.patrimoniomusical.com/bd-autor-<?= (int) $pm ?>" target="_blank" rel="noopener">Ver su ficha en Patrimonio Musical</a></p>
    </section>
<?php endif; ?>
    </div></div>
<?php endif; ?>

    <div class="ficha-cuerpo">
    <section class="blk">
    <div class="shead" id="obra">
        <h2>Obra</h2>
        <span class="n" id="obra-count"><?= $num($nM) ?> marchas · por décadas</span>
<?php if ($nM >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar…" aria-label="Filtrar marchas del compositor" data-filter="obra-table" data-count="obra-count" data-total="<?= $nM ?>">
<?php endif; ?>
    </div>
    <div class="scrollx">
    <table class="t t-grupos" id="obra-table">
        <thead><tr>
            <th scope="col">Marcha</th>
            <th scope="col">Banda de estreno</th>
            <th scope="col">Dedicatoria</th>
            <th scope="col" class="t-num">Año</th>
            <th scope="col" class="t-num">Grab.</th>
        </tr></thead>
        <tbody>
<?php foreach ($porDecada as $dec => $filasD): ?>
            <tr class="t-grupo"><th scope="rowgroup" colspan="5"><?= $dec > 0 ? $dec . '–' . ($dec + 9) : 'Sin fecha' ?> <span class="muted">· <?= count($filasD) ?></span></th></tr>
<?php foreach ($filasD as $m): ?>
            <tr>
                <td class="t-main"><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                <td data-l=""><?php if ($t($m['BANDA_ESTRENO'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_BREVE'])) ?>"><?= V::e($m['BANDA_BREVE']) ?></a><?php else: ?><span class="t-vacio">—</span><?php endif; ?></td>
                <td data-l=""><?php if ($t($m['DEDICATORIA'])): ?><?= V::e($m['DEDICATORIA']) ?><?php if ($t($m['LOCALIDAD'])): ?> (<?= V::e($m['LOCALIDAD']) ?>)<?php endif; ?><?php else: ?><span class="t-vacio">—</span><?php endif; ?></td>
                <td class="t-num" data-l=""><?= $m['_Y'] > 0 ? $m['_Y'] : '<span class="t-vacio">—</span>' ?></td>
                <td class="t-num" data-l="Grabaciones"><?= (int) $m['N_GRAB'] > 0 ? (int) $m['N_GRAB'] : '<span class="t-vacio">—</span>' ?></td>
            </tr>
<?php endforeach; ?>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>
    </div>

    <footer class="ficha-pie">Ficha A-<?= $aid ?><?php if (!empty($url)): ?> · enlace permanente: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a><?php endif; ?></footer>
</article>
