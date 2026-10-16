-- =============================================================================
-- Niger Parcelles - DONNÉES DE DÉMONSTRATION FICTIVES
-- Générées par tools/generate_demo_data.php. NE PAS utiliser comme données réelles.
-- Les parcelles sont des rectangles dessinés arbitrairement près de Niamey ;
-- les propriétaires sont des personnes inventées. Tout est marqué is_demo = 1.
-- À importer APRÈS schema.sql.
-- =============================================================================
SET NAMES utf8mb4;

INSERT INTO owners (id, owner_type, first_name, last_name, organization_name, phone, address, file_reference, info_status, notes, is_demo) VALUES
(9001, 'personne_physique', 'Exemple', 'DÉMO-ALPHA', NULL, '+227 00 00 00 01', '[DÉMO - FICTIF] Adresse inventée', 'DEMO-DOS-1', 'declare', '[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.', 1),
(9002, 'personne_physique', 'Exemple', 'DÉMO-BETA', NULL, '+227 00 00 00 02', '[DÉMO - FICTIF] Adresse inventée', 'DEMO-DOS-2', 'documente', '[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.', 1),
(9003, 'personne_physique', 'Exemple', 'DÉMO-GAMMA', NULL, '+227 00 00 00 03', '[DÉMO - FICTIF] Adresse inventée', 'DEMO-DOS-3', 'verifie', '[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.', 1),
(9004, 'personne_morale', NULL, NULL, 'Société fictive DÉMO SARL', '+227 00 00 00 04', '[DÉMO - FICTIF] Adresse inventée', 'DEMO-DOS-4', 'documente', '[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.', 1),
(9005, 'groupement', NULL, NULL, 'Famille fictive DÉMO', NULL, '[DÉMO - FICTIF] Adresse inventée', 'DEMO-DOS-5', 'conteste', '[DÉMO - FICTIF] Propriétaire inventé pour la démonstration.', 1);

INSERT INTO parcels (id, parcel_reference, cadastral_reference, administrative_area_id, locality_description, category, area_m2, perimeter_m,
  centroid_lat, centroid_lng, min_lat, max_lat, min_lng, max_lng, current_geometry_version, survey_level, declared_status, verification_status,
  has_overlap_warning, description, is_demo, created_at) VALUES
(9001, 'NIG-NIA-2026-000001', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 1', 'residentiel', 600.43, 100.04, 13.4801357, 2.1550924, 13.4800000, 13.4802713, 2.1550000, 2.1551848, 1, 'verifiee_autorite', 'attestation_coutumiere', 'verifiee', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-01-03 09:10:00'),
(9002, 'NIG-NIA-2026-000002', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 2', 'residentiel', 600.43, 100.04, 13.4801357, 2.1552771, 13.4800000, 13.4802713, 2.1551848, 2.1553695, 1, 'releve_terrain', 'inconnu', 'soumise', 1, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-02-04 09:11:00'),
(9003, 'NIG-NIA-2026-000003', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 3', 'commercial', 600.46, 100.04, 13.4801357, 2.1554619, 13.4800000, 13.4802713, 2.1553695, 2.1555543, 1, 'documentee', 'inconnu', 'en_verification', 1, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-03-05 09:12:00'),
(9004, 'NIG-NIA-2026-000004', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 4', 'terrain_nu', 600.43, 100.04, 13.4801357, 2.1556466, 13.4800000, 13.4802713, 2.1555543, 2.1557390, 1, 'indicative', 'attestation_coutumiere', 'brouillon', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-04-06 09:13:00'),
(9005, 'NIG-NIA-2026-000005', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 5', 'en_construction', 600.43, 100.04, 13.4801357, 2.1558314, 13.4800000, 13.4802713, 2.1557390, 2.1559238, 1, 'documentee', 'inconnu', 'verifiee', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-05-07 09:14:00'),
(9006, 'NIG-NIA-2026-000006', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 6', 'residentiel', 600.42, 100.04, 13.4804070, 2.1550924, 13.4802713, 13.4805426, 2.1550000, 2.1551848, 1, 'releve_terrain', 'inconnu', 'anomalie', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-06-08 09:15:00'),
(9007, 'NIG-NIA-2026-000007', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 7', 'agricole', 600.42, 100.04, 13.4804070, 2.1552771, 13.4802713, 13.4805426, 2.1551848, 2.1553695, 1, 'indicative', 'attestation_coutumiere', 'brouillon', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-07-09 09:16:00'),
(9008, 'NIG-NIA-2026-000008', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 8', 'administratif', 600.46, 100.04, 13.4804070, 2.1554619, 13.4802713, 13.4805426, 2.1553695, 2.1555543, 1, 'verifiee_autorite', 'inconnu', 'verifiee', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-08-10 09:17:00'),
(9009, 'NIG-NIA-2026-000009', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 9', 'residentiel', 600.42, 100.04, 13.4804070, 2.1556466, 13.4802713, 13.4805426, 2.1555543, 2.1557390, 1, 'releve_terrain', 'inconnu', 'soumise', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-09-11 09:18:00'),
(9010, 'NIG-NIA-2026-000010', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 10', 'terrain_nu', 600.42, 100.04, 13.4804070, 2.1558314, 13.4802713, 13.4805426, 2.1557390, 2.1559238, 1, 'indicative', 'attestation_coutumiere', 'brouillon', 0, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-01-12 09:19:00'),
(9011, 'NIG-NIA-2026-000011', NULL, 6, '[DÉMO - FICTIF] Lot de démonstration n° 11', 'commercial', 300.22, 70.02, 13.4801492, 2.1553695, 13.4800814, 13.4802171, 2.1552771, 2.1554619, 1, 'indicative', 'inconnu', 'anomalie', 1, '[DÉMONSTRATION - données fictives] Cette parcelle n''existe pas.', 1, '2026-02-13 09:20:00');

