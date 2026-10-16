-- POS Marwa 1.4 : modules complémentaires (dossier addons/), position du menu, assistance

CREATE TABLE IF NOT EXISTS addons (
  code VARCHAR(50) NOT NULL PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  version VARCHAR(20) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('menu_position', 'right'),
  ('support_contact', '+227 92 08 12 82 / 87 22 52 02');
