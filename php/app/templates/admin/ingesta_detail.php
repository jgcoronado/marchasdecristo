<?php use App\View as V; use App\Auth; use App\Html as H; use App\IngestaRepo;
/** @var array $session @var array<string,mixed> $cand
 *  @var list<array{ID_AUTOR:int,NOMBRE_COMPLETO:string,score:float}> $autoresAuto
 *  @var list<string> $autoresSugeridos @var string $back
 *  @var string|null $estiloSugerido
 *  @var array|null $notice @var string|null $error */
$csrf = Auth::csrfToken($session);
$val = static fn(string $k, string $default = ''): string => V::e($cand[$k] ?? $default);
$flags = $cand['FLAGS'] ? (json_decode((string) $cand['FLAGS'], true) ?: []) : [];

$fields = [
    ['TITULO', 'Título', 'text', $cand['P_TITULO'] ?? ''],
    ['FECHA', 'Fecha (año de 4 dígitos)', 'text', $cand['P_FECHA'] ?? ''],
];
$candLocalidad = (string) ($cand['P_LOCALIDAD'] ?? '');
$candProvincia = is_string($cand['P_PROVINCIA'] ?? null) && $cand['P_PROVINCIA'] !== '' ? (string) $cand['P_PROVINCIA'] : null;
$bandaEstrenoVal = $cand['P_BANDA_ESTRENO'] ?? $cand['ID_BANDA'] ?? '';

// Origen del candidato: YouTube (pipeline yt-dlp) o el catálogo de streaming
// de la banda (Spotify/Deezer/Apple). Cambia el reproductor, la etiqueta de
// los enlaces y dónde se guarda la URL al aceptar.
$fuente = (string) ($cand['FUENTE'] ?? 'youtube');
$fuenteLabel = IngestaRepo::FUENTE_LABEL[$fuente] ?? ucfirst($fuente);
$esYoutube = $fuente === 'youtube';
$embed = IngestaRepo::embedUrl($fuente, (string) $cand['VIDEO_ID']);
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Revisar candidato #<?= (int) $cand['ID_CAND'] ?></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="<?= V::e($cand['VIDEO_URL']) ?>" target="_blank"><?= $esYoutube ? 'Vídeo original' : 'Escuchar en ' . V::e($fuenteLabel) ?> ↗</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard/ingesta<?= $back !== '' ? '?' . V::e($back) : '' ?>">← Volver</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>

<?php if ($cand['ESTADO'] !== 'pendiente'): ?>
    <div class="alert alert-info">Este candidato ya está <strong><?= V::e($cand['ESTADO']) ?></strong> y no admite más acciones.</div>
<?php endif; ?>

