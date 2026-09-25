<?php use App\View as V; use App\Slug as S; use App\AcompSerie as A;
/** Acompañamientos históricos de una localidad (N-04; rediseño B, sep-2026):
 *  día → hermandad → paso, y por cada paso una tira cronológica y debajo la
 *  serie completa año a año, reciente → antiguo, con los años sin datos como
 *  "sin registro". Todo a la vista: sin desplegables (condición de Javier).
 *  La vista la monta App\AcompSerie; aquí solo se pinta.
 *  @var string $h1 @var string $localidad @var string $pieza ('paso' | 'trono')
 *  @var array{eje:array{ini:int,fin:int,n:int},nContratos:int,dias:list<array>} $serie */
$eje = $serie['eje'];
$dias = $serie['dias'];
$piezas = $pieza === 'trono' ? 'tronos' : 'pasos';
// Líneas de década de la tira: ancho de 10 años y desfase hasta la primera década.
$dec = round(10 / $eje['n'] * 100, 4);
$dec0 = round(((10 - $eje['ini'] % 10) % 10) / $eje['n'] * 100, 4);
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/acompanamientos">Acompañamientos</a> › <?= V::e($localidad) ?></span>
    </div>

    <article class="record acs" style="--dec:<?= $dec ?>%;--dec0:<?= $dec0 ?>%">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha tocado cada año tras cada <?= V::e($pieza) ?> de Cristo en <?= V::e($localidad) ?>, por días y hermandades. La barra de cada <?= V::e($pieza) ?> va de <?= $eje['ini'] ?> a <?= $eje['fin'] ?>, con una marca por década; los tramos vacíos son años sin registro. Debajo, la serie completa, de más reciente a más antiguo.</p>

<?php if ($dias === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados para <?= V::e($localidad) ?>.</p>
<?php else: ?>
<?php if (count($dias) > 1): ?>
        <nav class="acs-dias" aria-label="Días">
<?php foreach ($dias as $d): ?>
            <a href="#<?= V::e($d['slug']) ?>"><?= V::e($d['nombre']) ?></a>
<?php endforeach; ?>
        </nav>
<?php endif; ?>

<?php foreach ($dias as $d): ?>
        <section class="acs-dia" aria-labelledby="<?= V::e($d['slug']) ?>">
            <h2 class="acs-dia-t" id="<?= V::e($d['slug']) ?>"><?= V::e($d['nombre']) ?></h2>
            <div class="acs-cols">
<?php foreach ($d['hermandades'] as $h): ?>
                <div class="acs-h" id="h-<?= V::e($h['slug']) ?>">
                    <h3><?= V::e($h['nombre']) ?></h3>
                    <p class="meta"><?= $h['nPasos'] ?> <?= $h['nPasos'] === 1 ? V::e($pieza) : V::e($piezas) ?> · <?= V::e(A::anios($h['primero'], $h['ultimo'])) ?></p>
<?php foreach ($h['pasos'] as $p): ?>
                    <div class="acs-paso">
                        <p class="acs-paso-t"><?= V::e($p['nombre']) ?></p>
                        <div class="acs-tira" style="--carriles:<?= (int) $p['carriles'] ?>" aria-hidden="true">
<?php foreach ($p['tira'] as $t): ?>
                            <i class="t-<?= $t['tono'] ?>" style="left:<?= $t['left'] ?>%;width:<?= $t['width'] ?>%;top:calc(<?= (int) $t['carril'] ?> * var(--carril))" title="<?= V::e($t['title']) ?>"></i>
<?php endforeach; ?>
                        </div>
                        <ol class="acs-serie">
<?php foreach ($p['serie'] as $f): ?>
<?php if ($f['sinRegistro']): ?>
                            <li class="sin"><span class="anio"><?= V::e(A::anios($f['ini'], $f['fin'])) ?></span><span>sin registro</span></li>
<?php else: ?>
                            <li<?= $f['vigente'] ? ' class="vig"' : '' ?>><span class="anio"><?= V::e(A::anios($f['ini'], $f['fin'])) ?></span><span class="bandas">
<?php foreach ($f['lineas'] as $l): ?>
                                <span class="b<?= $l['vigente'] && count($f['lineas']) > 1 ? ' vig' : '' ?>"><?php if ($l['tramo'] !== null): ?><span class="tramo"><?= V::e($l['tramo']) ?></span> <?php endif; ?><span><a href="<?= V::e(S::buildDetailPath('banda', $l['idBanda'], $l['banda'])) ?>"><?= V::e($l['banda']) ?></a><?php if ($l['anios'] !== null): ?> <span class="sub"><?= V::e($l['anios']) ?></span><?php endif; ?></span></span>
<?php endforeach; ?>
                            </span></li>
<?php endif; ?>
<?php endforeach; ?>
                        </ol>
                    </div>
<?php endforeach; ?>
                </div>
<?php endforeach; ?>
            </div>
        </section>
<?php endforeach; ?>
<?php endif; ?>
    </article>
</div>
