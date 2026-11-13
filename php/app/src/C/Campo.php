<?php

declare(strict_types=1);

namespace App\C;

/** Campo de texto del formulario de Filtros. */
final class Campo
{
    public const TIPOS = ['text', 'search', 'number'];

    public function __construct(
        public readonly string $nombre,
        public readonly string $etiqueta,
        public readonly string $valor = '',
        public readonly string $placeholder = '',
        public readonly string $tipo = 'text',
        public readonly ?string $inputmode = null,
        public readonly ?int $maxlength = null,
    ) {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Campo: tipo desconocido «' . $tipo . '».');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_\-\[\]]*$/', $nombre) !== 1) {
            throw new \InvalidArgumentException('Campo: nombre no válido «' . $nombre . '».');
        }
    }
}
