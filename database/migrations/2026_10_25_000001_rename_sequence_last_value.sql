-- « last_value » est un mot réservé de MySQL 8 (fonction de fenêtrage) : renommé en current_value.
-- Conditionnel : ne fait rien si la base a été créée avec le schéma déjà corrigé.
SET @has_old = (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_sequences' AND column_name = 'last_value');
SET @sql = IF(@has_old > 0,
  'ALTER TABLE document_sequences CHANGE `last_value` current_value BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
