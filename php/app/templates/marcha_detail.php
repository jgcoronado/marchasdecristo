<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Pages as P; use App\EnlaceRepo as ER;
/** @var array<string,mixed> $m
 *  @var array{original: array<string,string>, actual: array<string,string>} $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */

// "truthy" al estilo JS: null, '', 0, 0.0 y false son falsos; '0' (string) es verdadero.
$t = static fn($v): bool => !($v === null || $v === '' || $v === 0 || $v === 0.0 || $v === false);

$num = static fn($n): string => number_format((int) $n, 0, ',', '.');

/** 208 → "3 min 28 s" */
$dur = static function ($seg): string {
    $s = (int) $seg;
    return $s > 0 ? intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s' : '';
};

/** Forma de lectura: "Nombre Apellidos" (o lo que exista). En la ficha se lee
 *  de corrido, así que no se invierte al estilo catálogo ("Apellidos, Nombre"). */
$autoridad = static function (array $a): string {
    $ap = trim((string) ($a['APELLIDOS'] ?? ''));
    $no = trim((string) ($a['NOMBRE'] ?? ''));
    if ($ap !== '' && $no !== '') return $no . ' ' . $ap;
    return $no !== '' ? $no : $ap;
};

$mid = (int) $m['ID_MARCHA'];
$tipo = $t($m['TIPO'] ?? null) ? ucfirst(mb_strtolower((string) $m['TIPO'])) : 'Marcha';
$estilo = match ($m['ESTILO'] ?? null) {
    'CCTT' => 'Cornetas y Tambores',
    'AM' => 'Agrupación Musical',
    default => '',
};
$duracion = $dur($m['DURACION_SEG'] ?? 0);
$autores = $m['AUTORES_FICHA'] ?? [];

// Localidad (Provincia) — se muestra pegada a la dedicatoria, no como fila
// propia. La provincia se omite si coincide con la localidad ("Sevilla").
$localidad = '';
if ($t($m['LOCALIDAD'])) {
    $mismaProvincia = $t($m['PROVINCIA']) && mb_strtolower((string) $m['PROVINCIA'], 'UTF-8') === mb_strtolower((string) $m['LOCALIDAD'], 'UTF-8');
    $localidad = (string) $m['LOCALIDAD'] . ($t($m['PROVINCIA']) && !$mismaProvincia ? ' (' . $m['PROVINCIA'] . ')' : '');
} elseif ($t($m['PROVINCIA'])) {
    $localidad = (string) $m['PROVINCIA'];
}

/* La BD abrevia el tipo de corporación al estilo del boletín impreso
   ("Hdad", "Cofr.", "Agrup Parr"). En la ficha se escriben enteras: el
   visitante no tiene por qué saber la jerga. Solo toca la palabra suelta —
   "Cofrentes" o "Hdades" no se ven afectados. */
$expandir = static fn(string $s): string => (string) preg_replace(
    ['/\bAgrup\.?\s*Parr\.?(?!\p{L})/iu', '/\bHdad\.?(?!\p{L})/iu', '/\bCofr\.?(?!\p{L})/iu'],
    ['Agrupación Parroquial', 'Hermandad', 'Cofradía'],
    $s
);

// Notas: la BD guarda '<br>' literales; se escapan y se restauran solo esos saltos.
$notas = '';
if ($t($m['DETALLES_MARCHA'])) {
    $notas = str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($m['DETALLES_MARCHA']));
}

$nGrab = (int) $m['discosLength'];
// FECHA puede venir normalizada a 's/f': solo los años reales enlazan a su hub.
$anioOk = preg_match('/^\d{4}$/', (string) $m['FECHA']) === 1;
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> › <a href="/marcha">Marchas</a><?php if ($anioOk): ?> › <a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= V::e($m['FECHA']) ?></a><?php endif; ?> › M-<?= $mid ?></span>
</div>

<article class="record">
    <h1><?= V::e($m['TITULO']) ?></h1>

<?php
/* Sección Escuchar: una sola botonera homogénea, y partida en dos pestañas
   (original / actual) cuando la marcha lleva ya bastantes años sobre la calle
   y sus grabaciones de época y actuales no se parecen. Ver Html::escuchar. */
$enl = $enlaces ?? ['original' => [], 'actual' => []];
$anioMarcha = $anioOk ? (int) $m['FECHA'] : null;
$escuchar = H::escuchar($enl, $m['AUDIO'] ?? null, ER::admiteVersiones($anioMarcha), $mid);
$hayEscuchar = $escuchar !== '';
?>
<?php /* Las anclas solo se pintan si la ficha no cabe en pantalla. Con pocas
         grabaciones llevaban a secciones visibles sin desplazarse: ruido. */ ?>
<?php if ($nGrab >= 12): ?>
    <nav class="rectabs" aria-label="Secciones de la ficha">
        <a href="#datos">Datos</a>
<?php if ($hayEscuchar): ?>
        <a href="#escuchar">Escuchar</a>
<?php endif; ?>
        <a href="#grabaciones">Grabaciones (<?= $num($nGrab) ?>)</a>
    </nav>
<?php endif; ?>

    <dl class="desc" id="datos">
<?php /* Una fila por dato. Compositores comparten fila (uno por línea dentro
         del <dd>). Grabaciones se elimina: la sección de abajo ya muestra el
         recuento. Dedicatoria lleva la localidad a continuación en la misma
         celda. Tipo se omite casi siempre (ver condición al final). */ ?>
