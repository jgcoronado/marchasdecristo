/* Autocomplete de autores para el formulario de marcha (JS mínimo, sin dependencias). */
(function () {
    const box = document.getElementById('autoresBox');
    const search = document.getElementById('autorSearch');
    const suggest = document.getElementById('autorSuggest');
    if (!box || !search || !suggest) return;

    const selectedIds = () =>
        Array.from(box.querySelectorAll('input[name="autoresIds[]"]')).map((i) => i.value);

    function addChip(id, label) {
        if (selectedIds().includes(String(id))) return;
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.dataset.id = id;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'autoresIds[]';
        hidden.value = id;
        const span = document.createElement('span');
        span.textContent = label;
        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'chip-x';
        x.setAttribute('aria-label', 'Quitar');
        x.textContent = '×';
        chip.append(hidden, span, x);
        box.appendChild(chip);
        triggerDuplicateCheck();
    }

    box.addEventListener('click', (e) => {
        if (e.target.classList.contains('chip-x')) {
            e.target.closest('.chip').remove();
            triggerDuplicateCheck();
        }
    });

    // API pública mínima para que otras páginas (p.ej. revisión de ingesta)
    // puedan añadir un autor ya conocido sin pasar por el cuadro de búsqueda.
    window.AutorAutocomplete = { addChip, selectedIds };

    function closeSuggest() { suggest.hidden = true; suggest.innerHTML = ''; }

    let timer, controller;
    search.addEventListener('input', () => {
        const q = search.value.trim();
        clearTimeout(timer);
        if (q.length < 3) { closeSuggest(); return; }
        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            try {
                const res = await fetch('/api/autor/fastSearch?nombre=' + encodeURIComponent(q),
                    { signal: controller.signal, credentials: 'same-origin' });
                const data = await res.json();
                const rows = Array.isArray(data.data) ? data.data : [];
                const sel = selectedIds();
                const items = rows.filter((r) => !sel.includes(String(r.ID_AUTOR)));
                if (!items.length) { closeSuggest(); return; }
                suggest.innerHTML = '';
                items.forEach((r) => {
                    const label = r.NOMBRE_COMPLETO || ((r.APELLIDOS || '') + ' ' + (r.NOMBRE || '')).trim();
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'suggest-item';
                    b.textContent = label;
                    b.addEventListener('click', () => {
                        clearTimeout(timer);
                        if (controller) { controller.abort(); controller = null; }
                        addChip(r.ID_AUTOR, label);
                        search.value = '';
                        closeSuggest();
                        search.focus();
                    });
                    suggest.appendChild(b);
                });
                suggest.hidden = false;
            } catch (e) { /* abortado o red: ignorar */ }
        }, 200);
    });

    document.addEventListener('mousedown', (e) => {
        if (!suggest.contains(e.target) && e.target !== search) closeSuggest();
    });
})();

/* Selector en cascada provincia → localidad (MunicipioRepo: catálogo cerrado,
   una localidad pertenece siempre a una única provincia). Se instancia sobre
   cualquier <select data-municipio-provincia> + <input data-municipio-localidad>
   que compartan contenedor — puede haber varios en la misma página. Si lo que
   se escribe no está en el catálogo, se ofrece añadirlo (admin: de alta en el
   momento; editor: se usa tal cual, viaja dentro de su propuesta y el admin lo
   da de alta al revisarla, con el mismo endpoint). */
