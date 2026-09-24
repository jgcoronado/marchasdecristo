<?php
declare(strict_types=1);

/**
 * depurar_dmp_existentes.php
 *
 * Saca de la cola de ingesta los candidatos FUENTE='dmp' pendientes que ya
 * existen en la BD, cruzándolos por POSICIÓN DE PISTA en vez de por título:
 *
 *   candidato → marcha del origen → discos del origen donde aparece (caché)
 *   → disco equivalente en BD (banda compatible + solapamiento de pistas)
 *   → disco_marcha.NUMEROMARCHA → marcha de la BD
 *
 * Clases:
 *   existe   posición + (título parecido, contenido, o mismo autor no genérico con
 *            título algo parecido), o título casi igual en ese mismo disco
 *            → ESTADO='duplicado' con
 *            MATCH_MARCHA_ID, MOTIVO 'dmp-dedup: …' e ID_BANDA si faltaba.
 *   revisar  la posición cae en otra marcha y nada lo confirma, o casa pero el
 *            origen da otro compositor (posible homónima) → sigue
 *            'pendiente'; se anotan MATCH_MARCHA_ID/MATCH_SCORE y un flag
 *            'dmp-dedup (revisar): …' para decidir a mano en el panel.
 *   sin_cruce el disco del origen no está en la BD (o no hay caché) → no se toca.
 *
 * Se usa 'duplicado' y no 'descartado' porque IngestaRepo::reevaluarTrasCrearMarcha
 * reabre los descartados.
 *
 * Uso (PowerShell, desde la raíz del repo):
 *   php php\app\tools\depurar_dmp_existentes.php --db=php\data\mdc.db `
 *       --cache="$env:TEMP\dmp_cache" --informe="$env:TEMP\dmp_dedup.json" [--aplicar]
 *
 * Sin --aplicar la BD se abre en solo lectura y solo se escribe el informe.
 * Idempotente: solo toca candidatos en 'pendiente'.
 *
 * Deshacer:
 *   UPDATE ingest_candidato SET ESTADO='pendiente', MATCH_MARCHA_ID=NULL, MATCH_SCORE=NULL,
 *          MOTIVO=NULL, REVIEWED_AT=NULL, ID_BANDA=NULL
 *    WHERE FUENTE='dmp' AND MOTIVO LIKE 'dmp-dedup:%';
 *   (los 'revisar' conservan sus valores previos en el informe JSON)
 */

require __DIR__ . '/lib/dmp_dedup.php';

$opt = getopt('', ['db:', 'cache:', 'informe:', 'aplicar']);
foreach (['db', 'cache', 'informe'] as $req) {
    if (empty($opt[$req])) {
        fwrite(STDERR, "Falta --$req\n");
        exit(2);
    }
}
$aplicar = isset($opt['aplicar']);
$cache = rtrim((string) $opt['cache'], '/\\');
if (!is_dir("$cache/discos") || !is_file("$cache/json/discos.json")) {
    fwrite(STDERR, "La caché $cache no tiene discos/ y json/ (la genera proponer_discografias.php)\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $opt['db'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => $aplicar ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY,
]);
$dd = new DmpDedup($pdo, "$cache/discos", "$cache/json");

