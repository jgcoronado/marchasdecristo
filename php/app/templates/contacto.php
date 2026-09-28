<?php use App\View as V;
/**
 * Página «Contacto»: correo y perfil de X para aportes y correcciones.
 *
 * El correo nunca se escribe literal: usuario y dominio van invertidos en
 * data-u/data-d y catalog.js los recompone (sin JS se lee «(arroba)»).
 *
 * @var string $correo  Vacío = «Próximamente» (ver Pages::contacto).
 */
$asunto = 'Aporte a Marchas de Cristo';
?>
<article class="record">
    <h1>Contacto</h1>
    <p class="asiento">Marchas de Cristo es un proyecto personal de Javier Guerra que quiere documentar la música
        procesional con rigor. Si tienes datos, correcciones o sugerencias, me encantará leerlos.</p>

    <div class="stack">
        <section class="card">
            <div class="shead"><h2>Correo electrónico</h2></div>
<?php if ($correo !== ''):
    [$usuario, $dominio] = explode('@', $correo, 2);
    $u = V::e(strrev($usuario));
    $d = V::e(strrev($dominio)); ?>
            <p class="notas"><strong data-correo data-u="<?= $u ?>" data-d="<?= $d ?>"><?= V::e($usuario) ?> (arroba) <?= V::e(str_replace('.', ' (punto) ', $dominio)) ?></strong></p>
            <p class="svcs"><a class="svc" data-correo data-u="<?= $u ?>" data-d="<?= $d ?>" data-asunto="<?= V::e($asunto) ?>" hidden>Escribir un correo</a></p>
<?php else: ?>
            <p class="notas">Próximamente. Mientras tanto, escríbeme por X.</p>
<?php endif; ?>
        </section>

        <section class="card">
            <div class="shead"><h2>X (Twitter)</h2></div>
            <p class="notas"><strong>@JaviWarSVQ</strong> — para comentarios rápidos, novedades del proyecto o mensajes directos.</p>
            <p class="svcs"><a class="svc" href="https://x.com/JaviWarSVQ" rel="me noopener" target="_blank">Ir al perfil</a></p>
        </section>

        <section class="card">
            <div class="shead"><h2>Aviso: no alojamos partituras ni audios</h2></div>
            <p class="notas">Marchas de Cristo es un catálogo de datos. <strong>No almacenamos ni disponemos de partituras
                ni de grabaciones</strong>: los audios y vídeos que ves en las fichas son enlaces a plataformas externas
                (YouTube, Spotify, Apple Music…). Por eso no podemos atender peticiones de partituras o archivos de audio.</p>
        </section>

        <section class="card">
            <div class="shead"><h2>Qué puedes aportar</h2></div>
            <p class="notas"><strong>Nos interesan especialmente los libretos de los CD</strong>: suelen recoger autores,
                fechas de composición, dedicatorias y bandas que no aparecen en ninguna otra parte. Una foto o
                transcripción del libreto es una de las aportaciones más valiosas.</p>
            <ul class="notas">
                <li>Correcciones de fechas, autores o dedicatorias de una marcha.</li>
                <li>Marchas, bandas o discos que falten en el catálogo.</li>
                <li>Acompañamientos musicales de tu hermandad o localidad.</li>
            </ul>
        </section>

        <section class="card">
            <div class="shead"><h2>Para que tu aporte sea útil</h2></div>
            <p class="notas">Envía el dato <strong>junto con una fuente donde podamos contrastarlo</strong>
                (libreto de CD, programa de mano, web de la banda o hermandad, prensa…). Sin fuente es difícil
                publicarlo. Si es una corrección, indica también el enlace a la ficha afectada.</p>
        </section>
    </div>

    <p class="muted">Respondo en cuanto puedo; normalmente en unos días. Tu correo solo se usará para contestarte.</p>
</article>
