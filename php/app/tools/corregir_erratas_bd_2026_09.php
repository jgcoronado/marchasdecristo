<?php

declare(strict_types=1);

/*
 * Corrección de erratas en toda la BD (revisión de septiembre de 2026).
 *
 * Salen de una pasada completa por los campos de texto visibles (marchas,
 * autores, bandas, discos, dedicatorias, pasos y contratos): tildes que faltan,
 * letras cambiadas, espacios sobrantes, títulos escritos enteros en mayúsculas...
 * El usuario revisó la lista numerada (1-94) y aceptó todo salvo los apellidos
 * dudosos 67-70 (Hedrera, Rondán, Gacías, Cristopher), que NO se tocan. El
 * número de cada cambio se conserva en la primera columna de $CAMBIOS.
 *
 * Queda fuera a propósito, porque el código lo usa como clave o es estilo:
 *  - hermandad/contrato_localidad 'Cadiz'/'Cordoba'/'Malaga' (MalagaBlogImporter::LOCALIDAD…),
 *    TITULAR 'Cruz de Guia' y los nombres de semana_santa_dia (se cruzan por texto con hermandad.DIA);
 *  - las notas banda_raw=… de contrato (texto crudo de la fuente);
 *  - la "V" romana deliberada (Jesvs, Amargvra, Salvd), latinismos y andalucismos (Queó, Resucitao).
 *
 * Además de los UPDATE campo a campo:
 *  - 64: fusiona el autor #349 "Montesionos Fabre" en #267 "Montesinos Fabre";
 *  - 65: fusiona #252 "Peñeranda González" (sin marchas) en #657 "Peñaranda González";
 *  - 59: borra el municipio no oficial #8113 "Alcalá de Guadaría" si ya nadie lo usa;
 *  - 93/94: contrato.TITULAR y contrato.HERMANDAD se corrigen por valor exacto.
 *
 * marcha.DEDICATORIA se enlaza con su canónica por dedicatoria_alias
 * (VARIANTE, LOCALIDAD) y ese enlace es texto: al corregir la dedicatoria de una
 * marcha se crea el alias con el texto nuevo apuntando a la misma canónica y se
 * borra el viejo cuando ya ninguna marcha lo usa. Los cambios de LOCALIDAD los
 * resuelve el trigger trg_marcha_localidad_sync_alias; por eso en $CAMBIOS la
 * DEDICATORIA de una marcha va siempre antes que su LOCALIDAD.
 *
 * Re-ejecutable: cada fila solo se actualiza si sigue teniendo el valor viejo
 * exacto; lo ya corregido (aquí o a mano) no se pisa. Pensado para lanzarse
 * también en el host, cuya BD tiene escrituras propias.
 *
 * Uso:
 *   php php/app/tools/corregir_erratas_bd_2026_09.php --dry-run
 *   php php/app/tools/corregir_erratas_bd_2026_09.php
 *
 * Hace copia de seguridad (VACUUM INTO) antes de tocar nada, solo si hay algo
 * que corregir.
 */

require __DIR__ . '/_cli.php';
[, $db] = cliBootstrap('Corrección abortada');

$dryRun = in_array('--dry-run', $argv, true);

const PK = [
    'marcha' => 'ID_MARCHA', 'autor' => 'ID_AUTOR', 'banda' => 'ID_BANDA',
    'disco' => 'ID_DISCO', 'dedicatoria' => 'ID_DEDIC', 'paso' => 'ID_PASO',
    'municipio' => 'ID_MUNICIPIO',
];

/** [nº de la lista, valor viejo exacto, valor nuevo] aplicados a todas las filas con ese valor. */
const CONTRATO_POR_VALOR = [
    'TITULAR' => [
        ['93', 'Nuestro Padre Jesús de la Fé en su Sagrada Cena', 'Nuestro Padre Jesús de la Fe en su Sagrada Cena'],
    ],
    'HERMANDAD' => [
        ['94', 'Divino Perdon de Alcosa', 'Divino Perdón de Alcosa'],
        ['94', 'Jesus Despojado', 'Jesús Despojado'],
        ['94', 'La Resurreccion', 'La Resurrección'],
        ['94', 'San Jose Obrero', 'San José Obrero'],
        ['94', 'Senor de la Humillacion', 'Señor de la Humillación'],
    ],
];

/** [nº, autor duplicado, autor que se conserva]. */
const FUSIONES_AUTOR = [['64', 349, 267], ['65', 252, 657]];

/*
 * [nº, canónica duplicada, canónica que se conserva]. Eran la misma dedicatoria
 * separada solo por la errata (Sevila, Cigarerras, Esperaza, San Fernand,
 * Guadaría); al corregirla quedan dos canónicas idénticas. Se conserva la que
 * tenía el SLUG_KEY bien escrito y se le pasan los alias de la otra.
 */
const FUSIONES_DEDIC = [['59', 515, 514], ['60', 617, 618], ['45', 825, 826], ['58', 850, 851], ['47', 958, 957]];

const MUNICIPIO_ERRATA = 8113; // 'Alcalá de Guadaría', OFICIAL=0

/*
 * [nº, tabla, id, campo, valor viejo exacto, valor nuevo]. null = vaciar el campo.
 * "mayús" = título que estaba entero en mayúsculas.
 */
