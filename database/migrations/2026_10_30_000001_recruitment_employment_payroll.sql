-- =============================================================================
--  Recrutement, emploi et paie
--  * interviews     : entretiens (en ligne ou en présentiel) liés à une candidature
--  * employments    : contrat d'emploi / de prestation (poste, temps de travail, salaire),
--                     figé par empreinte SHA-256 et accepté par l'employé → archive des clauses
--  * payslips       : fiches de paie générées automatiquement (fin de mois ou fin de prestation),
--                     demande de paiement (Mobile Money / présentiel), déclaration de paiement par
--                     l'employeur, confirmation de réception par l'employé
--  * payslip_events : historique complet (jamais supprimé)
-- =============================================================================

CREATE TABLE interviews (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)     NOT NULL,
  application_id    BIGINT UNSIGNED NOT NULL,
  job_post_id       BIGINT UNSIGNED NOT NULL,
  recruiter_user_id BIGINT UNSIGNED NOT NULL,
  candidate_user_id BIGINT UNSIGNED NOT NULL,
  mode              ENUM('EN_LIGNE','PRESENTIEL') NOT NULL,
  scheduled_at      DATETIME     NOT NULL,
  duration_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  meeting_link      VARCHAR(500) NULL,          -- en ligne : lien (Google Meet, Zoom, WhatsApp…)
  location          VARCHAR(255) NULL,          -- présentiel : adresse
  notes             VARCHAR(1000) NULL,
  status            ENUM('PROPOSE','CONFIRME','REPORT_DEMANDE','ANNULE','CONCLUANT','NON_CONCLUANT') NOT NULL DEFAULT 'PROPOSE',
  candidate_note    VARCHAR(500) NULL,
  result_note       VARCHAR(1000) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_interviews_uuid (uuid),
  KEY idx_interviews_application (application_id),
  KEY idx_interviews_candidate (candidate_user_id, scheduled_at),
  KEY idx_interviews_recruiter (recruiter_user_id, scheduled_at),
  CONSTRAINT fk_interviews_app FOREIGN KEY (application_id) REFERENCES job_applications(id),
  CONSTRAINT fk_interviews_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_interviews_recruiter FOREIGN KEY (recruiter_user_id) REFERENCES users(id),
  CONSTRAINT fk_interviews_candidate FOREIGN KEY (candidate_user_id) REFERENCES users(id),
  CONSTRAINT chk_interviews_place CHECK (
    (mode = 'EN_LIGNE' AND meeting_link IS NOT NULL) OR (mode = 'PRESENTIEL' AND location IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36)     NOT NULL,
  reference             VARCHAR(24)  NOT NULL,              -- JN-E-2026-000001
  job_post_id           BIGINT UNSIGNED NULL,
  application_id        BIGINT UNSIGNED NULL,
  employer_user_id      BIGINT UNSIGNED NOT NULL,
  employee_user_id      BIGINT UNSIGNED NOT NULL,
  business_id           BIGINT UNSIGNED NULL,
  position_title        VARCHAR(160) NOT NULL,              -- emploi / poste occupé
  duties                TEXT         NULL,                  -- tâches et clauses particulières
  work_location         VARCHAR(255) NULL,
  work_days             VARCHAR(120) NOT NULL,              -- ex : lundi au samedi
  daily_start_time      TIME         NULL,
  daily_end_time        TIME         NULL,
  hours_per_week        DECIMAL(5,2) NOT NULL,              -- temps de travail
  pay_period            ENUM('MENSUEL','PRESTATION') NOT NULL,
  salary_amount         DECIMAL(15,2) NOT NULL,             -- salaire mensuel ou montant total de la prestation
  currency              CHAR(3)      NOT NULL DEFAULT 'XOF',
  payment_method        ENUM('MOBILE_MONEY','ESPECES') NOT NULL DEFAULT 'MOBILE_MONEY',
  commission_rate       DECIMAL(5,2) NOT NULL DEFAULT 0,    -- figée à la signature (commission JOB NIGER)
  start_date            DATE         NOT NULL,
  end_date              DATE         NULL,                  -- obligatoire pour une prestation
  status                ENUM('EN_ATTENTE_ACCEPTATION','ACTIF','TERMINE','REFUSE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE_ACCEPTATION',
  terms_snapshot        MEDIUMTEXT   NOT NULL,              -- clauses complètes acceptées
  content_hash          CHAR(64)     NOT NULL,
  employee_accepted_at  DATETIME     NULL,
  employee_accept_ip    VARCHAR(45)  NULL,
  ended_at              DATETIME     NULL,
  end_reason            VARCHAR(500) NULL,
  ended_by              BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employments_uuid (uuid),
  UNIQUE KEY uq_employments_reference (reference),
  KEY idx_employments_employer (employer_user_id, status),
  KEY idx_employments_employee (employee_user_id, status),
  KEY idx_employments_status (status, pay_period),
  CONSTRAINT fk_employments_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_employments_app FOREIGN KEY (application_id) REFERENCES job_applications(id),
  CONSTRAINT fk_employments_employer FOREIGN KEY (employer_user_id) REFERENCES users(id),
  CONSTRAINT fk_employments_employee FOREIGN KEY (employee_user_id) REFERENCES users(id),
  CONSTRAINT fk_employments_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_employments_ended_by FOREIGN KEY (ended_by) REFERENCES users(id),
  CONSTRAINT chk_employments_amounts CHECK (salary_amount > 0 AND hours_per_week > 0 AND hours_per_week <= 84 AND commission_rate BETWEEN 0 AND 50),
  CONSTRAINT chk_employments_dates CHECK (end_date IS NULL OR end_date >= start_date),
  CONSTRAINT chk_employments_prestation CHECK (pay_period = 'MENSUEL' OR end_date IS NOT NULL),
  CONSTRAINT chk_employments_parties CHECK (employer_user_id <> employee_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payslips (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36)     NOT NULL,
  number                VARCHAR(30)  NOT NULL,              -- JN-FP-2026-000001
  employment_id         BIGINT UNSIGNED NOT NULL,
  employer_user_id      BIGINT UNSIGNED NOT NULL,
  employee_user_id      BIGINT UNSIGNED NOT NULL,
  period_start          DATE         NOT NULL,
  period_end            DATE         NOT NULL,
  period_label          VARCHAR(60)  NOT NULL,              -- ex : Novembre 2026
  days_in_period        SMALLINT UNSIGNED NOT NULL,
  reference_salary      DECIMAL(15,2) NOT NULL,             -- salaire de référence du contrat
  gross_amount          DECIMAL(15,2) NOT NULL,             -- brut de la période (prorata éventuel)
  commission_rate       DECIMAL(5,2)  NOT NULL,
  commission_amount     DECIMAL(15,2) NOT NULL,
  net_amount            DECIMAL(15,2) NOT NULL,             -- = brut − commission : montant versé à l'employé
  currency              CHAR(3)      NOT NULL DEFAULT 'XOF',
  generated_by          ENUM('AUTOMATIQUE','EMPLOYEUR') NOT NULL DEFAULT 'AUTOMATIQUE',
  status                ENUM('EMISE','PAIEMENT_DEMANDE','PAYEE_DECLAREE','RECUE','CONTESTEE','ANNULEE') NOT NULL DEFAULT 'EMISE',
  payment_method        ENUM('MOBILE_MONEY','ESPECES') NULL,
  payout_phone          VARCHAR(20)  NULL,                  -- numéro Mobile Money de l'employé
  requested_at          DATETIME     NULL,
  employer_paid_at      DATETIME     NULL,
  employer_reference    VARCHAR(120) NULL,                  -- référence de l'opération Mobile Money / reçu
  employee_confirmed_at DATETIME     NULL,
  dispute_note          VARCHAR(1000) NULL,
  payment_id            BIGINT UNSIGNED NULL,               -- paiement enregistré à la confirmation
  content_hash          CHAR(64)     NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payslips_uuid (uuid),
  UNIQUE KEY uq_payslips_number (number),
  UNIQUE KEY uq_payslips_period (employment_id, period_start),   -- jamais deux fiches pour la même période
  KEY idx_payslips_employee (employee_user_id, status),
  KEY idx_payslips_employer (employer_user_id, status),
  KEY idx_payslips_status (status, period_end),
  CONSTRAINT fk_payslips_employment FOREIGN KEY (employment_id) REFERENCES employments(id),
  CONSTRAINT fk_payslips_employer FOREIGN KEY (employer_user_id) REFERENCES users(id),
  CONSTRAINT fk_payslips_employee FOREIGN KEY (employee_user_id) REFERENCES users(id),
  CONSTRAINT fk_payslips_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT chk_payslips_math CHECK (gross_amount > 0 AND commission_amount >= 0 AND net_amount = gross_amount - commission_amount AND net_amount > 0),
  CONSTRAINT chk_payslips_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payslip_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payslip_id    BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,          -- NULL = génération automatique
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NOT NULL,
  note          VARCHAR(1000) NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pe_payslip (payslip_id, id),
  CONSTRAINT fk_pe_payslip FOREIGN KEY (payslip_id) REFERENCES payslips(id),
  CONSTRAINT fk_pe_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nouveaux types d'opérations financières
ALTER TABLE payments MODIFY purpose ENUM('MISSION','ABONNEMENT','SPONSORING','COMMISSION_DIRECTE','SALAIRE') NOT NULL DEFAULT 'MISSION';
ALTER TABLE transactions MODIFY type ENUM('PAIEMENT_MISSION','COMMISSION','VERSEMENT_PRESTATAIRE','REMBOURSEMENT','ANNULATION',
  'AJUSTEMENT','FRAIS_TRANSACTION','CONTESTATION','PAIEMENT_ECHOUE','ABONNEMENT','SPONSORING','ENCAISSEMENT_COMMISSION',
  'SALAIRE') NOT NULL;

-- Paramètres administrables
INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, description) VALUES
  ('jobs.require_verified_recruiter', '0', 'BOOL', 'jobs', 'Publication d''offres réservée aux recruteurs dont les documents sont vérifiés'),
  ('applications.require_verified_worker', '0', 'BOOL', 'jobs', 'Candidature réservée aux prestataires dont l''identité est vérifiée'),
  ('payroll.commission_enabled', '1', 'BOOL', 'finance', 'Appliquer la commission JOB NIGER sur les fiches de paie'),
  ('payroll.generate_on_last_day', '1', 'BOOL', 'finance', 'Générer la fiche mensuelle dès le dernier jour du mois');

-- Garde-fous : fiches de paie et emplois jamais supprimés ; montants d'une fiche immuables
DELIMITER $$
CREATE TRIGGER trg_payslips_guard_update BEFORE UPDATE ON payslips
FOR EACH ROW BEGIN
  IF NEW.gross_amount <> OLD.gross_amount OR NEW.net_amount <> OLD.net_amount
     OR NEW.commission_amount <> OLD.commission_amount OR NEW.employment_id <> OLD.employment_id
     OR NEW.period_start <> OLD.period_start OR NEW.number <> OLD.number OR NEW.content_hash <> OLD.content_hash THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fiche de paie figee : montants et periode immuables';
  END IF;
END$$
CREATE TRIGGER trg_payslips_no_delete BEFORE DELETE ON payslips
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de fiche de paie interdite';
END$$
CREATE TRIGGER trg_payslip_events_no_change BEFORE UPDATE ON payslip_events
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historique de fiche de paie immuable';
END$$
CREATE TRIGGER trg_employments_no_delete BEFORE DELETE ON employments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de contrat interdite : le terminer';
END$$
CREATE TRIGGER trg_employments_guard_update BEFORE UPDATE ON employments
FOR EACH ROW BEGIN
  IF OLD.employee_accepted_at IS NOT NULL AND (NEW.content_hash <> OLD.content_hash OR NEW.salary_amount <> OLD.salary_amount
     OR NEW.terms_snapshot <> OLD.terms_snapshot OR NEW.commission_rate <> OLD.commission_rate) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contrat accepte : clauses immuables';
  END IF;
END$$
DELIMITER ;
