#!/usr/bin/env python3
"""
Descubridor de marchas que faltan en la BD, a partir del catálogo de streaming
de cada banda (Spotify / Deezer / Apple Music).

Idea: las bandas con perfil de artista ya enlazado en `enlace_streaming`
(TIPO_ENT='banda') tienen ahí publicada su discografía entera. Recorriendo sus
discos y las pistas de cada disco salen todos los títulos que esa banda ha
grabado; los que no están en `marcha` son marchas que le faltan a la BD.

Salida: candidatos en el mismo formato NDJSON que consume
`php/app/tools/import_candidatos.php`, con FUENTE = el servicio de origen (no
YouTube), más un informe legible por banda.

    python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db
    python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --solo 16,10 --dry-run
    python3 tools/music_links/descubrir_marchas.py --db php/data/mdc.db --sin-apple  # pasada rápida

Fuentes: Spotify, Deezer y Apple Music. **YouTube no se usa** ni para elegir
bandas ni como catálogo, aunque la banda lo tenga enlazado: un canal mezcla
marchas con conciertos, ensayos y vídeos que no son música procesional.

Credenciales: Deezer y Apple no necesitan ninguna. Spotify usa
SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET del .env (mismo que el resto de
tools/music_links); sin ellas el script sigue funcionando con las demás
fuentes y lo avisa.

Lo que NO hace: escribir en la BD. Genera el NDJSON y el informe; el alta la
decide el revisor en /dashboard/ingesta tras importar.
"""

import argparse
import base64
import csv
import difflib
import json
import os
import re
import sqlite3
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict

RAIZ = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Servicios que sirven como origen de un candidato, en orden de preferencia:
# si una misma pista aparece en varios, el candidato se queda con el primero de
# esta lista (es el enlace que acabará publicado en la ficha de la marcha).
#
# YouTube queda FUERA a propósito, aunque muchas bandas lo tengan enlazado en
# `enlace_streaming`: un canal mezcla marchas con conciertos, ensayos, vídeos
# de hermandad y contenido que no es música procesional, así que como catálogo
# ensucia más de lo que aporta. El descubrimiento se hace solo sobre catálogos
# de discos. (El pipeline de YouTube sigue existiendo aparte, en tools/ingest/,
# si alguna vez hace falta.)
#
# Tidal y Amazon tampoco están: no tienen API pública de catálogo utilizable
# (ver docs/plan-music-apps.md §3).
PRIORIDAD_FUENTE = ['spotify', 'deezer', 'apple']

# Servicios cuyo perfil de artista, si la banda lo tiene enlazado, sirve para
# entrar en el barrido. Mismo criterio que PRIORIDAD_FUENTE: catálogos de
# discos, nunca YouTube.
SERVICIOS_CATALOGO = ('spotify', 'deezer', 'apple')

# Títulos que no son marchas: ruido habitual de los discos de estas bandas.
# Incluye los tramos de un disco en directo, que se titulan por el sitio del
# recorrido ("Jesús Despojado llegando al Postigo 2025") y no por la marcha.
RUIDO = re.compile(
    r'\b(intro|introduccion|obertura|presentacion del disco|entrevista|locucion|narracion|pregon|'
    r'saeta|campanas|tambor de|toque de|llamada de|ensayo|making of|bonus track|'
    r'himno nacional|marcha real|villancic|'
    r'llegando a|saliendo de|entrando en|por la calle|en la plaza|revira|levanta)'
)

# Discos que no son de música procesional: Navidad, cabalgata de Reyes,
# carnaval… Las bandas los graban igual, pero sus pistas no son marchas y no
# deben proponerse. Ojo con "Reyes": muchas bandas se llaman "Virgen de los
# Reyes", así que solo cuenta "reyes magos" / "cabalgata".
ALBUM_NO_PROCESIONAL = re.compile(
    r'\b(navidad|navideñ|villancic|zambomba|cabalgata|reyes magos|carnaval)', re.I
)

# Discos o pistas grabadas en directo: conciertos y, sobre todo, la formación
# tocando en la calle durante el recorrido de una salida procesional. El
# título de estas grabaciones (del disco o de la pista) no es representativo
# de una marcha nueva —viene del evento, no de la pieza—, así que se descarta
# el origen entero en vez de limpiarlo y darlo por bueno.
EN_DIRECTO = re.compile(r'\b(en directo|directo|en vivo|live)\b', re.I)

# Pistas que encadenan varias piezas ("X, Marcha Real y Z", "Popurrí de…"):
# no son el título de una marcha suelta, así que se marcan para revisión.
POPURRI = re.compile(r'(popurr|,[^,]+\sy\s)', re.I)

# Palabras vacías al comparar títulos: no aportan identidad a la marcha.
STOP_TITULO = {'de', 'la', 'el', 'los', 'las', 'y', 'del', 'en', 'a', 'al', 'marcha'}


# ── utilidades ───────────────────────────────────────────────────────────────

def norm(s):
    """Minúsculas sin acentos ni signos: la forma en la que se comparan títulos."""
    if not s:
        return ''
    s = unicodedata.normalize('NFKD', str(s)).encode('ascii', 'ignore').decode('ascii').lower()
    s = re.sub(r'[^a-z0-9 ]', ' ', s)
    return re.sub(r'\s+', ' ', s).strip()


