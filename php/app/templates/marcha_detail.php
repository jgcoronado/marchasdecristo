<?php use App\View as V; use App\Slug as S; use App\Html as H; use App\Pages as P; use App\EnlaceRepo as ER;
/** @var array<string,mixed> $m
 *  @var array{original: array<string,string>, actual: array<string,string>} $enlaces */
/** @var string|null $url  URL canónica absoluta (permalink) */
/** @var list<array<string,mixed>> $relacionadas  Repo::marchasRelacionadas */

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
<?php
// ── Identidad: sello generado + mosaico con las portadas de sus grabaciones ──
$variante = H::varianteEstilo($m['ESTILO'] ?? null);
$sello = $anioOk
    ? H::sello((string) $m['FECHA'], (string) ($m['ESTILO'] ?? ''), $variante)
    : H::sello($variante !== '' ? (string) $m['ESTILO'] : 'M', 'sin fecha', $variante);
$portadas = [];
foreach ($m['discos'] as $d) {
    $did = (int) $d['ID_DISCO'];
    if ($did > 0 && !isset($portadas[$did])) {
        $portadas[$did] = ['id' => $did, 'nombre' => (string) $d['NOMBRE_CD'],
            'anio' => (int) ($d['FECHA_CD'] ?? 0) > 1800 ? (int) $d['FECHA_CD'] : null,
            'sub' => (string) ($d['BANDA_BREVE'] ?? '')];
    }
}
$portadas = array_values($portadas);
$nombresAutores = [];
foreach ($autores as $a) {
    $nombresAutores[] = '<a href="' . V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')))) . '">' . V::e($autoridad($a)) . '</a>';
}
$firma = match (count($nombresAutores)) {
    0 => '',
    1 => $nombresAutores[0],
    default => implode(', ', array_slice($nombresAutores, 0, -1)) . ' y ' . end($nombresAutores),
};
$hayDedic = $t($m['DEDICATORIA']) && $m['DEDICATORIA'] !== '0';
?>
<div class="crumbs">
    <span><a href="/">Inicio</a> / <a href="/marcha">Marchas</a><?php if ($anioOk): ?> / <a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= V::e($m['FECHA']) ?></a><?php endif; ?></span>
</div>

<article class="record">
    <header class="fh fh-obra">
        <?= $sello ?>
        <div class="fh-txt">
            <p class="fh-k"><?= H::chipEstilo($m['ESTILO'] ?? null) ?><?php if ($t($m['TIPO']) && mb_strtolower($tipo, 'UTF-8') !== 'marcha procesional'): ?><span class="dato"><?= V::e($tipo) ?></span><?php endif; ?></p>
            <h1><?= V::e($m['TITULO']) ?></h1>
<?php if ($firma !== ''): ?>
            <p class="fh-by">de <?= $firma ?></p>
<?php endif; ?>
<?php if ($t($m['BANDA_ESTRENO']) || $hayDedic): ?>
            <p class="fh-sub"><?php if ($t($m['BANDA_ESTRENO'])): ?>Estrenada por <a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) ?>"><?= V::e($m['BANDA_NOMBRE']) ?></a><?php if ($t($m['BANDA_LOC'])): ?> (<?= V::e($m['BANDA_LOC']) ?>)<?php endif; ?>. <?php endif; ?><?php if ($hayDedic): ?>Dedicada a <?= V::e($expandir((string) $m['DEDICATORIA'])) ?><?php if ($localidad !== ''): ?>, <?= V::e($localidad) ?><?php endif; ?>.<?php endif; ?></p>
<?php endif; ?>
        </div>
<?php if ($portadas !== []): ?>
        <div class="fh-mos"><?= H::mosaico($portadas, 8, count($portadas) === 1 ? 'Disco donde está grabada' : 'Discos donde está grabada') ?></div>
<?php endif; ?>
    </header>

<?php
/* Sección Escuchar: una sola botonera homogénea, y partida en dos pestañas
   (original / actual) cuando la marcha lleva ya bastantes años sobre la calle
   y sus grabaciones de época y actuales no se parecen. Ver Html::escuchar. */
$enl = $enlaces ?? ['original' => [], 'actual' => []];
$anioMarcha = $anioOk ? (int) $m['FECHA'] : null;
$escuchar = H::escuchar($enl, $m['AUDIO'] ?? null, ER::admiteVersiones($anioMarcha), $mid);
$hayEscuchar = $escuchar !== '';
?>
<?php /* Las anclas solo se pintan si la ficha no cabe en pantalla. */ ?>
<?php if ($nGrab >= 12): ?>
    <nav class="rectabs" aria-label="Secciones de la ficha">
        <a href="#datos">Datos</a>
        <a href="#escuchar">Escuchar</a>
        <a href="#grabaciones">Grabaciones (<?= $num($nGrab) ?>)</a>
    </nav>
