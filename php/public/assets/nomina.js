// Panel /dashboard/semana-santa: arrastrar para reordenar (HTML5 drag & drop
// nativo, sin librerías). Las flechas ↑↓ de cada fila son el respaldo sin JS
// (ver app/templates/admin/semana_santa.php) y siguen funcionando igual con
// este script cargado.
(function () {
    'use strict';

    // Conservar el scroll: cada alta/renombrado/borrado es un POST con redirect
    // a esta misma página, que volvería arriba del todo. Se guarda la posición
    // al salir y se restaura al volver si han pasado pocos segundos. Con ?err=
    // no se restaura, para que el aviso de error (arriba) quede a la vista.
    var claveScroll = 'nomina-scroll:' + location.pathname;
    try {
        var guardado = JSON.parse(sessionStorage.getItem(claveScroll) || 'null');
        sessionStorage.removeItem(claveScroll);
        if (guardado && Date.now() - guardado.t < 15000 && !/[?&]err=/.test(location.search)) {
            window.scrollTo(0, guardado.y);
        }
    } catch (e) { /* sin sessionStorage: se queda arriba */ }
    window.addEventListener('beforeunload', function () {
        try {
            sessionStorage.setItem(claveScroll, JSON.stringify({ y: window.scrollY, t: Date.now() }));
        } catch (e) { /* nada */ }
    });

    /** Elemento tras el que hay que insertar el que se está arrastrando, según la posición vertical del ratón. */
    function elementoTrasArrastre(contenedor, y, selector) {
        var els = Array.prototype.slice.call(contenedor.querySelectorAll(selector))
            .filter(function (el) { return el !== arrastrando; });
        var masCercano = { offset: -Infinity, elemento: null };
        els.forEach(function (el) {
            var box = el.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > masCercano.offset) {
                masCercano = { offset: offset, elemento: el };
            }
        });
        return masCercano.elemento;
    }

    var arrastrando = null;
    var ordenInicial = '';

    /**
     * Activa arrastrar-y-soltar sobre $contenedor para reordenar sus hijos
     * directos que cumplan $selector, y guarda el orden al soltar si ha
     * cambiado.
     */
    function activarArrastre(contenedor, selector) {
        var filas = contenedor.querySelectorAll(selector);
        filas.forEach(function (fila) {
            // Las listas van anidadas (día > hermandad > paso): la fila sólo se
            // arrastra desde su propia asa, así los inputs de dentro siguen
            // siendo editables y arrastrar un paso no arrastra su día.
            fila.draggable = false;
            var asa = fila.querySelector('.drag-handle');
            if (asa) {
                asa.addEventListener('mousedown', function () { fila.draggable = true; });
                asa.addEventListener('mouseup', function () { fila.draggable = false; });
            }
            fila.addEventListener('dragstart', function (e) {
                if (e.target !== fila) return;
                e.stopPropagation();
                arrastrando = fila;
                ordenInicial = idsDe(contenedor).join(',');
                fila.classList.add('arrastrando');
            });
            fila.addEventListener('dragend', function (e) {
                if (e.target !== fila) return;
                fila.classList.remove('arrastrando');
                fila.draggable = false;
                arrastrando = null;
                if (idsDe(contenedor).join(',') !== ordenInicial) guardarOrden(contenedor);
            });
        });

        contenedor.addEventListener('dragover', function (e) {
            // Sólo reordena hijos propios; el arrastre de una lista anidada no le toca.
            if (!arrastrando || arrastrando.parentNode !== contenedor) return;
            e.stopPropagation();
            e.preventDefault();
            var despues = elementoTrasArrastre(contenedor, e.clientY, selector);
            if (despues == null) {
                contenedor.appendChild(arrastrando);
            } else {
                contenedor.insertBefore(arrastrando, despues);
            }
        });

        contenedor.addEventListener('drop', function (e) {
            if (!arrastrando || arrastrando.parentNode !== contenedor) return;
            e.stopPropagation();
            e.preventDefault();
        });
    }

    function idsDe(lista) {
        return Array.prototype.map.call(lista.querySelectorAll(':scope > [data-id]'), function (fila) {
            return fila.getAttribute('data-id');
        });
    }

    /**
     * Guarda el orden actual de $lista en segundo plano (sin recargar): manda
     * el form de reordenar de la lista con los IDs en orden DOM como orden[].
     * El servidor responde con un redirect PRG; si la URL final lleva ?err=,
     * no se guardó y se recarga para que la página vuelva al orden real.
     */
    function guardarOrden(lista) {
        var form = document.getElementById(lista.getAttribute('data-form'));
        var estado = document.querySelector('[data-estado-orden="' + lista.id + '"]');
        if (!form) return;
        var datos = new FormData(form);
        datos.delete('orden[]');
        idsDe(lista).forEach(function (id) { datos.append('orden[]', id); });
        if (estado) { estado.hidden = false; estado.textContent = 'Guardando…'; }
        fetch(form.action, { method: 'POST', body: datos, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok || /[?&]err=/.test(r.url)) throw new Error('No se guardó');
                if (estado) estado.textContent = 'Orden guardado ✓';
            })
            .catch(function () {
                if (estado) estado.textContent = 'Error al guardar el orden; recargando…';
                setTimeout(function () { location.reload(); }, 1200);
            });
    }
    // ── /dashboard/acompanamientos/{localidad} con nómina ───────────────────
    // Una línea de rango (tr[data-rango]) se arrastra desde su asa y se suelta
    // en la tabla de otro paso (tbody[data-paso-drop]); o se mueve con
    // «Mover…» / «Mover seleccionados…», que abren el diálogo con el selector
    // de paso. Todo acaba en el mismo form POST (mover-paso) y recarga.
    var formMover = document.getElementById('moverPasoForm');
    if (formMover) {
        var dlgMover = document.getElementById('dlgMoverPaso');
        var destino = document.getElementById('moverPasoDestino');
        var listaMover = document.getElementById('moverPasoLista');
        var rangoArrastrado = null;

        var abrirMover = function (ids, etiquetas) {
            document.getElementById('moverPasoIds').value = ids.join(',');
            listaMover.innerHTML = '';
            etiquetas.forEach(function (t) {
                var li = document.createElement('li');
                li.textContent = t;
                listaMover.appendChild(li);
            });
            destino.value = '';
            dlgMover.showModal();
        };

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-mover-paso]');
            if (!btn) return;
            abrirMover([btn.closest('tr').getAttribute('data-ids')], [btn.getAttribute('data-etiqueta')]);
        });
        var btnSel = document.getElementById('btnMoverSeleccionados');
        if (btnSel) {
            btnSel.addEventListener('click', function () {
                var marcados = Array.prototype.filter.call(document.querySelectorAll('.acomp-check'), function (c) { return c.checked; });
                if (!marcados.length) return;
                abrirMover(
                    marcados.map(function (c) { return c.getAttribute('data-ids'); }),
                    marcados.map(function (c) { return c.getAttribute('data-etiqueta'); })
                );
            });
        }
        document.getElementById('btnCancelarMover').addEventListener('click', function () { dlgMover.close(); });

        document.querySelectorAll('tr[data-rango]').forEach(function (tr) {
            tr.draggable = false;
            var asa = tr.querySelector('.drag-handle');
            if (asa) {
                asa.addEventListener('mousedown', function () { tr.draggable = true; });
                asa.addEventListener('mouseup', function () { tr.draggable = false; });
            }
            tr.addEventListener('dragstart', function (e) {
                // Si la fila arrastrada está marcada, se llevan todas las
                // marcadas (de cualquier paso, o de fuera de la nómina); si no,
                // solo ella.
                var marcada = tr.querySelector('.acomp-check');
                rangoArrastrado = marcada && marcada.checked
                    ? Array.prototype.map.call(document.querySelectorAll('.acomp-check:checked'), function (c) { return c.closest('tr'); })
                    : [tr];
                rangoArrastrado.forEach(function (f) { f.classList.add('arrastrando'); });
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', tr.getAttribute('data-ids')); // Firefox no arrastra sin datos
                if (rangoArrastrado.length > 1) {
                    var etiqueta = document.createElement('div');
                    etiqueta.className = 'arrastre-varios';
                    etiqueta.textContent = rangoArrastrado.length + ' líneas de acompañamiento';
                    document.body.appendChild(etiqueta);
                    e.dataTransfer.setDragImage(etiqueta, 12, 12);
                    setTimeout(function () { etiqueta.remove(); }, 0);
                }
            });
            tr.addEventListener('dragend', function () {
                (rangoArrastrado || [tr]).forEach(function (f) { f.classList.remove('arrastrando'); });
                tr.draggable = false;
                rangoArrastrado = null;
                document.querySelectorAll('.drop-activo').forEach(function (z) { z.classList.remove('drop-activo'); });
            });
        });

        // Filas arrastradas que no están ya en este paso (soltar una sobre su propio paso no hace nada).
        var ajenas = function (zona) {
            return (rangoArrastrado || []).filter(function (f) { return f.parentNode !== zona; });
        };
        document.querySelectorAll('[data-paso-drop]').forEach(function (zona) {
            var valida = function () { return ajenas(zona).length > 0; };
            zona.addEventListener('dragover', function (e) {
                if (!valida()) return;
                e.preventDefault();
                zona.classList.add('drop-activo');
            });
            zona.addEventListener('dragleave', function (e) {
                if (!zona.contains(e.relatedTarget)) zona.classList.remove('drop-activo');
            });
            zona.addEventListener('drop', function (e) {
                if (!valida()) return;
                e.preventDefault();
                document.getElementById('moverPasoIds').value = ajenas(zona).map(function (f) { return f.getAttribute('data-ids'); }).join(',');
                destino.value = zona.getAttribute('data-id-paso');
                formMover.submit();
            });
        });
    }

    // Días de la localidad.
    var diasList = document.getElementById('diasList');
    if (diasList) activarArrastre(diasList, '[data-dia-row]');

    // Hermandades de cada día.
    document.querySelectorAll('[data-hermandades-reorder]').forEach(function (lista) {
        activarArrastre(lista, '[data-hermandad-row]');
    });

    // Pasos de cada hermandad (la cruz de guía no es arrastrable: no lleva draggable="true").
    document.querySelectorAll('[data-pasos-reorder]').forEach(function (lista) {
        activarArrastre(lista, '[data-paso-row][draggable="true"]');
    });

})();
