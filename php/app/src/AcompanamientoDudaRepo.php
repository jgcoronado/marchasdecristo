<?php

declare(strict_types=1);

namespace App;

/**
 * Lecturas de la cola de revisión de acompanamiento_duda (013_acompanamiento_duda.sql),
 * ver /dashboard/acompanamientos-dudas. Las escrituras (resolver/descartar)
 * viven en AdminRepo, junto al resto de operaciones de escritura del panel.
 */
final class AcompanamientoDudaRepo
{
    public const TIPOS = ['cristo', 'virgen', 'cruz_guia', 'ninguno'];

    /** Cruces de guía ocultas de momento (ver Repo::sqlSinCruzDeGuia): aquí la
     *  etiqueta es texto libre ("Cruz de Guía (Sección de la Virgen)"), así que
     *  se mira si EMPIEZA por "Cruz de Guía". */
    private const SIN_CRUZ_GUIA = "lower(replace(replace(trim(COALESCE(ETIQUETA_RAW, '')), 'í', 'i'), 'Í', 'i')) NOT LIKE 'cruz de guia%'";

    public const MOTIVO_LABEL = [
        'tipo_paso_ambiguo' => 'Etiqueta de paso ambigua',
        'sin_musica_detectada' => 'Sin música detectada en la ficha',
    ];

    /** @return list<array<string,mixed>> */
    public static function pendientes(): array
    {
        return Db::all(
            "SELECT * FROM acompanamiento_duda WHERE ESTADO = 'pendiente' AND " . self::SIN_CRUZ_GUIA . "
             ORDER BY LOCALIDAD, DIA, HERMANDAD"
        );
    }

    public static function countPendientes(): int
    {
        $row = Db::one("SELECT COUNT(*) AS n FROM acompanamiento_duda WHERE ESTADO = 'pendiente' AND " . self::SIN_CRUZ_GUIA);
        return (int) ($row['n'] ?? 0);
    }

    /** @return list<array<string,mixed>> Ya resueltas o descartadas, más recientes primero. */
    public static function revisadas(int $limit = 100): array
    {
        return Db::all(
            "SELECT * FROM acompanamiento_duda WHERE ESTADO != 'pendiente' AND " . self::SIN_CRUZ_GUIA . "
             ORDER BY REVIEWED_AT DESC LIMIT ?",
            [$limit]
        );
    }
}
