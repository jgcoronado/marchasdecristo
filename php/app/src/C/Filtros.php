<?php

declare(strict_types=1);

namespace App\C;

/**
 * Filtros de un listado: columna lateral con un formulario GET de campos de
 * texto y, debajo, los grupos de facetas con su recuento (la composición de
 * B; líneas maestras §2, principios 3 y 8; §5 «Recuentos»; DG).
 *
 *  - Los grupos de facetas van plegados salvo el primero y los que tengan
 *    una faceta activa.
 *  - La faceta activa se escribe en negrita y color de texto; pulsarla quita
 *    el filtro. Sin fondo, sin «×» en caja: nada con aspecto de etiqueta.
 *  - Es uno de los tres componentes que admiten cifras (los recuentos de
 *    faceta): lleva data-cifras.
 *
 * $limpiar: href para quitar todos los filtros, o null si no hay ninguno.
 * $ocultos: parámetros que el formulario conserva (p. ej. el orden).
 */
final class Filtros extends Componente
{
    /**
     * @param list<Campo> $campos
     * @param list<GrupoFacetas> $grupos
     * @param array<string,string> $ocultos
     */
    public function __construct(
        private readonly string $accion,
        private readonly array $campos = [],
        private readonly array $grupos = [],
        private readonly ?string $limpiar = null,
        private readonly array $ocultos = [],
        private readonly string $titulo = 'Filtros',
    ) {
    }

    public function render(): string
    {
        $out = '<aside class="c-filtros" aria-label="' . self::e($this->titulo) . '" data-cifras>';

        if ($this->campos !== []) {
            $accion = Seguro::href($this->accion) ?? '';
            $out .= '<form class="c-filtros-form" action="' . $accion . '" method="get">';
            foreach ($this->ocultos as $k => $v) {
                if ($v !== '') {
                    $out .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
                }
            }
            foreach ($this->campos as $c) {
                $id = 'f-' . $c->nombre;
                $out .= '<div class="c-campo"><label for="' . self::e($id) . '">' . self::e($c->etiqueta) . '</label>'
                    . '<input id="' . self::e($id) . '" type="' . self::e($c->tipo) . '" name="' . self::e($c->nombre) . '"'
                    . ' value="' . self::e($c->valor) . '"'
                    . ($c->placeholder !== '' ? ' placeholder="' . self::e($c->placeholder) . '"' : '')
                    . ($c->inputmode !== null ? ' inputmode="' . self::e($c->inputmode) . '"' : '')
                    . ($c->maxlength !== null ? ' maxlength="' . $c->maxlength . '"' : '')
                    . '></div>';
            }
            $out .= '<p class="c-filtros-acciones"><button class="c-primario" type="submit">Buscar</button>';
            if ($this->limpiar !== null && ($h = Seguro::href($this->limpiar)) !== null) {
                $out .= ' <a href="' . $h . '">Quitar filtros</a>';
            }
            $out .= '</p></form>';
        } elseif ($this->limpiar !== null && ($h = Seguro::href($this->limpiar)) !== null) {
            $out .= '<p class="c-filtros-acciones"><a href="' . $h . '">Quitar filtros</a></p>';
        }

        $primero = true;
        foreach ($this->grupos as $g) {
            if ($g->facetas === []) {
                continue;
            }
            $abierto = $primero || $g->tieneActiva();
            $primero = false;
            $out .= '<details class="c-facetas"' . ($abierto ? ' open' : '') . '><summary>' . self::e($g->titulo) . '</summary><ul>';
            foreach ($g->facetas as $f) {
                $h = Seguro::href($f->href);
                $etq = self::e($f->etiqueta);
                $enlace = $h === null ? $etq
                    : '<a href="' . $h . '"' . ($f->activa ? ' aria-current="true" title="Quitar este filtro"' : '') . '>' . $etq . '</a>';
                $out .= '<li>' . $enlace . ' <span class="c-n">' . number_format($f->n, 0, ',', '.') . '</span></li>';
            }
            $out .= '</ul></details>';
        }
        return $out . '</aside>';
    }
}
