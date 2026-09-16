<?php use App\View as V; use App\Auth; use App\AcompanamientoDudaRepo as R;
/** @var array $session @var list<array<string,mixed>> $pendientes @var list<array<string,mixed>> $revisadas @var array|null $notice */
$csrf = Auth::csrfToken($session);
$tipoLabel = ['cristo' => 'Cristo', 'virgen' => 'Virgen', 'cruz_guia' => 'Cruz de Guía', 'ninguno' => 'Ninguno (no es un paso)'];
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Dudas de acompañamientos</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

    <p class="muted small">
        Filas que los scripts de <code>scripts/tmp_acompanamientos/</code> no han sabido
        clasificar solas al parsear la nómina de hermandades y pasos (ver
        <code>docs/acompanamientos-nomina-2026.md</code>): una etiqueta de paso que no
        encaja con los patrones conocidos, o una ficha sin música detectada. Resolver
        aquí deja constancia de la decisión — todavía no escribe en la nómina final
        (<code>hermandad</code>/<code>paso</code>), que sigue pendiente de importar.
    </p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php if (!$pendientes): ?>
    <p class="muted">No hay dudas pendientes de revisión.</p>
<?php else: ?>
    <div class="stack">
<?php foreach ($pendientes as $d): $id = (int) $d['ID_DUDA']; ?>
        <div class="panel">
            <p>
                <span class="chip"><?= V::e($d['LOCALIDAD'] ?? '') ?></span>
                <?php if (!empty($d['DIA'])): ?><span class="muted small"><?= V::e($d['DIA']) ?></span><?php endif; ?>
                · <strong><?= V::e($d['HERMANDAD'] ?? '') ?></strong>
                <span class="muted small">· <?= V::e(R::MOTIVO_LABEL[$d['MOTIVO'] ?? ''] ?? $d['MOTIVO'] ?? '') ?></span>
            </p>
<?php if (!empty($d['ETIQUETA_RAW'])): ?>
            <p class="small">Etiqueta de la fuente: <strong><?= V::e($d['ETIQUETA_RAW']) ?></strong></p>
<?php endif; ?>
<?php if (!empty($d['BANDA_TEXTO'])): ?>
            <p class="small">Banda mencionada: <?= V::e($d['BANDA_TEXTO']) ?>
<?php if (!empty($d['BANDA_URL'])): ?> · <a href="<?= V::e($d['BANDA_URL']) ?>" target="_blank" rel="noopener">ficha en musicofrades ↗</a><?php endif; ?>
            </p>
<?php endif; ?>

            <form class="row" action="/dashboard/acompanamientos-dudas/<?= $id ?>/resolver" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <select class="input" name="TIPO" required>
                    <option value="">— Elige qué es este paso —</option>
<?php foreach ($tipoLabel as $val => $label): ?>
                    <option value="<?= V::e($val) ?>"><?= V::e($label) ?></option>
<?php endforeach; ?>
                </select>
                <input class="input" type="text" name="NOTA" placeholder="Nota (p. ej. banda correcta, aclaración)">
                <button class="btn btn-sm" type="submit">Resolver</button>
            </form>
            <form action="/dashboard/acompanamientos-dudas/<?= $id ?>/descartar" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="NOTA" value="">
                <button class="btn btn-sm btn-ghost" type="submit">Descartar sin resolver</button>
            </form>
        </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($revisadas): ?>
    <h2 class="section-title">Revisadas recientemente</h2>
    <div class="tableList"><table class="table table-zebra table-sm">
        <thead class="thead-neutral"><tr><td>Localidad</td><td>Hermandad</td><td>Motivo</td><td>Resultado</td><td>Cuándo</td></tr></thead>
        <tbody>
<?php foreach ($revisadas as $d): ?>
            <tr>
                <td><?= V::e($d['LOCALIDAD'] ?? '') ?></td>
                <td><?= V::e($d['HERMANDAD'] ?? '') ?></td>
                <td class="small"><?= V::e(R::MOTIVO_LABEL[$d['MOTIVO'] ?? ''] ?? $d['MOTIVO'] ?? '') ?></td>
                <td class="small">
<?php if (($d['ESTADO'] ?? '') === 'descartado'): ?>
                    <span class="muted">descartada</span>
<?php else: ?>
                    <?= V::e($tipoLabel[$d['RESOLUCION_TIPO'] ?? ''] ?? $d['RESOLUCION_TIPO'] ?? '') ?>
<?php endif; ?>
<?php if (!empty($d['RESOLUCION_NOTA'])): ?> · <span class="muted"><?= V::e($d['RESOLUCION_NOTA']) ?></span><?php endif; ?>
                </td>
                <td class="small nums"><?= V::e($d['REVIEWED_AT'] ?? '') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
