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
$hayFiltro = array_filter($criteria, static fn($x) => trim((string) $x) !== '') !== [];

// El panel se abre solo si hay algún criterio que las facetas de la izquierda
// no sepan mostrar (título, dedicatoria, localidad): si el filtro activo ya se
// ve en la barra lateral, abrirlo sería repetir la misma información.
$advAbierto = $val('titulo') !== '' || $val('dedicatoria') !== '' || $val('localidad') !== '';
?>
<div class="stack list-page">
    <div class="toolbar">
        <h1 class="rescount">Marchas — <b><?= $num($total) ?></b> registros</h1>
        <?php /* Control segmentado: la etiqueta es una celda más (.lbl) y los
                 separadores "·" desaparecen — el filete entre celdas ya separa. */ ?>
        <span class="sortby"><span class="lbl">orden</span><a href="<?= V::e($href(['orden' => ''])) ?>"<?= $orden === '' ? ' class="on"' : '' ?>>título</a><a href="<?= V::e($href(['orden' => 'fecha'])) ?>"<?= $orden === 'fecha' ? ' class="on"' : '' ?>>año</a><a href="<?= V::e($href(['orden' => 'grabaciones'])) ?>"<?= $orden === 'grabaciones' ? ' class="on"' : '' ?>>grabaciones</a></span>
        <?= H::porPagina($limit, '/marcha', $criteria) ?>
<?php if ($hayFiltro): ?>
        <a class="clearall" href="/marcha">limpiar filtros ×</a>
<?php endif; ?>
    </div>

<?php /* Sin tarjeta ni recuadro: es un desplegable en línea, separado por un
         filete, igual que el resto de secciones. Antes era un .panel que, aun
         cerrado, ocupaba un bloque en blanco entre la barra y los resultados. */ ?>
    <details class="adv"<?= $advAbierto ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form class="adv-form" action="/marcha" method="GET">
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
    </details>

    <details class="facet-rail-toggle">
        <summary class="rail-title">Refinar por</summary>
        <div class="facet-groups">
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
        </div>
    </details>

    <section>
<?php if ($total === 0): ?>
            <p class="bio-empty">No se han encontrado marchas con esos criterios.</p>
<?php else: ?>
            <div class="scrollx tableList">
            <table class="reg">
                <colgroup>
                    <col style="width:30%">
                    <col style="width:20%">
                    <col style="width:5%">
                    <col style="width:20%">
                    <col style="width:15%">
                    <col style="width:5%">
                    <col style="width:5%">
                </colgroup>
                <thead><tr>
                    <th>Marcha</th>
                    <th>Compositor</th>
                    <th>Año</th>
                    <th>Dedicatoria</th>
                    <th>Localidad</th>
                    <th class="ico-col" title="¿Tiene enlace de RRSS?">RRSS</th>
                    <th class="ico-col" title="¿Grabada en algún disco?">Grabada</th>
                </tr></thead>
                <tbody>
<?php foreach ($result['data'] as $m): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                        <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                            <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                        </td>
                        <td><?= !empty($m['FECHA']) ? V::e($m['FECHA']) : '—' ?></td>
                        <td><?= !empty($m['DEDICATORIA']) ? V::e($m['DEDICATORIA']) : '<span class="muted">—</span>' ?></td>
                        <td><?= !empty($m['LOCALIDAD']) ? V::e($m['LOCALIDAD']) : '<span class="muted">—</span>' ?></td>
                        <td class="ico-col">
<?php if (!empty($m['TIENE_RRSS'])): ?>
                            <span role="img" aria-label="Tiene enlace de RRSS" title="Tiene enlace de RRSS">🔗</span>
<?php else: ?>
                            <span class="muted" aria-label="Sin enlace de RRSS" title="Sin enlace de RRSS">—</span>
<?php endif; ?>
                        </td>
                        <td class="ico-col">
<?php if ((int) $m['N_GRAB'] > 0): ?>
                            <span role="img" aria-label="Grabada en <?= (int) $m['N_GRAB'] ?> disco(s)" title="Grabada en <?= (int) $m['N_GRAB'] ?> disco(s)">💿</span>
<?php else: ?>
                            <span class="muted" aria-label="No grabada en ningún disco" title="No grabada en ningún disco">—</span>
<?php endif; ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?= H::pagination($page, $total, $limit, '/marcha', $criteria) ?>
<?php endif; ?>
    </section>
</div>
