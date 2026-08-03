<?php use App\View as V; use App\Auth; use App\ImportadorPistas; use App\Tracklist;
/** @var array $session @var array<string,mixed> $disco @var list<array<string,mixed>> $pistas
 *  @var string $fase  'url' | 'revision'
 *  @var string $url @var string|null $servicio @var list<array<string,mixed>> $filas
 *  @var string|null $error @var bool $creado */
$csrf = Auth::csrfToken($session);
$id = (int) $disco['ID_DISCO'];
$mmss = static fn(?int $s): string => $s === null || $s <= 0 ? '' : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
$pct = static fn(float $s): string => number_format($s * 100, 0) . '%';
$percusionDisco = (int) ($disco['PERCUSION'] ?? 0) === 1;
$anioDisco = trim((string) ($disco['FECHA_CD'] ?? ''));
$bandaDisco = (int) ($disco['BANDADISCO'] ?? 0);

$erroresLegibles = [
    'URL_NO_RECONOCIDA' => 'No reconozco ese enlace. Tiene que ser la página del álbum en Spotify, Apple Music o Deezer.',
    'SPOTIFY_SIN_CREDENCIALES' => 'Este servidor no tiene credenciales de Spotify configuradas (spotify_client_id / spotify_client_secret en config.local.php). Prueba con el enlace de Apple Music o Deezer del mismo disco, que no necesitan credenciales.',
    'SIN_PISTAS' => 'El servicio no ha devuelto ninguna pista para ese álbum. Comprueba el enlace, o inténtalo de nuevo en un momento.',
    'CSRF' => 'La sesión ha caducado. Vuelve a intentarlo.',
];
$errorMsg = $error !== null ? ($erroresLegibles[$error] ?? ('Error: ' . $error)) : null;

// Resumen del análisis: lo que se va a añadir, lo que no se ha reconocido y lo
// que ya estaba en el disco. Es lo primero que tiene que ver el usuario.
$reconocidas = array_values(array_filter($filas, static fn(array $f): bool => $f['estado'] === 'reconocida'));
$sinCoincidencia = array_values(array_filter($filas, static fn(array $f): bool => $f['estado'] === 'sin_coincidencia'));
$yaEnDisco = array_values(array_filter($filas, static fn(array $f): bool => $f['estado'] === 'ya_en_disco'));
$duplicadas = array_values(array_filter($filas, static fn(array $f): bool => $f['estado'] === 'duplicada'));

// Vuelta desde el alta de marcha: el importador no guarda estado, así que se
// vuelve al paso 1 con el enlace puesto y basta con reanalizar.
$nueva = isset($_GET['nueva']) ? (int) $_GET['nueva'] : 0;
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Importar pistas · disco #<?= $id ?></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard/disco/<?= $id ?>?tab=pistas">Añadir a mano →</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($errorMsg): ?><div class="alert alert-error"><?= V::e($errorMsg) ?></div><?php endif; ?>
<?php if ($creado): ?><div class="alert alert-success">Disco creado. Ya puedes añadirle las marchas.</div><?php endif; ?>
<?php if ($nueva > 0): ?>
    <div class="alert alert-success">Marcha #<?= $nueva ?> creada. Vuelve a analizar el enlace para que la reconozca.</div>
<?php endif; ?>

    <p class="muted">
        <strong><?= V::e((string) $disco['NOMBRE_CD']) ?></strong>
<?php if ($anioDisco !== ''): ?> · <?= V::e($anioDisco) ?><?php endif; ?>
<?php if (!empty($disco['BANDA_BREVE'])): ?> · <?= V::e((string) $disco['BANDA_BREVE']) ?><?php endif; ?>
        · <?= count($pistas) === 1 ? '1 pista ya en el disco' : count($pistas) . ' pistas ya en el disco' ?>
    </p>

<?php if ($fase === 'url'): ?>
    <?php /* ── Paso 1: el enlace del álbum ──────────────────────────────── */ ?>
    <form class="panel" action="/dashboard/disco/<?= $id ?>/importar" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

        <div class="field">
            <label class="field-label" for="url">Enlace del álbum en un servicio de música</label>
            <input class="input" id="url" name="url" type="url" required autofocus
                   placeholder="https://open.spotify.com/album/…"
                   value="<?= V::e($url !== '' ? $url : (string) ($_GET['url'] ?? '')) ?>">
            <p class="muted small field-help">Vale el enlace que la banda comparte en sus redes, siempre que
                apunte al <strong>álbum</strong> (no a una pista suelta) en
                <strong>Spotify</strong>, <strong>Apple Music</strong> o <strong>Deezer</strong>. De ahí salen
                el orden de las pistas, sus títulos y su duración; después revisas marcha a marcha antes de
                guardar nada.
