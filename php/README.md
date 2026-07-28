# Marchas de Cristo — puerto a PHP (HelioHost)

Reescritura de la app en **PHP 8.4 plano + PDO/SQLite + plantillas PHP**, sin
composer ni `vendor/` (deploy por FTP en HelioHost). Se construye en paralelo al
Next.js actual; el cutover es la última fase (ver `docs/` y la memoria del proyecto).

## Estructura

```
php/
├── public/            → contenido de  public_html/  en HelioHost
│   ├── index.php        front controller
│   ├── .htaccess        mod_rewrite: todo → index.php (sirve estáticos reales tal cual)
│   ├── assets/app.css   estilos mínimos (el diseño final llega en Fase 2)
│   └── cover/           portadas *.png (subir aquí los PNG del VPS)
├── app/               → FUERA de public_html (p.ej. /home/USER/app)
│   ├── bootstrap.php    autoload + config + dispatch
│   ├── config.php       defaults (sin secretos)
│   ├── config.local.php secretos/overrides — NO se versiona (copiar del .example)
│   ├── routes.php       definición de rutas
│   ├── src/             Router, Db, Slug, View
│   └── templates/       layout.php + vistas
└── data/              → FUERA de public_html (p.ej. /home/USER/data)
    └── mdc.db           base de datos (NO se versiona; se descarga del VPS)
```

`index.php` resuelve las rutas con `dirname(__DIR__)`, así que **`app/` y `data/`
deben ser hermanos del document root** tanto en local (`php/`) como en el host
(`/home/USER/`, hermano de `public_html`).

## Desarrollo en local

1. Descarga `mdc.db` del VPS y déjalo en `php/data/mdc.db`:
   ```bash
   # en el VPS actual
   cd /var/www/mdc-back
   docker compose stop
   docker cp mdc-nextjs:/app/data/mdc.db ./mdc.db
   docker compose start
   ```
2. Crea tu config local:
   ```bash
   cp php/app/config.local.example.php php/app/config.local.php   # y pon 'debug' => true
   ```
3. Arranca el servidor de desarrollo:
   ```bash
   scripts/dev_server.sh              # localhost:8000, 4 workers
   scripts/dev_server.sh 8080         # otro puerto
   HOST=0.0.0.0 WORKERS=8 scripts/dev_server.sh
   DB_PATH=/ruta/otra.db scripts/dev_server.sh   # BD alternativa (p. ej. una fixture de CI)
   ```
   El script comprueba que la BD existe, avisa si falta `config.local.php` y
   arranca el servidor embebido **con varios workers**, que es lo que evita el
   problema siguiente.

   > ⚠️ **No lo arranques a mano sin workers.** Por defecto el servidor embebido
   > procesa **una petición a la vez** (`PHP_CLI_SERVER_WORKERS=1`): mientras
   > genera una página, el CSS/JS que el navegador pide en paralelo se queda
   > encolado detrás. Medido en este proyecto, con una página de 400 ms:
   > `app.css` tarda ~350 ms con 1 worker frente a <1 ms con 4. Con la BD real y
   > varias pestañas abiertas eso degenera en assets que llegan tarde o se
   > descartan bajo carga → **503 intermitente en `app.css`** y páginas sin
   > estilos. El equivalente manual del script es:
   > ```bash
   > PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t php/public php/public/index.php
   > ```
   > En Windows la variable se ignora (los workers usan `fork()`): allí el
   > servidor embebido sirve de una en una, sin remedio.
   >
   > Para pruebas de carga o UX realistas, sirve detrás de Apache/Nginx + PHP-FPM
   > en vez del servidor embebido.
4. Abre:
   - http://localhost:8000/        → home (conteos reales en el pie = BD OK)
   - http://localhost:8000/health  → diagnóstico app → PDO → SQLite → FTS5

## Deploy en HelioHost (FTP)

