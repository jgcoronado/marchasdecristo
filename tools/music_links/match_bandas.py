#!/usr/bin/env python3
"""
Fase 2: enlazado de la PÁGINA DE ARTISTA de cada banda con los servicios.
Ámbito configurable con --scope (por defecto all-active).
Refuerzo por el artista de los discos ya localizados (fase 1) y guard contra
falsos positivos de un solo token. Escribe candidatos TIPO_ENT='banda'.
"""
import sys, time, datetime
import mdc_music as M

def main():
    scope = M.scope_arg('all-active')
    env = M.load_env()
    have_spotify = bool(env.get('SPOTIFY_CLIENT_ID'))
    RUN_ID = f'bandas-{scope}-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    print(f'ámbito={scope} · Spotify={"ON" if have_spotify else "OFF"}', file=sys.stderr)
    c = M.connect()
    bandas = M.bandas(c, scope)
    print(f'bandas en ámbito: {len(bandas)}', file=sys.stderr)

    db_rows = []
    for idx, (bid, breve, completo) in enumerate(bandas):
        artistas_disco = [r[0] for r in c.execute(
            "select distinct ARTISTA_ENC from enlace_candidato where TIPO_ENT='disco' and ID_ENT in "
            "(select ID_DISCO from disco where BANDADISCO=?) and ARTISTA_ENC is not null and ARTISTA_ENC<>''", (bid,))]
        cands = []
        for name in {breve, completo}:
            if not name: continue
            cands += M.deezer_artists(name) + M.itunes_artists(name)
            if have_spotify: cands += M.spotify_artists(name, env)
            time.sleep(0.15)
        banda_toks = M.toks(breve, M.STOP_ARTIST) | M.toks(completo or '', M.STOP_ARTIST)
        best_by_srv = {}
        for cand in cands:
            base = max(M.jacc(cand['name'], breve, M.STOP_ARTIST), M.jacc(cand['name'], completo or '', M.STOP_ARTIST))
            if base < 0.30:
                continue
            refuerzo = 0.0
            for ad in artistas_disco:
                if M.jacc(cand['name'], ad, M.STOP_ARTIST) >= 0.6:
                    refuerzo = 0.2; break
            score = round(min(base + refuerzo, 1.0), 3)
            compartidos = len(M.toks(cand['name'], M.STOP_ARTIST) & banda_toks)
            srv = cand['servicio']
            if srv not in best_by_srv or score > best_by_srv[srv][0]:
                best_by_srv[srv] = (score, cand, compartidos, refuerzo > 0)
        for srv, (sc, cand, compartidos, reforzado) in best_by_srv.items():
            fiable = compartidos >= 2 or reforzado
            conf = 'ALTA' if (sc >= 0.6 and fiable) else ('MEDIA' if sc >= 0.4 else 'BAJA')
            db_rows.append(('banda', bid, srv, cand['url'], cand['id_ext'], cand['name'], '', None, sc, conf))
        if (idx + 1) % 20 == 0:
            print(f'  ...{idx+1}/{len(bandas)} bandas', file=sys.stderr)

    _persist(db_rows, RUN_ID)

def _persist(db_rows, RUN_ID):
    wc = M.connect()
    ids = [d[1] for d in db_rows] or [-1]
    ph = ','.join('?'*len(ids))
    wc.execute(f"delete from enlace_candidato where TIPO_ENT='banda' and ESTADO='pendiente' and ID_ENT in ({ph})", ids)
    wc.executemany("""insert or ignore into enlace_candidato
        (TIPO_ENT,ID_ENT,SERVICIO,URL,ID_EXT,TITULO_ENC,ARTISTA_ENC,ANIO_ENC,SCORE,CONFIANZA,ESTADO,RUN_ID)
        values (?,?,?,?,?,?,?,?,?,?, 'pendiente', ?)""",
        [(d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7],d[8],d[9], RUN_ID) for d in db_rows])
    wc.commit()
    from collections import Counter
    conf = Counter(d[9] for d in db_rows)
    ins = wc.execute("select count(*) from enlace_candidato where RUN_ID=?", (RUN_ID,)).fetchone()[0]
    print(f'\nRUN_ID={RUN_ID}\nconfianza: {dict(conf)}\ncandidatos insertados: {ins}')

if __name__ == '__main__':
    main()
