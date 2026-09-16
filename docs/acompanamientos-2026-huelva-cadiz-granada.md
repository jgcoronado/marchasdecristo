# Acompañamientos musicales 2026 — Huelva, Cádiz y Granada

Continuación de la carga de Sevilla 2026 (`contratos_ss_sevilla_2026.csv`, 92 filas,
ya en la BD). Aquí quedan investigadas y volcadas a CSV las tres localidades que
faltaban. **Nada de esto está todavía en la BD**: son ficheros de entrada, y el
paso de resolución de `ID_BANDA` hay que darlo en local, contra `php/data/mdc.db`.

## Ficheros

| Fichero | Filas | Bandas distintas | Hermandades | Fuente |
|---|---|---|---|---|
| `contratos_ss_huelva_2026.csv`  | 44 | 22 | 24 | musicofrades.com |
| `contratos_ss_cadiz_2026.csv`   | 50 | 36 | 29 | musicofrades.com |
| `contratos_ss_granada_2026.csv` | 56 | 29 | 31 | ahoragranada.com |
| `bandas_ss2026_hue_cad_gra.csv` | 79 bandas | — | — | derivado de los tres anteriores |

Fuentes exactas:

- Huelva — <https://musicofrades.com/acompanamientos-musicales-de-la-semana-santa-de-huelva-2026/> (2026-02-02)
- Cádiz — <https://musicofrades.com/acompanamientos-musicales-de-la-semana-santa-de-cadiz-2026/> (2026-02-04)
- Granada — <https://www.ahoragranada.com/noticias/asi-sonara-la-semana-santa-de-granada-2026-las-29-bandas-que-acompanaran-a-cada-paso/> (2026-03-27)

Musicofrades **no publicó** guía de Granada 2026 (la URL equivalente da 404), de
ahí que Granada venga de *Ahora Granada*, que sí da el listado completo cofradía
a cofradía. Es la razón de que `FUENTE` no sea uniforme entre los tres CSV.

## Formato

Mismas columnas que `contratos_ss_sevilla_2026.csv` más dos nuevas:

```
ID_BANDA,BANDA,CLAVE_BANDA,HERMANDAD,TITULAR,ANIO,FUENTE,NOTA,LOCALIDAD
```

- `ID_BANDA` llega **vacío**: las fuentes solo dan el nombre de la banda y este
  entorno no tiene la BD. `seed_contratos_2026.php` ya salta y lista las filas
  sin `ID_BANDA`, así que el CSV es cargable tal cual, pero no cargaría nada.
- `BANDA` — nombre de la banda tal como lo publica la fuente (normalizado, ver
  más abajo). Columna nueva; `seed_contratos_2026.php` la ignora, lee el CSV por
  nombre de cabecera y las columnas de más no le molestan.
- `CLAVE_BANDA` — el mismo nombre en minúsculas, sin acentos ni puntuación. Es la
  misma forma que `clave_normalizada` en `bandas_a_crear.csv` / `bandas_creadas.csv`,
  para poder cruzar los dos ficheros con un join plano.
- `LOCALIDAD` es la de la **Semana Santa** (Huelva / Cadiz / Granada), no la de la
  banda — como manda `sql/009_contrato_localidad.sql`.
- `HERMANDAD` y `TITULAR` van sin acentos, igual que las 92 filas de Sevilla ya
  cargadas, para no mezclar dos estilos en la misma tabla.

`TITULAR` usa el mismo vocabulario que Sevilla: `Cruz de Guia`, `Paso de Misterio`,
`Paso de Cristo`, `Paso de Palio`, `Paso de Virgen`.

## Diferencia de alcance respecto a Sevilla

La carga de Sevilla solo tiene cruces de guía y misterios (nada de palios). Estos
tres CSV, tal como se investigaron el 2026-09-09, incluían **todos los pasos**,
palios de banda de música incluidos.

