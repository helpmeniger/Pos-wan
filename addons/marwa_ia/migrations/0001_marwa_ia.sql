-- MARWA IA : historique des questions (90 jours), pour retrouver ses dernières demandes
CREATE TABLE IF NOT EXISTS ia_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  question VARCHAR(500) NOT NULL,
  kind VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_iah_user (user_id, id),
  CONSTRAINT fk_iah_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
