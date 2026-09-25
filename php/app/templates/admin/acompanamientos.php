<?php use App\View as V; use App\Auth; use App\Slug as S;
/** Acompañamientos de una localidad — alta en bloque por rango de años (N-04,
 *  rehecho 2026-08-29, reemplaza al panel de /dashboard/temporada por año).
 *  @var array $session @var string $slug @var string $localidad @var bool $esNueva
 *  @var list<array{slug:string,nombre:string,titulares:list<array{titular:?string,
 *       rangos:list<array{anioInicio:int,anioFin:int,idBanda:int,banda:string,actual:bool,
 *                          contratos:list<int>,posibleDuplicado:bool}>}>}> $hermandades
 *  @var list<array>|null $nomina  días → hermandades → pasos (con 'rangos') y 'sinPaso'
 *       de NominaRepo::acompanamientosPorNomina(); null si la localidad no tiene nómina
 *       en /dashboard/semana-santa. Con nómina, $hermandades son solo las de fuera.
 *  @var int|null $anio  vista por año (?anio=AAAA): solo los contratos de ese año
 *  @var list<array{ANIO:int,N:int}> $anios  años con acompañamientos, para el selector
 *  @var array|null $notice */
$csrf = Auth::csrfToken($session);
// Los formularios llevan el año en su action para volver a la misma vista tras guardar.
$q = $anio !== null ? '?anio=' . $anio : '';

// Pasos de la nómina para el alta y para "Mover…": un optgroup por hermandad.
$gruposPaso = [];
foreach ($nomina ?? [] as $d) {
    foreach ($d['hermandades'] as $h) {
        if ($h['pasos']) $gruposPaso[] = ['label' => $d['NOMBRE'] . ' · ' . $h['NOMBRE'], 'pasos' => $h['pasos']];
    }
}
$opcionesPaso = static function () use ($gruposPaso): void {
    foreach ($gruposPaso as $g) {
        echo '<optgroup label="' . V::e($g['label']) . '">';
        foreach ($g['pasos'] as $ps) echo '<option value="' . (int) $ps['ID_PASO'] . '">' . V::e($ps['NOMBRE']) . '</option>';
        echo '</optgroup>';
    }
};

// Datalists de ayuda (no obligan a nada, solo reducen el riesgo de escribir la
// misma hermandad/paso de dos formas distintas — HERMANDAD y TITULAR siguen
// siendo texto libre, no hay entidad `hermandad` todavía, ver N-03).
$hermandadesNombres = array_unique(array_column($hermandades, 'nombre'));
sort($hermandadesNombres, SORT_STRING | SORT_FLAG_CASE);
$titularesNombres = [];
foreach ($hermandades as $h) {
    foreach ($h['titulares'] as $t) {
        if ($t['titular'] !== null && $t['titular'] !== 'Sin especificar') {
            $titularesNombres[$t['titular']] = true;
        }
    }
}
$titularesNombres = array_keys($titularesNombres);
sort($titularesNombres, SORT_STRING | SORT_FLAG_CASE);
?>
<div class="crumbs">
    <span><a href="/dashboard">Panel</a> › <a href="/dashboard/acompanamientos">Acompañamientos</a> › <?= V::e($localidad) ?></span>
</div>

<h1>Acompañamientos — <?= V::e($localidad) ?><?= $anio !== null ? ' · ' . $anio : '' ?></h1>

<?php if (!$esNueva): ?>
<form class="row acomp-vista" method="GET" action="/dashboard/acompanamientos/<?= V::e($slug) ?>" style="align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem">
    <span class="muted small">Ver:</span>
<?php if ($anio !== null): ?>
    <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos/<?= V::e($slug) ?>">Por hermandad (todos los años)</a>
    <a class="btn btn-sm btn-ghost" href="?anio=<?= $anio - 1 ?>" title="Año anterior">‹ <?= $anio - 1 ?></a>
<?php else: ?>
    <strong class="small">Por hermandad</strong>
    <span class="muted small">· Por año:</span>
