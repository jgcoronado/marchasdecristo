<?php use App\View as V; use App\Auth; use App\Slug as S;
/** Nómina de Semana Santa de una localidad: días → hermandades → pasos (N-06).
 *  Base sobre la que se cuelgan luego los acompañamientos (contratos), ver
 *  /dashboard/acompanamientos/{localidad}.
 *  @var array $session @var string $slug @var string $localidad @var bool $esNueva
 *  @var string|null $provincia de localidad_provincia (o la elegida, si es nueva)
 *  @var list<array{ID_DIA:int,NOMBRE:string,ORDEN:int,
 *       hermandades:list<array{ID_HERMANDAD:int,NOMBRE:string,SLUG:string,ORDEN:int,PUEDE_BORRAR:bool,
 *            ES_EXTRA:bool,DIAS:list<array{ID_DIA:int,NOMBRE:string}>,historico:list<array>,
 *            pasos:list<array{ID_PASO:int,NOMBRE:string,ORDEN:int,ES_CRUZ_GUIA:bool,PUEDE_BORRAR:bool}>}>}> $dias
 *       (una hermandad que sale varios días viene una vez por día, ver NominaRepo::cargarLocalidad)
 *  @var bool $variosDias migración 020 aplicada (días extra e históricos)
 *  @var array|null $notice */
$csrf = Auth::csrfToken($session);
$base = "/dashboard/semana-santa/$slug";
?>
<div class="crumbs">
    <span><a href="/dashboard">Panel</a> › <a href="/dashboard/semana-santa">Semana Santa</a> › <?= V::e($localidad) ?></span>
</div>

<div class="admin-bar">
    <h1>Semana Santa — <?= V::e($localidad) ?><?php if ($provincia !== null && $provincia !== $localidad): ?> <span class="muted">(<?= V::e($provincia) ?>)</span><?php endif; ?></h1>
    <?php if (!$esNueva): /* una localidad aún sin días no resuelve en acompañamientos */ ?>
    <div class="row">
        <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos/<?= V::e($slug) ?>">Acompañamientos de <?= V::e($localidad) ?> →</a>
    </div>
    <?php endif; ?>
</div>
<p class="muted">Solo pasos de Cristo; los palios no se registran. Arrastra las filas para reordenar (asa <span aria-hidden="true">⠿</span>) o usa las flechas ↑↓. Ver también <a href="/dashboard/acompanamientos/<?= V::e($slug) ?>">acompañamientos de <?= V::e($localidad) ?></a>.</p>

<?php if ($esNueva): ?><p class="muted small">Localidad nueva: <strong><?= V::e($localidad) ?></strong> (<?= V::e($provincia) ?>) — se crea al guardar el primer día.</p><?php endif; ?>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php if ($dias): ?>
<form id="diasReorderForm" action="<?= V::e($base) ?>/dias/reorder" method="POST" hidden>
    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
    <span id="diasReorderInputs"></span>
</form>
<div class="row" style="justify-content:flex-end;margin-bottom:0.35rem">
    <span class="small muted" data-estado-orden="diasList" hidden></span>
</div>
<div id="diasList" class="stack" data-dias-reorder data-form="diasReorderForm" data-inputs="diasReorderInputs">
<?php foreach ($dias as $dia): ?>
<?php $diaBase = $base . '/dia/' . $dia['ID_DIA']; ?>
<section class="panel" data-dia-row data-id="<?= (int) $dia['ID_DIA'] ?>" draggable="true">
    <div class="row" style="align-items:center;justify-content:space-between;gap:0.5rem">
        <div class="row" style="align-items:center;gap:0.5rem">
            <span class="drag-handle" title="Arrastrar para reordenar" aria-hidden="true">⠿</span>
            <h2 class="section-title" style="margin:0"><?= V::e($dia['NOMBRE']) ?></h2>
            <form class="inline-form" action="<?= V::e($diaBase) ?>/mover" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="direccion" value="up">
                <button class="btn btn-sm btn-ghost" type="submit" title="Subir día">↑</button>
            </form>
            <form class="inline-form" action="<?= V::e($diaBase) ?>/mover" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="direccion" value="down">
                <button class="btn btn-sm btn-ghost" type="submit" title="Bajar día">↓</button>
            </form>
        </div>
        <div class="row" style="align-items:center;gap:0.5rem">
            <form class="inline-form" action="<?= V::e($diaBase) ?>/renombrar" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input class="input" type="text" name="NOMBRE" value="<?= V::e($dia['NOMBRE']) ?>" style="width:14rem">
                <button class="btn btn-sm btn-neutral" type="submit">Renombrar</button>
            </form>
            <form class="inline-form" action="<?= V::e($diaBase) ?>/borrar" method="POST" onsubmit="return confirm('¿Borrar el día «<?= V::e($dia['NOMBRE']) ?>»? Solo se puede si no tiene hermandades.');">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <button class="btn btn-sm btn-ghost" type="submit">Borrar día</button>
            </form>
        </div>
    </div>

