<?php

declare(strict_types=1);

namespace App\C;

/**
 * Componente del rediseño C (docs/rediseno/plan.md, F2).
 *
 * Contrato común:
 *  - las props van tipadas en el constructor y no cambian después;
 *  - render() devuelve HTML y ESCAPA todo lo que recibe como string. Lo único
 *    que pasa sin escapar es un Texto (ver App\C\Texto), que solo se puede
 *    construir escapando por dentro;
 *  - las URL pasan por Seguro::href(): un esquema que no sea http(s), mailto o
 *    una ruta relativa se descarta y el texto sale sin enlace.
 *
 * Reglas de diseño que van en el código (líneas maestras §2, §3 y §5):
 *  - NO existe ni debe existir un componente de etiqueta de color (chip,
 *    badge, pill). La categoría se escribe: texto o enlace de texto.
 *  - Los recuentos solo los admiten tres componentes: Tabla (celdas que son
 *    dato), Filtros (recuento de cada faceta) y Paginacion (el total, una vez).
 *    Ningún otro componente tiene una prop para una cifra de inventario. Esos
 *    tres marcan su raíz con data-cifras, que es lo que mira el auditor
 *    (.claude/qa/auditor.mjs) para no contarlos como cifras sueltas.
 *  - Los estilos están en public/assets/c/componentes.css y solo usan los
 *    tokens de c/tokens.css.
 */
abstract class Componente implements \Stringable
{
    abstract public function render(): string;

    public function __toString(): string
    {
        return $this->render();
    }

    /** Escape para texto y atributos. */
    protected static function e(string|int|float $v): string
    {
        return Seguro::e($v);
    }

    /**
     * Contenido de una celda o de un valor: el Texto pasa tal cual (ya viene
     * escapado), lo demás se escapa y el vacío se pinta como «—» secundario.
     */
    protected static function valor(string|int|float|Texto|null $v): string
    {
        if ($v instanceof Texto) {
            return $v->vacio() ? self::guion() : $v->html();
        }
        if ($v === null || $v === '') {
            return self::guion();
        }
        return self::e($v);
    }

    protected static function guion(): string
    {
        return '<span class="t-vacio">—</span>';
    }
}
