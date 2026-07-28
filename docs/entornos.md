# Despliegue a producción

> Generado: 2026-07-16 (M5) · Actualizado: 2026-07-23 (entorno PRE retirado)

Hay **dos entornos**: el **local** (donde se desarrolla y valida) y
**producción** (`marchasdecristo.com`, en HelioHost). No hay preproducción.

> **Nota histórica.** M5 llegó a montar un entorno de PREPRODUCCIÓN reutilizando
> el subdominio `marchasdecristo.jaguerra27.helioho.st`. Se **descartó el
> 2026-07-23**: el aislamiento dependía de apuntar el document root del
> subdominio a `pre/httpdocs`, y HelioHost (hosting gratuito, panel Plesk) no
> permite editar ese campo desde la cuenta —está bloqueado—. Sin ese cambio no
> había forma limpia de aislar PRE de PRO, así que se retiró todo el andamiaje
> (job `deploy-pre`, config `preproduccion`, cinta, `--pre`/`--env pre`).

## Mapa

| | **Local** | **Producción** |
|---|---|---|
| URL | `http://127.0.0.1:puerto` (servidor embebido de PHP) | `https://marchasdecristo.com` |
| Código | `php/` en el repo | `app/` + `marchasdecristo.com/` (docroot) |
| BD + privados | `php/data/mdc.db` (maestra) | `private/` |
| Deploy de código | — | **Manual**: botón *Run workflow* en Actions |
| Deploy de BD | — | Manual: `php scripts/sync_db_to_prod.php` |
| Backups (cron Plesk) | — | Sí (`app/tools/backup.php`) |
| Monitor uptime | — | UptimeRobot ([monitoring.md](monitoring.md)) |

La **maestra de datos es la BD local**. Producción tiene escrituras propias
(admin y cron), por lo que el sync sube la local **con guardarraíles** (backup
reciente y propuestas de editores pendientes bajadas) y nunca al revés.

## Flujo normal de trabajo

```
cambio de código → validar en LOCAL (servidor embebido + smoke)
                 → push/merge a main
                 → CI (lint + smoke sobre fixture, ver ci_smoke.php para el conteo real)   ← se ejecuta solo, no despliega
                 → Actions → Deploy → Run workflow       ← promoción manual a PRO
                 → mantenimiento ON → mirror FTP a PRO → mantenimiento OFF (siempre)
                 → smoke remoto contra PRO (datos reales)
```

La **BD va aparte y siempre manual**: `php scripts/sync_db_to_prod.php` cuando
haya que subir datos nuevos. El pipeline de código **nunca** toca `private/`,
`config.local.php`, `cover/` ni `.well-known/`.

**Rollback de código en PRO**: Actions → Deploy → *Run workflow* → en el
desplegable de ref, elegir el commit/tag anterior.

**Rollback de infraestructura/DNS** (distinto del anterior: volver todo el
dominio al VPS antiguo, no solo un commit): procedimiento en
[cutover-fase5.md §7](cutover-fase5.md) — vigente solo **mientras el VPS
no se desmantele** (ver [pendientes-post-cutover.md](pendientes-post-cutover.md)).

## Puesta en marcha (una sola vez)

En GitHub → *Settings → Secrets and variables → Actions*, definir los secrets
`FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` (los mismos de `.env.ftp`). Opcional:
variable `PRO_BASE_URL` para sobrescribir la URL por defecto. Sin secrets FTP,
el job `deploy-pro` se **omite** (no falla) — así un fork sin credenciales no
deja runs en rojo.

## Qué vigilar / limitaciones conocidas

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
- El mirror pisa `marchasdecristo.com/.htaccess` y `app/*` en cada deploy:
  cualquier ajuste manual hecho por FTP se pierde. El sitio legítimo para
  configuración por-host es `config.local.php`.
- **Cloudflare**: si HelioHost pone Cloudflare delante del dominio, purgar su
  caché tras un deploy con cambios de contenido grandes (no solo en el
  cutover inicial) — si no, puede servir HTML/assets viejos un rato.
