<?php

declare(strict_types=1);

namespace App\C;

/**
 * Una opción de faceta: etiqueta, enlace al listado con (o, si está activa,
 * sin) ese filtro, y su recuento. El recuento aquí sí ayuda a elegir
 * (líneas maestras §2, principio 3).
 */
final class Faceta
{
    public function __construct(
        public readonly string $etiqueta,
        public readonly string $href,
        public readonly int $n,
        public readonly bool $activa = false,
    ) {
    }
}
