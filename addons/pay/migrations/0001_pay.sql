-- Marwa Pay Connect : tables du module (aucune table existante n'est modifiée).
-- Retour arrière : addons/pay/sql/down.sql (manuel, après sauvegarde et confirmation).

CREATE TABLE IF NOT EXISTS payment_currencies (
  code CHAR(3) NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  symbol VARCHAR(10) NULL,
  decimals TINYINT UNSIGNED NOT NULL DEFAULT 2,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_exchange_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_currency CHAR(3) NOT NULL,
  to_currency CHAR(3) NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  source VARCHAR(255) NOT NULL,
  valid_from DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_per (from_currency, to_currency, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  category ENUM('card','wallet','mobile_money','bank','terminal','local','other') NOT NULL DEFAULT 'other',
  country CHAR(2) NULL,
  website VARCHAR(200) NULL,
  official_doc_ref TEXT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_connectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  driver VARCHAR(60) NOT NULL,
  environment ENUM('test','prod') NOT NULL DEFAULT 'test',
  account_label VARCHAR(120) NULL,
  store_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  config_json TEXT NULL,
  timeout_s SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  status ENUM('unknown','ok','error') NOT NULL DEFAULT 'unknown',
  last_test_at DATETIME NULL,
  last_test_message VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_pc_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_api_credentials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  ckey VARCHAR(40) NOT NULL,
  value_enc TEXT NOT NULL,
  hint VARCHAR(12) NULL,
  expires_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pac (connector_id, terminal_id, ckey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_terminals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  manufacturer VARCHAR(80) NULL,
  model VARCHAR(80) NULL,
  serial_number VARCHAR(80) NULL,
  driver VARCHAR(60) NOT NULL,
  connection ENUM('standalone','usb','serial','bluetooth','ethernet','tcp','wifi','http_local','android_sdk') NOT NULL DEFAULT 'standalone',
  ip_address VARCHAR(64) NULL,
  port SMALLINT UNSIGNED NULL,
  device_path VARCHAR(80) NULL,
  bridge_url VARCHAR(200) NULL,
  environment ENUM('test','prod') NOT NULL DEFAULT 'test',
  store_id INT UNSIGNED NULL,
  register_id INT UNSIGNED NULL,
  config_json TEXT NULL,
  timeout_s SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  active TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('unknown','ok','error') NOT NULL DEFAULT 'unknown',
  last_seen_at DATETIME NULL,
  last_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_pt_reg (active, register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  kind ENUM('card','wallet','mobile_money','bank','terminal','other') NOT NULL,
  sale_method ENUM('card','mobile_money','transfer') NOT NULL,
  connector_id INT UNSIGNED NULL,
  currency CHAR(3) NOT NULL,
  fee_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
  fee_fixed DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_amount DECIMAL(15,2) NULL,
  max_amount DECIMAL(15,2) NULL,
  expiry_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  register_ids VARCHAR(255) NULL,
  icon VARCHAR(40) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_pm_connector FOREIGN KEY (connector_id) REFERENCES payment_connectors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  idempotency_key CHAR(64) NOT NULL UNIQUE,
  cart_uuid CHAR(36) NULL,
  sale_id INT UNSIGNED NULL,
  method_id INT UNSIGNED NOT NULL,
  connector_id INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  driver VARCHAR(60) NOT NULL,
  environment ENUM('test','prod') NOT NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 1,
  store_id INT UNSIGNED NULL,
  register_id INT UNSIGNED NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  exchange_rate DECIMAL(20,8) NULL,
  provider_amount DECIMAL(15,2) NULL,
  provider_currency CHAR(3) NULL,
  fees DECIMAL(15,2) NULL,
  refunded_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('pending','processing','succeeded','declined','cancelled','expired','refunded','partially_refunded','to_verify','failed') NOT NULL DEFAULT 'pending',
  verified_by ENUM('api','webhook','reconciliation','terminal') NULL,
  provider_reference VARCHAR(120) NULL,
  authorization_code VARCHAR(40) NULL,
  redirect_url VARCHAR(500) NULL,
  payer_hint VARCHAR(40) NULL,
  message VARCHAR(255) NULL,
  expires_at DATETIME NULL,
  succeeded_at DATETIME NULL,
  proof_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_ptx_sale (sale_id),
  INDEX idx_ptx_cart (cart_uuid),
  INDEX idx_ptx_status (status, created_at),
  INDEX idx_ptx_ref (provider_reference),
  CONSTRAINT fk_ptx_method FOREIGN KEY (method_id) REFERENCES payment_methods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  transaction_id INT UNSIGNED NOT NULL,
  idempotency_key CHAR(64) NOT NULL UNIQUE,
  amount DECIMAL(15,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','succeeded','failed','to_verify') NOT NULL DEFAULT 'pending',
  provider_reference VARCHAR(120) NULL,
  message VARCHAR(255) NULL,
  requested_by INT UNSIGNED NULL,
  authorized_by INT UNSIGNED NULL,
  proof_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_prf_tx (transaction_id),
  CONSTRAINT fk_prf_tx FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_transaction_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NULL,
  refund_id INT UNSIGNED NULL,
  connector_id INT UNSIGNED NULL,
  terminal_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  result_code VARCHAR(40) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  duration_ms INT UNSIGNED NULL,
  message VARCHAR(255) NULL,
  request_redacted MEDIUMTEXT NULL,
  response_redacted MEDIUMTEXT NULL,
  dedup_key CHAR(64) NULL UNIQUE,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ptl_tx (transaction_id),
  INDEX idx_ptl_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhooks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NOT NULL,
  token CHAR(40) NOT NULL UNIQUE,
  secret_enc TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_received_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pwh_connector FOREIGN KEY (connector_id) REFERENCES payment_connectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_reconciliation (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch VARCHAR(40) NOT NULL,
  connector_id INT UNSIGNED NULL,
  source_name VARCHAR(160) NULL,
  line_no INT UNSIGNED NULL,
  provider_reference VARCHAR(120) NULL,
  amount DECIMAL(15,2) NULL,
  currency CHAR(3) NULL,
  op_date DATE NULL,
  external_status VARCHAR(40) NULL,
  transaction_id INT UNSIGNED NULL,
  result ENUM('matched','amount_mismatch','status_mismatch','missing_local','missing_provider') NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prc_batch (batch),
  INDEX idx_prc_tx (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  value TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devises (codes ISO 4217). Aucun taux de change n'est prérempli.
INSERT IGNORE INTO payment_currencies (code, name, symbol, decimals) VALUES
('XOF', 'Franc CFA BCEAO', 'F', 0), ('EUR', 'Euro', '€', 2), ('USD', 'Dollar américain', '$', 2),
('NGN', 'Naira', '₦', 2), ('GHS', 'Cedi', 'GH₵', 2), ('MAD', 'Dirham marocain', 'DH', 2);

-- Catalogue indicatif : aucun de ces prestataires n'est déclaré intégré (verified = 0).
INSERT IGNORE INTO payment_providers (code, name, category, country, notes) VALUES
('simulation', 'Simulation (TEST)', 'other', NULL, 'Transactions fictives marquées TEST, jamais comptées comme encaissements.'),
('visa', 'Visa', 'card', NULL, 'Réseau de cartes : acceptation via un acquéreur ou un prestataire certifié PCI DSS.'),
('mastercard', 'Mastercard', 'card', NULL, 'Réseau de cartes : acceptation via un acquéreur ou un prestataire certifié PCI DSS.'),
('paypal', 'PayPal', 'wallet', NULL, 'Documentation et identifiants marchands à obtenir auprès de PayPal.'),
('mynita', 'My NiTA', 'local', 'NE', 'Disponibilité d''une API marchande à confirmer auprès du fournisseur.'),
('amana', 'AMANA', 'local', 'NE', 'Disponibilité d''une API marchande à confirmer auprès du fournisseur.'),
('mobile_money', 'Mobile Money (opérateur)', 'mobile_money', NULL, 'API propre à chaque opérateur : documentation et contrat marchand requis.'),
('bank', 'Banque (API)', 'bank', NULL, 'API propre à chaque banque : documentation et contrat requis.'),
('tpe', 'TPE bancaire', 'terminal', NULL, 'Terminal fourni par la banque ou le prestataire.');

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('pay_transaction', 'PAY', 1, YEAR(CURDATE())), ('pay_refund', 'RMB', 1, YEAR(CURDATE()));
