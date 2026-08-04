<?php use App\View as V; use App\Slug as S;
/** Récords de un año concreto (N-07): drill-down de /rankings.
 *  @var string $h1  @var string $anio
 *  @var list<array<string,mixed>> $masAutor, $masEstreno, $masGrabada
 *  @var list<array{href:string,label:string,cnt:?int}> $vease */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="stack list-page">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/rankings">Rankings</a> › <?= V::e($anio) ?></span>
    </div>

    <div class="toolbar">
        <span class="rescount"><?= V::e($h1) ?></span>
    </div>

    <div class="stack">
<?php if ($masAutor === [] && $masEstreno === [] && $masGrabada === []): ?>
        <p class="welcome-text">No hay datos suficientes de <?= V::e($anio) ?> para calcular rankings todavía.</p>
<?php endif; ?>
<?php if ($masAutor !== []): ?>
        <details class="collapse" open>
            <summary class="collapse-title">Compositores de <?= V::e($anio) ?></summary>
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
<?php endif; ?>

<?php if ($masEstreno !== []): ?>
        <details class="collapse" open>
            <summary class="collapse-title">Bandas que más estrenaron en <?= V::e($anio) ?></summary>
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
<?php endif; ?>

<?php if ($masGrabada !== []): ?>
        <details class="collapse" open>
            <summary class="collapse-title">Marchas de <?= V::e($anio) ?> más grabadas</summary>
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
<?php endif; ?>
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
