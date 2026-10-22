-- MARWA REMOTE ASSIST : postes équipés, interventions et journal de chaque intervention

CREATE TABLE IF NOT EXISTS assist_postes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(60) NOT NULL,
  kind ENUM('boutique','technicien') NOT NULL DEFAULT 'boutique',
  hostname VARCHAR(60) NULL,
  rustdesk_id VARCHAR(16) NULL,
  ip VARCHAR(45) NULL,
  token_hash CHAR(64) NULL,
  token_expires DATETIME NULL,
  registered_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assist_token (token_hash),
  INDEX idx_assist_poste_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assist_interventions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('demandee','observation','controle','terminee','expiree') NOT NULL DEFAULT 'demandee',
  reason VARCHAR(300) NOT NULL,
  poste_id INT UNSIGNED NULL,
  poste_label VARCHAR(60) NULL,
  ip VARCHAR(45) NULL,
  requested_by INT UNSIGNED NULL,
  control_by INT UNSIGNED NULL,
  control_until DATETIME NULL,
  technician VARCHAR(60) NULL,
  summary VARCHAR(500) NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  ended_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assist_status (status),
  INDEX idx_assist_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assist_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  intervention_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  details VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assist_ev (intervention_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annuaire des boutiques suivies (poste du technicien High Tech Niger, rôle « serveur HTN »)
CREATE TABLE IF NOT EXISTS assist_boutiques (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(30) NULL,
  phone VARCHAR(40) NULL,
  postes VARCHAR(500) NULL,
  notes VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assist_boutique_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
