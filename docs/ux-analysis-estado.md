# Análisis UX extendido — estado y memoria de sesión

> Última actualización: 2026-07-27
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
un plan de actuación de 6 prioridades (0-5), de las cuales **4 están
completadas, 1 en curso con una tarea de despliegue pendiente, y 1 sin
empezar**.

## 2. Estado por prioridad

| # | Título | Estado | Pendiente |
|---|--------|--------|-----------|
| 0 | Infraestructura (servidor local, CSS 503) | ⬜ No empezada | Ver §3.1 |
| 1 | Ficha de marcha (compactar, pestañas) | ✅ Hecha | — |
| 2 | Legibilidad global (versalitas monoespaciadas) | ✅ Hecha | — |
| 3 | Listados (filtros facetados, tablas ordenables) | ✅ Hecha | — |
| 4 | Datos (ubicación geográfica, TIPO editable, mapa, catálogo de municipios) | 🟡 Código completo | Ejecutar migración en producción (§3.2) |
| 5 | Consistencia (aplicar compactación a compositor/banda/disco/home) | ⬜ No empezada | Es la siguiente tarea de UX pura si se retoma este plan |

## 3. Pendientes concretos para la próxima sesión

### 3.1 Prioridad 0 — Infraestructura (sin tocar desde el diagnóstico inicial)

- [ ] Arrancar el servidor local con varios workers para desarrollo con
      assets: `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t php/public php/public/index.php`
      (el servidor embebido de PHP por defecto solo atiende una conexión a la
      vez; con `WORKERS=1` una petición de HTML y otra de CSS en paralelo
      hacen que una se descarte → CSS servido con 503 intermitente).
- [ ] Documentar esto en `php/README.md` § "Desarrollo en local".
- [ ] Para pruebas de UX/carga realistas, servir detrás de Apache/Nginx +
      PHP-FPM en vez del servidor embebido.

### 3.2 Prioridad 4 — Ejecutar en producción (código ya en `main`/rama de feature, verificado con 81/81 smoke tests)

El catálogo cerrado de municipios (tabla `municipio`, selector en cascada
provincia→localidad en todo el panel admin) está completo y probado, pero
**la migración no se ha ejecutado contra la base de datos real**:

```bash
php php/app/tools/migrate_ingest.php     # crea la tabla `municipio` (007_municipio.sql)
php php/app/tools/seed_municipios.php    # la siembra: ~8.112 municipios oficiales (INE)
                                          # + los pares LOCALIDAD/PROVINCIA ya usados
                                          # en marcha/banda que no encajen (OFICIAL=0)
```

Mismo patrón que el resto de scripts de mantenimiento del proyecto: backup
`VACUUM INTO` previo, transacción, `PRAGMA wal_checkpoint(TRUNCATE)`,
re-ejecutable sin efecto si ya está aplicado. Tras ejecutarlo: el mapa
(`/mapa`, `/mapa/provincia/{slug}`) empezará a leer coordenadas de esta tabla
en vez del fichero estático `app/geo/municipios_es.php`, y los formularios de
marcha/banda/dedicatoria/ingesta/propuestas dejarán de aceptar
Localidad/Provincia como texto libre.

### 3.3 Prioridad 5 — Consistencia (no empezada)

Aplicar el mismo patrón de compactación + bloques rotulados con recuento que
ya se aplicó a la ficha de marcha (Prioridad 1) a las fichas de **compositor,
banda y disco**, y a **home**. Mantener lo que ya funciona bien: breadcrumbs,
búsqueda global, "Véase también" con recuentos.

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

## 5. Dónde está cada cosa

- **Log narrativo completo** (todo el detalle de bugs, criterios, decisiones
  paso a paso): [`../ANALISIS_UX.md`](../ANALISIS_UX.md).
- **Contexto general del proyecto/stack**: [`context.md`](context.md).
- **Panel de administración**: [`admin-panel.md`](admin-panel.md) — no
  actualizado todavía con el selector de municipios; revisar si se retoma
  trabajo ahí.
