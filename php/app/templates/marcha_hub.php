<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Repo;
/** Hub de catálogo indexable (C1): año / estilo / provincia.
 *  @var string $h1  @var string $intro  @var array $result  @var int $page
 *  @var string $basePath  canónica del hub sin ?page
 *  @var list<array{href:string,label:string,cnt:?int}> $vease
 *  @var ?array{autor:?array,estreno:?array,grabada:?array} $anuario  solo en el hub de año (N-08) */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
$total = (int) $result['totalRows'];
?>
<div class="stack list-page">
    <div class="crumbs">
        <span><a href="/">Inicio</a> / <a href="/marcha">Marchas</a> / <?= V::e($h1) ?></span>
    </div>

    <div class="toolbar">
        <h1 class="rescount"><?= V::e($h1) ?> — <b><?= $num($total) ?></b> marchas</h1>
    </div>

    <p class="welcome-text"><?= V::e($intro) ?></p>

<?php if (!empty($anuario)): ?>
    <div class="panel">
        <h2 class="section-title">Resumen del año</h2>
        <ul class="vease">
<?php if ($anuario['autor']): $a = $anuario['autor']; ?>
            <li>Compositor con más marchas: <a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], (string) $a['AUTOR'])) ?>"><?= V::e($a['AUTOR']) ?></a> <span class="cnt"><?= $num($a['MARCHAS']) ?></span></li>
<?php endif; ?>
<?php if ($anuario['estreno']): $e = $anuario['estreno']; ?>
            <li>Banda con más estrenos: <a href="<?= V::e(S::buildDetailPath('banda', $e['ID_BANDA'], (string) $e['BANDA'])) ?>"><?= V::e($e['BANDA']) ?></a> <span class="cnt"><?= $num($e['MARCHAS']) ?></span></li>
<?php endif; ?>
<?php if ($anuario['grabada']): $g = $anuario['grabada']; ?>
            <li>Marcha más grabada: <a href="<?= V::e(S::buildDetailPath('marcha', $g['ID_MARCHA'], (string) $g['TITULO'])) ?>"><?= V::e($g['TITULO']) ?></a> <span class="cnt"><?= $num($g['GRABACIONES']) ?> <?= (int) $g['GRABACIONES'] === 1 ? 'grabación' : 'grabaciones' ?></span></li>
<?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

    <section>
        <div class="tableList">
        <table class="t">
            <thead><tr>
                <th>Marcha</th>
                <th class="t-num">Año</th>
                <th>Compositor</th>
                <th>Provincia</th>
                <th class="t-num">Grab.</th>
            </tr></thead>
            <tbody>
<?php foreach ($result['data'] as $m): ?>
                <tr>
XX, $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                    <td class="t-num" data-l=""><?= !empty($m['FECHA']) ? V::e($m['FECHA']) : '<span class="t-vacio">—</span>' ?></td>
                    <td data-l="">
<?php foreach ($m['AUTOR'] as $a): ?>
                        <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                    </td>
                    <td data-l=""><?= !empty($m['PROVINCIA']) ? V::e($m['PROVINCIA']) : '<span class="t-vacio">—</span>' ?></td>
                    <td class="t-num" data-l="Grab."><?= (int) $m['N_GRAB'] ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?= H::pagination($page, $total, Repo::HUB_PAGE_SIZE, $basePath, [], false) ?>
    </section>

<?php if ($vease !== []): ?>
    <div class="shead"><h2>Véase también</h2></div>
    <ul class="vease">
<?php foreach ($vease as $vs): ?>
        <li><a href="<?= V::e($vs['href']) ?>"><?= V::e($vs['label']) ?></a><?php if ($vs['cnt'] !== null): ?> <span class="cnt"><?= $num($vs['cnt']) ?> registros</span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>