> **Desde M5 el deploy de código está automatizado**: se valida en local, se
> hace push a `main` (que dispara CI) y se promociona a producción con el botón
> *Run workflow* en Actions — ver [docs/entornos.md](../docs/entornos.md). La
> tabla siguiente documenta el equivalente manual (útil para entender qué hace
> el pipeline, o como plan B).

| Local | Destino en HelioHost |
|-------|----------------------|
| `php/public/*` | `public_html/` |
| `php/app/` | `/home/USER/app/` (fuera de `public_html`) |
| `php/data/mdc.db` | `/home/USER/data/mdc.db` (fuera de `public_html`) |

Luego crea `app/config.local.php` en el host con la `secret_key` (Fase 3) y, si
usas subdominio de staging, el `site_url`. Visita `/health` para confirmar todo.

> ⚠️ El `.db` **nunca** debe quedar dentro de `public_html`. Hay un `.htaccess`
> de bloqueo en `data/` como red de seguridad, pero lo correcto es tenerlo fuera.

## Validación de paridad (Fase 1)

`app/src/Repo.php` es el port de `nextjs/lib/api.ts`. Para probar que devuelve
datos idénticos al sistema actual:

```bash
node tools/parity_expected.cjs   # ejecuta el SQL exacto de api.ts (better-sqlite3) → parity_expected.json
php  tools/parity_compare.php     # ejecuta Repo y compara campo a campo (estricto: tipo + valor)
```

- `parity_expected.cjs` es el "espejo" del sistema en producción (mismo SQL, con
  `json_group_array`). `parity_compare.php` compara la salida del `Repo`.
- Comparación **estricta por defecto** (tipo y valor). `LENIENT=1` tolera igualdad
  numérica. Estado actual: **28/28 casos OK** (23 de datos + 5 de JSON-LD schema.org).

## Estado

- **Fase 0 (hecha):** esqueleto — router, PDO/SQLite, slug, plantillas, `/health`.
- **Fase 1 (hecha):** `Repo.php` — todas las lecturas de `api.ts` portadas y
  validadas con paridad estricta. Autores agrupados en PHP (sin JSON1).
- **Fase 2 (hecha):** páginas públicas (home, listados/búsqueda, detalles de las 4
  entidades, estadísticas), URLs `slug-id` con redirect 308, `<title>`/meta/OG,
  JSON-LD (`Seo.php`, verificado con paridad), `sitemap.xml` y `robots.txt`, y CSS
  propio (`public/assets/app.css`, tema claro tipo lofi, sin build). Rutas en
  `routes.php`, controladores en `Pages.php`.
- **Fase 3 (hecha):** auth + panel admin server-rendered. `Auth.php` (sesión HMAC,
  PBKDF2/MD5 con auto-upgrade, rate-limit a fichero, CSRF) — **compatible con los
  hashes y sesiones de Next**. `AdminRepo.php` (escrituras: allowlists, validación
  FECHA, transacciones, audit log) y `Admin.php` (controladores PRG). Formularios en
  `templates/admin/`, autocomplete de autores en `public/assets/admin.js`.
  Requiere `secret_key` en `config.local.php` y `.db` con escritura.
- **Fase 4 (hecha):** caché (`Cache-Control`: detalle 1h, home/estadísticas 30min,
  búsquedas `no-store`, sitemap 1h; estáticos 30d por `.htaccess`), cabeceras de
  seguridad, `/health` restringido (detalle solo con sesión admin) y backup del
  `.db` por cron (`app/tools/backup.php`, VACUUM INTO + retención). Ver "Backups".
- **Fase 5:** cutover DNS (apuntar marchasdecristo.com) + vigilancia en Search Console.

## Backups (cron)

`app/tools/backup.php` hace una copia consistente del `.db` (`VACUUM INTO`) en
`backups/` junto a la BD (fuera del webroot) y purga las de más de 14 días. En
HelioHost → **Cron Jobs**, diario:

```
/usr/local/bin/php /home/USUARIO/app/tools/backup.php
```

(ajusta la ruta de PHP a la de tu versión si difiere).
