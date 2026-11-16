-- Reconnaissance 1.3 : rôle des caméras (étagère, caisse, sortie), emplacements d'étagère,
-- suivi « article pris en rayon → encaissé ? » et alertes de vérification.
-- Aucune table existante n'est vidée ; les caméras existantes gardent leur fonctionnement (rôle « passage »).

ALTER TABLE vision_cameras
  ADD COLUMN role ENUM('passage','etagere','caisse','sortie') NOT NULL DEFAULT 'passage' AFTER kind,
  ADD COLUMN store_id INT UNSIGNED NULL AFTER role,
  ADD COLUMN place VARCHAR(100) NULL AFTER store_id,
  ADD COLUMN register_id INT UNSIGNED NULL AFTER place;

ALTER TABLE vision_events
  MODIFY direction ENUM('sortie','entree','vu','prise','remis') NOT NULL,
  ADD COLUMN slot_id INT UNSIGNED NULL AFTER camera_id;

-- Emplacements tracés sur l'image d'une caméra d'étagère (coordonnées relatives 0 à 1), article attendu facultatif
CREATE TABLE IF NOT EXISTS vision_slots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  product_id INT UNSIGNED NULL,
  x DECIMAL(5,4) NOT NULL, y DECIMAL(5,4) NOT NULL, w DECIMAL(5,4) NOT NULL, h DECIMAL(5,4) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vslot_cam (camera_id),
  CONSTRAINT fk_vslot_cam FOREIGN KEY (camera_id) REFERENCES vision_cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suivi d'un article pris en rayon : encaissé, remis, vu en caisse, vu à la sortie, ou à vérifier
CREATE TABLE IF NOT EXISTS vision_journeys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NULL,
  camera_id INT UNSIGNED NULL,
  slot_id INT UNSIGNED NULL,
  product_id INT UNSIGNED NULL,
  identified_by ENUM('emplacement','reconnaissance','aucun') NOT NULL DEFAULT 'aucun',
  origin ENUM('rayon','caisse','sortie') NOT NULL DEFAULT 'rayon',
  taken_event_id INT UNSIGNED NULL,
  taken_at DATETIME NOT NULL,
  deadline_at DATETIME NOT NULL,
  status ENUM('en_cours','vu_caisse','encaisse','remis','alerte','verifie_encaisse','verifie_remis','fausse_alerte','perte') NOT NULL DEFAULT 'en_cours',
  level ENUM('faible','verifier','priorite') NULL,
  reason VARCHAR(255) NULL,
  checkout_event_id INT UNSIGNED NULL,
  checkout_at DATETIME NULL,
  exit_event_id INT UNSIGNED NULL,
  exit_at DATETIME NULL,
  sale_id INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  alerted_at DATETIME NULL,
  reminded_at DATETIME NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  note VARCHAR(255) NULL,
  INDEX idx_vj_status (status, deadline_at),
  INDEX idx_vj_prod (product_id, status),
  INDEX idx_vj_taken (taken_at),
  INDEX idx_vj_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
