-- Marwa Fiscal Connect : tables du module (aucune table existante n'est modifiée).
-- Retour arrière : addons/fiscal/sql/down.sql (manuel, après sauvegarde et confirmation).

CREATE TABLE IF NOT EXISTS fiscal_countries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(2) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  currency CHAR(3) NOT NULL,
  invoice_format VARCHAR(120) NULL,
  tax_id_label VARCHAR(40) NULL,
  tax_regimes TEXT NULL,
  numbering_rules TEXT NULL,
  legal_mentions TEXT NULL,
  certification_mode ENUM('unknown','none','mcf','api','cloud','mixed') NOT NULL DEFAULT 'unknown',
  authorized_provider VARCHAR(160) NULL,
  qr_format VARCHAR(160) NULL,
  signature_format VARCHAR(160) NULL,
  archiving_rules TEXT NULL,
  retention_years SMALLINT UNSIGNED NULL,
  official_sources TEXT NULL,
  configured TINYINT(1) NOT NULL DEFAULT 0,
  homologated TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_connectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('mcf','api','cloud','simulation') NOT NULL,
  driver VARCHAR(60) NOT NULL,
  transport ENUM('none','usb','serial','tcp','http','rest','soap','cloud') NOT NULL DEFAULT 'none',
  environment ENUM('test','prod') NOT NULL DEFAULT 'test',
  store_id INT UNSIGNED NULL,
  register_id INT UNSIGNED NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 0,
  config_json TEXT NULL,
  timeout_s SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  max_retries TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status ENUM('unknown','ok','error') NOT NULL DEFAULT 'unknown',
  last_test_at DATETIME NULL,
  last_test_message VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_fc_scope (active, store_id, register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  country_id INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NULL,
  environment ENUM('test','prod') NOT NULL DEFAULT 'test',
  seller_nif VARCHAR(40) NULL,
  seller_regime VARCHAR(80) NULL,
  connector_id INT UNSIGNED NULL,
  legal_mentions TEXT NULL,
  print_uncertified ENUM('mark','block') NOT NULL DEFAULT 'mark',
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_fp_scope (active, store_id),
  CONSTRAINT fk_fp_country FOREIGN KEY (country_id) REFERENCES fiscal_countries(id),
  CONSTRAINT fk_fp_connector FOREIGN KEY (connector_id) REFERENCES fiscal_connectors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_tax_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_id INT UNSIGNED NOT NULL,
  group_code VARCHAR(10) NOT NULL,
  label VARCHAR(120) NOT NULL,
  rate DECIMAL(6,3) NOT NULL,
  local_rate DECIMAL(6,3) NULL,
  official_source VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ftr (country_id, group_code),
  CONSTRAINT fk_ftr_country FOREIGN KEY (country_id) REFERENCES fiscal_countries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(80) NULL,
  serial_number VARCHAR(80) NULL,
  model VARCHAR(120) NULL,
  firmware VARCHAR(60) NULL,
  info_json TEXT NULL,
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fd (connector_id),
  CONSTRAINT fk_fd_connector FOREIGN KEY (connector_id) REFERENCES fiscal_connectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_api_credentials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NOT NULL,
  ckey VARCHAR(40) NOT NULL,
  value_enc TEXT NOT NULL,
  hint VARCHAR(12) NULL,
  expires_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fac (connector_id, ckey),
  CONSTRAINT fk_fac_connector FOREIGN KEY (connector_id) REFERENCES fiscal_connectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NOT NULL,
  kind ENUM('ca','client_cert','client_key') NOT NULL,
  filename VARCHAR(160) NOT NULL,
  path VARCHAR(255) NOT NULL,
  fingerprint_sha256 CHAR(64) NOT NULL,
  subject VARCHAR(255) NULL,
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  password_enc TEXT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fcert (connector_id, kind),
  CONSTRAINT fk_fcert_connector FOREIGN KEY (connector_id) REFERENCES fiscal_connectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_certifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type ENUM('invoice','credit_note') NOT NULL,
  doc_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  local_number VARCHAR(30) NOT NULL,
  profile_id INT UNSIGNED NULL,
  connector_id INT UNSIGNED NULL,
  environment ENUM('test','prod') NOT NULL,
  is_simulation TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','sending','certified','rejected','failed') NOT NULL DEFAULT 'pending',
  idempotency_key CHAR(64) NOT NULL UNIQUE,
  payload_hash CHAR(64) NOT NULL,
  fiscal_number VARCHAR(80) NULL,
  device_id VARCHAR(80) NULL,
  signature TEXT NULL,
  qr_payload TEXT NULL,
  counters VARCHAR(255) NULL,
  certified_at DATETIME NULL,
  response_hash CHAR(64) NULL,
  proof_json MEDIUMTEXT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_fcn_doc (doc_type, doc_id),
  INDEX idx_fcn_sale (sale_id),
  INDEX idx_fcn_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_pending_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certification_id INT UNSIGNED NOT NULL UNIQUE,
  next_attempt_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fpq_next (next_attempt_at),
  CONSTRAINT fk_fpq_cert FOREIGN KEY (certification_id) REFERENCES fiscal_certifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_invoice_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  certification_id INT UNSIGNED NULL,
  connector_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  result_code VARCHAR(40) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  duration_ms INT UNSIGNED NULL,
  message VARCHAR(255) NULL,
  request_redacted MEDIUMTEXT NULL,
  response_redacted MEDIUMTEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fil_cert (certification_id),
  INDEX idx_fil_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_webhooks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connector_id INT UNSIGNED NOT NULL,
  token CHAR(40) NOT NULL UNIQUE,
  secret_enc TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_received_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fwh_connector FOREIGN KEY (connector_id) REFERENCES fiscal_connectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fal (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pays listés : seules la devise officielle et le nom sont renseignés.
-- Toutes les règles fiscales restent à configurer à partir des textes officiels (configured = 0, homologated = 0).
INSERT IGNORE INTO fiscal_countries (code, name, currency) VALUES
('NE', 'Niger', 'XOF'), ('BJ', 'Bénin', 'XOF'), ('TG', 'Togo', 'XOF'), ('CI', 'Côte d''Ivoire', 'XOF'),
('BF', 'Burkina Faso', 'XOF'), ('SN', 'Sénégal', 'XOF'), ('ML', 'Mali', 'XOF'), ('GH', 'Ghana', 'GHS'),
('NG', 'Nigeria', 'NGN'), ('MA', 'Maroc', 'MAD'), ('FR', 'France', 'EUR');
