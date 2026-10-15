-- MARWA — script d'installation complet (généré par bin/build-install-sql.php)
-- Importer dans une base vide créée en utf8mb4_unicode_ci (phpMyAdmin → Importer).
-- Préférer « php bin/migrate.php », qui suit les migrations déjà appliquées.

-- ==================== 001_core_schema.sql ====================
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

-- ==================== 002_finance_schema.sql ====================
-- =====================================================================
-- MARWA — Migration 002 : paiements, commissions, grand livre, versements
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE users ADD CONSTRAINT fk_users_photo FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL;

CREATE TABLE payments (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  order_id         BIGINT UNSIGNED NOT NULL,
  payer_user_id    BIGINT UNSIGNED NULL,
  method           ENUM('cash','demo_mobile_money','mobile_money','card','business_account') NOT NULL,
  gateway          VARCHAR(30) NOT NULL COMMENT 'cash, demo, business, <fournisseur>',
  amount           INT UNSIGNED NOT NULL COMMENT 'Montant attendu',
  declared_amount  INT UNSIGNED NULL COMMENT 'Espèces : montant déclaré par le collecteur',
  verified_amount  INT UNSIGNED NULL COMMENT 'Montant vérifié (rapprochement)',
  fee_amount       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Frais du prestataire de paiement',
  status           ENUM('pending','declared','confirmed','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  collector_type   ENUM('provider','merchant','gateway','business','marwa') NOT NULL,
  collector_id     BIGINT UNSIGNED NULL,
  idempotency_key  VARCHAR(80) NOT NULL,
  external_ref     VARCHAR(120) NULL,
  failure_reason   VARCHAR(255) NULL,
  verified_by      BIGINT UNSIGNED NULL,
  verified_at      DATETIME NULL,
  confirmed_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_idem (idempotency_key),
  UNIQUE KEY uq_pay_external (gateway, external_ref),
  KEY idx_pay_order (order_id),
  KEY idx_pay_status (status, created_at),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_pay_payer FOREIGN KEY (payer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal brut des notifications reçues des prestataires de paiement (webhooks)
CREATE TABLE payment_webhooks (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway         VARCHAR(30) NOT NULL,
  event_id        VARCHAR(120) NOT NULL,
  external_ref    VARCHAR(120) NULL,
  signature_valid TINYINT(1) NOT NULL,
  payload         LONGTEXT NOT NULL,
  processed_at    DATETIME NULL,
  result          VARCHAR(255) NULL,
  received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webhook_event (gateway, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  payment_id     BIGINT UNSIGNED NULL,
  amount         INT UNSIGNED NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  borne_by       ENUM('marwa','provider','merchant') NOT NULL DEFAULT 'marwa',
  status         ENUM('requested','approved','completed','rejected') NOT NULL DEFAULT 'requested',
  requested_by   BIGINT UNSIGNED NOT NULL,
  approved_by    BIGINT UNSIGNED NULL,
  complaint_id   BIGINT UNSIGNED NULL,
  ledger_txn_id  BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at   DATETIME NULL,
  KEY idx_refund_order (order_id),
  CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_refund_requester FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT chk_refund_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Détail des commissions calculées par commande (une ligne par partie)
CREATE TABLE commissions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id         BIGINT UNSIGNED NOT NULL,
  party            ENUM('provider','merchant') NOT NULL,
  party_user_id    BIGINT UNSIGNED NOT NULL,
  rule_id          INT UNSIGNED NULL,
  model            VARCHAR(20) NOT NULL,
  base_amount      INT UNSIGNED NOT NULL,
  percent_bp       INT UNSIGNED NOT NULL DEFAULT 0,
  fixed_amount     INT UNSIGNED NOT NULL DEFAULT 0,
  commission       INT UNSIGNED NOT NULL,
  vat_amount       INT UNSIGNED NOT NULL DEFAULT 0,
  partner_net      INT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commission (order_id, party),
  CONSTRAINT fk_comm_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_comm_rule FOREIGN KEY (rule_id) REFERENCES commission_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Grand livre interne en partie double (écritures immuables)
-- Comptes de partenaire : solde créditeur = MARWA doit au partenaire ;
-- solde débiteur = le partenaire doit à MARWA (ex. commission sur espèces).
-- ---------------------------------------------------------------------
CREATE TABLE ledger_accounts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL UNIQUE,
  name         VARCHAR(150) NOT NULL,
  type         ENUM('asset','liability','revenue','expense','equity') NOT NULL,
  owner_type   ENUM('system','provider','merchant','business') NOT NULL DEFAULT 'system',
  owner_id     BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_transactions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  kind          VARCHAR(40) NOT NULL COMMENT 'settlement, payment, refund, payout, remittance, expense, adjustment, subscription, fee',
  dedupe_key    VARCHAR(120) NULL COMMENT 'Empêche la double comptabilisation',
  order_id      BIGINT UNSIGNED NULL,
  description   VARCHAR(255) NOT NULL,
  reverses_id   BIGINT UNSIGNED NULL,
  created_by    BIGINT UNSIGNED NULL,
  occurred_at   DATETIME NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_dedupe (dedupe_key),
  KEY idx_lt_order (order_id),
  KEY idx_lt_kind (kind, occurred_at),
  CONSTRAINT fk_lt_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_lt_reverses FOREIGN KEY (reverses_id) REFERENCES ledger_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  account_id      INT UNSIGNED NOT NULL,
  debit           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  credit          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  memo            VARCHAR(190) NULL,
  occurred_at     DATETIME NOT NULL,
  KEY idx_le_account (account_id, occurred_at),
  KEY idx_le_txn (transaction_id),
  CONSTRAINT fk_le_txn FOREIGN KEY (transaction_id) REFERENCES ledger_transactions(id),
  CONSTRAINT fk_le_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT chk_le_side CHECK ((debit = 0 AND credit > 0) OR (credit = 0 AND debit > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Soldes des partenaires calculés depuis le grand livre (positif = MARWA doit au partenaire)
CREATE VIEW partner_balances AS
  SELECT a.id AS account_id, a.owner_type, a.owner_id, a.name,
         COALESCE(SUM(e.credit),0) - COALESCE(SUM(e.debit),0) AS balance
    FROM ledger_accounts a
    LEFT JOIN ledger_entries e ON e.account_id = a.id
   WHERE a.owner_type IN ('provider','merchant','business')
   GROUP BY a.id, a.owner_type, a.owner_id, a.name;

CREATE TABLE payouts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  direction       ENUM('to_partner','from_partner') NOT NULL DEFAULT 'to_partner' COMMENT 'from_partner = reversement de commissions encaissées en espèces',
  partner_type    ENUM('provider','merchant') NOT NULL,
  partner_user_id BIGINT UNSIGNED NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  method          VARCHAR(40) NOT NULL COMMENT 'cash, mobile_money, bank_transfer',
  destination     VARCHAR(120) NULL COMMENT 'N° Mobile Money / IBAN partiel',
  status          ENUM('requested','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'requested',
  reference       VARCHAR(120) NULL COMMENT 'Référence du justificatif',
  proof_file_id   BIGINT UNSIGNED NULL,
  note            VARCHAR(255) NULL,
  requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by     BIGINT UNSIGNED NULL,
  reviewed_at     DATETIME NULL,
  paid_at         DATETIME NULL,
  ledger_txn_id   BIGINT UNSIGNED NULL,
  KEY idx_payout_partner (partner_user_id, status),
  KEY idx_payout_status (status),
  CONSTRAINT fk_payout_partner FOREIGN KEY (partner_user_id) REFERENCES users(id),
  CONSTRAINT fk_payout_proof FOREIGN KEY (proof_file_id) REFERENCES files(id) ON DELETE SET NULL,
  CONSTRAINT chk_payout_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category      VARCHAR(60) NOT NULL,
  description   VARCHAR(255) NOT NULL,
  amount        INT UNSIGNED NOT NULL,
  expense_date  DATE NOT NULL,
  city_id       INT UNSIGNED NULL,
  paid_from     ENUM('cash','bank','mobile_money') NOT NULL DEFAULT 'cash',
  receipt_file_id BIGINT UNSIGNED NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  ledger_txn_id BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_expense_date (expense_date),
  CONSTRAINT fk_expense_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_expense_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_type    ENUM('provider','merchant','business') NOT NULL,
  partner_user_id BIGINT UNSIGNED NOT NULL,
  plan_name       VARCHAR(80) NOT NULL,
  amount          INT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  period_end      DATE NOT NULL,
  status          ENUM('active','billed','cancelled') NOT NULL DEFAULT 'active',
  ledger_txn_id   BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sub_period (partner_user_id, period_start),
  CONSTRAINT fk_sub_user FOREIGN KEY (partner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number        VARCHAR(30) NOT NULL UNIQUE,
  kind          ENUM('receipt','business_statement','partner_statement') NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  business_id   BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  period_start  DATE NULL,
  period_end    DATE NULL,
  amount        BIGINT NOT NULL,
  status        ENUM('issued','paid','void') NOT NULL DEFAULT 'issued',
  issued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at       DATETIME NULL,
  UNIQUE KEY uq_invoice_order (kind, order_id),
  KEY idx_invoice_business (business_id),
  CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_invoice_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immuabilité du grand livre : aucune modification ni suppression possible
CREATE TRIGGER trg_ledger_entries_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries est immuable : passez une ecriture d ajustement';

CREATE TRIGGER trg_ledger_entries_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries est immuable : passez une ecriture d ajustement';

CREATE TRIGGER trg_ledger_txn_no_update BEFORE UPDATE ON ledger_transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions est immuable';

CREATE TRIGGER trg_ledger_txn_no_delete BEFORE DELETE ON ledger_transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_transactions est immuable';

-- ==================== 003_communication_schema.sql ====================
-- =====================================================================
-- MARWA — Migration 003 : notifications, messagerie, évaluations,
-- réclamations, urgences, audit, paramètres, temps réel
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(50) NOT NULL,
  title       VARCHAR(150) NOT NULL,
  body        VARCHAR(500) NOT NULL,
  data        LONGTEXT NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, id),
  KEY idx_notif_unread (user_id, read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File d'envoi des messages externes (e-mail, SMS, WhatsApp)
CREATE TABLE outbound_messages (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel      ENUM('email','sms','whatsapp') NOT NULL,
  recipient    VARCHAR(190) NOT NULL,
  subject      VARCHAR(190) NULL,
  body         TEXT NOT NULL,
  driver       VARCHAR(30) NOT NULL,
  status       ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error        VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  KEY idx_out_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flux d'événements temps réel consommé par interrogation incrémentale (?since=id)
CREATE TABLE realtime_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(50) NOT NULL,
  order_id    BIGINT UNSIGNED NULL,
  payload     LONGTEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rt_user (user_id, id),
  KEY idx_rt_created (created_at),
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    BIGINT UNSIGNED NOT NULL,
  sender_id   BIGINT UNSIGNED NOT NULL,
  body        VARCHAR(500) NOT NULL,
  is_flagged  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_order (order_id, id),
  CONSTRAINT fk_msg_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ratings (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  rater_id     BIGINT UNSIGNED NOT NULL,
  target_type  ENUM('provider','merchant','service','customer') NOT NULL,
  target_id    BIGINT UNSIGNED NULL,
  score        TINYINT UNSIGNED NOT NULL,
  comment      VARCHAR(500) NULL,
  status       ENUM('published','pending','hidden') NOT NULL DEFAULT 'published',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rating (order_id, rater_id, target_type),
  KEY idx_rating_target (target_type, target_id),
  CONSTRAINT fk_rating_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_rating_rater FOREIGN KEY (rater_id) REFERENCES users(id),
  CONSTRAINT chk_rating_score CHECK (score BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE complaints (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference     VARCHAR(20) NOT NULL UNIQUE,
  user_id       BIGINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NULL,
  category      ENUM('ride','order','payment','lost_item','safety','account','report_user','other') NOT NULL,
  subject       VARCHAR(150) NOT NULL,
  description   TEXT NOT NULL,
  status        ENUM('open','in_progress','resolved','rejected') NOT NULL DEFAULT 'open',
  priority      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to   BIGINT UNSIGNED NULL,
  resolution    TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at   DATETIME NULL,
  KEY idx_complaint_status (status, priority),
  KEY idx_complaint_user (user_id),
  CONSTRAINT fk_complaint_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_complaint_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_complaint_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE refunds ADD CONSTRAINT fk_refund_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL;

CREATE TABLE complaint_messages (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id  BIGINT UNSIGNED NOT NULL,
  author_id     BIGINT UNSIGNED NOT NULL,
  body          TEXT NOT NULL,
  is_internal   TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cm (complaint_id, id),
  CONSTRAINT fk_cm_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE emergency_alerts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  order_id     BIGINT UNSIGNED NULL,
  lat          DECIMAL(9,6) NULL,
  lng          DECIMAL(9,6) NULL,
  message      VARCHAR(255) NULL,
  status       ENUM('open','acknowledged','closed') NOT NULL DEFAULT 'open',
  handled_by   BIGINT UNSIGNED NULL,
  handled_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_alert_status (status),
  CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_alert_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(80) NOT NULL,
  entity_type  VARCHAR(40) NULL,
  entity_id    BIGINT UNSIGNED NULL,
  old_values   LONGTEXT NULL,
  new_values   LONGTEXT NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs est immuable';

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs est immuable';

CREATE TABLE settings (
  k           VARCHAR(80) PRIMARY KEY,
  v           LONGTEXT NOT NULL COMMENT 'Valeur JSON',
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE content_pages (
  slug        VARCHAR(60) PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  body        TEXT NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== 004_reference_data.sql ====================
-- =====================================================================
-- MARWA — Migration 004 : données de référence indispensables
-- (rôles, permissions, comptes du grand livre, paramètres par défaut).
-- Les tarifs et commissions ci-dessous sont des VALEURS INITIALES
-- MODIFIABLES dans l'administration ; elles ne sont pas imposées par le code.
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO roles (code, name, is_staff) VALUES
 ('client',   'Client', 0),
 ('driver',   'Chauffeur / Taximan', 0),
 ('courier',  'Livreur', 0),
 ('merchant', 'Restaurant / Commerçant', 0),
 ('business', 'Entreprise', 0),
 ('admin',    'Administrateur', 1),
 ('support',  'Assistance client', 1),
 ('finance',  'Finance / Comptabilité', 1);

INSERT INTO permissions (code, description) VALUES
 ('admin.dashboard',   'Voir le tableau de bord de direction'),
 ('users.view',        'Consulter les utilisateurs'),
 ('users.manage',      'Suspendre / réactiver des utilisateurs'),
 ('partners.validate', 'Valider chauffeurs, livreurs, commerces et entreprises'),
 ('orders.view',       'Consulter toutes les commandes'),
 ('orders.manage',     'Annuler / réaffecter / arbitrer des commandes'),
 ('pricing.manage',    'Modifier les règles tarifaires'),
 ('commissions.manage','Modifier les règles de commission'),
 ('zones.manage',      'Gérer villes, zones et points de repère'),
 ('finance.view',      'Consulter paiements, grand livre et rapports financiers'),
 ('payments.manage',   'Vérifier les paiements et rapprochements'),
 ('refunds.manage',    'Approuver les remboursements'),
 ('payouts.manage',    'Valider les versements aux partenaires'),
 ('expenses.manage',   'Saisir les dépenses opérationnelles'),
 ('complaints.manage', 'Traiter réclamations, litiges et alertes'),
 ('promotions.manage', 'Gérer les promotions'),
 ('notifications.send','Envoyer des notifications de masse'),
 ('settings.manage',   'Modifier les paramètres et fournisseurs'),
 ('audit.view',        'Consulter le journal d''audit'),
 ('reports.export',    'Exporter les rapports');

-- admin : toutes les permissions
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin';
-- support
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   ON p.code IN ('users.view','orders.view','orders.manage','complaints.manage','partners.validate')
  WHERE r.code = 'support';
-- finance
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
   ON p.code IN ('admin.dashboard','orders.view','finance.view','payments.manage','refunds.manage','payouts.manage','expenses.manage','reports.export','audit.view')
  WHERE r.code = 'finance';

-- Comptes système du grand livre
INSERT INTO ledger_accounts (code, name, type, owner_type) VALUES
 ('1000', 'Trésorerie MARWA (caisse / banque)', 'asset', 'system'),
 ('1100', 'Fonds chez les prestataires de paiement', 'asset', 'system'),
 ('2300', 'Paiements clients reçus d''avance', 'liability', 'system'),
 ('2400', 'TVA collectée sur commissions', 'liability', 'system'),
 ('3000', 'Capitaux / apports', 'equity', 'system'),
 ('4000', 'Commissions MARWA', 'revenue', 'system'),
 ('4100', 'Frais de service et de réservation', 'revenue', 'system'),
 ('4300', 'Abonnements professionnels', 'revenue', 'system'),
 ('5000', 'Promotions financées par MARWA', 'expense', 'system'),
 ('5100', 'Frais de paiement électronique', 'expense', 'system'),
 ('5200', 'Dépenses opérationnelles', 'expense', 'system'),
 ('5300', 'Remboursements pris en charge par MARWA', 'expense', 'system'),
 ('5400', 'Écarts de caisse et ajustements', 'expense', 'system');

-- Niamey : coordonnées du centre-ville (donnée publique, vérifiable sur OpenStreetMap)
INSERT INTO cities (country_code, region, name, lat, lng, is_active) VALUES
 ('NE', 'Niamey', 'Niamey', 13.512700, 2.112600, 1);

-- Règles tarifaires initiales (à ajuster par l'administration avant lancement)
INSERT INTO pricing_rules (name, service_type, vehicle_category, base_fare, per_km, per_min, min_fare, booking_fee, service_fee, night_multiplier_pct, night_start, night_end, priority) VALUES
 ('Taxi standard',        'taxi',     'taxi',      300, 150, 10,  700,   0,  0, 120, '22:00:00', '05:00:00', 0),
 ('Berline confort',      'taxi',     'sedan',     500, 250, 15, 1200, 100,  0, 120, '22:00:00', '05:00:00', 0),
 ('Véhicule familial',    'taxi',     'family',    700, 300, 15, 1500, 100,  0, 120, '22:00:00', '05:00:00', 0),
 ('Moto-taxi',            'taxi',     'moto_taxi', 200, 100,  5,  400,   0,  0, 120, '22:00:00', '05:00:00', 0),
 ('Transport pro',        'pro',      NULL,        800, 300, 15, 2000, 200,  0, 100, NULL, NULL, 0),
 ('Livraison repas',      'food',     NULL,        300, 100,  0,  500,   0,100, 100, NULL, NULL, 0),
 ('Livraison commerce',   'shop',     NULL,        300, 100,  0,  500,   0,100, 100, NULL, NULL, 0),
 ('Colis',                'parcel',   NULL,        400, 125,  0,  700,   0,  0, 100, NULL, NULL, 0),
 ('Documents',            'document', NULL,        400, 100,  0,  600,   0,  0, 100, NULL, NULL, 0),
 ('Courses particuliers', 'errand',   NULL,        500, 125,  0,  800,   0,  0, 100, NULL, NULL, 0),
 ('Livraison urgente',    'urgent',   NULL,        700, 200,  0, 1200,   0,  0, 100, NULL, NULL, 0);

-- Règles de commission initiales (exemple de démonstration : 10 % sur les courses)
INSERT INTO commission_rules (name, applies_to, service_type, provider_type, model, percent_bp, fixed_amount, priority) VALUES
 ('Commission courses (défaut)',      'provider', NULL, 'driver',  'percent', 1000,   0, 0),
 ('Commission livraisons (défaut)',   'provider', NULL, 'courier', 'percent', 1000,   0, 0),
 ('Commission restaurants (défaut)',  'merchant', NULL, NULL,      'percent', 1500,   0, 0);

INSERT INTO settings (k, v) VALUES
 ('dispatch.radius_km',            '6'),
 ('dispatch.batch_size',           '5'),
 ('dispatch.offer_ttl_seconds',    '60'),
 ('dispatch.max_rounds',           '4'),
 ('dispatch.location_max_age_s',   '300'),
 ('dispatch.rating_weight',        '0'),
 ('negotiation.counter_ttl_seconds','90'),
 ('negotiation.max_counters_per_provider','3'),
 ('negotiation.max_customer_updates','5'),
 ('orders.max_open_per_customer',  '2'),
 ('orders.merchant_accept_timeout_min','10'),
 ('orders.customer_free_cancel_seconds','120'),
 ('pricing.rounding_step',         '50'),
 ('tracking.history_retention_days','30'),
 ('tracking.stale_after_seconds',  '45'),
 ('tracking.min_interval_seconds', '3'),
 ('finance.vat_rate_bp',           '0'),
 ('finance.cash_discrepancy_tolerance','0'),
 ('zones.enforce',                 'false'),
 ('ratings.moderation',            'false'),
 ('emergency.numbers',             '[]'),
 ('emergency.message',             '"Aucune intégration avec les services de secours n''est active. Composez directement le numéro d''urgence local. L''équipe MARWA est alertée."'),
 ('support.phone',                 '""'),
 ('support.email',                 '""'),
 ('payments.methods',              '["cash","demo_mobile_money","business_account"]'),
 ('payments.demo_fee_bp',          '100'),
 ('prohibited_items',              '["Armes, munitions et explosifs","Stupéfiants et substances illicites","Produits inflammables ou dangereux","Argent liquide et objets de grande valeur non déclarés","Animaux vivants","Médicaments soumis à prescription (sauf pharmacie autorisée)","Tout objet interdit par la réglementation nigérienne"]');

INSERT INTO content_pages (slug, title, body) VALUES
 ('cgu', 'Conditions générales d''utilisation', 'Texte à rédiger et à faire valider juridiquement avant le lancement commercial.'),
 ('confidentialite', 'Politique de confidentialité', 'Texte à rédiger conformément à la loi nigérienne sur la protection des données à caractère personnel, et à faire valider par un juriste.'),
 ('securite', 'Règles de sécurité', 'Vérifiez toujours la plaque d''immatriculation et le nom du chauffeur avant de monter. Ne partagez jamais votre code de livraison avant d''avoir reçu votre colis.');

-- ==================== 005_mobile_i18n_security.sql ====================
-- =====================================================================
-- MARWA — migration 005 : photos de catalogue, langues, notifications push,
-- double authentification du personnel, déconnexion de tous les appareils.
-- Compatible MariaDB 10.6+ et MySQL 8.0.16+.
-- =====================================================================

-- Plusieurs photos par produit (la première reste aussi dans products.photo_file_id)
CREATE TABLE product_photos (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  BIGINT UNSIGNED NOT NULL,
  file_id     BIGINT UNSIGNED NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pp_product (product_id, sort_order),
  CONSTRAINT fk_pp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_photos (product_id, file_id, sort_order)
  SELECT id, photo_file_id, 0 FROM products WHERE photo_file_id IS NOT NULL;

-- Photo de couverture des commerces, logo des entreprises, photo des catégories
ALTER TABLE merchants ADD COLUMN cover_file_id BIGINT UNSIGNED NULL AFTER logo_file_id,
  ADD CONSTRAINT fk_merchant_cover FOREIGN KEY (cover_file_id) REFERENCES files(id) ON DELETE SET NULL;
ALTER TABLE businesses ADD COLUMN logo_file_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_business_logo FOREIGN KEY (logo_file_id) REFERENCES files(id) ON DELETE SET NULL;
ALTER TABLE product_categories ADD COLUMN photo_file_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_pcat_photo FOREIGN KEY (photo_file_id) REFERENCES files(id) ON DELETE SET NULL;

-- Langue préférée, double authentification (TOTP), version de session (déconnexion globale)
ALTER TABLE users
  ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'fr' AFTER is_demo,
  ADD COLUMN totp_secret_enc VARCHAR(255) NULL AFTER locale,
  ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret_enc,
  ADD COLUMN totp_last_step BIGINT NULL AFTER totp_enabled_at,
  ADD COLUMN totp_recovery_json TEXT NULL AFTER totp_last_step,
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_recovery_json,
  ADD COLUMN password_changed_at DATETIME NULL AFTER session_version,
  ADD COLUMN known_devices_json TEXT NULL AFTER password_changed_at;

-- Abonnements aux notifications push (Web Push, norme W3C / RFC 8030)
CREATE TABLE push_subscriptions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  endpoint      VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh        VARCHAR(255) NOT NULL,
  auth_secret   VARCHAR(64) NOT NULL,
  user_agent    VARCHAR(255) NULL,
  failures      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traductions modifiables depuis l'administration (priment sur les fichiers de langue)
CREATE TABLE translations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locale      VARCHAR(10) NOT NULL,
  source_hash CHAR(40) NOT NULL,
  source      TEXT NOT NULL,
  target      TEXT NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_translation (locale, source_hash),
  CONSTRAINT fk_tr_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (k, v) VALUES
 ('nearby.radius_km', '5'),
 ('nearby.location_max_age_s', '120'),
 ('nearby.max_vehicles', '20'),
 ('catalog.max_photos_per_product', '6'),
 ('i18n.languages', '["fr","ha","dje","en"]'),
 ('security.staff_2fa_required', 'false'),
 ('push.enabled', 'true')
ON DUPLICATE KEY UPDATE v = v;

CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (version) VALUES ('001_core_schema');
INSERT INTO schema_migrations (version) VALUES ('002_finance_schema');
INSERT INTO schema_migrations (version) VALUES ('003_communication_schema');
INSERT INTO schema_migrations (version) VALUES ('004_reference_data');
INSERT INTO schema_migrations (version) VALUES ('005_mobile_i18n_security');
