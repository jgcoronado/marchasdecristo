<?php

declare(strict_types=1);

/*
 * Importa candidatos a la tabla `ingest_candidato`, lista para revisar en el
 * panel admin. Sirve para los dos descubridores:
 *
 *   php app/tools/import_candidatos.php tools/ingest/out/candidatos.ndjson        (YouTube)
 *   php app/tools/import_candidatos.php tools/music_links/out/candidatos.ndjson   (streaming)
 *   DB_PATH=data/mdc.db php php/app/tools/import_candidatos.php <fichero.ndjson>
 *
 * Upsert por VIDEO_ID (el id del origen: vídeo de YouTube o `servicio:track:id`):
 * si el candidato ya existe y sigue "pendiente", se refresca con los datos
 * nuevos (permite re-ejecutar el pipeline sin duplicar filas); si ya fue
 * revisado (aceptado/descartado/duplicado), la fila NO se toca — la decisión
 * del revisor no se pierde al reimportar.
 *
 * Además, los orígenes **vetados** (`ingest_veto`: lo que se descartó a mano
 * en el panel) se saltan por completo, aunque su fila de candidato ya no
 * exista. Es lo que garantiza que un descarte no vuelva a aparecer nunca.
 */

define('APP_DIR', dirname(__DIR__));
define('BASE_DIR', dirname(APP_DIR));
define('DATA_DIR', BASE_DIR . '/data');

$ndjsonPath = $argv[1] ?? '';
if ($ndjsonPath === '' || !is_file($ndjsonPath)) {
    fwrite(STDERR, "Uso: php app/tools/import_candidatos.php <candidatos.ndjson>\n");
    exit(1);
}

/** @var array<string,mixed> $config */
$config = require APP_DIR . '/config.php';
$db = (string) $config['db_path'];
if (!is_file($db)) {
    fwrite(STDERR, "Abortado: no existe la BD en $db\n");
    exit(1);
}

$lines = array_filter(explode("\n", file_get_contents($ndjsonPath) ?: ''), static fn(string $l): bool => trim($l) !== '');

