# Revisión de duplicados — 2026-07-31

Fuente: `php/data/mdc.db` (5.004 marchas, 925 autores). Detección por normalización
(minúsculas, sin acentos, sin puntuación, sin artículos/preposiciones).
**Nada se ha modificado en la BD.** Anota en la columna *Decisión* qué ID se mantiene.

---

## A) MARCHAS — mismo título + autor común + mismo año

| # | IDs | Título | Año | Autor(es) | Diferencias | Decisión |
|---|-----|--------|-----|-----------|-------------|----------|
| A1 | **4036** / **4080** | Aquel día… | 2017 | Trujillo Lira, Felipe | 4080 añade a Márquez Salaverri y banda estreno (AM Cristo de Gracia); 4036 tiene duración 240 s | | se queda 4080, añade duración y audios de 4035
| A2 | **4297** / **4659** | Cautivo | 2019 | Vázquez Mateo, Rafael | Idénticas; 4659 sin TIPO | | deja 4297 y añade el audio de 4659
| A3 | **4357** / **4520** | Dios de la Caridad | 2020 | Garfia Moreno, Enrique | Dedicatoria distinta (Agrup Parr Santa Cruz / Hdad Santa Cruz); 4520 sin TIPO | | deja 4357, pero pon la dedicatoria de 4520
| A4 | **4883** / **5052** | La Esperanza | 2019 | Martín Gutiérrez, Josue | Dedicatoria distinta (Hdad Jesús Nazareno / a la madre del autor); 4883 tiene localidad | | 4883, elimina 5052
| A5 | **4846** / **5092** | Latidos de fe | 2025 | Sánchez Crespillo, José Manuel | Idénticas salvo mayúsculas del título | | deja 4846
| A6 | **4809** / **4960** | Rosarium Maris | 2025 | García Pérez, Ignacio José | Idénticas | | deja 4809
| A7 | **1914** / **3371** | A tu memoria | 2005 | 1914 **sin autor** / 3371 Marín Antequera, José Manuel | Misma banda (BCT Salud); dedicatoria «A Juan M. Marín Antequera» vs «A José Manuel Marín Santos»; 1914 tiene duración | | 3371, pero con la duración de 1914

## B) MARCHAS — mismo título + autor común + años consecutivos

| # | IDs | Título | Años | Autor(es) | Diferencias | Decisión |
|---|-----|--------|------|-----------|-------------|----------|
| B1 | **5017** / **5093** | Alma y compás | 2026 / 2025 | López Lomas, Pablo | Idénticas (AM Rosario) | | nos quedamos con la fecha más antigua
| B2 | **4354** / **4516** | Cirineo pa tus penas | 2018 / 2019 | Torres Simón, Francisco Javier | Idénticas; 4354 con duración y TIPO | | deja 5354
| B3 | **3327** / **3645** | Humildad | 2013 / 2012 | Ortiz Morón, Francisco José | 3327 título en minúsculas y sin dedicatoria/localidad | | deja 3645
| B4 | **2373** / **3916** | La última cena | 2013 / 2014 | Torres Muñoz, Esteban | Banda estreno distinta (Varias bandas / AM Estrella); dedicatoria Hdad Sagrada Cena / Hdad La Cena | | deja 3916
| B5 | **5016** / **5089** | Qyamta | 2026 / 2025 | Guerrero Marín, Manuel Jesús | Idénticas (AM Rosario) | | deja la fecha más antigua
| B6 | **4808** / **4956** | Rey de los Ángeles | 2023 / 2024 | Sánchez Crespillo, José Manuel | Idénticas | | deja 4808
| B7 | **4947** / **5167** | Sanguis Christi | 2024 / 2025 | García Caro + Duro Molina | 5167 con dedicatoria y localidad (Arahal) | |
| B8 | **4845** / **4913** | Suspiros al cielo | 2025 / 2026 | Soriano Lorenzo, Alejandro | Idénticas (AM Rosario) | |
| B9 | **4828** / **5087** | Yeshua | 2025 / 2026 | Muñoz Serna, Emilio | Idénticas | |

> Descartados como falsos positivos (títulos distintos que colisionan al quitar
> preposiciones): 639 «En tu memoria» vs 1914 «A tu memoria»; 1912 «A tu memoria»
> (2004, Ortega León) vs 1914; 2143 «Rosario» vs 2149 «A mi Rosario».

## C) MARCHAS — mismo título exacto + mismo autor, pero años distantes (>1)

Puede ser duplicado con año mal metido, o una marcha reeditada/estrenada por otra banda.

