<?php use App\View as V; use App\Slug as S;
/** Índice de localidades con acompañamientos históricos (N-04, rehecho
 *  2026-08-29 — reemplaza a /temporada, que listaba años en vez de
 *  localidades). Misma gramática que /acompanamientos/{localidad}: cabecera en
 *  .record y debajo una lista de filas alternas; cada fila, entera pulsable,
 *  con una barra proporcional al número de acompañamientos de la localidad.
 *  $otras (fuera de Repo::ACOMP_LOCALIDADES_PRINCIPALES) llega vacía donde
 *  Secciones::ACOMPANAMIENTOS_OTRAS no está publicada.
 *  @var string $h1 @var list<array{LOCALIDAD:string,NOMBRE:string,N:int}> $localidades
 *  @var list<array{LOCALIDAD:string,NOMBRE:string,N:int}> $otras */
$todas = array_merge($localidades, $otras);
$max = $todas === [] ? 0 : max(array_map('intval', array_column($todas, 'N')));
$lista = static function (array $filas) use ($max): void { ?>
    <ul class="acs-locs">
<?php foreach ($filas as $l): ?>
<?php $n = (int) $l['N']; ?>
        <li><a class="acs-loc" href="/acompanamientos/<?= V::e(S::slugify((string) $l['LOCALIDAD'])) ?>">
            <span class="acs-loc-t"><?= V::e($l['NOMBRE']) ?></span>
            <span class="acs-loc-barra" aria-hidden="true"><i style="width:<?= $max > 0 ? round($n / $max * 100, 2) : 0 ?>%"></i></span>
            <span class="acs-loc-n"><b><?= number_format($n, 0, ',', '.') ?></b> acompañamientos</span>
        </a></li>
<?php endforeach; ?>
        </ul>
<?php };
?>
<div class="stack acs">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <?= V::e($h1) ?></span>
    </div>

    <header class="record">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha acompañado cada año a cada paso de Cristo, día a día y hermandad por hermandad. Elige una localidad; la barra indica cuántos acompañamientos hay registrados en cada una.</p>
<?php if ($todas === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados.</p>
<?php endif; ?>
    </header>

<?php if ($localidades !== []) $lista($localidades); ?>

<?php if ($otras !== []): ?>
    <div class="shead"><h2>Otras localidades</h2></div>
<?php $lista($otras); ?>
<?php endif; ?>
</div>
