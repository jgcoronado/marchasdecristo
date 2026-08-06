<?php use App\View as V; use App\Auth; use App\Entorno; use App\Roles;
/** @var string $q @var string $qb @var string $qd @var list<array<string,mixed>> $marchas @var list<array<string,mixed>> $autores @var list<array<string,mixed>> $bandas @var list<array<string,mixed>> $discos @var array $session @var array|null $notice @var int $pendientes */
$csrf = Auth::csrfToken($session);
$rol = $session['rol'] ?? Roles::EDITOR;
$isAdmin = Roles::isAdmin($rol);
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

    <div class="panel">
        <div class="field">
            <label class="field-label" for="q">Buscar marcha</label>
            <div data-dash-box="marcha" class="autocomplete dash-ac">
                <input class="input" id="q" type="text" placeholder="Título o ID…"
                       autocomplete="off" aria-autocomplete="list" aria-expanded="false" autofocus
                       data-dash-input>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="field">
            <label class="field-label" for="qa">Buscar compositor</label>
            <div data-dash-box="autor" class="autocomplete dash-ac">
                <input class="input" id="qa" type="text" placeholder="Nombre o ID…"
                       autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                       data-dash-input>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="field">
            <label class="field-label" for="qb">Buscar banda <span class="muted small">· para editar sus datos y su linaje</span></label>
            <div data-dash-box="banda" class="autocomplete dash-ac">
                <input class="input" id="qb" type="text" placeholder="Nombre o ID…"
                       autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                       data-dash-input>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </div>

<?php if ($isAdmin): ?>
    <div class="panel">
        <div class="field">
            <label class="field-label" for="qd">Buscar disco <span class="muted small">· para editar sus datos, su portada y sus pistas</span></label>
            <div data-dash-box="disco" class="autocomplete dash-ac">
                <input class="input" id="qd" type="text" placeholder="Nombre o ID…"
                       autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                       data-dash-input>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
<script src="/assets/dashboard-search.js" defer></script>
