-- POS Marwa SaaS — base CENTRALE (une seule pour tout le service).
-- Les données commerciales de chaque client sont dans SA propre base (une base par client).

CREATE TABLE IF NOT EXISTS saas_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  price_month DECIMAL(14,2) NOT NULL DEFAULT 0,
  max_users INT UNSIGNED NOT NULL DEFAULT 3,
  max_stores INT UNSIGNED NOT NULL DEFAULT 1,
  max_registers INT UNSIGNED NOT NULL DEFAULT 1,
  addons VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'codes des modules autorisés, séparés par des virgules ; * = tous',
  public TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_tenants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL UNIQUE,
  company VARCHAR(150) NOT NULL,
  business_type VARCHAR(40) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(150) NULL,
  city VARCHAR(80) NULL,
  plan_id INT UNSIGNED NOT NULL,
  state ENUM('waiting_db','provisioning','ready','failed') NOT NULL DEFAULT 'provisioning',
  trial_ends DATE NULL,
  paid_until DATE NULL,
  suspended_at DATETIME NULL,
  suspended_reason VARCHAR(255) NULL,
  db_name VARCHAR(64) NULL,
  db_user VARCHAR(80) NULL,
  db_pass_enc TEXT NULL,
  pending_enc TEXT NULL COMMENT 'compte administrateur à créer (chiffré), effacé après création',
  provision_error VARCHAR(500) NULL,
  notes TEXT NULL,
  signup_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  provisioned_at DATETIME NULL,
  KEY idx_state (state),
  CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  months INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('mobile_money','virement','especes','cheque','autre') NOT NULL,
  reference VARCHAR(80) NULL,
  payer_phone VARCHAR(40) NULL,
  paid_on DATE NOT NULL,
  status ENUM('pending','validated','rejected') NOT NULL DEFAULT 'pending',
  period_from DATE NULL,
  period_to DATE NULL,
  invoice_no VARCHAR(30) NULL UNIQUE,
  declared_by ENUM('client','console') NOT NULL,
  declared_by_name VARCHAR(120) NULL,
  validated_by INT UNSIGNED NULL,
  validated_at DATETIME NULL,
  note VARCHAR(255) NULL,
  reject_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id, status),
  KEY idx_ref (method, reference),
  CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id),
  CONSTRAINT fk_pay_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_db_pool (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  db_name VARCHAR(64) NOT NULL UNIQUE,
  db_user VARCHAR(80) NOT NULL,
  db_pass_enc TEXT NOT NULL,
  tenant_id INT UNSIGNED NULL UNIQUE,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  tenant_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_throttle (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kind_ip (kind, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offres d'exemple : prix et limites à ajuster dans la console (Offres)
INSERT IGNORE INTO saas_plans (code, name, description, price_month, max_users, max_stores, max_registers, addons, sort) VALUES
('essentiel', 'Essentiel', 'Une boutique, une caisse : ventes, stock, achats, clients, rapports.', 7500, 3, 1, 1, '', 1),
('pro', 'Pro', 'Plusieurs caisses, MARWA IA et livraisons.', 15000, 10, 2, 4, 'marwa_ia,livraisons,marwa_voice', 2),
('entreprise', 'Entreprise', 'Multi-boutiques et modules métier (hôtel, école, clinique, pressing, banques, comptabilité).', 30000, 50, 10, 20, '*', 3);
