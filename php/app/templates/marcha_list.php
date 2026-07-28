<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array $result @var int $page @var int $limit
 *  @var array{tipo:list<array>,provincia:list<array>,decada:list<array>} $facets */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** URL del explorador con los criterios actuales más/menos los cambios dados. */
$href = static function (array $over) use ($criteria): string {
    $q = array_filter(array_merge($criteria, $over), static fn($x) => $x !== '' && $x !== null);
    unset($q['page']);
    return '/marcha' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$orden = (string) ($criteria['orden'] ?? '');
$total = (int) $result['totalRows'];
$hayFiltro = array_filter($criteria, static fn($x) => trim((string) $x) !== '') !== [];
?>
<div class="stack list-page">
    <div class="toolbar">
        <span class="rescount">Marchas — <b><?= $num($total) ?></b> registros</span>
        <span class="sortby">orden:
            <a href="<?= V::e($href(['orden' => ''])) ?>"<?= $orden === '' ? ' class="on"' : '' ?>>título</a> ·
            <a href="<?= V::e($href(['orden' => 'fecha'])) ?>"<?= $orden === 'fecha' ? ' class="on"' : '' ?>>año</a> ·
            <a href="<?= V::e($href(['orden' => 'grabaciones'])) ?>"<?= $orden === 'grabaciones' ? ' class="on"' : '' ?>>grabaciones</a>
        </span>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/marcha">limpiar filtros ×</a>
<?php endif; ?>
    </div>

    <details class="panel adv"<?= ($val('dedicatoria') !== '' || $val('localidad') !== '') ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form action="/marcha" method="GET">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="titulo">Título</label>
                    <input id="titulo" class="input" type="text" name="titulo" value="<?= $val('titulo') ?>" placeholder="Consuelo Gitano…">
                </div>
                <div class="field">
                    <label class="field-label">Fecha</label>
                    <div class="row">
                        <input class="input" type="text" name="fechaDesde" value="<?= $val('fechaDesde') ?>" maxlength="4" placeholder="Desde">
                        <input class="input" type="text" name="fechaHasta" value="<?= $val('fechaHasta') ?>" maxlength="4" placeholder="Hasta">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="dedicatoria">Dedicatoria</label>
                    <input id="dedicatoria" class="input" type="text" name="dedicatoria" value="<?= $val('dedicatoria') ?>" placeholder="Hdad Cristo de la Corona…">
                </div>
                <div class="field">
                    <label class="field-label" for="localidad">Localidad</label>
                    <input id="localidad" class="input" type="text" name="localidad" value="<?= $val('localidad') ?>" placeholder="Osuna…">
                </div>
                <div class="field">
                    <label class="field-label" for="provincia">Provincia</label>
                    <input id="provincia" class="input" type="text" name="provincia" value="<?= $val('provincia') ?>" placeholder="Almería…">
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
<?php if ($facets['tipo'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Tipo</div>
<?php foreach ($facets['tipo'] as $f): $on = ($criteria['tipo'] ?? '') === $f['K']; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['tipo' => $on ? '' : $f['K']])) ?>"><?= V::e(ucfirst(mb_strtolower((string) $f['K']))) ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
<?php if ($facets['estilo'] !== []):
    $estiloLabel = static fn(string $k): string => match ($k) {
        'CCTT' => 'Cornetas y Tambores',
        'AM' => 'Agrupación Musical',
        default => $k,
    }; ?>
            <div class="fgroup">
                <div class="ftitle">Estilo</div>
<?php foreach ($facets['estilo'] as $f): $on = ($criteria['estilo'] ?? '') === $f['K']; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['estilo' => $on ? '' : $f['K']])) ?>"><?= V::e($estiloLabel((string) $f['K'])) ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
<?php if ($facets['provincia'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Provincia</div>
<?php foreach ($facets['provincia'] as $f): $on = ($criteria['provincia'] ?? '') === $f['K']; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['provincia' => $on ? '' : $f['K']])) ?>"><?= V::e($f['K']) ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
<?php if ($facets['decada'] !== []): ?>
            <div class="fgroup">
                <div class="ftitle">Década</div>
<?php foreach ($facets['decada'] as $f):
    $d0 = (string) $f['K']; $d9 = (string) ((int) $f['K'] + 9);
    $on = ($criteria['fechaDesde'] ?? '') === $d0 && ($criteria['fechaHasta'] ?? '') === $d9; ?>
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['fechaDesde' => $on ? '' : $d0, 'fechaHasta' => $on ? '' : $d9])) ?>"><?= V::e($d0) ?>s<?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </aside>

        <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado marchas con esos criterios.</p>
<?php else: ?>
            <div class="scrollx tableList">
            <table class="reg">
                <thead><tr>
                    <th>Marcha</th>
                    <th>Año</th>
                    <th>Compositor</th>
                    <th>Prov.</th>
                    <th class="num">Grab.</th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $m): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                        <td><?= !empty($m['FECHA']) ? V::e($m['FECHA']) : '—' ?></td>
                        <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                            <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                        </td>
                        <td><?= !empty($m['PROVINCIA']) ? V::e($m['PROVINCIA']) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= (int) $m['N_GRAB'] ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/marcha', $criteria) ?>
<?php endif; ?>
        </section>
    </div>
</div>
