/* Marchas de Cristo — acompañamientos de una localidad: al pasar el ratón
   (o el foco del teclado) por una banda de la serie se resalta su tramo en la
   tira, y al revés. Cada banda y su tramo comparten data-r (App\AcompSerie).
   Solo con puntero que puede "pasar por encima" (escritorio): en táctil no
   hay hover y un toque ya abre el enlace de la banda.
   Progresiva: sin JS la página se ve igual, sin resaltado. */
(function () {
    'use strict';

    if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;

    function marcar(paso, r) {
        paso.classList.toggle('foco', r !== null);
        var els = paso.querySelectorAll('[data-r]');
        for (var i = 0; i < els.length; i++) {
            els[i].classList.toggle('on', r !== null && els[i].getAttribute('data-r') === r);
        }
    }

    function alEntrar(e) {
        var el = e.target.closest ? e.target.closest('[data-r]') : null;
        marcar(e.currentTarget, el ? el.getAttribute('data-r') : null);
    }

    function alSalir(e) {
        marcar(e.currentTarget, null);
    }

    var pasos = document.querySelectorAll('.acs-paso');
    for (var i = 0; i < pasos.length; i++) {
        pasos[i].addEventListener('mouseover', alEntrar);
        pasos[i].addEventListener('mouseleave', alSalir);
        pasos[i].addEventListener('focusin', alEntrar);
        pasos[i].addEventListener('focusout', alSalir);
    }
})();
