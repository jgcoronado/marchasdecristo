<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,mixed> $d @var string|null $url @var array<string,string> $enlaces */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$did = (int) $d['ID_DISCO'];
$anio = (int) ($d['FECHA_CD'] ?? 0);
$multi = (int) $d['DISCOS'] > 1;
$nP = (int) $d['marchasLength'];
$coverSrc = H::coverSrc($did);
$detalles = $d['D_DETALLES'] ?? $d['d_DETALLES'] ?? null;

?>
<?php
$bandaPath = $t($d['ID_BANDA']) ? S::buildDetailPath('banda', $d['ID_BANDA'], (string) ($d['BANDA_COMPLETO'] ?: $d['BANDA_BREVE'])) : null;
$botones = H::streaming($enlaces ?? []);

/* Frase de cabecera: «Disco de <banda>, <año>. N pistas.» Solo lo que hay. */
$frase = 'Disco'
    . ($bandaPath !== null ? ' de <a href="' . V::e($bandaPath) . '">' . V::e($d['BANDA_BREVE']) . '</a>' . ($t($d['BANDA_LOC']) ? ' (' . V::e($d['BANDA_LOC']) . ')' : '') : '')
    . ($anio > 1800 ? ', ' . $anio : '') . '. '
    . ($nP === 1 ? 'Una pista' : $num($nP) . ' pistas')
    . ($multi ? ' en ' . (int) $d['DISCOS'] . ' volúmenes' : '') . '.';
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/disco">Discos</a><?php if ($anio > 1800): ?> / <?= $anio ?><?php endif; ?></span>
</div>

<article class="ficha ficha-disco">
<?php /* La portada grande va junto al título. Debajo de la imagen está la
         funda tipográfica (título y banda): si la portada no existe o falla,
         Html::cover la oculta y queda la funda, sin descolocar nada. */ ?>
    <div class="ficha-portadas">
        <div class="funda-g">
            <span class="funda-txt" aria-hidden="true"><b><?= V::e($d['NOMBRE_CD']) ?></b><?php if ($t($d['BANDA_BREVE'])): ?><span><?= V::e($d['BANDA_BREVE']) ?></span><?php endif; ?></span>
<?php if (H::hayPortada($did) !== false): ?>
            <?= H::cover($coverSrc, 'Portada de ' . (string) $d['NOMBRE_CD'], 'funda-img') ?>
<?php endif; ?>
        </div>
    </div>
    <header class="ficha-cab">
        <h1><?= V::e($d['NOMBRE_CD']) ?></h1>
        <p class="ficha-frase"><?= $frase ?></p>
    </header>

<?php if ($botones !== ''): ?>
    <section class="ficha-escuchar blk">
    <div class="shead"><h2>Escuchar</h2></div>
    <?= $botones ?>
    </section>
<?php endif; ?>

<?php if ($t($detalles)): ?>
    <section class="ficha-notas blk">
    <div class="shead" id="notas"><h2>Notas</h2></div>
    <p class="notas"><?= str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($detalles)) ?></p>
    </section>
<?php endif; ?>

    <div class="ficha-cuerpo">
    <section class="blk">
    <div class="shead" id="contenido">
        <h2>Contenido</h2>
        <span class="n" id="pistas-count"><?= $num($nP) ?> pistas</span>
<?php if ($nP >= 10): ?>
        <input class="filter" type="text" placeholder="filtrar pistas…" aria-label="Filtrar pistas" data-filter="pistas-table" data-count="pistas-count" data-total="<?= $nP ?>">
<?php endif; ?>
    </div>
    <div class="scrollx">
    <table class="t" id="pistas-table" data-sortable>
        <thead><tr>
            <th scope="col" class="t-num" data-type="num"><?= $multi ? 'Vol./pista' : 'Pista' ?> <span class="ar">↕</span></th>
            <th scope="col">Marcha <span class="ar">↕</span></th>
            <th scope="col">Compositor <span class="ar">↕</span></th>
            <th scope="col" class="t-num" data-type="num">Año <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($d['marchas'] as $m): ?>
            <tr>
                <td class="t-num" data-l="" data-v="<?= (int) $m['N_DISCO'] * 1000 + (int) $m['NUMEROMARCHA'] ?>"><?= $multi ? (int) $m['N_DISCO'] . '.' : '' ?><?= (int) $m['NUMEROMARCHA'] ?></td>
                <td class="t-main"><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                <td data-l=""><?php foreach ($m['AUTOR'] as $i => $a): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a><?php endforeach; ?><?php if ($m['AUTOR'] === []): ?><span class="muted">—</span><?php endif; ?></td>
                <td class="t-num" data-l=""><?= $t($m['FECHA']) ? V::e($m['FECHA']) : '<span class="muted">—</span>' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>
    </div>

    <footer class="ficha-pie">Ficha D-<?= $did ?><?php if (!empty($url)): ?> · enlace permanente: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a><?php endif; ?></footer>
</article>
