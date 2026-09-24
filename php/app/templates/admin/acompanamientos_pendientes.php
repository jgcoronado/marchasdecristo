<?php use App\View as V; use App\Auth; use App\AcompanamientoPendienteRepo as R;
/** @var array $session @var list<array<string,mixed>> $grupos @var array|null $notice */
$csrf = Auth::csrfToken($session);
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Bandas pendientes de acompañamientos</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

    <p class="muted small">
        Bandas CCTT/AM mencionadas en malagamusical.blogspot.com (u otra fuente de
        acompañamientos históricos) que no existen todavía en <code>banda</code> o que
        salieron ambiguas al cargar (dos bandas posibles, no se enlazó a ciegas). Elige
        aquí la banda real para convertir TODAS sus filas en contratos de golpe — ver
        <code>php/app/tools/cargar_acompanamientos_malaga_blog.php</code> y
        <code>docs/acompanamientos-nomina-2026.md</code>.
    </p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php if (!$grupos): ?>
    <p class="muted">No hay bandas pendientes de enlazar.</p>
<?php else: ?>
    <div class="stack">
<?php foreach ($grupos as $g): ?>
        <div class="panel">
            <p>
                <strong><?= V::e($g['BANDA_TEXTO']) ?></strong>
                <span class="chip"><?= (int) $g['N'] ?> fila<?= (int) $g['N'] === 1 ? '' : 's' ?></span>
            </p>
            <p class="small muted">
                <?= V::e($g['LOCALIDADES']) ?> · <?= V::e($g['HERMANDADES']) ?> · años <?= V::e($g['ANIOS']) ?>
            </p>
            <form class="row" action="/dashboard/acompanamientos-pendientes/convertir" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="BANDA_TEXTO" value="<?= V::e($g['BANDA_TEXTO']) ?>">
                <input type="hidden" name="ID_BANDA" value="" data-banda-edit-hidden>
                <div style="position:relative">
                    <input class="input" type="text" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off" data-banda-edit-search style="width:20rem">
                    <div class="suggest" data-banda-edit-suggest hidden></div>
                </div>
                <button class="btn btn-sm" type="submit">Enlazar las <?= (int) $g['N'] ?> filas</button>
            </form>
        </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