<?php if (!Tracklist::disponible('spotify')): ?>
                <br>⚠ Spotify no está configurado en este servidor: usa el enlace de Apple Music o Deezer.
<?php endif; ?>
            </p>
        </div>

        <div class="row">
            <button class="btn btn-neutral" type="submit">Analizar el enlace</button>
            <a class="btn btn-ghost" href="/dashboard/disco/<?= $id ?>?tab=pistas">Prefiero añadirlas a mano</a>
        </div>
    </form>

<?php else: ?>
    <?php /* ── Paso 2: revisión del plan ────────────────────────────────── */ ?>
    <div class="alert <?= $sinCoincidencia === [] ? 'alert-success' : 'alert-info' ?>">
        <?= count($filas) ?> pistas leídas en <?= V::e(ucfirst((string) $servicio)) ?>:
        <strong><?= count($reconocidas) ?></strong> reconocidas
        (coincidencia ≥ <?= (int) round(ImportadorPistas::UMBRAL * 100) ?>%),
        <strong><?= count($sinCoincidencia) ?></strong> sin reconocer<?php
        if ($duplicadas !== []): ?>, <strong><?= count($duplicadas) ?></strong> repetidas<?php endif;
        if ($yaEnDisco !== []): ?>, <strong><?= count($yaEnDisco) ?></strong> ya en el disco<?php endif; ?>.
    </div>

<?php if ($sinCoincidencia !== []): ?>
    <div class="alert alert-error">
        <p><strong>Sin coincidencia en el catálogo</strong> — o son marchas que aún no existen, o están
            escritas de otra forma. Crea las que falten (vuelves aquí al terminar) o búscalas a mano en su fila:</p>
        <ul>
<?php foreach ($sinCoincidencia as $f): ?>
            <li>
                Pista <?= (int) $f['n'] ?>: «<?= V::e($f['titulo']) ?>»
<?php if ($f['sugerencia'] !== null): ?>
                <span class="muted small">· lo más parecido: «<?= V::e($f['sugerencia']['titulo']) ?>»
                    (<?= $pct((float) $f['sugerencia']['score']) ?>)</span>
<?php endif; ?>
            </li>
<?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

    <form class="panel" action="/dashboard/disco/<?= $id ?>/importar/confirmar" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="url" value="<?= V::e($url) ?>">

        <div class="scrollx tableList">
        <table class="reg">
            <thead><tr>
                <th></th>
                <th class="num">Pista</th>
                <th class="num">Vol.</th>
                <th>En el álbum</th>
                <th class="num">Duración</th>
                <th>Marcha del catálogo</th>
                <th>🥁</th>
            </tr></thead>
            <tbody>
<?php foreach ($filas as $f): $i = (int) $f['idx']; $marcada = $f['estado'] === 'reconocida' && !$f['ocupada']; ?>
                <tr>
                    <td>
                        <input type="hidden" name="p[<?= $i ?>][titulo]" value="<?= V::e($f['titulo']) ?>">
                        <input type="hidden" name="p[<?= $i ?>][seg]" value="<?= (int) ($f['seg'] ?? 0) ?>">
                        <input type="checkbox" name="p[<?= $i ?>][add]" value="1" <?= $marcada ? 'checked' : '' ?>
                               aria-label="Añadir esta pista al disco" data-import-add>
                    </td>
                    <td class="num"><input class="input input-num" name="p[<?= $i ?>][numero]" type="number"
                                           min="1" max="999" value="<?= (int) $f['n'] ?>" style="width:5rem"></td>
                    <td class="num"><input class="input input-num" name="p[<?= $i ?>][volumen]" type="number"
                                           min="1" max="99" value="<?= (int) $f['volumen'] ?>" style="width:4.5rem"></td>
                    <td>
                        <?= V::e($f['titulo']) ?>
<?php if ($f['ocupada']): ?>
                        <br><span class="badge badge-warn">Ya hay una pista con ese número en ese volumen</span>