<?php if ($dia['hermandades']): ?>
    <form id="hermReorderForm<?= (int) $dia['ID_DIA'] ?>" action="<?= V::e($diaBase) ?>/hermandades/reorder" method="POST" hidden>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <span id="hermReorderInputs<?= (int) $dia['ID_DIA'] ?>"></span>
    </form>
    <span class="small muted" data-estado-orden="hermList<?= (int) $dia['ID_DIA'] ?>" hidden></span>
    <div id="hermList<?= (int) $dia['ID_DIA'] ?>" class="stack" data-hermandades-reorder data-form="hermReorderForm<?= (int) $dia['ID_DIA'] ?>" data-inputs="hermReorderInputs<?= (int) $dia['ID_DIA'] ?>">
<?php foreach ($dia['hermandades'] as $h): ?>
<?php $hermBase = $base . '/hermandad/' . $h['ID_HERMANDAD'];
      // Una hermandad que sale varios días aparece una vez por día: los id del DOM llevan los dos.
      $k = $h['ID_HERMANDAD'] . '-' . $dia['ID_DIA'];
      $idsSuyos = array_column($h['DIAS'], 'ID_DIA');
      $otros = array_values(array_filter($h['DIAS'], static fn(array $d): bool => $d['ID_DIA'] !== $dia['ID_DIA']));
      $cruz = null; $reales = [];
      foreach ($h['pasos'] as $ps) { if ($ps['ES_CRUZ_GUIA']) $cruz = $ps; else $reales[] = $ps; } ?>
    <div class="nomina-herm" data-hermandad-row data-id="<?= (int) $h['ID_HERMANDAD'] ?>" draggable="true">
        <div class="nomina-herm-cab">
            <div class="row" style="align-items:center;gap:0.5rem;flex-wrap:nowrap">
                <span class="drag-handle" title="Arrastrar para reordenar" aria-hidden="true">⠿</span>
<?php if ($h['ES_EXTRA']): ?>
                <strong style="flex:1;min-width:0"><?= V::e($h['NOMBRE']) ?></strong>
<?php else: ?>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/renombrar" method="POST" style="flex:1;min-width:0;display:flex;gap:0.35rem">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <input class="input" type="text" name="NOMBRE" value="<?= V::e($h['NOMBRE']) ?>" style="flex:1;min-width:0">
                    <button class="btn btn-sm btn-neutral" type="submit">Renombrar</button>
                </form>
<?php endif; ?>
            </div>
<?php if ($otros): ?>
            <p class="small muted" style="margin:0.25rem 0 0;padding-left:1.6rem">
                <?= $h['ES_EXTRA'] ? 'Día principal: ' . V::e($h['DIAS'][0]['NOMBRE']) : 'También sale: ' . V::e(implode(', ', array_column($otros, 'NOMBRE'))) ?>
            </p>
<?php endif; ?>
            <div class="row" style="align-items:center;gap:0.5rem;margin-top:0.35rem;padding-left:1.6rem;flex-wrap:wrap">
                <form class="inline-form" action="<?= V::e($hermBase) ?>/mover" method="POST">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <input type="hidden" name="direccion" value="up">
                    <input type="hidden" name="idDia" value="<?= (int) $dia['ID_DIA'] ?>">
                    <button class="btn btn-sm btn-ghost" type="submit" title="Subir">↑</button>
                </form>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/mover" method="POST">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <input type="hidden" name="direccion" value="down">
                    <input type="hidden" name="idDia" value="<?= (int) $dia['ID_DIA'] ?>">
                    <button class="btn btn-sm btn-ghost" type="submit" title="Bajar">↓</button>
                </form>
                <form class="inline-form" action="<?= V::e($hermBase) ?><?= $h['ES_EXTRA'] ? '/dia-extra/' . (int) $dia['ID_DIA'] . '/mover' : '/mover-dia' ?>" method="POST">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <select class="input" name="idDia" onchange="this.form.submit()" title="Mover a otro día">
<?php foreach ($dias as $destino): if ($destino['ID_DIA'] !== $dia['ID_DIA'] && in_array($destino['ID_DIA'], $idsSuyos, true)) continue; ?>
                        <option value="<?= (int) $destino['ID_DIA'] ?>" <?= $destino['ID_DIA'] === $dia['ID_DIA'] ? 'selected' : '' ?>><?= V::e($destino['NOMBRE']) ?></option>
