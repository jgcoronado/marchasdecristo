<?php

declare(strict_types=1);

namespace App;

/**
 * Identidad del entorno en el que corre esta petición, en un solo sitio.
 *
 * Hasta ahora cada consumidor rehacía la deducción a mano combinando dos
 * claves de config con significados distintos:
 *
 *   - 'env'           → 'local' habilita las escrituras en BD (Db::assertWritable).
 *                       PRE y PRO lo mantienen en 'production'.
 *   - 'preproduccion' → true SOLO en el host de PRE (lo pone su env.php).
 *                       Controla noindex/robots/cinta, no la escritura.
 *
 * De ahí salen los tres entornos reales (ver docs/entornos.md):
 *
 *   local → env=local                             · BD maestra, escribe
 *   pre   → env=production + preproduccion=true   · BD de PRO en solo lectura
 *   prod  → env=production + preproduccion=false  · BD de PRO
 *
 * Los nombres coinciden con lo que imprime /health, que es como el smoke
 * remoto del pipeline distingue un host de otro tras cada deploy.
 */
final class Entorno
{
    public const LOCAL = 'local';
    public const PRE = 'pre';
    public const PROD = 'prod';

    /** @return 'local'|'pre'|'prod' */
    public static function nombre(): string
    {
        $config = $GLOBALS['config'] ?? [];
        if (($config['env'] ?? 'production') === 'local') {
            return self::LOCAL;
        }
        return !empty($config['preproduccion']) ? self::PRE : self::PROD;
    }

    public static function esLocal(): bool
    {
        return self::nombre() === self::LOCAL;
    }

    /**
     * ¿Puede este entorno escribir directamente en la BD?
     *
     * Espejo en LECTURA del fail-safe de Db::assertWritable(): sirve para
     * decidir qué ofrece la interfaz (propuesta en vez de guardado directo,
     * aviso de desincronización) antes de intentar una escritura que acabaría
     * en un 503. La única barrera real sigue siendo la de Db.
     *
     * Solo local escribe porque la BD maestra es la local: PRE y PRO comparten
     * el .db de producción y ese fichero lo reemplaza entero
     * scripts/sync_db_to_prod.php, así que cualquier cambio hecho ahí se
     * perdería (o pisaría datos buenos) en el siguiente sync.
     */
    public static function permiteEscrituraDirecta(): bool
    {
        return self::esLocal();
    }
}
