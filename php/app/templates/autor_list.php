<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Mapa;
/** @var array<string,string> $criteria @var array|null $result @var int $page @var int $limit */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

// Los sentinelas heredados de la era MySQL llegan como 0 o "0" (ver banda_list).
$anio = static function ($a): string {
    $n = (int) $a;
    return $n > 0 ? (string) $n : '—';
};
$defuncion = static function ($d): string {
    if ($d === null || (int) $d === 0) return '—';
    return (string) (int) $d;
};
/** "PROVINCIA|N" del subselect → "Sevilla (12)", sin la etiqueta "mayoría de marchas para:". */
$mayoria = static function (?string $topProvinciaN): string {
    if ($topProvinciaN === null || $topProvinciaN === '') return '—';
    [$prov, $n] = explode('|', $topProvinciaN, 2);
    return $prov . ' (' . $n . ')';
};

$hayFiltroAvanzado = array_filter(
    array_intersect_key($criteria, array_flip(['nacDesde', 'nacHasta', 'fallecido', 'minMarchas', 'provincia'])),
    static fn($x) => trim((string) $x) !== ''
) !== [];
$hayFiltro = $val('nombre') !== '' || $hayFiltroAvanzado;
?>
<div class="stack list-page">
<?php /* El nombre ya se busca desde la barra site-search; aquí solo queda
         la búsqueda avanzada. */ ?>
    <form class="buscar-form-adv" action="/autor" method="GET" role="search">
        <details class="adv"<?= $hayFiltroAvanzado ? ' open' : '' ?>>
            <summary>Búsqueda avanzada</summary>
            <div class="adv-grid">
                <div class="field">
                    <label class="field-label" for="nacDesde">Año de nacimiento — desde</label>
                    <input id="nacDesde" class="input" type="text" inputmode="numeric" name="nacDesde"
                           value="<?= $val('nacDesde') ?>" placeholder="1950 o 50" maxlength="4">
                </div>
                <div class="field">
                    <label class="field-label" for="nacHasta">Año de nacimiento — hasta</label>
                    <input id="nacHasta" class="input" type="text" inputmode="numeric" name="nacHasta"
                           value="<?= $val('nacHasta') ?>" placeholder="1975 o 75" maxlength="4">
                </div>
                <div class="field">
                    <label class="field-label" for="minMarchas">Más de X marchas compuestas</label>
                    <input id="minMarchas" class="input" type="number" name="minMarchas" min="1"
                           value="<?= $val('minMarchas') ?>" placeholder="10">
                </div>
                <div class="field">
                    <label class="field-label" for="provincia">Provincia con más marchas dedicadas</label>
                    <select id="provincia" class="input" name="provincia">
                        <option value="">— Cualquiera —</option>
<?php foreach (Mapa::provinciasOrdenExplorador() as $p): $sel = ($criteria['provincia'] ?? '') === $p; ?>
                        <option value="<?= V::e($p) ?>"<?= $sel ? ' selected' : '' ?>><?= V::e($p) ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="field field-check">
                    <label class="field-label" for="fallecido">
                        <input id="fallecido" type="checkbox" name="fallecido" value="1"<?= $val('fallecido') !== '' ? ' checked' : '' ?>>
                        Solo compositores fallecidos
                    </label>
                </div>
            </div>
            <div class="adv-actions">
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
<?php if ($hayFiltro): ?>
                <a href="/autor">limpiar</a>
<?php endif; ?>
            </div>
        </details>
    </form>

<?php if ($result !== null): $total = (int) $result['totalRows'];
    $hayDefuncion = array_reduce($result['data'] ?? [], static fn(bool $c, array $a): bool => $c || ($a['F_DEF'] !== null && (int) $a['F_DEF'] !== 0), false); ?>
    <section>
<?php if ($total === 0): ?>
        <p class="bio-empty">No se han encontrado compositores con esos criterios.</p>
<?php else: ?>
        <div class="toolbar">
            <h1 class="rescount">Compositores — <b><?= $num($total) ?></b> registros</h1>
            <?= H::porPagina($limit, '/autor', $criteria) ?>
<?php if ($hayFiltro): ?>
            <a class="clearall" href="/autor">limpiar filtros ×</a>
<?php endif; ?>
        </div>
<?php endif; ?>
<?php if ($total > 0): ?>
        <div class="scrollx tableList">
            <table class="table table-zebra table-sm">
                <thead><tr>
                    <th>Nombre</th><th class="nums">Nacimiento</th><?php if ($hayDefuncion): ?><th>Defunción</th><?php endif; ?><th class="nums">Marchas</th><th>Mayoría de marchas</th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $a): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], (string) $a['NOMBRE_COMPLETO'])) ?>"><?= V::e($a['NOMBRE_COMPLETO']) ?></a></td>
                        <td class="nums"><?= $anio($a['F_NAC']) ?></td>
<?php if ($hayDefuncion): ?>
                        <td><?= $defuncion($a['F_DEF']) ?></td>
<?php endif; ?>
                        <td class="nums"><?= V::e($a['MARCHAS']) ?></td>
                        <td><?= V::e($mayoria($a['TOP_PROVINCIA_N'])) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= H::pagination($page, $result['totalRows'], $limit, '/autor', $criteria) ?>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