**Corrección 2026-09-09 (antes de cargar Huelva):** el usuario decidió alinear el
alcance con el resto del proyecto de acompañamientos — se descarta cualquier paso
acompañado por banda de música, sea Cristo, Misterio, Palio o Cruz de Guía; solo
cuentan CCTT y AM. `contratos_ss_huelva_2026.csv.resuelto.csv` pasó de 44 a 24
filas (se quitaron 19 palios + El Santo Entierro, que llevaba banda de música en
un paso de Misterio) y `bandas_a_crear_contratos_ss_huelva_2026.csv` de 22 a 10
bandas. Aplicar el mismo filtro al resolver Cádiz y Granada.

Se han **excluido** las filas sin banda: `Silencio`, `Trío de Capilla`,
`Capilla Musical`, el tambor ronco del Silencio de Granada y el cornetín de la
Hora Nona. Por eso hay hermandades de la fuente que no aparecen en el CSV
(El Calvario, Santa Cruz, La Misericordia y La Soledad en Huelva; El Caminito,
Descendimiento, Buena Muerte y Ecce Mater en Cádiz; San Agustín y Silencio en
Granada).

Cuando la fuente da dos bandas para un mismo paso separadas por `/` (La Borriquita
y Despojado en Cádiz), se generan **dos filas** con `NOTA = comparte paso`, la
misma convención que usó Sevilla.

## Normalizaciones aplicadas a Granada

El artículo de *Ahora Granada* nombra a varias bandas de dos o tres formas
distintas. Se han unificado, y la comprobación es el propio artículo: dice cuántas
jornadas hace cada banda, y los recuentos del CSV cuadran exactamente (Despojado 8,
San Isidro de Armilla 6, San Sebastián de Padul 5, La Estrella 4, Los Ángeles 4,
Felipe Moreno 3, y 2 para Exfiliana, Dulce Nombre y La Victoria). También cuadra el
total: 29 bandas distintas, las mismas que anuncia el titular.

| Formas publicadas | Nombre unificado |
|---|---|
| `BCT Nuestro Padre Jesús Despojado` / `… de Nuestro Padre Jesús Despojado de sus Vestiduras` / `… Nuestro Padres Jesús …` | BCT Nuestro Padre Jesús Despojado de sus Vestiduras de Granada |
| `BCT María Santísima de la Victoria` / `BCT Nuestra Señora de la Victoria` | BCT María Santísima de la Victoria de Granada |
| `Banda y Unidad de Música Nuestra Señora de los Ángeles` / `… Ángeles de Granada` | Banda y Unidad de Música Nuestra Señora de los Ángeles de Granada |
| `AM Nuestra Señora de la Cabeza de Exfiliana` / `AM Virgen de la Cabeza de Exfiliana` | AM Virgen de la Cabeza de Exfiliana |
| `AM Dulce Nombre de Jesús` / `AM del Dulce Nombre de Jesús` | AM del Dulce Nombre de Jesús de Granada |
| `Asociación` / `Agrupación Músico Cultural San Sebastián de Padul` | Asociación Músico-Cultural San Sebastián de Padul |
| `Banda Felipe Moreno e Cúllar Vega` (errata) | Banda de Música Felipe Moreno de Cúllar Vega |

Erratas de la fuente que **no** afectan al CSV porque el dato no se guarda: marca
`(Má)` en la Banda de Música de Torredonjimeno, que es de Jaén (el propio artículo
la cuenta entre las jiennenses).

## `bandas_ss2026_hue_cad_gra.csv`

Inventario de las 79 bandas distintas que salen en los tres CSV, en el formato que
espera `php/tools/seed_bandas_2026.php`
(`NOMBRE_COMPLETO,NOMBRE_BREVE,LOCALIDAD,PROVINCIA,clave_normalizada`).

- `LOCALIDAD`/`PROVINCIA` salen de casar el final del nombre contra
  `php/app/geo/municipios_es.php` (misma fuente que la tabla `municipio`), con la
  forma invertida del artículo incluida (`La Puebla del Río` → `Puebla del Río (La)`).
  Dos excepciones fijadas a mano porque no son municipio: **Exfiliana** (pedanía de
  Valle del Zalabí, Granada) y **Soledad de Mena**, que es la advocación, no el
  sitio — esa banda es de Málaga.
