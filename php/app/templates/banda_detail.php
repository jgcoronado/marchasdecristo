<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,mixed> $b @var string|null $url @var array<string,string> $enlaces */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** "1978–1986", "1996–hoy", "s/f–2000"… */
$yrs = static function ($fund, $ext): string {
    $f = (int) (float) ($fund ?? 0);
    $e = (int) (float) ($ext ?? 0);
    return ($f > 1800 ? $f : 's/f') . '–' . ($e > 1800 ? $e : 'hoy');
};

$bid = (int) $b['ID_BANDA'];
$fund = (int) (float) ($b['FECHA_FUND'] ?? 0);
$ext = (int) (float) ($b['FECHA_EXT'] ?? 0);
$estrenos = $b['ESTRENOS_MAP'] ?? [];
$nEst = (int) ($estrenos[$bid] ?? $b['marchasLength']);

// Asiento: "Nombre completo. — Localidad, fundada en 1911. — En activo."
$asiento = [];
if ($t($b['NOMBRE_COMPLETO']) && $b['NOMBRE_COMPLETO'] !== $b['NOMBRE_BREVE']) $asiento[] = V::e($b['NOMBRE_COMPLETO']);
$loc = trim(implode(', ', array_filter([
    $t($b['LOCALIDAD']) ? (string) $b['LOCALIDAD'] : '',
    $fund > 1800 ? 'fundada en ' . $fund : '',
], static fn($v) => $v !== '')));
if ($loc !== '') $asiento[] = V::e($loc);
$asiento[] = $ext > 1800 ? 'Desaparecida en ' . $ext : 'En activo';

// Línea de sucesión (§09-B del dossier): predecesoras (de la más antigua) →
// foco → sucesoras; madres/juveniles cuelgan con ramal punteado. Sin linaje,
// la propia banda es el único nodo (absorbe la antigua timeline).
$lin = $b['linaje'] ?? null;
$filas = [];  // [clases, id|null(foco), nombre, años, estrenos|null]
if ($lin !== null) {
    foreach ($lin['madres'] as $m2) {
        $filas[] = ['normal', (int) $m2['ID_BANDA'], (string) $m2['NOMBRE_BREVE'], $yrs($m2['FECHA_INICIO'] ?? null, $m2['FECHA_FIN'] ?? null), $estrenos[(int) $m2['ID_BANDA']] ?? null];
    }
    for ($i = count($lin['up']) - 1; $i >= 0; $i--) {
        foreach ($lin['up'][$i] as $n) {
            $filas[] = ['normal', (int) $n['ID'], (string) $n['NOMBRE'], $yrs($n['FUND'], $n['EXT']), $estrenos[(int) $n['ID']] ?? null];
        }
    }
}
$esMadre = $lin !== null && $lin['madres'] !== [];
$filas[] = [$esMadre ? 'focus juv' : 'focus', null, (string) $b['NOMBRE_BREVE'], $yrs($b['FECHA_FUND'], $b['FECHA_EXT']), $nEst];
if ($lin !== null) {
    foreach ($lin['down'] as $lvl) {
        foreach ($lvl as $n) {
            $filas[] = ['normal', (int) $n['ID'], (string) $n['NOMBRE'], $yrs($n['FUND'], $n['EXT']), $estrenos[(int) $n['ID']] ?? null];
        }
    }
    foreach ($lin['juveniles'] as $j) {
        $filas[] = ['juv', (int) $j['ID_BANDA'], $j['NOMBRE_BREVE'] . ' (juvenil)', $yrs($j['FECHA_INICIO'] ?? null, $j['FECHA_FIN'] ?? null), $estrenos[(int) $j['ID_BANDA']] ?? null];
    }
}
// Cierre del carril si la última fila es de la cadena principal.
$last = count($filas) - 1;
if (!str_contains($filas[$last][0], 'juv')) $filas[$last][0] .= ' last';

$nJuv = count(array_filter($filas, static fn(array $f): bool => $f[0] === 'juv'));
$nSuc = count($filas) - $nJuv;
$nDiscos = (int) $b['discosLength'];
$nMarchas = (int) $b['marchasLength'];
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › <a href="/banda">Bandas</a><?php if ($t($b['LOCALIDAD'])): ?> › <a href="<?= V::e('/banda?localidad=' . rawurlencode((string) $b['LOCALIDAD'])) ?>"><?= V::e($b['LOCALIDAD']) ?></a><?php endif; ?> › B-<?= $bid ?></span>
    <span class="regnav">registro <?= $num($b['REG_POS']) ?> de <?= $num($b['REG_TOTAL']) ?></span>
</div>

<article class="record">
    <div class="head">
        <span class="eb">Banda</span>
        <span class="sig">MDC · B-<?= $bid ?></span>
    </div>
    <h1><?= V::e($b['NOMBRE_BREVE']) ?></h1>
    <p class="asiento"><?= implode('. — ', $asiento) ?>.</p>

    <nav class="rectabs" aria-label="Secciones de la ficha">
        <a href="#datos">Datos</a>
        <a href="#formaciones">Formaciones</a>
<?php if ($nDiscos > 0): ?>
        <a href="#discografia">Discografía (<?= $num($nDiscos) ?>)</a>
<?php endif; ?>
        <a href="#estrenos">Estrenos (<?= $num($nMarchas) ?>)</a>
    </nav>

    <dl class="desc" id="datos">