(function () {
    function closeSuggest(el) { el.hidden = true; el.innerHTML = ''; }

    function initMunicipioPicker(root) {
        const provSel = root.querySelector('[data-municipio-provincia]');
        const locInput = root.querySelector('[data-municipio-localidad]');
        const suggest = root.querySelector('[data-municipio-suggest]');
        if (!provSel || !locInput || !suggest) return;
        const isAdmin = root.dataset.municipioAdmin === '1';
        const csrf = root.dataset.municipioCsrf || '';

        function syncDisabled() {
            const hay = provSel.value.trim() !== '';
            locInput.disabled = !hay;
            locInput.placeholder = hay ? 'Escribe para buscar…' : 'Elige antes la provincia';
        }
        syncDisabled();

        provSel.addEventListener('change', () => {
            locInput.value = '';
            syncDisabled();
            closeSuggest(suggest);
        });

        function addItem(text, cls, onClick) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = cls;
            b.textContent = text;
            b.addEventListener('click', onClick);
            suggest.appendChild(b);
        }

        async function crearYUsar(provincia, nombre) {
            try {
                const body = new URLSearchParams({ provincia, nombre, _csrf: csrf });
                const res = await fetch('/dashboard/municipio/add', {
                    method: 'POST', credentials: 'same-origin', body,
                });
                const data = await res.json();
                if (data.code !== 'CREATED') { alert('No se pudo añadir: ' + data.code); return; }
                locInput.value = nombre;
            } catch (_) { alert('No se pudo añadir: error de red'); }
            closeSuggest(suggest);
            locInput.focus();
        }

        let timer, ctrl;
        locInput.addEventListener('input', () => {
            const provincia = provSel.value.trim();
            const q = locInput.value.trim();
            clearTimeout(timer);
            if (!provincia || q.length < 2) { closeSuggest(suggest); return; }
            timer = setTimeout(async () => {
                if (ctrl) ctrl.abort();
                ctrl = new AbortController();
                try {
                    const res = await fetch(
                        '/api/municipio/fastSearch?provincia=' + encodeURIComponent(provincia) + '&q=' + encodeURIComponent(q),
                        { signal: ctrl.signal, credentials: 'same-origin' }
                    );
                    const data = await res.json();
                    const items = Array.isArray(data.data) ? data.data : [];
                    suggest.innerHTML = '';
                    const hayExacta = items.some((v) => v.toLowerCase() === q.toLowerCase());
                    items.forEach((val) => {
                        addItem(val, 'suggest-item', () => {
                            locInput.value = val;
                            closeSuggest(suggest);
                            locInput.focus();
                        });
                    });
                    if (!hayExacta) {
                        addItem(
                            isAdmin ? '+ Añadir «' + q + '» a ' + provincia : 'Usar «' + q + '» (se propondrá al administrador)',
                            'suggest-item suggest-item-add',
                            () => { isAdmin ? crearYUsar(provincia, q) : (locInput.value = q, closeSuggest(suggest)); }
                        );
                    }
                    if (!items.length && hayExacta) { closeSuggest(suggest); return; }
                    suggest.hidden = false;
                } catch (_) { /* abortado */ }
            }, 200);
        });

        document.addEventListener('mousedown', (e) => {
            if (!suggest.contains(e.target) && e.target !== locInput) closeSuggest(suggest);
        });

        // API pública mínima: rellenar el par desde otro widget (p.ej. al elegir
        // una dedicatoria ya existente en la revisión de ingesta) sin pasar por
        // el buscador — dispara 'change' para que syncDisabled() se aplique.
        root.municipioSetValue = function (provincia, nombre) {
            if (provincia) {
                provSel.value = provincia;
                provSel.dispatchEvent(new Event('change'));
            }
            if (nombre) locInput.value = nombre;
        };
    }

    document.querySelectorAll('[data-municipio-picker]').forEach(initMunicipioPicker);
})();

/* Autocomplete de banda de estreno (single-select) en el formulario de marcha. */
(function () {
    const hidden  = document.getElementById('BANDA_ESTRENO');
    const search  = document.getElementById('bandaEstrenoSearch');
    const suggest = document.getElementById('bandaEstrenoSuggest');
    const clear   = document.getElementById('bandaEstrenoClear');
    if (!hidden || !search || !suggest) return;

    function close() { suggest.hidden = true; suggest.innerHTML = ''; }

    function setChosen(id, label) {
        hidden.value = id;
        search.value = label + ' (#' + id + ')';
        close();
        search.focus();
    }

    if (clear) {
        clear.addEventListener('click', () => {
            hidden.value = '';
            search.value = '';
            close();
        });
    }

    let timer, ctrl;
    search.addEventListener('input', () => {
        const q = search.value.trim();
        // Si el usuario borra el campo, limpiar el hidden también.
        if (q === '') hidden.value = '';
        clearTimeout(timer);
        if (q.length < 3) { close(); return; }
        timer = setTimeout(async () => {
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            try {
                const res = await fetch('/api/banda/fastSearch?q=' + encodeURIComponent(q),
                    { signal: ctrl.signal, credentials: 'same-origin' });
                const data = await res.json();
                const rows = Array.isArray(data.data) ? data.data : [];
                if (!rows.length) { close(); return; }
                suggest.innerHTML = '';
                rows.forEach((r) => {
                    const label = r.LABEL || ('#' + r.ID_BANDA);
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'suggest-item';
                    b.textContent = label;
                    b.addEventListener('click', () => setChosen(r.ID_BANDA, label));
                    suggest.appendChild(b);
                });
                suggest.hidden = false;
            } catch (_) { /* abortado */ }
        }, 200);
    });

    document.addEventListener('mousedown', (e) => {
        if (!suggest.contains(e.target) && e.target !== search) close();
    });
})();

