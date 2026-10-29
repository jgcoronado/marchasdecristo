<?php use App\View as V;
/** @var string|null $error @var string $username @var string $csrf */
?>
<div class="stack admin-narrow">
    <h1>Acceso al panel</h1>
<?php if ($error): ?>
    <div class="alert alert-error"><?= V::e($error) ?></div>
<?php endif; ?>
    <form class="panel" action="/login" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="field">
            <label class="field-label" for="username">Usuario</label>
            <input class="input" id="username" name="username" type="text" value="<?= V::e($username ?? '') ?>" autocomplete="username" autofocus>
        </div>
        <div class="field">
            <label class="field-label" for="password">Contraseña</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password">
        </div>
        <div><button class="btn btn-neutral" type="submit">Entrar</button></div>
    </form>
</div>