<?php endif; ?>

<?php /* Dos columnas. La tabla de datos es estrecha por naturaleza (etiqueta +
         valor): a todo el ancho dejaba media caja vacía a la derecha. Va en la
         columna lateral, con la escucha y las notas, y el ancho se lo llevan
         las tablas que sí lo necesitan: grabaciones y relacionadas. */ ?>
    <div class="fgrid">
    <aside class="fcol">
    <section class="blk blk-ficha">
    <div class="shead"><h2>Ficha</h2><span class="n">M-<?= $mid ?></span></div>
    <dl class="desc" id="datos">
<?php if ($autores !== []): ?>
        <div class="f"><dt><?= count($autores) > 1 ? 'Compositores' : 'Compositor' ?></dt><dd><?php foreach ($autores as $i => $a): ?><div class="par"><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], trim(($a['NOMBRE'] ?? '') . ' ' . ($a['APELLIDOS'] ?? '')))) ?>"><?= V::e($autoridad($a)) ?></a><?php if ((int) $a['N_MARCHAS'] > 1): ?> <span class="cnt"><?= $num($a['N_MARCHAS']) ?> marchas compuestas</span><?php endif; ?></div><?php endforeach; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['BANDA_ESTRENO'])): ?>
        <div class="f"><dt>Estrenada por</dt><dd><a href="<?= V::e(S::buildDetailPath('banda', $m['BANDA_ESTRENO'], (string) $m['BANDA_NOMBRE'])) ?>"><?= V::e($m['BANDA_NOMBRE']) ?></a><?php if ($t($m['BANDA_LOC'])): ?>, <?= V::e($m['BANDA_LOC']) ?><?php endif; ?><?php if ((int) $m['BANDA_ESTRENOS'] > 1): ?> <span class="cnt"><?= $num($m['BANDA_ESTRENOS']) ?> marchas estrenadas</span><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['FECHA'])): ?>
        <div class="f"><dt>Año</dt><dd><?php if ($anioOk): ?><a href="<?= V::e(P::anioHubPath((string) $m['FECHA'])) ?>"><?= V::e($m['FECHA']) ?></a><?php if ((int) $m['N_MISMO_ANIO'] > 1): ?> <span class="cnt"><?= $num($m['N_MISMO_ANIO']) ?> marchas de ese año</span><?php endif; ?><?php else: ?><?= V::e($m['FECHA']) ?><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($hayDedic): ?>
        <div class="f"><dt>Dedicatoria</dt><dd><?= V::e($expandir((string) $m['DEDICATORIA'])) ?><?php if ($localidad !== ''): ?>, <?= V::e($localidad) ?><?php endif; ?></dd></div>
<?php elseif ($localidad !== ''): ?>
        <div class="f"><dt>Localidad</dt><dd><?= V::e($localidad) ?></dd></div>
<?php endif; ?>
<?php if ($estilo !== ''): ?>
        <div class="f"><dt>Estilo</dt><dd><?php $estiloHub = P::estiloHubPath((string) $m['ESTILO']); ?><?php if ($estiloHub !== null): ?><a href="<?= V::e($estiloHub) ?>"><?= V::e($estilo) ?></a><?php else: ?><?= V::e($estilo) ?><?php endif; ?></dd></div>
<?php endif; ?>
<?php if ($t($m['PROVINCIA']) && (int) $m['N_MISMA_PROV'] > 1): ?>
        <div class="f"><dt>Provincia</dt><dd><a href="<?= V::e(P::provinciaHubPath((string) $m['PROVINCIA'])) ?>"><?= V::e($m['PROVINCIA']) ?></a> <span class="cnt"><?= $num($m['N_MISMA_PROV']) ?> marchas</span></dd></div>
<?php endif; ?>
<?php if ($duracion !== ''): ?>
        <div class="f"><dt>Duración</dt><dd><?= V::e($duracion) ?></dd></div>
<?php endif; ?>
<?php if ($t($m['TIPO']) && mb_strtolower($tipo, 'UTF-8') !== 'marcha procesional'): ?>
        <div class="f"><dt>Tipo</dt><dd><?= V::e($tipo) ?></dd></div>
<?php endif; ?>
    </dl>
    </section>

