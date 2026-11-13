<?php

declare(strict_types=1);

namespace App\C;

/**
 * Un par clave–valor de la FichaTecnica. Con $href, el valor entero se enlaza
 * (p. ej. «Estilo: Agrupación musical» → listado filtrado por estilo). Para un
 * valor con varios enlaces, pasa un Texto y deja $href en null.
 */
final class Dato
{
    public function __construct(
        public readonly string $clave,
        public readonly string|Texto $valor,
        public readonly ?string $href = null,
    ) {
    }
}
