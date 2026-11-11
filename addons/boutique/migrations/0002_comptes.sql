-- Boutique en ligne 1.1 : comptes clients (coordonnées enregistrées, historique des commandes et reçus)

CREATE TABLE IF NOT EXISTS shop_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  phone_key VARCHAR(20) NOT NULL,                -- chiffres seulement : identifiant de connexion
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_shop_account_phone (phone_key),
  INDEX idx_shop_account_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE shop_orders ADD COLUMN account_id INT UNSIGNED NULL AFTER customer_id;

ALTER TABLE shop_orders ADD INDEX idx_shopo_account (account_id, created_at);
