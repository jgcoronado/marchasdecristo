<?php use App\View as V; use App\Auth; use App\Slug as S; use App\Html as H;
/** @var string $mode @var array $session @var string $action
 *  @var array<string,mixed> $marcha @var list<array{ID_AUTOR:int,NOMBRE_COMPLETO:string}> $authors
 *  @var bool $proposalMode @var array|null $notice @var string|null $error */
$csrf = Auth::csrfToken($session);
$isEdit = $mode === 'edit';
$proposalMode = $proposalMode ?? false;
$val = static fn(string $k): string => V::e($marcha[$k] ?? '');
$estilo = (string) ($marcha['ESTILO'] ?? '');
$tipo = (string) ($marcha['TIPO'] ?? '');
$tipoLabel = static fn(string $t): string => ucfirst(mb_strtolower($t, 'UTF-8'));
// ID numérico de la banda de estreno ya guardada (para el campo hidden)
$bandaEstrenoId  = (string) ($marcha['BANDA_ESTRENO'] ?? '');
$bandaEstrenoNom = (string) ($marcha['BANDA_NOMBRE'] ?? '');  // nombre breve, pasado desde Admin
$excludeId = $isEdit ? (int) ($marcha['ID_MARCHA'] ?? 0) : 0;
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1><?= $isEdit ? 'Editar marcha #' . V::e($marcha['ID_MARCHA']) : 'Añadir marcha' ?></h1>
        <div class="row">
<?php if ($isEdit): ?>
            <a class="btn btn-sm btn-ghost" href="<?= V::e(S::buildDetailPath('marcha', $marcha['ID_MARCHA'], (string) ($marcha['TITULO'] ?? ''))) ?>" target="_blank">Ver ↗</a>
<?php endif; ?>
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= $notice['type'] === 'ok' ? 'success' : ($notice['type'] === 'error' ? 'error' : 'info') ?>"><?= V::e($notice['msg']) ?></div><?php endif; ?>
<?php if ($proposalMode): ?><div class="alert alert-info">Verás una <strong>previsualización</strong> antes de enviar. Tu propuesta la revisará un administrador; no se guarda directamente en la base de datos.</div><?php endif; ?>

    <div id="duplicateAlert" class="alert alert-error" hidden></div>

    <form class="panel" action="<?= V::e($action) ?>" method="POST" id="marchaForm" <?= H::municipioFormAttrs(!$proposalMode, $csrf) ?>>
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">
        <input type="hidden" name="excludeId" value="<?= $excludeId ?>">

        <div class="field">
            <label class="field-label" for="TITULO">Título <span class="muted small">· obligatorio</span></label>
            <input class="input" id="TITULO" name="TITULO" type="text" value="<?= $val('TITULO') ?>" placeholder="p. ej. «Pasan los Tercios»">
            <p class="field-help muted small">Nombre oficial de la marcha tal como aparece en el catálogo o en el disco original. Evita artículos iniciales («La», «El», «Los»…) cuando no forman parte del título registrado.</p>
        </div>

        <div class="field">
            <label class="field-label" for="FECHA">Año de composición</label>
            <input class="input" id="FECHA" name="FECHA" type="text" value="<?= $val('FECHA') ?>" placeholder="p. ej. 1987">
            <p class="field-help muted small">Cuatro dígitos. Si no se conoce el año exacto, déjalo en blanco o usa el año más probable según las fuentes disponibles.</p>
        </div>

        <div class="field">
            <label class="field-label" for="DEDICATORIA">Dedicatoria</label>
            <input class="input" id="DEDICATORIA" name="DEDICATORIA" type="text" value="<?= $val('DEDICATORIA') ?>" placeholder="p. ej. «A la Hdad. del Gran Poder de Sevilla»">
            <p class="field-help muted small">A quién está dedicada la marcha (hermandad, paso, persona…). Copia el texto original de la partitura o del disco si está disponible.</p>
        </div>

<?= H::municipioFields((string) ($marcha['LOCALIDAD'] ?? ''), $marcha['PROVINCIA'] ?? null) ?>

<?php if ($isEdit): ?>
        <div class="field">
            <label class="field-label" for="AUDIO">Audio (URL)</label>
            <input class="input" id="AUDIO" name="AUDIO" type="text" value="<?= $val('AUDIO') ?>" placeholder="p. ej. https://www.youtube.com/watch?v=…">
            <p class="field-help muted small">URL de YouTube u otro servicio de audio. Se usa para el reproductor embebido en la ficha pública.</p>
        </div>
