<?php

declare(strict_types=1);

namespace App;

/**
 * Listas de provincias (ISO 3166-2:ES → nombre en la BD) que usan el explorador
 * de compositores, los municipios y acompañamientos. Es lo que queda de la
 * coropleta /mapa (N-10), retirada el 2026-10-01 — ver docs/roadmap.md para
 * recuperar el render SVG desde git.
 */
final class Mapa
{
    /**
     * Código ISO 3166-2:ES => nombre de provincia, tal cual aparece en el SVG
     * base y coincide con marcha.PROVINCIA/banda.PROVINCIA en la BD (ambos
     * heredan la forma castellana histórica: "La Coruña", "Gerona"…).
     */
    public const PROVINCIAS = [
        'ES-A' => 'Alicante', 'ES-AB' => 'Albacete', 'ES-AL' => 'Almería',
        'ES-AV' => 'Ávila', 'ES-B' => 'Barcelona', 'ES-BA' => 'Badajoz',
        'ES-BI' => 'Vizcaya', 'ES-BU' => 'Burgos', 'ES-C' => 'La Coruña',
        'ES-CA' => 'Cádiz', 'ES-CC' => 'Cáceres', 'ES-CE' => 'Ceuta',
        'ES-CO' => 'Córdoba', 'ES-CR' => 'Ciudad Real', 'ES-CS' => 'Castellón',
        'ES-CU' => 'Cuenca', 'ES-GC' => 'Las Palmas', 'ES-GI' => 'Gerona',
        'ES-GR' => 'Granada', 'ES-GU' => 'Guadalajara', 'ES-H' => 'Huelva',
        'ES-HU' => 'Huesca', 'ES-J' => 'Jaén', 'ES-L' => 'Lérida',
        'ES-LE' => 'León', 'ES-LO' => 'La Rioja', 'ES-LU' => 'Lugo',
        'ES-M' => 'Madrid', 'ES-MA' => 'Málaga', 'ES-ML' => 'Melilla',
        'ES-MU' => 'Murcia', 'ES-NA' => 'Navarra', 'ES-O' => 'Asturias',
        'ES-OR' => 'Orense', 'ES-P' => 'Palencia', 'ES-PM' => 'Baleares',
        'ES-PO' => 'Pontevedra', 'ES-S' => 'Cantabria', 'ES-SA' => 'Salamanca',
        'ES-SE' => 'Sevilla', 'ES-SG' => 'Segovia', 'ES-SO' => 'Soria',
        'ES-SS' => 'Guipúzcoa', 'ES-T' => 'Tarragona', 'ES-TE' => 'Teruel',
        'ES-TF' => 'Santa Cruz de Tenerife', 'ES-TO' => 'Toledo', 'ES-V' => 'Valencia',
        'ES-VA' => 'Valladolid', 'ES-VI' => 'Álava', 'ES-Z' => 'Zaragoza',
        'ES-ZA' => 'Zamora',
    ];

    /** Provincias andaluzas tal como aparecen en marcha.PROVINCIA (Sevilla primero). */
    private const PROVINCIAS_ANDALUCIA = [
        'Sevilla', 'Almería', 'Cádiz', 'Córdoba', 'Granada', 'Huelva', 'Jaén', 'Málaga',
    ];

    /**
     * Orden del selector de provincia del explorador de compositores (/autor):
     * Andalucía primero con Sevilla a la cabeza, luego el resto de España en
     * orden alfabético. Pedido así porque el catálogo es abrumadoramente
     * sevillano y es donde va a mirar la mayoría de las búsquedas.
     *
     * @return list<string>
     */
    public static function provinciasOrdenExplorador(): array
    {
        $todas = array_values(self::PROVINCIAS);
        $restoAndalucia = array_values(array_diff(self::PROVINCIAS_ANDALUCIA, ['Sevilla']));
        sort($restoAndalucia, SORT_LOCALE_STRING);
        $restoEspana = array_values(array_diff($todas, self::PROVINCIAS_ANDALUCIA));
        sort($restoEspana, SORT_LOCALE_STRING);
        return array_merge(['Sevilla'], $restoAndalucia, $restoEspana);
    }
}
