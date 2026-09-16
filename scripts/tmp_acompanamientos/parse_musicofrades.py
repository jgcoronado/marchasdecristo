#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Parsea las páginas de musicofrades.com de acompañamientos musicales de
Sevilla, Málaga y Jerez 2026 (sevilla.html, malaga.html, jerez.html — HTML
descargado tal cual con curl, ver README al final de este fichero) a una
fila por (hermandad, paso) con el tipo de paso y la banda.

A diferencia del programa de Córdoba (parse_cordoba_programa.py), aquí el
HTML es una lista <ul><li> bien anidada por WordPress: un <h3> por día, un
<li><strong>Hermandad</strong> por hermandad, y dentro un <li>Etiqueta:
banda</li> por paso. No hace falta reconstruir el orden de lectura a mano
como con el PDF de Córdoba: BeautifulSoup basta.

Lo que SÍ sigue siendo trabajo humano (Regla 5: el código no adivina):
- La etiqueta de cada paso varía por ciudad y no siempre distingue Cristo
  de Virgen sin ambigüedad ("Trono de Cristo y Virgen" en Málaga, "Paso de
  San Juan" en Jerez...). Los patrones seguros se normalizan a
  cruz_guia/cristo/virgen; cualquier etiqueta que no encaje se marca
  TIPO_NORMALIZADO=dudoso en vez de adivinar, para revisar en el panel de
  /dashboard.
- Resolver el texto de la banda contra la tabla `banda` real.
- Elegir el nombre corto/popular del paso.

Salida: musicofrades_extraido.csv (una fila por hermandad+paso, las tres
ciudades juntas, columna LOCALIDAD para distinguirlas).
"""
import csv
import re
import sys
from bs4 import BeautifulSoup

CIUDADES = [
    ('Sevilla', 'sevilla.html'),
    ('Malaga', 'malaga.html'),
    ('Jerez de la Frontera', 'jerez.html'),
]
OUT = 'musicofrades_extraido.csv'

CRUZ_GUIA_RE = re.compile(r'^cruz de gu[ií]a$', re.IGNORECASE)
CRISTO_RE = re.compile(
    r'^(paso (de|del) (misterio|cristo|nazareno)|trono (del?|de la)? ?cristo)$',
    re.IGNORECASE)
VIRGEN_RE = re.compile(
    r'^(paso de (palio|virgen)|trono (del?|de la)? ?virgen)$',
    re.IGNORECASE)

CCTT_AM_RE = re.compile(
    r'cornetas y tambores|\bcctt\b|\bbct\.?\b|agrupaci[oó]n musical|'
    r'\bam\b|asociaci[oó]n musical', re.IGNORECASE)

SIN_BANDA_RE = re.compile(
    r'^(silencio|capilla musical|escolan[ií]a|sin banda|sin m[uú]sica)\.?$',
    re.IGNORECASE)


def clean(text):
    return re.sub(r'\s+', ' ', text or '').strip()


def normaliza_tipo(etiqueta):
    e = clean(etiqueta)
    if CRUZ_GUIA_RE.match(e):
        return 'cruz_guia'
    if CRISTO_RE.match(e):
        return 'cristo'
    if VIRGEN_RE.match(e):
        return 'virgen'
    return 'dudoso'


def parse_ciudad(localidad, path):
    with open(path, encoding='utf-8') as f:
        soup = BeautifulSoup(f.read(), 'html.parser')

    content = soup.find('div', class_='entry-content')
    if content is None:
        print(f'AVISO [{localidad}]: no se encontro entry-content', file=sys.stderr)
        return []

    rows = []
    dia = None
    dia_orden = 0
    orden_pagina = 0
    for el in content.find_all(['h3', 'ul'], recursive=False):
        if el.name == 'h3':
            nuevo_dia = clean(el.get_text())
            if nuevo_dia:
                dia = nuevo_dia
                dia_orden += 1
                orden_pagina = 0
            continue
        # el.name == 'ul': lista de hermandades de este dia (nivel superior)
        for li in el.find_all('li', recursive=False):
            strong = li.find('strong')
            if strong is None:
                continue
            hermandad = clean(strong.get_text())
            if not hermandad:
                continue
            orden_pagina += 1
            sub_ul = li.find('ul')
            if sub_ul is None:
                rows.append({
                    'LOCALIDAD': localidad, 'DIA': dia, 'DIA_ORDEN': dia_orden,
                    'ORDEN_PAGINA': orden_pagina, 'HERMANDAD': hermandad,
                    'ETIQUETA_RAW': '', 'TIPO_NORMALIZADO': 'dudoso',
                    'BANDA_TEXTO': '', 'BANDA_URL': '', 'ES_CCTT_AM': False,
                })
                continue
            for sub_li in sub_ul.find_all('li', recursive=False):
                a = sub_li.find('a')
                full_text = clean(sub_li.get_text())
                if ':' not in full_text:
                    continue
                etiqueta, _, resto = full_text.partition(':')
                etiqueta = clean(etiqueta)
                banda_texto = clean(a.get_text()) if a else clean(resto).rstrip('.')
                banda_url = a['href'] if a else ''
                es_sin_banda = bool(SIN_BANDA_RE.match(clean(resto).rstrip('.')))
                es_cctt_am = bool(CCTT_AM_RE.search(banda_texto)) and not es_sin_banda
                rows.append({
                    'LOCALIDAD': localidad, 'DIA': dia, 'DIA_ORDEN': dia_orden,
                    'ORDEN_PAGINA': orden_pagina, 'HERMANDAD': hermandad,
                    'ETIQUETA_RAW': etiqueta,
                    'TIPO_NORMALIZADO': normaliza_tipo(etiqueta),
                    'BANDA_TEXTO': banda_texto if not es_sin_banda else clean(resto).rstrip('.'),
                    'BANDA_URL': banda_url, 'ES_CCTT_AM': es_cctt_am,
                })
    return rows


def main():
    all_rows = []
    for localidad, path in CIUDADES:
        try:
            rows = parse_ciudad(localidad, path)
        except FileNotFoundError:
            print(f'AVISO: falta {path}, se salta {localidad}', file=sys.stderr)
            continue
        print(f'{localidad}: {len(rows)} filas '
              f'({len({r["HERMANDAD"] for r in rows})} hermandades)', file=sys.stderr)
        all_rows.extend(rows)

    fieldnames = ['LOCALIDAD', 'DIA', 'DIA_ORDEN', 'ORDEN_PAGINA', 'HERMANDAD',
                  'ETIQUETA_RAW', 'TIPO_NORMALIZADO', 'BANDA_TEXTO',
                  'BANDA_URL', 'ES_CCTT_AM']
    with open(OUT, 'w', newline='', encoding='utf-8') as f:
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        for r in all_rows:
            w.writerow(r)

    dudosos = [r for r in all_rows if r['TIPO_NORMALIZADO'] == 'dudoso']
    print(f'\nTotal: {len(all_rows)} filas -> {OUT}', file=sys.stderr)
    if dudosos:
        etiquetas = sorted({(r['LOCALIDAD'], r['ETIQUETA_RAW']) for r in dudosos})
        print(f'AVISO - {len(dudosos)} filas con TIPO_NORMALIZADO=dudoso '
              '(revisar en el panel de dashboard), etiquetas distintas:',
              file=sys.stderr)
        for loc, et in etiquetas:
            print(f'  [{loc}] {et!r}', file=sys.stderr)


if __name__ == '__main__':
    main()

# --- Como reproducir la descarga (no forma parte del pipeline, solo referencia) ---
# curl -s -o sevilla.html "https://musicofrades.com/acompanamientos-musicales-de-la-semana-santa-de-sevilla-2026/"
# curl -s -o malaga.html  "https://musicofrades.com/acompanamientos-musicales-de-la-semana-santa-de-malaga-2026/"
# curl -s -o jerez.html   "https://musicofrades.com/acompanamientos-musicales-de-la-semana-santa-de-jerez-de-la-frontera-2026/"
