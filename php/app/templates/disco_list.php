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
$ariaSort = static fn(string $col): string => $orden === $col ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';

$total = (int) $result['totalRows'];
/* Vista (§2.6): por defecto, rejilla de fundas —un disco se reconoce por la
   portada—; ?vista=tabla deja la tabla de siempre para quien quiera ordenar
   y comparar columnas. No viaja en $criteria (no es un criterio de búsqueda),
   así que se añade a mano a los enlaces de orden y paginación de la tabla. */
$tabla = ($_GET['vista'] ?? '') === 'tabla';
$conVista = static fn(string $u): string => $tabla ? $u . (str_contains($u, '?') ? '&' : '?') . 'vista=tabla' : $u;
$hayFiltro = array_filter($criteria, static fn($x) => trim((string) $x) !== '') !== [];
?>
<div class="stack list-page">
    <div class="toolbar">
        <h1 class="rescount"><b><?= $num($total) ?></b> discos</h1>
        <?= H::porPagina($limit, '/disco', $criteria) ?>
        <span class="seg vista-seg" aria-label="Vista">
            <a class="seg-op<?= $tabla ? '' : ' on' ?>" href="<?= V::e($href([])) ?>"<?= $tabla ? '' : ' aria-current="true"' ?>>Portadas</a>
            <a class="seg-op<?= $tabla ? ' on' : '' ?>" href="<?= V::e($href(['vista' => 'tabla'])) ?>"<?= $tabla ? ' aria-current="true"' : '' ?>>Tabla</a>
        </span>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/disco">limpiar filtros ×</a>
<?php endif; ?>
    </div>

    <details class="adv"<?= $val('nombre') !== '' ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form class="adv-form" action="/disco" method="GET">
            <div class="adv-grid">
                <div class="field">
                    <label class="field-label" for="nombre">Nombre del disco</label>
                    <input id="nombre" class="input" type="text" name="nombre" value="<?= $val('nombre') ?>" placeholder="Fons Vitae…">
                </div>
            </div>
            <div class="adv-actions">
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
<?php if ($hayFiltro): ?>
                <a href="/disco">limpiar</a>
<?php endif; ?>
            </div>
        </form>
    </details>

    <details class="facet-rail-toggle">
        <summary class="rail-title">Refinar por</summary>
        <div class="facet-groups">
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
        </div>
    </details>

    <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado discos con esos criterios.</p>
<?php elseif (!$tabla): ?>
            <ul class="fundas">
<?php foreach ($result['data'] as $d):
    $anioD = (int) ($d['FECHA_CD'] ?? 0) > 1800 ? (int) $d['FECHA_CD'] : null;
    $bandaD = !empty($d['ID_BANDA']) ? (string) $d['BANDA'] : ''; ?>
                <li class="funda-item">
                    <?= H::tesela((int) $d['ID_DISCO'], (string) $d['NOMBRE_CD'], $anioD, $bandaD) ?>
                    <a class="funda-tit" href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a>
<?php if ($bandaD !== ''): ?>
                    <a class="funda-sub" href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], $bandaD)) ?>"><?= V::e($bandaD) ?></a>
<?php endif; ?>
<?php if ($anioD): ?>
                    <span class="funda-sub"><?= $anioD ?></span>
<?php endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
            <?= H::pagination($page, $total, $limit, '/disco', $criteria) ?>
<?php else: ?>
            <div class="scrollx tableList">
            <table class="t reg">
                <thead><tr>
                    <th aria-sort="<?= $ariaSort('nombre') ?>"><a class="sortcol" href="<?= V::e($conVista($sortHref('nombre'))) ?>">Nombre <span class="ar"><?= $arrow('nombre') ?></span></a></th>
                    <th aria-sort="<?= $ariaSort('banda') ?>"><a class="sortcol" href="<?= V::e($conVista($sortHref('banda'))) ?>">Banda <span class="ar"><?= $arrow('banda') ?></span></a></th>
                    <th aria-sort="<?= $ariaSort('anio') ?>"><a class="sortcol" href="<?= V::e($conVista($sortHref('anio'))) ?>">Año <span class="ar"><?= $arrow('anio') ?></span></a></th>
                    <th class="col-escucha" title="¿Hay dónde escucharlo?">Escuchar</th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $d): ?>
                    <tr>
                        <td class="t-main"><div class="con-mini"><?= \App\Html::tesela((int) $d['ID_DISCO'], (string) $d['NOMBRE_CD'], null) ?><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></div></td>
                        <td data-l="Banda">
<?php if (!empty($d['ID_BANDA'])): ?>
                            <a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA'])) ?>"><?= V::e($d['BANDA']) ?></a>
<?php else: ?>
                            <span class="muted">—</span>
<?php endif; ?>
                        </td>
                        <td class="t-num" data-l=""><?= !empty($d['FECHA_CD']) ? V::e($d['FECHA_CD']) : '—' ?></td>
                        <td class="col-escucha" data-l="">
<?php if (!empty($d['TIENE_STREAMING'])): ?>
                            <span class="ico-escucha" role="img" aria-label="Disponible en streaming" title="Disponible en streaming">
                                <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" focusable="false">
                                    <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.4"/>
                                    <path d="M6.4 5.2 11 8l-4.6 2.8Z" fill="currentColor"/>
                                </svg>
                            </span>
<?php else: ?>
                            <span class="muted" aria-label="Sin enlaces de streaming" title="Sin enlaces de streaming">—</span>
<?php endif; ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/disco', $criteria + ['vista' => 'tabla']) ?>
<?php endif; ?>
    </section>
</div>
