-- =============================================================================
--  JOB NIGER — Catalogue des métiers par défaut (21 domaines A → U)
--  Généré : tout reste administrable depuis le tableau de bord (ajout,
--  modification, désactivation, rattachement à une ville / type de mission).
--  risk_level : LOW | MEDIUM | HIGH (dangereux / personnes vulnérables) | REGULATED
-- =============================================================================
USE job_niger;
SET NAMES utf8mb4;

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('commerce-distribution', 'Commerce et distribution', 'bi-shop', NULL, 10);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'vendeur-temporaire', 'Vendeur temporaire', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'caissier', 'Caissier', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'assistant-commercial', 'Assistant commercial', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'agent-de-promotion', 'Agent de promotion', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'commercial-de-terrain', 'Commercial de terrain', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'inventoriste', 'Inventoriste', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'magasinier', 'Magasinier', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'emballeur', 'Emballeur', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'etiqueteur', 'Étiqueteur', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'agent-de-mise-en-rayon', 'Agent de mise en rayon', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'agent-de-livraison-interne', 'Agent de livraison interne', 'LOW', 18, 0, 0, 0, 0, 110),
  (@c, 'assistant-de-boutique', 'Assistant de boutique', 'LOW', 18, 0, 0, 0, 0, 120),
  (@c, 'agent-de-recensement-de-produits', 'Agent de recensement de produits', 'LOW', 18, 0, 0, 0, 0, 130);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('restauration-alimentation', 'Restauration et alimentation', 'bi-cup-hot', 'Respect des règles d''hygiène alimentaire. Les conditions de travail en cuisine (chaleur, objets tranchants) doivent être précisées.', 20);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'serveur', 'Serveur', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'aide-cuisinier', 'Aide-cuisinier', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'cuisinier', 'Cuisinier', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'plongeur', 'Plongeur', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'boulanger', 'Boulanger', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'patissier', 'Pâtissier', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'preparateur-de-jus', 'Préparateur de jus', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'vendeur-de-nourriture', 'Vendeur de nourriture', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'assistant-de-restaurant', 'Assistant de restaurant', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'agent-de-nettoyage-de-cuisine', 'Agent de nettoyage de cuisine', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'serveur-pour-ceremonies', 'Serveur pour cérémonies', 'LOW', 18, 0, 0, 0, 0, 110),
  (@c, 'personnel-temporaire-de-cantine', 'Personnel temporaire de cantine', 'LOW', 18, 0, 0, 0, 0, 120);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('hotellerie-tourisme', 'Hôtellerie et tourisme', 'bi-building', NULL, 30);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'receptionniste-remplacant', 'Réceptionniste remplaçant', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'bagagiste', 'Bagagiste', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'agent-d-entretien-hotelier', 'Agent d''entretien hôtelier', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'assistant-de-reservation', 'Assistant de réservation', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'guide-touristique-qualifie', 'Guide touristique qualifié', 'MEDIUM', 18, 2, 1, 0, 0, 50),
  (@c, 'assistant-d-organisation-de-voyages', 'Assistant d''organisation de voyages', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'personnel-temporaire-d-hotel', 'Personnel temporaire d''hôtel', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'aide-a-l-accueil-de-groupes', 'Aide à l''accueil de groupes', 'LOW', 18, 0, 0, 0, 0, 80);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('evenementiel-ceremonies', 'Événementiel et cérémonies', 'bi-balloon', NULL, 40);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'serveur-de-ceremonie', 'Serveur de cérémonie', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'hote-ou-hotesse-d-accueil', 'Hôte ou hôtesse d''accueil', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'agent-d-installation-de-chaises', 'Agent d''installation de chaises', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'decorateur', 'Décorateur', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'assistant-de-decoration', 'Assistant de décoration', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'agent-de-securite-autorise', 'Agent de sécurité autorisé', 'REGULATED', 21, 3, 1, 0, 0, 60),
  (@c, 'assistant-logistique-evenementiel', 'Assistant logistique événementiel', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'monteur-de-scene', 'Monteur de scène', 'HIGH', 18, 1, 0, 0, 0, 80),
  (@c, 'agent-d-orientation-des-invites', 'Agent d''orientation des invités', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'aide-a-l-organisation-de-mariages', 'Aide à l''organisation de mariages', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'personnel-de-salon-professionnel', 'Personnel de salon professionnel', 'LOW', 18, 0, 0, 0, 0, 110),
  (@c, 'agent-de-distribution-de-documents', 'Agent de distribution de documents', 'LOW', 18, 0, 0, 0, 0, 120);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('livraison-logistique', 'Livraison et logistique', 'bi-truck', 'Les activités de transport doivent respecter les exigences légales (permis, assurance, équipements de sécurité dont le casque).', 50);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'livreur-a-pied', 'Livreur à pied', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'livreur-a-moto', 'Livreur à moto', 'REGULATED', 18, 2, 1, 0, 0, 20),
  (@c, 'coursier', 'Coursier', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'agent-de-collecte-de-colis', 'Agent de collecte de colis', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'assistant-de-demenagement', 'Assistant de déménagement', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'manutentionnaire', 'Manutentionnaire', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'chargeur-et-dechargeur', 'Chargeur et déchargeur', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'agent-de-stockage', 'Agent de stockage', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'assistant-d-expedition', 'Assistant d''expédition', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'agent-d-inventaire', 'Agent d''inventaire', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'aide-magasinier', 'Aide-magasinier', 'LOW', 18, 0, 0, 0, 0, 110);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('nettoyage-entretien', 'Nettoyage et entretien', 'bi-stars', 'La mission doit préciser le matériel fourni, les produits utilisés et les conditions d''intervention.', 60);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'nettoyage-de-maisons', 'Nettoyage de maisons', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'nettoyage-de-bureaux', 'Nettoyage de bureaux', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'nettoyage-de-boutiques', 'Nettoyage de boutiques', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'nettoyage-apres-ceremonie', 'Nettoyage après cérémonie', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'lavage-de-vitres', 'Lavage de vitres', 'MEDIUM', 18, 0, 0, 0, 0, 50),
  (@c, 'nettoyage-de-cours', 'Nettoyage de cours', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'entretien-de-jardins', 'Entretien de jardins', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'lavage-de-vehicules', 'Lavage de véhicules', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'nettoyage-de-tapis', 'Nettoyage de tapis', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'aide-au-rangement', 'Aide au rangement', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'entretien-de-locaux-professionnels', 'Entretien de locaux professionnels', 'LOW', 18, 0, 0, 0, 0, 110);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('travaux-domestiques', 'Travaux domestiques et aide aux particuliers', 'bi-house-heart', 'Les services impliquant des enfants ou des personnes vulnérables exigent une vérification d''identité et des règles de sécurité spécifiques.', 70);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'aide-au-rangement-a-domicile', 'Aide au rangement à domicile', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'assistant-pour-les-courses', 'Assistant pour les courses', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'aide-ponctuelle-lors-d-un-demenagement', 'Aide ponctuelle lors d''un déménagement', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'preparation-de-repas', 'Préparation de repas', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'assistance-lors-d-une-reception', 'Assistance lors d''une réception', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'aide-aux-taches-domestiques', 'Aide aux tâches domestiques', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'jardinage', 'Jardinage', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'petits-travaux-d-entretien', 'Petits travaux d''entretien', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'assistance-informatique-a-domicile', 'Assistance informatique à domicile', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'garde-d-enfants', 'Garde d''enfants', 'HIGH', 18, 2, 0, 1, 0, 100),
  (@c, 'accompagnement-de-personne-agee', 'Accompagnement de personne âgée', 'HIGH', 18, 2, 0, 1, 0, 110);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('construction-batiment', 'Construction et bâtiment', 'bi-bricks', 'Travaux électriques, en hauteur et autres activités dangereuses : réservés aux personnes qualifiées et équipées (EPI obligatoires).', 80);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'manoeuvre', 'Manœuvre', 'MEDIUM', 18, 1, 0, 0, 0, 10),
  (@c, 'aide-macon', 'Aide-maçon', 'MEDIUM', 18, 1, 0, 0, 0, 20),
  (@c, 'assistant-peintre', 'Assistant peintre', 'MEDIUM', 18, 0, 0, 0, 0, 30),
  (@c, 'aide-carreleur', 'Aide-carreleur', 'MEDIUM', 18, 0, 0, 0, 0, 40),
  (@c, 'assistant-plombier', 'Assistant plombier', 'MEDIUM', 18, 1, 0, 0, 0, 50),
  (@c, 'assistant-electricien', 'Assistant électricien', 'HIGH', 18, 2, 1, 0, 0, 60),
  (@c, 'manutentionnaire-de-chantier', 'Manutentionnaire de chantier', 'MEDIUM', 18, 1, 0, 0, 0, 70),
  (@c, 'aide-menuisier', 'Aide-menuisier', 'MEDIUM', 18, 1, 0, 0, 0, 80),
  (@c, 'agent-de-nettoyage-de-chantier', 'Agent de nettoyage de chantier', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'aide-a-la-pose-de-materiaux', 'Aide à la pose de matériaux', 'MEDIUM', 18, 1, 0, 0, 0, 100),
  (@c, 'travail-en-hauteur-qualifie', 'Travail en hauteur qualifié', 'HIGH', 18, 2, 1, 0, 0, 110);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('reparation-artisanat', 'Réparation et artisanat', 'bi-tools', NULL, 90);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'reparateur-de-telephones', 'Réparateur de téléphones', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'technicien-informatique', 'Technicien informatique', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'assistant-de-maintenance', 'Assistant de maintenance', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'reparateur-de-petits-appareils', 'Réparateur de petits appareils', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'couturier', 'Couturier', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'assistant-de-couture', 'Assistant de couture', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'menuisier', 'Menuisier', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'soudeur-qualifie', 'Soudeur qualifié', 'HIGH', 18, 2, 1, 0, 0, 80),
  (@c, 'mecanicien', 'Mécanicien', 'MEDIUM', 18, 1, 0, 0, 0, 90),
  (@c, 'vulcanisateur', 'Vulcanisateur', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'reparateur-de-velos', 'Réparateur de vélos', 'LOW', 18, 0, 0, 0, 0, 110),
  (@c, 'artisan-creatif', 'Artisan créatif', 'LOW', 18, 0, 0, 0, 0, 120);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('informatique-numerique', 'Informatique et numérique', 'bi-laptop', NULL, 100);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'saisie-de-donnees', 'Saisie de données', 'LOW', 16, 0, 0, 0, 1, 10),
  (@c, 'operateur-de-saisie', 'Opérateur de saisie', 'LOW', 16, 0, 0, 0, 1, 20),
  (@c, 'assistant-bureautique', 'Assistant bureautique', 'LOW', 18, 0, 0, 0, 1, 30),
  (@c, 'mise-en-page-de-documents', 'Mise en page de documents', 'LOW', 16, 0, 0, 0, 1, 40),
  (@c, 'creation-de-presentations', 'Création de présentations', 'LOW', 16, 0, 0, 0, 1, 50),
  (@c, 'assistance-informatique', 'Assistance informatique', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'installation-de-logiciels', 'Installation de logiciels', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'creation-de-sites-web', 'Création de sites web', 'LOW', 18, 0, 0, 0, 1, 80),
  (@c, 'developpement-informatique', 'Développement informatique', 'LOW', 18, 0, 0, 0, 1, 90),
  (@c, 'gestion-de-pages-sociales', 'Gestion de pages sociales', 'LOW', 18, 0, 0, 0, 1, 100),
  (@c, 'moderation-de-contenus', 'Modération de contenus', 'LOW', 18, 1, 0, 0, 1, 110),
  (@c, 'assistance-a-distance', 'Assistance à distance', 'LOW', 18, 0, 0, 0, 1, 120),
  (@c, 'numerisation-de-documents', 'Numérisation de documents', 'LOW', 18, 0, 0, 0, 0, 130),
  (@c, 'transcription-audio', 'Transcription audio', 'LOW', 16, 0, 0, 0, 1, 140),
  (@c, 'traitement-de-fichiers-excel', 'Traitement de fichiers Excel', 'LOW', 16, 0, 0, 0, 1, 150);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('communication-audiovisuel', 'Communication et audiovisuel', 'bi-camera-reels', NULL, 110);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'photographe', 'Photographe', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'assistant-photographe', 'Assistant photographe', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'videaste', 'Vidéaste', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'assistant-de-tournage', 'Assistant de tournage', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'monteur-video', 'Monteur vidéo', 'LOW', 18, 0, 0, 0, 1, 50),
  (@c, 'graphiste', 'Graphiste', 'LOW', 18, 0, 0, 0, 1, 60),
  (@c, 'createur-d-affiches', 'Créateur d''affiches', 'LOW', 16, 0, 0, 0, 1, 70),
  (@c, 'assistant-de-production', 'Assistant de production', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'cameraman', 'Cameraman', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'cadreur', 'Cadreur', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'assistant-son', 'Assistant son', 'LOW', 18, 0, 0, 0, 0, 110),
  (@c, 'community-manager', 'Community manager', 'LOW', 18, 0, 0, 0, 1, 120),
  (@c, 'redacteur-web', 'Rédacteur web', 'LOW', 18, 0, 0, 0, 1, 130),
  (@c, 'traducteur', 'Traducteur', 'LOW', 18, 0, 0, 0, 1, 140),
  (@c, 'animateur-d-evenements', 'Animateur d''événements', 'LOW', 18, 0, 0, 0, 0, 150),
  (@c, 'operateur-drone-autorise', 'Opérateur drone autorisé', 'REGULATED', 18, 3, 1, 0, 0, 160);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('education-soutien-scolaire', 'Éducation et soutien scolaire', 'bi-mortarboard', 'Travail auprès de mineurs : identité vérifiée et compétences contrôlées obligatoires.', 120);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'repetiteur', 'Répétiteur', 'HIGH', 18, 2, 0, 1, 0, 10),
  (@c, 'tuteur-scolaire', 'Tuteur scolaire', 'HIGH', 18, 2, 0, 1, 1, 20),
  (@c, 'assistant-de-cours', 'Assistant de cours', 'HIGH', 18, 2, 0, 1, 0, 30),
  (@c, 'formateur-en-informatique', 'Formateur en informatique', 'MEDIUM', 18, 1, 0, 0, 1, 40),
  (@c, 'assistant-de-bibliotheque', 'Assistant de bibliothèque', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'aide-a-la-preparation-d-exercices', 'Aide à la préparation d''exercices', 'MEDIUM', 18, 1, 0, 0, 1, 60),
  (@c, 'enseignant-particulier-qualifie', 'Enseignant particulier qualifié', 'HIGH', 18, 2, 1, 1, 1, 70),
  (@c, 'formateur-en-langues', 'Formateur en langues', 'MEDIUM', 18, 1, 0, 0, 1, 80),
  (@c, 'assistant-de-saisie-de-resultats', 'Assistant de saisie de résultats', 'LOW', 18, 0, 0, 0, 0, 90);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('agriculture-elevage', 'Agriculture et élevage', 'bi-flower1', 'Préciser la manipulation éventuelle de produits phytosanitaires (réservée aux personnes formées et équipées).', 130);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'aide-aux-semis', 'Aide aux semis', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'travailleur-agricole-saisonnier', 'Travailleur agricole saisonnier', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'aide-a-la-recolte', 'Aide à la récolte', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'agent-de-tri-de-produits-agricoles', 'Agent de tri de produits agricoles', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'manutentionnaire-agricole', 'Manutentionnaire agricole', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'assistant-de-pepiniere', 'Assistant de pépinière', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'aide-a-l-irrigation', 'Aide à l''irrigation', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'agent-d-entretien-de-ferme', 'Agent d''entretien de ferme', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'assistant-d-elevage', 'Assistant d''élevage', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'agent-de-conditionnement', 'Agent de conditionnement', 'LOW', 18, 0, 0, 0, 0, 100),
  (@c, 'aide-au-stockage-de-recoltes', 'Aide au stockage de récoltes', 'LOW', 18, 0, 0, 0, 0, 110);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('sante-bien-etre', 'Santé et bien-être (non médical)', 'bi-heart-pulse', 'Aucun acte médical, soin ou prescription : réservés aux professionnels de santé dûment autorisés.', 140);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'agent-d-accueil-non-medical', 'Agent d''accueil non médical', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'assistant-administratif-de-cabinet', 'Assistant administratif de cabinet', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'agent-de-nettoyage-de-locaux-sanitaires', 'Agent de nettoyage de locaux sanitaires', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'assistant-logistique-de-sante', 'Assistant logistique de santé', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'aide-a-la-prise-de-rendez-vous', 'Aide à la prise de rendez-vous', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'agent-de-distribution-de-documents-de-sante', 'Agent de distribution de documents de santé', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'professionnel-de-sante-autorise', 'Professionnel de santé autorisé', 'REGULATED', 21, 3, 1, 1, 0, 70);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('transport-automobile', 'Transport et automobile', 'bi-car-front', 'Conduite : permis valide, assurance et autorisations requises.', 150);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'assistant-de-garage', 'Assistant de garage', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'aide-au-lavage-automobile', 'Aide au lavage automobile', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'assistant-de-parc-automobile', 'Assistant de parc automobile', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'agent-d-accueil-de-garage', 'Agent d''accueil de garage', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'preparateur-de-vehicules', 'Préparateur de véhicules', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'assistant-de-stationnement', 'Assistant de stationnement', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'conducteur-professionnel-autorise', 'Conducteur professionnel autorisé', 'REGULATED', 21, 3, 1, 0, 0, 70),
  (@c, 'convoyeur-de-vehicules-autorise', 'Convoyeur de véhicules autorisé', 'REGULATED', 21, 3, 1, 0, 0, 80);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('administration-secretariat', 'Administration et secrétariat', 'bi-folder2-open', NULL, 160);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'assistant-administratif', 'Assistant administratif', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'classement-de-dossiers', 'Classement de dossiers', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'archivage', 'Archivage', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'saisie-de-documents', 'Saisie de documents', 'LOW', 16, 0, 0, 0, 1, 40),
  (@c, 'numerisation', 'Numérisation', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'assistant-de-bureau', 'Assistant de bureau', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'standardiste-temporaire', 'Standardiste temporaire', 'LOW', 18, 0, 0, 0, 1, 70),
  (@c, 'assistant-de-gestion', 'Assistant de gestion', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'agent-d-accueil', 'Agent d''accueil', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'distribution-de-courriers', 'Distribution de courriers', 'LOW', 18, 0, 0, 0, 0, 100);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('etudes-enquetes-terrain', 'Études, enquêtes et missions de terrain', 'bi-clipboard-data', NULL, 170);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'enqueteur', 'Enquêteur', 'MEDIUM', 18, 1, 0, 0, 0, 10),
  (@c, 'agent-de-collecte-de-donnees', 'Agent de collecte de données', 'MEDIUM', 18, 1, 0, 0, 0, 20),
  (@c, 'agent-de-recensement', 'Agent de recensement', 'MEDIUM', 18, 1, 0, 0, 0, 30),
  (@c, 'intervieweur', 'Intervieweur', 'LOW', 18, 0, 0, 0, 1, 40),
  (@c, 'assistant-de-recherche', 'Assistant de recherche', 'LOW', 18, 0, 0, 0, 1, 50),
  (@c, 'agent-de-sensibilisation', 'Agent de sensibilisation', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'distributeur-de-questionnaires', 'Distributeur de questionnaires', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'assistant-de-suivi-de-projet', 'Assistant de suivi de projet', 'LOW', 18, 0, 0, 0, 0, 80),
  (@c, 'agent-de-terrain', 'Agent de terrain', 'LOW', 18, 0, 0, 0, 0, 90),
  (@c, 'assistant-d-etude-de-marche', 'Assistant d''étude de marché', 'LOW', 18, 0, 0, 0, 0, 100);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('mode-beaute', 'Mode et beauté', 'bi-scissors', NULL, 180);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'couturier-de-mode', 'Couturier de mode', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'assistant-de-styliste', 'Assistant de styliste', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'coiffeur-qualifie', 'Coiffeur qualifié', 'MEDIUM', 18, 1, 1, 0, 0, 30),
  (@c, 'maquilleur', 'Maquilleur', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'assistant-de-salon', 'Assistant de salon', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'mannequin-pour-campagne-commerciale', 'Mannequin pour campagne commerciale', 'MEDIUM', 18, 2, 0, 0, 0, 60),
  (@c, 'assistant-de-defile', 'Assistant de défilé', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'agent-de-retouche-de-vetements', 'Agent de retouche de vêtements', 'LOW', 18, 0, 0, 0, 0, 80);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('immobilier', 'Immobilier', 'bi-houses', 'Interdiction des pratiques frauduleuses (frais de visite injustifiés, fausses annonces). La rémunération doit être précisée.', 190);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'assistant-d-agence', 'Assistant d''agence', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'agent-d-accueil-immobilier', 'Agent d''accueil immobilier', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'assistant-de-visite-immobiliere', 'Assistant de visite immobilière', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'photographe-immobilier', 'Photographe immobilier', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'agent-de-collecte-de-documents', 'Agent de collecte de documents', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'assistant-de-prospection', 'Assistant de prospection', 'LOW', 18, 0, 0, 0, 0, 60);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('emplois-a-distance', 'Emplois à distance', 'bi-globe2', NULL, 200);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'assistant-virtuel', 'Assistant virtuel', 'LOW', 18, 0, 0, 0, 1, 10),
  (@c, 'saisie-de-donnees-a-distance', 'Saisie de données à distance', 'LOW', 16, 0, 0, 0, 1, 20),
  (@c, 'transcription', 'Transcription', 'LOW', 16, 0, 0, 0, 1, 30),
  (@c, 'traduction', 'Traduction', 'LOW', 18, 0, 0, 0, 1, 40),
  (@c, 'assistance-client', 'Assistance client', 'LOW', 18, 0, 0, 0, 1, 50),
  (@c, 'gestion-de-courriels', 'Gestion de courriels', 'LOW', 18, 0, 0, 0, 1, 60),
  (@c, 'redaction', 'Rédaction', 'LOW', 18, 0, 0, 0, 1, 70),
  (@c, 'creation-graphique', 'Création graphique', 'LOW', 16, 0, 0, 0, 1, 80),
  (@c, 'montage-video-a-distance', 'Montage vidéo à distance', 'LOW', 18, 0, 0, 0, 1, 90),
  (@c, 'recherche-documentaire', 'Recherche documentaire', 'LOW', 16, 0, 0, 0, 1, 100),
  (@c, 'gestion-de-reseaux-sociaux', 'Gestion de réseaux sociaux', 'LOW', 18, 0, 0, 0, 1, 110),
  (@c, 'support-informatique', 'Support informatique', 'LOW', 18, 0, 0, 0, 1, 120);

