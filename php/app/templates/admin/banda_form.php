<?php use App\View as V; use App\Auth; use App\Slug as S; use App\EnlaceRepo; use App\Html as H;
/** @var array $session @var array<string,mixed> $banda @var string $action
 *  @var list<array<string,mixed>> $relaciones @var list<string> $tipos
 *  @var list<array<string,mixed>> $etapas Repo::bandaEtapas() — con etapas, fundación/extinción se calculan
 *  @var bool $proposalMode @var array|null $notice @var string|null $error
 *  @var array<string,string> $enlaces
 *  @var array<int,list<array<string,mixed>>> $acomp Repo::acompanamientosDeBandaAdmin(), año → filas
 *  @var array<string,array<string,mixed>> $acompNomina NominaRepo::datosAltaBanda() @var list<string> $provincias
 *  @var int $anioAbierto año de la pestaña que se abre (0 = el más reciente) */
$csrf = Auth::csrfToken($session);
$enlaces = $enlaces ?? [];
$acomp = $acomp ?? [];
$acompNomina = $acompNomina ?? [];
$provincias = $provincias ?? [];
$anioAbierto = $anioAbierto ?? 0;
$etapas = $etapas ?? [];
$conEtapas = $etapas !== [];

// Provincia → localidad con el predictivo del catálogo de municipios (admin.js,
// data-municipio-picker), como en bandas y dedicatorias. No se usa
// H::municipioFields() porque fija los id PROVINCIA/LOCALIDAD, que ya usa la
// pestaña «Datos» de esta misma página.
$acompMunicipio = static function (string $pref) use ($provincias): string {
    $opts = '<option value="">— Selecciona una provincia —</option>';
    foreach ($provincias as $pr) $opts .= '<option value="' . V::e($pr) . '">' . V::e($pr) . '</option>';
    return '<div class="field"><label class="field-label" for="' . $pref . 'Provincia">Provincia</label>'
        . '<select class="input" id="' . $pref . 'Provincia" name="PROVINCIA" data-municipio-provincia required>' . $opts . '</select></div>'
        . '<div class="field"><label class="field-label" for="' . $pref . 'Localidad">Localidad</label>'
        . '<div class="autocomplete"><input class="input" id="' . $pref . 'Localidad" name="LOCALIDAD" type="text" autocomplete="off" required data-municipio-localidad>'
        . '<div class="suggest" hidden data-municipio-suggest></div></div></div>';
};
$id = (int) $banda['ID_BANDA'];

// Los años se guardan como "1980.0" en datos heredados; se muestran como año limpio.
$val = static fn(string $k): string => V::e((string) ($banda[$k] ?? ''));

$fields = [
    ['NOMBRE_BREVE', 'Nombre breve', 'text'],
    ['NOMBRE_COMPLETO', 'Nombre completo', 'text'],
    ['FECHA_FUND', 'Fecha de fundación (año)', 'number'],
    ['FECHA_EXT', 'Fecha de extinción (año)', 'number'],
    ['DIRECTOR_ACTUAL', 'Director actual', 'text'],
    ['DIR_MUS_ACTUAL', 'Director musical actual', 'text'],
];

$tipoLabel = [
    'renombrado' => 'Renombrado',
    'fusion'     => 'Fusión',
    'division'   => 'División',
    'juvenil'    => 'Juvenil',
];
// Rol de cada punta según el tipo, para describir la arista en lenguaje natural.
$rolOrigen = ['renombrado' => 'formación anterior', 'fusion' => 'se une', 'division' => 'se divide', 'juvenil' => 'banda madre'];
$rolDestino = ['renombrado' => 'formación nueva', 'fusion' => 'formación resultante', 'division' => 'formación nueva', 'juvenil' => 'banda juvenil'];

