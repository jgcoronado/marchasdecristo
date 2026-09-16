#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Fase 2: normaliza hermandad/paso, resuelve solapes entre fuentes (prioridad
a la revision 2026 de costalero_prado sobre la lista 2019 de josemariumb),
detecta conflictos reales, y casa el nombre de banda contra la tabla banda.

Entradas: extraidos_raw.csv, banda_dump.csv, contrato_dump.csv
Salidas:
  reemplazos.csv          -- auditoria: valores 2019 sustituidos por la revision 2026
  conflictos.csv          -- mismo (hermandad,paso,anio) con valores distintos DENTRO de la misma fuente/prioridad -> requieren decision humana
  resueltos.csv           -- (hermandad,paso,anio,banda_raw) ya deduplicados, un valor por clave
  bandas_no_resueltas.csv -- nombres de banda sin match claro en la tabla banda (agrupados, con cuantas filas afecta)
  contratos_a_cargar.csv  -- CSV listo para seed_contratos_2026.php (columnas ID_BANDA,HERMANDAD,TITULAR,ANIO,FUENTE,NOTA,LOCALIDAD)
"""
import csv, re, sys, unicodedata
from collections import defaultdict, Counter

def strip_accents(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')

def norm_key(s):
    s = strip_accents(s).upper()
    s = re.sub(r'[^A-Z0-9 ]+', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s

# --- Mapa hermandad forum -> nombre oficial HERMANDAD que se escribira en contrato ---
HERM_MAP = {
 'AMARGURA':'La Amargura','AMOR':'El Amor','BELLAVISTA':'Dulce Nombre de Bellavista',
 'BENDICION Y ESPERANZA':'Bendicion y Esperanza','BESO DE JUDAS':'El Beso de Judas',
 'BUEN FIN':'El Buen Fin','CANDELARIA':'La Candelaria','CARRETERIA':'La Carreteria',
 'CRISTO DE BURGOS':'Cristo de Burgos','DIVINO PERDON ALCOSA':'Divino Perdon de Alcosa',
 'DIVINO PERDON (ALCOSA)':'Divino Perdon de Alcosa','DULCE NOMBRE':'El Dulce Nombre',
 'DULCE NOMBRE (BELLAVISTA)':'Dulce Nombre de Bellavista','EL AMOR':'El Amor',
 'EL BARATILLO':'El Baratillo','EL BESO DE JUDAS':'El Beso de Judas','EL CACHORRO':'El Cachorro',
 'EL CARMEN':'El Carmen','EL CERRO':'El Cerro del Aguila','EL DULCE NOMBRE':'El Dulce Nombre',
 'EL MUSEO':'El Museo','EL VALLE':'El Valle','ENTRADA EN JERUSALEN':'La Borriquita',
 'ESPERANZA DE TRIANA':'La Esperanza de Triana','ESTRELLA':'La Estrella','HINIESTA':'La Hiniesta',
 'JESUS DESPOJADO':'Jesus Despojado','LA BORRIQUITA':'La Borriquita','LA CANDELARIA':'La Candelaria',
 'LA CENA':'La Cena','LA ESPIGA (SEVILLA ESTE)':'La Espiga','LA ESTRELLA':'La Estrella',
 'LA EXALTACION':'La Exaltacion','LA LANZADA':'La Lanzada','LA MILAGROSA':'La Milagrosa',
 'LA MISION (HELIOPOLIS)':'La Mision','LA O':'La O','LA PAZ':'La Paz','LA SED':'La Sed',
 'LAS AGUAS':'Las Aguas','LAS CIGARRERAS':'Las Cigarreras',
 'LAS PENAS DE SAN VICENTE':'Las Penas de San Vicente','LAS SIETE PALABRAS':'Las Siete Palabras',
 'LOS ESTUDIANTES':'Los Estudiantes','LOS GITANOS':'Los Gitanos','LOS JAVIERES':'Los Javieres',
 'LOS PANADEROS':'Los Panaderos','MACARENA':'La Macarena','MONTESION':'Montesion',
 'MONTSERRAT':'Montserrat','MUSEO':'El Museo','NEGRITOS':'Los Negritos','PADRE PIO':'Padre Pio',
 'PADRE PIO (PALMETE)':'Padre Pio','PASION':'La Pasion','PAZ Y MISERICORDIA':'Paz y Misericordia',
 'PAZ Y MISERICORDIA (ROCHELAMBERT)':'Paz y Misericordia','PINOMONTANO':'Pino Montano',
 'RESURRECCION':'La Resurreccion','SAGRADA CENA':'La Cena','SAN BENITO':'San Benito',
 'SAN BERNARDO':'San Bernardo','SAN ESTEBAN':'San Esteban','SAN GONZALO':'San Gonzalo',
 'SAN JERONIMO':'San Jeronimo','SAN JOSE OBRERO':'San Jose Obrero','SAN PABLO':'San Pablo',
 'SAN ROQUE':'San Roque','SANTA CRUZ':'Santa Cruz','SANTA GENOVEVA':'Santa Genoveva',
 'SANTO ENTIERRO':'Santo Entierro','SERVITAS':'Los Servitas','SOL':'El Sol',
 'SOLEDAD DE SAN BUENAVENTURA':'Soledad de San Buenaventura','TORREBLANCA':'Dolores de Torreblanca',
 'TRINIDAD':'La Trinidad',
}

HERM_MAP_NORM = {norm_key(k): v for k, v in HERM_MAP.items()}

def canon_herm(raw):
    k = norm_key(raw)
    if k not in HERM_MAP_NORM:
        raise KeyError(f"Hermandad sin mapear: {raw!r} (key={k!r})")
    return HERM_MAP_NORM[k]

def norm_titular(raw):
    if not raw:
        return ''
    t = raw.strip().rstrip(':').strip()
    tk = norm_key(t)
    repl = {
        'PASO CRISTO':'Paso de Cristo','PASO DE CRISTO':'Paso de Cristo',
        'PASO MISTERIO':'Paso de Misterio','PASO DE MISTERIO':'Paso de Misterio',
        'PASO DE MISTERIO ':'Paso de Misterio',
        'PASO PALIO':'Paso de Palio','PASO DE PALIO':'Paso de Palio',
        'PASO VIRGEN':'Paso de Virgen','PASO DE VIRGEN':'Paso de Virgen',
        'PASO DEL NAZARENO':'Paso del Nazareno','PASO DEL CAUTIVO':'Paso del Cautivo',
        'PASO DEL SENOR':'Paso del Senor','PASO DEL DECRETO':'Paso del Decreto',
        'PASO DE LAS CINCO LLAGAS':'Paso de las Cinco Llagas','PASO DE LA URNA':'Paso de la Urna',
        'PASO DE LA VIRGEN':'Paso de Virgen',
    }
    return repl.get(tk, t)

# --- Prioridad de fuentes: mayor numero = mas reciente/autoritativa ---
SOURCE_PRIORITY = {
    'foro-2019-josemariumb': 1,
    'foro-2026-costalero_prado-visperas': 2,
    'foro-2026-costalero_prado-carrera': 2,
}

rows = list(csv.DictReader(open('extraidos_raw.csv', encoding='utf-8')))
print(f"Filas leidas de extraidos_raw.csv: {len(rows)}", file=sys.stderr)

by_key = defaultdict(list)  # (herm,titular,anio) -> list of (priority, banda_norm, source, orig_line, banda_raw)
unmapped_herms = Counter()

for r in rows:
    try:
        herm = canon_herm(r['hermandad'])
    except KeyError as e:
        unmapped_herms[r['hermandad']] += 1
        continue
    tit = norm_titular(r['paso'])
    anio = int(r['anio'])
    banda_raw = r['banda_raw'].strip()
    # limpia notas finales tipo "(mismas que la anterior)" o dobletes con "/" -> se queda el texto completo, no se parte
    prio = SOURCE_PRIORITY.get(r['source'], 0)
    key = (herm, tit, anio)
    by_key[key].append(dict(prio=prio, banda_raw=banda_raw, source=r['source'], orig_line=r['orig_line']))

if unmapped_herms:
    print("AVISO: hermandades sin mapear (revisar HERM_MAP):", file=sys.stderr)
    for h, c in unmapped_herms.most_common():
        print(f"  {c:4d}  {h}", file=sys.stderr)

resueltos = []
reemplazos = []
conflictos = []

for key, entries in by_key.items():
    herm, tit, anio = key
    maxprio = max(e['prio'] for e in entries)
    top = [e for e in entries if e['prio'] == maxprio]
    distinct_top = {}
    for e in top:
        distinct_top.setdefault(e['banda_raw'], []).append(e)
    if len(distinct_top) == 1:
        banda_raw = next(iter(distinct_top))
        resueltos.append(dict(hermandad=herm, titular=tit, anio=anio, banda_raw=banda_raw,
                               fuentes=';'.join(sorted(set(e['source'] for e in entries)))))
        if maxprio > min((e['prio'] for e in entries), default=maxprio):
            lower = [e for e in entries if e['prio'] < maxprio]
            for e in lower:
                if e['banda_raw'] != banda_raw:
                    reemplazos.append(dict(hermandad=herm, titular=tit, anio=anio,
                                            valor_2019=e['banda_raw'], valor_2026=banda_raw))
    else:
        # conflicto real dentro de la fuente mas prioritaria
        conflictos.append(dict(hermandad=herm, titular=tit, anio=anio,
                                opciones=' | '.join(f"[{e['source']}] {e['banda_raw']}" for e in top)))

print(f"Claves (hermandad,paso,anio) resueltas sin ambiguedad: {len(resueltos)}", file=sys.stderr)
print(f"Reemplazos 2019->2026 registrados: {len(reemplazos)}", file=sys.stderr)
print(f"Conflictos reales (misma prioridad, valores distintos): {len(conflictos)}", file=sys.stderr)

with open('reemplazos.csv','w',newline='',encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['hermandad','titular','anio','valor_2019','valor_2026'])
    w.writeheader(); w.writerows(reemplazos)

with open('conflictos.csv','w',newline='',encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['hermandad','titular','anio','opciones'])
    w.writeheader(); w.writerows(conflictos)

with open('resueltos.csv','w',newline='',encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['hermandad','titular','anio','banda_raw','fuentes'])
    w.writeheader(); w.writerows(resueltos)

# ---- Fase 3: casar banda_raw contra la tabla banda ----
bandas = list(csv.DictReader(open('banda_dump.csv', encoding='utf-8')))

STOPWORDS = {'B','BM','AM','A','M','CCTT','CYT','BCT','CT','C','Y','T','DE','DEL','LA','LAS','EL','LOS',
             'BANDA','MUSICA','MUSICAL','MUNICIPAL','AGRUPACION','ASOCIACION','SOCIEDAD',
             'FILARMONICA','CORNETAS','TAMBORES','NTRA','NUESTRA','SRA','SENORA','STMO','STMA',
             'SANTISIMO','SANTISIMA'}

def band_tokens(s):
    s = strip_accents(s).upper()
    s = re.sub(r'\(.*?\)', ' ', s)   # quita parentesis (localidad aclaratoria)
    s = re.sub(r'["\'\.]', ' ', s)
    s = re.sub(r'[^A-Z0-9 ]+', ' ', s)
    toks = [t for t in s.split() if t and t not in STOPWORDS]
    return frozenset(toks)

# indice: ID_BANDA -> lista de token-sets (uno por NOMBRE_COMPLETO y uno por NOMBRE_BREVE)
banda_tokensets = defaultdict(list)
banda_exact = {}   # tokenset (frozenset) -> set(ID_BANDA) para match exacto rapido
for b in bandas:
    for field in ('NOMBRE_COMPLETO','NOMBRE_BREVE'):
        val = (b.get(field) or '').strip()
        if not val:
            continue
        ts = band_tokens(val)
        if not ts:
            continue
        banda_tokensets[b['ID_BANDA']].append(ts)
        banda_exact.setdefault(ts, set()).add(b['ID_BANDA'])

DOBLETE_RE = re.compile(r'\bida\b|\bvuelta\b|hasta (el |la )?(salvador|catedral|entrada)|desde la (encarnaci|catedral)|/', re.IGNORECASE)

def match_band(raw):
    """Devuelve (id_banda o None, motivo)."""
    if DOBLETE_RE.search(raw):
        return None, 'doblete_dos_bandas'
    q = band_tokens(raw)
    if not q:
        return None, 'texto_vacio_tras_normalizar'
    # 1) match exacto de conjunto de tokens
    ids = banda_exact.get(q)
    if ids and len(ids) == 1:
        return next(iter(ids)), 'match_exacto'
    if ids and len(ids) > 1:
        return None, f'ambiguo_{len(ids)}_bandas_nombre_identico'
    # 2) contencion: los tokens de la fuente son subconjunto de los de la banda (falta localidad/sufijo)
    candidates = set()
    for bid, tslist in banda_tokensets.items():
        for ts in tslist:
            if q <= ts or ts <= q:
                candidates.add(bid)
                break
    if len(candidates) == 1:
        return next(iter(candidates)), 'match_por_contencion'
    if len(candidates) > 1:
        return None, f'ambiguo_{len(candidates)}_bandas_por_contencion'
    return None, 'sin_match'

bandas_no_resueltas = Counter()
bandas_no_resueltas_lines = defaultdict(list)
bandas_no_resueltas_motivo = {}
contratos_ok = []
contratos_dobletes = []
motivo_counter = Counter()

for r in resueltos:
    bid, motivo = match_band(r['banda_raw'])
    motivo_counter[motivo] += 1
    if bid is None:
        bandas_no_resueltas[r['banda_raw']] += 1
        bandas_no_resueltas_lines[r['banda_raw']].append(f"{r['hermandad']} / {r['titular']} / {r['anio']}")
        bandas_no_resueltas_motivo[r['banda_raw']] = motivo
        if motivo == 'doblete_dos_bandas':
            contratos_dobletes.append(r)
        continue
    contratos_ok.append(dict(ID_BANDA=bid, HERMANDAD=r['hermandad'], TITULAR=r['titular'],
                              ANIO=r['anio'], FUENTE='elforocofrade.es - Acompañamiento Musical a lo largo de la Historia',
                              NOTA=f"banda_raw={r['banda_raw']}", LOCALIDAD='Sevilla'))

with open('bandas_no_resueltas.csv','w',newline='',encoding='utf-8') as f:
    w = csv.writer(f)
    w.writerow(['banda_raw','n_filas_afectadas','motivo','ejemplos'])
    for banda_raw, n in bandas_no_resueltas.most_common():
        if bandas_no_resueltas_motivo[banda_raw].startswith('ambiguo'):
            continue
        ejemplos = ' ; '.join(bandas_no_resueltas_lines[banda_raw][:3])
        w.writerow([banda_raw, n, bandas_no_resueltas_motivo[banda_raw], ejemplos])

with open('bandas_ambiguas.csv','w',newline='',encoding='utf-8') as f:
    w = csv.writer(f)
    w.writerow(['banda_raw','n_filas_afectadas','motivo','ids_banda_candidatos','ejemplos'])
    for banda_raw, n in bandas_no_resueltas.most_common():
        motivo = bandas_no_resueltas_motivo[banda_raw]
        if not motivo.startswith('ambiguo'):
            continue
        q = band_tokens(banda_raw)
        ids = sorted(banda_exact.get(q, set()))
        if not ids:
            ids = sorted({bid for bid, tslist in banda_tokensets.items() for ts in tslist if (q <= ts or ts <= q)})
        ejemplos = ' ; '.join(bandas_no_resueltas_lines[banda_raw][:3])
        w.writerow([banda_raw, n, motivo, ','.join(ids), ejemplos])

print("Motivos (todas las filas resueltas de por si, con o sin match):", file=sys.stderr)
for m, c in motivo_counter.most_common():
    print(f"  {c:5d}  {m}", file=sys.stderr)

with open('contratos_dobletes.csv','w',newline='',encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['hermandad','titular','anio','banda_raw','fuentes'])
    w.writeheader(); w.writerows(contratos_dobletes)

with open('contratos_a_cargar.csv','w',newline='',encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['ID_BANDA','HERMANDAD','TITULAR','ANIO','FUENTE','NOTA','LOCALIDAD'])
    w.writeheader()
    for c in contratos_ok:
        w.writerow(c)

print(f"\ncontratos_a_cargar.csv: {len(contratos_ok)} filas con banda resuelta", file=sys.stderr)
print(f"bandas_no_resueltas.csv: {len(bandas_no_resueltas)} nombres de banda distintos sin match ({sum(bandas_no_resueltas.values())} filas afectadas)", file=sys.stderr)
print(f"contratos_dobletes.csv: {len(contratos_dobletes)} filas con doblete de banda (ida/vuelta) no resoluble a 1 sola banda", file=sys.stderr)
