<?php use App\View as V; use App\Auth; use App\Slug as S; use App\Html as H;
/** Índice admin de localidades con nómina de Semana Santa (N-06).
 *  @var array $session @var array<string,list<string>> $grupos PROVINCIA ('' = sin provincia) → localidades, ya ordenados
 *  @var array|null $notice */
$csrf = Auth::csrfToken($session);
?>
<div class="crumbs">
    <span><a href="/dashboard">Panel</a> › Semana Santa</span>
</div>

<div class="admin-bar">
    <h1>Semana Santa</h1>
    <div class="row">
        <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos">Acompañamientos →</a>
    </div>
</div>
<p class="muted">Días, hermandades y pasos de cada localidad. Sobre esta base se colocan luego los <a href="/dashboard/acompanamientos">acompañamientos</a>.</p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<section>
    <h2 class="section-title">Localidades</h2>
<?php if ($grupos): ?>
    <div class="stack">
<?php foreach ($grupos as $prov => $ls): ?>
        <details class="collapse">
            <summary class="collapse-title"><?= $prov === '' ? 'Sin provincia' : V::e((string) $prov) ?> <span class="muted small">(<?= count($ls) ?>)</span></summary>
            <div class="collapse-content">
<?php if ($prov === ''): ?>
                <p class="muted small">Elige abajo la provincia y la localidad para asignársela.</p>
<?php endif; ?>
                <ul class="vease">
<?php foreach ($ls as $l): ?>
                    <li>→ <a href="/dashboard/semana-santa/<?= V::e(S::slugify($l)) ?>"><?= V::e($l) ?></a></li>
<?php endforeach; ?>
                </ul>
            </div>
        </details>
<?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="muted">Todavía no hay ninguna localidad con nómina cargada.</p>
<?php endif; ?>
</section>

<section>
    <h2 class="section-title">Localidad nueva</h2>
    <form class="panel" action="/dashboard/semana-santa/crear" method="POST" <?= H::municipioFormAttrs(true, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <p class="muted small">Provincia y localidad del listado de municipios, como en bandas y dedicatorias. Si la localidad ya tiene nómina, se abre la suya.</p>
<?= H::municipioFields('', null) ?>
        <div><button class="btn btn-neutral" type="submit">Empezar</button></div>
    </form>
</section>

<script src="/assets/admin.js" defer></script>
