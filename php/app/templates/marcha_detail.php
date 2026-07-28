<?php use App\View as V; use App\Slug as S; use App\Media as MD; use App\Html as H; use App\Pages as P;
/** @var array<string,mixed> $m @var array<string,string> $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */

// "truthy" al estilo JS: null, '', 0, 0.0 y false son falsos; '0' (string) es verdadero.
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);

$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** 208 → "3 min 28 s" */
$dur = static function ($seg): string {
    $s = (int) $seg;
    return $s > 0 ? intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : '';
};

/** Forma de autoridad: "Apellidos, Nombre" (o lo que exista). */
$autoridad = static function (array $a): string {
    $ap = trim((string) ($a['APELLIDOS'] ?? ''));
    $no = trim((string) ($a['NOMBRE'] ?? ''));
    if ($ap !== '' && $no !== '') return $ap . ', ' . $no;
    return $ap !== '' ? $ap : $no;
};

$mid = (int) $m['ID_MARCHA'];
$ytid = MD::youtubeId($m['AUDIO'] ?? null);
$audioEsUrl = $t($m['AUDIO']) && preg_match('~^https?://~i', (string) $m['AUDIO']) === 1;
$tipo = $t($m['TIPO'] ?? null) ? ucfirst(mb_strtolower((string) $m['TIPO'])) : 'Marcha';
$estilo = match ($m['ESTILO'] ?? null) {
    'CCTT' => 'Cornetas y Tambores',
    'AM' => 'Agrupación Musical',
    default => '',
};
$duracion = $dur($m['DURACION_SEG'] ?? 0);
$autores = $m['AUTORES_FICHA'] ?? [];

// Asiento bibliográfico bajo el título: solo autor(es) y año de composición
// (sin las fechas de nacimiento/defunción del autor).
$asientoAutores = [];
foreach ($autores as $a) {
    $path = S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')));
    $asientoAutores[] = '<a href="' . V::e($path) . '">' . V::e($autoridad($a)) . '</a>';
}
$asiento = [implode('; ', $asientoAutores)];
if ($t($m['FECHA'])) $asiento[] = V::e((string) $m['FECHA']);

// Localidad (Provincia) para la descripción — la provincia se omite si
// coincide con la localidad (p.ej. "Sevilla (Sevilla)" en las capitales).
$localidad = '';
if ($t($m['LOCALIDAD'])) {
    $mismaProvincia = $t($m['PROVINCIA']) && mb_strtolower((string) $m['PROVINCIA'], 'UTF-8') === mb_strtolower((string) $m['LOCALIDAD'], 'UTF-8');
    $localidad = (string) $m['LOCALIDAD'] . ($t($m['PROVINCIA']) && !$mismaProvincia ? ' (' . $m['PROVINCIA'] . ')' : '');
} elseif ($t($m['PROVINCIA'])) {
    $localidad = (string) $m['PROVINCIA'];
}

// Notas: la BD guarda '<br>' literales; se escapan y se restauran solo esos saltos.
$notas = '';
if ($t($m['DETALLES_MARCHA'])) {
    $notas = str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($m['DETALLES_MARCHA']));
}

$nGrab = (int) $m['discosLength'];
$badge1a = null; // primera fila cuya fecha coincide con la primera grabación
// FECHA puede venir normalizada a 's/f': solo los años reales enlazan a su hub.
$anioOk = preg_match('/^\d{4}$/', (string) $m['FECHA']) === 1;
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › <a href="/marcha">Marchas</a><?php if ($anioOk): ?> › <a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= V::e($m['FECHA']) ?></a><?php endif; ?> › M-<?= $mid ?></span>
</div>

<article class="record">
    <div class="head">
        <span class="eb"><?= V::e($tipo) ?></span>
    </div>
    <h1><?= V::e($m['TITULO']) ?></h1>
<?php if ($asiento[0] !== '' || count($asiento) > 1): ?>
    <p class="asiento"><?= implode('. — ', array_filter($asiento, static fn($s) => $s !== '')) ?>.</p>
<?php endif; ?>

<?php $hayEscuchar = $t($m['AUDIO']) || ($enlaces ?? []) !== []; ?>
    <nav class="rectabs" aria-label="Secciones de la ficha">
        <a href="#datos">Datos</a>
<?php if ($hayEscuchar): ?>
        <a href="#escuchar">Escuchar</a>
