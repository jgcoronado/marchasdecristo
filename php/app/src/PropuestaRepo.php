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
 * Dos tipos de propuesta:
 *   - ficha (entidad marcha|banda|autor, accion add|edit): el formulario entero
 *     de la ficha, con previsualización; el admin puede retocar los valores.
 *   - operación (entidad 'operacion', accion = clave de PropuestaOps): el resto
 *     del panel (pistas, etapas, Semana Santa, acompañamientos…). Lleva una
 *     descripción legible y la pantalla donde se pidió ('seccion'); el admin
 *     solo acepta o rechaza. Puede llevar adjuntos (la portada de un disco):
 *     ficheros <id>.<nombre>.<ext> junto al JSON, que viajan con él.
 *
 * Layout (bajo dirname(db_path)/propuestas):
 *   pendientes/<id>.json   → esperando revisión
 *   recibidas/<id>.json    → solo en producción: ya bajadas a local por
 *                            sync_propuestas_from_prod.php
 *   aplicadas/<id>.json    → aceptadas y aplicadas a la BD local
 *   rechazadas/<id>.json   → descartadas por el admin
 *
 * La resolución (aplicada/rechazada) se apunta además en admin_log (tabla
 * 'propuesta'): como el .db local se sube entero a producción, así le llega al
 * editor el estado de lo que propuso (ver delAutor / resueltasDe).
 */
