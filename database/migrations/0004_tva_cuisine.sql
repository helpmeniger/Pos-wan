-- POS Wan 1.3 : TVA générale, restaurant (poste par défaut, envoi en cuisine à l'encaissement)

-- Article exonéré de la TVA générale (produits de première nécessité…)
ALTER TABLE products ADD COLUMN vat_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_rate;

-- Mode « product » par défaut : comportement identique aux versions précédentes (taux par article)
INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('vat_mode', 'product'),
  ('vat_rate', '19'),
  ('vat_label', 'TVA'),
  ('restaurant_default_station', 'cuisine'),
  ('restaurant_send_on_pay', '1');