/** @var list<array{0:string,1:string,2:int,3:string,4:?string,5:?string}> $CAMBIOS */
$CAMBIOS = json_decode(<<<'JSON'
[
["1", "marcha", 3142, "TITULO", "Juzgo, condejo y sentencio", "Juzgo, condeno y sentencio"],
["2", "marcha", 3827, "TITULO", "Y fue crucuficado", "Y fue crucificado"],
["3", "marcha", 3999, "TITULO", "Jesús Orando en el Hueto", "Jesús Orando en el Huerto"],
["4", "marcha", 3795, "TITULO", "A Jesús de la Misericorida", "A Jesús de la Misericordia"],
["5", "marcha", 5076, "TITULO", "La luz del munto", "La luz del mundo"],
["6", "marcha", 3037, "TITULO", "Capataz de nuestas vidas", "Capataz de nuestras vidas"],
["7", "marcha", 2846, "TITULO", "Bajo tu mirada, Señor de Jeruslaén", "Bajo tu mirada, Señor de Jerusalén"],
["8", "marcha", 2208, "TITULO", "Señor del Moviedro", "Señor de Molviedro"],
["9", "marcha", 3943, "TITULO", "MISEROCORDIAM TUAM", "Misericordiam tuam"],
["10", "marcha", 3449, "TITULO", "SEÑOR DE LOS LÍRIOS", "Señor de los Lirios"],
["11", "marcha", 2497, "TITULO", "Ángeles de San Sebastián ", "Ángeles de San Sebastián"],
["11", "marcha", 3148, "TITULO", "Perdón y Consuelo ", "Perdón y Consuelo"],
["11", "marcha", 4183, "TITULO", "Cirineo en tus penas ", "Cirineo en tus penas"],
["12", "marcha", 674, "TITULO", "Entrada en Jerusalen", "Entrada en Jerusalén"],
["12", "marcha", 675, "TITULO", "Entrada en Jerusalen", "Entrada en Jerusalén"],
["12", "marcha", 2512, "TITULO", "¡A Jerusalen contigo!", "¡A Jerusalén contigo!"],
["13", "marcha", 28, "TITULO", "Ahí viene el Rey de los Judios", "Ahí viene el Rey de los Judíos"],
["14", "marcha", 3670, "TITULO", "Legion blanca de Dios", "Legión blanca de Dios"],
["15", "marcha", 1356, "TITULO", "Pasión por Bulerias", "Pasión por Bulerías"],
["16", "marcha", 1380, "TITULO", "Penas y alegrias de mi Macarena", "Penas y alegrías de mi Macarena"],
["17", "marcha", 1732, "TITULO", "Señor Acuerdate de Mí", "Señor Acuérdate de Mí"],
["18", "marcha", 561, "TITULO", "Devocion", "Devoción"],
["19", "marcha", 63, "TITULO", "Al compas de la Laguna", "Al compás de la Laguna"],
["19", "marcha", 70, "TITULO", "Al Compas del Amor", "Al Compás del Amor"],
["20", "marcha", 708, "TITULO", "Esperanza en tí, Cautivo", "Esperanza en ti, Cautivo"],
["20", "marcha", 1178, "TITULO", "Mis sones para tí Señor", "Mis sones para ti Señor"],
["20", "marcha", 1860, "TITULO", "A tí Nazareno", "A ti Nazareno"],
["20", "marcha", 1864, "TITULO", "A tí, Cristo de los Remedios", "A ti, Cristo de los Remedios"],
["20", "marcha", 2564, "TITULO", "Llora por tí, Cautivo", "Llora por ti, Cautivo"],
["20", "marcha", 3391, "TITULO", "Pa tí Señor... tus sones flamencos", "Pa ti Señor... tus sones flamencos"],
["20", "marcha", 1339, "TITULO", "Para Tí, Hermano", "Para Ti, Hermano"],
["21", "marcha", 1862, "TITULO", "A Tí, Asuncion", "A Ti, Asunción"],
["22", "marcha", 2190, "TITULO", "Imaginero de fé", "Imaginero de fe"],
["23", "marcha", 3207, "TITULO", "... y Campillos te vió nacer", "... y Campillos te vio nacer"],
["24", "marcha", 3486, "TITULO", "Y Jesús fué Despojado", "Y Jesús fue Despojado"],
["25", "marcha", 96, "TITULO", "Al Gitano de Santa Maria", "Al Gitano de Santa María"],
["25", "marcha", 308, "TITULO", "Angustias de Maria", "Angustias de María"],
["25", "marcha", 1106, "TITULO", "Maria de Magdala", "María de Magdala"],
["25", "marcha", 1112, "TITULO", "Maria Santisima de las Angustias", "María Santísima de las Angustias"],
["25", "marcha", 1113, "TITULO", "Maria Santisima del Rocio", "María Santísima del Rocío"],
["25", "marcha", 1676, "TITULO", "Santa Maria De La Esperanza", "Santa María De La Esperanza"],
["25", "marcha", 3948, "TITULO", "MARIA DEL CARMEN", "María del Carmen"],
["26", "marcha", 3497, "TITULO", "AL PADRE JESUS", "Al Padre Jesús"],
["26", "marcha", 362, "TITULO", "Corazon de Jesus", "Corazón de Jesús"],
["26", "marcha", 423, "TITULO", "Creo en Jesus", "Creo en Jesús"],
["26", "marcha", 899, "TITULO", "Jesus Condenado a Muerte", "Jesús Condenado a Muerte"],
["26", "marcha", 957, "TITULO", "Jesus Ora en Getsemaní", "Jesús Ora en Getsemaní"],
["26", "marcha", 886, "TITULO", "Jesus ante Caifás", "Jesús ante Caifás"],
["26", "marcha", 901, "TITULO", "Jesus de la Caridad", "Jesús de la Caridad"],
["26", "marcha", 931, "TITULO", "Jesus del Gran Poder", "Jesús del Gran Poder"],
["26", "marcha", 964, "TITULO", "Jesus, Danos tu Salvación", "Jesús, Danos tu Salvación"],
["26", "marcha", 912, "TITULO", "Jesus de la Redención", "Jesús de la Redención"],
["26", "marcha", 909, "TITULO", "Jesus de la paz", "Jesús de la Paz"],
["26", "marcha", 911, "TITULO", "Jesus de la Presentacion", "Jesús de la Presentación"],
["26", "marcha", 1257, "TITULO", "Nuestro Padre Jesus de Guia", "Nuestro Padre Jesús de Guía"],
["27", "marcha", 3448, "TITULO", "CAIDO EN TU AMARGURA", "Caído en tu amargura"],
["27", "marcha", 3248, "TITULO", "Caido Bajo la Cruz", "Caído Bajo la Cruz"],
["27", "marcha", 91, "TITULO", "Caido vas por Triana", "Caído vas por Triana"],
["27", "marcha", 1132, "TITULO", "Mi Cristo Caido", "Mi Cristo Caído"],
["27", "marcha", 1133, "TITULO", "Mi Cristo Caido", "Mi Cristo Caído"],
["28", "marcha", 3491, "TITULO", "PASION EN EL CIELO", "Pasión en el cielo"],
["28", "marcha", 1349, "TITULO", "Pasion Cofrade", "Pasión Cofrade"],
["28", "marcha", 1359, "TITULO", "Pasion por la Cruz", "Pasión por la Cruz"],
["28", "marcha", 1747, "TITULO", "Señor De Pasion", "Señor De Pasión"],
["28", "marcha", 1826, "TITULO", "Sones de la Pasion", "Sones de la Pasión"],
["28", "marcha", 367, "TITULO", "Aurora De Resurreccion", "Aurora De Resurrección"],
["28", "marcha", 1829, "TITULO", "Sones de Resurreccion", "Sones de Resurrección"],
["28", "marcha", 3492, "TITULO", "TRIUNFO EN TU RESURRECCION", "Triunfo en tu Resurrección"],
["29", "marcha", 1290, "TITULO", "La Oracion en el Huerto", "La Oración en el Huerto"],
["29", "marcha", 1280, "TITULO", "Oracion al Alba", "Oración al Alba"],
["29", "marcha", 1285, "TITULO", "Oracion de Gloria", "Oración de Gloria"],
["29", "marcha", 3494, "TITULO", "ORACION DE GLORIA", "Oración de Gloria"],
["30", "marcha", 471, "TITULO", "Cristo de San Julian", "Cristo de San Julián"],
["30", "marcha", 480, "TITULO", "Cristo del Perdon", "Cristo del Perdón"],
["30", "marcha", 1922, "TITULO", "Tu Perdon al Cielo", "Tu Perdón al Cielo"],
["30", "marcha", 98, "TITULO", "Cáliz de Salvacion", "Cáliz de Salvación"],
["30", "marcha", 3489, "TITULO", "DE SENTENCIA Y ESPERANZA SON TUS LAGRIMAS, MACARENA", "De Sentencia y Esperanza son tus lágrimas, Macarena"],
["30", "marcha", 1935, "TITULO", "Tus Lagrimas", "Tus Lágrimas"],
["30", "marcha", 3490, "TITULO", "EN TUS SONES, ENCARNACION", "En tus sones, Encarnación"],
["30", "marcha", 285, "TITULO", "El Angel", "El Ángel"],
["30", "marcha", 1082, "TITULO", "Madre y Señora de Consolacion", "Madre y Señora de Consolación"],
["30", "marcha", 1243, "TITULO", "Nuestra Señora de Consolacion y Lagrimas", "Nuestra Señora de Consolación y Lágrimas"],
["30", "marcha", 1245, "TITULO", "Nuestra Señora de Guia", "Nuestra Señora de Guía"],
["30", "marcha", 1464, "TITULO", "Presentacion En San Benito", "Presentación En San Benito"],
["30", "marcha", 1623, "TITULO", "Salud de San Nicolas", "Salud de San Nicolás"],
["30", "marcha", 1685, "TITULO", "Santisimo Cristo de la Sagrada Cena", "Santísimo Cristo de la Sagrada Cena"],
["30", "marcha", 782, "TITULO", "Gitano De San Roman", "Gitano De San Román"],
["mayús", "marcha", 2282, "TITULO", "PIETÁ, IN AMORE AFFLICTA", "Pietà, in amore afflicta"],
["mayús", "marcha", 2288, "TITULO", "AL SEÑOR DE SAN AGUSTÍN", "Al Señor de San Agustín"],
["mayús", "marcha", 2289, "TITULO", "EL AZOTE", "El azote"],
["mayús", "marcha", 2292, "TITULO", "MELODÍAS DE ENSUEÑO", "Melodías de ensueño"],
["mayús", "marcha", 2544, "TITULO", "TUS MANOS MORENAS", "Tus manos morenas"],
["mayús", "marcha", 3445, "TITULO", "RESPLANDOR EN OTERO", "Resplandor en Otero"],
["mayús", "marcha", 3446, "TITULO", "A LA LUZ DE TU CONSUELO", "A la luz de tu Consuelo"],
["mayús", "marcha", 3447, "TITULO", "HERIDAS DE AMOR", "Heridas de amor"],
["mayús", "marcha", 3450, "TITULO", "SAN JUAN DE DIOS", "San Juan de Dios"],
["mayús", "marcha", 3451, "TITULO", "ESPERANZA DEL ALMANZORA", "Esperanza del Almanzora"],
["mayús", "marcha", 3452, "TITULO", "EN TU CUNA DE ARPILLERA", "En tu cuna de arpillera"],
["mayús", "marcha", 3453, "TITULO", "DESDE EL CIELO A MI HERMANDAD", "Desde el cielo a mi Hermandad"],
["mayús", "marcha", 3493, "TITULO", "MISERICORDIA Y PIEDAD", "Misericordia y Piedad"],
["mayús", "marcha", 3495, "TITULO", "POR TU AMOR, TE DESPOJARON", "Por tu amor, te despojaron"],
["mayús", "marcha", 3496, "TITULO", "A LOS PIES DE TU CRUZ", "A los pies de tu Cruz"],
["mayús", "marcha", 3498, "TITULO", "MADRE DE LOS DOLORES", "Madre de los Dolores"],
["mayús", "marcha", 3751, "TITULO", "PAZ EN SANTA MARÍA", "Paz en Santa María"],
["mayús", "marcha", 3942, "TITULO", "NOCHE BLANCA DE UN NAZARENO", "Noche blanca de un Nazareno"],
["mayús", "marcha", 3944, "TITULO", "EL ANDAR DE MI SEÑOR", "El andar de mi Señor"],
["mayús", "marcha", 3945, "TITULO", "LA ESPERANZA DE LA VIDA", "La Esperanza de la vida"],
["mayús", "marcha", 3946, "TITULO", "DE SANGRE MORENA", "De sangre morena"],
["mayús", "marcha", 3947, "TITULO", "DE ALMA BLANCA", "De alma blanca"],
["mayús", "marcha", 3949, "TITULO", "ESCAPULARIO DE SALUD", "Escapulario de Salud"],
["mayús", "marcha", 3950, "TITULO", "PATMOS", "Patmos"],
["mayús", "marcha", 3951, "TITULO", "JUNTO A TI", "Junto a ti"],
["mayús", "marcha", 3952, "TITULO", "EN TU SUDARIO DE ESPERANZA", "En tu sudario de Esperanza"],
["mayús", "marcha", 3953, "TITULO", "A TI, MI VIRGEN DE GRACIA", "A ti, mi Virgen de Gracia"],
["mayús", "marcha", 3954, "TITULO", "TU CARIDAD, NAZARENO", "Tu Caridad, Nazareno"],
["mayús", "marcha", 3955, "TITULO", "UN MANTO DE ESTRELLAS", "Un manto de estrellas"],
["mayús", "marcha", 3956, "TITULO", "PORTADORES DE FE", "Portadores de fe"],
["mayús", "marcha", 3957, "TITULO", "AL HERMANO MAYOR", "Al Hermano Mayor"],
["mayús", "marcha", 3958, "TITULO", "AMOR EN SOLEDAD", "Amor en Soledad"],
["mayús", "marcha", 4033, "TITULO", "SOY DE TI SEÑOR", "Soy de ti Señor"],
["mayús", "marcha", 4034, "TITULO", "LEGIONARIOS DE UNA FE, LOS HIJOS DEL PERDÓN", "Legionarios de una fe, los hijos del Perdón"],
["mayús", "marcha", 4035, "TITULO", "EN TU MIRADA DE ESPERANZA", "En tu mirada de Esperanza"],
["mayús", "marcha", 4036, "TITULO", "AQUEL DÍA...", "Aquel día..."],
["mayús", "marcha", 4292, "TITULO", "LA MÚSICA QUE TE REZA", "La música que te reza"],
["mayús", "marcha", 5121, "TITULO", "Y TE NEGARON TRES VECES", "Y te negaron tres veces"],
["31", "marcha", 351, "DETALLES_MARCHA", " Dedicada a Las Cigarreras por su XXV Aniversao, a su Dirección Musical y a Antonio González Ríos.", "Dedicada a Las Cigarreras por su XXV Aniversario, a su Dirección Musical y a Antonio González Ríos."],
["32", "marcha", 1737, "DETALLES_MARCHA", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"...Humildad\".Dedicara a la figura del nazareno de Juan Manuel Miñarro que no procesiona", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"...Humildad\". Dedicada a la figura del nazareno de Juan Manuel Miñarro que no procesiona"],
["33", "marcha", 197, "DETALLES_MARCHA", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"...Humildad\"", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"...Humildad\""],
["33", "marcha", 392, "DETALLES_MARCHA", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"Siempre de Frente\".Dedicada a mi abuelo, que fue un gran costalero de nuestra Semana Santa", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"Siempre de Frente\". Dedicada a mi abuelo, que fue un gran costalero de nuestra Semana Santa"],
["33", "marcha", 1775, "DETALLES_MARCHA", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"Siempre de Frente\"", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"Siempre de Frente\""],
["33", "marcha", 623, "DETALLES_MARCHA", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"...Humildad\"", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"...Humildad\""],
["33", "marcha", 1480, "DETALLES_MARCHA", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"Siempre de Frente\"", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"Siempre de Frente\""],
["33", "marcha", 1546, "DETALLES_MARCHA", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"Siempre de Frente\"", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"Siempre de Frente\""],
["33", "marcha", 1551, "DETALLES_MARCHA", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"...Humildad\"", "Incluida en el primer trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"...Humildad\""],
["33", "marcha", 1565, "DETALLES_MARCHA", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad , titulado \"Siempre de Frente\"", "Incluida en el segundo trabajo discográfico de la banda Nuestro Padre Jesús de la Humildad, titulado \"Siempre de Frente\""],
["34", "marcha", 3651, "DETALLES_MARCHA", "Dedicada a la Agrupación Musical \"Santa Cecila\" de Sevilla con motivo de su V aniversario fundacional.", "Dedicada a la Agrupación Musical \"Santa Cecilia\" de Sevilla con motivo de su V aniversario fundacional."],
["35", "marcha", 3583, "DETALLES_MARCHA", "Marcha interpretada en concierto por la Banda de la Sagrada Lanzada de Sevilla en mayo de 1980. Es posible que se corresponda con una marcha de Francisco Arroyo que posteriormente interpretaría la Centura Macarena, o quizás una adaptación de la marcha de palio \"Virgen de las Aguas\".", "Marcha interpretada en concierto por la Banda de la Sagrada Lanzada de Sevilla en mayo de 1980. Es posible que se corresponda con una marcha de Francisco Arroyo que posteriormente interpretaría la Centuria Macarena, o quizás una adaptación de la marcha de palio \"Virgen de las Aguas\"."],
["36", "marcha", 4399, "DETALLES_MARCHA", "Dedicada al 125 aniversario de la reorganización de la Centuria Romanan Macarena", "Dedicada al 125 aniversario de la reorganización de la Centuria Romana Macarena"],
["37", "marcha", 3586, "DETALLES_MARCHA", "Marcha (no se especifica si de paso lento u ordinario) estrenada por la Banda de Cornetas y Tambores de la Policía Municipal de Sevilla, según reza el peródico ABC de Sevilla del 21/XII/1979.", "Marcha (no se especifica si de paso lento u ordinario) estrenada por la Banda de Cornetas y Tambores de la Policía Municipal de Sevilla, según reza el periódico ABC de Sevilla del 21/XII/1979."],
["38", "marcha", 961, "DETALLES_MARCHA", "Dedicado al Santísmo Sacramento, titular de la Hermandad de San Benito.", "Dedicado al Santísimo Sacramento, titular de la Hermandad de San Benito."],
["39", "marcha", 3937, "DETALLES_MARCHA", "Versión para banda basada en el popular \"Pescador de Hombres\" (Cesareo Gabaráin, 1979) para banda de música.", "Versión para banda basada en el popular \"Pescador de Hombres\" (Cesáreo Gabaráin, 1979) para banda de música."],
["40", "marcha", 4144, "DETALLES_MARCHA", "Dedicada a la memoria de la abuela de Grabriel Escabias Expósito, miembro de la formación.", "Dedicada a la memoria de la abuela de Gabriel Escabias Expósito, miembro de la formación."],
["41", "marcha", 380, "DETALLES_MARCHA", "\r\n", null],
["41", "marcha", 483, "DETALLES_MARCHA", "Esta marcha fue compuesta en un principio para la banda de las Cigarreras, en los primeros años de la formación, cuando interpretaba marchas de todos los estilos. ", "Esta marcha fue compuesta en un principio para la banda de las Cigarreras, en los primeros años de la formación, cuando interpretaba marchas de todos los estilos."],
["42", "marcha", 2315, "DEDICATORIA", "A La Agruapción Musical Mixta Plaza De Roma De Montequinto", "A La Agrupación Musical Mixta Plaza De Roma De Montequinto"],
["42", "dedicatoria", 110, "NOMBRE", "A La Agruapción Musical Mixta Plaza De Roma De Montequinto", "A La Agrupación Musical Mixta Plaza De Roma De Montequinto"],
["43", "marcha", 3735, "DEDICATORIA", "A La Agupación Musical Virgen De La Amargura De Ferrol Y Cofr Humildad", "A La Agrupación Musical Virgen De La Amargura De Ferrol Y Cofr Humildad"],
["43", "dedicatoria", 138, "NOMBRE", "A La Agupación Musical Virgen De La Amargura De Ferrol Y Cofr Humildad", "A La Agrupación Musical Virgen De La Amargura De Ferrol Y Cofr Humildad"],
["44", "marcha", 3453, "DEDICATORIA", "AJorge Luís Casas Heredia Y Hdad Amargura", "A Jorge Luis Casas Heredia Y Hdad Amargura"],
["44", "dedicatoria", 348, "NOMBRE", "AJorge Luís Casas Heredia Y Hdad Amargura", "A Jorge Luis Casas Heredia Y Hdad Amargura"],
["45", "marcha", 372, "DEDICATORIA", "Hdad Las Cigarerras", "Hdad Las Cigarreras"],
["45", "dedicatoria", 825, "NOMBRE", "Las Cigarerras", "Las Cigarreras"],
["46", "marcha", 2777, "DEDICATORIA", "Corf Santa Marta", "Cofr Santa Marta"],
["47", "marcha", 3420, "DEDICATORIA", "Hdad Pobre Y Esperaza", "Hdad Pobre Y Esperanza"],
["47", "dedicatoria", 958, "NOMBRE", "Pobre Y Esperaza", "Pobre Y Esperanza"],
["48", "marcha", 1267, "DEDICATORIA", "A La Familia Fernándes Fuente", "A La Familia Fernández Fuente"],
["48", "dedicatoria", 178, "NOMBRE", "A La Familia Fernándes Fuente", "A La Familia Fernández Fuente"],
["49", "marcha", 871, "DEDICATORIA", "A Mercedes Guitérrez Ureña", "A Mercedes Gutiérrez Ureña"],
["49", "dedicatoria", 289, "NOMBRE", "A Mercedes Guitérrez Ureña", "A Mercedes Gutiérrez Ureña"],
["50", "marcha", 3939, "DEDICATORIA", "Hdad Del Ecce Hommo De Aspe (Alicante)", "Hdad Del Ecce Homo De Aspe (Alicante)"],
["50", "dedicatoria", 585, "NOMBRE", "Del Ecce Hommo De Aspe (Alicante)", "Del Ecce Homo De Aspe (Alicante)"],
["51", "marcha", 4183, "DEDICATORIA", "A La Agrupación Musical \"Jeús Nazareno\" De La Algaba.", "A La Agrupación Musical \"Jesús Nazareno\" De La Algaba."],
["51", "dedicatoria", 117, "NOMBRE", "A La Agrupación Musical \"Jeús Nazareno\" De La Algaba.", "A La Agrupación Musical \"Jesús Nazareno\" De La Algaba."],
["52", "marcha", 3182, "DEDICATORIA", "A La Agrupación Musical \"Buena Muerte", "A La Agrupación Musical \"Buena Muerte\""],
["52", "dedicatoria", 112, "NOMBRE", "A La Agrupación Musical \"Buena Muerte", "A La Agrupación Musical \"Buena Muerte\""],
["53", "marcha", 3947, "DEDICATORIA", "Vera Cruz,Los Blancos.", "Vera Cruz, Los Blancos"],
["53", "dedicatoria", 1206, "NOMBRE", "Vera Cruz,Los Blancos.", "Vera Cruz, Los Blancos"],
["54", "marcha", 3277, "DEDICATORIA", "Hdad  Jesús Nazareno", "Hdad Jesús Nazareno"],
["55", "marcha", 335, "DEDICATORIA", "Hdad Sentencia ", "Hdad Sentencia"],
["55", "marcha", 351, "DEDICATORIA", " A La Banda De Las Cigarreras", "A La Banda De Las Cigarreras"],
["55", "marcha", 2359, "DEDICATORIA", " Al P. Tiburcio Arnáiz Muñoz", "Al P. Tiburcio Arnáiz Muñoz"],
["55", "marcha", 2566, "DEDICATORIA", "A Los Miembros De La Agrupación Musical ", "A Los Miembros De La Agrupación Musical"],
["55", "marcha", 3073, "DEDICATORIA", "A La Banda De Cornetas Y Tambores ", "A La Banda De Cornetas Y Tambores"],
["55", "marcha", 3597, "DEDICATORIA", "A La Banda De Cornetas Y Tambores ", "A La Banda De Cornetas Y Tambores"],
["55", "marcha", 3954, "DEDICATORIA", "Hdad De Jesus Nazareno ", "Hdad De Jesús Nazareno"],
["55", "marcha", 36, "DEDICATORIA", " ", null],
["56", "marcha", 40, "DEDICATORIA", "A Alba Del Rocio Roman Y Hdad Santa Cruz", "A Alba Del Rocío Román Y Hdad Santa Cruz"],
["56", "dedicatoria", 7, "NOMBRE", "A Alba Del Rocio Roman Y Hdad Santa Cruz", "A Alba Del Rocío Román Y Hdad Santa Cruz"],
["56", "marcha", 589, "DEDICATORIA", "A Carlos Gutierrez Y Pablo León", "A Carlos Gutiérrez Y Pablo León"],
["56", "dedicatoria", 23, "NOMBRE", "A Carlos Gutierrez Y Pablo León", "A Carlos Gutiérrez Y Pablo León"],
["56", "marcha", 3941, "DEDICATORIA", "A Jose Hidalgo Lopez", "A José Hidalgo López"],
["56", "dedicatoria", 76, "NOMBRE", "A Jose Hidalgo Lopez", "A José Hidalgo López"],
["56", "marcha", 3958, "DEDICATORIA", "A La Cofradia De La Soledad", "A La Cofradía De La Soledad"],
["56", "marcha", 2415, "DEDICATORIA", "Cofradia De Los Ramos", "Cofradía De Los Ramos"],
["56", "dedicatoria", 175, "NOMBRE", "A La Cofradia De La Soledad", "A La Cofradía De La Soledad"],
["56", "marcha", 3455, "DEDICATORIA", "A Los Músicos De La Agrupacion Musical \"Cristo De Las Aguas\"", "A Los Músicos De La Agrupación Musical \"Cristo De Las Aguas\""],
["56", "dedicatoria", 259, "NOMBRE", "A Los Músicos De La Agrupacion Musical \"Cristo De Las Aguas\"", "A Los Músicos De La Agrupación Musical \"Cristo De Las Aguas\""],
["56", "marcha", 3984, "DEDICATORIA", "A Maria Del Carmen Cantero", "A María Del Carmen Cantero"],
["56", "dedicatoria", 283, "NOMBRE", "A Maria Del Carmen Cantero", "A María Del Carmen Cantero"],
["56", "marcha", 204, "DEDICATORIA", "A Ntro. Padre Jesus Cautivo", "A Ntro. Padre Jesús Cautivo"],
["56", "marcha", 3944, "DEDICATORIA", "Hdad De Jesus Nazareno", "Hdad De Jesús Nazareno"],
["56", "marcha", 3949, "DEDICATORIA", "Jesus De La Salud", "Jesús De La Salud"],
["56", "dedicatoria", 302, "NOMBRE", "A Ntro. Padre Jesus Cautivo", "A Ntro. Padre Jesús Cautivo"],
["56", "dedicatoria", 574, "NOMBRE", "De Jesus Nazareno", "De Jesús Nazareno"],
["56", "dedicatoria", 575, "NOMBRE", "De Jesus Nazareno", "De Jesús Nazareno"],
["56", "dedicatoria", 1184, "NOMBRE", "Jesus De La Salud", "Jesús De La Salud"],
["56", "marcha", 3730, "DEDICATORIA", "Hdad Oracion En El Huerto", "Hdad Oración En El Huerto"],
["56", "dedicatoria", 899, "NOMBRE", "Oracion En El Huerto", "Oración En El Huerto"],
["56", "marcha", 3948, "DEDICATORIA", "Maria Del Carmen Rodriguez", "María Del Carmen Rodríguez"],
["56", "dedicatoria", 1186, "NOMBRE", "Maria Del Carmen Rodriguez", "María Del Carmen Rodríguez"],
["56", "marcha", 1938, "DEDICATORIA", "Stmo Cristo De La Expiracion", "Stmo Cristo De La Expiración"],
["56", "marcha", 2482, "DEDICATORIA", "Stmo Cristo De La Expiracion", "Stmo Cristo De La Expiración"],
["56", "dedicatoria", 1202, "NOMBRE", "Cristo De La Expiracion", "Cristo De La Expiración"],
["57", "marcha", 3947, "LOCALIDAD", "Alcalà del Valle", "Alcalá del Valle"],
["57", "dedicatoria", 1206, "LOCALIDAD", "Alcalà Del Valle", "Alcalá del Valle"],
["58", "marcha", 3117, "LOCALIDAD", "San Fernand", "San Fernando"],
["58", "marcha", 3127, "LOCALIDAD", "San Fernand", "San Fernando"],
["58", "dedicatoria", 850, "LOCALIDAD", "San Fernand", "San Fernando"],
["59", "marcha", 4345, "LOCALIDAD", "Alcalá de Guadaría", "Alcalá de Guadaíra"],
["59", "dedicatoria", 515, "LOCALIDAD", "Alcalá De Guadaría", "Alcalá de Guadaíra"],
["59", "dedicatoria", 514, "LOCALIDAD", "Alcalá De Guadaira", "Alcalá de Guadaíra"],
["59", "dedicatoria", 1007, "LOCALIDAD", "Alcalá De Guadaira", "Alcalá de Guadaíra"],
["59", "banda", 65, "LOCALIDAD", "Alcalá de Guadaira", "Alcalá de Guadaíra"],
["59", "banda", 109, "LOCALIDAD", "Alcalá de Guadaira", "Alcalá de Guadaíra"],
["59", "banda", 159, "LOCALIDAD", "Alcalá de Guadaira", "Alcalá de Guadaíra"],
["60", "dedicatoria", 617, "LOCALIDAD", "Sevila", "Sevilla"],
["61", "marcha", 2415, "LOCALIDAD", "Caceres", "Cáceres"],
["61", "marcha", 3774, "LOCALIDAD", "Caceres", "Cáceres"],
["61", "dedicatoria", 405, "LOCALIDAD", "Caceres", "Cáceres"],
["61", "marcha", 2490, "LOCALIDAD", "Huescar", "Huéscar"],
["61", "marcha", 4003, "LOCALIDAD", "Huescar", "Huéscar"],
["61", "dedicatoria", 1178, "LOCALIDAD", "Huescar", "Huéscar"],
["61", "dedicatoria", 577, "LOCALIDAD", "Cordoba", "Córdoba"],
["61", "dedicatoria", 302, "LOCALIDAD", "Moron De La Frontera", "Morón de la Frontera"],
["61", "dedicatoria", 1187, "LOCALIDAD", "Moron De La Frontera", "Morón de la Frontera"],
["61", "dedicatoria", 1202, "LOCALIDAD", "Moron De La Frontera", "Morón de la Frontera"],
["61", "dedicatoria", 684, "LOCALIDAD", "Coria Del Rio", "Coria del Río"],
["61", "banda", 124, "LOCALIDAD", "Coria del Rio", "Coria del Río"],
["62", "dedicatoria", 585, "LOCALIDAD", "Aspe ", "Aspe"],
["62", "dedicatoria", 1104, "LOCALIDAD", "Martos ", "Martos"],
["63", "marcha", 4429, "DEDICATORIA", null, "El Sol"],
["63", "marcha", 4919, "DEDICATORIA", null, "San Roque (Estreno)"],
["63", "marcha", 4086, "LOCALIDAD", "Hdad Cristo de Gracia", null],
["63", "marcha", 4429, "LOCALIDAD", "El Sol", null],
["63", "marcha", 4919, "LOCALIDAD", "San Roque (Estreno)", null],
["63", "dedicatoria", 559, "LOCALIDAD", "Hdad Cristo De Gracia", ""],
["66", "autor", 1053, "APELLIDOS", "Alabrrán Acosta", "Albarrán Acosta"],
["71", "autor", 242, "NOMBRE", "Alvaro José", "Álvaro José"],
["71", "autor", 502, "NOMBRE", "Jose Luis", "José Luis"],
["71", "autor", 194, "NOMBRE", "Jose María", "José María"],
["71", "autor", 370, "NOMBRE", "Jose Ramón", "José Ramón"],
["71", "autor", 398, "NOMBRE", "Jose", "José"],
["71", "autor", 417, "NOMBRE", "Maria Luisa", "María Luisa"],
["71", "autor", 162, "NOMBRE", "Raul", "Raúl"],
["71", "autor", 402, "NOMBRE", "Raul", "Raúl"],
["71", "autor", 564, "NOMBRE", "Felix Javier", "Félix Javier"],
["71", "autor", 1211, "NOMBRE", "Josue", "Josué"],
["72", "autor", 256, "APELLIDOS", "Carmona Suarez", "Carmona Suárez"],
["72", "autor", 202, "APELLIDOS", "Ramirez Chaves", "Ramírez Chaves"],
["72", "autor", 579, "APELLIDOS", "Rodriguez Lagomazzinni", "Rodríguez Lagomazzinni"],
["72", "autor", 118, "APELLIDOS", "Tomas Muñiz", "Tomás Muñiz"],
["72", "autor", 331, "APELLIDOS", "Marin Carmona", "Marín Carmona"],
["72", "autor", 249, "APELLIDOS", "Barrera Rios", "Barrera Ríos"],
["72", "autor", 1033, "APELLIDOS", "Ortíz Castillo", "Ortiz Castillo"],
["72", "autor", 83, "APELLIDOS", "Ruíz Rodríguez", "Ruiz Rodríguez"],
["72", "autor", 1176, "APELLIDOS", "Ruíz", "Ruiz"],
["73", "autor", 133, "BIO", "Músico y ensayista, Cursó  estudios deSolfeo, Canto Coral y Guitarra en el Conservatorio Profesional de Valladolid y Musicología en el Real Conservatorio Superior de Madrid.", "Músico y ensayista, cursó estudios de Solfeo, Canto Coral y Guitarra en el Conservatorio Profesional de Valladolid y Musicología en el Real Conservatorio Superior de Madrid."],
["74", "autor", 190, "BIO", "Compositor y director de orquesta, conocido en todo el mundo por sus más de medio millar de bandas sonoras de diversas películas y serires de televisión.", "Compositor y director de orquesta, conocido en todo el mundo por sus más de medio millar de bandas sonoras de diversas películas y series de televisión."],
["75", "autor", 463, "BIO", "Compositor checo de numerosas obas para piano, música de cámara o coro mixto. ", "Compositor checo de numerosas obras para piano, música de cámara o coro mixto."],
["76", "autor", 741, "BIO", "Compositor de las conocidas Saetas del Silencio para música de capila en el siglo XVIII", "Compositor de las conocidas Saetas del Silencio para música de capilla en el siglo XVIII"],
["77", "autor", 1227, "BIO", "Compositor de bandas sonoras de películas de Hollywood, llegando a la fama por su nominación al Oscar por la banda sonora de la película \"La Pasión de Cristo\" de Mel Gibson.", "Compositor de bandas sonoras de películas de Hollywood, llegando a la fama por su nominación al Óscar por la banda sonora de la película \"La Pasión de Cristo\" de Mel Gibson."],
["77", "autor", 3, "BIO", "Son marchas cuya autoría no se puede conocer, por ser adaptaciones populares en su mayoría. En algunos discos aparece como autor  de las mismas D.R. (derechos reservados).", "Son marchas cuya autoría no se puede conocer, por ser adaptaciones populares en su mayoría. En algunos discos aparece como autor de las mismas D.R. (derechos reservados)."],
["78", "banda", 170, "NOMBRE_COMPLETO", "Agrpación Musical Santísimo Cristo De Los Afligidos", "Agrupación Musical Santísimo Cristo De Los Afligidos"],
["79", "banda", 216, "NOMBRE_COMPLETO", "Agrupación Musical Nuestro Padre Jesús Nazareno y María Santísia de los Dolores", "Agrupación Musical Nuestro Padre Jesús Nazareno y María Santísima de los Dolores"],
["80", "banda", 13, "NOMBRE_COMPLETO", "Banda De Cornetas Y Tambores Nuestro Padre Jesús Cautivo Y Santiago Apostol ", "Banda De Cornetas Y Tambores Nuestro Padre Jesús Cautivo Y Santiago Apóstol"],
["80", "banda", 198, "NOMBRE_COMPLETO", "AM Nuestro Padre Jesús Nazareno ", "AM Nuestro Padre Jesús Nazareno"],
["81", "banda", 158, "NOMBRE_COMPLETO", "Banda de Cornetas y Tambores Santísimo Cristo de la Columna y Maria Santisima de la Amargura \"Los Coloraos\"", "Banda de Cornetas y Tambores Santísimo Cristo de la Columna y María Santísima de la Amargura \"Los Coloraos\""],
["81", "banda", 98, "NOMBRE_BREVE", "BCT Caido", "BCT Caído"],
["82", "banda", 247, "DIRECTOR_ACTUAL", "Francisco José Gacía Márquez", "Francisco José García Márquez"],
["83", "banda", 53, "DIR_MUS_ACTUAL", "Francsico González Téllez", "Francisco González Téllez"],
["83", "banda", 30, "DIR_MUS_ACTUAL", "Miguel Ángel Román Gracía", "Miguel Ángel Román García"],
["83", "banda", 69, "DIR_MUS_ACTUAL", "Daniel Rodríguez Ramíre Y Alejandro Pino", "Daniel Rodríguez Ramírez Y Alejandro Pino"],
["83", "banda", 15, "DIR_MUS_ACTUAL", "Salvador Paquet Guerreo", "Salvador Paquet Guerrero"],
["84", "banda", 28, "DIR_MUS_ACTUAL", "Jesús Jiménez, Rafael Villén, Gabríel Vicentí Y Moises Bonilla", "Jesús Jiménez, Rafael Villén, Gabriel Vicentí Y Moisés Bonilla"],
["85", "banda", 16, "DIR_MUS_ACTUAL", "Pedro M. Pacheco , Vicente Moreno Y Dionisio Buñuel", "Pedro M. Pacheco, Vicente Moreno Y Dionisio Buñuel"],
["85", "banda", 158, "DIR_MUS_ACTUAL", " Juan Carlos Martín-Consuegra Céspedes Y David Martín-Consuegra Céspedes", "Juan Carlos Martín-Consuegra Céspedes Y David Martín-Consuegra Céspedes"],
["85", "banda", 194, "DIR_MUS_ACTUAL", "Luis Mariano Franco, Luis Carlos Montes, Jose Antonio Lara  Y Alfonso García", "Luis Mariano Franco, Luis Carlos Montes, José Antonio Lara Y Alfonso García"],
["86", "banda", 124, "DIRECTOR_ACTUAL", "Andrés Martínez Martínez ", "Andrés Martínez Martínez"],
["86", "banda", 96, "DIRECTOR_ACTUAL", "Antonio Manuel Jaraíces Gutierrez", "Antonio Manuel Jaraíces Gutiérrez"],
["86", "banda", 151, "DIRECTOR_ACTUAL", "Fernando Biedma Fuentes E Ivan Santiago Martín", "Fernando Biedma Fuentes E Iván Santiago Martín"],
["86", "banda", 40, "DIRECTOR_ACTUAL", "Jesús Fernandez Y Ricardo Rojas", "Jesús Fernández Y Ricardo Rojas"],
["86", "banda", 72, "DIRECTOR_ACTUAL", "Joaquin Muñoz", "Joaquín Muñoz"],
["86", "banda", 143, "DIRECTOR_ACTUAL", "Jose Antonio Expósito Sánchez", "José Antonio Expósito Sánchez"],
["86", "banda", 163, "DIRECTOR_ACTUAL", "Miguel Angel Marcote Prats", "Miguel Ángel Marcote Prats"],
["86", "banda", 24, "DIRECTOR_ACTUAL", "Pedro Marquez García", "Pedro Márquez García"],
["86", "banda", 157, "DIRECTOR_ACTUAL", "Juan Carlos García Ibañez", "Juan Carlos García Ibáñez"],
["87", "banda", 214, "DIR_MUS_ACTUAL", "David Moya Diáz Y Victor Perez Herrera", "David Moya Díaz Y Víctor Pérez Herrera"],
["87", "banda", 25, "DIR_MUS_ACTUAL", "Abel Rodriguez Delgado", "Abel Rodríguez Delgado"],
["87", "banda", 213, "DIR_MUS_ACTUAL", "Alvaro J. Rufino, Fco. Manuel Lopez Y José Antonio Lechón", "Álvaro J. Rufino, Fco. Manuel López Y José Antonio Lechón"],
["87", "banda", 88, "DIR_MUS_ACTUAL", "Antonio Manuel Baquero, Jose Manuel García Y Miguel González", "Antonio Manuel Baquero, José Manuel García Y Miguel González"],
["87", "banda", 72, "DIR_MUS_ACTUAL", "Francisco Muñoz Y Jose María Conejo", "Francisco Muñoz Y José María Conejo"],
["87", "banda", 146, "DIR_MUS_ACTUAL", "Ismael Buzón, Juan Antonio Bazán Y Alejandro Suarez", "Ismael Buzón, Juan Antonio Bazán Y Alejandro Suárez"],
["87", "banda", 144, "DIR_MUS_ACTUAL", "Jose Angel Fontecha Vazquez", "José Ángel Fontecha Vázquez"],
["87", "banda", 140, "DIR_MUS_ACTUAL", "Jose Maria Jimenez Cabrero", "José María Jiménez Cabrero"],
["87", "banda", 4, "DIR_MUS_ACTUAL", "Juan Antonio Diaz Belizon", "Juan Antonio Díaz Belizón"],
["87", "banda", 196, "DIR_MUS_ACTUAL", "Oscar Albiol Bernal", "Óscar Albiol Bernal"],
["87", "banda", 262, "DIR_MUS_ACTUAL", "Oscar Javier Ruiz Delgado", "Óscar Javier Ruiz Delgado"],
["87", "banda", 151, "DIR_MUS_ACTUAL", "Pedro José Toral Jimenez", "Pedro José Toral Jiménez"],
["87", "banda", 96, "DIR_MUS_ACTUAL", "Raul Prieto Santos", "Raúl Prieto Santos"],
["87", "banda", 122, "DIR_MUS_ACTUAL", "Raul Rodriguez Dominguez", "Raúl Rodríguez Domínguez"],
["87", "banda", 68, "DIR_MUS_ACTUAL", "Rubén Ruíz Torti Y Augusto Martínez Mora", "Rubén Ruiz Torti Y Augusto Martínez Mora"],
["88", "disco", 455, "NOMBRE_CD", "X anivesrario", "X aniversario"],
["89", "disco", 132, "NOMBRE_CD", "Presentación y Sagre", "Presentación y Sangre"],
["90", "disco", 264, "NOMBRE_CD", "XX  Aniversario", "XX Aniversario"],
["91", "disco", 10, "NOMBRE_CD", "30 Años de Agrupacion", "30 Años de Agrupación"],
["91", "disco", 178, "NOMBRE_CD", "Cruz de Pasion", "Cruz de Pasión"],
["91", "disco", 77, "NOMBRE_CD", "En tu Pasion", "En tu Pasión"],
["91", "disco", 272, "NOMBRE_CD", "Semana de Pasion", "Semana de Pasión"],
["91", "disco", 190, "NOMBRE_CD", "Sones de la Pasion", "Sones de la Pasión"],
["91", "disco", 148, "NOMBRE_CD", "Lagrimas de Pasión", "Lágrimas de Pasión"],
["91", "disco", 236, "NOMBRE_CD", "Nuestra Musica", "Nuestra Música"],
["91", "disco", 58, "NOMBRE_CD", "Fundacion Alcalde Zoilo Ruiz-Mateos", "Fundación Alcalde Zoilo Ruiz-Mateos"],
["91", "disco", 127, "NOMBRE_CD", "Antologia", "Antología"],
["91", "disco", 221, "NOMBRE_CD", "Valeme, Señora", "Váleme, Señora"],
["92", "disco", 37, "NOMBRE_CD", "Hacia tí, Estrella", "Hacia ti, Estrella"],
["92", "disco", 65, "NOMBRE_CD", "La Fé", "La Fe"],
["92", "disco", 263, "NOMBRE_CD", "... y Sevilla la vió nacer", "... y Sevilla la vio nacer"],
["92", "disco", 421, "NOMBRE_CD", "... y Campillos te vió nacer", "... y Campillos te vio nacer"],
["93", "paso", 26, "NOMBRE", "Nuestro Padre Jesús de la Fé en su Sagrada Cena", "Nuestro Padre Jesús de la Fe en su Sagrada Cena"],
["PM", "autor", 579, "APELLIDOS", "Rodríguez Lagomazzinni", "Rodríguez Lagomazzini"],
["PM", "autor", 1145, "APELLIDOS", "Delgado Perea", "Delgado Perera"],
["PM", "autor", 195, "NOMBRE", "Ángel Jesús", "Ángel José"],
["PM", "autor", 171, "NOMBRE", "Mariano", "Mariana"]
]
JSON, true, 512, JSON_THROW_ON_ERROR);

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("UPDATE log_actor SET ACTOR = 'cli:corregir_erratas_bd_2026_09' WHERE ID = 1");

    $valor = static function (string $tabla, int $id, string $campo) use ($pdo): mixed {
        $st = $pdo->prepare("SELECT $campo FROM $tabla WHERE " . PK[$tabla] . ' = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_NUM);
        $st->closeCursor(); // si no, el VACUUM INTO falla: "SQL statements in progress"
        return $row === false ? false : $row[0];
    };
    $cuenta = static function (string $sql, array $args) use ($pdo): int {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    };

    // ── Qué queda pendiente (lo ya corregido se salta sin ruido) ──
    $pendientes = [];
    $omitidos = 0;
    // Un mismo campo puede corregirse en dos pasos (p. ej. autor #579: tilde y
    // luego el apellido de PatrimonioMusical): se simula la cadena en orden y
    // solo se avisa si el valor no es ni el esperado ni el final de la cadena.
    $simulado = [];
    $final = [];
    foreach ($CAMBIOS as $c) {
        $final["$c[1]#$c[2].$c[3]"] = $c[5];
    }
    foreach ($CAMBIOS as $c) {
        [, $tabla, $id, $campo, $viejo] = $c;
        $clave = "$tabla#$id.$campo";
        $actual = array_key_exists($clave, $simulado) ? $simulado[$clave] : $valor($tabla, $id, $campo);
        $fusionada = $tabla === 'dedicatoria' && in_array($id, array_column(FUSIONES_DEDIC, 1), true);
        if ($actual === $viejo) {
            $pendientes[] = $c;
            $simulado[$clave] = $c[5];
        } elseif ($actual !== $c[5] && $actual !== $final[$clave] && !($actual === false && $fusionada)) {
            $omitidos++;
            echo "  aviso: $tabla#$id.$campo no tiene el valor esperado (¿otra BD o editado a mano?), se omite\n";
        }
    }
    $contratoPend = [];
    foreach (CONTRATO_POR_VALOR as $campo => $lista) {
        foreach ($lista as [$n, $viejo, $nuevo]) {
            $k = $cuenta("SELECT COUNT(*) FROM contrato WHERE $campo = ?", [$viejo]);
            if ($k > 0) $contratoPend[] = [$n, $campo, $viejo, $nuevo, $k];
        }
    }
    $fusionesPend = [];
    foreach (FUSIONES_AUTOR as [$n, $dup, $keep]) {
        if ($valor('autor', $dup, 'ID_AUTOR') !== false) {
            if ($valor('autor', $keep, 'ID_AUTOR') === false) {
                throw new RuntimeException("$n: no existe el autor destino #$keep");
            }
            $fusionesPend[] = [$n, $dup, $keep];
        }
    }

    $dedicPend = array_values(array_filter(
        FUSIONES_DEDIC,
        static fn(array $f): bool => $valor('dedicatoria', $f[1], 'ID_DEDIC') !== false
    ));

    if ($pendientes === [] && $contratoPend === [] && $fusionesPend === [] && $dedicPend === []) {
        echo "nada que corregir (ya está todo bien)\n";
        exit(0);
    }

    echo count($pendientes) . " campos por corregir ($omitidos omitidos)\n";
    foreach ($pendientes as [$n, $tabla, $id, $campo, $viejo, $nuevo]) {
        echo "  [$n] $tabla#$id.$campo: " . json_encode($viejo, JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode($nuevo, JSON_UNESCAPED_UNICODE) . "\n";
    }
    foreach ($contratoPend as [$n, $campo, $viejo, $nuevo, $k]) {
        echo "  [$n] contrato.$campo \"$viejo\" -> \"$nuevo\" ($k filas)\n";
    }
    foreach ($fusionesPend as [$n, $dup, $keep]) {
        echo "  [$n] fusionar autor #$dup en #$keep\n";
    }
    foreach ($dedicPend as [$n, $dup, $keep]) {
        echo "  [$n] fusionar dedicatoria #$dup en #$keep\n";
    }

    if ($dryRun) {
        echo "--dry-run: no se ha escrito nada\n";
        exit(0);
    }

    $backupDir = dirname($db) . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Corrección abortada: no se pudo crear $backupDir\n");
        exit(1);
    }
    $dest = $backupDir . '/mdc-' . date('Ymd-His') . '-pre-erratas-bd.db';
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    echo 'backup: ' . $dest . "\n";

    $pdo->beginTransaction();
    $hechos = 0;
    foreach ($pendientes as [$n, $tabla, $id, $campo, $viejo, $nuevo]) {
        $aliasDedic = null;
        $loc = '';
        if ($tabla === 'marcha' && $campo === 'DEDICATORIA' && $viejo !== null && $viejo !== '') {
            $loc = (string) ($valor('marcha', $id, 'LOCALIDAD') ?? '');
            $aliasDedic = $cuenta('SELECT COALESCE(MAX(ID_DEDIC), 0) FROM dedicatoria_alias WHERE VARIANTE = ? AND LOCALIDAD = ?', [$viejo, $loc]) ?: null;
        }

        // IS y no =: algunos valores viejos son NULL (dedicatorias vacías del 63).
        $upd = $pdo->prepare("UPDATE $tabla SET $campo = ? WHERE " . PK[$tabla] . " = ? AND $campo IS ?");
        $upd->execute([$nuevo, $id, $viejo]);
        $hechos += $upd->rowCount();

        if ($aliasDedic !== null) {
            if ($nuevo !== null && $nuevo !== '') {
                $pdo->prepare('INSERT OR IGNORE INTO dedicatoria_alias (VARIANTE, LOCALIDAD, ID_DEDIC) VALUES (?, ?, ?)')
                    ->execute([$nuevo, $loc, $aliasDedic]);
            }
            $enUso = $cuenta("SELECT COUNT(*) FROM marcha WHERE DEDICATORIA = ? AND COALESCE(LOCALIDAD, '') = ?", [$viejo, $loc]);
            if ($enUso === 0) {
                $pdo->prepare('DELETE FROM dedicatoria_alias WHERE VARIANTE = ? AND LOCALIDAD = ?')->execute([$viejo, $loc]);
            }
        }
    }
    echo "campos corregidos: $hechos\n";

    foreach ($contratoPend as [$n, $campo, $viejo, $nuevo]) {
        $upd = $pdo->prepare("UPDATE contrato SET $campo = ? WHERE $campo = ?");
        $upd->execute([$nuevo, $viejo]);
        echo "  [$n] contrato.$campo \"$nuevo\": {$upd->rowCount()} filas\n";
    }

    foreach ($fusionesPend as [$n, $dup, $keep]) {
        // Mueve las marchas que el destino no tenga ya; las repetidas se borran.
        $mov = $pdo->prepare('UPDATE marcha_autor SET ID_AUTOR = ? WHERE ID_AUTOR = ?
                               AND ID_MARCHA NOT IN (SELECT ID_MARCHA FROM marcha_autor WHERE ID_AUTOR = ?)');
        $mov->execute([$keep, $dup, $keep]);
        $pdo->prepare('DELETE FROM marcha_autor WHERE ID_AUTOR = ?')->execute([$dup]);
        $pdo->prepare('DELETE FROM autor WHERE ID_AUTOR = ?')->execute([$dup]);
        echo "  [$n] autor #$dup fusionado en #$keep ({$mov->rowCount()} marchas movidas)\n";
    }

    foreach ($dedicPend as [$n, $dup, $keep]) {
        // Solo si, ya corregidas, son de verdad idénticas: en otra BD podrían no serlo.
        $iguales = $cuenta('SELECT COUNT(*) FROM dedicatoria a JOIN dedicatoria b ON a.NOMBRE = b.NOMBRE AND a.LOCALIDAD = b.LOCALIDAD
                             WHERE a.ID_DEDIC = ? AND b.ID_DEDIC = ?', [$dup, $keep]);
        if ($iguales === 0) {
            echo "  aviso [$n]: dedicatorias #$dup y #$keep no son idénticas, no se fusionan\n";
            continue;
        }
        $mov = $pdo->prepare('UPDATE dedicatoria_alias SET ID_DEDIC = ? WHERE ID_DEDIC = ?');
        $mov->execute([$keep, $dup]);
        $pdo->prepare('DELETE FROM dedicatoria WHERE ID_DEDIC = ?')->execute([$dup]);
        echo "  [$n] dedicatoria #$dup fusionada en #$keep ({$mov->rowCount()} alias movidos)\n";
    }

    $nombreMun = $valor('municipio', MUNICIPIO_ERRATA, 'NOMBRE');
    if ($nombreMun === 'Alcalá de Guadaría') {
        $usos = $cuenta('SELECT (SELECT COUNT(*) FROM marcha WHERE LOCALIDAD = ?) + (SELECT COUNT(*) FROM banda WHERE LOCALIDAD = ?)
                               + (SELECT COUNT(*) FROM dedicatoria WHERE LOCALIDAD = ?)', [$nombreMun, $nombreMun, $nombreMun]);
        if ($usos === 0) {
            $pdo->prepare('DELETE FROM municipio WHERE ID_MUNICIPIO = ? AND OFICIAL = 0')->execute([MUNICIPIO_ERRATA]);
            echo "  [59] municipio #" . MUNICIPIO_ERRATA . " \"$nombreMun\" borrado\n";
        } else {
            echo "  aviso [59]: municipio \"$nombreMun\" aún en uso ($usos), no se borra\n";
        }
    }
    $pdo->commit();

    // Vuelca el WAL al fichero principal (el .db se copia/sube tal cual).
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    // Los triggers *_au ya han refrescado los índices FTS; se comprueba.
    $pdo->exec("INSERT INTO marcha_fts(marcha_fts) VALUES('integrity-check')");
    $pdo->exec("INSERT INTO autor_fts(autor_fts) VALUES('integrity-check')");
    echo "marcha_fts / autor_fts: índices íntegros\n";

    $fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    echo 'FK check: ' . ($fk === [] ? 'limpio' : 'REVISAR: ' . print_r($fk, true)) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Corrección falló: ' . $e->getMessage() . "\n");
    exit(1);
}
