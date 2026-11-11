-- Module Archives : dossiers, documents, versions de fichiers

CREATE TABLE IF NOT EXISTS arch_dossiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  confidential TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_arch_dos_parent (parent_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arch_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dossier_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  reference VARCHAR(80) NULL,
  category ENUM('administratif','juridique','contrat','finance','rh','projet','courrier','rapport','technique','photo','autre') NOT NULL DEFAULT 'autre',
  doc_date DATE NULL,
  expires_on DATE NULL,
  tags VARCHAR(255) NULL,
  description TEXT NULL,
  confidential TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('actif','corbeille') NOT NULL DEFAULT 'actif',
  versions INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  deleted_by INT UNSIGNED NULL,
  INDEX idx_arch_doc_dossier (dossier_id, status),
  INDEX idx_arch_doc_expires (expires_on, status),
  INDEX idx_arch_doc_ref (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arch_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  original_name VARCHAR(200) NOT NULL,
  stored_path VARCHAR(120) NOT NULL,
  extension VARCHAR(10) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  note VARCHAR(255) NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_arch_version (document_id, version),
  INDEX idx_arch_sha (sha256),
  CONSTRAINT fk_arch_ver_doc FOREIGN KEY (document_id) REFERENCES arch_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
