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
  ADD COLUMN password_changed_at DATETIME NULL AFTER session_version;

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
 ('security.staff_2fa_required', '0'),
 ('push.enabled', '1')
ON DUPLICATE KEY UPDATE v = v;
