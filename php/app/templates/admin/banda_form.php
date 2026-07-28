<?php use App\View as V; use App\Auth; use App\Slug as S; use App\EnlaceRepo; use App\Html as H;
/** @var array $session @var array<string,mixed> $banda @var string $action
 *  @var list<array<string,mixed>> $relaciones @var list<string> $tipos
 *  @var bool $showLinaje @var bool $proposalMode @var array|null $notice @var string|null $error
 *  @var array<string,string> $enlaces */
$csrf = Auth::csrfToken($session);
$showLinaje = $showLinaje ?? true;
$proposalMode = $proposalMode ?? false;
$enlaces = $enlaces ?? [];
$id = (int) $banda['ID_BANDA'];

// Los años se guardan como "1980.0" en datos heredados; se muestran como año limpio.
$val = static function (string $k) use ($banda): string {
    $v = (string) ($banda[$k] ?? '');
    if (in_array($k, ['FECHA_FUND', 'FECHA_EXT'], true)) $v = preg_replace('/\.0+$/', '', $v) ?? $v;
    return V::e($v);
};

$fields = [
    ['NOMBRE_BREVE', 'Nombre breve', 'text'],
    ['NOMBRE_COMPLETO', 'Nombre completo', 'text'],
    ['FECHA_FUND', 'Fecha de fundación (año)', 'number'],
    ['FECHA_EXT', 'Fecha de extinción (año)', 'number'],
    ['DIRECTOR_ACTUAL', 'Director actual', 'text'],
    ['DIR_MUS_ACTUAL', 'Director musical actual', 'text'],
];

$tipoLabel = [
    'renombrado' => 'Renombrado',
    'fusion'     => 'Fusión',
    'division'   => 'División',
    'juvenil'    => 'Juvenil',
];
// Rol de cada punta según el tipo, para describir la arista en lenguaje natural.
$rolOrigen = ['renombrado' => 'formación anterior', 'fusion' => 'se une', 'division' => 'se divide', 'juvenil' => 'banda madre'];
$rolDestino = ['renombrado' => 'formación nueva', 'fusion' => 'formación resultante', 'division' => 'formación nueva', 'juvenil' => 'banda juvenil'];

/** Etiqueta de una punta: enlace a su panel si es otra banda, o "(esta banda)". */
$punta = static function (?int $bid, ?string $nombre, ?string $loc) use ($id): string {
    $txt = $nombre !== null ? V::e($nombre) . ($loc ? ' <span class="muted small">(' . V::e($loc) . ')</span>' : '') : '<span class="muted">#desconocida</span>';
    if ($bid === $id) return '<strong>' . $txt . '</strong>';
    if ($bid === null) return $txt;
    return '<a href="/dashboard/banda/' . $bid . '">' . $txt . '</a>';
};
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Editar banda <?= V::e($banda['NOMBRE_BREVE']) ?> <span class="muted small">#<?= $id ?></span></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('banda', $id, (string) $banda['NOMBRE_BREVE'])) ?>" target="_blank">Ver ↗</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($proposalMode): ?><div class="alert alert-info">Verás una <strong>previsualización</strong> antes de enviar. Tu propuesta la revisará un administrador; no se guarda directamente en la base de datos.</div><?php endif; ?>

    <div class="row tabs" role="tablist" style="gap:0.5rem;margin-bottom:0.75rem">
        <button type="button" class="btn btn-sm btn-neutral tab-btn" data-tab="datos" aria-selected="true">Datos</button>
        <button type="button" class="btn btn-sm btn-ghost tab-btn" data-tab="social" aria-selected="false">Social</button>
    </div>

    <div data-tab-panel="datos">
    <form class="panel" id="bandaForm" action="<?= V::e($action) ?>" method="POST" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach ($fields as [$key, $label, $type]): ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= $label ?></label>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>"<?= $type === 'number' ? ' min="1800" max="2100"' : '' ?> value="<?= $val($key) ?>">
        </div>
<?php if ($key === 'NOMBRE_COMPLETO'): ?>
<?= H::municipioFields((string) ($banda['LOCALIDAD'] ?? ''), $banda['PROVINCIA'] ?? null) ?>
<?php endif; ?>
<?php endforeach; ?>
        <div><button class="btn btn-neutral" type="submit"><?= $proposalMode ? 'Previsualizar propuesta' : 'Guardar cambios' ?></button></div>
    </form>
<?php if ($showLinaje): ?>

    <section>
        <h2 class="section-title">Linaje: predecesoras, sucesoras y juveniles</h2>
<?php if ($relaciones): ?>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Tipo</td><td>Origen → Destino</td><td>Fecha</td><td>Nota</td><td></td></tr></thead>
            <tbody>
<?php foreach ($relaciones as $r):
        $t = (string) $r['TIPO'];
        $oId = $r['ID_ORIGEN'] !== null ? (int) $r['ID_ORIGEN'] : null;
        $dId = $r['ID_DESTINO'] !== null ? (int) $r['ID_DESTINO'] : null;
        $fecha = $r['FECHA_INICIO'] !== null ? (string) $r['FECHA_INICIO'] : '—';
        if ($t === 'juvenil' && $r['FECHA_FIN'] !== null) $fecha .= '–' . $r['FECHA_FIN'];
