# /rosario — hora y lugar de cada levantá y arriá de un paso

> Última actualización: 2026-10-11 (tabla de marchas, §4.4)

Documento para **retomar el trabajo desde cero** (por ejemplo, en una sesión
en la nube): qué es, qué se decidió y por qué, cómo funciona, cómo se prueba y
qué queda pendiente. El resumen operativo por entornos está en
[entornos.md](entornos.md) § «Solo en PRE: /rosario».

## 1. Qué es

Una página de campo, ajena al catálogo, para usarla desde un **iPhone (iOS 27)
con Brave** durante el recorrido de un paso. Tiene dos botones grandes, uno
encima de otro: **«Arriba»** (verde, el paso se levanta) y **«Abajo»** (rojo,
el paso se baja). Cada pulsación añade una línea a un CSV con la fecha, la
hora, la acción y la ubicación del teléfono.

- URL: **https://marchasdecristo.jaguerra27.helioho.st/rosario** (solo PRE).
- El CSV vive en **`private/rosario.csv`**, junto a `mdc.db`, fuera del
  webroot: no se puede descargar por URL (`/rosario.csv` → 404). Se baja a
  mano.
- No toca la BD ni sale en nav, sitemap ni `llms.txt`.
- Debajo de los botones, al hacer scroll, la **tabla del repertorio** previsto
  con un check «Sonó» y un campo «Observaciones» por marcha. Va a otro CSV,
  **`private/rosario_marchas.csv`** (§4.4).

## 2. Estado (2026-10-10)

| | |
|---|---|
| Código | En la rama `pre`: `f7ce4c0` (la funcionalidad) y `b23060e` (arreglo de una prueba de CI que bloqueaba el deploy, ver §8). |
| PRE | Desplegado y verificado: `GET /rosario` → 200 con `X-Robots-Tag: noindex, nofollow` y `Cache-Control: no-store`; smoke remoto 10/10. |
| PRO | `GET /rosario` → 404. Seguirá así aunque `pre` se fusione en `main` (ver §4.1). |
| CSV real | **Aún no existe**: se crea con la primera pulsación. Nadie ha hecho un POST contra PRE (para no ensuciarlo), así que la escritura en `private/` del host está **sin confirmar en vivo**. |
| Dispositivo real | **Sin probar en iPhone + Brave.** Probado en Chrome headless con GPS simulado (§6.4). |

## 3. Decisiones

### 3.1 Tomadas por el usuario (2026-10-10)

| Pregunta | Decisión | Consecuencia |
|---|---|---|
| ¿Quién puede registrar? | **Sin protección** (ni clave en la URL ni login) | Cualquiera que conozca la URL de PRE puede añadir líneas. El repo es público, así que la ruta es visible. Se descartaron: clave en `config.local.php` de PRE, y login del panel (la sesión caduca a las 8 h, a mitad de recorrido). |
| ¿Sin cobertura? | **Guardar en el móvil y reenviar** | Cola en `localStorage`; nada se pierde salvo que se borren los datos del navegador. |
| ¿Formato del CSV? | **Excel en español** | Separador `;`, coma decimal. |
| ¿Rama base? | **Solo /rosario, desde `origin/pre`** | El commit local `a16ea29` (a11y/UX) de la rama `claude/ux-informe-analizador` **no** se subió. |

Tabla de marchas (2026-10-11):

| Pregunta | Decisión | Consecuencia |
|---|---|---|
| ¿Qué tabla? | El Excel `marchas_2026_consenso.xlsx` **sin sus dos filas de título**, filas 3 a 35, dos columnas **no editables**; a la derecha, «Sonó» (check) y «Observaciones» (~150 caracteres) | El repertorio está fijo en `Rosario::REPERTORIO`, con el texto tal cual viene en el Excel. |
| ¿Dónde se guarda? | **Móvil + servidor** | Pendientes en `localStorage` con reintento, como los botones; el servidor tiene la tabla entera en `private/rosario_marchas.csv`. |
| ¿Cabecera de las dos columnas del Excel (no traen título)? | **Lugar \| Marcha** | Cabecera: Lugar · Marcha · Sonó · Observaciones. |

