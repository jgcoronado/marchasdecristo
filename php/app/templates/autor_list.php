<?php use App\View as V; use App\Slug as S; use App\Html as H;
/** @var array<string,string> $criteria @var array|null $result @var int $page @var int $limit */
$val = static fn(string $k): string => V::e($criteria[$k] ?? '');
?>
<div class="stack list-page">
<?php /* Un solo campo: no necesita tarjeta ni desplegable, va en línea. El
         tamaño de página se elige con los enlaces de la barra de resultados,
         igual que en el resto de listados. */ ?>
    <form class="buscar-form" action="/autor" method="GET" role="search">
        <input id="nombre" class="input" type="search" name="nombre" value="<?= $val('nombre') ?>"
               placeholder="Apellidos o nombre del compositor…" aria-label="Buscar compositor">
        <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
    </form>

<?php if ($result !== null): $total = (int) $result['totalRows']; ?>
    <section>
<?php if ($total === 0): ?>
        <p class="bio-empty">No se han encontrado compositores.</p>
<?php else: ?>
        <div class="toolbar">
            <span class="rescount">Compositores — <b><?= number_format($total, 0, ',', '.') ?></b> registros</span>
            <?= H::porPagina($limit, '/autor', $criteria) ?>
        </div>
<?php endif; ?>
<?php if ($total > 0): ?>
        <div class="tableList">
            <table class="table table-zebra table-sm">
                <thead><tr><th>Nombre</th><th>Marchas</th></tr></thead>
                <tbody>
<?php foreach ($result['data'] as $a): ?>
                    <tr>
                        <td><a href="<?= V::e(S::buildDetailPath('autor', $a['ID_AUTOR'], (string) $a['NOMBRE_COMPLETO'])) ?>"><?= V::e($a['NOMBRE_COMPLETO']) ?></a></td>
                        <td class="nums"><?= V::e($a['MARCHAS']) ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= H::pagination($page, $result['totalRows'], $limit, '/autor', $criteria) ?>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
