<?php use App\View as V; use App\Auth; use App\Html as H; use App\Slug as S; use App\Media;
/** @var array $session @var array<string,mixed> $disco @var list<array<string,mixed>> $pistas
 *  @var bool $portada @var string|null $error @var string|null $notice */
$csrf = Auth::csrfToken($session);
$id = (int) $disco['ID_DISCO'];
$val = static fn(string $k): string => V::e((string) ($disco[$k] ?? ''));
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
$maxMb = (int) round(Media::PORTADA_MAX_BYTES / 1024 / 1024);
// El nº de volúmenes NO es un campo del disco: sale de la pista más alta
// (AdminRepo::discoConPistas), igual que en la ficha pública.
$volumenes = max(1, (int) ($disco['VOLUMENES'] ?? 1));
$multi = $volumenes > 1;

// Siguiente número libre: sugerencia, no imposición — las pistas no tienen por
// qué ser consecutivas (un disco puede llevar cortes que no son marchas).
$ocupadas = array_map(static fn(array $p): int => (int) $p['NUMEROMARCHA'], $pistas);
$sugerido = $ocupadas === [] ? 1 : max($ocupadas) + 1;

$erroresLegibles = [
    'MARCHA_YA_EN_DISCO' => 'Esa marcha ya está en el disco.',
    'PISTA_OCUPADA' => 'Ya hay una marcha con ese número de pista en ese volumen.',
    'MARCHA_NO_EXISTE' => 'No existe ninguna marcha con ese identificador.',
    'PISTA_INVALIDA' => 'El número de pista debe estar entre 1 y 999.',
    'VOLUMEN_INVALIDO' => 'El volumen debe estar entre 1 y 99.',
    'INVALID_FECHA' => 'El año debe tener cuatro cifras.',
    'BANDA_NO_EXISTE' => 'La banda seleccionada no existe.',
    'PORTADA_NO_ES_IMAGEN' => 'El fichero de portada no es una imagen válida.',
    'PORTADA_DEMASIADO_GRANDE' => 'La portada supera el tamaño máximo (' . $maxMb . ' MB).',
    'PORTADA_DEMASIADO_PEQUENA' => 'La portada es demasiado pequeña (mínimo 50×50 px).',
    'PORTADA_DIR_NO_ESCRIBIBLE' => 'No se puede escribir en la carpeta de portadas del servidor.',
    'PORTADA_ESCRITURA_FALLIDA' => 'No se pudo guardar la portada en el servidor.',
    'PORTADA_SUBIDA_FALLIDA' => 'La subida de la portada falló. Inténtalo de nuevo.',
    'CSRF' => 'La sesión ha caducado. Vuelve a intentarlo.',
];
$errorMsg = $error !== null ? ($erroresLegibles[$error] ?? ('Error: ' . $error)) : null;
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Disco #<?= $id ?></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('disco', $id, (string) $disco['NOMBRE_CD'])) ?>">Ver ficha pública ↗</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($errorMsg): ?><div class="alert alert-error"><?= V::e($errorMsg) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= V::e($notice) ?></div><?php endif; ?>

    <?php /* ── Datos del disco ─────────────────────────────────────────── */ ?>
    <form class="panel" action="/dashboard/disco/<?= $id ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

        <div class="field">
            <label class="field-label" for="NOMBRE_CD">Nombre del disco</label>
            <input class="input" id="NOMBRE_CD" name="NOMBRE_CD" type="text" value="<?= $val('NOMBRE_CD') ?>" required>
        </div>

        <div class="field">
            <label class="field-label" for="FECHA_CD">Año de edición</label>
            <input class="input" id="FECHA_CD" name="FECHA_CD" type="number" min="1900" max="2100" value="<?= $val('FECHA_CD') ?>">
        </div>

        <div class="field autocomplete" data-banda-picker>
            <label class="field-label" for="bandaBuscar">Banda propietaria</label>
            <input class="input" id="bandaBuscar" type="text" autocomplete="off"
                   placeholder="Escribe para buscar una banda…"
                   value="<?= $disco['BANDADISCO'] ? V::e(($disco['BANDA_BREVE'] ?? '') . ' (#' . (int) $disco['BANDADISCO'] . ')') : '' ?>"
                   data-banda-input>
            <input type="hidden" name="BANDADISCO" value="<?= $val('BANDADISCO') ?>" data-banda-id>
            <div class="suggest" hidden data-banda-suggest></div>
        </div>

        <div class="field">
            <label class="field-label" for="d_DETALLES">Notas</label>
            <textarea class="input" id="d_DETALLES" name="d_DETALLES" rows="3"><?= $val('d_DETALLES') ?></textarea>
        </div>

        <div class="field">
            <label class="field-label" for="portada">Portada</label>
