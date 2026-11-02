-- MARWA IA 1.1 : progression de la formation (meilleur score par leçon et par utilisateur)
CREATE TABLE IF NOT EXISTS ia_training (
  user_id INT UNSIGNED NOT NULL,
  lesson VARCHAR(30) NOT NULL,
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  total TINYINT UNSIGNED NOT NULL DEFAULT 0,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson),
  CONSTRAINT fk_iat_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
