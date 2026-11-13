<?php

declare(strict_types=1);

namespace App\C;

/**
 * Tabla de datos de la ficha: lista clave–valor que va ARRIBA, en la columna
 * principal, justo debajo del h1 (requisito fijo del mantenedor, 2026-11-13;
 * líneas maestras §2, principio 6, y §6; plan, «Decisiones tomadas»). Lleva
 * los mismos campos que la ficha de pre, en su orden; un campo sin dato no se
 * pinta. No hay frase descriptiva: los datos no se pasan a prosa.
 *
 * Los valores van enlazados donde haya destino (compositor, año → hub,
 * estilo → listado filtrado: nunca un rectángulo de color, §5 «Etiquetas»).
 * Sin recuentos de inventario junto a los valores (§5 «Recuentos»).
 *
 * Plana: clave en sans y color secundario, valor en color de texto, líneas
 * neutras de 1 px; sin caja, sin sombra y sin cebra (c/componentes.css).
 * $titulo es opcional: la tabla de la ficha va sin h2.
 */
final class FichaTecnica extends Componente
{
    /** @param list<Dato> $datos */
    public function __construct(
        private readonly array $datos,
        private readonly ?string $titulo = null,
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
        $titulo = $this->titulo !== null ? '<h2>' . self::e($this->titulo) . '</h2>' : '';
        return '<section class="c-datos" id="datos" aria-label="Datos">' . $titulo . '<dl>' . $filas . '</dl></section>';
    }
}
