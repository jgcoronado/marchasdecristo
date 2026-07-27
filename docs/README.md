# Documentación — marchasdecristo.com

Esta carpeta contiene la documentación viva del proyecto, escrita para que cualquier sesión nueva (humano o asistente) pueda entender la aplicación sin tener que leer el código entero.

El proyecto corre en **PHP 8.4 + SQLite sobre HelioHost** desde el cutover del
2026-07-04. Si vienes de una sesión antigua o del historial de git y ves
referencias a Next.js/VPS/MySQL, eso es el stack **anterior**, desmantelado —
ver [context.md](context.md) §7 y [archive/](archive/).

## Índice

1. **[context.md](context.md)** — Punto de entrada. Visión general, stack real (PHP/SQLite/HelioHost), estructura de carpetas, configuración, flujo de propuestas y sync, convenciones.
2. **[architecture.md](architecture.md)** — Diagrama de componentes, flujos de petición, ADRs (decisiones arquitectónicas registradas), patrones vigentes.
3. **[technical-debt.md](technical-debt.md)** — Deuda técnica real y priorizada del stack actual.
4. **[roadmap.md](roadmap.md)** — Estado de ejecución del plan de acción vigente (apunta a `consejo-de-sabios-2026-07.md`).
5. **[consejo-de-sabios-2026-07.md](consejo-de-sabios-2026-07.md)** — Evaluación integral del proyecto (DAFO de UX/UI/QA/desarrollo/producto, veredicto de síntesis, plan de acción corto/medio/largo plazo con coste/repercusión, catálogo de automatizaciones). Documento de referencia para el roadmap.
6. **[db-analysis.md](db-analysis.md)** — Auditoría del esquema SQLite (actualizado 2026-06-05; el análisis MySQL original es histórico).
7. **[admin-panel.md](admin-panel.md)** — Panel de administración en PHP: acceso, roles/capacidades, funcionalidades, más las secciones específicas de linaje de bandas, curación de estilo, selector de municipio y scripts de herramientas.
8. **[pendientes-post-cutover.md](pendientes-post-cutover.md)** — Lo que queda del cierre operativo del cutover: cron de backup por confirmar, correo del dominio, desmantelar el VPS, limpieza. (La verificación del panel y Search Console ya están resueltas.)
9. **[cutover-fase5.md](cutover-fase5.md)** — Runbook ejecutado del cutover DNS a PHP.
10. **[monitoring.md](monitoring.md)** — Monitorización externa de uptime (UptimeRobot sobre `/health`): configuración, qué cubre y qué no, falsas alarmas esperadas, runbook.
11. **[entornos.md](entornos.md)** — Despliegue a producción: entornos local y PRO, pipeline (push a `main` → CI; deploy a PRO manual con mantenimiento + smoke remoto), puesta en marcha y limitaciones.
12. **[plan-music-apps.md](plan-music-apps.md)** / **[youtube-canales-bandas.md](youtube-canales-bandas.md)** — Notas de trabajo sobre ingesta de audio (YouTube) y enlaces de streaming.
13. **[archive/](archive/)** — Documentos históricos del stack Next.js/VPS/MySQL, ya desmantelado. Conservados por su razonamiento, no como referencia vigente: [redesign-options.md](archive/redesign-options.md), [vps-migration-3b.md](archive/vps-migration-3b.md).
14. **[ux-analysis-estado.md](ux-analysis-estado.md)** — Estado y memoria del análisis UX comparativo con patrimoniomusical.com: plan de 6 prioridades, **las 6 completadas**, y las decisiones de arquitectura clave por si se retoma esta zona. El log narrativo completo vive en `../ANALISIS_UX.md`.

Además, `../php/README.md` documenta el desarrollo local y el deploy por FTP con más detalle operativo del día a día que `context.md`.

## Cómo mantener estos docs

- Si cambias arquitectura → actualiza `architecture.md` (sección de ADRs si es una decisión nueva).
- Si encuentras un bug, riesgo u obsolescencia → añádelo a `technical-debt.md`.
- Si avanzas una tarea del plan del consejo (C/M/L) → actualiza su estado en `roadmap.md`. No reescribas `consejo-de-sabios-2026-07.md`: es una fotografía puntual, no un tracker.
- Si añades o cambias funcionalidad del panel admin → actualiza `admin-panel.md`.
- Si cambias el schema de BD → actualiza `db-analysis.md`.
- El resto (`context.md`, `archive/`) cambia menos.

## Convenciones de los docs

- Idioma: español (consistente con el código y los commits).
- Citas a archivos: con formato `[ruta:linea](../ruta#Llinea)` para que sean clickables desde IDE.
- Severidades: 🔴 Crítica, 🟠 Alta, 🟡 Media, 🟢 Baja.
- Generación: cada doc lleva una línea `> Última actualización: YYYY-MM-DD` (o `> Generado:`) al inicio.
