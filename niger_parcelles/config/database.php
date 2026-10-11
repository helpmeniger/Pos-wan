<?php
/**
 * Paramètres de connexion MySQL / MariaDB.
 *
 * Valeurs par défaut de WampServer : utilisateur "root" sans mot de passe.
 * NE METTEZ PAS de mot de passe réel dans ce fichier versionné : l'installateur
 * (install.php) écrit vos paramètres dans config/database.local.php, fichier
 * exclu du dépôt Git et protégé par .htaccess. Vous pouvez aussi créer ce
 * fichier à la main en copiant config/database.local.example.php.
 */
declare(strict_types=1);

$db = [
    'host'     => '127.0.0.1',
    'port'     => 3306,      // MariaDB sous WampServer peut utiliser 3307
    'database' => 'niger_parcelles',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $db = array_replace($db, (array) require $local);
}

// Variables d'environnement (utilisées notamment par les tests automatisés)
foreach (['host' => 'NP_DB_HOST', 'port' => 'NP_DB_PORT', 'database' => 'NP_DB_NAME',
          'username' => 'NP_DB_USER', 'password' => 'NP_DB_PASS'] as $key => $env) {
    $value = getenv($env);
    if ($value !== false && $value !== '') {
        $db[$key] = $key === 'port' ? (int) $value : $value;
    }
}

return $db;