def limpiar_titulo(t):
    """
    Quita del título de la pista lo que es del disco, no de la marcha:
    sufijos entre paréntesis del tipo "(En directo)", "- Remasterizado",
    numeraciones "01. " y el prefijo "Marcha ".
    """
    t = (t or '').strip()
    t = re.sub(r'^\s*\d{1,2}\s*[.\-–)]\s*', '', t)
    t = re.sub(
        r'\s*[\(\[][^)\]]*(directo|en vivo|live|remaster|remasteriz|bonus|version|versión|instrumental|edit|'
        r'estreno|with |feat|ft\.|junto a)[^)\]]*[\)\]]\s*',
        ' ', t, flags=re.I)
    t = re.sub(r'\s+[-–]\s+(remaster|remasteriz|en\s+directo|directo|live)\b[^-–]*$', '', t, flags=re.I)
    # Muchos discos en directo cuelgan el evento del título con una barra
    # vertical o una "I" suelta: "Eterna Expiración I Concierto Moriles 2026".
    t = re.sub(r'\s+[|I]\s+.*$', '', t)
    t = re.sub(r'^\s*marcha\s+(procesional\s+)?', '', t, flags=re.I)
    return re.sub(r'\s+', ' ', t).strip()


def partes_de_titulo(t):
    """
    Trocea un título que encadena varias piezas ("A / B", "A - B"): en los
    discos en directo una sola pista recoge varias marchas ya conocidas, y hay
    que poder cotejar cada parte por separado para no darlas por nuevas.
    """
    partes = re.split(r'\s*[/·]\s*|\s+[-–]\s+', t)
    return [p.strip() for p in partes if len(p.strip()) >= 4]


def anio_de(v):
    m = re.search(r'(19|20)\d{2}', str(v or ''))
    return int(m.group(0)) if m else None


def tokens(s):
    return {t for t in norm(s).split() if t and t not in STOP_TITULO}


def similitud(a, b):
    return difflib.SequenceMatcher(None, norm(a), norm(b)).ratio()


def cargar_env(ruta):
    env = {}
    if ruta and os.path.exists(ruta):
        for linea in open(ruta, encoding='utf-8'):
            linea = linea.strip()
            if not linea or linea.startswith('#') or '=' not in linea:
                continue
            k, v = linea.split('=', 1)
            env[k.strip()] = v.strip().strip('\'"')
    return env


def conectar(db):
    con = sqlite3.connect(db, timeout=30)
    # La BD de producción viene de una importación de MySQL y no es homogénea:
    # casi todo es UTF-8, pero hay filas antiguas en latin-1 que harían fallar
    # el decode estricto. Se intenta UTF-8 y se cae a latin-1 fila a fila.
    def texto(b):
        try:
            return b.decode('utf-8')
        except UnicodeDecodeError:
            return b.decode('latin-1')
    con.text_factory = texto
    con.execute('PRAGMA busy_timeout = 30000')
    return con


# ── HTTP con caché en disco y reintentos ─────────────────────────────────────

class Http:
    """
    GET de JSON con caché en disco y backoff. La caché es lo que hace barato
    repetir la pasada: iTunes y Deezer devuelven lo mismo un rato después, y
    así una segunda ejecución no vuelve a pedir nada.
    """

    def __init__(self, dir_cache, sleep=0.25, verbose=True):
        self.dir_cache = dir_cache
        self.sleep = sleep
        self.verbose = verbose
        os.makedirs(dir_cache, exist_ok=True)

    def _ruta(self, url):
        clave = re.sub(r'[^A-Za-z0-9]+', '_', url)[:120]
        import hashlib
        return os.path.join(self.dir_cache, f'{clave}_{hashlib.sha1(url.encode()).hexdigest()[:10]}.json')

    def get(self, url, headers=None, reintentos=4, usar_cache=True):
        ruta = self._ruta(url)
        if usar_cache and os.path.exists(ruta):
            try:
                return json.load(open(ruta, encoding='utf-8'))
            except Exception:
                pass

        espera = 2.0
        for intento in range(reintentos):
            try:
                req = urllib.request.Request(url, headers=headers or {'User-Agent': 'Mozilla/5.0'})
                datos = json.load(urllib.request.urlopen(req, timeout=30))
                if usar_cache:
                    json.dump(datos, open(ruta, 'w', encoding='utf-8'), ensure_ascii=False)
                time.sleep(self.sleep)
                return datos
            except urllib.error.HTTPError as e:
                if e.code in (429, 403, 500, 502, 503) and intento < reintentos - 1:
                    ra = e.headers.get('Retry-After')
                    pausa = float(ra) if (ra and str(ra).isdigit()) else espera
                    if self.verbose:
                        print(f'    [{e.code}] espera {pausa:.0f}s', file=sys.stderr)
                    time.sleep(pausa)
                    espera *= 2
                    continue
                return None
            except Exception:
                if intento < reintentos - 1:
                    time.sleep(espera)
                    espera *= 2
                    continue
                return None
        return None


# ── catálogos por servicio ───────────────────────────────────────────────────

def id_de_url(url, patron):
    m = re.search(patron, url or '')
    return m.group(1) if m else None


