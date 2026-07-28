<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array $result @var int $page @var int $limit
 *  @var array{provincia:list<array>} $facets */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

$showDate = static function ($fund, $ext): string {
    $funRes = ((int) $fund) > 1800 ? (string) $fund : 's/f';
    $extRes = ($ext === null || (int) $ext === 0) ? '' : ' – ' . $ext;
    return $funRes . $extRes;
};
$showLocalidad = static function ($loc, $prov): string {
    $isLoc = $loc && $loc !== '0';
    $isProv = $prov && $prov !== '0';
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
$arrow = static fn(string $col): string => $orden === $col ? ($dir === 'asc' ? '↑' : '↓') : '↕';

$total = (int) $result['totalRows'];
$hayFiltro = array_filter($criteria, static fn($x) => trim((string) $x) !== '') !== [];
?>
<div class="stack list-page">
    <div class="toolbar">
        <span class="rescount">Bandas — <b><?= $num($total) ?></b> registros</span>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/banda">limpiar filtros ×</a>
<?php endif; ?>
    </div>

    <details class="panel adv"<?= ($val('titulo') !== '' || $val('localidad') !== '') ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form action="/banda" method="GET">
            <div class="form-grid">
                <div class="field col-span-2">
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
            <div class="search-actions">
                <label class="muted" for="limit">Resultados por página</label>
                <select id="limit" name="limit" class="select">
<?php foreach ([10, 20, 50] as $opt): ?>
                    <option value="<?= $opt ?>"<?= $opt === $limit ? ' selected' : '' ?>><?= $opt ?></option>
<?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
            </div>
        </form>
    </details>

    <div class="results-layout">
        <aside class="facet-rail">
            <div class="rail-title">Refinar por</div>
<?php if ($facets['provincia'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Provincia</div>
<?php foreach ($facets['provincia'] as $f): $on = ($criteria['provincia'] ?? '') === $f['K']; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['provincia' => $on ? '' : $f['K']])) ?>"><?= V::e($f['K']) ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </aside>

        <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado bandas con esos criterios.</p>
<?php else: ?>
            <div class="scrollx tableList">
            <table class="reg">
                <thead><tr>
                    <th><a class="sortcol" href="<?= V::e($sortHref('nombre')) ?>">Nombre <span class="ar"><?= $arrow('nombre') ?></span></a></th>
                    <th><a class="sortcol" href="<?= V::e($sortHref('localidad')) ?>">Localidad <span class="ar"><?= $arrow('localidad') ?></span></a></th>
                    <th><a class="sortcol" href="<?= V::e($sortHref('fundacion')) ?>">Fundación <span class="ar"><?= $arrow('fundacion') ?></span></a></th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $b): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('banda', $b['ID_BANDA'], (string) $b['NOMBRE_COMPLETO'])) ?>"><?= V::e($b['NOMBRE_COMPLETO']) ?></a></td>
                        <td><?= V::e($showLocalidad($b['LOCALIDAD'], $b['PROVINCIA'])) ?></td>
                        <td><?= V::e($showDate($b['FECHA_FUND'], $b['FECHA_EXT'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/banda', $criteria) ?>
<?php endif; ?>
        </section>
    </div>
</div>
