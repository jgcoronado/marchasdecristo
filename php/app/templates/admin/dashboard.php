<?php use App\View as V; use App\Auth; use App\Entorno; use App\Roles;
/** @var string $q @var string $qb @var string $qd @var list<array<string,mixed>> $marchas @var list<array<string,mixed>> $autores @var list<array<string,mixed>> $bandas @var list<array<string,mixed>> $discos @var array $session @var array|null $notice @var int $pendientes */
$csrf = Auth::csrfToken($session);
$rol = $session['rol'] ?? Roles::EDITOR;
$isAdmin = Roles::isAdmin($rol);

// Estado inicial del widget: qué pestaña está activa y qué valor tiene el input
if ($qb !== '') {
    $tipoInit = 'banda';
    $qInit    = $qb;
    $phInit   = 'Nombre de la banda…';
} elseif ($qd !== '') {
    $tipoInit = 'disco';
    $qInit    = $qd;
    $phInit   = 'Nombre del disco o ID…';
} else {
    $tipoInit = 'marcha';
    $qInit    = $q;
    $phInit   = 'Título o ID…';
}
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Panel de administración</h1>
        <div class="row">
            <span class="muted small">Sesión: <strong><?= V::e($session['user'] ?? '') ?></strong> · <?= V::e(Roles::label($rol)) ?></span>
<?php if (Roles::has($rol, 'marcha.add')): ?>
            <a class="btn btn-sm" href="/dashboard/marcha/add">+ Marcha</a>
<?php endif; ?>
<?php if (Roles::has($rol, 'autor.add')): ?>
            <a class="btn btn-sm" href="/dashboard/autor/add">+ Compositor</a>
<?php endif; ?>
<?php if (Roles::has($rol, 'banda.add')): ?>
            <a class="btn btn-sm" href="/dashboard/banda/add">+ Banda</a>
<?php endif; ?>
<?php if ($isAdmin): ?>
            <?php /* Solo administrador: el disco no pasa por la cola de propuestas
                     y su alta escribe la portada en el docroot. */ ?>
            <a class="btn btn-sm" href="/dashboard/disco/add">+ Disco</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/propuestas">Propuestas<?= $pendientes > 0 ? ' <span class="chip">' . (int) $pendientes . '</span>' : '' ?></a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/usuarios">Usuarios</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/ingesta">Ingesta marchas</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/enlaces">Enlaces streaming</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/dedicatorias">Dedicatorias</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/temporada/<?= (int) date('Y') ?>">Temporada</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/estilos">Estilos CCTT/AM</a>
<?php endif; ?>
            <form action="/logout" method="POST" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <button class="btn btn-sm btn-ghost" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

<?php if ($notice): ?>
    <div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div>
<?php endif; ?>
<?php if (!$isAdmin): ?>
    <div class="alert alert-info">Trabajas como <strong>Editor</strong>. Tus altas y cambios se envían como <strong>propuestas</strong>; un administrador las revisa antes de aplicarlas.</div>
<?php endif; ?>
<?php if ($isAdmin && !Entorno::permiteEscrituraDirecta()): ?>
    <?php /* Qué se puede hacer de verdad aquí: la cinta de peligro (layout.php)
             avisa del riesgo, esto concreta el alcance. Las secciones que no
             tienen propuesta escriben directo y chocan con el fail-safe de
             Db::assertWritable(): mejor decirlo antes que tras rellenar un
             formulario. Ver Admin::proposalMode() y docs/entornos.md. */ ?>
    <div class="alert alert-error">
        Entorno <strong><?= V::e(Entorno::nombre()) ?></strong>: aquí <strong>nadie escribe en la base de datos</strong>, tampoco tú.
        Las altas y ediciones de <strong>marcha, compositor y banda</strong> se guardan como <strong>propuestas</strong>
        y se aplican al revisarlas en local. El resto del panel (discos, dedicatorias, estilos, ingesta, enlaces,
        usuarios y temporada) está en <strong>solo lectura</strong>: si intentas guardar, responderá con un error 503.
    </div>
