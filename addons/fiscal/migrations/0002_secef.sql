-- Marwa Fiscal Connect 1.1 : MARWA SECEF CONNECT (Niger, plateforme e-SECeF / e-SFE de la DGI).
-- Tables propres au module : aucune table existante n'est modifiée, aucune donnée n'est effacée.

-- Choix du caissier, vente par vente : « certifiée DGI » ou « ordinaire » (historique conservé).
CREATE TABLE IF NOT EXISTS fiscal_sale_requests (
  sale_id INT UNSIGNED NOT NULL PRIMARY KEY,
  mode ENUM('certifiee','ordinaire') NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fsr_mode (mode, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correspondance article POS Marwa ↔ article « programmé » sur l'e-SFE.
-- groupe : A, B, C, D, F, G, H, I, J, K, L (liste observée sur l'e-SFE) ; NULL = règle par défaut.
CREATE TABLE IF NOT EXISTS secef_articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  designation VARCHAR(200) NOT NULL,
  code VARCHAR(60) NULL,
  groupe CHAR(1) NULL,
  prix DECIMAL(15,2) NULL,
  mode ENUM('TTC','HT') NOT NULL DEFAULT 'TTC',
  statut ENUM('a_programmer','programme','erreur','ignore') NOT NULL DEFAULT 'a_programmer',
  derniere_erreur VARCHAR(255) NULL,
  programme_le DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_secef_art_product (product_id),
  INDEX idx_secef_art_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Articles relevés sur l'e-SFE (copie de consultation, rafraîchie à la demande).
CREATE TABLE IF NOT EXISTS secef_portail_articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  designation VARCHAR(200) NOT NULL,
  groupe CHAR(1) NULL,
  prix_ttc DECIMAL(15,2) NULL,
  prix_ht DECIMAL(15,2) NULL,
  mode VARCHAR(5) NULL,
  taxe_specifique VARCHAR(60) NULL,
  releve_le DATETIME NOT NULL,
  INDEX idx_secef_pa_designation (designation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groupe de taxation par défaut d'une catégorie d'articles (prioritaire sur la règle du régime).
CREATE TABLE IF NOT EXISTS secef_categories (
  category_id INT UNSIGNED NOT NULL PRIMARY KEY,
  groupe CHAR(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue) VALUES ('fiscal_choix_caisse', 'toutes');