/** Etiqueta de una punta: enlace a su panel si es otra banda, o "(esta banda)". */
$punta = static function (?int $bid, ?string $nombre, ?string $loc) use ($id): string {
    $txt = $nombre !== null ? V::e($nombre) . ($loc ? ' <span class="muted small">(' . V::e($loc) . ')</span>' : '') : '<span class="muted">#desconocida</span>';
    if ($bid === $id) return '<strong>' . $txt . '</strong>';
    if ($bid === null) return $txt;
    return '<a href="/dashboard/banda/' . $bid . '">' . $txt . '</a>';
};
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Editar banda <?= V::e($banda['NOMBRE_BREVE']) ?> <span class="muted small">#<?= $id ?></span></h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('banda', $id, (string) $banda['NOMBRE_BREVE'])) ?>" target="_blank">Ver ↗</a>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($proposalMode): ?><div class="alert alert-info">Todo lo que envíes aquí es una <strong>propuesta</strong> que revisará un administrador; no se guarda directamente en la base de datos.
        Los datos de la ficha muestran antes una <strong>previsualización</strong>; etapas, linaje, enlaces y acompañamientos se envían al pulsar su botón.</div><?php endif; ?>

    <div class="row tabs" role="tablist" style="gap:0.5rem;margin-bottom:0.75rem">
        <button type="button" class="btn btn-sm btn-neutral tab-btn" data-tab="datos" aria-selected="true">Datos</button>
        <button type="button" class="btn btn-sm btn-ghost tab-btn" data-tab="social" aria-selected="false">Social</button>
        <button type="button" class="btn btn-sm btn-ghost tab-btn" data-tab="acompanamientos" aria-selected="false">Acompañamientos</button>
    </div>

    <div data-tab-panel="datos">
    <form class="panel" id="bandaForm" action="<?= V::e($action) ?>" method="POST" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach ($fields as [$key, $label, $type]): ?>
        <div class="field">
            <label class="field-label" for="<?= $key ?>"><?= $label ?></label>
            <input class="input" id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>"<?= $type === 'number' ? ' min="1800" max="2100"' : '' ?> value="<?= $val($key) ?>"<?= $conEtapas && $type === 'number' ? ' readonly' : '' ?>>
<?php if ($conEtapas && $type === 'number'): ?>
            <p class="muted small">Se calcula a partir de las etapas de actividad (más abajo).</p>
<?php endif; ?>
        </div>
<?php if ($key === 'NOMBRE_COMPLETO'): ?>
<?= H::municipioFields((string) ($banda['LOCALIDAD'] ?? ''), $banda['PROVINCIA'] ?? null) ?>
<?php endif; ?>
<?php endforeach; ?>
        <div><button class="btn btn-neutral" type="submit"><?= $proposalMode ? 'Previsualizar propuesta' : 'Guardar cambios' ?></button></div>
    </form>

    <section>
        <h2 class="section-title">Etapas de actividad</h2>
        <p class="muted small">Solo para una banda que desaparece y vuelve a crearse con el mismo nombre. Con etapas, la fundación y la extinción se calculan solas. Al añadir la primera, el periodo actual (fundación–extinción) se guarda como etapa.</p>
<?php if ($etapas): ?>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Desde</td><td>Hasta</td><td>Nota</td><td></td></tr></thead>
            <tbody>
<?php foreach ($etapas as $e): ?>
                <tr>
                    <td class="small nums"><?= (int) $e['ANIO_INICIO'] ?></td>
                    <td class="small nums"><?= $e['ANIO_FIN'] !== null ? (int) $e['ANIO_FIN'] : 'hoy' ?></td>
                    <td class="small"><?= V::e($e['NOTA'] ?? '') ?></td>
                    <td>
                        <form action="/dashboard/banda/<?= $id ?>/etapa/<?= (int) $e['ID_ETAPA'] ?>/borrar" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar esta etapa?');">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php endif; ?>
        <form class="panel" action="/dashboard/banda/<?= $id ?>/etapa" method="POST">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
            <div class="row">
                <div class="field">
                    <label class="field-label" for="etapa_inicio">Desde (año)</label>
                    <input class="input" id="etapa_inicio" name="anio_inicio" type="number" min="1800" max="2100" required>
                </div>
                <div class="field">
                    <label class="field-label" for="etapa_fin">Hasta (año)</label>
                    <input class="input" id="etapa_fin" name="anio_fin" type="number" min="1800" max="2100" placeholder="vacío = en activo">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="etapa_nota">Nota (opcional)</label>
                <input class="input" id="etapa_nota" name="nota" type="text" value="">
            </div>
            <div><button class="btn btn-neutral" type="submit">Añadir etapa</button></div>
        </form>
    </section>

    <section>
        <h2 class="section-title">Linaje: predecesoras, sucesoras y juveniles</h2>
