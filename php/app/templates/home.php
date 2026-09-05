<?php use App\View as V; use App\Slug as S; use App\Media as MD;
/** @var list<array<string,mixed>> $ultimas */
/** @var array{MARCHAS:int,AUTORES:int,BANDAS:int,DISCOS:int}|null $estado */
/** @var array<string,mixed>|null $marchaDelDia */
/** @var list<array{href:string,label:string,cnt:?int,note:?string}> $sugerencias */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="stack home">
    <section class="card">
        <p class="welcome-text">
            Base de datos de <strong>música procesional</strong> española: marchas, compositores,
            bandas de cornetas y tambores, agrupaciones musicales y discos.
            Usa el menú para explorar o buscar por cualquier criterio.
        </p>
<?php if ($estado): ?>
        <div class="welcome-counts">
            <?= $estado['MARCHAS'] ?> marchas · <?= $estado['AUTORES'] ?> compositores · <?= $estado['BANDAS'] ?> bandas · <?= $estado['DISCOS'] ?> discos
        </div>
<?php endif; ?>
    </section>

    <div class="home-top">
<?php if ($marchaDelDia):
    $mdd = $marchaDelDia;
    $mddYtid = MD::youtubeId($mdd['AUDIO'] ?? null);
    $mddAutores = implode(', ', array_map(static fn(array $a): string => (string) $a['nombre'], $mdd['AUTOR']));
    $mddPath = S::buildDetailPath('marcha', $mdd['ID_MARCHA'], (string) $mdd['TITULO']);
    $mddFecha = (string) ($mdd['FECHA'] ?? ''); // ya normalizada a 's/f' por Repo::fetchMarcha si no hay año
?>
        <section class="card marcha-dia">
            <div class="shead"><h2>Marcha del día</h2></div>
            <a class="ultima-row" href="<?= V::e($mddPath) ?>">
                <span class="ultima-main">
                    <span class="ultima-title"><?= V::e($mdd['TITULO']) ?></span>
                    <span class="ultima-authors"><?= V::e($mddAutores) ?></span>
<?php if (!empty($mdd['BANDA_NOMBRE'])): ?>
                    <span class="ultima-banda"><?= V::e((string) $mdd['BANDA_NOMBRE']) ?><?php if (!empty($mdd['BANDA_LOC'])): ?>, <?= V::e($mdd['BANDA_LOC']) ?><?php endif; ?></span>
<?php endif; ?>
                </span>
<?php if ($mddFecha !== '' && $mddFecha !== 's/f'): ?>
                <span class="ultima-date"><?= V::e($mddFecha) ?></span>
<?php endif; ?>
            </a>
<?php if ($mddYtid !== null): ?>
            <div class="ytembed" data-ytid="<?= V::e($mddYtid) ?>">
                <button type="button" class="ytfacade" aria-label="Reproducir el vídeo (carga YouTube al pulsar)">
                    <img class="ytfacade-img" src="<?= V::e(MD::youtubeThumb($mddYtid)) ?>" alt="" loading="lazy" width="480" height="270">
                    <span class="ytfacade-play" aria-hidden="true"></span>
                </button>
            </div>
<?php endif; ?>
        </section>
<?php endif; ?>

<?php if ($sugerencias !== []): ?>
        <section class="explora-col">
            <h2 class="section-title">Explorar el catálogo</h2>
            <ul class="vease">
<?php foreach ($sugerencias as $s): ?>
                <li><a href="<?= V::e($s['href']) ?>"><?= V::e($s['label']) ?></a><?php if ($s['cnt'] !== null): ?> <span class="cnt"><?= $num($s['cnt']) ?> registros</span><?php elseif ($s['note'] !== null): ?> <span class="cnt"><?= V::e($s['note']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>
    </div>

<?php if ($ultimas): ?>
    <section>
        <h2 class="section-title">Últimas incorporaciones</h2>
        <div class="ultimas">
<?php foreach ($ultimas as $m):
    $authors = implode(', ', array_map(static fn(array $a): string => (string) $a['nombre'], $m['AUTOR'])); ?>
            <a class="ultima-row" href="<?= V::e(S::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO'])) ?>">
                <span class="ultima-main">
                    <span class="ultima-title"><?= V::e($m['TITULO']) ?></span>
                    <span class="ultima-authors"><?= V::e($authors) ?></span>
<?php if (!empty($m['BANDA_BREVE'])): ?>
                    <span class="ultima-banda"><?= V::e((string) $m['BANDA_BREVE']) ?><?php if (!empty($m['BANDA_LOC'])): ?>, <?= V::e($m['BANDA_LOC']) ?><?php endif; ?></span>
<?php endif; ?>
                </span>
                <span class="ultima-date"><?= V::e($m['FECHA']) ?></span>
            </a>
<?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

    <p class="firma">Javier Guerra — <a href="https://x.com/JaviWarSVQ">@JaviWarSVQ</a></p>
</div>
