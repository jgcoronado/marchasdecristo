<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array $result @var int $page @var int $limit
 *  @var array{provincia:list<array>} $facets */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$showDate = static function ($fund, $ext): string {
    $funRes = ((int) $fund) > 1800 ? (string) $fund : 's/f';
    $extRes = $ext === null ? '' : ' – ' . $ext;
    return $funRes . $extRes;
};
// Los sentinelas heredados de la era MySQL llegan como 0 o "0"; los dos son
// falsy en PHP, así que basta la comprobación de verdad para descartarlos.
$showLocalidad = static function ($loc, $prov): string {
    $isLoc = (bool) $loc;
    $isProv = (bool) $prov;
    if ($isLoc && $isProv) return "$loc ($prov)";
    if ($isLoc) return (string) $loc;
    return '';
};

/** URL del explorador con los criterios actuales más/menos los cambios dados. */
$href = static function (array $over) use ($criteria): string {
    $q = array_filter(array_merge($criteria, $over), static fn($x) => $x !== '' && $x !== null);
    unset($q['page']);
    return '/banda' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$orden = (string) ($criteria['orden'] ?? 'nombre');
$dir = (string) ($criteria['dir'] ?? 'asc');
/** Enlace de cabecera ordenable: alterna asc/desc al reordenar por la misma columna. */
$sortHref = static function (string $col) use ($href, $orden, $dir): string {
    $next = ($orden === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $href(['orden' => $col, 'dir' => $next]);
};
$ariaSort = static fn(string $col): string => $orden === $col ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';

$total = (int) $result['totalRows'];
$hayFiltro = array_filter($criteria, static fn($x) => trim((string) $x) !== '') !== [];
$nFiltros = count(array_filter(array_intersect_key($criteria, ['titulo' => 1, 'localidad' => 1, 'provincia' => 1]), static fn($x) => trim((string) $x) !== ''));
// Por página: a la derecha del título en escritorio, dentro de «Filtros» en
// móvil (mismo patrón que /marcha; r/tablas.css enseña una u otra copia).
$controles = H::porPagina($limit, '/banda', $criteria);
?>
<div class="stack list-page">
    <div class="toolbar">
        <h1 class="rescount"><b><?= $num($total) ?></b> bandas</h1>
        <div class="t-ctl"><?= $controles ?></div>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/banda">limpiar filtros ×</a>
<?php endif; ?>
    </div>

    <details class="filtros"<?= ($val('titulo') !== '' || $val('localidad') !== '') ? ' open' : '' ?>>
        <summary>Filtros<?php if ($nFiltros > 0): ?> <span class="n">(<?= $nFiltros ?>)</span><?php endif; ?></summary>
        <div class="filtros-panel">
        <div class="t-ctl t-ctl-movil"><?= $controles ?></div>
        <form class="adv-form" aria-label="Búsqueda avanzada" action="/banda" method="GET">
            <div class="adv-grid">
                <div class="field">
                    <label class="field-label" for="titulo">Nombre</label>
                    <input id="titulo" class="input" type="text" name="titulo" value="<?= $val('titulo') ?>" placeholder="Sagrada Columna y Azotes…">
                </div>
                <div class="field">
                    <label class="field-label" for="localidad">Localidad</label>
                    <input id="localidad" class="input" type="text" name="localidad" value="<?= $val('localidad') ?>" placeholder="Palma del Río…">
                </div>
                <div class="field">
                    <label class="field-label" for="provincia">Provincia</label>
                    <input id="provincia" class="input" type="text" name="provincia" value="<?= $val('provincia') ?>" placeholder="Huelva…">
                </div>
            </div>
            <div class="adv-actions">
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
<?php if ($hayFiltro): ?>
                <a href="/banda">limpiar</a>
<?php endif; ?>
            </div>
        </form>

        <div class="facet-groups" aria-label="Refinar por">
<?php if ($facets['provincia'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Provincia</div>
<?php foreach ($facets['provincia'] as $f): $on = ($criteria['provincia'] ?? '') === $f['K']; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['provincia' => $on ? '' : $f['K']])) ?>"><?= V::e($f['K']) ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </div>
        </div>
    </details>

    <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado bandas con esos criterios.</p>
<?php else: ?>
            <div class="tableList">
            <table class="t">
                <thead><tr>
                    <th aria-sort="<?= $ariaSort('nombre') ?>"><a class="sortcol" href="<?= V::e($sortHref('nombre')) ?>">Nombre</a></th>
                    <th aria-sort="<?= $ariaSort('localidad') ?>"><a class="sortcol" href="<?= V::e($sortHref('localidad')) ?>">Localidad</a></th>
                    <th class="t-num" aria-sort="<?= $ariaSort('fundacion') ?>"><a class="sortcol" href="<?= V::e($sortHref('fundacion')) ?>">Fundación</a></th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $b): ?>
                    <tr>
                        <td class="t-main" data-l=""><a href="<?= V::e(S::buildDetailPath('banda', $b['ID_BANDA'], (string) $b['NOMBRE_COMPLETO'])) ?>"><?= V::e($b['NOMBRE_COMPLETO']) ?></a></td>
                        <td data-l=""><?= V::e($showLocalidad($b['LOCALIDAD'], $b['PROVINCIA'])) ?></td>
                        <td class="t-num" data-l=""><?= V::e($showDate($b['FECHA_FUND'], $b['FECHA_EXT'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/banda', $criteria) ?>
<?php endif; ?>
    </section>
</div>
