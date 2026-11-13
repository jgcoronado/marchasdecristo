<?php

declare(strict_types=1);

namespace App\C;

/**
 * Paginación: el total de resultados, una vez («1–20 de 5.518»), y la lista
 * numerada de páginas en enlaces de texto (líneas maestras §2, principio 8;
 * §5 «Recuentos»; DG, MB). La página actual va en negrita y color de texto,
 * sin fondo: nada de botones ni segmentados.
 *
 * Es uno de los tres componentes que admiten cifras (el total): lleva
 * data-cifras.
 *
 * $porPaginaOpciones: si no está vacío, añade «Por página: 10 · 20 · 50» como
 * enlaces de texto. $conLimite=false omite `limit` de las URL (hubs de tamaño
 * fijo: una sola URL por página).
 */
final class Paginacion extends Componente
{
    /**
     * @param array<string,string|int> $criterios  criterios activos (sin page)
     * @param list<int> $porPaginaOpciones
     */
    public function __construct(
        private readonly int $pagina,
        private readonly int $total,
        private readonly int $porPagina,
        private readonly string $ruta,
        private readonly array $criterios = [],
        private readonly bool $conLimite = true,
        private readonly array $porPaginaOpciones = [],
    ) {
        // La ruta se concatena con la query en cada enlace: tiene que ser una
        // ruta propia («/marcha»), nunca un esquema (javascript:) ni un host
        // («//otro», «/\\otro»), ni llevar espacios o caracteres de control.
        if (preg_match('~^/(?![/\\\\])[^\x00-\x20\x7f\\\\]*$~', $ruta) !== 1) {
            throw new \InvalidArgumentException('Paginacion: la ruta tiene que ser una ruta propia que empiece por «/».');
        }
        if ($porPagina < 1) {
            throw new \InvalidArgumentException('Paginacion: porPagina tiene que ser al menos 1.');
        }
    }

    public function render(): string
    {
        if ($this->total <= 0) {
            return '';
        }
        $paginas = (int) ceil($this->total / $this->porPagina);
        $actual = max(1, min($this->pagina, $paginas));
        $desde = ($actual - 1) * $this->porPagina + 1;
        $hasta = min($actual * $this->porPagina, $this->total);

        $out = '<nav class="c-pag" aria-label="Paginación" data-cifras>'
            . '<p class="c-pag-total">' . self::num($desde) . '–' . self::num($hasta) . ' de ' . self::num($this->total) . '</p>';

        if ($paginas > 1) {
            $out .= '<ul class="c-pag-num">';
            if ($actual > 1) {
                $out .= '<li><a href="' . self::e($this->url($actual - 1)) . '" rel="prev">Anterior</a></li>';
            }
            foreach (self::lista($actual, $paginas) as $p) {
                if ($p === '…') {
                    $out .= '<li aria-hidden="true">…</li>';
                } elseif ($p === $actual) {
                    $out .= '<li><span aria-current="page">' . $p . '</span></li>';
                } else {
                    $lejos = $p !== 1 && $p !== $paginas ? ' class="c-pag-lejos"' : '';
                    $out .= '<li' . $lejos . '><a href="' . self::e($this->url((int) $p)) . '">' . $p . '</a></li>';
                }
            }
            if ($actual < $paginas) {
                $out .= '<li><a href="' . self::e($this->url($actual + 1)) . '" rel="next">Siguiente</a></li>';
            }
            $out .= '</ul>';
        }

        if ($this->porPaginaOpciones !== []) {
            $ops = [];
            foreach ($this->porPaginaOpciones as $n) {
                $params = array_merge($this->criterios, ['limit' => (string) $n]);
                unset($params['page']);
                $ops[] = $n === $this->porPagina
                    ? '<span aria-current="true">' . $n . '</span>'
                    : '<a href="' . self::e($this->ruta . '?' . http_build_query($params)) . '">' . $n . '</a>';
            }
            $out .= '<p class="c-pag-tam">Por página: ' . implode(' · ', $ops) . '</p>';
        }
        return $out . '</nav>';
    }

    private function url(int $pagina): string
    {
        $params = array_merge($this->criterios, ['page' => (string) $pagina]);
        if ($this->conLimite) {
            $params['limit'] = (string) $this->porPagina;
        } else {
            unset($params['limit']);
        }
        return $this->ruta . '?' . http_build_query($params);
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /** @return list<int|string> 1 … 6 7 8 … 40 */
    private static function lista(int $actual, int $total): array
    {
        if ($total <= 7) {
            return range(1, $total);
        }
        $paginas = [1];
        if ($actual > 3) {
            $paginas[] = '…';
        }
        for ($p = max(2, $actual - 1); $p <= min($total - 1, $actual + 1); $p++) {
            $paginas[] = $p;
        }
        if ($actual < $total - 2) {
            $paginas[] = '…';
        }
        $paginas[] = $total;
        return $paginas;
    }
}