<?php endif; ?>
    <select class="input" name="anio" aria-label="Año" onchange="this.form.submit()" style="width:auto">
        <option value="">— Año —</option>
<?php $hayAnio = false; foreach ($anios as $a): $hayAnio = $hayAnio || (int) $a['ANIO'] === $anio; ?>
        <option value="<?= (int) $a['ANIO'] ?>"<?= (int) $a['ANIO'] === $anio ? ' selected' : '' ?>><?= (int) $a['ANIO'] ?> (<?= (int) $a['N'] ?>)</option>
<?php endforeach; ?>
<?php if ($anio !== null && !$hayAnio): ?>
        <option value="<?= $anio ?>" selected><?= $anio ?> (0)</option>
<?php endif; ?>
    </select>
<?php if ($anio !== null): ?>
    <a class="btn btn-sm btn-ghost" href="?anio=<?= $anio + 1 ?>" title="Año siguiente"><?= $anio + 1 ?> ›</a>
<?php endif; ?>
    <input class="input" type="number" name="anio" min="1900" max="2100" placeholder="Otro año" aria-label="Otro año" style="width:6.5rem" disabled data-otro-anio>
    <button type="button" class="btn btn-sm btn-ghost" data-otro-anio-btn>Otro año…</button>
</form>
<?php endif; ?>
<?php if ($nomina !== null): ?>
<p class="muted">Los días, hermandades y pasos salen de <a href="/dashboard/semana-santa/<?= V::e($slug) ?>">Semana Santa de <?= V::e($localidad) ?></a>. Para corregir un acompañamiento mal colocado, arrástralo desde su asa (<span aria-hidden="true">⠿</span>) a otro paso o usa «Mover…» (si marcas varias casillas, arrastra una de ellas y se mueven todas juntas); al moverlo toma la hermandad y el paso de destino y no se pierde nada. Un alta cubre todo el rango de años con la misma banda de una vez; los años ya cargados se saltan sin duplicar.</p>
<?php else: ?>
<p class="muted">La hermandad y el paso son texto libre: escríbelos igual que ya están cargados (usa el desplegable) para que se agrupen bien en <a href="/acompanamientos/<?= V::e($slug) ?>">la página pública</a>. Un alta cubre todo el rango de años con la misma banda de una vez; los años ya cargados para esa banda+hermandad+paso se saltan sin duplicar.</p>
<?php endif; ?>

<?php if ($anio !== null): ?>
<p class="muted small">Vista del año <strong><?= $anio ?></strong>: solo se muestran los acompañamientos de ese año (una línea por banda). Lo que añadas aquí se guarda solo para <?= $anio ?>; cambiar la banda o borrar una línea afecta solo a este año.</p>
<?php endif; ?>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php /* Con nómina se añade sobre cada paso (fila "Añadir banda…"); aquí solo
         queda el alta por texto libre, plegada, para hermandades fuera de ella. */ ?>
<?php if ($nomina !== null): ?>
<details class="acomp-fuera-alta">
    <summary class="muted">Añadir a una hermandad que no está en la nómina (texto libre)</summary>
<?php else: ?>
<section>
    <h2 class="section-title">Añadir acompañamiento (rango de años)</h2>
<?php endif; ?>
    <form class="panel" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/add<?= $q ?>" method="POST" id="contratoForm">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php if ($esNueva): ?>
        <input type="hidden" name="LOCALIDAD" value="<?= V::e($localidad) ?>">
        <p class="muted small">Localidad nueva: <strong><?= V::e($localidad) ?></strong> (se crea al guardar el primer acompañamiento).</p>
