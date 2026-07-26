# Análisis UX comparativo — mdc-back vs. patrimoniomusical

> Generado a partir de una sesión de exploración con navegador sobre `localhost:8010`
> comparando el proyecto actual con [patrimoniomusical.com](https://patrimoniomusical.com)
> (portal de referencia del mismo dominio: marchas procesionales).

## Contratiempo detectado durante la exploración

El servidor local se volvió intermitente y llegó a caer del todo
(`ERR_CONNECTION_REFUSED`). El fichero `assets/app.css` devolvió en algún momento un
**503**, lo que hizo que home y listados se renderizaran sin estilos (viñetas, SVGs
como manchas negras, enlaces azules subrayados), mientras que la ficha de detalle sí
cargaba con estilo. No se pudieron explorar las vistas de Bandas, Discos,
Dedicatorias, Estadísticas, Mapa ni Temporada en vivo por este motivo.

Diagnóstico: `app.css` se sirve como fichero estático real (no pasa por
`routes.php`/`index.php`), así que el fallo no está en el código de la app. Encaja con
el **servidor embebido de PHP (`php -S`)**, que por defecto atiende **una conexión a
la vez** (`PHP_CLI_SERVER_WORKERS=1`): cuando el navegador pide HTML y CSS a la vez,
una de las dos peticiones queda en cola o se descarta bajo carga.

- [ ] Arrancar el servidor local con varios workers para desarrollo con assets:
      `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t php/public php/public/index.php`
- [ ] Documentar esto en `php/README.md` (sección "Desarrollo en local").
- [ ] Para pruebas de UX/carga realistas, servir detrás de Apache/Nginx + PHP-FPM.

## Análisis comparativo por vistas

**Ficha de marcha.** Mejor tipografía y navegación cruzada ("Véase también") que
patrimoniomusical, pero los datos se dispersan en dos columnas muy separadas y el
vídeo, al desplegarse, invade la pantalla. Patrimoniomusical usa una tabla compacta de
una columna y pestañas "Datos / Grabaciones (n)" que se escanean de un vistazo.
→ Compactar la ficha y separar en pestañas o bloques rotulados con recuento.

**Home.** Muy bien estructurada semánticamente (regiones "Marcha del día", "Explorar
el catálogo", "Últimas incorporaciones"), más rica y moderna que la home-portal de
patrimoniomusical (revista con cabecera pesada y bloques recargados). Aquí gana
claramente el proyecto actual en concepto; el único problema es el fallo de CSS.
→ Arreglar la carga del CSS es prioridad cero: la buena estructura no se aprecia sin
estilos.

**Listados y búsqueda.** La diferencia más instructiva. El proyecto actual ofrece una
única caja de búsqueda global con autocompletado (elegante y rápida para quien sabe
qué busca). Patrimoniomusical ofrece un **buscador avanzado por criterios** (Título,
Autor, Año, Tipo, Estilo, País, Región, Provincia, Localidad, Dedicatoria, más filtros
como "Cumple Aniversario", "No Datada", "Reciente", "Anónima") con resultados en
**tabla ordenable por columnas**. Para casi 4.800 marchas, la búsqueda facetada es un
complemento muy valioso.
→ Añadir filtros facetados y tablas ordenables a los listados, sin renunciar a la
búsqueda global existente.

**Entidades (compositores, bandas, discos).** Patrimoniomusical las trata como fichas
con datos tabulados y listados asociados; el modelo actual de "página de entidad +
enlaces con recuentos" es conceptualmente igual o mejor.
→ Mantener el enfoque, aplicando la misma compactación visual de la ficha de marcha.

## Plan de actuación consolidado

- [ ] **Prioridad 0 — Infraestructura.** Resolver el 503 del CSS y las caídas del
      servidor en desarrollo local (workers del servidor embebido de PHP o servir
      detrás de Apache/Nginx + PHP-FPM). Verificar cabeceras MIME del CSS.
- [x] **Prioridad 1 — Ficha de marcha.** Compactar la rejilla de datos (menos espacio
      vertical, columnas más juntas), introducir pestañas/anclas "Datos / Escuchar /
      Grabaciones (n)", y limitar la altura del reproductor de vídeo para que no
      desplace el contenido.
- [x] **Prioridad 2 — Legibilidad global.** Revisar las etiquetas en versalitas
      monoespaciadas (COMPOSITOR, DEDICATORIA…) hacia un estilo más legible para
      público general, conservando la monoespaciada solo como acento.
- [x] **Prioridad 3 — Listados.** Añadir filtros facetados (autor, año, tipo, estilo,
      dedicatoria, ubicación) y tablas con ordenación por columna, complementando la
      búsqueda global existente.
      - El explorador de marchas ya tenía facetas de tipo, provincia y década, y
        ordenación por título/año/grabaciones. **Añadida: faceta de Estilo**
        (Cornetas y Tambores / Agrupación Musical).
      - **Bandas y discos** pasan de "buscar primero" a explorador que lista siempre,
        con barra "Refinar por" (bandas → provincia; discos → década) y **cabeceras de
        columna ordenables** (orden en servidor, correcto con la paginación; alterna
        asc/desc). El orden por defecto se preserva idéntico al histórico (paridad).
      - Nota: compositores (autor) se deja como buscador por nombre; su único eje
        útil sería "nº de marchas", ya cubierto por Estadísticas.
- [~] **Prioridad 4 — Datos.** Valorar campos adicionales que aporta
      patrimoniomusical (ubicación geográfica, distinción tipo/estilo, campo libre de
      "notas/observaciones").
      - Ubicación (`LOCALIDAD`/`PROVINCIA`) y notas (`DETALLES_MARCHA`) ya existían y
        se muestran en la ficha; no requerían cambios.
      - **TIPO ahora es editable y validado en el panel de admin** (antes era de solo
        lectura, texto libre sin curar). Valores reales confirmados por consulta
        directa a la BD de producción (2026-07): `MARCHA PROCESIONAL` (4182),
        vacío (657), y 3 adaptaciones minoritarias (13+9+8). Lista cerrada en
        `AdminRepo::MARCHA_TIPOS`, mismo patrón de validación que `ESTILO`.
      - **Coordenadas geográficas: hecho.** El usuario aportó un listado de ~8.000
        municipios de España con lat/lng (INE + Google Maps). Se calibró una
        transformación afín lat/lng → coordenadas del `mapa-provincias.svg` por
        mínimos cuadrados (centro geográfico real de cada provincia vs. centro de
        su `<g>` en el SVG; error medio ~5.5 unidades sobre un lienzo 569×392,
        Canarias excluida por dibujarse como recuadro aparte). Sin llamadas de red
        en tiempo de ejecución (dataset estático en `app/geo/municipios_es.php`,
        coherente con la política de CSP del sitio).
        Navegación en dos niveles (a petición del usuario, tras ver que en el
        mapa nacional todos los municipios quedaban clicables a la vez):
        el mapa nacional (`/mapa`) solo pinta **provincias con su nombre**,
        sin puntos de localidad, para seleccionarlas; lleva a un mapa ampliado
        de esa provincia (`/mapa/provincia/{slug}`, recorte del viewBox a su
        caja delimitadora), pintado en el color de marca (`--acc`, contraste
        con el fondo de la tarjeta en ambos temas — ya no la coropleta, aquí
        no hay recuentos que comparar entre provincias), con cada **municipio
        rotulado con su nombre y clicable** → buscador filtrado por localidad.
        El tamaño de punto/rótulo se calcula como fracción del ancho del
        viewBox recortado (no un valor absoluto), para que se vea igual de
        grande en una provincia pequeña que en una grande.
        Ajuste tras probar con datos reales (Sevilla tiene decenas de
        municipios muy próximos): el tamaño de punto ya no varía por
        recuento — con muchos municipios cercanos, puntos grandes se
        solapaban y dejaban de poder pulsarse. Ahora el punto es pequeño y
        fijo, y **el color** indica la cantidad (`App\Mapa::nivelLocalidad`,
        rampa `--pt-1..4` ámbar — deliberadamente distinta de la coropleta
        índigo, para no fundirse con el fondo `--acc` de la provincia), con
        leyenda debajo del mapa (corregida: a las clases de color les
        faltaba `background` — solo tenían `fill`, que no pinta un `<span>`
        HTML). El rótulo también se redujo a un tercio del tamaño anterior.
        Añadido zoom/pan (`public/assets/mapa.js`, rueda del ratón, arrastrar,
        botones +/−/reset) y "traer al frente" el punto+rótulo al pasar el
        ratón (reordena el `<a>` al final de su capa SVG). Un efecto
        secundario no evidente: un listener de `pointermove` permanente en el
        `<svg>` combinado con ese reordenamiento hacía que Chromium dejara de
        despachar el `click` sobre el municipio — el listener ahora se añade
        solo durante el gesto de arrastre (pointerdown→pointerup), y el
        reordenamiento se quitó del evento `focus` (que el navegador dispara
        como parte del propio clic) dejándolo solo en `pointerenter`.
        Ajuste adicional: los puntos/rótulos mantienen su tamaño en pantalla
        al hacer zoom (antes crecían igual que el contorno de la provincia,
        al ser el mismo `viewBox` quien determina ambos). `mapa.js` guarda el
        radio/tamaño de letra "base" a escala 1 y los reescala en sentido
        inverso al factor de zoom en cada cambio de vista, de modo que solo
        cambia su posición relativa, no su tamaño aparente.
        Bug de datos encontrado al probar con datos reales de producción
        (provincia de Córdoba): la misma localidad aparecía dos veces con
        distinta capitalización ("Aguilar De La Frontera" / "Aguilar de la
        Frontera"), y al coincidir ambas en el mismo punto geográfico
        (mismo match en `municipios_es.php`), sus dos rótulos quedaban
        superpuestos exactamente en el mismo sitio — ilegibles/"borrosos".
        `Repo::hubLocalidades()` ahora fusiona variantes de mayúsculas/acentos
        de una misma localidad (agrupa por clave normalizada con `Db::noAcc`,
        suma los recuentos, se queda con la grafía más frecuente como texto
        mostrado) antes de devolver la lista — corrige tanto los puntos del
        mapa como la tabla accesible de abajo, que comparten esta misma
        fuente de datos.
        **Limpieza en origen**: nuevo `app/tools/normalizar_localidades.php`
        (mismo patrón que los demás `migrate_*.php`/`completar_provincia.php`
        del proyecto: backup VACUUM INTO, transacción, re-ejecutable sin
        efecto si ya está limpio). Recorta espacios de más y fusiona
        variantes de mayúsculas/acentos en `marcha.LOCALIDAD`,
        `marcha.PROVINCIA`, `banda.LOCALIDAD` y `banda.PROVINCIA`,
        quedándose con la grafía más usada (empate → prefiere mayúsculas/
        minúsculas mixtas sobre TODO MAYÚSCULAS o todo minúsculas). Pendiente
        de que el usuario lo ejecute contra la BD real (`php
        php/app/tools/normalizar_localidades.php` en el servidor, o con
        `DB_PATH=` apuntando a una copia local) — no se ha tocado la BD de
        producción desde aquí.
        **Ejecutado por el usuario contra producción.** Al revisar la salida
        real (54 filas fusionadas en total) se encontraron 3 fusiones
        equivocadas por el criterio original (más filas → alfabético):
        `"ávila"`(3) le ganó a `"Ávila"`(2) por recuento, y `"Caceres"`/
        `"Huescar"` (sin tilde) le ganaron a `"Cáceres"`/`"Huéscar"` en un
        empate a 1 resuelto alfabéticamente — el orden alfabético no
        distingue "correcto" de "más frecuente". Corregido:
        `normalizar_localidades.php` antepone ahora dos criterios al
        recuento: 1) empezar en mayúscula (un topónimo nunca debería
        perder por esto) y 2) más letras con tilde. Como esas 3 fusiones ya
        estaban aplicadas en la BD real (el script no es una simulación) y
        al estar fusionadas ya no se detectan como "variantes", se añadió
        `app/tools/corregir_acentos_localidad.php` — un corrector puntual,
        mismo patrón de backup/transacción, para esos 3 casos exactos.
        Verificado con datos de prueba reproduciendo el bug: el criterio
        nuevo resuelve bien los 3 casos y no rompe ninguno de los que ya
        acertaba; el corrector puntual es idempotente. 81/81 smoke tests.
        **Unificación de "De/La/Del" a ortografía española (a petición del
        usuario).** Nuevo `app/tools/normalizar_preposiciones_localidad.php`:
        "de"/"del" siempre en minúscula, "la"/"las"/"los" en minúscula salvo
        que sean la primera palabra del nombre (topónimos que empiezan así de
        verdad, tipo "La Línea de la Concepción", no se tocan), "y" siempre
        en minúscula. Solo LOCALIDAD (marcha y banda) — las provincias con
        "de"/"la" ya estaban bien (`Mapa::PROVINCIAS`: "Santa Cruz de
        Tenerife", "La Rioja"…). Verificado con todos los casos reales
        vistos en la ejecución del usuario: todas las variantes convergen a
        la forma correcta, es idempotente.
        **Bug encontrado al probar en un entorno más realista**:
        `dedicatoria_alias` enlaza con `marcha` por texto exacto de
        `(VARIANTE, LOCALIDAD)` — si se renombra `marcha.LOCALIDAD` sin tocar
        también `dedicatoria_alias.LOCALIDAD`, las marchas afectadas
        desaparecen en silencio de la ficha de su dedicatoria (404 si eran
        las únicas). Esto afecta a los tres scripts de renombrado ya
        creados, incluido el que el usuario ya ejecutó en producción. Nuevo
        `app/tools/reconciliar_alias_localidad.php`: en vez de rehacer el
        historial de cada cambio, compara `dedicatoria_alias` contra el
        estado actual de `marcha` (fuente de verdad) y corrige los casos
        1-a-1 sin ambigüedad; los casos con más de un hueco/huérfano a la
        vez se listan para revisión manual en vez de adivinar. **Ejecutar
        siempre después de cualquiera de los otros tres scripts de
        localidad** (incluida la ejecución ya hecha en producción).
        De paso, un problema real encontrado durante las pruebas: los
        cuatro scripts combinan `VACUUM INTO` (backup) con una transacción
        de escritura en la misma conexión; en bases con
        `journal_mode=WAL` (la que deja la app tras usarse) esto podía dejar
        los cambios solo en el `-wal`, invisibles desde otra conexión hasta
        el próximo checkpoint automático — se añadió
        `PRAGMA wal_checkpoint(TRUNCATE)` tras el commit en los cuatro.
        **Vínculo permanente a nivel de BD (a petición del usuario)**: en vez
        de depender de acordarse de ejecutar `reconciliar_alias_localidad.php`
        cada vez, nuevo `app/tools/sql/006_sync_dedicatoria_alias_localidad.sql`
        — un trigger `AFTER UPDATE OF LOCALIDAD ON marcha` que propaga el
        cambio a `dedicatoria_alias.LOCALIDAD` automáticamente, tanto si lo
        dispara un script como una edición manual desde el panel admin. Solo
        actúa en el caso inequívoco (ninguna otra marcha de la misma
        DEDICATORIA sigue usando la localidad vieja, y no hay ya un alias
        para la localidad nueva); el resto de casos se deja para
        `reconciliar_alias_localidad.php`/revisión manual, igual que antes.
        Se aplica con el script ya existente del proyecto,
        `php php/app/tools/migrate_ingest.php` (aplica todos los `.sql` de
        `app/tools/sql/`, `CREATE ... IF NOT EXISTS`, re-ejecutable sin
        riesgo). Verificado: el alias se actualiza solo con un `UPDATE marcha`
        directo (sin llamar a ningún script), no toca nada cuando otra marcha
        de la misma dedicatoria sigue usando la localidad vieja, y la ficha
        de dedicatoria que antes daba 404 vuelve a dar 200. 81/81 smoke
        tests.
- [ ] **Prioridad 5 — Consistencia.** Aplicar la compactación y el patrón de bloques a
      todas las vistas de entidad (compositor, banda, disco) y a home, manteniendo los
      puntos fuertes actuales (breadcrumbs, búsqueda global, "Véase también" con
      recuentos).
