-- =====================================================================
-- MARWA — Migration 004 : données de référence indispensables
-- (rôles, permissions, comptes du grand livre, paramètres par défaut).
-- Les tarifs et commissions ci-dessous sont des VALEURS INITIALES
-- MODIFIABLES dans l'administration ; elles ne sont pas imposées par le code.
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO roles (code, name, is_staff) VALUES
 ('client',   'Client', 0),
 ('driver',   'Chauffeur / Taximan', 0),
 ('courier',  'Livreur', 0),
 ('merchant', 'Restaurant / Commerçant', 0),
 ('business', 'Entreprise', 0),
 ('admin',    'Administrateur', 1),
 ('support',  'Assistance client', 1),
 ('finance',  'Finance / Comptabilité', 1);

INSERT INTO permissions (code, description) VALUES
 ('admin.dashboard',   'Voir le tableau de bord de direction'),
 ('users.view',        'Consulter les utilisateurs'),
 ('users.manage',      'Suspendre / réactiver des utilisateurs'),
 ('partners.validate', 'Valider chauffeurs, livreurs, commerces et entreprises'),
 ('orders.view',       'Consulter toutes les commandes'),
 ('orders.manage',     'Annuler / réaffecter / arbitrer des commandes'),
 ('pricing.manage',    'Modifier les règles tarifaires'),
 ('commissions.manage','Modifier les règles de commission'),
 ('zones.manage',      'Gérer villes, zones et points de repère'),
 ('finance.view',      'Consulter paiements, grand livre et rapports financiers'),
 ('payments.manage',   'Vérifier les paiements et rapprochements'),
 ('refunds.manage',    'Approuver les remboursements'),
 ('payouts.manage',    'Valider les versements aux partenaires'),
 ('expenses.manage',   'Saisir les dépenses opérationnelles'),
 ('complaints.manage', 'Traiter réclamations, litiges et alertes'),
 ('promotions.manage', 'Gérer les promotions'),
 ('notifications.send','Envoyer des notifications de masse'),
 ('settings.manage',   'Modifier les paramètres et fournisseurs'),
 ('audit.view',        'Consulter le journal d''audit'),
 ('reports.export',    'Exporter les rapports');

-- admin : toutes les permissions
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin';
-- support
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   ON p.code IN ('users.view','orders.view','orders.manage','complaints.manage','partners.validate')
  WHERE r.code = 'support';
-- finance
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   ON p.code IN ('admin.dashboard','orders.view','finance.view','payments.manage','refunds.manage','payouts.manage','expenses.manage','reports.export','audit.view')
  WHERE r.code = 'finance';

-- Comptes système du grand livre
INSERT INTO ledger_accounts (code, name, type, owner_type) VALUES
 ('1000', 'Trésorerie MARWA (caisse / banque)', 'asset', 'system'),
 ('1100', 'Fonds chez les prestataires de paiement', 'asset', 'system'),
 ('2300', 'Paiements clients reçus d''avance', 'liability', 'system'),
 ('2400', 'TVA collectée sur commissions', 'liability', 'system'),
 ('3000', 'Capitaux / apports', 'equity', 'system'),
 ('4000', 'Commissions MARWA', 'revenue', 'system'),
 ('4100', 'Frais de service et de réservation', 'revenue', 'system'),
 ('4300', 'Abonnements professionnels', 'revenue', 'system'),
 ('5000', 'Promotions financées par MARWA', 'expense', 'system'),
 ('5100', 'Frais de paiement électronique', 'expense', 'system'),
 ('5200', 'Dépenses opérationnelles', 'expense', 'system'),
 ('5300', 'Remboursements pris en charge par MARWA', 'expense', 'system'),
 ('5400', 'Écarts de caisse et ajustements', 'expense', 'system');

-- Niamey : coordonnées du centre-ville (donnée publique, vérifiable sur OpenStreetMap)
INSERT INTO cities (country_code, region, name, lat, lng, is_active) VALUES
 ('NE', 'Niamey', 'Niamey', 13.512700, 2.112600, 1);

