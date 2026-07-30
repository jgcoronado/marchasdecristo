<?php use App\View as V; use App\Auth; use App\Media;
/** @var array $session @var array<string,mixed> $disco @var string|null $error */
$csrf = Auth::csrfToken($session);
$val = static fn(string $k): string => V::e((string) ($disco[$k] ?? ''));
$maxMb = (int) round(Media::PORTADA_MAX_BYTES / 1024 / 1024);
?>
<div class="stack admin-form">
    <div class="admin-bar">
        <h1>Añadir disco</h1>
        <div class="row">
            <a class="btn btn-sm btn-ghost" href="/dashboard">← Panel</a>
        </div>
    </div>

<?php if ($error): ?><div class="alert alert-error">Error: <?= V::e($error) ?></div><?php endif; ?>

    <p class="muted">Primero se crea el disco; al guardarlo pasarás a su ficha para
        añadir las marchas con su número de pista.</p>

    <form class="panel" action="/dashboard/disco/add" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= V::e($csrf) ?>">

        <div class="field">
            <label class="field-label" for="NOMBRE_CD">Nombre del disco <span class="muted small">· obligatorio</span></label>
            <input class="input" id="NOMBRE_CD" name="NOMBRE_CD" type="text" value="<?= $val('NOMBRE_CD') ?>" required>
        </div>

        <div class="field">
            <label class="field-label" for="FECHA_CD">Año de edición</label>
            <input class="input" id="FECHA_CD" name="FECHA_CD" type="number" min="1900" max="2100" value="<?= $val('FECHA_CD') ?>">
        </div>

        <?php /* Buscador de banda: mismo patrón que admin.js, en disco.js. */ ?>
        <div class="field autocomplete" data-banda-picker>
            <label class="field-label" for="bandaBuscar">Banda propietaria</label>
            <input class="input" id="bandaBuscar" type="text" autocomplete="off"
                   placeholder="Escribe para buscar una banda…"
                   value="<?= $val('BANDA_BREVE') ?>" data-banda-input>
            <input type="hidden" name="BANDADISCO" value="<?= $val('BANDADISCO') ?>" data-banda-id>
            <div class="suggest" hidden data-banda-suggest></div>
            <p class="muted small field-help">Opcional: la banda que firma el disco. Déjalo vacío en recopilatorios de varias bandas.</p>
        </div>

        <div class="field">
            <label class="field-label" for="portada">Portada</label>
            <input class="input" id="portada" name="portada" type="file" accept="image/png,image/jpeg,image/webp">
            <p class="muted small field-help">PNG, JPG o WebP, hasta <?= $maxMb ?> MB. Se recorta al cuadrado
                y se guarda como <code class="mono">/cover/{id}.png</code>, igual que el resto del catálogo.
                Puedes subirla después desde la ficha del disco.</p>
        </div>

        <div class="field">
            <label class="field-label" for="d_DETALLES">Notas</label>
            <textarea class="input" id="d_DETALLES" name="d_DETALLES" rows="3"><?= $val('d_DETALLES') ?></textarea>
        </div>

        <div><button class="btn btn-neutral" type="submit">Crear disco</button></div>
    </form>
</div>
<script src="/assets/disco.js" defer></script>
