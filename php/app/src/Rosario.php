<?php

declare(strict_types=1);

namespace App;

/**
 * /rosario: registro de campo de cuándo y dónde se levanta («arriba») y se
 * baja («abajo») un paso, pulsado desde un iPhone durante el recorrido.
 *
 * No forma parte del catálogo: no toca la BD ni sale en nav, sitemap ni
 * llms.txt. Cada pulsación es una línea de un CSV en el directorio de datos
 * (private/ en el hosting, junto a mdc.db y fuera del webroot, así que no se
 * puede descargar por URL), que se baja a mano. Solo existe en PRE y en local:
 * routes.php no registra la ruta en PRO aunque el código llegue allí con la
 * fusión de pre en main.
 *
 * La hora y la ubicación las pone el teléfono al pulsar, no el servidor al
 * recibir: la página guarda cada pulsación en el móvil y la reenvía hasta que
 * llega (entre la bulla puede no haber cobertura en minutos), así que la hora
 * de llegada no vale. Ese reenvío puede repetir una pulsación que sí se
 * escribió pero cuya respuesta se perdió por el camino; por eso una línea
 * idéntica a otra ya escrita se ignora.
 *
 * Debajo de los botones, la página muestra el repertorio previsto y deja
 * marcar en cada marcha si sonó y una observación corta. Eso va a otro CSV,
 * rosario_marchas.csv, que es la tabla entera y se reescribe con cada cambio.
 */
final class Rosario
{
    /** Valor de la columna «accion»: el mismo texto que el botón pulsado. */
    public const ACCIONES = ['arriba', 'abajo'];

    /** Sin tildes: Excel abre como ANSI un CSV UTF-8 sin BOM y las rompería. */
    public const CABECERA = 'fecha;hora;accion;latitud;longitud;precision_m';

    /**
     * Repertorio previsto, [lugar, marcha], tal cual viene en el Excel
     * «marchas_2026_consenso» (filas 3 a 35; procesión de Ntra. Sra. del
     * Rosario, 11/10/2026). Cada fila se identifica por su orden (1..N), no
     * por el texto: hay lugares y marchas repetidos.
     */
    public const REPERTORIO = [
        ['Salida - Pta Perdón', 'Himno de España'],
        ['Gradas 1', 'Rosario de Montesión'],
        ['Gradas 2', 'Pasa la Virgen Macarena'],
        ['Carlos Amigo', 'El día del Señor'],
        ['Alemanes', 'Pasa la Virgen de la Candelaria'],
        ['G Vinuesa', 'Virgen de la Paz'],
        ['G Vinuesa', 'Danos la Paz'],
        ['G Vinuesa - Arfe', 'Pasan los campanilleros'],
        ['Arfe-Adriano', 'Coronación de la Macarena'],
        ['Saludo Baratillo', 'Nanas del Baratillo'],
        ['Salida Baratillo', 'Triana, tu Esperanza'],
        ['Gracia Fdez Palacios', 'Saeta Cordobesa'],
        ['Antonia Diaz', 'Soleá dame la mano'],
        ['Techada - Carretería', 'Quinta Angustia'],
        ['Saludo Carretería', 'Cristo de Vera-Cruz'],
        ['Rodo', 'Valle de Sevilla'],
        ['Rodo', 'Nuestra Señora de la Palma'],
        ['Dos de Mayo', 'La Estrella Sublime'],
        ['Saludo Aguas', 'Nuestra Señora de Guadalupe'],
        ['Dos de Mayo', 'Dolores, Saeta Onubense'],
        ['Postigo', 'Hosanna in Excelsis'],
        ['Postigo - Almirantazgo', 'María Santísima del Subterráneo'],
        ['Almirantazgo - Avda', 'Procesión de Semana Santa en Sevilla'],
        ['Plaza del Triunfo', 'Ave María'],
        ['Romero Murube - Alianza', 'La Madrugá'],
        ['Rodrigo Caro', 'La Virgen de los Desamparados'],
        ['Mateos Gago', 'Cachorro Eterno'],
        ['Virgen de los Reyes', 'Cordero de Dios'],
        ['Giralda', 'Se Arrodilla Triana'],
        ['Gradas - Pta Perdón', 'Virgen del Valle'],
        ['Patio', 'Cristo de la Buena Muerte'],
        ['Patio', 'María Santísima del Dulce nombre'],
        ['Entrada Sagrario', 'Himno de España'],
    ];