def deezer_catalogo(http, artista_id):
    """[(album, tracks)] del artista en Deezer. Sin credenciales."""
    salida = []
    url = f'https://api.deezer.com/artist/{artista_id}/albums?limit=100'
    while url:
        d = http.get(url)
        if not d:
            break
        for a in d.get('data', []):
            det = http.get(f"https://api.deezer.com/album/{a.get('id')}")
            if not det:
                continue
            album = {
                'servicio': 'deezer',
                'titulo': det.get('title') or a.get('title'),
                'url': det.get('link') or a.get('link'),
                'anio': anio_de(det.get('release_date')),
                'id': str(det.get('id')),
            }
            pistas = []
            for t in (det.get('tracks') or {}).get('data', []):
                pistas.append({
                    'servicio': 'deezer',
                    'titulo': t.get('title_short') or t.get('title'),
                    'url': t.get('link') or f"https://www.deezer.com/track/{t.get('id')}",
                    'id': f"deezer:track:{t.get('id')}",
                    'duracion': t.get('duration'),
                    'autores': None,   # Deezer no expone compositor, solo intérprete
                    # Deezer devuelve el ISRC directo en el objeto de pista, sin
                    # llamada adicional (a diferencia de Spotify).
                    'isrc': t.get('isrc') or None,
                })
            salida.append((album, pistas))
        url = d.get('next')
    return salida


def itunes_catalogo(http, artista_id):
    """[(album, tracks)] del artista en Apple Music/iTunes. Sin credenciales, pero con rate-limit agresivo."""
    salida = []
    d = http.get(f'https://itunes.apple.com/lookup?id={artista_id}&entity=album&limit=200&country=ES')
    if not d:
        return salida
    for a in d.get('results', []):
        if a.get('wrapperType') != 'collection':
            continue
        det = http.get(f"https://itunes.apple.com/lookup?id={a.get('collectionId')}&entity=song&limit=200&country=ES")
        if not det:
            continue
        album = {
            'servicio': 'apple',
            'titulo': a.get('collectionName'),
            'url': a.get('collectionViewUrl'),
            'anio': anio_de(a.get('releaseDate')),
            'id': str(a.get('collectionId')),
        }
        pistas = []
        for t in det.get('results', []):
            if t.get('wrapperType') != 'track':
                continue
            pistas.append({
                'servicio': 'apple',
                'titulo': t.get('trackName'),
                'url': t.get('trackViewUrl'),
                'id': f"apple:track:{t.get('trackId')}",
                'duracion': int((t.get('trackTimeMillis') or 0) / 1000) or None,
                # iTunes sí trae compositor en algunas fichas: es la única
                # fuente de las tres que puede rellenar el autor solo.
                'autores': t.get('composerName') or None,
                # El lookup público de iTunes no expone ISRC (a diferencia de
                # Spotify/Deezer): no hay forma gratis de conseguirlo aquí.
                'isrc': None,
            })
        salida.append((album, pistas))
    return salida


class Spotify:
    def __init__(self, http, env):
        self.http = http
        self.env = env
        self.token = None
        self.activo = bool(env.get('SPOTIFY_CLIENT_ID') and env.get('SPOTIFY_CLIENT_SECRET'))

    def _token(self):
        if self.token or not self.activo:
            return self.token
        datos = urllib.parse.urlencode({'grant_type': 'client_credentials'}).encode()
        cred = base64.b64encode(
            f"{self.env['SPOTIFY_CLIENT_ID']}:{self.env['SPOTIFY_CLIENT_SECRET']}".encode()).decode()
        try:
            req = urllib.request.Request('https://accounts.spotify.com/api/token', data=datos,
                                         headers={'Authorization': 'Basic ' + cred})
            self.token = json.load(urllib.request.urlopen(req, timeout=25))['access_token']
        except Exception as e:
            print(f'  [spotify] no se pudo obtener token: {e}', file=sys.stderr)
            self.activo = False
        return self.token

    def catalogo(self, artista_id):
        tk = self._token()
        if not tk:
            return []
        cab = {'Authorization': 'Bearer ' + tk}
        salida = []
        url = (f'https://api.spotify.com/v1/artists/{artista_id}/albums'
               '?include_groups=album,single,compilation&limit=50&market=ES')
        while url:
            d = self.http.get(url, headers=cab)
            if not d:
                break
            for a in d.get('items', []):
                album = {
                    'servicio': 'spotify',
                    'titulo': a.get('name'),
                    'url': (a.get('external_urls') or {}).get('spotify'),
                    'anio': anio_de(a.get('release_date')),
                    'id': a.get('id'),
                }
                pistas = []
                pu = f"https://api.spotify.com/v1/albums/{a.get('id')}/tracks?limit=50&market=ES"
                while pu:
                    dt = self.http.get(pu, headers=cab)
                    if not dt:
                        break
                    for t in dt.get('items', []):
                        pistas.append({
                            'servicio': 'spotify',
                            'titulo': t.get('name'),
                            'url': (t.get('external_urls') or {}).get('spotify'),
                            'id': f"spotify:track:{t.get('id')}",
                            'duracion': int((t.get('duration_ms') or 0) / 1000) or None,
                            'autores': None,
                            # Se rellena después con _rellenar_isrc(): el endpoint
                            # de pistas de álbum (arriba) devuelve objetos "track
                            # simplificado", que no incluyen external_ids.
                            'isrc': None,
                            '_spotify_id': t.get('id'),
                        })
                    pu = dt.get('next')
                salida.append((album, pistas))
            url = d.get('next')
        self._rellenar_isrc(salida, cab)
        return salida

    def _rellenar_isrc(self, salida, cab):
        """Completa 'isrc' en las pistas de `salida` (lista de (album, pistas))
        con una llamada por lote al endpoint "several tracks", que sí incluye
        external_ids — a diferencia del endpoint de pistas de álbum usado
        arriba, que devuelve tracks simplificados sin ese campo. Máx. 50 ids
        por llamada (límite de la API)."""
        todas = [p for _album, pistas in salida for p in pistas if p.get('_spotify_id')]
        for i in range(0, len(todas), 50):
            lote = todas[i:i + 50]
            ids = ','.join(p['_spotify_id'] for p in lote)
            d = self.http.get(f'https://api.spotify.com/v1/tracks?ids={ids}&market=ES', headers=cab)
            if not d:
                continue
            por_id = {t.get('id'): t for t in (d.get('tracks') or []) if t}
            for p in lote:
                t = por_id.get(p['_spotify_id'])
                if t:
                    p['isrc'] = (t.get('external_ids') or {}).get('isrc') or None
        for _album, pistas in salida:
            for p in pistas:
                p.pop('_spotify_id', None)