$cands = $pdo->query("SELECT ID_CAND, ID_RUN, ID_BANDA, VIDEO_ID, VIDEO_URL, VIDEO_TITULO, P_TITULO, P_AUTORES, FLAGS,
                             MATCH_MARCHA_ID, MATCH_SCORE
                      FROM ingest_candidato WHERE FUENTE = 'dmp' AND ESTADO = 'pendiente' ORDER BY ID_CAND")
            ->fetchAll(PDO::FETCH_ASSOC);

$filas = [];
$cont = ['existe' => 0, 'revisar' => 0, 'sin_cruce' => 0, 'existe_con_autor_distinto' => 0];
foreach ($cands as $c) {
    $titulo = (string) ($c['P_TITULO'] ?: $c['VIDEO_TITULO']);
    $flags = json_decode((string) $c['FLAGS'], true) ?: [];
    $hint = [];
    foreach ($flags as $f) {
        if (preg_match_all('/\(id (\d+), ya en BD\)/u', (string) $f, $m)) {
            array_push($hint, ...array_map('intval', $m[1]));
        }
    }
    $hint = array_values(array_unique(array_merge($hint, $dd->autoresBd($c['P_AUTORES']))));

    // apariciones [disco origen, índice de pista] de esta marcha en el origen
    $apar = [];
    if (preg_match('/^dmp-marcha-(\d+)$/', (string) $c['VIDEO_ID'], $m)) {
        $pkM = (int) $m[1];
        foreach ($dd->aparicionesMarcha($pkM) as $a) {
            $i = $dd->indicePorMarcha($a['disco'], $pkM);
            if ($i !== null) {
                $apar[] = [$a['disco'], $i];
            }
        }
    } elseif (preg_match('#/discos/(\d+)/?$#', (string) $c['VIDEO_URL'], $m)) {
        $pkD = (int) $m[1];
        $i = $dd->indicePorTitulo($pkD, $titulo) ?? $dd->indicePorTitulo($pkD, (string) $c['VIDEO_TITULO']);
        if ($i !== null) {
            $apar[] = [$pkD, $i];
            $pkM = $dd->discoOrigen($pkD)['pistas'][$i]['id_marcha'] ?? null;
            foreach ($pkM !== null ? $dd->aparicionesMarcha($pkM) : [] as $a) {
                $j = $dd->indicePorMarcha($a['disco'], $pkM);
                if ($j !== null && $a['disco'] !== $pkD) {
                    $apar[] = [$a['disco'], $j];
                }
            }
        }
    }

    $mejor = null;
    foreach ($apar as [$pkD, $i]) {
        $r = $dd->cruzarPista($pkD, $i, $titulo, $hint);
        if ($r === null) {
            continue;
        }
        if ($r['clase'] === 'existe') {
            $mejor = $r;
            break;
        }
        $mejor ??= $r;
    }
    if ($mejor !== null && $mejor['clase'] === 'revisar' && $mejor['discrepancia_autor']) {
        $cont['existe_con_autor_distinto']++;
    }
    $clase = $mejor['clase'] ?? 'sin_cruce';
    $cont[$clase]++;
    $filas[] = ['ID_CAND' => (int) $c['ID_CAND'], 'VIDEO_ID' => $c['VIDEO_ID'], 'titulo' => $titulo,
                'autores_hint' => $hint, 'apariciones_origen' => count($apar), 'clase' => $clase,
                'previo' => ['ID_BANDA' => $c['ID_BANDA'], 'MATCH_MARCHA_ID' => $c['MATCH_MARCHA_ID'],
                             'MATCH_SCORE' => $c['MATCH_SCORE']]]
              + ($mejor ?? []);
}

// ---------------------------------------------------------------- escritura

$escritos = ['duplicado' => 0, 'revisar_anotado' => 0];
if ($aplicar) {
    $pdo->beginTransaction();
    $upDup = $pdo->prepare("UPDATE ingest_candidato
        SET ESTADO = 'duplicado', MATCH_MARCHA_ID = :m, MATCH_SCORE = :s, ID_BANDA = COALESCE(ID_BANDA, :b),
            MOTIVO = :motivo, REVIEWED_AT = datetime('now')
        WHERE ID_CAND = :id AND ESTADO = 'pendiente'");
    $upRev = $pdo->prepare("UPDATE ingest_candidato
        SET MATCH_MARCHA_ID = :m, MATCH_SCORE = :s, FLAGS = json_insert(COALESCE(FLAGS, '[]'), '$[#]', :flag)
        WHERE ID_CAND = :id AND ESTADO = 'pendiente' AND MATCH_MARCHA_ID IS NULL");
    foreach ($filas as $f) {
        $donde = sprintf('disco %d pista %d%s (marcha %d «%s»), vía %s', $f['id_disco'] ?? 0, $f['pista'] ?? 0,
            isset($f['cd']) && $f['cd'] !== null ? " CD{$f['cd']}" : '', $f['id_marcha'] ?? 0, $f['titulo_bd'] ?? '', $f['via'] ?? '');
        if ($f['clase'] === 'existe') {
            $upDup->execute([':m' => $f['id_marcha'], ':s' => $f['ratio'], ':b' => $f['id_banda'],
                             ':motivo' => "dmp-dedup: ya en $donde", ':id' => $f['ID_CAND']]);
            $escritos['duplicado'] += $upDup->rowCount();
        } elseif ($f['clase'] === 'revisar') {
            $upRev->execute([':m' => $f['id_marcha'], ':s' => $f['ratio'], ':id' => $f['ID_CAND'],
                             ':flag' => $f['discrepancia_autor']
                                 ? "dmp-dedup (revisar): en esa posición está $donde, pero el origen da como autor a {$f['autor_origen']}: ¿misma marcha con autoría distinta u homónima de otro autor?"
                                 : "dmp-dedup (revisar): en esa posición está $donde; título y autor no lo confirman"]);
            $escritos['revisar_anotado'] += $upRev->rowCount();
        }
    }
    $pdo->commit();
}

file_put_contents((string) $opt['informe'], json_encode(
    ['fecha' => date('c'), 'aplicado' => $aplicar, 'pendientes_dmp' => count($cands), 'resumen' => $cont,
     'escritos' => $escritos, 'filas' => $filas],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

printf("Pendientes dmp: %d\n", count($cands));
printf("  existe (→ duplicado): %d\n  revisar:              %d (de ellos, misma pista con otro autor en origen: %d)\n  sin cruce:            %d\n",
    $cont['existe'], $cont['revisar'], $cont['existe_con_autor_distinto'], $cont['sin_cruce']);
if ($aplicar) {
    printf("Escritos: %d duplicados, %d revisar anotados\n", $escritos['duplicado'], $escritos['revisar_anotado']);
} else {
    echo "Solo lectura (sin --aplicar). Informe: {$opt['informe']}\n";
}
