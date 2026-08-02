<?php use App\View as V; use App\Slug as S; use App\Pages as P;
/** @var list<array<string,mixed>> $masAutor, $masDedica, $masEstreno, $masGrabada
 *  @var list<array{K:int,N:int}> $anios  más reciente primero */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

// Agrupar por década (N-07/M9: "series por década") en vez de una lista plana
// de ~86 años — mismo patrón azgrid/aznav que el índice de dedicatorias.
$decadas = [];
foreach ($anios as $a) {
    $d = intdiv((int) $a['K'], 10) * 10;
    $decadas[$d][] = $a;
}
krsort($decadas);
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › Rankings</span>
</div>

<article class="record">
    <h1>Rankings de música procesional</h1>
    <p class="asiento">Los compositores con más marchas, las bandas con más estrenos y las marchas más grabadas
        de siempre — y los récords de cada año. Para saber qué falta por documentar, en vez de qué ya destaca,
        está el <a href="/estado-catalogo">estado del catálogo</a>.</p>

    <div class="stack">
        <details class="collapse">
            <summary class="collapse-title">Autores que más marchas han compuesto</summary>
            <div class="collapse-content">
                <div class="tableList">
                    <table class="table table-zebra">
                        <thead class="thead-neutral"><tr><td>Nombre</td><td>Marchas compuestas</td></tr></thead>
                        <tbody>
<?php foreach ($masAutor as $a): ?>
                            <tr>
                                <td><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], (string) $a['AUTOR'])) ?>"><?= V::e($a['AUTOR']) ?></a></td>
                                <td><?= V::e($a['MARCHAS']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="collapse">
            <summary class="collapse-title">Hermandades con más marchas dedicadas</summary>
            <div class="collapse-content">
                <div class="tableList">
                    <table class="table table-zebra">
                        <thead class="thead-neutral"><tr><td>Nombre</td><td>Marchas dedicadas</td></tr></thead>
                        <tbody>
<?php foreach ($masDedica as $d): ?>
                            <tr><td><?= V::e($d['LUGAR']) ?></td><td><?= V::e($d['CUENTA']) ?></td></tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="collapse">
            <summary class="collapse-title">Bandas que más marchas han estrenado</summary>
            <div class="collapse-content">
                <div class="tableList">
                    <table class="table table-zebra">
                        <thead class="thead-neutral"><tr><td>Banda</td><td>Marchas estrenadas</td></tr></thead>
                        <tbody>
<?php foreach ($masEstreno as $e): ?>
                            <tr>
                                <td><a href="<?= V::e(S::buildDetailPath('banda', $e['ID_BANDA'], (string) $e['BANDA'])) ?>"><?= V::e($e['BANDA']) ?></a></td>
                                <td><?= V::e($e['MARCHAS']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="collapse">
            <summary class="collapse-title">Marchas que más veces han sido grabadas</summary>
            <div class="collapse-content">
                <div class="tableList">
                    <table class="table table-zebra">
                        <thead class="thead-neutral"><tr><td>Marcha</td><td>Autor/es</td><td>Grabaciones</td></tr></thead>
                        <tbody>
<?php foreach ($masGrabada as $g): ?>
                            <tr>
                                <td><a href="<?= V::e(S::buildDetailPath('marcha', $g['ID_MARCHA'], (string) $g['TITULO'])) ?>"><?= V::e($g['TITULO']) ?></a></td>
                                <td>
<?php foreach ($g['AUTOR'] as $a): ?>
                                    <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                                </td>
                                <td><?= V::e($g['GRABACIONES']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    </div>

<?php if ($decadas !== []): ?>
    <div class="shead"><h2>Rankings por año</h2></div>
    <nav class="aznav" aria-label="Saltar a década">
<?php foreach (array_keys($decadas) as $d): ?>
        <a href="#decada-<?= $d ?>"><?= $d ?>s</a>
<?php endforeach; ?>
    </nav>
<?php foreach ($decadas as $d => $lista): ?>
    <div class="shead" id="decada-<?= $d ?>">
        <h3>Años <?= $d ?>–<?= $d + 9 ?></h3>
    </div>
    <ul class="azgrid">
<?php foreach ($lista as $a): ?>
        <li class="azitem">
            <a href="<?= V::e(P::rankingsAnioPath($a['K'])) ?>">
                <span class="aznom"><?= V::e($a['K']) ?></span>
            </a>
            <span class="azcount"><?= $num($a['N']) ?></span>
        </li>
<?php endforeach; ?>
    </ul>
<?php endforeach; ?>
<?php endif; ?>
</article>
