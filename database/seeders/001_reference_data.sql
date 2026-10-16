-- =============================================================================
--  JOB NIGER — Données de référence (obligatoires, non « démo »)
--  À importer après database/schema.sql, puis 002_job_catalogue.sql.
--
--  AUCUN compte administrateur n'est créé ici : le premier compte est créé
--  par la commande `php bin/console admin:create` (Phase 2), qui génère un
--  mot de passe temporaire aléatoire et impose son changement à la connexion.
-- =============================================================================
USE job_niger;
SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Langues
-- -----------------------------------------------------------------------------
INSERT INTO languages (code, name, is_ui_language) VALUES
  ('fr',  'Français', 1),
  ('ha',  'Haoussa', 0),
  ('dje', 'Zarma (Djerma)', 0),
  ('en',  'Anglais', 0),
  ('ar',  'Arabe', 0),
  ('ff',  'Peul (Fulfulde)', 0),
  ('tmh', 'Tamasheq', 0),
  ('kr',  'Kanouri', 0);

-- -----------------------------------------------------------------------------
-- Villes (chefs-lieux des 8 régions) et quartiers de démarrage
-- Coordonnées approximatives du centre-ville.
-- -----------------------------------------------------------------------------
INSERT INTO cities (slug, name, region, latitude, longitude, sort_order) VALUES
  ('niamey',    'Niamey',    'Niamey',    13.512700,  2.112600, 10),
  ('maradi',    'Maradi',    'Maradi',    13.500000,  7.101700, 20),
  ('zinder',    'Zinder',    'Zinder',    13.805300,  8.988300, 30),
  ('tahoua',    'Tahoua',    'Tahoua',    14.890000,  5.265000, 40),
  ('agadez',    'Agadez',    'Agadez',    16.973300,  7.991100, 50),
  ('dosso',     'Dosso',     'Dosso',     13.049000,  3.194000, 60),
  ('diffa',     'Diffa',     'Diffa',     13.315400, 12.611300, 70),
  ('tillaberi', 'Tillabéri', 'Tillabéri', 14.211800,  1.453100, 80);

INSERT INTO neighborhoods (city_id, name)
SELECT c.id, n.name FROM cities c
JOIN (
  SELECT 'niamey' AS city, 'Plateau' AS name UNION ALL SELECT 'niamey','Koira Kano' UNION ALL
  SELECT 'niamey','Lazaret' UNION ALL SELECT 'niamey','Yantala' UNION ALL SELECT 'niamey','Recasement' UNION ALL
  SELECT 'niamey','Banifandou' UNION ALL SELECT 'niamey','Dar Es Salam' UNION ALL SELECT 'niamey','Kalley' UNION ALL
  SELECT 'niamey','Boukoki' UNION ALL SELECT 'niamey','Talladjé' UNION ALL SELECT 'niamey','Gamkallé' UNION ALL
  SELECT 'niamey','Harobanda' UNION ALL SELECT 'niamey','Saga' UNION ALL SELECT 'niamey','Koubia' UNION ALL
  SELECT 'niamey','Madina' UNION ALL SELECT 'niamey','Wadata' UNION ALL SELECT 'niamey','Terminus' UNION ALL
  SELECT 'niamey','Cité Faycal' UNION ALL SELECT 'niamey','Bobiel' UNION ALL SELECT 'niamey','Aéroport' UNION ALL
  SELECT 'niamey','Lamordé' UNION ALL SELECT 'niamey','Kirkissoye' UNION ALL SELECT 'niamey','Tondibiah' UNION ALL
  SELECT 'maradi','Centre-ville' UNION ALL SELECT 'maradi','Sabon Gari' UNION ALL SELECT 'maradi','Zaria' UNION ALL
  SELECT 'zinder','Birni' UNION ALL SELECT 'zinder','Zengou' UNION ALL SELECT 'zinder','Centre-ville' UNION ALL
  SELECT 'tahoua','Centre-ville' UNION ALL SELECT 'agadez','Centre-ville' UNION ALL SELECT 'dosso','Centre-ville' UNION ALL
  SELECT 'diffa','Centre-ville' UNION ALL SELECT 'tillaberi','Centre-ville'
) n ON n.city = c.slug;

