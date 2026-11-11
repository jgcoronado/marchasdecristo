<?php use App\View as V; use App\Slug as S;
/** Índice de localidades con acompañamientos históricos (N-04, rehecho
 *  2026-08-29 — reemplaza a /temporada, que listaba años en vez de
 *  localidades). Misma gramática que /acompanamientos/{localidad}: cabecera en
 *  .record y debajo una lista de filas alternas; cada fila, entera pulsable,
 *  con el número de acompañamientos de la localidad (las barras se quitaron:
 *  en las pequeñas eran un punto de color y la cifra basta).
 *  $otras (fuera de Repo::ACOMP_LOCALIDADES_PRINCIPALES) llega vacía donde
 *  Secciones::ACOMPANAMIENTOS_OTRAS no está publicada.
 *  @var string $h1 @var list<array{LOCALIDAD:string,NOMBRE:string,N:int}> $localidades
 *  @var list<array{LOCALIDAD:string,NOMBRE:string,N:int}> $otras
 *  @var array<string,list<array{LOCALIDAD:string,NOMBRE:string,N:int}>> $otrasPorProvincia
 *       las mismas $otras por provincia ('' = sin provincia, la última) */
$todas = array_merge($localidades, $otras);
$lista = static function (array $filas): void { ?>
    <ul class="acs-locs">
<?php foreach ($filas as $l): ?>
<?php $n = (int) $l['N']; ?>
        <li><a class="acs-loc" href="/acompanamientos/<?= V::e(S::slugify((string) $l['LOCALIDAD'])) ?>">
            <span class="acs-loc-t"><?= V::e($l['NOMBRE']) ?></span>
            <span class="acs-loc-n" title="<?= $n === 1 ? 'acompañamiento' : 'acompañamientos' ?>"><?= number_format($n, 0, ',', '.') ?></span>
        </a></li>
<?php endforeach; ?>
        </ul>
<?php };
?>
<div class="stack acs">
    <div class="crumbs">
        <span><a href="/">Inicio</a> / <?= V::e($h1) ?></span>
    </div>

    <header class="record">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha acompañado cada año a cada paso de Cristo, día a día y hermandad por hermandad. Elige una localidad; la cifra indica cuántos acompañamientos hay registrados en cada una.</p>
<?php if ($todas === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados.</p>
<?php endif; ?>
    </header>

<?php if ($localidades !== []) $lista($localidades); ?>

<?php if ($otras !== []): ?>
    <div class="shead"><h2>Otras localidades</h2></div>
<?php foreach ($otrasPorProvincia as $prov => $filas): ?>
    <h3><?= $prov === '' ? 'Sin provincia' : V::e((string) $prov) ?></h3>
<?php $lista($filas); ?>
<?php endforeach; ?>
<?php endif; ?>
</div>