<?php endif; ?>

    <div class="panel" data-dash-search>
        <div class="dash-tabs" role="tablist" aria-label="Tipo de búsqueda">
            <a href="/dashboard" class="btn btn-sm<?= $tipoInit === 'marcha' ? ' is-on' : '' ?>"
               data-dash-tab="marcha" data-dash-ph="Título o ID…"
               role="tab" aria-selected="<?= $tipoInit === 'marcha' ? 'true' : 'false' ?>">Marchas</a>
            <a href="/dashboard" class="btn btn-sm<?= $tipoInit === 'autor' ? ' is-on' : '' ?>"
               data-dash-tab="autor" data-dash-ph="Nombre del compositor…"
               role="tab" aria-selected="<?= $tipoInit === 'autor' ? 'true' : 'false' ?>">Compositores</a>
            <a href="/dashboard" class="btn btn-sm<?= $tipoInit === 'banda' ? ' is-on' : '' ?>"
               data-dash-tab="banda" data-dash-ph="Nombre de la banda…"
               role="tab" aria-selected="<?= $tipoInit === 'banda' ? 'true' : 'false' ?>">Bandas</a>
<?php if ($isAdmin): ?>
            <a href="/dashboard" class="btn btn-sm<?= $tipoInit === 'disco' ? ' is-on' : '' ?>"
               data-dash-tab="disco" data-dash-ph="Nombre del disco o ID…"
               role="tab" aria-selected="<?= $tipoInit === 'disco' ? 'true' : 'false' ?>">Discos</a>
<?php endif; ?>
        </div>
        <div class="autocomplete">
            <input type="hidden" data-dash-tipo value="<?= V::e($tipoInit) ?>">
            <input class="input" type="text" data-dash-input
                   value="<?= V::e($qInit) ?>"
                   placeholder="<?= V::e($phInit) ?>"
                   autocomplete="off" aria-autocomplete="list" aria-expanded="false" autofocus>
            <div class="suggest" data-dash-suggest hidden role="listbox"></div>
        </div>
    </div>

<?php if ($qd !== ''): ?>
    <section>
        <h2 class="section-title">Discos <span class="muted small">· datos, portada y contenido</span></h2>
<?php if ($discos): ?>
        <div class="tableList"><table class="table table-zebra table-sm"><tbody>
<?php foreach ($discos as $d): ?>
            <tr>
                <td><a href="/dashboard/disco/<?= (int) $d['ID_DISCO'] ?>">#<?= (int) $d['ID_DISCO'] ?> · <?= V::e($d['NOMBRE_CD']) ?></a></td>
                <td class="small muted"><?= V::e((string) ($d['FECHA_CD'] ?? '')) ?></td>
                <td class="small muted"><?= (int) $d['PISTAS'] ?> pistas</td>
            </tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php else: ?>
        <p class="muted">Sin resultados.</p>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($qb !== ''): ?>
    <section>
        <h2 class="section-title">Bandas <span class="muted small">· edición y linaje (predecesoras, sucesoras, juveniles)</span></h2>
<?php if ($bandas): ?>
        <div class="tableList"><table class="table table-zebra table-sm"><tbody>
<?php foreach ($bandas as $b): ?>
            <tr>
                <td><a href="/dashboard/banda/<?= (int) $b['ID_BANDA'] ?>">#<?= (int) $b['ID_BANDA'] ?> · <?= V::e($b['NOMBRE_BREVE']) ?></a></td>
                <td class="small muted"><?= V::e($b['LOCALIDAD'] ?? '') ?></td>
            </tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php else: ?>
        <p class="muted">Sin resultados.</p>
<?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($q !== ''): ?>
    <section>
        <h2 class="section-title">Marchas</h2>
<?php if ($marchas): ?>
        <div class="tableList"><table class="table table-zebra table-sm"><tbody>
<?php foreach ($marchas as $m): ?>
            <tr>
                <td><a href="/dashboard/marcha/<?= (int) $m['ID_MARCHA'] ?>">#<?= (int) $m['ID_MARCHA'] ?> · <?= V::e($m['TITULO']) ?></a></td>
                <td class="small nums"><?= V::e($m['FECHA']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php else: ?>
        <p class="muted">Sin resultados.</p>
<?php endif; ?>
    </section>

    <section>
        <h2 class="section-title">Compositores</h2>
<?php if ($autores): ?>
        <div class="tableList"><table class="table table-zebra table-sm"><tbody>
<?php foreach ($autores as $a): ?>
            <tr>
                <td><a href="/dashboard/autor/<?= (int) $a['ID_AUTOR'] ?>">#<?= (int) $a['ID_AUTOR'] ?> · <?= V::e($a['NOMBRE_COMPLETO']) ?></a></td>
                <td class="small nums"><?= V::e($a['MARCHAS']) ?></td>
            </tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php else: ?>
        <p class="muted">Sin resultados.</p>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
<script src="/assets/dashboard-search.js" defer></script>
