<?php use App\View as V; use App\Pages as P;
/** Mapa ampliado de una provincia (N-10): municipios clicables.
 *  @var string $svgMapa  markup <svg>…</svg> ya construido en App\Mapa (no escapar)
 *  @var string $provincia
 *  @var list<array{LOCALIDAD:string,PROVINCIA:string,N:int}> $porLocalidad  ordenado N DESC
 *  @var int $total */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › <a href="/mapa">Mapa</a> › <?= V::e($provincia) ?></span>
    </div>

    <article class="record">
        <div class="head">
            <span class="eb">Mapa</span>
            <span class="sig">MDC · <?= V::e($provincia) ?></span>
        </div>
        <h1>Mapa de <?= V::e($provincia) ?></h1>
        <p class="asiento">Pulsa un municipio para ver sus marchas, o consulta la tabla de abajo.
            El color indica cuántas marchas tiene cada uno; la posición es aproximada. Puedes
            hacer zoom con la rueda del ratón, arrastrar para desplazarte, o usar los botones
            +/−.</p>

        <div class="mapa-wrap mapa-wrap-provincia">
            <?= $svgMapa ?>
        </div>

        <ul class="mapa-leyenda" aria-hidden="true">
            <li><span class="prov-sw mapa-punto-n1"></span> 1 marcha</li>
            <li><span class="prov-sw mapa-punto-n2"></span> 2–3</li>
            <li><span class="prov-sw mapa-punto-n3"></span> 4–8</li>
            <li><span class="prov-sw mapa-punto-n4"></span> 9+</li>
        </ul>

        <p class="ids">
            <a href="<?= V::e(P::provinciaHubPath($provincia)) ?>">Ver las <?= $num($total) ?> marchas de <?= V::e($provincia) ?> →</a>
            · <a href="/mapa">← Volver al mapa de provincias</a>
        </p>

        <div class="shead"><h2>Localidades con marchas</h2></div>
        <div class="scrollx tableList">
        <table class="reg">
            <thead><tr>
                <th>Localidad</th>
                <th class="num">Marchas</th>
            </tr></thead>
            <tbody>
<?php foreach ($porLocalidad as $l): ?>
                <tr>
                    <td><a href="/marcha?<?= V::e(http_build_query(['localidad' => $l['LOCALIDAD']])) ?>"><?= V::e($l['LOCALIDAD']) ?></a></td>
                    <td class="num"><?= $num($l['N']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </article>
</div>
<script src="/assets/mapa.js" defer></script>
