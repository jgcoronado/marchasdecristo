<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array $result @var int $page @var int $limit
 *  @var array{decada:list<array>} $facets */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** URL del explorador con los criterios actuales más/menos los cambios dados. */
$href = static function (array $over) use ($criteria): string {
    $q = array_filter(array_merge($criteria, $over), static fn($x) => $x !== '' && $x !== null);
    unset($q['page']);
    return '/disco' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$orden = (string) ($criteria['orden'] ?? 'anio');
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
        <span class="rescount">Discos — <b><?= $num($total) ?></b> registros</span>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/disco">limpiar filtros ×</a>
<?php endif; ?>
    </div>

    <details class="panel adv"<?= $val('nombre') !== '' ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form action="/disco" method="GET">
            <div class="form-grid">
                <div class="field col-span-2">
                    <label class="field-label" for="nombre">Nombre del disco</label>
                    <input id="nombre" class="input" type="text" name="nombre" value="<?= $val('nombre') ?>" placeholder="Fons Vitae…">
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
<?php if ($facets['decada'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Década</div>
<?php foreach ($facets['decada'] as $f):
    $d0 = (string) $f['K'];
    $on = ($criteria['decada'] ?? '') === $d0; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['decada' => $on ? '' : $d0])) ?>"><?= V::e($d0) ?>s<?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </aside>

        <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado discos con esos criterios.</p>
<?php else: ?>
            <div class="scrollx tableList">
            <table class="reg">
                <thead><tr>
                    <th><a class="sortcol" href="<?= V::e($sortHref('nombre')) ?>">Nombre <span class="ar"><?= $arrow('nombre') ?></span></a></th>
                    <th><a class="sortcol" href="<?= V::e($sortHref('banda')) ?>">Banda <span class="ar"><?= $arrow('banda') ?></span></a></th>
                    <th><a class="sortcol" href="<?= V::e($sortHref('anio')) ?>">Año <span class="ar"><?= $arrow('anio') ?></span></a></th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $d): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></td>
                        <td>
<?php if (!empty($d['ID_BANDA'])): ?>
                            <a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA'])) ?>"><?= V::e($d['BANDA']) ?></a>
<?php else: ?>
                            <span class="muted">—</span>
<?php endif; ?>
                        </td>
                        <td><?= !empty($d['FECHA_CD']) ? V::e($d['FECHA_CD']) : '—' ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/disco', $criteria) ?>
<?php endif; ?>
        </section>
    </div>
</div>
