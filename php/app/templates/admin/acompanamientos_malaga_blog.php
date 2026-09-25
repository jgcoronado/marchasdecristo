<?php use App\View as V; use App\Auth; use App\MalagaBlogImporter as M;
/** @var array $session @var string $url @var array|null $a @var array|null $notice @var bool $local @var list<array<string,mixed>> $hermandades @var list<array<string,mixed>> $pasos */
$csrf = Auth::csrfToken($session);

// Valores iniciales del formulario de mapeo.
$mapeo = $a['mapeo'] ?? null;
$pasosMapeo = [];
foreach ((array) ($mapeo['pasos'] ?? []) as $clave => $cfg) $pasosMapeo[M::claveCabecera((string) $clave)] = (array) $cfg;
// Hermandad: la del mapeo; si no hay, la de la nómina cuyo slug o nombre
// coincide con la etiqueta ("Huerto" → huerto); si tampoco, se propone nueva.
// ?herm= (al cambiar el selector, ver script de abajo) manda sobre el mapeo guardado.
$slugInicial = (string) ($_GET['herm'] ?? ($mapeo['hermandad_slug'] ?? ''));
if ($slugInicial === '' && $a) {
    foreach ($hermandades as $h) {
        // Sin guiones: la etiqueta «HumildadyPaciencia» → humildadypaciencia = humildad-y-paciencia.
        // Y sin artículo inicial: «Cautivo» = el-cautivo.
        $sinGuion = static fn(string $x): string => str_replace('-', '', preg_replace('/^(el|la|los|las)-/', '', $x) ?? $x);
        if ($sinGuion($h['SLUG']) === $sinGuion($a['labelSlug']) || $sinGuion(\App\Slug::slugify((string) $h['NOMBRE'])) === $sinGuion($a['labelSlug'])) { $slugInicial = (string) $h['SLUG']; break; }
    }
}
$existeInicial = false;
foreach ($hermandades as $h) if ($h['SLUG'] === $slugInicial) $existeInicial = true;
$nombreInicial = $existeInicial ? '' : (string) ($mapeo['hermandad_nombre'] ?? ($a['etiqueta'] ?? ''));
// Pasos de esa hermandad, para elegir a cuál va cada cabecera del blog.
$pasosHerm = array_values(array_filter($pasos, static fn(array $p): bool => $p['SLUG'] === $slugInicial));
$nombresPasos = array_map(static fn(array $p): string => (string) $p['NOMBRE'], $pasosHerm);
?>
<div class="stack">
    <div class="admin-bar">
        <h1>Acompañamientos de Málaga desde el blog</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos-pendientes">Bandas pendientes</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/acompanamientos/malaga">Acompañamientos de Málaga</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

    <p class="muted small">
        Pega la URL de la etiqueta de una hermandad en malagamusical.blogspot.com
        (p. ej. <code>https://malagamusical.blogspot.com/search/label/Pollinica</code>)
        o la de una entrada concreta (<code>…/2013/02/nombre-de-la-entrada.html</code>).
        «Analizar» no escribe nada; «Cargar» hace una copia de la BD y guarda los contratos
        (solo CCTT y AM, desde 1980, con Cruz de Guía). Las bandas que no existen o son ambiguas
        quedan como texto en <a href="/dashboard/acompanamientos-pendientes">Bandas pendientes</a>.
    </p>

<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php if (!$local): ?>
    <div class="alert alert-info">Esta herramienta solo funciona en local: escribe directamente en la BD maestra.</div>
<?php else: ?>
    <form class="row" action="/dashboard/acompanamientos-malaga-blog" method="GET">
        <input class="input" type="url" name="url" value="<?= V::e($url) ?>" placeholder="https://malagamusical.blogspot.com/search/label/…" required style="flex:1;min-width:16rem">
        <button class="btn btn-neutral" type="submit">Analizar</button>
    </form>

<?php if ($a): ?>
    <h2 class="section-title"><?= V::e($a['etiqueta']) ?></h2>

<?php /* ── Formulario de mapeo: abierto si falta, plegado si ya está completo ── */ ?>
<?php $cabecerasForm = $a['listo'] || $a['faltaHermandad'] ? $a['cabeceras'] : $a['sinMapeo']; ?>
<?php if (!$a['listo']): ?>
    <div class="alert alert-info">
        <?= $a['faltaHermandad']
            ? 'Esta etiqueta todavía no está mapeada. Indica a qué hermandad corresponde y qué paso es cada bloque del blog.'
            : 'Hay bloques del blog sin paso asignado: ' . V::e(implode(', ', $a['sinMapeo'])) . '.' ?>
    </div>
<?php endif; ?>
    <details class="panel"<?= $a['listo'] ? '' : ' open' ?>>
        <summary><strong><?= $a['listo'] ? 'Editar mapeo' : 'Mapeo' ?></strong> <span class="muted small">(etiqueta → hermandad, bloque → paso)</span></summary>
        <form class="stack admin-form" action="/dashboard/acompanamientos-malaga-blog/mapeo" method="POST">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
            <div class="row">
                <div class="field">
                    <label class="field-label" for="mb_slug">Hermandad</label>
                    <select class="input" id="mb_slug" name="hermandad_slug">
                        <option value="">— Nueva (usa el nombre de al lado) —</option>
<?php foreach ($hermandades as $h): ?>
                        <option value="<?= V::e($h['SLUG']) ?>"<?= $h['SLUG'] === $slugInicial ? ' selected' : '' ?>><?= V::e($h['NOMBRE']) ?></option>
<?php endforeach; ?>
                    </select>
                    <script>
                    // Recarga con ?herm= para enseñar los pasos de la hermandad elegida.
                    // No va en onchange="": ahí `URL` resolvería a document.URL (un texto).
                    document.getElementById('mb_slug').addEventListener('change', function () {
                        var u = new window.URL(window.location.href);
                        u.searchParams.set('herm', this.value);
                        ['mapeo', 'corregido', 'anadido', 'err', 'cargado'].forEach(function (k) { u.searchParams.delete(k); });
                        window.location.href = u.toString();
                    });
                    </script>
                    <span class="field-help muted small">Al cambiarla se recarga con sus pasos; no se guarda hasta pulsar «Guardar mapeo».</span>
                </div>
                <div class="field">
                    <label class="field-label" for="mb_nombre">Nombre de la hermandad nueva</label>
                    <input class="input" id="mb_nombre" name="hermandad_nombre" value="<?= V::e($nombreInicial) ?>">
                    <span class="field-help muted small">Solo si eliges «Nueva»: se creará con día «Sin asignar».</span>
                </div>
            </div>
            <div class="tableList"><table class="table table-zebra table-sm">
                <thead class="thead-neutral"><tr><td>Cabecera del blog</td><td>Qué es</td><td>Paso de la hermandad</td><td>…o paso nuevo</td></tr></thead>
                <tbody>
<?php foreach ($cabecerasForm as $i => $cab):
    $clave = M::claveCabecera($cab);
    $cfg = $pasosMapeo[$clave] ?? null;
    if ($cfg !== null) {
        $tipo = !empty($cfg['descartar']) ? 'descartar' : (!empty($cfg['es_cruz_guia']) ? 'cruz' : 'paso');
        $nombre = (string) ($cfg['nombre'] ?? '');
    } elseif ($clave === 'cruz de guia') {
        [$tipo, $nombre] = ['cruz', 'Cruz de Guía'];
    } elseif ($clave === 'virgen') {
        [$tipo, $nombre] = ['descartar', ''];
    } else {
        [$tipo, $nombre] = ['paso', ''];
    } ?>
                    <tr>
                        <td><input type="hidden" name="pasos[<?= $i ?>][cabecera]" value="<?= V::e($cab) ?>"><?= V::e($cab) ?></td>
                        <td>
                            <select class="input" name="pasos[<?= $i ?>][tipo]">
                                <option value="paso"<?= $tipo === 'paso' ? ' selected' : '' ?>>Paso</option>
                                <option value="cruz"<?= $tipo === 'cruz' ? ' selected' : '' ?>>Cruz de guía</option>
                                <option value="descartar"<?= $tipo === 'descartar' ? ' selected' : '' ?>>Descartar (solo bandas de música)</option>
                            </select>
                        </td>
<?php $existe = in_array($nombre, $nombresPasos, true); ?>
                        <td>
                            <select class="input" name="pasos[<?= $i ?>][nombre]">
                                <option value="">—</option>
<?php foreach ($pasosHerm as $p): ?>
                                <option value="<?= V::e($p['NOMBRE']) ?>"<?= $p['NOMBRE'] === $nombre ? ' selected' : '' ?>><?= V::e($p['NOMBRE']) ?><?= $p['ES_CRUZ_GUIA'] ? ' (cruz de guía)' : '' ?></option>
<?php endforeach; ?>
                            </select>
                        </td>
                        <td><input class="input" name="pasos[<?= $i ?>][nuevo]" value="<?= $existe ? '' : V::e($nombre) ?>" placeholder="Crear paso con este nombre"></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="muted small field-help">«Descartar» solo es seguro si en ese bloque no hay CCTT ni AM: si las hay, el análisis las marcará como duda.</p>
            <div><button class="btn btn-neutral" type="submit">Guardar mapeo</button></div>
        </form>
    </details>

<?php if ($a['listo']):
    $hayQueHacer = $a['nuevosContratos'] || $a['nuevasPendientes'] || $a['pasosNuevos'] || $a['idHermandad'] === null; ?>
    <div class="row">
        <span class="chip"><?= count($a['nuevosContratos']) ?> contratos nuevos</span>
        <span class="chip"><?= count($a['nuevasPendientes']) ?> sin enlazar</span>
        <span class="chip"><?= (int) $a['yaCargados'] ?> ya cargados</span>
        <span class="chip"><?= (int) $a['yaPendientes'] ?> ya pendientes</span>
<?php if ($a['conflictos']): ?><span class="badge badge-warn"><?= count($a['conflictos']) ?> conflicto(s)</span><?php endif; ?>
<?php if ($a['dudasBanda'] || $a['dudasTipo']): ?><span class="badge badge-warn"><?= count($a['dudasBanda']) + count($a['dudasTipo']) ?> duda(s)</span><?php endif; ?>
    </div>

<?php if ($a['idHermandad'] === null): ?>
    <div class="alert alert-info">Se creará la hermandad <strong><?= V::e($a['hermandadNombre']) ?></strong> (<?= V::e($a['hermandadSlug']) ?>) con día «Sin asignar»: ponle día y orden después en la nómina.</div>
<?php endif; ?>
<?php if ($a['pasosNuevos']): ?>
    <p class="small">Pasos nuevos a crear: <?= V::e(implode(', ', array_map(static fn($p) => $p['nombre'] . ($p['es_cruz_guia'] ? ' (cruz de guía)' : ''), $a['pasosNuevos']))) ?></p>
<?php endif; ?>

<?php if ($a['conflictos']): ?>
    <h3 class="section-title">Conflictos — no se toca nada</h3>
    <p class="muted small">La BD ya tiene otra banda para ese paso y año. Revísalo a mano en Acompañamientos de Málaga.</p>
    <ul class="small"><?php foreach ($a['conflictos'] as $s): ?><li><?= V::e($s) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php
    // Una tabla por paso, una fila por línea del blog, con el mismo marcado que
    // /dashboard/acompanamientos/{localidad} (.acomp-paso/.acomp-tabla/.acomp-alta).
    // Cada cambio se guarda como corrección en el mapeo y se aplica en cada
    // análisis (Admin::malagaBlogCorreccionPost); el alta crea un «extra».
    $opcionesPaso = $a['pasosExistentes'];
    foreach ($pasosMapeo as $cfg) if (isset($cfg['nombre'])) $opcionesPaso[] = (string) $cfg['nombre'];
    foreach ($a['filas'] as $f) $opcionesPaso[] = $f['paso'];
    $opcionesPaso = array_values(array_unique($opcionesPaso));
    $esCruz = [];
    foreach ($pasosMapeo as $cfg) if (!empty($cfg['es_cruz_guia']) && isset($cfg['nombre'])) $esCruz[(string) $cfg['nombre']] = true;
    $porPaso = array_fill_keys($opcionesPaso, []);
    foreach ($a['filas'] as $f) $porPaso[$f['paso']][] = $f;
    uksort($porPaso, static fn($x, $y): int => empty($esCruz[$x]) <=> empty($esCruz[$y])); // cruz de guía primero ?>
    <p class="muted small">Corrige cada línea antes de cargar (años, banda, paso) o descártala; las corregidas llevan ✎. Sin banda enlazada, la línea queda como texto en Bandas pendientes. «+ Añadir» crea un acompañamiento que no está en el blog.</p>
    <div class="row acomp-barra mb-lote" style="align-items:center;gap:0.75rem;margin-bottom:0.5rem">
        <form id="mb-lote" class="inline-form" action="/dashboard/acompanamientos-malaga-blog/correccion" method="POST">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
            <button class="btn btn-sm btn-neutral" type="submit" name="accion" value="guardar" data-mb-lote-btn disabled>Guardar seleccionados</button>
            <button class="btn btn-sm btn-danger" type="submit" name="accion" value="descartar" data-mb-lote-btn disabled>Descartar seleccionados (<span data-mb-cuenta>0</span>)</button>
        </form>
    </div>
    <div data-contratos-table>
<section class="panel">
    <h2 class="section-title" style="margin-top:0"><?= V::e($a['hermandadNombre']) ?></h2>
    <div class="nomina-pasos" style="padding-left:0">
<?php foreach ($porPaso as $pasoNombre => $filasTabla): $pasoNombre = (string) $pasoNombre; ?>
        <div class="acomp-paso">
            <div class="acomp-paso-cab"><label><input type="checkbox" data-mb-todos aria-label="Seleccionar todas las líneas de este paso"></label> <?= !empty($esCruz[$pasoNombre]) ? '<span class="badge">Cruz de guía</span> ' : '' ?><?= V::e($pasoNombre) ?><?= in_array($pasoNombre, $a['pasosExistentes'], true) ? '' : ' <span class="badge badge-warn">se creará</span>' ?></div>
            <table class="table table-sm acomp-tabla"><tbody>
<?php foreach ($filasTabla as $f):
    $fid = 'mbc' . substr(md5($f['clave']), 0, 10);
    $corr = $f['correccion'];
    [$desde, $hasta] = array_map('intval', explode('/', $f['anios'] . '/' . $f['anios']));
    $bandaNombre = $f['idBanda'] !== null ? (string) ($a['bandaNombres'][$f['idBanda']] ?? ('#' . $f['idBanda'])) : ''; ?>
                <tr>
                    <td style="width:2rem"><input type="checkbox" class="acomp-check" data-mb-fila="<?= $fid ?>" aria-label="Seleccionar"></td>
                    <td style="width:13rem;white-space:nowrap">
                        <span data-anios-display>
                            <button type="button" class="btn btn-sm btn-ghost" data-mb-editar-anios title="Cambiar los años"><?= V::e(str_replace('/', '–', $f['anios'])) ?></button>
<?php if ($f['anios'] !== $f['rangoBlog'] && empty($f['extra'])): ?><span class="muted small">blog: <?= V::e($f['rangoBlog']) ?></span><?php endif; ?>
                        </span>
                        <span data-anios-form hidden>
                            <input class="input" type="number" name="desde" form="<?= $fid ?>" value="<?= $desde ?>" min="1900" max="2100" style="width:5.5rem" aria-label="Desde">
                            –
                            <input class="input" type="number" name="hasta" form="<?= $fid ?>" value="<?= $hasta ?>" min="1900" max="2100" style="width:5.5rem" aria-label="Hasta">
                        </span>
                    </td>
                    <td>
                        <span data-banda-display>
                            <?= $f['idBanda'] !== null ? V::e($bandaNombre) . ' <span class="mono muted small">#' . (int) $f['idBanda'] . '</span>' : '<span class="badge">texto</span>' ?>
                            <button type="button" class="btn btn-sm btn-ghost" data-editar-banda><?= $f['idBanda'] !== null ? 'Editar' : 'Enlazar' ?></button>
                            <?= $corr ? '<span class="badge badge-warn" title="Corregida a mano">✎</span>' : '' ?>
                            <div class="muted small"><?= V::e($f['bandaTexto']) ?><?= $pasoNombre !== $f['cabecera'] ? ' · bloque: ' . V::e($f['cabecera']) : '' ?></div>
                        </span>
                        <div class="inline-form" data-banda-edit-form hidden>
                            <input type="hidden" name="ID_BANDA" value="" form="<?= $fid ?>" data-banda-edit-hidden>
                            <div class="autocomplete">
                                <input class="input" type="text" value="<?= V::e($bandaNombre) ?>" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off" data-banda-edit-search>
                                <div class="suggest" data-banda-edit-suggest hidden></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-ghost" data-editar-cancelar>Cancelar</button>
                        </div>
                    </td>
                    <td style="white-space:nowrap;text-align:right">
                        <form id="<?= $fid ?>" class="inline-form" action="/dashboard/acompanamientos-malaga-blog/correccion" method="POST">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
                            <input type="hidden" name="clave" value="<?= V::e($f['clave']) ?>">
                            <input type="hidden" name="paso_blog" value="<?= V::e(isset($corr['paso']) ? '' : $f['paso']) ?>">
                            <input type="hidden" name="anios_blog" value="<?= V::e($f['rangoBlog']) ?>">
<?php if (isset($corr['id_banda'])): ?>
                            <input type="hidden" name="id_banda_previa" value="<?= (int) $corr['id_banda'] ?>">
<?php endif; ?>
<?php if (count($opcionesPaso) > 1): ?>
                            <select class="input acomp-tramo" name="paso" aria-label="Paso" title="Mover a otro paso">
<?php foreach ($opcionesPaso as $op): ?>
                                <option value="<?= V::e($op) ?>"<?= $op === $f['paso'] ? ' selected' : '' ?>><?= V::e($op) ?></option>
<?php endforeach; ?>
                            </select>
<?php endif; ?>
                            <button class="btn btn-sm btn-neutral" type="submit" name="accion" value="guardar">Guardar</button>
                            <button class="btn btn-sm btn-ghost" type="submit" name="accion" value="descartar"><?= empty($f['extra']) ? 'Descartar' : 'Borrar' ?></button>
<?php if ($corr): ?>
                            <button class="btn btn-sm btn-ghost" type="submit" name="accion" value="quitar" title="Volver a lo que dice el blog">Deshacer</button>
<?php endif; ?>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if (!$filasTabla): ?>
                <tr class="acomp-vacio"><td colspan="4" class="muted small">Sin acompañamientos en el blog.</td></tr>
<?php endif; ?>
                <tr class="acomp-alta">
                    <td colspan="4">
                        <form class="inline-form" action="/dashboard/acompanamientos-malaga-blog/correccion" method="POST">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
                            <input type="hidden" name="paso" value="<?= V::e($pasoNombre) ?>">
                            <input type="hidden" name="ID_BANDA" value="" data-banda-edit-hidden>
                            <div class="autocomplete">
                                <input class="input" type="text" placeholder="Añadir banda…" autocomplete="off" data-banda-edit-search aria-label="Banda" style="width:17rem">
                                <div class="suggest" data-banda-edit-suggest hidden></div>
                            </div>
                            <input class="input" type="number" name="desde" min="1900" max="2100" placeholder="Desde" required style="width:5.5rem" aria-label="Año inicio">
                            <input class="input" type="number" name="hasta" min="1900" max="2100" placeholder="Hasta" style="width:5.5rem" aria-label="Año fin (vacío = solo el de inicio)">
                            <button class="btn btn-sm btn-neutral" type="submit" name="accion" value="anadir">+ Añadir</button>
                        </form>
                    </td>
                </tr>
            </tbody></table>
        </div>
<?php endforeach; ?>
    </div>
</section>
    </div>

<style>.mb-lote{position:sticky;top:0;z-index:5;background:var(--bg);padding:.5rem 0}</style>
<script>
(function () {
    // Mantiene el scroll al volver de cualquier POST de esta página.
    var K = 'mb-scroll:' + location.pathname + location.search.replace(/[?&](corregido|anadido|mapeo|err|cargado)=[^&]*/g, '');
    try {
        var y = sessionStorage.getItem(K);
        if (y !== null) {
            sessionStorage.removeItem(K);
            window.addEventListener('load', function () { window.scrollTo(0, +y); });
        }
    } catch (_) {}
    document.addEventListener('submit', function () {
        try { sessionStorage.setItem(K, String(window.scrollY)); } catch (_) {}
    }, true);

    // Años: botón → campos desde/hasta (como en la página de acompañamientos).
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-mb-editar-anios]');
        if (!b) return;
        var td = b.closest('td');
        td.querySelector('[data-anios-display]').hidden = true;
        td.querySelector('[data-anios-form]').hidden = false;
        td.querySelector('[data-anios-form] input').focus();
    });

    var lote = document.getElementById('mb-lote');
    if (!lote) return;
    var filas = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-mb-fila]')); };
    function actualizar() {
        var n = filas().filter(function (c) { return c.checked; }).length;
        lote.querySelector('[data-mb-cuenta]').textContent = n;
        lote.querySelectorAll('[data-mb-lote-btn]').forEach(function (b) { b.disabled = n === 0; });
    }
    document.addEventListener('change', function (e) {
        var todos = e.target.closest('[data-mb-todos]');
        if (todos) {
            todos.closest('.acomp-paso').querySelectorAll('[data-mb-fila]').forEach(function (c) { c.checked = todos.checked; });
        }
        if (todos || e.target.closest('[data-mb-fila]')) actualizar();
    });
    // Copia los campos de cada fila marcada (su form + inputs con form=) como filas[i][campo].
    lote.addEventListener('submit', function () {
        lote.querySelectorAll('[data-mb-copia]').forEach(function (x) { x.remove(); });
        filas().filter(function (c) { return c.checked; }).forEach(function (c, i) {
            var f = document.getElementById(c.getAttribute('data-mb-fila'));
            Array.prototype.forEach.call(f.elements, function (el) {
                if (!el.name || el.name === '_csrf' || el.name === 'url' || el.name === 'accion') return;
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'filas[' + i + '][' + el.name + ']'; h.value = el.value;
                h.setAttribute('data-mb-copia', '');
                lote.appendChild(h);
            });
        });
    });
    actualizar();
})();
</script>
<?php foreach (['dudasBanda' => 'Dudas — banda ambigua (queda como texto)', 'dudasTipo' => 'Dudas — tipo de banda o paso', 'resueltoPorOtraFuente' => 'Ya resuelto por otra fuente', 'lineasNoParseadas' => 'Líneas del blog no reconocidas'] as $clave => $titulo): ?>
<?php if ($a[$clave]): ?>
    <h3 class="section-title"><?= V::e($titulo) ?></h3>
    <ul class="small"><?php foreach ($a[$clave] as $s): ?><li><?= V::e($s) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($a['descartadas']): ?>
    <details>
        <summary class="small muted">Descartadas por tipo de banda o a mano (<?= count($a['descartadas']) ?>)</summary>
        <ul class="small muted"><?php foreach ($a['descartadas'] as $d): ?><li><?= V::e($d['paso'] . ' ' . $d['anio'] . ': ' . $d['texto']) ?>
<?php if ($d['motivo'] === 'corregido'): ?>
            <form class="inline-form" action="/dashboard/acompanamientos-malaga-blog/correccion" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
                <input type="hidden" name="clave" value="<?= V::e($d['clave']) ?>">
                <button class="btn btn-sm btn-ghost" type="submit" name="accion" value="quitar">Recuperar</button>
            </form>
<?php endif; ?>
        </li><?php endforeach; ?></ul>
    </details>
<?php endif; ?>

    <form action="/dashboard/acompanamientos-malaga-blog/cargar" method="POST" class="row">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="url" value="<?= V::e($a['url']) ?>">
<?php if ($hayQueHacer): ?>
        <button class="btn btn-neutral" type="submit">Cargar (<?= count($a['nuevosContratos']) ?> contratos + <?= count($a['nuevasPendientes']) ?> sin enlazar)</button>
        <span class="muted small">Hace copia de la BD antes de escribir.</span>
<?php else: ?>
        <span class="muted small">Nada nuevo que cargar para esta hermandad.</span>
<?php endif; ?>
    </form>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<script src="/assets/admin.js" defer></script>
