<?php

declare(strict_types=1);

namespace App;

/**
 * Similitud de texto 0..1 (1 = idéntico). Mismo criterio que
 * tools/ingest/dedup.mjs (normaliza minúsculas/sin acentos/sin signos de
 * apertura/espacios colapsados y compara por distancia de Levenshtein), para
 * que el umbral "80%"/"90%" signifique lo mismo en el pipeline offline y en
 * el panel PHP.
 */
final class Similarity
{
    /** Marcadores de "versión" que los orígenes añaden al título de la marcha. */
    private const SUFIJO_VERSION = 'en\s+vivo|en\s+directo|vivo|directo|live|estreno|versi[oó]n|remaster(?:izado|ed)?';

    /**
     * Quita del título lo que no es título: "Flagrum — en vivo",
     * "Nombre - (Live) Concierto en X", "Nombre [En Vivo]", "Nombre (feat. …)",
     * "Nombre — Estreno 2026". Solo si el sufijo va tras un guion/raya o un
     * paréntesis/corchete, para no recortar títulos que contengan esas palabras
     * ("Cristo vivo", "El directo…"). Si lo que quedaría es vacío, no toca nada.
     */
    public static function limpiarTitulo(string $s): string
    {
        $v = self::SUFIJO_VERSION;
        $limpio = preg_replace('/\s*(?:\s[—–-]\s|[\(\[])\s*[\(\[]?\s*(?:' . $v . ')\b.*$/isu', '', $s)
            ?? $s;
        $limpio = preg_replace('/\s*[\(\[]\s*(?:feat|ft|con|with)\b.*$/isu', '', $limpio) ?? $limpio;
        $limpio = trim($limpio);
        return $limpio === '' ? $s : $limpio;
    }

    private static function normalize(string $s): string
    {
        $s = Db::noAcc(self::limpiarTitulo($s));
        $s = preg_replace('/[¡!¿?"\'«»“”‘’]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    public static function ratio(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $maxLen = max(strlen($a), strlen($b));
        return 1 - levenshtein($a, $b) / $maxLen;
    }
}
