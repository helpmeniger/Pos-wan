-- =====================================================================
-- SMART GUARD AI — Schéma de base de données (MySQL 8 / MariaDB 10.6+)
-- Encodage : utf8mb4. Moteur : InnoDB (clés étrangères + transactions).
--
-- Principe fondamental : les OBSERVATIONS VIDÉO (detection_results,
-- object_tracks, exit_events, camera_events) sont stockées séparément
-- des MOUVEMENTS COMPTABLES VALIDÉS (inventory_movements, sales).
-- Une observation ne modifie jamais le stock sans validation humaine.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Utilisateurs, rôles, permissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36)     NOT NULL,
  username        VARCHAR(60)  NOT NULL,
  full_name       VARCHAR(120) NOT NULL,
  email           VARCHAR(190) NULL,
  phone           VARCHAR(30)  NULL,
  password_hash   VARCHAR(255) NOT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at   DATETIME     NULL,
  last_login_ip   VARCHAR(45)  NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL,
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(60)  NOT NULL,
  module      VARCHAR(40)  NOT NULL,
  description VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(60) NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_user_time (username, attempted_at),
  KEY idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Magasins
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stores (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(30)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  city       VARCHAR(80)  NULL,
  address    VARCHAR(255) NULL,
  phone      VARCHAR(30)  NULL,
  timezone   VARCHAR(60)  NOT NULL DEFAULT 'Africa/Niamey',
  currency   VARCHAR(10)  NOT NULL DEFAULT 'XOF',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stores_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_users (
  store_id INT UNSIGNED NOT NULL,
  user_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (store_id, user_id),
  CONSTRAINT fk_su_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_su_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Produits et images de référence
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  is_active  TINYINT(1)  NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_models (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  method      ENUM('embedding_similarity','supervised_detector') NOT NULL DEFAULT 'embedding_similarity',
  description VARCHAR(255) NULL,
  active_version_id INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_aim_store (store_id),
  CONSTRAINT fk_aim_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36)     NOT NULL,
  store_id         INT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NULL,
  name             VARCHAR(150) NOT NULL,
  brand            VARCHAR(100) NULL,
  reference        VARCHAR(80)  NULL,
  barcode          VARCHAR(64)  NULL,
  purchase_price   DECIMAL(14,2) NOT NULL DEFAULT 0,
  sale_price       DECIMAL(14,2) NOT NULL DEFAULT 0,
  stock_quantity   DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Stock théorique (mis à jour uniquement par inventory_movements validés)',
  stock_alert_threshold DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit             VARCHAR(20)  NOT NULL DEFAULT 'pièce',
  ai_model_id      INT UNSIGNED NULL,
  min_confidence   DECIMAL(4,3) NOT NULL DEFAULT 0.750,
  recognition_status ENUM('aucune','photos_insuffisantes','prete','validee','active','echec') NOT NULL DEFAULT 'aucune',
  detection_enabled TINYINT(1)  NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  is_demo          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = donnée de démonstration fictive',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME     NULL,
  UNIQUE KEY uq_products_uuid (uuid),
  UNIQUE KEY uq_products_store_barcode (store_id, barcode),
  KEY idx_products_store (store_id, is_active),
  KEY idx_products_name (name),
  CONSTRAINT fk_products_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_products_cat   FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_model FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  file_path     VARCHAR(255) NOT NULL COMMENT 'Chemin relatif à storage/product_images',
  sha256        CHAR(64)     NOT NULL,
  width         INT UNSIGNED NOT NULL,
  height        INT UNSIGNED NOT NULL,
  view_angle    ENUM('avant','arriere','gauche','droite','dessus','dessous','autre') NOT NULL DEFAULT 'autre',
  source        ENUM('webcam','import','smartphone') NOT NULL DEFAULT 'import',
  annotation    VARCHAR(255) NULL,
  bbox_json     VARCHAR(255) NULL COMMENT 'Annotation facultative {x,y,w,h} normalisés 0..1',
  quality_score DECIMAL(5,3) NULL,
  quality_notes VARCHAR(255) NULL,
  usage_split   ENUM('reference','test','exclue') NOT NULL DEFAULT 'reference',
  uploaded_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pi_product_sha (product_id, sha256),
  KEY idx_pi_product (product_id),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_user    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Modèles IA, versions, entraînements, validations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_model_versions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ai_model_id     INT UNSIGNED NOT NULL,
  version_number  INT UNSIGNED NOT NULL,
  artifact_file   VARCHAR(255) NULL COMMENT 'Fichier galerie/modèle côté service IA',
  embedder        VARCHAR(80)  NULL,
  image_count     INT UNSIGNED NOT NULL DEFAULT 0,
  product_count   INT UNSIGNED NOT NULL DEFAULT 0,
  products_json   JSON NULL,
  metrics_json    JSON NULL,
  status          ENUM('en_preparation','prete','active','archivee','echec') NOT NULL DEFAULT 'en_preparation',
  notes           VARCHAR(500) NULL,
  created_by      INT UNSIGNED NULL,
  trained_at      DATETIME NULL,
  activated_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_amv_model_version (ai_model_id, version_number),
  CONSTRAINT fk_amv_model FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
  CONSTRAINT fk_amv_user  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ai_models
  ADD CONSTRAINT fk_aim_active_version FOREIGN KEY (active_version_id) REFERENCES ai_model_versions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS ai_training_jobs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL,
  ai_model_id      INT UNSIGNED NOT NULL,
  model_version_id INT UNSIGNED NULL,
  remote_job_id    VARCHAR(64) NULL COMMENT 'Identifiant du job côté service IA',
  status           ENUM('en_attente','en_cours','termine','echec','annule') NOT NULL DEFAULT 'en_attente',
  progress         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  message          VARCHAR(500) NULL,
  errors_json      JSON NULL,
  requested_by     INT UNSIGNED NULL,
  started_at       DATETIME NULL,
  finished_at      DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_atj_uuid (uuid),
  KEY idx_atj_status (status),
  CONSTRAINT fk_atj_model   FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
  CONSTRAINT fk_atj_version FOREIGN KEY (model_version_id) REFERENCES ai_model_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_atj_user    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_validation_results (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_version_id INT UNSIGNED NOT NULL,
  product_id       INT UNSIGNED NULL,
  test_images      INT UNSIGNED NOT NULL DEFAULT 0,
  correct          INT UNSIGNED NOT NULL DEFAULT 0,
  wrong            INT UNSIGNED NOT NULL DEFAULT 0,
  unidentified     INT UNSIGNED NOT NULL DEFAULT 0,
  accuracy         DECIMAL(6,4) NULL,
  most_confused_with_product_id INT UNSIGNED NULL,
  details_json     JSON NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_avr_version (model_version_id),
  CONSTRAINT fk_avr_version FOREIGN KEY (model_version_id) REFERENCES ai_model_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_avr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_avr_confused FOREIGN KEY (most_confused_with_product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Caméras, zones, lignes virtuelles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cameras (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36)     NOT NULL,
  store_id        INT UNSIGNED NOT NULL,
  name            VARCHAR(100) NOT NULL,
  type            ENUM('usb','ip_rtsp','ip_http','nvr') NOT NULL DEFAULT 'usb',
  device_index    TINYINT UNSIGNED NULL COMMENT 'Index du périphérique USB (OpenCV)',
  ip_address      VARCHAR(45)  NULL,
  port            SMALLINT UNSIGNED NULL,
  stream_path     VARCHAR(255) NULL COMMENT 'Chemin du flux, ex: /Streaming/Channels/101',
  username        VARCHAR(100) NULL,
  password_enc    VARBINARY(512) NULL COMMENT 'Chiffré (libsodium secretbox), jamais affiché',
  location        VARCHAR(120) NULL,
  monitored_zone  VARCHAR(120) NULL,
  role            ENUM('sortie','entree','caisse','rayon','reserve','autre') NOT NULL DEFAULT 'sortie',
  resolution_w    SMALLINT UNSIGNED NOT NULL DEFAULT 1280,
  resolution_h    SMALLINT UNSIGNED NOT NULL DEFAULT 720,
  target_fps      TINYINT UNSIGNED NOT NULL DEFAULT 15,
  analysis_fps    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  analysis_enabled TINYINT(1)  NOT NULL DEFAULT 0,
  recording_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Extraits vidéo autour des événements',
  status          ENUM('inconnu','en_ligne','hors_ligne','erreur','desactivee') NOT NULL DEFAULT 'inconnu',
  last_seen_at    DATETIME NULL,
  last_error      VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cameras_uuid (uuid),
  KEY idx_cameras_store (store_id, is_active),
  CONSTRAINT fk_cameras_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_zones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  zone_type   ENUM('analyse','sortie','caisse','exclusion') NOT NULL DEFAULT 'analyse',
  polygon_json JSON NOT NULL COMMENT 'Points normalisés [[x,y],...] 0..1',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cz_camera (camera_id),
  CONSTRAINT fk_cz_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  x1 DECIMAL(6,5) NOT NULL, y1 DECIMAL(6,5) NOT NULL,
  x2 DECIMAL(6,5) NOT NULL, y2 DECIMAL(6,5) NOT NULL,
  exit_direction ENUM('gauche_vers_droite','droite_vers_gauche') NOT NULL DEFAULT 'gauche_vers_droite'
    COMMENT 'Sens de SORTIE relativement au vecteur (x1,y1)->(x2,y2) : côté gauche -> côté droit',
  min_track_frames TINYINT UNSIGNED NOT NULL DEFAULT 3,
  hysteresis   DECIMAL(5,4) NOT NULL DEFAULT 0.0200 COMMENT 'Marge normalisée pour éviter les comptages multiples',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vl_camera (camera_id),
  CONSTRAINT fk_vl_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Observations vidéo (faits observés, pas de valeur comptable)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS camera_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid        CHAR(36) NOT NULL,
  camera_id   INT UNSIGNED NOT NULL,
  event_type  ENUM('connexion','deconnexion','reconnexion','flux_inutilisable','analyse_demarree','analyse_arretee','erreur') NOT NULL,
  message     VARCHAR(255) NULL,
  occurred_at DATETIME(3) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ce_uuid (uuid),
  KEY idx_ce_camera_time (camera_id, occurred_at),
  CONSTRAINT fk_ce_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS object_tracks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL,
  camera_id       INT UNSIGNED NOT NULL,
  session_id      VARCHAR(40) NOT NULL COMMENT 'Session d analyse du service IA',
  track_number    INT UNSIGNED NOT NULL COMMENT 'Identifiant temporaire de suivi',
  product_id      INT UNSIGNED NULL,
  best_confidence DECIMAL(5,4) NULL,
  frames_seen     INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at   DATETIME(3) NOT NULL,
  last_seen_at    DATETIME(3) NOT NULL,
  end_reason      ENUM('actif','sorti_du_champ','perdu','franchissement') NOT NULL DEFAULT 'actif',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ot_uuid (uuid),
  UNIQUE KEY uq_ot_session_track (camera_id, session_id, track_number),
  KEY idx_ot_product (product_id),
  KEY idx_ot_time (first_seen_at),
  CONSTRAINT fk_ot_camera  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
  CONSTRAINT fk_ot_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detection_results (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL,
  camera_id      INT UNSIGNED NULL COMMENT 'NULL = test sur image importée',
  track_id       BIGINT UNSIGNED NULL,
  product_id     INT UNSIGNED NULL COMMENT 'NULL = Produit non identifié',
  model_version_id INT UNSIGNED NULL,
  label          VARCHAR(150) NOT NULL,
  detector_label VARCHAR(60) NULL,
  confidence     DECIMAL(5,4) NOT NULL COMMENT 'Score de similarité/reconnaissance 0..1',
  detector_confidence DECIMAL(5,4) NULL,
  bbox_x DECIMAL(6,5) NOT NULL, bbox_y DECIMAL(6,5) NOT NULL,
  bbox_w DECIMAL(6,5) NOT NULL, bbox_h DECIMAL(6,5) NOT NULL,
  is_test        TINYINT(1) NOT NULL DEFAULT 0,
  detected_at    DATETIME(3) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dr_uuid (uuid),
  KEY idx_dr_camera_time (camera_id, detected_at),
  KEY idx_dr_product_time (product_id, detected_at),
  CONSTRAINT fk_dr_camera  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
  CONSTRAINT fk_dr_track   FOREIGN KEY (track_id)  REFERENCES object_tracks(id) ON DELETE SET NULL,
  CONSTRAINT fk_dr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_dr_version FOREIGN KEY (model_version_id) REFERENCES ai_model_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_records (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid        CHAR(36) NOT NULL,
  camera_id   INT UNSIGNED NOT NULL,
  kind        ENUM('snapshot','extrait') NOT NULL,
  file_path   VARCHAR(255) NOT NULL COMMENT 'Relatif à storage/ (snapshots/ ou videos/)',
  frame_count INT UNSIGNED NOT NULL DEFAULT 1,
  size_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  started_at  DATETIME(3) NOT NULL,
  ended_at    DATETIME(3) NULL,
  expires_at  DATETIME NULL,
  is_protected TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ne pas purger (alerte en cours d examen)',
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vr_uuid (uuid),
  KEY idx_vr_expire (expires_at, is_protected, deleted_at),
  CONSTRAINT fk_vr_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exit_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL COMMENT 'Identifiant unique de l événement (fourni par le service IA, idempotent)',
  store_id        INT UNSIGNED NOT NULL,
  camera_id       INT UNSIGNED NOT NULL,
  virtual_line_id INT UNSIGNED NULL,
  track_id        BIGINT UNSIGNED NULL,
  track_number    INT UNSIGNED NULL,
  product_id      INT UNSIGNED NULL,
  confidence      DECIMAL(5,4) NULL,
  direction       ENUM('sortie','entree','ambigu','perte_suivi') NOT NULL,
  quantity        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  snapshot_id     BIGINT UNSIGNED NULL,
  clip_id         BIGINT UNSIGNED NULL,
  reconciliation_status ENUM('non_applicable','en_attente','vente_trouvee','aucune_vente','incertain') NOT NULL DEFAULT 'en_attente',
  matched_sale_item_id BIGINT UNSIGNED NULL,
  reconciliation_note VARCHAR(255) NULL,
  occurred_at     DATETIME(3) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ee_uuid (uuid),
  KEY idx_ee_store_time (store_id, occurred_at),
  KEY idx_ee_product_time (product_id, occurred_at),
  KEY idx_ee_recon (reconciliation_status),
  CONSTRAINT fk_ee_store   FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_ee_camera  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
  CONSTRAINT fk_ee_line    FOREIGN KEY (virtual_line_id) REFERENCES virtual_lines(id) ON DELETE SET NULL,
  CONSTRAINT fk_ee_track   FOREIGN KEY (track_id) REFERENCES object_tracks(id) ON DELETE SET NULL,
  CONSTRAINT fk_ee_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_ee_snap    FOREIGN KEY (snapshot_id) REFERENCES video_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_ee_clip    FOREIGN KEY (clip_id) REFERENCES video_records(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Alertes et vérification humaine
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  camera_id     INT UNSIGNED NULL,
  exit_event_id BIGINT UNSIGNED NULL,
  product_id    INT UNSIGNED NULL,
  level         ENUM('information','a_verifier','prioritaire') NOT NULL,
  alert_type    ENUM('sortie_observee','sortie_sans_vente','mouvement_inhabituel','objet_non_identifie','perte_suivi','franchissement_ambigu','camera','stock','systeme') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  description   VARCHAR(1000) NULL,
  confidence    DECIMAL(5,4) NULL,
  indicators_json JSON NULL COMMENT 'Indices ayant conduit au niveau d alerte',
  status        ENUM('nouvelle','en_examen','mouvement_normal','vente_retrouvee','erreur_detection','anomalie_confirmee','non_concluante','cloturee') NOT NULL DEFAULT 'nouvelle',
  assigned_to   INT UNSIGNED NULL,
  occurred_at   DATETIME(3) NOT NULL,
  closed_at     DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alerts_uuid (uuid),
  KEY idx_alerts_store_status (store_id, status, level),
  KEY idx_alerts_time (occurred_at),
  CONSTRAINT fk_alerts_store  FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_alerts_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_exit   FOREIGN KEY (exit_event_id) REFERENCES exit_events(id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_user   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_reviews (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id    BIGINT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  old_status  VARCHAR(30) NULL,
  new_status  VARCHAR(30) NOT NULL,
  comment     VARCHAR(1000) NULL,
  stock_movement_id BIGINT UNSIGNED NULL COMMENT 'Mouvement de stock créé suite à la vérification',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ar_alert (alert_id),
  CONSTRAINT fk_ar_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_user  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Caisse (ventes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL,
  store_id       INT UNSIGNED NOT NULL,
  receipt_number VARCHAR(40) NOT NULL,
  source         ENUM('caisse_interne','api','import_csv') NOT NULL DEFAULT 'caisse_interne',
  external_ref   VARCHAR(80) NULL,
  cashier_id     INT UNSIGNED NULL,
  status         ENUM('validee','annulee','remboursee','partiellement_remboursee') NOT NULL DEFAULT 'validee',
  total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_method ENUM('especes','mobile_money','carte','credit','autre') NOT NULL DEFAULT 'especes',
  amount_paid    DECIMAL(14,2) NOT NULL DEFAULT 0,
  sold_at        DATETIME(3) NOT NULL,
  cancelled_at   DATETIME NULL,
  cancel_reason  VARCHAR(255) NULL,
  is_demo        TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_uuid (uuid),
  UNIQUE KEY uq_sales_receipt (store_id, receipt_number),
  UNIQUE KEY uq_sales_external (store_id, source, external_ref),
  KEY idx_sales_time (store_id, sold_at),
  CONSTRAINT fk_sales_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_sales_user  FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id       BIGINT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  quantity      DECIMAL(14,3) NOT NULL,
  unit_price    DECIMAL(14,2) NOT NULL,
  line_total    DECIMAL(14,2) NOT NULL,
  returned_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  matched_exit_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre de sorties vidéo rapprochées',
  KEY idx_si_sale (sale_id),
  KEY idx_si_product (product_id),
  CONSTRAINT fk_si_sale    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exit_events
  ADD CONSTRAINT fk_ee_sale_item FOREIGN KEY (matched_sale_item_id) REFERENCES sale_items(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 9. Stocks (mouvements comptables validés)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_movements (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  movement_type ENUM('reception','vente','retour_client','retour_fournisseur','transfert','casse','perime','ajustement','sortie_autorisee','mouvement_observe','mouvement_non_explique','inventaire') NOT NULL,
  quantity      DECIMAL(14,3) NOT NULL COMMENT 'Signé : positif = entrée en stock, négatif = sortie',
  stock_before  DECIMAL(14,3) NOT NULL,
  stock_after   DECIMAL(14,3) NOT NULL,
  affects_stock TINYINT(1) NOT NULL DEFAULT 1,
  reference     VARCHAR(80) NULL,
  sale_id       BIGINT UNSIGNED NULL,
  alert_id      BIGINT UNSIGNED NULL,
  from_zone     VARCHAR(60) NULL,
  to_zone       VARCHAR(60) NULL,
  comment       VARCHAR(500) NULL,
  user_id       INT UNSIGNED NULL,
  occurred_at   DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_im_uuid (uuid),
  KEY idx_im_product_time (product_id, occurred_at),
  KEY idx_im_store_type (store_id, movement_type, occurred_at),
  CONSTRAINT fk_im_store   FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_im_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_im_sale    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
  CONSTRAINT fk_im_alert   FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE SET NULL,
  CONSTRAINT fk_im_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE alert_reviews
  ADD CONSTRAINT fk_ar_movement FOREIGN KEY (stock_movement_id) REFERENCES inventory_movements(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS stock_snapshots (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id        INT UNSIGNED NOT NULL,
  product_id      INT UNSIGNED NOT NULL,
  snapshot_type   ENUM('inventaire_physique','cloture_journaliere') NOT NULL,
  theoretical_qty DECIMAL(14,3) NOT NULL,
  physical_qty    DECIMAL(14,3) NULL,
  variance        DECIMAL(14,3) NULL,
  comment         VARCHAR(255) NULL,
  user_id         INT UNSIGNED NULL,
  taken_at        DATETIME NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ss_product_time (product_id, taken_at),
  CONSTRAINT fk_ss_store   FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_ss_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_ss_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Rapports, notifications, journaux, paramètres, sauvegardes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_reports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED NOT NULL,
  report_date  DATE NOT NULL,
  summary_json JSON NOT NULL,
  products_json JSON NOT NULL,
  cameras_json JSON NOT NULL,
  generated_by ENUM('automatique','manuel') NOT NULL DEFAULT 'automatique',
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dr_store_date (store_id, report_date),
  CONSTRAINT fk_dreport_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL COMMENT 'NULL = visible par tous les utilisateurs autorisés du magasin',
  type        VARCHAR(40) NOT NULL,
  severity    ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(1000) NULL,
  link        VARCHAR(255) NULL,
  channel     ENUM('interne','email','sms','push','whatsapp') NOT NULL DEFAULT 'interne',
  dedupe_key  VARCHAR(120) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_store_read (store_id, read_at, created_at),
  KEY idx_notif_dedupe (dedupe_key, created_at),
  CONSTRAINT fk_notif_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_user  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id BIGINT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id, user_id),
  CONSTRAINT fk_nr_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_nr_user  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  store_id    INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id   VARCHAR(64) NULL,
  details_json JSON NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user_time (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    INT UNSIGNED NULL COMMENT 'NULL = paramètre global',
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(1000) NOT NULL,
  value_type  ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  description VARCHAR(255) NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_settings (store_id, setting_key),
  CONSTRAINT fk_settings_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
  CONSTRAINT fk_settings_user  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_type ENUM('base_de_donnees','complete','restauration') NOT NULL,
  file_path   VARCHAR(255) NULL,
  size_bytes  BIGINT UNSIGNED NULL,
  sha256      CHAR(64) NULL,
  status      ENUM('succes','echec') NOT NULL,
  message     VARCHAR(500) NULL,
  triggered_by ENUM('manuel','planifie','cli') NOT NULL DEFAULT 'manuel',
  user_id     INT UNSIGNED NULL,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  CONSTRAINT fk_bl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(80) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('0001_initial_schema');

SET FOREIGN_KEY_CHECKS = 1;
