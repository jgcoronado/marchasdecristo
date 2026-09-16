<?php use App\View as V; use App\Slug as S;
/** Acompañamientos históricos de una localidad, por hermandad y paso (N-04,
 *  rehecho 2026-08-29 — reemplaza a /temporada/{anio}, que enseñaba un año
 *  cada vez). Los años se colapsan en rangos con la misma banda (ver
 *  Repo::agruparAcompanamientos): más reciente arriba, hasta el año más
 *  antiguo con datos — no hay huecos rellenados, un año sin contrato
 *  conocido simplemente no aparece.
 *  @var string $h1 @var string $localidad
 *  @var list<array{slug:string,nombre:string,titulares:list<array{titular:?string,
 *       rangos:list<array{anioInicio:int,anioFin:int,idBanda:int,banda:string,actual:bool}>}>}> $hermandades */
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/acompanamientos">Acompañamientos</a> › <?= V::e($localidad) ?></span>
    </div>

    <article class="record">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha tocado cada año tras cada paso de Cristo en <?= V::e($localidad) ?>, hermandad a hermandad — de más reciente a más antiguo.</p>

<?php if ($hermandades === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados para <?= V::e($localidad) ?>.</p>
<?php else: ?>
<?php if (count($hermandades) > 8): ?>
        <nav class="acomp-jump" aria-label="Índice de hermandades">
<?php foreach ($hermandades as $h): ?>
            <a href="#h-<?= V::e($h['slug']) ?>"><?= V::e($h['nombre']) ?></a>
<?php endforeach; ?>
        </nav>
<?php endif; ?>

<?php foreach ($hermandades as $h): ?>
        <div class="shead" id="h-<?= V::e($h['slug']) ?>"><h2><?= V::e($h['nombre']) ?></h2></div>
<?php foreach ($h['titulares'] as $t): ?>
<?php if ($t['titular'] !== null): ?>
        <p class="acomp-paso"><?= V::e($t['titular']) ?></p>
<?php endif; ?>
        <ul class="vease acomp-rangos">
<?php foreach ($t['rangos'] as $rg): ?>
            <li class="<?= $rg['actual'] ? 'acomp-actual' : '' ?>">
                <span class="anio"><?= $rg['anioInicio'] === $rg['anioFin'] ? (int) $rg['anioInicio'] : ((int) $rg['anioInicio'] . '–' . (int) $rg['anioFin']) ?>:</span>
                <a href="<?= V::e(S::buildDetailPath('banda', $rg['idBanda'], $rg['banda'])) ?>"><?= V::e($rg['banda']) ?></a>
            </li>
<?php endforeach; ?>
        </ul>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>
    </article>
</div>
