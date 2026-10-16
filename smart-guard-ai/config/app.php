<?php
declare(strict_types=1);

/**
 * La configuration est lue depuis le fichier .env (voir .env.example) par App\Core\Env.
 * Ce dossier contient la table des routes (routes.php). Aucun secret ne doit être placé ici.
 */
return [
    'name' => App\Core\Env::get('APP_NAME', 'SMART GUARD AI'),
    'slogan' => 'La vision intelligente au service de votre commerce.',
    'version' => '1.0.0',
];
