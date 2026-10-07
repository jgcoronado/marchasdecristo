<?php use App\View as V; use App\Auth; use App\Slug as S;
/** @var string $mode @var array $session @var string $action
 *  @var array<string,mixed> $autor @var bool $proposalMode @var array|null $notice @var string|null $error */
$csrf = Auth::csrfToken($session);
$isEdit = $mode === 'edit';
$proposalMode = $proposalMode ?? false;
$val = static fn(string $k): string => V::e($autor[$k] ?? '');

$fields = [
    ['NOMBRE', 'Nombre', 'text'],
    ['APELLIDOS', 'Apellidos', 'text'],
    ['NOMBRE_ART', 'Nombre artístico', 'text'],
    ['F_NAC', 'Año de nacimiento', 'number'],
    ['LUGAR_NAC', 'Lugar de nacimiento', 'text'],
    ['F_DEF', 'Año de defunción', 'number'],
];
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1><?= $isEdit ? 'Editar compositor #' . V::e($autor['ID_AUTOR']) : 'Añadir compositor' ?></h1>
        <div class="row">
<?php if ($isEdit): ?>
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('autor', $autor['ID_AUTOR'], trim(($autor['NOMBRE'] ?? '') . ' ' . ($autor['APELLIDOS'] ?? '')))) ?>" target="_blank">Ver ↗</a>
<?php endif; ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($proposalMode): ?><div class="alert alert-info">Verás una <strong>previsualización</strong> antes de enviar. Tu propuesta la revisará un administrador; no se guarda directamente en la base de datos.</div><?php endif; ?>

    <form class="panel" action="<?= V::e($action) ?>" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach ($fields as [$key, $label, $type]): ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= $label ?></label>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" value="<?= $val($key) ?>">
        </div>
<?php endforeach; ?>
        <div class="field">
            <label class="field-label" for="BIO">Biografía</label>
            <textarea class="input" id="BIO" name="BIO" rows="4"><?= $val('BIO') ?></textarea>
        </div>
        <div><button class="btn btn-neutral" type="submit"><?= $proposalMode ? 'Previsualizar propuesta' : ($isEdit ? 'Guardar cambios' : 'Crear compositor') ?></button></div>
    </form>
</div>
