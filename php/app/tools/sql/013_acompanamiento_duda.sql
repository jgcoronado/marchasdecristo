-- Cola de revisión para la nómina de hermandades/pasos (N-03, ver
-- 012_hermandad_paso.sql y docs/acompanamientos-nomina-2026.md).
--
-- Al parsear las fuentes de cada localidad (el programa oficial en PDF de
-- Córdoba, las páginas de musicofrades.com de Sevilla/Málaga/Jerez) la
-- mayoría de filas se clasifican solas (Cristo/Virgen/Cruz de Guía, banda
-- CCTT/AM o no), pero algunas no: una etiqueta de paso que no se ha visto
-- antes ("Trono de Cristo y Virgen", "Paso de San Juan"...) o una ficha sin
-- música detectable por un maquetado distinto (La O/Lágrimas de Córdoba).
-- En vez de adivinar esas, se guardan aquí para que un admin las resuelva
-- desde /dashboard/acompanamientos-dudas en vez de a mano en un CSV.
--
-- Resolver una duda NO escribe todavía en `hermandad`/`paso`/`contrato_paso`
-- (eso es la importación final, que sigue pendiente): solo dejar registrada
-- la decisión (RESOLUCION_TIPO/RESOLUCION_NOTA) para cuando llegue.
CREATE TABLE IF NOT EXISTS acompanamiento_duda (
    ID_DUDA         INTEGER PRIMARY KEY,
    LOCALIDAD       TEXT    NOT NULL,
    DIA             TEXT,
    HERMANDAD       TEXT    NOT NULL,
    ETIQUETA_RAW    TEXT,                       -- tal cual la trae la fuente
    BANDA_TEXTO     TEXT,
    BANDA_URL       TEXT,
    FUENTE          TEXT    NOT NULL,           -- 'musicofrades' | 'cordoba_programa'
    MOTIVO          TEXT    NOT NULL,           -- 'tipo_paso_ambiguo' | 'sin_musica_detectada'
    ESTADO          TEXT    NOT NULL DEFAULT 'pendiente', -- pendiente | resuelto | descartado
    RESOLUCION_TIPO TEXT,                       -- cristo | virgen | cruz_guia | ninguno
    RESOLUCION_NOTA TEXT,                       -- texto libre del admin (banda correcta, aclaración...)
    CREATED_AT      TEXT    NOT NULL DEFAULT (datetime('now')),
    REVIEWED_AT     TEXT,
    REVIEWED_BY     TEXT,
    -- Evita duplicar la misma duda si el import se repite (es idempotente).
    UNIQUE (LOCALIDAD, HERMANDAD, ETIQUETA_RAW, MOTIVO)
);
CREATE INDEX IF NOT EXISTS ix_acomp_duda_estado ON acompanamiento_duda (ESTADO);
