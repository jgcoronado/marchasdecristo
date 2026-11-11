<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,mixed> $b @var string|null $url @var array<string,string> $enlaces
 *  @var array<int,list<array<string,mixed>>> $acomp Repo::acompanamientosDeBanda(), año → filas */
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** "1978–1986", "1996–hoy", "s/f–2000"… */
$yrs = static function ($fund, $ext): string {
    $f = (int) ($fund ?? 0);
    $e = (int) ($ext ?? 0);
    return ($f > 1800 ? $f : 's/f') . '–' . ($e > 1800 ? $e : 'hoy');
};

/** Años de otra formación del linaje: sus etapas si se refundó, si no fundación–extinción. */
$etapasMap = $b['ETAPAS_MAP'] ?? [];
$yrsDe = static fn(array $n): string => isset($etapasMap[(int) $n['ID']])
    ? implode(' · ', array_map(static fn(array $e): string => $yrs($e['ANIO_INICIO'], $e['ANIO_FIN']), $etapasMap[(int) $n['ID']]))
    : $yrs($n['FUND'], $n['EXT']);

$bid = (int) $b['ID_BANDA'];
$fund = (int) ($b['FECHA_FUND'] ?? 0);
$ext = (int) ($b['FECHA_EXT'] ?? 0);
$estrenos = $b['ESTRENOS_MAP'] ?? [];
$nEst = (int) ($estrenos[$bid] ?? $b['marchasLength']);

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
            $filas[] = ['normal', (int) $n['ID'], (string) $n['NOMBRE'], $yrsDe($n), $estrenos[(int) $n['ID']] ?? null];
        }
    }
}
$esMadre = $lin !== null && $lin['madres'] !== [];
// Sucesoras (fusión, renombrado…) en orden, con su año de inicio para
// colocarlas tras la etapa de la que salen. Sin fundación conocida, salen de
// la primera etapa (el año 0 las pone antes de cualquier refundación).
$sucesoras = [];
if ($lin !== null) {
    foreach ($lin['down'] as $lvl) {
        foreach ($lvl as $n) {
            $sucesoras[] = [(int) ($n['FUND'] ?? 0), ['normal', (int) $n['ID'], (string) $n['NOMBRE'], $yrsDe($n), $estrenos[(int) $n['ID']] ?? null]];
        }
    }
}
// Varias etapas (banda_etapa): una fila de foco por etapa. Antes de cada
// etapa posterior van las sucesoras que empezaron antes que ella (BCT Caído y
// Fuensanta, 2008, sale de la etapa 1992–2008, no de la refundación de 2022).
// Si nada se interpone, el hueco entre etapas es una fila "sin actividad"; si
// hay sucesoras en medio, la etapa se marca "(refundada)" para que no parezca
// que viene de ellas. Los estrenos van una sola vez, en la primera.
$etapas = $b['etapas'] ?? [];
if (count($etapas) > 1) {
    foreach ($etapas as $i => $e) {
        $previas = 0;
        while ($i > 0 && $sucesoras !== [] && $sucesoras[0][0] < $e['ANIO_INICIO']) {
            $filas[] = array_shift($sucesoras)[1];
            $previas++;
        }
        if ($i > 0 && $previas === 0) {
            $filas[] = ['gap', null, 'sin actividad', $etapas[$i - 1]['ANIO_FIN'] . '–' . $e['ANIO_INICIO'], null];
        }
        $nombre = (string) $b['NOMBRE_BREVE'] . ($i > 0 && $previas > 0 ? ' (refundada)' : '');
        $filas[] = [($esMadre ? 'focus juv' : 'focus') . ($i > 0 ? ' etapa' : ''), null, $nombre, $yrs($e['ANIO_INICIO'], $e['ANIO_FIN']), $i === 0 ? $nEst : null];
    }
} else {
    $filas[] = [$esMadre ? 'focus juv' : 'focus', null, (string) $b['NOMBRE_BREVE'], $yrs($b['FECHA_FUND'], $b['FECHA_EXT']), $nEst];
}
foreach ($sucesoras as [, $fila]) $filas[] = $fila;
if ($lin !== null) {
    foreach ($lin['juveniles'] as $j) {
        $filas[] = ['juv', (int) $j['ID_BANDA'], $j['NOMBRE_BREVE'] . ' (juvenil)', $yrs($j['FECHA_INICIO'] ?? null, $j['FECHA_FIN'] ?? null), $estrenos[(int) $j['ID_BANDA']] ?? null];
    }
}
// Cierre del carril si la última fila es de la cadena principal.
$last = count($filas) - 1;
if (!str_contains($filas[$last][0], 'juv')) $filas[$last][0] .= ' last';

