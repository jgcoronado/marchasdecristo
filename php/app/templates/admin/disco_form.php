<?php use App\View as V; use App\Auth; use App\Html as H; use App\Slug as S; use App\Media; use App\EnlaceRepo;
/** @var array $session @var array<string,mixed> $disco @var list<array<string,mixed>> $pistas
 *  @var bool $portada @var string|null $error @var string|null $notice
 *  @var array<string,string> $enlaces @var string $tab */
$csrf = Auth::csrfToken($session);
$enlaces = $enlaces ?? [];
$tab = $tab ?? 'datos';
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
    'PISTA_NO_EXISTE' => 'Esa pista ya no existe (puede que la hayan quitado).',
    'MARCHA_NO_EXISTE' => 'No existe ninguna marcha con ese identificador.',
    'PISTA_INVALIDA' => 'El número de pista debe estar entre 1 y 999.',
    'VOLUMEN_INVALIDO' => 'El volumen debe estar entre 1 y 99.',
    'INVALID_FECHA' => 'El año debe tener cuatro cifras.',
    'INVALID_PERCUSION_SEG' => 'La introducción de percusión debe durar entre 5 y 180 segundos.',
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

    <div class="row tabs" role="tablist" style="gap:0.5rem;margin-bottom:0.75rem">
<?php foreach ([['datos', 'Datos'], ['pistas', 'Pistas'], ['streaming', 'Streaming']] as [$k, $etiqueta]): ?>
        <button type="button" class="btn btn-sm <?= $tab === $k ? 'btn-neutral' : 'btn-ghost' ?> tab-btn"
                data-tab="<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= $etiqueta ?></button>
<?php endforeach; ?>
    </div>

    <div data-tab-panel="datos"<?= $tab !== 'datos' ? ' hidden' : '' ?>>
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

        <?php /* Intro de percusión: muchas grabaciones abren con ~40 s de tambores
                 antes de la marcha. Marcarlo aquí evita que esas tomas inflen la
                 mediana de duración de la obra. El hidden a 0 garantiza que el
                 campo llegue siempre, también cuando se desmarca. */ ?>
        <div class="field">
            <input type="hidden" name="PERCUSION" value="0">
            <label class="field-label">
                <input type="checkbox" name="PERCUSION" value="1"
                       <?= (int) ($disco['PERCUSION'] ?? 0) === 1 ? 'checked' : '' ?>
                       data-percusion-toggle>
                Las pistas empiezan con introducción de percusión
            </label>
            <p class="muted small field-help">Márcalo si el disco abre sus pistas con un fragmento de
                tambores antes de la marcha. Se descuenta al calcular la duración de referencia de cada
                marcha, y la ficha pública lo señala con 🥁.</p>
        </div>
        <div class="field">
            <label class="field-label" for="PERCUSION_SEG">Duración de esa introducción (segundos)</label>
            <input class="input" id="PERCUSION_SEG" name="PERCUSION_SEG" type="number" min="5" max="180"
                   value="<?= (int) ($disco['PERCUSION_SEG'] ?? 40) ?>">
            <p class="muted small field-help">Por defecto <strong>40</strong>, que es la mejor estimación
                sin cronometrarla (suelen durar entre 37 y 42 s). Si mides una de verdad, ponla aquí y
                deja de ser una estimación.</p>
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
    </div><?php /* fin pestaña "datos" */ ?>

    <div data-tab-panel="pistas"<?= $tab !== 'pistas' ? ' hidden' : '' ?>>
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
            <div class="field">
                <label class="field-label" for="duracion">Duración (mm:ss)</label>
                <input class="input" id="duracion" name="duracion" type="text" inputmode="numeric"
                       placeholder="p. ej. 3:45" pattern="^(?:\d+:)?[0-5]?\d:[0-5]\d$">
                <p class="muted small field-help">Opcional. Es la duración de <strong>esta</strong> grabación
                    concreta, no la de la obra — puede variar entre discos.</p>
            </div>
            <div class="field">
                <label class="field-label" for="percusion">Introducción de percusión</label>
                <select class="input" id="percusion" name="percusion">
                    <option value="heredar" selected>Como el disco<?= (int) ($disco['PERCUSION'] ?? 0) === 1 ? ' (con 🥁)' : ' (sin 🥁)' ?></option>
                    <option value="1">Sí, esta pista lleva</option>
                    <option value="0">No, esta pista no lleva</option>
                </select>
                <p class="muted small field-help">Déjalo en «como el disco» salvo que esta pista concreta
                    se salga de la norma.</p>
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
            <th class="num">Duración</th>
            <th></th>
        </tr></thead>
        <tbody>
