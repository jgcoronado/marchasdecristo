/* Formularios de acompañamientos con destino libre: ficha de banda (alta y
   el flotante de «Editar» / «+ Añadir en AAAA») y pantalla de acompañamientos
   de una localidad. Necesita en la página un <script type="application/json"
   id="acompNomina"> con NominaRepo::datosAltaBanda() (o su parte de la
   localidad) y formularios .acomp-form con LOCALIDAD, DIA, HERMANDAD y PASO. */
(function () {
    // Formularios de acompañamientos (alta general y el flotante de «Editar» /
    // «+ Añadir en AAAA»): día, hermandad y paso tienen un predictivo con lo
    // que ya hay en la nómina de la localidad elegida (el paso, de la hermandad
    // elegida), pero admiten un valor nuevo; se avisa de lo que se va a crear
    // en la nómina.
    var forms = Array.prototype.slice.call(document.querySelectorAll('.acomp-form'));
    if (!forms.length) return;
    var datos = JSON.parse(document.getElementById('acompNomina').textContent);
    var slug = function (s) {
        return s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    };
    var mismo = function (a, b) { return a.trim() !== '' && slug(a) === slug(b || ''); };
    // dias: todos los de la hermandad (principal, otros de este año y de años pasados).
    var saleEl = function (h, dia) { return h.dias.some(function (d) { return mismo(dia, d); }); };
    var localidad = function (form) {
        for (var k in datos) if (mismo(form.elements.LOCALIDAD.value, k)) return datos[k];
        return null;
    };
    var hermandad = function (form, l) {
        if (!l) return null;
        return l.hermandades.filter(function (h) { return mismo(form.elements.HERMANDAD.value, h.nombre); })[0] || null;
    };

    function refrescar(form) {
        var el = function (n) { return form.elements[n]; };
        var l = localidad(form);
        var h = hermandad(form, l);
        var dia = el('DIA').value;

        // Al editar, lo que la fila ya tenía no es nuevo: una hermandad fuera
        // de la nómina sin día, o un paso antiguo sin enlazar, se conservan tal cual.
        var o = form.dataset;
        var mismaHermandad = mismo(el('LOCALIDAD').value, o.origLocalidad) && mismo(el('HERMANDAD').value, o.origHermandad);
        var hermandadNueva = el('HERMANDAD').value.trim() !== '' && !h && !(mismaHermandad && dia.trim() === '');
        var nuevos = [];
        if (el('LOCALIDAD').value.trim() !== '' && !l) nuevos.push('localidad');
        if (hermandadNueva && dia.trim() !== '' && !(l && l.dias.some(function (d) { return mismo(dia, d); }))) nuevos.push('día');
        if (hermandadNueva) nuevos.push('hermandad');
        if (el('PASO').value.trim() !== '' && !(h && h.pasos.some(function (p) { return mismo(el('PASO').value, p); }))
            && !(mismaHermandad && mismo(el('PASO').value, o.origPaso))) nuevos.push('paso');
        var avisos = [];
        if (nuevos.length) avisos.push('Nuevo en Semana Santa: ' + nuevos.join(', ') + '.');
        if (h && dia.trim() !== '' && !saleEl(h, dia)) avisos.push('Esta hermandad sale el ' + h.dias.join(' / ') + '; para cambiarle el día, hazlo en Semana Santa.');
        form.querySelector('.acomp-aviso').textContent = avisos.join(' ');
    }

    // Valores de la nómina para un campo; vacío si la localidad no tiene
    // Semana Santa cargada (entonces no hay predictivo). Las hermandades se
    // limitan al día escrito, si lo hay.
    function opciones(form, campo) {
        var l = localidad(form);
        if (!l) return [];
        if (campo === 'DIA') return l.dias.map(function (d) { return { valor: d }; });
        if (campo === 'HERMANDAD') {
            var dia = form.elements.DIA.value;
            return l.hermandades.filter(function (x) { return dia.trim() === '' || saleEl(x, dia); })
                .map(function (x) { return { valor: x.nombre, meta: x.dias.join(' / ') }; });
        }
        var h = hermandad(form, l);
        return h ? h.pasos.map(function (p) { return { valor: p }; }) : [];
    }

    function predictivo(form, input) {
        var box = input.parentNode.querySelector('.suggest');
        var cursor = -1;
        var cerrar = function () { box.hidden = true; box.innerHTML = ''; cursor = -1; };
        var marcar = function (n) {
            var items = box.querySelectorAll('.suggest-item');
            if (!items.length) return;
            cursor = (n + items.length) % items.length;
            items.forEach(function (it, i) { it.classList.toggle('is-on', i === cursor); });
            items[cursor].scrollIntoView({ block: 'nearest' });
        };
        var item = function (texto, meta, cls, alElegir) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = cls;
            b.textContent = texto;
            if (meta) {
                var m = document.createElement('span');
                m.className = 'suggest-meta';
                m.textContent = meta;
                b.appendChild(m);
            }
            b.addEventListener('click', alElegir);
            box.appendChild(b);
        };
        var elegir = function (valor) {
            input.value = valor;
            cerrar();
            input.dispatchEvent(new Event('change'));
            refrescar(form);
        };
        function abrir() {
            var q = input.value.trim();
            var todas = opciones(form, input.name);
            // Con un valor ya existente se ofrece todo (para cambiarlo); si no, se filtra.
            var exacta = todas.some(function (o) { return mismo(q, o.valor); });
            var lista = q === '' || exacta ? todas : todas.filter(function (o) { return slug(o.valor).indexOf(slug(q)) !== -1; });
            box.innerHTML = '';
            cursor = -1;
            lista.forEach(function (o) {
                item(o.valor, o.meta, 'suggest-item', function () { elegir(o.valor); });
            });
            if (todas.length && q !== '' && !exacta) {
                item('+ Nuevo: «' + q + '»', null, 'suggest-item suggest-item-add', cerrar);
            }
            box.hidden = !box.children.length;
        }
        input.addEventListener('focus', abrir);
        input.addEventListener('input', abrir);
        input.addEventListener('blur', cerrar);
        // Que el clic en la lista no quite el foco al campo (el blur la cerraría antes del clic).
        box.addEventListener('mousedown', function (e) { e.preventDefault(); });
        input.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(cursor + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(cursor - 1); }
            else if (e.key === 'Escape') { cerrar(); }
            else if (e.key === 'Enter' && cursor >= 0) { e.preventDefault(); box.querySelectorAll('.suggest-item')[cursor].click(); }
        });
    }

    forms.forEach(function (form) {
        form.addEventListener('input', function () { refrescar(form); });
        form.addEventListener('focusin', function () { refrescar(form); });
        form.elements.HERMANDAD.addEventListener('change', function () {
            var h = hermandad(form, localidad(form));
            // Si ya hay uno de sus días (sale varios, o salía otro en años pasados) se respeta.
            if (h && !saleEl(h, form.elements.DIA.value)) form.elements.DIA.value = h.dia;
            refrescar(form);
        });
        ['DIA', 'HERMANDAD', 'PASO'].forEach(function (n) { predictivo(form, form.elements[n]); });
    });

    var flot = document.getElementById('acompFlotante');
    if (!flot) return;
    var ff = flot.querySelector('form');
    function abrir(tras, accion, titulo, v, original) {
        tras.parentNode.insertBefore(flot, tras.nextSibling);
        flot.hidden = false;
        ff.action = accion;
        ff.querySelector('.acomp-titulo').textContent = titulo;
        // Provincia → localidad por la API del selector de municipios (admin.js):
        // cambiar la provincia vacía y deshabilita la localidad, y luego se rellena.
        ff.elements.PROVINCIA.value = v.PROVINCIA || '';
        ff.elements.PROVINCIA.dispatchEvent(new Event('change'));
        ff.elements.LOCALIDAD.value = v.LOCALIDAD || '';
        ['ANIO_INICIO', 'DIA', 'HERMANDAD', 'PASO'].forEach(function (n) { ff.elements[n].value = v[n] || ''; });
        ff.dataset.origLocalidad = original ? v.LOCALIDAD : '';
        ff.dataset.origHermandad = original ? v.HERMANDAD : '';
        ff.dataset.origPaso = original ? v.PASO : '';
        refrescar(ff);
        ff.elements[original ? 'HERMANDAD' : 'PROVINCIA'].focus();
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.acomp-editar, .acomp-anadir, .acomp-cancelar');
        if (!b) return;
        if (b.classList.contains('acomp-cancelar')) {
            flot.hidden = true;
        } else if (b.classList.contains('acomp-editar')) {
            var d = b.closest('tr').dataset;
            abrir(b.closest('.tableList'), ff.dataset.accionEditar.replace('{c}', d.id),
                'Editar ' + d.anio + ': ' + d.hermandad + (d.paso ? ' · ' + d.paso : '') + ' (' + d.localidad + ')',
                { ANIO_INICIO: d.anio, PROVINCIA: d.provincia, LOCALIDAD: d.localidad, DIA: d.dia, HERMANDAD: d.hermandad, PASO: d.paso }, true);
        } else {
            abrir(b.parentNode, ff.dataset.accionAlta, 'Añadir acompañamiento en ' + b.dataset.anio, { ANIO_INICIO: b.dataset.anio }, false);
        }
    });
})();
