-- Pressing 1.3 : articles facturés au poids (kg) et quantités décimales (4,5 kg)
ALTER TABLE pressing_articles ADD COLUMN unit VARCHAR(10) NOT NULL DEFAULT 'piece' AFTER category;
ALTER TABLE pressing_items MODIFY quantity DECIMAL(10,3) NOT NULL;
ALTER TABLE pressing_items ADD COLUMN unit VARCHAR(10) NOT NULL DEFAULT 'piece' AFTER quantity;
ALTER TABLE pressing_items MODIFY picked_qty DECIMAL(10,3) NOT NULL DEFAULT 0;
ALTER TABLE pressing_orders ADD COLUMN weight DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER pieces;
-- Article fourni (tarifs indicatifs, à adapter) : linge de maison ou vêtements courants pesés ensemble
INSERT IGNORE INTO pressing_articles (name, category, unit, sort_order) VALUES ('Linge au kilo', 'Au kilo', 'kg', 100);
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'lavage', 1000 FROM pressing_articles WHERE name = 'Linge au kilo';
INSERT IGNORE INTO pressing_prices (article_id, service, price) SELECT id, 'repassage', 500 FROM pressing_articles WHERE name = 'Linge au kilo';