try {
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->beginTransaction();

    $runStmt = $pdo->prepare(
        "INSERT INTO ingest_run (FUENTE, N_CANDIDATOS, FINISHED_AT) VALUES ('classify+dedup', ?, datetime('now'))"
    );
    $runStmt->execute([count($lines)]);
    $runId = (int) $pdo->lastInsertId();

    $upsert = $pdo->prepare(
        'INSERT INTO ingest_candidato (
            ID_RUN, ID_BANDA, FUENTE, FUENTE_ALBUM, FUENTE_ALBUM_URL,
            VIDEO_ID, VIDEO_URL, VIDEO_TITULO, VIDEO_DESC, PUBLICADO_AT, DURACION_SEG,
            CLASIFICACION, CONFIANZA, FLAGS, P_TITULO, P_FECHA, P_DEDICATORIA, P_LOCALIDAD, P_PROVINCIA,
            P_AUTORES, P_ESTILO, P_BANDA_ESTRENO, MATCH_MARCHA_ID, MATCH_SCORE, ESTADO, MOTIVO, RAW_JSON
        ) VALUES (
            :run, :banda, :fuente, :fuente_album, :fuente_album_url,
            :video_id, :video_url, :video_titulo, :video_desc, :publicado, :duracion,
            :clasificacion, :confianza, :flags, :p_titulo, :p_fecha, :p_dedicatoria, :p_localidad, :p_provincia,
            :p_autores, :p_estilo, :p_banda_estreno, :match_id, :match_score, :estado, :motivo, :raw
        )
        ON CONFLICT (VIDEO_ID) DO UPDATE SET
            ID_RUN = excluded.ID_RUN, ID_BANDA = excluded.ID_BANDA, FUENTE = excluded.FUENTE,
            FUENTE_ALBUM = excluded.FUENTE_ALBUM, FUENTE_ALBUM_URL = excluded.FUENTE_ALBUM_URL,
            VIDEO_URL = excluded.VIDEO_URL,
            VIDEO_TITULO = excluded.VIDEO_TITULO, VIDEO_DESC = excluded.VIDEO_DESC,
            PUBLICADO_AT = excluded.PUBLICADO_AT, DURACION_SEG = excluded.DURACION_SEG,
            CLASIFICACION = excluded.CLASIFICACION, CONFIANZA = excluded.CONFIANZA, FLAGS = excluded.FLAGS,
            P_TITULO = excluded.P_TITULO, P_FECHA = excluded.P_FECHA, P_DEDICATORIA = excluded.P_DEDICATORIA,
            P_LOCALIDAD = excluded.P_LOCALIDAD, P_PROVINCIA = excluded.P_PROVINCIA, P_AUTORES = excluded.P_AUTORES,
            P_ESTILO = excluded.P_ESTILO,
            P_BANDA_ESTRENO = excluded.P_BANDA_ESTRENO, MATCH_MARCHA_ID = excluded.MATCH_MARCHA_ID,
            MATCH_SCORE = excluded.MATCH_SCORE,
            ESTADO = excluded.ESTADO, MOTIVO = excluded.MOTIVO, RAW_JSON = excluded.RAW_JSON
        WHERE ingest_candidato.ESTADO = \'pendiente\''
    );

    $vetado = $pdo->prepare('SELECT 1 FROM ingest_veto WHERE FUENTE = ? AND FUENTE_ID = ?');
    $before = $pdo->prepare('SELECT ESTADO FROM ingest_candidato WHERE VIDEO_ID = ?');

    $insertados = 0;
    $actualizados = 0;
    $conservados = 0;
    $vetados = 0;
    foreach ($lines as $line) {
        $c = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $fuente = (string) ($c['fuente'] ?? 'youtube');

        // Descartado a mano en el panel: no vuelve a entrar, ni siquiera si su
        // fila de candidato ya no existe.
        $vetado->execute([$fuente, $c['video_id']]);
        if ($vetado->fetchColumn() !== false) {
            $vetados++;
            continue;
        }

        $before->execute([$c['video_id']]);
        $existiaEstado = $before->fetchColumn();

        $upsert->execute([
            ':run' => $runId,
            ':banda' => $c['id_banda'] ?? null,
            ':fuente' => $fuente,
            ':fuente_album' => $c['fuente_album'] ?? null,
            ':fuente_album_url' => $c['fuente_album_url'] ?? null,
            ':video_id' => $c['video_id'],
            ':video_url' => $c['video_url'] ?? null,
            ':video_titulo' => $c['video_titulo'] ?? null,
            ':video_desc' => $c['video_desc'] ?? null,
            ':publicado' => $c['publicado_at'] ?? null,
            ':duracion' => $c['duracion_seg'] ?? null,
            ':clasificacion' => $c['clasificacion'] ?? null,
            ':confianza' => $c['confianza'] ?? null,
            ':flags' => isset($c['flags']) ? json_encode($c['flags'], JSON_UNESCAPED_UNICODE) : null,
            ':p_titulo' => $c['p_titulo'] ?? null,
            ':p_fecha' => $c['p_fecha'] ?? null,
            ':p_dedicatoria' => $c['p_dedicatoria'] ?? null,
            ':p_localidad' => $c['p_localidad'] ?? null,
            ':p_provincia' => $c['p_provincia'] ?? null,
            ':p_autores' => $c['p_autores'] ?? null,
            ':p_estilo' => $c['p_estilo'] ?? null,
            ':p_banda_estreno' => $c['p_banda_estreno'] ?? null,
            ':match_id' => $c['match_marcha_id'] ?? null,
            ':match_score' => $c['match_score'] ?? null,
            ':estado' => $c['estado'] ?? 'pendiente',
            ':motivo' => $c['motivo'] ?? null,
            ':raw' => isset($c['raw_json']) ? (string) $c['raw_json'] : null,
        ]);

        if ($existiaEstado === false) {
            $insertados++;
        } elseif ($existiaEstado === 'pendiente') {
            $actualizados++;
        } else {
            $conservados++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Importación falló: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "OK. Run #$runId: $insertados nuevos, $actualizados actualizados, $conservados conservados (ya revisados), "
    . "$vetados saltados por veto, de " . count($lines) . " candidatos.\n";
