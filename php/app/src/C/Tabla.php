<?php

declare(strict_types=1);

namespace App\C;

/**
 * Tabla con el contrato .t (c/base.css + c/componentes.css):
 *
 *     <table class="t">
 *       <td class="t-main">            celda principal: siempre la primera
 *       <td class="t-num" data-l="">   cifra: a la derecha y tabular
 *       <td data-l="Banda">            resto; data-l = rótulo en móvil
 *       <td class="t-opt">             prescindible: no sale en móvil
 *
 * En móvil cada fila pasa a dos líneas: la principal y la primera cifra
 * arriba, el resto debajo en secundario.
 *
 * Es uno de los tres componentes que admiten cifras (las celdas que son
 * dato: año, duración, grabaciones). Las celdas no llevan etiquetas de color:
 * un Texto solo admite texto, enlaces y abreviaturas.
 *
 * Celdas: string|int|float se escapan; Texto pasa tal cual; null o '' se
 * pintan «—». Las cifras no se formatean aquí (un año no lleva punto de
 * millar): llegan ya como quieran verse.
 *
 * Opciones:
 *  - $id: necesario para ordenar o filtrar;
 *  - $ordenable: orden por columna pulsando la cabecera (catalog.js). Con
 *    menos de tres filas no se activa: no ordena nada;
 *  - $filtro: texto del campo de filtro encima de la tabla; solo aparece a
 *    partir de FILTRO_MIN filas, y nunca lleva contador.
 */
final class Tabla extends Componente
{
    public const FILTRO_MIN = 8;

    /**
     * @param list<Columna> $columnas
     * @param list<list<string|int|float|Texto|null>> $filas
     */
    public function __construct(
        private readonly array $columnas,
        private readonly array $filas,
        private readonly ?string $id = null,
        private readonly bool $ordenable = false,
        private readonly ?string $filtro = null,
        private readonly ?string $titulo = null,
    ) {
        if ($columnas === []) {
            throw new \InvalidArgumentException('Tabla: hace falta al menos una columna.');
        }
        $n = count($columnas);
        foreach ($filas as $i => $fila) {
            if (count($fila) !== $n) {
                throw new \InvalidArgumentException('Tabla: la fila ' . $i . ' tiene ' . count($fila) . ' celdas y hay ' . $n . ' columnas.');
            }
        }
        if (($ordenable || $filtro !== null) && ($id === null || $id === '')) {
            throw new \InvalidArgumentException('Tabla: ordenar o filtrar necesita un id.');
        }
    }

    public function render(): string
    {
        if ($this->filas === []) {
            return '';
        }
        $ordena = $this->ordenable && count($this->filas) > 2;
        $primeraNum = null;
        foreach ($this->columnas as $i => $c) {
            if ($i > 0 && $c->tipo === 'num') {
                $primeraNum = $i;
                break;
            }
        }

        $out = '';
        if ($this->filtro !== null && count($this->filas) >= self::FILTRO_MIN) {
            $out .= '<input class="c-filtro-tabla" type="search" placeholder="' . self::e($this->filtro) . '"'
                . ' aria-label="' . self::e($this->filtro) . '" data-filter="' . self::e((string) $this->id) . '">';
        }

        $out .= '<div class="scrollx" data-cifras><table class="t"'
            . ($this->id !== null && $this->id !== '' ? ' id="' . self::e($this->id) . '"' : '')
            . ($ordena ? ' data-sortable' : '') . '>';
        if ($this->titulo !== null) {
            $out .= '<caption>' . self::e($this->titulo) . '</caption>';
        }
        $out .= '<thead><tr>';
        foreach ($this->columnas as $i => $c) {
            $cls = $i > 0 ? self::clase($c->tipo, $i === $primeraNum) : '';
            $out .= '<th scope="col"' . ($cls !== '' ? ' class="' . $cls . '"' : '')
                . ($ordena && $c->tipo === 'num' ? ' data-type="num"' : '')
                . ($c->ayuda !== null ? ' title="' . self::e($c->ayuda) . '"' : '') . '>'
                . self::e($c->titulo) . ($ordena ? ' <span class="ar" aria-hidden="true">↕</span>' : '') . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($this->filas as $fila) {
            $out .= '<tr>';
            foreach ($fila as $i => $celda) {
                if ($i === 0) {
                    $out .= '<td class="t-main">' . self::valor($celda) . '</td>';
                    continue;
                }
                $c = $this->columnas[$i];
                $cls = self::clase($c->tipo, $i === $primeraNum);
                $out .= '<td' . ($cls !== '' ? ' class="' . $cls . '"' : '')
                    . ' data-l="' . self::e($c->movil) . '">' . self::valor($celda) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private static function clase(string $tipo, bool $primeraNum): string
    {
        return match ($tipo) {
            'num' => $primeraNum ? 't-num t-linea1' : 't-num',
            'opt' => 't-opt',
            default => '',
        };
    }
}
