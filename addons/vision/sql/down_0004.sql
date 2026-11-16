-- Retour arrière de la migration 0004 (Reconnaissance 1.3). À n'exécuter qu'après une sauvegarde :
-- les suivis rayon → caisse et les emplacements tracés sont perdus.
DROP TABLE IF EXISTS vision_journeys;
DROP TABLE IF EXISTS vision_slots;
DELETE FROM vision_events WHERE direction IN ('prise','remis');
ALTER TABLE vision_events DROP COLUMN slot_id, MODIFY direction ENUM('sortie','entree','vu') NOT NULL;
ALTER TABLE vision_cameras DROP COLUMN register_id, DROP COLUMN place, DROP COLUMN store_id, DROP COLUMN role;
