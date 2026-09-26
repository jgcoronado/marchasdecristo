<?php use App\View as V; use App\Slug as S; use App\AcompSerie as A;
/** Acompañamientos históricos de una localidad (N-04; rediseño C, sep-2026):
 *  día → hermandad → paso. Cada día es una lista como la de "Últimas
 *  incorporaciones" de la portada: una fila por hermandad, con el nombre a la
 *  izquierda y sus pasos al lado. Por paso, una tira cronológica y debajo la
 *  serie completa, reciente → antiguo, con los años sin datos como "sin
 *  registro". Todo a la vista: sin desplegables (condición de Javier).
 *  La vista la monta App\AcompSerie; aquí solo se pinta.
 *  @var string $h1 @var string $localidad @var string $pieza ('paso' | 'trono')
 *  @var array{eje:array{ini:int,fin:int,n:int},nContratos:int,dias:list<array>} $serie */
$eje = $serie['eje'];
$dias = $serie['dias'];
?>
<div class="stack acs">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/acompanamientos">Acompañamientos</a> › <?= V::e($localidad) ?></span>
    </div>

    <header class="record acs-cab">
        <h1><?= V::e($h1) ?></h1>
        <p class="asiento">Qué banda ha acompañado cada año a cada <?= V::e($pieza) ?> de Cristo en <?= V::e($localidad) ?>, día a día y hermandad por hermandad. La barra de cada <?= V::e($pieza) ?> va de su primer año con registro a <?= $eje['fin'] ?>: el tramo intenso es la banda actual, los claros las anteriores y los huecos, años sin registro. Debajo, la lista completa, de lo más reciente a lo más antiguo.</p>
<?php if ($dias === []): ?>
        <p class="bio-empty">Todavía no hay acompañamientos registrados para <?= V::e($localidad) ?>.</p>
<?php endif; ?>
    </header>

<?php if (count($dias) > 1): ?>
        <nav class="acs-dias" aria-label="Días">
<?php foreach ($dias as $d): ?>
            <a href="#<?= V::e($d['slug']) ?>"><?= V::e($d['nombre']) ?></a>
<?php endforeach; ?>
        </nav>
<?php endif; ?>

<?php foreach ($dias as $d): ?>
        <section class="acs-dia" aria-labelledby="<?= V::e($d['slug']) ?>">
            <h2 class="section-title acs-dia-t" id="<?= V::e($d['slug']) ?>"><?= V::e($d['nombre']) ?></h2>
            <div class="acs-lista">
<?php foreach ($d['hermandades'] as $h): ?>
                <div class="acs-h" id="h-<?= V::e($h['slug']) ?>">
                        <h3><?= V::e($h['nombre']) ?></h3>
                    <div class="acs-pasos">
<?php foreach ($h['pasos'] as $p): ?>
                    <div class="acs-paso">
                        <p class="acs-paso-t"><?= V::e($p['nombre']) ?></p>
                        <div class="acs-tira" style="--carriles:<?= (int) $p['carriles'] ?>;--dec:<?= $p['dec'] ?>%;--dec0:<?= $p['dec0'] ?>%" aria-hidden="true">
<?php foreach ($p['tira'] as $t): ?>
                            <i class="t-<?= $t['tono'] ?>" style="left:<?= $t['left'] ?>%;width:<?= $t['width'] ?>%;top:calc(<?= (int) $t['carril'] ?> * var(--carril))" title="<?= V::e($t['title']) ?>"></i>
<?php endforeach; ?>
                        </div>
                        <ol class="acs-serie">
<?php foreach ($p['serie'] as $f): ?>
<?php if ($f['sinRegistro']): ?>
                            <li class="sin"><span class="anio"><?= V::e(A::anios($f['ini'], $f['fin'])) ?></span><span class="b"><i class="sw" aria-hidden="true"></i><span>sin registro</span></span></li>
<?php else: ?>
                            <li<?= $f['vigente'] ? ' class="vig"' : '' ?>><span class="anio"><?= V::e(A::anios($f['ini'], $f['fin'])) ?></span><span class="bandas">
<?php foreach ($f['lineas'] as $l): ?>
                                <span class="b<?= $l['vigente'] ? ' vig' : '' ?>"><i class="sw t-<?= $l['tono'] ?>" aria-hidden="true"></i><span><?php if ($l['tramo'] !== null): ?><span class="tramo"><?= V::e($l['tramo']) ?></span> <?php endif; ?><a href="<?= V::e(S::buildDetailPath('banda', $l['idBanda'], $l['banda'])) ?>"><?= V::e($l['banda']) ?></a><?php if ($l['anios'] !== null): ?> <span class="sub"><?= V::e($l['anios']) ?></span><?php endif; ?></span></span>
<?php endforeach; ?>
                            </span></li>
<?php endif; ?>
<?php endforeach; ?>
                        </ol>
                    </div>
<?php endforeach; ?>
                    </div>
                </div>
<?php endforeach; ?>
            </div>
        </section>
<?php endforeach; ?>
</div>
