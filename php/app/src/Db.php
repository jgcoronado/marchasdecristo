<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Se lanza cuando el panel intenta escribir en la BD fuera del entorno local.
 * Router::dispatch la captura para mostrar un aviso en vez de un error crudo.
 */
final class ReadOnlyModeException extends RuntimeException
{
}

/**
 * Acceso a SQLite vía PDO. Singleton perezoso + prepared statements.
 * Espejo intencionado de nextjs/lib/db.ts (all/run) para portar el resto sin fricción.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Usuario que se registra en admin_log; lo fija Auth::requireAuth por petición. */
    private static string $auditUser = 'system';

    /** Si `self::$auditUser` ya se ha publicado en `log_actor` para los triggers de cambio_log. */
    private static bool $actorSynced = false;

    public static function setAuditUser(string $user): void
    {
        self::$auditUser = $user !== '' ? $user : 'system';
        self::$actorSynced = false;
    }

    /**
     * Publica el actor actual en `log_actor` para que lo lean los triggers de
     * cambio_log (SQLite no tiene variables de sesión). Una vez por petición
     * o script, justo antes de la primera escritura: las peticiones de solo
     * lectura no ejecutan ningún UPDATE extra. Ver docs/plan-log-cambios.md.
     */
    private static function syncActor(): void
    {
        if (self::$actorSynced) {
            return;
        }
        self::$actorSynced = true;
        self::pdo()->prepare('UPDATE log_actor SET ACTOR = ? WHERE ID = 1')
                    ->execute([self::$auditUser]);
    }

    /** Usuario de la petición actual, para guardarlo en tablas propias (no solo en admin_log). */
    public static function auditUser(): string
    {
        return self::$auditUser;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = (string) ($GLOBALS['config']['db_path'] ?? '');
        // No dejamos que PDO CREE una BD vacía si el fichero no existe: sería un
        // falso "todo va bien" con cero tablas. Mejor un error claro.
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException("Base de datos no encontrada en: {$path} — descarga mdc.db (ver php/README.md).");
        }

        // Pdo\Sqlite (PHP 8.4+) expone createFunction() sin deprecar; una
        // instancia PDO "plana" solo tiene el alias sqliteCreateFunction(),
        // deprecado desde PHP 8.5. Instanciar la subclase evita el aviso.
        $pdoClass = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : PDO::class;
        $pdo = new $pdoClass('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // WAL para lecturas concurrentes; si el FS del host no lo admite,
        // SQLite mantiene el modo anterior y seguimos funcionando.
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (Throwable) {
            // journal_mode se queda como esté; irrelevante con un solo admin.
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // PDO::sqliteCreateFunction() está deprecado desde PHP 8.5 en favor de
        // Pdo\Sqlite::createFunction() (PHP 8.4+); el fallback cubre PHP <8.4.
        if (method_exists($pdo, 'createFunction')) {
            $pdo->createFunction('NOACC', [self::class, 'noAcc'], 1);
        } else {
            $pdo->sqliteCreateFunction('NOACC', [self::class, 'noAcc'], 1);
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * Minúsculas + sin diacríticos, para búsquedas LIKE insensibles a mayúsculas
     * y acentos (p.ej. "redencion" encuentra "Redención"). Expuesta a SQLite como
     * función NOACC(), y usada también en PHP para normalizar el parámetro del bind.
     */
    public static function noAcc(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }
        $lower = mb_strtolower($s, 'UTF-8');
        $decomposed = \Normalizer::normalize($lower, \Normalizer::FORM_D);
        if ($decomposed === false) {
            return $lower;
        }
        return (string) preg_replace('/\p{Mn}/u', '', $decomposed);
    }

    /**
     * @param  list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * @param  list<mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Bloquea las escrituras fuera del entorno local (config 'env' => 'local').
     * Los editores nunca llegan aquí: sus cambios van a PropuestaRepo (JSON),
     * no a la BD. Este guard cubre al admin y a las pantallas que sí escriben
     * directo, para que producción se mantenga de solo lectura hasta que se
     * sincronice el .db a mano.
     */
    private static function assertWritable(): void
    {
        $env = (string) ($GLOBALS['config']['env'] ?? 'production');
        if ($env !== 'local') {
            throw new ReadOnlyModeException(
                "Escritura en BD deshabilitada: entorno '{$env}' (solo 'local' permite escribir)."
            );
        }
    }

    /**
     * Devuelve el número de filas afectadas.
     *
     * @param list<mixed> $params
     */
    public static function run(string $sql, array $params = []): int
    {
        self::assertWritable();
        self::syncActor();
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Ejecuta $fn dentro de una transacción (commit/rollback automático). */
    public static function transaction(callable $fn): mixed
    {
        self::assertWritable();
        self::syncActor();
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Registro de auditoría (equivale a lib/db.ts logAdmin). */
    public static function logAdmin(string $accion, string $tabla, ?int $idRegistro, mixed $payload = null): void
    {
        self::run(
            'INSERT INTO admin_log (accion, tabla, id_registro, usuario, ts, payload) VALUES (?, ?, ?, ?, ?, ?)',
            [$accion, $tabla, $idRegistro, self::$auditUser, time(), $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null]
        );
    }

    /**
     * Conteos globales para el pie de página (equivalente a fetchEstado()).
     *
     * @return array{MARCHAS:int,AUTORES:int,BANDAS:int,DISCOS:int}
     */
    public static function counts(): array
    {
        $row = self::one(
            'SELECT (SELECT COUNT(*) FROM marcha) AS MARCHAS,
                    (SELECT COUNT(*) FROM autor)  AS AUTORES,
                    (SELECT COUNT(*) FROM banda)  AS BANDAS,
                    (SELECT COUNT(*) FROM disco)  AS DISCOS'
        );

        return [
            'MARCHAS' => (int) ($row['MARCHAS'] ?? 0),
            'AUTORES' => (int) ($row['AUTORES'] ?? 0),
            'BANDAS'  => (int) ($row['BANDAS'] ?? 0),
            'DISCOS'  => (int) ($row['DISCOS'] ?? 0),
        ];
    }
}
