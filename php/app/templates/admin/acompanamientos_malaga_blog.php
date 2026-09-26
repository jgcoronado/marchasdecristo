<?php use App\View as V; use App\Auth; use App\MalagaBlogImporter as M;
/** @var array $session @var string $url @var array|null $a @var array|null $notice @var bool $local @var list<array<string,mixed>> $hermandades @var list<array<string,mixed>> $pasos */
$csrf = Auth::csrfToken($session);

/** "1998, 1999, 2000, 2003" → "1998–2000, 2003" */
$rangos = static function (array $anios): string {
    sort($anios);
    $out = [];
    $ini = $prev = null;
    foreach ($anios as $y) {
        if ($prev !== null && $y === $prev + 1) { $prev = $y; continue; }
        if ($ini !== null) $out[] = $ini === $prev ? (string) $ini : "{$ini}–{$prev}";
        $ini = $prev = $y;
    }
    if ($ini !== null) $out[] = $ini === $prev ? (string) $ini : "{$ini}–{$prev}";
    return implode(', ', $out);
};
/** Agrupa filas por paso + banda para enseñar rangos de años en vez de una fila por año. */
$agrupar = static function (array $filas, string $claveBanda) use ($rangos): array {
    $g = [];
    foreach ($filas as $f) {
        $k = $f['paso'] . '|' . $f[$claveBanda];
        $g[$k] ??= ['paso' => $f['paso'], 'banda' => $f[$claveBanda], 'texto' => $f['bandaTexto'], 'anios' => []];
        $g[$k]['anios'][] = (int) $f['anio'];
    }
    return array_map(static fn(array $x): array => $x + ['rango' => $rangos($x['anios']), 'n' => count($x['anios'])], array_values($g));
};

// Valores iniciales del formulario de mapeo.
$mapeo = $a['mapeo'] ?? null;
$pasosMapeo = [];
foreach ((array) ($mapeo['pasos'] ?? []) as $clave => $cfg) $pasosMapeo[M::claveCabecera((string) $clave)] = (array) $cfg;
$slugInicial = (string) ($mapeo['hermandad_slug'] ?? ($a['labelSlug'] ?? ''));
$nombreInicial = (string) ($mapeo['hermandad_nombre'] ?? '');
if ($nombreInicial === '') {
    foreach ($hermandades as $h) if ($h['SLUG'] === $slugInicial) $nombreInicial = (string) $h['NOMBRE'];
}
if ($nombreInicial === '') $nombreInicial = (string) ($a['etiqueta'] ?? '');
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
        (p. ej. <code>https://malagamusical.blogspot.com/search/label/Pollinica</code>).
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
                    <label class="field-label" for="mb_slug">Hermandad (slug)</label>
                    <input class="input" id="mb_slug" name="hermandad_slug" list="mb_hermandades" value="<?= V::e($slugInicial) ?>" required>
                    <span class="field-help muted small">Si ya existe en la nómina de Málaga, usa su slug; si no, se creará con día «Sin asignar».</span>
                </div>
                <div class="field">
                    <label class="field-label" for="mb_nombre">Nombre de la hermandad</label>
                    <input class="input" id="mb_nombre" name="hermandad_nombre" value="<?= V::e($nombreInicial) ?>" required>
                </div>
            </div>
            <datalist id="mb_hermandades">
<?php foreach ($hermandades as $h): ?>
                <option value="<?= V::e($h['SLUG']) ?>"><?= V::e($h['NOMBRE']) ?></option>
<?php endforeach; ?>
            </datalist>
            <datalist id="mb_pasos">
<?php foreach ($pasos as $p): ?>
                <option value="<?= V::e($p['NOMBRE']) ?>"></option>
<?php endforeach; ?>
            </datalist>
            <div class="tableList"><table class="table table-zebra table-sm">
                <thead class="thead-neutral"><tr><td>Bloque del blog</td><td>Qué es</td><td>Nombre del paso</td></tr></thead>
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
                        <td><input class="input" name="pasos[<?= $i ?>][nombre]" list="mb_pasos" value="<?= V::e($nombre) ?>" placeholder="p. ej. Nuestro Padre Jesús de la Soledad"></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="muted small field-help">«Descartar» solo es seguro si en ese bloque no hay CCTT ni AM: si las hay, el análisis las marcará como duda.</p>
            <div><button class="btn btn-neutral" type="submit">Guardar mapeo</button></div>
        </form>
    </details>

<?php if ($a['listo']):
    $contratos = $agrupar($a['nuevosContratos'], 'idBanda');
    $pendientes = $agrupar($a['nuevasPendientes'], 'bandaTexto');
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

    <h3 class="section-title">Contratos a crear</h3>
<?php if ($contratos): ?>
    <div class="tableList"><table class="table table-zebra table-sm">
        <thead class="thead-neutral"><tr><td>Paso</td><td>Años</td><td>Banda en el blog</td><td>Enlazada a</td></tr></thead>
        <tbody>
<?php foreach ($contratos as $g): ?>
            <tr><td><?= V::e($g['paso']) ?></td><td class="nums"><?= V::e($g['rango']) ?></td><td class="small"><?= V::e($g['texto']) ?></td><td class="mono">#<?= (int) $g['banda'] ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php else: ?>
    <p class="muted small">Ninguno.</p>
<?php endif; ?>

    <h3 class="section-title">Quedan como texto (bandas pendientes)</h3>
<?php if ($pendientes): ?>
    <div class="tableList"><table class="table table-zebra table-sm">
        <thead class="thead-neutral"><tr><td>Paso</td><td>Años</td><td>Banda en el blog</td></tr></thead>
        <tbody>
<?php foreach ($pendientes as $g): ?>
            <tr><td><?= V::e($g['paso']) ?></td><td class="nums"><?= V::e($g['rango']) ?></td><td class="small"><?= V::e($g['texto']) ?></td></tr>
<?php endforeach; ?>
        </tbody>
    </table></div>
<?php else: ?>
    <p class="muted small">Ninguna.</p>
<?php endif; ?>

<?php foreach (['dudasBanda' => 'Dudas — banda ambigua (queda como texto)', 'dudasTipo' => 'Dudas — tipo de banda o paso', 'resueltoPorOtraFuente' => 'Ya resuelto por otra fuente', 'lineasNoParseadas' => 'Líneas del blog no reconocidas'] as $clave => $titulo): ?>
<?php if ($a[$clave]): ?>
    <h3 class="section-title"><?= V::e($titulo) ?></h3>
    <ul class="small"><?php foreach ($a[$clave] as $s): ?><li><?= V::e($s) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($a['descartadas']): ?>
    <details>
        <summary class="small muted">Descartadas por tipo de banda (<?= count($a['descartadas']) ?>)</summary>
        <ul class="small muted"><?php foreach ($a['descartadas'] as $d): ?><li><?= V::e($d['paso'] . ' ' . $d['anio'] . ': ' . $d['texto']) ?></li><?php endforeach; ?></ul>
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
