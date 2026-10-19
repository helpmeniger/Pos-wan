-- Reconnaissance 1.2 : annulation d'un ajout automatique enregistrée dans le journal
-- (les empreintes « objet isolé » utilisent la colonne vision_refs.model existante : 'mnv2-p512-obj')
ALTER TABLE vision_log MODIFY outcome ENUM('confirme','corrige','auto','aucun','annule') NOT NULL;
-- taille apparente de l'article isolé (0..1, caméra fixe avec fond appris) : distingue 33 cl / 50 cl
ALTER TABLE vision_refs ADD COLUMN size DECIMAL(4,3) NULL AFTER model;
