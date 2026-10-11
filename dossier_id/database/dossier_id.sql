-- DOSSIER ID — script SQL complet d'installation (généré depuis database/migrations/).
-- Import possible via phpMyAdmin dans une base vide utf8mb4_unicode_ci.
-- Exécutez ENSUITE : php scripts/install.php (clés de chiffrement + premier administrateur).

-- DOSSIER ID — Migration 001 : schéma initial
-- Compatible MySQL 8.x et MariaDB 10.6+ (WampServer 3.3+)
-- Note : éviter les points-virgules à l'intérieur des chaînes (découpage simple par l'outil de migration).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Rôles et permissions
-- ---------------------------------------------------------------
CREATE TABLE roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50)  NOT NULL UNIQUE,
    label       VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(60)  NOT NULL UNIQUE,
    label   VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Utilisateurs
-- ---------------------------------------------------------------
CREATE TABLE users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username             VARCHAR(60)  NOT NULL UNIQUE,
    full_name            VARCHAR(150) NOT NULL,
    email                VARCHAR(150) NULL,
    service              VARCHAR(120) NULL,
    role_id              INT UNSIGNED NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    disabled_reason      VARCHAR(255) NULL,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
    failed_attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME     NULL,
    last_login_at        DATETIME     NULL,
    password_changed_at  DATETIME     NULL,
    is_demo              TINYINT(1)   NOT NULL DEFAULT 0,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(60)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL,
    attempted_at DATETIME     NOT NULL,
    INDEX idx_la_user (username, attempted_at),
    INDEX idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    DATETIME     NOT NULL,
    updated_by    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Fiches individuelles
