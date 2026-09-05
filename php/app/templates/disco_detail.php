<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,mixed> $d @var string|null $url @var array<string,string> $enlaces */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$did = (int) $d['ID_DISCO'];
$anio = (int) (float) ($d['FECHA_CD'] ?? 0);
$multi = (int) $d['DISCOS'] > 1;
$nP = (int) $d['marchasLength'];
$coverSrc = H::coverSrc($did);
$detalles = $d['D_DETALLES'] ?? $d['d_DETALLES'] ?? null;

?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › <a href="/disco">Discos</a><?php if ($anio > 1800): ?> › <?= $anio ?><?php endif; ?> › D-<?= $did ?></span>
</div>

<article class="record">
    <div class="disco-head">
        <figure class="disco-cover">
            <?= H::cover($coverSrc, "Portada del disco '" . $d['NOMBRE_CD'] . "'", 'cover-large') ?>
        </figure>
        <div class="disco-meta">
            <h1><?= V::e($d['NOMBRE_CD']) ?></h1>
<?php /* Solo si la ficha no cabe en pantalla (ver marcha_detail.php). */ ?>
<?php if ($nP >= 12): ?>
            <nav class="rectabs" aria-label="Secciones de la ficha">
                <a href="#datos">Datos</a>
<?php if ($t($detalles)): ?>
                <a href="#notas">Notas</a>
<?php endif; ?>
                <a href="#contenido">Contenido (<?= $num($nP) ?>)</a>
            </nav>
<?php endif; ?>
            <dl class="desc" id="datos">
<?php if ($t($d['ID_BANDA'])): ?>
                <div class="f"><dt>Banda</dt><dd><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) ($d['BANDA_COMPLETO'] ?: $d['BANDA_BREVE']))) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?>, <?= V::e($d['BANDA_LOC']) ?><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($anio > 1800): ?>
                <div class="f"><dt>Año</dt><dd><?= $anio ?></dd></div>
<?php endif; ?>
                <div class="f"><dt>Pistas</dt><dd><?= $num($nP) ?></dd></div>
<?php if ($multi): ?>
                <div class="f"><dt>Volúmenes</dt><dd><?= (int) $d['DISCOS'] ?></dd></div>
<?php endif; ?>
            </dl>
            <?= H::streaming($enlaces ?? []) ?>
        </div>
    </div>

<?php if ($t($detalles)): ?>
    <div class="shead" id="notas"><h2>Notas</h2></div>
    <p class="notas"><?= str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($detalles)) ?></p>
<?php endif; ?>

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
            <th class="num" data-type="num">Vol. <span class="ar">↕</span></th>
<?php endif; ?>
            <th class="num" data-type="num">Pista <span class="ar">↕</span></th>
            <th>Marcha <span class="ar">↕</span></th>
            <th>Compositor <span class="ar">↕</span></th>
            <th data-type="num">Año <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($d['marchas'] as $m): ?>
            <tr>
<?php if ($multi): ?>
                <td class="num"><?= (int) $m['N_DISCO'] ?></td>
<?php endif; ?>
                <td class="num"><?= (int) $m['NUMEROMARCHA'] ?></td>
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

<?php if ($t($d['ID_BANDA'])): ?>
    <div class="shead"><h2>Véase también</h2></div>
    <ul class="vease">
        <li><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) ($d['BANDA_COMPLETO'] ?: $d['BANDA_BREVE']))) ?>"><?= V::e($d['BANDA_BREVE']) ?></a> — ficha y discografía de la banda <span class="cnt">B-<?= (int) $d['ID_BANDA'] ?></span></li>
    </ul>
<?php endif; ?>

    <div class="ids">
<?php if (!empty($url)): ?>
        <span>permalink: <?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></span>
<?php endif; ?>
    </div>
</article>
