<?php use App\View as V; use App\Auth; use App\Html as H;
/** @var array $session @var array<string,mixed> $prop @var array<string,mixed>|null $actual
 *  @var list<array{ID_AUTOR:int,NOMBRE_COMPLETO:string}> $authors @var list<string> $editable
 *  @var string|null $bandaNombre @var array|null $notice @var string|null $error */
$csrf = Auth::csrfToken($session);
$ent = (string) ($prop['entidad'] ?? '');
$acc = (string) ($prop['accion'] ?? '');
$datos = (array) ($prop['datos'] ?? []);
$id = (string) ($prop['id'] ?? '');
$esEdicion = $acc === 'edit';

$entLabel = ['marcha' => 'Marcha', 'banda' => 'Banda', 'autor' => 'Compositor'];
$accLabel = ['add' => 'Alta', 'edit' => 'Edición'];
$fieldLabels = [
    'TITULO' => 'Título', 'FECHA' => 'Fecha (año)', 'DEDICATORIA' => 'Dedicatoria',
    'LOCALIDAD' => 'Localidad', 'PROVINCIA' => 'Provincia', 'AUDIO' => 'Audio (URL)',
    'BANDA_ESTRENO' => 'ID banda de estreno', 'ESTILO' => 'Estilo', 'DETALLES_MARCHA' => 'Detalles',
    'NOMBRE' => 'Nombre', 'APELLIDOS' => 'Apellidos', 'NOMBRE_ART' => 'Nombre artístico',
    'F_NAC' => 'Fecha nacimiento', 'LUGAR_NAC' => 'Lugar nacimiento', 'F_DEF' => 'Fecha defunción', 'BIO' => 'Biografía',
    'NOMBRE_BREVE' => 'Nombre breve', 'NOMBRE_COMPLETO' => 'Nombre completo',
    'FECHA_FUND' => 'Fecha fundación', 'FECHA_EXT' => 'Fecha extinción',
    'DIRECTOR_ACTUAL' => 'Director actual', 'DIR_MUS_ACTUAL' => 'Director musical actual',
    'WEB' => 'Web', 'LINK_FORO' => 'Enlace al foro',
];
$textareas = ['BIO', 'DETALLES_MARCHA'];
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Revisar propuesta</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard/propuestas">← Propuestas</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

    <div class="panel">
        <p>
            <span class="chip"><?= V::e($entLabel[$ent] ?? $ent) ?></span>
            <strong><?= V::e($accLabel[$acc] ?? $acc) ?></strong>
<?php if ($prop['target_id'] !== null): ?> sobre el registro <strong>#<?= (int) $prop['target_id'] ?></strong><?php endif; ?>
            · propuesta de <strong><?= V::e($prop['autor'] ?? '') ?></strong>
            · <?= V::e(date('Y-m-d H:i', (int) ($prop['creado_ts'] ?? 0))) ?>
        </p>
<?php if ($esEdicion && $actual === null): ?>
        <p class="alert alert-error">Atención: el registro #<?= (int) ($prop['target_id'] ?? 0) ?> ya no existe en la base de datos local.</p>
