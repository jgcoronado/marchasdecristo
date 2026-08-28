# Entornos: preproducción y producción

> Generado: 2026-07-16 (M5) · Reintroducido el entorno PRE: 2026-07-28

Hay **tres entornos**: el **local** (donde se desarrolla), **preproducción**
(`marchasdecristo.jaguerra27.helioho.st`) y **producción**
(`marchasdecristo.com`). Los dos últimos viven en la misma cuenta de HelioHost.

> **Nota histórica.** El primer intento de montar PRE (2026-07-16) se retiró el
> 2026-07-23: dependía de apuntar el document root del subdominio a
> `pre/httpdocs`, y HelioHost (panel Plesk) no permite editar ese campo. La
> vuelta (2026-07-28) **no necesita tocar Plesk**: el aislamiento se hace desde
> el propio código, con `env.php` (ver «Cómo se aísla PRE»).

## Mapa

| | **Local** | **PRE** | **PRO** |
|---|---|---|---|
| URL | `http://127.0.0.1:puerto` | `https://marchasdecristo.jaguerra27.helioho.st` | `https://marchasdecristo.com` |
| Docroot (Plesk) | `php/public/` | `marchasdecristo.jaguerra27.helioho.st/` | `marchasdecristo.com/` |
| Código | `php/` en el repo | `pre/app/` (vía `env.php`) | `app/` |
| BD | `php/data/mdc.db` (maestra) | **la de PRO, en solo lectura** | `private/mdc.db` |
| Portadas | `php/public/cover/` | se cargan de PRO (`cover_base_url`) | `marchasdecristo.com/cover/` |
| Indexación | — | **Bloqueada** (noindex + `X-Robots-Tag` + `robots.txt` Disallow total) | Normal |
| Señas visibles | — | Cinta «Entorno de preproducción»; `/health` dice `entorno: pre` | `/health` dice `entorno: prod` |
| Secciones en maduración | Todas visibles | Ocultas | Ocultas |
| Panel de admin | Escribe directo | Solo propuestas | Solo propuestas |
| Deploy de código | — | **Automático** en cada push a `pre` | **Automático** al fusionar `pre` en `main` |
| Deploy de BD | — | — (usa la de PRO) | Manual: `php scripts/sync_db_to_prod.php` |
| Backups (cron Plesk) | — | — | Sí (`app/tools/backup.php`) |
| Monitor uptime | — | No | UptimeRobot ([monitoring.md](monitoring.md)) |

La **maestra de datos sigue siendo la BD local**. Producción tiene escrituras
propias (admin y cron), por lo que el sync sube la local **con guardarraíles**
(backup reciente y propuestas de editores pendientes bajadas) y nunca al revés.

## Cómo se aísla PRE

El subdominio cuelga de la misma cuenta que producción y su document root no se
puede mover desde Plesk. Como `index.php` resuelve `app/` a partir del **padre
del docroot**, los dos entornos resolverían el mismo `app/` y PRE serviría
literalmente el código de producción.

El desvío lo hace **`env.php`**, un fichero en el docroot de PRE que
`index.php` lee (si existe) para sobrescribir `APP_DIR`:

```
/home/jaguerra27/
├── marchasdecristo.com/          ← docroot PRO · index.php → APP_DIR = ../app
├── app/                          ← código de PRO
├── private/mdc.db                ← BD (la leen los DOS entornos)
├── marchasdecristo.jaguerra27.helioho.st/
│   ├── index.php                 ← el mismo del repo
│   └── env.php                   ← APP_DIR = ../pre/app, DATA_DIR = ../private
└── pre/app/                      ← código de PRE
```

`env.php` **no está en el repo** (gitignored): lo genera el job `deploy-pre` en
cada despliegue. Así el mirror de producción no puede llevárselo nunca — y por
si acaso, el mirror de PRO lo excluye explícitamente. Si faltara, PRE cargaría
el `app/` de PRO y el smoke lo cazaría: `/health` diría `entorno: prod`.