?>
                <tr>
                    <td><span class="chip"><?= V::e($tipoLabel[$t] ?? $t) ?></span></td>
                    <td>
                        <?= $punta($oId, $r['ORIGEN_NOMBRE'] ?? null, $r['ORIGEN_LOC'] ?? null) ?>
                        <span class="muted small">(<?= V::e($rolOrigen[$t] ?? '') ?>)</span>
                        &rarr;
                        <?= $punta($dId, $r['DESTINO_NOMBRE'] ?? null, $r['DESTINO_LOC'] ?? null) ?>
                        <span class="muted small">(<?= V::e($rolDestino[$t] ?? '') ?>)</span>
                    </td>
                    <td class="small nums"><?= V::e($fecha) ?></td>
                    <td class="small"><?= V::e($r['NOTA'] ?? '') ?></td>
                    <td>
                        <form action="/dashboard/banda/<?= $id ?>/relacion/<?= (int) $r['ID_RELACION'] ?>/borrar" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar esta relación?');">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php else: ?>
        <p class="muted">Esta banda no tiene relaciones de linaje registradas.</p>
<?php endif; ?>
    </section>

    <section>
        <h2 class="section-title">Añadir relación de linaje</h2>
        <form class="panel" action="/dashboard/banda/<?= $id ?>/relacion" method="POST" id="relacionForm">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

            <div class="field">
                <label class="field-label" for="tipo">Tipo de relación</label>
                <select class="input" id="tipo" name="tipo">
<?php foreach ($tipos as $t): ?>
                    <option value="<?= V::e($t) ?>"><?= V::e($tipoLabel[$t] ?? $t) ?></option>
<?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="field-label" for="direccion">La otra banda es…</label>
                <select class="input" id="direccion" name="direccion">
                    <option value="entrante">PREDECESORA / banda madre (otra banda → esta)</option>
                    <option value="saliente">SUCESORA / banda juvenil (esta → otra banda)</option>
                </select>
                <p class="muted small">Linaje (renombrado · fusión · división): la predecesora es la formación anterior y la sucesora la nueva. Juvenil: elige «predecesora» si <strong>esta</strong> banda es la juvenil de la otra, o «sucesora» si la otra es la juvenil de esta.</p>
            </div>

            <div class="field">
                <label class="field-label" for="otraBandaSearch">Otra banda</label>
                <input type="hidden" name="otraBanda" id="otraBandaId" value="">
                <div class="autocomplete">
                    <input class="input" id="otraBandaSearch" type="text" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off">
                    <div id="otraBandaSuggest" class="suggest" hidden></div>
                </div>
                <p class="muted small">Seleccionada: <strong id="otraBandaChosen">(ninguna)</strong></p>
            </div>

            <div class="row">
                <div class="field">
                    <label class="field-label" for="fecha_inicio">Fecha inicio (año)</label>
                    <input class="input" id="fecha_inicio" name="fecha_inicio" type="number" min="1800" max="2100" placeholder="p. ej. 2005">
                </div>
                <div class="field" id="fechaFinWrap">
                    <label class="field-label" for="fecha_fin">Fecha fin (solo juvenil)</label>
                    <input class="input" id="fecha_fin" name="fecha_fin" type="number" min="1800" max="2100" placeholder="vacío = vigente">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="nota">Nota (opcional)</label>
                <input class="input" id="nota" name="nota" type="text" value="">
            </div>

            <div><button class="btn btn-neutral" type="submit">Añadir relación</button></div>
        </form>
    </section>
<?php endif; ?>
    </div>

    <div data-tab-panel="social" hidden>
        <section>
            <h2 class="section-title">Web y enlaces oficiales</h2>
            <div class="field">
                <label class="field-label" for="WEB">Web</label>
                <input class="input" id="WEB" name="WEB" type="text" form="bandaForm" value="<?= $val('WEB') ?>">
                <p class="muted small">Se guarda junto con la pestaña «Datos».</p>
            </div>
        </section>

<?php if ($showLinaje): // enlace_streaming se escribe directo, sin flujo de propuestas: solo admin ?>
        <section>
            <h2 class="section-title">Enlaces de streaming / RRSS musicales</h2>
            <p class="muted small">Vincula el perfil oficial de esta banda en cada servicio. Vacío = sin enlace.</p>
            <form class="panel" action="/dashboard/banda/<?= $id ?>/social" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach (EnlaceRepo::SERVICIOS as $servicio): ?>
                <div class="field">
                    <label class="field-label" for="social_<?= $servicio ?>"><?= V::e(H::STREAMING_LABELS[$servicio] ?? ucfirst($servicio)) ?></label>
                    <input class="input" id="social_<?= $servicio ?>" name="<?= $servicio ?>" type="url" placeholder="https://…" value="<?= V::e($enlaces[$servicio] ?? '') ?>">
                </div>
<?php endforeach; ?>
                <div><button class="btn btn-neutral" type="submit">Guardar enlaces</button></div>
            </form>
        </section>
<?php endif; ?>
    </div>
</div>
<?php if ($showLinaje): ?>
<script src="/assets/banda-relaciones.js" defer></script>
<?php endif; ?>
<script>
(function () {
    var btns = Array.from(document.querySelectorAll('.tab-btn'));
    var panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            btns.forEach(function (b) {
                b.classList.toggle('btn-neutral', b === btn);
                b.classList.toggle('btn-ghost', b !== btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.hidden = p.dataset.tabPanel !== btn.dataset.tab;
            });
        });
    });
})();
</script>
