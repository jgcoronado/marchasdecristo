#!/usr/bin/env python3
"""
Fase 1: enlazado de DISCOS con servicios de streaming (Spotify/Apple/Deezer).
Ámbito configurable con --scope (por defecto all-active): ver mdc_music.SCOPES.
Escribe candidatos a enlace_candidato (ESTADO='pendiente'). Re-ejecutable:
reemplaza los 'pendiente' de los discos en ámbito (no toca aprobados/rechazados).
"""
import sys, time, datetime, csv, os
import mdc_music as M

def main():
    scope = M.scope_arg('all-active')
    env = M.load_env()
    have_spotify = bool(env.get('SPOTIFY_CLIENT_ID'))
    RUN_ID = f'discos-{scope}-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    print(f'ámbito={scope} · Spotify={"ON" if have_spotify else "OFF"}', file=sys.stderr)
    c = M.connect()
    bandas = M.bandas(c, scope)
    print(f'bandas en ámbito: {len(bandas)}', file=sys.stderr)

    db_rows = []
    for idx, (bid, breve, completo) in enumerate(bandas):
        discos = c.execute('select ID_DISCO,NOMBRE_CD,FECHA_CD from disco where BANDADISCO=? order by FECHA_CD', (bid,)).fetchall()
        if not discos:
            continue
        cands = []
        for name in {breve, completo}:
            if not name: continue
            cands += M.deezer_albums(name) + M.itunes_albums(name)
            if have_spotify: cands += M.spotify_albums(name, env)
            time.sleep(0.15)
        for did, cd, fcd in discos:
            byr = M.year_of(fcd)
            best_by_srv = {}
            for cand in cands:
                a_score = max(M.jacc(cand['artist'], breve, M.STOP_ARTIST), M.jacc(cand['artist'], completo or '', M.STOP_ARTIST))
                if a_score < 0.34:
                    continue
                t_score = M.jacc(cand['album'], cd, M.STOP_ARTIST)
                yb = 0.15 if (byr and cand.get('year') == byr) else 0.0
                score = round(0.55*t_score + 0.30*a_score + yb, 3)
                srv = cand['servicio']
                if srv not in best_by_srv or score > best_by_srv[srv][0]:
                    best_by_srv[srv] = (score, cand)
            for srv, (sc, cand) in best_by_srv.items():
                conf = 'ALTA' if sc >= 0.55 else ('MEDIA' if sc >= 0.4 else 'BAJA')
                db_rows.append(('disco', did, srv, cand['url'], cand.get('id_ext'),
                                cand['album'], cand['artist'], cand.get('year') or None, sc, conf))
        if (idx + 1) % 20 == 0:
            print(f'  ...{idx+1}/{len(bandas)} bandas', file=sys.stderr)

    _persist(db_rows, RUN_ID, 'disco')

def _persist(db_rows, RUN_ID, tipo):
    wc = M.connect()
    ids = [d[1] for d in db_rows] or [-1]
    ph = ','.join('?'*len(ids))
    wc.execute(f"delete from enlace_candidato where TIPO_ENT='{tipo}' and ESTADO='pendiente' and ID_ENT in ({ph})", ids)
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
