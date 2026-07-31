# Pendientes manuales — cierre de B-01 y arranque de OPS (2026-07-30)

> Este documento es **operativo y de corta vida**: nace del cierre de B-01 en
> `docs/roadmap.md` §2/§4 y se archiva (no se borra) en cuanto todo lo de aquí
> quede resuelto — a partir de ahí, el estado vive solo en `roadmap.md`. No es
> un tracker paralelo: es la lista de "qué toca hacer tú, con tus manos, desde
> tu máquina" porque una sesión en la nube no tiene ni credenciales de
> producción ni el `.db` local.
>
> Escrito para que una **sesión de Claude Code local** (con acceso a tu
> repositorio clonado, `.env.ftp`, `config.local.php` y `php/data/mdc.db`)
> pueda recogerlo sin tener que releer todo el hilo anterior. Cada ítem indica
> **quién actúa** y **qué comando exacto ejecutar**. Si algo queda resuelto,
> táchalo con `~~texto~~` y anota la fecha.

---

## 0. Contexto en una frase

Una sesión en la nube (sin acceso a tus credenciales) fusionó **todas** las
ramas `claude/*` sueltas + M6 + M7 en una sola, la llevó a `pre`, la validó
con smoke tests locales y remotos, y abrió el PR
**[#27](https://github.com/jgcoronado/mdc-back/pull/27)** (`pre` → `main`)
**sin fusionarlo a propósito**. Todo lo que sigue requiere cosas que esa
sesión no tenía: tu navegador, tus credenciales FTP, tu `.db` local.

---

## 1. Ya hecho (verificado en la sesión del 2026-07-30, no hace falta repetirlo)

- [x] **Fusión de las 4 ramas `claude/*` sueltas** (`project-roadmap-review`,
      `filtrado-candidatas-videos`, `diseño-discreto-sencillo`, más el
      ancestro redundante `bandas-rrss-discos-sync`) + los commits de **M6**
      (accesibilidad + impresión) y **M7** (notificaciones editoriales) que ya
      vivían en `claude/siguiente-que-hacer-pvvxrn`.
- [x] **Fusión de `origin/pre`** en esa misma rama: traía fixes de mapa de otra
      sesión (500 en `/dashboard/ingesta` si falta la migración `008`, dianas
      de clic sin solape, `stroke-width` de SVG, `/mapa` oculto en PRO) que no
      estaban en la rama consolidada. Sin pérdida de nada de ningún lado —
      todos los conflictos fueron colisiones de numeración de secciones en
      `docs/admin-panel.md`, resueltas conservando ambas.
- [x] **Lint** (`php -l`) sobre el árbol completo: limpio.
- [x] **Smoke tests locales**: **82/82 superadas**, mismo procedimiento que
      `.github/workflows/ci.yml` (fixture determinista + servidor embebido +
      `ci_smoke.php`).
- [x] **Push a `pre`** (fast-forward, sin sobrescribir nada) → CI + deploy
      automático a PRE, verificado hasta `completed success`.
- [x] **Smoke remoto contra PRE** (`https://marchasdecristo.jaguerra27.helioho.st`):
      `/health` → `entorno: pre`, `db: ok`; `robots.txt` con `Disallow: /`
      total (correcto, PRE no debe indexarse); cinta de preproducción presente
      en el HTML; `/mapa` → 200 y `/temporada` → 302 (ambas **visibles**,
      confirma que el gate PRE/PRO distingue bien); una ficha de marcha real
      tomada del sitemap (`/marcha/bajo-tus-lagrimas-1`) → 200 con JSON-LD
      `MusicComposition`; `/dashboard/disco/add` sin sesión → 302 a `/login`;
      skip-link de M6 presente en el DOM de portada.
- [x] **PR [#27](https://github.com/jgcoronado/mdc-back/pull/27)** abierto
      (`pre` → `main`), con toda la descripción de qué incluye y qué se
      verificó. **No fusionado a propósito.**
- [x] `docs/roadmap.md` actualizado con el estado real de §4.

**Nota técnica para la próxima sesión que corra smoke tests**: `ci_smoke.php`
está escrito para la fixture determinista de CI, **no** para datos reales.
Correrlo tal cual contra PRE o PRO da ~30 "fallos" que no son bugs: fallan por
diseño porque PRE tiene noindex/robots-disallow global (la fixture no lo
simula) y porque los slugs de la fixture no existen en la BD real. No te
asustes si lo repites y ves esa lista de fallos — son esperados, no una
regresión.

---

## 2. Puedes avanzar tú ahora mismo (sin esperar a nada)

### 2.1 · Validación visual de PRE en el navegador

Entra a `https://marchasdecristo.jaguerra27.helioho.st` y comprueba a ojo lo
que el smoke remoto solo pudo verificar por HTTP:

- Cinta de preproducción visible en todas las páginas.
- El rediseño (tipografía, spacing) se ve como esperabas.
- `/dashboard/disco/add` — alta de disco con portada: sube una imagen de
  prueba, comprueba que se normaliza a PNG y se ve en la ficha.
- `/mapa` — clics en municipios próximos (el caso que motivó los 3 fixes:
  Castilleja de la Cuesta / Tomares en el Aljarafe) aciertan en el municipio
  correcto, no en el vecino.
- `/temporada` — sigue visible y funcionando en PRE (aunque esté oculta en
  producción).
- Accesibilidad de M6: pulsa Tab nada más cargar una página — debe aparecer el
  skip link "Saltar al contenido"; las tablas ordenables (banda/disco)
  responden a Enter/Espacio con el foco en una cabecera.

### 2.2 · Borrar las 4 ramas ya fusionadas del remoto

La sesión en la nube no pudo hacerlo — su proxy git devuelve `403` en
`git push --delete` (solo permite push normal, no borrado de refs). Desde tu
máquina, con permisos normales de escritura en el repo:

```bash
git push origin --delete \
  claude/bandas-rrss-discos-sync-x60kfw \
  claude/diseño-discreto-sencillo-jymud4 \
  claude/filtrado-candidatas-videos-drdd1y \
  claude/project-roadmap-review-yrc7zt
```

Todas están ya fusionadas (la primera era redundante y nunca llegó a
fusionarse, pero es un ancestro estricto de `filtrado-candidatas-videos`, así
que tampoco pierde nada). Después de esto, el remoto queda con solo
`main`, `pre` y `claude/siguiente-que-hacer-pvvxrn` como ramas de trabajo.

---

## 3. Pendiente de que continúes tú con acceso a producción

### 3.1 · Fusionar el PR #27 (tras validar 2.1)

Cuando la validación visual de PRE te convenza:

```bash
# desde GitHub, o:
gh pr merge 27 --merge   # o desde la web, botón "Merge pull request"
```

Esto dispara automáticamente el **deploy a PRO** (con modo mantenimiento
durante el swap, según `deploy.yml`). Tras el deploy, repite el smoke remoto
pero contra producción:

```bash
curl -fsS https://marchasdecristo.com/health
php php/tools/ci_smoke.php https://marchasdecristo.com   # ¡pero recuerda la nota de §1! muchos "fallos" serán falsos positivos por la misma razón (fixture vs datos reales) — además aquí SÍ deben dar 404: /mapa, /mapa/provincia/*, /temporada, /temporada/*
```

En PRO, a diferencia de PRE, `/mapa` y `/temporada` **deben** devolver 404 —
si no lo hacen, algo va mal con el gate de entorno.

### 3.2 · OPS-01 — Migración `008` + importar candidatos de streaming

Depende de que 3.1 esté hecho. Se hace **en tu máquina local**, nunca sobre
PRE (PRE comparte la BD de producción y es de solo lectura). Pasos, en orden:

```bash
# 1. Migración (idempotente, aplica app/tools/sql/*.sql en orden + añade
#    columnas FUENTE/FUENTE_ALBUM/FUENTE_ALBUM_URL/P_ESTILO a ingest_candidato)
DB_PATH=php/data/mdc.db php php/app/tools/migrate_ingest.php

# 2. Descubrir candidatos desde el catálogo de streaming de las bandas
#    (usa SPOTIFY_CLIENT_ID/SPOTIFY_CLIENT_SECRET del .env si los tienes;
#    sin ellos sigue funcionando con Deezer/Apple, avisando)
python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db

# 3. Importar el NDJSON generado a ingest_candidato (upsert, respeta lo ya
#    revisado, salta lo vetado)
php php/app/tools/import_candidatos.php tools/music_links/out/candidatos.ndjson

# 4. Revisar en el panel: /dashboard/ingesta (rol admin) — aceptar/descartar
#    uno a uno, con veto y deshacer disponibles.

# 5. Cuando tengas el .db local en el estado que quieres publicar, subirlo a
#    producción (con backup+checksum+rollback automático incluidos):
php scripts/sync_db_to_prod.php --dry-run    # primero en seco, para ver qué haría
php scripts/sync_db_to_prod.php              # si el dry-run te convence
```

`sync_db_to_prod.php` exige un backup de producción con menos de 10 días en
`private/backups/` — si no lo hay, para sin tocar nada y te lo dice.

### 3.3 · OPS-02 — `seed_dedicatorias.php` en producción

Pendiente desde el 2026-07-23, independiente de B-01. Vía Plesk:
**Scheduled Tasks → "Run a PHP script"**, seleccionando **PHP 8.4
explícitamente** (el resto del cron ya usa `/usr/local/bin/php8.4`, comprueba
que este script no herede el 7.x por defecto del panel), apuntando a
`app/tools/seed_dedicatorias.php`. Es idempotente (solo inserta pares
variante→canónica que aún no existan), así que no hay riesgo de repetirlo.

### 3.4 · M7 en producción — activar notificaciones editoriales

Añade a tu `config.local.php` de producción (y de PRE si quieres probarlo
ahí primero):

```php
'mail_from'      => 'noreply@marchasdecristo.com',
'mail_from_name' => 'Marchas de Cristo',
'mail_admin_to'  => 'tu-email@ejemplo.com',        // destino del digest semanal
'notif_emails'   => [
    'nombreusuario' => 'editor@ejemplo.com',        // uno por cada editor con email conocido
],
```

Y en Plesk → Scheduled Tasks, añade el digest semanal (PHP 8.4 explícito):

```
0 8 * * 1 /usr/local/bin/php8.4 /home/USUARIO/app/tools/digest_semanal.php
```

(lunes 08:00 — cuenta pendientes de propuestas + candidatos de ingesta +
enlaces por verificar, y envía un resumen HTML a `mail_admin_to`).

---

## 4. Qué necesito confirmar de ti para poder seguir con precisión

1. **¿Validaste PRE en el navegador (§2.1)?** Es la señal para fusionar el
   PR #27.
2. **R-01 (capturar ISRC)**: ¿lo desarrollamos ya, o esperamos a que OPS-01
   (migración + primera importación real) esté cerrado primero? Tiene más
   sentido hacerlo *antes* de curar los 616 candidatos a mano, para no
   repetir trabajo — pero es tu decisión de secuencia.
3. **Orden de P1–P2 en `roadmap.md`**: ¿te sigue sirviendo tal como está, o
   prefieres reordenar algo ahora que B-01 está prácticamente cerrado?

---

## Cómo cerrar este documento

Cuando todos los ítems de §2 y §3 estén resueltos (tachados con fecha) y no
queden preguntas abiertas en §4, este fichero se archiva: muévelo a
`docs/archive/` (o pide que lo haga una sesión), y `docs/roadmap.md` vuelve a
ser la única fuente de estado. No lo dejes creciendo indefinidamente como un
tracker paralelo — es una lista de arranque, no el plan.
