<?php use App\View as V; use App\Slug as S;
/** Índice de localidades con acompañamientos históricos (N-04, rehecho
 *  2026-08-29 — reemplaza a /temporada, que listaba años en vez de
 *  localidades). @var string $h1 @var list<array{LOCALIDAD:string,N:int}> $localidades */
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <?= V::e($h1) ?></span>
    </div>

    <article class="record">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha tocado cada año tras cada paso de Cristo, hermandad a hermandad. Elige una localidad.</p>

<?php if ($localidades === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados.</p>
<?php else: ?>
        <ul class="vease">
<?php foreach ($localidades as $l): ?>
<?php $slug = S::slugify((string) $l['LOCALIDAD']); ?>
            <li><a href="/acompanamientos/<?= V::e($slug) ?>"><?= V::e($l['LOCALIDAD']) ?></a> <span class="cnt"><?= number_format((int) $l['N'], 0, ',', '.') ?></span></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </article>
</div>