<?php endif; ?>

        <div class="field">
            <label class="field-label" for="bandaEstrenoSearch">Banda de estreno</label>
            <input type="hidden" id="BANDA_ESTRENO" name="BANDA_ESTRENO" value="<?= V::e($bandaEstrenoId) ?>">
            <div class="autocomplete">
                <input class="input" id="bandaEstrenoSearch" type="text"
                       placeholder="Buscar banda por nombre (mín. 3 caracteres)…"
                       autocomplete="off"
                       value="<?= $bandaEstrenoId !== '' ? V::e($bandaEstrenoNom !== '' ? $bandaEstrenoNom . ' (#' . $bandaEstrenoId . ')' : '#' . $bandaEstrenoId) : '' ?>">
                <div id="bandaEstrenoSuggest" class="suggest" hidden></div>
            </div>
            <p class="field-help muted small">Banda que estrenó la marcha. Busca por nombre breve, nombre completo o localidad. Deja en blanco si se desconoce. <button type="button" class="link-btn" id="bandaEstrenoClear">Quitar selección</button>.</p>
        </div>

        <div class="field">
            <label class="field-label" for="TIPO">Tipo</label>
            <select class="input" id="TIPO" name="TIPO">
                <option value="" <?= $tipo === '' ? 'selected' : '' ?>>— Sin asignar —</option>
<?php foreach (\App\AdminRepo::MARCHA_TIPOS as $opt): ?>
                <option value="<?= V::e($opt) ?>" <?= $tipo === $opt ? 'selected' : '' ?>><?= V::e($tipoLabel($opt)) ?></option>
<?php endforeach; ?>
            </select>
            <p class="field-help muted small">Casi todas las marchas son «Marcha procesional»; el resto son adaptaciones minoritarias heredadas del catálogo original.</p>
        </div>

        <div class="field">
            <label class="field-label" for="ESTILO">Estilo</label>
            <select class="input" id="ESTILO" name="ESTILO">
                <option value="" <?= $estilo === '' ? 'selected' : '' ?>>— Sin asignar —</option>
                <option value="CCTT" <?= $estilo === 'CCTT' ? 'selected' : '' ?>>Cornetas y Tambores (CCTT)</option>
                <option value="AM" <?= $estilo === 'AM' ? 'selected' : '' ?>>Agrupación Musical (AM)</option>
            </select>
            <p class="field-help muted small">CCTT = banda de cornetas y tambores; AM = agrupación musical (banda con vientos, metales y percusión). Si no consta, déjalo sin asignar.</p>
        </div>

        <div class="field">
            <label class="field-label" for="DETALLES_MARCHA">Notas / detalles</label>
            <textarea class="input" id="DETALLES_MARCHA" name="DETALLES_MARCHA" rows="4"><?= $val('DETALLES_MARCHA') ?></textarea>
            <p class="field-help muted small">Información adicional: fuentes bibliográficas, contexto histórico, variantes del título, datos de edición, etc. Para saltos de línea usa <code>&lt;br&gt;</code>.</p>
        </div>

        <div class="field">
            <label class="field-label">Autor(es) <span class="muted small">· al menos uno obligatorio</span></label>
            <div id="autoresBox" class="chips">
<?php foreach ($authors as $a): ?>
                <span class="chip" data-id="<?= (int) $a['ID_AUTOR'] ?>">
                    <input type="hidden" name="autoresIds[]" value="<?= (int) $a['ID_AUTOR'] ?>">
                    <span><?= V::e($a['NOMBRE_COMPLETO']) ?></span>
                    <button type="button" class="chip-x" aria-label="Quitar">×</button>
                </span>
<?php endforeach; ?>
            </div>
            <div class="autocomplete">
                <input class="input" id="autorSearch" type="text" placeholder="Buscar compositor (mín. 3 caracteres)…" autocomplete="off">
                <div id="autorSuggest" class="suggest" hidden></div>
            </div>
            <p class="field-help muted small">Compositor(es) de la marcha. Si hay varios (partitura a dos, adaptación…), añádelos todos. El orden no es relevante.</p>
        </div>

        <div><button class="btn btn-neutral" type="submit"><?= $proposalMode ? 'Previsualizar propuesta' : ($isEdit ? 'Guardar cambios' : 'Crear marcha') ?></button></div>
    </form>
</div>
<script>window._marchaExcludeId = <?= $excludeId ?>;</script>
<script src="/assets/admin.js" defer></script>
