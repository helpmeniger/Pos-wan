-- MARWA AI 3.0 — centre d'entraînement : scénarios, synonymes, évaluations, historique des améliorations.
-- Aucune table existante n'est modifiée.

CREATE TABLE IF NOT EXISTS ai_scenarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  domain VARCHAR(80) NOT NULL,
  intent VARCHAR(60) NOT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'admin',
  question VARCHAR(500) NOT NULL,
  train_json TEXT NULL,
  test_json TEXT NULL,
  expect_json TEXT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'builtin',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_ai_scenario_code (code),
  INDEX idx_ai_scenario_domain (domain),
  INDEX idx_ai_scenario_intent (intent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_synonyms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  term VARCHAR(80) NOT NULL,
  canonical VARCHAR(80) NOT NULL,
  note VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_synonym_term (term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_eval_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(120) NULL,
  scope VARCHAR(80) NOT NULL DEFAULT 'tout',
  scenarios INT UNSIGNED NOT NULL DEFAULT 0,
  phrases INT UNSIGNED NOT NULL DEFAULT 0,
  passed INT UNSIGNED NOT NULL DEFAULT 0,
  metrics_json MEDIUMTEXT NULL,
  failures_json MEDIUMTEXT NULL,
  version VARCHAR(20) NULL,
  duration_ms INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_knowledge_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  ref VARCHAR(120) NULL,
  summary VARCHAR(500) NOT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_kv_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
