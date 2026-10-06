#!/usr/bin/env python3
"""
Fase 3: enlazado de SINGLES/PISTAS con marchas estrenadas que NO están en ningún
disco. Ámbito configurable con --scope (por defecto all-active): se toman las
marchas cuya BANDA_ESTRENO está en el ámbito y que no aparecen en disco_marcha.

Heurística del usuario: al estrenar, la banda suele subir el audio el MISMO año →
boost fuerte (+0.2) cuando el año de la pista == año de estreno.

Servicios: Spotify + Deezer + iTunes (con --no-itunes se omite Apple, útil en
ámbitos grandes por el rate-limit de iTunes). Escribe TIPO_ENT='marcha'.
"""
import sys, time, datetime
import mdc_music as M

def main():
    scope = M.scope_arg('all-active')
    no_itunes = '--no-itunes' in sys.argv
    env = M.load_env()
    have_spotify = bool(env.get('SPOTIFY_CLIENT_ID'))
    RUN_ID = f'marchas-{scope}-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    print(f'ámbito={scope} · Spotify={"ON" if have_spotify else "OFF"} · iTunes={"OFF" if no_itunes else "ON"}', file=sys.stderr)
    c = M.connect()
    bandas = M.bandas(c, scope)
    bids = [b[0] for b in bandas] or [-1]
    ph = ','.join('?'*len(bids))
    marchas = c.execute(f"""
        select m.ID_MARCHA, m.TITULO, m.FECHA, b.NOMBRE_BREVE, b.NOMBRE_COMPLETO
        from marcha m join banda b on b.ID_BANDA = m.BANDA_ESTRENO
        where m.BANDA_ESTRENO in ({ph})
          and not exists (select 1 from disco_marcha dm where dm.IDMARCHA = m.ID_MARCHA)
        order by m.FECHA desc""", bids).fetchall()
    print(f'marchas objetivo: {len(marchas)}', file=sys.stderr)

    db_rows = []
    for i, (mid, titulo, fecha, breve, completo) in enumerate(marchas):
        anio = M.year_of(fecha)
        q = f'{titulo} {breve}'
        cands = M.deezer_tracks(q)
        if not no_itunes: cands += M.itunes_tracks(q)
        if have_spotify: cands += M.spotify_tracks(q, env)
        time.sleep(0.12)
        best_by_srv = {}
        for cand in cands:
            t_score = M.jacc(cand['title'], titulo, M.STOP_TITLE)
            a_score = max(M.jacc(cand['artist'], breve, M.STOP_ARTIST), M.jacc(cand['artist'], completo or '', M.STOP_ARTIST))
            if t_score < 0.5 or a_score < 0.34:
                continue
            yb = 0.2 if (anio and cand.get('year') == anio) else 0.0
            score = round(min(0.5*t_score + 0.3*a_score + yb, 1.0), 3)
            srv = cand['servicio']
            if srv not in best_by_srv or score > best_by_srv[srv][0]:
                best_by_srv[srv] = (score, cand)
        for srv, (sc, cand) in best_by_srv.items():
            conf = 'ALTA' if sc >= 0.6 else ('MEDIA' if sc >= 0.45 else 'BAJA')
            db_rows.append(('marcha', mid, srv, cand['url'], cand['id_ext'], cand['title'],
                            cand['artist'], cand.get('year') or None, sc, conf))
        if (i + 1) % 50 == 0:
            print(f'  ...{i+1}/{len(marchas)} marchas', file=sys.stderr)

    wc = M.connect()
    mids = [m[0] for m in marchas] or [-1]
    phm = ','.join('?'*len(mids))
    wc.execute(f"delete from enlace_candidato where TIPO_ENT='marcha' and ESTADO='pendiente' and ID_ENT in ({phm})", mids)
    wc.executemany("""insert or ignore into enlace_candidato
        (TIPO_ENT,ID_ENT,SERVICIO,URL,ID_EXT,TITULO_ENC,ARTISTA_ENC,ANIO_ENC,SCORE,CONFIANZA,ESTADO,RUN_ID)
        values (?,?,?,?,?,?,?,?,?,?, 'pendiente', ?)""",
        [(d[0],d[1],d[2],d[3],d[4],d[5],d[6],d[7],d[8],d[9], RUN_ID) for d in db_rows])
    wc.commit()
    from collections import Counter
    conf = Counter(d[9] for d in db_rows)
    marchas_con = len({d[1] for d in db_rows})
    ins = wc.execute("select count(*) from enlace_candidato where RUN_ID=?", (RUN_ID,)).fetchone()[0]
    print(f'\nRUN_ID={RUN_ID}\nconfianza: {dict(conf)}\nmarchas con candidato: {marchas_con}/{len(marchas)}\ncandidatos insertados: {ins}')

if __name__ == '__main__':
    main()
