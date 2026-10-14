<?php

declare(strict_types=1);

namespace App;

/**
 * Operaciones del panel que pueden llegar como propuesta: todo lo que se
 * escribe desde una pantalla que no es el formulario de una ficha (marcha,
 * banda, autor), y las fichas nuevas en esta vía (disco).
 *
 * Cada operación es una clave ('nomina.paso.add', 'disco.pista.edit'…) con
 * unos parámetros ya validados por el controlador. Esta clase es el ÚNICO
 * sitio que la ejecuta, tanto si el admin guarda en directo (en local) como
 * si acepta la propuesta de un editor: así las dos vías no pueden divergir.
 * Ver Admin::operar() y PropuestaRepo.
 *
 * describir() genera la frase que el admin lee al revisar («Semana Santa de
 * Málaga: añadir paso «Virgen» a la hermandad «X»»). Se llama al proponer,
 * con los nombres tal como los ve el editor, y se guarda con la propuesta.
 */
final class PropuestaOps
{
    /** Códigos de resultado que significan «hecho» (los de AdminRepo / NominaRepo). */
    private const EXITOS = ['CREATED', 'UPDATED', 'DELETED', 'REORDERED', 'MOVED', 'RESOLVED', 'DISCARDED', 'CONVERTED'];

    /** Etiqueta de la sección del panel por prefijo de la clave. */
    private const SECCIONES = [
        'marcha' => 'Marcha', 'banda' => 'Banda', 'disco' => 'Disco',
        'nomina' => 'Semana Santa', 'localidad' => 'Semana Santa', 'acomp' => 'Acompañamientos',
    ];

    /** @param array<string,mixed> $r */
    public static function esExito(array $r): bool
    {
        return in_array($r['code'] ?? '', self::EXITOS, true);
    }

    public static function seccion(string $op): string
    {
        return self::SECCIONES[strstr($op, '.', true) ?: $op] ?? 'Panel';
    }

