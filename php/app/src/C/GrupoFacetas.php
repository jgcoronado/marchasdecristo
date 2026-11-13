<?php

declare(strict_types=1);

namespace App\C;

/** Grupo de facetas con su título («Tipo», «Estilo», «Provincia»…). */
final class GrupoFacetas
{
    /** @param list<Faceta> $facetas */
    public function __construct(
        public readonly string $titulo,
        public readonly array $facetas,
    ) {
    }

    public function tieneActiva(): bool
    {
        foreach ($this->facetas as $f) {
            if ($f->activa) {
                return true;
            }
        }
        return false;
    }
}
