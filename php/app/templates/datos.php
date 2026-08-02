<?php use App\View as V;
/**
 * Página «Datos» (M1): licencia CC BY 4.0, política de citación y puntos de
 * acceso a los datos (API JSON, feeds, sitemap, llms.txt).
 *
 * @var array{MARCHAS:int,AUTORES:int,BANDAS:int,DISCOS:int}|null $counts
 * @var array{nombre:string,nombre_completo:string,url:string,atribucion:string} $licencia
 * @var string $base
 * @var string $ejemploApi  URL real de ejemplo de la API (una ficha con datos)
 */
$num = static fn(int $n): string => number_format($n, 0, ',', '.');
?>
<article class="record">
    <h1>Datos y licencia</h1>
    <p class="asiento">Marchas de Cristo es una base de datos de música procesional española de acceso libre.
<?php if ($counts !== null): ?>
        <?= $num($counts['MARCHAS']) ?> marchas, <?= $num($counts['AUTORES']) ?> compositores, <?= $num($counts['BANDAS']) ?> bandas y <?= $num($counts['DISCOS']) ?> discos,
<?php endif; ?>
        publicados bajo licencia Creative Commons para que puedas consultarlos, reutilizarlos y citarlos.</p>

    <div class="stack">
        <section class="card">
            <div class="shead"><h2>Licencia</h2></div>
            <p class="notas">Los datos de <strong>marchasdecristo.com</strong> se publican bajo licencia
                <strong><?= V::e($licencia['nombre_completo']) ?> (<?= V::e($licencia['nombre']) ?>)</strong>.
                Puedes usarlos, copiarlos y redistribuirlos, incluso con fines comerciales, siempre que
                <strong>cites la fuente con un enlace a marchasdecristo.com</strong>.</p>
            <p class="notas">Licencia completa:
                <a class="link" href="<?= V::e($licencia['url']) ?>" rel="license nofollow noopener" target="_blank"><?= V::e($licencia['url']) ?></a>.</p>

            <p class="muted">Ejemplo de atribución (copia y pega):</p>
            <pre class="scrollx"><code>Datos: marchasdecristo.com (CC BY 4.0)</code></pre>
        </section>

        <section class="card">
            <div class="shead"><h2>API JSON (solo lectura)</h2></div>
            <p class="notas">Cada ficha del catálogo está disponible como JSON estable, con la URL canónica y el
                bloque de licencia incluidos. El <span class="mono">{id}</span> es el número al final de la URL de la
                ficha (por ejemplo <span class="mono">/marcha/consuelo-gitano-330</span> → <span class="mono">330</span>).</p>
            <div class="scrollx">
                <table class="table table-zebra">
                    <thead class="thead-neutral"><tr><td>Recurso</td><td>URL</td></tr></thead>
                    <tbody>
                        <tr><td>Marcha</td><td><span class="mono"><?= V::e($base) ?>/api/marcha/{id}.json</span></td></tr>
                        <tr><td>Compositor</td><td><span class="mono"><?= V::e($base) ?>/api/autor/{id}.json</span></td></tr>
                        <tr><td>Banda</td><td><span class="mono"><?= V::e($base) ?>/api/banda/{id}.json</span></td></tr>
                        <tr><td>Disco</td><td><span class="mono"><?= V::e($base) ?>/api/disco/{id}.json</span></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="notas"><a class="svc" href="<?= V::e($ejemploApi) ?>" rel="nofollow">Ver un ejemplo →</a></p>
        </section>

        <section class="card">
            <div class="shead"><h2>Novedades</h2></div>
            <p class="notas">Últimas marchas incorporadas al catálogo, para sindicar o seguir:</p>
            <p class="svcs">
                <a class="svc" href="<?= V::e($base) ?>/feed.xml">Feed RSS</a>
                <a class="svc" href="<?= V::e($base) ?>/feed.json">Feed JSON</a>
            </p>
        </section>

        <section class="card">
            <div class="shead"><h2>Para asistentes de IA y buscadores</h2></div>
            <p class="notas">Guía de uso y citación en formato legible por máquinas, más el mapa completo del sitio:</p>
            <p class="svcs">
                <a class="svc" href="<?= V::e($base) ?>/llms.txt">llms.txt</a>
                <a class="svc" href="<?= V::e($base) ?>/sitemap.xml">sitemap.xml</a>
            </p>
        </section>
    </div>
</article>
