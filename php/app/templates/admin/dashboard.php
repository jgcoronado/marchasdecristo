<?php use App\View as V; use App\Auth; use App\Entorno; use App\Roles;
/** @var string $q @var string $qb @var string $qd @var list<array<string,mixed>> $marchas @var list<array<string,mixed>> $autores @var list<array<string,mixed>> $bandas @var list<array<string,mixed>> $discos @var array $session @var array|null $notice @var int $pendientes @var bool $proposalMode @var int $misPendientes @var int $dudasAcompanamientos @var int $pendientesDmp @var int $pendientesAcompanamiento */
$csrf = Auth::csrfToken($session);
$rol = $session['rol'] ?? Roles::EDITOR;
$isAdmin = Roles::isAdmin($rol);
$soloLectura = Auth::soloLectura($session); // admin en PRE/PRO: solo mira
$puede = static fn(string $cap): bool => !$soloLectura && Roles::has($rol, $cap);
$chip = static fn(int $n): string => $n > 0 ? ' <span class="chip">' . $n . '</span>' : '';
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Panel de administración</h1>
        <div class="row">
            <span class="muted small">Sesión: <strong><?= V::e($session['user'] ?? '') ?></strong> · <?= V::e(Roles::label($rol)) ?></span>
<?php if ($puede('marcha.add')): ?>
            <a class="btn btn-sm" href="/dashboard/marcha/add">+ Marcha</a>
<?php endif; ?>
<?php if ($puede('autor.add')): ?>
            <a class="btn btn-sm" href="/dashboard/autor/add">+ Compositor</a>
<?php endif; ?>
<?php if ($puede('banda.add')): ?>
            <a class="btn btn-sm" href="/dashboard/banda/add">+ Banda</a>
<?php endif; ?>
<?php if ($puede('disco.add')): ?>
            <a class="btn btn-sm" href="/dashboard/disco/add">+ Disco</a>
<?php endif; ?>
<?php if ($proposalMode): ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard/mis-propuestas">Mis propuestas<?= $chip($misPendientes) ?></a>
<?php endif; ?>
<?php if ($isAdmin): ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard/propuestas">Propuestas<?= $chip($pendientes) ?></a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/usuarios">Usuarios</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/ingesta">Ingesta marchas</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/ingesta?fuente=dmp">Candidatos DMP<?= $chip($pendientesDmp) ?></a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/enlaces">Enlaces streaming</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/dedicatorias">Dedicatorias</a>
<?php endif; ?>
<?php if (Roles::has($rol, 'acompanamientos.edit')): ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos">Acompañamientos</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos-dudas">Dudas acompañamientos<?= $chip($dudasAcompanamientos) ?></a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos-pendientes">Bandas pendientes<?= $chip($pendientesAcompanamiento) ?></a>
<?php endif; ?>
<?php if ($isAdmin && \App\Entorno::permiteEscrituraDirecta()): ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos-malaga-blog">Cargar Málaga (blog)</a>
<?php endif; ?>
<?php if (Roles::has($rol, 'nomina.edit')): ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard/semana-santa">Semana Santa</a>
<?php endif; ?>
<?php if ($isAdmin): ?>
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
    <div class="alert alert-info">Trabajas como <strong>Editor</strong>. Todo lo que guardes —altas, cambios y borrados de marchas, compositores, bandas,
        discos, Semana Santa y acompañamientos— se envía como <strong>propuesta</strong>; un administrador la revisa antes de aplicarla.
        Lo que tienes pendiente está en <a href="/dashboard/mis-propuestas">Mis propuestas</a>.</div>
<?php endif; ?>
<?php if ($soloLectura): ?>
    <?php /* La cinta de layout.php avisa en todas las pantallas; aquí se
             concreta el alcance. Ver Auth::soloLectura() y docs/entornos.md. */ ?>
    <div class="alert alert-error">
        Entorno <strong><?= V::e(Entorno::nombre()) ?></strong>: el panel está en <strong>solo lectura</strong> para ti.
        Puedes consultar cualquier pantalla, pero no guardar, borrar, proponer, aceptar ni rechazar propuestas, ni gestionar usuarios:
        todo eso se hace en <strong>local</strong>. Las propuestas que los editores dejan aquí se bajan con
        <code class="mono">sync_propuestas_from_prod.php</code> y se revisan allí.
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

<?php if (Roles::has($rol, 'disco.edit')): ?>
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
