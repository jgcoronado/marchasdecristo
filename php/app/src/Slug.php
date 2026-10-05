<?php

declare(strict_types=1);

namespace App;

use Normalizer;

/**
 * Puerto exacto de nextjs/lib/slugify.ts — las URLs deben salir idénticas a las
 * actuales para no perder indexación tras el cutover.
 */
final class Slug
{
    public static function slugify(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // NFD + eliminar marcas diacríticas combinantes (equivale a ̀-ͯ en JS).
        $s = Normalizer::normalize($value, Normalizer::FORM_D);
        if ($s === false) {
            $s = $value;
        }
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');
        $s = preg_replace('/-{2,}/', '-', $s) ?? $s;

        return $s;
    }

    public static function buildDetailPath(string $page, string|int $id, string $label): string
    {
        $safeId = trim((string) $id);
        if ($safeId === '') {
            return "/{$page}";
        }
        if ($page === 'banda') {
            $label = self::nombresCompletosBanda()[(int) $safeId] ?? $label;
        }
        $slug = self::slugify($label);
        return $slug !== '' ? "/{$page}/{$slug}-{$safeId}" : "/{$page}/{$safeId}";
    }

    /** @var array<int,string>|null */
    private static ?array $nombresBanda = null;

    /**
     * ID_BANDA → NOMBRE_COMPLETO, una consulta por petición. La canónica de la
     * ficha de banda sale de NOMBRE_COMPLETO (Pages::banda) y casi todos los
     * listados solo traen NOMBRE_BREVE: sin esto enlazaban a /banda/am-…-ID y
     * cada clic pasaba por un 308. Sin base de datos, se usa la etiqueta dada.
     * @return array<int,string>
     */
    private static function nombresCompletosBanda(): array
    {
        if (self::$nombresBanda === null) {
            self::$nombresBanda = [];
            try {
                foreach (Db::all('SELECT ID_BANDA, NOMBRE_COMPLETO FROM banda') as $r) {
                    self::$nombresBanda[(int) $r['ID_BANDA']] = (string) ($r['NOMBRE_COMPLETO'] ?? '');
                }
            } catch (\Throwable $e) {
                error_log('[slug banda] ' . $e->getMessage());
            }
        }
        return self::$nombresBanda;
    }

    public static function extractId(string $slugAndId): ?string
    {
        return preg_match('/(\d+)$/', $slugAndId, $m) ? $m[1] : null;
    }
}