-- -----------------------------------------------------------------------------
-- Rôles
-- -----------------------------------------------------------------------------
INSERT INTO roles (code, name, description, is_staff) VALUES
  ('WORKER',        'Prestataire',                'Propose ses services et réalise des missions', 0),
  ('CLIENT',        'Demandeur',                  'Publie des missions et recrute des prestataires', 0),
  ('SUPER_ADMIN',   'Administrateur général',     'Accès complet, gestion des rôles et paramètres', 1),
  ('ADMIN',         'Administrateur',             'Gestion opérationnelle de la plateforme (hors rôles)', 1),
  ('FINANCE_ADMIN', 'Administrateur financier',   'Transactions, commissions, versements, remboursements, rapports', 1),
  ('MODERATOR',     'Modérateur',                 'Annonces, avis, signalements, vérifications', 1),
  ('SUPPORT',       'Agent de support',           'Tickets, consultation limitée des comptes et missions', 1);

-- -----------------------------------------------------------------------------
-- Permissions (contrôlées côté serveur par les Policies et le middleware)
-- -----------------------------------------------------------------------------
INSERT INTO permissions (code, module, description) VALUES
  ('admin.dashboard.view',          'admin',      'Accéder au tableau de bord d''administration'),
  ('users.view',                    'users',      'Consulter les comptes utilisateurs'),
  ('users.manage',                  'users',      'Modifier, suspendre, réactiver un compte'),
  ('users.verify',                  'users',      'Valider les vérifications de profil'),
  ('users.view_identity_docs',      'users',      'Consulter les pièces d''identité (accès journalisé)'),
  ('businesses.manage',             'users',      'Gérer et vérifier les entreprises'),
  ('roles.manage',                  'security',   'Gérer les rôles et permissions du personnel'),
  ('catalog.manage',                'catalog',    'Gérer catégories, métiers, compétences, villes'),
  ('jobs.moderate',                 'jobs',       'Valider, refuser ou retirer des annonces'),
  ('missions.view_all',             'missions',   'Consulter toutes les missions'),
  ('missions.manage',               'missions',   'Suspendre ou annuler une mission'),
  ('messages.view_reported',        'messages',   'Lire une conversation signalée ou versée à un litige'),
  ('reviews.moderate',              'reviews',    'Modérer les avis'),
  ('reports.manage',                'reports',    'Traiter les signalements'),
  ('disputes.manage',               'disputes',   'Instruire et décider les litiges'),
  ('support.manage',                'support',    'Traiter les tickets de support'),
  ('notifications.broadcast',       'notifications','Envoyer des annonces générales'),
  ('finance.dashboard.view',        'finance',    'Voir le tableau de bord financier'),
  ('finance.transactions.view',     'finance',    'Consulter les transactions et le grand livre'),
  ('finance.payments.verify',       'finance',    'Valider manuellement un paiement (espèces, virement)'),
  ('finance.refunds.request',       'finance',    'Créer une demande de remboursement'),
  ('finance.refunds.approve',       'finance',    'Approuver un remboursement'),
  ('finance.payouts.request',       'finance',    'Préparer un versement prestataire'),
  ('finance.payouts.approve',       'finance',    'Approuver un versement (≠ préparateur)'),
  ('finance.adjustments.create',    'finance',    'Passer une écriture corrective justifiée'),
  ('finance.commission_rules.manage','finance',   'Créer ou modifier les règles de commission'),
  ('finance.reconciliation',        'finance',    'Effectuer le rapprochement des paiements'),
  ('finance.export',                'finance',    'Exporter les rapports financiers (CSV / Excel)'),
  ('payment_gateways.manage',       'finance',    'Activer / configurer les moyens de paiement'),
  ('settings.manage',               'settings',   'Modifier les paramètres généraux'),
  ('audit.view',                    'security',   'Consulter le journal d''audit et les événements de sécurité'),
  ('stats.view',                    'admin',      'Consulter les statistiques et rapports');

-- SUPER_ADMIN : tout
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPER_ADMIN';

-- ADMIN : tout sauf rôles, ajustements financiers, approbation des versements/remboursements, passerelles
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.code NOT IN ('roles.manage','finance.adjustments.create','finance.payouts.approve',
                    'finance.refunds.approve','payment_gateways.manage','finance.commission_rules.manage')
WHERE r.code = 'ADMIN';

-- FINANCE_ADMIN
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.code IN ('admin.dashboard.view','users.view','missions.view_all','disputes.manage','stats.view','audit.view')
     OR p.module = 'finance'
