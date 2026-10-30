-- Pressing 1.5 : service « Lavage seul », articles au mètre carré (tapis), fichier des clients

-- Lavage seul : tarif indicatif = lavage + repassage moins le repassage seul (à adapter dans Tarifs)
INSERT IGNORE INTO pressing_prices (article_id, service, price)
  SELECT l.article_id, 'lavage_seul', l.price - COALESCE(r.price, 0) FROM pressing_prices l
  LEFT JOIN pressing_prices r ON r.article_id = l.article_id AND r.service = 'repassage'
  WHERE l.service = 'lavage' AND l.price - COALESCE(r.price, 0) > 0;

-- Surface déposée (m²), à côté du poids (kg)
ALTER TABLE pressing_orders ADD COLUMN area DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER weight;
INSERT IGNORE INTO pressing_articles (name, category, unit, sort_order) VALUES ('Tapis / moquette', 'Au m²', 'm2', 101);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage_seul', 1500 FROM pressing_articles WHERE name = 'Tapis / moquette';

-- Fichier des clients : un client par numéro de téléphone (chiffres seuls)
CREATE TABLE IF NOT EXISTS pressing_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_key VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  address VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  last_order_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pc_phone (phone_key),
  INDEX idx_pc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE pressing_orders ADD COLUMN client_id INT UNSIGNED NULL AFTER customer_id;
ALTER TABLE pressing_orders ADD INDEX idx_pr_client (client_id);

-- Reprise des clients des dépôts existants (nom et adresse du dépôt le plus récent)
INSERT IGNORE INTO pressing_clients (phone_key, name, phone, address, last_order_at, created_at)
  SELECT m.k, o.customer_name, o.phone, o.delivery_address, m.last_at, m.first_at FROM pressing_orders o
  JOIN (SELECT LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '.', ''), '(', ''), ')', ''), '/', ''), 50) AS k,
               MAX(id) AS last_id, MAX(created_at) AS last_at, MIN(created_at) AS first_at
        FROM pressing_orders GROUP BY k) m ON m.last_id = o.id
  WHERE m.k <> '';
UPDATE pressing_orders o JOIN pressing_clients c
  ON c.phone_key = LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(o.phone, ' ', ''), '+', ''), '-', ''), '.', ''), '(', ''), ')', ''), '/', ''), 50)
  SET o.client_id = c.id WHERE o.client_id IS NULL;
