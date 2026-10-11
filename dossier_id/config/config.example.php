<?php
/**
 * DOSSIER ID — Fichier de configuration d'exemple.
 *
 * Copier ce fichier en config/config.php puis l'adapter.
 * Ne jamais versionner config/config.php ni les fichiers de clés.
 */
return [
    'app' => [
        'name'        => 'DOSSIER ID',
        // 'demo' : environnement de test (bandeau « données fictives », comptes de démonstration autorisés)
        // 'production' : comptes de démonstration interdits, erreurs masquées
        'env'         => 'demo',
        // URL d'accès au répertoire public/ (sans barre finale).
        // WampServer sans VirtualHost : '/dossier_id/public'
        // Avec un VirtualHost pointant sur public/ : ''
        'base_url'    => '/dossier_id/public',
        'timezone'    => 'Africa/Niamey',
        // Mettre true dès que l'application est servie en HTTPS (cookie de session « Secure »).
        'force_https' => false,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'dossier_id',
        'user'     => 'dossier_id',
        'password' => 'CHANGER_CE_MOT_DE_PASSE',
        'charset'  => 'utf8mb4',
    ],

    'storage' => [
        // Répertoire de stockage protégé (hors de public/).
        'path'    => dirname(__DIR__) . '/storage',
        // Répertoire des clés de chiffrement, HORS de C:\wamp64\www.
        // Par défaut : C:\wamp64\dossier_id_keys (créé par scripts/install.php).
        // Conservez une copie des clés sur un support séparé et protégé.
        'key_dir' => dirname(__DIR__, 3) . '/dossier_id_keys',
    ],

    'uploads' => [
        'photo_max_bytes'    => 5 * 1024 * 1024,
        'document_max_bytes' => 10 * 1024 * 1024,
        'photo_min_side'     => 200,   // pixels : en dessous, l'image est refusée (qualité insuffisante)
        'photo_max_side'     => 1600,  // pixels : au-delà, l'image est redimensionnée
    ],
];