<?php endif; ?>
                    </td>
                    <td class="num"><?= $mmss($f['seg']) !== '' ? V::e($mmss($f['seg'])) : '—' ?></td>
                    <td>
                        <?php /* Mismo buscador que la edición de pistas: disco.js
                                 cablea todos los [data-marcha-picker-edit] de la página. */ ?>
                        <div class="autocomplete" data-marcha-picker-edit>
                            <input class="input" type="text" autocomplete="off"
                                   placeholder="Identificador o parte del título…"
                                   value="<?= $f['marcha'] !== null ? V::e($f['marcha']['TITULO'] . ' (#' . (int) $f['marcha']['ID_MARCHA'] . ')') : '' ?>"
                                   data-marcha-input>
                            <input type="hidden" name="p[<?= $i ?>][idMarcha]"
                                   value="<?= $f['idMarcha'] !== null ? (int) $f['idMarcha'] : '' ?>" data-marcha-id>
                            <div class="suggest" hidden data-marcha-suggest></div>
                        </div>
<?php if ($f['estado'] === 'reconocida'): ?>
                        <span class="muted small">coincidencia <?= $pct((float) $f['score']) ?><?php
                            if (!empty($f['marcha']['AUTORES'])): ?> · <?= V::e((string) $f['marcha']['AUTORES']) ?><?php endif; ?></span>
<?php elseif ($f['estado'] === 'ya_en_disco'): ?>
                        <span class="badge badge-warn">Ya está en el disco</span>
<?php elseif ($f['estado'] === 'duplicada'): ?>
                        <span class="badge badge-warn">Otra pista ya se lleva «<?= V::e((string) $f['sugerencia']['titulo']) ?>»</span>
                        <span class="muted small">una marcha solo puede estar una vez en el disco</span>
<?php else: ?>
                        <?php
                        // Alta de la marcha que falta, con lo que ya sabemos del
                        // disco. El resto (autor, sobre todo) lo pone el usuario:
                        // ninguna API de streaming devuelve el compositor.
                        $qs = ['TITULO' => $f['titulo'], 'TIPO' => 'MARCHA PROCESIONAL',
                               'volver' => '/dashboard/disco/' . $id . '/importar?url=' . rawurlencode($url)];
                        if ($anioDisco !== '') $qs['FECHA'] = $anioDisco;
                        if ($bandaDisco > 0) $qs['BANDA_ESTRENO'] = (string) $bandaDisco;
                        ?>
                        <a class="btn btn-sm btn-ghost" href="/dashboard/marcha/add?<?= V::e(http_build_query($qs)) ?>">Crear esta marcha</a>
                        <span class="muted small">sin coincidencia<?php
                            if ($f['sugerencia'] !== null): ?> · lo más parecido, <?= $pct((float) $f['sugerencia']['score']) ?><?php endif; ?></span>
<?php endif; ?>
                    </td>
                    <td>
                        <select class="input" name="p[<?= $i ?>][percusion]" aria-label="Introducción de percusión">
                            <option value="heredar" selected>Como el disco<?= $percusionDisco ? ' (con 🥁)' : ' (sin 🥁)' ?></option>
                            <option value="1">Sí, lleva</option>
                            <option value="0">No lleva</option>
                        </select>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="field">
            <label class="field-label">
                <input type="checkbox" name="guardarEnlace" value="1" checked>
                Guardar también este enlace como enlace de streaming del disco
            </label>
        </div>

        <div class="row">
            <button class="btn btn-neutral" type="submit">Añadir las pistas marcadas</button>
            <button class="btn btn-ghost" type="button" data-import-todas>Marcar / desmarcar todas</button>
            <a class="btn btn-ghost" href="/dashboard/disco/<?= $id ?>/importar">Empezar de nuevo</a>
        </div>
        <p class="muted small">Las pistas se añaden en orden. La duración es la de <strong>esta grabación</strong>;
            marca 🥁 en las que empiecen con introducción de tambores si se desvían de lo que diga el disco.</p>
    </form>
<?php endif; ?>
</div>
<script src="/assets/disco.js" defer></script>
<script>
(function () {
    var btn = document.querySelector('[data-import-todas]');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cajas = Array.from(document.querySelectorAll('[data-import-add]'));
        var encender = cajas.some(function (c) { return !c.checked; });
        cajas.forEach(function (c) { c.checked = encender; });
    });
})();
</script>
