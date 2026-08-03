<?php

declare(strict_types=1);

namespace App;

/**
 * Almacén de propuestas de cambio del editor — en FICHEROS, no en la base de
 * datos. Cada propuesta es un JSON en data/propuestas/pendientes/. El editor
 * (que trabaja en producción) las genera ahí; el admin las baja a local con
 * scripts/sync_propuestas_from_prod.php, las revisa y, al aceptarlas, se
 * aplican sobre la BD local reutilizando AdminRepo. Así la BD remota nunca se
 * escribe directamente: solo cambia cuando el admin sincroniza el .db local.
 *
 * Layout (bajo dirname(db_path)/propuestas):
 *   pendientes/<id>.json   → esperando revisión
 *   aplicadas/<id>.json    → aceptadas y aplicadas a la BD local
 *   rechazadas/<id>.json   → descartadas por el admin
 */
final class PropuestaRepo
{
    public const ENTIDADES = ['marcha', 'banda', 'autor'];
    public const ACCIONES = ['add', 'edit'];

    public static function baseDir(): string
    {
        $dbPath = (string) ($GLOBALS['config']['db_path'] ?? '');
        return dirname($dbPath) . '/propuestas';
    }

    private static function dir(string $estado): string
    {
        $d = self::baseDir() . '/' . $estado;
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Id opaco y ordenable por fecha: 20260710-153012-ab12cd. */
    private static function newId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    /** Solo nombres [A-Za-z0-9-]; evita traversal desde parámetros de ruta. */
    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{1,64}$/', $id);
    }

    /**
     * Crea una propuesta pendiente y devuelve su id.
     *
     * @param 'marcha'|'banda'|'autor' $entidad
     * @param 'add'|'edit'             $accion
     * @param array<string,mixed>      $datos       campo => valor propuestos
     * @param list<int>                $autoresIds  solo para marchas
     */
    public static function create(
        string $entidad,
        string $accion,
        ?int $targetId,
        array $datos,
        array $autoresIds,
        string $autor
    ): string {
        $id = self::newId();
        $registro = [
            'id' => $id,
            'entidad' => $entidad,
            'accion' => $accion,
            'target_id' => $targetId,
            'autor' => $autor,
            'creado_ts' => time(),
            'datos' => $datos,
            'autoresIds' => array_values($autoresIds),
            'estado' => 'pendiente',
        ];
        self::writeAtomic(self::dir('pendientes') . '/' . $id . '.json', $registro);
        return $id;
    }

