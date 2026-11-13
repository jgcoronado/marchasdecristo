<?php

declare(strict_types=1);

namespace App\C;

/**
 * Cabecera de ficha: el nombre (h1) y la frase descriptiva, a tamaño de texto.
 * Líneas maestras §2, principios 4 y 6; §5 «Etiquetas» y «Metadatos».
 *
 * Por diseño no tiene sitio para una etiqueta sobre el h1 (lo dice la frase)
 * ni para el botón de compartir (se elimina: el enlace permanente va en
 * PieFicha). La frase puede llevar cifras que son biografía («fundada en
 * 1980»), no inventario («su obra suma 112 grabaciones»): eso es cosa de quien
 * la escribe.
 *
 * $anclas: atajos de texto a secciones de una ficha larga, solo en móvil
 * (lista de [texto, '#id']).
 */
final class CabeceraFicha extends Componente
{
    /** @param list<array{0:string,1:string}> $anclas */
    public function __construct(
        private readonly string $nombre,
        private readonly string|Texto|null $frase = null,
        private readonly array $anclas = [],
    ) {
    }

    public function render(): string
    {
        $out = '<header class="c-cab"><h1>' . self::e($this->nombre) . '</h1>';
        if ($this->frase !== null && $this->frase !== '' && !($this->frase instanceof Texto && $this->frase->vacio())) {
            $out .= '<p class="ficha-frase">' . self::valor($this->frase) . '</p>';
        }
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
