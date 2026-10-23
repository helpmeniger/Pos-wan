-- Clinique 1.2 : imagerie médicale (appareils, demandes d'examen, examens reçus, images, annotations, comptes rendus)

CREATE TABLE IF NOT EXISTS clinic_imaging_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  modality VARCHAR(8) NOT NULL DEFAULT 'OT',
  ae_title VARCHAR(16) NULL,
  ip VARCHAR(45) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auto_detected TINYINT(1) NOT NULL DEFAULT 0,
  last_seen DATETIME NULL,
  images_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cid_ae (ae_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_imaging_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  modality VARCHAR(8) NOT NULL DEFAULT 'CR',
  exam VARCHAR(150) NOT NULL,
  clinical_info VARCHAR(500) NULL,
  priority ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('demande','realise','annule') NOT NULL DEFAULT 'demande',
  requested_by INT UNSIGNED NOT NULL,
  study_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cir_status (status),
  CONSTRAINT fk_cir_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_imaging_studies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  study_uid VARCHAR(64) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NULL,
  request_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  source ENUM('reseau','dossier','import') NOT NULL DEFAULT 'import',
  modality VARCHAR(8) NULL,
  description VARCHAR(150) NULL,
  accession VARCHAR(30) NULL,
  study_date DATETIME NULL,
  dicom_patient_name VARCHAR(150) NULL,
  dicom_patient_id VARCHAR(64) NULL,
  dicom_birth_date DATE NULL,
  dicom_sex VARCHAR(2) NULL,
  images INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('a_rattacher','a_lire','lu','valide') NOT NULL DEFAULT 'a_lire',
  technique VARCHAR(500) NULL,
  findings TEXT NULL,
  conclusion TEXT NULL,
  reported_by INT UNSIGNED NULL,
  reported_at DATETIME NULL,
  validated_by INT UNSIGNED NULL,
  validated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cis_patient (patient_id),
  INDEX idx_cis_status (status),
  INDEX idx_cis_accession (accession),
  CONSTRAINT fk_cis_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_imaging_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  study_id INT UNSIGNED NOT NULL,
  series_uid VARCHAR(64) NULL,
  series_number INT NULL,
  series_description VARCHAR(100) NULL,
  sop_uid VARCHAR(64) NOT NULL UNIQUE,
  instance_number INT NULL,
  format ENUM('dicom','jpeg','png') NOT NULL DEFAULT 'dicom',
  file_path VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  transfer_syntax VARCHAR(64) NULL,
  width INT NULL,
  height INT NULL,
  frames INT NOT NULL DEFAULT 1,
  pixel_spacing VARCHAR(40) NULL,
  body_part VARCHAR(40) NULL,
  renderable TINYINT(1) NOT NULL DEFAULT 1,
  render_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cii_study (study_id),
  CONSTRAINT fk_cii_study FOREIGN KEY (study_id) REFERENCES clinic_imaging_studies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_imaging_annotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  image_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  data VARCHAR(2000) NOT NULL,
  label VARCHAR(150) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  INDEX idx_cia_image (image_id),
  CONSTRAINT fk_cia_image FOREIGN KEY (image_id) REFERENCES clinic_imaging_images(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('imagerie', 'IMG', 1, YEAR(CURDATE()));
