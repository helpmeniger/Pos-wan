-- Reconnaissance 1.1 : caméras (USB, carte d'acquisition, IP) et surveillance des passages d'articles

CREATE TABLE IF NOT EXISTS vision_cameras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kind ENUM('usb','carte','ip') NOT NULL DEFAULT 'usb',
  device_label VARCHAR(200) NULL,
  url VARCHAR(500) NULL,
  url_kind ENUM('snapshot','mjpeg') NOT NULL DEFAULT 'snapshot',
  username VARCHAR(100) NULL,
  secret TEXT NULL,
  fps TINYINT UNSIGNED NOT NULL DEFAULT 3,
  use_caisse TINYINT(1) NOT NULL DEFAULT 0,
  use_surveillance TINYINT(1) NOT NULL DEFAULT 1,
  mode ENUM('ligne','zone') NOT NULL DEFAULT 'ligne',
  geometry TEXT NULL,
  sensitivity TINYINT UNSIGNED NOT NULL DEFAULT 5,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vision_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id INT UNSIGNED NOT NULL,
  direction ENUM('sortie','entree','vu') NOT NULL,
  product_id INT UNSIGNED NULL,
  score DECIMAL(5,3) NULL,
  margin DECIMAL(5,3) NULL,
  status ENUM('identifie','incertain','confirme','corrige','ignore') NOT NULL DEFAULT 'incertain',
  thumb MEDIUMTEXT NULL,
  seen_at DATETIME NOT NULL,
  user_id INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  INDEX idx_vev_seen (seen_at),
  INDEX idx_vev_prod (product_id, seen_at),
  CONSTRAINT fk_vev_cam FOREIGN KEY (camera_id) REFERENCES vision_cameras(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vision_refs MODIFY source ENUM('photo','fiche','vente','surveillance') NOT NULL DEFAULT 'photo';
