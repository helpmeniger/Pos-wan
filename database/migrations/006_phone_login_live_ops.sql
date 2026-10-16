-- =====================================================================
-- MARWA — migration 006 : connexion par téléphone, « se souvenir de moi »,
-- présence dans l'application, photos des promotions, contact des prestataires.
-- =====================================================================

-- Jetons « se souvenir de moi » (sélecteur public + vérificateur haché, rotation à chaque usage)
CREATE TABLE remember_tokens (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  selector       CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  expires_at     DATETIME NOT NULL,
  last_used_at   DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dernière activité dans l'application (pour savoir qui joindre autrement)
ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at,
  ADD KEY idx_users_last_seen (last_seen_at);

-- Photo des promotions
ALTER TABLE promotions ADD COLUMN image_file_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_promo_image FOREIGN KEY (image_file_id) REFERENCES files(id) ON DELETE SET NULL;

-- Historique des contacts de l'administration vers les prestataires / clients
CREATE TABLE staff_contacts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id    BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NULL,
  channels    VARCHAR(60) NOT NULL,
  message     VARCHAR(500) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sc_user (user_id, created_at),
  CONSTRAINT fk_sc_staff FOREIGN KEY (staff_id) REFERENCES users(id),
  CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (k, v) VALUES
 ('auth.client_phone_login', 'true'),
 ('auth.remember_days', '30'),
 ('notifications.sms_fallback', 'true'),
 ('live.alert_searching_minutes', '5'),
 ('dispatch.offer_open_orders_on_online', 'true')
ON DUPLICATE KEY UPDATE v = v;
