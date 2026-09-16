<?php use App\View as V; use App\Auth; use App\Slug as S;
/** Índice admin de localidades (N-04, rehecho 2026-08-29).
 *  @var array $session @var list<array{LOCALIDAD:string,N:int}> $localidades @var array|null $notice */
$csrf = Auth::csrfToken($session);
?>
<div class="crumbs">
    <span><a href="/dashboard">Panel</a> › Acompañamientos</span>
</div>

<h1>Acompañamientos</h1>
<p class="muted">Alta manual por rango de años (una localidad cada vez, ver convención del pipeline). Cada localidad tiene su propia página con las hermandades y el histórico ya cargado.</p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<section>
    <h2 class="section-title">Localidades</h2>
<?php if ($localidades): ?>
    <ul class="vease">
<?php foreach ($localidades as $l): ?>
<?php $slug = S::slugify((string) $l['LOCALIDAD']); ?>
        <li>→ <a href="/dashboard/acompanamientos/<?= V::e($slug) ?>"><?= V::e($l['LOCALIDAD']) ?></a> <span class="muted small">(<?= (int) $l['N'] ?>)</span></li>
<?php endforeach; ?>
    </ul>
<?php else: ?>
    <p class="muted">Todavía no hay ninguna localidad con acompañamientos.</p>
<?php endif; ?>
</section>

<section>
    <h2 class="section-title">Localidad nueva</h2>
    <form class="panel" action="/dashboard/acompanamientos/crear" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="field">
            <label class="field-label" for="LOCALIDAD">Nombre (con tildes, tal cual se debe mostrar)</label>
            <input class="input" id="LOCALIDAD" name="LOCALIDAD" type="text" placeholder="p. ej. Málaga" required>
        </div>
        <div><button class="btn btn-neutral" type="submit">Empezar</button></div>
    </form>
</section>
