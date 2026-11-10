# Auditoría integral — 2026-11-10

> Generado: 2026-11-10 · rama `pre`, commit `9aa5a9a` · faltan 131 días para el Domingo de Ramos (21-03-2027)
>
> Fotografía puntual, no tracker. Lo que se decida ejecutar pasa a [roadmap.md](roadmap.md).
> Versión con capturas y maquetas: artifact «Auditoría Marchas de Cristo» (enlace en la sesión que la generó).

**Método.** Siete agentes Opus 5.5 en esfuerzo bajo (seguridad, bugs de la parte pública, panel y flujo de datos, base de datos, operación, SEO/rendimiento/accesibilidad medidos, documentación y producto). Diseño/UX, verificación cruzada y redacción en la sesión orquestadora, mirando capturas reales del sitio en local (390 y 1280 px, claro y oscuro). Todo en solo lectura: el repo y la BD maestra no se tocaron; a producción se hicieron 24 GET anónimos.

**Confianza.** ✔ = reproducido o comprobado a mano por el orquestador · ◐ = leído en código por un agente · ? = hipótesis.

---

## 1. Veredicto

1. **Ingeniería sólida.** 7.847 URLs del sitemap responden 200 sin redirecciones; `integrity_check` y `foreign_key_check` limpios; sin inyección SQL ni XSS encontrados; CSRF y permisos correctos en todos los POST del panel.
2. **Los datos viven en una sola máquina.** La BD maestra está en el portátil sin copia propia, y las copias de producción no salen de HelioHost. Es el riesgo más grave y el más barato de cerrar.
3. **El diseño es correcto y anónimo.** Tres rondas de revisión pulieron contraste y tokens; ninguna decidió qué es el sitio.
4. **La subida de datos puede perder cambios** (sync sin instantánea ni integridad; propuestas no idempotentes).
5. **La documentación ya no describe el proyecto** (8.400 líneas; `context.md` contradice al código en despliegue, mapa y recuentos).

### Foto de hoy

| Entidad | Filas |
|---|---|
| Marchas | 5.438 (3.450 AM / 1.988 CCTT) |
| Compositores | 957 |
| Bandas | 360 |
| Discos | 449 (4.665 pistas) |
| Dedicatorias | 1.405 (1.528 alias) |
| Acompañamientos (contratos) | 6.606, años 1980–2026 |
| Enlaces de streaming | 5.596 |
| Cola de ingesta | 290 pendientes (meta «<400» cumplida) |
| Usuarios | 6 (1 admin, 5 editores) |

Ritmo: 185 commits desde el 1 de agosto (≈34 % producto público, ≈25 % acompañamientos/nómina, ≈15 % ingesta y datos, ≈11 % panel/seguridad, ≈7 % calidad y docs).

---

## 2. Diseño y UX

### 2.1 Diagnóstico: por qué se ve genérico

La cabecera de `app.css` declara el criterio: «discreto, sencillo y cómodo de leer». La revisión de julio retiró la textura; las siguientes midieron contrastes y unificaron tokens. Cada ronda quitó cosas y ninguna puso algo propio. El problema no es el color (cambiado tres veces): es la estructura.

