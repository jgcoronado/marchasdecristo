<?php use App\View as V; use App\Auth; use App\PropuestaRepo;
/**
 * Revisión de una propuesta de OPERACIÓN (ver PropuestaOps): no hay ficha que
 * previsualizar ni campos que retocar; se lee lo que pide, se compara con lo
 * que hay en local cuando reescribe valores, y se acepta o se rechaza.
 *
 * @var array $session @var array<string,mixed> $prop @var string $seccionLabel
 * @var list<array{0:string,1:string,2:string}> $contexto [campo, actual, propuesto]
 * @var list<string> $adjuntos nombres de los adjuntos ('portada')
 * @var array|null $notice
 */
$csrf = Auth::csrfToken($session);
$id = (string) ($prop['id'] ?? '');
$seccion = PropuestaRepo::seccionDe($prop);
$datosJson = json_encode($prop['datos'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Revisar propuesta</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard/propuestas">← Propuestas</a>
        </div>
    </div>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

    <div class="panel">
        <p><span class="chip"><?= V::e($seccionLabel) ?></span></p>
        <p><strong><?= V::e(PropuestaRepo::resumen($prop)) ?></strong></p>
        <p class="muted small">
            Propuesta de <strong><?= V::e($prop['autor'] ?? '') ?></strong> · <?= V::e(date('Y-m-d H:i', (int) ($prop['creado_ts'] ?? 0))) ?>
<?php if ($seccion !== null && $seccion !== '/dashboard'): ?>
            · desde <a href="<?= V::e($seccion) ?>"><?= V::e($seccion) ?></a>
<?php endif; ?>
        </p>
        <p class="muted small">Los nombres son los que veía el editor al enviarla. Al aceptar se aplica tal cual sobre la base de datos local;
            si algo ya no cuadra (un registro borrado, un orden que cambió), la propuesta no se aplica, sigue pendiente y verás el motivo.
            Para matizarla, acéptala y retócala después aquí mismo, o recházala explicando por qué.</p>
    </div>

<?php if ($contexto): ?>
    <section>
        <h2 class="section-title">Qué cambia <span class="muted small">· actual en local frente a lo propuesto</span></h2>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Campo</td><td>Actual</td><td>Propuesto</td></tr></thead>
            <tbody>
<?php foreach ($contexto as [$campo, $actual, $propuesto]): ?>
                <tr>
                    <td class="small"><?= V::e($campo) ?></td>
                    <td class="small"><?= trim($actual) === '' ? '<span class="muted">(vacío)</span>' : V::e($actual) ?></td>
                    <td class="small"><?= trim($propuesto) === '' ? '<span class="muted">(vacío: se borra)</span>' : V::e($propuesto) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
<?php endif; ?>

<?php if (in_array('portada', $adjuntos, true)): ?>
    <section>
        <h2 class="section-title">Portada propuesta</h2>
        <div class="panel">
            <img src="/dashboard/propuesta/<?= V::e($id) ?>/adjunto/portada" alt="Portada propuesta" width="200" height="200" style="max-width:200px;height:auto">
        </div>
    </section>
<?php endif; ?>

    <details class="collapse">
        <summary class="collapse-title">Datos técnicos de la operación <span class="muted small">(<?= V::e((string) ($prop['accion'] ?? '')) ?>)</span></summary>
        <div class="collapse-content"><pre class="mono small" style="white-space:pre-wrap"><?= V::e((string) $datosJson) ?></pre></div>
    </details>

    <form class="panel" action="/dashboard/propuesta/<?= V::e($id) ?>/aceptar" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="row">
            <button class="btn btn-neutral" type="submit">Aceptar y aplicar</button>
        </div>
    </form>

    <form class="panel" action="/dashboard/propuesta/<?= V::e($id) ?>/rechazar" method="POST" onsubmit="return confirm('¿Rechazar esta propuesta? No se aplicará ningún cambio.');">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <div class="field">
            <label class="field-label" for="motivo">Rechazar — motivo (opcional, lo verá el editor)</label>
            <input class="input" id="motivo" name="motivo" type="text" placeholder="p. ej. esa hermandad ya está en otro día">
        </div>
        <div><button class="btn btn-sm btn-ghost" type="submit">Rechazar propuesta</button></div>
    </form>
</div>