<?php endif; ?>
        <a href="#grabaciones">Grabaciones (<?= $num($nGrab) ?>)</a>
    </nav>

    <dl class="desc" id="datos">
<?php /* Fila 1: Compositor(es) / Estrenada por — Fila 2: Año / Grabaciones —
         Fila 3: Dedicatoria / Localidad — Fila 4: Estilo / Duración.
         Tipo se omite casi siempre (ver condición abajo); cuando aparece
         (valor distinto de "marcha procesional"), va al final como fila extra
         para no descuadrar las cuatro filas anteriores. */ ?>
<?php foreach ($autores as $a): ?>
        <div class="f"><dt>Compositor</dt><dd><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')))) ?>"><?= V::e($autoridad($a)) ?></a><?php if ((int) $a['N_MARCHAS'] > 1): ?><br><span class="cnt"><?= $num($a['N_MARCHAS']) ?> marchas compuestas</span><?php endif; ?></dd></div>
<?php endforeach; ?>
<?php if ($t($m['BANDA_ESTRENO'])): ?>
        <div class="f"><dt>Estrenada por</dt><dd><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) ?>"><?= V::e($m['BANDA_NOMBRE']) ?></a><?php if ($t($m['BANDA_LOC'])): ?>, <?= V::e($m['BANDA_LOC']) ?><?php endif; ?><?php if ((int) $m['BANDA_ESTRENOS'] > 1): ?><br><span class="cnt"><?= $num($m['BANDA_ESTRENOS']) ?> marchas estrenadas</span><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['FECHA'])): ?>
        <div class="f"><dt>Año</dt><dd><?= V::e($m['FECHA']) ?></dd></div>
<?php endif; ?>
        <div class="f"><dt>Grabaciones</dt><dd><?= $num($nGrab) ?><?php if ($m['PRIMERA_GRABACION']): ?> <span class="cnt">· primera en <?= (int) $m['PRIMERA_GRABACION'] ?></span><?php endif; ?></dd></div>
<?php if ($t($m['DEDICATORIA']) && $m['DEDICATORIA'] !== '0'): ?>
        <div class="f"><dt>Dedicatoria</dt><dd><?= V::e($m['DEDICATORIA']) ?></dd></div>
<?php endif; ?>
<?php if ($localidad !== ''): ?>
        <div class="f"><dt>Localidad</dt><dd><?= V::e($localidad) ?></dd></div>
<?php endif; ?>
<?php if ($estilo !== ''): ?>
        <div class="f"><dt>Estilo</dt><dd><?= V::e($estilo) ?></dd></div>
<?php endif; ?>
<?php if ($duracion !== ''): ?>
        <div class="f"><dt>Duración</dt><dd><?= V::e($duracion) ?></dd></div>
<?php endif; ?>
<?php if ($t($m['TIPO']) && mb_strtolower($tipo, 'UTF-8') !== 'marcha procesional'): ?>
        <div class="f"><dt>Tipo</dt><dd><?= V::e($tipo) ?></dd></div>
<?php endif; ?>
    </dl>

<?php $enl = $enlaces ?? []; if ($hayEscuchar): ?>
    <details class="collapse listen" id="escuchar">
        <summary class="collapse-title">Escuchar</summary>
        <div class="collapse-content">
<?php if ($ytid !== null): ?>
        <div class="ytembed" data-ytid="<?= V::e($ytid) ?>">
            <button type="button" class="ytfacade" aria-label="Reproducir el vídeo (carga YouTube al pulsar)">
                <img class="ytfacade-img" src="<?= V::e(MD::youtubeThumb($ytid)) ?>" alt="" loading="lazy" width="480" height="270">
                <span class="ytfacade-play" aria-hidden="true"></span>
            </button>
        </div>
<?php endif; ?>
<?php if ($ytid !== null || $audioEsUrl): ?>
        <div class="svcs">
<?php if ($ytid !== null): ?>
            <a class="svc" href="<?= V::e($m['AUDIO']) ?>" rel="noopener" target="_blank">▶ YouTube</a>
<?php else: ?>
            <a class="svc" href="<?= V::e($m['AUDIO']) ?>" rel="noopener" target="_blank">▶ Escuchar</a>
<?php endif; ?>
        </div>
<?php endif; ?>
        <?= H::streaming($enl) ?>
        </div>
    </details>
