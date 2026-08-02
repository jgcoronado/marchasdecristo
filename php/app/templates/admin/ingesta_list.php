<?php use App\View as V; use App\Html as H; use App\IngestaRepo; use App\Auth;
/** @var array $session @var array{estado:string,banda:string,clasificacion:string} $filters
 *  @var int $page @var array{rowsReturned:int,totalRows:int,data:list<array<string,mixed>>} $result
 *  @var list<array{ID_BANDA:int,NOMBRE_BREVE:string,LOCALIDAD:?string,N:int}> $bandas @var array<string,int> $counts @var string $backQs
 *  @var array{N:int,CREATED_AT:string,USUARIO:?string,TITULO:?string}|null $ultimoDescarte
 *  @var array<string,true> $vetos */
$limit = 30;
$estadoLabels = ['pendiente' => 'Pendientes', 'aceptado' => 'Aceptados', 'descartado' => 'Descartados', 'duplicado' => 'Duplicados'];
$claseBadge = ['estreno' => 'badge-estreno', 'novedad' => 'badge-novedad', 'recuperacion' => 'badge-recuperacion'];
$csrf = Auth::csrfToken($session);
$puedeDescartarMultiple = $filters['estado'] === 'pendiente' && $result['data'];
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Ingesta de marchas</h1>
        <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
    </div>

<?php if (isset($_GET['descartado'])): ?>
    <div class="alert alert-info">Candidato descartado.</div>
<?php endif; ?>
<?php if (isset($_GET['descartados'])): ?>
    <div class="alert alert-info"><?= (int) $_GET['descartados'] ?> candidato(s) descartado(s).</div>
<?php endif; ?>
<?php if (isset($_GET['recuperados'])): ?>
    <div class="alert alert-success"><?= (int) $_GET['recuperados'] ?> candidato(s) recuperado(s): vuelven a estar pendientes y se ha levantado su veto.</div>
