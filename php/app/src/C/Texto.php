<?php

declare(strict_types=1);

namespace App\C;

/**
 * Fragmento de texto con enlaces, escapado al construirlo. Es la única forma
 * de pasar HTML a un componente: no hay método para meter marcado crudo.
 *
 *     Texto::de('Marcha de ')->enlace('Pedro Morales', '/autor/pedro-morales-3')->texto(', 1969.')
 *
 * Inmutable: cada método devuelve una copia.
 */
final class Texto implements \Stringable
{
    private function __construct(private readonly string $html)
    {
    }

    public static function de(string $texto = ''): self
    {
        return new self(Seguro::e($texto));
    }

    public function texto(string $texto): self
    {
        return new self($this->html . Seguro::e($texto));
    }

    /** Enlace de texto. Con una URL no admitida (ver Seguro::href) queda el texto solo. */
    public function enlace(string $texto, string $href): self
    {
        $h = Seguro::href($href);
        $a = $h === null ? Seguro::e($texto) : '<a href="' . $h . '">' . Seguro::e($texto) . '</a>';
        return new self($this->html . $a);
    }

    /** Abreviatura con su forma larga al pasar el ratón («perc.»). Texto, no etiqueta. */
    public function abreviatura(string $texto, string $titulo): self
    {
        return new self($this->html . '<abbr title="' . Seguro::e($titulo) . '">' . Seguro::e($texto) . '</abbr>');
    }

    /** Une otro Texto al final. */
    public function mas(self $otro): self
    {
        return new self($this->html . $otro->html);
    }

    /**
     * Une varios Texto con un separador (que se escapa).
     *
     * @param list<self> $partes
     */
    public static function unir(array $partes, string $separador): self
    {
        $sep = Seguro::e($separador);
        return new self(implode($sep, array_map(static fn(self $t): string => $t->html, $partes)));
    }

    public function vacio(): bool
    {
        return $this->html === '';
    }

    public function html(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