def deezer_buscar_artista(http, nombre):
    """
    Para bandas que solo tienen Spotify enlazado: busca su artista en Deezer
    por nombre. El parecido de nombre por sí solo NO basta —"Santa Cruz" o
    "Redención" tienen homónimos que no son bandas de procesión, y una banda
    equivocada mete decenas de candidatos falsos—, así que exige parecido muy
    alto y quien lo llama valida además el catálogo contra la BD.
    """
    d = http.get('https://api.deezer.com/search/artist?q=' + urllib.parse.quote(nombre) + '&limit=10')
    if not d:
        return None
    mejor, mejor_score = None, 0.0
    for a in d.get('data', []):
        s = similitud(nombre, a.get('name'))
        if s > mejor_score:
            mejor, mejor_score = a, s
    if mejor and mejor_score >= 0.90:
        return str(mejor.get('id')), mejor_score
    return None


# ── BD ───────────────────────────────────────────────────────────────────────

def bandas_objetivo(con, solo=None):
    """
    Bandas con perfil de artista en alguno de los catálogos de discos
    (SERVICIOS_CATALOGO), con todos sus enlaces de banda para poder recorrer
    los que tengan. Tener solo YouTube enlazado no basta para entrar: no se usa
    como catálogo.
    """
    ph = ','.join('?' * len(SERVICIOS_CATALOGO))
    filas = con.execute(f"""
        SELECT b.ID_BANDA, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO, b.LOCALIDAD, b.PROVINCIA
        FROM banda b
        WHERE b.ID_BANDA <> 0
          AND EXISTS (SELECT 1 FROM enlace_streaming e
                      WHERE e.TIPO_ENT = 'banda' AND e.ID_ENT = b.ID_BANDA
                        AND e.SERVICIO IN ({ph}))
        ORDER BY b.NOMBRE_BREVE
    """, SERVICIOS_CATALOGO).fetchall()

    # Solo se cargan los enlaces de servicios que sí son catálogo: así el resto
    # (YouTube, Tidal, Amazon) no puede colarse como fuente por descuido.
    enlaces = defaultdict(dict)
    for id_banda, servicio, url, id_ext in con.execute(
            f"SELECT ID_ENT, SERVICIO, URL, ID_EXT FROM enlace_streaming "
            f"WHERE TIPO_ENT = 'banda' AND SERVICIO IN ({ph})", SERVICIOS_CATALOGO):
        enlaces[id_banda][servicio] = {'url': url, 'id_ext': id_ext}

    bandas = []
    for id_banda, breve, completo, localidad, provincia in filas:
        if solo and id_banda not in solo:
            continue
        bandas.append({
            'id': id_banda, 'breve': breve, 'completo': completo,
            'localidad': localidad, 'provincia': provincia,
            'enlaces': enlaces.get(id_banda, {}),
        })
    return bandas


def indice_marchas(con):
    """
    Índice de todas las marchas existentes: por título normalizado (exacto) y
    por token, para poder comparar cada pista solo contra las marchas que
    comparten alguna palabra (comparar 5.000 × 5.000 a lo bruto no es viable).
    """
    exacto = {}
    por_token = defaultdict(list)
    marchas = []
    for id_marcha, titulo, banda in con.execute(
            'SELECT ID_MARCHA, TITULO, BANDA_ESTRENO FROM marcha WHERE TITULO IS NOT NULL'):
        n = norm(titulo)
        if not n:
            continue
        idx = len(marchas)
        marchas.append({'id': id_marcha, 'titulo': titulo, 'banda': banda, 'norm': n})
        exacto.setdefault(n, marchas[idx])
        for t in tokens(titulo):
            por_token[t].append(idx)
    return {'exacto': exacto, 'por_token': por_token, 'lista': marchas}


