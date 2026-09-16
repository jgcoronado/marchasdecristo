#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Extrae acompañamientos musicales (hermandad, paso, año, banda) de los
volcados de texto del foro (page28.txt, page37.txt, page38.txt) y los
cruza con la banda de datos MDC (banda_dump.csv, contrato_dump.csv).

Salidas (en el mismo directorio):
  - extraidos_raw.csv         : todo lo que se ha podido parsear (auditoria)
  - conflictos.csv            : mismo (hermandad,paso,anio) con bandas distintas entre fuentes -> requieren decision humana
  - contratos_a_cargar.csv    : listos para seed_contratos_2026.php (ID_BANDA resuelto, sin conflicto, no duplican 2026 ya cargado)
  - bandas_no_resueltas.csv   : nombres de banda que no se han podido casar con la tabla banda (agrupados)
"""
import csv, re, sys, unicodedata
from collections import defaultdict

BASE = "."

DIAS = {
    "VIERNES DE DOLORES Y SABADO DE PASION": "Viernes de Dolores y Sabado de Pasion",
    "VIERNES DE DOLORES": "Viernes de Dolores",
    "SABADO DE PASION": "Sabado de Pasion",
    "DOMINGO DE RAMOS": "Domingo de Ramos",
    "LUNES SANTO": "Lunes Santo",
    "MARTES SANTO": "Martes Santo",
    "MIERCOLES SANTO": "Miercoles Santo",
    "JUEVES SANTO": "Jueves Santo",
    "MADRUGA": "Madruga",
    "VIERNES SANTO (TARDE)": "Viernes Santo",
    "VIERNES SANTO": "Viernes Santo",
    "SABADO SANTO Y DOMINGO DE RESURRECCION": "Sabado Santo y Domingo de Resurreccion",
    "SABADO SANTO Y DOMINGO DE RESURRECCION\n(INCOMPLETO)": "Sabado Santo y Domingo de Resurreccion",
}

def strip_accents(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')

def norm_key(s):
    s = strip_accents(s).upper()
    s = re.sub(r'[^A-Z0-9 ]+', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s

BOILERPLATE_PATTERNS = [
    r'^Se incorpor', r'^Mensajes:', r'^Me gusta recibidos:', r'^Puntos de trofeos:',
    r'^Localizaci', r'^Ocupaci', r'^P.gina web:', r'^#\d+$', r'^A .* les? gusta esto',
    r'dijo: .$', r'^Haz clic para expandir', r'^.ltima modificaci',
    r'^P.gina \d+ de \d+$', r'^< Prev$', r'^Siguiente >', r'^Comparte esta p.gina$',
    r'^Portal$', r'^Foros$', r'^Tu espacio cofrade$', r'^Pentagrama Cofrade$',
    r'^Tema en ', r'^\d+$', r'^.$', r'^Tweet$', r'^\(Debes conectarte',
    r'^costalero_prado, ', r'^josemariumb, ', r'^CreoenJes.s, ', r'^pentagramaenfa, ',
    r'^arsielo con ella, ', r'^con_humildad_al_cielo, ', r'^Cavra, ', r'^cofradedegarganta, ',
    r'^SevillaSanta, ', r'^dsanroque, ', r'^a_jierro, ', r'^COR-AGRU, ', r'^endocare, ',
    r'^Luna de Parasceve, ', r'^Musicofrades, ', r'^san_pedro, ', r'^Gorri, ', r'^Albay, ',
    r'^silencio\.blanco_cofrade, ', r'^Prendimiento de Oro, ',
]
BOILERPLATE_RE = re.compile('|'.join(BOILERPLATE_PATTERNS), re.IGNORECASE)

def strip_quotes(text):
    """Elimina bloques citados 'X dijo: ...' hasta 'Haz clic para expandir...' (incluido)."""
    lines = text.split('\n')
    out = []
    in_quote = False
    for ln in lines:
        if re.search(r'dijo: .$', ln.strip()):
            in_quote = True
            continue
        if in_quote:
            if 'Haz clic para expandir' in ln:
                in_quote = False
            continue
        out.append(ln)
    return '\n'.join(out)

def clean_lines(raw):
    raw = strip_quotes(raw)
    lines = []
    for ln in raw.split('\n'):
        ln = ln.strip()
        if not ln:
            continue
        if BOILERPLATE_RE.search(ln):
            continue
        lines.append(ln)
    return lines

YEAR_RANGE_RE = re.compile(r'^\*?\s*(\d{4})\s*[-–—]\s*(\d{4})\s*[:\-]?\s+(\S.*)$')
YEAR_SINGLE_RE = re.compile(r'^\*?\s*(\d{4})\s*[:\-]?\s+(\S.*)$')

def is_hermandad_header(ln):
    if re.search(r'\d', ln):
        return False
    if len(ln) > 60:
        return False
    letters = re.sub(r'[^A-Za-zÀ-ÿ]', '', ln)
    if not letters:
        return False
    if not letters.isupper():
        return False
    if ln.upper().startswith('PASO'):
        return False
    if norm_key(ln) in DIAS:
        return False
    return True

def is_paso_header(ln):
    return ln.strip().upper().startswith('PASO')

def is_dia_header(ln):
    return norm_key(ln) in DIAS

records = []  # dict: source,dia,hermandad,paso,anio,banda_raw

def parse_file(fname, source_label):
    with open(fname, encoding='utf-8') as f:
        raw = f.read()
    lines = clean_lines(raw)
    dia = None
    hermandad = None
    paso = ''
    for ln in lines:
        if is_dia_header(ln):
            dia = DIAS[norm_key(ln)]
            hermandad = None
            paso = ''
            continue
        if is_hermandad_header(ln):
            hermandad = ln.strip().rstrip(':')
            paso = ''
            continue
        if is_paso_header(ln):
            paso = ln.strip().rstrip(':')
            continue
        if hermandad is None:
            continue
        m = YEAR_RANGE_RE.match(ln)
        if m:
            y1, y2, txt = int(m.group(1)), int(m.group(2)), m.group(3).strip()
            for y in range(y1, y2 + 1):
                records.append(dict(source=source_label, dia=dia, hermandad=hermandad,
                                     paso=paso, anio=y, banda_raw=txt, orig_line=ln))
            continue
        m = YEAR_SINGLE_RE.match(ln)
        if m:
            y1, txt = int(m.group(1)), m.group(2).strip()
            records.append(dict(source=source_label, dia=dia, hermandad=hermandad,
                                 paso=paso, anio=y1, banda_raw=txt, orig_line=ln))
            continue
        # linea sin año al inicio dentro de un bloque de paso: se ignora (no se puede fechar)

for fn, lab in [(f"{BASE}/page28_clean.txt", "foro-2019-josemariumb"),
                (f"{BASE}/page37.txt", "foro-2026-costalero_prado-visperas"),
                (f"{BASE}/page38.txt", "foro-2026-costalero_prado-carrera")]:
    parse_file(fn, lab)

print(f"Registros parseados (antes de filtrar): {len(records)}", file=sys.stderr)

NO_BANDA_MARK = re.compile(r'sin m.sica|sin banda|en silencio|m.sica de capilla|capilla musical|no llev.|no hizo estaci.n', re.IGNORECASE)

filtered = []
sin_banda = []
for r in records:
    if r['anio'] < 1980:
        continue
    if NO_BANDA_MARK.search(r['banda_raw']):
        sin_banda.append(r)
        continue
    filtered.append(r)

print(f"Registros con banda, anio>=1980: {len(filtered)}", file=sys.stderr)
print(f"Registros 'sin banda' (capilla/silencio) descartados: {len(sin_banda)}", file=sys.stderr)

with open(f"{BASE}/extraidos_raw.csv", 'w', newline='', encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['source','dia','hermandad','paso','anio','banda_raw','orig_line'])
    w.writeheader()
    for r in filtered:
        w.writerow(r)

with open(f"{BASE}/sin_banda.csv", 'w', newline='', encoding='utf-8') as f:
    w = csv.DictWriter(f, fieldnames=['source','dia','hermandad','paso','anio','banda_raw','orig_line'])
    w.writeheader()
    for r in sin_banda:
        w.writerow(r)

print("OK: extraidos_raw.csv y sin_banda.csv escritos", file=sys.stderr)
