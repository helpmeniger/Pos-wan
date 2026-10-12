-- VERIFIA — schéma MySQL 8 / MariaDB 10.6+
-- Encodage utf8mb4, moteur InnoDB (clés étrangères + transactions).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS roles (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL,
  label         VARCHAR(100) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id         TINYINT UNSIGNED NOT NULL,
  username        VARCHAR(64)  NOT NULL,
  display_name    VARCHAR(120) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sites surveillés (un site = un domaine ; plusieurs sessions peuvent viser le même site)
CREATE TABLE IF NOT EXISTS monitored_sites (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain             VARCHAR(190) NOT NULL,
  name               VARCHAR(120) NOT NULL,
  adapter            VARCHAR(40)  NOT NULL DEFAULT 'generic',
  automation_allowed TINYINT(1) NOT NULL DEFAULT 1,
  is_satire          TINYINT(1) NOT NULL DEFAULT 0,
  notes              TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_sessions (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid                CHAR(36) NOT NULL,
  site_id            INT UNSIGNED NULL,
  name               VARCHAR(120) NOT NULL,
  start_url          VARCHAR(2048) NOT NULL,
  -- état demandé par l'interface ; le moteur de surveillance converge vers cet état
  desired_state      ENUM('stopped','running','paused','closed') NOT NULL DEFAULT 'stopped',
  -- état réel rapporté par le moteur de surveillance
  run_state          ENUM('stopped','starting','running','paused','error','closed') NOT NULL DEFAULT 'stopped',
  connection_state   ENUM('unknown','ok','login_required','captcha','blocked','offline') NOT NULL DEFAULT 'unknown',
  analysis_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  autoscroll_enabled TINYINT(1) NOT NULL DEFAULT 1,
  scroll_step_px     SMALLINT UNSIGNED NOT NULL DEFAULT 600,
  scroll_delay_ms    INT UNSIGNED NOT NULL DEFAULT 4000,
  processing_interval_ms INT UNSIGNED NOT NULL DEFAULT 2000,
  max_posts          INT UNSIGNED NOT NULL DEFAULT 500,
  max_duration_min   INT UNSIGNED NOT NULL DEFAULT 120,
  window_x           INT NULL,
  window_y           INT NULL,
  window_w           INT UNSIGNED NULL,
  window_h           INT UNSIGNED NULL,
  posts_seen         INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_count       INT UNSIGNED NOT NULL DEFAULT 0,
  status_message     VARCHAR(500) NULL,
  current_url        VARCHAR(2048) NULL,
  last_heartbeat_at  DATETIME NULL,
  started_at         DATETIME NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at          DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_uid (uid),
  KEY idx_sessions_state (desired_state, run_state),
  KEY idx_sessions_site (site_id),
  CONSTRAINT fk_sessions_site FOREIGN KEY (site_id) REFERENCES monitored_sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commandes ponctuelles transmises au moteur de surveillance (scan manuel, redémarrage, ...)
CREATE TABLE IF NOT EXISTS session_commands (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id   INT UNSIGNED NOT NULL,
  command      VARCHAR(32) NOT NULL,
  payload      JSON NULL,
  status       ENUM('pending','delivered','done','failed') NOT NULL DEFAULT 'pending',
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cmd_pending (status, session_id),
  CONSTRAINT fk_cmd_session FOREIGN KEY (session_id) REFERENCES monitoring_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publication dédupliquée (une ligne par contenu unique)
CREATE TABLE IF NOT EXISTS detected_publications (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fingerprint       CHAR(64) NOT NULL,          -- sha256(site + permalien ou texte normalisé)
  first_session_id  INT UNSIGNED NULL,          -- session d'origine (première détection)
  site_id           INT UNSIGNED NULL,
  permalink         VARCHAR(2048) NULL,
  author_label      VARCHAR(255) NULL,
  published_label   VARCHAR(120) NULL,          -- date telle qu'affichée par le site
  source            ENUM('monitor','manual') NOT NULL DEFAULT 'monitor',
  occurrences       INT UNSIGNED NOT NULL DEFAULT 1,
  status            ENUM('queued','analyzing','analyzed','failed') NOT NULL DEFAULT 'queued',
  first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pub_fingerprint (fingerprint),
  KEY idx_pub_session (first_session_id),
  KEY idx_pub_site (site_id),
  KEY idx_pub_seen (first_seen_at),
  CONSTRAINT fk_pub_session FOREIGN KEY (first_session_id) REFERENCES monitoring_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pub_site FOREIGN KEY (site_id) REFERENCES monitored_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trace de chaque détection (même publication vue dans plusieurs sessions)
CREATE TABLE IF NOT EXISTS publication_occurrences (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id  BIGINT UNSIGNED NOT NULL,
  session_id      INT UNSIGNED NULL,
  page_url        VARCHAR(2048) NULL,
  seen_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_occ (publication_id, session_id),
  KEY idx_occ_session (session_id),
  CONSTRAINT fk_occ_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_session FOREIGN KEY (session_id) REFERENCES monitoring_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publication_content (
  publication_id  BIGINT UNSIGNED NOT NULL,
  text_content    MEDIUMTEXT NULL,
  text_simhash    BIGINT NULL,          -- SimHash 64 bits (signé)
  links_json      JSON NULL,
  dates_json      JSON NULL,
  thumbnail_path  VARCHAR(255) NULL,
  media_json      JSON NULL,            -- images reçues, en attente de hachage par le moteur d'analyse
  language        VARCHAR(8) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (publication_id),
  KEY idx_content_simhash (text_simhash),
  FULLTEXT KEY ft_content_text (text_content),
  CONSTRAINT fk_content_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File de tâches : priorité, tentatives, verrou de traitement
CREATE TABLE IF NOT EXISTS analysis_jobs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id  BIGINT UNSIGNED NOT NULL,
  job_type        VARCHAR(32) NOT NULL DEFAULT 'full_analysis',
  priority        TINYINT UNSIGNED NOT NULL DEFAULT 5,   -- plus petit = plus urgent
  status          ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  available_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_by       VARCHAR(64) NULL,
  locked_at       DATETIME NULL,
  last_error      TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_claim (status, available_at, priority, id),
  KEY idx_jobs_pub (publication_id),
  CONSTRAINT fk_jobs_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analysis_results (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id     BIGINT UNSIGNED NOT NULL,
  category           VARCHAR(40) NOT NULL,
  topic              VARCHAR(120) NULL,
  priority           ENUM('low','normal','high','critical') NOT NULL DEFAULT 'low',
  score              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  confidence         DECIMAL(4,3) NOT NULL DEFAULT 0,
  indicators_json    JSON NULL,       -- liste des indices détectés (code, libellé, poids, extrait)
  places_json        JSON NULL,
  sources_json       JSON NULL,
  similar_json       JSON NULL,       -- publications antérieures similaires
  limitations        TEXT NULL,
  analyzer_version   VARCHAR(20) NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_results_pub (publication_id),
  KEY idx_results_priority (priority),
  CONSTRAINT fk_results_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id   BIGINT UNSIGNED NOT NULL,
  claim_text       TEXT NOT NULL,
  claim_hash       CHAR(64) NOT NULL,
  claim_type       VARCHAR(40) NOT NULL DEFAULT 'factual',
  verdict          ENUM('unverified','corroborated','contradicted','mixed','unprovable') NOT NULL DEFAULT 'unverified',
  verification_method TEXT NULL,
  limitations      TEXT NULL,
  external_search_done TINYINT(1) NOT NULL DEFAULT 0,
  verified_by      INT UNSIGNED NULL,
  verified_at      DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claims_pub (publication_id),
  KEY idx_claims_hash (claim_hash),
  CONSTRAINT fk_claims_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE,
  CONSTRAINT fk_claims_user FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sources saisies par un analyste (jamais générées automatiquement)
CREATE TABLE IF NOT EXISTS evidence_sources (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id        BIGINT UNSIGNED NULL,
  image_asset_id  BIGINT UNSIGNED NULL,
  url             VARCHAR(2048) NOT NULL,
  title           VARCHAR(500) NULL,
  publisher       VARCHAR(255) NULL,
  stance          ENUM('corroborates','contradicts','context','neutral') NOT NULL DEFAULT 'neutral',
  source_published_at DATE NULL,
  independence    ENUM('independent','related','same_origin','unknown') NOT NULL DEFAULT 'unknown',
  method          VARCHAR(255) NULL,
  notes           TEXT NULL,
  consulted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  added_by        INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_evidence_claim (claim_id),
  KEY idx_evidence_image (image_asset_id),
  CONSTRAINT fk_evidence_claim FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE,
  CONSTRAINT fk_evidence_image FOREIGN KEY (image_asset_id) REFERENCES image_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_evidence_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_assets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id  BIGINT UNSIGNED NOT NULL,
  sha256          CHAR(64) NOT NULL,
  dhash           BIGINT NOT NULL,      -- empreinte perceptuelle (différence)
  ahash           BIGINT NOT NULL,      -- empreinte perceptuelle (moyenne)
  width           INT UNSIGNED NULL,
  height          INT UNSIGNED NULL,
  file_path       VARCHAR(255) NOT NULL,
  source_url      VARCHAR(2048) NULL,
  caption         TEXT NULL,
  ocr_text        TEXT NULL,
  ocr_status      VARCHAR(40) NOT NULL DEFAULT 'not_run',
  exif_json       JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_img_pub (publication_id),
  KEY idx_img_sha (sha256),
  KEY idx_img_dhash (dhash),
  CONSTRAINT fk_img_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_matches (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  image_id         BIGINT UNSIGNED NOT NULL,   -- nouvelle image
  matched_image_id BIGINT UNSIGNED NOT NULL,   -- image déjà connue
  match_type       ENUM('exact','perceptual') NOT NULL,
  distance         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  caption_similarity DECIMAL(4,3) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match (image_id, matched_image_id),
  KEY idx_match_matched (matched_image_id),
  CONSTRAINT fk_match_img FOREIGN KEY (image_id) REFERENCES image_assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_matched FOREIGN KEY (matched_image_id) REFERENCES image_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id   BIGINT UNSIGNED NOT NULL,
  result_id        BIGINT UNSIGNED NULL,
  session_id       INT UNSIGNED NULL,
  -- copies dénormalisées pour que l'alerte reste lisible même si la session est supprimée
  session_name     VARCHAR(120) NULL,
  site_name        VARCHAR(120) NULL,
  priority         ENUM('low','normal','high','critical') NOT NULL,
  category         VARCHAR(40) NOT NULL,
  reason           VARCHAR(1000) NOT NULL,
  excerpt          TEXT NULL,
  confidence       DECIMAL(4,3) NOT NULL DEFAULT 0,
  status           ENUM('new','read','in_review','verified','ignored') NOT NULL DEFAULT 'new',
  is_important     TINYINT(1) NOT NULL DEFAULT 0,
  assigned_to      INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert_pub (publication_id),
  KEY idx_alerts_status (status, priority),
  KEY idx_alerts_created (created_at),
  KEY idx_alerts_session (session_id),
  CONSTRAINT fk_alerts_pub FOREIGN KEY (publication_id) REFERENCES detected_publications(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alerts_result FOREIGN KEY (result_id) REFERENCES analysis_results(id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_session FOREIGN KEY (session_id) REFERENCES monitoring_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id    BIGINT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(40) NOT NULL,
  old_value   VARCHAR(255) NULL,
  new_value   VARCHAR(255) NULL,
  comment     TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ah_alert (alert_id),
  CONSTRAINT fk_ah_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ah_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id    BIGINT UNSIGNED NULL,
  file_path   VARCHAR(255) NOT NULL,
  format      ENUM('pdf','html') NOT NULL,
  sha256      CHAR(64) NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_alert (alert_id),
  CONSTRAINT fk_reports_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE SET NULL,
  CONSTRAINT fk_reports_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level       ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
  component   VARCHAR(40) NOT NULL,     -- web, monitor, worker, session
  session_id  INT UNSIGNED NULL,
  message     VARCHAR(1000) NOT NULL,
  context_json JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_created (created_at),
  KEY idx_events_session (session_id, level),
  CONSTRAINT fk_events_session FOREIGN KEY (session_id) REFERENCES monitoring_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id   VARCHAR(64) NULL,
  ip_address  VARCHAR(45) NULL,
  details_json JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_settings (
  setting_key   VARCHAR(64) NOT NULL,
  setting_value TEXT NOT NULL,
  description   VARCHAR(255) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- État des processus (moteur de surveillance, workers d'analyse)
CREATE TABLE IF NOT EXISTS service_heartbeats (
  service     VARCHAR(64) NOT NULL,
  pid         INT UNSIGNED NULL,
  host_info_json JSON NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO roles (code, label) VALUES
  ('admin',   'Administrateur'),
  ('analyst', 'Analyste'),
  ('viewer',  'Observateur');

INSERT IGNORE INTO application_settings (setting_key, setting_value, description) VALUES
  ('max_concurrent_sessions', '4',    'Nombre maximal de sessions de navigation simultanées'),
  ('session_memory_mb',       '450',  'Mémoire estimée par navigateur (Mo) pour le calcul de capacité'),
  ('memory_reserve_mb',       '1500', 'Mémoire à laisser libre pour le système (Mo)'),
  ('alert_min_priority',      'high', 'Priorité minimale transmise au centre d''alertes (low|normal|high|critical)'),
  ('emergency_stop',          '0',    'Arrêt immédiat de toute la surveillance (1 = actif)'),
  ('image_dhash_threshold',   '10',   'Distance de Hamming maximale pour une correspondance perceptuelle'),
  ('text_similarity_threshold','0.6', 'Similarité (Jaccard) minimale entre deux textes'),
  ('tesseract_path',          '',     'Chemin vers tesseract.exe pour l''OCR (vide = désactivé)'),
  ('retention_days',          '90',   'Durée de conservation des publications non signalées (jours)'),
  ('store_thumbnails',        '1',    'Conserver les miniatures des publications'),
  ('session_previews',        '1',    'Afficher un aperçu périodique des pages surveillées (stocké localement, écrasé)');
