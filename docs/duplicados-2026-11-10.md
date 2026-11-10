# Duplicados de marcha que se colaron — 2026-11-10

> Generado: 2026-11-10

Al revisar los discos de origen de las marchas añadidas con la ingesta DMP
(discografiasdemarchasprocesionales.com) aparecieron cinco marchas creadas
otra vez aunque ya existían. Se fusionaron con
`php/app/tools/fusionar_marchas.php`; se conserva siempre la ficha antigua y la
nueva le cede sus datos (nota de origen en `DATOS_INT`) y sus referencias.

| Se queda | Se borró | Marcha | Vía de alta del duplicado |
|---|---|---|---|
| 3996 | 5539 | Stabat Mater Dolorosa (Marco Frisina, AM Buena Muerte) | Panel de ingesta, candidato DMP #3066 (2026-11-09) |
| 2147 | 5545 | Bendito sea el que viene en nombre del Señor (Ortega León) | Panel de ingesta, candidato DMP #3415 (2026-11-09) |
| 3736 | 5602 | A mi Esperanza (L. S. de Juan Fernández, AM Bienaventuranza) | Panel de ingesta, candidato DMP #3434 (2026-11-10) |
| 3737 | 5603 | A ti Carmelo (L. S. de Juan Fernández, AM Bienaventuranza) | Panel de ingesta, candidato DMP #3435 (2026-11-10) |
| 4054 | 5355 | Coronación (Manuel Roldán Roldán) | Carga masiva de estrenos de 2026 (actor `system`, 2026-10-05, 151 marchas) |

## Por qué no se detectó

**1. La pantalla de aceptar un candidato no comprobaba nada.** El aviso de
posible duplicado (`/api/marcha/checkDuplicate`, autor común + título ≥ 80 %)
solo existía en el formulario de alta/edición de marcha: `admin.js` lo engancha
a un `#duplicateAlert` que `ingesta_detail.php` no tenía. Y
`AdminRepo::aceptarCandidato()` creaba la marcha sin ninguna comprobación en el
servidor. El único aviso posible en esa pantalla era `MATCH_MARCHA_ID`, que
rellena quien importa el candidato, y el importador DMP lo dejó vacío en los
cuatro casos.

Con el criterio de siempre (autor común) los cinco casos daban 100 % o casi
(«Bendito…» 84-91 %). No hacía falta un detector más listo: bastaba con
ejecutarlo donde se crean las marchas.

**2. El cruce del importador DMP depende de que el autor coincida, y el autor
del origen viene con variantes.**
`proponer_discografias*.php` deduplica por título + apellidos del autor contra
la BD, y DMP escribe a los autores de otra forma:

- «De San Juan Fernández, Luis Sergio» frente a «de Juan Fernández» en la BD:
  el autor no se resuelve, la clave título+autor no casa y la marcha se propone
  como nueva (*A mi Esperanza*, *A ti Carmelo*);
- «Frosini Fedi, Marco» frente a «Marco Frisina»: el importador lo marcó como
  `E_homonima_otro_autor` (mismo título, otro autor) y la propuso como nueva,
  aunque la banda de estreno era la misma (*Stabat Mater Dolorosa*);
- «Bendito **el** que viene en **el** nombre…» frente a «Bendito **sea** el que
  viene en nombre…»: la similitud título+autor se quedó bajo el 0,9.

En el panel el revisor sí resolvió bien el autor, pero ahí ya no había ninguna
comprobación (punto 1).

**3. La carga masiva de estrenos de 2026 no deduplicaba.** El 2026-10-05 un
proceso sin script versionado (actor `system` en `cambio_log`) insertó
151 marchas de golpe sin cruzarlas con las existentes. *Coronación* de Manuel
Roldán ya estaba desde 2013 con la AM Cristo de Gracia.

## Qué se ha cambiado

- **Un solo detector**, `AdminRepo::posiblesDuplicadosMarcha()`, con dos vías:
  - autor común + título ≥ 80 % (el criterio de siempre);
  - **misma banda de estreno + título ≥ 90 %**, para cuando el autor del origen
    no se resolvió o se resolvió a otra ficha. La banda 0 («Varias bandas») no
    cuenta.
- **El panel de ingesta avisa** al abrir el candidato (con el título, los
  autores resueltos solos y la banda) y en vivo mientras se edita el
  formulario (título, autores o banda), con el mismo aviso que el formulario
  de marcha.
- **El servidor frena**: `aceptarCandidato()` devuelve `POSIBLE_DUPLICADO` si
  hay coincidencias, salvo que el revisor marque «He revisado las marchas
  parecidas y esta es una marcha distinta». Si es la misma, lo correcto es la
  pestaña «Asociar a marcha existente».
- `/api/marcha/checkDuplicate` usa el mismo detector y acepta `?banda=`, así que
  el formulario de marcha también avisa por banda de estreno.
- Pruebas en `php/tools/ci_duplicados.php` (en CI).

## Pendiente: más duplicados probables

Al pasar el detector nuevo por las 332 marchas creadas desde el 2026-10-05,
comparándolas solo con las anteriores, salen otras 21 coincidencias. Casi todas
son de la carga masiva de estrenos de 2026, y en muchas el título lleva la
grafía latinizante con V (*Hvmilde*, *Salvd*, *Amargvra*, *Jesvs*). **No se han
tocado**: cada una necesita una decisión.

| Nueva | Existente | Títulos |
|---|---|---|
| 5528 | 4086 | Himno al Cristo de Gracia / ídem (misma banda y dedicatoria; la 5528 trae autor, la 4086 «Popular»). El disco «La Santísima Trinidad» enlaza la 4086 |
| 5289 | 4790 | Humilde / Hvmilde |
| 5295 | 4244 | Expiratio / Exspiratio |
| 5331 | 4795 | El juicio de Redentor / El juicio del Redentor |
| 5332 | 4089 | Hágase tu voluntad / ídem (otra banda) |
| 5341 | 4998 | Señor del Gran Poder / Al Señor del Gran Poder |
| 5349 | 907 | Señor de la Humildad / Jesús de la Humildad (probable falso positivo) |
| 5356 | 4561 | El camino de la vida eterna / El camino a la vida eterna |
| 5357 | 4773 | Eterno será tu Amor / Eterno sea tu amor |
| 5359 | 4717 | Al Señor del mercado / Al Señor del mercao |
| 5360 | 4718 | Jesús / Jesvs |
| 5380 | 4732 | Salud de los cautivos / Salud de Cautivos |
| 5381 | 5376 | En el nombre del Mesías / En nombre del Mesías |
| 5389 | 4996 | Senderos de vida / Sendero de Vida |
| 5396 | 5366 | La otra vida / ídem |
| 5397 | 4411 | El camino del Perdón / En el camino del Perdón |
| 5403 | 5076 | Luz del Mundo / La luz del mundo |
| 5406 | 4948 | Salud / Salvd |
| 5410 | 4749 | La devoción de un padre / La Devoción del Padre |
| 5411 | 2881 | El sacramento de nuestra fe / ídem (2012) |
| 5418 | 4763 | Amargura / Amargvra |
| 5550 | 1046 | Rey de Jerusalén / Luz de Jerusalén (probable falso positivo) |

Para fusionar los que se confirmen:
`php php/app/tools/fusionar_marchas.php --pares=CONSERVAR:BORRAR,... [--dry-run]`.