| # | IDs | Título | Años | Autor | Diferencias | Decisión |
|---|-----|--------|------|-------|-------------|----------|
| C1 | **247** / **5099** | Amistad | 2003 / 2024 | Alameda Herrador + Murciano Fuentes | Misma banda (BCT Penas); 5099 con dedic. Hdad Sentencia | |
| C2 | **1537** / **1543** | Resurrección | 1997 / 1999 | Velasco Rodríguez, Antonio | Banda distinta (AM La Paz / AM Pasión) | |
| C3 | **3479** / **3781** | Señor de San Esteban | 2002 / 2006 | García Rodríguez, Javier | Misma dedic. (Hdad Dominicana), banda distinta | |
| C4 | **3897** / **4928** | Gólgota | 2014 / 2019 | Águila Ordóñez, Jorge | Banda distinta; 4928 sin dedicatoria | |
| C5 | **2356** / **5064** | Exaltatum | 2012 / 2024 | Jiménez Cañestro, Fernando | Misma dedic. (Hdad Fusionadas), banda distinta | |
| C6 | **2379** / **4925** | Madre del Amor | 2006 / 2026 | López Gándara, Cristóbal | **Misma banda y misma dedicatoria** → muy sospechoso | |
| C7 | **4141** / **4836** | En tu victoria, nuestra fe | 2017 / 2022 | Carmona Suarez, Juan Manuel | Banda distinta; 4836 sin dedicatoria | |
| C8 | **4326** / **4456** | Perdónalos | 2020 / 2022 | Marcial Ortiz, Jorge | **Misma banda (AM Polillas) y misma dedicatoria** → muy sospechoso | |
| C9 | **4552** / **4853** | La luz del amor | 2019 / 2025 | Moreno Rodríguez, Alejandro | Misma dedic. (Hdad Jesús Despojado), banda distinta | |
| C10 | **4592** / **4994** | El beso de la vida | 2020 / 2026 | Sánchez Crespillo, José Manuel | Banda distinta; 4994 sin dedicatoria | |
| C11 | **4885** / **5062** | Vida eterna | 2021 / 2023 | Ortiz Morón, Francisco José | **Misma banda (BCT Carmen)**; 5062 sin dedicatoria | |
| C12 | **4914** / **5146** | Dux noster | 2026 / 2022 | Sancho Gómez, Miguel Ángel | **Misma banda (AM Rosario)** → año mal en uno de los dos | |

---

## D) AUTORES — confianza alta

| # | IDs | Fichas | Motivo | Marchas | Decisión |
|---|-----|--------|--------|---------|----------|
| D1 | **1218** / **1219** | Arias Ramiro, Antonio / Arias Ramiro, Antonio | idénticos | 1 / **0** | |
| D2 | **207** / **455** | Gómez Sánchez, Juan Manuel / Gómez, Juan Manuel | falta apellido | 4 / 1 | |
| D3 | **446** / **611** | González, Rubén / González Ramírez, Rubén | falta apellido | 1 / 2 | |
| D4 | **336** / **446** | González Téllez, Rubén / González, Rubén | falta apellido | 3 / 1 | |
| D5 | **338** / **1084** | Herrera, Alfonso / Herrera, Juan Alfonso | falta nombre | 1 / 1 | |
| D6 | **242** / **486** | López Morcillo, Alvaro José / López Morcillo, Álvaro | falta nombre | 2 / 1 | |
| D7 | **1003** / **1142** | Lora, Daniel / Lora Melero, Daniel | falta apellido | 1 / 1 | |
| D8 | **445** / **447** | Macías Gómez, David / Macías, David | falta apellido | 8 / 1 | |
| D9 | **8** / **1150** | Moreno Álvarez, Diego Alejandro / Moreno Álvarez, Alejandro | falta nombre | 5 / **0** | |
| D10 | **312** / **442** | Puntas, Juan José / Puntas Fernández, Juan José | falta apellido | 1 / 1 | |
| D11 | **1176** / **1233** | Ruíz, Alejandro / Ruiz González, Alejandro | falta apellido (+tilde errónea en 1176) | 2 / 3 | |
| D12 | **1129** / **1238** | Salas, Daniel / Salas Vargas, Daniel | falta apellido | 1 / 1 | |

> Ojo: **446 «González, Rubén»** aparece en D3 y D4 — hay dos candidatos distintos
> (González Ramírez y González Téllez). Su única marcha es *Tres Caídas* (2009),
> que también existe como 336 → *Tres Caídas* (2008) y 447 → *Tres Caídas* (2009).

## E) AUTORES — confianza media (revisar, probablemente personas distintas)

| # | IDs | Fichas | Marchas | Decisión |
|---|-----|--------|---------|----------|
| E1 | 282 / 299 | Blanco Díaz, José Manuel / Blanco, José | 4 / 1 | |
| E2 | 1024 / 1159 | Domínguez Romero, Manuel / Romero, Manuel Ángel | 1 / 1 | |
| E3 | 175 / 1128 | Gálvez Robles, Miguel Ángel / Robles, Miguel | 19 / 1 | |
| E4 | 723 / 1056 | Gambero Sánchez, José / Sánchez, José Antonio | 4 / 1 | |
| E5 | 455 / 556 | Gómez, Juan Manuel / Gómez Moreno, Manuel | 1 / 1 | |
| E6 | 455 / 1107 | Gómez, Juan Manuel / Gómez Monsalve, Manuel | 1 / 1 | |
| E7 | 28 / 1184 | González Cruz, Manuel Alejandro / Cruz, Alejandro | 57 / 1 | |
| E8 | 305 / 652 | Ruiz Sánchez, Ángel / Sánchez, Miguel Ángel | 1 / 1 | |
| E9 | 585 / 1056 | Sánchez López, José (*Padre Josico*) / Sánchez, José Antonio | 1 / 1 | |

---

## Notas

- Los autores con **0 marchas** (1219, 1150) son candidatos obvios a borrar sin
  necesidad de reasignar `marcha_autor`.
- Al fusionar autores hay que reapuntar `marcha_autor.ID_AUTOR` y comprobar que no
  quedan pares duplicados en esa tabla.
- Al fusionar marchas hay que revisar además `disco_marcha`, `enlace_streaming`,
  `enlace_candidato` y `videos`.