<?php endif; ?>
<?php if (isset($_GET['aceptado'])): ?>
    <div class="alert alert-success">Marcha añadida correctamente (<a href="/dashboard/marcha/<?= (int) $_GET['aceptado'] ?>" target="_blank">#<?= (int) $_GET['aceptado'] ?> ↗</a>).</div>
<?php endif; ?>
<?php if (isset($_GET['err'])): ?>
    <div class="alert alert-error">Error: <?= V::e((string) $_GET['err']) ?><?= $_GET['err'] === 'NOTHING_TO_UNDO' ? ' — no queda ningún descarte reciente que deshacer.' : '' ?></div>
<?php endif; ?>

<?php if ($ultimoDescarte !== null): ?>
    <?php /* Un solo paso a propósito: es el "me he equivocado al pulsar", no un
             historial. Para revisar descartes antiguos está la pestaña Descartados. */ ?>
    <form class="row" style="align-items:center;gap:0.6rem;flex-wrap:wrap"
          method="POST" action="/dashboard/ingesta/deshacer-descarte">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="ref" value="<?= V::e($backQs) ?>">
        <button class="btn btn-sm btn-ghost" type="submit" title="Devuelve el candidato a pendientes y levanta su veto">
            ↩ Deshacer último descarte<?= $ultimoDescarte['N'] > 1 ? ' (' . (int) $ultimoDescarte['N'] . ')' : '' ?>
        </button>
        <span class="small muted">
<?php if ($ultimoDescarte['TITULO'] !== null): ?>
            Último descarte: «<?= V::e($ultimoDescarte['TITULO']) ?>»
<?php else: ?>
            Último descarte: <?= (int) $ultimoDescarte['N'] ?> candidatos
<?php endif; ?>
            · <?= V::e($ultimoDescarte['CREATED_AT']) ?><?= $ultimoDescarte['USUARIO'] ? ' · ' . V::e($ultimoDescarte['USUARIO']) : '' ?>
        </span>
    </form>
<?php endif; ?>

    <div class="row" style="flex-wrap:wrap;gap:0.5rem">
<?php foreach ($estadoLabels as $key => $label): ?>
        <a class="btn btn-sm <?= $filters['estado'] === $key ? 'btn-neutral' : 'btn-ghost' ?>"
           href="/dashboard/ingesta?estado=<?= $key ?>"><?= $label ?> (<?= $counts[$key] ?? 0 ?>)</a>
<?php endforeach; ?>
    </div>

    <form class="panel" action="/dashboard/ingesta" method="GET">
        <input type="hidden" name="estado" value="<?= V::e($filters['estado']) ?>">
        <div class="row" style="flex-wrap:wrap;gap:0.75rem">
            <div class="field">
                <label class="field-label" for="banda">Banda</label>
                <select class="input" id="banda" name="banda" onchange="this.form.submit()">
                    <option value="">Todas</option>
<?php foreach ($bandas as $b): ?>
                    <option value="<?= (int) $b['ID_BANDA'] ?>" <?= (string) $b['ID_BANDA'] === $filters['banda'] ? 'selected' : '' ?>>
                        <?= V::e($b['NOMBRE_BREVE'] ?? ('Banda #' . $b['ID_BANDA'])) ?><?= $b['LOCALIDAD'] ? ' — ' . V::e($b['LOCALIDAD']) : '' ?> (<?= (int) $b['N'] ?>)
                    </option>
<?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="clasificacion">Clasificación</label>
                <select class="input" id="clasificacion" name="clasificacion" onchange="this.form.submit()">
                    <option value="">Todas</option>
<?php foreach (IngestaRepo::CLASIFICACIONES as $c): ?>
                    <option value="<?= $c ?>" <?= $filters['clasificacion'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
<?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

<?php if ($result['data']): ?>
    <form id="bulkForm" method="POST" action="/dashboard/ingesta/descartar-multiple">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="ref" value="<?= V::e($backQs) ?>">
<?php if ($puedeDescartarMultiple): ?>
        <div class="row" style="align-items:center;gap:0.75rem;margin-bottom:0.5rem">
            <button type="button" id="btnDescartarSeleccionados" class="btn btn-sm btn-danger" disabled>Descartar seleccionados (<span id="numSeleccionados">0</span>)</button>
        </div>
<?php endif; ?>
    <div class="tableList"><table class="table table-zebra table-sm"><tbody>
<?php foreach ($result['data'] as $c): ?>
        <tr>
<?php if ($puedeDescartarMultiple): ?>
            <td style="width:1.5rem">
                <input type="checkbox" class="ingesta-check" name="ids[]" value="<?= (int) $c['ID_CAND'] ?>"
                       data-titulo="<?= V::e($c['P_TITULO'] ?: $c['VIDEO_TITULO']) ?>"
                       data-banda="<?= V::e($c['NOMBRE_BREVE'] ?? ('Banda #' . $c['ID_BANDA'])) ?>">
            </td>
<?php endif; ?>
            <td><span class="badge <?= $claseBadge[$c['CLASIFICACION']] ?? '' ?>"><?= V::e($c['CLASIFICACION']) ?></span></td>
            <td class="small muted"><?= V::e(IngestaRepo::FUENTE_LABEL[$c['FUENTE'] ?? 'youtube'] ?? (string) $c['FUENTE']) ?></td>
            <td>
                <a href="/dashboard/ingesta/<?= (int) $c['ID_CAND'] ?><?= $backQs !== '' ? '?ref=' . rawurlencode($backQs) : '' ?>"><?= V::e($c['P_TITULO'] ?: $c['VIDEO_TITULO']) ?></a>
<?php if ($c['MATCH_MARCHA_ID']): ?>
                <span class="badge badge-warn" title="Posible coincidencia con una marcha ya existente">⚠ posible duplicado</span>
<?php endif; ?>
<?php if (!empty($c['FUENTE_ALBUM'])): ?>
                <span class="small muted">· <?= V::e($c['FUENTE_ALBUM']) ?></span>
<?php endif; ?>
            </td>
            <td class="small"><?= V::e($c['NOMBRE_BREVE'] ?? ('Banda #' . $c['ID_BANDA'])) ?></td>
            <td class="small nums"><?= V::e($c['P_FECHA']) ?></td>
            <td class="small nums"><?= (int) round(((float) $c['CONFIANZA']) * 100) ?>%</td>
            <td class="small">
                <?= V::e($c['ESTADO']) ?><?php if (isset($vetos[($c['FUENTE'] ?? 'youtube') . '|' . $c['VIDEO_ID']])): ?>
                <span class="badge" title="Este origen no volverá a proponerse en futuras pasadas">vetado</span>
<?php endif; ?>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody></table></div>
    </form>
    <?= H::pagination($page, $result['totalRows'], $limit, '/dashboard/ingesta', $filters) ?>
<?php else: ?>
    <p class="muted">No hay candidatos con estos filtros.</p>
<?php endif; ?>

<?php if ($puedeDescartarMultiple): ?>
    <dialog id="dlgDescartar" class="panel">
        <p>Vas a descartar <strong id="dlgNum">0</strong> candidato(s):</p>
        <ul id="dlgLista" class="stack small" style="max-height:40vh;overflow-y:auto"></ul>
        <div class="row" style="justify-content:flex-end;gap:0.5rem">
            <button type="button" id="btnCancelarDescarte" class="btn btn-sm btn-ghost">Cancelar</button>
            <button type="submit" form="bulkForm" class="btn btn-sm btn-danger">Confirmar descarte</button>
        </div>
    </dialog>
    <script>
    (function () {
        var checks = function () { return Array.from(document.querySelectorAll('.ingesta-check')); };
        var btn = document.getElementById('btnDescartarSeleccionados');
        var num = document.getElementById('numSeleccionados');
        var dlg = document.getElementById('dlgDescartar');
        var dlgNum = document.getElementById('dlgNum');
        var dlgLista = document.getElementById('dlgLista');
        var btnCancelar = document.getElementById('btnCancelarDescarte');

        function actualizarContador() {
            var n = checks().filter(function (c) { return c.checked; }).length;
            num.textContent = n;
            btn.disabled = n === 0;
        }

        checks().forEach(function (c) { c.addEventListener('change', actualizarContador); });

        btn.addEventListener('click', function () {
            var seleccionados = checks().filter(function (c) { return c.checked; });
            dlgNum.textContent = seleccionados.length;
            dlgLista.innerHTML = '';
            seleccionados.forEach(function (c) {
                var li = document.createElement('li');
                li.textContent = c.dataset.titulo + ' — ' + c.dataset.banda;
                dlgLista.appendChild(li);
            });
            dlg.showModal();
        });

        btnCancelar.addEventListener('click', function () { dlg.close(); });
    })();
    </script>
<?php endif; ?>
</div>
