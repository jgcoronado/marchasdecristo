/*
 * Espejo de nextjs/lib/api.ts ejecutando el SQL EXACTO del sistema actual
 * (json_group_array incluido) con better-sqlite3, para comparar con el port PHP.
 * Genera parity_expected.json con la salida canónica de cada caso.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const Database = require(path.join(__dirname, '..', '..', 'nextjs', 'node_modules', 'better-sqlite3'));

const DB_PATH = path.join(__dirname, '..', 'data', 'mdc.db');
const OUT = process.argv[2] || path.join(__dirname, 'parity_expected.json');

const db = new Database(DB_PATH, { readonly: true });
db.pragma('foreign_keys = ON');
const dbAll = (sql, params = []) => db.prepare(sql).all(...params);

// ── helpers (idénticos a api.ts) ──────────────────────────────────────────
const buildFtsQuery = (raw) => {
  const cleaned = raw.replace(/[^\p{L}\p{N}\s]/gu, ' ').trim();
  if (!cleaned) return null;
  return cleaned.split(/\s+/).map((t) => `"${t}"`).join(' ');
};
const normalizeFecha = (row) => {
  if (row.FECHA === null || row.FECHA === '') row.FECHA = 's/f';
  return row;
};
const formatAutor = (row) => {
  if (row.AUTOR == null) { row.AUTOR = []; return row; }
  row.AUTOR = JSON.parse(row.AUTOR);
  return row;
};

// ── funciones (SQL copiado de api.ts) ─────────────────────────────────────
function fetchMarcha(id) {
  const rows = dbAll(`
    SELECT m.ID_MARCHA, m.TITULO, m.DEDICATORIA, m.LOCALIDAD, m.PROVINCIA, m.AUDIO, m.FECHA,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR,
      m.BANDA_ESTRENO, m.DETALLES_MARCHA,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
    FROM marcha m
    LEFT OUTER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
    WHERE m.ID_MARCHA = ?
      AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)`, [id]);
  if (!rows.length) return null;
  const marcha = formatAutor(normalizeFecha(rows[0]));
  const discos = dbAll(`
    SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, b.ID_BANDA,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
    FROM disco d
    LEFT OUTER JOIN disco_marcha dm ON dm.ID_DISCO = d.ID_DISCO
    LEFT OUTER JOIN banda b ON b.ID_BANDA = d.BANDADISCO
    WHERE dm.IDMARCHA = ? ORDER BY d.FECHA_CD ASC`, [id]);
  return { ...marcha, discosLength: discos.length, discos };
}

function searchMarchas(query, page = 1, limit = 20) {
  const params = new URLSearchParams(query);
  const conditions = [];
  const values = [];
  const titulo = params.get('titulo') ?? '';
  const fts = titulo ? buildFtsQuery(titulo) : null;
  if (fts) { conditions.push(`m.ID_MARCHA IN (SELECT rowid FROM marcha_fts WHERE marcha_fts MATCH ?)`); values.push(fts); }
  const { fechaDesde, fechaHasta, dedicatoria, localidad, provincia } = Object.fromEntries(params);
  if (fechaDesde) { conditions.push(`m.FECHA >= ?`); values.push(fechaDesde); }
  if (fechaHasta) { conditions.push(`m.FECHA <= ?`); values.push(fechaHasta); }
  if (dedicatoria) { conditions.push(`m.DEDICATORIA LIKE ?`); values.push(`%${dedicatoria}%`); }
  if (localidad) { conditions.push(`m.LOCALIDAD LIKE ?`); values.push(`%${localidad}%`); }
  if (provincia) { conditions.push(`m.PROVINCIA LIKE ?`); values.push(`%${provincia}%`); }
  const where = conditions.length ? conditions.join(' AND ') : '1=1';
  const baseWhere = `EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA) AND ${where}`;
  const countRows = dbAll(`SELECT COUNT(*) AS n FROM marcha m WHERE ${baseWhere}`, values);
  const totalRows = countRows[0]?.n ?? 0;
  const offset = (page - 1) * limit;
  const rows = dbAll(`
    SELECT m.ID_MARCHA, m.TITULO, m.DEDICATORIA, m.LOCALIDAD, m.AUDIO, m.FECHA,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR,
      CASE WHEN EXISTS (SELECT 1 FROM disco_marcha dm WHERE dm.IDMARCHA = m.ID_MARCHA) THEN 1 ELSE 0 END AS GRABADA
    FROM marcha m
    WHERE ${baseWhere}
    ORDER BY m.TITULO ASC LIMIT ? OFFSET ?`, [...values, limit, offset]);
  rows.forEach((r) => { normalizeFecha(r); formatAutor(r); });
  return { rowsReturned: rows.length, totalRows, data: rows };
}

function fetchAutor(id) {
  const autores = dbAll(`SELECT * FROM autor WHERE ID_AUTOR = ?`, [id]);
  if (!autores.length) return null;
  const marchas = dbAll(`
    SELECT m.ID_MARCHA, m.TITULO, m.FECHA, m.DEDICATORIA
    FROM marcha m
    INNER JOIN marcha_autor ma ON ma.ID_MARCHA = m.ID_MARCHA
    WHERE ma.ID_AUTOR = ? ORDER BY m.FECHA ASC`, [id]);
  marchas.forEach(normalizeFecha);
  return { ...autores[0], marchasLength: marchas.length, marchas };
}

function searchAutores(query, page = 1, limit = 20) {
  const params = new URLSearchParams(query);
  const nombre = params.get('nombre') ?? '';
  const fts = nombre ? buildFtsQuery(nombre) : null;
  const where = fts ? `a.ID_AUTOR IN (SELECT rowid FROM autor_fts WHERE autor_fts MATCH ?)` : '1=1';
  const values = fts ? [fts] : [];
  const countRows = dbAll(`SELECT COUNT(*) AS n FROM autor a WHERE ${where}`, values);
  const totalRows = countRows[0]?.n ?? 0;
  const offset = (page - 1) * limit;
  const rows = dbAll(`
    SELECT a.*, (a.NOMBRE || ' ' || a.APELLIDOS) AS NOMBRE_COMPLETO,
      (SELECT COUNT(ma.ID_MARCHA) FROM marcha_autor ma WHERE ma.ID_AUTOR = a.ID_AUTOR) AS MARCHAS
    FROM autor a WHERE ${where} ORDER BY a.APELLIDOS ASC LIMIT ? OFFSET ?`, [...values, limit, offset]);
  return { rowsReturned: rows.length, totalRows, data: rows };
}

function fetchBanda(id) {
  const bandas = dbAll(`SELECT * FROM banda WHERE ID_BANDA = ?`, [id]);
  if (!bandas.length) return null;
  const banda = bandas[0];
  const { ID_BANDA, FECHA_FUND, FECHA_EXT, NOMBRE_BREVE } = banda;
  const timeline = [{ ID_BANDA, FECHA_FUND, FECHA_EXT, NOMBRE_BREVE }];
  const discos = dbAll(`
    SELECT DISTINCT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD,
      (SELECT COUNT(m.ID_DM) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS PISTAS,
      (SELECT MAX(m.N_DISCO) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS DISCOS
    FROM disco d WHERE d.BANDADISCO = ? ORDER BY d.FECHA_CD ASC`, [id]);
  const marchas = dbAll(`
    SELECT m.TITULO, m.ID_MARCHA, m.DEDICATORIA, m.LOCALIDAD, m.FECHA,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor am INNER JOIN autor a ON a.ID_AUTOR = am.ID_AUTOR
             WHERE am.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR
    FROM marcha m
    WHERE m.BANDA_ESTRENO = ?
      AND EXISTS (SELECT 1 FROM marcha_autor am WHERE am.ID_MARCHA = m.ID_MARCHA)
    ORDER BY m.FECHA DESC, m.TITULO ASC`, [id]);
  marchas.forEach((r) => formatAutor(r));
  timeline.sort((a, b) => a.FECHA_FUND - b.FECHA_FUND);
  return { ...banda, timeline, discosLength: discos.length, discos, marchasLength: marchas.length, marchas };
}

function searchBandas(query, page = 1, limit = 20) {
  const params = new URLSearchParams(query);
  const conditions = [];
  const values = [];
  const { titulo, localidad, provincia } = Object.fromEntries(params);
  if (titulo) { conditions.push(`b.NOMBRE_COMPLETO LIKE ?`); values.push(`%${titulo}%`); }
  if (localidad) { conditions.push(`b.LOCALIDAD LIKE ?`); values.push(`%${localidad}%`); }
  if (provincia) { conditions.push(`b.PROVINCIA LIKE ?`); values.push(`%${provincia}%`); }
  const where = conditions.length ? conditions.join(' AND ') : '1=1';
  const countRows = dbAll(`SELECT COUNT(*) AS n FROM banda b WHERE ${where}`, values);
  const totalRows = countRows[0]?.n ?? 0;
  const offset = (page - 1) * limit;
  const rows = dbAll(`
    SELECT b.ID_BANDA, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO, b.PROVINCIA,
      b.LOCALIDAD, b.FECHA_FUND, b.FECHA_EXT,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
    FROM banda b WHERE ${where}
    GROUP BY b.ID_BANDA ORDER BY b.NOMBRE_BREVE ASC LIMIT ? OFFSET ?`, [...values, limit, offset]);
  return { rowsReturned: rows.length, totalRows, data: rows };
}

function fetchDisco(id) {
  const discos = dbAll(`
    SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, d.d_DETALLES, b.ID_BANDA,
      (SELECT MAX(m.N_DISCO) FROM disco_marcha m WHERE m.ID_DISCO = d.ID_DISCO) AS DISCOS,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
    FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
    WHERE d.ID_DISCO = ?`, [id]);
  if (!discos.length) return null;
  const marchas = dbAll(`
    SELECT dm.N_DISCO, dm.NUMEROMARCHA, m.ID_MARCHA, m.TITULO, m.FECHA,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor am INNER JOIN autor a ON a.ID_AUTOR = am.ID_AUTOR
             WHERE am.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR,
      CASE WHEN dm.DM_ENLAZADA IS NULL THEN 0 ELSE 1 END AS ENLAZADA
    FROM disco d
    INNER JOIN disco_marcha dm ON dm.ID_DISCO = d.ID_DISCO
    INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
    WHERE d.ID_DISCO = ?
      AND EXISTS (SELECT 1 FROM marcha_autor am WHERE am.ID_MARCHA = m.ID_MARCHA)
    ORDER BY dm.N_DISCO ASC, dm.NUMEROMARCHA ASC, dm.DM_ENLAZADA ASC`, [id]);
  marchas.forEach((r) => { normalizeFecha(r); formatAutor(r); });
  return { ...discos[0], marchasLength: marchas.length, marchas };
}

function searchDiscos(query, page = 1, limit = 20) {
  const params = new URLSearchParams(query);
  const nombre = params.get('nombre') ?? '';
  const where = nombre ? `d.NOMBRE_CD LIKE ?` : '1=1';
  const values = nombre ? [`%${nombre}%`] : [];
  const countRows = dbAll(`SELECT COUNT(*) AS n FROM disco d WHERE ${where}`, values);
  const totalRows = countRows[0]?.n ?? 0;
  const offset = (page - 1) * limit;
  // TIENE_STREAMING no existe en api.ts: es una columna añadida por la versión
  // PHP (icono "Escuchar" del listado /disco). Se replica aquí para que el
  // comparador siga verde — la divergencia es intencionada, no un fallo de porte.
  const rows = dbAll(`
    SELECT d.ID_DISCO, d.NOMBRE_CD, d.FECHA_CD, b.ID_BANDA,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
      EXISTS (SELECT 1 FROM enlace_streaming es
              WHERE es.TIPO_ENT = 'disco' AND es.ID_ENT = d.ID_DISCO) AS TIENE_STREAMING
    FROM disco d LEFT JOIN banda b ON b.ID_BANDA = d.BANDADISCO
    WHERE ${where} ORDER BY d.FECHA_CD ASC LIMIT ? OFFSET ?`, [...values, limit, offset]);
  return { rowsReturned: rows.length, totalRows, data: rows };
}

function fetchUltimas() {
  const rows = dbAll(`
    SELECT m.ID_MARCHA, m.TITULO, m.FECHA,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR
    FROM marcha m
    WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
    ORDER BY m.ID_MARCHA DESC LIMIT 5`);
  rows.forEach((r) => { normalizeFecha(r); formatAutor(r); });
  return rows;
}

function fetchEstado() {
  const rows = dbAll(`SELECT
    (SELECT COUNT(*) FROM marcha) AS MARCHAS,
    (SELECT COUNT(*) FROM autor)  AS AUTORES,
    (SELECT COUNT(*) FROM banda)  AS BANDAS,
    (SELECT COUNT(*) FROM disco)  AS DISCOS`);
  return rows[0];
}
function fetchMasAutor() {
  return dbAll(`
    SELECT a.ID_AUTOR, COUNT(m.ID_MARCHA) AS MARCHAS,
      (a.NOMBRE || ' ' || a.APELLIDOS) AS AUTOR
    FROM autor a
    INNER JOIN marcha_autor am ON am.ID_AUTOR = a.ID_AUTOR
    INNER JOIN marcha m ON m.ID_MARCHA = am.ID_MARCHA
    GROUP BY a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
    ORDER BY MARCHAS DESC LIMIT 10`);
}
function fetchMasDedica() {
  return dbAll(`
    SELECT COUNT(DEDICATORIA) AS CUENTA,
      (DEDICATORIA || ' (' || LOCALIDAD || ')') AS LUGAR
    FROM marcha WHERE DEDICATORIA LIKE '%Hdad%' GROUP BY LUGAR
    HAVING CUENTA >= 15 ORDER BY CUENTA DESC`);
}
function fetchMasEstreno() {
  return dbAll(`
    SELECT b.ID_BANDA, COUNT(m.ID_MARCHA) AS MARCHAS,
      (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA
    FROM marcha m INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
    WHERE b.ID_BANDA != 0
    GROUP BY b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
    ORDER BY MARCHAS DESC LIMIT 20`);
}
function fetchMasGrabada() {
  const rows = dbAll(`
    SELECT COUNT(dm.IDMARCHA) AS GRABACIONES, m.ID_MARCHA, m.TITULO,
      (SELECT json_group_array(json_object('autorId', ID_AUTOR, 'nombre', NOMBRE || ' ' || APELLIDOS))
       FROM (SELECT DISTINCT a.ID_AUTOR, a.NOMBRE, a.APELLIDOS
             FROM marcha_autor ma INNER JOIN autor a ON a.ID_AUTOR = ma.ID_AUTOR
             WHERE ma.ID_MARCHA = m.ID_MARCHA ORDER BY a.APELLIDOS)
      ) AS AUTOR
    FROM disco_marcha dm INNER JOIN marcha m ON m.ID_MARCHA = dm.IDMARCHA
    WHERE EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
    GROUP BY dm.IDMARCHA, m.ID_MARCHA, m.TITULO
    ORDER BY GRABACIONES DESC LIMIT 20`);
  rows.forEach((r) => formatAutor(r));
  return rows;
}

// ── SEO schema (copiado de lib/schema.ts + lib/slugify.ts) ──────────────────
const SCHEMA_BASE = process.env.SITE_URL || 'https://marchasdecristo.com';
const slugifyLib = (value) => {
  if (!value) return '';
  return String(value).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-');
};
const buildDetailPath = (page, id, label) => {
  const safeId = String(id ?? '').trim();
  if (!safeId) return `/${page}`;
  const slug = slugifyLib(label);
  return slug ? `/${page}/${slug}-${safeId}` : `/${page}/${safeId}`;
};
const schemaSlug = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  .replace(/[^\w\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-');
const formatDate = (dateStr) => {
  if (!dateStr) return undefined;
  const str = String(dateStr);
  if (str.includes('-')) return str;
  if (str.length === 4) return str;
  return /^\d{4}/.test(str) ? str : undefined;
};
function generateMarchaSchema(data, url) {
  const autores = data.AUTOR.map((a) => ({ '@type': 'Person', name: a.nombre, url: `${SCHEMA_BASE}/autor/${schemaSlug(a.nombre)}-${a.autorId}` }));
  const bandaEstreno = data.BANDA_ESTRENO ? { '@type': 'MusicGroup', name: data.BANDA, url: `${SCHEMA_BASE}/banda/${schemaSlug(data.BANDA)}-${data.BANDA_ESTRENO}` } : undefined;
  return {
    '@context': 'https://schema.org', '@type': 'MusicComposition', name: data.TITULO, url,
    creator: autores.length > 0 ? autores : undefined,
    dateCreated: data.FECHA ? String(data.FECHA) : undefined,
    description: `Marcha procesional${data.DEDICATORIA ? ` dedicada a ${data.DEDICATORIA}` : ''}.`,
    performanceLocation: bandaEstreno,
    ...(data.discosLength > 0 && { recordedAs: data.discos.map((d) => ({ '@type': 'MusicRecording', name: d.NOMBRE_CD, byArtist: { '@type': 'MusicGroup', name: d.BANDA } })) }),
  };
}
function generateAutorSchema(data, url) {
  return {
    '@context': 'https://schema.org', '@type': 'Person', name: `${data.NOMBRE} ${data.APELLIDOS}`.trim(), url,
    birthDate: data.F_NAC ? formatDate(data.F_NAC) : undefined,
    birthPlace: data.LUGAR_NAC ? { '@type': 'Place', name: data.LUGAR_NAC } : undefined,
    description: `Compositor de música procesional. Ha compuesto ${data.marchasLength} marchas.`,
    ...(data.BIO && { knowsAbout: data.BIO }),
  };
}
function generateBandaSchema(data, url) {
  return {
    '@context': 'https://schema.org', '@type': 'MusicGroup', name: data.NOMBRE_COMPLETO || data.NOMBRE_BREVE, url,
    foundingDate: data.FECHA_FUND ? String(data.FECHA_FUND) : undefined,
    dissolutionDate: data.FECHA_EXT ? String(data.FECHA_EXT) : undefined,
    location: { '@type': 'Place', name: data.LOCALIDAD },
    description: `Banda de música procesional. Ha estrenado ${data.marchasLength} marchas y grabado ${data.discosLength} discos.`,
  };
}
function generateDiscoSchema(data, url) {
  return {
    '@context': 'https://schema.org', '@type': 'MusicAlbum', name: data.NOMBRE_CD, url,
    byArtist: { '@type': 'MusicGroup', name: data.BANDA },
    datePublished: data.FECHA_CD ? String(data.FECHA_CD) : undefined,
    description: `Disco que contiene ${data.marchasLength} marchas de música procesional.`,
    ...(data.marchasLength > 0 && {
      tracks: { '@type': 'ItemList', itemListElement: data.marchas.map((m, idx) => ({ '@type': 'MusicRecording', position: idx + 1, name: m.TITULO, byArtist: m.AUTOR.map((a) => ({ '@type': 'Person', name: a.nombre })) })) },
    }),
  };
}
function generateBreadcrumbs(items) {
  return { '@context': 'https://schema.org', '@type': 'BreadcrumbList', itemListElement: items.map((item, idx) => ({ '@type': 'ListItem', position: idx + 1, name: item.name, item: item.url })) };
}

const _m = fetchMarcha('330');
const _a = fetchAutor('44');
const _b = fetchBanda('6');
const _d = fetchDisco('165');
const _urlM = `${SCHEMA_BASE}${buildDetailPath('marcha', _m.ID_MARCHA, _m.TITULO)}`;

// ── casos ──────────────────────────────────────────────────────────────────
const cases = {
  marcha_330: fetchMarcha('330'),
  marcha_1: fetchMarcha('1'),
  marcha_missing: fetchMarcha('99999'),
  search_marchas_amargura: searchMarchas('titulo=Amargura', 1, 20),
  search_marchas_localidad_sevilla: searchMarchas('localidad=Sevilla', 1, 20),
  search_marchas_fecha: searchMarchas('fechaDesde=1990&fechaHasta=2000', 1, 5),
  search_marchas_dedic: searchMarchas('dedicatoria=Hdad', 1, 10),
  autor_1: fetchAutor('1'),
  autor_44: fetchAutor('44'),
  autor_missing: fetchAutor('99999'),
  search_autores_gamez: searchAutores('nombre=Gamez', 1, 20),
  banda_1: fetchBanda('1'),
  banda_6: fetchBanda('6'),
  search_bandas_tres: searchBandas('titulo=Tres', 1, 20),
  disco_1: fetchDisco('1'),
  disco_165: fetchDisco('165'),
  search_discos_pasion: searchDiscos('nombre=Pasion', 1, 20),
  ultimas: fetchUltimas(),
  estado: fetchEstado(),
  masAutor: fetchMasAutor(),
  masDedica: fetchMasDedica(),
  masEstreno: fetchMasEstreno(),
  masGrabada: fetchMasGrabada(),

  schema_marcha_330: generateMarchaSchema(_m, _urlM),
  schema_autor_44: generateAutorSchema(_a, `${SCHEMA_BASE}${buildDetailPath('autor', _a.ID_AUTOR, `${_a.NOMBRE} ${_a.APELLIDOS}`.trim())}`),
  schema_banda_6: generateBandaSchema(_b, `${SCHEMA_BASE}${buildDetailPath('banda', _b.ID_BANDA, _b.NOMBRE_COMPLETO)}`),
  schema_disco_165: generateDiscoSchema(_d, `${SCHEMA_BASE}${buildDetailPath('disco', _d.ID_DISCO, _d.NOMBRE_CD)}`),
  breadcrumbs_marcha_330: generateBreadcrumbs([
    { name: 'Inicio', url: SCHEMA_BASE },
    { name: 'Marchas', url: `${SCHEMA_BASE}/marcha` },
    { name: _m.TITULO, url: _urlM },
  ]),
};

fs.writeFileSync(OUT, JSON.stringify(cases, null, 2));
console.log('expected escrito en', OUT, '—', Object.keys(cases).length, 'casos');
