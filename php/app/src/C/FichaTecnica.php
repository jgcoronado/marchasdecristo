<?php

declare(strict_types=1);

namespace App\C;

/**
 * Ficha técnica: lista clave–valor de la columna lateral (líneas maestras §2,
 * principio 6; §5 «Etiquetas»: el estilo va aquí como texto enlazado, nunca
 * como rectángulo de color). Sin datos no pinta nada.
 */
final class FichaTecnica extends Componente
{
    /** @param list<Dato> $datos */
    public function __construct(
        private readonly array $datos,
        private readonly string $titulo = 'Datos',
    ) {
    }

    public function render(): string
    {
        if ($this->datos === []) {
            return '';
        }
        $filas = '';
        foreach ($this->datos as $d) {
            $valor = $d->href !== null && is_string($d->valor)
                ? Texto::de()->enlace($d->valor, $d->href)
                : $d->valor;
            $filas .= '<div><dt>' . self::e($d->clave) . '</dt><dd>' . self::valor($valor) . '</dd></div>';
        }
        return '<section class="c-datos"><h2>' . self::e($this->titulo) . '</h2><dl>' . $filas . '</dl></section>';
    }
}
