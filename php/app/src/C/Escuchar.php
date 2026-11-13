<?php

declare(strict_types=1);

namespace App\C;

use App\Html;
use App\Media;

/**
 * Escuchar: lista de texto con el nombre de cada servicio en el color de
 * enlace y, delante, su icono monocromo de 16 px (líneas maestras §2,
 * principio 7; MB). Sin colores ni botones de marca, sin reproductor
 * incrustado: nada de terceros hasta que el visitante pulsa.
 *
 * Sin ningún enlace, una frase (no una caja) que pide la grabación.
 *
 * $grupos: [rótulo => [servicio => url]]. Con un solo grupo, el rótulo no se
 * pinta (pasa ''). Con varios (versión original y actual), cada lista lleva
 * su rótulo como texto, no como pestaña ni segmentado (principio 8). Un grupo
 * vacío no se pinta.
 *
 * Clases stream-<servicio> en cada enlace: las mira ci_smoke.php.
 */
final class Escuchar extends Componente
{
    /**
     * @param array<string, array<string,string>> $grupos
     */
    public function __construct(
        private readonly array $grupos,
        private readonly string|Texto $vacio,
    ) {
    }

    /**
     * Escuchar de una ficha de marcha, con la misma lógica que tenía
     * Html::escuchar: marcha.AUDIO entra como un enlace más de la versión
     * actual (gana al curado del mismo servicio: lo puso un humano a mano), y
     * con antigüedad suficiente se separan versión original y actual.
     *
     * @param array{original: array<string,string>, actual: array<string,string>} $porVersion
     */
    public static function marcha(array $porVersion, ?string $audio, bool $conVersiones): self
    {
        $original = $porVersion['original'];
        $actual = $porVersion['actual'];
        $audio = trim((string) $audio);
        if ($audio !== '' && preg_match('~^https?://~i', $audio) === 1) {
            $svc = Media::embedDeUrl($audio)['servicio'] ?? 'otro';
            $actual = [$svc => $audio] + $actual;
        }
        $grupos = $conVersiones
            ? ['Versión original' => $original, 'Versión actual' => $actual]
            : ['' => $actual + $original];
        $vacio = Texto::de('Todavía no tenemos ninguna grabación enlazada de esta marcha. Si conoces una, ')
            ->enlace('proponla', '/contacto')->texto('.');
        return new self($grupos, $vacio);
    }

    public function render(): string
    {
        $grupos = array_filter($this->grupos, static fn(array $g): bool => $g !== []);
        if ($grupos === []) {
            return '<p class="c-escuchar-vacio">' . self::valor($this->vacio) . '</p>';
        }
        $conRotulo = count($this->grupos) > 1;
        $out = '<div class="c-escuchar">';
        foreach ($grupos as $rotulo => $enlaces) {
            $items = self::items($enlaces);
            if ($items === '') {
                continue;
            }
            if ($conRotulo && $rotulo !== '') {
                $out .= '<p class="c-escuchar-rotulo">' . self::e($rotulo) . '</p>';
            }
            $out .= '<ul class="c-escuchar-lista">' . $items . '</ul>';
        }
        return $out . '</div>';
    }

    /** @param array<string,string> $enlaces */
    private static function items(array $enlaces): string
    {
        // Orden canónico de servicios, con los no reconocidos al final: las
        // dos versiones presentan los mismos servicios en la misma posición.
        $ordenado = [];
        foreach (array_keys(Html::STREAMING_LABELS) as $s) {
            if (isset($enlaces[$s])) {
                $ordenado[$s] = $enlaces[$s];
            }
        }
        $ordenado += $enlaces;

        $out = '';
        foreach ($ordenado as $servicio => $url) {
            $href = Seguro::href($url);
            if ($href === null) {
                continue;
            }
            $servicio = (string) $servicio;
            $nombre = Html::STREAMING_LABELS[$servicio] ?? ($servicio === 'otro' ? 'Escuchar' : ucfirst($servicio));
            // Servicio sin icono: el triángulo de «reproducir».
            $ruta = Html::STREAMING_ICONOS[$servicio] ?? 'M6 3.5v17l14-8.5z';
            $clase = 'stream-' . (preg_replace('/[^a-z0-9-]/', '', strtolower($servicio)) ?? 'otro');
            $out .= '<li><a class="' . self::e($clase) . '" href="' . $href . '" target="_blank" rel="noopener noreferrer nofollow">'
                . '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . self::e($ruta) . '"/></svg>'
                . self::e($nombre) . '</a></li>';
        }
        return $out;
    }
}
