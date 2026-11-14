-- Retour arrière de la migration 0004 (MARWA AI 3.0). À n'exécuter qu'après une sauvegarde.
DROP TABLE IF EXISTS ai_knowledge_versions;
DROP TABLE IF EXISTS ai_eval_runs;
DROP TABLE IF EXISTS ai_synonyms;
DROP TABLE IF EXISTS ai_scenarios;
DELETE FROM schema_migrations WHERE version = 'addon:marwa_ia:0004_marwa_ai_training';
