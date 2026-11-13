<?php

declare(strict_types=1);

namespace App\C;

/** Escape de salida y filtro de URL para los componentes. */
final class Seguro
{
    public static function e(string|int|float $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * URL apta para href, ya escapada, o null si no lo es. Admite rutas del
     * propio sitio («/marcha», «?page=2», «#grabaciones») y los esquemas
     * http, https y mailto. Todo lo demás (javascript:, data:, vbscript:,
     * «//otro-host» sin esquema…) se rechaza: el componente pinta el texto sin
     * enlace en vez de un enlace peligroso.
     */
    public static function href(string $url): ?string
    {
        $u = trim($url);
        if ($u === '') {
            return null;
        }
        // Caracteres de control dentro de la URL: el navegador los ignora y
        // dejaría pasar «java\tscript:».
        if (preg_match('/[\x00-\x20\x7f]/', $u) === 1) {
            return null;
        }
        $relativa = (str_starts_with($u, '/') && !str_starts_with($u, '//'))
            || str_starts_with($u, '?') || str_starts_with($u, '#');
        if ($relativa || preg_match('~^(https?://|mailto:)~i', $u) === 1) {
            return self::e($u);
        }
        return null;
    }
}
