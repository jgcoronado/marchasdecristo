#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// Fase 2 — Clasificador + extractor heurístico de campos
//
// Lee out/videos.ndjson (salida de extract.mjs) y para cada vídeo:
//   1. Clasifica: estreno | novedad | recuperacion | otro (por keywords).
//   2. Si no es "otro", extrae los campos de marcha propuestos (P_*) del
//      título/descripción: título, autores, localidad, año.
//   3. Asigna una confianza 0..1 y una lista de flags con lo que no pudo
//      determinar (para que el panel de revisión lo resalte).
//
// NO decide duplicados contra la BD (Fase 3) ni escribe en SQLite (Fase 4).
// Salida: out/candidatos.ndjson — solo vídeos clasificados como estreno,
// novedad o recuperación (el resto se descarta aquí; ver --debug para verlos).
//
// Uso:
//   node classify.mjs                 # out/videos.ndjson → out/candidatos.ndjson
//   node classify.mjs --debug         # además escribe out/descartados.ndjson con el motivo
//   node classify.mjs --in otro.ndjson --out otro-candidatos.ndjson
// ─────────────────────────────────────────────────────────────────────────────

import { readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, isAbsolute } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const KEYWORDS_FILE = join(HERE, 'config', 'keywords.json');
const resolvePath = (p) => (isAbsolute(p) ? p : join(HERE, p));

function parseArgs(argv) {
  const a = { in: join(HERE, 'out', 'videos.ndjson'), out: join(HERE, 'out', 'candidatos.ndjson'), debug: false };
  for (let i = 0; i < argv.length; i++) {
    const t = argv[i];
    if (t === '--debug') a.debug = true;
    else if (t === '--in') a.in = resolvePath(argv[++i]);
    else if (t === '--out') a.out = resolvePath(argv[++i]);
    else { console.error(`Argumento no reconocido: ${t}`); process.exit(2); }
  }
  return a;
}
const args = parseArgs(process.argv.slice(2));

