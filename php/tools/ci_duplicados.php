<?php

declare(strict_types=1);

/*
 * Pruebas del detector de marchas duplicadas (AdminRepo::posiblesDuplicadosMarcha)
 * y del freno al aceptar candidatos de ingesta (AdminRepo::aceptarCandidato).
 *
 * Reproduce los casos que lo motivaron (docs/duplicados-2026-11-10.md): el
 * panel de ingesta dejaba crear una marcha idéntica a otra ya existente porque
 * no comprobaba nada, y el autor del origen a veces ni siquiera se resuelve.
 *
 * Uso: php php/tools/ci_duplicados.php [ruta .db temporal]
 */

use App\AdminRepo;
use App\Db;

require __DIR__ . '/ci_boot.php';
$dbPath = ciBoot($argv[1] ?? null);

// Fixture: marcha 1 «Consuelo Gitano» (autor 1, banda 1); marcha 3 «Costalero
// Bueno» (autores 2 y 3, banda 2); banda 0 = «Varias bandas».
$ids = static fn(array $hits): array => array_map(static fn(array $h): int => $h['ID_MARCHA'], $hits);

$candidato = static function (string $titulo, int $banda, string $videoId): int {
    Db::run(
        "INSERT INTO ingest_candidato (VIDEO_ID, VIDEO_URL, VIDEO_TITULO, P_TITULO, ID_BANDA, FUENTE) VALUES (?, ?, ?, ?, ?, 'dmp')",
        [$videoId, 'https://example.org/' . $videoId, $titulo, $titulo, $banda]
    );
    return Db::lastInsertId();
};

$exit = ciEjecuta([
    'autor común y título casi igual → duplicado' => static function () use ($ids): void {
        $h = AdminRepo::posiblesDuplicadosMarcha('CONSUELO GITANO', [1], null);
        assertIgual([1], $ids($h), 'coincidencias');
        assertIgual('mismo autor', $h[0]['motivo'], 'motivo');
    },
    'autor sin resolver pero misma banda de estreno → duplicado' => static function () use ($ids): void {
        $h = AdminRepo::posiblesDuplicadosMarcha('Consuelo gitano', [], 1);
        assertIgual([1], $ids($h), 'coincidencias');
        assertIgual('misma banda de estreno', $h[0]['motivo'], 'motivo');
    },
    'autor resuelto a otra ficha, misma banda → duplicado' => static function () use ($ids): void {
        assertIgual([1], $ids(AdminRepo::posiblesDuplicadosMarcha('Consuelo Gitano', [4], 1)), 'coincidencias');
    },
    'misma banda pero título solo parecido → no avisa' => static function () use ($ids): void {
        // «La Madrugá» es de la banda 1; por banda se exige ≥ 90 %.
        assertIgual([], $ids(AdminRepo::posiblesDuplicadosMarcha('La Madrugada', [], 1)), 'coincidencias');
    },
    '«Varias bandas» no cuenta como banda común' => static function () use ($ids): void {
        Db::run("INSERT INTO marcha (ID_MARCHA, TITULO, BANDA_ESTRENO) VALUES (900, 'Marcha Colectiva', 0)");
        assertIgual([], $ids(AdminRepo::posiblesDuplicadosMarcha('Marcha Colectiva', [], 0)), 'coincidencias');
    },
    'sin autor ni banda no se busca' => static function () use ($ids): void {
        assertIgual([], $ids(AdminRepo::posiblesDuplicadosMarcha('Consuelo Gitano', [], null)), 'coincidencias');
    },
    'la propia marcha se excluye al editar' => static function () use ($ids): void {
        assertIgual([], $ids(AdminRepo::posiblesDuplicadosMarcha('Consuelo Gitano', [1], 1, 1)), 'coincidencias');
    },
    'aceptar un candidato duplicado se bloquea sin confirmación' => static function () use ($candidato): void {
        $antes = (int) Db::one('SELECT COUNT(*) AS n FROM marcha')['n'];
        $id = $candidato('CONSUELO GITANO', 1, 'dmp-ci-1');
        $r = AdminRepo::aceptarCandidato($id, ['TITULO' => 'Consuelo Gitano', 'BANDA_ESTRENO' => '1'], [1]);
        assertIgual('POSIBLE_DUPLICADO', $r['code'], 'código');
        assertIgual($antes, (int) Db::one('SELECT COUNT(*) AS n FROM marcha')['n'], 'marchas tras el intento');
        assertIgual('pendiente', Db::one('SELECT ESTADO FROM ingest_candidato WHERE ID_CAND = ?', [$id])['ESTADO'], 'estado del candidato');
    },
    'confirmando que es otra marcha, se crea' => static function () use ($candidato): void {
        $id = $candidato('Consuelo Gitano', 1, 'dmp-ci-2');
        $r = AdminRepo::aceptarCandidato($id, ['TITULO' => 'Consuelo Gitano', 'BANDA_ESTRENO' => '1'], [1], false, true);
        assertIgual('CREATED', $r['code'], 'código');
    },
    'un candidato sin parecido se acepta sin más' => static function () use ($candidato): void {
        $id = $candidato('Marcha Nueva de Verdad', 2, 'dmp-ci-3');
        $r = AdminRepo::aceptarCandidato($id, ['TITULO' => 'Marcha Nueva de Verdad', 'BANDA_ESTRENO' => '2'], [4], false);
        assertIgual('CREATED', $r['code'], 'código');
        // La ingesta no pide tipo: la marcha nace como marcha procesional.
        assertIgual(AdminRepo::TIPO_POR_DEFECTO, Db::one('SELECT TIPO FROM marcha WHERE ID_MARCHA = ?', [$r['marchaId']])['TIPO'], 'tipo por defecto');
    },
    'un tipo explícito se respeta' => static function (): void {
        $r = AdminRepo::addMarcha(['TITULO' => 'Adaptación de prueba', 'TIPO' => 'ADAPTACIÓN MÚSICA POPULAR'], [4]);
        assertIgual('ADAPTACIÓN MÚSICA POPULAR', Db::one('SELECT TIPO FROM marcha WHERE ID_MARCHA = ?', [$r['marchaId']])['TIPO'], 'tipo');
    },
]);

ciLimpia($dbPath);
exit($exit);
