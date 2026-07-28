<?php use App\View as V; use App\Slug as S;
/** @var list<array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,SLUG_KEY:string,N:int}> $items
 *  @var array{localidad:string,provincia:string} $criteria */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
$hayFiltro = trim($criteria['localidad']) !== '' || trim($criteria['provincia']) !== '';

// Agrupar por letra inicial (derivada de SLUG_KEY: ya sin artículo ni prefijo).
$grupos = [];
$totalMarchas = 0;
foreach ($items as $it) {
    $ch = strtoupper((string) substr($it['SLUG_KEY'], 0, 1));
    $letra = ($ch >= 'A' && $ch <= 'Z') ? $ch : '#';
    $grupos[$letra][] = $it;
    $totalMarchas += (int) $it['N'];
}
ksort($grupos);
$letras = array_keys($grupos);
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › Dedicatorias</span>
    <span class="regnav"><?= $num(count($items)) ?> advocaciones</span>
</div>

<article class="record">
    <h1>Dedicatorias</h1>
    <p class="asiento">Advocaciones, hermandades, cofradías y agrupaciones a las que están dedicadas las marchas del catálogo.
        <?= $num(count($items)) ?> advocaciones · <?= $num($totalMarchas) ?> marchas dedicadas.</p>

    <details class="panel adv"<?= $hayFiltro ? ' open' : '' ?>>
        <summary>Búsqueda avanzada</summary>
        <form action="/dedicatorias" method="GET">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="localidad">Localidad</label>
                    <input id="localidad" class="input" type="text" name="localidad" value="<?= $val('localidad') ?>" placeholder="Osuna…">
                </div>
                <div class="field">
                    <label class="field-label" for="provincia">Provincia</label>
                    <input id="provincia" class="input" type="text" name="provincia" value="<?= $val('provincia') ?>" placeholder="Sevilla…">
                </div>
            </div>
            <div class="search-actions">
                <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
<?php if ($hayFiltro): ?>
                <a class="btn btn-sm btn-ghost" href="/dedicatorias">limpiar filtros ×</a>
<?php endif; ?>
            </div>
        </form>
    </details>

<?php if ($items === [] && !$hayFiltro): ?>
    <p class="bio-empty">Todavía no hay dedicatorias normalizadas. Ejecuta <span class="mono">seed_dedicatorias.php</span>.</p>
<?php elseif ($items === []): ?>
    <p class="bio-empty">No hay advocaciones con esos criterios.</p>
<?php else: ?>
    <nav class="aznav" aria-label="Saltar a letra">
<?php foreach ($letras as $L): ?>
        <a href="#letra-<?= V::e($L) ?>"><?= V::e($L) ?></a>
<?php endforeach; ?>
    </nav>

<?php foreach ($grupos as $L => $lista): ?>
    <div class="shead" id="letra-<?= V::e($L) ?>">
        <h2><?= V::e($L) ?></h2>
        <span class="n"><?= $num(count($lista)) ?> advocaciones</span>
    </div>
    <ul class="azgrid">
<?php foreach ($lista as $it):
        $loc = trim((string) $it['LOCALIDAD']);
        $label = $it['NOMBRE'] . ($loc !== '' ? ' ' . $loc : ''); ?>
        <li class="azitem">
            <a href="<?= V::e(S::buildDetailPath('dedicatoria', $it['ID_DEDIC'], $label)) ?>">
                <span class="aznom"><?= V::e($it['NOMBRE']) ?></span>
<?php if ($loc !== ''): ?>                <span class="azloc"><?= V::e($loc) ?></span><?php endif; ?>
            </a>
            <span class="azcount"><?= $num($it['N']) ?></span>
        </li>
<?php endforeach; ?>
    </ul>
<?php endforeach; ?>
<?php endif; ?>
</article>