<?php endif; ?>
        <p class="muted small">Puedes ajustar los valores antes de aceptar. Al aceptar se aplican sobre la base de datos local.</p>
    </div>

    <section>
        <h2 class="section-title">Previsualización <span class="muted small">· cómo quedará la ficha con los valores propuestos</span></h2>
        <div class="panel"><?= V::capture('admin/_ficha_preview', ['entidad' => $ent, 'datos' => $datos, 'authors' => $authors, 'bandaNombre' => $bandaNombre]) ?></div>
    </section>

    <form class="panel" action="/dashboard/propuesta/<?= V::e($id) ?>/aceptar" method="POST" id="propuestaForm" <?= H::municipioFormAttrs(true, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach ($editable as $key):
        if ($key === 'PROVINCIA') continue; // se renderiza junto con LOCALIDAD, ver H::municipioFields()
        $propuesto = array_key_exists($key, $datos) ? (string) ($datos[$key] ?? '') : '';
        $anterior = $actual !== null && array_key_exists($key, $actual) ? (string) ($actual[$key] ?? '') : null;
        $cambia = $esEdicion && $anterior !== null && trim($anterior) !== trim($propuesto);
?>
<?php if ($key === 'LOCALIDAD'):
        $provPropuesta = array_key_exists('PROVINCIA', $datos) && $datos['PROVINCIA'] !== '' ? (string) $datos['PROVINCIA'] : null;
?>
        <div class="field">
            <label class="field-label">Localidad / provincia propuesta<?= $cambia ? ' <span class="chip">cambia</span>' : '' ?></label>
            <p class="muted small">Tal como la propuso el editor: «<?= V::e($propuesto) ?><?= $provPropuesta ? ', ' . V::e($provPropuesta) : '' ?>». Ajusta abajo al par correcto del listado antes de aceptar (o añádelo si no existe).</p>
        </div>
<?= H::municipioFields($propuesto, $provPropuesta) ?>
<?php continue; ?>
<?php endif; ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= V::e($fieldLabels[$key] ?? $key) ?><?= $cambia ? ' <span class="chip">cambia</span>' : '' ?></label>
<?php if ($key === 'ESTILO'): ?>
            <select class="input" id="ESTILO" name="ESTILO">
                <option value="" <?= $propuesto === '' ? 'selected' : '' ?>>— Sin asignar —</option>
                <option value="CCTT" <?= $propuesto === 'CCTT' ? 'selected' : '' ?>>Cornetas y Tambores (CCTT)</option>
                <option value="AM" <?= $propuesto === 'AM' ? 'selected' : '' ?>>Agrupación Musical (AM)</option>
            </select>
<?php elseif (in_array($key, $textareas, true)): ?>
            <textarea class="input" id="<?= $key ?>" name="<?= $key ?>" rows="4"><?= V::e($propuesto) ?></textarea>
<?php else: ?>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="text" value="<?= V::e($propuesto) ?>">
<?php endif; ?>
<?php if ($cambia): ?>
            <p class="muted small">Actual: <?= $anterior === '' ? '<em>(vacío)</em>' : V::e($anterior) ?></p>
<?php endif; ?>
        </div>
<?php endforeach; ?>

<?php if ($ent === 'marcha'): ?>
        <div class="field">
            <label class="field-label">Autor(es)</label>
            <div id="autoresBox" class="chips">
<?php foreach ($authors as $a): ?>
                <span class="chip" data-id="<?= (int) $a['ID_AUTOR'] ?>">
                    <input type="hidden" name="autoresIds[]" value="<?= (int) $a['ID_AUTOR'] ?>">
                    <span><?= V::e($a['NOMBRE_COMPLETO']) ?></span>
                    <button type="button" class="chip-x" aria-label="Quitar">×</button>
                </span>
<?php endforeach; ?>
            </div>
            <div class="autocomplete">
                <input class="input" id="autorSearch" type="text" placeholder="Buscar compositor (mín. 3 caracteres)…" autocomplete="off">
                <div id="autorSuggest" class="suggest" hidden></div>
            </div>
            <p class="muted">Debe haber al menos un autor.</p>
        </div>
<?php endif; ?>

        <div class="row">
            <button class="btn btn-neutral" type="submit">Aceptar y aplicar</button>
        </div>
    </form>

    <form class="panel" action="/dashboard/propuesta/<?= V::e($id) ?>/rechazar" method="POST" onsubmit="return confirm('¿Rechazar esta propuesta? No se aplicará ningún cambio.');">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="field">
            <label class="field-label" for="motivo">Rechazar — motivo (opcional)</label>
            <input class="input" id="motivo" name="motivo" type="text" placeholder="p. ej. datos duplicados">
        </div>
        <div><button class="btn btn-sm btn-ghost" type="submit">Rechazar propuesta</button></div>
    </form>
</div>
<?php if ($ent === 'marcha' || $ent === 'banda'): ?>
<script src="/assets/admin.js" defer></script>
<?php endif; ?>
