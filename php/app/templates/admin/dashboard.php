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

    <form class="panel" action="/dashboard" method="GET">
        <input type="hidden" name="qb" value="<?= V::e($qb) ?>">
        <input type="hidden" name="qd" value="<?= V::e($qd) ?>">
        <div class="field">
            <label class="field-label" for="q">Buscar marcha</label>
            <div data-dash-box="marcha">
                <div class="autocomplete row">
                    <input class="input" id="q" name="q" type="text"
                           value="<?= V::e($q) ?>" placeholder="Título o ID…"
                           autocomplete="off" aria-autocomplete="list" aria-expanded="false" autofocus
                           data-dash-input>
                    <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
                </div>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </form>

    <form class="panel" action="/dashboard" method="GET">
        <input type="hidden" name="qb" value="<?= V::e($qb) ?>">
        <input type="hidden" name="qd" value="<?= V::e($qd) ?>">
        <div class="field">
            <label class="field-label" for="qa">Buscar compositor</label>
            <div data-dash-box="autor">
                <div class="autocomplete row">
                    <input class="input" id="qa" name="q" type="text"
                           value="<?= V::e($q) ?>" placeholder="Nombre o ID…"
                           autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                           data-dash-input>
                    <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
                </div>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </form>

    <form class="panel" action="/dashboard" method="GET">
        <input type="hidden" name="q" value="<?= V::e($q) ?>">
        <input type="hidden" name="qd" value="<?= V::e($qd) ?>">
        <div class="field">
            <label class="field-label" for="qb">Buscar banda <span class="muted small">· para editar sus datos y su linaje</span></label>
            <div data-dash-box="banda">
                <div class="autocomplete row">
                    <input class="input" id="qb" name="qb" type="text"
                           value="<?= V::e($qb) ?>" placeholder="Nombre o ID…"
                           autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                           data-dash-input>
                    <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
                </div>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </form>

<?php if ($isAdmin): ?>
    <form class="panel" action="/dashboard" method="GET">
        <input type="hidden" name="q" value="<?= V::e($q) ?>">
        <input type="hidden" name="qb" value="<?= V::e($qb) ?>">
        <div class="field">
            <label class="field-label" for="qd">Buscar disco <span class="muted small">· para editar sus datos, su portada y sus pistas</span></label>
            <div data-dash-box="disco">
                <div class="autocomplete row">
                    <input class="input" id="qd" name="qd" type="text"
                           value="<?= V::e($qd) ?>" placeholder="Nombre o ID…"
                           autocomplete="off" aria-autocomplete="list" aria-expanded="false"
                           data-dash-input>
                    <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
                </div>
                <div class="suggest" data-dash-suggest hidden role="listbox"></div>
            </div>
        </div>
    </form>
<?php endif; ?>

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
