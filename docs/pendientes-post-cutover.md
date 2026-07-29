# Post-cutover — plan de actuación pendiente

> Actualizado: 2026-07-27 (auditoría documental — items 1 y 3 ya resueltos según
> otros documentos del repo, alineados aquí; referencia rápida recortada para no
> duplicar `entornos.md`)
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

### ~~1. Verificar el panel de admin en producción~~ ✅ Ya no aplica tal como estaba escrito
- Esta tarea pedía editar/dar de alta directamente en producción. Desde que se
  adoptó **producción de solo lectura por diseño** (`Db::assertWritable()`,
  ADR-003 en [architecture.md](architecture.md) — decidido después de escribir
  esta tarea), cualquier intento de escritura en producción muestra
  `readonly.php` en vez de guardar nada. La prueba real de alta/edición se hace
  en **local** (donde `env=local` sí permite escribir) — ver §5 de
  [context.md](context.md). En producción solo cabe verificar navegación/lectura
  del panel y, si se quiere, que un intento de escritura efectivamente cae en
  modo solo-lectura.

### ~~2. Cron de backup~~  ·  *(Plesk)* — ✅ Verificado 2026-07-29
> Resuelto como contradicción documental el 2026-07-29: `cutover-fase5.md` y
> `roadmap.md` T-03 ya no afirmaban nada por su cuenta, apuntaban aquí. La
> verificación en sí (que no se podía hacer desde el repo) se completó el
> mismo día.

- `cutover-fase5.md` registraba el cron como "configurado, confirmado por el
  usuario" el 2026-07-06, y `context.md`/`entornos.md` lo daban por hecho sin
  matices; `roadmap.md` (T-03) lo marcaba como "Parcial". Ninguna de esas
  afirmaciones se había reverificado desde entonces.
- [x] Confirmado en Plesk → **Scheduled Tasks**: la tarea existe y corre en
      PHP 8.4. Ejecución manual de comprobación el 2026-07-29:
      `backup OK: /home/jaguerra27.helioho.st/private/backups/mdc-20260729-132640.db (9.629.696 bytes)`.
- [x] Backup reciente confirmado (el de arriba, 9,6 MB, mismo día).
- [x] `roadmap.md` T-03/OPS-03 actualizado a completado.

### ~~3. Search Console~~ ✅ Hecho 2026-07-06, ver `cutover-fase5.md` §6
- Sitemap reenviado (5.744 URLs, "Correcto"), cobertura revisada, URLs clave
  inspeccionadas/indexación solicitada — todo con fecha y confirmación en
  [cutover-fase5.md](cutover-fase5.md) §6, escrito **después** de que se
  redactara esta tarea (que quedó sin marcar por descuido, no porque siguiera
  pendiente).
- La vigilancia de "Páginas/Cobertura 1-2 semanas" ya venció (han pasado ~3
  semanas desde el cutover). Si hace falta seguir vigilando SEO de forma
  continua, es una tarea de `roadmap.md`/`technical-debt.md`, no un pendiente
  de "post-cutover" — este ítem se da por cerrado.

### 4. Correo del dominio, si aplica  ·  *(tú)*
- [ ] Si `marchasdecristo.com` tiene email, confirmar que sigue funcionando (el
      cutover solo debía cambiar el registro `A`, no el `MX`). Enviar/recibir una prueba.

### ~~5. Desmantelar el VPS~~  ·  ✅ Hecho — confirmado por el usuario 2026-07-29
- [x] Se mantuvo como **rollback** ~3,5 semanas (más de las 1-2 previstas,
      mientras se vigilaba Search Console).
- [x] `docker compose down` en el VPS y servidor dado de baja: **todo
      apagado**.
- [x] TTL del DNS subido a su valor normal.
- ⚠️ **Consecuencia**: el [runbook de rollback de infraestructura/DNS
      (cutover-fase5.md §7)](cutover-fase5.md) queda **obsoleto** desde hoy —
      ya no hay VPS al que volver. Si algo grave ocurriera en HelioHost, la
      recuperación ya no puede apoyarse en ese runbook.

### 6. Opcionales / limpieza
- [x] ~~Eliminar el subdominio vacío `marchasdecristo.jaguerra27.helioho.st`~~ —
      **reaprovechado como entorno de PREPRODUCCIÓN** (2026-07-28, tras un primer
      intento descartado el 2026-07-23). Ver [entornos.md](entornos.md) para su
      configuración y el pipeline de despliegue.
- [ ] Revisar logs del host y espacio en disco (crecimiento de `private/backups/`).
- [ ] Borrar el `.sql.zip` viejo del *home* si sigue ahí.

---

## Referencia rápida

El despliegue de código ya **no** es un paso manual por FTP — está
automatizado desde CI (push a `pre` → PRE, fusión en `main` → PRO; ver
[entornos.md](entornos.md), que sustituye por completo lo que decía aquí antes). Lo que sigue siendo
específico de esta fase de cierre:

- **Paridad** (histórico, solo si se toca la capa de datos de forma que
  pudiera divergir del port original):
  `cd php && node tools/parity_expected.cjs && php tools/parity_compare.php` → 28/28.
- **Servidor local**: `scripts/dev_server.sh` — ver `php/README.md`.
- **Runbook de cutover** (referencia histórica): [cutover-fase5.md](cutover-fase5.md).
