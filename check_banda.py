import sqlite3
c = sqlite3.connect('php/data/mdc.db')
cur = c.cursor()

sql = """
SELECT b.ID_BANDA, (b.NOMBRE_BREVE || ' (' || b.LOCALIDAD || ')') AS BANDA,
        COUNT(*) AS TOTAL,
        SUM(CASE WHEN (
            (m.AUDIO IS NOT NULL AND TRIM(m.AUDIO) != '')
            OR EXISTS (SELECT 1 FROM enlace_streaming es WHERE es.TIPO_ENT = 'marcha' AND es.ID_ENT = m.ID_MARCHA)
        ) THEN 1 ELSE 0 END) AS CON_AUDIO
 FROM marcha m INNER JOIN banda b ON b.ID_BANDA = m.BANDA_ESTRENO
 WHERE b.ID_BANDA != 0
   AND EXISTS (SELECT 1 FROM marcha_autor ma WHERE ma.ID_MARCHA = m.ID_MARCHA)
 GROUP BY b.ID_BANDA, b.NOMBRE_BREVE, b.LOCALIDAD
 HAVING COUNT(*) >= ?
 ORDER BY (CAST(CON_AUDIO AS REAL) / TOTAL) ASC, TOTAL DESC
"""

try:
    cur.execute(sql, (3,))
    rows = cur.fetchall()
    print("filas devueltas:", len(rows))
    for r in rows[:5]:
        print(r)
except Exception as e:
    print("ERROR ejecutando la consulta:")
    print(repr(e))

# Comprobación de la tabla enlace_streaming: ¿existen las columnas que espera la query?
cur.execute("PRAGMA table_info(enlace_streaming)")
print("\ncolumnas de enlace_streaming:", [r[1] for r in cur.fetchall()])