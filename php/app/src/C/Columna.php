<?php

declare(strict_types=1);

namespace App\C;

/**
 * Columna de una Tabla.
 *
 * $tipo:
 *  - 'texto': dato de texto; en móvil va en la segunda línea, en secundario;
 *  - 'num':   cifra que es dato (año, duración, grabaciones): a la derecha y
 *             tabular. La primera columna 'num' sube en móvil a la primera
 *             línea, junto a la celda principal;
 *  - 'opt':   dato prescindible: en móvil no sale.
 * La primera columna de la tabla es siempre la celda principal (.t-main):
 * su tipo se ignora.
 *
 * $movil: rótulo que precede al valor en la segunda línea en móvil (data-l).
 * Vacío, sin rótulo: el valor se entiende solo (una banda, una localidad).
 */
final class Columna
{
    public const TIPOS = ['texto', 'num', 'opt'];

    public function __construct(
        public readonly string $titulo,
        public readonly string $tipo = 'texto',
        public readonly string $movil = '',
        public readonly ?string $ayuda = null,
    ) {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Columna: tipo desconocido «' . $tipo . '».');
        }
    }
}
