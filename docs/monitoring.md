# Monitorización externa de uptime — marchasdecristo.com

> Generado: 2026-07-16 (C6 — [issue #12](https://github.com/jgcoronado/mdc-back/issues/12))

## Qué se monitoriza y por qué

`https://marchasdecristo.com/health` es el único endpoint monitorizado. Antes
de C6 nadie vigilaba nada externamente: una caída del hosting compartido en
plena Cuaresma/Semana Santa habría pasado desapercibida hasta que la
reportaran los propios usuarios.

`/health` (`routes.php`) para un visitante anónimo (sin sesión admin) hace dos
comprobaciones encadenadas y nada más — no revela ruta del `.db`, versión de
SQLite ni conteos, eso sigue reservado a sesión admin:

1. Que PHP arranca y el router despacha → imprime `status: ok` y `php: <versión>`.
2. Que SQLite responde a una consulta trivial (`SELECT 1`) → imprime `db: ok`
   o `db: error`, y en el caso de error fuerza además el código HTTP **503**
   (añadido en C6, antes este chequeo solo se ejecutaba para sesiones admin).

Con sesión admin activa, `/health` añade `db_path`, `db_exists`, versión de
SQLite, `journal_mode`, conteos de tablas y una prueba de FTS5 — eso no
cambia, sigue siendo el diagnóstico completo para el mantenedor.

## Servicio elegido: UptimeRobot

Se evaluaron **UptimeRobot** y **Better Stack** (ambos con plan gratuito
suficiente en la comparativa inicial). Se eligió UptimeRobot por:

- 50 monitores gratis frente a 10 de Better Stack (aquí solo hace falta uno,
  pero deja margen).
- Better Stack está pensado para equipos con guardias/escalado — funcionalidad
  irrelevante para un proyecto de un solo mantenedor.
- Ventanas de mantenimiento programadas disponibles en el plan gratuito de
  ambos.

**⚠️ Corrección tras configurarlo en la práctica (2026-07-16)**: en la
comparativa inicial se asumió que Telegram/Slack/webhook eran gratis en
UptimeRobot. Al intentar activar Telegram, la opción apareció **de pago**
(igual que la comprobación de expiración de SSL, ver tabla siguiente).
UptimeRobot ha ido moviendo funciones del plan gratuito al de pago desde que
se hizo la comparativa — **no dar por buena la tabla comparativa contra la
interfaz real sin comprobarlo primero**. A día de hoy, el único canal de
alerta gratuito confirmado en funcionamiento es **email**.

## Configuración del monitor

| Campo | Valor |
|---|---|
| Tipo de monitor | **Keyword** (no HTTP simple — necesitamos mirar el contenido, no solo el código) |
| Nombre | `marchasdecristo.com — /health` |
| URL | `https://marchasdecristo.com/health` |
| Palabra clave | `db: ok` |
| Modo | *Start incident when keyword does **not** exist* |
| Intervalo | 5 minutos (máximo del plan gratuito) |
| Ubicación de chequeo | Europa |
| Método / Auth | `GET`, sin autenticación (ruta pública) |
| Códigos "up" | `2xx` + `3xx` (por defecto — un `503` cuenta como caída, que es justo lo que queremos) |
| Alertas | Email a `jaguerra27@gmail.com` — único canal confirmado gratuito |
| Telegram / Slack / webhook | **No disponible** — de pago en el plan gratuito actual (comprobado 2026-07-16 al intentar activarlo). Si en el futuro se libera o se paga el plan, activar desde *Integrations & API* → conectar Telegram → marcar el nuevo contacto en el monitor. |
| SSL expiry / dominio | **No activado** — también de pago; queda fuera de alcance de C6. Si expira el certificado, `/health` empezará a fallar por HTTPS igualmente y el monitor lo detectará, solo que sin aviso previo de "quedan N días". |

**Por qué la keyword es `db: ok` y no `status: ok`**: `status: ok` se imprime
siempre, incluso si la base de datos falla — solo confirma que PHP arrancó.
`db: ok` solo aparece si la consulta a SQLite tuvo éxito, así que cubre tanto
una caída de proceso (PHP/Apache no responde en absoluto → el monitor lo
detecta por timeout) como una caída de datos (PHP responde pero la BD falla →
lo detecta por keyword ausente + código 503).

## Qué SÍ cubre y qué NO cubre

**Cubre:**
- Apache/PHP caído o sin responder (timeout).
- Fichero `.db` bloqueado, corrupto o inaccesible (el `SELECT 1` fallaría).
- Modo mantenimiento activo (devuelve 503 a todo, incluido `/health`) —
  ver más abajo, es una falsa alarma *esperada*, no un fallo real.

**No cubre:**
- Errores en páginas concretas que no sea `/health` (p. ej. una plantilla
  rota en `/marcha/{id}` que lance una excepción solo en esa ruta).
- Expiración de certificado TLS con antelación (ver tabla arriba).
- Regresiones de contenido/SEO — de eso se encargan el CI (`ci_smoke.php`,
  contra una fixture) y el smoke remoto tras cada deploy a PRO
  (`php/tools/smoke_remote.php`, contra datos reales — ver
  [entornos.md](entornos.md)), no el monitor de uptime.
- Cualquier cosa mientras la respuesta siga conteniendo `db: ok` con 2xx/3xx.

## Falsas alarmas esperadas (no son bugs)

### 1. Durante `scripts/sync_db_to_prod.php`
El script activa el fichero `.maintenance` mientras reemplaza el `.db` en
producción; con él activo, **todo** el sitio responde 503, incluido
`/health` (`bootstrap.php` lo comprueba antes de enrutar nada). Si el sync
coincide con uno de los chequeos de 5 minutos, llega un aviso de caída
seguido, minutos después, de uno de recuperación. Es señal de que el modo
mantenimiento funciona, no un fallo real.

Si el ruido molesta: pausa el monitor a mano en el dashboard de UptimeRobot
justo antes de lanzar el sync y reanúdalo al terminar.

### 2. Tras cambiar el código de `/health` sin desplegar todavía
**Esto es justo lo que ha pasado al dar de alta este monitor (2026-07-16):**
el código que añade la línea `db: ok` se subió a `git`/`main` el mismo día
que se creó el monitor, pero el despliegue a HelioHost es un paso **manual
por FTP** que hay que hacer aparte (ver `php/README.md` → "Deploy en
HelioHost"). Mientras el código nuevo no esté subido, `/health` en
producción sigue respondiendo la versión anterior (sin línea `db:`), la
keyword `db: ok` nunca aparece, y el monitor marca caída de forma continua
— no es una caída real del sitio, es que el monitor está un paso por delante
del despliegue.

**Lección aplicable en general**: cualquier cambio futuro en el *texto* que
`/health` devuelve (añadir, quitar o renombrar una línea) debe desplegarse
**antes o a la vez** que se actualice la keyword esperada en UptimeRobot —
o, si se actualiza primero el monitor, hay que esperar al despliegue antes
de fiarse del resultado.

> **Actualización (M5, 2026-07-16)**: con el pipeline de despliegue de
> [entornos.md](entornos.md) este desfase ya no ocurre por olvido — el código
> de `main` llega a producción con el botón de deploy, no por FTP a mano.
> Quedan dos ecos menores del mismo fenómeno: el desfase existe *hasta que
> pulses el botón* (la validación previa se hace en local), y el propio deploy
> de PRO activa el modo mantenimiento unos segundos (mismo caso benigno que el
> sync de BD, caso 1).

## Runbook — qué hacer si salta la alerta

1. Mira el detalle de la incidencia en el dashboard de UptimeRobot: motivo
   exacto (timeout, keyword ausente, código HTTP).
2. **¿Coincide con un sync reciente?** → probablemente el modo mantenimiento
   (caso 1 arriba). Espera al siguiente chequeo (5 min).
3. **¿Coincide con un despliegue de código reciente que tocó `/health`?** →
   probablemente el desfase deploy/monitor (caso 2 arriba). Comprueba si el
   FTP con el código nuevo ya se subió; si no, súbelo.
4. Si ninguno de los dos aplica: abre `https://marchasdecristo.com/health`
   a mano en el navegador para ver el detalle real de la respuesta.
5. Si no carga nada en absoluto → entra a Plesk y revisa logs de Apache/PHP
   del hosting (posible caída del hosting compartido, cuota agotada, etc.).
6. Si carga pero dice `db: error` → la BD tiene un problema real. Valorar
   restaurar desde el último backup en `private/backups/` (generado por
   `app/tools/backup.php`, cron semanal — ver `pendientes-post-cutover.md`).

## Pendiente / mejoras futuras (no forman parte de C6)

- **M5** del plan del consejo (deploy FTP automatizado desde CI) eliminaría
  el caso de falsa alarma nº 2 de raíz: el código desplegado nunca iría por
  detrás de lo que hay en `main`.
- Si el tráfico o la complejidad crecen, valorar un segundo monitor sobre la
  home (`/`) además de `/health`, para detectar errores de renderizado que
  no toquen la capa de BD. El plan gratuito de UptimeRobot da margen (50
  monitores).
