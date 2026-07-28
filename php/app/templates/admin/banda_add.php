<?php use App\View as V; use App\Auth; use App\Html as H;
/** @var array $session @var string $action @var array<string,mixed> $banda
 *  @var bool $proposalMode @var array|null $notice @var string|null $error */
$csrf = Auth::csrfToken($session);
$proposalMode = $proposalMode ?? false;
$val = static fn(string $k): string => V::e($banda[$k] ?? '');

$fields = [
    ['NOMBRE_BREVE', 'Nombre breve', 'text'],
    ['NOMBRE_COMPLETO', 'Nombre completo', 'text'],
    ['FECHA_FUND', 'Fecha de fundación (año)', 'number'],
    ['FECHA_EXT', 'Fecha de extinción (año)', 'number'],
    ['DIRECTOR_ACTUAL', 'Director actual', 'text'],
    ['DIR_MUS_ACTUAL', 'Director musical actual', 'text'],
    ['WEB', 'Web', 'text'],
];
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Añadir banda</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($proposalMode): ?><div class="alert alert-info">Verás una <strong>previsualización</strong> antes de enviar. Tu propuesta la revisará un administrador; no se guarda directamente en la base de datos.</div><?php endif; ?>

    <form class="panel" action="<?= V::e($action) ?>" method="POST" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach ($fields as [$key, $label, $type]): ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= $label ?><?= $key === 'NOMBRE_BREVE' ? ' <span class="muted small">· obligatorio</span>' : '' ?></label>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>"<?= $type === 'number' ? ' min="1800" max="2100"' : '' ?> value="<?= $val($key) ?>">
        </div>
<?php if ($key === 'NOMBRE_COMPLETO'): ?>
<?= H::municipioFields((string) ($banda['LOCALIDAD'] ?? ''), $banda['PROVINCIA'] ?? null) ?>
<?php endif; ?>
<?php endforeach; ?>
        <div><button class="btn btn-neutral" type="submit"><?= $proposalMode ? 'Previsualizar propuesta' : 'Crear banda' ?></button></div>
    </form>
</div>