<?php /* Escuchar va abierto y sin reproductor incrustado (decisión anterior que
         se mantiene: nada de terceros hasta que el visitante decide irse). Lo
         nuevo es el caso vacío: casi la mitad del catálogo no tiene escucha, y
         ese hueco ahora pide la grabación en vez de desaparecer. */ ?>
    <section class="blk blk-escuchar">
    <div class="shead" id="escuchar"><h2>Escuchar</h2></div>
<?php if ($hayEscuchar): ?>
    <?= $escuchar ?>
<?php else: ?>
    <p class="pide"><b>Sin enlaces de escucha todavía.</b> ¿Conoces una grabación publicada de esta marcha? <a href="/contacto">Envíanos el enlace</a> y la añadimos.</p>
<?php endif; ?>
    </section>

<?php if ($notas !== ''): ?>
    <section class="blk">
    <div class="shead"><h2>Notas</h2></div>
    <p class="notas"><?= $notas ?></p>
    </section>
<?php endif; ?>
    </aside>

    <div class="fmain">
    <section class="blk">
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
<?php /* Ordenar una tabla de dos filas no ordena nada: con menos de tres
         grabaciones la cabecera se queda quieta y sin flechas. */
$ordenable = count($m['discos']) > 2; ?>
    <div class="scrollx">
    <table class="reg" id="grab-table"<?= $ordenable ? ' data-sortable' : '' ?>>
        <thead><tr>
            <th scope="col" data-type="num">Año<?php if ($ordenable): ?> <span class="ar">↕</span><?php endif; ?></th>
            <th scope="col">Grabación<?php if ($ordenable): ?> <span class="ar">↕</span><?php endif; ?></th>
            <th scope="col">Banda<?php if ($ordenable): ?> <span class="ar">↕</span><?php endif; ?></th>
            <th scope="col" data-type="num" class="mobile-hide">Duración<?php if ($ordenable): ?> <span class="ar">↕</span><?php endif; ?></th>
        </tr></thead>
        <tbody>
<?php foreach ($m['discos'] as $d):
    $anio = (int) ($d['FECHA_CD'] ?? 0);
?>
            <tr>
                <td><?= $anio > 1800 ? $anio : '—' ?></td>
                <td><a href="<?= V::e(S::buildDetailPath('disco', $d['ID_DISCO'], (string) $d['NOMBRE_CD'])) ?>"><?= V::e($d['NOMBRE_CD']) ?></a></td>
                <td><?php if ($t($d['ID_BANDA'])): ?><a href="<?= V::e(S::buildDetailPath('banda', $d['ID_BANDA'], (string) $d['BANDA_BREVE'])) ?>"><?= V::e($d['BANDA_BREVE']) ?></a><?php if ($t($d['BANDA_LOC'])): ?> · <?= V::e($d['BANDA_LOC']) ?><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <?php /* La duración mostrada es la REAL del track. Si abre con
                         intro de percusión se marca con una etiqueta de texto
                         (el emoji se dibujaba distinto en cada sistema). */ ?>
                <td class="mobile-hide"><?php if (!empty($d['DURACION_SEG'])): ?>
                    <?= gmdate('i:s', (int) $d['DURACION_SEG']) ?><?php if (!empty($d['PERCUSION'])): ?> <abbr class="perc" title="Empieza con introducción de percusión (unos 40 s de tambores antes de la marcha)">perc.</abbr><?php endif; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
    </section>

<?php if (!empty($relacionadas)): ?>
    <section class="blk">
    <div class="shead"><h2>Marchas relacionadas</h2><span class="n">mismo compositor, dedicatoria o banda</span></div>
    <div class="scrollx">
    <table class="reg">
        <thead><tr><th scope="col">Marcha</th><th scope="col">Compositor</th><th scope="col" data-type="num">Año</th></tr></thead>
        <tbody>
<?php foreach ($relacionadas as $r): ?>
            <tr>
                <td><a href="<?= V::e(S::buildDetailPath('marcha', $r['ID_MARCHA'], (string) $r['TITULO'])) ?>"><?= V::e($r['TITULO']) ?></a></td>
                <td><?= $r['AUTOR'] !== [] ? V::e(V::listaY(array_map(static fn(array $a): string => (string) $a['nombre'], $r['AUTOR']))) : '<span class="muted">—</span>' ?></td>
                <td><?= preg_match('/^\d{4}$/', (string) $r['FECHA']) === 1 ? V::e($r['FECHA']) : '<span class="muted">—</span>' ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>
<?php endif; ?>
    </div>
    </div>

    <div class="ids">
<?php if (!empty($url)): ?>
        <span>Enlace permanente: <a href="<?= V::e($url) ?>"><?= V::e(preg_replace('#^https?://#', '', (string) $url)) ?></a></span>
<?php endif; ?>
    </div>
</article>