def mejor_marcha(indice, titulo):
    """Marcha existente más parecida al título dado, o None. Devuelve (marcha, score)."""
    n = norm(titulo)
    if not n:
        return None, 0.0
    if n in indice['exacto']:
        return indice['exacto'][n], 1.0

    vistos = set()
    for t in tokens(titulo):
        vistos.update(indice['por_token'].get(t, ()))
    mejor, mejor_score = None, 0.0
    for i in vistos:
        m = indice['lista'][i]
        s = difflib.SequenceMatcher(None, n, m['norm']).ratio()
        if s > mejor_score:
            mejor, mejor_score = m, s
    return mejor, mejor_score


def estilos_por_banda(con):
    """
    Estilo dominante (CCTT/AM) de cada banda según las marchas que ya tiene
    estrenadas — el mismo criterio que sugiere el panel al revisar. Si no hay
    marchas previas, se deduce del nombre de la banda.
    """
    dominante = {}
    for banda, estilo, _n in con.execute("""
        SELECT BANDA_ESTRENO, ESTILO, COUNT(*) n FROM marcha
        WHERE BANDA_ESTRENO IS NOT NULL AND ESTILO IN ('CCTT','AM')
        GROUP BY BANDA_ESTRENO, ESTILO ORDER BY BANDA_ESTRENO, n DESC
    """):
        dominante.setdefault(banda, estilo)
    return dominante


def estilo_por_nombre(nombre):
    n = norm(nombre)
    if 'cornetas' in n or re.search(r'\bbct\b|\bbcct\b|\bcctt\b', n):
        return 'CCTT'
    if 'agrupacion musical' in n or re.search(r'\bam\b', n):
        return 'AM'
    return None


def discos_por_banda(con):
    discos = defaultdict(list)
    for id_disco, nombre, fecha, banda in con.execute(
            'SELECT ID_DISCO, NOMBRE_CD, FECHA_CD, BANDADISCO FROM disco'):
        discos[banda].append({'id': id_disco, 'nombre': nombre, 'anio': anio_de(fecha)})
    return discos


def vetos(con):
    try:
        return {(f, i) for f, i in con.execute('SELECT FUENTE, FUENTE_ID FROM ingest_veto')}
    except sqlite3.OperationalError:
        # BD sin migrar (008_ingest_streaming.sql todavía no aplicado).
        return set()


# ── proceso ──────────────────────────────────────────────────────────────────

def catalogo_de_banda(banda, http, spotify, usar_apple, log):
    """
    Todo el catálogo de una banda, servicio a servicio. Devuelve
    [(album, pistas)] y la lista de servicios que respondieron.
    """
    catalogo, usados, notas = [], [], []
    inferido = False
    enlaces = banda['enlaces']

    if spotify.activo and 'spotify' in enlaces:
        sid = enlaces['spotify'].get('id_ext') or id_de_url(enlaces['spotify']['url'], r'/artist/([A-Za-z0-9]+)')
        if sid:
            c = spotify.catalogo(sid)
            if c:
                catalogo += c
                usados.append('spotify')

    if 'deezer' in enlaces:
        did = enlaces['deezer'].get('id_ext') or id_de_url(enlaces['deezer']['url'], r'/artist/(\d+)')
        if did:
            c = deezer_catalogo(http, did)
            if c:
                catalogo += c
                usados.append('deezer')
    elif 'spotify' in enlaces:
        # Sin Deezer enlazado: se intenta localizar el artista por nombre, con
        # guarda de parecido, para no quedarnos sin catálogo si no hay Spotify.
        # El catálogo así obtenido queda marcado como inferido: quien llama lo
        # valida contra la BD antes de aceptar ni un candidato.
        hallado = deezer_buscar_artista(http, banda['breve'] or banda['completo'])
        if hallado:
            did, score = hallado
            c = deezer_catalogo(http, did)
            if c:
                catalogo += c
                usados.append('deezer')
                inferido = True
                notas.append(f'artista de Deezer inferido por nombre (parecido {score:.2f})')
                log(f"    deezer inferido: artista {did} (parecido {score:.2f})")

    if usar_apple and 'apple' in enlaces:
        aid = enlaces['apple'].get('id_ext') or id_de_url(enlaces['apple']['url'], r'/artist/[^/]+/(\d+)')
        if aid:
            c = itunes_catalogo(http, aid)
            if c:
                catalogo += c
                usados.append('apple')

    return catalogo, usados, notas, inferido


