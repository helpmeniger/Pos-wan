-- Module Doublons d'articles : journal des fusions (copie complète de chaque doublon supprimé).
CREATE TABLE IF NOT EXISTS doublons_journal (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kept_id INT UNSIGNED NOT NULL,
  kept_name VARCHAR(200) NOT NULL,
  removed_id INT UNSIGNED NOT NULL,
  removed_name VARCHAR(200) NOT NULL,
  removed_ref VARCHAR(50) NULL,
  removed_barcode VARCHAR(64) NULL,
  moved INT NOT NULL DEFAULT 0,
  deleted TINYINT(1) NOT NULL DEFAULT 1,
  details LONGTEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dbl_kept (kept_id),
  INDEX idx_dbl_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
