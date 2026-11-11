-- Lien entre le personnel de l'ONG et les employés du module Ressources humaines (une seule fiche à tenir)

ALTER TABLE ong_personnel ADD COLUMN grh_employe_id INT UNSIGNED NULL AFTER id;

ALTER TABLE ong_personnel ADD UNIQUE KEY uq_ong_personnel_grh (grh_employe_id);
