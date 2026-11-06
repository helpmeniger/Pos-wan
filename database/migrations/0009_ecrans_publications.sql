-- POS Marwa 3.4 : publications de l'écran client (texte seul ou texte + photo, mise en forme
-- automatique) et accès direct aux écrans (téléviseur, écran connecté) par une adresse dédiée.

ALTER TABLE display_media MODIFY kind ENUM('image','video','text') NOT NULL;
ALTER TABLE display_media MODIFY path VARCHAR(255) NULL;
ALTER TABLE display_media ADD COLUMN body TEXT NULL AFTER title;
ALTER TABLE display_media ADD COLUMN style VARCHAR(255) NULL AFTER body;

-- Écran « mémorisé » : jeton propre à l'appareil (téléviseur…), révocable, invalidé si le
-- mot de passe de l'utilisateur change. Seule l'empreinte du jeton est conservée.
CREATE TABLE IF NOT EXISTS screen_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  screen VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  pwd_sig CHAR(64) NOT NULL,
  label VARCHAR(190) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  revoked_at DATETIME NULL,
  INDEX idx_sd_screen (screen, revoked_at),
  CONSTRAINT fk_sd_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