INSERT INTO parcel_geometries (parcel_id, geojson, geometry_version, vertex_count, area_m2, perimeter_m, source, source_crs, change_reason, created_at) VALUES
(9001, '{"type":"Polygon","coordinates":[[[2.155,13.48],[2.15518475,13.48],[2.15518475,13.48027131],[2.155,13.48027131],[2.155,13.48]]]}', 1, 4, 600.43, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-01-03 09:10:00'),
(9002, '{"type":"Polygon","coordinates":[[[2.15518475,13.48],[2.1553695,13.48],[2.1553695,13.48027131],[2.15518475,13.48027131],[2.15518475,13.48]]]}', 1, 4, 600.43, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-02-04 09:11:00'),
(9003, '{"type":"Polygon","coordinates":[[[2.1553695,13.48],[2.15555426,13.48],[2.15555426,13.48027131],[2.1553695,13.48027131],[2.1553695,13.48]]]}', 1, 4, 600.46, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-03-05 09:12:00'),
(9004, '{"type":"Polygon","coordinates":[[[2.15555426,13.48],[2.15573901,13.48],[2.15573901,13.48027131],[2.15555426,13.48027131],[2.15555426,13.48]]]}', 1, 4, 600.43, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-04-06 09:13:00'),
(9005, '{"type":"Polygon","coordinates":[[[2.15573901,13.48],[2.15592376,13.48],[2.15592376,13.48027131],[2.15573901,13.48027131],[2.15573901,13.48]]]}', 1, 4, 600.43, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-05-07 09:14:00'),
(9006, '{"type":"Polygon","coordinates":[[[2.155,13.48027131],[2.15518475,13.48027131],[2.15518475,13.48054262],[2.155,13.48054262],[2.155,13.48027131]]]}', 1, 4, 600.42, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-06-08 09:15:00'),
(9007, '{"type":"Polygon","coordinates":[[[2.15518475,13.48027131],[2.1553695,13.48027131],[2.1553695,13.48054262],[2.15518475,13.48054262],[2.15518475,13.48027131]]]}', 1, 4, 600.42, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-07-09 09:16:00'),
(9008, '{"type":"Polygon","coordinates":[[[2.1553695,13.48027131],[2.15555426,13.48027131],[2.15555426,13.48054262],[2.1553695,13.48054262],[2.1553695,13.48027131]]]}', 1, 4, 600.46, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-08-10 09:17:00'),
(9009, '{"type":"Polygon","coordinates":[[[2.15555426,13.48027131],[2.15573901,13.48027131],[2.15573901,13.48054262],[2.15555426,13.48054262],[2.15555426,13.48027131]]]}', 1, 4, 600.42, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-09-11 09:18:00'),
(9010, '{"type":"Polygon","coordinates":[[[2.15573901,13.48027131],[2.15592376,13.48027131],[2.15592376,13.48054262],[2.15573901,13.48054262],[2.15573901,13.48027131]]]}', 1, 4, 600.42, 100.04, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-01-12 09:19:00'),
(9011, '{"type":"Polygon","coordinates":[[[2.15527713,13.48008139],[2.15546188,13.48008139],[2.15546188,13.48021705],[2.15527713,13.48021705],[2.15527713,13.48008139]]]}', 1, 4, 300.22, 70.02, 'dessin', 'EPSG:4326', 'Création (démonstration)', '2026-02-13 09:20:00');

INSERT INTO parcel_history (parcel_id, user_id, action_type, previous_data, new_data, created_at) VALUES
(9001, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000001","demo":true}', '2026-01-03 09:10:00'),
(9002, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000002","demo":true}', '2026-02-04 09:11:00'),
(9003, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000003","demo":true}', '2026-03-05 09:12:00'),
(9004, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000004","demo":true}', '2026-04-06 09:13:00'),
(9005, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000005","demo":true}', '2026-05-07 09:14:00'),
(9006, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000006","demo":true}', '2026-06-08 09:15:00'),
(9007, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000007","demo":true}', '2026-07-09 09:16:00'),
(9008, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000008","demo":true}', '2026-08-10 09:17:00'),
(9009, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000009","demo":true}', '2026-09-11 09:18:00'),
(9010, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000010","demo":true}', '2026-01-12 09:19:00'),
(9011, NULL, 'creation', NULL, '{"parcel_reference":"NIG-NIA-2026-000011","demo":true}', '2026-02-13 09:20:00');

INSERT INTO parcel_overlaps (parcel_a_id, parcel_b_id, overlap_type, status) VALUES (9002, 9011, 'chevauchement', 'a_verifier'), (9003, 9011, 'chevauchement', 'a_verifier');

-- Liens propriétaires (dont une copropriété 60/40 et une mutation historique)
INSERT INTO mutations (id, parcel_id, mutation_type, mutation_date, document_reference, notes) VALUES
(9001, 9001, 'vente', '2026-02-15', 'DEMO-ACTE-0001', '[DÉMO - FICTIF] Vente fictive pour illustrer l''historique.');
INSERT INTO parcel_owners (parcel_id, owner_id, ownership_role, share_percentage, valid_from, valid_to, verification_status, document_reference, mutation_in_id, mutation_out_id) VALUES
(9001, 9001, 'proprietaire', NULL, '2023-05-01', '2026-02-15', 'documente', 'DEMO-ATT-0001', NULL, 9001),
(9001, 9003, 'proprietaire', NULL, '2026-02-15', NULL, 'verifie', 'DEMO-ACTE-0001', 9001, NULL),
(9002, 9001, 'coproprietaire', 60.000, '2025-01-10', NULL, 'declare', NULL, NULL, NULL),
(9002, 9002, 'coproprietaire', 40.000, '2025-01-10', NULL, 'documente', 'DEMO-ACTE-0002', NULL, NULL),
(9003, 9004, 'proprietaire', NULL, '2024-03-20', NULL, 'documente', 'DEMO-CONT-0003', NULL, NULL),
(9005, 9002, 'proprietaire', NULL, NULL, NULL, 'declare', NULL, NULL, NULL),
(9006, 9005, 'ayant_droit', NULL, NULL, NULL, 'conteste', NULL, NULL, NULL),
(9008, 9003, 'proprietaire', NULL, '2021-07-01', NULL, 'verifie', 'DEMO-TF-0008', NULL, NULL);

INSERT INTO parcel_reference_sequences (year, seq_value) VALUES (2026, 11)
  ON DUPLICATE KEY UPDATE seq_value = GREATEST(seq_value, VALUES(seq_value));