WHERE r.code = 'FINANCE_ADMIN';

-- MODERATOR
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.code IN ('admin.dashboard.view','users.view','users.verify','users.view_identity_docs','businesses.manage',
                'jobs.moderate','missions.view_all','messages.view_reported','reviews.moderate','reports.manage',
                'disputes.manage','catalog.manage')
WHERE r.code = 'MODERATOR';

-- SUPPORT
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.code IN ('admin.dashboard.view','users.view','missions.view_all','support.manage','reports.manage')
WHERE r.code = 'SUPPORT';

-- -----------------------------------------------------------------------------
-- Types de mission
-- -----------------------------------------------------------------------------
INSERT INTO mission_types (code, name, description, sort_order) VALUES
  ('HEURES',          'Mission de quelques heures',          'Moins d''une journée', 10),
  ('JOURNEE',         'Mission d''une journée',              'Une journée de travail', 20),
  ('PLUSIEURS_JOURS', 'Mission de plusieurs jours',          'De 2 jours à quelques semaines', 30),
  ('WEEKEND',         'Mission de week-end',                 'Samedi et/ou dimanche', 40),
  ('SAISONNIERE',     'Mission saisonnière',                 'Campagne agricole, fêtes, rentrée…', 50),
  ('REMPLACEMENT',    'Remplacement temporaire',             'Remplacement d''un salarié absent', 60),
  ('RECURRENTE',      'Mission récurrente',                  'Se répète selon un calendrier', 70),
  ('DISTANCE',        'Travail à distance',                  'Réalisé en ligne', 80),
  ('PRESTATION',      'Contrat de prestation ponctuelle',    'Livrable défini, prix forfaitaire', 90),
  ('EMPLOI_TEMPORAIRE','Emploi temporaire proposé par une entreprise','Peut relever du droit du travail : voir CGU', 100);

-- -----------------------------------------------------------------------------
-- Compétences transversales
-- -----------------------------------------------------------------------------
INSERT INTO skills (subcategory_id, slug, name) VALUES
  (NULL, 'ponctualite',            'Ponctualité'),
  (NULL, 'travail-en-equipe',      'Travail en équipe'),
  (NULL, 'relation-client',        'Relation client'),
  (NULL, 'permis-moto',            'Permis moto'),
  (NULL, 'permis-b',               'Permis B'),
  (NULL, 'word-excel',             'Word / Excel'),
  (NULL, 'reseaux-sociaux',        'Réseaux sociaux'),
  (NULL, 'hygiene-alimentaire',    'Hygiène alimentaire'),
  (NULL, 'premiers-secours',       'Premiers secours'),
  (NULL, 'port-de-charges',        'Port de charges'),
  (NULL, 'saisie-rapide',          'Saisie rapide'),
  (NULL, 'photographie',           'Photographie'),
  (NULL, 'montage-video',          'Montage vidéo'),
  (NULL, 'kobo-odk',               'Collecte mobile (KoboToolbox / ODK)');

