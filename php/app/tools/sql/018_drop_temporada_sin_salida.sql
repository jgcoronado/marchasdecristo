-- Elimina `temporada_sin_salida` (2026-09-24): el usuario decidió quitar la
-- tabla y el aviso público "No hubo salida (COVID)" — ver
-- 014_temporada_sin_salida.sql (ahora neutralizada), Repo::aniosSinSalida()
-- (eliminado) y sus llamadores en Pages/Admin/NominaRepo.
--
-- DROP en vez de dejar la tabla huérfana: no la usa ya ningún código y no hay
-- FK de otra tabla hacia ella. Si algún host todavía no la tenía creada (nunca
-- llegó a ejecutar 014), este DROP IF EXISTS no hace nada.
DROP TABLE IF EXISTS temporada_sin_salida;
