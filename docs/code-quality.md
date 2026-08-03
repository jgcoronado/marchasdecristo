# Calidad y duplicidad de código — análisis de alternativas y toolchain vigente

> Última actualización: 2026-08-03 (primera auditoría medida e implantación del gate)

El repositorio ha llegado a **19.300 líneas de PHP en 101 ficheros**, más JS de
assets, `.mjs` de ingesta y Python de `music_links`. A ese tamaño ya no se
revisa "a ojo": hacen falta métricas para saber qué duele y un gate que impida
que empeore. Este documento responde a dos cosas:

1. **§1–§3** — qué alternativas hay para auditar calidad y duplicidad (incluida
   SonarQube), cuál se ha elegido y por qué.
2. **§4–§6** — la primera auditoría con números reales, y el burn-down
   priorizado de lo que señala.

---

## 1. El criterio: qué restringe la elección en este proyecto

Cualquier herramienta tiene que convivir con tres decisiones ya tomadas, y esto
descarta más opciones que cualquier comparativa de features:

| Restricción | Consecuencia |
|---|---|
| **Sin composer ni `vendor/`** (`php/README.md`, ADR-001) | Nada que se instale como dependencia PHP del proyecto. La vía viable son **PHARs autocontenidos** descargados a una carpeta gitignorada, o servicios que analicen sin instalar nada. |
| **Deploy por mirror FTP de `php/app` y `php/public` con `--delete`** | Cualquier fichero dentro de esas dos carpetas acaba en producción. Configuración y herramientas van en la **raíz** o en `scripts/`, que no se despliegan. |
| **Un solo desarrollador, ciclo corto, deploy automático a PRO** | Un gate que dé falsos positivos o tarde minutos se acaba desactivando. Y el gate de calidad **no debe** poder bloquear un hotfix a producción. |

A eso se suma que el problema real no es de estilo (el código ya es
consistente: `declare(strict_types=1)`, clases `final`, docblocks) sino de
**tamaño y complejidad concentrada** más **duplicación entre plantillas y
scripts**. Eso condiciona qué familia de herramienta aporta algo.

---

## 2. Las alternativas, evaluadas

### 2.1 SonarQube Cloud (ex SonarCloud) — SaaS

- **Coste**: **gratis y sin límite de líneas para repositorios públicos**, y este
  lo es. El plan gratuito cubre además hasta 50.000 líneas en privados (el
  proyecto está en ~19.300 de PHP, así que entraría igual si algún día se cierra).
