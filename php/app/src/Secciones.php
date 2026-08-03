<?php

declare(strict_types=1);

namespace App;

/**
 * Qué secciones públicas se publican en cada entorno.
 *
 * Algunas secciones están terminadas a nivel de código pero todavía no tienen
 * el grado de madurez (datos, curación o pulido) para enseñarlas fuera de
 * local. En vez de borrarlas o de dejarlas colgando de condiciones repartidas
 * por el código, se listan aquí: siguen enteras y se republican cambiando esta
 * lista, sin tocar rutas, plantillas ni sitemap.
 *
 * Reglas:
 *   - Una sección que NO esté en EN_MADURACION es visible en todos los entornos.
 *   - Una sección en EN_MADURACION es visible solo en local…
 *   - …salvo que el host la publique explícitamente con la clave de config
 *     'secciones_publicadas' (lista de slugs). Eso permite enseñar una sección
 *     primero en PRE, validarla con datos reales y publicarla después en PRO,
 *     sin desplegar código nuevo. Cuando ya esté publicada en los dos, lo
 *     limpio es sacarla de EN_MADURACION y quitarla de los config.local.php.
 *
 * Ocultar una sección la apaga a la vez en sus CUATRO superficies: la ruta
 * (404), el enlace del nav, el sitemap.xml y llms.txt. Anunciar en un sitemap
 * una URL que el propio sitio responde con 404 es peor que no anunciarla.
 */
final class Secciones
{
    public const DEDICATORIAS = 'dedicatorias';
    public const ESTADO_CATALOGO = 'estado-catalogo';
    public const MAPA = 'mapa';
    public const TEMPORADA = 'temporada';

    /**
     * Secciones no publicadas todavía fuera de local, con el motivo por el que
     * esperan. Quitar una entrada de aquí = publicarla en todos los entornos.
     *
     * @var array<string,string>
     */
    public const EN_MADURACION = [
        // N-01/N-02 · La curación de advocaciones (alias, unificaciones,
        // dedicatorias personales) sigue en marcha; hasta que el índice esté
        // estable no se enseña fuera de local.
        self::DEDICATORIAS => 'Dedicatorias',
        // R-07 · El KPI de cobertura de audio se lee como una foto del estado
        // real del catálogo: solo tiene sentido publicarlo cuando la campaña de
        // audio (P1 · M2) haya dejado la cobertura en un número presentable.
        self::ESTADO_CATALOGO => 'Estado del catálogo',
        // N-10 · Pendiente de corregir el solape de dianas de clic entre
        // municipios próximos (Castilleja de la Cuesta / Tomares).
        self::MAPA => 'Mapa',
        // N-04 · `contrato` es de alta manual y todavía no tiene datos de
        // calidad suficiente para una página pública.
        self::TEMPORADA => 'Temporada',
    ];

    /** ¿Se muestra $seccion en este entorno? */
    public static function visible(string $seccion): bool
    {
        if (!isset(self::EN_MADURACION[$seccion])) {
            return true; // el resto del sitio no depende del entorno
        }
        if (Entorno::esLocal()) {
            return true; // local es donde se rellenan y se validan
        }
        $publicadas = $GLOBALS['config']['secciones_publicadas'] ?? [];
        return is_array($publicadas) && in_array($seccion, $publicadas, true);
    }
}
