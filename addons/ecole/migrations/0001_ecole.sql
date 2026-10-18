-- Module Établissement scolaire / université : élèves, personnel, classes, frais, notes, présences

CREATE TABLE IF NOT EXISTS ecole_annees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  libelle VARCHAR(20) NOT NULL UNIQUE,
  debut DATE NOT NULL,
  fin DATE NOT NULL,
  courante TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_periodes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annee_id INT UNSIGNED NOT NULL,
  nom VARCHAR(50) NOT NULL,
  ordre TINYINT UNSIGNED NOT NULL DEFAULT 1,
  debut DATE NULL,
  fin DATE NULL,
  cloturee TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_eper_annee FOREIGN KEY (annee_id) REFERENCES ecole_annees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_personnel (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricule VARCHAR(30) NOT NULL UNIQUE,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  sexe ENUM('M','F') NULL,
  fonction ENUM('enseignant','direction','administration','surveillance','service','autre') NOT NULL DEFAULT 'enseignant',
  poste VARCHAR(100) NULL,
  telephone VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  adresse VARCHAR(255) NULL,
  date_naissance DATE NULL,
  date_embauche DATE NULL,
  salaire DECIMAL(15,2) NULL,
  photo VARCHAR(80) NULL,
  statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
  consentement_visage TINYINT(1) NOT NULL DEFAULT 0,
  consentement_date DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eper_nom (nom, prenom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annee_id INT UNSIGNED NOT NULL,
  nom VARCHAR(80) NOT NULL,
  niveau VARCHAR(60) NULL,
  cycle ENUM('maternelle','primaire','college','lycee','superieur','formation') NOT NULL DEFAULT 'primaire',
  salle VARCHAR(50) NULL,
  capacite SMALLINT UNSIGNED NULL,
  titulaire_id INT UNSIGNED NULL,
  frais_inscription DECIMAL(15,2) NOT NULL DEFAULT 0,
  frais_scolarite DECIMAL(15,2) NOT NULL DEFAULT 0,
  nb_tranches TINYINT UNSIGNED NOT NULL DEFAULT 3,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ecla (annee_id, nom),
  CONSTRAINT fk_ecla_annee FOREIGN KEY (annee_id) REFERENCES ecole_annees(id),
  CONSTRAINT fk_ecla_tit FOREIGN KEY (titulaire_id) REFERENCES ecole_personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_matieres (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL UNIQUE,
  code VARCHAR(20) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_classe_matieres (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  classe_id INT UNSIGNED NOT NULL,
  matiere_id INT UNSIGNED NOT NULL,
  coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
  credits DECIMAL(5,2) NULL,
  enseignant_id INT UNSIGNED NULL,
  UNIQUE KEY uq_ecm (classe_id, matiere_id),
  CONSTRAINT fk_ecm_cla FOREIGN KEY (classe_id) REFERENCES ecole_classes(id),
  CONSTRAINT fk_ecm_mat FOREIGN KEY (matiere_id) REFERENCES ecole_matieres(id),
  CONSTRAINT fk_ecm_ens FOREIGN KEY (enseignant_id) REFERENCES ecole_personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_eleves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  matricule VARCHAR(30) NOT NULL UNIQUE,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  sexe ENUM('M','F') NOT NULL,
  date_naissance DATE NULL,
  lieu_naissance VARCHAR(100) NULL,
  nationalite VARCHAR(60) NULL,
  adresse VARCHAR(255) NULL,
  telephone VARCHAR(40) NULL,
  email VARCHAR(150) NULL,
  tuteur_nom VARCHAR(150) NULL,
  tuteur_lien VARCHAR(40) NULL,
  tuteur_tel VARCHAR(40) NULL,
  tuteur_tel2 VARCHAR(40) NULL,
  infos_medicales TEXT NULL,
  photo VARCHAR(80) NULL,
  statut ENUM('actif','suspendu','sorti','diplome') NOT NULL DEFAULT 'actif',
  consentement_visage TINYINT(1) NOT NULL DEFAULT 0,
  consentement_par VARCHAR(150) NULL,
  consentement_date DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_eel_nom (nom, prenom),
  INDEX idx_eel_tut (tuteur_tel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_inscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eleve_id INT UNSIGNED NOT NULL,
  annee_id INT UNSIGNED NOT NULL,
  classe_id INT UNSIGNED NOT NULL,
  date_inscription DATE NOT NULL,
  type ENUM('nouvelle','reinscription','redoublement','transfert') NOT NULL DEFAULT 'nouvelle',
  remise DECIMAL(15,2) NOT NULL DEFAULT 0,
  motif_remise VARCHAR(150) NULL,
  statut ENUM('active','abandon','transferee') NOT NULL DEFAULT 'active',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eins (eleve_id, annee_id),
  INDEX idx_eins_cla (classe_id),
  CONSTRAINT fk_eins_el FOREIGN KEY (eleve_id) REFERENCES ecole_eleves(id),
  CONSTRAINT fk_eins_an FOREIGN KEY (annee_id) REFERENCES ecole_annees(id),
  CONSTRAINT fk_eins_cla FOREIGN KEY (classe_id) REFERENCES ecole_classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_echeances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inscription_id INT UNSIGNED NOT NULL,
  type ENUM('inscription','scolarite','autre') NOT NULL DEFAULT 'scolarite',
  libelle VARCHAR(100) NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  paye DECIMAL(15,2) NOT NULL DEFAULT 0,
  date_limite DATE NULL,
  annule TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_eech_ins (inscription_id),
  CONSTRAINT fk_eech_ins FOREIGN KEY (inscription_id) REFERENCES ecole_inscriptions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_paiements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE,
  uuid CHAR(36) NOT NULL UNIQUE,
  inscription_id INT UNSIGNED NOT NULL,
  eleve_id INT UNSIGNED NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  mode VARCHAR(30) NOT NULL DEFAULT 'cash',
  operateur VARCHAR(40) NULL,
  reference VARCHAR(100) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  annule TINYINT(1) NOT NULL DEFAULT 0,
  annule_par INT UNSIGNED NULL,
  annule_le DATETIME NULL,
  motif_annulation VARCHAR(255) NULL,
  INDEX idx_epai_date (created_at),
  INDEX idx_epai_el (eleve_id),
  CONSTRAINT fk_epai_ins FOREIGN KEY (inscription_id) REFERENCES ecole_inscriptions(id),
  CONSTRAINT fk_epai_el FOREIGN KEY (eleve_id) REFERENCES ecole_eleves(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_paiement_lignes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paiement_id INT UNSIGNED NOT NULL,
  echeance_id INT UNSIGNED NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_epl_pai FOREIGN KEY (paiement_id) REFERENCES ecole_paiements(id),
  CONSTRAINT fk_epl_ech FOREIGN KEY (echeance_id) REFERENCES ecole_echeances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_salaires (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE,
  personnel_id INT UNSIGNED NOT NULL,
  periode CHAR(7) NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  mode VARCHAR(30) NOT NULL DEFAULT 'cash',
  reference VARCHAR(100) NULL,
  note VARCHAR(255) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  annule TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_esal_per (periode),
  CONSTRAINT fk_esal_pers FOREIGN KEY (personnel_id) REFERENCES ecole_personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_evaluations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  classe_id INT UNSIGNED NOT NULL,
  matiere_id INT UNSIGNED NOT NULL,
  periode_id INT UNSIGNED NOT NULL,
  type ENUM('devoir','interrogation','composition','examen','tp') NOT NULL DEFAULT 'devoir',
  titre VARCHAR(100) NOT NULL,
  date DATE NOT NULL,
  bareme DECIMAL(5,2) NOT NULL DEFAULT 20,
  coefficient DECIMAL(5,2) NOT NULL DEFAULT 1,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eev (classe_id, periode_id),
  CONSTRAINT fk_eev_cla FOREIGN KEY (classe_id) REFERENCES ecole_classes(id),
  CONSTRAINT fk_eev_mat FOREIGN KEY (matiere_id) REFERENCES ecole_matieres(id),
  CONSTRAINT fk_eev_per FOREIGN KEY (periode_id) REFERENCES ecole_periodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_notes (
  evaluation_id INT UNSIGNED NOT NULL,
  eleve_id INT UNSIGNED NOT NULL,
  note DECIMAL(5,2) NULL,
  absent TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (evaluation_id, eleve_id),
  CONSTRAINT fk_eno_ev FOREIGN KEY (evaluation_id) REFERENCES ecole_evaluations(id),
  CONSTRAINT fk_eno_el FOREIGN KEY (eleve_id) REFERENCES ecole_eleves(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_bulletins (
  inscription_id INT UNSIGNED NOT NULL,
  periode_id INT UNSIGNED NOT NULL,
  appreciation VARCHAR(500) NULL,
  conduite VARCHAR(100) NULL,
  decision VARCHAR(100) NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (inscription_id, periode_id),
  CONSTRAINT fk_ebu_ins FOREIGN KEY (inscription_id) REFERENCES ecole_inscriptions(id),
  CONSTRAINT fk_ebu_per FOREIGN KEY (periode_id) REFERENCES ecole_periodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_presences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_type ENUM('eleve','personnel') NOT NULL,
  personne_id INT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  arrivee TIME NULL,
  depart TIME NULL,
  statut ENUM('present','retard','absent','excuse') NOT NULL DEFAULT 'present',
  source ENUM('manuel','camera') NOT NULL DEFAULT 'manuel',
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_epr (personne_type, personne_id, date),
  INDEX idx_epr_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Points de pointage (portail, couloir…) : caméra du poste ou caméra IP du module Reconnaissance
CREATE TABLE IF NOT EXISTS ecole_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  role ENUM('entree','sortie','passage') NOT NULL DEFAULT 'passage',
  vision_camera_id INT UNSIGNED NULL,
  device_label VARCHAR(200) NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empreintes faciales (128 nombres) : uniquement avec le consentement enregistré
CREATE TABLE IF NOT EXISTS ecole_visages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  personne_type ENUM('eleve','personnel') NOT NULL,
  personne_id INT UNSIGNED NOT NULL,
  descriptor VARBINARY(512) NOT NULL,
  source ENUM('inscription','pointage','correction') NOT NULL DEFAULT 'inscription',
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_evi (personne_type, personne_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ecole_passages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  point_id INT UNSIGNED NULL,
  role ENUM('entree','sortie','passage') NOT NULL DEFAULT 'passage',
  personne_type ENUM('eleve','personnel') NULL,
  personne_id INT UNSIGNED NULL,
  distance DECIMAL(5,3) NULL,
  marge DECIMAL(5,3) NULL,
  statut ENUM('reconnu','inconnu','confirme','corrige','ignore') NOT NULL DEFAULT 'reconnu',
  thumb MEDIUMTEXT NULL,
  vu_a DATETIME NOT NULL,
  user_id INT UNSIGNED NULL,
  INDEX idx_epa_vu (vu_a)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES
  ('ecole_eleve', 'EL', 1, YEAR(CURDATE())), ('ecole_personnel', 'PE', 1, YEAR(CURDATE())),
  ('ecole_recu', 'REC', 1, YEAR(CURDATE())), ('ecole_salaire', 'SAL', 1, YEAR(CURDATE()));