    /** Caracteres, no bytes: lo mismo que cuenta el maxlength del campo. */
    public const OBSERVACIONES_MAX = 150;

    /** Con tildes: este CSV lleva BOM, que los nombres de las marchas las necesitan. */
    public const CABECERA_MARCHAS = 'Orden;Lugar;Marcha;Sonó;Observaciones;Actualizado';

    private const ZONA = 'Europe/Madrid';

    /** Formato de la columna «Actualizado», que también se relee para comparar. */
    private const FECHA_HORA = 'd/m/Y H:i:s';

    public static function pagina(): void
    {
        Http::noStore();
        header('Content-Type: text/html; charset=UTF-8');
        echo View::capture('rosario', ['marchas' => self::marchas(self::ficheroMarchas())]);
    }

    public static function registrar(): void
    {
        Http::noStore();
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $linea = self::linea($_POST);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $nueva = self::anotar(self::fichero(), $linea);
        } catch (\RuntimeException $e) {
            error_log('[rosario] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo escribir el CSV'], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode(['ok' => true, 'repetida' => !$nueva]);
    }

    /** POST /rosario/marchas: una fila de la tabla (sonó y observaciones). */
    public static function marcar(): void
    {
        Http::noStore();
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $marca = self::marca($_POST);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $aplicada = self::aplicar(self::ficheroMarchas(), $marca);
        } catch (\RuntimeException $e) {
            error_log('[rosario] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo escribir la tabla'], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode(['ok' => true, 'aplicada' => $aplicada]);
    }

    /** Junto al .db, como el resto de ficheros que escribe la app (propuestas, og-cache…). */
    public static function fichero(): string
    {
        return dirname((string) ($GLOBALS['config']['db_path'] ?? '')) . '/rosario.csv';
    }

    public static function ficheroMarchas(): string
    {
        return dirname((string) ($GLOBALS['config']['db_path'] ?? '')) . '/rosario_marchas.csv';
    }

    /**
     * Línea del CSV (sin salto) a partir de lo que envía la página. Formato
     * para Excel en español: «;» como separador y coma decimal.
     *
     * Sin ubicación (el GPS no dio ninguna lectura a tiempo) la pulsación se
     * guarda igual, con latitud, longitud y precisión vacías: mejor la hora sin
     * sitio que perder la pulsación.
     *
     * @param array<string,mixed> $campos accion, ts (ms Unix del teléfono), lat, lon, precision (m)
     */
    public static function linea(array $campos): string
    {
        $accion = $campos['accion'] ?? null;
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new \InvalidArgumentException('Acción no válida');
        }

        $ts = $campos['ts'] ?? null;
        if (!is_string($ts) || preg_match('/^\d{13}$/', $ts) !== 1) {
            throw new \InvalidArgumentException('Hora no válida');
        }
        $momento = (new \DateTimeImmutable('@' . intdiv((int) $ts, 1000)))
            ->setTimezone(new \DateTimeZone(self::ZONA));

        $lat = self::numero($campos['lat'] ?? '', -90, 90, 'Latitud');
        $lon = self::numero($campos['lon'] ?? '', -180, 180, 'Longitud');
        $precision = self::numero($campos['precision'] ?? '', 0, 1_000_000, 'Precisión');
        if (($lat === null) !== ($lon === null)) {
            throw new \InvalidArgumentException('Ubicación incompleta');
        }

