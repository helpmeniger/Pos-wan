-- Retour arrière de la migration 0005 (Reconnaissance 1.4). À n'exécuter qu'après une sauvegarde :
-- les déplacements et dépôts enregistrés, les comptes vus et le nombre de places des emplacements sont perdus.
DELETE FROM vision_events WHERE direction IN ('deplace','depose');
ALTER TABLE vision_events DROP COLUMN to_slot_id, DROP COLUMN qty, MODIFY direction ENUM('sortie','entree','vu','prise','remis') NOT NULL;
ALTER TABLE vision_slots DROP COLUMN seen_at, DROP COLUMN seen_count, DROP COLUMN min_count, DROP COLUMN units;
