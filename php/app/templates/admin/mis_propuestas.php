<?php use App\View as V; use App\PropuestaOps; use App\PropuestaRepo;
/**
 * Lo que ha propuesto el usuario de la sesión (ver Admin::misPropuestas).
 *
 * @var array $session
 * @var list<array<string,mixed>> $pendientes registros de PropuestaRepo sin resolver
 * @var list<array{id:string,estado:string,resumen:string,seccion:?string,motivo:?string,ts:int}> $resueltas
 */
$entLabel = ['marcha' => 'Marcha', 'banda' => 'Banda', 'autor' => 'Compositor'];
$tipo = static fn(array $p): string => PropuestaRepo::esOperacion($p)
    ? PropuestaOps::seccion((string) ($p['accion'] ?? ''))
    : ($entLabel[$p['entidad'] ?? ''] ?? (string) ($p['entidad'] ?? '')) . (($p['accion'] ?? '') === 'add' ? ' · alta' : ' · edición');
$enlace = static fn(?string $s): string => $s !== null && $s !== '/dashboard' ? ' <a class="small" href="' . V::e($s) . '">ver pantalla</a>' : '';
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Mis propuestas</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

    <p class="muted">Lo que envías desde el panel no se aplica al momento: un administrador lo revisa y, si lo acepta, se verá en la web
        con la siguiente actualización de la base de datos. Mientras tanto, la pantalla donde lo pediste sigue mostrando lo de antes;
        no hace falta que vuelvas a enviarlo.</p>

    <section>
        <h2 class="section-title">Pendientes <span class="muted small">(<?= count($pendientes) ?>)</span></h2>
<?php if (!$pendientes): ?>
        <p class="muted">No tienes propuestas pendientes.</p>
<?php else: ?>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Tipo</td><td>Qué pediste</td><td>Enviada</td></tr></thead>
            <tbody>
<?php foreach ($pendientes as $p): ?>
                <tr>
                    <td><span class="chip"><?= V::e($tipo($p)) ?></span></td>
                    <td><?= V::e(PropuestaRepo::resumen($p)) ?><?= $enlace(PropuestaRepo::seccionDe($p)) ?></td>
                    <td class="small nums"><?= V::e(date('Y-m-d H:i', (int) ($p['creado_ts'] ?? 0))) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php endif; ?>
    </section>

    <section>
        <h2 class="section-title">Revisadas</h2>
<?php if (!$resueltas): ?>
        <p class="muted">Todavía no se ha revisado ninguna de tus propuestas.</p>
<?php else: ?>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Resultado</td><td>Qué pediste</td><td>Revisada</td></tr></thead>
            <tbody>
<?php foreach ($resueltas as $r): ?>
                <tr>
                    <td><span class="chip"><?= $r['estado'] === 'aplicada' ? 'Aceptada' : 'Rechazada' ?></span></td>
                    <td>
                        <?= V::e($r['resumen']) ?><?= $enlace($r['seccion']) ?>
<?php if ($r['motivo'] !== null && $r['motivo'] !== ''): ?>
                        <br><span class="muted small">Motivo: <?= V::e($r['motivo']) ?></span>
<?php endif; ?>
                    </td>
                    <td class="small nums"><?= $r['ts'] > 0 ? V::e(date('Y-m-d H:i', $r['ts'])) : '' ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php endif; ?>
    </section>
</div>
