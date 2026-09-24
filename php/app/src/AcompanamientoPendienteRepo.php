<?php

declare(strict_types=1);

namespace App;

/**
 * Lecturas de la cola de bandas sin enlazar de la carga de acompanamientos
 * de Málaga (017_acompanamiento_pendiente.sql, ver
 * php/app/tools/cargar_acompanamientos_malaga_blog.php y
 * docs/acompanamientos-nomina-2026.md). Las escrituras (enlazar a una banda,
 * lo que las convierte en `contrato`) viven en AdminRepo, junto al resto de
 * operaciones de escritura del panel.
 */
final class AcompanamientoPendienteRepo
{
    /**
     * Agrupadas por BANDA_TEXTO (para poder enlazar de golpe todas las filas
     * de la misma banda a un ID_BANDA), con el número de filas y el rango de
     * localidades/hermandades/años que afectan.
     * @return list<array{BANDA_TEXTO:string,N:int,LOCALIDADES:string,HERMANDADES:string,ANIOS:string}>
     */
    public static function agrupadasPorBanda(): array
    {
        return Db::all(
            "SELECT BANDA_TEXTO,
                    COUNT(*) AS N,
                    GROUP_CONCAT(DISTINCT LOCALIDAD) AS LOCALIDADES,
                    GROUP_CONCAT(DISTINCT HERMANDAD_SLUG) AS HERMANDADES,
                    MIN(ANIO) || '-' || MAX(ANIO) AS ANIOS
             FROM acompanamiento_pendiente
             GROUP BY BANDA_TEXTO
             ORDER BY N DESC, BANDA_TEXTO ASC"
        );
    }

    /** @return list<array<string,mixed>> */
    public static function porBanda(string $bandaTexto): array
    {
        return Db::all(
            "SELECT ap.*, p.NOMBRE AS PASO_NOMBRE
             FROM acompanamiento_pendiente ap
             JOIN paso p ON p.ID_PASO = ap.ID_PASO
             WHERE ap.BANDA_TEXTO = ?
             ORDER BY ap.LOCALIDAD, ap.HERMANDAD_SLUG, ap.ANIO",
            [$bandaTexto]
        );
    }

    public static function count(): int
    {
        $row = Db::one('SELECT COUNT(*) AS n FROM acompanamiento_pendiente');
        return (int) ($row['n'] ?? 0);
    }
}