/* Comprobación de duplicados: avisa si ya existe una marcha con título similar (≥ 80 %)
   para los mismos autores. Se dispara al cambiar el título o los autores. */
(function () {
    const tituloInput = document.getElementById('TITULO');
    const alert = document.getElementById('duplicateAlert');
    if (!tituloInput || !alert) return;

    const excludeId = (typeof window._marchaExcludeId !== 'undefined') ? window._marchaExcludeId : 0;

    function getAutorIds() {
        return Array.from(document.querySelectorAll('input[name="autoresIds[]"]')).map((i) => i.value);
    }

    let timer, ctrl;

    function triggerCheck() {
        const titulo = tituloInput.value.trim();
        const ids = getAutorIds();
        clearTimeout(timer);
        alert.hidden = true;
        if (titulo.length < 3 || ids.length === 0) return;
        timer = setTimeout(async () => {
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            try {
                const params = new URLSearchParams({ titulo, excludeId });
                ids.forEach((id) => params.append('autorIds[]', id));
                const res = await fetch('/api/marcha/checkDuplicate?' + params.toString(),
                    { signal: ctrl.signal, credentials: 'same-origin' });
                const data = await res.json();
                const hits = Array.isArray(data.data) ? data.data : [];
                if (!hits.length) { alert.hidden = true; return; }
                const links = hits.map((h) =>
                    '<a href="/dashboard/marcha/' + h.ID_MARCHA + '" target="_blank">' +
                    escHtml(h.TITULO) + ' (M-' + h.ID_MARCHA + ', ' + Math.round(h.sim * 100) + '% similitud)</a>'
                ).join('; ');
                alert.innerHTML = '⚠️ Posible duplicado — ya existe una marcha similar: ' + links + '. Revísala antes de continuar.';
                alert.hidden = false;
            } catch (_) { /* abortado o red */ }
        }, 400);
    }

    function escHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    tituloInput.addEventListener('input', triggerCheck);

    // Se llama también desde el bloque de autores al añadir/quitar chips.
    window.triggerDuplicateCheck = triggerCheck;
})();

/* Autocomplete de banda (single-select) en el formulario de alta de contrato
   (temporada, N-04). Mismo patrón que el selector de banda de estreno, con
   un "Seleccionada:" aparte en vez de escribir la elección en el buscador
   (igual que banda-relaciones.js). */
(function () {
    const search  = document.getElementById('contratoBandaSearch');
    const hidden  = document.getElementById('ID_BANDA');
    const suggest = document.getElementById('contratoBandaSuggest');
    const chosen  = document.getElementById('contratoBandaChosen');
    if (!search || !hidden || !suggest) return;

    function close() { suggest.hidden = true; suggest.innerHTML = ''; }

    function setChosen(id, label) {
        hidden.value = id;
        if (chosen) chosen.textContent = label + ' (#' + id + ')';
        search.value = '';
        close();
        search.focus();
    }

    let timer, ctrl;
    search.addEventListener('input', () => {
        const q = search.value.trim();
        if (q === '') hidden.value = '';
        clearTimeout(timer);
        if (q.length < 3) { close(); return; }
        timer = setTimeout(async () => {
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            try {
                const res = await fetch('/api/banda/fastSearch?q=' + encodeURIComponent(q),
                    { signal: ctrl.signal, credentials: 'same-origin' });
                const data = await res.json();
                const rows = Array.isArray(data.data) ? data.data : [];
                if (!rows.length) { close(); return; }
                suggest.innerHTML = '';
                rows.forEach((r) => {
                    const label = r.LABEL || ('#' + r.ID_BANDA);
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'suggest-item';
                    b.textContent = label;
                    b.addEventListener('click', () => setChosen(r.ID_BANDA, label));
                    suggest.appendChild(b);
                });
                suggest.hidden = false;
            } catch (_) { /* abortado */ }
        }, 200);
    });

    document.addEventListener('mousedown', (e) => {
        if (!suggest.contains(e.target) && e.target !== search) close();
    });
})();