<?php endforeach; ?>
                    </select>
                </form>
<?php if ($h['ES_EXTRA']): ?>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/dia-extra/<?= (int) $dia['ID_DIA'] ?>/borrar" method="POST" onsubmit="return confirm('¿Quitar «<?= V::e($h['NOMBRE']) ?>» del <?= V::e($dia['NOMBRE']) ?>? Sus pasos de este día pasan al <?= V::e($h['DIAS'][0]['NOMBRE']) ?>.');">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <button class="btn btn-sm btn-ghost" type="submit">Quitar este día</button>
                </form>
<?php else: ?>
<?php $libres = array_values(array_filter($dias, static fn(array $d): bool => !in_array($d['ID_DIA'], $idsSuyos, true))); ?>
<?php if ($libres && $variosDias): ?>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/dia-extra/add" method="POST">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <select class="input" name="idDia" onchange="if (this.value) this.form.submit()" title="La hermandad sale también otro día esta Semana Santa">
                        <option value="">+ También sale el…</option>
<?php foreach ($libres as $d): ?>
                        <option value="<?= (int) $d['ID_DIA'] ?>"><?= V::e($d['NOMBRE']) ?></option>
<?php endforeach; ?>
                    </select>
                </form>
<?php endif; ?>
<?php if ($h['PUEDE_BORRAR']): ?>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/borrar" method="POST" onsubmit="return confirm('¿Borrar la hermandad «<?= V::e($h['NOMBRE']) ?>» y sus pasos?');">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                </form>
<?php else: ?>
                <span class="muted small" style="white-space:nowrap" title="No se puede borrar: tiene contratos de acompañamiento asociados">🔒 con contratos</span>
<?php endif; ?>
<?php endif; ?>
            </div>
<?php if (!$h['ES_EXTRA'] && $variosDias): ?>
            <details class="small" style="margin-top:0.35rem;padding-left:1.6rem"<?= $h['historico'] ? ' open' : '' ?>>
                <summary class="muted">Otro día en años anteriores<?= $h['historico'] ? ' (' . count($h['historico']) . ')' : '' ?></summary>
<?php foreach ($h['historico'] as $hh): ?>
                <div class="row" style="align-items:center;gap:0.5rem;margin-top:0.25rem">
                    <span><?= $hh['DESDE'] === $hh['HASTA'] ? (int) $hh['DESDE'] : (int) $hh['DESDE'] . '–' . (int) $hh['HASTA'] ?>: <?= V::e($hh['DIA']) ?></span>
                    <form class="inline-form" action="<?= V::e($base) ?>/historico/<?= (int) $hh['ID_HISTORICO'] ?>/borrar" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                    </form>
                </div>
<?php endforeach; ?>
                <form class="inline-form" action="<?= V::e($hermBase) ?>/historico/add" method="POST" style="display:flex;flex-wrap:wrap;gap:0.35rem;align-items:center;margin-top:0.35rem">
                    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                    <input class="input" type="number" name="DESDE" min="1900" max="2100" placeholder="Desde" required style="width:6rem" aria-label="Desde el año">
                    <input class="input" type="number" name="HASTA" min="1900" max="2100" placeholder="Hasta" required style="width:6rem" aria-label="Hasta el año">
                    <select class="input" name="idDia" required aria-label="Día en esos años">
<?php foreach ($dias as $d): if ($d['ID_DIA'] === $dia['ID_DIA']) continue; ?>
                        <option value="<?= (int) $d['ID_DIA'] ?>"><?= V::e($d['NOMBRE']) ?></option>
<?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-neutral" type="submit">Añadir</button>
                </form>
            </details>
<?php endif; ?>
        </div>

        <div class="nomina-pasos">
<?php if ($h['pasos']): ?>
            <form id="pasosReorderForm<?= $k ?>" action="<?= V::e($hermBase) ?>/pasos/reorder" method="POST" hidden>
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <span id="pasosReorderInputs<?= $k ?>"></span>
            </form>
            <span class="small muted" data-estado-orden="pasosList<?= $k ?>" hidden></span>
            <ul class="stack small" id="pasosList<?= $k ?>" data-pasos-reorder data-form="pasosReorderForm<?= $k ?>" data-inputs="pasosReorderInputs<?= $k ?>">
<?php if ($cruz !== null): ?>
                <li data-paso-row data-cruz-guia>
                    <span class="badge">Cruz de guía</span> <?= V::e($cruz['NOMBRE']) ?>
<?php if ($cruz['PUEDE_BORRAR']): ?>
                    <form class="inline-form" action="<?= V::e($base) ?>/paso/<?= (int) $cruz['ID_PASO'] ?>/borrar" method="POST" onsubmit="return confirm('¿Borrar la cruz de guía?');">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                    </form>
