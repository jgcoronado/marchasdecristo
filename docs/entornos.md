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
/home/USUARIO/
├── marchasdecristo.com/          ← docroot PRO · index.php → APP_DIR = ../app
├── app/                          ← código de PRO
├── private/mdc.db                ← BD (la leen los DOS entornos)
├── marchasdecristo.jaguerra27.helioho.st/
│   ├── index.php                 ← el mismo del repo
│   └── env.php                   ← APP_DIR = ../pre/app   (lo genera el deploy)
└── pre/app/                      ← código de PRE
```

`env.php` **no está en el repo** (gitignored): lo genera el job `deploy-pre` en
cada despliegue. Así el mirror de producción no puede llevárselo nunca — y por
si acaso, el mirror de PRO lo excluye explícitamente. Si faltara, PRE cargaría
el `app/` de PRO y el smoke lo cazaría: `/health` diría `entorno: prod`.

**La BD es compartida y PRE la trata como solo lectura**: su `config.local.php`
mantiene `env => 'production'`, que es el fail-safe que bloquea las escrituras
del panel (`Db::assertWritable()`). Preproducción sirve para validar *código*
contra datos reales, no para probar escrituras — para eso está el local.

## Flujo normal de trabajo

```
cambio de código → push a la rama `pre`
                 → CI (lint + smoke sobre fixture)
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
dominio al VPS antiguo, no solo un commit): procedimiento en
[cutover-fase5.md §7](cutover-fase5.md) — vigente solo **mientras el VPS
no se desmantele** (ver [pendientes-post-cutover.md](pendientes-post-cutover.md)).

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
       'preproduccion' => true,           // noindex + robots Disallow + cinta
       'site_url' => 'https://marchasdecristo.jaguerra27.helioho.st',
       'force_canonical_host' => false,   // OBLIGATORIO: si no, PRE redirige 301 a PRO
       'db_path' => '/home/USUARIO/private/mdc.db',   // la MISMA BD que producción
       'cover_base_url' => 'https://marchasdecristo.com',
       'secret_key' => '...(96 chars nuevos, NO el de PRO)...',
       // indexnow_key y goatcounter_code se quedan null (defaults)
   ];
   ```
   El directorio `pre/app/` lo crea el propio mirror en el primer deploy, así
   que este paso va **después** de él (o se crea el directorio a mano antes).
   **El primer deploy fallará en el smoke** (sin `config.local.php`, `/health`
   dice `entorno: prod` y no encuentra la BD): es su forma de recordarte este
   paso. Con el fichero puesto, relanzar el deploy.

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
