# Opciones de rediseño profundo

> **📦 Archivado 2026-07-16**: documento histórico. La decisión que aquí se evaluó
> (migrar de Node/VPS a un stack más simple) ya se tomó y ejecutó: el proyecto
> corre en PHP 8.4 + SQLite sobre HelioHost desde el cutover del 2026-07-04. Ver
> [../context.md](../context.md) para el stack actual. Se conserva por el
> razonamiento de la decisión, no como referencia de arquitectura vigente.

> Generado: 2026-06-01
> Pregunta de partida: ¿conviene cambiar arquitectura, lenguaje, base de datos u hosting para optimizar el funcionamiento?
> Contexto fijado por el mantenedor: tráfico **bajo**, VPS **no se amplía**, app es **side-project** con un solo mantenedor.

## TL;DR

| Decisión | Veredicto |
|----------|-----------|
| Cambiar de **Node.js** a Go/Rust/etc. | **No.** Sin ROI. Lo que mata aquí no es el lenguaje. |
| Cambiar de **Express** a Fastify/Hono | **Solo si** se migra todo a Next.js (RSC + API routes). No por rendimiento. |
| Cambiar de **MySQL** a Postgres | **No.** Coste de migración > beneficio. |
| Cambiar de **MySQL** a **SQLite** embebido | **Sí, evaluar seriamente.** Para 4 mil filas es ideal. |
| Cambiar de **MySQL externo** a **MySQL en Docker** | **Sí, recomendado.** Elimina dependencia de helioho.st. |
| Cambiar de **VPS** a **CDN/Vercel/Cloudflare** | **No** en este modelo (admin requiere servidor). **Sí** si se va a build estático. |
| **Build estático** (SSG con redeploy nocturno) | **Sí, mejor opción para el público.** El admin se queda en VPS. |
| **Unificar todo en Next.js** (admin + público) | **Sí.** Decidido en sesión 2026-06-01. |

El cuello de botella real no es ni el rendimiento ni el lenguaje: es **complejidad operativa** (3 procesos, 2 frameworks, BD externa, código muerto).

## 1. ¿Cambiar de lenguaje?

### Hipótesis: migrar Node 22 a Go o Rust

**Argumentos a favor:**
- Menor consumo de RAM (importante: VPS de 1 GB).
- Compilado, sin GC pauses.
- Binarios estáticos, contenedores diminutos (10-30 MB vs 200 MB de Node).

**Argumentos en contra (más fuertes aquí):**
- Reescribir ~20 endpoints SQL no es trivial: 1-2 semanas de trabajo.
- Pierdes el ecosistema (mysql2, express middleware, ergonomía JS).
- Next.js es Node por definición. Tendrías que mantener dos lenguajes.
- El consumo real de RAM lo dictan **Next.js** (~250 MB) y **MySQL** (~150 MB), no Express (~50 MB).
- Tráfico bajo: 50 ms vs 5 ms por petición es irrelevante.

**Veredicto: NO.** Solo tendría sentido si:
- a) escalases a miles de requests/segundo, o
- b) ya no usaras Next.js (y solo tuvieras una API que sirviera JSON).

## 2. ¿Cambiar de framework HTTP?

### Express 5 → Fastify / Hono / Bun

- **Express 5**: ya estás en la rama moderna (no la 4 legacy). Soporta `async/await` nativo, middleware decente.
- **Fastify**: 2-3× más throughput, schema validation incorporada. Migración: ~1 día. **Recomendable si te importa la validación**.
- **Hono**: ultraligero, perfecto si despliegas en edge. Aquí no aplica.
- **Bun**: runtime alternativo. Compatible con casi todo Express. Beneficio: arranque más rápido, menos RAM. **Riesgo**: dependencias nativas (mysql2) a veces dan problemas.

**Veredicto: NO cambiar de Express por sí solo.** Si se hace la migración a Next.js (recomendada), las API routes de Next.js reemplazan Express y el debate se vuelve irrelevante.

## 3. ¿Cambiar de base de datos?

### Opción A — Mantener MySQL externo (status quo)
- ✅ Cero migración.
- ❌ Dependencia con helioho.st. Si cierran, te quedas sin BD.
- ❌ Credenciales en `.env`, latencia de red, expuesta al exterior.
- ❌ MyISAM (sin transacciones, sin FKs).

### Opción B — MySQL en Docker en el VPS
- ✅ Sin dependencia externa.
- ✅ La red es la del bridge Docker (latencia <1 ms).
- ✅ Versión moderna (MySQL 8 / MariaDB 11).
- ❌ Consume ~150 MB de RAM (en un VPS de 1 GB es notable).
- ❌ Hay que backupear (mysqldump cron).
- **Migración**: `mysqldump` desde helioho.st + `mysql < dump.sql` en el contenedor. Una tarde de trabajo.

