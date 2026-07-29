<?php use App\View as V;
/**
 * Resultados de la búsqueda global (M3). Destino sin-JS del formulario de la
 * cabecera y "ver todo" con más resultados por tipo. El desplegable en vivo lo
 * pinta catalog.js contra /api/buscar; aquí se renderiza en servidor.
 *
 * @var string $q
 * @var int $total
 * @var array<string,array{etiqueta:string,items:list<array{tipo:string,titulo:string,sub:string,url:string}>}> $grupos
 */
?>
<article class="record">
    <h1>Buscar en el catálogo</h1>

    <form class="buscar-form" action="/buscar" method="get" role="search">
        <input class="input" type="search" name="q" value="<?= V::e($q) ?>"
               placeholder="Marcha, compositor, banda o disco…"
               aria-label="Buscar en el catálogo" autofocus>
        <button class="btn btn-sm btn-neutral" type="submit">Buscar</button>
    </form>

<?php if ($q === ''): ?>
    <p class="asiento">Escribe para buscar a la vez entre marchas procesionales, compositores, bandas y discos.</p>
<?php elseif ($total === 0): ?>
    <p class="bio-empty">No se ha encontrado nada para «<?= V::e($q) ?>». Prueba con menos palabras o comprueba la ortografía.</p>
<?php else: ?>
    <p class="asiento"><?= (int) $total ?> resultado<?= $total === 1 ? '' : 's' ?> para «<?= V::e($q) ?>».</p>

    <div class="stack buscar-grupos">
<?php foreach ($grupos as $grupo): if ($grupo['items'] === []) continue; ?>
        <section class="card">
            <div class="shead"><h2><?= V::e($grupo['etiqueta']) ?> <span class="cnt">(<?= count($grupo['items']) ?>)</span></h2></div>
            <ul class="buscar-lista">
<?php foreach ($grupo['items'] as $it): ?>
                <li>
                    <a href="<?= V::e($it['url']) ?>"><?= V::e($it['titulo']) ?></a>
<?php if ($it['sub'] !== ''): ?>
                    <span class="buscar-sub"><?= V::e($it['sub']) ?></span>
<?php endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</article>
