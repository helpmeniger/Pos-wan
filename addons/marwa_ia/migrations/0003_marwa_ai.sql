-- MARWA AI 2.0 : conversations, connaissance, intentions, outils, apprentissage, modèles, journaux.
-- Les tables existantes (ia_history, ia_training) sont conservées. Retour arrière : sql/down_0003.sql.

CREATE TABLE IF NOT EXISTS ai_conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL DEFAULT 'Nouvelle conversation',
  context_json TEXT NULL,
  summary TEXT NULL,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_aic_user (user_id, updated_at),
  CONSTRAINT fk_aic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  payload_json MEDIUMTEXT NULL,
  intent VARCHAR(60) NULL,
  confidence DECIMAL(4,3) NULL,
  sources VARCHAR(500) NULL,
  provider VARCHAR(60) NULL,
  duration_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aim_conv (conversation_id, id),
  CONSTRAINT fk_aim_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_base (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('faq','procedure','validated_answer','note') NOT NULL DEFAULT 'procedure',
  title VARCHAR(200) NOT NULL,
  keywords VARCHAR(500) NULL,
  body TEXT NOT NULL,
  links_json TEXT NULL,
  module VARCHAR(60) NULL,
  permission VARCHAR(60) NULL,
  source ENUM('builtin','admin','learning') NOT NULL DEFAULT 'admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  INDEX idx_aik_kind (kind, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_intents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  label VARCHAR(160) NOT NULL,
  module VARCHAR(60) NULL,
  tool VARCHAR(60) NULL,
  permission VARCHAR(120) NULL,
  description VARCHAR(255) NULL,
  builtin TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_intent_examples (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  intent_code VARCHAR(60) NOT NULL,
  text VARCHAR(300) NOT NULL,
  normalized VARCHAR(300) NOT NULL,
  source ENUM('builtin','admin','learning') NOT NULL DEFAULT 'builtin',
  validated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aie (intent_code, normalized),
  INDEX idx_aie_intent (intent_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_tool_registry (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  label VARCHAR(160) NOT NULL,
  kind ENUM('read','navigate','report','action') NOT NULL,
  permission VARCHAR(120) NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
  needs_confirmation TINYINT(1) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_user_context (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  preferences_json TEXT NULL,
  last_module VARCHAR(60) NULL,
  memory_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NULL,
  CONSTRAINT fk_aiu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  rating TINYINT NOT NULL,
  comment VARCHAR(500) NULL,
  status ENUM('new','reviewed') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aif (message_id, user_id),
  CONSTRAINT fk_aif_msg FOREIGN KEY (message_id) REFERENCES ai_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_learning_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  question VARCHAR(500) NOT NULL,
  normalized VARCHAR(500) NOT NULL,
  intent_guess VARCHAR(60) NULL,
  confidence DECIMAL(4,3) NULL,
  outcome ENUM('answered','fallback','not_understood','refused','clarify','error') NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolution VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ail_outcome (outcome, resolved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_model_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  provider ENUM('ollama','openai','anthropic','gemini','openai_compatible') NOT NULL,
  base_url VARCHAR(255) NULL,
  model VARCHAR(120) NOT NULL,
  api_key_enc TEXT NULL,
  api_key_hint VARCHAR(12) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_fallback TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 0,
  external_ack TINYINT(1) NOT NULL DEFAULT 0,
  max_output_tokens INT UNSIGNED NOT NULL DEFAULT 1024,
  daily_token_limit INT UNSIGNED NULL,
  user_daily_requests INT UNSIGNED NULL,
  input_cost_per_mtok DECIMAL(10,4) NULL,
  output_cost_per_mtok DECIMAL(10,4) NULL,
  timeout_s SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  options_json TEXT NULL,
  status ENUM('unknown','ok','error') NOT NULL DEFAULT 'unknown',
  last_test_at DATETIME NULL,
  last_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_setting_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  purpose VARCHAR(30) NOT NULL,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cost DECIMAL(12,6) NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aiul_day (created_at),
  INDEX idx_aiul_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_action_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  tool_code VARCHAR(60) NOT NULL,
  params_json TEXT NULL,
  summary VARCHAR(500) NOT NULL,
  status ENUM('proposed','executed','refused','failed','expired','cancelled') NOT NULL DEFAULT 'proposed',
  confirm_token_hash CHAR(64) NULL,
  expires_at DATETIME NULL,
  result VARCHAR(500) NULL,
  ref_type VARCHAR(40) NULL,
  ref_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  executed_at DATETIME NULL,
  INDEX idx_aia_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_documentation_index (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('module','menu','route','table','column','permission','setting','doc') NOT NULL,
  ref VARCHAR(190) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  module VARCHAR(60) NULL,
  permission VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aid (kind, ref),
  INDEX idx_aid_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
