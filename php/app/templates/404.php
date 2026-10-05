<?php declare(strict_types=1);

use App\View as V;

// Ruta pedida, sin query string, para que el visitante vea qué ha fallado.
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
?>
<div class="stack error-page">
    <header class="masthead">
        <p class="error-code" aria-hidden="true">404</p>
        <h1>Página no encontrada</h1>
        <p class="welcome-text">
            No hay nada en <code><?= V::e($ruta) ?></code>. Puede que el enlace esté mal
            escrito o que la ficha ya no forme parte del catálogo. Prueba con el buscador
            de arriba o sigue por una de estas secciones.
        </p>
    </header>

    <section class="card">
        <h2 class="section-title">Sigue por aquí</h2>
        <ul class="vease">
            <li><a href="/">Inicio</a></li>
            <li><a href="/marcha">Marchas</a></li>
            <li><a href="/autor">Compositores</a></li>
            <li><a href="/banda">Bandas</a></li>
            <li><a href="/disco">Discos</a></li>
        </ul>
    </section>
</div>