$nJuv = count(array_filter($filas, static fn(array $f): bool => $f[0] === 'juv'));
// Las etapas posteriores y los huecos son la misma formación, no cuentan aparte.
$nSuc = count(array_filter($filas, static fn(array $f): bool => $f[0] !== 'juv' && $f[0] !== 'gap' && !str_contains($f[0], 'etapa')));
$nDiscos = (int) $b['discosLength'];
$nMarchas = (int) $b['marchasLength'];
$acomp ??= [];
$nAcomp = array_sum(array_map('count', $acomp));
?>
<?php
$botones = H::streaming($enlaces ?? []);
$varBanda = H::varianteBanda((string) $b['NOMBRE_BREVE']);
$portadas = [];
foreach (array_reverse($b['discos'] ?? []) as $d) {
    $portadas[] = ['id' => (int) $d['ID_DISCO'], 'nombre' => (string) $d['NOMBRE_CD'],
        'anio' => (int) ($d['FECHA_CD'] ?? 0) > 1800 ? (int) $d['FECHA_CD'] : null];
}

/* Frase de cabecera con lo que se sabe: «Banda de cornetas y tambores de
   Sevilla, fundada en 1980.» Sin fundación ni localidad, se queda corta, sin
   rellenos. */
$loc = $t($b['LOCALIDAD']) ? (string) $b['LOCALIDAD'] : '';
$frase = match ($varBanda) { 'am' => 'Agrupación musical', 'cctt' => 'Banda de cornetas y tambores', default => 'Banda' }
    . ($loc !== '' ? ' de ' . V::e($loc) : '')
    . ($loc !== '' && $t($b['PROVINCIA']) && mb_strtolower((string) $b['PROVINCIA']) !== mb_strtolower($loc) ? ' (' . V::e($b['PROVINCIA']) . ')' : '');
if (count($etapas) > 1) {
    $frase .= ', activa en ' . V::e(V::listaY(array_map(static fn(array $e): string => $yrs($e['ANIO_INICIO'], $e['ANIO_FIN']), $etapas)));
} else {
    if ($fund > 1800) $frase .= ', fundada en ' . $fund;
    if ($ext > 1800) $frase .= ($fund > 1800 ? ' y' : ',') . ' extinguida en ' . $ext;
}
$frase .= '.';
if ($nMarchas > 0) $frase .= ' Ha estrenado ' . ($nMarchas === 1 ? 'una marcha' : $num($nMarchas) . ' marchas') . '.';