<?php if ($autores !== []): ?>
        <div class="f"><dt><?= count($autores) > 1 ? 'Compositores' : 'Compositor' ?></dt><dd><?php foreach ($autores as $i => $a): ?><?= $i > 0 ? '<br>' : '' ?><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')))) ?>"><?= V::e($autoridad($a)) ?></a><?php if ((int) $a['N_MARCHAS'] > 1): ?> <span class="cnt"><?= $num($a['N_MARCHAS']) ?> marchas compuestas</span><?php endif; ?><?php endforeach; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['BANDA_ESTRENO'])): ?>
        <div class="f"><dt>Estrenada por</dt><dd><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) ?>"><?= V::e($m['BANDA_NOMBRE']) ?></a><?php if ($t($m['BANDA_LOC'])): ?>, <?= V::e($m['BANDA_LOC']) ?><?php endif; ?><?php if ((int) $m['BANDA_ESTRENOS'] > 1): ?> <span class="cnt"><?= $num($m['BANDA_ESTRENOS']) ?> marchas estrenadas</span><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['FECHA'])): ?>
        <div class="f"><dt>Año</dt><dd><?= V::e($m['FECHA']) ?></dd></div>
<?php endif; ?>
<?php $hayDedic = $t($m['DEDICATORIA']) && $m['DEDICATORIA'] !== '0'; ?>
<?php if ($hayDedic): ?>
        <div class="f"><dt>Dedicatoria</dt><dd><?= V::e($expandir((string) $m['DEDICATORIA'])) ?><?php if ($localidad !== ''): ?>, <?= V::e($localidad) ?><?php endif; ?></dd></div>
<?php elseif ($localidad !== ''): ?>
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

<?php /* Escuchar va abierto: es lo que la mayoría de visitantes viene a hacer.
         Plegado tras un <details> se llevaba un clic el contenido principal de
         la ficha. Ya no hay reproductor incrustado: todas las escuchas son
         botones idénticos, así que no se carga nada de terceros hasta que el
         visitante decide irse al servicio. */ ?>
<?php if ($hayEscuchar): ?>
    <div class="shead" id="escuchar"><h2>Escuchar</h2></div>
    <?= $escuchar ?>
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
            <th data-type="num">Duración <span class="ar">↕</span></th>
        </tr></thead>
        <tbody>
<?php foreach ($m['discos'] as $d):
    $anio = (int) (float) ($d['FECHA_CD'] ?? 0);
?>
            <tr>
                <td><?= $anio > 1800 ? $anio : '—' ?></td>
                <td><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></td>
                <td><?php if ($t($d['ID_BANDA'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA_BREVE'])) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?> - <?= V::e($d['BANDA_LOC']) ?><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <?php /* La duración mostrada es la REAL del track. Si la grabación
                         abre con intro de percusión (~40 s de tambores), se marca
                         con el icono para explicar por qué es más larga que las
                         demás; el descuento solo se aplica al calcular la mediana. */ ?>
                <td><?php if (!empty($d['DURACION_SEG'])): ?>
                    <?= gmdate('i:s', (int) $d['DURACION_SEG']) ?><?php if (!empty($d['PERCUSION'])): ?><span class="perc" title="Empieza con introducción de percusión (unos 40 s de tambores antes de la marcha)" aria-label="Con introducción de percusión">🥁</span><?php endif; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
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
    $vease[] = '<a href="' . V::e($p) . '">' . V::e($autoridad($a)) . '</a> — ' . $label . ' <span class="cnt">A-' . (int) $a['ID_AUTOR'] . '</span>';
}
if ($t($m['BANDA_ESTRENO']) && (int) $m['BANDA_ESTRENOS'] > 1) {
    $p = S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE']);
    $vease[] = '<a href="' . V::e($p) . '">' . V::e($m['BANDA_NOMBRE']) . '</a> — los ' . $num($m['BANDA_ESTRENOS']) . ' estrenos de la banda <span class="cnt">B-' . (int) $m['BANDA_ESTRENO'] . '</span>';
}
if ($anioOk && (int) $m['N_MISMO_ANIO'] > 1) {
    $vease[] = '<a href="' . V::e(P::anioHubPath((string) $m['FECHA'])) . '">Marchas del año ' . V::e($m['FECHA']) . '</a> <span class="cnt">' . $num($m['N_MISMO_ANIO']) . ' registros</span>';
}
if ($estilo !== '' && (int) ($m['N_MISMO_ESTILO'] ?? 0) > 1 && ($estiloHub = P::estiloHubPath((string) $m['ESTILO'])) !== null) {
    $vease[] = '<a href="' . V::e($estiloHub) . '">Marchas de ' . V::e(mb_strtolower($estilo, 'UTF-8')) . '</a> <span class="cnt">' . $num($m['N_MISMO_ESTILO']) . ' registros</span>';
}
if ($t($m['PROVINCIA']) && (int) $m['N_MISMA_PROV'] > 1) {
    $vease[] = '<a href="' . V::e(P::provinciaHubPath((string) $m['PROVINCIA'])) . '">Marchas de la provincia de ' . V::e($m['PROVINCIA']) . '</a> <span class="cnt">' . $num($m['N_MISMA_PROV']) . ' registros</span>';
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
