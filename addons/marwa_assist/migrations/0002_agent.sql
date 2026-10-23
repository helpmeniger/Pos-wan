-- MARWA REMOTE ASSIST 1.1 : agent automatique sur chaque poste équipé, code d'accès de la session

ALTER TABLE assist_postes
  ADD COLUMN agent_seen DATETIME NULL,
  ADD COLUMN agent_state VARCHAR(120) NULL,
  ADD COLUMN agent_version VARCHAR(20) NULL;

ALTER TABLE assist_interventions
  ADD COLUMN access_code VARCHAR(20) NULL;