// Acompañamientos: el último año arriba del cuerpo; los anteriores, al final.
$anioUlt = $acomp !== [] ? (int) array_key_first($acomp) : null;
$tablaAcomp = static function (array $filasAnio): string {
    $h = '<div class="scrollx"><table class="t"><thead><tr><th scope="col">Hermandad</th><th scope="col">Localidad</th><th scope="col">Día</th></tr></thead><tbody>';
    foreach ($filasAnio as $f) {
        $locUrl = '/acompanamientos/' . S::slugify((string) $f['LOCALIDAD']);
        $h .= '<tr><td class="t-main"><a href="' . V::e($locUrl . ($f['EN_NOMINA'] ? '#h-' . $f['HERMANDAD_SLUG'] : '')) . '">' . V::e($f['HERMANDAD']) . '</a></td>'
            . '<td data-l=""><a href="' . V::e($locUrl) . '">' . V::e($f['LOCALIDAD_NOMBRE']) . '</a></td>'
            . '<td data-l="">' . ($f['DIA'] !== null ? V::e($f['DIA']) : '<span class="t-vacio">—</span>') . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
};
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/banda">Bandas</a><?php if ($t($b['LOCALIDAD'])): ?> / <a href="<?= V::e('/banda?localidad=' . rawurlencode((string) $b['LOCALIDAD'])) ?>"><?= V::e($b['LOCALIDAD']) ?></a><?php endif; ?></span>
</div>

<article class="ficha ficha-banda">
    <header class="ficha-cab">
<?php if ($varBanda !== ''): ?>
        <p class="ficha-k"><?= H::chipEstilo($varBanda === 'am' ? 'AM' : 'CCTT') ?></p>
<?php endif; ?>
        <h1><?= V::e($b['NOMBRE_BREVE']) ?></h1>
        <p class="ficha-frase"><?= $frase ?></p>
<?php if ($nMarchas + $nDiscos + $nAcomp >= 12): ?>
        <nav class="ficha-anclas" aria-label="Secciones de la ficha"><a href="#datos">Datos</a><?php if ($nAcomp > 0): ?> · <a href="#acompanamientos">Acompañamientos</a><?php endif; ?> · <a href="#estrenos">Estrenos</a><?php if ($nDiscos > 0): ?> · <a href="#discografia">Discografía</a><?php endif; ?></nav>
<?php endif; ?>
    </header>
<?php if ($portadas !== []): ?>
    <div class="ficha-portadas"><?= H::mosaico($portadas, 8, count($portadas) === 1 ? 'Su disco' : 'Su discografía') ?></div>
<?php endif; ?>

<?php if ($botones !== ''): ?>
    <section class="ficha-escuchar blk">
    <div class="shead"><h2>Escuchar</h2></div>
    <?= $botones ?>
    </section>
<?php endif; ?>

    <section class="ficha-datos blk">
    <div class="shead"><h2>Datos</h2></div>
    <dl class="desc" id="datos">
<?php if ($t($b['NOMBRE_COMPLETO'])): ?>
        <div class="f"><dt>Nombre completo</dt><dd><?= V::e($b['NOMBRE_COMPLETO']) ?></dd></div>
<?php endif; ?>
<?php if ($t($b['DIR_MUS_ACTUAL'])): ?>
        <div class="f"><dt>Dir. musical</dt><dd><?= V::e($b['DIR_MUS_ACTUAL']) ?></dd></div>
<?php endif; ?>
<?php if ($t($b['DIRECTOR_ACTUAL'])): ?>
        <div class="f"><dt>Director</dt><dd><?= V::e($b['DIRECTOR_ACTUAL']) ?></dd></div>
<?php endif; ?>
<?php if ($nDiscos > 0): ?>
        <div class="f"><dt>Discos propios</dt><dd><?= $num($nDiscos) ?></dd></div>
<?php endif; ?>
<?php if ($nAcomp > 0): ?>
        <div class="f"><dt>Acompañamientos</dt><dd><?= $num($nAcomp) ?></dd></div>
<?php endif; ?>
<?php $webUrl = H::externalUrl($b['WEB']); if ($t($webUrl)): ?>
        <div class="f"><dt>Web</dt><dd><a href="<?= V::e($webUrl) ?>" rel="noopener" target="_blank"><?= V::e(preg_replace('#^https?://#', '', (string) $webUrl)) ?> ↗</a></dd></div>
<?php endif; ?>
    </dl>
    </section>

    <div class="ficha-notas">
    <section class="blk">
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

    </section>
    </div>

    <div class="ficha-cuerpo">
<?php if ($anioUlt !== null): ?>
    <section class="blk">
    <div class="shead" id="acompanamientos">
        <h2>Acompañamientos de <?= $anioUlt ?></h2>
        <span class="n"><?= $num(count($acomp[$anioUlt])) ?></span>
    </div>
    <?= $tablaAcomp($acomp[$anioUlt]) ?>
    </section>
<?php endif; ?>

    <section class="blk">
    <div class="shead" id="estrenos">
        <h2>Marchas estrenadas</h2>
<?php if ($nMarchas > 0): ?>
        <span class="n" id="est-count"><?= $num($nMarchas) ?> · más recientes primero</span>
<?php endif; ?>
    </div>
<?php if ($nMarchas === 0): ?>
    <p class="bio-empty">Sin estrenos documentados.</p>
<?php else: ?>
    <details class="collapse"<?= $nMarchas <= 40 ? ' open' : '' ?>>
    <summary class="collapse-title">Ver <?= $nMarchas === 1 ? 'la marcha' : 'las ' . $num($nMarchas) . ' marchas' ?></summary>
    <div class="collapse-content">
<?php if ($nMarchas >= 8): ?>
    <input class="filter est-filter" type="text" placeholder="filtrar…" aria-label="Filtrar marchas estrenadas" data-filter="est-table" data-count="est-count" data-total="<?= $nMarchas ?>">
<?php endif; ?>
    <div class="scrollx">
    <table class="t" id="est-table" data-sortable>
        <thead><tr>
            <th scope="col">Marcha <span class="ar">↕</span></th>
            <th scope="col">Compositor <span class="ar">↕</span></th>
            <th scope="col" class="t-num" data-type="num">Año <span class="ar">↕</span></th>
            <th scope="col" class="t-num" data-type="num">Grabaciones <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($b['marchas'] as $m): ?>
            <tr>
                <td class="t-main"><a href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>"><?= V::e($m['TITULO']) ?></a></td>
                <td data-l=""><?php foreach ($m['AUTOR'] as $i => $a): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= V::e(S::buildDetailPath('autor', $a['autorId'], (string) $a['nombre'])) ?>"><?= V::e($a['nombre']) ?></a><?php endforeach; ?><?php if ($m['AUTOR'] === []): ?><span class="t-vacio">—</span><?php endif; ?></td>
                <td class="t-num" data-l=""><?= $t($m['FECHA']) ? (int) $m['FECHA'] : '<span class="t-vacio">—</span>' ?></td>
                <td class="t-num" data-l="Grab."><?= (int) $m['N_GRAB'] ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    </details>
<?php endif; ?>
    </section>

<?php if ($nDiscos > 0): ?>
<?php /* Discografía como rejilla de fundas: portada (o funda tipográfica) con
         el título y el año debajo. */ ?>
    <section class="blk">
    <div class="shead" id="discografia">
        <h2>Discografía propia</h2>
        <span class="n"><?= $num($nDiscos) ?></span>
    </div>
    <ul class="fundas">
<?php foreach (array_reverse($b['discos']) as $d): $anio = (int) ($d['FECHA_CD'] ?? 0); ?>
        <li><?= H::tesela((int) $d['ID_DISCO'], (string) $d['NOMBRE_CD'], $anio > 1800 ? $anio : null) ?>
            <a class="funda-t" href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a>
            <span class="funda-s"><?= $anio > 1800 ? $anio . ' · ' : '' ?><?= (int) $d['PISTAS'] ?> pistas</span></li>
<?php endforeach; ?>
    </ul>
    </section>
<?php endif; ?>

<?php if (count($acomp) > 1): ?>
    <section class="blk">
    <div class="shead"><h2>Acompañamientos anteriores</h2></div>
    <div class="stack acomp-anios">
<?php foreach ($acomp as $anio => $filasAnio): if ((int) $anio === $anioUlt) continue; ?>
        <details class="collapse">
            <summary class="collapse-title"><?= (int) $anio ?> <span class="muted">(<?= count($filasAnio) ?>)</span></summary>
            <div class="collapse-content"><?= $tablaAcomp($filasAnio) ?></div>
        </details>
<?php endforeach; ?>
    </div>
    </section>
<?php endif; ?>
    </div>

    <footer class="ficha-pie">Ficha B-<?= $bid ?><?php if (!empty($url)): ?> · enlace permanente: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a><?php endif; ?></footer>
</article>
