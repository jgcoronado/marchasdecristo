<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array $result @var int $page @var int $limit
 *  @var array{tipo:list<array>,estilo:list<array>,provincia:list<array>,decada:list<array>} $facets */
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
$hayFiltro = array_filter(array_diff_key($criteria, ['orden' => 1]), static fn($x) => trim((string) $x) !== '') !== [];

// El panel se abre solo si hay algún criterio que las facetas de la izquierda
// no sepan mostrar (título, dedicatoria, localidad): si el filtro activo ya se
// ve en la barra lateral, abrirlo sería repetir la misma información.
$advAbierto = $val('titulo') !== '' || $val('dedicatoria') !== '' || $val('localidad') !== '';
?>
<?php /* Orden y por página: a la derecha del título en escritorio; en móvil
         se pliegan dentro de «Filtros» para que la primera fila de resultados
         entre en el primer pantallazo (390×844). Se pintan dos veces y la hoja
         r/tablas.css enseña una u otra: sin JS y sin mover nodos. */
$controles = '<span class="sortby seg"><span class="lbl">orden</span><a href="' . V::e($href(['orden' => ''])) . '"' . ($orden === '' ? ' class="on"' : '') . '>título</a><a href="' . V::e($href(['orden' => 'fecha'])) . '"' . ($orden === 'fecha' ? ' class="on"' : '') . '>año</a><a href="' . V::e($href(['orden' => 'grabaciones'])) . '"' . ($orden === 'grabaciones' ? ' class="on"' : '') . '>grabaciones</a></span>'
    . H::porPagina($limit, '/marcha', $criteria);
$nFiltros = count(array_filter(array_diff_key($criteria, ['orden' => 1, 'limit' => 1, 'page' => 1]), static fn($x) => trim((string) $x) !== ''));
?>
<div class="stack list-page">
    <div class="toolbar">
        <h1 class="rescount"><b><?= $num($total) ?></b> marchas</h1>
        <div class="t-ctl"><?= $controles ?></div>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/marcha">limpiar filtros ×</a>
<?php endif; ?>
    </div>

<?php /* «Búsqueda avanzada» y «Refinar por» bajo un solo botón (§2.4): antes
         eran dos desplegables seguidos que empujaban la tabla hacia abajo. */ ?>
    <details class="filtros"<?= $advAbierto ? ' open' : '' ?>>
        <summary>Filtros<?php if ($nFiltros > 0): ?> <span class="n">(<?= $nFiltros ?>)</span><?php endif; ?></summary>
        <div class="filtros-panel">
        <div class="t-ctl t-ctl-movil"><?= $controles ?></div>
        <form class="adv-form" aria-label="Búsqueda avanzada" action="/marcha" method="GET">
            <div class="adv-grid">
                <div class="field">
                    <label class="field-label" for="titulo">Título</label>
                    <input id="titulo" class="input" type="text" name="titulo" value="<?= $val('titulo') ?>" placeholder="Consuelo Gitano…">
                </div>
                <div class="field">
                    <label class="field-label" for="dedicatoria">Dedicatoria</label>
                    <input id="dedicatoria" class="input" type="text" name="dedicatoria" value="<?= $val('dedicatoria') ?>" placeholder="Hdad Cristo de la Corona…">
                </div>
                <div class="field">
                    <label class="field-label" for="fechaDesde">Años</label>
                    <div class="adv-rango">
                        <input id="fechaDesde" class="input" type="text" inputmode="numeric" name="fechaDesde" value="<?= $val('fechaDesde') ?>" maxlength="4" placeholder="desde" aria-label="Año desde">
                        <span aria-hidden="true">–</span>
                        <input class="input" type="text" inputmode="numeric" name="fechaHasta" value="<?= $val('fechaHasta') ?>" maxlength="4" placeholder="hasta" aria-label="Año hasta">
                    </div>
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
            <div class="adv-actions">
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
<?php if ($hayFiltro): ?>
                <a href="/marcha">limpiar</a>
<?php endif; ?>
            </div>
        </form>

        <div class="facet-groups" aria-label="Refinar por">
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
                <a class="fopt<?= $on ? ' on' : '' ?>" href="<?= V::e($href(['fechaDesde' => $on ? '' : $d0, 'fechaHasta' => $on ? '' : $d9])) ?>"><?= V::e($d0) ?>–<?= (int) $d0 + 9 ?><?php if ($on): ?> <span class="x">×</span><?php endif; ?><span class="fcount"><?= $num($f['N']) ?></span></a>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </div>
        </div>
    </details>

    <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado marchas con esos criterios.</p>
<?php else: ?>
            <div class="tableList">
            <table class="t">
                <thead><tr>
                    <th>Marcha</th>
                    <th>Compositor</th>
                    <th class="t-num">Año</th>
                    <th>Estilo</th>
                    <th>Dedicatoria</th>
                    <th>Localidad</th>
                    <th title="¿Tiene enlace de redes sociales?">Redes</th>
                    <th class="t-num" title="Número de discos en que está grabada">Discos</th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $m): $nGrab = (int) $m['N_GRAB']; ?>
                    <tr>
                        <td class="t-main" data-l=""><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                        <td data-l=""><?php foreach ($m['AUTOR'] as $a): ?><div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div><?php endforeach; ?></td>
                        <td class="t-num" data-l=""><?= !empty($m['FECHA']) && $m['FECHA'] !== 's/f' ? V::e($m['FECHA']) : '<span class="t-vacio">—</span>' ?></td>
                        <td data-l=""><?= \App\Html::chipEstilo($m['ESTILO'] ?? null, false) ?: '<span class="t-vacio">—</span>' ?></td>
                        <td class="t-opt" data-l=""><?= !empty($m['DEDICATORIA']) ? V::e($m['DEDICATORIA']) : '<span class="t-vacio">—</span>' ?></td>
                        <td class="t-opt" data-l=""><?= !empty($m['LOCALIDAD']) ? V::e($m['LOCALIDAD']) : '<span class="t-vacio">—</span>' ?></td>
                        <td class="t-opt" data-l=""><?= !empty($m['TIENE_RRSS']) ? '<span title="Tiene enlace de redes sociales">Sí</span>' : '<span class="t-vacio" title="Sin enlace de RRSS">—</span>' ?></td>
                        <td class="t-num" data-l="Discos"><?= $nGrab > 0 ? $nGrab : '<span class="t-vacio" title="No grabada en ningún disco">—</span>' ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/marcha', $criteria) ?>
<?php endif; ?>
    </section>
</div>
