# Post-cutover — plan de actuación pendiente

> El sitio **ya está migrado y en producción** en `https://marchasdecristo.com`
> (stack PHP en HelioHost). Este documento recoge lo que queda, para retomarlo
> en una nueva sesión.

---

## Estado actual (resumen)

- `marchasdecristo.com` sirve el nuevo sitio PHP: público, SEO (canónicas, sitemap
  5.744 URLs todas 200, JSON-LD, robots), admin, caché, hardening y **398 portadas**.
- **Redirects** (301/308, sin bucles): `http→https`, `www→no-www`,
  `jaguerra27.helioho.st`/`id-legado → canónico`.
- **Config del host** en `app/config.local.php` (no versionado): `debug=false`,
  `force_canonical_host=true`, `secret_key` (generado, 96 chars), `db_path` en
  `private/mdc.db`.
- En Plesk se **desactivó "Serve static files directly by nginx"** → los estáticos
  los sirve Apache con la caché de 30 días del `.htaccess`.
- Commits (rama `feat/frontend-overhaul`): Fases 0-2 `7c957d5`, Fase 3 `12b13dd`,
  Fase 4 `2c41b9c`, runbook `32b3898`, canónico `4dd80ad`, fix sitemap `91a553f`.

---

## Tareas pendientes

### 1. Verificar el panel de admin en producción  ·  *(tú)*
- [ ] Entrar en `marchasdecristo.com/login` con tus credenciales de admin.
- [ ] Buscar una marcha, editarla, guardar y confirmar que persiste.
- [ ] Probar el alta de una marcha (con autocomplete de autores) y de un compositor.
- Nota: el `secret_key` es nuevo → entras de cero. Si quieres sesiones "sin corte",
  sustitúyelo por la `SECRET_KEY` del VPS (`/var/www/mdc-back/.env`) en
  `app/config.local.php`.

### 2. Cron de backup  ·  *(Plesk)*
- [ ] Plesk → **Scheduled Tasks** → *Add Task* → tipo **"Run a PHP script"** →
      `/home/jaguerra27.helioho.st/app/tools/backup.php` → **semanal** (p. ej. domingo 03:00).
      Retención subida a `backup_keep_days=60` (~8-9 copias) para compensar la
      cadencia semanal. También se puede lanzar a mano desde Plesk tras ediciones
      importantes en el admin.
      ⚠️ **El PHP por defecto de las Scheduled Tasks es PHP 5.x** (falla con
      `Unsupported declare 'strict_types'` en cualquier script del repo, que usa
      PHP 8.4) — hay que **seleccionar 8.4 explícitamente** en el formulario de
      la tarea. Descubierto 2026-07-23 al ejecutar `completar_provincia.php`.
- [ ] Ejecutarlo una vez a mano y confirmar que aparece `private/backups/mdc-*.db`.

### 3. Search Console  ·  *(tú)*
- [ ] Property de `marchasdecristo.com` (la de siempre; las URLs no cambian).
- [ ] **Sitemaps** → reenviar `sitemap.xml`.
- [ ] **Inspección de URLs** en la home y 2-3 detalles → *Solicitar indexación*.
- [ ] Vigilar **Páginas/Cobertura** 1-2 semanas (que no aparezcan 404/500 nuevos;
      que Google consolide los 301 de www/staging).

### 4. Correo del dominio, si aplica  ·  *(tú)*
- [ ] Si `marchasdecristo.com` tiene email, confirmar que sigue funcionando (el
      cutover solo debía cambiar el registro `A`, no el `MX`). Enviar/recibir una prueba.

### 5. Desmantelar el VPS  ·  *(cuando esté estable, ~1-2 semanas)*
- [ ] Mantenerlo como **rollback** mientras vigilas Search Console.
- [ ] Cuando todo esté ok: `docker compose down` en el VPS y dar de baja el servidor.
- [ ] Subir de nuevo el **TTL** del DNS a su valor normal.

### 6. Opcionales / limpieza
- [ ] Eliminar el subdominio vacío `marchasdecristo.jaguerra27.helioho.st`. Se
      intentó reaprovechar como entorno de PREPRODUCCIÓN (2026-07-16) pero se
      **descartó el 2026-07-23** (HelioHost no permite mover su document root
      desde el panel). Vuelve a quedar libre para borrar. Ver
      [entornos.md](entornos.md).
- [ ] Revisar logs del host y espacio en disco (crecimiento de `private/backups/`).
- [ ] Borrar el `.sql.zip` viejo del *home* si sigue ahí.

---

## Referencia rápida (desarrollo / despliegue)

- **Estructura**: `php/public/` = webroot; `php/app/` y `private/` fuera del webroot.
  En el host, el webroot del dominio es la carpeta `marchasdecristo.com/` (hermana de
  `app/` y `private/`).
- **Despliegue por FTP**: credenciales en `.env.ftp` (gitignored). Subir `app/`
  (excepto `config.local.php`) → `app/`, y el contenido de `public/` → `marchasdecristo.com/`.
- **Paridad** (tras cambios en la capa de datos):
  `cd php && node tools/parity_expected.cjs && php tools/parity_compare.php`  → 28/28.
- **Servidor local**: `scripts/dev_server.sh` (servidor embebido con 4 workers; a
  mano sin workers, el CSS/JS se encola detrás del HTML → 503 intermitente).
- **Runbook de cutover** (referencia): [cutover-fase5.md](cutover-fase5.md).
