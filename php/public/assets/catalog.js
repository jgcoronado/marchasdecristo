/* Marchas de Cristo — mejoras progresivas del catálogo (sin dependencias).
   Todo funciona sin JS; esto añade orden/filtro en tablas y el atajo "/". */
(function () {
    'use strict';

    // Orden por columna: <table data-sortable>, cabeceras con data-type="num" opcional.
    function initSort(table) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var dir = {};
        var ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));
        ths.forEach(function (th) {
            th.setAttribute('aria-sort', 'none');
            th.setAttribute('tabindex', '0');
        });

        function sortByCol(th, i) {
            var type = th.getAttribute('data-type');
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            dir[i] = !dir[i];
            rows.sort(function (a, b) {
                var x = (a.children[i] ? a.children[i].textContent : '').trim();
                var y = (b.children[i] ? b.children[i].textContent : '').trim();
                if (type === 'num') {
                    x = parseFloat(x) || 0; y = parseFloat(y) || 0;
                    return dir[i] ? x - y : y - x;
                }
                return dir[i] ? x.localeCompare(y, 'es') : y.localeCompare(x, 'es');
            }).forEach(function (r) { tbody.appendChild(r); });
            ths.forEach(function (h, j) {
                var ar = h.querySelector('.ar');
                if (j === i) {
                    h.setAttribute('aria-sort', dir[i] ? 'ascending' : 'descending');
                    if (ar) ar.textContent = dir[i] ? '↑' : '↓';
                } else {
                    h.setAttribute('aria-sort', 'none');
                    if (ar) ar.textContent = '↕';
                }
            });
        }

        ths.forEach(function (th, i) {
            th.addEventListener('click', function () { sortByCol(th, i); });
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortByCol(th, i); }
            });
        });
    }

    // Filtro de filas: <input data-filter="id-tabla" data-count="id-contador" data-total="N">
    function initFilter(input) {
        var table = document.getElementById(input.getAttribute('data-filter'));
        if (!table) return;
        var tbody = table.querySelector('tbody');
        var count = document.getElementById(input.getAttribute('data-count') || '');
        var initial = count ? count.textContent : '';
        var total = parseInt(input.getAttribute('data-total') || '0', 10);
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var vis = 0;
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (r) {
                var hit = q === '' || r.textContent.toLowerCase().indexOf(q) !== -1;
                r.classList.toggle('hidden', !hit);
                if (hit) vis++;
            });
            if (count) count.textContent = q === '' ? initial : vis + ' de ' + (total || vis);
        });
    }

    // Fachada de vídeo: al pulsar, sustituye la miniatura por el iframe de
    // youtube-nocookie con autoplay. Hasta entonces no se carga nada de YouTube.
    function initYtFacade(embed) {
        var btn = embed.querySelector('.ytfacade');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var id = embed.getAttribute('data-ytid');
            if (!id) return;
            var iframe = document.createElement('iframe');
            iframe.src = 'https://www.youtube-nocookie.com/embed/' + id +
                '?autoplay=1&rel=0';
            iframe.title = 'Reproductor de vídeo de YouTube';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            iframe.setAttribute('allowfullscreen', '');
            embed.innerHTML = '';
            embed.appendChild(iframe);
        });
    }

    // Fachada genérica para el resto de servicios (Spotify, Deezer, Apple):
    // no tienen miniatura pública que enseñar, así que la fachada es un botón
    // con el nombre del servicio y, al pulsarlo, se inserta su reproductor.
    // Igual que con YouTube, hasta ese clic no se pide nada a terceros.
    function initFacade(embed) {
        var btn = embed.querySelector('.mdfacade');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var src = embed.getAttribute('data-embed');
            if (!src) return;
            var iframe = document.createElement('iframe');
            iframe.src = src;
            iframe.title = embed.getAttribute('data-embed-title') || 'Reproductor de audio';
            iframe.allow = 'autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture';
            iframe.setAttribute('loading', 'lazy');
            embed.innerHTML = '';
            embed.appendChild(iframe);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('table[data-sortable]'), initSort);
    Array.prototype.forEach.call(document.querySelectorAll('input[data-filter]'), initFilter);
    Array.prototype.forEach.call(document.querySelectorAll('.ytembed[data-ytid]'), initYtFacade);
    Array.prototype.forEach.call(document.querySelectorAll('.mdembed[data-embed]'), initFacade);

    // Autocompletado global (M3): desplegable en vivo contra /api/buscar.
    // Mejora progresiva — sin JS, el formulario envía a /buscar igualmente.
    function initAutocomplete(input) {
        var panel = document.getElementById('site-ac');
        if (!panel) return;
        var items = [];   // [{ url, el }]
        var active = -1;
        var timer = null;
        var lastQ = '';

        function close() {
            panel.hidden = true;
            panel.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            items = []; active = -1;
        }

        function setActive(i) {
            if (active >= 0 && items[active]) items[active].el.classList.remove('on');
            active = i;
            if (active >= 0 && items[active]) {
                items[active].el.classList.add('on');
                input.setAttribute('aria-activedescendant', items[active].el.id);
                items[active].el.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function render(data) {
            panel.innerHTML = '';
            items = []; active = -1;
            var grupos = (data && data.grupos) || {};
            var n = 0;
            Object.keys(grupos).forEach(function (key) {
                var g = grupos[key];
                if (!g.items || !g.items.length) return;
                var head = document.createElement('div');
                head.className = 'ac-group';
                head.textContent = g.etiqueta;
                panel.appendChild(head);
                g.items.forEach(function (it) {
                    var a = document.createElement('a');
                    a.className = 'ac-item';
                    a.id = 'ac-opt-' + (n++);
                    a.href = it.url;
                    a.setAttribute('role', 'option');
                    var t = document.createElement('span');
                    t.className = 'ac-title';
                    t.textContent = it.titulo;
                    a.appendChild(t);
                    if (it.sub) {
                        var s = document.createElement('span');
                        s.className = 'ac-sub';
                        s.textContent = it.sub;
                        a.appendChild(s);
                    }
                    // mousedown (antes del blur del input) para no perder el clic.
                    a.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        window.location.href = it.url;
                    });
                    panel.appendChild(a);
                    items.push({ url: it.url, el: a });
                });
            });
            if (!items.length) {
                var empty = document.createElement('div');
                empty.className = 'ac-empty';
                empty.textContent = 'Sin coincidencias';
                panel.appendChild(empty);
            }
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function fetchNow(query) {
            fetch('/api/buscar?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || input.value.trim() !== query) return; // respuesta obsoleta
                    render(data);
                })
                .catch(function () { /* silencio: el submit a /buscar sigue funcionando */ });
        }

        input.addEventListener('input', function () {
            var query = input.value.trim();
            if (query === lastQ) return;
            lastQ = query;
            if (timer) clearTimeout(timer);
            if (query.length < 2) { close(); return; }
            timer = setTimeout(function () { fetchNow(query); }, 150);
        });

        input.addEventListener('keydown', function (e) {
            if (panel.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1 >= items.length ? 0 : active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1 < 0 ? items.length - 1 : active - 1); }
            else if (e.key === 'Enter') {
                if (active >= 0 && items[active]) { e.preventDefault(); window.location.href = items[active].url; }
                // sin opción activa → el formulario envía a /buscar
            } else if (e.key === 'Escape') { close(); }
        });

        input.addEventListener('blur', function () { setTimeout(close, 120); });
    }

    // "/" salta al buscador del catálogo (salvo que ya se esté escribiendo).
    var q = document.getElementById('site-q');
    if (q) {
        document.addEventListener('keydown', function (e) {
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) {
                e.preventDefault();
                q.focus();
            }
        });
        initAutocomplete(q);
    }
})();