`env.php` lleva otras dos cosas, ambas derivadas de `__DIR__` para no depender
de rutas absolutas:

- `data_dir` → `private/`, de donde sale el `db_path` por defecto. Así PRE
  encuentra la BD compartida **sin configurar nada**.
- `preproduccion => true`, el **fail-safe de indexación**: un PRE recién
  desplegado al que todavía le falte su `config.local.php` ya nace con
  `noindex`, en vez de duplicar producción en Google.

`config.local.php` puede sobrescribir ambas si hiciera falta.

**La BD es compartida y PRE la trata como solo lectura**: su `config.local.php`
mantiene `env => 'production'`, que es el fail-safe que bloquea las escrituras
del panel (`Db::assertWritable()`). Preproducción sirve para validar *código*
contra datos reales, no para probar escrituras — para eso está el local.

`App\Entorno` es el único sitio donde se deduce «qué entorno es este» a partir
de esas dos claves (`env` y `preproduccion`). De ahí salen los tres nombres que
imprime `/health` —`local`, `pre`, `prod`— y las dos decisiones que dependen
del entorno: qué secciones se publican y si el panel puede escribir.

## Qué secciones se publican en cada entorno

Algunas secciones están terminadas de código pero aún no tienen el grado de
madurez (datos, curación o pulido) para enseñarlas fuera de local. **No se
borran: se ocultan**, y se republican cuando toque. La lista, con el motivo de
cada una, vive en `App\Secciones::EN_MADURACION`:

| Sección | Rutas | Espera a… |
|---|---|---|
| Dedicatorias | `/dedicatorias`, `/dedicatoria/{slug-id}` | que la curación de advocaciones (alias, unificaciones) esté estable |
| Estado del catálogo | `/estado-catalogo` | que la campaña de audio (P1 · M2) deje la cobertura en un número presentable |
| Mapa | `/mapa`, `/mapa/provincia/{slug}` | corregir el solape de dianas de clic entre municipios próximos |
| Temporada | `/temporada`, `/temporada/{año}` | que `contrato` tenga datos de calidad suficiente |

Ocultar una sección la apaga a la vez en sus **cuatro superficies**: la ruta
(404), el enlace del nav, el `sitemap.xml` y `llms.txt` — más los enlaces
entrantes desde secciones que sí se publican (la sugerencia de la home,
`/rankings` → `/estado-catalogo`). Anunciar una URL que el propio sitio
responde con 404 es peor que no anunciarla.

**Para publicar una sección** hay dos escalones, y se pueden usar en orden:

1. **Solo en un host**: añadir su slug a `secciones_publicadas` en el
   `config.local.php` de ese host. Sirve para sacarla primero en PRE, validarla
   con datos reales y decidir. No requiere desplegar código.
2. **En todas partes**: quitarla de `Secciones::EN_MADURACION` y borrar el slug
   de los `config.local.php` que lo tuvieran. Eso es «publicarla» de verdad.

La segunda pasada de CI (ver abajo) es la que sigue probando el contenido de
estas secciones mientras están ocultas, para que republicarlas no sea un salto
al vacío.

## El panel en PRE y PRO: solo propuestas

Fuera de local **no escribe nadie en la BD, tampoco el administrador**. La
maestra es la local y el sync reemplaza el `.db` remoto entero, así que un
cambio hecho en PRE o PRO se perdería —o pisaría datos buenos— en el siguiente
`sync_db_to_prod.php`.

- **Marcha, banda y autor**: el envío se guarda como **propuesta**
  (`Admin::proposalMode()`), igual que lo que hace el editor en cualquier
  entorno. No se pierde: se baja con `sync_propuestas_from_prod.php` y se aplica
  en local. Como PRE y PRO comparten el directorio de datos, una propuesta
  creada en PRE aparece en la misma cola que las de PRO.