def procesar(args):
    env = cargar_env(args.env)
    con = conectar(args.db)
    solo = {int(x) for x in args.solo.split(',')} if args.solo else None

    http = Http(os.path.join(args.out, 'cache'), sleep=args.sleep)
    spotify = Spotify(http, env)
    if not spotify.activo:
        print('[aviso] sin SPOTIFY_CLIENT_ID/SECRET en el .env: se usan Deezer'
              + ('' if args.sin_apple else ' y Apple') + ' como catálogo.', file=sys.stderr)

    bandas = bandas_objetivo(con, solo)
    indice = indice_marchas(con)
    estilos = estilos_por_banda(con)
    discos_bd = discos_por_banda(con)
    vetados = vetos(con)
    n_marchas_banda = {b: n for b, n in con.execute(
        'SELECT BANDA_ESTRENO, COUNT(*) FROM marcha WHERE BANDA_ESTRENO IS NOT NULL GROUP BY BANDA_ESTRENO')}

    print(f'{len(bandas)} bandas con catálogo enlazado ({"/".join(SERVICIOS_CATALOGO)}; YouTube no cuenta)'
          f' · {len(indice["lista"])} marchas en la BD · {len(vetados)} orígenes vetados'
          f' · mínimo {args.min_fuentes} RRSS por candidato')

    candidatos = []
    informe = []
    for i, banda in enumerate(bandas, 1):
        etiqueta = f"{banda['breve']} (#{banda['id']}, {banda['localidad']})"
        print(f'[{i}/{len(bandas)}] {etiqueta}')
        log = (lambda m: print(m)) if args.verbose else (lambda m: None)

        catalogo, usados, notas, inferido = catalogo_de_banda(banda, http, spotify, not args.sin_apple, log)
        if not catalogo:
            print('    sin catálogo accesible')
            informe.append({'banda': banda, 'albumes': 0, 'pistas': 0, 'existentes': 0,
                            'nuevas': 0, 'servicios': [], 'notas': notas + ['sin catálogo accesible'],
                            'discos_nuevos': [], 'nuevas_detalle': []})
            continue

        estilo = estilos.get(banda['id']) or estilo_por_nombre(banda['completo'] or banda['breve'])

        # Una pista puede venir repetida (varios discos, varios servicios): se
        # agrupa por título normalizado y se queda el mejor origen disponible.
        agrupadas = {}
        n_pistas = 0
        for album, pistas in catalogo:
            # Disco de Navidad, cabalgata o carnaval: la banda lo graba, pero
            # ahí no hay marchas procesionales que proponer.
            if ALBUM_NO_PROCESIONAL.search(album['titulo'] or ''):
                continue
            # Disco grabado en directo (concierto o formación en la calle):
            # sus títulos vienen del evento, no sirven como candidato.
            if EN_DIRECTO.search(album['titulo'] or ''):
                continue
            for p in pistas:
                # Pista suelta marcada como directo/en vivo aunque el disco no
                # lo esté (compilaciones que mezclan estudio y directo).
                if EN_DIRECTO.search(p['titulo'] or ''):
                    continue
                titulo = limpiar_titulo(p['titulo'])
                clave = norm(titulo)
                if not clave:
                    continue
                n_pistas += 1
                previo = agrupadas.get(clave)
                if previo is None:
                    agrupadas[clave] = {'titulo': titulo, 'pista': p, 'album': album,
                                        'servicios': {p['servicio']}}
                    continue
                # Mismo título en más de un servicio: cuenta como corroboración,
                # se necesita para no dar por buena la ficha de una sola RRSS.
                previo['servicios'].add(p['servicio'])
                # Mejor origen = servicio más prioritario; a igualdad, el disco más antiguo
                # (suele ser el de estreno, y su año es el que más se acerca al de la marcha).
                mejor_servicio = PRIORIDAD_FUENTE.index(p['servicio']) < PRIORIDAD_FUENTE.index(previo['pista']['servicio'])
                mismo_servicio = p['servicio'] == previo['pista']['servicio']
                mas_antiguo = (album.get('anio') or 9999) < (previo['album'].get('anio') or 9999)
                if mejor_servicio or (mismo_servicio and mas_antiguo):
                    previo['titulo'], previo['pista'], previo['album'] = titulo, p, album

        # Fusión por ISRC: dos títulos que normalizan distinto pero comparten
        # ISRC son la misma grabación (mismo master, catalogado con otra
        # ortografía/orden en cada servicio) — el caso que la corroboración
        # por título normalizado no puede ver. Se funden los conjuntos de
        # `servicios` de todas las claves que comparten ISRC *antes* de exigir
        # `--min-fuentes`, así una banda con un único catálogo que además
        # tenga ISRC ya no puede corroborar sola (haría falta un segundo
        # servicio con la misma pista), pero dos títulos parecidos-no-iguales
        # en dos servicios distintos con el mismo ISRC sí cuentan como
        # corroborados. No se fusionan las claves en una sola: cada título
        # sigue proponiéndose por separado si pasa el umbral, para que el
        # revisor humano decida cuál de las dos variantes es la buena en vez
        # de que el pipeline elija a ciegas.
        por_isrc = {}
        for clave, item in agrupadas.items():
            isrc = item['pista'].get('isrc')
            if isrc:
                por_isrc.setdefault(isrc, []).append(clave)
        for isrc, claves in por_isrc.items():
            if len(claves) < 2:
                continue
            servicios_unidos = set()
            for c in claves:
                servicios_unidos |= agrupadas[c]['servicios']
            for c in claves:
                agrupadas[c]['servicios'] = servicios_unidos

        existentes, insuficientes, nuevas = 0, 0, []
        for clave, item in sorted(agrupadas.items()):
            titulo, p, album = item['titulo'], item['pista'], item['album']
            if RUIDO.search(clave):
                continue

            # Corroboración entre RRSS: un título que solo aparece en un
            # servicio puede ser ruido propio de ese catálogo (etiquetado
            # suelto, homónimo, versión rara). Se exige que el mismo título
            # aparezca en al menos --min-fuentes catálogos de streaming antes
            # de proponerlo; una banda con un único servicio enlazado no
            # puede corroborar nada y por tanto no aporta candidatos hasta
            # que enlace otro.
            if len(item['servicios']) < args.min_fuentes:
                insuficientes += 1
                continue

            marcha, score = mejor_marcha(indice, titulo)
            if score >= args.umbral_existe:
                existentes += 1
                continue

            # Pista que encadena varias piezas: si alguna de sus partes es una
            # marcha que ya está en la BD, la pista es una interpretación de lo
            # conocido (típico de los discos en directo), no una marcha nueva.
            partes = partes_de_titulo(titulo)
            if len(partes) > 1 and any(
                    mejor_marcha(indice, parte)[1] >= args.umbral_existe for parte in partes):
                existentes += 1
                continue
            if (p['servicio'], p['id']) in vetados:
                continue

            flags = []
            if not p.get('autores'):
                flags.append('sin_autor_detectado')
            # Popurrís y enlaces de varias piezas en una sola pista: el título
            # no es el de una marcha, hay que trocearlo o descartarlo a mano.
            if POPURRI.search(titulo):
                flags.append('posible_popurri')
            if score >= args.umbral_aviso:
                flags.append('posible_duplicado')
            if not album.get('anio'):
                flags.append('sin_anio')
            flags.append('banda_estreno_sin_verificar')

            # Confianza: alta cuando no se parece a nada de la BD y el disco
            # tiene año; baja cuando hay una marcha parecida rondando.
            confianza = 0.85
            if score >= args.umbral_aviso:
                confianza = 0.45
            elif score >= 0.6:
                confianza = 0.7
            if not album.get('anio'):
                confianza -= 0.1

            nuevas.append({
                'fuente': p['servicio'],
                'fuente_album': album['titulo'],
                'fuente_album_url': album['url'],
                'id_banda': banda['id'],
                'video_id': p['id'],
                'video_url': p['url'],
                'video_titulo': p['titulo'],
                'video_desc': None,
                'publicado_at': str(album['anio']) if album.get('anio') else None,
                'duracion_seg': p.get('duracion'),
                'isrc': p.get('isrc'),
                'clasificacion': 'novedad',
                'confianza': round(confianza, 2),
                'flags': flags,
                'p_titulo': titulo,
                'p_fecha': album.get('anio'),
                'p_dedicatoria': None,
                'p_localidad': None,
                'p_provincia': None,
                'p_autores': p.get('autores'),
                'p_estilo': estilo,
                'p_banda_estreno': banda['id'],
                'match_marcha_id': marcha['id'] if (marcha and score >= args.umbral_aviso) else None,
                'match_score': round(score, 3) if (marcha and score >= args.umbral_aviso) else None,
                'estado': 'pendiente',
                'motivo': None,
                'raw_json': json.dumps({'album': album, 'pista': p}, ensure_ascii=False),
            })

        # Validación del catálogo inferido: si el artista se dedujo por nombre,
        # el catálogo correcto tiene que solaparse con lo que la BD ya sabe de
        # esa banda. Cero coincidencias en una banda con marchas registradas
        # significa que es un homónimo (pasó con "AM Santa Cruz": el artista
        # encontrado era un grupo de rap), así que se tira todo el lote.
        if inferido and existentes == 0 and n_marchas_banda.get(banda['id'], 0) >= 5:
            print(f'    descartado: artista inferido sin ninguna coincidencia con las '
                  f"{n_marchas_banda[banda['id']]} marchas que la BD ya tiene de esta banda")
            informe.append({'banda': banda, 'albumes': len(catalogo), 'pistas': n_pistas,
                            'unicas': len(agrupadas), 'existentes': 0, 'nuevas': 0, 'servicios': [],
                            'notas': notas + ['artista inferido descartado: 0 coincidencias con la BD '
                                              '(probable banda homónima) — enlaza el artista a mano si existe'],
                            'discos_nuevos': [], 'nuevas_detalle': []})
            continue

        # Discos del catálogo que no están en la tabla `disco` de la banda:
        # informativo, para saber qué grabaciones le faltan a la BD.
        titulos_bd = {norm(d['nombre']) for d in discos_bd.get(banda['id'], [])}
        discos_nuevos = []
        for album, _p in catalogo:
            na = norm(album['titulo'])
            if na and na not in titulos_bd and not any(
                    difflib.SequenceMatcher(None, na, t).ratio() >= 0.9 for t in titulos_bd):
                if album['titulo'] not in [d['titulo'] for d in discos_nuevos]:
                    discos_nuevos.append({'titulo': album['titulo'], 'anio': album.get('anio'),
                                          'servicio': album['servicio'], 'url': album['url']})

        candidatos += nuevas
        informe.append({'banda': banda, 'albumes': len(catalogo), 'pistas': n_pistas,
                        'unicas': len(agrupadas), 'existentes': existentes, 'insuficientes': insuficientes,
                        'nuevas': len(nuevas), 'servicios': usados, 'notas': notas,
                        'discos_nuevos': discos_nuevos, 'nuevas_detalle': nuevas})
        print(f'    {len(catalogo)} discos · {len(agrupadas)} pistas únicas · '
              f'{existentes} ya en la BD · {insuficientes} sin corroborar en {args.min_fuentes}+ RRSS · '
              f'{len(nuevas)} nuevas')

    escribir_salidas(args, candidatos, informe)
    return candidatos, informe


