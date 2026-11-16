-- Reconnaissance 1.4 : comptage des articles à leur place (emplacement découpé en places), quantité par geste,
-- article déplacé sur le rayon ou posé hors de sa place, rayons à réassortir.
-- Aucune table n'est vidée ; un emplacement existant compte 1 place (fonctionnement 1.3 inchangé).

ALTER TABLE vision_slots
  ADD COLUMN units TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER product_id,
  ADD COLUMN min_count TINYINT UNSIGNED NULL AFTER units,
  ADD COLUMN seen_count TINYINT UNSIGNED NULL AFTER min_count,
  ADD COLUMN seen_at DATETIME NULL AFTER seen_count;

ALTER TABLE vision_events
  MODIFY direction ENUM('sortie','entree','vu','prise','remis','deplace','depose') NOT NULL,
  ADD COLUMN qty SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER direction,
  ADD COLUMN to_slot_id INT UNSIGNED NULL AFTER slot_id;