- **El resto del panel** (discos, dedicatorias, estilos, ingesta, enlaces,
  usuarios, temporada) escribe directo y choca con `Db::assertWritable()`: 503
  de solo lectura. Sigue accesible para el admin porque a veces hay que
  *mirarlo* con datos reales.
- El admin ve en todas las pantallas del panel la cinta roja **«PELIGRO: riesgo
  de desincronización. No actuar en este entorno salvo urgencia.»**, y en
  `/dashboard` un aviso que detalla qué funciona y qué no. El editor no la
  necesita: su flujo es idéntico aquí y en local.

## Flujo normal de trabajo

```
cambio de código → push a la rama `pre`
                 → CI (lint + smoke sobre fixture, dos pasadas: PRO y local)
                 → deploy automático a PRE (lftp mirror)
                 → smoke remoto contra PRE (datos reales, exige noindex y cinta)
                 → LO VALIDAS EN EL NAVEGADOR
                 → PR de `pre` a `main` y fusionar
                 → CI → mantenimiento ON → mirror a PRO → mantenimiento OFF (siempre)
                 → smoke remoto contra PRO
```

La **BD va aparte y siempre manual**: `php scripts/sync_db_to_prod.php` cuando
haya que subir datos nuevos. El pipeline de código **nunca** toca `private/`,
`config.local.php`, `cover/` ni `.well-known/`.

**Rollback de código en PRO**: Actions → Deploy → *Run workflow* → en el
desplegable de ref, elegir el commit/tag anterior, target `pro`.

**Rollback de infraestructura/DNS** (distinto del anterior: volver todo el
dominio al VPS antiguo, no solo un commit): el procedimiento de
[cutover-fase5.md §7](cutover-fase5.md) es **obsoleto desde el 2026-07-29** — el
VPS se desmanteló ese día (ver [pendientes-post-cutover.md §5](pendientes-post-cutover.md)).
Ya no existe ese destino de rollback; solo queda el rollback de código en PRO
descrito arriba.

## Puesta en marcha (una sola vez)

### En Plesk
1. Emitir certificado **Let's Encrypt** para el subdominio (SSL/TLS), si no lo
   tiene ya.
2. Desactivar «Serve static files directly by nginx» (igual que en PRO), para
   que el `.htaccess` del docroot mande.

*No hace falta tocar el document root* — el aislamiento no depende de él.

### Por FTP
3. Crear `pre/app/config.local.php` en el host (el pipeline no lo toca nunca):
   ```php
   <?php
   return [
       'debug' => false,
       'env'   => 'production',           // solo lectura: fail-safe de escrituras
       'site_url' => 'https://marchasdecristo.jaguerra27.helioho.st',
       'force_canonical_host' => false,   // OBLIGATORIO: si no, PRE redirige 301 a PRO
       'cover_base_url' => 'https://marchasdecristo.com',
       'secret_key' => '...(96 chars nuevos, NO el de PRO)...',
       // NO pongas 'db_path': env.php ya apunta a private/mdc.db (la de PRO).
       // 'preproduccion' también lo pone env.php. indexnow_key y
       // goatcounter_code se quedan null (defaults) — son de producción.
   ];
   ```

   ⚠️ **Nunca escribas rutas absolutas en la config del host.** El home de
   HelioHost está enjaulado: lo que el panel muestra como `/home/USUARIO` no es
   la ruta que ve PHP, así que un `db_path` absoluto copiado del File Manager
   apunta a un fichero inexistente. Por eso producción usa
   `dirname(__DIR__) . '/private/mdc.db'` y por eso `env.php` deriva todas sus
   rutas de `__DIR__`. Si alguna vez hiciera falta fijar `db_path` en PRE, la
   forma correcta desde `pre/app/` es `dirname(__DIR__, 2) . '/private/mdc.db'`.

   El directorio `pre/app/` lo crea el propio mirror en el primer deploy, así
   que este paso va **después** de él (o se crea el directorio a mano antes).
   **El primer deploy fallará en el smoke** mientras falte el fichero: sin
   `site_url` ni `force_canonical_host`, PRE responde 301 hacia producción. El
   `noindex` y la BD sí funcionan desde el primer segundo (los da `env.php`).
   Con el fichero puesto, relanzar el deploy.