<?php if ($relaciones): ?>
        <div class="tableList"><table class="table table-zebra table-sm">
            <thead class="thead-neutral"><tr><td>Tipo</td><td>Origen → Destino</td><td>Fecha</td><td>Nota</td><td></td></tr></thead>
            <tbody>
<?php foreach ($relaciones as $r):
        $t = (string) $r['TIPO'];
        $oId = $r['ID_ORIGEN'] !== null ? (int) $r['ID_ORIGEN'] : null;
        $dId = $r['ID_DESTINO'] !== null ? (int) $r['ID_DESTINO'] : null;
        $fecha = $r['FECHA_INICIO'] !== null ? (string) $r['FECHA_INICIO'] : '—';
        if ($t === 'juvenil' && $r['FECHA_FIN'] !== null) $fecha .= '–' . $r['FECHA_FIN'];
?>
                <tr>
                    <td><span class="chip"><?= V::e($tipoLabel[$t] ?? $t) ?></span></td>
                    <td>
                        <?= $punta($oId, $r['ORIGEN_NOMBRE'] ?? null, $r['ORIGEN_LOC'] ?? null) ?>
                        <span class="muted small">(<?= V::e($rolOrigen[$t] ?? '') ?>)</span>
                        &rarr;
                        <?= $punta($dId, $r['DESTINO_NOMBRE'] ?? null, $r['DESTINO_LOC'] ?? null) ?>
                        <span class="muted small">(<?= V::e($rolDestino[$t] ?? '') ?>)</span>
                    </td>
                    <td class="small nums"><?= V::e($fecha) ?></td>
                    <td class="small"><?= V::e($r['NOTA'] ?? '') ?></td>
                    <td>
                        <form action="/dashboard/banda/<?= $id ?>/relacion/<?= (int) $r['ID_RELACION'] ?>/borrar" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar esta relación?');">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table></div>
<?php else: ?>
        <p class="muted">Esta banda no tiene relaciones de linaje registradas.</p>
<?php endif; ?>
    </section>

    <section>
        <h2 class="section-title">Añadir relación de linaje</h2>
        <form class="panel" action="/dashboard/banda/<?= $id ?>/relacion" method="POST" id="relacionForm">
            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

            <div class="field">
                <label class="field-label" for="tipo">Tipo de relación</label>
                <select class="input" id="tipo" name="tipo">
<?php foreach ($tipos as $t): ?>
                    <option value="<?= V::e($t) ?>"><?= V::e($tipoLabel[$t] ?? $t) ?></option>
