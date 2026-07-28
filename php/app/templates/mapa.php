<?php use App\View as V; use App\Pages as P;
/** Coropleta de provincias (N-10).
 *  @var string $svgMapa  markup <svg>…</svg> ya construido en App\Mapa (no escapar)
 *  @var list<array{K:string,N:int}> $porProvincia  ordenado N DESC, ya excluye provincias sin marchas */
$num = static fn($n): string => number_format((int) $n, 0, ',', '.');
?>
<div class="stack">
    <div class="crumbs">
        <span><a href="/">Inicio</a> › Mapa</span>
    </div>

    <article class="record">
        <div class="head">
            <span class="eb">Mapa</span>
            <span class="sig">MDC · provincias</span>
        </div>
        <h1>Mapa de provincias</h1>
        <p class="asiento">Marchas procesionales del catálogo por provincia. Pulsa una provincia
            para ver el detalle de sus municipios con marchas. También puedes ir directo al
            catálogo desde la tabla de abajo.</p>

        <div class="mapa-wrap">
            <?= $svgMapa ?>
        </div>

        <ul class="mapa-leyenda" aria-hidden="true">
            <li><span class="prov-sw prov-0"></span> Sin marchas</li>
            <li><span class="prov-sw prov-1"></span> 1–9</li>
            <li><span class="prov-sw prov-2"></span> 10–49</li>
            <li><span class="prov-sw prov-3"></span> 50–149</li>
            <li><span class="prov-sw prov-4"></span> 150–399</li>
            <li><span class="prov-sw prov-5"></span> 400+</li>
        </ul>

        <div class="shead"><h2>Provincias con marchas</h2></div>
        <div class="scrollx tableList">
        <table class="reg">
            <thead><tr>
                <th>Provincia</th>
                <th class="num">Marchas</th>
            </tr></thead>
            <tbody>
<?php foreach ($porProvincia as $p): ?>
                <tr>
                    <td><a href="<?= V::e(P::provinciaHubPath((string) $p['K'])) ?>"><?= V::e($p['K']) ?></a></td>
                    <td class="num"><?= $num($p['N']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </article>
</div>
