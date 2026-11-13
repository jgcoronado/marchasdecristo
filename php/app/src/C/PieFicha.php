<?php

declare(strict_types=1);

namespace App\C;

/**
 * Pie de ficha: identificador y enlace permanente en una línea, en texto
 * secundario, al final de la ficha (líneas maestras §5 «Metadatos del
 * sistema»; RM, WP). El identificador es la única cifra de sistema que admite
 * la ficha: lleva data-cifras para que el auditor no la cuente como suelta.
 */
final class PieFicha extends Componente
{
    /** @param string $prefijo letra del tipo de ficha: «M» marcha, «B» banda… */
    public function __construct(
        private readonly string $prefijo,
        private readonly int $id,
        private readonly ?string $url = null,
    ) {
    }

    public function render(): string
    {
        $out = '<footer class="c-pie-ficha" data-cifras>Ficha ' . self::e($this->prefijo . '-' . $this->id);
        if ($this->url !== null && $this->url !== '') {
            $visible = (string) preg_replace('#^https?://#', '', $this->url);
            $out .= ' · enlace permanente: ' . Texto::de()->enlace($visible, $this->url);
        }
        return $out . '</footer>';
    }
}