-- ---------------------------------------------------------------
CREATE TABLE persons (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_number         VARCHAR(30)  NOT NULL UNIQUE,
    last_name           VARCHAR(100) NOT NULL,
    first_name          VARCHAR(100) NOT NULL,
    other_names         VARCHAR(255) NULL,
    birth_date          DATE         NULL,
    declared_age        TINYINT UNSIGNED NULL,
    birth_place         VARCHAR(120) NULL,
    sex                 ENUM('M','F') NULL,
    nationality         VARCHAR(80)  NULL,
    profession          VARCHAR(120) NULL,
    admin_id_type       VARCHAR(60)  NULL,
    admin_id_number     VARCHAR(80)  NULL,
    admin_id_verified   TINYINT(1)   NOT NULL DEFAULT 0,
    registration_date   DATE         NOT NULL,
    registration_place  VARCHAR(120) NOT NULL,
    registering_service VARCHAR(120) NOT NULL,
    access_level        ENUM('standard','restreint') NOT NULL DEFAULT 'standard',
    status              ENUM('actif','archive')      NOT NULL DEFAULT 'actif',
    archived_at         DATETIME     NULL,
    archived_by         INT UNSIGNED NULL,
    archive_reason      VARCHAR(255) NULL,
    last_name_norm      VARCHAR(100) NOT NULL,
    first_name_norm     VARCHAR(100) NOT NULL,
    other_names_norm    VARCHAR(255) NULL,
    last_name_phon      VARCHAR(40)  NOT NULL,
    first_name_phon     VARCHAR(40)  NOT NULL,
    admin_id_norm       VARCHAR(80)  NULL,
    is_demo             TINYINT(1)   NOT NULL DEFAULT 0,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME     NOT NULL,
    INDEX idx_p_names (last_name_norm, first_name_norm),
    INDEX idx_p_phon (last_name_phon),
    INDEX idx_p_fphon (first_name_phon),
    INDEX idx_p_admin (admin_id_norm),
    INDEX idx_p_birth (birth_date),
    INDEX idx_p_status (status),
    INDEX idx_p_updated (updated_at),
    CONSTRAINT fk_p_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_p_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_p_archived FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE photos (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id            INT UNSIGNED NOT NULL,
    storage_name         VARCHAR(120) NOT NULL,
    mime_type            VARCHAR(40)  NOT NULL,
    width                INT UNSIGNED NOT NULL,
    height               INT UNSIGNED NOT NULL,
    size_bytes           INT UNSIGNED NOT NULL,
    sha256_original      CHAR(64)     NOT NULL,
    authorization_ref    VARCHAR(120) NOT NULL,
    taken_at             DATE         NULL,
    is_primary           TINYINT(1)   NOT NULL DEFAULT 0,
    status               ENUM('active','retiree') NOT NULL DEFAULT 'active',
    retired_reason       VARCHAR(255) NULL,
    retired_at           DATETIME     NULL,
    retired_by           INT UNSIGNED NULL,
    uploaded_by          INT UNSIGNED NULL,
    uploaded_at          DATETIME     NOT NULL,
    INDEX idx_ph_person (person_id, status),
    CONSTRAINT fk_ph_person FOREIGN KEY (person_id) REFERENCES persons(id),
    CONSTRAINT fk_ph_upl FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ph_ret FOREIGN KEY (retired_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Historique des passages
-- ---------------------------------------------------------------
CREATE TABLE visits (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id        INT UNSIGNED NOT NULL,
    visit_date       DATETIME     NOT NULL,
    location         VARCHAR(150) NOT NULL,
    service          VARCHAR(120) NOT NULL,
    reason           VARCHAR(255) NOT NULL,
    reference        VARCHAR(80)  NOT NULL,
    responsible_agent VARCHAR(150) NOT NULL,
    observations     TEXT         NULL,
    closure_status   ENUM('ouvert','cloture') NOT NULL DEFAULT 'ouvert',
    created_by       INT UNSIGNED NULL,
    updated_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    INDEX idx_v_person (person_id, visit_date),
    INDEX idx_v_date (visit_date),
    INDEX idx_v_ref (reference),
    INDEX idx_v_service (service),
    CONSTRAINT fk_v_person FOREIGN KEY (person_id) REFERENCES persons(id),
    CONSTRAINT fk_v_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_v_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Procédures
-- ---------------------------------------------------------------
CREATE TABLE procedures (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id            INT UNSIGNED NOT NULL,
    procedure_number     VARCHAR(80)  NOT NULL,
    pv_reference         VARCHAR(80)  NULL,
    facts_date           DATE         NULL,
    facts_nature         VARCHAR(255) NOT NULL,
    investigating_service VARCHAR(150) NOT NULL,
    status               ENUM('entendue','mise_en_cause','poursuivie','condamnee','acquittee','cloturee') NOT NULL,
    court_reference      VARCHAR(120) NULL,
    decision_text        VARCHAR(500) NULL,
    decision_date        DATE         NULL,
    decision_final       ENUM('inconnu','non_definitive','definitive') NOT NULL DEFAULT 'inconnu',
    justification_ref    VARCHAR(255) NULL,
    notes                TEXT         NULL,
    created_by           INT UNSIGNED NULL,
    updated_by           INT UNSIGNED NULL,
    created_at           DATETIME     NOT NULL,
    updated_at           DATETIME     NOT NULL,
    INDEX idx_pr_person (person_id),
    INDEX idx_pr_number (procedure_number),
    INDEX idx_pr_status (status),
    INDEX idx_pr_facts (facts_date),
    CONSTRAINT fk_pr_person FOREIGN KEY (person_id) REFERENCES persons(id),
    CONSTRAINT fk_pr_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE procedure_status_history (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    procedure_id  INT UNSIGNED NOT NULL,
    old_status    VARCHAR(30)  NULL,
    new_status    VARCHAR(30)  NOT NULL,
    justification VARCHAR(500) NOT NULL,
    changed_by    INT UNSIGNED NULL,
    changed_at    DATETIME     NOT NULL,
    INDEX idx_psh_proc (procedure_id),
    CONSTRAINT fk_psh_proc FOREIGN KEY (procedure_id) REFERENCES procedures(id),
    CONSTRAINT fk_psh_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Documents associés
-- ---------------------------------------------------------------
CREATE TABLE documents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id      INT UNSIGNED NOT NULL,
    visit_id       INT UNSIGNED NULL,
    procedure_id   INT UNSIGNED NULL,
    title          VARCHAR(200) NOT NULL,
    doc_reference  VARCHAR(120) NULL,
    original_name  VARCHAR(200) NOT NULL,
    storage_name   VARCHAR(120) NOT NULL,
    mime_type      VARCHAR(60)  NOT NULL,
    size_bytes     INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    uploaded_by    INT UNSIGNED NULL,
    uploaded_at    DATETIME     NOT NULL,
    INDEX idx_d_person (person_id),
    CONSTRAINT fk_d_person FOREIGN KEY (person_id) REFERENCES persons(id),
    CONSTRAINT fk_d_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL,
    CONSTRAINT fk_d_proc FOREIGN KEY (procedure_id) REFERENCES procedures(id) ON DELETE SET NULL,
    CONSTRAINT fk_d_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Revue des doublons
-- ---------------------------------------------------------------
CREATE TABLE duplicate_reviews (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_a_id  INT UNSIGNED NOT NULL,
    person_b_id  INT UNSIGNED NOT NULL,
    decision     ENUM('distinctes','doublon_confirme') NOT NULL,
    comment      VARCHAR(500) NOT NULL,
    reviewed_by  INT UNSIGNED NULL,
    reviewed_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_dr_pair (person_a_id, person_b_id),
    CONSTRAINT fk_dr_a FOREIGN KEY (person_a_id) REFERENCES persons(id),
    CONSTRAINT fk_dr_b FOREIGN KEY (person_b_id) REFERENCES persons(id),
    CONSTRAINT fk_dr_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Journal d'audit (chaîné par empreintes SHA-256, ajout seul)
-- ---------------------------------------------------------------
CREATE TABLE audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at  DATETIME     NOT NULL,
    user_id     INT UNSIGNED NULL,
    username    VARCHAR(60)  NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(40)  NULL,
    entity_id   INT UNSIGNED NULL,
    ip_address  VARCHAR(45)  NULL,
    details     TEXT         NULL,
    prev_hash   CHAR(64)     NOT NULL,
    hash        CHAR(64)     NOT NULL,
    INDEX idx_a_date (created_at),
    INDEX idx_a_user (user_id),
    INDEX idx_a_action (action),
    INDEX idx_a_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Données de référence
-- ---------------------------------------------------------------
INSERT INTO roles (code, label, description) VALUES
 ('admin',         'Administrateur',                     'Comptes, paramètres techniques, sauvegardes. Aucun accès par défaut au contenu des dossiers.'),
 ('enregistrement','Agent d''enregistrement',            'Création et mise à jour des fiches, photographies, passages, procédures et documents.'),
 ('consultation',  'Agent habilité à consulter',         'Consultation en lecture seule des dossiers autorisés.'),
 ('controleur',    'Responsable de contrôle',            'Consultation du journal d''audit et contrôle de la traçabilité.');

INSERT INTO permissions (code, label) VALUES
 ('persons.view',            'Consulter les fiches (niveau standard, actives)'),
 ('persons.view_restricted', 'Consulter les fiches à accès restreint'),
 ('persons.view_archived',   'Consulter les fiches archivées'),
 ('persons.create',          'Créer des fiches'),
 ('persons.edit',            'Modifier / rectifier des fiches'),
 ('persons.archive',         'Archiver / désarchiver des fiches'),
 ('photos.view',             'Voir les photographies'),
 ('photos.manage',           'Importer / retirer des photographies'),
 ('visits.view',             'Consulter les passages'),
 ('visits.manage',           'Enregistrer / modifier des passages'),
 ('procedures.view',         'Consulter les procédures'),
 ('procedures.manage',       'Enregistrer / modifier des procédures'),
 ('documents.view',          'Télécharger les documents'),
 ('documents.manage',        'Importer des documents'),
 ('duplicates.review',       'Examiner les doublons potentiels'),
 ('export.csv',              'Exporter des listes en CSV'),
 ('stats.view',              'Voir les statistiques agrégées'),
 ('audit.view',              'Consulter le journal d''audit'),
 ('users.manage',            'Gérer les comptes utilisateurs'),
 ('backups.manage',          'Gérer les sauvegardes et restaurations'),
 ('settings.manage',         'Gérer les paramètres et la matrice des permissions');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 ON (r.code = 'admin' AND p.code IN ('users.manage','backups.manage','settings.manage','stats.view'))
 OR (r.code = 'enregistrement' AND p.code IN ('persons.view','persons.view_archived','persons.create','persons.edit','persons.archive',
        'photos.view','photos.manage','visits.view','visits.manage','procedures.view','procedures.manage',
        'documents.view','documents.manage','duplicates.review','stats.view'))
 OR (r.code = 'consultation' AND p.code IN ('persons.view','persons.view_restricted','persons.view_archived',
        'photos.view','visits.view','procedures.view','documents.view','export.csv','stats.view'))
 OR (r.code = 'controleur' AND p.code IN ('audit.view','stats.view','export.csv'));

INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
 ('institution_name',       'Service administratif (à configurer)', NOW()),
 ('session_idle_minutes',   '15',  NOW()),
 ('session_max_hours',      '8',   NOW()),
 ('login_max_attempts',     '5',   NOW()),
 ('lockout_minutes',        '15',  NOW()),
 ('account_inactivity_days','90',  NOW()),
 ('password_min_length',    '12',  NOW());

INSERT INTO schema_migrations (version, applied_at) VALUES ('001_initial_schema', NOW());