<?php if (!empty($cand['MATCH_MARCHA_ID'])): ?>
    <div class="alert alert-error">
        ⚠ Posible coincidencia con una marcha ya existente:
        <a href="/marcha/<?= (int) $cand['MATCH_MARCHA_ID'] ?>" target="_blank"><?= V::e($cand['MATCH_TITULO']) ?> (#<?= (int) $cand['MATCH_MARCHA_ID'] ?>)</a>
        — similitud <?= (int) round(((float) $cand['MATCH_SCORE']) * 100) ?>%. Revisa antes de aceptar.
    </div>
<?php endif; ?>

    <div class="panel">
        <div class="row" style="flex-wrap:wrap;gap:1rem">
            <div style="flex:1;min-width:280px">
<?php if ($embed !== null): ?>
                <iframe width="100%" height="220" style="border-radius:var(--radius-sm);border:1px solid var(--border)"
                        src="<?= V::e($embed) ?>"
                        title="Reproductor de <?= V::e($fuenteLabel) ?>" frameborder="0" allowfullscreen
                        allow="encrypted-media; clipboard-write"></iframe>
<?php else: ?>
                <p class="small muted"><?= V::e($fuenteLabel) ?> no permite incrustar la pista aquí; ábrela con el enlace de arriba para escucharla.</p>
<?php endif; ?>
            </div>
            <div style="flex:1;min-width:280px" class="stack">
                <p><strong>Título original:</strong> <?= V::e($cand['VIDEO_TITULO']) ?></p>
<?php if (!empty($cand['FUENTE_ALBUM'])): ?>
                <p class="small">Disco de origen:
<?php if (!empty($cand['FUENTE_ALBUM_URL'])): ?>
                    <a href="<?= V::e($cand['FUENTE_ALBUM_URL']) ?>" target="_blank"><?= V::e($cand['FUENTE_ALBUM']) ?> ↗</a>
<?php else: ?>
                    <?= V::e($cand['FUENTE_ALBUM']) ?>
<?php endif; ?>
                </p>
<?php endif; ?>
                <p class="small muted">Fuente: <?= V::e($fuenteLabel) ?> ·
                    <?= $esYoutube ? 'Publicado' : 'Fecha' ?>: <?= V::e($cand['PUBLICADO_AT']) ?: '—' ?> ·
                    Duración: <?= $cand['DURACION_SEG'] ? gmdate('i:s', (int) $cand['DURACION_SEG']) : '—' ?> ·
                    Clasificación: <span class="badge badge-<?= V::e($cand['CLASIFICACION']) ?>"><?= V::e($cand['CLASIFICACION']) ?></span> ·
                    Confianza: <?= (int) round(((float) $cand['CONFIANZA']) * 100) ?>%
                </p>
<?php if ($flags): ?>
                <p class="small">Revisar: <?= V::e(implode(', ', $flags)) ?></p>
<?php endif; ?>
            </div>
        </div>
<?php if (!empty($cand['VIDEO_DESC'])): ?>
        <details>
            <summary class="small muted" style="cursor:pointer">Ver descripción original<?= $esYoutube ? ' del vídeo' : '' ?></summary>
            <p class="small" style="white-space:pre-wrap"><?= V::e($cand['VIDEO_DESC']) ?></p>
        </details>
<?php endif; ?>
    </div>

<?php if ($cand['ESTADO'] === 'pendiente'): ?>
    <form class="panel" action="/dashboard/ingesta/<?= (int) $cand['ID_CAND'] ?>/aceptar" method="POST" id="aceptarForm" <?= H::municipioFormAttrs(true, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="ref" value="<?= V::e($back) ?>">
<?php foreach ($fields as [$key, $label, $type, $default]): ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= $label ?></label>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" value="<?= V::e($default) ?>">
        </div>
<?php endforeach; ?>
        <div class="field">
            <label class="field-label" for="DEDICATORIA">Dedicatoria</label>
            <div class="autocomplete">
                <input class="input" id="DEDICATORIA" name="DEDICATORIA" type="text"
                       value="<?= V::e($cand['P_DEDICATORIA'] ?? '') ?>" autocomplete="off">
                <div id="dedicatoriaSuggest" class="suggest" hidden></div>
            </div>
            <p class="muted">Escribe 7+ caracteres para buscar hermandades ya existentes en la BD. Al elegir una, se rellenan también localidad y provincia.</p>
        </div>
<?= H::municipioFields($candLocalidad, $candProvincia) ?>
        <div class="field">
            <label class="field-label" for="BANDA_ESTRENO">ID de la banda de estreno</label>
            <input class="input" id="BANDA_ESTRENO" name="BANDA_ESTRENO" type="number" value="<?= V::e($bandaEstrenoVal) ?>">
            <p class="muted">Banda del candidato: <strong><?= V::e($cand['NOMBRE_BREVE'] ?? ('#' . $cand['ID_BANDA'])) ?></strong> (#<?= (int) $cand['ID_BANDA'] ?>)<?php if ($cand['BANDA_LOCALIDAD']): ?> — <?= V::e($cand['BANDA_LOCALIDAD']) ?><?php endif; ?><?php if ((string) $bandaEstrenoVal !== (string) $cand['ID_BANDA']): ?> — el valor del campo es distinto, revísalo<?php endif; ?>.</p>
        </div>
        <div class="field">
            <label class="field-label" for="ESTILO">Estilo</label>
            <select class="input" id="ESTILO" name="ESTILO">
                <option value="">— Sin asignar —</option>
                <option value="CCTT"<?= ($estiloSugerido ?? '') === 'CCTT' ? ' selected' : '' ?>>Cornetas y Tambores (CCTT)</option>
                <option value="AM"<?= ($estiloSugerido ?? '') === 'AM' ? ' selected' : '' ?>>Agrupación Musical (AM)</option>
            </select>
            <p class="muted small" id="estiloHint"><?= $estiloSugerido ? 'Sugerido automáticamente según las marchas existentes de esta banda.' : 'No hay marchas previas de esta banda; selecciónalo manualmente.' ?></p>
        </div>
        <div class="field">
            <label class="field-label" for="DETALLES_MARCHA">Detalles</label>
            <textarea class="input" id="DETALLES_MARCHA" name="DETALLES_MARCHA" rows="3"></textarea>
        </div>

        <div class="field">
            <label class="field-label">Autor(es)</label>
<?php if ($autoresAuto): ?>
            <p class="small muted">
                Añadidos automáticamente (coincidencia ≥80% con un autor ya existente):
                <?= V::e(implode(', ', array_map(static fn(array $a): string => $a['NOMBRE_COMPLETO'] . ' (' . (int) round($a['score'] * 100) . '%)', $autoresAuto))) ?>
            </p>
<?php endif; ?>
<?php if ($autoresSugeridos): ?>
            <p class="small muted">
                Sugeridos por el vídeo:
<?php foreach ($autoresSugeridos as $nombre): ?>
                <button type="button" class="btn btn-sm btn-ghost sugerido-autor" data-nombre="<?= V::e($nombre) ?>"><?= V::e($nombre) ?></button>
                <a class="small" href="/dashboard/autor/add?nombre=<?= rawurlencode($nombre) ?>" target="_blank">(＋ crear)</a>
<?php endforeach; ?>
            </p>
<?php endif; ?>
            <div id="autoresBox" class="chips"></div>
            <div class="row" style="align-items:center;gap:0.6rem">
                <div class="autocomplete" style="flex:1">
                    <input class="input" id="autorSearch" type="text" placeholder="Buscar compositor (mín. 3 caracteres)…" autocomplete="off">
                    <div id="autorSuggest" class="suggest" hidden></div>
                </div>
                <a class="btn btn-sm btn-ghost" href="/dashboard/autor/add" target="_blank">＋ crear</a>
            </div>
            <p class="muted">Debe haber al menos un autor. Si no existe, créalo con el enlace "＋ crear" y vuelve a buscarlo aquí.</p>
        </div>

        <div class="field">
            <label class="row" style="align-items:center;gap:0.4rem;cursor:pointer">
                <input type="checkbox" id="guardar_origen" name="guardar_origen" value="1" checked>
<?= $esYoutube
    ? 'Guardar el vídeo como audio de la marcha'
    : 'Guardar el enlace de ' . V::e($fuenteLabel) . ' en la ficha de la marcha' ?>
            </label>
        </div>

        <div class="row">
            <button class="btn btn-neutral" type="submit">Aceptar y crear marcha</button>
        </div>
    </form>

    <form class="panel" style="border:2px solid var(--color-danger,#dc2626);background:color-mix(in srgb,var(--color-danger,#dc2626) 5%,transparent)"
          action="/dashboard/ingesta/<?= (int) $cand['ID_CAND'] ?>/descartar" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="ref" value="<?= V::e($back) ?>">
        <p class="small" style="color:var(--color-danger,#dc2626);font-weight:600;margin-bottom:0.5rem">⚠ Zona de descarte</p>
        <p class="small muted" style="margin-bottom:0.5rem">Al descartarlo, este origen queda vetado y no volverá a proponerse en futuras pasadas. Si te equivocas, puedes deshacerlo desde el listado (solo el último descarte).</p>
        <div class="row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:220px;margin-bottom:0">
                <label class="field-label" for="motivo">Motivo del descarte (opcional)</label>
                <input class="input" id="motivo" name="motivo" type="text" placeholder="p.ej. no es una marcha nueva, es un cover…">
            </div>
            <button class="btn btn-danger" type="submit" style="white-space:nowrap;flex-shrink:0">Descartar candidato</button>
        </div>
    </form>
<?php endif; ?>
</div>
<script src="/assets/admin.js" defer></script>
<script>
// Autores con coincidencia fuerte (≥80%) detectada en el servidor: se añaden
// como chip ya seleccionado sin esperar a que el revisor los busque a mano.
var autoresAuto = <?= json_encode(array_map(static fn(array $a): array => ['id' => $a['ID_AUTOR'], 'nombre' => $a['NOMBRE_COMPLETO']], $autoresAuto), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', function () {
    if (!window.AutorAutocomplete) return;
    autoresAuto.forEach(function (a) { window.AutorAutocomplete.addChip(a.id, a.nombre); });
});

// Sugerencias de autor extraídas del vídeo: si el nombre YA existe en la BD,
// se añade directamente como autor (sin tener que buscarlo y volver a
// aceptarlo en el desplegable). Si no existe, se deja el nombre en el cuadro
// de búsqueda para que se vea que no hay coincidencia (usar el enlace "＋ crear").
document.querySelectorAll('.sugerido-autor').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        var search = document.getElementById('autorSearch');
        var nombre = btn.dataset.nombre;
        try {
            var res = await fetch('/api/autor/fastSearch?nombre=' + encodeURIComponent(nombre), { credentials: 'same-origin' });
            var data = await res.json();
            var rows = Array.isArray(data.data) ? data.data : [];
            if (rows.length && window.AutorAutocomplete) {
                var r = rows[0];
                window.AutorAutocomplete.addChip(r.ID_AUTOR, r.NOMBRE_COMPLETO || nombre);
                return;
            }
        } catch (e) { /* red: caemos al buscador manual */ }
        search.value = nombre;
        search.dispatchEvent(new Event('input'));
        search.focus();
    });
});