<?php endif; ?>
                </li>
<?php endif; ?>
<?php foreach ($reales as $ps): ?>
<?php $pasoBase = $base . '/paso/' . $ps['ID_PASO']; ?>
                <li data-paso-row data-id="<?= (int) $ps['ID_PASO'] ?>" draggable="true">
                    <span class="drag-handle" title="Arrastrar para reordenar" aria-hidden="true">⠿</span>
                    <form class="inline-form" action="<?= V::e($pasoBase) ?>/renombrar" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input class="input" type="text" name="NOMBRE" value="<?= V::e($ps['NOMBRE']) ?>" style="width:16rem">
                        <button class="btn btn-sm btn-neutral" type="submit">Renombrar</button>
                    </form>
                    <form class="inline-form" action="<?= V::e($pasoBase) ?>/mover" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input type="hidden" name="direccion" value="up">
                        <button class="btn btn-sm btn-ghost" type="submit" title="Subir">↑</button>
                    </form>
                    <form class="inline-form" action="<?= V::e($pasoBase) ?>/mover" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input type="hidden" name="direccion" value="down">
                        <button class="btn btn-sm btn-ghost" type="submit" title="Bajar">↓</button>
                    </form>
<?php if ($otros): ?>
                    <form class="inline-form" action="<?= V::e($pasoBase) ?>/dia" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <select class="input" name="idDia" onchange="this.form.submit()" title="Día en que sale este paso">
<?php foreach ($h['DIAS'] as $d): ?>
                            <option value="<?= (int) $d['ID_DIA'] ?>" <?= $d['ID_DIA'] === $dia['ID_DIA'] ? 'selected' : '' ?>><?= V::e($d['NOMBRE']) ?></option>
<?php endforeach; ?>
                        </select>
                    </form>
<?php endif; ?>
<?php if ($ps['PUEDE_BORRAR']): ?>
                    <form class="inline-form" action="<?= V::e($pasoBase) ?>/borrar" method="POST" onsubmit="return confirm('¿Borrar el paso «<?= V::e($ps['NOMBRE']) ?>»?');">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                    </form>
<?php else: ?>
                    <span class="muted small" style="white-space:nowrap" title="No se puede borrar: tiene contratos de acompañamiento asociados">🔒 con contratos</span>
<?php endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
<?php else: ?>
            <p class="muted small">Sin pasos todavía.</p>
<?php endif; ?>
            <form class="inline-form" action="<?= V::e($base) ?>/paso/add" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="idHermandad" value="<?= (int) $h['ID_HERMANDAD'] ?>">
<?php if ($h['ES_EXTRA']): ?>
                <input type="hidden" name="idDia" value="<?= (int) $dia['ID_DIA'] ?>">
<?php endif; ?>
                <input class="input" type="text" name="NOMBRE" placeholder="Nuevo paso (p. ej. La Sentencia)" style="width:16rem">
<?php if ($cruz === null): ?>
                <label class="muted small"><input type="checkbox" name="esCruzGuia" value="1"> es la cruz de guía</label>
<?php endif; ?>
                <button class="btn btn-sm btn-neutral" type="submit">+ Paso</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="muted small">Sin hermandades todavía.</p>
<?php endif; ?>

    <form class="inline-form" action="<?= V::e($base) ?>/hermandad/add" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="idDia" value="<?= (int) $dia['ID_DIA'] ?>">
        <input class="input" type="text" name="NOMBRE" placeholder="Nombre de la hermandad" style="width:22rem">
        <button class="btn btn-sm btn-neutral" type="submit">+ Hermandad</button>
    </form>
</section>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="muted">Todavía no hay ningún día cargado para <?= V::e($localidad) ?>.</p>
<?php endif; ?>

<section>
    <h2 class="section-title">Añadir día</h2>
    <form class="panel" action="<?= V::e($base) ?>/dia/add" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php if ($esNueva): ?>
        <input type="hidden" name="LOCALIDAD" value="<?= V::e($localidad) ?>">
        <input type="hidden" name="PROVINCIA" value="<?= V::e($provincia) ?>">
<?php endif; ?>
        <div class="field">
            <label class="field-label" for="NOMBRE_DIA">Nombre del día</label>
            <input class="input" id="NOMBRE_DIA" name="NOMBRE" type="text" placeholder="p. ej. Domingo de Ramos" required>
        </div>
        <div><button class="btn btn-neutral" type="submit">Añadir día</button></div>
    </form>
</section>

<script src="/assets/nomina.js?v=<?= (int) @filemtime(PUBLIC_DIR . '/assets/nomina.js') ?>" defer></script>