final class PropuestaRepo
{
    public const ENTIDADES = ['marcha', 'banda', 'autor', 'operacion'];
    public const ACCIONES = ['add', 'edit']; // solo fichas; en una operación, la clave de PropuestaOps

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
        string $autor,
        array $extra = []
    ): string {
        self::comprobarCupo($autor);
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
        ] + $extra;
        self::writeAtomic(self::dir('pendientes') . '/' . $id . '.json', $registro);
        return $id;
    }

    /** Propuestas sin resolver que puede acumular un mismo autor. */
    public const MAX_PENDIENTES_POR_AUTOR = 300;
    /** Tamaño máximo del JSON de una propuesta (sin adjuntos). */
    public const MAX_BYTES = 262144;

    /**
     * Corta el alta si el autor ya tiene el cupo de propuestas sin resolver.
     * Las propuestas se escriben en el disco de producción, donde el editor
     * trabaja: sin tope, un bucle de envíos (o una cuenta comprometida)
     * llenaría el disco del alojamiento.
     *
     * @throws PropuestaNoAdmitida
     */
    private static function comprobarCupo(string $autor): void
    {
        if (count(self::pendientesDe($autor)) >= self::MAX_PENDIENTES_POR_AUTOR) {
            throw new PropuestaNoAdmitida('CUPO_PROPUESTAS');
        }
    }

    /**
     * Crea una propuesta de OPERACIÓN pendiente y devuelve su id.
     *
     * @param array<string,mixed>  $params    parámetros de PropuestaOps::aplicar
     * @param string               $seccion   ruta del panel donde se pidió (aviso «pendientes aquí»)
     * @param array<string,string> $adjuntos  nombre => fichero ya validado; se MUEVE junto al JSON
     */
    public static function createOperacion(
        string $op,
        array $params,
        string $autor,
        string $descripcion,
        string $seccion,
        array $adjuntos = []
    ): string {
        $id = self::newId();
        $dir = self::dir('pendientes');
        $guardados = [];
        try {
            self::comprobarCupo($autor);
            foreach ($adjuntos as $nombre => $origen) {
                $fichero = $id . '.' . $nombre . '.' . strtolower(pathinfo($origen, PATHINFO_EXTENSION));
                if (!@rename($origen, $dir . '/' . $fichero)) {
                    throw new \RuntimeException('No se pudo guardar el adjunto de la propuesta.');
                }
                $guardados[$nombre] = $fichero;
            }
            self::writeAtomic($dir . '/' . $id . '.json', [
                'id' => $id,
                'entidad' => 'operacion',
                'accion' => $op,
                'target_id' => null,
                'autor' => $autor,
                'creado_ts' => time(),
                'descripcion' => $descripcion,
                'seccion' => $seccion,
                'datos' => $params,
                'autoresIds' => [],
                'adjuntos' => $guardados,
                'estado' => 'pendiente',
            ]);
        } catch (\Throwable $e) {
            // Ni el adjunto ya movido ni el temporal se quedan huérfanos.
            foreach ($guardados as $f) @unlink($dir . '/' . $f);
            foreach ($adjuntos as $origen) @unlink($origen);
            throw $e;
        }
        return $id;
    }

    /**
     * Propuesta del propio $autor aún sin resolver (pendiente aquí o ya bajada
     * a local), o null. Es la única de la que puede colgar otra suya.
     *
     * @return array<string,mixed>|null
     */
    public static function propiaSinResolver(string $autor, string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        foreach (self::pendientesDe($autor) as $p) {
            if (($p['id'] ?? null) === $id) return $p;
        }
        return null;
    }

    /**
     * Estado de una propuesta en este host y, si se aplicó, su resultado.
     *
     * @return array{estado:'pendiente'|'aplicada'|'rechazada'|'desconocida', resultado?:array<string,mixed>}
     */
    public static function estadoDe(string $id): array
    {
        if (!self::isValidId($id)) return ['estado' => 'desconocida'];
        foreach (['pendientes' => 'pendiente', 'recibidas' => 'pendiente', 'aplicadas' => 'aplicada', 'rechazadas' => 'rechazada'] as $dir => $estado) {
            $path = self::baseDir() . '/' . $dir . '/' . $id . '.json';
            if (!is_file($path)) continue;
            $r = self::readFile($path);
            if ($r === null) continue;
            return $estado === 'aplicada'
                ? ['estado' => 'aplicada', 'resultado' => (array) ($r['resolucion_resultado'] ?? [])]
                : ['estado' => $estado];
        }
        return ['estado' => 'desconocida'];
    }

    /**
     * Sustituye en los parámetros de una operación las referencias a otras
     * propuestas ('refs' => [campo => idPropuesta]) por el id que creó cada
     * una al aplicarse. Así un editor puede proponer la hermandad de un día que
     * él mismo acaba de proponer, sin esperar a que se acepte.
     *
     * @param array<string,mixed> $p
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>} [parámetros resueltos, error]
     */
    public static function resolverRefs(array $p): array
    {
        $refs = (array) ($p['refs'] ?? []);
        foreach ($refs as $campo => $idProp) {
            $e = self::estadoDe((string) $idProp);
            if ($e['estado'] === 'pendiente') return [null, ['code' => 'DEPENDE_DE_PENDIENTE', 'depende' => (string) $idProp]];
            $nuevo = (int) ($e['resultado']['id'] ?? 0);
            if ($e['estado'] !== 'aplicada' || $nuevo <= 0) return [null, ['code' => 'DEPENDENCIA_NO_APLICADA', 'depende' => (string) $idProp]];
            $p[(string) $campo] = $nuevo;
        }
        unset($p['refs']);
        return [$p, null];
    }

    /** Ruta temporal (en pendientes/, mismo FS que el destino) para preparar un adjunto. */
    public static function rutaAdjuntoTemporal(string $ext): string
    {
        return self::dir('pendientes') . '/.adjunto-' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    /**
     * Rutas absolutas de los adjuntos de una propuesta pendiente.
     *
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    public static function adjuntosDe(array $p, string $estado = 'pendientes'): array
    {
        $out = [];
        foreach ((array) ($p['adjuntos'] ?? []) as $nombre => $fichero) {
            if (!is_string($fichero) || !preg_match('/^[A-Za-z0-9-]+\.[a-z]+\.[a-z0-9]+$/', $fichero)) continue;
            $out[(string) $nombre] = self::dir($estado) . '/' . $fichero;
        }
        return $out;
    }

    /** ¿Es una propuesta de operación (y no de ficha)? @param array<string,mixed> $p */
    public static function esOperacion(array $p): bool
    {
        return ($p['entidad'] ?? '') === 'operacion';
    }

    /**
     * Pantalla del panel a la que se refiere la propuesta: la que guardó la
     * operación o, en una edición de ficha, la de ese registro. Null en las
     * altas de ficha (aún no hay pantalla).
     *
     * @param array<string,mixed> $p
     */
    public static function seccionDe(array $p): ?string
    {
        if (isset($p['seccion']) && is_string($p['seccion'])) return $p['seccion'];
        if (($p['accion'] ?? '') === 'edit' && ($p['target_id'] ?? null) !== null) {
            return '/dashboard/' . (string) ($p['entidad'] ?? '') . '/' . (int) $p['target_id'];
        }
        return null;
    }

    /**
     * Resumen legible de una propuesta, para listados y el email al editor.
     *
     * @param array<string,mixed> $p
     */
    public static function resumen(array $p): string
    {
        if (self::esOperacion($p)) return (string) ($p['descripcion'] ?? $p['accion'] ?? 'operación');
        $datos = (array) ($p['datos'] ?? []);
        $v = static fn(string $k): string => trim((string) ($datos[$k] ?? ''));
        return match ($p['entidad'] ?? '') {
            'marcha' => $v('TITULO') ?: 'marcha sin título',
            'autor'  => trim($v('NOMBRE') . ' ' . $v('APELLIDOS')) ?: 'compositor sin nombre',
            'banda'  => $v('NOMBRE_COMPLETO') ?: ($v('NOMBRE_BREVE') ?: 'banda sin nombre'),
            default  => 'propuesta',
        };
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
        // Solo las altas (pendientes/) vienen de un formulario del editor; el
        // archivado de una ya admitida no se recorta.
        if (strlen($json) > self::MAX_BYTES && basename(dirname($path)) === 'pendientes') {
            throw new PropuestaNoAdmitida('PROPUESTA_DEMASIADO_GRANDE');
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
     * @return array<string,mixed> con 'code'; en una ficha, además marchaId/autorId/bandaId;
     *                             en una operación, lo que devuelva PropuestaOps::aplicar
     */
    public static function aplicar(string $id, string $adminUser, ?array $overrideDatos = null, ?array $overrideAutores = null): array
    {
        return self::conCerrojo(static function () use ($id, $adminUser, $overrideDatos, $overrideAutores): array {
            // Releída bajo el cerrojo: si otra petición la resolvió mientras
            // tanto (doble clic, dos pestañas), aquí ya no está.
            $p = self::fetchPendiente($id);
            if ($p === null) return ['code' => 'NOT_FOUND'];

            // Operación: se aplica tal cual la pidió el editor (sin retoques),
            // una vez resueltas las referencias a propuestas de las que cuelga.
            if (self::esOperacion($p)) {
                [$datos, $error] = self::resolverRefs((array) ($p['datos'] ?? []));
                if ($error !== null) return $error; // sigue pendiente
                $op = (string) ($p['accion'] ?? '');
                $r = PropuestaOps::aplicar($op, (array) $datos, self::adjuntosDe($p));
                if (!PropuestaOps::esExito($r)) return $r; // sigue pendiente para reintentar
                self::archivar($id, 'aplicadas', $adminUser, ['resultado' => $r]);
                return $r;
            }

            $datos = $overrideDatos ?? (array) ($p['datos'] ?? []);
            $autores = $overrideAutores ?? array_map('intval', (array) ($p['autoresIds'] ?? []));
            $entidad = (string) ($p['entidad'] ?? '');
            $accion = (string) ($p['accion'] ?? '');
            $targetId = $p['target_id'] !== null ? (int) $p['target_id'] : null;

            // Campos y autores de una ficha van juntos: o todo o nada.
            $r = Db::transaction(static function () use ($entidad, $accion, $targetId, $datos, $autores): array {
                $r = self::dispatchApply($entidad, $accion, $targetId, $datos, $autores);
                if (!in_array($r['code'] ?? '', ['CREATED', 'UPDATED'], true)) throw new AplicacionFallida($r);
                return $r;
            });

            self::archivar($id, 'aplicadas', $adminUser, ['datos' => $datos, 'autoresIds' => $autores, 'resultado' => $r]);
            return $r;
        });
    }

    /**
     * Ejecuta $fn con el cerrojo exclusivo del almacén: aceptar y rechazar no
     * pueden solaparse, ni consigo mismos ni entre sí.
     *
     * @param callable(): array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private static function conCerrojo(callable $fn): array
    {
        $fh = @fopen(self::dir('pendientes') . '/.cerrojo', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if ($fh !== false) fclose($fh);
            return ['code' => 'CERROJO_NO_DISPONIBLE'];
        }
        try {
            try {
                return $fn();
            } catch (AplicacionFallida $e) {
                return $e->resultado; // rollback ya hecho; sigue pendiente
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
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
            // Normalizado igual que en la edición directa del admin.
            [$keys, $values] = self::keysValues(AdminRepo::EDITABLE_MARCHA, $datos);
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
        return self::conCerrojo(static function () use ($id, $adminUser, $motivo): array {
            $p = self::fetchPendiente($id);
            if ($p === null) return ['code' => 'NOT_FOUND'];
            self::archivar($id, 'rechazadas', $adminUser, ['motivo' => $motivo]);
            return ['code' => 'REJECTED'];
        });
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

        // Los adjuntos acompañan al JSON a su carpeta final.
        foreach (self::adjuntosDe($p) as $ruta) {
            if (is_file($ruta)) @rename($ruta, self::dir($estado) . '/' . basename($ruta));
        }
        self::writeAtomic(self::dir($estado) . '/' . $id . '.json', $p);
        @unlink(self::dir('pendientes') . '/' . $id . '.json');

        // Rastro en la BD para que el editor vea el estado en «Mis propuestas»
        // cuando el .db local llegue a producción. Solo donde se puede escribir
        // (local): en PRE/PRO la BD es de solo lectura. Si el apunte falla, la
        // propuesta queda archivada igual — reabrirla volvería a aplicarla.
        if (Entorno::permiteEscrituraDirecta()) {
            try {
                Db::logAdmin($p['estado'] === 'aplicada' ? 'PROPUESTA_APLICADA' : 'PROPUESTA_RECHAZADA', 'propuesta', null, [
                    'id' => $id,
                    'autor' => (string) ($p['autor'] ?? ''),
                    'resumen' => self::resumen($p),
                    'seccion' => self::seccionDe($p),
                    'motivo' => $extra['motivo'] ?? null,
                ]);
            } catch (\Throwable $e) {
                error_log('[propuestas] no se pudo apuntar la resolución de ' . $id . ': ' . $e->getMessage());
            }
        }
    }

    // ── Vista del editor («Mis propuestas») ────────────────────────────────

    /**
     * Propuestas de $autor que aún no se han resuelto, más recientes primero.
     * En producción incluye las ya bajadas a local (recibidas/) cuya
     * resolución todavía no ha llegado con el .db.
     *
     * @return list<array<string,mixed>>
     */
    public static function pendientesDe(string $autor): array
    {
        $propias = [];
        foreach (['pendientes', 'recibidas'] as $estado) {
            $dir = self::baseDir() . '/' . $estado;
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $r = self::readFile($f);
                if ($r !== null && ($r['autor'] ?? null) === $autor) $propias[(string) ($r['id'] ?? basename($f, '.json'))] = $r;
            }
        }
        if ($propias === []) return [];
        $resueltas = array_column(self::resueltasDe($autor, PHP_INT_MAX), null, 'id');
        $out = array_values(array_filter($propias, static fn(array $r): bool => !isset($resueltas[(string) ($r['id'] ?? '')])));
        usort($out, static fn(array $a, array $b): int => strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')));
        return $out;
    }

    /** Cuántas propuestas sin resolver tiene $autor sobre la pantalla $seccion. */
    public static function pendientesEn(string $autor, string $seccion): int
    {
        return count(array_filter(self::pendientesDe($autor), static fn(array $p): bool => self::seccionDe($p) === $seccion));
    }

    /**
     * Propuestas resueltas de $autor (aplicadas o rechazadas), más recientes
     * primero: las apuntadas en admin_log (llegan a producción con el .db) y
     * las archivadas en este host, sin repetir.
     *
     * @return list<array{id:string,estado:string,resumen:string,seccion:?string,motivo:?string,ts:int}>
     */
    public static function resueltasDe(string $autor, int $limite = 100): array
    {
        $out = [];
        try {
            $filas = Db::all(
                "SELECT accion, ts, payload FROM admin_log
                 WHERE tabla = 'propuesta' AND accion IN ('PROPUESTA_APLICADA', 'PROPUESTA_RECHAZADA')
                 ORDER BY ts DESC"
            );
        } catch (\Throwable) {
            $filas = [];
        }
        foreach ($filas as $f) {
            $d = json_decode((string) ($f['payload'] ?? ''), true);
            if (!is_array($d) || ($d['autor'] ?? null) !== $autor || !is_string($d['id'] ?? null)) continue;
            $out[$d['id']] ??= [
                'id' => $d['id'],
                'estado' => $f['accion'] === 'PROPUESTA_APLICADA' ? 'aplicada' : 'rechazada',
                'resumen' => (string) ($d['resumen'] ?? ''),
                'seccion' => is_string($d['seccion'] ?? null) ? $d['seccion'] : null,
                'motivo' => is_string($d['motivo'] ?? null) ? $d['motivo'] : null,
                'ts' => (int) $f['ts'],
            ];
        }
        foreach (['aplicadas', 'rechazadas'] as $estado) {
            foreach (glob(self::baseDir() . '/' . $estado . '/*.json') ?: [] as $file) {
                $r = self::readFile($file);
                if ($r === null || ($r['autor'] ?? null) !== $autor) continue;
                $out[(string) $r['id']] ??= [
                    'id' => (string) $r['id'],
                    'estado' => $estado === 'aplicadas' ? 'aplicada' : 'rechazada',
                    'resumen' => self::resumen($r),
                    'seccion' => self::seccionDe($r),
                    'motivo' => is_string($r['resolucion_motivo'] ?? null) ? $r['resolucion_motivo'] : null,
                    'ts' => (int) ($r['resuelto_ts'] ?? 0),
                ];
            }
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);
        return array_slice($out, 0, $limite);
    }
}