| # | Problema | Evidencia |
|---|---|---|
| 1 | **Las cuatro fichas son la misma plantilla**: tarjeta, título, tabla clave-valor cebrada, más tablas. Tapando el título no se sabe si es marcha, autor, banda o disco. | ✔ capturas de las 4 fichas |
| 2 | **La ficha de marcha no tiene jerarquía**: compositor, año, estilo y duración pesan igual. «Véase también» repite los enlaces de la tabla superior. | ✔ `marcha_detail.php` |
| 3 | **Escuchar es el tercer bloque y son cuatro píldoras de 13 px.** La home incrusta el vídeo de la marcha del día; la ficha de esa misma marcha no tiene reproductor. | ✔ `grep ytfacade` solo da `home.php` |
| 4 | **No hay imágenes.** El listado de discos es una tabla de texto aunque las portadas existen (`/cover/{id}.webp`, 200 px). Bandas y autores no tienen campo de foto ni escudo. | ✔ `disco_list.php`, esquema |
| 5 | **Dos marcas según el tema**: índigo sobre papel en claro, salmón sobre negro en oscuro. Sin logotipo ni elemento gráfico propio; el nombre va en IBM Plex Serif. | ✔ `app.css` / `dark.css` |
| 6 | **En móvil el contenido empieza muy abajo.** Home a 390 px: la marcha del día asoma al 81 % del primer pantallazo. Listado: la primera fila empieza a media pantalla, tras orden, página, búsqueda avanzada y filtros. | ✔ capturas 390×844 |
| 7 | **La home no invita a explorar.** El h1 es un saludo, el texto se autoelogia, «Explorar el catálogo» son enlaces derivados de la marcha del día y las «últimas incorporaciones» son el último lote de ingesta (hoy 4 de 5 de la misma banda). Nada de temporada. | ✔ `home.php` |
| 8 | **Muro de enlaces azules** en las tablas largas (ficha de autor: 119 filas, dos columnas enlazadas). | ✔ captura |
| 9 | **La marca salta 176 px** entre home/ficha y listados. El comentario dice que la cabecera va siempre a `--wrap-ancho`; la regla real es `.header-inner { max-width: var(--wrap) }` (`app.css:320`). Es el B1 de la revisión de septiembre, aún abierto. | ✔ |
| 10 | **Ficha de disco desalineada**: título, tabla y «Contenido» arrancan en 271, 308 y 255 px cuando no hay portada. | ✔ captura |
| 11 | **Escala sin sistema**: 39 tamaños de letra, 65 espaciados, 8 breakpoints (los colores sí son sistema: 0 hex fuera de tokens). h2 de 20 px frente a h1 de 32. | ◐ inventario del CSS |
| 12 | **Vocabulario de base de datos**: «registros», «M-201», «permalink:», «s/f», «Refinar por». | ✔ |

Menores: píldoras de streaming con «·» que parece un icono roto; hover de marca sin contraste (Tidal 1,24:1, Spotify 2,56:1 en claro); atajo «/» visible en el buscador táctil; botón de tema de 36 px; 5.ª cifra huérfana en la rejilla de la home en móvil.

**Por qué los intentos anteriores no bastaron.** Todas las revisiones documentadas buscaron defectos medibles y los corrigieron. Así no se llega a una identidad, porque una identidad es una decisión, no un defecto. El prototipo de ficha de julio no se llevó entero a producción, y la validación fue `ci_smoke` más una lista de anchos, nunca una captura mirada como visitante.

### 2.2 Propuesta: una dirección

- **Un color de marca común a los dos temas**: grana de terciopelo en la cabecera con filete dorado (banderines y cordones de las bandas). El resto, papel casi blanco y tinta.
- **El estilo como señal visual**: etiqueta de color fija (grana CCTT, azul noche AM) en fichas, listados y resultados. Es el eje del catálogo y hoy es una fila más.
- **Títulos de obra en cursiva de imprenta** (Caslon o similar, autoalojada), como en programas de mano y carátulas. Texto en sans neutra.
- **Frases en vez de tablas**: «De X y Y. Estrenada por Z. Dedicada a W.»
- **Escuchar primero**: fachada de reproductor bajo el título (la de la home) y botones de servicio de tamaño táctil.
- **Cada entidad con su forma**: disco con portada grande; banda con línea de tiempo y acompañamientos del año arriba; compositor con obra por décadas.
- **Estado vacío de escucha diseñado**: el 43 % de las marchas no tiene audio; ese hueco debe pedir la grabación («propón un enlace»), no quedar en blanco (enlaza con R-06 y R-08 del roadmap).

### 2.3 Orden de implantación

| Paso | Contenido | Esfuerzo |
|---|---|---|
| 1 | Cabecera única de ancho fijo (cierra B1), buscador plegado en móvil, color de marca común; escala cerrada (6 tamaños, 8 espaciados, 2 breakpoints) | 1–2 sesiones |
| 2 | Ficha de marcha nueva, primero en móvil; validar en PRE con capturas 390/1280, claro/oscuro | 2–3 sesiones |
| 3 | Disco con portada y listado en rejilla; compositor por décadas; banda con acompañamientos arriba | 2 sesiones |
| 4 | Home (buscador como titular, módulo de temporada, efemérides, discos recientes); listados móviles con filtros en panel y filas de dos líneas | 1–2 sesiones |