// ── normalización de texto (minúsculas, sin diacríticos) ────────────────────
const normalize = (s) =>
  (s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

// ── clasificación por keywords ──────────────────────────────────────────────
// "Estreno"/"recuperamos" también se usan en un sentido que NO nos interesa:
// el debut/reinicio de actividad DE LA BANDA para una hermandad (p.ej. "estreno
// de nuestra formación tras la imagen de...") no tiene nada que ver con el
// estreno de una marcha nueva. Se neutralizan esas frases antes de buscar
// keywords de clasificación (la comprobación de exclusión no se ve afectada).
const FALSOS_POSITIVOS = [
  /estreno de (?:la|nuestra|esta) (?:formacion|banda|agrupacion)/g,
  /(?:formacion|banda|agrupacion) (?:estrena|estrenara) (?:su|la) temporada/g,
  /estreno de temporada/g,
  /recupera(?:mos)? (?:la )?actividad/g,
];

function stripFalsosPositivos(haystackNorm) {
  let h = haystackNorm;
  for (const re of FALSOS_POSITIVOS) h = h.replace(re, ' ');
  return h;
}

// "ensayo" en la exclusión busca vídeos DE ensayo, pero "local/nave/sala de
// ensayo" es solo el nombre del sitio donde graban (boilerplate habitual en
// vídeos de estreno en audio) — sin esto se descartaban TODOS los estrenos
// de algún canal solo por mencionar su sala de ensayos en la descripción.
const EXCLUSION_FALSOS_POSITIVOS = [/(?:local|nave|sala|salon) de ensayo/g];

function stripExclusionFalsosPositivos(haystackNorm) {
  let h = haystackNorm;
  for (const re of EXCLUSION_FALSOS_POSITIVOS) h = h.replace(re, ' ');
  return h;
}

function classifyText(kw, tituloNorm, descNorm) {
  for (const term of (kw.excluir_titulo?.terminos || [])) {
    if (tituloNorm.includes(normalize(term))) return { clasificacion: 'otro', motivo: `excluido_titulo:${term}` };
  }
  const haystack = `${tituloNorm}\n${descNorm}`;
  const haystackExcl = stripExclusionFalsosPositivos(haystack);
  for (const term of kw.excluir) {
    if (haystackExcl.includes(normalize(term))) return { clasificacion: 'otro', motivo: `excluido:${term}` };
  }
  const haystackCat = stripFalsosPositivos(haystack);
  // Orden de prioridad: estreno > novedad > recuperacion. Un vídeo puede tocar
  // varias categorías (p.ej. "estreno" y "novedad" a la vez); nos quedamos con
  // la primera que matchee y anotamos cuántos términos en total dieron señal.
  const order = ['estreno', 'novedad', 'recuperacion'];
  let hits = 0;
  let clasificacion = null;
  for (const cat of order) {
    const matched = (kw.clasificacion[cat] || []).filter((term) => haystackCat.includes(normalize(term)));
    hits += matched.length;
    if (!clasificacion && matched.length > 0) clasificacion = cat;
  }
  return clasificacion ? { clasificacion, hits } : { clasificacion: 'otro', motivo: 'sin_keywords' };
}

// ── extracción de P_TITULO ──────────────────────────────────────────────────
const QUOTE_PAIRS = [
  ['"', '"'],
  ["'", "'"],
  ['«', '»'],
  ['“', '”'], // “ ”
  ['‘', '’'], // ‘ ’
];

// Prefijos de "etiqueta" que suelen preceder al título real y no son parte de
// él (p.ej. "ESTRENO MUNDIAL: Reina de las Lágrimas" → nos interesa solo lo de
// después de los dos puntos si lo hay, o lo dejamos para el separador). El \b
// final es importante: sin él, "Estrenos" (plural, vídeo-resumen de varias
// marchas) se leía como "Estreno" + sobraba una "s" suelta como título.
const LABEL_PREFIX = /^[\s\p{Extended_Pictographic}️]*\s*(estreno( mundial| absoluto)?|novedad|nuevo estreno|primicia|recuperaci[oó]n|recuperamos)\b\s*:?\s*[-|]?\s*/iu;

// Etiqueta de calidad/resolución al principio del título ("[4K]", "4K", "HD"...),
// muy común en canales de banda y que si no se quita contamina el título
// extraído (p.ej. "4K | Desprecio | Estreno 2025" → sin esto se leía "4K").
const QUALITY_PREFIX = /^\[?\s*(?:4k|8k|2k|hd|fhd|uhd)\s*\]?\s*[-|:]?\s*/i;

function buscarEntrecomillado(text) {
  for (const [open, close] of QUOTE_PAIRS) {
    const re = new RegExp(`${escapeRe(open)}([^${escapeRe(close)}]{2,100})${escapeRe(close)}`);
    const m = text.match(re);
    if (m && m[1].trim().length >= 2) return m[1].trim();
  }
  return null;
}

function extractTitulo(rawTitulo, kw, descripcion) {
  const cleaned = (rawTitulo || '').trim().replace(QUALITY_PREFIX, '');

  const enTitulo = buscarEntrecomillado(cleaned);
  if (enTitulo) return { titulo: enTitulo, confianza: 'alta' };

  // Algunos canales no citan la marcha en el título pero sí en la descripción
  // (p.ej. audios de estreno: 'Audio en directo de "Judio", obra compuesta
  // por...' con título simplemente "JUDIO I ESTRENO (Directo)").
  const enDesc = buscarEntrecomillado(descripcion || '');
  if (enDesc) return { titulo: enDesc, confianza: 'alta' };

  const withoutLabel = cleaned.replace(LABEL_PREFIX, '').trim();
  const candidate = withoutLabel || cleaned;

  const seps = kw.extraccion.separadores;
  let cut = candidate.length;
  for (const sep of seps) {
    const idx = candidate.indexOf(sep);
    if (idx > 0 && idx < cut) cut = idx;
  }
  // Separador estilístico: una "I" mayúscula suelta entre espacios, usado por
  // algún canal en vez de "|" (p.ej. "JUDIO I ESTRENO (Directo)").
  const mI = candidate.match(/\sI\s/);
  if (mI && mI.index > 0 && mI.index < cut) cut = mI.index;

  const segment = candidate.slice(0, cut).trim();
  return { titulo: segment || null, confianza: 'baja' };
}

function escapeRe(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ── extracción de LOCALIDAD (del título) ────────────────────────────────────
// Dos estilos vistos en canales reales:
//   "... en Santaella 2026"                    (año pegado a la localidad)
//   "... en la Cuesta del Rosario | Sábado Santo 2026 | ..." (año en otro
//   segmento tras un separador; la localidad puede llevar artículo/conectores)
// Se corta la captura en el primer separador o justo antes de un año, y se
// exige que el título tenga un año EN ALGÚN PUNTO (evita falsos positivos
// como "en directo", que además no empieza en mayúscula tras "en").
function extractLocalidad(rawTitulo) {
  const t = rawTitulo || '';
  if (!/(19|20)\d{2}/.test(t)) return null;
  const m = t.match(/\ben\s+((?:el|la|los|las)\s+)?([A-ZÁÉÍÓÚÑ][^|:\-–·»«\n]*?)(?=\s*[|:\-–·»«]|\s+(?:19|20)\d{2}\b|$)/);
  if (!m) return null;
  const loc = ((m[1] || '') + m[2]).trim().replace(/\s+(el|la|los|las|de|del)$/i, '').trim();
  return loc || null;
}

// ── extracción de AUTORES (de la descripción) ───────────────────────────────
function splitAutores(chunk) {
  return chunk
    .split(/\s*(?:,|;|\by\b|\be\b|&)\s*/i)
    .map((s) => s.trim().replace(/\.$/, ''))
    .filter((s) => s.length >= 4 && /^[A-ZÁÉÍÓÚÑ]/.test(s));
}

// Conectores entre el título y el nombre del compositor (fragmentos de regex,
// no texto literal — por eso llevan [ií] para admitir tilde). Orden: frases
// largas primero (la alternación prueba en orden y para en el primer match).
const AUTOR_CONECTORES =
  'autor[ií]a de|compuesta por|compuesto por|obra de|original de|de';

// Palabras que marcan dónde termina el nombre y empieza otra cosa (dedicatoria,
// intérprete...). Sin "dedicad[oa]" frases como "de Fulano dedicada a la
// Virgen..." se colaban enteras dentro del nombre capturado.
const STOP_AUTOR = /\b(dedicad[oa]|interpretad[ao]|por la|por el|en el|en la|para)\b/i;

function extractAutores(descripcion, tituloCandidato) {
  const desc = descripcion || '';

  // 1. "<Título candidato>" ... [conector] <Nombre1> y <Nombre2>
  if (tituloCandidato) {
    const escTitulo = escapeRe(tituloCandidato);
    const reAfterTitle = new RegExp(`${escTitulo}["'”’»]?\\s*,?\\s*(?:${AUTOR_CONECTORES})\\s+([^.\\n]{3,140})`, 'i');
    const m = desc.match(reAfterTitle);
    if (m) {
      const autores = splitAutores(m[1].split(STOP_AUTOR)[0]);
      if (autores.length) return { autores, confianza: 'alta' };
    }
  }

  // 2. Etiqueta explícita "Autor(es):" / "Compositor(es):"
  const reLabel = desc.match(/\b(?:autor(?:es)?|compositor(?:es)?)\s*:\s*([^\n]{3,140})/i);
  if (reLabel) {
    const autores = splitAutores(reLabel[1].split(STOP_AUTOR)[0]);
    if (autores.length) return { autores, confianza: 'media' };
  }

  // 3. "obra (musical) de <Autor>" sin repetir el título antes (p.ej. "Nueva
  // obra musical de Jesús Barrera Ríos, dedicada a...").
  const reObra = desc.match(/\bobra(?:\s+musical)?\s+de\s+([^.\n]{3,140})/i);
  if (reObra) {
    const autores = splitAutores(reObra[1].split(STOP_AUTOR)[0]);
    if (autores.length) return { autores, confianza: 'media' };
  }

  return { autores: [], confianza: null };
}

// ── construcción de un candidato ────────────────────────────────────────────
function buildCandidate(video, kw) {
  const tituloNorm = normalize(video.titulo);
  const descNorm = normalize(video.descripcion);
  const { clasificacion, hits, motivo } = classifyText(kw, tituloNorm, descNorm);

  if (clasificacion === 'otro') {
    return { candidato: null, descartado: { video_id: video.video_id, motivo: motivo || 'sin_keywords' } };
  }

  const flags = [];
  const { titulo: pTitulo, confianza: tituloConfianza } = extractTitulo(video.titulo, kw, video.descripcion);
  if (!pTitulo) flags.push('sin_titulo_detectado');
  if (tituloConfianza === 'baja') flags.push('titulo_sin_comillas');

  const { autores, confianza: autoresConfianza } = extractAutores(video.descripcion, pTitulo);
  if (autores.length === 0) flags.push('sin_autor_detectado');

  const localidad = extractLocalidad(video.titulo);
  if (!localidad) flags.push('sin_localidad_detectada');

  const anioPublicacion = video.publicado ? parseInt(video.publicado.slice(0, 4), 10) : null;
  if (!anioPublicacion) flags.push('sin_fecha_publicacion');

  // Confianza compuesta: arranca de la fuerza de la clasificación y suma
  // puntos por cada campo bien resuelto; se queda en revisión humana el resto.
  let confianza = Math.min(0.5 + 0.15 * (hits || 1), 0.8);
  if (tituloConfianza === 'alta') confianza += 0.1;
  if (autoresConfianza === 'alta') confianza += 0.1;
  else if (autoresConfianza === 'media') confianza += 0.05;
  if (localidad) confianza += 0.05;
  confianza = Math.round(Math.min(confianza, 1) * 100) / 100;

  return {
    candidato: {
      id_banda: video.id_banda,
      video_id: video.video_id,
      video_url: video.url,
      video_titulo: video.titulo,
      video_desc: video.descripcion,
      publicado_at: video.publicado,
      duracion_seg: video.duracion_seg,
      clasificacion,
      confianza,
      flags,
      p_titulo: pTitulo,
      p_fecha: anioPublicacion,
      p_dedicatoria: null,
      // Localidad y provincia sólo tienen sentido con dedicatoria; sin ella van vacías.
      p_localidad: null,
      p_provincia: null,
      p_autores: autores.length ? autores.join(', ') : null,
      p_banda_estreno: video.id_banda,
      raw_json: JSON.stringify(video),
    },
    descartado: null,
  };
}

async function main() {
  if (!existsSync(args.in)) {
    console.error(`No existe ${args.in}. Ejecuta antes extract.mjs (Fase 1).`);
    process.exit(1);
  }
  const kw = JSON.parse(await readFile(KEYWORDS_FILE, 'utf8'));
  const lines = (await readFile(args.in, 'utf8')).split(/\r?\n/).filter((l) => l.trim());

  const candidatos = [];
  const descartados = [];
  const porClase = { estreno: 0, novedad: 0, recuperacion: 0, otro: 0 };

  for (const line of lines) {
    const video = JSON.parse(line);
    const { candidato, descartado } = buildCandidate(video, kw);
    if (candidato) {
      candidatos.push(candidato);
      porClase[candidato.clasificacion]++;
    } else {
      descartados.push({ ...descartado, titulo: video.titulo });
      porClase.otro++;
    }
  }

  await writeFile(args.out, candidatos.map((c) => JSON.stringify(c)).join('\n') + (candidatos.length ? '\n' : ''), 'utf8');
  if (args.debug) {
    const debugFile = join(dirname(args.out), 'descartados.ndjson');
    await writeFile(debugFile, descartados.map((d) => JSON.stringify(d)).join('\n') + (descartados.length ? '\n' : ''), 'utf8');
    console.log(`descartados → ${debugFile}`);
  }

  console.log(`\n── Resumen (${lines.length} vídeos) ──`);
  for (const [cat, n] of Object.entries(porClase)) console.log(`  ${cat}: ${n}`);
  const sinFlags = candidatos.filter((c) => c.flags.length === 0).length;
  console.log(`  candidatos: ${candidatos.length} (${sinFlags} sin flags, ${candidatos.length - sinFlags} a revisar)`);
  console.log(`  → ${args.out}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
