<?php

declare(strict_types=1);

namespace App;

/**
 * Roles y capacidades del panel de administración (definidos en código).
 *
 *   - admin  → acceso total (comodín '*').
 *   - editor → fichas completas de marcha, banda (con linaje, etapas, enlaces
 *              y acompañamientos), compositor y disco (con portada, pistas y
 *              streaming), y la nómina de Semana Santa y los acompañamientos
 *              (por localidad, dudas y bandas pendientes). NO accede a ingesta,
 *              enlaces de streaming candidatos, dedicatorias, estilos, gestión
 *              de usuarios ni revisión de propuestas.
 *
 * El editor nunca escribe en la base de datos: todo lo que envía (altas,
 * ediciones y borrados) se guarda como propuesta (ver PropuestaRepo y
 * PropuestaOps) que el admin revisa y aplica en local.
 */
final class Roles
{
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';

    /** @var list<string> */
    public const ALL = [self::ADMIN, self::EDITOR];

    /** @var array<string,string> */
    public const LABELS = [
        self::ADMIN => 'Administrador',
        self::EDITOR => 'Editor',
    ];

    /**
     * Capacidades del editor. El admin las tiene todas (comodín), así que solo
     * enumeramos aquí las del rol restringido.
     *
     * @var list<string>
     */
    public const EDITOR_CAPS = [
        'marcha.add', 'marcha.edit',
        'banda.add', 'banda.edit',
        'autor.add', 'autor.edit',
        'disco.add', 'disco.edit',
        'nomina.edit',            // /dashboard/semana-santa
        'acompanamientos.edit',   // /dashboard/acompanamientos, dudas y bandas pendientes
    ];

    /** Normaliza un valor de rol arbitrario a uno conocido (editor por defecto). */
    public static function normalize(?string $rol): string
    {
        return in_array($rol, self::ALL, true) ? (string) $rol : self::EDITOR;
    }

    public static function isAdmin(?string $rol): bool
    {
        return $rol === self::ADMIN;
    }

    /** ¿El rol tiene la capacidad $cap? El admin siempre; el editor según EDITOR_CAPS. */
    public static function has(?string $rol, string $cap): bool
    {
        if ($rol === self::ADMIN) return true;
        if (self::normalize($rol) === self::EDITOR) return in_array($cap, self::EDITOR_CAPS, true);
        return false;
    }

    public static function label(?string $rol): string
    {
        return self::LABELS[self::normalize($rol)] ?? self::normalize($rol);
    }
}
