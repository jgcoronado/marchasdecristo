<?php use App\View as V; use App\Slug as S; use App\Pages as P;
/** Marchas que cumplen aniversario redondo en un año (N-09).
 *  @var string $h1  @var string $anio
 *  @var list<array{ANIOS:int,ANIO_COMPUESTO:int,result:array}> $tramos
 *  @var list<array{href:string,label:string,cnt:?int}> $vease */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="stack list-page">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/aniversarios">Aniversarios</a> › <?= V::e($anio) ?></span>
    </div>

    <div class="toolbar">
        <span class="rescount"><?= V::e($h1) ?></span>
    </div>

    <p class="welcome-text">Marchas procesionales que cumplen un aniversario redondo (25, 50, 75, 100 años o
        más) en <?= V::e($anio) ?>, agrupadas por el año en que se compusieron.</p>

    <div class="stack">
<?php foreach ($tramos as $t):
    $centenario = $t['ANIOS'] % 100 === 0;
    $result = $t['result'];
    $total = (int) $result['totalRows']; ?>
        <details class="collapse" open>
            <summary class="collapse-title"><?php if ($centenario): ?>🎉 <?php endif; ?><?= $t['ANIOS'] ?> años — compuestas en <?= $t['ANIO_COMPUESTO'] ?><?php if ($centenario): ?> (centenario)<?php endif; ?></summary>
            <div class="collapse-content">
                <div class="scrollx tableList">
                <table class="reg">
                    <thead><tr>
                        <th>Marcha</th>
                        <th>Compositor</th>
                        <th>Prov.</th>
                        <th class="num">Grab.</th>
                    </tr></thead>
                    <tbody>
<?php foreach ($result['data'] as $m): ?>
                        <tr>
                            <td><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                            <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                                <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                            </td>
                            <td><?= !empty($m['PROVINCIA']) ? V::e($m['PROVINCIA']) : '<span class="muted">—</span>' ?></td>
                            <td class="num"><?= (int) $m['N_GRAB'] ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
                </div>
<?php if ($total > (int) $result['rowsReturned']): ?>
                <p class="muted">Mostrando <?= $num($result['rowsReturned']) ?> de <?= $num($total) ?> —
                    <a href="<?= V::e(P::anioHubPath($t['ANIO_COMPUESTO'])) ?>">ver el catálogo completo de <?= $t['ANIO_COMPUESTO'] ?></a>.</p>
<?php endif; ?>
            </div>
        </details>
<?php endforeach; ?>
    </div>

<?php if ($vease !== []): ?>
    <div class="shead"><h2>Véase también</h2></div>
    <ul class="vease">
<?php foreach ($vease as $vs): ?>
        <li><a href="<?= V::e($vs['href']) ?>"><?= V::e($vs['label']) ?></a><?php if ($vs['cnt'] !== null): ?> <span class="cnt"><?= $num($vs['cnt']) ?> registros</span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>