**Pendiente del mantenedor:** ¿hay escudo o logotipo?; ¿grana o morado nazareno?; ¿de dónde sacar fotos de bandas y compositores con permiso?

---

## 3. Bugs y riesgos

### Alta

| Hallazgo | Dónde | Arreglo | Conf. |
|---|---|---|---|
| BD maestra sin copia fuera del portátil; backups de PRO en el mismo hosting (retención 60 días) | `php/data/mdc.db`, `app/tools/backup.php` | Copia diaria local con `VACUUM INTO` + subida cifrada externa; probar restauración | ✔ (Time Machine no comprobable) |
| Sync sube el fichero vivo (WAL) sin instantánea, sin `integrity_check`, y con `STOR` directo sobre `private/mdc.db` | `scripts/sync_db_to_prod.php`, `Db.php:86` | `VACUUM INTO` → integridad + FK → subir como `mdc.db.new` → RNFR/RNTO; borrar `-wal`/`-shm` remotos; abortar si no se activa mantenimiento | ✔ |
| Propuesta ya aplicada puede rebajarse y reaplicarse (si falla el RNTO a `recibidas/` o el archivado local tras el commit) | `sync_propuestas_from_prod.php:177-196`, `PropuestaRepo.php:367,509` | Tabla `propuesta_aplicada(id PK)` en la misma transacción; filtrar ids ya archivados al bajar | ◐ |
| Deploy con FTP en claro (`ssl-allow false`) y no atómico; el mantenimiento se quita aunque el mirror falle | `deploy.yml:117,160,176` | FTPS forzado + rotar contraseña; mantenimiento persistente si falla | ✔ |
| `main` y `pre` sin protección de rama; Quality (PHPStan) no bloquea el deploy | GitHub | Proteger `main`: PR + checks CI y Quality | ◐ (`gh api` → 404) |
| Sin runbook de desastre; `cover/` y `config.local.php` solo existen en el servidor | `pendientes-post-cutover.md:78` | `docs/runbook-desastre.md`, ensayado una vez | ◐ |

### Media

| Hallazgo | Dónde | Arreglo | Conf. |
|---|---|---|---|
| Orden alfabético por bytes: tildes, Ñ y minúsculas («de la…») tras la Z; 65 marchas afectadas | `Repo.php:299,699,852,1313,1328,1346` | `ORDER BY NOACC(col) COLLATE NOCASE, col` (función ya registrada) | ✔ |
| Carrera en el rate limit del login (leer-modificar-escribir sin `flock`); el JSON crece con usuarios variados | `Auth.php:396-439` | `flock(LOCK_EX)` sobre el ciclo completo o tabla SQLite; tope de claves | ◐ |
| `/rosario` (PRE) acepta POST anónimos sin tope y permite inyección de fórmulas CSV | `routes.php:124`, `Rosario.php:97-140,245,371` | Token en URL; prefijo `'` en celdas `= + - @`; tope de líneas | ◐ |
| Caché de og:image multiplicable con ids no normalizados (`0001`) | `Og.php:41,65-87` | `(string)(int)$id`, 404 si ≤ 0 | ◐ |
| Operaciones de propuesta que no son de contratos se aplican sin control de concurrencia | `PropuestaOps.php:51-62` | Hash de fila al proponer, comparar al aceptar | ◐ |
| Esquema sin versionar: 21 SQL numerados sin ejecutor ni `user_version` | `php/app/tools/sql/` | Ejecutor mínimo + `PRAGMA user_version` | ◐ |
| JSON-LD: `creator` por `composer`; `performanceLocation` → `MusicGroup`; `tracks` en desuso; `byArtist` → compositor; faltan `sameAs`, `numTracks` | `Seo.php` | Corregir las cuatro plantillas | ◐ |
| 18 `catch (\Throwable)` en el panel convierten cualquier fallo en aviso de UI | `Admin.php:463,791,972,…`, `PropuestaOps.php:418,560` | Helper único que registre y capture solo excepciones de dominio | ◐ |
| Columnas de relación sin FK (`marcha.BANDA_ESTRENO`, `disco.BANDADISCO`, `disco_marcha.DM_BANDA`) — ya hay un huérfano | esquema | Validación en `ci_*`; FK al recrear tabla | ◐ |
| Sin política de privacidad ni aviso legal (hay GoatCounter sin cookies y cookie de login) | plantillas | Página `/privacidad` enlazada en el pie | ◐ |

