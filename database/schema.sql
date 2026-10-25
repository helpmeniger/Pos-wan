-- =============================================================================
--  JOB NIGER — Schéma complet de la base de données (Phase 1)
--  « Un travail, une opportunité, un revenu. »
--
--  Cible      : MySQL 8.0.16+ ou MariaDB 10.6+ (WampServer 3.3+)
--  Moteur     : InnoDB — jeu de caractères utf8mb4 / utf8mb4_unicode_ci
--  Monnaie    : XOF (franc CFA BCEAO) — montants en DECIMAL(15,2), jamais FLOAT
--  Fuseau     : les dates sont stockées en heure locale Africa/Niamey (UTC+1,
--               pas d'heure d'été) ; l'application force la connexion à '+01:00'.
--
--  Import     : mysql -u root -p < database/schema.sql
--               (ou phpMyAdmin > Importer — les blocs DELIMITER sont supportés)
--
--  Règles structurantes :
--   * Les tables financières sont en AJOUT SEUL : les triggers en fin de
--     fichier interdisent la suppression et la modification des montants.
--     Les corrections passent par des écritures correctives (contre-passation).
--   * Idempotence : clés uniques sur (passerelle, id d'événement),
--     (passerelle, référence), et idempotency_key sur les opérations.
--   * Le statut d'un paiement est indépendant du statut d'une mission.
--   * Les utilisateurs ne sont jamais supprimés physiquement (désactivation).
-- =============================================================================

CREATE DATABASE IF NOT EXISTS job_niger
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE job_niger;

SET NAMES utf8mb4;
SET time_zone = '+01:00';
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. RÉFÉRENTIELS GÉOGRAPHIQUES ET LINGUISTIQUES
-- -----------------------------------------------------------------------------

CREATE TABLE cities (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(60)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  region        VARCHAR(100) NOT NULL,
  latitude      DECIMAL(9,6) NULL,
  longitude     DECIMAL(9,6) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cities_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE neighborhoods (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_id       SMALLINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  latitude      DECIMAL(9,6) NULL,
  longitude     DECIMAL(9,6) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_neighborhoods_city_name (city_id, name),
  CONSTRAINT fk_neighborhoods_city FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE languages (
  code          VARCHAR(8)   NOT NULL,           -- fr, ha, dje, en, ar, ff, kr, tmh
  name          VARCHAR(60)  NOT NULL,
  is_ui_language TINYINT(1)  NOT NULL DEFAULT 0, -- langue d'interface disponible
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. RÔLES, PERMISSIONS, UTILISATEURS
-- -----------------------------------------------------------------------------

CREATE TABLE roles (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,   -- WORKER, CLIENT, SUPER_ADMIN, ADMIN, FINANCE_ADMIN, MODERATOR, SUPPORT
  name          VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NULL,
  is_staff      TINYINT(1)   NOT NULL DEFAULT 0,
  is_system     TINYINT(1)   NOT NULL DEFAULT 1,   -- non supprimable
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(80)  NOT NULL,   -- ex : finance.transactions.view
  module        VARCHAR(40)  NOT NULL,
  description   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36)     NOT NULL,          -- identifiant exposé (URL, API)
  primary_type          ENUM('WORKER','CLIENT','STAFF') NOT NULL,
  first_name            VARCHAR(80)  NOT NULL,
  last_name             VARCHAR(80)  NOT NULL,
  phone                 VARCHAR(20)  NOT NULL,          -- format E.164 : +227XXXXXXXX
  phone_verified_at     DATETIME     NULL,
  email                 VARCHAR(190) NULL,
  email_verified_at     DATETIME     NULL,
  password_hash         VARCHAR(255) NOT NULL,          -- Argon2id (password_hash)
  must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at   DATETIME     NULL,
  birth_date            DATE         NULL,              -- requis pour métiers à âge minimum
  age_verified_at       DATETIME     NULL,
  city_id               SMALLINT UNSIGNED NULL,
  neighborhood_id       INT UNSIGNED NULL,
  locale                VARCHAR(8)   NOT NULL DEFAULT 'fr',
  status                ENUM('PENDING','ACTIVE','SUSPENDED','BANNED','DEACTIVATED') NOT NULL DEFAULT 'PENDING',
  status_reason         VARCHAR(255) NULL,
  suspended_until       DATETIME     NULL,
  failed_login_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME     NULL,
  last_login_at         DATETIME     NULL,
  last_login_ip         VARCHAR(45)  NULL,
  is_demo               TINYINT(1)   NOT NULL DEFAULT 0,  -- données de démonstration
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deactivated_at        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_uuid (uuid),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status),
  KEY idx_users_type_city (primary_type, city_id),
  KEY idx_users_demo (is_demo),
  CONSTRAINT fk_users_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_users_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods(id),
  CONSTRAINT fk_users_locale FOREIGN KEY (locale) REFERENCES languages(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id       BIGINT UNSIGNED NOT NULL,
  role_id       SMALLINT UNSIGNED NOT NULL,
  granted_by    BIGINT UNSIGNED NULL,
  granted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_ur_granted_by FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fichiers : registre central. Les fichiers PRIVATE sont hors de public/ et
-- servis uniquement par un contrôleur qui vérifie les droits.
CREATE TABLE stored_files (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)     NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  disk          ENUM('PUBLIC','PRIVATE') NOT NULL DEFAULT 'PRIVATE',
  purpose       ENUM('AVATAR','PORTFOLIO','MESSAGE','APPLICATION','IDENTITY','QUALIFICATION',
                     'BUSINESS_DOC','PAYMENT_PROOF','DISPUTE_EVIDENCE','RECEIPT','INVOICE','EXPORT','OTHER') NOT NULL,
  storage_path  VARCHAR(255) NOT NULL,      -- chemin relatif, nom aléatoire
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,      -- type détecté côté serveur (finfo)
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64)     NOT NULL,
  retention_until DATE       NULL,          -- politique de conservation (pièces d'identité)
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_files_uuid (uuid),
  KEY idx_files_owner (owner_user_id, purpose),
  CONSTRAINT fk_files_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_profiles (
  user_id             BIGINT UNSIGNED NOT NULL,
  display_name        VARCHAR(120) NULL,         -- nom professionnel public
  headline            VARCHAR(160) NULL,         -- métier principal affiché
  bio                 TEXT         NULL,
  avatar_file_id      BIGINT UNSIGNED NULL,
  gender              ENUM('F','M','NON_PRECISE') NOT NULL DEFAULT 'NON_PRECISE',
  years_experience    TINYINT UNSIGNED NULL,
  hourly_rate         DECIMAL(15,2) NULL,        -- tarif indicatif
  daily_rate          DECIMAL(15,2) NULL,
  verification_level  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0..3, voir docs/02
  is_available        TINYINT(1)   NOT NULL DEFAULT 1,
  accepts_remote      TINYINT(1)   NOT NULL DEFAULT 0,
  is_public           TINYINT(1)   NOT NULL DEFAULT 1,
  approx_latitude     DECIMAL(8,4) NULL,         -- position APPROXIMATIVE (~10 m–1 km)
  approx_longitude    DECIMAL(8,4) NULL,
  geoloc_consent      TINYINT(1)   NOT NULL DEFAULT 0,
  -- Agrégats dénormalisés recalculés par les services (jamais saisis)
  rating_avg          DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count        INT UNSIGNED NOT NULL DEFAULT 0,
  missions_completed  INT UNSIGNED NOT NULL DEFAULT 0,
  client_rating_avg   DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  client_rating_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_profiles_search (is_public, is_available, verification_level, rating_avg),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_profiles_avatar FOREIGN KEY (avatar_file_id) REFERENCES stored_files(id),
  CONSTRAINT chk_profiles_level CHECK (verification_level BETWEEN 0 AND 3),
  CONSTRAINT chk_profiles_rates CHECK ((hourly_rate IS NULL OR hourly_rate >= 0) AND (daily_rate IS NULL OR daily_rate >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_languages (
  user_id       BIGINT UNSIGNED NOT NULL,
  language_code VARCHAR(8) NOT NULL,
  level         ENUM('NOTIONS','COURANT','MATERNELLE') NOT NULL DEFAULT 'COURANT',
  PRIMARY KEY (user_id, language_code),
  KEY idx_ul_lang (language_code),
  CONSTRAINT fk_ul_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_ul_lang FOREIGN KEY (language_code) REFERENCES languages(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_consents (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  document      ENUM('CGU','PRIVACY','COMMISSION_TERMS','MARKETING','GEOLOCATION','SMS') NOT NULL,
  version       VARCHAR(20)  NOT NULL,
  accepted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  revoked_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_consents_user_doc (user_id, document),
  CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_documents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          ENUM('CGU','PRIVACY','COMMISSION_TERMS','DISPUTE_POLICY','REFUND_POLICY') NOT NULL,
  version       VARCHAR(20)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  content       MEDIUMTEXT   NOT NULL,
  published_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legal_code_version (code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,       -- SHA-256 du jeton, jamais le jeton
  channel       ENUM('EMAIL','SMS') NOT NULL,
  expires_at    DATETIME     NOT NULL,
  used_at       DATETIME     NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_token (token_hash),
  KEY idx_pr_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  phone         VARCHAR(20)  NOT NULL,
  purpose       ENUM('PHONE_VERIFY','LOGIN_2FA','PASSWORD_RESET','PAYOUT_CONFIRM') NOT NULL,
  code_hash     VARCHAR(255) NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at    DATETIME     NOT NULL,
  consumed_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_phone_purpose (phone, purpose, created_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitation de débit (connexion, OTP, messages, candidatures…)
CREATE TABLE rate_limits (
  bucket_key    VARCHAR(190) NOT NULL,   -- ex : login:ip:1.2.3.4 / login:phone:+227…
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME     NOT NULL,
  blocked_until DATETIME     NULL,
  PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_blocks (
  blocker_user_id BIGINT UNSIGNED NOT NULL,
  blocked_user_id BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_user_id, blocked_user_id),
  KEY idx_blocks_blocked (blocked_user_id),
  CONSTRAINT fk_blocks_blocker FOREIGN KEY (blocker_user_id) REFERENCES users(id),
  CONSTRAINT fk_blocks_blocked FOREIGN KEY (blocked_user_id) REFERENCES users(id),
  CONSTRAINT chk_blocks_self CHECK (blocker_user_id <> blocked_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. ORGANISATIONS (entreprises, commerces, ONG, écoles…) ET ABONNEMENTS
-- -----------------------------------------------------------------------------

CREATE TABLE subscription_plans (
  id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(40)  NOT NULL,     -- GRATUIT, PROFESSIONNEL, ENTREPRISE
  name              VARCHAR(100) NOT NULL,
  description       TEXT         NULL,
  monthly_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
  yearly_price      DECIMAL(15,2) NULL,
  max_active_posts  INT UNSIGNED NULL,         -- NULL = illimité
  max_members       INT UNSIGNED NULL,
  max_sites         INT UNSIGNED NULL,
  features_json     TEXT         NULL,         -- liste des fonctionnalités (JSON)
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order        SMALLINT     NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code),
  CONSTRAINT chk_plans_price CHECK (monthly_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE businesses (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)     NOT NULL,
  owner_user_id       BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(160) NOT NULL,
  kind                ENUM('COMMERCE','SUPERMARCHE','RESTAURANT','HOTEL','ENTREPRISE','PME','STARTUP','ONG',
                           'ASSOCIATION','ECOLE','UNIVERSITE','CENTRE_FORMATION','EVENEMENTIEL','CONSTRUCTION',
                           'AGRICULTURE','ELEVAGE','GARAGE','TRANSPORT','IMMOBILIER','ADMINISTRATION','AUTRE') NOT NULL,
  sector              VARCHAR(120) NULL,
  rccm_number         VARCHAR(60)  NULL,   -- Registre du commerce (si pertinent)
  nif_number          VARCHAR(60)  NULL,   -- Numéro d'identification fiscale
  address             VARCHAR(255) NULL,
  city_id             SMALLINT UNSIGNED NULL,
  phone               VARCHAR(20)  NULL,
  email               VARCHAR(190) NULL,
  legal_rep_name      VARCHAR(160) NULL,   -- personne habilitée
  legal_rep_title     VARCHAR(120) NULL,
  logo_file_id        BIGINT UNSIGNED NULL,
  verification_status ENUM('NON_VERIFIEE','EN_COURS','VERIFIEE','REFUSEE') NOT NULL DEFAULT 'NON_VERIFIEE',
  verified_at         DATETIME     NULL,
  status              ENUM('ACTIVE','SUSPENDED','DEACTIVATED') NOT NULL DEFAULT 'ACTIVE',
  is_demo             TINYINT(1)   NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_businesses_uuid (uuid),
  KEY idx_businesses_owner (owner_user_id),
  KEY idx_businesses_kind_city (kind, city_id),
  CONSTRAINT fk_businesses_owner FOREIGN KEY (owner_user_id) REFERENCES users(id),
  CONSTRAINT fk_businesses_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_businesses_logo FOREIGN KEY (logo_file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_members (
  business_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  member_role   ENUM('OWNER','MANAGER','RECRUITER','FINANCE','VIEWER') NOT NULL DEFAULT 'RECRUITER',
  status        ENUM('INVITED','ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
  invited_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, user_id),
  KEY idx_bm_user (user_id),
  CONSTRAINT fk_bm_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_bm_invited_by FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Établissements multiples (agences, boutiques, sites)
CREATE TABLE business_sites (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id     BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(160) NOT NULL,
  address         VARCHAR(255) NULL,
  city_id         SMALLINT UNSIGNED NOT NULL,
  neighborhood_id INT UNSIGNED NULL,
  phone           VARCHAR(20)  NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sites_business (business_id),
  CONSTRAINT fk_sites_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_sites_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_sites_neighborhood FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (la clé étrangère vers payments est ajoutée après la création de payments)
CREATE TABLE business_subscriptions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id   BIGINT UNSIGNED NOT NULL,
  plan_id       SMALLINT UNSIGNED NOT NULL,
  status        ENUM('EN_ATTENTE_PAIEMENT','ACTIF','EXPIRE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE_PAIEMENT',
  billing_cycle ENUM('MENSUEL','ANNUEL','GRATUIT') NOT NULL DEFAULT 'MENSUEL',
  price         DECIMAL(15,2) NOT NULL DEFAULT 0,  -- prix figé au moment de la souscription
  starts_at     DATETIME NULL,
  ends_at       DATETIME NULL,
  payment_id    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bs_business_status (business_id, status),
  CONSTRAINT fk_bs_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_bs_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. CATALOGUE DES MÉTIERS (administrable sans toucher au code)
-- -----------------------------------------------------------------------------

CREATE TABLE mission_types (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,   -- HEURES, JOURNEE, PLUSIEURS_JOURS, WEEKEND, SAISONNIERE…
  name          VARCHAR(100) NOT NULL,
  description   VARCHAR(255) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mission_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_categories (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(80)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  icon          VARCHAR(60)  NULL,         -- nom d'icône (Bootstrap Icons)
  safety_notice TEXT         NULL,         -- règles affichées sur les annonces de la catégorie
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_subcategories (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id                 SMALLINT UNSIGNED NOT NULL,
  slug                        VARCHAR(100) NOT NULL,
  name                        VARCHAR(140) NOT NULL,
  description                 VARCHAR(255) NULL,
  -- Niveau de risque : LOW (standard), MEDIUM (vérif. renforcée),
  -- HIGH (travail dangereux / personnes vulnérables), REGULATED (profession réglementée)
  risk_level                  ENUM('LOW','MEDIUM','HIGH','REGULATED') NOT NULL DEFAULT 'LOW',
  min_age                     TINYINT UNSIGNED NOT NULL DEFAULT 18,
  required_verification_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
  requires_qualification      TINYINT(1)   NOT NULL DEFAULT 0, -- diplôme / autorisation vérifiée obligatoire
  involves_minors_or_vulnerable TINYINT(1) NOT NULL DEFAULT 0,
  is_remote_possible          TINYINT(1)   NOT NULL DEFAULT 0,
  is_active                   TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order                  SMALLINT     NOT NULL DEFAULT 0,
  created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subcategories_slug (slug),
  KEY idx_subcategories_cat (category_id, is_active, sort_order),
  CONSTRAINT fk_subcategories_cat FOREIGN KEY (category_id) REFERENCES job_categories(id),
  CONSTRAINT chk_sub_level CHECK (required_verification_level BETWEEN 0 AND 3),
  CONSTRAINT chk_sub_age CHECK (min_age BETWEEN 14 AND 25)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restriction facultative d'un métier à certaines villes (aucune ligne = toutes les villes)
CREATE TABLE job_subcategory_cities (
  subcategory_id INT UNSIGNED NOT NULL,
  city_id        SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (subcategory_id, city_id),
  CONSTRAINT fk_jsc_sub  FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id) ON DELETE CASCADE,
  CONSTRAINT fk_jsc_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restriction facultative d'un métier à certains types de mission (aucune ligne = tous)
CREATE TABLE job_subcategory_mission_types (
  subcategory_id  INT UNSIGNED NOT NULL,
  mission_type_id TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (subcategory_id, mission_type_id),
  CONSTRAINT fk_jsmt_sub  FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id) ON DELETE CASCADE,
  CONSTRAINT fk_jsmt_type FOREIGN KEY (mission_type_id) REFERENCES mission_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE skills (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subcategory_id INT UNSIGNED NULL,       -- NULL = compétence transversale
  slug           VARCHAR(100) NOT NULL,
  name           VARCHAR(120) NOT NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_skills_slug (slug),
  KEY idx_skills_sub (subcategory_id),
  CONSTRAINT fk_skills_sub FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. PROFIL PRESTATAIRE : métiers, compétences, zones, disponibilités, portfolio
-- -----------------------------------------------------------------------------

CREATE TABLE worker_trades (
  user_id        BIGINT UNSIGNED NOT NULL,
  subcategory_id INT UNSIGNED NOT NULL,
  is_primary     TINYINT(1) NOT NULL DEFAULT 0,
  hourly_rate    DECIMAL(15,2) NULL,
  daily_rate     DECIMAL(15,2) NULL,
  task_rate      DECIMAL(15,2) NULL,
  -- Habilitation pour métiers HIGH/REGULATED : validée par un modérateur
  qualification_status ENUM('NON_REQUISE','DECLAREE','VERIFIEE','REFUSEE') NOT NULL DEFAULT 'NON_REQUISE',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, subcategory_id),
  KEY idx_wt_sub (subcategory_id, qualification_status),
  CONSTRAINT fk_wt_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_wt_sub FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_skills (
  user_id       BIGINT UNSIGNED NOT NULL,
  skill_id      INT UNSIGNED NOT NULL,
  level         ENUM('DEBUTANT','INTERMEDIAIRE','CONFIRME','EXPERT') NOT NULL DEFAULT 'INTERMEDIAIRE',
  years         TINYINT UNSIGNED NULL,
  -- Distinction exigée : compétence DÉCLARÉE vs VÉRIFIÉE
  source        ENUM('DECLAREE','VERIFIEE') NOT NULL DEFAULT 'DECLAREE',
  verified_by   BIGINT UNSIGNED NULL,
  verified_at   DATETIME NULL,
  PRIMARY KEY (user_id, skill_id),
  KEY idx_us_skill (skill_id, source),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_us_skill FOREIGN KEY (skill_id) REFERENCES skills(id),
  CONSTRAINT fk_us_verified_by FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_experiences (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160) NOT NULL,
  organization  VARCHAR(160) NULL,
  city_id       SMALLINT UNSIGNED NULL,
  start_date    DATE NULL,
  end_date      DATE NULL,
  description   TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_we_user (user_id),
  CONSTRAINT fk_we_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_we_city FOREIGN KEY (city_id) REFERENCES cities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_qualifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  kind          ENUM('DIPLOME','ATTESTATION','CERTIFICAT','PERMIS','AUTORISATION','AUTRE') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  issuer        VARCHAR(200) NULL,
  obtained_at   DATE NULL,
  expires_at    DATE NULL,
  file_id       BIGINT UNSIGNED NULL,           -- stocké en PRIVATE
  status        ENUM('DECLAREE','EN_VERIFICATION','VERIFIEE','REFUSEE','EXPIREE') NOT NULL DEFAULT 'DECLAREE',
  verified_by   BIGINT UNSIGNED NULL,
  verified_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wq_user (user_id, status),
  CONSTRAINT fk_wq_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_wq_file FOREIGN KEY (file_id) REFERENCES stored_files(id),
  CONSTRAINT fk_wq_verified_by FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_service_areas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  city_id         SMALLINT UNSIGNED NOT NULL,
  neighborhood_id INT UNSIGNED NULL,           -- NULL = toute la ville
  PRIMARY KEY (id),
  UNIQUE KEY uq_wsa (user_id, city_id, neighborhood_id),
  KEY idx_wsa_city (city_id, neighborhood_id),
  CONSTRAINT fk_wsa_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_wsa_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_wsa_nb FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_availabilities (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  kind          ENUM('HEBDOMADAIRE','DISPONIBLE','INDISPONIBLE') NOT NULL,
  weekday       TINYINT UNSIGNED NULL,     -- 1 = lundi … 7 = dimanche (kind HEBDOMADAIRE)
  start_date    DATE NULL,                 -- plages ponctuelles
  end_date      DATE NULL,
  start_time    TIME NULL,
  end_time      TIME NULL,
  note          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_wa_user (user_id, kind),
  KEY idx_wa_dates (start_date, end_date),
  CONSTRAINT fk_wa_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT chk_wa_weekday CHECK (weekday IS NULL OR weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_portfolio (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160) NOT NULL,
  description   TEXT NULL,
  file_id       BIGINT UNSIGNED NOT NULL,     -- disque PUBLIC, image ré-encodée
  subcategory_id INT UNSIGNED NULL,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wp_user (user_id),
  CONSTRAINT fk_wp_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_wp_file FOREIGN KEY (file_id) REFERENCES stored_files(id),
  CONSTRAINT fk_wp_sub FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
  client_user_id BIGINT UNSIGNED NOT NULL,
  worker_user_id BIGINT UNSIGNED NOT NULL,
  note           VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (client_user_id, worker_user_id),
  KEY idx_fav_worker (worker_user_id),
  CONSTRAINT fk_fav_client FOREIGN KEY (client_user_id) REFERENCES users(id),
  CONSTRAINT fk_fav_worker FOREIGN KEY (worker_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. VÉRIFICATIONS D'IDENTITÉ ET DE QUALIFICATION
-- -----------------------------------------------------------------------------

CREATE TABLE verification_requests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NULL,
  business_id     BIGINT UNSIGNED NULL,
  target_level    TINYINT UNSIGNED NOT NULL,        -- niveau visé (1..3)
  kind            ENUM('IDENTITE','ADRESSE','QUALIFICATION','ENTREPRISE','AGE') NOT NULL,
  status          ENUM('SOUMISE','EN_EXAMEN','INFOS_DEMANDEES','APPROUVEE','REFUSEE','ANNULEE') NOT NULL DEFAULT 'SOUMISE',
  reviewer_id     BIGINT UNSIGNED NULL,
  decision_note   VARCHAR(500) NULL,
  submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_vr_status (status, submitted_at),
  KEY idx_vr_user (user_id),
  CONSTRAINT fk_vr_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_vr_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_vr_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id),
  CONSTRAINT chk_vr_target CHECK (user_id IS NOT NULL OR business_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verification_documents (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id      BIGINT UNSIGNED NOT NULL,
  doc_type        ENUM('CNI','PASSEPORT','CARTE_ETUDIANT','ACTE_NAISSANCE','CARTE_CONSULAIRE','PERMIS_CONDUIRE',
                       'DIPLOME','ATTESTATION','RCCM','NIF','AUTORISATION','AUTRE') NOT NULL,
  file_id         BIGINT UNSIGNED NOT NULL,         -- toujours disque PRIVATE
  document_number_last4 VARCHAR(8) NULL,            -- jamais le numéro complet
  status          ENUM('EN_ATTENTE','ACCEPTE','REFUSE') NOT NULL DEFAULT 'EN_ATTENTE',
  purge_after     DATE NULL,                        -- suppression du fichier après décision + délai
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vd_request (request_id),
  CONSTRAINT fk_vd_request FOREIGN KEY (request_id) REFERENCES verification_requests(id),
  CONSTRAINT fk_vd_file FOREIGN KEY (file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. ANNONCES DE MISSIONS, CANDIDATURES, INVITATIONS
-- -----------------------------------------------------------------------------

CREATE TABLE job_posts (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                  CHAR(36)     NOT NULL,
  reference             VARCHAR(20)  NOT NULL,           -- ex : JN-A-2026-000123
  client_user_id        BIGINT UNSIGNED NOT NULL,        -- auteur
  business_id           BIGINT UNSIGNED NULL,
  business_site_id      BIGINT UNSIGNED NULL,
  category_id           SMALLINT UNSIGNED NOT NULL,
  subcategory_id        INT UNSIGNED NOT NULL,
  mission_type_id       TINYINT UNSIGNED NOT NULL,
  title                 VARCHAR(160) NOT NULL,
  description           TEXT         NOT NULL,
  -- Localisation : la zone est publique, l'adresse précise ne l'est qu'après attribution
  city_id               SMALLINT UNSIGNED NULL,
  neighborhood_id       INT UNSIGNED NULL,
  address_private       VARCHAR(255) NULL,
  approx_latitude       DECIMAL(8,4) NULL,
  approx_longitude      DECIMAL(8,4) NULL,
  is_remote             TINYINT(1)   NOT NULL DEFAULT 0,
  -- Calendrier
  start_date            DATE         NOT NULL,
  end_date              DATE         NOT NULL,
  daily_start_time      TIME         NULL,
  daily_end_time        TIME         NULL,
  schedule_notes        VARCHAR(500) NULL,
  recurrence_rule       VARCHAR(120) NULL,             -- missions récurrentes (ex : WEEKLY;MO,WE)
  application_deadline  DATETIME     NOT NULL,
  -- Postes
  positions_total       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  positions_filled      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Rémunération (montant par travailleur)
  pay_unit              ENUM('HEURE','JOUR','TACHE','FORFAIT') NOT NULL,
  pay_rate              DECIMAL(15,2) NOT NULL,         -- montant unitaire proposé
  estimated_units       DECIMAL(10,2) NOT NULL DEFAULT 1, -- heures / jours / tâches
  estimated_amount      DECIMAL(15,2) NOT NULL,         -- = pay_rate × estimated_units (calculé serveur)
  is_negotiable         TINYINT(1)   NOT NULL DEFAULT 1,
  expenses_covered      VARCHAR(500) NULL,              -- transport, repas…
  equipment_required    VARCHAR(500) NULL,
  equipment_provided    VARCHAR(500) NULL,
  required_qualifications VARCHAR(500) NULL,
  payment_mode          ENUM('INTEGRE','DIRECT') NOT NULL DEFAULT 'DIRECT',
  payment_terms         VARCHAR(500) NULL,
  cancellation_terms    VARCHAR(1000) NOT NULL,
  execution_conditions  VARCHAR(1000) NULL,
  min_age               TINYINT UNSIGNED NOT NULL DEFAULT 18,
  required_verification_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
  contact_visibility    ENUM('MESSAGERIE','APRES_ATTRIBUTION') NOT NULL DEFAULT 'APRES_ATTRIBUTION',
  -- Cycle de vie
  status                ENUM('BROUILLON','EN_MODERATION','PUBLIEE','CANDIDATURES_OUVERTES','EN_NEGOCIATION',
                             'ATTRIBUEE','EN_COURS','EN_ATTENTE_DE_VALIDATION','TERMINEE','ANNULEE',
                             'EN_LITIGE','EXPIREE','REFUSEE') NOT NULL DEFAULT 'BROUILLON',
  moderation_flags      VARCHAR(255) NULL,              -- mots-clés suspects détectés
  moderated_by          BIGINT UNSIGNED NULL,
  moderated_at          DATETIME     NULL,
  rejection_reason      VARCHAR(500) NULL,
  is_sponsored          TINYINT(1)   NOT NULL DEFAULT 0,
  sponsored_until       DATETIME     NULL,
  views_count           INT UNSIGNED NOT NULL DEFAULT 0,
  republished_from_id   BIGINT UNSIGNED NULL,
  is_demo               TINYINT(1)   NOT NULL DEFAULT 0,
  published_at          DATETIME     NULL,
  closed_at             DATETIME     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jp_uuid (uuid),
  UNIQUE KEY uq_jp_reference (reference),
  KEY idx_jp_search (status, city_id, subcategory_id, start_date),
  KEY idx_jp_category (category_id, status),
  KEY idx_jp_client (client_user_id, status, created_at),
  KEY idx_jp_business (business_id, status),
  KEY idx_jp_deadline (status, application_deadline),
  KEY idx_jp_sponsored (is_sponsored, sponsored_until),
  FULLTEXT KEY ft_jp_text (title, description),
  CONSTRAINT fk_jp_client FOREIGN KEY (client_user_id) REFERENCES users(id),
  CONSTRAINT fk_jp_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_jp_site FOREIGN KEY (business_site_id) REFERENCES business_sites(id),
  CONSTRAINT fk_jp_category FOREIGN KEY (category_id) REFERENCES job_categories(id),
  CONSTRAINT fk_jp_sub FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id),
  CONSTRAINT fk_jp_type FOREIGN KEY (mission_type_id) REFERENCES mission_types(id),
  CONSTRAINT fk_jp_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_jp_nb FOREIGN KEY (neighborhood_id) REFERENCES neighborhoods(id),
  CONSTRAINT fk_jp_moderator FOREIGN KEY (moderated_by) REFERENCES users(id),
  CONSTRAINT fk_jp_republished FOREIGN KEY (republished_from_id) REFERENCES job_posts(id),
  CONSTRAINT chk_jp_dates CHECK (end_date >= start_date),
  CONSTRAINT chk_jp_positions CHECK (positions_total >= 1 AND positions_filled <= positions_total),
  CONSTRAINT chk_jp_amounts CHECK (pay_rate > 0 AND estimated_units > 0 AND estimated_amount > 0),
  CONSTRAINT chk_jp_location CHECK (is_remote = 1 OR city_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_post_skills (
  job_post_id   BIGINT UNSIGNED NOT NULL,
  skill_id      INT UNSIGNED NOT NULL,
  is_required   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (job_post_id, skill_id),
  KEY idx_jps_skill (skill_id),
  CONSTRAINT fk_jps_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_jps_skill FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_post_status_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_post_id   BIGINT UNSIGNED NOT NULL,
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  reason        VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_jpsh_post (job_post_id, created_at),
  CONSTRAINT fk_jpsh_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_jpsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_applications (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36) NOT NULL,
  job_post_id       BIGINT UNSIGNED NOT NULL,
  worker_user_id    BIGINT UNSIGNED NOT NULL,
  invitation_id     BIGINT UNSIGNED NULL,
  message           TEXT NULL,
  proposed_rate     DECIMAL(15,2) NULL,         -- si négociation autorisée
  availability_note VARCHAR(500) NULL,
  status            ENUM('SOUMISE','VUE','PRESELECTIONNEE','INFOS_DEMANDEES','EN_NEGOCIATION',
                         'ACCEPTEE','REFUSEE','RETIREE','EXPIREE') NOT NULL DEFAULT 'SOUMISE',
  decided_by        BIGINT UNSIGNED NULL,
  decided_at        DATETIME NULL,
  decision_note     VARCHAR(500) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ja_uuid (uuid),
  UNIQUE KEY uq_ja_post_worker (job_post_id, worker_user_id),   -- une candidature par annonce
  KEY idx_ja_worker (worker_user_id, status, created_at),
  KEY idx_ja_post_status (job_post_id, status),
  CONSTRAINT fk_ja_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_ja_worker FOREIGN KEY (worker_user_id) REFERENCES users(id),
  CONSTRAINT fk_ja_decided_by FOREIGN KEY (decided_by) REFERENCES users(id),
  CONSTRAINT chk_ja_rate CHECK (proposed_rate IS NULL OR proposed_rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_application_files (
  application_id BIGINT UNSIGNED NOT NULL,
  file_id        BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (application_id, file_id),
  CONSTRAINT fk_jaf_app FOREIGN KEY (application_id) REFERENCES job_applications(id),
  CONSTRAINT fk_jaf_file FOREIGN KEY (file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_invitations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_post_id     BIGINT UNSIGNED NOT NULL,
  client_user_id  BIGINT UNSIGNED NOT NULL,
  worker_user_id  BIGINT UNSIGNED NOT NULL,
  message         VARCHAR(1000) NULL,
  status          ENUM('ENVOYEE','VUE','ACCEPTEE','DECLINEE','EXPIREE','ANNULEE') NOT NULL DEFAULT 'ENVOYEE',
  responded_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ji_post_worker (job_post_id, worker_user_id),
  KEY idx_ji_worker (worker_user_id, status),
  CONSTRAINT fk_ji_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_ji_client FOREIGN KEY (client_user_id) REFERENCES users(id),
  CONSTRAINT fk_ji_worker FOREIGN KEY (worker_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE job_applications
  ADD CONSTRAINT fk_ja_invitation FOREIGN KEY (invitation_id) REFERENCES job_invitations(id);

-- -----------------------------------------------------------------------------
-- 8. NÉGOCIATION, CONTRATS, MISSIONS
-- -----------------------------------------------------------------------------

CREATE TABLE negotiations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) NOT NULL,
  job_post_id     BIGINT UNSIGNED NOT NULL,
  application_id  BIGINT UNSIGNED NULL,
  client_user_id  BIGINT UNSIGNED NOT NULL,
  worker_user_id  BIGINT UNSIGNED NOT NULL,
  status          ENUM('OUVERTE','ACCORD','ECHOUEE','ANNULEE','EXPIREE') NOT NULL DEFAULT 'OUVERTE',
  last_offer_id   BIGINT UNSIGNED NULL,
  agreed_rate     DECIMAL(15,2) NULL,
  agreed_units    DECIMAL(10,2) NULL,
  agreed_pay_unit ENUM('HEURE','JOUR','TACHE','FORFAIT') NULL,
  expires_at      DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_neg_uuid (uuid),
  UNIQUE KEY uq_neg_post_worker (job_post_id, worker_user_id),
  KEY idx_neg_client (client_user_id, status),
  KEY idx_neg_worker (worker_user_id, status),
  CONSTRAINT fk_neg_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_neg_app FOREIGN KEY (application_id) REFERENCES job_applications(id),
  CONSTRAINT fk_neg_client FOREIGN KEY (client_user_id) REFERENCES users(id),
  CONSTRAINT fk_neg_worker FOREIGN KEY (worker_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique complet et immuable des offres / contre-offres
CREATE TABLE negotiation_messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  negotiation_id  BIGINT UNSIGNED NOT NULL,
  author_user_id  BIGINT UNSIGNED NOT NULL,
  kind            ENUM('OFFRE','CONTRE_OFFRE','ACCEPTATION','REFUS','RETRAIT','NOTE') NOT NULL,
  pay_unit        ENUM('HEURE','JOUR','TACHE','FORFAIT') NULL,
  rate            DECIMAL(15,2) NULL,
  units           DECIMAL(10,2) NULL,
  total_amount    DECIMAL(15,2) NULL,         -- rate × units (calculé serveur)
  start_date      DATE NULL,
  end_date        DATE NULL,
  note            VARCHAR(1000) NULL,
  responds_to_id  BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nm_neg (negotiation_id, created_at),
  CONSTRAINT fk_nm_neg FOREIGN KEY (negotiation_id) REFERENCES negotiations(id),
  CONSTRAINT fk_nm_author FOREIGN KEY (author_user_id) REFERENCES users(id),
  CONSTRAINT fk_nm_responds FOREIGN KEY (responds_to_id) REFERENCES negotiation_messages(id),
  CONSTRAINT chk_nm_amounts CHECK ((rate IS NULL OR rate > 0) AND (units IS NULL OR units > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE negotiations
  ADD CONSTRAINT fk_neg_last_offer FOREIGN KEY (last_offer_id) REFERENCES negotiation_messages(id);

-- Une mission = un travailleur engagé sur une annonce (une annonce à 5 postes → 5 missions)
CREATE TABLE missions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)     NOT NULL,
  reference           VARCHAR(20)  NOT NULL,            -- JN-M-2026-000123
  job_post_id         BIGINT UNSIGNED NOT NULL,
  application_id      BIGINT UNSIGNED NULL,
  negotiation_id      BIGINT UNSIGNED NULL,
  client_user_id      BIGINT UNSIGNED NOT NULL,
  worker_user_id      BIGINT UNSIGNED NOT NULL,
  business_id         BIGINT UNSIGNED NULL,
  status              ENUM('EN_NEGOCIATION','ATTRIBUEE','EN_COURS','EN_ATTENTE_DE_VALIDATION',
                           'TERMINEE','ANNULEE','EN_LITIGE') NOT NULL DEFAULT 'EN_NEGOCIATION',
  status_before_dispute VARCHAR(30) NULL,
  service_amount      DECIMAL(15,2) NULL,              -- prix de la prestation convenu (figé par contrat)
  currency            CHAR(3)      NOT NULL DEFAULT 'XOF',
  payment_mode        ENUM('INTEGRE','DIRECT') NOT NULL DEFAULT 'DIRECT',
  scheduled_start_at  DATETIME     NULL,
  scheduled_end_at    DATETIME     NULL,
  started_at          DATETIME     NULL,
  worker_done_at      DATETIME     NULL,               -- le prestataire déclare la fin
  client_confirmed_at DATETIME     NULL,               -- le demandeur valide
  auto_confirm_at     DATETIME     NULL,               -- validation tacite après délai paramétrable
  completed_at        DATETIME     NULL,
  cancelled_at        DATETIME     NULL,
  cancelled_by        BIGINT UNSIGNED NULL,
  cancel_reason       VARCHAR(500) NULL,
  archived_at         DATETIME     NULL,
  is_demo             TINYINT(1)   NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_missions_uuid (uuid),
  UNIQUE KEY uq_missions_reference (reference),
  UNIQUE KEY uq_missions_post_worker (job_post_id, worker_user_id),
  KEY idx_missions_client (client_user_id, status),
  KEY idx_missions_worker (worker_user_id, status),
  KEY idx_missions_status_dates (status, scheduled_start_at),
  KEY idx_missions_autoconfirm (status, auto_confirm_at),
  CONSTRAINT fk_missions_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_missions_app FOREIGN KEY (application_id) REFERENCES job_applications(id),
  CONSTRAINT fk_missions_neg FOREIGN KEY (negotiation_id) REFERENCES negotiations(id),
  CONSTRAINT fk_missions_client FOREIGN KEY (client_user_id) REFERENCES users(id),
  CONSTRAINT fk_missions_worker FOREIGN KEY (worker_user_id) REFERENCES users(id),
  CONSTRAINT fk_missions_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_missions_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT chk_missions_parties CHECK (client_user_id <> worker_user_id),
  CONSTRAINT chk_missions_amount CHECK (service_amount IS NULL OR service_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mission_status_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id    BIGINT UNSIGNED NOT NULL,
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,       -- NULL = système (tâche planifiée)
  reason        VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msh_mission (mission_id, created_at),
  CONSTRAINT fk_msh_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_msh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de commission (la plus spécifique et prioritaire s'applique)
CREATE TABLE commission_rules (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(160) NOT NULL,
  scope             ENUM('GLOBAL','CATEGORY','SUBCATEGORY','BUSINESS','CAMPAIGN') NOT NULL DEFAULT 'GLOBAL',
  category_id       SMALLINT UNSIGNED NULL,
  subcategory_id    INT UNSIGNED NULL,
  business_id       BIGINT UNSIGNED NULL,
  campaign_code     VARCHAR(40) NULL,
  -- Taux en pourcentage (10.00 = 10 %) ; le payeur est déduit des taux non nuls
  client_rate       DECIMAL(5,2) NOT NULL DEFAULT 0.00,     -- ajouté au prix, payé par le demandeur
  worker_rate       DECIMAL(5,2) NOT NULL DEFAULT 10.00,    -- déduit du prix, à la charge du prestataire
  fixed_fee         DECIMAL(15,2) NOT NULL DEFAULT 0.00,    -- frais fixes par transaction
  fixed_fee_payer   ENUM('CLIENT','WORKER') NOT NULL DEFAULT 'CLIENT',
  min_commission    DECIMAL(15,2) NULL,
  max_commission    DECIMAL(15,2) NULL,
  priority          SMALLINT NOT NULL DEFAULT 0,
  valid_from        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valid_to          DATETIME NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cr_lookup (is_active, scope, valid_from, valid_to),
  CONSTRAINT fk_cr_category FOREIGN KEY (category_id) REFERENCES job_categories(id),
  CONSTRAINT fk_cr_sub FOREIGN KEY (subcategory_id) REFERENCES job_subcategories(id),
  CONSTRAINT fk_cr_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_cr_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_cr_rates CHECK (client_rate BETWEEN 0 AND 50 AND worker_rate BETWEEN 0 AND 50 AND fixed_fee >= 0),
  CONSTRAINT chk_cr_minmax CHECK (min_commission IS NULL OR max_commission IS NULL OR min_commission <= max_commission),
  CONSTRAINT chk_cr_scope CHECK (
    (scope = 'GLOBAL') OR
    (scope = 'CATEGORY' AND category_id IS NOT NULL) OR
    (scope = 'SUBCATEGORY' AND subcategory_id IS NOT NULL) OR
    (scope = 'BUSINESS' AND business_id IS NOT NULL) OR
    (scope = 'CAMPAIGN' AND campaign_code IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Récapitulatif contractuel : figé (snapshot + empreinte SHA-256) avant signature
CREATE TABLE contracts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)    NOT NULL,
  reference           VARCHAR(20) NOT NULL,              -- JN-C-2026-000123
  mission_id          BIGINT UNSIGNED NOT NULL,
  version             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status              ENUM('EN_ATTENTE_SIGNATURES','ACCEPTE','REMPLACE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE_SIGNATURES',
  title               VARCHAR(160) NOT NULL,
  description         TEXT        NOT NULL,
  start_at            DATETIME    NOT NULL,
  end_at              DATETIME    NOT NULL,
  schedule_text       VARCHAR(500) NULL,
  location_text       VARCHAR(255) NULL,
  pay_unit            ENUM('HEURE','JOUR','TACHE','FORFAIT') NOT NULL,
  rate                DECIMAL(15,2) NOT NULL,
  units               DECIMAL(10,2) NOT NULL,
  -- Montants figés au moment de la génération du contrat
  service_amount      DECIMAL(15,2) NOT NULL,            -- prix de la prestation
  commission_rule_id  BIGINT UNSIGNED NULL,
  client_rate         DECIMAL(5,2)  NOT NULL,
  worker_rate         DECIMAL(5,2)  NOT NULL,
  fixed_fee           DECIMAL(15,2) NOT NULL DEFAULT 0,
  fixed_fee_payer     ENUM('CLIENT','WORKER') NOT NULL DEFAULT 'CLIENT',
  client_commission   DECIMAL(15,2) NOT NULL,            -- part payée en plus par le demandeur
  worker_commission   DECIMAL(15,2) NOT NULL,            -- part retenue sur le prestataire
  total_commission    DECIMAL(15,2) NOT NULL,
  client_total        DECIMAL(15,2) NOT NULL,            -- = service_amount + client_commission
  worker_net          DECIMAL(15,2) NOT NULL,            -- = service_amount − worker_commission
  commission_included_in_price TINYINT(1) NOT NULL,      -- 1 si la commission est déduite du prix
  currency            CHAR(3)       NOT NULL DEFAULT 'XOF',
  payment_mode        ENUM('INTEGRE','DIRECT') NOT NULL,
  expenses_text       VARCHAR(500)  NULL,
  cancellation_terms  VARCHAR(1000) NOT NULL,
  terms_version       VARCHAR(20)   NOT NULL,            -- version des CGU applicables
  snapshot_json       MEDIUMTEXT    NOT NULL,            -- contenu complet signé
  content_hash        CHAR(64)      NOT NULL,            -- SHA-256 du snapshot
  accepted_at         DATETIME      NULL,                -- quand les 2 parties ont accepté
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contracts_uuid (uuid),
  UNIQUE KEY uq_contracts_reference (reference),
  UNIQUE KEY uq_contracts_mission_version (mission_id, version),
  CONSTRAINT fk_contracts_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_contracts_rule FOREIGN KEY (commission_rule_id) REFERENCES commission_rules(id),
  CONSTRAINT chk_contracts_math CHECK (
    service_amount > 0 AND client_commission >= 0 AND worker_commission >= 0
    AND total_commission = client_commission + worker_commission
    AND client_total = service_amount + client_commission
    AND worker_net = service_amount - worker_commission
    AND worker_net >= 0),
  CONSTRAINT chk_contracts_dates CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contract_acceptances (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contract_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  party         ENUM('CLIENT','WORKER') NOT NULL,
  content_hash  CHAR(64)    NOT NULL,      -- doit égaler contracts.content_hash
  accepted_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ca_contract_party (contract_id, party),
  CONSTRAINT fk_ca_contract FOREIGN KEY (contract_id) REFERENCES contracts(id),
  CONSTRAINT fk_ca_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. MESSAGERIE
-- -----------------------------------------------------------------------------

CREATE TABLE conversations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36) NOT NULL,
  type            ENUM('DIRECTE','ANNONCE','MISSION','LITIGE','SUPPORT') NOT NULL DEFAULT 'DIRECTE',
  job_post_id     BIGINT UNSIGNED NULL,
  mission_id      BIGINT UNSIGNED NULL,
  subject         VARCHAR(160) NULL,
  created_by      BIGINT UNSIGNED NOT NULL,
  last_message_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  is_locked       TINYINT(1) NOT NULL DEFAULT 0,      -- verrouillée (litige, suspension)
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_uuid (uuid),
  KEY idx_conv_post (job_post_id),
  KEY idx_conv_mission (mission_id),
  CONSTRAINT fk_conv_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_conv_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_members (
  conversation_id      BIGINT UNSIGNED NOT NULL,
  user_id              BIGINT UNSIGNED NOT NULL,
  member_role          ENUM('PARTICIPANT','STAFF') NOT NULL DEFAULT 'PARTICIPANT',
  last_read_message_id BIGINT UNSIGNED NULL,
  last_read_at         DATETIME NULL,
  is_muted             TINYINT(1) NOT NULL DEFAULT 0,
  joined_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at              DATETIME NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_cm_user (user_id, conversation_id),
  CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_user_id  BIGINT UNSIGNED NULL,           -- NULL = message système
  kind            ENUM('TEXTE','PIECE_JOINTE','SYSTEME','OFFRE') NOT NULL DEFAULT 'TEXTE',
  body            TEXT NULL,
  client_msg_id   CHAR(36) NULL,                  -- anti-doublon (envoi hors-ligne / ré-essai)
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  edited_at       DATETIME NULL,
  deleted_at      DATETIME NULL,                  -- masquage, le contenu reste pour les litiges
  PRIMARY KEY (id),
  UNIQUE KEY uq_messages_client_id (sender_user_id, client_msg_id),
  KEY idx_messages_conv (conversation_id, id),   -- pagination par curseur
  CONSTRAINT fk_messages_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id),
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE conversations
  ADD CONSTRAINT fk_conv_last_msg FOREIGN KEY (last_message_id) REFERENCES messages(id);

CREATE TABLE message_attachments (
  message_id    BIGINT UNSIGNED NOT NULL,
  file_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (message_id, file_id),
  CONSTRAINT fk_matt_message FOREIGN KEY (message_id) REFERENCES messages(id),
  CONSTRAINT fk_matt_file FOREIGN KEY (file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. PAIEMENTS
-- -----------------------------------------------------------------------------

-- Passerelles (opérateurs) : état de configuration explicite, jamais de secret ici
CREATE TABLE payment_gateways (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,          -- MANUAL, CASH, BANK_TRANSFER, MOMO_X, CARD_PSP…
  name          VARCHAR(120) NOT NULL,
  driver_class  VARCHAR(190) NOT NULL,          -- classe PHP implémentant PaymentGatewayInterface
  environment   ENUM('INDISPONIBLE','A_CONFIGURER','TEST','PRODUCTION') NOT NULL DEFAULT 'A_CONFIGURER',
  supports_webhook TINYINT(1) NOT NULL DEFAULT 0,
  supports_refund  TINYINT(1) NOT NULL DEFAULT 0,
  supports_payout  TINYINT(1) NOT NULL DEFAULT 0,
  holds_funds      TINYINT(1) NOT NULL DEFAULT 0, -- 1 = mode intégré (fonds encaissés par le PSP)
  is_active     TINYINT(1)   NOT NULL DEFAULT 0,
  notes         VARCHAR(500) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gateways_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  kind          ENUM('MOBILE_MONEY','CARTE','VIREMENT','ESPECES','PORTEFEUILLE') NOT NULL,
  gateway_id    SMALLINT UNSIGNED NOT NULL,
  verification  ENUM('AUTOMATIQUE','MANUELLE') NOT NULL,
  min_amount    DECIMAL(15,2) NULL,
  max_amount    DECIMAL(15,2) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm_code (code),
  CONSTRAINT fk_pm_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)    NOT NULL,
  reference           VARCHAR(24) NOT NULL,         -- JN-P-2026-000123 (communiquée au PSP)
  purpose             ENUM('MISSION','ABONNEMENT','SPONSORING','COMMISSION_DIRECTE') NOT NULL DEFAULT 'MISSION',
  mission_id          BIGINT UNSIGNED NULL,
  contract_id         BIGINT UNSIGNED NULL,
  payer_user_id       BIGINT UNSIGNED NOT NULL,
  payee_user_id       BIGINT UNSIGNED NULL,         -- prestataire (missions)
  payment_method_id   SMALLINT UNSIGNED NOT NULL,
  gateway_id          SMALLINT UNSIGNED NOT NULL,
  mode                ENUM('INTEGRE','DIRECT') NOT NULL,
  verification_type   ENUM('AUTOMATIQUE','MANUELLE') NOT NULL,
  -- Montants attendus (figés depuis le contrat, jamais depuis le navigateur)
  amount              DECIMAL(15,2) NOT NULL,       -- total à payer par le demandeur
  service_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  client_commission   DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency            CHAR(3)     NOT NULL DEFAULT 'XOF',
  confirmed_amount    DECIMAL(15,2) NULL,           -- montant confirmé par le PSP
  gateway_fee         DECIMAL(15,2) NOT NULL DEFAULT 0,
  refunded_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  status              ENUM('INITIATED','PENDING','CONFIRMED','FAILED','CANCELLED',
                           'REFUND_PENDING','PARTIALLY_REFUNDED','REFUNDED','DISPUTED') NOT NULL DEFAULT 'INITIATED',
  gateway_reference   VARCHAR(120) NULL,            -- référence de l'opérateur
  payer_account_masked VARCHAR(40) NULL,            -- ex : +227 9x xx xx 12 (jamais de code secret)
  proof_file_id       BIGINT UNSIGNED NULL,         -- preuve (mode direct / espèces / virement)
  verified_by         BIGINT UNSIGNED NULL,         -- vérification manuelle
  verified_at         DATETIME NULL,
  idempotency_key     VARCHAR(80) NOT NULL,
  initiated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at        DATETIME NULL,
  failed_at           DATETIME NULL,
  failure_reason      VARCHAR(255) NULL,
  expires_at          DATETIME NULL,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_uuid (uuid),
  UNIQUE KEY uq_payments_reference (reference),
  UNIQUE KEY uq_payments_idem (idempotency_key),
  UNIQUE KEY uq_payments_gateway_ref (gateway_id, gateway_reference),
  KEY idx_payments_mission (mission_id, status),
  KEY idx_payments_payer (payer_user_id, status, initiated_at),
  KEY idx_payments_payee (payee_user_id, status),
  KEY idx_payments_status_date (status, confirmed_at),
  CONSTRAINT fk_payments_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_payments_contract FOREIGN KEY (contract_id) REFERENCES contracts(id),
  CONSTRAINT fk_payments_payer FOREIGN KEY (payer_user_id) REFERENCES users(id),
  CONSTRAINT fk_payments_payee FOREIGN KEY (payee_user_id) REFERENCES users(id),
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_payments_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
  CONSTRAINT fk_payments_proof FOREIGN KEY (proof_file_id) REFERENCES stored_files(id),
  CONSTRAINT fk_payments_verified_by FOREIGN KEY (verified_by) REFERENCES users(id),
  CONSTRAINT chk_payments_amounts CHECK (amount > 0 AND refunded_amount >= 0 AND refunded_amount <= amount
                                         AND gateway_fee >= 0 AND service_amount >= 0 AND client_commission >= 0),
  CONSTRAINT chk_payments_mission CHECK (purpose <> 'MISSION' OR mission_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE business_subscriptions
  ADD CONSTRAINT fk_bs_payment FOREIGN KEY (payment_id) REFERENCES payments(id);

CREATE TABLE payment_status_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id    BIGINT UNSIGNED NOT NULL,
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NOT NULL,
  source        ENUM('SYSTEME','WEBHOOK','ADMIN','UTILISATEUR','RAPPROCHEMENT') NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  webhook_event_id BIGINT UNSIGNED NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_psh_payment (payment_id, created_at),
  CONSTRAINT fk_psh_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_psh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_attempts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id        BIGINT UNSIGNED NOT NULL,
  attempt_no        SMALLINT UNSIGNED NOT NULL,
  request_redacted  TEXT NULL,          -- requête envoyée au PSP, données sensibles masquées
  response_redacted TEXT NULL,
  http_status       SMALLINT NULL,
  result            ENUM('ENVOYE','ACCEPTE','REFUSE','ERREUR','TIMEOUT') NOT NULL,
  error_message     VARCHAR(500) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pa_payment_attempt (payment_id, attempt_no),
  CONSTRAINT fk_pa_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal brut des notifications serveur-à-serveur (anti-rejeu par clé unique)
CREATE TABLE payment_webhook_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gateway_id        SMALLINT UNSIGNED NOT NULL,
  event_id          VARCHAR(160) NOT NULL,          -- identifiant d'événement de l'opérateur
  event_type        VARCHAR(80)  NULL,
  gateway_reference VARCHAR(120) NULL,
  payload           MEDIUMTEXT   NOT NULL,
  headers_redacted  TEXT         NULL,
  signature_valid   TINYINT(1)   NOT NULL DEFAULT 0,
  source_ip         VARCHAR(45)  NULL,
  processing_status ENUM('RECU','TRAITE','IGNORE_DOUBLON','REJETE','ERREUR') NOT NULL DEFAULT 'RECU',
  error_message     VARCHAR(500) NULL,
  payment_id        BIGINT UNSIGNED NULL,
  received_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  processed_at      DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pwe_gateway_event (gateway_id, event_id),
  KEY idx_pwe_ref (gateway_id, gateway_reference),
  KEY idx_pwe_status (processing_status, received_at),
  CONSTRAINT fk_pwe_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
  CONSTRAINT fk_pwe_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_status_history
  ADD CONSTRAINT fk_psh_webhook FOREIGN KEY (webhook_event_id) REFERENCES payment_webhook_events(id);

-- -----------------------------------------------------------------------------
-- 11. COMMISSIONS, VERSEMENTS, REMBOURSEMENTS
-- -----------------------------------------------------------------------------

CREATE TABLE commission_records (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id          BIGINT UNSIGNED NOT NULL,
  payment_id          BIGINT UNSIGNED NOT NULL,
  contract_id         BIGINT UNSIGNED NOT NULL,
  rule_id             BIGINT UNSIGNED NULL,
  base_amount         DECIMAL(15,2) NOT NULL,       -- prix de la prestation
  client_rate         DECIMAL(5,2)  NOT NULL,
  worker_rate         DECIMAL(5,2)  NOT NULL,
  fixed_fee           DECIMAL(15,2) NOT NULL DEFAULT 0,
  client_commission   DECIMAL(15,2) NOT NULL,
  worker_commission   DECIMAL(15,2) NOT NULL,
  total_commission    DECIMAL(15,2) NOT NULL,
  reversed_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  -- DIFFEREE : paiement confirmé, mission non validée (produit constaté d'avance)
  -- ACQUISE  : mission terminée → revenu de la plateforme
  -- A_RECEVOIR / ENCAISSEE : mode direct, commission due par le prestataire
  status              ENUM('DIFFEREE','ACQUISE','A_RECEVOIR','ENCAISSEE','PARTIELLEMENT_ANNULEE','ANNULEE') NOT NULL,
  earned_at           DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cr_payment (payment_id),           -- une commission par paiement (idempotence)
  KEY idx_crec_mission (mission_id),
  KEY idx_crec_status (status, earned_at),
  CONSTRAINT fk_crec_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_crec_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_crec_contract FOREIGN KEY (contract_id) REFERENCES contracts(id),
  CONSTRAINT fk_crec_rule FOREIGN KEY (rule_id) REFERENCES commission_rules(id),
  CONSTRAINT chk_crec_math CHECK (total_commission = client_commission + worker_commission
                                  AND reversed_amount >= 0 AND reversed_amount <= total_commission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payouts (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)    NOT NULL,
  reference           VARCHAR(24) NOT NULL,          -- JN-V-2026-000123
  worker_user_id      BIGINT UNSIGNED NOT NULL,
  amount              DECIMAL(15,2) NOT NULL,
  currency            CHAR(3)     NOT NULL DEFAULT 'XOF',
  payment_method_id   SMALLINT UNSIGNED NOT NULL,
  gateway_id          SMALLINT UNSIGNED NOT NULL,
  destination_masked  VARCHAR(60) NULL,
  status              ENUM('EN_ATTENTE','APPROUVE','EN_COURS','PAYE','ECHOUE','ANNULE') NOT NULL DEFAULT 'EN_ATTENTE',
  gateway_reference   VARCHAR(120) NULL,
  gateway_fee         DECIMAL(15,2) NOT NULL DEFAULT 0,
  proof_file_id       BIGINT UNSIGNED NULL,
  requested_by        BIGINT UNSIGNED NULL,
  approved_by         BIGINT UNSIGNED NULL,          -- principe des 4 yeux : ≠ requested_by
  approved_at         DATETIME NULL,
  paid_at             DATETIME NULL,
  failure_reason      VARCHAR(255) NULL,
  idempotency_key     VARCHAR(80) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payouts_uuid (uuid),
  UNIQUE KEY uq_payouts_reference (reference),
  UNIQUE KEY uq_payouts_idem (idempotency_key),
  UNIQUE KEY uq_payouts_gateway_ref (gateway_id, gateway_reference),
  KEY idx_payouts_worker (worker_user_id, status),
  KEY idx_payouts_status (status, created_at),
  CONSTRAINT fk_payouts_worker FOREIGN KEY (worker_user_id) REFERENCES users(id),
  CONSTRAINT fk_payouts_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_payouts_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
  CONSTRAINT fk_payouts_proof FOREIGN KEY (proof_file_id) REFERENCES stored_files(id),
  CONSTRAINT fk_payouts_requested FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_payouts_approved FOREIGN KEY (approved_by) REFERENCES users(id),
  CONSTRAINT chk_payouts_amount CHECK (amount > 0 AND gateway_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payout_id     BIGINT UNSIGNED NOT NULL,
  mission_id    BIGINT UNSIGNED NOT NULL,
  payment_id    BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pi_payout_payment (payout_id, payment_id),
  KEY idx_pi_mission (mission_id),
  KEY idx_pi_payment (payment_id),
  CONSTRAINT fk_pi_payout FOREIGN KEY (payout_id) REFERENCES payouts(id),
  CONSTRAINT fk_pi_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_pi_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT chk_pi_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)    NOT NULL,
  reference           VARCHAR(24) NOT NULL,          -- JN-R-2026-000123
  payment_id          BIGINT UNSIGNED NOT NULL,
  mission_id          BIGINT UNSIGNED NULL,
  dispute_id          BIGINT UNSIGNED NULL,
  requested_by        BIGINT UNSIGNED NOT NULL,
  reason              ENUM('ANNULATION_CLIENT','ANNULATION_PRESTATAIRE','MISSION_NON_REALISEE','LITIGE',
                           'DOUBLE_PAIEMENT','ERREUR_MONTANT','AUTRE') NOT NULL,
  description         VARCHAR(1000) NULL,
  requested_amount    DECIMAL(15,2) NOT NULL,
  approved_amount     DECIMAL(15,2) NULL,
  -- Ventilation du remboursement (somme = approved_amount)
  from_worker_share   DECIMAL(15,2) NOT NULL DEFAULT 0,   -- part reprise sur le dû prestataire
  from_commission     DECIMAL(15,2) NOT NULL DEFAULT 0,   -- commission annulée
  from_platform       DECIMAL(15,2) NOT NULL DEFAULT 0,   -- geste commercial à la charge de la plateforme
  status              ENUM('DEMANDE','APPROUVE','REFUSE','EN_COURS','EFFECTUE','ECHOUE','ANNULE') NOT NULL DEFAULT 'DEMANDE',
  decided_by          BIGINT UNSIGNED NULL,
  decided_at          DATETIME NULL,
  decision_note       VARCHAR(1000) NULL,
  gateway_reference   VARCHAR(120) NULL,
  processed_at        DATETIME NULL,
  idempotency_key     VARCHAR(80) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_uuid (uuid),
  UNIQUE KEY uq_refunds_reference (reference),
  UNIQUE KEY uq_refunds_idem (idempotency_key),
  KEY idx_refunds_payment (payment_id, status),
  KEY idx_refunds_status (status, created_at),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_refunds_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_refunds_requested FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_refunds_decided FOREIGN KEY (decided_by) REFERENCES users(id),
  CONSTRAINT chk_refunds_amounts CHECK (requested_amount > 0
       AND (approved_amount IS NULL OR approved_amount = from_worker_share + from_commission + from_platform)
       AND from_worker_share >= 0 AND from_commission >= 0 AND from_platform >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. REGISTRE FINANCIER
--   a) transactions : registre opérationnel lisible (une ligne par opération métier)
--   b) grand livre en partie double : journaux + écritures équilibrées (Σ débit = Σ crédit)
-- -----------------------------------------------------------------------------

CREATE TABLE transactions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                CHAR(36)    NOT NULL,
  reference           VARCHAR(24) NOT NULL,           -- JN-T-2026-000123
  type                ENUM('PAIEMENT_MISSION','COMMISSION','VERSEMENT_PRESTATAIRE','REMBOURSEMENT','ANNULATION',
                           'AJUSTEMENT','FRAIS_TRANSACTION','CONTESTATION','PAIEMENT_ECHOUE',
                           'ABONNEMENT','SPONSORING','ENCAISSEMENT_COMMISSION') NOT NULL,
  direction           ENUM('ENTRANT','SORTANT','INTERNE') NOT NULL,
  user_id             BIGINT UNSIGNED NULL,           -- utilisateur concerné
  mission_id          BIGINT UNSIGNED NULL,
  payment_id          BIGINT UNSIGNED NULL,
  payout_id           BIGINT UNSIGNED NULL,
  refund_id           BIGINT UNSIGNED NULL,
  commission_record_id BIGINT UNSIGNED NULL,
  journal_id          BIGINT UNSIGNED NULL,           -- écritures comptables liées
  amount              DECIMAL(15,2) NOT NULL,         -- montant brut de l'opération
  commission_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_amount          DECIMAL(15,2) NOT NULL,
  currency            CHAR(3)     NOT NULL DEFAULT 'XOF',
  payment_method_id   SMALLINT UNSIGNED NULL,
  status              ENUM('EN_ATTENTE','CONFIRMEE','ECHOUEE','ANNULEE','CONTESTEE') NOT NULL,
  gateway_reference   VARCHAR(120) NULL,
  reverses_transaction_id BIGINT UNSIGNED NULL,       -- écriture corrective
  description         VARCHAR(500) NULL,
  idempotency_key     VARCHAR(100) NOT NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_uuid (uuid),
  UNIQUE KEY uq_tx_reference (reference),
  UNIQUE KEY uq_tx_idem (idempotency_key),
  KEY idx_tx_user (user_id, created_at),
  KEY idx_tx_mission (mission_id),
  KEY idx_tx_payment (payment_id),
  KEY idx_tx_type_status_date (type, status, created_at),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tx_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_tx_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_tx_payout FOREIGN KEY (payout_id) REFERENCES payouts(id),
  CONSTRAINT fk_tx_refund FOREIGN KEY (refund_id) REFERENCES refunds(id),
  CONSTRAINT fk_tx_commission FOREIGN KEY (commission_record_id) REFERENCES commission_records(id),
  CONSTRAINT fk_tx_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_tx_reverses FOREIGN KEY (reverses_transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_tx_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_tx_amounts CHECK (amount >= 0 AND commission_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_status_history (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  from_status     VARCHAR(20) NULL,
  to_status       VARCHAR(20) NOT NULL,
  actor_user_id   BIGINT UNSIGNED NULL,
  note            VARCHAR(500) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_tsh_tx (transaction_id),
  CONSTRAINT fk_tsh_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_tsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_ledger_accounts (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(10)  NOT NULL,     -- plan de comptes interne (voir docs/03)
  name            VARCHAR(160) NOT NULL,
  type            ENUM('ACTIF','PASSIF','PRODUIT','CHARGE','CAPITAUX') NOT NULL,
  normal_balance  ENUM('DEBIT','CREDIT') NOT NULL,
  has_user_subledger TINYINT(1) NOT NULL DEFAULT 0,   -- suivi par utilisateur (ex : dû prestataire)
  is_off_platform TINYINT(1) NOT NULL DEFAULT 0,      -- compte mémo (mode direct, hors fonds plateforme)
  description     VARCHAR(500) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fla_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_ledger_journals (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)    NOT NULL,
  reference       VARCHAR(24) NOT NULL,             -- JN-J-2026-000123
  event_type      VARCHAR(60) NOT NULL,             -- PAYMENT_CONFIRMED, MISSION_COMPLETED, PAYOUT_PAID…
  source_type     VARCHAR(40) NOT NULL,             -- payment, payout, refund, adjustment…
  source_id       BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,            -- ex : payment:123:confirmed
  description     VARCHAR(500) NULL,
  reverses_journal_id BIGINT UNSIGNED NULL,         -- contre-passation
  created_by      BIGINT UNSIGNED NULL,
  posted_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_flj_uuid (uuid),
  UNIQUE KEY uq_flj_reference (reference),
  UNIQUE KEY uq_flj_idem (idempotency_key),
  KEY idx_flj_source (source_type, source_id),
  KEY idx_flj_posted (posted_at),
  CONSTRAINT fk_flj_reverses FOREIGN KEY (reverses_journal_id) REFERENCES financial_ledger_journals(id),
  CONSTRAINT fk_flj_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD CONSTRAINT fk_tx_journal FOREIGN KEY (journal_id) REFERENCES financial_ledger_journals(id);

CREATE TABLE financial_ledger_entries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id      BIGINT UNSIGNED NOT NULL,
  account_id      SMALLINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,          -- sous-compte (prestataire, demandeur)
  mission_id      BIGINT UNSIGNED NULL,
  gateway_id      SMALLINT UNSIGNED NULL,        -- compte de trésorerie par opérateur
  debit           DECIMAL(15,2) NOT NULL DEFAULT 0,
  credit          DECIMAL(15,2) NOT NULL DEFAULT 0,
  currency        CHAR(3) NOT NULL DEFAULT 'XOF',
  memo            VARCHAR(255) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_fle_journal (journal_id),
  KEY idx_fle_account_date (account_id, created_at),
  KEY idx_fle_account_user (account_id, user_id),
  KEY idx_fle_mission (mission_id),
  CONSTRAINT fk_fle_journal FOREIGN KEY (journal_id) REFERENCES financial_ledger_journals(id),
  CONSTRAINT fk_fle_account FOREIGN KEY (account_id) REFERENCES financial_ledger_accounts(id),
  CONSTRAINT fk_fle_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_fle_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_fle_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
  CONSTRAINT chk_fle_sides CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. FACTURES ET REÇUS (numérotation séquentielle sans trou)
-- -----------------------------------------------------------------------------

CREATE TABLE document_sequences (
  name          VARCHAR(30) NOT NULL,   -- RECEIPT-2026, INVOICE-2026, MISSION-2026…
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  number        VARCHAR(30) NOT NULL,           -- JN-RC-2026-000001
  payment_id    BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  amount        DECIMAL(15,2) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'XOF',
  details_json  TEXT NOT NULL,                  -- ventilation : prix, commission, net, moyen
  file_id       BIGINT UNSIGNED NULL,
  issued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipts_number (number),
  UNIQUE KEY uq_receipts_payment (payment_id),
  KEY idx_receipts_user (user_id, issued_at),
  CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_receipts_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_receipts_file FOREIGN KEY (file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  number        VARCHAR(30) NOT NULL,           -- JN-FA-2026-000001
  type          ENUM('COMMISSION','ABONNEMENT','SPONSORING','AVOIR') NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  business_id   BIGINT UNSIGNED NULL,
  mission_id    BIGINT UNSIGNED NULL,
  payment_id    BIGINT UNSIGNED NULL,
  credits_invoice_id BIGINT UNSIGNED NULL,      -- pour un avoir
  amount_ht     DECIMAL(15,2) NOT NULL,
  tax_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0, -- TVA à paramétrer selon statut fiscal
  tax_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_ttc    DECIMAL(15,2) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'XOF',
  status        ENUM('EMISE','PAYEE','ANNULEE_PAR_AVOIR') NOT NULL DEFAULT 'EMISE',
  file_id       BIGINT UNSIGNED NULL,
  issued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_number (number),
  KEY idx_invoices_user (user_id, issued_at),
  CONSTRAINT fk_invoices_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_invoices_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_invoices_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_invoices_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_invoices_credits FOREIGN KEY (credits_invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_invoices_file FOREIGN KEY (file_id) REFERENCES stored_files(id),
  CONSTRAINT chk_invoices_math CHECK (amount_ttc = amount_ht + tax_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annonces sponsorisées
CREATE TABLE sponsored_listings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_post_id   BIGINT UNSIGNED NOT NULL,
  starts_at     DATETIME NOT NULL,
  ends_at       DATETIME NOT NULL,
  price         DECIMAL(15,2) NOT NULL,
  payment_id    BIGINT UNSIGNED NULL,
  status        ENUM('EN_ATTENTE_PAIEMENT','ACTIVE','TERMINEE','ANNULEE') NOT NULL DEFAULT 'EN_ATTENTE_PAIEMENT',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sl_post (job_post_id, status),
  CONSTRAINT fk_sl_post FOREIGN KEY (job_post_id) REFERENCES job_posts(id),
  CONSTRAINT fk_sl_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT chk_sl_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. LITIGES, AVIS, SIGNALEMENTS
-- -----------------------------------------------------------------------------

CREATE TABLE disputes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid            CHAR(36)    NOT NULL,
  reference       VARCHAR(24) NOT NULL,              -- JN-L-2026-000123
  mission_id      BIGINT UNSIGNED NOT NULL,
  opened_by       BIGINT UNSIGNED NOT NULL,
  respondent_id   BIGINT UNSIGNED NOT NULL,
  reason          ENUM('TRAVAIL_NON_REALISE','TRAVAIL_INCOMPLET','RETARD','NON_PAIEMENT','DESACCORD_PRIX',
                       'COMPORTEMENT_INAPPROPRIE','FAUSSE_ANNONCE','FRAUDE','DOMMAGES_MATERIELS',
                       'NON_RESPECT_CONDITIONS','AUTRE') NOT NULL,
  description     TEXT NOT NULL,
  claimed_amount  DECIMAL(15,2) NULL,
  status          ENUM('OUVERT','EN_INSTRUCTION','INFOS_DEMANDEES','MEDIATION','RESOLU_AMIABLE',
                       'RESOLU_DECISION','REJETE','CLOS') NOT NULL DEFAULT 'OUVERT',
  priority        ENUM('BASSE','NORMALE','HAUTE','URGENTE') NOT NULL DEFAULT 'NORMALE',
  assigned_to     BIGINT UNSIGNED NULL,
  conversation_id BIGINT UNSIGNED NULL,
  resolution      ENUM('AUCUNE','REMBOURSEMENT_TOTAL','REMBOURSEMENT_PARTIEL','PAIEMENT_PRESTATAIRE',
                       'MISSION_A_REPRENDRE','SANCTION','ACCORD_PARTIES') NULL,
  resolution_note TEXT NULL,
  response_due_at DATETIME NULL,
  opened_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at     DATETIME NULL,
  closed_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_disputes_uuid (uuid),
  UNIQUE KEY uq_disputes_reference (reference),
  KEY idx_disputes_status (status, priority, opened_at),
  KEY idx_disputes_mission (mission_id),
  KEY idx_disputes_assigned (assigned_to, status),
  CONSTRAINT fk_disputes_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_disputes_opened_by FOREIGN KEY (opened_by) REFERENCES users(id),
  CONSTRAINT fk_disputes_respondent FOREIGN KEY (respondent_id) REFERENCES users(id),
  CONSTRAINT fk_disputes_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_disputes_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE refunds
  ADD CONSTRAINT fk_refunds_dispute FOREIGN KEY (dispute_id) REFERENCES disputes(id);

CREATE TABLE dispute_evidence (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispute_id    BIGINT UNSIGNED NOT NULL,
  submitted_by  BIGINT UNSIGNED NOT NULL,
  file_id       BIGINT UNSIGNED NULL,
  message_id    BIGINT UNSIGNED NULL,     -- message de la messagerie versé au dossier
  note          VARCHAR(1000) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_de_dispute (dispute_id),
  CONSTRAINT fk_de_dispute FOREIGN KEY (dispute_id) REFERENCES disputes(id),
  CONSTRAINT fk_de_user FOREIGN KEY (submitted_by) REFERENCES users(id),
  CONSTRAINT fk_de_file FOREIGN KEY (file_id) REFERENCES stored_files(id),
  CONSTRAINT fk_de_message FOREIGN KEY (message_id) REFERENCES messages(id),
  CONSTRAINT chk_de_content CHECK (file_id IS NOT NULL OR message_id IS NOT NULL OR note IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispute_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispute_id    BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action        ENUM('OUVERTURE','ASSIGNATION','DEMANDE_INFOS','REPONSE','PROPOSITION_AMIABLE',
                     'DECISION','SUSPENSION_MISSION','SANCTION','REMBOURSEMENT','CLOTURE','REOUVERTURE','NOTE') NOT NULL,
  from_status   VARCHAR(30) NULL,
  to_status     VARCHAR(30) NULL,
  details       TEXT NULL,
  is_internal   TINYINT(1) NOT NULL DEFAULT 0,  -- note interne non visible des parties
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dev_dispute (dispute_id, created_at),
  CONSTRAINT fk_dev_dispute FOREIGN KEY (dispute_id) REFERENCES disputes(id),
  CONSTRAINT fk_dev_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id        BIGINT UNSIGNED NOT NULL,
  reviewer_id       BIGINT UNSIGNED NOT NULL,
  reviewee_id       BIGINT UNSIGNED NOT NULL,
  direction         ENUM('CLIENT_VERS_PRESTATAIRE','PRESTATAIRE_VERS_CLIENT') NOT NULL,
  punctuality       TINYINT UNSIGNED NOT NULL,
  quality           TINYINT UNSIGNED NOT NULL,
  communication     TINYINT UNSIGNED NOT NULL,
  commitment        TINYINT UNSIGNED NOT NULL,
  professionalism   TINYINT UNSIGNED NOT NULL,
  overall           DECIMAL(3,2) NOT NULL,      -- moyenne des 5 critères (calculée serveur)
  comment           VARCHAR(1000) NULL,
  status            ENUM('PUBLIE','EN_MODERATION','MASQUE') NOT NULL DEFAULT 'PUBLIE',
  revision          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  moderated_by      BIGINT UNSIGNED NULL,
  moderation_note   VARCHAR(500) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_mission_reviewer (mission_id, reviewer_id),
  KEY idx_reviews_reviewee (reviewee_id, status, created_at),
  CONSTRAINT fk_reviews_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id),
  CONSTRAINT fk_reviews_reviewee FOREIGN KEY (reviewee_id) REFERENCES users(id),
  CONSTRAINT fk_reviews_moderator FOREIGN KEY (moderated_by) REFERENCES users(id),
  CONSTRAINT chk_reviews_scores CHECK (punctuality BETWEEN 1 AND 5 AND quality BETWEEN 1 AND 5
      AND communication BETWEEN 1 AND 5 AND commitment BETWEEN 1 AND 5 AND professionalism BETWEEN 1 AND 5),
  CONSTRAINT chk_reviews_parties CHECK (reviewer_id <> reviewee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des modifications d'avis (exigence : pas de modification sans trace)
CREATE TABLE review_revisions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  review_id     BIGINT UNSIGNED NOT NULL,
  revision      SMALLINT UNSIGNED NOT NULL,
  scores_json   VARCHAR(255) NOT NULL,
  comment       VARCHAR(1000) NULL,
  status        VARCHAR(20) NOT NULL,
  changed_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rr_review_rev (review_id, revision),
  CONSTRAINT fk_rr_review FOREIGN KEY (review_id) REFERENCES reviews(id),
  CONSTRAINT fk_rr_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference       VARCHAR(24) NOT NULL,
  reporter_id     BIGINT UNSIGNED NOT NULL,
  target_type     ENUM('UTILISATEUR','ANNONCE','MESSAGE','CONVERSATION','AVIS','ENTREPRISE') NOT NULL,
  target_id       BIGINT UNSIGNED NOT NULL,
  reason          ENUM('FAUSSE_OFFRE','FRAIS_RECRUTEMENT','ACTIVITE_ILLEGALE','HARCELEMENT','FRAUDE',
                       'CONTOURNEMENT','CONTENU_INAPPROPRIE','MINEUR_EN_DANGER','DANGER_IMMEDIAT',
                       'USURPATION','SPAM','AVIS_ABUSIF','AUTRE') NOT NULL,
  description     VARCHAR(2000) NULL,
  severity        ENUM('BASSE','NORMALE','HAUTE','CRITIQUE') NOT NULL DEFAULT 'NORMALE',
  status          ENUM('NOUVEAU','EN_COURS','FONDE','NON_FONDE','CLOS') NOT NULL DEFAULT 'NOUVEAU',
  handled_by      BIGINT UNSIGNED NULL,
  action_taken    VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_at      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reports_reference (reference),
  KEY idx_reports_status (status, severity, created_at),
  KEY idx_reports_target (target_type, target_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id),
  CONSTRAINT fk_reports_handler FOREIGN KEY (handled_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Règles de modération automatique des annonces (mots-clés suspects)
CREATE TABLE moderation_rules (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pattern       VARCHAR(190) NOT NULL,             -- mot ou expression (insensible à la casse)
  applies_to    ENUM('ANNONCE','MESSAGE','PROFIL','AVIS') NOT NULL DEFAULT 'ANNONCE',
  action        ENUM('SIGNALER','MODERATION_OBLIGATOIRE','BLOQUER') NOT NULL DEFAULT 'MODERATION_OBLIGATOIRE',
  reason        VARCHAR(255) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mr_pattern (pattern, applies_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. NOTIFICATIONS
-- -----------------------------------------------------------------------------

CREATE TABLE notifications (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  type          VARCHAR(60)  NOT NULL,       -- application.new, payment.confirmed, …
  title         VARCHAR(160) NOT NULL,
  body          VARCHAR(1000) NULL,
  link_url      VARCHAR(255) NULL,
  data_json     TEXT NULL,
  read_at       DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_unread (user_id, read_at, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_preferences (
  user_id       BIGINT UNSIGNED NOT NULL,
  type          VARCHAR(60) NOT NULL,        -- '*' = tous
  channel       ENUM('IN_APP','EMAIL','SMS','PUSH') NOT NULL,
  enabled       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, type, channel),
  CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- File d'envoi (e-mail, SMS, push) traitée par une tâche planifiée
CREATE TABLE notification_deliveries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id BIGINT UNSIGNED NOT NULL,
  channel         ENUM('EMAIL','SMS','PUSH') NOT NULL,
  destination     VARCHAR(190) NOT NULL,
  status          ENUM('EN_ATTENTE','ENVOYE','ECHEC','ABANDONNE') NOT NULL DEFAULT 'EN_ATTENTE',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  provider_ref    VARCHAR(120) NULL,
  last_error      VARCHAR(500) NULL,
  next_attempt_at DATETIME NULL,
  sent_at         DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nd_queue (status, next_attempt_at),
  CONSTRAINT fk_nd_notification FOREIGN KEY (notification_id) REFERENCES notifications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_devices (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  platform      ENUM('ANDROID','IOS','WEB') NOT NULL,
  token         VARCHAR(255) NOT NULL,
  last_seen_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_token (token),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jetons d'API pour la future application Android (stockés hachés)
CREATE TABLE api_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  abilities     VARCHAR(500) NULL,
  last_used_at  DATETIME NULL,
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_token_hash (token_hash),
  KEY idx_api_user (user_id),
  CONSTRAINT fk_api_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. ADMINISTRATION, AUDIT, SÉCURITÉ, SUPPORT, PARAMÈTRES
-- -----------------------------------------------------------------------------

CREATE TABLE admin_audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(80) NOT NULL,        -- user.suspend, commission_rule.update, refund.approve…
  entity_type   VARCHAR(60) NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  before_json   MEDIUMTEXT NULL,
  after_json    MEDIUMTEXT NULL,
  reason        VARCHAR(1000) NULL,          -- justification obligatoire pour les actions financières
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  prev_hash     CHAR(64) NULL,               -- chaînage (détection d'altération)
  row_hash      CHAR(64) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_aal_actor (actor_user_id, created_at),
  KEY idx_aal_entity (entity_type, entity_id),
  KEY idx_aal_action (action, created_at),
  CONSTRAINT fk_aal_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  event_type    VARCHAR(60) NOT NULL,        -- login.success, login.failed, csrf.invalid, idor.denied, upload.rejected, webhook.bad_signature…
  severity      ENUM('INFO','AVERTISSEMENT','CRITIQUE') NOT NULL DEFAULT 'INFO',
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  details_json  TEXT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_se_user (user_id, created_at),
  KEY idx_se_type (event_type, created_at),
  KEY idx_se_ip (ip_address, created_at),
  CONSTRAINT fk_se_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_settings (
  setting_key   VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  value_type    ENUM('STRING','INT','DECIMAL','BOOL','JSON') NOT NULL DEFAULT 'STRING',
  group_name    VARCHAR(40) NOT NULL DEFAULT 'general',
  description   VARCHAR(255) NULL,
  is_sensitive  TINYINT(1) NOT NULL DEFAULT 0,  -- jamais de secret ici (→ .env), mais masquage UI
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  KEY idx_ps_group (group_name),
  CONSTRAINT fk_ps_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference     VARCHAR(24) NOT NULL,
  user_id       BIGINT UNSIGNED NULL,          -- NULL = formulaire contact public
  contact_name  VARCHAR(160) NULL,
  contact_phone VARCHAR(20) NULL,
  contact_email VARCHAR(190) NULL,
  category      ENUM('COMPTE','MISSION','PAIEMENT','VERSEMENT','TECHNIQUE','SECURITE','AUTRE') NOT NULL,
  subject       VARCHAR(200) NOT NULL,
  status        ENUM('OUVERT','EN_COURS','ATTENTE_UTILISATEUR','RESOLU','FERME') NOT NULL DEFAULT 'OUVERT',
  priority      ENUM('BASSE','NORMALE','HAUTE','URGENTE') NOT NULL DEFAULT 'NORMALE',
  assigned_to   BIGINT UNSIGNED NULL,
  mission_id    BIGINT UNSIGNED NULL,
  payment_id    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_st_reference (reference),
  KEY idx_st_status (status, priority),
  KEY idx_st_user (user_id),
  CONSTRAINT fk_st_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_st_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_st_mission FOREIGN KEY (mission_id) REFERENCES missions(id),
  CONSTRAINT fk_st_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_ticket_messages (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id     BIGINT UNSIGNED NOT NULL,
  author_id     BIGINT UNSIGNED NULL,
  body          TEXT NOT NULL,
  is_internal   TINYINT(1) NOT NULL DEFAULT 0,
  file_id       BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stm_ticket (ticket_id, id),
  CONSTRAINT fk_stm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
  CONSTRAINT fk_stm_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_stm_file FOREIGN KEY (file_id) REFERENCES stored_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suivi des migrations appliquées
CREATE TABLE schema_migrations (
  version       VARCHAR(190) NOT NULL,
  applied_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 17. VUES DE REPORTING
-- -----------------------------------------------------------------------------

-- Solde de chaque compte du grand livre (dans le sens normal du compte)
CREATE OR REPLACE VIEW v_ledger_account_balances AS
SELECT a.id AS account_id, a.code, a.name, a.type, a.normal_balance, a.is_off_platform,
       COALESCE(SUM(e.debit), 0)  AS total_debit,
       COALESCE(SUM(e.credit), 0) AS total_credit,
       CASE WHEN a.normal_balance = 'DEBIT'
            THEN COALESCE(SUM(e.debit), 0) - COALESCE(SUM(e.credit), 0)
            ELSE COALESCE(SUM(e.credit), 0) - COALESCE(SUM(e.debit), 0) END AS balance
FROM financial_ledger_accounts a
LEFT JOIN financial_ledger_entries e ON e.account_id = a.id
GROUP BY a.id, a.code, a.name, a.type, a.normal_balance, a.is_off_platform;

-- Solde par prestataire sur les comptes à sous-ledger (dû retenu / dû disponible / commission à recevoir)
CREATE OR REPLACE VIEW v_ledger_user_balances AS
SELECT e.user_id, a.code, a.name,
       CASE WHEN a.normal_balance = 'DEBIT'
            THEN SUM(e.debit) - SUM(e.credit)
            ELSE SUM(e.credit) - SUM(e.debit) END AS balance
FROM financial_ledger_entries e
JOIN financial_ledger_accounts a ON a.id = e.account_id
WHERE a.has_user_subledger = 1 AND e.user_id IS NOT NULL
GROUP BY e.user_id, a.code, a.name, a.normal_balance;

-- Contrôle d'intégrité : tout journal doit être équilibré (cette vue doit être vide)
CREATE OR REPLACE VIEW v_ledger_unbalanced_journals AS
SELECT j.id, j.reference, j.event_type, SUM(e.debit) AS total_debit, SUM(e.credit) AS total_credit
FROM financial_ledger_journals j
LEFT JOIN financial_ledger_entries e ON e.journal_id = j.id
GROUP BY j.id, j.reference, j.event_type
HAVING COALESCE(SUM(e.debit), 0) <> COALESCE(SUM(e.credit), 0) OR COUNT(e.id) < 2;

-- -----------------------------------------------------------------------------
-- 18. TRIGGERS D'INTÉGRITÉ FINANCIÈRE (ajout seul, aucune suppression silencieuse)
--   Remarque : si la journalisation binaire est active (production), exécuter
--   avec un compte disposant du droit TRIGGER et log_bin_trust_function_creators=1.
-- -----------------------------------------------------------------------------

DELIMITER $$

CREATE TRIGGER trg_fle_no_update BEFORE UPDATE ON financial_ledger_entries
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ecriture comptable immuable : utiliser une contre-passation';
END$$

CREATE TRIGGER trg_fle_no_delete BEFORE DELETE ON financial_ledger_entries
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression interdite sur le grand livre';
END$$

CREATE TRIGGER trg_flj_no_update BEFORE UPDATE ON financial_ledger_journals
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal comptable immuable';
END$$

CREATE TRIGGER trg_flj_no_delete BEFORE DELETE ON financial_ledger_journals
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression interdite sur les journaux comptables';
END$$

-- Transactions : seul le statut (et l'horodatage) peut évoluer
CREATE TRIGGER trg_tx_guard_update BEFORE UPDATE ON transactions
FOR EACH ROW BEGIN
  IF NEW.amount <> OLD.amount OR NEW.commission_amount <> OLD.commission_amount
     OR NEW.net_amount <> OLD.net_amount OR NEW.currency <> OLD.currency
     OR NEW.type <> OLD.type OR NEW.reference <> OLD.reference
     OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.payment_id <=> OLD.payment_id)
     OR NOT (NEW.mission_id <=> OLD.mission_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Montants de transaction immuables : creer une ecriture corrective';
  END IF;
END$$

CREATE TRIGGER trg_tx_no_delete BEFORE DELETE ON transactions
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de transaction interdite';
END$$

-- Paiements : montants attendus figés ; refunded_amount ne peut que croître
CREATE TRIGGER trg_payments_guard_update BEFORE UPDATE ON payments
FOR EACH ROW BEGIN
  IF NEW.amount <> OLD.amount OR NEW.service_amount <> OLD.service_amount
     OR NEW.client_commission <> OLD.client_commission OR NEW.currency <> OLD.currency
     OR NEW.reference <> OLD.reference OR NOT (NEW.mission_id <=> OLD.mission_id)
     OR NEW.payer_user_id <> OLD.payer_user_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Montants du paiement immuables';
  END IF;
  IF NEW.refunded_amount < OLD.refunded_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le montant rembourse ne peut pas diminuer';
  END IF;
  IF OLD.confirmed_amount IS NOT NULL AND NOT (NEW.confirmed_amount <=> OLD.confirmed_amount) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Montant confirme deja enregistre';
  END IF;
  IF OLD.gateway_reference IS NOT NULL AND NOT (NEW.gateway_reference <=> OLD.gateway_reference) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reference operateur deja enregistree';
  END IF;
END$$

CREATE TRIGGER trg_payments_no_delete BEFORE DELETE ON payments
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de paiement interdite';
END$$

CREATE TRIGGER trg_crec_guard_update BEFORE UPDATE ON commission_records
FOR EACH ROW BEGIN
  IF NEW.base_amount <> OLD.base_amount OR NEW.client_commission <> OLD.client_commission
     OR NEW.worker_commission <> OLD.worker_commission OR NEW.total_commission <> OLD.total_commission
     OR NEW.payment_id <> OLD.payment_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Commission immuable : utiliser une annulation partielle';
  END IF;
  IF NEW.reversed_amount < OLD.reversed_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le montant annule ne peut pas diminuer';
  END IF;
END$$

CREATE TRIGGER trg_crec_no_delete BEFORE DELETE ON commission_records
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de commission interdite';
END$$

CREATE TRIGGER trg_payouts_guard_update BEFORE UPDATE ON payouts
FOR EACH ROW BEGIN
  IF NEW.amount <> OLD.amount OR NEW.worker_user_id <> OLD.worker_user_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Montant de versement immuable';
  END IF;
  IF NEW.approved_by IS NOT NULL AND NEW.approved_by <=> NEW.requested_by THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Principe des quatre yeux : approbateur distinct requis';
  END IF;
END$$

CREATE TRIGGER trg_payouts_no_delete BEFORE DELETE ON payouts
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de versement interdite';
END$$

CREATE TRIGGER trg_refunds_no_delete BEFORE DELETE ON refunds
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de remboursement interdite';
END$$

CREATE TRIGGER trg_contracts_guard_update BEFORE UPDATE ON contracts
FOR EACH ROW BEGIN
  IF NEW.content_hash <> OLD.content_hash OR NEW.service_amount <> OLD.service_amount
     OR NEW.client_total <> OLD.client_total OR NEW.worker_net <> OLD.worker_net
     OR NEW.snapshot_json <> OLD.snapshot_json THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contrat fige : creer une nouvelle version';
  END IF;
END$$

CREATE TRIGGER trg_contracts_no_delete BEFORE DELETE ON contracts
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de contrat interdite';
END$$

CREATE TRIGGER trg_ca_no_change BEFORE UPDATE ON contract_acceptances
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acceptation contractuelle immuable';
END$$

CREATE TRIGGER trg_aal_no_update BEFORE UPDATE ON admin_audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal d audit immuable';
END$$

CREATE TRIGGER trg_aal_no_delete BEFORE DELETE ON admin_audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression du journal d audit interdite';
END$$

CREATE TRIGGER trg_receipts_no_delete BEFORE DELETE ON receipts
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de recu interdite';
END$$

CREATE TRIGGER trg_invoices_no_delete BEFORE DELETE ON invoices
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Suppression de facture interdite : emettre un avoir';
END$$

CREATE TRIGGER trg_nm_no_update BEFORE UPDATE ON negotiation_messages
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historique de negociation immuable';
END$$

-- Postes : impossible de dépasser le nombre de postes (double sécurité avec le verrou applicatif)
CREATE TRIGGER trg_jp_positions BEFORE UPDATE ON job_posts
FOR EACH ROW BEGIN
  IF NEW.positions_filled > NEW.positions_total THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nombre de postes disponibles depasse';
  END IF;
END$$

DELIMITER ;

INSERT INTO schema_migrations (version) VALUES ('2026_10_16_000001_initial_schema');