// Predictivo de dedicatorias: a partir de 7 caracteres busca hermandades ya
// existentes en la BD (/api/dedicatoria/fastSearch). Si la dedicatoria es de
// tipo hermandad ("Hdad" en el texto), al aceptar la sugerencia se rellenan
// también localidad y provincia.
(function () {
    var input = document.getElementById('DEDICATORIA');
    var suggest = document.getElementById('dedicatoriaSuggest');
    if (!input || !suggest) return;

    function closeSuggest() { suggest.hidden = true; suggest.innerHTML = ''; }

    var timer, controller;
    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 7) { closeSuggest(); return; }
        timer = setTimeout(async function () {
            if (controller) controller.abort();
            controller = new AbortController();
            try {
                var res = await fetch('/api/dedicatoria/fastSearch?q=' + encodeURIComponent(q),
                    { signal: controller.signal, credentials: 'same-origin' });
                var data = await res.json();
                var rows = Array.isArray(data.data) ? data.data : [];
                if (!rows.length) { closeSuggest(); return; }
                suggest.innerHTML = '';
                rows.forEach(function (r) {
                    var esHermandad = /hdad/i.test(r.DEDICATORIA || '');
                    var label = esHermandad
                        ? [r.DEDICATORIA, r.LOCALIDAD, r.PROVINCIA].filter(Boolean).join(' - ')
                        : r.DEDICATORIA;
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'suggest-item';
                    b.textContent = label;
                    b.addEventListener('click', function () {
                        input.value = r.DEDICATORIA;
                        if (esHermandad && r.LOCALIDAD) {
                            var form = document.getElementById('aceptarForm');
                            if (form && typeof form.municipioSetValue === 'function') {
                                form.municipioSetValue(r.PROVINCIA || '', r.LOCALIDAD);
                            }
                        }
                        closeSuggest();
                        input.focus();
                    });
                    suggest.appendChild(b);
                });
                suggest.hidden = false;
            } catch (e) { /* abortado o red: ignorar */ }
        }, 200);
    });

    document.addEventListener('mousedown', function (e) {
        if (!suggest.contains(e.target) && e.target !== input) closeSuggest();
    });
})();

