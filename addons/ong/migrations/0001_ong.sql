-- Module ONG : bailleurs, projets, conventions, budget, trésorerie, dépenses, missions, logistique

CREATE TABLE IF NOT EXISTS ong_bailleurs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  kind ENUM('institution','ong','gouvernement','prive','particulier','autre') NOT NULL DEFAULT 'institution',
  country VARCHAR(80) NULL,
  contact VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  note VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_bailleur (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_projets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(180) NOT NULL,
  sector VARCHAR(100) NULL,
  zone VARCHAR(150) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  manager VARCHAR(120) NULL,
  status ENUM('preparation','en_cours','suspendu','termine') NOT NULL DEFAULT 'en_cours',
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_projet (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_conventions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(60) NOT NULL,
  bailleur_id INT UNSIGNED NOT NULL,
  projet_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  report_frequency ENUM('mensuel','trimestriel','semestriel','annuel','final') NOT NULL DEFAULT 'trimestriel',
  overhead_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  status ENUM('negociation','active','cloturee') NOT NULL DEFAULT 'active',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_convention (number),
  CONSTRAINT fk_ong_conv_bailleur FOREIGN KEY (bailleur_id) REFERENCES ong_bailleurs(id),
  CONSTRAINT fk_ong_conv_projet FOREIGN KEY (projet_id) REFERENCES ong_projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_lignes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  convention_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  label VARCHAR(150) NOT NULL,
  category ENUM('personnel','fonctionnement','activites','equipement','deplacements','frais_admin','autre') NOT NULL DEFAULT 'activites',
  budget DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ong_ligne (convention_id, code),
  CONSTRAINT fk_ong_ligne_conv FOREIGN KEY (convention_id) REFERENCES ong_conventions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_comptes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('banque','caisse','mobile') NOT NULL DEFAULT 'banque',
  bank_name VARCHAR(100) NULL,
  account_number VARCHAR(60) NULL,
  convention_id INT UNSIGNED NULL,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_compte (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_personnel (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricule VARCHAR(30) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  position VARCHAR(120) NULL,
  kind ENUM('salarie','consultant','volontaire','stagiaire','journalier') NOT NULL DEFAULT 'salarie',
  projet_id INT UNSIGNED NULL,
  phone VARCHAR(40) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  monthly_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_personnel (matricule)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_vehicules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plate VARCHAR(30) NOT NULL,
  label VARCHAR(120) NOT NULL,
  kind ENUM('voiture','4x4','moto','camion','autre') NOT NULL DEFAULT '4x4',
  projet_id INT UNSIGNED NULL,
  odometer INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('disponible','en_mission','panne','reforme') NOT NULL DEFAULT 'disponible',
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_ong_vehicule (plate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_missions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL,
  projet_id INT UNSIGNED NOT NULL,
  convention_id INT UNSIGNED NOT NULL,
  ligne_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  destination VARCHAR(180) NOT NULL,
  objective TEXT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  chef_id INT UNSIGNED NOT NULL,
  vehicule_id INT UNSIGNED NULL,
  status ENUM('demandee','approuvee','en_cours','terminee','cloturee','annulee') NOT NULL DEFAULT 'demandee',
  estimate DECIMAL(15,2) NOT NULL DEFAULT 0,
  advance DECIMAL(15,2) NOT NULL DEFAULT 0,
  justified DECIMAL(15,2) NULL,
  report TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  closed_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_mission (number),
  INDEX idx_ong_mission_ligne (ligne_id, status),
  CONSTRAINT fk_ong_mis_projet FOREIGN KEY (projet_id) REFERENCES ong_projets(id),
  CONSTRAINT fk_ong_mis_ligne FOREIGN KEY (ligne_id) REFERENCES ong_lignes(id),
  CONSTRAINT fk_ong_mis_chef FOREIGN KEY (chef_id) REFERENCES ong_personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_mission_membres (
  mission_id INT UNSIGNED NOT NULL,
  personnel_id INT UNSIGNED NOT NULL,
  per_diem DECIMAL(15,2) NOT NULL DEFAULT 0,
  days DECIMAL(6,1) NOT NULL DEFAULT 0,
  PRIMARY KEY (mission_id, personnel_id),
  CONSTRAINT fk_ong_mm_mission FOREIGN KEY (mission_id) REFERENCES ong_missions(id),
  CONSTRAINT fk_ong_mm_pers FOREIGN KEY (personnel_id) REFERENCES ong_personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_depenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL,
  exp_date DATE NOT NULL,
  projet_id INT UNSIGNED NOT NULL,
  convention_id INT UNSIGNED NOT NULL,
  ligne_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  payee VARCHAR(150) NOT NULL,
  description VARCHAR(255) NOT NULL,
  piece VARCHAR(80) NULL,
  mission_id INT UNSIGNED NULL,
  status ENUM('soumise','approuvee','payee','rejetee','annulee') NOT NULL DEFAULT 'soumise',
  compte_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  paid_at DATETIME NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ong_depense (number),
  INDEX idx_ong_dep_ligne (ligne_id, status),
  INDEX idx_ong_dep_date (exp_date),
  CONSTRAINT fk_ong_dep_ligne FOREIGN KEY (ligne_id) REFERENCES ong_lignes(id),
  CONSTRAINT fk_ong_dep_conv FOREIGN KEY (convention_id) REFERENCES ong_conventions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_mouvements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL,
  compte_id INT UNSIGNED NOT NULL,
  direction TINYINT NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  op_date DATE NOT NULL,
  type ENUM('fonds_bailleur','autre_recette','depense','avance_mission','retour_avance','virement_sortant','virement_entrant','ajustement') NOT NULL,
  convention_id INT UNSIGNED NULL,
  depense_id BIGINT UNSIGNED NULL,
  mission_id INT UNSIGNED NULL,
  transfer_id BIGINT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  description VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ong_mv_compte (compte_id, op_date),
  INDEX idx_ong_mv_conv (convention_id, type),
  CONSTRAINT fk_ong_mv_compte FOREIGN KEY (compte_id) REFERENCES ong_comptes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_carnet (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vehicule_id INT UNSIGNED NOT NULL,
  log_date DATE NOT NULL,
  mission_id INT UNSIGNED NULL,
  driver VARCHAR(120) NOT NULL,
  route VARCHAR(180) NULL,
  km_start INT UNSIGNED NOT NULL,
  km_end INT UNSIGNED NOT NULL,
  fuel_liters DECIMAL(8,2) NOT NULL DEFAULT 0,
  fuel_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ong_carnet (vehicule_id, log_date),
  CONSTRAINT fk_ong_carnet_veh FOREIGN KEY (vehicule_id) REFERENCES ong_vehicules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_equipements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(150) NOT NULL,
  category VARCHAR(80) NULL,
  acquired_on DATE NULL,
  value DECIMAL(15,2) NOT NULL DEFAULT 0,
  convention_id INT UNSIGNED NULL,
  location VARCHAR(120) NULL,
  holder VARCHAR(120) NULL,
  state ENUM('bon','moyen','mauvais','hors_service','cede') NOT NULL DEFAULT 'bon',
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_ong_equipement (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'unité',
  stock DECIMAL(15,3) NOT NULL DEFAULT 0,
  alert DECIMAL(15,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ong_article (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_stock_mouvements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  article_id INT UNSIGNED NOT NULL,
  direction TINYINT NOT NULL,
  quantity DECIMAL(15,3) NOT NULL,
  balance_after DECIMAL(15,3) NOT NULL,
  mv_date DATE NOT NULL,
  type ENUM('achat','don','distribution','perte','ajustement') NOT NULL,
  projet_id INT UNSIGNED NULL,
  site VARCHAR(150) NULL,
  beneficiaries INT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ong_sm (article_id, mv_date),
  CONSTRAINT fk_ong_sm_article FOREIGN KEY (article_id) REFERENCES ong_articles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ong_indicateurs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  projet_id INT UNSIGNED NOT NULL,
  label VARCHAR(200) NOT NULL,
  unit VARCHAR(40) NULL,
  target DECIMAL(15,2) NOT NULL DEFAULT 0,
  achieved DECIMAL(15,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  CONSTRAINT fk_ong_ind_projet FOREIGN KEY (projet_id) REFERENCES ong_projets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('ong_depense', 'DP', 1, YEAR(CURDATE()));

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('ong_mission', 'OM', 1, YEAR(CURDATE()));

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('ong_tresorerie', 'OT', 1, YEAR(CURDATE()));
