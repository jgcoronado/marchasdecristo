#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// Fase 3 — Dedup contra la BD existente
//
// Lee out/candidatos.ndjson (Fase 2) y out/marchas.json (exportado por
// php/app/tools/export_marchas.php) y para cada candidato busca la marcha más
// parecida YA EXISTENTE en la banda de estreno, por título normalizado.
//
// Regla acordada: una "recuperación" (reinterpretación de marcha antigua) solo
// debe convertirse en candidato de alta si NO existe ya en la BD. Si hay una
// coincidencia fuerte, se marca ESTADO=duplicado y no molesta al revisor. Un
// "estreno"/"novedad" con coincidencia fuerte es raro (por definición es
// nuevo) así que NUNCA se autodescarta — se deja pendiente con el match
// anotado para que el humano decida (puede ser una re-subida del mismo vídeo,
// o dos marchas distintas con el mismo título).
//
// Escribe el resultado enriquecido de vuelta en out/candidatos.ndjson (añade
// match_marcha_id, match_titulo, match_score, estado, motivo).
//
// Uso:
//   php app/tools/export_marchas.php > tools/ingest/out/marchas.json   (antes, desde la raíz del repo)
//   node dedup.mjs
// ─────────────────────────────────────────────────────────────────────────────

import { readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, isAbsolute } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const resolvePath = (p) => (isAbsolute(p) ? p : join(HERE, p));

function parseArgs(argv) {
  const a = {
    candidatos: join(HERE, 'out', 'candidatos.ndjson'),
    marchas: join(HERE, 'out', 'marchas.json'),
    out: join(HERE, 'out', 'candidatos.ndjson'),
    fuerte: 0.9,
    media: 0.75,
  };
  for (let i = 0; i < argv.length; i++) {
    const t = argv[i];
    if (t === '--candidatos') a.candidatos = resolvePath(argv[++i]);
    else if (t === '--marchas') a.marchas = resolvePath(argv[++i]);
    else if (t === '--out') a.out = resolvePath(argv[++i]);
    else if (t === '--fuerte') a.fuerte = Number(argv[++i]);
    else if (t === '--media') a.media = Number(argv[++i]);
    else { console.error(`Argumento no reconocido: ${t}`); process.exit(2); }
  }
  return a;
}
const args = parseArgs(process.argv.slice(2));

// Quita del título lo que no es título ("— en vivo", "- (Live) Concierto…",
// "[En Vivo]", "(feat. …)", "— Estreno 2026"). Mismo criterio que
// php/app/src/Similarity.php::limpiarTitulo().
const SUFIJO_VERSION = 'en\\s+vivo|en\\s+directo|vivo|directo|live|estreno|versi[oó]n|remaster(?:izado|ed)?';
const limpiarTitulo = (s) => {
  const t = (s || '')
    .replace(new RegExp('\\s*(?:\\s[—–-]\\s|[(\\[])\\s*[(\\[]?\\s*(?:' + SUFIJO_VERSION + ')\\b.*$', 'isu'), '')
    .replace(/\s*[(\[]\s*(?:feat|ft|con|with)\b.*$/isu, '')
    .trim();
  return t === '' ? (s || '') : t;
};

const normalize = (s) =>
  limpiarTitulo(s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[¡!¿?"'«»""'']/g, '')
    .replace(/\s+/g, ' ')
    .trim();

/** Distancia de Levenshtein (DP clásica, sin dependencias). */
function levenshtein(a, b) {
  if (a === b) return 0;
  const m = a.length, n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  let prev = Array.from({ length: n + 1 }, (_, j) => j);
  for (let i = 1; i <= m; i++) {
    const cur = [i];
    for (let j = 1; j <= n; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
    }
    prev = cur;
  }
  return prev[n];
}

/** Similitud 0..1 (1 = idéntico) sobre los títulos ya normalizados. */
function similitud(tituloA, tituloB) {
  const a = normalize(tituloA);
  const b = normalize(tituloB);
  if (!a || !b) return 0;
  if (a === b) return 1;
  const maxLen = Math.max(a.length, b.length);
  return 1 - levenshtein(a, b) / maxLen;
}

async function main() {
  if (!existsSync(args.candidatos)) {
    console.error(`No existe ${args.candidatos}. Ejecuta antes classify.mjs (Fase 2).`);
    process.exit(1);
  }
  if (!existsSync(args.marchas)) {
    console.error(
      `No existe ${args.marchas}. Genera antes con:\n` +
      `  php php/app/tools/export_marchas.php > tools/ingest/out/marchas.json`
    );
    process.exit(1);
  }

  const marchas = JSON.parse(await readFile(args.marchas, 'utf8'));
  const porBanda = new Map();
  for (const m of marchas) {
    if (!porBanda.has(m.banda_estreno)) porBanda.set(m.banda_estreno, []);
    porBanda.get(m.banda_estreno).push(m);
  }

  const lines = (await readFile(args.candidatos, 'utf8')).split(/\r?\n/).filter((l) => l.trim());
  const resumen = { sin_match: 0, media: 0, fuerte: 0, duplicado: 0 };

  const resultado = lines.map((line) => {
    const c = JSON.parse(line);
    const candidatas = porBanda.get(c.p_banda_estreno) || porBanda.get(c.id_banda) || [];

    let mejor = null;
    for (const m of candidatas) {
      const score = similitud(c.p_titulo, m.titulo);
      if (!mejor || score > mejor.score) mejor = { score, marcha: m };
    }

    let match_marcha_id = null, match_titulo = null, match_score = null;
    let estado = 'pendiente', motivo = null;

    if (mejor && mejor.score >= args.media) {
      match_marcha_id = mejor.marcha.id_marcha;
      match_titulo = mejor.marcha.titulo;
      match_score = Math.round(mejor.score * 100) / 100;

      if (mejor.score >= args.fuerte) {
        resumen.fuerte++;
        if (c.clasificacion === 'recuperacion') {
          estado = 'duplicado';
          motivo = `ya existe en BD como "${match_titulo}" (ID_MARCHA ${match_marcha_id}, similitud ${match_score})`;
          resumen.duplicado++;
        } else {
          motivo = `posible duplicado de "${match_titulo}" (ID_MARCHA ${match_marcha_id}) — revisar antes de aceptar`;
        }
      } else {
        resumen.media++;
        motivo = `parecido a "${match_titulo}" (ID_MARCHA ${match_marcha_id}, similitud ${match_score}) — revisar`;
      }
    } else {
      resumen.sin_match++;
    }

    return { ...c, match_marcha_id, match_titulo, match_score, estado, motivo };
  });

  await writeFile(args.out, resultado.map((r) => JSON.stringify(r)).join('\n') + (resultado.length ? '\n' : ''), 'utf8');

  console.log(`── Resumen (${resultado.length} candidatos) ──`);
  console.log(`  sin coincidencia: ${resumen.sin_match}`);
  console.log(`  coincidencia media (${args.media}-${args.fuerte}): ${resumen.media} (quedan pendientes, con aviso)`);
  console.log(`  coincidencia fuerte (>=${args.fuerte}): ${resumen.fuerte}, de las cuales ${resumen.duplicado} marcadas duplicado (recuperación ya existente)`);
  console.log(`  → ${args.out}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
