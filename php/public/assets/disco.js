/* Marchas de Cristo — pantallas de disco del panel (alta y edición).
   Dos buscadores con el mismo patrón que los de admin.js: teclear → consultar
   → elegir de la lista → guardar el ID en un <input hidden>. Sin dependencias.

   1. Banda propietaria del disco (/api/banda/fastSearch).
   2. Marcha que se añade como pista (/api/marcha/fastSearch), que además
      acepta el ID exacto: en la carátula suele venir el número, no el título. */
(function () {
    'use strict';

    /**
     * Cablea un buscador de los de arriba.
     * @param {object} o raíz, campo de texto, hidden, panel, endpoint, mínimo de
     *                   caracteres, y funciones para pintar y etiquetar cada fila.
     */
    function autocompletar(o) {
        if (!o.input || !o.hidden || !o.panel) return;

        function cerrar() { o.panel.hidden = true; o.panel.innerHTML = ''; }

        function elegir(id, etiqueta) {
            o.hidden.value = id;
            o.input.value = etiqueta;
            cerrar();
            if (o.alElegir) o.alElegir(id, etiqueta);
        }

        var timer, ctrl;
        o.input.addEventListener('input', function () {
            var q = o.input.value.trim();
            // Si se vacía el campo hay que vaciar el hidden: si no, quedaría
            // seleccionado el último ID elegido aunque en pantalla no se vea.
            if (q === '') o.hidden.value = '';
            clearTimeout(timer);
            if (q.length < o.min) { cerrar(); return; }
            timer = setTimeout(function () {
                if (ctrl) ctrl.abort();
                ctrl = new AbortController();
                fetch(o.url + encodeURIComponent(q), { signal: ctrl.signal, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var filas = Array.isArray(data.data) ? data.data : [];
                        if (!filas.length) { cerrar(); return; }
                        o.panel.innerHTML = '';
                        filas.forEach(function (r) {
                            var b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'suggest-item';
                            b.innerHTML = o.pintar(r);
                            b.addEventListener('click', function () { elegir(o.id(r), o.etiqueta(r)); });
                            o.panel.appendChild(b);
                        });
                        o.panel.hidden = false;
                    })
                    .catch(function () { /* abortado */ });
            }, 200);
        });

        document.addEventListener('mousedown', function (e) {
            if (!o.panel.contains(e.target) && e.target !== o.input) cerrar();
        });
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Banda propietaria ───────────────────────────────────────────────────
    var bandaRaiz = document.querySelector('[data-banda-picker]');
    if (bandaRaiz) {
        autocompletar({
            input: bandaRaiz.querySelector('[data-banda-input]'),
            hidden: bandaRaiz.querySelector('[data-banda-id]'),
            panel: bandaRaiz.querySelector('[data-banda-suggest]'),
            url: '/api/banda/fastSearch?q=',
            min: 3,
            pintar: function (r) { return esc(r.LABEL || ('#' + r.ID_BANDA)); },
            id: function (r) { return r.ID_BANDA; },
            etiqueta: function (r) { return (r.LABEL || ('#' + r.ID_BANDA)) + ' (#' + r.ID_BANDA + ')'; },
        });
    }

    // ── Marcha que se añade como pista ──────────────────────────────────────
    // Solo existe en la ficha del disco (formulario de alta manual). La
    // pantalla de importación reutiliza este mismo fichero pero solo trae
    // buscadores por fila, así que este bloque se salta sin cortar el resto.
    var pistaRaiz = document.querySelector('[data-marcha-picker]');
    if (pistaRaiz) cablearAltaManual(pistaRaiz);

    function cablearAltaManual(pistaRaiz) {
        var previa = document.querySelector('[data-pista-previa]');
        var previaTitulo = previa && previa.querySelector('[data-previa-titulo]');
        var previaSub = previa && previa.querySelector('[data-previa-sub]');
        var numeroInput = document.querySelector('[data-pista-numero]');
        var volumenInput = document.querySelector('[data-pista-volumen]');
        var previaNum = previa && previa.querySelector('[data-previa-num]');

        function refrescarNumero() {
            if (!previaNum) return;
            var n = numeroInput && numeroInput.value ? numeroInput.value : '—';
            var v = volumenInput && volumenInput.value ? volumenInput.value : '1';
            previaNum.textContent = (volumenInput && volumenInput.max !== '1' && Number(v) > 1)
                ? ('vol. ' + v + ' · pista ' + n) : ('pista ' + n);
        }
        if (numeroInput) numeroInput.addEventListener('input', refrescarNumero);
        if (volumenInput) volumenInput.addEventListener('input', refrescarNumero);

        autocompletar({
            input: pistaRaiz.querySelector('[data-marcha-input]'),
            hidden: pistaRaiz.querySelector('[data-marcha-id]'),
            panel: pistaRaiz.querySelector('[data-marcha-suggest]'),
            url: '/api/marcha/fastSearch?q=',
            // 1 carácter basta si es un ID; el servidor ya exige 3 para texto.
            min: 1,
            pintar: function (r) {
                return '<strong>' + esc(r.TITULO) + '</strong> <span class="muted small">#' + esc(r.ID_MARCHA)
                    + (r.FECHA ? ' · ' + esc(r.FECHA) : '')
                    + (r.AUTORES ? ' · ' + esc(r.AUTORES) : '') + '</span>';
            },
            id: function (r) { return r.ID_MARCHA; },
            etiqueta: function (r) { return r.TITULO + ' (#' + r.ID_MARCHA + ')'; },
            alElegir: function (id, etiqueta) {
                if (!previa) return;
                previa.hidden = false;
                if (previaTitulo) previaTitulo.textContent = etiqueta;
                if (previaSub) previaSub.textContent = 'Marcha #' + id;
                refrescarNumero();
            },
        });
    }

    // ── Editar pista existente ──────────────────────────────────────────────
    // Cada fila de "Contenido del disco" trae su propia fila oculta con un
    // formulario de edición (marcha, número, volumen, duración). Un botón
    // "Editar" la muestra; hay un picker de marcha por fila, así que se
    // cablean todos con querySelectorAll en vez de uno solo como el de arriba.
    document.querySelectorAll('[data-marcha-picker-edit]').forEach(function (raiz) {
        autocompletar({
            input: raiz.querySelector('[data-marcha-input]'),
            hidden: raiz.querySelector('[data-marcha-id]'),
            panel: raiz.querySelector('[data-marcha-suggest]'),
            url: '/api/marcha/fastSearch?q=',
            min: 1,
            pintar: function (r) {
                return '<strong>' + esc(r.TITULO) + '</strong> <span class="muted small">#' + esc(r.ID_MARCHA)
                    + (r.FECHA ? ' · ' + esc(r.FECHA) : '')
                    + (r.AUTORES ? ' · ' + esc(r.AUTORES) : '') + '</span>';
            },
            id: function (r) { return r.ID_MARCHA; },
            etiqueta: function (r) { return r.TITULO + ' (#' + r.ID_MARCHA + ')'; },
        });
    });

    function filaEdicion(btn, attr) {
        var pid = btn.getAttribute(attr);
        return document.querySelector('[data-pista-edit-row="' + pid + '"]');
    }
    document.querySelectorAll('[data-pista-editar]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fila = filaEdicion(btn, 'data-pista-editar');
            if (fila) fila.hidden = !fila.hidden;
        });
    });
    document.querySelectorAll('[data-pista-editar-cancelar]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fila = filaEdicion(btn, 'data-pista-editar-cancelar');
            if (fila) fila.hidden = true;
        });
    });
})();
