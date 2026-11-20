-- POS Marwa 3.14 : ordinateurs du réseau comptés par la licence (licence multiposte).
-- Téléphones et tablettes ne sont pas comptés ; le poste principal (où POS Marwa est installé) non plus.

CREATE TABLE IF NOT EXISTS licence_postes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_hash CHAR(64) NOT NULL,
  label VARCHAR(150) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  status ENUM('actif','libere') NOT NULL DEFAULT 'actif',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  freed_at DATETIME NULL,
  UNIQUE KEY uq_licence_postes_device (device_hash),
  KEY idx_licence_postes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
