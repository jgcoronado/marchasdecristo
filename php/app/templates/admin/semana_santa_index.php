<?php use App\View as V; use App\Auth; use App\Slug as S;
/** Índice admin de localidades con nómina de Semana Santa (N-06).
 *  @var array $session @var list<string> $localidades @var array|null $notice */
$csrf = Auth::csrfToken($session);
?>
<div class="crumbs">
    <span><a href="/dashboard">Panel</a> › Semana Santa</span>
</div>

<h1>Semana Santa</h1>
<p class="muted">Días, hermandades y pasos de cada localidad. Sobre esta base se colocan luego los <a href="/dashboard/acompanamientos">acompañamientos</a>.</p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<section>
    <h2 class="section-title">Localidades</h2>
<?php if ($localidades): ?>
    <ul class="vease">
<?php foreach ($localidades as $l): ?>
<?php $slug = S::slugify($l); ?>
        <li>→ <a href="/dashboard/semana-santa/<?= V::e($slug) ?>"><?= V::e($l) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php else: ?>
    <p class="muted">Todavía no hay ninguna localidad con nómina cargada.</p>
<?php endif; ?>
</section>

<section>
    <h2 class="section-title">Localidad nueva</h2>
    <form class="panel" action="/dashboard/semana-santa/crear" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="field">
            <label class="field-label" for="LOCALIDAD">Nombre (con tildes, tal cual se debe mostrar)</label>
            <input class="input" id="LOCALIDAD" name="LOCALIDAD" type="text" placeholder="p. ej. Málaga" required>
        </div>
        <div><button class="btn btn-neutral" type="submit">Empezar</button></div>
    </form>
</section>
