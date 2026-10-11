-- Module Livraisons : tables préfixées par le code du module
CREATE TABLE IF NOT EXISTS livraisons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NULL,
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  address VARCHAR(255) NOT NULL,
  driver VARCHAR(100) NULL,
  fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  status ENUM('a_livrer','en_route','livree','annulee') NOT NULL DEFAULT 'a_livrer',
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  INDEX idx_liv_status (status),
  CONSTRAINT fk_liv_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
  CONSTRAINT fk_liv_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