### Opción C — Postgres en Docker
- ✅ FKs, transacciones, JSON, FULLTEXT moderno (`tsvector`), constraints CHECK, etc.
- ✅ Cultura SQL más moderna.
- ❌ Reescribir queries: `MATCH AGAINST` → `to_tsvector @@ plainto_tsquery`, `GROUP_CONCAT` → `string_agg`, `LIMIT n,m` → `LIMIT n OFFSET m`, etc. **1 semana de trabajo**.
- ❌ Mismo problema de RAM que MySQL.

### Opción D — **SQLite embebido**
- ✅ Cero proceso adicional, cero RAM extra (~5 MB del lib en el binario Node).
- ✅ Backups triviales: copiar el archivo `.db`.
- ✅ FULLTEXT5 (`fts5`) excelente para español con `unicode61` + `remove_diacritics=2`.
- ✅ Para una BD read-mostly con 10 000 filas y baja escritura: **óptimo**.
- ✅ Con `better-sqlite3` y `WAL mode`, lecturas concurrentes ilimitadas, ~30 µs por query.
- ❌ Reescribir queries: similar a Postgres (sintaxis SQL muy parecida). ~3-4 días.
- ❌ Para escritura concurrente intensa no es ideal (no es tu caso: 1-2 admin máximo).
- ❌ Pierdes la capacidad de "consultar la BD desde otra máquina" (es un archivo en el VPS).

### Comparativa

| Aspecto | MySQL ext (hoy) | MySQL Docker | Postgres | SQLite |
|---------|-----------------|--------------|----------|--------|
| RAM extra | 0 (externo) | ~150 MB | ~150 MB | ~5 MB |
| Latencia query | 5-20 ms | <1 ms | <1 ms | <0.1 ms |
| Coste migración | — | 2-4 h | 1 semana | 3-4 días |
| Backups | manual ext | cron mysqldump | cron pg_dump | `cp archivo.db` |
| FULLTEXT español | OK | OK | excelente (tsvector) | excelente (fts5) |
| Transacciones | parciales (MyISAM) | sí | sí | sí |
| Riesgo proveedor | alto | bajo | bajo | nulo |

**Recomendación**:
1. **A corto plazo**: migrar de helioho.st a **MySQL/MariaDB en Docker** (1 tarde, mantiene queries existentes).
2. **A medio plazo si te apetece**: evaluar **SQLite** — encaja como un guante con tu volumen y patrón de uso, y simplifica enormemente la operativa.

## 4. ¿Cambiar de hosting?

### Opción A — VPS actual (status quo)
- 5 €/mes. Total control. 1 vCPU / 1 GB RAM / 15 GB disco.
- Apretado: con Next.js + Express + (futuro) MySQL es justito. Hoy ya hay swap activo durante builds.

### Opción B — Vercel / Cloudflare Pages
- Ideal para Next.js puro (deploy automático desde git push).
- Build estático: gratis ilimitado. SSR con ISR: gratis hasta cierto límite, después de pago.
- **Problema**: tu admin escribe en MySQL. Vercel no puede tener MySQL embebido. Necesitas:
  - O bien BD externa (PlanetScale, Neon, Supabase) — añade costes y otro vendor.
  - O bien admin en otro lado (el VPS) y público en Vercel — más complejidad.

### Opción C — Build estático (HTML pre-renderizado)
- Generar el sitio entero como HTML estático con un cron nocturno (o hook tras edición admin).
- Subir a **Cloudflare Pages** o **Netlify**: CDN global, gratis ilimitado.
- El admin sigue en el VPS escribiendo en MySQL.
- Tras editar: el admin dispara `next build && rsync` al CDN.
- **Ventaja**: público ultra-rápido y barato; VPS solo necesita levantar Express + MySQL (RAM holgada).
- **Coste**: añadir paso de build + sincronización (~5 min cada redeploy).

### Comparativa

| Aspecto | VPS hoy | Vercel | Estático + CDN |
|---------|---------|--------|----------------|
| Coste | 5 €/mes | 0 (con tier free) | 0 (con tier free) |
| Latencia pública | ~50 ms (Europa) | ~10 ms (edge) | ~5 ms (edge) |
| Complejidad | media | baja para Next.js | media |
| Admin escribiendo | sencillo | requiere BD externa | sigue en VPS |
| Riesgo vendor | bajo | medio (tier free puede cambiar) | bajo |

**Recomendación**:
- Si el VPS aguanta (lo hace), **quedarse en el VPS**.
- Si quieres explorar mejora real de latencia pública: build estático nocturno a Cloudflare Pages, manteniendo VPS para admin.

## 5. ¿Cambiar de arquitectura?

### Opción A — Status quo (Express + Next.js + Vue admin)
- 3 procesos, 2 frameworks. Funciona pero confunde.

