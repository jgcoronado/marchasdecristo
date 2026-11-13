<?php

declare(strict_types=1);

namespace App\C;

/**
 * Cabecera de ficha: solo el nombre (h1). Líneas maestras §2, principios 4 y
 * 6; plan, «Decisiones tomadas».
 *
 * Requisito fijo del mantenedor (2026-11-13): NO hay frase descriptiva bajo
 * el título. Justo debajo, en la columna principal, va la tabla de datos
 * clave–valor (FichaTecnica) con los mismos campos que en pre. Por eso este
 * componente no tiene prop para una frase: los datos no se pasan a prosa.
 *
 * Tampoco tiene sitio para una etiqueta sobre el h1 ni para el botón de
 * compartir (se elimina: el enlace permanente va en PieFicha).
 *
 * $anclas: atajos de texto a secciones de una ficha larga, solo en móvil
 * (lista de [texto, '#id']).
 */
final class CabeceraFicha extends Componente
{
    /** @param list<array{0:string,1:string}> $anclas */
    public function __construct(
        private readonly string $nombre,
        private readonly array $anclas = [],
    ) {
    }

    public function render(): string
    {
        $out = '<header class="c-cab"><h1>' . self::e($this->nombre) . '</h1>';
        if ($this->anclas !== []) {
            $enlaces = [];
            foreach ($this->anclas as [$texto, $href]) {
                $enlaces[] = Texto::de()->enlace($texto, $href);
            }
            $out .= '<nav class="c-cab-anclas" aria-label="Secciones de la ficha">' . Texto::unir($enlaces, ' · ') . '</nav>';
        }
        return $out . '</header>';
    }
}
