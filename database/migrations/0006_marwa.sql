-- POS Marwa 2.0 : appareils (accès mobile), inventaire par scan, modèles d'étiquettes

CREATE TABLE IF NOT EXISTS user_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  label VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NULL,
  status ENUM('approved','pending','revoked') NOT NULL DEFAULT 'approved',
  approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  UNIQUE KEY uq_device_user (device_hash, user_id),
  INDEX idx_device_status (status),
  CONSTRAINT fk_udev_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventaire : qui a compté, observations, nombre de scans, dernière modification
ALTER TABLE inventory_items ADD COLUMN note VARCHAR(255) NULL AFTER counted_qty;
ALTER TABLE inventory_items ADD COLUMN scans INT NOT NULL DEFAULT 0 AFTER note;
ALTER TABLE inventory_items ADD COLUMN counted_by INT UNSIGNED NULL AFTER scans;
ALTER TABLE inventory_items ADD COLUMN counted_at DATETIME NULL AFTER counted_by;
ALTER TABLE inventories ADD COLUMN category_id INT UNSIGNED NULL AFTER warehouse_id;

-- Historique des corrections de comptage (avant validation)
CREATE TABLE IF NOT EXISTS inventory_count_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  old_qty DECIMAL(15,3) NULL,
  new_qty DECIMAL(15,3) NULL,
  method ENUM('scan','manual','reset') NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_icl_inv (inventory_id),
  CONSTRAINT fk_icl_inv FOREIGN KEY (inventory_id) REFERENCES inventories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modèles d'étiquettes réutilisables
CREATE TABLE IF NOT EXISTS label_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  config TEXT NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO label_templates (name, config) VALUES
  ('A4 — 3 colonnes (38 × 21 mm)', '{"paper":"a4","width":63.5,"height":38.1,"cols":3,"margin_top":10,"margin_left":7,"gap_x":2.5,"gap_y":0,"barcode_height":12,"font":8,"fields":["barcode","name","price"]}'),
  ('Rouleau thermique 40 × 30 mm', '{"paper":"roll","width":40,"height":30,"cols":1,"margin_top":1,"margin_left":1,"gap_x":0,"gap_y":2,"barcode_height":10,"font":7,"fields":["barcode","name","price"]}'),
  ('Rouleau thermique 58 × 40 mm', '{"paper":"roll","width":58,"height":40,"cols":1,"margin_top":1,"margin_left":1,"gap_x":0,"gap_y":2,"barcode_height":14,"font":9,"fields":["barcode","name","price","reference"]}');

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('device_approval', '0'),
  ('scan_repeat_ms', '1500');