def escribir_salidas(args, candidatos, informe):
    os.makedirs(args.out, exist_ok=True)

    if args.dry_run:
        print(f'\n[dry-run] {len(candidatos)} candidatos (no se escribe NDJSON)')
    else:
        ruta = os.path.join(args.out, 'candidatos.ndjson')
        with open(ruta, 'w', encoding='utf-8') as f:
            for c in candidatos:
                f.write(json.dumps(c, ensure_ascii=False) + '\n')
        print(f'\nEscrito {ruta} ({len(candidatos)} candidatos)')

        ruta_csv = os.path.join(args.out, 'candidatos.csv')
        with open(ruta_csv, 'w', encoding='utf-8-sig', newline='') as f:
            w = csv.writer(f, delimiter=';')
            w.writerow(['ID_BANDA', 'BANDA', 'TITULO', 'ANIO', 'ESTILO', 'FUENTE', 'DISCO', 'URL', 'ISRC', 'FLAGS', 'MATCH'])
            porbanda = {i['banda']['id']: i['banda'] for i in informe}
            for c in candidatos:
                b = porbanda.get(c['id_banda'], {})
                w.writerow([c['id_banda'], b.get('breve', ''), c['p_titulo'], c['p_fecha'] or '',
                            c['p_estilo'] or '', c['fuente'], c['fuente_album'], c['video_url'],
                            c.get('isrc') or '', ','.join(c['flags']), c['match_score'] or ''])
        print(f'Escrito {ruta_csv}')

    ruta_md = os.path.join(args.out, 'informe.md')
    with open(ruta_md, 'w', encoding='utf-8') as f:
        f.write('# Marchas encontradas en el catálogo de streaming de las bandas\n\n')
        f.write(f'Generado por `tools/music_links/descubrir_marchas.py` · '
                f'{len(informe)} bandas · {len(candidatos)} candidatos nuevos.\n\n')
        f.write('| Banda | Servicios | Discos | Pistas únicas | Ya en BD | Sin corroborar | Nuevas |\n')
        f.write('|---|---|---:|---:|---:|---:|---:|\n')
        for i in sorted(informe, key=lambda x: -x['nuevas']):
            b = i['banda']
            f.write(f"| {b['breve']} (#{b['id']}, {b['localidad']}) | {', '.join(i['servicios']) or '—'} "
                    f"| {i['albumes']} | {i.get('unicas', 0)} | {i['existentes']} | "
                    f"{i.get('insuficientes', 0)} | {i['nuevas']} |\n")
        f.write('\n---\n\n')
        for i in sorted(informe, key=lambda x: -x['nuevas']):
            b = i['banda']
            f.write(f"## {b['breve']} — #{b['id']} ({b['localidad']})\n\n")
            if i['notas']:
                f.write('> ' + ' · '.join(i['notas']) + '\n\n')
            if i['nuevas_detalle']:
                f.write('**Marchas que faltan en la BD:**\n\n')
                for c in i['nuevas_detalle']:
                    aviso = ' ⚠ posible duplicado' if 'posible_duplicado' in c['flags'] else ''
                    f.write(f"- [{c['p_titulo']}]({c['video_url']}) — {c['fuente_album']}"
                            f" ({c['p_fecha'] or 's/f'}){aviso}\n")
                f.write('\n')
            if i['discos_nuevos']:
                f.write('**Discos del catálogo que no están en la tabla `disco`:**\n\n')
                for d in i['discos_nuevos']:
                    f.write(f"- [{d['titulo']}]({d['url']}) ({d['anio'] or 's/f'}, {d['servicio']})\n")
                f.write('\n')
    print(f'Escrito {ruta_md}')


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument('--db', default=os.environ.get('DB_PATH', os.path.join(RAIZ, 'php', 'data', 'mdc.db')),
                   help='ruta del .db (por defecto php/data/mdc.db o $DB_PATH)')
    p.add_argument('--env', default=os.path.join(RAIZ, '.env'), help='.env con las credenciales de Spotify')
    p.add_argument('--out', default=os.path.join(RAIZ, 'tools', 'music_links', 'out'), help='directorio de salida')
    p.add_argument('--solo', help='lista de ID_BANDA separados por comas')
    p.add_argument('--sin-apple', action='store_true',
                   help='no usar iTunes/Apple como catálogo (va bien para una pasada rápida: '
                        'Apple tiene un rate-limit agresivo y es la parte lenta)')
    p.add_argument('--sleep', type=float, default=0.25, help='pausa entre peticiones HTTP (s)')
    p.add_argument('--min-fuentes', type=int, default=2,
                   help='nº mínimo de catálogos de streaming (spotify/deezer/apple) en los que '
                        'debe aparecer el mismo título para proponerlo como candidato; una banda '
                        'con un único servicio enlazado no aporta candidatos hasta que enlace otro')
    p.add_argument('--umbral-existe', type=float, default=0.90,
                   help='similitud a partir de la cual se da la marcha por existente')
    p.add_argument('--umbral-aviso', type=float, default=0.75,
                   help='similitud a partir de la cual se avisa de posible duplicado')
    p.add_argument('--dry-run', action='store_true', help='no escribe el NDJSON, solo cuenta e informa')
    p.add_argument('--verbose', action='store_true')
    args = p.parse_args()

    if not os.path.isfile(args.db):
        print(f'No existe la BD en {args.db}', file=sys.stderr)
        sys.exit(1)
    procesar(args)


if __name__ == '__main__':
    main()