-- Règles tarifaires initiales (à ajuster par l'administration avant lancement)
INSERT INTO pricing_rules (name, service_type, vehicle_category, base_fare, per_km, per_min, min_fare, booking_fee, service_fee, night_multiplier_pct, night_start, night_end, priority) VALUES
 ('Taxi standard',        'taxi',     'taxi',      300, 150, 10,  700,   0,  0, 120, '22:00:00', '05:00:00', 0),
 ('Berline confort',      'taxi',     'sedan',     500, 250, 15, 1200, 100,  0, 120, '22:00:00', '05:00:00', 0),
 ('Véhicule familial',    'taxi',     'family',    700, 300, 15, 1500, 100,  0, 120, '22:00:00', '05:00:00', 0),
 ('Moto-taxi',            'taxi',     'moto_taxi', 200, 100,  5,  400,   0,  0, 120, '22:00:00', '05:00:00', 0),
 ('Transport pro',        'pro',      NULL,        800, 300, 15, 2000, 200,  0, 100, NULL, NULL, 0),
 ('Livraison repas',      'food',     NULL,        300, 100,  0,  500,   0,100, 100, NULL, NULL, 0),
 ('Livraison commerce',   'shop',     NULL,        300, 100,  0,  500,   0,100, 100, NULL, NULL, 0),
 ('Colis',                'parcel',   NULL,        400, 125,  0,  700,   0,  0, 100, NULL, NULL, 0),
 ('Documents',            'document', NULL,        400, 100,  0,  600,   0,  0, 100, NULL, NULL, 0),
 ('Courses particuliers', 'errand',   NULL,        500, 125,  0,  800,   0,  0, 100, NULL, NULL, 0),
 ('Livraison urgente',    'urgent',   NULL,        700, 200,  0, 1200,   0,  0, 100, NULL, NULL, 0);

-- Règles de commission initiales (exemple de démonstration : 10 % sur les courses)
INSERT INTO commission_rules (name, applies_to, service_type, provider_type, model, percent_bp, fixed_amount, priority) VALUES
 ('Commission courses (défaut)',      'provider', NULL, 'driver',  'percent', 1000,   0, 0),
 ('Commission livraisons (défaut)',   'provider', NULL, 'courier', 'percent', 1000,   0, 0),
 ('Commission restaurants (défaut)',  'merchant', NULL, NULL,      'percent', 1500,   0, 0);

INSERT INTO settings (k, v) VALUES
 ('dispatch.radius_km',            '6'),
 ('dispatch.batch_size',           '5'),
 ('dispatch.offer_ttl_seconds',    '60'),
 ('dispatch.max_rounds',           '4'),
 ('dispatch.location_max_age_s',   '300'),
 ('dispatch.rating_weight',        '0'),
 ('negotiation.counter_ttl_seconds','90'),
 ('negotiation.max_counters_per_provider','3'),
 ('negotiation.max_customer_updates','5'),
 ('orders.max_open_per_customer',  '2'),
 ('orders.merchant_accept_timeout_min','10'),
 ('orders.customer_free_cancel_seconds','120'),
 ('pricing.rounding_step',         '50'),
 ('tracking.history_retention_days','30'),
 ('tracking.stale_after_seconds',  '45'),
 ('tracking.min_interval_seconds', '3'),
 ('finance.vat_rate_bp',           '0'),
 ('finance.cash_discrepancy_tolerance','0'),
 ('zones.enforce',                 'false'),
 ('ratings.moderation',            'false'),
 ('emergency.numbers',             '[]'),
 ('emergency.message',             '"Aucune intégration avec les services de secours n''est active. Composez directement le numéro d''urgence local. L''équipe MARWA est alertée."'),
 ('support.phone',                 '""'),
 ('support.email',                 '""'),
 ('payments.methods',              '["cash","demo_mobile_money","business_account"]'),
 ('payments.demo_fee_bp',          '100'),
 ('prohibited_items',              '["Armes, munitions et explosifs","Stupéfiants et substances illicites","Produits inflammables ou dangereux","Argent liquide et objets de grande valeur non déclarés","Animaux vivants","Médicaments soumis à prescription (sauf pharmacie autorisée)","Tout objet interdit par la réglementation nigérienne"]');

INSERT INTO content_pages (slug, title, body) VALUES
 ('cgu', 'Conditions générales d''utilisation', 'Texte à rédiger et à faire valider juridiquement avant le lancement commercial.'),
 ('confidentialite', 'Politique de confidentialité', 'Texte à rédiger conformément à la loi nigérienne sur la protection des données à caractère personnel, et à faire valider par un juriste.'),
 ('securite', 'Règles de sécurité', 'Vérifiez toujours la plaque d''immatriculation et le nom du chauffeur avant de monter. Ne partagez jamais votre code de livraison avant d''avoir reçu votre colis.');
