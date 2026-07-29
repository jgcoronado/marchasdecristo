# Fase 5 — Cutover de marchasdecristo.com a HelioHost

> Runbook paso a paso. Marca las casillas a medida que avanzas.
> **Principio rector:** el SEO es el activo crítico. El VPS (Next.js) sigue siendo
> la fuente de verdad y el rollback hasta que el cambio esté validado.

La estructura de URLs del PHP es **idéntica** a la del Next.js (mismas rutas
`slug-id`, mismos redirects 308, mismo sitemap), así que el cutover **no cambia
ninguna URL** — es solo mover el dominio de un servidor a otro.

---

## 0. Datos que necesitarás a mano

- **IP de HelioHost** de tu cuenta: Plesk → *Sitios web y dominios → jaguerra27.helioho.st
  → Webspace settings → IP addresses* (o `ping jaguerra27.helioho.st`).
- **Dónde se gestiona el DNS de marchasdecristo.com** (registrador o Cloudflare):
  ahí cambiarás el registro `A`.
- Acceso al **VPS** (para la sincronización final del `.db`).

---

## 1. Pre-requisitos (días antes) — sin tocar el DNS todavía

- [x] Fases 3-4 subidas y **verificadas en `jaguerra27.helioho.st`**: `/login` responde
      200, `/health` anónimo solo muestra `status`+versión (sin ruta del `.db`).
      *(Pendiente por tu parte: probar login con tus credenciales reales y hacer una
      edición de prueba — no puedo autenticarme yo.)*
- [x] **Portadas**: `/cover/1.png` y `/cover/50.png` cargan (200, `image/png`, tamaño
      correcto). Nota: el listado FTP no muestra la carpeta `cover/` (posible
      restricción de permisos de listado), pero HTTP la sirve bien, así que no bloquea.
- [x] `secret_key` y `'debug' => false` ya definidos en `app/config.local.php` del host
      (confirmado por el usuario 2026-07-06).
- [x] **Cron de backup** configurado en Plesk (confirmado por el usuario 2026-07-06).
      ⚠️ *Nota añadida 2026-07-29*: esta línea es el registro de lo que se confirmó
      ese día y se deja tal cual (este documento es un runbook ejecutado, no un
      estado vivo). Pero **no se ha vuelto a verificar desde entonces** y es el
      origen de una contradicción entre documentos. El dueño único de ese dato es
      [pendientes-post-cutover.md §2](pendientes-post-cutover.md); no cites esta
      línea como prueba de que el cron existe hoy.
- [x] Añadir **marchasdecristo.com** (y `www`) al panel de HelioHost/Plesk como dominio o
      alias del sitio: confirmado, ambos resuelven a la IP de HelioHost y sirven el sitio.