<?php endif; ?>

        <div class="field">
            <label class="field-label" for="contratoBandaSearch">Banda</label>
            <input type="hidden" name="ID_BANDA" id="ID_BANDA" value="">
            <div class="autocomplete">
                <input class="input" id="contratoBandaSearch" type="text" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off">
                <div id="contratoBandaSuggest" class="suggest" hidden></div>
            </div>
            <p class="muted small">Seleccionada: <strong id="contratoBandaChosen">(ninguna)</strong></p>
        </div>

        <div class="field">
            <label class="field-label" for="HERMANDAD">Hermandad</label>
            <input class="input" id="HERMANDAD" name="HERMANDAD" type="text" list="hermandadesList" placeholder="p. ej. Hermandad de la Esperanza de Triana" required>
            <datalist id="hermandadesList">
<?php foreach ($hermandadesNombres as $n): ?>
                <option value="<?= V::e($n) ?>">
<?php endforeach; ?>
            </datalist>
        </div>

        <div class="field">
            <label class="field-label" for="TITULAR">Titular / paso (opcional — solo si la hermandad saca más de un paso de Cristo)</label>
            <input class="input" id="TITULAR" name="TITULAR" type="text" list="titularesList" placeholder="p. ej. Cruz de Guía">
            <datalist id="titularesList">
<?php foreach ($titularesNombres as $n): ?>
                <option value="<?= V::e($n) ?>">
<?php endforeach; ?>
            </datalist>
        </div>

<?php if ($anio !== null): /* vista por año: el alta es solo para ese año */ ?>
        <input type="hidden" name="ANIO_INICIO" value="<?= $anio ?>">
<?php else: ?>
        <div class="adv-grid">
            <div class="field">
                <label class="field-label" for="ANIO_INICIO">Año inicio</label>
                <input class="input" id="ANIO_INICIO" name="ANIO_INICIO" type="number" min="1900" max="2100" required>
            </div>
            <div class="field">
                <label class="field-label" for="ANIO_FIN">Año fin (vacío = solo el año de inicio)</label>
                <input class="input" id="ANIO_FIN" name="ANIO_FIN" type="number" min="1900" max="2100">
            </div>
        </div>
<?php endif; ?>

        <div class="field">
            <label class="field-label" for="FUENTE">Fuente (opcional, uso interno — no se muestra público)</label>
            <input class="input" id="FUENTE" name="FUENTE" type="text" placeholder="URL del anuncio o del hilo del foro">
        </div>

        <div class="field">
            <label class="field-label" for="NOTA">Nota interna (opcional, NO se muestra público)</label>
            <textarea class="input" id="NOTA" name="NOTA" rows="2"></textarea>
        </div>

        <div><button class="btn btn-neutral" type="submit">Añadir</button></div>
    </form>
<?php if ($nomina !== null): ?>
</details>
<?php else: ?>
</section>
<?php endif; ?>

<?php $hayDuplicados = false;
foreach ($hermandades as $h) { foreach ($h['titulares'] as $t) { foreach ($t['rangos'] as $rg) { if ($rg['posibleDuplicado']) $hayDuplicados = true; } } }
foreach ($nomina ?? [] as $d) { foreach ($d['hermandades'] as $h) { foreach ($h['sinPaso'] as $t) { foreach ($t['rangos'] as $rg) { if ($rg['posibleDuplicado']) $hayDuplicados = true; } } } } ?>
<?php /* data-contratos-table en el contenedor común: admin.js engancha ahí la
         edición de banda de todas las filas, estén en la nómina o fuera. */ ?>
<div data-contratos-table>
<?php if ($nomina !== null || $hermandades): ?>
    <div class="row acomp-barra" style="align-items:center;gap:0.75rem;margin-bottom:0.5rem">
<?php if ($hayDuplicados): ?>
        <button type="button" id="btnMarcarDuplicados" class="btn btn-sm btn-ghost">Marcar posibles duplicados</button>
<?php endif; ?>
<?php if ($nomina !== null): ?>
        <button type="button" id="btnMoverSeleccionados" class="btn btn-sm btn-neutral" disabled>Mover seleccionados a un paso…</button>