-- -----------------------------------------------------------------------------
-- Plan de comptes interne (grand livre en partie double) — voir docs/03
-- -----------------------------------------------------------------------------
INSERT INTO financial_ledger_accounts (code, name, type, normal_balance, has_user_subledger, is_off_platform, description) VALUES
  ('1010', 'Fonds chez le prestataire de paiement',     'ACTIF',  'DEBIT',  0, 0, 'Fonds encaissés en mode intégré, détenus par le PSP habilité (par passerelle)'),
  ('1020', 'Trésorerie propre de la plateforme',        'ACTIF',  'DEBIT',  0, 0, 'Compte bancaire / Mobile Money de JOB NIGER (commissions reversées)'),
  ('1030', 'Fonds en transit (versements en cours)',    'ACTIF',  'DEBIT',  0, 0, 'Versements émis, non encore confirmés'),
  ('1210', 'Commissions à recevoir des prestataires',   'ACTIF',  'DEBIT',  1, 0, 'Mode direct : commission due par le prestataire, non encore encaissée'),
  ('2010', 'Dû prestataires — retenu (mission non validée)', 'PASSIF','CREDIT',1, 0, 'Montant net en attente de validation de la mission'),
  ('2011', 'Dû prestataires — disponible au versement',  'PASSIF','CREDIT',1, 0, 'Mission validée, montant net versable'),
  ('2020', 'Remboursements à verser aux demandeurs',    'PASSIF', 'CREDIT', 1, 0, 'Remboursements approuvés non encore exécutés'),
  ('2030', 'Commissions constatées d''avance',          'PASSIF', 'CREDIT', 0, 0, 'Commission sur paiement confirmé, mission non terminée'),
  ('2040', 'Paiements contestés (fonds bloqués)',       'PASSIF', 'CREDIT', 0, 0, 'Montants gelés suite à contestation / rétrofacturation'),
  ('4010', 'Produits — commissions sur missions',       'PRODUIT','CREDIT', 0, 0, 'Commission acquise (mission terminée)'),
  ('4020', 'Produits — abonnements professionnels',     'PRODUIT','CREDIT', 0, 0, NULL),
  ('4030', 'Produits — annonces sponsorisées',          'PRODUIT','CREDIT', 0, 0, NULL),
  ('4090', 'Annulations de commissions',                'PRODUIT','DEBIT',  0, 0, 'Contre-produit : commissions annulées suite à remboursement'),
  ('6010', 'Charges — frais des prestataires de paiement','CHARGE','DEBIT', 0, 0, 'Frais prélevés par les opérateurs'),
  ('6020', 'Charges — gestes commerciaux',              'CHARGE', 'DEBIT',  0, 0, 'Remboursements pris en charge par la plateforme'),
  ('6030', 'Charges — ajustements et pertes',           'CHARGE', 'DEBIT',  0, 0, 'Écritures correctives justifiées'),
  ('9010', 'Mémo — paiements directs déclarés',         'ACTIF',  'DEBIT',  0, 1, 'Hors fonds plateforme : valeur des paiements directs client → prestataire'),
  ('9020', 'Mémo — contrepartie paiements directs',     'PASSIF', 'CREDIT', 0, 1, 'Contrepartie du compte 9010');

-- -----------------------------------------------------------------------------
-- Passerelles et moyens de paiement — états réels : rien n'est en production
-- tant qu'aucun contrat marchand ni identifiant n'a été configuré.
-- -----------------------------------------------------------------------------
INSERT INTO payment_gateways (code, name, driver_class, environment, supports_webhook, supports_refund, supports_payout, holds_funds, is_active, notes) VALUES
  ('MANUAL_DIRECT', 'Paiement direct déclaré (suivi manuel)', 'App\\Services\\Payment\\Gateways\\ManualGateway',   'PRODUCTION', 0, 0, 0, 0, 1,
     'Mode 1 : le demandeur paie le prestataire directement ; la plateforme enregistre la preuve, ne détient pas les fonds.'),
  ('CASH',          'Espèces (remise en main propre)',        'App\\Services\\Payment\\Gateways\\CashGateway',     'PRODUCTION', 0, 0, 0, 0, 1,
     'Jamais considéré comme encaissé par la plateforme. Confirmation des deux parties requise.'),
  ('BANK_TRANSFER', 'Virement bancaire',                      'App\\Services\\Payment\\Gateways\\BankTransferGateway','A_CONFIGURER', 0, 0, 0, 0, 0,
     'Nécessite un compte bancaire de la plateforme ; vérification manuelle sur relevé.'),
  ('MOBILE_MONEY',  'Agrégateur Mobile Money (à contractualiser)', 'App\\Services\\Payment\\Gateways\\MobileMoneyGateway','A_CONFIGURER', 1, 1, 1, 1, 0,
     'Opérateurs présents au Niger à confirmer par contrat (ex. Airtel Money, Moov Money, Zamani Cash, Al Izza…). Aucune API activée.'),
  ('CARD_PSP',      'Carte bancaire via PSP agréé',           'App\\Services\\Payment\\Gateways\\CardGateway',     'A_CONFIGURER', 1, 1, 0, 1, 0,
     'Page de paiement hébergée par le PSP : aucune donnée de carte ne transite par JOB NIGER.'),
  ('SANDBOX',       'Passerelle de test (simulateur local)',  'App\\Services\\Payment\\Gateways\\SandboxGateway',  'TEST', 1, 1, 1, 1, 0,
     'Réservée au développement : webhooks signés simulés. Désactivée automatiquement si APP_ENV=production.');

