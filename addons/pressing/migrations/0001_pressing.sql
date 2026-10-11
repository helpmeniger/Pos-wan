-- Module Pressing : tarifs, dépôts, articles déposés, paiements, historique

CREATE TABLE IF NOT EXISTS pressing_articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  category VARCHAR(50) NOT NULL DEFAULT 'Vêtements',
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pressing_prices (
  article_id INT UNSIGNED NOT NULL,
  service VARCHAR(20) NOT NULL,
  price DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (article_id, service),
  CONSTRAINT fk_pp_article FOREIGN KEY (article_id) REFERENCES pressing_articles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pressing_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NULL,
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  status ENUM('received','processing','ready','delivered','cancelled') NOT NULL DEFAULT 'received',
  express TINYINT(1) NOT NULL DEFAULT 0,
  due_date DATE NOT NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  express_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid DECIMAL(15,2) NOT NULL DEFAULT 0,
  pieces INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NOT NULL,
  ready_at DATETIME NULL,
  delivered_at DATETIME NULL,
  delivered_by INT UNSIGNED NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pr_status (status),
  INDEX idx_pr_phone (phone),
  INDEX idx_pr_due (due_date),
  CONSTRAINT fk_pr_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pressing_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  article_id INT UNSIGNED NULL,
  article_name VARCHAR(100) NOT NULL,
  service VARCHAR(20) NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(15,2) NOT NULL,
  list_price DECIMAL(15,2) NOT NULL,
  line_total DECIMAL(15,2) NOT NULL,
  color VARCHAR(60) NULL,
  brand VARCHAR(80) NULL,
  defects VARCHAR(255) NULL,
  tag VARCHAR(30) NOT NULL,
  INDEX idx_pi_tag (tag),
  CONSTRAINT fk_pi_order FOREIGN KEY (order_id) REFERENCES pressing_orders(id),
  CONSTRAINT fk_pi_article FOREIGN KEY (article_id) REFERENCES pressing_articles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pressing_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('deposit','payment','refund') NOT NULL,
  method VARCHAR(30) NOT NULL,
  provider VARCHAR(50) NULL,
  amount DECIMAL(15,2) NOT NULL,
  reference VARCHAR(100) NULL,
  cash_session_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pp_date (created_at),
  CONSTRAINT fk_ppay_order FOREIGN KEY (order_id) REFERENCES pressing_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pressing_status_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_psl_order FOREIGN KEY (order_id) REFERENCES pressing_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES ('pressing', 'PR', 1, YEAR(CURDATE()));

-- Tarifs indicatifs (FCFA), modifiables dans Pressing → Tarifs
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Chemise', 'Hommes', 1);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 500 FROM pressing_articles WHERE name = 'Chemise';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 1000 FROM pressing_articles WHERE name = 'Chemise';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 250 FROM pressing_articles WHERE name = 'Chemise';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 1500 FROM pressing_articles WHERE name = 'Chemise';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Pantalon', 'Hommes', 2);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 500 FROM pressing_articles WHERE name = 'Pantalon';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 1000 FROM pressing_articles WHERE name = 'Pantalon';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 250 FROM pressing_articles WHERE name = 'Pantalon';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 2000 FROM pressing_articles WHERE name = 'Pantalon';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('T-shirt / polo', 'Hommes', 3);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 300 FROM pressing_articles WHERE name = 'T-shirt / polo';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 200 FROM pressing_articles WHERE name = 'T-shirt / polo';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Veste', 'Hommes', 4);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1000 FROM pressing_articles WHERE name = 'Veste';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 2000 FROM pressing_articles WHERE name = 'Veste';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 500 FROM pressing_articles WHERE name = 'Veste';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Costume 2 pièces', 'Hommes', 5);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 2000 FROM pressing_articles WHERE name = 'Costume 2 pièces';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 3500 FROM pressing_articles WHERE name = 'Costume 2 pièces';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 1000 FROM pressing_articles WHERE name = 'Costume 2 pièces';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Costume 3 pièces', 'Hommes', 6);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 2500 FROM pressing_articles WHERE name = 'Costume 3 pièces';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 4500 FROM pressing_articles WHERE name = 'Costume 3 pièces';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 1250 FROM pressing_articles WHERE name = 'Costume 3 pièces';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Boubou simple', 'Traditionnel', 7);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1000 FROM pressing_articles WHERE name = 'Boubou simple';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 2000 FROM pressing_articles WHERE name = 'Boubou simple';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 500 FROM pressing_articles WHERE name = 'Boubou simple';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 3000 FROM pressing_articles WHERE name = 'Boubou simple';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Grand boubou (3 pièces)', 'Traditionnel', 8);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 2500 FROM pressing_articles WHERE name = 'Grand boubou (3 pièces)';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 4000 FROM pressing_articles WHERE name = 'Grand boubou (3 pièces)';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 1500 FROM pressing_articles WHERE name = 'Grand boubou (3 pièces)';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 5000 FROM pressing_articles WHERE name = 'Grand boubou (3 pièces)';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Complet pagne', 'Femmes', 9);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1000 FROM pressing_articles WHERE name = 'Complet pagne';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 2000 FROM pressing_articles WHERE name = 'Complet pagne';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 500 FROM pressing_articles WHERE name = 'Complet pagne';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 3000 FROM pressing_articles WHERE name = 'Complet pagne';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Robe', 'Femmes', 10);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1000 FROM pressing_articles WHERE name = 'Robe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 2000 FROM pressing_articles WHERE name = 'Robe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 500 FROM pressing_articles WHERE name = 'Robe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 2500 FROM pressing_articles WHERE name = 'Robe';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Robe de soirée / mariée', 'Femmes', 11);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 3000 FROM pressing_articles WHERE name = 'Robe de soirée / mariée';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 6000 FROM pressing_articles WHERE name = 'Robe de soirée / mariée';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 1500 FROM pressing_articles WHERE name = 'Robe de soirée / mariée';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Jupe', 'Femmes', 12);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 500 FROM pressing_articles WHERE name = 'Jupe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 1000 FROM pressing_articles WHERE name = 'Jupe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 250 FROM pressing_articles WHERE name = 'Jupe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 1500 FROM pressing_articles WHERE name = 'Jupe';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Turban / chèche', 'Traditionnel', 13);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 500 FROM pressing_articles WHERE name = 'Turban / chèche';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 1000 FROM pressing_articles WHERE name = 'Turban / chèche';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 250 FROM pressing_articles WHERE name = 'Turban / chèche';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'teinture', 1000 FROM pressing_articles WHERE name = 'Turban / chèche';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Cravate', 'Hommes', 14);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 300 FROM pressing_articles WHERE name = 'Cravate';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 500 FROM pressing_articles WHERE name = 'Cravate';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 200 FROM pressing_articles WHERE name = 'Cravate';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Drap', 'Maison', 15);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 750 FROM pressing_articles WHERE name = 'Drap';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 300 FROM pressing_articles WHERE name = 'Drap';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Couverture', 'Maison', 16);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 2500 FROM pressing_articles WHERE name = 'Couverture';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 4000 FROM pressing_articles WHERE name = 'Couverture';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Rideau (pièce)', 'Maison', 17);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1500 FROM pressing_articles WHERE name = 'Rideau (pièce)';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'sec', 3000 FROM pressing_articles WHERE name = 'Rideau (pièce)';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 750 FROM pressing_articles WHERE name = 'Rideau (pièce)';
INSERT IGNORE INTO pressing_articles (name, category, sort_order) VALUES ('Nappe', 'Maison', 18);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 750 FROM pressing_articles WHERE name = 'Nappe';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 300 FROM pressing_articles WHERE name = 'Nappe';
