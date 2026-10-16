<?php
declare(strict_types=1);

/** Régénère database/marwa_install.sql (import phpMyAdmin) à partir des migrations. */
$root = dirname(__DIR__);
$out = "-- MARWA — script d'installation complet (généré par bin/build-install-sql.php)\n"
     . "-- Importer dans une base vide créée en utf8mb4_unicode_ci (phpMyAdmin → Importer).\n"
     . "-- Préférer « php bin/migrate.php », qui suit les migrations déjà appliquées.\n\n";
$files = glob($root . '/database/migrations/*.sql');
sort($files);
foreach ($files as $f) {
    $out .= "-- ==================== " . basename($f) . " ====================\n" . trim((string) file_get_contents($f)) . "\n\n";
}
$out .= "CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
foreach ($files as $f) {
    $out .= "INSERT INTO schema_migrations (version) VALUES ('" . basename($f, '.sql') . "');\n";
}
file_put_contents($root . '/database/marwa_install.sql', $out);
echo "database/marwa_install.sql généré (" . count($files) . " migrations).\n";