<?php if ($portada): ?>
            <p class="muted small">Portada actual — sube otra imagen para reemplazarla.</p>
            <img class="cover-thumb" src="<?= V::e(H::coverSrc($id)) ?>?v=<?= time() ?>" alt="Portada actual del disco" width="128" height="128">
<?php else: ?>
            <p class="muted small">Este disco todavía no tiene portada.</p>
<?php endif; ?>
            <input class="input" id="portada" name="portada" type="file" accept="image/png,image/jpeg,image/webp">
            <p class="muted small field-help">PNG, JPG o WebP, hasta <?= $maxMb ?> MB. Se recorta al cuadrado y se guarda como <code class="mono">/cover/<?= $id ?>.png</code>.</p>
        </div>

        <div><button class="btn btn-neutral" type="submit">Guardar cambios</button></div>
    </form>

    <?php /* ── Añadir marchas al disco ──────────────────────────────────── */ ?>
    <div class="shead"><h2>Añadir marchas</h2>
        <span class="n"><?= count($pistas) === 1 ? '1 pista' : $num(count($pistas)) . ' pistas' ?></span>
    </div>

    <form class="panel" action="/dashboard/disco/<?= $id ?>/pista" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

        <div class="field autocomplete" data-marcha-picker>
            <label class="field-label" for="marchaBuscar">Marcha</label>
            <input class="input" id="marchaBuscar" type="text" autocomplete="off"
                   placeholder="Identificador (p. ej. 330) o parte del título…" data-marcha-input required>
            <input type="hidden" name="idMarcha" value="" data-marcha-id>
            <div class="suggest" hidden data-marcha-suggest></div>
            <p class="muted small field-help">Busca por el <strong>identificador exacto</strong> o por el título. Escribe al menos 3 letras (o el número).</p>
        </div>

        <div class="form-grid">
            <div class="field">
                <label class="field-label" for="numero">Número de pista</label>
                <input class="input" id="numero" name="numero" type="number" min="1" max="999" value="<?= $sugerido ?>" required data-pista-numero>
                <p class="muted small field-help">No tiene por qué ser consecutivo: se propone el siguiente libre, pero puedes poner cualquiera.</p>
            </div>
            <div class="field">
                <label class="field-label" for="nDisco">Volumen</label>
                <input class="input" id="nDisco" name="nDisco" type="number" min="1" max="99" value="1" data-pista-volumen>
                <p class="muted small field-help">1 salvo que sea un doble o una caja. El disco muestra
                    tantos volúmenes como el más alto que uses aquí.</p>
            </div>
        </div>

        <?php /* Vista previa: qué se va a añadir exactamente, antes de enviar. */ ?>
        <div class="panel-previa" hidden data-pista-previa>
            <span class="previa-et">Se añadirá</span>
            <span class="previa-num" data-previa-num>pista <?= $sugerido ?></span>
            <span class="previa-titulo" data-previa-titulo></span>
            <span class="muted small" data-previa-sub></span>
        </div>

        <div><button class="btn btn-neutral" type="submit">Añadir al disco</button></div>
    </form>

    <?php /* ── Contenido actual + vista previa de la ficha ──────────────── */ ?>
    <div class="shead"><h2>Contenido del disco</h2></div>
