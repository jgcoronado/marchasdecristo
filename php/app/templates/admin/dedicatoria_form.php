<?php use App\View as V; use App\Slug as S; use App\Auth; use App\Html as H;
/** @var array{ID_DEDIC:int,NOMBRE:string,LOCALIDAD:string,PROVINCIA:?string,variantes:list<array<string,mixed>>} $dedic
 *  @var array|null $notice @var string|null $error @var array $session */
$csrf = Auth::csrfToken($session);
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
$did = (int) $dedic['ID_DEDIC'];
$loc = trim((string) $dedic['LOCALIDAD']);
$label = $dedic['NOMBRE'] . ($loc !== '' ? ' ' . $loc : '');
$base = "/dashboard/dedicatoria/$did";
$nVar = count($dedic['variantes']);
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Dedicatoria #<?= $did ?></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard/dedicatorias">← Lista</a>
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('dedicatoria', $did, $label)) ?>" target="_blank" rel="noopener">Ver hub público ↗</a>
        </div>
    </div>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= V::e($error) ?></div><?php endif; ?>

    <form class="panel" action="<?= $base ?>" method="POST" <?= H::municipioFormAttrs(true, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <h2 class="section-title">Datos de la advocación canónica</h2>
        <div class="form-grid">
            <div class="field">
                <label class="field-label" for="NOMBRE">Nombre (advocación)</label>
                <input class="input" id="NOMBRE" name="NOMBRE" type="text" value="<?= V::e($dedic['NOMBRE']) ?>" required>
            </div>
<?= H::municipioFields($dedic['LOCALIDAD'], $dedic['PROVINCIA'] ?? null) ?>
        </div>
        <div class="field">
            <label class="chip">
                <input type="checkbox" name="PERSONAL" value="1"<?= (int) $dedic['PERSONAL'] === 1 ? ' checked' : '' ?>>
                Dedicatoria personal (particular a una persona o grupo) — ocultar del índice público y del sitemap
            </label>
        </div>
        <div class="search-actions">
            <button class="btn btn-sm btn-neutral" type="submit">Guardar datos</button>
        </div>
    </form>

<?php if ($nVar > 1): ?>
    <section>
        <h2 class="section-title">Unificar variantes <span class="muted small">· dejar una sola grafía</span></h2>
        <p class="muted">Reescribe el texto de <span class="mono">DEDICATORIA</span> (y localidad) de <strong>todas</strong> las marchas del grupo a la variante elegida. Es limpieza definitiva del texto libre; las demás variantes desaparecen.</p>
        <form class="panel" action="<?= $base ?>/unificar" method="POST" onsubmit="return confirm('¿Unificar las <?= $nVar ?> variantes en la elegida? Se reescribirá el texto de las marchas afectadas.');">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <div class="field">
                <label class="field-label" for="objetivo">Conservar la variante</label>
                <div class="row">
                    <select class="select" id="objetivo" name="objetivo">
<?php foreach ($dedic['variantes'] as $i => $v):
        $vl = trim((string) $v['LOCALIDAD']); ?>
                        <option value="<?= (int) $i ?>"><?= V::e($v['VARIANTE']) ?><?= $vl !== '' ? ' — ' . V::e($vl) : '' ?> (<?= $num($v['N_MAR']) ?> marchas)</option>
<?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-neutral" type="submit">Unificar</button>
                </div>
            </div>
        </form>
    </section>
<?php endif; ?>

    <section>
        <h2 class="section-title">Variantes agrupadas <span class="muted small">· <?= $num($nVar) ?> · el par (variante, localidad) tal cual aparece en las marchas</span></h2>
        <p class="muted"><strong>Mover</strong> reasigna una variante a otra canónica (indica su <span class="mono">#ID</span>, que ves en la lista). <strong>Separar</strong> la extrae a una canónica nueva. Si esta canónica se queda sin variantes, se elimina sola.</p>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Variante</td><td>Localidad</td><td>Marchas</td><td>Mover a #</td><td>Separar</td></tr></thead>
            <tbody>
<?php foreach ($dedic['variantes'] as $v):
        $var = (string) $v['VARIANTE'];
        $vloc = (string) $v['LOCALIDAD']; ?>
            <tr>
                <td><?= V::e($var) ?></td>
                <td class="small muted"><?= V::e($vloc !== '' ? $vloc : '—') ?></td>
                <td class="small nums"><?= $num($v['N_MAR']) ?></td>
                <td>
                    <form class="inline-form row" action="<?= $base ?>/alias/mover" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input type="hidden" name="variante" value="<?= V::e($var) ?>">
                        <input type="hidden" name="localidad" value="<?= V::e($vloc) ?>">
                        <input class="input" style="width:5.5rem" name="destino" type="number" min="1" placeholder="ID" aria-label="ID canónica destino" required>
                        <button class="btn btn-sm" type="submit">Mover</button>
                    </form>
                </td>
                <td>
                    <form class="inline-form" action="<?= $base ?>/alias/separar" method="POST" onsubmit="return confirm('¿Separar «<?= V::e(addslashes($var)) ?>» en una canónica nueva?');">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <input type="hidden" name="variante" value="<?= V::e($var) ?>">
                        <input type="hidden" name="localidad" value="<?= V::e($vloc) ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">Separar</button>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</div>
