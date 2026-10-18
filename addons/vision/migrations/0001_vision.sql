-- Reconnaissance des articles par photo : empreintes visuelles apprises et journal des reconnaissances

CREATE TABLE IF NOT EXISTS vision_refs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  vec VARBINARY(512) NOT NULL,
  model VARCHAR(20) NOT NULL DEFAULT 'mnv2-p512',
  source ENUM('photo','fiche','vente') NOT NULL DEFAULT 'photo',
  thumb MEDIUMTEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vref_product (product_id),
  CONSTRAINT fk_vref_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vision_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  suggested_id INT UNSIGNED NULL,
  chosen_id INT UNSIGNED NULL,
  score DECIMAL(5,3) NULL,
  margin DECIMAL(5,3) NULL,
  outcome ENUM('confirme','corrige','auto','aucun') NOT NULL,
  context VARCHAR(30) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vlog_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
