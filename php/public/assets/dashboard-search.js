/* Predictivo del panel: una instancia independiente por cada caja de búsqueda.
   Sin dependencias. Sin JS, las cajas siguen funcionando como formularios normales. */
(function () {
    function initBox(box) {
        const tipo    = box.dataset.dashBox;
        const input   = box.querySelector('[data-dash-input]');
        const suggest = box.querySelector('[data-dash-suggest]');
        if (!input || !suggest) return;

        let items = [];
        let cursor = -1;

        function close() {
            suggest.hidden = true;
            suggest.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            items = [];
            cursor = -1;
        }

        function highlight(n) {
            const nodes = suggest.querySelectorAll('.suggest-item');
            if (!nodes.length) return;
            cursor = (n + nodes.length) % nodes.length;
            nodes.forEach((el, i) => el.classList.toggle('is-on', i === cursor));
            nodes[cursor].scrollIntoView({ block: 'nearest' });
        }

        function go(url) {
            if (url) window.location.href = url;
        }

        function render(rows) {
            items = rows;
            cursor = -1;
            suggest.innerHTML = '';
            rows.forEach(function (r) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'suggest-item';
                b.setAttribute('role', 'option');
                const id = document.createElement('span');
                id.className = 'suggest-id';
                id.textContent = '#' + r.id;
                b.append(id, document.createTextNode(r.label || ''));
                if (r.meta) {
                    const m = document.createElement('span');
                    m.className = 'suggest-meta';
                    m.textContent = r.meta;
                    b.appendChild(m);
                }
                b.addEventListener('click', function () { go(r.url); });
                suggest.appendChild(b);
            });
            suggest.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        let timer, controller;
        function query() {
            const q = input.value.trim();
            clearTimeout(timer);
            // Un número solo (ID) se acepta con 1+ dígitos; texto desde 3 caracteres
            if (q === '' || (q.length < 3 && !/^\d+$/.test(q))) { close(); return; }
            timer = setTimeout(async function () {
                if (controller) controller.abort();
                controller = new AbortController();
                try {
                    const url = '/api/dashboard/fastSearch?tipo=' + encodeURIComponent(tipo) +
                        '&q=' + encodeURIComponent(q);
                    const res = await fetch(url, { signal: controller.signal, credentials: 'same-origin' });
                    const data = await res.json();
                    const rows = Array.isArray(data.data) ? data.data : [];
                    if (!rows.length) { close(); return; }
                    render(rows);
                } catch (e) { /* abortado o red: ignorar */ }
            }, 180);
        }

        input.addEventListener('input', query);
        input.addEventListener('focus', function () { if (input.value.trim() !== '') query(); });

        input.addEventListener('keydown', function (e) {
            if (suggest.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); highlight(cursor + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(cursor - 1); }
            else if (e.key === 'Escape') { close(); }
            else if (e.key === 'Enter' && cursor >= 0 && items[cursor]) {
                e.preventDefault();
                go(items[cursor].url);
            }
        });

        document.addEventListener('mousedown', function (e) {
            if (!suggest.contains(e.target) && e.target !== input) close();
        });
    }

    document.querySelectorAll('[data-dash-box]').forEach(initBox);
})();
