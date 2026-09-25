<?php use App\View as V;
/** Celda de años de una línea de rango: al pulsar los años se abre un
 *  formulario para cambiarlos (AdminRepo::editarAniosRango — si el rango
 *  nuevo se solapa con otra línea de la misma banda y paso, se funden).
 *  El conmutador y la confirmación al recortar están en acompanamientos.php.
 *  @var array $rg @var string $slug @var string $csrf */
$anios = $rg['anioInicio'] === $rg['anioFin'] ? (string) $rg['anioInicio'] : ($rg['anioInicio'] . '–' . $rg['anioFin']);
?>
<span data-anios-display>
    <button type="button" class="btn btn-sm btn-ghost" data-editar-anios title="Cambiar los años"><?= V::e($anios) ?></button>
</span>
<form action="/dashboard/acompanamientos/<?= V::e($slug) ?>/anios-rango" method="POST" class="inline-form" data-anios-form
      data-inicio="<?= (int) $rg['anioInicio'] ?>" data-fin="<?= (int) $rg['anioFin'] ?>" hidden>
    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
    <input type="hidden" name="ids" value="<?= V::e(implode(',', $rg['contratos'])) ?>">
    <input class="input" type="number" name="ANIO_INICIO" value="<?= (int) $rg['anioInicio'] ?>" min="1900" max="2100" required style="width:5.5rem" aria-label="Año inicio">
    –
    <input class="input" type="number" name="ANIO_FIN" value="<?= (int) $rg['anioFin'] ?>" min="1900" max="2100" required style="width:5.5rem" aria-label="Año fin">
    <button class="btn btn-sm btn-neutral" type="submit">Guardar</button>
    <button type="button" class="btn btn-sm btn-ghost" data-anios-cancelar>Cancelar</button>
</form>
