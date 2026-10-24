-- Module Comptabilité générale : exercices, plan comptable, tiers, journaux, écritures (pièces) et lignes

CREATE TABLE IF NOT EXISTS cpt_exercices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  libelle VARCHAR(100) NOT NULL,
  date_debut DATE NOT NULL,
  date_fin DATE NOT NULL,
  statut ENUM('ouvert','cloture') NOT NULL DEFAULT 'ouvert',
  verrou_au DATE NULL,
  resultat DECIMAL(15,2) NULL,
  cloture_le DATETIME NULL,
  cloture_par INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpt_exercice_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpt_comptes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(20) NOT NULL,
  libelle VARCHAR(150) NOT NULL,
  classe TINYINT UNSIGNED NOT NULL,
  tiers_type ENUM('','client','fournisseur','salarie','autre') NOT NULL DEFAULT '',
  lettrable TINYINT(1) NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpt_compte_numero (numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpt_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  nom VARCHAR(150) NOT NULL,
  type ENUM('client','fournisseur','salarie','autre') NOT NULL DEFAULT 'client',
  compte_id INT UNSIGNED NOT NULL,
  telephone VARCHAR(40) NULL,
  nif VARCHAR(40) NULL,
  adresse VARCHAR(190) NULL,
  source VARCHAR(20) NULL,
  source_id INT UNSIGNED NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpt_tiers_code (code),
  UNIQUE KEY uq_cpt_tiers_source (source, source_id),
  CONSTRAINT fk_cpt_tiers_compte FOREIGN KEY (compte_id) REFERENCES cpt_comptes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpt_journaux (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(8) NOT NULL,
  libelle VARCHAR(100) NOT NULL,
  type ENUM('achats','ventes','tresorerie','od','an') NOT NULL DEFAULT 'od',
  compte_id INT UNSIGNED NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpt_journal_code (code),
  CONSTRAINT fk_cpt_journal_compte FOREIGN KEY (compte_id) REFERENCES cpt_comptes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpt_ecritures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exercice_id INT UNSIGNED NOT NULL,
  journal_id INT UNSIGNED NOT NULL,
  numero INT UNSIGNED NULL,
  date_ecriture DATE NOT NULL,
  piece VARCHAR(40) NULL,
  libelle VARCHAR(190) NOT NULL,
  statut ENUM('brouillard','valide') NOT NULL DEFAULT 'brouillard',
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  origine VARCHAR(20) NOT NULL DEFAULT 'manuel',
  origine_ref VARCHAR(60) NULL,
  extourne_de BIGINT UNSIGNED NULL,
  extournee_par BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  valide_le DATETIME NULL,
  valide_par INT UNSIGNED NULL,
  UNIQUE KEY uq_cpt_ecriture_numero (journal_id, exercice_id, numero),
  UNIQUE KEY uq_cpt_ecriture_origine (origine, origine_ref),
  INDEX idx_cpt_ecriture_date (exercice_id, date_ecriture),
  INDEX idx_cpt_ecriture_statut (statut),
  CONSTRAINT fk_cpt_ecriture_exercice FOREIGN KEY (exercice_id) REFERENCES cpt_exercices (id),
  CONSTRAINT fk_cpt_ecriture_journal FOREIGN KEY (journal_id) REFERENCES cpt_journaux (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cpt_lignes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ecriture_id BIGINT UNSIGNED NOT NULL,
  ordre SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  compte_id INT UNSIGNED NOT NULL,
  tiers_id INT UNSIGNED NULL,
  libelle VARCHAR(190) NOT NULL,
  debit DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit DECIMAL(15,2) NOT NULL DEFAULT 0,
  echeance DATE NULL,
  lettrage VARCHAR(8) NULL,
  lettre_le DATETIME NULL,
  INDEX idx_cpt_ligne_compte (compte_id, tiers_id),
  INDEX idx_cpt_ligne_lettrage (compte_id, lettrage),
  CONSTRAINT fk_cpt_ligne_ecriture FOREIGN KEY (ecriture_id) REFERENCES cpt_ecritures (id) ON DELETE CASCADE,
  CONSTRAINT fk_cpt_ligne_compte FOREIGN KEY (compte_id) REFERENCES cpt_comptes (id),
  CONSTRAINT fk_cpt_ligne_tiers FOREIGN KEY (tiers_id) REFERENCES cpt_tiers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
