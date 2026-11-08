-- POS Marwa 3.5 : clôture automatique des caisses à 23h59 (option) et fond de caisse facultatif.

ALTER TABLE cash_sessions ADD COLUMN auto_closed TINYINT(1) NOT NULL DEFAULT 0 AFTER closing_note;

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('cash_ask_opening', '1'),
  ('cash_auto_close', '0');