<?php if ($pistas === []): ?>
    <p class="bio-empty">Todavía no hay ninguna marcha en este disco.</p>
<?php else: ?>
    <div class="scrollx tableList">
    <table class="reg">
        <thead><tr>
<?php if ($multi): ?>
            <th class="num">Vol.</th>
<?php endif; ?>
            <th class="num">Pista</th>
            <th>Marcha</th>
            <th>Compositor</th>
            <th class="num">Año</th>
            <th></th>
        </tr></thead>
        <tbody>
<?php foreach ($pistas as $p): ?>
            <tr>
<?php if ($multi): ?>
                <td class="num"><?= (int) $p['N_DISCO'] ?></td>
<?php endif; ?>
                <td class="num"><?= (int) $p['NUMEROMARCHA'] ?></td>
                <td>
<?php if ($p['TITULO'] !== null): ?>
                    <a href="/dashboard/marcha/<?= (int) $p['IDMARCHA'] ?>"><?= V::e($p['TITULO']) ?></a>
                    <span class="muted small">#<?= (int) $p['IDMARCHA'] ?></span>
<?php else: ?>
                    <span class="badge badge-warn">Marcha #<?= (int) $p['IDMARCHA'] ?> no existe</span>
<?php endif; ?>
                </td>
                <td><?= $p['AUTORES'] !== null ? V::e($p['AUTORES']) : '<span class="muted">—</span>' ?></td>
                <td class="num"><?= $p['FECHA'] !== null && $p['FECHA'] !== '' ? V::e((string) $p['FECHA']) : '—' ?></td>
                <td>
                    <form class="inline-form" action="/dashboard/disco/<?= $id ?>/pista/<?= (int) $p['ID_DM'] ?>/borrar" method="POST"
                          onsubmit="return confirm('¿Quitar esta marcha del disco?');">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Quitar</button>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

    <div class="shead"><h2>Vista previa de la ficha</h2>
        <span class="n">así se verá en la web</span>
    </div>
    <div class="previa-ficha">
        <article class="record" style="margin:0;">
            <div class="disco-head">
                <figure class="disco-cover">
<?php if ($portada): ?>
                    <img class="cover-large" src="<?= V::e(H::coverSrc($id)) ?>?v=<?= time() ?>" alt="Portada del disco" width="256" height="256">
<?php else: ?>
                    <div class="cover-vacia">sin portada</div>
<?php endif; ?>
                </figure>
                <div class="disco-meta">
                    <h1><?= $val('NOMBRE_CD') !== '' ? $val('NOMBRE_CD') : '<span class="muted">(sin nombre)</span>' ?></h1>
                    <dl class="desc">
<?php if ($disco['BANDADISCO']): ?>
                        <div class="f"><dt>Banda</dt><dd><?= V::e((string) ($disco['BANDA_BREVE'] ?? ('#' . (int) $disco['BANDADISCO']))) ?></dd></div>
<?php endif; ?>
<?php if ($val('FECHA_CD') !== ''): ?>
                        <div class="f"><dt>Año</dt><dd><?= $val('FECHA_CD') ?></dd></div>
<?php endif; ?>
                        <div class="f"><dt>Pistas</dt><dd><?= $num(count($pistas)) ?></dd></div>
<?php if ($multi): ?>
                        <div class="f"><dt>Volúmenes</dt><dd><?= $volumenes ?></dd></div>
<?php endif; ?>
                    </dl>
                </div>
            </div>
<?php if ($val('d_DETALLES') !== ''): ?>
            <div class="shead"><h2>Notas</h2></div>
            <p class="notas"><?= nl2br($val('d_DETALLES')) ?></p>
<?php endif; ?>
        </article>
    </div>
</div>
<script src="/assets/disco.js" defer></script>