    /** @param array<string,mixed> $registro */
    private static function writeAtomic(string $path, array $registro): void
    {
        $json = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Si la serialización falla (p.ej. entrada no-UTF-8), abortamos: nunca
        // dejamos un fichero de propuesta vacío o corrupto que luego el admin no
        // podría leer.
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar la propuesta: ' . json_last_error_msg());
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir la propuesta en disco.');
        }
        if (!rename($tmp, $path)) { // atómico en el mismo FS
            @unlink($tmp);
            throw new \RuntimeException('No se pudo finalizar la escritura de la propuesta.');
        }
    }

    /**
     * Propuestas pendientes, más recientes primero.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendientes(): array
    {
        $dir = self::dir('pendientes');
        $files = glob($dir . '/*.json') ?: [];
        rsort($files); // el id empieza por fecha → orden cronológico inverso
        $out = [];
        foreach ($files as $f) {
            $r = self::readFile($f);
            if ($r !== null) $out[] = $r;
        }
        return $out;
    }

    public static function countPendientes(): int
    {
        return count(glob(self::dir('pendientes') . '/*.json') ?: []);
    }

    /** @return array<string,mixed>|null */
    public static function fetchPendiente(string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        $path = self::dir('pendientes') . '/' . $id . '.json';
        return is_file($path) ? self::readFile($path) : null;
    }

    /** @return array<string,mixed>|null */
    private static function readFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Aplica una propuesta pendiente sobre la BD local (reutilizando AdminRepo) y
     * la archiva en aplicadas/. $override permite al admin ajustar los datos y/o
     * autores antes de aceptar. Devuelve el código de AdminRepo.
     *
     * @param array<string,mixed>|null $overrideDatos
     * @param list<int>|null           $overrideAutores
     * @return array{code:string, marchaId?:int, autorId?:int, bandaId?:int}
     */
    public static function aplicar(string $id, string $adminUser, ?array $overrideDatos = null, ?array $overrideAutores = null): array
    {
        $p = self::fetchPendiente($id);
        if ($p === null) return ['code' => 'NOT_FOUND'];

        $datos = $overrideDatos ?? (array) ($p['datos'] ?? []);
        $autores = $overrideAutores ?? array_map('intval', (array) ($p['autoresIds'] ?? []));
        $entidad = (string) ($p['entidad'] ?? '');
        $accion = (string) ($p['accion'] ?? '');
        $targetId = $p['target_id'] !== null ? (int) $p['target_id'] : null;

        $r = self::dispatchApply($entidad, $accion, $targetId, $datos, $autores);
        if (!in_array($r['code'] ?? '', ['CREATED', 'UPDATED'], true)) {
            return $r; // no se archiva: sigue pendiente para reintentar
        }

        self::archivar($id, 'aplicadas', $adminUser, ['datos' => $datos, 'autoresIds' => $autores, 'resultado' => $r]);
        return $r;
    }

    /**
     * @param array<string,mixed> $datos
     * @param list<int> $autores
     * @return array{code:string, marchaId?:int, autorId?:int, bandaId?:int}
     */
    private static function dispatchApply(string $entidad, string $accion, ?int $targetId, array $datos, array $autores): array
    {
        if ($entidad === 'marcha' && $accion === 'add') {
            return AdminRepo::addMarcha($datos, $autores);
        }
        if ($entidad === 'marcha' && $accion === 'edit') {
            if ($targetId === null) return ['code' => 'INVALID_TARGET'];
            $keys = [];
            $values = [];
            foreach (AdminRepo::EDITABLE_MARCHA as $f) {
                if (array_key_exists($f, $datos)) { $keys[] = $f; $values[] = $datos[$f]; }
            }
            if ($keys !== []) {
                $r = AdminRepo::editMarcha($targetId, $keys, $values);
                if (($r['code'] ?? '') !== 'UPDATED') return $r;
            }
            if ($autores !== []) {
                $r = AdminRepo::editMarchaAutores($targetId, $autores);
                if (($r['code'] ?? '') !== 'UPDATED') return $r;
            }
            return ['code' => 'UPDATED'];
        }
        if ($entidad === 'autor' && $accion === 'add') {
            return AdminRepo::addAutor($datos);
        }
        if ($entidad === 'autor' && $accion === 'edit') {
            if ($targetId === null) return ['code' => 'INVALID_TARGET'];
            [$keys, $values] = self::keysValues(AdminRepo::EDITABLE_AUTOR, $datos);
            return AdminRepo::editAutor($targetId, $keys, $values);
        }
        if ($entidad === 'banda' && $accion === 'add') {
            return AdminRepo::addBanda($datos);
        }
        if ($entidad === 'banda' && $accion === 'edit') {
            if ($targetId === null) return ['code' => 'INVALID_TARGET'];
            [$keys, $values] = self::keysValues(AdminRepo::EDITABLE_BANDA, $datos);
            return AdminRepo::editBanda($targetId, $keys, $values);
        }
        return ['code' => 'INVALID_ENTIDAD'];
    }

    /**
     * @param list<string> $editable
     * @param array<string,mixed> $datos
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function keysValues(array $editable, array $datos): array
    {
        $keys = [];
        $values = [];
        foreach ($editable as $f) {
            if (array_key_exists($f, $datos)) {
                $keys[] = $f;
                $values[] = AdminRepo::normalize($datos[$f]);
            }
        }
        return [$keys, $values];
    }

    /** Rechaza una propuesta pendiente (la archiva en rechazadas/ con motivo). */
    public static function rechazar(string $id, string $adminUser, ?string $motivo): array
    {
        $p = self::fetchPendiente($id);
        if ($p === null) return ['code' => 'NOT_FOUND'];
        self::archivar($id, 'rechazadas', $adminUser, ['motivo' => $motivo]);
        return ['code' => 'REJECTED'];
    }

    /**
     * Mueve la propuesta de pendientes/ a $estado/, añadiendo metadatos de
     * resolución. No borra nada de la BD: solo reorganiza el fichero.
     *
     * @param array<string,mixed> $extra
     */
    private static function archivar(string $id, string $estado, string $adminUser, array $extra): void
    {
        $p = self::fetchPendiente($id);
        if ($p === null) return;
        $p['estado'] = $estado === 'aplicadas' ? 'aplicada' : 'rechazada';
        $p['resuelto_ts'] = time();
        $p['resuelto_por'] = $adminUser;
        foreach ($extra as $k => $v) $p['resolucion_' . $k] = $v;

        self::writeAtomic(self::dir($estado) . '/' . $id . '.json', $p);
        @unlink(self::dir('pendientes') . '/' . $id . '.json');
    }
}
