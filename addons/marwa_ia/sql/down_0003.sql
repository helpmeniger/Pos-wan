-- MARWA AI 2.0 : retour arrière (supprime conversations, connaissances ajoutées, journaux).
-- À n'exécuter qu'après une sauvegarde vérifiée et l'accord écrit du responsable.
-- Les tables d'origine ia_history et ia_training ne sont pas touchées.
DROP TABLE IF EXISTS ai_feedback;
DROP TABLE IF EXISTS ai_messages;
DROP TABLE IF EXISTS ai_conversations;
DROP TABLE IF EXISTS ai_knowledge_base;
DROP TABLE IF EXISTS ai_intent_examples;
DROP TABLE IF EXISTS ai_intents;
DROP TABLE IF EXISTS ai_tool_registry;
DROP TABLE IF EXISTS ai_user_context;
DROP TABLE IF EXISTS ai_learning_logs;
DROP TABLE IF EXISTS ai_model_settings;
DROP TABLE IF EXISTS ai_usage_logs;
DROP TABLE IF EXISTS ai_action_logs;
DROP TABLE IF EXISTS ai_documentation_index;
DELETE FROM schema_migrations WHERE version = 'addon:marwa_ia:0003_marwa_ai';
