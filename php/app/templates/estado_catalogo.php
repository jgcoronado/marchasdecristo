<?php use App\View as V; use App\Slug as S;
/** @var array{total:int,conAudio:int,pct:float} $global
 *  @var list<array{K:int,TOTAL:int,CON_AUDIO:int,PCT:float}> $porAnio
 *  @var list<array{ID_BANDA:int,BANDA:string,TOTAL:int,CON_AUDIO:int,PCT:float}> $porBanda */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
$pct = static fn(float $p): string => number_format($p, 1, ',', '.') . '%';
$nBandas = count($porBanda);
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › Estado del catálogo</span>
</div>

<article class="record">
    <h1>Estado del catálogo</h1>
    <p class="asiento">Cuántas marchas tienen ya una grabación enlazada para escuchar (audio propio o enlace de
        streaming) y cuántas faltan todavía. Es la medida de cobertura de la campaña de audio del catálogo — se
        actualiza sola a medida que se revisan candidatos y se enlazan grabaciones, no hace falta rehacer este
        recuento a mano.</p>

    <dl class="desc" id="datos">
        <div class="f"><dt>Cobertura global</dt><dd><?= $pct($global['pct']) ?>
            <span class="cnt"><?= $num($global['conAudio']) ?> de <?= $num($global['total']) ?> marchas</span></dd></div>
    </dl>

<?php if ($porAnio !== []): ?>
    <div class="shead" id="por-anio"><h2>Cobertura por año</h2>
        <span class="n"><?= $num(count($porAnio)) ?> años con marchas catalogadas</span>
    </div>
    <div class="scrollx">
    <table class="reg" data-sortable>
        <thead><tr>
            <th data-type="num">Año <span class="ar">↕</span></th>
            <th data-type="num">Marchas <span class="ar">↕</span></th>
            <th data-type="num">Con audio <span class="ar">↕</span></th>
            <th data-type="num">Cobertura <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($porAnio as $a): ?>
            <tr>
                <td><a href="/marcha/ano/<?= (int) $a['K'] ?>"><?= (int) $a['K'] ?></a></td>
                <td><?= $num($a['TOTAL']) ?></td>
                <td><?= $num($a['CON_AUDIO']) ?></td>
                <td><?= $pct($a['PCT']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php if ($porBanda !== []): ?>
    <div class="shead" id="por-banda"><h2>Cobertura por banda</h2>
        <span class="n" id="banda-count"><?= $num($nBandas) ?> · peor cobertura primero</span>
<?php if ($nBandas >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar bandas…" aria-label="Filtrar bandas" data-filter="banda-table" data-count="banda-count" data-total="<?= $nBandas ?>">
<?php endif; ?>
    </div>
    <p class="muted small">Solo bandas con 3 o más marchas de estreno catalogadas — con menos, un único hueco
        distorsiona el porcentaje sin aportar una prioridad real de curación.</p>
    <div class="scrollx">
    <table class="reg" id="banda-table" data-sortable>
        <thead><tr>
            <th>Banda <span class="ar">↕</span></th>
            <th data-type="num">Marchas <span class="ar">↕</span></th>
            <th data-type="num">Con audio <span class="ar">↕</span></th>
            <th data-type="num">Cobertura <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($porBanda as $b): ?>
            <tr>
                <td><a href="<?= V::e(S::buildDetailPath('banda', $b['ID_BANDA'], (string) $b['BANDA'])) ?>"><?= V::e($b['BANDA']) ?></a></td>
                <td><?= $num($b['TOTAL']) ?></td>
                <td><?= $num($b['CON_AUDIO']) ?></td>
                <td><?= $pct($b['PCT']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</article>
