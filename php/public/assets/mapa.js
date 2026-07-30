/* Marchas de Cristo — mapa de provincia: zoom y desplazamiento sobre el SVG.
   Progresiva: sin JS el mapa se ve igual, estático (App\Mapa ya lo pinta
   recortado a la provincia).

   NO se reordena el DOM al pasar el ratón. Antes había un "traer al frente"
   que hacía capa.appendChild(a) en cada pointerenter para que el punto
   señalado se pintara sobre sus vecinos. Mover un nodo lo saca y lo vuelve a
   meter en el árbol, y con un centenar de municipios solapados eso deja el
   cursor parpadeando entre la mano y la flecha y se come el clic. El orden de
   pintado se decide ahora en servidor y es fijo (App\Mapa::pintarPuntos). */
(function () {
    'use strict';

    function initZoom(svg) {
        var home = svg.getAttribute('viewBox').split(/\s+/).map(Number);
        var view = home.slice(); // [minX, minY, w, h] actual
        var minW = home[2] * 0.12;  // tope de zoom-in (~8x)
        var maxW = home[2];         // no alejar más que la vista inicial

        // Puntos y rótulos a tamaño de pantalla constante: sin esto, al
        // acercar (viewBox más pequeño) crecerían en pantalla igual que el
        // contorno de la provincia. Se guarda el radio/tamaño de letra "base"
        // (a escala 1, tal como los pintó App\Mapa) y se reescalan en sentido
        // contrario cada vez que cambia el zoom.
        var capa = svg.querySelector('.mapa-puntos');
        var puntos = [];
        var baseFontSize = 0;
        if (capa) {
            var fontMatch = /--mapa-punto-font:\s*([\d.]+)/.exec(capa.getAttribute('style') || '');
            baseFontSize = fontMatch ? parseFloat(fontMatch[1]) : 0;
            Array.prototype.forEach.call(capa.querySelectorAll('a'), function (a) {
                var c = a.querySelector('.mapa-punto'),
                    h = a.querySelector('.mapa-punto-hit'),
                    t = a.querySelector('text');
                if (!c) return;
                puntos.push({
                    c: c, h: h, t: t,
                    r0: parseFloat(c.getAttribute('r')),
                    rh0: h ? parseFloat(h.getAttribute('r')) : 0,
                    cy: parseFloat(c.getAttribute('cy'))
                });
            });
        }

        function rescalePuntos() {
            if (!capa || puntos.length === 0) return;
            var scale = view[2] / home[2];
            var fontSize = baseFontSize * scale;
            capa.style.setProperty('--mapa-punto-font', fontSize + 'px');
            puntos.forEach(function (p) {
                var r = p.r0 * scale;
                p.c.setAttribute('r', r);
                // La diana se reescala igual que el punto: si no, al acercar
                // el zoom se quedaría enorme y taparía a los vecinos.
                if (p.h) p.h.setAttribute('r', p.rh0 * scale);
                if (p.t) p.t.setAttribute('y', p.cy - r - fontSize * 0.35);
            });
        }

        function apply() {
            svg.setAttribute('viewBox', view.join(' '));
            rescalePuntos();
        }

        function clampPan() {
            // No dejar que el recuadro visible se vaya del todo fuera de la provincia.
            var margin = view[2] * 0.6;
            var minX = home[0] - margin, maxX = home[0] + home[2] + margin - view[2];
            var minY = home[1] - margin, maxY = home[1] + home[3] + margin - view[3];
            if (maxX < minX) { view[0] = home[0] + (home[2] - view[2]) / 2; }
            else { view[0] = Math.min(maxX, Math.max(minX, view[0])); }
            if (maxY < minY) { view[1] = home[1] + (home[3] - view[3]) / 2; }
            else { view[1] = Math.min(maxY, Math.max(minY, view[1])); }
        }

        // Coordenadas de usuario del SVG (espacio del viewBox) para un punto de pantalla.
        function toUser(clientX, clientY) {
            var r = svg.getBoundingClientRect();
            if (!r.width || !r.height) return [view[0], view[1]];
            return [
                view[0] + ((clientX - r.left) / r.width) * view[2],
                view[1] + ((clientY - r.top) / r.height) * view[3]
            ];
        }

        function zoomAt(clientX, clientY, factor) {
            var p = toUser(clientX, clientY);
            var newW = Math.min(maxW, Math.max(minW, view[2] * factor));
            var newH = newW * (view[3] / view[2]);
            view[0] = p[0] - (p[0] - view[0]) * (newW / view[2]);
            view[1] = p[1] - (p[1] - view[1]) * (newH / view[3]);
            view[2] = newW; view[3] = newH;
            clampPan();
            apply();
        }

        svg.addEventListener('wheel', function (e) {
            e.preventDefault();
            zoomAt(e.clientX, e.clientY, e.deltaY > 0 ? 1.18 : 1 / 1.18);
        }, { passive: false });

        // Arrastrar para desplazar (ratón y táctil, vía Pointer Events).
        // El listener de pointermove solo está puesto entre pointerdown y
        // pointerup, y setPointerCapture no se llama hasta que el gesto supera
        // el umbral de 3 px: así un clic limpio no pasa nunca por la maquinaria
        // de arrastre y llega intacto al enlace del municipio. "moved" cancela
        // el click sintético que el navegador dispara igualmente al soltar
        // sobre el mismo elemento tras un arrastre real.
        var start = null;  // { id, x, y } desde pointerdown, antes de decidir si es arrastre
        var dragging = null; // { id, x, y } una vez confirmado el arrastre (con captura)
        var moved = false;

        function onPointerMove(e) {
            if (!start || e.pointerId !== start.id) return;
            var r = svg.getBoundingClientRect();
            if (!r.width || !r.height) return;
            if (!dragging) {
                if (Math.abs(e.clientX - start.x) <= 3 && Math.abs(e.clientY - start.y) <= 3) return;
                moved = true;
                dragging = { id: start.id, x: start.x, y: start.y };
                svg.setPointerCapture(e.pointerId);
                svg.classList.add('mapa-svg-dragging');
            }
            var dx = e.clientX - dragging.x, dy = e.clientY - dragging.y;
            view[0] -= dx / r.width * view[2];
            view[1] -= dy / r.height * view[3];
            dragging.x = e.clientX; dragging.y = e.clientY;
            clampPan();
            apply();
        }

        svg.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button !== 0) return;
            start = { id: e.pointerId, x: e.clientX, y: e.clientY };
            moved = false;
            svg.addEventListener('pointermove', onPointerMove);
        });
        function endDrag(e) {
            svg.removeEventListener('pointermove', onPointerMove);
            if (start && e.pointerId === start.id) {
                start = null;
                dragging = null;
                svg.classList.remove('mapa-svg-dragging');
            }
        }
        svg.addEventListener('pointerup', endDrag);
        svg.addEventListener('pointercancel', endDrag);
        svg.addEventListener('click', function (e) {
            if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
        }, true);

        // Botones +/−/reset (accesibles y para táctil sin rueda ni pellizco).
        var wrap = svg.closest('.mapa-wrap-provincia');
        if (wrap) {
            var ctrl = document.createElement('div');
            ctrl.className = 'mapa-zoom-ctrl';
            ctrl.innerHTML =
                '<button type="button" data-zoom-in aria-label="Acercar">+</button>' +
                '<button type="button" data-zoom-out aria-label="Alejar">−</button>' +
                '<button type="button" data-zoom-reset aria-label="Restablecer zoom">⤾</button>';
            wrap.appendChild(ctrl);
            var r = svg.getBoundingClientRect();
            var cx = r.left + r.width / 2, cy = r.top + r.height / 2;
            ctrl.querySelector('[data-zoom-in]').addEventListener('click', function () {
                var rr = svg.getBoundingClientRect();
                zoomAt(rr.left + rr.width / 2, rr.top + rr.height / 2, 1 / 1.5);
            });
            ctrl.querySelector('[data-zoom-out]').addEventListener('click', function () {
                var rr = svg.getBoundingClientRect();
                zoomAt(rr.left + rr.width / 2, rr.top + rr.height / 2, 1.5);
            });
            ctrl.querySelector('[data-zoom-reset]').addEventListener('click', function () {
                view = home.slice();
                apply();
            });
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('svg[data-zoom]'), initZoom);
})();