<?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="field-label" for="direccion">La otra banda es…</label>
                <select class="input" id="direccion" name="direccion">
                    <option value="entrante">PREDECESORA / banda madre (otra banda → esta)</option>
                    <option value="saliente">SUCESORA / banda juvenil (esta → otra banda)</option>
                </select>
                <p class="muted small">Linaje (renombrado · fusión · división): la predecesora es la formación anterior y la sucesora la nueva. Juvenil: elige «predecesora» si <strong>esta</strong> banda es la juvenil de la otra, o «sucesora» si la otra es la juvenil de esta.</p>
            </div>

            <div class="field">
                <label class="field-label" for="otraBandaSearch">Otra banda</label>
                <input type="hidden" name="otraBanda" id="otraBandaId" value="">
                <div class="autocomplete">
                    <input class="input" id="otraBandaSearch" type="text" placeholder="Buscar banda (mín. 3 caracteres)…" autocomplete="off">
                    <div id="otraBandaSuggest" class="suggest" hidden></div>
                </div>
                <p class="muted small">Seleccionada: <strong id="otraBandaChosen">(ninguna)</strong></p>
            </div>

            <div class="row">
                <div class="field">
                    <label class="field-label" for="fecha_inicio">Fecha inicio (año)</label>
                    <input class="input" id="fecha_inicio" name="fecha_inicio" type="number" min="1800" max="2100" placeholder="p. ej. 2005">
                </div>
                <div class="field" id="fechaFinWrap">
                    <label class="field-label" for="fecha_fin">Fecha fin (solo juvenil)</label>
                    <input class="input" id="fecha_fin" name="fecha_fin" type="number" min="1800" max="2100" placeholder="vacío = vigente">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="nota">Nota (opcional)</label>
                <input class="input" id="nota" name="nota" type="text" value="">
            </div>

            <div><button class="btn btn-neutral" type="submit">Añadir relación</button></div>
        </form>
    </section>
    </div>

    <div data-tab-panel="social" hidden>
        <section>
            <h2 class="section-title">Web y enlaces oficiales</h2>
            <div class="field">
                <label class="field-label" for="WEB">Web</label>
                <input class="input" id="WEB" name="WEB" type="text" form="bandaForm" value="<?= $val('WEB') ?>">
                <p class="muted small">Se guarda junto con la pestaña «Datos».</p>
            </div>
        </section>

        <section>
            <h2 class="section-title">Enlaces de streaming / RRSS musicales</h2>
            <p class="muted small">Vincula el perfil oficial de esta banda en cada servicio. Vacío = sin enlace.</p>
            <form class="panel" action="/dashboard/banda/<?= $id ?>/social" method="POST">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
<?php foreach (EnlaceRepo::SERVICIOS as $servicio): ?>
                <div class="field">
                    <label class="field-label" for="social_<?= $servicio ?>"><?= V::e(H::STREAMING_LABELS[$servicio] ?? ucfirst($servicio)) ?></label>
                    <input class="input" id="social_<?= $servicio ?>" name="<?= $servicio ?>" type="url" placeholder="https://…" value="<?= V::e($enlaces[$servicio] ?? '') ?>">
                </div>
<?php endforeach; ?>
                <div><button class="btn btn-neutral" type="submit">Guardar enlaces</button></div>
            </form>
        </section>
    </div>

    <div data-tab-panel="acompanamientos" hidden>
<?php if ($acompError ?? null): ?>
        <div class="alert alert-error"><?= V::e($acompError) ?></div>
<?php else: ?>
        <section>
            <h2 class="section-title">Acompañamientos por año</h2>
<?php if ($acomp): ?>
            <div class="stack">
<?php $abierto = isset($acomp[$anioAbierto]) ? $anioAbierto : array_key_first($acomp); ?>
<?php foreach ($acomp as $anio => $filasAnio): ?>
            <details class="collapse"<?= $anio === $abierto ? ' open' : '' ?>>
                <summary class="collapse-title"><?= (int) $anio ?> <span class="muted small">(<?= count($filasAnio) ?>)</span></summary>
                <div class="collapse-content">
                    <div class="tableList"><table class="table table-zebra table-sm">
                        <thead class="thead-neutral"><tr><td>Día</td><td>Hermandad</td><td>Paso</td><td>Localidad</td><td>Provincia</td><td></td></tr></thead>
                        <tbody>
