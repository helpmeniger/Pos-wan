-- =====================================================================
-- SMART GUARD AI — Données de référence (rôles, permissions, catégories,
-- paramètres). Aucun mot de passe n'est créé ici : le compte
-- administrateur est créé par scripts/create_admin.php.
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO roles (code, name, description, is_system) VALUES
('admin',    'Administrateur général', 'Accès complet : magasins, comptes, caméras, modèles IA, paramètres, journaux', 1),
('owner',    'Propriétaire du magasin', 'Rapports, produits, caméras autorisées, stocks, alertes, exports', 1),
('manager',  'Responsable de magasin', 'Événements, vérification des alertes, mouvements autorisés, inventaires', 1),
('cashier',  'Caissier', 'Ventes, consultation des produits, retours autorisés', 1),
('guard',    'Agent de surveillance', 'Caméras autorisées, vérification et annotation des événements', 1);

INSERT IGNORE INTO permissions (code, module, description) VALUES
('dashboard.view',     'dashboard',  'Voir le tableau de bord'),
('live.view',          'cameras',    'Voir la surveillance en direct'),
('cameras.view',       'cameras',    'Voir la liste des caméras'),
('cameras.manage',     'cameras',    'Créer, modifier et configurer les caméras et lignes virtuelles'),
('cameras.control',    'cameras',    'Démarrer / arrêter l analyse d une caméra'),
('video.view',         'cameras',    'Consulter les images et extraits vidéo enregistrés'),
('products.view',      'products',   'Consulter les produits'),
('products.manage',    'products',   'Créer, modifier les produits et leurs photos'),
('ai.manage',          'ai',         'Préparer, valider, activer et restaurer les versions de reconnaissance'),
('ai.test',            'ai',         'Tester la reconnaissance sur une image'),
('detections.view',    'detections', 'Consulter les détections et sorties observées'),
('alerts.view',        'alerts',     'Consulter les alertes'),
('alerts.review',      'alerts',     'Examiner et commenter les alertes'),
('alerts.close',       'alerts',     'Clôturer les alertes et fixer leur conclusion'),
('pos.sell',           'pos',        'Enregistrer des ventes'),
('pos.refund',         'pos',        'Enregistrer des retours / remboursements'),
('pos.cancel',         'pos',        'Annuler une vente'),
('sales.view',         'pos',        'Consulter toutes les ventes'),
('sales.import',       'pos',        'Importer des ventes (CSV/API)'),
('stock.view',         'stock',      'Consulter les stocks'),
('stock.manage',       'stock',      'Enregistrer des mouvements de stock'),
('stock.inventory',    'stock',      'Réaliser des inventaires physiques'),
('reports.view',       'reports',    'Consulter les rapports'),
('reports.export',     'reports',    'Exporter les rapports (PDF, Excel, CSV)'),
('reports.generate',   'reports',    'Générer / régénérer les rapports journaliers'),
('users.manage',       'users',      'Gérer les comptes et les droits'),
('stores.manage',      'stores',     'Créer et modifier les magasins'),
('settings.manage',    'settings',   'Modifier les paramètres'),
('maintenance.manage', 'maintenance','Sauvegardes, rétention, diagnostic'),
('audit.view',         'maintenance','Consulter les journaux d activité');

-- Administrateur : toutes les permissions
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin';

-- Propriétaire
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','live.view','cameras.view','video.view','products.view','products.manage','ai.manage','ai.test',
 'detections.view','alerts.view','alerts.review','alerts.close','sales.view','sales.import','pos.refund','pos.cancel',
 'stock.view','stock.manage','stock.inventory','reports.view','reports.export','reports.generate','cameras.control'
) WHERE r.code = 'owner';

-- Responsable de magasin
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','live.view','cameras.view','video.view','products.view','ai.test','detections.view',
 'alerts.view','alerts.review','alerts.close','sales.view','pos.sell','pos.refund','stock.view','stock.manage',
 'stock.inventory','reports.view','reports.export'
) WHERE r.code = 'manager';

-- Caissier
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'products.view','pos.sell','pos.refund'
) WHERE r.code = 'cashier';

-- Agent de surveillance
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'live.view','cameras.view','video.view','detections.view','alerts.view','alerts.review','alerts.close'
) WHERE r.code = 'guard';

INSERT IGNORE INTO product_categories (name) VALUES
('Riz et céréales'),('Sucre'),('Huile'),('Lait'),('Boissons'),('Biscuits'),('Savons'),('Détergents'),
('Conserves'),('Produits alimentaires divers'),('Articles ménagers'),
('Produits pharmaceutiques (établissements autorisés)'),('Autres marchandises');

INSERT IGNORE INTO stores (code, name, city, timezone, currency) VALUES
('MAG-001', 'Magasin principal', 'Niamey', 'Africa/Niamey', 'XOF');

-- Paramètres globaux (modifiables dans Paramètres)
INSERT IGNORE INTO system_settings (store_id, setting_key, setting_value, value_type, description) VALUES
(NULL,'recon.enabled','1','bool','Activer le rapprochement automatique entre sorties observées et ventes de la caisse'),
(NULL,'recon.window_before_sec','900','int','Délai max (s) entre une vente et la sortie observée (vente AVANT la sortie)'),
(NULL,'recon.window_after_sec','120','int','Tolérance (s) si l horloge caisse est en retard (vente APRÈS la sortie)'),
(NULL,'alerts.priority_min_confidence','0.85','float','Confiance minimale de reconnaissance pour qu une sortie sans vente devienne prioritaire'),
(NULL,'alerts.priority_requires_identified','1','bool','Une alerte prioritaire exige un produit identifié'),
(NULL,'alerts.info_for_matched_exits','1','bool','Créer une alerte de niveau Information pour les sorties rapprochées d une vente'),
(NULL,'alerts.unidentified_level','a_verifier','string','Niveau des alertes « objet non identifié »'),
(NULL,'alerts.track_lost_level','a_verifier','string','Niveau des alertes « perte de suivi »'),
(NULL,'alerts.ambiguous_level','a_verifier','string','Niveau des alertes « franchissement ambigu »'),
(NULL,'retention.snapshots_days','30','int','Durée de conservation des images d événements (jours)'),
(NULL,'retention.videos_days','14','int','Durée de conservation des extraits vidéo (jours)'),
(NULL,'retention.detections_days','90','int','Durée de conservation des détections brutes (jours)'),
(NULL,'storage.max_media_gb','50','float','Espace maximal consacré aux médias (Go)'),
(NULL,'storage.min_free_gb','5','float','Alerte si l espace libre du disque descend sous ce seuil (Go)'),
(NULL,'ai.min_images_per_product','8','int','Nombre minimal de photos de référence par produit'),
(NULL,'ai.recommended_images_per_product','15','int','Nombre recommandé de photos par produit'),
(NULL,'ai.min_validation_accuracy','0.80','float','Précision minimale sur le jeu de test pour autoriser l activation'),
(NULL,'ai.unknown_margin','0.04','float','Écart minimal entre le meilleur produit et le second (sinon « Produit non identifié »)'),
(NULL,'privacy.notice_text','Ce magasin est placé sous vidéosurveillance pour la protection des biens. Aucune reconnaissance faciale n est utilisée.','string','Texte d information des personnes filmées'),
(NULL,'reports.auto_generate','1','bool','Générer automatiquement le rapport de la veille');