<?php endif; ?>
        <button type="button" id="btnBorrarSeleccionados" class="btn btn-sm btn-danger" disabled>Eliminar seleccionados (<span id="numSeleccionados">0</span>)</button>
    </div>
<?php endif; ?>

<?php if ($nomina !== null): ?>
<?php foreach ($nomina as $d): ?>
<section class="panel">
    <h2 class="section-title" style="margin-top:0"><?= V::e($d['NOMBRE']) ?></h2>
<?php if (!$d['hermandades']): ?>
    <p class="muted small">Sin hermandades en la nómina.</p>
<?php endif; ?>
<?php foreach ($d['hermandades'] as $h): ?>
    <div class="nomina-herm">
        <div class="nomina-herm-cab">
            <strong><?= V::e($h['NOMBRE']) ?></strong>
            <form class="inline-form" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/hermandad/<?= (int) $h['ID_HERMANDAD'] ?>/ida-vuelta<?= $q ?>" method="POST" style="display:block;margin-top:0.35rem">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="activo" value="<?= $h['IDA_VUELTA'] ? '0' : '1' ?>">
                <label class="small muted" title="Marca si a la ida y a la vuelta van bandas distintas detrás del mismo paso"><input type="checkbox"<?= $h['IDA_VUELTA'] ? ' checked' : '' ?> onchange="this.form.submit()"> Ida / vuelta</label>
            </form>
        </div>
        <div class="nomina-pasos">
<?php if (!$h['pasos']): ?>
            <p class="muted small">Sin pasos en la nómina — añádelos en <a href="/dashboard/semana-santa/<?= V::e($slug) ?>">Semana Santa</a>.</p>
<?php endif; ?>
<?php foreach ($h['pasos'] as $ps): ?>
            <div class="acomp-paso">
                <div class="acomp-paso-cab"><?= $ps['ES_CRUZ_GUIA'] ? '<span class="badge">Cruz de guía</span> ' : '' ?><?= V::e($ps['NOMBRE']) ?></div>
                <table class="table table-sm acomp-tabla"><tbody data-paso-drop data-id-paso="<?= (int) $ps['ID_PASO'] ?>">
<?php foreach ($ps['rangos'] as $rg): ?>
<?= V::capture('admin/_acomp_rango', ['rg' => $rg, 'etiqueta' => $h['NOMBRE'] . ' — ' . $ps['NOMBRE'], 'slug' => $slug, 'q' => $q, 'csrf' => $csrf, 'idaVuelta' => $h['IDA_VUELTA']]) ?>
<?php endforeach; ?>
<?php if (!$ps['rangos']): ?>
                    <tr class="acomp-vacio"><td colspan="4" class="muted small">Sin acompañamientos — arrastra aquí para asignar.</td></tr>
<?php endif; ?>
<?php /* Alta rápida sobre este paso. El predictivo de banda es el de la
         edición de banda de admin.js (data-banda-edit-*, delegado por fila). */ ?>
                    <tr class="acomp-alta">
                        <td colspan="4">
                            <form class="inline-form" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/add<?= $q ?>" method="POST" data-alta-rapida>
                                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                                <input type="hidden" name="ID_PASO" value="<?= (int) $ps['ID_PASO'] ?>">
                                <input type="hidden" name="ID_BANDA" value="" data-banda-edit-hidden>
                                <div class="autocomplete">
                                    <input class="input" type="text" placeholder="Añadir banda…" autocomplete="off" data-banda-edit-search aria-label="Banda" style="width:17rem">
                                    <div class="suggest" data-banda-edit-suggest hidden></div>
                                </div>
<?php if ($anio !== null): ?>
                                <input type="hidden" name="ANIO_INICIO" value="<?= $anio ?>">
<?php else: ?>
                                <input class="input" type="number" name="ANIO_INICIO" min="1900" max="2100" placeholder="Desde" required style="width:5.5rem" aria-label="Año inicio">
                                <input class="input" type="number" name="ANIO_FIN" min="1900" max="2100" placeholder="Hasta" style="width:5.5rem" aria-label="Año fin (vacío = solo el de inicio)">
