-- Recorrido de ida / vuelta (2026-09-24). Algunas hermandades llevan una banda
-- a la ida y otra distinta a la vuelta detrás del MISMO paso, así que ese paso
-- tiene dos contratos el mismo año y ninguno es un duplicado. El admin marca la
-- hermandad en /dashboard/acompanamientos/{localidad} y entonces cada línea de
-- acompañamiento de sus pasos puede decir si es de ida o de vuelta.
--
-- Tablas satélite en vez de columnas nuevas por el mismo motivo que
-- contrato_localidad (009) y contrato_paso (012): SQLite no tiene
-- "ADD COLUMN IF NOT EXISTS" y migrate_ingest.php re-ejecuta los .sql a ciegas.

-- Presencia de la fila = la hermandad hace ida y vuelta con bandas distintas.
CREATE TABLE IF NOT EXISTS hermandad_ida_vuelta (
    ID_HERMANDAD INTEGER PRIMARY KEY REFERENCES hermandad(ID_HERMANDAD)
);

-- Tramo de un contrato. Sin fila = sin indicar (lo normal: toda la carrera).
-- Se borra antes que el contrato (foreign_keys=ON), ver AdminRepo.
CREATE TABLE IF NOT EXISTS contrato_tramo (
    ID_CONTRATO INTEGER PRIMARY KEY REFERENCES contrato(ID_CONTRATO),
    TRAMO       TEXT    NOT NULL CHECK (TRAMO IN ('ida', 'vuelta'))
);