### 3.2 Supuestos aceptados (el usuario no los objetó)

- **Solo PRE**, pero también activa en **local** para poder probarla.
- **Hora**: la del iPhone en el instante de pulsar (sincronizada por red,
  error < 1 s), convertida a hora de Madrid en el servidor. Cumple el ±5 s
  pedido. No vale la hora de llegada al servidor: con la cola, una pulsación
  puede llegar minutos después.
- **Precisión de ±3 m: no garantizable** desde una web. El iPhone da 3-5 m a
  cielo abierto y 10-20 m en calles estrechas. Se busca la mejor lectura y se
  anota la precisión real en una columna; nunca se descarta una pulsación por
  precisión.
- **Permiso de ubicación**: lo guarda Brave, no la página. La página recuerda
  que ya se concedió y arranca el GPS sola al recargar; si Brave vuelve a
  preguntar, es decisión del navegador.

## 4. Cómo funciona

### 4.1 Servidor

- [routes.php:118](../php/app/routes.php#L118) registra `GET` y `POST /rosario`
  **solo si `App\Entorno::nombre()` es `pre` o `local`**. En PRO la ruta no
  existe y cae en el 404 genérico. Es un registro condicional, como el de
  IndexNow en el mismo fichero.
- [Rosario::pagina](../php/app/src/Rosario.php#L35) sirve la plantilla
  autónoma (sin el layout del sitio), con `no-store`.
- [Rosario::registrar](../php/app/src/Rosario.php#L42) recibe el POST
  (`accion`, `ts`, `lat`, `lon`, `precision`) y responde JSON:
  `{"ok":true,"repetida":false}`, 422 con `error` si los datos no valen, o 500
  si no puede escribir.
- [Rosario::linea](../php/app/src/Rosario.php#L80) valida y da formato:
  - `accion` ∈ `arriba|abajo`;
  - `ts` = 13 dígitos (ms Unix del teléfono);
  - lat/lon en rango y las dos o ninguna;
  - precisión ≥ 0.
- [Rosario::anotar](../php/app/src/Rosario.php#L115) abre con `flock`, crea
  la cabecera si el fichero está vacío y **no escribe una línea idéntica a
  otra ya presente** (reenvío de la cola cuya respuesta se perdió). Devuelve
  `false` en ese caso.
- [Rosario::fichero](../php/app/src/Rosario.php#L65) es
  `dirname(db_path)/rosario.csv`, el mismo patrón que `propuestas/` u
  `og-cache/`. En PRE, `db_path` es `private/mdc.db`, compartido con PRO.

### 4.2 Formato del CSV

```
fecha;hora;accion;latitud;longitud;precision_m
10/10/2026;20:15:32;arriba;37,389123;-5,984459;4,7
10/10/2026;20:17:01;abajo;;;
```

- Fin de línea `\r\n`, sin BOM. La cabecera va **sin tildes** a propósito:
  Excel abre como ANSI un UTF-8 sin BOM.
- Coordenadas con 6 decimales (≈ 0,1 m) y precisión con 1 decimal.
- La hora se trunca al segundo.
- Una línea sin coordenadas (`;;;`) significa que el GPS no dio ninguna
  lectura válida a tiempo; la pulsación se guarda igual.

### 4.3 Cliente ([templates/rosario.php](../php/app/templates/rosario.php))

Todo el JS va en línea en la plantilla, sin assets nuevos en `php/public`, para
que nada de esto acabe servido en PRO. La CSP del `.htaccess` ya permite
`'unsafe-inline'`.

- **Permiso** ([arrancarGps](../php/app/templates/rosario.php#L139),
  [alFallar](../php/app/templates/rosario.php#L163)). En la primera visita se
  muestra un aviso con el botón «Activar ubicación». Con la primera lectura se
  guarda `localStorage['rosario.gps'] = "si"`, y al recargar el GPS arranca
  sin aviso. Si el permiso se deniega, se borra esa marca y el aviso explica
  la ruta en el iPhone: *Ajustes → Brave → Localización → «Mientras se usa la
  app» + «Ubicación exacta»*.
- **GPS**: `watchPosition` con `enableHighAccuracy: true, maximumAge: 0`,
  siempre encendido mientras la página está abierta, y un búfer de las
  lecturas de los últimos ~65 s.
- **Elección de la lectura**
  ([elegirLectura](../php/app/templates/rosario.php#L181)):
  1. Se toma la de menor error entre 5 s antes y 5 s después de pulsar, y se
     deja de esperar en cuanto una llega a ≤ 3 m.
  2. Si en esa ventana no hay ninguna, vale la última del último minuto.
  3. Sin ninguna, la pulsación se guarda sin ubicación.
- **Cola y envío** ([pulsar](../php/app/templates/rosario.php#L206),
  [enviar](../php/app/templates/rosario.php#L237)):
  - Cada pulsación entra en `localStorage['rosario.cola']`, ordenada por hora.
  - Se envía de una en una y en orden, sin adelantar a una pulsación anterior
    que aún busca ubicación.
  - **Solo sale de la cola si el servidor responde `ok: true`**. Cualquier
    otra cosa la deja pendiente: sin red, 503 de mantenimiento, 404 o 500.
  - Reintentos: cada 10 s, en el evento `online`, al volver a la página y al
    abrirla.
  - Cada envío se corta a los 15 s.
- **Pantalla**:
  - Arriba se ve el estado del GPS («GPS ±4 m» en verde si ≤ 3 m, en ámbar si
    no) y la cola («Todo enviado» o «Pendientes: N»).
  - Debajo, la última pulsación (acción · hora · precisión, o «SIN
    UBICACIÓN») y el último error («No enviado (sin conexión). Se reintenta
    solo.»).
  - Al pulsar, el botón da un destello de 250 ms.

### 4.4 Tabla de marchas (2026-10-11)

- **Pantalla**: la botonera sigue ocupando la primera pantalla entera
  (`.botonera`, `100dvh`); la tabla va debajo y se llega con scroll. Antes la
  página no hacía scroll (`overflow: hidden`); ahora sí.
- **Servidor**:
  - `GET /rosario` pinta la tabla con lo guardado
    ([Rosario::marchas](../php/app/src/Rosario.php)), así que otra pestaña u
    otro teléfono ven lo mismo.
  - `POST /rosario/marchas` ([Rosario::marcar](../php/app/src/Rosario.php))
    recibe **una fila**: `orden` (1..33), `sono` (`0|1`), `obs`, `ts` (ms del
    teléfono). Responde `{"ok":true,"aplicada":true|false}`, 422 o 500.
  - [Rosario::marca](../php/app/src/Rosario.php) valida y deja las
    observaciones en una línea (saltos y tabuladores → espacio) y en
    150 caracteres como máximo (caracteres, no bytes, como el `maxlength`).
  - [Rosario::aplicar](../php/app/src/Rosario.php) reescribe la tabla entera
    bajo `flock`, pero **solo si la fila que llega es igual o más reciente**
    que la guardada. Una pestaña con la tabla vieja no pisa lo anotado
    después, y cada envío solo cambia su fila.
- **Formato** de `rosario_marchas.csv`: con **BOM UTF-8** (las marchas llevan
  tildes), `;`, `\r\n`, y una línea por marcha en el orden del Excel:
  ```
  Orden;Lugar;Marcha;Sonó;Observaciones;Actualizado
  1;Salida - Pta Perdón;Himno de España;no;;
  3;Gradas 2;Pasa la Virgen Macarena;sí;"Muy bien; con ""saeta""";11/10/2026 20:56:40
  ```
  «Actualizado» es la hora de Madrid del último cambio de la fila; el
  servidor la relee para comparar. Una observación con `;` o `"` va entre
  comillas.
- **Cliente**:
  - Cada cambio guarda la fila en `localStorage['rosario.marchas']` con la
    hora del teléfono. El check se envía al momento; el texto, tras 1,5 s sin
    escribir o al salir del campo.
  - Una fila deja de estar pendiente cuando el servidor confirma **y** no se
    ha vuelto a tocar mientras tanto.
  - Al abrir la página, lo pendiente se pinta encima de lo que trae el
    servidor, salvo que la fila sea más nueva en el servidor.
  - Reintentos: los mismos que los botones (cada 10 s, `online`, al volver a
    la página). Estado encima de la tabla: «Tabla guardada» o «Sin enviar: N
    filas», y el último error.
  - «Intro» cierra el teclado en vez de meter un salto de línea, y el campo
    crece con el texto.
  - El envío con corte a los 15 s quedó en una función `post()` que usan los
    botones y la tabla. Para los botones el comportamiento es el mismo.

## 5. Ficheros

| Fichero | Qué |
|---|---|
| [php/app/src/Rosario.php](../php/app/src/Rosario.php) | Controlador, formato y escritura de los dos CSV; repertorio fijo (`REPERTORIO`) |
| [php/app/templates/rosario.php](../php/app/templates/rosario.php) | Página autónoma: HTML, CSS y JS |
| [php/app/routes.php:118](../php/app/routes.php#L118) | Registro condicional (PRE/local) |
| [php/tools/ci_rosario.php](../php/tools/ci_rosario.php) | Pruebas unitarias de los CSV (paso propio en `ci.yml`) |
| [php/tools/ci_smoke.php:966](../php/tools/ci_smoke.php#L966) | Pasada PRO: GET y los dos POST → 404. Pasada local: página con los dos botones y la tabla |
| [php/tools/smoke_remote.php:114](../php/tools/smoke_remote.php#L114) | PRE: 200 + botones + tabla + noindex (**solo GET**). PRO: 404 |
| [docs/entornos.md](entornos.md) | Resumen en § «Solo en PRE: /rosario» |

## 6. Pruebas

### 6.1 Unitarias — `php php/tools/ci_rosario.php`

Seis pruebas que fijan **por qué** importa cada cosa:

- La hora sale en la zona de Madrid sea cual sea la del servidor, en verano y
  en invierno.
- El formato es el de Excel en español.
- Una pulsación sin ubicación se guarda igual.
- Se rechazan los datos que no vienen de la página.
- El fichero nace con su cabecera.
- Un reenvío no duplica la línea.

Se comprobó que fallan con las mutaciones correspondientes: zona UTC, punto
decimal, sin deduplicar y rechazar la falta de ubicación.

Y cinco más de la tabla de marchas (2026-10-11):

- Sin nada marcado salen las 33 marchas, sin marcar.
- El CSV lleva BOM y las tildes, en el orden del Excel, y cambiar una fila deja
  el resto como estaba.
- Una versión más vieja de una fila no pisa la nueva; un reenvío sí entra.
- `;`, comillas y saltos de línea no descuadran las columnas.
- Se rechaza lo que no es una fila de la página; caben 150 caracteres con
  tildes y no 151.

Se comprobó que fallan con estas mutaciones: sin comparar la hora, sin BOM,
sin quitar saltos, contar bytes, sin comillas y hora UTC.

### 6.2 Smoke de CI

Las dos pasadas de `ci_smoke.php`:

- **PRO**: `/rosario` → 404 en GET y POST; `POST /rosario/marchas` → 404.
- **local**: 200 con los dos botones, la tabla (33 filas) y `noindex`.

### 6.3 Smoke remoto

Corre tras cada deploy:

- **PRE**: 200 con botones, tabla y `X-Robots-Tag`. **Nunca POST**:
  escribiría en los CSV reales.
- **PRO**: 404.

### 6.4 Extremo a extremo (manual, no está en el repo)

Se hizo con **Chrome headless controlado por CDP desde Node 20**, sin
dependencias. 35/35 comprobaciones. Para repetirlo:

1. BD de prueba: `php php/tools/ci_fixture.php <scratch>/mdc.db`.
2. Router envoltorio, porque `php -S` en Windows **no ve `DB_PATH`** del
   entorno:
   ```php
   <?php putenv('DB_PATH=<scratch>/mdc.db');
   return require '<repo>/php/public/index.php';
   ```
   El CSV de prueba cae entonces en `<scratch>/rosario.csv`.
3. Elegir el entorno simulado:
   - **PRE**: `php/public/env.php` con `<?php return ['preproduccion' => true];`
     (gitignorado; bórralo al acabar).
   - **Local**: `php/app/config.local.php` con `['env' => 'local']`.
   - **PRO**: sin ninguno de los dos.
4. Servidor: `php -S 127.0.0.1:8772 -t php/public <scratch>/router.php`.
5. Chrome:
   ```
   chrome.exe --headless=new --remote-debugging-port=9333 --user-data-dir=<scratch>/perfil --no-first-run about:blank
   ```
   Luego un script `node --experimental-websocket` que habla CDP con:
   - `Browser.grantPermissions` / `Browser.setPermission` (geolocation);
   - `Emulation.setGeolocationOverride` (lat, lon, accuracy; `{}` = sin
     señal);
   - `Emulation.setDeviceMetricsOverride` (390×844, móvil);
   - `Network.emulateNetworkConditions` (`offline: true`);
   - `Runtime.evaluate` para pulsar y leer el estado;
   - `Page.captureScreenshot`.
6. Escenarios cubiertos:
   - primera visita con aviso;
   - activar el GPS y recordar el permiso;
   - pulsar con ±2 m (inmediato);
   - recargar sin aviso;
   - ±8 m que mejora a ±2,5 m dentro de la ventana;
   - ±8 m que no mejora (se guarda a los 5 s con 8,0);
   - sin red, cerrar la pestaña y reabrir (se envía sola);
   - mantenimiento, creando `<scratch>/.maintenance` (503 y reintento);
   - sin GPS (columnas vacías);
   - permiso denegado (instrucciones del iPhone);
   - sin errores de JS.

   Tabla de marchas (2026-10-11), mismo montaje en entorno PRE, 32/32:
   - los botones siguen llenando la primera pantalla y la tabla empieza justo
     debajo, sin scroll horizontal a 390 px;
   - el botón «Arriba» sigue escribiendo en `rosario.csv`;
   - 33 filas, Lugar y Marcha no editables, y lo guardado en el servidor
     pintado al abrir;
   - check al momento, texto tras la pausa, corte en 150, «intro» sin salto
     de línea;
   - sin red: «Sin enviar: 1 fila» y se envía sola al volver la red;
   - pendiente que sobrevive a recargar con el envío bloqueado;
   - pendiente más viejo que el servidor descartado;
   - sin errores de JS.

Trampas del PHP local (C:\php-8.5.1):

- **No carga GD**: las 4 pruebas `og`/«compartir» de `ci_smoke.php` fallan en
  local aunque en CI pasen. Se resuelve con `php -d extension=gd` en el
  servidor y en el runner.
- **opcache está activo en `php -S`** (`opcache.enable` aplica a cli-server).
  Al mutar código para ver fallar una prueba, arrancar con
  `-d opcache.enable=0` o reiniciar el servidor.

## 7. Operación

### Antes de la procesión (en el iPhone)

1. *Ajustes → Brave → Localización → «Mientras se usa la app»* con
   **«Ubicación exacta»** activada. Sin esto el error es de kilómetros.
2. Abrir la URL en una **pestaña normal**, no privada: al cerrar una privada
   se pierde la cola.
3. Hacer **una pulsación de prueba** y comprobar «Todo enviado». Esto confirma
   también la escritura en `private/` (§2). Después, borrar esa línea o el
   fichero.
4. Subir el bloqueo automático de pantalla (o ponerlo en «Nunca»): con la
   pantalla bloqueada la página se pausa. Al desbloquear sigue sola, y la
   ventana de 5 s cubre el arranque del GPS.
5. **No desplegar a PRO ni ejecutar `sync_db_to_prod.php` durante el
   recorrido**: PRE comparte `private/.maintenance` y también entra en
   mantenimiento. No se pierde nada, porque la cola reintenta, pero las
   pulsaciones se quedan pendientes.

### Después

- Descargar `private/rosario.csv` con el gestor de archivos de Plesk o por
  FTP. Se abre con doble clic en Excel en español.
- Para empezar otra procesión de cero, renombrar o borrar el fichero: el
  siguiente se crea solo con su cabecera.
- La tabla está en `private/rosario_marchas.csv`, junto al anterior; también
  se abre con doble clic. El repertorio es el de esta procesión: para otra hay
  que cambiar `Rosario::REPERTORIO` y borrar el fichero.

## 8. Incidencia del deploy (2026-10-10)

El primer push (`f7ce4c0`) **no llegó a desplegarse**:

- La prueba `compartir: tarjeta de disco con portada la muestra` de
  `ci_smoke.php` fallaba desde `837b101` (2026-10-08, tarjetas sociales
  «centradas»). La tarjeta pone la portada **centrada arriba** y la prueba
  seguía mirando el píxel (300, 315), donde iba antes, a la izquierda.
- Con eso, `verify` fallaba y `deploy-pre` se saltaba. Ya pasó con el push de
  `837b101`, que nunca llegó a PRE.
- Arreglo en `b23060e`: muestrear (600, 197), que cae dentro de la portada con
  sus dos tamaños posibles (170 y 210 px). Se comprobó que la prueba sigue
  fallando si la tarjeta sale sin portada.
- Consecuencia: **las tarjetas sociales nuevas de `837b101` llegaron a PRE con
  este deploy**.

## 9. Pendiente y deuda

- **Validar en el iPhone real** (§7, punto 3). Es lo único que no se ha podido
  probar.
- Comentario desfasado en [Og.php:137](../php/app/src/Og.php#L137): dice que
  la portada va «a la izquierda». No se tocó (fuera de alcance).
- PHPStan ya avisaba de `$argc`/`$argv` sin definir en `ci_auth.php` (l. 66) y
  `smoke_remote.php` (l. 27-28). `ci_rosario.php` usa `isset($argv[1])` para
  no añadir otro.
- Al fusionar `pre` en `main`, `/rosario` viaja a PRO pero no se registra
  allí. Si algún día se quiere en PRO, basta con añadir `Entorno::PROD` a la
  condición de `routes.php` y cambiar la pasada PRO de los smokes.

### Ideas no implementadas (no se pidieron)

- Mantener la pantalla encendida (Screen Wake Lock).
- Botón para deshacer la última pulsación.
- Proteger el acceso (clave o login): el usuario eligió no hacerlo.
- Comprobar `navigator.permissions` para saltarse el aviso si el permiso ya
  está concedido pero se borró la marca.
- Neutralizar en `rosario_marchas.csv` las observaciones que empiezan por
  `=`, `+`, `-` o `@`: Excel las toma por fórmula. Solo importa si alguien
  ajeno escribe en la tabla, porque la ruta no tiene protección (decisión del
  usuario).

Efecto conocido de la deduplicación: dos pulsaciones del **mismo botón en el
mismo segundo** con la misma lectura quedan como una sola línea.

## 10. Retomar en remoto

- Partir de `origin/pre`: el código vive ahí y no está en `main`.
  `git push origin <rama>:pre` en fast-forward dispara `deploy.yml` (job
  `deploy-pre`). Seguirlo con `gh run watch <id>`.
- Desde una sesión en la nube **no** se puede leer `private/rosario.csv` (no
  está en git ni es accesible por URL) ni probar en el iPhone. Para eso hace
  falta el usuario.
- Verificación sin efectos: `curl -sI https://marchasdecristo.jaguerra27.helioho.st/rosario`
  (200 + noindex) y `curl -s -o /dev/null -w '%{http_code}' https://marchasdecristo.com/rosario`
  (404). **No hacer POST contra PRE.**