### En GitHub (Settings → Secrets and variables → Actions)
4. Secrets `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` (los mismos de `.env.ftp`):
   ya están si producción despliega. Sin ellos, los dos jobs de deploy se
   **omiten** (no fallan) — así un fork sin credenciales no deja runs en rojo.
5. Variables opcionales: `PRE_BASE_URL` / `PRO_BASE_URL` (URLs) y
   `PRE_REMOTE_DOCROOT` (nombre del directorio del subdominio en el FTP, si no
   fuera `marchasdecristo.jaguerra27.helioho.st`).
6. Crear la rama `pre` y empujarla. **Ojo con el nombre**: el workflow escucha
   exactamente `pre`, en minúsculas.

### Validación del conjunto
7. Con el deploy de PRE en verde, abrir el subdominio: cinta de preproducción,
   `/health` con `entorno: pre` y `db: ok`, y las portadas visibles.
8. Fusionar `pre` en `main` y comprobar que PRO despliega solo y su smoke pasa.

## Qué vigilar / limitaciones conocidas

- **PRE y PRO comparten la BD.** El panel de admin está en solo lectura en PRE
  por el fail-safe `env !== 'local'`, pero **cualquier código nuevo que escriba
  en la BD por otra vía escribirá en los datos reales**. Si una rama toca
  escrituras o migraciones, valídala en local, no en PRE.
- Como consecuencia, PRE entra en **modo mantenimiento** a la vez que PRO: el
  centinela `.maintenance` vive junto al `.db`, que es el mismo fichero.
- **PRE no tiene portadas propias**: las carga desde `marchasdecristo.com` vía
  `cover_base_url` (por eso la CSP de `.htaccess` incluye ese origen en
  `img-src`). Si producción estuviera caída, en PRE no se verían.
- **Sin Basic Auth**: el subdominio es accesible para quien conozca la URL. La
  defensa contra indexación es triple (meta `noindex`, `X-Robots-Tag` en toda
  respuesta PHP y `robots.txt` en Disallow total) y el smoke remoto la
  comprueba en cada deploy, pero no es un control de acceso.
- **FTP desde GitHub Actions**: si HelioHost bloqueara las IPs de Actions, el
  mirror fallará con timeout — se vería en el log del job. El mirror usa **FTP
  plano**, igual que los scripts de sync (es lo que ofrece el FTP de HelioHost).
  Si el servidor admite FTPS explícito, cambiar `set ftp:ssl-allow false` a
  `true` en `deploy.yml` y probar.
- El deploy de PRO activa el **modo mantenimiento** (503 + Retry-After) durante
  el mirror — la ventana es de segundos, pero puede cruzarse con un chequeo de
  UptimeRobot y generar el aviso esperado documentado en
  [monitoring.md](monitoring.md).
- El deploy **no es transaccional**: si el mirror falla a mitad, el código queda
  mixto. El paso de mantenimiento minimiza el impacto (nadie lo ve servir) y el
  arreglo es relanzar el deploy (o el rollback por ref).
- El mirror pisa el `.htaccess` del docroot y `app/*` (o `pre/app/*`) en cada
  deploy: cualquier ajuste manual hecho por FTP se pierde. El sitio legítimo
  para configuración por-host es `config.local.php`.
- **Cloudflare**: si HelioHost pone Cloudflare delante del dominio, purgar su
  caché tras un deploy con cambios de contenido grandes — si no, puede servir
  HTML/assets viejos un rato.