INSERT INTO job_categories (slug, name, icon, safety_notice, sort_order) VALUES ('services-divers', 'Services divers', 'bi-grid', NULL, 210);
SET @c = LAST_INSERT_ID();
INSERT INTO job_subcategories (category_id, slug, name, risk_level, min_age, required_verification_level, requires_qualification, involves_minors_or_vulnerable, is_remote_possible, sort_order) VALUES
  (@c, 'distribution-de-prospectus', 'Distribution de prospectus', 'LOW', 18, 0, 0, 0, 0, 10),
  (@c, 'agent-de-promotion-commerciale', 'Agent de promotion commerciale', 'LOW', 18, 0, 0, 0, 0, 20),
  (@c, 'assistant-de-demenagement-ponctuel', 'Assistant de déménagement ponctuel', 'LOW', 18, 0, 0, 0, 0, 30),
  (@c, 'aide-ponctuelle-a-un-particulier', 'Aide ponctuelle à un particulier', 'LOW', 18, 0, 0, 0, 0, 40),
  (@c, 'assistant-lors-d-une-operation-commerciale', 'Assistant lors d''une opération commerciale', 'LOW', 18, 0, 0, 0, 0, 50),
  (@c, 'participation-a-une-campagne-de-sensibilisation', 'Participation à une campagne de sensibilisation', 'LOW', 18, 0, 0, 0, 0, 60),
  (@c, 'agent-de-controle-de-stocks', 'Agent de contrôle de stocks', 'LOW', 18, 0, 0, 0, 0, 70),
  (@c, 'travailleur-temporaire-pour-besoin-specifique', 'Travailleur temporaire pour besoin spécifique', 'LOW', 18, 0, 0, 0, 0, 80);

