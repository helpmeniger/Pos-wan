<?php
/**
 * Produit un fichier SQL unique pour créer les tables de POS Marwa à la main (phpMyAdmin,
 * onglet « Importer »), quand l'assistant web ne peut pas les créer lui-même.
 * Contenu : schéma, migrations, permissions et rôles. AUCUN compte ni mot de passe :
 * le compte administrateur est ensuite créé par l'assistant /install.
 * Usage : php bin/tables-sql.php > pos-marwa-tables.sql
 * N'accède à aucune base de données.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Services/Catalog.php';

use App\Services\Catalog;

$q = fn(string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
$version = preg_match("/'version' => '([^']+)'/", (string) file_get_contents(BASE_PATH . '/config/app.php'), $m) ? $m[1] : '';
$out = "-- POS Marwa $version : création des tables (à importer dans une base VIDE).\n"
    . "-- Aucun compte utilisateur : ouvrez ensuite /install pour créer l'administrateur.\n\n";
$out .= rtrim((string) file_get_contents(BASE_PATH . '/database/schema.sql')) . "\n\n";
$out .= "INSERT IGNORE INTO schema_migrations (version) VALUES ('0001_schema_initial');\n\n";
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $f) {
    $v = basename($f, '.sql');
    $out .= "-- ---- Migration $v\n" . rtrim((string) file_get_contents($f)) . "\n";
    $out .= 'INSERT IGNORE INTO schema_migrations (version) VALUES (' . $q($v) . ");\n\n";
}

// Permissions et rôles système (même résultat que InstallService::seedPermissions)
$out .= "-- ---- Permissions et rôles\n";
$all = [];
foreach (Catalog::PERMISSIONS as $module => $perms) {
    foreach ($perms as $code => $label) {
        $all[] = $code;
        $out .= 'INSERT INTO permissions (code, module, label) VALUES (' . $q($code) . ', ' . $q($module) . ', ' . $q($label) . ") ON DUPLICATE KEY UPDATE module = VALUES(module), label = VALUES(label);\n";
    }
}
foreach (Catalog::ROLES as $code => [$name, $desc, $perms]) {
    $out .= 'INSERT IGNORE INTO roles (code, name, description, is_system) VALUES (' . $q($code) . ', ' . $q($name) . ', ' . $q($desc) . ", 1);\n";
    if ($perms === '*') {
        $list = $all;
    } elseif (is_string($perms) && str_starts_with($perms, '*except:')) {
        $list = array_values(array_diff($all, explode(',', substr($perms, 8))));
    } else {
        $list = $perms;
    }
    if ($list) {
        $out .= 'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('
            . implode(', ', array_map($q, $list)) . ') WHERE r.code = ' . $q($code) . ";\n";
    }
}
$out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
echo $out;