<?php foreach ($pistas as $p): $pid = (int) $p['ID_DM']; $durVal = !empty($p['DURACION_SEG']) ? gmdate('i:s', (int) $p['DURACION_SEG']) : ''; ?>
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
                <?php /* 🥁 = esta pista arranca con intro de percusión. Sale del flag
                         del disco salvo que la pista tenga excepción propia. */
                    $percEfectiva = $p['PERCUSION_PISTA'] !== null
                        ? (int) $p['PERCUSION_PISTA']
                        : (int) ($disco['PERCUSION'] ?? 0);
                ?>
                <td class="num"><?= !empty($p['DURACION_SEG']) ? gmdate('i:s', (int) $p['DURACION_SEG']) : '—' ?><?php
                    if ($percEfectiva === 1): ?><span class="perc" title="<?= $p['PERCUSION_PISTA'] !== null ? 'Excepción de esta pista' : 'Por el ajuste del disco' ?>">🥁</span><?php
                    elseif ($p['PERCUSION_PISTA'] !== null): ?><span class="muted small" title="Excepción: esta pista no lleva intro aunque el disco sí"> ·sin🥁</span><?php endif; ?></td>
                <td>
                    <div class="row">
                        <button class="btn btn-sm btn-ghost" type="button" data-pista-editar="<?= $pid ?>">Editar</button>
                        <form class="inline-form" action="/dashboard/disco/<?= $id ?>/pista/<?= $pid ?>/borrar" method="POST"
                              onsubmit="return confirm('¿Quitar esta marcha del disco?');">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Quitar</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php /* Fila de edición rápida: oculta salvo que se pulse "Editar". Cubre
                     los tres despistes típicos al capturar una carátula: la marcha no
                     es, el número de pista está mal, o falta/sobra la duración. */ ?>
            <tr class="pista-edit-row" hidden data-pista-edit-row="<?= $pid ?>">
                <td colspan="<?= $multi ? 7 : 6 ?>">
                    <form class="stack" action="/dashboard/disco/<?= $id ?>/pista/<?= $pid ?>/editar" method="POST">
                        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

                        <div class="field autocomplete" data-marcha-picker-edit>
                            <label class="field-label">Marcha</label>
                            <input class="input" type="text" autocomplete="off"
                                   value="<?= $p['TITULO'] !== null ? V::e($p['TITULO'] . ' (#' . (int) $p['IDMARCHA'] . ')') : V::e('#' . (int) $p['IDMARCHA']) ?>"
                                   placeholder="Identificador (p. ej. 330) o parte del título…" data-marcha-input required>
                            <input type="hidden" name="idMarcha" value="<?= (int) $p['IDMARCHA'] ?>" data-marcha-id>
                            <div class="suggest" hidden data-marcha-suggest></div>
                        </div>

                        <div class="form-grid">
                            <div class="field">
                                <label class="field-label">Número de pista</label>
                                <input class="input" name="numero" type="number" min="1" max="999" value="<?= (int) $p['NUMEROMARCHA'] ?>" required>
                            </div>
                            <div class="field">
                                <label class="field-label">Volumen</label>
                                <input class="input" name="nDisco" type="number" min="1" max="99" value="<?= (int) $p['N_DISCO'] ?>">
                            </div>
                            <div class="field">
                                <label class="field-label">Duración (mm:ss)</label>
                                <input class="input" name="duracion" type="text" inputmode="numeric"
                                       placeholder="p. ej. 3:45" pattern="^(?:\d+:)?[0-5]?\d:[0-5]\d$" value="<?= V::e($durVal) ?>">
                            </div>
                            <?php /* Excepción por pista al flag del disco. Lo normal es
                                     "heredar"; solo se toca en discos mixtos. */ ?>
                            <div class="field">
                                <label class="field-label">Introducción de percusión</label>
                                <select class="input" name="percusion">
                                    <option value="heredar" <?= $p['PERCUSION_PISTA'] === null ? 'selected' : '' ?>>Como el disco<?= (int) ($disco['PERCUSION'] ?? 0) === 1 ? ' (con 🥁)' : ' (sin 🥁)' ?></option>
                                    <option value="1" <?= (int) $p['PERCUSION_PISTA'] === 1 && $p['PERCUSION_PISTA'] !== null ? 'selected' : '' ?>>Sí, esta pista lleva</option>
                                    <option value="0" <?= $p['PERCUSION_PISTA'] !== null && (int) $p['PERCUSION_PISTA'] === 0 ? 'selected' : '' ?>>No, esta pista no lleva</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <button class="btn btn-sm btn-neutral" type="submit">Guardar cambios</button>
                            <button class="btn btn-sm btn-ghost" type="button" data-pista-editar-cancelar="<?= $pid ?>">Cancelar</button>
                        </div>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

    </div><?php /* fin pestaña "pistas" */ ?>

    <div data-tab-panel="streaming"<?= $tab !== 'streaming' ? ' hidden' : '' ?>>
    <?php /* ── Enlaces de streaming del disco ───────────────────────────── */ ?>
    <div class="shead"><h2>Enlaces de streaming</h2>
        <span class="n"><?= count($enlaces) === 1 ? '1 enlace' : $num(count($enlaces)) . ' enlaces' ?></span>
    </div>
    <p class="muted small">Vincula este álbum en cada servicio. Vacío = sin enlace (se borra el que hubiera).
        Se escribe directo en <code class="mono">enlace_streaming</code>, al margen de la cola de candidatos.</p>

    <form class="panel" action="/dashboard/disco/<?= $id ?>/social" method="POST">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach (EnlaceRepo::SERVICIOS as $servicio): ?>
        <div class="field">
            <label class="field-label" for="social_<?= $servicio ?>"><?= V::e(H::STREAMING_LABELS[$servicio] ?? ucfirst($servicio)) ?></label>
            <input class="input" id="social_<?= $servicio ?>" name="<?= $servicio ?>" type="url" placeholder="https://…" value="<?= V::e($enlaces[$servicio] ?? '') ?>">
        </div>
<?php endforeach; ?>
        <div><button class="btn btn-neutral" type="submit">Guardar enlaces</button></div>
    </form>
    </div><?php /* fin pestaña "streaming" */ ?>
</div>
<script src="/assets/disco.js" defer></script>
<script>
(function () {
    var btns = Array.from(document.querySelectorAll('.tab-btn'));
    var panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            btns.forEach(function (b) {
                b.classList.toggle('btn-neutral', b === btn);
                b.classList.toggle('btn-ghost', b !== btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.hidden = p.dataset.tabPanel !== btn.dataset.tab;
            });
            // La pestaña activa viaja en la URL: así un F5 o el PRG de los
            // formularios (?tab=…) devuelve al usuario donde estaba.
            var u = new URL(window.location.href);
            u.searchParams.set('tab', btn.dataset.tab);
            history.replaceState(null, '', u);
        });
    });
})();
</script>
