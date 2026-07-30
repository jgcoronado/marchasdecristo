<?php use App\View as V; use App\Slug as S;
/** @var array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,marchas:list<array<string,mixed>>,N:int} $d
 *  @var string $url */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$did = (int) $d['ID_DEDIC'];
$loc = trim((string) $d['LOCALIDAD']);
$prov = trim((string) ($d['PROVINCIA'] ?? ''));
$nM = (int) $d['N'];
$conAudio = 0;
foreach ($d['marchas'] as $m) { if ($t($m['AUDIO'])) $conAudio++; }
$letra = strtoupper(mb_substr($d['NOMBRE'], 0, 1));

?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › <a href="/dedicatorias">Dedicatorias</a><?php if ($letra !== ''): ?> › <?= V::e($letra) ?><?php endif; ?> › D-<?= $did ?></span>
    <span class="regnav"><a href="/dedicatorias">↩ índice</a></span>
</div>

<article class="record">
    <h1>Marchas dedicadas a <?= V::e($d['NOMBRE']) ?></h1>

    <dl class="desc">
        <div class="f"><dt>Advocación</dt><dd><?= V::e($d['NOMBRE']) ?></dd></div>
<?php if ($loc !== ''): ?>
        <div class="f"><dt>Localidad</dt><dd><?= V::e($loc) ?></dd></div>
<?php endif; ?>
<?php if ($prov !== ''): ?>
        <div class="f"><dt>Provincia</dt><dd><a href="/marcha?provincia=<?= V::e(rawurlencode($prov)) ?>"><?= V::e($prov) ?></a></dd></div>
<?php endif; ?>
        <div class="f"><dt>Marchas dedicadas</dt><dd><?= $num($nM) ?></dd></div>
<?php if ($conAudio > 0): ?>
        <div class="f"><dt>Con audio/vídeo</dt><dd><?= $num($conAudio) ?> <span class="cnt">(<?= (int) round($conAudio / $nM * 100) ?> %)</span></dd></div>
<?php endif; ?>
    </dl>

    <div class="shead">
        <h2>Marchas dedicadas</h2>
        <span class="n" id="ded-count"><?= $num($nM) ?> marchas · orden cronológico</span>
<?php if ($nM >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar…" aria-label="Filtrar marchas dedicadas" data-filter="ded-table" data-count="ded-count" data-total="<?= $nM ?>">
<?php endif; ?>
    </div>
    <div class="scrollx">
    <table class="reg" id="ded-table" data-sortable>
        <thead><tr>
            <th>Marcha <span class="ar">↕</span></th>
            <th data-type="num">Año <span class="ar">↕</span></th>
            <th>Compositor <span class="ar">↕</span></th>
            <th>Banda de estreno <span class="ar">↕</span></th>
            <th class="num" data-type="num">Grab. <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($d['marchas'] as $m): ?>
            <tr>
                <td>
                    <a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a>
<?php if ($t($m['AUDIO'])): ?> <span class="badge-1a" title="Con audio/vídeo">▶</span><?php endif; ?>
                </td>
                <td><?= $t($m['FECHA']) ? V::e($m['FECHA']) : '—' ?></td>
                <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                    <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                </td>
                <td><?php if ($t($m['BANDA_ESTRENO']) && $t($m['BANDA_BREVE'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_BREVE'])) ?>"><?= V::e($m['BANDA_BREVE']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td class="num"><?= (int) $m['N_GRAB'] ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="shead"><h2>Véase también</h2></div>
    <ul class="vease">
        <li>→ <a href="/dedicatorias">Índice de dedicatorias</a> — todas las advocaciones del catálogo</li>
<?php if ($prov !== ''): ?>
        <li>→ <a href="/marcha?provincia=<?= V::e(rawurlencode($prov)) ?>">Marchas de <?= V::e($prov) ?></a> — explorar por provincia</li>
<?php endif; ?>
    </ul>

    <div class="ids">
        <span>permalink: <?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></span>
    </div>
</article>
