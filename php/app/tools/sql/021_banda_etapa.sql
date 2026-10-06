-- Etapas de actividad de una banda (2026-10-06). Hasta ahora banda.FECHA_FUND /
-- FECHA_EXT daban UN solo periodo, y eso no cubre una banda que desaparece y se
-- vuelve a crear con el mismo nombre: BCT Fuensanta (#8) existió 1992–2008, se
-- fusionó en BCT Caído y Fuensanta, y en 2022 se refundó mientras la fusionada
-- sigue. Es la misma ficha (mismo nombre, mismos estrenos), con dos etapas.
--
-- Solo llevan filas aquí las bandas con más de una etapa; el resto sigue con
-- FECHA_FUND / FECHA_EXT escritos a mano, como siempre. Si una banda tiene
-- etapas, esas dos columnas pasan a ser un resumen CALCULADO por los triggers
-- de abajo (primer inicio; último fin, o NULL si hay una etapa abierta), y lo
-- que ya las lee (listado, API, SEO, linaje) sigue funcionando sin cambios.
-- El panel no deja editarlas a mano mientras haya etapas (AdminRepo::editBanda).
--
-- No entra en cambio_log por tabla (011 se genera antes que esta y fallaría en
-- una BD nueva, igual que 012–020): el alta/borrado queda en admin_log, y el
-- efecto sobre banda.FECHA_FUND / FECHA_EXT sí lo registra trg_log_banda_u.
CREATE TABLE IF NOT EXISTS banda_etapa (
    ID_ETAPA    INTEGER PRIMARY KEY,
    ID_BANDA    INTEGER NOT NULL REFERENCES banda(ID_BANDA),
    ANIO_INICIO INTEGER NOT NULL,
    ANIO_FIN    INTEGER,           -- NULL = en activo
    NOTA        TEXT,
    CHECK (ANIO_FIN IS NULL OR ANIO_FIN >= ANIO_INICIO)
);
CREATE INDEX IF NOT EXISTS idx_banda_etapa ON banda_etapa (ID_BANDA, ANIO_INICIO);

-- Recalcula el resumen de la banda tocada. Mientras quede alguna etapa; al
-- borrar la última, FECHA_FUND / FECHA_EXT se quedan con su último valor.
CREATE TRIGGER IF NOT EXISTS trg_banda_etapa_i AFTER INSERT ON banda_etapa BEGIN
  UPDATE banda SET
    FECHA_FUND = (SELECT MIN(ANIO_INICIO) FROM banda_etapa WHERE ID_BANDA = new.ID_BANDA),
    FECHA_EXT  = (SELECT CASE WHEN COUNT(*) > COUNT(ANIO_FIN) THEN NULL ELSE MAX(ANIO_FIN) END
                  FROM banda_etapa WHERE ID_BANDA = new.ID_BANDA)
  WHERE ID_BANDA = new.ID_BANDA;
END;

CREATE TRIGGER IF NOT EXISTS trg_banda_etapa_u AFTER UPDATE ON banda_etapa BEGIN
  UPDATE banda SET
    FECHA_FUND = (SELECT MIN(ANIO_INICIO) FROM banda_etapa WHERE ID_BANDA = banda.ID_BANDA),
    FECHA_EXT  = (SELECT CASE WHEN COUNT(*) > COUNT(ANIO_FIN) THEN NULL ELSE MAX(ANIO_FIN) END
                  FROM banda_etapa WHERE ID_BANDA = banda.ID_BANDA)
  WHERE ID_BANDA IN (old.ID_BANDA, new.ID_BANDA)
    AND EXISTS (SELECT 1 FROM banda_etapa WHERE ID_BANDA = banda.ID_BANDA);
END;

CREATE TRIGGER IF NOT EXISTS trg_banda_etapa_d AFTER DELETE ON banda_etapa BEGIN
  UPDATE banda SET
    FECHA_FUND = (SELECT MIN(ANIO_INICIO) FROM banda_etapa WHERE ID_BANDA = old.ID_BANDA),
    FECHA_EXT  = (SELECT CASE WHEN COUNT(*) > COUNT(ANIO_FIN) THEN NULL ELSE MAX(ANIO_FIN) END
                  FROM banda_etapa WHERE ID_BANDA = old.ID_BANDA)
  WHERE ID_BANDA = old.ID_BANDA
    AND EXISTS (SELECT 1 FROM banda_etapa WHERE ID_BANDA = old.ID_BANDA);
END;
