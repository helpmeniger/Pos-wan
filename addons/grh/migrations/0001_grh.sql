-- Module Ressources humaines : structure, employés, contrats, congés, présences, avances, paie, dossier

CREATE TABLE IF NOT EXISTS grh_departements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  parent_id INT UNSIGNED NULL,
  manager_id INT UNSIGNED NULL,
  UNIQUE KEY uq_grh_dep (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_postes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  departement_id INT UNSIGNED NULL,
  grade VARCHAR(40) NULL,
  base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_grh_poste (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_employes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricule VARCHAR(30) NOT NULL,
  civility ENUM('M.','Mme','Mlle') NOT NULL DEFAULT 'M.',
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  sex ENUM('M','F') NOT NULL DEFAULT 'M',
  birth_date DATE NULL,
  birth_place VARCHAR(100) NULL,
  nationality VARCHAR(60) NULL,
  marital ENUM('celibataire','marie','divorce','veuf') NOT NULL DEFAULT 'celibataire',
  children TINYINT UNSIGNED NOT NULL DEFAULT 0,
  phone VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(200) NULL,
  id_number VARCHAR(60) NULL,
  cnss_number VARCHAR(40) NULL,
  bank_account VARCHAR(80) NULL,
  emergency_contact VARCHAR(150) NULL,
  departement_id INT UNSIGNED NULL,
  poste_id INT UNSIGNED NULL,
  manager_id INT UNSIGNED NULL,
  hire_date DATE NOT NULL,
  status ENUM('actif','suspendu','sorti') NOT NULL DEFAULT 'actif',
  exit_date DATE NULL,
  exit_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grh_emp (matricule),
  INDEX idx_grh_emp_dep (departement_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_contrats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employe_id INT UNSIGNED NOT NULL,
  kind ENUM('cdi','cdd','essai','stage','prestation') NOT NULL DEFAULT 'cdi',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowances DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('en_cours','termine') NOT NULL DEFAULT 'en_cours',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grh_contrat (employe_id, status),
  CONSTRAINT fk_grh_contrat_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_conges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employe_id INT UNSIGNED NOT NULL,
  kind ENUM('annuel','maladie','maternite','exceptionnel','sans_solde') NOT NULL DEFAULT 'annuel',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days DECIMAL(5,1) NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('demande','approuve','refuse','annule') NOT NULL DEFAULT 'demande',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grh_conge (employe_id, status, start_date),
  CONSTRAINT fk_grh_conge_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_presences (
  employe_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  status ENUM('present','absent','retard','mission','repos') NOT NULL,
  note VARCHAR(150) NULL,
  user_id INT UNSIGNED NULL,
  PRIMARY KEY (employe_id, day),
  INDEX idx_grh_pres_day (day),
  CONSTRAINT fk_grh_pres_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_avances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employe_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  monthly DECIMAL(15,2) NOT NULL,
  remaining DECIMAL(15,2) NOT NULL,
  granted_on DATE NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('en_cours','soldee','annulee') NOT NULL DEFAULT 'en_cours',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grh_avance (employe_id, status),
  CONSTRAINT fk_grh_avance_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_paies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL,
  status ENUM('brouillon','validee','payee') NOT NULL DEFAULT 'brouillon',
  validated_by INT UNSIGNED NULL,
  validated_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grh_paie (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_bulletins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paie_id INT UNSIGNED NOT NULL,
  employe_id INT UNSIGNED NOT NULL,
  base DECIMAL(15,2) NOT NULL DEFAULT 0,
  allowances DECIMAL(15,2) NOT NULL DEFAULT 0,
  bonus DECIMAL(15,2) NOT NULL DEFAULT 0,
  overtime DECIMAL(15,2) NOT NULL DEFAULT 0,
  absence_days DECIMAL(5,1) NOT NULL DEFAULT 0,
  absence_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
  gross DECIMAL(15,2) NOT NULL DEFAULT 0,
  cnss DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_manual TINYINT(1) NOT NULL DEFAULT 0,
  advance DECIMAL(15,2) NOT NULL DEFAULT 0,
  other_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
  net DECIMAL(15,2) NOT NULL DEFAULT 0,
  employer_charges DECIMAL(15,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_grh_bulletin (paie_id, employe_id),
  CONSTRAINT fk_grh_bul_paie FOREIGN KEY (paie_id) REFERENCES grh_paies(id),
  CONSTRAINT fk_grh_bul_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grh_dossier (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employe_id INT UNSIGNED NOT NULL,
  kind ENUM('evaluation','sanction','promotion','formation','note') NOT NULL,
  event_date DATE NOT NULL,
  title VARCHAR(180) NOT NULL,
  detail TEXT NULL,
  score DECIMAL(4,1) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_grh_dossier (employe_id, event_date),
  CONSTRAINT fk_grh_dossier_emp FOREIGN KEY (employe_id) REFERENCES grh_employes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
