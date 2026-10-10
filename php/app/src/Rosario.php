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
 */
final class Rosario
{
    /** Valor de la columna «accion»: el mismo texto que el botón pulsado. */
    public const ACCIONES = ['arriba', 'abajo'];

    /** Sin tildes: Excel abre como ANSI un CSV UTF-8 sin BOM y las rompería. */
    public const CABECERA = 'fecha;hora;accion;latitud;longitud;precision_m';

    private const ZONA = 'Europe/Madrid';

    public static function pagina(): void
    {
        Http::noStore();
        header('Content-Type: text/html; charset=UTF-8');
        echo View::capture('rosario');
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

    /** Junto al .db, como el resto de ficheros que escribe la app (propuestas, og-cache…). */
    public static function fichero(): string
    {
        return dirname((string) ($GLOBALS['config']['db_path'] ?? '')) . '/rosario.csv';
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