<?php endif; ?>
<?php if ($h['IDA_VUELTA']): ?>
                                <select class="input acomp-tramo" name="TRAMO" aria-label="Ida o vuelta">
                                    <option value="">Ida / vuelta…</option>
                                    <option value="ida">Ida</option>
                                    <option value="vuelta">Vuelta</option>
                                </select>
<?php endif; ?>
                                <button class="btn btn-sm btn-neutral" type="submit">+ Añadir</button>
                            </form>
                        </td>
                    </tr>
                </tbody></table>
            </div>
<?php endforeach; ?>
<?php foreach ($h['sinPaso'] as $t): ?>
            <div class="acomp-paso acomp-sin-paso">
                <div class="acomp-paso-cab"><span class="badge badge-warn">Sin paso asignado</span> <?= V::e($t['titular']) ?></div>
                <table class="table table-sm acomp-tabla"><tbody>
<?php foreach ($t['rangos'] as $rg): ?>
<?= V::capture('admin/_acomp_rango', ['rg' => $rg, 'etiqueta' => $h['NOMBRE'] . ' — ' . $t['titular'], 'slug' => $slug, 'q' => $q, 'csrf' => $csrf, 'idaVuelta' => $h['IDA_VUELTA']]) ?>
<?php endforeach; ?>
                </tbody></table>
            </div>
<?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>

<section>
    <h2 class="section-title"><?= $nomina !== null ? 'Hermandades fuera de la nómina' : 'Histórico cargado' ?></h2>
<?php if ($nomina !== null && $hermandades): ?>
    <p class="muted small">Contratos de hermandades que no están (o no con este nombre) en la nómina de Semana Santa. Usa «Mover…» para colocarlos en su paso.</p>
<?php endif; ?>
<?php if ($hermandades): ?>
    <div class="tableList"><table class="table table-zebra table-sm">
        <thead class="thead-neutral"><tr><td style="width:1.5rem"></td><td>Hermandad / paso</td><td>Años</td><td>Banda</td><td></td></tr></thead>
        <tbody>
<?php foreach ($hermandades as $h): ?>
<?php foreach ($h['titulares'] as $t): ?>
<?php foreach ($t['rangos'] as $i => $rg): ?>
<?php $idsCsv = implode(',', $rg['contratos']);
      $anios = $rg['anioInicio'] === $rg['anioFin'] ? (string) $rg['anioInicio'] : ($rg['anioInicio'] . '–' . $rg['anioFin']); ?>
            <tr data-ids="<?= V::e($idsCsv) ?>">
                <td>
                    <input type="checkbox" class="acomp-check" data-ids="<?= V::e($idsCsv) ?>"
                           data-dup="<?= $rg['posibleDuplicado'] ? '1' : '0' ?>"
                           data-etiqueta="<?= V::e($h['nombre'] . ($t['titular'] !== null ? ' — ' . $t['titular'] : '') . ' (' . $anios . ', ' . $rg['banda'] . ')') ?>">
                </td>
                <td>
<?php if ($i === 0): ?>
                    <strong><?= V::e($h['nombre']) ?></strong><?= $t['titular'] !== null ? '<br><span class="muted small">' . V::e($t['titular']) . '</span>' : '' ?>
<?php endif; ?>
<?php if ($rg['posibleDuplicado']): ?>
                    <span class="badge badge-warn" title="Otro paso de esta hermandad tiene la misma banda en años que se solapan">⚠ posible duplicado</span>
<?php endif; ?>
                </td>
                <td style="white-space:nowrap"><?= V::capture('admin/_acomp_anios', ['rg' => $rg, 'slug' => $slug, 'q' => $q, 'csrf' => $csrf]) ?></td>
                <td>
                    <span data-banda-display>
                        <a href="<?= V::e(S::buildDetailPath('banda', $rg['idBanda'], $rg['banda'])) ?>"><?= V::e($rg['banda']) ?></a>
                        <button type="button" class="btn btn-sm btn-ghost" data-editar-banda>Editar</button>
                    </span>
                    <form action="/dashboard/acompanamientos/<?= V::e($slug) ?>/banda-rango<?= $q ?>" method="POST" class="inline-form" data-banda-edit-form hidden>
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
                <td style="white-space:nowrap">
