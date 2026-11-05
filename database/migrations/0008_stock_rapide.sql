-- Ajout de stock rapide et import du stock depuis Excel / CSV (version 3.2.0)
-- Chaque import est conservé (fichier d'origine, auteur, lignes appliquées ou refusées) ;
-- les quantités passent toujours par des mouvements de stock signés (stock_movements).

CREATE TABLE IF NOT EXISTS stock_imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(190) NOT NULL,
  stored_file VARCHAR(190) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  mode ENUM('add','set') NOT NULL,
  warehouse_id INT UNSIGNED NULL,
  rows_total INT UNSIGNED NOT NULL DEFAULT 0,
  rows_applied INT UNSIGNED NOT NULL DEFAULT 0,
  rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_si_date (created_at),
  CONSTRAINT fk_si_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_import_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL,
  identifier VARCHAR(120) NULL,
  product_id INT UNSIGNED NULL,
  warehouse_id INT UNSIGNED NULL,
  qty_before DECIMAL(15,3) NULL,
  qty_change DECIMAL(15,3) NULL,
  qty_after DECIMAL(15,3) NULL,
  status ENUM('applied','skipped','error') NOT NULL,
  message VARCHAR(255) NULL,
  INDEX idx_sir_import (import_id, line_no),
  CONSTRAINT fk_sir_import FOREIGN KEY (import_id) REFERENCES stock_imports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lisibilité du ticket : titre principal en gras par défaut (case cochée dans les paramètres)
INSERT IGNORE INTO settings (skey, svalue) VALUES ('ticket_title_bold', '1');
