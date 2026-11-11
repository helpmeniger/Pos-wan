-- Marwa Fiscal Connect : RETOUR ARRIÈRE (suppression des tables du module).
-- ATTENTION : supprime les preuves de certification et l'historique fiscal, qui peuvent être
-- soumis à une obligation légale de conservation. À n'exécuter qu'après :
--   1. une sauvegarde complète (Réglages > Sauvegardes) vérifiée ;
--   2. la confirmation écrite du responsable ;
--   3. la vérification de la durée de conservation légale du pays.
-- Les ventes (tables sales, sale_items…) ne sont pas touchées.
DROP TABLE IF EXISTS fiscal_audit_logs;
DROP TABLE IF EXISTS fiscal_webhooks;
DROP TABLE IF EXISTS fiscal_invoice_logs;
DROP TABLE IF EXISTS fiscal_pending_queue;
DROP TABLE IF EXISTS fiscal_certifications;
DROP TABLE IF EXISTS fiscal_certificates;
DROP TABLE IF EXISTS fiscal_api_credentials;
DROP TABLE IF EXISTS fiscal_devices;
DROP TABLE IF EXISTS fiscal_tax_rates;
DROP TABLE IF EXISTS fiscal_profiles;
DROP TABLE IF EXISTS fiscal_connectors;
DROP TABLE IF EXISTS fiscal_countries;
DELETE FROM schema_migrations WHERE version LIKE 'addon:fiscal:%';
