<?php
/**
 * Copier ce fichier en config/config.php (le script install.bat le fait automatiquement)
 * puis adapter les valeurs. config.php n'est jamais versionné.
 */
return [
    'app' => [
        'name'      => 'VERIFIA',
        // URL publique de l'interface (sans barre finale)
        'base_url'  => 'http://localhost/verifia/public',
        'timezone'  => 'Africa/Niamey',
        'debug'     => false,
    ],
    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=verifia;charset=utf8mb4',
        'user'     => 'root',
        'password' => '',
    ],
    // Jeton partagé entre l'API PHP et le moteur de surveillance (Node/Playwright).
    // install.php en génère un aléatoire ; le moteur le lit dans ce même fichier.
    'monitor' => [
        'token'           => 'CHANGE-ME',
        // Adresses autorisées à appeler l'API interne du moteur
        'allowed_ips'     => ['127.0.0.1', '::1'],
        // Chemin de Chromium/Chrome/Edge. Vide = navigateur fourni par Playwright.
        'browser_path'    => '',
        // Canal de navigateur installé ('msedge', 'chrome') utilisé si browser_path est vide
        'browser_channel' => '',
        'headless'        => false,
    ],
    'storage' => [
        'path' => __DIR__ . '/../storage',
    ],
    'security' => [
        // Origines autorisées pour les requêtes POST/AJAX (protection CSRF complémentaire)
        'allowed_origins' => ['http://localhost', 'http://127.0.0.1'],
        'session_name'    => 'VERIFIASESSID',
        'login_max_attempts' => 5,
        'login_lock_minutes' => 15,
    ],
];
