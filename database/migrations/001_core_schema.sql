-- =====================================================================
-- MARWA — Migration 001 : schéma principal
-- Compatible MySQL 8.0+ et MariaDB 10.6+ (InnoDB, utf8mb4).
-- Montants : entiers en FCFA (XOF, pas de sous-unité).
-- Dates : DATETIME en heure locale Africa/Niamey (UTC+1, sans heure d'été).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Rôles, permissions, utilisateurs
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(32)  NOT NULL UNIQUE,
  name          VARCHAR(80)  NOT NULL,
  is_staff      TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(64)  NOT NULL UNIQUE,
  description   VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36)     NOT NULL UNIQUE,
  role_id            SMALLINT UNSIGNED NOT NULL,
  full_name          VARCHAR(120) NOT NULL,
  phone              VARCHAR(20)  NULL UNIQUE,
  email              VARCHAR(190) NULL UNIQUE,
  password_hash      VARCHAR(255) NOT NULL,
  photo_file_id      BIGINT UNSIGNED NULL,
  status             ENUM('active','pending','suspended','deleted') NOT NULL DEFAULT 'active',
  phone_verified_at  DATETIME NULL,
  email_verified_at  DATETIME NULL,
  failed_logins      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME NULL,
  last_login_at      DATETIME NULL,
  is_demo            TINYINT(1) NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  KEY idx_users_role (role_id),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT chk_users_contact CHECK (phone IS NOT NULL OR email IS NOT NULL OR status = 'deleted')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,
  last_used_at DATETIME NULL,
  expires_at   DATETIME NOT NULL,
  revoked_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target       VARCHAR(190) NOT NULL,
  purpose      ENUM('verify_phone','reset_password','login') NOT NULL,
  code_hash    VARCHAR(255) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_target (target, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  k             VARCHAR(191) NOT NULL PRIMARY KEY,
  window_start  INT UNSIGNED NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fichiers téléversés (stockés hors du dossier public)
-- ---------------------------------------------------------------------
CREATE TABLE files (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id     BIGINT UNSIGNED NULL,
  purpose      VARCHAR(40)  NOT NULL,
  path         VARCHAR(255) NOT NULL,
  mime         VARCHAR(80)  NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL,
  visibility   ENUM('public','private') NOT NULL DEFAULT 'private',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_files_owner (owner_id),
  CONSTRAINT fk_files_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Géographie : villes, zones, points de repère, adresses
-- ---------------------------------------------------------------------
CREATE TABLE cities (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_code CHAR(2)      NOT NULL DEFAULT 'NE',
  region       VARCHAR(80)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  lat          DECIMAL(9,6) NOT NULL,
  lng          DECIMAL(9,6) NOT NULL,
  timezone     VARCHAR(40)  NOT NULL DEFAULT 'Africa/Niamey',
  currency     CHAR(3)      NOT NULL DEFAULT 'XOF',
  is_active    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_city (country_code, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE neighborhoods (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  lat        DECIMAL(9,6) NULL,
  lng        DECIMAL(9,6) NULL,
  verified   TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_nb (city_id, name),
  CONSTRAINT fk_nb_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_zones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  polygon       LONGTEXT     NOT NULL COMMENT 'GeoJSON Polygon [lng,lat]',
  min_lat       DECIMAL(9,6) NOT NULL,
  max_lat       DECIMAL(9,6) NOT NULL,
  min_lng       DECIMAL(9,6) NOT NULL,
  max_lng       DECIMAL(9,6) NOT NULL,
  allow_rides   TINYINT(1)   NOT NULL DEFAULT 1,
  allow_delivery TINYINT(1)  NOT NULL DEFAULT 1,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_zone_city (city_id, is_active),
  CONSTRAINT fk_zone_city FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE landmarks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  category    VARCHAR(40)  NOT NULL DEFAULT 'autre',
  lat         DECIMAL(9,6) NOT NULL,
  lng         DECIMAL(9,6) NOT NULL,
  source      VARCHAR(60)  NOT NULL DEFAULT 'admin' COMMENT 'osm:<type>/<id>, admin, demo',
  verified    TINYINT(1)   NOT NULL DEFAULT 0,
  is_meeting_point TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_landmark_source (source),
  KEY idx_landmark_city (city_id, is_active),
  KEY idx_landmark_name (name),
  CONSTRAINT fk_landmark_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NULL,
  business_id   BIGINT UNSIGNED NULL,
  label         VARCHAR(60)  NOT NULL,
  address_text  VARCHAR(255) NOT NULL,
  landmark      VARCHAR(150) NULL,
  instructions  VARCHAR(255) NULL,
  lat           DECIMAL(9,6) NULL,
  lng           DECIMAL(9,6) NULL,
  city_id       INT UNSIGNED NULL,
  is_favorite   TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_addr_user (user_id),
  KEY idx_addr_business (business_id),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_addr_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE geocode_cache (
  query_hash  CHAR(64) PRIMARY KEY,
  query       VARCHAR(255) NOT NULL,
  result      LONGTEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Profils
-- ---------------------------------------------------------------------
CREATE TABLE customer_profiles (
  user_id                BIGINT UNSIGNED PRIMARY KEY,
  default_payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
  city_id                INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE businesses (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id    BIGINT UNSIGNED NOT NULL,
  name             VARCHAR(150) NOT NULL,
  tax_id           VARCHAR(60)  NULL COMMENT 'NIF',
  rccm             VARCHAR(60)  NULL,
  billing_email    VARCHAR(190) NULL,
  billing_address  VARCHAR(255) NULL,
  status           ENUM('pending','verified','suspended','refused') NOT NULL DEFAULT 'pending',
  postpaid_enabled TINYINT(1) NOT NULL DEFAULT 0,
  credit_limit     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  monthly_budget   BIGINT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_business_owner (owner_user_id),
  CONSTRAINT fk_business_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE addresses ADD CONSTRAINT fk_addr_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE;

CREATE TABLE business_members (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_id     BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  member_role     ENUM('owner','manager','employee') NOT NULL DEFAULT 'employee',
  monthly_limit   BIGINT UNSIGNED NULL COMMENT 'Plafond mensuel en FCFA (NULL = illimité)',
  can_order       TINYINT(1) NOT NULL DEFAULT 1,
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bm (business_id, user_id),
  CONSTRAINT fk_bm_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chauffeurs (taxi) et livreurs partagent la même table : provider_type distingue les deux.
-- (adaptation documentée des tables driver_profiles / delivery_profiles du cahier des charges)
CREATE TABLE provider_profiles (
  user_id              BIGINT UNSIGNED PRIMARY KEY,
  provider_type        ENUM('driver','courier') NOT NULL,
  verification_status  ENUM('pending','verified','suspended','refused') NOT NULL DEFAULT 'pending',
  verification_note    VARCHAR(255) NULL,
  business_id          BIGINT UNSIGNED NULL COMMENT 'Livreur rattaché à une entreprise / un commerce',
  merchant_id          BIGINT UNSIGNED NULL COMMENT 'Livreur interne d''un commerce',
  city_id              INT UNSIGNED NULL,
  id_number            VARCHAR(60)  NULL,
  license_number       VARCHAR(60)  NULL,
  license_expires_at   DATE NULL,
  is_online            TINYINT(1) NOT NULL DEFAULT 0,
  current_order_id     BIGINT UNSIGNED NULL,
  rating_avg           DECIMAL(3,2) NOT NULL DEFAULT 0,
  rating_count         INT UNSIGNED NOT NULL DEFAULT 0,
  offers_received      INT UNSIGNED NOT NULL DEFAULT 0,
  offers_refused       INT UNSIGNED NOT NULL DEFAULT 0,
  offers_expired       INT UNSIGNED NOT NULL DEFAULT 0,
  went_online_at       DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_current_order (current_order_id),
  KEY idx_provider_dispatch (provider_type, verification_status, is_online),
  CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE SET NULL,
  CONSTRAINT fk_pp_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id  BIGINT UNSIGNED NOT NULL,
  category     ENUM('taxi','sedan','family','van','moto_taxi','moto','bicycle','car','other') NOT NULL,
  make         VARCHAR(60) NULL,
  model        VARCHAR(60) NULL,
  color        VARCHAR(40) NULL,
  plate        VARCHAR(30) NULL,
  capacity     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  photo_file_id BIGINT UNSIGNED NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_vehicle_provider (provider_id, is_active),
  UNIQUE KEY uq_vehicle_plate (plate),
  CONSTRAINT fk_vehicle_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_photo FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_documents (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id  BIGINT UNSIGNED NOT NULL,
  doc_type     ENUM('id_card','driving_license','vehicle_registration','insurance','technical_inspection','photo','other') NOT NULL,
  file_id      BIGINT UNSIGNED NOT NULL,
  expires_at   DATE NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_note  VARCHAR(255) NULL,
  reviewed_by  BIGINT UNSIGNED NULL,
  reviewed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_provider (provider_id),
  CONSTRAINT fk_doc_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_file FOREIGN KEY (file_id) REFERENCES files(id),
  CONSTRAINT fk_doc_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_locations (
  provider_id  BIGINT UNSIGNED PRIMARY KEY,
  lat          DECIMAL(9,6) NOT NULL,
  lng          DECIMAL(9,6) NOT NULL,
  heading      SMALLINT NULL,
  speed_kmh    DECIMAL(5,1) NULL,
  accuracy_m   INT UNSIGNED NULL,
  updated_at   DATETIME NOT NULL,
  KEY idx_ploc_geo (lat, lng),
  CONSTRAINT fk_ploc_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Commerces / restaurants et catalogue
-- ---------------------------------------------------------------------
CREATE TABLE merchants (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id   BIGINT UNSIGNED NOT NULL,
  kind            ENUM('restaurant','shop') NOT NULL DEFAULT 'restaurant',
  name            VARCHAR(150) NOT NULL,
  cuisine         VARCHAR(80)  NULL,
  description     VARCHAR(500) NULL,
  phone           VARCHAR(20)  NULL,
  address_text    VARCHAR(255) NOT NULL,
  lat             DECIMAL(9,6) NOT NULL,
  lng             DECIMAL(9,6) NOT NULL,
  city_id         INT UNSIGNED NOT NULL,
  logo_file_id    BIGINT UNSIGNED NULL,
  status          ENUM('pending','verified','suspended','refused') NOT NULL DEFAULT 'pending',
  is_open         TINYINT(1) NOT NULL DEFAULT 0,
  prep_time_min   SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  delivery_mode   ENUM('marwa','own') NOT NULL DEFAULT 'marwa',
  delivery_radius_km DECIMAL(5,1) NOT NULL DEFAULT 8.0,
  min_order       INT UNSIGNED NOT NULL DEFAULT 0,
  track_stock     TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg      DECIMAL(3,2) NOT NULL DEFAULT 0,
  rating_count    INT UNSIGNED NOT NULL DEFAULT 0,
  rccm            VARCHAR(60) NULL,
  tax_id          VARCHAR(60) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_merchant_city (city_id, status, is_open),
  KEY idx_merchant_owner (owner_user_id),
  CONSTRAINT fk_merchant_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT fk_merchant_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_merchant_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE provider_profiles ADD CONSTRAINT fk_pp_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE SET NULL;

CREATE TABLE merchant_hours (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '1=lundi … 7=dimanche (ISO-8601)',
  open_time   TIME NOT NULL,
  close_time  TIME NOT NULL,
  KEY idx_mh (merchant_id, weekday),
  CONSTRAINT fk_mh_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  CONSTRAINT chk_mh_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE merchant_closures (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id  BIGINT UNSIGNED NOT NULL,
  closed_on    DATE NOT NULL,
  reason       VARCHAR(120) NULL,
  UNIQUE KEY uq_closure (merchant_id, closed_on),
  CONSTRAINT fk_mc_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_categories (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pc (merchant_id, name),
  CONSTRAINT fk_pc_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id   BIGINT UNSIGNED NOT NULL,
  category_id   BIGINT UNSIGNED NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(500) NULL,
  price         INT UNSIGNED NOT NULL,
  promo_price   INT UNSIGNED NULL,
  photo_file_id BIGINT UNSIGNED NULL,
  is_available  TINYINT(1) NOT NULL DEFAULT 1,
  stock_qty     INT NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  KEY idx_products_merchant (merchant_id, is_available),
  FULLTEXT KEY ft_products (name, description),
  CONSTRAINT fk_products_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_photo FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT chk_promo CHECK (promo_price IS NULL OR promo_price < price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variantes (choix exclusif dans un groupe) et suppléments (cumulables)
CREATE TABLE product_variants (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id   BIGINT UNSIGNED NOT NULL,
  kind         ENUM('variant','extra') NOT NULL DEFAULT 'extra',
  group_name   VARCHAR(60) NOT NULL,
  name         VARCHAR(80) NOT NULL,
  price_delta  INT NOT NULL DEFAULT 0,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_pv_product (product_id),
  CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tarification et commissions (toutes les valeurs sont paramétrables)
-- ---------------------------------------------------------------------
CREATE TABLE pricing_rules (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  service_type     ENUM('taxi','food','parcel','document','errand','shop','urgent','pro') NOT NULL,
  vehicle_category VARCHAR(20) NULL,
  city_id          INT UNSIGNED NULL,
  zone_id          INT UNSIGNED NULL,
  base_fare        INT UNSIGNED NOT NULL DEFAULT 0,
  per_km           INT UNSIGNED NOT NULL DEFAULT 0,
  per_min          INT UNSIGNED NOT NULL DEFAULT 0,
  min_fare         INT UNSIGNED NOT NULL DEFAULT 0,
  booking_fee      INT UNSIGNED NOT NULL DEFAULT 0,
  service_fee      INT UNSIGNED NOT NULL DEFAULT 0,
  night_multiplier_pct SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '100 = x1.00 ; appliqué de night_start à night_end',
  night_start      TIME NULL,
  night_end        TIME NULL,
  min_offer_pct    SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Prix négocié minimal autorisé en % de l''estimation',
  max_offer_pct    SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  priority         SMALLINT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  valid_from       DATETIME NULL,
  valid_to         DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pricing_lookup (service_type, is_active),
  CONSTRAINT fk_pricing_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
  CONSTRAINT fk_pricing_zone FOREIGN KEY (zone_id) REFERENCES service_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_rules (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  applies_to       ENUM('provider','merchant') NOT NULL COMMENT 'provider = sur la course / les frais de livraison ; merchant = sur le sous-total articles',
  service_type     VARCHAR(20) NULL,
  provider_type    ENUM('driver','courier') NULL,
  vehicle_category VARCHAR(20) NULL,
  merchant_id      BIGINT UNSIGNED NULL COMMENT 'Contrat négocié pour un commerce',
  provider_id      BIGINT UNSIGNED NULL COMMENT 'Contrat négocié pour un prestataire',
  business_id      BIGINT UNSIGNED NULL,
  city_id          INT UNSIGNED NULL,
  zone_id          INT UNSIGNED NULL,
  model            ENUM('percent','fixed','mixed','subscription') NOT NULL DEFAULT 'percent',
  percent_bp       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Points de base : 1000 = 10,00 %',
  fixed_amount     INT UNSIGNED NOT NULL DEFAULT 0,
  min_commission   INT UNSIGNED NULL,
  max_commission   INT UNSIGNED NULL,
  min_order_amount INT UNSIGNED NULL,
  max_order_amount INT UNSIGNED NULL,
  priority         SMALLINT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  valid_from       DATETIME NULL,
  valid_to         DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comm_lookup (applies_to, is_active),
  CONSTRAINT chk_comm_bp CHECK (percent_bp <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(30) NOT NULL UNIQUE,
  description     VARCHAR(255) NOT NULL,
  discount_type   ENUM('percent','fixed') NOT NULL,
  value           INT UNSIGNED NOT NULL COMMENT 'percent: points de base ; fixed: FCFA',
  max_discount    INT UNSIGNED NULL,
  min_order       INT UNSIGNED NOT NULL DEFAULT 0,
  service_type    VARCHAR(20) NULL,
  merchant_id     BIGINT UNSIGNED NULL,
  funded_by       ENUM('marwa','merchant') NOT NULL DEFAULT 'marwa',
  starts_at       DATETIME NOT NULL,
  ends_at         DATETIME NOT NULL,
  max_uses        INT UNSIGNED NULL,
  per_user_limit  INT UNSIGNED NOT NULL DEFAULT 1,
  used_count      INT UNSIGNED NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  is_public       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_promo_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Commandes (tous services)
-- ---------------------------------------------------------------------
CREATE TABLE orders (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  reference        VARCHAR(20) NOT NULL UNIQUE,
  service_type     ENUM('taxi','food','parcel','document','errand','shop','urgent','pro') NOT NULL,
  flow             ENUM('ride','courier','merchant') NOT NULL,
  status           VARCHAR(30) NOT NULL,
  dispatch_status  ENUM('none','searching','assigned','no_provider') NOT NULL DEFAULT 'none',
  provider_stage   VARCHAR(30) NULL COMMENT 'Étape du livreur pendant la préparation (flux commerce)',
  customer_id      BIGINT UNSIGNED NOT NULL,
  business_id      BIGINT UNSIGNED NULL,
  merchant_id      BIGINT UNSIGNED NULL,
  provider_id      BIGINT UNSIGNED NULL,
  city_id          INT UNSIGNED NULL,
  zone_id          INT UNSIGNED NULL,
  pickup_text      VARCHAR(255) NOT NULL,
  pickup_lat       DECIMAL(9,6) NULL,
  pickup_lng       DECIMAL(9,6) NULL,
  pickup_notes     VARCHAR(255) NULL,
  dropoff_text     VARCHAR(255) NOT NULL,
  dropoff_lat      DECIMAL(9,6) NULL,
  dropoff_lng      DECIMAL(9,6) NULL,
  dropoff_notes    VARCHAR(255) NULL,
  distance_m       INT UNSIGNED NULL,
  duration_s       INT UNSIGNED NULL,
  route_source     VARCHAR(20) NULL COMMENT 'osrm | estimate',
  route_geometry   LONGTEXT NULL COMMENT 'JSON [[lat,lng],…]',
  vehicle_category VARCHAR(20) NULL,
  pricing_mode     ENUM('auto','negotiated','catalog') NOT NULL DEFAULT 'auto',
  pricing_rule_id  INT UNSIGNED NULL,
  estimated_price  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prix indicatif MARWA (course ou livraison)',
  offered_price    INT UNSIGNED NULL COMMENT 'Prix proposé par le client (mode négocié)',
  final_price      INT UNSIGNED NULL COMMENT 'Prix course/livraison accepté par les deux parties',
  items_subtotal   INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_fee     INT UNSIGNED NOT NULL DEFAULT 0,
  booking_fee      INT UNSIGNED NOT NULL DEFAULT 0,
  service_fee      INT UNSIGNED NOT NULL DEFAULT 0,
  discount         INT UNSIGNED NOT NULL DEFAULT 0,
  promotion_id     INT UNSIGNED NULL,
  total            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total dû par le client',
  payment_method   ENUM('cash','demo_mobile_money','mobile_money','card','business_account') NOT NULL DEFAULT 'cash',
  payment_status   ENUM('unpaid','pending','paid','partially_refunded','refunded','failed') NOT NULL DEFAULT 'unpaid',
  cash_payer       ENUM('customer','recipient') NOT NULL DEFAULT 'customer',
  passengers       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  share_tracking   TINYINT(1) NOT NULL DEFAULT 1,
  delivery_code    CHAR(4) NULL COMMENT 'Code remis au destinataire',
  notes            VARCHAR(500) NULL,
  prep_time_min    SMALLINT UNSIGNED NULL,
  scheduled_at     DATETIME NULL,
  dispatch_after   DATETIME NULL,
  expires_at       DATETIME NULL,
  cancel_reason    VARCHAR(255) NULL,
  cancelled_by     BIGINT UNSIGNED NULL,
  rated_at         DATETIME NULL,
  settled_at       DATETIME NULL,
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  is_demo          TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  assigned_at      DATETIME NULL,
  picked_up_at     DATETIME NULL,
  completed_at     DATETIME NULL,
  KEY idx_orders_customer (customer_id, created_at),
  KEY idx_orders_provider (provider_id, status),
  KEY idx_orders_merchant (merchant_id, status),
  KEY idx_orders_business (business_id, created_at),
  KEY idx_orders_status (status, dispatch_status),
  KEY idx_orders_created (created_at),
  KEY idx_orders_city (city_id, created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id),
  CONSTRAINT fk_orders_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_orders_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id),
  CONSTRAINT fk_orders_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id),
  CONSTRAINT fk_orders_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_orders_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE provider_profiles ADD CONSTRAINT fk_pp_current_order FOREIGN KEY (current_order_id) REFERENCES orders(id) ON DELETE SET NULL;

CREATE TABLE order_items (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NULL,
  name         VARCHAR(120) NOT NULL,
  unit_price   INT UNSIGNED NOT NULL,
  quantity     SMALLINT UNSIGNED NOT NULL,
  options      LONGTEXT NULL COMMENT 'JSON des variantes/suppléments choisis',
  line_total   INT UNSIGNED NOT NULL,
  KEY idx_items_order (order_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT chk_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  from_status  VARCHAR(30) NULL,
  to_status    VARCHAR(30) NOT NULL,
  actor_id     BIGINT UNSIGNED NULL,
  actor_role   VARCHAR(20) NOT NULL,
  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_osh_order (order_id, id),
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Détails spécifiques aux courses
CREATE TABLE rides (
  order_id          BIGINT UNSIGNED PRIMARY KEY,
  vehicle_id        BIGINT UNSIGNED NULL,
  arrived_at        DATETIME NULL,
  started_at        DATETIME NULL,
  ended_at          DATETIME NULL,
  waiting_seconds   INT UNSIGNED NULL,
  CONSTRAINT fk_rides_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_rides_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Détails spécifiques aux livraisons (repas, colis, documents, courses…)
CREATE TABLE deliveries (
  order_id          BIGINT UNSIGNED PRIMARY KEY,
  sender_name       VARCHAR(120) NULL,
  sender_phone      VARCHAR(20)  NULL,
  recipient_name    VARCHAR(120) NOT NULL,
  recipient_phone   VARCHAR(20)  NOT NULL,
  package_type      VARCHAR(40)  NULL,
  description       VARCHAR(500) NULL,
  weight_kg         DECIMAL(6,2) NULL,
  dimensions        VARCHAR(60)  NULL,
  declared_value    INT UNSIGNED NULL,
  instructions      VARCHAR(500) NULL,
  proof_type        ENUM('otp','photo','signature','none') NOT NULL DEFAULT 'otp',
  proof_file_id     BIGINT UNSIGNED NULL,
  proof_signature   LONGTEXT NULL COMMENT 'Image PNG en data-URI (signature)',
  otp_verified_at   DATETIME NULL,
  otp_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  failed_reason     VARCHAR(255) NULL,
  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_deliv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_deliv_proof FOREIGN KEY (proof_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Propositions de commande envoyées aux prestataires (driver_offers)
CREATE TABLE dispatch_offers (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  provider_id    BIGINT UNSIGNED NOT NULL,
  round_no       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  distance_m     INT UNSIGNED NULL,
  status         ENUM('pending','accepted','refused','expired','superseded','countered') NOT NULL DEFAULT 'pending',
  refusal_reason VARCHAR(120) NULL,
  sent_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     DATETIME NOT NULL,
  responded_at   DATETIME NULL,
  UNIQUE KEY uq_offer (order_id, provider_id),
  KEY idx_offer_provider (provider_id, status),
  KEY idx_offer_expiry (status, expires_at),
  CONSTRAINT fk_offer_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Négociation du prix (propositions client et contre-propositions prestataires)
CREATE TABLE fare_negotiations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  provider_id   BIGINT UNSIGNED NULL COMMENT 'NULL pour une proposition du client adressée à tous',
  proposed_by   ENUM('customer','provider') NOT NULL,
  amount        INT UNSIGNED NOT NULL,
  status        ENUM('pending','accepted','rejected','expired','superseded','withdrawn') NOT NULL DEFAULT 'pending',
  expires_at    DATETIME NOT NULL,
  responded_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_neg_order (order_id, status),
  KEY idx_neg_provider (provider_id),
  CONSTRAINT fk_neg_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_neg_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_neg_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Affectations : active_order_id UNIQUE garantit un seul prestataire actif par commande
CREATE TABLE assignments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        BIGINT UNSIGNED NOT NULL,
  provider_id     BIGINT UNSIGNED NOT NULL,
  active_order_id BIGINT UNSIGNED NULL,
  agreed_price    INT UNSIGNED NOT NULL,
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at        DATETIME NULL,
  end_reason      VARCHAR(120) NULL,
  UNIQUE KEY uq_assign_active (active_order_id),
  KEY idx_assign_order (order_id),
  KEY idx_assign_provider (provider_id, assigned_at),
  CONSTRAINT fk_assign_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique de positions limité aux commandes actives (purge automatique, cf. settings)
CREATE TABLE driver_locations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  provider_id  BIGINT UNSIGNED NOT NULL,
  lat          DECIMAL(9,6) NOT NULL,
  lng          DECIMAL(9,6) NOT NULL,
  heading      SMALLINT NULL,
  speed_kmh    DECIMAL(5,1) NULL,
  recorded_at  DATETIME NOT NULL,
  KEY idx_dl_order (order_id, id),
  KEY idx_dl_recorded (recorded_at),
  CONSTRAINT fk_dl_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotion_redemptions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promotion_id  INT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NOT NULL,
  amount        INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_redemption_order (order_id),
  KEY idx_red_user (promotion_id, user_id),
  CONSTRAINT fk_red_promo FOREIGN KEY (promotion_id) REFERENCES promotions(id),
  CONSTRAINT fk_red_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_red_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