- [x] **SSL** (Let's Encrypt / AutoSSL) para `marchasdecristo.com` y `www`: confirmado,
      `https://` responde sin error de certificado en ambos.
- [x] En Plesk → *Hosting Settings*: **Redirect HTTP → HTTPS (301)** y dominio preferido
      no-www: confirmado — `http://` → 301 a `https://`, `www.` → 301 a no-`www`.
- [x] **Decisión (2026-07-06): TTL se deja como está**, sin bajarlo a 300s. Implicación:
      si hace falta rollback (Sección 7), la propagación puede tardar más que los minutos
      que daría un TTL bajo — asúmelo como riesgo aceptado.

---

## 2. Sincronización final de datos (el día del cutover)

> El `.db` del host es una foto antigua. Hay que traer los últimos datos del VPS.

- [x] `.db` original ya subido a `private/` del host (confirmado por el usuario 2026-07-06).
- [x] Verificar en el host (con sesión admin) que `/health` muestra los conteos correctos:
      confirmado 2026-07-06 — marchas=4212, autores=827, bandas=268, discos=431 (coincide
      con `docs/context.md`), fts5: OK.

---

## 3. El cambio (ventana de cutover)

- [x] Registro **`A`** de marchasdecristo.com → IP de HelioHost (`65.19.154.93`):
      confirmado por `nslookup` (misma IP que `jaguerra27.helioho.st`). No he tocado ni
      verificado registros `AAAA`/`MX`.
- [x] Propagación completa: `nslookup marchasdecristo.com` devuelve la IP de HelioHost.
- [x] SSL válido en `https://marchasdecristo.com`: confirmado (conexión TLS correcta,
      sin necesitar `-k`/`--insecure`).

---

## 4. Verificación post-cutover (inmediata)

- [x] `https://marchasdecristo.com/` carga (200, HTML completo). *(Conteos en el pie no
      verificados visualmente — confírmalo tú si depende de datos actualizados.)*
- [x] Detalle: `/marcha/consuelo-gitano-330` → 200.
- [x] Canónica: `/marcha/330` → **308** → `/marcha/consuelo-gitano-330`.
- [x] `http://` → `https://` redirige (301); `www.` → no-`www` redirige (301).
- [x] `/sitemap.xml` → 200; `/robots.txt` → `Sitemap: https://marchasdecristo.com/sitemap.xml`.
- [x] Admin: `/login`, entrar, una edición real de prueba y comprobar que persiste:
      confirmado por el usuario 2026-07-06 (asignó banda de estreno a una marcha, el
      cambio persistió). Hubo un fallo de conexión puntual al recargar justo tras
      guardar (transitorio, no reproducible — al recargar cargó bien).
- [x] `/health` anónimo: solo `status: ok` + `php: 8.4.22` (sin ruta del `.db`).
- [x] Cabeceras en `/marcha` (GET): `Cache-Control: public, max-age=3600`,
      `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`.
- [x] Portadas de discos cargan (`/cover/1.png`, `/cover/50.png` → 200, `image/png`).
- [x] **PageSpeed Insights 100/100/100/100** (móvil, Lighthouse local contra producción,
      2026-07-06): se corrigió contraste de color (`--muted` `#6b7280`→`#4b5563`, `.firma`
      pasó a usar `var(--muted)`) y el 404 de `/favicon.ico` (se añadió
      `httpdocs/assets/favicon.svg` y `<link rel="icon">` en `layout.php`). Pendiente menor
      sin aplicar (impacto marginal, no baja el score): subir cache TTL de `app.css` a 1
      año requeriría antes un esquema de cache-busting (el archivo no tiene hash/versión
      en el nombre).

---

## 5. Subdominio de staging (evitar contenido duplicado)

`jaguerra27.helioho.st` sigue sirviendo el mismo sitio. Como todas las `og:url`/JSON-LD/
canónicas apuntan a `marchasdecristo.com`, Google **consolida** hacia el dominio bueno,
así que el riesgo es bajo. Aun así, recomendable cerrar la puerta:

- [x] Opción A (recomendada): **301 del subdominio (y `www`) a marchasdecristo.com** — ya
      implementado en la app. Confirmado activo: `http://jaguerra27.helioho.st/` → 301 →
      `https://marchasdecristo.com/`. Alguien ya puso `'force_canonical_host' => true` en
      `config.local.php` del host.
- [ ] Opción B: `noindex` para el host de staging (redundante ya que la Opción A está
      activa, pero puedes añadirlo como capa extra si quieres).

---

## 6. Search Console (primeros días)

- [x] Reenviar `sitemap.xml` en Search Console: confirmado 2026-07-06, estado "Correcto",
      5744 URLs descubiertas.
- [x] Revisar **Cobertura/Indexación**: vigilar 404/500 y páginas excluidas nuevas.
      Revisado 2026-07-06 — necesita unos días para que la reindexación tras el cutover
      se refleje; sin señales de alarma por ahora.
- [x] Inspeccionar URLs clave (home + un par de detalles) → "URL está en Google" /
      solicitar indexación: hecho 2026-07-06, solicitada indexación de home + 4 páginas
      clave.
- [x] Vigilar **Rendimiento** (clics/impresiones) unos días por si hay caída. Revisado
      2026-07-06 — usuario lo comprobará más adelante, cuando haya datos suficientes tras
      el cutover para comparar.

---

## 7. Rollback (si algo va mal)

1. Volver a apuntar el registro `A` de marchasdecristo.com → **IP del VPS**.
2. Con el TTL a 300s, propaga en minutos. El VPS sigue sirviendo Next.js intacto.

No borres ni apagues el VPS hasta que la Fase 8 esté hecha.

> Este es el rollback de **infraestructura/DNS** (todo el dominio, no un
> commit). [entornos.md](entornos.md) enlaza aquí para quien necesite
> encontrarlo sin saber que vive en un runbook histórico — sigue siendo válido
> mientras el VPS no se desmantele (ver
> [pendientes-post-cutover.md](pendientes-post-cutover.md), tarea 5).

---

## 8. Limpieza (semanas después, con todo estable)

- [ ] Apagar/retirar el VPS (o mantenerlo un tiempo más como backup).
- [ ] Subir de nuevo el **TTL** del DNS a su valor normal.
- [ ] Revisar los logs del host y el espacio en disco (backups).

---

## Notas

- **`site_url`** ya vale `https://marchasdecristo.com` por defecto, así que tras el cutover
  todas las URLs canónicas/OG/sitemap son correctas sin tocar nada.
- Si HelioHost pone Cloudflare delante del dominio, considera **purgar su caché** justo
  tras el cutover y en cada cambio grande de contenido.
- El `.db` y sus backups viven en `private/` (fuera del webroot). Confirma que esa carpeta
  tiene escritura (el admin, el rate-limiter y el cron escriben ahí).