### Baja

- `?q[]=a` pinta «Array» y rompe el JSON del autocompletado en local (`Pages.php:1120`, `Api.php:412`, `layout.php:110`). ✔
- API acepta ids no canónicos (`-1`, `01`, `x-1`) con 200 (`Api.php:104`). ✔
- URL heredada con ceros a la izquierda → dos saltos 301+308 (`Legacy.php:56-72`). ✔
- Feeds sin fecha por elemento y solo 5 items. ✔
- Año «actual» en UTC (`gmdate('Y')` en `Pages.php:337,891,903,1210`); `Seo.php:43` sí usa Europe/Madrid. ◐
- Logout no revoca el token (válido hasta 8 h). ◐
- Login de un usuario con MD5 heredado fallaría en PRO (rehash contra BD de solo lectura, `Admin.php:403`); hoy 0 cuentas MD5. ◐
- `MalagaBlogImporter` sigue redirecciones sin restringir protocolo (solo admin, host fijo). ◐
- Workflows sin `permissions:` y acciones sin fijar a SHA. ◐
- `calidad.yml` es un no-op (sin `composer.json` se salta todo) que sale en verde. ◐
- `ci_enlaces_auto` falla en local porque asume que no existe `config.local.php`. ✔ (5 de 6 baterías pasan: 93 comprobaciones)
- Código sobrante: `MunicipioRepo::conCoordenadas()`; `Mapa.php` solo se usa por sus constantes de provincias (renombrar); 16 clases CSS sin uso aparente.

### Quick wins (≤ 1 h cada uno)

gzip en nginx (hoy solo Brotli) · `lastmod` en sitemaps · «— página N» en el `<title>` de la paginación · 301 de `/marcha/` a `/marcha` · `width`/`height` y `loading="lazy"` en portadas (`Html.php:86`) · `scope` en los 86 `<th>` · `preload` de las 2 fuentes iniciales · quitar `x-powered-by` · hover de Tidal/Spotify · helper `Http::qs()`.

### Mantenibilidad

`Admin.php` 3.552 líneas / 178 funciones, `Repo.php` 2.356, `AdminRepo.php` 2.082; los tres han crecido desde julio. Corte propuesto sin tocar rutas: `AdminAuth`, `AdminAcomp` (≈600 líneas, `:1085-1780`), `AdminPropuestas`, `AdminDisco`, `AdminDashboard` y un trait para el patrón notice + redirect. PHPStan nivel 5 con 117 errores en baseline.

### Lo que está bien (verificado)

FTS5 a prueba de entradas raras · 404 reales en todos los casos límite · un solo 308 a la canónica · SQL parametrizado con allowlists · sesión HMAC con huella de clave, cookie `__Host-` · `clientIp()` no confía en X-Forwarded-For · portadas recodificadas · secretos nunca versionados · FK activas por conexión, transacciones reentrantes · sync con backup obligatorio, checksum y rollback · texto de la BD sin mojibake ni espacios, localidades 100 % alineadas con el INE.

---

## 4. Datos

| Cobertura (sobre 5.438 marchas) | % |
|---|---|
| Estilo | 100 |
| Año | 95,2 |
| Banda de estreno | 85,7 |
| Dedicatoria | 63,6 |
| Localidad/provincia | 58,3 |
| Alguna escucha (AUDIO o streaming) | 56,9 |
| Duración | 41,4 |
| Enlace de streaming | 34,4 |
| Notas | 5,3 |
| Compositores con año de nacimiento | 10,8 |
| Compositores con biografía | 4,1 |
| Bandas con año de fundación | 71,4 |
| Discos con streaming | 47,9 |