        return implode(';', [
            $momento->format('d/m/Y'),
            $momento->format('H:i:s'),
            $accion,
            self::decimal($lat, 6),        // 6 decimales ≈ 0,1 m
            self::decimal($lon, 6),
            self::decimal($precision, 1),
        ]);
    }

    /**
     * Añade la línea al CSV (creándolo con su cabecera si no existe).
     * Devuelve false si esa misma línea ya estaba: es un reenvío.
     */
    public static function anotar(string $fichero, string $linea): bool
    {
        $f = @fopen($fichero, 'c+');
        if ($f === false) {
            throw new \RuntimeException("No se puede abrir $fichero");
        }
        try {
            if (!flock($f, LOCK_EX)) {
                throw new \RuntimeException("No se puede bloquear $fichero");
            }
            $actual = (string) stream_get_contents($f);
            if (str_contains($actual, "\r\n" . $linea . "\r\n")) {
                return false;
            }
            $texto = ($actual === '' ? self::CABECERA . "\r\n" : '') . $linea . "\r\n";
            fseek($f, 0, SEEK_END);
            if (fwrite($f, $texto) !== strlen($texto)) {
                throw new \RuntimeException("Escritura incompleta en $fichero");
            }
            fflush($f);
            return true;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    /**
     * Una fila de la tabla a partir de lo que envía la página.
     *
     * @param array<string,mixed> $campos orden (1..N), sono ('0'|'1'), obs, ts (ms Unix del teléfono)
     * @return array{orden:int,sono:bool,obs:string,ts:int} ts en segundos
     */
    public static function marca(array $campos): array
    {
        $orden = $campos['orden'] ?? null;
        if (!is_string($orden) || preg_match('/^[1-9]\d{0,2}$/', $orden) !== 1 || (int) $orden > count(self::REPERTORIO)) {
            throw new \InvalidArgumentException('Fila no válida');
        }
        $sono = $campos['sono'] ?? null;
        if ($sono !== '0' && $sono !== '1') {
            throw new \InvalidArgumentException('«Sonó» no válido');
        }
        $ts = $campos['ts'] ?? null;
        if (!is_string($ts) || preg_match('/^\d{13}$/', $ts) !== 1) {
            throw new \InvalidArgumentException('Hora no válida');
        }
        $obs = $campos['obs'] ?? '';
        if (!is_string($obs) || !mb_check_encoding($obs, 'UTF-8')) {
            throw new \InvalidArgumentException('Observaciones no válidas');
        }
        // En una línea: un salto partiría la fila al abrir el CSV en Excel.
        $obs = trim((string) preg_replace('/[\s\p{Cc}]+/u', ' ', $obs));
        if (mb_strlen($obs) > self::OBSERVACIONES_MAX) {
            throw new \InvalidArgumentException('Observaciones de más de ' . self::OBSERVACIONES_MAX . ' caracteres');
        }
        return ['orden' => (int) $orden, 'sono' => $sono === '1', 'obs' => $obs, 'ts' => intdiv((int) $ts, 1000)];
    }

    /**
     * La tabla guardada, por orden (1..N). Una fila que nunca se tocó sale sin
     * marcar, sin observaciones y con ts 0.
     *
     * @return array<int,array{sono:bool,obs:string,ts:int}>
     */
    public static function marchas(string $fichero): array
    {
        $f = @fopen($fichero, 'r');
        if ($f === false) {
            return self::tablaVacia(); // aún no se ha marcado nada
        }
        try {
            flock($f, LOCK_SH);
            return self::leerMarchas((string) stream_get_contents($f));
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    /**
     * Guarda la fila si es igual o más reciente que la que ya hay y reescribe
     * la tabla entera. Devuelve false si la guardada es más nueva: se cambió
     * después desde otra pestaña u otro teléfono, y una página con la tabla
     * vieja no debe pisarla. Así, además, solo cuenta la fila que se tocó.
     *
     * @param array{orden:int,sono:bool,obs:string,ts:int} $marca
     */
    public static function aplicar(string $fichero, array $marca): bool
    {
        $f = @fopen($fichero, 'c+');
        if ($f === false) {
            throw new \RuntimeException("No se puede abrir $fichero");
        }
        try {
            if (!flock($f, LOCK_EX)) {
                throw new \RuntimeException("No se puede bloquear $fichero");
            }
            $tabla = self::leerMarchas((string) stream_get_contents($f));
            if ($marca['ts'] < $tabla[$marca['orden']]['ts']) {
                return false;
            }
            $tabla[$marca['orden']] = ['sono' => $marca['sono'], 'obs' => $marca['obs'], 'ts' => $marca['ts']];
            $texto = self::csvMarchas($tabla);
            if (!ftruncate($f, 0) || !rewind($f) || fwrite($f, $texto) !== strlen($texto)) {
                throw new \RuntimeException("Escritura incompleta en $fichero");
            }
            fflush($f);
            return true;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    /**
     * El CSV de la tabla: BOM, cabecera y una línea por marcha del
     * repertorio, en su orden. Excel en español: «;» y «sí»/«no».
     *
     * @param array<int,array{sono:bool,obs:string,ts:int}> $tabla
     */
    public static function csvMarchas(array $tabla): string
    {
        $zona = new \DateTimeZone(self::ZONA);
        $texto = "\u{FEFF}" . self::CABECERA_MARCHAS . "\r\n";
        foreach (self::REPERTORIO as $i => [$lugar, $marcha]) {
            $fila = $tabla[$i + 1];
            $texto .= implode(';', [
                $i + 1,
                self::celda($lugar),
                self::celda($marcha),
                $fila['sono'] ? 'sí' : 'no',
                self::celda($fila['obs']),
                $fila['ts'] === 0 ? '' : (new \DateTimeImmutable('@' . $fila['ts']))->setTimezone($zona)->format(self::FECHA_HORA),
            ]) . "\r\n";
        }
        return $texto;
    }

    /** @return array<int,array{sono:bool,obs:string,ts:int}> */
    private static function tablaVacia(): array
    {
        return array_fill(1, count(self::REPERTORIO), ['sono' => false, 'obs' => '', 'ts' => 0]);
    }

    /**
     * Lo contrario de csvMarchas(). Las observaciones nunca llevan saltos de
     * línea (marca() los quita), así que cada línea es una fila.
     *
     * @return array<int,array{sono:bool,obs:string,ts:int}>
     */
    private static function leerMarchas(string $texto): array
    {
        $tabla = self::tablaVacia();
        $zona = new \DateTimeZone(self::ZONA);
        $lineas = preg_split('/\r?\n/', $texto) ?: [];
        foreach (array_slice($lineas, 1) as $linea) {
            $c = str_getcsv($linea, ';', '"', '');
            $orden = (int) $c[0];
            if (!isset($tabla[$orden], $c[5])) {
                continue;
            }
            $momento = \DateTimeImmutable::createFromFormat('!' . self::FECHA_HORA, (string) $c[5], $zona);
            $tabla[$orden] = [
                'sono' => $c[3] === 'sí',
                'obs' => (string) $c[4],
                'ts' => $momento === false ? 0 : $momento->getTimestamp(),
            ];
        }
        return $tabla;
    }

    /** Entre comillas si lleva «;» o «"» (solo puede pasar en las observaciones). */
    private static function celda(string $texto): string
    {
        return strpbrk($texto, ';"') === false ? $texto : '"' . str_replace('"', '""', $texto) . '"';
    }

    private static function numero(mixed $valor, float $min, float $max, string $que): ?float
    {
        if ($valor === '') {
            return null;
        }
        if (!is_string($valor) || !is_numeric($valor)) {
            throw new \InvalidArgumentException("$que no válida");
        }
        $n = (float) $valor;
        if (!is_finite($n) || $n < $min || $n > $max) {
            throw new \InvalidArgumentException("$que fuera de rango");
        }
        return $n;
    }

    private static function decimal(?float $n, int $decimales): string
    {
        return $n === null ? '' : number_format($n, $decimales, ',', '');
    }
}
