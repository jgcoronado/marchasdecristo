/* Buscador del panel: pestañas de entidad + predictivo que salta directamente
   a la ficha. Sin dependencias, y todo lo que hace es opcional: sin JS las
   pestañas son enlaces normales y el formulario se envía como siempre. */
(function () {
    const root = document.querySelector('[data-dash-search]');
    if (!root) return;

    const input = root.querySelector('[data-dash-input]');
    const suggest = root.querySelector('[data-dash-suggest]');
    const tipoField = root.querySelector('[data-dash-tipo]');
    const tabs = Array.from(root.querySelectorAll('[data-dash-tab]'));
    if (!input || !suggest || !tipoField) return;

    // param GET que corresponde a cada tipo (para el fallback de Enter sin sugerencia)
    const paramForTipo = { marcha: 'q', autor: 'q', banda: 'qb', disco: 'qd' };

    let items = [];   // filas de la última respuesta
    let cursor = -1;  // fila resaltada con el teclado

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
        rows.forEach((r) => {
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
            b.addEventListener('click', () => go(r.url));
            suggest.appendChild(b);
        });
        suggest.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    let timer, controller;
    function query() {
        const q = input.value.trim();
        clearTimeout(timer);
        // Con dos caracteres basta si son un ID ("7"); para texto se pide más.
        if (q === '' || (q.length < 3 && !/^\d+$/.test(q))) { close(); return; }
        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            try {
                const url = '/api/dashboard/fastSearch?tipo=' + encodeURIComponent(tipoField.value) +
                    '&q=' + encodeURIComponent(q);
                const res = await fetch(url, { signal: controller.signal, credentials: 'same-origin' });
                const data = await res.json();
                const rows = Array.isArray(data.data) ? data.data : [];
                if (!rows.length) { close(); return; }
                render(rows);
            } catch (e) { /* abortado o red: ignorar */ }
        }, 200);
    }

    input.addEventListener('input', query);
    input.addEventListener('focus', () => { if (input.value.trim() !== '') query(); });

    input.addEventListener('keydown', (e) => {
        // Fallback de Enter cuando no hay desplegable: navegar al listado
        if (e.key === 'Enter' && suggest.hidden) {
            const q = input.value.trim();
            if (q) {
                e.preventDefault();
                const param = paramForTipo[tipoField.value] || 'q';
                window.location.href = '/dashboard?' + param + '=' + encodeURIComponent(q);
            }
            return;
        }
        if (suggest.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); highlight(cursor + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(cursor - 1); }
        else if (e.key === 'Escape') { close(); }
        else if (e.key === 'Enter' && cursor >= 0 && items[cursor]) {
            // Solo si hay una fila resaltada: sin ella, Enter envía el formulario.
            e.preventDefault();
            go(items[cursor].url);
        }
    });

    // Cambiar de entidad no recarga: se reaprovecha lo ya escrito y se relanza
    // el predictivo. La URL se mantiene al día para que recargar o compartir
    // el enlace siga llevando a la misma pestaña.
    tabs.forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            tipoField.value = tab.dataset.dashTab;
            input.placeholder = tab.dataset.dashPh || '';
            tabs.forEach((t) => {
                const on = t === tab;
                t.classList.toggle('is-on', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            if (window.history && window.history.replaceState) {
                const q = input.value.trim();
                const param = paramForTipo[tab.dataset.dashTab] || 'q';
                const url = q ? '/dashboard?' + param + '=' + encodeURIComponent(q) : '/dashboard';
                window.history.replaceState(null, '', url);
            }
            close();
            input.focus();
            query();
        });
    });

    document.addEventListener('mousedown', (e) => {
        if (!suggest.contains(e.target) && e.target !== input) close();
    });
})();
