-- Sincronización permanente marcha.LOCALIDAD <-> dedicatoria_alias.LOCALIDAD.
--
-- El enlace marcha -> dedicatoria es por texto exacto del par (VARIANTE,
-- LOCALIDAD) contra dedicatoria_alias (ver 003_dedicatoria.sql):
--
--   marcha m JOIN dedicatoria_alias da
--     ON da.VARIANTE = m.DEDICATORIA AND da.LOCALIDAD = COALESCE(m.LOCALIDAD, '')
--
-- Si algo renombra marcha.LOCALIDAD (un script de limpieza, o editar la
-- localidad de una marcha desde el panel admin) sin tocar también
-- dedicatoria_alias.LOCALIDAD, esa marcha desaparece en silencio de la ficha
-- de su dedicatoria (ver app/tools/reconciliar_alias_localidad.php, que
-- corrige el desfase ya existente). Este trigger evita que vuelva a pasar:
-- cuando el UPDATE de una marcha cambia su LOCALIDAD, propaga el cambio al
-- alias correspondiente, siempre que sea un caso inequívoco:
--
--   1) Ninguna OTRA marcha con la misma DEDICATORIA sigue usando la
--      localidad vieja (si la sigue usando, el alias vieja todavía hace
--      falta para esas otras marchas — no se toca).
--   2) No existe ya un alias para (VARIANTE, localidad nueva) — si existe,
--      esa marcha pasa a coincidir con un alias que ya apuntaba a otra
--      dedicatoria (reasignación real, no un simple renombrado de texto);
--      no se sobrescribe a ciegas.
--
-- Con un UPDATE que afecta a varias marchas con la misma DEDICATORIA (el
-- caso normal de los scripts de limpieza), SQLite dispara este trigger una
-- vez por fila; el renombrado del alias solo se completa cuando ya no
-- queda ninguna fila usando la localidad vieja (sea cual sea el orden en
-- que el motor procese las filas del UPDATE).
--
-- Casos fuera de estas dos condiciones (varias marchas de la misma
-- DEDICATORIA repartidas entre localidades distintas y ninguna llega a
-- "liberar" del todo la vieja, o colisión real con un alias existente) se
-- quedan igual que estaban: es preferible no actuar a adivinar mal, y
-- reconciliar_alias_localidad.php los seguirá listando para revisión manual.
CREATE TRIGGER IF NOT EXISTS trg_marcha_localidad_sync_alias
AFTER UPDATE OF LOCALIDAD ON marcha
WHEN NEW.DEDICATORIA IS NOT NULL AND NEW.DEDICATORIA != ''
     AND COALESCE(NEW.LOCALIDAD, '') != COALESCE(OLD.LOCALIDAD, '')
BEGIN
    UPDATE dedicatoria_alias
       SET LOCALIDAD = COALESCE(NEW.LOCALIDAD, '')
     WHERE VARIANTE = NEW.DEDICATORIA
       AND LOCALIDAD = COALESCE(OLD.LOCALIDAD, '')
       AND NOT EXISTS (
             SELECT 1 FROM marcha m2
              WHERE m2.ID_MARCHA != NEW.ID_MARCHA
                AND m2.DEDICATORIA = NEW.DEDICATORIA
                AND COALESCE(m2.LOCALIDAD, '') = COALESCE(OLD.LOCALIDAD, '')
           )
       AND NOT EXISTS (
             SELECT 1 FROM dedicatoria_alias da2
              WHERE da2.VARIANTE = NEW.DEDICATORIA
                AND da2.LOCALIDAD = COALESCE(NEW.LOCALIDAD, '')
           );
END;
