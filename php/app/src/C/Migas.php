<?php

declare(strict_types=1);

namespace App\C;

/**
 * Migas de pan, en texto secundario y separadas por «/» (las pone la hoja).
 * El último eslabón sin enlace es la página actual.
 *
 * Regla (plan, «Decisiones tomadas»; líneas maestras §5): el año no es un
 * eslabón. Ya va enlazado en la frase de la ficha. Un eslabón que sea solo un
 * año se rechaza con una excepción, para que nadie lo vuelva a meter.
 */
final class Migas extends Componente
{
    /** @param list<array{0:string,1:?string}> $eslabones [texto, href|null] */
    public function __construct(private readonly array $eslabones)
    {
        foreach ($eslabones as [$texto]) {
            if (preg_match('/^\s*\d{4}\s*$/', $texto) === 1) {
                throw new \InvalidArgumentException('Migas: el año no es un eslabón («' . $texto . '»).');
            }
        }
    }

    public function render(): string
    {
        if ($this->eslabones === []) {
            return '';
        }
        $items = '';
        foreach ($this->eslabones as [$texto, $href]) {
            $h = $href !== null ? Seguro::href($href) : null;
            $items .= $h !== null
                ? '<li><a href="' . $h . '">' . self::e($texto) . '</a></li>'
                : '<li aria-current="page">' . self::e($texto) . '</li>';
        }
        return '<nav class="c-migas" aria-label="Migas de pan"><ol>' . $items . '</ol></nav>';
    }
}