<?php if ($nomina !== null): ?>
                    <button type="button" class="btn btn-sm btn-ghost" data-mover-paso data-etiqueta="<?= V::e($h['nombre'] . ($t['titular'] !== null ? ' — ' . $t['titular'] : '') . ' (' . $anios . ', ' . $rg['banda'] . ')') ?>">Mover…</button>
<?php endif; ?>
                    <form action="/dashboard/acompanamientos/<?= V::e($slug) ?>/borrar-rango<?= $q ?>" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar <?= count($rg['contratos']) ?> acompañamiento(s) (<?= $anios ?>)?');">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input type="hidden" name="ids" value="<?= V::e($idsCsv) ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php elseif ($nomina !== null): ?>
    <p class="muted small">Ninguna: todos los contratos de <?= V::e($localidad) ?> están en hermandades de la nómina.</p>
<?php else: ?>
    <p class="muted">Todavía no hay acompañamientos registrados para <?= V::e($localidad) ?>.</p>
<?php endif; ?>
</section>
</div>

<?php if ($nomina !== null): ?>
<dialog id="dlgMoverPaso" class="panel">
    <form id="moverPasoForm" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/mover-paso<?= $q ?>" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="ids" id="moverPasoIds">
        <p>Mover a otro paso:</p>
        <ul id="moverPasoLista" class="stack small" style="max-height:30vh;overflow-y:auto"></ul>
        <div class="field">
            <label class="field-label" for="moverPasoDestino">Paso de destino</label>
            <select class="input" id="moverPasoDestino" name="ID_PASO" required>
                <option value="">— Elige paso —</option>
                <?php $opcionesPaso(); ?>
            </select>
        </div>
        <p class="muted small">Toma la hermandad y el nombre del paso de destino. No se borra nada; si el destino ya tiene esa banda ese año, no se mueve.</p>
        <div class="row" style="justify-content:flex-end;gap:0.5rem">
            <button type="button" id="btnCancelarMover" class="btn btn-sm btn-ghost">Cancelar</button>
            <button type="submit" class="btn btn-sm btn-neutral">Mover</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<form id="bulkBorrarForm" action="/dashboard/acompanamientos/<?= V::e($slug) ?>/borrar-rango<?= $q ?>" method="POST" hidden>
    <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
    <input type="hidden" name="ids" id="bulkBorrarIds">
</form>
<dialog id="dlgBorrarSeleccionados" class="panel">
    <p>Vas a eliminar <strong id="dlgNum">0</strong> acompañamiento(s):</p>
    <ul id="dlgLista" class="stack small" style="max-height:40vh;overflow-y:auto"></ul>
    <div class="row" style="justify-content:flex-end;gap:0.5rem">
        <button type="button" id="btnCancelarBorrado" class="btn btn-sm btn-ghost">Cancelar</button>
        <button type="submit" form="bulkBorrarForm" class="btn btn-sm btn-danger">Confirmar eliminación</button>
    </div>