<?php foreach ($filasAnio as $f): ?>
                            <tr data-id="<?= (int) $f['ID_CONTRATO'] ?>" data-anio="<?= (int) $anio ?>" data-localidad="<?= V::e($f['LOCALIDAD']) ?>" data-provincia="<?= V::e($f['PROVINCIA'] ?? '') ?>"
                                data-dia="<?= V::e($f['DIA'] ?? '') ?>" data-hermandad="<?= V::e($f['HERMANDAD']) ?>" data-paso="<?= V::e($f['PASO'] ?? '') ?>">
                                <td><?= $f['DIA'] !== null ? V::e($f['DIA']) : '<span class="muted">—</span>' ?></td>
                                <td><a href="/dashboard/acompanamientos/<?= V::e(S::slugify((string) $f['LOCALIDAD'])) ?>"><?= V::e($f['HERMANDAD']) ?></a></td>
                                <td><?= $f['PASO'] !== null ? V::e($f['PASO']) : '<span class="muted">—</span>' ?><?= $f['TRAMO'] !== null ? ' <span class="muted small">(' . V::e($f['TRAMO']) . ')</span>' : '' ?></td>
                                <td><?= V::e($f['LOCALIDAD']) ?></td>
                                <td><?= $f['PROVINCIA'] !== null ? V::e($f['PROVINCIA']) : '<span class="muted">—</span>' ?></td>
                                <td>
                                    <div class="row" style="gap:0.25rem;flex-wrap:nowrap">
                                        <button class="btn btn-sm btn-ghost acomp-editar" type="button">Editar</button>
                                        <form action="/dashboard/banda/<?= $id ?>/acompanamiento/borrar" method="POST" class="inline-form" onsubmit="return confirm('¿Eliminar este acompañamiento de <?= (int) $anio ?>?');">
                                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                                            <input type="hidden" name="ids" value="<?= (int) $f['ID_CONTRATO'] ?>">
                                            <input type="hidden" name="ANIO" value="<?= (int) $anio ?>">
                                            <button class="btn btn-sm btn-ghost" type="submit">Borrar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <div class="row" style="flex-wrap:wrap;align-items:center">
                        <button class="btn btn-sm btn-ghost acomp-anadir" type="button" data-anio="<?= (int) $anio ?>">+ Añadir en <?= (int) $anio ?></button>
<?php if (count($acomp) > 1): ?>
                        <form action="/dashboard/banda/<?= $id ?>/acompanamiento/importar" method="POST" class="row" style="gap:0.25rem;align-items:center"
                              onsubmit="return confirm('¿Importar a <?= (int) $anio ?> todos los acompañamientos de ' + this.elements.ORIGEN.value + '?');">
                            <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                            <input type="hidden" name="ANIO" value="<?= (int) $anio ?>">
                            <select class="input" name="ORIGEN" aria-label="Año del que importar a <?= (int) $anio ?>">
<?php foreach (array_keys($acomp) as $otro): if ($otro === $anio) continue; ?>
                                <option value="<?= (int) $otro ?>"><?= (int) $otro ?> (<?= count($acomp[$otro]) ?>)</option>
<?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-ghost" type="submit">Importar a <?= (int) $anio ?></button>
                        </form>
<?php endif; ?>
                    </div>
                </div>
            </details>
<?php endforeach; ?>
            </div>
<?php else: ?>
            <p class="muted">Esta banda no tiene acompañamientos registrados.</p>
<?php endif; ?>
        </section>
<?php if ($acomp): ?>

        <section>
            <h2 class="section-title">Importar un año a otro</h2>
            <form class="panel" action="/dashboard/banda/<?= $id ?>/acompanamiento/importar" method="POST"
                  onsubmit="return confirm('¿Importar a ' + this.elements.ANIO.value + ' todos los acompañamientos de ' + this.elements.ORIGEN.value + '?');">
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <div class="adv-grid">
                    <div class="field">
                        <label class="field-label" for="acompImpOrigen">Acompañamientos de</label>
                        <select class="input" id="acompImpOrigen" name="ORIGEN">
<?php foreach ($acomp as $otro => $filasOtro): ?>
                            <option value="<?= (int) $otro ?>"><?= (int) $otro ?> (<?= count($filasOtro) ?>)</option>
<?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompImpDestino">Al año (puede ser uno sin acompañamientos)</label>
                        <input class="input" id="acompImpDestino" name="ANIO" type="number" min="1900" max="2100" required>
                    </div>
                </div>
                <p class="muted small">Copia localidad, hermandad, paso e ida/vuelta. Lo que la banda ya tenga ese año en el mismo paso no se duplica.</p>
                <div><button class="btn btn-neutral" type="submit">Importar</button></div>
            </form>
        </section>
<?php endif; ?>