- `NOMBRE_BREVE` es una propuesta derivada (AM / AMC / BCT / BM / BUM / SM +
  núcleo del nombre, quitando la localidad), con `(Localidad)` añadido cuando el
  breve quedaba repetido o demasiado genérico. **Revísalo antes de dar de alta**:
  es el campo que se ve en la ficha.
- **No es una lista de "bandas a crear"**: muchas ya estarán en la BD. La lista real
  de altas sale del informe del resolutor (abajo).

## Procedimiento para cargarlo (local, luego sync a prod)

```powershell
# Windows/PowerShell — desde la raíz del proyecto
php php/app/tools/resolver_contratos_banda.php contratos_ss_huelva_2026.csv
php php/app/tools/resolver_contratos_banda.php contratos_ss_cadiz_2026.csv
php php/app/tools/resolver_contratos_banda.php contratos_ss_granada_2026.csv
```

Eso no escribe nada: informa de cuántas bandas casan por nombre contra `banda`
(por slug, insensible a acentos y puntuación, contra `NOMBRE_COMPLETO` y
`NOMBRE_BREVE`), cuáles salen ambiguas y cuáles no aparecen.

`--faltantes` da el segundo paso: cruza las que no aparecen contra
`bandas_ss2026_hue_cad_gra.csv` y escribe
`bandas_a_crear_contratos_ss_<loc>_2026.csv` ya en el formato de
`seed_bandas_2026.php`, sin tener que recortar el inventario a mano.

```powershell
# Windows/PowerShell
php php/app/tools/resolver_contratos_banda.php contratos_ss_huelva_2026.csv --faltantes
# revisa NOMBRE_BREVE en bandas_a_crear_contratos_ss_huelva_2026.csv, y entonces:
php php/tools/seed_bandas_2026.php bandas_a_crear_contratos_ss_huelva_2026.csv            # dry-run
php php/tools/seed_bandas_2026.php bandas_a_crear_contratos_ss_huelva_2026.csv --commit
```

Ojo con el orden: las tres localidades comparten bandas (la BCT Santísimo Cristo
de la Victoria de León sale en Huelva y en Cádiz), así que haz una localidad
entera antes de pasar a la siguiente — si no, el segundo `--faltantes` volvería a
proponer altas que el primero ya hizo. `seed_bandas_2026.php` no duplica
(comprueba `NOMBRE_COMPLETO`), pero el ruido sobra.

Y ya con las bandas dadas de alta, se vuelve al resolutor con `--write` y se carga:

```powershell
# Windows/PowerShell
php php/app/tools/resolver_contratos_banda.php contratos_ss_huelva_2026.csv --write
php php/app/tools/seed_contratos_2026.php contratos_ss_huelva_2026.csv.resuelto.csv            # dry-run
php php/app/tools/seed_contratos_2026.php contratos_ss_huelva_2026.csv.resuelto.csv --commit
```

Igual para Cádiz y Granada. `seed_contratos_2026.php` es idempotente a mano
(comprueba `ID_BANDA` + `HERMANDAD_SLUG` + `TITULAR` + `ANIO` antes de insertar),
así que se puede relanzar sin duplicar. La subida a producción va por
`scripts/sync_db_to_prod.php`, como el resto.

## Pendiente / avisos

- La columna `LOCALIDAD` de los CSV **no llega a la BD como agrupación visible**:
  `Repo::temporada()` sigue agrupando por `banda.LOCALIDAD` (la de la banda), no
  por `contrato_localidad`. Está descrito en `docs/technical-debt.md` §2.2. Con tres
  localidades nuevas cargadas el problema deja de ser teórico: una banda de Bollullos
  tocando en Huelva aparecerá bajo "Bollullos Par del Condado".
- `seed_contratos_2026.php` sí persiste la localidad vía `AdminRepo::addContrato`,
  así que el dato queda guardado aunque todavía no se use para agrupar.
- Los datos son de febrero/marzo de 2026 y la Semana Santa ya pasó: pueden existir
  cambios de última hora que las fuentes no recogieran.
