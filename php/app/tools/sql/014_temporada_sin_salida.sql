-- Años en que una localidad no tuvo salida procesional (N-03, ver
-- docs/acompanamientos-2020-2025.md).
--
-- El histórico de `contrato` es una fila por año con banda: si un año no hubo
-- procesión no hay filas, y en /acompanamientos eso se ve como un hueco entre
-- dos rangos ("2022-2026 ... 2019") sin explicación. Registrar el año aquí
-- permite anotar el hueco con su motivo en vez de dejarlo mudo, y sin inventar
-- contratos que no existieron: en 2020 y 2021 ninguna hermandad salió por la
-- pandemia, aunque varias tuvieran banda contratada.
--
-- No hay FK a `contrato` ni a `hermandad` a propósito: el hecho es de la
-- localidad entera, no de una hermandad concreta.
CREATE TABLE IF NOT EXISTS temporada_sin_salida (
    LOCALIDAD  TEXT    NOT NULL,
    ANIO       INTEGER NOT NULL,
    MOTIVO     TEXT    NOT NULL,   -- texto corto que se enseña tal cual
    FUENTE     TEXT,
    CREATED_AT TEXT    NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (LOCALIDAD, ANIO)
);
