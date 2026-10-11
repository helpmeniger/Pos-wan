-- =============================================================================
-- Niger Parcelles - Schéma de base de données
-- Compatible MySQL 5.7+ / 8.x et MariaDB 10.3+ (WampServer)
-- Moteur InnoDB, encodage utf8mb4 (caractères français et emojis)
--
-- Import : phpMyAdmin > base "niger_parcelles" > onglet Importer > ce fichier.
-- Le fichier ne crée PAS la base elle-même (pour pouvoir choisir son nom).
--
-- Conventions :
--   * Les géométries sont stockées en GeoJSON (RFC 7946), système WGS84
--     (EPSG:4326), ordre des coordonnées [longitude, latitude].
--   * Chaque modification de géométrie crée une NOUVELLE ligne dans
--     parcel_geometries (versionnage) : les anciennes limites sont conservées.
--   * Les boîtes englobantes (min/max lat/lng) des parcelles sont indexées pour
--     les requêtes cartographiques et la détection des chevauchements.
--   * Aucune donnée n'est supprimée physiquement pour les parcelles, mutations
--     et historiques : on archive.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS import_batches;
DROP TABLE IF EXISTS parcel_overlaps;
DROP TABLE IF EXISTS parcel_history;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS parcel_owners;
DROP TABLE IF EXISTS mutations;
DROP TABLE IF EXISTS parcel_geometries;
DROP TABLE IF EXISTS parcels;
DROP TABLE IF EXISTS parcel_reference_sequences;
DROP TABLE IF EXISTS owners;
DROP TABLE IF EXISTS administrative_areas;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Rôles (les permissions associées sont définies dans includes/permissions.php)
-- -----------------------------------------------------------------------------
CREATE TABLE roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Utilisateurs
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username             VARCHAR(60)  NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    full_name            VARCHAR(150) NOT NULL,
    email                VARCHAR(190) NULL,
    phone                VARCHAR(40)  NULL,
    role_id              INT UNSIGNED NOT NULL,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at        DATETIME     NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentatives de connexion (limitation anti force brute)
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(60)  NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_user_time (username, attempted_at),
    KEY idx_login_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Découpage administratif (hiérarchique : pays > région > département >
-- commune > quartier / village). Les limites (geojson) sont optionnelles et
-- doivent provenir de sources officielles importées (jamais inventées).
-- `code` sert de préfixe aux références de parcelles (ex. NIA pour Niamey).
-- -----------------------------------------------------------------------------
CREATE TABLE administrative_areas (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(150) NOT NULL,
    area_type          ENUM('pays','region','departement','commune','quartier','village') NOT NULL,
    parent_id          INT UNSIGNED NULL,
    code               VARCHAR(10)  NULL,
    external_reference VARCHAR(100) NULL,
    geojson            LONGTEXT     NULL,
    source             VARCHAR(255) NULL,
    min_lat            DECIMAL(10,7) NULL,
    max_lat            DECIMAL(10,7) NULL,
    min_lng            DECIMAL(10,7) NULL,
    max_lng            DECIMAL(10,7) NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_parent_name_type (parent_id, name, area_type),
    KEY idx_area_type (area_type),
    KEY idx_area_name (name),
    CONSTRAINT fk_area_parent FOREIGN KEY (parent_id) REFERENCES administrative_areas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Propriétaires / ayants droit (personnes physiques ou morales)
-- Un nom saisi ici n'est JAMAIS une preuve juridique de propriété :
-- `info_status` indique le niveau de fiabilité de l'information.
-- -----------------------------------------------------------------------------
CREATE TABLE owners (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_type         ENUM('personne_physique','personne_morale','collectivite','etat','groupement') NOT NULL DEFAULT 'personne_physique',
    first_name         VARCHAR(100) NULL,
    last_name          VARCHAR(100) NULL,
    organization_name  VARCHAR(200) NULL,
    id_document_type   VARCHAR(60)  NULL,
    id_document_number VARCHAR(80)  NULL,
    phone              VARCHAR(40)  NULL,
    email              VARCHAR(190) NULL,
    address            VARCHAR(255) NULL,
    file_reference     VARCHAR(80)  NULL,
    info_status        ENUM('declare','documente','verifie','conteste','incomplet') NOT NULL DEFAULT 'declare',
    last_verified_at   DATETIME     NULL,
    last_verified_by   INT UNSIGNED NULL,
    notes              TEXT         NULL,
    is_archived        TINYINT(1)   NOT NULL DEFAULT 0,
    archived_at        DATETIME     NULL,
    is_demo            TINYINT(1)   NOT NULL DEFAULT 0,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_owner_names (last_name, first_name),
    KEY idx_owner_org (organization_name),
    KEY idx_owner_phone (phone),
    KEY idx_owner_file (file_reference),
    KEY idx_owner_archived (is_archived),
    CONSTRAINT fk_owner_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_owner_verified_by FOREIGN KEY (last_verified_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Séquence des références (NIG-XXX-AAAA-NNNNNN) : un compteur par année,
-- incrémenté de façon atomique ; jamais décrémenté => aucune réattribution.
CREATE TABLE parcel_reference_sequences (
    year       SMALLINT UNSIGNED NOT NULL,
    last_value INT UNSIGNED      NOT NULL,
    PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Parcelles
--   parcel_reference   : référence applicative unique et immuable
--   cadastral_reference: référence cadastrale OFFICIELLE si elle existe
--   survey_level       : nature de la délimitation (indicative, relevé terrain,
--                        documentée, vérifiée par une autorité habilitée)
--   declared_status    : situation foncière déclarée (titre foncier, etc.)
--   verification_status: étape du circuit de vérification interne
-- -----------------------------------------------------------------------------
CREATE TABLE parcels (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_reference         VARCHAR(32)  NOT NULL,
    cadastral_reference      VARCHAR(80)  NULL,
    administrative_area_id   INT UNSIGNED NULL,
    locality_description     VARCHAR(255) NULL,
    address                  VARCHAR(255) NULL,
    category                 ENUM('residentiel','commercial','agricole','industriel','administratif','terrain_nu','en_construction','autre') NOT NULL DEFAULT 'autre',
    area_m2                  DECIMAL(14,2) NOT NULL,
    perimeter_m              DECIMAL(12,2) NOT NULL,
    centroid_lat             DECIMAL(10,7) NOT NULL,
    centroid_lng             DECIMAL(10,7) NOT NULL,
    min_lat                  DECIMAL(10,7) NOT NULL,
    max_lat                  DECIMAL(10,7) NOT NULL,
    min_lng                  DECIMAL(10,7) NOT NULL,
    max_lng                  DECIMAL(10,7) NOT NULL,
    current_geometry_version INT UNSIGNED NOT NULL DEFAULT 1,
    survey_level             ENUM('indicative','releve_terrain','documentee','verifiee_autorite') NOT NULL DEFAULT 'indicative',
    declared_status          ENUM('titre_foncier','permis_urbain','acte_cession','attestation_coutumiere','autre_document','sans_document','inconnu') NOT NULL DEFAULT 'inconnu',
    title_reference          VARCHAR(80)  NULL,
    tenure_document_type     VARCHAR(100) NULL,
    tenure_document_date     DATE         NULL,
    issuing_authority        VARCHAR(150) NULL,
    restrictions             TEXT         NULL,
    observations             TEXT         NULL,
    verification_status      ENUM('brouillon','soumise','en_verification','verifiee','anomalie','rejetee') NOT NULL DEFAULT 'brouillon',
    has_overlap_warning      TINYINT(1)   NOT NULL DEFAULT 0,
    description              TEXT         NULL,
    is_archived              TINYINT(1)   NOT NULL DEFAULT 0,
    is_demo                  TINYINT(1)   NOT NULL DEFAULT 0,
    created_by               INT UNSIGNED NULL,
    updated_by               INT UNSIGNED NULL,
    verified_by              INT UNSIGNED NULL,
    verified_at              DATETIME     NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parcel_reference (parcel_reference),
    KEY idx_parcel_cadastral (cadastral_reference),
    KEY idx_parcel_area (administrative_area_id),
    KEY idx_parcel_status (verification_status),
    KEY idx_parcel_category (category),
    KEY idx_parcel_created (created_at),
    KEY idx_parcel_bbox (min_lat, max_lat, min_lng, max_lng),
    KEY idx_parcel_centroid (centroid_lat, centroid_lng),
    KEY idx_parcel_archived (is_archived),
    CONSTRAINT fk_parcel_area FOREIGN KEY (administrative_area_id) REFERENCES administrative_areas (id),
    CONSTRAINT fk_parcel_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_parcel_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT fk_parcel_verified_by FOREIGN KEY (verified_by) REFERENCES users (id),
    CONSTRAINT chk_parcel_area_positive CHECK (area_m2 > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versions successives des limites de chaque parcelle (GeoJSON Polygon WGS84)
CREATE TABLE parcel_geometries (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id        INT UNSIGNED NOT NULL,
    geojson          LONGTEXT     NOT NULL,
    geometry_version INT UNSIGNED NOT NULL,
    vertex_count     INT UNSIGNED NOT NULL,
    area_m2          DECIMAL(14,2) NOT NULL,
    perimeter_m      DECIMAL(12,2) NOT NULL,
    source           ENUM('dessin','import','gps','autre') NOT NULL DEFAULT 'dessin',
    source_crs       VARCHAR(20)  NULL,
    change_reason    VARCHAR(255) NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_geom_parcel_version (parcel_id, geometry_version),
    CONSTRAINT fk_geom_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
    CONSTRAINT fk_geom_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mutations (vente, succession, donation...) : jamais supprimées
CREATE TABLE mutations (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id          INT UNSIGNED NOT NULL,
    mutation_type      ENUM('vente','succession','donation','partage','echange','decision_justice','attribution','autre') NOT NULL,
    mutation_date      DATE         NOT NULL,
    document_reference VARCHAR(100) NULL,
    notes              TEXT         NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mutation_parcel (parcel_id),
    KEY idx_mutation_date (mutation_date),
    CONSTRAINT fk_mutation_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
    CONSTRAINT fk_mutation_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liaison N-N parcelles / propriétaires avec période de validité.
-- valid_to NULL = lien en cours. Un lien clos reste en base (historique).
CREATE TABLE parcel_owners (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id           INT UNSIGNED NOT NULL,
    owner_id            INT UNSIGNED NOT NULL,
    ownership_role      ENUM('proprietaire','coproprietaire','usufruitier','ayant_droit','occupant','mandataire') NOT NULL DEFAULT 'proprietaire',
    share_percentage    DECIMAL(6,3) NULL,
    valid_from          DATE         NULL,
    valid_to            DATE         NULL,
    verification_status ENUM('declare','documente','verifie','conteste') NOT NULL DEFAULT 'declare',
    document_reference  VARCHAR(100) NULL,
    mutation_in_id      INT UNSIGNED NULL,
    mutation_out_id     INT UNSIGNED NULL,
    notes               VARCHAR(255) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_po_parcel (parcel_id, valid_to),
    KEY idx_po_owner (owner_id, valid_to),
    CONSTRAINT fk_po_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
    CONSTRAINT fk_po_owner FOREIGN KEY (owner_id) REFERENCES owners (id),
    CONSTRAINT fk_po_mut_in FOREIGN KEY (mutation_in_id) REFERENCES mutations (id),
    CONSTRAINT fk_po_mut_out FOREIGN KEY (mutation_out_id) REFERENCES mutations (id),
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_po_share CHECK (share_percentage IS NULL OR (share_percentage > 0 AND share_percentage <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents justificatifs (fichiers stockés dans storage/documents, privés)
CREATE TABLE documents (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id           INT UNSIGNED NULL,
    owner_id            INT UNSIGNED NULL,
    document_type       ENUM('plan','acte','attestation','titre_foncier','contrat','proces_verbal','rapport_verification','photo','piece_identite','autre') NOT NULL DEFAULT 'autre',
    title               VARCHAR(200) NOT NULL,
    document_reference  VARCHAR(100) NULL,
    document_date       DATE         NULL,
    original_filename   VARCHAR(255) NOT NULL,
    storage_filename    VARCHAR(80)  NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    file_size           INT UNSIGNED NOT NULL,
    sha256              CHAR(64)     NOT NULL,
    verification_status ENUM('non_verifie','verifie','rejete') NOT NULL DEFAULT 'non_verifie',
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME     NULL,
    replaces_id         INT UNSIGNED NULL,
    is_archived         TINYINT(1)   NOT NULL DEFAULT 0,
    uploaded_by         INT UNSIGNED NULL,
    uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doc_storage (storage_filename),
    KEY idx_doc_parcel (parcel_id),
    KEY idx_doc_owner (owner_id),
    KEY idx_doc_type (document_type),
    CONSTRAINT fk_doc_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
    CONSTRAINT fk_doc_owner FOREIGN KEY (owner_id) REFERENCES owners (id),
    CONSTRAINT fk_doc_replaces FOREIGN KEY (replaces_id) REFERENCES documents (id),
    CONSTRAINT fk_doc_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id),
    CONSTRAINT fk_doc_verified_by FOREIGN KEY (verified_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique métier des parcelles (données avant / après au format JSON)
CREATE TABLE parcel_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id     INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NULL,
    action_type   VARCHAR(40)  NOT NULL,
    previous_data LONGTEXT     NULL,
    new_data      LONGTEXT     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hist_parcel (parcel_id, created_at),
    KEY idx_hist_action (action_type),
    CONSTRAINT fk_hist_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
    CONSTRAINT fk_hist_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chevauchements / doublons potentiels détectés (paire ordonnée a < b)
CREATE TABLE parcel_overlaps (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_a_id     INT UNSIGNED NOT NULL,
    parcel_b_id     INT UNSIGNED NOT NULL,
    overlap_type    ENUM('chevauchement','doublon_probable') NOT NULL DEFAULT 'chevauchement',
    status          ENUM('a_verifier','legitime','corrige') NOT NULL DEFAULT 'a_verifier',
    notes           VARCHAR(255) NULL,
    detected_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by     INT UNSIGNED NULL,
    resolved_at     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_overlap_pair (parcel_a_id, parcel_b_id),
    KEY idx_overlap_b (parcel_b_id),
    KEY idx_overlap_status (status),
    CONSTRAINT fk_overlap_a FOREIGN KEY (parcel_a_id) REFERENCES parcels (id),
    CONSTRAINT fk_overlap_b FOREIGN KEY (parcel_b_id) REFERENCES parcels (id),
    CONSTRAINT fk_overlap_resolver FOREIGN KEY (resolved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rapports d'importation
CREATE TABLE import_batches (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename       VARCHAR(255) NOT NULL,
    format         VARCHAR(20)  NOT NULL,
    source_crs     VARCHAR(20)  NOT NULL,
    feature_count  INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count    INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count  INT UNSIGNED NOT NULL DEFAULT 0,
    report         LONGTEXT     NULL,
    status         ENUM('termine','echec') NOT NULL DEFAULT 'termine',
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_import_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal d'audit (opérations sensibles, connexions, exports, téléchargements)
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(40)  NULL,
    entity_id   INT UNSIGNED NULL,
    details     TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres applicatifs
CREATE TABLE settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(80)  NOT NULL,
    setting_value TEXT         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Données de référence (NON fictives)
-- =============================================================================

INSERT INTO roles (id, code, name, description) VALUES
(1, 'admin_general',  'Administrateur général', 'Utilisateurs, paramètres, journaux, accès complet'),
(2, 'admin_foncier',  'Administrateur foncier', 'Parcelles, propriétaires, documents, validation des dossiers'),
(3, 'agent_terrain',  'Agent de terrain',       'Création et délimitation des parcelles, saisie, soumission'),
(4, 'verificateur',   'Vérificateur',           'Contrôle des dossiers, signalement et validation technique'),
(5, 'consultation',   'Consultation',           'Lecture des seules informations autorisées');

-- Pays et 8 régions administratives du Niger (découpage officiel public).
-- Les codes servent de préfixe de référence. Aucune limite géographique n'est
-- fournie : importez des limites officielles via Paramètres > Découpage.
INSERT INTO administrative_areas (id, name, area_type, parent_id, code, source) VALUES
(1, 'Niger',     'pays',   NULL, 'NIG', 'Référentiel initial'),
(2, 'Agadez',    'region', 1,    'AGA', 'Référentiel initial'),
(3, 'Diffa',     'region', 1,    'DIF', 'Référentiel initial'),
(4, 'Dosso',     'region', 1,    'DOS', 'Référentiel initial'),
(5, 'Maradi',    'region', 1,    'MAR', 'Référentiel initial'),
(6, 'Niamey',    'region', 1,    'NIA', 'Référentiel initial (Communauté urbaine)'),
(7, 'Tahoua',    'region', 1,    'TAH', 'Référentiel initial'),
(8, 'Tillabéri', 'region', 1,    'TIL', 'Référentiel initial'),
(9, 'Zinder',    'region', 1,    'ZIN', 'Référentiel initial');

-- Les 5 arrondissements communaux de Niamey
INSERT INTO administrative_areas (name, area_type, parent_id, source) VALUES
('Niamey I',   'commune', 6, 'Référentiel initial'),
('Niamey II',  'commune', 6, 'Référentiel initial'),
('Niamey III', 'commune', 6, 'Référentiel initial'),
('Niamey IV',  'commune', 6, 'Référentiel initial'),
('Niamey V',   'commune', 6, 'Référentiel initial');

INSERT INTO settings (setting_key, setting_value) VALUES
('organisation_name', 'Niger Parcelles'),
('map_center_lat', '17.6078'),
('map_center_lng', '8.0817'),
('map_zoom', '6'),
('bounds_min_lat', '11.3'),
('bounds_max_lat', '23.6'),
('bounds_min_lng', '0.0'),
('bounds_max_lng', '16.1'),
('min_parcel_area_m2', '1'),
('max_parcel_area_m2', '50000000'),
('max_upload_mb', '10'),
('satellite_enabled', '1'),
('map_max_features', '1500'),
('overlap_tolerance_m', '0.10');