INSERT INTO payment_methods (code, name, kind, gateway_id, verification, is_active, sort_order)
SELECT m.code, m.name, m.kind, g.id, m.verification, m.active, m.sort_order FROM (
  SELECT 'DIRECT_MOMO'  AS code, 'Mobile Money direct au prestataire (déclaré)' AS name, 'MOBILE_MONEY' AS kind, 'MANUAL_DIRECT' AS gw, 'MANUELLE' AS verification, 1 AS active, 10 AS sort_order UNION ALL
  SELECT 'CASH',        'Espèces',                              'ESPECES',      'CASH',          'MANUELLE',  1, 20 UNION ALL
  SELECT 'BANK',        'Virement bancaire',                    'VIREMENT',     'BANK_TRANSFER', 'MANUELLE',  0, 30 UNION ALL
  SELECT 'MOMO_INTEGRE','Mobile Money (paiement intégré)',      'MOBILE_MONEY', 'MOBILE_MONEY',  'AUTOMATIQUE', 0, 40 UNION ALL
  SELECT 'CARTE',       'Carte bancaire',                       'CARTE',        'CARD_PSP',      'AUTOMATIQUE', 0, 50 UNION ALL
  SELECT 'SANDBOX',     'Paiement de test',                     'PORTEFEUILLE', 'SANDBOX',       'AUTOMATIQUE', 0, 90
) m JOIN payment_gateways g ON g.code = m.gw;

-- -----------------------------------------------------------------------------
-- Règle de commission par défaut : 10 % à la charge du prestataire
-- -----------------------------------------------------------------------------
INSERT INTO commission_rules (name, scope, client_rate, worker_rate, fixed_fee, fixed_fee_payer, priority, valid_from)
VALUES ('Commission standard JOB NIGER', 'GLOBAL', 0.00, 10.00, 0.00, 'CLIENT', 0, '2026-01-01 00:00:00');