// Estilo CCTT/AM: se sugiere automáticamente al cargar (PHP) y se actualiza
// cuando el revisor cambia el ID de banda de estreno. Solo actualiza si el
// revisor no ha tocado el select manualmente.
(function () {
    var bandaInput = document.getElementById('BANDA_ESTRENO');
    var estiloSelect = document.getElementById('ESTILO');
    var estiloHint = document.getElementById('estiloHint');
    if (!bandaInput || !estiloSelect) return;

    var autoModified = false;
    estiloSelect.addEventListener('change', function () { autoModified = true; });

    async function fetchEstilo(bandaId) {
        if (!bandaId) return;
        try {
            var res = await fetch('/api/banda/estilo?id=' + encodeURIComponent(bandaId), { credentials: 'same-origin' });
            var data = await res.json();
            if (!autoModified) {
                estiloSelect.value = data.estilo || '';
                if (estiloHint) {
                    estiloHint.textContent = data.estilo
                        ? 'Sugerido automáticamente según las marchas existentes de esta banda.'
                        : 'No hay marchas previas de esta banda; selecciónalo manualmente.';
                }
            }
        } catch (e) { /* red: ignorar */ }
    }

    bandaInput.addEventListener('change', function () { fetchEstilo(bandaInput.value.trim()); });
    bandaInput.addEventListener('blur', function () { fetchEstilo(bandaInput.value.trim()); });
})();
</script>