<?php if ($t($b['NOMBRE_COMPLETO'])): ?>
        <div class="f"><dt>Nombre completo</dt><dd><?= V::e($b['NOMBRE_COMPLETO']) ?></dd></div>
<?php endif; ?>
<?php if ($t($b['LOCALIDAD'])): ?>
        <div class="f"><dt>Localidad</dt><dd><?= V::e($b['LOCALIDAD']) ?><?php if ($t($b['PROVINCIA'])): ?> (<?= V::e($b['PROVINCIA']) ?>)<?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($fund > 1800): ?>
        <div class="f"><dt>Fundación</dt><dd><?= $fund ?></dd></div>
<?php endif; ?>
<?php if ($ext > 1800): ?>
        <div class="f"><dt>Extinción</dt><dd><?= $ext ?></dd></div>
<?php endif; ?>
<?php if ($t($b['DIR_MUS_ACTUAL'])): ?>
        <div class="f"><dt>Dir. musical</dt><dd><?= V::e($b['DIR_MUS_ACTUAL']) ?></dd></div>
<?php endif; ?>
<?php if ($t($b['DIRECTOR_ACTUAL'])): ?>
        <div class="f"><dt>Director</dt><dd><?= V::e($b['DIRECTOR_ACTUAL']) ?></dd></div>
<?php endif; ?>
        <div class="f"><dt>Estrenos</dt><dd><?= $num($nMarchas) ?></dd></div>
<?php if ($nDiscos > 0): ?>
        <div class="f"><dt>Discos propios</dt><dd><?= $num($nDiscos) ?></dd></div>
<?php endif; ?>
<?php if ($t($b['WEB'])): ?>
        <div class="f"><dt>Web</dt><dd><a href="<?= V::e($b['WEB']) ?>" rel="noopener" target="_blank"><?= V::e(preg_replace('#^https?://#', '', (string) $b['WEB'])) ?> ↗</a></dd></div>
<?php endif; ?>
    </dl>
    <?= H::streaming($enlaces ?? []) ?>

    <div class="shead" id="formaciones">
        <h2>Formaciones</h2>
        <span class="n"><?= $nSuc === 1 ? '1 formación' : $nSuc . ' sucesivas' ?><?php if ($nJuv > 0): ?> · <?= $nJuv ?> juvenil<?= $nJuv > 1 ? 'es' : '' ?><?php endif; ?></span>
    </div>
    <ul class="lnB">
<?php foreach ($filas as [$cls, $fid, $nombre, $anios, $nEstF]): ?>
        <li class="<?= $cls ?>">
<?php if ($fid !== null): ?>
            <a class="nm" href="<?= V::e(S::buildDetailPath('banda', $fid, $nombre)) ?>"><?= V::e($nombre) ?></a>
<?php else: ?>
            <span class="nm"><?= V::e($nombre) ?></span>
<?php endif; ?>
<?php if ($nEstF !== null && (int) $nEstF > 0): ?>
            <span class="cnt2">(<?= $num($nEstF) ?> estreno<?= (int) $nEstF > 1 ? 's' : '' ?>)</span>
<?php endif; ?>
            <span class="lead"></span>
            <span class="yr"><?= V::e($anios) ?></span>
        </li>
<?php endforeach; ?>
    </ul>

<?php if ($nDiscos > 0): ?>
    <div class="shead" id="discografia">
        <h2>Discografía propia</h2>
        <span class="n"><?= $num($nDiscos) ?> discos · orden cronológico</span>
    </div>
    <div class="scrollx">
    <table class="reg" data-sortable>
        <thead><tr>
            <th data-type="num">Año <span class="ar">↕</span></th>
            <th>Disco <span class="ar">↕</span></th>
            <th class="num" data-type="num">Pistas <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($b['discos'] as $d): $anio = (int) (float) ($d['FECHA_CD'] ?? 0); ?>
            <tr>
                <td><?= $anio > 1800 ? $anio : '—' ?></td>
                <td><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></td>
                <td class="num"><?= (int) $d['PISTAS'] ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

    <div class="shead" id="estrenos">
        <h2>Marchas estrenadas</h2>
<?php if ($nMarchas > 0): ?>
        <span class="n" id="est-count"><?= $num($nMarchas) ?> · más recientes primero</span>
<?php if ($nMarchas >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar…" aria-label="Filtrar marchas estrenadas" data-filter="est-table" data-count="est-count" data-total="<?= $nMarchas ?>">
<?php endif; ?>
<?php endif; ?>
    </div>
<?php if ($nMarchas === 0): ?>
    <p class="bio-empty">Sin estrenos documentados.</p>
<?php else: ?>
    <div class="scrollx">
    <table class="reg" id="est-table" data-sortable>
        <thead><tr>
            <th>Marcha <span class="ar">↕</span></th>
            <th data-type="num">Año <span class="ar">↕</span></th>
            <th>Compositor <span class="ar">↕</span></th>
            <th class="num" data-type="num">Grab. <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($b['marchas'] as $m): ?>
            <tr>
                <td><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                <td><?= $t($m['FECHA']) ? (int) (float) $m['FECHA'] : '—' ?></td>
                <td>
<?php foreach ($m['AUTOR'] as $a): ?>
                    <div><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a></div>
<?php endforeach; ?>
                </td>
                <td class="num"><?= (int) $m['N_GRAB'] ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

    <div class="ids">
<?php if (!empty($url)): ?>
        <span>permalink: <?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></span>
<?php endif; ?>
        <span>registro B-<?= $bid ?></span>
    </div>
</article>