-- -----------------------------------------------------------------------------
-- Abonnements professionnels (prix à définir par l'administrateur)
-- -----------------------------------------------------------------------------
INSERT INTO subscription_plans (code, name, description, monthly_price, max_active_posts, max_members, max_sites, features_json, is_active, sort_order) VALUES
  ('GRATUIT', 'Gratuit', 'Création de compte, publication limitée, recherche, messagerie', 0, 3, 1, 1,
   '["publication_limitee","recherche","messagerie"]', 1, 10),
  ('PROFESSIONNEL', 'Professionnel', 'Plus de missions, outils avancés, historique, collaborateurs', 0, 30, 5, 3,
   '["recherche_avancee","historique_complet","collaborateurs","favoris_illimites"]', 0, 20),
  ('ENTREPRISE', 'Entreprise', 'Multi-sites, comptes employés, rapports et exports financiers', 0, NULL, NULL, NULL,
   '["multi_sites","comptes_employes","rapports_avances","exports_financiers","gestion_centralisee"]', 0, 30);
-- Les offres payantes restent inactives tant que leur prix n'a pas été fixé.

-- -----------------------------------------------------------------------------
-- Paramètres généraux (aucun secret : les secrets vont dans .env)
-- -----------------------------------------------------------------------------
INSERT INTO platform_settings (setting_key, setting_value, value_type, group_name, description) VALUES
  ('app.name',                         'JOB NIGER',                         'STRING',  'general',  'Nom de la plateforme'),
  ('app.slogan',                       'Un travail, une opportunité, un revenu.', 'STRING', 'general', 'Slogan'),
  ('app.currency',                     'XOF',                               'STRING',  'general',  'Devise (franc CFA BCEAO)'),
  ('app.currency_minor_units',         '0',                                 'INT',     'general',  'Décimales affichées / arrondi (XOF = 0)'),
  ('app.timezone',                     'Africa/Niamey',                     'STRING',  'general',  'Fuseau horaire'),
  ('app.date_format',                  'd/m/Y',                             'STRING',  'general',  'Format JJ/MM/AAAA'),
  ('auth.email_required',              '0',                                 'BOOL',    'auth',     'E-mail obligatoire à l''inscription'),
  ('auth.phone_otp_enabled',           '0',                                 'BOOL',    'auth',     'Vérification OTP par SMS (exige un fournisseur SMS configuré)'),
  ('auth.max_login_attempts',          '5',                                 'INT',     'auth',     'Tentatives avant verrouillage temporaire'),
  ('auth.lockout_minutes',             '15',                                'INT',     'auth',     'Durée du verrouillage'),
  ('jobs.moderation_required',         '0',                                 'BOOL',    'jobs',     'Toutes les annonces passent en modération'),
  ('jobs.min_description_length',      '40',                                'INT',     'jobs',     'Longueur minimale de la description'),
  ('jobs.max_positions',               '200',                               'INT',     'jobs',     'Postes maximum par annonce'),
  ('missions.auto_confirm_hours',      '72',                                'INT',     'missions', 'Validation tacite après déclaration de fin (sans contestation)'),
  ('missions.start_reminder_hours',    '12',                                'INT',     'missions', 'Rappel avant le début d''une mission'),
  ('finance.min_mission_amount',       '1000',                              'DECIMAL', 'finance',  'Montant minimum d''une mission (FCFA)'),
  ('finance.max_mission_amount',       '5000000',                           'DECIMAL', 'finance',  'Montant maximum d''une mission (FCFA)'),
  ('finance.rounding_mode',            'HALF_UP',                           'STRING',  'finance',  'Arrondi des commissions à l''unité'),
  ('finance.payment_mode_default',     'DIRECT',                            'STRING',  'finance',  'DIRECT (mode 1) ou INTEGRE (mode 2, exige un PSP habilité)'),
  ('finance.integrated_mode_enabled',  '0',                                 'BOOL',    'finance',  'Mode 2 activable uniquement après validation contractuelle et réglementaire'),
  ('finance.escrow_enabled',           '0',                                 'BOOL',    'finance',  'Paiement différé / séquestre : interdit sans prestataire habilité'),
  ('finance.payout_min_amount',        '1000',                              'DECIMAL', 'finance',  'Montant minimum d''un versement'),
  ('finance.direct_commission_due_days','7',                                'INT',     'finance',  'Mode direct : délai de paiement de la commission par le prestataire'),
  ('finance.tax_rate_commission',      '0',                                 'DECIMAL', 'finance',  'TVA sur commissions — à fixer avec un expert-comptable'),
  ('uploads.max_size_mb',              '5',                                 'INT',     'uploads',  'Taille maximale d''un fichier'),
  ('uploads.allowed_mimes',            '["image/jpeg","image/png","image/webp","application/pdf"]', 'JSON', 'uploads', 'Types MIME autorisés (vérifiés par finfo)'),
  ('messages.poll_interval_seconds',   '8',                                 'INT',     'messages', 'Intervalle d''interrogation (avant WebSocket)'),
  ('privacy.identity_doc_retention_days','90',                              'INT',     'privacy',  'Conservation des pièces d''identité après décision'),
  ('legal.cgu_version',                '0.1-brouillon',                     'STRING',  'legal',    'Version des CGU en vigueur (validation juridique requise)'),
  ('legal.privacy_version',            '0.1-brouillon',                     'STRING',  'legal',    'Version de la politique de confidentialité');

-- -----------------------------------------------------------------------------
-- Règles de modération (fausses offres, frais de recrutement…)
-- -----------------------------------------------------------------------------
INSERT INTO moderation_rules (pattern, applies_to, action, reason) VALUES
  ('frais de dossier',          'ANNONCE', 'MODERATION_OBLIGATOIRE', 'Frais de recrutement interdits'),
  ('frais d''inscription',      'ANNONCE', 'MODERATION_OBLIGATOIRE', 'Frais de recrutement interdits'),
  ('payer pour postuler',       'ANNONCE', 'BLOQUER',                'Frais de recrutement interdits'),
  ('caution à verser',          'ANNONCE', 'MODERATION_OBLIGATOIRE', 'Demande de dépôt suspecte'),
  ('gain garanti',              'ANNONCE', 'MODERATION_OBLIGATOIRE', 'Promesse de gain suspecte'),
  ('code secret',               'MESSAGE', 'SIGNALER',               'Ne jamais communiquer un code Mobile Money'),
  ('code pin',                  'MESSAGE', 'SIGNALER',               'Ne jamais communiquer un code Mobile Money'),
  ('envoyez votre code',        'MESSAGE', 'SIGNALER',               'Tentative d''hameçonnage');

-- Séquences de numérotation
INSERT INTO document_sequences (name, last_value) VALUES
  ('RECEIPT-2026', 0), ('INVOICE-2026', 0);
