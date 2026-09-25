<?php use App\View as V; use App\Slug as S;
/** Una línea de rango (años seguidos con la misma banda) en la vista sobre la
 *  nómina de /dashboard/acompanamientos/{localidad}. Se arrastra desde el asa
 *  a otro paso, o se mueve con "Mover…" (diálogo común, ver acompanamientos.php).
 *  La edición de banda usa el mismo marcado que la tabla clásica: admin.js la
 *  engancha por [data-contratos-table] y closest('tr').
 *  Si la hermandad hace ida/vuelta con bandas distintas ($idaVuelta), cada
 *  línea lleva además su selector de tramo (016_ida_vuelta.sql).
 *  @var array $rg @var string $etiqueta @var string $slug @var string $csrf @var bool $idaVuelta */
$idsCsv = implode(',', $rg['contratos']);
$anios = $rg['anioInicio'] === $rg['anioFin'] ? (string) $rg['anioInicio'] : ($rg['anioInicio'] . '–' . $rg['anioFin']);
?>
<tr data-rango data-ids="<?= V::e($idsCsv) ?>">
    <td style="width:3rem;white-space:nowrap">
        <span class="drag-handle" title="Arrastrar a otro paso" aria-hidden="true">⠿</span>
        <input type="checkbox" class="acomp-check" data-ids="<?= V::e($idsCsv) ?>"
               data-dup="<?= $rg['posibleDuplicado'] ? '1' : '0' ?>"
               data-etiqueta="<?= V::e($etiqueta . ' (' . $anios . ', ' . $rg['banda'] . ')') ?>">
    </td>
    <td style="white-space:nowrap">
        <?= V::capture('admin/_acomp_anios', ['rg' => $rg, 'slug' => $slug, 'csrf' => $csrf]) ?>
<?php if (!empty($idaVuelta)): ?>
        <form class="inline-form" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/tramo-rango" method="POST">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <input type="hidden" name="ids" value="<?= V::e($idsCsv) ?>">
            <select class="input acomp-tramo" name="TRAMO" onchange="this.form.submit()" aria-label="Ida o vuelta">
                <option value="">Ida / vuelta…</option>
                <option value="ida"<?= ($rg['tramo'] ?? null) === 'ida' ? ' selected' : '' ?>>Ida</option>
                <option value="vuelta"<?= ($rg['tramo'] ?? null) === 'vuelta' ? ' selected' : '' ?>>Vuelta</option>
            </select>
        </form>
<?php endif; ?>
<?php if ($rg['posibleDuplicado']): ?>
        <span class="badge badge-warn" title="Otro paso de esta hermandad tiene la misma banda en años que se solapan">⚠</span>
<?php endif; ?>
    </td>
    <td>
        <span data-banda-display>
            <a href="<?= V::e(S::buildDetailPath('banda', $rg['idBanda'], $rg['banda'])) ?>"><?= V::e($rg['banda']) ?></a>
            <button type="button" class="btn btn-sm btn-ghost" data-editar-banda>Editar</button>
        </span>
        <form action="/dashboard/acompanamientos/<?= V::e($slug) ?>/banda-rango" method="POST" class="inline-form" data-banda-edit-form hidden>
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <input type="hidden" name="ids" value="<?= V::e($idsCsv) ?>">
            <input type="hidden" name="ID_BANDA" value="<?= (int) $rg['idBanda'] ?>" data-banda-edit-hidden>
            <div class="autocomplete">
                <input class="input" type="text" value="<?= V::e($rg['banda']) ?>" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off" data-banda-edit-search>
                <div class="suggest" data-banda-edit-suggest hidden></div>
            </div>
            <button class="btn btn-sm btn-neutral" type="submit">Guardar</button>
            <button type="button" class="btn btn-sm btn-ghost" data-editar-cancelar>Cancelar</button>
        </form>
    </td>
    <td style="width:9rem;white-space:nowrap;text-align:right">
        <button type="button" class="btn btn-sm btn-ghost" data-mover-paso data-etiqueta="<?= V::e($etiqueta . ' (' . $anios . ', ' . $rg['banda'] . ')') ?>">Mover…</button>
        <form action="/dashboard/acompanamientos/<?= V::e($slug) ?>/borrar-rango" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar <?= count($rg['contratos']) ?> acompañamiento(s) (<?= $anios ?>)?');">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <input type="hidden" name="ids" value="<?= V::e($idsCsv) ?>">
            <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
        </form>
    </td>
</tr>
