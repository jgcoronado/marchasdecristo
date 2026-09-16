#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Parsea el programa oficial de la Agrupación de Hermandades y Cofradías de
Córdoba 2026 (cordoba_programa_2026_raw.txt, extraído del PDF con
`pdftotext -enc UTF-8 -raw`) para sacar, por hermandad: día, horas de
salida/C.O./entrada y el texto de acompañamientos musicales.

Por qué hace falta este script y no basta con leer el .txt a ojo: dentro de
cada ficha de hermandad el PDF apila varios cuadros de texto (túnica,
acompañamientos musicales, estrenos, curiosidades) sin separador, y el
extractor de texto no siempre respeta el orden visual de esos cuadros
(cambia de una hermandad a otra según cómo esté maquetada la página). Lo
único estable es que las etiquetas de sección ("ACOMPAÑAMIENTOS MUSICALES",
"ESTRENOS", "CURIOSIDADES") aparecen SIEMPRE después de su contenido, nunca
antes.

Lo que este script NO hace (queda para revisión manual, ver
docs/acompanamientos-nomina-2026.md): no separa el paso de Cristo del de
palio, no resuelve el nombre de banda contra la tabla `banda`, y no decide
el nombre corto/popular del paso. La redacción es demasiado variable
("en el paso de X la BANDA", "tras el paso de X la BANDA", "Al Señor lo
acompaña la BANDA", "BANDA para el Misterio") para forzar eso con regex sin
arriesgar silenciar errores (Regla 12: mejor exponer la incertidumbre).

Salida: cordoba_extraido.csv, una fila por hermandad, para revisar antes de
poblar `hermandad`/`paso`/`contrato_paso`.
"""
import csv
import re
import sys

SRC = "cordoba_programa_2026_raw.txt"
OUT = "cordoba_extraido.csv"

# Tokens tal y como los deja pdftotext (sin espacios, con mayúsculas y tildes).
DAY_TOKENS = [
    ("SÁBADODEPASIÓN", "Sabado de Pasion"),
    ("DOMINGODERAMOS", "Domingo de Ramos"),
    ("LUNESSANTO", "Lunes Santo"),
    ("MARTESSANTO", "Martes Santo"),
    ("MIÉRCOLESSANTO", "Miercoles Santo"),
    ("JUEVESSANTO", "Jueves Santo"),
    ("VIERNESSANTO", "Viernes Santo"),
    ("DOMINGODERESURRECCIÓN", "Domingo de Resurreccion"),
]
DAY_TOKEN_SET = {t for t, _ in DAY_TOKENS}

# A partir de aquí el programa pasa a "Glorias de Córdoba" (cultos fuera de
# Semana Santa): fuera de alcance por completo, ver decisiones del proyecto.
STOP_TOKEN = "GLORIASDECÓRDOBA"

LOCATION_RE = re.compile(
    r'^(Parroquia|Iglesia|Convento|Bas[íi]lica|Real (Iglesia|Colegiata)|'
    r'Santuario|Ermita)\b', re.IGNORECASE)

SALIDA_RE = re.compile(r'Salida:\s*(\d{1,2}:\d{2})')
CO_RE = re.compile(r'C\.O\.:?\s*(\d{1,2}:\d{2})')
ENTRADA_RE = re.compile(r'Entrada:?\s*(\d{1,2}:\d{2})')

# Deliberadamente específico (no "banda"/"coro"/"capilla" a secas) para no
# tragarse frases de estrenos que mencionen una hermandad o virgen homónima.
BAND_KEYWORDS_RE = re.compile(
    r'banda de (m[uú]sica|cornetas|cctt|cc\s*y\s*tt)|agrupaci[oó]n musical|'
    r'\bcctt\b|\bcc\s*y\s*tt\b|\bbct\.?\b|cornetas y tambores|coro de herman|'
    r'capilla musical|trio de capilla|coral polif|riguroso silencio|'
    r'tambores roncos|asociaci[oó]n musical|unidad de m[uú]sica|'
    r'banda municipal|banda sinf[oó]nica|sociedad filarm[oó]nica|'
    r'filarm[oó]nica',
    re.IGNORECASE)

CCTT_AM_RE = re.compile(
    r'\bcctt\b|\bcc\s*y\s*tt\b|\bbct\.?\b|cornetas y tambores|'
    r'agrupaci[oó]n musical|asociaci[oó]n musical|\bam\b',
    re.IGNORECASE)

# Líneas que no pueden ser el nombre de una hermandad (cabeceras de sección,
# metadatos de ficha, etc.) aunque la línea siguiente parezca una ubicación.
NOISE_NAME_RE = re.compile(
    r'^(Escanea el QR|Consiliario|Hermano mayor|ACOMPAÑAMIENTOS|ESTRENOS|'
    r'CURIOSIDADES|CUADRO HORARIO|Salida:|C\.O\.:?\s*\d|Entrada:|'
    r'N[ºo°]?\.? de|\d+ Pasos?\b|RECORRIDO|HORA\b|\d+$)', re.IGNORECASE)

ABREVIATURAS = ['D.', 'Dña.', 'Sr.', 'Sra.', 'Srta.', 'Rvdo.', 'Ilmo.',
                'Ntra.', 'Sta.', 'Stmo.', 'Sto.', 'Av.', 'etc.']

# Limpieza cosmética: la línea de horario, cuando queda pegada al texto de
# música porque no la separa un punto, y los pies de foto del QR.
NOISE_INLINE_RE = re.compile(
    r'Salida:\s*\d{1,2}:\d{2}(\s*C\.O\.:?\s*\d{1,2}:\d{2})?'
    r'(\s*Entrada:?\s*\d{1,2}:\d{2})?|'
    r'Escanea el QR para conocer '
    r'(todos los detalles de la hermandad|el recorrido y el itinerario completo)',
    re.IGNORECASE)


def read_lines(path):
    with open(path, encoding='utf-8') as f:
        return [ln.rstrip('\n') for ln in f]


def split_sentences(text):
    protected = text
    for abbr in ABREVIATURAS:
        protected = protected.replace(abbr, abbr[:-1] + '')
    parts = re.split(r'(?<=[.!?])\s+(?=[A-ZÁÉÍÓÚÑ0-9])', protected)
    return [p.replace('', '.').strip() for p in parts if p.strip()]


ACOMP_LABEL = 'ACOMPAÑAMIENTOS MUSICALES'
OTHER_LABELS_RE = re.compile(r'\b(ESTRENOS|CURIOSIDADES)\b')


def extract_acompanamiento(block_text):
    """Aísla las frases que hablan de música (banda, agrupación, coro,
    silencio...) en la ficha. En la mayoría de hermandades el contenido de
    música va ANTES de la etiqueta "ACOMPAÑAMIENTOS MUSICALES" (todos los
    cuadros de la ficha se leen seguidos y las etiquetas de sección
    aparecen juntas al final), pero en varias fichas de Viernes Santo
    (Sepulcro, Expiración, Conversión) el cuadro de música está maquetado
    de forma que su texto sale DESPUÉS de las etiquetas. Por eso se prueba
    primero antes de la etiqueta y, si no hay nada que suene a música, se
    prueba después.
    """
    label_pos = block_text.find(ACOMP_LABEL)
    if label_pos == -1:
        return None, ''

    before = ' '.join(block_text[:label_pos].split())
    # Ancla más específica que "completo" a secas: esa palabra suelta
    # reaparece en frases de estrenos ("Dorado completo del paso...") y
    # cortaba el contenido en el sitio equivocado.
    anchor = before.rfind('itinerario completo')
    if anchor != -1:
        before = before[anchor + len('itinerario completo'):]
    else:
        qr_pos = before.rfind('completo')
        if qr_pos != -1:
            before = before[qr_pos + len('completo'):]
    before = NOISE_INLINE_RE.sub(' ', before)
    before = ' '.join(before.split())

    music = []
    if before:
        music = [s for s in split_sentences(before) if BAND_KEYWORDS_RE.search(s)]

    contexto = before
    if not music:
        after = block_text[label_pos + len(ACOMP_LABEL):]
        after = OTHER_LABELS_RE.sub(' ', after)
        after = NOISE_INLINE_RE.sub(' ', after)
        after = ' '.join(after.split())
        if after:
            contexto = (before + ' [DESPUES DE LA ETIQUETA] ' + after).strip()
            music = [s for s in split_sentences(after) if BAND_KEYWORDS_RE.search(s)]

    return (' '.join(music) if music else None), contexto


def looks_like_ficha_start(lines, i):
    if i + 1 >= len(lines):
        return False
    name = lines[i].strip()
    nxt = lines[i + 1].strip()
    if not name or len(name) >= 60 or NOISE_NAME_RE.match(name):
        return False
    return bool(LOCATION_RE.match(nxt))


def main():
    lines = read_lines(SRC)
    n = len(lines)
    day_token_map = dict(DAY_TOKENS)
    state = {'current_day': None, 'day_order': {}, 'next_day_order': 1}

    def note_day(raw):
        """Actualiza el día si la línea es una cabecera de día. Esas
        cabeceras se repiten como encabezado de página DENTRO de una misma
        ficha por un salto de página (p. ej. Expiración, Sepulcro,
        Conversión en Viernes Santo), así que nunca deben cortar un bloque
        en marcha: solo se anota el día y se sigue leyendo.
        """
        compact = raw.replace(' ', '')
        if compact in day_token_map:
            state['current_day'] = day_token_map[compact]
            if state['current_day'] not in state['day_order']:
                state['day_order'][state['current_day']] = state['next_day_order']
                state['next_day_order'] += 1
            return True
        return False

    records = []
    i = 0
    while i < n:
        raw = lines[i].strip()
        compact = raw.replace(' ', '')
        if STOP_TOKEN in compact:
            break
        if note_day(raw):
            i += 1
            continue

        if looks_like_ficha_start(lines, i):
            name = raw
            location = lines[i + 1].strip()
            j = i + 2
            block_lines = []
            while j < n:
                nxt_raw = lines[j].strip()
                nxt_compact = nxt_raw.replace(' ', '')
                if STOP_TOKEN in nxt_compact:
                    break
                if nxt_raw.startswith('CUADRO HORARIO'):
                    break
                if note_day(nxt_raw):
                    j += 1
                    continue
                if looks_like_ficha_start(lines, j):
                    break
                block_lines.append(nxt_raw)
                j += 1
            block_text = '\n'.join(block_lines)
            # La última línea no vacía del bloque, si es "Salida: ...", es
            # casi siempre la ficha de la SIGUIENTE hermandad (que va justo
            # antes de su nombre y por tanto queda pegada al final de este
            # bloque). Se descarta para no colarle a esta hermandad, p. ej.,
            # el C.O. de la siguiente cuando esta no tiene (vísperas).
            body_lines = list(block_lines)
            for idx in range(len(body_lines) - 1, -1, -1):
                if body_lines[idx].strip():
                    if body_lines[idx].strip().startswith('Salida:'):
                        body_lines = body_lines[:idx]
                    break
            joined = ' '.join(' '.join(body_lines).split())
            # La línea "Salida: HH:MM C.O.: HH:MM Entrada: HH:MM" a veces va
            # justo ANTES del nombre en vez de dentro de la ficha (p. ej.
            # Vera Cruz, Esperanza, Amor): se busca también ahí para no
            # perderla ni atribuírsela a la hermandad anterior.
            prev_line = lines[i - 1].strip() if i > 0 else ''
            joined_for_times = prev_line + ' ' + joined
            salida = SALIDA_RE.search(joined_for_times)
            co = CO_RE.search(joined_for_times)
            entrada = ENTRADA_RE.search(joined_for_times)
            acomp, contexto = extract_acompanamiento(block_text)
            records.append({
                'DIA': state['current_day'],
                'DIA_ORDEN': state['day_order'].get(state['current_day'], ''),
                'NOMBRE': name,
                'LOCALIZACION': location,
                'HORA_SALIDA': salida.group(1) if salida else '',
                'HORA_CO': co.group(1) if co else '',
                'HORA_ENTRADA': entrada.group(1) if entrada else '',
                'MENCIONA_CCTT_AM': bool(acomp and CCTT_AM_RE.search(acomp)),
                'ACOMPANAMIENTO_RAW': acomp or '',
                'CONTEXTO_FICHA': contexto,
            })
            i = j
            continue
        i += 1

    def sort_key(hhmm):
        h, m = map(int, hhmm.split(':'))
        if h < 6:  # madrugada: va despues de la tarde/noche del mismo dia
            h += 24
        return h * 60 + m

    by_day = {}
    for r in records:
        by_day.setdefault(r['DIA'], []).append(r)
    for rows in by_day.values():
        con_co = sorted((r for r in rows if r['HORA_CO']),
                         key=lambda r: sort_key(r['HORA_CO']))
        for idx, r in enumerate(con_co, start=1):
            r['ORDEN_POR_CO'] = idx
        for r in rows:
            r.setdefault('ORDEN_POR_CO', '')

    fieldnames = ['DIA', 'DIA_ORDEN', 'ORDEN_POR_CO', 'NOMBRE', 'LOCALIZACION',
                  'HORA_SALIDA', 'HORA_CO', 'HORA_ENTRADA', 'MENCIONA_CCTT_AM',
                  'ACOMPANAMIENTO_RAW', 'CONTEXTO_FICHA']
    with open(OUT, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        for r in records:
            w.writerow(r)

    print(f"{len(records)} hermandades extraidas -> {OUT}", file=sys.stderr)
    sin_musica = [r['NOMBRE'] for r in records if not r['ACOMPANAMIENTO_RAW']]
    if sin_musica:
        print("AVISO - sin seccion de musica detectada (revisar a mano):",
              sin_musica, file=sys.stderr)
    sin_dia = [r['NOMBRE'] for r in records if not r['DIA']]
    if sin_dia:
        print("AVISO - sin dia asignado (revisar a mano):", sin_dia,
              file=sys.stderr)


if __name__ == '__main__':
    main()
