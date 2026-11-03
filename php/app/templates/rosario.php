<?php declare(strict_types=1);

use App\Rosario;
use App\View as V;

/** @var array<int,array{sono:bool,obs:string,ts:int}> $marchas la tabla guardada (Rosario::marchas) */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#111111">
<title>Rosario — arriba / abajo</title>
<style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    html, body { margin: 0; background: #111; color: #f2f2f2; }
    body {
        overscroll-behavior: none;
        font: 17px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        -webkit-user-select: none; user-select: none; -webkit-touch-callout: none;
        touch-action: manipulation;
    }
    /* Los botones llenan la primera pantalla; la tabla queda debajo, al hacer scroll. */
    .botonera {
        display: flex; flex-direction: column; gap: 12px; height: 100dvh;
        padding: max(12px, env(safe-area-inset-top)) max(12px, env(safe-area-inset-right))
                 max(12px, env(safe-area-inset-bottom)) max(12px, env(safe-area-inset-left));
    }
    .estado { display: grid; gap: 4px; }
    .estado p { margin: 0; }
    .fila { display: flex; justify-content: space-between; gap: 12px; font-weight: 600; }
    .bien { color: #6fdc8c; }
    .regular { color: #ffc857; }
    .mal { color: #ff6b6b; }
    #ultimo, #error { font-size: 15px; color: #c8c8c8; min-height: 1.35em; }
    #error { color: #ff6b6b; }
    .boton {
        flex: 1; width: 100%; border: 0; border-radius: 20px; color: #fff;
        font: 700 clamp(48px, 17vw, 120px)/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        touch-action: manipulation; -webkit-tap-highlight-color: transparent;
        transition: filter .15s, transform .15s;
    }
    #arriba { background: #1e7a46; }
    #abajo { background: #9b2226; }
    .boton.pulsado { filter: brightness(1.6); transform: scale(.98); }
    #permiso {
        position: fixed; inset: 0; z-index: 1; display: flex; flex-direction: column;
        justify-content: center; gap: 20px; padding: 32px 24px; background: #111;
    }
    #permiso[hidden] { display: none; }
    #permiso h1 { margin: 0; font-size: 28px; }
    #permiso p { margin: 0; }
    #activar {
        padding: 18px; border: 0; border-radius: 14px; background: #f2f2f2; color: #111;
        font: 700 22px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .repertorio {
        padding: 4px max(12px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom))
                 max(12px, env(safe-area-inset-left));
    }
    .repertorio p { margin: 0 0 8px; }
    #cola-marchas, #error-marchas { font-size: 15px; font-weight: 600; }
    #error-marchas { color: #ff6b6b; }
    #error-marchas:empty { display: none; }
    .repertorio table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 15px; }
    .repertorio th, .repertorio td {
        padding: 8px 4px; border-bottom: 1px solid #333; text-align: left; vertical-align: top;
        overflow-wrap: anywhere; -webkit-hyphens: auto; hyphens: auto;
    }
    .repertorio th { font-size: 13px; font-weight: 700; color: #a8a8a8; }
    .repertorio .lugar { width: 23%; }
    .repertorio .sono { width: 44px; text-align: center; }
    .repertorio .obs { width: 36%; }
    .repertorio td.lugar { font-size: 14px; color: #c8c8c8; }
    .repertorio td.marcha { font-weight: 600; }
    .repertorio input[type="checkbox"] { width: 28px; height: 28px; margin: 0; accent-color: #1e7a46; }
    /* 16 px como mínimo: con menos, el iPhone amplía la página al escribir. */
    .repertorio textarea {
        display: block; width: 100%; min-height: 36px; padding: 6px; resize: none; overflow: hidden;
        border: 1px solid #444; border-radius: 8px; background: #1c1c1c; color: #f2f2f2;
        font: 16px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        -webkit-user-select: text; user-select: text;
    }
</style>
</head>
<body>
    <main class="botonera">
        <div class="estado" aria-live="polite">
            <p class="fila"><span id="gps" class="regular">GPS apagado</span><span id="cola">Todo enviado</span></p>
            <p id="ultimo"></p>
            <p id="error"></p>
        </div>
        <button type="button" class="boton" id="arriba" data-accion="arriba">Arriba</button>
        <button type="button" class="boton" id="abajo" data-accion="abajo">Abajo</button>
    </main>

    <section class="repertorio" id="repertorio" aria-label="Repertorio">
        <div aria-live="polite">
            <p id="cola-marchas">Tabla guardada</p>
            <p id="error-marchas"></p>
        </div>
        <table>
            <thead>
                <tr><th class="lugar">Lugar</th><th class="marcha">Marcha</th><th class="sono">Sonó</th><th class="obs">Observaciones</th></tr>
            </thead>
            <tbody>
<?php foreach (Rosario::REPERTORIO as $i => [$lugar, $marcha]): $fila = $marchas[$i + 1]; ?>
                <tr data-orden="<?= $i + 1 ?>" data-ts="<?= $fila['ts'] * 1000 ?>">
                    <td class="lugar"><?= V::e($lugar) ?></td>
                    <td class="marcha"><?= V::e($marcha) ?></td>
                    <td class="sono"><input type="checkbox" aria-label="Sonó «<?= V::e($marcha) ?>»"<?= $fila['sono'] ? ' checked' : '' ?>></td>
                    <td class="obs"><textarea rows="1" maxlength="<?= Rosario::OBSERVACIONES_MAX ?>" enterkeyhint="done" aria-label="Observaciones de «<?= V::e($marcha) ?>»"><?= V::e($fila['obs']) ?></textarea></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div id="permiso" hidden>
        <h1>Ubicación</h1>
        <p id="permiso-texto">Cada pulsación guarda la hora y el sitio donde estás. Para eso la página necesita la ubicación del teléfono.</p>
        <button type="button" id="activar">Activar ubicación</button>
    </div>

<script>
(() => {
    'use strict';

    const COLA = 'rosario.cola';      // pulsaciones guardadas en el móvil, pendientes de envío
    const PERMISO = 'rosario.gps';    // 'si' tras la primera lectura: al recargar no se vuelve a pedir
    const VENTANA_MS = 5000;          // margen, antes y después de pulsar, para buscar la mejor lectura
    const OBJETIVO_M = 3;             // con esta precisión ya no se espera más
    const VIGENCIA_MS = 60000;        // lectura más vieja que vale si en la ventana no llega ninguna
    const REINTENTO_MS = 10000;
    const TIEMPO_ENVIO_MS = 15000;
    const NOMBRE = { arriba: 'Arriba', abajo: 'Abajo' };

    const $ = id => document.getElementById(id);

    // localStorage puede fallar (cuota, almacenamiento bloqueado): la página
    // sigue funcionando con la cola en memoria y lo avisa.
    function leer(clave, porDefecto) {
        try {
            const v = localStorage.getItem(clave);
            return v === null ? porDefecto : JSON.parse(v);
        } catch (e) {
            return porDefecto;
        }
    }
    function guardar(clave, valor) {
        try {
            localStorage.setItem(clave, JSON.stringify(valor));
            return true;
        } catch (e) {
            return false;
        }
    }

    let cola = leer(COLA, []);
    let lecturas = [];             // {lat, lon, acc, t} recientes del GPS
    let vigilancia = null;
    const esperas = new Set();     // pulsaciones esperando una lectura mejor
    const localizando = new Set(); // hora (ms) de las pulsaciones aún sin ubicación
    let enviando = false;
    let ultimoError = '';

    function hora(ms) {
        return new Date(ms).toLocaleTimeString('es-ES', { timeZone: 'Europe/Madrid', hour12: false });
    }

    function guardarCola() {
        if (!guardar(COLA, cola)) {
            ultimoError = 'No se puede guardar en el móvil: no recargues ni cierres la página hasta que se envíe todo.';
        }
    }

    function pintar() {
        $('cola').textContent = cola.length === 0 && localizando.size === 0
            ? 'Todo enviado'
            : 'Pendientes: ' + (cola.length + localizando.size);
        $('cola').className = cola.length === 0 ? '' : 'regular';
        $('error').textContent = ultimoError;
    }

    function estadoGps(texto, clase) {
        $('gps').textContent = texto;
        $('gps').className = clase;
    }

    // ── Ubicación ────────────────────────────────────────────────────────────
    function pedirPermiso(texto) {
        if (texto) $('permiso-texto').textContent = texto;
        $('permiso').hidden = false;
    }

    function arrancarGps() {
        $('permiso').hidden = true;
        if (vigilancia !== null) return;
        if (!window.isSecureContext || !('geolocation' in navigator)) {
            estadoGps('Este navegador no da la ubicación', 'mal');
            return;
        }
        estadoGps('Buscando señal…', 'regular');
        vigilancia = navigator.geolocation.watchPosition(alLeer, alFallar, {
            enableHighAccuracy: true,
            maximumAge: 0,
        });
    }

    function alLeer(posicion) {
        guardar(PERMISO, 'si');
        const c = posicion.coords;
        const ahora = Date.now();
        lecturas.push({ lat: c.latitude, lon: c.longitude, acc: c.accuracy, t: ahora });
        lecturas = lecturas.filter(l => ahora - l.t <= VIGENCIA_MS + VENTANA_MS);
        estadoGps('GPS ±' + Math.round(c.accuracy) + ' m', c.accuracy <= OBJETIVO_M ? 'bien' : 'regular');
        esperas.forEach(comprobar => comprobar());
    }

    function alFallar(error) {
        if (error.code !== error.PERMISSION_DENIED) {
            // Sin señal momentánea: watchPosition sigue vigilando solo.
            estadoGps('Sin señal GPS', 'mal');
            return;
        }
        navigator.geolocation.clearWatch(vigilancia);
        vigilancia = null;
        try { localStorage.removeItem(PERMISO); } catch (e) { /* nada que borrar */ }
        estadoGps('Sin permiso de ubicación', 'mal');
        pedirPermiso('Brave no tiene permiso para la ubicación. En el iPhone: Ajustes → Brave → Localización → «Mientras se usa la app», con «Ubicación exacta» activada. Después vuelve aquí y pulsa el botón.');
    }

    // La mejor lectura (la de menor error) de las tomadas entre 5 s antes y
    // 5 s después de pulsar; se deja de esperar en cuanto una llega a ±3 m.
    // Si en ese margen no llega ninguna (con el teléfono quieto el iPhone
    // puede espaciarlas), vale la última del último minuto; y sin ninguna, la
    // pulsación se guarda sin ubicación antes que perderla.
    function elegirLectura(t0) {
        return new Promise(resolver => {
            let hecho = false;
            const enVentana = () => lecturas
                .filter(l => Math.abs(l.t - t0) <= VENTANA_MS)
                .reduce((mejor, l) => (mejor === null || l.acc < mejor.acc ? l : mejor), null);
            const terminar = () => {
                if (hecho) return;
                hecho = true;
                clearTimeout(reloj);
                esperas.delete(comprobar);
                const recientes = lecturas.filter(l => t0 - l.t <= VIGENCIA_MS);
                resolver(enVentana() || recientes[recientes.length - 1] || null);
            };
            const comprobar = () => {
                const mejor = enVentana();
                if (mejor !== null && mejor.acc <= OBJETIVO_M) terminar();
            };
            const reloj = setTimeout(terminar, VENTANA_MS);
            esperas.add(comprobar);
            comprobar();
        });
    }

    // ── Pulsaciones ──────────────────────────────────────────────────────────
    async function pulsar(boton) {
        const accion = boton.dataset.accion;
        const t0 = Date.now(); // hora del iPhone en el instante de pulsar
        boton.classList.add('pulsado');
        setTimeout(() => boton.classList.remove('pulsado'), 250);

        localizando.add(t0);
        $('ultimo').textContent = NOMBRE[accion] + ' · ' + hora(t0) + ' · localizando…';
        pintar();

        const l = await elegirLectura(t0);
        cola.push({
            accion: accion,
            ts: String(t0),
            lat: l ? String(l.lat) : '',
            lon: l ? String(l.lon) : '',
            precision: l ? String(l.acc) : '',
        });
        cola.sort((a, b) => Number(a.ts) - Number(b.ts));
        localizando.delete(t0);
        guardarCola();
        $('ultimo').textContent = NOMBRE[accion] + ' · ' + hora(t0) + ' · '
            + (l ? '±' + Math.round(l.acc) + ' m' : 'SIN UBICACIÓN');
        pintar();
        enviar();
    }

    // ── Envío ────────────────────────────────────────────────────────────────
    // Vuelve solo si el servidor confirma que lo ha escrito (ok: true);
    // cualquier otra respuesta (sin red, mantenimiento, error) lanza un error.
    async function post(url, datos) {
        const control = new AbortController();
        const corte = setTimeout(() => control.abort(), TIEMPO_ENVIO_MS);
        let respuesta;
        try {
            respuesta = await fetch(url, {
                method: 'POST',
                body: new URLSearchParams(datos),
                signal: control.signal,
                cache: 'no-store',
            });
        } finally {
            clearTimeout(corte);
        }
        const r = await respuesta.json().catch(() => null);
        if (!respuesta.ok || !r || r.ok !== true) {
            throw new Error((r && r.error) || 'respuesta ' + respuesta.status);
        }
        return r;
    }

    function noEnviado(e) {
        const motivo = e.name === 'AbortError' ? 'sin respuesta'
            : e instanceof TypeError ? 'sin conexión' : e.message;
        return 'No enviado (' + motivo + '). Se reintenta solo.';
    }

    // En orden y de una en una. Una pulsación solo sale de la cola cuando el
    // servidor confirma que está en el CSV; si no, queda para el siguiente intento.
    async function enviar() {
        if (enviando) return;
        enviando = true;
        try {
            while (cola.length > 0) {
                const p = cola[0];
                // No adelantar a una pulsación anterior que aún busca ubicación.
                if ([...localizando].some(t => t < Number(p.ts))) break;
                await post('/rosario', p);
                cola.splice(cola.indexOf(p), 1);
                guardarCola();
                ultimoError = '';
                pintar();
            }
        } catch (e) {
            ultimoError = noEnviado(e);
        } finally {
            enviando = false;
            pintar();
        }
    }

    // ── Tabla de marchas ─────────────────────────────────────────────────────
    // Cada cambio de una fila (sonó u observaciones) se guarda en el móvil con
    // la hora del teléfono y se envía; el servidor se queda con la versión más
    // reciente de cada fila. Una fila deja de estar pendiente cuando el
    // servidor la confirma y no se ha vuelto a tocar mientras tanto.
    const MARCHAS = 'rosario.marchas';   // filas cambiadas sin confirmar, por orden
    const PAUSA_ESCRITURA_MS = 1500;     // al escribir, se envía tras esta pausa

    const filas = [...document.querySelectorAll('#repertorio tbody tr')];
    let pendientes = leer(MARCHAS, {});
    let enviandoMarchas = false;
    let errorMarchas = '';
    let pausa = null;

    function guardarPendientes() {
        if (!guardar(MARCHAS, pendientes)) {
            errorMarchas = 'No se puede guardar en el móvil: no recargues ni cierres la página hasta que se envíe todo.';
        }
    }

    function pintarMarchas() {
        const n = Object.keys(pendientes).length;
        $('cola-marchas').textContent = n === 0 ? 'Tabla guardada' : 'Sin enviar: ' + n + (n === 1 ? ' fila' : ' filas');
        $('cola-marchas').className = n === 0 ? '' : 'regular';
        $('error-marchas').textContent = errorMarchas;
    }

    // El campo crece con el texto en vez de esconderlo.
    function ajustarAlto(campo) {
        campo.style.height = 'auto';
        campo.style.height = campo.scrollHeight + campo.offsetHeight - campo.clientHeight + 'px';
    }

    function cambiar(tr) {
        pendientes[tr.dataset.orden] = {
            orden: tr.dataset.orden,
            sono: tr.querySelector('input').checked ? '1' : '0',
            obs: tr.querySelector('textarea').value,
            ts: String(Date.now()),
        };
        guardarPendientes();
        pintarMarchas();
    }

    async function enviarMarchas() {
        if (enviandoMarchas) return;
        enviandoMarchas = true;
        try {
            for (;;) {
                const p = Object.values(pendientes).sort((a, b) => Number(a.ts) - Number(b.ts))[0];
                if (!p) break;
                await post('/rosario/marchas', p);
                // Si se tocó durante el envío, queda pendiente con lo nuevo.
                if (pendientes[p.orden] === p) delete pendientes[p.orden];
                guardarPendientes();
                errorMarchas = '';
                pintarMarchas();
            }
        } catch (e) {
            errorMarchas = noEnviado(e);
        } finally {
            enviandoMarchas = false;
            pintarMarchas();
        }
    }

    // Lo pendiente de una visita anterior se pinta encima de lo que trae el
    // servidor, salvo que la fila se cambiara después en otro sitio.
    Object.values(pendientes).forEach(p => {
        const tr = filas[Number(p.orden) - 1];
        if (!tr || Number(p.ts) < Number(tr.dataset.ts)) {
            delete pendientes[p.orden];
            return;
        }
        tr.querySelector('input').checked = p.sono === '1';
        tr.querySelector('textarea').value = p.obs;
    });
    guardarPendientes();

    filas.forEach(tr => {
        const campo = tr.querySelector('textarea');
        ajustarAlto(campo);
        tr.querySelector('input').addEventListener('change', () => {
            cambiar(tr);
            enviarMarchas();
        });
        campo.addEventListener('input', () => {
            ajustarAlto(campo);
            cambiar(tr);
            clearTimeout(pausa);
            pausa = setTimeout(enviarMarchas, PAUSA_ESCRITURA_MS);
        });
        // Una sola línea: «intro» cierra el teclado en vez de saltar de línea.
        campo.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.isComposing) {
                e.preventDefault();
                campo.blur();
            }
        });
        campo.addEventListener('blur', enviarMarchas);
    });

    // ── Arranque ─────────────────────────────────────────────────────────────
    document.querySelectorAll('.boton').forEach(b => b.addEventListener('click', () => pulsar(b)));
    $('activar').addEventListener('click', arrancarGps);

    if (leer(PERMISO, '') === 'si') {
        arrancarGps();
    } else {
        pedirPermiso();
    }

    function enviarTodo() {
        enviar();
        enviarMarchas();
    }
    window.addEventListener('online', enviarTodo);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') enviarTodo();
    });
    setInterval(() => {
        if (cola.length > 0) enviar();
        if (Object.keys(pendientes).length > 0) enviarMarchas();
    }, REINTENTO_MS);

    pintar();
    pintarMarchas();
    enviarTodo();
})();
</script>
</body>
</html>