### Opción B — Unificar todo en Next.js (decidido en sesión 2026-06-01)
- Eliminar `frontend/` (Vue admin).
- Llevar admin a `nextjs/app/admin/` con Server Actions y/o Route Handlers protegidos por middleware de auth.
- Eliminar el contenedor `mdc-app` (Express).
- Las queries SQL viven en `nextjs/lib/db.ts` (módulo server-only).
- **Ventajas**: un solo proceso, un framework, un build, un lenguaje de configuración, un modelo de routing, validación tipada compartida cliente ↔ server.
- **Trabajo**: 2-3 días.
- **Riesgo**: Next.js no es tan ligero como Express puro (~250 MB vs 50 MB). En 1 GB RAM justa es un argumento.

### Opción C — Build estático + admin en otro proceso pequeño
- Público es 100% HTML estático generado periódicamente.
- Admin es un binario Go pequeño (50 MB RAM) sirviendo `/admin` y `/api/admin`.
- Más fragmentado pero ultraligero.

### Opción D — Volver a SPA pura (deshacer Next.js)
- Recuperar el Vue público (que ya existe en `frontend/`) y servir todo por Express.
- ❌ **Mata el SEO** (la razón de la migración fue exactamente esa). Descartado.

### Recomendación: **Opción B** (unificación en Next.js)
- Decisión ya tomada en sesión 2026-06-01.
- Detalle de implementación: ver el roadmap de la época en el historial de git
  (`git log -p -- docs/roadmap.md`) — el roadmap vigente ya no describe fases Next.js.

## 6. Otras tecnologías a considerar

### 6.1 Tipado en el backend
Hoy el backend es JS puro sin tipos. Migrar `src/` a TypeScript (o usar JSDoc tipado) ayudaría a evitar bugs como el `db.connection.query` (un compiler hubiera avisado).
- Coste: 1 día.
- **Recomendado** si se mantiene Express. **Trivial** si se unifica en Next.js (ya es TS).

### 6.2 ORM o query builder
- **Prisma**: bonito pero pesado, mata startup time. Sobredimensionado para 10 endpoints.
- **Drizzle**: TypeScript-first, ligero, queries tipadas. Pega bien con la migración a TS.
- **Kysely**: query builder puro, sin ORM. Aún más ligero.
- **Mantener SQL crudo**: factible, pero pierdes refactoring seguro.

**Recomendación**: si se hace TS, evaluar **Drizzle** o **Kysely** para mejorar mantenibilidad. No por rendimiento.

### 6.3 Validación de input
- **Zod** es el estándar de facto en TS. Encaja perfecto en Next.js Server Actions / Route Handlers.
- Hoy la validación es ad-hoc (`if (!username || username.length > 120)`). Zod centraliza esto y emite errores 400 consistentes.

### 6.4 Caché en memoria
- Para 4 200 marchas, podrías tener el catálogo entero en RAM como un Map.
- Carga inicial al arrancar (~1 segundo), reload cada 5 min o tras admin write.
- Búsquedas en JS puro (filter + sort), <1 ms.
- **Solo merece la pena** si abandonas SQLite/MySQL. Innecesario si tienes SQLite (ya es así de rápido).

### 6.5 Testing
- **Vitest** para unit/integración (rápido, ESM-native).
- **Supertest** para HTTP del backend.
- **Playwright** para e2e (golden paths del público).
- Mínimo viable: 20 tests cubriendo:
  - Login flow (login OK, login fallo, rate limit).
  - GET de cada entidad por ID existente y no existente.
  - Buscador con y sin filtros (incluyendo el caso vacío que hoy crashea).
  - editMarcha con allowlist (rechaza claves no permitidas).
  - Render de detalle con metadata SEO.

## 7. Veredicto final

**Quedan vigentes 4 cambios merecidos por su ratio coste/beneficio:**

1. **Unificación en Next.js** (decidido).
2. **MySQL en Docker** en lugar de helioho.st (1 tarde, elimina riesgo vendor).
3. **TypeScript en el backend** (1 día, evita bugs de tipos).
4. **Validación con Zod** (medio día, centraliza errores 400).

**Cambios opcionales con buen ROI si te interesa:**

5. **Drizzle/Kysely** como query builder tipado (medio día más).
6. **SQLite** en vez de MySQL (3-4 días, ultra-simplifica operativa).
7. **Build estático** + Cloudflare Pages para público (1 día, abarata latencia).

**Cambios desaconsejados:**

- Migrar a Go/Rust.
- Migrar a Postgres.
- Vercel sin replanteamiento de BD.
- ORMs pesados como Prisma.

El secreto está en que el sistema **ya funciona razonablemente bien para su tamaño**. La mayor ganancia no viene de cambiar piezas, sino de **eliminar complejidad** (un framework, un proceso, sin código muerto, sin BD externa).
