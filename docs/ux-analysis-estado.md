# Análisis UX extendido — estado y memoria de sesión

> Última actualización: 2026-07-28
> Síntesis de una serie larga de sesiones a partir de una comparativa UX con
> [patrimoniomusical.com](https://patrimoniomusical.com). El log detallado,
> con la narrativa completa de cada cambio (bugs encontrados, criterios de
> diseño, verificaciones), vive en [`../ANALISIS_UX.md`](../ANALISIS_UX.md) —
> este documento es el resumen ejecutable para retomar el trabajo sin releer
> las ~300 líneas de ese log.

---

## 1. Origen y alcance

Se comparó el catálogo actual con patrimoniomusical.com (portal de referencia
del mismo dominio: marchas procesionales) en 4 frentes: ficha de marcha, home,
listados/búsqueda, y páginas de entidad (compositor/banda/disco). De ahí salió
un plan de actuación de 6 prioridades (0-5), y **las 6 están completadas**. El
plan que dio origen a este documento está cerrado; queda como referencia de
arquitectura (§4) para quien retome esta zona del código.

## 2. Estado por prioridad

| # | Título | Estado |
|---|--------|--------|
| 0 | Infraestructura (servidor local, CSS 503) | ✅ Hecha (§3.1) |
| 1 | Ficha de marcha (compactar, pestañas) | ✅ Hecha |
| 2 | Legibilidad global (versalitas monoespaciadas) | ✅ Hecha |
| 3 | Listados (filtros facetados, tablas ordenables) | ✅ Hecha |
| 4 | Datos (ubicación geográfica, TIPO editable, mapa, catálogo de municipios) | ✅ Hecha (migración + siembra ejecutadas en producción, 2026-07-28 — `sync_db_to_prod.php` verificado por checksum sin errores) |
| 5 | Consistencia (anclas de navegación en compositor/banda/disco) | ✅ Hecha (§3.2) |

## 3. Resumen de las dos últimas prioridades

### 3.1 Prioridad 0 — Infraestructura

El 503 intermitente de `assets/app.css` no estaba en el código de la app: el
servidor embebido de PHP procesa **una petición a la vez**
(`PHP_CLI_SERVER_WORKERS=1`), así que el CSS/JS que el navegador pide en
paralelo se encola detrás del HTML. Medido contra una página de 400 ms:
`app.css` en ~350 ms con 1 worker frente a <1 ms con 4.

- [x] `scripts/dev_server.sh`: arranca el servidor con 4 workers (configurable
      por `WORKERS`/`HOST`/`PORT`/`DB_PATH`), comprueba que la BD existe y
      avisa si falta `config.local.php`.
- [x] Documentado en `php/README.md` § "Desarrollo en local" y en la
      referencia rápida de `pendientes-post-cutover.md`.
- [x] Cabecera MIME del CSS verificada: `Content-Type: text/css; charset=UTF-8`
      (el `.htaccess`/servidor la emiten bien; no era parte del problema).
- Nota: en Windows los workers se ignoran (usan `fork()`). Para pruebas de
  carga o UX realistas, servir detrás de Apache/Nginx + PHP-FPM.

### 3.2 Prioridad 5 — Consistencia

Al retomarla se comprobó (`git log -S` sobre `class="desc"`) que la propia
compactación de la Prioridad 1 — rejilla `dl.desc`/`.f`, cabeceras `.shead`
con recuento, `.vease`, tope de ancho del vídeo (`.ytembed { max-width:
30rem }`) — ya era **CSS/clases compartidas** por las 4 fichas de entidad
desde antes de la comparativa UX; no había nada que "portar". Lo único
genuinamente exclusivo de marcha era la barra de anclas `.rectabs` ("Datos /
Escuchar / Grabaciones (n)"), así que el trabajo real fue añadir esa misma
navegación a las otras 3 fichas:

- **Compositor**: Datos / Biografía (si hay `BIO`) / Obra (n).
- **Banda**: Datos / Formaciones / Discografía (n, si hay discos propios) /
  Estrenos (n) — la que más se beneficia: 3 bloques de contenido distinto,
  igual que marcha tenía Datos/Escuchar/Grabaciones.
- **Disco**: Datos / Notas (si hay `D_DETALLES`) / Contenido (n).

Cada pestaña es condicional como en marcha (se omite si la sección no tiene
contenido). Verificado sirviendo la fixture de CI a través de
`scripts/dev_server.sh`: los `id` de cada bloque casan con el `href="#..."`
de su pestaña, y 81/81 smoke tests.

**Home** se revisó sin encontrar ningún hueco real: ya usa `.ytembed` (mismo
tope de ancho) y `.vease`/`.cnt` en "Explorar el catálogo", y el diagnóstico
original ya reconocía que ganaba en estructura a patrimoniomusical. Sin
cambios.

## 4. Decisiones de arquitectura relevantes para retomar el trabajo

Por si una sesión futura necesita tocar esta zona sin releer todo el log:

- **Mapa** (`App\Mapa`, `public/assets/mapa.js`): navegación en dos niveles
  — `/mapa` pinta solo provincias (coropleta, color `--acc`), `/mapa/provincia/{slug}`
  amplía una provincia con cada municipio como punto clicable (color por
  cantidad de marchas vía `Mapa::nivelLocalidad`, rampa `--pt-1..4`, distinta
  de la coropleta para no fundirse con el fondo). Zoom/pan con rueda/arrastre;
  el radio de punto y tamaño de rótulo se recalculan en sentido inverso al
  factor de zoom para mantener tamaño aparente constante. Canarias se excluye
  del dibujado (no del catálogo) por no encajar en la proyección afín del
  SVG base.
- **Catálogo de municipios** (`App\MunicipioRepo`, tabla `municipio`): fuente
  única de verdad para Localidad/Provincia en todo el panel admin y en las
  coordenadas del mapa. Provincia cerrada a las 52 de `Mapa::PROVINCIAS`;
  Localidad con predictivo scoped a la provincia elegida
  (`/api/municipio/fastSearch`). Regla de negocio: **la localidad manda sobre
  la provincia** — `AdminRepo::fijarMunicipio()` deriva/corrige la provincia
  a partir de la localidad en cada alta/edición (marcha, banda, dedicatoria);
  solo rechaza si la localidad no existe (`INVALID_LOCALIDAD`) o es ambigua
  entre provincias sin especificar cuál (`AMBIGUOUS_LOCALIDAD`). Alta de
  pares nuevos: admin directo (`/dashboard/municipio/add`), editor vía cola
  de propuestas (igual que el resto de sus altas/ediciones).
- **Selector en cascada** (`App\Html::municipioFields()` +
  `public/assets/admin.js` → `initMunicipioPicker`): componente reutilizado
  en `marcha_form`, `banda_form`, `banda_add`, `dedicatoria_form`,
  `ingesta_detail` y `propuesta_detail`. `Html::municipioFields()` escapa
  internamente (HTML-escape) — pasarle un valor ya escapado produce doble
  escape; pasar siempre el valor crudo.
- **Sincronización `dedicatoria_alias` ↔ `marcha.LOCALIDAD`**: trigger SQLite
  `app/tools/sql/006_sync_dedicatoria_alias_localidad.sql` (`AFTER UPDATE OF
  LOCALIDAD ON marcha`) mantiene la ficha de dedicatoria coherente
  automáticamente ante cualquier renombrado de localidad, incluidos los
  hechos a mano desde el admin. Casos ambiguos (más de un hueco/huérfano a la
  vez) se dejan para `app/tools/reconciliar_alias_localidad.php` en vez de
  adivinar.
- **Patrón de scripts de mantenimiento** (`app/tools/*.php`): standalone (sin
  depender de que el resto de la app cargue), backup `VACUUM INTO` antes de
  escribir, transacción, `PRAGMA wal_checkpoint(TRUNCATE)` tras el commit
  (necesario en bases `journal_mode=WAL` — si no, los cambios pueden quedar
  solo en el `-wal`, invisibles desde otra conexión), idempotentes. Las
  migraciones DDL van en `app/tools/sql/*.sql` (`CREATE ... IF NOT EXISTS`) y
  se aplican todas de una vez con `php php/app/tools/migrate_ingest.php`.
  Ojo: ese runner falla contra la BD fixture de CI (`tools/ci_fixture.php`)
  porque su schema es intencionadamente mínimo — para probar una `.sql`
  suelta contra la fixture, aplicarla con PDO directo, no con el runner.
- **`app/config.local.php` en pruebas manuales**: debe **devolver un array**
  que se fusiona con los defaults (`config.php`); un `putenv('DB_PATH=...')`
  suelto en ese fichero no sirve porque `db_path` en los defaults ya evalúa
  `getenv('DB_PATH')` antes de que se cargue `config.local.php`. Usar
  `return ['db_path' => '...', 'secret_key' => '...', 'env' => 'local'];`.

## 4 bis. Revisión «discreto, sencillo y cómodo» (2026-07-28)

Tras las 6 prioridades anteriores, la valoración del responsable seguía siendo
que las pantallas **no** transmitían un diseño discreto ni cómodo, tomando como
referencia [patrimoniomusical.com](https://www.patrimoniomusical.com). Se
compararon las tres fichas que él señaló (`bd-marcha-22`, `bd-autor-854`,
`bd-banda-3`) contra las nuestras, con capturas de ambas.

### Qué hace la referencia (y nosotros no hacíamos)

1. **Una sola familia tipográfica.** Todo —título, etiquetas, valores, menú— en
   la misma. Nosotros teníamos tres compitiendo: serif en títulos, la de
   sistema en el cuerpo y **monoespaciada en 37 reglas** de CSS (migas,
   contadores, «orden cronológico», eyebrow, firma, permalink, buscador, pie…).
2. **La página cabe en una pantalla.** Las 44 grabaciones de «La Estrella
   Sublime» no están en la página: viven tras una pestaña que carga al pulsar.
3. **Filas etiqueta/valor con fondo alterno y sin filetes.** La separación la
   da el color de fondo, no las líneas.
4. **Etiquetas en caja normal**, no versalitas.
5. **Nada de cromo de catálogo**: sin identificador de registro, sin «registro
   N de M», sin firma bibliográfica.
6. **Contraste bajo.** El texto no es negro (`#2c3a49`) y no hay ningún filete
   del color de la tinta.

**No se ha copiado su piel** (plantilla Artisteer de 2010: banner con degradado,
sombras, crema y tostado). Lo que se ha tomado es la contención y la
arquitectura de información.

### Diagnóstico

El problema no era el color: era la **textura**. Cada elemento pedía atención
con algo —cambio de familia, filete negro de 2px, caja alta, píldora,
contador— y, como todo destacaba un poco, nada quedaba en segundo plano. A eso
se sumaban letra pequeña (valores a 14px, tablas a 12,8px, contadores a 11px) y
repetición: el «asiento» en cursiva bajo el título decía exactamente lo mismo
que la rejilla de datos dos líneas más abajo.

### Qué se cambió

- **Tipografía.** Retirada la monoespaciada de todo el texto de lectura; queda
  solo en `.mono`, que se usa a propósito para URLs y nombres de fichero
  (`datos.php`, `dedicatoria_list.php`). El serif se reserva a títulos.
  Ningún texto de lectura por debajo de 0.8rem.
- **Filetes.** Fuera los `2px solid var(--ink)` (cabecera, `.record`, `.shead`,
  `.section-title`, `.rail-title`, `.headDetail`), el `3px double` de
  `dl.desc` y los `dotted` de `.f`/`.vease`/`.azitem`. Máximo 1px, nunca del
  color de la tinta.
- **Datos.** `dl.desc` pasa de rejilla de 2 columnas con puntos de guía a una
  sola columna con fila alterna (`--zebra`). En móvil la etiqueta va encima.
  Mismo tratamiento en `table.reg` y `.table`.
- **Redundancia eliminada.** El eyebrow (`.eb`) y la firma (`.sig`) en 13
  plantillas; el `.asiento` en las 5 fichas de entidad (se conserva en las
  páginas de sección, donde sí es una descripción real); «registro N de M» en
  las migas; el identificador repetido al pie.
- **«Escuchar» abierto.** Era un `<details>` plegado: escondía tras un clic lo
  que la mayoría de visitantes viene a hacer. La fachada del vídeo sigue sin
  cargar YouTube hasta que se pulsa.
- **Anclas condicionales.** `.rectabs` solo se pinta si la ficha no cabe en
  pantalla (≥12 filas en la tabla principal). Antes llevaban a secciones ya
  visibles.
- **Superficies y contraste.** Fondo de página más próximo a la tarjeta, sin
  sombra en `.record`, tinta a `#22262f` en vez de casi negro, y el tinte
  índigo del hover de tabla sustituido por gris neutro.

Verificado con `php -l` (0 fallos), 81/81 smoke tests, y una auditoría en
navegador sobre 6 vistas que confirma **0 elementos monoespaciados** fuera de
`.mono` y ningún texto de lectura por debajo de 12,5px. Comprobado en claro,
oscuro y móvil (390px).

> `revision-diseno-frontend.html` documenta la iteración **anterior** («índigo
> noche», gramática de catálogo bibliográfico). Se conserva como registro
> histórico: los criterios vigentes son los de la cabecera de `app.css`.

## 5. Dónde está cada cosa

- **Log narrativo completo** (todo el detalle de bugs, criterios, decisiones
  paso a paso): [`../ANALISIS_UX.md`](../ANALISIS_UX.md).
- **Contexto general del proyecto/stack**: [`context.md`](context.md).
- **Panel de administración**: [`admin-panel.md`](admin-panel.md) —
  documenta el selector de municipios en cascada (§9).
