<?php
/**
 * Mises à jour automatiques et idempotentes de la base de données.
 *
 * database/schema.sql décrit la version 1. Les versions suivantes sont
 * appliquées ici, une seule fois, dans l'ordre, automatiquement à la première
 * requête après une mise à jour des fichiers de l'application (aucune
 * manipulation dans phpMyAdmin). Chaque étape vérifie ce qui existe déjà :
 * relancer une migration ne casse rien. Les données existantes ne sont jamais
 * supprimées.
 */
declare(strict_types=1);

final class MigrationService
{
    public const LATEST = 2;

    /** Applique les migrations manquantes. Retourne la liste des versions appliquées. */
    public static function migrate(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? db();
        $current = (int) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn() ?: 1);
        $applied = [];
        for ($v = $current + 1; $v <= self::LATEST; $v++) {
            $fn = 'v' . $v;
            self::$fn($pdo);
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string) $v]);
            $applied[] = $v;
        }
        if ($applied) {
            setting('__reset__');
        }
        self::ensureSecretKey();
        return $applied;
    }

    /** Appel léger depuis bootstrap : migre seulement si la version enregistrée est ancienne. */
    public static function autoMigrate(): void
    {
        if ((int) setting('schema_version', 1) >= self::LATEST && is_file(self::secretPath())) {
            return;
        }
        try {
            self::migrate();
        } catch (Throwable $e) {
            log_error($e);
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        return (bool) $st->fetchColumn();
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $index]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Version 2 : fiches authentifiables (QR code), idempotence de la saisie
     * hors connexion, décisions sur le contrôle qualité des limites.
     */
    private static function v2(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS issued_documents (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token           CHAR(32)     NOT NULL,
            doc_type        ENUM('fiche_parcelle','fiche_proprietaire') NOT NULL,
            parcel_id       INT UNSIGNED NULL,
            owner_id        INT UNSIGNED NULL,
            snapshot        LONGTEXT     NOT NULL,
            snapshot_hash   CHAR(64)     NOT NULL,
            signature       CHAR(64)     NOT NULL,
            issued_by       INT UNSIGNED NULL,
            issued_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at      DATETIME     NULL,
            revoked_by      INT UNSIGNED NULL,
            revoke_reason   VARCHAR(255) NULL,
            verify_count    INT UNSIGNED NOT NULL DEFAULT 0,
            last_verified_at DATETIME    NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_issued_token (token),
            KEY idx_issued_parcel (parcel_id, issued_at),
            KEY idx_issued_owner (owner_id),
            CONSTRAINT fk_issued_parcel FOREIGN KEY (parcel_id) REFERENCES parcels (id),
            CONSTRAINT fk_issued_owner FOREIGN KEY (owner_id) REFERENCES owners (id),
            CONSTRAINT fk_issued_by FOREIGN KEY (issued_by) REFERENCES users (id),
            CONSTRAINT fk_issued_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS quality_dismissals (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            parcel_a_id  INT UNSIGNED NOT NULL,
            parcel_b_id  INT UNSIGNED NOT NULL,
            reason       VARCHAR(255) NOT NULL,
            dismissed_by INT UNSIGNED NULL,
            dismissed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quality_pair (parcel_a_id, parcel_b_id),
            CONSTRAINT fk_qd_a FOREIGN KEY (parcel_a_id) REFERENCES parcels (id),
            CONSTRAINT fk_qd_b FOREIGN KEY (parcel_b_id) REFERENCES parcels (id),
            CONSTRAINT fk_qd_user FOREIGN KEY (dismissed_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!self::columnExists($pdo, 'parcels', 'client_uuid')) {
            $pdo->exec('ALTER TABLE parcels ADD COLUMN client_uuid CHAR(36) NULL AFTER parcel_reference');
        }
        if (!self::indexExists($pdo, 'parcels', 'uq_parcel_client_uuid')) {
            $pdo->exec('ALTER TABLE parcels ADD UNIQUE KEY uq_parcel_client_uuid (client_uuid)');
        }
        foreach ([['public_base_url', ''], ['quality_tolerance_m', '0.60'], ['risk_overdue_days', '15']] as [$k, $v]) {
            $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$k, $v]);
        }
    }

    // ------------------------------------------------------------------
    // Clé secrète de signature des fiches (jamais versionnée)
    // ------------------------------------------------------------------

    public static function secretPath(): string
    {
        return storage_path('secret.key');
    }

    public static function ensureSecretKey(): void
    {
        $p = self::secretPath();
        if (!is_file($p)) {
            file_put_contents($p, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($p, 0600);
        }
    }

    public static function secretKey(): string
    {
        self::ensureSecretKey();
        return trim((string) file_get_contents(self::secretPath()));
    }
}
