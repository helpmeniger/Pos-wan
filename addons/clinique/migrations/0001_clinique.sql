-- Module Clinique : patients, rendez-vous, consultations, constantes, ordonnances,
-- hospitalisation (lits, plan de soins), facturation

CREATE TABLE IF NOT EXISTS clinic_patients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  last_name VARCHAR(100) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  sex ENUM('M','F') NOT NULL,
  birth_date DATE NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  blood_group VARCHAR(5) NULL,
  allergies VARCHAR(500) NULL,
  history TEXT NULL,
  emergency_contact VARCHAR(150) NULL,
  insurer VARCHAR(100) NULL,
  coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cp_name (last_name, first_name),
  INDEX idx_cp_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id INT UNSIGNED NOT NULL,
  practitioner_id INT UNSIGNED NULL,
  start_at DATETIME NOT NULL,
  duration_min INT NOT NULL DEFAULT 20,
  reason VARCHAR(255) NULL,
  status ENUM('planned','arrived','done','no_show','cancelled') NOT NULL DEFAULT 'planned',
  visit_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ca_start (start_at),
  CONSTRAINT fk_ca_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_visits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  practitioner_id INT UNSIGNED NULL,
  status ENUM('waiting','in_progress','done','cancelled') NOT NULL DEFAULT 'waiting',
  priority ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  reason VARCHAR(255) NULL,
  anamnesis TEXT NULL,
  exam TEXT NULL,
  diagnosis VARCHAR(500) NULL,
  conclusion TEXT NULL,
  bill_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  closed_at DATETIME NULL,
  INDEX idx_cv_status (status),
  CONSTRAINT fk_cv_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  ward VARCHAR(80) NOT NULL DEFAULT 'Hospitalisation',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_beds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  daily_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_cb_room FOREIGN KEY (room_id) REFERENCES clinic_rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_stays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  bed_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  kind ENUM('hospitalisation','observation') NOT NULL DEFAULT 'hospitalisation',
  reason VARCHAR(255) NOT NULL,
  diagnosis VARCHAR(500) NULL,
  status ENUM('admitted','discharged') NOT NULL DEFAULT 'admitted',
  open_bed INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'admitted' THEN bed_id ELSE NULL END) STORED,
  admitted_at DATETIME NOT NULL,
  discharged_at DATETIME NULL,
  discharge_mode ENUM('gueri','ameliore','transfert','contre_avis','deces','autre') NULL,
  discharge_note VARCHAR(500) NULL,
  bill_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_cs_open_bed (open_bed),
  INDEX idx_cs_status (status),
  CONSTRAINT fk_cs_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id),
  CONSTRAINT fk_cs_bed FOREIGN KEY (bed_id) REFERENCES clinic_beds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_vitals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  temperature DECIMAL(4,1) NULL,
  systolic SMALLINT NULL,
  diastolic SMALLINT NULL,
  pulse SMALLINT NULL,
  spo2 SMALLINT NULL,
  resp_rate SMALLINT NULL,
  glycemia DECIMAL(4,2) NULL,
  weight DECIMAL(5,1) NULL,
  height SMALLINT NULL,
  pain TINYINT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cvt_stay (stay_id, taken_at),
  CONSTRAINT fk_cvt_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_care_plan (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id INT UNSIGNED NOT NULL,
  kind ENUM('medication','care','monitoring') NOT NULL DEFAULT 'medication',
  label VARCHAR(150) NOT NULL,
  dose VARCHAR(100) NULL,
  route VARCHAR(50) NULL,
  every_hours SMALLINT NOT NULL DEFAULT 8,
  next_due_at DATETIME NOT NULL,
  end_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  prescriber_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ccp_due (active, next_due_at),
  CONSTRAINT fk_ccp_stay FOREIGN KEY (stay_id) REFERENCES clinic_stays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_care_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  stay_id INT UNSIGNED NOT NULL,
  status ENUM('done','refused','skipped') NOT NULL DEFAULT 'done',
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ccl_plan FOREIGN KEY (plan_id) REFERENCES clinic_care_plan(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id INT UNSIGNED NOT NULL,
  kind ENUM('nurse','doctor') NOT NULL,
  note TEXT NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cn_stay FOREIGN KEY (stay_id) REFERENCES clinic_stays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_prescriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  prescriber_id INT UNSIGNED NOT NULL,
  status ENUM('new','partial','dispensed','cancelled') NOT NULL DEFAULT 'new',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispensed_at DATETIME NULL,
  INDEX idx_cpr_status (status),
  CONSTRAINT fk_cpr_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_prescription_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prescription_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  drug_name VARCHAR(150) NOT NULL,
  posology VARCHAR(255) NULL,
  duration VARCHAR(50) NULL,
  quantity INT NOT NULL DEFAULT 1,
  dispensed_qty INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_cpi_presc FOREIGN KEY (prescription_id) REFERENCES clinic_prescriptions(id),
  CONSTRAINT fk_cpi_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_dispensings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prescription_id INT UNSIGNED NOT NULL,
  sale_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cd_presc FOREIGN KEY (prescription_id) REFERENCES clinic_prescriptions(id),
  CONSTRAINT fk_cd_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_acts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  category VARCHAR(60) NOT NULL DEFAULT 'Consultation',
  price DECIMAL(15,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  insurer VARCHAR(100) NULL,
  coverage_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  patient_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
  cancel_reason VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cbl_status (status),
  CONSTRAINT fk_cbl_patient FOREIGN KEY (patient_id) REFERENCES clinic_patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_bill_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id INT UNSIGNED NOT NULL,
  act_id INT UNSIGNED NULL,
  label VARCHAR(150) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  CONSTRAINT fk_cbi_bill FOREIGN KEY (bill_id) REFERENCES clinic_bills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinic_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id INT UNSIGNED NOT NULL,
  method VARCHAR(30) NOT NULL,
  provider VARCHAR(50) NULL,
  amount DECIMAL(15,2) NOT NULL,
  reference VARCHAR(100) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cpay_bill FOREIGN KEY (bill_id) REFERENCES clinic_bills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('clinic_patient', 'PAT', 1, YEAR(CURDATE()));
INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('clinic_visit', 'CS', 1, YEAR(CURDATE()));
INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('clinic_stay', 'HOSP', 1, YEAR(CURDATE()));
INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('clinic_prescription', 'ORD', 1, YEAR(CURDATE()));
INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('clinic_bill', 'FC', 1, YEAR(CURDATE()));

-- Actes indicatifs (FCFA), modifiables dans Clinique → Actes, lits, rapport
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Consultation de médecine générale', 'Consultation', 3000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Consultation spécialisée', 'Consultation', 7500);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Consultation pédiatrique', 'Consultation', 3000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Consultation prénatale (CPN)', 'Consultation', 2500);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Consultation de nuit / urgence', 'Consultation', 5000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Pansement simple', 'Soins', 1000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Pansement complexe', 'Soins', 2500);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Injection', 'Soins', 500);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Pose de perfusion', 'Soins', 2000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Suture (petite plaie)', 'Soins', 5000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Accouchement normal', 'Maternité', 25000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Goutte épaisse / TDR paludisme', 'Laboratoire', 1500);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Glycémie', 'Laboratoire', 1000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Numération formule sanguine (NFS)', 'Laboratoire', 4000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Échographie', 'Imagerie', 10000);
INSERT IGNORE INTO clinic_acts (name, category, price) VALUES ('Mise en observation (journée)', 'Hospitalisation', 5000);

INSERT IGNORE INTO clinic_rooms (id, name, ward, sort_order) VALUES (1, 'Salle 1', 'Hospitalisation', 1);
INSERT IGNORE INTO clinic_rooms (id, name, ward, sort_order) VALUES (2, 'Salle d''observation', 'Urgences', 2);
INSERT IGNORE INTO clinic_beds (room_id, name, daily_price) SELECT 1, 'Lit 1', 7500 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM clinic_beds);
INSERT IGNORE INTO clinic_beds (room_id, name, daily_price) SELECT 1, 'Lit 2', 7500 FROM DUAL WHERE (SELECT COUNT(*) FROM clinic_beds) = 1;
INSERT IGNORE INTO clinic_beds (room_id, name, daily_price) SELECT 2, 'Obs 1', 5000 FROM DUAL WHERE (SELECT COUNT(*) FROM clinic_beds) = 2;
INSERT IGNORE INTO clinic_beds (room_id, name, daily_price) SELECT 2, 'Obs 2', 5000 FROM DUAL WHERE (SELECT COUNT(*) FROM clinic_beds) = 3;
