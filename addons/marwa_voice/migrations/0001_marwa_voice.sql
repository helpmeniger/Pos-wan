-- MARWA VOICE AI : historique des annonces vocales (ce qui a été lu, écarté ou n'a pas pu être lu)

CREATE TABLE IF NOT EXISTS marwa_voice_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(60) NOT NULL,
  level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('lu','doublon','groupe','silence','expire','interrompu','file_pleine','erreur_voix','desactive') NOT NULL,
  text VARCHAR(400) NULL,
  engine VARCHAR(20) NULL,
  poste VARCHAR(40) NULL,
  page VARCHAR(60) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mvl_date (created_at),
  INDEX idx_mvl_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
