-- POS Marwa 2.14 : écran client (second écran) et afficheur client
-- Images et vidéos diffusées sur l'écran tourné vers le client quand la caisse est au repos.
CREATE TABLE IF NOT EXISTS display_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('image','video') NOT NULL,
  path VARCHAR(255) NOT NULL,
  title VARCHAR(150) NULL,
  duration_s INT NOT NULL DEFAULT 8,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dm_order (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