</dialog>
<script>
(function () {
    var checks = function () { return Array.from(document.querySelectorAll('.acomp-check')); };
    var btnMarcar = document.getElementById('btnMarcarDuplicados');
    var btnBorrar = document.getElementById('btnBorrarSeleccionados');
    var num = document.getElementById('numSeleccionados');
    var dlg = document.getElementById('dlgBorrarSeleccionados');
    var dlgNum = document.getElementById('dlgNum');
    var dlgLista = document.getElementById('dlgLista');
    var btnCancelar = document.getElementById('btnCancelarBorrado');

    function actualizarContador() {
        var n = checks().filter(function (c) { return c.checked; }).length;
        num.textContent = n;
        btnBorrar.disabled = n === 0;
        var btnMover = document.getElementById("btnMoverSeleccionados");
        if (btnMover) btnMover.disabled = n === 0;
    }

    checks().forEach(function (c) { c.addEventListener('change', actualizarContador); });

    if (btnMarcar) {
        btnMarcar.addEventListener('click', function () {
            checks().forEach(function (c) { if (c.dataset.dup === '1') c.checked = true; });
            actualizarContador();
        });
    }

    btnBorrar.addEventListener('click', function () {
        var seleccionados = checks().filter(function (c) { return c.checked; });
        dlgNum.textContent = seleccionados.length;
        dlgLista.innerHTML = '';
        seleccionados.forEach(function (c) {
            var li = document.createElement('li');
            li.textContent = c.dataset.etiqueta;
            dlgLista.appendChild(li);
        });
        dlg.showModal();
    });

    btnCancelar.addEventListener('click', function () { dlg.close(); });

    document.getElementById('bulkBorrarForm').addEventListener('submit', function () {
        var ids = checks().filter(function (c) { return c.checked; })
            .map(function (c) { return c.dataset.ids; }).join(',');
        document.getElementById('bulkBorrarIds').value = ids;
    });

    // Editar años de una línea (ver _acomp_anios.php): alterna el botón con
    // el formulario y avisa si el rango nuevo deja años fuera (se borran).
    document.addEventListener('click', function (e) {
        var td;
        if (e.target.closest('[data-editar-anios]')) {
            td = e.target.closest('td');
            td.querySelector('[data-anios-display]').hidden = true;
            td.querySelector('[data-anios-form]').hidden = false;
            td.querySelector('[name="ANIO_INICIO"]').focus();
        } else if (e.target.closest('[data-anios-cancelar]')) {
            td = e.target.closest('td');
            td.querySelector('[data-anios-form]').hidden = true;
            td.querySelector('[data-anios-display]').hidden = false;
        }
    });
    // «Otro año…»: año sin acompañamientos todavía (p. ej. el próximo). El
    // número va deshabilitado hasta pulsar el botón para no chocar con el select.
    var btnOtro = document.querySelector('[data-otro-anio-btn]');
    if (btnOtro) {
        btnOtro.addEventListener('click', function () {
            var f = btnOtro.form, n = f.querySelector('[data-otro-anio]');
            if (n.disabled) {
                n.disabled = false; f.querySelector('select[name="anio"]').disabled = true;
                btnOtro.textContent = 'Ver'; n.focus();
            } else if (n.value) {
                f.submit();
            }
        });
    }
    // Alta rápida sobre un paso: la banda tiene que salir del predictivo.
    document.addEventListener('submit', function (e) {
        var f = e.target.closest('[data-alta-rapida]');
        if (f && !f.ID_BANDA.value) {
            e.preventDefault();
            alert('Elige la banda de la lista del predictivo (escribe al menos 3 letras).');
            f.querySelector('[data-banda-edit-search]').focus();
        }
    });
    document.addEventListener('submit', function (e) {
        var f = e.target.closest('[data-anios-form]');
        if (!f) return;
        var ini = +f.ANIO_INICIO.value, fin = +f.ANIO_FIN.value;
        var antesIni = +f.getAttribute('data-inicio'), antesFin = +f.getAttribute('data-fin');
        if ((ini > antesIni || fin < antesFin)
            && !confirm('El rango nuevo deja fuera años que ya estaban cargados (' + antesIni + '–' + antesFin + '): esos años se eliminarán. ¿Continuar?')) {
            e.preventDefault();
        }
    });
})();
</script>

<script src="/assets/admin.js" defer></script>
<?php if ($nomina !== null): ?>
<script src="/assets/nomina.js?v=<?= (int) @filemtime(PUBLIC_DIR . "/assets/nomina.js") ?>" defer></script>
<?php endif; ?>
