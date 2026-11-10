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
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/disco">Discos</a><?php if ($anio > 1800): ?> / <?= $anio ?><?php endif; ?></span>
</div>

<article class="record">
<?php /* Cabecera: la portada es la imagen de la ficha. Si no existe, la tesela
         deja su marcador (inicial y año) y la composición no se descoloca. A la
         derecha, la tabla de datos: son tres filas y en columna aparte dejaban
         un hueco bajo ellas. */ ?>
    <header class="fh">
        <div class="portada-disco"><?= H::tesela($did, (string) $d['NOMBRE_CD'], $anio > 1800 ? $anio : null) ?></div>
        <div class="fh-txt">
            <p class="fh-k"><span class="dato">Disco</span></p>
            <h1><?= V::e($d['NOMBRE_CD']) ?></h1>
<?php if ($bandaPath !== null): ?>
            <p class="fh-by">de <a href="<?= V::e($bandaPath) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?> (<?= V::e($d['BANDA_LOC']) ?>)<?php endif; ?></p>
<?php endif; ?>
            <?= $botones ?>
        </div>
        <div class="fh-datos">
    <dl class="desc" id="datos">
<?php if ($bandaPath !== null): ?>
        <div class="f"><dt>Banda</dt><dd><a href="<?= V::e($bandaPath) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?>, <?= V::e($d['BANDA_LOC']) ?><?php endif; ?><span class="cnt">ficha y discografía de la banda</span></dd></div>
<?php endif; ?>
<?php if ($anio > 1800): ?>
        <div class="f"><dt>Año</dt><dd><?= $anio ?></dd></div>
<?php endif; ?>
        <div class="f"><dt>Pistas</dt><dd><?= $num($nP) ?></dd></div>
<?php if ($multi): ?>
        <div class="f"><dt>Volúmenes</dt><dd><?= (int) $d['DISCOS'] ?></dd></div>
<?php endif; ?>
    </dl>
        </div>
    </header>

<?php if ($t($detalles)): ?>
    <section class="blk">
    <div class="shead" id="notas"><h2>Notas</h2></div>
    <p class="notas"><?= str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($detalles)) ?></p>
    </section>
<?php endif; ?>
    <section class="blk">
    <div class="shead" id="contenido">
        <h2>Contenido</h2>
        <span class="n" id="pistas-count"><?= $num($nP) ?> pistas</span>
<?php if ($nP >= 10): ?>
        <input class="filter" type="text" placeholder="filtrar pistas…" aria-label="Filtrar pistas" data-filter="pistas-table" data-count="pistas-count" data-total="<?= $nP ?>">
<?php endif; ?>
    </div>
    <div class="scrollx">
    <table class="reg" id="pistas-table" data-sortable>
        <thead><tr>
<?php if ($multi): ?>
            <th scope="col" class="num mobile-hide" data-type="num">Vol. <span class="ar">↕</span></th>
<?php endif; ?>
            <th scope="col" class="num mobile-hide" data-type="num">Pista <span class="ar">↕</span></th>
            <th scope="col">Marcha <span class="ar">↕</span></th>
            <th scope="col">Compositor <span class="ar">↕</span></th>
            <th scope="col" data-type="num">Año <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($d['marchas'] as $m): ?>
            <tr>
<?php if ($multi): ?>
                <td class="num mobile-hide"><?= (int) $m['N_DISCO'] ?></td>
<?php endif; ?>
                <td class="num mobile-hide"><?= (int) $m['NUMEROMARCHA'] ?></td>
                <td><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                    <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                </td>
                <td><?= $t($m['FECHA']) ? V::e($m['FECHA']) : '—' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>

    <div class="ids">
<?php if (!empty($url)): ?>
        <span>Enlace permanente: <?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></span>
<?php endif; ?>
    </div>
</article>