- **Duplicados por revisar:** 27 pares de marchas con mismo título normalizado y autor común (17 con la misma banda de estreno; p. ej. 247/5099, 1537/1543, 2356/5064, 2373/3916, 2881/5411); autores 188/222, 242/486, 529/1268; bandas 92/157; discos 161/239 y 5/68/258; 15 pistas con dos marchas en el mismo número (p. ej. disco 21 pista 7).
- **Datos rotos:** marcha 4036 con `BANDA_ESTRENO = 2017` (no existe); fechas sospechosas en 841 (1241) y 1344 (1729).
- **Dedicatorias:** 31 altas recientes sin canonizar («Hdad …»).
- **Índices sugeridos** (la ficha de marcha hace ~4 SCAN por visita; hoy < 100 ms, no urge):

```sql
CREATE INDEX idx_marcha_fecha     ON marcha(FECHA);
CREATE INDEX idx_marcha_provincia ON marcha(PROVINCIA, TITULO);
CREATE INDEX idx_marcha_estilo    ON marcha(ESTILO, TITULO);
CREATE INDEX idx_marcha_dedic_loc ON marcha(DEDICATORIA, LOCALIDAD);
CREATE INDEX idx_contrato_loc_loc ON contrato_localidad(LOCALIDAD);
```

---

## 5. Despliegue y operación

- **Pipeline:** 25 últimos runs en verde, deploy de 1–2,5 min. Falta lo que pasa cuando algo va mal (rollback automático, atomicidad, cifrado).
- **Rendimiento medido en PRO:** TTFB 0,5–0,7 s en todo, también en estáticos (en local < 0,1 s): es el hosting, no PHP. HTTP/2, Brotli, estáticos con 30 días + ETag; el HTML sin ETag ni Last-Modified.
- **Recomendación 1 — Cloudflare gratuito delante**, con caché de HTML anónimo y bypass de `/dashboard` y `/login`. Horas de trabajo; se prueba en PRE.
- **Recomendación 2 — migrar después de Pascua** a un compartido de pago con SSH y PHP 8.4: deploy atómico por rsync, copias externas fáciles, sin límites de cuenta gratuita. 1–2 días. No antes de Semana Santa salvo que Cloudflare no baste.
- **Monitorización:** un monitor sobre `/health` con aviso por correo; errores 500 a un log que nadie revisa. Añadir monitor sobre `/` y aviso de caducidad de dominio.
- **Higiene:** 12 MB versionados en `scripts/tmp_acompanamientos/`; CSV y scripts sueltos en raíz y `php/`; `.DS_Store` y `Claude outputs/` sin ignorar; `main` local un mes por detrás de `origin/main`.
- **Ramas remotas sin fusionar (revisar antes de borrar):** `claude/acompañamientos-2025-2024-ayj2st`, `claude/acompañamientos-jaen-almeria-2026-ufpldf`, `claude/acompañamientos-dashboard-edicion-j143qy`, `claude/auto-insert-tracks-social-hxzif4`. El resto (≈12) están fusionadas y se pueden borrar. Los 4 parches de `Claude outputs/editor/` ya están aplicados.
- **Sin pruebas:** `backup.php`, `sync_db_to_prod.php`, caché/poda de OG, migraciones.
- **Abiertos según los docs:** correo del dominio, revisión de logs y espacio, OPS-01/OPS-02, OPS-04 (emails M7, 3 meses parado).

---

## 6. Documentación y roadmap

- **Contradicciones:** `context.md:53,97` («deploy FTP manual / sin CI/CD de deploy») frente a `deploy.yml`; `context.md:86,90` y `architecture.md:129` describen `/mapa` y «cuatro secciones solo en local»; recuentos de julio; `db-analysis.md` lista tablas que no existen (`videos`, `users`) y omite las de las migraciones 011–021.
- **Roadmap desfasado:** R-07 publicada el 1-oct y sigue «falta desplegar»; M2/P-01 con 899 pendientes (hoy 290); R-02 parece hecha (1.923 pistas con duración); §4 «Ramas abiertas» parado en agosto; `/rosario`, ingesta DMP y endurecimiento del login no figuran; issues #16, #20, #21 abiertos con M6/M7 cerradas.
- **Archivar:** `pendientes-manuales-2026-07-{29,30,31}`, `pendientes-post-cutover`, `cutover-fase5`, `duplicados-2026-07-31`, `enlaces-otras-rrss-2026-08-01`, `ux-analysis-estado`, `ANALISIS_UX.md`, los dos HTML de diseño.
- **Faltan:** `CLAUDE.md` (~80 líneas, condensando `ai-handoff-guide.md`), runbook de desastre, LICENSE (código y datos), guía de editores.

