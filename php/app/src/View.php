<?php

declare(strict_types=1);

namespace App;

/**
 * Renderizado con plantillas PHP nativas. Una vista se captura en un buffer y se
 * inyecta dentro de layout.php como $content.
 */
final class View
{
    /**
     * @param array<string,mixed> $data  variables para la plantilla
     * @param array<string,mixed> $meta  title, description, noindex, og, jsonld
     */
    public static function render(string $template, array $data = [], array $meta = []): void
    {
        $content = self::capture($template, $data);
        $config = $GLOBALS['config'];
        require APP_DIR . '/templates/layout.php';
    }

    /** @param array<string,mixed> $data */
    public static function capture(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_DIR . '/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    /** Escape HTML seguro para interpolar en las plantillas. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Enumeración en castellano: «A», «A y B», «A, B y C». La conjunción pasa
     * a «e» ante sonido /i/ («Pedro Morales e Ignacio…»), no ante diptongo
     * («… y Hierro»).
     *
     * @param list<string> $items
     */
    public static function listaY(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn(string $s): bool => $s !== ''));
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $ultimo = array_pop($items);
        $conj = preg_match('/^h?[ií](?![aeiouáéíóú])/iu', $ultimo) === 1 ? ' e ' : ' y ';
        return implode(', ', $items) . $conj . $ultimo;
    }
}
