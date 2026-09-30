-- Provincia de cada localidad de acompañamientos (2026-09-28). La LOCALIDAD de
-- contrato_localidad / hermandad / semana_santa_dia es texto libre (ver 009 y
-- 012) y no dice a qué provincia pertenece: Jerez de la Frontera, Écija o Dos
-- Hermanas no son capitales. El alta de acompañamientos desde la ficha de
-- banda (/dashboard/banda/{id}, pestaña «Acompañamientos») pide la provincia
-- al dar de alta una localidad nueva y la enseña en el listado.
--
-- LOCALIDAD es la clave literal tal cual está en las otras tablas (con o sin
-- tildes: "Cadiz", "Cordoba", "Malaga" van sin ellas desde su carga);
-- PROVINCIA usa la forma de Mapa::PROVINCIAS, igual que municipio.PROVINCIA.
--
-- Idempotente (CREATE ... IF NOT EXISTS + INSERT OR IGNORE): lo aplica
-- migrate_ingest.php, que re-ejecuta todos los .sql a ciegas en cada despliegue.
CREATE TABLE IF NOT EXISTS localidad_provincia (
    LOCALIDAD TEXT PRIMARY KEY,
    PROVINCIA TEXT NOT NULL
);

-- Semilla: las localidades con datos en local a 2026-09-29, todas cotejadas
-- con el catálogo de municipios. /dashboard/semana-santa solo deja crear
-- localidades con su provincia; esto casa las que ya había. En un host donde
-- alguna no exista, la fila sobra pero no molesta.
INSERT OR IGNORE INTO localidad_provincia (LOCALIDAD, PROVINCIA) VALUES
    ('Alcalá de Guadaíra', 'Sevilla'),
    ('Alcalá del Río', 'Sevilla'),
    ('Algeciras', 'Cádiz'),
    ('Almería', 'Almería'),
    ('Badolatosa', 'Sevilla'),
    ('Cadiz', 'Cádiz'),
    ('Campillos', 'Málaga'),
    ('Chiclana de la Frontera', 'Cádiz'),
    ('Ciudad Real', 'Ciudad Real'),
    ('Cordoba', 'Córdoba'),
    ('Dos Hermanas', 'Sevilla'),
    ('El Puerto de Santa María', 'Cádiz'),
    ('Estepa', 'Sevilla'),
    ('Estepona', 'Málaga'),
    ('Granada', 'Granada'),
    ('Huelva', 'Huelva'),
    ('Huévar del Aljarafe', 'Sevilla'),
    ('Jaén', 'Jaén'),
    ('Jerez de la Frontera', 'Cádiz'),
    ('La Algaba', 'Sevilla'),
    ('La Rinconada', 'Sevilla'),
    ('Mairena del Alcor', 'Sevilla'),
    ('Malaga', 'Málaga'),
    ('Molares (Los)', 'Sevilla'),
    ('Moriles', 'Córdoba'),
    ('Morón de la Frontera', 'Sevilla'),
    ('Osuna', 'Sevilla'),
    ('Palma del Río', 'Córdoba'),
    ('Paradas', 'Sevilla'),
    ('Purchil', 'Granada'),
    ('San Fernando', 'Cádiz'),
    ('Sanlúcar de Barrameda', 'Cádiz'),
    ('Sevilla', 'Sevilla'),
    ('Tarifa', 'Cádiz'),
    ('Tomares', 'Sevilla'),
    ('Trebujena', 'Cádiz'),
    ('Utrera', 'Sevilla'),
    ('Écija', 'Sevilla');
