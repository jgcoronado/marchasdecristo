<?php use App\View as V;
/**
 * Previsualización de solo lectura de una ficha a partir de los valores
 * propuestos (no de la BD). Reutiliza el look de las fichas públicas
 * (article.record + dl.desc) pero solo con los campos editables, así que no
 * necesita el modelo hidratado completo de Pages::*Detail.
 *
 * @var string $entidad 'marcha'|'banda'|'autor'
 * @var array<string,mixed> $datos  campo => valor propuesto (strings crudos)
 * @var list<array<string,mixed>> $authors  solo marcha: {NOMBRE_COMPLETO}
 * @var string|null $bandaNombre  solo marcha: nombre de la BANDA_ESTRENO resuelto
 */
$authors = $authors ?? [];
$bandaNombre = $bandaNombre ?? null;
$v = static fn(string $k): string => trim((string) ($datos[$k] ?? ''));
$has = static fn(string $k): bool => trim((string) ($datos[$k] ?? '')) !== '';

$estiloLabel = match ($v('ESTILO')) {
    'CCTT' => 'Cornetas y Tambores (CCTT)',
    'AM' => 'Agrupación Musical (AM)',
    default => '',
};
// Localidad (Provincia)
$localidad = $has('LOCALIDAD')
    ? $v('LOCALIDAD') . ($has('PROVINCIA') ? ' (' . $v('PROVINCIA') . ')' : '')
    : ($has('PROVINCIA') ? $v('PROVINCIA') : '');
// Notas: la BD guarda '<br>' literales; se escapan y se restauran solo esos saltos.
$notas = static function (string $s): string {
    if ($s === '') return '';
    return str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', V::e($s));
};

$titulo = match ($entidad) {
    'marcha' => $v('TITULO'),
    'banda' => $v('NOMBRE_BREVE'),
    'autor' => trim($v('NOMBRE') . ' ' . $v('APELLIDOS')),
    default => '',
};
$anios = static fn(string $a, string $b): string => trim(($a !== '' ? $a : '') . (($a !== '' || $b !== '') ? '–' : '') . ($b !== '' ? $b : ''));
?>
<article class="record" style="margin:0;">
    <h1><?= $titulo !== '' ? V::e($titulo) : '<span class="muted">(sin título)</span>' ?></h1>

    <dl class="desc">
<?php if ($entidad === 'marcha'): ?>
<?php foreach ($authors as $a): ?>
        <div class="f"><dt>Compositor</dt><dd><?= V::e($a['NOMBRE_COMPLETO'] ?? '') ?></dd></div>
<?php endforeach; ?>
<?php if ($has('FECHA')): ?>        <div class="f"><dt>Año</dt><dd><?= V::e($v('FECHA')) ?></dd></div><?php endif; ?>
<?php if ($estiloLabel !== ''): ?>        <div class="f"><dt>Estilo</dt><dd><?= V::e($estiloLabel) ?></dd></div><?php endif; ?>
<?php if ($has('DEDICATORIA') && $v('DEDICATORIA') !== '0'): ?>        <div class="f"><dt>Dedicatoria</dt><dd><?= V::e($v('DEDICATORIA')) ?></dd></div><?php endif; ?>
<?php if ($localidad !== ''): ?>        <div class="f"><dt>Localidad</dt><dd><?= V::e($localidad) ?></dd></div><?php endif; ?>
<?php if ($has('BANDA_ESTRENO')): ?>        <div class="f"><dt>Estreno</dt><dd><?= $bandaNombre !== null && $bandaNombre !== '' ? V::e($bandaNombre) : '<span class="muted">banda #' . V::e($v('BANDA_ESTRENO')) . '</span>' ?></dd></div><?php endif; ?>
<?php if ($has('AUDIO')): ?>        <div class="f"><dt>Audio</dt><dd><?= V::e($v('AUDIO')) ?></dd></div><?php endif; ?>

<?php elseif ($entidad === 'banda'): ?>
<?php if ($has('NOMBRE_COMPLETO')): ?>        <div class="f"><dt>Nombre completo</dt><dd><?= V::e($v('NOMBRE_COMPLETO')) ?></dd></div><?php endif; ?>
<?php if ($localidad !== ''): ?>        <div class="f"><dt>Localidad</dt><dd><?= V::e($localidad) ?></dd></div><?php endif; ?>
<?php if ($anios($v('FECHA_FUND'), $v('FECHA_EXT')) !== ''): ?>        <div class="f"><dt>Actividad</dt><dd><?= V::e($anios($v('FECHA_FUND'), $v('FECHA_EXT'))) ?></dd></div><?php endif; ?>
<?php if ($has('DIRECTOR_ACTUAL')): ?>        <div class="f"><dt>Director</dt><dd><?= V::e($v('DIRECTOR_ACTUAL')) ?></dd></div><?php endif; ?>
<?php if ($has('DIR_MUS_ACTUAL')): ?>        <div class="f"><dt>Director musical</dt><dd><?= V::e($v('DIR_MUS_ACTUAL')) ?></dd></div><?php endif; ?>
<?php if ($has('WEB')): ?>        <div class="f"><dt>Web</dt><dd><?= V::e($v('WEB')) ?></dd></div><?php endif; ?>
<?php if ($has('LINK_FORO')): ?>        <div class="f"><dt>Foro</dt><dd><?= V::e($v('LINK_FORO')) ?></dd></div><?php endif; ?>

<?php elseif ($entidad === 'autor'): ?>
<?php if ($has('NOMBRE_ART')): ?>        <div class="f"><dt>Nombre artístico</dt><dd><?= V::e($v('NOMBRE_ART')) ?></dd></div><?php endif; ?>
<?php if ($anios($v('F_NAC'), $v('F_DEF')) !== ''): ?>        <div class="f"><dt>Vida</dt><dd><?= V::e($anios($v('F_NAC'), $v('F_DEF'))) ?></dd></div><?php endif; ?>
<?php if ($has('LUGAR_NAC')): ?>        <div class="f"><dt>Lugar de nacimiento</dt><dd><?= V::e($v('LUGAR_NAC')) ?></dd></div><?php endif; ?>
<?php endif; ?>
    </dl>

<?php $texto = $entidad === 'autor' ? $v('BIO') : ($entidad === 'marcha' ? $v('DETALLES_MARCHA') : ''); ?>
<?php if ($texto !== ''): ?>
    <div class="shead"><h2><?= $entidad === 'autor' ? 'Biografía' : 'Notas' ?></h2></div>
    <p class="notas"><?= $notas($texto) ?></p>
<?php endif; ?>
</article>
