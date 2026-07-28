# Deuda técnica — marchasdecristo.com

> Última actualización: 2026-07-27 (auditoría documental: 3.1 y 3.2 resueltos desde el origen del documento, no eran deuda real)
> La auditoría de la BD vive en [db-analysis.md](db-analysis.md). El análisis del panel en [admin-panel.md](admin-panel.md). El plan priorizado de mejoras (no solo deuda) vive en [consejo-de-sabios-2026-07.md](consejo-de-sabios-2026-07.md) y su estado de ejecución en [roadmap.md](roadmap.md).

## Resumen ejecutivo

| Categoría | Items abiertos | Severidad máxima |
|-----------|-----------------|-------------------|
| Operativa / observabilidad | 0 | — |
| Deploy | 1 | 🟡 Media |
| Calidad de código PHP | 0 | — |
| Base de datos (SQLite) | 1 | 🟢 Baja |
| Panel de administración | 1 | 🟢 Baja |

**Contexto**: desde el consejo de sabios (2026-07-12) se han cerrado las 8
tareas de corto plazo — incluyendo CI (C5), el endurecimiento del sync (C7) y
la monitorización externa (C6), que resolvían la mayor parte de la deuda
operativa crítica que tenía el proyecto en ese momento. Lo que queda aquí es
deuda real vigente, no un resumen del informe del consejo (ver ese documento
para el plan de mejora completo, que incluye trabajo de producto y SEO
además de deuda).

---

## 1. Operativa / observabilidad

### ~~1.1 Sin monitorización externa de uptime~~ ✅ Resuelto (C6, 2026-07-16)
- Monitor UptimeRobot activo sobre `https://marchasdecristo.com/health`
  (keyword `db: ok`, 5 min, alerta por email). Detalle completo, runbook y
  falsas alarmas esperadas (modo mantenimiento, desfase deploy/monitor) en
  [monitoring.md](monitoring.md). `/health` se amplió para exponer un
  chequeo de BD también a visitantes anónimos (antes solo con sesión admin),
  necesario para que el monitor externo cubra caídas de datos y no solo de
  proceso PHP.

### ~~1.2 CI verifica, pero no despliega ni alerta si producción diverge~~ ✅ Resuelto (M5, 2026-07-16; entorno PRE reintroducido el 2026-07-28)
- Pipeline en `.github/workflows/deploy.yml`: push a `pre` → CI (`verify`) →
  despliegue automático a **preproducción**; fusión de `pre` en `main` →
  despliegue automático a **producción**, con modo mantenimiento durante el
  mirror FTP. Los dos terminan en un smoke remoto con datos reales
  (`php/tools/smoke_remote.php`). PRE se aísla sin tocar Plesk mediante
  `env.php`, que desvía `APP_DIR` a `pre/app`. El **sync de BD** sigue siendo
  manual **a propósito**
  (`sync_db_to_prod.php`) — datos y código separados, la maestra es la local.

---

## 2. Deploy

### 2.1 Sin verificación de integridad periódica del backup 🟡
- `app/tools/backup.php` genera el backup (`VACUUM INTO` + retención), pero no
  comprueba que el fichero resultante sea íntegro más allá de que la copia
  termine sin excepción.
- **Fix**: añadir `PRAGMA integrity_check` sobre el backup recién creado y
  avisar (log o email) si falla. Además, hoy el backup vive en el mismo host
  que el `.db` — una copia externa (rclone/GitHub Action hacia almacenamiento
  gratuito) mitigaría un fallo del hosting completo. Ambos puntos están en el
  catálogo de automatizaciones del consejo (§8, "Para el administrador"),
  todavía sin issue propio.

---

## 3. Calidad del código PHP

### ~~3.1 Autoload manual sin PSR-4 ni gestor de paquetes~~ ✅ No era deuda real (verificado 2026-07-27)
- Descripción errónea desde el origen del documento: `bootstrap.php` ya
  registra un autoload PSR-4 mínimo por convención de directorio
  (`spl_autoload_register`, `App\Foo\Bar` → `src/Foo/Bar.php`), exactamente
  el "fix" que este ítem proponía. No hay ningún mapa clase→fichero explícito
  en el repo. Ver `docs/architecture.md` ADR-001 (ya corregido).

### ~~3.2 Rate limiting de login persistido a fichero, sin purga automática~~ ✅ No era deuda real (verificado 2026-07-27)
- Descripción errónea desde el origen del documento: `Auth::rateFail()` ya
  poda las entradas cuya ventana/bloqueo han expirado en cada escritura
  (comentario "Poda de entradas viejas" en el propio código). El fichero no
  crece sin límite.

---

## 4. Base de datos (SQLite)

### 4.1 Tablas heredadas sin revisar tras el cutover 🟢
- El esquema conserva columnas/tablas de la era MySQL (p. ej. sentinelas
  numéricos como `BANDA_ESTRENO = 0` en vez de `NULL`, documentados en
  [db-analysis.md](db-analysis.md)) que no se han limpiado porque no bloquean
  nada funcionalmente.
- **Fix**: revisar `db-analysis.md` tras el cutover y decidir qué se normaliza
  ahora que SQLite (y no MySQL) es el motor definitivo. Baja prioridad — no
  hay corrupción de datos, solo aspereza del esquema.

---

## 5. Panel de administración

### 5.1 Gestión de discos ausente ✅ resuelto (2026-07)
- Implementado: `/dashboard/disco/add` (alta con subida de portada) y
  `/dashboard/disco/{id}` (datos, portada, pistas y vista previa). La marcha se
  busca por identificador o por título, y el número de pista no tiene por qué
  ser consecutivo. Detalle y decisiones en
  [admin-panel.md §11](admin-panel.md).

---

## Ítems verificados como ya resueltos (no confundir con deuda abierta)

Para que una sesión nueva no reabra trabajo ya hecho:

- **Botonera de streaming en fichas públicas**: `Html::streaming()` está
  invocado en los tres templates de detalle (`marcha_detail.php`,
  `banda_detail.php`, `disco_detail.php`) — verificado en el código actual
  (2026-07-16), no solo en un issue cerrado.
- **Checksum + rollback + modo mantenimiento en el sync**: implementado en
  `scripts/sync_db_to_prod.php` (C7, [issue #13](https://github.com/jgcoronado/mdc-back/issues/13)).
- **CI con smoke tests**: `.github/workflows/ci.yml` + `php/tools/ci_fixture.php`
  + `php/tools/ci_smoke.php` (81 aserciones y creciendo — no fiarse de un
  número fijo) en cada push/PR (C5,
  [issue #11](https://github.com/jgcoronado/mdc-back/issues/11)).
- **Hubs SEO, `og:image`/Twitter Card, `lastmod`+IndexNow, marcha del día**:
  C1–C4, todos cerrados — ver [roadmap.md](roadmap.md) para el estado
  completo de las tareas de corto plazo del consejo.

## Cómo mantener este documento

- Un hallazgo nuevo → añadirlo aquí con severidad (🔴🟠🟡🟢) y un fix propuesto.
- Al resolver un ítem → táchalo con `~~texto~~` y una nota de fecha/commit, o
  muévelo a la sección de verificados si conviene documentar explícitamente
  que ya no hay que buscarlo. No lo borres sin más: el historial de qué se
  resolvió y cuándo es parte del valor de este documento.
- Deuda que en realidad es una mejora de producto/SEO (no un bug ni un
  riesgo) va en `consejo-de-sabios-2026-07.md`/`roadmap.md`, no aquí.