    /**
     * Ejecuta la operación sobre la BD de este entorno.
     *
     * @param array<string,mixed>  $p         parámetros (ver cada controlador en Admin)
     * @param array<string,string> $adjuntos  nombre => ruta (solo propuestas aceptadas)
     * @return array<string,mixed> con 'code' y lo que devuelva el repositorio
     */
    public static function aplicar(string $op, array $p, array $adjuntos = []): array
    {
        $i = static fn(string $k): int => (int) ($p[$k] ?? 0);
        $s = static fn(string $k): string => (string) ($p[$k] ?? '');
        $sn = static fn(string $k): ?string => isset($p[$k]) ? (string) $p[$k] : null;
        $ids = static fn(string $k): array => array_values(array_map('intval', (array) ($p[$k] ?? [])));
        $idDiaOpc = static fn(): ?int => $i('idDia') > 0 ? $i('idDia') : null;

        return match ($op) {
            // ── Ficha de marcha ─────────────────────────────────────────────
            'marcha.enlaces' => self::enlaces('marcha', $i('idMarcha'), (array) ($p['enlaces'] ?? [])),

            // ── Ficha de banda ──────────────────────────────────────────────
            'banda.relacion.add' => AdminRepo::addRelacion($i('origen'), $i('destino'), $s('tipo'), $sn('fechaInicio'), $sn('fechaFin'), $sn('nota')),
            'banda.relacion.delete' => AdminRepo::deleteRelacion($i('idRelacion')),
            'banda.etapa.add' => AdminRepo::addEtapa($i('idBanda'), $sn('anioInicio'), $sn('anioFin'), $sn('nota')),
            'banda.etapa.delete' => AdminRepo::deleteEtapa($i('idBanda'), $i('idEtapa')),
            'banda.enlaces' => self::enlaces('banda', $i('idBanda'), (array) ($p['enlaces'] ?? [])),
            'banda.acomp.add' => NominaRepo::altaDesdeBanda($i('idBanda'), (array) ($p['destino'] ?? []) + ['anioInicio' => $i('anioInicio'), 'anioFin' => $i('anioFin')]),
            'banda.acomp.edit' => NominaRepo::editarDesdeBanda($i('idBanda'), $i('idContrato'), (array) ($p['destino'] ?? []) + ['anio' => $i('anio')]),
            'banda.acomp.importar' => NominaRepo::copiarAnioDeBanda($i('idBanda'), $i('origen'), $i('anio')),
            'banda.acomp.delete' => NominaRepo::borrarDeBanda($i('idBanda'), $ids('ids')),

            // ── Disco ───────────────────────────────────────────────────────
            'disco.add' => self::discoAdd((array) ($p['campos'] ?? []), $adjuntos['portada'] ?? null) + ['urlAlbum' => $s('urlAlbum')],
            'disco.edit' => self::discoEdit($i('idDisco'), (array) ($p['campos'] ?? []), $adjuntos['portada'] ?? null),
            'disco.pista.add' => AdminRepo::addPista($i('idDisco'), $i('idMarcha'), $i('numero'), $i('nDisco'), self::intOpc($p['duracion'] ?? null), self::intOpc($p['percusion'] ?? null)),
            'disco.pista.edit' => AdminRepo::updatePista($i('idDisco'), $i('idDm'), $i('idMarcha'), $i('numero'), $i('nDisco'), self::intOpc($p['duracion'] ?? null), self::intOpc($p['percusion'] ?? null)),
            'disco.pista.delete' => AdminRepo::deletePista($i('idDisco'), $i('idDm')),
            'disco.enlaces' => self::discoEnlaces($i('idDisco'), (array) ($p['enlaces'] ?? [])),
            'disco.importar' => self::discoImportar($i('idDisco'), (array) ($p['filas'] ?? []), $s('url'), !empty($p['guardarEnlace'])),

            // ── Nómina de Semana Santa ──────────────────────────────────────
            'localidad.provincia' => self::fijarProvincia($s('localidad'), $s('provincia')),
            'nomina.dia.add' => self::diaAdd($s('localidad'), $s('nombre'), $sn('provNueva')),
            'nomina.dia.rename' => NominaRepo::renameDia($i('idDia'), $s('nombre')),
            'nomina.dia.delete' => NominaRepo::deleteDia($i('idDia')),
            'nomina.dia.mover' => NominaRepo::swapDiaConVecino($i('idDia'), $s('direccion')),
            'nomina.dias.reorder' => NominaRepo::reorderDias($s('localidad'), $ids('orden')),
            'nomina.hermandad.add' => NominaRepo::addHermandad($s('localidad'), $i('idDia'), $s('nombre')),
            'nomina.hermandad.rename' => NominaRepo::renameHermandad($i('idHermandad'), $s('nombre')),
            'nomina.hermandad.delete' => NominaRepo::deleteHermandad($i('idHermandad')),
            'nomina.hermandad.dia' => NominaRepo::moveHermandad($i('idHermandad'), $i('idDia')),
            'nomina.hermandad.mover' => NominaRepo::swapHermandadConVecino($i('idHermandad'), $s('direccion'), $idDiaOpc()),
            'nomina.hermandades.reorder' => NominaRepo::reorderHermandades($i('idDia'), $ids('orden')),
            'nomina.paso.add' => NominaRepo::addPaso($i('idHermandad'), $s('nombre'), !empty($p['esCruzGuia']), $idDiaOpc()),
            'nomina.paso.rename' => NominaRepo::renamePaso($i('idPaso'), $s('nombre')),
            'nomina.paso.delete' => NominaRepo::deletePaso($i('idPaso')),
            'nomina.paso.mover' => NominaRepo::swapPasoConVecino($i('idPaso'), $s('direccion')),
            'nomina.pasos.reorder' => NominaRepo::reorderPasos($i('idHermandad'), $ids('orden')),
            'nomina.paso.dia' => NominaRepo::setPasoDia($i('idPaso'), $i('idDia')),
            'nomina.diaExtra.add' => NominaRepo::addDiaExtra($i('idHermandad'), $i('idDia')),
            'nomina.diaExtra.delete' => NominaRepo::removeDiaExtra($i('idHermandad'), $i('idDia')),
            'nomina.diaExtra.mover' => NominaRepo::moveDiaExtra($i('idHermandad'), $i('idDia'), $i('idDiaDestino')),
            'nomina.historico.add' => NominaRepo::addDiaHistorico($i('idHermandad'), $i('idDia'), $i('desde'), $i('hasta')),
            'nomina.historico.delete' => NominaRepo::deleteDiaHistorico($i('idHistorico')),

            // ── Acompañamientos por localidad, dudas y bandas pendientes ────
            'acomp.add' => self::acompAdd($p),
            'acomp.idaVuelta' => NominaRepo::setIdaVuelta($s('localidad'), $i('idHermandad'), !empty($p['activo'])),
            'acomp.tramo' => NominaRepo::setTramo($s('localidad'), $ids('ids'), $sn('tramo')),
            'acomp.moverPaso' => NominaRepo::moverContratosAPaso($s('localidad'), $ids('ids'), $i('idPaso')),
            'acomp.borrar' => AdminRepo::deleteContratoRango($ids('ids')),
            'acomp.banda' => AdminRepo::updateContratoBandaRango($ids('ids'), $i('idBanda')),
            'acomp.anios' => AdminRepo::editarAniosRango($ids('ids'), $i('anioInicio'), $i('anioFin')),
            'acomp.duda.resolver' => AdminRepo::resolverAcompanamientoDuda($i('idDuda'), $s('tipo'), $sn('nota')),
            'acomp.duda.descartar' => AdminRepo::descartarAcompanamientoDuda($i('idDuda'), $sn('nota')),
            'acomp.pendiente.convertir' => AdminRepo::convertirPendientesEnContratos($s('bandaTexto'), $i('idBanda')),

            default => ['code' => 'OPERACION_DESCONOCIDA'],
        };
    }

