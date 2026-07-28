<?php use App\View as V; use App\Slug as S;
/** Contratos banda↔hermandad de una temporada (N-04/N-05), agrupados por
 *  ciudad (heurístico: localidad más frecuente entre las bandas de cada
 *  hermandad ese año — ver docs/n03-hermandad.md) y luego por hermandad.
 *  @var string $h1  @var string $anio
 *  @var array<string,array<string,array{nombre:string,ciudad:string,items:list<array<string,mixed>>}>> $porCiudad */
$anioInt = (int) $anio;
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <?= V::e($h1) ?></span>
        <span class="regnav">
            <a href="/temporada/<?= $anioInt - 1 ?>">&larr; <?= $anioInt - 1 ?></a>
            &nbsp;·&nbsp;
            <a href="/temporada/<?= $anioInt + 1 ?>"><?= $anioInt + 1 ?> &rarr;</a>
        </span>
    </div>

    <article class="record">
        <div class="head">
            <span class="eb">Temporada</span>
            <span class="sig">MDC · contratos</span>
        </div>
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda toca este año tras cada paso, hermandad a hermandad.</p>

<?php if ($porCiudad === []): ?>
        <p class="bio-empty">Todavía no hay contratos registrados para <?= V::e($anio) ?>.</p>
<?php else: ?>
<?php foreach ($porCiudad as $ciudad => $hermandades): ?>
        <div class="shead shead-ciudad"><h2><?= V::e($ciudad) ?></h2></div>
<?php foreach ($hermandades as $g): ?>
        <div class="shead"><h3><?= V::e($g['nombre']) ?></h3></div>
        <ul class="vease">
<?php foreach ($g['items'] as $it): ?>
            <li>→
<?php if (!empty($it['TITULAR'])): ?>
                <strong><?= V::e($it['TITULAR']) ?></strong> —
<?php endif; ?>
                <a href="<?= V::e(S::buildDetailPath('banda', $it['ID_BANDA'], (string) $it['BANDA'])) ?>"><?= V::e($it['BANDA']) ?></a>
            </li>
<?php endforeach; ?>
        </ul>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
    </article>
</div>