---

## 7. DAFO

| | |
|---|---|
| **Fortalezas** — modelo de datos rico y limpio (obra/grabación, linaje, alias) · 6.606 acompañamientos que nadie más tiene ordenados · SEO técnico y datos abiertos (API, feeds, `llms.txt`, CC BY) · seguridad bien resuelta · coste casi nulo, deploy automático con pruebas | **Debilidades** — identidad visual anónima, ninguna imagen propia · un mantenedor, una máquina, una BD sin copia externa · 34 % de streaming, 4 % de biografías · toda escritura pasa por el portátil · tres clases de >2.000 líneas, docs desfasados |
| **Oportunidades** — Semana Santa 2027 con contenido de temporada exclusivo · editores como fuente de grabaciones y fotos · ser la fuente citada por IA y Wikidata en el nicho · alianza iniciada con Patrimonio Musical · tarjetas sociales por marcha para WhatsApp | **Amenazas** — suspensión o límites del hosting gratuito en el pico · pérdida del portátil o corrupción silenciosa en una subida · erosión de calidad por ingesta masiva (ya se colaron 5 duplicados) · cansancio del mantenedor (185 commits en 3 meses) · rebote del visitante de marzo si la ficha no engancha |

Respecto a julio: los acompañamientos pasan de idea a fortaleza; la ingesta cambia de problema (descubrir es fácil, atribuir y deduplicar es lo caro); la amenaza de que «lo sobrio se lea como web antigua» se ha cumplido.

---

## 8. Plan hasta Cuaresma

Prioridades declaradas por el mantenedor: (1) calidad del producto, (2) datos y cobertura, (3) audiencia y SEO.

| Cuándo | Qué |
|---|---|
| **Esta semana** — red de seguridad | Copia externa de BD local y portadas, restauración probada · sync con instantánea/integridad/rename · propuestas idempotentes · FTPS + rotar contraseña · proteger `main` |
| **Nov–dic** — producto | Pasos 1–3 de §2.3 · estado vacío de escucha + filtro «solo con audio» (R-06, R-08) · quick wins de §3 |
| **Enero** — datos y temporada | Home nueva (paso 4) · resolver 27 pares de duplicados y 31 dedicatorias · nómina 2027 de Sevilla y Málaga · Cloudflare delante, probado en PRE |
| **Hasta el 10-feb** — congelar | Runbook de desastre ensayado · segundo monitor y revisión semanal del log · tag del último deploy bueno y congelación de PRO salvo arreglos |
| **Después de Pascua** | Migrar a hosting con SSH y replantear escritura moderada de editores en PRO · volcados CC BY y Wikidata · partir `Admin.php`, migraciones versionadas · fotos y biografías con permiso |

**Dejar de hacer:** nuevas fuentes de ingesta hasta cerrar la deduplicación · retoques sueltos de color y contraste · un documento fechado por sesión · partituras, PWA y mapa antes de Semana Santa.

---

## 9. Límites de esta auditoría

- Revisados poco o nada: `AdminRepo`, `IngestaRepo`, los `Acompanamiento*Repo`, `MalagaBlogImporter` (más allá de seguridad).
- Sin métricas de navegador real (LCP, CLS, INP) ni datos de tráfico: las prioridades de producto son juicio, no medición.
- No comprobable desde aquí: Time Machine u otra copia del portátil, estado del servidor, si OPS-04 está activo, portadas existentes (en local `cover/` está vacío).
- Un dato de agente corregido a mano: la retención de backups es de 60 días (`config.php:63`), no 14.