    // ── Aplicación de las operaciones compuestas ────────────────────────────

    private static function intOpc(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /**
     * Enlaces de streaming de una ficha: una entrada por (servicio[, versión]);
     * url vacía = borrar. En marcha llevan versión y año, y quedan como
     * clasificados a mano (igual que en Admin::marchaSocialPost).
     *
     * @param list<array<string,mixed>> $enlaces
     * @return array{code:string}
     */
    private static function enlaces(string $tipo, int $id, array $enlaces): array
    {
        foreach ($enlaces as $e) {
            $url = is_string($e['url'] ?? null) ? $e['url'] : null;
            $r = $tipo === 'marcha'
                ? AdminRepo::setEnlaceStreaming('marcha', $id, (string) ($e['servicio'] ?? ''), $url, null,
                    (string) ($e['version'] ?? 'actual'), self::intOpc($e['anio'] ?? null), true)
                : AdminRepo::setEnlaceStreaming($tipo, $id, (string) ($e['servicio'] ?? ''), $url);
            if ($r['code'] === 'BAD_REQUEST') return $r;
        }
        return ['code' => 'UPDATED'];
    }

    /**
     * Guardar los enlaces de un disco dispara siempre la cascada (resto de
     * servicios del disco, sus marchas y la banda), ver Admin::discoSocialPost.
     *
     * @param list<array<string,mixed>> $enlaces
     * @return array{code:string, resumen?:?string}
     */
    private static function discoEnlaces(int $idDisco, array $enlaces): array
    {
        $r = self::enlaces('disco', $idDisco, $enlaces);
        if ($r['code'] !== 'UPDATED') return $r;
        return ['code' => 'UPDATED', 'resumen' => EnlacesAuto::resumen(EnlacesAuto::paraDisco($idDisco))];
    }

    /** @param array<string,mixed> $campos @return array<string,mixed> */
    private static function discoAdd(array $campos, ?string $portada): array
    {
        $r = AdminRepo::addDisco($campos);
        if (($r['code'] ?? '') !== 'CREATED') return $r;
        // Como en el alta directa: si la portada falla, el disco ya existe y se
        // avisa, en vez de deshacer el alta.
        if ($portada !== null) $r['errPortada'] = Media::instalarPortada($portada, (int) $r['discoId']);
        return $r;
    }

    /** @param array<string,mixed> $campos @return array<string,mixed> */
    private static function discoEdit(int $idDisco, array $campos, ?string $portada): array
    {
        if ($campos !== []) {
            $r = AdminRepo::updateDisco($idDisco, $campos);
            if (($r['code'] ?? '') !== 'UPDATED') return $r;
        } elseif (AdminRepo::discoConPistas($idDisco) === null) {
            return ['code' => 'DISCO_NO_EXISTE'];
        }
        if ($portada !== null) {
            $err = Media::instalarPortada($portada, $idDisco);
            if ($err !== null) return ['code' => $err];
        }
        return ['code' => 'UPDATED'];
    }

    /**
     * Pistas aprobadas en el importador (paso 3) y, si se pidió, el enlace del
     * álbum como enlace del disco con su cascada. Ver Admin::discoImportarConfirmar.
     *
     * @param list<array<string,mixed>> $filas
     * @return array<string,mixed>
     */
    private static function discoImportar(int $idDisco, array $filas, string $url, bool $guardarEnlace): array
    {
        if (AdminRepo::discoConPistas($idDisco) === null) return ['code' => 'DISCO_NO_EXISTE'];
        $r = ImportadorPistas::aplicar($idDisco, $filas);
        $resumenAuto = null;
        if ($guardarEnlace) {
            $ref = Tracklist::parseUrl($url);
            if ($ref !== null) {
                AdminRepo::setEnlaceStreaming('disco', $idDisco, $ref['servicio'], $url);
                $resumenAuto = EnlacesAuto::resumen(EnlacesAuto::paraDisco($idDisco));
            }
        }
        return ['code' => 'CREATED', 'anadidas' => $r['anadidas'], 'errores' => $r['errores'], 'resumenAuto' => $resumenAuto];
    }

    /** @return array{code:string} */
    private static function fijarProvincia(string $localidad, string $provincia): array
    {
        if ($localidad === '' || !MunicipioRepo::esProvinciaValida($provincia)) return ['code' => 'PROVINCIA_REQUERIDA'];
        NominaRepo::fijarProvincia($localidad, $provincia);
        return ['code' => 'UPDATED'];
    }

    /** Día nuevo; en una localidad nueva fija además su provincia. @return array<string,mixed> */
    private static function diaAdd(string $localidad, string $nombre, ?string $provNueva): array
    {
        $r = NominaRepo::addDia($localidad, $nombre);
        if ($r['code'] === 'CREATED' && $provNueva !== null) NominaRepo::fijarProvincia($localidad, $provNueva);
        return $r;
    }

    /**
     * Alta en bloque por rango de años desde /dashboard/acompanamientos/{localidad}
     * (ver Admin::acompanamientosAddPost). Con paso de la nómina, de él salen
     * hermandad, titular y slug; si no, texto libre.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function acompAdd(array $p): array
    {
        $localidad = (string) ($p['localidad'] ?? '');
        $idBanda = (int) ($p['idBanda'] ?? 0);
        $anioInicio = (int) ($p['anioInicio'] ?? 0);
        $anioFin = (int) ($p['anioFin'] ?? 0);
        $fuente = isset($p['fuente']) ? (string) $p['fuente'] : null;
        $nota = isset($p['nota']) ? (string) $p['nota'] : null;
        $idPaso = (int) ($p['idPaso'] ?? 0);

        if ($idPaso > 0) {
            $paso = NominaRepo::pasoDeLocalidad($idPaso, $localidad);
            if ($paso === null) return ['code' => 'PASO_INVALIDO'];
            $r = AdminRepo::addContratoRango($idBanda, $paso['HERMANDAD'], $paso['NOMBRE'], $anioInicio, $anioFin, $fuente, $nota, $localidad, $paso['SLUG']);
            if ($r['code'] === 'CREATED') {
                NominaRepo::enlazarRangoAPaso($localidad, $idBanda, $paso, $anioInicio, $anioFin);
                $tramo = (string) ($p['tramo'] ?? '');
                if ($tramo !== '') NominaRepo::marcarTramoRango($localidad, $idBanda, $paso, $anioInicio, $anioFin, $tramo);
            }
        } else {
            $titular = isset($p['titular']) ? (string) $p['titular'] : null;
            $r = AdminRepo::addContratoRango($idBanda, (string) ($p['hermandad'] ?? ''), $titular, $anioInicio, $anioFin, $fuente, $nota, $localidad);
        }
        if ($r['code'] === 'CREATED' && isset($p['provNueva'])) NominaRepo::fijarProvincia($localidad, (string) $p['provNueva']);
        return $r;
    }

    // ── Descripción legible ─────────────────────────────────────────────────

    /**
     * Frase que resume la operación para quien la revisa. Nunca falla: si un
     * registro ya no existe (o falta una tabla en este host), se nombra por id.
     *
     * @param array<string,mixed> $p
     */
    public static function describir(string $op, array $p): string
    {
        try {
            return self::frase($op, $p);
        } catch (\Throwable $e) {
            error_log('[propuestas] describir ' . $op . ': ' . $e->getMessage());
            return self::seccion($op) . ': ' . $op;
        }
    }

    /** @param array<string,mixed> $p */
    private static function frase(string $op, array $p): string
    {
        $i = static fn(string $k): int => (int) ($p[$k] ?? 0);
        $s = static fn(string $k): string => trim((string) ($p[$k] ?? ''));
        $q = static fn(string $v): string => '«' . $v . '»';
        $anios = static fn(int $a, int $b): string => $b > $a ? "{$a}–{$b}" : (string) $a;
        $dir = static fn(): string => $s('direccion') === 'up' ? 'hacia arriba' : 'hacia abajo';
        $ss = static fn(string $loc): string => 'Semana Santa de ' . $q($loc) . ': ';
        $locDia = static fn(int $id): string => self::localidadDeDia($id);
        $locHerm = static fn(int $id): string => self::localidadDeHermandad($id);
        $ac = static fn(string $loc): string => 'Acompañamientos de ' . $q($loc) . ': ';
        $n = static fn(array $x): int => count((array) $x);

        return match ($op) {
            'marcha.enlaces' => 'Enlaces de escucha de la marcha ' . $q(self::marcha($i('idMarcha'))) . ': ' . self::cambios($n($p['enlaces'] ?? [])),

            'banda.relacion.add' => 'Linaje: añadir relación «' . $s('tipo') . '» ' . $q(self::banda($i('origen'))) . ' → ' . $q(self::banda($i('destino')))
                . ($s('fechaInicio') !== '' ? ' (' . $s('fechaInicio') . ($s('fechaFin') !== '' ? '–' . $s('fechaFin') : '') . ')' : ''),
            'banda.relacion.delete' => 'Linaje de ' . $q(self::banda($i('idBanda'))) . ': borrar la relación ' . self::relacion($i('idRelacion')),
            'banda.etapa.add' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': añadir etapa de actividad ' . $s('anioInicio') . '–' . ($s('anioFin') !== '' ? $s('anioFin') : 'hoy'),
            'banda.etapa.delete' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': borrar la etapa ' . self::etapa($i('idEtapa')),
            'banda.enlaces' => 'Enlaces de streaming de la banda ' . $q(self::banda($i('idBanda'))) . ': ' . self::cambios($n($p['enlaces'] ?? [])),
            'banda.acomp.add' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': añadir acompañamiento ' . self::destino((array) ($p['destino'] ?? [])) . ', ' . $anios($i('anioInicio'), $i('anioFin')),
            'banda.acomp.edit' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': cambiar el acompañamiento ' . self::contratos([$i('idContrato')])
                . ' por ' . self::destino((array) ($p['destino'] ?? [])) . ', ' . $i('anio'),
            'banda.acomp.importar' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': copiar a ' . $i('anio') . ' los acompañamientos de ' . $i('origen'),
            'banda.acomp.delete' => 'Banda ' . $q(self::banda($i('idBanda'))) . ': borrar ' . self::contratos((array) ($p['ids'] ?? [])),

            'disco.add' => 'Alta del disco ' . $q(trim((string) (((array) ($p['campos'] ?? []))['NOMBRE_CD'] ?? '')))
                . self::extrasDisco((array) ($p['campos'] ?? []), !empty($p['conPortada']), $s('urlAlbum')),
            'disco.edit' => 'Disco ' . $q(self::disco($i('idDisco'))) . ': ' . implode(' y ', array_filter([
                $n($p['campos'] ?? []) > 0 ? 'cambiar ' . self::plural($n($p['campos'] ?? []), 'dato', 'datos') : '',
                !empty($p['conPortada']) ? 'portada nueva' : '',
            ])),
            'disco.pista.add' => 'Disco ' . $q(self::disco($i('idDisco'))) . ': añadir la pista ' . self::numPista($i('numero'), $i('nDisco')) . ' ' . $q(self::marcha($i('idMarcha'))),
            'disco.pista.edit' => 'Disco ' . $q(self::disco($i('idDisco'))) . ': cambiar la pista ' . self::pista($i('idDm'))
                . ' por la ' . self::numPista($i('numero'), $i('nDisco')) . ' ' . $q(self::marcha($i('idMarcha'))),
            'disco.pista.delete' => 'Disco ' . $q(self::disco($i('idDisco'))) . ': quitar la pista ' . self::pista($i('idDm')),
            'disco.enlaces' => 'Enlaces de streaming del disco ' . $q(self::disco($i('idDisco'))) . ': ' . self::cambios($n($p['enlaces'] ?? [])),
            'disco.importar' => 'Disco ' . $q(self::disco($i('idDisco'))) . ': importar ' . self::plural($n($p['filas'] ?? []), 'pista', 'pistas') . ' desde el enlace del álbum',

            'localidad.provincia' => $q($s('localidad')) . ': fijar la provincia ' . $q($s('provincia')),
            'nomina.dia.add' => $ss($s('localidad')) . 'añadir el día ' . $q($s('nombre')) . ($s('provNueva') !== '' ? ' (localidad nueva, provincia de ' . $s('provNueva') . ')' : ''),
            'nomina.dia.rename' => $ss($locDia($i('idDia'))) . 'renombrar el día ' . $q(self::dia($i('idDia'))) . ' a ' . $q($s('nombre')),
            'nomina.dia.delete' => $ss($locDia($i('idDia'))) . 'borrar el día ' . $q(self::dia($i('idDia'))),
            'nomina.dia.mover' => $ss($locDia($i('idDia'))) . 'mover el día ' . $q(self::dia($i('idDia'))) . ' ' . $dir(),
            'nomina.dias.reorder' => $ss($s('localidad')) . 'nuevo orden de los días: ' . self::lista(array_map([self::class, 'dia'], array_map('intval', (array) ($p['orden'] ?? [])))),
            'nomina.hermandad.add' => $ss($s('localidad')) . 'añadir la hermandad ' . $q($s('nombre')) . ' al día ' . $q(self::dia($i('idDia'))),
            'nomina.hermandad.rename' => $ss($locHerm($i('idHermandad'))) . 'renombrar la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' a ' . $q($s('nombre')),
            'nomina.hermandad.delete' => $ss($locHerm($i('idHermandad'))) . 'borrar la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' y sus pasos',
            'nomina.hermandad.dia' => $ss($locHerm($i('idHermandad'))) . 'pasar la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' al día ' . $q(self::dia($i('idDia'))),
            'nomina.hermandad.mover' => $ss($locHerm($i('idHermandad'))) . 'mover la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' ' . $dir()
                . ($i('idDia') > 0 ? ' en el día ' . $q(self::dia($i('idDia'))) : ''),
            'nomina.hermandades.reorder' => $ss($locDia($i('idDia'))) . 'nuevo orden de las hermandades del ' . $q(self::dia($i('idDia'))) . ': '
                . self::lista(array_map([self::class, 'hermandad'], array_map('intval', (array) ($p['orden'] ?? [])))),
            'nomina.paso.add' => $ss($locHerm($i('idHermandad'))) . 'añadir el paso ' . $q($s('nombre')) . (!empty($p['esCruzGuia']) ? ' (cruz de guía)' : '')
                . ' a la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ($i('idDia') > 0 ? ', el ' . $q(self::dia($i('idDia'))) : ''),
            'nomina.paso.rename' => $ss(self::localidadDePaso($i('idPaso'))) . 'renombrar el paso ' . self::paso($i('idPaso')) . ' a ' . $q($s('nombre')),
            'nomina.paso.delete' => $ss(self::localidadDePaso($i('idPaso'))) . 'borrar el paso ' . self::paso($i('idPaso')),
            'nomina.paso.mover' => $ss(self::localidadDePaso($i('idPaso'))) . 'mover el paso ' . self::paso($i('idPaso')) . ' ' . $dir(),
            'nomina.pasos.reorder' => $ss($locHerm($i('idHermandad'))) . 'nuevo orden de los pasos de ' . $q(self::hermandad($i('idHermandad'))) . ': '
                . self::lista(array_map(static fn(int $id): string => self::filaNombre('paso', 'ID_PASO', $id), array_map('intval', (array) ($p['orden'] ?? [])))),
            'nomina.paso.dia' => $ss(self::localidadDePaso($i('idPaso'))) . 'el paso ' . self::paso($i('idPaso')) . ' sale el ' . $q(self::dia($i('idDia'))),
            'nomina.diaExtra.add' => $ss($locHerm($i('idHermandad'))) . 'la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' sale también el ' . $q(self::dia($i('idDia'))),
            'nomina.diaExtra.delete' => $ss($locHerm($i('idHermandad'))) . 'la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' deja de salir el ' . $q(self::dia($i('idDia'))),
            'nomina.diaExtra.mover' => $ss($locHerm($i('idHermandad'))) . 'el día extra de ' . $q(self::hermandad($i('idHermandad'))) . ' pasa del '
                . $q(self::dia($i('idDia'))) . ' al ' . $q(self::dia($i('idDiaDestino'))),
            'nomina.historico.add' => $ss($locHerm($i('idHermandad'))) . 'la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' salía el ' . $q(self::dia($i('idDia')))
                . ' entre ' . $i('desde') . ' y ' . $i('hasta'),
            'nomina.historico.delete' => 'Semana Santa: borrar el día histórico ' . self::historico($i('idHistorico')),

            'acomp.add' => $ac($s('localidad')) . 'añadir ' . $q(self::banda($i('idBanda'))) . ' con '
                . ($i('idPaso') > 0 ? self::paso($i('idPaso')) : $q($s('hermandad')) . ($s('titular') !== '' ? ' · ' . $s('titular') : ''))
                . ($s('tramo') !== '' ? ' (' . $s('tramo') . ')' : '') . ', ' . $anios($i('anioInicio'), $i('anioFin'))
                . ($s('provNueva') !== '' ? ' (localidad nueva, provincia de ' . $s('provNueva') . ')' : ''),
            'acomp.idaVuelta' => $ac($s('localidad')) . (!empty($p['activo']) ? 'marcar' : 'desmarcar') . ' la hermandad ' . $q(self::hermandad($i('idHermandad'))) . ' como «ida / vuelta»',
            'acomp.tramo' => $ac($s('localidad')) . ($s('tramo') !== '' ? 'poner tramo ' . $q($s('tramo')) . ' a ' : 'quitar el tramo a ') . self::contratos((array) ($p['ids'] ?? [])),
            'acomp.moverPaso' => $ac($s('localidad')) . 'mover ' . self::contratos((array) ($p['ids'] ?? [])) . ' al paso ' . self::paso($i('idPaso')),
            'acomp.borrar' => $ac($s('localidad')) . 'borrar ' . self::contratos((array) ($p['ids'] ?? [])),
            'acomp.banda' => $ac($s('localidad')) . 'cambiar a ' . $q(self::banda($i('idBanda'))) . ' la banda de ' . self::contratos((array) ($p['ids'] ?? [])),
            'acomp.anios' => $ac($s('localidad')) . 'cambiar a ' . $anios($i('anioInicio'), $i('anioFin')) . ' los años de ' . self::contratos((array) ($p['ids'] ?? [])),
            'acomp.duda.resolver' => 'Duda de acompañamiento ' . self::duda($i('idDuda')) . ': resolver como ' . $q($s('tipo')) . ($s('nota') !== '' ? ' — ' . $s('nota') : ''),
            'acomp.duda.descartar' => 'Duda de acompañamiento ' . self::duda($i('idDuda')) . ': descartar' . ($s('nota') !== '' ? ' — ' . $s('nota') : ''),
            'acomp.pendiente.convertir' => 'Bandas pendientes: enlazar ' . $q($s('bandaTexto')) . ' con la banda ' . $q(self::banda($i('idBanda'))) . ' y crear sus acompañamientos',

            default => self::seccion($op) . ': ' . $op,
        };
    }

    /**
     * Valores actuales frente a los propuestos, para las operaciones que
     * reescriben campos (datos del disco, enlaces). Lista de
     * [etiqueta, actual, propuesto]; vacía en el resto.
     *
     * @param array<string,mixed> $p
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function contexto(string $op, array $p): array
    {
        try {
            $filas = [];
            if ($op === 'disco.edit') {
                $actual = (array) (AdminRepo::discoConPistas((int) ($p['idDisco'] ?? 0))['disco'] ?? []);
                $actualCi = array_change_key_case($actual, CASE_UPPER);
                foreach ((array) ($p['campos'] ?? []) as $campo => $nuevo) {
                    $filas[] = [(string) $campo, (string) ($actualCi[strtoupper((string) $campo)] ?? ''), (string) $nuevo];
                }
            } elseif (in_array($op, ['marcha.enlaces', 'banda.enlaces', 'disco.enlaces'], true)) {
                $tipo = (string) strstr($op, '.', true);
                $id = (int) ($p['idMarcha'] ?? $p['idBanda'] ?? $p['idDisco'] ?? 0);
                $actuales = [];
                if ($tipo === 'marcha') {
                    foreach (EnlaceRepo::detalleDe($tipo, $id) as $version => $porServicio) {
                        foreach ($porServicio as $servicio => $fila) $actuales[$version . '_' . $servicio] = $fila['url'];
                    }
                } else {
                    // Banda y disco: lo mismo que enseña su formulario (ver Admin::bandaEditForm).
                    foreach (EnlaceRepo::publicadosDe($tipo, $id) as $servicio => $url) $actuales['actual_' . $servicio] = (string) $url;
                }
                foreach ((array) ($p['enlaces'] ?? []) as $e) {
                    $version = (string) ($e['version'] ?? 'actual');
                    $servicio = (string) ($e['servicio'] ?? '');
                    $etiqueta = (Html::STREAMING_LABELS[$servicio] ?? $servicio) . ($tipo === 'marcha' ? ' (' . $version . ')' : '');
                    $filas[] = [$etiqueta, $actuales[$version . '_' . $servicio] ?? '', (string) ($e['url'] ?? '')];
                }
            }
            return $filas;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Nombres para las frases (por id; '#id' si ya no existe) ─────────────

    private static function filaNombre(string $tabla, string $col, int $id, string $campo = 'NOMBRE'): string
    {
        $r = Db::one("SELECT $campo AS N FROM $tabla WHERE $col = ?", [$id]);
        return $r !== null && (string) $r['N'] !== '' ? (string) $r['N'] : '#' . $id;
    }

    private static function banda(int $id): string { return self::filaNombre('banda', 'ID_BANDA', $id, 'NOMBRE_BREVE'); }
    private static function marcha(int $id): string { return self::filaNombre('marcha', 'ID_MARCHA', $id, 'TITULO'); }
    private static function disco(int $id): string { return self::filaNombre('disco', 'ID_DISCO', $id, 'NOMBRE_CD'); }
    private static function dia(int $id): string { return self::filaNombre('semana_santa_dia', 'ID_DIA', $id); }
    private static function hermandad(int $id): string { return self::filaNombre('hermandad', 'ID_HERMANDAD', $id); }
    private static function localidadDeDia(int $id): string { return self::filaNombre('semana_santa_dia', 'ID_DIA', $id, 'LOCALIDAD'); }
    private static function localidadDeHermandad(int $id): string { return self::filaNombre('hermandad', 'ID_HERMANDAD', $id, 'LOCALIDAD'); }

    private static function localidadDePaso(int $id): string
    {
        $r = Db::one('SELECT h.LOCALIDAD FROM paso p JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE p.ID_PASO = ?', [$id]);
        return $r !== null ? (string) $r['LOCALIDAD'] : '#?';
    }

    /** «Paso» (de la hermandad «X»). */
    private static function paso(int $id): string
    {
        $r = Db::one('SELECT p.NOMBRE, h.NOMBRE AS HERMANDAD FROM paso p JOIN hermandad h ON h.ID_HERMANDAD = p.ID_HERMANDAD WHERE p.ID_PASO = ?', [$id]);
        return $r !== null ? '«' . $r['NOMBRE'] . '» de «' . $r['HERMANDAD'] . '»' : '#' . $id;
    }

    private static function relacion(int $id): string
    {
        $r = Db::one('SELECT TIPO, ID_ORIGEN, ID_DESTINO, FECHA_INICIO FROM banda_relacion WHERE ID_RELACION = ?', [$id]);
        if ($r === null) return '#' . $id;
        return '«' . $r['TIPO'] . '» «' . self::banda((int) $r['ID_ORIGEN']) . '» → «' . self::banda((int) $r['ID_DESTINO']) . '»'
            . ($r['FECHA_INICIO'] !== null ? ' (' . $r['FECHA_INICIO'] . ')' : '');
    }

    private static function etapa(int $id): string
    {
        $r = Db::one('SELECT ANIO_INICIO, ANIO_FIN FROM banda_etapa WHERE ID_ETAPA = ?', [$id]);
        return $r !== null ? $r['ANIO_INICIO'] . '–' . ($r['ANIO_FIN'] ?? 'hoy') : '#' . $id;
    }

    private static function historico(int $id): string
    {
        $r = Db::one(
            'SELECT h.NOMBRE AS HERMANDAD, h.LOCALIDAD, d.NOMBRE AS DIA, x.DESDE, x.HASTA FROM hermandad_dia_historico x
             JOIN hermandad h ON h.ID_HERMANDAD = x.ID_HERMANDAD JOIN semana_santa_dia d ON d.ID_DIA = x.ID_DIA WHERE x.ID_HISTORICO = ?',
            [$id]
        );
        return $r !== null ? '«' . $r['DIA'] . '» (' . $r['DESDE'] . '–' . $r['HASTA'] . ') de «' . $r['HERMANDAD'] . '», ' . $r['LOCALIDAD'] : '#' . $id;
    }

    private static function pista(int $idDm): string
    {
        $r = Db::one('SELECT dm.NUMEROMARCHA, dm.N_DISCO, m.TITULO FROM disco_marcha dm LEFT JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA WHERE dm.ID_DM = ?', [$idDm]);
        return $r !== null ? self::numPista((int) $r['NUMEROMARCHA'], (int) $r['N_DISCO']) . ' «' . ($r['TITULO'] ?? '?') . '»' : '#' . $idDm;
    }

    private static function numPista(int $numero, int $volumen): string
    {
        return 'nº ' . $numero . ($volumen > 1 ? ' (vol. ' . $volumen . ')' : '');
    }

    private static function duda(int $id): string
    {
        $r = Db::one('SELECT HERMANDAD, LOCALIDAD, ETIQUETA_RAW FROM acompanamiento_duda WHERE ID_DUDA = ?', [$id]);
        return $r !== null ? '«' . $r['HERMANDAD'] . '» (' . $r['LOCALIDAD'] . ')' . ($r['ETIQUETA_RAW'] ? ' · ' . $r['ETIQUETA_RAW'] : '') : '#' . $id;
    }

    /**
     * Resumen de unas filas de contrato: «2 acompañamientos de «Banda» con
     * «Hermandad · Titular» (2010–2011)».
     *
     * @param array<mixed> $ids
     */
    private static function contratos(array $ids): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0));
        if ($ids === []) return 'ningún acompañamiento';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $filas = Db::all("SELECT ID_BANDA, HERMANDAD, TITULAR, ANIO FROM contrato WHERE ID_CONTRATO IN ($ph)", $ids);
        if ($filas === []) return self::plural(count($ids), 'acompañamiento', 'acompañamientos') . ' (#' . implode(', #', $ids) . ')';
        $bandas = array_unique(array_map(static fn(array $f): string => self::banda((int) $f['ID_BANDA']), $filas));
        $destinos = array_unique(array_map(static fn(array $f): string => $f['HERMANDAD'] . ($f['TITULAR'] ? ' · ' . $f['TITULAR'] : ''), $filas));
        $anios = array_map(static fn(array $f): int => (int) $f['ANIO'], $filas);
        $min = min($anios);
        $max = max($anios);
        return self::plural(count($filas), 'acompañamiento', 'acompañamientos') . ' de ' . self::lista($bandas)
            . ' con ' . self::lista($destinos) . ' (' . ($max > $min ? "{$min}–{$max}" : $min) . ')';
    }

    /** @param array<string,mixed> $d destino de la pestaña de la ficha de banda */
    private static function destino(array $d): string
    {
        $partes = array_filter([trim((string) ($d['hermandad'] ?? '')), trim((string) ($d['paso'] ?? ''))], static fn(string $x): bool => $x !== '');
        $donde = array_filter([trim((string) ($d['dia'] ?? '')), trim((string) ($d['localidad'] ?? ''))], static fn(string $x): bool => $x !== '');
        return '«' . implode(' · ', $partes) . '»' . ($donde !== [] ? ' (' . implode(', ', $donde) . ')' : '');
    }

    /** @param array<string,mixed> $campos */
    private static function extrasDisco(array $campos, bool $conPortada, string $urlAlbum): string
    {
        $extras = [];
        if (trim((string) ($campos['FECHA_CD'] ?? '')) !== '') $extras[] = (string) $campos['FECHA_CD'];
        if ((int) ($campos['BANDADISCO'] ?? 0) > 0) $extras[] = 'de «' . self::banda((int) $campos['BANDADISCO']) . '»';
        if ($conPortada) $extras[] = 'con portada';
        if ($urlAlbum !== '') $extras[] = 'con enlace del álbum para importar las pistas';
        return $extras !== [] ? ' (' . implode(', ', $extras) . ')' : '';
    }

    /** @param array<mixed> $xs */
    private static function lista(array $xs): string
    {
        $xs = array_values(array_map('strval', $xs));
        if (count($xs) > 8) $xs = [...array_slice($xs, 0, 8), '… (' . (count($xs) - 8) . ' más)'];
        return implode(', ', $xs);
    }

    private static function cambios(int $n): string
    {
        return self::plural($n, 'cambio', 'cambios');
    }

    private static function plural(int $n, string $uno, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $uno : $varios);
    }
}