- **A favor**: es exactamente lo que pedías — panel web, deuda técnica estimada
  en tiempo, **histórico de métricas** (la única opción que responde "¿esto va a
  mejor o a peor desde marzo?"), quality gate sobre *código nuevo* ya integrado,
  decoración de PRs, y análisis nativo de duplicación además de PHP, JS y Python
  en la misma vista.
- **En contra**: el analizador PHP de Sonar es sensiblemente **más flojo que
  PHPStan en inferencia de tipos** — no detecta la clase de fallos reales que sí
  aparecen en §4.3. Añade una dependencia externa (cuenta, token, GitHub App con
  acceso al repo) y un servicio de terceros en el pipeline. Su detector de
  duplicación no cubre `.mjs` ni plantillas con la misma finura que jscpd.
- **Veredicto**: **complemento válido, no sustituto.** Vale la pena si quieres el
  panel y la serie histórica; no vale como única red de seguridad.

### 2.2 SonarQube Community autohospedado

- Requiere un servidor con JVM + Elasticsearch (~2–4 GB de RAM) permanentemente
  en pie. El hosting del proyecto es **HelioHost compartido con FTP**: no hay
  dónde ponerlo. Levantarlo en Docker en local funciona, pero entonces el
  análisis solo pasa cuando te acuerdas de arrancar el contenedor — justo lo que
  un gate debe evitar.
- **Veredicto**: descartado. Todo el coste operativo, ninguna ventaja sobre la
  versión Cloud en un proyecto de un solo desarrollador.

### 2.3 Toolchain nativa de PHP con PHARs — **la elegida**

Tres herramientas que cubren, entre ellas, lo que hace SonarQube, sin servidor,
sin cuenta y sin composer:

| Herramienta | Qué aporta | Por qué esta y no otra |
|---|---|---|
| **PHPStan** (PHAR) | Análisis estático real: tipos, ramas imposibles, código muerto, offsets inexistentes. Es lo que encuentra **bugs**. | Frente a **Psalm**: PHPStan tiene mejor soporte de PHP 8.4 y el sistema de **baseline** que permite adoptarlo en un repositorio con historia sin parar a arreglar 97 cosas primero. Un solo analizador es suficiente; dos se solapan y doblan el mantenimiento. |
| **jscpd** (npx) | Duplicidad. | **Multi-lenguaje en una sola pasada**, que es lo que este repo necesita (PHP + JS + `.mjs` + Python + CSS + SQL). La alternativa PHP-only, `phpcpd`, está **abandonada** y solo vería el PHP. |
| **PHPMD** (PHAR) | Tamaño y complejidad: complejidad ciclomática, NPath, god classes, métodos kilométricos, código sin usar. | Es el sustituto directo de las reglas de "mantenibilidad" de Sonar. **PHPMetrics** da informes más bonitos pero no es gate-able con la misma precisión; se puede añadir después si apetece el HTML. |

Se han dejado **fuera a propósito**:

- **PHP_CodeSniffer / PHP-CS-Fixer** (estilo PSR-12): generarían un diff mecánico
  de miles de líneas que ensucia el historial y **no arregla ningún problema
  real** — el estilo ya es consistente. Si algún día se quiere, va en un commit
  aislado y solo de formato.
- **PHPUnit**: la verificación aquí es de integración (`php/tools/ci_smoke.php`,
  81+ aserciones contra un servidor real). Tests unitarios son una discusión de
  producto, no de esta auditoría.

### 2.4 Plataformas SaaS alternativas (Codacy, Code Climate, Qlty, DeepSource)

Mismo modelo que SonarQube Cloud, casi todas gratis en repos públicos. En la
práctica son **envoltorios sobre estas mismas herramientas** (Codacy ejecuta
PHPMD y PHPCS por dentro), así que aportan panel e histórico, no capacidad de
detección. Si en algún momento se quiere el panel, la elección natural es Sonar
por ser el que pediste y el que tiene mejor soporte PHP del grupo.

### 2.5 GitHub nativo: CodeQL y revisión de Copilot

- **CodeQL** es de **seguridad**, no de calidad ni duplicidad, y su soporte de PHP
  es limitado. Resuelve otro problema (y `/security-review` ya lo cubre a
  demanda). No sustituye nada de lo de arriba.
- **Revisión automática de Copilot**: comenta PRs, no mide nada. Sin métricas no
  hay burn-down ni histórico.

### 2.6 Claude Code

Es la pata **complementaria** de todo esto, y conviene tener claro qué hace y qué
no:

| Uso | Qué aporta | Límite |
|---|---|---|
| `/code-review` sobre el diff, `/review` sobre un PR | Revisión semántica: intención, casos borde, coherencia con el resto del código. Ve cosas que **ninguna** herramienta estática ve. | No es determinista ni exhaustivo: no sirve de gate ni da una métrica comparable en el tiempo. |
| `/simplify` | Aplica limpiezas de reuso y simplificación sobre lo cambiado. | Trabaja sobre el diff actual, no audita el repositorio entero. |
| `/security-review` | Revisión de seguridad de la rama. | Complementa CodeQL, no es análisis de calidad. |
| Sesión con las métricas delante (esta) | **Ejecutar el burn-down**: interpretar los hallazgos, decidir qué es falso positivo y aplicar los refactors. | Necesita las métricas como entrada; no las reemplaza. |

**La división correcta**: las herramientas **miden y bloquean** de forma
reproducible; Claude **interpreta y arregla**. Usar solo Claude deja el
proyecto sin serie histórica ni gate. Usar solo herramientas deja los hallazgos
sin resolver.

---

## 3. Lo implantado

```
phpstan.neon.dist         configuración de PHPStan (nivel, exclusiones, falsos positivos con su motivo)
phpstan-baseline.neon     los 97 errores que YA existían — el gate está verde con esto
.jscpd.json               duplicidad: umbral, lenguajes, exclusiones
phpmd.xml                 tamaño y complejidad, umbrales calibrados sobre el estado real
scripts/quality.sh        un comando para todo; descarga los PHARs a .tools/ (gitignorado)
scripts/quality_metrics.php  ranking de complejidad/longitud/tamaño, y --csv para comparar en el tiempo
.github/workflows/quality.yml  el gate en CI, con resumen legible en cada run
sonar-project.properties  inerte: deja SonarQube Cloud a un paso si se quiere el panel (§7)
```

### Uso en local

```bash
scripts/quality.sh              # todo, igual que CI
scripts/quality.sh phpstan      # análisis estático
scripts/quality.sh dup          # duplicidad → informe HTML en build/jscpd/
scripts/quality.sh phpmd        # complejidad → informe HTML en build/phpmd.html
scripts/quality.sh metrics      # ranking de lo que más cuesta revisar
scripts/quality.sh baseline     # recongela PHPStan tras resolver errores
```

La primera ejecución descarga ~30 MB de PHARs a `.tools/`; las siguientes son
locales. Nada de esto entra en el repositorio ni se despliega.

### Qué bloquea y qué no

| Comprobación | En CI | Motivo |
|---|---|---|
| PHPStan sobre el baseline | 🔴 **bloquea** | El código nuevo sale limpio o no entra. Lo viejo está congelado, no ignorado. |
| Duplicidad sobre umbral (5%) | 🔴 **bloquea** | Un clon nuevo grande es una decisión, no un descuido. |
| PHPMD y métricas | 🟢 informativos | Sirven para elegir el siguiente refactor, no para frenar el trabajo del día. |

`quality.yml` está **separado de `ci.yml` a propósito**: `ci.yml` es el gate del
despliegue (`deploy.yml` lo invoca como workflow reutilizable), y un aviso de
tipos no debe impedir subir un fix a producción. Si algún día se quiere que sí
bloquee el deploy, basta mover el paso de PHPStan a `ci.yml`.

---

## 4. La auditoría: estado medido el 2026-08-03

### 4.1 Duplicidad — 3,91% de líneas (🟢 saludable)

| Lenguaje | Ficheros | Líneas | Clones | Líneas duplicadas |
|---|---|---|---|---|
| PHP | 93 | 13.384 | 47 | 634 (4,74%) |
| JavaScript | 9 | 2.298 | 3 | 49 (2,13%) |
| Python | 5 | 1.267 | 3 | 28 (2,21%) |
| CSS / SQL | 9 | 1.149 | 0 | 0 |
| **Total** | **117** | **18.182** | **53** | **711 (3,91%)** |

**Conclusión importante: la duplicidad no es el problema de este repositorio.**
Está en el mismo orden que el umbral por defecto de SonarQube (3%) y muy lejos
de una situación patológica. Los clones que hay están concentrados en tres
patrones concretos y reparables (§6.2), no repartidos por todas partes.

### 4.2 Tamaño y complejidad — aquí sí está el problema (🟡)

530 funciones/métodos, de los cuales solo **4 pasan de 80 líneas** y **19 de
complejidad ciclomática 15**. Los métodos, en general, están bien.

Lo que está mal es el **tamaño de las clases**:

| Fichero | Líneas | Métodos públicos |
|---|---|---|
| `php/app/src/Repo.php` | 1.607 | 51 |
| `php/app/src/Admin.php` | 1.447 | 62 |
| `php/app/src/Pages.php` | 1.414 | — |
| `php/app/src/AdminRepo.php` | 1.164 | — |

**Esas cuatro clases son el 39% del PHP de la aplicación.** Es la causa directa
de "es difícil de manejar y revisar": no hay un método incomprensible, hay
cuatro ficheros en los que no cabe el contexto de un vistazo.

Los puntos calientes dentro de ellas:

| Método | cc | líneas |
|---|---|---|
| `php/tools/parity_compare.php::deepDiff` | 26 | 43 |
| `PropuestaRepo::dispatchApply` | 24 | 39 |
| `Pages::sitemap` | 24 | 106 |
| `Media::guardarPortada` | 22 | 46 |
| `Api::marcha` | 21 | 53 |
| `Repo::fetchMarcha` | 21 | 100 |
| `AdminRepo::addMarcha` | 20 | 52 |
| `Mapa::pintarPuntos` | 9 | **113** |

(Ranking completo: `scripts/quality.sh metrics`.)

### 4.3 Análisis estático — 97 errores, y entre ellos hay bugs reales

PHPStan **nivel 5** limpio en nivel 0. Los 97 se reparten así:

| Categoría | Nº | Naturaleza |
|---|---|---|
| `nullCoalesce.offset` / `.variable` | 74 | `?? ` redundante sobre claves que siempre existen. Ruido, pero ruido que oculta lo demás. |
| `identical.alwaysFalse` / `notIdentical.alwaysTrue` / `booleanOr.rightAlwaysFalse` | 8 | **Comparaciones que nunca se cumplen: candidatos a bug real.** |
| `arrayValues.list` | 6 | `array_values()` sin efecto. |
| `offsetAccess.notFound`, `isset.offset`, `nullsafe.neverNull`, `classConstant.unused` | 5 | Restos y código muerto. |
| `variable.undefined` | 2 | Fuera de plantillas: revisar. |

El nivel 5 es el vigente. La escalera medida, para saber lo que cuesta subir:
nivel 6 → 463 errores, nivel 8 → 638, nivel 9 → 1.414 (casi todos por tipos de
valor en `array` sin declarar). **Criterio para subir**: solo cuando el baseline
del nivel actual esté vacío o casi.

Falsos positivos ya neutralizados en `phpstan.neon.dist`, con su motivo escrito
(no en el baseline, que es lista de trabajo):

- Las plantillas reciben sus variables por `extract()` en `View::capture()`: 184
  avisos de "variable no definida" que no lo son.
- `Http::notFound()`/`redirect()` terminan la petición: el `return;` posterior es
  formalmente inalcanzable y se conserva porque hace explícito el flujo.
- Closures con `use (&$x)` en `scripts/sync_db_to_prod.php`: PHPStan evalúa la
  variable con el valor del momento de la definición.

---

## 5. Cómo evoluciona esto

1. **El baseline solo baja.** Al resolver errores, `scripts/quality.sh baseline`
   y el contador cae. Añadir entradas nuevas solo es aceptable al **subir de
   nivel**; para código nuevo, se arregla.
2. **Los umbrales solo se aprietan.** `.jscpd.json` está en 5% con el estado real
   en 3,91%: al resolver §6.2 baja a 4 y luego a 3. `phpmd.xml` tiene
   `ExcessiveClassLength` en 1.200 para señalar las tres god classes; cuando
   caigan, baja a 1.000 (el defecto) y luego a 600.
3. **La serie histórica**: `php scripts/quality_metrics.php --csv > build/m-AAAA-MM-DD.csv`
   antes y después de cada refactor grande. Si se acaba queriendo el gráfico sin
   mantenerlo a mano, ese es el momento de activar SonarQube Cloud (§7).

---

## 6. Burn-down priorizado — lo que dice la auditoría

Ordenado por relación entre lo que arregla y lo que cuesta. Nada de esto está
hecho todavía; al ejecutar un punto, táchalo con la fecha (convención de
`technical-debt.md`).

### 6.1 🟠 Verificar los 8 avisos de "comparación que nunca se cumple"

Los únicos hallazgos que pueden ser **bugs en producción**, no deuda estética:

- `php/app/src/Pages.php:661` y `:670` — en `dedicatoriaDetail()`, la condición
  `(int) ($d['PERSONAL'] ?? 0) === 1` se evalúa siempre a `false` según los tipos
  inferidos. Si es correcto, **el `noindex` de las dedicatorias personales nunca
  se aplica**, con lo que eso implica para SEO y para la privacidad de personas
  vivas. Hay que decidir si el bug está en la condición o en el tipo declarado.
- `php/app/tools/load_canales.php:60` y `php/app/tools/reevaluar_ingesta.php:73` —
  comparaciones estrictas contra un tipo imposible: la rama nunca entra.
- `php/app/tools/migrate_marcha_estilo.php:66` — lado derecho de `&&` siempre
  cierto.
- `php/app/src/Admin.php:1366` — sentencia inalcanzable fuera del patrón conocido
  de `Http::`.
- `php/app/src/Og.php:239` — parámetro `$img` que no se usa: o falta usarlo, o
  sobra en la firma.

**Coste**: una sesión de lectura. **Valor**: es literalmente para lo que sirve
haber montado esto.

### 6.2 🟡 Los tres patrones de duplicación reales

1. **El bootstrap de los scripts CLI — 14 líneas × 9 ficheros.** El bloque
   `define('APP_DIR'…)` + `require config.php` + comprobación de que existe el
   `.db` está copiado idéntico en `php/app/tools/backup.php`,
   `completar_provincia.php`, `corregir_acentos_localidad.php`,
   `migrate_banda_relacion.php`, `migrate_ingest.php`, `migrate_marcha_estilo.php`,
   `normalizar_localidades.php`, `normalizar_preposiciones_localidad.php`,
   `reconciliar_alias_localidad.php` y `seed_municipios.php`.
   **Fix**: un `php/app/tools/_cli.php` que se haga `require` desde cada script.
   El de mejor relación valor/riesgo de toda la lista: mecánico, verificable con
   el smoke, y cada script futuro nace sin el copia-pega.
2. **Los dos scripts de sync — 86 líneas.** `scripts/sync_db_to_prod.php` y
   `sync_propuestas_from_prod.php` comparten los helpers FTP (`ftpQuote`,
   `ftpListOptional`, lectura de `.env.ftp`). **Fix**: `scripts/ftp_lib.php`.
   Cuidado: `sync_db_to_prod.php` es código sensible con checksum y rollback —
   este refactor va solo, y se prueba con `--dry-run`.
3. **Las plantillas de listado — ~130 líneas alrededor de `marcha_list.php`.**
   `marcha_list.php` ↔ `banda_list.php` ↔ `disco_list.php` ↔ `dedicatoria_list.php`
   ↔ `marcha_hub.php` repiten la paginación y la cabecera de resultados;
   `rankings_anio.php` ↔ `rankings_index.php` repiten 59 líneas de tabla.
   **Fix**: parciales en `templates/` (o helpers en `Html.php`, que ya es el sitio
   de esos fragmentos). Es el bloque más grande, pero también el que más fácil
   rompe la maqueta: uno por commit, revisando visualmente en PRE.

### 6.3 🟡 Partir las cuatro god classes

El trabajo de fondo, y el que de verdad responde a "es difícil de revisar". No
es un refactor de una tarde: la propuesta es **por corte natural y de uno en
uno**, con el smoke verde entre medias.

- `Repo.php` (1.607) → separar las lecturas por entidad (`MarchaRepo`,
  `BandaRepo`, `DiscoRepo`, `AutorRepo`), que ya es el patrón de
  `MunicipioRepo`/`EnlaceRepo`/`IngestaRepo`.
- `Admin.php` (1.447, **62 métodos públicos**) → un controlador por zona del
  panel (marchas, bandas, discos, ingesta, usuarios), que es como ya está
  organizado `templates/admin/`.
- `Pages.php` (1.414) → separar los hubs SEO (`sitemap`, `llms`, hubs por año) de
  las fichas públicas.
- `AdminRepo.php` (1.164) → seguir el mismo corte que `Admin.php`.

Antes de tocar nada: `--csv` de referencia, y `php/tools/parity_compare.php` es
la red de seguridad para todo lo que salga de `Repo.php`.

### 6.4 🟢 Barrer los 74 `??` redundantes

Ruido de bajo riesgo (`sed` no sirve: hay que mirar cada caso), pero mientras
esté ahí **tapa los hallazgos de verdad** cada vez que se sube de nivel. Buen
trabajo de relleno para una sesión corta. Mayoría en `Admin.php`.

---

## 7. Si se quiere además el panel de SonarQube Cloud

`sonar-project.properties` ya está escrito y probado contra la estructura del
repo (exclusiones de datos generados, de las plantillas con `extract()` y de la
cobertura, que aquí no se mide así). Falta solo:

1. sonarcloud.io → importar `jgcoronado/marchasdecristo` (gratis, repo público).
   Elegir **"CI-based analysis"**, no "Automatic Analysis", para que respete el
   `.properties`.
2. Guardar el token como secret `SONAR_TOKEN`.
3. Añadir a `.github/workflows/quality.yml`:

```yaml
      - name: SonarQube Cloud
        if: always() && github.ref_name == 'main'
        uses: SonarSource/sonarqube-scan-action@v5
        env:
          SONAR_TOKEN: ${{ secrets.SONAR_TOKEN }}
```

Recomendación: **solo en `main`**. Un análisis por rama consume tiempo de CI y
ruido para un histórico que solo interesa sobre la línea principal. Y mantener
PHPStan como gate: Sonar aporta el panel y la serie, no la detección.

---

## 8. Cómo mantener este documento

- Al ejecutar un punto de §6 → táchalo con `~~texto~~` y la fecha, como en
  `technical-debt.md`. No lo borres: el histórico de qué se resolvió es parte
  del valor.
- Al re-medir → actualiza las tablas de §4 y la fecha de la cabecera. Los
  números viejos no se borran si sirven de comparación.
- Al subir el nivel de PHPStan o apretar un umbral → anótalo en §5 con la fecha.
- Si un hallazgo resulta ser falso positivo → sácalo del baseline y llévalo a
  `ignoreErrors` de `phpstan.neon.dist` **con su motivo escrito**. Un ignore sin
  explicación es deuda nueva.