<?php /* Formulario único para «Editar» y «+ Añadir en AAAA»: el JS lo coloca
         bajo la tabla del año y le pone la acción y los valores de la fila. */ ?>
        <div id="acompFlotante" hidden>
            <form class="panel acomp-form" method="POST" action="/dashboard/banda/<?= $id ?>/acompanamiento" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>
                  data-accion-alta="/dashboard/banda/<?= $id ?>/acompanamiento"
                  data-accion-editar="/dashboard/banda/<?= $id ?>/acompanamiento/{c}/editar">
                <p><strong class="acomp-titulo"></strong></p>
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <div class="adv-grid">
                    <div class="field">
                        <label class="field-label" for="acompFAnio">Año</label>
                        <input class="input" id="acompFAnio" name="ANIO_INICIO" type="number" min="1900" max="2100" required>
                    </div>
                    <?= $acompMunicipio('acompF') ?>
                    <div class="field">
                        <label class="field-label" for="acompFDia">Día</label>
                        <div class="autocomplete"><input class="input" id="acompFDia" name="DIA" type="text" autocomplete="off"><div class="suggest" hidden></div></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompFHermandad">Hermandad</label>
                        <div class="autocomplete"><input class="input" id="acompFHermandad" name="HERMANDAD" type="text" autocomplete="off" required><div class="suggest" hidden></div></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompFPaso">Paso (opcional)</label>
                        <div class="autocomplete"><input class="input" id="acompFPaso" name="PASO" type="text" autocomplete="off"><div class="suggest" hidden></div></div>
                    </div>
                </div>
                <p class="small acomp-aviso" aria-live="polite"></p>
                <div class="row" style="gap:0.5rem">
                    <button class="btn btn-neutral" type="submit">Guardar</button>
                    <button class="btn btn-ghost acomp-cancelar" type="button">Cancelar</button>
                </div>
            </form>
        </div>

        <section>
            <h2 class="section-title">Añadir acompañamiento (uno o varios años)</h2>
            <form class="panel acomp-form" action="/dashboard/banda/<?= $id ?>/acompanamiento" method="POST" id="acompForm" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>>
                <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
                <div class="adv-grid">
                    <div class="field">
                        <label class="field-label" for="acompAnioInicio">Año</label>
                        <input class="input" id="acompAnioInicio" name="ANIO_INICIO" type="number" min="1900" max="2100" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompAnioFin">Hasta (vacío = solo ese año)</label>
                        <input class="input" id="acompAnioFin" name="ANIO_FIN" type="number" min="1900" max="2100">
                    </div>
                    <?= $acompMunicipio('acomp') ?>
                    <div class="field">
                        <label class="field-label" for="acompDia">Día</label>
                        <div class="autocomplete"><input class="input" id="acompDia" name="DIA" type="text" autocomplete="off"><div class="suggest" hidden></div></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompHermandad">Hermandad</label>
                        <div class="autocomplete"><input class="input" id="acompHermandad" name="HERMANDAD" type="text" autocomplete="off" required><div class="suggest" hidden></div></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="acompPaso">Paso (opcional)</label>
                        <div class="autocomplete"><input class="input" id="acompPaso" name="PASO" type="text" autocomplete="off"><div class="suggest" hidden></div></div>
                    </div>
                </div>
                <p class="muted small">Si la hermandad ya está en la nómina, el día es el suyo. La localidad se elige del listado de municipios, como en bandas y dedicatorias. Lo que no exista en la nómina (día, hermandad o paso) se da de alta en <a href="/dashboard/semana-santa">Semana Santa</a>; un día nuevo va al final de la localidad y se reordena allí.</p>
                <p class="small acomp-aviso" aria-live="polite"></p>
                <div><button class="btn btn-neutral" type="submit">Añadir</button></div>
            </form>
            <script type="application/json" id="acompNomina"><?= json_encode($acompNomina, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
        </section>
<?php endif; ?>
    </div>
</div>
<script src="/assets/admin.js" defer></script>
<script src="/assets/banda-relaciones.js" defer></script>
<script src="/assets/acomp-destino.js?v=<?= (int) @filemtime(PUBLIC_DIR . "/assets/acomp-destino.js") ?>" defer></script>
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
        });
    });
    // Tras guardar en una pestaña, la redirección vuelve a ella (#tab-…).
    var inicial = btns.filter(function (b) { return '#tab-' + b.dataset.tab === location.hash; })[0];
    if (inicial) inicial.click();
})();
</script>
