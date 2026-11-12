-- Marwa Pay Connect : RETOUR ARRIÈRE (suppression des tables du module).
-- ATTENTION : supprime l'historique des transactions électroniques, remboursements et preuves, qui sont des
-- pièces comptables. À n'exécuter qu'après une sauvegarde complète vérifiée et la confirmation écrite du responsable.
-- Les ventes et leurs règlements (sales, sale_payments) ne sont pas touchés.
DROP TABLE IF EXISTS payment_reconciliation;
DROP TABLE IF EXISTS payment_webhooks;
DROP TABLE IF EXISTS payment_transaction_logs;
DROP TABLE IF EXISTS payment_refunds;
DROP TABLE IF EXISTS payment_transactions;
DROP TABLE IF EXISTS payment_methods;
DROP TABLE IF EXISTS payment_terminals;
DROP TABLE IF EXISTS payment_api_credentials;
DROP TABLE IF EXISTS payment_connectors;
DROP TABLE IF EXISTS payment_providers;
DROP TABLE IF EXISTS payment_exchange_rates;
DROP TABLE IF EXISTS payment_currencies;
DROP TABLE IF EXISTS payment_settings;
DELETE FROM sequences WHERE name IN ('pay_transaction', 'pay_refund');
DELETE FROM schema_migrations WHERE version LIKE 'addon:pay:%';
