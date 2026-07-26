<?php

declare(strict_types=1);

namespace App;

/**
 * Catálogo de municipios (localidad + provincia): ver app/tools/sql/007_municipio.sql.
 *
 * Es la fuente de verdad del par localidad-provincia, tanto para los
 * desplegables del panel como para los puntos del mapa. Un municipio pertenece
 * siempre a una única provincia, así que elegir localidad fija la provincia.
 */
final class MunicipioRepo
{
    /** Provincias españolas, en el mismo castellano histórico que usa la BD. */
    public static function provincias(): array
    {
        $p = array_values(Mapa::PROVINCIAS);
        sort($p, SORT_LOCALE_STRING);
        return $p;
    }

    public static function esProvinciaValida(string $provincia): bool
    {
        return in_array($provincia, Mapa::PROVINCIAS, true);
    }

    /** Clave única normalizada de un par (ver el UNIQUE de la tabla). */
    public static function clave(string $provincia, string $nombre): string
    {
        return Db::noAcc(trim($provincia)) . '|' . Db::noAcc(trim($nombre));
    }

    /**
     * ¿Existe la tabla? El código público (mapa) se ejecuta también en bases
     * aún sin migrar; sin esto una BD vieja tumbaría la página en vez de
     * limitarse a no pintar puntos.
     */
    public static function tablaDisponible(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $row = Db::one("SELECT 1 AS x FROM sqlite_master WHERE type = 'table' AND name = 'municipio'");
        return $ok = $row !== null;
    }

    /**
     * Municipios de una provincia que casan con $q (sin acentos, por prefijo de
     * palabra: "guad" encuentra "Alcalá de Guadaíra"). Para el predictivo del panel.
     *
     * @return list<array{ID_MUNICIPIO:int,NOMBRE:string,PROVINCIA:string,OFICIAL:int}>
     */
    public static function buscar(string $provincia, string $q, int $limit = 15): array
    {
        if (!self::tablaDisponible()) {
            return [];
        }
        $sql = 'SELECT ID_MUNICIPIO, NOMBRE, PROVINCIA, OFICIAL FROM municipio WHERE PROVINCIA = ?';
        $values = [$provincia];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND NOACC(NOMBRE) LIKE ?';
            $values[] = '%' . Db::noAcc($q) . '%';
        }
        $sql .= ' ORDER BY NOMBRE COLLATE NOCASE LIMIT ?';
        $values[] = $limit;
        return Db::all($sql, $values);
    }

    /** Fila del par exacto (insensible a acentos/mayúsculas), o null. */
    public static function buscarPar(string $provincia, string $nombre): ?array
    {
        if (!self::tablaDisponible()) {
            return null;
        }
        return Db::one(
            'SELECT ID_MUNICIPIO, NOMBRE, PROVINCIA, LAT, LNG, OFICIAL FROM municipio WHERE CLAVE = ?',
            [self::clave($provincia, $nombre)]
        );
    }

    /**
     * Provincia(s) en las que existe una localidad con ese nombre. Sirve para
     * fijar la provincia cuando solo llega la localidad; si el nombre se repite
     * en varias provincias (los hay), devuelve todas y decide quien llama.
     *
     * @return list<string>
     */
    public static function provinciasDe(string $nombre): array
    {
        if (!self::tablaDisponible()) {
            return [];
        }
        $rows = Db::all(
            'SELECT DISTINCT PROVINCIA FROM municipio WHERE NOACC(NOMBRE) = ? ORDER BY PROVINCIA',
            [Db::noAcc(trim($nombre))]
        );
        return array_map(static fn(array $r): string => (string) $r['PROVINCIA'], $rows);
    }

    /**
     * Municipios de una provincia con coordenadas conocidas (para el mapa).
     *
     * @return list<array{NOMBRE:string,PROVINCIA:string,LAT:float,LNG:float}>
     */
    public static function conCoordenadas(string $provincia): array
    {
        if (!self::tablaDisponible()) {
            return [];
        }
        return Db::all(
            'SELECT NOMBRE, PROVINCIA, LAT, LNG FROM municipio
             WHERE PROVINCIA = ? AND LAT IS NOT NULL AND LNG IS NOT NULL',
            [$provincia]
        );
    }

    /**
     * Alta de un par nuevo. Devuelve el código del resultado, con la misma
     * gramática que AdminRepo (CREATED / INVALID_* / DUPLICATE).
     *
     * @return array{code:string, municipioId?:int}
     */
    public static function crear(string $provincia, string $nombre, ?float $lat = null, ?float $lng = null): array
    {
        $provincia = trim($provincia);
        $nombre = trim($nombre);
        if ($nombre === '') {
            return ['code' => 'INVALID_NOMBRE'];
        }
        if (!self::esProvinciaValida($provincia)) {
            return ['code' => 'INVALID_PROVINCIA'];
        }
        if (($lat !== null && ($lat < -90 || $lat > 90)) || ($lng !== null && ($lng < -180 || $lng > 180))) {
            return ['code' => 'INVALID_COORDS'];
        }
        if (self::buscarPar($provincia, $nombre) !== null) {
            return ['code' => 'DUPLICATE'];
        }

        Db::run(
            'INSERT INTO municipio (PROVINCIA, NOMBRE, LAT, LNG, OFICIAL, CLAVE) VALUES (?, ?, ?, ?, 0, ?)',
            [$provincia, $nombre, $lat, $lng, self::clave($provincia, $nombre)]
        );
        $id = Db::lastInsertId();
        Db::logAdmin('INSERT', 'municipio', $id, ['provincia' => $provincia, 'nombre' => $nombre]);
        return ['code' => 'CREATED', 'municipioId' => $id];
    }
}