<?php endif; ?>

<?php if ($notas !== ''): ?>
    <div class="shead"><h2>Notas</h2></div>
    <p class="notas"><?= $notas ?></p>
<?php endif; ?>

    <div class="shead" id="grabaciones">
        <h2>Grabaciones</h2>
<?php if ($nGrab > 0): ?>
        <span class="n" id="grab-count"><?= $num($nGrab) ?> · orden cronológico</span>
<?php if ($nGrab >= 8): ?>
        <input class="filter" type="text" placeholder="filtrar grabaciones…" aria-label="Filtrar grabaciones" data-filter="grab-table" data-count="grab-count" data-total="<?= $nGrab ?>">
<?php endif; ?>
<?php endif; ?>
    </div>
<?php if ($nGrab === 0): ?>
    <p class="bio-empty">Aún sin grabaciones documentadas.</p>
<?php else: ?>
    <div class="scrollx">
    <table class="reg" id="grab-table" data-sortable>
        <thead><tr>
            <th data-type="num">Año <span class="ar">↕</span></th>
            <th>Grabación <span class="ar">↕</span></th>
            <th>Banda <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($m['discos'] as $d):
    $anio = (int) (float) ($d['FECHA_CD'] ?? 0);
    $es1a = $badge1a === null && $anio > 1800 && $anio === (int) $m['PRIMERA_GRABACION'];
    if ($es1a) $badge1a = $d;
?>
            <tr>
                <td><?= $anio > 1800 ? $anio : '—' ?></td>
                <td><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a><?php if ($es1a): ?><span class="badge-1a">◆ 1.ª grabación</span><?php endif; ?></td>
                <td><?php if ($t($d['ID_BANDA'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA_BREVE'])) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?> - <?= V::e($d['BANDA_LOC']) ?><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php
$vease = [];
foreach ($autores as $a) {
    $p = S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')));
    $label = (int) $a['N_MARCHAS'] > 1
        ? 'las ' . $num($a['N_MARCHAS']) . ' marchas del compositor'
        : 'ficha del compositor';
    $vease[] = '→ <a href="' . V::e($p) . '">' . V::e($autoridad($a)) . '</a> — ' . $label . ' <span class="cnt">(A-' . (int) $a['ID_AUTOR'] . ')</span>';
}
if ($t($m['BANDA_ESTRENO']) && (int) $m['BANDA_ESTRENOS'] > 1) {
    $p = S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE']);
    $vease[] = '→ <a href="' . V::e($p) . '">' . V::e($m['BANDA_NOMBRE']) . '</a> — los ' . $num($m['BANDA_ESTRENOS']) . ' estrenos de la banda <span class="cnt">(B-' . (int) $m['BANDA_ESTRENO'] . ')</span>';
}
if ($anioOk && (int) $m['N_MISMO_ANIO'] > 1) {
    $vease[] = '→ <a href="' . V::e(P::anioHubPath((string) $m['FECHA'])) . '">Marchas del año ' . V::e($m['FECHA']) . '</a> <span class="cnt">(' . $num($m['N_MISMO_ANIO']) . ' registros)</span>';
}
if ($estilo !== '' && (int) ($m['N_MISMO_ESTILO'] ?? 0) > 1 && ($estiloHub = P::estiloHubPath((string) $m['ESTILO'])) !== null) {
    $vease[] = '→ <a href="' . V::e($estiloHub) . '">Marchas de ' . V::e(mb_strtolower($estilo, 'UTF-8')) . '</a> <span class="cnt">(' . $num($m['N_MISMO_ESTILO']) . ' registros)</span>';
}
if ($t($m['PROVINCIA']) && (int) $m['N_MISMA_PROV'] > 1) {
    $vease[] = '→ <a href="' . V::e(P::provinciaHubPath((string) $m['PROVINCIA'])) . '">Marchas de la provincia de ' . V::e($m['PROVINCIA']) . '</a> <span class="cnt">(' . $num($m['N_MISMA_PROV']) . ' registros)</span>';
}
?>
<?php if ($vease !== []): ?>
    <div class="shead"><h2>Véase también</h2></div>
    <ul class="vease">
<?php foreach ($vease as $vs): ?>
        <li><?= $vs ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>

    <div class="ids">
<?php if (!empty($url)): ?>
        <span>permalink: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a></span>
<?php endif; ?>
    </div>
</article>
